<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Core\Service;

use OpenBooking\Support\Option_Keys;
use OpenBooking\Support\Cron_Hook_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone el estado de cron y permite disparar eventos manuales.
 */
class Cron_Status_Service {

    private const ALLOWED_EVENTS = [
        'expire_pending'             => Cron_Hook_Keys::EXPIRE_PENDING,
        'send_reminders'             => Cron_Hook_Keys::SEND_REMINDERS,
        'reconcile_state'            => Cron_Hook_Keys::RECONCILE_STATE,
        'cleanup_logs'               => Cron_Hook_Keys::CLEANUP_LOGS,
        'process_notification_queue' => Cron_Hook_Keys::PROCESS_NOTIFICATION_QUEUE,
        'process_outbox'             => Cron_Hook_Keys::PROCESS_OUTBOX,
        'heartbeat'                  => Cron_Hook_Keys::HEARTBEAT,
    ];

    private const HEARTBEAT_STALE_THRESHOLD = 600;


    public function __construct(
        private ?\OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface $queue_repo,
        private \OpenBooking\Application\Shared\Port\HookDispatcherInterface $hooks,
        private \OpenBooking\Domain\Shared\Port\SettingsInterface $settings,
    ) {}

    public function get_status(): array {
        $events_status = [];
        foreach ( self::ALLOWED_EVENTS as $key => $hook ) {
            $next = wp_next_scheduled( $hook );
            $events_status[ $key ] = [
                'hook'        => $hook,
                'scheduled'   => $next !== false,
                'next_run_ts' => $next ?: null,
                'next_run'    => $next ? date( 'Y-m-d H:i:s', $next ) : null,
                'last_run'    => $this->settings->get( Option_Keys::CRON_LAST_RUN_PREFIX . $key, null ),
                'status'      => $next !== false ? 'ok' : 'warning',
            ];
        }

        $heartbeat_last  = $this->settings->get( Option_Keys::CRON_HEARTBEAT_LAST, null );
        $heartbeat_stale = true;
        if ( $heartbeat_last ) {
            $heartbeat_stale = ( time() - strtotime( $heartbeat_last ) ) > self::HEARTBEAT_STALE_THRESHOLD;
        }

        $using_system_cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $using_alternate   = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;

        $queue_pending = $this->queue_repo?->count_by_status( 'pending' ) ?? 0;
        $queue_dead    = $this->queue_repo?->count_by_status( 'dead' ) ?? 0;

        return [
            'heartbeat_last'           => $heartbeat_last,
            'heartbeat_stale'          => $heartbeat_stale,
            'using_system_cron'        => $using_system_cron,
            'using_alternate_cron'     => $using_alternate,
            'system_cron_recommendation' => ! $using_system_cron
                ? "Para mayor confiabilidad, configura una tarea programada del sistema que ejecute wp-cron.php cada 5 minutos y agrega define('DISABLE_WP_CRON', true) a wp-config.php."
                : null,
            'events'        => $events_status,
            'queue_pending' => $queue_pending,
            'queue_dead'    => $queue_dead,
        ];
    }

    public function trigger_event( string $event ): array {
        if ( ! isset( self::ALLOWED_EVENTS[ $event ] ) ) {
            return [
                'success' => false,
                'error'   => 'Evento de cron no permitido.',
            ];
        }

        $this->hooks->do_action( self::ALLOWED_EVENTS[ $event ] );

        return [
            'success'      => true,
            'event'        => $event,
            'hook'         => self::ALLOWED_EVENTS[ $event ],
            'triggered_at' => current_time( 'mysql', true ),
        ];
    }
}
