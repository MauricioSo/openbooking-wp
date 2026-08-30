<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Constantes para todas las claves de opciones del plugin.
 */
class Setting_Keys {

    // ─── Negocio ──────────────────────────────────────
    public const BUSINESS_NAME            = 'obwp_business_name';
    public const BUSINESS_COUNTRY         = 'obwp_business_country';
    public const BUSINESS_CURRENCY        = 'obwp_business_currency';
    public const BUSINESS_TIMEZONE        = 'obwp_business_timezone';
    public const BUSINESS_LANGUAGE        = 'obwp_business_language';

    // ─── Email ────────────────────────────────────────
    public const EMAIL_SENDER_NAME        = 'obwp_email_sender_name';
    public const EMAIL_SENDER_ADDRESS     = 'obwp_email_sender_address';
    public const EMAIL_HTML_ENABLED       = 'obwp_email_html_enabled';
    public const EMAIL_ACCENT_COLOR       = 'obwp_email_accent_color';
    public const EMAIL_TEMPLATE_PREFIX    = 'obwp_email_template_';

    // ─── Pagina Publica ───────────────────────────────
    public const PUBLIC_BOOKING_PAGE_URL  = 'obwp_public_booking_page_url';
    public const UI_CONFIG                = 'obwp_ui_config';
    public const PRIVACY_POLICY_URL       = 'obwp_privacy_policy_url';
    public const PRIVACY_CONSENT_REQUIRED = 'obwp_privacy_consent_required';

    // ─── Uninstall ────────────────────────────────────
    public const UNINSTALL_REMOVE_DATA    = 'obwp_uninstall_remove_data';

    // ─── Cancelacion y Reagendamiento ─────────────────
    public const CANCEL_MIN_HOURS         = 'obwp_cancel_min_hours';
    public const RESCHEDULE_MIN_HOURS     = 'obwp_reschedule_min_hours';

    // ─── Booking ──────────────────────────────────────
    public const BOOKING_EXPIRY_MINUTES   = 'obwp_booking_expiry_minutes';
    public const FREE_BOOKING_EXPIRY      = 'obwp_free_booking_expiry_minutes';
    public const BOOKING_ORCHESTRATOR_ENABLED = 'obwp_booking_orchestrator_enabled';
    public const TOKEN_TTL_HOURS       = 'obwp_token_ttl_hours';
    public const VIEW_TOKEN_TTL_HOURS  = 'obwp_view_token_ttl_hours';
    public const SKIP_EMAIL_DNS_CHECK  = 'obwp_skip_email_dns_check';

    // ─── Recordatorios ────────────────────────────────
    public const REMINDER_HOURS_BEFORE    = 'obwp_reminder_hours_before';

    // ─── Notificaciones ───────────────────────────────
    public const NOTIFICATION_LOG_RETENTION  = 'obwp_notification_log_retention_days';
    public const NOTIFICATION_DASHBOARD_ENABLED = 'obwp_notification_dashboard_enabled';

    // ─── Auditoria ────────────────────────────────────
    public const AUDIT_LOG_RETENTION      = 'obwp_audit_log_retention_days';

    // ─── Outbox ───────────────────────────────────────
    public const OUTBOX_RECORD_EVENTS     = 'obwp_outbox_record_events';
    public const OUTBOX_WORKER_ENABLED    = 'obwp_outbox_worker_enabled';
    public const ASYNC_OUTBOUND_WEBHOOKS  = 'obwp_async_outbound_webhooks';
    public const OUTBOX_RETENTION_DAYS    = 'obwp_outbox_processed_retention_days';

    // ─── Pagos ────────────────────────────────────────
    public const PAYMENT_MODE             = 'obwp_payment_mode';
    public const DEPOSIT_PERCENT          = 'obwp_deposit_percent';
    public const ENABLED_GATEWAYS         = 'obwp_enabled_gateways';
    public const CHECKOUT_TTL_MINUTES     = 'obwp_checkout_ttl_minutes';

    // ─── Stripe ───────────────────────────────────────
    public const STRIPE_SECRET_KEY        = 'obwp_stripe_secret_key';
    public const STRIPE_PUBLISHABLE_KEY   = 'obwp_stripe_publishable_key';
    public const STRIPE_WEBHOOK_SECRET    = 'obwp_stripe_webhook_secret';
    public const STRIPE_TEST_MODE_VERIFIED = 'obwp_stripe_test_mode_verified';
    public const STRIPE_EVENT_PREFIX      = 'obwp_stripe_event_';

    // ─── MercadoPago ──────────────────────────────────
    public const MP_ACCESS_TOKEN          = 'obwp_mp_access_token';
    public const MP_SANDBOX               = 'obwp_mp_sandbox';
    public const MP_WEBHOOK_SECRET        = 'obwp_mp_webhook_secret';
    public const MP_PREFIX                = 'obwp_mp_';
    public const MP_EVENT_PREFIX          = 'obwp_mp_event_';

