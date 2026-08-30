<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\PaymentGateway;

use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registra y resuelve instancias de gateways de pago.
 */
class Gateway_Registry {

    private static array $gateways = [];

    public static function register( PaymentGatewayInterface $gateway ): void {
        self::$gateways[ $gateway->get_key() ] = $gateway;
    }

    public static function get( string $key ): ?PaymentGatewayInterface {
        return self::$gateways[ $key ] ?? null;
    }

    public static function get_all(): array {
        return self::$gateways;
    }

    public static function get_available_for_country( string $country_code ): array {
        return array_filter( self::$gateways, function ( PaymentGatewayInterface $gateway ) use ( $country_code ) {
            return $gateway->is_available_for_country( $country_code );
        });
    }

    public static function get_enabled_for_country( string $country_code ): array {
        $enabled = get_option( Setting_Keys::ENABLED_GATEWAYS, [] );
        return array_filter( self::$gateways, function ( PaymentGatewayInterface $gateway ) use ( $country_code, $enabled ) {
            return $gateway->is_available_for_country( $country_code )
                && $gateway->is_enabled()
                && ( $gateway->get_key() === 'manual' || in_array( $gateway->get_key(), $enabled, true ) );
        });
    }
}
