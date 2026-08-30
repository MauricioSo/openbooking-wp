<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Settings;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Support\Color_Contrast;
use OpenBooking\Support\Currency_Helper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone y guarda configuracion administrativa del plugin.
 */
class Admin_Settings_Controller {


    public function __construct(
        private \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface $service_repo, // consulta servicios del catalogo
        private \OpenBooking\Domain\Availability\Repository\AvailabilityConfigRepositoryInterface $availability_repo, // persiste reglas de disponibilidad
        private \OpenBooking\Application\Audit\Service\Audit_Logger $audit_logger, // deja trazabilidad de cambios
        private \OpenBooking\Application\Settings\Service\Onboarding_Preset_Service $onboarding_preset_service, // presets de configuracion regional
        private \OpenBooking\Application\Core\Service\Integrity_Check_Service $integrity_check_service,
        private \OpenBooking\Domain\Shared\Port\CronManagerInterface $cron_manager, // programa tareas periodicas
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo, // consulta y persiste reservas
        private \OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface $queue_repo, // gestiona cola de notificaciones
        private \OpenBooking\Domain\Booking\Repository\PublicFormFieldRepositoryInterface $form_field_repo, // campos del formulario publico
        private \OpenBooking\Application\Core\Service\Feature_Flag_Service $feature_flags,
        private \OpenBooking\Application\Availability\Service\Availability_Service $availability_service, // calcula disponibilidad de slots
        private \OpenBooking\Application\Settings\Service\Settings_Save_Service $settings_save_service, // guarda configuracion del plugin
        private \OpenBooking\Application\Settings\Service\Onboarding_Service $onboarding_service, // gestiona asistente inicial
    ) {}

