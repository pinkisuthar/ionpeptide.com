<?php
namespace ShelfPlanner;

/**
 * SPCWP shelf_planner_setup Class
 */
class shelf_planner_setup
{
	private $config;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct($config)
	{
		$this->config = $config;
		add_action('admin_enqueue_scripts', array($this, 'register_scripts'));
		add_action('admin_menu', array($this, 'register_page'));
	}

	/**
	 * Load all necessary dependencies.
	 *
	 * @since 1.0.0
	 */
	public function register_scripts()
	{
		if (
			!method_exists('Automattic\WooCommerce\Admin\PageController', 'is_admin_or_embed_page') ||
			!\Automattic\WooCommerce\Admin\PageController::is_admin_or_embed_page()
		) {
			return;
		}

		$script_path = '/build/index.js';
		$script_asset_path = dirname($this->config->get_main_plugin_file()) . '/build/index.asset.php';
		$script_asset = file_exists($script_asset_path)
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version' => filemtime($script_path),
			);
		$script_url = plugins_url($script_path, $this->config->get_main_plugin_file());

		wp_register_script(
			$this->config->get_handle(),
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		wp_register_style(
			$this->config->get_handle(),
			plugins_url('/build/index.css', $this->config->get_main_plugin_file()),
			// Add any dependencies styles may have, such as wp-components.
			array(),
			filemtime(dirname($this->config->get_main_plugin_file()) . '/build/index.css')
		);

		wp_enqueue_script($this->config->get_handle());
		wp_enqueue_style($this->config->get_handle());
	}

	/**
	 * Register page in wc-admin.
	 *
	 * @since 1.0.0
	 */
	public function register_page()
	{
		if (!function_exists('wc_admin_register_page')) {
			return;
		}

		wc_admin_register_page(
			array(
				'id' => $this->config->get_domain() . '-dashboard',
				'title' => __('Inventory', 'inventory-management-woocommerce'),
				'parent' => 'woocommerce',
				'path' => '/' . $this->config->get_handle(),
			)
		);
	}
}
