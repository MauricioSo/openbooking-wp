<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contenedor liviano para bindings y singletons del plugin.
 */
class Container {

    private static array $bindings = [];
    private static array $instances = [];
    private static bool $booted = false;

    public static function bind( string $abstract, $concrete ): void {
        self::$bindings[ $abstract ] = $concrete;
    }

    public static function singleton( string $abstract, $concrete ): void {
        self::$bindings[ $abstract ] = $concrete;
        self::$instances[ $abstract ] = null;
    }

    public static function get( string $abstract ) {
        if ( ! self::$booted && ! isset( self::$bindings[ $abstract ] ) && ! array_key_exists( $abstract, self::$instances ) ) {
            self::boot();
        }

        if ( isset( self::$instances[ $abstract ] ) ) {
            return self::$instances[ $abstract ];
        }

        if ( ! isset( self::$bindings[ $abstract ] ) ) {
            return new $abstract();
        }

        $concrete = self::$bindings[ $abstract ];

        if ( is_callable( $concrete ) ) {
            $instance = $concrete( self::class );
        } elseif ( is_object( $concrete ) ) {
            $instance = $concrete;
        } else {
            $instance = new $concrete();
        }

        if ( array_key_exists( $abstract, self::$instances ) ) {
            self::$instances[ $abstract ] = $instance;
        }

        return $instance;
    }

    public static function has( string $abstract ): bool {
        return isset( self::$bindings[ $abstract ] );
    }

    public static function reset(): void {
        self::$bindings  = [];
        self::$instances = [];
        self::$booted    = false;
    }

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        // ── Core Ports (clean architecture adapters) ──

