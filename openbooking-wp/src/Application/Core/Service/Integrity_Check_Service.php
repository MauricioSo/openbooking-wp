<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Core\Service;

use OpenBooking\Application\Availability\Service\Slot_Lock_Service;
use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface;
use OpenBooking\Domain\Availability\Repository\SlotLockRepositoryInterface;
use OpenBooking\Domain\Availability\Repository\AvailabilityConfigRepositoryInterface;
use OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ejecuta verificaciones operativas para detectar inconsistencias internas.
 */
class Integrity_Check_Service {


    public function __construct(
        private Slot_Lock_Service $slot_lock_service,
        private BookingRepositoryInterface $booking_repo,
        private PaymentRepositoryInterface $payment_repo,
        private SlotLockRepositoryInterface $slot_lock_repo,
        private AvailabilityConfigRepositoryInterface $availability_config_repo,
        private NotificationQueueRepositoryInterface $notification_queue_repo,
    ) {}

    public function run_all_checks(): array {
        $checks = [
            'check_booking_payment_consistency',
            'check_orphan_bookings',
            'check_orphan_payments',
            'check_orphan_customers',
            'check_expired_pending_bookings',
            'check_availability_rules',
            'check_schema_tables',
            'check_booking_state_valid',
            'check_inverted_dates',
            'check_notification_queue_stale',
            'check_slot_lock_orphans',
            'check_slot_lock_missing_for_active_bookings',
            'check_slot_lock_stale_holds',
            'check_confirmed_lock_with_terminal_booking',
        ];

        return array_values(
            array_filter(
                array_map(
                    fn( string $method ) => $this->{$method}(),
                    $checks
                )
            )
        );
    }

    private function result( string $check, string $label, string $status, int $count, string $message ): array {
        return [
            'check'   => $check,
            'label'   => $label,
            'status'  => $status,
            'count'   => $count,
            'message' => $message,
        ];
    }

    private function check_booking_payment_consistency(): ?array {
        $count = $this->booking_repo->count_booking_payment_inconsistencies();
        if ( 0 === $count ) {
            return null;
        }

        return $this->result(
            'booking_payment_consistency',
            'Inconsistencias booking/pago',
            'warning',
            $count,
            sprintf( '%d reservas con inconsistencia entre estado de reserva y estado de pago.', $count )
        );
    }

    private function check_orphan_bookings(): ?array {
        $count = $this->booking_repo->count_orphan_bookings();
        if ( 0 === $count ) {
            return null;
        }

        return $this->result(
            'orphan_bookings',
            'Reservas sin servicio',
            'warning',
            $count,
            sprintf( '%d reservas referencian un servicio que ya no existe.', $count )
        );
    }

    private function check_orphan_payments(): ?array {
        $count = $this->payment_repo->count_orphan_payments();
        if ( 0 === $count ) {
            return null;
        }

        return $this->result(
            'orphan_payments',
            'Pagos sin reserva',
            'warning',
            $count,
            sprintf( '%d pagos referencian una reserva que ya no existe.', $count )
        );
    }

    private function check_expired_pending_bookings(): ?array {
        $count = $this->booking_repo->count_expired_pending_bookings();
        if ( 0 === $count ) {
            return null;
        }

        return $this->result(
            'expired_pending',
            'Reservas pendientes expiradas',
            'warning',
            $count,
            sprintf( '%d reservas pendientes con expiracion vencida que no fueron procesadas.', $count )
        );
    }

    private function check_availability_rules(): ?array {
        if ( ! $this->availability_config_repo->rules_table_exists() ) {
            return null;
        }

        $count = $this->availability_config_repo->count_invalid_time_range_rules();
        if ( 0 === $count ) {
            return null;
        }

        return $this->result(
            'invalid_availability_rules',
            'Reglas de disponibilidad invalidas',
            'warning',
            $count,
            sprintf( '%d reglas con time_from >= time_to (rango invalido).', $count )
        );
    }

