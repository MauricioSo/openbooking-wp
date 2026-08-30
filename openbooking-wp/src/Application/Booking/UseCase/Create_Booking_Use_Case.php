<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\UseCase;

use OpenBooking\Application\Booking\Service\Booking_Persistence_Service;
use OpenBooking\Application\Booking\Service\Booking_Input_Validator;
use OpenBooking\Application\Booking\Service\Booking_Catalog_Resolver;
use OpenBooking\Application\Booking\Service\Booking_Customer_Resolver;
use OpenBooking\Application\Booking\Service\Booking_Availability_Guard;
use OpenBooking\Application\Booking\Service\Booking_Payment_Initializer;
use OpenBooking\Application\Booking\Service\Booking_Event_Publisher;
use OpenBooking\Application\Booking\Service\Booking_Audit_Recorder;
use OpenBooking\Application\Booking\Service\Booking_Request_Context;
use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Shared\Port\SanitizerInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Orquesta un caso de uso del bounded context de reservas.
 */

class Create_Booking_Use_Case {

    private ?\OpenBooking\Application\Payment\Service\Payment_Service $payment_service = null;

    public function __construct(
        private Booking_Persistence_Service $persistence_service,
        private Booking_Input_Validator $validator,
        private Booking_Catalog_Resolver $catalog_resolver,
        private Booking_Customer_Resolver $customer_resolver,
        private Booking_Availability_Guard $availability_guard,
        private Booking_Payment_Initializer $payment_initializer,
        private Booking_Event_Publisher $event_publisher,
        private \OpenBooking\Domain\Booking\Service\Booking_Token_Generator $token_generator,
        private \OpenBooking\Domain\Shared\Port\SettingsInterface $settings,
        private \OpenBooking\Domain\Shared\Port\ClockInterface $clock,
        private \OpenBooking\Application\Shared\Port\HookDispatcherInterface $hooks,
        private ?Booking_Audit_Recorder $audit_recorder,
        private ?SanitizerInterface $sanitizer = null,
    ) {
        $this->sanitizer = $sanitizer ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Sanitizer();
    }

    public function execute( array $data, Booking_Request_Context $context ): array {
        return $this->orchestrate( $this->enrich_integration_meta( $data, $context ), $context );
    }

    private function orchestrate( array $data, Booking_Request_Context $context ): array {
        $validated = $this->validator->validate( $data, $context );
        if ( ! $validated['valid'] ) {
            return [ 'error' => $validated['error'], 'code' => $validated['code'] ];
        }
        $v = $validated['data'];

        $catalog = $this->catalog_resolver->resolve(
            $v['service_id'],
            $v['price_check'],
            $v['start_at_dt'],
            $v['resource_id']
        );
        if ( $catalog['error'] ) {
            $error = [ 'error' => $catalog['message'], 'code' => $catalog['code'] ];
            if ( isset( $catalog['price_changed'] ) ) {
                $error['price_changed']  = true;
                $error['previous_price'] = $catalog['previous_price'];
                $error['current_price']  = $catalog['current_price'];
            }
            return $error;
        }
        $service = $catalog['service'];
        $end_at  = $catalog['end_at'];

        if ( ! $context->is_admin() && 'public' !== $service->visibility ) {
            return [ 'error' => __( 'Servicio no disponible.', 'openbooking-wp' ), 'code' => 404 ];
        }

        $guard = $this->availability_guard->check(
            $v['service_id'],
            $v['start_at'],
            $end_at,
            $v['resource_id']
        );
        if ( ! $guard['available'] ) {
            return [ 'error' => $guard['error'], 'code' => $guard['code'] ];
        }

        $idempotency = $this->persistence_service->check_client_ref_idempotency( $v['client_ref'], $data );
        if ( $idempotency !== null ) {
            return $idempotency;
        }

        $customer = $this->customer_resolver->resolve(
            $v['email'],
            $v['first_name'],
            $v['last_name'],
            $v['phone'],
            $v['whatsapp_opt_in']
        );

        $payment = $this->payment_initializer->compute( $service );
        $booking = $this->build_booking_entity( $v, (int) $customer->id, $end_at, $payment, $data );

        $result = $this->persistence_service->persist_booking(
            $booking,
            $v['service_id'],
            $v['start_at'],
            $end_at,
            $v['client_ref'],
            $data
        );

        if ( ! empty( $result['error'] ) ) {
            return $result;
        }

        if ( ! empty( $result['duplicate'] ) ) {
            return $result;
        }

        $this->event_publisher->publish_created( $booking );
        $this->event_publisher->publish_confirmed_if_auto( $booking );

        if ( $context->is_admin() ) {
            $admin_error = $this->handle_admin_post_persist( $booking, $data );
            if ( $admin_error !== null ) {
                return $admin_error;
            }

            if ( $this->audit_recorder ) {
                $this->audit_recorder->record_creation( (int) $booking->id, 'admin', $context->actor_id() );
            }

            $response = $this->persistence_service->build_booking_response( $booking );
            $response['booking'] = $booking->to_array();
            return $response;
        }

        return $this->persistence_service->build_booking_response( $booking );
    }

