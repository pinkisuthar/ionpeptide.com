<?php

/**
 * Plugin Name: Inventory Management
 * Description: Inventory Management, Demand Forecasting, Automated Replenishment and Purchase Order Management for WooCommerce, all in one powerful tool.
 * Version: 2.8.2
 * Author: Shelf Planner
 * Author URI: https://shelfplanner.com
 * Text Domain: inventory-management-woocommerce
 * Domain Path: /languages
 * Woo: 18734002062098:e1a5d3c99dcce5f6f9b51fb523d0ac55
 *
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package extension
 */

defined('ABSPATH') || exit;

if (!defined('WP_REST_API_DISABLED')) {
	define('WP_REST_API_DISABLED', false);
}

if (!defined('SPC_WC__VERSION')) {
	define('SPC_WC__VERSION', '2.8.2');
}

if (!defined('SPC_WC__MAIN_PLUGIN_FILE')) {
	define('SPC_WC__MAIN_PLUGIN_FILE', __FILE__);
}

require_once plugin_dir_path(__FILE__) . 'includes/shelf_planner_config.php';
require_once plugin_dir_path(__FILE__) . 'includes/shelf_planner_setup.php';
require_once plugin_dir_path(__FILE__) . 'includes/shelf_planner_connector.php';

use ShelfPlanner\shelf_planner_config;
use ShelfPlanner\shelf_planner_setup;
use ShelfPlanner\shelf_planner_connector;

// phpcs:disable WordPress.Files.FileName

register_activation_hook(__FILE__, 'inventory_management_woocommerce_activate');

/**
 * Activation hook.
 *
 * @since 0.1.0
 */
function inventory_management_woocommerce_activate()
{
	if (!inventory_management_woocommerce_check_dependencies()) {
		inventory_management_woocommerce_deactivate_plugin();
		return;
	}

	$config = shelf_planner_config::instance();
	$sp_connector = shelf_planner_connector::instance($config);
	$sp_connector->activate();
}

function inventory_management_woocommerce_check_dependencies()
{
	$config = shelf_planner_config::instance();

	if (class_exists('shelf_planner')) {
		wp_die(esc_html__('Inventory Management could not be activated. Found Stock Management for Woocommerce plugin installed. Please uninstall it before using Inventory Management, thanks.', 'inventory-management-woocommerce'));
		return false;
	}

	if (!class_exists('WooCommerce')) {
		wp_die(esc_html__('Inventory Management could not be activated. Inventory Management requires WooCommerce to be installed and active. You can download <a href="https://woo.com/" target="_blank">WooCommerce</a> here.', 'inventory-management-woocommerce'));
		return false;
	}

	return true;
}

function inventory_management_woocommerce_deactivate_plugin()
{
	require_once(ABSPATH . 'wp-admin/includes/plugin.php');
	deactivate_plugins(plugin_basename(__FILE__));
	if (isset($_GET['activate'])) {
		unset($_GET['activate']);
	}
}

register_deactivation_hook(__FILE__, 'inventory_management_woocommerce_deactivate');

/**
 * Dectivation hook.
 *
 * @since 2.1.1
 */
function inventory_management_woocommerce_deactivate()
{
	$config = shelf_planner_config::instance();
	$sp_connector = shelf_planner_connector::instance($config);
	$sp_connector->deactivate();
}

function inventory_management_woocommerce_update_hook($upgrader_object, $options)
{
	// Check if the plugin was updated
	if ($options['action'] == 'update' && $options['type'] == 'plugin' && isset($options['plugins']) && is_array($options['plugins'])) {
		// Get the list of updated plugins
		$updated_plugins = $options['plugins'];

		// Check if our plugin is in the list of updated plugins
		if (in_array(plugin_basename(__FILE__), $updated_plugins)) {
			// Perform actions after the plugin is updated
			inventory_management_woocommerce_activate();
		}
	}
}

add_action('upgrader_process_complete', 'inventory_management_woocommerce_update_hook', 10, 2);

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'inventory_management_woocommerce_get_started');

function inventory_management_woocommerce_get_started($settings)
{
	$config = shelf_planner_config::instance();
	$sp_connector = shelf_planner_connector::instance($config);

	if ($sp_connector->is_activated()) {
		$settings[] = '<a href="' . get_admin_url(null, '/admin.php?page=wc-admin&path=/' . $config->get_handle()) . '">Dashboard</a>';
	} else {
		$settings[] = '<a href="' . get_admin_url(null, '/admin.php?page=wc-admin&path=/' . $config->get_handle()) . '" style="color:#F98AB1;font-weight:bold;">Get Started</a>';
	}

	return $settings;
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'inventory_management_woocommerce_documentation');

function inventory_management_woocommerce_documentation($settings)
{
	$settings[] = '<a href="https://www.shelfplanner.com/contact/" target="_blank">Documentation</a>';
	return $settings;
}

if (!class_exists('inventory_management_woocommerce')):
	/**
	 * The inventory_management_woocommerce class.
	 */
	class inventory_management_woocommerce
	{
		/**
		 * This class instance.
		 *
		 * @var \inventory_management_woocommerce single instance of this class.
		 */
		private static $instance;

		/**
		 * Constructor.
		 */
		public function __construct()
		{
			if (is_admin()) {
				$config = shelf_planner_config::instance();
				new shelf_planner_setup($config);
			}
		}

		/**
		 * Cloning is forbidden.
		 */
		public function __clone()
		{
			$config = shelf_planner_config::instance();
			wc_doing_it_wrong(__FUNCTION__, __('Cloning is forbidden.', 'inventory-management-woocommerce'), $config->get_version());
		}

		/**
		 * Unserializing instances of this class is forbidden.
		 */
		public function __wakeup()
		{
			$config = shelf_planner_config::instance();
			wc_doing_it_wrong(__FUNCTION__, __('Unserializing instances of this class is forbidden.', 'inventory-management-woocommerce'), $config->get_version());
		}

		/**
		 * Gets the main instance.
		 *
		 * Ensures only one instance can be loaded.
		 *
		 * @return \inventory_management_woocommerce
		 */
		public static function instance()
		{

			if (null === self::$instance) {
				self::$instance = new self();
			}

			return self::$instance;
		}
	}
endif;

add_action('plugins_loaded', 'inventory_management_woocommerce_init', 10);

/**
 * Initialize the plugin.
 *
 * @since 0.1.0
 */
function inventory_management_woocommerce_init()
{
	$config = shelf_planner_config::instance();

	if (!inventory_management_woocommerce_check_dependencies()) {
		inventory_management_woocommerce_deactivate_plugin();
		return;
	}

	inventory_management_woocommerce::instance($config);
	$connector = shelf_planner_connector::instance($config);

	if (!$connector->is_activated()) {
		add_action('admin_notices', 'inventory_management_woocommerce_display_setup_notice');
	}
}

function inventory_management_woocommerce_display_setup_notice()
{
	$config = shelf_planner_config::instance();
	$connector = shelf_planner_connector::instance($config);
	$setup_message = '<h3>Set up your Shelf Planner account to use the Shelf Planner + WooCommerce integration</h3>';
	$setup_message .= '<a class="button" role="button" href="' . $connector->get_setup_url() . '" target="_blank" rel="noreferrer" style="font-weight:bold;">Complete setup</a>';
	wp_admin_notice($setup_message, ['type' => 'warning', 'paragraph_wrap' => true]);
}
