<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Center_Form {

	public function __construct() {
		$this->init();
	}

	private static $instance;

	/**
	 * Get the class instance
	 *
	 * @since  1.0
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
	 * @since  1.0
	 */
	public function init() {

		// Return center form shortcode for guest user
		add_shortcode( 'return-center', array( $this, 'display_return_center_form' ) );

		// Fetch return center form
		add_action( 'wp_ajax_fetch_return_request_data', array( $this, 'fetch_return_request_data_callback' ) );
		add_action( 'wp_ajax_nopriv_fetch_return_request_data', array( $this, 'fetch_return_request_data_callback' ) );

		// Fetch return form data
		add_action( 'wp_ajax_fetch_guest_return_form', array( $this, 'fetch_guest_return_form_data_callback' ) );
		add_action( 'wp_ajax_nopriv_fetch_guest_return_form', array( $this, 'fetch_guest_return_form_data_callback' ) );

		// Fetch return items data
		add_action( 'wp_ajax_fetch_return_items_data', array( $this, 'fetch_return_items_data_callback' ) );
		add_action( 'wp_ajax_nopriv_fetch_return_items_data', array( $this, 'fetch_return_items_data_callback' ) );

		// Save return request data
		add_action( 'wp_ajax_save_return_center_data', array( $this, 'save_return_center_data_callback' ) );
		add_action( 'wp_ajax_nopriv_save_return_center_data', array( $this, 'save_return_center_data_callback' ) );

		// return request cancel actions
		add_action( 'wp_ajax_return_request_cancel', array( $this, 'return_request_cancel_callback' ) );
		add_action( 'wp_ajax_nopriv_return_request_cancel', array( $this, 'return_request_cancel_callback' ) );

		// Admin-initiated return request from the order edit screen (no nopriv — admins only)
		add_action( 'wp_ajax_zorem_admin_create_return', array( $this, 'admin_create_return_callback' ) );
	}

	/**
	 * Get return center form from shortcode
	 *
	 * @since  1.0.0
	 */
	public function display_return_center_form() {
		ob_start();
		$order_id = isset( $_GET['order_id'] ) ? sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) : '';
		$order    = ! empty( $order_id ) ? wc_get_order( $order_id ) : '';
		?>
		<section class="return_center_container" >
			<form id="return-center-form" method="post" >
				<div class="return_center_header">
					<h2 class="form-heading"><?php echo esc_html__( 'Return Portal', 'zorem-returns' ); ?></h2>
					<p class="form-desc"><?php echo esc_html__( "Let's track your order first", 'zorem-returns' ); ?></p>
				</div>
				<div class="container">
					<?php if ( ! is_user_logged_in() ) { ?>
					<label for="customer_email"><?php echo esc_html__( 'Email Address', 'zorem-returns' ); ?></label>
					<input type="text" name="customer_email" class="customer_email" required>
					<?php } ?>
					<label for="order_id"><?php echo esc_html__( 'Order ID', 'zorem-returns' ); ?></label>
					<input type="text" name="order_id" class="order_id" required>

					<button type="submit" class="request-center-btn button" ><?php echo esc_html__( 'Find My Order', 'zorem-returns' ); ?></button>
					<input type="hidden" name="action" value="fetch_return_request_data">
					<?php $ajax_nonce = wp_create_nonce( 'return_center_form' ); ?>
					<input type="hidden" name="return_center_nonce" id="return_center_nonce" value="<?php echo esc_html( $ajax_nonce ); ?>" />
				</div>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get fetch return center form by order ID
	 *
	 * @since  1.0.0
	 */
	public function fetch_return_request_data_callback() {
		if ( ! empty( $_POST ) && check_ajax_referer( 'return_center_form', 'return_center_nonce' ) ) {

			$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
			$order_id = zorem_returns_exchanges()->rxCommon->get_formated_order_id( $order_id );

			if ( is_user_logged_in() ) {
				$current_user = wp_get_current_user();
			} else {
				$customer_email = isset( $_POST['customer_email'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_email'] ) ) : '';
			}

			$order = ! empty( $order_id ) ? wc_get_order( $order_id ) : '';

			if ( empty( $order ) ) {
				wp_send_json_error( array( 'error' => __( 'This order number does not exist.', 'zorem-returns' ) ) );
				die();
			}

			if ( ! empty( $order ) && ! empty( $customer_email ) && strtolower( $order->get_billing_email() ) !== strtolower( $customer_email ) ) {
				wp_send_json_error( array( 'error' => __( 'This email address does not match the order number.', 'zorem-returns' ) ) );
				die();
			}

			if ( ! zorem_returns_exchanges()->common->is_order_valid_for_return( $order ) ) {
				wp_send_json_error( array( 'error' => __( 'This order is not eligible for return and exchange.', 'zorem-returns' ) ) );
				die();
			}

			// Fraud prevention — blocklist / per-customer return limit.
			if ( isset( zorem_returns_exchanges()->fraud_prevention ) && is_object( zorem_returns_exchanges()->fraud_prevention ) ) {
				$fp_check = zorem_returns_exchanges()->fraud_prevention->check_order( $order );
				if ( ! empty( $fp_check['blocked'] ) ) {
					wp_send_json_error( array( 'error' => $fp_check['message'] ) );
					die();
				}
			}

			// Block by payment method (e.g. COD).
			if ( zorem_returns_exchanges()->common->is_payment_method_blocked_for_return( $order ) ) {
				$message = zorem_returns_exchanges()->common->get_return_blocked_message( $order );
				wp_send_json_error( array( 'error' => $message ) );
				die();
			}
			$return_general_settings_option = get_option( 'return_general_settings_option', array() );
			$exchange_enable                = isset( $return_general_settings_option['exchange_enable'] ) ? $return_general_settings_option['exchange_enable'] : 'yes';
			$return_form_data               = ! empty( $order ) ? zorem_returns_exchanges()->common->get_returned_form_data( $order ) : '';
			$exchange_form_data             = ! empty( $order ) && 'yes' == $exchange_enable ? zorem_returns_exchanges()->exCommon->get_exchanged_form_data( $order ) : '';
			if ( empty( $return_form_data ) && empty( $exchange_form_data ) ) {
				wp_send_json_error( array( 'error' => __( 'A return or exchange has already been requested for this order.', 'zorem-returns' ) ) );
				die();
			}

			ob_start();
			include __DIR__ . '/htmls/guest-return-center-form.php';
			$return_center_order_content = ob_get_clean();

			wp_send_json_success( array( 'return_center_order_content' => $return_center_order_content ) );
			die();
		}
	}

	/**
	 * Get fetch guest return form by order ID
	 *
	 * @since  1.0.0
	 */
	public function fetch_guest_return_form_data_callback() {

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! empty( $_POST ) && wp_verify_nonce( $nonce, 'guest_return_center_order_form' ) ) {
			$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
			$item_id  = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';
			$type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

			$order = ! empty( $order_id ) ? wc_get_order( $order_id ) : '';

			ob_start();
			if ( 'exchange' == $type ) {
				include __DIR__ . '/../zorem-exchanges/htmls/exchanges-center-form.php';
			} else {
				include __DIR__ . '/htmls/returns-center-form.php';
			}
			$guest_return_form_content = ob_get_clean();

			wp_send_json_success( array( 'guest_return_form_content' => $guest_return_form_content ) );
			die();
		}
	}

	/**
	 * Get fetch return center items data by order ID
	 *
	 * @since  1.0.0
	 */
	public function fetch_return_items_data_callback() {
		if ( ! empty( $_POST ) && check_ajax_referer( 'return_center_multiStep_form', 'return_center_multiStep_nonce' ) ) {

			$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
			$item_id  = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';
			$qty      = isset( $_POST['qty'] ) ? sanitize_text_field( wp_unslash( $_POST['qty'] ) ) : '';
			$order    = ! empty( $order_id ) ? wc_get_order( $order_id ) : '';

			if ( ! $order ) {
				wp_send_json_error();
				die();
			}

			if ( ! $item_id ) {
				wp_send_json_error();
				die();
			}

			$order_item = new WC_Order_Item_Product( $item_id );
			$total      = $order_item->get_product()->get_price() * $qty;

			wp_send_json_success( array( 'totalHtml' => wc_price( $total ) ) );
			die();
		}
	}

	/**
	 * Callback function of save return center form data
	 *
	 * @since  1.0.0
	 */
	public function save_return_center_data_callback() {

		if ( ! empty( $_POST ) && check_ajax_referer( 'return_center_request_form', 'return_center_request_nonce' ) ) {

			$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
			$order    = ! empty( $order_id ) ? wc_get_order( $order_id ) : '';

			if ( ! $order ) {
				wp_send_json_error();
				die();
			}

			// Block submission if the order's payment method is restricted from returns.
			if ( zorem_returns_exchanges()->common->is_payment_method_blocked_for_return( $order ) ) {
				$message = zorem_returns_exchanges()->common->get_return_blocked_message( $order );
				wp_send_json_error( array( 'error' => $message ) );
				die();
			}

			// Fraud prevention — blocklist / per-customer return limit.
			if ( isset( zorem_returns_exchanges()->fraud_prevention ) && is_object( zorem_returns_exchanges()->fraud_prevention ) ) {
				$fp_check = zorem_returns_exchanges()->fraud_prevention->check_order( $order );
				if ( ! empty( $fp_check['blocked'] ) ) {
					wp_send_json_error( array( 'error' => $fp_check['message'] ) );
					die();
				}
			}

			/**
			 * Filter: validate a customer-submitted return request BEFORE the
			 * request row is created.
			 *
			 * Return `true` (default) to accept the submission. To reject it,
			 * return a `WP_Error` (its message is shown to the customer), a
			 * non-empty string (shown to the customer as-is), or `false` (a
			 * generic message is shown). The message is the developer's own
			 * string, so it can be written in any language (e.g. Turkish) or
			 * passed through the developer's own translation setup.
			 *
			 * Example — require a hygiene-seal confirmation for swimwear:
			 *
			 *     add_filter( 'zorem_return_request_validation', function ( $valid, $order, $posted ) {
			 *         if ( empty( $posted['return_data']['hygiene_confirm'] ) ) {
			 *             return new WP_Error( 'hygiene_seal', 'Hijyen bandı onayı işaretlenmeden mayo/tayt iadesi kabul edilemez.' );
			 *         }
			 *         return $valid;
			 *     }, 10, 3 );
			 *
			 * @param bool|string|WP_Error $valid  True to accept (default).
			 * @param WC_Order             $order  Order the return is requested against.
			 * @param array                $posted Sanitized submitted form data (order_id, return_data, …).
			 */
			$validation = apply_filters( 'zorem_return_request_validation', true, $order, wc_clean( wp_unslash( $_POST ) ) );
			if ( true !== $validation ) {
				$message = '';
				if ( is_wp_error( $validation ) ) {
					$message = $validation->get_error_message();
				} elseif ( is_string( $validation ) && '' !== trim( $validation ) ) {
					$message = $validation;
				} elseif ( false === $validation ) {
					$message = __( 'Your return request could not be submitted. Please contact us for assistance.', 'zorem-returns' );
				}
				if ( '' !== $message ) {
					wp_send_json_error( array( 'error' => $message ) );
					die();
				}
			}

			// save form data
			$request_id = zorem_returns_exchanges()->center->save_return_requested_data( $_POST, $_FILES );

			ob_start();
			?>
			<div class="step" id="step-3" style="display: block;">
				<?php echo esc_html( zorem_returns_exchanges()->common->display_return_data_request_id( $request_id ) ); ?>
			</div>
			<?php
			$html = ob_get_clean();

			wp_send_json_success( array( 'step3Html' => $html ) );
			die();
		}
	}

	/**
	 * Admin-initiated return: create a return request on the customer's behalf
	 * from the order edit screen. Deliberately bypasses the customer-facing
	 * eligibility rules (return window, payment-method block, fraud limits) —
	 * those are shown as soft warnings in the modal instead of hard blocks.
	 *
	 * @since 1.6.8
	 */
	public function admin_create_return_callback() {

		check_ajax_referer( 'zorem_admin_create_return', 'security' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'error' => __( 'You do not have permission to create return requests.', 'zorem-returns' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			wp_send_json_error( array( 'error' => __( 'Invalid order.', 'zorem-returns' ) ) );
		}

		$return_data  = isset( $_POST['return_data'] ) ? wc_clean( wp_unslash( $_POST['return_data'] ) ) : array();
		$posted_items = isset( $return_data['return_items'] ) && is_array( $return_data['return_items'] ) ? $return_data['return_items'] : array();

		// Validate items: must belong to the order and not exceed the remaining returnable qty.
		$return_datas = zorem_returns_exchanges()->common->get_data_by_order_id( $order_id );
		$items_clean  = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( empty( $posted_items[ $item_id ]['return_product_qty'] ) ) {
				continue;
			}
			$qty       = absint( $posted_items[ $item_id ]['return_product_qty'] );
			$remaining = $item->get_quantity() - (int) zorem_returns_exchanges()->common->get_returned_line_item_qty( $item_id, $return_datas );
			if ( $qty < 1 || $qty > $remaining ) {
				/* translators: %s: product name */
				wp_send_json_error( array( 'error' => sprintf( __( 'Invalid return quantity for "%s".', 'zorem-returns' ), $item->get_name() ) ) );
			}
			$items_clean[ $item_id ] = array(
				'return_product_id'  => $item_id,
				'return_product_qty' => $qty,
			);
		}
		if ( empty( $items_clean ) ) {
			wp_send_json_error( array( 'error' => __( 'Please select a quantity for at least one item.', 'zorem-returns' ) ) );
		}

		$reason = isset( $return_data['return_reason']['no_other'] ) ? $return_data['return_reason']['no_other'] : '';
		if ( '' === $reason ) {
			wp_send_json_error( array( 'error' => __( 'Please select a return reason.', 'zorem-returns' ) ) );
		}

		$payment_method = isset( $return_data['payment_method'] ) && in_array( $return_data['payment_method'], array( 'customer_account', 'store_credit' ), true ) ? $return_data['payment_method'] : 'customer_account';
		$status         = isset( $return_data['return_status'] ) && in_array( $return_data['return_status'], array( 'return-requested', 'return-approved' ), true ) ? $return_data['return_status'] : 'return-approved';

		$request = array(
			'order_id'        => $order_id,
			'notify_customer' => isset( $_POST['notify_customer'] ) ? sanitize_text_field( wp_unslash( $_POST['notify_customer'] ) ) : 'no',
			'return_data'     => array(
				'return_items'   => $items_clean,
				'return_reason'  => array(
					'no_other'     => $reason,
					'other_reason' => isset( $return_data['return_reason']['other_reason'] ) ? $return_data['return_reason']['other_reason'] : '',
				),
				'return_issue'   => isset( $return_data['return_issue'] ) ? $return_data['return_issue'] : '',
				'payment_method' => $payment_method,
				'return_status'  => $status,
			),
		);

		$request_id = $this->save_return_requested_data( $request, array(), 'admin' );

		if ( empty( $request_id ) ) {
			wp_send_json_error( array( 'error' => __( 'Could not create the return request.', 'zorem-returns' ) ) );
		}

		wp_send_json_success( array( 'request_id' => $request_id ) );
	}

	/**
	 * Save return request form data
	 *
	 * @param array  $POST    Submitted form data (order_id, return_data, …).
	 * @param array  $FILES   Uploaded attachment files (empty array for admin flow).
	 * @param string $context 'customer' (default) or 'admin' — admin-initiated requests
	 *                        use the status chosen in the modal, only email the customer
	 *                        when asked, and skip the admin notification email.
	 * @since  1.0.0
	 */
	public function save_return_requested_data( $POST, $FILES, $context = 'customer' ) {

		if ( isset( $FILES ) && $FILES['return_attachments']['name'] && ! empty( $FILES['return_attachments']['name'][0] ) && ! is_int( $FILES['return_attachments']['name'] ) ) {

			$length        = count( $FILES['return_attachments']['name'] );
			$file          = $FILES['return_attachments'];
			$allowed_types = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
			$max_size      = 5 * 1024 * 1024;

			for ( $i = 0; $i < $length; $i++ ) {
				// Check if the file type is allowed
				$file_extension = pathinfo( $file['name'][ $i ], PATHINFO_EXTENSION );
				if ( ! empty( $file_extension ) && ! in_array( strtolower( $file_extension ), $allowed_types ) ) {
					/* translators: %s: comma-separated list of allowed file extensions */
					wp_send_json_error( array( 'error' => sprintf( __( 'Invalid file type. Allowed types: %s', 'zorem-returns' ), implode( ', ', $allowed_types ) ) ) );
					die();
				}

				// Check if the file size is within the allowed limit
				if ( $file['size'][ $i ] > $max_size ) {
					wp_send_json_error( array( 'error' => __( 'File size exceeds the allowed limit (5 MB)', 'zorem-returns' ) ) );
					die();
				}
			}
		}

		$order_id     = isset( $POST['order_id'] ) ? sanitize_text_field( $POST['order_id'] ) : '';
		$return_datas = zorem_returns_exchanges()->common->get_data_by_order_id( $order_id );

		$order        = wc_get_order( $order_id );
		$return_array = isset( $POST['return_data'] ) ? wc_clean( $POST['return_data'] ) : array();
		if ( isset( $return_array['return_the_entire_order'] ) && 1 == $return_array['return_the_entire_order'] ) {
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( 0 == $return_array['return_items'][ $item_id ]['return_product_qty'] ) {
					unset( $return_array['return_items'][ $item_id ] );
				} else {
					unset( $return_array['return_items'][ $item_id ]['return_product_id'] );
					$item_qty = $item->get_quantity() - zorem_returns_exchanges()->common->get_returned_line_item_qty( $item_id, $return_datas );
					$return_array['return_items'][ $item_id ]['return_product_qty'] = $item_qty;
				}
			}
		} else {
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( isset( $return_array['return_items'][ $item_id ] ) && null != $return_array['return_items'][ $item_id ] && 0 == $return_array['return_items'][ $item_id ]['return_product_id'] ) {
					unset( $return_array['return_items'][ $item_id ] );
				} else {
					unset( $return_array['return_items'][ $item_id ]['return_product_id'] );
				}
			}
		}

		// Items with no quantity are not being returned — drop them, and reject
		// the request outright when nothing returnable remains (e.g. a customer
		// ticked an item but left its quantity at 0).
		if ( ! empty( $return_array['return_items'] ) && is_array( $return_array['return_items'] ) ) {
			foreach ( $return_array['return_items'] as $iid => $item_data ) {
				if ( ! is_array( $item_data ) || empty( $item_data['return_product_qty'] ) || (int) $item_data['return_product_qty'] < 1 ) {
					unset( $return_array['return_items'][ $iid ] );
				}
			}
		}
		if ( empty( $return_array['return_items'] ) ) {
			wp_send_json_error( array( 'error' => __( 'Please select a quantity for at least one item.', 'zorem-returns' ) ) );
			die();
		}

		if ( 'admin' === $context ) {
			// Admin-initiated: status chosen in the modal (default approved).
			$admin_status                  = isset( $return_array['return_status'] ) ? $return_array['return_status'] : '';
			$return_array['return_status'] = in_array( $admin_status, array( 'return-requested', 'return-approved' ), true ) ? $admin_status : 'return-approved';
		} else {
			// Automatic return approval
			$automatic_return = zorem_returns_exchanges()->common->return_settings( 'automatic_return', 'no' );
			if ( 'yes' == $automatic_return ) {
				$return_array['return_status'] = 'return-approved';

			} else {
				$return_array['return_status'] = 'return-requested';
			}
		}

		// Get the user ID
		$order   = wc_get_order( $order_id );
		$user_id = $order ? $order->get_user_id() : 0;

		$return_data = array(
			'order_id'       => $order_id,
			'user_id'        => $user_id,
			'return_status'  => $return_array['return_status'],
			'return_items'   => serialize( $return_array['return_items'] ),
			'return_reason'  => serialize( $return_array['return_reason'] ),
			'return_issue'   => $return_array['return_issue'],
			'payment_method' => $return_array['payment_method'],
			'keep_the_item'  => isset( $return_array['keep_the_item'] ) ? sanitize_text_field( $return_array['keep_the_item'] ) : null,
			'created_at'     => current_time( 'mysql', true ),
		);

		// Calculate and snapshot the restocking fee at submit time.
		// Frozen here so the customer's agreed-to amount cannot drift if global settings change later;
		// admin can still override on approval if needed.
		if ( zorem_returns_exchanges()->restocking_fee && zorem_returns_exchanges()->restocking_fee->is_enabled() ) {
			$fee_summary = zorem_returns_exchanges()->restocking_fee->calculate_for_request( $order, $return_array['return_items'] );
			if ( ! empty( $fee_summary ) ) {
				$return_data['restocking_fee_amount']    = $fee_summary['total_fee'];
				$return_data['restocking_fee_type']      = zorem_returns_exchanges()->common->return_settings( 'restocking_fee_type', 'percentage' );
				$return_data['restocking_fee_breakdown'] = wp_json_encode( $fee_summary['breakdown'] );
			}
		}

		// database functions
		global $wpdb;
		$table = $wpdb->prefix . 'return_orders';

		// Defensive: if the 1.9 migration hasn't run yet (admin hasn't visited the dashboard
		// since the plugin update), the created_at column may not exist. Drop it from the
		// insert payload so the row still saves correctly.
		static $has_created_at = null;
		if ( null === $has_created_at ) {
			$cols           = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), 0 );
			$has_created_at = is_array( $cols ) && in_array( 'created_at', $cols, true );
		}
		if ( ! $has_created_at && isset( $return_data['created_at'] ) ) {
			unset( $return_data['created_at'] );
		}

		// insert data functions
		$wpdb->insert( $table, $return_data );
		$request_id = $wpdb->insert_id;

		$return_items_image = zorem_returns_exchanges()->common->upload_file_by_url( ( isset( $FILES ) ? $FILES : array() ), $request_id );
		if ( $return_items_image ) {
			$return_data['return_items_image'] = serialize( $return_items_image );
			$where                             = array(
				'id' => $request_id,
			);
			$wpdb->update( $table, $return_data, $where );
		}

		// Order status change to return( After all items requested for return )
		if ( ! empty( $request_id ) && ! empty( $order ) ) {

			$is_order_valid   = zorem_returns_exchanges()->common->is_order_valid_for_return( $order );
			$return_form_data = zorem_returns_exchanges()->common->get_returned_form_data( $order );

			$return_datas    = zorem_returns_exchanges()->common->get_data_by_order_id( $order_id );
			$is_valid_status = false;
			foreach ( (array) $return_datas as $return_data ) {
				$return_status = isset( $return_data->return_status ) ? $return_data->return_status : '';
				if ( 'return-approved' == $return_status ) {
					$is_valid_status = true;
				}
			}

			if ( $is_order_valid && empty( $return_form_data ) && $is_valid_status ) {
				// update order status
				$order->set_status( 'return' );
				$order->save();
			}

			if ( $is_order_valid && ! empty( $return_form_data ) && $is_valid_status ) {
				// update order status
				$order->set_status( 'partial-return' );
				$order->save();
			}
		}

		// email trigger for Customer/Admin
		if ( ! empty( $request_id ) ) {

			// Admin-initiated requests only email the customer when the agent asked for it
			// (they may already be on a call), and skip the admin notification email.
			$notify_customer = 'admin' !== $context || ( isset( $POST['notify_customer'] ) && 'yes' === $POST['notify_customer'] );

			if ( $notify_customer ) {
				zorem_returns_exchanges()->front->send_return_email_func( $request_id, $order_id, $return_array['return_status'] );
			}

			if ( 'admin' !== $context && ( 'return-requested' == $return_array['return_status'] || 'return-approved' == $return_array['return_status'] ) ) {
				zorem_returns_exchanges()->front->send_return_admin_email_func( $request_id, $order_id, $return_array['return_status'] );
			}

			if ( 'return-approved' == $return_array['return_status'] && 'store_credit' == $return_array['payment_method'] ) {
				// Generate Customer Coupon
				zorem_returns_exchanges()->common->create_coupon_by_return_requestId( $request_id );
			}

			if ( 'return-approved' == $return_array['return_status'] && 'tera_wallet' == $return_array['payment_method'] ) {
				// Refund to TeraWallet
				zorem_returns_exchanges()->common->refund_to_tera_wallet( $request_id );
			}

			// Automatically restocks items when a return request is approved (skip for keep_the_item
			// and for the "Keep the Item & Partial Refund" resolution — customer retains the product).
			$is_keep_item_auto     = isset( $return_array['keep_the_item'] ) && 'yes' === $return_array['keep_the_item'];
			$is_keep_item_partial  = ( 'keep_item_partial' === $return_array['payment_method'] );
			if ( 'return-approved' == $return_array['return_status'] && ! $is_keep_item_auto && ! $is_keep_item_partial ) {
				zorem_returns_exchanges()->common->restock_return_items_by_request_id( $request_id );
			}

			// For keep_the_item + customer_account: auto-refund on approval
			if ( 'return-approved' == $return_array['return_status'] && $is_keep_item_auto && 'customer_account' === $return_array['payment_method'] ) {
				zorem_returns_exchanges()->common->process_keep_item_refund( $request_id );
			}

			// Note: the "Keep the Item & Partial Refund" coupon is generated on
			// COMPLETION (see fulfillment dashboard), not here on approval — the
			// customer receives the coupon only after the admin fully signs off.

			// Fraud Prevention — notify admin if this submission brings the customer to the
			// limit. Skipped for admin-initiated requests (the admin is acting deliberately).
			if ( 'admin' !== $context && isset( zorem_returns_exchanges()->fraud_prevention ) && is_object( zorem_returns_exchanges()->fraud_prevention ) ) {
				zorem_returns_exchanges()->fraud_prevention->maybe_notify_admin_on_limit_hit( $request_id, $order_id );
			}
		}

		return $request_id;
	}

	/**
	 * Callback function of return request cancel
	 *
	 * @since  1.0.0
	 */
	public function return_request_cancel_callback() {

		if ( ! empty( $_POST ) ) {

			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! empty( $nonce ) && ! wp_verify_nonce( $nonce, 'return_request_cancel' ) ) {
				return;
			}

			$request_id = isset( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '';
			if ( ! empty( $request_id ) ) {

				// database functions
				global $wpdb;
				$table = $wpdb->prefix . 'return_orders';

				$return_data = (array) zorem_returns_exchanges()->common->get_data_by_return_id( $request_id );

				$return_data['return_status'] = 'return-cancelled';
				$where                        = array(
					'id' => $request_id,
				);

				$wpdb->update( $table, $return_data, $where );

				zorem_returns_exchanges()->front->send_return_admin_email_func( $request_id, $return_data['order_id'], 'return-cancelled' );

				wp_send_json_success();
			}
		}
	}
}
