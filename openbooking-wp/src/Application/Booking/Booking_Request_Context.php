<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class_alias(
    \OpenBooking\Application\Booking\Service\Booking_Request_Context::class,
    \OpenBooking\Application\Booking\Booking_Request_Context::class
);
