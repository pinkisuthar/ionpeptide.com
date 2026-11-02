<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'BWFAN_Contact_Field' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFAN_Contact_Field extends BWFAN_Merge_Tag {

		private static $instance = null;

		public function __construct() {
			$this->tag_name        = 'contact_field';
			$this->tag_description = __( 'Contact Field', 'wp-marketing-automations' );
			add_shortcode( 'bwfan_contact_field', array( $this, 'parse_shortcode' ) );
			$this->priority         = 29;
			$this->is_crm_broadcast = true;
		}

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Show the html in popup for the merge tag.
		 */
		public function get_view() {
			$this->get_back_button();
			$this->data_key();
			if ( $this->support_fallback ) {
				$this->get_fallback();
			}

			$this->get_preview();
			$this->get_copy_button();
		}

		public function data_key() {
			?>
            <div class="bwfan_mtag_wrap">
                <div class="bwfan_label">
                    <label for="" class="bwfan-label-title"><?php esc_html_e( 'Field Key', 'wp-marketing-automations' ); ?></label>
                </div>
                <div class="bwfan_label_val">
                    <input type="text" class="bwfan-input-wrapper bwfan_tag_input" name="key" required/>
                    <div class="clearfix bwfan_field_desc"><?php esc_html_e( 'Input the correct field key in order to get the data', 'wp-marketing-automations' ); ?></div>
                </div>
            </div>
			<?php
		}


		/**
		 * Parse the merge tag and return its value.
		 *
		 * @param $attr
		 *
		 * @return mixed|string|void
		 */
		public function parse_shortcode( $attr ) {
			$get_data = BWFAN_Merge_Tag_Loader::get_data();
			if ( true === $get_data['is_preview'] ) {
				return $this->parse_shortcode_output( $this->get_dummy_preview(), $attr );
			}

			$key = $attr['key'];
			if ( empty( $key ) ) {
				return '';
			}

			/** If Contact ID available */
			$cid   = isset( $get_data['contact_id'] ) ? $get_data['contact_id'] : '';
			$value = $this->get_value( $cid, $key );
			if ( false !== $value ) {
				return $this->parse_shortcode_output( $value, $attr );
			}

			/** If order */
			$order = isset( $get_data['wc_order'] ) ? $get_data['wc_order'] : '';
			if ( bwfan_is_woocommerce_active() && $order instanceof WC_Order ) {
				$cid   = BWFAN_Woocommerce_Compatibility::get_order_data( $order, '_woofunnel_cid' );
				$value = $this->get_value( $cid, $key );
				if ( false !== $value ) {
					return $this->parse_shortcode_output( $value, $attr );
				}
			}

			/** If user ID or email */
			$user_id = isset( $get_data['user_id'] ) ? $get_data['user_id'] : '';
			$email   = isset( $get_data['email'] ) ? $get_data['email'] : '';

			$contact = bwf_get_contact( $user_id, $email );
			if ( absint( $contact->get_id() ) > 0 ) {
				$cid   = $contact->get_id();
				$value = $this->get_value( $cid, $key );
				if ( false !== $value ) {
					return $this->parse_shortcode_output( $value, $attr );
				}
			}

			return $this->parse_shortcode_output( '', $attr );
		}

		/**
		 * Return value by key
		 *
		 * @param $cid
		 * @param $key
		 *
		 * @return false|mixed|string|null
		 */
		public function get_value( $cid, $key ) {
			$cid = absint( $cid );
			if ( 0 === $cid ) {
				return false;
			}

			$contact = new BWFCRM_Contact( $cid );
			if ( ! $contact->is_contact_exists() ) {
				return false;
			}

			$value = $contact->get_field_by_slug( $key );

			$field_type = $this->get_field_type( $key );
			if ( BWFCRM_Fields::$TYPE_DATE === absint( $field_type ) ) {
				return $this->get_formatted_date_value( $value );
			}
			if ( BWFCRM_Fields::$TYPE_CHECKBOX === absint( $field_type ) ) {
				$temp_value = json_decode( $value, true );
				if ( is_array( $temp_value ) && count( $temp_value ) > 0 ) {
					return implode( ', ', $temp_value );
				}
			}
			if ( BWFCRM_Fields::$TYPE_TEXTAREA === absint( $field_type ) ) {
				/** used nl2br to convert \n to <br />*/
				return nl2br( $value );
			}

			return $value;
		}

		/**
		 * @param $field_key
		 *
		 * @return mixed|void
		 */
		public function get_field_type( $field_key ) {
			$field_data = BWFAN_Model_Fields::get_field_by_slug( $field_key );
			if ( ! isset( $field_data['type'] ) || empty( $field_data['type'] ) ) {
				return;
			}

			return $field_data['type'];
		}

		/**
		 * Show dummy value of the current merge tag.
		 *
		 * @return string
		 *
		 */
		public function get_dummy_preview() {
			return 'Dummy value';
		}

		/**
		 * Return mergetag schema
		 *
		 * @return array[]
		 */
		public function get_setting_schema() {
			return [
				[
					'id'          => 'key',
					'label'       => __( 'Field Key', 'wp-marketing-automations' ),
					'type'        => 'text',
					'class'       => '',
					'placeholder' => '',
					'hint'        => __( 'Input the correct field key in order to get the data', 'wp-marketing-automations' ),
					'required'    => true,
					'toggler'     => array(),
				],
			];
		}

	}

	/**
	 * Register this merge tag to a group.
	 */
	BWFAN_Merge_Tag_Loader::register( 'bwf_contact', 'BWFAN_Contact_Field', null, __( 'Contact', 'wp-marketing-automations' ) );
}