    private function handle_admin_post_persist( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking, array $data ): ?array {
        $mark_as_paid = ! empty( $data['mark_as_paid'] );

        if ( $booking->price_due_now_minor <= 0 ) {
            $booking->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED;
            $this->persistence_service->update_booking_status( $booking );
        } elseif ( $mark_as_paid && $this->payment_service ) {
            $payment_result  = $this->payment_service->create_checkout( [
                'booking_id' => $booking->id,
                'gateway'    => 'manual',
            ] );

            if ( ! empty( $payment_result['error'] ) ) {
                return $payment_result;
            }

            if ( empty( $payment_result['error'] ) ) {
                $refreshed = $this->persistence_service->find_booking( $booking->id );
                if ( $refreshed ) {
                    $booking->status         = $refreshed->status;
                    $booking->payment_status = $refreshed->payment_status;
                }
            }
        }

        return null;
    }

    private function enrich_integration_meta( array $data, Booking_Request_Context $context ): array {
        if ( ! $context->is_integration() ) {
            return $data;
        }

        $data['_integration_meta'] = array_merge(
            $data['_integration_meta'] ?? [],
            array_filter( [
                'integration_client_key' => $context->integration_client_key(),
                'integration_request_id' => $context->integration_request_id(),
                'external_id'            => $context->external_id(),
                'created_via'            => 'integration_api',
            ] )
        );

        return $data;
    }

    private function build_booking_entity(
        array $v,
        int $customer_id,
        string $end_at,
        array $payment,
        array $data
    ): \OpenBooking\Domain\Booking\Entity\Booking_Entity {
        $booking = new \OpenBooking\Domain\Booking\Entity\Booking_Entity();
        $booking->service_id          = $v['service_id'];
        $booking->resource_id         = $v['resource_id'];
        $booking->customer_id         = $customer_id;
        $booking->status              = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_PENDING;
        $booking->payment_status      = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PENDING;
        $booking->start_at            = $v['start_at'];
        $booking->end_at              = $end_at;
        $booking->timezone            = $this->get_timezone();
        $booking->price_total_minor   = $payment['price_total_minor'];
        $booking->price_due_now_minor = $payment['price_due_now_minor'];
        $booking->price_paid_minor    = 0;
        $booking->currency            = $payment['currency'];
        $booking->source              = $v['source'];
        $booking->notes_customer      = $v['notes'];

        if ( ! empty( $data['_integration_meta'] ) && is_array( $data['_integration_meta'] ) ) {
            $this->apply_integration_meta( $booking, $data['_integration_meta'] );
        }

        $this->generate_tokens( $booking );
        $booking->client_ref = $v['client_ref'] ?: null;

        $booking = $this->apply_before_insert_filter( $booking, $data );
        $booking->expires_at = $this->compute_booking_expiry( $booking );

        return $booking;
    }

    private function apply_integration_meta( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking, array $meta ): void {
        $booking->integration_client_key = $this->sanitizer->text( $meta['integration_client_key'] ?? '' ) ?: null;
        $booking->integration_request_id = $this->sanitizer->text( $meta['integration_request_id'] ?? '' ) ?: null;
        $booking->external_id            = $this->sanitizer->text( $meta['external_id'] ?? '' ) ?: null;
        $booking->created_via            = $this->sanitizer->text( $meta['created_via'] ?? 'core' );
    }

    private function compute_booking_expiry( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking ): string {
        $expiry_option = $booking->price_due_now_minor > 0
            ? Setting_Keys::BOOKING_EXPIRY_MINUTES
            : Setting_Keys::FREE_BOOKING_EXPIRY;
        $min_minutes     = $booking->price_due_now_minor > 0 ? 5 : 3;
        $default_minutes = $booking->price_due_now_minor > 0 ? 15 : 5;
        $expiry_minutes   = max( $min_minutes, $this->get_expiry_minutes( $expiry_option, $default_minutes ) );

        return $this->clock->now()
            ->modify( '+' . $expiry_minutes . ' minutes' )
            ->setTimezone( new \DateTimeZone( 'UTC' ) )
            ->format( 'Y-m-d H:i:s' );
    }

    protected function generate_tokens( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking ): void {
        $this->token_generator->generate_cancel_token( $booking );
        $this->token_generator->generate_reschedule_token( $booking );
        $this->token_generator->generate_view_token( $booking );
        $this->token_generator->generate_booking_token( $booking );
        $this->token_generator->generate_confirm_token( $booking );
    }

    protected function apply_before_insert_filter( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking, array $data ): \OpenBooking\Domain\Booking\Entity\Booking_Entity {
        return $this->hooks->apply_filters( 'openbooking_before_booking_insert', $booking, $data );
    }

    protected function get_timezone(): string {
        return $this->settings->get( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' );
    }

    protected function get_expiry_minutes( string $option, int $default ): int {
        return (int) $this->settings->get( $option, $default );
    }
}
