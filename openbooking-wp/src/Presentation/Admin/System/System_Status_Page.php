<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Admin\System;

use OpenBooking\Support\Setting_Keys;
use OpenBooking\Support\Option_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renderiza la pagina de estado del sistema en admin.
 */
class System_Status_Page {

    public function render_system_status_page(): void {
        $status_repo = new \OpenBooking\Infrastructure\Persistence\System\System_Status_Repository();

        $audit_table = $status_repo->audit_table();
        $notification_table = $status_repo->notification_table();
        $audit_count = $status_repo->audit_count();
        $failed_notifications = $status_repo->failed_notifications_last_7_days();
        $rejected_webhooks = $status_repo->rejected_webhooks_last_7_days();
        $stale_pending = $status_repo->stale_pending_bookings();
        $reconciliation_count = $status_repo->booking_payment_inconsistencies();

        // KPI and queue metrics
        $db_kpis = \OpenBooking\Infrastructure\WordPress\Metrics\Request_Metrics::get_db_kpis();
        $endpoint_stats = \OpenBooking\Infrastructure\WordPress\Metrics\Request_Metrics::get_all_stats();

        // Queue backlog
        $queue_pending = $status_repo->queue_pending();
        $queue_failed  = $status_repo->queue_failed();

        // Cron heartbeat staleness
        $heartbeat_last = get_option( Option_Keys::CRON_HEARTBEAT_LAST, null );
        $heartbeat_ok   = $heartbeat_last && ( time() - (int) strtotime( $heartbeat_last ) ) < 10 * MINUTE_IN_SECONDS;

        $checks = [
            [ 'label' => 'Plugin version', 'status' => 'ok', 'value' => OBWP_VERSION ],
            [ 'label' => 'DB version', 'status' => 'ok', 'value' => (string) get_option( Option_Keys::DB_VERSION, '' ) ],
            [ 'label' => 'Schema version', 'status' => 'ok', 'value' => (string) get_option( Option_Keys::SCHEMA_VERSION, 0 ) ],
            [ 'label' => 'Audit table', 'status' => $status_repo->table_exists( $audit_table ) ? 'ok' : 'error', 'value' => $audit_table ],
            [ 'label' => 'Notification table', 'status' => $status_repo->table_exists( $notification_table ) ? 'ok' : 'error', 'value' => $notification_table ],
            [ 'label' => 'Audit retention', 'status' => 'ok', 'value' => (string) get_option( Setting_Keys::AUDIT_LOG_RETENTION, 0 ) . ' days' ],
            [ 'label' => 'Audit log count', 'status' => 'ok', 'value' => (string) $audit_count ],
            [ 'label' => 'Last cleanup cron', 'status' => get_option( Option_Keys::CRON_LAST_CLEANUP_LOGS, '' ) ? 'ok' : 'warning', 'value' => (string) get_option( Option_Keys::CRON_LAST_CLEANUP_LOGS, 'never' ) ],
            [ 'label' => 'Last expire pending cron', 'status' => get_option( Option_Keys::CRON_LAST_EXPIRE_PENDING, '' ) ? 'ok' : 'warning', 'value' => (string) get_option( Option_Keys::CRON_LAST_EXPIRE_PENDING, 'never' ) ],
            [ 'label' => 'Last reminders cron', 'status' => get_option( Option_Keys::CRON_LAST_SEND_REMINDERS, '' ) ? 'ok' : 'warning', 'value' => (string) get_option( Option_Keys::CRON_LAST_SEND_REMINDERS, 'never' ) ],
            [ 'label' => 'Last reconcile cron', 'status' => get_option( Option_Keys::CRON_LAST_RECONCILE_STATE, '' ) ? 'ok' : 'warning', 'value' => (string) get_option( Option_Keys::CRON_LAST_RECONCILE_STATE, 'never' ) ],
            [ 'label' => 'Failed notifications (7d)', 'status' => $failed_notifications > 0 ? 'warning' : 'ok', 'value' => (string) $failed_notifications ],
            [ 'label' => 'Rejected webhooks (7d)', 'status' => $rejected_webhooks > 0 ? 'warning' : 'ok', 'value' => (string) $rejected_webhooks ],
            [ 'label' => 'Expired pending bookings', 'status' => $stale_pending > 0 ? 'warning' : 'ok', 'value' => (string) $stale_pending ],
            [ 'label' => 'Booking/payment inconsistencies', 'status' => $reconciliation_count > 0 ? 'warning' : 'ok', 'value' => (string) $reconciliation_count ],
            [ 'label' => 'REST nonce mode', 'status' => 'ok', 'value' => 'cookie + nonce + capability' ],
            [ 'label' => 'Cron heartbeat', 'status' => $heartbeat_ok ? 'ok' : 'warning', 'value' => $heartbeat_last ?: 'never' ],
            [ 'label' => 'Notification queue pending', 'status' => $queue_pending > 100 ? 'warning' : 'ok', 'value' => (string) $queue_pending ],
            [ 'label' => 'Notification queue failed', 'status' => $queue_failed > 0 ? 'warning' : 'ok', 'value' => (string) $queue_failed ],
            [ 'label' => 'Bookings today', 'status' => 'ok', 'value' => (string) $db_kpis['bookings_today'] ],
            [ 'label' => 'Bookings last 7 days', 'status' => 'ok', 'value' => (string) $db_kpis['bookings_7d'] ],
        ];

        ?>
        <div class="wrap obwp-wrap">
            <h1><?php esc_html_e( 'System Status', 'openbooking-wp' ); ?></h1>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Check', 'openbooking-wp' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'openbooking-wp' ); ?></th>
                        <th><?php esc_html_e( 'Value', 'openbooking-wp' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $checks as $check ) : ?>
                    <tr>
                        <td><?php echo esc_html( $check['label'] ); ?></td>
                        <td><span class="obwp-status obwp-status--<?php echo esc_attr( $check['status'] ); ?>"><?php echo esc_html( $check['status'] ); ?></span></td>
                        <td><code><?php echo esc_html( $check['value'] ); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:2em"><?php esc_html_e( 'Endpoint Latency (last 2h)', 'openbooking-wp' ); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Endpoint', 'openbooking-wp' ); ?></th>
                        <th><?php esc_html_e( 'Requests', 'openbooking-wp' ); ?></th>
                        <th><?php esc_html_e( 'Errors', 'openbooking-wp' ); ?></th>
                        <th><?php esc_html_e( 'Err %', 'openbooking-wp' ); ?></th>
                        <th><?php esc_html_e( 'p50 ms', 'openbooking-wp' ); ?></th>
                        <th><?php esc_html_e( 'p95 ms', 'openbooking-wp' ); ?></th>
                        <th><?php esc_html_e( 'max ms', 'openbooking-wp' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $endpoint_stats as $stat ) : ?>
                    <?php $err_status = ( $stat['error_rate'] > 5 ) ? 'warning' : 'ok'; ?>
                    <tr>
                        <td><code><?php echo esc_html( $stat['endpoint'] ); ?></code></td>
                        <td><?php echo esc_html( (string) $stat['count'] ); ?></td>
                        <td><span class="obwp-status obwp-status--<?php echo esc_attr( $err_status ); ?>"><?php echo esc_html( (string) $stat['error_count'] ); ?></span></td>
                        <td><?php echo esc_html( $stat['error_rate'] . '%' ); ?></td>
                        <td><?php echo esc_html( null !== $stat['p50_ms'] ? $stat['p50_ms'] . ' ms' : '-' ); ?></td>
                        <td><?php echo esc_html( null !== $stat['p95_ms'] ? $stat['p95_ms'] . ' ms' : '-' ); ?></td>
                        <td><?php echo esc_html( null !== $stat['max_ms'] ? $stat['max_ms'] . ' ms' : '-' ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

}
