<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Shared\Event;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato comun para eventos de dominio.
 */
interface DomainEvent {

    /**
     * WordPress action hook name (e.g. 'openbooking_booking_created').
     * This is what do_action() dispatches to.
     */
    public function event_name(): string;

    /**
     * ISO 8601 timestamp when the event was recorded.
     */
    public function occurred_at(): string;

    /**
     * The aggregate root ID this event belongs to (e.g. booking_id, payment_id).
     * Return 0 if the event spans multiple aggregates or has no single owner.
     */
    public function aggregate_id(): int;

    /**
     * Full payload as an associative array suitable for JSON encoding.
     * This replaces the $booking_array / $payment_array passed to do_action.
     */
    public function to_array(): array;
}
