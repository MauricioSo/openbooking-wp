<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Integration\Outbox;

use OpenBooking\Domain\Shared\Event\DomainEvent;
use OpenBooking\Domain\Shared\Repository\OutboxEventRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y consulta eventos del outbox.
 */
class Outbox_Event_Repository implements OutboxEventRepositoryInterface {

    private \wpdb $wpdb;
    private string $table = '';

    public function __construct(
        ?\wpdb $wpdb_instance = null
    ) {
        global $wpdb;
        $this->wpdb  = $wpdb_instance ?: $wpdb;
        $this->table = $this->wpdb->prefix . 'ob_outbox_events';
    }

    public function record_domain_event( DomainEvent $event ): bool {
        $payload = [
            'event_class' => get_class( $event ),
            'payload'     => $event->to_array(),
        ];

        $payload_json = wp_json_encode( $payload );
        if ( false === $payload_json ) {
            $payload_json = '{}';
        }

        $dedupe_key = $this->build_dedupe_key( $event, $payload_json );
        $now        = current_time( 'mysql', true );

        $inserted = $this->wpdb->insert( $this->table, [
            'event_name'   => $event->event_name(),
            'event_class'  => get_class( $event ),
            'aggregate_id' => $event->aggregate_id(),
            'dedupe_key'   => $dedupe_key,
            'payload'      => $payload_json,
            'status'       => 'pending',
            'available_at' => $now,
            'occurred_at'  => $this->format_occurred_at( $event->occurred_at() ),
            'created_at'   => $now,
            'updated_at'   => $now,
        ] );

        return false !== $inserted;
    }

    public function table_name(): string {
        return $this->table;
    }

    public function table_exists(): bool {
        return $this->wpdb->get_var( "SHOW TABLES LIKE '{$this->table}'" ) === $this->table;
    }

