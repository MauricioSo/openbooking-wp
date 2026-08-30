<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Core;

use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Crea respuestas de error coherentes para controladores REST.
 */
class Rest_Error_Helper {

    private static function build( string $error_code, string $message, int $status, array $extra = [] ): \WP_REST_Response {
        $body = [
            'error'   => $error_code,
            'message' => $message,
            'code'    => $status,
        ];

        try {
            if ( class_exists( \OpenBooking\Support\Request_Context::class )
                && method_exists( \OpenBooking\Support\Request_Context::class, 'get_request_id' )
            ) {
                $request_id = \OpenBooking\Support\Request_Context::get_request_id();
                if ( $request_id !== '' ) {
                    $body['request_id'] = $request_id;
                }
            }
        } catch ( \Throwable $e ) {
            // Request_Context may not be available in unit tests.
        }

        if ( ! empty( $extra ) ) {
            $body = array_merge( $body, $extra );
        }

        return new \WP_REST_Response( $body, $status );
    }

    public static function validation_error( string $message, array $details = [], int $status = 400 ): \WP_REST_Response {
        $extra = [];
        if ( ! empty( $details ) ) {
            $extra['details'] = $details;
        }
        return self::build( 'validation_error', $message, $status, $extra );
    }

    public static function missing_field( string $field, int $status = 400 ): \WP_REST_Response {
        return self::build( 'missing_field', "El campo '{$field}' es obligatorio.", $status, [ 'field' => $field ] );
    }

    public static function invalid_field( string $field, string $reason, int $status = 400 ): \WP_REST_Response {
        return self::build( 'validation_error', "El campo '{$field}' no es valido: {$reason}", $status, [ 'field' => $field ] );
    }

    public static function forbidden_field( string $field, int $status = 400 ): \WP_REST_Response {
        return self::build( 'forbidden_field', "El campo '{$field}' no esta permitido.", $status, [ 'field' => $field ] );
    }

    public static function not_found( string $entity_type, ?string $message = null, int $status = 404 ): \WP_REST_Response {
        $label   = ucfirst( str_replace( '_', ' ', $entity_type ) );
        $message = $message ?? "{$label} no encontrado.";
        return self::build( "{$entity_type}_not_found", $message, $status );
    }

    public static function token_not_found( ?string $message = null, int $status = 404 ): \WP_REST_Response {
        $message = $message ?? 'Token invalido o reserva no encontrada.';
        return self::build( 'token_not_found', $message, $status );
    }

    public static function slot_unavailable( ?string $message = null, int $status = 409 ): \WP_REST_Response {
        $message = $message ?? 'El horario seleccionado ya no esta disponible.';
        return self::build( 'slot_unavailable', $message, $status );
    }

    public static function invalid_state_transition( string $from, string $to, int $status = 409 ): \WP_REST_Response {
        return self::build( 'invalid_state_transition', "Transicion de estado no valida: {$from} -> {$to}.", $status, [
            'from' => $from,
            'to'   => $to,
        ] );
    }

    public static function token_expired( int $status = 409 ): \WP_REST_Response {
        return self::build( 'token_expired', 'El token ha expirado.', $status );
    }

    public static function token_replay( int $status = 409 ): \WP_REST_Response {
        return self::build( 'token_replay', 'Este token ya fue utilizado.', $status );
    }

    public static function idempotency_conflict( int $status = 409 ): \WP_REST_Response {
        return self::build( 'idempotency_conflict', 'Conflicto de idempotencia: la solicitud ya fue procesada.', $status );
    }

    public static function rate_limit_exceeded( ?string $message = null, int $status = 429 ): \WP_REST_Response {
        $message = $message ?? 'Demasiadas solicitudes. Intenta de nuevo mas tarde.';
        return self::build( 'rate_limit_exceeded', $message, $status );
    }

    public static function unauthorized( string $error_code = Setting_Keys::REST_AUTH, ?string $message = null, int $status = 401 ): \WP_REST_Response {
        $message = $message ?? 'No autorizado.';
        return self::build( $error_code, $message, $status );
    }

    public static function forbidden( string $error_code = Setting_Keys::REST_FORBIDDEN, ?string $message = null, int $status = 403 ): \WP_REST_Response {
        $message = $message ?? 'Sin permisos.';
        return self::build( $error_code, $message, $status );
    }

    public static function booking_unprocessable( string $reason, int $status = 422 ): \WP_REST_Response {
        return self::build( 'booking_unprocessable', $reason, $status );
    }

    public static function internal_error( ?string $message = null, int $status = 500 ): \WP_REST_Response {
        $message = $message ?? 'Error interno del servidor.';
        return self::build( 'internal_error', $message, $status );
    }
}
