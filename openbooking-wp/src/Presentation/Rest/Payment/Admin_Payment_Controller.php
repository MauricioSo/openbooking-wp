<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Payment;

use OpenBooking\Application\Audit\Service\Audit_Logger;
use OpenBooking\Application\Payment\Service\Payment_Service;
use OpenBooking\Application\Payment\Service\Gateway_Settings_Service;
use OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface;
use OpenBooking\Support\Currency_Helper;
use OpenBooking\Support\Payment_Payloads;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone las acciones administrativas sobre pagos y gateways.
 */
class Admin_Payment_Controller {

    public function __construct(
        private PaymentRepositoryInterface $payment_repo, // consulta y persiste pagos
        private Payment_Service $payment_service, // orquesta ciclo de pago
        private Audit_Logger $audit_logger, // deja trazabilidad de cambios
        private \OpenBooking\Domain\Payment\Repository\GatewayResolverInterface $gateway_resolver, // resuelve pasarela de pago
        private Gateway_Settings_Service $gateway_settings, // configura pasarelas de pago
    ) {}

    public function admin_get_payments( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [];
        if ( $request->get_param( 'booking_id' ) ) {
            $args['booking_id'] = absint( $request->get_param( 'booking_id' ) );
        }
        if ( $request->get_param( 'gateway' ) ) {
            $args['gateway'] = sanitize_text_field( $request->get_param( 'gateway' ) );
        }
        if ( $request->get_param( 'status' ) ) {
            $args['status'] = sanitize_text_field( $request->get_param( 'status' ) );
        }
		if ( $request->get_param( 'limit' ) ) {
			$args['limit'] = min( 500, max( 1, absint( $request->get_param( 'limit' ) ) ) );
		}
        if ( $request->get_param( 'offset' ) ) {
            $args['offset'] = absint( $request->get_param( 'offset' ) );
        }

        $payments = $this->payment_repo->find_all( $args );

        return new \WP_REST_Response( [ 'payments' => array_map( fn( $payment ) => Payment_Payloads::admin_list_from_entity( $payment ), $payments ) ], 200 );
    }

    public function admin_refund_payment( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = absint( $request['id'] );
        $body = $this->decode_json_body( $request );
        $payment = $this->payment_repo->find( $id );

        if ( ! $payment ) {
            return new \WP_REST_Response( [ 'error' => 'Pago no encontrado.' ], 404 );
        }

        $gateway = $this->gateway_resolver->get( $payment->gateway );
        if ( ! $gateway ) {
            return new \WP_REST_Response( [ 'error' => 'Gateway no disponible.' ], 400 );
        }

        $amount_minor = ! empty( $body['amount'] ) ? Currency_Helper::major_to_minor( (float) $body['amount'], $payment->currency ) : null;
        $reason       = sanitize_text_field( $body['reason'] ?? 'admin_refund' );
        $result       = $gateway->refund( $id, $amount_minor );

        if ( empty( $result['success'] ) ) {
            return new \WP_REST_Response( [ 'error' => $result['message'] ?? 'Error al reembolsar.' ], 400 );
        }

        $sync_result = $this->payment_service->sync_refund_result( $payment->id, $amount_minor, $result, $reason );
        if ( ! empty( $sync_result['error'] ) ) {
            return new \WP_REST_Response( [ 'error' => $sync_result['error'] ], $sync_result['code'] ?? 400 );
        }

        return new \WP_REST_Response( array_merge( $result, [ 'sync' => $sync_result ] ), 200 );
    }

    public function admin_change_payment_status( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = absint( $request['id'] );
        $body = $this->decode_json_body( $request );
        $status = sanitize_text_field( $body['status'] ?? '' );
        $reason = sanitize_text_field( $body['reason'] ?? '' );

        if ( '' === $status || '' === $reason ) {
            return new \WP_REST_Response( [ 'error' => 'Status y reason son obligatorios.' ], 400 );
        }

        $result = $this->payment_service->change_payment_status_manually( $id, $status, $reason );
        if ( ! empty( $result['error'] ) ) {
            return new \WP_REST_Response( [ 'error' => $result['error'] ], $result['code'] ?? 400 );
        }

        return new \WP_REST_Response( $result, 200 );
    }

    public function admin_dispute_payment( \WP_REST_Request $request ): \WP_REST_Response {
        $id     = absint( $request['id'] );
        $body   = $this->decode_json_body( $request );
        $reason = sanitize_text_field( $body['reason'] ?? '' );
        $result = $this->payment_service->mark_disputed( $id, $reason );

        if ( ! empty( $result['error'] ) ) {
            return new \WP_REST_Response( [ 'error' => $result['error'] ], $result['code'] ?? 400 );
        }

        return new \WP_REST_Response( $result, 200 );
    }

    public function admin_get_payment_attempts( \WP_REST_Request $request ): \WP_REST_Response {
        $id       = absint( $request['id'] );
        $attempts = $this->payment_service->get_attempt_ledger( $id );

        return new \WP_REST_Response( [
            'attempts' => array_map( fn( $a ) => Payment_Payloads::attempt_from_entity( $a ), $attempts ),
        ], 200 );
    }

    public function admin_get_gateways( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'gateways' => $this->gateway_settings->get_gateway_overview() ], 200 );
    }

    public function admin_save_gateway_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $key  = sanitize_key( $request['key'] );
        $body = $this->decode_json_body( $request );
        $result = $this->gateway_settings->save_gateway_settings( $key, $body );

        if ( ! empty( $result['error'] ) ) {
            return new \WP_REST_Response( [ 'error' => $result['error'] ], $result['code'] ?? 400 );
        }

        return new \WP_REST_Response( [
            'success'        => true,
            'applied'        => $result['applied'],
            'ignored_fields' => $result['ignored_fields'],
        ], 200 );
    }

    /**
     * Decodifica el body JSON de forma segura.
     */
    private function decode_json_body( \WP_REST_Request $request ): array {
        $body = json_decode( $request->get_body(), true );

        return is_array( $body ) ? $body : [];
    }
}
