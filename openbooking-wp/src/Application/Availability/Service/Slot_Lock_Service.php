<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Availability\Service;

use OpenBooking\Domain\Availability\Repository\SlotLockRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Slot_Lock_Service {


    public function __construct(
        private SlotLockRepositoryInterface $repository,
    ) {}

    public function claim_slot(
        int $service_id,
        ?int $resource_id,
        string $slot_start,
        string $slot_end,
        int $capacity,
        ?string $expires_at = null
    ): array {
        return $this->repository->claim_slot( $service_id, $resource_id, $slot_start, $slot_end, $capacity, $expires_at );
    }

    public function attach_booking( int $lock_id, int $booking_id ): bool {
        return $this->repository->attach_booking( $lock_id, $booking_id );
    }

    public function confirm_for_booking( int $booking_id ): bool {
        return $this->repository->confirm_for_booking( $booking_id );
    }

    public function extend_expires_for_booking( int $booking_id, string $expires_at ): int {
        return $this->repository->extend_expires_for_booking( $booking_id, $expires_at );
    }

    public function release_for_booking( int $booking_id, string $reason = '' ): bool {
        return $this->repository->release_for_booking( $booking_id, $reason );
    }

    public function expire_for_booking( int $booking_id ): bool {
        return $this->repository->expire_for_booking( $booking_id );
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
        return $this->repository->move_booking_lock( $booking_id, $service_id, $resource_id, $new_start, $new_end, $capacity, $expires_at );
    }

    public function expire_stale_holds( int $limit = 200 ): int {
        return $this->repository->expire_stale_holds( $limit );
    }

    public function find_active_for_range( int $service_id, string $date_from, string $date_to, ?int $resource_id = null ): array {
        return $this->repository->find_active_for_range( $service_id, $date_from, $date_to, $resource_id );
    }

    public function count_active_locks_for_slot( int $service_id, string $slot_start, string $slot_end, ?int $resource_id = null, ?int $exclude_booking_id = null ): int {
        return $this->repository->count_active_locks_for_slot( $service_id, $slot_start, $slot_end, $resource_id, $exclude_booking_id );
    }

    public function get_locked_slots_for_date( int $service_id, string $date_from, string $date_to, ?int $resource_id = null ): array {
        return $this->repository->get_locked_slots_for_date( $service_id, $date_from, $date_to, $resource_id );
    }

    public function get_locked_slots_grouped_by_date( int $service_id, string $date_from, string $date_to, ?int $resource_id = null ): array {
        return $this->repository->get_locked_slots_grouped_by_date( $service_id, $date_from, $date_to, $resource_id );
    }

    public function detect_orphans( int $limit = 200 ): array {
        return $this->repository->detect_orphans( $limit );
    }

    public function detect_overbookings( int $limit = 200 ): array {
        return $this->repository->detect_overbookings( $limit );
    }
}
