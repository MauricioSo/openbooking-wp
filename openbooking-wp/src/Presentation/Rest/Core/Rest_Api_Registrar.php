<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Core;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Shared\Port\RateLimiterInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registra las rutas REST publicas, administrativas e integracion.
 */
class Rest_Api_Registrar {

    private string $namespace = 'openbooking/v1';
    private $current_admin_request = null;
    private ?RateLimiterInterface $rate_limiter = null;

    public function __construct(
        private ?\OpenBooking\Presentation\Rest\Booking\Booking_Controller $booking_public = null,
        private ?\OpenBooking\Presentation\Rest\Booking\Admin_Booking_Controller $booking_admin = null,
        private ?\OpenBooking\Presentation\Rest\Availability\Availability_Controller $availability = null,
        private ?\OpenBooking\Presentation\Rest\Payment\Payment_Controller $payment_public = null,
        private ?\OpenBooking\Presentation\Rest\Payment\Admin_Payment_Controller $payment_admin = null,
        private ?\OpenBooking\Presentation\Rest\Customer\Admin_Customer_Controller $customers = null,
        private ?\OpenBooking\Presentation\Rest\Catalog\Service_Controller $service_public = null,
        private ?\OpenBooking\Presentation\Rest\Catalog\Admin_Service_Controller $service_admin = null,
        private ?\OpenBooking\Presentation\Rest\Catalog\Admin_Resource_Controller $resource_admin = null,
        private ?\OpenBooking\Presentation\Rest\Settings\Admin_Settings_Controller $settings = null,
        private ?\OpenBooking\Presentation\Rest\Notification\Admin_Notification_Controller $notifications = null,
        private ?\OpenBooking\Presentation\Rest\Audit\Admin_Audit_Controller $audit = null,
        private ?Health_Controller $health = null,
        private ?Admin_Dashboard_Controller $dashboard = null,
        private ?Admin_Outbox_Controller $outbox = null,
        private ?\OpenBooking\Presentation\Rest\Integration\Integration_Controller $integration = null,
        private ?\OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface $payment_repo = null,
        private ?\OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo = null,
        private ?\OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface $customer_repo = null,
        private ?\OpenBooking\Domain\Notification\Repository\NotificationPreferencesRepositoryInterface $notification_prefs_repo = null,
        private ?\OpenBooking\Domain\Notification\Service\NotificationManagerInterface $notification_manager = null,
        private ?\OpenBooking\Application\Payment\Service\Payment_Service $payment_service = null,
        private ?\OpenBooking\Application\Audit\Service\Audit_Logger $audit_logger = null,
        ?RateLimiterInterface $rate_limiter = null,
        private ?\OpenBooking\Domain\Payment\Repository\GatewayResolverInterface $gateway_resolver = null,
        private ?\OpenBooking\Domain\Shared\Port\PrivacyHandlerInterface $privacy_handler = null,
        private ?Admin_Cron_Controller $cron_controller = null,
        private ?Admin_Webhook_Controller $webhook_controller = null,
        private ?Telemetry_Controller $telemetry_controller = null,
        private ?\OpenBooking\Application\Payment\Service\Gateway_Settings_Service $gateway_settings_service = null,
    ) {
        $this->rate_limiter = $rate_limiter;
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_filter( 'rest_pre_dispatch', [ $this, 'capture_request_context' ], 10, 3 );
        add_filter( 'rest_post_dispatch', [ $this, 'attach_request_id_header' ], 10, 3 );
        add_filter( 'rest_pre_serve_request', [ $this, 'restrict_cors_headers' ], 11, 3 );
    }

    /**
     * WordPress core refleja cualquier Origin en las cabeceras CORS del REST API.
     * Para rutas de OpenBooking, elimina esas cabeceras cuando el Origin no esta
     * en la lista de origenes permitidos del sitio (mismo host por defecto).
     */
    public function restrict_cors_headers( $served, $result, $request ) {
        $route = (string) $request->get_route();
        if ( ! str_starts_with( $route, '/' . $this->namespace ) ) {
            return $served;
        }

        if ( ! function_exists( 'get_http_origin' ) || ! function_exists( 'is_allowed_http_origin' ) ) {
            return $served;
        }

        $origin = get_http_origin();
        if ( $origin && '' === (string) is_allowed_http_origin( $origin ) ) {
            header_remove( 'Access-Control-Allow-Origin' );
            header_remove( 'Access-Control-Allow-Methods' );
            header_remove( 'Access-Control-Allow-Headers' );
            header_remove( 'Access-Control-Allow-Credentials' );
            header_remove( 'Access-Control-Expose-Headers' );
        }

        return $served;
    }

    public function capture_request_context( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
        $route = (string) $request->get_route();
        if ( ! str_starts_with( $route, '/' . $this->namespace ) ) {
            return $result;
        }

        $source = str_starts_with( $route, '/' . $this->namespace . '/admin/' ) ? 'admin' : 'public';
        \OpenBooking\Support\Request_Context::reset();
        \OpenBooking\Support\Request_Context::set_rest_request( $route, (string) $request->get_method(), $source );

        return $result;
    }

