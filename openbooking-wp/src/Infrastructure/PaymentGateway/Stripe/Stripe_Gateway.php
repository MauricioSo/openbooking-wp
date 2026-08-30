<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\PaymentGateway\Stripe;

use OpenBooking\Infrastructure\PaymentGateway\PaymentGatewayInterface;
use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gateway Stripe para pagos con Checkout Sessions.
 * Uses Stripe Checkout Sessions via the Stripe REST API (no SDK dependency).
 * Requires: secret_key, publishable_key, webhook_secret in wp_options.
 */
class Stripe_Gateway implements PaymentGatewayInterface {

    private const API_BASE = 'https://api.stripe.com/v1';
    private const SUPPORTED_CURRENCIES = [ 'usd', 'mxn', 'eur', 'clp', 'cop', 'pen', 'brl', 'ars' ];


    public function __construct(
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo, // consulta y persiste reservas
        private \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface $service_repo, // consulta servicios del catalogo
        private \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface $customer_repo, // consulta datos de clientes
        private \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface $payment_repo, // consulta y persiste pagos
    ) {}

    public function get_key(): string {
        return 'stripe';
    }

    public function get_label(): string {
        return __( 'Stripe (tarjeta de crédito/débito)', 'openbooking-wp' );
    }

    public function is_available_for_country( string $country_code ): bool {
        $supported = [ 'US', 'MX', 'ES', 'CL', 'CO', 'PE', 'BR', 'AR' ];
        return in_array( strtoupper( $country_code ), $supported, true );
    }

