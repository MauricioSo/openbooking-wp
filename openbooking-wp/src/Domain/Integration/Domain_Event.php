<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class_alias(
    \OpenBooking\Integration\Domain_Event::class,
    \OpenBooking\Domain\Integration\Domain_Event::class
);
