<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\PaymentGateway\Webpay;

use OpenBooking\Infrastructure\PaymentGateway\PaymentGatewayInterface;
use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gateway Webpay Plus de Transbank.
 * Uses Webpay Plus REST API v1.2 (no SDK dependency).
 * Requires: commerce_code (Tbk-Api-Key-Id) and api_key (Tbk-Api-Key-Secret) in wp_options.
 *
 * Flow:
 *   1. create_checkout() → GET redirect to Webpay with token_ws query param.
 *   2. Transbank redirects buyer back to /payments/webpay-return?payment_id=X with token_ws.
 *   3. Return endpoint calls handle_webhook() with packed JSON payload to confirm transaction.
 *   4. Refund via /transactions/{token}/refunds (anulación/reversa).
 *
 * Sandbox: use Transbank's published Integration credentials from their official docs.
 * Never hardcode credentials in source code.
 */
class Webpay_Gateway implements PaymentGatewayInterface {

    private const API_BASE_SANDBOX = 'https://webpay3gint.transbank.cl';
    private const API_BASE_LIVE    = 'https://webpay3g.transbank.cl';
    private const TX_PATH          = '/rswebpaytransaction/api/webpay/v1.2/transactions';


    public function __construct(
        private \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface $payment_repo, // consulta y persiste pagos
    ) {}

    private function api_base(): string {
        return get_option( Setting_Keys::WEBPAY_SANDBOX, '1' ) ? self::API_BASE_SANDBOX : self::API_BASE_LIVE;
    }

