<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Notification\Service;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Shared\Port\SettingsInterface;
use OpenBooking\Domain\Notification\Service\EmailServiceInterface;
use OpenBooking\Domain\Notification\Service\WhatsAppServiceInterface;
use OpenBooking\Domain\Notification\Service\SMSServiceInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Notification_Test_Service {

    public function __construct(
        private EmailServiceInterface $email_service,
        private WhatsAppServiceInterface $whatsapp_service,
        private SMSServiceInterface $sms_service,
        private SettingsInterface $settings,
    ) {}

    public function test_email( string $to, string $subject, string $message ): array {
        if ( ! $to || ! is_email( $to ) ) {
            return [ 'success' => false, 'error' => 'Dirección de email no válida.', 'status' => 400 ];
        }

        $sender_name  = $this->settings->get( Setting_Keys::EMAIL_SENDER_NAME, get_bloginfo( 'name' ) );
        $sender_email = $this->settings->get( Setting_Keys::EMAIL_SENDER_ADDRESS, get_bloginfo( 'admin_email' ) );

        $headers = [
            'From: ' . $sender_name . ' <' . $sender_email . '>',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        $phpmailer_error = '';
        $error_handler = function( $wp_error ) use ( &$phpmailer_error ) {
            if ( is_wp_error( $wp_error ) ) {
                $phpmailer_error = $wp_error->get_error_message();
            }
        };
        add_action( 'wp_mail_failed', $error_handler );

        $start = microtime( true );
        $sent  = wp_mail( $to, $subject, $message, $headers );
        $ms    = round( ( microtime( true ) - $start ) * 1000 );

        remove_action( 'wp_mail_failed', $error_handler );

        if ( ! $sent ) {
            return [
                'success'        => false,
                'error'          => 'No se pudo enviar el email. Verifica la configuración SMTP de WordPress.',
                'provider_error' => $phpmailer_error ?: 'WordPress no pudo enviar el email.',
                'sender_used'    => $sender_name . ' <' . $sender_email . '>',
                'duration_ms'    => $ms,
                'status'         => 400,
            ];
        }

        return [
            'success'     => true,
            'message'     => 'Email enviado a ' . $to,
            'sender_used' => $sender_name . ' <' . $sender_email . '>',
            'duration_ms' => $ms,
            'status'      => 200,
        ];
    }

    public function test_whatsapp( string $to, string $message ): array {
        if ( '' === $to ) {
            return [ 'success' => false, 'error' => 'Debes indicar un número de destino.', 'status' => 400 ];
        }

        if ( ! $this->whatsapp_service->is_enabled() ) {
            return [ 'success' => false, 'error' => 'WhatsApp no está habilitado en ajustes.', 'status' => 400 ];
        }

        $provider = $this->whatsapp_service->resolve_provider();
        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'El proveedor de WhatsApp no está configurado o las credenciales son incorrectas.', 'status' => 400 ];
        }

        $provider_name = $this->settings->get( Setting_Keys::WHATSAPP_PROVIDER, 'twilio' );
        $start = microtime( true );
        $sent  = $provider->send( $to, $message, [ 'test' => true ] );
        $ms    = round( ( microtime( true ) - $start ) * 1000 );

        if ( ! $sent ) {
            return [
                'success'     => false,
                'error'       => 'No se pudo enviar el mensaje. Revisa las credenciales y los logs de WordPress.',
                'provider'    => $provider_name,
                'duration_ms' => $ms,
                'diagnostics' => [
                    'provider_configured' => true,
                    'provider_name'       => $provider_name,
                    'from_configured'     => 'twilio' === $provider_name
                        ? (bool) $this->settings->get( Setting_Keys::WHATSAPP_TWILIO_FROM, '' )
                        : (bool) $this->settings->get( Setting_Keys::WHATSAPP_META_PHONE_ID, '' ),
                ],
                'status' => 400,
            ];
        }

        return [
            'success'     => true,
            'message'     => 'Mensaje enviado a ' . $to,
            'provider'    => $provider_name,
            'duration_ms' => $ms,
            'status'      => 200,
        ];
    }

    public function test_sms( string $to, string $message ): array {
        if ( '' === $to ) {
            return [ 'success' => false, 'error' => 'Debes indicar un numero de destino.', 'status' => 400 ];
        }

        if ( ! $this->sms_service->is_enabled() ) {
            return [ 'success' => false, 'error' => 'SMS no esta habilitado en ajustes.', 'status' => 400 ];
        }

        $provider = $this->sms_service->resolve_provider();
        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'El proveedor de SMS no esta configurado o las credenciales son incorrectas.', 'status' => 400 ];
        }

        $start = microtime( true );
        $sent  = $provider->send( $to, $message, [ 'test' => true ] );
        $ms    = round( ( microtime( true ) - $start ) * 1000 );

        if ( ! $sent ) {
            return [
                'success'     => false,
                'error'       => 'No se pudo enviar el SMS. Revisa las credenciales y los logs de WordPress.',
                'duration_ms' => $ms,
                'status'      => 400,
            ];
        }

        return [
            'success'     => true,
            'message'     => 'SMS enviado a ' . $to,
            'duration_ms' => $ms,
            'status'      => 200,
        ];
    }
}
