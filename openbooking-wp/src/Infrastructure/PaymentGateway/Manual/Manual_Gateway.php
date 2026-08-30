<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\PaymentGateway\Manual;

use OpenBooking\Infrastructure\PaymentGateway\PaymentGatewayInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gateway de pago manual.
 */
class Manual_Gateway implements PaymentGatewayInterface {

    public function get_key(): string {
        return 'manual';
    }

    public function get_label(): string {
        return __( 'Pago manual / en local', 'openbooking-wp' );
    }

    public function is_available_for_country( string $country_code ): bool {
        return true;
    }

    public function is_enabled(): bool {
        return true;
    }

    public function create_checkout( int $booking_id, array $context ): array {
        return [
            'type'    => 'manual',
            'message' => __( 'La reserva será confirmada como pago manual.', 'openbooking-wp' ),
            'booking_id' => $booking_id,
        ];
    }

    public function handle_webhook( string $payload, array $headers = [] ): array {
        return [ 'handled' => false ];
    }

    public function refund( int $payment_id, ?int $amount_minor = null ): array {
        return [ 'success' => false, 'message' => __( 'Manual payments cannot be refunded through the system.', 'openbooking-wp' ) ];
    }
}
