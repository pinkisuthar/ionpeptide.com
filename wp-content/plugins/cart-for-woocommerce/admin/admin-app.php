<?php

namespace FKCart\Admin;

use FKCart\Includes\Data;
use FKCart\Includes\Traits\Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
if ( ! class_exists( '\FKCart\Admin\Admin_App' ) ) {
	/**
	 * Class Admin
	 */
	class Admin_App {

		use Instance;

		public $admin_path;
		public $admin_url;

		public function __construct() {
			$this->admin_path = FKCART_PLUGIN_DIR . '/admin';
			$this->admin_url  = FKCART_PLUGIN_URL . '/admin';

			/** Add FunnelKit cart menu */
			add_action( 'admin_menu', [ $this, 'register_admin_menu' ], 902 );

			/** Plugin action links */
			add_filter( "plugin_action_links_" . FKCART_PLUGIN_BASENAME, [ $this, 'add_plugin_action_links' ] );

			/** Admin enqueue scripts */
			add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_assets' ], 99 );

			/** Admin icon related css */
			add_action( 'admin_head', array( $this, 'change_menu_icon' ), - 1 );
		}

		/**
		 * Clear cache
		 *
		 * @return void
		 */
		public static function maybe_clear_cache() {
			/**
			 * Clear wordpress cache
			 */
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}

			/**
			 * Checking if wp fastest cache installed
			 * Clear cache of wp fastest cache
			 */
			if ( class_exists( '\WpFastestCache' ) ) {
				global $wp_fastest_cache;
				if ( method_exists( $wp_fastest_cache, 'deleteCache' ) ) {
					$wp_fastest_cache->deleteCache();
				}

				// clear all cache
				if ( function_exists( 'wpfc_clear_all_cache' ) ) {
					wpfc_clear_all_cache( true );
				}
			}

			/**
			 * Checking if wp Autoptimize installed
			 * Clear cache of Autoptimize
			 */

			if ( class_exists( '\autoptimizeCache' ) && method_exists( '\autoptimizeCache', 'clearall' ) ) {
				\autoptimizeCache::clearall();
			}

			/**
			 * Checking if W3Total Cache plugin activated.
			 * Clear cache of W3Total Cache plugin
			 */
			if ( function_exists( 'w3tc_flush_all' ) ) {
				w3tc_flush_all();
			}

			/**
			 * Checking if wp rocket caching add on installed
			 * Cleaning the url for current opened URL
			 */
			if ( function_exists( 'rocket_clean_home' ) ) {
				$referer = wp_get_referer();


				if ( 0 !== strpos( $referer, 'http' ) ) {
					$rocket_pass_url = get_rocket_parse_url( untrailingslashit( home_url() ) );

					if ( is_array( $rocket_pass_url ) && 0 < count( $rocket_pass_url ) ) {
						list( $host, $path, $scheme, $query ) = $rocket_pass_url;
						$referer = $scheme . '://' . $host . $referer;
					}

				}

				if ( home_url( '/' ) === $referer ) {
					rocket_clean_home();
				} else {
					rocket_clean_files( $referer );
				}
			}

			/**
			 * LiteSpeed cache plugin
			 */
			if ( class_exists( '\LiteSpeed\Purge' ) ) {
				\LiteSpeed\Purge::purge_all();
			}

			/**
			 * Checking if Wp Super Cache plugin activated.
			 * Clear cache of Wp Super Cache plugin
			 */
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache();
			}
		}

		/**
		 * Add sub menu
		 *
		 * @return void
		 */
		public function register_admin_menu() {
			$capability = 'manage_options';

			/** When FB active */
			if ( ! $this->is_any_fk_plugin() ) {
				add_menu_page( false, __( 'FunnelKit', 'cart-for-woocommerce' ), $capability, 'fkcart', array(
					$this,
					'fkcart_page'
				), esc_url( FKCART_PLUGIN_URL . '/admin/assets/img/bwf-icon-grey.svg' ), 58 );

				add_submenu_page( 'fkcart', __( 'Cart', 'cart-for-woocommerce' ), __( 'Cart', 'cart-for-woocommerce' ), $capability, 'fkcart', [ $this, 'fkcart_page' ], 100 );

				$checkout_icon = '<span style="padding-left: 2px;color: #f18200; vertical-align: super; font-size: 9px;"> ' . __( 'NEW', 'cart-for-woocommerce' ) . '!</span>';
				add_submenu_page( 'fkcart', __( 'Checkout', 'cart-for-woocommerce' ), __( 'Checkout', 'cart-for-woocommerce' ) . $checkout_icon, $capability, 'fkcart&path=/checkout', [
					$this,
					'fkcart_page'
				], 15 );

				$time = strtotime( gmdate( 'c' ) );
				if ( $time >= 1700456400 && $time < 1701493200 ) {
					$utm_campaign = 'CM' . date( 'Y' );
					$title        = __( "Cyber Monday", 'cart-for-woocommerce' );
					if ( $time < 1701061200 ) {
						$utm_campaign = 'BF' . date( 'Y' );
						$title        = __( "Black Friday", 'cart-for-woocommerce' );
					}
					$title .= " 🔥";
					$link  = add_query_arg( [
						'utm_source'   => 'WordPress',
						'utm_medium'   => 'Admin+Menu+FKCart',
						'utm_campaign' => $utm_campaign
					], "https://funnelkit.com/exclusive-offer/" );
					add_submenu_page( 'fkcart', '', '<a href="' . $link . '"  target="_blank">' . $title . '</a>', $capability, 'upgrade_pro', function () {
					}, 50 );
				}

				return;
			}

			/** When FB not active */
			add_filter( 'wffn_header_menu', [ $this, 'add_wffn_header_cart_menu' ] );

			add_submenu_page( 'woofunnels', __( 'Cart', 'cart-for-woocommerce' ), __( 'Cart', 'cart-for-woocommerce' ), $capability, 'fkcart', [ $this, 'fkcart_page' ], 99 );
		}

		/**
		 * Check whether any FK plugin exists
		 *
		 * @return bool
		 */
		public function is_any_fk_plugin() {
			return ( class_exists( '\WFFN_Core' ) || class_exists( '\WFACP_Core' ) || class_exists( '\WFOCU_Core' ) || class_exists( '\WFOB_Core' ) );
		}

		/**
		 * Load page
		 *
		 * @return void
		 */
		public function fkcart_page() {
			?>
            <div id="fkcart-page" class="fkcart-page"></div>
			<?php
		}

		/**
		 * Register plugin action links
		 *
		 * @param $links
		 *
		 * @return array|string[]
		 */
		public function add_plugin_action_links( $links ) {
			$plugin_links = [];

			if ( false === defined( 'WFFN_PRO_BUILD_VERSION' ) ) {
				$link = add_query_arg( [
					'utm_source'   => 'WordPress',
					'utm_medium'   => 'Plugin+Action+Links',
					'utm_campaign' => 'WP+Cart+Repo',
					'utm_content'  => 'Upgrade'
				], "https://funnelkit.com/funnelkit-cart-upgrade/" );

				$plugin_links['fkcart_pro_upgrade'] = '<a href="' . $link . '" target="_blank" style="color: #1da867 !important;font-weight:600">' . __( 'Upgrade to Pro', 'cart-for-woocommerce' ) . '</a>';
			}
			$plugin_links['fkcart_settings_link'] = '<a href="' . admin_url( 'admin.php?page=fkcart' ) . '">' . __( 'Settings', 'cart-for-woocommerce' ) . '</a>';

			return array_merge( $plugin_links, $links );
		}

		/**
		 * Load admin script
		 *
		 * @return void
		 */
		public function admin_enqueue_assets() {
			$page = filter_input( INPUT_GET, 'page' );
			if ( empty( $page ) || 'fkcart' !== strval( $page ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$build_dir  = $this->admin_path . '/app/dist';
			$app_name   = 'main-20260609142950';
			$app_name   = str_replace( '-{{{APP_VERSION}}}', '', $app_name );
			$script_dir = ( 1 === FKCART_REACT_ENVIRONMENT ) ? FKCART_REACT_PROD_URL : FKCART_REACT_DEV_URL;

			if ( ! is_dir( $build_dir ) || ! file_exists( $build_dir . "/$app_name.js" ) || ! file_exists( $build_dir . "/$app_name.css" ) ) {
				?>
                <script>
                    document.addEventListener("DOMContentLoaded", function () {
                        var appLoader = document.getElementById('fkcart-page');
                        if (appLoader) {
                            appLoader.innerHTML = "<div class='notice notice-error'>" +
                                "<p><strong>Warning! Build files are missing.</strong></p>" +
                                "</div>";
                        }
                    });
                </script>
				<?php
				return;
			}
			do_action( 'fkcart_before_app_script_loaded' );

			/** Include tinymce editor */
			wp_enqueue_editor();
			wp_tinymce_inline_scripts();

			/** Enqueue wp media */
			wp_enqueue_media();

			/** Common */
			if ( class_exists( '\WooCommerce' ) ) {
				wp_dequeue_style( 'woocommerce_admin_styles' );
				wp_dequeue_style( 'wc-components' );
			}

			wp_enqueue_style( 'wp-components' );

			$deps    = $this->get_deps( $app_name );
			$version = ( isset( $deps['version'] ) ? $deps['version'] : time() );
			wp_register_script( "fkcart_$app_name", $script_dir . "/$app_name.js", $deps['dependencies'], $version, true );
			wp_enqueue_style( "fkcart_{$app_name}_css", $script_dir . "/$app_name.css", array(), $version );

			wp_localize_script( "fkcart_$app_name", 'fkcart_app_data', apply_filters( 'fkcart_app_localize_data', $this->get_localized_data() ) );
			wp_enqueue_script( "fkcart_$app_name" );

			Data::load_admin_assets();
			wp_set_script_translations( "fkcart_$app_name", 'cart-for-woocommerce' );
			$this->setup_js_for_localization( "fkcart_$app_name", $deps['dependencies'], $version );

			do_action( 'fkcart_after_app_script_loaded' );
		}

		/**
		 * Load dependencies
		 *
		 * @param $app_name
		 *
		 * @return array
		 */
		public function get_deps( $app_name ) {
			$assets_path = $this->admin_path . "/app/dist/$app_name.asset.php";
			$assets      = require_once $assets_path;
			$deps        = ( isset( $assets['dependencies'] ) ? array_merge( $assets['dependencies'], array( 'jquery' ) ) : array( 'jquery' ) );
			$version     = ( isset( $assets['version'] ) ? $assets['version'] : FKCART_VERSION );

			$script_deps = array_filter( $deps, function ( $dep ) use ( &$style_deps ) {
				return false === strpos( $dep, 'css' );
			} );

			return array(
				'dependencies' => $script_deps,
				'version'      => $version,
			);
		}

		/**
		 * Localize data for admin
		 *
		 * @return array
		 */
		public function get_localized_data() {
			$menus = get_terms( 'nav_menu' );
			$menus = array_combine( wp_list_pluck( $menus, 'term_id' ), wp_list_pluck( $menus, 'name' ) );

			$localized_data = [
				'header_data'                => [
					'logo'      => esc_url( plugin_dir_url( FKCART_PLUGIN_FILE ) . 'admin/assets/img/funnelkit-logo.svg' ),
					'logo_link' => admin_url( 'admin.php?page=fkcart' ),
					'left_nav'  => class_exists( '\WFFN_Core' ) ? apply_filters( 'fkcart_app_header_menu', [
						'dashboard'      => [
							'name' => __( 'Dashboard', 'cart-for-woocommerce' ),
							'link' => admin_url( 'admin.php?page=bwf' ),
						],
						'funnels'        => [
							'name' => __( 'Funnels', 'cart-for-woocommerce' ),
							'link' => admin_url( 'admin.php?page=bwf&path=/funnels' ),
						],
						'store-checkout' => [
							'name' => __( 'Store Checkout', 'cart-for-woocommerce' ),
							'link' => admin_url( 'admin.php?page=bwf&path=/store-checkout' ),
						],
						'analytics'      => [
							'name' => __( 'Analytics', 'cart-for-woocommerce' ),
							'link' => admin_url( 'admin.php?page=bwf&path=/analytics' ),
						],
						'templates'      => [
							'name' => __( 'Templates', 'cart-for-woocommerce' ),
							'link' => admin_url( 'admin.php?page=bwf&path=/templates' ),
						],
						'automations'    => [
							'name' => __( 'Automations', 'cart-for-woocommerce' ),
							'link' => admin_url( 'admin.php?page=bwf&path=/automations' ),
						],
						'cart'           => [
							'name' => __( 'Cart', 'cart-for-woocommerce' ),
							'link' => admin_url( 'admin.php?page=fkcart' ),
						],
					] ) : [
						'cart' => [
							'name' => __( 'Cart', 'cart-for-woocommerce' ),
							'link' => admin_url( 'admin.php?page=fkcart' ),
						]
					],
					'right_nav' => [
						'settings' => [
							'name'   => __( 'Settings', 'cart-for-woocommerce' ),
							'icon'   => 'settings',
							'link'   => admin_url( 'admin.php?page=bwf&path=/settings' ),
							'desc'   => '',
							'target' => '_blank'
						],
						'setup'    => [
							'name'   => __( 'Setup & Help', 'cart-for-woocommerce' ),
							'icon'   => 'help-circle',
							'link'   => admin_url( 'admin.php?page=bwf&path=/setup' ),
							'desc'   => '',
							'target' => '_blank'
						],
					],
					'data'      => [
						'back_link'                            => '',
						'level_1_navigation_active'            => '',
						'level_2_title'                        => '',
						'level_2_post_title'                   => '',
						'level_2_right_wrap_type'              => 'menu',
						'level_2_right_side_navigation'        => [],
						'level_2_navigation_pos'               => 'left',
						'level_2_right_side_navigation_active' => '',
						'level_2_right_html'                   => '',
					],
					'pluginDir' => FKCART_PLUGIN_DIR,
				],
				'cart_settings'              => Data::get_settings(),
				'cart_html'                  => '<div id="fkcart-modal" class="fkcart-modal fkcart-show">' . fkcart_get_active_skin_html() . '</div>',
				'ajax_url'                   => admin_url( 'admin-ajax.php' ),
				'ajax_nonce'                 => wp_create_nonce( 'fkcart-action-admin' ),
				'is_preview'                 => fkcart_is_preview(),
				'lang_options'               => Data::get_language_options(),
				'coupon_enabled'             => ( true === wc_coupons_enabled() ) ? 1 : 0,
				'shipping_enabled'           => ( true === wc_shipping_enabled() ) ? 1 : 0,
				'default_customer_location'  => get_option( 'woocommerce_default_customer_address' ),
				'is_free_shipping_available' => true === Data::check_free_shipping_method_available() ? 1 : 0,
				'fb_active'                  => $this->is_any_fk_plugin() ? 1 : 0,
				'site_url'                   => site_url(),
				'current_logged_user'        => get_current_user_id(),
				'is_pro'                     => defined( 'WFFN_PRO_VERSION' ) && class_exists( '\WFFN_Core' ),
				'pro_version'                => defined( 'WFFN_PRO_VERSION' ) ? WFFN_PRO_VERSION : '0.0.0',
				'lev'                        => ( class_exists( '\WFFN_Core' ) && method_exists( \WFFN_Core()->admin, 'get_license_config' ) ) ? \WFFN_Core()->admin->get_license_config() : [],
				'pro_min_valid'              => fkcart_fb_pro_min_version_verified() ? 1 : 0,
				'pro_min_version'            => FKCART_MIN_FB_PRO_VERSION,
				'cart_icon_list'             => Data::get_cart_icon_list(),
				'wp_menu_options'            => $menus,
				'currency'                   => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'img_path'                   => esc_url( plugin_dir_url( FKCART_PLUGIN_FILE ) ) . '/assets/img/',
				'no_product_img'             => esc_url( plugin_dir_url( FKCART_PLUGIN_FILE ) . 'admin/assets/img/dummy/no-product-found.jpg' ),
			];
			if ( class_exists( '\WFFN_Pro_Admin' ) && method_exists( \WFFN_Pro_Core()->admin, 'add_license_related_code' ) ) {
				$localized_data['localize_texts'] = WFFN_Pro_Core()->admin->add_license_related_code( [] );
			}
			if ( isset( $localized_data['header_data']['left_nav']['automations'] ) && method_exists( '\WFFN_Common', 'skip_automation_page' ) && true === \WFFN_Common::skip_automation_page() ) {
				unset( $localized_data['header_data']['left_nav']['automations'] );
			}

			$localized_data['user_has_notifications'] = get_user_meta( get_current_user_id(), '_fkcart_notifications_close', true );
			if ( empty( $localized_data['user_has_notifications'] ) ) {
				$localized_data['user_has_notifications'] = [];
			}

			if ( ! class_exists( '\WFFN_Core' ) ) {
				$localized_data['header_data']['left_nav']['checkout'] = [
					'name' => 'Checkout',
					'link' => admin_url( 'admin.php?page=fkcart&path=/checkout' ),
				];
			}

			/** FK Stripe */
			$localized_data['stripe'] = [ 'status' => 'not_installed', 'express_checkout_enabled' => false ];

			$all_plugins = get_plugins();
			if ( isset( $all_plugins['funnelkit-stripe-woo-payment-gateway/funnelkit-stripe-woo-payment-gateway.php'] ) ) {
				$localized_data['stripe'] = [ 'status' => 'not_activated' ];
				if ( is_plugin_active( 'funnelkit-stripe-woo-payment-gateway/funnelkit-stripe-woo-payment-gateway.php' ) ) {
					$localized_data['stripe'] = [ 'status' => 'not_connected', 'link' => \FKWCS\Gateway\Stripe\Admin::get_instance()->get_connect_url() ];
					if ( \FKWCS\Gateway\Stripe\Admin::get_instance()->is_stripe_connected() ) {
						$localized_data['stripe'] = [ 'status' => 'connected' ];
					}
				}
				$localized_data['stripe']['express_checkout_enabled'] = false;
				$localized_data['stripe']['version']                  = defined( 'FKWCS_VERSION' ) ? FKWCS_VERSION : '1.0.0';
				$localized_data['stripe']['minversion']               = '1.3.0';
			}

			if ( class_exists( '\FKWCS_Gateway_Stripe' ) ) {
				$local_settings = \FKWCS\Gateway\Stripe\Helper::get_gateway_settings();
				if ( 'yes' === $local_settings['express_checkout_enabled'] && 'yes' === $local_settings['enabled'] ) {
					$localized_data['stripe']['express_checkout_enabled'] = true;
				}
			}

			return $localized_data;
		}

		/**
		 * Admin menu icon CSS
		 *
		 * @return void
		 */
		public function change_menu_icon() {
			?>
            <style>
                .wp-admin #adminmenu #toplevel_page_fkcart a img {
                    max-width: 22px;
                }

                .wp-has-submenu.toplevel_page_fkcart ul.wp-submenu > li a:empty, .wp-has-submenu.toplevel_page_woofunnels ul.wp-submenu > li a:empty {
                    display: none !important
                }
            </style>
			<?php

			/** Make admin footer blank */
			add_filter( 'admin_footer_text', [ $this, 'hide_admin_footer_on_cart' ], PHP_INT_MAX );
			add_filter( 'update_footer', [ $this, 'hide_admin_footer_on_cart' ], PHP_INT_MAX );
		}

		/**
		 * Hide admin footer on cart
		 *
		 * @param $str
		 *
		 * @return string
		 */
		public function hide_admin_footer_on_cart( $str ) {
			$page = filter_input( INPUT_GET, 'page' );
			if ( ! empty( $page ) && 'fkcart' === strval( $page ) ) {
				return '';
			}

			return $str;
		}

		/**
		 * Add cart menu on funnel builder
		 *
		 * @return array
		 */
		public function add_wffn_header_cart_menu( $menu ) {
			$menu['cart'] = [
				'name' => __( 'Cart', 'cart-for-woocommerce' ),
				'link' => admin_url( 'admin.php?page=fkcart' ),
			];

			return $menu;
		}

		/**
		 * Setup js for localization
		 *
		 * @param $app_name
		 * @param $deps
		 * @param $version
		 */
		public function setup_js_for_localization( $app_name, $deps, $version ) {

			if ( defined( FKCART_REACT_ENVIRONMENT ) && 0 === FKCART_REACT_ENVIRONMENT ) {
				return;
			}
			/** enqueue other js file from the dist folder */
			$path = $this->admin_path . '/app/dist/';

			/** enqueue other js file from the dist folder */
			foreach ( glob( $path . "*.js" ) as $dist_file ) {
				$file_info = pathinfo( $dist_file );
				if ( $app_name === $file_info['filename'] ) {
					continue;
				}
				$handle = "fkcart_admin_" . $file_info['filename'];
				wp_register_script( $handle, FKCART_PLUGIN_URL . '/' . $file_info['basename'], $deps, $version, true );
				wp_set_script_translations( $handle, 'cart-for-woocommerce' );
			}
			add_action( 'admin_print_footer_scripts', function () {
				$path = $this->admin_path . '/app/dist/';
				/** enqueue other js file from the dist folder */ global $wp_scripts;
				foreach ( glob( $path . "*.js" ) as $dist_file ) {
					$file_info    = pathinfo( $dist_file );
					$handle       = "fkcart_admin_" . $file_info['filename'];
					$translations = $wp_scripts->print_translations( $handle, false );
					if ( $translations ) {
						$translations = sprintf( "<script%s id='%s-js-translations'>\n%s\n</script>\n", '', esc_attr( $handle ), $translations );
					}
					echo $translations; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			} );
		}
	}
}
