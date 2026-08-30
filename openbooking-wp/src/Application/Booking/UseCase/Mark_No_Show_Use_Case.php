<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\UseCase;

use OpenBooking\Application\Booking\Service\Booking_State_Log_Recorder;
use OpenBooking\Application\Booking\Service\Booking_Request_Context;
use OpenBooking\Domain\Booking\Entity\Booking_Entity;
use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Booking\Event\BookingNoShow;
use OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface;
use OpenBooking\Domain\Shared\Port\EventBusInterface;
use OpenBooking\Domain\Shared\Port\ActorContextInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Orquesta un caso de uso del bounded context de reservas.
 */

class Mark_No_Show_Use_Case {


    public function __construct(
        private BookingRepositoryInterface $booking_repo,
        private AuditRepositoryInterface $audit_log_repo,
        private Booking_State_Log_Recorder $state_log_recorder,
        private EventBusInterface $event_bus,
        private ActorContextInterface $actor_context,
    ) {
$this->actor_context       = $actor_context ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_ActorContext();
    }

    public function execute( int $booking_id, Booking_Request_Context $context ): array {
        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking ) {
            return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
        }

        $state_log_booking = clone $booking;
        $booking->status = Booking_Entity::STATUS_NO_SHOW;
        if ( ! $this->booking_repo->update( $booking ) ) {
            return [ 'error' => __( 'No se pudo actualizar la reserva.', 'openbooking-wp' ), 'code' => 500 ];
        }

        $this->state_log_recorder->record( $state_log_booking, Booking_Entity::STATUS_NO_SHOW );

        $this->audit_log_repo->insert( [
            'entity_type' => 'booking',
            'entity_id'   => $booking->id,
            'action'      => 'admin_mark_no_show',
            'actor_type'  => 'admin',
            'actor_id'    => $this->actor_context->get_current_user_id(),
            'message'     => 'Booking marked as no show by admin.',
            'context'     => [
                'message'        => 'Booking marked as no show by admin.',
                'allowed_fields' => [ 'status' ],
            ],
        ] );

        $this->event_bus->dispatch( new BookingNoShow( $booking->id, $booking->to_array() ) );

        return [ 'success' => true, 'booking_id' => $booking_id ];
    }
}
