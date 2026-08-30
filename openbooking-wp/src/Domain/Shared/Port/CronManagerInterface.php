<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Shared\Port;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para coordinar tareas cron.
 */
interface CronManagerInterface {
    public function schedule_events(): void;
    public function run_heartbeat(): void;
    public function run_expire_pending(): int;
    public function run_send_reminders(): int;
    public function run_reconcile_state(): int;
    public function run_cleanup_logs(): int;
    public function run_process_notification_queue(): int;
    public function run_process_outbox(): int;
    public function run_expire_stale_locks(): int;
    public function run_data_retention(): int;
}
