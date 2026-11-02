<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

#[\AllowDynamicProperties]
class BWFAN_Contact_Subscribe_Link extends BWFAN_Merge_Tag {

	private static $instance = null;
	protected $support_v2 = true;
	protected $support_v1 = false;


	public function __construct() {
		$this->tag_name        = 'contact_subscribe_link';
		$this->tag_description = __( 'Subscribe URL', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_contact_subscribe_link', array( $this, 'parse_shortcode' ) );
		add_shortcode( 'bwfan_contact_confirmation_link', array( $this, 'parse_shortcode' ) );
		$this->priority = 23;
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
	 * Form context: uses form_feed_id, form notification "Redirect After Confirmation" and tag settings (same as contact_confirmation_link).
	 * Automation/Broadcast: uses automation_id and redirect attr or global setting.
	 *
	 * @param array $attr Shortcode attributes e.g. ['redirect' => 'https://...']
	 *
	 * @return string
	 */
	public function parse_shortcode( $attr ) {
		if ( true === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
			return $this->parse_shortcode_output( $this->get_dummy_preview(), $attr );
		}

		$data          = BWFAN_Merge_Tag_Loader::get_data();
		$contact_id    = isset( $data['cid'] ) ? $data['cid'] : ( isset( $data['contact_id'] ) ? $data['contact_id'] : '' );
		$form_feed_id  = isset( $data['form_feed_id'] ) ? $data['form_feed_id'] : '';
		$automation_id = isset( $data['automation_id'] ) ? $data['automation_id'] : '';

		/** Form notification context: confirmation link. Same URL structure as Pro contact_confirmation_link. */
		if ( ! empty( $form_feed_id ) ) {
			return $this->get_form_feed_subscribe_url( $contact_id, $form_feed_id, $attr );
		}

		/** Automation / Broadcast context: require contact_id and automation_id, redirect from attr or global */
		if ( empty( $contact_id ) || empty( $automation_id ) ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$contact = new WooFunnels_Contact( '', '', '', intval( $contact_id ) );
		if ( ! $contact instanceof WooFunnels_Contact || 0 === intval( $contact->get_id() ) ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$redirect_url = isset( $attr['redirect'] ) && ! empty( trim( $attr['redirect'] ) ) ? trim( $attr['redirect'] ) : '';
		if ( ! empty( $redirect_url ) && method_exists( 'BWFAN_Common', 'decode_merge_tags' ) ) {
			$redirect_url = BWFAN_Common::decode_merge_tags( $redirect_url );
		}

		$args = [
			'bwfan-action'  => 'subscribe',
			'automation-id' => absint( $automation_id ),
			'bwfan-uid'     => $contact->get_uid(),
		];

		/** Add redirect: use merge tag redirect attribute, else global setting */
		if ( empty( $redirect_url ) ) {
			$general_options   = BWFAN_Common::get_global_settings();
			$confirmation_type = isset( $general_options['after_confirmation_type'] ) ? $general_options['after_confirmation_type'] : 'show_message';
			if ( 'show_message' !== $confirmation_type ) {
				$redirect_url = ! empty( $general_options['bwfan_confirmation_redirect_url'] ) ? trim( $general_options['bwfan_confirmation_redirect_url'] ) : home_url( '/' );
			}
		}

		if ( ! empty( $redirect_url ) && false !== BWFAN_Common::bwfan_is_valid_link( $redirect_url ) ) {
			/** Insert link into database for validation on click */
			if ( BWFAN_Core()->conversation && method_exists( BWFAN_Core()->conversation, 'maybe_insert_link' ) ) {
				BWFAN_Core()->conversation->maybe_insert_link( $redirect_url, [ 'type' => 1, 'oid' => $automation_id ] );
			}
			$args['bwfan-link'] = rawurlencode( $redirect_url );
		}

		return add_query_arg( $args, esc_url_raw( home_url( '/' ) ) );
	}

	/**
	 * Build form feed confirmation URL. Matches Pro contact_confirmation_link: bwfan-action=incentive, feed-id, bwfan-uid.
	 * Redirect is fetched by BWFCRM_Forms::handle_incentive_email when link is clicked.
	 *
	 * @param int|string $contact_id   Contact ID.
	 * @param int        $form_feed_id Form feed ID.
	 * @param array      $attr         Shortcode attributes.
	 *
	 * @return string Confirmation URL or empty output for invalid.
	 */
	private function get_form_feed_subscribe_url( $contact_id, $form_feed_id, $attr ) {
		if ( empty( $contact_id ) ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$uid = '';
		if ( class_exists( 'BWFCRM_Contact' ) && class_exists( 'BWFCRM_Form_Feed' ) ) {
			$contact = new BWFCRM_Contact( absint( $contact_id ) );
			$feed    = new BWFCRM_Form_Feed( absint( $form_feed_id ) );
			if ( ! $contact->is_contact_exists() || ! $feed->is_feed_exists() ) {
				return $this->parse_shortcode_output( '', $attr );
			}
			$uid = $contact->contact->get_uid();
		} else {
			$contact = new WooFunnels_Contact( '', '', '', intval( $contact_id ) );
			if ( ! $contact instanceof WooFunnels_Contact || 0 >= intval( $contact->get_id() ) ) {
				return $this->parse_shortcode_output( '', $attr );
			}
			$uid = $contact->get_uid();
		}

		if ( empty( $uid ) ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$args = [
			'bwfan-action' => 'incentive',
			'feed-id'      => absint( $form_feed_id ),
			'bwfan-uid'    => $uid,
		];

		return add_query_arg( $args, esc_url_raw( home_url( '/' ) ) );
	}

	/**
	 * Show dummy value of the current merge tag.
	 *
	 * @return string
	 */
	public function get_dummy_preview() {
		return '';
	}

	/**
	 * Return mergetag schema
	 *
	 * @return array[]
	 */
	public function get_setting_schema() {
		return [
			[
				'id'          => 'redirect',
				'label'       => __( 'Redirect', 'wp-marketing-automations' ),
				'type'        => 'text',
				'class'       => '',
				'placeholder' => '',
				'required'    => false,
				'toggler'     => array(),
			],
		];
	}
}

/**
 * Register this merge tag (lite and pro). Form notification uses form feed redirect and tag settings; automation/broadcast uses redirect attr or global.
 */
BWFAN_Merge_Tag_Loader::register( 'bwf_contact', 'BWFAN_Contact_Subscribe_Link', null, __( 'Contact', 'wp-marketing-automations' ) );
