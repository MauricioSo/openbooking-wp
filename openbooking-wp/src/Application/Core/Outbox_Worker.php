<?php
declare( strict_types=1 );
namespace OpenBooking\Application\Core;
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Outbox_Worker extends \OpenBooking\Application\Core\Service\Outbox_Worker {}
