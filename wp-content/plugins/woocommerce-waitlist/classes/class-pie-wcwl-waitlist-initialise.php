<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
if ( ! class_exists( 'Pie_WCWL_Waitlist_Initialise' ) ) {
	/**
	 * Initialise Pie_WCWL_Waitlist
	 *
	 * Functions to be carried out at the initilisation for the plugin
	 *
	 * @package WooCommerce Waitlist
	 */
	class Pie_WCWL_Waitlist_Initialise {

		/**
		 * Initialisation hooks
		 *
		 * @return void
		 */
		public function init() {
			add_action( 'init', array( $this, 'set_default_localization_directory' ) );
			add_action( 'init', array( $this, 'register_custom_endpoints' ) );
			add_action( 'init', array( $this, 'setup_product_types' ), 15 );
			add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
			add_filter( 'woocommerce_email_classes', array( $this, 'initialise_waitlist_email_class' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( plugin_dir_path( dirname( __FILE__ ) ) ) . '/woocommerce-waitlist.php', array( $this, 'plugin_links' ) );
			add_filter( 'cocart_products_ignore_private_meta_keys', array( $this, 'remove_waitlist_metadata_from_cocart_request' ), 0, 2 );
		}

		/**
		 * Setup localization for plugin
		 *
		 * @return void
		 */
		public function set_default_localization_directory() {
			$plugin_path = apply_filters( 'wcwl_translation_file_path', dirname( plugin_basename( __FILE__ ) ) . '/assets/languages' );
			// Check for a language file in /wp-content/plugins/woocommerce-waitlist/assets/languages/ (this will be overriden by any file already loaded)
			load_plugin_textdomain( 'woocommerce-waitlist', false, $plugin_path );
		}

		/**
		 * Include links to the documentation and settings page on the plugin screen
		 *
		 * @param mixed $links plugin links.
		 *
		 * @since 1.7.3
		 * @return array
		 */
		public function plugin_links( $links ) {
			$plugin_links = array(
				'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=products&section=waitlist' ) . '">' . __( 'Settings', 'woocommerce-waitlist' ) . '</a>',
				'<a href="https://docs.woocommerce.com/document/woocommerce-waitlist/">' . _x( 'Docs', 'short for documents', 'woocommerce-waitlist' ) . '</a>',
				'<a href="https://woocommerce.com/my-account/marketplace-ticket-form/">' . __( 'Support', 'woocommerce-waitlist' ) . '</a>',
			);

			return array_merge( $plugin_links, $links );
		}

		/**
		 * Add custom endpoint for the waitlist tab on the user account page
		 */
		public function register_custom_endpoints() {
			/**
			 * Filter the endpoint slug for the waitlist tab on the user account page
			 * 
			 * @since 2.4.0
			 */
			add_rewrite_endpoint( apply_filters( 'wcwl_waitlist_endpoint', get_option( 'woocommerce_myaccount_waitlist_endpoint', 'woocommerce-waitlist' ) ), EP_ROOT | EP_PAGES );
		}

		/**
		 * Register any required query variables. Currently, just the account tab endpoint is required
		 *
		 * @param array $vars query variables.
		 *
		 * @return array
		 */
		public function add_query_vars( $vars ) {
			/**
			 * Filter to change the waitlist endpoint
			 * 
			 * @since 2.4.0
			 */
			$vars[] = apply_filters( 'wcwl_waitlist_endpoint', get_option( 'woocommerce_myaccount_waitlist_endpoint', 'woocommerce-waitlist' ) );

			return $vars;
		}

		/**
		 * Setup allowed product types. Delayed on the init hook to allow for customisation
		 */
		public function setup_product_types() {
			WooCommerce_Waitlist_Plugin::$allowed_product_types = $this->get_product_types();
		}

		/**
		 * Define the product types we want to load waitlist into
		 *
		 * @return mixed|void
		 */
		public function get_product_types() {
			/**
			 * Filter the product types we want to load waitlist into
			 * 
			 * @since 2.4.11
			 * @deprecated use wcwl_supported_products instead
			 */
			$product_types = apply_filters_deprecated(
				'woocommerce_waitlist_supported_products',
				array(
					array(
						'simple'                => array(
							'filepath' => 'product-types/class-pie-wcwl-frontend-simple.php',
							'class'    => 'Pie_WCWL_Frontend_Simple',
						),
						'variable'              => array(
							'filepath' => 'product-types/class-pie-wcwl-frontend-variable.php',
							'class'    => 'Pie_WCWL_Frontend_Variable',
						),
						'grouped'               => array(
							'filepath' => 'product-types/class-pie-wcwl-frontend-grouped.php',
							'class'    => 'Pie_WCWL_Frontend_Grouped',
						),
						'subscription'          => array(
							'filepath' => 'product-types/class-pie-wcwl-frontend-simple.php',
							'class'    => 'Pie_WCWL_Frontend_Simple',
						),
						'variable-subscription' => array(
							'filepath' => 'product-types/class-pie-wcwl-frontend-variable.php',
							'class'    => 'Pie_WCWL_Frontend_Variable',
						),
						'bundle'                => array(
							'filepath' => 'product-types/class-pie-wcwl-frontend-bundle.php',
							'class'    => 'Pie_WCWL_Frontend_Bundle',
						),
					)
				),
				'2.4.11',
				'wcwl_supported_products',
				'Use wcwl_supported_products instead.'
			);

			/**
			 * Filter the product types we want to load waitlist into
			 * 
			 * @since 2.4.0
			 */
			return apply_filters( 'wcwl_supported_products', $product_types );
		}

		/**
		 * Appends our Pie_WCWL_Waitlist_Mailout class to the array of WC_Email objects.
		 *
		 * @static
		 *
		 * @param array $emails the woocommerce array of email objects.
		 *
		 * @return array         the woocommerce array of email objects with our email appended
		 */
		public static function initialise_waitlist_email_class( $emails ) {
			require_once 'class-pie-wcwl-waitlist-mailout.php';
			require_once 'class-pie-wcwl-waitlist-joined-email.php';
			require_once 'class-pie-wcwl-waitlist-left-email.php';
			require_once 'class-pie-wcwl-waitlist-signup-email.php';
			$emails['Pie_WCWL_Waitlist_Mailout']      = new Pie_WCWL_Waitlist_Mailout();
			$emails['Pie_WCWL_Waitlist_Joined_Email'] = new Pie_WCWL_Waitlist_Joined_Email();
			$emails['Pie_WCWL_Waitlist_Left_Email']   = new Pie_WCWL_Waitlist_Left_Email();
			$emails['Pie_WCWL_Waitlist_Signup_Email'] = new Pie_WCWL_Waitlist_Signup_Email();
			if ( 'yes' === get_option( WCWL_SLUG . '_double_optin' ) ) {
				require_once 'class-pie-wcwl-waitlist-optin-email.php';
				$emails['Pie_WCWL_Waitlist_Optin_Email'] = new Pie_WCWL_Waitlist_Optin_Email();
			}

			return $emails;
		}

		/**
		 * Removes meta data that waitlist should NOT be outputting with Products API.
		 *
		 * @access public
		 *
		 * @hooked: cocart_products_ignore_private_meta_keys - 1
		 *
		 * @param array      $ignored_meta_keys Ignored meta keys.
		 * @param WC_Product $product           The product object.
		 *
		 * @return array $ignored_meta_keys Ignored meta keys.
		 */
		public function remove_waitlist_metadata_from_cocart_request( $ignored_meta_keys, $product ) {
			$meta_data = $product->get_meta_data();
			$keys      = array( 'woocommerce_waitlist', 'wcwl_waitlist_archive', 'wcwl_mailout_errors' );
			foreach ( $meta_data as $meta ) {
				if ( in_array( $meta->key, $keys ) ) {
					$ignored_meta_keys[] = $meta->key;
				}
			}

			return $ignored_meta_keys;
		}
	}
}
