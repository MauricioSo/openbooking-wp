<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Audit\Service;

use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface;
use OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface;
use OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface;
use OpenBooking\Domain\Booking\Entity\Booking_Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Audit_Enrichment_Service {


    public function __construct(
        private BookingRepositoryInterface $booking_repo,
        private PaymentRepositoryInterface $payment_repo,
        private ServiceRepositoryInterface $service_repo,
        private CustomerRepositoryInterface $customer_repo,
    ) {}

    public function enrich_log( array $log ): array {
        $log['request_link'] = ! empty( $log['request_id'] )
            ? admin_url( 'admin.php?page=openbooking-audit-logs&request_id=' . rawurlencode( (string) $log['request_id'] ) )
            : null;

        if ( ! empty( $log['entity_type'] ) && ! empty( $log['entity_id'] ) ) {
            $log['entity_link'] = admin_url(
                'admin.php?page=openbooking-audit-logs&entity_type=' . rawurlencode( (string) $log['entity_type'] ) . '&entity_id=' . absint( $log['entity_id'] )
            );
        }

        if ( ! empty( $log['actor_id'] ) ) {
            $user = get_userdata( (int) $log['actor_id'] );
            if ( $user ) {
                $log['actor'] = [
                    'display_name' => $user->display_name,
                    'email'        => $user->user_email,
                ];
            }
        }

        if ( ( $log['entity_type'] ?? '' ) === 'booking' && ! empty( $log['entity_id'] ) ) {
            $booking = $this->booking_repo->find( (int) $log['entity_id'] );
            if ( $booking ) {
                $booking_data = $this->enrich_booking( $booking );
                $log['entity_summary'] = [
                    'id'            => $booking_data['id'],
                    'status'        => $booking_data['status'],
                    'start_at'      => $booking_data['start_at'],
                    'service_name'  => $booking_data['service_name'],
                    'customer_name' => $booking_data['customer_name'],
                ];
            }
        }

        if ( ( $log['entity_type'] ?? '' ) === 'payment' && ! empty( $log['entity_id'] ) ) {
            $payment = $this->payment_repo->find( (int) $log['entity_id'] );
            if ( $payment ) {
                $payment_data = $payment->to_array();
                $log['entity_summary'] = [
                    'id'      => $payment_data['id'],
                    'gateway' => $payment_data['gateway'],
                    'status'  => $payment_data['status'],
                ];
            }
        }

        return $log;
    }

    public function enrich_logs( array $logs ): array {
        return array_map( [ $this, 'enrich_log' ], $logs );
    }

    public function enrich_booking( Booking_Entity $booking ): array {
        $data = $booking->to_array();
        $service  = $this->service_repo->find( $booking->service_id );
        $customer = $this->customer_repo->find( $booking->customer_id );
        $data['service_name']   = $service ? $service->name : '';
        $data['customer_name']  = $customer ? $customer->get_full_name() : '';
        $data['customer_email'] = $customer ? $customer->email : '';

        return $data;
    }
}
