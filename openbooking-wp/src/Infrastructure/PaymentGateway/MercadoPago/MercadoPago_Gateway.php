<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\PaymentGateway\MercadoPago;

use OpenBooking\Infrastructure\PaymentGateway\PaymentGatewayInterface;
use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gateway MercadoPago para Checkout Pro.
 * Uses MercadoPago Checkout Pro (preferences API). No SDK dependency.
 * Requires: access_token in wp_options.
 */
class MercadoPago_Gateway implements PaymentGatewayInterface {

    private const API_BASE = 'https://api.mercadopago.com';

    private const ZERO_DECIMAL_CURRENCIES = [ 'CLP', 'COP' ];


    public function __construct(
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo, // consulta y persiste reservas
        private \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface $service_repo, // consulta servicios del catalogo
        private \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface $customer_repo, // consulta datos de clientes
        private \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface $payment_repo, // consulta y persiste pagos
    ) {}

    private static function currency_decimals( string $currency ): int {
        return in_array( strtoupper( $currency ), self::ZERO_DECIMAL_CURRENCIES, true ) ? 0 : 2;
    }

    private static function minor_to_api( int $amount_minor, string $currency ): float {
        $decimals = self::currency_decimals( $currency );
        return $decimals === 0 ? (float) $amount_minor : round( $amount_minor / 100, $decimals );
    }

    private static function api_to_minor( float $api_amount, string $currency ): int {
        $decimals = self::currency_decimals( $currency );
        return $decimals === 0 ? (int) round( $api_amount ) : (int) round( $api_amount * 100 );
    }

    public function get_key(): string {
        return 'mercadopago';
    }

    public function get_label(): string {
        return __( 'MercadoPago', 'openbooking-wp' );
    }

    public function is_available_for_country( string $country_code ): bool {
        $supported = [ 'CL', 'CO', 'MX', 'AR', 'PE', 'BR', 'UY', 'BO', 'EC', 'VE' ];
        return in_array( strtoupper( $country_code ), $supported, true );
    }

