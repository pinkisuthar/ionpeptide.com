<?php
/**
 * Static class that will init emails for the plugin
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Emails' ) ) {
	/**
	 * Affiliates Emails Handler
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Emails {

		/**
		 * Available email ids
		 *
		 * @var array
		 */
		protected static $emails = array();

		/**
		 * Available email objects
		 *
		 * @var $classes YITH_WCAF_Abstract_Email[]
		 */
		protected static $classes = array();

		/**
		 * Performs all required actions to handle emails
		 *
		 * @return void
		 */
		public static function init() {
			// Include email classes.
			include_once WC()->plugin_path() . '/includes/emails/class-wc-email.php';

			// register existing plugin's emails.
			add_filter( 'woocommerce_email_classes', array( self::class, 'register_classes' ), 10, 1 );
			add_filter( 'woocommerce_email_actions', array( self::class, 'register_actions' ), 10, 1 );

			// append commissions data to New Order email.
			add_action( 'woocommerce_email_order_meta', array( self::class, 'add_commissions_table' ), 10, 4 );
		}

		/**
		 * Returns available emails
		 *
		 * @return array
		 */
		public static function get_emails() {
			if ( empty( self::$emails ) ) {
				self::$emails = array(
					'admin_new_affiliate',
					'admin_affiliate_status_changed',
					'admin_affiliate_banned',
					'admin_commission_status_changed',
					'admin_paid_commission',
					'affiliate_enabled',
					'affiliate_disabled',
					'affiliate_banned',
					'new_affiliate',
					'new_affiliate_commission',
					'new_affiliate_payment',
					'new_affiliate_coupon',
				);
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_emails
			 *
			 * Filters the available emails.
			 *
			 * @param array $emails Available emails.
			 */
			return apply_filters( 'yith_wcaf_emails', self::$emails );
		}

		/* === INIT METHODS === */

		/**
		 * Init emails objects for future usage
		 *
		 * @return YITH_WCAF_Abstract_Email[] Array of email objects.
		 */
		public static function init_classes() {
			if ( empty( self::$classes ) ) {
				$emails = self::get_emails();

				if ( empty( $emails ) ) {
					return self::$classes;
				}

				foreach ( $emails as $email ) {
					$email_class = "YITH_WCAF_{$email}_Email";

					if ( ! class_exists( $email_class ) ) {
						continue;
					}

					self::$classes[ $email_class ] = new $email_class();
				}
			}

			return apply_filters( 'yith_wcaf_emails', self::$classes );
		}

		/**
		 * Init email classes
		 *
		 * @param array $classes Available email classes.
		 * @return array Array of filtered email classes.
		 */
		public static function register_classes( $classes ) {
			$plugin_classes = self::init_classes();

			if ( empty( $plugin_classes ) ) {
				return $classes;
			}

			$classes = array_merge(
				$classes,
				$plugin_classes
			);

			return $classes;
		}

		/**
		 * Register trigger actions for the emails
		 *
		 * @param array $actions Emailer actions.
		 *
		 * @return array Array of emailer actions.
		 */
		public static function register_actions( $actions ) {
			$classes = self::init_classes();

			if ( empty( $classes ) ) {
				return $actions;
			}

			foreach ( $classes as $email ) {
				$actions = array_merge(
					$actions,
					(array) $email->get_trigger()
				);
			}

			return $actions;
		}

		/* === COMMISSIONS TABLE IN EMAILS === */

		/**
		 * Add commissions table template into "Admin New Order" email
		 *
		 * @param \WC_Order $order         Current order.
		 * @param bool      $sent_to_admin Whether email is sent to admin or not.
		 * @param bool      $plain_text    Whether email has HTML content or plain text content.
		 * @param \WC_Email $email         Current email object.
		 *
		 * @return void
		 * @since 1.1.1
		 */
		public static function add_commissions_table( $order, $sent_to_admin, $plain_text, $email = false ) {
			if ( ! $sent_to_admin || ! $email || ! isset( $email->id ) || 'new_order' !== $email->id || apply_filters( 'yith_wcaf_hide_commissions_table_new_order_email', false ) ) {
				return;
			}

			$affiliate   = YITH_WCAF_Affiliate_Factory::get_affiliate_by_order_id( $order );
			$commissions = YITH_WCAF_Commission_Factory::get_commissions(
				array(
					'order_id' => $order->get_id(),
				)
			);

			if ( ! $affiliate || 0 >= count( $commissions ) ) {
				return;
			}

			self::print_commissions_table(
				$commissions,
				$plain_text,
				array(
					'affiliate'     => $affiliate,
					'order'         => $order,
					'token'         => $affiliate->get_token(),
					'sent_to_admin' => true,
				)
			);
		}

		/**
		 * Print commissions table
		 *
		 * @param YITH_WCAF_Commissions_Collection $commissions Commissions to print.
		 * @param bool                             $plain_text  Whether to show HTML content or plain text content.
		 * @param array                            $args        Array of additional arguments to pass to the template.
		 */
		public static function print_commissions_table( $commissions, $plain_text, $args = array() ) {
			$subsection = $plain_text ? '/plain' : '';

			yith_wcaf_get_template(
				'commissions-table.php',
				array_merge(
					array(
						'commissions'   => $commissions,
						'sent_to_admin' => false,
					),
					$args
				),
				'emails' . $subsection
			);
		}
	}
}
