<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\PaymentGateway;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato comun para gateways de pago.
 */
interface PaymentGatewayInterface {

    public function get_key(): string;

    public function get_label(): string;

    public function is_available_for_country( string $country_code ): bool;

    public function is_enabled(): bool;

    public function create_checkout( int $booking_id, array $context ): array;

    public function handle_webhook( string $payload, array $headers = [] ): array;

    public function refund( int $payment_id, ?int $amount_minor = null ): array;
}
