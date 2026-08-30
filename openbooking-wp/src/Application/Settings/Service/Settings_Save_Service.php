<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Settings\Service;

use OpenBooking\Support\Setting_Keys;
use OpenBooking\Support\Option_Keys;
use OpenBooking\Support\Cron_Hook_Keys;

use OpenBooking\Domain\Shared\Port\SettingsInterface;
use OpenBooking\Domain\Shared\Port\CronManagerInterface;
use OpenBooking\Domain\Shared\Port\ActorContextInterface;
use OpenBooking\Application\Audit\Service\Audit_Logger;
use OpenBooking\Application\Availability\Service\Availability_Service;
use OpenBooking\Support\Currency_Helper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings_Save_Service {

    public function __construct(
        private SettingsInterface $settings,
        private CronManagerInterface $cron_manager,
        private Audit_Logger $audit_logger,
        private Availability_Service $availability_service,
        private ActorContextInterface $actor_context,
    ) {}

    public function save_settings( array $body ): array {
        $before = $this->snapshot_settings();

        $this->guard_text_settings( $body );
        $this->apply_numeric_settings( $body );
        $this->guard_outbox_settings( $body );

        $after = $this->snapshot_settings();

        $this->audit_changes( $body, $before, $after );

        return [
            'success'         => true,
            'impact_warnings' => $this->collect_impact_warnings( $body, $before, $after ),
        ];
    }

    private function guard_text_settings( array $body ): void {
        $error = $this->apply_text_settings( $body );

        if ( null !== $error ) {
            throw new \RuntimeException( $error['error'], $error['code'] ?? 400 );
        }
    }

    private function guard_outbox_settings( array $body ): void {
        $error = $this->apply_outbox_settings( $body );

        if ( null !== $error ) {
            throw new \RuntimeException( $error['error'], $error['code'] ?? 400 );
        }
    }

    private function snapshot_settings(): array {
        return [
            'business_name'              => $this->settings->get( Setting_Keys::BUSINESS_NAME, '' ),
            'country'                    => $this->settings->get( Setting_Keys::BUSINESS_COUNTRY, '' ),
            'currency'                   => $this->settings->get( Setting_Keys::BUSINESS_CURRENCY, 'USD' ),
            'timezone'                   => $this->settings->get( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' ),
            'language'                   => $this->settings->get( Setting_Keys::BUSINESS_LANGUAGE, 'es' ),
            'email_sender_name'          => $this->settings->get( Setting_Keys::EMAIL_SENDER_NAME, '' ),
            'email_sender_address'       => $this->settings->get( Setting_Keys::EMAIL_SENDER_ADDRESS, '' ),
            'public_booking_page_url'    => $this->settings->get( Setting_Keys::PUBLIC_BOOKING_PAGE_URL, '' ),
            'uninstall_remove_data'      => $this->settings->get( Setting_Keys::UNINSTALL_REMOVE_DATA, false ),
            'cancel_min_hours'           => (int) $this->settings->get( Setting_Keys::CANCEL_MIN_HOURS, 0 ),
            'reschedule_min_hours'       => (int) $this->settings->get( Setting_Keys::RESCHEDULE_MIN_HOURS, 0 ),
            'free_booking_expiry_minutes'=> (int) $this->settings->get( Setting_Keys::FREE_BOOKING_EXPIRY, 5 ),
            'outbox_record_events'       => (bool) $this->settings->get( Setting_Keys::OUTBOX_RECORD_EVENTS, 0 ),
            'outbox_worker_enabled'      => (bool) $this->settings->get( Setting_Keys::OUTBOX_WORKER_ENABLED, 0 ),
            'async_outbound_webhooks'    => (bool) $this->settings->get( Setting_Keys::ASYNC_OUTBOUND_WEBHOOKS, 0 ),
            'outbox_processed_retention_days' => (int) $this->settings->get( Setting_Keys::OUTBOX_RETENTION_DAYS, 7 ),
        ];
    }

    private function apply_text_settings( array $body ): ?array {
        $allowed = [ 'business_name', 'country', 'currency', 'timezone', 'language', 'email_sender_name', 'email_sender_address', 'public_booking_page_url', 'uninstall_remove_data', 'privacy_policy_url', 'privacy_consent_required' ];

        $required = [
            'business_name'        => 'El nombre del negocio es obligatorio.',
            'email_sender_name'    => 'El nombre del remitente es obligatorio.',
            'email_sender_address' => 'La direccion de correo del remitente es obligatoria.',
        ];

        foreach ( $allowed as $key ) {
            if ( ! isset( $body[ $key ] ) ) {
                continue;
            }

            $option_key = $this->option_key_for( $key );

            if ( isset( $required[ $key ] ) && '' === sanitize_text_field( (string) $body[ $key ] ) ) {
                return [ 'error' => $required[ $key ], 'field' => $key, 'code' => 400 ];
            }

            if ( 'email_sender_address' === $key ) {
                $email = sanitize_email( (string) $body[ $key ] );
                if ( ! $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
                    return [ 'error' => 'La direccion de correo del remitente no es valida.', 'field' => $key, 'code' => 400 ];
                }

                $this->settings->set( $option_key, $email );
                continue;
            }

            if ( 'public_booking_page_url' === $key ) {
                $url = esc_url_raw( (string) $body[ $key ] );
                $validation = $this->validate_public_booking_url( $url );
                if ( true !== $validation ) {
                    return [ 'error' => $validation, 'field' => 'public_booking_page_url', 'code' => 400 ];
                }

                $this->settings->set( $option_key, $url );
                continue;
            }

            if ( 'currency' === $key ) {
                $currency = Currency_Helper::sanitize_supported_currency( $body[ $key ] );
                if ( null === $currency ) {
                    return [ 'error' => 'Moneda no soportada.', 'code' => 400 ];
                }

                $this->settings->set( $option_key, $currency );
                continue;
            }

            if ( 'timezone' === $key ) {
                $timezone = sanitize_text_field( (string) $body[ $key ] );
                if ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
                    return [ 'error' => 'Zona horaria no soportada.', 'code' => 400 ];
                }

                $this->settings->set( $option_key, $timezone );
                continue;
            }

            $this->settings->set( $option_key, sanitize_text_field( $body[ $key ] ) );
        }

        return null;
    }

    private function apply_numeric_settings( array $body ): void {
        $rules = [
            'cancel_min_hours'            => [ 'option' => Setting_Keys::CANCEL_MIN_HOURS, 'min' => 0 ],
            'reschedule_min_hours'        => [ 'option' => Setting_Keys::RESCHEDULE_MIN_HOURS, 'min' => 0 ],
            'booking_expiry_minutes'      => [ 'option' => Setting_Keys::BOOKING_EXPIRY_MINUTES, 'min' => 5 ],
            'free_booking_expiry_minutes'  => [ 'option' => Setting_Keys::FREE_BOOKING_EXPIRY, 'min' => 3 ],
            'reminder_hours_before'       => [ 'option' => Setting_Keys::REMINDER_HOURS_BEFORE, 'min' => 1 ],
            'notification_log_retention_days' => [ 'option' => Setting_Keys::NOTIFICATION_LOG_RETENTION, 'min' => 7 ],
            'audit_log_retention_days'    => [ 'option' => Setting_Keys::AUDIT_LOG_RETENTION, 'min' => 0 ],
            'outbox_processed_retention_days' => [ 'option' => Setting_Keys::OUTBOX_RETENTION_DAYS, 'min' => 1 ],
        ];

        foreach ( $rules as $field => $rule ) {
            if ( ! isset( $body[ $field ] ) ) {
                continue;
            }

            $value = max( $rule['min'], absint( $body[ $field ] ) );
            $this->settings->set( $rule['option'], $value );
        }
    }

    private function apply_outbox_settings( array $body ): ?array {
        $target_outbox_record_events = $this->resolve_outbox_flag( $body, 'outbox_record_events' );
        $target_outbox_worker_enabled = $this->resolve_outbox_flag( $body, 'outbox_worker_enabled' );
        $target_async_outbound_webhooks = $this->resolve_outbox_flag( $body, 'async_outbound_webhooks' );

        if ( $target_async_outbound_webhooks && ( ! $target_outbox_record_events || ! $target_outbox_worker_enabled ) ) {
            return [ 'error' => 'Para activar webhooks async primero debes activar outbox_record_events y outbox_worker_enabled.', 'code' => 400 ];
        }

        if ( isset( $body['outbox_record_events'] ) ) {
            $this->settings->set( Setting_Keys::OUTBOX_RECORD_EVENTS, $target_outbox_record_events ? 1 : 0 );
        }

        if ( isset( $body['outbox_worker_enabled'] ) ) {
            $this->settings->set( Setting_Keys::OUTBOX_WORKER_ENABLED, $target_outbox_worker_enabled ? 1 : 0 );
            $this->sync_outbox_worker_state( $target_outbox_worker_enabled );
        }

        if ( isset( $body['async_outbound_webhooks'] ) ) {
            $this->settings->set( Setting_Keys::ASYNC_OUTBOUND_WEBHOOKS, $target_async_outbound_webhooks ? 1 : 0 );
        }

        return null;
    }

    private function resolve_outbox_flag( array $body, string $field ): bool {
        if ( ! array_key_exists( $field, $body ) ) {
            $option = $this->option_key_for( $field );

            return (bool) $this->settings->get( $option, 0 );
        }

        return ! empty( $body[ $field ] );
    }

    private function sync_outbox_worker_state( bool $enabled ): void {
        if ( $enabled ) {
            $this->cron_manager->schedule_events();
            return;
        }

        wp_clear_scheduled_hook( Cron_Hook_Keys::PROCESS_OUTBOX );
    }

    private function option_key_for( string $field ): string {
        if ( in_array( $field, [ 'country', 'currency', 'timezone', 'language' ], true ) ) {
            return Setting_Keys::BUSINESS_PREFIX . $field;
        }

        // Los campos de privacidad persisten con la clave prefijada que lee el
        // frontend (Booking_Shortcode): sin este mapa se guardaban sin "obwp_"
        // y el consentimiento nunca se reflejaba en el wizard público.
        $const_map = [
            'business_name'            => Setting_Keys::BUSINESS_NAME,
            'email_sender_name'        => Setting_Keys::EMAIL_SENDER_NAME,
            'email_sender_address'     => Setting_Keys::EMAIL_SENDER_ADDRESS,
            'public_booking_page_url'  => Setting_Keys::PUBLIC_BOOKING_PAGE_URL,
            'uninstall_remove_data'    => Setting_Keys::UNINSTALL_REMOVE_DATA,
            'privacy_policy_url'       => Setting_Keys::PRIVACY_POLICY_URL,
            'privacy_consent_required' => Setting_Keys::PRIVACY_CONSENT_REQUIRED,
        ];
        if ( isset( $const_map[ $field ] ) ) {
            return $const_map[ $field ];
        }

        return Setting_Keys::extract_field_name( $field );
    }

    private function log_async_flag_changes( array $body, array $before, array $after ): void {
        $async_flags = [ 'outbox_record_events', 'outbox_worker_enabled', 'async_outbound_webhooks' ];
        $async_changes = [];

        foreach ( $async_flags as $flag ) {
            if ( ! isset( $body[ $flag ] ) ) {
                continue;
            }

            if ( (bool) $before[ $flag ] === (bool) $after[ $flag ] ) {
                continue;
            }

            $async_changes[ $flag ] = [ 'from' => (bool) $before[ $flag ], 'to' => (bool) $after[ $flag ] ];
        }

        if ( empty( $async_changes ) ) {
            return;
        }

        $this->audit_logger->log( [
            'entity_type' => 'settings',
            'entity_id'   => 0,
            'action'      => 'outbox_async_flags_changed',
            'actor_type'  => 'admin',
            'actor_id'    => $this->actor_context->get_current_user_id(),
            'message'     => 'Outbox async flags changed from admin.',
            'severity'    => 'warning',
            'context'     => $async_changes,
        ] );
    }

    private function audit_changes( array $body, array $before, array $after ): void {
        $this->log_async_flag_changes( $body, $before, $after );
        $this->log_general_changes( $before, $after );
    }

    private function log_general_changes( array $before, array $after ): void {
        $this->audit_logger->log_entity_change( 'settings', 0, 'settings_updated_general', $before, $after, [], [
            'message'        => 'General settings updated from admin.',
            'allowed_fields' => array_keys( $after ),
        ] );
    }

    private function collect_impact_warnings( array $body, array $before, array $after ): array {
        $warnings = [];

        $this->warn_if_timezone_changed( $body, $before, $after, $warnings );
        $this->warn_if_currency_changed( $body, $before, $after, $warnings );

        return $warnings;
    }

    private function warn_if_timezone_changed( array $body, array $before, array $after, array &$warnings ): void {
        if ( ! isset( $body['timezone'] ) ) {
            return;
        }

        if ( $before['timezone'] === $after['timezone'] ) {
            return;
        }

        $warnings[] = sprintf(
            'La zona horaria cambio. Los recordatorios del cron y la visualizacion de todos los slots ahora usaran %s.',
            $after['timezone']
        );

        $this->availability_service->invalidate_all_cache();
    }

    private function warn_if_currency_changed( array $body, array $before, array $after, array &$warnings ): void {
        if ( ! isset( $body['currency'] ) ) {
            return;
        }

        if ( $before['currency'] === $after['currency'] ) {
            return;
        }

        $warnings[] = sprintf(
            'La moneda cambio de %s a %s. Los precios de los servicios existentes no fueron convertidos.',
            $before['currency'],
            $after['currency']
        );
    }

    private function validate_public_booking_url( string $url ): bool|string {
        if ( '' === $url ) {
            return true;
        }

        $host      = wp_parse_url( $url, PHP_URL_HOST );
        $site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );

        if ( ! $host || ! $site_host ) {
            return 'URL no valida.';
        }

        if ( 0 !== strcasecmp( $host, $site_host ) ) {
            return 'La URL debe pertenecer a este sitio WordPress.';
        }

        return true;
    }

}
