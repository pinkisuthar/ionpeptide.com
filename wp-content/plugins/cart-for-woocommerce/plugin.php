<?php
/**
 * Plugin Name: FunnelKit Cart for WooCommerce
 * Plugin URI: https://funnelkit.com/funnelkit-cart/
 * Description: Add a beautiful sliding cart to your WooCommerce site. Let the buyers edit items, add upsells on sliding cart and skip to checkout.
 * Version: 1.9.1.2
 * Author: FunnelKit
 * Author URI: https://funnelkit.com
 * License: GPLv3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: cart-for-woocommerce
 *
 * Requires at least: 5.0
 * WC requires at least: 5.0
 * WC tested up to: 10.8.0
 * Requires PHP: 7.0
 */

namespace FKCart;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( '\FKCart\Plugin' ) ) {
	final class Plugin {

		/**
		 * @var $instance
		 */
		private static $instance = null;

		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		/**
		 * Class constructor
		 */
		public function __construct() {
			/** Defining constants */
			$this->define_constant();

			spl_autoload_register( [ $this, 'autoload' ] );

			/** Including common functions */
			require_once FKCART_PLUGIN_DIR . '/includes/functions.php';

			add_action( 'plugins_loaded', [ $this, 'load_flies' ], 15 );
			add_action( 'admin_notices', [ $this, 'maybe_wc_not_active' ] );

			/** Localization */
			add_action( 'init', [ $this, 'load_plugin_text_domain' ] );

			/** HPOS compatibility */
			add_action( 'before_woocommerce_init', [ $this, 'hpos_compatibility_declaration' ] );
		}

		/**
		 * Define plugin constants
		 *
		 * @return void
		 */
		public function define_constant() {
			define( 'FKCART_VERSION', '1.9.1.2' );
			define( 'FKCART_MIN_WC_VERSION', '5.0' );
			define( 'FKCART_MIN_FB_PRO_VERSION', '3.0' );
			define( 'FKCART_PLUGIN_FILE', __FILE__ );
			define( 'FKCART_PLUGIN_DIR', __DIR__ );
			define( 'FKCART_DB_VERSION', '1.8.4' );

			$plugin_url = untrailingslashit( plugin_dir_url( FKCART_PLUGIN_FILE ) );
			if ( is_ssl() ) {
				$plugin_url = preg_replace( "/^http:/i", "https:", $plugin_url );
			}

			define( 'FKCART_PLUGIN_URL', $plugin_url );
			define( 'FKCART_PLUGIN_BASENAME', plugin_basename( FKCART_PLUGIN_FILE ) );

			( ! defined( 'FKCART_REACT_ENVIRONMENT' ) ) && define( 'FKCART_REACT_ENVIRONMENT', 1 );
			define( 'FKCART_REACT_PROD_URL', FKCART_PLUGIN_URL . '/admin/app/dist' );
		}

		/**
		 * Autoload classes.
		 *
		 * @param string $class class name.
		 */
		public function autoload( $class ) {
			if ( 0 !== strpos( $class, __NAMESPACE__ ) ) {
				return;
			}

			$class_to_load = $class;

			$filename = strtolower( preg_replace( [ '/^' . __NAMESPACE__ . '\\\/', '/([a-z])([A-Z])/', '/_/', '/\\\/' ], [ '', '$1-$2', '-', DIRECTORY_SEPARATOR ], $class_to_load ) );

			$file = FKCART_PLUGIN_DIR . '/' . $filename . '.php';

			/** If file is readable, include it */
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}

		/**
		 * Load plugin files
		 *
		 * @return void
		 */
		public function load_flies() {
			if ( false === fkcart_is_wc_active() ) {
				return;
			}
			Compatibilities\Compatibility::load();
			do_action( 'funnelkit_cart_loaded' );

			/** Loads admin objects */
			if ( is_admin() ) {
				Admin\Admin_App::get_instance();
				Admin\App_Ajax::get_instance();
			}

			/** Loads public objects */
			Includes\Front::get_instance();
			Includes\Cart::get_instance();
			Includes\Ajax::get_instance();

			/** DB creation */
			Includes\DB::get_instance();
		}

		/**
		 * Print notice if WooCommerce is not active
		 *
		 * @return void
		 */
		public function maybe_wc_not_active() {
			if ( true === fkcart_is_wc_active() ) {
				return;
			}

			$plugin_slug = 'woocommerce';
			$plugin_file = 'woocommerce/woocommerce.php';

			// Check if WooCommerce is installed
			$installed_plugins = get_plugins();
			$is_installed      = isset( $installed_plugins[ $plugin_file ] );

			if ( ! $is_installed ) {
				// Install WooCommerce
				$install_url = wp_nonce_url( admin_url( "update.php?action=install-plugin&plugin=$plugin_slug" ), "install-plugin_$plugin_slug" );
			} else {
				// Activate WooCommerce
				$install_url = wp_nonce_url( admin_url( "plugins.php?action=activate&plugin=$plugin_file" ), "activate-plugin_$plugin_file" );
			}

			?>
            <div class="bwf-notice notice error">
                <p>
					<?php
					echo sprintf( "The <strong>WooCommerce</strong> plugin must be active for <strong>FunnelKit Cart For WooCommerce</strong> to work. Please <a href='%s'>install & activate WooCommerce</a>.", esc_url( $install_url ) );
					?>
                </p>
            </div>
			<?php
		}


		/**
		 * Text localization
		 *
		 * @return void
		 */
		public function load_plugin_text_domain() {
			load_plugin_textdomain( 'cart-for-woocommerce', false, plugin_dir_path( FKCART_PLUGIN_FILE ) . 'languages/' );
		}

		/**
		 * Declares compatibility with WooCommerce HPOS
		 *
		 * @return void
		 */
		public function hpos_compatibility_declaration() {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FKCART_PLUGIN_FILE, true );
			}
		}
	}

	Plugin::get_instance();
}
