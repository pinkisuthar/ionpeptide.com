<?php
/**
 * Shared snippet input preparation, used by the snippet editor and the abilities.
 *
 * @package WPCode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPCode_Snippet_Input.
 */
class WPCode_Snippet_Input {

	/**
	 * Get the capability needed to edit snippets of a code type.
	 *
	 * @param string $code_type The code type.
	 *
	 * @return string
	 */
	public static function capability_for_code_type( $code_type ) {
		$capability = class_exists( 'WPCode_Access' ) ? WPCode_Access::capability_for_code_type( $code_type ) : '';

		return empty( $capability ) ? 'wpcode_edit_snippets' : $capability;
	}

	/**
	 * Check if the current user can edit snippets of a code type, and a specific snippet if an id is given.
	 *
	 * @param string $code_type  The code type.
	 * @param int    $snippet_id Optional existing snippet id.
	 *
	 * @return bool
	 */
	public static function can_edit( $code_type, $snippet_id = 0 ) {
		if ( ! current_user_can( self::capability_for_code_type( $code_type ) ) ) {
			return false;
		}

		return ! $snippet_id || current_user_can( 'edit_post', $snippet_id );
	}

	/**
	 * Prepare submitted code for storage. Works on the code as given (no slashing or unslashing).
	 *
	 * @param string $code      The code.
	 * @param string $code_type The code type.
	 *
	 * @return string|WP_Error
	 */
	public static function prepare_code( $code, $code_type ) {
		if ( WPCode_Snippet_Execute::is_code_not_allowed( $code ) ) {
			return new WP_Error(
				'wpcode_restricted_code',
				__( 'For your protection, we blocked the addition of certain types of code that could compromise your website\'s security.', 'insert-headers-and-footers' ),
				array( 'status' => 400 )
			);
		}

		if ( 'text' === $code_type ) {
			$code = wpautop( $code );
			// Strip script/event-handler HTML from text snippets authored without unfiltered_html.
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				$code = wp_kses_post( $code );
			}
		}

		return WPCode_Snippet_Execute::normalize_code( $code, $code_type );
	}

	/**
	 * Sanitize the simple snippet fields. Only keys that are present are returned.
	 *
	 * @param array $fields Raw (unslashed) fields.
	 *
	 * @return array
	 */
	public static function sanitize_fields( $fields ) {
		$clean = array();

		foreach ( array( 'title', 'location', 'location_extra' ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( $fields[ $key ] );
			}
		}
		if ( isset( $fields['tags'] ) ) {
			$tags = is_array( $fields['tags'] ) ? $fields['tags'] : explode( ',', (string) $fields['tags'] );
			$tags = array_map( 'sanitize_text_field', array_map( 'trim', $tags ) );

			$clean['tags'] = array_values( array_filter( $tags, 'strlen' ) );
		}
		if ( isset( $fields['note'] ) ) {
			$clean['note'] = wp_kses_post( $fields['note'] );
		}
		foreach ( array( 'insert_number', 'auto_insert' ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$clean[ $key ] = absint( $fields[ $key ] );
			}
		}
		if ( isset( $fields['priority'] ) ) {
			$clean['priority'] = intval( $fields['priority'] );
		}

		return $clean;
	}
}