    public function is_enabled(): bool {
        return (bool) ( \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::STRIPE_SECRET_KEY, '' ) ) && \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::STRIPE_WEBHOOK_SECRET, '' ) ) );
    }

    public function create_checkout( int $booking_id, array $context ): array {
        $secret_key = \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::STRIPE_SECRET_KEY, '' ) );
        if ( ! $secret_key ) {
            return [ 'error' => 'Stripe no está configurado.' ];
        }

        $booking  = $this->booking_repo->find( $booking_id );
        $service  = $booking ? $this->service_repo->find( $booking->service_id ) : null;
        $customer = $booking ? $this->customer_repo->find( $booking->customer_id ) : null;

        if ( ! $booking || ! $service || ! $customer ) {
            return [ 'error' => 'Datos de reserva incompletos.' ];
        }

        $payment_id  = $context['payment_id'] ?? 0;
        $amount      = $context['amount'] ?? $booking->price_due_now_minor;
        $currency    = strtolower( $context['currency'] ?? $booking->currency );
        if ( ! preg_match( '/^[a-z]{3}$/', $currency ) || ! in_array( $currency, self::SUPPORTED_CURRENCIES, true ) ) {
            return [ 'error' => 'Moneda de Stripe no soportada.', 'code' => 400 ];
        }
        $public_token = $booking->view_token ?: $booking->booking_token;
        $success_url = \OpenBooking\Support\Public_Booking_Page::get_url( [
            Setting_Keys::PAYMENT_NONCE_KEY => 'success',
            'booking_id'   => $booking_id,
            'payment_id'   => $payment_id,
            Setting_Keys::TOKEN_NONCE_KEY   => $public_token,
        ] );
        $cancel_url  = \OpenBooking\Support\Public_Booking_Page::get_url( [
            Setting_Keys::PAYMENT_NONCE_KEY => 'cancel',
            'booking_id'   => $booking_id,
            Setting_Keys::TOKEN_NONCE_KEY   => $public_token,
        ] );

        $body = [
            'payment_method_types[]'              => 'card',
            'mode'                                => 'payment',
            'customer_email'                      => $customer->email,
            'line_items[0][price_data][currency]' => $currency,
            'line_items[0][price_data][unit_amount]' => $amount,
            'line_items[0][price_data][product_data][name]' => sprintf(
                '%s — %s',
                $service->name,
                date_i18n( get_option( 'date_format' ), strtotime( $booking->start_at ) )
            ),
            'line_items[0][quantity]'             => 1,
            'success_url'                         => $success_url,
            'cancel_url'                          => $cancel_url,
            'metadata[booking_id]'                => $booking_id,
            'metadata[payment_id]'                => $payment_id,
        ];

        $response = wp_remote_post( self::API_BASE . '/checkout/sessions', [
            'headers' => [
                'Authorization'    => 'Basic ' . base64_encode( $secret_key . ':' ),
                'Content-Type'     => 'application/x-www-form-urlencoded',
                'Idempotency-Key'  => 'obwp-payment-' . (int) $payment_id,
            ],
            'body'    => $body,
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'OpenBooking Stripe connection error: ' . $response->get_error_message() );
            return [ 'error' => __( 'No se pudo conectar con el procesador de pago. Inténtalo de nuevo.', 'openbooking-wp' ) ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $data['error'] ) ) {
            error_log( 'OpenBooking Stripe API error: ' . ( $data['error']['message'] ?? 'Unknown error' ) . ' | code: ' . ( $data['error']['code'] ?? '' ) );
            return [ 'error' => __( 'Error al procesar el pago. Inténtalo de nuevo.', 'openbooking-wp' ) ];
        }

        return [
            'redirect_url'  => $data['url'] ?? '',
            'session_id'    => $data['id'] ?? '',
        ];
    }

    public function handle_webhook( string $payload, array $headers = [] ): array {
        $webhook_secret = \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::STRIPE_WEBHOOK_SECRET, '' ) );
        if ( ! $webhook_secret ) {
            return [ 'error' => 'Stripe webhook secret missing.', 'http_status' => 503 ];
        }

        $sig_header = $headers['x_stripe_signature'][0] ?? ( $headers['X-Stripe-Signature'][0] ?? '' );
        if ( ! $sig_header || ! $this->verify_stripe_signature( $payload, $sig_header, $webhook_secret ) ) {
            return [ 'error' => 'Invalid signature.', 'http_status' => 401 ];
        }

        $event = json_decode( $payload, true );
        if ( empty( $event['type'] ) ) {
            return [ 'error' => 'Invalid event.' ];
        }

        // Idempotency: ignore already-processed Stripe events.
        $event_id = $event['id'] ?? '';
        if ( $event_id ) {
            $seen_key = Setting_Keys::STRIPE_EVENT_PREFIX . $event_id;
            if ( get_transient( $seen_key ) ) {
                return [ 'handled' => true ];
            }
        }

        if ( $event['type'] === 'checkout.session.completed' ) {
            $session    = $event['data']['object'] ?? [];
            $payment_id = (int) ( $session['metadata']['payment_id'] ?? 0 );
            $payment_intent = $session['payment_intent'] ?? '';

            if ( $payment_id ) {
                $result = [
                    'payment_id'          => $payment_id,
                    'status'              => \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID,
                    'provider_payment_id' => $payment_intent,
                    'raw_payload'         => $payload,
                    'event_transient_key' => $seen_key ?? null,
                ];

                if ( isset( $session['amount_total'] ) ) {
                    $result['gateway_amount_minor'] = (int) $session['amount_total'];
                }
                if ( isset( $session['currency'] ) ) {
                    $result['gateway_currency'] = strtoupper( $session['currency'] );
                }

                return $result;
            }
        }

        if ( $event['type'] === 'checkout.session.async_payment_failed' ) {
            $session    = $event['data']['object'] ?? [];
            $payment_id = (int) ( $session['metadata']['payment_id'] ?? 0 );
            if ( $payment_id ) {
                return [
                    'payment_id' => $payment_id,
                    'status'     => \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED,
                    'raw_payload' => $payload,
                    'event_transient_key' => $seen_key ?? null,
                ];
            }
        }

        return [ 'handled' => true, 'event_transient_key' => $seen_key ?? null ];
    }

    public function refund( int $payment_id, ?int $amount_minor = null ): array {
        $secret_key = \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::STRIPE_SECRET_KEY, '' ) );
        if ( ! $secret_key ) {
            return [ 'success' => false, 'message' => 'Stripe no está configurado.' ];
        }

        $payment = $this->payment_repo->find( $payment_id );
        if ( ! $payment || ! $payment->provider_payment_id ) {
            return [ 'success' => false, 'message' => 'Pago no encontrado.' ];
        }

        $body = [ 'payment_intent' => $payment->provider_payment_id ];
        if ( $amount_minor ) {
            $body['amount'] = $amount_minor;
        }

        $response = wp_remote_post( self::API_BASE . '/refunds', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => $body,
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $data['error'] ) ) {
            return [ 'success' => false, 'message' => $data['error']['message'] ?? 'Error de Stripe.' ];
        }

        $payment->status = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED;
        $this->payment_repo->update( $payment );

        return [ 'success' => true, 'refund_id' => $data['id'] ?? '' ];
    }

    private function verify_stripe_signature( string $payload, string $sig_header, string $secret ): bool {
        $parts = [];
        foreach ( explode( ',', $sig_header ) as $part ) {
            [ $k, $v ] = array_pad( explode( '=', $part, 2 ), 2, '' );
            $parts[ $k ] = $v;
        }

        $timestamp = $parts['t'] ?? '';
        $v1        = $parts['v1'] ?? '';

        if ( ! $timestamp || ! $v1 ) {
            return false;
        }

        // Reject events older than 5 minutes to prevent replay attacks.
        $tolerance = apply_filters( 'openbooking_stripe_webhook_tolerance_seconds', 300 );
        if ( abs( time() - (int) $timestamp ) > $tolerance ) {
            return false;
        }

        $signed_payload = $timestamp . '.' . $payload;
        $expected       = hash_hmac( 'sha256', $signed_payload, $secret );

        return hash_equals( $expected, $v1 );
    }
}
