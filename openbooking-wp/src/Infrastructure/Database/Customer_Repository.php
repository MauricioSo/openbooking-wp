<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }
class_alias( \OpenBooking\Infrastructure\Persistence\Customer\Customer_Repository::class, \OpenBooking\Infrastructure\Database\Customer_Repository::class );
