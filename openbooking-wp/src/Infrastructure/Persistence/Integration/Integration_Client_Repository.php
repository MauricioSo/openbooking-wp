<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Persistence\Integration;

use OpenBooking\Domain\Integration\Repository\IntegrationClientRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y consulta clientes de integracion.
 */
class Integration_Client_Repository implements IntegrationClientRepositoryInterface {

    private \wpdb $wpdb;
    private string $table = '';

    public function __construct(
        ?\wpdb $wpdb_override = null
    ) {
global $wpdb;        $this->wpdb  = $wpdb_override ?? $wpdb;        $this->table = $this->wpdb->prefix . 'ob_integration_clients';
    }

    public function find_by_client_key( string $client_key ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE client_key = %s AND status = 'active' LIMIT 1",
                $client_key
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function find_by_id( int $id ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function create( string $client_key, string $name, string $secret_hash, array $scopes = [], array $allowed_ips = [], int $rate_limit_per_minute = 60, int $rate_limit_per_hour = 1000 ): int {
        $this->wpdb->insert( $this->table, [
            'client_key'          => sanitize_text_field( $client_key ),
            'name'                => sanitize_text_field( $name ),
            'secret_hash'         => $secret_hash,
            'status'              => 'active',
            'allowed_scopes'      => ! empty( $scopes ) ? wp_json_encode( $scopes ) : null,
            'allowed_ips'         => ! empty( $allowed_ips ) ? wp_json_encode( $allowed_ips ) : null,
            'rate_limit_per_minute' => max( 1, $rate_limit_per_minute ),
            'rate_limit_per_hour'   => max( 1, $rate_limit_per_hour ),
        ] );
        return (int) $this->wpdb->insert_id;
    }

    public function update_last_used( int $id ): void {
        $this->wpdb->update(
            $this->table,
            [ 'last_used_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id ]
        );
    }

    public function deactivate( int $id ): bool {
        return false !== $this->wpdb->update(
            $this->table,
            [ 'status' => 'inactive' ],
            [ 'id' => $id ]
        );
    }

    public function get_scopes( array $client ): array {
        if ( empty( $client['allowed_scopes'] ) ) {
            return [];
        }
        $decoded = json_decode( $client['allowed_scopes'], true );
        return is_array( $decoded ) ? $decoded : [];
    }

    public function get_allowed_ips( array $client ): array {
        if ( empty( $client['allowed_ips'] ) ) {
            return [];
        }
        $decoded = json_decode( $client['allowed_ips'], true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
