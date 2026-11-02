<?php
/**
 * Add extra profile fields for users in admin
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Admin_Profile_Premium' ) ) {
	/**
	 * Class that manages extra fields on Affiliate profile form
	 */
	class YITH_WCAF_Admin_Profile_Premium extends YITH_WCAF_Admin_Profile {

		/**
		 * Init method.
		 *
		 * @since 2.0.0
		 */
		public static function init() {
			add_action( 'yith_wcaf_before_save_profile_fields', array( self::class, 'save_non_affiliate_specific_fields' ), 10, 1 );

			parent::init();
		}

		/**
		 * Get additional fields for Affiliate profile form
		 *
		 * @return array Filtered fields.
		 */
		public static function get_profile_fields() {
			$fields = parent::get_profile_fields();

			// append custom profile fields.
			$additional_fields = self::get_additional_profile_fields();

			if ( ! empty( $additional_fields ) ) {
				$fields = yith_wcaf_append_items(
					$fields,
					'affiliate_details',
					array(
						'affiliate_additional_info' => array(
							'title'  => _x( 'Affiliate additional information', '[ADMIN] Affiliate profile form', 'yith-woocommerce-affiliates' ),
							'fields' => $additional_fields,
						),
					)
				);
			}

			// append Associated affiliate fields for all users.
			$fields = array_merge(
				$fields,
				array(
					'associated_affiliate' => array(
						'title'  => _x( 'Associated affiliate', '[ADMIN] Affiliate profile form', 'yith-woocommerce-affiliates' ),
						'fields' => array(
							'persistent_token' => array(
								'label'             => _x( 'Associated affiliate', '[ADMIN] Affiliate profile form', 'yith-woocommerce-affiliates' ),
								'type'              => 'select',
								'description'       => _x( 'Select an affiliate that will receive permanent commissions from this customer\'s purchases.', '[ADMIN] Affiliate profile form', 'yith-woocommerce-affiliates' ),
								'class'             => 'yith-wcaf-enhanced-select',
								'custom_attributes' => array(
									'style' => 'min-width: 25em;',
								),
								'data'              => array(
									'action'      => 'yith_wcaf_get_affiliates_tokens',
									'security'    => wp_create_nonce( 'search-affiliates' ),
									'allow-clear' => 'yes',
									'placeholder' => _x( 'Select an affiliate', '[ADMIN] Affiliate profile form', 'yith-woocommerce-affiliates' ),
								),
							),
						),
					),
				)
			);

			return $fields;
		}

		/**
		 *  Parse a field to produce an array that can be used in the profile
		 *
		 * @param string $key     Key of the field.
		 * @param array  $field   Field to parse.
		 * @param string $context Context of the operation.
		 *
		 * @return array Parsed field.
		 */
		public static function parse_field( $key, $field, $context = 'edit' ) {
			$field = parent::parse_field( $key, $field, $context );
			$value = isset( $field['value'] ) ? $field['value'] : false;

			if ( 'persistent_token' === $key && $value ) {
				$affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate_by_token( $value );

				if ( $affiliate ) {
					$field['options'] = array(
						$value => $affiliate->get_formatted_name( 'edit' ),
					);
				}
			}

			return $field;
		}

		/**
		 * Save extra fields in the user profile (used to save data non-affiliate specific)
		 *
		 * @param int $user_id Id of the user being saved.
		 */
		public static function save_non_affiliate_specific_fields( $user_id ) {
			// nonce is already verified in \YITH_WCAF_Admin_Profile::save_profile_fields.
			$value = isset( $_POST['yith_wcaf_affiliate_meta']['persistent_token'] ) ? sanitize_text_field( wp_unslash( $_POST['yith_wcaf_affiliate_meta']['persistent_token'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( $value ) {
				/**
				 * DO_ACTION: yith_wcaf_updated_persistent_token
				 *
				 * Allows to trigger some action when the persistent token is saved.
				 *
				 * @param int    $user_id  Current user id.
				 * @param string $referral Current referral token.
				 * @param int    $order_id Current order id (if any; null otherwise).
				 */
				do_action( 'yith_wcaf_updated_persistent_token', $user_id, $value, null );

				update_user_meta( $user_id, '_yith_wcaf_persistent_token', $value );
			} else {
				/**
				 * DO_ACTION: yith_wcaf_deleted_persistent_token
				 *
				 * Allows to trigger some action when the persistent token is deleted.
				 *
				 * @param int $user_id Current user id.
				 */
				do_action( 'yith_wcaf_deleted_persistent_token', $user_id );

				delete_user_meta( $user_id, '_yith_wcaf_persistent_token' );
			}
		}

		/**
		 * Returns additional fields from Profile field editor and Active gateways
		 *
		 * @return array Additional fields for profile field / active gateways.
		 */
		protected static function get_additional_profile_fields() {
			$defined_fields    = YITH_WCAF_Affiliates_Profile::get_fields( 'view', array( 'reserved' => false ) );
			$additional_fields = array();

			if ( ! empty( $defined_fields ) ) {
				foreach ( $defined_fields as $field_name => $field ) {
					$additional_fields[ $field_name ] = self::get_additional_profile_field( $field );
				}
			}

			return $additional_fields;
		}

		/**
		 * Converts data structure used to describe a profile filed, into a valid setting array
		 *
		 * @param array $field Array describing profile field, as provided by YITH_WCAF_Affiliates_Profile.
		 * @return array A settings array, that can be used in this class.
		 */
		protected static function get_additional_profile_field( $field ) {
			$field['class'] = implode( ' ', $field['class'] );

			if ( 'select' === $field['type'] ) {
				$field['class']  = $field['class'] ?? '';
				$field['class'] .= ' wc-enhanced-select';
			}

			return $field;
		}

	}
}
