<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Booking\Event;

use OpenBooking\Domain\Shared\Event\DomainEvent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Evento de reserva confirmada.
 */
final class BookingConfirmed implements DomainEvent {

    private string $occurredAt;
    private int $bookingId;
    private array $booking;

    public function __construct( int $booking_id, array $booking_array ) {
        $this->occurredAt = gmdate( 'c' );
        $this->bookingId  = $booking_id;
        $this->booking    = $booking_array;
    }

    public function event_name(): string {
        return 'openbooking_booking_confirmed';
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

    public function booking(): array {
        return $this->booking;
    }

    public function to_array(): array {
        return $this->booking;
    }
}
