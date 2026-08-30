<?php


declare( strict_types=1 );
namespace OpenBooking\Domain\Catalog\Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Representa una entidad del dominio de catalogo.
 */

class Resource_Entity {

    public ?int $id = null;
    public string $name = '';
    public string $type = 'person';
    public string $status = 'active';
    public int $capacity = 1;
    public ?array $meta = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function to_array(): array {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'type'       => $this->type,
            'status'     => $this->status,
            'capacity'   => $this->capacity,
            'meta'       => $this->meta,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public static function from_array( array $data ): self {
        $entity = new self();
        $entity->id         = isset( $data['id'] ) ? (int) $data['id'] : null;
        $entity->name       = $data['name'] ?? '';
        $entity->type       = $data['type'] ?? 'person';
        $entity->status     = $data['status'] ?? 'active';
        $entity->capacity   = (int) ( $data['capacity'] ?? 1 );
        $entity->meta       = isset( $data['meta'] ) ? ( is_string( $data['meta'] ) ? json_decode( $data['meta'], true ) : $data['meta'] ) : null;
        $entity->created_at = $data['created_at'] ?? null;
        $entity->updated_at = $data['updated_at'] ?? null;
        return $entity;
    }
}
