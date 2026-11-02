<?php
/**
 * Main Premium class
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Premium' ) ) {
	/**
	 * WooCommerce Affiliates Premium
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Premium extends YITH_WCAF {

		/**
		 * Constructor method
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			parent::__construct();

			// register plugin to licence/update system.
			add_action( 'wp_loaded', array( $this, 'register_plugin_for_activation' ), 99 );
			add_action( 'wp_loaded', array( $this, 'register_plugin_for_updates' ), 99 );

			// emails init.
			add_filter( 'woocommerce_locate_core_template', array( $this, 'register_woocommerce_template' ), 10, 3 );
		}

		/* === INSTALL METHODS === */

		/**
		 * Startup plugin
		 *
		 * @return void
		 */
		public function init() {
			// do startup operations.
			YITH_WCAF_Install_Premium::init();
			YITH_WCAF_Shortcodes_Premium::init();
			YITH_WCAF_Form_Handler_Premium::init();
			YITH_WCAF_Ajax_Handler_Premium::init();
			YITH_WCAF_Crons_Handler_Premium::init();

			YITH_WCAF_Instance::init();
			YITH_WCAF_Endpoints::init();
			YITH_WCAF_Gateways::init();
			YITH_WCAF_Blocks::init();
			YITH_WCAF_Affiliates_Profile::init();
			YITH_WCAF_Affiliates_Invoice_Profile::init();
			YITH_WCAF_Compatibilities::init();
			YITH_WCAF_Emails::init();
			YITH_WCAF_Rate_Handler_Premium::get_default();

			// init required objects.
			$this->init_instances();

			// init legacy classes.
			$this->init_legacy();

			do_action( 'yith_wcaf_standby' );
		}

		/**
		 * Startup plugin's objects
		 *
		 * @return void
		 */
		public function init_instances() {
			// init objects' instances.
			YITH_WCAF_Coupons::get_instance();
			YITH_WCAF_Promo::get_instance();
			YITH_WCAF_Invoices::get_instance();
			YITH_WCAF_Withdraws::get_instance();

			// init APIs.
			YITH_WCAF_REST_Install::get_instance();

			parent::init_instances();
		}

		/**
		 * Locate default templates of woocommerce in plugin, if exists
		 *
		 * @param string $core_file     Template's default location.
		 * @param string $template      Template name.
		 * @param string $template_base Base template path.
		 *
		 * @return string
		 * @since  1.0.0
		 */
		public function register_woocommerce_template( $core_file, $template, $template_base ) {
			$located = yith_wcaf_locate_template( $template );

			if ( $located && file_exists( $located ) ) {
				return $located;
			} else {
				return $core_file;
			}
		}

		/**
		 * Declare support for WooCommerce features.
		 */
		public function declare_wc_features_support() {
			parent::declare_wc_features_support();

			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', YITH_WCAF_INIT, true );
			}
		}

		/* === LICENCE HANDLING METHODS === */

		/**
		 * Register plugins for activation tab
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function register_plugin_for_activation() {
			if ( ! class_exists( 'YIT_Plugin_Licence' ) ) {
				require_once YITH_WCAF_DIR . 'plugin-fw/licence/lib/yit-licence.php';
				require_once YITH_WCAF_DIR . 'plugin-fw/licence/lib/yit-plugin-licence.php';
			}

			YIT_Plugin_Licence()->register( YITH_WCAF_INIT, YITH_WCAF_SECRET_KEY, YITH_WCAF_SLUG );
		}

		/**
		 * Register plugins for update tab
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function register_plugin_for_updates() {
			if ( ! class_exists( 'YIT_Plugin_Licence' ) ) {
				require_once YITH_WCAF_DIR . 'plugin-fw/lib/yit-upgrade.php';
			}

			YIT_Upgrade()->register( YITH_WCAF_SLUG, YITH_WCAF_INIT );
		}
	}
}

/**
 * Unique access to instance of YITH_WCAF_Premium class
 *
 * @return \YITH_WCAF_Premium
 * @since 1.0.0
 */
function YITH_WCAF_Premium() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return YITH_WCAF_Premium::get_instance();
}
