<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Persistence\Catalog;

use OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de catalogo.
 */

class Resource_Repository implements ResourceRepositoryInterface {

    private \wpdb $wpdb;
    private string $table;
    private string $pivot_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb        = $wpdb;
        $this->table       = $wpdb->prefix . 'ob_resources';
        $this->pivot_table = $wpdb->prefix . 'ob_service_resources';
    }

    public function find( int $id ): ?\OpenBooking\Domain\Catalog\Entity\Resource_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Catalog\Entity\Resource_Entity::from_array( $row ) : null;
    }

    public function find_all( array $args = [] ): array {
        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = sanitize_text_field( $args['status'] );
        }

        $where_clause = implode( ' AND ', $where );
        $sql = "SELECT * FROM {$this->table} WHERE {$where_clause} ORDER BY name ASC";

        if ( ! empty( $params ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$params );
        }

        $rows = $this->wpdb->get_results( $sql, ARRAY_A );
        return array_map( function ( $row ) {
            return \OpenBooking\Domain\Catalog\Entity\Resource_Entity::from_array( $row );
        }, $rows ?: [] );
    }

    public function count_by_status( string $status ): int {
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = %s",
            sanitize_text_field( $status )
        );
        return (int) $this->wpdb->get_var( $sql );
    }

    public function find_by_service( int $service_id ): array {
        $sql = $this->wpdb->prepare(
            "SELECT r.* FROM {$this->table} r
             INNER JOIN {$this->pivot_table} sr ON r.id = sr.resource_id
             WHERE sr.service_id = %d AND r.status = 'active'",
            $service_id
        );
        $rows = $this->wpdb->get_results( $sql, ARRAY_A );
        return array_map( function ( $row ) {
            return \OpenBooking\Domain\Catalog\Entity\Resource_Entity::from_array( $row );
        }, $rows ?: [] );
    }

    public function insert( \OpenBooking\Domain\Catalog\Entity\Resource_Entity $entity ): int {
        $this->wpdb->insert( $this->table, [
            'name'     => sanitize_text_field( $entity->name ),
            'type'     => sanitize_text_field( $entity->type ),
            'status'   => sanitize_text_field( $entity->status ),
            'capacity' => absint( $entity->capacity ),
            'meta'     => $entity->meta ? wp_json_encode( $entity->meta ) : null,
        ] );

        return (int) $this->wpdb->insert_id;
    }

    public function update( \OpenBooking\Domain\Catalog\Entity\Resource_Entity $entity ): bool {
        if ( ! $entity->id ) {
            return false;
        }
        $result = $this->wpdb->update( $this->table, [
            'name'     => sanitize_text_field( $entity->name ),
            'type'     => sanitize_text_field( $entity->type ),
            'status'   => sanitize_text_field( $entity->status ),
            'capacity' => absint( $entity->capacity ),
            'meta'     => $entity->meta ? wp_json_encode( $entity->meta ) : null,
        ], [ 'id' => $entity->id ] );

        return false !== $result;
    }

    public function delete( int $id ): bool {
        $this->wpdb->delete( $this->pivot_table, [ 'resource_id' => $id ], [ '%d' ] );
        return false !== $this->wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
    }

    public function archive( int $id ): bool {
        $entity = $this->find( $id );
        if ( ! $entity ) {
            return false;
        }

        $this->wpdb->update( $this->table, [
            'status'      => 'archived',
            'archived_at' => current_time( 'mysql', true ),
        ], [ 'id' => $id ] );

        return true;
    }

    public function restore( int $id ): bool {
        $this->wpdb->update( $this->table, [
            'status'      => 'active',
            'archived_at' => null,
        ], [ 'id' => $id ] );

        return false !== $this->wpdb->rows_affected;
    }

    public function attach_to_service( int $service_id, int $resource_id ): void {
        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->pivot_table} WHERE service_id = %d AND resource_id = %d",
                $service_id, $resource_id
            )
        );
        if ( ! $exists ) {
            $this->wpdb->insert( $this->pivot_table, [
                'service_id'  => $service_id,
                'resource_id' => $resource_id,
            ] );
        }
    }

    public function detach_from_service( int $service_id, int $resource_id ): void {
        $this->wpdb->delete( $this->pivot_table, [
            'service_id'  => $service_id,
            'resource_id' => $resource_id,
        ], [ '%d', '%d' ] );
    }

    public function detach_all_services( int $resource_id ): void {
        $this->wpdb->delete( $this->pivot_table, [ 'resource_id' => $resource_id ], [ '%d' ] );
    }

    public function get_service_ids_for_resource( int $resource_id ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare( "SELECT service_id FROM {$this->pivot_table} WHERE resource_id = %d", $resource_id ),
            ARRAY_A
        );
        return array_map( 'intval', wp_list_pluck( $rows ?: [], 'service_id' ) );
    }

    public function get_service_names_for_resource( int $resource_id ): array {
        $service_table = $this->wpdb->prefix . 'ob_services';
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT s.name FROM {$service_table} s INNER JOIN {$this->pivot_table} sr ON s.id = sr.service_id WHERE sr.resource_id = %d",
                $resource_id
            ),
            ARRAY_A
        );
        return wp_list_pluck( $rows ?: [], 'name' );
    }

    public function sync_services( int $resource_id, array $service_ids ): void {
        $current   = $this->get_service_ids_for_resource( $resource_id );
        $new_ids   = array_map( 'absint', $service_ids );
        $to_remove = array_diff( $current, $new_ids );
        $to_add    = array_diff( $new_ids, $current );
        foreach ( $to_remove as $sid ) {
            $this->detach_from_service( $sid, $resource_id );
        }
        foreach ( $to_add as $sid ) {
            $this->attach_to_service( $sid, $resource_id );
        }
    }
}
