<?php
/**
 * Main class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH Infinite Scrolling
 * @version 1.0.0
 */

defined( 'YITH_INFS' ) || exit; // Exit if accessed directly.

if ( ! class_exists( 'YITH_INFS' ) ) {
	/**
	 * YITH Infinite Scrolling
	 *
	 * @since 1.0.0
	 */
	class YITH_INFS {

		/**
		 * Single instance of the class
		 *
		 * @since 1.0.0
		 * @var YITH_INFS
		 */
		protected static $instance;

		/**
		 * Plugin version
		 *
		 * @since 1.0.0
		 * @var string
		 */
		public $version = YITH_INFS_VERSION;

		/**
		 * Plugin object
		 *
		 * @since 1.0.0
		 * @var string
		 */
		public $obj = null;

		/**
		 * Returns single instance of the class
		 *
		 * @since 1.0.0
		 * @return YITH_INFS
		 */
		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor
		 *
		 * @since 1.0.0
		 * @return mixed YITH_INFS_Admin | YITH_INFS_Frontend
		 */
		public function __construct() {
			// Class admin.
			if ( $this->is_admin() ) {
				require_once 'class.yith-infs-admin.php';

				YITH_INFS_Admin();

			} elseif ( $this->load_frontend() ) {
				require_once 'class.yith-infs-frontend.php';
				YITH_INFS_Frontend();
			}
			add_action( 'before_woocommerce_init', array( $this, 'declare_wc_features_support' ) );
		}

		/**
		 * Check if is admin
		 *
		 * @since  1.0.6
		 * @return boolean
		 */
		public function is_admin() {
			$check_ajax    = defined( 'DOING_AJAX' ) && DOING_AJAX;
			$check_context = isset( $_REQUEST['context'] ) && 'frontend' === sanitize_text_field( wp_unslash( $_REQUEST['context'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			return is_admin() && ! ( $check_ajax && $check_context );
		}

		/**
		 * Check if load frontend class
		 *
		 * @since  1.0.6
		 * @return boolean
		 */
		public function load_frontend() {

			$active_mobile = 'yes' === yinfs_get_option( 'yith-infs-enable-mobile', 'yes' );

			/**
			 * APPLY_FILTERS: yith_infinite_scrolling_load_frontend
			 *
			 * Filters whether the frontend classes should be loaded.
			 *
			 * @param bool $load_frontend Whether the frontend classes should be loaded or not.
			 *
			 * @return bool
			 */
			return apply_filters( 'yith_infinite_scrolling_load_frontend', ( ( ! wp_is_mobile() || ( wp_is_mobile() && $active_mobile ) ) ) );
		}

		/**
		 * Declare support for WooCommerce features.
		 */
		public function declare_wc_features_support() {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', YITH_INFS_FREE_INIT, true );
			}
		}
	}
}

/**
 * Unique access to instance of YITH_INFS class
 *
 * @since 1.0.0
 * @return YITH_INFS
 */
function YITH_INFS() { // phpcs:ignore
	return YITH_INFS::get_instance();
}
