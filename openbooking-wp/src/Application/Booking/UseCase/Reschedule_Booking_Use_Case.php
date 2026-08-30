<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\UseCase;

use OpenBooking\Application\Booking\Service\Booking_Persistence_Service;
use OpenBooking\Application\Booking\Service\Booking_State_Guard;
use OpenBooking\Application\Booking\Service\Booking_Token_Guard;
use OpenBooking\Application\Booking\Service\Reschedule_Availability_Guard;
use OpenBooking\Application\Booking\Service\Booking_Request_Context;
use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Booking\Event\BookingRescheduled;
use OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface;
use OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface;
use OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface;
use OpenBooking\Domain\Shared\Port\TransactionManagerInterface;
use OpenBooking\Domain\Shared\Port\EventBusInterface;
use OpenBooking\Domain\Shared\Port\ActorContextInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Orquesta un caso de uso del bounded context de reservas.
 */

class Reschedule_Booking_Use_Case {


    public function __construct(
        private Booking_Persistence_Service $persistence_service,
        private BookingRepositoryInterface $booking_repo,
        private ServiceRepositoryInterface $service_repo,
        private CustomerRepositoryInterface $customer_repo,
        private Booking_State_Guard $state_guard,
        private Booking_Token_Guard $token_guard,
        private Reschedule_Availability_Guard $availability_guard,
        private AuditRepositoryInterface $audit_log_repo,
        private TransactionManagerInterface $transaction,
        private EventBusInterface $event_bus,
        private \OpenBooking\Domain\Booking\Service\Booking_Token_Generator $token_generator,
        private \OpenBooking\Application\Availability\Service\Availability_Service $availability_service,
        private \OpenBooking\Domain\Shared\Port\SettingsInterface $settings,
        private ActorContextInterface $actor_context,
    ) {
$this->actor_context      = $actor_context ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_ActorContext();
    }

    public function execute( int $booking_id, string $new_start_at, ?int $new_resource_id = null, ?string $reschedule_token = null, ?Booking_Request_Context $context = null ): array {
        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking ) {
            return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
        }

        $guard = $this->state_guard->assert_can_reschedule( $booking );
        if ( ! $guard['allowed'] ) {
            return [ 'error' => $guard['error'], 'code' => $guard['code'] ];
        }

        $service = $this->service_repo->find( $booking->service_id );
        if ( ! $service ) {
            return [ 'error' => __( 'Servicio no encontrado.', 'openbooking-wp' ), 'code' => 404 ];
        }

        $new_start_dt = $this->parse_business_datetime( $new_start_at );
        if ( ! $new_start_dt ) {
            return [ 'error' => __( 'Fecha/hora inválida para la zona horaria configurada.', 'openbooking-wp' ), 'code' => 400 ];
        }

        $not_past = $this->state_guard->assert_not_past( $new_start_dt );
        if ( ! $not_past['allowed'] ) {
            return [ 'error' => $not_past['error'], 'code' => $not_past['code'] ];
        }

        $new_end_at = $new_start_dt->modify( '+' . $service->duration_minutes . ' minutes' )->format( 'Y-m-d H:i:s' );
        $is_public_token = $reschedule_token !== null;

        // Guard de jornada: el nuevo horario debe caer dentro de la disponibilidad publicada
        // (reglas semanales vigentes, sin bloques), no solo estar libre de conflictos de locks.
        $within_schedule = $this->availability_service->is_slot_available(
            $booking->service_id,
            $new_start_at,
            $new_end_at,
            $new_resource_id ?: $booking->resource_id,
            $booking->id
        );
        if ( ! $within_schedule ) {
            return [ 'error' => __( 'El nuevo horario está fuera del horario de atención.', 'openbooking-wp' ), 'code' => 409 ];
        }

        $max_attempts = 3;
        $old_start = null;
        $old_resource_id = null;
        $booking_locked = null;

        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $this->transaction->begin();

