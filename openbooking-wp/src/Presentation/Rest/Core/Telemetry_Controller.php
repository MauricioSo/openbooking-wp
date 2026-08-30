<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Core;

use OpenBooking\Domain\Shared\Port\RateLimiterInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Recibe eventos publicos de telemetria con limite de tasa.
 */
class Telemetry_Controller {

    private ?RateLimiterInterface $rate_limiter = null;

    public function __construct( ?RateLimiterInterface $rate_limiter = null ) {
        $this->rate_limiter = $rate_limiter;
    }

    private function check_public_rate_limit( string $action, int $max_attempts, int $ttl, string $message ): ?\WP_REST_Response {
        $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
        if ( '' === $ip ) {
            return null;
        }

        $limiter = $this->rate_limiter;
        if ( ! $limiter->check( $action, $ip, $max_attempts, $ttl ) ) {
            return Rest_Error_Helper::rate_limit_exceeded( $message );
        }

        return null;
    }

    public function public_telemetry_event( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );
        $event = sanitize_key( $body['event'] ?? '' );

        if ( '' === $event ) {
            return Rest_Error_Helper::validation_error( 'Evento invalido.' );
        }

        $rate_limited = $this->check_public_rate_limit( 'telemetry_event_' . $event, 200, HOUR_IN_SECONDS, 'Demasiados eventos.' );
        if ( $rate_limited ) {
            return $rate_limited;
        }

        \OpenBooking\Infrastructure\WordPress\Metrics\Request_Metrics::record_funnel_event(
            $event,
            is_array( $body['meta'] ?? null ) ? $body['meta'] : []
        );

        return new \WP_REST_Response( [ 'success' => true ], 202 );
    }

    /**
     * Decodifica el body JSON de forma segura.
     */
    private function decode_json_body( \WP_REST_Request $request ): array {
        $body = json_decode( $request->get_body(), true );

        return is_array( $body ) ? $body : [];
    }
}
