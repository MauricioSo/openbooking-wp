<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Booking\Event;

use OpenBooking\Domain\Shared\Event\DomainEvent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Evento de reserva reprogramada.
 */
final class BookingRescheduled implements DomainEvent {

    private string $occurredAt;
    private int $bookingId;
    private array $payload;

    public function __construct( int $booking_id, string $old_start_at, string $new_start_at, array $booking_array ) {
        $this->occurredAt = gmdate( 'c' );
        $this->bookingId  = $booking_id;
        $this->payload    = [
            'old_start_at' => $old_start_at,
            'new_start_at' => $new_start_at,
            'booking'      => $booking_array,
        ];
    }

    public function event_name(): string {
        return 'openbooking_booking_rescheduled';
    }

    public function occurred_at(): string {
        return $this->occurredAt;
    }

    public function aggregate_id(): int {
        return $this->bookingId;
    }

    public function booking_id(): int {
        return $this->bookingId;
    }

    public function old_start_at(): string {
        return $this->payload['old_start_at'];
    }

    public function new_start_at(): string {
        return $this->payload['new_start_at'];
    }

    public function booking(): array {
        return $this->payload['booking'];
    }

    public function to_array(): array {
        return $this->payload;
    }
}
