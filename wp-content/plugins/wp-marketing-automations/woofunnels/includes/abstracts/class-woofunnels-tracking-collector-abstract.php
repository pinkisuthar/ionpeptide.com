<?php
/**
 * Abstract Tracking Collector Base Class (Deprecated)
 *
 * This class is deprecated. Use WooFunnels_Usage_Collector_Abstract instead.
 *
 * @package WooFunnels
 * @since 1.10.12.72
 * @deprecated Use WooFunnels_Usage_Collector_Abstract instead
 */

defined( 'ABSPATH' ) || exit;

// Load the new usage collector abstract class
if ( ! class_exists( 'WooFunnels_Usage_Collector_Abstract' ) ) {
	require_once __DIR__ . '/../class-woofunnels-usage-collector-abstract.php';
}

if ( ! class_exists( 'WooFunnels_Tracking_Collector_Abstract' ) ) {

	/**
	 * Abstract Class WooFunnels_Tracking_Collector_Abstract
	 * @deprecated Use WooFunnels_Usage_Collector_Abstract instead
	 */
	#[\AllowDynamicProperties]
	abstract class WooFunnels_Tracking_Collector_Abstract extends WooFunnels_Usage_Collector_Abstract {
		// All methods inherited from WooFunnels_Usage_Collector_Abstract
	}
}
