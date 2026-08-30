<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Catalog\Service;

use OpenBooking\Domain\Shared\Port\ActorContextInterface;
use OpenBooking\Support\Service_Payloads;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Service_Crud_Service {

    private const UPDATABLE_FIELDS = [
        'name', 'slug', 'description', 'duration_minutes',
        'buffer_before_minutes', 'buffer_after_minutes',
        'price_minor', 'currency', 'capacity', 'mode', 'status',
        'color', 'visibility',
    ];


    public function __construct(
        private \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface $service_repo,
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo,
        private \OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface $audit_repo,
        private ActorContextInterface $actor_context,
    ) {}

	public function create( array $body, callable $sanitize_currency ): array {
		$body = $this->sanitize_service_payload( $body );
		if ( '' === (string) ( $body['name'] ?? '' ) ) {
			return [ 'error' => 'El nombre del servicio es obligatorio.', 'code' => 400 ];
		}
		if ( isset( $body['currency'] ) ) {
            $currency = $sanitize_currency( $body['currency'] );
            if ( null === $currency ) {
                return [ 'error' => 'Moneda no soportada.' ];
            }
            $body['currency'] = $currency;
        }
		$validation = $this->validate_service_payload( $body );
		if ( ! empty( $validation['error'] ) ) {
			return $validation;
		}
        $sanitized = [];
        foreach ( $body as $key => $val ) {
            if ( in_array( $key, self::UPDATABLE_FIELDS, true ) ) {
                $sanitized[ $key ] = $val;
            }
        }
        $entity = \OpenBooking\Domain\Catalog\Entity\Service_Entity::from_array( $sanitized );
        $id = $this->service_repo->insert( $entity );
        return [ 'success' => true, 'id' => $id ];
    }

	public function update( int $id, array $body, callable $sanitize_currency ): array {
		$entity = $this->service_repo->find( $id );
		if ( ! $entity ) {
			return [ 'error' => 'Servicio no encontrado.' ];
		}
		if ( array_key_exists( 'expected_updated_at', $body ) ) {
			$expected = trim( (string) $body['expected_updated_at'] );
			$current  = trim( (string) ( $entity->updated_at ?? '' ) );
			if ( '' !== $expected && $expected !== $current ) {
				return [ 'error' => 'El servicio fue modificado por otra persona. Recarga la pagina e intenta de nuevo.', 'code' => 409 ];
			}
		}
		$body = $this->sanitize_service_payload( $body );
		if ( array_key_exists( 'name', $body ) && '' === (string) $body['name'] ) {
			return [ 'error' => 'El nombre del servicio es obligatorio.', 'code' => 400 ];
		}
		$validation = $this->validate_service_payload( $body, $id );
		if ( ! empty( $validation['error'] ) ) {
			return $validation;
		}
		if ( isset( $body['currency'] ) ) {
            $currency = $sanitize_currency( $body['currency'] );
            if ( null === $currency ) {
                return [ 'error' => 'Moneda no soportada.' ];
            }
            $body['currency'] = $currency;
        }

        $warnings = $this->validate_retroactive_changes( $id, $entity, $body );
        if ( ! empty( $body['_force'] ) ) {
            unset( $body['_force'] );
        } elseif ( ! empty( $warnings ) ) {
            return [ 'error' => 'Cambios con impacto en reservas existentes.', 'warnings' => $warnings, 'code' => 'retroactive_warning' ];
        }

        $before = Service_Payloads::public_from_entity( $entity );
        foreach ( self::UPDATABLE_FIELDS as $field ) {
            if ( isset( $body[ $field ] ) ) {
                $entity->$field = $body[ $field ];
            }
        }
        $this->service_repo->update( $entity );

        $after = Service_Payloads::public_from_entity( $entity );
        $changed = [];
        foreach ( self::UPDATABLE_FIELDS as $field ) {
            if ( ( $before[ $field ] ?? null ) !== ( $after[ $field ] ?? null ) ) {
                $changed[ $field ] = [ 'before' => $before[ $field ] ?? null, 'after' => $after[ $field ] ?? null ];
            }
        }
        if ( ! empty( $changed ) ) {
            $this->audit_repo->insert( [
                'entity_type'    => 'service',
                'entity_id'      => $id,
                'action'         => 'service_updated',
                'actor_type'     => $this->actor_context->is_user_logged_in() ? 'admin' : 'system',
                'actor_id'       => $this->actor_context->get_current_user_id() ?: null,
                'context'        => $changed,
                'changed_fields' => array_keys( $changed ),
                'severity'       => 'info',
                'source'         => 'admin_api',
            ] );
        }

        return [ 'success' => true ];
    }

    public function delete( int $id, bool $force ): array {
        $entity = $this->service_repo->find( $id );
        if ( ! $entity ) {
            return [ 'error' => 'Servicio no encontrado.' ];
        }
        if ( $force ) {
            $this->service_repo->delete( $id );
        } else {
            $this->service_repo->archive( $id );
        }
        return [ 'success' => true, 'action' => $force ? 'deleted' : 'archived' ];
    }

	private function validate_retroactive_changes( int $service_id, $entity, array $body ): array {
		$warnings = [];

		$has_active = $this->booking_repo->count_active_for_service( $service_id );

		if ( ! $has_active ) {
			return $warnings;
		}

		$breaking_fields = [
			'currency'         => 'Cambiar la moneda afecta reservas existentes que ya tienen precios en ' . ( $entity->currency ?? 'la moneda original' ) . '.',
			'price_minor'      => 'Cambiar el precio no afecta reservas existentes (ya tienen su propio precio), pero las nuevas reservas tendrán precio diferente.',
			'capacity'         => 'Reducir la capacidad podría dejar reservas confirmadas por encima del nuevo límite.',
			'duration_minutes' => 'Cambiar la duración no afecta reservas existentes (conservan su horario original), pero las nuevas reservas usarán la nueva duración.',
		];

		foreach ( $breaking_fields as $field => $message ) {
			if ( isset( $body[ $field ] ) && $body[ $field ] != $entity->$field ) {
				$warnings[] = $message;
			}
		}

		return $warnings;
	}

	private function sanitize_service_payload( array $body ): array {
		$text_fields = [ 'name', 'slug', 'description', 'color' ];
		foreach ( $text_fields as $field ) {
			if ( isset( $body[ $field ] ) ) {
				$body[ $field ] = sanitize_text_field( $body[ $field ] );
			}
		}

		foreach ( [ 'duration_minutes', 'capacity' ] as $field ) {
			if ( isset( $body[ $field ] ) ) {
				$body[ $field ] = max( 1, (int) $body[ $field ] );
			}
		}

		foreach ( [ 'buffer_before_minutes', 'buffer_after_minutes', 'price_minor' ] as $field ) {
			if ( isset( $body[ $field ] ) ) {
				$body[ $field ] = max( 0, (int) $body[ $field ] );
			}
		}

		foreach ( [ 'mode', 'status', 'visibility' ] as $field ) {
			if ( isset( $body[ $field ] ) ) {
				$body[ $field ] = sanitize_key( $body[ $field ] );
			}
		}

		if ( isset( $body['status'] ) && ! in_array( $body['status'], [ 'draft', 'active', 'inactive', 'archived' ], true ) ) {
			unset( $body['status'] );
		}
		if ( isset( $body['visibility'] ) && ! in_array( $body['visibility'], [ 'public', 'private' ], true ) ) {
			unset( $body['visibility'] );
		}

		return $body;
	}

	private function validate_service_payload( array $body, int $exclude_id = 0 ): array {
		if ( isset( $body['name'] ) ) {
			$name = trim( (string) $body['name'] );
			$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );

			if ( $len < 2 ) {
				return [ 'error' => 'El nombre del servicio debe tener al menos 2 caracteres.', 'code' => 400 ];
			}
			if ( $len > 191 ) {
				return [ 'error' => 'El nombre del servicio no puede superar 191 caracteres.', 'code' => 400 ];
			}
			if ( preg_match( '/(--|\/\*|\*\/|\b(or|and)\s+\d+\s*=\s*\d+)/i', $name ) || preg_match( '/[<>\/\\?]/u', $name ) ) {
				return [ 'error' => 'El nombre del servicio contiene caracteres no permitidos.', 'code' => 400 ];
			}

			$slug = isset( $body['slug'] ) && $body['slug'] !== '' ? $this->slugify( (string) $body['slug'] ) : $this->slugify( $name );
			if ( '' === $slug ) {
				return [ 'error' => 'El nombre del servicio no genera un slug válido.', 'code' => 400 ];
			}

			$existing = $this->service_repo->find_by_slug( $slug );
			if ( $existing && (int) $existing->id !== $exclude_id && 'archived' !== $existing->status ) {
				return [ 'error' => 'Ya existe un servicio activo o inactivo con ese nombre.', 'code' => 409 ];
			}
		}

		if ( isset( $body['description'] ) ) {
			$description = (string) $body['description'];
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $description ) : strlen( $description );
			if ( $len > 1000 ) {
				return [ 'error' => 'La descripción no puede superar 1000 caracteres.', 'code' => 400 ];
			}
		}

		return [ 'valid' => true ];
	}

	private function slugify( string $value ): string {
		if ( function_exists( 'sanitize_title' ) ) {
			return sanitize_title( $value );
		}

		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value ) ?: '';

		return trim( $value, '-' );
	}
}
