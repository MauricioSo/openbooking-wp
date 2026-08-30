<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Shared\Port\SanitizerInterface;
use OpenBooking\Domain\Shared\Port\SettingsInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Valida y normaliza la entrada de reservas.
 */
class Booking_Input_Validator {

    private ?SanitizerInterface $sanitizer = null;
    private ?SettingsInterface $settings = null;

    public function __construct( ?SanitizerInterface $sanitizer = null, ?SettingsInterface $settings = null ) {
        $this->sanitizer = $sanitizer ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Sanitizer();
        $this->settings   = $settings ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Settings();
    }

    private function sanitizer(): SanitizerInterface {
        if ( ! $this->sanitizer ) {
            $this->sanitizer = new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Sanitizer();
        }
        return $this->sanitizer;
    }

    private function settings(): SettingsInterface {
        if ( ! $this->settings ) {
            $this->settings = new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Settings();
        }
        return $this->settings;
    }

    public function validate( array $data, Booking_Request_Context $context ): array {
        $service_id  = $this->sanitizer()->absint( $data['service_id'] ?? 0 );
        $start_at    = $this->sanitizer()->text( $data['start_at'] ?? '' );
        $resource_id = ! empty( $data['resource_id'] ) ? $this->sanitizer()->absint( $data['resource_id'] ) : null;

        if ( ! $start_at ) {
            return $this->error( $this->translate( 'La fecha de inicio es obligatoria.' ), 400 );
        }

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start_at ) ) {
            return $this->error( $this->translate( 'Formato de fecha/hora inválido.' ), 400 );
        }

        $start_at_dt = $this->parse_business_datetime( $start_at );
        if ( ! $start_at_dt ) {
            return $this->error( $this->translate( 'Fecha/hora inválida para la zona horaria configurada.' ), 400 );
        }

        if ( $start_at_dt->getTimestamp() <= $this->get_business_now()->getTimestamp() ) {
            return $this->error( $this->translate( 'No se pueden crear reservas en el pasado.' ), 400 );
        }

        $email      = $this->sanitizer()->email( $data['email'] ?? '' );
        $first_name = $this->sanitizer()->text( $data['first_name'] ?? '' );
        $last_name  = $this->sanitizer()->text( $data['last_name'] ?? '' );
        $phone      = $this->sanitizer()->text( $data['phone'] ?? '' );
        $notes      = $this->sanitizer()->textarea( $data['notes'] ?? '' );
        $whatsapp   = isset( $data['whatsapp_opt_in'] ) ? (bool) $data['whatsapp_opt_in'] : null;

        if ( empty( $email ) || empty( $first_name ) ) {
            return $this->error( $this->translate( 'Nombre y correo son obligatorios.' ), 400 );
        }

        if ( ! $this->email_domain_is_reachable( $email ) ) {
            return $this->error( $this->translate( 'El correo electrónico no parece válido. Verifica que el dominio sea correcto.' ), 422 );
        }

        $client_ref_raw = $data['client_ref'] ?? ( $data['_client_ref'] ?? '' );
        $client_ref     = substr( $this->sanitizer()->text( $client_ref_raw ), 0, 64 );
        $price_check    = isset( $data['_price_check'] ) ? (int) $data['_price_check'] : null;
        $source         = $context->is_admin() ? 'admin' : $this->sanitizer()->text( $data['source'] ?? 'public' );

        $sanitized = [
            'service_id'      => $service_id,
            'start_at'        => $start_at,
            'start_at_dt'     => $start_at_dt,
            'resource_id'     => $resource_id,
            'email'           => $email,
            'first_name'      => $first_name,
            'last_name'       => $last_name,
            'phone'           => $phone,
            'notes'           => $notes,
            'whatsapp_opt_in' => $whatsapp,
            'client_ref'      => $client_ref,
            'price_check'     => $price_check,
            'source'          => $source,
        ];

        if ( $context->is_integration() ) {
            $meta = $data['_integration_meta'] ?? [];
            if ( is_array( $meta ) ) {
                $sanitized['_integration_meta'] = array_map( fn( $value ) => $this->sanitizer()->text( $value ), array_filter( $meta ) );
            }
        }

        return [ 'valid' => true, 'data' => $sanitized ];
    }

    private function error( string $message, int $code ): array {
        return [ 'valid' => false, 'error' => $message, 'code' => $code ];
    }

    private function translate( string $message ): string {
        return function_exists( '__' ) ? __( $message, 'openbooking-wp' ) : $message;
    }

    protected function get_business_timezone(): \DateTimeZone {
        $timezone = $this->settings()->get( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' );
        try {
            return new \DateTimeZone( $timezone );
        } catch ( \Exception $e ) {
            return new \DateTimeZone( 'UTC' );
        }
    }

    protected function get_business_now(): \DateTimeImmutable {
        return new \DateTimeImmutable( 'now', $this->get_business_timezone() );
    }

    protected function parse_business_datetime( string $datetime ): ?\DateTimeImmutable {
        $timezone = $this->get_business_timezone();
        $parsed   = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $datetime, $timezone );

        if ( ! $parsed ) {
            return null;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) {
            return null;
        }

        return $parsed;
    }

    protected function email_domain_is_reachable( string $email ): bool {
        if ( $this->settings()->get( Setting_Keys::SKIP_EMAIL_DNS_CHECK, false ) ) {
            return true;
        }

        $parts = explode( '@', $email, 2 );
        if ( count( $parts ) !== 2 || empty( $parts[1] ) ) {
            return false;
        }

        $domain = strtolower( trim( $parts[1] ) );

        $disposable = [
            'mailinator.com', 'guerrillamail.com', 'trashmail.com', 'tempmail.com',
            'throwaway.email', 'maildrop.cc', 'yopmail.com', 'sharklasers.com',
            'spam4.me', 'dispostable.com', '10minutemail.com', 'fakeinbox.com',
        ];
        if ( in_array( $domain, $disposable, true ) ) {
            return false;
        }

        return checkdnsrr( $domain, 'MX' ) || checkdnsrr( $domain, 'A' );
    }
}