    // ─── Webpay ───────────────────────────────────────
    public const WEBPAY_COMMERCE_CODE     = 'obwp_webpay_commerce_code';
    public const WEBPAY_API_KEY           = 'obwp_webpay_api_key';
    public const WEBPAY_SANDBOX           = 'obwp_webpay_sandbox';
    public const WEBPAY_RETURN_URL_VERIFIED = 'obwp_webpay_return_url_verified';
    public const WEBPAY_SANDBOX_VERIFIED  = 'obwp_webpay_sandbox_verified';

    // ─── WhatsApp ─────────────────────────────────────
    public const WHATSAPP_ENABLED         = 'obwp_whatsapp_enabled';
    public const WHATSAPP_PROVIDER        = 'obwp_whatsapp_provider';
    public const WHATSAPP_NOTIFY_ADMIN    = 'obwp_whatsapp_notify_admin';
    public const WHATSAPP_ADMIN_PHONE     = 'obwp_whatsapp_admin_phone';
    public const WHATSAPP_TWILIO_SID      = 'obwp_whatsapp_twilio_sid';
    public const WHATSAPP_TWILIO_TOKEN    = 'obwp_whatsapp_twilio_token';
    public const WHATSAPP_TWILIO_FROM     = 'obwp_whatsapp_twilio_from';
    public const WHATSAPP_META_TOKEN      = 'obwp_whatsapp_meta_token';
    public const WHATSAPP_META_PHONE_ID   = 'obwp_whatsapp_meta_phone_id';
    public const WHATSAPP_META_USE_TEMPLATES = 'obwp_whatsapp_meta_use_templates';
    public const WHATSAPP_META_LANGUAGE   = 'obwp_whatsapp_meta_language';
    public const WHATSAPP_META_TPL_PREFIX = 'obwp_whatsapp_meta_tpl_';
    public const WHATSAPP_TEMPLATE_PREFIX = 'obwp_whatsapp_template_';

    // ─── SMS ──────────────────────────────────────────
    public const SMS_ENABLED              = 'obwp_sms_enabled';
    public const SMS_PROVIDER             = 'obwp_sms_provider';
    public const SMS_TWILIO_SID           = 'obwp_sms_twilio_sid';
    public const SMS_TWILIO_TOKEN         = 'obwp_sms_twilio_token';
    public const SMS_TWILIO_FROM          = 'obwp_sms_twilio_from';
    public const SMS_ADMIN_PHONE          = 'obwp_sms_admin_phone';
    public const SMS_TEMPLATE_PREFIX      = 'obwp_sms_template_';

    // ─── Disponibilidad ───────────────────────────────
    public const AVAIL_PREFIX          = 'obwp_avail_';
    public const AVAIL_GLOBAL_VERSION  = 'obwp_avail_global_ver';

    // ─── Prefijos de Plantilla ─────────────────────────
    public const TEMPLATE_CANCEL_PREFIX    = 'obwp_cancel';
    public const TEMPLATE_RESCHEDULE_PREFIX = 'obwp_reschedule';
    public const TEMPLATE_CONFIRM_PREFIX   = 'obwp_confirm';

    // ─── Nonces y Transacciones ────────────────────────
    public const PAYMENT_NONCE_KEY     = 'obwp_payment';
    public const TOKEN_NONCE_KEY       = 'obwp_token';

    // ─── REST API ──────────────────────────────────────
    public const REST_AUTH             = 'obwp_rest_auth';
    public const REST_FORBIDDEN        = 'obwp_rest_forbidden';
    public const REST_NONCE            = 'obwp_rest_nonce';

    // ─── Prefijo de Negocio ────────────────────────────
    public const BUSINESS_PREFIX       = 'obwp_business_';

    // ─── Webhooks ─────────────────────────────────────
    public const WEBHOOK_ENDPOINTS        = 'obwp_webhook_endpoints';
    public const OUTBOUND_WEBHOOK_DOMAIN_ALLOWLIST = 'obwp_outbound_webhook_domain_allowlist';
    public const WEBHOOK_IP_ALLOWLIST_PREFIX = 'obwp_webhook_ip_allowlist_';

    // ─── Retencion de Datos ───────────────────────────
    public const LOG_RETENTION_DAYS       = 'obwp_log_retention_days';
    public const QUEUE_RETENTION_DAYS     = 'obwp_queue_retention_days';
    public const BOOKING_RETENTION_DAYS   = 'obwp_booking_retention_days';
    public const TOKEN_RETENTION_DAYS     = 'obwp_token_retention_days';

    /**
     * Extrae el nombre de campo legible desde una clave de opcion.
     */
    public static function extract_field_name( string $key ): string {
        return str_replace( 'obwp_', '', $key );
    }
}
