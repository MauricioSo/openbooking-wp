<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Application\Audit\Service\Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registra eventos o cambios en el bounded context de reservas.
 */

class Booking_Audit_Recorder {


    public function __construct(
        private Audit_Logger $audit_logger,
    ) {}

    public function record_creation( int $booking_id, string $source = 'public', ?int $actor_id = null ): void {
        $this->audit_logger->log( [
            'entity_type' => 'booking',
            'entity_id'   => $booking_id,
            'action'      => $source === 'admin' ? 'admin_create_booking' : 'create_booking',
            'actor_type'  => $source === 'admin' ? 'admin' : ( $source === 'integration_api' ? 'integration' : 'customer' ),
            'actor_id'    => $actor_id,
            'message'     => $source === 'admin' ? 'Booking created by admin.' : 'Booking created.',
        ] );
    }
}
