<?php
namespace ShelfPlanner;

defined('ABSPATH') || exit;

if (!class_exists('shelf_planner_config')):
	/**
	 * The shelf_planner_config class.
	 */
	class shelf_planner_config
	{
		// Static
		private $product_name = 'Inventory Management';
		private $domain = 'inventory_management_woocommerce';
		private $handle = 'inventory-management-woocommerce';
		private $platform = 2;

		// Computed
		private $api_baseuri = '';
		private $version = '';
		private $plugin_dir = '';
		private $main_plugin_file = '';
		private $log_path = '';
		private $environment = '';

		public function get_product_name()
		{
			return $this->product_name;
		}

		public function get_domain()
		{
			return $this->domain;
		}

		public function get_handle()
		{
			return $this->handle;
		}

		public function get_api_baseuri()
		{
			return $this->api_baseuri;
		}

		public function get_platform()
		{
			return $this->platform;
		}

		public function get_version()
		{
			return $this->version;
		}

		public function get_main_plugin_file()
		{
			return $this->main_plugin_file;
		}

		public function get_plugin_dir()
		{
			return $this->plugin_dir;
		}

		public function get_log_path()
		{
			return $this->log_path;
		}

		public function get_environment()
		{
			return $this->environment;
		}

		/**
		 * This class instance.
		 *
		 * @var \shelf_planner_config single instance of this class.
		 */
		private static $instance;

		/**
		 * Constructor.
		 */
		public function __construct()
		{
			$this->version = SPC_WC__VERSION;
			$this->main_plugin_file = SPC_WC__MAIN_PLUGIN_FILE;
			$this->plugin_dir = plugin_dir_path($this->main_plugin_file);
			$this->api_baseuri = "{$this->handle}/v1";

			// Read the JSON file
			$json = file_get_contents($this->plugin_dir . 'includes/settings.json');

			// Check if the file was read successfully
			if ($json === false) {
				die('Error reading the JSON file');
			}

			// Decode the JSON file
			$json_data = json_decode($json, true);

			// Check if the JSON was decoded successfully
			if ($json_data === null) {
				die('Error decoding the JSON file');
			}

			// Environment
			$this->environment = $json_data['ENV'];

			// LOG_PATH
			$log_path = $this->plugin_dir . 'logs/';
			if (!file_exists($log_path) || !is_dir($log_path)) {
				global $wp_filesystem;
				// create a file interaction object, if it is not already created
				if (! $wp_filesystem) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
					WP_Filesystem();
				}
				$wp_filesystem->mkdir($log_path);
			}
			$this->log_path = $log_path;
		}

		/**
		 * Cloning is forbidden.
		 */
		public function __clone()
		{
			wc_doing_it_wrong(__FUNCTION__, __('Cloning is forbidden.', 'inventory-management-woocommerce'), $this->version);
		}

		/**
		 * Unserializing instances of this class is forbidden.
		 */
		public function __wakeup()
		{
			wc_doing_it_wrong(__FUNCTION__, __('Unserializing instances of this class is forbidden.', 'inventory-management-woocommerce'), $this->version);
		}

		/**
		 * Gets the main instance.
		 *
		 * Ensures only one instance can be loaded.
		 *
		 * @return \shelf_planner_config
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
