<?php
/**
 * Plugin Name:     Payment Method Based Fee &amp; Discounts
 * Plugin URI:       https://woocommerce.com/products/addify-payment-gateway-fee-discounts
 * Version:         1.2.0
 * Description:     Add fee or discount based on payment gateways.
 * Author:            Addify
 * Developed By:      Addify
 * Author URI:        https://woocommerce.com/vendor/addify/
 * Support:           https://woocommerce.com/vendor/addify/
 * License:           GNU General Public License v3.0
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Domain Path:       /languages
 * Requires Plugins: woocommerce
 * Text Domain:     Addify_pgbf
 * WC requires at least: 4.0
 * WC tested up to: 10.*.*
 * Requires at least: 6.5
 * Tested up to: 6.*.*
 * Requires PHP: 7.4
 * Woo: 18734002062333:0cf810a5960d05d00bd5fc61864cbe55

 */


// Prevent direct file access

defined( 'ABSPATH' ) || exit;


/**
 * Class Start
 */

class Addify_Payment_Gateway_Based_Fees_Main {

	public function __construct() {
		$this->afpgbf_global_constanat_vars();
		// add_action( 'wp_loaded', array( $this, 'afpgbf_textdomain' ) );
		add_action( 'init', array( $this, 'afpgbf_custom_post_type' ) );

		if ( is_admin() ) {
			include_once AFPGBF_DIR_PATH . 'includes/admin/class-af-pgbf-admin.php';
		}
		include_once AFPGBF_DIR_PATH . 'includes/front/class-af-pgbf-front.php';

		// HOPS compatibility
		add_action( 'before_woocommerce_init', array( $this, 'afpgbf_HOPS_Compatibility' ) );

		add_action( 'plugins_loaded', array( $this, 'afpgbf_plugin_check' ) );
	}

	public function afpgbf_HOPS_Compatibility() {

		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}

	public function afpgbf_plugin_check() {

		if ( ! is_multisite() && ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {

			add_action( 'admin_notices', array( $this, 'afpgbf_plugin_loaded' ) );

		}
	}
	public function afpgbf_plugin_loaded() {
			// Deactivate the plugin.

			$afpgbf_woo_check = '<div id="message" class="error">
			<p><strong>' . __( 'Payment Method Based Fee & Discounts plugin is inactive.', 'Addify_pgbf' ) . '</strong> The <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce plugin</a> ' . __( 'must be active for this plugin to work. Please install &amp; activate WooCommerce.', 'Addify_pgbf' ) . ' »</p></div>';
		echo wp_kses_post( $afpgbf_woo_check );
	}

	public function afpgbf_global_constanat_vars() {

		if ( ! defined( 'AFPGBF_DIR_URL' ) ) {
			define( 'AFPGBF_DIR_URL', plugin_dir_url( __FILE__ ) );
		}
		if ( ! defined( 'AFPGBF_BASENAME' ) ) {
			define( 'AFPGBF_BASENAME', plugin_basename( __FILE__ ) );
		}
		if ( ! defined( 'AFPGBF_DIR_PATH' ) ) {
			define( 'AFPGBF_DIR_PATH', plugin_dir_path( __FILE__ ) );
		}
	}

	// public function afpgbf_textdomain() {

	//  if ( function_exists( 'load_plugin_textdomain' ) ) {
	//      load_plugin_textdomain( 'Addify_pgbf', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	//  }
	// }

	public function afpgbf_custom_post_type() {

		$labels = array(
			'name'                => esc_html__( 'Payment Method Based Fee & Discount', 'Addify_pgbf' ),
			'singular_name'       => esc_html__( 'Fee/Discount', 'Addify_pgbf' ),
			'add_new'             => esc_html__( 'Add New Fee/Discount', 'Addify_pgbf' ),
			'add_new_item'        => esc_html__( 'Payment Method Based Fee & Discount', 'Addify_pgbf' ),
			'edit_item'           => esc_html__( 'Edit Fee/Discount', 'Addify_pgbf' ),
			'new_item'            => esc_html__( 'Fee/Discount', 'Addify_pgbf' ),
			'view_item'           => esc_html__( 'Fee/Discount', 'Addify_pgbf' ),
			'search_items'        => esc_html__( 'Search Fee/Discount', 'Addify_pgbf' ),
			'exclude_from_search' => true,
			'not_found'           => esc_html__( 'No Fee/Discount', 'Addify_pgbf' ),
			'not_found_in_trash'  => esc_html__( 'No Fee/Discount found in trash', 'Addify_pgbf' ),
			'parent_item_colon'   => '',
			'all_items'           => esc_html__( 'Payment Fee', 'Addify_pgbf' ),
			'menu_name'           => esc_html__( 'Fee/Discount', 'Addify_pgbf' ),
			'attributes'          => esc_html__( 'Priority', 'Addify_pgbf' ),
		);

		$args = array(
			'labels'             => $labels,
			'supports'           => array( 'title', 'page-attributes' ),
			// 'menu_icon'          => plugin_dir_url( __FILE__ ) . 'images/small_logo_grey.png',
			'public'             => true, // Whether a post type is intended(consider) for use publicly either via the admin interface or by front-end users.
			'publicly_queryable' => true,
			'show_ui'            => true, // ‘false’ – do not display a user-interface for this post type
			'show_in_menu'       => 'woocommerce', // ‘false’ – do not display in the admin menu
			'query_var'          => true, // Sets the query_var key for this post type. give string rather than true
			'capability_type'    => 'post',
			'has_archive'        => true, // 'Archives' is used for a list of entries from a post type or taxonomy (like categories and tags)
			'hierarchical'       => false, // Allows Parent to be specified.
			'menu_position'      => null,  // default
			'rewrite'            => array( 'slug' => 'afpgbf_rules' ), // Default: true and use $post_type as slug. To prevent rewrites, set to false.
		);

		register_post_type( 'afpgbf_rules', $args ); // Register Post type
	}
}

new Addify_Payment_Gateway_Based_Fees_Main();
