<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Integration\Service;

use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Booking\Entity\Booking_Entity;
use OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface;
use OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface;
use OpenBooking\Domain\Integration\Repository\IntegrationRequestLogRepositoryInterface;
use OpenBooking\Application\Booking\UseCase\Create_Booking_Use_Case;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Integration_Booking_Service {


    public function __construct(
        private IntegrationRequestLogRepositoryInterface $log_repo,
        private BookingRepositoryInterface $booking_repo,
        private ServiceRepositoryInterface $service_repo,
        private CustomerRepositoryInterface $customer_repo,
    ) {}

    public function create_booking_with_idempotency(
        array $auth_result,
        string $raw_body,
        string $idempotency_key,
        Create_Booking_Use_Case $use_case
    ): array {
        $body_hash = hash( 'sha256', $raw_body );
        $client    = $auth_result['client'];
        $client_key = $client['client_key'];

        $existing_log = $this->log_repo->find_by_idempotency_key( $client_key, $idempotency_key );
        if ( $existing_log ) {
            if ( $existing_log['body_hash'] !== $body_hash ) {
                return [
                    'status' => 409,
                    'data'   => [ 'error' => 'idempotency_conflict', 'message' => 'Same Idempotency-Key with different body.' ],
                ];
            }
            $booking = null;
            if ( ! empty( $existing_log['result_entity_id'] ) && $existing_log['result_entity_type'] === 'booking' ) {
                $booking = $this->booking_repo->find( (int) $existing_log['result_entity_id'] );
            }
            if ( $booking ) {
                $service  = $this->service_repo->find( $booking->service_id );
                $customer = $this->customer_repo->find( $booking->customer_id );
                $response_data = $this->build_booking_response( $existing_log['request_id'], $booking, $service, $customer, true );
                return [ 'status' => 200, 'data' => $response_data ];
            }

            return [
                'status' => 409,
                'data'   => [
                    'error'      => 'idempotency_in_progress',
                    'message'    => 'A request with this Idempotency-Key is already being processed.',
                    'request_id' => $existing_log['request_id'] ?? null,
                ],
            ];
        }

        $request_id = 'obreq_' . bin2hex( random_bytes( 16 ) );
        $log_id = $this->log_repo->insert( [
            'request_id'      => $request_id,
            'client_id'       => (int) ( $client['id'] ?? 0 ),
            'client_key'      => $client_key,
            'route'           => $auth_result['_route'] ?? '',
            'http_method'     => 'POST',
            'idempotency_key' => $idempotency_key,
            'body_hash'       => $body_hash,
            'ip_address'      => $auth_result['ip'] ?? '',
            'user_agent'      => $auth_result['user_agent'] ?? '',
        ] );

        if ( $log_id <= 0 ) {
            $existing_log = $this->log_repo->find_by_idempotency_key( $client_key, $idempotency_key );
            if ( $existing_log && ( $existing_log['body_hash'] ?? '' ) === $body_hash ) {
                return [
                    'status' => 409,
                    'data'   => [
                        'error'      => 'idempotency_in_progress',
                        'message'    => 'A request with this Idempotency-Key is already being processed.',
                        'request_id' => $existing_log['request_id'] ?? null,
                    ],
                ];
            }

            return [
                'status' => 409,
                'data'   => [ 'error' => 'idempotency_conflict', 'message' => 'Could not reserve Idempotency-Key.' ],
            ];
        }

        $body = json_decode( $raw_body, true );
        if ( ! is_array( $body ) ) {
            $body = [];
        }

        $forbidden_fields = [ 'status', 'payment_status', 'price_total_minor', 'price_due_now_minor', 'price_paid_minor', 'cancel_token', 'reschedule_token', 'booking_token', 'view_token', 'confirm_token' ];
        foreach ( $forbidden_fields as $field ) {
            unset( $body[ $field ] );
        }

        $external_id = ! empty( $body['external_id'] ) ? sanitize_text_field( $body['external_id'] ) : $idempotency_key;
        unset( $body['source'] );
        unset( $body['external_id'] );

        $body['source']     = $client_key;
        $body['client_ref'] = substr( hash( 'sha256', $client_key . ':' . ( $body['client_ref'] ?? $idempotency_key ) ), 0, 64 );

        $body['_integration_meta'] = [
            'integration_client_key'  => $client_key,
            'integration_request_id' => $request_id,
            'external_id'            => $external_id,
            'created_via'            => 'integration_api',
        ];

        $context = \OpenBooking\Application\Booking\Service\Booking_Request_Context::integration(
            $client_key,
            $request_id,
            $external_id
        );
        $result = $use_case->execute( $body, $context );

        if ( ! empty( $result['error'] ) ) {
            $this->log_repo->update_result( $log_id, $result['code'] ?? 400, 'booking_create_failed' );
            return [ 'status' => $result['code'] ?? 400, 'data' => [ 'error' => $result['error'] ] ];
        }

        $booking_id = (int) $result['booking_id'];
        $booking  = $this->booking_repo->find( $booking_id );
        if ( ! $booking ) {
            $this->log_repo->update_result( $log_id, 500, 'booking_create_result_missing' );
            return [ 'status' => 500, 'data' => [ 'error' => 'booking_create_result_missing' ] ];
        }

        $this->log_repo->update_result( $log_id, 201, null, 'booking', $booking_id );

        $svc      = $this->service_repo->find( $booking->service_id );
        $customer = $this->customer_repo->find( $booking->customer_id );

        $response_data = $this->build_booking_response( $request_id, $booking, $svc, $customer, false, $result );

        return [ 'status' => 201, 'data' => $response_data ];
    }

    public function find_and_verify_ownership( int $booking_id, string $client_key ): ?Booking_Entity {
        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking ) {
            return null;
        }
        if ( $booking->integration_client_key !== $client_key ) {
            return null;
        }
        return $booking;
    }

    private function build_booking_response( string $request_id, $booking, $service, $customer, bool $duplicate = false, array $create_result = [] ): array {
        return \OpenBooking\Presentation\Rest\Booking\Booking_Response_Mapper::integration_create(
            $request_id,
            $booking,
            $service,
            $customer,
            $duplicate,
            $create_result
        );
    }
}
