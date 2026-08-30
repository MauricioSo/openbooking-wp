<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Booking\Event\BookingCancelled;
use OpenBooking\Domain\Booking\Event\BookingConfirmed;
use OpenBooking\Domain\Booking\Event\BookingCreated;
use OpenBooking\Domain\Booking\Event\BookingExpired;
use OpenBooking\Domain\Booking\Event\BookingNoShow;
use OpenBooking\Domain\Booking\Event\BookingRescheduled;
use OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface;
use OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface;
use OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface;
use OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface;
use OpenBooking\Domain\Shared\Port\EventBusInterface;
use OpenBooking\Domain\Shared\Port\ActorContextInterface;
use OpenBooking\Domain\Shared\Port\SettingsInterface;
use OpenBooking\Domain\Shared\Port\ClockInterface;
use OpenBooking\Domain\Shared\Port\TransactionManagerInterface;
use OpenBooking\Domain\Booking\Service\Booking_Token_Generator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Coordina la logica de negocio del bounded context de reservas.
 */

class Booking_Admin_Service {

    private $availability;

    public function __construct(
        private BookingRepositoryInterface $booking_repo,
        private ServiceRepositoryInterface $service_repo,
        private CustomerRepositoryInterface $customer_repo,
        private ResourceRepositoryInterface $resource_repo,
        private PaymentRepositoryInterface $payment_repo,
        private \OpenBooking\Application\Availability\Service\Slot_Lock_Service $slot_lock_service,
        private \OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface $audit_log_repo,
        private \OpenBooking\Application\Audit\Service\Audit_Logger $audit_logger,
        private TransactionManagerInterface $transaction,
        private Booking_Persistence_Service $persistence,
        private \OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface $notification_queue_repo,
        private \OpenBooking\Domain\Booking\Repository\BookingStateLogRepositoryInterface $state_log_repo,
        private EventBusInterface $event_bus,
        private SettingsInterface $settings,
        private ClockInterface $clock,
        private Booking_State_Guard $state_guard,
        private Booking_Token_Guard $token_guard,
        private Booking_Token_Generator $token_generator,
        private ActorContextInterface $actor_context,
        private ?\OpenBooking\Application\Availability\Service\Availability_Service $availability_service = null,
    ) {
        $this->actor_context = $actor_context ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_ActorContext();
        $this->availability  = $availability_service ?? \OpenBooking\Support\Container::get( \OpenBooking\Application\Availability\Service\Availability_Service::class );
    }

    public function confirm_booking( int $booking_id ): array {
        $this->transaction->begin();

        try {
            $booking = $this->booking_repo->find_locked( $booking_id );
            if ( ! $booking ) {
                $this->transaction->rollback();
                return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
            }

            if ( ! \OpenBooking\Domain\Booking\Service\Booking_State_Machine::can_transition_status( $booking->status, \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED ) ) {
                $this->transaction->rollback();
                return [ 'error' => __( 'La reserva no puede ser confirmada desde su estado actual.', 'openbooking-wp' ), 'code' => 400 ];
            }

            $this->write_state_log( $booking, \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED );
            $booking->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED;
            $this->booking_repo->update( $booking );

            $this->transaction->commit();
        } catch ( \Throwable $e ) {
            $this->transaction->rollback();
            throw $e;
        }

        $this->audit_log_repo->insert( [
            'entity_type' => 'booking',
            'entity_id'   => $booking->id,
            'action'      => 'admin_confirm',
            'actor_type'  => 'admin',
            'actor_id'    => $this->actor_context->get_current_user_id(),
            'message'     => 'Booking confirmed by admin.',
            'context'     => [
                'status' => $booking->status,
            ],
        ] );

        $this->event_bus->dispatch( new BookingConfirmed( $booking->id, $booking->to_array() ) );

        return [ 'success' => true, 'booking_id' => $booking_id ];
    }

