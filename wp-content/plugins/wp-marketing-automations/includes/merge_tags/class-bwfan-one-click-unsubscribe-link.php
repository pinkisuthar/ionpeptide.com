<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

#[\AllowDynamicProperties]
class BWFAN_One_Click_Unsubscribe_Link extends BWFAN_Merge_Tag {

	private static $instance = null;

	public function __construct() {
		$this->tag_name        = 'one_click_unsubscribe_link';
		$this->tag_description = esc_html__( 'One Click Unsubscribe Link', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_one_click_unsubscribe_link', array( $this, 'parse_shortcode' ) );
		$this->support_fallback = false;
		$this->priority         = 25;
		$this->support_v1       = false;
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

		$get_data     = BWFAN_Merge_Tag_Loader::get_data();
		$user_id      = isset( $get_data['user_id'] ) ? $get_data['user_id'] : '';
		$email        = isset( $get_data['email'] ) ? $get_data['email'] : '';
		$contact_id   = isset( $get_data['contact_id'] ) ? $get_data['contact_id'] : '';
		$broadcast_id = isset( $get_data['broadcast_id'] ) ? $get_data['broadcast_id'] : '';
		$form_feed_id = isset( $get_data['form_feed_id'] ) ? $get_data['form_feed_id'] : '';
		$template_id  = isset( $get_data['template_id'] ) ? $get_data['template_id'] : '';

		$unsubscribe_link = $this->get_unsubscribe_page_url();

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
		if ( ! empty( $broadcast_id ) ) {
			$query_args['bid'] = $broadcast_id;
		}
		if ( ! empty( $form_feed_id ) ) {
			$query_args['form_feed_id'] = absint( $form_feed_id );
		}

		/** Set template id to identify the transactional mail for unsubscribe link */
		if ( ! empty( $template_id ) ) {
			$query_args['tid'] = intval( $template_id );
		}
		
		if ( ! empty( $uid ) ) {
			$unsubscribe_link = add_query_arg( $query_args, $unsubscribe_link );
		}

		$unsubscribe_link = apply_filters( 'bwfan_unsubscribe_link', $unsubscribe_link, $attr );

		return $this->parse_shortcode_output( $unsubscribe_link, $attr );
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

	/** get the unsubscribe page url
	 * @return string
	 */
	public function get_unsubscribe_page_url() {
		$global_settings = BWFAN_Common::get_global_settings();
		$page_exist      = class_exists( 'BWFAN_Common' ) ? BWFAN_Common::is_unsubscribe_page_valid() : array();
		$page_url        = home_url( '/' );

		if ( isset( $page_exist ) && 1 === $page_exist['status'] ) {
			// Return home URL as fallback when unsubscribe page is not set
			$page     = absint( $global_settings['bwfan_unsubscribe_page'] );
			$page_url = get_permalink( $page );
		}

		$unsubscribe_link = add_query_arg( array(
			'bwfan-action'     => 'unsubscribe',
			'List-Unsubscribe' => 'One-Click' // Adding the List-Unsubscribe parameter
		), $page_url );

		return $unsubscribe_link;
	}

	/**
	 * Show dummy value of the current merge tag.
	 *
	 * @return string
	 */
	public function get_dummy_preview() {
		$get_data         = BWFAN_Merge_Tag_Loader::get_data();
		$unsubscribe_link = $this->get_unsubscribe_page_url();

		/** in case contact id is given via send test email */
		$contact_id = isset( $get_data['contact_id'] ) ? $get_data['contact_id'] : '';
		$uid        = $this->get_uid( $contact_id );
		if ( ! empty( $uid ) ) {
			return add_query_arg( array(
				'uid' => $uid
			), $unsubscribe_link );
		}

		$email             = isset( $get_data['test_email'] ) ? $get_data['test_email'] : '';
		$unsubscriber_name = 'John';
		$contact           = false;

		if ( ! empty( $email ) && is_email( $email ) ) {
			$contact = new WooFunnels_Contact( '', $email );
		}

		/** check for contact instance and id */
		if ( $contact instanceof WooFunnels_Contact && absint( $contact->get_id() ) > 0 ) {
			return add_query_arg( array(
				'uid' => $contact->get_uid()
			), $unsubscribe_link );
		}

		/** get the current user details */
		$user = wp_get_current_user();
		if ( $user instanceof WP_User && $user->ID > 0 ) {
			$contact = new WooFunnels_Contact( $user->ID );
			if ( $contact instanceof WooFunnels_Contact && absint( $contact->get_id() ) > 0 ) {
				return add_query_arg( array(
					'uid' => $contact->get_uid()
				), $unsubscribe_link );
			}

			$email             = $user->user_email;
			$unsubscriber_name = ! empty( $user->first_name ) ? $user->first_name : $unsubscriber_name;
		}

		$unsubscribe_link = add_query_arg( array(
			'subscriber_recipient' => $email,
			'subscriber_name'      => $unsubscriber_name,
		), $unsubscribe_link );

		return $unsubscribe_link;
	}
}

/**
 * Register this merge tag to a group.
 */
BWFAN_Merge_Tag_Loader::register( 'bwf_contact', 'BWFAN_One_Click_Unsubscribe_Link', null, __( 'Contact', 'wp-marketing-automations' ) );
