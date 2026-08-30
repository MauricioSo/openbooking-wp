<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface;
use OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface;
use OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface;
use OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface;
use OpenBooking\Application\Availability\Service\Slot_Lock_Service;
use OpenBooking\Domain\Shared\Port\TransactionManagerInterface;
use OpenBooking\Domain\Shared\Port\SettingsInterface;
use OpenBooking\Domain\Shared\Port\ActorContextInterface;
use OpenBooking\Domain\Shared\Port\SanitizerInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Coordina la logica de negocio del bounded context de reservas.
 */

class Booking_Persistence_Service {

    private ?\OpenBooking\Domain\Payment\Repository\GatewayResolverInterface $gateway_resolver = null;

    public function __construct(
        private BookingRepositoryInterface $booking_repo,
        private ServiceRepositoryInterface $service_repo,
        private CustomerRepositoryInterface $customer_repo,
        private ResourceRepositoryInterface $resource_repo,
        private PaymentRepositoryInterface $payment_repo,
        private Slot_Lock_Service $slot_lock_service,
        private TransactionManagerInterface $transaction,
        private \OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface $audit_log_repo,
        private \OpenBooking\Domain\Booking\Repository\BookingStateLogRepositoryInterface $state_log_repo,
        private \OpenBooking\Application\Availability\Service\Availability_Service $availability,
        private SettingsInterface $settings,
        private Booking_State_Guard $state_guard,
        private ?ActorContextInterface $actor_context = null,
        private ?SanitizerInterface $sanitizer = null,
    ) {
        $this->sanitizer     = $sanitizer ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Sanitizer();
        $this->actor_context = $actor_context ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_ActorContext();
    }

