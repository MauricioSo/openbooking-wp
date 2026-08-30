<?php


declare( strict_types=1 );
namespace OpenBooking\Domain\Payment\Service;

use OpenBooking\Domain\Payment\Entity\Payment_Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Formal state machine for Payment status transitions.
 *
 * Status flow:
 *
 *   pending    → authorized | paid | failed | expired
 *   authorized → paid | failed | expired
 *   paid       → partially_paid | refunded | disputed
 *   partially_paid → partially_paid | refunded | disputed
 *   refunded   → (terminal)
 *   failed     → (terminal)
 *   expired    → (terminal)
 *   disputed   → paid | refunded
 *
 * This is the single source of truth for payment transitions.
 * Payment_Service.ALLOWED_TRANSITIONS is kept for backwards compatibility
 * but delegates to this class.
 */
class Payment_State_Machine {

    private const TRANSITIONS = [
        Payment_Entity::STATUS_PENDING    => [
            Payment_Entity::STATUS_AUTHORIZED,
            Payment_Entity::STATUS_PAID,
            Payment_Entity::STATUS_FAILED,
            Payment_Entity::STATUS_EXPIRED,
        ],
        Payment_Entity::STATUS_AUTHORIZED => [
            Payment_Entity::STATUS_PAID,
            Payment_Entity::STATUS_FAILED,
            Payment_Entity::STATUS_EXPIRED,
        ],
        Payment_Entity::STATUS_PAID       => [
            Payment_Entity::STATUS_PARTIALLY_PAID,
            Payment_Entity::STATUS_REFUNDED,
            Payment_Entity::STATUS_DISPUTED,
        ],
        Payment_Entity::STATUS_PARTIALLY_PAID => [
            Payment_Entity::STATUS_PARTIALLY_PAID,
            Payment_Entity::STATUS_REFUNDED,
            Payment_Entity::STATUS_DISPUTED,
        ],
        Payment_Entity::STATUS_REFUNDED   => [],
        Payment_Entity::STATUS_FAILED     => [],
        Payment_Entity::STATUS_EXPIRED    => [],
        Payment_Entity::STATUS_DISPUTED   => [
            Payment_Entity::STATUS_PAID,
            Payment_Entity::STATUS_REFUNDED,
        ],
    ];

    /**
     * Assert a transition is valid, throw if not.
     *
     * @throws \RuntimeException
     */
    public static function assert_transition( string $from, string $to ): void {
        $allowed = self::TRANSITIONS[ $from ] ?? null;

        if ( null === $allowed ) {
            throw new \RuntimeException(
                "Unknown payment status '{$from}'. Cannot transition to '{$to}'."
            );
        }

        if ( ! in_array( $to, $allowed, true ) ) {
            throw new \RuntimeException(
                "Payment status transition '{$from}' → '{$to}' is not allowed."
            );
        }
    }

    /**
     * Non-throwing check.
     */
    public static function can_transition( string $from, string $to ): bool {
        return in_array( $to, self::TRANSITIONS[ $from ] ?? [], true );
    }

    /**
     * Return allowed next statuses from a given status.
     */
    public static function allowed_next( string $from ): array {
        return self::TRANSITIONS[ $from ] ?? [];
    }
}
