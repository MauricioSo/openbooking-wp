<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Aplica reglas de validacion del bounded context de reservas.
 */

class Booking_Availability_Guard {

    private $availability;

    public function __construct( $availability ) {
        $this->availability = $availability;
    }

    public function check( int $service_id, string $start_at, string $end_at, ?int $resource_id = null ): array {
        $available = $this->availability->is_slot_available( $service_id, $start_at, $end_at, $resource_id );

        if ( ! $available ) {
            return [
                'available' => false,
                'error'     => $this->translate( 'El horario seleccionado ya no está disponible.' ),
                'code'      => 409,
            ];
        }

        return [ 'available' => true ];
    }

    private function translate( string $message ): string {
        return function_exists( '__' ) ? __( $message, 'openbooking-wp' ) : $message;
    }
}
