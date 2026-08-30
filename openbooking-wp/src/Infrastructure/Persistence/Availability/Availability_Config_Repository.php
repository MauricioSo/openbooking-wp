<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Persistence\Availability;

use OpenBooking\Domain\Availability\Repository\AvailabilityConfigRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de disponibilidad.
 */

class Availability_Config_Repository implements AvailabilityConfigRepositoryInterface {

    private \wpdb $wpdb;
    private string $rules_table;
    private string $blocks_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb        = $wpdb;
        $this->rules_table = $wpdb->prefix . 'ob_availability_rules';
        $this->blocks_table = $wpdb->prefix . 'ob_blocks';
    }

    public function find_rule( int $id ): ?\OpenBooking\Domain\Availability\Entity\AvailabilityRule_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->rules_table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Availability\Entity\AvailabilityRule_Entity::from_array( $row ) : null;
    }

    public function get_rules( string $scope_type = 'global', ?int $scope_id = null, ?string $rule_type = null ): array {
        $where  = [ '1=1' ];
        $params = [];

        $where[]  = 'scope_type = %s';
        $params[] = $scope_type;

        if ( $scope_id !== null ) {
            $where[]  = 'scope_id = %d';
            $params[] = $scope_id;
        }

        if ( $rule_type ) {
            $where[]  = 'rule_type = %s';
            $params[] = $rule_type;
        }

        return $this->fetch_rules( implode( ' AND ', $where ), $params );
    }

    public function get_applicable_rules( int $service_id, ?int $resource_id = null ): array {
        $sql = "SELECT * FROM {$this->rules_table}
                WHERE scope_type = 'global'
                   OR (scope_type = 'service' AND scope_id = %d)";

        $params = [ $service_id ];

        if ( $resource_id ) {
            $sql .= " OR (scope_type = 'resource' AND scope_id = %d)";
            $params[] = $resource_id;
        }

        $sql .= " ORDER BY scope_type ASC, weekday ASC, time_from ASC";
        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );

        return $this->hydrate_rule_rows( $rows ?: [] );
    }

    public function insert_rule( \OpenBooking\Domain\Availability\Entity\AvailabilityRule_Entity $entity ): int {
        $entity->time_from = $this->normalize_time_value( $entity->time_from );
        $entity->time_to   = $this->normalize_time_value( $entity->time_to );

        $existing_id = $this->find_existing_rule_id( $entity );
        if ( $existing_id ) {
            return $existing_id;
        }

        $this->wpdb->insert( $this->rules_table, [
            'scope_type' => sanitize_text_field( $entity->scope_type ),
            'scope_id'   => $entity->scope_id,
            'rule_type'  => sanitize_text_field( $entity->rule_type ),
            'weekday'    => $entity->weekday,
            'date_from'  => $entity->date_from,
            'date_to'    => $entity->date_to,
            'time_from'  => $entity->time_from,
            'time_to'    => $entity->time_to,
            'capacity'   => $entity->capacity,
            'meta'       => $entity->meta ? wp_json_encode( $entity->meta ) : null,
        ] );

        return (int) $this->wpdb->insert_id;
    }

    private function find_existing_rule_id( \OpenBooking\Domain\Availability\Entity\AvailabilityRule_Entity $entity ): int {
        $sql = "SELECT id FROM {$this->rules_table}
                WHERE scope_type = %s
                AND COALESCE(scope_id, 0) = %d
                AND rule_type = %s
                AND COALESCE(weekday, 0) = %d
                AND COALESCE(date_from, '') = %s
                AND COALESCE(date_to, '') = %s
                AND COALESCE(time_from, '') IN (%s, %s)
                AND COALESCE(time_to, '') IN (%s, %s)
                AND COALESCE(capacity, 0) = %d
                LIMIT 1";

        $time_from = (string) ( $entity->time_from ?? '' );
        $time_to   = (string) ( $entity->time_to ?? '' );

        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            $sql,
            sanitize_text_field( $entity->scope_type ),
            (int) ( $entity->scope_id ?? 0 ),
            sanitize_text_field( $entity->rule_type ),
            (int) ( $entity->weekday ?? 0 ),
            (string) ( $entity->date_from ?? '' ),
            (string) ( $entity->date_to ?? '' ),
            $time_from,
            $this->legacy_time_value( $time_from ),
            $time_to,
            $this->legacy_time_value( $time_to ),
            (int) ( $entity->capacity ?? 0 )
        ) );
    }

    public function update_rule( \OpenBooking\Domain\Availability\Entity\AvailabilityRule_Entity $entity ): bool {
        if ( ! $entity->id ) {
            return false;
        }

        $entity->time_from = $this->normalize_time_value( $entity->time_from );
        $entity->time_to   = $this->normalize_time_value( $entity->time_to );

        $result = $this->wpdb->update( $this->rules_table, [
            'scope_type' => sanitize_text_field( $entity->scope_type ),
            'scope_id'   => $entity->scope_id,
            'rule_type'  => sanitize_text_field( $entity->rule_type ),
            'weekday'    => $entity->weekday,
            'date_from'  => $entity->date_from,
            'date_to'    => $entity->date_to,
            'time_from'  => $entity->time_from,
            'time_to'    => $entity->time_to,
            'capacity'   => $entity->capacity,
            'meta'       => $entity->meta ? wp_json_encode( $entity->meta ) : null,
        ], [ 'id' => $entity->id ] );

        return false !== $result;
    }

    private function normalize_time_value( ?string $time ): ?string {
        if ( null === $time || '' === $time ) {
            return $time;
        }

        if ( preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
            return $time . ':00';
        }

        return $time;
    }

    private function legacy_time_value( string $time ): string {
        if ( preg_match( '/^(\d{2}:\d{2}):00$/', $time, $m ) ) {
            return $m[1];
        }

        return $time;
    }

    public function delete_rule( int $id ): bool {
        return false !== $this->wpdb->delete( $this->rules_table, [ 'id' => $id ], [ '%d' ] );
    }

    public function delete_rules_by_scope( string $scope_type, int $scope_id ): void {
        $this->wpdb->delete( $this->rules_table, [
            'scope_type' => $scope_type,
            'scope_id'   => $scope_id,
        ] );
    }

    public function delete_blocks_by_scope( string $scope_type, int $scope_id ): void {
        $this->wpdb->delete( $this->blocks_table, [
            'scope_type' => $scope_type,
            'scope_id'   => $scope_id,
        ] );
    }

    public function get_blocks( string $scope_type = 'global', ?int $scope_id = null, ?string $date_from = null, ?string $date_to = null ): array {
        $where  = [ '1=1' ];
        $params = [];

        $where[]  = 'scope_type = %s';
        $params[] = $scope_type;

        if ( $scope_id !== null ) {
            $where[]  = 'scope_id = %d';
            $params[] = $scope_id;
        }
        if ( $date_from ) {
            $where[]  = 'end_at >= %s';
            $params[] = $date_from;
        }
        if ( $date_to ) {
            $where[]  = 'start_at <= %s';
            $params[] = $date_to;
        }

        $where_clause = implode( ' AND ', $where );
        $sql = "SELECT * FROM {$this->blocks_table} WHERE {$where_clause} ORDER BY start_at ASC";

        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );
        return $rows ?: [];
    }

    public function get_applicable_blocks( int $service_id, ?int $resource_id, string $date_from, string $date_to ): array {
        $sql = "SELECT * FROM {$this->blocks_table}
                WHERE (
                    (scope_type = 'global')
                    OR (scope_type = 'service' AND scope_id = %d)";

        $params = [ $service_id ];

        if ( $resource_id ) {
            $sql .= " OR (scope_type = 'resource' AND scope_id = %d)";
            $params[] = $resource_id;
        }

        $sql .= ") AND end_at >= %s AND start_at <= %s ORDER BY start_at ASC";
        $params[] = $date_from;
        $params[] = $date_to;

        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );
        return $rows ?: [];
    }

    public function insert_block( array $data ): int {
        $this->wpdb->insert( $this->blocks_table, [
            'scope_type' => sanitize_text_field( $data['scope_type'] ?? 'global' ),
            'scope_id'   => $data['scope_id'] ?? null,
            'start_at'   => sanitize_text_field( $data['start_at'] ),
            'end_at'     => sanitize_text_field( $data['end_at'] ),
            'reason'     => sanitize_text_field( $data['reason'] ?? '' ) ?: null,
        ] );

        return (int) $this->wpdb->insert_id;
    }

    public function delete_block( int $id ): bool {
        return false !== $this->wpdb->delete( $this->blocks_table, [ 'id' => $id ], [ '%d' ] );
    }

    private function fetch_rules( string $where_clause, array $params ): array {
        $sql  = "SELECT * FROM {$this->rules_table} WHERE {$where_clause} ORDER BY weekday ASC, time_from ASC";
        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );

        return $this->hydrate_rule_rows( $rows ?: [] );
    }

    private function hydrate_rule_rows( array $rows ): array {
        return array_map( function ( $row ) {
            return \OpenBooking\Domain\Availability\Entity\AvailabilityRule_Entity::from_array( $row );
        }, $rows );
    }

    public function rules_table_exists(): bool {
        $table = $this->wpdb->prefix . 'ob_availability_rules';
        return $this->wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
    }

    public function count_invalid_time_range_rules(): int {
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->rules_table}
             WHERE time_from >= time_to"
        );
    }
}
