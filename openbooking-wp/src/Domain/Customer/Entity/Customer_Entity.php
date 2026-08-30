<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Customer\Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Representa un cliente del dominio.
 */
class Customer_Entity {

    public ?int $id = null;
    public string $first_name = '';
    public ?string $last_name = null;
    public string $email = '';
    public ?string $phone = null;
    public ?string $notes = null;
    /** 1 = opted in to WhatsApp notifications, 0 = opted out. */
    public int $whatsapp_opt_in = 1;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function to_array(): array {
        return [
            'id'               => $this->id,
            'first_name'       => $this->first_name,
            'last_name'        => $this->last_name,
            'email'            => $this->email,
            'phone'            => $this->phone,
            'notes'            => $this->notes,
            'whatsapp_opt_in'  => $this->whatsapp_opt_in,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }

    public static function from_array( array $data ): self {
        $entity = new self();
        $entity->id              = isset( $data['id'] ) ? (int) $data['id'] : null;
        $entity->first_name      = $data['first_name'] ?? '';
        $entity->last_name       = $data['last_name'] ?? null;
        $entity->email           = $data['email'] ?? '';
        $entity->phone           = $data['phone'] ?? null;
        $entity->notes           = $data['notes'] ?? null;
        $entity->whatsapp_opt_in = isset( $data['whatsapp_opt_in'] ) ? (int) $data['whatsapp_opt_in'] : 1;
        $entity->created_at      = $data['created_at'] ?? null;
        $entity->updated_at      = $data['updated_at'] ?? null;
        return $entity;
    }

    public function get_full_name(): string {
        return trim( $this->first_name . ' ' . ( $this->last_name ?? '' ) );
    }
}
