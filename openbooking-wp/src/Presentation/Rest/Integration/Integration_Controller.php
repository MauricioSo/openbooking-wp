<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Integration;

use OpenBooking\Application\Availability\Service\Availability_Service;
use OpenBooking\Application\Booking\UseCase\Cancel_Booking_Use_Case;
use OpenBooking\Application\Booking\UseCase\Create_Booking_Use_Case;
use OpenBooking\Application\Booking\UseCase\Reschedule_Booking_Use_Case;
use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface;
use OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface;
use OpenBooking\Application\Integration\Service\Integration_Authenticator;
use OpenBooking\Application\Integration\Service\Integration_Integrity_Service;
use OpenBooking\Application\Integration\Service\Integration_Booking_Service;
use OpenBooking\Domain\Integration\Repository\IntegrationRequestLogRepositoryInterface;
use OpenBooking\Support\Service_Payloads;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone la API de integraciones autenticadas.
 */
class Integration_Controller {


    public function __construct(
        private Integration_Authenticator $auth,
        private IntegrationRequestLogRepositoryInterface $log_repo,
        private BookingRepositoryInterface $booking_repo,
        private ServiceRepositoryInterface $service_repo,
        private CustomerRepositoryInterface $customer_repo,
        private Availability_Service $availability_service,
        private Create_Booking_Use_Case $create_booking_use_case,
        private Cancel_Booking_Use_Case $cancel_booking_use_case,
        private Reschedule_Booking_Use_Case $reschedule_booking_use_case,
        private Integration_Integrity_Service $integrity_service,
        private Integration_Booking_Service $booking_service,
    ) {}

