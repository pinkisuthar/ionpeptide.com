<?php
/**
 * WOOCS – Currency Switcher for WooCommerce Professional
 * realmag777
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\WOOCS' ) ) {
	class WOOCS {
		public function is_enable() {
			return isset( $GLOBALS['WOOCS'] ) && $GLOBALS['WOOCS'] instanceof \WOOCS;
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
			return $GLOBALS['WOOCS']->woocs_exchange_value( $price );
		}

	}

	Compatibility::register( new WOOCS(), 'woocs' );
}
