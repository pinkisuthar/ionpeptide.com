<?php
/**
 * WooCommerce Table Rate Official
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\Tablerate' ) ) {

	class Tablerate {
		public function is_enable() {
			return class_exists( '\WC_Table_Rate_Shipping' );
		}

		public function get_free_shipping( \WC_Shipping_Table_Rate $shipping_instance ) {
			global $wpdb;
			$query   = $wpdb->prepare( "select MIN(rate_min) as min_amount,rate_id from {$wpdb->prefix}woocommerce_shipping_table_rates where shipping_method_id=%d AND rate_cost=0 AND rate_cost_per_item=0 AND rate_cost_per_weight_unit=0 AND rate_cost_percent=0", $shipping_instance->instance_id );
			$results = $wpdb->get_results( $query, ARRAY_A );

			if ( empty( $results ) ) {
				return false;
			}

			return array(
				'min_amount'       => $results[0]['min_amount'],
				'ignore_discounts' => false,
				'method_id'        => $shipping_instance->get_rate_id() . ':' . $results[0]['rate_id']
			);
		}
	}

	Compatibility::register( new Tablerate(), 'table_rate' );
}
