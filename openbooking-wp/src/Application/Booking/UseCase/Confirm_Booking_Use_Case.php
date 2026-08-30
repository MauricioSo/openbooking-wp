<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\UseCase;

use OpenBooking\Application\Booking\Service\Booking_State_Guard;
use OpenBooking\Application\Booking\Service\Booking_State_Log_Recorder;
use OpenBooking\Application\Booking\Service\Booking_Request_Context;
use OpenBooking\Domain\Booking\Entity\Booking_Entity;
use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Booking\Event\BookingConfirmed;
use OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface;
use OpenBooking\Domain\Shared\Port\TransactionManagerInterface;
use OpenBooking\Domain\Shared\Port\EventBusInterface;
use OpenBooking\Domain\Shared\Port\ActorContextInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Orquesta un caso de uso del bounded context de reservas.
 */

class Confirm_Booking_Use_Case {


    public function __construct(
        private BookingRepositoryInterface $booking_repo,
        private Booking_State_Guard $state_guard,
        private AuditRepositoryInterface $audit_log_repo,
        private Booking_State_Log_Recorder $state_log_recorder,
        private TransactionManagerInterface $transaction,
        private EventBusInterface $event_bus,
        private ActorContextInterface $actor_context,
    ) {
$this->actor_context       = $actor_context ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_ActorContext();
    }

    public function execute( int $booking_id, Booking_Request_Context $context ): array {
        $this->transaction->begin();

        try {
            $booking = $this->booking_repo->find_locked( $booking_id );
            if ( ! $booking ) {
                $this->transaction->rollback();
                return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
            }

            $guard = $this->state_guard->assert_can_transition( $booking, Booking_Entity::STATUS_CONFIRMED );
            if ( ! $guard['allowed'] ) {
                $this->transaction->rollback();
                return [ 'error' => $guard['error'], 'code' => $guard['code'] ];
            }

            $this->state_log_recorder->record( $booking, Booking_Entity::STATUS_CONFIRMED );
            $booking->status = Booking_Entity::STATUS_CONFIRMED;
            $this->booking_repo->update( $booking );

            $this->transaction->commit();
        } catch ( \Throwable $e ) {
            $this->transaction->rollback();
            throw $e;
        }

        $this->audit_log_repo->insert( [
            'entity_type' => 'booking',
            'entity_id'   => $booking->id,
            'action'      => 'admin_confirm',
            'actor_type'  => 'admin',
            'actor_id'    => $this->actor_context->get_current_user_id(),
            'message'     => 'Booking confirmed by admin.',
            'context'     => [
                'status' => $booking->status,
            ],
        ] );

        $this->event_bus->dispatch( new BookingConfirmed( $booking->id, $booking->to_array() ) );

        return [ 'success' => true, 'booking_id' => $booking_id ];
    }
}
