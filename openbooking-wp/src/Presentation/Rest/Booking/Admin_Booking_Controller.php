<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Booking;

use OpenBooking\Application\Audit\Service\Audit_Logger;
use OpenBooking\Application\Booking\Service\Booking_Export_Service;
use OpenBooking\Application\Booking\Service\Booking_Request_Context;
use OpenBooking\Domain\Booking\Repository\BookingTimelineRepositoryInterface;
use OpenBooking\Presentation\Rest\Core\Rest_Error_Helper;
use OpenBooking\Domain\Shared\Port\RateLimiterInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone las operaciones administrativas sobre reservas.
 */
class Admin_Booking_Controller {

    public function __construct(
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo,          // consulta y persiste reservas
        private \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface $service_repo,          // consulta servicios del catalogo
        private \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface $customer_repo,       // consulta datos de clientes
        private Booking_Export_Service $export_service,                                      // exporta reservas a CSV
        private \OpenBooking\Application\Booking\UseCase\Create_Booking_Use_Case $create_booking_use_case,
        private \OpenBooking\Application\Booking\UseCase\Cancel_Booking_Use_Case $cancel_booking_use_case,
        private \OpenBooking\Application\Booking\UseCase\Confirm_Booking_Use_Case $confirm_booking_use_case,
        private \OpenBooking\Application\Booking\UseCase\Mark_No_Show_Use_Case $mark_no_show_use_case,
        private \OpenBooking\Application\Booking\UseCase\Reschedule_Booking_Use_Case $reschedule_booking_use_case,
        private BookingTimelineRepositoryInterface $timeline_repo,                          // consulta timeline de reservas
        private Audit_Logger $audit_logger,                                                 // deja trazabilidad de cambios
        private ?RateLimiterInterface $rate_limiter = null,                                 // limita frecuencia de acciones
    ) {}

    // ─── Endpoints Publicos ────────────────────────────

    /**
     * Lista reservas con filtros opcionales (admin).
     */
    public function admin_get_bookings( \WP_REST_Request $request ): \WP_REST_Response {
        $args     = $this->build_booking_query_args( $request );
        $bookings = $this->booking_repo->find_all( $args );
        $data     = $this->enrich_bookings( $bookings );

        return new \WP_REST_Response( [ 'bookings' => $data ], 200 );
    }

    /**
     * Exporta reservas a CSV con rate limiting y encabezados de privacidad.
     */
    public function admin_export_bookings( \WP_REST_Request $request ): void {
        if ( ! $this->rate_limiter_allows_csv_export() ) {
            wp_send_json( [ 'error' => 'Too many export requests. Please wait before downloading again.' ], 429 );
            return;
        }

        $args     = $this->build_booking_query_args( $request, true );
        $page     = $args['_page'];
        $per_page = $args['_per_page'];
        unset( $args['_page'], $args['_per_page'] );

        $bookings     = $this->booking_repo->find_all( $args );
        $total_rows   = count( $bookings );
        $service_map  = $this->resolve_service_map( $bookings );
        $customer_map = $this->resolve_customer_map( $bookings );

        $csv_body = $this->export_service->export_csv(
            $bookings,
            $this->export_service->get_business_timezone(),
            $service_map,
            $customer_map
        );

        $this->log_csv_export( $total_rows, $request, $page );
        $this->emit_csv_response( $csv_body, $page, $per_page, $total_rows );
    }

    /**
     * Crea una reserva desde el panel administrativo.
     */
    public function admin_create_booking( \WP_REST_Request $request ): \WP_REST_Response {
        $body    = $this->decode_json_body( $request );
        $context = Booking_Request_Context::admin( get_current_user_id() );
        $result  = $this->create_booking_use_case->execute( $body, $context );

        if ( ! empty( $result['error'] ) ) {
            return new \WP_REST_Response( [ 'error' => $result['error'] ], $result['code'] ?? 400 );
        }

        return new \WP_REST_Response( $result, 201 );
    }

    /**
     * Maneja GET, PATCH y DELETE sobre una reserva individual (admin).
     */
    public function admin_booking_action( \WP_REST_Request $request ): \WP_REST_Response {
        $method = $request->get_method();

        if ( 'GET' === $method ) {
            return $this->handle_admin_get_booking( $request );
        }

        if ( 'PATCH' === $method ) {
            return $this->handle_admin_patch_booking( $request );
        }

        if ( 'DELETE' === $method ) {
            return $this->handle_admin_delete_booking( $request );
        }

        return new \WP_REST_Response( [ 'error' => 'Metodo no soportado.' ], 405 );
    }

