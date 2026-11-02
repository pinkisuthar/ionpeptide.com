<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.DynamicHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Front {

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

		// database functions
		global $wpdb;
		$table = $wpdb->prefix . 'return_orders';
		if ( $wpdb->get_var( $wpdb->prepare( 'show tables like %s', $table ) ) != $table ) {
			$create_table_query = "
				CREATE TABLE IF NOT EXISTS `{$table}` (
					`id` int NOT NULL AUTO_INCREMENT,
					`order_id` text NULL,
					`return_status` text NULL,
					`return_items` text NULL,
					`return_reason` text NULL,
					`return_issue` text NULL,
					`return_items_image` text NULL,
					`payment_method` text NULL,
					`created_at` datetime DEFAULT NULL,
					PRIMARY KEY (id)
				) ENGINE=InnoDB  DEFAULT CHARSET=utf8;
			";
			// Run directly instead of dbDelta(): dbDelta cannot parse IF NOT EXISTS
			// (it reads the table name as "IF" and runs a broken "DESCRIBE IF;" query).
			$wpdb->query( $create_table_query );
		}

		$return_general_settings_option = get_option( 'return_general_settings_option', array() );
		$return_entire_order            = isset( $return_general_settings_option['return_entire_order'] ) ? $return_general_settings_option['return_entire_order'] : 'no';
		if ( 'yes' == $return_entire_order ) {
			add_action( 'woocommerce_order_item_meta_end', array( $this, 'add_request_button_after_product_title' ), 10, 3 );
		}

		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'add_request_button_after_order_table' ), 10, 1 );

		// Update available store credit amount after use coupon
		add_action( 'woocommerce_payment_complete', array( $this, 'update_coupon_remaining_amount' ), 10, 1 );

		// Add return endpoint content
		add_action( 'woocommerce_account_return_endpoint', array( $this, 'return_endpoint_content' ) );

		// Secure download endpoint for return shipping label PDF
		add_action( 'wp_ajax_rt4wc_download_return_label', array( $this, 'download_return_label' ) );
	}

	/** Add return endpoint content
	 *
	 * @since  1.0.0
	 */
	public function return_endpoint_content() {

		$order_id            = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;
		$order               = ! empty( $order_id ) ? wc_get_order( $order_id ) : '';
		$item_id             = isset( $_GET['item_id'] ) ? sanitize_text_field( wp_unslash( $_GET['item_id'] ) ) : '0';
		$return_form_data    = zorem_returns_exchanges()->common->get_returned_form_data( $order );
		$return_entire_order = zorem_returns_exchanges()->common->return_settings( 'return_entire_order', 'no' );
		$return_datas        = zorem_returns_exchanges()->common->get_data_by_order_id( $order_id );

		if ( array_key_exists( $item_id, (array) $return_form_data ) || ! empty( $return_form_data ) ) {

			include __DIR__ . '/htmls/returns-center-form.php';

		} elseif ( ( ! empty( $item_id ) || ! empty( $order_id ) ) && ! empty( $return_datas ) ) {

			foreach ( $return_datas as $request_data ) {
				$returned_data = isset( $request_data->return_items ) ? unserialize( $request_data->return_items ) : array();
				if ( empty( $item_id ) && count( $returned_data ) > 1 ) {
					?>
					<form id="return-multi-step-form" >
						<div class="multi-form-container">
							<?php if ( 'no' == $return_entire_order ) { ?>
								<h2 class="form-heading" style="display: flex;flex-direction: column;"><span style="font-size: 14px;margin-bottom: 5px;"><strong style="color: #008BC5;"><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'view-order' ) . $order_id ); ?>" style="color: #008BC5;"><?php esc_html_e( 'View Order', 'zorem-returns' ); ?></a> > </strong> <?php esc_html_e( 'Start a Return', 'zorem-returns' ); ?></span><?php esc_html_e( 'Start a Return', 'zorem-returns' ); ?></h2>
							<?php } else { ?>
								<h2 class="form-heading" style="display: flex;flex-direction: column;"><span style="font-size: 14px;margin-bottom: 5px;"><strong style="color: #008BC5;"><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'view-order' ) . $order_id ); ?>" style="color: #008BC5;"><?php esc_html_e( 'View Order', 'zorem-returns' ); ?></a> > </strong> <?php esc_html_e( 'Product Selected for Return', 'zorem-returns' ); ?></span><?php esc_html_e( 'Product Selected for Return', 'zorem-returns' ); ?></h2>
							<?php } ?>
							<div class="form-content">
								<?php
								$html = $this->display_return_data( $order );
								if ( ! empty( $html ) ) {
									echo wp_kses_post( $html );
								}
								?>
							</div>
						</div>
					</form>
					<?php
				} else {
					$returned_item_id = isset( $returned_data ) ? key( $returned_data ) : '0';
					if ( ! empty( $item_id ) && $item_id == $returned_item_id ) {
						$request_id = isset( $request_data->id ) ? $request_data->id : '0';
						?>
						<form id="return-multi-step-form" >
							<div class="multi-form-container">
								<?php if ( 'no' == $return_entire_order ) { ?>
									<h2 class="form-heading" style="display: flex;flex-direction: column;"><span style="font-size: 14px;margin-bottom: 5px;"><strong style="color: #008BC5;"><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'view-order' ) . $order_id ); ?>" style="color: #008BC5;"><?php esc_html_e( 'View Order', 'zorem-returns' ); ?></a> > </strong> <?php esc_html_e( 'Start a Return', 'zorem-returns' ); ?></span><?php esc_html_e( 'Start a Return', 'zorem-returns' ); ?></h2>
								<?php } else { ?>
									<h2 class="form-heading" style="display: flex;flex-direction: column;"><span style="font-size: 14px;margin-bottom: 5px;"><strong style="color: #008BC5;"><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'view-order' ) . $order_id ); ?>" style="color: #008BC5;"><?php esc_html_e( 'View Order', 'zorem-returns' ); ?></a> > </strong> <?php esc_html_e( 'Product Selected for Return', 'zorem-returns' ); ?></span><?php esc_html_e( 'Product Selected for Return', 'zorem-returns' ); ?></h2>
								<?php } ?>
								<div class="form-content">
									<?php echo wp_kses_post( zorem_returns_exchanges()->common->display_return_data_request_id( $request_id ) ); ?>
								</div>
							</div>
						</form>
						<?php
					}
				}
			}
		} else {

			esc_html_e( 'This item is not availabale for return', 'zorem-returns' );

		}
	}

	/**
	 * Front-end return request button
	 *
	 * @since  1.0.0
	 */
	public function add_request_button_after_order_table( $order ) {

		// Don't offer returns on exchange-generated child orders.
		$parent_order_id = $order ? $order->get_meta( '_parent_order_id' ) : '';
		if ( ! zorem_returns_exchanges()->common->is_order_valid_for_return( $order ) || ! empty( $parent_order_id ) ) {
			return;
		}

		// Fraud prevention — blocklist or per-customer return limit.
		if ( isset( zorem_returns_exchanges()->fraud_prevention ) && is_object( zorem_returns_exchanges()->fraud_prevention ) ) {
			$fp_check = zorem_returns_exchanges()->fraud_prevention->check_order( $order );
			if ( ! empty( $fp_check['blocked'] ) ) {
				if ( zorem_returns_exchanges()->fraud_prevention->should_silent_block() ) {
					return;
				}
				echo '<div class="zorem-rne-notice-row"><div class="zorem-return-blocked-notice zorem-fraud-blocked-notice">' . esc_html( $fp_check['message'] ) . '</div></div>';
				return;
			}
		}

		// Block returns by payment method (e.g., COD).
		if ( zorem_returns_exchanges()->common->is_payment_method_blocked_for_return( $order ) ) {

			// If exchanges are ALSO blocked for this payment method, show a single combined notice
			// (the exchange handler will skip its notice when it sees this combined state).
			if ( zorem_returns_exchanges()->exCommon->is_payment_method_blocked_for_exchange( $order ) ) {
				$message = zorem_returns_exchanges()->common->get_combined_blocked_message( $order );
				echo '<div class="zorem-rne-notice-row"><div class="zorem-return-blocked-notice zorem-combined-blocked-notice">' . esc_html( $message ) . '</div></div>';
			} else {
				$message = zorem_returns_exchanges()->common->get_return_blocked_message( $order );
				echo '<div class="zorem-rne-notice-row"><div class="zorem-return-blocked-notice">' . esc_html( $message ) . '</div></div>';
			}
			return;
		}

		// Admin setting: hide the whole-order "Start a Return" button entirely
		// (per-item Return buttons are unaffected). Enabled by default.
		if ( 'no' === zorem_returns_exchanges()->common->return_settings( 'show_start_return_button', 'yes' ) ) {
			return;
		}

		$return_form_data = zorem_returns_exchanges()->common->get_returned_form_data( $order );
		if ( empty( $return_form_data ) ) {
			return;
		}

		// Show the button only if at least one line item is actually eligible for return
		// (respects excluded products, excluded categories, and on-sale item settings).
		$returnable_item_found = false;
		foreach ( $order->get_items() as $item_id => $item ) {

			if ( 'line_item' !== $item->get_type() || ! array_key_exists( $item_id, $return_form_data ) ) {
				continue;
			}

			if ( zorem_returns_exchanges()->common->is_item_valid_for_return( $item ) ) {
				$returnable_item_found = true;
				break;
			}
		}

		if ( ! $returnable_item_found ) {
			return;
		}

		// Get order ID
		$order_id = $order->get_id();

		$return_button_html = '<div class="zorem-rne-action-row"><a href="' . esc_url( wc_get_account_endpoint_url( 'return' ) . '?order_id=' . $order_id ) . '"><button class="return_request_button button" type="button"> ' . esc_html__( 'Start a Return', 'zorem-returns' ) . '</button></a></div>';

		// Output the custom buttons
		echo wp_kses_post( $return_button_html );
	}

	/** Add request button after product title in order details
	 *
	 * @since  1.0.0
	 */
	public function add_request_button_after_product_title( $item_id, $item, $order ) {

		if ( ! is_wc_endpoint_url( 'view-order' ) ) {
			return;
		}

		$parent_order_id = $order ? $order->get_meta( '_parent_order_id' ) : '';
		if ( ! zorem_returns_exchanges()->common->is_order_valid_for_return( $order ) || ! empty( $parent_order_id ) ) {
			return;
		}

		// Silently hide per-line Return button when payment method is blocked.
		if ( zorem_returns_exchanges()->common->is_payment_method_blocked_for_return( $order ) ) {
			return;
		}

		// Silently hide per-line Return button when fraud prevention blocks the customer.
		// The combined notice has already been shown by add_request_button_after_order_table.
		if ( isset( zorem_returns_exchanges()->fraud_prevention ) && is_object( zorem_returns_exchanges()->fraud_prevention ) ) {
			$fp_check = zorem_returns_exchanges()->fraud_prevention->check_order( $order );
			if ( ! empty( $fp_check['blocked'] ) ) {
				return;
			}
		}

		if ( ! zorem_returns_exchanges()->common->is_item_valid_for_return( $item ) ) {
			return;
		}

		$return_form_data = zorem_returns_exchanges()->common->get_returned_form_data( $order );
		if ( empty( $return_form_data ) ) {
			return;
		}

		$return_button_html = '<div class="request-buttons">';
		// Check if the item is a product
		if ( $item->get_type() === 'line_item' && array_key_exists( $item_id, $return_form_data ) ) {

			// Get order ID
			$order_id = $order->get_id();

			// Custom button HTML for Return
			$return_button_html .= '<span class="zorem-rne-line-button"><a href="' . esc_url( wc_get_account_endpoint_url( 'return' ) . '?order_id=' . $order_id . '&item_id=' . $item_id ) . '"><button class="return_request_button button" type="button"> ' . esc_html__( 'Return', 'zorem-returns' ) . '</button></a></span>';

		}

		// Output the custom buttons
		echo wp_kses_post( $return_button_html );
	}

	/** Set available store credit amount after use coupon
	 *
	 * @since  1.0.0
	 */
	public function update_coupon_remaining_amount( $order_id ) {

		if ( ! isset( $order_id ) || empty( $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		// Get the applied coupons. get_used_coupons() was deprecated in WooCommerce 3.7
		// in favour of WC_Abstract_Order::get_coupon_codes(), which returns the same
		// array of coupon-code strings.
		$applied_coupons = $order->get_coupon_codes();

		// Loop through applied coupons
		foreach ( (array) $applied_coupons as $coupon_code ) {

			// Get the coupon object
			$coupon = new WC_Coupon( $coupon_code );

			// ❗ Only update if this is a store credit coupon
			$is_store_credit = get_post_meta( $coupon->get_id(), '_zorem_is_store_credit', true );

			if ( 'yes' !== $is_store_credit ) {
				continue; // Skip regular coupons
			}

			// Get the original coupon amount
			$original_coupon_amount = $coupon->get_amount();

			// Calculate the new coupon amount based on the order discount
			$new_coupon_amount = $original_coupon_amount - $order->get_discount_total();

			// Update the coupon amount
			$coupon->set_amount( round( $new_coupon_amount, 2 ) );

			// Save the updated coupon
			$coupon->save();
		}
	}

	/**
	 * Send generated coupon details to the customer by email
	 *
	 * @since  1.0.0
	 */
	public function send_generated_coupon_to_customer( $coupon_id ) {
		ob_start();
		if ( $coupon_id ) {
			$local_template = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/emails/customer-coupon-email';
			if ( file_exists( $local_template ) && wp_is_writable( $local_template ) ) {
				$directory_path = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/';
			} else {
				$directory_path = zorem_returns_exchanges()->get_plugin_path() . '/templates/';
			}
			wc_get_template(
				'emails/customer-coupon-email.php',
				array(
					'coupon_id' => $coupon_id,
				),
				'zorem-returns-exchanges/',
				$directory_path
			);
		}
		$message = ob_get_clean();
		return $message;
	}

	/**
	 * Display return requested data by status
	 *
	 * @since  1.0.0
	 */
	public function display_return_data( $order ) {

		if ( ! isset( $order ) || empty( $order ) ) {
			return;
		}

		$order_id     = $order->get_id();
		$return_datas = zorem_returns_exchanges()->common->get_data_by_order_id( $order_id );

		$returns_status_instructions = array(
			'return-requested' => esc_html( apply_filters( 'zorem_returns_success_message_text', __( 'Your request has been received. Our customer service team will review it and get back to you within a few business days.', 'zorem-returns' ) ) ),
			'return-approved'  => esc_html__( 'Your return request has been approved. Please proceed with shipping the return item(s) back to us.', 'zorem-returns' ),
			'return-completed' => esc_html__( 'Your return has been completed. The returned items have been received and restocked. Your refund has been processed.', 'zorem-returns' ),
			'return-rejected'  => esc_html__( 'The return request has been rejected. Unfortunately, this order is not eligible for return based on our return/exchange policy.', 'zorem-returns' ),
			'return-cancelled' => esc_html__( 'Your return request has been cancelled.', 'zorem-returns' ),
		);

		if ( $return_datas ) {
			foreach ( (array) $return_datas as $return_data ) {
				?>
				<div class="return_request_content">
					<div class="return_request_header">
						<?php if ( 'return-rejected' == $return_data->return_status || 'return-cancelled' == $return_data->return_status ) { ?>
							<span class="dashicons dashicons-dismiss"></span>
						<?php } else { ?>
							<span class="dashicons dashicons-yes-alt"></span>
						<?php } ?>
						<h4>
							<?php esc_html_e( ucfirst( str_replace( '-', ' ', $return_data->return_status ) ), 'zorem-returns' ); ?>
						</h4>
					</div>
					<?php
					if ( 'return-approved' === $return_data->return_status && isset( $return_data->keep_the_item ) && 'yes' === $return_data->keep_the_item ) {
						$returns_status_instruction = esc_html__( 'Your return request has been approved. You may keep the item. Your refund will be processed automatically.', 'zorem-returns' );
					} else {
						$returns_status_instruction = apply_filters( 'customer_return_status_instructions', $returns_status_instructions[ $return_data->return_status ], $return_data->return_status );
					}
					?>
					<p class="return_request_note">
						<?php esc_html_e( $returns_status_instruction, 'zorem-returns' ); ?>
					</p>
					<div class="return_request_details">
						<p class="return_reason_header">
							<?php esc_html_e( 'Returns reason:', 'zorem-returns' ); ?>
						</p>
						<p class="return_reason_note">
							<?php
							$return_reason = ( null != $return_data->return_reason ) ? unserialize( $return_data->return_reason ) : array();
							if ( 'other_reason' == $return_reason['no_other'] ) {
								esc_html_e( stripslashes( $return_reason['other_reason'] ), 'zorem-returns' );
							} else {
								esc_html_e( stripslashes( $return_reason['no_other'] ), 'zorem-returns' );
							}
							?>
						</p>
						<?php if ( ! empty( $return_data->return_issue ) ) { ?>
							<p class="return_reason_header">
								<?php esc_html_e( 'Returns message:', 'zorem-returns' ); ?>
							</p>
							<p class="return_reason_note">
								<?php esc_html_e( stripslashes( $return_data->return_issue ), 'zorem-returns' ); ?>
							</p>
						<?php } ?>
						<?php if ( ! empty( $return_data->return_note ) ) { ?>
							<p class="return_reason_header">
								<?php
								if ( 'return-rejected' == $return_data->return_status ) {
									esc_html_e( 'Return Rejected Note:', 'zorem-returns' );
								} else {
									esc_html_e( 'Return Approved Note:', 'zorem-returns' );
								}
								?>
							</p>
							<p class="return_reason_note">
								<?php esc_html_e( stripslashes( $return_data->return_note ), 'zorem-returns' ); ?>
							</p>
						<?php } ?>
						<p class="return_items_header">
							<?php esc_html_e( 'Items Approved for Return', 'zorem-returns' ); ?>
						</p>
						<div class="return_items_note">
							<?php
							foreach ( $order->get_items() as $item_id => $item ) {
								$return_items = ( null != $return_data->return_items ) ? unserialize( $return_data->return_items ) : array();
								$product_name = $item->get_name();
								$return_qty   = isset( $return_items[ $item_id ]['return_product_qty'] ) ? $return_items[ $item_id ]['return_product_qty'] : 0;
								if ( 0 < $return_qty ) {
									// echo '<li>';
									if ( is_a( $item, 'WC_Order_Item_Product' ) ) {
										$product_id = $item->get_product_id();
										$product    = wc_get_product( $product_id );
										if ( ! is_object( $product ) ) {
											continue;
										}
										$product_image_url = wp_get_attachment_url( $product->get_image_id() );
										$product_title     = $product->get_name();
									}
									?>
									<div class="product-details">
										<img src="<?php echo esc_url( $product_image_url ); ?>" alt="<?php echo esc_attr( $product_title ); ?>"/>
										<div class="product-detail">
											<p class="product-title"><?php echo esc_html( $product_title ); ?></p>
											<div class="product-qty">
												<strong>Qty. <?php echo esc_html( $return_qty ); ?></strong>
											</div>
										</div>
									</div>
									<?php
									// esc_html_e($product_name . ' x ' . $return_qty, 'zorem-returns');
									// echo '</li>';
								}
							}
							?>
						</div>
						<?php if ( 'return-requested' == $return_data->return_status ) { ?>
							<button type="button" class="request-cancel button" style="margin: 1em;" data-request_id="<?php echo esc_attr( $return_data->id ); ?>"><?php esc_html_e( 'Cancel Request', 'zorem-returns' ); ?></button>
							<?php $ajax_nonce = wp_create_nonce( 'return_request_cancel' ); ?>
							<input type="hidden" name="return_request_cancel_nonce" id="return_request_cancel_nonce" value="<?php echo esc_html( $ajax_nonce ); ?>" />
						<?php } ?>
					</div>
					
				</div>
				<?php
			}
		}
	}

	/**
	 * Send return mail.
	 * This method will create a object and call trigger method.
	 *
	 * @since  1.0.0
	 */
	public function send_return_email_func( $request_id, $order_id, $status ) {

		if ( empty( $request_id ) ) {
			return;
		}

		/**
		 * Fires when a return status changes, before the email enabled check.
		 * Allows third-party plugins (e.g. SMS for WooCommerce) to send
		 * notifications independently of the email toggle.
		 *
		 * @param int    $request_id Return request ID.
		 * @param int    $order_id   WooCommerce order ID.
		 * @param string $status     New return status slug, e.g. 'return-approved'.
		 */
		do_action( 'zorem_return_status_changed', $request_id, $order_id, $status );

		$order  = wc_get_order( $order_id );
		$mailer = WC()->mailer();
		$email  = new WC_Email();

		$Status = strtolower( str_replace( '-', '_', $status ) );

		$enabled = get_option( 'customer_' . $Status . '_status', 'yes' );
		if ( 'no' == $enabled ) {
			return;
		}

		// WPML: render the email in the language the order was placed in —
		// translated option strings AND gettext defaults. Restored after send.
		zorem_returns_exchanges()->wpml->switch_to_order_language( $order );

		$defualt_settings = zorem_returns_exchanges()->common->generate_defaults();
		$email_settings   = get_option( 'rt4wc_customer_' . $Status . '_settings', $defualt_settings[ 'customer_' . $Status ] );

		if ( 'return-requested' == $status ) {
			$subject       = isset( $email_settings['subject'] ) ? $email_settings['subject'] : '';
			$email_heading = isset( $email_settings['heading'] ) ? $email_settings['heading'] : '';
		} elseif ( 'return-approved' == $status ) {
			$subject       = isset( $email_settings['subject'] ) ? $email_settings['subject'] : '';
			$email_heading = isset( $email_settings['heading'] ) ? $email_settings['heading'] : '';
		} elseif ( 'return-completed' == $status ) {
			$subject       = isset( $email_settings['subject'] ) ? $email_settings['subject'] : '';
			$email_heading = isset( $email_settings['heading'] ) ? $email_settings['heading'] : '';
		} elseif ( 'return-rejected' == $status ) {
			$subject       = isset( $email_settings['subject'] ) ? $email_settings['subject'] : '';
			$email_heading = isset( $email_settings['heading'] ) ? $email_settings['heading'] : '';
		}

		$customer      = new WC_Customer( $order->get_customer_id() );
		$email_heading = str_replace( array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username() ), $email_heading );
		$subject       = str_replace( array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username() ), $subject );

		$message = $this->return_customer_email_content( $request_id, $order, $email, $email_settings, $status );

		// create a new email
		$message = apply_filters( 'woocommerce_mail_content', $email->style_inline( $mailer->wrap_message( $email_heading, $message ) ) );
		add_filter( 'wp_mail_from', array( $this, 'get_from_address' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'get_from_name' ) );

		$recipient = $order->get_billing_email();
		if ( $recipient ) {
			$attachments = array();

			// Attach return shipping label PDF for approved returns
			if ( 'return-approved' == $status ) {
				$return_data = zorem_returns_exchanges()->common->get_data_by_return_id( $request_id );
				if ( ! empty( $return_data->return_label_pdf ) && file_exists( $return_data->return_label_pdf ) ) {
					$attachments[] = $return_data->return_label_pdf;
				}
			}

			wp_mail( $recipient, $subject, $message, $email->get_headers(), $attachments );
		}

		zorem_returns_exchanges()->wpml->restore_language();
	}

	/**
	 * Send return mail for admin.
	 * This method will create a object and call trigger method.
	 *
	 * @since  1.0.0
	 */
	public function send_return_admin_email_func( $request_id, $order_id, $status ) {

		if ( empty( $request_id ) ) {
			return;
		}

		$order  = wc_get_order( $order_id );
		$mailer = WC()->mailer();
		$email  = new WC_Email();

		// All admin-side return emails. `cancelled` keeps its existing template/recipient-filter
		// behaviour for backwards compatibility, but is not exposed in the Notifications UI.
		$valid = array( 'return-requested', 'return-cancelled', 'return-approved', 'return-completed', 'return-rejected' );
		if ( ! in_array( $status, $valid, true ) ) {
			return;
		}

		$Status = strtolower( str_replace( '-', '_', $status ) );

		// Per-email enable/disable toggle. Defaults to "yes" so existing installs keep firing.
		// `cancelled` is intentionally NOT toggle-gated — it has no UI surface.
		if ( 'return-cancelled' !== $status ) {
			$enabled = get_option( 'admin_' . $Status . '_status', 'yes' );
			if ( 'no' === $enabled ) {
				return;
			}
		}

		// WPML: admin emails always render in the site's default language, so they
		// don't inherit the customer's front-end language. Restored after send.
		zorem_returns_exchanges()->wpml->switch_to_site_language();

		$defualt_settings = zorem_returns_exchanges()->common->generate_defaults();
		$default_for_key  = isset( $defualt_settings[ 'admin_' . $Status ] ) ? $defualt_settings[ 'admin_' . $Status ] : array();
		$email_settings   = get_option( 'rt4wc_admin_' . $Status . '_settings', $default_for_key );

		$subject       = isset( $email_settings['subject'] ) ? $email_settings['subject'] : '';
		$email_heading = isset( $email_settings['heading'] ) ? $email_settings['heading'] : '';
		$message       = $this->return_admin_email_content( $request_id, $order, $email, $email_settings, $status );

		$customer      = new WC_Customer( $order->get_customer_id() );
		$email_heading = str_replace( array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username() ), $email_heading );
		$subject       = str_replace( array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username() ), $subject );

		// create a new email
		$message = apply_filters( 'woocommerce_mail_content', $email->style_inline( $mailer->wrap_message( $email_heading, $message ) ) );
		add_filter( 'wp_mail_from', array( $this, 'get_from_address' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'get_from_name' ) );

		// Vendor/brand snippets historically hook `rwc_return_request_recipient_email`. Keep
		// that filter ONLY for the initial request + cancellation. Status-change emails
		// (approved/completed/rejected) use a separate filter so vendors don't get them.
		// `$status` is passed as the third arg so snippets can gate vendor emails to
		// `return-requested` only (skipping `return-cancelled`).
		if ( 'return-requested' === $status || 'return-cancelled' === $status ) {
			$recipient = apply_filters( 'rwc_return_request_recipient_email', get_option( 'admin_email' ), $order_id, $status );
		} else {
			$recipient = apply_filters( 'rwc_return_status_change_recipient_email', get_option( 'admin_email' ), $order_id, $status );
		}

		if ( $recipient ) {
			wp_mail( $recipient, $subject, $message, $email->get_headers() );
		}

		zorem_returns_exchanges()->wpml->restore_language();
	}

	/**
	 * Get return requested email content data
	 *
	 * @since  1.0.0
	 */
	public function return_customer_email_content( $request_id, $order, $email, $email_settings, $status ) {

		ob_start();

		$local_template = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/emails/customer-' . $status . '.php';
		if ( file_exists( $local_template ) && wp_is_writable( $local_template ) ) {
			$directory_path = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/';
		} else {
			$directory_path = zorem_returns_exchanges()->get_plugin_path() . '/templates/';
		}

		wc_get_template(
			'emails/customer-' . $status . '.php',
			array(
				'request_id'     => $request_id,
				'order'          => $order,
				'email'          => $email,
				'email_settings' => $email_settings,
			),
			'zorem-returns-exchanges/',
			$directory_path
		);

		$message = ob_get_clean();
		return $message;
	}

	/**
	 * Get return requested email content data(for admin)
	 *
	 * @since  1.0.0
	 */
	public function return_admin_email_content( $request_id, $order, $email, $email_settings, $status ) {

		ob_start();

		$local_template = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/emails/admin-' . $status . '.php';
		if ( file_exists( $local_template ) && wp_is_writable( $local_template ) ) {
			$directory_path = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/';
		} else {
			$directory_path = zorem_returns_exchanges()->get_plugin_path() . '/templates/';
		}

		wc_get_template(
			'emails/admin-' . $status . '.php',
			array(
				'request_id'     => $request_id,
				'order'          => $order,
				'email'          => $email,
				'email_settings' => $email_settings,
			),
			'zorem-returns-exchanges/',
			$directory_path
		);

		$message = ob_get_clean();
		return $message;
	}

	/**
	 * Get the from name for outgoing emails.
	 *
	 * @return string
	 */
	public function get_from_name() {
		$from_name = apply_filters( 'woocommerce_email_from_name', get_option( 'woocommerce_email_from_name' ), $this );
		return wp_specialchars_decode( esc_html( $from_name ), ENT_QUOTES );
	}

	/**
	 * Get the from address for outgoing emails.
	 *
	 * @return string
	 */
	public function get_from_address() {
		$from_address = apply_filters( 'woocommerce_email_from_address', get_option( 'woocommerce_email_from_address' ), $this );
		return sanitize_email( $from_address );
	}

	/**
	 * Secure download handler for return shipping label PDF.
	 */
	public function download_return_label() {
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'rt4wc_download_label' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'zorem-returns' ), 403 );
		}

		$request_id = isset( $_GET['request_id'] ) ? absint( $_GET['request_id'] ) : 0;
		if ( ! $request_id ) {
			wp_die( esc_html__( 'Invalid request.', 'zorem-returns' ), 400 );
		}

		$return_data = zorem_returns_exchanges()->common->get_data_by_return_id( $request_id );
		if ( empty( $return_data ) || empty( $return_data->return_label_pdf ) ) {
			wp_die( esc_html__( 'No return label found.', 'zorem-returns' ), 404 );
		}

		// Permission check
		$current_user_id = get_current_user_id();
		$order           = wc_get_order( $return_data->order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'zorem-returns' ), 404 );
		}

		if ( (int) $current_user_id !== (int) $order->get_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this file.', 'zorem-returns' ), 403 );
		}

		// Build a SAFE filename from the strict-typed request_id only.
		// We do NOT use the DB-stored filename directly in the path.
		$upload_dir = trailingslashit( wp_normalize_path( WP_CONTENT_DIR . '/uploads/rt4wc-labels/' ) );

		// Strip the original filename down to its basename, then whitelist allowed characters.
		$raw_name = basename( (string) $return_data->return_label_pdf );

		// Whitelist: only letters, numbers, dashes, underscores, dots. Anything else = reject.
		if ( ! preg_match( '/^[a-zA-Z0-9_\-]+\.pdf$/', $raw_name ) ) {
			wp_die( esc_html__( 'Invalid file name.', 'zorem-returns' ), 400 );
		}

		$file_path = $upload_dir . $raw_name;

		// Final containment check
		$real_upload = wp_normalize_path( $upload_dir );
		$real_file   = wp_normalize_path( $file_path );
		if ( 0 !== strpos( $real_file, $real_upload ) ) {
			wp_die( esc_html__( 'Invalid file path.', 'zorem-returns' ), 403 );
		}

		if ( ! file_exists( $file_path ) || ! is_file( $file_path ) ) {
			wp_die( esc_html__( 'File not found.', 'zorem-returns' ), 404 );
		}

		// Clean buffers
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// Headers
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="return-shipping-label-' . absint( $request_id ) . '.pdf"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// nosemgrep: audit.php.lang.security.file.read-write-delete
		readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
