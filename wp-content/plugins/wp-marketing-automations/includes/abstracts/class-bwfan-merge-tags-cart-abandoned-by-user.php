<?php

#[AllowDynamicProperties]
abstract class Cart_Merge_Tag extends BWFAN_Merge_Tag {

	public $checkout_data = null;
	public $cart_details = null;

	/**
	 * Get cart value which is captured at the checkout page
	 *
	 * @param $key
	 * @param $cart_details
	 *
	 * @return mixed|string
	 */
	public function get_cart_value( $key, $cart_details ) {
		if ( empty( $cart_details ) ) {
			return '';
		}

		$this->cart_details = $cart_details;

		$checkout_data = $cart_details['checkout_data'] ?? '';
		if ( empty( $checkout_data ) ) {
			return '';
		}
		$field_value = '';

		$checkout_data       = json_decode( $checkout_data, true );
		$this->checkout_data = $checkout_data;
		if ( isset( $checkout_data['fields'][ $key ] ) ) {
			$field_value = $checkout_data['fields'][ $key ];
			$field_value = $this->post_value_check( $field_value );
		}

		if ( empty( $field_value ) ) {
			return $checkout_data[ $key ] ?? '';
		}

		return $field_value;
	}

	/**
	 * Individual merge tags can decode the value as they need
	 *
	 * @param $field_value
	 *
	 * @return mixed
	 */
	public function post_value_check( $field_value ) {
		return $field_value;
	}
}
