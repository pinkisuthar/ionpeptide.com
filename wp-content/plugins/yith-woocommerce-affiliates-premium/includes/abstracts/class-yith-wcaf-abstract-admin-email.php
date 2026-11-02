<?php
/**
 * General admin email handling
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Abstract_Admin_Email' ) ) {
	/**
	 * Wraps general method used in admin email sending
	 *
	 * @since 1.0.0
	 */
	abstract class YITH_WCAF_Abstract_Admin_Email extends YITH_WCAF_Abstract_Email {

		/**
		 * Returns admin recipient for admin-only emails
		 *
		 * @return string Recipient address.
		 */
		public function get_recipient() {
			// set recipient for the email according to configuration.
			$recipient = $this->get_option( 'recipient' );

			if ( ! $recipient ) {
				$recipient = get_option( 'admin_email' );
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_$id_email_admin_recipient
			 *
			 * Filters the email recipient for the admin emails.
			 * <code>$id</code> will be replaced with the email id.
			 *
			 * @param string $recipient Email recipient.
			 */
			$this->recipient = apply_filters( "yith_wcaf_{$this->id}_email_admin_recipient", $recipient );

			// returns recipient.
			return parent::get_recipient();
		}

	}
}
