<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Domain\Booking\Entity\Booking_Entity;
use OpenBooking\Domain\Booking\Repository\BookingStateLogRepositoryInterface;
use OpenBooking\Domain\Shared\Port\ActorContextInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registra eventos o cambios en el bounded context de reservas.
 */

class Booking_State_Log_Recorder {


    public function __construct(
        private BookingStateLogRepositoryInterface $repo,
        private ActorContextInterface $actor_context,
    ) {
$this->actor_context = $actor_context ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_ActorContext();
    }

    public function record(
        Booking_Entity $booking,
        string $new_status,
        ?string $reason = null,
        ?string $actor_type = null,
        ?int $actor_id = null,
        ?string $to_payment_status = null
    ): void {
        if ( $actor_type === null ) {
            $actor_type = $this->actor_context->is_user_logged_in() ? 'admin' : 'system';
            $actor_id   = $actor_type === 'admin' ? $this->actor_context->get_current_user_id() ?: null : null;
        }

        $this->repo->insert_state_change(
            $booking,
            $new_status,
            $reason,
            $actor_type,
            $actor_id,
            $to_payment_status ?? $booking->payment_status,
            \OpenBooking\Support\Request_Context::get_request_id() ?: null
        );
    }
}
