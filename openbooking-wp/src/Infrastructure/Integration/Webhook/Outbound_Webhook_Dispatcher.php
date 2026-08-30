<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Integration\Webhook;

use OpenBooking\Integration\Domain_Event;
use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Envía webhooks salientes firmados a endpoints configurados.
 *
 * Each endpoint registers a URL + shared secret. The dispatcher sends a
 * POST request with HMAC-SHA256 signature in the X-OpenBooking-Signature
 * header so receivers can verify authenticity without trusting IP.
 *
 * OpenBooking knows nothing about who is listening — endpoints are just URLs.
 */
class Outbound_Webhook_Dispatcher {

    private const OPTION_ENDPOINTS = Setting_Keys::WEBHOOK_ENDPOINTS;
    private const TIMEOUT_SECONDS  = 10;
    private const SIGNATURE_HEADER = 'X-OpenBooking-Signature';
    private const EVENT_HEADER     = 'X-OpenBooking-Event';
    private const VERSION_HEADER    = 'X-OpenBooking-Version';

    private string $last_error = '';

    /**
     * Dispatch a domain event to all registered endpoints that subscribe to it.
     */
    public function dispatch( Domain_Event $event ): bool {
        $endpoints = $this->get_endpoints();
        if ( empty( $endpoints ) ) {
            return true;
        }

        $this->last_error = '';
        $payload = $event->to_json();
        $all_sent = true;
        $had_target = false;

        foreach ( $endpoints as $endpoint ) {
            if ( ! $this->endpoint_subscribes_to( $endpoint, $event->get_event() ) ) {
                continue;
            }
            $had_target = true;
            $all_sent = $this->send( $endpoint, $payload, $event->get_event() ) && $all_sent;
        }

        if ( $had_target && ! $all_sent && '' === $this->last_error ) {
            $this->last_error = 'Webhook dispatch failed.';
        }

        return $all_sent;
    }

    /**
     * Ultimo error de dispatch (bloqueo SSRF, allowlist, HTTP o red), vacio si fue exitoso.
     */
    public function get_last_error(): string {
        return $this->last_error;
    }

    /**
     * Returns all configured webhook endpoints.
     * Each entry: [ 'url' => '', 'secret' => '', 'events' => ['booking.confirmed', ...] ]
     */
    public function get_endpoints(): array {
        $raw = get_option( self::OPTION_ENDPOINTS, [] );
        return is_array( $raw ) ? $raw : [];
    }

    public function save_endpoints( array $endpoints ): void {
        update_option( self::OPTION_ENDPOINTS, $endpoints );
    }

    private function endpoint_subscribes_to( array $endpoint, string $event ): bool {
        if ( empty( $endpoint['events'] ) || ! is_array( $endpoint['events'] ) ) {
            return true; // no filter = receive all events
        }
        return in_array( $event, $endpoint['events'], true )
            || in_array( '*', $endpoint['events'], true );
    }

    private function send( array $endpoint, string $payload, string $event_type ): bool {
        $url    = $endpoint['url'] ?? '';
        $secret = $endpoint['secret'] ?? '';

        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            $this->last_error = 'Webhook URL invalida: ' . $url;
            return false;
        }

        if ( $this->url_is_ssrf_risk( $url ) ) {
            $this->last_error = 'Webhook bloqueado (SSRF/host no resuelve): ' . $url;
            error_log( '[OpenBooking] Webhook blocked (SSRF): ' . $url );
            return false;
        }

        if ( ! $this->url_is_in_allowlist( $url ) ) {
            $this->last_error = 'Webhook bloqueado (dominio fuera de allowlist): ' . $url;
            error_log( '[OpenBooking] Webhook blocked (domain not allowlisted): ' . $url );
            return false;
        }

        $signature = $secret
            ? 'sha256=' . hash_hmac( 'sha256', $payload, $secret )
            : '';

        $args = [
            'method'    => 'POST',
            'timeout'   => self::TIMEOUT_SECONDS,
            'blocking'  => true,
            'headers'   => array_filter( [
                'Content-Type'            => 'application/json',
                self::EVENT_HEADER        => $event_type,
                self::VERSION_HEADER      => Domain_Event::VERSION,
                self::SIGNATURE_HEADER    => $signature,
            ] ),
            'body'      => $payload,
        ];

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->last_error = 'Webhook dispatch failed to ' . $url . ': ' . $response->get_error_message();
            error_log( '[OpenBooking] ' . $this->last_error );
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            $this->last_error = 'Webhook dispatch failed to ' . $url . ': HTTP ' . $status;
            error_log( '[OpenBooking] ' . $this->last_error );
            return false;
        }

        return true;
    }

    /**
     * Block URLs that resolve to private/loopback addresses (SSRF prevention).
     * Checks the hostname before the HTTP client opens a connection.
     */
    private function url_is_ssrf_risk( string $url ): bool {
        $host = (string) parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) {
            return true;
        }

        // Strip IPv6 brackets.
        $host = trim( $host, '[]' );

        // Resolve hostname to IP(s) — gethostbynamel returns false on failure.
        $ips = gethostbynamel( $host );
        if ( $ips === false ) {
            // Unresolvable host — treat as a risk to prevent DNS-based bypasses.
            return true;
        }

        foreach ( $ips as $ip ) {
            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
                return true; // private or reserved range
            }
        }

        return false;
    }

    /**
     * Checks whether the URL's domain is in the configured allowlist.
     *
     * If obwp_outbound_webhook_domain_allowlist is empty, all domains are allowed
     * (backward compatible). When populated, only domains in the list pass.
     *
     * The allowlist supports exact host matches and wildcard prefixes (e.g. *.example.com).
     */
    private function url_is_in_allowlist( string $url ): bool {
        $raw = get_option( Setting_Keys::OUTBOUND_WEBHOOK_DOMAIN_ALLOWLIST, '' );
        $allowlist = array_map( 'trim', explode( "\n", (string) $raw ) );
        $allowlist = array_filter( $allowlist, static fn( $entry ) => '' !== $entry && '#' !== $entry[0] );

        if ( empty( $allowlist ) ) {
            return true;
        }

        $host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
        if ( '' === $host ) {
            return false;
        }

        foreach ( $allowlist as $entry ) {
            $entry = strtolower( $entry );

            if ( $entry === $host ) {
                return true;
            }

            if ( str_starts_with( $entry, '*.' ) ) {
                $suffix = substr( $entry, 1 );
                if ( str_ends_with( $host, $suffix ) && strlen( $host ) > strlen( $suffix ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Verify an inbound request came from OpenBooking (for Dentbot's side).
     * Usage: Outbound_Webhook_Dispatcher::verify_signature($raw_body, $header_value, $secret)
     */
    public static function verify_signature( string $payload, string $signature_header, string $secret ): bool {
        if ( ! $secret || ! $signature_header ) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );
        return hash_equals( $expected, $signature_header );
    }
}
