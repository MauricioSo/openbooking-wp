<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resuelve la URL publica de reservas con un fallback seguro.
 */
class Public_Booking_Page {

    public static function get_url( array $args = [] ): string {
        $url = trim( (string) get_option( Setting_Keys::PUBLIC_BOOKING_PAGE_URL, '' ) );

        if ( $url ) {
            $url_host  = (string) parse_url( $url, PHP_URL_HOST );
            $site_host = (string) parse_url( home_url( '/' ), PHP_URL_HOST );
            if ( ! filter_var( parse_url( $url, PHP_URL_SCHEME ) ) || ( $url_host && $url_host !== $site_host ) ) {
                $url = '';
            }
        }

        if ( ! $url ) {
            $url = self::detect_shortcode_page_url();
        }

        if ( ! $url ) {
            $url = home_url( '/' );
        }

        $url = apply_filters( 'openbooking_public_booking_page_url', $url, $args );

        if ( ! empty( $args ) ) {
            $url = add_query_arg( $args, $url );
        }

        return $url;
    }

    private static function detect_shortcode_page_url(): string {
        $pages = get_posts( [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'ASC',
            'order'          => 'ASC',
        ] );

        foreach ( $pages as $page ) {
            if ( $page instanceof \WP_Post && has_shortcode( $page->post_content, 'openbooking' ) ) {
                return get_permalink( $page );
            }
        }

        return '';
    }
}
