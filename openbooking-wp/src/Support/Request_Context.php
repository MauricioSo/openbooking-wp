<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Conserva y enruta el contexto de la request para logs y auditoria.
 */
class Request_Context {

    private static ?string $request_id = null;
    private static string $route = '';
    private static string $http_method = '';
    private static string $source = 'system';

    /**
     * Sensitive field names that must be redacted in structured logs.
     * Any key matching these patterns will have its value replaced with '[REDACTED]'.
     */
    private const SENSITIVE_FIELDS = [
        'password', 'secret', 'token', 'key', 'api_key', 'access_token',
        'webhook_secret', 'publishable_key', 'raw_payload', 'email',
        'phone', 'card', 'cvv', 'stripe_secret', 'mp_access',
    ];

    public static function bootstrap(): void {
        if ( null === self::$request_id ) {
            self::$request_id = self::generate_request_id();
        }

        if ( empty( self::$http_method ) ) {
            self::$http_method = sanitize_text_field( $_SERVER['REQUEST_METHOD'] ?? '' );
        }

        if ( empty( self::$source ) || self::$source === 'system' ) {
            self::$source = is_admin() ? 'admin' : 'public';
        }
    }

    public static function reset(): void {
        self::$request_id  = null;
        self::$route       = '';
        self::$http_method = '';
        self::$source      = 'system';
    }

    public static function set_rest_request( string $route, string $method, string $source = 'admin' ): void {
        self::bootstrap();
        self::$route       = sanitize_text_field( $route );
        self::$http_method = sanitize_text_field( strtoupper( $method ) );
        self::$source      = sanitize_text_field( $source );
    }

    public static function get_request_id(): string {
        self::bootstrap();
        return (string) self::$request_id;
    }

    public static function get_route(): string {
        self::bootstrap();
        return self::$route;
    }

    public static function get_http_method(): string {
        self::bootstrap();
        return self::$http_method;
    }

    public static function get_source(): string {
        self::bootstrap();
        return self::$source;
    }

    public static function get_ip_address(): ?string {
        $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
        if ( '' === $ip ) {
            return null;
        }
        return self::anonymize_ip( $ip );
    }

    private static function anonymize_ip( string $ip ): string {
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $parts = explode( '.', $ip );
            $parts[3] = '0';
            return implode( '.', $parts );
        }
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            $packed = inet_pton( $ip );
            if ( $packed !== false ) {
                for ( $i = 6; $i < 16; $i++ ) {
                    $packed[ $i ] = "\x00";
                }
                $anonymized = inet_ntop( $packed );
                if ( $anonymized !== false ) {
                    return $anonymized;
                }
            }
        }
        return $ip;
    }

    public static function get_user_agent(): ?string {
        $ua = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
        return '' !== $ua ? $ua : null;
    }

    /**
     * Redact sensitive fields from a context array before it is logged or audited.
     * Processes arrays recursively.
     *
     * @param array $context Arbitrary context payload.
     * @return array         Context with sensitive values replaced by '[REDACTED]'.
     */
    public static function redact( array $context ): array {
        $redacted = [];
        foreach ( $context as $key => $value ) {
            $lower = strtolower( (string) $key );
            $is_sensitive = false;
            foreach ( self::SENSITIVE_FIELDS as $field ) {
                if ( false !== strpos( $lower, $field ) ) {
                    $is_sensitive = true;
                    break;
                }
            }

            if ( $is_sensitive ) {
                $redacted[ $key ] = '[REDACTED]';
            } elseif ( is_array( $value ) ) {
                $redacted[ $key ] = self::redact( $value );
            } else {
                $redacted[ $key ] = $value;
            }
        }
        return $redacted;
    }

    /**
     * Build a structured log entry enriched with request context.
     * All entries carry: request_id, source, route, http_method, ip_address.
     *
     * @param array $entry Raw log entry (entity_type, action, message, context, …).
     * @return array       Entry enriched with context fields.
     */
    public static function enrich_log_entry( array $entry ): array {
        self::bootstrap();

        if ( ! isset( $entry['request_id'] ) || '' === (string) $entry['request_id'] ) {
            $entry['request_id'] = self::$request_id;
        }
        if ( ! isset( $entry['source'] ) || '' === (string) $entry['source'] ) {
            $entry['source'] = self::$source;
        }
        if ( ! isset( $entry['route'] ) ) {
            $entry['route'] = self::$route;
        }
        if ( ! isset( $entry['http_method'] ) ) {
            $entry['http_method'] = self::$http_method;
        }
        if ( ! isset( $entry['ip_address'] ) ) {
            $entry['ip_address'] = self::get_ip_address();
        }

        // Redact sensitive fields from context before storage.
        if ( ! empty( $entry['context'] ) && is_array( $entry['context'] ) ) {
            $entry['context'] = self::redact( $entry['context'] );
        }

        return $entry;
    }

    private static function generate_request_id(): string {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return 'obwp_' . wp_generate_uuid4();
        }

        return 'obwp_' . uniqid( '', true );
    }
}
