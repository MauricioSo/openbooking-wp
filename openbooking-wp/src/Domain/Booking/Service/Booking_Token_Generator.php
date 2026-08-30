<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Booking\Service;

use OpenBooking\Support\Setting_Keys;
use OpenBooking\Domain\Booking\Entity\Booking_Entity;
use OpenBooking\Domain\Shared\Port\ClockInterface;
use OpenBooking\Domain\Shared\Port\SettingsInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Genera tokens de acceso y expiracion para reservas.
 */
class Booking_Token_Generator {


    public const DEFAULT_TOKEN_TTL_HOURS = 72;
    public const DEFAULT_VIEW_TOKEN_TTL_HOURS = 720;

    public function __construct(
        private ClockInterface $clock,
        private SettingsInterface $settings,
    ) {}

    public function generate_cancel_token( Booking_Entity $booking ): void {
        $booking->cancel_token            = bin2hex( random_bytes( 32 ) );
        $ttl_hours                        = max( 1, (int) $this->settings->get( Setting_Keys::TOKEN_TTL_HOURS, self::DEFAULT_TOKEN_TTL_HOURS ) );
        $booking->cancel_token_expires_at = $this->compute_expiry( $ttl_hours );
    }

    public function generate_reschedule_token( Booking_Entity $booking ): void {
        $booking->reschedule_token            = bin2hex( random_bytes( 32 ) );
        $ttl_hours                            = max( 1, (int) $this->settings->get( Setting_Keys::TOKEN_TTL_HOURS, self::DEFAULT_TOKEN_TTL_HOURS ) );
        $booking->reschedule_token_expires_at = $this->compute_expiry( $ttl_hours );
        $booking->token_version               = max( 1, $booking->token_version ) + 1;
    }

    public function generate_view_token( Booking_Entity $booking ): void {
        $booking->view_token            = bin2hex( random_bytes( 32 ) );
        $ttl_hours                      = max( 1, (int) $this->settings->get( Setting_Keys::VIEW_TOKEN_TTL_HOURS, self::DEFAULT_VIEW_TOKEN_TTL_HOURS ) );
        $booking->view_token_expires_at = $this->compute_expiry( $ttl_hours );
    }

    public function generate_booking_token( Booking_Entity $booking ): void {
        $booking->booking_token            = bin2hex( random_bytes( 32 ) );
        $ttl_hours                         = max( 1, (int) $this->settings->get( Setting_Keys::TOKEN_TTL_HOURS, self::DEFAULT_TOKEN_TTL_HOURS ) );
        $booking->booking_token_expires_at = $this->compute_expiry( $ttl_hours );
    }

    public function generate_confirm_token( Booking_Entity $booking ): void {
        $booking->confirm_token = bin2hex( random_bytes( 32 ) );
    }

    public function is_cancel_token_valid( Booking_Entity $booking ): bool {
        if ( null === $booking->cancel_token_expires_at ) {
            return true;
        }
        return $this->expiry_timestamp( $booking->cancel_token_expires_at ) > $this->clock->timestamp();
    }

    public function is_reschedule_token_valid( Booking_Entity $booking ): bool {
        if ( null === $booking->reschedule_token_expires_at ) {
            return true;
        }
        return $this->expiry_timestamp( $booking->reschedule_token_expires_at ) > $this->clock->timestamp();
    }

    public function is_view_token_valid( Booking_Entity $booking ): bool {
        if ( null === $booking->view_token_expires_at ) {
            return true;
        }
        return $this->expiry_timestamp( $booking->view_token_expires_at ) > $this->clock->timestamp();
    }

    public function is_booking_token_valid( Booking_Entity $booking ): bool {
        if ( null === $booking->booking_token_expires_at ) {
            return true;
        }
        return $this->expiry_timestamp( $booking->booking_token_expires_at ) > $this->clock->timestamp();
    }

    private function compute_expiry( int $ttl_hours ): string {
        return $this->clock->now()
            ->modify( '+' . $ttl_hours . ' hours' )
            ->setTimezone( new \DateTimeZone( 'UTC' ) )
            ->format( 'Y-m-d H:i:s' );
    }

    private function expiry_timestamp( string $expires_at ): int {
        $parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $expires_at, new \DateTimeZone( 'UTC' ) );

        return $parsed ? $parsed->getTimestamp() : 0;
    }
}
