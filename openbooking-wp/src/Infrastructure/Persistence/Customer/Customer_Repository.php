<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Persistence\Customer;

use OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y consulta clientes.
 */
class Customer_Repository implements CustomerRepositoryInterface {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_customers';
    }

    public function find( int $id ): ?\OpenBooking\Domain\Customer\Entity\Customer_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Customer\Entity\Customer_Entity::from_array( $row ) : null;
    }

    public function find_by_email( string $email ): ?\OpenBooking\Domain\Customer\Entity\Customer_Entity {
        $email = strtolower( trim( $email ) );
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE email = %s", $email ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Customer\Entity\Customer_Entity::from_array( $row ) : null;
    }

    public function find_all( array $args = [] ): array {
        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['search'] ) ) {
            $where[]  = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)';
            $like     = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_clause = implode( ' AND ', $where );
        $limit  = ! empty( $args['limit'] ) ? absint( $args['limit'] ) : 50;
        $offset = ! empty( $args['offset'] ) ? absint( $args['offset'] ) : 0;

        $sql = "SELECT * FROM {$this->table} WHERE {$where_clause} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";

        if ( ! empty( $params ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$params );
        }

        $rows = $this->wpdb->get_results( $sql, ARRAY_A );
        return array_map( function ( $row ) {
            return \OpenBooking\Domain\Customer\Entity\Customer_Entity::from_array( $row );
        }, $rows ?: [] );
    }

    /**
     * Fetches multiple customers by ID in a single query.
     * Returns a map of id => Customer_Entity for fast lookup.
     *
     * @param int[] $ids
     * @return array<int, \OpenBooking\Domain\Customer\Entity\Customer_Entity>
     */
    public function find_by_ids( array $ids ): array {
        $ids = array_filter( array_map( 'absint', $ids ) );
        if ( empty( $ids ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated, not user input.
        $sql  = $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id IN ({$placeholders})", $ids );
        $rows = $this->wpdb->get_results( $sql, ARRAY_A );

        $map = [];
        foreach ( $rows ?: [] as $row ) {
            $entity       = \OpenBooking\Domain\Customer\Entity\Customer_Entity::from_array( $row );
            $map[ $entity->id ] = $entity;
        }

        return $map;
    }

    public function insert( \OpenBooking\Domain\Customer\Entity\Customer_Entity $entity ): int {
        $entity->email = strtolower( trim( $entity->email ) );
        $this->wpdb->insert( $this->table, [
            'first_name'      => sanitize_text_field( $entity->first_name ),
            'last_name'       => sanitize_text_field( $entity->last_name ?? '' ),
            'email'           => sanitize_email( $entity->email ),
            'phone'           => sanitize_text_field( $entity->phone ?? '' ),
            'notes'           => sanitize_textarea_field( $entity->notes ?? '' ),
            'whatsapp_opt_in' => $entity->whatsapp_opt_in ? 1 : 0,
        ] );

        return (int) $this->wpdb->insert_id;
    }

    public function update( \OpenBooking\Domain\Customer\Entity\Customer_Entity $entity ): bool {
        if ( ! $entity->id ) {
            return false;
        }
        $result = $this->wpdb->update( $this->table, [
            'first_name'      => sanitize_text_field( $entity->first_name ),
            'last_name'       => sanitize_text_field( $entity->last_name ?? '' ),
            'email'           => sanitize_email( $entity->email ),
            'phone'           => sanitize_text_field( $entity->phone ?? '' ),
            'notes'           => sanitize_textarea_field( $entity->notes ?? '' ),
            'whatsapp_opt_in' => $entity->whatsapp_opt_in ? 1 : 0,
        ], [ 'id' => $entity->id ] );

        return false !== $result;
    }

    /**
     * @param ?bool $whatsapp_opt_in  null = don't change existing preference; true/false = explicit choice.
     */
    public function find_or_create_by_email( string $email, string $first_name = '', string $last_name = '', ?string $phone = null, ?bool $whatsapp_opt_in = null ): \OpenBooking\Domain\Customer\Entity\Customer_Entity {
        $email = strtolower( trim( $email ) );
        $this->wpdb->query( $this->wpdb->prepare(
            "INSERT IGNORE INTO {$this->table} (first_name, last_name, email, phone, whatsapp_opt_in) VALUES (%s, %s, %s, %s, %d)",
            sanitize_text_field( $first_name ),
            sanitize_text_field( $last_name ?? '' ),
            sanitize_email( $email ),
            sanitize_text_field( $phone ?? '' ),
            $whatsapp_opt_in !== null ? (int) $whatsapp_opt_in : 1
        ) );

        $customer = $this->find_by_email( $email );

        $updated = false;
        if ( $first_name && $first_name !== $customer->first_name ) {
            $customer->first_name = $first_name;
            $updated = true;
        }
        if ( $last_name && $last_name !== $customer->last_name ) {
            $customer->last_name = $last_name;
            $updated = true;
        }
        if ( $phone && $phone !== $customer->phone ) {
            $customer->phone = $phone;
            $updated = true;
        }
        // Update opt-in only when the caller made an explicit choice (not null).
        if ( $whatsapp_opt_in !== null && (int) $whatsapp_opt_in !== $customer->whatsapp_opt_in ) {
            $customer->whatsapp_opt_in = (int) $whatsapp_opt_in;
            $updated = true;
        }
        if ( $updated ) {
            $this->update( $customer );
        }

        return $customer;
    }
}