    public function cancel_booking( int $booking_id, string $cancelled_by = 'customer', ?string $reason = null, ?string $cancel_token = null ): array {
        $this->transaction->begin();

        try {
            $booking = $this->booking_repo->find_locked( $booking_id );
            if ( ! $booking ) {
                $this->transaction->rollback();
                return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
            }

            if ( $cancel_token !== null ) {
                $token_check = $this->token_guard->verify_cancel_token( $booking, $cancel_token );
                if ( ! $token_check['valid'] ) {
                    $this->transaction->rollback();
                    return [ 'error' => $token_check['error'], 'code' => $token_check['code'] ];
                }
            }

            $can_cancel = $this->state_guard->assert_can_cancel( $booking, $cancelled_by );
            if ( ! $can_cancel['allowed'] ) {
                $this->transaction->rollback();
                return [ 'error' => $can_cancel['error'], 'code' => $can_cancel['code'] ];
            }

            $status = $cancelled_by === 'admin'
                ? \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CANCELLED_BY_ADMIN
                : \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CANCELLED_BY_CUSTOMER;

            if ( ! \OpenBooking\Domain\Booking\Service\Booking_State_Machine::can_transition_status( $booking->status, $status ) ) {
                $this->transaction->rollback();
                return [ 'error' => __( 'La reserva no puede ser cancelada desde su estado actual.', 'openbooking-wp' ), 'code' => 400 ];
            }

            $this->write_state_log(
                $booking,
                $status,
                $reason,
                $cancel_token !== null ? 'public_token' : null,
                null
            );
            $booking->status = $status;
            if ( $reason ) {
                $booking->notes_internal = trim( $booking->notes_internal . "\nCancelada: " . $reason );
            }
            $this->token_generator->generate_cancel_token( $booking );
            $this->booking_repo->update( $booking );

            $this->slot_lock_service->release_for_booking( $booking->id, 'cancelled' );

            $expired_payments = $this->payment_repo->expire_pending_for_booking( $booking->id );
            if ( $expired_payments > 0 ) {
                $this->audit_log_repo->insert( [
                    'entity_type' => 'booking',
                    'entity_id'   => $booking->id,
                    'action'      => 'pending_payments_expired_on_cancel',
                    'severity'    => 'info',
                    'message'     => "Expired {$expired_payments} pending payment(s) for cancelled booking.",
                    'context'     => [
                        'booking_id'     => $booking->id,
                        'payments_count' => $expired_payments,
                        'cancelled_by'   => $cancelled_by,
                    ],
                ] );
            }

            $this->transaction->commit();
        } catch ( \Throwable $e ) {
            $this->transaction->rollback();
            throw $e;
        }

        $is_public_token = $cancel_token !== null;

        if ( $cancelled_by === 'admin' && ! $is_public_token ) {
            $this->audit_log_repo->insert( [
                'entity_type' => 'booking',
                'entity_id'   => $booking->id,
                'action'      => 'admin_cancel',
                'actor_type'  => 'admin',
                'actor_id'    => $this->actor_context->get_current_user_id(),
                'message'     => 'Booking cancelled by admin.',
                'context'     => [
                    'reason' => $reason,
                    'status' => $status,
                ],
            ] );
        } elseif ( $is_public_token ) {
            $this->audit_log_repo->insert( [
                'entity_type' => 'booking',
                'entity_id'   => $booking->id,
                'action'      => 'public_cancel',
                'actor_type'  => 'public_token',
                'actor_id'    => null,
                'message'     => 'Booking cancelled via public cancel token.',
                'context'     => [
                    'reason' => $reason,
                    'status' => $status,
                    'token_prefix' => substr( $cancel_token, 0, 8 ),
                ],
            ] );
        }

        $this->notification_queue_repo->cancel_for_booking( $booking->id );

        $this->availability->invalidate_cache( $booking->service_id );

        $this->event_bus->dispatch( new BookingCancelled( $booking->id, $booking->to_array() ) );

        return [ 'success' => true, 'booking_id' => $booking_id ];
    }

    public function mark_no_show( int $booking_id ): array {
        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking ) {
            return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
        }

        $old_booking = $booking->to_array();
        $state_log_booking = clone $booking;
        $booking->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_NO_SHOW;
        if ( ! $this->booking_repo->update( $booking ) ) {
            return [ 'error' => __( 'No se pudo actualizar la reserva.', 'openbooking-wp' ), 'code' => 500 ];
        }

        $this->write_state_log( $state_log_booking, \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_NO_SHOW );

        $this->audit_logger->log_entity_change(
            'booking',
            $booking->id,
            'admin_mark_no_show',
            $old_booking,
            $booking->to_array(),
            [],
            [
                'message'        => 'Booking marked as no show by admin.',
                'allowed_fields' => [ 'status' ],
            ]
        );

        $this->event_bus->dispatch( new BookingNoShow( $booking->id, $booking->to_array() ) );