    private function request_headers(): array {
        return [
            'Tbk-Api-Key-Id'     => (string) get_option( Setting_Keys::WEBPAY_COMMERCE_CODE, '' ),
            'Tbk-Api-Key-Secret' => \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::WEBPAY_API_KEY, '' ) ),
            'Content-Type'       => 'application/json',
        ];
    }

    public function get_key(): string {
        return 'webpay';
    }

    public function get_label(): string {
        return __( 'Webpay (Transbank)', 'openbooking-wp' );
    }

    public function is_available_for_country( string $country_code ): bool {
        return strtoupper( $country_code ) === 'CL';
    }

    public function is_enabled(): bool {
        return (bool) ( get_option( Setting_Keys::WEBPAY_COMMERCE_CODE, '' ) && \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::WEBPAY_API_KEY, '' ) ) );
    }

    public function create_checkout( int $booking_id, array $context ): array {
        if ( ! $this->is_enabled() ) {
            return [ 'error' => 'Webpay no está configurado.' ];
        }

        $payment_id = (int) ( $context['payment_id'] ?? 0 );
        $amount     = (int) ( $context['amount'] ?? 0 );  // CLP is always integer

        if ( ! $payment_id || ! $amount ) {
            return [ 'error' => 'Datos de pago incompletos.' ];
        }

        // buy_order max 26 chars, session_id max 61 chars (Transbank limits).
        $buy_order  = substr( 'ob-' . $booking_id . '-' . $payment_id, 0, 26 );
        $session_id = substr( 'sess-' . $payment_id . '-' . substr( md5( (string) $payment_id ), 0, 8 ), 0, 61 );
        $return_url = rest_url( 'openbooking/v1/payments/webpay-return' ) . '?payment_id=' . $payment_id;

        $response = wp_remote_post( $this->api_base() . self::TX_PATH, [
            'headers' => $this->request_headers(),
            'body'    => wp_json_encode( [
                'buy_order'  => $buy_order,
                'session_id' => $session_id,
                'amount'     => $amount,
                'return_url' => $return_url,
            ] ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $data      = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $http_code !== 200 || empty( $data['token'] ) || empty( $data['url'] ) ) {
            $detail = $data['error_message'] ?? ( $data['detail'] ?? 'Error al crear transacción Webpay.' );
            return [ 'error' => $detail ];
        }

        return [
            'redirect_url' => $data['url'] . '?token_ws=' . rawurlencode( $data['token'] ),
            'session_id'   => $data['token'],  // stored as provider_checkout_id by Payment_Service
        ];
    }

    /**
     * Webpay Plus uses a browser return URL, not server-to-server webhooks.
     * The return endpoint packages {token_ws, payment_id} in payload and calls this method
     * to confirm the transaction synchronously.
     */
    public function handle_webhook( string $payload, array $headers = [] ): array {
        $data       = json_decode( $payload, true );
        $token_ws   = (string) ( $data['token_ws'] ?? '' );
        $payment_id = (int) ( $data['payment_id'] ?? 0 );

        if ( ! $token_ws || ! $payment_id ) {
            return [ 'handled' => false ];
        }

        $payment = $this->payment_repo->find( $payment_id );
        if ( $payment && ( ! $payment->provider_checkout_id || ! hash_equals( (string) $payment->provider_checkout_id, $token_ws ) ) ) {
            return [ 'error' => 'Webpay token does not match payment checkout.', 'http_status' => 400 ];
        }

        $confirm = $this->call_confirm_api( $token_ws );

        if ( ! empty( $confirm['error'] ) ) {
            return [
                'payment_id' => $payment_id,
                'status'     => \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED,
                'raw_payload' => $payload,
            ];
        }

        return array_merge( $confirm, [
            'payment_id'  => $payment_id,
            'raw_payload' => $payload,
        ] );
    }

    /**
     * Calls PUT /transactions/{token_ws} to confirm a Webpay Plus transaction.
     * Returns a normalized payment result array.
     */
    private function call_confirm_api( string $token_ws ): array {
        $response = wp_remote_request(
            $this->api_base() . self::TX_PATH . '/' . rawurlencode( $token_ws ),
            [
                'method'  => 'PUT',
                'headers' => $this->request_headers(),
                'body'    => '{}',
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            $msg = $response->get_error_message();
            error_log( '[Webpay] call_confirm_api wp_error: ' . $msg );
            return [ 'error' => $msg ];
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $body_raw  = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body_raw, true );

        if ( $http_code !== 200 ) {
            $detail = $data['error_message'] ?? ( $data['detail'] ?? 'Error al confirmar transacción Webpay.' );
            error_log( '[Webpay] call_confirm_api HTTP ' . $http_code . ': ' . $detail . ' | body: ' . substr( $body_raw, 0, 200 ) );
            return [ 'error' => $detail ];
        }

        $response_code = (int) ( $data['response_code'] ?? -1 );
        $tbk_status    = $data['status'] ?? '';

        if ( $response_code === 0 && $tbk_status === 'AUTHORIZED' ) {
            // Use token_ws as provider_payment_id because authorization_code is not
            // guaranteed to be unique (sandbox always returns the same code, e.g. "1213").
            // token_ws is always unique per transaction and used for refunds anyway.
            return [
                'status'               => \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID,
                'provider_payment_id'  => $token_ws,
                'gateway_amount_minor' => (int) round( (float) ( $data['amount'] ?? 0 ) ),
                'gateway_currency'     => 'CLP',
            ];
        }

        return [
            'status' => \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED,
        ];
    }

    /**
     * Refund (anulación/reversa) via POST /transactions/{token}/refunds.
     * Full reversal: omit amount. Partial reversal: include amount in CLP.
     * Note: Transbank only allows reversal within 24h; after that use nullification.
     */
    public function refund( int $payment_id, ?int $amount_minor = null ): array {
        $payment      = $this->payment_repo->find( $payment_id );

        if ( ! $payment || ! $payment->provider_checkout_id ) {
            return [ 'success' => false, 'message' => 'Pago no encontrado o sin token de transacción.' ];
        }

        $body = $amount_minor !== null ? [ 'amount' => $amount_minor ] : [];

        $response = wp_remote_post(
            $this->api_base() . self::TX_PATH . '/' . rawurlencode( $payment->provider_checkout_id ) . '/refunds',
            [
                'headers' => $this->request_headers(),
                'body'    => wp_json_encode( $body ),
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $data      = json_decode( wp_remote_retrieve_body( $response ), true );

        // 200 = partial reversal; 204 = full reversal (empty body).
        if ( $http_code === 200 || $http_code === 204 ) {
            $payment->status = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED;
            $this->payment_repo->update( $payment );
            return [ 'success' => true, 'type' => $data['type'] ?? 'REVERSED' ];
        }

        $detail = $data['error_message'] ?? ( $data['detail'] ?? 'Error al reembolsar en Webpay.' );
        return [ 'success' => false, 'message' => $detail ];
    }
}
