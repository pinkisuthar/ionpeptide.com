<?php
/**
 * General email handling
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Abstract_Email' ) ) {
	/**
	 * Wraps general method used in email sending
	 *
	 * @since 1.0.0
	 */
	abstract class YITH_WCAF_Abstract_Email extends WC_Email {

		/**
		 * Action that triggers email sending
		 *
		 * @var string|array
		 */
		protected $trigger = '';

		/**
		 * List of placeholders that can be used in email content.
		 *
		 * @var array
		 */
		protected $available_placeholders = array();

		/**
		 * List of placeholders that can be used in plain email content.
		 *
		 * @var array
		 */
		protected $available_text_placeholders = array();

		/**
		 * Constructor method
		 */
		public function __construct() {
			// Triggers for this email.
			$triggers = (array) self::get_trigger();

			foreach ( $triggers as $trigger ) {
				add_action( "{$trigger}_notification", array( $this, 'trigger' ), 10, 99 );
			}

			// Call parent constructor.
			parent::__construct();
		}

		/**
		 * Returns trigger action for current email
		 *
		 * @return string|array
		 */
		public function get_trigger() {
			/**
			 * APPLY_FILTERS: yith_wcaf_email_trigger
			 *
			 * Filters the trigger of the email.
			 *
			 * @param string                   $trigger Email trigger.
			 * @param YITH_WCAF_Abstract_Email $email   Email object.
			 */
			return apply_filters( 'yith_wcaf_email_trigger', $this->trigger, self::class );
		}

		/**
		 * Init form fields to display in WC admin pages
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'recipient'  => array(
					'title'       => _x( 'Recipient(s)', '[EMAILS] General options', 'yith-woocommerce-affiliates' ),
					'type'        => 'text',
					// Translators: 1. Default value for Recipients.
					'description' => sprintf( _x( 'Enter recipients (comma separated) for this email. Defaults to <code>%s</code>.', '[EMAILS] General options', 'yith-woocommerce-affiliates' ), esc_attr( get_option( 'admin_email' ) ) ),
					'placeholder' => '',
					'default'     => '',
				),
				'subject'    => array(
					'title'       => _x( 'Subject', '[EMAILS] General options', 'yith-woocommerce-affiliates' ),
					'type'        => 'text',
					// Translators: 1. Default value for Subject.
					'description' => sprintf( _x( 'This controls the email subject line. Leave blank to use the default subject: <code>%s</code>.', '[EMAILS] General options', 'yith-woocommerce-affiliates' ), $this->subject ),
					'placeholder' => '',
					'default'     => '',
				),
				'heading'    => array(
					'title'       => _x( 'Email heading', '[EMAILS] General options', 'yith-woocommerce-affiliates' ),
					'type'        => 'text',
					// Translators: 1. Default value for Heading.
					'description' => sprintf( _x( 'This controls the main heading contained in the email notification. Leave blank to use the default heading: <code>%s</code>.', '[EMAILS] General options', 'yith-woocommerce-affiliates' ), $this->heading ),
					'placeholder' => '',
					'default'     => '',
				),
				'email_type' => array(
					'title'       => _x( 'Email type', '[EMAILS] General options', 'yith-woocommerce-affiliates' ),
					'type'        => 'select',
					'description' => _x( 'Choose which format of email to send.', '[EMAILS] General options', 'yith-woocommerce-affiliates' ),
					'default'     => 'html',
					'class'       => 'email_type wc-enhanced-select',
					'options'     => $this->get_email_type_options(),
				),
			);
		}

	}
}
