<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Core\Service;

use OpenBooking\Support\Setting_Keys;
use OpenBooking\Support\Option_Keys;
use OpenBooking\Support\Cron_Hook_Keys;

use OpenBooking\Domain\Shared\Repository\OutboxEventRepositoryInterface;
use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Notification\Repository\NotificationLogRepositoryInterface;
use OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface;
use OpenBooking\Domain\Availability\Repository\SlotLockRepositoryInterface;
use OpenBooking\Domain\Shared\Port\ActivatorInterface;
use OpenBooking\Domain\Shared\Port\SettingsInterface;
use OpenBooking\Application\Payment\Service\Gateway_Settings_Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Calcula el estado de salud del sistema para vistas publicas y detalladas.
 */
class Health_Check_Service {


    public function __construct(
        private OutboxEventRepositoryInterface $outbox_repo,
        private BookingRepositoryInterface $booking_repo,
        private NotificationLogRepositoryInterface $notification_log_repo,
        private AuditRepositoryInterface $audit_repo,
        private SlotLockRepositoryInterface $slot_lock_repo,
        private ActivatorInterface $activator,
        private Gateway_Settings_Service $gateway_settings,
        private SettingsInterface $settings,
    ) {}

    public function get_public_health(): array {
        $required_suffixes = [ 'ob_services', 'ob_bookings', 'ob_payments' ];
        $missing = $this->booking_repo->find_missing_tables( $required_suffixes );

        $status = empty( $missing ) ? 'ok' : 'error';

        return [
            'status'         => $status,
            'plugin_version' => defined( 'OBWP_VERSION' ) ? OBWP_VERSION : null,
            'schema_version' => (int) $this->settings->get( Option_Keys::SCHEMA_VERSION, 0 ),
            'missing_tables' => $status === 'error' ? $missing : [],
        ];
    }

