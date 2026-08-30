<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Booking\Service;

use OpenBooking\Domain\Booking\Entity\Booking_Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Valida transiciones permitidas de estado y pago.
 *
 * Centralises which transitions are allowed so no controller, service, or webhook
 * can silently corrupt booking state. Any attempt to perform an unlisted transition
 * raises an exception at the domain boundary.
 *
 * booking.status transitions:
 *
 *   pending  → confirmed | expired | cancelled_by_customer | cancelled_by_admin
 *   confirmed → completed | no_show | cancelled_by_admin | cancelled_by_customer
 *   cancelled_by_customer → (terminal)
 *   cancelled_by_admin    → (terminal)
 *   expired               → (terminal)
 *   completed             → (terminal)
 *   no_show               → (terminal)
 *
 * booking.payment_status transitions:
 *
 *   pending       → authorized | paid | failed | expired
 *   authorized    → paid | failed | expired
 *   paid          → refunded | partially_paid
 *   partially_paid → paid | refunded
 *   failed        → (terminal)
 *   expired       → (terminal)
 *   refunded      → (terminal)
 */
class Booking_State_Machine {

    private const STATUS_TRANSITIONS = [
        Booking_Entity::STATUS_PENDING => [
            Booking_Entity::STATUS_CONFIRMED,
            Booking_Entity::STATUS_EXPIRED,
            Booking_Entity::STATUS_CANCELLED_BY_CUSTOMER,
            Booking_Entity::STATUS_CANCELLED_BY_ADMIN,
        ],
        Booking_Entity::STATUS_CONFIRMED => [
            Booking_Entity::STATUS_COMPLETED,
            Booking_Entity::STATUS_NO_SHOW,
            Booking_Entity::STATUS_CANCELLED_BY_ADMIN,
            Booking_Entity::STATUS_CANCELLED_BY_CUSTOMER,
        ],
        Booking_Entity::STATUS_CANCELLED_BY_CUSTOMER => [],
        Booking_Entity::STATUS_CANCELLED_BY_ADMIN    => [],
        Booking_Entity::STATUS_EXPIRED               => [],
        Booking_Entity::STATUS_COMPLETED             => [],
        Booking_Entity::STATUS_NO_SHOW               => [],
    ];

    private const PAYMENT_STATUS_TRANSITIONS = [
        Booking_Entity::PAYMENT_PENDING        => [
            Booking_Entity::PAYMENT_AUTHORIZED,
            Booking_Entity::PAYMENT_PAID,
            Booking_Entity::PAYMENT_FAILED,
            Booking_Entity::PAYMENT_EXPIRED,
        ],
        Booking_Entity::PAYMENT_AUTHORIZED     => [
            Booking_Entity::PAYMENT_PAID,
            Booking_Entity::PAYMENT_FAILED,
            Booking_Entity::PAYMENT_EXPIRED,
        ],
        Booking_Entity::PAYMENT_PARTIALLY_PAID => [
            Booking_Entity::PAYMENT_PAID,
            Booking_Entity::PAYMENT_REFUNDED,
        ],
        Booking_Entity::PAYMENT_PAID           => [
            Booking_Entity::PAYMENT_REFUNDED,
            Booking_Entity::PAYMENT_PARTIALLY_PAID,
        ],
        Booking_Entity::PAYMENT_FAILED         => [],
        Booking_Entity::PAYMENT_EXPIRED        => [],
        Booking_Entity::PAYMENT_REFUNDED       => [],
    ];

    /**
     * Assert that a booking status transition is allowed.
     *
     * @throws \RuntimeException if the transition is not permitted.
     */
    public static function assert_status_transition( string $from, string $to ): void {
        $allowed = self::STATUS_TRANSITIONS[ $from ] ?? null;

        if ( null === $allowed ) {
            throw new \RuntimeException(
                "Unknown booking status '{$from}'. Cannot transition to '{$to}'."
            );
        }

        if ( ! in_array( $to, $allowed, true ) ) {
            throw new \RuntimeException(
                "Booking status transition '{$from}' → '{$to}' is not allowed."
            );
        }
    }

    /**
     * Assert that a booking payment_status transition is allowed.
     *
     * @throws \RuntimeException if the transition is not permitted.
     */
    public static function assert_payment_status_transition( string $from, string $to ): void {
        $allowed = self::PAYMENT_STATUS_TRANSITIONS[ $from ] ?? null;

        if ( null === $allowed ) {
            throw new \RuntimeException(
                "Unknown booking payment_status '{$from}'. Cannot transition to '{$to}'."
            );
        }

        if ( ! in_array( $to, $allowed, true ) ) {
            throw new \RuntimeException(
                "Booking payment_status transition '{$from}' → '{$to}' is not allowed."
            );
        }
    }

    /**
     * Check (non-throwing) whether a status transition is permitted.
     */
    public static function can_transition_status( string $from, string $to ): bool {
        return in_array( $to, self::STATUS_TRANSITIONS[ $from ] ?? [], true );
    }

    /**
     * Check (non-throwing) whether a payment_status transition is permitted.
     */
    public static function can_transition_payment_status( string $from, string $to ): bool {
        return in_array( $to, self::PAYMENT_STATUS_TRANSITIONS[ $from ] ?? [], true );
    }

    /**
     * Return all valid next statuses from a given status.
     */
    public static function allowed_next_statuses( string $from ): array {
        return self::STATUS_TRANSITIONS[ $from ] ?? [];
    }
}
