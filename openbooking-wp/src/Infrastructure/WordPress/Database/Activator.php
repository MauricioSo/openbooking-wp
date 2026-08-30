<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Database;

use OpenBooking\Support\Setting_Keys;
use OpenBooking\Support\Option_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gestiona activacion, migraciones y esquema de base de datos.
 */
class Activator {

    public const SCHEMA_VERSION = 19;

    public function activate(): void {
        $this->run_migrations();
        $this->set_default_options();
    }

    public static function needs_migration(): bool {
        return self::get_schema_version() < self::SCHEMA_VERSION;
    }

    public static function get_schema_version(): int {
        return (int) get_option( Option_Keys::SCHEMA_VERSION, 0 );
    }

    private function run_migrations(): void {
        $current_version = self::get_schema_version();
        $migrations = [
            1 => 'migration_001_initial_schema',
            2 => 'migration_002_concurrency_hardening',
            3 => 'migration_003_notification_dashboard',
            4 => 'migration_004_token_security_and_queue_priority',
            5 => 'migration_005_payment_attempt_ledger',
            6 => 'migration_006_view_token_and_cron_system',
            7 => 'migration_007_availability_snapshots_and_archive',
            8 => 'migration_008_notification_log_and_feature_flags',
            9 => 'migration_009_booking_token_self_service',
            10 => 'migration_010_payment_checkout_hardening',
            11 => 'migration_011_attendance_confirmation',
            12 => 'migration_012_notification_idempotency',
            13 => 'migration_013_hardening_dedupe_consent_sms',
            14 => 'migration_014_slot_locks',
            15 => 'migration_015_customer_whatsapp_opt_in',
            16 => 'migration_016_booking_start_status_index',
            17 => 'migration_017_drop_redundant_token_indexes',
            18 => 'migration_018_integration_api_hardening',
            19 => 'migration_019_outbox_events',
        ];

        foreach ( $migrations as $version => $method ) {
            if ( $current_version >= $version ) {
                continue;
            }

            $this->{$method}();
            $this->assert_schema_for_version( $version );
            update_option( Option_Keys::SCHEMA_VERSION, $version, false );
            $current_version = $version;
        }
    }