    public function get_detailed_health(): array {
        $required_suffixes = [ 'ob_services', 'ob_bookings', 'ob_payments', 'ob_audit_logs', 'ob_slot_locks', 'ob_outbox_events' ];
        $missing_tables = $this->booking_repo->find_missing_tables( $required_suffixes );

        $cron_checks = [
            'expire_pending' => wp_next_scheduled( Cron_Hook_Keys::EXPIRE_PENDING ) ? 'ok' : 'warning',
            'send_reminders' => wp_next_scheduled( Cron_Hook_Keys::SEND_REMINDERS ) ? 'ok' : 'warning',
            'reconcile_state' => wp_next_scheduled( Cron_Hook_Keys::RECONCILE_STATE ) ? 'ok' : 'warning',
            'cleanup_logs'   => wp_next_scheduled( Cron_Hook_Keys::CLEANUP_LOGS ) ? 'ok' : 'warning',
            'expire_stale_locks' => wp_next_scheduled( Cron_Hook_Keys::EXPIRE_STALE_LOCKS ) ? 'ok' : 'warning',
            'process_outbox' => ( ! (bool) $this->settings->get( Setting_Keys::OUTBOX_WORKER_ENABLED, 0 ) || wp_next_scheduled( Cron_Hook_Keys::PROCESS_OUTBOX ) ) ? 'ok' : 'warning',
        ];

        $enabled_gateways = $this->settings->get( Setting_Keys::ENABLED_GATEWAYS, [] );
        $configured_gateways = [];
        foreach ( [ 'stripe', 'mercadopago', 'manual' ] as $gateway_key ) {
            if ( $this->gateway_settings->is_gateway_configured( $gateway_key ) ) {
                $configured_gateways[] = $gateway_key;
            }
        }

        $failed_notifications = $this->notification_log_repo->count_recent_failed( 7 );
        $cutoff_7d = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
        $rejected_webhooks = $this->audit_repo->count_by_action_since( 'gateway_webhook_rejected', $cutoff_7d );
        $expired_pending = $this->booking_repo->count_expired_pending_bookings();
        $outbox_health = $this->get_outbox_health_details();
        $desynced_bookings = $this->booking_repo->count_booking_payment_inconsistencies();

        $checks = [
            'wordpress' => [
                'status'  => function_exists( 'rest_url' ) ? 'ok' : 'error',
                'message' => function_exists( 'rest_url' ) ? 'WordPress loaded.' : 'WordPress functions unavailable.',
            ],
            'database'  => [
                'status'  => ( isset( $GLOBALS['wpdb'] ) && ! empty( $GLOBALS['wpdb']->prefix ) ) ? 'ok' : 'error',
                'message' => ( isset( $GLOBALS['wpdb'] ) && ! empty( $GLOBALS['wpdb']->prefix ) ) ? 'Database connection available.' : 'Database connection unavailable.',
            ],
            'plugin'    => [
                'status'  => defined( 'OBWP_VERSION' ) ? 'ok' : 'error',
                'message' => defined( 'OBWP_VERSION' ) ? 'Plugin constants loaded.' : 'Plugin not bootstrapped correctly.',
            ],
            'schema'    => [
                'status'  => $this->activator->get_schema_version() >= $this->activator->get_expected_schema_version() ? 'ok' : 'warning',
                'message' => 'Schema version checked.',
                'version' => $this->activator->get_schema_version(),
                'expected' => $this->activator->get_expected_schema_version(),
            ],
            'tables'    => [
                'status'  => empty( $missing_tables ) ? 'ok' : 'error',
                'message' => empty( $missing_tables ) ? 'Required tables available.' : 'Missing required tables.',
                'missing' => $missing_tables,
            ],
            'cron'      => [
                'status'  => in_array( 'warning', $cron_checks, true ) ? 'warning' : 'ok',
                'message' => 'Cron schedule status checked.',
                'events'  => $cron_checks,
                'scheduled_at' => [
                    'expire_pending' => wp_next_scheduled( Cron_Hook_Keys::EXPIRE_PENDING ) ?: null,
                    'send_reminders' => wp_next_scheduled( Cron_Hook_Keys::SEND_REMINDERS ) ?: null,
                    'reconcile_state' => wp_next_scheduled( Cron_Hook_Keys::RECONCILE_STATE ) ?: null,
                    'cleanup_logs'   => wp_next_scheduled( Cron_Hook_Keys::CLEANUP_LOGS ) ?: null,
                    'expire_stale_locks' => wp_next_scheduled( Cron_Hook_Keys::EXPIRE_STALE_LOCKS ) ?: null,
                    'process_outbox' => wp_next_scheduled( Cron_Hook_Keys::PROCESS_OUTBOX ) ?: null,
                ],
                'last_run' => [
                    'expire_pending' => $this->settings->get( Option_Keys::CRON_LAST_EXPIRE_PENDING, null ),
                    'send_reminders' => $this->settings->get( Option_Keys::CRON_LAST_SEND_REMINDERS, null ),
                    'reconcile_state' => $this->settings->get( Option_Keys::CRON_LAST_RECONCILE_STATE, null ),
                    'cleanup_logs'   => $this->settings->get( Option_Keys::CRON_LAST_CLEANUP_LOGS, null ),
                    'process_outbox' => $this->settings->get( Option_Keys::CRON_LAST_PROCESS_OUTBOX, null ),
                ],
            ],
            'payments'  => [
                'status'              => ! empty( $configured_gateways ) ? 'ok' : 'warning',
                'message'             => ! empty( $configured_gateways ) ? 'Payment gateways configured.' : 'No payment gateways configured.',
                'configured_gateways' => $configured_gateways,
                'enabled_gateways'    => is_array( $enabled_gateways ) ? array_values( $enabled_gateways ) : [],
                'details'             => [
                    'stripe'      => $this->gateway_settings->get_gateway_health( 'stripe' ),
                    'mercadopago' => $this->gateway_settings->get_gateway_health( 'mercadopago' ),
                    'manual'      => $this->gateway_settings->get_gateway_health( 'manual' ),
                ],
            ],
            'notifications' => [
                'status'       => $failed_notifications > 0 ? 'warning' : 'ok',
                'message'      => $failed_notifications > 0 ? 'Recent failed notifications detected.' : 'No recent failed notifications.',
                'failed_count' => $failed_notifications,
            ],
            'webhooks' => [
                'status'         => $rejected_webhooks > 0 ? 'warning' : 'ok',
                'message'        => $rejected_webhooks > 0 ? 'Recent rejected webhooks detected.' : 'No recent rejected webhooks.',
                'rejected_count' => $rejected_webhooks,
            ],
            'outbox' => $outbox_health,
            'expired_pending' => [
                'status'  => $expired_pending > 0 ? 'warning' : 'ok',
                'message' => $expired_pending > 0 ? 'There are expired pending bookings awaiting cron processing.' : 'No stale pending bookings found.',
                'count'   => $expired_pending,
            ],
            'reconciliation' => [
                'status'  => $desynced_bookings > 0 ? 'warning' : 'ok',
                'message' => $desynced_bookings > 0 ? 'Detected bookings/payout state inconsistencies.' : 'No booking/payment inconsistencies detected.',
                'count'   => $desynced_bookings,
            ],
            'slot_locks' => [
                'status'  => $this->get_slot_lock_health_status(),
                'message' => 'Slot lock integrity checked.',
                'details' => $this->get_slot_lock_health_details(),
            ],
        ];

        $overall = 'ok';
        foreach ( $checks as $check ) {
            if ( $check['status'] === 'error' ) {
                $overall = 'error';
                break;
            }
            if ( $check['status'] === 'warning' ) {
                $overall = 'warning';
            }
        }

        return [
            'status'    => $overall,
            'version'   => defined( 'OBWP_VERSION' ) ? OBWP_VERSION : null,
            'timestamp' => current_time( 'mysql', true ),
            'checks'    => $checks,
        ];
    }

