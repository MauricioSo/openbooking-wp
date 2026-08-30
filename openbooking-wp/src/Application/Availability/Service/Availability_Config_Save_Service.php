<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Availability\Service;

use OpenBooking\Domain\Availability\Repository\AvailabilityConfigRepositoryInterface;
use OpenBooking\Domain\Availability\Entity\AvailabilityRule_Entity;
use OpenBooking\Domain\Shared\Port\ActorContextInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Availability_Config_Save_Service {


    public function __construct(
        private AvailabilityConfigRepositoryInterface $repo,
        private Availability_Service $engine,
        private Availability_Snapshot_Service $snapshot_service,
        private ActorContextInterface $actor_context,
    ) {}

	public function save_settings( string $scope_type, int $scope_id, array $body ): array {
		$auto_snapshot = ! empty( $body['auto_snapshot'] );
		if ( $auto_snapshot ) {
			$this->snapshot_service->create_snapshot( $scope_type, $scope_id ?: null, 'Auto-snapshot before save', $this->actor_context->get_current_user_id() );
		}

		$this->repo->delete_rules_by_scope( $scope_type, $scope_id );
		$this->repo->delete_blocks_by_scope( $scope_type, $scope_id );

		if ( ! empty( $body['rules'] ) ) {
			foreach ( $body['rules'] as $rule_data ) {
				if ( ! is_array( $rule_data ) ) {
					continue;
				}
				$sanitized = [
					'scope_type' => $scope_type,
					'scope_id'   => $scope_id,
					'rule_type'  => sanitize_text_field( $rule_data['rule_type'] ?? 'weekly' ),
					'weekday'    => isset( $rule_data['weekday'] ) ? absint( $rule_data['weekday'] ) : null,
					'date_from'  => ! empty( $rule_data['date_from'] ) ? sanitize_text_field( $rule_data['date_from'] ) : null,
					'date_to'    => ! empty( $rule_data['date_to'] ) ? sanitize_text_field( $rule_data['date_to'] ) : null,
					'time_from'  => ! empty( $rule_data['time_from'] ) ? sanitize_text_field( $rule_data['time_from'] ) : null,
					'time_to'    => ! empty( $rule_data['time_to'] ) ? sanitize_text_field( $rule_data['time_to'] ) : null,
					'capacity'   => isset( $rule_data['capacity'] ) ? absint( $rule_data['capacity'] ) : null,
				];
				$entity = AvailabilityRule_Entity::from_array( $sanitized );
				$this->repo->insert_rule( $entity );
			}
		}

		if ( ! empty( $body['blocks'] ) ) {
			foreach ( $body['blocks'] as $block ) {
				$block['scope_type'] = $scope_type;
				$block['scope_id']   = $scope_id;
				$this->repo->insert_block( $block );
			}
		}

		$this->engine->invalidate_all_cache();

		return [ 'success' => true ];
	}

	public function import_csv( string $csv ): array {
		if ( '' === trim( $csv ) ) {
			return [ 'error' => 'CSV requerido.' ];
		}

		$handle   = fopen( 'php://temp', 'r+' );
		$inserted = [ 'rules' => 0, 'blocks' => 0 ];

		fwrite( $handle, $csv );
		rewind( $handle );

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle );
			return [ 'error' => 'CSV inválido.' ];
		}

		$normalized = array_map( static function ( $column ): string {
			return sanitize_key( remove_accents( (string) $column ) );
		}, $header );

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( count( $row ) !== count( $normalized ) ) {
				continue;
			}

			$record      = array_combine( $normalized, $row );
			$date        = sanitize_text_field( $record['fecha'] ?? '' );
			$time_from   = sanitize_text_field( $record['hora_inicio'] ?? '' );
			$time_to     = sanitize_text_field( $record['hora_fin'] ?? '' );
			$resource_id = absint( $record['recurso_id'] ?? 0 );
			$type        = sanitize_key( $record['tipo'] ?? '' );

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! $this->is_valid_time_hm( $time_from ) || ! $this->is_valid_time_hm( $time_to ) || $time_from >= $time_to ) {
				continue;
			}

			$scope_type = $resource_id ? 'resource' : 'global';
			$scope_id   = $resource_id ?: 0;

			if ( 'blocked' === $type ) {
				$this->repo->insert_block( [
					'scope_type' => $scope_type,
					'scope_id'   => $scope_id,
					'start_at'   => $date . ' ' . $time_from . ':00',
					'end_at'     => $date . ' ' . $time_to . ':00',
					'reason'     => 'Imported from CSV',
				] );
				$inserted['blocks']++;
				continue;
			}

			if ( 'available' === $type ) {
				$entity = AvailabilityRule_Entity::from_array( [
					'scope_type' => $scope_type,
					'scope_id'   => $scope_id,
					'rule_type'  => 'date_specific',
					'date_from'  => $date,
					'date_to'    => $date,
					'time_from'  => $time_from . ':00',
					'time_to'    => $time_to . ':00',
				] );
				$this->repo->insert_rule( $entity );
				$inserted['rules']++;
			}
		}

		fclose( $handle );
		$this->engine->invalidate_all_cache();

		return [ 'success' => true, 'inserted' => $inserted ];
	}

	private function is_valid_time_hm( string $time ): bool {
		if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time, $m ) ) {
			return false;
		}

		$hour   = (int) $m[1];
		$minute = (int) $m[2];

		return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59;
	}
}