    private function assert_schema_for_version( int $version ): void {
        global $wpdb;

        $required = match ( $version ) {
            1 => [
                'ob_services',
                'ob_resources',
                'ob_customers',
                'ob_bookings',
                'ob_payments',
                'ob_audit_logs',
            ],
            14 => [ 'ob_slot_locks' ],
            18 => [ 'ob_integration_clients', 'ob_integration_request_logs' ],
            19 => [ 'ob_outbox_events' ],
            default => [],
        };

        foreach ( $required as $suffix ) {
            $table = $wpdb->prefix . $suffix;
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $found !== $table ) {
                throw new \RuntimeException( 'OpenBooking schema migration failed; missing table: ' . $table );
            }
        }
    }

    private function migration_001_initial_schema(): void {
        $this->create_tables();
    }

    /**
     * Adds DB-level concurrency guards:
     *  - client_ref column + UNIQUE key on bookings (idempotency for double-submit).
     *  - UNIQUE key on payments(gateway, provider_payment_id) (webhook deduplication).
     */
    private function migration_002_concurrency_hardening(): void {
        global $wpdb;
        $bookings    = $wpdb->prefix . 'ob_bookings';
        $payments    = $wpdb->prefix . 'ob_payments';
        $rate_limits = $wpdb->prefix . 'ob_rate_limits';

        // ── bookings: client_ref column ──────────────────────────────────────
        $col = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'client_ref'",
            DB_NAME, $bookings
        ) );
        if ( empty( $col ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL does not support placeholders; table name derived from $wpdb->prefix + hardcoded suffix; esc_sql provides a defensive layer.
            $wpdb->query( 'ALTER TABLE `' . esc_sql( $bookings ) . '` ADD COLUMN client_ref VARCHAR(64) NULL DEFAULT NULL' );
        }

        // ── bookings: UNIQUE key on client_ref ───────────────────────────────
        $idx = $wpdb->get_results( $wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_client_ref'",
            DB_NAME, $bookings
        ) );
        if ( empty( $idx ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( 'ALTER TABLE `' . esc_sql( $bookings ) . '` ADD UNIQUE KEY uniq_client_ref (client_ref)' );
        }

        // ── bookings: composite index for conflict check ─────────────────────
        // Covers (service_id, status, start_at, end_at) so has_conflict_locked()
        // is a pure index lookup instead of a partial scan.
        $cidx = $wpdb->get_results( $wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_conflict_check'",
            DB_NAME, $bookings
        ) );
        if ( empty( $cidx ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( 'ALTER TABLE `' . esc_sql( $bookings ) . '` ADD INDEX idx_conflict_check (service_id, status, start_at, end_at)' );
        }

        // ── payments: UNIQUE key to prevent duplicate webhook records ─────────
        $pidx = $wpdb->get_results( $wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_gateway_payment'",
            DB_NAME, $payments
        ) );
        if ( empty( $pidx ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( 'ALTER TABLE `' . esc_sql( $payments ) . '` ADD UNIQUE KEY uniq_gateway_payment (gateway, provider_payment_id)' );
        }

        // ── rate_limits table ────────────────────────────────────────────────
        $charset_collate = $wpdb->get_charset_collate();
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta( "CREATE TABLE {$rate_limits} (
            key_hash   VARCHAR(64)          NOT NULL,
            count      INT UNSIGNED         NOT NULL DEFAULT 1,
            expires_at DATETIME             NOT NULL,
            PRIMARY KEY  (key_hash),
            KEY idx_expires (expires_at)
        ) {$charset_collate};" );
    }

    private function migration_003_notification_dashboard(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $queue_table       = $wpdb->prefix . 'ob_notification_queue';
        $prefs_table       = $wpdb->prefix . 'ob_notification_preferences';
        $campaigns_table   = $wpdb->prefix . 'ob_notification_campaigns';
        $logs_table        = $wpdb->prefix . 'ob_notification_logs';

        dbDelta( "CREATE TABLE {$queue_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            campaign_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NULL,
            channel VARCHAR(20) NOT NULL,
            template_key VARCHAR(60) NOT NULL,
            recipient VARCHAR(255) NOT NULL,
            scheduled_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
            last_attempted_at DATETIME NULL,
            sent_at DATETIME NULL,
            error_message TEXT NULL,
            payload LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_status_scheduled (status, scheduled_at),
            KEY idx_booking (booking_id),
            KEY idx_campaign (campaign_id),
            KEY idx_recipient (recipient(20))
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$prefs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            channel_email TINYINT(1) NOT NULL DEFAULT 1,
            channel_whatsapp TINYINT(1) NOT NULL DEFAULT 1,
            reminders TINYINT(1) NOT NULL DEFAULT 1,
            marketing TINYINT(1) NOT NULL DEFAULT 0,
            opt_out_token VARCHAR(64) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_customer_id (customer_id),
            KEY idx_customer (customer_id),
            KEY idx_opt_out_token (opt_out_token)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$campaigns_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(30) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message_email TEXT NULL,
            message_whatsapp TEXT NULL,
            scope_json LONGTEXT NULL,
            total_targets INT UNSIGNED NOT NULL DEFAULT 0,
            total_sent INT UNSIGNED NOT NULL DEFAULT 0,
            total_failed INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY idx_status (status)
        ) {$charset_collate};" );

        $this->add_column_if_missing( $logs_table, 'queue_id', 'BIGINT UNSIGNED NULL AFTER id' );
        $this->add_column_if_missing( $logs_table, 'campaign_id', 'BIGINT UNSIGNED NULL AFTER queue_id' );
        $this->add_column_if_missing( $logs_table, 'error_message', 'TEXT NULL AFTER status' );
        $this->add_column_if_missing( $logs_table, 'attempts', 'TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER error_message' );
        $this->add_index_if_missing( $logs_table, 'idx_recipient', 'recipient(20)' );
        $this->add_index_if_missing( $logs_table, 'idx_booking', 'booking_id' );
        $this->add_index_if_missing( $logs_table, 'idx_channel_status', 'channel, status' );
        $this->add_index_if_missing( $logs_table, 'idx_sent_at', 'sent_at' );
    }

    /**
     * Migration 004: Token security (TTL columns on bookings) + notification queue priority.
     *
     * - cancel_token_expires_at / reschedule_token_expires_at let the service enforce
     *   a configurable window after which public tokens no longer work.
     * - token_version increments on every rotation so stale copies fail.
     * - priority on notification_queue enables transactional-first ordering.
     * - booking_state_log table for full audit trail of status transitions.
     */
    private function migration_004_token_security_and_queue_priority(): void {
        global $wpdb;

        $bookings  = $wpdb->prefix . 'ob_bookings';
        $queue     = $wpdb->prefix . 'ob_notification_queue';
        $state_log = $wpdb->prefix . 'ob_booking_state_log';

        // ── bookings: token TTL columns ──────────────────────────────────────
        $this->add_column_if_missing( $bookings, 'cancel_token_expires_at', 'DATETIME NULL DEFAULT NULL AFTER cancel_token' );
        $this->add_column_if_missing( $bookings, 'reschedule_token_expires_at', 'DATETIME NULL DEFAULT NULL AFTER reschedule_token' );
        $this->add_column_if_missing( $bookings, 'token_version', 'TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER reschedule_token_expires_at' );
        $this->add_index_if_missing( $bookings, 'idx_cancel_token_exp', 'cancel_token, cancel_token_expires_at' );
        $this->add_index_if_missing( $bookings, 'idx_reschedule_token_exp', 'reschedule_token, reschedule_token_expires_at' );

        // ── notification_queue: priority column ──────────────────────────────
        // 1=critical (booking_confirmed, payment_received), 5=normal, 10=bulk/reminder
        $this->add_column_if_missing( $queue, 'priority', 'TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER template_key' );
        $this->add_index_if_missing( $queue, 'idx_priority_scheduled', 'priority, status, scheduled_at' );

        // ── booking_state_log: formal audit of every booking status transition ─
        $charset_collate = $wpdb->get_charset_collate();
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta( "CREATE TABLE {$state_log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            from_status VARCHAR(30) NULL,
            to_status VARCHAR(30) NOT NULL,
            from_payment_status VARCHAR(30) NULL,
            to_payment_status VARCHAR(30) NULL,
            actor_type VARCHAR(30) NOT NULL DEFAULT 'system',
            actor_id BIGINT UNSIGNED NULL,
            reason VARCHAR(191) NULL,
            request_id VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_booking (booking_id),
            KEY idx_created (created_at)
        ) {$charset_collate};" );
    }

    /**
     * Migration 005: Payment attempt ledger.
     *
     * Introduces `wp_ob_payment_attempts` — an append-only log of every gateway
     * call for a booking.  This separates the *current* payment state (kept on
     * `wp_ob_payments`) from the *full history* of what was tried, which is
     * required to support chargebacks, disputes, and partial-refund flows without
     * over-loading the payments aggregate.
     *
     * Also adds a `disputed_at` timestamp to `wp_ob_payments` so admin can see
     * when a chargeback was received.
     */
    private function migration_005_payment_attempt_ledger(): void {
        global $wpdb;

        $payments  = $wpdb->prefix . 'ob_payments';
        $attempts  = $wpdb->prefix . 'ob_payment_attempts';

        $charset_collate = $wpdb->get_charset_collate();
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        // ── payment_attempts table ────────────────────────────────────────────
        // Each row is one gateway call.  Multiple failed attempts before a
        // successful one are perfectly normal.
        dbDelta( "CREATE TABLE {$attempts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id BIGINT UNSIGNED NOT NULL,
            booking_id BIGINT UNSIGNED NOT NULL,
            gateway VARCHAR(40) NOT NULL,
            amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            status VARCHAR(30) NOT NULL DEFAULT 'initiated',
            gateway_ref VARCHAR(191) NULL,
            gateway_response LONGTEXT NULL,
            ip_address VARCHAR(45) NULL,
            initiated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_payment (payment_id),
            KEY idx_booking (booking_id),
            KEY idx_gateway_ref (gateway_ref(40)),
            KEY idx_status (status)
        ) {$charset_collate};" );

        // ── payments: dispute tracking columns ────────────────────────────────
        $this->add_column_if_missing( $payments, 'disputed_at', 'DATETIME NULL DEFAULT NULL' );
        $this->add_column_if_missing( $payments, 'dispute_reason', 'VARCHAR(191) NULL DEFAULT NULL' );
    }

    /**
     * Migration 006: View token (read-only, long-lived) + cron system-cron flag.
     *
     * - view_token / view_token_expires_at on bookings: a dedicated read-only token
     *   separate from the cancel and reschedule tokens.  Sending the view token in a
     *   confirmation email does not grant the holder permission to cancel or reschedule.
     * - obwp_system_cron_detected option stored by the plugin itself to distinguish
     *   real system cron from WordPress pseudo-cron (improves cron health reporting).
     */
    private function migration_006_view_token_and_cron_system(): void {
        global $wpdb;
        $bookings = $wpdb->prefix . 'ob_bookings';

        $this->add_column_if_missing( $bookings, 'view_token', 'VARCHAR(64) NULL DEFAULT NULL AFTER reschedule_token_expires_at' );
        $this->add_column_if_missing( $bookings, 'view_token_expires_at', 'DATETIME NULL DEFAULT NULL AFTER view_token' );
        $this->add_index_if_missing( $bookings, 'idx_view_token', 'view_token' );
    }

    private function migration_007_availability_snapshots_and_archive(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $snapshots = $wpdb->prefix . 'ob_availability_snapshots';
        dbDelta( "CREATE TABLE {$snapshots} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope_type VARCHAR(20) NOT NULL DEFAULT 'global',
            scope_id BIGINT UNSIGNED NULL,
            label VARCHAR(191) NULL,
            rules_json LONGTEXT NOT NULL,
            blocks_json LONGTEXT NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_scope (scope_type, scope_id),
            KEY idx_created (created_at)
        ) {$charset_collate};" );

        $services  = $wpdb->prefix . 'ob_services';
        $resources = $wpdb->prefix . 'ob_resources';

        $this->add_column_if_missing( $services, 'archived_at', 'DATETIME NULL DEFAULT NULL AFTER updated_at' );
        $this->add_column_if_missing( $resources, 'archived_at', 'DATETIME NULL DEFAULT NULL AFTER updated_at' );
    }

    private function add_column_if_missing( string $table, string $column, string $definition ): void {
        global $wpdb;

        $exists = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table,
            $column
        ) );

        if ( empty( $exists ) ) {
            $wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . '` ADD COLUMN ' . $column . ' ' . $definition );
        }
    }

    private function add_index_if_missing( string $table, string $index_name, string $definition ): void {
        global $wpdb;

        $exists = $wpdb->get_results( $wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
            DB_NAME,
            $table,
            $index_name
        ) );

        if ( empty( $exists ) ) {
            $wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . '` ADD INDEX ' . $index_name . ' (' . $definition . ')' );
        }
    }

    private function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'ob_';

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $sql = [];

        $sql[] = "CREATE TABLE {$prefix}services (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            description TEXT NULL,
            duration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
            buffer_before_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            buffer_after_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            price_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            capacity INT UNSIGNED NOT NULL DEFAULT 1,
            mode VARCHAR(20) NOT NULL DEFAULT 'presencial',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            color VARCHAR(20) NULL,
            visibility VARCHAR(20) NOT NULL DEFAULT 'public',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY idx_status (status)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}resources (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'person',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            capacity INT UNSIGNED NOT NULL DEFAULT 1,
            meta JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_status (status)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}service_resources (
            service_id BIGINT UNSIGNED NOT NULL,
            resource_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (service_id, resource_id)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}customers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NULL,
            email VARCHAR(191) NOT NULL,
            phone VARCHAR(50) NULL,
            notes TEXT NULL,
            whatsapp_opt_in TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_email (email)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}bookings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            service_id BIGINT UNSIGNED NOT NULL,
            resource_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            payment_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            start_at DATETIME NOT NULL,
            end_at DATETIME NOT NULL,
            timezone VARCHAR(100) NOT NULL DEFAULT 'UTC',
            price_total_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            price_due_now_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            price_paid_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            source VARCHAR(30) NOT NULL DEFAULT 'public',
            notes_customer TEXT NULL,
            notes_internal TEXT NULL,
            cancel_token VARCHAR(191) NULL,
            reschedule_token VARCHAR(191) NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_service_time (service_id, start_at),
            KEY idx_resource_time (resource_id, start_at),
            KEY idx_customer (customer_id),
            KEY idx_status (status),
            KEY idx_start_status (start_at, status),
            KEY idx_expires (expires_at)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}booking_meta (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(191) NOT NULL,
            meta_value LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY idx_booking_meta (booking_id, meta_key)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}payments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            gateway VARCHAR(50) NOT NULL,
            provider_payment_id VARCHAR(191) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            mode VARCHAR(30) NOT NULL DEFAULT 'full',
            raw_payload LONGTEXT NULL,
            paid_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_booking (booking_id),
            KEY idx_provider_payment_id (provider_payment_id),
            KEY idx_status (status)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}availability_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope_type VARCHAR(20) NOT NULL DEFAULT 'global',
            scope_id BIGINT UNSIGNED NULL,
            rule_type VARCHAR(30) NOT NULL DEFAULT 'weekly',
            weekday TINYINT NULL,
            date_from DATE NULL,
            date_to DATE NULL,
            time_from TIME NULL,
            time_to TIME NULL,
            capacity INT NULL,
            meta JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_scope (scope_type, scope_id),
            KEY idx_rule_type (rule_type)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}blocks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope_type VARCHAR(20) NOT NULL DEFAULT 'global',
            scope_id BIGINT UNSIGNED NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME NOT NULL,
            reason VARCHAR(191) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_scope (scope_type, scope_id),
            KEY idx_time (start_at, end_at)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}notification_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NULL,
            channel VARCHAR(20) NOT NULL DEFAULT 'email',
            template_key VARCHAR(100) NOT NULL,
            recipient VARCHAR(191) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            payload LONGTEXT NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_booking (booking_id),
            KEY idx_template (template_key)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}audit_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            actor_type VARCHAR(30) NOT NULL DEFAULT 'system',
            actor_id BIGINT UNSIGNED NULL,
            message TEXT NULL,
            context_json LONGTEXT NULL,
            request_id VARCHAR(64) NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'info',
            source VARCHAR(30) NOT NULL DEFAULT 'system',
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            route VARCHAR(191) NULL,
            http_method VARCHAR(10) NULL,
            changed_fields_json LONGTEXT NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_action (action),
            KEY idx_actor (actor_type, actor_id),
            KEY idx_created_at (created_at),
            KEY idx_request_id (request_id),
            KEY idx_entity_action (entity_type, entity_id, action),
            KEY idx_actor_created (actor_type, actor_id, created_at)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}ui_presets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            config_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE {$prefix}form_fields (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            field_key VARCHAR(100) NOT NULL,
            label VARCHAR(191) NOT NULL,
            placeholder VARCHAR(191) NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            config_json LONGTEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_field_key (field_key)
        ) $charset_collate;";

        foreach ( $sql as $query ) {
            dbDelta( $query );
        }
    }

    private function set_default_options(): void {
        $defaults = [
            Setting_Keys::BUSINESS_COUNTRY   => '',
            Setting_Keys::BUSINESS_CURRENCY  => 'USD',
            Setting_Keys::BUSINESS_TIMEZONE  => 'UTC',
            Setting_Keys::BUSINESS_LANGUAGE  => 'es',
            Setting_Keys::PAYMENT_MODE       => 'full',
            Setting_Keys::ENABLED_GATEWAYS   => [],
            Setting_Keys::AUDIT_LOG_RETENTION => 0,
            Setting_Keys::NOTIFICATION_DASHBOARD_ENABLED => 1,
            Setting_Keys::OUTBOX_RECORD_EVENTS => 0,
            Setting_Keys::OUTBOX_WORKER_ENABLED => 0,
            Setting_Keys::ASYNC_OUTBOUND_WEBHOOKS => 0,
            Setting_Keys::OUTBOX_RETENTION_DAYS => 7,
            Setting_Keys::BOOKING_ORCHESTRATOR_ENABLED => 1,
        ];

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key, false ) ) {
                add_option( $key, $value );
            }
        }

        if ( false === get_option( Setting_Keys::UI_CONFIG, false ) ) {
            add_option( Setting_Keys::UI_CONFIG, [
                'preset'       => 'minimal',
                'color_primary' => '#111111',
                'color_bg'     => '#ffffff',
                'color_text'   => '#1f1f1f',
                'font_family'  => 'Inter, sans-serif',
                'radius'       => '12px',
                'layout'       => 'steps',
            ] );
        }

        $this->seed_form_fields();
        $this->seed_default_preset();
    }

    private function seed_form_fields(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ob_form_fields';

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) {
            return;
        }

        $fields = [
            [ 'first_name', 'Tu nombre', 'Escribe tu nombre', 1, 1, 0 ],
            [ 'email', 'Correo electrónico', 'tu@correo.com', 1, 1, 1 ],
            [ 'phone', 'Teléfono', '+56 9 1234 5678', 0, 1, 2 ],
            [ 'notes', 'Notas', 'Algo que debas saber...', 0, 1, 3 ],
        ];

        foreach ( $fields as $f ) {
            $wpdb->insert( $table, [
                'field_key'    => $f[0],
                'label'        => $f[1],
                'placeholder'  => $f[2],
                'is_required'  => $f[3],
                'is_enabled'   => $f[4],
                'sort_order'   => $f[5],
            ] );
        }
    }

    private function seed_default_preset(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ob_ui_presets';

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) {
            return;
        }

        $wpdb->insert( $table, [
            'name'       => 'Minimal',
            'is_default' => 1,
            'config_json' => wp_json_encode( [
                'color_primary'  => '#111111',
                'color_bg'       => '#ffffff',
                'color_text'     => '#1f1f1f',
                'font_family'    => 'Inter, sans-serif',
                'radius'         => '12px',
            ] ),
        ] );
    }

    private function migration_008_notification_log_and_feature_flags(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $notification_log = $wpdb->prefix . 'ob_notification_log';
        dbDelta( "CREATE TABLE {$notification_log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NULL,
            channel VARCHAR(30) NOT NULL DEFAULT 'email',
            template_key VARCHAR(100) NOT NULL,
            recipient VARCHAR(191) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            provider_id VARCHAR(191) NULL,
            error_message TEXT NULL,
            retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_booking (booking_id),
            KEY idx_status (status),
            KEY idx_channel_template (channel, template_key),
            KEY idx_sent_at (sent_at),
            KEY idx_created (created_at)
        ) {$charset_collate};" );

        $feature_flags = $wpdb->prefix . 'ob_feature_flags';
        dbDelta( "CREATE TABLE {$feature_flags} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            flag_key VARCHAR(100) NOT NULL,
            value LONGTEXT NOT NULL DEFAULT 'false',
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_flag_key (flag_key)
        ) {$charset_collate};" );

        $defaults = [
            'safe_mode'              => 'false',
            'maintenance_mode'       => 'false',
            'disable_online_payment' => 'false',
            'disable_notifications'  => 'false',
            'readonly_booking_page'  => 'false',
        ];
        foreach ( $defaults as $key => $val ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$feature_flags} WHERE flag_key = %s",
                $key
            ) );
            if ( ! $exists ) {
                $wpdb->insert( $feature_flags, [
                    'flag_key' => $key,
                    'value'    => $val,
                ] );
            }
        }

        if ( false === get_option( Setting_Keys::LOG_RETENTION_DAYS, false ) ) {
            add_option( Setting_Keys::LOG_RETENTION_DAYS, 90 );
        }
        if ( false === get_option( Setting_Keys::QUEUE_RETENTION_DAYS, false ) ) {
            add_option( Setting_Keys::QUEUE_RETENTION_DAYS, 30 );
        }
    }

    private function migration_009_booking_token_self_service(): void {
        global $wpdb;
        $bookings = $wpdb->prefix . 'ob_bookings';

        $this->add_column_if_missing( $bookings, 'booking_token', 'VARCHAR(64) NULL DEFAULT NULL AFTER view_token_expires_at' );
        $this->add_column_if_missing( $bookings, 'booking_token_expires_at', 'DATETIME NULL DEFAULT NULL AFTER booking_token' );
        $this->add_index_if_missing( $bookings, 'idx_booking_token', 'booking_token, booking_token_expires_at' );
    }

    private function migration_010_payment_checkout_hardening(): void {
        global $wpdb;
        $payments = $wpdb->prefix . 'ob_payments';

        $this->add_column_if_missing( $payments, 'provider_checkout_id', 'VARCHAR(191) NULL DEFAULT NULL AFTER disputed_at' );
        $this->add_column_if_missing( $payments, 'checkout_url', 'TEXT NULL AFTER provider_checkout_id' );
        $this->add_column_if_missing( $payments, 'checkout_expires_at', 'DATETIME NULL DEFAULT NULL AFTER checkout_url' );

        $wpdb->query( "UPDATE `{$payments}` SET provider_payment_id = NULL WHERE provider_payment_id = ''" );

        if ( false === get_option( Setting_Keys::CHECKOUT_TTL_MINUTES, false ) ) {
            add_option( Setting_Keys::CHECKOUT_TTL_MINUTES, 30 );
        }
        if ( false === get_option( Setting_Keys::FREE_BOOKING_EXPIRY, false ) ) {
            add_option( Setting_Keys::FREE_BOOKING_EXPIRY, 5 );
        }
    }

    private function migration_011_attendance_confirmation(): void {
        global $wpdb;
        $bookings = $wpdb->prefix . 'ob_bookings';

        $this->add_column_if_missing( $bookings, 'confirm_token', 'VARCHAR(64) NULL DEFAULT NULL AFTER booking_token_expires_at' );
        $this->add_column_if_missing( $bookings, 'attendance_confirmed_at', 'DATETIME NULL DEFAULT NULL AFTER confirm_token' );
        $this->add_index_if_missing( $bookings, 'idx_confirm_token', 'confirm_token' );
    }

    private function migration_012_notification_idempotency(): void {
        global $wpdb;
        $bookings = $wpdb->prefix . 'ob_bookings';
        $this->add_column_if_missing( $bookings, 'confirmed_email_sent', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER attendance_confirmed_at' );
        $this->add_column_if_missing( $bookings, 'confirmed_wa_sent', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER confirmed_email_sent' );
    }

    private function migration_013_hardening_dedupe_consent_sms(): void {
        global $wpdb;

        $queue_table   = $wpdb->prefix . 'ob_notification_queue';
        $prefs_table   = $wpdb->prefix . 'ob_notification_preferences';
        $consent_table = $wpdb->prefix . 'ob_consent_log';

        $this->add_column_if_missing( $queue_table, 'dedupe_key', 'VARCHAR(191) NULL DEFAULT NULL AFTER campaign_id' );

        $col_check = $wpdb->get_row( "SHOW COLUMNS FROM {$queue_table} LIKE 'dedupe_key'" );
        if ( $col_check ) {
            $wpdb->query( "UPDATE {$queue_table} q INNER JOIN (SELECT dedupe_key, MIN(id) AS keep_id FROM {$queue_table} WHERE dedupe_key IS NOT NULL AND dedupe_key <> '' GROUP BY dedupe_key HAVING COUNT(*) > 1) dup ON q.dedupe_key = dup.dedupe_key AND q.id <> dup.keep_id SET q.dedupe_key = NULL" );

            $existing_index = $wpdb->get_row( $wpdb->prepare( "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_dedupe_key'", $queue_table ) );
            if ( $existing_index ) {
                $wpdb->query( "ALTER TABLE {$queue_table} DROP INDEX idx_dedupe_key" );
            }
            $wpdb->query( "ALTER TABLE {$queue_table} ADD UNIQUE KEY idx_dedupe_key (dedupe_key)" );
        }

        $this->add_column_if_missing( $prefs_table, 'channel_sms', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER channel_whatsapp' );

        $charset_collate = $wpdb->get_charset_collate();
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta( "CREATE TABLE {$consent_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            channel VARCHAR(20) NOT NULL,
            purpose VARCHAR(30) NOT NULL DEFAULT 'transactional',
            action VARCHAR(20) NOT NULL,
            source VARCHAR(60) NOT NULL DEFAULT '',
            source_text TEXT NULL,
            ip_hash VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_customer_channel (customer_id, channel, purpose),
            KEY idx_created_at (created_at)
        ) {$charset_collate};" );
    }

    private function migration_014_slot_locks(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $locks_table = $wpdb->prefix . 'ob_slot_locks';
        dbDelta( "CREATE TABLE {$locks_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            service_id BIGINT UNSIGNED NOT NULL,
            resource_id BIGINT UNSIGNED NULL,
            resource_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
            slot_start DATETIME NOT NULL,
            slot_end DATETIME NOT NULL,
            capacity_index INT UNSIGNED NOT NULL,
            booking_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'held',
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_slot_capacity (service_id, resource_key, slot_start, capacity_index),
            KEY idx_booking (booking_id),
            KEY idx_status_expires (status, expires_at),
            KEY idx_service_slot (service_id, slot_start),
            KEY idx_resource_slot (resource_key, slot_start)
        ) {$charset_collate};" );

        $bookings_table = $wpdb->prefix . 'ob_bookings';
        $active_statuses = [ 'pending', 'confirmed' ];
        $placeholders = implode( ',', array_fill( 0, count( $active_statuses ), '%s' ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, service_id, resource_id, start_at, end_at, status, expires_at
                 FROM {$bookings_table}
                 WHERE status IN ({$placeholders})
                 AND start_at > UTC_TIMESTAMP()",
                $active_statuses
            ),
            ARRAY_A
        );

        foreach ( $rows ?: [] as $row ) {
            $resource_key = ! empty( $row['resource_id'] ) ? (int) $row['resource_id'] : 0;
            $lock_status = $row['status'] === 'confirmed' ? 'confirmed' : 'held';
            $expires_at = $row['expires_at'] ?? null;

            $next_index = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(MAX(capacity_index), 0) + 1 FROM {$locks_table}
                     WHERE service_id = %d AND resource_key = %d AND slot_start = %s",
                    $row['service_id'],
                    $resource_key,
                    $row['start_at']
                )
            );

            $capacity_index = max( 1, $next_index );

            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$locks_table}
                     WHERE service_id = %d AND resource_key = %d AND slot_start = %s AND booking_id = %d",
                    $row['service_id'],
                    $resource_key,
                    $row['start_at'],
                    (int) $row['id']
                )
            );

            if ( $existing ) {
                continue;
            }

            $wpdb->insert( $locks_table, [
                'service_id'     => (int) $row['service_id'],
                'resource_id'    => ! empty( $row['resource_id'] ) ? (int) $row['resource_id'] : null,
                'resource_key'   => $resource_key,
                'slot_start'     => $row['start_at'],
                'slot_end'       => $row['end_at'],
                'capacity_index' => $capacity_index,
                'booking_id'     => (int) $row['id'],
                'status'         => $lock_status,
                'expires_at'     => $expires_at,
            ] );
        }
    }

    private function migration_015_customer_whatsapp_opt_in(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ob_customers';
        $col   = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'whatsapp_opt_in'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN whatsapp_opt_in TINYINT(1) NOT NULL DEFAULT 1" );
        }
    }

    private function migration_016_booking_start_status_index(): void {
        global $wpdb;
        $bookings = $wpdb->prefix . 'ob_bookings';
        $this->add_index_if_missing( $bookings, 'idx_start_status', 'start_at, status' );
    }

    private function migration_017_drop_redundant_token_indexes(): void {
        global $wpdb;
        $bookings = $wpdb->prefix . 'ob_bookings';

        $to_drop = [ 'idx_cancel_token', 'idx_reschedule_token' ];
        foreach ( $to_drop as $index_name ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
                DB_NAME, $bookings, $index_name
            ) );
            if ( $exists ) {
                $wpdb->query( 'ALTER TABLE `' . esc_sql( $bookings ) . '` DROP INDEX `' . esc_sql( $index_name ) . '`' );
            }
        }
    }

    private function migration_018_integration_api_hardening(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $clients_table = $wpdb->prefix . 'ob_integration_clients';
        dbDelta( "CREATE TABLE {$clients_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_key VARCHAR(80) NOT NULL,
            name VARCHAR(120) NOT NULL,
            secret_hash VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            allowed_scopes LONGTEXT NULL,
            allowed_ips LONGTEXT NULL,
            rate_limit_per_minute INT UNSIGNED NOT NULL DEFAULT 60,
            rate_limit_per_hour INT UNSIGNED NOT NULL DEFAULT 1000,
            last_used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_client_key (client_key),
            KEY idx_status (status)
        ) {$charset_collate};" );

        $request_logs_table = $wpdb->prefix . 'ob_integration_request_logs';
        dbDelta( "CREATE TABLE {$request_logs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id VARCHAR(64) NOT NULL,
            client_id BIGINT UNSIGNED NULL,
            client_key VARCHAR(80) NULL,
            route VARCHAR(191) NOT NULL,
            http_method VARCHAR(10) NOT NULL,
            idempotency_key VARCHAR(191) NULL,
            body_hash VARCHAR(64) NULL,
            status_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            result_entity_type VARCHAR(50) NULL,
            result_entity_id BIGINT UNSIGNED NULL,
            error_code VARCHAR(80) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_request_id (request_id),
            UNIQUE KEY uniq_client_idempotency (client_key, idempotency_key),
            KEY idx_client_created (client_key, created_at),
            KEY idx_entity (result_entity_type, result_entity_id),
            KEY idx_error (error_code)
        ) {$charset_collate};" );

        $bookings = $wpdb->prefix . 'ob_bookings';
        $this->add_column_if_missing( $bookings, 'integration_client_key', 'VARCHAR(80) NULL DEFAULT NULL' );
        $this->add_column_if_missing( $bookings, 'integration_request_id', 'VARCHAR(64) NULL DEFAULT NULL' );
        $this->add_column_if_missing( $bookings, 'external_id', 'VARCHAR(191) NULL DEFAULT NULL' );
        $this->add_column_if_missing( $bookings, 'created_via', "VARCHAR(30) NOT NULL DEFAULT 'core'" );
        $this->add_index_if_missing( $bookings, 'idx_integration_request', 'integration_request_id' );
        $this->add_index_if_missing( $bookings, 'idx_integration_client', 'integration_client_key' );
        $this->add_unique_if_missing( $bookings, 'uniq_integration_external_id', 'integration_client_key, external_id' );
    }

    private function migration_019_outbox_events(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $outbox_table = $wpdb->prefix . 'ob_outbox_events';
        dbDelta( "CREATE TABLE {$outbox_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_name VARCHAR(120) NOT NULL,
            event_class VARCHAR(191) NOT NULL,
            aggregate_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            dedupe_key VARCHAR(64) NOT NULL,
            payload LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
            available_at DATETIME NOT NULL,
            locked_by VARCHAR(80) NULL,
            locked_at DATETIME NULL,
            last_error TEXT NULL,
            occurred_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_dedupe_key (dedupe_key),
            KEY idx_status_available (status, available_at),
            KEY idx_aggregate (aggregate_id),
            KEY idx_event_name (event_name),
            KEY idx_locked (locked_at)
        ) {$charset_collate};" );
    }

    private function add_unique_if_missing( string $table, string $index_name, string $definition ): void {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
            DB_NAME, $table, $index_name
        ) );
        if ( ! $exists ) {
            $wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . '` ADD UNIQUE KEY ' . $index_name . ' (' . $definition . ')' );
        }
    }

}
