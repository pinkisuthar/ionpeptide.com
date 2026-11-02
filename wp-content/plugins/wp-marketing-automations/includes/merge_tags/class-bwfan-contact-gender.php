<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'BWFAN_Contact_Gender' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFAN_Contact_Gender extends BWFAN_Merge_Tag {

		private static $instance = null;

		public function __construct() {
			$this->tag_name        = 'contact_gender';
			$this->tag_description = __( 'Contact Gender', 'wp-marketing-automations' );
			add_shortcode( 'bwfan_contact_gender', array( $this, 'parse_shortcode' ) );
			$this->priority         = 30;
			$this->is_crm_broadcast = true;
		}

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
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

			/** If Contact ID available */
			$cid    = isset( $get_data['contact_id'] ) ? $get_data['contact_id'] : '';
			$gender = $this->get_gender( $cid );
			if ( false !== $gender ) {
				return $this->parse_shortcode_output( $gender, $attr );
			}

			/** If order */
			$order = isset( $get_data['wc_order'] ) ? $get_data['wc_order'] : '';
			if ( bwfan_is_woocommerce_active() && $order instanceof WC_Order ) {
				$cid    = BWFAN_Woocommerce_Compatibility::get_order_data( $order, '_woofunnel_cid' );
				$gender = $this->get_gender( $cid );
				if ( false !== $gender ) {
					return $this->parse_shortcode_output( $gender, $attr );
				}
			}

			/** If user ID or email */
			$user_id = isset( $get_data['user_id'] ) ? $get_data['user_id'] : '';
			$email   = isset( $get_data['email'] ) ? $get_data['email'] : '';

			$contact = bwf_get_contact( $user_id, $email );
			if ( absint( $contact->get_id() ) > 0 ) {
				$cid    = $contact->get_id();
				$gender = $this->get_gender( $cid );
				if ( false !== $gender ) {
					return $this->parse_shortcode_output( $gender, $attr );
				}
			}

			return $this->parse_shortcode_output( '', $attr );
		}

		public function get_gender( $cid ) {
			$cid = absint( $cid );
			if ( 0 === $cid ) {
				return false;
			}
			$contact = new BWFCRM_Contact( $cid );
			if ( ! $contact->is_contact_exists() ) {
				return false;
			}

			return $contact->get_gender();
		}

		/**
		 * Show dummy value of the current merge tag.
		 *
		 * @return string
		 *
		 */
		public function get_dummy_preview() {
			$gender = 'Male';
			if ( ! method_exists( BWFAN_Merge_Tag::class, 'get_contact_data' ) ) {
				return $gender;
			}

			$contact = $this->get_contact_data();

			/** check for contact instance */
			if ( ! $contact instanceof WooFunnels_Contact || 0 === absint( $contact->get_id() ) ) {
				return $gender;
			}

			$gender = $this->get_gender( $contact->get_id() );

			return ! empty( $gender ) ? $gender : '-';
		}

	}

	/**
	 * Register this merge tag to a group.
	 */
	BWFAN_Merge_Tag_Loader::register( 'bwf_contact', 'BWFAN_Contact_Gender', null, __( 'Contact', 'wp-marketing-automations' ) );
}
