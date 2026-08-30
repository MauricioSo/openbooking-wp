<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Conecta OpenBooking con WooCommerce.
 */
class WooCommerce_Bridge {

    public static function is_active(): bool {
        return class_exists( 'WooCommerce' );
    }

    public static function init_hooks(): void {
        if ( ! self::is_active() ) {
            return;
        }
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_order_status_changed' ], 10, 3 );
        add_action( 'woocommerce_payment_complete',     [ __CLASS__, 'on_payment_complete' ], 10, 1 );
    }

    public static function on_order_status_changed( int $order_id, string $old_status, string $new_status ): void {
        self::sync_booking_status_from_order( $order_id );
    }

    public static function on_payment_complete( int $order_id ): void {
        self::sync_booking_status_from_order( $order_id );
    }

    public static function get_checkout_url( int $booking_id ): ?string {
        if ( ! self::is_active() ) {
            return null;
        }

        $product_id = self::create_or_get_product( $booking_id );
        if ( ! $product_id ) {
            return null;
        }

        return add_query_arg( 'add-to-cart', $product_id, wc_get_checkout_url() );
    }

    public static function create_or_get_product( int $booking_id ): ?int {
        if ( ! self::is_active() ) {
            return null;
        }

        $booking_repo = new \OpenBooking\Infrastructure\Persistence\Booking\Booking_Repository();
        $booking = $booking_repo->find( $booking_id );
        if ( ! $booking ) {
            return null;
        }

        $service_repo = new \OpenBooking\Infrastructure\Persistence\Catalog\Service_Repository();
        $service = $service_repo->find( $booking->service_id );
        $service_name = $service ? $service->name : 'Reserva #' . $booking->id;

        $existing = get_posts( [
            'post_type'   => 'product',
            'meta_key'    => '_obwp_booking_id',
            'meta_value'  => $booking->id,
            'numberposts' => 1,
            'post_status' => 'any',
        ] );

        if ( ! empty( $existing ) ) {
            return (int) $existing[0]->ID;
        }

        $product_id = wp_insert_post( [
            'post_title'  => sprintf( 'Reserva: %s — #%d', $service_name, $booking->id ),
            'post_type'   => 'product',
            'post_status' => 'publish',
            'meta_input'  => [
                '_obwp_booking_id' => $booking->id,
            ],
        ] );

        if ( is_wp_error( $product_id ) ) {
            return null;
        }

        wp_set_object_terms( $product_id, 'simple', 'product_type' );

        $price = self::minor_to_wc_price( $booking->price_total_minor );
        update_post_meta( $product_id, '_price', $price );
        update_post_meta( $product_id, '_regular_price', $price );
        update_post_meta( $product_id, '_virtual', 'yes' );
        update_post_meta( $product_id, '_sold_individually', 'yes' );

        return (int) $product_id;
    }

    /**
     * Converts minor-units integer to a WooCommerce price string.
     *
     * WooCommerce uses the currency's decimal precision to store prices.
     * Zero-decimal currencies (CLP, JPY, KRW, ISK, VUV, etc.) have 0 decimals,
     * so the minor amount is the final price. Others divide by 10^decimals.
     */
    private static function minor_to_wc_price( int $minor ): string {
        $decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

        if ( $decimals <= 0 ) {
            return (string) $minor;
        }

        return (string) round( $minor / pow( 10, $decimals ), $decimals );
    }

    public static function sync_booking_status_from_order( int $order_id ): void {
        if ( ! self::is_active() ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $booking_id = get_post_meta( $product_id, '_obwp_booking_id', true );
            if ( ! $booking_id ) {
                continue;
            }

            $booking_repo = new \OpenBooking\Infrastructure\Persistence\Booking\Booking_Repository();
            $booking = $booking_repo->find( (int) $booking_id );
            if ( ! $booking ) {
                continue;
            }

            $wc_status = $order->get_status();
            $lock_action = null;

            switch ( $wc_status ) {
                case 'completed':
                    $booking->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_COMPLETED;
                    $booking->payment_status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PAID;
                    $lock_action = 'confirm';
                    break;
                case 'processing':
                    $booking->payment_status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_PAID;
                    $lock_action = 'confirm';
                    break;
                case 'cancelled':
                    $booking->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CANCELLED_BY_CUSTOMER;
                    $lock_action = 'release';
                    break;
                case 'failed':
                    $booking->payment_status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_FAILED;
                    $lock_action = 'release';
                    break;
                case 'refunded':
                    $booking->status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CANCELLED_BY_CUSTOMER;
                    $booking->payment_status = \OpenBooking\Domain\Booking\Entity\Booking_Entity::PAYMENT_REFUNDED;
                    $lock_action = 'release';
                    break;
            }

            $booking_repo->update( $booking );
            self::sync_slot_lock( (int) $booking->id, $lock_action );
        }
    }

    private static function sync_slot_lock( int $booking_id, ?string $action ): void {
        if ( ! $action ) {
            return;
        }

        $locks = new \OpenBooking\Application\Availability\Service\Slot_Lock_Service(
            new \OpenBooking\Infrastructure\Persistence\Availability\Slot_Lock_Repository(
                new \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository()
            )
        );

        if ( 'confirm' === $action ) {
            $locks->confirm_for_booking( $booking_id );
            return;
        }

        if ( 'release' === $action ) {
            $locks->release_for_booking( $booking_id, 'woocommerce_' . $action );
        }
    }
}
