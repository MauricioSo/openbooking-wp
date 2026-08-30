<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Booking\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para consultar la cronologia de reservas.
 */
interface BookingTimelineRepositoryInterface {

    public function get_timeline_events(int $booking_id): array;
}
