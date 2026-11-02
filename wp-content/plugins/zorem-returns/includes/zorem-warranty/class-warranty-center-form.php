<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; no core caching API applies.
/**
 * Guest Warranty Claim shortcode — [warranty-center]
 *
 * Mirrors the [return-center] guest flow: the customer enters an order number
 * + billing email (email skipped when logged in), the order is verified, and
 * the warranty claim form (defect details + photo uploads) is rendered without
 * requiring a login. Submission goes through the existing
 * zorem_submit_warranty_claim AJAX handler in Return_Warranty, which verifies
 * the billing email again for guests.
 *
 * @package Zorem_Returns_Exchanges
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Warranty_Center_Form {

	public function __construct() {
		$this->init();
	}

	private static $instance;

	/**
	 * Get the class instance
	 *
	 * @since  1.6.8
	 */
	public static function get_instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/*
	 * init function
	 *
	 * @since  1.6.8
	 */
	public function init() {

		// Warranty center form shortcode for guest user
		add_shortcode( 'warranty-center', array( $this, 'display_warranty_center_form' ) );

		// Fetch warranty claim form by order number + email
		add_action( 'wp_ajax_fetch_warranty_request_data', array( $this, 'fetch_warranty_request_data_callback' ) );
		add_action( 'wp_ajax_nopriv_fetch_warranty_request_data', array( $this, 'fetch_warranty_request_data_callback' ) );
	}

	/**
	 * Get warranty center form from shortcode
	 *
	 * @since  1.6.8
	 */
	public function display_warranty_center_form() {
		ob_start();

		if ( ! isset( zorem_returns_exchanges()->warranty ) || ! zorem_returns_exchanges()->warranty->is_enabled() ) {
			?>
			<section class="return_center_container warranty_center_container">
				<p class="form-desc"><?php echo esc_html__( 'Warranty claims are currently not available.', 'zorem-returns' ); ?></p>
			</section>
			<?php
			return ob_get_clean();
		}
		?>
		<section class="return_center_container warranty_center_container">
			<form id="warranty-center-form" method="post">
				<div class="return_center_header">
					<h2 class="form-heading"><?php echo esc_html__( 'Warranty Claims', 'zorem-returns' ); ?></h2>
					<p class="form-desc"><?php echo esc_html__( "Let's track your order first", 'zorem-returns' ); ?></p>
				</div>
				<div class="container">
					<?php if ( ! is_user_logged_in() ) { ?>
					<label for="customer_email"><?php echo esc_html__( 'Email Address', 'zorem-returns' ); ?></label>
					<input type="text" name="customer_email" class="customer_email" required>
					<?php } ?>
					<label for="order_id"><?php echo esc_html__( 'Order ID', 'zorem-returns' ); ?></label>
					<input type="text" name="order_id" class="order_id" required>

					<button type="submit" class="request-center-btn warranty-center-btn button"><?php echo esc_html__( 'Find My Order', 'zorem-returns' ); ?></button>
					<input type="hidden" name="action" value="fetch_warranty_request_data">
					<?php $ajax_nonce = wp_create_nonce( 'warranty_center_form' ); ?>
					<input type="hidden" name="warranty_center_nonce" id="warranty_center_nonce" value="<?php echo esc_html( $ajax_nonce ); ?>" />
				</div>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Fetch the guest warranty claim form by order number + email
	 *
	 * @since  1.6.8
	 */
	public function fetch_warranty_request_data_callback() {
		if ( ! empty( $_POST ) && check_ajax_referer( 'warranty_center_form', 'warranty_center_nonce' ) ) {

			$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
			$order_id = zorem_returns_exchanges()->rxCommon->get_formated_order_id( $order_id );

			$customer_email = '';
			if ( ! is_user_logged_in() ) {
				$customer_email = isset( $_POST['customer_email'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_email'] ) ) : '';
			}

			$order = ! empty( $order_id ) ? wc_get_order( $order_id ) : '';

			if ( empty( $order ) ) {
				wp_send_json_error( array( 'error' => __( 'This order number does not exist.', 'zorem-returns' ) ) );
				die();
			}

			if ( ! empty( $customer_email ) && strtolower( $order->get_billing_email() ) !== strtolower( $customer_email ) ) {
				wp_send_json_error( array( 'error' => __( 'This email address does not match the order number.', 'zorem-returns' ) ) );
				die();
			}

			// Logged-in customers may only look up their own orders.
			if ( is_user_logged_in() && (int) $order->get_customer_id() !== get_current_user_id() && strtolower( $order->get_billing_email() ) !== strtolower( wp_get_current_user()->user_email ) ) {
				wp_send_json_error( array( 'error' => __( 'You do not have permission to view this order.', 'zorem-returns' ) ) );
				die();
			}

			$warranty = zorem_returns_exchanges()->warranty;

			if ( ! $warranty->is_enabled() ) {
				wp_send_json_error( array( 'error' => __( 'Warranty claims are currently not available.', 'zorem-returns' ) ) );
				die();
			}

			$eligible        = $warranty->get_eligible_items( $order );
			$existing_claims = $warranty->get_claims_by_order( $order->get_id() );

			if ( empty( $eligible ) && empty( $existing_claims ) ) {
				// Give a more helpful message while the return window is still open.
				if ( ! $warranty->is_past_return_window( $order ) ) {
					wp_send_json_error( array( 'error' => __( 'This order is still within the return window. Please submit a return or exchange request instead.', 'zorem-returns' ) ) );
					die();
				}
				wp_send_json_error( array( 'error' => __( 'No items in this order are eligible for a warranty claim.', 'zorem-returns' ) ) );
				die();
			}

			$reasons = $warranty->get_reasons();
			// Passed into the claim form as a hidden field so Return_Warranty::ajax_submit_claim()
			// can re-verify guest ownership at submission time.
			$guest_email = $customer_email;

			ob_start();
			include __DIR__ . '/htmls/guest-warranty-center-form.php';
			$warranty_center_order_content = ob_get_clean();

			wp_send_json_success( array( 'warranty_center_order_content' => $warranty_center_order_content ) );
			die();
		}
	}
}
