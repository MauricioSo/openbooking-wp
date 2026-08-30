<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Domain\Booking\Entity\Booking_Entity;
use OpenBooking\Domain\Booking\Service\Booking_Token_Generator;
use OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Aplica reglas de validacion del bounded context de reservas.
 */

class Booking_Token_Guard {


    public function __construct(
        private AuditRepositoryInterface $audit_log_repo,
        private Booking_Token_Generator $token_generator,
    ) {}

    public function verify_cancel_token( Booking_Entity $booking, string $cancel_token ): array {
        if ( ! hash_equals( (string) $booking->cancel_token, $cancel_token ) || ! $this->token_generator->is_cancel_token_valid( $booking ) ) {
            $this->audit_log_repo->insert( [
                'entity_type' => 'booking',
                'action'      => 'cancel_token_replay_blocked',
                'severity'    => 'warning',
                'message'     => 'Cancel token no longer matches locked booking (concurrent replay).',
                'context'     => [ 'booking_id' => $booking->id, 'token_prefix' => substr( $cancel_token, 0, 8 ) ],
            ] );
            return [
                'valid' => false,
                'error' => __( 'El enlace de cancelación ya no es válido.', 'openbooking-wp' ),
                'code'  => 404,
            ];
        }
        return [ 'valid' => true ];
    }

    public function verify_reschedule_token( Booking_Entity $booking, string $reschedule_token ): array {
        if ( ! hash_equals( (string) $booking->reschedule_token, $reschedule_token ) || ! $this->token_generator->is_reschedule_token_valid( $booking ) ) {
            $this->audit_log_repo->insert( [
                'entity_type' => 'booking',
                'action'      => 'reschedule_token_replay_blocked',
                'severity'    => 'warning',
                'message'     => 'Reschedule token no longer matches locked booking (concurrent replay).',
                'context'     => [ 'booking_id' => $booking->id, 'token_prefix' => substr( $reschedule_token, 0, 8 ) ],
            ] );
            return [
                'valid' => false,
                'error' => __( 'El enlace de reprogramación ya no es válido.', 'openbooking-wp' ),
                'code'  => 404,
            ];
        }
        return [ 'valid' => true ];
    }
}