            try {
                $booking_locked = $this->booking_repo->find_locked( $booking_id );
                if ( ! $booking_locked ) {
                    $this->transaction->rollback();
                    return [ 'error' => __( 'La reserva ya no puede ser reprogramada.', 'openbooking-wp' ), 'code' => 409 ];
                }

                $can_reschedule_locked = $this->state_guard->assert_can_reschedule( $booking_locked );
                if ( ! $can_reschedule_locked['allowed'] ) {
                    $this->transaction->rollback();
                    return [ 'error' => $can_reschedule_locked['error'], 'code' => $can_reschedule_locked['code'] ];
                }

                if ( $reschedule_token !== null ) {
                    $token_check = $this->token_guard->verify_reschedule_token( $booking_locked, $reschedule_token );
                    if ( ! $token_check['valid'] ) {
                        $this->transaction->rollback();
                        return [ 'error' => $token_check['error'], 'code' => $token_check['code'] ];
                    }
                }

                $old_start       = $booking_locked->start_at;
                $old_resource_id = $booking_locked->resource_id;
                $locked_resource_id = $new_resource_id ?: $booking_locked->resource_id;

                $slot_result = $this->availability_guard->check_and_move(
                    $service,
                    $booking_id,
                    $booking_locked->service_id,
                    $new_start_at,
                    $new_end_at,
                    $locked_resource_id,
                    $booking_id,
                    $booking_locked->expires_at
                );

                if ( ! empty( $slot_result['error'] ) ) {
                    $this->transaction->rollback();
                    if ( $slot_result['code'] === 409 ) {
                        return [ 'error' => __( 'El nuevo horario no está disponible.', 'openbooking-wp' ), 'code' => 409 ];
                    }
                    return [ 'error' => __( 'Servicio temporalmente ocupado. Intenta de nuevo.', 'openbooking-wp' ), 'code' => 503 ];
                }

                $resolved_resource_id = $slot_result['resolved_resource_id'];

                $booking_locked->start_at    = $new_start_at;
                $booking_locked->end_at      = $new_end_at;
                $booking_locked->resource_id = $resolved_resource_id;
                $this->token_generator->generate_reschedule_token( $booking_locked );
                if ( ! $this->booking_repo->update( $booking_locked ) ) {
                    $this->transaction->rollback();
                    return [ 'error' => __( 'No se pudo reprogramar la reserva.', 'openbooking-wp' ), 'code' => 500 ];
                }

                $last_error = $this->transaction->last_error();
                if ( $last_error && strpos( $last_error, '1213' ) !== false ) {
                    $this->transaction->rollback();
                    if ( $attempt < $max_attempts ) {
                        usleep( 50000 * $attempt );
                        continue;
                    }
                    return [ 'error' => __( 'Servicio temporalmente ocupado. Intenta de nuevo.', 'openbooking-wp' ), 'code' => 503 ];
                }

                $this->transaction->commit();
            } catch ( \Throwable $e ) {
                $this->transaction->rollback();
                throw $e;
            }
            break;
        }

        if ( $is_public_token ) {
            $this->audit_log_repo->insert( [
                'entity_type' => 'booking',
                'entity_id'   => $booking_locked->id,
                'action'      => 'public_reschedule',
                'actor_type'  => 'public_token',
                'actor_id'    => null,
                'message'     => 'Booking rescheduled via public token.',
                'context'     => [
                    'old_start_at'    => $old_start,
                    'new_start_at'    => $new_start_at,
                    'old_resource_id' => $old_resource_id,
                    'new_resource_id' => $booking_locked->resource_id,
                    'token_prefix'    => substr( $reschedule_token, 0, 8 ),
                ],
            ] );
        } else {
            $this->audit_log_repo->insert( [
                'entity_type' => 'booking',
                'entity_id'   => $booking_locked->id,
                'action'      => 'admin_update_booking',
                'actor_type'  => 'admin',
                'actor_id'    => $this->actor_context->get_current_user_id(),
                'message'     => 'Booking updated by admin.',
                'context'     => [
                    'old_start_at'    => $old_start,
                    'new_start_at'    => $new_start_at,
                    'old_resource_id' => $old_resource_id,
                    'new_resource_id' => $booking_locked->resource_id,
                ],
            ] );
        }

        $this->availability_service->invalidate_cache( $booking_locked->service_id );

        $this->event_bus->dispatch( new BookingRescheduled(
            $booking_locked->id,
            $old_start,
            $new_start_at,
            $booking_locked->to_array()
        ) );

        if ( $is_public_token ) {
            $service_after  = $this->service_repo->find( $booking_locked->service_id );
            $customer_after = $this->customer_repo->find( $booking_locked->customer_id );
            return [
                'success'    => true,
                'booking_id' => $booking_id,
                'booking'    => $this->persistence_service->build_public_booking_payload( $booking_locked, $service_after, $customer_after ),
            ];
        }

        return [ 'success' => true, 'booking_id' => $booking_id, 'booking' => $booking_locked->to_array() ];
    }

    private function parse_business_datetime( string $datetime ): ?\DateTimeImmutable {
        $timezone_name = $this->settings->get( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' );
        try {
            $timezone = new \DateTimeZone( $timezone_name );
        } catch ( \Exception $e ) {
            $timezone = new \DateTimeZone( 'UTC' );
        }

        $parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $datetime, $timezone );
        if ( ! $parsed ) {
            return null;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) {
            return null;
        }

        return $parsed;
    }
}
