<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Settings\Service;

use OpenBooking\Support\Setting_Keys;
use OpenBooking\Support\Option_Keys;

use OpenBooking\Domain\Shared\Port\LocaleProviderInterface;
use OpenBooking\Domain\Shared\Port\PageQueryInterface;
use OpenBooking\Domain\Shared\Port\SettingsInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Onboarding_Preset_Service {


    public function __construct(
        private \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface $service_repo,
        private \OpenBooking\Domain\Availability\Repository\AvailabilityConfigRepositoryInterface $availability_repo,
        private SettingsInterface $settings,
        private LocaleProviderInterface $locale_provider,
        private PageQueryInterface $page_query,
    ) {}

    private const PRESETS = [
        'health'        => [
            'label'             => 'Salud / Clinica',
            'icon'              => 'dashicons-heart',
            'description'       => 'Consultas medicas, dental, psicologia, kinesiologia',
            'service_name'      => 'Consulta',
            'duration_minutes'  => 30,
            'mode'              => 'presencial',
            'capacity'          => 1,
            'schedule'          => [
                [ 'weekday' => 1, 'time_from' => '09:00', 'time_to' => '17:00' ],
                [ 'weekday' => 2, 'time_from' => '09:00', 'time_to' => '17:00' ],
                [ 'weekday' => 3, 'time_from' => '09:00', 'time_to' => '17:00' ],
                [ 'weekday' => 4, 'time_from' => '09:00', 'time_to' => '17:00' ],
                [ 'weekday' => 5, 'time_from' => '09:00', 'time_to' => '17:00' ],
            ],
            'payment_mode'      => 'manual',
            'buffer_after'      => 5,
            'reminder_hours'    => 24,
            'cancel_min_hours'  => 24,
        ],
        'beauty'        => [
            'label'             => 'Belleza / Estetica',
            'icon'              => 'dashicons-admin-appearance',
            'description'       => 'Peluqueria, manicura, estetica, spa',
            'service_name'      => 'Hora de belleza',
            'duration_minutes'  => 60,
            'mode'              => 'presencial',
            'capacity'          => 1,
            'schedule'          => [
                [ 'weekday' => 1, 'time_from' => '10:00', 'time_to' => '19:00' ],
                [ 'weekday' => 2, 'time_from' => '10:00', 'time_to' => '19:00' ],
                [ 'weekday' => 3, 'time_from' => '10:00', 'time_to' => '19:00' ],
                [ 'weekday' => 4, 'time_from' => '10:00', 'time_to' => '19:00' ],
                [ 'weekday' => 5, 'time_from' => '10:00', 'time_to' => '19:00' ],
                [ 'weekday' => 6, 'time_from' => '10:00', 'time_to' => '14:00' ],
            ],
            'payment_mode'      => 'full',
            'buffer_after'      => 10,
            'reminder_hours'    => 2,
            'cancel_min_hours'  => 12,
        ],
        'education'     => [
            'label'             => 'Educacion / Clases',
            'icon'              => 'dashicons-welcome-learn-more',
            'description'       => 'Tutorias, clases particulares, talleres, capacitaciones',
            'service_name'      => 'Clase',
            'duration_minutes'  => 60,
            'mode'              => 'both',
            'capacity'          => 10,
            'schedule'          => [
                [ 'weekday' => 1, 'time_from' => '09:00', 'time_to' => '18:00' ],
                [ 'weekday' => 2, 'time_from' => '09:00', 'time_to' => '18:00' ],
                [ 'weekday' => 3, 'time_from' => '09:00', 'time_to' => '18:00' ],
                [ 'weekday' => 4, 'time_from' => '09:00', 'time_to' => '18:00' ],
                [ 'weekday' => 5, 'time_from' => '09:00', 'time_to' => '18:00' ],
            ],
            'payment_mode'      => 'full',
            'buffer_after'      => 0,
            'reminder_hours'    => 24,
            'cancel_min_hours'  => 48,
        ],
        'legal'         => [
            'label'             => 'Legal / Consultoria',
            'icon'              => 'dashicons-businessperson',
            'description'       => 'Abogados, contadores, asesores, consultores',
            'service_name'      => 'Consulta',
            'duration_minutes'  => 45,
            'mode'              => 'both',
            'capacity'          => 1,
            'schedule'          => [
                [ 'weekday' => 1, 'time_from' => '09:00', 'time_to' => '18:00' ],
                [ 'weekday' => 2, 'time_from' => '09:00', 'time_to' => '18:00' ],
                [ 'weekday' => 3, 'time_from' => '09:00', 'time_to' => '18:00' ],
                [ 'weekday' => 4, 'time_from' => '09:00', 'time_to' => '18:00' ],
                [ 'weekday' => 5, 'time_from' => '09:00', 'time_to' => '18:00' ],
            ],
            'payment_mode'      => 'manual',
            'buffer_after'      => 10,
            'reminder_hours'    => 24,
            'cancel_min_hours'  => 24,
        ],
        'fitness'       => [
            'label'             => 'Fitness / Deporte',
            'icon'              => 'dashicons-performance',
            'description'       => 'Personal trainer, yoga, pilates, CrossFit',
            'service_name'      => 'Sesion',
            'duration_minutes'  => 60,
            'mode'              => 'presencial',
            'capacity'          => 8,
            'schedule'          => [
                [ 'weekday' => 1, 'time_from' => '07:00', 'time_to' => '20:00' ],
                [ 'weekday' => 2, 'time_from' => '07:00', 'time_to' => '20:00' ],
                [ 'weekday' => 3, 'time_from' => '07:00', 'time_to' => '20:00' ],
                [ 'weekday' => 4, 'time_from' => '07:00', 'time_to' => '20:00' ],
                [ 'weekday' => 5, 'time_from' => '07:00', 'time_to' => '20:00' ],
                [ 'weekday' => 6, 'time_from' => '09:00', 'time_to' => '13:00' ],
            ],
            'payment_mode'      => 'full',
            'buffer_after'      => 0,
            'reminder_hours'    => 2,
            'cancel_min_hours'  => 12,
        ],
        'spaces'        => [
            'label'             => 'Espacios / Reservas',
            'icon'              => 'dashicons-building',
            'description'       => 'Salas de reunion, canchas, coworking, estudios',
            'service_name'      => 'Reserva de espacio',
            'duration_minutes'  => 60,
            'mode'              => 'presencial',
            'capacity'          => 20,
            'schedule'          => [
                [ 'weekday' => 1, 'time_from' => '08:00', 'time_to' => '20:00' ],
                [ 'weekday' => 2, 'time_from' => '08:00', 'time_to' => '20:00' ],
                [ 'weekday' => 3, 'time_from' => '08:00', 'time_to' => '20:00' ],
                [ 'weekday' => 4, 'time_from' => '08:00', 'time_to' => '20:00' ],
                [ 'weekday' => 5, 'time_from' => '08:00', 'time_to' => '20:00' ],
                [ 'weekday' => 6, 'time_from' => '09:00', 'time_to' => '20:00' ],
            ],
            'payment_mode'      => 'full',
            'buffer_after'      => 0,
            'reminder_hours'    => 24,
            'cancel_min_hours'  => 48,
        ],
        'generic'       => [
            'label'             => 'Otro / General',
            'icon'              => 'dashicons-calendar-alt',
            'description'       => 'Configuracion generica adaptable a cualquier tipo de agenda',
            'service_name'      => 'Cita',
            'duration_minutes'  => 60,
            'mode'              => 'presencial',
            'capacity'          => 1,
            'schedule'          => [
                [ 'weekday' => 1, 'time_from' => '09:00', 'time_to' => '18:00' ],
                [ 'weekday' => 2, 'time_from' => '09:00', 'time_to' => '18:00' ],
                [ 'weekday' => 3, 'time_from' => '09:00', 'time_to' => '18:00' ],
                [ 'weekday' => 4, 'time_from' => '09:00', 'time_to' => '18:00' ],
                [ 'weekday' => 5, 'time_from' => '09:00', 'time_to' => '18:00' ],
            ],
            'payment_mode'      => 'full',
            'buffer_after'      => 0,
            'reminder_hours'    => 24,
            'cancel_min_hours'  => 12,
        ],
    ];

    private const COUNTRY_DEFAULTS = [
        'CL' => [ 'currency' => 'CLP', 'timezone' => 'America/Santiago', 'language' => 'es' ],
        'CO' => [ 'currency' => 'COP', 'timezone' => 'America/Bogota',    'language' => 'es' ],
        'MX' => [ 'currency' => 'MXN', 'timezone' => 'America/Mexico_City', 'language' => 'es' ],
        'AR' => [ 'currency' => 'ARS', 'timezone' => 'America/Buenos_Aires', 'language' => 'es' ],
        'PE' => [ 'currency' => 'PEN', 'timezone' => 'America/Lima',     'language' => 'es' ],
        'BR' => [ 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo', 'language' => 'pt' ],
        'US' => [ 'currency' => 'USD', 'timezone' => 'America/New_York', 'language' => 'en' ],
        'ES' => [ 'currency' => 'EUR', 'timezone' => 'Europe/Madrid',    'language' => 'es' ],
    ];

    public function get_presets(): array {
        return self::PRESETS;
    }

    public function get_preset( string $key ): ?array {
        return self::PRESETS[ $key ] ?? null;
    }

    public function get_country_defaults( string $country ): ?array {
        return self::COUNTRY_DEFAULTS[ strtoupper( $country ) ] ?? null;
    }

    public function detect_country(): string {
        $locale = $this->locale_provider->get_user_locale();

        $locale_map = [
            'es_CL' => 'CL', 'es_MX' => 'MX', 'es_AR' => 'AR',
            'es_CO' => 'CO', 'es_PE' => 'PE', 'es_ES' => 'ES',
            'pt_BR' => 'BR', 'en_US' => 'US',
        ];

        if ( isset( $locale_map[ $locale ] ) ) {
            return $locale_map[ $locale ];
        }

        $lang = substr( $locale, 0, 2 );
        $lang_map = [ 'es' => 'CL', 'pt' => 'BR', 'en' => 'US' ];
        return $lang_map[ $lang ] ?? 'US';
    }

    public function get_readiness_checklist(): array {
        $items = [];

        $items[] = [
            'id'       => 'business_name',
            'label'    => 'Nombre del negocio',
            'done'     => (bool) $this->settings->get( Setting_Keys::BUSINESS_NAME, '' ),
            'section'  => 'general',
        ];

        $items[] = [
            'id'       => 'country',
            'label'    => 'Pais configurado',
            'done'     => (bool) $this->settings->get( Setting_Keys::BUSINESS_COUNTRY, '' ),
            'section'  => 'general',
        ];

        $items[] = [
            'id'       => 'timezone',
            'label'    => 'Zona horaria distinta de UTC',
            'done'     => $this->settings->get( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' ) !== 'UTC',
            'section'  => 'general',
        ];

        $active_services = $this->service_repo->find_all( [ 'status' => 'active' ] );
        $items[] = [
            'id'       => 'has_service',
            'label'    => 'Al menos un servicio activo',
            'done'     => ! empty( $active_services ),
            'section'  => 'catalog',
        ];

        $rules = $this->availability_repo->get_rules( 'global', 0, 'weekly' );
        $items[] = [
            'id'       => 'has_schedule',
            'label'    => 'Horario semanal definido',
            'done'     => ! empty( $rules ),
            'section'  => 'availability',
        ];

        $page_url = (string) $this->settings->get( Setting_Keys::PUBLIC_BOOKING_PAGE_URL, '' );
        $has_page = false;
        if ( $page_url ) {
            $has_page = true;
        } else {
            $pages = $this->page_query->find_published_pages_containing( '[openbooking]', 1 );
            $has_page = ! empty( $pages );
        }
        $items[] = [
            'id'       => 'has_booking_page',
            'label'    => 'Pagina de reservas publicada',
            'done'     => $has_page,
            'section'  => 'publish',
        ];

        $gateways = (array) $this->settings->get( Setting_Keys::ENABLED_GATEWAYS, [] );
        $has_manual = in_array( 'manual', $gateways, true );
        $has_online = false;
        foreach ( [ 'stripe', 'mercadopago' ] as $gw ) {
            if ( in_array( $gw, $gateways, true ) ) {
                if ( $gw === 'stripe' && $this->settings->get( Setting_Keys::STRIPE_PUBLISHABLE_KEY, '' ) ) {
                    $has_online = true;
                }
                if ( $gw === 'mercadopago' && $this->settings->get( Setting_Keys::MP_ACCESS_TOKEN, '' ) ) {
                    $has_online = true;
                }
            }
        }
        $items[] = [
            'id'       => 'payment_configured',
            'label'    => 'Metodo de pago configurado',
            'done'     => $has_manual || $has_online,
            'section'  => 'payment',
        ];

        $items[] = [
            'id'       => 'cron_running',
            'label'    => 'Cron activo y corriendo',
            'done'     => (bool) $this->settings->get( Option_Keys::CRON_HEARTBEAT_LAST, false ),
            'section'  => 'system',
        ];

        return $items;
    }

    public function apply_preset( string $preset_key, array $overrides = [] ): array {
        $preset = $this->get_preset( $preset_key );
        if ( ! $preset ) {
            return [ 'error' => 'Preset no encontrado.' ];
        }

        $result = [
            'preset'           => $preset_key,
            'service_name'     => $overrides['service_name'] ?? $preset['service_name'],
            'duration_minutes' => (int) ( $overrides['duration_minutes'] ?? $preset['duration_minutes'] ),
            'mode'             => $overrides['service_mode'] ?? $preset['mode'],
            'capacity'         => (int) ( $overrides['capacity'] ?? $preset['capacity'] ),
            'payment_mode'     => $overrides['payment_mode'] ?? $preset['payment_mode'],
            'schedule'         => $preset['schedule'],
            'buffer_after'     => $preset['buffer_after'],
            'reminder_hours'   => $preset['reminder_hours'],
            'cancel_min_hours' => $preset['cancel_min_hours'],
        ];

        return $result;
    }
}
