<?php

namespace FKCart\Compatibilities;

/**
 * Class Loader
 * Loads all the compatibilities files we have to provide compatibility with each plugin
 */
if ( ! class_exists( '\FKCart\Compatibilities\Compatibility' ) ) {
	class Compatibility {

		private static $plugin_compatibilities = array();

		public static function load() {
			self::after_plugins_loaded_compatibilities();

			add_action( 'after_setup_theme', [ __CLASS__, 'after_setup_theme_compatibilities' ] );
		}

		/**
		 * Load compatibilities after plugins loaded
		 *
		 * @return void
		 */
		public static function after_plugins_loaded_compatibilities() {
			$files = [
				'adp.php'                                    => defined( 'WC_ADP_PLUGIN_FILE' ),
				'aelia.php'                                  => class_exists( '\Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher' ),
				'bump.php'                                   => class_exists( '\WFOB_Core' ),
				'chained.php'                                => defined( 'WC_CP_PLUGIN_DIRNAME' ),
				'checkoutpluginstripe.php'                   => class_exists( '\CPSW\Inc\Helper' ),
				'freeshipping.php'                           => class_exists( '\FKCart\Pro\Plugin' ),
				'funnelkitstripe.php'                        => class_exists( '\FKWCS_Gateway_Stripe' ),
				'paymentpluginbraintree.php'                 => defined( 'WC_BRAINTREE_PATH' ),
				'paymentpluginstripe.php'                    => class_exists( '\WC_Stripe_Manager' ),
				'tablerate.php'                              => class_exists( '\WC_Table_Rate_Shipping' ),
				'wcrewardpoints.php'                         => class_exists( '\WC_Points_Rewards' ),
				'wcstripe.php'                               => function_exists( 'woocommerce_gateway_stripe' ),
				'woocommerce-product-bundles.php'            => class_exists( '\WC_Bundles' ),
				'woocommerceSubscriptionByWC.php'            => class_exists( '\WC_Subscriptions' ),
				'woocs.php'                                  => class_exists( '\WOOCS' ),
				'woomulticurrency.php'                       => defined( 'WOOMULTI_CURRENCY_F_VERSION' ) || defined( 'WOOMULTI_CURRENCY_VERSION' ),
				'wooProductBundle.php'                       => defined( 'WOOSB_DIR' ),
				'wpml-multicurrency.php'                     => class_exists( '\woocommerce_wpml' ),
				'litespeed.php'                              => defined( 'LSCWP_V' ),
				'klarna.php'                                 => class_exists( '\WC_Klarna_Payments' ) && defined( 'WFFN_PRO_FILE' ),
				'funnelkitcheckout.php'                      => class_exists( '\WFACP_Core' ),
				'yithgiftcard.php'                           => defined( 'YITH_YWGC_FREE' ) || defined( 'YITH_YWGC_PREMIUM' ),
				'germanized.php'                             => class_exists( '\WooCommerce_Germanized' ),
				'booster.php'                                => class_exists( '\WC_Jetpack' ),
				'plugins/commercegurus-commerce-kit.php'     => defined( 'CGKIT_MIN_WC_VER' ),
				'plugins/discount-rules-core-by-flycart.php' => defined( 'WDR_VERSION' ),
				'plugins/wc-wholesale-order-form.php'        => defined( 'WWOF_PLUGIN_DIR_PATH' ),
				'yithbundle.php'                             => defined( 'YITH_WCPB_VERSION' ),
				'supportSelectOptions.php'                   => true
			];

			self::add_files( $files );
		}

		public static function add_files( $paths ) {

			foreach ( $paths as $file => $condition ) {
				if ( false === $condition ) {
					continue;
				}
				try {
					include_once __DIR__ . '/' . $file;
				} catch ( \Exception|\Error $e ) {
					fkcart_log_error( $e->getMessage() );
					if ( defined( 'BWF_DEV' ) && true === BWF_DEV ) {
						trigger_error( $e->getMessage() );
					}
				}
			}

		}

		/**
		 * Load compatibilities after setup theme
		 *
		 * @return void
		 */
		public static function after_setup_theme_compatibilities() {
			$files = [
				'astra.php'                        => defined( 'ASTRA_THEME_VERSION' ),
				'shoptimizer.php'                  => function_exists( 'shoptimizer_header_cart' ),
				'smartcoupons.php'                 => class_exists( '\WC_Smart_Coupons' ),
				'allproductsubscriptions.php'      => class_exists( '\WCS_ATT_Cart' ),
				'pricebasedcountry.php'            => function_exists( '\wcpbc' ),
				'flexibleshipping.php'             => defined( 'FLEXIBLE_SHIPPING_VERSION' ),
				'wpml.php'                         => class_exists( '\SitePress' ),
				'polylang.php'                     => defined( 'PLLWC_VERSION' ),
				'rightpresspricinganddiscount.php' => defined( 'RP_WCDPD_PLUGIN_PATH' ),
				'germanized.php'                   => class_exists( '\WooCommerce_Germanized' ),
				'paypalpayments.php'               => function_exists( '\WooCommerce\PayPalCommerce\init' ),
				'yaycurrency.php'                  => defined( 'YAY_CURRENCY_FILE' ),
				'translatepress.php'               => class_exists( '\TRP_Translate_Press' ),
				'weglot.php'                       => defined( 'WEGLOT_SLUG' ),
				'yithminmaxqty.php'                => defined( 'YWMMQ_VERSION' ),
				'pricebyquantity.php'              => defined( 'WP_PBQ_DIR' ),
				'wpfactoryminmaxamount.php'        => class_exists( '\Alg_WC_OMA' )
			];
			self::add_files( $files );
		}

		public static function register( $object, $slug ) {
			self::$plugin_compatibilities[ $slug ] = $object;
		}

		public static function get_compatibility_class( $slug ) {
			return ( isset( self::$plugin_compatibilities[ $slug ] ) ) ? self::$plugin_compatibilities[ $slug ] : false;
		}

		public static function remove_smart_buttons() {
			try {
				if ( empty( self::$plugin_compatibilities ) ) {
					return '';
				}
				foreach ( self::$plugin_compatibilities as $plugins_class ) {
					if ( method_exists( $plugins_class, 'is_enable' ) && $plugins_class->is_enable() && is_callable( array( $plugins_class, 'remove_smart_buttons' ) ) ) {
						return $plugins_class->remove_smart_buttons();
					}
				}

			} catch ( \Exception|\Error $e ) {
				fkcart_log_error( $e->getMessage() );
			}
		}

		public static function get_free_shipping( \WC_Shipping_Method $shipping_instance ) {
			if ( empty( self::$plugin_compatibilities ) || ! isset( self::$plugin_compatibilities[ $shipping_instance->id ] ) ) {
				return false;
			}

			return self::$plugin_compatibilities[ $shipping_instance->id ]->get_free_shipping( $shipping_instance );
		}

		public static function get_fixed_currency_price( $price, $currency = null ) {
			try {
				if ( empty( self::$plugin_compatibilities ) ) {
					return $price;
				}

				foreach ( self::$plugin_compatibilities as $plugins_class ) {
					if ( method_exists( $plugins_class, 'is_enable' ) && $plugins_class->is_enable() && is_callable( array( $plugins_class, 'alter_fixed_amount' ) ) ) {
						return $plugins_class->alter_fixed_amount( $price, $currency );
					}
				}
			} catch ( \Exception|\Error $e ) {
				fkcart_log_error( $e->getMessage() );
			}

			return $price;
		}

		/**
		 * Return the Current language code of the website
		 * @return array
		 *
		 */
		public static function get_language_code() {
			$language_code = '';
			try {
				if ( empty( self::$plugin_compatibilities ) ) {
					return \FKCart\Includes\Data::get_language_code();
				}
				foreach ( self::$plugin_compatibilities as $plugins_class ) {

					if ( method_exists( $plugins_class, 'is_enable' ) && $plugins_class->is_enable() && is_callable( array( $plugins_class, 'get_language_code' ) ) ) {
						$language_code = $plugins_class->get_language_code();
						break;
					}
				}
			} catch ( \Exception|\Error $e ) {
				fkcart_log_error( $e->getMessage() );
			}

			if ( ! empty( $language_code ) ) {
				return [ $language_code ];
			}

			return \FKCart\Includes\Data::get_language_code();
		}

	}
}
