<?php
/**
 * Abstract Usage Collector Base Class
 *
 * All usage collectors must extend this class
 *
 * @package WooFunnels
 * @since 1.10.12.72
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WooFunnels_Usage_Collector_Abstract' ) ) {

	/**
	 * Abstract Class WooFunnels_Usage_Collector_Abstract
	 */
	#[\AllowDynamicProperties]
	abstract class WooFunnels_Usage_Collector_Abstract {

		/**
		 * Plugin identifier (e.g., 'funnel-builder', 'automations')
		 * Must be set by child class
		 *
		 * @var string
		 */
		protected $plugin_id;

		/**
		 * Module type ('lite' or 'pro')
		 * Must be set by child class
		 *
		 * @var string
		 */
		protected $module;

		/**
		 * Usage version
		 *
		 * @var string
		 */
		protected $usage_version = '1.0.0';

		/**
		 * Constructor - Registers collector with registry
		 */
		public function __construct() {

		}

		/**
		 * Collect usage data
		 * Must be implemented by child classes
		 *
		 * @return array Usage data
		 */
		abstract public function collect();

		/**
		 * Setup data - Collects data and saves to options table
		 * Called by individual tracker schedule
		 * Must be implemented by child classes
		 *
		 * @return bool True on success, false on failure
		 */
		abstract public function setup_data();

		/**
		 * Return data - Returns saved data from options table
		 * Called by final collector to retrieve saved data
		 * Must be implemented by child classes
		 *
		 * @return array Saved data or empty array if not found
		 */
		abstract public function return_data();

		/**
		 * Get option key - Returns the option key name for this tracker's data
		 * Each tracker decides its own option key format
		 * Must be implemented by child classes
		 *
		 * @return string Option key name
		 */
		abstract public function get_option_key();

		/**
		 * Check if collector should collect data
		 * Lite: checks opt-in using wrapper method
		 * Pro: always returns true (can be overridden)
		 *
		 * @return bool
		 */
		public function should_collect() {
			// Lite: Check opt-in using wrapper method
			if ( 'lite' === $this->module ) {
				return ( true === WooFunnels_OptIn_Manager::is_optin_allowed()
						 && 'yes' === WooFunnels_OptIn_Manager::get_optIn_state() );
			}

			return true;
		}

		/**
		 * Get plugin identifier
		 *
		 * @return string
		 */
		public function get_plugin_id() {
			return $this->plugin_id;
		}

		/**
		 * Get module type
		 *
		 * @return string
		 */
		public function get_module() {
			return $this->module;
		}

		/**
		 * Get usage version
		 *
		 * @return string
		 */
		public function get_usage_version() {
			return $this->usage_version;
		}
	}
}

