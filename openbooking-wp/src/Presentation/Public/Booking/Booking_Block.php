<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Public\Booking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registra el bloque Gutenberg del formulario publico.
 */
class Booking_Block {

    public function __construct() {
        add_action( 'init', [ $this, 'register_block' ] );
    }

    public function register_block(): void {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        wp_register_script(
            'obwp-booking-block-editor',
            OBWP_PLUGIN_URL . 'blocks/booking-form/index.js',
            [ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ],
            OBWP_VERSION,
            true
        );

        register_block_type(
            OBWP_PLUGIN_DIR . 'blocks/booking-form',
            [
                'editor_script'   => 'obwp-booking-block-editor',
                'render_callback' => [ $this, 'render' ],
            ]
        );
    }

    public function render( array $attributes = [] ): string {
        Booking_Shortcode::enqueue_public_assets();

        return Booking_Shortcode::render_form( [
            'service' => isset( $attributes['service'] ) ? (string) $attributes['service'] : '',
            'layout'  => isset( $attributes['layout'] ) ? (string) $attributes['layout'] : 'steps',
            'preset'  => isset( $attributes['preset'] ) ? (string) $attributes['preset'] : '',
        ] );
    }
}
