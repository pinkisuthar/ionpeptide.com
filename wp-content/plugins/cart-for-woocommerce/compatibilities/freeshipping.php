<?php

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\Freeshipping' ) ) {

	class Freeshipping {
		public function is_enable() {
			return true;
		}

		public function get_free_shipping( \WC_Shipping_Free_Shipping $shipping_instance ) {
			return array(
				'min_amount'       => $shipping_instance->min_amount,
				'ignore_discounts' => $shipping_instance->ignore_discounts,
				'method_id'        => $shipping_instance->get_rate_id()
			);
		}
	}

	Compatibility::register( new Freeshipping(), 'free_shipping' );
}