<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'BWFAN_Contact_DOB' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFAN_Contact_DOB extends BWFAN_Merge_Tag {

		private static $instance = null;

		public function __construct() {
			$this->tag_name        = 'contact_dob';
			$this->tag_description = __( 'Contact DOB', 'wp-marketing-automations' );
			add_shortcode( 'bwfan_contact_dob', array( $this, 'parse_shortcode' ) );
			$this->support_date     = true;
			$this->priority         = 27;
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
			$parameters           = [];
			$format               = BWFAN_Common::bwfan_get_date_format();
			$parameters['format'] = isset( $attr['format'] ) ? $attr['format'] : $format;

			if ( isset( $attr['modify'] ) ) {
				$parameters['modify'] = $attr['modify'];
			}

			$get_data = BWFAN_Merge_Tag_Loader::get_data();
			if ( true === $get_data['is_preview'] ) {
				return $this->parse_shortcode_output( $this->get_dummy_preview( $parameters ), $attr );
			}

			/** Birthday date available */
			$birthday_date = isset( $get_data['birthday'] ) ? $get_data['birthday'] : '';
			if ( ! empty( $birthday_date ) ) {
				return $this->parse_shortcode_output( $this->format_datetime( $birthday_date, $parameters ), $attr );
			}

			/** If Contact ID available */
			$cid = isset( $get_data['contact_id'] ) ? $get_data['contact_id'] : '';
			$dob = $this->get_dob( $cid, $parameters );
			if ( ! empty( $dob ) ) {
				return $this->parse_shortcode_output( $dob, $attr );
			}

			/** If order */
			$order = isset( $get_data['wc_order'] ) ? $get_data['wc_order'] : '';
			if ( bwfan_is_woocommerce_active() && $order instanceof WC_Order ) {
				$cid = BWFAN_Woocommerce_Compatibility::get_order_data( $order, '_woofunnel_cid' );
				$dob = $this->get_dob( $cid, $parameters );
				if ( ! empty( $dob ) ) {
					return $this->parse_shortcode_output( $dob, $attr );
				}
			}

			/** If user ID or email */
			$user_id = isset( $get_data['user_id'] ) ? $get_data['user_id'] : '';
			$email   = isset( $get_data['email'] ) ? $get_data['email'] : '';

			$contact = bwf_get_contact( $user_id, $email );
			if ( absint( $contact->get_id() ) > 0 ) {
				$cid = $contact->get_id();
				$dob = $this->get_dob( $cid, $parameters );
				if ( ! empty( $dob ) ) {
					return $this->parse_shortcode_output( $dob, $attr );
				}
			}

			return $this->parse_shortcode_output( '', $attr );
		}

		public function get_dob( $cid, $parameters ) {
			$cid = absint( $cid );
			if ( empty( $cid ) ) {
				return '';
			}
			$contact = new BWFCRM_Contact( $cid );
			if ( ! $contact->is_contact_exists() ) {
				return '';
			}

			$dob = $contact->get_dob();
			if ( empty( $dob ) ) {
				return '';
			}

			return $this->format_datetime( $dob, $parameters );
		}

		/**
		 * Show dummy value of the current merge tag.
		 *
		 * @return string
		 *
		 */
		public function get_dummy_preview( $parameters ) {
			if ( ! method_exists( BWFAN_Merge_Tag::class, 'get_contact_data' ) ) {
				return '-';
			}

			$contact = $this->get_contact_data();

			/** checking contact instance and id */
			if ( ! $contact instanceof WooFunnels_Contact || 0 === absint( $contact->get_id() ) ) {
				return '-';
			}

			$dob = $this->get_dob( $contact->get_id(), $parameters );

			return ! empty( $dob ) ? $dob : '-';
		}

	}

	/**
	 * Register this merge tag to a group.
	 */
	BWFAN_Merge_Tag_Loader::register( 'bwf_contact', 'BWFAN_Contact_DOB', null, __( 'Contact', 'wp-marketing-automations' ) );
}