<?php
/**
 * Admin class premium
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Admin_Premium' ) ) {
	/**
	 * WooCommerce Affiliates Admin Premium
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Admin_Premium extends YITH_WCAF_Admin {

		/**
		 * Constructor method
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			add_filter( 'yith_wcaf_panel_args', array( $this, 'filter_panel_args' ) );
			add_filter( 'yith_wcaf_available_admin_tabs', array( $this, 'filter_admin_tabs' ) );
			add_filter( 'yith_wcaf_affiliates_settings', array( $this, 'filter_settings' ) );

			parent::__construct();
		}

		/**
		 * Startup admin panel
		 *
		 * @return void
		 */
		public function init() {
			// do startup operations.
			YITH_WCAF_Admin_Profile_Premium::init();
			YITH_WCAF_Admin_Meta_Boxes_Premium::init();
			YITH_WCAF_Admin_Coupons::init();
			YITH_WCAF_Admin_Orders::init();

			// load current tab.
			$this->load_tab();
		}

		/**
		 * Add your Store Tools tab.
		 *
		 * @param array $args Panel args.
		 *
		 * @return mixed
		 */
		public function filter_panel_args( $args ) {
			$args['your_store_tools'] = array(
				'items' => array(
					'wishlist'               => array(
						'name'           => 'Wishlist',
						'icon_url'       => YITH_WCAF_ASSETS_URL . '/images/your-store-tools/wishlist.svg',
						'url'            => '//yithemes.com/themes/your-store-tools/yith-woocommerce-wishlist/',
						'description'    => _x(
							'Allow your customers to create lists of products they want and share them with family and friends.',
							'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Wishlist',
							'yith-woocommerce-affiliates'
						),
						'is_active'      => defined( 'YITH_WCWL_PREMIUM' ),
						'is_recommended' => true,
					),
					'gift-cards'             => array(
						'name'           => 'Gift Cards',
						'icon_url'       => YITH_WCAF_ASSETS_URL . '/images/your-store-tools/gift-cards.svg',
						'url'            => '//yithemes.com/themes/your-store-tools/yith-woocommerce-gift-cards/',
						'description'    => _x(
							'Sell gift cards in your shop to increase your earnings and attract new customers.',
							'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Gift Cards',
							'yith-woocommerce-affiliates'
						),
						'is_active'      => defined( 'YITH_YWGC_PREMIUM' ),
						'is_recommended' => true,
					),
					'request-a-quote'        => array(
						'name'           => 'Request a Quote',
						'icon_url'       => YITH_WCAF_ASSETS_URL . '/images/your-store-tools/request-a-quote.svg',
						'url'            => '//yithemes.com/themes/your-store-tools/yith-woocommerce-request-a-quote/',
						'description'    => _x(
							'Hide prices and/or the "Add to cart" button and let your customers request a custom quote for every product.',
							'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Request a Quote',
							'yith-woocommerce-affiliates'
						),
						'is_active'      => defined( 'YITH_YWRAQ_PREMIUM' ),
						'is_recommended' => false,
					),
					'points-rewards'         => array(
						'name'           => 'Points and Rewards',
						'icon_url'       => YITH_WCAF_ASSETS_URL . '/images/your-store-tools/points-rewards.svg',
						'url'            => '//yithemes.com/themes/your-store-tools/yith-woocommerce-points-and-rewards/',
						'description'    => _x(
							'Loyalize your customers with an effective points-based loyalty program and instant rewards.',
							'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Points and Rewards',
							'yith-woocommerce-affiliates'
						),
						'is_active'      => defined( 'YITH_YWPAR_PREMIUM' ),
						'is_recommended' => false,
					),
					'product-addons'         => array(
						'name'           => 'Product Add-Ons & Extra Options',
						'icon_url'       => YITH_WCAF_ASSETS_URL . '/images/your-store-tools/product-add-ons.svg',
						'url'            => '//yithemes.com/themes/your-store-tools/yith-woocommerce-product-add-ons/',
						'description'    => _x(
							'Add paid or free advanced options to your product pages using fields like radio buttons, checkboxes, drop-downs, custom text inputs, and more.',
							'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Product Add-Ons',
							'yith-woocommerce-affiliates'
						),
						'is_active'      => defined( 'YITH_WAPO_PREMIUM' ),
						'is_recommended' => false,
					),
					'dynamic-pricing'        => array(
						'name'           => 'Dynamic Pricing and Discounts',
						'icon_url'       => YITH_WCAF_ASSETS_URL . '/images/your-store-tools/dynamic-pricing-and-discounts.svg',
						'url'            => '//yithemes.com/themes/your-store-tools/yith-woocommerce-dynamic-pricing-and-discounts/',
						'description'    => _x(
							'Increase conversions through dynamic discounts and price rules, and build powerful and targeted offers.',
							'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Dynamic Pricing and Discounts',
							'yith-woocommerce-affiliates'
						),
						'is_active'      => defined( 'YITH_YWDPD_PREMIUM' ),
						'is_recommended' => false,
					),
					'customize-my-account'   => array(
						'name'           => 'Customize My Account Page',
						'icon_url'       => YITH_WCAF_ASSETS_URL . '/images/your-store-tools/customize-myaccount-page.svg',
						'url'            => '//yithemes.com/themes/your-store-tools/yith-woocommerce-customize-my-account-page/',
						'description'    => _x(
							'Customize the My Account page of your customers by creating custom sections with promotions and ad-hoc content based on your needs.',
							'[YOUR STORE TOOLS TAB] Description for plugin YITH WooCommerce Customize My Account',
							'yith-woocommerce-affiliates'
						),
						'is_active'      => defined( 'YITH_WCMAP_PREMIUM' ),
						'is_recommended' => false,
					),
					'recover-abandoned-cart' => array(
						'name'           => 'Recover Abandoned Cart',
						'icon_url'       => YITH_WCAF_ASSETS_URL . '/images/your-store-tools/recover-abandoned-cart.svg',
						'url'            => '//yithemes.com/themes/your-store-tools/yith-woocommerce-recover-abandoned-cart/',
						'description'    => _x(
							'Contact users who have added products to the cart without completing the order and try to recover lost sales.',
							'[YOUR STORE TOOLS TAB] Description for plugin Recover Abandoned Cart',
							'yith-woocommerce-affiliates'
						),
						'is_active'      => defined( 'YITH_YWRAC_PREMIUM' ),
						'is_recommended' => false,
					),
				),
			);
			return $args;
		}

		/* === PLUGIN PANEL METHODS === */

		/**
		 * Returns name of the class that mangas passed admin tab
		 *
		 * @param string|bool $tab Tab to print; false to use current tab.
		 * @return string|bool Class name; false when provided tab is invalid.
		 */
		public function get_tab_class_name( $tab = false ) {
			if ( ! $tab ) {
				$tab = $this->get_current_tab();
			}

			if ( ! $tab ) {
				return false;
			}

			$class_name = 'YITH_WCAF_' . ucfirst( $tab ) . '_Admin_Panel_Premium';

			if ( class_exists( $class_name ) ) {
				return $class_name;
			}

			return parent::get_tab_class_name( $tab );
		}

		/**
		 * Filters tabs for admin section
		 *
		 * @param mixed $tabs Array of available tabs.
		 *
		 * @return mixed Filtered array of tabs
		 * @since 1.0.0
		 */
		public function filter_admin_tabs( $tabs ) {
			// add dashboard tab.
			$tabs = yith_wcaf_append_items(
				$tabs,
				'affiliates',
				array(
					'dashboard' => array(
						'title'       => _x( 'Dashboard', '[ADMIN] Panel tabs', 'yith-woocommerce-affiliates' ),
						'description' => _x(
							'Monitor conversions and revenues from your affiliate programme',
							'[ADMIN] Description for Dashboard tab',
							'yith-woocommerce-affiliates'
						),
						'icon'        => '<svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5m.75-9l3-3 2.148 2.148A12.061 12.061 0 0116.5 7.605"></path></svg>',
					),
				),
				'before'
			);

			// remove premium tab.
			unset( $tabs['premium'] );

			return $tabs;
		}

		/**
		 * Filers plugin options to add premium-specific data
		 *
		 * @param array $options Array of options.
		 * @return array Filtered array of options.
		 */
		public function filter_settings( $options ) {
			$current_action = current_action();
			$panel          = YITH_WCAF_Admin()->get_tab_instance();
			$rules_doc      = $panel && isset( $panel->rules_doc ) ? $panel->rules_doc : '';

			if ( 'yith_wcaf_affiliates_settings' === $current_action ) {
				$options['affiliates'] = array(
					'affiliates_options' => array(
						'type'     => 'multi_tab',
						'sub-tabs' => array_merge(
							array(
								'affiliates-list'  => array(
									'title'       => _x(
										'Affiliates List',
										'[ADMIN] Affiliate tab title',
										'yith-woocommerce-affiliates'
									),
									'description' => _x(
										'The complete list of users registered in your affiliate programme.',
										'[ADMIN] Affiliate tab description',
										'yith-woocommerce-affiliates'
									),
								),
								'affiliates-rates' => array(
									'title'       => _x(
										'Rates',
										'[ADMIN] Affiliate Rates tab title',
										'yith-woocommerce-affiliates'
									),
									'description' => sprintf(
									// translators: 1. Url to documentation page about rules.
										_x(
											'Rate rules allow to override the global rate (defined in General options) for specific users, user roles or products.<br/>Please note: rules applied to products are higher in priority by default. <a target="_blank" href="%s">Read the documentation to better understand how rules work ></a>',
											'[ADMIN] Rates table description',
											'yith-woocommerce-affiliates'
										),
										$rules_doc
									),
								),
							),
							YITH_WCAF_Clicks()->are_hits_registered() ? array(
								'affiliates-clicks' => array(
									'title'       => _x(
										'Visits',
										'[ADMIN] Affiliate Clicks tab title',
										'yith-woocommerce-affiliates'
									),
									'description' => _x(
										'Monitor shop accesses generated by your affiliates.',
										'[ADMIN] Affiliate Clicks tab description',
										'yith-woocommerce-affiliates'
									),
								),
							) : array()
						),
					),
				);
			}

			return $options;
		}

		/* === PLUGIN LINK METHODS === */

		/**
		 * Adds plugin row meta
		 *
		 * @param array  $new_row_meta_args Array of data to filter.
		 * @param array  $plugin_meta       Array of plugin meta.
		 * @param string $plugin_file       Path to init file.
		 * @param array  $plugin_data       Array of plugin data.
		 * @param string $status            Not used.
		 * @param string $init_file         Constant containing plugin int path.
		 *
		 * @return array Filtered array of plugin meta
		 * @since 1.0.0
		 */
		public function add_plugin_meta( $new_row_meta_args, $plugin_meta, $plugin_file, $plugin_data, $status, $init_file = 'YITH_WCAF_PREMIUM_INIT' ) {
			$new_row_meta_args = parent::add_plugin_meta( $new_row_meta_args, $plugin_meta, $plugin_file, $plugin_data, $status, $init_file );

			if ( defined( $init_file ) && constant( $init_file ) === $plugin_file ) {
				$new_row_meta_args['is_premium'] = true;
			}

			return $new_row_meta_args;
		}
	}
}
