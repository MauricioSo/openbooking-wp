<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Payment\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para resolver gateways de pago.
 */
interface GatewayResolverInterface {

    public function get(string $key): ?object;

    public function get_enabled_for_country(string $country_code): array;

    public function get_available_for_country(string $country_code): array;

    public function get_gateways_for_country(string $country_code): array;
}
