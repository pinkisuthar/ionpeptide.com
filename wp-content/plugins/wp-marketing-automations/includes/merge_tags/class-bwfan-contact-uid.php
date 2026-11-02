<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'BWFAN_Contact_UID' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFAN_Contact_UID extends BWFAN_Merge_Tag {

		private static $instance = null;

		public function __construct() {
			$this->tag_name        = 'contact_hash_uid';
			$this->tag_description = __( 'Contact Hash UID', 'wp-marketing-automations' );
			add_shortcode( 'bwfan_contact_hash_uid', array( $this, 'parse_shortcode' ) );
			$this->priority         = 14.1;
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
			$cid = isset( $get_data['contact_id'] ) ? $get_data['contact_id'] : '';
			$uid = $this->get_uid( $cid );
			if ( ! empty( $uid ) ) {
				return $this->parse_shortcode_output( $uid, $attr );
			}

			/** If order */
			$order = isset( $get_data['wc_order'] ) ? $get_data['wc_order'] : '';
			if ( bwfan_is_woocommerce_active() && $order instanceof WC_Order ) {
				$cid = BWFAN_Woocommerce_Compatibility::get_order_data( $order, '_woofunnel_cid' );
				$uid = $this->get_uid( $cid );
				if ( ! empty( $uid ) ) {
					return $this->parse_shortcode_output( $uid, $attr );
				}
			}

			/** If user ID or email */
			$user_id = isset( $get_data['user_id'] ) ? $get_data['user_id'] : '';
			$email   = isset( $get_data['email'] ) ? $get_data['email'] : '';

			$contact = bwf_get_contact( $user_id, $email );
			if ( absint( $contact->get_id() ) > 0 ) {
				$cid = $contact->get_id();
				$uid = $this->get_uid( $cid );
				if ( ! empty( $uid ) ) {
					return $this->parse_shortcode_output( $uid, $attr );
				}
			}

			return $this->parse_shortcode_output( '', $attr );
		}

		/**
		 * Get contact unique ID i.e. uid
		 *
		 * @param $cid
		 *
		 * @return false|string
		 */
		public function get_uid( $cid ) {
			$cid = intval( $cid );
			if ( empty( $cid ) ) {
				return false;
			}

			$contact = new WooFunnels_Contact( '', '', '', $cid );
			if ( empty( $contact->get_id() ) ) {
				return false;
			}

			return $contact->get_uid();
		}

		/**
		 * Show dummy value of the current merge tag.
		 *
		 * @return string
		 *
		 */
		public function get_dummy_preview() {
			if ( ! method_exists( BWFAN_Merge_Tag::class, 'get_contact_data' ) ) {
				return '-';
			}

			$contact = $this->get_contact_data();

			/** checking contact instance and id */
			if ( ! $contact instanceof WooFunnels_Contact || 0 === absint( $contact->get_id() ) ) {
				return '-';
			}

			return $contact->get_uid();
		}

	}

	/**
	 * Register this merge tag to a group.
	 */
	BWFAN_Merge_Tag_Loader::register( 'bwf_contact', 'BWFAN_Contact_UID', null, __( 'Contact', 'wp-marketing-automations' ) );
}
