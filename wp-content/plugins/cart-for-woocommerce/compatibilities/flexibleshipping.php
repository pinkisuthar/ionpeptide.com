<?php
/**
 * Flexible Shipping
 * Plugin URI: https://wordpress.org/plugins/flexible-shipping/
 * Description: Create additional shipment methods in WooCommerce and enable pricing based on cart weight or total.
 */

namespace FKCart\compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\Flexibleshipping' ) ) {
	class Flexibleshipping {
		public function is_enable() {
			return defined( 'FLEXIBLE_SHIPPING_VERSION' );
		}

		public function get_free_shipping( \WPDesk\FS\TableRate\ShippingMethodSingle $shipping_instance ) {

			$instance_id = $shipping_instance->get_instance_id();
			$data        = get_option( 'woocommerce_flexible_shipping_single_' . $instance_id . '_settings', [] );

			if ( empty( $data ) ) {
				return false;
			}

			if ( ! isset( $data['method_free_shipping_requires_upselling'] ) || 'order_amount' !== $data['method_free_shipping_requires_upselling'] || ! isset( $data['method_free_shipping'] ) || empty( $data['method_free_shipping'] ) ) {
				return false;
			}

			return array(
				'min_amount'       => floatval( $data['method_free_shipping'] ),
				'ignore_discounts' => false,
				'method_id'        => $shipping_instance->id . $shipping_instance->get_instance_id()
			);
		}
	}

	Compatibility::register( new Flexibleshipping(), 'flexible_shipping_single' );
}
