<?php


declare( strict_types=1 );
namespace OpenBooking\Domain\Availability\Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Representa una entidad del dominio de disponibilidad.
 */

class AvailabilityRule_Entity {

    public ?int $id = null;
    public string $scope_type = 'global';
    public ?int $scope_id = null;
    public string $rule_type = 'weekly';
    public ?int $weekday = null;
    public ?string $date_from = null;
    public ?string $date_to = null;
    public ?string $time_from = null;
    public ?string $time_to = null;
    public ?int $capacity = null;
    public ?array $meta = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function to_array(): array {
        return [
            'id'         => $this->id,
            'scope_type' => $this->scope_type,
            'scope_id'   => $this->scope_id,
            'rule_type'  => $this->rule_type,
            'weekday'    => $this->weekday,
            'date_from'  => $this->date_from,
            'date_to'    => $this->date_to,
            'time_from'  => $this->time_from,
            'time_to'    => $this->time_to,
            'capacity'   => $this->capacity,
            'meta'       => $this->meta,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public static function from_array( array $data ): self {
        $entity = new self();
        $entity->id         = isset( $data['id'] ) ? (int) $data['id'] : null;
        $entity->scope_type = $data['scope_type'] ?? 'global';
        $entity->scope_id   = isset( $data['scope_id'] ) ? (int) $data['scope_id'] : null;
        $entity->rule_type  = $data['rule_type'] ?? 'weekly';
        $entity->weekday    = isset( $data['weekday'] ) ? (int) $data['weekday'] : null;
        $entity->date_from  = $data['date_from'] ?? null;
        $entity->date_to    = $data['date_to'] ?? null;
        $entity->time_from  = $data['time_from'] ?? null;
        $entity->time_to    = $data['time_to'] ?? null;
        $entity->capacity   = isset( $data['capacity'] ) ? (int) $data['capacity'] : null;
        $entity->meta       = isset( $data['meta'] ) ? ( is_string( $data['meta'] ) ? json_decode( $data['meta'], true ) : $data['meta'] ) : null;
        $entity->created_at = $data['created_at'] ?? null;
        $entity->updated_at = $data['updated_at'] ?? null;
        return $entity;
    }
}