        return [ 'success' => true, 'booking_id' => $booking_id ];
    }

    public function expire_pending(): int {
        $expired = $this->booking_repo->find_pending_expired();
        $count   = 0;
        foreach ( $expired as $booking ) {
            if ( ! \OpenBooking\Domain\Booking\Service\Booking_State_Machine::can_transition_status( $booking->status, \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_EXPIRED ) ) {
                continue;
            }
            $old_booking = $booking->to_array();
            $this->write_state_log( $booking, \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_EXPIRED );
            $booking->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_EXPIRED;
            $booking->payment_status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_EXPIRED;
            if ( ! $this->booking_repo->update( $booking ) ) {
                $this->audit_log_repo->insert( [
                    'entity_type' => 'booking',
                    'entity_id'   => $booking->id,
                    'action'      => 'booking_expiration_failed',
                    'severity'    => 'error',
                    'message'     => 'Pending booking expiration failed while updating booking state.',
                    'context'     => [
                        'booking_id' => $booking->id,
                        'source'     => 'cron',
                    ],
                ] );
                continue;
            }

            $this->slot_lock_service->expire_for_booking( $booking->id );

            $expired_payments = $this->payment_repo->expire_pending_for_booking( $booking->id );
            if ( $expired_payments > 0 ) {
                $this->audit_log_repo->insert( [
                    'entity_type' => 'booking',
                    'entity_id'   => $booking->id,
                    'action'      => 'pending_payments_expired_with_booking',
                    'severity'    => 'info',
                    'message'     => "Expired {$expired_payments} pending payment(s) for expired booking.",
                    'context'     => [
                        'booking_id'      => $booking->id,
                        'payments_count'  => $expired_payments,
                        'source'          => 'cron',
                    ],
                ] );
            }

            $this->audit_logger->log_entity_change(
                'booking',
                $booking->id,
                'booking_expired',
                $old_booking,
                $booking->to_array(),
                [],
                [
                    'message'        => 'Pending booking expired automatically.',
                    'source'         => 'cron',
                    'allowed_fields' => [ 'status', 'payment_status' ],
                ]
            );
            $this->notification_queue_repo->cancel_for_booking( $booking->id );
            $this->event_bus->dispatch( new BookingExpired( $booking->id, $booking->to_array() ) );

            $count++;
        }
        return $count;
    }

    public function reschedule_booking( int $booking_id, string $new_start_at, ?int $new_resource_id = null, ?string $reschedule_token = null ): array {
        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking ) {
            return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
        }

        $can_reschedule = $this->state_guard->assert_can_reschedule( $booking );
        if ( ! $can_reschedule['allowed'] ) {
            return [ 'error' => $can_reschedule['error'], 'code' => $can_reschedule['code'] ];
        }

        $service = $this->service_repo->find( $booking->service_id );
        if ( ! $service ) {
            return [ 'error' => __( 'Servicio no encontrado.', 'openbooking-wp' ), 'code' => 404 ];
        }

        $new_start_dt = $this->parse_business_datetime( $new_start_at );
        if ( ! $new_start_dt ) {
            return [ 'error' => __( 'Fecha/hora inválida para la zona horaria configurada.', 'openbooking-wp' ), 'code' => 400 ];
        }

        if ( $new_start_dt->getTimestamp() <= $this->get_business_now()->getTimestamp() ) {
            return [ 'error' => __( 'No se pueden crear reservas en el pasado.', 'openbooking-wp' ), 'code' => 400 ];
        }

        $new_end_at = $this->calculate_end_at( $new_start_dt, $service->duration_minutes );

        $is_public_token = $reschedule_token !== null;

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

                $available = $this->availability->is_slot_available(
                    $booking_locked->service_id,
                    $new_start_at,
                    $new_end_at,
                    $locked_resource_id,
                    $booking_id
                );
                if ( ! $available ) {
                    $this->transaction->rollback();
                    return [ 'error' => __( 'El nuevo horario no está disponible.', 'openbooking-wp' ), 'code' => 409 ];
                }

                $resolved_resource_id = $this->resolve_resource_for_locked_slot( $service, $new_start_at, $new_end_at, $locked_resource_id, $booking_id );
                if ( $resolved_resource_id === false ) {
                    $this->transaction->rollback();
                    return [ 'error' => __( 'El nuevo horario no está disponible.', 'openbooking-wp' ), 'code' => 409 ];
                }

                $resources = $this->resource_repo->find_by_service( $booking_locked->service_id );
                $capacity  = empty( $resources )
                    ? max( 1, $service->capacity )
                    : max( 1, (int) current( array_filter( $resources, fn( $r ) => $r->id === $resolved_resource_id ) )->capacity ?? $service->capacity );

                $lock_result = $this->slot_lock_service->move_booking_lock(
                    $booking_id,
                    $booking_locked->service_id,
                    $resolved_resource_id,
                    $new_start_at,
                    $new_end_at,
                    $capacity,
                    $booking_locked->expires_at
                );

                if ( ! empty( $lock_result['error'] ) ) {
                    $this->transaction->rollback();
                    if ( ! empty( $lock_result['code'] ) && $lock_result['code'] === 409 ) {
                        return [ 'error' => __( 'El nuevo horario no está disponible.', 'openbooking-wp' ), 'code' => 409 ];
                    }
                    return [ 'error' => __( 'Servicio temporalmente ocupado. Intenta de nuevo.', 'openbooking-wp' ), 'code' => 503 ];
                }

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

        $this->availability->invalidate_cache( $booking_locked->service_id );

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
                'booking'    => $this->persistence->build_public_booking_payload( $booking_locked, $service_after, $customer_after ),
            ];
        }

        return [ 'success' => true, 'booking_id' => $booking_id, 'booking' => $booking_locked->to_array() ];
    }

    private function write_state_log(
        \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking,
        string $new_status,
        ?string $reason = null,
        ?string $actor_type = null,
        ?int $actor_id = null,
        ?string $to_payment_status = null
    ): void {
        if ( $actor_type === null ) {
            $actor_type = $this->actor_context->is_user_logged_in() ? 'admin' : 'system';
            $actor_id   = $actor_type === 'admin' ? $this->actor_context->get_current_user_id() ?: null : null;
        }

        $repo = $this->state_log_repo;
        $repo->insert_state_change(
            $booking,
            $new_status,
            $reason,
            $actor_type,
            $actor_id,
            $to_payment_status ?? $booking->payment_status,
            \OpenBooking\Support\Request_Context::get_request_id() ?: null
        );
    }

    private function resolve_resource_for_locked_slot(
        \OpenBooking\Domain\Catalog\Entity\Service_Entity $service,
        string $start_at,
        string $end_at,
        ?int $requested_resource_id,
        ?int $exclude_booking_id
    ) {
        $resources = $this->resource_repo->find_by_service( $service->id );

        if ( empty( $resources ) ) {
            $conflicts = $this->slot_lock_service->count_active_locks_for_slot( $service->id, $start_at, $end_at, null, $exclude_booking_id );
            return $conflicts < max( 1, $service->capacity ) ? null : false;
        }

        if ( $requested_resource_id ) {
            foreach ( $resources as $resource ) {
                if ( $resource->id !== $requested_resource_id ) {
                    continue;
                }

                $conflicts = $this->slot_lock_service->count_active_locks_for_slot( $service->id, $start_at, $end_at, $resource->id, $exclude_booking_id );
                return $conflicts < max( 1, $resource->capacity ) ? $resource->id : false;
            }

            return false;
        }

        foreach ( $resources as $resource ) {
            $conflicts = $this->slot_lock_service->count_active_locks_for_slot( $service->id, $start_at, $end_at, $resource->id, $exclude_booking_id );
            if ( $conflicts < max( 1, $resource->capacity ) ) {
                return $resource->id;
            }
        }

        return false;
    }

    private function get_business_timezone(): \DateTimeZone {
        $timezone = $this->settings->get( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' );

        try {
            return new \DateTimeZone( $timezone );
        } catch ( \Exception $e ) {
            return new \DateTimeZone( 'UTC' );
        }
    }

    private function get_business_now(): \DateTimeImmutable {
        return $this->clock->now()->setTimezone( $this->get_business_timezone() );
    }

    private function parse_business_datetime( string $datetime ): ?\DateTimeImmutable {
        $timezone = $this->get_business_timezone();
        $parsed   = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $datetime, $timezone );

        if ( ! $parsed ) {
            return null;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) {
            return null;
        }

        return $parsed;
    }

    private function calculate_end_at( \DateTimeImmutable $start_at, int $duration_minutes ): string {
        return $start_at->modify( '+' . $duration_minutes . ' minutes' )->format( 'Y-m-d H:i:s' );
    }
}
