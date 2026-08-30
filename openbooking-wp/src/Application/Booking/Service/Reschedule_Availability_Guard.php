<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Application\Availability\Service\Slot_Lock_Service;
use OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface;
use OpenBooking\Domain\Catalog\Entity\Service_Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Aplica reglas de validacion del bounded context de reservas.
 */

class Reschedule_Availability_Guard {


    public function __construct(
        private Slot_Lock_Service $slot_lock_service,
        private ResourceRepositoryInterface $resource_repo,
    ) {}

    public function check_and_move(
        Service_Entity $service,
        int $booking_id,
        int $service_id,
        string $new_start_at,
        string $new_end_at,
        ?int $requested_resource_id,
        int $exclude_booking_id,
        ?string $expires_at
    ): array {
        $resolved_resource_id = $this->resolve_resource_for_locked_slot(
            $service, $new_start_at, $new_end_at, $requested_resource_id, $exclude_booking_id
        );
        if ( $resolved_resource_id === false ) {
            return [ 'error' => true, 'message' => 'slot_unavailable', 'code' => 409 ];
        }

        $resources = $this->resource_repo->find_by_service( $service_id );
        $capacity  = $this->resolve_capacity( $service, $resources, $resolved_resource_id );

        $lock_result = $this->slot_lock_service->move_booking_lock(
            $booking_id,
            $service_id,
            $resolved_resource_id,
            $new_start_at,
            $new_end_at,
            $capacity,
            $expires_at
        );

        if ( ! empty( $lock_result['error'] ) ) {
            if ( ! empty( $lock_result['code'] ) && $lock_result['code'] === 409 ) {
                return [ 'error' => true, 'message' => 'slot_unavailable', 'code' => 409 ];
            }
            return [ 'error' => true, 'message' => 'temporary_conflict', 'code' => 503 ];
        }

        return [ 'success' => true, 'resolved_resource_id' => $resolved_resource_id ];
    }

    private function resolve_resource_for_locked_slot(
        Service_Entity $service,
        string $start_at,
        string $end_at,
        ?int $requested_resource_id,
        int $exclude_booking_id
    ) {
        $resources = $this->resource_repo->find_by_service( $service->id );

        if ( empty( $resources ) ) {
            $conflicts = $this->slot_lock_service->count_active_locks_for_slot( $service->id, $start_at, $end_at, null, $exclude_booking_id );
            return $conflicts < max( 1, $service->capacity ) ? null : false;
        }

        if ( $requested_resource_id ) {
            foreach ( $resources as $resource ) {
                if ( $resource->id !== $requested_resource_id ) {
                    continue;
                }
                $conflicts = $this->slot_lock_service->count_active_locks_for_slot( $service->id, $start_at, $end_at, $resource->id, $exclude_booking_id );
                return $conflicts < max( 1, $resource->capacity ) ? $resource->id : false;
            }
            return false;
        }

        foreach ( $resources as $resource ) {
            $conflicts = $this->slot_lock_service->count_active_locks_for_slot( $service->id, $start_at, $end_at, $resource->id, $exclude_booking_id );
            if ( $conflicts < max( 1, $resource->capacity ) ) {
                return $resource->id;
            }
        }

        return false;
    }

    private function resolve_capacity( Service_Entity $service, array $resources, ?int $resource_id ): int {
        if ( empty( $resources ) ) {
            return max( 1, (int) $service->capacity );
        }

        foreach ( $resources as $resource ) {
            if ( $resource->id === $resource_id ) {
                return max( 1, (int) $resource->capacity );
            }
        }

        return max( 1, (int) $service->capacity );
    }
}