    public function authenticate_request( \WP_REST_Request $request ): array {
        $headers = [
            'x-ob-client-key' => $request->get_header( 'x-ob-client-key' ) ?? '',
            'x-ob-timestamp'  => $request->get_header( 'x-ob-timestamp' ) ?? '',
            'x-ob-nonce'      => $request->get_header( 'x-ob-nonce' ) ?? '',
            'x-ob-signature'  => $request->get_header( 'x-ob-signature' ) ?? '',
        ];

        $raw_body   = $request->get_body() ?: '';
        $route      = $request->get_route();
        $method     = $request->get_method();

        $auth_result = $this->auth->authenticate( $headers, $raw_body, $route, $method );

        if ( ! $auth_result['valid'] ) {
            return $auth_result;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if ( ! $this->auth->verify_ip( $auth_result['client'], sanitize_text_field( $ip ) ) ) {
            return [ 'valid' => false, 'error' => 'integration_forbidden', 'message' => 'IP address not allowed.' ];
        }

        $auth_result['ip']         = $ip;
        $auth_result['user_agent'] = $request->get_header( 'user-agent' ) ?? '';
        $auth_result['raw_body']   = $raw_body;

        return $auth_result;
    }

    private function log_request( array $auth_result, string $route, string $method, ?string $idempotency_key, string $body_hash ): int {
        return $this->log_repo->insert( [
            'request_id'      => $this->generate_request_id(),
            'client_id'       => (int) ( $auth_result['client']['id'] ?? 0 ),
            'client_key'      => $auth_result['client']['client_key'] ?? '',
            'route'           => $route,
            'http_method'     => $method,
            'idempotency_key' => $idempotency_key,
            'body_hash'       => $body_hash,
            'ip_address'      => $auth_result['ip'] ?? '',
            'user_agent'      => $auth_result['user_agent'] ?? '',
        ] );
    }

    private function generate_request_id(): string {
        return 'obreq_' . bin2hex( random_bytes( 16 ) );
    }

    public function health( \WP_REST_Request $request ): \WP_REST_Response {
        $auth_result = $this->authenticate_request( $request );
        if ( ! $auth_result['valid'] ) {
            return new \WP_REST_Response( [ 'error' => $auth_result['error'], 'message' => $auth_result['message'] ], 401 );
        }

        return new \WP_REST_Response( [
            'status'     => 'ok',
            'client_key' => $auth_result['client']['client_key'],
            'timestamp'  => current_time( 'mysql', true ),
        ], 200 );
    }

    public function get_services( \WP_REST_Request $request ): \WP_REST_Response {
        $auth_result = $this->authenticate_request( $request );
        if ( ! $auth_result['valid'] ) {
            return new \WP_REST_Response( [ 'error' => $auth_result['error'], 'message' => $auth_result['message'] ], 401 );
        }

        if ( ! $this->auth->verify_scope( $auth_result['client'], 'services:read' ) ) {
            return new \WP_REST_Response( [ 'error' => 'integration_forbidden', 'message' => 'Insufficient scope.' ], 403 );
        }

        $services     = $this->service_repo->find_all( [ 'status' => 'active' ] );

        $result = array_map( function ( $s ) {
            return Service_Payloads::public_from_entity( $s );
        }, $services );

        return new \WP_REST_Response( [ 'services' => $result ], 200 );
    }

    public function get_availability( \WP_REST_Request $request ): \WP_REST_Response {
        $auth = $this->guard_authenticated_scope( $request, 'availability:read' );
        if ( $auth instanceof \WP_REST_Response ) {
            return $auth;
        }

        $service_id  = absint( $request->get_param( 'service_id' ) );
        $date        = sanitize_text_field( $request->get_param( 'date' ) );
        $resource_id = $request->get_param( 'resource_id' ) ? absint( $request->get_param( 'resource_id' ) ) : null;

        if ( ! $service_id || ! $date ) {
            return new \WP_REST_Response( [ 'error' => 'invalid_request', 'message' => 'service_id and date are required.' ], 400 );
        }

        $slots = $this->availability_service->get_slots( $service_id, $date, $resource_id );

        return new \WP_REST_Response( [ 'slots' => $slots ], 200 );
    }

    public function create_booking( \WP_REST_Request $request ): \WP_REST_Response {
        $auth = $this->guard_authenticated_scope( $request, 'bookings:write' );
        if ( $auth instanceof \WP_REST_Response ) {
            return $auth;
        }

        $idempotency_key = $request->get_header( 'idempotency-key' );
        if ( empty( $idempotency_key ) ) {
            return new \WP_REST_Response( [ 'error' => 'invalid_request', 'message' => 'Idempotency-Key header is required.' ], 400 );
        }
        $idempotency_key = substr( sanitize_text_field( $idempotency_key ), 0, 191 );
        if ( $idempotency_key === '' ) {
            return new \WP_REST_Response( [ 'error' => 'invalid_request', 'message' => 'Idempotency-Key header is required.' ], 400 );
        }

        $auth['_route'] = $request->get_route();

        $result = $this->booking_service->create_booking_with_idempotency(
            $auth,
            $auth['raw_body'],
            $idempotency_key,
            $this->create_booking_use_case
        );

        return new \WP_REST_Response( $result['data'], $result['status'] );
    }

    public function get_booking( \WP_REST_Request $request ): \WP_REST_Response {
        $auth = $this->guard_authenticated_scope( $request, 'bookings:read' );
        if ( $auth instanceof \WP_REST_Response ) {
            return $auth;
        }

        $booking = $this->guard_booking_ownership( $auth, absint( $request['id'] ) );
        if ( $booking instanceof \WP_REST_Response ) {
            return $booking;
        }

        $svc      = $this->service_repo->find( $booking->service_id );
        $customer = $this->customer_repo->find( $booking->customer_id );

        return new \WP_REST_Response( [
            'booking_id'  => $booking->id,
            'external_id' => $booking->external_id,
            'booking'     => \OpenBooking\Presentation\Rest\Booking\Booking_Response_Mapper::integration_booking( $booking, $svc, $customer ),
            'service'     => $svc ? Service_Payloads::public_from_entity( $svc ) : null,
            'customer'    => $customer ? [ 'first_name' => $customer->first_name, 'last_name' => $customer->last_name, 'email' => $customer->email ] : null,
        ], 200 );
    }

    public function cancel_booking( \WP_REST_Request $request ): \WP_REST_Response {
        $auth = $this->guard_authenticated_scope( $request, 'bookings:write' );
        if ( $auth instanceof \WP_REST_Response ) {
            return $auth;
        }

        $booking = $this->guard_booking_ownership( $auth, absint( $request['id'] ) );
        if ( $booking instanceof \WP_REST_Response ) {
            return $booking;
        }

        $body   = json_decode( $request->get_body(), true ) ?: [];
        $reason = sanitize_text_field( $body['reason'] ?? 'Cancelled via integration API.' );
        $log_id = $this->log_request( $auth, $request->get_route(), $request->get_method(), null, hash( 'sha256', $request->get_body() ?: '' ) );
        $result = $this->cancel_booking_use_case->execute( $booking->id, 'admin', $reason );

        if ( ! empty( $result['error'] ) ) {
            $status = $result['code'] ?? 400;
            if ( $log_id > 0 ) {
                $this->log_repo->update_result( $log_id, $status, 'booking_cancel_failed' );
            }
            return new \WP_REST_Response( [ 'error' => $result['error'] ], $status );
        }

        if ( $log_id > 0 ) {
            $this->log_repo->update_result( $log_id, 200, null, 'booking', $booking->id );
        }

        return new \WP_REST_Response( [ 'success' => true, 'booking_id' => $booking->id ], 200 );
    }

    public function reschedule_booking( \WP_REST_Request $request ): \WP_REST_Response {
        $auth = $this->guard_authenticated_scope( $request, 'bookings:write' );
        if ( $auth instanceof \WP_REST_Response ) {
            return $auth;
        }

        $booking = $this->guard_booking_ownership( $auth, absint( $request['id'] ) );
        if ( $booking instanceof \WP_REST_Response ) {
            return $booking;
        }

        $body        = json_decode( $request->get_body(), true ) ?: [];
        $new_start   = sanitize_text_field( $body['start_at'] ?? '' );
        $resource_id = ! empty( $body['resource_id'] ) ? absint( $body['resource_id'] ) : null;

        if ( empty( $new_start ) ) {
            return new \WP_REST_Response( [ 'error' => 'invalid_request', 'message' => 'start_at is required.' ], 400 );
        }

        $log_id = $this->log_request( $auth, $request->get_route(), $request->get_method(), null, hash( 'sha256', $request->get_body() ?: '' ) );
        $result = $this->reschedule_booking_use_case->execute( $booking->id, $new_start, $resource_id );

        if ( ! empty( $result['error'] ) ) {
            $status = $result['code'] ?? 400;
            if ( $log_id > 0 ) {
                $this->log_repo->update_result( $log_id, $status, 'booking_reschedule_failed' );
            }
            return new \WP_REST_Response( [ 'error' => $result['error'] ], $status );
        }

        if ( $log_id > 0 ) {
            $this->log_repo->update_result( $log_id, 200, null, 'booking', $booking->id );
        }

        return new \WP_REST_Response( [ 'success' => true, 'booking_id' => $booking->id ], 200 );
    }

    public function integrity_check( \WP_REST_Request $request ): \WP_REST_Response {
        $auth = $this->guard_authenticated_scope( $request, 'system:read' );
        if ( $auth instanceof \WP_REST_Response ) {
            return $auth;
        }

        return new \WP_REST_Response( $this->integrity_service->run_full_check(), 200 );
    }

    // ─── Guards ────────────────────────────────────────

    private function guard_authenticated_scope( \WP_REST_Request $request, string $scope ): array|\WP_REST_Response {
        $auth_result = $this->authenticate_request( $request );

        if ( ! $auth_result['valid'] ) {
            return new \WP_REST_Response( [ 'error' => $auth_result['error'], 'message' => $auth_result['message'] ], 401 );
        }

        if ( ! $this->auth->verify_scope( $auth_result['client'], $scope ) ) {
            return new \WP_REST_Response( [ 'error' => 'integration_forbidden', 'message' => 'Insufficient scope.' ], 403 );
        }

        return $auth_result;
    }

    private function guard_booking_ownership( array $auth_result, int $booking_id ): \OpenBooking\Domain\Booking\Entity\Booking_Entity|\WP_REST_Response {
        $client_key = $auth_result['client']['client_key'];
        $booking    = $this->booking_service->find_and_verify_ownership( $booking_id, $client_key );

        if ( ! $booking ) {
            return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
        }

        return $booking;
    }
}
