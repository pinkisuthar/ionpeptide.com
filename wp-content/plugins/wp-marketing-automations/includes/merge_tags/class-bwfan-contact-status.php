<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'BWFAN_Contact_Status' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFAN_Contact_Status extends BWFAN_Merge_Tag {

		private static $instance = null;

		public function __construct() {
			$this->tag_name        = 'contact_status';
			$this->tag_description = __( 'Contact Status', 'wp-marketing-automations' );
			add_shortcode( 'bwfan_contact_status', array( $this, 'parse_shortcode' ) );
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
			$cid     = isset( $get_data['contact_id'] ) ? $get_data['contact_id'] : 0;
			$contact = new BWFCRM_Contact( $cid );
			if ( ! $contact->is_contact_exists() ) {
				return $this->parse_shortcode_output( '', $attr );
			}

			if ( ! empty( absint( $contact->get_display_status() ) ) ) {
				switch ( $contact->get_display_status() ) {
					case BWFCRM_Contact::$DISPLAY_STATUS_UNSUBSCRIBED:
						return __( 'Unsubscribed', 'wp-marketing-automations' );
					case BWFCRM_Contact::$DISPLAY_STATUS_SUBSCRIBED:
						return __( 'Subscribed', 'wp-marketing-automations' );
					case BWFCRM_Contact::$DISPLAY_STATUS_UNVERIFIED:
						return __( 'Unverified', 'wp-marketing-automations' );
					case BWFCRM_Contact::$DISPLAY_STATUS_BOUNCED:
						return __( 'Bounced', 'wp-marketing-automations' );
					case BWFCRM_Contact::$DISPLAY_STATUS_COMPLAINT:
						return __( 'Complaint', 'wp-marketing-automations' );
					case BWFCRM_Contact::$DISPLAY_STATUS_SOFT_BOUNCED:
						return __( 'Soft Bounced', 'wp-marketing-automations' );
				}
			}

			return $this->parse_shortcode_output( '', $attr );
		}

		/**
		 * Show dummy value of the current merge tag.
		 *
		 * @return string
		 *
		 */
		public function get_dummy_preview() {
			return '';
		}

	}

	/**
	 * Register this merge tag to a group.
	 */
	BWFAN_Merge_Tag_Loader::register( 'bwf_contact', 'BWFAN_Contact_Status', null, __( 'Contact', 'wp-marketing-automations' ) );
}