<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Persistence\Notification;

use OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de notificaciones.
 */

/**
 * Gestiona la cola persistente de notificaciones.
 */
class Notification_Queue_Repository implements NotificationQueueRepositoryInterface {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_notification_queue';
    }

    /** Priority constants for clarity at call sites. */
    public const PRIORITY_CRITICAL = 1;
    public const PRIORITY_NORMAL   = 5;
    public const PRIORITY_LOW      = 10;

    public function enqueue( array $data ): int {
        $dedupe_key = ! empty( $data['dedupe_key'] ) ? \sanitize_text_field( $data['dedupe_key'] ) : null;

        $row = [
            'booking_id'         => \absint( $data['booking_id'] ?? 0 ),
            'campaign_id'        => \absint( $data['campaign_id'] ?? 0 ) ?: null,
            'dedupe_key'         => $dedupe_key,
            'customer_id'        => \absint( $data['customer_id'] ?? 0 ) ?: null,
            'channel'            => \sanitize_key( $data['channel'] ?? 'email' ),
            'template_key'       => \sanitize_key( $data['template_key'] ?? '' ),
            'priority'           => \absint( $data['priority'] ?? self::PRIORITY_NORMAL ),
            'recipient'          => \sanitize_text_field( $data['recipient'] ?? '' ),
            'scheduled_at'       => \sanitize_text_field( $data['scheduled_at'] ?? $this->utc_now() ),
            'status'             => \sanitize_key( $data['status'] ?? 'pending' ),
            'attempts'           => \absint( $data['attempts'] ?? 0 ),
            'max_attempts'       => max( 1, \absint( $data['max_attempts'] ?? 3 ) ),
            'last_attempted_at'  => $data['last_attempted_at'] ?? null,
            'sent_at'            => $data['sent_at'] ?? null,
            'error_message'      => isset( $data['error_message'] ) ? \sanitize_textarea_field( (string) $data['error_message'] ) : null,
            'payload'            => ! empty( $data['payload'] ) ? \wp_json_encode( $data['payload'] ) : null,
        ];

        if ( $dedupe_key ) {
            $formats = [ '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ];
            $inserted = $this->wpdb->query(
                $this->wpdb->prepare(
                    "INSERT IGNORE INTO {$this->table} (booking_id, campaign_id, dedupe_key, customer_id, channel, template_key, priority, recipient, scheduled_at, status, attempts, max_attempts, last_attempted_at, sent_at, error_message, payload) VALUES (%d, %d, %s, %d, %s, %s, %d, %s, %s, %s, %d, %d, %s, %s, %s, %s)",
                    $row['booking_id'], $row['campaign_id'], $row['dedupe_key'], $row['customer_id'], $row['channel'], $row['template_key'], $row['priority'], $row['recipient'], $row['scheduled_at'], $row['status'], $row['attempts'], $row['max_attempts'], $row['last_attempted_at'], $row['sent_at'], $row['error_message'], $row['payload']
                )
            );
            return (int) $inserted;
        }

        $this->wpdb->insert( $this->table, $row );
        return (int) $this->wpdb->insert_id;
    }

    public function find( int $id ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public function list( array $args = [] ): array {
        $where = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['status'] ) ) {
            $where[] = 'status = %s';
            $params[] = \sanitize_key( $args['status'] );
        }
        if ( ! empty( $args['channel'] ) ) {
            $where[] = 'channel = %s';
            $params[] = \sanitize_key( $args['channel'] );
        }
        if ( ! empty( $args['booking_id'] ) ) {
            $where[] = 'booking_id = %d';
            $params[] = \absint( $args['booking_id'] );
        }
        if ( ! empty( $args['campaign_id'] ) ) {
            $where[] = 'campaign_id = %d';
            $params[] = \absint( $args['campaign_id'] );
        }

        $limit = ! empty( $args['limit'] ) ? \absint( $args['limit'] ) : 50;
        $offset = ! empty( $args['offset'] ) ? \absint( $args['offset'] ) : 0;
        $sql = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . " ORDER BY scheduled_at ASC, id ASC LIMIT {$limit} OFFSET {$offset}";
        if ( $params ) {
            $sql = $this->wpdb->prepare( $sql, ...$params );
        }
        return (array) $this->wpdb->get_results( $sql, ARRAY_A );
    }

    public function count( array $args = [] ): int {
        $where = [ '1=1' ];
        $params = [];
        if ( ! empty( $args['status'] ) ) {
            $where[] = 'status = %s';
            $params[] = \sanitize_key( $args['status'] );
        }
        if ( ! empty( $args['channel'] ) ) {
            $where[] = 'channel = %s';
            $params[] = \sanitize_key( $args['channel'] );
        }
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE " . implode( ' AND ', $where );
        if ( $params ) {
            $sql = $this->wpdb->prepare( $sql, ...$params );
        }
        return (int) $this->wpdb->get_var( $sql );
    }

    /**
     * Claim pending items ordered by priority (lower number = higher priority)
     * then by scheduled_at so critical transactional notifications go first.
     *
     * Priority bands (set by Notification_Manager):
     *   1 = critical (booking_confirmed, payment_received)
     *   5 = normal   (cancelled, rescheduled, expired)
     *  10 = low      (reminders, broadcasts)
     */
    public function claim_due( int $limit = 25 ): array {
        $now = $this->utc_now();
        $ids = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->table}
                 WHERE status = 'pending' AND scheduled_at <= %s
                 ORDER BY priority ASC, scheduled_at ASC, id ASC
                 LIMIT %d",
                $now,
                $limit
            )
        );

        if ( ! $ids ) {
            return [];
        }

        $claimed = [];
        foreach ( array_map( 'absint', $ids ) as $id ) {
            $updated = $this->wpdb->query(
                $this->wpdb->prepare(
                    "UPDATE {$this->table} SET status = 'processing', attempts = attempts + 1, last_attempted_at = %s WHERE id = %d AND status = 'pending'",
                    $now,
                    $id
                )
            );

            if ( 1 === (int) $updated ) {
                $row = $this->find( $id );
                if ( $row ) {
                    $claimed[] = $row;
                }
            }
        }

        return $claimed;
    }

    public function mark_sent( int $id ): void {
        $this->wpdb->update( $this->table, [
            'status'  => 'sent',
            'sent_at' => $this->utc_now(),
            'error_message' => null,
        ], [ 'id' => $id ] );
    }

    public function mark_skipped( int $id, string $message ): void {
        $this->wpdb->update( $this->table, [
            'status'        => 'skipped',
            'error_message' => \sanitize_textarea_field( $message ),
        ], [ 'id' => $id ] );
    }

    public function mark_failed( int $id, int $attempts, int $max_attempts, string $message ): void {
        if ( $attempts >= $max_attempts ) {
            $this->wpdb->update( $this->table, [
                'status'        => 'dead',
                'error_message' => \sanitize_textarea_field( $message ),
            ], [ 'id' => $id ] );
            return;
        }

        $delay = 300;
        if ( $attempts >= 2 ) {
            $delay = 1800;
        }

        $this->wpdb->update( $this->table, [
            'status'        => 'pending',
            'scheduled_at'  => gmdate( 'Y-m-d H:i:s', time() + $delay ),
            'error_message' => \sanitize_textarea_field( $message ),
        ], [ 'id' => $id ] );
    }

    public function cancel( int $id ): bool {
        return false !== $this->wpdb->update( $this->table, [ 'status' => 'cancelled' ], [ 'id' => $id, 'status' => 'pending' ] );
    }

    public function retry( int $id ): bool {
        $data = [
            'status'        => 'pending',
            'scheduled_at'  => $this->utc_now(),
            'error_message' => null,
        ];

        $updated = $this->wpdb->update( $this->table, $data, [ 'id' => $id, 'status' => 'failed' ] );
        if ( false !== $updated && (int) $updated > 0 ) {
            return true;
        }

        return false !== $this->wpdb->update( $this->table, $data, [ 'id' => $id, 'status' => 'dead' ] );
    }

    public function cancel_for_booking( int $booking_id ): int {
        return (int) $this->wpdb->update( $this->table, [ 'status' => 'cancelled' ], [ 'booking_id' => $booking_id, 'status' => 'pending' ] );
    }

    /**
     * Recover items stuck in 'processing' that exceed the stale threshold.
     *
     * If a PHP worker dies or exceeds max_execution_time while dispatching,
     * claimed items remain in 'processing' forever. This method resets them
     * back to 'pending' so they are picked up by the next claim_due cycle.
     *
     * @param int $stale_minutes How long 'processing' must be stale (default 10 min).
     * @return int Number of items recovered.
     */
    public function recover_stale_processing( int $stale_minutes = 10 ): int {
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( $stale_minutes * 60 ) );
        $now       = \current_time( 'mysql', true );
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table}
                 SET status = 'pending',
                     last_attempted_at = NULL,
                       error_message = CONCAT(COALESCE(error_message,''), ' [auto-recovered from stale processing on ', %s, ']')
                 WHERE status = 'processing'
                 AND last_attempted_at IS NOT NULL
                 AND last_attempted_at < %s",
                $now,
                $threshold
            )
        );
        return (int) $this->wpdb->rows_affected;
    }

    public function count_due_by_channel(): array {
        $rows = (array) $this->wpdb->get_results( "SELECT channel, COUNT(*) AS total FROM {$this->table} WHERE status = 'pending' GROUP BY channel", ARRAY_A );
        $result = [ 'email' => 0, 'whatsapp' => 0, 'sms' => 0 ];
        foreach ( $rows as $row ) {
            $result[ $row['channel'] ] = (int) $row['total'];
        }
        return $result;
    }

    public function count_stale_pending( int $hours = 24 ): int {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                 WHERE status IN ('pending', 'processing')
                 AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)",
                $hours
            )
        );
    }

    public function count_by_status( string $status ): int {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE status = %s",
                \sanitize_key( $status )
            )
        );
    }

    private function utc_now(): string {
        return \current_time( 'mysql', true );
    }
}
