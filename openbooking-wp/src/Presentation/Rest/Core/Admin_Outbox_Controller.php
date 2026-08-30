<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Core;

use OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface;
use OpenBooking\Domain\Shared\Repository\OutboxEventRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone acciones manuales para revisar y corregir la cola outbox.
 */
class Admin_Outbox_Controller {


    public function __construct(
        private OutboxEventRepositoryInterface $repo, // persiste eventos del outbox
        private AuditRepositoryInterface $audit, // consulta trazabilidad
    ) {}

    public function admin_get_outbox_events( \WP_REST_Request $request ): \WP_REST_Response {
        $status = sanitize_key( (string) $request->get_param( 'status' ) );
        $allowed_statuses = [ '', 'pending', 'processing', 'processed', 'failed', 'dead', 'ignored' ];

        if ( ! in_array( $status, $allowed_statuses, true ) ) {
            return Rest_Error_Helper::validation_error( 'Invalid outbox status filter.' );
        }

        $limit  = min( 100, max( 1, absint( $request->get_param( 'limit' ) ?: 50 ) ) );
        $offset = max( 0, absint( $request->get_param( 'offset' ) ?: 0 ) );

        return new \WP_REST_Response( [
            'events' => $this->repo->list_recent( $status, $limit, $offset ),
            'counts' => $this->repo->counts_by_status(),
            'limit'  => $limit,
            'offset' => $offset,
        ], 200 );
    }

    public function admin_retry_outbox_event( \WP_REST_Request $request ): \WP_REST_Response {
        $id = absint( $request['id'] ?? 0 );

        if ( ! $id ) {
            return Rest_Error_Helper::validation_error( 'Invalid outbox event id.' );
        }

        if ( ! $this->repo->retry_failed( $id ) ) {
            return Rest_Error_Helper::invalid_state_transition( 'failed', 'pending' );
        }

        $this->audit->insert( [
            'entity_type' => 'outbox_event',
            'entity_id'   => $id,
            'action'      => 'outbox_event_retried',
            'actor_type'  => 'admin',
            'actor_id'    => get_current_user_id(),
            'message'     => 'Outbox event manually retried from admin.',
            'severity'    => 'info',
            'context'     => [ 'outbox_event_id' => $id ],
        ] );

        return new \WP_REST_Response( [ 'ok' => true, 'id' => $id, 'status' => 'pending' ], 200 );
    }

    public function admin_ignore_outbox_event( \WP_REST_Request $request ): \WP_REST_Response {
        $id = absint( $request['id'] ?? 0 );

        if ( ! $id ) {
            return Rest_Error_Helper::validation_error( 'Invalid outbox event id.' );
        }

        if ( ! $this->repo->ignore( $id ) ) {
            return Rest_Error_Helper::invalid_state_transition( 'current', 'ignored' );
        }

        $this->audit->insert( [
            'entity_type' => 'outbox_event',
            'entity_id'   => $id,
            'action'      => 'outbox_event_ignored',
            'actor_type'  => 'admin',
            'actor_id'    => get_current_user_id(),
            'message'     => 'Outbox event manually ignored from admin.',
            'severity'    => 'warning',
            'context'     => [ 'outbox_event_id' => $id ],
        ] );

        return new \WP_REST_Response( [ 'ok' => true, 'id' => $id, 'status' => 'ignored' ], 200 );
    }
}
