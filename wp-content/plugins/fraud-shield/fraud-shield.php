<?php
/**
 * Plugin Name: Fraud Shield for WooCommerce
 * Plugin URI:  https://example.com/fraud-shield
 * Description: Detects high-risk customers (shared addresses, shared email/phone, new-account velocity, repeat chargebacks) across your entire order history and at checkout. Includes a backwards full-database scan, a transparent risk-scoring engine, a blocklist, and optional enforcement (review-hold or hard-block).
 * Version:     1.32.0
 * Author:      Bones R&D
 * License:     GPL-2.0-or-later
 * Text Domain: fraud-shield
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

define( 'FS_VERSION', '1.32.0' );
define( 'FS_DB_VERSION', '23' );
define( 'FS_PLUGIN_FILE', __FILE__ );
define( 'FS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once FS_PLUGIN_DIR . 'includes/class-fs-normalizer.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-data.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-rules.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-blocklist.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-address-check.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-rewrites.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-scanner.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-importer.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-idv.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-cardholder.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-api-keys.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-webhooks.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-api.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-api-admin.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-live.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-insights.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-admin.php';
require_once FS_PLUGIN_DIR . 'includes/class-fs-core.php';

/**
 * Activation: build tables, register the custom order status, seed default settings.
 */
function fs_activate() {
	FS_Data::install();

	if ( false === get_option( 'fs_settings', false ) ) {
		add_option( 'fs_settings', FS_Core::default_settings() );
	}

	// Re-arm the webhook queue. Deactivation clears the schedule, and without
	// this a store that deactivated and reactivated would sit on its backlog
	// until the next event happened to schedule a run.
	if ( class_exists( 'FS_Webhooks' ) && FS_Webhooks::active_count() > 0 ) {
		FS_Webhooks::ensure_cron( true );
	}

	// Make sure the Charged Back status exists before any flush.
	FS_Core::register_order_status();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'fs_activate' );

/**
 * Deactivation: nothing destructive. Data + tables are preserved.
 *
 * The webhook queue's schedule is the one exception worth clearing: leaving a
 * recurring job behind for a plugin that is no longer running is how a site
 * ends up with cron entries nothing owns.
 */
function fs_deactivate() {
	if ( class_exists( 'FS_Webhooks' ) ) {
		FS_Webhooks::clear_cron();
	}
	// The geo backfill re-arms itself from install() on reactivation.
	wp_clear_scheduled_hook( FS_Data::CRON_GEO );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'fs_deactivate' );

/**
 * Load translations.
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'fraud-shield', false, dirname( plugin_basename( FS_PLUGIN_FILE ) ) . '/languages' );
	}
);

/**
 * Declare High-Performance Order Storage (HPOS) compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Boot. Requires WooCommerce.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . wp_kses(
						/* translators: %s: plugin name, wrapped in strong tags */
						sprintf( __( '%s requires WooCommerce to be installed and active.', 'fraud-shield' ), '<strong>Fraud Shield</strong>' ),
						array( 'strong' => array() )
					) . '</p></div>';
				}
			);
			return;
		}
		FS_Core::instance();

		// Apply schema upgrades on plugin update (adds new tables/columns via dbDelta).
		// Runs on the frontend too: v1.6 gate + auto-sync read the block-actions tables,
		// so the schema must be current before the first admin visit.
		if ( get_option( 'fs_db_version' ) !== FS_DB_VERSION ) {
			FS_Data::install();
		}
	}
);

/**
 * Convenience accessor.
 *
 * @return FS_Core
 */
function FS() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	return FS_Core::instance();
}
