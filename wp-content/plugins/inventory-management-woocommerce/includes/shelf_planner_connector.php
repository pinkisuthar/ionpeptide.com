<?php

namespace ShelfPlanner;

defined('ABSPATH') || exit;

if (!class_exists('shelf_planner_connector')):
	/**
	 * SPCWP shelf_planner_connector Class
	 */
	class shelf_planner_connector
	{
		private $config;

		/**
		 * This class instance.
		 *
		 * @var \shelf_planner_connector single instance of this class.
		 */
		private static $instance;

		/**
		 * Constructor.
		 *
		 * @since 1.0.0
		 */
		public function __construct($config)
		{
			// Set config
			$this->config = $config;

			$this->init();
		}

		/**
		 * Cloning is forbidden.
		 */
		public function __clone()
		{
			wc_doing_it_wrong(__FUNCTION__, __('Cloning is forbidden.', 'inventory-management-woocommerce'), $this->config->get_version());
		}

		/**
		 * Unserializing instances of this class is forbidden.
		 */
		public function __wakeup()
		{
			wc_doing_it_wrong(__FUNCTION__, __('Unserializing instances of this class is forbidden.', 'inventory-management-woocommerce'), $this->config->get_version());
		}

		/**
		 * Gets the main instance.
		 *
		 * Ensures only one instance can be loaded.
		 *
		 * @return \shelf_planner_connector
		 */
		public static function instance($config)
		{

			if (null === self::$instance) {
				self::$instance = new self($config);
			}

			return self::$instance;
		}

		public function init()
		{
			$this->init_settings();
			$this->setup();

			if ($this->setup_done()) {
				$this->register_rest_routes();
			}

			if ($this->is_activated()) {
				$this->wcml_check();
				$this->register_live_sync_actions();
			}
		}

		function get_plugin_info()
		{
			$errorInfo = get_option($this->config->get_domain());

			return [
				'is_debug' => $this->is_debug(),
				'is_active' => $this->is_activated(),
				'server_key' => $this->get_server_key(),
				'license_key' => $this->get_license_key(),
				'error_code' => $errorInfo->error_code,
				'error_message' => $errorInfo->error_message,
				'error_description' => $errorInfo->error_description,
				'app_url' => $this->get_app_url(),
				'setup_url' => $this->get_setup_url(),
				'has_wcml' => get_option($this->config->get_domain() . '_wcml_is_active')
			];
		}

		function init_settings()
		{
			$default = array(
				'is_debug' => $this->is_debug(),
				'is_active' => $this->is_activated(),
				'error_code' => 0,
				'error_message' => '',
				'error_description' => ''
			);
			$schema = array(
				'type' => 'object',
				'properties' => array(
					'is_debug' => array(
						'type' => 'boolean',
					),
					'is_active' => array(
						'type' => 'boolean',
					),
					'error_code' => array(
						'type' => 'integer',
					),
					'error_message' => array(
						'type' => 'string',
					),
					'error_description' => array(
						'type' => 'string',
					),
				),
			);

			register_setting(
				'options',
				$this->config->get_domain(),
				array(
					'type' => 'object',
					'default' => $default,
					'show_in_rest' => array(
						'schema' => $schema,
					),
				)
			);

			add_action('rest_api_init', function () {
				register_rest_route(
					$this->config->get_api_baseuri(),
					'/plugin/info',
					array(
						'methods' => 'GET',
						'callback' => [$this, 'get_plugin_info'],
						'permission_callback' => 'is_user_logged_in',
					)
				);
			});
		}

		public function setup_done()
		{
			// return true; // TEST
			// return false; // TEST
			$installed_version = $this->get_installed_plugin_version();
			$current_version = $this->config->get_version();
			return ($installed_version === $current_version) && $this->has_server_key();
		}

		public function is_activated()
		{
			// return true; // TEST
			// return false; // TEST
			return $this->has_server_key() && $this->has_license_key();
		}

		public function is_debug()
		{
			return 'DEV' == $this->config->get_environment() || 'LOCALDEV' == $this->config->get_environment();
		}

		public function setup()
		{
			if (!$this->setup_done()) {
				$this->activate();
			}
		}

		public function register_rest_routes()
		{
			add_action('rest_api_init', function () {
				register_rest_route(
					$this->config->get_api_baseuri(),
					'/store/info',
					array(
						'methods' => 'POST',
						'callback' => [$this, 'get_store_info_request'],
						'permission_callback' => [$this, 'validate_request_auth'],
					)
				);

				register_rest_route(
					$this->config->get_api_baseuri(),
					'/setup',
					array(
						'methods' => 'POST',
						'callback' => [$this, 'setup_connector'],
						'permission_callback' => [$this, 'validate_request_auth_website_url_server_key'],
					)
				);

				register_rest_route(
					$this->config->get_api_baseuri(),
					'/sync/categories/',
					array(
						'methods' => 'POST',
						'callback' => [$this, 'get_categories_list'],
						'permission_callback' => [$this, 'validate_request_auth'],
					)
				);

				register_rest_route(
					$this->config->get_api_baseuri(),
					'/sync/products/init',
					array(
						'methods' => 'POST',
						'callback' => [$this, 'get_products_id'],
						'permission_callback' => [$this, 'validate_request_auth'],
					)
				);

				register_rest_route(
					$this->config->get_api_baseuri(),
					'/sync/products/detail',
					array(
						'methods' => 'POST',
						'callback' => [$this, 'get_products_detail'],
						'permission_callback' => [$this, 'validate_request_auth'],
					)
				);

				register_rest_route(
					$this->config->get_api_baseuri(),
					'/sync/orders/init',
					array(
						'methods' => 'POST',
						'callback' => [$this, 'get_orders_id'],
						'permission_callback' => [$this, 'validate_request_auth'],
					)
				);

				register_rest_route(
					$this->config->get_api_baseuri(),
					'/sync/orders/detail',
					array(
						'methods' => 'POST',
						'callback' => [$this, 'get_orders_detail'],
						'permission_callback' => [$this, 'validate_request_auth'],
						'args' => array(
							'IDS' => array(
								'required' => true,
							),
						),
					)
				);

				register_rest_route(
					$this->config->get_api_baseuri(),
					'/products/stock/add',
					array(
						'methods' => ['PUT', 'POST'],
						'callback' => [$this, 'add_product_stock'],
						'permission_callback' => [$this, 'validate_request_auth'],
						'args' => array(
							'ProductId' => array(
								'required' => true,
							),
							'Stock' => array(
								'required' => true,
							),
						),
					)
				);

				register_rest_route(
					$this->config->get_api_baseuri(),
					'/products/stock/update',
					array(
						'methods' => ['PUT', 'POST'],
						'callback' => [$this, 'update_product_stock'],
						'permission_callback' => [$this, 'validate_request_auth'],
						'args' => array(
							'ProductId' => array(
								'required' => true,
							),
							'Stock' => array(
								'required' => true,
							),
						),
					)
				);

				register_rest_route(
					$this->config->get_api_baseuri(),
					'/products/stock/track/update',
					array(
						'methods' => ['PUT', 'POST'],
						'callback' => [$this, 'update_products_track_stock'],
						'permission_callback' => [$this, 'validate_request_auth'],
					)
				);

				register_rest_route(
					$this->config->get_api_baseuri(),
					'/ping',
					array(
						'methods' => 'POST',
						'callback' => [$this, 'ping'],
						'permission_callback' => [$this, 'validate_request_auth_website_url'],
					)
				);

				register_rest_route(
					$this->config->get_api_baseuri(),
					'/logs',
					array(
						'methods' => 'POST',
						'callback' => [$this, 'get_logs'],
						'permission_callback' => [$this, 'validate_request_auth'],
					)
				);
			});
		}

		public function register_live_sync_actions()
		{
			add_action('woocommerce_update_product', [$this, 'connector_product_update'], 10, 2);
			add_action('woocommerce_order_status_completed', [$this, 'connector_order_completed']);
		}

		// Connector Fx
		function add_product_stock($data)
		{
			$this->debug("add_product_stock call parameters:" . json_encode($data->get_json_params()));
			$product = wc_get_product($data['ProductId']);
			if (!$product) {
				return null;
			}

			$current_stock = $product->get_stock_quantity();
			$updated_stock = $current_stock + $data['Stock'];
			$product->set_stock_quantity($updated_stock);
			$product->save();

			return $product->get_stock_quantity();
		}

		function update_product_stock($data)
		{
			$this->debug("update_product_stock call parameters:" . json_encode($data->get_json_params()));
			$product = wc_get_product($data['ProductId']);
			if (!$product) {
				return null;
			}
			$product->set_stock_quantity($data['Stock']);
			$product->save();

			return $product->get_stock_quantity();
		}

		function update_products_track_stock($data)
		{
			$this->debug("update_products_track_stock call parameters:" . json_encode($data->get_json_params()));
			foreach ($data['ProductsToUpdateTrackStock'] as $key => $value) {
				$this->debug("update_products_track_stock item: " . $key . ' :: ' . (true == $value ? 'true' : 'false'));
				$product = wc_get_product($key);
				if (!$product) {
					return null;
				}
				$product->set_manage_stock($value);
				$product->save();
			}
		}

		function get_categories_list($data)
		{
			// Validate request auth
			$this->debug("get_categories_list call parameters:" . json_encode($data->get_json_params()));
			$taxonomy = 'product_cat';
			$orderby = 'name';
			$show_count = 0;      // 1 for yes, 0 for no
			$pad_counts = 0;      // 1 for yes, 0 for no
			$hierarchical = 1;      // 1 for yes, 0 for no
			$title = '';
			$empty = 0;

			$args = array(
				'taxonomy' => $taxonomy,
				'orderby' => $orderby,
				'show_count' => $show_count,
				'pad_counts' => $pad_counts,
				'hierarchical' => $hierarchical,
				'title_li' => $title,
				'hide_empty' => $empty
			);
			$all_categories = get_categories($args);
			$categories = [];

			foreach ($all_categories as $category) {
				$catArray = $category->to_array();
				$category_id = '' . $catArray['term_id'];

				$tmpCategory = [];
				$tmpCategory['Success'] = true;
				$tmpCategory['Message'] = '';

				$tmpCategory['Id'] = $category_id;

				try {
					$tmpCategory['Title'] = $catArray['name'];
					$tmpCategory['Pid'] = '' . $catArray['parent'];

					if ($this->is_wcml_active()) {
						// Remove translated categories
						$original_category_id = $this->get_original_category_id($category_id);
						if ($original_category_id !== $category_id) {
							continue;
						}
					}
				} catch (\Throwable $th) {
					$tmpCategory['Success'] = false;
					$tmpCategory['Message'] = $th->getMessage();
				}

				$categories[] = $tmpCategory;
			}

			return $categories;
		}


		function get_products_id($data)
		{
			// Validate request auth
			$this->debug("get_products_id call parameters:" . json_encode($data->get_json_params()));
			global $wpdb;

			$datab = $wpdb->get_results(
				$wpdb->prepare(
					"
        SELECT ID
        FROM {$wpdb->posts} AS posts
        WHERE posts.post_type IN ('product', 'product_variation')
		AND post_modified >= %s
		order by post_modified",
					$data['LastUpdateFrom']
				)
			);

			$all_product_ids = array_map('strval', array_column($datab, 'ID'));

			if ($this->is_wcml_active()) {
				$original_product_ids = [];
				foreach ($all_product_ids as $product_id) {
					// Remove translated products
					$original_product_id = $this->get_original_product_id($product_id);
					if ($original_product_id !== $product_id) {
						continue;
					}

					$original_product_ids[] = $product_id;
				}
				$all_product_ids = $original_product_ids;
			}

			return $all_product_ids;
		}

		function get_orders_id($data)
		{
			// Validate request auth
			$this->debug("get_orders_id call parameters:" . json_encode($data->get_json_params()));
			global $wpdb;

			$order_type_column = "post_type";
			$order_status_column = "post_status";
			$order_date_column = "post_modified";
			$orders_table = $wpdb->prefix . "posts";

			// woocommerce_custom_orders_table_enabled == 'yes' : hpos enabled (new wc_orders table)
			// woocommerce_custom_orders_table_enabled == 'no' : hpos disabled (legacy posts table)
			if ('yes' == get_option('woocommerce_custom_orders_table_enabled')) {
				$orders_table = $wpdb->prefix . "wc_orders";
				$order_type_column = "type";
				$order_status_column = "status";
				$order_date_column = "date_created_gmt";
			}

			// 	$order_query =
			// 	"
			//  SELECT ID
			//  FROM {$orders_table}
			//  WHERE {$order_type_column} = 'shop_order'
			//  AND {$order_date_column} >= %s
			//  AND {$order_status_column} IN ( 'wc-completed' ) order by {$order_date_column}";

			$data = $wpdb->get_results(
				$wpdb->prepare(
					"
			 SELECT ID
			 FROM %i
			 WHERE %i = 'shop_order'
			 AND %i >= %s
			 AND %i IN ( 'wc-completed' ) order by %i",
					$orders_table,
					$order_type_column,
					$order_date_column,
					$data['LastUpdateFrom'],
					$order_status_column,
					$order_date_column,
				)
			);

			return (array_map('strval', array_column($data, 'ID')));
		}

		function get_orders_detail($data)
		{
			$this->debug("get_orders_detail call parameters:" . json_encode($data->get_json_params()));
			$params = $data->get_params(); // Ottieni i parametri passati nella richiesta POST
			$tmpOrders = [];
			foreach ($params['IDS'] as $order_id) {
				$tmpOrders[] = $this->map_order($order_id);
			}

			return $tmpOrders;
		}

		public function map_order($order_id)
		{
			$tmpOrder = [];
			$tmpOrder['Id'] = '' . $order_id;
			$tmpOrder['Success'] = true;
			$tmpOrder['Message'] = '';

			try {
				// https://wp-kama.com/plugin/woocommerce/function/wc_get_order
				$order = wc_get_order($order_id);
				$data = $order->get_data(); // order data
				$tmpOrder['DateCreated'] = $data['date_created']->getTimestamp();
				$tmpOrder['DateModified'] = $data['date_modified']->getTimestamp();
				$tmpOrder['CustomerId'] = '' . $data['customer_id'];
				$tmpOrder['BillingCity'] = $data['billing']['city'];
				$tmpOrder['BillingCountry'] = $data['billing']['country'];
				$tmpOrder['ShippingCity'] = $data['shipping']['city'];
				$tmpOrder['ShippingCountry'] = $data['shipping']['country'];
				$tmpOrder['CartTax'] = $order->get_cart_tax();
				$tmpOrder['Currency'] = $order->get_currency();
				$tmpOrder['DiscountTax'] = $order->get_discount_tax();
				$tmpOrder['DiscountTotal'] = $order->get_discount_total();
				$tmpOrder['TotalFees'] = $order->get_total_fees();
				$tmpOrder['ShippingTax'] = $order->get_shipping_tax();
				$tmpOrder['ShippingTotal'] = $order->get_shipping_total();
				$tmpOrder['Subtotal'] = $order->get_subtotal();
				$tmpOrder['TaxTotals'] = $order->get_tax_totals();
				$tmpOrder['Taxes'] = $order->get_taxes();
				$tmpOrder['Total'] = $order->get_total();
				$tmpOrder['TotalDiscount'] = $order->get_total_discount();
				$tmpOrder['TotalTax'] = $order->get_total_tax();
				$tmpOrder['TotalRefunded'] = $order->get_total_refunded();
				$tmpOrder['TotalTaxRefunded'] = $order->get_total_tax_refunded();
				$tmpOrder['TotalShippingRefunded'] = $order->get_total_shipping_refunded();
				$tmpOrder['Items'] = [];

				foreach ($order->get_items() as $item_id => $item) {
					$product_id = '' . $item->get_product_id();
					$variation_id = '' . $item->get_variation_id();

					if ($this->is_wcml_active()) {
						$requested_product_id = $product_id;
						if (0 === $item->get_variation_id()) {
							// Product master
							$requested_product_id = $product_id;
							$original_product_id = $this->get_original_product_id($requested_product_id);
							if ($requested_product_id !== $original_product_id) {
								$product = wc_get_product($original_product_id);
								$product_id = '' . $product->get_id();
								$variation_id = '';
							}
						} else {
							// Product variation
							$requested_product_id = $variation_id;
							$original_product_id = $this->get_original_product_id($requested_product_id);
							if ($requested_product_id !== $original_product_id) {
								$product = wc_get_product($original_product_id);
								$product_id = '' . $product->get_parent_id();
								$variation_id = '' . $product->get_id();
							}
						}
					}

					$tmpOrderItem = [];
					$tmpOrderItem["ProductId"] = $product_id;
					$tmpOrderItem["VariationId"] = $variation_id;
					$tmpOrderItem["ProductName"] = $item->get_name();
					$tmpOrderItem["Quantity"] = '' . $item->get_quantity();
					$tmpOrderItem["Subtotal"] = $item->get_subtotal();
					$tmpOrderItem["Total"] = $item->get_total();
					$tmpOrderItem["Tax"] = $item->get_subtotal_tax();
					$tmpOrderItem["TaxClass"] = $item->get_tax_class();
					$tmpOrderItem["TaxStatus"] = $item->get_tax_status();
					$tmpOrderItem["ItemType"] = $item->get_type(); // e.g. "line_item", "fee"
					$tmpOrder['Items'][] = $tmpOrderItem;
				}
			} catch (\Throwable $th) {
				$tmpOrder['Success'] = false;
				$tmpOrder['Message'] = $th->getMessage();
			}

			return $tmpOrder;
		}

		function get_products_detail($data)
		{
			$this->debug("get_products_detail call parameters:" . json_encode($data->get_json_params()));
			$ar_return = [];
			foreach ($data['IDS'] as $product_id) {
				$ar_return[] = $this->map_product($product_id);
			}
			return $ar_return;
		}

		public function map_product($product_id)
		{
			$tmpProduct = [];
			$tmpProduct['Id'] = '' . $product_id;
			$tmpProduct['Success'] = true;
			$tmpProduct['Message'] = '';

			try {
				$product = wc_get_product($product_id);
				$tmpProduct['Title'] = $product->get_name();
				$tmpProduct['Status'] = $product->get_status();
				$tmpProduct['Type'] = $product->get_type();
				$tmpProduct['Categories'] = array_map('strval', $product->get_category_ids());
				$tmpProduct['DateCreated'] = $product->get_date_created();
				$tmpProduct['DateModified'] = $product->get_date_modified();
				$tmpProduct['DateCreatedTimestamp'] = $product->get_date_created()->getTimestamp();
				$tmpProduct['DateModifiedTimestamp'] = $product->get_date_modified()->getTimestamp();
				$tmpProduct['SKU'] = $product->get_sku();
				$tmpProduct['Price'] = $product->get_price();
				$tmpProduct['RegularPrice'] = $product->get_regular_price();
				$tmpProduct['SalePrice'] = $product->get_sale_price();
				$tmpProduct['TotalSales'] = '' . $product->get_total_sales();
				$tmpProduct['TaxStatus'] = $product->get_tax_status();
				$tmpProduct['TaxClass'] = $product->get_tax_class();
				$tmpProduct['ManageStock'] = $product->get_manage_stock();
				$tmpProduct['StockQuantity'] = $product->get_stock_quantity();
				$tmpProduct['StockStatus'] = $product->get_stock_status();
				$tmpProduct['Backorders'] = $product->get_backorders();
				$tmpProduct['Pid'] = '' . $product->get_parent_id();
				$tmpProduct['Childs'] = array_map('strval', $product->get_children());
				$tmpProduct['ThumbUri'] = null;
				$thumb = wp_get_attachment_image_src(get_post_thumbnail_id($product->get_id()), 'single-post-thumbnail');
				if ($thumb && count($thumb) > 0) {
					$tmpProduct['ThumbUri'] = $thumb[0];
				}
			} catch (\Throwable $th) {
				$tmpProduct['Success'] = false;
				$tmpProduct['Message'] = $th->getMessage();
			}

			return $tmpProduct;
		}

		public function get_store_info_request($data)
		{
			$sp_payload = $this->get_store_info();
			return $sp_payload;
		}

		function ping($data)
		{
			return "pong";
		}

		function validate_request_auth_website_url($data)
		{
			// Verify WebsiteUrl validity
			if ($this->get_website_url() != $data['WebsiteUrl']) {
				$this->warning("ERROR SITE: doesn't match ");
				return false;
			}

			return true;
		}

		function validate_request_auth_website_url_server_key($data)
		{
			// Verify WebsiteUrl validity
			if (!$this->validate_request_auth_website_url($data)) {
				return false;
			}

			// Verify ServerKey validity
			if ($this->get_server_key() != $data['ServerKey']) {
				$this->warning("ERROR SERVER: doesn't match ");
				return false;
			}

			return true;
		}

		function validate_request_auth($data)
		{
			// Verify WebsiteUrl and ServerKey validity
			if (!$this->validate_request_auth_website_url_server_key($data)) {
				return false;
			}

			// Verify LicenseKey validity
			if ($this->get_license_key() != $data['LicenseKey']) {
				$this->warning("ERROR LICENSE doesn't match ");
				return false;
			}

			return true;
		}

		function setup_connector($data)
		{
			// Set licenseKey
			$this->set_license_key($data['LicenseKey']);
		}

		function connector_product_update($product_id, $product)
		{
			if (!$this->has_license_key()) {
				return;
			}

			$updating_product_id = 'update_product_' . $product_id;
			if (false === ($updating_product = get_transient($updating_product_id))) {
				set_transient($updating_product_id, $product_id, 2); // change 2 seconds if not enough
				$this->debug('PRODUCT UPDATED! PRODUCT_ID: ' . $product_id);
				$this->send_product_update_sync_request($product_id);
			}
		}

		function connector_order_completed($order_id)
		{
			if (!$this->has_license_key()) {
				return;
			}

			$updating_order_id = 'update_order_' . $order_id;
			if (false === ($updating_order = get_transient($updating_order_id))) {
				set_transient($updating_order_id, $order_id, 2); // change 2 seconds if not enough
				$this->debug('ORDER COMPLETED! ORDER_ID: ' . $order_id);
				$this->send_order_completed_sync_request($order_id);
			}
		}

		/**
		 * Logs messages if WP_DEBUG is enabled.
		 *
		 * @param mixed  $data  Data to log.
		 * @param string $level Log level (debug, info, warning, error).
		 */
		public function log($data, $level = 'info')
		{
			$log_enabled = get_option($this->config->get_domain() . '_enable_logs', 'checked');
			if ('checked' == $log_enabled) {
				$message = $data;
				if (strpos($message, 'serverKey') !== false || strpos($message, 'ServerKey') !== false) {
					$message = preg_replace('/"[Ss]erver[Kk]ey":\s*"[^"]*"/', '"ServerKey":"ANON"', $message);
				}
				if (strpos($message, 'licenseKey') !== false || strpos($message, 'LicenseKey') !== false) {
					$message = preg_replace('/"[Ll]icense[Kk]ey":\s*"[^"]*"/', '"LicenseKey":"ANON"', $message);
				}
				$log_file = $this->get_log_file_path(gmdate('Y-m-d'), true);
				$message = gmdate('[Y.m.d H:i:s]') . ' [' . strtoupper($level) . '] ' . $message . PHP_EOL;
				file_put_contents($log_file, $message, FILE_APPEND);
			}
		}

		function get_logs($data)
		{
			$logs_filename = $data["LogsDate"] . '.log';
			$log_file = $this->get_log_file_path($data["LogsDate"]);

			if (file_exists($log_file)) {
				global $wp_filesystem;
				// create a file interaction object, if it is not already created
				if (! $wp_filesystem) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
					WP_Filesystem();
				}

				$contents = $wp_filesystem->get_contents($log_file);
				if (!$contents) {
					return "Log file read error for date: " . $data["LogsDate"];
				}
				return new \WP_REST_Response(
					$contents,
					200,
					array('Content-Type' => 'text/plain', "Content-Disposition:attachment; filename=\"$logs_filename\"")
				);
			} else {
				return "Log file not found for date: " . $data["LogsDate"];
			}
		}

		public function get_log_file_path($log_date, $create_if_not_exists = false)
		{
			$log_files_dictionary = get_option($this->config->get_domain() . '_logs_dictionary');

			if (
				!$create_if_not_exists &&
				(!isset($log_files_dictionary) || !is_array($log_files_dictionary) || !isset($log_files_dictionary[$log_date]))
			) {
				return null;
			}

			if ($create_if_not_exists && (!isset($log_files_dictionary) || !is_array($log_files_dictionary))) {
				$log_files_dictionary = array();
			}

			if (!isset($log_files_dictionary[$log_date])) {

				$log_files_dictionary[$log_date] = $this->config->get_log_path() . wp_generate_uuid4() . '.log';
				update_option($this->config->get_domain() . '_logs_dictionary', $log_files_dictionary);
			}

			return $log_files_dictionary[$log_date];
		}

		public function debug($data)
		{
			$this->log($data, 'debug');
		}

		public function info($data)
		{
			$this->log($data, 'info');
		}

		public function warning($data)
		{
			$this->log($data, 'warning');
		}

		public function error($data)
		{
			$this->log($data, 'error');
		}

		function toInteger($string)
		{
			sscanf($string, '%u%c', $number, $suffix);
			if (isset($suffix)) {
				$number = $number * pow(1024, strpos(' KMG', strtoupper($suffix)));
			}
			return $number;
		}

		public function get_website_url()
		{
			$url = get_site_url();
			$parsed_url = wp_parse_url($url);
			$host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
			$port = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
			$path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
			return "$host$port$path";
		}

		public function get_api_url()
		{
			switch ($this->config->get_environment()) {
				case 'LOCALDEV':
					return 'http://localhost:5204/api/v1/';
				case 'DEV':
					return 'https://api-dev.shelfplanner.com/api/v1/';
				case 'UAT':
					return 'https://api-uat.shelfplanner.com/api/v1/';
				case 'PROD':
					return 'https://api.shelfplanner.com/api/v1/';
			}
		}

		public function get_app_url()
		{
			switch ($this->config->get_environment()) {
				case 'LOCALDEV':
					return 'http://localhost:4200/#/';
				case 'DEV':
					return 'https://my-dev.shelfplanner.com/#/';
				case 'UAT':
					return 'https://my-uat.shelfplanner.com/#/';
				case 'PROD':
					return 'https://my.shelfplanner.com/#/';
			}
		}

		public function get_installed_plugin_version()
		{
			return get_option($this->config->get_domain() . '_installed_plugin_version', '');
		}

		public function set_installed_plugin_version($version)
		{
			update_option($this->config->get_domain() . '_installed_plugin_version', $version);
		}

		public function get_server_key()
		{
			return get_option($this->config->get_domain() . '_server_key', '');
		}

		public function set_server_key($serverKey)
		{
			update_option($this->config->get_domain() . '_server_key', $serverKey);
		}

		public function delete_server_key()
		{
			delete_option($this->config->get_domain() . '_server_key');
		}

		public function get_license_key()
		{
			return get_option($this->config->get_domain() . '_license_key', '');
		}

		public function has_license_key()
		{
			 $license_key = $this->get_license_key();

    		 return is_string($license_key) && trim($license_key) !== '';
		}

		public function has_server_key()
		{
			$server_key = $this->get_server_key();
			return is_string($server_key) && trim($server_key) !== '';
		}

		public function set_license_key($licenseKey)
		{
			update_option($this->config->get_domain() . '_license_key', $licenseKey);
		}

		public function delete_license_key()
		{
			delete_option($this->config->get_domain() . '_license_key');
		}

		public function get_setup_url($is_embedded = false)
		{
			return $this->get_app_url() . "connector/setup/" . $this->get_server_key() . "/" . $this->base64url_encode($this->get_website_url()) . ($is_embedded ? "/embedded" : "/external");
		}

		public function base64url_encode($data)
		{
			return str_replace("==", "=", strtr(base64_encode($data), '+/', '-_'));
		}

		public function get_connector_hook()
		{
			return get_site_url() . '/?rest_route=' . '/' . $this->config->get_api_baseuri();
		}

		function get_store_info()
		{
			if (!class_exists('WooCommerce')) {
				$this->error('NO WOOCOMMERCE DETECTED');
				return;
			}

			global $wp_version;
			global $woocommerce;

			$total_memory = ini_get('memory_limit');
			$used_memory = memory_get_usage();
			$available_memory = $used_memory;

			// Store country
			$storeCountry = wc_get_base_location()['country'];

			// Store currency
			// https://wp-kama.com/plugin/woocommerce/function/get_woocommerce_currency
			$storeCurrency = get_woocommerce_currency();

			$current_user = wp_get_current_user();

			$sp_payload = [
				'Title' => get_bloginfo(),
				'WebsiteUrl' => $this->get_website_url(),
				'ConnectorPlatform' => $this->config->get_platform(),
				'ConnectorVersion' => $this->config->get_version(),
				'ConnectorHook' => $this->get_connector_hook(),
				'CountryId' => $storeCountry, // get store country
				'CurrencyCode' => $storeCurrency, // get store currency
				'AvailableCpuCores' => 1,
				'UsedMemory' => $available_memory,
				'TotalMemory' => $this->toInteger($total_memory),
				'ApplicationName' => 'WORDPRESS',
				'ApplicationVersion' => $wp_version,
				'EnvironmentName' => 'WOOCOMMERCE',
				'EnvironmentVersion' => $woocommerce->version,
				'AccountDisplayName' => false != $current_user->display_name ? $current_user->display_name : null,
				'AccountEmail' => false != $current_user->user_email ? $current_user->user_email : null,
				'Metadata' => '',
			];

			return $sp_payload;
		}

		public function activate()
		{
			if (!class_exists('WooCommerce')) {
				$this->error('NO WOOCOMMERCE DETECTED');
				return;
			}

			// $this->delete_server_key();
			// $this->delete_license_key();

			$sp_payload = $this->get_store_info();

			$sp_json_data = json_encode(
				$sp_payload,
				JSON_PRETTY_PRINT
			);

			$url = $this->get_api_url() . 'connector/helo';
			$args = array(
				'method' => 'POST',
				'headers' => array(
					'content-type' => 'application/json',
				),
				'body' => $sp_json_data,
				'timeout' => 60,
			);

			$this->debug("activate payload: " . $sp_json_data);
			$response = wp_remote_request($url, $args);
			$this->debug("activate response: " . json_encode($response));

			if (!is_wp_error($response)) {
				$serverData = json_decode($response['body']);

				if (!isset($serverData)) {
					update_option($this->config->get_domain(), [
						'is_debug' => $this->is_debug(),
						'is_active' => false,
						'error_code' => -1,
						'error_message' => 'No response from server.',
						'error_description' => '',
					]);
					return;
				}

				if (!$serverData->success) {
					update_option($this->config->get_domain(), [
						'is_debug' => $this->is_debug(),
						'is_active' => false,
						'error_code' => $serverData->errorCode,
						'error_message' => $serverData->errorMessage,
						'error_description' => $serverData->errorDescription,
					]);
					return;
				}

				// Setup connector
				$this->set_server_key($serverData->serverKey);
				$this->set_installed_plugin_version($this->config->get_version());

				update_option($this->config->get_domain(), [
					'is_debug' => $this->is_debug(),
					'is_active' => $this->is_activated(),
					'error_code' => '',
					'error_message' => '',
					'error_description' => ''
				]);

				// Send sync store request
				// $this->send_store_sync_request();
			} else {
				$this->error('SPC HELO FAILED: ' . $response->get_error_message());
			}
		}

		public function deactivate()
		{
			$sp_payload = [
				'websiteUrl' => $this->get_website_url(),
				'serverKey' => $this->get_server_key(),
				'licenseKey' => $this->get_license_key(),
			];

			$sp_json_data = json_encode(
				$sp_payload,
				JSON_PRETTY_PRINT
			);

			$url = $this->get_api_url() . 'connector/gbye';
			$args = array(
				'method' => 'POST',
				'headers' => array(
					'content-type' => 'application/json',
				),
				'body' => $sp_json_data,
				'timeout' => 60,
			);

			$response = wp_remote_request($url, $args);
			$this->debug("deactivate response: " . json_encode($response));

			$this->delete_server_key();
			$this->delete_license_key();
		}

		public function send_store_sync_request($full_sync = false)
		{
			$sp_payload = [
				'WebsiteUrl' => $this->get_website_url(),
				'ServerKey' => $this->get_server_key(),
				'LicenseKey' => $this->get_license_key(),
			];

			$sp_json_data = json_encode(
				$sp_payload,
				JSON_PRETTY_PRINT
			);

			$url = $this->get_api_url() . 'connector/sync/store/' . ($full_sync ? 'full' : 'incremental');
			$args = array(
				'method' => 'POST',
				'headers' => array(
					'content-type' => 'application/json',
				),
				'body' => $sp_json_data,
				'timeout' => 60,
			);
			return wp_remote_request($url, $args);
		}

		public function send_order_completed_sync_request($order_id)
		{
			$order_data = $this->map_order($order_id);

			$sp_payload = [
				'WebsiteUrl' => $this->get_website_url(),
				'ServerKey' => $this->get_server_key(),
				'LicenseKey' => $this->get_license_key(),
				'ItemId' => '' . $order_id,
				'ItemData' => $order_data
			];

			$sp_json_data = json_encode(
				$sp_payload,
				JSON_PRETTY_PRINT
			);

			$url = $this->get_api_url() . 'connector/sync/order';
			$args = array(
				'method' => 'POST',
				'headers' => array(
					'content-type' => 'application/json',
				),
				'body' => $sp_json_data,
				'timeout' => 60,
			);
			$response = wp_remote_request($url, $args);
		}

		public function send_product_update_sync_request($product_id)
		{
			$product_id_to_sync = '' . $product_id;
			if ($this->is_wcml_active()) {
				$product_id_to_sync = $this->get_original_product_id($product_id);
			}

			$product_data = $this->map_product($product_id);

			$sp_payload = [
				'WebsiteUrl' => $this->get_website_url(),
				'ServerKey' => $this->get_server_key(),
				'LicenseKey' => $this->get_license_key(),
				'ItemId' => $product_id_to_sync,
				'ItemData' => $product_data
			];

			$sp_json_data = json_encode(
				$sp_payload,
				JSON_PRETTY_PRINT
			);

			$url = $this->get_api_url() . 'connector/sync/product';
			$args = array(
				'method' => 'POST',
				'headers' => array(
					'content-type' => 'application/json',
				),
				'body' => $sp_json_data,
				'timeout' => 60,
			);
			$response = wp_remote_request($url, $args);
		}

		// WPML Support

		function is_wcml_active(): bool
		{
			if (
				defined('ICL_SITEPRESS_VERSION')
				&& defined('ICL_PLUGIN_INACTIVE')
				&& !ICL_PLUGIN_INACTIVE
				&& class_exists('SitePress')
				&& defined('WCML_VERSION')
			) {
				return true;
			}

			return false;
		}

		function wcml_check()
		{
			if ($this->is_wcml_active() === true && get_option($this->config->get_domain() . '_wcml_is_active') === false) {
				$response = $this->send_store_sync_request(true);
				if (!is_wp_error($response)) {
					add_option($this->config->get_domain() . '_wcml_is_active', true);
				} else {
					$this->error("wcml_check send_store_sync_request response: " . json_encode($response));
				}
			}
		}

		function get_original_product_id($requested_product_id)
		{
			$original_product_id = '' . apply_filters('wpml_object_id', $requested_product_id, 'product');
			return $original_product_id;
		}

		function get_original_category_id($requested_category_id)
		{
			$original_category_id = '' . apply_filters('wpml_object_id', $requested_category_id, 'category');
			return $original_category_id;
		}
	}
endif;