    public function persist_booking(
        \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking,
        int $service_id,
        string $start_at,
        string $end_at,
        string $client_ref = '',
        array $original_data = []
    ): array {
        $service = $this->service_repo->find( $service_id );
        if ( ! $service ) {
            return [ 'error' => __( 'Servicio no encontrado.', 'openbooking-wp' ), 'code' => 404 ];
        }

        $max_attempts = 3;
        $lock_id = null;
        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $this->transaction->begin();

            $resolved_resource_id = $this->resolve_resource_for_locked_slot( $service, $start_at, $end_at, $booking->resource_id, null );
            if ( $resolved_resource_id === false ) {
                $this->transaction->rollback();
                return [ 'error' => __( 'El horario seleccionado ya no está disponible.', 'openbooking-wp' ), 'code' => 409 ];
            }

            $deadlock_error = $this->transaction->last_error();
            if ( $deadlock_error && strpos( $deadlock_error, '1213' ) !== false ) {
                $this->transaction->rollback();
                if ( $attempt < $max_attempts ) {
                    usleep( 50000 * $attempt );
                    continue;
                }
                return [ 'error' => __( 'El horario seleccionado ya no está disponible.', 'openbooking-wp' ), 'code' => 409 ];
            }

            $booking->resource_id = $resolved_resource_id;

            $resources = $this->resource_repo->find_by_service( $service_id );
            $capacity  = max( 1, $service->capacity );
            if ( ! empty( $resources ) && $resolved_resource_id !== null ) {
                $matching = array_filter( $resources, fn( $r ) => $r->id === $resolved_resource_id );
                $res      = current( $matching );
                if ( $res ) {
                    $capacity = max( 1, $res->capacity );
                }
            }

            $claim = $this->slot_lock_service->claim_slot(
                $service_id,
                $resolved_resource_id,
                $start_at,
                $end_at,
                $capacity,
                $booking->expires_at
            );

            if ( ! empty( $claim['error'] ) ) {
                $this->transaction->rollback();
                if ( ! empty( $claim['code'] ) && $claim['code'] === 409 ) {
                    return [ 'error' => __( 'El horario seleccionado ya no está disponible.', 'openbooking-wp' ), 'code' => 409 ];
                }
                return [ 'error' => __( 'No se pudo reservar el horario. Intenta de nuevo.', 'openbooking-wp' ), 'code' => 503 ];
            }

            $lock_id = $claim['lock_id'];

            // Dedupe por cliente+slot: el mismo cliente no puede acumular dos reservas
            // activas en el mismo horario del mismo servicio (doble submit con refs distintas).
            if ( $booking->customer_id ) {
                $dupe = $this->booking_repo->find_active_duplicate_for_customer(
                    (int) $booking->customer_id,
                    $service_id,
                    $start_at
                );
                if ( null !== $dupe ) {
                    $this->transaction->rollback();
                    return $this->build_duplicate_booking_response( $dupe );
                }
            }

            $booking->id = $this->booking_repo->insert( $booking );

            $last_error = $this->transaction->last_error();
            if ( $last_error && strpos( $last_error, '1213' ) !== false ) {
                $this->transaction->rollback();
                $booking->id = null;
                $lock_id     = null;
                if ( $attempt < $max_attempts ) {
                    usleep( 50000 * $attempt );
                    continue;
                }
                return [ 'error' => __( 'El horario seleccionado ya no está disponible.', 'openbooking-wp' ), 'code' => 409 ];
            }

            if ( ! $booking->id ) {
                $this->transaction->rollback();
                if ( $client_ref ) {
                    $idempotency = $this->check_client_ref_idempotency( $client_ref, $original_data );
                    if ( null !== $idempotency ) {
                        return $idempotency;
                    }
                }
                return [ 'error' => __( 'No se pudo crear la reserva.', 'openbooking-wp' ), 'code' => 500 ];
            }

            $attached = $this->slot_lock_service->attach_booking( $lock_id, $booking->id );
            if ( ! $attached ) {
                $this->transaction->rollback();
                return [ 'error' => __( 'No se pudo crear la reserva.', 'openbooking-wp' ), 'code' => 500 ];
            }

            if ( $booking->price_due_now_minor <= 0 ) {
                $this->slot_lock_service->confirm_for_booking( $booking->id );

                $this->write_state_log(
                    $booking,
                    \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED,
                    'auto_confirm_free_booking',
                    'system',
                    null,
                    \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PAID
                );

                $booking->status         = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED;
                $booking->payment_status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PAID;
                $this->booking_repo->update( $booking );

                $this->audit_log_repo->insert( [
                    'entity_type' => 'booking',
                    'entity_id'   => $booking->id,
                    'action'      => 'auto_confirm_free_booking',
                    'severity'    => 'info',
                    'actor_type'  => 'system',
                    'message'     => 'Free booking auto-confirmed; no payment required.',
                ] );
            }

            $this->transaction->commit();
            break;
        }

        $this->availability->invalidate_cache( $service_id );

