<?php
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'BWF_Compatibility_With_CURCY' ) ) {
	#[AllowDynamicProperties]
	class BWF_Compatibility_With_CURCY {

		public function __construct() {
		}

		public function is_enable() {
			return class_exists( 'WOOMULTI_CURRENCY_F_Data' );
		}

		public function alter_fixed_amount( $price, $currency = null ) {
			if ( class_exists( 'WOOMULTI_CURRENCY_F_Data' ) ) {
				$currentCurrencyRate   = 1;
				$multiCurrencySettings = WOOMULTI_CURRENCY_F_Data::get_ins();
				$wmcCurrencies         = $multiCurrencySettings->get_list_currencies();
				$currentCurrency       = $multiCurrencySettings->get_current_currency();
				$currentCurrencyRate   = floatval( $wmcCurrencies[ $currentCurrency ]['rate'] );

				// Convert the price to the base currency
				$price = $price / $currentCurrencyRate;
			}

			return $price;
		}

		public function get_fixed_currency_price_reverse( $price, $from = null, $base = null ) {
			if ( class_exists( 'WOOMULTI_CURRENCY_F_Data' ) ) {
				$data = new WOOMULTI_CURRENCY_F_Data();
				$from = ( is_null( $from ) ) ? $data->get_current_currency() : $from;
				$base = ( is_null( $base ) ) ? get_option( 'woocommerce_currency' ) : $base;

				$rates = $data->get_exchange( $from, $base );
				if ( is_array( $rates ) && isset( $rates[ $base ] ) ) {
					$price = $price * $rates[ $base ];
				}
			}

			return $price;
		}
	}

	BWF_Plugin_Compatibilities::register( new BWF_Compatibility_With_CURCY(), 'curcy' );


}

