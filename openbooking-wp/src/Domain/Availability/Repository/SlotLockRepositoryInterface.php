<?php


declare( strict_types=1 );
namespace OpenBooking\Domain\Availability\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de disponibilidad.
 */

interface SlotLockRepositoryInterface {

    public function claim_slot(
        int $service_id,
        ?int $resource_id,
        string $slot_start,
        string $slot_end,
        int $capacity,
        ?string $expires_at = null
    ): array;

    public function attach_booking( int $lock_id, int $booking_id ): bool;

    public function confirm_for_booking( int $booking_id ): bool;

    public function extend_expires_for_booking( int $booking_id, string $expires_at ): int;

    public function release_for_booking( int $booking_id, string $reason = '' ): bool;

    public function expire_for_booking( int $booking_id ): bool;

    public function move_booking_lock(
        int $booking_id,
        int $service_id,
        ?int $resource_id,
        string $new_start,
        string $new_end,
        int $capacity,
        ?string $expires_at = null
    ): array;

    public function expire_stale_holds( int $limit = 200 ): int;

    public function find_active_for_range( int $service_id, string $date_from, string $date_to, ?int $resource_id = null ): array;

    public function count_active_locks_for_slot( int $service_id, string $slot_start, string $slot_end, ?int $resource_id = null, ?int $exclude_booking_id = null ): int;

    public function get_locked_slots_for_date( int $service_id, string $date_from, string $date_to, ?int $resource_id = null ): array;

    public function get_locked_slots_grouped_by_date( int $service_id, string $date_from, string $date_to, ?int $resource_id = null ): array;

    public function detect_orphans( int $limit = 200 ): array;

    public function detect_overbookings( int $limit = 200 ): array;

    public function count_missing_locks_for_active_bookings(): int;

    public function count_stale_held_locks(): int;

    public function count_confirmed_locks_with_terminal_bookings(): int;

    public function table_exists(): bool;

    public function health_details(): array;
}
