<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\PaymentGateway;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resuelve gateways y moneda por pais.
 */
class Country_Payment_Resolver {

    private const COUNTRY_GATEWAYS = [
        'CL' => [ 'manual', 'webpay', 'mercadopago' ],
        'CO' => [ 'manual', 'mercadopago', 'stripe' ],
        'MX' => [ 'manual', 'mercadopago', 'stripe' ],
        'AR' => [ 'manual', 'mercadopago' ],
        'PE' => [ 'manual', 'mercadopago' ],
        'BR' => [ 'manual', 'mercadopago', 'stripe' ],
        'US' => [ 'manual', 'stripe' ],
        'ES' => [ 'manual', 'stripe' ],
    ];

    private const COUNTRY_CURRENCY = [
        'CL' => 'CLP',
        'CO' => 'COP',
        'MX' => 'MXN',
        'AR' => 'ARS',
        'PE' => 'PEN',
        'BR' => 'BRL',
        'US' => 'USD',
        'ES' => 'EUR',
    ];

    public static function get_gateways_for_country( string $country_code ): array {
        return self::COUNTRY_GATEWAYS[ strtoupper( $country_code ) ] ?? [ 'manual' ];
    }

    public static function get_currency_for_country( string $country_code ): string {
        return self::COUNTRY_CURRENCY[ strtoupper( $country_code ) ] ?? 'USD';
    }
}
