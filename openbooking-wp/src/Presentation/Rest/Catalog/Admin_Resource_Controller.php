<?php


declare( strict_types=1 );
namespace OpenBooking\Presentation\Rest\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Traduce comandos HTTP en llamadas al dominio de catalogo.
 */

class Admin_Resource_Controller {

    public function __construct(
        private \OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface $resource_repo, // consulta recursos del catalogo
        private \OpenBooking\Application\Catalog\Service\Resource_Crud_Service $crud_service, // CRUD de recursos
    ) {}

    public function admin_get_resources( \WP_REST_Request $request ): \WP_REST_Response {
        $resources = $this->resource_repo->find_all();
        $data = array_map( function ( $r ) {
            $arr = $r->to_array();
            $arr['service_ids']   = $this->resource_repo->get_service_ids_for_resource( $r->id );
            $arr['service_names'] = $this->resource_repo->get_service_names_for_resource( $r->id );
            return $arr;
        }, $resources );
        return new \WP_REST_Response( [ 'resources' => $data ], 200 );
    }

    public function admin_create_resource( \WP_REST_Request $request ): \WP_REST_Response {
        $body = json_decode( $request->get_body(), true ) ?: [];
        $result = $this->crud_service->create( $body );
        if ( isset( $result['error'] ) ) {
            return new \WP_REST_Response( [ 'error' => $result['error'] ], 400 );
        }
        return new \WP_REST_Response( [ 'success' => true, 'id' => $result['id'], 'message' => 'Recurso creado correctamente.' ], 201 );
    }

    public function admin_resource_action( \WP_REST_Request $request ): \WP_REST_Response {
        $id     = absint( $request['id'] );
        $method = $request->get_method();
        if ( $method === 'GET' ) {
            $resource = $this->resource_repo->find( $id );
            if ( ! $resource ) {
                return new \WP_REST_Response( [ 'error' => 'Recurso no encontrado.' ], 404 );
            }
            return new \WP_REST_Response( [ 'resource' => $resource->to_array() ], 200 );
        }

        if ( $method === 'PATCH' ) {
            $body = json_decode( $request->get_body(), true ) ?: [];
            $result = $this->crud_service->update( $id, $body );
            if ( isset( $result['error'] ) ) {
                return new \WP_REST_Response( [ 'error' => $result['error'] ], 404 );
            }
            return new \WP_REST_Response( [ 'success' => true, 'message' => 'Recurso actualizado correctamente.' ], 200 );
        }

        if ( $method === 'DELETE' ) {
            $force = ! empty( json_decode( $request->get_body(), true )['force'] ?? '' );
            $result = $this->crud_service->delete( $id, $force );
            if ( isset( $result['error'] ) ) {
                return new \WP_REST_Response( [ 'error' => $result['error'] ], 404 );
            }
            $action = $result['action'];
            return new \WP_REST_Response( [ 'success' => true, 'action' => $action, 'message' => $action === 'deleted' ? 'Recurso eliminado correctamente.' : 'Recurso archivado correctamente.' ], 200 );
        }

        return new \WP_REST_Response( [ 'error' => 'Método no soportado.' ], 405 );
    }

    public function admin_restore_resource( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = absint( $request['id'] );
        $entity = $this->resource_repo->find( $id );
        if ( ! $entity ) {
            return new \WP_REST_Response( [ 'error' => 'Recurso no encontrado.' ], 404 );
        }
        $this->resource_repo->restore( $id );
        return new \WP_REST_Response( [ 'success' => true, 'message' => 'Recurso restaurado correctamente.' ], 200 );
    }
}
