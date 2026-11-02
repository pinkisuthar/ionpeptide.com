<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Exchange_Center_Form {

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
		add_action( 'wp_ajax_fetch_exchange_items_data', array( $this, 'fetch_exchange_items_data_callback' ) );
		add_action( 'wp_ajax_nopriv_fetch_exchange_items_data', array( $this, 'fetch_exchange_items_data_callback' ) );
		add_action( 'wp_ajax_save_exchange_center_data', array( $this, 'save_exchange_center_data_callback' ) );
		add_action( 'wp_ajax_nopriv_save_exchange_center_data', array( $this, 'save_exchange_center_data_callback' ) );

		// exchange request cancel actions
		add_action( 'wp_ajax_exchange_request_cancel', array( $this, 'exchange_request_cancel_callback' ) );
		add_action( 'wp_ajax_nopriv_exchange_request_cancel', array( $this, 'exchange_request_cancel_callback' ) );

		// Admin-initiated exchange request from the order edit screen (admin-only, no nopriv).
		add_action( 'wp_ajax_zorem_admin_create_exchange', array( $this, 'admin_create_exchange_callback' ) );
	}

	/**
	 * Get fetch return center items data by order ID
	 *
	 * @since  1.0.0
	 */
	public function fetch_exchange_items_data_callback() {
		if ( ! empty( $_POST ) && check_ajax_referer( 'exchange_center_multiStep_form', 'exchange_center_multiStep_nonce' ) ) {

			$order_id              = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
			$item_id               = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';
			$deliverd_variation_id = isset( $_POST['variation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['variation_id'] ) ) : '';
			$variants              = isset( $_POST['variants'] ) ? wc_clean( wp_unslash( $_POST['variants'] ) ) : array();

			if ( ! $item_id ) {
				wp_send_json_error();
				die();
			}

			$deliverd_variation_obj = new WC_Product_Variation( $deliverd_variation_id );
			$deliverd_item_price    = $deliverd_variation_obj->get_price();

			$variable_product_id = wc_get_order_item_meta( $item_id, '_product_id', true );
			$variation_id        = zorem_returns_exchanges()->exCommon->get_variation_id_by_attributes( $variable_product_id, $variants );
			$variation_obj       = new WC_Product_Variation( $variation_id );
			$exchange_item_price = $variation_obj->get_price();

			if ( $variation_id != $deliverd_variation_id && $exchange_item_price === $deliverd_item_price ) {
				wp_send_json_success();
			} else {
				wp_send_json_error();
			}
			die();
		}
	}

	/**
	 * Callback function of save exchange center form data
	 *
	 * @since  1.0.0
	 */
	public function save_exchange_center_data_callback() {
		if ( ! empty( $_POST ) && check_ajax_referer( 'exchange_center_request_form', 'exchange_center_request_nonce' ) ) {

			$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
			$order    = ! empty( $order_id ) ? wc_get_order( $order_id ) : '';

			if ( ! $order ) {
				wp_send_json_error();
				die();
			}

			// Block submission if the order's payment method is restricted from exchanges.
			if ( zorem_returns_exchanges()->exCommon->is_payment_method_blocked_for_exchange( $order ) ) {
				$message = zorem_returns_exchanges()->exCommon->get_exchange_blocked_message( $order );
				wp_send_json_error( array( 'error' => $message ) );
				die();
			}

			// Cross-product mode: every item selected for exchange must carry its
			// OWN replacement product (and a concrete variant when the replacement
			// is a variable product). Mirrors the per-item widgets on the form.
			if ( class_exists( 'Zorem_Exchange_Product_Search' ) && Zorem_Exchange_Product_Search::is_enabled() ) {
				$posted_ex_items = isset( $_POST['exchange_data']['exchange_items'] ) ? wc_clean( wp_unslash( $_POST['exchange_data']['exchange_items'] ) ) : array();
				foreach ( (array) $posted_ex_items as $posted_item ) {
					if ( ! is_array( $posted_item ) ) {
						continue;
					}
					$is_selected = ! empty( $posted_item['exchange_product_id'] ) && ( ! isset( $posted_item['exchange_product_qty'] ) || (int) $posted_item['exchange_product_qty'] > 0 );
					if ( ! $is_selected ) {
						continue;
					}
					$posted_pid = isset( $posted_item['new_product_id'] ) ? absint( $posted_item['new_product_id'] ) : 0;
					if ( ! $posted_pid ) {
						wp_send_json_error( array( 'error' => __( 'Please select a replacement product for each item you are exchanging.', 'zorem-returns' ) ) );
						die();
					}
					$posted_target = wc_get_product( $posted_pid );
					if ( ! $posted_target || ! $posted_target->is_purchasable() ) {
						wp_send_json_error( array( 'error' => __( 'One of the selected replacement products is no longer available.', 'zorem-returns' ) ) );
						die();
					}
					if ( $posted_target->is_type( 'variable' ) && empty( $posted_item['new_variation_id'] ) ) {
						wp_send_json_error( array( 'error' => __( 'Please select a variant for each replacement product.', 'zorem-returns' ) ) );
						die();
					}
				}
			}

			/**
			 * Filter: validate a customer-submitted exchange request BEFORE the
			 * request row is created. Counterpart of `zorem_return_request_validation`
			 * — see its docblock in Return_Center_Form for full usage.
			 *
			 * Return `true` (default) to accept. To reject, return a `WP_Error`
			 * (its message is shown to the customer), a non-empty string (shown
			 * as-is), or `false` (a generic message is shown).
			 *
			 * @param bool|string|WP_Error $valid  True to accept (default).
			 * @param WC_Order             $order  Order the exchange is requested against.
			 * @param array                $posted Sanitized submitted form data (order_id, exchange_data, …).
			 */
			$validation = apply_filters( 'zorem_exchange_request_validation', true, $order, wc_clean( wp_unslash( $_POST ) ) );
			if ( true !== $validation ) {
				$message = '';
				if ( is_wp_error( $validation ) ) {
					$message = $validation->get_error_message();
				} elseif ( is_string( $validation ) && '' !== trim( $validation ) ) {
					$message = $validation;
				} elseif ( false === $validation ) {
					$message = __( 'Your exchange request could not be submitted. Please contact us for assistance.', 'zorem-returns' );
				}
				if ( '' !== $message ) {
					wp_send_json_error( array( 'error' => $message ) );
					die();
				}
			}

			// save form data
			$request_id = $this->save_exchange_requested_data( $_POST, $_FILES );

			ob_start();
			?>
			<div class="step" id="step-3" style="display: block;">
				<?php echo esc_html( zorem_returns_exchanges()->exCommon->display_exchange_data_request_id( $request_id ) ); ?>
			</div>
			<?php
			$html = ob_get_clean();

			wp_send_json_success( array( 'step3Html' => $html ) );
			die();
		}
	}

	/**
	 * Admin-initiated exchange: create ONE exchange request on the customer's
	 * behalf from the order edit screen, covering every selected item. Two
	 * modes, exactly like the customer form: same-variation (equal-price
	 * variant of the same product) and cross-product (each item carries its
	 * OWN replacement product, stored per item inside exchange_items; the
	 * row-level target columns keep the first item's target for backward
	 * compatibility). Approval creates a single child order containing all
	 * replacement items. Customer-facing eligibility rules are deliberately
	 * bypassed; they are shown as soft warnings in the modal instead.
	 *
	 * @since 1.6.9
	 */
	public function admin_create_exchange_callback() {

		check_ajax_referer( 'zorem_admin_create_exchange', 'security' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'error' => __( 'You do not have permission to create exchange requests.', 'zorem-returns' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			wp_send_json_error( array( 'error' => __( 'Invalid order.', 'zorem-returns' ) ) );
		}

		$exchange_data = isset( $_POST['exchange_data'] ) ? wc_clean( wp_unslash( $_POST['exchange_data'] ) ) : array();
		$posted_items  = isset( $exchange_data['exchange_items'] ) && is_array( $exchange_data['exchange_items'] ) ? $exchange_data['exchange_items'] : array();

		// Cross-product mode: replacement is any product picked via search. Only
		// honoured while the setting is enabled (mirrors the customer form).
		$cross_mode = isset( $exchange_data['exchange_type'] ) && 'cross-product' === $exchange_data['exchange_type'];
		if ( $cross_mode && ! ( class_exists( 'Zorem_Exchange_Product_Search' ) && Zorem_Exchange_Product_Search::is_enabled() ) ) {
			wp_send_json_error( array( 'error' => __( 'Cross-product exchange is not enabled.', 'zorem-returns' ) ) );
		}

		// Validate items: must belong to the order, not exceed the remaining
		// exchangeable qty (units already returned or exchanged are excluded),
		// and resolve to an equal-price replacement variant.
		$exchange_datas = zorem_returns_exchanges()->exCommon->get_exchange_data_by_order_id( $order_id );
		// Rejected/cancelled returns must not reserve quantity, so those units stay exchangeable.
		$return_datas   = zorem_returns_exchanges()->common->get_active_return_data_by_order_id( $order_id );
		$items_clean    = array();
		$cross_targets  = array(); // Row-level cross summary: first item's target + summed price difference.
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( empty( $posted_items[ $item_id ]['exchange_product_qty'] ) ) {
				continue;
			}
			$qty       = absint( $posted_items[ $item_id ]['exchange_product_qty'] );
			$remaining = $item->get_quantity()
				- (int) zorem_returns_exchanges()->exCommon->get_exchanged_line_item_qty( $item_id, $exchange_datas )
				- (int) zorem_returns_exchanges()->common->get_returned_line_item_qty( $item_id, $return_datas );
			if ( $qty < 1 || $qty > $remaining ) {
				/* translators: %s: product name */
				wp_send_json_error( array( 'error' => sprintf( __( 'Invalid exchange quantity for "%s".', 'zorem-returns' ), $item->get_name() ) ) );
			}

			if ( $cross_mode ) {
				// Per-item replacement: validate THIS item's chosen product/variant and
				// compute its own price difference (each item becomes its own request).
				$new_product_id   = isset( $posted_items[ $item_id ]['new_product_id'] ) ? absint( $posted_items[ $item_id ]['new_product_id'] ) : 0;
				$new_variation_id = isset( $posted_items[ $item_id ]['new_variation_id'] ) ? absint( $posted_items[ $item_id ]['new_variation_id'] ) : 0;

				$new_product = $new_product_id ? wc_get_product( $new_product_id ) : false;
				if ( ! $new_product || ! $new_product->is_purchasable() ) {
					/* translators: %s: product name */
					wp_send_json_error( array( 'error' => sprintf( __( 'Please select a valid replacement product for "%s".', 'zorem-returns' ), $item->get_name() ) ) );
				}
				if ( $new_product->is_type( 'variable' ) ) {
					$new_variation = $new_variation_id ? wc_get_product( $new_variation_id ) : false;
					if ( ! $new_variation || ! $new_variation->is_type( 'variation' ) || (int) $new_variation->get_parent_id() !== (int) $new_product_id ) {
						/* translators: %s: product name */
						wp_send_json_error( array( 'error' => sprintf( __( 'Please select a variant of the replacement product for "%s".', 'zorem-returns' ), $item->get_name() ) ) );
					}
					if ( ! $new_variation->is_in_stock() ) {
						/* translators: %s: product name */
						wp_send_json_error( array( 'error' => sprintf( __( 'The replacement variant selected for "%s" is out of stock.', 'zorem-returns' ), $item->get_name() ) ) );
					}
					$target_obj = $new_variation;
				} else {
					$new_variation_id = 0;
					if ( ! $new_product->is_in_stock() ) {
						/* translators: %s: product name */
						wp_send_json_error( array( 'error' => sprintf( __( 'The replacement product selected for "%s" is out of stock.', 'zorem-returns' ), $item->get_name() ) ) );
					}
					$target_obj = $new_product;
				}

				// Same logic as approval-time recalculation, per item: credit the
				// ACTUAL amount paid for the original line (after coupons) and charge
				// the replacement's catalog price minus the same coupon discount when
				// eligible. Display-only; approval re-verifies via Exchange_Price_Handler.
				$orig_price    = zorem_returns_exchanges()->exCommon->zre_original_line_paid_gross( $item, $qty );
				$new_unit_disc = (float) wc_get_price_including_tax( $target_obj ) - zorem_returns_exchanges()->exCommon->zre_replacement_unit_discount( $target_obj, $order );
				$difference    = round( ( $new_unit_disc * $qty ) - $orig_price, 2 );
				if ( abs( $difference ) <= 0.5 ) {
					$difference = 0;
				}

				// Target stored PER ITEM inside exchange_items — one request row and
				// one child order cover the whole multi-item exchange.
				$items_clean[ $item_id ] = array(
					'exchange_product_id'  => $item_id,
					'exchange_product_qty' => $qty,
					'new_product_id'       => $new_product_id,
					'new_variation_id'     => $new_variation_id,
				);

				// Chosen attribute values so "Any …" variations resolve for fulfilment.
				if ( isset( $posted_items[ $item_id ]['new_variation_attrs'] ) && is_array( $posted_items[ $item_id ]['new_variation_attrs'] ) ) {
					$cross_variants = array();
					foreach ( $posted_items[ $item_id ]['new_variation_attrs'] as $attr_key => $attr_val ) {
						$attr_key = sanitize_text_field( $attr_key );
						$attr_val = sanitize_text_field( $attr_val );
						if ( 0 === strpos( $attr_key, 'attribute_' ) && '' !== $attr_val ) {
							$cross_variants[ $attr_key ] = $attr_val;
						}
					}
					if ( ! empty( $cross_variants ) ) {
						$items_clean[ $item_id ]['cross_variants'] = $cross_variants;
					}
				}

				// Row-level summary: first item's target (legacy display fallback) and
				// the difference summed across all items (drives pay-more / store credit).
				if ( empty( $cross_targets ) ) {
					$cross_targets = array(
						'exchange_type'    => 'cross-product',
						'new_product_id'   => $new_product_id,
						'new_variation_id' => $new_variation_id,
						'price_difference' => 0,
					);
				}
				$cross_targets['price_difference'] += $difference;
				continue;
			}

			$variants_raw = isset( $posted_items[ $item_id ]['variants'] ) && is_array( $posted_items[ $item_id ]['variants'] ) ? $posted_items[ $item_id ]['variants'] : array();
			$variants     = array();
			foreach ( $variants_raw as $attr_key => $attr_val ) {
				$attr_key = sanitize_text_field( $attr_key );
				$attr_val = sanitize_text_field( $attr_val );
				if ( '' !== $attr_val ) {
					$variants[ $attr_key ] = $attr_val;
				}
			}
			if ( empty( $variants ) ) {
				/* translators: %s: product name */
				wp_send_json_error( array( 'error' => sprintf( __( 'Please select a replacement variant for "%s".', 'zorem-returns' ), $item->get_name() ) ) );
			}

			// The chosen attributes must resolve to a real variation of this product…
			$variable_product_id = $item->get_product_id();
			$new_variation_id    = zorem_returns_exchanges()->exCommon->get_variation_id_by_attributes( $variable_product_id, $variants );
			if ( empty( $new_variation_id ) ) {
				/* translators: %s: product name */
				wp_send_json_error( array( 'error' => sprintf( __( 'The selected variant of "%s" is not available.', 'zorem-returns' ), $item->get_name() ) ) );
			}
			// …that differs from the one delivered and costs the same (±0.5, incl. tax —
			// same tolerance as the customer form's equal-price filter).
			$delivered_variation = wc_get_product( $item->get_variation_id() );
			$new_variation       = wc_get_product( $new_variation_id );
			if ( ! $new_variation || (int) $new_variation_id === (int) $item->get_variation_id() ) {
				/* translators: %s: product name */
				wp_send_json_error( array( 'error' => sprintf( __( 'Please pick a different variant of "%s" than the one delivered.', 'zorem-returns' ), $item->get_name() ) ) );
			}
			$delivered_price = $delivered_variation ? (float) wc_get_price_including_tax( $delivered_variation ) : 0;
			$new_price       = (float) wc_get_price_including_tax( $new_variation );
			if ( abs( $new_price - $delivered_price ) >= 0.5 ) {
				/* translators: %s: product name */
				wp_send_json_error( array( 'error' => sprintf( __( '"%s" can only be exchanged for a variant of equal value.', 'zorem-returns' ), $item->get_name() ) ) );
			}

			$items_clean[ $item_id ] = array(
				'exchange_product_id'  => $item_id,
				'exchange_product_qty' => $qty,
				'variants'             => $variants,
			);
		}
		if ( empty( $items_clean ) ) {
			wp_send_json_error( array( 'error' => __( 'Please select a quantity for at least one item.', 'zorem-returns' ) ) );
		}

		$reason = isset( $exchange_data['exchange_reason']['no_other'] ) ? $exchange_data['exchange_reason']['no_other'] : '';
		if ( '' === $reason ) {
			wp_send_json_error( array( 'error' => __( 'Please select an exchange reason.', 'zorem-returns' ) ) );
		}

		$status = isset( $exchange_data['exchange_status'] ) && in_array( $exchange_data['exchange_status'], array( 'exchange-requested', 'exchange-approved' ), true ) ? $exchange_data['exchange_status'] : 'exchange-approved';

		$notify_customer = isset( $_POST['notify_customer'] ) ? sanitize_text_field( wp_unslash( $_POST['notify_customer'] ) ) : 'no';
		$reason_array    = array(
			'no_other'     => $reason,
			'other_reason' => isset( $exchange_data['exchange_reason']['other_reason'] ) ? $exchange_data['exchange_reason']['other_reason'] : '',
		);
		$issue           = isset( $exchange_data['exchange_issue'] ) ? $exchange_data['exchange_issue'] : '';

		if ( $cross_mode ) {
			$cross_targets['price_difference'] = round( $cross_targets['price_difference'], 2 );
		}

		// ONE request covering every selected item — approval creates a single
		// child order containing all the replacement products.
		$request = array(
			'order_id'        => $order_id,
			'notify_customer' => $notify_customer,
			'exchange_data'   => array_merge(
				array(
					'exchange_items'  => $items_clean,
					'exchange_reason' => $reason_array,
					'exchange_issue'  => $issue,
					'exchange_status' => $status,
				),
				$cross_mode ? $cross_targets : array()
			),
		);

		$request_id = $this->save_exchange_requested_data( $request, array(), 'admin' );

		if ( empty( $request_id ) ) {
			wp_send_json_error( array( 'error' => __( 'Could not create the exchange request.', 'zorem-returns' ) ) );
		}

		wp_send_json_success( array( 'request_id' => $request_id ) );
	}

	/**
	 * Save exchange request form data
	 *
	 * @param array  $POST    Submitted form data (order_id, exchange_data, …).
	 * @param array  $FILES   Uploaded attachment files (empty array for admin flow).
	 * @param string $context 'customer' (default) or 'admin' — admin-initiated requests
	 *                        use the status chosen in the modal, only email the customer
	 *                        when asked, and skip the admin notification email.
	 * @since  1.0.0
	 */
	public function save_exchange_requested_data( $POST, $FILES, $context = 'customer' ) {

		if ( isset( $FILES ) && $FILES['exchange_attachments']['name'] && ! empty( $FILES['exchange_attachments']['name'][0] ) && ! is_int( $FILES['exchange_attachments']['name'] ) ) {

			$length        = count( $FILES['exchange_attachments']['name'] );
			$file          = $FILES['exchange_attachments'];
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

		$order_id       = isset( $POST['order_id'] ) ? sanitize_text_field( $POST['order_id'] ) : '';
		$exchange_datas = zorem_returns_exchanges()->exCommon->get_exchange_data_by_order_id( $order_id );

		$order          = wc_get_order( $order_id );
		$exchange_array = isset( $POST['exchange_data'] ) ? wc_clean( $POST['exchange_data'] ) : array();
		if ( isset( $exchange_array['exchange_the_entire_order'] ) && 1 == $exchange_array['exchange_the_entire_order'] ) {
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( 0 == $exchange_array['exchange_items'][ $item_id ]['exchange_product_qty'] ) {
					unset( $exchange_array['exchange_items'][ $item_id ] );
				} else {
					unset( $exchange_array['exchange_items'][ $item_id ]['exchange_product_id'] );
					$item_qty = $item->get_quantity() - zorem_returns_exchanges()->exCommon->get_exchanged_line_item_qty( $item_id, $exchange_datas );
					$exchange_array['exchange_items'][ $item_id ]['exchange_product_qty'] = $item_qty;
				}
			}
		} else {
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( isset( $exchange_array['exchange_items'][ $item_id ] ) && null != $exchange_array['exchange_items'][ $item_id ] && 0 == $exchange_array['exchange_items'][ $item_id ]['exchange_product_id'] ) {
					unset( $exchange_array['exchange_items'][ $item_id ] );
				} else {
					unset( $exchange_array['exchange_items'][ $item_id ]['exchange_product_id'] );
				}
			}
		}

		// Items with no quantity are not being exchanged — drop them, and reject
		// the request outright when nothing exchangeable remains (e.g. a customer
		// ticked an item but left its quantity at 0).
		if ( ! empty( $exchange_array['exchange_items'] ) && is_array( $exchange_array['exchange_items'] ) ) {
			foreach ( $exchange_array['exchange_items'] as $iid => $item_data ) {
				if ( ! is_array( $item_data ) || empty( $item_data['exchange_product_qty'] ) || (int) $item_data['exchange_product_qty'] < 1 ) {
					unset( $exchange_array['exchange_items'][ $iid ] );
				}
			}
		}
		if ( empty( $exchange_array['exchange_items'] ) ) {
			wp_send_json_error( array( 'error' => __( 'Please select a quantity for at least one item.', 'zorem-returns' ) ) );
			die();
		}

		// Cross-product: persist the customer's chosen attribute values so a
		// replacement variation saved with "Any …" attributes still records
		// exactly what the customer picked (e.g. Taglia 38 / Tacco 120).
		if ( isset( $exchange_array['new_variation_attrs'] ) && is_array( $exchange_array['new_variation_attrs'] ) ) {
			$chosen_attrs = array();
			foreach ( $exchange_array['new_variation_attrs'] as $attr_key => $attr_val ) {
				$attr_key = sanitize_text_field( $attr_key );
				$attr_val = sanitize_text_field( $attr_val );
				if ( 0 === strpos( $attr_key, 'attribute_' ) && '' !== $attr_val ) {
					$chosen_attrs[ $attr_key ] = $attr_val;
				}
			}
			if ( ! empty( $chosen_attrs ) && ! empty( $exchange_array['exchange_items'] ) && is_array( $exchange_array['exchange_items'] ) ) {
				foreach ( $exchange_array['exchange_items'] as $iid => $item_data ) {
					if ( is_array( $item_data ) ) {
						$exchange_array['exchange_items'][ $iid ]['cross_variants'] = $chosen_attrs;
					}
				}
			}
			unset( $exchange_array['new_variation_attrs'] );
		}

		// Per-item cross-product targets (multi-item exchange): each item carries
		// its own new_product_id / new_variation_id / cross_variants. Sanitize
		// them and derive the row-level summary (type, first target, summed
		// price difference) server-side — never trusted from the client.
		$per_item_cross = false;
		if ( ! empty( $exchange_array['exchange_items'] ) && is_array( $exchange_array['exchange_items'] ) ) {
			foreach ( $exchange_array['exchange_items'] as $iid => $item_data ) {
				if ( ! is_array( $item_data ) ) {
					continue;
				}
				if ( ! empty( $item_data['new_product_id'] ) ) {
					$exchange_array['exchange_items'][ $iid ]['new_product_id']   = absint( $item_data['new_product_id'] );
					$exchange_array['exchange_items'][ $iid ]['new_variation_id'] = ! empty( $item_data['new_variation_id'] ) ? absint( $item_data['new_variation_id'] ) : 0;
					if ( ! empty( $item_data['cross_variants'] ) && is_array( $item_data['cross_variants'] ) ) {
						$clean_cv = array();
						foreach ( $item_data['cross_variants'] as $cv_key => $cv_val ) {
							$cv_key = sanitize_text_field( $cv_key );
							$cv_val = sanitize_text_field( $cv_val );
							if ( 0 === strpos( $cv_key, 'attribute_' ) && '' !== $cv_val ) {
								$clean_cv[ $cv_key ] = $cv_val;
							}
						}
						if ( ! empty( $clean_cv ) ) {
							$exchange_array['exchange_items'][ $iid ]['cross_variants'] = $clean_cv;
						} else {
							unset( $exchange_array['exchange_items'][ $iid ]['cross_variants'] );
						}
					}
					$per_item_cross = true;
				} else {
					unset( $exchange_array['exchange_items'][ $iid ]['new_product_id'], $exchange_array['exchange_items'][ $iid ]['new_variation_id'] );
				}
			}
		}
		if ( $per_item_cross ) {
			$exchange_array['exchange_type'] = 'cross-product';

			// Row-level summary target = first item's target (legacy display fallback).
			foreach ( $exchange_array['exchange_items'] as $item_data ) {
				if ( is_array( $item_data ) && ! empty( $item_data['new_product_id'] ) ) {
					$exchange_array['new_product_id']   = $item_data['new_product_id'];
					$exchange_array['new_variation_id'] = $item_data['new_variation_id'];
					break;
				}
			}

			// Summed price difference across all items (same formula as
			// Exchange_Price_Handler::recalculate_price_difference(), which
			// re-verifies this again at approval time).
			$sum_difference = 0;
			foreach ( $order->get_items() as $iid => $order_item ) {
				if ( empty( $exchange_array['exchange_items'][ $iid ]['new_product_id'] ) ) {
					continue;
				}
				$item_data  = $exchange_array['exchange_items'][ $iid ];
				$target_obj = wc_get_product( ! empty( $item_data['new_variation_id'] ) ? $item_data['new_variation_id'] : $item_data['new_product_id'] );
				$orig_obj   = $order_item->get_product();
				if ( ! $target_obj || ! $orig_obj ) {
					continue;
				}
				$use_qty         = isset( $item_data['exchange_product_qty'] ) ? max( 1, (int) $item_data['exchange_product_qty'] ) : 1;
				$new_unit_disc   = (float) wc_get_price_including_tax( $target_obj ) - zorem_returns_exchanges()->exCommon->zre_replacement_unit_discount( $target_obj, $order );
				$sum_difference += ( $new_unit_disc * $use_qty ) - zorem_returns_exchanges()->exCommon->zre_original_line_paid_gross( $order_item, $use_qty );
			}
			$exchange_array['price_difference'] = round( $sum_difference, 2 );
		}

		if ( 'admin' === $context ) {
			// Admin-initiated: status chosen in the modal (default approved).
			$admin_status                      = isset( $exchange_array['exchange_status'] ) ? $exchange_array['exchange_status'] : '';
			$exchange_array['exchange_status'] = in_array( $admin_status, array( 'exchange-requested', 'exchange-approved' ), true ) ? $admin_status : 'exchange-approved';
		} else {
			// Automatic exchange approval
			$automatic_exchange = zorem_returns_exchanges()->common->return_settings( 'automatic_exchange', 'no' );
			if ( 'yes' == $automatic_exchange ) {
				$exchange_array['exchange_status'] = 'exchange-approved';
			} else {
				$exchange_array['exchange_status'] = 'exchange-requested';
			}
		}

		// Get the user ID
		$order   = wc_get_order( $order_id );
		$user_id = $order ? $order->get_user_id() : 0;

		$keep_the_item = ( isset( $exchange_array['keep_the_item'] ) && 'yes' === $exchange_array['keep_the_item'] ) ? 'yes' : '';

		$exchange_data = array(
			'order_id'        => $order_id,
			'user_id'         => $user_id,
			'exchange_status' => $exchange_array['exchange_status'],
			'exchange_items'  => serialize( $exchange_array['exchange_items'] ),
			'exchange_reason' => serialize( $exchange_array['exchange_reason'] ),
			'exchange_issue'  => $exchange_array['exchange_issue'],
			'keep_the_item'   => $keep_the_item,
		);

		// Cross-product exchange: persist new product, variation and price difference.
		if ( isset( $exchange_array['exchange_type'] ) && 'cross-product' === $exchange_array['exchange_type'] ) {
			$exchange_data['exchange_type']    = 'cross-product';
			$exchange_data['new_product_id']   = isset( $exchange_array['new_product_id'] )   ? absint( $exchange_array['new_product_id'] )   : 0;
			$exchange_data['new_variation_id'] = isset( $exchange_array['new_variation_id'] ) ? absint( $exchange_array['new_variation_id'] ) : 0;
			$exchange_data['price_difference'] = isset( $exchange_array['price_difference'] )  ? floatval( $exchange_array['price_difference'] ) : 0.00;
		} else {
			$exchange_data['exchange_type'] = 'same-variation';
		}

		// database functions
		global $wpdb;
		$table = $wpdb->prefix . 'exchange_orders';

		// insert data functions
		$wpdb->insert( $table, $exchange_data );
		$request_id = $wpdb->insert_id;

		$exchange_items_image = zorem_returns_exchanges()->common->upload_file_by_url( ( isset( $FILES ) ? $FILES : array() ), $request_id );
		if ( $exchange_items_image ) {
			$exchange_data['exchange_items_image'] = serialize( $exchange_items_image );
			$where                                 = array(
				'id' => $request_id,
			);
			$wpdb->update( $table, $exchange_data, $where );
		}

		// Order status change to exchange( After all items requested for exchange )
		if ( ! empty( $request_id ) && ! empty( $order ) ) {

			$is_order_valid     = zorem_returns_exchanges()->common->is_order_valid_for_return( $order );
			$exchange_form_data = zorem_returns_exchanges()->exCommon->get_exchanged_form_data( $order );

			$exchange_datas  = zorem_returns_exchanges()->exCommon->get_exchange_data_by_order_id( $order_id );
			$is_valid_status = false;
			foreach ( (array) $exchange_datas as $exchange_data ) {
				$exchange_status = isset( $exchange_data->exchange_status ) ? $exchange_data->exchange_status : '';
				if ( 'exchange-approved' == $exchange_status ) {
					$is_valid_status = true;
				}
			}

			if ( $is_order_valid && empty( $exchange_form_data ) && $is_valid_status ) {
				// update order status
				$order->set_status( 'return' );
				$order->save();
			}

			if ( $is_order_valid && ! empty( $exchange_form_data ) && $is_valid_status ) {
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
				// Send a exchange request email to customer
				zorem_returns_exchanges()->exFront->send_exchange_email_func( $request_id, $order_id, $exchange_array['exchange_status'] );
			}

			if ( 'exchange-approved' == $exchange_array['exchange_status'] ) {
				// Create a new order for exchange item
				zorem_returns_exchanges()->exFront->create_exchange_order( $request_id );
			}

			if ( 'admin' !== $context && ( 'exchange-requested' == $exchange_array['exchange_status'] || 'exchange-approved' == $exchange_array['exchange_status'] ) ) {
				// Send a exchange request email to admin
				zorem_returns_exchanges()->exFront->send_exchange_admin_email_func( $request_id, $order_id, $exchange_array['exchange_status'] );
			}

			// Automatically restocks items when a exchange request is approved
			if ( 'exchange-approved' == $exchange_array['exchange_status'] ) {
				zorem_returns_exchanges()->exCommon->restock_exchange_items_by_request_id( $request_id );
			}
		}

		return $request_id;
	}

	/**
	 * Callback function of exchange request cancel
	 *
	 * @since  1.0.0
	 */
	public function exchange_request_cancel_callback() {

		if ( ! empty( $_POST ) ) {

			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! empty( $nonce ) && ! wp_verify_nonce( $nonce, 'exchange_request_cancel' ) ) {
				return;
			}

			$request_id = isset( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '';
			if ( ! empty( $request_id ) ) {

				// database functions
				global $wpdb;
				$table = $wpdb->prefix . 'exchange_orders';

				$exchange_data = (array) zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $request_id );

				$exchange_data['exchange_status'] = 'exchange-cancelled';
				$where                            = array(
					'id' => $request_id,
				);

				$wpdb->update( $table, $exchange_data, $where );

				zorem_returns_exchanges()->front->send_return_admin_email_func( $request_id, $exchange_data['order_id'], 'exchange-cancelled' );

				wp_send_json_success();
			}
		}
	}
}
