<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone estado y ejecucion manual de tareas cron.
 */
class Admin_Cron_Controller {


    public function __construct(
        private \OpenBooking\Application\Core\Service\Cron_Status_Service $service,
    ) {}

    public function admin_cron_status( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( $this->service->get_status(), 200 );
    }

    public function admin_cron_trigger( \WP_REST_Request $request ): \WP_REST_Response {
        $event = sanitize_key( $request['event'] ?? '' );
        $result = $this->service->trigger_event( $event );

        if ( ! $result['success'] ) {
            return Rest_Error_Helper::validation_error( $result['error'] );
        }

        return new \WP_REST_Response( $result, 200 );
    }
}
