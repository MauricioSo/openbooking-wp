<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Audit;

use OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface;
use OpenBooking\Application\Audit\Service\Audit_Enrichment_Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone la consulta administrativa de registros de auditoria.
 */
class Admin_Audit_Controller {


    public function __construct(
        private AuditRepositoryInterface $audit_repo, // consulta trazabilidad
        private Audit_Enrichment_Service $enrichment, // enriquece entradas de auditoria
    ) {}

    public function register_routes( string $namespace, $permission_callback ): void {
        register_rest_route( $namespace, '/admin/audit-logs', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'admin_get_audit_logs' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/audit-logs/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'admin_get_audit_log' ],
            'permission_callback' => $permission_callback,
        ] );
    }

    public function admin_get_audit_logs( \WP_REST_Request $request ): \WP_REST_Response {
        $limit = $request->get_param( 'limit' ) ? min( 100, max( 1, absint( $request->get_param( 'limit' ) ) ) ) : 20;
        $args  = $this->build_query_args( $request, $limit );

        $logs  = $this->enrichment->enrich_logs( $this->audit_repo->find_all( $args ) );
        $total = $this->audit_repo->count_all( $args );

        return new \WP_REST_Response( [
            'logs'       => $logs,
            'pagination' => [
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $args['offset'],
            ],
        ], 200 );
    }

    public function admin_get_audit_log( \WP_REST_Request $request ): \WP_REST_Response {
        $log  = $this->audit_repo->find( absint( $request['id'] ) );

        if ( ! $log ) {
            return new \WP_REST_Response( [ 'error' => 'Audit log no encontrado.' ], 404 );
        }

        return new \WP_REST_Response( [ 'log' => $this->enrichment->enrich_log( $log ) ], 200 );
    }

    /**
     * Construye filtros legibles para la busqueda de audit logs.
     */
    private function build_query_args( \WP_REST_Request $request, int $limit ): array {
        $args = [
            'limit'    => $limit,
            'offset'   => $request->get_param( 'offset' ) ? absint( $request->get_param( 'offset' ) ) : 0,
            'order_by' => sanitize_text_field( $request->get_param( 'order_by' ) ?: 'created_at' ),
            'order'    => sanitize_text_field( $request->get_param( 'order' ) ?: 'DESC' ),
        ];

        foreach ( [ 'entity_type', 'action', 'actor_type', 'date_from', 'date_to', 'search', 'request_id' ] as $key ) {
            if ( null !== $request->get_param( $key ) && '' !== $request->get_param( $key ) ) {
                $args[ $key ] = sanitize_text_field( $request->get_param( $key ) );
            }
        }

        foreach ( [ 'entity_id', 'actor_id' ] as $key ) {
            if ( null !== $request->get_param( $key ) && '' !== $request->get_param( $key ) ) {
                $args[ $key ] = absint( $request->get_param( $key ) );
            }
        }

        return $args;
    }
}
