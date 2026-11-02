<?php
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'BWF_Compatibility_With_WC_Price_Based_On_Country' ) ) {
	#[AllowDynamicProperties]
	class BWF_Compatibility_With_WC_Price_Based_On_Country {

		public function is_enable() {
			return class_exists( 'WC_Product_Price_Based_Country' );
		}

		/**
		 *
		 * Modifies the amount for the fixed discount given by the admin in the currency selected.
		 *
		 * @param integer|float $price
		 *
		 * @return float
		 */
		public function alter_fixed_amount( $price, $currency = null ) {
			if ( ! $this->is_enable() ) {
				return $price;
			}
			$rate = $this->get_exchange_rate( $currency );

			return $price * $rate;
		}

		public function get_fixed_currency_price_reverse( $price, $currency = null, $base = null ) {
			if ( ! $this->is_enable() ) {
				return $price;
			}

			$rate = $this->get_exchange_rate( $currency );

			return $price / $rate;
		}

		/**
		 * Get exchange rate
		 *
		 * @param $currency
		 *
		 * @return float|int
		 */
		public function get_exchange_rate( $currency ) {
			if ( ! class_exists( 'WCPBC_Pricing_Zones' ) ) {
				return 1;
			}
			$zones = WCPBC_Pricing_Zones::get_zones();
			foreach ( $zones as $zone ) {
				/** @var $zone WCPBC_Pricing_Zone */
				if ( $currency !== $zone->get_currency() ) {
					continue;
				}

				return $zone->get_exchange_rate();
			}

			return 1;
		}
	}

	BWF_Plugin_Compatibilities::register( new BWF_Compatibility_With_WC_Price_Based_On_Country(), 'wc_price_based_on_country' );
}