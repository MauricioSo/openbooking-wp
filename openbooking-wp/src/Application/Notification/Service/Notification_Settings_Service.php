<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Notification\Service;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Shared\Port\SettingsInterface;
use OpenBooking\Application\Audit\Service\Audit_Logger;
use OpenBooking\Support\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Notification_Settings_Service {

    public function __construct(
        private SettingsInterface $settings,
        private Audit_Logger $audit_logger,
    ) {}

    public function get_settings(): array {
        return [
            'email_html_enabled'    => (bool) $this->settings->get( Setting_Keys::EMAIL_HTML_ENABLED, false ),
            'email_accent_color'    => $this->settings->get( Setting_Keys::EMAIL_ACCENT_COLOR, '#2563eb' ),
            'whatsapp_enabled'      => (bool) $this->settings->get( Setting_Keys::WHATSAPP_ENABLED, false ),
            'whatsapp_provider'     => $this->settings->get( Setting_Keys::WHATSAPP_PROVIDER, 'twilio' ),
            'whatsapp_notify_admin' => (bool) $this->settings->get( Setting_Keys::WHATSAPP_NOTIFY_ADMIN, false ),
            'whatsapp_admin_phone'  => $this->settings->get( Setting_Keys::WHATSAPP_ADMIN_PHONE, '' ),
            'whatsapp_twilio_sid'   => $this->settings->get( Setting_Keys::WHATSAPP_TWILIO_SID, '' ),
            'whatsapp_twilio_token' => '' !== $this->settings->get( Setting_Keys::WHATSAPP_TWILIO_TOKEN, '' ) ? '••••••••' : '',
            'whatsapp_twilio_from'  => $this->settings->get( Setting_Keys::WHATSAPP_TWILIO_FROM, '' ),
            'whatsapp_meta_token'         => '' !== $this->settings->get( Setting_Keys::WHATSAPP_META_TOKEN, '' ) ? '••••••••' : '',
            'whatsapp_meta_phone_id'      => $this->settings->get( Setting_Keys::WHATSAPP_META_PHONE_ID, '' ),
            'whatsapp_meta_use_templates' => (bool) $this->settings->get( Setting_Keys::WHATSAPP_META_USE_TEMPLATES, false ),
            'whatsapp_meta_language'      => $this->settings->get( Setting_Keys::WHATSAPP_META_LANGUAGE, 'es' ),
            'whatsapp_meta_tpl_booking_confirmed'   => $this->settings->get( Setting_Keys::WHATSAPP_META_TPL_PREFIX . 'booking_confirmed', '' ),
            'whatsapp_meta_tpl_booking_cancelled'   => $this->settings->get( Setting_Keys::WHATSAPP_META_TPL_PREFIX . 'booking_cancelled', '' ),
            'whatsapp_meta_tpl_booking_rescheduled' => $this->settings->get( Setting_Keys::WHATSAPP_META_TPL_PREFIX . 'booking_rescheduled', '' ),
            'whatsapp_meta_tpl_reminder_customer'   => $this->settings->get( Setting_Keys::WHATSAPP_META_TPL_PREFIX . 'reminder_customer', '' ),
            'whatsapp_meta_tpl_new_booking_admin'   => $this->settings->get( Setting_Keys::WHATSAPP_META_TPL_PREFIX . 'new_booking_admin', '' ),
            'whatsapp_meta_is_configured' => (
                '' !== $this->settings->get( Setting_Keys::WHATSAPP_META_TOKEN, '' ) &&
                '' !== $this->settings->get( Setting_Keys::WHATSAPP_META_PHONE_ID, '' )
            ),
            'whatsapp_meta_needs_templates_warning' => (
                $this->settings->get( Setting_Keys::WHATSAPP_PROVIDER, 'twilio' ) === 'meta' &&
                (bool) $this->settings->get( Setting_Keys::WHATSAPP_ENABLED, false ) &&
                ! (bool) $this->settings->get( Setting_Keys::WHATSAPP_META_USE_TEMPLATES, false )
            ),
            'sms_enabled'          => (bool) $this->settings->get( Setting_Keys::SMS_ENABLED, false ),
            'sms_provider'         => $this->settings->get( Setting_Keys::SMS_PROVIDER, 'twilio' ),
            'sms_twilio_sid'       => $this->settings->get( Setting_Keys::SMS_TWILIO_SID, '' ),
            'sms_twilio_token'     => '' !== $this->settings->get( Setting_Keys::SMS_TWILIO_TOKEN, '' ) ? '••••••••' : '',
            'sms_twilio_from'      => $this->settings->get( Setting_Keys::SMS_TWILIO_FROM, '' ),
        ];
    }

    public function save_settings( array $body ): void {
        if ( isset( $body['email_html_enabled'] ) ) {
            $this->settings->set( Setting_Keys::EMAIL_HTML_ENABLED, (bool) $body['email_html_enabled'] );
        }
        if ( isset( $body['email_accent_color'] ) ) {
            $color = sanitize_hex_color( $body['email_accent_color'] );
            if ( $color ) {
                $this->settings->set( Setting_Keys::EMAIL_ACCENT_COLOR, $color );
            }
        }
        if ( isset( $body['whatsapp_enabled'] ) ) {
            $this->settings->set( Setting_Keys::WHATSAPP_ENABLED, (bool) $body['whatsapp_enabled'] );
        }
        if ( isset( $body['whatsapp_provider'] ) ) {
            $provider = sanitize_text_field( $body['whatsapp_provider'] );
            if ( in_array( $provider, [ 'twilio', 'meta' ], true ) ) {
                $this->settings->set( Setting_Keys::WHATSAPP_PROVIDER, $provider );
            }
        }
        if ( isset( $body['whatsapp_notify_admin'] ) ) {
            $this->settings->set( Setting_Keys::WHATSAPP_NOTIFY_ADMIN, (bool) $body['whatsapp_notify_admin'] );
        }
        if ( isset( $body['whatsapp_admin_phone'] ) ) {
            $this->settings->set( Setting_Keys::WHATSAPP_ADMIN_PHONE, sanitize_text_field( $body['whatsapp_admin_phone'] ) );
        }
        if ( isset( $body['whatsapp_twilio_sid'] ) ) {
            $this->settings->set( Setting_Keys::WHATSAPP_TWILIO_SID, sanitize_text_field( $body['whatsapp_twilio_sid'] ) );
        }
        if ( isset( $body['whatsapp_twilio_token'] ) && strpos( $body['whatsapp_twilio_token'], '•' ) === false ) {
            $this->settings->set( Setting_Keys::WHATSAPP_TWILIO_TOKEN, Crypto::encrypt( sanitize_text_field( $body['whatsapp_twilio_token'] ) ) );
        }
        if ( isset( $body['whatsapp_twilio_from'] ) ) {
            $this->settings->set( Setting_Keys::WHATSAPP_TWILIO_FROM, sanitize_text_field( $body['whatsapp_twilio_from'] ) );
        }
        if ( isset( $body['whatsapp_meta_token'] ) && strpos( $body['whatsapp_meta_token'], '•' ) === false ) {
            $this->settings->set( Setting_Keys::WHATSAPP_META_TOKEN, Crypto::encrypt( sanitize_text_field( $body['whatsapp_meta_token'] ) ) );
        }
        if ( isset( $body['whatsapp_meta_phone_id'] ) ) {
            $this->settings->set( Setting_Keys::WHATSAPP_META_PHONE_ID, sanitize_text_field( $body['whatsapp_meta_phone_id'] ) );
        }
        if ( isset( $body['whatsapp_meta_use_templates'] ) ) {
            $this->settings->set( Setting_Keys::WHATSAPP_META_USE_TEMPLATES, (bool) $body['whatsapp_meta_use_templates'] );
        }
        if ( isset( $body['whatsapp_meta_language'] ) ) {
            $this->settings->set( Setting_Keys::WHATSAPP_META_LANGUAGE, sanitize_text_field( $body['whatsapp_meta_language'] ) );
        }
        $valid_events = [ 'booking_confirmed', 'booking_cancelled', 'booking_rescheduled', 'reminder_customer', 'new_booking_admin' ];
        foreach ( $valid_events as $event ) {
            $field = 'whatsapp_meta_tpl_' . $event;
            if ( isset( $body[ $field ] ) ) {
                $this->settings->set( Setting_Keys::WHATSAPP_META_TPL_PREFIX . $event, sanitize_text_field( $body[ $field ] ) );
            }
        }

        if ( isset( $body['sms_enabled'] ) ) {
            $this->settings->set( Setting_Keys::SMS_ENABLED, (bool) $body['sms_enabled'] );
        }
        if ( isset( $body['sms_twilio_sid'] ) ) {
            $this->settings->set( Setting_Keys::SMS_TWILIO_SID, sanitize_text_field( $body['sms_twilio_sid'] ) );
        }
        if ( isset( $body['sms_twilio_token'] ) && strpos( $body['sms_twilio_token'], '•' ) === false ) {
            $this->settings->set( Setting_Keys::SMS_TWILIO_TOKEN, Crypto::encrypt( sanitize_text_field( $body['sms_twilio_token'] ) ) );
        }
        if ( isset( $body['sms_twilio_from'] ) ) {
            $this->settings->set( Setting_Keys::SMS_TWILIO_FROM, sanitize_text_field( $body['sms_twilio_from'] ) );
        }

        $this->audit_logger->log_entity_change( 'settings', 0, 'settings_updated_notifications', [], [], [], [
            'message' => 'Notification settings updated from admin.',
        ] );
    }
}
