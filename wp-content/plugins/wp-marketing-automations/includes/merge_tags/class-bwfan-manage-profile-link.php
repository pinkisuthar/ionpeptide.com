<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

#[\AllowDynamicProperties]
class BWFAN_Manage_Profile_Link extends BWFAN_Merge_Tag {

	private static $instance = null;

	public function __construct() {
		$this->tag_name        = 'profile_link';
		$this->tag_description = __( 'Contact Profile Link', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_profile_link', array( $this, 'parse_shortcode' ) );
		$this->support_fallback = false;
		$this->priority         = 24;
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
		if ( true === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
			return $this->get_dummy_preview();
		}

		$get_data            = BWFAN_Merge_Tag_Loader::get_data();
		$user_id             = isset( $get_data['user_id'] ) ? $get_data['user_id'] : '';
		$email               = isset( $get_data['email'] ) ? $get_data['email'] : '';
		$contact_id          = isset( $get_data['contact_id'] ) ? $get_data['contact_id'] : '';
		$manage_profile_link = $this->get_manage_page_url();

		/** If Contact ID */
		$uid = $this->get_uid( $contact_id );

		/** if user id || email */
		if ( empty( $uid ) ) {
			$contact = bwf_get_contact( $user_id, $email );
			if ( $contact->get_id() > 0 ) {
				$uid = $contact->get_uid();
			}
		}
		$query_args = array( 'uid' => $uid );

		if ( ! empty( $uid ) ) {
			$manage_profile_link = add_query_arg( $query_args, $manage_profile_link );
		}

		$manage_profile_link = apply_filters( 'bwfan_manage_profile_url', $manage_profile_link, $attr );

		return $this->parse_shortcode_output( $manage_profile_link, $attr );
	}

	/** get contact uid using contact id
	 *
	 * @param $cid
	 *
	 * @return false|string
	 *
	 */
	public function get_uid( $cid = '' ) {
		$cid = absint( $cid );
		if ( empty( $cid ) ) {
			return 0;
		}
		$contact = new WooFunnels_Contact( '', '', '', $cid );
		if ( $contact->get_id() > 0 ) {
			return $contact->get_uid();
		}

		return 0;
	}

	/** get the manage page url
	 * @return string
	 */
	public function get_manage_page_url() {
		$global_settings = BWFAN_Common::get_global_settings();
		if ( ! isset( $global_settings['bwfan_profile_page'] ) || empty( $global_settings['bwfan_profile_page'] ) ) {
			return '';
		}

		$page = absint( $global_settings['bwfan_profile_page'] );

		if ( ! $page || ! get_post( $page ) ) {
			return '';
		}

		return get_permalink( $page );
	}

	/**
	 * Show dummy value of the current merge tag.
	 *
	 * @return string
	 */
	public function get_dummy_preview() {
		$get_data            = BWFAN_Merge_Tag_Loader::get_data();
		$manage_profile_link = $this->get_manage_page_url();

		/** in case contact id is given via send test email */
		$contact_id = isset( $get_data['contact_id'] ) ? $get_data['contact_id'] : '';
		$uid        = $this->get_uid( $contact_id );
		if ( ! empty( $uid ) ) {
			return add_query_arg( array(
				'uid' => $uid
			), $manage_profile_link );
		}

		$email   = isset( $get_data['test_email'] ) ? $get_data['test_email'] : '';
		$contact = false;

		if ( ! empty( $email ) && is_email( $email ) ) {
			$contact = new WooFunnels_Contact( '', $email );
		}

		/** check for contact instance and id */
		if ( $contact instanceof WooFunnels_Contact && absint( $contact->get_id() ) > 0 ) {
			return add_query_arg( array(
				'uid' => $contact->get_uid()
			), $manage_profile_link );
		}

		/** get the current user details */
		$user = wp_get_current_user();
		if ( $user instanceof WP_User && $user->ID > 0 ) {
			$contact = new WooFunnels_Contact( $user->ID );
			if ( $contact instanceof WooFunnels_Contact && absint( $contact->get_id() ) > 0 ) {
				return add_query_arg( array(
					'uid' => $contact->get_uid()
				), $manage_profile_link );
			}
		}


		return $manage_profile_link;
	}
}

/**
 * Register this merge tag to a group.
 */
BWFAN_Merge_Tag_Loader::register( 'bwf_contact', 'BWFAN_Manage_Profile_Link', null, __( 'Contact', 'wp-marketing-automations' ) );
