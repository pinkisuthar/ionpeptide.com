<?php
/**
 * Plugin Name: Ion WooCommerce Compatibility Registry
 * Description: Declares WooCommerce feature compatibility for Ion/BRW custom plugins so HPOS and related features stay enabled after WooCommerce updates. See wp-content/ION-WOOCOMMERCE-CUSTOMIZATIONS.md for the full customization map.
 * Version: 1.0.0
 * Author: Intangible Treasures LLC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declare compatibility with WooCommerce features for plugins that own storefront behavior.
 *
 * @return void
 */
function ion_wc_compatibility_declarations() {
	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		return;
	}

	$plugin_files = array(
		WP_PLUGIN_DIR . '/ion-shop-redesign/ion-shop-redesign.php',
		WP_PLUGIN_DIR . '/ion-product-page-redesign/ion-product-page-redesign.php',
		WP_PLUGIN_DIR . '/ion-performance-guard/ion-performance-guard.php',
		WP_PLUGIN_DIR . '/ion-promo-control-v107/ion-promo-control.php',
		WPMU_PLUGIN_DIR . '/ion-prior-order-category-access-v2.2.0.php',
		WPMU_PLUGIN_DIR . '/brw-address-verifier3.php',
		WPMU_PLUGIN_DIR . '/brw-limit-cart-fragments.php',
		WPMU_PLUGIN_DIR . '/ion-shop-category-infinite-scroll-fix2.php',
	);

	foreach ( $plugin_files as $plugin_file ) {
		if ( ! is_readable( $plugin_file ) ) {
			continue;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $plugin_file, true );
	}
}

add_action( 'before_woocommerce_init', 'ion_wc_compatibility_declarations' );
