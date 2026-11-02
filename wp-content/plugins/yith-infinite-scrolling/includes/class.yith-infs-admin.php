<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Admin class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH Infinite Scrolling
 * @version 1.0.0
 */

defined( 'YITH_INFS' ) || exit; // Exit if accessed directly.

if ( ! class_exists( 'YITH_INFS_Admin' ) ) {
	/**
	 * Admin class.
	 * The class manage all the admin behaviors.
	 *
	 * @since 1.0.0
	 */
	class YITH_INFS_Admin {

		/**
		 * Single instance of the class
		 *
		 * @since 1.0.0
		 * @var YITH_INFS_Admin
		 */
		protected static $instance;

		/**
		 * Plugin options
		 *
		 * @since  1.0.0
		 * @var array
		 * @access public
		 */
		public $options = array();

		/**
		 * Plugin version
		 *
		 * @since 1.0.0
		 * @var string
		 */
		public $version = YITH_INFS_VERSION;

		/**
		 * Panel Object
		 *
		 * @var YIT_Plugin_Panel
		 */
		protected $panel;



		/**
		 * Premium version landing link
		 *
		 * @var string
		 */
		protected $premium_landing = 'https://yithemes.com/themes/plugins/yith-infinite-scrolling/';

		/**
		 * Infinite Scrolling panel page
		 *
		 * @var string
		 */
		protected $panel_page = 'yith_infs_panel';

		/**
		 * Various links
		 *
		 * @since  1.0.0
		 * @var string
		 * @access public
		 */
		public $doc_url = 'https://yithemes.com/docs-plugins/yith-infinite-scrolling/';

		/**
		 * Returns single instance of the class
		 *
		 * @since 1.0.0
		 * @return YITH_INFS_Admin
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
		 * @access public
		 * @since  1.0.0
		 */
		public function __construct() {

			add_action( 'admin_menu', array( $this, 'register_panel' ), 5 );

			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_style' ) );

			// Add action links.
			add_filter( 'plugin_action_links_' . plugin_basename( YITH_INFS_DIR . '/' . basename( YITH_INFS_FILE ) ), array( $this, 'action_links' ) );
			add_filter( 'yith_show_plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 5 );



			// YITH INFS Loaded.
			/**
			 * DO_ACTION: yith_infs_loaded
			 *
			 * Allows to trigger some action when the plugin is loaded.
			 */
			do_action( 'yith_infs_loaded' );
		}

		/**
		 * Enqueue style
		 *
		 * @since  1.0.0
		 * @access public
		 */
		public function enqueue_style() {
			if ( isset( $_GET['page'] ) && sanitize_text_field( wp_unslash( $_GET['page'] ) ) === $this->panel_page ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wp_enqueue_style( 'yith-infs-admin', YITH_INFS_ASSETS_URL . '/css/admin.css', array(), YITH_INFS_VERSION );
			}
		}

		/**
		 * Action Links
		 * add the action links to plugin admin page
		 *
		 * @since    1.0
		 * @param array $links Links plugin array.
		 * @return   array
		 */
		public function action_links( $links ) {
			$links = yith_add_action_links( $links, $this->panel_page, true, YITH_INFS_SLUG );

			return $links;
		}



		/**
		 * Add a panel under YITH Plugins tab
		 *
		 * @since    1.0
		 * @use      /Yit_Plugin_Panel class
		 * @return   void
		 * @see      plugin-fw/lib/yit-plugin-panel.php
		 */
		public function register_panel() {

			if ( ! empty( $this->panel ) ) {
				return;
			}

			$admin_tabs = array(
				'general'  => array(
					'title'       => __( 'General Settings', 'yith-infinite-scrolling' ),
					'icon'        => 'settings',
					'description' => __( 'Set the general behaviour of the plugin.', 'yith-infinite-scrolling' ),
				)
			);



			if ( ! ( defined( 'YITH_INFS_PREMIUM' ) && YITH_INFS_PREMIUM ) ) {
				$admin_tabs['premium'] = __( 'Premium Version', 'yith-infinite-scrolling' );
			}else{
				$admin_tabs['sections'] = array(
					'title'       => __( 'Sections', 'yith-infinite-scrolling' ),
					'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
  <path stroke-linecap="round" stroke-linejoin="round" d="M14.25 9.75 16.5 12l-2.25 2.25m-4.5 0L7.5 12l2.25-2.25M6 20.25h12A2.25 2.25 0 0 0 20.25 18V6A2.25 2.25 0 0 0 18 3.75H6A2.25 2.25 0 0 0 3.75 6v12A2.25 2.25 0 0 0 6 20.25Z" />
</svg>',
					'description' => __( 'Add sections and set its options.', 'yith-infinite-scrolling' ),
				);
			}

			$args = array(
				'ui_version'       => 2,
				'create_menu_page' => true,
				'parent_slug'      => '',
				'page_title'       => 'YITH Infinite Scrolling',
				'menu_title'       => 'Infinite Scrolling',
				'parent'           => 'infs',
				'parent_page'      => 'yith_plugin_panel',
				'plugin_slug'      => YITH_INFS_SLUG,
				'plugin-url'       => YITH_INFS_URL,
				'page'             => $this->panel_page,
				'admin-tabs'       => $admin_tabs,
				'options-path'     => YITH_INFS_DIR . 'plugin-options',
				'class'            => yith_set_wrapper_class(),
				'is_free'          => ! defined( 'YITH_INFS_PREMIUM' ),
				'is_premium'       => defined( 'YITH_INFS_PREMIUM' ),
				'premium_tab'      => array(
					'features' => $this->get_premium_features(),
				),
			);

			if ( ( defined( 'YITH_INFS_PREMIUM' ) && YITH_INFS_PREMIUM ) ) {
				$args['your_store_tools'] = array(
					'items' => array(
						'ajax-search'          => array(
							'name'           => 'Ajax Search',
							'icon_url'       => YITH_INFS_ASSETS_URL . '/images/plugins/ajax-search.svg',
							'url'            => '//yithemes.com/themes/plugins/yith-woocommerce-ajax-search/',
							'description'    => _x(
								'Add an <strong>intelligent search engine</strong> to your store so that your customers can quickly find the products they are looking for.',
								'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Ajax Search',
								'yith-infinite-scrolling'
							),
							'is_active'      => defined( 'YITH_WCAS_INIT' ),
							'is_recommended' => true,
						),
						'gift-cards'           => array(
							'name'           => 'Gift Cards',
							'icon_url'       => YITH_INFS_ASSETS_URL . '/images/plugins/gift-card.svg',
							'url'            => '//yithemes.com/themes/plugins/yith-woocommerce-gift-cards/',
							'description'    => _x(
								'Sell gift cards to increase your store\'s revenue and win new customers.',
								'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Gift Cards',
								'yith-infinite-scrolling'
							),
							'is_active'      => defined( 'YITH_YWGC_PREMIUM' ),
							'is_recommended' => true,
						),
						'ajax-product-filter'  => array(
							'name'           => 'Ajax Product Filter',
							'icon_url'       => YITH_INFS_ASSETS_URL . '/images/plugins/ajax-product-filter.svg',
							'url'            => '//yithemes.com/themes/plugins/yith-woocommerce-ajax-product-filter/',
							'description'    => _x(
								'Help your customers to easily find the products they are looking for and improve the user experience of your shop.',
								'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Ajax Product Filter',
								'yith-infinite-scrolling'
							),
							'is_active'      => defined( 'YITH_WCAN_PREMIUM' ),
							'is_recommended' => false,
						),
						'wishlist'             => array(
							'name'           => 'Wishlist',
							'icon_url'       => YITH_INFS_ASSETS_URL . '/images/plugins/wishlist.svg',
							'url'            => '//yithemes.com/themes/plugins/yith-woocommerce-wishlist/',
							'description'    => _x(
								'Allow your customers to create lists of products they want and share them with family and friends.',
								'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Wishlist',
								'yith-infinite-scrolling'
							),
							'is_active'      => defined( 'YITH_WCWL_PREMIUM' ),
							'is_recommended' => false,
						),
						'product-addons'       => array(
							'name'           => 'Product Add-Ons & Extra Options',
							'icon_url'       => YITH_INFS_ASSETS_URL . '/images/plugins/product-add-ons.svg',
							'url'            => '//yithemes.com/themes/plugins/yith-woocommerce-product-add-ons/',
							'description'    => _x(
								'Add paid or free advanced options to your product pages using fields like radio buttons, checkboxes, drop-downs, custom text inputs, and more.',
								'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Product Add-Ons',
								'yith-infinite-scrolling'
							),
							'is_active'      => defined( 'YITH_WAPO_PREMIUM' ),
							'is_recommended' => false,
						),
						'request-a-quote'      => array(
							'name'           => 'Request a Quote',
							'icon_url'       => YITH_INFS_ASSETS_URL . '/images/plugins/request-a-quote.svg',
							'url'            => '//yithemes.com/themes/plugins/yith-woocommerce-request-a-quote/',
							'description'    => _x(
								'Hide prices and/or the "Add to cart" button and let your customers request a custom quote for every product.',
								'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Request a Quote',
								'yith-infinite-scrolling'
							),
							'is_active'      => defined( 'YITH_YWRAQ_PREMIUM' ),
							'is_recommended' => false,
						),
						'dynamic-pricing'      => array(
							'name'           => 'Dynamic Pricing & Discounts',
							'icon_url'       => YITH_INFS_ASSETS_URL . '/images/plugins/dynamic-pricing-and-discounts.svg',
							'url'            => '//yithemes.com/themes/plugins/yith-woocommerce-dynamic-pricing-and-discounts/',
							'description'    => _x(
								'The best-selling plugin for creating promotions and upsell strategies in your store: 3x2, 2x1, BOGO, free products in the cart, quantity discounts, last-minute offers, and more.',
								'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Dynamic Pricing & Discounts',
								'yith-infinite-scrolling'
							),
							'is_active'      => defined( 'YITH_YWDPD_PREMIUM' ),
							'is_recommended' => false,
						),

						'customize-my-account' => array(
							'name'           => 'Customize My Account Page',
							'icon_url'       => YITH_INFS_ASSETS_URL . '/images/plugins/customize-myaccount-page.svg',
							'url'            => '//yithemes.com/themes/plugins/yith-woocommerce-customize-my-account-page/',
							'description'    => _x(
								'Customize the My Account page of your customers by creating custom sections with promotions and ad-hoc content based on your needs.',
								'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Customize My Account',
								'yith-infinite-scrolling'
							),
							'is_active'      => defined( 'YITH_WCMAP_PREMIUM' ),
							'is_recommended' => false,
						),

					),
				);
				$args['welcome_modals'] = array(
					'on_close' => function () {
						update_option( 'yith-infinite-scrolling-welcome-modal', 'no' );
					},
					'modals'   => array(
						'welcome' => array(
							'type'        => 'welcome',
							'description' => __( 'Enable an infinite scrolling feature on your products and posts.', 'yith-infinite-scrolling' ),
							'show'        => get_option( 'yith-infinite-scrolling-welcome-modal', 'welcome' ) === 'welcome',
							'items'       => array(
								'documentation'  => array(),
								'create-product' => array(
									'title'       => __( 'Create a section where you want to apply the infinite scrolling effect', 'yith-infinite-scrolling' ),
									'description' => __( '...and embark on this new adventure!', 'yith-infinite-scrolling' ),
									'url'         => add_query_arg( array( 'page' => $this->panel_page ), admin_url( 'admin.php' ) ),
								),
							),
						),
					),
				);
			}
			if ( ! class_exists( 'YIT_Plugin_Panel' ) ) {
				require_once YITH_INFS_DIR . '/plugin-fw/lib/yit-plugin-panel.php';
			}

			$this->panel = new YIT_Plugin_Panel( $args );
		}



		/**
		 * Get premium tab features array
		 *
		 * @since 3.0.0
		 * @return array
		 */
		protected function get_premium_features() {
			return array(
				array(
					'title'       => __( 'Infinite sections', 'yith-infinite-scrolling' ),
					'description' => __( 'Do not be restricted to choose if you want to activate the scrolling in the comments, in the shop
					products or in the posts of your blog. You can create infinite sections for the contents of your
					site and have a settings panel for each of these. ', 'yith-infinite-scrolling' ),
				),
				array(
					'title'       => __( 'The loader as you want', 'yith-infinite-scrolling' ),
					'description' => __( 'Four different loader types for the scrolling of your page, and in case you are not pleased by them,
					you can always load a custom one with the related button.', 'yith-infinite-scrolling' ),
				),
				array(
					'title'       => __( 'Types of pagination', 'yith-infinite-scrolling' ),
					'description' => __( 'The Infinite scrolling is not the only option for the paging of you contents. You can choose to load
					them gradually in the same page with the related button, or offer an Ajax paging to your users.', 'yith-infinite-scrolling' ),
				),
				array(
					'title'       => __( 'Loading effect', 'yith-infinite-scrolling' ),
					'description' => __( 'Choose the animation effect you want for the loading of the contents of your section. You can choose
					among seven different options.', 'yith-infinite-scrolling' ),
				),
				array(
					'title'       => __( 'Page URL', 'yith-infinite-scrolling' ),
					'description' => __( 'The main purpose of the infinite scrolling is to allow users to consult several pages contents
					without waiting for the new loading of the webpage. Now, page url becomes dynamic too thanks to its
					automatic update when the contents of next page are loaded.', 'yith-infinite-scrolling' ),
				),

			);
		}

		/**
		 * Add the action links to plugin admin page
		 *
		 * @since    1.0
		 * @use      plugin_row_meta
		 * @param array    $new_row_meta_args An array of plugin row meta.
		 * @param string[] $plugin_meta An array of the plugin's metadata,
		 *                                    including the version, author,
		 *                                    author URI, and plugin URI.
		 * @param string   $plugin_file Path to the plugin file relative to the plugins directory.
		 * @param array    $plugin_data An array of plugin data.
		 * @param string   $status Status of the plugin. Defaults are 'All', 'Active',
		 *                                    'Inactive', 'Recently Activated', 'Upgrade', 'Must-Use',
		 *                                    'Drop-ins', 'Search', 'Paused'.
		 * @return   array
		 */
		public function plugin_row_meta( $new_row_meta_args, $plugin_meta, $plugin_file, $plugin_data, $status ) {

			if ( defined( 'YITH_INFS_INIT' ) && YITH_INFS_INIT === $plugin_file ) {
				$new_row_meta_args['slug'] = YITH_INFS_SLUG;

				if ( defined( 'YITH_INFS_PREMIUM' ) ) {
					$new_row_meta_args['is_premium'] = true;
				}
			}

			return $new_row_meta_args;
		}

		/**
		 * Get the premium landing uri
		 *
		 * @since   1.0.0
		 * @return  string The premium landing link
		 */
		public function get_premium_landing_uri() {
			return defined( 'YITH_REFER_ID' ) ? $this->premium_landing . '?refer_id=' . YITH_REFER_ID : $this->premium_landing . '?refer_id=1030585';
		}

		/**
		 * Get options from db
		 *
		 * @access public
		 * @since  1.0.0
		 * @param string $option The option key.
		 * @param mixed  $default Default option value.
		 * @return mixed
		 */
		public static function get_option( $option, $default = false ) {
			return yinfs_get_option( $option, $default );
		}
	}
}
/**
 * Unique access to instance of YITH_WCQV_Admin class
 *
 * @since 1.0.0
 * @return YITH_INFS_Admin
 */
function YITH_INFS_Admin() { // phpcs:ignore
	return YITH_INFS_Admin::get_instance();
}
