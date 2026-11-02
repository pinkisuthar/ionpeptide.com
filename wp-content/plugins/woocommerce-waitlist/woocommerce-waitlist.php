<?php
/**
 * Plugin Name: WooCommerce Waitlist
 * Plugin URI: http://www.woothemes.com/products/woocommerce-waitlist/
 * Description: This plugin enables registered users to request an email notification when an out-of-stock product comes back into stock. It tallies these registrations in the admin panel for review and provides details.
 * Version: 2.5.1
 * Author: Neil Pie
 * Author URI: https://pie.co.de/
 * Developer: Neil Pie
 * Developer URI: https://pie.co.de/
 * WC requires at least: 3.0.0
 * WC tested up to: 10.3.5
 * Requires at least: 4.2.0
 * Tested up to: 6.8.3
 * Requires PHP: 7.4
 * Text Domain: woocommerce-waitlist
 * Domain Path: /assets/languages/
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Copyright: © 2015-2025 WooCommerce
 *
 * @package WooCommerce Waitlist
 * Woo: 122144:55d9643a241ecf5ad501808c0787483f

 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) ) {
	require_once 'woo-includes/woo-functions.php';
}
/**
 * Plugin updates
 */
woothemes_queue_update( plugin_basename( __FILE__ ), '55d9643a241ecf5ad501808c0787483f', '122144' );
if ( ! class_exists( 'WooCommerce_Waitlist_Plugin' ) ) {
	/**
	 * Activate when WC starts
	 *
	 * Only start us up if WC is running & declare HPOS compatibility
	 * 
	 * Filter the list of active plugins we check
	 * 
	 * @since 2.4.0
	 */
	if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ||
		( is_array( get_site_option( 'active_sitewide_plugins' ) ) && array_key_exists( 'woocommerce/woocommerce.php', get_site_option( 'active_sitewide_plugins' ) ) ) ) {
		add_action( 'plugins_loaded', 'WooCommerce_Waitlist_Plugin::load_plugin' );
		add_action( 'before_woocommerce_init', function () {
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
		} );
	} else {
		add_action( 'admin_notices', array( 'WooCommerce_Waitlist_Plugin', 'output_waitlist_not_active_notice' ) );
	}

	/**
	 * Namespace class for functions non-specific to any object within the plugin
	 *
	 * @package  WooCommerce Waitlist
	 */
	class WooCommerce_Waitlist_Plugin {

		/**
		 * Main plugin class instance
		 *
		 * @var object
		 */
		protected static $instance;

		/**
		 * Path to plugin directory
		 *
		 * @var string
		 */
		public static $path;

		/**
		 * Supported product types
		 *
		 * @var array
		 */
		public static $allowed_product_types = array();

		/**
		 * $Pie_WCWL_Admin_Init
		 *
		 * @var object
		 */
		public static $Pie_WCWL_Admin_Init;

		/**
		 * WooCommerce_Waitlist_Plugin constructor
		 */
		public function __construct() {
			self::$path = plugin_dir_path( __FILE__ );
			require_once 'definitions.php';
			if ( ! $this->minimum_woocommerce_version_is_loaded() ) {
				return;
			}
			$this->include_files();
			$this->load_hooks();
		}

		/**
		 * Check users version of WooCommerce is high enough for our plugin
		 *
		 * @return bool
		 */
		public function minimum_woocommerce_version_is_loaded() {
			global $woocommerce;
			if ( ! version_compare( $woocommerce->version, '3.0', '<' ) ) {
				return true;
			}
			add_action( 'admin_notices', array( __CLASS__, 'output_waitlist_not_active_notice' ) );

			return false;
		}

		/**
		 * Display an admin notice notifying users their version of WooCommerce is too low
		 *
		 * @return void
		 */
		public static function output_waitlist_not_active_notice() {
			?>
			<div class="error">
				<p><?php esc_html_e( 'WooCommerce Waitlist is active but is not functional. Is WooCommerce installed and up to date (version 3.0 or higher)?', 'woocommerce-waitlist' ); ?></p>
			</div>
			<?php
		}

		/**
		 * Load required files and instantiate classes where needed
		 */
		public function include_files() {
			require_once 'classes/class-pie-wcwl-waitlist-initialise.php';
			require_once 'wcwl-waitlist-template-functions.php';
			require_once 'classes/class-pie-wcwl-waitlist.php';
			require_once 'classes/class-pie-wcwl-waitlist-update-stock.php';
			
			$init = new Pie_WCWL_Waitlist_Initialise();
			$init->init();

			$update_stock = new Pie_WCWL_Waitlist_Update_Stock();
			$update_stock->init();
		
			if ( is_admin() ) {
				require_once 'classes/admin/class-pie-wcwl-admin-init.php';
				$admin = new Pie_WCWL_Admin_Init();
				$admin->init();
				require_once 'classes/frontend/class-pie-wcwl-frontend-ajax.php';
				$frontend_ajax = new Pie_WCWL_Frontend_Ajax();
				$frontend_ajax->init();
			} else {
				require_once 'classes/frontend/class-pie-wcwl-frontend-init.php';
				$frontend = new Pie_WCWL_Frontend_Init();
				$frontend->init();
			}	
		}

		/**
		 * All other hooks pertinent to the main plugin class
		 */
		public function load_hooks() {
			// Global.
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'remove_user_from_waitlist_on_product_purchase' ), 10, 3 );
			add_action( 'user_register', array( $this, 'check_new_user_for_waitlist_entries' ) );
			add_action( 'delete_user', array( $this, 'unregister_user_when_deleted' ) );
		}

		/**
		 * Check to see if product is of type "bundle"
		 *
		 * @param WC_Product $product product object.
		 *
		 * @return bool
		 */
		public static function is_bundle( WC_Product $product ) {
			if ( ! $product ) {
				return false;
			}
			if ( $product->is_type( 'bundle' ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Check to see if product is of type "variable"
		 *
		 * @param WC_Product $product product object.
		 *
		 * @return bool
		 */
		public static function is_variable( WC_Product $product ) {
			if ( ! $product ) {
				return false;
			}
			if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Check to see if product is of type "variation"
		 *
		 * @param WC_Product $product product object.
		 *
		 * @return bool
		 */
		public static function is_variation( WC_Product $product ) {
			if ( ! $product ) {
				return false;
			}
			if ( $product->is_type( 'variation' ) || $product->is_type( 'subscription_variation' ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Check to see if product is of type "simple"
		 *
		 * @param WC_Product $product product object.
		 *
		 * @return bool
		 */
		public static function is_simple( WC_Product $product ) {
			if ( ! $product ) {
				return false;
			}
			if ( $product->is_type( 'simple' ) || $product->is_type( 'subscription' ) ) {
				return true;
			}

			return false;
		}


		/**
		 * Return all the products that the user is on the waitlist for
		 *
		 * @param object $user user object.
		 *
		 * @return array
		 *
		 * @since  1.6.2
		 */
		public static function get_waitlist_products_for_user( $user ) {
			global $wpdb;
			$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND (meta_value LIKE %s OR meta_value LIKE %s)", array( WCWL_SLUG, '%%i:' . $user->ID . ';%%', '%%' . $user->user_email . '%%' ) ), OBJECT );
			$results = self::return_products_user_is_registered_on( $results, $user );
			return $results;
		}

		/**
		 * Integrity check on data to ensure users are on the waitlists for the returned products
		 *
		 * @param array  $products products to check.
		 * @param object $user     user object.
		 *
		 * @return array
		 */
		public static function return_products_user_is_registered_on( $products, $user ) {
			$waitlist_products = array();
			foreach ( $products as $product ) {
				$product  = wc_get_product( $product->post_id );
				if ( ! $product ) {
					continue;
				}
				$waitlist = new Pie_WCWL_Waitlist( $product );
				if ( $waitlist->user_is_registered( $user->user_email ) ) {
					$waitlist_products[] = $product;
				}
			}

			return $waitlist_products;
		}

		/**
		 * Return all the products that the user is on a waitlist archive for
		 *
		 * @param object $user user object.
		 *
		 * @return array
		 * @since  1.6.2
		 */
		public static function get_waitlist_archives_for_user( $user ) {
			if ( ! get_option( '_' . WCWL_SLUG . '_metadata_updated' ) ) {
				return array();
			}
			global $wpdb;

			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}postmeta WHERE meta_key = 'wcwl_waitlist_archive' AND (meta_value LIKE %s OR meta_value LIKE %s)", array( '%%i:' . $user->ID . ';i:' . $user->ID . ';%%', '%%' . $user->user_email . '%%' ) ), OBJECT );
		}

		/**
		 * Remove user from all archives
		 * If an admin removes the user they are deleted, if a user removes themselves the User ID is stored as 0 so we can track it
		 *
		 * @param array  $archives archives to check.
		 * @param object $user user object.
		 */
		public static function remove_user_from_archives( $archives, $user ) {
			if ( ! $user || ! $archives ) {
				return;
			}
			foreach ( $archives as $archive ) {
				$product_id  = $archive->post_id;
				$old_archive = unserialize( $archive->meta_value );
				$new_archive = $old_archive;
				foreach ( $old_archive as $timestamp => $users ) {
					if ( ! $users ) {
						unset( $new_archive[ $timestamp ] );
					} else {
						unset( $new_archive[ $timestamp ][ $user->ID ] );
						unset( $new_archive[ $timestamp ][ $user->user_email ] );
					}
				}
				update_post_meta( $product_id, 'wcwl_waitlist_archive', $new_archive );
			}
		}

		/**
		 * Return all product posts
		 *
		 * @return array all product posts.
		 * @since  1.7.0
		 */
		public static function return_all_product_ids() {
			$query = new WC_Product_Query( array(
				'limit'  => -1,
				'return' => 'ids',
			) );
			
			return $query->get_products();
		}

		/**
		 * Return all product posts with a waitlist entry in the database
		 *
		 * @return array all product posts.
		 * @since  1.7.0
		 */
		public static function return_all_waitlist_archive_product_ids() {
			global $wpdb;
			$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}postmeta WHERE (meta_key = %s AND meta_value != '' AND meta_value != 'a:0:{}' ) OR (meta_key = 'wcwl_waitlist_archive' AND meta_value != '' AND meta_value != 'a:0:{}' )", WCWL_SLUG ), OBJECT );

			$product_ids = array();
			foreach ( $results as $product ) {
				$product_ids[] = intval( $product->post_id );
			}

			return array_unique( $product_ids );
		}

		/**
		 * A function to easily add and remove hooks pertaining to creating a user and forcing options
		 *
		 * @return string
		 */
		public function return_option_setting_yes() {
			return 'yes';
		}

		/**
		 * Check if persistent waitlists are disabled
		 *
		 * Filterable function to switch on persistent waitlists. Persistent waitlists will prevent users from being
		 * removed from a waitlist after email is sent, instead being removed when they purchase an item.
		 *
		 * @static
		 *
		 * @param $product_id product ID.
		 *
		 * @return bool
		 * @since  1.1.1
		 */
		public static function persistent_waitlists_are_disabled( $product_id ) {
			/**
			 * Filter to disable persistent waitlists
			 * 
			 * @since 2.4.0
			 */
			return apply_filters( 'wcwl_persistent_waitlists_are_disabled', true, $product_id );
		}

		/**
		 * Check if automatic mailouts are disabled. If so, no email will be sent to waitlisted users when a product
		 * returns to stock and as such they will remain on the waitlist.
		 *
		 * @static
		 *
		 * @param $product_id product ID.
		 *
		 * @return bool
		 * @since  1.1.8
		 */
		public static function automatic_mailouts_are_disabled( $product_id ) {
			/**
			 * Filter to disable automatic mailouts
			 * 
			 * @since 2.4.0
			 */
			return apply_filters( 'wcwl_automatic_mailouts_are_disabled', false, $product_id );
		}

		/**
		 * Apply filter to show waitlist on products that are "in stock" but available on back order
		 *
		 * @static
		 *
		 * @param $product_id product ID.
		 *
		 * @return bool
		 * @since  2.0.14
		 */
		public static function enable_waitlist_for_backorder_products( $product_id ) {
			/**
			 * Filter to enable waitlist for backorder products
			 * 
			 * @since 2.4.0
			 */
			return apply_filters( 'wcwl_enable_waitlist_for_backorder_products', false, $product_id );
		}

		/**
		 * Removes user from waitlist on purchase if persistent waitlists are enabled
		 *
		 * @param int    $order_id    order ID.
		 * @param array  $posted_data form data.
		 * @param object $order       order object.
		 *
		 */
		public function remove_user_from_waitlist_on_product_purchase( $order_id, $posted_data, $order ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( $product ) {
					$waitlist = new Pie_WCWL_Waitlist( $product );
					$waitlist->unregister_user( $order->get_billing_email() );
				}
			}
		}

		/**
		 * When a new user registers check waitlists for the email used and adjust this to IDs
		 *
		 * @param int $user_id
		 */
		public function check_new_user_for_waitlist_entries( $user_id ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) {
				return;
			}
			$products = self::get_waitlist_products_for_user( $user );
			foreach ( $products as $product ) {
				if ( ! $product ) {
					continue;
				}
				$waitlist = new Pie_WCWL_Waitlist( $product );
				if ( isset( $waitlist->waitlist[ $user->user_email ] ) ) {
					$waitlist->waitlist[ $user_id ] = $waitlist->waitlist[ $user->user_email ];
					unset( $waitlist->waitlist[ $user->user_email ] );
					asort( $waitlist->waitlist );
					$waitlist->save_waitlist();
				}
			}
		}

		/**
		 * Get the user object, check which products they are on the waitlist for and unregister them from each one when deleted
		 *
		 * @param int $user_id id of the user that is being deleted.
		 *
		 * @return void
		 * @since  1.3
		 */
		public function unregister_user_when_deleted( $user_id ) {
			$user      = get_user_by( 'id', $user_id );
			$waitlists = WooCommerce_Waitlist_Plugin::get_waitlist_products_for_user( $user );
			if ( $user && $waitlists ) {
				foreach ( $waitlists as $product ) {
					if ( $product ) {
						$waitlist = new Pie_WCWL_Waitlist( $product );
						$waitlist->unregister_user( $user->user_email );
					}
				}
			}
			$archives = WooCommerce_Waitlist_Plugin::get_waitlist_archives_for_user( $user );
			WooCommerce_Waitlist_Plugin::remove_user_from_archives( $archives, $user );
		}

		/**
		 * Load the plugin
		 * Hooking directly into action with the instance function causes issues as it returns something
		 */
		public static function load_plugin() {
			self::instance();
		}

		/**
		 * Waitlist main instance, ensures only one instance is loaded
		 *
		 * @since 1.5.0
		 * @return WooCommerce_Waitlist_Plugin
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}
	}
}