        return [ 'success' => true, 'booking_id' => $booking->id ];
    }

    public function find_booking( int $booking_id ): ?\OpenBooking\Domain\Booking\Entity\Booking_Entity {
        return $this->booking_repo->find( $booking_id );
    }

    public function update_booking_status( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking ): void {
        $this->booking_repo->update( $booking );
    }

    public function check_client_ref_idempotency( string $client_ref, array $data ): ?array {
        if ( ! $client_ref ) {
            return null;
        }

        $existing = $this->booking_repo->find_by_client_ref( $client_ref );
        if ( ! $existing ) {
            return null;
        }

        if ( ! $this->matches_idempotent_request( $existing, $data ) ) {
            return [ 'error' => __( 'La referencia de solicitud ya fue usada para otra reserva.', 'openbooking-wp' ), 'code' => 409 ];
        }

        return $this->build_duplicate_booking_response( $existing );
    }

    public function build_booking_response( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking ): array {
        $service_after  = $this->service_repo->find( $booking->service_id );
        $customer_after = $this->customer_repo->find( $booking->customer_id );

        $response = [
            'success'    => true,
            'booking_id' => $booking->id,
            'booking'    => $this->build_public_booking_payload( $booking, $service_after, $customer_after, true ),
            'ui_state'   => $this->describe_booking_ui_state( $booking ),
        ];

        if ( $booking->price_due_now_minor > 0 ) {
            $token = $booking->get_payment_token();
            $response['payment'] = [
                'required'        => true,
                'token'           => $token,
                'amount_minor'    => $booking->price_due_now_minor,
                'amount_currency' => $booking->currency,
                'payment_url'     => \OpenBooking\Support\Public_Booking_Page::get_url( [ Setting_Keys::TOKEN_NONCE_KEY => $token ] ),
            ];
        }

        return $response;
    }

    public function build_public_booking_payload(
        \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking,
        ?\OpenBooking\Domain\Catalog\Entity\Service_Entity $service,
        ?\OpenBooking\Domain\Customer\Entity\Customer_Entity $customer,
        bool $include_action_tokens = false
    ): array {
        $can_cancel     = (bool) ( $this->state_guard->assert_can_cancel( $booking, 'customer' )['allowed'] ?? false );
        $can_reschedule = (bool) ( $this->state_guard->assert_can_reschedule( $booking )['allowed'] ?? false );

        $payload = [
            'status'         => $booking->status,
            'payment_status' => $booking->payment_status,
            'start_at'       => $booking->start_at,
            'end_at'         => $booking->end_at,
            'timezone'       => $booking->timezone,
            'currency'       => $booking->currency,
            'price_total_minor'     => $booking->price_total_minor,
            'price_due_now_minor'   => $booking->price_due_now_minor,
            'price_due_now_formatted' => $this->format_minor_amount( $booking->price_due_now_minor, $booking->currency ),
            'price_later_formatted'   => $this->format_minor_amount( $booking->price_total_minor - $booking->price_due_now_minor, $booking->currency ),
            'service_name'   => $service ? $service->name : '',
            'service_slug'   => $service ? $service->slug : '',
            'service_price'  => $service ? $service->get_formatted_price() : '',
            'customer_name'  => $customer ? $customer->get_full_name() : '',
            'can_cancel'     => $can_cancel,
            'can_reschedule' => $can_reschedule,
            'status_label'   => $this->humanize_booking_status( $booking->status ),
            'payment_status_label' => $this->humanize_payment_status( $booking->payment_status ),
            'payment_requires_external_redirect' => $this->payment_requires_external_redirect( $booking ),
            'available_payment_gateways' => $this->get_external_gateways_for_country(),
        ];

        if ( $include_action_tokens && $can_cancel && ! empty( $booking->cancel_token ) ) {
            $payload['cancel_token'] = $booking->cancel_token;
        }
        if ( $include_action_tokens && $can_reschedule && ! empty( $booking->reschedule_token ) ) {
            $payload['reschedule_token'] = $booking->reschedule_token;
        }
        if ( $include_action_tokens && ! empty( $booking->view_token ) ) {
            $payload['view_token'] = $booking->view_token;
        }

        return $payload;
    }

    public function describe_booking_ui_state( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking ): array {
        $requires_payment = $booking->price_due_now_minor > 0;

        if ( $booking->status === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED && $booking->payment_status === \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PAID ) {
            return [
                'key' => 'confirmed',
                'title' => 'Reserva confirmada',
                'subtitle' => 'Tu reserva quedó confirmada.',
                'requires_payment' => false,
            ];
        }

        if ( $booking->payment_status === \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PENDING ) {
            return [
                'key' => $requires_payment ? 'payment_pending' : 'booking_pending',
                'title' => 'Reserva registrada',
                'subtitle' => $requires_payment
                    ? 'Tu reserva quedó registrada y estamos esperando la confirmación del pago.'
                    : 'Tu reserva quedó registrada.',
                'requires_payment' => $requires_payment,
            ];
        }

        return [
            'key' => 'in_progress',
            'title' => 'Reserva en proceso',
            'subtitle' => 'Estamos actualizando el estado de tu reserva.',
            'requires_payment' => $requires_payment,
        ];
    }

    private function build_duplicate_booking_response( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking ): array {
        $service_after  = $this->service_repo->find( $booking->service_id );
        $customer_after = $this->customer_repo->find( $booking->customer_id );

        $response = [
            'success'    => true,
            'booking_id' => $booking->id,
            'booking'    => $this->build_public_booking_payload( $booking, $service_after, $customer_after, true ),
            'duplicate'  => true,
            'ui_state'   => $this->describe_booking_ui_state( $booking ),
        ];

        if ( $booking->price_due_now_minor > 0 ) {
            $token = $booking->get_payment_token();
            $response['payment'] = [
                'required'        => true,
                'token'           => $token,
                'amount_minor'    => $booking->price_due_now_minor,
                'amount_currency' => $booking->currency,
                'payment_url'     => \OpenBooking\Support\Public_Booking_Page::get_url( [ Setting_Keys::TOKEN_NONCE_KEY => $token ] ),
            ];
        }

        return $response;
    }

    private function matches_idempotent_request( \OpenBooking\Domain\Booking\Entity\Booking_Entity $existing, array $data ): bool {
        $service_id       = $this->sanitizer->absint( $data['service_id'] ?? 0 );
        $start_at         = $this->sanitizer->text( $data['start_at'] ?? '' );
        $email            = $this->sanitizer->email( $data['email'] ?? '' );
        $requested_resource = ! empty( $data['resource_id'] ) ? $this->sanitizer->absint( $data['resource_id'] ) : null;

        if ( (int) $existing->service_id !== $service_id ) {
            return false;
        }
        if ( $existing->start_at !== $start_at ) {
            return false;
        }

        $customer = $this->customer_repo->find( $existing->customer_id );
        if ( ! $customer || strtolower( trim( $customer->email ) ) !== strtolower( trim( $email ) ) ) {
            return false;
        }

        if ( (int) ( $existing->resource_id ?? 0 ) !== (int) ( $requested_resource ?? 0 ) ) {
            return false;
        }

        return true;
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

    private function format_minor_amount( int $minor, string $currency ): string {
        return \OpenBooking\Support\Currency_Helper::format_minor( $minor, $currency );
    }

    private function humanize_booking_status( string $status ): string {
        $map = [
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_PENDING => 'Pendiente',
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED => 'Confirmada',
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CANCELLED_BY_CUSTOMER => 'Cancelada por el cliente',
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CANCELLED_BY_ADMIN => 'Cancelada por el administrador',
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_COMPLETED => 'Completada',
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_NO_SHOW => 'No asistió',
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_EXPIRED => 'Expirada',
        ];

        return $map[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
    }

    private function humanize_payment_status( string $status ): string {
        $map = [
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PENDING => 'Pendiente',
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_AUTHORIZED => 'Autorizado',
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PAID => 'Pagado',
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PARTIALLY_PAID => 'Parcialmente pagado',
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_FAILED => 'Fallido',
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_REFUNDED => 'Reembolsado',
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_EXPIRED => 'Expirado',
        ];

        return $map[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
    }

    private function payment_requires_external_redirect( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking ): bool {
        if ( $booking->price_due_now_minor <= 0 ) {
            return false;
        }
        return ! empty( $this->get_external_gateways_for_country() );
    }

    private function get_external_gateways_for_country(): array {
        $country = (string) $this->settings->get( Setting_Keys::BUSINESS_COUNTRY, '' );
        $enabled = (array) $this->settings->get( Setting_Keys::ENABLED_GATEWAYS, [] );
        $labels  = [
            'stripe'      => 'Tarjeta (Stripe)',
            'mercadopago' => 'MercadoPago',
            'webpay'      => 'Webpay',
        ];
        $out = [];
        $available = $this->gateway_resolver ? $this->gateway_resolver->get_enabled_for_country( $country ) : [];
        foreach ( $available as $key => $_g ) {
            if ( $key === 'manual' ) continue;
            if ( ! in_array( $key, $enabled, true ) ) continue;
            $out[] = [ 'key' => $key, 'label' => $labels[ $key ] ?? ucfirst( $key ) ];
        }
        return $out;
    }
}