        self::singleton(
            \OpenBooking\Domain\Shared\Port\ClockInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Clock();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Shared\Port\SettingsInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Settings();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Shared\Port\SanitizerInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Sanitizer();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Shared\Port\ActorContextInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\WordPress\Adapter\WP_ActorContext();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Shared\Port\LocaleProviderInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\WordPress\Adapter\WP_LocaleProvider();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Shared\Port\PageQueryInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\WordPress\Adapter\WP_PageQuery();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Shared\Port\TransactionManagerInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Transaction_Manager();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Shared\Port\EventBusInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\WordPress\Adapter\WP_EventBus(
                    self::get( \OpenBooking\Application\Core\Service\Outbox_Service::class )
                );
            }
        );
        self::singleton(
            \OpenBooking\Application\Shared\Port\HookDispatcherInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\WordPress\Adapter\WP_HookDispatcher();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Shared\Port\RateLimiterInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\WordPress\Database\Rate_Limiter();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Shared\Repository\FeatureFlagRepositoryInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\WordPress\Feature_Flag_Repository();
            }
        );
        self::singleton(
            \OpenBooking\Application\Core\Service\Feature_Flag_Service::class,
            function () {
                return new \OpenBooking\Application\Core\Service\Feature_Flag_Service(
                    self::get( \OpenBooking\Domain\Shared\Repository\FeatureFlagRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class )
                );
            }
        );

        self::singleton(
            \OpenBooking\Domain\Payment\Repository\GatewayResolverInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\PaymentGateway\Gateway_Resolver_Adapter();
            }
        );

        // ── Repositories (singletons via interfaces) ──

        self::singleton(
            \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Booking\Booking_Repository();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Catalog\Service_Repository();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Catalog\Resource_Repository();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Customer\Customer_Repository();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Payment\Payment_Repository();
            }
        );

        // ── Audit ──

        self::bind(
            \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface::class,
            function () {
                return self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class );
            }
        );
        self::bind(
            \OpenBooking\Application\Audit\Service\Audit_Logger::class,
            function () {
                return new \OpenBooking\Application\Audit\Service\Audit_Logger(
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SanitizerInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class )
                );
            }
        );

        // ── Availability ──

        self::bind(
            \OpenBooking\Infrastructure\Persistence\Availability\Availability_Config_Repository::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Availability\Availability_Config_Repository();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Availability\Repository\AvailabilityConfigRepositoryInterface::class,
            function () {
                return self::get( \OpenBooking\Infrastructure\Persistence\Availability\Availability_Config_Repository::class );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Persistence\Availability\Availability_Snapshot_Repository::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Availability\Availability_Snapshot_Repository();
            }
        );
        self::bind(
            \OpenBooking\Domain\Availability\Service\Availability_Calculator::class,
            function () {
                return new \OpenBooking\Domain\Availability\Service\Availability_Calculator();
            }
        );
        self::bind(
            \OpenBooking\Application\Availability\Service\Availability_Service::class,
            function () {
                return new \OpenBooking\Application\Availability\Service\Availability_Service(
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Availability\Availability_Config_Repository::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Slot_Lock_Service::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class ),
                    self::get( \OpenBooking\Application\Shared\Port\HookDispatcherInterface::class ),
                    self::get( \OpenBooking\Domain\Availability\Service\Availability_Calculator::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Domain\Availability\Repository\SlotLockRepositoryInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Availability\Slot_Lock_Repository(
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Availability\Service\Slot_Lock_Service::class,
            function () {
                return new \OpenBooking\Application\Availability\Service\Slot_Lock_Service(
                    self::get( \OpenBooking\Domain\Availability\Repository\SlotLockRepositoryInterface::class )
                );
            }
        );

        // ── Booking / Payment ──

        self::bind(
            \OpenBooking\Infrastructure\Persistence\Booking\Booking_State_Log_Repository::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Booking\Booking_State_Log_Repository();
            }
        );

        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_Persistence_Service::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_Persistence_Service(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Slot_Lock_Service::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\TransactionManagerInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Booking\Booking_State_Log_Repository::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Service::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_State_Guard::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SanitizerInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_Public_Service::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_Public_Service(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Slot_Lock_Service::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_Persistence_Service::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\TransactionManagerInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Queue_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Booking\Booking_State_Log_Repository::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\EventBusInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ClockInterface::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_State_Guard::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_Token_Guard::class ),
                    self::get( \OpenBooking\Domain\Booking\Service\Booking_Token_Generator::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Service::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_Admin_Service::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_Admin_Service(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Slot_Lock_Service::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\TransactionManagerInterface::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_Persistence_Service::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Queue_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Booking\Booking_State_Log_Repository::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\EventBusInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ClockInterface::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_State_Guard::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_Token_Guard::class ),
                    self::get( \OpenBooking\Domain\Booking\Service\Booking_Token_Generator::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Service::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_Input_Validator::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_Input_Validator(
                    self::get( \OpenBooking\Domain\Shared\Port\SanitizerInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_Catalog_Resolver::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_Catalog_Resolver(
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_Customer_Resolver::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_Customer_Resolver(
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_Availability_Guard::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_Availability_Guard(
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Service::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_Payment_Initializer::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_Payment_Initializer(
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_Audit_Recorder::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_Audit_Recorder(
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_Event_Publisher::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_Event_Publisher(
                    self::get( \OpenBooking\Domain\Shared\Port\EventBusInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\UseCase\Create_Booking_Use_Case::class,
            function () {
                $validator   = self::get( \OpenBooking\Application\Booking\Service\Booking_Input_Validator::class );
                $catalog     = self::get( \OpenBooking\Application\Booking\Service\Booking_Catalog_Resolver::class );
                $customer    = self::get( \OpenBooking\Application\Booking\Service\Booking_Customer_Resolver::class );
                $availability = self::get( \OpenBooking\Application\Booking\Service\Booking_Availability_Guard::class );
                $payment     = self::get( \OpenBooking\Application\Booking\Service\Booking_Payment_Initializer::class );
                $events      = self::get( \OpenBooking\Application\Booking\Service\Booking_Event_Publisher::class );
                $audit       = self::get( \OpenBooking\Application\Booking\Service\Booking_Audit_Recorder::class );

                return new \OpenBooking\Application\Booking\UseCase\Create_Booking_Use_Case(
                    self::get( \OpenBooking\Application\Booking\Service\Booking_Persistence_Service::class ),
                    $validator,
                    $catalog,
                    $customer,
                    $availability,
                    $payment,
                    $events,
                    self::get( \OpenBooking\Domain\Booking\Service\Booking_Token_Generator::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ClockInterface::class ),
                    self::get( \OpenBooking\Application\Shared\Port\HookDispatcherInterface::class ),
                    $audit,
                    self::get( \OpenBooking\Domain\Shared\Port\SanitizerInterface::class )
                );
            }
        );
        // ── Booking Domain Services ──

        self::bind(
            \OpenBooking\Domain\Booking\Service\Booking_Cancellation_Policy::class,
            function () {
                return new \OpenBooking\Domain\Booking\Service\Booking_Cancellation_Policy(
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ClockInterface::class ),
                    self::get( \OpenBooking\Application\Shared\Port\HookDispatcherInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Domain\Booking\Service\Booking_Reschedule_Policy::class,
            function () {
                return new \OpenBooking\Domain\Booking\Service\Booking_Reschedule_Policy(
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ClockInterface::class ),
                    self::get( \OpenBooking\Application\Shared\Port\HookDispatcherInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Domain\Booking\Service\Booking_Token_Generator::class,
            function () {
                return new \OpenBooking\Domain\Booking\Service\Booking_Token_Generator(
                    self::get( \OpenBooking\Domain\Shared\Port\ClockInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class )
                );
            }
        );

        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_State_Guard::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_State_Guard(
                    self::get( \OpenBooking\Domain\Booking\Service\Booking_Cancellation_Policy::class ),
                    self::get( \OpenBooking\Domain\Booking\Service\Booking_Reschedule_Policy::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_State_Log_Recorder::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_State_Log_Recorder(
                    self::get( \OpenBooking\Infrastructure\Persistence\Booking\Booking_State_Log_Repository::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_Token_Guard::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_Token_Guard(
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class ),
                    self::get( \OpenBooking\Domain\Booking\Service\Booking_Token_Generator::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_Lock_Releaser::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_Lock_Releaser(
                    self::get( \OpenBooking\Application\Availability\Service\Slot_Lock_Service::class ),
                    self::get( \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Reschedule_Availability_Guard::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Reschedule_Availability_Guard(
                    self::get( \OpenBooking\Application\Availability\Service\Slot_Lock_Service::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\UseCase\Confirm_Booking_Use_Case::class,
            function () {
                return new \OpenBooking\Application\Booking\UseCase\Confirm_Booking_Use_Case(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_State_Guard::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_State_Log_Recorder::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\TransactionManagerInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\EventBusInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\UseCase\Cancel_Booking_Use_Case::class,
            function () {
                return new \OpenBooking\Application\Booking\UseCase\Cancel_Booking_Use_Case(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_State_Guard::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_Token_Guard::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_Lock_Releaser::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_State_Log_Recorder::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\TransactionManagerInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\EventBusInterface::class ),
                    self::get( \OpenBooking\Domain\Booking\Service\Booking_Token_Generator::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Queue_Repository::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Service::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\UseCase\Reschedule_Booking_Use_Case::class,
            function () {
                return new \OpenBooking\Application\Booking\UseCase\Reschedule_Booking_Use_Case(
                    self::get( \OpenBooking\Application\Booking\Service\Booking_Persistence_Service::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_State_Guard::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_Token_Guard::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Reschedule_Availability_Guard::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\TransactionManagerInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\EventBusInterface::class ),
                    self::get( \OpenBooking\Domain\Booking\Service\Booking_Token_Generator::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Service::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\UseCase\Mark_No_Show_Use_Case::class,
            function () {
                return new \OpenBooking\Application\Booking\UseCase\Mark_No_Show_Use_Case(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_State_Log_Recorder::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\EventBusInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Payment\Service\Payment_Service::class,
            function () {
                return new \OpenBooking\Application\Payment\Service\Payment_Service(
                    self::get( \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Payment\Payment_Attempt_Repository::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Slot_Lock_Service::class ),
                    self::get( \OpenBooking\Domain\Booking\Service\Booking_Token_Generator::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\EventBusInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\TransactionManagerInterface::class ),
                    self::get( \OpenBooking\Domain\Payment\Repository\GatewayResolverInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Payment\Service\Webhook_Security_Service::class,
            function () {
                return new \OpenBooking\Application\Payment\Service\Webhook_Security_Service(
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Payment\Payment_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Payment\Payment_Controller(
                    self::get( \OpenBooking\Application\Payment\Service\Payment_Service::class ),
                    self::get( \OpenBooking\Application\Payment\Service\Webhook_Security_Service::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\RateLimiterInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Payment\Service\Gateway_Settings_Service::class,
            function () {
                return new \OpenBooking\Application\Payment\Service\Gateway_Settings_Service(
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class ),
                    self::get( \OpenBooking\Application\Shared\Port\HookDispatcherInterface::class ),
                    self::get( \OpenBooking\Domain\Payment\Repository\GatewayResolverInterface::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Payment\Admin_Payment_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Payment\Admin_Payment_Controller(
                    self::get( \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Payment\Service\Payment_Service::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class ),
                    self::get( \OpenBooking\Domain\Payment\Repository\GatewayResolverInterface::class ),
                    self::get( \OpenBooking\Application\Payment\Service\Gateway_Settings_Service::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Persistence\Payment\Payment_Attempt_Repository::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Payment\Payment_Attempt_Repository();
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\PaymentGateway\Stripe\Stripe_Gateway::class,
            function () {
                return new \OpenBooking\Infrastructure\PaymentGateway\Stripe\Stripe_Gateway(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\PaymentGateway\MercadoPago\MercadoPago_Gateway::class,
            function () {
                return new \OpenBooking\Infrastructure\PaymentGateway\MercadoPago\MercadoPago_Gateway(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\PaymentGateway\Webpay\Webpay_Gateway::class,
            function () {
                return new \OpenBooking\Infrastructure\PaymentGateway\Webpay\Webpay_Gateway(
                    self::get( \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface::class )
                );
            }
        );

        // ── Notification ──

        self::bind(
            \OpenBooking\Infrastructure\Persistence\Notification\Notification_Queue_Repository::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Notification\Notification_Queue_Repository();
            }
        );
        self::singleton(
            \OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface::class,
            function () {
                return self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Queue_Repository::class );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Persistence\Notification\Notification_Preferences_Repository::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Notification\Notification_Preferences_Repository();
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Persistence\Notification\Notification_Campaign_Repository::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Notification\Notification_Campaign_Repository();
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Persistence\Notification\Notification_Log_Repository::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Notification\Notification_Log_Repository();
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Persistence\Notification\Consent_Log_Repository::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Notification\Consent_Log_Repository();
            }
        );

        // ── Integration ──

        self::bind(
            \OpenBooking\Infrastructure\Persistence\Integration\Integration_Request_Log_Repository::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Integration\Integration_Request_Log_Repository();
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Persistence\Integration\Integration_Client_Repository::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Integration\Integration_Client_Repository();
            }
        );
        self::bind(
            \OpenBooking\Application\Integration\Service\Integration_Authenticator::class,
            function () {
                return new \OpenBooking\Application\Integration\Service\Integration_Authenticator(
                    self::get( \OpenBooking\Infrastructure\Persistence\Integration\Integration_Client_Repository::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SanitizerInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Integration\Service\Integration_Integrity_Service::class,
            function () {
                return new \OpenBooking\Application\Integration\Service\Integration_Integrity_Service(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ClockInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Integration\Service\Integration_Booking_Service::class,
            function () {
                return new \OpenBooking\Application\Integration\Service\Integration_Booking_Service(
                    self::get( \OpenBooking\Infrastructure\Persistence\Integration\Integration_Request_Log_Repository::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Integration\Integration_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Integration\Integration_Controller(
                    self::get( \OpenBooking\Application\Integration\Service\Integration_Authenticator::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Integration\Integration_Request_Log_Repository::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Service::class ),
                    self::get( \OpenBooking\Application\Booking\UseCase\Create_Booking_Use_Case::class ),
                    self::get( \OpenBooking\Application\Booking\UseCase\Cancel_Booking_Use_Case::class ),
                    self::get( \OpenBooking\Application\Booking\UseCase\Reschedule_Booking_Use_Case::class ),
                    self::get( \OpenBooking\Application\Integration\Service\Integration_Integrity_Service::class ),
                    self::get( \OpenBooking\Application\Integration\Service\Integration_Booking_Service::class )
                );
            }
        );

        // ── Notification Services ──

        self::bind(
            \OpenBooking\Infrastructure\Notification\Email\Email_Service::class,
            function () {
                return new \OpenBooking\Infrastructure\Notification\Email\Email_Service(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Preferences_Repository::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Notification\WhatsApp\WhatsApp_Service::class,
            function () {
                return new \OpenBooking\Infrastructure\Notification\WhatsApp\WhatsApp_Service(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Preferences_Repository::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Notification\SMS\SMS_Service::class,
            function () {
                return new \OpenBooking\Infrastructure\Notification\SMS\SMS_Service(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Preferences_Repository::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Notification\Notification_Manager::class,
            function () {
                return new \OpenBooking\Infrastructure\Notification\Notification_Manager(
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Queue_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Preferences_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Campaign_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Log_Repository::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\Email\Email_Service::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\WhatsApp\WhatsApp_Service::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\SMS\SMS_Service::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Consent_Log_Repository::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Notification\Email\Email_Listener::class,
            function () {
                return new \OpenBooking\Infrastructure\Notification\Email\Email_Listener(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\Notification_Manager::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Notification\WhatsApp\WhatsApp_Listener::class,
            function () {
                return new \OpenBooking\Infrastructure\Notification\WhatsApp\WhatsApp_Listener(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\Notification_Manager::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Notification\SMS\SMS_Listener::class,
            function () {
                return new \OpenBooking\Infrastructure\Notification\SMS\SMS_Listener(
                    self::get( \OpenBooking\Infrastructure\Notification\Notification_Manager::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Notification\Service\Notification_Settings_Service::class,
            function () {
                return new \OpenBooking\Application\Notification\Service\Notification_Settings_Service(
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Notification\Service\Notification_Broadcast_Service::class,
            function () {
                return new \OpenBooking\Application\Notification\Service\Notification_Broadcast_Service(
                    self::get( \OpenBooking\Infrastructure\Notification\Notification_Manager::class ),
                    self::get( \OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Booking\UseCase\Cancel_Booking_Use_Case::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\SMS\SMS_Service::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Notification\Service\Notification_Test_Service::class,
            function () {
                return new \OpenBooking\Application\Notification\Service\Notification_Test_Service(
                    self::get( \OpenBooking\Infrastructure\Notification\Email\Email_Service::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\WhatsApp\WhatsApp_Service::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\SMS\SMS_Service::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Notification\Admin_Notification_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Notification\Admin_Notification_Controller(
                    self::get( \OpenBooking\Infrastructure\Notification\Email\Email_Service::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\WhatsApp\WhatsApp_Service::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\SMS\SMS_Service::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Log_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Queue_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\Notification_Manager::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Campaign_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Preferences_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Consent_Log_Repository::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class ),
                    self::get( \OpenBooking\Application\Booking\UseCase\Cancel_Booking_Use_Case::class ),
                    self::get( \OpenBooking\Application\Notification\Service\Notification_Settings_Service::class ),
                    self::get( \OpenBooking\Application\Notification\Service\Notification_Broadcast_Service::class ),
                    self::get( \OpenBooking\Application\Notification\Service\Notification_Test_Service::class )
                );
            }
        );

        // ── Availability Services ──

        self::bind(
            \OpenBooking\Application\Availability\Service\Availability_Snapshot_Service::class,
            function () {
                return new \OpenBooking\Application\Availability\Service\Availability_Snapshot_Service(
                    self::get( \OpenBooking\Infrastructure\Persistence\Availability\Availability_Config_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Availability\Availability_Snapshot_Repository::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Service::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Availability\Service\Availability_Preview_Service::class,
            function () {
                return new \OpenBooking\Application\Availability\Service\Availability_Preview_Service(
                    self::get( \OpenBooking\Infrastructure\Persistence\Availability\Availability_Config_Repository::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Availability\Service\Availability_Config_Save_Service::class,
            function () {
                return new \OpenBooking\Application\Availability\Service\Availability_Config_Save_Service(
                    self::get( \OpenBooking\Domain\Availability\Repository\AvailabilityConfigRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Service::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Snapshot_Service::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Availability\Availability_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Availability\Availability_Controller(
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Service::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Availability\Availability_Config_Repository::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Preview_Service::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Snapshot_Service::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Config_Save_Service::class )
                );
            }
        );

        // ── Core Services ──

        self::bind(
            \OpenBooking\Application\Core\Service\Integrity_Check_Service::class,
            function () {
                return new \OpenBooking\Application\Core\Service\Integrity_Check_Service(
                    self::get( \OpenBooking\Application\Availability\Service\Slot_Lock_Service::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Availability\Repository\SlotLockRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Availability\Availability_Config_Repository::class ),
                    self::get( \OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Integration\Outbox\Outbox_Event_Repository::class,
            function () {
                return new \OpenBooking\Infrastructure\Integration\Outbox\Outbox_Event_Repository();
            }
        );
        self::bind(
            \OpenBooking\Application\Core\Service\Outbox_Service::class,
            function () {
                return new \OpenBooking\Application\Core\Service\Outbox_Service(
                    self::get( \OpenBooking\Infrastructure\Integration\Outbox\Outbox_Event_Repository::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Core\Service\Outbox_Worker::class,
            function () {
                return new \OpenBooking\Application\Core\Service\Outbox_Worker(
                    self::get( \OpenBooking\Infrastructure\Integration\Outbox\Outbox_Event_Repository::class ),
                    self::get( \OpenBooking\Application\Shared\Port\HookDispatcherInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Core\Service\Health_Check_Service::class,
            function () {
                return new \OpenBooking\Application\Core\Service\Health_Check_Service(
                    self::get( \OpenBooking\Infrastructure\Integration\Outbox\Outbox_Event_Repository::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Log_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class ),
                    self::get( \OpenBooking\Domain\Availability\Repository\SlotLockRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActivatorInterface::class ),
                    self::get( \OpenBooking\Application\Payment\Service\Gateway_Settings_Service::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Core\Health_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Core\Health_Controller(
                    self::get( \OpenBooking\Application\Core\Service\Health_Check_Service::class )
                );
            }
        );
        self::singleton(
            \OpenBooking\Domain\Shared\Port\ActivatorInterface::class,
            function () {
                return new class implements \OpenBooking\Domain\Shared\Port\ActivatorInterface {
                    public function get_schema_version(): int {
                        return \OpenBooking\Infrastructure\WordPress\Database\Activator::get_schema_version();
                    }

                    public function needs_migration(): bool {
                        return \OpenBooking\Infrastructure\WordPress\Database\Activator::needs_migration();
                    }

                    public function get_expected_schema_version(): int {
                        return \OpenBooking\Infrastructure\WordPress\Database\Activator::SCHEMA_VERSION;
                    }
                };
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Core\Admin_Outbox_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Core\Admin_Outbox_Controller(
                    self::get( \OpenBooking\Infrastructure\Integration\Outbox\Outbox_Event_Repository::class ),
                    self::get( \OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Core\Service\Dashboard_Service::class,
            function () {
                return new \OpenBooking\Application\Core\Service\Dashboard_Service(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Core\Admin_Dashboard_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Core\Admin_Dashboard_Controller(
                    self::get( \OpenBooking\Application\Core\Service\Dashboard_Service::class )
                );
            }
        );

        // ── Settings Services ──

        self::bind(
            \OpenBooking\Application\Settings\Service\Onboarding_Preset_Service::class,
            function () {
                return new \OpenBooking\Application\Settings\Service\Onboarding_Preset_Service(
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Availability\Availability_Config_Repository::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\LocaleProviderInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\PageQueryInterface::class )
                );
            }
        );

        self::bind(
            \OpenBooking\Application\Settings\Service\Settings_Save_Service::class,
            function () {
                return new \OpenBooking\Application\Settings\Service\Settings_Save_Service(
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class ),
                    self::get( \OpenBooking\Infrastructure\WordPress\Cron\Cron_Manager::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Service::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class )
                );
            }
        );

        self::bind(
            \OpenBooking\Application\Settings\Service\Onboarding_Service::class,
            function () {
                return new \OpenBooking\Application\Settings\Service\Onboarding_Service(
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Availability\Availability_Config_Repository::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class )
                );
            }
        );

        self::bind(
            \OpenBooking\Presentation\Rest\Settings\Admin_Settings_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Settings\Admin_Settings_Controller(
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Availability\Availability_Config_Repository::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class ),
                    self::get( \OpenBooking\Application\Settings\Service\Onboarding_Preset_Service::class ),
                    self::get( \OpenBooking\Application\Core\Service\Integrity_Check_Service::class ),
                    self::get( \OpenBooking\Infrastructure\WordPress\Cron\Cron_Manager::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface::class ),
                    new \OpenBooking\Infrastructure\Persistence\Booking\Public_Form_Field_Repository(),
                    self::get( \OpenBooking\Application\Core\Service\Feature_Flag_Service::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Service::class ),
                    self::get( \OpenBooking\Application\Settings\Service\Settings_Save_Service::class ),
                    self::get( \OpenBooking\Application\Settings\Service\Onboarding_Service::class )
                );
            }
        );

        self::bind(
            \OpenBooking\Presentation\Rest\Catalog\Service_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Catalog\Service_Controller(
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Catalog\Service\Service_Crud_Service::class,
            function () {
                return new \OpenBooking\Application\Catalog\Service\Service_Crud_Service(
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Audit\Repository\AuditRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\ActorContextInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Catalog\Admin_Service_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Catalog\Admin_Service_Controller(
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Catalog\Service\Service_Crud_Service::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Catalog\Service\Resource_Crud_Service::class,
            function () {
                return new \OpenBooking\Application\Catalog\Service\Resource_Crud_Service(
                    self::get( \OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Catalog\Admin_Resource_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Catalog\Admin_Resource_Controller(
                    self::get( \OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Catalog\Service\Resource_Crud_Service::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Customer\Service\Customer_Crud_Service::class,
            function () {
                return new \OpenBooking\Application\Customer\Service\Customer_Crud_Service(
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Customer\Admin_Customer_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Customer\Admin_Customer_Controller(
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class ),
                    self::get( \OpenBooking\Application\Customer\Service\Customer_Crud_Service::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Application\Audit\Service\Audit_Enrichment_Service::class,
            function () {
                return new \OpenBooking\Application\Audit\Service\Audit_Enrichment_Service(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Audit\Admin_Audit_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Audit\Admin_Audit_Controller(
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Enrichment_Service::class )
                );
            }
        );

        // ── Controllers ──

        self::bind(
            \OpenBooking\Presentation\Rest\Booking\Booking_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Booking\Booking_Controller(
                    self::get( \OpenBooking\Application\Booking\UseCase\Create_Booking_Use_Case::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_Public_Service::class ),
                    self::get( \OpenBooking\Application\Booking\UseCase\Cancel_Booking_Use_Case::class ),
                    self::get( \OpenBooking\Application\Booking\UseCase\Reschedule_Booking_Use_Case::class ),
                    self::get( \OpenBooking\Application\Payment\Service\Payment_Service::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    new \OpenBooking\Infrastructure\Persistence\Booking\Public_Form_Field_Repository()
                );
            }
        );
        self::bind(
            \OpenBooking\Domain\Booking\Repository\BookingTimelineRepositoryInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\Persistence\Booking\Booking_Timeline_Repository();
            }
        );
        self::bind(
            \OpenBooking\Application\Booking\Service\Booking_Export_Service::class,
            function () {
                return new \OpenBooking\Application\Booking\Service\Booking_Export_Service(
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Booking\Admin_Booking_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Booking\Admin_Booking_Controller(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_Export_Service::class ),
                    self::get( \OpenBooking\Application\Booking\UseCase\Create_Booking_Use_Case::class ),
                    self::get( \OpenBooking\Application\Booking\UseCase\Cancel_Booking_Use_Case::class ),
                    self::get( \OpenBooking\Application\Booking\UseCase\Confirm_Booking_Use_Case::class ),
                    self::get( \OpenBooking\Application\Booking\UseCase\Mark_No_Show_Use_Case::class ),
                    self::get( \OpenBooking\Application\Booking\UseCase\Reschedule_Booking_Use_Case::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingTimelineRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\RateLimiterInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Integration\Booking_Facade::class,
            function () {
                return new \OpenBooking\Infrastructure\Integration\Booking_Facade(
                    self::get( \OpenBooking\Application\Booking\UseCase\Create_Booking_Use_Case::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_Public_Service::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Availability_Service::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Integration\Outbox\Outbox_Webhook_Handler::class,
            function () {
                return new \OpenBooking\Infrastructure\Integration\Outbox\Outbox_Webhook_Handler(
                    null,
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Infrastructure\Integration\Integration_Manager::class,
            function () {
                return new \OpenBooking\Infrastructure\Integration\Integration_Manager(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\EventBusInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Admin\Menu\Admin_Menu::class,
            function () {
                return new \OpenBooking\Presentation\Admin\Menu\Admin_Menu(
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface::class )
                );
            }
        );

        // ── Cron ──

        self::bind(
            \OpenBooking\Infrastructure\WordPress\Cron\Cron_Manager::class,
            function () {
                return new \OpenBooking\Infrastructure\WordPress\Cron\Cron_Manager(
                    self::get( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Integration\Outbox\Outbox_Event_Repository::class ),
                    self::get( \OpenBooking\Application\Availability\Service\Slot_Lock_Service::class ),
                    self::get( \OpenBooking\Application\Core\Service\Outbox_Worker::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\Notification_Manager::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\WhatsApp\WhatsApp_Service::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\SMS\SMS_Service::class ),
                    self::get( \OpenBooking\Application\Booking\Service\Booking_Admin_Service::class ),
                    self::get( \OpenBooking\Application\Payment\Service\Payment_Service::class )
                );
            }
        );

        // ── REST API: route-only controllers ──

        self::bind(
            \OpenBooking\Application\Core\Service\Cron_Status_Service::class,
            function () {
                return new \OpenBooking\Application\Core\Service\Cron_Status_Service(
                    self::get( \OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface::class ),
                    self::get( \OpenBooking\Application\Shared\Port\HookDispatcherInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\SettingsInterface::class )
                );
            }
        );
        self::bind(
            \OpenBooking\Presentation\Rest\Core\Admin_Cron_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Core\Admin_Cron_Controller(
                    self::get( \OpenBooking\Application\Core\Service\Cron_Status_Service::class )
                );
            }
        );

        self::bind(
            \OpenBooking\Presentation\Rest\Core\Admin_Webhook_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Core\Admin_Webhook_Controller();
            }
        );

        self::bind(
            \OpenBooking\Presentation\Rest\Core\Telemetry_Controller::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Core\Telemetry_Controller(
                    self::get( \OpenBooking\Domain\Shared\Port\RateLimiterInterface::class )
                );
            }
        );

        // ── REST API Registrar ──

        self::bind(
            \OpenBooking\Presentation\Rest\Core\Rest_Api_Registrar::class,
            function () {
                return new \OpenBooking\Presentation\Rest\Core\Rest_Api_Registrar(
                    self::get( \OpenBooking\Presentation\Rest\Booking\Booking_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Booking\Admin_Booking_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Availability\Availability_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Payment\Payment_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Payment\Admin_Payment_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Customer\Admin_Customer_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Catalog\Service_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Catalog\Admin_Service_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Catalog\Admin_Resource_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Settings\Admin_Settings_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Notification\Admin_Notification_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Audit\Admin_Audit_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Core\Health_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Core\Admin_Dashboard_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Core\Admin_Outbox_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Integration\Integration_Controller::class ),
                    self::get( \OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface::class ),
                    self::get( \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Notification_Preferences_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Notification\Notification_Manager::class ),
                    self::get( \OpenBooking\Application\Payment\Service\Payment_Service::class ),
                    self::get( \OpenBooking\Application\Audit\Service\Audit_Logger::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\RateLimiterInterface::class ),
                    self::get( \OpenBooking\Domain\Payment\Repository\GatewayResolverInterface::class ),
                    self::get( \OpenBooking\Domain\Shared\Port\PrivacyHandlerInterface::class ),
                    self::get( \OpenBooking\Presentation\Rest\Core\Admin_Cron_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Core\Admin_Webhook_Controller::class ),
                    self::get( \OpenBooking\Presentation\Rest\Core\Telemetry_Controller::class ),
                    self::get( \OpenBooking\Application\Payment\Service\Gateway_Settings_Service::class )
                );
            }
        );

        self::singleton(
            \OpenBooking\Domain\Shared\Port\PrivacyHandlerInterface::class,
            function () {
                return new \OpenBooking\Infrastructure\WordPress\Privacy\Customer_Privacy_Handler(
                    self::get( \OpenBooking\Infrastructure\Persistence\Customer\Customer_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Booking\Booking_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Catalog\Service_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Payment\Payment_Repository::class ),
                    self::get( \OpenBooking\Infrastructure\Persistence\Notification\Consent_Log_Repository::class )
                );
            }
        );
    }
}
