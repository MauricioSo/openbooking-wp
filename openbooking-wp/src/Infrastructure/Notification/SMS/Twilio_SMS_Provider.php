<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Notification\SMS;

use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Implementa un proveedor externo de servicios de notificaciones.
 */

class Twilio_SMS_Provider implements SMS_Provider_Interface {

    private string $account_sid;
    private string $auth_token;
    private string $from_number;

    private const API_BASE = 'https://api.twilio.com/2010-04-01/Accounts/';

    public function __construct() {
        $this->account_sid = (string) get_option( Setting_Keys::SMS_TWILIO_SID, '' );
        $this->auth_token  = \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::SMS_TWILIO_TOKEN, '' ) );
        $this->from_number = (string) get_option( Setting_Keys::SMS_TWILIO_FROM, '' );
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
            error_log( '[OpenBooking] Twilio SMS: credenciales no configuradas.' );
            return false;
        }

        $to = $this->normalize_phone( $to );
        if ( ! $to ) {
            error_log( sprintf( '[OpenBooking] Twilio SMS: numero de destino invalido "%s".', $context['raw_to'] ?? $to ) );
            return false;
        }

        $url = self::API_BASE . $this->account_sid . '/Messages.json';

        $response = \wp_remote_post( $url, [
            'timeout'  => 15,
            'headers'  => [
                'Authorization' => 'Basic ' . base64_encode( $this->account_sid . ':' . $this->auth_token ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'From' => $this->from_number,
                'To'   => $to,
                'Body' => $message,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( sprintf(
                '[OpenBooking] Twilio SMS error: %s (booking_id=%s)',
                $response->get_error_message(),
                $context['booking_id'] ?? 'n/a'
            ) );
            return false;
        }

        $code = \wp_remote_retrieve_response_code( $response );

        if ( $code < 200 || $code >= 300 ) {
            $raw = \wp_remote_retrieve_body( $response );
            error_log( sprintf(
                '[OpenBooking] Twilio SMS HTTP %d: %s (booking_id=%s)',
                $code,
                $raw,
                $context['booking_id'] ?? 'n/a'
            ) );
            return false;
        }

        return true;
    }

    private function normalize_phone( string $phone ): string {
        $phone = preg_replace( '/[\s\-\(\)\.]+/', '', $phone );

        if ( preg_match( '/^\+\d{7,15}$/', $phone ) ) {
            return $phone;
        }

        if ( preg_match( '/^\d{7,15}$/', $phone ) ) {
            return '+' . $phone;
        }

        return '';
    }
}
