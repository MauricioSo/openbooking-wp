<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Adapter;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Shared\Port\ClockInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adaptador de tiempo basado en la zona horaria del negocio.
 */
class WP_Clock implements ClockInterface {

    private \DateTimeZone $timezone;

    public function __construct( ?\DateTimeZone $timezone = null ) {
        $this->timezone = $timezone ?? $this->resolve_business_timezone();
    }

    public function now(): \DateTimeImmutable {
        if ( function_exists( 'current_time' ) ) {
            try {
                return ( new \DateTimeImmutable( current_time( 'mysql', true ), new \DateTimeZone( 'UTC' ) ) )
                    ->setTimezone( $this->timezone );
            } catch ( \Exception $e ) {
            }
        }

        return new \DateTimeImmutable( 'now', $this->timezone );
    }

    public function timestamp(): int {
        if ( function_exists( 'current_time' ) ) {
            return (int) strtotime( (string) current_time( 'mysql', true ) );
        }

        return time();
    }

    private function resolve_business_timezone(): \DateTimeZone {
        $tz_name = function_exists( 'get_option' ) ? get_option( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' ) : 'UTC';
        try {
            return new \DateTimeZone( $tz_name );
        } catch ( \Exception $e ) {
            return new \DateTimeZone( 'UTC' );
        }
    }
}
