<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Core;

use OpenBooking\Infrastructure\Integration\Webhook\Outbound_Webhook_Dispatcher;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone la lectura y guardado de endpoints de webhooks salientes.
 */
class Admin_Webhook_Controller {

    private ?Outbound_Webhook_Dispatcher $dispatcher = null;

    public function __construct( ?Outbound_Webhook_Dispatcher $dispatcher = null ) {
        $this->dispatcher = $dispatcher ?? new Outbound_Webhook_Dispatcher();
    }

    private function get_dispatcher(): Outbound_Webhook_Dispatcher {
        return $this->dispatcher;
    }

    public function admin_get_webhooks( \WP_REST_Request $request ): \WP_REST_Response {
        $dispatcher = $this->get_dispatcher();
        $endpoints  = $dispatcher->get_endpoints();

        $safe = array_map( fn( $ep ) => $this->sanitize_endpoint_for_admin( $ep ), $endpoints );

        return new \WP_REST_Response( [ 'endpoints' => $safe ], 200 );
    }

	public function admin_save_webhooks( \WP_REST_Request $request ): \WP_REST_Response {
		$body      = $this->decode_json_body( $request );
		$incoming  = $body['endpoints'] ?? [];
		$existing  = $this->get_dispatcher()->get_endpoints();
		$existing_by_url = [];
		foreach ( $existing as $endpoint ) {
			$url = esc_url_raw( $endpoint['url'] ?? '' );
			if ( $url ) {
				$existing_by_url[ $url ] = $endpoint;
			}
		}

		$sanitized = [];
		foreach ( $incoming as $i => $ep ) {
			$url = esc_url_raw( $ep['url'] ?? '' );
            if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                continue;
            }
            $events = array_filter(
                array_map( 'sanitize_text_field', (array) ( $ep['events'] ?? [] ) )
			);
			$secret = sanitize_text_field( $ep['secret'] ?? '' );
			if ( $secret === '........' ) {
				$secret = $existing_by_url[ $url ]['secret'] ?? '';
			}
            $sanitized[] = [
                'url'    => $url,
                'events' => array_values( $events ),
                'secret' => $secret,
            ];
        }

        $this->get_dispatcher()->save_endpoints( $sanitized );
        return new \WP_REST_Response( [ 'saved' => count( $sanitized ) ], 200 );
    }

    /**
     * Decodifica el body JSON de forma segura.
     */
    private function decode_json_body( \WP_REST_Request $request ): array {
        $body = json_decode( $request->get_body(), true );

        return is_array( $body ) ? $body : [];
    }

    /**
     * Oculta secretos antes de exponerlos al admin.
     */
    private function sanitize_endpoint_for_admin( array $endpoint ): array {
        return [
            'url'    => $endpoint['url'] ?? '',
            'events' => $endpoint['events'] ?? [],
            'secret' => ! empty( $endpoint['secret'] ) ? '........' : '',
        ];
    }
}
