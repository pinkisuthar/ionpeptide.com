<?php
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'BWF_Compatibility_With_WooCommerce_Payments' ) ) {
	#[AllowDynamicProperties]
	class BWF_Compatibility_With_WooCommerce_Payments {

		public function __construct() {

		}

		public function is_enable() {
			if ( class_exists( 'WCPay\MultiCurrency\MultiCurrency' ) && function_exists( 'WC_Payments_Multi_Currency' ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Adds currency parameter to URLs to maintain currency context
		 *
		 * @param string $url
		 * @param WC_Order $order
		 *
		 * @return string
		 */
		public function maybe_add_currency_converter_url( $url, $order ) {

			if ( ! $order instanceof WC_Order ) {
				return $url;
			}

			$currency = $order->get_currency();
			if ( $currency ) {
				$url = add_query_arg( array( 'currency' => strtoupper( $currency ) ), $url );
			}

			return $url;
		}

		/**
		 * Modifies the amount for the fixed discount given by the admin in the currency selected.
		 *
		 * @param integer|float $price
		 * @param string|null $currency
		 *
		 * @return float
		 */
		public function alter_fixed_amount( $price, $currency = null ) {
			if ( ! $this->is_enable() ) {
				return $price;
			}

			$multi_currency = WC_Payments_Multi_Currency();
			if ( ! $multi_currency ) {
				return $price;
			}

			return $multi_currency->get_price( $price, 'product' );
		}

		/**
		 * Converts price back to the base currency (reverse conversion)
		 *
		 * @param float $price
		 * @param string|null $from
		 * @param string|null $base
		 *
		 * @return float
		 */
		public function get_fixed_currency_price_reverse( $price, $from = null, $base = null ) {
			if ( ! $this->is_enable() ) {
				return $price;
			}

			$multi_currency = WC_Payments_Multi_Currency();
			if ( ! $multi_currency ) {
				return $price;
			}

			$from = ( is_null( $from ) ) ? $multi_currency->get_selected_currency()->get_code() : $from;
			$base = ( is_null( $base ) ) ? $multi_currency->get_default_currency()->get_code() : $base;

			// If currencies are the same, no conversion needed
			if ( $from === $base ) {
				return $price;
			}

			try {
				// Use WooCommerce Payments' raw conversion method
				return $multi_currency->get_raw_conversion( $price, $base, $from );
			} catch ( Exception $e ) {
				// If conversion fails, return original price
				return $price;
			}
		}

		/**
		 * Gets the current selected currency code
		 *
		 * @return string|null
		 */
		public function get_current_currency() {
			if ( ! $this->is_enable() ) {
				return null;
			}

			$multi_currency = WC_Payments_Multi_Currency();
			if ( ! $multi_currency ) {
				return null;
			}

			return $multi_currency->get_selected_currency()->get_code();
		}

		/**
		 * Gets the default store currency code
		 *
		 * @return string|null
		 */
		public function get_default_currency() {
			if ( ! $this->is_enable() ) {
				return null;
			}

			$multi_currency = WC_Payments_Multi_Currency();
			if ( ! $multi_currency ) {
				return null;
			}

			return $multi_currency->get_default_currency()->get_code();
		}

		/**
		 * Gets the exchange rate for a currency
		 *
		 * @param string $currency_code
		 *
		 * @return float
		 */
		public function get_exchange_rate( $currency_code ) {
			if ( ! $this->is_enable() ) {
				return 1.0;
			}

			$multi_currency = WC_Payments_Multi_Currency();
			if ( ! $multi_currency ) {
				return 1.0;
			}

			$enabled_currencies = $multi_currency->get_enabled_currencies();
			if ( isset( $enabled_currencies[ $currency_code ] ) ) {
				return $enabled_currencies[ $currency_code ]->get_rate();
			}

			return 1.0;
		}

	}

	BWF_Plugin_Compatibilities::register( new BWF_Compatibility_With_WooCommerce_Payments(), 'woocommerce_payments' );

}
