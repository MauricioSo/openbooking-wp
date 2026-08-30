<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Core;

use OpenBooking\Application\Core\Service\Dashboard_Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone datos resumidos del dashboard para el panel administrativo.
 */
class Admin_Dashboard_Controller {

    public function __construct( private Dashboard_Service $dashboard_service ) {}

    public function admin_get_kpis( \WP_REST_Request $request ): \WP_REST_Response {
        $response = [
            'kpis'      => $this->read_database_kpis(),
            'endpoints' => $this->read_endpoint_stats(),
            'timestamp' => current_time( 'mysql', true ),
        ];

        return new \WP_REST_Response( $response, 200 );
    }

    public function admin_dashboard( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( $this->dashboard_service->get_dashboard_data(), 200 );
    }

    /**
     * Lee las metricas tecnicas de la base de datos.
     */
    private function read_database_kpis(): array {
        return \OpenBooking\Infrastructure\WordPress\Metrics\Request_Metrics::get_db_kpis();
    }

    /**
     * Lee las metricas de consumo de endpoints.
     */
    private function read_endpoint_stats(): array {
        return \OpenBooking\Infrastructure\WordPress\Metrics\Request_Metrics::get_all_stats();
    }
}
