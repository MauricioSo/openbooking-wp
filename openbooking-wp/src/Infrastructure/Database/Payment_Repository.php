<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }
class_alias( \OpenBooking\Infrastructure\Persistence\Payment\Payment_Repository::class, \OpenBooking\Infrastructure\Database\Payment_Repository::class );
