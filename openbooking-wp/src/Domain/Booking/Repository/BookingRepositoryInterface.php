<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Booking\Repository;

use OpenBooking\Domain\Booking\Entity\Booking_Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato del repositorio de reservas.
 */
interface BookingRepositoryInterface {

    public function find( int $id ): ?Booking_Entity;

    public function find_locked( int $id ): ?Booking_Entity;

    public function find_by_cancel_token( string $token ): ?Booking_Entity;

    public function find_by_reschedule_token( string $token ): ?Booking_Entity;

    public function find_by_view_token( string $token ): ?Booking_Entity;

    public function find_by_booking_token( string $token ): ?Booking_Entity;

    public function find_by_confirm_token( string $token ): ?Booking_Entity;

    /** @return Booking_Entity[] */
    public function find_all( array $args = [] ): array;

    public function find_by_client_ref( string $client_ref ): ?Booking_Entity;

 /**
  * Busca una reserva activa del mismo cliente (email) en el mismo slot.
  * Usado para deduplicar dobles envíos del mismo cliente con client_ref distinto.
  *
  * @param int    $customer_id ID del cliente.
  * @param int    $service_id  ID del servicio.
  * @param string $start_at    Inicio del slot (Y-m-d H:i:s).
  * @return ?Booking_Entity Reserva activa existente o null.
  */
 public function find_active_duplicate_for_customer( int $customer_id, int $service_id, string $start_at ): ?Booking_Entity;

    public function has_conflict( int $service_id, string $start_at, string $end_at, ?int $resource_id = null, ?int $exclude_id = null ): bool;

    public function has_conflict_locked( int $service_id, string $start_at, string $end_at, ?int $resource_id = null, ?int $exclude_id = null ): bool;

    public function count_conflicts_locked( int $service_id, string $start_at, string $end_at, ?int $resource_id = null, ?int $exclude_id = null ): int;

    public function get_booked_slots( int $service_id, string $date_from, string $date_to, ?int $resource_id = null ): array;

    public function get_booked_slots_grouped_by_date( int $service_id, string $date_from, string $date_to, ?int $resource_id = null ): array;

    public function find_pending_expired(): array;

    public function insert( Booking_Entity $entity ): int;

    public function update( Booking_Entity $entity ): bool;

    public function update_status( int $id, string $status ): bool;

    public function update_payment_status( int $id, string $payment_status ): bool;

    public function count_booking_payment_inconsistencies(): int;

    public function count_orphan_bookings(): int;

    public function count_expired_pending_bookings(): int;

    public function count_invalid_status_bookings(): int;

    public function count_orphan_customers(): int;

    public function count_inverted_date_bookings(): int;

    public function find_missing_tables( array $suffixes ): array;

    public function count_active_for_service( int $service_id ): int;

    public function count_pending(): int;

    public function count_unpaid(): int;

    public function find_today_dashboard_rows( string $date, int $limit = 20 ): array;

    public function find_attention_required_rows( int $limit = 20 ): array;

    public function find_suspicious_bookings( int $limit = 200 ): array;

    public function find_bookings_without_request_log( int $limit = 200 ): array;

    public function find_duplicate_external_ids( int $limit = 200 ): array;
}
