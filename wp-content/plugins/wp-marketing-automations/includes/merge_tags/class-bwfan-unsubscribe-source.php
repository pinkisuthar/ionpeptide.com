<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Merge tag: Unsubscribe Source
 *
 * Outputs the source of unsubscribe for the contact (e.g. Broadcast, Automation, Manual, Form, CSV).
 * Uses bwfan_message_unsubscribe.c_type. Filter bwfan_unsubscribe_source_labels can add more sources.
 */
#[\AllowDynamicProperties]
class BWFAN_Unsubscribe_Source extends BWFAN_Merge_Tag {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		$this->tag_name        = 'unsubscribe_source';
		$this->tag_description = __( 'Unsubscribe Source', 'wp-marketing-automations' );
		add_shortcode( 'bwfan_unsubscribe_source', array( $this, 'parse_shortcode' ) );
		$this->priority         = 25;
		$this->is_crm_broadcast = true;
	}

	/**
	 * Parse the merge tag and return the unsubscribe source label.
	 *
	 * @param array $attr Shortcode attributes (e.g. fallback).
	 * @return string
	 */
	public function parse_shortcode( $attr ) {
		if ( true === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
			return $this->parse_shortcode_output( $this->get_dummy_preview(), $attr );
		}

		$get_data = BWFAN_Merge_Tag_Loader::get_data();
		$contact  = $this->get_contact_object( $get_data );

		if ( ! $contact ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$recipients = array( $contact->get_email() );
		$phone      = $contact->get_contact_no();
		if ( ! empty( $phone ) ) {
			$recipients[] = $phone;
		}

		$latest = BWFAN_Model_Message_Unsubscribe::get_latest_unsubscribe_row( $recipients );
		if ( empty( $latest ) || ! isset( $latest['c_type'] ) ) {
			return $this->parse_shortcode_output( '', $attr );
		}

		$c_type = (int) $latest['c_type'];
		$label  = method_exists( 'BWFCRM_Contact', 'get_unsubscribe_source_label' ) ? BWFCRM_Contact::get_unsubscribe_source_label( $c_type ) : __( 'Manual', 'wp-marketing-automations' );
		$oid    = isset( $latest['automation_id'] ) ? absint( $latest['automation_id'] ) : 0;
		$sid    = isset( $latest['sid'] ) ? absint( $latest['sid'] ) : 0;

		if ( $oid > 0 ) {
			$label .= ' (#' . $oid . ')';
		}
		if ( BWFCRM_Contact::UNSUBSCRIBE_SOURCE_AUTOMATION === $c_type && $sid > 0 ) {
			$label .= ' ' . __( 'Step', 'wp-marketing-automations' ) . ' #' . $sid;
		}

		return $this->parse_shortcode_output( $label, $attr );
	}

	/**
	 * Get contact object from merge tag data (contact_id, user_id, email, or wc_order).
	 *
	 * @param array $get_data Merge tag data.
	 * @return WooFunnels_Contact|false
	 */
	protected function get_contact_object( $get_data ) {
		$cid = isset( $get_data['contact_id'] ) ? absint( $get_data['contact_id'] ) : 0;
		if ( $cid > 0 ) {
			$contact = new WooFunnels_Contact( '', '', '', $cid );
			if ( $contact->get_id() > 0 ) {
				return $contact;
			}
		}

		$email = isset( $get_data['email'] ) ? sanitize_email( $get_data['email'] ) : '';
		$uid  = isset( $get_data['user_id'] ) ? absint( $get_data['user_id'] ) : 0;
		if ( empty( $email ) && $uid > 0 ) {
			$user = get_user_by( 'id', $uid );
			if ( $user ) {
				$email = $user->user_email;
			}
		}
		if ( ! empty( $email ) ) {
			$contact = bwf_get_contact( $uid, $email );
			if ( $contact && $contact->get_id() > 0 ) {
				return $contact;
			}
		}

		$order = $this->get_order_object( $get_data );
		if ( $order instanceof WC_Order ) {
			$cid = BWFAN_Woocommerce_Compatibility::get_order_data( $order, '_woofunnel_cid' );
			if ( $cid ) {
				$contact = new WooFunnels_Contact( '', '', '', $cid );
				if ( $contact->get_id() > 0 ) {
					return $contact;
				}
			}
			$email = $order->get_billing_email();
			if ( $email ) {
				$contact = bwf_get_contact( 0, $email );
				if ( $contact && $contact->get_id() > 0 ) {
					return $contact;
				}
			}
		}

		return false;
	}

	/**
	 * Return dummy value for preview.
	 *
	 * @return string
	 */
	public function get_dummy_preview() {
		return __( 'Automation', 'wp-marketing-automations' ) . ' (#123) ' . __( 'Step', 'wp-marketing-automations' ) . ' #456';
	}
}

BWFAN_Merge_Tag_Loader::register( 'bwf_contact', 'BWFAN_Unsubscribe_Source', null, __( 'Contact', 'wp-marketing-automations' ) );
