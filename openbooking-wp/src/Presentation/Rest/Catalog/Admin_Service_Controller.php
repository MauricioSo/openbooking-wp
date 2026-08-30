<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Catalog;

use OpenBooking\Support\Currency_Helper;
use OpenBooking\Support\Service_Payloads;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone operaciones administrativas sobre servicios del catalogo.
 */
class Admin_Service_Controller {
    private const SERVICE_UPDATABLE_FIELDS = [
        'name', 'slug', 'description', 'duration_minutes',
        'buffer_before_minutes', 'buffer_after_minutes',
        'price_minor', 'currency', 'capacity', 'mode', 'status',
        'color', 'visibility',
    ];


    public function __construct(
        private \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface $service_repo, // consulta servicios del catalogo
        private \OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface $audit_repo, // consulta trazabilidad
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo, // consulta y persiste reservas
        private \OpenBooking\Application\Catalog\Service\Service_Crud_Service $crud_service, // CRUD de servicios
    ) {}

    public function admin_get_services( \WP_REST_Request $request ): \WP_REST_Response {
        $services = $this->service_repo->find_all();

        return new \WP_REST_Response( [ 'services' => array_map( fn( $service ) => Service_Payloads::public_from_entity( $service ), $services ) ], 200 );
    }

    public function admin_create_service( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );
        $result = $this->crud_service->create( $body, [ Currency_Helper::class, 'sanitize_supported_currency' ] );

        if ( isset( $result['error'] ) ) {
            return new \WP_REST_Response( [ 'error' => $result['error'] ], 400 );
        }

        return new \WP_REST_Response( [ 'success' => true, 'id' => $result['id'], 'message' => 'Servicio creado correctamente.' ], 201 );
    }

    public function admin_service_action( \WP_REST_Request $request ): \WP_REST_Response {
        $id     = absint( $request['id'] );
        $method = $request->get_method();

        if ( 'GET' === $method ) {
            $service = $this->service_repo->find( $id );

            return new \WP_REST_Response( [ 'service' => $service ? Service_Payloads::public_from_entity( $service ) : null ], $service ? 200 : 404 );
        }

        if ( 'PATCH' === $method ) {
            $body = $this->decode_json_body( $request );
            $result = $this->crud_service->update( $id, $body, [ Currency_Helper::class, 'sanitize_supported_currency' ] );

            if ( isset( $result['error'] ) ) {
                $status = isset( $result['code'] ) ? 409 : ( isset( $result['error'] ) && $result['error'] === 'Servicio no encontrado.' ? 404 : 400 );

                return new \WP_REST_Response( $result, $status );
            }

            return new \WP_REST_Response( [ 'success' => true, 'message' => 'Servicio actualizado correctamente.' ], 200 );
        }

        if ( 'DELETE' === $method ) {
            $body = $this->decode_json_body( $request );
            $force = ! empty( $body['force'] ?? '' );
            $result = $this->crud_service->delete( $id, $force );

            if ( isset( $result['error'] ) ) {
                return new \WP_REST_Response( [ 'error' => $result['error'] ], 404 );
            }

            $action = $result['action'];

            return new \WP_REST_Response( [ 'success' => true, 'action' => $action, 'message' => $action === 'deleted' ? 'Servicio eliminado correctamente.' : 'Servicio archivado correctamente.' ], 200 );
        }

        return new \WP_REST_Response( [ 'error' => 'Método no soportado.' ], 405 );
    }

    public function admin_restore_service( \WP_REST_Request $request ): \WP_REST_Response {
        $id     = absint( $request['id'] );
        $entity = $this->service_repo->find( $id );

        if ( ! $entity ) {
            return new \WP_REST_Response( [ 'error' => 'Servicio no encontrado.' ], 404 );
        }

        $this->service_repo->restore( $id );

        return new \WP_REST_Response( [ 'success' => true, 'message' => 'Servicio restaurado correctamente.' ], 200 );
    }

    /**
     * Decodifica el body JSON de forma segura.
     */
    private function decode_json_body( \WP_REST_Request $request ): array {
        $body = json_decode( $request->get_body(), true );

        return is_array( $body ) ? $body : [];
    }

}
