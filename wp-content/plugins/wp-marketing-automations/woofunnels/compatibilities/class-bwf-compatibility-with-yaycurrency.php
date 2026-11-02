<?php
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'BWF_Compatibility_With_YayCurrency' ) ) {
	#[AllowDynamicProperties]
	class BWF_Compatibility_With_YayCurrency {

		public function is_enable() {
			return class_exists( 'Yay_Currency\Helpers\YayCurrencyHelper' );
		}

		/**
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

			$currency = $this->get_formatted_currency( $currency );
			if ( empty( $currency ) ) {
				return $price;
			}

			return Yay_Currency\Helpers\YayCurrencyHelper::calculate_price_by_currency( $price, false, $currency );
		}

		function get_fixed_currency_price_reverse( $price, $from = null, $base = null ) {
			if ( ! $this->is_enable() ) {
				return $price;
			}

			$currency = $this->get_formatted_currency( $from );
			if ( empty( $currency ) ) {
				return $price;
			}

			return Yay_Currency\Helpers\YayCurrencyHelper::reverse_calculate_price_by_currency( $price, $currency );
		}

		public function get_formatted_currency( $from ) {
			if ( ! $this->is_enable() ) {
				return [];
			}

			return Yay_Currency\Helpers\YayCurrencyHelper::get_currency_by_currency_code( $from );
		}

		public function get_currency_symbol( $currency ) {
			if ( ! class_exists( 'Yay_Currency\Helpers\YayCurrencyHelper' ) ) {
				return '';
			}
			$apply_currency = $this->get_formatted_currency( $currency );
			add_filter( 'yay_currency_detect_current_currency', function () use ( $apply_currency ) {
				return $apply_currency;
			} );

			return Yay_Currency\Helpers\YayCurrencyHelper::get_symbol_by_currency_code( $currency );
		}
	}

	BWF_Plugin_Compatibilities::register( new BWF_Compatibility_With_YayCurrency(), 'yaycurrency' );
}