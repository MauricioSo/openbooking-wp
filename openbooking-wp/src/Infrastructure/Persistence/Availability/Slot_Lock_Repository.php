<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Persistence\Availability;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de disponibilidad.
 */

class Slot_Lock_Repository implements \OpenBooking\Domain\Availability\Repository\SlotLockRepositoryInterface {

    private \wpdb $wpdb;
    private string $table = '';

    public function __construct(
        private \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository $audit_repo, // persiste log de auditoria
    ) {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_slot_locks';
    }

    public function claim_slot(
        int $service_id,
        ?int $resource_id,
        string $slot_start,
        string $slot_end,
        int $capacity,
        ?string $expires_at = null
    ): array {
        $resource_key = $resource_id ?: 0;

        for ( $i = 1; $i <= $capacity; $i++ ) {
            $inserted = $this->wpdb->insert( $this->table, [
                'service_id'     => $service_id,
                'resource_id'    => $resource_id ?: null,
                'resource_key'   => $resource_key,
                'slot_start'     => $slot_start,
                'slot_end'       => $slot_end,
                'capacity_index' => $i,
                'status'         => 'held',
                'expires_at'     => $expires_at,
            ] );

            if ( $inserted ) {
                $lock_id = (int) $this->wpdb->insert_id;

                $this->audit_repo->insert( [
                    'entity_type' => 'slot_lock',
                    'entity_id'   => $lock_id,
                    'action'      => 'slot_lock_claimed',
                    'message'     => 'Slot lock claimed.',
                    'context'     => [
                        'service_id'     => $service_id,
                        'resource_key'   => $resource_key,
                        'slot_start'     => $slot_start,
                        'slot_end'       => $slot_end,
                        'capacity_index' => $i,
                        'expires_at'     => $expires_at,
                    ],
                ] );

                return [
                    'success'        => true,
                    'lock_id'        => $lock_id,
                    'capacity_index' => $i,
                ];
            }

            $last_error = $this->wpdb->last_error ?? '';
            if ( $last_error && ( strpos( $last_error, '1062' ) !== false || stripos( $last_error, 'duplicate' ) !== false ) ) {
                continue;
            }

            if ( $last_error && ( strpos( $last_error, '1213' ) !== false || stripos( $last_error, 'deadlock' ) !== false || stripos( $last_error, 'lock wait timeout' ) !== false ) ) {
                $this->audit_repo->insert( [
                    'entity_type' => 'slot_lock',
                    'entity_id'   => 0,
                    'action'      => 'slot_lock_deadlock',
                    'severity'    => 'info',
                    'message'     => 'Deadlock during claim_slot, treating as conflict.',
                    'context'     => [
                        'service_id'     => $service_id,
                        'resource_key'   => $resource_key,
                        'slot_start'     => $slot_start,
                        'capacity_index' => $i,
                    ],
                ] );

                return [ 'error' => 'slot_unavailable', 'code' => 409 ];
            }

            $this->audit_repo->insert( [
                'entity_type' => 'slot_lock',
                'entity_id'   => 0,
                'action'      => 'slot_lock_insert_failed',
                'severity'    => 'error',
                'message'     => 'Slot lock insert failed: ' . $last_error,
                'context'     => [
                    'service_id'     => $service_id,
                    'resource_key'   => $resource_key,
                    'slot_start'     => $slot_start,
                    'capacity_index' => $i,
                ],
            ] );

            return [ 'error' => 'lock_insert_failed', 'code' => 500 ];
        }

        $this->audit_repo->insert( [
            'entity_type' => 'slot_lock',
            'entity_id'   => 0,
            'action'      => 'slot_lock_conflict',
            'severity'    => 'info',
            'message'     => 'No slot capacity available.',
            'context'     => [
                'service_id'   => $service_id,
                'resource_key' => $resource_key,
                'slot_start'   => $slot_start,
                'slot_end'     => $slot_end,
                'capacity'     => $capacity,
            ],
        ] );

        return [ 'error' => 'slot_unavailable', 'code' => 409 ];
    }

    public function attach_booking( int $lock_id, int $booking_id ): bool {
        $result = $this->wpdb->update(
            $this->table,
            [ 'booking_id' => $booking_id ],
            [ 'id' => $lock_id, 'booking_id' => null ]
        );

        if ( $result ) {
            $this->audit_repo->insert( [
                'entity_type' => 'slot_lock',
                'entity_id'   => $lock_id,
                'action'      => 'slot_lock_attached',
                'message'     => 'Slot lock attached to booking.',
                'context'     => [ 'booking_id' => $booking_id ],
            ] );
        }

        return (bool) $result;
    }

