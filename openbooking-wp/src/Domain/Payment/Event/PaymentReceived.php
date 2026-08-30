<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Payment\Event;

use OpenBooking\Core\Domain\Event\DomainEvent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Evento de pago recibido.
 */
final class PaymentReceived implements DomainEvent {

    private string $occurredAt;
    private int $paymentId;
    private array $payment;

    public function __construct( int $payment_id, array $payment_array ) {
        $this->occurredAt = gmdate( 'c' );
        $this->paymentId  = $payment_id;
        $this->payment    = $payment_array;
    }

    public function event_name(): string {
        return 'openbooking_payment_received';
    }

    public function occurred_at(): string {
        return $this->occurredAt;
    }

    public function aggregate_id(): int {
        return $this->paymentId;
    }

    public function payment_id(): int {
        return $this->paymentId;
    }

    public function booking_id(): int {
        return (int) ( $this->payment['booking_id'] ?? 0 );
    }

    public function to_array(): array {
        return $this->payment;
    }
}
