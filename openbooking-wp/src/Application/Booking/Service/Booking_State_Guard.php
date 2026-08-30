<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Domain\Booking\Service\Booking_Cancellation_Policy;
use OpenBooking\Domain\Booking\Entity\Booking_Entity;
use OpenBooking\Domain\Booking\Service\Booking_Reschedule_Policy;
use OpenBooking\Domain\Booking\Service\Booking_State_Machine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Aplica reglas de validacion del bounded context de reservas.
 */

class Booking_State_Guard {


    public function __construct(
        private Booking_Cancellation_Policy $cancellation_policy,
        private Booking_Reschedule_Policy $reschedule_policy,
    ) {}

    public function assert_can_transition( Booking_Entity $booking, string $target_status ): array {
        if ( ! Booking_State_Machine::can_transition_status( $booking->status, $target_status ) ) {
            return [
                'allowed' => false,
                'error'   => __( 'La reserva no puede ser confirmada desde su estado actual.', 'openbooking-wp' ),
                'code'    => 400,
            ];
        }
        return [ 'allowed' => true ];
    }

    public function assert_can_cancel( Booking_Entity $booking, string $cancelled_by ): array {
        if ( ! $this->cancellation_policy->can_cancel( $booking ) ) {
            return [
                'allowed' => false,
                'error'   => __( 'La reserva no puede ser cancelada.', 'openbooking-wp' ),
                'code'    => 400,
            ];
        }

        $target_status = $cancelled_by === 'admin'
            ? Booking_Entity::STATUS_CANCELLED_BY_ADMIN
            : Booking_Entity::STATUS_CANCELLED_BY_CUSTOMER;

        if ( ! Booking_State_Machine::can_transition_status( $booking->status, $target_status ) ) {
            return [
                'allowed' => false,
                'error'   => __( 'La reserva no puede ser cancelada desde su estado actual.', 'openbooking-wp' ),
                'code'    => 400,
            ];
        }

        return [ 'allowed' => true, 'target_status' => $target_status ];
    }

    public function assert_can_reschedule( Booking_Entity $booking ): array {
        if ( ! $this->reschedule_policy->can_reschedule( $booking ) ) {
            return [
                'allowed' => false,
                'error'   => __( 'La reserva no puede ser reprogramada.', 'openbooking-wp' ),
                'code'    => 400,
            ];
        }
        return [ 'allowed' => true ];
    }

    public function assert_not_past( \DateTimeImmutable $datetime ): array {
        $now = new \DateTimeImmutable( 'now', $datetime->getTimezone() );
        if ( $datetime->getTimestamp() <= $now->getTimestamp() ) {
            return [
                'allowed' => false,
                'error'   => __( 'No se pueden crear reservas en el pasado.', 'openbooking-wp' ),
                'code'    => 400,
            ];
        }
        return [ 'allowed' => true ];
    }
}
