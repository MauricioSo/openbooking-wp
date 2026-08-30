<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Constantes para los hooks de cron del plugin.
 */
class Cron_Hook_Keys {

    /** Procesa la cola de notificaciones. */
    public const PROCESS_NOTIFICATION_QUEUE = 'obwp_cron_process_notification_queue';

    /** Heartbeat del sistema. */
    public const HEARTBEAT                  = 'obwp_cron_heartbeat';

    /** Expira locks viejos. */
    public const EXPIRE_STALE_LOCKS         = 'obwp_cron_expire_stale_locks';

    /** Ejecuta retencion de datos. */
    public const DATA_RETENTION             = 'obwp_cron_data_retention';

    /** Procesa el outbox de eventos. */
    public const PROCESS_OUTBOX             = 'obwp_cron_process_outbox';

    /** Expira reservas pendientes. */
    public const EXPIRE_PENDING             = 'obwp_cron_expire_pending';

    /** Envia recordatorios. */
    public const SEND_REMINDERS             = 'obwp_cron_send_reminders';

    /** Reconcilia estado de reservas. */
    public const RECONCILE_STATE            = 'obwp_cron_reconcile_state';

    /** Limpia logs viejos. */
    public const CLEANUP_LOGS               = 'obwp_cron_cleanup_logs';

    /**
     * Lista todos los hooks de cron definidos.
     *
     * @return string[]
     */
    public static function all(): array {
        return [
            self::PROCESS_NOTIFICATION_QUEUE,
            self::HEARTBEAT,
            self::EXPIRE_STALE_LOCKS,
            self::DATA_RETENTION,
            self::PROCESS_OUTBOX,
            self::EXPIRE_PENDING,
            self::SEND_REMINDERS,
            self::RECONCILE_STATE,
            self::CLEANUP_LOGS,
        ];
    }
}
