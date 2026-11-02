<?php
/**
 * Implements compatibility with YITH Subscription plugin
 *
 * @class   YITH_YWPI_Subscription_Compatibility
 * @package YITH\PDF_Invoice\Compatibilities
 * @since   1.0.0
 * @author  YITH <plugins@yithemes.com>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


/**
 * The class
 */
class YITH_YWPI_Subscription_Compatibility {


	/**
	 * Single instance of the class
	 *
	 * @var \YITH_YWPI_Backend
	 * @since 1.0.0
	 */
	protected static $instance;

	/**
	 * Returns single instance of the class
	 *
	 * @since 1.0.0
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {

		$pro_forma_enabled = ywpi_get_option( 'ywpi_enable_pro_forma' );
		$pro_forma_on_renews = get_option('ywpi_enable_pro_forma_renews', 'no');
		if( 'yes' === $pro_forma_enabled && 'yes' === $pro_forma_on_renews ) {
			add_action( 'ywsbs_renew_order_saved', array( $this, 'maybe_create_pro_forma' ) , 10 , 1 );
		}
	}

	/**
	 * Check if necessary create the pro-forma on renews
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return void
	 */
	public function maybe_create_pro_forma( $order ) {


		$payment_gateway_id = $order->get_payment_method();

		$gateways = WC_Payment_Gateways::instance();
		$all_gateways = $gateways->payment_gateways();
		if( 'yes' === $order->get_meta( 'is_a_renew' ) && isset( $all_gateways[ $payment_gateway_id ] ) && !$all_gateways[ $payment_gateway_id ]->supports( 'yith_subscriptions' ) ) {
			add_filter('yith_ywpi_pro_forma_attachment_on_emails_ids', array( $this, 'add_customer_invoice_on_renews') );
			YITH_WooCommerce_Pdf_Invoice_Premium::get_instance()->create_automatic_proforma( $order->get_id() );
			WC()->mailer()->customer_invoice( $order );
			remove_filter('yith_ywpi_pro_forma_attachment_on_emails_ids', array( $this, 'add_customer_invoice_on_renews') );
		}
	}

	/**
	 * Allow to add the attachment on customer invoice email.
	 *
	 * @param array $email_ids The email ids.
	 *
	 * @return array
	 */
	public function add_customer_invoice_on_renews( $email_ids ) {
		$email_ids[] = 'customer_invoice';
		return $email_ids;
	}
}