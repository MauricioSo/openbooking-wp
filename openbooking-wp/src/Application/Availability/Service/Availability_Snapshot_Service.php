<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Availability\Service;

use OpenBooking\Domain\Availability\Repository\AvailabilityConfigRepositoryInterface;
use OpenBooking\Domain\Availability\Repository\AvailabilitySnapshotRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Availability_Snapshot_Service {


    public function __construct(
        private AvailabilityConfigRepositoryInterface $repo,
        private AvailabilitySnapshotRepositoryInterface $snapshot_repo,
        private Availability_Service $availability_service,
    ) {}

    public function create_snapshot( string $scope_type, ?int $scope_id, ?string $label = null, ?int $created_by = null ): int {
        $rules  = $this->repo->get_rules( $scope_type, $scope_id );
        $blocks = $this->repo->get_blocks( $scope_type, $scope_id );

        $rules_data  = array_map( fn( $r ) => $r->to_array(), $rules );

        return $this->snapshot_repo->insert_snapshot( $scope_type, $scope_id, $label, $rules_data, $blocks, $created_by );
    }

    public function list_snapshots( string $scope_type, ?int $scope_id, int $limit = 20 ): array {
        return $this->snapshot_repo->list_snapshots( $scope_type, $scope_id, $limit );
    }

    public function get_snapshot( int $id ): ?array {
        return $this->snapshot_repo->find_snapshot( $id );
    }

    public function restore_snapshot( int $id ): bool {
        $snapshot = $this->get_snapshot( $id );
        if ( ! $snapshot ) {
            return false;
        }

        $scope_type = $snapshot['scope_type'];
        $scope_id   = $snapshot['scope_id'] !== null && $snapshot['scope_id'] !== '' ? (int) $snapshot['scope_id'] : null;

        $this->create_snapshot( $scope_type, $scope_id, 'Auto-snapshot before restore #' . $id );

        try {
            $this->snapshot_repo->begin();

            $this->repo->delete_rules_by_scope( $scope_type, $scope_id ?? 0 );
            $this->repo->delete_blocks_by_scope( $scope_type, $scope_id ?? 0 );

            $rules_data = json_decode( $snapshot['rules_json'], true );
            if ( is_array( $rules_data ) ) {
                foreach ( $rules_data as $rule_row ) {
                    if ( ! is_array( $rule_row ) ) {
                        continue;
                    }
                    $rule_row['scope_type'] = $scope_type;
                    $rule_row['scope_id']   = $scope_id;
                    $entity = \OpenBooking\Domain\Availability\Entity\AvailabilityRule_Entity::from_array( $rule_row );
                    $entity->id = null;
                    $this->repo->insert_rule( $entity );
                }
            }

            $blocks_data = json_decode( $snapshot['blocks_json'], true );
            if ( is_array( $blocks_data ) ) {
                foreach ( $blocks_data as $block_row ) {
                    if ( ! is_array( $block_row ) ) {
                        continue;
                    }
                    $block_row['scope_type'] = $scope_type;
                    $block_row['scope_id']   = $scope_id;
                    $this->repo->insert_block( $block_row );
                }
            }

            $this->snapshot_repo->commit();
        } catch ( \Throwable $e ) {
            $this->snapshot_repo->rollback();
            return false;
        }

        $this->availability_service->invalidate_all_cache();

        return true;
    }

    public function delete_snapshot( int $id ): bool {
        return $this->snapshot_repo->delete_snapshot( $id );
    }
}
