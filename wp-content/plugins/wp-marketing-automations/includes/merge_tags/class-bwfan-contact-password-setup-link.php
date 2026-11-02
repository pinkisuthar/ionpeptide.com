<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

#[\AllowDynamicProperties]
class BWFAN_Contact_Password_Setup_Link extends BWFAN_Merge_Tag {

	private static $instance = null;

	public function __construct() {
		$this->tag_name        = 'contact_password_setup_link';
		$this->tag_description = __( 'Contact User Password Setup Link', 'wp-marketing-automations' );

		add_shortcode( 'bwfan_contact_password_setup_link', array( $this, 'parse_shortcode' ) );
		$this->priority = 14.5;
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

		$get_data = BWFAN_Merge_Tag_Loader::get_data();

		$user_id = isset( $get_data['user_id'] ) ? $get_data['user_id'] : '';
		if ( ! empty( $user_id ) ) {
			$key  = isset( $get_data['user_reset_key'] ) ? $get_data['user_reset_key'] : '';
			$link = $this->get_reset_link( $user_id, false, $key );
			if ( ! empty( $link ) ) {
				return $this->parse_shortcode_output( $link, $attr );
			}
		}

		$cid = isset( $get_data['contact_id'] ) ? $get_data['contact_id'] : '';
		if ( ! empty( $cid ) && intval( $cid ) > 0 ) {
			$contact = new WooFunnels_Contact( '', '', '', $cid );
			if ( intval( $contact->get_id() ) > 0 && intval( $contact->get_wpid() ) > 0 ) {
				$link = $this->get_reset_link( $contact->get_wpid() );
				if ( ! empty( $link ) ) {
					return $this->parse_shortcode_output( $link, $attr );
				}
			}
		}

		$email = isset( $get_data['email'] ) ? $get_data['email'] : '';
		if ( ! empty( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user instanceof WP_User ) {
				$link = $this->get_reset_link( $user->ID, $user );

				return $this->parse_shortcode_output( $link, $attr );
			}
		}

		return $this->parse_shortcode_output( home_url(), $attr );
	}

	/**
	 * Return reset link of a user
	 *
	 * @param $user_id
	 * @param $user WP_User
	 *
	 * @return string
	 */
	protected function get_reset_link( $user_id, $user = false, $key = '' ) {
		if ( empty( $user ) ) {
			$user = new WP_User( $user_id );
			if ( ! $user instanceof WP_User || ! wp_is_password_reset_allowed_for_user( $user ) ) {
				return '';
			}
		}

		if ( empty( $key ) ) {
			$key = get_password_reset_key( $user );

			if ( is_wp_error( $key ) ) {
				return '';
			}
		}
		/** Check if WooCommerce is active then redirect to my account page */
		if ( bwfan_is_woocommerce_active() ) {
			$lost_password_url = 'publish' === get_post_status( wc_get_page_id( 'myaccount' ) ) ? wc_get_account_endpoint_url( 'lost-password' ) : '';

			if ( ! empty( $lost_password_url ) ) {
				return add_query_arg( array(
					'action' => 'rp',
					'key'    => rawurlencode( $key ),
					'login'  => rawurlencode( $user->user_login ),
				), $lost_password_url );
			}
		}

		return add_query_arg( array(
			'action' => 'rp',
			'key'    => rawurlencode( $key ),
			'login'  => rawurlencode( $user->user_login ),
		), wp_login_url() );
	}

	/**
	 * Show dummy value of the current merge tag
	 *
	 * @return string
	 */
	public function get_dummy_preview() {
		$get_data = BWFAN_Merge_Tag_Loader::get_data();
		/**  Check user ID  */
		$user_id = self::get_preview_user_id( $get_data );
		if ( empty( $user_id ) ) {
			return home_url();
		}
		$link = $this->get_reset_link( $user_id );

		return ! empty( $link ) ? $link : home_url();
	}

	/**
	 * Retrieve the preview user ID based on provided data or current user session.
	 *
	 * @param array $get_data An array of data that may include 'contact_id' or 'test_email'.
	 *
	 * @return int|null The WordPress user ID if found, or null if no valid user ID is retrievable.
	 */
	public static function get_preview_user_id( $get_data ) {
		/** if contact id is available */
		if ( ! empty( $get_data['contact_id'] ) ) {
			$contact = new WooFunnels_Contact( '', '', '', $get_data['contact_id'] );
			if ( $contact->get_id() > 0 && $contact->get_wpid() > 0 ) {
				return $contact->get_wpid();
			}
		}

		/** if test email is available */
		if ( ! empty( $get_data['test_email'] ) && is_email( $get_data['test_email'] ) ) {
			$contact = new WooFunnels_Contact( '', $get_data['test_email'] );
			if ( $contact->get_id() > 0 && $contact->get_wpid() > 0 ) {
				return $contact->get_wpid();
			}
		}

		/** if user is logged in */
		$user = wp_get_current_user();
		if ( $user instanceof WP_User && $user->ID > 0 ) {
			return $user->ID;
		}

		return null;
	}
}

/**
 * Register this merge tag to a group
 */
BWFAN_Merge_Tag_Loader::register( 'bwf_contact', 'BWFAN_Contact_password_setup_link', null, __( 'Contact', 'wp-marketing-automations' ) );