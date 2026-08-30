<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Persistence\Booking;

use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de reservas.
 */

class Booking_Repository implements BookingRepositoryInterface {

    private \wpdb $wpdb;
    private string $table;
    private string $meta_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb       = $wpdb;
        $this->table      = $wpdb->prefix . 'ob_bookings';
        $this->meta_table = $wpdb->prefix . 'ob_booking_meta';
    }

    public function find( int $id ): ?\OpenBooking\Domain\Booking\Entity\Booking_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Booking\Entity\Booking_Entity::from_array( $row ) : null;
    }

    /**
     * Same as find() but with SELECT…FOR UPDATE.
     * Must be called inside an open transaction.
     * Prevents concurrent reschedule/cancel from operating on stale state.
     */
    public function find_locked( int $id ): ?\OpenBooking\Domain\Booking\Entity\Booking_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d FOR UPDATE", $id ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Booking\Entity\Booking_Entity::from_array( $row ) : null;
    }

    /**
     * Find booking by cancel token, enforcing TTL when present.
     * Returns null if the token is expired or unknown.
     */
    public function find_by_cancel_token( string $token ): ?\OpenBooking\Domain\Booking\Entity\Booking_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE cancel_token = %s
                   AND (cancel_token_expires_at IS NULL OR cancel_token_expires_at > UTC_TIMESTAMP())
                 LIMIT 1",
                $token
            ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Booking\Entity\Booking_Entity::from_array( $row ) : null;
    }

    /**
     * Find booking by reschedule token, enforcing TTL when present.
     * Returns null if the token is expired or unknown.
     */
    public function find_by_reschedule_token( string $token ): ?\OpenBooking\Domain\Booking\Entity\Booking_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE reschedule_token = %s
                   AND (reschedule_token_expires_at IS NULL OR reschedule_token_expires_at > UTC_TIMESTAMP())
                 LIMIT 1",
                $token
            ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Booking\Entity\Booking_Entity::from_array( $row ) : null;
    }

    /**
     * Find booking by view token (read-only, long-lived).
     * Returns null if the token is expired or unknown.
     */
    public function find_by_view_token( string $token ): ?\OpenBooking\Domain\Booking\Entity\Booking_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE view_token = %s
                   AND (view_token_expires_at IS NULL OR view_token_expires_at > UTC_TIMESTAMP())
                 LIMIT 1",
                $token
            ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Booking\Entity\Booking_Entity::from_array( $row ) : null;
    }

    public function find_by_booking_token( string $token ): ?\OpenBooking\Domain\Booking\Entity\Booking_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE booking_token = %s
                   AND (booking_token_expires_at IS NULL OR booking_token_expires_at > UTC_TIMESTAMP())
                 LIMIT 1",
                $token
            ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Booking\Entity\Booking_Entity::from_array( $row ) : null;
    }

    public function find_by_confirm_token( string $token ): ?\OpenBooking\Domain\Booking\Entity\Booking_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE confirm_token = %s
                   AND status = 'confirmed'
                 LIMIT 1",
                $token
            ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Booking\Entity\Booking_Entity::from_array( $row ) : null;
    }

    public function find_all( array $args = [] ): array {
        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['service_id'] ) ) {
            $where[]  = 'service_id = %d';
            $params[] = absint( $args['service_id'] );
        }
        if ( ! empty( $args['customer_id'] ) ) {
            $where[]  = 'customer_id = %d';
            $params[] = absint( $args['customer_id'] );
        }
        if ( ! empty( $args['resource_id'] ) ) {
            $where[]  = 'resource_id = %d';
            $params[] = absint( $args['resource_id'] );
        }
        if ( ! empty( $args['status'] ) ) {
            if ( is_array( $args['status'] ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $args['status'] ), '%s' ) );
                $where[] = "status IN ({$placeholders})";
                $params  = array_merge( $params, $args['status'] );
            } else {
                $where[]  = 'status = %s';
                $params[] = sanitize_text_field( $args['status'] );
            }
        }
        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = 'start_at >= %s';
            $params[] = sanitize_text_field( $args['date_from'] );
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = 'start_at <= %s';
            $params[] = sanitize_text_field( $args['date_to'] );
        }
        if ( ! empty( $args['created_after'] ) ) {
            $where[]  = 'created_at >= %s';
            $params[] = sanitize_text_field( $args['created_after'] );
        }

        $where_clause = implode( ' AND ', $where );
        $allowed_order = [ 'id', 'start_at', 'end_at', 'status', 'created_at', 'updated_at', 'service_id', 'customer_id' ];
        $order_by     = ! empty( $args['order_by'] ) && in_array( $args['order_by'], $allowed_order, true ) ? $args['order_by'] : 'start_at';
        $order        = ! empty( $args['order'] ) && in_array( strtoupper( $args['order'] ), [ 'ASC', 'DESC' ], true ) ? strtoupper( $args['order'] ) : 'ASC';
        $limit        = ! empty( $args['limit'] ) ? absint( $args['limit'] ) : 100;
        $offset       = ! empty( $args['offset'] ) ? absint( $args['offset'] ) : 0;

        $sql = "SELECT * FROM {$this->table} WHERE {$where_clause} ORDER BY {$order_by} {$order} LIMIT {$limit} OFFSET {$offset}";

        if ( ! empty( $params ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$params );
        }

        $rows = $this->wpdb->get_results( $sql, ARRAY_A );
        return array_map( function ( $row ) {
            return \OpenBooking\Domain\Booking\Entity\Booking_Entity::from_array( $row );
        }, $rows ?: [] );
    }

    public function count_for_date( string $date, array $statuses = [ 'pending', 'confirmed' ] ): int {
        if ( empty( $statuses ) ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $params = array_merge( $statuses, [ $date . ' 00:00:00', $date . ' 23:59:59' ] );

        $sql = "SELECT COUNT(*) FROM {$this->table}
                WHERE status IN ({$placeholders})
                AND start_at >= %s AND start_at <= %s";

        return (int) $this->wpdb->get_var( $this->wpdb->prepare( $sql, ...$params ) );
    }

    public function find_pending_expired(): array {
        $now = current_time( 'mysql', true );
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'pending'
             AND expires_at IS NOT NULL
             AND expires_at < %s",
            $now
        );
        $rows = $this->wpdb->get_results( $sql, ARRAY_A );
        return array_map( function ( $row ) {
            return \OpenBooking\Domain\Booking\Entity\Booking_Entity::from_array( $row );
        }, $rows ?: [] );
    }

    public function get_booked_slots( int $service_id, string $date_from, string $date_to, ?int $resource_id = null ): array {
        $sql = "SELECT id, resource_id, start_at, end_at FROM {$this->table}
                WHERE service_id = %d
                AND status IN ('pending','confirmed')
                AND start_at >= %s AND start_at < %s";

        $params = [ $service_id, $date_from, $date_to ];

        if ( $resource_id ) {
            $sql .= " AND (resource_id IS NULL OR resource_id = %d)";
            $params[] = $resource_id;
        }

        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );
        return $rows ?: [];
    }

    /**
     * Batch load all booked slots for a service across an entire date range (e.g., a month).
     * Returns an associative array keyed by date string ('Y-m-d') for O(1) per-day lookup.
     * Used by availability date calculations to avoid N per-day DB queries.
     *
     * @return array<string, list<array{resource_id:int|null,start_at:string,end_at:string}>>
     */
    public function get_booked_slots_grouped_by_date( int $service_id, string $date_from, string $date_to, ?int $resource_id = null ): array {
        $sql    = "SELECT resource_id, start_at, end_at FROM {$this->table}
                   WHERE service_id = %d
                   AND status IN ('pending','confirmed')
                   AND start_at >= %s AND start_at < %s";
        $params = [ $service_id, $date_from, $date_to ];

        if ( $resource_id ) {
            $sql .= " AND (resource_id IS NULL OR resource_id = %d)";
            $params[] = $resource_id;
        }

        $rows   = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );
        $result = [];
        foreach ( $rows ?: [] as $row ) {
            $day = substr( $row['start_at'], 0, 10 );
            $result[ $day ][] = $row;
        }

        return $result;
    }

    /**
     * Identical conflict check but with SELECT…FOR UPDATE so it participates in the
     * caller's open transaction.  Must be called inside START TRANSACTION / COMMIT.
     * The InnoDB gap-lock prevents any concurrent INSERT into the same slot range
     * until the transaction commits.
     */
    public function has_conflict_locked( int $service_id, string $start_at, string $end_at, ?int $resource_id = null, ?int $exclude_id = null ): bool {
        $sql = "SELECT id FROM {$this->table}
                WHERE service_id = %d
                AND status IN ('pending','confirmed')
                AND start_at < %s AND end_at > %s
                FOR UPDATE
                LIMIT 1";

        $params = [ $service_id, $end_at, $start_at ];

        if ( $resource_id ) {
            $sql = "SELECT id FROM {$this->table}
                    WHERE service_id = %d
                    AND status IN ('pending','confirmed')
                    AND start_at < %s AND end_at > %s
                    AND (resource_id IS NULL OR resource_id = %d)
                    FOR UPDATE
                    LIMIT 1";
            $params[] = $resource_id;
        }

        if ( $exclude_id ) {
            // Rebuild with exclude_id appended before FOR UPDATE.
            $base = $resource_id
                ? "SELECT id FROM {$this->table}
                   WHERE service_id = %d
                   AND status IN ('pending','confirmed')
                   AND start_at < %s AND end_at > %s
                   AND (resource_id IS NULL OR resource_id = %d)
                   AND id != %d
                   FOR UPDATE LIMIT 1"
                : "SELECT id FROM {$this->table}
                   WHERE service_id = %d
                   AND status IN ('pending','confirmed')
                   AND start_at < %s AND end_at > %s
                   AND id != %d
                   FOR UPDATE LIMIT 1";

            $params = $resource_id
                ? [ $service_id, $end_at, $start_at, $resource_id, $exclude_id ]
                : [ $service_id, $end_at, $start_at, $exclude_id ];

            return null !== $this->wpdb->get_var( $this->wpdb->prepare( $base, $params ) );
        }

        return null !== $this->wpdb->get_var( $this->wpdb->prepare( $sql, ...$params ) );
    }

    public function count_conflicts_locked( int $service_id, string $start_at, string $end_at, ?int $resource_id = null, ?int $exclude_id = null ): int {
        $sql = "SELECT COUNT(*) FROM {$this->table}
                WHERE service_id = %d
                AND status IN ('pending','confirmed')
                AND start_at < %s AND end_at > %s";

        $params = [ $service_id, $end_at, $start_at ];

        if ( $resource_id ) {
            $sql .= ' AND (resource_id IS NULL OR resource_id = %d)';
            $params[] = $resource_id;
        }

        if ( $exclude_id ) {
            $sql .= ' AND id != %d';
            $params[] = $exclude_id;
        }

        $sql .= ' FOR UPDATE';

        return (int) $this->wpdb->get_var( $this->wpdb->prepare( $sql, ...$params ) );
    }

    public function find_by_client_ref( string $client_ref ): ?\OpenBooking\Domain\Booking\Entity\Booking_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE client_ref = %s LIMIT 1", $client_ref ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Booking\Entity\Booking_Entity::from_array( $row ) : null;
    }

    public function find_active_duplicate_for_customer( int $customer_id, int $service_id, string $start_at ): ?\OpenBooking\Domain\Booking\Entity\Booking_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE customer_id = %d
                 AND service_id = %d
                 AND start_at = %s
                 AND status IN ('pending','confirmed')
                 LIMIT 1",
                $customer_id,
                $service_id,
                $start_at
            ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Booking\Entity\Booking_Entity::from_array( $row ) : null;
    }

    public function has_conflict( int $service_id, string $start_at, string $end_at, ?int $resource_id = null, ?int $exclude_id = null ): bool {
        $sql = "SELECT COUNT(*) FROM {$this->table}
                WHERE service_id = %d
                AND status IN ('pending','confirmed')
                AND start_at < %s AND end_at > %s";

        $params = [ $service_id, $end_at, $start_at ];

        if ( $resource_id ) {
            $sql .= " AND (resource_id IS NULL OR resource_id = %d)";
            $params[] = $resource_id;
        }

        if ( $exclude_id ) {
            $sql .= " AND id != %d";
            $params[] = $exclude_id;
        }

        return (int) $this->wpdb->get_var( $this->wpdb->prepare( $sql, ...$params ) ) > 0;
    }

    public function insert( \OpenBooking\Domain\Booking\Entity\Booking_Entity $entity ): int {
        $this->wpdb->insert( $this->table, [
            'service_id'                   => absint( $entity->service_id ),
            'resource_id'                  => $entity->resource_id ?: null,
            'customer_id'                  => absint( $entity->customer_id ),
            'status'                       => sanitize_text_field( $entity->status ),
            'payment_status'               => sanitize_text_field( $entity->payment_status ),
            'start_at'                     => sanitize_text_field( $entity->start_at ),
            'end_at'                       => sanitize_text_field( $entity->end_at ),
            'timezone'                     => sanitize_text_field( $entity->timezone ),
            'price_total_minor'            => absint( $entity->price_total_minor ),
            'price_due_now_minor'          => absint( $entity->price_due_now_minor ),
            'price_paid_minor'             => absint( $entity->price_paid_minor ),
            'currency'                     => sanitize_text_field( $entity->currency ),
            'source'                       => sanitize_text_field( $entity->source ),
            'notes_customer'               => sanitize_textarea_field( $entity->notes_customer ?? '' ) ?: null,
            'notes_internal'               => sanitize_textarea_field( $entity->notes_internal ?? '' ) ?: null,
            'cancel_token'                 => $entity->cancel_token,
            'cancel_token_expires_at'      => $entity->cancel_token_expires_at,
            'reschedule_token'             => $entity->reschedule_token,
            'reschedule_token_expires_at'  => $entity->reschedule_token_expires_at,
            'view_token'                   => $entity->view_token,
            'view_token_expires_at'        => $entity->view_token_expires_at,
            'booking_token'                => $entity->booking_token,
            'booking_token_expires_at'     => $entity->booking_token_expires_at,
            'token_version'                => max( 1, (int) $entity->token_version ),
            'confirm_token'                => $entity->confirm_token,
            'attendance_confirmed_at'      => $entity->attendance_confirmed_at,
            'confirmed_email_sent'        => (int) $entity->confirmed_email_sent,
            'confirmed_wa_sent'           => (int) $entity->confirmed_wa_sent,
            'client_ref'                  => $entity->client_ref ?: null,
            'expires_at'                  => $entity->expires_at,
            'integration_client_key'      => $entity->integration_client_key ?: null,
            'integration_request_id'      => $entity->integration_request_id ?: null,
            'external_id'                 => $entity->external_id ?: null,
            'created_via'                 => sanitize_text_field( $entity->created_via ),
        ] );

        return (int) $this->wpdb->insert_id;
    }

    public function update( \OpenBooking\Domain\Booking\Entity\Booking_Entity $entity ): bool {
        if ( ! $entity->id ) {
            return false;
        }
        $result = $this->wpdb->update( $this->table, [
            'service_id'                   => absint( $entity->service_id ),
            'resource_id'                  => $entity->resource_id ?: null,
            'customer_id'                  => absint( $entity->customer_id ),
            'status'                       => sanitize_text_field( $entity->status ),
            'payment_status'               => sanitize_text_field( $entity->payment_status ),
            'start_at'                     => sanitize_text_field( $entity->start_at ),
            'end_at'                       => sanitize_text_field( $entity->end_at ),
            'timezone'                     => sanitize_text_field( $entity->timezone ),
            'price_total_minor'            => absint( $entity->price_total_minor ),
            'price_due_now_minor'          => absint( $entity->price_due_now_minor ),
            'price_paid_minor'             => absint( $entity->price_paid_minor ),
            'currency'                     => sanitize_text_field( $entity->currency ),
            'source'                       => sanitize_text_field( $entity->source ),
            'notes_customer'               => sanitize_textarea_field( $entity->notes_customer ?? '' ) ?: null,
            'notes_internal'               => sanitize_textarea_field( $entity->notes_internal ?? '' ) ?: null,
            'cancel_token'                 => $entity->cancel_token,
            'cancel_token_expires_at'      => $entity->cancel_token_expires_at,
            'reschedule_token'             => $entity->reschedule_token,
            'reschedule_token_expires_at'  => $entity->reschedule_token_expires_at,
            'view_token'                   => $entity->view_token,
            'view_token_expires_at'        => $entity->view_token_expires_at,
            'booking_token'                => $entity->booking_token,
            'booking_token_expires_at'     => $entity->booking_token_expires_at,
            'token_version'                => max( 1, (int) $entity->token_version ),
            'confirm_token'                => $entity->confirm_token,
            'attendance_confirmed_at'      => $entity->attendance_confirmed_at,
            'confirmed_email_sent'        => (int) $entity->confirmed_email_sent,
            'confirmed_wa_sent'           => (int) $entity->confirmed_wa_sent,
            'expires_at'                  => $entity->expires_at,
            'integration_client_key'      => $entity->integration_client_key ?: null,
            'integration_request_id'      => $entity->integration_request_id ?: null,
            'external_id'                 => $entity->external_id ?: null,
            'created_via'                 => sanitize_text_field( $entity->created_via ),
        ], [ 'id' => $entity->id ] );

        return false !== $result;
    }

    public function update_status( int $id, string $status ): bool {
        return false !== $this->wpdb->update(
            $this->table,
            [ 'status' => sanitize_text_field( $status ) ],
            [ 'id' => $id ]
        );
    }

    public function update_payment_status( int $id, string $payment_status ): bool {
        return false !== $this->wpdb->update(
            $this->table,
            [ 'payment_status' => sanitize_text_field( $payment_status ) ],
            [ 'id' => $id ]
        );
    }

    public function add_meta( int $booking_id, string $key, string $value ): void {
        $this->wpdb->insert( $this->meta_table, [
            'booking_id' => $booking_id,
            'meta_key'   => sanitize_text_field( $key ),
            'meta_value' => sanitize_textarea_field( $value ),
        ] );
    }

    public function get_meta( int $booking_id, string $key ): ?string {
        $val = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT meta_value FROM {$this->meta_table} WHERE booking_id = %d AND meta_key = %s LIMIT 1",
                $booking_id, $key
            )
        );
        return $val;
    }

    public function set_meta( int $booking_id, string $key, string $value ): void {
        $this->wpdb->delete(
            $this->meta_table,
            [
                'booking_id' => $booking_id,
                'meta_key'   => sanitize_text_field( $key ),
            ]
        );

        $this->add_meta( $booking_id, $key, $value );
    }

    public function get_all_meta( int $booking_id ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$this->meta_table} WHERE booking_id = %d",
                $booking_id
            ),
            ARRAY_A
        );
        $meta = [];
        foreach ( $rows ?: [] as $row ) {
            $meta[ $row['meta_key'] ] = $row['meta_value'];
        }
        return $meta;
    }

    public function count_booking_payment_inconsistencies(): int {
        $payments_table = $this->wpdb->prefix . 'ob_payments';
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$this->table} b
             INNER JOIN {$payments_table} p ON p.booking_id = b.id
             WHERE (
                (p.status = 'paid' AND (b.payment_status != 'paid' OR b.status != 'confirmed'))
                OR (p.status = 'failed' AND b.payment_status != 'failed')
                OR (p.status = 'expired' AND b.payment_status != 'expired')
             )"
        );
    }

    public function count_orphan_bookings(): int {
        $services_table = $this->wpdb->prefix . 'ob_services';
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} b
             LEFT JOIN {$services_table} s ON s.id = b.service_id
             WHERE s.id IS NULL"
        );
    }

    public function count_expired_pending_bookings(): int {
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE status = 'pending'
             AND expires_at IS NOT NULL
             AND expires_at < UTC_TIMESTAMP()"
        );
    }

    public function count_invalid_status_bookings(): int {
        $valid = [ 'pending', 'confirmed', 'cancelled_by_customer', 'cancelled_by_admin', 'completed', 'no_show', 'expired' ];
        $placeholders = implode( ',', array_fill( 0, count( $valid ), '%s' ) );
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE status NOT IN ({$placeholders})",
                ...$valid
            )
        );
    }

    public function count_orphan_customers(): int {
        $customers_table = $this->wpdb->prefix . 'ob_customers';
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} b
             LEFT JOIN {$customers_table} c ON c.id = b.customer_id
             WHERE c.id IS NULL"
        );
    }

    public function count_inverted_date_bookings(): int {
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE end_at <= start_at"
        );
    }

    public function find_missing_tables( array $suffixes ): array {
        $missing = [];
        foreach ( $suffixes as $suffix ) {
            $table = $this->wpdb->prefix . $suffix;
            if ( $this->wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
                $missing[] = $table;
            }
        }
        return $missing;
    }

    public function count_active_for_service( int $service_id ): int {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE service_id = %d AND status IN ('pending','confirmed')",
                $service_id
            )
        );
    }

    public function count_pending(): int {
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'pending'"
        );
    }

    public function count_unpaid(): int {
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE payment_status = 'pending' AND status = 'pending'"
        );
    }

    public function find_today_dashboard_rows( string $date, int $limit = 20 ): array {
        $services_table  = $this->wpdb->prefix . 'ob_services';
        $customers_table = $this->wpdb->prefix . 'ob_customers';
        $limit           = max( 1, absint( $limit ) );

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT b.id, b.start_at, b.status, b.payment_status, b.customer_id,
                        s.name as service_name, c.first_name, c.last_name, c.email
                 FROM {$this->table} b
                 LEFT JOIN {$services_table} s ON s.id = b.service_id
                 LEFT JOIN {$customers_table} c ON c.id = b.customer_id
                 WHERE DATE(b.start_at) = %s
                 ORDER BY b.start_at ASC LIMIT {$limit}",
                $date
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    public function find_attention_required_rows( int $limit = 20 ): array {
        $services_table  = $this->wpdb->prefix . 'ob_services';
        $customers_table = $this->wpdb->prefix . 'ob_customers';
        $limit           = max( 1, absint( $limit ) );

        $rows = $this->wpdb->get_results(
            "SELECT b.id, b.start_at, b.status, b.payment_status, b.expires_at, b.service_id, b.customer_id,
                    s.name as service_name, c.first_name, c.last_name, c.email
             FROM {$this->table} b
             LEFT JOIN {$services_table} s ON s.id = b.service_id
             LEFT JOIN {$customers_table} c ON c.id = b.customer_id
             WHERE (b.status = 'pending' AND b.expires_at IS NOT NULL AND b.expires_at < UTC_TIMESTAMP())
                OR (b.status = 'confirmed' AND b.start_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), b.start_at) BETWEEN 0 AND 120)
             ORDER BY b.start_at ASC LIMIT {$limit}",
            ARRAY_A
        );

        return $rows ?: [];
    }

    public function find_suspicious_bookings( int $limit = 200 ): array {
        $limit = max( 1, absint( $limit ) );

        $rows = $this->wpdb->get_results(
            "SELECT id, source, integration_client_key, integration_request_id, external_id, created_via, created_at
             FROM {$this->table}
             WHERE source IN ('integration','dentbot')
               AND ( created_via != 'integration_api'
                  OR integration_request_id IS NULL
                  OR integration_client_key IS NULL )
             ORDER BY created_at DESC
             LIMIT {$limit}",
            ARRAY_A
        );

        $results = [];
        foreach ( $rows ?: [] as $row ) {
            $reasons = [];
            if ( $row['created_via'] !== 'integration_api' ) {
                $reasons[] = 'created_via_is_not_integration_api';
            }
            if ( empty( $row['integration_request_id'] ) ) {
                $reasons[] = 'missing_integration_request_id';
            }
            if ( empty( $row['integration_client_key'] ) ) {
                $reasons[] = 'missing_integration_client_key';
            }
            $results[] = [
                'booking_id'  => (int) $row['id'],
                'source'      => $row['source'],
                'created_via' => $row['created_via'],
                'reasons'     => $reasons,
                'created_at'  => $row['created_at'],
            ];
        }

        return $results;
    }

    public function find_bookings_without_request_log( int $limit = 200 ): array {
        $logs_table = $this->wpdb->prefix . 'ob_integration_request_logs';
        $limit      = max( 1, absint( $limit ) );

        $rows = $this->wpdb->get_results(
            "SELECT b.id, b.integration_client_key, b.integration_request_id, b.external_id, b.created_at
             FROM {$this->table} b
             LEFT JOIN {$logs_table} l ON l.result_entity_type = 'booking' AND l.result_entity_id = b.id
             WHERE b.integration_request_id IS NOT NULL
                AND l.id IS NULL
             ORDER BY b.created_at DESC
             LIMIT {$limit}",
            ARRAY_A
        );

        return array_map( function ( $row ) {
            return [
                'booking_id' => (int) $row['id'],
                'client_key' => $row['integration_client_key'],
                'request_id' => $row['integration_request_id'],
                'external_id' => $row['external_id'],
                'created_at' => $row['created_at'],
                'reason'     => 'booking_has_request_id_but_no_request_log',
            ];
        }, $rows ?: [] );
    }

    public function find_duplicate_external_ids( int $limit = 200 ): array {
        $limit = max( 1, absint( $limit ) );

        $rows = $this->wpdb->get_results(
            "SELECT integration_client_key, external_id, COUNT(*) as cnt,
                    GROUP_CONCAT(id ORDER BY id) as booking_ids
             FROM {$this->table}
             WHERE external_id IS NOT NULL AND external_id != ''
             GROUP BY integration_client_key, external_id
             HAVING cnt > 1
             LIMIT {$limit}",
            ARRAY_A
        );

        return array_map( function ( $row ) {
            return [
                'client_key'  => $row['integration_client_key'],
                'external_id' => $row['external_id'],
                'count'       => (int) $row['cnt'],
                'booking_ids' => array_map( 'intval', explode( ',', $row['booking_ids'] ) ),
                'reason'      => 'duplicate_external_id',
            ];
        }, $rows ?: [] );
    }
}
