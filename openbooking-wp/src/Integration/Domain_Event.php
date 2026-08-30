<?php

declare( strict_types=1 );

namespace OpenBooking\Integration;

use OpenBooking\Support\Booking_Payloads;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Immutable value object that represents an integration event payload.
 */
final class Domain_Event {

    public const VERSION = '1';

    public const BOOKING_CREATED     = 'booking.created';
    public const BOOKING_CONFIRMED   = 'booking.confirmed';
    public const BOOKING_CANCELLED   = 'booking.cancelled';
    public const BOOKING_RESCHEDULED = 'booking.rescheduled';
    public const BOOKING_NO_SHOW     = 'booking.no_show';
    public const BOOKING_EXPIRED     = 'booking.expired';
    public const PAYMENT_CAPTURED    = 'payment.captured';
    public const PAYMENT_FAILED      = 'payment.failed';

    private string $timestamp;

    public function __construct(
        private string $event,
        private array $data,
    ) {
        $this->timestamp = gmdate( 'c' );
    }

    public static function make( string $event, array $data ): self {
        return new self( $event, $data );
    }

    public static function from_booking( string $event, array $booking ): self {
        return new self( $event, Booking_Payloads::public_from_array( $booking ) );
    }

    public function get_event(): string {
        return $this->event;
    }

    public function to_array(): array {
        return [
            'event'     => $this->event,
            'version'   => self::VERSION,
            'timestamp' => $this->timestamp,
            'data'      => $this->data,
        ];
    }

    public function to_json(): string {
        return (string) wp_json_encode( $this->to_array() );
    }
}
