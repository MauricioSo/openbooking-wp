<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Persistence\Notification;

use OpenBooking\Domain\Notification\Repository\ConsentLogRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de notificaciones.
 */

/**
 * Guarda el historial de consentimientos por cliente.
 */
class Consent_Log_Repository implements ConsentLogRepositoryInterface {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_consent_log';
    }

    public function log( int $customer_id, string $channel, string $purpose, string $action, string $source = '', ?string $source_text = null, ?string $ip_hash = null, ?string $user_agent = null ): int {
        $this->wpdb->insert( $this->table, [
            'customer_id' => \absint( $customer_id ),
            'channel'     => \sanitize_key( $channel ),
            'purpose'     => \sanitize_key( $purpose ),
            'action'      => \sanitize_key( $action ),
            'source'      => \sanitize_text_field( $source ),
            'source_text' => $source_text ? \sanitize_textarea_field( $source_text ) : null,
            'ip_hash'     => $ip_hash ? \sanitize_text_field( $ip_hash ) : null,
            'user_agent'  => $user_agent ? \sanitize_text_field( substr( $user_agent, 0, 255 ) ) : null,
        ] );

        return (int) $this->wpdb->insert_id;
    }

    public function has_consent( int $customer_id, string $channel, string $purpose = 'marketing' ): bool {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT action FROM {$this->table} WHERE customer_id = %d AND channel = %s AND purpose = %s ORDER BY id DESC LIMIT 1",
                $customer_id,
                $channel,
                $purpose
            ),
            ARRAY_A
        );

        return $row && 'opted_in' === $row['action'];
    }

    public function get_history( int $customer_id, int $limit = 50 ): array {
        return (array) $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE customer_id = %d ORDER BY id DESC LIMIT %d",
                $customer_id,
                $limit
            ),
            ARRAY_A
        );
    }

    public function find_by_customer( int $customer_id, int $limit = 100 ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT channel, purpose, action, source, created_at FROM {$this->table} WHERE customer_id = %d ORDER BY id DESC LIMIT %d",
                $customer_id,
                $limit
            ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    public function record_opt_in( int $customer_id, string $channel, string $purpose, string $source = '', ?string $source_text = null ): int {
        $ip_hash = $this->hash_ip();
        return $this->log( $customer_id, $channel, $purpose, 'opted_in', $source, $source_text, $ip_hash );
    }

    public function record_opt_out( int $customer_id, string $channel, string $purpose, string $source = '' ): int {
        return $this->log( $customer_id, $channel, $purpose, 'opted_out', $source );
    }

    private function hash_ip(): ?string {
        $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
        return '' !== $ip ? hash( 'sha256', $ip . wp_salt() ) : null;
    }
}
