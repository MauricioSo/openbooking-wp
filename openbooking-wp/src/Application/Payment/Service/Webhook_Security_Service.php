<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Payment\Service;

use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Valida la procedencia de webhooks de pago mediante allowlists e IPs conocidas.
 */
class Webhook_Security_Service {


    public function __construct(
        private \OpenBooking\Domain\Shared\Port\SettingsInterface $settings,
    ) {}

    public function is_webhook_ip_allowed( string $gateway, string $remote ): bool {
        $custom_allowlist = $this->get_custom_allowlist( $gateway );
        if ( ! empty( $custom_allowlist ) ) {
            return $this->ip_matches_any( $remote, $custom_allowlist );
        }

        $known_provider_ips = $this->get_known_provider_ips( $gateway );
        if ( ! empty( $known_provider_ips ) ) {
            return $this->ip_matches_any( $remote, $known_provider_ips );
        }

        return true;
    }

    private function get_custom_allowlist( string $gateway ): array {
        $custom = (string) $this->settings->get( Setting_Keys::WEBHOOK_IP_ALLOWLIST_PREFIX . $gateway, '' );

        if ( '' === $custom ) {
            return [];
        }

        $allowed = array_map( 'trim', explode( "\n", $custom ) );

        return array_values(
            array_filter(
                $allowed,
                static function ( $ip ) {
                    return '' !== $ip && '#' !== $ip[0];
                }
            )
        );
    }

    public function ip_matches_any( string $remote, array $ranges ): bool {
        $remote_packed = inet_pton( $remote );
        if ( false === $remote_packed ) {
            return false;
        }

        foreach ( $ranges as $range ) {
            if ( false === strpos( $range, '/' ) ) {
                if ( $remote === $range ) return true;
                continue;
            }
            [ $subnet, $bits ] = explode( '/', $range, 2 );
            $subnet_packed = inet_pton( $subnet );
            if ( false === $subnet_packed ) continue;

            $bits = (int) $bits;
            $remote_len  = strlen( $remote_packed ) * 8;
            $subnet_len  = strlen( $subnet_packed ) * 8;
            if ( $bits > $remote_len || $bits > $subnet_len ) continue;

            $mask = str_repeat( "\xff", intdiv( $bits, 8 ) );
            $remainder = $bits % 8;
            if ( $remainder > 0 ) {
                $mask .= chr( 0xff << ( 8 - $remainder ) );
            }
            $mask = str_pad( $mask, strlen( $remote_packed ), "\x00" );

            if ( ( $remote_packed & $mask ) === ( $subnet_packed & $mask ) ) {
                return true;
            }
        }

        return false;
    }

    public function get_known_provider_ips( string $gateway ): array {
        switch ( $gateway ) {
            case 'stripe':
                return [
                    '3.18.12.0/23', '3.130.192.0/22', '13.235.8.0/21', '13.235.32.0/20',
                    '35.154.0.0/16', '52.15.0.0/16', '54.187.0.0/16', '54.241.0.0/16',
                ];
            case 'mercadopago':
                return [
                    '216.33.196.0/22', '67.228.235.0/24', '74.63.221.0/24',
                    '173.193.210.0/23', '173.193.212.0/23', '173.193.230.0/23',
                    '173.193.234.0/23', '158.85.110.0/23', '158.85.118.0/23',
                ];
            default:
                return [];
        }
    }
}