    public function is_enabled(): bool {
        return (bool) ( \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::MP_ACCESS_TOKEN, '' ) ) && get_option( Setting_Keys::MP_WEBHOOK_SECRET, '' ) );
    }

    public function create_checkout( int $booking_id, array $context ): array {
        $access_token = \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::MP_ACCESS_TOKEN, '' ) );
        if ( ! $access_token ) {
            return [ 'error' => 'MercadoPago no está configurado.' ];
        }

        $booking  = $this->booking_repo->find( $booking_id );
        $service  = $booking ? $this->service_repo->find( $booking->service_id ) : null;
        $customer = $booking ? $this->customer_repo->find( $booking->customer_id ) : null;

        if ( ! $booking || ! $service || ! $customer ) {
            return [ 'error' => 'Datos de reserva incompletos.' ];
        }

        $payment_id  = $context['payment_id'] ?? 0;
        $currency    = strtoupper( $context['currency'] ?? $booking->currency );
        $amount      = self::minor_to_api( $context['amount'] ?? $booking->price_due_now_minor, $currency );
        $public_token = $booking->view_token ?: $booking->booking_token;
        $success_url = \OpenBooking\Support\Public_Booking_Page::get_url( [
            Setting_Keys::PAYMENT_NONCE_KEY => 'success',
            'booking_id'   => $booking_id,
            'payment_id'   => $payment_id,
            Setting_Keys::TOKEN_NONCE_KEY   => $public_token,
        ] );
        $failure_url = \OpenBooking\Support\Public_Booking_Page::get_url( [
            Setting_Keys::PAYMENT_NONCE_KEY => 'cancel',
            'booking_id'   => $booking_id,
            Setting_Keys::TOKEN_NONCE_KEY   => $public_token,
        ] );
        $pending_url = \OpenBooking\Support\Public_Booking_Page::get_url( [
            Setting_Keys::PAYMENT_NONCE_KEY => 'pending',
            'booking_id'   => $booking_id,
            Setting_Keys::TOKEN_NONCE_KEY   => $public_token,
        ] );

        $preference = [
            'items' => [
                [
                    'title'       => sprintf(
                        '%s — %s',
                        $service->name,
                        date_i18n( get_option( 'date_format' ), strtotime( $booking->start_at ) )
                    ),
                    'quantity'    => 1,
                    'unit_price'  => (float) $amount,
                    'currency_id' => $currency,
                ],
            ],
            'payer'           => [
                'name'  => $customer->first_name,
                'surname' => $customer->last_name ?? '',
                'email' => $customer->email,
            ],
            'back_urls'       => [
                'success' => $success_url,
                'failure' => $failure_url,
                'pending' => $pending_url,
            ],
            'auto_return'     => 'approved',
            'external_reference' => (string) $payment_id,
            'notification_url'   => rest_url( 'openbooking/v1/payments/webhook/mercadopago' ),
        ];

        $response = wp_remote_post( self::API_BASE . '/checkout/preferences', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
                'X-Idempotency-Key' => 'obwp-booking-' . $booking_id . '-' . $payment_id,
            ],
            'body'    => wp_json_encode( $preference ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['id'] ) ) {
            return [ 'error' => $data['message'] ?? 'Error de MercadoPago.' ];
        }

        $redirect_url = str_starts_with( $access_token, 'TEST-' ) && ! empty( $data['sandbox_init_point'] )
            ? $data['sandbox_init_point']
            : $data['init_point'];

        return [
            'redirect_url'   => $redirect_url,
            'preference_id'  => $data['id'],
        ];
    }

    public function handle_webhook( string $payload, array $headers = [] ): array {
        $webhook_secret = \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::MP_WEBHOOK_SECRET, '' ) );
        if ( ! $webhook_secret ) {
            return [ 'error' => 'MercadoPago webhook secret missing.', 'http_status' => 503 ];
        }

        $sig_header = $headers['x_signature'][0] ?? ( $headers['x-signature'][0] ?? '' );
        if ( ! $sig_header || ! $this->verify_mp_signature( $payload, $headers, $sig_header, $webhook_secret ) ) {
            return [ 'error' => 'Invalid signature.', 'http_status' => 401 ];
        }

        // Reject webhooks older than 10 minutes to prevent replay attacks.
        $parts = [];
        foreach ( explode( ',', $sig_header ) as $part ) {
            [ $k, $v ] = array_pad( explode( '=', $part, 2 ), 2, '' );
            $parts[ trim( $k ) ] = trim( $v );
        }
        $ts = (int) ( $parts['ts'] ?? 0 );
        if ( $ts && abs( time() - $ts ) > 600 ) {
            return [ 'error' => 'Webhook timestamp out of window.', 'http_status' => 401 ];
        }

        $data = json_decode( $payload, true );
        if ( empty( $data ) ) {
            // MP can also send x-www-form-urlencoded notifications
            parse_str( $payload, $data );
        }

        $topic   = $data['topic'] ?? $data['type'] ?? '';
        $mp_id   = $data['id'] ?? $data['data']['id'] ?? '';

        if ( ! $mp_id ) {
            return [ 'handled' => true ];
        }

        $event_key = '';
        if ( in_array( $topic, [ 'payment', 'merchant_order' ], true ) ) {
            $event_key = Setting_Keys::MP_EVENT_PREFIX . sanitize_key( (string) $topic ) . '_' . absint( $mp_id );
            if ( get_transient( $event_key ) ) {
                return [ 'handled' => true ];
            }
        }

        if ( in_array( $topic, [ 'payment', 'merchant_order' ], true ) || $topic === 'payment' ) {
            $access_token = \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::MP_ACCESS_TOKEN, '' ) );
            if ( ! $access_token ) {
                return [ 'error' => 'MercadoPago not configured.' ];
            }

            $response = wp_remote_get( self::API_BASE . '/v1/payments/' . absint( $mp_id ), [
                'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
                'timeout' => 10,
            ] );

            if ( is_wp_error( $response ) ) {
                return [ 'error' => $response->get_error_message() ];
            }

            $payment_data = json_decode( wp_remote_retrieve_body( $response ), true );
            $mp_status    = $payment_data['status'] ?? '';
            $external_ref = $payment_data['external_reference'] ?? '';
            $payment_id   = (int) $external_ref;

            if ( ! $payment_id ) {
                return [ 'handled' => true ];
            }

            $status = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PENDING;
            if ( $mp_status === 'approved' ) {
                $status = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID;
            } elseif ( in_array( $mp_status, [ 'rejected', 'cancelled' ], true ) ) {
                $status = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED;
            }

            $result = [
                'payment_id'          => $payment_id,
                'status'              => $status,
                'provider_payment_id' => (string) $mp_id,
                'raw_payload'         => $payload,
                'event_transient_key' => $event_key,
            ];

            $mp_raw_amount = isset( $payment_data['transaction_details']['total_paid_amount'] )
                ? (float) $payment_data['transaction_details']['total_paid_amount']
                : ( isset( $payment_data['transaction_amount'] ) ? (float) $payment_data['transaction_amount'] : null );
            $mp_currency  = isset( $payment_data['currency_id'] ) ? strtoupper( $payment_data['currency_id'] ) : null;
            if ( $mp_raw_amount !== null ) {
                $conversion_currency = $mp_currency;
                if ( ! $conversion_currency ) {
                    $local_payment = $this->payment_repo->find( $payment_id );
                    $conversion_currency = $local_payment ? strtoupper( $local_payment->currency ) : 'USD';
                }
                $result['gateway_amount_minor'] = self::api_to_minor( $mp_raw_amount, $conversion_currency );
            }
            if ( $mp_currency ) {
                $result['gateway_currency'] = $mp_currency;
            }

            return $result;
        }

        return [ 'handled' => true ];
    }

    private function verify_mp_signature( string $payload, array $headers, string $sig_header, string $secret ): bool {
        $parts = [];
        foreach ( explode( ',', $sig_header ) as $part ) {
            [ $k, $v ] = array_pad( explode( '=', $part, 2 ), 2, '' );
            $parts[ trim( $k ) ] = trim( $v );
        }

        $timestamp  = $parts['ts'] ?? '';
        $v1         = $parts['v1'] ?? '';

        if ( ! $timestamp || ! $v1 ) {
            return false;
        }

        $request_id  = $headers['x_request_id'][0] ?? '';
        $data        = json_decode( $payload, true );
        $resource_id = $data['data']['id'] ?? $data['id'] ?? '';
        $manifest    = "id:{$resource_id};request-id:{$request_id};ts:{$timestamp};";

        $expected = hash_hmac( 'sha256', $manifest, $secret );
        return hash_equals( $expected, $v1 );
    }

    public function refund( int $payment_id, ?int $amount_minor = null ): array {
        $access_token = \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::MP_ACCESS_TOKEN, '' ) );
        if ( ! $access_token ) {
            return [ 'success' => false, 'message' => 'MercadoPago no está configurado.' ];
        }

        $payment = $this->payment_repo->find( $payment_id );
        if ( ! $payment || ! $payment->provider_payment_id ) {
            return [ 'success' => false, 'message' => 'Pago no encontrado.' ];
        }

        $body = [];
        if ( $amount_minor ) {
            $body['amount'] = self::minor_to_api( $amount_minor, $payment->currency );
        }

        $response = wp_remote_post(
            self::API_BASE . '/v1/payments/' . absint( $payment->provider_payment_id ) . '/refunds',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => $body ? wp_json_encode( $body ) : '{}',
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $data['error'] ) ) {
            return [ 'success' => false, 'message' => $data['message'] ?? 'Error de MercadoPago.' ];
        }

        $payment->status = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED;
        $this->payment_repo->update( $payment );

        return [ 'success' => true, 'refund_id' => $data['id'] ?? '' ];
    }
}
