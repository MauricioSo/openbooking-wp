<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centraliza la lista de monedas soportadas por el plugin.
 */
class Currency_Helper {

    private const SUPPORTED = [ 'USD', 'CLP', 'COP', 'MXN', 'EUR', 'ARS', 'PEN', 'BRL' ];
    private const ZERO_DECIMAL = [ 'CLP', 'COP' ];

    public static function sanitize_supported_currency( $value ): ?string {
        $cur = strtoupper( sanitize_text_field( (string) $value ) );

        return in_array( $cur, self::SUPPORTED, true ) ? $cur : null;
    }

    public static function get_supported(): array {
        return self::SUPPORTED;
    }

    public static function is_zero_decimal( string $currency ): bool {
        return in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true );
    }

    public static function major_to_minor( float $amount, string $currency ): int {
        $multiplier = self::is_zero_decimal( $currency ) ? 1 : 100;

        return absint( round( $amount * $multiplier ) );
    }

    public static function minor_to_major( int $amount_minor, string $currency ): float {
        $divisor = self::is_zero_decimal( $currency ) ? 1 : 100;

        return $amount_minor / $divisor;
    }

    public static function format_minor( int $amount_minor, string $currency ): string {
        $decimals = self::is_zero_decimal( $currency ) ? 0 : 2;

        return number_format( self::minor_to_major( $amount_minor, $currency ), $decimals, '.', ',' );
    }
}
