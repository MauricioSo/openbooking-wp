<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registra encabezados HTTP de seguridad para el frontend y admin.
 */
class Security_Headers {

    public static function register(): void {
        add_action( 'send_headers', [ self::class, 'send_security_headers' ] );
    }

    public static function send_security_headers(): void {
        if ( headers_sent() ) {
            return;
        }

        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );

        if ( is_admin() && self::is_openbooking_admin_page() ) {
            $csp = apply_filters( 'openbooking_admin_csp', self::build_csp() );
            header( 'Content-Security-Policy: ' . $csp );
        }
    }

    private static function build_csp(): string {
        $plugin_url = OBWP_PLUGIN_URL;
        $site_url   = site_url();
        $admin_url  = admin_url();
        $rest_url   = rest_url();
        $fonts_url  = 'https://fonts.googleapis.com https://fonts.gstatic.com';

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' {$site_url} {$admin_url}",
            "style-src 'self' 'unsafe-inline' {$plugin_url} {$fonts_url}",
            "img-src 'self' data: blob: {$site_url} {$plugin_url}",
            "font-src 'self' {$fonts_url}",
            "connect-src 'self' {$site_url} {$rest_url} https://api.stripe.com https://api.mercadopago.com https://webpay3g.transbank.cl https://webpay3gint.transbank.cl https://api.twilio.com https://graph.facebook.com",
            "frame-src 'self' https://js.stripe.com https://hooks.stripe.com",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        return implode( '; ', $directives );
    }

    /**
     * Detects whether the current request is an OpenBooking admin page.
     */
    private static function is_openbooking_admin_page(): bool {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        return $page !== '' && str_starts_with( $page, 'openbooking' );
    }
}
