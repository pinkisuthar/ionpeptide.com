<?php
/**
 * Discount Rules Core by Flycart version 2.6.6
 * Author url: https://www.flycart.org
 *
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\FKCart_Discount_Rules_Core_by_Flycart' ) ) {


	/**
	 * Class FKCart_Discount_Rules_Core_by_Flycart
	 * Unset the woocommerce_before_calculate_totals hook from Plugin filter
	 * FKCart_Discount_Rules_Core_by_Flycart
	 */
	class FKCart_Discount_Rules_Core_by_Flycart {

		public function __construct() {
			add_filter( 'advanced_woo_discount_rules_exclude_hooks_from_removing', [ $this, 'action' ] );
		}

		public function action( $allowed_hooks ) {
			if ( isset( $allowed_hooks['woocommerce_before_calculate_totals'] ) ) {
				unset( $allowed_hooks['woocommerce_before_calculate_totals'] );
			}

			return $allowed_hooks;
		}

	}


	Compatibility::register( new FKCart_Discount_Rules_Core_by_Flycart(), 'woo-discount-rules' );
}