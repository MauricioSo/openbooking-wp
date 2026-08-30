<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Booking\Service;

use OpenBooking\Support\Setting_Keys;
use OpenBooking\Domain\Booking\Entity\Booking_Entity;
use OpenBooking\Domain\Shared\Port\ClockInterface;
use OpenBooking\Application\Shared\Port\HookDispatcherInterface;
use OpenBooking\Domain\Shared\Port\SettingsInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Decide si una reserva puede reagendarse.
 */
class Booking_Reschedule_Policy {


    public function __construct(
        private SettingsInterface $settings,
        private ClockInterface $clock,
        private HookDispatcherInterface $hooks,
    ) {}

    public function can_reschedule( Booking_Entity $booking ): bool {
        if ( ! in_array( $booking->status, [ Booking_Entity::STATUS_PENDING, Booking_Entity::STATUS_CONFIRMED ], true ) ) {
            return false;
        }

        if ( empty( $booking->start_at ) ) {
            return false;
        }

        $min_hours = (int) $this->settings->get( Setting_Keys::RESCHEDULE_MIN_HOURS, 0 );
        if ( $min_hours > 0 && $booking->start_at ) {
            $now_ts   = $this->clock->now()->getTimestamp();
            $start_ts = $this->resolve_start_timestamp( $booking );
            if ( null === $start_ts ) {
                return false;
            }
            $threshold = $start_ts - ( $min_hours * 3600 );
            if ( $now_ts >= $threshold ) {
                return false;
            }
        }

        return (bool) $this->hooks->apply_filters( 'openbooking_booking_can_be_rescheduled', true, $booking );
    }

    private function resolve_start_timestamp( Booking_Entity $booking ): ?int {
        try {
            $timezone = $this->resolve_timezone( $booking );
            $start    = new \DateTimeImmutable( $booking->start_at, $timezone );
        } catch ( \Exception $e ) {
            return null;
        }
        return $start->getTimestamp();
    }

    private function resolve_timezone( Booking_Entity $booking ): \DateTimeZone {
        $tz_name = (string) $this->settings->get( Setting_Keys::BUSINESS_TIMEZONE, $booking->timezone ?: 'UTC' );
        try {
            return new \DateTimeZone( $tz_name );
        } catch ( \Exception $e ) {
            return new \DateTimeZone( 'UTC' );
        }
    }
}
