<?php
/** Booster Plus for WooCommerce
 * https://booster.io/
 */

namespace FKCart\compatibilities;

class booster {
	public function __construct() {
	}

	public function is_enable() {
		return true;
	}

	public function alter_fixed_amount( $price, $currency = null ) {
		$instance = \WC_Jetpack::instance();
		if ( isset( $instance->modules['price_by_country'] ) && $instance->modules['price_by_country'] instanceof \WCJ_Price_By_Country && ! is_null( $instance->modules['price_by_country']->core ) ) {
			return $instance->modules['price_by_country']->core->change_price( $price, null );
		}

		return $price;
	}
}

Compatibility::register( new booster(), 'booster-wcj' );