    private function get_outbox_health_details(): array {
        if ( ! $this->outbox_repo->table_exists() ) {
            return [ 'status' => 'error', 'message' => 'Outbox table is missing.', 'table_exists' => false ];
        }

        $counts = $this->outbox_repo->counts_by_status();
        $oldest_pending = $this->outbox_repo->oldest_pending_created_at();
        $oldest_pending_age_seconds = null;
        if ( $oldest_pending ) {
            $timestamp = strtotime( $oldest_pending );
            if ( false !== $timestamp ) {
                $oldest_pending_age_seconds = max( 0, time() - $timestamp );
            }
        }

        $status = 'ok';
        $message = 'Outbox is healthy.';
        if ( (int) ( $counts['failed'] ?? 0 ) > 0 ) {
            $status = 'warning';
            $message = 'Outbox has failed events requiring review.';
        } elseif ( null !== $oldest_pending_age_seconds && $oldest_pending_age_seconds > 15 * MINUTE_IN_SECONDS ) {
            $status = 'warning';
            $message = 'Outbox has stale pending events.';
        }

        return [
            'status'                     => $status,
            'message'                    => $message,
            'table_exists'               => true,
            'flags'                      => [
                'record_events'           => (bool) $this->settings->get( Setting_Keys::OUTBOX_RECORD_EVENTS, 0 ),
                'worker_enabled'          => (bool) $this->settings->get( Setting_Keys::OUTBOX_WORKER_ENABLED, 0 ),
                'async_outbound_webhooks' => (bool) $this->settings->get( Setting_Keys::ASYNC_OUTBOUND_WEBHOOKS, 0 ),
            ],
            'counts'                     => $counts,
            'oldest_pending_created_at'  => $oldest_pending,
            'oldest_pending_age_seconds' => $oldest_pending_age_seconds,
            'last_worker_run'            => $this->settings->get( Option_Keys::CRON_LAST_PROCESS_OUTBOX, null ),
        ];
    }

    private function get_slot_lock_health_status(): string {
        return $this->slot_lock_repo->table_exists() ? 'ok' : 'error';
    }

    private function get_slot_lock_health_details(): array {
        return $this->slot_lock_repo->health_details();
    }
}
