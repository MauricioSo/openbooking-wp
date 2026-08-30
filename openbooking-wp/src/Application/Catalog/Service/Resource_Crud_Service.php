<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Catalog\Service;

use OpenBooking\Support\Free_Core_Limits;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Resource_Crud_Service {


    public function __construct(
        private \OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface $resource_repo,
    ) {}

	public function create( array $body ): array {
		$body = $this->sanitize_resource_payload( $body );
		$entity           = new \OpenBooking\Domain\Catalog\Entity\Resource_Entity();
		$entity->name     = $body['name'] ?? '';
		$entity->type     = $body['type'] ?? 'person';
		$entity->status   = $body['status'] ?? 'active';
		$entity->capacity = $body['capacity'] ?? 1;

        if ( empty( $entity->name ) ) {
            return [ 'error' => 'El nombre es obligatorio.' ];
        }

        if ( $this->active_name_exists( $entity->name ) ) {
            return [ 'error' => 'Ya existe un recurso activo con ese nombre.' ];
        }

        if ( 'active' === $entity->status ) {
            global $wpdb;
            $wpdb->query( 'START TRANSACTION' );

            $wpdb->query(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ob_resources WHERE status = 'active' FOR UPDATE"
                )
            );

            if ( ! $this->can_activate_resource() ) {
                $wpdb->query( 'ROLLBACK' );
                return [ 'error' => $this->resource_limit_message() ];
            }
        }

        $id = $this->resource_repo->insert( $entity );

        if ( 'active' === $entity->status ) {
            $wpdb->query( 'COMMIT' );
        }

        if ( ! empty( $body['service_ids'] ) && is_array( $body['service_ids'] ) ) {
            $this->resource_repo->sync_services( $id, $body['service_ids'] );
        }
        return [ 'success' => true, 'id' => $id ];
    }

	public function update( int $id, array $body ): array {
		$resource = $this->resource_repo->find( $id );
		if ( ! $resource ) {
			return [ 'error' => 'Recurso no encontrado.' ];
		}
		$body = $this->sanitize_resource_payload( $body );
		if ( isset( $body['name'] ) )     $resource->name     = $body['name'];
		if ( isset( $body['type'] ) )     $resource->type     = $body['type'];
		$previous_status = $resource->status;
		if ( isset( $body['status'] ) )   $resource->status   = $body['status'];
		if ( isset( $body['capacity'] ) ) $resource->capacity = $body['capacity'];

        if ( isset( $body['name'] ) && $this->active_name_exists( $resource->name, $id ) ) {
            return [ 'error' => 'Ya existe un recurso activo con ese nombre.' ];
        }

        if ( 'active' !== $previous_status && 'active' === $resource->status && ! $this->can_activate_resource( $id ) ) {
            return [ 'error' => $this->resource_limit_message() ];
        }

        $this->resource_repo->update( $resource );
        if ( isset( $body['service_ids'] ) && is_array( $body['service_ids'] ) ) {
            $this->resource_repo->sync_services( $id, $body['service_ids'] );
        }
        return [ 'success' => true ];
    }

    public function delete( int $id, bool $force ): array {
        $entity = $this->resource_repo->find( $id );
        if ( ! $entity ) {
            return [ 'error' => 'Recurso no encontrado.' ];
        }
        if ( $force ) {
            $this->resource_repo->delete( $id );
        } else {
            $this->resource_repo->archive( $id );
        }
        return [ 'success' => true, 'action' => $force ? 'deleted' : 'archived' ];
    }

    private function can_activate_resource( int $exclude_id = 0 ): bool {
        $active_count = $this->resource_repo->count_by_status( 'active' );

        if ( $exclude_id > 0 ) {
            $resource = $this->resource_repo->find( $exclude_id );
            if ( $resource && 'active' === $resource->status ) {
                $active_count--;
            }
        }

        return $active_count < Free_Core_Limits::active_resources();
    }

	private function resource_limit_message(): string {
        return sprintf(
            /* translators: %d: active resource limit in Free Core. */
            __( 'El Core Free permite hasta %d recursos o profesionales activos. Archiva uno existente o amplia este limite desde una extension externa.', 'openbooking-wp' ),
            Free_Core_Limits::active_resources()
        );
	}

	private function sanitize_resource_payload( array $body ): array {
		if ( isset( $body['name'] ) ) {
			$body['name'] = sanitize_text_field( $body['name'] );
		}

		if ( isset( $body['type'] ) ) {
			$body['type'] = sanitize_key( $body['type'] );
			if ( ! in_array( $body['type'], [ 'person', 'room', 'equipment', 'other' ], true ) ) {
				unset( $body['type'] );
			}
		}

		if ( isset( $body['status'] ) ) {
			$body['status'] = sanitize_key( $body['status'] );
			if ( ! in_array( $body['status'], [ 'active', 'inactive', 'archived' ], true ) ) {
				unset( $body['status'] );
			}
		}

		if ( isset( $body['capacity'] ) ) {
			$body['capacity'] = max( 1, (int) $body['capacity'] );
		}

		if ( isset( $body['service_ids'] ) && is_array( $body['service_ids'] ) ) {
			$body['service_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $body['service_ids'] ) ) ) );
		}

		return $body;
	}

    private function active_name_exists( string $name, int $exclude_id = 0 ): bool {
        $needle = strtolower( trim( $name ) );
        if ( '' === $needle ) {
            return false;
        }

        foreach ( $this->resource_repo->find_all( [ 'status' => 'active' ] ) as $resource ) {
            if ( (int) $resource->id === $exclude_id ) {
                continue;
            }
            if ( strtolower( trim( (string) $resource->name ) ) === $needle ) {
                return true;
            }
        }

        return false;
    }
}
