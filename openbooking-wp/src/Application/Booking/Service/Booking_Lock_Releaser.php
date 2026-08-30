<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Application\Availability\Service\Slot_Lock_Service;
use OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface;
use OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gestiona el ciclo de vida en el bounded context de reservas.
 */

class Booking_Lock_Releaser {


    public function __construct(
        private Slot_Lock_Service $slot_lock_service,
        private PaymentRepositoryInterface $payment_repo,
        private AuditRepositoryInterface $audit_log_repo,
    ) {}

    public function release_all_for_cancel( int $booking_id, string $cancelled_by ): void {
        $this->slot_lock_service->release_for_booking( $booking_id, 'cancelled' );

        $expired_payments = $this->payment_repo->expire_pending_for_booking( $booking_id );
        if ( $expired_payments > 0 ) {
            $this->audit_log_repo->insert( [
                'entity_type' => 'booking',
                'entity_id'   => $booking_id,
                'action'      => 'pending_payments_expired_on_cancel',
                'severity'    => 'info',
                'message'     => "Expired {$expired_payments} pending payment(s) for cancelled booking.",
                'context'     => [
                    'booking_id'     => $booking_id,
                    'payments_count' => $expired_payments,
                    'cancelled_by'   => $cancelled_by,
                ],
            ] );
        }
    }
}
