<?php
/**
 * Price Based on Country for WooCommerce
 * By Oscar Gare
 * https://wordpress.org/plugins/woocommerce-product-price-based-on-countries/
 */

namespace FKCart\compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\Pricebasedcountry' ) ) {
	class Pricebasedcountry {
		public function is_enable() {
			return function_exists( '\wcpbc' ) && defined( 'WCPBC_PLUGIN_FILE' );
		}

		/**
		 * Modifies the amount for the fixed discount given by the admin in the currency selected.
		 *
		 * @param integer|float $price
		 *
		 * @return float
		 */
		public function alter_fixed_amount( $price, $currency = null ) {
			if ( function_exists( '\wcpbc_the_zone' ) && \wcpbc_the_zone() ) {
				$instance = \wcpbc_the_zone();
				if ( method_exists( $instance, 'get_exchange_rate_price' ) ) {
					return \wcpbc_the_zone()->get_exchange_rate_price( $price, false, 'fkcart-reward' );
				}
			}

			return $price;
		}

	}

	Compatibility::register( new Pricebasedcountry(), 'pricebasecountry' );
}
