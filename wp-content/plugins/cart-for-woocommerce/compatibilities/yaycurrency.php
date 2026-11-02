<?php
/**
 * https://wordpress.org/plugins/yaycurrency/
 */

namespace FKCart\Compatibilities;

use Yay_Currency\Helpers\YayCurrencyHelper;

if ( ! class_exists( '\FKCart\Compatibilities\YayCurrency' ) ) {
	class YayCurrency {
		public function __construct() {
		}

		public function is_enable() {
			return true;
		}

		public function alter_fixed_amount( $price, $currency = null ) {
			$cookie_name = YayCurrencyHelper::get_cookie_name();

			if ( isset( $_COOKIE[ $cookie_name ] ) ) {
				$currency_ID    = sanitize_key( $_COOKIE[ $cookie_name ] );
				$apply_currency = YayCurrencyHelper::get_currency_by_ID( $currency_ID );
				$price          = YayCurrencyHelper::calculate_price_by_currency( $price, false, $apply_currency );

				return $price;
			}

			return $price;
		}
	}

	Compatibility::register( new YayCurrency(), 'YayCurrency' );
}
