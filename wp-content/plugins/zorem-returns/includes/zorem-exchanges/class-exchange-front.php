<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.DynamicHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
if (!defined('ABSPATH')) {
	exit;
}

class Exchange_Front {

	public function __construct() {
		$this->init();
	}

	private static $instance;
	
	/**
	 * Get the class instance
	 *
	 * @since  1.0
	 * 
	 */
	public static function get_instance() {

		if (null === self::$instance) {
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

		//database functions
		global $wpdb;
		$table = $wpdb->prefix . 'exchange_orders';
		if ($wpdb->get_var($wpdb->prepare('show tables like %s', $table)) != $table) {
			$create_table_query = "
				CREATE TABLE IF NOT EXISTS `{$table}` (
					`id` int NOT NULL AUTO_INCREMENT,
					`order_id` text NULL,
					`exchange_status` text NULL,
					`exchange_items` text NULL,
					`exchange_reason` text NULL,
					`exchange_issue` text NULL,
					`exchange_items_image` text NULL,
					PRIMARY KEY (id)
				) ENGINE=InnoDB  DEFAULT CHARSET=utf8;
			";
			// Run directly instead of dbDelta(): dbDelta cannot parse IF NOT EXISTS
			// (it reads the table name as "IF" and runs a broken "DESCRIBE IF;" query).
			$wpdb->query( $create_table_query );
		}

		$return_general_settings_option = get_option( 'return_general_settings_option', array() );
		$exchange_entire_order   = isset($return_general_settings_option['exchange_entire_order']) ? $return_general_settings_option['exchange_entire_order'] : 'no';
		$cross_product_exchange  = isset($return_general_settings_option['allow_cross_product_exchange']) ? $return_general_settings_option['allow_cross_product_exchange'] : 'no';
		// Show per-item Exchange button when partial exchange OR cross-product exchange is enabled
		if ( 'yes' == $exchange_entire_order || 'yes' == $cross_product_exchange ) {
			//Add custom action button after product title in order details
			add_action( 'woocommerce_order_item_meta_end', array( $this, 'add_exchange_request_button_after_product_title' ), 10, 3 );
		}

		add_action('woocommerce_order_details_after_order_table', array($this, 'add_exchange_request_button_after_order_table'), 10, 1);

		// NOTE (1.6.7): We no longer hook woocommerce_order_number to prepend
		// "parent → child" on exchange child orders. That filter's output is
		// consumed as the REAL order/invoice number by integrations (Xero for
		// WooCommerce builds its InvoiceNumber from get_order_number(), PDF
		// invoice plugins, accounting syncs, marketplaces). Xero rejects the
		// non-ASCII "→" with "ValidationException: Please remove invalid
		// character → from Invoice number field". The parent↔child relationship
		// is still visible in the Returns Dashboard (its JS concatenates the
		// numbers itself), in the "Exchange Parent Order" admin metabox, and in
		// order links — without polluting the canonical order number.

		// Add exchange endpoint content
		add_action('woocommerce_account_exchange_endpoint', array($this, 'exchange_endpoint_content'));

		// Bridge: when admin marks an exchange CHILD order as Completed from the
		// regular WC Orders list, transition the exchange row to
		// "exchange-completed" so the same follow-up runs as when admin clicks
		// "Mark as Completed" inside the Returns Dashboard — fires emails and
		// generates the store-credit coupon (for pay-less cross-product exchanges).
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_wc_child_order_completed' ), 10, 1 );

		// Secure download endpoint for exchange shipping label PDF
		add_action( 'wp_ajax_rt4wc_download_exchange_label', array( $this, 'download_exchange_label' ) );
	}

	/**
	 * Mirror "Mark as Completed" from the WC Orders list onto the exchange row.
	 *
	 * Triggered for ANY WC order completion, but only acts when the order is an
	 * exchange child (carries _parent_order_id meta). Idempotent — if the
	 * exchange row is already at exchange-completed it does nothing.
	 *
	 * @param int $order_id WC order id that just transitioned to "completed".
	 */
	public function on_wc_child_order_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Only exchange child orders carry _parent_order_id meta. Regular
		// parent/standalone orders are skipped.
		$parent_order_id = $order->get_meta( '_parent_order_id' );
		if ( empty( $parent_order_id ) ) {
			return;
		}

		// Find the exchange request that produced this child order. The exchange
		// row stores the parent order id (not the child id), so look it up that way.
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}exchange_orders WHERE order_id = %d ORDER BY id DESC LIMIT 1",
				intval( $parent_order_id )
			)
		);

		if ( ! $row ) {
			return;
		}

		// Idempotency — Returns Dashboard path may already have completed it,
		// or this hook may fire twice during the same status transition.
		if ( 'exchange-completed' === $row->exchange_status ) {
			return;
		}

		// Transition the exchange row.
		$wpdb->update(
			$wpdb->prefix . 'exchange_orders',
			array( 'exchange_status' => 'exchange-completed' ),
			array( 'id' => intval( $row->id ) )
		);

		// Fire the same admin + customer emails the dashboard path fires.
		$this->send_exchange_admin_email_func( $row->id, $row->order_id, 'exchange-completed' );
		$this->send_exchange_email_func( $row->id, $row->order_id, 'exchange-completed' );

		// Generate and email the store-credit coupon if applicable
		// (cross-product, pay-less). handle_completion() is idempotent.
		$fresh_row = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $row->id );
		if ( $fresh_row && class_exists( 'Exchange_Price_Handler' ) ) {
			Exchange_Price_Handler::get_instance()->handle_completion( $fresh_row );
		}
	}

	/** Add exchange endpoint content
	 *
	 * @since  1.0.0
	 */
	public function exchange_endpoint_content() {
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		$itemid  = isset($_GET['item_id']) ? sanitize_text_field( wp_unslash( $_GET['item_id'] ) ) : '';
		$order = $order_id ? wc_get_order($order_id) : false;
		if ( ! $order ) {
			esc_html_e( 'Invalid order.', 'zorem-returns' );
			return;
		}
		$exchange_form_data    = zorem_returns_exchanges()->exCommon->get_exchanged_form_data( $order );
		$exchange_entire_order = zorem_returns_exchanges()->common->return_settings( 'exchange_entire_order', 'no' );
		$exchange_datas        = zorem_returns_exchanges()->exCommon->get_exchange_data_by_order_id( $order_id );
		// Condition 1: Show form for item-level exchange
		if ( '' !== $itemid && array_key_exists( $itemid, (array) $exchange_form_data ) ) {
			include dirname( __FILE__ ) . '/htmls/exchanges-center-form.php';
			return;
		}
		// Condition 2: Show form for entire order exchange
		if ( '' === $itemid && ! empty( $exchange_form_data ) ) {
			include dirname( __FILE__ ) . '/htmls/exchanges-center-form.php';
			return;
		}
		// Condition 3: Show already requested exchange data
		if ( ( ! empty( $itemid ) || ! empty( $order_id ) ) && ! empty( $exchange_datas ) ) {
			foreach ( $exchange_datas as $request_data ) {
				$exchange_items   = isset( $request_data->exchange_items ) ? maybe_unserialize( $request_data->exchange_items ) : array();
				$exchanged_item_id = key( (array) $exchange_items );
				$request_id        = ! empty( $request_data->id ) ? absint( $request_data->id ) : 0;
				$heading = ( 'no' === $exchange_entire_order ) ? __( 'Exchange Item', 'zorem-returns' ) : __( 'Exchange Entire Order', 'zorem-returns' );
				?>
				<form id="exchange-multi-step-form">
					<div class="multi-form-container">
						<h2 class="form-heading" style="display: flex; flex-direction: column;">
							<span style="font-size: 14px; margin-bottom: 5px;">
								<strong style="color: #008BC5;">
									<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'view-order' ) . $order_id ); ?>" style="color: #008BC5;">
										<?php esc_html_e( 'View Order', 'zorem-returns' ); ?>
									</a> >
								</strong>
								<?php echo esc_html($heading); ?>
							</span>
							<?php echo esc_html($heading); ?>
						</h2>
						<div class="form-content">
							<?php
							$display_data = zorem_returns_exchanges()->exCommon->display_exchange_data_request_id( $request_id );
							echo wp_kses_post( $display_data ? $display_data : '' );
							?>
						</div>
					</div>
				</form>
				<?php
			}
			return;
		}
		// Fallback: No valid data found
		esc_html_e( 'This item is not available for exchange.', 'zorem-returns' );
	}

	/**
	 * Front-end exchange request button
	 *
	 * @since  1.0.0
	 */
	public function add_exchange_request_button_after_order_table( $order ) {
		
		$parent_order_id = $order ? $order->get_meta( '_parent_order_id' ) : '';
		if (!zorem_returns_exchanges()->common->is_order_valid_for_return($order) || !empty($parent_order_id)) {
			return;
		}

		// Block exchanges by payment method (e.g., COD).
		if ( zorem_returns_exchanges()->exCommon->is_payment_method_blocked_for_exchange( $order ) ) {

			// If returns are ALSO blocked, the return handler has already rendered a combined notice.
			// Skip outputting the exchange-specific notice here to avoid duplicate messaging.
			if ( zorem_returns_exchanges()->common->is_payment_method_blocked_for_return( $order ) ) {
				return;
			}

			$message = zorem_returns_exchanges()->exCommon->get_exchange_blocked_message( $order );
			echo '<div class="zorem-rne-notice-row"><div class="zorem-exchange-blocked-notice">' . esc_html( $message ) . '</div></div>';
			return;
		}
		
		// Admin setting: hide the whole-order "Start an Exchange" button entirely
		// (per-item Exchange Item buttons are unaffected). Enabled by default.
		if ( 'no' === zorem_returns_exchanges()->common->return_settings( 'show_start_exchange_button', 'yes' ) ) {
			return;
		}

		$exchange_form_data = zorem_returns_exchanges()->exCommon->get_exchanged_form_data($order);
		if (empty($exchange_form_data)) {
			return;
		}

		// "Start an Exchange" opens the whole-order exchange form, where the
		// customer can select several items and (with cross-product enabled)
		// pick a separate replacement product for each one. Shown whenever at
		// least one item is exchangeable — multi-item orders especially need it.

		$variation_found = false;
		foreach ($order->get_items() as $item_id => $item) {

			if (!zorem_returns_exchanges()->exCommon->is_item_valid_for_exchange($item)) {
				continue;
			}

			$available_variations = zorem_returns_exchanges()->exCommon->is_item_price_match_variation($item);
			if ( $available_variations ) {
				$variation_found = true;
				break;
			}
		}

		if (!$variation_found) {
			return;
		}

		// Get order ID
		$order_id = $order->get_id();

		$exchange_button_html = '<div class="zorem-rne-action-row"><a href="' . esc_url( wc_get_account_endpoint_url('exchange') . '?order_id=' . $order_id ) . '"><button class="exchange_request_button button" type="button" data-order-id="' . esc_html( $order_id ) . '"> ' . esc_html__( 'Start an Exchange', 'zorem-returns' ) . '</button></a></div>';

		// Output the custom buttons
		echo wp_kses_post($exchange_button_html);
		
	}

	/** Add request button after product title in order details
	 *
	 * @since  1.0.0
	 */
	public function add_exchange_request_button_after_product_title( $item_id, $item, $order ) {

		if ( !is_wc_endpoint_url( 'view-order' ) ) {
			return;
		}

		$parent_order_id = $order ? $order->get_meta( '_parent_order_id' ) : '';
		if (!zorem_returns_exchanges()->common->is_order_valid_for_return($order) || !empty($parent_order_id)) {
			return;
		}

		// Silently hide per-line Exchange button when payment method is blocked.
		if ( zorem_returns_exchanges()->exCommon->is_payment_method_blocked_for_exchange( $order ) ) {
			return;
		}

		if (!zorem_returns_exchanges()->exCommon->is_item_valid_for_exchange($item)) {
			return;
		}
		
		$exchange_form_data = zorem_returns_exchanges()->exCommon->get_exchanged_form_data($order);
		if (empty($exchange_form_data)) {
			return;
		}
		
		$available_variations = zorem_returns_exchanges()->exCommon->is_item_price_match_variation($item);
		$exchange_button_html = '';
		if ( $item->get_type() === 'line_item' && array_key_exists($item_id, $exchange_form_data) && $available_variations ) {
			
			$exchange_button_html = '<span class="zorem-rne-line-button"><a href="' . esc_url( wc_get_account_endpoint_url('exchange') . '?order_id=' . $order->get_id() . '&item_id=' . $item_id ) . '"><button class="exchange_request_button button" type="button" data-item-id="' . esc_html( $item_id ) . '" data-order-id="' . esc_html( $order->get_id() ) . '"> ' . esc_html__( 'Exchange Item', 'zorem-returns' ) . '</button></a></span>';
	
		}


		echo wp_kses_post($exchange_button_html);
	}

	/** Create a exchange order from exchange request id
	 *
	 * @since  1.0.0
	 */
	public function create_exchange_order( $request_id ) {
		$exchange_data = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $request_id );
		if ( empty( $exchange_data ) ) {
			return;
		}

		$exchange_items  = ( null != $exchange_data->exchange_items ) ? unserialize( $exchange_data->exchange_items ) : array();
		$exchange_type   = isset( $exchange_data->exchange_type ) ? $exchange_data->exchange_type : 'same-variation';
		$parent_order_id = $exchange_data->order_id;
		$parent_order    = wc_get_order( $parent_order_id );

		if ( empty( $exchange_items ) ) {
			return;
		}

		// ── Cross-product exchange ────────────────────────────────────────────
		if ( 'cross-product' === $exchange_type ) {

			// Per-item targets (admin multi-item exchange): each exchange item
			// carries its own replacement product — ALL of them go into ONE child
			// order. Legacy rows (customer flow) store a single row-level target.
			$per_item_targets = array();
			foreach ( $exchange_items as $iid => $item_data ) {
				if ( ! is_array( $item_data ) || empty( $item_data['new_product_id'] ) ) {
					continue;
				}
				$t_id       = ! empty( $item_data['new_variation_id'] ) ? intval( $item_data['new_variation_id'] ) : intval( $item_data['new_product_id'] );
				$t_product  = wc_get_product( $t_id );
				if ( ! $t_product ) {
					continue;
				}
				$per_item_targets[ $iid ] = array(
					'product'        => $t_product,
					'qty'            => isset( $item_data['exchange_product_qty'] ) ? max( 1, intval( $item_data['exchange_product_qty'] ) ) : 1,
					'cross_variants' => ( ! empty( $item_data['cross_variants'] ) && is_array( $item_data['cross_variants'] ) ) ? $item_data['cross_variants'] : array(),
				);
			}

			// Legacy single-target validation (only when no per-item targets exist).
			$product = null;
			$qty     = 1;
			if ( empty( $per_item_targets ) ) {
				$new_product_id   = isset( $exchange_data->new_product_id )   ? intval( $exchange_data->new_product_id )   : 0;
				$new_variation_id = isset( $exchange_data->new_variation_id ) ? intval( $exchange_data->new_variation_id ) : 0;

				if ( ! $new_product_id ) {
					return;
				}

				$target_id = ! empty( $new_variation_id ) ? $new_variation_id : $new_product_id;
				$product   = wc_get_product( $target_id );
				if ( ! $product ) {
					return;
				}

				// Qty = first item in exchange_items array
				foreach ( $exchange_items as $item_data ) {
					if ( isset( $item_data['exchange_product_qty'] ) ) {
						$qty = max( 1, intval( $item_data['exchange_product_qty'] ) );
					}
					break;
				}
			}

			$order = wc_create_order();

			if ( $parent_order ) {
				$order->set_customer_id( $parent_order->get_customer_id() );
				$order->set_address( $parent_order->get_address( 'billing' ), 'billing' );
				$order->set_address( $parent_order->get_address( 'shipping' ), 'shipping' );
			}

			if ( ! empty( $per_item_targets ) ) {
				// One line per exchanged item, each with its own replacement product.
				foreach ( $per_item_targets as $target ) {
					$order_item_id = $order->add_product( $target['product'], $target['qty'] );

					// For replacement variations saved with "Any …" attributes, stamp
					// the chosen values onto the order item so fulfilment knows
					// exactly what to ship (e.g. taglia: 38).
					if ( $order_item_id && ! empty( $target['cross_variants'] ) && $target['product']->is_type( 'variation' ) ) {
						foreach ( $target['product']->get_variation_attributes() as $attr_key => $attr_val ) {
							if ( ( '' === $attr_val || null === $attr_val ) && ! empty( $target['cross_variants'][ $attr_key ] ) ) {
								wc_update_order_item_meta( $order_item_id, str_replace( 'attribute_', '', $attr_key ), $target['cross_variants'][ $attr_key ] );
							}
						}
					}
				}
			} else {
				$order_item_id = $order->add_product( $product, $qty );

				// For replacement variations saved with "Any …" attributes, stamp
				// the customer's chosen values onto the order item so fulfilment
				// knows exactly what to ship (e.g. taglia: 38).
				$chosen_attrs = zorem_returns_exchanges()->exCommon->get_stored_cross_variants( $exchange_items );
				if ( $order_item_id && ! empty( $chosen_attrs ) && $product->is_type( 'variation' ) ) {
					foreach ( $product->get_variation_attributes() as $attr_key => $attr_val ) {
						if ( ( '' === $attr_val || null === $attr_val ) && ! empty( $chosen_attrs[ $attr_key ] ) ) {
							wc_update_order_item_meta( $order_item_id, str_replace( 'attribute_', '', $attr_key ), $chosen_attrs[ $attr_key ] );
						}
					}
				}
			}
			zorem_returns_exchanges()->exCommon->zre_apply_preset_shipping_to_exchange_order( $order );

			/**
			 * Fires on the exchange child order after the replacement items and
			 * preset shipping are added, and BEFORE the final calculate_totals( true )
			 * that applies fees + tax and locks the total.
			 *
			 * Lets a developer attach a custom taxable fee (e.g. a fixed two-way
			 * shipping fee for size exchanges) that is totaled and taxed by the same
			 * recalculation as the plugin's own fee lines. Set the fee NET so that
			 * net + recomputed tax equals the intended inc-tax amount, e.g. for
			 * 100 inc tax at a 20% rate:
			 *
			 *     add_action( 'rwc_exchange_child_order_before_calculate_totals', function ( $order, $exchange_data ) {
			 *         $fee = new WC_Order_Item_Fee();
			 *         $fee->set_name( 'Exchange Shipping' );
			 *         $fee->set_amount( 100 / 1.20 ); // net, WC re-adds tax on recalc
			 *         $fee->set_total( 100 / 1.20 );
			 *         $fee->set_tax_status( 'taxable' );
			 *         $fee->set_tax_class( '' );      // standard rate
			 *         $order->add_item( $fee );
			 *     }, 10, 2 );
			 *
			 * Fired once per child order (for every exchange type). On pay-more
			 * exchanges the price handler's later calculate_totals( true ) re-totals
			 * from the same item properties, so the fee is neither lost nor doubled.
			 *
			 * @param WC_Order $order         Exchange child order (items + shipping added, not yet saved).
			 * @param object   $exchange_data Exchange request row from {prefix}exchange_orders.
			 */
			do_action( 'rwc_exchange_child_order_before_calculate_totals', $order, $exchange_data );

			// Apply the parent order's coupon discount to each replacement line so
			// the child order charges the discounted price (e.g. 269.10 instead of
			// 299), matching Exchange_Price_Handler::recalculate_price_difference().
			// Eligibility (sale / category exclusions) is enforced inside
			// zre_replacement_unit_discount(); ineligible lines are left untouched.
			if ( $parent_order ) {
				foreach ( $order->get_items() as $line_item ) {
					$line_product = $line_item->get_product();
					if ( ! $line_product ) {
						continue;
					}
					$unit_price = (float) wc_get_price_including_tax( $line_product );
					if ( $unit_price <= 0 ) {
						continue;
					}
					$unit_discount = zorem_returns_exchanges()->exCommon->zre_replacement_unit_discount( $line_product, $parent_order );
					if ( $unit_discount <= 0 ) {
						continue;
					}
					$rate     = $unit_discount / $unit_price;           // e.g. 0.10
					$subtotal = (float) $line_item->get_subtotal();     // net regular line (pre-discount)
					$line_item->set_total( $subtotal * ( 1 - $rate ) ); // net discounted; tax recomputed below
				}
			}

			$order->calculate_totals();

			// Custom fees added via the hook above must survive the $0 baseline:
			// their inc-tax sum becomes the child order's starting total.
			$custom_fee_gross = 0;
			foreach ( $order->get_fees() as $fee_item ) {
				$custom_fee_gross += floatval( $fee_item->get_total() ) + floatval( $fee_item->get_total_tax() );
			}

			// Default $0 (+ custom fees) — price handler adjusts if a difference exists
			$order->set_total( max( 0, round( $custom_fee_gross, wc_get_price_decimals() ) ) );
			$order->set_total( $order->get_shipping_total(), 'shipping' );

			// Predict whether the customer actually owes anything BEFORE we save
			// the order — $order->save() below triggers WC's customer-processing
			// email, which shows the payment method. For even / store-credit
			// exchanges nothing is owed, so showing the parent's payment method
			// (e.g. "Direct bank transfer") is misleading. For pay-more we keep
			// the parent's method since the customer needs it to pay the balance.
			$predicted_difference = Exchange_Price_Handler::get_instance()->recalculate_price_difference( $exchange_data );
			// A custom fee added via the hook also means the customer owes a balance.
			$payment_required     = ( $predicted_difference > 0.5 ) || ( $custom_fee_gross > 0.5 );

			if ( $parent_order ) {
				if ( $payment_required ) {
					$order->set_payment_method( $parent_order->get_payment_method() );
					$order->set_payment_method_title( $parent_order->get_payment_method_title() );
				} else {
					$order->set_payment_method( '' );
					$order->set_payment_method_title( __( 'No payment required (Exchange)', 'zorem-returns' ) );
				}
			}

			// Parent-child links
			$order->update_meta_data( '_parent_order_id', $parent_order_id );
			$order->update_meta_data( '_zre_exchange_type', 'cross-product' );
			if ( $parent_order ) {
				$parent_order->update_meta_data( '_child_order_id', $order->get_id() );
				$parent_order->save();
			}

			if ( 'exchange-approved' == $exchange_data->exchange_status ) {
				$status = apply_filters( 'rwc_exchange_child_order_status', 'processing', $exchange_data, $order );
				$order->set_status( $status );
			}
			$order->save();

			// Delegate to price handler (pay-more / store-credit / even)
			Exchange_Price_Handler::get_instance()->handle( $order, $exchange_data );

			return;
		}

		// ── Same-variation exchange (unchanged existing behaviour) ────────────
		$order = wc_create_order();

		foreach ( $exchange_items as $item_id => $exchange_item ) {
			$product_id   = wc_get_order_item_meta( $item_id, '_product_id', true );
			$attributes   = isset( $exchange_items[ $item_id ]['variants'] ) ? $exchange_items[ $item_id ]['variants'] : array();
			$variation_id = zorem_returns_exchanges()->exCommon->get_variation_id_by_attributes( $product_id, $attributes );

			if ( empty( $variation_id ) ) {
				continue;
			}

			$variation = wc_get_product( $variation_id );
			if ( ! is_object( $variation ) ) {
				continue;
			}

			$qty           = isset( $exchange_items[ $item_id ]['exchange_product_qty'] ) ? $exchange_items[ $item_id ]['exchange_product_qty'] : 0;
			$order_item_id = $order->add_product( $variation, $qty );

			if ( $parent_order ) {
				foreach ( $parent_order->get_items() as $parent_item ) {
					if ( $parent_item->get_product_id() == $variation->get_id() ) {
						$item = $order->get_item( $order_item_id );
						$item->set_subtotal( $parent_item->get_subtotal() );
						$item->set_total( $parent_item->get_total() );
						$item->set_taxes( $parent_item->get_taxes() );
						$item->save();
					}
				}
			}
		}

		if ( $parent_order ) {
			$order->set_customer_id( $parent_order->get_customer_id() );
			$order->set_address( $parent_order->get_address( 'billing' ), 'billing' );
			$order->set_address( $parent_order->get_address( 'shipping' ), 'shipping' );
		}

		zorem_returns_exchanges()->exCommon->zre_apply_preset_shipping_to_exchange_order( $order );

		/**
		 * Same hook as the cross-product path — see the docblock there.
		 * Fires after items + preset shipping, before totals are calculated and
		 * the $0 even-swap baseline is applied, so a custom taxable fee added
		 * here is taxed by calculate_totals() and lands in the order total.
		 *
		 * @param WC_Order $order         Exchange child order (items + shipping added, not yet saved).
		 * @param object   $exchange_data Exchange request row from {prefix}exchange_orders.
		 */
		do_action( 'rwc_exchange_child_order_before_calculate_totals', $order, $exchange_data );

		$order->calculate_totals();

		// Custom fees added via the hook above must survive the $0 even-swap
		// baseline: their inc-tax sum becomes the child order's total.
		$custom_fee_gross = 0;
		foreach ( $order->get_fees() as $fee_item ) {
			$custom_fee_gross += floatval( $fee_item->get_total() ) + floatval( $fee_item->get_total_tax() );
		}

		$order->set_total( max( 0, round( $custom_fee_gross, wc_get_price_decimals() ) ) );
		$order->set_total( 0, 'paid' );
		$order->set_total( $order->get_shipping_total(), 'shipping' );

		if ( $custom_fee_gross > 0.5 && $parent_order ) {
			// A custom fee (added via the hook) is owed — keep the parent's payment
			// method so the customer can settle the balance from the order-pay page.
			$order->set_payment_method( $parent_order->get_payment_method() );
			$order->set_payment_method_title( $parent_order->get_payment_method_title() );
		} else {
			// Same-variation exchange: total is always $0 (even swap), so display a
			// clear "no payment" label in the customer-processing email instead of
			// the blank / inherited payment method.
			$order->set_payment_method( '' );
			$order->set_payment_method_title( __( 'No payment required (Exchange)', 'zorem-returns' ) );
		}

		$order->update_meta_data( '_parent_order_id', $parent_order_id );
		if ( $parent_order ) {
			$parent_order->update_meta_data( '_child_order_id', $order->get_id() );
			$parent_order->save();
		}

		if ( 'exchange-approved' == $exchange_data->exchange_status ) {
			$status = apply_filters( 'rwc_exchange_child_order_status', 'processing', $exchange_data, $order );
			$order->set_status( $status );
		}
		$order->save();
	}


	/** Change woocommerce exchange order number
	 *
	 * Prepend "parent → " to a child exchange order's displayed number. Uses the
	 * customer-facing order numbers from both orders so plugins that remap raw
	 * post IDs (e.g. Sequential Order Numbers) are respected on both sides.
	 *
	 * @since      1.0.0
	 * @deprecated 1.6.7 No longer hooked to woocommerce_order_number. The "→"
	 *             (U+2192) broke third-party integrations that use
	 *             get_order_number() as the canonical invoice number (e.g. Xero
	 *             ValidationException ErrorNumber 10 — invalid character in
	 *             Invoice number field). Kept only for back-compat in case a
	 *             merchant snippet hooks it deliberately.
	 *
	 * @param string        $formatted_number Already-filtered number for $order (customer-facing).
	 * @param WC_Order|null $order            Order object passed by the woocommerce_order_number filter.
	 * @return string
	 */
	public function change_woocommerce_order_number( $formatted_number, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			return $formatted_number;
		}

		$parent_order_id = $order->get_meta( '_parent_order_id' );
		if ( empty( $parent_order_id ) ) {
			return $formatted_number;
		}

		$parent_order = wc_get_order( $parent_order_id );
		if ( ! $parent_order ) {
			return $formatted_number;
		}

		// get_order_number() re-applies the woocommerce_order_number filter so
		// the parent's number also goes through Sequential Order Numbers etc.
		// No infinite recursion: the parent has no _parent_order_id of its own.
		return $parent_order->get_order_number() . ' → ' . $formatted_number;
	}

	/**
	 * Send exchange mail.
	 * This method will create a object and call trigger method.
	 *
	 * @since  1.0.0
	 */
	public function send_exchange_email_func( $request_id, $order_id, $status ) {

		if ( empty($request_id) ) {
			return;
		}

		/**
		 * Fires when an exchange status changes, before the email enabled check.
		 * Allows third-party plugins (e.g. SMS for WooCommerce) to send
		 * notifications independently of the email toggle.
		 *
		 * @param int    $request_id Exchange request ID.
		 * @param int    $order_id   WooCommerce order ID.
		 * @param string $status     New exchange status slug, e.g. 'exchange-completed'.
		 */
		do_action( 'zorem_return_status_changed', $request_id, $order_id, $status );

		$order = wc_get_order($order_id);
		$mailer = WC()->mailer();
		$email = new WC_Email();

		$Status = strtolower(str_replace('-', '_', $status));

		$enabled = get_option('customer_' . $Status . '_status', 'yes');
		if ( 'no' == $enabled ) {
			return;
		}

		// WPML: render the email in the language the order was placed in —
		// translated option strings AND gettext defaults. Restored after send.
		zorem_returns_exchanges()->wpml->switch_to_order_language( $order );

		$defualt_settings = zorem_returns_exchanges()->exCommon->generate_defaults();
		$email_settings = get_option('ex4wc_customer_' . $Status . '_settings', $defualt_settings['customer_' . $Status]);

		if ('exchange-requested' == $status) {
			$subject = isset($email_settings['subject']) ? $email_settings['subject'] : '';
			$email_heading = isset($email_settings['heading']) ? $email_settings['heading'] : '';
		} elseif ('exchange-approved' == $status) {
			$subject = isset($email_settings['subject']) ? $email_settings['subject'] : '';
			$email_heading = isset($email_settings['heading']) ? $email_settings['heading'] : '';
		} elseif ('exchange-rejected' == $status) {
			$subject = isset($email_settings['subject']) ? $email_settings['subject'] : '';
			$email_heading = isset($email_settings['heading']) ? $email_settings['heading'] : '';
		} elseif ('exchange-completed' == $status) {
			$subject = isset($email_settings['subject']) ? $email_settings['subject'] : '';
			$email_heading = isset($email_settings['heading']) ? $email_settings['heading'] : '';
		}

		$customer = new WC_Customer($order->get_customer_id());
		$email_heading = str_replace(array('{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}'), array(get_bloginfo('name'), $order->get_order_number(), wc_format_datetime($order->get_date_created()), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username()), $email_heading);
		$subject = str_replace(array('{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}'), array(get_bloginfo('name'), $order->get_order_number(), wc_format_datetime($order->get_date_created()), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username()), $subject);

		$message = $this->exchange_customer_email_content($request_id, $order, $email, $email_settings, $status);

		// create a new email
		$message = apply_filters('woocommerce_mail_content', $email->style_inline($mailer->wrap_message($email_heading, $message)));
		add_filter('wp_mail_from', array($this, 'get_from_address'));
		add_filter('wp_mail_from_name', array($this, 'get_from_name'));

		$recipient = $order->get_billing_email();
		if ($recipient) {
			$attachments = array();

			// Attach return shipping label PDF for approved exchanges
			if ( 'exchange-approved' == $status ) {
				$exchange_data = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $request_id );
				if ( ! empty( $exchange_data->exchange_label_pdf ) && file_exists( $exchange_data->exchange_label_pdf ) ) {
					$attachments[] = $exchange_data->exchange_label_pdf;
				}
			}

			wp_mail($recipient, $subject, $message, $email->get_headers(), $attachments);
		}

		zorem_returns_exchanges()->wpml->restore_language();
	}

	/**
	 * Send exchange mail for admin.
	 * This method will create a object and call trigger method.
	 *
	 * @since  1.0.0
	 */
	public function send_exchange_admin_email_func( $request_id, $order_id, $status ) {

		if ( empty($request_id) ) {
			return;
		}

		$order = wc_get_order($order_id);
		$mailer = WC()->mailer();
		$email = new WC_Email();

		// All admin-side exchange emails. `cancelled` keeps its existing template/recipient-filter
		// behaviour for backwards compatibility, but is not exposed in the Notifications UI.
		$valid = array( 'exchange-requested', 'exchange-cancelled', 'exchange-approved', 'exchange-completed', 'exchange-rejected' );
		if ( ! in_array( $status, $valid, true ) ) {
			return;
		}

		$Status = strtolower(str_replace('-', '_', $status));

		// Per-email enable/disable toggle. Defaults to "yes" so existing installs keep firing.
		// `cancelled` is intentionally NOT toggle-gated — it has no UI surface.
		if ( 'exchange-cancelled' !== $status ) {
			$enabled = get_option( 'admin_' . $Status . '_status', 'yes' );
			if ( 'no' === $enabled ) {
				return;
			}
		}

		// WPML: admin emails always render in the site's default language, so they
		// don't inherit the customer's front-end language. Restored after send.
		zorem_returns_exchanges()->wpml->switch_to_site_language();

		$defualt_settings = zorem_returns_exchanges()->exCommon->generate_defaults();
		$default_for_key  = isset( $defualt_settings[ 'admin_' . $Status ] ) ? $defualt_settings[ 'admin_' . $Status ] : array();
		$email_settings   = get_option('ex4wc_admin_' . $Status . '_settings', $default_for_key );

		$subject = isset($email_settings['subject']) ? $email_settings['subject'] : '';
		$email_heading = isset($email_settings['heading']) ? $email_settings['heading'] : '';
		$message = $this->exchange_admin_email_content($request_id, $order, $email, $email_settings, $status);

		$customer = new WC_Customer($order->get_customer_id());
		$email_heading = str_replace(array('{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}'), array(get_bloginfo('name'), $order->get_order_number(), wc_format_datetime($order->get_date_created()), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username()), $email_heading);
		$subject = str_replace(array('{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}'), array(get_bloginfo('name'), $order->get_order_number(), wc_format_datetime($order->get_date_created()), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username()), $subject);

		// create a new email
		$message = apply_filters('woocommerce_mail_content', $email->style_inline($mailer->wrap_message($email_heading, $message)));
		add_filter('wp_mail_from', array($this, 'get_from_address'));
		add_filter('wp_mail_from_name', array($this, 'get_from_name'));

		// Vendor/brand snippets historically hook `rwc_exchange_request_recipient_email`. Keep
		// that filter ONLY for the initial request + cancellation. Status-change emails
		// (approved/completed/rejected) use a separate filter so vendors don't get them.
		// `$status` is passed as the third arg so snippets can gate vendor emails to
		// `exchange-requested` only (skipping `exchange-cancelled`).
		if ( 'exchange-requested' === $status || 'exchange-cancelled' === $status ) {
			$recipient = apply_filters( 'rwc_exchange_request_recipient_email', get_option('admin_email'), $order_id, $status );
		} else {
			$recipient = apply_filters( 'rwc_exchange_status_change_recipient_email', get_option('admin_email'), $order_id, $status );
		}

		if ( $recipient ) {
			wp_mail( $recipient, $subject, $message, $email->get_headers() );
		}

		zorem_returns_exchanges()->wpml->restore_language();
	}

	/**
	 * Get exchange requested email content data
	 *
	 * @since  1.0.0
	 */
	public function exchange_customer_email_content( $request_id, $order, $email, $email_settings, $status ) {

		ob_start();

		$local_template	= get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/emails/customer-' . $status . '.php';
		if ( file_exists( $local_template ) && wp_is_writable( $local_template )) {
			$directory_path = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/';
		} else {
			$directory_path = zorem_returns_exchanges()->get_plugin_path() . '/templates/';
		}

		wc_get_template(
			'emails/customer-' . $status . '.php',
			array(
				'request_id' => $request_id,
				'order' => $order,
				'email' => $email,
				'email_settings' => $email_settings,
			),
			'zorem-returns-exchanges/',
			$directory_path
		);

		$message = ob_get_clean();
		return $message;

	}

	/**
	 * Get exchange requested email content data(for admin)
	 * 
	 * @since  1.0.0
	 */
	public function exchange_admin_email_content( $request_id, $order, $email, $email_settings, $status ) {

		ob_start();

		$local_template	= get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/emails/admin-' . $status . '.php';
		if ( file_exists( $local_template ) && wp_is_writable( $local_template )) {
			$directory_path = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/';
		} else {
			$directory_path = zorem_returns_exchanges()->get_plugin_path() . '/templates/';
		}

		wc_get_template(
			'emails/admin-' . $status . '.php',
			array(
				'request_id' => $request_id,
				'order' => $order,
				'email' => $email,
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
		$from_name = apply_filters('woocommerce_email_from_name', get_option('woocommerce_email_from_name'), $this);
		return wp_specialchars_decode(esc_html($from_name), ENT_QUOTES);
	}

	/**
	 * Get the from address for outgoing emails.
	 *
	 * @return string
	 */
	public function get_from_address() {
		$from_address = apply_filters('woocommerce_email_from_address', get_option('woocommerce_email_from_address'), $this);
		return sanitize_email($from_address);
	}

	/**
	 * Secure download handler for exchange shipping label PDF.
	 */
	public function download_exchange_label() {
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'rt4wc_download_label' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'zorem-returns' ), 403 );
		}

		$request_id = isset( $_GET['request_id'] ) ? absint( $_GET['request_id'] ) : 0;
		if ( ! $request_id ) {
			wp_die( esc_html__( 'Invalid request.', 'zorem-returns' ), 400 );
		}

		$exchange_data = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $request_id );
		if ( empty( $exchange_data ) || empty( $exchange_data->exchange_label_pdf ) ) {
			wp_die( esc_html__( 'No return label found.', 'zorem-returns' ), 404 );
		}

		// Permission check
		$current_user_id = get_current_user_id();
		$order           = wc_get_order( $exchange_data->order_id );

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
		$raw_name = basename( (string) $exchange_data->exchange_label_pdf );

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
		header( 'Content-Disposition: attachment; filename="return-shipping-label-exchange-' . absint( $request_id ) . '.pdf"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// nosemgrep: audit.php.lang.security.file.read-write-delete
		readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

}
