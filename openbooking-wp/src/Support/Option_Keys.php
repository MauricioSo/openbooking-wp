<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Constantes para opciones internas del plugin.
 */
class Option_Keys {

    /** Version de esquema de base de datos. */
    public const DB_VERSION           = 'obwp_db_version';

    /** Version anterior del esquema (migraciones). */
    public const SCHEMA_VERSION       = 'obwp_schema_version';

    /** Marca si el onboarding fue completado. */
    public const ONBOARDING_DONE      = 'obwp_onboarding_done';

    /** Indica si se debe mostrar el asistente de onboarding. */
    public const SHOW_ONBOARDING      = 'obwp_show_onboarding';

    /** Preset de configuracion aplicado durante onboarding. */
    public const ONBOARDING_PRESET    = 'obwp_onboarding_preset';

    /** Marca temporal del ultimo heartbeat del cron. */
    public const CRON_HEARTBEAT_LAST  = 'obwp_cron_heartbeat_last';

    /** Ultima ejecucion de expiracion de reservas pendientes. */
    public const CRON_LAST_EXPIRE_PENDING = 'obwp_cron_last_run_expire_pending';

    /** Ultima ejecucion de envio de recordatorios. */
    public const CRON_LAST_SEND_REMINDERS = 'obwp_cron_last_run_send_reminders';

    /** Ultima ejecucion de reconciliacion de estado. */
    public const CRON_LAST_RECONCILE_STATE = 'obwp_cron_last_run_reconcile_state';

    /** Ultima ejecucion de limpieza de logs. */
    public const CRON_LAST_CLEANUP_LOGS = 'obwp_cron_last_run_cleanup_logs';

    /** Ultima ejecucion de procesamiento de cola de notificaciones. */
    public const CRON_LAST_NOTIFICATION_QUEUE = 'obwp_cron_last_run_process_notification_queue';

    /** Ultima ejecucion de procesamiento del outbox. */
    public const CRON_LAST_PROCESS_OUTBOX = 'obwp_cron_last_run_process_outbox';

    /** Ultima ejecucion de expiracion de locks viejos. */
    public const CRON_LAST_EXPIRE_STALE_LOCKS = 'obwp_cron_last_run_expire_stale_locks';

    /** Ultima ejecucion de retencion de datos. */
    public const CRON_LAST_DATA_RETENTION = 'obwp_cron_last_run_data_retention';

    /** Ultima advertencia de backlog en cola. */
    public const QUEUE_BACKLOG_LAST_WARN = 'obwp_queue_backlog_last_warn';

    /** Ultima advertencia de fallos en webhooks. */
    public const WEBHOOK_FAILURE_LAST_WARN = 'obwp_webhook_failure_last_warn';

    /** Prefijo para ultimas ejecuciones de cron. */
    public const CRON_LAST_RUN_PREFIX = 'obwp_cron_last_run_';

    /** Prefijo para metricas de uso. */
    public const METRICS_PREFIX       = 'obwp_metrics_';

    /** Prefijo para datos de embudo de conversion. */
    public const FUNNEL_PREFIX        = 'obwp_funnel_';

    /**
     * Lista todas las claves de opciones internas definidas.
     *
     * @return string[]
     */
    public static function all(): array {
        return [
            self::DB_VERSION,
            self::SCHEMA_VERSION,
            self::ONBOARDING_DONE,
            self::SHOW_ONBOARDING,
            self::ONBOARDING_PRESET,
            self::CRON_HEARTBEAT_LAST,
            self::CRON_LAST_EXPIRE_PENDING,
            self::CRON_LAST_SEND_REMINDERS,
            self::CRON_LAST_RECONCILE_STATE,
            self::CRON_LAST_CLEANUP_LOGS,
            self::CRON_LAST_NOTIFICATION_QUEUE,
            self::CRON_LAST_PROCESS_OUTBOX,
            self::CRON_LAST_EXPIRE_STALE_LOCKS,
            self::CRON_LAST_DATA_RETENTION,
            self::QUEUE_BACKLOG_LAST_WARN,
            self::WEBHOOK_FAILURE_LAST_WARN,
        ];
    }
}
