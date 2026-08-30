<?php


declare( strict_types=1 );
namespace OpenBooking\Presentation\Rest\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Encapsula la construccion de respuestas REST estandar del plugin.
 */

class API_Response {

    public static function success( $data = null, int $code = 200 ): \WP_REST_Response {
        $body = [ 'success' => true ];
        if ( null !== $data ) {
            $body['data'] = $data;
        }
        return new \WP_REST_Response( $body, $code );
    }

    public static function error(
        string $message,
        string $code = 'error',
        int $status = 400,
        array $details = []
    ): \WP_REST_Response {
        $body = [
            'success' => false,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];
        if ( ! empty( $details ) ) {
            $body['error']['details'] = $details;
        }
        return new \WP_REST_Response( $body, $status );
    }

    public static function validation_error( string $message, array $fields = [] ): \WP_REST_Response {
        return self::error( $message, 'validation_error', 422, [ 'fields' => $fields ] );
    }

    public static function not_found( string $message = 'Recurso no encontrado.' ): \WP_REST_Response {
        return self::error( $message, 'not_found', 404 );
    }

    public static function conflict( string $message ): \WP_REST_Response {
        return self::error( $message, 'conflict', 409 );
    }

    public static function unauthorized( string $message = 'No autorizado.' ): \WP_REST_Response {
        return self::error( $message, 'unauthorized', 401 );
    }

    public static function forbidden( string $message = 'Sin permisos.' ): \WP_REST_Response {
        return self::error( $message, 'forbidden', 403 );
    }

    public static function rate_limited( string $message = 'Demasiadas solicitudes.' ): \WP_REST_Response {
        $response = self::error( $message, 'rate_limited', 429 );
        $response->set_headers( [ 'Retry-After' => '60' ] );
        return $response;
    }

    public static function server_error( string $message = 'Error interno del servidor.' ): \WP_REST_Response {
        return self::error( $message, 'server_error', 500 );
    }
}
