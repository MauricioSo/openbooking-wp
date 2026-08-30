<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Core;

use OpenBooking\Application\Core\Service\Health_Check_Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone el estado de salud publico y detallado del sistema.
 */
class Health_Controller {


    public function __construct(
        private Health_Check_Service $health_service, // verifica salud del sistema
    ) {}

    public function health_check( \WP_REST_Request $request ): \WP_REST_Response {
        $start = microtime( true );

        $data = $this->can_view_health_details( $request )
            ? $this->health_service->get_detailed_health()
            : $this->health_service->get_public_health();

        $response = new \WP_REST_Response( $data, $data['status'] === 'error' ? 503 : 200 );

        return $this->finish_timed( 'health', $start, $response );
    }

    /**
     * Decide si el request puede ver detalles internos de salud.
     */
    private function can_view_health_details( \WP_REST_Request $request ): bool {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce ) {
            $nonce = $request->get_header( 'x-wp-nonce' );
        }

        return (bool) ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) );
    }

    /**
     * Registra el tiempo de ejecucion del endpoint antes de devolver la respuesta.
     */
    private function finish_timed( string $endpoint, float $start, \WP_REST_Response $response ): \WP_REST_Response {
        $ms       = ( microtime( true ) - $start ) * 1000;
        $is_error = $response->get_status() >= 400;
        \OpenBooking\Infrastructure\WordPress\Metrics\Request_Metrics::record( $endpoint, $ms, $is_error );

        return $response;
    }
}
