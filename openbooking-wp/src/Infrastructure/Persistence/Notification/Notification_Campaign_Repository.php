<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Persistence\Notification;

use OpenBooking\Domain\Notification\Repository\NotificationCampaignRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de notificaciones.
 */

/**
 * Persiste campañas de notificacion y su progreso.
 */
class Notification_Campaign_Repository implements NotificationCampaignRepositoryInterface {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_notification_campaigns';
    }

    public function create( array $data ): int {
        $this->wpdb->insert( $this->table, [
            'type'             => \sanitize_key( $data['type'] ?? 'broadcast' ),
            'title'            => \sanitize_text_field( $data['title'] ?? 'Campaign' ),
            'message_email'    => isset( $data['message_email'] ) ? \wp_kses_post( (string) $data['message_email'] ) : null,
            'message_whatsapp' => isset( $data['message_whatsapp'] ) ? \sanitize_textarea_field( (string) $data['message_whatsapp'] ) : null,
            'scope_json'       => ! empty( $data['scope_json'] ) ? \wp_json_encode( $data['scope_json'] ) : null,
            'total_targets'    => \absint( $data['total_targets'] ?? 0 ),
            'total_sent'       => \absint( $data['total_sent'] ?? 0 ),
            'total_failed'     => \absint( $data['total_failed'] ?? 0 ),
            'status'           => \sanitize_key( $data['status'] ?? 'draft' ),
            'created_by'       => \absint( $data['created_by'] ?? 0 ) ?: null,
            'finished_at'      => $data['finished_at'] ?? null,
        ] );

        return (int) $this->wpdb->insert_id;
    }

    public function find( int $id ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function find_all( array $args = [] ): array {
        $limit  = ! empty( $args['limit'] ) ? absint( $args['limit'] ) : 50;
        $offset = ! empty( $args['offset'] ) ? absint( $args['offset'] ) : 0;
        $rows   = $this->wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", ARRAY_A );
        return $rows ?: [];
    }

    public function update_progress( int $campaign_id ): void {
        $queue_table = $this->wpdb->prefix . 'ob_notification_queue';
        $stats = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT COUNT(*) AS total_targets, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS total_sent, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS total_failed, SUM(CASE WHEN status IN ('pending','processing') THEN 1 ELSE 0 END) AS total_pending FROM {$queue_table} WHERE campaign_id = %d",
                $campaign_id
            ),
            ARRAY_A
        );

        if ( ! $stats ) {
            return;
        }

        $status = ( (int) $stats['total_pending'] ) > 0 ? 'sending' : 'done';
        $this->wpdb->update( $this->table, [
            'total_targets' => (int) $stats['total_targets'],
            'total_sent'    => (int) $stats['total_sent'],
            'total_failed'  => (int) $stats['total_failed'],
            'status'        => $status,
'finished_at' => 'done' === $status ? \current_time( 'mysql', true ) : null,
        ], [ 'id' => $campaign_id ] );
    }
}