    private function check_schema_tables(): ?array {
        $required = [
            'ob_bookings', 'ob_payments', 'ob_services', 'ob_resources',
            'ob_customers', 'ob_availability_rules', 'ob_availability_blocks',
            'ob_audit_logs', 'ob_notification_logs', 'ob_notification_queue',
            'ob_form_fields', 'ob_booking_state_log', 'ob_payment_attempts',
            'ob_rate_limits', 'ob_availability_snapshots', 'ob_notification_log',
            'ob_feature_flags',
        ];
        $missing = $this->booking_repo->find_missing_tables( $required );
        if ( empty( $missing ) ) {
            return null;
        }

        return $this->result(
            'missing_tables',
            'Tablas faltantes',
            'error',
            count( $missing ),
            'Tablas faltantes: ' . implode( ', ', $missing )
        );
    }

    private function check_booking_state_valid(): ?array {
        $count = $this->booking_repo->count_invalid_status_bookings();
        if ( 0 === $count ) {
            return null;
        }

        return $this->result(
            'invalid_booking_states',
            'Estados de reserva invalidos',
            'warning',
            $count,
            sprintf( '%d reservas con estado no reconocido.', $count )
        );
    }

    private function check_notification_queue_stale(): ?array {
        $count = $this->notification_queue_repo->count_stale_pending( 24 );
        if ( 0 === $count ) {
            return null;
        }

        return $this->result(
            'stale_notification_queue',
            'Notificaciones estancadas (>24h)',
            'warning',
            $count,
            sprintf( '%d notificaciones en cola hace mas de 24 horas.', $count )
        );
    }

    private function check_slot_lock_orphans(): ?array {
        $orphans = $this->slot_lock_service->detect_orphans();
        if ( empty( $orphans ) ) {
            return null;
        }

        return $this->result(
            'slot_lock_orphans',
            'Locks sin booking activa',
            'warning',
            count( $orphans ),
            sprintf( '%d slot locks activos sin booking valida.', count( $orphans ) )
        );
    }

    private function check_slot_lock_missing_for_active_bookings(): ?array {
        $count = $this->slot_lock_repo->count_missing_locks_for_active_bookings();
        if ( 0 === $count ) {
            return null;
        }

        return $this->result(
            'slot_lock_missing_for_active_bookings',
            'Bookings activas sin lock',
            'warning',
            $count,
            sprintf( '%d bookings futuras activas sin slot lock.', $count )
        );
    }

    private function check_slot_lock_stale_holds(): ?array {
        $count = $this->slot_lock_repo->count_stale_held_locks();
        if ( 0 === $count ) {
            return null;
        }

        return $this->result(
            'slot_lock_stale_holds',
            'Locks held expirados',
            'warning',
            $count,
            sprintf( '%d slot locks en estado held con expiracion vencida.', $count )
        );
    }

    private function check_orphan_customers(): ?array {
        $count = $this->booking_repo->count_orphan_customers();
        if ( 0 === $count ) {
            return null;
        }

        return $this->result(
            'orphan_customers',
            'Reservas sin cliente',
            'warning',
            $count,
            sprintf( '%d reservas referencian un cliente que ya no existe.', $count )
        );
    }

    private function check_inverted_dates(): ?array {
        $count = $this->booking_repo->count_inverted_date_bookings();
        if ( 0 === $count ) {
            return null;
        }

        return $this->result(
            'inverted_dates',
            'Fechas invertidas (end <= start)',
            'warning',
            $count,
            sprintf( '%d reservas con end_at <= start_at.', $count )
        );
    }

    private function check_confirmed_lock_with_terminal_booking(): ?array {
        $count = $this->slot_lock_repo->count_confirmed_locks_with_terminal_bookings();
        if ( 0 === $count ) {
            return null;
        }

        return $this->result(
            'confirmed_lock_with_terminal_booking',
            'Lock confirmed con booking terminal',
            'warning',
            $count,
            sprintf( '%d locks en estado confirmed con booking en estado terminal.', $count )
        );
    }
}
