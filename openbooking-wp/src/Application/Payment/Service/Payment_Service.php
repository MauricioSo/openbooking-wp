<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Payment\Service;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Booking\Event\BookingConfirmed;
use OpenBooking\Domain\Shared\Port\EventBusInterface;
use OpenBooking\Domain\Shared\Port\SettingsInterface;
use OpenBooking\Domain\Shared\Port\ClockInterface;
use OpenBooking\Domain\Shared\Port\SanitizerInterface;
use OpenBooking\Domain\Shared\Port\ActorContextInterface;
use OpenBooking\Application\Shared\Port\HookDispatcherInterface;
use OpenBooking\Domain\Payment\Event\PaymentDisputed;
use OpenBooking\Domain\Payment\Event\PaymentFailed;
use OpenBooking\Domain\Payment\Event\PaymentReceived;
use OpenBooking\Domain\Booking\Service\Booking_Token_Generator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Coordina la vida de un pago desde checkout hasta confirmacion o fallo.
 */
class Payment_Service {

    /**
     * Payment state transitions are governed by Payment_State_Machine.
     * Delegate all transition validation to the canonical state machine class.
     */

    private ?SettingsInterface $settings = null;
    private ?ClockInterface $clock = null;
    private ?SanitizerInterface $sanitizer = null;
    private ?ActorContextInterface $actor_context = null;
    private ?HookDispatcherInterface $hooks = null;

    public function __construct(
        private \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface $payment_repo, // consulta y persiste pagos
        private \OpenBooking\Domain\Payment\Repository\PaymentAttemptRepositoryInterface $attempt_repo, // intentos de pago
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo, // consulta y persiste reservas
        private \OpenBooking\Application\Audit\Service\Audit_Logger $audit_logger, // deja trazabilidad de cambios
        private \OpenBooking\Application\Availability\Service\Slot_Lock_Service $slot_lock_service, // bloquea slots temporalmente
        private Booking_Token_Generator $token_generator, // genera tokens de acceso
        private EventBusInterface $event_bus, // publica eventos de dominio
        private \OpenBooking\Domain\Shared\Port\TransactionManagerInterface $transaction_manager, // gestiona transacciones DB
        private \OpenBooking\Domain\Payment\Repository\GatewayResolverInterface $gateway_resolver, // resuelve pasarela de pago
        ?SettingsInterface $settings = null,
        ?ClockInterface $clock = null,
        ?SanitizerInterface $sanitizer = null,
        ?ActorContextInterface $actor_context = null,
        ?HookDispatcherInterface $hooks = null,
    ) {
        $this->settings      = $settings ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Settings();
        $this->clock         = $clock ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Clock();
        $this->sanitizer     = $sanitizer ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Sanitizer();
        $this->actor_context = $actor_context ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_ActorContext();
        $this->hooks         = $hooks ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_HookDispatcher();
    }

    private function now_utc(): string {
        return $this->clock()->now()->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
    }

    private function is_booking_hold_expired( ?string $expires_at ): bool {
        if ( ! $expires_at ) {
            return false;
        }

        $expires = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $expires_at, new \DateTimeZone( 'UTC' ) );
        if ( ! $expires ) {
            $timestamp = strtotime( $expires_at );
            return $timestamp !== false && $timestamp <= $this->clock()->now()->getTimestamp();
        }

