<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\PaymentGateway;

use OpenBooking\Domain\Payment\Repository\GatewayResolverInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adaptador de dominio hacia el registro de gateways.
 */
class Gateway_Resolver_Adapter implements GatewayResolverInterface {

    public function get(string $key): ?object {
        return Gateway_Registry::get($key);
    }

    public function get_enabled_for_country(string $country_code): array {
        return Gateway_Registry::get_enabled_for_country($country_code);
    }

    public function get_available_for_country(string $country_code): array {
        return Gateway_Registry::get_available_for_country($country_code);
    }

    public function get_gateways_for_country(string $country_code): array {
        return Country_Payment_Resolver::get_gateways_for_country($country_code);
    }
}
