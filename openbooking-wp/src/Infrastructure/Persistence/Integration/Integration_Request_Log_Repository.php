<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Persistence\Integration;

use OpenBooking\Domain\Integration\Repository\IntegrationRequestLogRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y consulta logs de requests de integracion.
 */
class Integration_Request_Log_Repository implements IntegrationRequestLogRepositoryInterface {

    private \wpdb $wpdb;
    private string $table = '';

    public function __construct(
        ?\wpdb $wpdb_override = null
    ) {
global $wpdb;        $this->wpdb  = $wpdb_override ?? $wpdb;        $this->table = $this->wpdb->prefix . 'ob_integration_request_logs';
    }

    public function find_by_request_id( string $request_id ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE request_id = %s", $request_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function find_by_idempotency_key( string $client_key, string $idempotency_key ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE client_key = %s AND idempotency_key = %s LIMIT 1",
                $client_key,
                $idempotency_key
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function insert( array $data ): int {
        $this->wpdb->insert( $this->table, [
            'request_id'          => sanitize_text_field( $data['request_id'] ),
            'client_id'           => ! empty( $data['client_id'] ) ? absint( $data['client_id'] ) : null,
            'client_key'          => ! empty( $data['client_key'] ) ? sanitize_text_field( $data['client_key'] ) : null,
            'route'               => sanitize_text_field( $data['route'] ),
            'http_method'         => sanitize_text_field( $data['http_method'] ),
            'idempotency_key'     => ! empty( $data['idempotency_key'] ) ? sanitize_text_field( $data['idempotency_key'] ) : null,
            'body_hash'           => ! empty( $data['body_hash'] ) ? sanitize_text_field( $data['body_hash'] ) : null,
            'status_code'         => absint( $data['status_code'] ?? 0 ),
            'result_entity_type'  => ! empty( $data['result_entity_type'] ) ? sanitize_text_field( $data['result_entity_type'] ) : null,
            'result_entity_id'    => ! empty( $data['result_entity_id'] ) ? absint( $data['result_entity_id'] ) : null,
            'error_code'          => ! empty( $data['error_code'] ) ? sanitize_text_field( $data['error_code'] ) : null,
            'ip_address'          => ! empty( $data['ip_address'] ) ? sanitize_text_field( $data['ip_address'] ) : null,
            'user_agent'          => ! empty( $data['user_agent'] ) ? sanitize_textarea_field( $data['user_agent'] ) : null,
        ] );
        return (int) $this->wpdb->insert_id;
    }

    public function update_result( int $id, int $status_code, ?string $error_code = null, ?string $entity_type = null, ?int $entity_id = null ): bool {
        $data = [ 'status_code' => $status_code ];
        if ( $error_code !== null ) {
            $data['error_code'] = sanitize_text_field( $error_code );
        }
        if ( $entity_type !== null ) {
            $data['result_entity_type'] = sanitize_text_field( $entity_type );
        }
        if ( $entity_id !== null ) {
            $data['result_entity_id'] = absint( $entity_id );
        }
        return false !== $this->wpdb->update( $this->table, $data, [ 'id' => $id ] );
    }

    public function find_requests_for_booking( int $booking_id ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE result_entity_type = 'booking' AND result_entity_id = %d ORDER BY created_at ASC",
                $booking_id
            ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    public function has_request_for_booking( int $booking_id ): bool {
        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE result_entity_type = 'booking' AND result_entity_id = %d",
                $booking_id
            )
        );
        return $count > 0;
    }
}
