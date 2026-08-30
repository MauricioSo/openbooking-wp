<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Integration\Service;

use OpenBooking\Domain\Shared\Port\SanitizerInterface;
use OpenBooking\Domain\Integration\Repository\IntegrationClientRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Integration_Authenticator {

    private const TIMESTAMP_TOLERANCE = 300;
    private const NONCE_WINDOW_SECONDS = 600;

    public function __construct(
        private IntegrationClientRepositoryInterface $client_repo,
        private ?SanitizerInterface $sanitizer = null,
    ) {
$this->sanitizer = $sanitizer ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Sanitizer();
    }

    public function authenticate( array $headers, string $raw_body, string $request_path, string $method ): array {
        $client_key    = $headers['x-ob-client-key'] ?? '';
        $timestamp     = $headers['x-ob-timestamp'] ?? '';
        $nonce         = $headers['x-ob-nonce'] ?? '';
        $signature     = $headers['x-ob-signature'] ?? '';

        if ( empty( $client_key ) || empty( $timestamp ) || empty( $nonce ) || empty( $signature ) ) {
            return [ 'valid' => false, 'error' => 'integration_auth_required', 'message' => 'Missing required authentication headers.' ];
        }

        $client = $this->client_repo->find_by_client_key( $this->sanitizer->text( $client_key ) );
        if ( ! $client ) {
            return [ 'valid' => false, 'error' => 'integration_signature_invalid', 'message' => 'Invalid client credentials.' ];
        }

        $ts = (int) $timestamp;
        $now = time();
        if ( abs( $now - $ts ) > self::TIMESTAMP_TOLERANCE ) {
            return [ 'valid' => false, 'error' => 'integration_signature_invalid', 'message' => 'Request timestamp outside allowed window.' ];
        }

        $nonce_transient_key = 'ob_int_nonce_' . md5( $client_key . '_' . $nonce );
        if ( function_exists( 'get_transient' ) && get_transient( $nonce_transient_key ) !== false ) {
            return [ 'valid' => false, 'error' => 'integration_signature_invalid', 'message' => 'Replay detected: nonce already used.' ];
        }

        $string_to_sign = strtoupper( $method ) . "\n" .
                           $request_path . "\n" .
                           $timestamp . "\n" .
                           $nonce . "\n" .
                           hash( 'sha256', $raw_body );

        $secret = $this->resolve_secret( $client );
        if ( empty( $secret ) ) {
            return [ 'valid' => false, 'error' => 'integration_signature_invalid', 'message' => 'Client secret not configured.' ];
        }

        $expected = 'sha256=' . hash_hmac( 'sha256', $string_to_sign, $secret );
        if ( ! hash_equals( $expected, $signature ) ) {
            return [ 'valid' => false, 'error' => 'integration_signature_invalid', 'message' => 'Signature verification failed.' ];
        }

        if ( function_exists( 'set_transient' ) ) {
            set_transient( $nonce_transient_key, '1', self::NONCE_WINDOW_SECONDS );
        }

        $this->client_repo->update_last_used( (int) $client['id'] );

        return [
            'valid'  => true,
            'client' => $client,
        ];
    }

    public function verify_scope( array $client, string $required_scope ): bool {
        $scopes = $this->client_repo->get_scopes( $client );
        if ( empty( $scopes ) ) {
            return false;
        }
        return in_array( $required_scope, $scopes, true ) || in_array( '*:*', $scopes, true );
    }

    public function verify_ip( array $client, string $ip ): bool {
        $allowed = $this->client_repo->get_allowed_ips( $client );
        if ( empty( $allowed ) ) {
            return true;
        }
        return in_array( $ip, $allowed, true );
    }

    public function build_signature( string $method, string $path, string $timestamp, string $nonce, string $raw_body, string $secret ): string {
        $string_to_sign = strtoupper( $method ) . "\n" .
                           $path . "\n" .
                           $timestamp . "\n" .
                           $nonce . "\n" .
                           hash( 'sha256', $raw_body );
        return 'sha256=' . hash_hmac( 'sha256', $string_to_sign, $secret );
    }

    private function resolve_secret( array $client ): string {
        if ( ! empty( $client['secret_hash'] ) ) {
            $key = defined( 'OBWP_INTEGRATION_HMAC_KEY' ) ? OBWP_INTEGRATION_HMAC_KEY : '';
            if ( empty( $key ) && function_exists( 'wp_salt' ) ) {
                $key = wp_salt( 'auth' );
            }
            if ( ! empty( $key ) ) {
                $encrypted = $client['secret_hash'];
                if ( function_exists( 'openssl_decrypt' ) && 0 === strpos( $encrypted, 'ENC:' ) ) {
                    $decrypted = $this->decrypt_secret( substr( $encrypted, 4 ), $key );
                    if ( $decrypted !== false ) {
                        return $decrypted;
                    }
                }
            }
        }
        return '';
    }

    private function decrypt_secret( string $payload, string $key ): ?string {
        $data = base64_decode( $payload, true );
        if ( $data === false || strlen( $data ) < 48 ) {
            return null;
        }
        $iv        = substr( $data, 0, 16 );
        $tag       = substr( $data, -16 );
        $encrypted = substr( $data, 16, -16 );
        $decrypted = openssl_decrypt( $encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
        return $decrypted !== false ? $decrypted : null;
    }

    public static function encrypt_secret_for_storage( string $plain_secret ): string {
        $key = defined( 'OBWP_INTEGRATION_HMAC_KEY' ) ? OBWP_INTEGRATION_HMAC_KEY : '';
        if ( empty( $key ) && function_exists( 'wp_salt' ) ) {
            $key = wp_salt( 'auth' );
        }
        if ( empty( $key ) || ! function_exists( 'openssl_encrypt' ) ) {
            if ( function_exists( 'wp_hash_password' ) ) {
                return wp_hash_password( $plain_secret );
            }
            return password_hash( $plain_secret, PASSWORD_DEFAULT );
        }
        $iv   = random_bytes( 16 );
        $tag  = '';
        $enc  = openssl_encrypt( $plain_secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
        if ( $enc === false ) {
            if ( function_exists( 'wp_hash_password' ) ) {
                return wp_hash_password( $plain_secret );
            }
            return password_hash( $plain_secret, PASSWORD_DEFAULT );
        }
        return 'ENC:' . base64_encode( $iv . $enc . $tag );
    }
}