    /**
     * Devuelve la linea de tiempo de cambios de una reserva.
     */
    public function admin_booking_timeline( \WP_REST_Request $request ): \WP_REST_Response {
        $booking_id = absint( $request['id'] );
        $booking    = $this->booking_repo->find( $booking_id );

        if ( ! $booking ) {
            return Rest_Error_Helper::not_found( 'booking' );
        }

        return new \WP_REST_Response( [
            'booking_id' => $booking_id,
            'timeline'   => $this->timeline_repo->get_timeline_events( $booking_id ),
        ], 200 );
    }

    // ─── Helpers ───────────────────────────────────────

    /**
     * Construye los argumentos de consulta desde los parametros HTTP.
     */
    private function build_booking_query_args( \WP_REST_Request $request, bool $with_pagination = false ): array {
        $args = [];

        if ( $with_pagination ) {
            $page              = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
            $per_page          = min( 1000, max( 100, absint( $request->get_param( 'per_page' ) ?: 1000 ) ) );
            $args['limit']     = $per_page;
            $args['offset']    = ( $page - 1 ) * $per_page;
            $args['order_by']  = 'start_at';
            $args['order']     = 'ASC';
            $args['_page']     = $page;
            $args['_per_page'] = $per_page;
        }

		if ( $request->get_param( 'limit' ) ) {
			$args['limit'] = min( 1000, max( 1, absint( $request->get_param( 'limit' ) ) ) );
		}
        if ( $request->get_param( 'offset' ) ) {
            $args['offset'] = absint( $request->get_param( 'offset' ) );
        }
        if ( $request->get_param( 'service_id' ) ) {
            $args['service_id'] = absint( $request->get_param( 'service_id' ) );
        }
        if ( $request->get_param( 'customer_id' ) ) {
            $args['customer_id'] = absint( $request->get_param( 'customer_id' ) );
        }
        if ( $request->get_param( 'status' ) ) {
            $args['status'] = sanitize_text_field( $request->get_param( 'status' ) );
        }
        if ( $request->get_param( 'date_from' ) ) {
            $args['date_from'] = $this->export_service->local_date_to_utc(
                sanitize_text_field( $request->get_param( 'date_from' ) ), 'start'
            );
        }
        if ( $request->get_param( 'date_to' ) ) {
            $args['date_to'] = $this->export_service->local_date_to_utc(
                sanitize_text_field( $request->get_param( 'date_to' ) ), 'end'
            );
        }

        return $args;
    }

    /**
     * Enriquece una lista de reservas con datos de servicios y clientes.
     */
    private function enrich_bookings( array $bookings ): array {
        if ( empty( $bookings ) ) {
            return [];
        }

        $service_ids  = array_unique( array_filter( array_map( fn( $b ) => $b->service_id, $bookings ) ) );
        $customer_ids = array_unique( array_filter( array_map( fn( $b ) => $b->customer_id, $bookings ) ) );

        $service_map  = $this->service_repo->find_by_ids( $service_ids );
        $customer_map = $this->customer_repo->find_by_ids( $customer_ids );

        return Booking_Response_Mapper::admin_list( $bookings, $service_map, $customer_map );
    }

    /**
     * Despacha una accion administrativa (confirmar, cancelar, no_show, reagendar).
     */
    private function dispatch_admin_action(
        string $action,
        int $id,
        array $body,
        Booking_Request_Context $context,
    ): array {
        return match ( $action ) {
            'confirm'     => $this->confirm_booking_use_case->execute( $id, $context ),
            'cancel'      => $this->cancel_booking_use_case->execute( $id, 'admin', $body['reason'] ?? null, null, $context ),
            'no_show'     => $this->mark_no_show_use_case->execute( $id, $context ),
            'reschedule'  => $this->reschedule_booking_use_case->execute( $id, $body['start_at'] ?? '', $body['resource_id'] ?? null, null, $context ),
            default       => [ 'error' => 'Accion no valida.', 'code' => 400 ],
        };
    }

    /**
     * Verifica que el rate limiter permita una exportacion CSV.
     */
    private function rate_limiter_allows_csv_export(): bool {
        if ( ! $this->rate_limiter ) {
            return true;
        }

        return $this->rate_limiter->check( 'csv_export', (string) get_current_user_id(), 5, 5 * MINUTE_IN_SECONDS );
    }

    /**
     * Resuelve el mapa de servicios para un conjunto de reservas.
     */
    private function resolve_service_map( array $bookings ): array {
        $service_ids = array_unique( array_filter( array_map( fn( $b ) => $b->service_id, $bookings ) ) );

        return $this->service_repo->find_by_ids( $service_ids );
    }

    /**
     * Resuelve el mapa de clientes para un conjunto de reservas.
     */
    private function resolve_customer_map( array $bookings ): array {
        $customer_ids = array_unique( array_filter( array_map( fn( $b ) => $b->customer_id, $bookings ) ) );

        return $this->customer_repo->find_by_ids( $customer_ids );
    }

