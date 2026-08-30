<?php
declare( strict_types=1 );
namespace OpenBooking\Domain\Payment;
if ( ! defined( 'ABSPATH' ) ) { exit; }
interface GatewayResolverInterface extends \OpenBooking\Domain\Payment\Repository\GatewayResolverInterface {}
