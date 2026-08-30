<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Convierte fechas locales del negocio a UTC y resuelve zonas horarias.
 */
class Timezone_Helper {

    public static function local_date_to_utc( string $date, string $edge = 'start', string $tz_name = 'UTC' ): string {
        $tz = self::resolve_business_timezone( $tz_name );
        $time = ( $edge === 'end' ) ? '23:59:59' : '00:00:00';

        try {
            $dt = new \DateTimeImmutable( $date . ' ' . $time, $tz );
        } catch ( \Exception $e ) {
            return $date . ' ' . $time;
        }

        return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
    }

    public static function get_business_now( string $tz_name ): \DateTimeImmutable {
        $tz = self::resolve_business_timezone( $tz_name );

        return new \DateTimeImmutable( 'now', $tz );
    }

    public static function resolve_business_timezone( string $tz_name ): \DateTimeZone {
        try {
            return new \DateTimeZone( $tz_name !== '' ? $tz_name : 'UTC' );
        } catch ( \Exception $e ) {
            return new \DateTimeZone( 'UTC' );
        }
    }
}
