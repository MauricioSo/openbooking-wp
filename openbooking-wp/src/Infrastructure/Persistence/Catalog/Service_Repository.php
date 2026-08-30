<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Persistence\Catalog;

use OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de catalogo.
 */

class Service_Repository implements ServiceRepositoryInterface {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_services';
    }

    public function find( int $id ): ?\OpenBooking\Domain\Catalog\Entity\Service_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Catalog\Entity\Service_Entity::from_array( $row ) : null;
    }

    public function find_by_slug( string $slug ): ?\OpenBooking\Domain\Catalog\Entity\Service_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE slug = %s", $slug ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Catalog\Entity\Service_Entity::from_array( $row ) : null;
    }

    public function find_all( array $args = [] ): array {
        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = sanitize_text_field( $args['status'] );
        }

        if ( ! empty( $args['visibility'] ) ) {
            $where[]  = 'visibility = %s';
            $params[] = sanitize_text_field( $args['visibility'] );
        }

        $where_clause = implode( ' AND ', $where );

        $allowed_order_by = [ 'id', 'name', 'status', 'created_at', 'price_minor', 'duration_minutes' ];
        $order_by = in_array( $args['order_by'] ?? '', $allowed_order_by, true )
            ? $args['order_by']
            : 'id';
        $order = in_array( strtoupper( $args['order'] ?? '' ), [ 'ASC', 'DESC' ], true )
            ? strtoupper( $args['order'] )
            : 'DESC';

        $sql = "SELECT * FROM {$this->table} WHERE {$where_clause} ORDER BY {$order_by} {$order}";

        if ( ! empty( $params ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$params );
        }

        $rows = $this->wpdb->get_results( $sql, ARRAY_A );

        return array_map( function ( $row ) {
            return \OpenBooking\Domain\Catalog\Entity\Service_Entity::from_array( $row );
        }, $rows ?: [] );
    }

    public function find_active_public(): array {
        return $this->find_all( [ 'status' => 'active', 'visibility' => 'public' ] );
    }

    public function insert( \OpenBooking\Domain\Catalog\Entity\Service_Entity $entity ): int {
        $slug = $entity->slug ?: sanitize_title( $entity->name );
        $slug = $this->unique_slug( $slug );

        $this->wpdb->insert( $this->table, [
            'name'                  => sanitize_text_field( $entity->name ),
            'slug'                  => $slug,
            'description'           => sanitize_textarea_field( $entity->description ?? '' ),
            'duration_minutes'      => absint( $entity->duration_minutes ),
            'buffer_before_minutes' => absint( $entity->buffer_before_minutes ),
            'buffer_after_minutes'  => absint( $entity->buffer_after_minutes ),
            'price_minor'           => absint( $entity->price_minor ),
            'currency'              => sanitize_text_field( $entity->currency ),
            'capacity'              => absint( $entity->capacity ),
            'mode'                  => sanitize_text_field( $entity->mode ),
            'status'                => sanitize_text_field( $entity->status ),
            'color'                 => sanitize_hex_color( $entity->color ?? '' ) ?: null,
            'visibility'            => sanitize_text_field( $entity->visibility ),
        ] );

        return (int) $this->wpdb->insert_id;
    }

    public function update( \OpenBooking\Domain\Catalog\Entity\Service_Entity $entity ): bool {
        if ( ! $entity->id ) {
            return false;
        }

        $result = $this->wpdb->update( $this->table, [
            'name'                  => sanitize_text_field( $entity->name ),
            'slug'                  => sanitize_text_field( $entity->slug ),
            'description'           => sanitize_textarea_field( $entity->description ?? '' ),
            'duration_minutes'      => absint( $entity->duration_minutes ),
            'buffer_before_minutes' => absint( $entity->buffer_before_minutes ),
            'buffer_after_minutes'  => absint( $entity->buffer_after_minutes ),
            'price_minor'           => absint( $entity->price_minor ),
            'currency'              => sanitize_text_field( $entity->currency ),
            'capacity'              => absint( $entity->capacity ),
            'mode'                  => sanitize_text_field( $entity->mode ),
            'status'                => sanitize_text_field( $entity->status ),
            'color'                 => sanitize_hex_color( $entity->color ?? '' ) ?: null,
            'visibility'            => sanitize_text_field( $entity->visibility ),
        ], [ 'id' => $entity->id ] );

        return false !== $result;
    }

    /**
     * Fetches multiple services by ID in a single query.
     * Returns a map of id => Service_Entity for fast lookup.
     *
     * @param int[] $ids
     * @return array<int, \OpenBooking\Domain\Catalog\Entity\Service_Entity>
     */
    public function find_by_ids( array $ids ): array {
        $ids = array_filter( array_map( 'absint', $ids ) );
        if ( empty( $ids ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated, not user input.
        $sql  = $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id IN ({$placeholders})", $ids );
        $rows = $this->wpdb->get_results( $sql, ARRAY_A );

        $map = [];
        foreach ( $rows ?: [] as $row ) {
            $entity       = \OpenBooking\Domain\Catalog\Entity\Service_Entity::from_array( $row );
            $map[ $entity->id ] = $entity;
        }

        return $map;
    }

    public function delete( int $id ): bool {
        return false !== $this->wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
    }

    public function archive( int $id ): bool {
        $entity = $this->find( $id );
        if ( ! $entity ) {
            return false;
        }

        $booking_count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->wpdb->prefix}ob_bookings WHERE service_id = %d AND status IN ('pending','confirmed')",
                $id
            )
        );

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

    private function unique_slug( string $slug, int $exclude_id = 0 ): string {
        $original = $slug;
        $counter  = 1;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix + hardcoded suffix.
        $table = '`' . esc_sql( $this->table ) . '`';
        while ( true ) {
            if ( $exclude_id ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $query = $this->wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s AND id != %d", $slug, $exclude_id );
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $query = $this->wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE slug = %s", $slug );
            }
            if ( 0 === (int) $this->wpdb->get_var( $query ) ) {
                break;
            }
            $slug = $original . '-' . $counter;
            $counter++;
        }
        return $slug;
    }
}
