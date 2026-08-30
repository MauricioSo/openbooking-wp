<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Shared\Port\SettingsInterface;
use OpenBooking\Support\Timezone_Helper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Exporta reservas a CSV con fechas locales del negocio.
 */
class Booking_Export_Service {


    public function __construct(
        private SettingsInterface $settings,
    ) {}

    public function export_csv( array $bookings, string $biz_tz_name, array $service_map, array $customer_map ): string {
        try {
            $biz_tz = new \DateTimeZone( $biz_tz_name );
        } catch ( \Exception $e ) {
            $biz_tz = new \DateTimeZone( 'UTC' );
        }

        $stream = fopen( 'php://temp', 'r+' );
        fputcsv( $stream, [ 'booking_id', 'fecha', 'hora', 'timezone', 'servicio', 'cliente', 'email', 'telefono', 'estado', 'estado_pago', 'moneda', 'precio_total', 'precio_pagado' ] );

        foreach ( $bookings as $booking ) {
            $service  = $service_map[ $booking->service_id ]   ?? null;
            $customer = $customer_map[ $booking->customer_id ] ?? null;

            $start_local_date = '';
            $start_local_time = '';
            if ( ! empty( $booking->start_at ) ) {
                try {
                    $dt_local = ( new \DateTimeImmutable( $booking->start_at, new \DateTimeZone( 'UTC' ) ) )
                        ->setTimezone( $biz_tz );
                    $start_local_date = $dt_local->format( 'Y-m-d' );
                    $start_local_time = $dt_local->format( 'H:i' );
                } catch ( \Exception $e ) {
                    $start_local_date = substr( (string) $booking->start_at, 0, 10 );
                    $start_local_time = substr( (string) $booking->start_at, 11, 5 );
                }
            }

            fputcsv( $stream, array_map( [ $this, 'safe_csv_cell' ], [
                $booking->id,
                $start_local_date,
                $start_local_time,
                $biz_tz_name,
                $service  ? $service->name               : '',
                $customer ? $customer->get_full_name()   : '',
                $customer ? $customer->email             : '',
                $customer ? (string) $customer->phone    : '',
                $booking->status,
                $booking->payment_status,
                $booking->currency,
                $booking->price_total_minor,
                $booking->price_paid_minor,
            ] ) );
        }

        rewind( $stream );
        $csv_body = (string) stream_get_contents( $stream );
        fclose( $stream );

        return $csv_body;
    }

    public function local_date_to_utc( string $date, string $edge = 'start' ): string {
        $tz_name = $this->settings->get( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' );
        return Timezone_Helper::local_date_to_utc( $date, $edge, $tz_name );
    }

    public function safe_csv_cell( $value ): string {
        $value = (string) $value;
        return preg_match( '/^[=+\-@]/', ltrim( $value ) ) ? "'" . $value : $value;
    }

    public function get_business_timezone(): string {
        return $this->settings->get( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' );
    }
}