    public function attach_request_id_header( $response, \WP_REST_Server $server, \WP_REST_Request $request ) {
        $route = (string) $request->get_route();
        if ( ! str_starts_with( $route, '/' . $this->namespace ) || ! $response instanceof \WP_HTTP_Response ) {
            return $response;
        }

        $response->header( 'X-OpenBooking-Request-Id', \OpenBooking\Support\Request_Context::get_request_id() );
        return $response;
    }

    public function register_routes(): void {
        $cron = $this->cron_controller;
        $webhook = $this->webhook_controller ?? new Admin_Webhook_Controller();
        $telemetry = $this->telemetry_controller ?? new Telemetry_Controller($this->rate_limiter);

        // --- Public endpoints ---
        register_rest_route( $this->namespace, '/services', [ 'methods' => 'GET', 'callback' => [ $this->service_public, 'get_services' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/availability', [ 'methods' => 'GET', 'callback' => [ $this->availability, 'get_availability' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/availability/dates', [ 'methods' => 'GET', 'callback' => [ $this->availability, 'get_available_dates' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/bookings', [ 'methods' => 'POST', 'callback' => [ $this, 'create_booking' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/bookings/cancel/(?P<token>[a-zA-Z0-9]+)', [ 'methods' => 'POST', 'callback' => [ $this, 'cancel_by_token' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/bookings/confirm-attendance/(?P<token>[a-zA-Z0-9]+)', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'preview_attendance_by_token' ], 'permission_callback' => '__return_true' ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'confirm_attendance_by_token' ], 'permission_callback' => '__return_true' ],
        ] );
        register_rest_route( $this->namespace, '/bookings/reschedule/(?P<token>[a-zA-Z0-9]+)', [ 'methods' => 'POST', 'callback' => [ $this, 'reschedule_by_token' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/bookings/public/(?P<token>[a-zA-Z0-9]+)', [ 'methods' => 'GET', 'callback' => [ $this, 'get_public_booking_by_token' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/bookings/public/(?P<token>[a-zA-Z0-9]+)/renew-hold', [ 'methods' => 'POST', 'callback' => [ $this, 'renew_payment_hold' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/form-fields-public', [ 'methods' => 'GET', 'callback' => [ $this->booking_public, 'get_form_fields_public' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/bookings/status/(?P<token>[a-zA-Z0-9]+)', [ 'methods' => 'GET', 'callback' => [ $this, 'get_public_booking_status' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/payments/create', [ 'methods' => 'POST', 'callback' => [ $this, 'create_payment' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/payments/webhook/(?P<gateway>[a-z0-9_-]+)', [ 'methods' => 'POST', 'callback' => [ $this->payment_public, 'payment_webhook' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/payments/webpay-return', [ 'methods' => [ 'GET', 'POST' ], 'callback' => [ $this, 'webpay_return' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/telemetry/public', [ 'methods' => 'POST', 'callback' => [ $telemetry, 'public_telemetry_event' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/health', [ 'methods' => 'GET', 'callback' => [ $this->health, 'health_check' ], 'permission_callback' => '__return_true' ] );

        // --- Public unsubscribe ---
        register_rest_route( $this->namespace, '/public/notifications/unsubscribe', [ 'methods' => 'GET', 'callback' => [ $this, 'public_get_notification_unsubscribe' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/public/notifications/unsubscribe', [ 'methods' => 'POST', 'callback' => [ $this, 'public_post_notification_unsubscribe' ], 'permission_callback' => '__return_true' ] );

        // --- Admin endpoints ---
        $perm = [ $this, 'admin_rest_permission_check' ];
        register_rest_route( $this->namespace, '/admin/kpis', [ 'methods' => 'GET', 'callback' => [ $this->dashboard, 'admin_get_kpis' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/dashboard', [ 'methods' => 'GET', 'callback' => [ $this->dashboard, 'admin_dashboard' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/bookings', [ 'methods' => 'GET', 'callback' => [ $this->booking_admin, 'admin_get_bookings' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/bookings', [ 'methods' => 'POST', 'callback' => [ $this->booking_admin, 'admin_create_booking' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/bookings/export', [ 'methods' => 'GET', 'callback' => [ $this->booking_admin, 'admin_export_bookings' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/bookings/(?P<id>\d+)', [ 'methods' => [ 'GET', 'PATCH', 'DELETE' ], 'callback' => [ $this->booking_admin, 'admin_booking_action' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/bookings/(?P<id>\d+)/timeline', [ 'methods' => 'GET', 'callback' => [ $this->booking_admin, 'admin_booking_timeline' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/services', [ 'methods' => 'GET', 'callback' => [ $this->service_admin, 'admin_get_services' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/services', [ 'methods' => 'POST', 'callback' => [ $this->service_admin, 'admin_create_service' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/services/(?P<id>\d+)', [ 'methods' => [ 'GET', 'PATCH', 'DELETE' ], 'callback' => [ $this->service_admin, 'admin_service_action' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/services/(?P<id>\d+)/restore', [ 'methods' => 'POST', 'callback' => [ $this->service_admin, 'admin_restore_service' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/availability', [ 'methods' => [ 'GET', 'POST' ], 'callback' => [ $this->availability, 'admin_availability_settings' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/availability/import', [ 'methods' => 'POST', 'callback' => [ $this->availability, 'admin_import_availability' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/availability/preview', [ 'methods' => 'POST', 'callback' => [ $this->availability, 'admin_preview_availability' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/availability/validate', [ 'methods' => 'POST', 'callback' => [ $this->availability, 'admin_validate_availability' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/availability/snapshots', [ 'methods' => 'GET', 'callback' => [ $this->availability, 'admin_list_snapshots' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/availability/snapshots', [ 'methods' => 'POST', 'callback' => [ $this->availability, 'admin_create_snapshot' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/availability/snapshots/(?P<id>\d+)/restore', [ 'methods' => 'POST', 'callback' => [ $this->availability, 'admin_restore_snapshot' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/availability/snapshots/(?P<id>\d+)', [ 'methods' => 'DELETE', 'callback' => [ $this->availability, 'admin_delete_snapshot' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/customers', [ 'methods' => 'GET', 'callback' => [ $this->customers, 'admin_get_customers' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/customers/(?P<id>\d+)', [ 'methods' => [ 'GET', 'PATCH', 'DELETE' ], 'callback' => [ $this->customers, 'admin_customer_action' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/customers/(?P<id>\d+)/export-json', [ 'methods' => 'GET', 'callback' => [ $this, 'admin_export_customer_json' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/settings', [ 'methods' => 'GET', 'callback' => [ $this->settings, 'admin_get_settings' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/settings', [ 'methods' => 'POST', 'callback' => [ $this->settings, 'admin_save_settings' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/settings/validate', [ 'methods' => 'POST', 'callback' => [ $this->settings, 'admin_validate_settings' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/settings/payments', [ 'methods' => 'GET', 'callback' => [ $this, 'admin_get_payment_settings' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/settings/payments', [ 'methods' => 'POST', 'callback' => [ $this, 'admin_save_payment_settings' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/settings/design', [ 'methods' => 'POST', 'callback' => [ $this->settings, 'admin_save_design' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/form-fields', [ 'methods' => 'GET', 'callback' => [ $this->settings, 'admin_get_form_fields' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/form-fields', [ 'methods' => 'POST', 'callback' => [ $this->settings, 'admin_save_form_fields' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/onboarding', [ 'methods' => 'POST', 'callback' => [ $this->settings, 'admin_onboarding' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/create-booking-page', [ 'methods' => 'POST', 'callback' => [ $this, 'admin_create_booking_page' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/resources', [ 'methods' => 'GET', 'callback' => [ $this->resource_admin, 'admin_get_resources' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/resources', [ 'methods' => 'POST', 'callback' => [ $this->resource_admin, 'admin_create_resource' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/resources/(?P<id>\d+)', [ 'methods' => [ 'GET', 'PATCH', 'DELETE' ], 'callback' => [ $this->resource_admin, 'admin_resource_action' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/resources/(?P<id>\d+)/restore', [ 'methods' => 'POST', 'callback' => [ $this->resource_admin, 'admin_restore_resource' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/email-templates', [ 'methods' => 'GET', 'callback' => [ $this->notifications, 'admin_get_email_templates' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/email-templates/(?P<key>[a-z0-9_]+)', [ 'methods' => 'POST', 'callback' => [ $this->notifications, 'admin_save_email_template' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/email-test', [ 'methods' => 'POST', 'callback' => [ $this->notifications, 'admin_test_email' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/payments', [ 'methods' => 'GET', 'callback' => [ $this->payment_admin, 'admin_get_payments' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/payments/(?P<id>\d+)/refund', [ 'methods' => 'POST', 'callback' => [ $this->payment_admin, 'admin_refund_payment' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/payments/(?P<id>\d+)/status', [ 'methods' => 'POST', 'callback' => [ $this->payment_admin, 'admin_change_payment_status' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/payments/(?P<id>\d+)/dispute', [ 'methods' => 'POST', 'callback' => [ $this->payment_admin, 'admin_dispute_payment' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/payments/(?P<id>\d+)/attempts', [ 'methods' => 'GET', 'callback' => [ $this->payment_admin, 'admin_get_payment_attempts' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/gateways', [ 'methods' => 'GET', 'callback' => [ $this->payment_admin, 'admin_get_gateways' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/settings/gateway/(?P<key>[a-z0-9_]+)', [ 'methods' => 'POST', 'callback' => [ $this->payment_admin, 'admin_save_gateway_settings' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/gateways/(?P<key>[a-z0-9_]+)/checklist', [ 'methods' => 'GET', 'callback' => [ $this, 'admin_gateway_checklist' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/audit-logs', [ 'methods' => 'GET', 'callback' => [ $this->audit, 'admin_get_audit_logs' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/audit-logs/(?P<id>\d+)', [ 'methods' => 'GET', 'callback' => [ $this->audit, 'admin_get_audit_log' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/settings/notifications', [ 'methods' => 'GET', 'callback' => [ $this->notifications, 'admin_get_notification_settings' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/settings/notifications', [ 'methods' => 'POST', 'callback' => [ $this->notifications, 'admin_save_notification_settings' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/whatsapp-templates', [ 'methods' => 'GET', 'callback' => [ $this->notifications, 'admin_get_whatsapp_templates' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/whatsapp-templates/(?P<key>[a-z0-9_]+)', [ 'methods' => 'POST', 'callback' => [ $this->notifications, 'admin_save_whatsapp_template' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/whatsapp-test', [ 'methods' => 'POST', 'callback' => [ $this->notifications, 'admin_test_whatsapp' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/sms-templates', [ 'methods' => 'GET', 'callback' => [ $this->notifications, 'admin_get_sms_templates' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/sms-templates/(?P<key>[a-z0-9_]+)', [ 'methods' => 'POST', 'callback' => [ $this->notifications, 'admin_save_sms_template' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/sms-test', [ 'methods' => 'POST', 'callback' => [ $this->notifications, 'admin_test_sms' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/notification-logs', [ 'methods' => 'GET', 'callback' => [ $this->notifications, 'admin_get_notification_logs' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/notification-logs/export', [ 'methods' => 'GET', 'callback' => [ $this->notifications, 'admin_export_notification_logs' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/notification-logs/(?P<id>\d+)/resend', [ 'methods' => 'POST', 'callback' => [ $this->notifications, 'admin_resend_notification_log' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/notification-queue', [ 'methods' => 'GET', 'callback' => [ $this->notifications, 'admin_get_notification_queue' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/notification-queue/(?P<id>\d+)', [ 'methods' => 'DELETE', 'callback' => [ $this->notifications, 'admin_cancel_notification_queue_item' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/notification-queue/(?P<id>\d+)/retry', [ 'methods' => 'POST', 'callback' => [ $this->notifications, 'admin_retry_notification_queue_item' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/notification-queue/cancel-for-booking/(?P<id>\d+)', [ 'methods' => 'POST', 'callback' => [ $this->notifications, 'admin_cancel_notification_queue_for_booking' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/notification-stats', [ 'methods' => 'GET', 'callback' => [ $this->notifications, 'admin_get_notification_stats' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/notifications/bulk-cancel', [ 'methods' => 'POST', 'callback' => [ $this->notifications, 'admin_bulk_cancel_notifications' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/notifications/broadcast', [ 'methods' => 'POST', 'callback' => [ $this->notifications, 'admin_broadcast_notifications' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/notifications/preview', [ 'methods' => 'POST', 'callback' => [ $this->notifications, 'admin_preview_notification' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/notification-campaigns', [ 'methods' => 'GET', 'callback' => [ $this->notifications, 'admin_get_notification_campaigns' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/customers/(?P<id>\d+)/notification-preferences', [ 'methods' => 'GET', 'callback' => [ $this->notifications, 'admin_get_customer_notification_preferences' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/customers/(?P<id>\d+)/notification-preferences', [ 'methods' => 'PATCH', 'callback' => [ $this->notifications, 'admin_save_customer_notification_preferences' ], 'permission_callback' => $perm ] );

        // --- Admin: Cron ---
        register_rest_route( $this->namespace, '/admin/cron/status', [ 'methods' => 'GET', 'callback' => [ $cron, 'admin_cron_status' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/cron/trigger/(?P<event>[a-z0-9_]+)', [ 'methods' => 'POST', 'callback' => [ $cron, 'admin_cron_trigger' ], 'permission_callback' => $perm ] );

        // --- Admin: Webhooks ---
        register_rest_route( $this->namespace, '/admin/webhooks', [ 'methods' => 'GET',  'callback' => [ $webhook, 'admin_get_webhooks' ],  'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/webhooks', [ 'methods' => 'POST', 'callback' => [ $webhook, 'admin_save_webhooks' ], 'permission_callback' => $perm ] );

        // --- Admin: Outbox ---
        register_rest_route( $this->namespace, '/admin/outbox', [ 'methods' => 'GET', 'callback' => [ $this->outbox, 'admin_get_outbox_events' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/outbox/(?P<id>\d+)/retry', [ 'methods' => 'POST', 'callback' => [ $this->outbox, 'admin_retry_outbox_event' ], 'permission_callback' => $perm ] );
        register_rest_route( $this->namespace, '/admin/outbox/(?P<id>\d+)/ignore', [ 'methods' => 'POST', 'callback' => [ $this->outbox, 'admin_ignore_outbox_event' ], 'permission_callback' => $perm ] );

        // --- Integration endpoints ---
        register_rest_route( $this->namespace, '/integrations/health', [ 'methods' => 'GET', 'callback' => [ $this->integration, 'health' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/integrations/services', [ 'methods' => 'GET', 'callback' => [ $this->integration, 'get_services' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/integrations/availability', [ 'methods' => 'GET', 'callback' => [ $this->integration, 'get_availability' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/integrations/bookings', [ 'methods' => 'POST', 'callback' => [ $this->integration, 'create_booking' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/integrations/bookings/(?P<id>\d+)', [ 'methods' => 'GET', 'callback' => [ $this->integration, 'get_booking' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/integrations/bookings/(?P<id>\d+)/cancel', [ 'methods' => 'POST', 'callback' => [ $this->integration, 'cancel_booking' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/integrations/bookings/(?P<id>\d+)/reschedule', [ 'methods' => 'POST', 'callback' => [ $this->integration, 'reschedule_booking' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( $this->namespace, '/integrations/integrity-check', [ 'methods' => 'GET', 'callback' => [ $this->integration, 'integrity_check' ], 'permission_callback' => '__return_true' ] );
    }

    public function admin_permission_check( ?\WP_REST_Request $request = null ): bool|\WP_Error {
        return $this->admin_rest_permission_check( $request ?? new \WP_REST_Request( 'GET' ) );
    }

    public function admin_rest_permission_check( \WP_REST_Request $request ): bool|\WP_Error {
        $this->current_admin_request = $request;
        \OpenBooking\Support\Request_Context::set_rest_request(
            $request->get_route(),
            $request->get_method(),
            'admin'
        );

        if ( ! is_user_logged_in() ) {
            return new \WP_Error( Setting_Keys::REST_AUTH, 'Authentication required.', [ 'status' => 401 ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( Setting_Keys::REST_FORBIDDEN, 'Insufficient permissions.', [ 'status' => 403 ] );
        }

        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce ) {
            $nonce = $request->get_header( 'x-wp-nonce' );
        }

        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error( Setting_Keys::REST_NONCE, 'Invalid or missing REST nonce.', [ 'status' => 403 ] );
        }

        return true;
    }

    // =========================================================================
    // Public booking wrappers (rate limit + CSRF + delegate to controller)
    // =========================================================================

    public function create_booking( \WP_REST_Request $request ): \WP_REST_Response {
        $origin_blocked = $this->verify_same_origin_post();
        if ( $origin_blocked ) {
            return $origin_blocked;
        }
        $rate_limited = $this->check_public_rate_limit( 'booking_create', 5, HOUR_IN_SECONDS, 'Demasiadas reservas. Intenta de nuevo en una hora.' );
        if ( $rate_limited ) {
            return $rate_limited;
        }
        return $this->timed( 'booking_create', function() use ( $request ) {
            return $this->booking_public->create_booking( $request );
        } );
    }

    public function preview_attendance_by_token( \WP_REST_Request $request ): \WP_REST_Response {
        $token = sanitize_text_field( $request['token'] ?? '' );
        if ( ! $token ) {
            return Rest_Error_Helper::missing_field( 'token' );
        }
        $rate_limited = $this->check_public_rate_limit( 'booking_confirm_attendance', 20, HOUR_IN_SECONDS, 'Demasiadas confirmaciones. Intenta de nuevo mas tarde.' );
        if ( $rate_limited ) {
            return $rate_limited;
        }
        $token_limited = $this->check_public_rate_limit( 'preview_tok_' . substr( hash( 'sha256', $token ), 0, 16 ), 20, HOUR_IN_SECONDS, 'Demasiados intentos para este enlace.' );
        if ( $token_limited ) {
            return $token_limited;
        }
        return $this->booking_public->preview_attendance_by_token( $request );
    }

    public function confirm_attendance_by_token( \WP_REST_Request $request ): \WP_REST_Response {
        $token = sanitize_text_field( $request['token'] ?? '' );
        $origin_blocked = $this->verify_same_origin_post();
        if ( $origin_blocked ) {
            return $origin_blocked;
        }
        $rate_limited = $this->check_public_rate_limit( 'booking_confirm_attendance', 20, HOUR_IN_SECONDS, 'Demasiadas confirmaciones. Intenta de nuevo mas tarde.' );
        if ( $rate_limited ) {
            return $rate_limited;
        }
        if ( $token ) {
            $token_limited = $this->check_public_rate_limit( 'confirm_tok_' . substr( hash( 'sha256', $token ), 0, 16 ), 5, HOUR_IN_SECONDS, 'Demasiados intentos para este enlace.' );
            if ( $token_limited ) {
                return $token_limited;
            }
        }
        return $this->booking_public->confirm_attendance_by_token( $request );
    }

    public function cancel_by_token( \WP_REST_Request $request ): \WP_REST_Response {
        $token = sanitize_text_field( $request['token'] ?? '' );
        $origin_blocked = $this->verify_same_origin_post();
        if ( $origin_blocked ) {
            return $origin_blocked;
        }
        $rate_limited = $this->check_public_rate_limit( 'booking_cancel', 20, HOUR_IN_SECONDS, 'Demasiadas cancelaciones. Intenta de nuevo mas tarde.' );
        if ( $rate_limited ) {
            return $rate_limited;
        }
        if ( $token ) {
            $token_limited = $this->check_public_rate_limit( 'cancel_tok_' . substr( hash( 'sha256', $token ), 0, 16 ), 5, HOUR_IN_SECONDS, 'Demasiados intentos para este token. Intenta de nuevo mas tarde.' );
            if ( $token_limited ) {
                return $token_limited;
            }
        }
        return $this->timed( 'booking_cancel', function() use ( $request ) {
            return $this->booking_public->cancel_by_token( $request );
        } );
    }

    public function reschedule_by_token( \WP_REST_Request $request ): \WP_REST_Response {
        $token = sanitize_text_field( $request['token'] ?? '' );
        $origin_blocked = $this->verify_same_origin_post();
        if ( $origin_blocked ) {
            return $origin_blocked;
        }
        $rate_limited = $this->check_public_rate_limit( 'booking_reschedule', 20, HOUR_IN_SECONDS, 'Demasiadas reprogramaciones. Intenta de nuevo mas tarde.' );
        if ( $rate_limited ) {
            return $rate_limited;
        }
        if ( $token ) {
            $token_limited = $this->check_public_rate_limit( 'reschedule_tok_' . substr( hash( 'sha256', $token ), 0, 16 ), 5, HOUR_IN_SECONDS, 'Demasiados intentos para este token. Intenta de nuevo mas tarde.' );
            if ( $token_limited ) {
                return $token_limited;
            }
        }
        return $this->timed( 'booking_reschedule', function() use ( $request ) {
            return $this->booking_public->reschedule_by_token( $request );
        } );
    }

    public function get_public_booking_by_token( \WP_REST_Request $request ): \WP_REST_Response {
        $token = sanitize_text_field( $request['token'] ?? '' );
        $rate_limited = $this->check_public_rate_limit( 'booking_public_lookup', 60, HOUR_IN_SECONDS, 'Demasiadas consultas. Intenta de nuevo mas tarde.' );
        if ( $rate_limited ) {
            return $rate_limited;
        }
        if ( $token ) {
            $token_limited = $this->check_public_rate_limit( 'lookup_tok_' . substr( hash( 'sha256', $token ), 0, 16 ), 20, HOUR_IN_SECONDS, 'Demasiados intentos para este token. Intenta de nuevo mas tarde.' );
            if ( $token_limited ) {
                return $token_limited;
            }
        }
        return $this->timed( 'booking_public', function() use ( $request ) {
            return $this->booking_public->get_public_booking_by_token( $request );
        } );
    }

    public function renew_payment_hold( \WP_REST_Request $request ): \WP_REST_Response {
        $token = sanitize_text_field( $request['token'] ?? '' );
        $rate_limited = $this->check_public_rate_limit( 'booking_hold_renewal', 40, HOUR_IN_SECONDS, 'Demasiados intentos de renovacion. Intenta de nuevo mas tarde.' );
        if ( $rate_limited ) {
            return $rate_limited;
        }
        if ( $token ) {
            $token_limited = $this->check_public_rate_limit( 'hold_renew_tok_' . substr( hash( 'sha256', $token ), 0, 16 ), 10, HOUR_IN_SECONDS, 'Demasiados intentos para este enlace. Intenta de nuevo mas tarde.' );
            if ( $token_limited ) {
                return $token_limited;
            }
        }
        return $this->timed( 'booking_hold_renew', function() use ( $request ) {
            return $this->booking_public->renew_payment_hold_by_token( $request );
        } );
    }

    public function get_public_booking_status( \WP_REST_Request $request ): \WP_REST_Response {
        $token = sanitize_text_field( $request['token'] ?? '' );
        $rate_limited = $this->check_public_rate_limit( 'booking_status', 60, HOUR_IN_SECONDS, 'Demasiadas consultas de estado. Intenta de nuevo mas tarde.' );
        if ( $rate_limited ) {
            return $rate_limited;
        }
        if ( $token ) {
            $token_limited = $this->check_public_rate_limit( 'status_tok_' . substr( hash( 'sha256', $token ), 0, 16 ), 20, HOUR_IN_SECONDS, 'Demasiados intentos para este token.' );
            if ( $token_limited ) {
                return $token_limited;
            }
        }
        return $this->timed( 'booking_status', function() use ( $request ) {
            return $this->booking_public->get_public_booking_status( $request );
        } );
    }

    public function create_payment( \WP_REST_Request $request ): \WP_REST_Response {
        $origin_blocked = $this->verify_same_origin_post();
        if ( $origin_blocked ) {
            return $origin_blocked;
        }
        $rate_limited = $this->check_public_rate_limit( 'payment_create', 20, HOUR_IN_SECONDS, 'Demasiados intentos de pago. Intenta de nuevo mas tarde.' );
        if ( $rate_limited ) {
            return $rate_limited;
        }
        return $this->timed( 'payment_create', function() use ( $request ) {
            return $this->payment_public->create_payment( $request );
        } );
    }

    public function webpay_return( \WP_REST_Request $request ): \WP_REST_Response {
        $remote = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        $limiter = $this->rate_limiter;
        if ( $limiter && ! $limiter->check( 'webpay_return', $remote, 30, MINUTE_IN_SECONDS ) ) {
            return Rest_Error_Helper::rate_limit_exceeded( 'Too many requests.' );
        }

        $payment_id = absint( $request->get_param( 'payment_id' ) );
        $token_ws   = sanitize_text_field( (string) ( $request->get_param( 'token_ws' ) ?? '' ) );
        $tbk_token  = sanitize_text_field( (string) ( $request->get_param( 'TBK_TOKEN' ) ?? '' ) );

        $payment = null;
        if ( $payment_id ) {
            $payment = $this->payment_repo->find( $payment_id );
        }
        $cancel_booking_id = $payment ? $payment->booking_id : 0;
        $cancel_url        = $this->webpay_booking_page_url( $payment_id, 'cancel', $cancel_booking_id );

        if ( ! $token_ws && ! $tbk_token ) {
            return $this->make_redirect_response( $cancel_url );
        }

        if ( $tbk_token && ! $token_ws ) {
            return $this->make_redirect_response( $cancel_url );
        }

        if ( $payment_id ) {
            if ( ! $payment || $payment->gateway !== 'webpay' || $payment->provider_checkout_id !== $token_ws ) {
                return $this->make_redirect_response( $cancel_url );
            }
        }

        $this->payment_service->handle_webhook(
            'webpay',
            wp_json_encode( [ 'token_ws' => $token_ws, 'payment_id' => $payment_id ] ),
            []
        );

        $payment = $payment_id ? $this->payment_repo->find( $payment_id ) : null;

        if ( $payment && $payment->status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID ) {
            $redirect = $this->webpay_booking_page_url( $payment_id, 'success', $payment->booking_id );
        } else {
            $redirect = $this->webpay_booking_page_url( $payment_id, 'cancel', $payment ? $payment->booking_id : 0 );
        }

        return $this->make_redirect_response( $redirect );
    }

    // =========================================================================
    // Admin inline handlers (small enough to keep here)
    // =========================================================================

    public function admin_get_payment_settings( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( $this->gateway_settings_service->get_payment_settings(), 200 );
    }

    public function admin_save_payment_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $body = json_decode( $request->get_body(), true ) ?: [];
        $this->gateway_settings_service->save_payment_settings( $body );
        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    public function admin_gateway_checklist( \WP_REST_Request $request ): \WP_REST_Response {
        $key = sanitize_key( $request['key'] ?? '' );
        $checklist = $this->gateway_settings_service->get_gateway_checklist( $key );
        if ( ! $checklist ) {
            return Rest_Error_Helper::not_found( 'gateway' );
        }
        $total = count( $checklist['steps'] );
        $done  = count( array_filter( $checklist['steps'], fn( $s ) => $s['done'] ) );
        return new \WP_REST_Response( [
            'gateway'  => $key,
            'label'    => $checklist['label'],
            'docs_url' => $checklist['docs_url'],
            'mode'     => $checklist['mode'],
            'progress' => [ 'done' => $done, 'total' => $total ],
            'ready'    => $done === $total,
            'steps'    => $checklist['steps'],
        ], 200 );
    }

    public function admin_create_booking_page( \WP_REST_Request $request ): \WP_REST_Response {
        $pages = get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1 ] );
        foreach ( $pages as $page ) {
            if ( has_shortcode( $page->post_content, 'openbooking' ) ) {
                return new \WP_REST_Response( [
                    'page_id'  => $page->ID,
                    'page_url' => get_permalink( $page->ID ),
                    'existing' => true,
                ], 200 );
            }
        }

        $page_id = wp_insert_post( [
            'post_title'   => __( 'Reservas', 'openbooking-wp' ),
            'post_name'    => 'reservas',
            'post_content' => '[openbooking]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );

        if ( is_wp_error( $page_id ) ) {
            return Rest_Error_Helper::internal_error( $page_id->get_error_message() );
        }

        $page_url = get_permalink( $page_id );
        update_option( Setting_Keys::PUBLIC_BOOKING_PAGE_URL, $page_url );

        return new \WP_REST_Response( [
            'page_id'  => $page_id,
            'page_url' => $page_url,
            'existing' => false,
        ], 201 );
    }

    public function admin_export_customer_json( \WP_REST_Request $request ): \WP_REST_Response {
        $customer_id = absint( $request['id'] );
        if ( ! $customer_id ) {
            return Rest_Error_Helper::missing_field( 'id' );
        }

        $customer = $this->customer_repo->find( $customer_id );
        if ( ! $customer ) {
            return Rest_Error_Helper::not_found( 'customer' );
        }

        if ( ! $this->privacy_handler ) {
            return new \WP_REST_Response( [ 'error' => 'Privacidad no disponible.' ], 500 );
        }

        $export = $this->privacy_handler->export_json_portable( $customer->email );

        if ( ! empty( $export['error'] ) ) {
            return new \WP_REST_Response( [ 'error' => $export['error'] ], $export['code'] ?? 500 );
        }

        $response = new \WP_REST_Response( $export, 200 );
        $response->header( 'Content-Disposition', 'attachment; filename="openbooking-export-customer-' . $customer_id . '-' . gmdate( 'Y-m-d' ) . '.json"' );
        return $response;
    }

    // =========================================================================
    // Public unsubscribe (rate-limited)
    // =========================================================================

    public function public_get_notification_unsubscribe( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_limited = $this->check_public_rate_limit( 'unsubscribe_get', 10, HOUR_IN_SECONDS, 'Demasiados intentos. Intenta de nuevo mas tarde.' );
        if ( $rate_limited ) {
            return $rate_limited;
        }
        $token = sanitize_text_field( (string) $request->get_param( 'token' ) );
        if ( $token ) {
            $token_limited = $this->check_public_rate_limit( 'unsub_tok_' . substr( hash( 'sha256', $token ), 0, 16 ), 5, HOUR_IN_SECONDS, 'Demasiados intentos para este enlace.' );
            if ( $token_limited ) {
                return $token_limited;
            }
        }
        return $this->notifications->public_get_notification_unsubscribe( $request );
    }

    public function public_post_notification_unsubscribe( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_limited = $this->check_public_rate_limit( 'unsubscribe_post', 5, HOUR_IN_SECONDS, 'Demasiados intentos. Intenta de nuevo mas tarde.' );
        if ( $rate_limited ) {
            return $rate_limited;
        }
        $body = json_decode( $request->get_body(), true ) ?: [];
        $token = sanitize_text_field( (string) ( $body['token'] ?? '' ) );
        if ( $token ) {
            $token_limited = $this->check_public_rate_limit( 'unsub_tok_' . substr( hash( 'sha256', $token ), 0, 16 ), 3, HOUR_IN_SECONDS, 'Demasiados intentos para este enlace.' );
            if ( $token_limited ) {
                return $token_limited;
            }
        }
        $response = $this->notifications->public_post_notification_unsubscribe( $request );

        if ( 200 === $response->get_status() ) {
            $prefs = $this->notification_prefs_repo->find_by_token( $token );
            if ( $prefs ) {
                $channel = sanitize_key( $body['channel'] ?? 'all' );
                if ( 'all' === $channel ) {
                    foreach ( [ 'email', 'whatsapp', 'sms' ] as $ch ) {
                        $this->notification_manager?->record_consent( (int) $prefs['customer_id'], $ch, 'transactional', 'opted_out', 'unsubscribe_link' );
                        $this->notification_manager?->record_consent( (int) $prefs['customer_id'], $ch, 'marketing', 'opted_out', 'unsubscribe_link' );
                    }
                } else {
                    $this->notification_manager?->record_consent( (int) $prefs['customer_id'], $channel, 'transactional', 'opted_out', 'unsubscribe_link' );
                    $this->notification_manager?->record_consent( (int) $prefs['customer_id'], $channel, 'marketing', 'opted_out', 'unsubscribe_link' );
                }
            }
        }

        return $response;
    }

    // =========================================================================
    // Infrastructure helpers
    // =========================================================================

    private function webpay_booking_page_url( int $payment_id, string $result, int $booking_id = 0 ): string {
        $args = [ Setting_Keys::PAYMENT_NONCE_KEY => $result, 'payment_id' => $payment_id ];
        if ( $booking_id ) {
            $payment = $this->payment_repo->find( $payment_id );
            $booking = $payment ? $this->booking_repo->find( $payment->booking_id ) : null;
            if ( $booking ) {
                $args['booking_id'] = $booking->id;
                $args[Setting_Keys::TOKEN_NONCE_KEY] = $booking->view_token ?: $booking->booking_token;
            }
        }
        return \OpenBooking\Support\Public_Booking_Page::get_url( $args );
    }

    private function make_redirect_response( string $url ): \WP_REST_Response {
        $response = new \WP_REST_Response( null, 302 );
        $response->header( 'Location', $url );
        return $response;
    }

    private function timed( string $endpoint, callable $fn ): \WP_REST_Response {
        $start = microtime( true );
        $response = $fn();
        $ms       = ( microtime( true ) - $start ) * 1000;
        $is_error = $response->get_status() >= 400;
        \OpenBooking\Infrastructure\WordPress\Metrics\Request_Metrics::record( $endpoint, $ms, $is_error );
        return $response;
    }

    private function check_public_rate_limit( string $action, int $max_attempts, int $ttl, string $message ): ?\WP_REST_Response {
        $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
        if ( '' === $ip ) {
            return null;
        }
        $limiter = $this->rate_limiter;
        if ( $limiter && ! $limiter->check( $action, $ip, $max_attempts, $ttl ) ) {
            return new \WP_REST_Response( [ 'error' => $message ], 429 );
        }
        return null;
    }

    private function verify_same_origin_post(): ?\WP_REST_Response {
        $origin  = sanitize_text_field( $_SERVER['HTTP_ORIGIN'] ?? '' );
        $referer = sanitize_text_field( $_SERVER['HTTP_REFERER'] ?? '' );
        $site    = untrailingslashit( get_site_url() );

        if ( '' === $origin && '' === $referer ) {
            return null;
        }

        $origin_host = $origin ? wp_parse_url( $origin, PHP_URL_HOST ) : '';
        $referer_host = $referer ? wp_parse_url( $referer, PHP_URL_HOST ) : '';
        $site_host    = wp_parse_url( $site, PHP_URL_HOST );

        $allowed = false;

        if ( $origin_host && strcasecmp( $origin_host, $site_host ) === 0 ) {
            $allowed = true;
        }
        if ( ! $allowed && $referer_host && strcasecmp( $referer_host, $site_host ) === 0 ) {
            $allowed = true;
        }

        if ( ! $allowed && ( $origin_host || $referer_host ) ) {
            return new \WP_REST_Response( [ 'error' => 'Origen no permitido.' ], 403 );
        }

        return null;
    }
}
