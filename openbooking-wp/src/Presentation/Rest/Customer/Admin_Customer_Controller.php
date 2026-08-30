<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Customer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone operaciones administrativas sobre clientes y sus reservas.
 */
class Admin_Customer_Controller {

    public function __construct(
        private \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface $customer_repo, // consulta datos de clientes
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo, // consulta y persiste reservas
        private \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface $service_repo, // consulta servicios del catalogo
        private \OpenBooking\Application\Audit\Service\Audit_Logger $audit_logger, // deja trazabilidad de cambios
        private \OpenBooking\Application\Customer\Service\Customer_Crud_Service $crud_service, // CRUD de clientes
    ) {}

    public function admin_get_customers( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [];
        if ( $request->get_param( 'search' ) ) {
            $args['search'] = sanitize_text_field( $request->get_param( 'search' ) );
        }
		if ( $request->get_param( 'limit' ) ) {
			$args['limit'] = min( 500, max( 1, absint( $request->get_param( 'limit' ) ) ) );
		}

        $customers = $this->customer_repo->find_all( $args );

        return new \WP_REST_Response( [ 'customers' => array_map( fn( $customer ) => $customer->to_array(), $customers ) ], 200 );
    }

    public function admin_customer_action( \WP_REST_Request $request ): \WP_REST_Response {
        $id     = absint( $request['id'] );
        $method = $request->get_method();
        if ( $method === 'GET' ) {
            $customer = $this->customer_repo->find( $id );
            if ( ! $customer ) {
                return new \WP_REST_Response( [ 'error' => 'Cliente no encontrado.' ], 404 );
            }

            $bookings = $this->booking_repo->find_all( [ 'customer_id' => $id, 'limit' => 50 ] );
            $enriched = array_map( fn( $booking ) => $this->crud_service->enrich_booking( $booking ), $bookings );

            return new \WP_REST_Response( [
                'customer' => $customer->to_array(),
                'bookings' => $enriched,
            ], 200 );
        }

        if ( $method === 'PATCH' ) {
            $body = $this->decode_json_body( $request );
            $result = $this->crud_service->update( $id, $body );
            if ( isset( $result['error'] ) ) {
                return new \WP_REST_Response( [ 'error' => $result['error'] ], 404 );
            }
            return new \WP_REST_Response( [ 'success' => true, 'customer' => $result['customer'], 'message' => 'Cliente guardado correctamente.' ], 200 );
        }

        if ( $method === 'DELETE' ) {
            $result = $this->crud_service->anonymize( $id );
            if ( isset( $result['error'] ) ) {
                return new \WP_REST_Response( [ 'error' => $result['error'] ], $result['code'] ?? 404 );
            }

            return new \WP_REST_Response( [ 'success' => true, 'customer' => $result['customer'], 'message' => 'Cliente anonimizado correctamente.' ], 200 );
        }

        return new \WP_REST_Response( [ 'error' => 'Método no soportado.' ], 405 );
    }

    /**
     * Decodifica el body JSON de forma segura.
     */
    private function decode_json_body( \WP_REST_Request $request ): array {
        $body = json_decode( $request->get_body(), true );

        return is_array( $body ) ? $body : [];
    }
}