    /**
     * Deja trazabilidad de una exportacion CSV.
     */
    private function log_csv_export( int $total_rows, \WP_REST_Request $request, int $page ): void {
        $this->audit_logger->log( [
            'entity_type' => 'export',
            'entity_id'   => 0,
            'action'      => 'export_bookings_csv',
            'actor_type'  => 'admin',
            'message'     => "Bookings CSV exported ({$total_rows} rows).",
            'context'     => [
                'rows'       => $total_rows,
                'page'       => $page,
                'service_id' => $request->get_param( 'service_id' ),
                'status'     => $request->get_param( 'status' ),
                'date_from'  => $request->get_param( 'date_from' ),
                'date_to'    => $request->get_param( 'date_to' ),
            ],
            'severity' => 'info',
        ] );
    }

    /**
     * Emite la respuesta HTTP con el archivo CSV y encabezados de seguridad.
     */
    private function emit_csv_response( string $csv_body, int $page, int $per_page, int $total_rows ): void {
        $csv = "\xEF\xBB\xBF" . $csv_body;

        if ( ! headers_sent() ) {
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="openbooking-bookings.csv"' );
            header( 'Content-Length: ' . strlen( $csv ) );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );
            header( 'X-Page: ' . $page );
            header( 'X-Per-Page: ' . $per_page );
            header( 'X-Total-Rows: ' . $total_rows );
            header( 'X-PII-Warning: Este archivo contiene datos personales. Almacenalo de forma segura y eliminalo cuando ya no lo necesites.' );
        }

        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSV binary output.
        exit;
    }

    /**
     * Maneja la consulta GET de una reserva individual (admin).
     */
    private function handle_admin_get_booking( \WP_REST_Request $request ): \WP_REST_Response {
        $id      = absint( $request['id'] );
        $booking = $this->booking_repo->find( $id );

        if ( ! $booking ) {
            return Rest_Error_Helper::not_found( 'booking' );
        }

        $data = $this->enrich_bookings( [ $booking ] );

        return new \WP_REST_Response( [ 'booking' => $data[0] ], 200 );
    }

    /**
     * Maneja la actualizacion PATCH de una reserva (admin).
     */
    private function handle_admin_patch_booking( \WP_REST_Request $request ): \WP_REST_Response {
        $id      = absint( $request['id'] );
        $body    = $this->decode_json_body( $request );
        $action  = sanitize_text_field( $body['action'] ?? '' );
        $context = Booking_Request_Context::admin( get_current_user_id() );

        if ( 'update_notes' === $action || ( '' === $action && array_key_exists( 'notes_internal', $body ) ) ) {
            return $this->handle_admin_update_notes( $id, $body );
        }

        $result  = $this->dispatch_admin_action( $action, $id, $body, $context );

        if ( ! empty( $result['error'] ) ) {
            return new \WP_REST_Response( [ 'error' => $result['error'] ], $result['code'] ?? 400 );
        }

        return new \WP_REST_Response( $result, 200 );
    }

    /**
     * Actualiza notas internas sin disparar transiciones de estado.
     */
    private function handle_admin_update_notes( int $id, array $body ): \WP_REST_Response {
        $booking = $this->booking_repo->find( $id );
        if ( ! $booking ) {
            return Rest_Error_Helper::not_found( 'booking' );
        }

        if ( ! array_key_exists( 'notes_internal', $body ) ) {
            return new \WP_REST_Response( [ 'error' => 'notes_internal is required.' ], 400 );
        }

        $before = $booking->to_array();
        $booking->notes_internal = sanitize_textarea_field( (string) $body['notes_internal'] );
        $this->booking_repo->update( $booking );

        $this->audit_logger->log_entity_change(
            'booking',
            $booking->id ?? $id,
            'booking_notes_updated',
            $before,
            $booking->to_array(),
            [],
            [ 'message' => 'Booking internal notes updated from admin.' ]
        );

        $data = $this->enrich_bookings( [ $booking ] );

        return new \WP_REST_Response( [ 'success' => true, 'booking' => $data[0] ], 200 );
    }

    /**
     * Maneja la eliminacion DELETE de una reserva (admin).
     */
    private function handle_admin_delete_booking( \WP_REST_Request $request ): \WP_REST_Response {
        $id      = absint( $request['id'] );
        $booking = $this->booking_repo->find( $id );

        if ( ! $booking ) {
            return Rest_Error_Helper::not_found( 'booking' );
        }

        $context = Booking_Request_Context::admin( get_current_user_id() );
        $result  = $this->cancel_booking_use_case->execute( $id, 'admin', 'Eliminada desde admin', null, $context );

        if ( ! empty( $result['error'] ) ) {
            return new \WP_REST_Response( [ 'error' => $result['error'] ], $result['code'] ?? 400 );
        }

        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * Decodifica el body JSON de la request.
     */
    private function decode_json_body( \WP_REST_Request $request ): array {
        $body = json_decode( $request->get_body(), true );

        return is_array( $body ) ? $body : [];
    }
}