        return $expires->getTimestamp() <= $this->clock()->now()->setTimezone( new \DateTimeZone( 'UTC' ) )->getTimestamp();
    }

    private function settings(): SettingsInterface {
        if ( ! $this->settings ) {
            $this->settings = new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Settings();
        }
        return $this->settings;
    }

    private function clock(): ClockInterface {
        if ( ! $this->clock ) {
            $this->clock = new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Clock();
        }
        return $this->clock;
    }

    private function sanitizer(): SanitizerInterface {
        if ( ! $this->sanitizer ) {
            $this->sanitizer = new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Sanitizer();
        }
        return $this->sanitizer;
    }

    private function actor_context(): ActorContextInterface {
        if ( ! $this->actor_context ) {
            $this->actor_context = new \OpenBooking\Infrastructure\WordPress\Adapter\WP_ActorContext();
        }
        return $this->actor_context;
    }

    private function hooks(): HookDispatcherInterface {
        if ( ! $this->hooks ) {
            $this->hooks = new \OpenBooking\Infrastructure\WordPress\Adapter\WP_HookDispatcher();
        }
        return $this->hooks;
    }

    public function create_checkout( array $data ): array {
        $booking_id  = $this->sanitizer()->absint( $data['booking_id'] ?? 0 );
        $gateway_key = $this->sanitizer()->text( $data['gateway'] ?? 'manual' );
        $public_token = $this->sanitizer()->text( $data['public_token'] ?? '' );
        $is_admin_request = $this->actor_context()->is_user_logged_in()
            && $this->actor_context()->current_user_can( 'manage_options' );

        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking ) {
            return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
        }

        if ( ! $is_admin_request && ! $this->booking_matches_public_token( $booking, $public_token ) ) {
            return [ 'error' => __( 'Acceso de pago no autorizado.', 'openbooking-wp' ), 'code' => 403 ];
        }

        $enabled_gateways = $this->gateway_resolver->get_enabled_for_country(
            (string) $this->settings()->get( Setting_Keys::BUSINESS_COUNTRY, '' )
        );

        if ( ! isset( $enabled_gateways[ $gateway_key ] ) ) {
            return [ 'error' => __( 'Medio de pago no disponible.', 'openbooking-wp' ), 'code' => 400 ];
        }

        if ( $gateway_key === 'manual' ) {
            $not_payable = $this->assert_booking_payable( $booking );
            if ( $not_payable ) {
                return $not_payable;
            }
            if ( $is_admin_request ) {
                return $this->mark_manual_payment_as_paid( $booking );
            }

            return $this->register_public_manual_payment( $booking );
        }

        $gateway = $this->gateway_resolver->get( $gateway_key );
        if ( ! $gateway ) {
            return [ 'error' => __( 'Medio de pago no encontrado.', 'openbooking-wp' ), 'code' => 400 ];
        }

        $prepared = $this->prepare_payment_for_checkout( $booking_id, $gateway_key );
        if ( ! empty( $prepared['error'] ) ) {
            return $prepared;
        }

        $payment = $prepared['payment'];
        $booking = $prepared['booking'];
        $reused  = $prepared['reused'];

        if ( $reused && $payment->checkout_url && $payment->checkout_expires_at ) {
            try {
                $expires = new \DateTimeImmutable( $payment->checkout_expires_at, new \DateTimeZone( 'UTC' ) );
                $now     = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
                if ( $expires > $now ) {
                    return [
                        'success'    => true,
                        'type'       => 'redirect',
                        'reused'     => true,
                        'checkout'   => [
                            'redirect_url' => $payment->checkout_url,
                            'session_id'   => $payment->provider_checkout_id ?? '',
                        ],
                        'payment_id' => $payment->id,
                        'ui_state'   => [
                            'key' => 'redirect_to_gateway',
                            'title' => 'Continúa con el pago',
                            'subtitle' => 'Te redirigiremos al proveedor de pago para completar la reserva.',
                        ],
                    ];
                }
            } catch ( \Exception $e ) {
            }
        }

        $attempt_id = $this->attempt_repo->insert( [
            'payment_id'   => $payment->id,
            'booking_id'   => $booking_id,
            'gateway'      => $gateway_key,
            'amount_minor' => $payment->amount_minor,
            'currency'     => $payment->currency,
            'ip_address'   => \OpenBooking\Support\Request_Context::get_ip_address(),
        ] );

        $checkout_result = $gateway->create_checkout( $booking_id, [
            'payment_id' => $payment->id,
            'amount'     => $payment->amount_minor,
            'currency'   => $payment->currency,
        ] );

        if ( ! empty( $checkout_result['error'] ) ) {
            if ( $attempt_id ) {
                $this->attempt_repo->resolve(
                    $attempt_id,
                    \OpenBooking\Domain\Payment\Entity\Payment_Attempt_Entity::STATUS_FAILED,
                    null,
                    [ 'error' => $checkout_result['error'], 'stage' => 'checkout_create' ]
                );
            }

            $payment->status = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED;
            $payment->raw_payload = wp_json_encode( [
                'stage' => 'checkout_create',
                'error' => $checkout_result['error'],
                'code'  => $checkout_result['code'] ?? 400,
            ] );
            $this->payment_repo->update( $payment );

            $this->audit_logger->log_entity_change(
                'payment',
                $payment->id,
                'checkout_creation_failed',
                [
                    'status'      => \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PENDING,
                    'raw_payload' => null,
                ],
                $payment->to_array(),
                [ 'gateway' => $gateway_key ],
                [
                    'message'        => 'Payment checkout creation failed before redirect URL was returned.',
                    'allowed_fields' => [ 'status', 'raw_payload' ],
                ]
            );

            return [
                'error' => $checkout_result['error'],
                'code'  => $checkout_result['code'] ?? 400,
            ];
        }

        $payment->provider_checkout_id = $checkout_result['session_id'] ?? $checkout_result['preference_id'] ?? null;
        $payment->checkout_url         = $checkout_result['redirect_url'] ?? null;
        $checkout_ttl                  = max( 5, (int) $this->settings()->get( Setting_Keys::CHECKOUT_TTL_MINUTES, 30 ) );
        $payment->checkout_expires_at  = $this->clock()->now()->setTimezone( new \DateTimeZone( 'UTC' ) )
            ->modify( '+' . $checkout_ttl . ' minutes' )
            ->format( 'Y-m-d H:i:s' );
        $this->payment_repo->update( $payment );

        return [
            'success'    => true,
            'type'       => 'redirect',
            'reused'     => $reused,
            'checkout'   => $checkout_result,
            'payment_id' => $payment->id,
            'ui_state'   => [
                'key' => 'redirect_to_gateway',
                'title' => 'Continúa con el pago',
                'subtitle' => 'Te redirigiremos al proveedor de pago para completar la reserva.',
            ],
        ];
    }

    public function renew_hold_for_booking_token( string $token ): array {
        $booking = $this->booking_repo->find_by_booking_token( $token );
        if ( ! $booking ) {
            $booking = $this->booking_repo->find_by_view_token( $token );
        }
        if ( ! $booking ) {
            return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
        }

        return $this->renew_hold_for_booking( $booking->id );
    }

    private function mark_manual_payment_as_paid( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking ): array {
        $this->transaction_manager->begin();

        try {
            $booking_locked = $this->booking_repo->find_locked( $booking->id );
            if ( ! $booking_locked ) {
                $this->transaction_manager->rollback();
                return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
            }

            if ( $booking_locked->status === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED ) {
                $this->transaction_manager->rollback();
                return [
                    'success'    => true,
                    'type'       => 'manual',
                    'payment_id' => 0,
                    'booking_id' => $booking_locked->id,
                    'ui_state'   => [
                        'key' => 'manual_paid',
                        'title' => 'Reserva confirmada',
                        'subtitle' => 'El pago fue confirmado manualmente desde administración.',
                    ],
                ];
            }

            $payment = new \OpenBooking\Domain\Payment\Entity\Payment_Entity();
            $payment->booking_id   = $booking_locked->id;
            $payment->gateway      = 'manual';
            $payment->status       = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID;
            $payment->amount_minor = $booking_locked->price_due_now_minor;
            $payment->currency     = $booking_locked->currency;
            $payment->mode         = 'manual';
            $payment->paid_at      = $this->now_utc();
            $payment->id           = $this->payment_repo->insert( $payment );

            if ( ! $payment->id ) {
                $this->transaction_manager->rollback();
                return [ 'error' => __( 'No se pudo crear el pago.', 'openbooking-wp' ), 'code' => 500 ];
            }

            $old_booking = $booking_locked->to_array();
            $booking_locked->payment_status   = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PAID;
            $booking_locked->status           = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED;
            $booking_locked->price_paid_minor = $booking_locked->price_due_now_minor;
            $this->booking_repo->update( $booking_locked );

            $this->slot_lock_service->confirm_for_booking( $booking_locked->id );

            $this->transaction_manager->commit();
        } catch ( \Throwable $e ) {
            $this->transaction_manager->rollback();
            throw $e;
        }

        $booking = $booking_locked;

            $this->audit_logger->log( [
                'entity_type' => 'payment',
                'entity_id'   => $payment->id,
                'action'      => 'admin_mark_payment_paid',
                'message'     => 'Payment marked as paid manually.',
                'context'     => [
                    'gateway'      => $payment->gateway,
                    'amount_minor' => $payment->amount_minor,
                    'booking_id'   => $booking->id,
                ],
            ] );

            $this->audit_logger->log_entity_change(
                'booking',
                $booking->id,
                'payment_status_synced_to_booking',
                $old_booking,
                $booking->to_array(),
                [
                    'payment_id' => $payment->id,
                    'gateway'    => $payment->gateway,
                ],
                [
                    'message'        => 'Booking synchronized after manual payment confirmation.',
                    'allowed_fields' => [ 'status', 'payment_status', 'price_paid_minor' ],
                ]
            );

            $this->event_bus->dispatch( new PaymentReceived( $payment->id, $payment->to_array() ) );
            $this->event_bus->dispatch( new BookingConfirmed( $booking->id, $booking->to_array() ) );

            return [
                'success'    => true,
                'type'       => 'manual',
                'payment_id' => $payment->id,
                'booking_id' => $booking->id,
                'ui_state'   => [
                    'key' => 'manual_paid',
                    'title' => 'Reserva confirmada',
                    'subtitle' => 'El pago fue confirmado manualmente desde administración.',
                ],
            ];
    }

    private function register_public_manual_payment( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking ): array {
        $this->transaction_manager->begin();

        try {
            $booking_locked = $this->booking_repo->find_locked( $booking->id );
            if ( ! $booking_locked ) {
                $this->transaction_manager->rollback();
                return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
            }

            $not_payable = $this->assert_booking_payable( $booking_locked );
            if ( $not_payable ) {
                $this->transaction_manager->rollback();
                return $not_payable;
            }

            $payment = $this->payment_repo->find_pending_for_booking_gateway_locked( $booking_locked->id, 'manual' );
            $created = false;

            if ( ! $payment ) {
                $payment = new \OpenBooking\Domain\Payment\Entity\Payment_Entity();
                $payment->booking_id   = $booking_locked->id;
                $payment->gateway      = 'manual';
                $payment->status       = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PENDING;
                $payment->amount_minor = $booking_locked->price_due_now_minor;
                $payment->currency     = $booking_locked->currency;
                $payment->mode         = 'manual';
                $payment->id           = $this->payment_repo->insert( $payment );

                if ( ! $payment->id ) {
                    $this->transaction_manager->rollback();
                    $this->audit_logger->log( [
                        'entity_type' => 'payment',
                        'entity_id'   => 0,
                        'action'      => 'manual_payment_insert_failed',
                        'severity'    => 'error',
                        'message'     => 'Failed to insert manual payment record.',
                        'context'     => [
                            'gateway'      => 'manual',
                            'amount_minor' => $booking_locked->price_due_now_minor,
                            'booking_id'   => $booking_locked->id,
                        ],
                    ] );
                    return [
                        'success' => false,
                        'error'   => 'No se pudo registrar el pago. Intenta de nuevo.',
                        'code'    => 500,
                    ];
                }

                $created = true;
            }

            $this->transaction_manager->commit();
        } catch ( \Throwable $e ) {
            $this->transaction_manager->rollback();
            throw $e;
        }

        if ( $created ) {
            $this->audit_logger->log( [
                'entity_type' => 'payment',
                'entity_id'   => $payment->id,
                'action'      => 'public_select_manual_payment',
                'message'     => 'Customer selected manual payment.',
                'context'     => [
                    'gateway'      => $payment->gateway,
                    'amount_minor' => $payment->amount_minor,
                    'booking_id'   => $booking_locked->id,
                ],
            ] );
        }

        return [
            'success'    => true,
            'type'       => 'manual_pending',
            'payment_id' => $payment->id,
            'booking_id' => $booking_locked->id,
            'ui_state'   => [
                'key' => 'manual_pending',
                'title' => 'Reserva registrada',
                'subtitle' => 'Seleccionaste pago manual. Te avisaremos cuando validemos el pago.',
            ],
        ];
    }

    private function booking_matches_public_token( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking, string $public_token ): bool {
        if ( '' === $public_token ) {
            return false;
        }

        if ( ! $this->token_generator->is_booking_token_valid( $booking ) ) {
            return false;
        }

        return hash_equals( (string) $booking->get_payment_token(), $public_token );
    }

    public function handle_webhook( string $gateway_key, string $payload, array $headers = [] ): array {
        $gateway = $this->gateway_resolver->get( $gateway_key );
        if ( ! $gateway ) {
            return [ 'error' => 'Gateway not found.' ];
        }

        $result = $gateway->handle_webhook( $payload, $headers );

        if ( ! empty( $result['error'] ) ) {
            $this->audit_logger->log( [
                'entity_type' => 'payment_gateway',
                'entity_id'   => 0,
                'action'      => 'gateway_webhook_rejected',
                'message'     => 'Gateway webhook rejected.',
                'severity'    => 'warning',
                'source'      => 'webhook',
                'context'     => [
                    'gateway' => $gateway_key,
                    'error'   => $result['error'],
                ],
            ] );
            return $result;
        }

        $event_processed_locally = false;

        if ( empty( $result['payment_id'] ) ) {
            $this->audit_logger->log( [
                'entity_type' => 'payment_gateway',
                'entity_id'   => 0,
                'action'      => 'webhook_missing_payment_id',
                'message'     => 'Gateway webhook did not include a local payment_id.',
                'severity'    => 'warning',
                'source'      => 'webhook',
                'context'     => [
                    'gateway'   => $gateway_key,
                    'event_ref' => $this->extract_safe_webhook_event_ref( $result['raw_payload'] ?? null ),
                ],
            ] );

            return [ 'error' => 'payment_id is required.', 'http_status' => 400 ];
        }

        if ( ! empty( $result['payment_id'] ) && ! empty( $result['status'] ) ) {
            $payment = $this->payment_repo->find( (int) $result['payment_id'] );
            if ( ! $payment ) {
                $this->audit_logger->log( [
                    'entity_type' => 'payment',
                    'entity_id'   => 0,
                    'action'      => 'webhook_payment_not_found',
                    'message'     => 'Gateway webhook referenced a payment that does not exist locally.',
                    'severity'    => 'warning',
                    'source'      => 'webhook',
                    'context'     => array_filter( [
                        'gateway'             => $gateway_key,
                        'payment_id'          => (int) $result['payment_id'],
                        'requested_status'    => $this->sanitizer()->text( (string) $result['status'] ),
                        'provider_payment_id' => ! empty( $result['provider_payment_id'] ) ? $this->sanitizer()->text( (string) $result['provider_payment_id'] ) : null,
                        'event_ref'           => $this->extract_safe_webhook_event_ref( $result['raw_payload'] ?? null ),
                    ] ),
                ] );
                return [ 'error' => 'Payment not found.', 'http_status' => 404 ];
            }

            if ( $payment->gateway !== $gateway_key ) {
                $this->audit_logger->log( [
                    'entity_type' => 'payment',
                    'entity_id'   => $payment->id,
                    'action'      => 'webhook_gateway_mismatch',
                    'message'     => 'Gateway webhook referenced a payment owned by another gateway.',
                    'severity'    => 'warning',
                    'source'      => 'webhook',
                    'context'     => [
                        'webhook_gateway' => $gateway_key,
                        'payment_gateway' => $payment->gateway,
                        'payment_id'      => $payment->id,
                    ],
                ] );
                return [ 'handled' => true ];
            }

            $old_status  = $payment->status;
            $old_payment = $payment->to_array();

            // State machine guard: delegate to the canonical Payment_State_Machine.
            if ( ! \OpenBooking\Domain\Payment\Service\Payment_State_Machine::can_transition( $old_status, $result['status'] ) ) {
                $this->audit_logger->log( [
                    'entity_type' => 'payment',
                    'entity_id'   => $payment->id,
                    'action'      => 'webhook_transition_rejected',
                    'message'     => "Rejected transition {$old_status} → {$result['status']} (not allowed).",
                    'severity'    => 'warning',
                    'source'      => 'webhook',
                    'context'     => [
                        'gateway'    => $gateway_key,
                        'from'       => $old_status,
                        'to'         => $result['status'],
                    ],
                ] );
                return [ 'handled' => true ];
            }

            if ( $result['status'] === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID ) {
                $gateway_amount   = $result['gateway_amount_minor'] ?? null;
                $gateway_currency = $result['gateway_currency'] ?? null;

                if ( $gateway_amount !== null && (int) $gateway_amount !== (int) $payment->amount_minor ) {
                    $this->audit_logger->log( [
                        'entity_type' => 'payment',
                        'entity_id'   => $payment->id,
                        'action'      => 'webhook_amount_mismatch',
                        'message'     => sprintf(
                            'Webhook amount %d does not match payment amount %d.',
                            (int) $gateway_amount,
                            (int) $payment->amount_minor
                        ),
                        'severity'    => 'warning',
                        'source'      => 'webhook',
                        'context'     => [
                            'gateway'           => $gateway_key,
                            'gateway_amount'    => $gateway_amount,
                            'expected_amount'   => $payment->amount_minor,
                            'gateway_currency'  => $gateway_currency,
                            'expected_currency' => $payment->currency,
                        ],
                    ] );
                    return [ 'handled' => true ];
                }

                if ( $gateway_currency !== null && strtoupper( $gateway_currency ) !== strtoupper( $payment->currency ) ) {
                    $this->audit_logger->log( [
                        'entity_type' => 'payment',
                        'entity_id'   => $payment->id,
                        'action'      => 'webhook_currency_mismatch',
                        'message'     => sprintf(
                            'Webhook currency %s does not match payment currency %s.',
                            $gateway_currency,
                            $payment->currency
                        ),
                        'severity'    => 'warning',
                        'source'      => 'webhook',
                        'context'     => [
                            'gateway'           => $gateway_key,
                            'gateway_currency'  => $gateway_currency,
                            'expected_currency' => $payment->currency,
                        ],
                    ] );
                    return [ 'handled' => true ];
                }

                // Atomic compare-and-swap: only the first concurrent webhook wins.
                // The UPDATE uses WHERE status != 'paid', so if another process already
                // committed, affected rows = 0 and we return immediately (idempotent).
                $updated = $this->payment_repo->mark_as_paid_atomically(
                    $payment->id,
                    $this->now_utc(),
                    $result['provider_payment_id'] ?? null,
                    $result['raw_payload'] ?? null
                );
                if ( ! $updated ) {
                    return [ 'handled' => true ];
                }
                $event_processed_locally = true;
                $payment = $this->payment_repo->find( $payment->id );

                // Resolve the most recent initiated attempt for this payment as succeeded.
                $this->resolve_latest_attempt(
                    $payment->id,
                    \OpenBooking\Domain\Payment\Entity\Payment_Attempt_Entity::STATUS_SUCCEEDED,
                    $result['provider_payment_id'] ?? null
                );
            } else {
                // Non-paid transitions (failed, refunded…) — double-processing is harmless.
                $payment->status = $result['status'];
                if ( ! empty( $result['provider_payment_id'] ) ) {
                    $payment->provider_payment_id = $result['provider_payment_id'];
                }
                if ( ! empty( $result['raw_payload'] ) ) {
                    $payment->raw_payload = $result['raw_payload'];
                }
                $event_processed_locally = $this->payment_repo->update( $payment ) !== false;

                // Resolve attempt ledger for terminal non-success states.
                if ( in_array( $result['status'], [
                    \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED,
                    \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED,
                ], true ) ) {
                    $this->resolve_latest_attempt(
                        $payment->id,
                        \OpenBooking\Domain\Payment\Entity\Payment_Attempt_Entity::STATUS_FAILED,
                        $result['provider_payment_id'] ?? null
                    );
                } elseif ( $result['status'] === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED ) {
                    $this->resolve_latest_attempt(
                        $payment->id,
                        \OpenBooking\Domain\Payment\Entity\Payment_Attempt_Entity::STATUS_REFUNDED,
                        $result['provider_payment_id'] ?? null
                    );
                }
            }

            if ( $payment ) {

                $this->audit_logger->log_entity_change(
                    'payment',
                    $payment->id,
                    'admin_change_payment_status',
                    $old_payment,
                    $payment->to_array(),
                    [ 'gateway' => $gateway_key ],
                    [
                        'message'        => 'Payment status updated from gateway webhook.',
                        'source'         => 'webhook',
                        'allowed_fields' => [ 'status', 'provider_payment_id', 'raw_payload', 'paid_at' ],
                    ]
                );

                if ( $old_status !== \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID && $result['status'] === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID ) {
                    $booking = $this->booking_repo->find( $payment->booking_id );
                    if ( $booking ) {
                        $can_confirm = $booking->status === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_PENDING
                            && ! $this->is_booking_hold_expired( $booking->expires_at );

                        if ( $can_confirm ) {
                            $this->transaction_manager->begin();

                            try {
                                $booking_locked = $this->booking_repo->find_locked( $booking->id );
                                if ( ! $booking_locked || $booking_locked->status !== \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_PENDING ) {
                                    $this->transaction_manager->rollback();
                                } else {
                                    $old_booking = $booking_locked->to_array();
                                    $booking_locked->payment_status   = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PAID;
                                    $booking_locked->status           = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED;
                                    $booking_locked->price_paid_minor = max( $booking_locked->price_paid_minor, $payment->amount_minor );
                                    $this->booking_repo->update( $booking_locked );

                                    $this->slot_lock_service->confirm_for_booking( $booking_locked->id );

                                    $this->transaction_manager->commit();

                                    $booking = $booking_locked;

                                    $this->audit_logger->log_entity_change(
                                        'booking',
                                        $booking->id,
                                        'payment_status_synced_to_booking',
                                        $old_booking,
                                        $booking->to_array(),
                                        [
                                            'payment_id' => $payment->id,
                                            'gateway'    => $gateway_key,
                                        ],
                                        [
                                            'message'        => 'Booking synchronized after successful payment webhook.',
                                            'source'         => 'webhook',
                                            'allowed_fields' => [ 'status', 'payment_status', 'price_paid_minor' ],
                                        ]
                                    );

                                    $this->hooks()->do_action( 'openbooking_payment_received', $payment->id, $payment->to_array() );
                                    $this->hooks()->do_action( 'openbooking_booking_confirmed', $booking->id, $booking->to_safe_array() );
                                }
                            } catch ( \Throwable $e ) {
                                $this->transaction_manager->rollback();
                                throw $e;
                            }
                        } else {
                            $this->audit_logger->log( [
                                'entity_type' => 'payment',
                                'entity_id'   => $payment->id,
                                'action'      => 'late_payment_after_booking_expired',
                                'message'     => 'Payment received but booking is no longer confirmable (expired/cancelled or hold expired). Payment requires manual review.',
                                'severity'    => 'warning',
                                'source'      => 'webhook',
                                'context'     => [
                                    'booking_id'        => $booking->id,
                                    'booking_status'    => $booking->status,
                                    'booking_expires_at'=> $booking->expires_at,
                                    'payment_id'        => $payment->id,
                                    'gateway'           => $gateway_key,
                                ],
                            ] );
                        }
                    } else {
                        $this->audit_logger->log( [
                            'entity_type' => 'payment',
                            'entity_id'   => $payment->id,
                            'action'      => 'payment_status_desynced_warning',
                            'message'     => 'Payment updated but related booking was not found.',
                            'severity'    => 'warning',
                            'source'      => 'webhook',
                            'context'     => [
                                'booking_id' => $payment->booking_id,
                                'status'     => $payment->status,
                            ],
                        ] );
                    }
                } elseif ( in_array( $result['status'], [
                    \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED,
                    \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED,
                ], true ) ) {
                    $booking = $this->booking_repo->find( $payment->booking_id );
                    if ( $booking ) {
                        $old_booking = $booking->to_array();
                        $booking->payment_status = $result['status'] === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED
                            ? \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_EXPIRED
                            : \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_FAILED;
                        if ( $result['status'] === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED
                             && $booking->status === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_PENDING ) {
                            $booking->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_EXPIRED;
                        }
                        $this->booking_repo->update( $booking );

                        if ( $result['status'] === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED ) {
                            $this->slot_lock_service->expire_for_booking( $booking->id );
                        } else {
                            $this->slot_lock_service->release_for_booking( $booking->id, 'payment_failed' );
                        }

                        $this->audit_logger->log_entity_change(
                            'booking',
                            $booking->id,
                            'payment_status_synced_to_booking',
                            $old_booking,
                            $booking->to_array(),
                            [
                                'payment_id' => $payment->id,
                                'gateway'    => $gateway_key,
                            ],
                            [
                                'message'        => 'Booking synchronized after non-success payment webhook.',
                                'source'         => 'webhook',
                                'allowed_fields' => [ 'payment_status' ],
                            ]
                        );

                        if ( $result['status'] === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED ) {
                            $this->event_bus->dispatch( new PaymentFailed( $payment->id, $payment->to_array() ) );

                            if ( $booking->status === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_EXPIRED ) {
                                $this->audit_logger->log( [
                                    'entity_type' => 'payment',
                                    'entity_id'   => $payment->id,
                                    'action'      => 'late_payment_after_expiration',
                                    'message'     => 'A payment webhook was received after booking expiration.',
                                    'severity'    => 'warning',
                                    'source'      => 'webhook',
                                    'context'     => [
                                        'booking_id'     => $booking->id,
                                        'booking_status' => $booking->status,
                                        'payment_status' => $payment->status,
                                    ],
                                ] );
                            }
                        }
                    }
                }
            }
        }

        $this->audit_logger->log( [
            'entity_type' => 'payment_gateway',
            'entity_id'   => 0,
            'action'      => 'gateway_webhook_processed',
            'message'     => 'Gateway webhook processed successfully.',
            'source'      => 'webhook',
            'context'     => [
                'gateway'    => $gateway_key,
                'payment_id' => $result['payment_id'] ?? null,
                'status'     => $result['status'] ?? null,
            ],
        ] );

        if ( $event_processed_locally ) {
            $this->mark_webhook_event_processed( $result );
        }

        return [ 'handled' => true ];
    }

    private function mark_webhook_event_processed( array $result ): void {
        $key = $result['event_transient_key'] ?? null;
        if ( is_string( $key ) && $key !== '' && function_exists( 'set_transient' ) ) {
            set_transient( $key, 1, DAY_IN_SECONDS );
        }
    }

    public function sync_refund_result( int $payment_id, ?int $amount_minor, array $result, string $reason = 'admin_refund' ): array {
        $payment = $this->payment_repo->find( $payment_id );
        if ( ! $payment ) {
            return [ 'error' => __( 'Pago no encontrado.', 'openbooking-wp' ), 'code' => 404 ];
        }

        $booking = $this->booking_repo->find( $payment->booking_id );
        $refunded_amount = $amount_minor ?: $payment->amount_minor;
        $old_payment = $payment->to_array();

        $next_status = $refunded_amount < $payment->amount_minor
            ? \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PARTIALLY_PAID
            : \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED;

        if ( ! \OpenBooking\Domain\Payment\Service\Payment_State_Machine::can_transition( $payment->status, $next_status ) ) {
            return [ 'error' => "No se puede reembolsar un pago en estado '{$payment->status}'.", 'code' => 409 ];
        }

        $payment->status = $next_status;
        $this->payment_repo->update( $payment );

        $this->audit_logger->log_entity_change(
            'payment',
            $payment->id,
            $refunded_amount < $payment->amount_minor ? 'admin_refund_partial' : 'admin_refund_full',
            $old_payment,
            $payment->to_array(),
            [
                'amount_minor'  => $refunded_amount,
                'refund_result' => $result,
                'reason'        => $reason,
            ],
            [
                'message'        => 'Payment refund synchronized into OpenBooking.',
                'allowed_fields' => [ 'status' ],
            ]
        );

        if ( $booking ) {
            $old_booking = $booking->to_array();
            $booking->payment_status = $payment->status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED
                ? \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_REFUNDED
                : \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PARTIALLY_PAID;
            $booking->price_paid_minor = max( 0, $booking->price_paid_minor - $refunded_amount );
            if ( $payment->status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED ) {
                $booking->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CANCELLED_BY_ADMIN;
            }
            $this->booking_repo->update( $booking );

            if ( $payment->status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED ) {
                $this->slot_lock_service->release_for_booking( $booking->id, 'admin_refund_full' );
            }

            $this->audit_logger->log_entity_change(
                'booking',
                $booking->id,
                'payment_status_synced_to_booking',
                $old_booking,
                $booking->to_array(),
                [
                    'payment_id'    => $payment->id,
                    'amount_minor'  => $refunded_amount,
                    'refund_result' => $result,
                ],
                [
                    'message'        => 'Booking synchronized after refund.',
                    'allowed_fields' => [ 'status', 'payment_status', 'price_paid_minor' ],
                ]
            );
        }

        return [
            'success' => true,
            'payment' => $payment->to_array(),
            'booking' => $booking ? $booking->to_array() : null,
        ];
    }

    public function get_payments_for_booking( int $booking_id ): array {
        return $this->payment_repo->find_by_booking( $booking_id );
    }

    public function get_available_gateways(): array {
        $country  = (string) $this->settings()->get( Setting_Keys::BUSINESS_COUNTRY, '' );
        $gateways = $this->gateway_resolver->get_gateways_for_country( $country );
        return $gateways;
    }

    public function reconcile_inconsistencies( int $limit = 200 ): int {
        $payments   = $this->payment_repo->find_recent_reconcilable( $limit );
        $reconciled = 0;

        foreach ( $payments as $payment ) {
            $booking = $this->booking_repo->find( $payment->booking_id );
            if ( ! $booking ) {
                continue;
            }

            $old_booking = $booking->to_array();
            $changed     = false;

            switch ( $payment->status ) {
                case \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID:
                    $can_confirm = $booking->status === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_PENDING
                        && ! $this->is_booking_hold_expired( $booking->expires_at );

                    if ( ! $can_confirm ) {
                        $this->audit_logger->log( [
                            'entity_type' => 'payment',
                            'entity_id'   => $payment->id,
                            'action'      => 'late_payment_after_booking_expired',
                            'message'     => 'Payment is paid but booking is no longer confirmable. Payment requires manual review.',
                            'severity'    => 'warning',
                            'source'      => 'cron',
                            'actor_type'  => 'system',
                            'context'     => [
                                'booking_id'         => $booking->id,
                                'booking_status'     => $booking->status,
                                'booking_expires_at' => $booking->expires_at,
                                'payment_id'         => $payment->id,
                                'gateway'            => $payment->gateway,
                            ],
                        ] );
                        continue 2;
                    }

                    if ( $booking->payment_status !== \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PAID ) {
                        $booking->payment_status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PAID;
                        $changed = true;
                    }
                    if ( $booking->status !== \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED ) {
                        $booking->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED;
                        $changed = true;
                    }
                    if ( $booking->price_paid_minor < $payment->amount_minor ) {
                        $booking->price_paid_minor = $payment->amount_minor;
                        $changed = true;
                    }
                    break;

                case \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED:
                    if ( $booking->payment_status !== \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_FAILED ) {
                        $booking->payment_status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_FAILED;
                        $changed = true;
                    }
                    break;

                case \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED:
                    if ( $booking->payment_status !== \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_EXPIRED ) {
                        $booking->payment_status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_EXPIRED;
                        $changed = true;
                    }
                    if ( $booking->status === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_PENDING ) {
                        $booking->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_EXPIRED;
                        $changed = true;
                    }
                    break;

                case \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED:
                    if ( $booking->payment_status !== \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_REFUNDED ) {
                        $booking->payment_status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_REFUNDED;
                        $changed = true;
                    }
                    if ( $booking->price_paid_minor !== 0 ) {
                        $booking->price_paid_minor = 0;
                        $changed = true;
                    }
                    break;

                case \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PARTIALLY_PAID:
                    if ( $booking->payment_status !== \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PARTIALLY_PAID ) {
                        $booking->payment_status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PARTIALLY_PAID;
                        $changed = true;
                    }
                    break;

                case \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_DISPUTED:
                    // Disputed payments: flag the booking but don't change booking status,
                    // as the dispute may be reversed. Mark for manual review.
                    if ( $booking->payment_status !== 'disputed' ) {
                        $booking->payment_status = 'disputed';
                        $changed = true;
                        $desync_type = 'disputed_payment';
                    }
                    break;
            }

            if ( ! $changed ) {
                continue;
            }

            if ( $payment->status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID
                 && $booking->status === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED ) {
                $this->transaction_manager->begin();
                try {
                    if ( ! $this->booking_repo->update( $booking ) || ! $this->slot_lock_service->confirm_for_booking( $booking->id ) ) {
                        $this->transaction_manager->rollback();
                        $this->audit_logger->log( [
                            'entity_type' => 'booking',
                            'entity_id'   => $booking->id,
                            'action'      => 'payment_reconciliation_failed',
                            'message'     => 'Paid payment reconciliation failed while updating booking or confirming slot lock.',
                            'severity'    => 'error',
                            'source'      => 'cron',
                            'actor_type'  => 'system',
                            'context'     => [
                                'payment_id' => $payment->id,
                                'gateway'    => $payment->gateway,
                            ],
                        ] );
                        continue;
                    }
                    $this->transaction_manager->commit();
                } catch ( \Throwable $e ) {
                    $this->transaction_manager->rollback();
                    throw $e;
                }
            } else {
                $this->booking_repo->update( $booking );
                if ( $payment->status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED ) {
                    $this->slot_lock_service->release_for_booking( $booking->id, 'payment_failed_reconciliation' );
                } elseif ( $payment->status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED ) {
                    $this->slot_lock_service->expire_for_booking( $booking->id );
                }
            }

            // Classify the type of desync for richer audit trail.
            $desync_type = $desync_type ?? $this->classify_desync( $old_booking, $booking->to_array(), $payment );

            $this->audit_logger->log_entity_change(
                'booking',
                $booking->id,
                'booking_payment_reconciled',
                $old_booking,
                $booking->to_array(),
                [
                    'payment_id'  => $payment->id,
                    'gateway'     => $payment->gateway,
                    'desync_type' => $desync_type,
                ],
                [
                    'message'        => "Booking state reconciled from payment state. Desync type: {$desync_type}.",
                    'source'         => 'cron',
                    'actor_type'     => 'system',
                    'allowed_fields' => [ 'status', 'payment_status', 'price_paid_minor' ],
                ]
            );
            $reconciled++;
        }

        $orphaned = $this->payment_repo->find_orphaned_pending( $limit );
        foreach ( $orphaned as $payment ) {
            if ( ! $this->payment_repo->expire_pending_payment( $payment->id ) ) {
                continue;
            }

            $this->audit_logger->log( [
                'entity_type' => 'payment',
                'entity_id'   => $payment->id,
                'action'      => 'orphaned_pending_payment_expired',
                'message'     => 'Pending payment expired by reconciliation — booking already in terminal state.',
                'severity'    => 'warning',
                'source'      => 'cron',
                'actor_type'  => 'system',
                'context'     => [
                    'payment_id' => $payment->id,
                    'booking_id' => $payment->booking_id,
                    'gateway'    => $payment->gateway,
                    'amount'     => $payment->amount_minor,
                ],
            ] );
            $reconciled++;
        }

        return $reconciled;
    }

    /**
     * Classify the nature of a booking/payment desync for audit purposes.
     */
    private function classify_desync( array $old_booking, array $new_booking, \OpenBooking\Domain\Payment\Entity\Payment_Entity $payment ): string {
        $ps_changed = $old_booking['payment_status'] !== $new_booking['payment_status'];
        $bs_changed = $old_booking['status'] !== $new_booking['status'];
        $pp_changed = $old_booking['price_paid_minor'] !== $new_booking['price_paid_minor'];

        if ( $payment->status === 'paid' && $bs_changed ) {
            return 'paid_but_booking_not_confirmed';
        }
        if ( $payment->status === 'paid' && $pp_changed ) {
            return 'paid_but_price_not_synced';
        }
        if ( in_array( $payment->status, [ 'failed', 'expired' ], true ) && $ps_changed ) {
            return 'failed_payment_not_reflected';
        }
        if ( $payment->status === 'refunded' && $ps_changed ) {
            return 'refund_not_reflected';
        }
        if ( $ps_changed ) {
            return 'payment_status_mismatch';
        }
        return 'generic_desync';
    }

    private function extract_safe_webhook_event_ref( $raw_payload ): ?string {
        if ( ! $raw_payload ) {
            return null;
        }

        $payload = is_array( $raw_payload ) ? $raw_payload : json_decode( (string) $raw_payload, true );
        if ( ! is_array( $payload ) ) {
            return null;
        }

        foreach ( [ 'id', 'event_id', 'type', 'action' ] as $key ) {
            if ( ! empty( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) ) {
                return $this->sanitizer()->text( (string) $payload[ $key ] );
            }
        }

        return null;
    }

    /**
     * Get the full attempt ledger for a payment aggregate.
     *
     * @return \OpenBooking\Domain\Payment\Entity\Payment_Attempt_Entity[]
     */
    public function get_attempt_ledger( int $payment_id ): array {
        return $this->attempt_repo->find_by_payment( $payment_id );
    }

    /**
     * Get all payment attempts for a booking (across all its payment aggregates).
     *
     * @return \OpenBooking\Domain\Payment\Entity\Payment_Attempt_Entity[]
     */
    public function get_booking_attempt_history( int $booking_id ): array {
        return $this->attempt_repo->find_by_booking( $booking_id );
    }

    /**
     * Mark a payment as disputed (chargeback received).
     * Transitions: paid → disputed only (guard from state machine).
     */
    public function mark_disputed( int $payment_id, string $reason = '' ): array {
        $payment = $this->payment_repo->find( $payment_id );
        if ( ! $payment ) {
            return [ 'error' => __( 'Pago no encontrado.', 'openbooking-wp' ), 'code' => 404 ];
        }

        if ( ! \OpenBooking\Domain\Payment\Service\Payment_State_Machine::can_transition( $payment->status, \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_DISPUTED ) ) {
            return [ 'error' => "No se puede disputar un pago en estado '{$payment->status}'.", 'code' => 409 ];
        }

        $old_payment       = $payment->to_array();
        $payment->status   = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_DISPUTED;
        $this->payment_repo->update( $payment );

        $this->payment_repo->mark_disputed( $payment->id, $this->sanitizer()->text( $reason ) );

        $booking = $this->booking_repo->find( $payment->booking_id );
        if ( $booking ) {
            $old_booking             = $booking->to_array();
            $booking->payment_status = 'disputed';
            $this->booking_repo->update( $booking );

            $this->audit_logger->log_entity_change(
                'booking',
                $booking->id,
                'payment_disputed_synced',
                $old_booking,
                $booking->to_array(),
                [ 'payment_id' => $payment->id, 'reason' => $reason ],
                [ 'message' => 'Booking marked as disputed after chargeback.', 'allowed_fields' => [ 'payment_status' ] ]
            );
        }

        $this->audit_logger->log_entity_change(
            'payment',
            $payment->id,
            'payment_disputed',
            $old_payment,
            $payment->to_array(),
            [ 'reason' => $reason ],
            [
                'message'        => 'Payment marked as disputed (chargeback).',
                'severity'       => 'warning',
                'allowed_fields' => [ 'status' ],
            ]
        );

        $this->event_bus->dispatch( new PaymentDisputed( $payment->id, $payment->to_array() ) );

        return [ 'success' => true, 'payment' => $payment->to_array() ];
    }

    /**
     * Resolve the most recent `initiated` attempt for a payment aggregate.
     * Used when a webhook fires to close the loop on an attempt that was opened
     * during checkout creation.  Silently no-ops if the ledger table does not
     * exist yet (before migration 005).
     */
    private function resolve_latest_attempt( int $payment_id, string $status, ?string $gateway_ref ): void {
        $attempts = $this->attempt_repo->find_by_payment( $payment_id );
        foreach ( $attempts as $attempt ) {
            if ( $attempt->status === \OpenBooking\Domain\Payment\Entity\Payment_Attempt_Entity::STATUS_INITIATED ) {
                $this->attempt_repo->resolve( $attempt->id, $status, $gateway_ref );
                break;
            }
        }
    }

    private function find_reusable_pending_payment( int $booking_id, string $gateway_key ): ?\OpenBooking\Domain\Payment\Entity\Payment_Entity {
        foreach ( $this->payment_repo->find_by_booking( $booking_id ) as $payment ) {
            if ( $payment->gateway === $gateway_key && $payment->status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PENDING ) {
                return $payment;
            }
        }

        return null;
    }

    private function assert_booking_payable( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking ): ?array {
        if ( ! in_array( $booking->status, [
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_PENDING,
        ], true ) ) {
            return [ 'error' => __( 'La reserva ya no admite pagos.', 'openbooking-wp' ), 'code' => 409 ];
        }

        if ( $this->is_booking_hold_expired( $booking->expires_at ) ) {
            return [ 'error' => __( 'La reserva expiró. Crea una nueva reserva para continuar.', 'openbooking-wp' ), 'code' => 409 ];
        }

        if ( in_array( $booking->payment_status, [
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PAID,
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_REFUNDED,
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_EXPIRED,
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_FAILED,
        ], true ) ) {
            return [ 'error' => __( 'Esta reserva ya no admite nuevos pagos.', 'openbooking-wp' ), 'code' => 409 ];
        }

        if ( $booking->price_due_now_minor <= 0 ) {
            return [ 'error' => __( 'Esta reserva no requiere pago online.', 'openbooking-wp' ), 'code' => 409 ];
        }

        return null;
    }

    private function renew_hold_for_booking( int $booking_id ): array {
        $this->transaction_manager->begin();

        try {
            $booking = $this->booking_repo->find_locked( $booking_id );
            if ( ! $booking ) {
                $this->transaction_manager->rollback();
                return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
            }

            if ( $booking->status !== \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_PENDING || $booking->payment_status !== \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PENDING ) {
                $this->transaction_manager->rollback();
                return [ 'error' => __( 'La reserva ya no puede renovar su hold.', 'openbooking-wp' ), 'code' => 409 ];
            }

            $payment = $this->find_active_checkout_payment( $booking_id );
            if ( ! $payment ) {
                $this->transaction_manager->rollback();
                return [ 'error' => __( 'No hay un checkout activo para renovar.', 'openbooking-wp' ), 'code' => 409 ];
            }

            if ( $this->is_booking_hold_expired( $booking->expires_at ) ) {
                $this->transaction_manager->rollback();
                return [ 'error' => __( 'La reserva ya expiró.', 'openbooking-wp' ), 'code' => 409 ];
            }

            $renewals = max( 0, (int) ( $this->booking_repo->get_meta( $booking_id, 'payment_hold_renewals' ) ?? 0 ) );
            if ( $renewals >= 1 ) {
                $this->transaction_manager->rollback();
                return [
                    'success'       => true,
                    'renewed'       => false,
                    'limit_reached' => true,
                    'expires_at'    => $booking->expires_at,
                    'booking_id'    => $booking->id,
                ];
            }

            $ttl_minutes = max( 5, (int) $this->settings()->get( Setting_Keys::BOOKING_EXPIRY_MINUTES, 15 ) );
            $new_expires_at = ( new \DateTimeImmutable( $this->now_utc(), new \DateTimeZone( 'UTC' ) ) )
                ->modify( '+' . $ttl_minutes . ' minutes' )
                ->format( 'Y-m-d H:i:s' );

            $old_booking = $booking->to_array();
            $booking->expires_at = $new_expires_at;

            if ( ! $this->booking_repo->update( $booking ) ) {
                $this->transaction_manager->rollback();
                return [ 'error' => __( 'No se pudo renovar el hold.', 'openbooking-wp' ), 'code' => 500 ];
            }

            $locks_updated = $this->slot_lock_service->extend_expires_for_booking( $booking_id, $new_expires_at );
            if ( $locks_updated <= 0 ) {
                $this->transaction_manager->rollback();
                return [ 'error' => __( 'No se pudo renovar el hold.', 'openbooking-wp' ), 'code' => 500 ];
            }

            $this->booking_repo->set_meta( $booking_id, 'payment_hold_renewals', '1' );
            $this->booking_repo->set_meta( $booking_id, 'payment_hold_renewed_at', $this->now_utc() );

            $this->transaction_manager->commit();

            $this->audit_logger->log_entity_change(
                'booking',
                $booking_id,
                'payment_hold_renewed',
                $old_booking,
                $booking->to_array(),
                [ 'payment_id' => $payment->id ],
                [
                    'message'        => 'Active checkout hold renewed once.',
                    'allowed_fields' => [ 'expires_at' ],
                ]
            );

            return [
                'success'    => true,
                'renewed'    => true,
                'booking_id' => $booking->id,
                'expires_at' => $new_expires_at,
            ];
        } catch ( \Throwable $e ) {
            $this->transaction_manager->rollback();
            throw $e;
        }
    }

    public function change_payment_status_manually( int $payment_id, string $new_status, string $reason ): array {
        $payment = $this->payment_repo->find( $payment_id );
        if ( ! $payment ) {
            return [ 'error' => __( 'Pago no encontrado.', 'openbooking-wp' ), 'code' => 404 ];
        }

        $allowed_statuses = [
            \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PENDING,
            \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID,
            \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED,
            \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED,
            \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED,
            \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PARTIALLY_PAID,
        ];
        if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
            return [ 'error' => __( 'Estado de pago no permitido.', 'openbooking-wp' ), 'code' => 400 ];
        }

        $old_status = $payment->status;

        if ( ! \OpenBooking\Domain\Payment\Service\Payment_State_Machine::can_transition( $old_status, $new_status ) ) {
            $this->audit_logger->log_entity_change(
                'payment',
                $payment->id,
                'admin_transition_rejected',
                [ 'status' => $old_status ],
                [ 'status' => $new_status ],
                [ 'reason' => $reason ],
                [
                    'message'  => "Admin manual transition {$old_status} → {$new_status} rejected by state machine.",
                    'severity' => 'warning',
                ]
            );
            return [ 'error' => sprintf( 'No se puede cambiar de %s a %s.', $old_status, $new_status ), 'code' => 409 ];
        }

        if ( $new_status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID ) {
            $this->transaction_manager->begin();
            try {
                $booking_locked = $this->booking_repo->find_locked( $payment->booking_id );
                if ( ! $booking_locked ) {
                    $this->transaction_manager->rollback();
                    return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
                }

                $can_confirm = $booking_locked->status === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_PENDING
                    && ! $this->is_booking_hold_expired( $booking_locked->expires_at );

                if ( ! $can_confirm ) {
                    $this->transaction_manager->rollback();
                    $this->audit_logger->log( [
                        'entity_type' => 'booking',
                        'entity_id'   => $booking_locked->id,
                        'action'      => 'manual_paid_booking_not_confirmable',
                        'message'     => 'Manual payment was not marked paid because the booking is no longer confirmable.',
                        'severity'    => 'warning',
                        'context'     => [
                            'payment_id'     => $payment->id,
                            'booking_status' => $booking_locked->status,
                            'expires_at'     => $booking_locked->expires_at,
                        ],
                    ] );
                    return [ 'error' => __( 'La reserva ya no puede confirmarse automaticamente.', 'openbooking-wp' ), 'code' => 409 ];
                }

                $old_payment = $payment->to_array();
                $old_booking = $booking_locked->to_array();

                $payment->status = $new_status;
                if ( empty( $payment->paid_at ) ) {
                    $payment->paid_at = $this->now_utc();
                }

                $booking_locked->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED;
                $booking_locked->payment_status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PAID;
                $booking_locked->price_paid_minor = max( $booking_locked->price_paid_minor, $payment->amount_minor );

                if ( ! $this->payment_repo->update( $payment )
                     || ! $this->booking_repo->update( $booking_locked )
                     || ! $this->slot_lock_service->confirm_for_booking( $booking_locked->id ) ) {
                    $this->transaction_manager->rollback();
                    $this->audit_logger->log( [
                        'entity_type' => 'payment',
                        'entity_id'   => $payment->id,
                        'action'      => 'admin_mark_payment_paid_failed',
                        'message'     => 'Manual paid transition failed while updating payment, booking, or slot lock.',
                        'severity'    => 'error',
                        'context'     => [
                            'booking_id' => $booking_locked->id,
                            'reason'     => $reason,
                        ],
                    ] );
                    return [ 'error' => __( 'No se pudo confirmar el pago manualmente.', 'openbooking-wp' ), 'code' => 500 ];
                }

                $this->transaction_manager->commit();

                $this->audit_logger->log_entity_change(
                    'booking',
                    $booking_locked->id,
                    'payment_status_synced_to_booking',
                    $old_booking,
                    $booking_locked->to_array(),
                    [ 'payment_id' => $payment->id, 'reason' => $reason ],
                    [
                        'message'        => 'Booking synchronized after manual payment status change.',
                        'allowed_fields' => [ 'status', 'payment_status', 'price_paid_minor' ],
                    ]
                );

                $this->audit_logger->log_entity_change(
                    'payment',
                    $payment->id,
                    'admin_mark_payment_paid',
                    $old_payment,
                    $payment->to_array(),
                    [ 'reason' => $reason ],
                    [ 'message' => 'Payment status changed manually from admin.', 'allowed_fields' => [ 'status', 'paid_at' ] ]
                );

                return [
                    'success' => true,
                    'payment' => $payment->to_array(),
                    'booking' => $booking_locked->to_array(),
                ];
            } catch ( \Throwable $e ) {
                $this->transaction_manager->rollback();
                throw $e;
            }
        }

        $old_payment = $payment->to_array();
        $booking = null;

        $this->transaction_manager->begin();

        try {
            $payment->status = $new_status;
            $this->payment_repo->update( $payment );

            $booking = $this->booking_repo->find_locked( $payment->booking_id );

            if ( $booking ) {
                $old_booking = $booking->to_array();
                $status_map = [
                    \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED       => \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_REFUNDED,
                    \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PARTIALLY_PAID => \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PARTIALLY_PAID,
                    \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED        => \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_EXPIRED,
                    \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED         => \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_FAILED,
                    \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PENDING        => \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PENDING,
                ];
                $booking->payment_status = $status_map[ $new_status ] ?? \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PENDING;
                if ( $new_status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED ) {
                    $booking->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CANCELLED_BY_ADMIN;
                    $booking->price_paid_minor = 0;
                } elseif ( $new_status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED
                           && $booking->status === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_PENDING ) {
                    $booking->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_EXPIRED;
                }
                $this->booking_repo->update( $booking );

                if ( $new_status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED ) {
                    $this->slot_lock_service->release_for_booking( $booking->id, 'admin_manual_refunded' );
                } elseif ( $new_status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED ) {
                    $this->slot_lock_service->release_for_booking( $booking->id, 'admin_manual_failed' );
                } elseif ( $new_status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED ) {
                    $this->slot_lock_service->expire_for_booking( $booking->id );
                }

                $this->audit_logger->log_entity_change(
                    'booking',
                    $booking->id,
                    'payment_status_synced_to_booking',
                    $old_booking,
                    $booking->to_array(),
                    [ 'payment_id' => $payment->id, 'reason' => $reason ],
                    [ 'message' => 'Booking payment status synced after manual change.', 'allowed_fields' => [ 'status', 'payment_status', 'price_paid_minor' ] ]
                );
            }

            $this->transaction_manager->commit();
        } catch ( \Throwable $e ) {
            $this->transaction_manager->rollback();
            throw $e;
        }

        $this->audit_logger->log_entity_change(
            'payment',
            $payment->id,
            $new_status === \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID ? 'admin_mark_payment_paid' : 'admin_change_payment_status',
            $old_payment,
            $payment->to_array(),
            [ 'reason' => $reason ],
            [ 'message' => 'Payment status changed manually from admin.', 'allowed_fields' => [ 'status', 'paid_at' ] ]
        );

        return [
            'success' => true,
            'payment' => $payment->to_array(),
            'booking' => $booking ? $booking->to_array() : null,
        ];
    }

    private function find_active_checkout_payment( int $booking_id ): ?\OpenBooking\Domain\Payment\Entity\Payment_Entity {
        foreach ( $this->payment_repo->find_by_booking( $booking_id ) as $payment ) {
            if ( $payment->status !== \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PENDING ) {
                continue;
            }

            if ( empty( $payment->checkout_url ) || empty( $payment->checkout_expires_at ) ) {
                continue;
            }

            if ( strtotime( $payment->checkout_expires_at ) <= strtotime( $this->now_utc() ) ) {
                continue;
            }

            return $payment;
        }

        return null;
    }

    private function prepare_payment_for_checkout( int $booking_id, string $gateway_key ): array {
        $this->transaction_manager->begin();

        try {
            $booking = $this->booking_repo->find_locked( $booking_id );
            if ( ! $booking ) {
                $this->transaction_manager->rollback();
                return [ 'error' => __( 'Reserva no encontrada.', 'openbooking-wp' ), 'code' => 404 ];
            }

            $not_payable = $this->assert_booking_payable( $booking );
            if ( $not_payable ) {
                $this->transaction_manager->rollback();
                return $not_payable;
            }

            $payment = $this->payment_repo->find_pending_for_booking_gateway_locked( $booking_id, $gateway_key );
            $reused  = false;

            if ( $payment ) {
                $reused = true;
            } else {
                $payment = new \OpenBooking\Domain\Payment\Entity\Payment_Entity();
                $payment->booking_id   = $booking_id;
                $payment->gateway      = $gateway_key;
                $payment->status       = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PENDING;
                $payment->amount_minor = $booking->price_due_now_minor;
                $payment->currency     = $booking->currency;
                $payment->mode         = (string) $this->settings()->get( Setting_Keys::PAYMENT_MODE, 'full' );
                $payment->id           = $this->payment_repo->insert( $payment );

                if ( ! $payment->id ) {
                    $this->transaction_manager->rollback();
                    return [ 'error' => __( 'No se pudo crear el pago.', 'openbooking-wp' ), 'code' => 500 ];
                }
            }

            $this->transaction_manager->commit();

            return [ 'success' => true, 'booking' => $booking, 'payment' => $payment, 'reused' => $reused ];
        } catch ( \Throwable $e ) {
            $this->transaction_manager->rollback();
            throw $e;
        }
    }
}
