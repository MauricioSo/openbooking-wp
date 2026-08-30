<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Persistence\System;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de admin.
 */

class System_Status_Repository {

    private \wpdb $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function audit_table(): string {
        return $this->wpdb->prefix . 'ob_audit_logs';
    }

    public function notification_table(): string {
        return $this->wpdb->prefix . 'ob_notification_logs';
    }

    public function audit_count(): int {
        return (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->audit_table() );
    }

    public function failed_notifications_last_7_days(): int {
        return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->notification_table()} WHERE status = 'failed' AND sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)" );
    }

    public function rejected_webhooks_last_7_days(): int {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->audit_table() . ' WHERE action = %s AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)', 'gateway_webhook_rejected' )
        );
    }

    public function stale_pending_bookings(): int {
        return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}ob_bookings WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()" );
    }

    public function booking_payment_inconsistencies(): int {
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$this->wpdb->prefix}ob_bookings b
             INNER JOIN {$this->wpdb->prefix}ob_payments p ON p.booking_id = b.id
             WHERE (
                (p.status = 'paid' AND (b.payment_status != 'paid' OR b.status != 'confirmed'))
                OR (p.status = 'failed' AND b.payment_status != 'failed')
                OR (p.status = 'expired' AND b.payment_status != 'expired')
             )"
        );
    }

    public function queue_pending(): int {
        return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}ob_notification_queue WHERE status = 'pending'" );
    }

    public function queue_failed(): int {
        return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->prefix}ob_notification_queue WHERE status = 'failed'" );
    }

    public function table_exists( string $table ): bool {
        return $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }
}