    public function claim_due( int $limit, string $worker_id ): array {
        $limit = max( 1, min( 100, $limit ) );
        $now   = current_time( 'mysql', true );

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE status = %s AND available_at <= %s
                 ORDER BY available_at ASC, id ASC
                 LIMIT %d",
                'pending',
                $now,
                $limit
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        $claimed = [];
        foreach ( $rows as $row ) {
            $updated = $this->wpdb->update(
                $this->table,
                [
                    'status'     => 'processing',
                    'locked_by'  => $worker_id,
                    'locked_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'id'     => (int) $row['id'],
                    'status' => 'pending',
                ]
            );

            if ( $updated ) {
                $row['status']    = 'processing';
                $row['locked_by'] = $worker_id;
                $row['locked_at'] = $now;
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public function mark_processed( int $id ): bool {
        $now = current_time( 'mysql', true );

        return false !== $this->wpdb->update(
            $this->table,
            [
                'status'       => 'processed',
                'processed_at' => $now,
                'locked_by'    => null,
                'locked_at'    => null,
                'updated_at'   => $now,
            ],
            [ 'id' => $id ]
        );
    }

    public function mark_failed_attempt( array $row, string $error_message ): bool {
        $attempts     = (int) ( $row['attempts'] ?? 0 ) + 1;
        $max_attempts = max( 1, (int) ( $row['max_attempts'] ?? 5 ) );
        $status       = $attempts >= $max_attempts ? 'dead' : 'pending';
        $now          = current_time( 'mysql', true );

        return false !== $this->wpdb->update(
            $this->table,
            [
                'status'       => $status,
                'attempts'     => $attempts,
                'available_at' => 'dead' === $status ? $now : $this->next_available_at( $attempts ),
                'locked_by'    => null,
                'locked_at'    => null,
                'last_error'   => substr( $error_message, 0, 2000 ),
                'updated_at'   => $now,
            ],
            [ 'id' => (int) $row['id'] ]
        );
    }

    public function counts_by_status(): array {
        $rows = $this->wpdb->get_results(
            "SELECT status, COUNT(*) AS total FROM {$this->table} GROUP BY status",
            ARRAY_A
        );

        $counts = [
            'pending'    => 0,
            'processing' => 0,
            'processed'  => 0,
            'failed'     => 0,
            'dead'       => 0,
            'ignored'    => 0,
        ];

        foreach ( (array) $rows as $row ) {
            $status = (string) ( $row['status'] ?? '' );
            if ( '' === $status ) {
                continue;
            }
            $counts[ $status ] = (int) ( $row['total'] ?? 0 );
        }

        return $counts;
    }

    public function oldest_pending_created_at(): ?string {
        $oldest = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT created_at FROM {$this->table} WHERE status = %s ORDER BY created_at ASC LIMIT 1",
                'pending'
            )
        );

        return $oldest ? (string) $oldest : null;
    }

    public function delete_processed_older_than( string $cutoff ): int {
        return (int) $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE status = %s AND processed_at IS NOT NULL AND processed_at < %s LIMIT 500",
                'processed',
                $cutoff
            )
        );
    }

    public function list_recent( string $status = '', int $limit = 50, int $offset = 0 ): array {
        $limit  = max( 1, min( 100, $limit ) );
        $offset = max( 0, $offset );

        if ( '' !== $status ) {
            return (array) $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT id, event_name, event_class, aggregate_id, status, attempts, max_attempts, available_at, locked_by, locked_at, last_error, occurred_at, processed_at, created_at, updated_at
                     FROM {$this->table}
                     WHERE status = %s
                     ORDER BY created_at DESC, id DESC
                     LIMIT %d OFFSET %d",
                    $status,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        }

        return (array) $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT id, event_name, event_class, aggregate_id, status, attempts, max_attempts, available_at, locked_by, locked_at, last_error, occurred_at, processed_at, created_at, updated_at
                 FROM {$this->table}
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );
    }

    public function retry_failed( int $id ): bool {
        $now = current_time( 'mysql', true );

        $data = [
            'status'       => 'pending',
            'available_at' => $now,
            'locked_by'    => null,
            'locked_at'    => null,
            'last_error'   => null,
            'updated_at'   => $now,
        ];

        $updated = $this->wpdb->update( $this->table, $data, [ 'id' => $id, 'status' => 'failed' ] );
        if ( false !== $updated && (int) $updated > 0 ) {
            return true;
        }

        return false !== $this->wpdb->update( $this->table, $data, [ 'id' => $id, 'status' => 'dead' ] );
    }

    public function release_stale_processing( int $stale_seconds = 900 ): int {
        $stale_seconds = max( 60, $stale_seconds );
        $now           = current_time( 'mysql', true );

        return (int) $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table}
                 SET status = 'pending', available_at = %s, locked_by = NULL, locked_at = NULL, last_error = 'Released from stale processing.', updated_at = %s
                 WHERE status = 'processing' AND locked_at IS NOT NULL AND locked_at < DATE_SUB(%s, INTERVAL %d SECOND)
                 LIMIT 100",
                $now,
                $now,
                $now,
                $stale_seconds
            )
        );
    }

    public function ignore( int $id ): bool {
        $now = current_time( 'mysql', true );

        return false !== $this->wpdb->update(
            $this->table,
            [
                'status'     => 'ignored',
                'locked_by'  => null,
                'locked_at'  => null,
                'updated_at' => $now,
            ],
            [ 'id' => $id ]
        );
    }

    private function build_dedupe_key( DomainEvent $event, string $payload_json ): string {
        return hash( 'sha256', implode( '|', [
            $event->event_name(),
            (string) $event->aggregate_id(),
            get_class( $event ),
            $payload_json,
        ] ) );
    }

    private function format_occurred_at( string $occurred_at ): string {
        $timestamp = strtotime( $occurred_at );
        if ( false === $timestamp ) {
            return current_time( 'mysql', true );
        }

        return gmdate( 'Y-m-d H:i:s', $timestamp );
    }

    private function next_available_at( int $attempts ): string {
        $delay_seconds = min( HOUR_IN_SECONDS, max( MINUTE_IN_SECONDS, $attempts * $attempts * MINUTE_IN_SECONDS ) );

        return gmdate( 'Y-m-d H:i:s', time() + $delay_seconds );
    }
}
