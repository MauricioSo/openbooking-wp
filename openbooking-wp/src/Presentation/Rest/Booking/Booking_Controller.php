<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Booking;

use OpenBooking\Application\Booking\Service\Booking_Request_Context;
use OpenBooking\Presentation\Rest\Core\Rest_Error_Helper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone las operaciones publicas de reservas.
 */
class Booking_Controller {

    public function __construct(
        private \OpenBooking\Application\Booking\UseCase\Create_Booking_Use_Case $create_booking_use_case,
        private \OpenBooking\Application\Booking\Service\Booking_Public_Service $public_service,
        private \OpenBooking\Application\Booking\UseCase\Cancel_Booking_Use_Case $cancel_booking_use_case,
        private \OpenBooking\Application\Booking\UseCase\Reschedule_Booking_Use_Case $reschedule_booking_use_case,
        private \OpenBooking\Application\Payment\Service\Payment_Service $payment_service,                          // orquesta ciclo de pago
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo,                       // consulta y persiste reservas
        private \OpenBooking\Domain\Booking\Repository\PublicFormFieldRepositoryInterface $form_field_repo,            // campos del formulario publico
    ) {}

    // ─── Endpoints Publicos ────────────────────────────

    /**
     * Crea una reserva con proteccion anti-spam.
     */
    public function create_booking( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_body( $request );

        $spam_error = $this->check_spam_protection( $body );
        if ( null !== $spam_error ) {
            return $spam_error;
        }

        $context = Booking_Request_Context::public();
        $result  = $this->create_booking_use_case->execute( $body, $context );

        return $this->respond_result( $result, 201 );
    }

    /**
     * Previsualiza la confirmacion de asistencia por token.
     */
    public function preview_attendance_by_token( \WP_REST_Request $request ): \WP_REST_Response {
        $token  = sanitize_text_field( $request['token'] ?? '' );
        $result = $this->public_service->preview_attendance_by_token( $token );

        return $this->respond_result( $result, 200 );
    }

    /**
     * Confirma la asistencia a una reserva por token.
     */
    public function confirm_attendance_by_token( \WP_REST_Request $request ): \WP_REST_Response {
        $token  = sanitize_text_field( $request['token'] ?? '' );
        $result = $this->public_service->confirm_attendance_by_token( $token );

        return $this->respond_result( $result, 200 );
    }

    /**
     * Cancela una reserva usando el token del enlace de cancelacion.
     */
    public function cancel_by_token( \WP_REST_Request $request ): \WP_REST_Response {
        $token   = sanitize_text_field( $request['token'] ?? '' );
        $booking = $this->booking_repo->find_by_cancel_token( $token );

        if ( ! $booking ) {
            return new \WP_REST_Response( [ 'error' => 'El enlace de cancelacion no es valido o ha expirado.' ], 404 );
        }

        $body   = $this->decode_body( $request );
        $result = $this->cancel_booking_use_case->execute( $booking->id, 'customer', $body['reason'] ?? null, $token );

        return $this->respond_result( $result, 200 );
    }

    /**
     * Reagenda una reserva usando el token del enlace de reprogramacion.
     */
    public function reschedule_by_token( \WP_REST_Request $request ): \WP_REST_Response {
        $token   = sanitize_text_field( $request['token'] ?? '' );
        $booking = $this->booking_repo->find_by_reschedule_token( $token );

        if ( ! $booking ) {
            return new \WP_REST_Response( [ 'error' => 'El enlace de reprogramacion no es valido o ha expirado.' ], 404 );
        }

        $body   = $this->decode_body( $request );
        $result = $this->reschedule_booking_use_case->execute( $booking->id, $body['start_at'] ?? '', $body['resource_id'] ?? null, $token );

        return $this->respond_result( $result, 200 );
    }

    /**
     * Consulta una reserva publica por token.
     */
    public function get_public_booking_by_token( \WP_REST_Request $request ): \WP_REST_Response {
        $token  = sanitize_text_field( $request['token'] ?? '' );
        $result = $this->public_service->get_public_booking_by_token( $token );

        return $this->respond_result( $result, 200 );
    }

    /**
     * Renueva el hold de pago para una reserva por token.
     */
    public function renew_payment_hold_by_token( \WP_REST_Request $request ): \WP_REST_Response {
        $token  = sanitize_text_field( $request['token'] ?? '' );
        $result = $this->payment_service->renew_hold_for_booking_token( $token );

        return $this->respond_result( $result, 200 );
    }

    /**
     * Verifica el estado de una reserva por token de visualizacion.
     */
    public function get_public_booking_status( \WP_REST_Request $request ): \WP_REST_Response {
        $token   = sanitize_text_field( $request['token'] ?? '' );
        $booking = $this->booking_repo->find_by_view_token( $token );

        if ( ! $booking ) {
            return Rest_Error_Helper::token_not_found();
        }

        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * Devuelve los campos habilitados para el formulario publico.
     */
    public function get_form_fields_public( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'fields' => $this->form_field_repo->find_enabled_for_public_form() ], 200 );
    }

    // ─── Helpers ───────────────────────────────────────

    /**
     * Verifica protecciones anti-spam: honeypot y velocidad de envio.
     *
     * @return \WP_REST_Response|null Respuesta de error si se detecta spam, null si pasa.
     */
    private function check_spam_protection( array $body ): ?\WP_REST_Response {
        if ( ! empty( $body['_obwp_hp'] ) ) {
            return new \WP_REST_Response( [ 'error' => 'Spam detectado.' ], 400 );
        }

        $loaded_at = (int) ( $body['_obwp_loaded_at'] ?? 0 );
        if ( $loaded_at > 0 && ( time() - $loaded_at ) < 3 ) {
            return new \WP_REST_Response( [ 'error' => 'Envio demasiado rapido. Intenta de nuevo.' ], 400 );
        }

        return null;
    }

    /**
     * Construye una respuesta REST a partir del resultado de un caso de uso.
     */
    private function respond_result( array $result, int $ok_code ): \WP_REST_Response {
        if ( ! empty( $result['error'] ) ) {
            return new \WP_REST_Response( [ 'error' => $result['error'] ], $result['code'] ?? 400 );
        }

        return new \WP_REST_Response( $result, $ok_code );
    }

    /**
     * Decodifica el body JSON, con fallback a parametros de formulario.
     */
    private function decode_body( \WP_REST_Request $request ): array {
        $raw  = $request->get_body();
        $body = ( is_string( $raw ) && $raw !== '' ) ? json_decode( $raw, true ) : null;

        return is_array( $body ) && ! empty( $body ) ? $body : $request->get_params();
    }
}