    public function confirm_for_booking( int $booking_id ): bool {
        $result = $this->wpdb->update(
            $this->table,
            [ 'status' => 'confirmed' ],
            [
                'booking_id' => $booking_id,
                'status'     => 'held',
            ]
        );

        if ( ! $result ) {
            $held_or_confirmed = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT status FROM {$this->table} WHERE booking_id = %d LIMIT 1",
                    $booking_id
                )
            );

            if ( ! $held_or_confirmed ) {
                $this->audit_repo->insert( [
                    'entity_type' => 'slot_lock',
                    'entity_id'   => 0,
                    'action'      => 'slot_lock_missing_for_booking',
                    'severity'    => 'warning',
                    'message'     => 'No slot lock found when confirming booking payment.',
                    'context'     => [ 'booking_id' => $booking_id ],
                ] );
            }
        }

        return (bool) $result;
    }

    public function extend_expires_for_booking( int $booking_id, string $expires_at ): int {
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table}
                 SET expires_at = %s
                 WHERE booking_id = %d AND status = 'held'",
                $expires_at,
                $booking_id
            )
        );

        $affected = (int) $this->wpdb->rows_affected;

        if ( $affected > 0 ) {
            $this->audit_repo->insert( [
                'entity_type' => 'slot_lock',
                'entity_id'   => 0,
                'action'      => 'slot_lock_expiry_extended',
                'message'     => 'Slot lock expiry extended for active checkout.',
                'context'     => [
                    'booking_id' => $booking_id,
                    'expires_at' => $expires_at,
                    'count'      => $affected,
                ],
            ] );
        }

        return $affected;
    }

    public function release_for_booking( int $booking_id, string $reason = '' ): bool {
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table} SET status = 'released' WHERE booking_id = %d AND status IN ('held','confirmed')",
                $booking_id
            )
        );

        $affected = (int) $this->wpdb->rows_affected;

        if ( $affected > 0 ) {
            $this->audit_repo->insert( [
                'entity_type' => 'slot_lock',
                'entity_id'   => 0,
                'action'      => 'slot_lock_released',
                'message'     => 'Slot lock(s) released for booking.',
                'context'     => [
                    'booking_id' => $booking_id,
                    'reason'     => $reason,
                    'count'      => $affected,
                ],
            ] );
        }

        return $affected > 0;
    }

    public function expire_for_booking( int $booking_id ): bool {
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table} SET status = 'expired' WHERE booking_id = %d AND status IN ('held','confirmed')",
                $booking_id
            )
        );

        $affected = (int) $this->wpdb->rows_affected;

        if ( $affected > 0 ) {
            $this->audit_repo->insert( [
                'entity_type' => 'slot_lock',
                'entity_id'   => 0,
                'action'      => 'slot_lock_expired',
                'message'     => 'Slot lock(s) expired for booking.',
                'context'     => [
                    'booking_id' => $booking_id,
                    'count'      => $affected,
                ],
            ] );
        }

        return $affected > 0;
    }

    public function move_booking_lock(
        int $booking_id,
        int $service_id,
        ?int $resource_id,
        string $new_start,
        string $new_end,
        int $capacity,
        ?string $expires_at = null
    ): array {
        $old_lock = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE booking_id = %d AND status IN ('held','confirmed') LIMIT 1",
                $booking_id
            ),
            ARRAY_A
        );

        $old_status = $old_lock ? $old_lock['status'] : 'held';

        $claim = $this->claim_slot( $service_id, $resource_id, $new_start, $new_end, $capacity, $expires_at );
        if ( ! empty( $claim['error'] ) ) {
            return $claim;
        }

        if ( $old_lock ) {
            $this->wpdb->update(
                $this->table,
                [ 'status' => 'released' ],
                [ 'id' => (int) $old_lock['id'] ]
            );
        }

        $this->wpdb->update(
            $this->table,
            [ 'booking_id' => $booking_id ],
            [ 'id' => $claim['lock_id'] ]
        );

        if ( $old_status === 'confirmed' ) {
            $this->wpdb->update(
                $this->table,
                [ 'status' => 'confirmed' ],
                [ 'id' => $claim['lock_id'] ]
            );
            $claim['status'] = 'confirmed';
        }

        return $claim;
    }

    public function expire_stale_holds( int $limit = 200 ): int {
        $bookings = $this->wpdb->prefix . 'ob_bookings';

        // 1. Locks whose TTL expired.
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table} SET status = 'expired'
                 WHERE status = 'held'
                 AND expires_at IS NOT NULL
                 AND expires_at < UTC_TIMESTAMP()
                 LIMIT %d",
                $limit
            )
        );
        $affected = (int) $this->wpdb->rows_affected;

        // 2. Orphaned locks: held with no booking_id and older than 15 min
        //    (booking creation failed before attach_booking was called).
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table} SET status = 'expired'
                 WHERE status = 'held'
                 AND booking_id IS NULL
                 AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)
                 LIMIT %d",
                15,
                $limit
            )
        );
        $affected += (int) $this->wpdb->rows_affected;

        // 3. Locks whose booking reached a terminal failed status without releasing the lock.
        // Note: MySQL does not allow LIMIT in multi-table UPDATEs, so we use a subquery.
        $terminal = [ 'cancelled', 'expired', 'no_show', 'failed' ];
        $placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table} l
                 SET l.status = 'expired'
                 WHERE l.status = 'held'
                 AND l.id IN (
                     SELECT id FROM (
                         SELECT l2.id FROM {$this->table} l2
                         INNER JOIN {$bookings} b ON l2.booking_id = b.id
                         WHERE l2.status = 'held'
                         AND b.status IN ({$placeholders})
                         LIMIT %d
                     ) AS _sub
                 )",
                ...[...$terminal, $limit]
            )
        );
        $affected += (int) $this->wpdb->rows_affected;

        // 4. Locks still 'held' but whose booking was confirmed — promote to 'confirmed'
        //    so availability queries don't double-count them as open capacity.
        $confirmed_statuses = [ 'confirmed', 'completed' ];
        $conf_placeholders  = implode( ',', array_fill( 0, count( $confirmed_statuses ), '%s' ) );
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table} l
                 SET l.status = 'confirmed'
                 WHERE l.status = 'held'
                 AND l.id IN (
                     SELECT id FROM (
                         SELECT l2.id FROM {$this->table} l2
                         INNER JOIN {$bookings} b ON l2.booking_id = b.id
                         WHERE l2.status = 'held'
                         AND b.status IN ({$conf_placeholders})
                         LIMIT %d
                     ) AS _sub
                 )",
                ...[...$confirmed_statuses, $limit]
            )
        );
        $confirmed_fixed = (int) $this->wpdb->rows_affected;
        $affected += $confirmed_fixed;

        if ( $affected > 0 ) {
            $this->audit_repo->insert( [
                'entity_type' => 'slot_lock',
                'entity_id'   => 0,
                'action'      => 'slot_lock_stale_expired',
                'actor_type'  => 'cron',
                'message'     => "Reconciled {$affected} stale slot lock holds ({$confirmed_fixed} promoted to confirmed).",
                'context'     => [ 'total' => $affected, 'promoted_to_confirmed' => $confirmed_fixed ],
            ] );
        }

        return $affected;
    }

	public function find_active_for_range( int $service_id, string $date_from, string $date_to, ?int $resource_id = null ): array {
		$sql = "SELECT resource_id, resource_key, slot_start, slot_end, capacity_index, booking_id, status, expires_at
				FROM {$this->table}
				WHERE service_id = %d
				AND (status = 'confirmed' OR (status = 'held' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())))
				AND slot_start < %s AND slot_end > %s";

        $params = [ $service_id, $date_to, $date_from ];

        if ( $resource_id ) {
            $sql .= ' AND (resource_key = 0 OR resource_key = %d)';
            $params[] = $resource_id;
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare( $sql, ...$params ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    public function count_active_locks_for_slot( int $service_id, string $slot_start, string $slot_end, ?int $resource_id = null, ?int $exclude_booking_id = null ): int {
        $resource_key = $resource_id ?: 0;

		$sql = "SELECT COUNT(*) FROM {$this->table}
				WHERE resource_key = %d
				AND slot_start = %s
				AND (status = 'confirmed' OR (status = 'held' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())))";

        $params = [ $resource_key, $slot_start ];

        if ( ! $resource_id ) {
			$sql = "SELECT COUNT(*) FROM {$this->table}
					WHERE service_id = %d
					AND resource_key = %d
					AND slot_start = %s
					AND (status = 'confirmed' OR (status = 'held' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())))";
            $params = [ $service_id, $resource_key, $slot_start ];
        }

        if ( $exclude_booking_id ) {
            $sql .= ' AND (booking_id IS NULL OR booking_id != %d)';
            $params[] = $exclude_booking_id;
        }

        return (int) $this->wpdb->get_var( $this->wpdb->prepare( $sql, ...$params ) );
    }

    public function get_locked_slots_for_date( int $service_id, string $date_from, string $date_to, ?int $resource_id = null ): array {
		$sql = "SELECT slot_start, slot_end, resource_key, COUNT(*) as lock_count
				FROM {$this->table}
				WHERE service_id = %d
				AND (status = 'confirmed' OR (status = 'held' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())))
				AND slot_start >= %s AND slot_start < %s";

        $params = [ $service_id, $date_from, $date_to ];

        if ( $resource_id ) {
            $sql .= ' AND (resource_key = 0 OR resource_key = %d)';
            $params[] = $resource_id;
        }

        $sql .= ' GROUP BY slot_start, slot_end, resource_key';

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare( $sql, ...$params ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    public function get_locked_slots_grouped_by_date( int $service_id, string $date_from, string $date_to, ?int $resource_id = null ): array {
		$sql = "SELECT slot_start, slot_end, resource_key, COUNT(*) as lock_count
				FROM {$this->table}
				WHERE service_id = %d
				AND (status = 'confirmed' OR (status = 'held' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())))
				AND slot_start >= %s AND slot_start < %s";

        $params = [ $service_id, $date_from, $date_to ];

        if ( $resource_id ) {
            $sql .= ' AND (resource_key = 0 OR resource_key = %d)';
            $params[] = $resource_id;
        }

        $sql .= ' GROUP BY slot_start, slot_end, resource_key';

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare( $sql, ...$params ),
            ARRAY_A
        );

        $result = [];
        foreach ( $rows ?: [] as $row ) {
            $day = substr( $row['slot_start'], 0, 10 );
            $result[ $day ][] = $row;
        }

        return $result;
    }

    public function detect_orphans( int $limit = 200 ): array {
		$sql = "SELECT l.id, l.booking_id, l.service_id, l.slot_start, l.status
				FROM {$this->table} l
				LEFT JOIN {$this->wpdb->prefix}ob_bookings b ON l.booking_id = b.id
				WHERE l.status IN ('held','confirmed')
				AND (l.status = 'confirmed' OR (l.status = 'held' AND (l.expires_at IS NULL OR l.expires_at > UTC_TIMESTAMP())))
				AND (l.booking_id IS NULL OR b.id IS NULL OR b.status NOT IN ('pending','confirmed'))
				LIMIT %d";

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare( $sql, $limit ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    public function detect_overbookings( int $limit = 200 ): array {
        $bookings_table = $this->wpdb->prefix . 'ob_bookings';

		$sql = "SELECT l.service_id, l.resource_key, l.slot_start, l.slot_end, COUNT(*) as lock_count
				FROM {$this->table} l
				WHERE l.status IN ('held','confirmed')
				AND (l.status = 'confirmed' OR (l.status = 'held' AND (l.expires_at IS NULL OR l.expires_at > UTC_TIMESTAMP())))
				AND l.slot_start > UTC_TIMESTAMP()
                GROUP BY l.service_id, l.resource_key, l.slot_start, l.slot_end
                HAVING COUNT(*) > 1
                LIMIT %d";

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare( $sql, $limit ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    public function count_missing_locks_for_active_bookings(): int {
        $bookings_table = $this->wpdb->prefix . 'ob_bookings';
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$bookings_table} b
             LEFT JOIN {$this->table} l ON l.booking_id = b.id AND l.status IN ('held','confirmed')
             WHERE b.status IN ('pending','confirmed')
             AND b.start_at > UTC_TIMESTAMP()
             AND l.id IS NULL"
        );
    }

    public function count_stale_held_locks(): int {
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE status = 'held'
             AND expires_at IS NOT NULL
             AND expires_at < UTC_TIMESTAMP()"
        );
    }

    public function count_confirmed_locks_with_terminal_bookings(): int {
        $bookings_table = $this->wpdb->prefix . 'ob_bookings';
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} l
             INNER JOIN {$bookings_table} b ON b.id = l.booking_id
             WHERE l.status = 'confirmed'
             AND b.status IN ('cancelled_by_customer', 'cancelled_by_admin', 'expired', 'completed', 'no_show')"
        );
    }

    public function table_exists(): bool {
        return $this->wpdb->get_var( "SHOW TABLES LIKE '{$this->table}'" ) === $this->table;
    }

    public function health_details(): array {
        if ( ! $this->table_exists() ) {
            return [ 'table_exists' => false ];
        }

        $bookings_table  = $this->wpdb->prefix . 'ob_bookings';
        $stale_holds     = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'held' AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()"
        );
		$active_locks    = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table} WHERE status = 'confirmed' OR (status = 'held' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()))"
		);
        $missing_locks   = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$bookings_table} b
             LEFT JOIN {$this->table} l ON l.booking_id = b.id AND l.status IN ('held','confirmed')
             WHERE b.status IN ('pending','confirmed') AND b.start_at > UTC_TIMESTAMP() AND l.id IS NULL"
        );

        return [ 'table_exists' => true, 'active_locks' => $active_locks, 'stale_holds' => $stale_holds, 'missing_locks' => $missing_locks ];
    }
}
