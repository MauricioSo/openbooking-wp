<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( get_option( 'obwp_uninstall_remove_data', false ) ) {
    global $wpdb;

    $tables = [
        // Created in migration_001
        'ob_form_fields',
        'ob_ui_presets',
        'ob_notification_logs',
        'ob_audit_logs',
        'ob_blocks',
        'ob_availability_rules',
        'ob_booking_meta',
        'ob_payments',
        'ob_bookings',
        'ob_service_resources',
        'ob_customers',
        'ob_resources',
        'ob_services',
        // Created in migration_002
        'ob_rate_limits',
        // Created in migration_003
        'ob_notification_campaigns',
        'ob_notification_preferences',
        'ob_notification_queue',
        // Created in migration_004
        'ob_booking_state_log',
        // Created in migration_005
        'ob_payment_attempts',
        // Created in migration_007
        'ob_availability_snapshots',
        // Created in migration_008
        'ob_feature_flags',
        'ob_notification_log',
        // Created in migration_013
        'ob_consent_log',
        // Created in migration_014
        'ob_slot_locks',
        // Created in migration_018
        'ob_integration_request_logs',
        'ob_integration_clients',
        // Created in migration_019
        'ob_outbox_events',
    ];

    foreach ( $tables as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
    }

    $options = [
        // Core
        'obwp_db_version', 'obwp_schema_version', 'obwp_onboarding_done', 'obwp_show_onboarding', 'obwp_onboarding_preset', 'obwp_uninstall_remove_data',
        'obwp_system_cron_detected',
        // Business
        'obwp_business_name', 'obwp_business_country', 'obwp_business_currency',
        'obwp_business_timezone', 'obwp_business_language',
        // Payments
        'obwp_payment_mode', 'obwp_enabled_gateways', 'obwp_deposit_percent', 'obwp_checkout_ttl_minutes',
        // Stripe credentials
        'obwp_stripe_secret_key', 'obwp_stripe_publishable_key', 'obwp_stripe_webhook_secret', 'obwp_stripe_test_mode_verified',
        // MercadoPago credentials
        'obwp_mp_access_token', 'obwp_mp_sandbox', 'obwp_mp_webhook_secret',
        // Webpay credentials
        'obwp_webpay_commerce_code', 'obwp_webpay_api_key', 'obwp_webpay_sandbox', 'obwp_webpay_return_url_verified', 'obwp_webpay_sandbox_verified',
        // Email settings
        'obwp_email_sender_name', 'obwp_email_sender_address', 'obwp_email_html_enabled', 'obwp_email_accent_color', 'obwp_public_booking_page_url',
        // Privacy / consent
        'obwp_privacy_policy_url', 'obwp_privacy_consent_required',
        // Notifications & retention
        'obwp_booking_expiry_minutes', 'obwp_free_booking_expiry_minutes', 'obwp_reminder_hours_before', 'obwp_notification_log_retention_days',
        // Policies
        'obwp_cancel_min_hours', 'obwp_reschedule_min_hours',
        // SMS / Twilio
        'obwp_sms_enabled', 'obwp_sms_provider',
        'obwp_sms_twilio_sid', 'obwp_sms_twilio_token', 'obwp_sms_twilio_from', 'obwp_sms_admin_phone',
        // WhatsApp own API credentials
        'obwp_whatsapp_enabled', 'obwp_whatsapp_provider', 'obwp_whatsapp_notify_admin', 'obwp_whatsapp_admin_phone',
        'obwp_whatsapp_twilio_sid', 'obwp_whatsapp_twilio_token', 'obwp_whatsapp_twilio_from',
        'obwp_whatsapp_meta_token', 'obwp_whatsapp_meta_phone_id', 'obwp_whatsapp_meta_use_templates', 'obwp_whatsapp_meta_language',
        // Design
        'obwp_ui_config',
        // Cron last runs
        'obwp_cron_last_run_expire_pending', 'obwp_cron_last_run_send_reminders', 'obwp_cron_last_run_cleanup_logs',
        'obwp_cron_last_run_reconcile_state', 'obwp_cron_last_run_process_notification_queue',
        'obwp_cron_last_run_expire_stale_locks', 'obwp_cron_last_run_process_outbox', 'obwp_cron_last_run_data_retention', 'obwp_cron_heartbeat_last',
        // Cron alert rate-limits
        'obwp_queue_backlog_last_warn', 'obwp_webhook_failure_last_warn',
        // Retention
        'obwp_audit_log_retention_days', 'obwp_notification_log_retention_days',
        'obwp_log_retention_days', 'obwp_queue_retention_days', 'obwp_booking_retention_days', 'obwp_token_retention_days',
        // Outbox / webhooks / integration
        'obwp_outbox_record_events', 'obwp_outbox_worker_enabled', 'obwp_async_outbound_webhooks', 'obwp_outbox_processed_retention_days',
        'obwp_webhook_endpoints', 'obwp_outbound_webhook_domain_allowlist',
        // Feature flags & misc
        'obwp_notification_dashboard_enabled', 'obwp_booking_orchestrator_enabled', 'obwp_token_ttl_hours', 'obwp_view_token_ttl_hours', 'obwp_skip_email_dns_check',
    ];

    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Email templates (dynamic keys)
    $template_keys = [
        'booking_confirmed', 'booking_cancelled', 'booking_rescheduled',
        'payment_received', 'payment_failed', 'reminder_customer', 'new_booking_admin',
    ];
    foreach ( $template_keys as $key ) {
        delete_option( 'obwp_email_template_' . $key );
    }

    // WhatsApp templates (dynamic keys)
    $whatsapp_keys = [ 'booking_confirmed', 'booking_cancelled', 'booking_rescheduled', 'payment_received', 'reminder_customer', 'new_booking_admin' ];
    foreach ( $whatsapp_keys as $key ) {
        delete_option( 'obwp_whatsapp_template_' . $key );
        delete_option( 'obwp_whatsapp_meta_tpl_' . $key );
    }

    // SMS templates (dynamic keys)
    $sms_keys = [ 'booking_confirmed', 'booking_cancelled', 'booking_rescheduled', 'payment_received', 'reminder_customer', 'new_booking_admin' ];
    foreach ( $sms_keys as $key ) {
        delete_option( 'obwp_sms_template_' . $key );
    }

    // Transients and cache version keys
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_obwp_%' OR option_name LIKE '_transient_timeout_obwp_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'obwp_avail_ver_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'obwp_stripe_event_%' OR option_name LIKE 'obwp_mp_%' OR option_name LIKE 'obwp_webhook_ip_allowlist_%'" );

    // WP Cron events
    wp_clear_scheduled_hook( 'obwp_cron_expire_pending' );
    wp_clear_scheduled_hook( 'obwp_cron_send_reminders' );
    wp_clear_scheduled_hook( 'obwp_cron_cleanup_logs' );
    wp_clear_scheduled_hook( 'obwp_cron_reconcile_state' );
    wp_clear_scheduled_hook( 'obwp_cron_process_notification_queue' );
    wp_clear_scheduled_hook( 'obwp_cron_heartbeat' );
    wp_clear_scheduled_hook( 'obwp_cron_expire_stale_locks' );
    wp_clear_scheduled_hook( 'obwp_cron_process_outbox' );
    wp_clear_scheduled_hook( 'obwp_cron_data_retention' );

    delete_transient( 'obwp_show_onboarding' );
}
