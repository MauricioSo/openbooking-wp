<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Persistence\Availability;

use OpenBooking\Domain\Availability\Repository\AvailabilitySnapshotRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de disponibilidad.
 */

class Availability_Snapshot_Repository implements AvailabilitySnapshotRepositoryInterface {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_availability_snapshots';
    }

    public function insert_snapshot( string $scope_type, ?int $scope_id, ?string $label, array $rules_data, array $blocks, ?int $created_by ): int {
        $this->wpdb->insert( $this->table, [
            'scope_type' => sanitize_text_field( $scope_type ),
            'scope_id'   => $scope_id,
            'label'      => $label ? sanitize_text_field( $label ) : null,
            'rules_json'  => wp_json_encode( $rules_data ),
            'blocks_json' => wp_json_encode( $blocks ),
            'created_by'  => $created_by,
        ] );

        return (int) $this->wpdb->insert_id;
    }

    public function list_snapshots( string $scope_type, ?int $scope_id, int $limit ): array {
        $sql    = "SELECT * FROM {$this->table} WHERE scope_type = %s";
        $params = [ $scope_type ];

        if ( $scope_id !== null ) {
            $sql     .= ' AND scope_id = %d';
            $params[] = $scope_id;
        }

        $sql     .= ' ORDER BY created_at DESC LIMIT %d';
        $params[] = $limit;

        return $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
    }

    public function find_snapshot( int $id ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public function delete_snapshot( int $id ): bool {
        return false !== $this->wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
    }

    public function begin(): void {
        $this->wpdb->query( 'START TRANSACTION' );
    }

    public function commit(): void {
        $this->wpdb->query( 'COMMIT' );
    }

    public function rollback(): void {
        $this->wpdb->query( 'ROLLBACK' );
    }
}
