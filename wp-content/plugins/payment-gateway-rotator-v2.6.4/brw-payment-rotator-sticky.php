<?php
/**
 * Plugin Name: BRW Payment Rotator (Sticky Per Customer)
 * Plugin URI: https://buildrightweb.com
 * Description: Sticky WooCommerce payment routing with fixed gateways, weighted rotation, secondary gateway support, gateway discounts, and license validation.
 * Version: 2.7.12
 * Author: BuildRightWeb
 * Author URI: https://buildrightweb.com
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BRW_PR_VERSION', '2.7.12' );
define( 'BRW_PR_FILE', __FILE__ );
define( 'BRW_PR_DIR', plugin_dir_path( __FILE__ ) );
define( 'BRW_PR_URL', plugin_dir_url( __FILE__ ) );

require_once BRW_PR_DIR . 'includes/class-brw-pr-license.php';
require_once BRW_PR_DIR . 'includes/class-brw-pr-admin.php';
require_once BRW_PR_DIR . 'includes/class-brw-pr-router.php';

final class BRW_Payment_Rotator_Sticky {

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'bootstrap' ), 20 );
	}

	public static function bootstrap() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		BRW_PR_Admin::init();

		/*
		 * Keep the storefront router live even when the license check is stale, unreachable,
		 * or not configured yet. The admin screen still shows the license warning, but the
		 * checkout must not silently lose routing, stats, discounts, or payment-type prompts.
		 */
		BRW_PR_Router::init();
	}
}

BRW_Payment_Rotator_Sticky::init();