    public function register_routes( string $namespace, $permission_callback ): void {
        register_rest_route( $namespace, '/admin/settings', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'admin_get_settings' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/settings', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_save_settings' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/settings/design', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_save_design' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/form-fields', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'admin_get_form_fields' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/form-fields', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_save_form_fields' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/onboarding', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_onboarding' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/onboarding/presets', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'admin_get_onboarding_presets' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/onboarding/readiness', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'admin_get_readiness_checklist' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/onboarding/detect', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'admin_detect_context' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/feature-flags', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'admin_get_feature_flags' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/feature-flags/(?P<key>[a-zA-Z0-9_]+)', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_set_feature_flag' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/integrity-check', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'admin_integrity_check' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/reconcile', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_manual_reconcile' ],
            'permission_callback' => $permission_callback,
        ] );

        register_rest_route( $namespace, '/admin/dashboard', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'admin_dashboard_data' ],
            'permission_callback' => $permission_callback,
        ] );
    }

    public function admin_save_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );

        try {
            $result = $this->settings_save_service->save_settings( $body );
        } catch ( \RuntimeException $e ) {
            return $this->error_response( $e->getMessage(), $e->getCode() > 0 ? $e->getCode() : 400 );
        }

        if ( ! empty( $result['error'] ) ) {
            return $this->error_response( $result['error'], $result['code'] ?? 400, $result['field'] ?? null );
        }

        return $this->success_response( [
            'success'         => $result['success'],
            'impact_warnings' => $result['impact_warnings'] ?? [],
        ] );
    }

    public function admin_save_design( \WP_REST_Request $request ): \WP_REST_Response {
        $body   = $this->decode_json_body( $request );
        $before = $this->current_ui_config();
        $config = $before;

        $allowed_fields = $this->apply_design_fields( $body, $config );

        $contrast_warnings = Color_Contrast::check_contrast_warnings(
            $config['color_bg'] ?? '',
            $config['color_text'] ?? '',
            $config['color_primary'] ?? ''
        );

        update_option( Setting_Keys::UI_CONFIG, $config );
        $this->audit_logger->log_entity_change( 'settings', 0, 'settings_updated_design', $before, $config, [], [
            'message'        => 'Design settings updated from admin.',
            'allowed_fields' => $allowed_fields,
        ] );

        $response = [ 'success' => true ];
        if ( ! empty( $contrast_warnings ) ) {
            $response['contrast_warnings'] = $contrast_warnings;
        }

        return $this->success_response( $response );
    }

    /**
     * Aplica los campos de diseno del body al array de configuracion.
     *
     * @return string[] Campos que fueron modificados.
     */
    private function apply_design_fields( array $body, array &$config ): array {
        $allowed = [ 'preset', 'color_primary', 'color_bg', 'color_text', 'font_family', 'radius', 'layout' ];

        foreach ( $allowed as $key ) {
            if ( isset( $body[ $key ] ) ) {
                $config[ $key ] = sanitize_text_field( $body[ $key ] );
            }
        }

        if ( isset( $body['custom_css'] ) ) {
            $css = wp_strip_all_tags( $body['custom_css'] );
            $css = str_replace( '</style', '', $css );
            $config['custom_css'] = substr( $css, 0, 51200 );
            $allowed[] = 'custom_css';
        }

        return $allowed;
    }

    /**
     * Obtiene la configuracion de UI actual como array.
     */
    private function current_ui_config(): array {
        $config = get_option( Setting_Keys::UI_CONFIG, [] );

        return is_array( $config ) ? $config : [];
    }

    public function admin_get_form_fields( \WP_REST_Request $request ): \WP_REST_Response {
        $rows = $this->form_field_repo->find_all_ordered();
        return new \WP_REST_Response( [ 'fields' => $rows ?: [] ], 200 );
    }

    public function admin_onboarding( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );
        $result = $this->onboarding_service->execute( $body );
        if ( ! empty( $result['error'] ) ) {
            return $this->error_response( $result['error'], $result['code'] ?? 400 );
        }

        return $this->success_response( $result );
    }

    public function admin_get_settings( \WP_REST_Request $request ): \WP_REST_Response {
        return $this->success_response( [
            'business_name'          => get_option( Setting_Keys::BUSINESS_NAME, '' ),
            'country'                => get_option( Setting_Keys::BUSINESS_COUNTRY, '' ),
            'currency'               => get_option( Setting_Keys::BUSINESS_CURRENCY, 'USD' ),
            'timezone'               => get_option( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' ),
            'language'               => get_option( Setting_Keys::BUSINESS_LANGUAGE, 'es' ),
            'email_sender_name'      => get_option( Setting_Keys::EMAIL_SENDER_NAME, get_bloginfo( 'name' ) ),
            'email_sender_address'   => get_option( Setting_Keys::EMAIL_SENDER_ADDRESS, get_bloginfo( 'admin_email' ) ),
            'public_booking_page_url'=> get_option( Setting_Keys::PUBLIC_BOOKING_PAGE_URL, '' ),
            'cancel_min_hours'                 => (int) get_option( Setting_Keys::CANCEL_MIN_HOURS, 0 ),
            'reschedule_min_hours'             => (int) get_option( Setting_Keys::RESCHEDULE_MIN_HOURS, 0 ),
            'booking_expiry_minutes'           => (int) get_option( Setting_Keys::BOOKING_EXPIRY_MINUTES, 15 ),
            'free_booking_expiry_minutes'      => (int) get_option( Setting_Keys::FREE_BOOKING_EXPIRY, 5 ),
            'reminder_hours_before'            => (int) get_option( Setting_Keys::REMINDER_HOURS_BEFORE, 24 ),
            'notification_log_retention_days'  => (int) get_option( Setting_Keys::NOTIFICATION_LOG_RETENTION, 30 ),
            'audit_log_retention_days'         => (int) get_option( Setting_Keys::AUDIT_LOG_RETENTION, 0 ),
            'outbox_record_events'             => (bool) get_option( Setting_Keys::OUTBOX_RECORD_EVENTS, 0 ),
            'outbox_worker_enabled'            => (bool) get_option( Setting_Keys::OUTBOX_WORKER_ENABLED, 0 ),
            'async_outbound_webhooks'          => (bool) get_option( Setting_Keys::ASYNC_OUTBOUND_WEBHOOKS, 0 ),
            'outbox_processed_retention_days'  => (int) get_option( Setting_Keys::OUTBOX_RETENTION_DAYS, 7 ),
            'uninstall_remove_data'            => (bool) get_option( Setting_Keys::UNINSTALL_REMOVE_DATA, false ),
            'ui_config'                        => get_option( Setting_Keys::UI_CONFIG, [] ),
        ] );
    }

    public function admin_save_form_fields( \WP_REST_Request $request ): \WP_REST_Response {
        $body   = $this->decode_json_body( $request );
        $fields = $body['fields'] ?? [];

        $this->form_field_repo->save_all( $fields );

        $this->audit_logger->log( [
            'entity_type' => 'settings',
            'entity_id'   => 0,
            'action'      => 'form_fields_updated',
            'actor_type'  => 'admin',
            'actor_id'    => get_current_user_id(),
            'message'     => 'Public booking form fields updated.',
            'context'     => [
                'field_keys' => array_map( static fn( $f ) => sanitize_key( (string) ( $f['field_key'] ?? '' ) ), $fields ),
            ],
        ] );

        return $this->success_response();
    }

    /**
     * Decodifica el cuerpo JSON de la request de forma segura.
     */
    private function decode_json_body( \WP_REST_Request $request ): array {
        $body = json_decode( $request->get_body(), true );

        return is_array( $body ) ? $body : [];
    }

    /**
     * Construye una respuesta REST de exito.
     */
    private function success_response( array $payload = [], int $status = 200 ): \WP_REST_Response {
        return new \WP_REST_Response( $payload, $status );
    }

    /**
     * Construye una respuesta REST de error con campo opcional.
     */
    private function error_response( string $message, int $status, ?string $field = null ): \WP_REST_Response {
        $payload = [ 'error' => $message ];

        if ( null !== $field ) {
            $payload['field'] = $field;
        }

        return new \WP_REST_Response( $payload, $status );
    }

    /**
     * Validate that the public booking page URL belongs to this WordPress site
     * and is reachable via wp_remote_get.
     *
     * @return true|string  true on success, error message string on failure.
     */
    private function validate_public_booking_url( string $url ) {
        if ( empty( $url ) ) {
            return true; // empty is allowed (disables the feature)
        }

        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return 'La URL de la página de reservas no es una URL válida.';
        }

        $site_host  = wp_parse_url( get_home_url(), PHP_URL_HOST );
        $input_host = wp_parse_url( $url, PHP_URL_HOST );

        if ( $site_host && $input_host && strtolower( $input_host ) !== strtolower( $site_host ) ) {
            return sprintf(
                'La URL de la página de reservas debe pertenecer a este sitio (%s). Se recibió: %s.',
                $site_host,
                $input_host
            );
        }

        return true;
    }

    public function admin_validate_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $body     = $this->decode_json_body( $request );
        $errors   = [];
        $warnings = [];

        $this->collect_currency_change_warning( $body, $warnings );
        $this->collect_timezone_change_impact( $body, $errors, $warnings );
        $this->collect_booking_page_url_error( $body, $errors );
        $this->collect_numeric_validation_errors( $body, $errors );
        $this->collect_gateway_configuration_warnings( $body, $warnings );

        return $this->success_response( [
            'valid'    => empty( $errors ),
            'errors'   => $errors,
            'warnings' => $warnings,
        ], empty( $errors ) ? 200 : 422 );
    }

    /**
     * Advierte si cambiar la moneda dejaria precios inconsistentes.
     */
    private function collect_currency_change_warning( array $body, array &$warnings ): void {
        $new_currency = ! empty( $body['currency'] ) ? strtoupper( sanitize_text_field( $body['currency'] ) ) : null;
        $old_currency = get_option( Setting_Keys::BUSINESS_CURRENCY, 'USD' );

        if ( ! $new_currency || $new_currency === $old_currency ) {
            return;
        }

        $warnings[] = [
            'field'   => 'currency',
            'message' => sprintf(
                'Cambiar la moneda de %s a %s no convierte los precios existentes. Revisa los precios de tus servicios despues del cambio.',
                $old_currency,
                $new_currency
            ),
        ];
    }

    /**
     * Valida la zona horaria y advierte sobre el impacto en slots y cron.
     */
    private function collect_timezone_change_impact( array $body, array &$errors, array &$warnings ): void {
        $new_timezone = ! empty( $body['timezone'] ) ? sanitize_text_field( $body['timezone'] ) : null;

        if ( ! $new_timezone ) {
            return;
        }

        if ( ! in_array( $new_timezone, timezone_identifiers_list(), true ) ) {
            $errors[] = [ 'field' => 'timezone', 'message' => 'Zona horaria no reconocida.' ];
            return;
        }

        $old_timezone = get_option( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' );
        if ( $new_timezone !== $old_timezone ) {
            $warnings[] = [
                'field'   => 'timezone',
                'message' => sprintf(
                    'Cambiar la zona horaria de %s a %s afecta la visualizacion de todos los slots y citas existentes. Los recordatorios del cron se calcularan en la nueva zona horaria.',
                    $old_timezone,
                    $new_timezone
                ),
            ];
        }
    }

    /**
     * Valida que la URL de la pagina de reservas pertenezca a este sitio.
     */
    private function collect_booking_page_url_error( array $body, array &$errors ): void {
        if ( ! isset( $body['public_booking_page_url'] ) || $body['public_booking_page_url'] === '' ) {
            return;
        }

        $url_check = $this->validate_public_booking_url( esc_url_raw( $body['public_booking_page_url'] ) );
        if ( $url_check !== true ) {
            $errors[] = [ 'field' => 'public_booking_page_url', 'message' => $url_check ];
        }
    }

    /**
     * Valida limites minimos en campos numericos.
     */
    private function collect_numeric_validation_errors( array $body, array &$errors ): void {
        if ( isset( $body['booking_expiry_minutes'] ) && (int) $body['booking_expiry_minutes'] < 5 ) {
            $errors[] = [ 'field' => 'booking_expiry_minutes', 'message' => 'El tiempo minimo de expiracion es 5 minutos.' ];
        }

        if ( isset( $body['reminder_hours_before'] ) && (int) $body['reminder_hours_before'] < 1 ) {
            $errors[] = [ 'field' => 'reminder_hours_before', 'message' => 'El recordatorio debe ser al menos 1 hora antes.' ];
        }
    }

    /**
     * Advierte si un gateway habilitado no esta configurado.
     */
    private function collect_gateway_configuration_warnings( array $body, array &$warnings ): void {
        if ( empty( $body['enabled_gateways'] ) ) {
            return;
        }

        $gateways = (array) $body['enabled_gateways'];

        if ( in_array( 'stripe', $gateways, true ) && ! get_option( Setting_Keys::STRIPE_SECRET_KEY, '' ) ) {
            $warnings[] = [ 'field' => 'enabled_gateways', 'message' => 'Stripe esta seleccionado pero no esta completamente configurado. Verifica las claves en Ajustes > Pagos.' ];
        }

        if ( in_array( 'mercadopago', $gateways, true ) && ! get_option( Setting_Keys::MP_ACCESS_TOKEN, '' ) ) {
            $warnings[] = [ 'field' => 'enabled_gateways', 'message' => 'MercadoPago esta seleccionado pero no esta completamente configurado. Verifica el access token en Ajustes > Pagos.' ];
        }
    }

    public function admin_get_onboarding_presets( \WP_REST_Request $request ): \WP_REST_Response {
        $presets = $this->onboarding_preset_service->get_presets();
        $list = [];
        foreach ( $presets as $key => $preset ) {
            $list[] = [
                'key'         => $key,
                'label'       => $preset['label'],
                'icon'        => $preset['icon'],
                'description' => $preset['description'],
            ];
        }
        return $this->success_response( [ 'presets' => $list ] );
    }

    public function admin_get_readiness_checklist( \WP_REST_Request $request ): \WP_REST_Response {
        $items   = $this->onboarding_preset_service->get_readiness_checklist();
        $done    = count( array_filter( $items, fn( $i ) => $i['done'] ) );
        $total   = count( $items );

        return $this->success_response( [
            'items'    => $items,
            'done'     => $done,
            'total'    => $total,
            'ready'    => $done === $total,
            'progress' => $total > 0 ? round( $done / $total * 100 ) : 0,
        ] );
    }

    public function admin_detect_context( \WP_REST_Request $request ): \WP_REST_Response {
        $country  = $this->onboarding_preset_service->detect_country();
        $defaults = $this->onboarding_preset_service->get_country_defaults( $country );

        $wp_timezone = $this->resolve_timezone_label();

        $detected = [
            'country'    => $country,
            'timezone'   => $wp_timezone,
            'locale'     => get_locale(),
        ];

        if ( $defaults ) {
            $detected['suggested_currency'] = $defaults['currency'];
            $detected['suggested_language'] = $defaults['language'];
        }

        return $this->success_response( $detected );
    }

    public function admin_get_feature_flags( \WP_REST_Request $request ): \WP_REST_Response {
        $flags = $this->feature_flags->get_all();
        return $this->success_response( [ 'flags' => $flags ] );
    }

    public function admin_set_feature_flag( \WP_REST_Request $request ): \WP_REST_Response {
        $key  = sanitize_text_field( $request['key'] );
        $body = $this->decode_json_body( $request );
        $value = sanitize_text_field( $body['value'] ?? '' );

        $allowed_keys = [ 'safe_mode', 'maintenance_mode', 'disable_online_payment', 'disable_notifications', 'readonly_booking_page' ];
        if ( ! in_array( $key, $allowed_keys, true ) ) {
            return $this->error_response( 'Flag no permitido.', 400 );
        }
        if ( ! in_array( $value, [ 'true', 'false' ], true ) ) {
            return $this->error_response( 'Valor debe ser true o false.', 400 );
        }

        $service = $this->feature_flags;
        $service->set( $key, $value );

        $this->audit_logger->log_entity_change( 'settings', 0, 'feature_flag_changed', [ $key => ! filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ], [ $key => $value ], [], [
            'message' => 'Feature flag changed.',
            'flag'    => $key,
            'value'   => $value,
        ] );

        return $this->success_response( [ 'success' => true, 'flag' => $key, 'value' => $value ] );
    }

    public function admin_integrity_check( \WP_REST_Request $request ): \WP_REST_Response {
        $results = $this->integrity_check_service->run_all_checks();
        $all_ok = empty( $results );
        return $this->success_response( [
            'success'  => $all_ok,
            'checks'   => $results,
            'total'    => count( $results ),
            'message'  => $all_ok ? 'Todas las verificaciones de integridad pasaron.' : sprintf( 'Se encontraron %d problemas.', count( $results ) ),
        ] );
    }

    public function admin_manual_reconcile( \WP_REST_Request $request ): \WP_REST_Response {
        $this->cron_manager->run_reconcile_state();

        $remaining = [];
        foreach ( $this->integrity_check_service->run_all_checks() as $check ) {
            if ( $check['check'] === 'booking_payment_consistency' ) {
                $remaining[] = $check;
            }
        }

        return $this->success_response( [
            'success'   => empty( $remaining ),
            'message'   => empty( $remaining ) ? 'Reconciliacion completada sin inconsistencias.' : sprintf( 'Quedan %d inconsistencias.', count( $remaining ) ),
            'remaining' => $remaining,
        ] );
    }

    public function admin_dashboard_data( \WP_REST_Request $request ): \WP_REST_Response {
        $today = current_time( 'Y-m-d' );

        return $this->success_response( [
            'stats'             => $this->build_dashboard_stats( $today ),
            'today_bookings'    => $this->booking_repo->find_today_dashboard_rows( $today ) ?: [],
            'attention_required' => $this->booking_repo->find_attention_required_rows() ?: [],
            'urgency'           => $this->build_urgency_items(),
            'flags'             => $this->build_feature_flag_summary(),
        ] );
    }

    /**
     * Construye las estadisticas rapidas del dashboard.
     */
    private function build_dashboard_stats( string $today ): array {
        return [
            'today_bookings'   => $this->booking_repo->count_for_date( $today ),
            'pending_bookings' => $this->booking_repo->count_pending(),
            'unpaid_bookings'  => $this->booking_repo->count_unpaid(),
        ];
    }

    /**
     * Recolecta items que requieren atencion urgente del admin.
     */
    private function build_urgency_items(): array {
        $items = [];

        $expired_pending = $this->booking_repo->count_expired_pending_bookings();
        if ( $expired_pending > 0 ) {
            $items[] = [ 'type' => 'expired_pending', 'label' => 'Reservas expiradas sin procesar', 'count' => $expired_pending ];
        }

        $queue_failed = $this->queue_repo->count_by_status( 'failed' );
        if ( $queue_failed > 0 ) {
            $items[] = [ 'type' => 'failed_notifications', 'label' => 'Notificaciones fallidas', 'count' => $queue_failed ];
        }

        foreach ( $this->integrity_check_service->run_all_checks() as $check ) {
            if ( $check['check'] !== 'expired_pending' ) {
                $items[] = [ 'type' => $check['check'], 'label' => $check['label'], 'count' => $check['count'] ];
            }
        }

        return $items;
    }

    /**
     * Resumen de feature flags activos para el dashboard.
     */
    private function build_feature_flag_summary(): array {
        return [
            'safe_mode'        => $this->feature_flags->is_safe_mode(),
            'maintenance_mode' => $this->feature_flags->is_maintenance_mode(),
        ];
    }

    /**
     * Resuelve la zona horaria visible para el admin.
     */
    private function resolve_timezone_label(): string {
        $wp_timezone = get_option( 'timezone_string', '' );

        if ( $wp_timezone ) {
            return $wp_timezone;
        }

        $offset = get_option( 'gmt_offset', 0 );
        if ( $offset ) {
            return 'UTC' . ( $offset >= 0 ? '+' : '' ) . $offset;
        }

        return 'UTC';
    }
}
