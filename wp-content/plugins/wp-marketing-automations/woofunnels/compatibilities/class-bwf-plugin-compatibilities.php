<?php
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'BWF_Plugin_Compatibilities' ) ) {
	/**
	 * Class BWF_Plugin_Compatibilities
	 * Loads all the compatibilities files we have to provide compatibility with each plugin
	 */
	#[AllowDynamicProperties]
	class BWF_Plugin_Compatibilities {

		public static $plugin_compatibilities = array();

		public static function load_all_compatibilities() {
			$compat = [
				'class-bwf-compatibilitiy-with-curcy.php'                    => class_exists( 'WOOMULTI_CURRENCY_F_VERSION' ),
				'class-bwf-compatibility-with-aelia-cs.php'                  => class_exists( 'Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher' ),
				'class-bwf-compatibility-with-woocs.php'                     => isset( $GLOBALS['WOOCS'] ) && $GLOBALS['WOOCS'] instanceof WOOCS,
				'class-bwf-compatibility-with-woomulticurrency.php'          => defined( 'WOOMULTI_CURRENCY_VERSION' ),
				'class-bwf-compatibility-with-wpml-multicurrency.php'        => class_exists( 'woocommerce_wpml' ),
				'class-bwf-compatibility-with-yaycurrency.php'               => class_exists( 'Yay_Currency\Helpers\YayCurrencyHelper' ),
				'class-bwf-compatibility-with-wc-price-based-on-country.php' => class_exists( 'WC_Product_Price_Based_Country' ),
				'class-bwf-compatibility-with-woocommerce-payments.php'      => function_exists( 'WC_Payments_Multi_Currency' ),
			];
			self::add_files( $compat );
		}

		public static function register( $object, $slug ) {
			self::$plugin_compatibilities[ $slug ] = $object;
		}

		public static function get_compatibility_class( $slug ) {
			return ( isset( self::$plugin_compatibilities[ $slug ] ) ) ? self::$plugin_compatibilities[ $slug ] : false;
		}

		public static function get_fixed_currency_price( $price, $currency = null ) {
			if ( empty( self::$plugin_compatibilities ) ) {
				return $price;
			}

			foreach ( self::$plugin_compatibilities as $plugins_class ) {
				if ( method_exists( $plugins_class, 'is_enable' ) && $plugins_class->is_enable() && is_callable( array( $plugins_class, 'alter_fixed_amount' ) ) ) {
					return call_user_func( array( $plugins_class, 'alter_fixed_amount' ), $price, $currency );
				}
			}

			return $price;
		}

		public static function get_fixed_currency_price_reverse( $price, $from = null, $to = null ) {

			try {
				if ( empty( self::$plugin_compatibilities ) ) {
					BWF_Plugin_Compatibilities::load_all_compatibilities();
				}
				if ( empty( self::$plugin_compatibilities ) ) {
					return $price;
				}

				foreach ( self::$plugin_compatibilities as $plugins_class ) {
					if ( method_exists( $plugins_class, 'is_enable' ) && $plugins_class->is_enable() && is_callable( array( $plugins_class, 'get_fixed_currency_price_reverse' ) ) ) {
						return call_user_func( array( $plugins_class, 'get_fixed_currency_price_reverse' ), $price, $from, $to );
					}
				}


			} catch ( Exception|Error $e ) {

				BWF_Logger::get_instance()->log( 'Error while getting reversed price through compatibility files: ' . $e->getMessage(), 'bwf-compatibilities', 'buildwoofunnels', true );


			}

			return $price;

		}

		/**
		 * Get currency symbol
		 *
		 * @param $currency
		 *
		 * @return mixed|string
		 */
		public static function get_currency_symbol( $currency ) {
			if ( empty( self::$plugin_compatibilities ) ) {
				BWF_Plugin_Compatibilities::load_all_compatibilities();
			}
			if ( empty( self::$plugin_compatibilities ) ) {
				return '';
			}

			foreach ( self::$plugin_compatibilities as $plugins_class ) {
				if ( method_exists( $plugins_class, 'is_enable' ) && $plugins_class->is_enable() && is_callable( array( $plugins_class, 'get_currency_symbol' ) ) ) {
					return call_user_func( array( $plugins_class, 'get_currency_symbol' ), $currency );

				}
			}

			return '';
		}

		public static function add_files( $paths ) {
			try {
				foreach ( $paths as $file => $condition ) {
					if ( false === $condition ) {
						continue;
					}

					include_once __DIR__ . '/' . $file;
				}

			} catch ( Exception|Error $e ) {
				BWF_Logger::get_instance()->log( 'Error while loading compatibility files: ' . $e->getMessage(), 'bwf-compatibilities' );
			}
		}
	}
}