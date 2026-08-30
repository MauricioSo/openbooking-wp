<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Booking\Repository;

use OpenBooking\Domain\Booking\Entity\Booking_Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato del log de estados de reservas.
 */
interface BookingStateLogRepositoryInterface {

    public function table_exists(): bool;

    public function insert_state_change(Booking_Entity $booking, string $new_status, ?string $reason, string $actor_type, ?int $actor_id, string $to_payment_status, ?string $request_id): void;

    public function find_state_events_for_booking(int $booking_id): array;
}
