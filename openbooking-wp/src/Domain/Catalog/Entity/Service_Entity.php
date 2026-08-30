<?php


declare( strict_types=1 );
namespace OpenBooking\Domain\Catalog\Entity;

use OpenBooking\Support\Currency_Helper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Representa una entidad del dominio de catalogo.
 */

class Service_Entity {

    public ?int $id = null;
    public string $name = '';
    public string $slug = '';
    public ?string $description = null;
    public int $duration_minutes = 60;
    public int $buffer_before_minutes = 0;
    public int $buffer_after_minutes = 0;
    public int $price_minor = 0;
    public string $currency = 'USD';
    public int $capacity = 1;
    public string $mode = 'presencial';
    public string $status = 'active';
    public ?string $color = null;
    public string $visibility = 'public';
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function to_array(): array {
        return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            'slug'                  => $this->slug,
            'description'           => $this->description,
            'duration_minutes'      => $this->duration_minutes,
            'buffer_before_minutes' => $this->buffer_before_minutes,
            'buffer_after_minutes'  => $this->buffer_after_minutes,
            'price_minor'           => $this->price_minor,
            'currency'              => $this->currency,
            'capacity'              => $this->capacity,
            'mode'                  => $this->mode,
            'status'                => $this->status,
            'color'                 => $this->color,
            'visibility'            => $this->visibility,
            'created_at'            => $this->created_at,
            'updated_at'            => $this->updated_at,
        ];
    }

    public static function from_array( array $data ): self {
        $entity = new self();
        $entity->id                    = isset( $data['id'] ) ? (int) $data['id'] : null;
        $entity->name                  = $data['name'] ?? '';
        $entity->slug                  = $data['slug'] ?? '';
        $entity->description           = $data['description'] ?? null;
        $entity->duration_minutes      = (int) ( $data['duration_minutes'] ?? 60 );
        $entity->buffer_before_minutes = (int) ( $data['buffer_before_minutes'] ?? 0 );
        $entity->buffer_after_minutes  = (int) ( $data['buffer_after_minutes'] ?? 0 );
        $entity->price_minor           = (int) ( $data['price_minor'] ?? 0 );
        $entity->currency              = $data['currency'] ?? 'USD';
        $entity->capacity              = (int) ( $data['capacity'] ?? 1 );
        $entity->mode                  = $data['mode'] ?? 'presencial';
        $entity->status                = $data['status'] ?? 'active';
        $entity->color                 = $data['color'] ?? null;
        $entity->visibility            = $data['visibility'] ?? 'public';
        $entity->created_at            = $data['created_at'] ?? null;
        $entity->updated_at            = $data['updated_at'] ?? null;
        return $entity;
    }

    public function get_formatted_price(): string {
        return Currency_Helper::format_minor( $this->price_minor, $this->currency );
    }
}
