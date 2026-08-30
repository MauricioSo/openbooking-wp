<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Inserta el texto de privacidad del plugin en WordPress.
 */
class Privacy_Policy_Guide {

    public function __construct() {
        add_action( 'admin_init', [ $this, 'register' ] );
    }

    public function register(): void {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        $content = sprintf(
            '<p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li><li>%s</li></ul><p>%s</p>',
            esc_html__( 'OpenBooking WP stores personal data when a customer creates or manages a booking.', 'openbooking-wp' ),
            esc_html__( 'Full name', 'openbooking-wp' ),
            esc_html__( 'Email address', 'openbooking-wp' ),
            esc_html__( 'Phone number (optional)', 'openbooking-wp' ),
            esc_html__( 'Booking details and customer notes associated with the reservation', 'openbooking-wp' ),
            esc_html__( 'This data is used to manage appointments, send notifications, and keep an administrative booking history.', 'openbooking-wp' )
        );

        wp_add_privacy_policy_content( 'OpenBooking WP', wp_kses_post( $content ) );
    }
}
