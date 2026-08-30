<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Integration\Service;

use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Shared\Port\ClockInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Integration_Integrity_Service {


    public function __construct(
        private BookingRepositoryInterface $booking_repo,
        private ?ClockInterface $clock = null,
    ) {
$this->clock = $clock ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Clock();
    }

    public function detect_suspicious_bookings( int $limit = 200 ): array {
        return $this->booking_repo->find_suspicious_bookings( $limit );
    }

    public function detect_bookings_without_request_log( int $limit = 200 ): array {
        return $this->booking_repo->find_bookings_without_request_log( $limit );
    }

    public function detect_duplicate_external_ids( int $limit = 200 ): array {
        return $this->booking_repo->find_duplicate_external_ids( $limit );
    }

    public function run_full_check(): array {
        return [
            'suspicious_bookings'           => $this->detect_suspicious_bookings(),
            'bookings_without_request_log'  => $this->detect_bookings_without_request_log(),
            'duplicate_external_ids'        => $this->detect_duplicate_external_ids(),
            'checked_at'                    => $this->clock->now()->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
        ];
    }
}
