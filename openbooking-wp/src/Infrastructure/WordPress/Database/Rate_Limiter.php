<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\WordPress\Database;

use OpenBooking\Domain\Shared\Port\RateLimiterInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Atomic, DB-backed rate limiter.
 *
 * Uses a single INSERT … ON DUPLICATE KEY UPDATE statement so the increment
 * is serialized by InnoDB's row lock — no race condition between read and write.
 *
 * Compared to the previous transient approach:
 *   Before: get_transient() + set_transient() — two separate operations, bypassable
 *           under concurrent load.
 *   After:  one atomic SQL statement per request — counter is always correct.
 */
class Rate_Limiter implements RateLimiterInterface {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_rate_limits';
    }

    /**
     * Checks and increments the rate limit counter for a given action + identifier.
     *
     * @param string $action          Logical name of the action (e.g. 'booking_create').
     * @param string $identifier      Per-client key, typically the IP address.
     * @param int    $max_attempts    Maximum allowed calls within the window.
     * @param int    $window_seconds  Rolling window duration in seconds.
     *
     * @return bool  true = request allowed, false = limit exceeded.
     */
    public function check( string $action, string $identifier, int $max_attempts, int $window_seconds ): bool {
        $key_hash = md5( $action . '|' . $identifier );

        // Atomic upsert:
        //  • Fresh key → inserts with count = 1 and a new expiry window.
        //  • Existing key, window active → increments count atomically.
        //  • Existing key, window expired → resets count to 1 and starts a new window.
        // InnoDB locks the PRIMARY KEY row during the UPDATE, so concurrent requests
        // are serialized — no two threads can both see the same pre-increment count.
        $this->wpdb->query( $this->wpdb->prepare(
            "INSERT INTO {$this->table} (key_hash, count, expires_at)
             VALUES (%s, 1, DATE_ADD(NOW(), INTERVAL %d SECOND))
             ON DUPLICATE KEY UPDATE
               count      = IF(expires_at < NOW(), 1,         count + 1),
               expires_at = IF(expires_at < NOW(), DATE_ADD(NOW(), INTERVAL %d SECOND), expires_at)",
            $key_hash,
            $window_seconds,
            $window_seconds
        ) );

        $count = (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT count FROM {$this->table} WHERE key_hash = %s",
            $key_hash
        ) );

        return $count <= $max_attempts;
    }

    /**
     * Removes expired rows. Called by the plugin's maintenance cron.
     */
    public function purge_expired(): void {
        $table_name = function_exists( 'esc_sql' ) ? esc_sql( $this->table ) : $this->table;
        $table      = '`' . $table_name . '`';
        $this->wpdb->query( "DELETE FROM {$table} WHERE expires_at < NOW()" );
    }
}
