<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Cron;

use OpenBooking\Support\Setting_Keys;
use OpenBooking\Support\Option_Keys;
use OpenBooking\Support\Cron_Hook_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Coordina las tareas programadas del plugin.
 */
class Cron_Manager implements \OpenBooking\Domain\Shared\Port\CronManagerInterface {

    private const QUEUE_BACKLOG_WARN = 200;

    private const HEARTBEAT_STALE_SECONDS = 600;

    private \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository $audit_repo;

    private \OpenBooking\Infrastructure\Integration\Outbox\Outbox_Event_Repository $outbox_repo;

    private \OpenBooking\Application\Availability\Service\Slot_Lock_Service $slot_lock_service;

    private \OpenBooking\Application\Core\Service\Outbox_Worker $outbox_worker;

    private ?\OpenBooking\Infrastructure\Notification\Notification_Manager $notification_manager;

    private ?\OpenBooking\Infrastructure\Notification\WhatsApp\WhatsApp_Service $whatsapp_service;

    private ?\OpenBooking\Infrastructure\Notification\SMS\SMS_Service $sms_service;

    private ?\OpenBooking\Application\Booking\Service\Booking_Admin_Service $booking_admin_service;

    private ?\OpenBooking\Application\Payment\Service\Payment_Service $payment_service;

    public function __construct(
        ?\OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository $audit_repo = null,
        ?\OpenBooking\Infrastructure\Integration\Outbox\Outbox_Event_Repository $outbox_repo = null,
        ?\OpenBooking\Application\Availability\Service\Slot_Lock_Service $slot_lock_service = null,
        ?\OpenBooking\Application\Core\Service\Outbox_Worker $outbox_worker = null,
        ?\OpenBooking\Infrastructure\Notification\Notification_Manager $notification_manager = null,
        ?\OpenBooking\Infrastructure\Notification\WhatsApp\WhatsApp_Service $whatsapp_service = null,
        ?\OpenBooking\Infrastructure\Notification\SMS\SMS_Service $sms_service = null,
        ?\OpenBooking\Application\Booking\Service\Booking_Admin_Service $booking_admin_service = null,
        ?\OpenBooking\Application\Payment\Service\Payment_Service $payment_service = null,
    ) {
        $this->audit_repo = $audit_repo ?? new \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository();
        $this->outbox_repo = $outbox_repo ?? new \OpenBooking\Infrastructure\Integration\Outbox\Outbox_Event_Repository();
        $this->slot_lock_service = $slot_lock_service ?? new \OpenBooking\Application\Availability\Service\Slot_Lock_Service(
            new \OpenBooking\Infrastructure\Persistence\Availability\Slot_Lock_Repository( $this->audit_repo )
        );
        $this->outbox_worker = $outbox_worker ?? new \OpenBooking\Application\Core\Service\Outbox_Worker( $this->outbox_repo );
        $this->notification_manager = $notification_manager;
        $this->whatsapp_service = $whatsapp_service;
        $this->sms_service = $sms_service;
        $this->booking_admin_service = $booking_admin_service;
        $this->payment_service = $payment_service;

        add_action( 'init', [ $this, 'schedule_events' ] );
        add_filter( 'cron_schedules', [ $this, 'register_schedules' ] );
        add_action( Cron_Hook_Keys::EXPIRE_PENDING, [ $this, 'run_expire_pending' ] );
        add_action( Cron_Hook_Keys::SEND_REMINDERS, [ $this, 'run_send_reminders' ] );
        add_action( Cron_Hook_Keys::RECONCILE_STATE, [ $this, 'run_reconcile_state' ] );
        add_action( Cron_Hook_Keys::CLEANUP_LOGS, [ $this, 'run_cleanup_logs' ] );
        add_action( Cron_Hook_Keys::PROCESS_NOTIFICATION_QUEUE, [ $this, 'run_process_notification_queue' ] );
        add_action( Cron_Hook_Keys::HEARTBEAT, [ $this, 'run_heartbeat' ] );
        add_action( Cron_Hook_Keys::EXPIRE_STALE_LOCKS, [ $this, 'run_expire_stale_locks' ] );
        add_action( Cron_Hook_Keys::DATA_RETENTION, [ $this, 'run_data_retention' ] );
        add_action( Cron_Hook_Keys::PROCESS_OUTBOX, [ $this, 'run_process_outbox' ] );
        add_action( 'openbooking_notification_permanently_failed', [ $this, 'on_notification_permanently_failed' ], 10, 1 );
    }

