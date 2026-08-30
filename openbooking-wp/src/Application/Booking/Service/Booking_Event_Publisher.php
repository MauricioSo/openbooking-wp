<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Domain\Booking\Entity\Booking_Entity;
use OpenBooking\Domain\Shared\Port\EventBusInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Publica eventos del dominio para que otros modulos reaccionen.
 */

class Booking_Event_Publisher {

    public function __construct(
        private EventBusInterface $event_bus,
    ) {}

    public function publish_created( Booking_Entity $booking ): void {
        $this->event_bus->dispatch(
            new \OpenBooking\Domain\Booking\Event\BookingCreated( $booking->id, $booking->to_array() )
        );
    }

    public function publish_confirmed_if_auto( Booking_Entity $booking ): void {
        if ( $booking->status === Booking_Entity::STATUS_CONFIRMED ) {
            $this->event_bus->dispatch(
                new \OpenBooking\Domain\Booking\Event\BookingConfirmed( $booking->id, $booking->to_array() )
            );
        }
    }
}
