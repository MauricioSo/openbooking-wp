<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Notification\WhatsApp;

use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sends WhatsApp messages via the Twilio Messaging API.
 *
 * Setup:
 *  1. Create a Twilio account at twilio.com.
 *  2. Enable the WhatsApp sandbox (for testing) or request a WhatsApp-approved sender.
 *  3. Fill in Account SID, Auth Token, and the sender number in
 *     OpenBooking > Ajustes > Notificaciones.
 *
 * The sender number must be whatsapp-enabled and in E.164 format (e.g. +14155238886).
 * For the sandbox the sender is always +14155238886.
 *
 * No external PHP libraries are required — all HTTP calls use wp_remote_post().
 */
class Twilio_WhatsApp_Provider implements WhatsApp_Provider_Interface {

    private string $account_sid;
    private string $auth_token;
    private string $from_number;

    private const API_BASE = 'https://api.twilio.com/2010-04-01/Accounts/';

    public function __construct() {
        $this->account_sid = (string) get_option( Setting_Keys::WHATSAPP_TWILIO_SID, '' );
        $this->auth_token  = \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::WHATSAPP_TWILIO_TOKEN, '' ) );
        $this->from_number = (string) get_option( Setting_Keys::WHATSAPP_TWILIO_FROM, '' );
    }

    public function get_name(): string {
        return 'twilio';
    }

    public function is_configured(): bool {
        return '' !== $this->account_sid
            && '' !== $this->auth_token
            && '' !== $this->from_number;
    }

    public function send( string $to, string $message, array $context = [] ): bool {
        if ( ! $this->is_configured() ) {
            error_log( '[OpenBooking] Twilio WhatsApp: credenciales no configuradas.' );
            return false;
        }

        $to = $this->normalize_phone( $to );
        if ( ! $to ) {
            error_log( sprintf( '[OpenBooking] Twilio WhatsApp: número de destino inválido "%s".', $to ) );
            return false;
        }

        $url = self::API_BASE . $this->account_sid . '/Messages.json';

        $response = wp_remote_post( $url, [
            'timeout'  => 15,
            'headers'  => [
                'Authorization' => 'Basic ' . base64_encode( $this->account_sid . ':' . $this->auth_token ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'From' => 'whatsapp:' . $this->from_number,
                'To'   => 'whatsapp:' . $to,
                'Body' => $message,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( sprintf(
                '[OpenBooking] Twilio WhatsApp error: %s (booking_id=%s)',
                $response->get_error_message(),
                $context['booking_id'] ?? 'n/a'
            ) );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );

        // Twilio returns 201 Created on success.
        if ( $code < 200 || $code >= 300 ) {
            $raw = wp_remote_retrieve_body( $response );
            error_log( sprintf(
                '[OpenBooking] Twilio WhatsApp HTTP %d: %s (booking_id=%s)',
                $code,
                $raw,
                $context['booking_id'] ?? 'n/a'
            ) );
            return false;
        }

        return true;
    }

    /**
     * Ensure the phone is in E.164 format (+country_code digits).
     * Strips spaces, dashes, parentheses.
     * Returns empty string if the result does not look like a valid E.164 number.
     */
    private function normalize_phone( string $phone ): string {
        $phone = preg_replace( '/[\s\-\(\)\.]+/', '', $phone );

        // Already E.164.
        if ( preg_match( '/^\+\d{7,15}$/', $phone ) ) {
            return $phone;
        }

        // Prepend + if missing.
        if ( preg_match( '/^\d{7,15}$/', $phone ) ) {
            return '+' . $phone;
        }

        return '';
    }
}