    public function register_schedules( array $schedules ): array {
        if ( empty( $schedules['every_five_minutes'] ) ) {
            $schedules['every_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 5 minutes', 'openbooking-wp' ),
            ];
        }

        return $schedules;
    }

    public function schedule_events(): void {
        if ( ! wp_next_scheduled( Cron_Hook_Keys::EXPIRE_PENDING ) ) {
            wp_schedule_event( time(), 'every_five_minutes', Cron_Hook_Keys::EXPIRE_PENDING );
        }

        if ( ! wp_next_scheduled( Cron_Hook_Keys::SEND_REMINDERS ) ) {
            wp_schedule_event( time(), 'daily', Cron_Hook_Keys::SEND_REMINDERS );
        }

        if ( ! wp_next_scheduled( Cron_Hook_Keys::RECONCILE_STATE ) ) {
            wp_schedule_event( time(), 'hourly', Cron_Hook_Keys::RECONCILE_STATE );
        }

        if ( ! wp_next_scheduled( Cron_Hook_Keys::CLEANUP_LOGS ) ) {
            wp_schedule_event( time(), 'weekly', Cron_Hook_Keys::CLEANUP_LOGS );
        }

        if ( ! wp_next_scheduled( Cron_Hook_Keys::PROCESS_NOTIFICATION_QUEUE ) ) {
            wp_schedule_event( time(), 'every_five_minutes', Cron_Hook_Keys::PROCESS_NOTIFICATION_QUEUE );
        }

        if ( ! wp_next_scheduled( Cron_Hook_Keys::HEARTBEAT ) ) {
            wp_schedule_event( time(), 'every_five_minutes', Cron_Hook_Keys::HEARTBEAT );
        }

        if ( ! wp_next_scheduled( Cron_Hook_Keys::EXPIRE_STALE_LOCKS ) ) {
            wp_schedule_event( time(), 'every_five_minutes', Cron_Hook_Keys::EXPIRE_STALE_LOCKS );
        }

        if ( ! wp_next_scheduled( Cron_Hook_Keys::DATA_RETENTION ) ) {
            wp_schedule_event( time(), 'daily', Cron_Hook_Keys::DATA_RETENTION );
        }

        if ( (bool) get_option( Setting_Keys::OUTBOX_WORKER_ENABLED, 0 ) && ! wp_next_scheduled( Cron_Hook_Keys::PROCESS_OUTBOX ) ) {
            wp_schedule_event( time(), 'every_five_minutes', Cron_Hook_Keys::PROCESS_OUTBOX );
        }
    }

    public function run_heartbeat(): void {
        update_option( Option_Keys::CRON_HEARTBEAT_LAST, current_time( 'mysql' ) );

        $this->check_queue_backlog();
        $this->check_webhook_failures();
    }

    public function run_expire_pending(): int {
        try {
            $booking_service = $this->make_booking_service();
            $count = $booking_service->expire_pending();
            update_option( Option_Keys::CRON_LAST_EXPIRE_PENDING, current_time( 'mysql' ) );
            do_action( 'openbooking_cron_expire_pending_done', $count );
            return $count;
        } catch ( \Throwable $e ) {
            error_log( '[OpenBooking] Cron expire_pending failed: ' . $e->getMessage() );
            do_action( 'openbooking_cron_failed', 'expire_pending', $e->getMessage() );
            return 0;
        }
    }

    public function run_send_reminders(): int {
        try {
            $manager = $this->notification_manager;
            if ( ! $manager ) {
                return 0;
            }

            global $wpdb;

            $timezone = get_option( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' );
            try {
                $tz = new \DateTimeZone( $timezone );
            } catch ( \Exception $e ) {
                $tz = new \DateTimeZone( 'UTC' );
            }

            $hours_before = max( 1, (int) get_option( Setting_Keys::REMINDER_HOURS_BEFORE, 24 ) );
            $target   = ( new \DateTimeImmutable( 'now', $tz ) )->modify( '+' . $hours_before . ' hours' );
            $from     = $target->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
            $to       = $target->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' );

            $table = $wpdb->prefix . 'ob_bookings';
            $rows  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE status = %s AND start_at >= %s AND start_at <= %s",
                    'confirmed',
                    $from,
                    $to
                ),
                ARRAY_A
            );

            if ( ! $rows ) {
                return 0;
            }

            $count = 0;
            $reminder_date = $target->format( 'Y-m-d' );

            foreach ( $rows as $row ) {
                $booking_id = (int) $row['id'];
                $dedupe_base = 'reminder_customer:' . $booking_id . ':' . $reminder_date;

                $channels = [ 'email' ];
                $wa = $this->whatsapp_service;
                if ( $wa && $wa->is_enabled() ) {
                    $channels[] = 'whatsapp';
                }
                $sms = $this->sms_service;
                if ( $sms && $sms->is_enabled() ) {
                    $channels[] = 'sms';
                }

                foreach ( $channels as $channel ) {
                    $count += $manager->queue_booking_message(
                        $booking_id,
                        $channel,
                        'reminder_customer',
                        '',
                        [],
                        null,
                        null,
                        $dedupe_base . ':' . $channel
                    );
                }
            }

            update_option( Option_Keys::CRON_LAST_SEND_REMINDERS, current_time( 'mysql' ) );

            return $count;
        } catch ( \Throwable $e ) {
            error_log( '[OpenBooking] Cron send_reminders failed: ' . $e->getMessage() );
            do_action( 'openbooking_cron_failed', 'send_reminders', $e->getMessage() );
            return 0;
        }
    }

    public function run_reconcile_state(): int {
        try {
            $payment_service = $this->make_payment_service();
            $count = $payment_service->reconcile_inconsistencies();
            update_option( Option_Keys::CRON_LAST_RECONCILE_STATE, current_time( 'mysql' ) );
            do_action( 'openbooking_cron_reconcile_state_done', $count );
            return $count;
        } catch ( \Throwable $e ) {
            error_log( '[OpenBooking] Cron reconcile_state failed: ' . $e->getMessage() );
            do_action( 'openbooking_cron_failed', 'reconcile_state', $e->getMessage() );
            return 0;
        }
    }

    public function run_cleanup_logs(): int {
        try {
            global $wpdb;

            $notification_table = $wpdb->prefix . 'ob_notification_logs';
            $notif_retention_days = max( 7, (int) get_option( Setting_Keys::NOTIFICATION_LOG_RETENTION, 30 ) );
            $notification_cutoff = date( 'Y-m-d H:i:s', strtotime( '-' . $notif_retention_days . ' days' ) );

            $count = (int) $wpdb->query(
                $wpdb->prepare( "DELETE FROM {$notification_table} WHERE created_at < %s", $notification_cutoff )
            );

            $retention_days = (int) get_option( Setting_Keys::AUDIT_LOG_RETENTION, 0 );
            if ( $retention_days > 0 ) {
                $audit_cutoff = date( 'Y-m-d H:i:s', strtotime( '-' . $retention_days . ' days' ) );
                $count += $this->audit_repo->delete_older_than( $audit_cutoff );
            }

            $outbox_retention_days = max( 1, (int) get_option( Setting_Keys::OUTBOX_RETENTION_DAYS, 7 ) );
            $outbox_cutoff = date( 'Y-m-d H:i:s', strtotime( '-' . $outbox_retention_days . ' days' ) );
            $count += $this->outbox_repo->delete_processed_older_than( $outbox_cutoff );

            $rate_limiter = new \OpenBooking\Infrastructure\WordPress\Database\Rate_Limiter();
            $rate_limiter->purge_expired();

            update_option( Option_Keys::CRON_LAST_CLEANUP_LOGS, current_time( 'mysql' ) );

            return $count;
        } catch ( \Throwable $e ) {
            error_log( '[OpenBooking] Cron cleanup_logs failed: ' . $e->getMessage() );
            return 0;
        }
    }

    public function run_process_notification_queue(): int {
        try {
            $manager = $this->notification_manager;
            if ( ! $manager ) {
                return 0;
            }
            $count = $manager->process_queue( 50 );
            update_option( Option_Keys::CRON_LAST_NOTIFICATION_QUEUE, current_time( 'mysql' ) );
            return $count;
        } catch ( \Throwable $e ) {
            error_log( '[OpenBooking] Cron process_notification_queue failed: ' . $e->getMessage() );
            do_action( 'openbooking_cron_failed', 'process_notification_queue', $e->getMessage() );
            return 0;
        }
    }

    public function run_process_outbox(): int {
        if ( ! (bool) get_option( Setting_Keys::OUTBOX_WORKER_ENABLED, 0 ) ) {
            return 0;
        }

        try {
            $count = $this->outbox_worker->process_due( 25 );
            update_option( Option_Keys::CRON_LAST_PROCESS_OUTBOX, current_time( 'mysql' ) );

            return $count;
        } catch ( \Throwable $e ) {
            error_log( '[OpenBooking] Cron process_outbox failed: ' . $e->getMessage() );
            do_action( 'openbooking_cron_failed', 'process_outbox', $e->getMessage() );
            return 0;
        }
    }

    public function on_notification_permanently_failed( array $queue_row ): void {
        $this->audit_repo->insert( [
            'entity_type' => 'notification',
            'entity_id'   => (int) ( $queue_row['id'] ?? 0 ),
            'action'      => 'notification_failed_permanently',
            'actor_type'  => 'system',
            'message'     => 'Notification reached max attempts.',
            'severity'    => 'warning',
            'context'     => $queue_row,
        ] );

        global $wpdb;
        $queue_table = $wpdb->prefix . 'ob_notification_queue';
        $wpdb->update( $queue_table, [ 'status' => 'dead' ], [ 'id' => (int) ( $queue_row['id'] ?? 0 ) ] );
    }

    private function check_queue_backlog(): void {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'ob_notification_queue';
        $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE status = 'pending'" );

        if ( $pending >= self::QUEUE_BACKLOG_WARN ) {
            $last_warned = (int) get_option( Option_Keys::QUEUE_BACKLOG_LAST_WARN, 0 );
            if ( ( time() - $last_warned ) >= HOUR_IN_SECONDS ) {
                $this->audit_repo->insert( [
                    'entity_type' => 'notification_queue',
                    'entity_id'   => 0,
                    'action'      => 'queue_backlog_warning',
                    'actor_type'  => 'cron',
                    'message'     => "Notification queue backlog exceeds threshold: {$pending} pending items.",
                    'severity'    => 'warning',
                    'context'     => [ 'pending_count' => $pending, 'threshold' => self::QUEUE_BACKLOG_WARN ],
                ] );
                update_option( Option_Keys::QUEUE_BACKLOG_LAST_WARN, time() );
            }
        }
    }

    private function check_webhook_failures(): void {
        global $wpdb;
        $audit_table = $wpdb->prefix . 'ob_audit_logs';
        $rejected = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$audit_table}
                 WHERE action = %s AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)",
                'gateway_webhook_rejected'
            )
        );

        if ( $rejected >= 5 ) {
            $last_warned = (int) get_option( Option_Keys::WEBHOOK_FAILURE_LAST_WARN, 0 );
            if ( ( time() - $last_warned ) >= HOUR_IN_SECONDS ) {
                $this->audit_repo->insert( [
                    'entity_type' => 'payment_gateway',
                    'entity_id'   => 0,
                    'action'      => 'webhook_failure_spike',
                    'actor_type'  => 'cron',
                    'message'     => "Webhook rejection spike: {$rejected} rejected in the last hour.",
                    'severity'    => 'warning',
                    'context'     => [ 'rejected_count' => $rejected ],
                ] );
                update_option( Option_Keys::WEBHOOK_FAILURE_LAST_WARN, time() );
            }
        }
    }

    public function run_expire_stale_locks(): int {
        try {
            $count = $this->slot_lock_service->expire_stale_holds( 200 );
            update_option( Option_Keys::CRON_LAST_EXPIRE_STALE_LOCKS, current_time( 'mysql' ) );
            return $count;
        } catch ( \Throwable $e ) {
            error_log( '[OpenBooking] Cron expire_stale_locks failed: ' . $e->getMessage() );
            do_action( 'openbooking_cron_failed', 'expire_stale_locks', $e->getMessage() );
            return 0;
        }
    }

    public function run_data_retention(): int {
        try {
            global $wpdb;
            $count = 0;

            $booking_retention_days = max( 180, (int) get_option( Setting_Keys::BOOKING_RETENTION_DAYS, 730 ) );
            $booking_cutoff = date( 'Y-m-d H:i:s', strtotime( '-' . $booking_retention_days . ' days' ) );

            $booking_table = $wpdb->prefix . 'ob_bookings';
            $payment_table = $wpdb->prefix . 'ob_payments';

            $old_booking_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$booking_table} WHERE status IN ('cancelled_by_customer','cancelled_by_admin','completed','no_show','expired') AND updated_at < %s LIMIT 500",
                    $booking_cutoff
                )
            );

            if ( ! empty( $old_booking_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $old_booking_ids ), '%d' ) );

                $count += (int) $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->prefix}ob_notification_queue WHERE booking_id IN ({$placeholders})",
                        ...$old_booking_ids
                    )
                );
                $count += (int) $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$payment_table} WHERE booking_id IN ({$placeholders})",
                        ...$old_booking_ids
                    )
                );
                $count += (int) $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$booking_table} WHERE id IN ({$placeholders})",
                        ...$old_booking_ids
                    )
                );
            }

            $token_retention_days = max( 30, (int) get_option( Setting_Keys::TOKEN_RETENTION_DAYS, 90 ) );
            $token_cutoff = date( 'Y-m-d H:i:s', strtotime( '-' . $token_retention_days . ' days' ) );
            $expired_tokens = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}ob_bookings WHERE status = 'expired' AND updated_at < %s LIMIT 500",
                    $token_cutoff
                )
            );
            $count += $expired_tokens;

            update_option( Option_Keys::CRON_LAST_DATA_RETENTION, current_time( 'mysql' ) );
            do_action( 'openbooking_cron_data_retention_done', $count );

            return $count;
        } catch ( \Throwable $e ) {
            error_log( '[OpenBooking] Cron data_retention failed: ' . $e->getMessage() );
            do_action( 'openbooking_cron_failed', 'data_retention', $e->getMessage() );
            return 0;
        }
    }

    protected function make_booking_service(): \OpenBooking\Application\Booking\Service\Booking_Admin_Service {
        return $this->booking_admin_service;
    }

    protected function make_payment_service(): \OpenBooking\Application\Payment\Service\Payment_Service {
        return $this->payment_service;
    }
}
