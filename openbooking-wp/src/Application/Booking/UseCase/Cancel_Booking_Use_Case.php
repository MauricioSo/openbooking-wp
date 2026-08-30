<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\UseCase;

use OpenBooking\Application\Booking\Service\Booking_State_Guard;
use OpenBooking\Application\Booking\Service\Booking_Token_Guard;
use OpenBooking\Application\Booking\Service\Booking_Lock_Releaser;
use OpenBooking\Application\Booking\Service\Booking_State_Log_Recorder;
use OpenBooking\Application\Booking\Service\Booking_Request_Context;
use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Booking\Event\BookingCancelled;
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

class Cancel_Booking_Use_Case {


    public function __construct(
        private BookingRepositoryInterface $booking_repo,
        private Booking_State_Guard $state_guard,
        private Booking_Token_Guard $token_guard,
        private Booking_Lock_Releaser $lock_releaser,
        private AuditRepositoryInterface $audit_log_repo,
        private Booking_State_Log_Recorder $state_log_recorder,
        private TransactionManagerInterface $transaction,
        private EventBusInterface $event_bus,
        private \OpenBooking\Domain\Booking\Service\Booking_Token_Generator $token_generator,
        private \OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface $notification_queue,
        private \OpenBooking\Application\Availability\Service\Availability_Service $availability_service,
        private ActorContextInterface $actor_context,
    ) {
$this->actor_context       = $actor_context ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_ActorContext();
    }

    public function execute( int $booking_id, string $cancelled_by = 'customer', ?string $reason = null, ?string $cancel_token = null, ?Booking_Request_Context $context = null ): array {
        $this->transaction->begin();

        try {
            $booking = $this->booking_repo->find_locked( $booking_id );
            if ( ! $booking ) {
                $this->transaction->rollback();
                return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
            }

            if ( $cancel_token !== null ) {
                $token_check = $this->token_guard->verify_cancel_token( $booking, $cancel_token );
                if ( ! $token_check['valid'] ) {
                    $this->transaction->rollback();
                    return [ 'error' => $token_check['error'], 'code' => $token_check['code'] ];
                }
            }

            $guard = $this->state_guard->assert_can_cancel( $booking, $cancelled_by );
            if ( ! $guard['allowed'] ) {
                $this->transaction->rollback();
                return [ 'error' => $guard['error'], 'code' => $guard['code'] ];
            }
            $status = $guard['target_status'];

            $this->state_log_recorder->record(
                $booking,
                $status,
                $reason,
                $cancel_token !== null ? 'public_token' : null,
                null
            );
            $booking->status = $status;
            if ( $reason ) {
                $booking->notes_internal = trim( $booking->notes_internal . "\nCancelada: " . $reason );
            }
            $this->token_generator->generate_cancel_token( $booking );
            $this->booking_repo->update( $booking );

            $this->lock_releaser->release_all_for_cancel( $booking->id, $cancelled_by );

            $this->transaction->commit();
        } catch ( \Throwable $e ) {
            $this->transaction->rollback();
            throw $e;
        }

        $is_public_token = $cancel_token !== null;

        if ( $cancelled_by === 'admin' && ! $is_public_token ) {
            $this->audit_log_repo->insert( [
                'entity_type' => 'booking',
                'entity_id'   => $booking->id,
                'action'      => 'admin_cancel',
                'actor_type'  => 'admin',
                'actor_id'    => $this->actor_context->get_current_user_id(),
                'message'     => 'Booking cancelled by admin.',
                'context'     => [
                    'reason' => $reason,
                    'status' => $status,
                ],
            ] );
        } elseif ( $is_public_token ) {
            $this->audit_log_repo->insert( [
                'entity_type' => 'booking',
                'entity_id'   => $booking->id,
                'action'      => 'public_cancel',
                'actor_type'  => 'public_token',
                'actor_id'    => null,
                'message'     => 'Booking cancelled via public cancel token.',
                'context'     => [
                    'reason' => $reason,
                    'status' => $status,
                    'token_prefix' => substr( $cancel_token, 0, 8 ),
                ],
            ] );
        }

        $this->notification_queue->cancel_for_booking( $booking->id );
        $this->availability_service->invalidate_cache( $booking->service_id );
        $this->event_bus->dispatch( new BookingCancelled( $booking->id, $booking->to_array() ) );

        return [ 'success' => true, 'booking_id' => $booking_id ];
    }
}
