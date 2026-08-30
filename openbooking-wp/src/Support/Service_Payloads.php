<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Convierte entidades de servicio a payloads de salida.
 */
final class Service_Payloads {

    public static function public_from_entity( \OpenBooking\Domain\Catalog\Entity\Service_Entity $service ): array {
        return [
            'id'                    => $service->id,
            'name'                  => $service->name,
            'slug'                  => $service->slug,
            'description'           => $service->description,
            'duration_minutes'      => $service->duration_minutes,
            'buffer_before_minutes' => $service->buffer_before_minutes,
            'buffer_after_minutes'   => $service->buffer_after_minutes,
            'price_minor'           => $service->price_minor,
            'currency'              => $service->currency,
            'capacity'              => $service->capacity,
            'mode'                  => $service->mode,
            'status'                => $service->status,
            'color'                 => $service->color,
            'visibility'            => $service->visibility,
            'created_at'            => $service->created_at,
            'updated_at'            => $service->updated_at,
        ];
    }
}
