<?php

#[AllowDynamicProperties]
class bwfan_Input_Number extends bwfan_Input_Text {

	public function __construct() {
		parent::__construct();
		$this->type = 'Number';
	}

	public function render( $field, $value = null ) {
		$field = array_merge( $this->defaults, $field );
		if ( ! isset( $field['id'] ) ) {
			$field['id'] = sanitize_title( $field['id'] );
		}
		$disabled = '';

		if ( true === $field['disabled'] ) {
			$disabled = 'disabled';
		}

		/** Don't add any escaping method on name field as it will break the dynamic backbone string */
		echo '<input ' . esc_attr( $disabled ) . ' name="' . $field['name'] . '" type="number" id="' . esc_attr( $field['id'] ) . '" class="' . esc_html( $field['class'] ) . '" placeholder="' . esc_html( $field['placeholder'] ) . '" value="' . esc_attr( (string) $value ) . '" />'; //phpcs:ignore WordPress.Security.EscapeOutput, WordPress.Security.EscapeOutput
	}

}
