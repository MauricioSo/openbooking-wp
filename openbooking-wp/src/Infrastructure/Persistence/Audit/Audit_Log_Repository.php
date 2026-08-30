<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Persistence\Audit;

use OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de auditoria.
 */

class Audit_Log_Repository implements AuditRepositoryInterface {

    private const ORDER_BY_WHITELIST = [ 'id', 'created_at', 'action', 'entity_type' ];
    private const ORDER_WHITELIST    = [ 'ASC', 'DESC' ];

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_audit_logs';
    }

    public function insert( array $data ): int {
        $this->wpdb->insert( $this->table, [
            'entity_type'         => sanitize_text_field( $data['entity_type'] ?? '' ),
            'entity_id'           => absint( $data['entity_id'] ?? 0 ),
            'action'              => sanitize_text_field( $data['action'] ?? '' ),
            'actor_type'          => sanitize_text_field( $data['actor_type'] ?? 'system' ),
            'actor_id'            => ! empty( $data['actor_id'] ) ? absint( $data['actor_id'] ) : null,
            'message'             => sanitize_textarea_field( $data['message'] ?? '' ) ?: null,
            'context_json'        => ! empty( $data['context'] ) ? wp_json_encode( $data['context'] ) : null,
            'request_id'          => ! empty( $data['request_id'] ) ? sanitize_text_field( $data['request_id'] ) : null,
            'severity'            => sanitize_text_field( $data['severity'] ?? 'info' ),
            'source'              => sanitize_text_field( $data['source'] ?? 'system' ),
            'ip_address'          => ! empty( $data['ip_address'] ) ? sanitize_text_field( $data['ip_address'] ) : null,
            'user_agent'          => ! empty( $data['user_agent'] ) ? sanitize_textarea_field( $data['user_agent'] ) : null,
            'route'               => ! empty( $data['route'] ) ? sanitize_text_field( $data['route'] ) : null,
            'http_method'         => ! empty( $data['http_method'] ) ? sanitize_text_field( $data['http_method'] ) : null,
            'changed_fields_json' => ! empty( $data['changed_fields'] ) ? wp_json_encode( $data['changed_fields'] ) : null,
            'meta_json'           => ! empty( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : null,
        ] );

        return (int) $this->wpdb->insert_id;
    }

    public function find( int $id ): ?array {
        if ( $id <= 0 ) {
            return null;
        }

        $query = $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id );
        $row   = $this->wpdb->get_row( $query, ARRAY_A );

        if ( empty( $row ) || ! is_array( $row ) ) {
            return null;
        }

        return $this->map_row( $row );
    }

    public function find_all( array $args = [] ): array {
        $prepared = $this->build_query_parts( $args );
        $limit    = isset( $args['limit'] ) ? absint( $args['limit'] ) : 20;
        $offset   = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

        if ( $limit <= 0 ) {
            $limit = 20;
        }

        $query = "SELECT * FROM {$this->table} {$prepared['where']} ORDER BY {$prepared['order_by']} {$prepared['order']} LIMIT %d OFFSET %d";
        $values = $prepared['values'];
        $values[] = $limit;
        $values[] = $offset;

        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $query, $values ), ARRAY_A );

        return array_map( [ $this, 'map_row' ], is_array( $rows ) ? $rows : [] );
    }

    public function count_all( array $args = [] ): int {
        $prepared = $this->build_query_parts( $args );
        $query    = "SELECT COUNT(*) FROM {$this->table} {$prepared['where']}";

        if ( empty( $prepared['values'] ) ) {
            return (int) $this->wpdb->get_var( $query );
        }

        return (int) $this->wpdb->get_var( $this->wpdb->prepare( $query, $prepared['values'] ) );
    }

    public function delete_older_than( string $cutoff ): int {
        return (int) $this->wpdb->query(
            $this->wpdb->prepare( "DELETE FROM {$this->table} WHERE created_at < %s", $cutoff )
        );
    }

    public function count_by_action_since( string $action, string $cutoff ): int {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE action = %s AND created_at >= %s",
                sanitize_text_field( $action ),
                sanitize_text_field( $cutoff )
            )
        );
    }

    private function build_query_parts( array $args ): array {
        $where  = [];
        $values = [];

        if ( ! empty( $args['entity_type'] ) ) {
            $where[]  = 'entity_type = %s';
            $values[] = sanitize_text_field( $args['entity_type'] );
        }

        if ( ! empty( $args['entity_id'] ) ) {
            $where[]  = 'entity_id = %d';
            $values[] = absint( $args['entity_id'] );
        }

        if ( ! empty( $args['action'] ) ) {
            $where[]  = 'action = %s';
            $values[] = sanitize_text_field( $args['action'] );
        }

        if ( ! empty( $args['actor_type'] ) ) {
            $where[]  = 'actor_type = %s';
            $values[] = sanitize_text_field( $args['actor_type'] );
        }

        if ( ! empty( $args['actor_id'] ) ) {
            $where[]  = 'actor_id = %d';
            $values[] = absint( $args['actor_id'] );
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = 'created_at >= %s';
            $values[] = sanitize_text_field( $args['date_from'] );
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = 'created_at <= %s';
            $values[] = sanitize_text_field( $args['date_to'] );
        }

        if ( ! empty( $args['search'] ) ) {
            $search   = '%' . $this->wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[]  = '(message LIKE %s OR request_id LIKE %s)';
            $values[] = $search;
            $values[] = $search;
        }

        if ( ! empty( $args['request_id'] ) ) {
            $where[]  = 'request_id = %s';
            $values[] = sanitize_text_field( $args['request_id'] );
        }

        $order_by = sanitize_text_field( $args['order_by'] ?? 'created_at' );
        if ( ! in_array( $order_by, self::ORDER_BY_WHITELIST, true ) ) {
            $order_by = 'created_at';
        }

        $order = strtoupper( sanitize_text_field( $args['order'] ?? 'DESC' ) );
        if ( ! in_array( $order, self::ORDER_WHITELIST, true ) ) {
            $order = 'DESC';
        }

        return [
            'where'    => ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '',
            'values'   => $values,
            'order_by' => $order_by,
            'order'    => $order,
        ];
    }

    private function map_row( array $row ): array {
        $context = null;
        $changed_fields = null;
        $meta = null;
        if ( isset( $row['context_json'] ) && null !== $row['context_json'] && '' !== $row['context_json'] ) {
            $decoded = json_decode( (string) $row['context_json'], true );
            if ( JSON_ERROR_NONE === json_last_error() ) {
                $context = $decoded;
            }
        }

        if ( isset( $row['changed_fields_json'] ) && null !== $row['changed_fields_json'] && '' !== $row['changed_fields_json'] ) {
            $decoded = json_decode( (string) $row['changed_fields_json'], true );
            if ( JSON_ERROR_NONE === json_last_error() ) {
                $changed_fields = $decoded;
            }
        }

        if ( isset( $row['meta_json'] ) && null !== $row['meta_json'] && '' !== $row['meta_json'] ) {
            $decoded = json_decode( (string) $row['meta_json'], true );
            if ( JSON_ERROR_NONE === json_last_error() ) {
                $meta = $decoded;
            }
        }

        $row['id']         = isset( $row['id'] ) ? (int) $row['id'] : 0;
        $row['entity_id']  = isset( $row['entity_id'] ) ? (int) $row['entity_id'] : 0;
        $row['actor_id']   = isset( $row['actor_id'] ) && null !== $row['actor_id'] ? (int) $row['actor_id'] : null;
        $row['context']            = $context;
        $row['context_raw']        = $row['context_json'] ?? null;
        $row['changed_fields']     = $changed_fields;
        $row['changed_fields_raw'] = $row['changed_fields_json'] ?? null;
        $row['meta']               = $meta;
        $row['meta_raw']           = $row['meta_json'] ?? null;

        return $row;
    }
}
