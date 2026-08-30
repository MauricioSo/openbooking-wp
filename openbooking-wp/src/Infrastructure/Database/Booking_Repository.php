<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }
class_alias( \OpenBooking\Infrastructure\Persistence\Booking\Booking_Repository::class, \OpenBooking\Infrastructure\Database\Booking_Repository::class );
