<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Calcula contrastes de color y devuelve advertencias de accesibilidad.
 */
class Color_Contrast {

    public static function check_contrast_warnings( string $bg, string $text, string $accent, string $label = '' ): array {
        $warnings = [];
        $pairs = [
            [ 'fg' => $text, 'bg' => $bg, 'label' => 'Texto sobre fondo' ],
            [ 'fg' => $accent, 'bg' => $bg, 'label' => 'Color principal sobre fondo' ],
        ];

        foreach ( $pairs as $pair ) {
            if ( ! $pair['fg'] || ! $pair['bg'] ) {
                continue;
            }

            $ratio = self::contrast_ratio( $pair['fg'], $pair['bg'] );
            if ( $ratio < 4.5 ) {
                $warnings[] = sprintf(
                    '%s: ratio %.1f:1 (minimo recomendado 4.5:1 para texto, 3:1 para elementos grandes)',
                    $pair['label'],
                    $ratio
                );
            }
        }
        return $warnings;
    }

    public static function contrast_ratio( string $hex1, string $hex2 ): float {
        $l1 = self::relative_luminance( $hex1 );
        $l2 = self::relative_luminance( $hex2 );
        $lighter = max( $l1, $l2 );
        $darker  = min( $l1, $l2 );
        return ( $lighter + 0.05 ) / ( $darker + 0.05 );
    }

    public static function relative_luminance( string $hex ): float {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) !== 6 ) {
            return 0.5;
        }

        $r = self::linearize_channel( hexdec( substr( $hex, 0, 2 ) ) );
        $g = self::linearize_channel( hexdec( substr( $hex, 2, 2 ) ) );
        $b = self::linearize_channel( hexdec( substr( $hex, 4, 2 ) ) );
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function linearize_channel( int $srgb ): float {
        $s = $srgb / 255.0;
        return $s <= 0.04045 ? $s / 12.92 : pow( ( $s + 0.055 ) / 1.055, 2.4 );
    }
}
