<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
/**
 * Cross-Product Exchange — Price Difference Handler
 *
 * Called at approval time from create_exchange_order().
 * Handles all three price scenarios:
 *   pay_more    → sets child order to difference amount, pending payment
 *   store_credit → generates WC coupon, emails it to customer
 *   even        → no action needed (child order already $0)
 *
 * @package Zorem_Returns_Exchanges
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Exchange_Price_Handler {

	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks() {
		// Correct WC's fixed_cart math for our exchange credit coupons.
		// We store the tax-inclusive difference (e.g. ₹960).
		// WooCommerce fixed_cart applies the coupon to the pre-tax subtotal and
		// then re-adds tax, which would give ₹960 * (1+tax) — too large.
		// This filter divides the per-item discount by (1 + tax_rate) so that
		// the SUM of per-item discounts INCLUDING their removed tax = stored amount exactly.
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'fix_zre_coupon_discount' ), 10, 5 );
	}

	/**
	 * Ensure ZRE exchange-credit coupons discount exactly the stored amount (incl. tax).
	 */
	public function fix_zre_coupon_discount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( get_post_meta( $coupon->get_id(), '_zre_is_exchange_credit', true ) !== 'yes' ) {
			return $discount;
		}
		// $discount is the per-item pre-tax discount WC calculated from the stored amount.
		// WC will later re-add tax on top, multiplying the effective discount by (1 + tax_rate).
		// To cancel this inflation, divide here by (1 + tax_rate) so the final displayed
		// discount equals exactly the stored coupon amount.
		if ( $cart_item && isset( $cart_item['data'] ) ) {
			$tax_rates = WC_Tax::get_rates( $cart_item['data']->get_tax_class() );
			if ( ! empty( $tax_rates ) ) {
				$tax_rate = array_sum( array_column( $tax_rates, 'rate' ) ) / 100;
				if ( $tax_rate > 0 ) {
					return $discount / ( 1 + $tax_rate );
				}
			}
		}
		return $discount;
	}

	// =========================================================================
	// MAIN DISPATCH
	// =========================================================================

	/**
	 * Dispatch APPROVAL-time handling.
	 * Only the "pay more" branch runs here — store-credit is deferred to completion
	 * so that no refund/credit is issued until the exchange is actually fulfilled.
	 *
	 * @param WC_Order $child_order  Newly created child exchange order.
	 * @param object   $exchange_row Row from wp_exchange_orders.
	 */
	public function handle( $child_order, $exchange_row ) {
		// Always recalculate server-side using current product prices for accuracy.
		// Never rely solely on the frontend-submitted price_difference value.
		$difference = $this->recalculate_price_difference( $exchange_row );

		// Persist the recalculated value back to DB so completion handler can use it.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'exchange_orders',
			array( 'price_difference' => $difference ),
			array( 'id' => intval( $exchange_row->id ) )
		);

		$action = zorem_returns_exchanges()->common->return_settings( 'cross_exchange_price_action', 'pending_payment' );

		if ( $difference > 0.5 ) {
			$this->handle_pay_more( $child_order, $difference, $action );
		}
		// pay-less branch is handled in handle_completion() at exchange-completed
		// even (within ±0.50) → child order stays $0, no action needed
	}

	/**
	 * Dispatch COMPLETION-time handling.
	 * Generates and emails the store-credit coupon for cross-product exchanges
	 * where the replacement item costs less than the original.
	 *
	 * @param object $exchange_row Row from wp_exchange_orders.
	 */
	public function handle_completion( $exchange_row ) {
		if ( ! isset( $exchange_row->exchange_type ) || 'cross-product' !== $exchange_row->exchange_type ) {
			return;
		}

		$credit_act = zorem_returns_exchanges()->common->return_settings( 'cross_exchange_credit_action', 'store_credit' );
		if ( 'store_credit' !== $credit_act ) {
			return;
		}

		$difference = isset( $exchange_row->price_difference ) ? floatval( $exchange_row->price_difference ) : 0;
		if ( $difference >= -0.5 ) {
			return; // not a credit-owed scenario
		}

		// Use the parent order for billing details (child order may be $0 / not appropriate).
		$parent_order = wc_get_order( $exchange_row->order_id );
		if ( ! $parent_order ) {
			return;
		}

		// If a coupon was already issued under the old approve-time flow, re-use it and
		// still fire the SMS + email on completion so the customer gets notified now.
		if ( ! empty( $exchange_row->store_credit_coupon ) ) {

			/**
			 * Virtual status `exchange-store-credit` — see handle_store_credit() for full docs.
			 */
			do_action( 'zorem_return_status_changed', intval( $exchange_row->id ), intval( $exchange_row->order_id ), 'exchange-store-credit' );

			$this->send_coupon_email( $parent_order, $exchange_row->store_credit_coupon, abs( $difference ) );
			return;
		}

		$this->handle_store_credit( $parent_order, $exchange_row, abs( $difference ) );
	}

	/**
	 * Recalculate price difference server-side at approval time.
	 * Uses new_product_id/new_variation_id vs original order item prices.
	 *
	 * @param  object $exchange_row
	 * @return float  Positive = customer owes. Negative = store owes credit. 0 = even.
	 */
	public function recalculate_price_difference( $exchange_row ) {

		// Per-item targets (admin multi-item exchange): each exchange item carries
		// its own replacement product — sum every item's replacement-vs-original
		// difference. Legacy rows fall through to the single row-level target below.
		$per_item_items = isset( $exchange_row->exchange_items ) ? maybe_unserialize( $exchange_row->exchange_items ) : array();
		$has_per_item   = false;
		if ( is_array( $per_item_items ) ) {
			foreach ( $per_item_items as $item_data ) {
				if ( is_array( $item_data ) && ! empty( $item_data['new_product_id'] ) ) {
					$has_per_item = true;
					break;
				}
			}
		}
		if ( $has_per_item ) {
			$parent_order = wc_get_order( $exchange_row->order_id );
			if ( ! $parent_order ) {
				return 0;
			}
			$difference = 0;
			foreach ( $parent_order->get_items() as $item_id => $item ) {
				if ( ! isset( $per_item_items[ $item_id ] ) || ! is_array( $per_item_items[ $item_id ] ) || empty( $per_item_items[ $item_id ]['new_product_id'] ) ) {
					continue;
				}
				$item_data = $per_item_items[ $item_id ];
				$new_obj   = wc_get_product( ! empty( $item_data['new_variation_id'] ) ? intval( $item_data['new_variation_id'] ) : intval( $item_data['new_product_id'] ) );
				$orig_obj  = $item->get_product();
				if ( ! $new_obj || ! $orig_obj ) {
					continue;
				}
				$ordered_qty = max( 1, intval( $item->get_quantity() ) );
				$posted_qty  = isset( $item_data['exchange_product_qty'] ) ? intval( $item_data['exchange_product_qty'] ) : 0;
				$use_qty     = ( $posted_qty >= 1 ) ? min( $posted_qty, $ordered_qty ) : $ordered_qty;

				// Replacement charge = catalog price incl tax − the same coupon
				// discount the customer had (when the replacement is eligible).
				// Original credit = ACTUAL amount paid for the line (after coupons),
				// prorated to the exchanged qty — not the current catalog price.
				$new_unit_disc = floatval( wc_get_price_including_tax( $new_obj ) ) - zorem_returns_exchanges()->exCommon->zre_replacement_unit_discount( $new_obj, $parent_order );
				$difference   += ( $new_unit_disc * $use_qty ) - zorem_returns_exchanges()->exCommon->zre_original_line_paid_gross( $item, $use_qty );
			}
			return round( $difference, 2 );
		}

		$new_pid = isset( $exchange_row->new_product_id )   ? intval( $exchange_row->new_product_id )   : 0;
		$new_vid = isset( $exchange_row->new_variation_id ) ? intval( $exchange_row->new_variation_id ) : 0;

		if ( ! $new_pid ) {
			return 0;
		}

		// New product price
		$new_obj = wc_get_product( ! empty( $new_vid ) ? $new_vid : $new_pid );
		if ( ! $new_obj ) {
			return 0;
		}

		// Items selected for exchange. In "Exchange Entire Order" mode the qty
		// selects post an entry for EVERY order item, so filter to entries that
		// carry a non-empty exchange_product_id — only checked items have one.
		$exchange_items = isset( $exchange_row->exchange_items ) ? maybe_unserialize( $exchange_row->exchange_items ) : array();
		// Saved rows contain ONLY the items selected for exchange (unselected
		// entries are removed by the submission handler), keyed by order item
		// id — so every numeric-keyed array entry is a selected item.
		$selected_items = array();
		if ( is_array( $exchange_items ) ) {
			foreach ( $exchange_items as $iid => $item_data ) {
				if ( is_array( $item_data ) && is_numeric( $iid ) ) {
					$selected_items[ intval( $iid ) ] = $item_data;
				}
			}
		}

		// Replacement qty: taken from the first selected item's requested qty.
		$new_qty = 1;
		foreach ( $selected_items as $item_data ) {
			if ( isset( $item_data['exchange_product_qty'] ) && intval( $item_data['exchange_product_qty'] ) >= 1 ) {
				$new_qty = intval( $item_data['exchange_product_qty'] );
			}
			break;
		}
		// Fetch the parent order first — its coupons drive the replacement
		// discount, and its line items drive the original credit.
		$parent_order = wc_get_order( $exchange_row->order_id );
		if ( ! $parent_order ) {
			return 0;
		}

		// Replacement charge: catalog price incl tax minus the same coupon
		// discount the customer had on the original order (when the replacement
		// is coupon-eligible). Always INCLUDING-tax so the stored amount equals
		// what the customer sees; the WooCommerce filter in
		// Exchange_Price_Handler::init() divides by (1 + tax_rate) internally.
		$new_unit_price = floatval( wc_get_price_including_tax( $new_obj ) );
		$new_unit_price = $new_unit_price - zorem_returns_exchanges()->exCommon->zre_replacement_unit_discount( $new_obj, $parent_order );
		$new_price      = $new_unit_price * $new_qty;

		// Original credit: sum ALL selected items from the parent WC order using
		// the ACTUAL amount paid (incl tax, after coupons/discounts), prorated to
		// the exchanged qty — not the current catalog price.
		$orig_price = 0;
		foreach ( $parent_order->get_items() as $item_id => $item ) {
			if ( ! isset( $selected_items[ $item_id ] ) ) {
				continue;
			}
			$posted_qty  = isset( $selected_items[ $item_id ]['exchange_product_qty'] ) ? intval( $selected_items[ $item_id ]['exchange_product_qty'] ) : 0;
			$orig_price += zorem_returns_exchanges()->exCommon->zre_original_line_paid_gross( $item, $posted_qty );
		}

		return round( $new_price - $orig_price, 2 );
	}

	// =========================================================================
	// PAY MORE
	// =========================================================================

	private function handle_pay_more( $child_order, $difference, $action ) {

		// Add an "Exchange Credit" fee line item (negative) so WooCommerce's
		// order total always equals the difference: New item − Original item credit.
		//
		// The credit we want to display in the email is the original item's
		// inc-tax price. WC will add tax onto whatever net amount we set on the
		// fee, so we need to set the fee NET so that fee_net + computed_tax
		// equals the desired inc-tax credit.
		//
		// We derive the effective tax rate directly from the child order's line
		// item (line_total + line_total_tax already computed by WC at order
		// creation). This is more reliable than WC_Tax::get_rates(), which
		// depends on customer-location context that may not be available here.
		$line_net      = 0; // sum of line totals (net) for new product items
		$line_tax      = 0; // sum of line taxes
		$tax_class     = '';
		foreach ( $child_order->get_items() as $item ) {
			$line_net += floatval( $item->get_total() );
			$line_tax += floatval( $item->get_total_tax() );
			if ( '' === $tax_class && $item->get_product() ) {
				$tax_class = $item->get_product()->get_tax_class();
			}
		}

		$line_gross    = $line_net + $line_tax;          // new item inc tax
		$credit_amount = $line_gross - $difference;      // original item inc tax (what we want to display as the credit)

		if ( $credit_amount > 0 ) {

			$tax_rate   = ( $line_net > 0 ) ? ( $line_tax / $line_net ) : 0;
			$credit_net = $credit_amount;
			$tax_status = 'none';

			if ( $tax_rate > 0 ) {
				// Reverse-engineer the net amount so that, once WC re-adds tax
				// at the same rate, the inc-tax fee equals $credit_amount exactly.
				$credit_net = $credit_amount / ( 1 + $tax_rate );
				$tax_status = 'taxable';
			}

			$fee = new WC_Order_Item_Fee();
			$fee->set_name( __( 'Exchange Credit (Original Item Value)', 'zorem-returns' ) );
			$fee->set_amount( -$credit_net );
			$fee->set_total( -$credit_net );
			$fee->set_tax_status( $tax_status );
			if ( 'taxable' === $tax_status ) {
				$fee->set_tax_class( $tax_class );
			}
			$child_order->add_item( $fee );
		}

		// Recalculate to apply the fee + its tax, then lock the total. This also
		// re-totals any custom fee added via the
		// `rwc_exchange_child_order_before_calculate_totals` action (fired once at
		// child-order creation in Exchange_Front): WC recomputes each fee from its
		// stored net amount + tax class, so custom fees are neither lost nor doubled.
		$child_order->calculate_totals( true );

		if ( 'ship_first' === $action ) {
			$child_order->set_status( 'processing' );
			$child_order->save();
			// Send invoice email explaining balance owed — no pay button needed since we ship first
			$this->send_ship_first_invoice_email( $child_order, $difference );
		} else {
			$child_order->set_status( 'pending' );
			$child_order->save();
			// Send payment email with pay button
			$this->send_payment_request_email( $child_order, $difference );
		}
	}

	/**
	 * Send a "ship first, pay later" invoice email explaining the balance owed.
	 * No payment button — order is Processing. Customer pays after receiving item.
	 */
	private function send_ship_first_invoice_email( $order, $amount ) {
		$payment_url    = $order->get_checkout_payment_url();
		$customer_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$customer_email = $order->get_billing_email();
		$shop_name      = get_bloginfo( 'name' );
		$amount_html    = html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$subject = sprintf(
			/* translators: 
			* %1$s: Balance amount (formatted)
			* %2$s: Shop name
			*/
			__( 'Your exchange is on its way — balance of %1$s due | %2$s', 'zorem-returns' ),
			$amount_html,
			$shop_name
		);

		$mailer    = WC()->mailer();
		$email_obj = new WC_Email();
		$heading   = __( 'Your exchange order has been shipped', 'zorem-returns' );

		$body  = '<p>' . sprintf(
			/* translators: %1$s: Customer name */
			__( 'Hi <strong>%1$s</strong>,', 'zorem-returns' ),
			esc_html( $customer_name )
		) . '</p>';
		$body .= '<p>' . __( 'Good news — your exchange item has been approved and will be shipped to you shortly.', 'zorem-returns' ) . '</p>';
		$body .= '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:14px 18px;margin:20px 0;">';
		$body .= '<p style="margin:0 0 8px;font-weight:700;color:#856404;">💰 ' . __( 'Balance due:', 'zorem-returns' ) . ' ' . esc_html( $amount_html ) . '</p>';
		$body .= '<p style="margin:0;color:#555;font-size:13px;">' . __( 'The replacement item costs more than your original item. Please pay the balance at your convenience using the link below.', 'zorem-returns' ) . '</p>';
		$body .= '</div>';
		$body .= '<p style="margin:20px 0;">';
		$body .= '<a href="' . esc_url( $payment_url ) . '" style="background:#2271b1;color:#fff;padding:12px 28px;text-decoration:none;border-radius:4px;font-size:15px;font-weight:600;display:inline-block;">💳 ' . __( 'Pay Balance Now', 'zorem-returns' ) . ' &rarr;</a>';
		$body .= '</p>';
		$body .= '<p style="font-size:12px;color:#888;">' . sprintf(
			/* translators: %1$s: Payment link (HTML anchor tag) */
			__( 'Payment link: %1$s', 'zorem-returns' ),
			'<a href="' . esc_url( $payment_url ) . '">' . esc_url( $payment_url ) . '</a>'
		) . '</p>';

		$content = apply_filters( 'woocommerce_mail_content', $email_obj->style_inline( $mailer->wrap_message( $heading, $body ) ) );
		wp_mail( $customer_email, $subject, $content, array( 'Content-Type: text/html; charset=UTF-8' ) );

		// Add order note with payment link for admin reference
		$order->add_order_note(
			sprintf(
				/* translators: 
				* %1$s: Balance amount (formatted HTML)
				* %2$s: Customer payment URL
				*/
				__( 'Ship first invoice sent to customer. Balance due: %1$s. Payment link: %2$s', 'zorem-returns' ),
				$amount_html,
				$payment_url
			),
			false
		);
	}

	/**
	 * Send a payment request email to the customer with the payment link.
	 */
	private function send_payment_request_email( $order, $amount ) {
		// Add payment link as order note so admin can also share it manually
		$payment_url = $order->get_checkout_payment_url();

		$order->add_order_note(
			sprintf(
				/* translators: 
				* %1$s: Exchange payment amount (plain text, no HTML)
				* %2$s: Customer payment URL
				*/
				__( 'Exchange payment required: %1$s. Customer payment link: %2$s', 'zorem-returns' ),
				html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				$payment_url
			),
			false
		);

		// Use WooCommerce's built-in Customer Invoice email (includes "Pay" button).
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		if ( isset( $emails['WC_Email_Customer_Invoice'] ) ) {
			$emails['WC_Email_Customer_Invoice']->trigger( $order->get_id() );
			return;
		}

		// Fallback custom email if WC invoice email unavailable.
		$payment_url    = $order->get_checkout_payment_url();
		$customer_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$customer_email = $order->get_billing_email();
		$shop_name      = get_bloginfo( 'name' );
		$amount_html    = html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$subject = sprintf(
			/* translators: %s: Shop name */
			__( 'Payment required for your exchange — %s', 'zorem-returns' ),
			$shop_name
		);

		$email_obj = new WC_Email();
		$heading   = __( 'Payment required for your exchange', 'zorem-returns' );
		$body  = '<p>' . sprintf(
			/* translators: %s: Customer name */
			__( 'Hi <strong>%s</strong>,', 'zorem-returns' ),
			esc_html( $customer_name )
		) . '</p>';
		$body .= '<p>' . sprintf(
			/* translators: %s: Additional payment amount (formatted HTML) */
			__( 'Your exchange request has been approved. An additional payment of <strong>%s</strong> is required to complete your exchange.', 'zorem-returns' ),
			esc_html( $amount_html )
		) . '</p>';
		$body .= '<p style="margin:24px 0;">' . sprintf(
			'<a href="%1\$s" style="background:#2271b1;color:#fff;padding:12px 28px;text-decoration:none;border-radius:4px;font-size:15px;font-weight:600;display:inline-block;">%2\$s &rarr;</a>',
			esc_url( $payment_url ),
			__( 'Pay Now', 'zorem-returns' )
		) . '</p>';
		$body .= '<p style="font-size:12px;color:#888;">' . sprintf(
			/* translators: %s: Payment link (HTML anchor tag) */
			__( 'Or use this link: %s', 'zorem-returns' ),
			'<a href="' . esc_url( $payment_url ) . '">' . esc_url( $payment_url ) . '</a>'
		) . '</p>';

		$content = apply_filters( 'woocommerce_mail_content', $email_obj->style_inline( $mailer->wrap_message( $heading, $body ) ) );
		wp_mail( $customer_email, $subject, $content, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	// =========================================================================
	// STORE CREDIT
	// =========================================================================

	private function handle_store_credit( $child_order, $exchange_row, $credit_amount ) {
		$coupon_code = $this->generate_credit_coupon(
			$credit_amount,
			$child_order->get_billing_email(),
			$exchange_row->id
		);

		if ( ! $coupon_code ) {
			return;
		}

		// Store coupon code on the exchange row
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'exchange_orders',
			array( 'store_credit_coupon' => $coupon_code ),
			array( 'id' => intval( $exchange_row->id ) )
		);

		/**
		 * Virtual status `exchange-store-credit` fires when a store-credit coupon
		 * is generated for a completed cross-product exchange. Used by the SMS for
		 * WooCommerce plugin to send the matching SMS notification, and shares the
		 * `zorem_return_status_changed` action with the rest of the status pipeline.
		 */
		do_action( 'zorem_return_status_changed', intval( $exchange_row->id ), intval( $exchange_row->order_id ), 'exchange-store-credit' );

		// Email the coupon to the customer
		$this->send_coupon_email( $child_order, $coupon_code, $credit_amount );
	}

	/**
	 * Create a single-use fixed-cart WooCommerce coupon.
	 *
	 * @param  float  $amount
	 * @param  string $customer_email
	 * @param  int    $exchange_id
	 * @return string|false  Coupon code on success.
	 */
	public function generate_credit_coupon( $amount, $customer_email, $exchange_id ) {
		$coupon_code = 'ZRE-' . strtoupper( substr( md5( $exchange_id . $customer_email . microtime() ), 0, 8 ) );

		// Return existing code if already created (idempotent)
		if ( wc_get_coupon_id_by_code( $coupon_code ) ) {
			return $coupon_code;
		}

		$expiry_days = intval( zorem_returns_exchanges()->common->return_settings( 'cross_exchange_credit_expiry', 0 ) );

		$post_id = wp_insert_post( array(
			'post_title'   => $coupon_code,
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'shop_coupon',
		) );

		if ( is_wp_error( $post_id ) ) {
			return false;
		}

		update_post_meta( $post_id, 'discount_type', 'fixed_cart' );
		// Store clean amount (no trailing .00) and the incl-tax display amount for emails
		$clean_amount = rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' );
		update_post_meta( $post_id, 'coupon_amount', $clean_amount );
		update_post_meta( $post_id, 'usage_limit', 1 );
		update_post_meta( $post_id, 'customer_email', array( $customer_email ) );
		update_post_meta( $post_id, 'individual_use', 'yes' );
		update_post_meta( $post_id, '_zre_is_exchange_credit', 'yes' );

		if ( $expiry_days > 0 ) {
			update_post_meta( $post_id, 'date_expires', gmdate( 'Y-m-d', strtotime( '+' . $expiry_days . ' days' ) ) );
		}

		return $coupon_code;
	}

	/**
	 * Email the store-credit coupon to the customer using the customizable template.
	 * Respects the customer_exchange_store_credit_status enable/disable toggle.
	 *
	 * @param WC_Order $order
	 * @param string   $coupon_code
	 * @param float    $amount
	 */
	private function send_coupon_email( $order, $coupon_code, $amount ) {

		if ( 'no' === get_option( 'customer_exchange_store_credit_status', 'yes' ) ) {
			return;
		}

		// WPML: render the email in the language the order was placed in. Restored after send.
		zorem_returns_exchanges()->wpml->switch_to_order_language( $order );

		$defualt_settings = zorem_returns_exchanges()->exCommon->generate_defaults();
		$email_settings   = get_option( 'ex4wc_customer_exchange_store_credit_settings', $defualt_settings['customer_exchange_store_credit'] );

		$customer = new WC_Customer( $order->get_customer_id() );

		$replace_keys = array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{coupon_code}', '{coupon_amount}' );
		$replace_vals = array(
			get_bloginfo( 'name' ),
			$order->get_order_number(),
			wc_format_datetime( $order->get_date_created() ),
			$order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			$order->get_billing_first_name(),
			$order->get_billing_last_name(),
			$customer->get_username(),
			strtoupper( $coupon_code ),
			wp_strip_all_tags( wc_price( $amount ) ),
		);

		$subject = isset( $email_settings['subject'] ) ? $email_settings['subject'] : $defualt_settings['customer_exchange_store_credit']['subject'];
		$heading = isset( $email_settings['heading'] ) ? $email_settings['heading'] : $defualt_settings['customer_exchange_store_credit']['heading'];
		$subject = str_replace( $replace_keys, $replace_vals, $subject );
		$heading = str_replace( $replace_keys, $replace_vals, $heading );

		ob_start();
		$local_template = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/emails/customer-exchange-store-credit.php';
		if ( file_exists( $local_template ) && wp_is_writable( $local_template ) ) {
			$directory_path = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/';
		} else {
			$directory_path = zorem_returns_exchanges()->get_plugin_path() . '/templates/';
		}

		$mailer    = WC()->mailer();
		$email_obj = new WC_Email();

		wc_get_template(
			'emails/customer-exchange-store-credit.php',
			array(
				'order'          => $order,
				'email'          => $email_obj,
				'email_settings' => $email_settings,
				'coupon_code'    => $coupon_code,
				'coupon_amount'  => $amount,
			),
			'zorem-returns-exchanges/',
			$directory_path
		);
		$message = ob_get_clean();

		$content = apply_filters( 'woocommerce_mail_content', $email_obj->style_inline( $mailer->wrap_message( $heading, $message ) ) );
		add_filter( 'wp_mail_from', array( zorem_returns_exchanges()->exFront, 'get_from_address' ) );
		add_filter( 'wp_mail_from_name', array( zorem_returns_exchanges()->exFront, 'get_from_name' ) );

		$recipient = $order->get_billing_email();
		if ( $recipient ) {
			wp_mail( $recipient, $subject, $content, $email_obj->get_headers() );
		}

		zorem_returns_exchanges()->wpml->restore_language();
	}
}
