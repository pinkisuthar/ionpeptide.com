<?php

namespace WPForms\SmartTags\SmartTag;

/**
 * Class FieldHtmlId.
 *
 * @since 1.6.7
 */
class FieldHtmlId extends SmartTag {

	/**
	 * Get smart tag value.
	 *
	 * @since 1.6.7
	 * @since 2.0.3.1 Escape a plain-text value printed into front-end markup, and drop form controls and `data-*` there.
	 *
	 * @param array  $form_data Form data.
	 * @param array  $fields    List of fields.
	 * @param string $entry_id  Entry ID.
	 *
	 * @return string
	 */
	public function get_value( $form_data, $fields = [], $entry_id = '' ) {

		$attributes = $this->get_attributes();

		if ( ! isset( $attributes['field_html_id'] ) || ! is_numeric( $attributes['field_html_id'] ) || $attributes['field_html_id'] < 0 ) {
			return '';
		}

		$field_id = absint( $attributes['field_html_id'] );

		if ( empty( $fields[ $field_id ] ) ) {
			return '';
		}

		if ( ! isset( $fields[ $field_id ]['value'] ) || (string) $fields[ $field_id ]['value'] === '' ) {
			return '<em>' . esc_html__( '(empty)', 'wpforms-lite' ) . '</em>';
		}

		$value = $this->get_formatted_field_value( (int) $field_id, (array) $fields, 'value' );
		$value = $this->sanitize_field_value( $value, (array) $fields[ $field_id ] );

		/**
		 * Modify value for the {field_html_id="123"} tag.
		 *
		 * @since 1.4.0
		 *
		 * @param string $value     Smart tag value.
		 * @param array  $field     The field.
		 * @param array  $form_data Processed form settings/data, prepared to be used later.
		 * @param string $context   Context usage.
		 */
		$value = (string) apply_filters( 'wpforms_html_field_value', $value, $fields[ $field_id ], $form_data, 'smart-tag' ); // phpcs:ignore WPForms.PHP.ValidateHooks.InvalidHookName

		// The callbacks build their own markup, so the escaping above says nothing about what they
		// return. The admin entry surfaces gate the same filter this way.
		$is_iframe_allowed = wpforms_is_entry_field_value_iframe_allowed( $fields[ $field_id ] );
		$value             = wpforms_esc_entry_field_value( $value, $is_iframe_allowed );

		if ( ! $this->is_markup_context() ) {
			return $value;
		}

		// The pass above URL-validates the iframe `data-src`, this one drops what front-end scripts act on.
		return wp_kses( $value, wpforms_get_allowed_html_tags_for_smart_tag_value( $is_iframe_allowed ) );
	}
}
