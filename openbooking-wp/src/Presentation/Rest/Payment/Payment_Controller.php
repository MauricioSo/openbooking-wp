<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Payment;

use OpenBooking\Domain\Shared\Port\RateLimiterInterface;
use OpenBooking\Application\Payment\Service\Payment_Service;
use OpenBooking\Application\Payment\Service\Webhook_Security_Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone la creacion publica de pagos y el procesamiento de webhooks.
 */
class Payment_Controller {

    public function __construct(
        private Payment_Service $payment_service, // orquesta ciclo de pago
        private Webhook_Security_Service $security, // valida webhooks entrantes
        private ?RateLimiterInterface $rate_limiter = null,
    ) {
        $this->security = $security ?? new Webhook_Security_Service(
            \OpenBooking\Support\Container::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class )
        );
    }

    public function create_payment( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );
        if ( ! $body ) {
            $body = $request->get_params();
        }

        $result = $this->payment_service->create_checkout( $body );
        if ( ! empty( $result['error'] ) ) {
            return new \WP_REST_Response( [ 'error' => $result['error'] ], $result['code'] ?? 400 );
        }

        return new \WP_REST_Response( $result, 200 );
    }

    public function payment_webhook( \WP_REST_Request $request ): \WP_REST_Response {
        $gateway = sanitize_key( $request['gateway'] );
        $remote = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );

        if ( ! $this->security->is_webhook_ip_allowed( $gateway, $remote ) ) {
            return new \WP_REST_Response( [ 'error' => 'IP no autorizada para webhooks de ' . $gateway . '.' ], 403 );
        }

        $limiter = $this->rate_limiter;
        if ( $limiter && ! $limiter->check( 'webhook_' . $gateway, $remote, 300, MINUTE_IN_SECONDS ) ) {
            return new \WP_REST_Response( [ 'error' => 'Too many requests.' ], 429 );
        }

        $payload = $request->get_body();
        $headers = $request->get_headers();
        $result = $this->payment_service->handle_webhook( $gateway, $payload, $headers );
        $http_status = $result['http_status'] ?? 200;
        unset( $result['http_status'] );

        return new \WP_REST_Response( $result, $http_status );
    }

    /**
     * Decodifica el body JSON de forma segura.
     */
    private function decode_json_body( \WP_REST_Request $request ): array {
        $body = json_decode( $request->get_body(), true );

        return is_array( $body ) ? $body : [];
    }
}
