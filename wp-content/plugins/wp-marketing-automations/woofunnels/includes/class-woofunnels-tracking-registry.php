<?php
/**
 * Tracking Registry Class (Deprecated)
 *
 * This class is deprecated. Use WooFunnels_Usage_Registry instead.
 *
 * @package WooFunnels
 * @since 1.10.12.72
 * @deprecated Use WooFunnels_Usage_Registry instead
 */

defined( 'ABSPATH' ) || exit;

// Load the new usage registry class
if ( ! class_exists( 'WooFunnels_Usage_Registry' ) ) {
	require_once __DIR__ . '/class-woofunnels-usage-registry.php';
}

if ( ! class_exists( 'WooFunnels_Tracking_Registry' ) ) {

	/**
	 * Class WooFunnels_Tracking_Registry
	 * @deprecated Use WooFunnels_Usage_Registry instead
	 */
	#[\AllowDynamicProperties]
	class WooFunnels_Tracking_Registry extends WooFunnels_Usage_Registry {
		// All methods inherited from WooFunnels_Usage_Registry
	}
}
