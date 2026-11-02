<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.DynamicHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

class Return_Common_Functions {

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
		// Email
		add_action( 'woocommerce_return_email_order_details', array( $this, 'return_email_detail' ), 10, 3 );
		add_action( 'woocommerce_customer_return_email_order_details', array( $this, 'return_email_detail' ), 10, 3 );
		add_action( 'woocommerce_return_plain_email_order_details', array( $this, 'return_email_detail' ), 10, 3 );

		// Add 'Return Item' meta-box on Order page
		add_action( 'add_meta_boxes', array( $this, 'add_order_meta_box' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'store_return_window_start_date' ), 10, 4 );
	}

	/**
	 * Add 'Return Item' meta-box to Orders page
	 *
	 * @since 1.0
	 */
	public function add_order_meta_box() {
		if ( class_exists( 'Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			$screen = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		} else {
			$screen = 'shop_order';
		}
		add_meta_box( 'return_order_meta_box', __( 'Return Requests', 'zorem-returns' ), array( $this, 'display_return_request_meta_box' ), $screen, 'side', 'default' );
	}

	/**
	 * Display Order Meta box in admin order
	 *
	 * @since  1.0.0
	 */
	public function display_return_request_meta_box( $post_or_order_object ) {

		$order    = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;
		$order_id = $order->get_id();

		$return_datas = zorem_returns_exchanges()->common->get_data_by_order_id( $order_id );

		// Items with returnable qty remaining — drives the admin "Create Return Request" button.
		$returnable_items = $this->get_admin_returnable_items( $order, $return_datas );

		if ( ! $return_datas && empty( $returnable_items ) ) {
			echo '<style>#return_order_meta_box{display:none;}</style>';
			return;
		}

		wp_enqueue_script(
			'rt4wc_order_script',
			zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/order.js',
			array( 'jquery' ),
			zorem_returns_exchanges()->version,
			true
		);

		// Admin-initiated return: support agents can file a return on the customer's behalf.
		if ( ! empty( $returnable_items ) ) {
			$this->render_admin_create_return_ui( $order, $returnable_items );
		}

		if ( ! $return_datas ) {
			return;
		}

		$count = count( $return_datas );
		$i     = 1;
		?>
		<input type="hidden" id="nonce_fullfillment_dashbaord" value="<?php echo esc_html( wp_create_nonce( 'nonce_fullfillment_dashbaord' ) ); ?>">

		<div class="return_request_item">
			<?php
			foreach ( (array) $return_datas as $return_data ) {
				$return_reason = ( null != $return_data->return_reason ) ? unserialize( $return_data->return_reason ) : array();
				$no_other      = isset( $return_reason['no_other'] ) ? $return_reason['no_other'] : '';
				$other_reason  = isset( $return_reason['other_reason'] ) ? $return_reason['other_reason'] : '';
				$return_issue  = isset( $return_data->return_issue ) ? $return_data->return_issue : '';
				$return_note   = isset( $return_data->return_note ) ? $return_data->return_note : '';
				$return_status = isset( $return_data->return_status ) ? $return_data->return_status : '';

				if ( $count > 1 ) {
					echo '<h3>' . esc_html__( 'Return Request ', 'zorem-returns' ) . '#' . esc_html( $i ) . '</h3>';
					++$i;
				}

				$return_items = ( null != $return_data->return_items ) ? unserialize( $return_data->return_items ) : array();

				$items = array();
				foreach ( $order->get_items() as $item_id => $item ) {
					if ( ! isset( $return_items[ $item_id ] ) || empty( $return_items[ $item_id ]['return_product_qty'] ) ) {
						continue;
					}

					$product_id = $item->get_product_id();
					$product    = wc_get_product( $product_id );
					if ( ! is_object( $product ) ) {
						continue;
					}

					$return_qty    = (int) $return_items[ $item_id ]['return_product_qty'];
					// Amount actually paid per unit (after discount, incl tax) — matches the refund.
					$item_qty      = $item->get_quantity();
					$line_gross    = (float) $item->get_total() + (float) $item->get_total_tax();
					$product_price = $item_qty > 0 ? ( $line_gross / $item_qty ) : $line_gross;
					$item_all_meta = $item->get_all_formatted_meta_data();

					$return_variation = '';
					foreach ( $item_all_meta as $key => $meta ) {
						$display_key       = $meta->display_key;
						$display_value     = wp_strip_all_tags( $meta->display_value );
						$return_variation .= wp_kses_post( $display_key . ' : ' . $display_value );
						$keys              = array_keys( $item_all_meta );
						$return_variation .= ( end( $keys ) !== $key ) ? ' | ' : '';
					}

					// Fallback: get variation attributes directly from the variation product
					if ( empty( $return_variation ) && $item->get_variation_id() ) {
						$variation_product = wc_get_product( $item->get_variation_id() );
						if ( is_object( $variation_product ) && $variation_product->is_type( 'variation' ) ) {
							$variation_attributes = $variation_product->get_variation_attributes();
							$parts                = array();
							foreach ( $variation_attributes as $attr_key => $attr_value ) {
								$attr_slug  = str_replace( 'attribute_', '', $attr_key );
								$attr_label = wc_attribute_label( $attr_slug, $product );
								if ( taxonomy_exists( $attr_slug ) ) {
									$term = get_term_by( 'slug', $attr_value, $attr_slug );
									if ( $term && ! is_wp_error( $term ) ) {
										$attr_value = $term->name;
									}
								}
								if ( ! empty( $attr_value ) ) {
									$parts[] = wp_kses_post( $attr_label . ' : ' . ucfirst( $attr_value ) );
								}
							}
							$return_variation = implode( ' | ', $parts );
						}
					}

					$items[] = array(
						'name'      => $product->get_name(),
						'variation' => ! empty( $return_variation ) ? $return_variation : '',
						'quantity'  => $return_qty,
						'price'     => $product_price,
					);
				}

				?>

				<div class="return-details">
					<p class="items-status label">
						<label><?php esc_html_e( 'Status', 'zorem-returns' ); ?>:</label>
						<span class="return_status_label status-<?php echo esc_attr( $return_status ); ?> desc">
							<?php echo esc_html( ucfirst( str_replace( '-', ' ', $return_status ) ) ); ?>
						</span>
					</p>

					<?php if ( ! empty( $items ) ) { ?>
						<h4>✅ <?php esc_html_e( 'Returned Items', 'zorem-returns' ); ?></h4>

						<?php foreach ( $items as $item_row ) { ?>
							<table class="widefat striped exchange-table">
								<tbody>
									<tr>
										<th><?php esc_html_e( 'Product Name', 'zorem-returns' ); ?></th>
										<td><?php echo esc_html( $item_row['name'] ); ?></td>
									</tr>
									<?php if ( ! empty( $item_row['variation'] ) ) { ?>
									<tr>
										<th><?php esc_html_e( 'Variation', 'zorem-returns' ); ?></th>
										<td><?php echo esc_html( $item_row['variation'] ); ?></td>
									</tr>
									<?php } ?>
									<tr>
										<th><?php esc_html_e( 'Quantity', 'zorem-returns' ); ?></th>
										<td><?php echo esc_html( $item_row['quantity'] ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Price', 'zorem-returns' ); ?></th>
										<td><?php echo wp_kses_post( wc_price( $item_row['price'] ) ); ?></td>
									</tr>
								</tbody>
							</table>
						<?php } ?>
					<?php } ?>

					<?php
					$reason_text = ( 'other_reason' === $no_other ) ? $other_reason : $no_other;
					echo '<p><b>' . esc_html__( 'Return Reason', 'zorem-returns' ) . ':</b> ' . esc_html( stripslashes( $reason_text ) ) . '</p>';

					// Selected resolution (Refund to Store Credit / Original Payment / Keep the Item & Partial Refund).
					if ( ! empty( $return_data->payment_method ) ) {
						$rt_resolution_label = Return_Exchange_Common::get_return_resolution_label( $return_data->payment_method );
						echo '<p><b>' . esc_html__( 'Selected Resolution:', 'zorem-returns' ) . '</b> ' . esc_html( $rt_resolution_label ) . '</p>';
						if ( 'keep_item_partial' === $return_data->payment_method ) {
							echo '<p><em>' . esc_html__( 'Customer keeps the item. Process the partial refund manually from the WooCommerce Edit Order page — the plugin does not issue it automatically.', 'zorem-returns' ) . '</em></p>';
						}
					}

					if ( ! empty( $return_issue ) ) {
						echo '<p><b>' . esc_html__( 'Customer’s Reason for Return:', 'zorem-returns' ) . '</b> ' . esc_html( stripslashes( $return_issue ) ) . '</p>';
					}

					if ( ! empty( $return_note ) && ( 'return-approved' === $return_status || 'return-completed' === $return_status ) ) {
						echo '<p><b>' . esc_html__( 'Return Approved Note:', 'zorem-returns' ) . ':</b> ' . esc_html( stripslashes( $return_note ) ) . '</p>';
					}

					if ( ! empty( $return_note ) && 'return-rejected' === $return_status ) {
						echo '<p><b>' . esc_html__( 'Return Rejected Note:', 'zorem-returns' ) . ':</b> ' . esc_html( stripslashes( $return_note ) ) . '</p>';
					}

					// Restocking fee — show snapshot for any status; allow edit only while return-requested.
					$has_fee_snapshot = isset( $return_data->restocking_fee_amount ) && (float) $return_data->restocking_fee_amount > 0;
					$fee_label_admin  = ( zorem_returns_exchanges()->restocking_fee ) ? zorem_returns_exchanges()->restocking_fee->get_label() : __( 'Restocking Fee', 'zorem-returns' );
					$fee_amount_admin = $has_fee_snapshot ? (float) $return_data->restocking_fee_amount : 0;

					if ( $has_fee_snapshot && 'return-requested' !== $return_status ) {
						echo '<p><b>' . esc_html( $fee_label_admin ) . ':</b> ' . wp_kses_post( wc_price( $fee_amount_admin ) ) . '</p>';
					}

					if ( 'return-requested' === $return_status ) {
						?>
						<textarea name="return_note" id="return_note" rows="4" placeholder="<?php esc_attr_e( 'Add a note for return approval or rejection...', 'zorem-returns' ); ?>" style="width: 100%"></textarea>
						<?php
						// Editable restocking fee field — admin can adjust before approval.
						if ( $has_fee_snapshot || ( zorem_returns_exchanges()->restocking_fee && zorem_returns_exchanges()->restocking_fee->is_enabled() ) ) {
							?>
							<div class="zorem-rf-admin-edit" style="margin-top:10px;">
								<label for="restocking_fee_amount"><strong><?php echo esc_html( $fee_label_admin ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Edit the restocking fee before approving the request. Leave 0 to refund the full amount.', 'zorem-returns' ); ?></p>
								<input type="number" step="0.01" min="0" name="restocking_fee_amount" id="restocking_fee_amount" value="<?php echo esc_attr( $fee_amount_admin ); ?>" />
							</div>
							<?php
						}
						?>
						<div class="actions-buttons" style="margin-top:10px;">
							<button type="button" class="button wc-action-button return_approved_button" status="return-approved" id="<?php echo esc_attr( $return_data->id ); ?>">
								<?php esc_html_e( 'Approve', 'zorem-returns' ); ?>
							</button>
							<button type="button" class="button wc-action-button return_rejected_button" status="return-rejected" id="<?php echo esc_attr( $return_data->id ); ?>">
								<?php esc_html_e( 'Reject', 'zorem-returns' ); ?>
							</button>
						</div>
					<?php } ?>

					<?php if ( 'return-approved' === $return_status ) { ?>
						<div class="actions-buttons" style="margin-top:10px;">
							<button type="button" class="button wc-action-button return_completed_button" status="return-completed" id="<?php echo esc_attr( $return_data->id ); ?>">
								<?php esc_html_e( 'Mark as Completed', 'zorem-returns' ); ?>
							</button>
						</div>
					<?php } ?>
				</div>
			<?php } ?>
		</div>
		<?php
	}

	/**
	 * Items that still have returnable quantity left (order qty minus qty already
	 * requested across every existing return row for this order).
	 *
	 * @param WC_Order $order        Order object.
	 * @param array    $return_datas Rows from get_data_by_order_id().
	 * @return array item_id => array( name, variation, max_qty )
	 * @since 1.6.8
	 */
	public function get_admin_returnable_items( $order, $return_datas ) {
		// Units already requested for exchange are excluded too — a unit that was
		// exchanged cannot also be returned (and vice versa).
		$exchange_datas = ( isset( zorem_returns_exchanges()->exCommon ) && is_object( zorem_returns_exchanges()->exCommon ) )
			? zorem_returns_exchanges()->exCommon->get_exchange_data_by_order_id( $order->get_id() )
			: array();

		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! is_object( $product ) ) {
				continue;
			}
			$remaining = $item->get_quantity() - (int) $this->get_returned_line_item_qty( $item_id, $return_datas );
			if ( ! empty( $exchange_datas ) ) {
				$remaining -= (int) zorem_returns_exchanges()->exCommon->get_exchanged_line_item_qty( $item_id, $exchange_datas );
			}
			if ( $remaining <= 0 ) {
				continue;
			}
			$meta_parts = array();
			foreach ( $item->get_all_formatted_meta_data() as $meta ) {
				$meta_parts[] = wp_strip_all_tags( $meta->display_key . ': ' . $meta->display_value );
			}
			// Per-unit amount actually paid (after discount, incl tax) — matches the refund,
			// the completion email, the customer return page and the admin summary.
			$unit_price = (float) $order->get_item_total( $item, true, true );

			$items[ $item_id ] = array(
				'name'       => $item->get_name(),
				'variation'  => implode( ' | ', $meta_parts ),
				'max_qty'    => $remaining,
				'price_raw'  => $unit_price,
				'price_html' => wc_price( $unit_price, array( 'currency' => $order->get_currency() ) ),
			);
		}
		return $items;
	}

	/**
	 * "Create Return Request" button + modal on the admin order edit screen.
	 * Lets a support agent file a return on the customer's behalf. The
	 * customer-facing eligibility rules (return window, payment-method block,
	 * fraud limits) are surfaced as soft warnings here instead of hard blocks —
	 * the admin decides whether to proceed.
	 *
	 * @param WC_Order $order            Order object.
	 * @param array    $returnable_items From get_admin_returnable_items().
	 * @since 1.6.8
	 */
	private function render_admin_create_return_ui( $order, $returnable_items ) {

		wp_enqueue_style( 'zorem-admin-create-return', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/admin-create-return.css', array(), zorem_returns_exchanges()->version );
		wp_enqueue_script( 'zorem-admin-create-return', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/admin-create-return.js', array( 'jquery' ), zorem_returns_exchanges()->version, true );
		wp_localize_script(
			'zorem-admin-create-return',
			'zorem_acr',
			array(
				'select_item'   => __( 'Please select a quantity for at least one item.', 'zorem-returns' ),
				'select_reason' => __( 'Please select a return reason.', 'zorem-returns' ),
				'network_error' => __( 'Network error. Please try again.', 'zorem-returns' ),
				// WooCommerce currency settings so the JS row/grand totals render
				// exactly like wc_price() output.
				'currency'      => array(
					'symbol'       => html_entity_decode( get_woocommerce_currency_symbol( $order->get_currency() ), ENT_QUOTES, 'UTF-8' ),
					'decimals'     => wc_get_price_decimals(),
					'decimal_sep'  => wc_get_price_decimal_separator(),
					'thousand_sep' => wc_get_price_thousand_separator(),
					'format'       => get_woocommerce_price_format(),
				),
			)
		);

		// Soft warnings — customer-facing rules the admin flow deliberately bypasses.
		$warnings = array();
		if ( ! $this->is_order_valid_for_return( $order ) ) {
			$warnings[] = __( 'This order is outside the return window (or its status is not eligible), so the customer could not request this return themselves.', 'zorem-returns' );
		}
		if ( $this->is_payment_method_blocked_for_return( $order ) ) {
			$warnings[] = __( "This order's payment method is blocked for customer returns.", 'zorem-returns' );
		}
		if ( isset( zorem_returns_exchanges()->fraud_prevention ) && is_object( zorem_returns_exchanges()->fraud_prevention ) ) {
			$fp_check = zorem_returns_exchanges()->fraud_prevention->check_order( $order );
			if ( ! empty( $fp_check['blocked'] ) ) {
				$warnings[] = __( 'Fraud prevention would block this customer on the front-end:', 'zorem-returns' ) . ' ' . $fp_check['message'];
			}
		}

		// Same reasons config as the front-end return form.
		$default_return_reasons = array(
			__( 'Product damaged, but shipping box OK', 'zorem-returns' ),
			__( 'Both product and shipping box damaged', 'zorem-returns' ),
			__( 'Wrong item was sent', 'zorem-returns' ),
			__( "Item defective, spoilt, expired, or doesn't work", 'zorem-returns' ),
			__( 'Items or parts missing', 'zorem-returns' ),
		);
		$return_reasons         = zorem_returns_exchanges()->common->return_settings( 'return_reasons', $default_return_reasons );
		?>
		<div class="zorem-acr-open-wrap">
			<button type="button" class="button button-primary" id="zorem_acr_open"><?php esc_html_e( 'Create Return Request', 'zorem-returns' ); ?></button>
		</div>

		<div id="zorem_acr_modal" class="zorem-acr-modal" style="display:none;">
			<div class="zorem-acr-modal-content">
				<div class="zorem-acr-modal-header">
					<h2><?php esc_html_e( 'Create Return Request', 'zorem-returns' ); ?></h2>
					<button type="button" class="zorem-acr-close" aria-label="<?php esc_attr_e( 'Close', 'zorem-returns' ); ?>">&times;</button>
				</div>
				<div class="zorem-acr-modal-body">
					<?php foreach ( $warnings as $warning ) { ?>
						<div class="zorem-acr-warning"><?php echo esc_html( $warning ); ?></div>
					<?php } ?>

					<input type="hidden" id="zorem_acr_nonce" value="<?php echo esc_attr( wp_create_nonce( 'zorem_admin_create_return' ) ); ?>">
					<input type="hidden" id="zorem_acr_order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">

					<h4><?php esc_html_e( 'Items to return', 'zorem-returns' ); ?></h4>
					<table class="widefat striped zorem-acr-items">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Product', 'zorem-returns' ); ?></th>
								<th class="zorem-acr-price-col"><?php esc_html_e( 'Price', 'zorem-returns' ); ?></th>
								<th class="zorem-acr-qty-col"><?php esc_html_e( 'Qty', 'zorem-returns' ); ?></th>
								<th class="zorem-acr-total-col"><?php esc_html_e( 'Total', 'zorem-returns' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $returnable_items as $item_id => $row ) { ?>
							<tr>
								<td>
									<?php echo esc_html( $row['name'] ); ?>
									<?php if ( ! empty( $row['variation'] ) ) { ?>
										<br><small><?php echo esc_html( $row['variation'] ); ?></small>
									<?php } ?>
								</td>
								<td class="zorem-acr-price-col"><?php echo wp_kses_post( $row['price_html'] ); ?></td>
								<td class="zorem-acr-qty-col">
									<select class="zorem-acr-qty" data-item-id="<?php echo esc_attr( $item_id ); ?>" data-price="<?php echo esc_attr( $row['price_raw'] ); ?>">
										<?php for ( $q = 0; $q <= $row['max_qty']; $q++ ) { ?>
											<option value="<?php echo esc_attr( $q ); ?>"><?php echo esc_html( $q ); ?></option>
										<?php } ?>
									</select>
								</td>
								<td class="zorem-acr-total-col zorem-acr-row-total">&mdash;</td>
							</tr>
						<?php } ?>
						</tbody>
						<tfoot>
							<tr>
								<th colspan="3" class="zorem-acr-grand-label"><?php esc_html_e( 'Total', 'zorem-returns' ); ?></th>
								<th class="zorem-acr-total-col zorem-acr-grand-total">&mdash;</th>
							</tr>
						</tfoot>
					</table>
					<?php if ( wc_tax_enabled() ) { ?>
						<p class="description zorem-acr-tax-note"><?php esc_html_e( 'Prices are shown including tax, as charged on the order.', 'zorem-returns' ); ?></p>
					<?php } ?>

					<p>
						<label for="zorem_acr_reason"><strong><?php esc_html_e( 'Return Reason', 'zorem-returns' ); ?></strong></label>
						<select id="zorem_acr_reason" class="widefat">
							<?php foreach ( (array) $return_reasons as $value ) { ?>
								<?php if ( ! empty( $value ) ) { ?>
									<option value="<?php echo esc_attr( stripslashes( $value ) ); ?>"><?php echo esc_html( stripslashes( $value ) ); ?></option>
								<?php } ?>
							<?php } ?>
							<option value="other_reason"><?php esc_html_e( 'Other (add details below)', 'zorem-returns' ); ?></option>
						</select>
					</p>
					<p class="zorem-acr-other-wrap" style="display:none;">
						<textarea id="zorem_acr_other_reason" class="widefat" rows="2" placeholder="<?php esc_attr_e( 'Reason details…', 'zorem-returns' ); ?>"></textarea>
					</p>
					<p>
						<label for="zorem_acr_issue"><strong><?php esc_html_e( 'Issue description (optional)', 'zorem-returns' ); ?></strong></label>
						<textarea id="zorem_acr_issue" class="widefat" rows="2"></textarea>
					</p>
					<p>
						<label for="zorem_acr_resolution"><strong><?php esc_html_e( 'Resolution', 'zorem-returns' ); ?></strong></label>
						<select id="zorem_acr_resolution" class="widefat">
							<option value="customer_account"><?php esc_html_e( 'Refund to Original Payment Method', 'zorem-returns' ); ?></option>
							<option value="store_credit"><?php esc_html_e( 'Refund to Store Credit', 'zorem-returns' ); ?></option>
						</select>
					</p>
					<p>
						<label for="zorem_acr_status"><strong><?php esc_html_e( 'Initial status', 'zorem-returns' ); ?></strong></label>
						<select id="zorem_acr_status" class="widefat">
							<option value="return-approved" selected><?php esc_html_e( 'Approved', 'zorem-returns' ); ?></option>
							<option value="return-requested"><?php esc_html_e( 'Requested', 'zorem-returns' ); ?></option>
						</select>
					</p>
					<p>
						<label><input type="checkbox" id="zorem_acr_notify" checked> <?php esc_html_e( 'Send notification email to the customer', 'zorem-returns' ); ?></label>
					</p>
					<div class="zorem-acr-error" style="display:none;"></div>
				</div>
				<div class="zorem-acr-modal-footer">
					<button type="button" class="button zorem-acr-close"><?php esc_html_e( 'Cancel', 'zorem-returns' ); ?></button>
					<button type="button" class="button button-primary" id="zorem_acr_submit"><?php esc_html_e( 'Create Return', 'zorem-returns' ); ?></button>
					<span class="spinner zorem-acr-spinner"></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Custom email for return for admin
	 *
	 * @since  1.0.0
	 */
	public function return_email_detail( $request_id, $order, $email ) {
		$order_id = $order->get_id();
		?>
		<table width="100%" style="border:1px solid #e5e5e5;border-collapse: collapse;text-align: center;margin: 0 0 16px">
		<tbody>
			<tr style="border-bottom:1px solid #e5e5e5;">
				<th style="border-right:1px solid #e5e5e5;font-size: 14px;color: #636363;"><?php esc_html_e( 'Product Name', 'zorem-returns' ); ?></th>
				<th style="border-right:1px solid #e5e5e5;font-size: 14px;color: #636363;"><?php esc_html_e( 'Product Price', 'zorem-returns' ); ?></th>
				<th style="border-right:1px solid #e5e5e5;font-size: 14px;color: #636363;"><?php esc_html_e( 'Return Quantity', 'zorem-returns' ); ?></th>
			</tr>
			<?php
			if ( 1 == $order_id ) {
				?>
				<tr style="border-bottom:1px solid #e5e5e5;">
					<td style="border-right:1px solid #e5e5e5;font-size: 14px;color: #636363;"><?php echo wp_kses_post( 'T-shirt' ); ?></td>
					<td style="border-right:1px solid #e5e5e5;font-size: 14px;color: #636363;"><?php echo wp_kses_post( wc_price( '14.95' ) ); ?></td>
					<td style="border-right:1px solid #e5e5e5;font-size: 14px;color: #636363;"><?php echo esc_html( '1' ); ?></td>	
				</tr>
				<?php
			} else {
				$return_data  = zorem_returns_exchanges()->common->get_data_by_return_id( $request_id );
				$return_items = unserialize( $return_data->return_items );
				foreach ( $order->get_items() as $item_id => $item ) {
					$product = wc_get_product( $item['product_id'] );
					if ( ! is_object( $product ) ) {
						continue;
					}
					// Show the amount actually paid per unit (after discount, incl tax), so the
					// email matches the refund and the admin summary — not the pre-discount price.
					$product_price = wc_price( $order->get_item_total( $item, true, true ), array( 'currency' => $order->get_currency() ) );
					$qty           = isset( $return_items[ $item_id ]['return_product_qty'] ) ? $return_items[ $item_id ]['return_product_qty'] : '0';

					$is_visible        = $product->is_visible();
					$product_permalink = apply_filters( 'woocommerce_order_item_permalink', $is_visible ? $product->get_permalink( $item ) : '', $item, $order );
					$product_name      = apply_filters( 'woocommerce_order_item_name', $product_permalink ? sprintf( '<a href="%s">%s</a>', $product_permalink, $item->get_name() ) : $item->get_name(), $item, $is_visible );

					if ( isset( $return_data->return_the_entire_order ) && 1 == $return_data->return_the_entire_order && $qty > 0 ) {
						?>
						<tr style="border-bottom:1px solid #e5e5e5;">
							<td style="border-right:1px solid #e5e5e5"><?php echo wp_kses_post( $product_name ); ?></td>
							<td style="border-right:1px solid #e5e5e5"><?php echo wp_kses_post( $product_price ); ?></td>
							<td style="border-right:1px solid #e5e5e5"><?php echo esc_html( $qty ); ?></td>	
						</tr>
					<?php } elseif ( $qty > 0 ) { ?>
						<tr style="border-bottom:1px solid #e5e5e5;">
							<td style="border-right:1px solid #e5e5e5"><?php echo wp_kses_post( $product_name ); ?></td>
							<td style="border-right:1px solid #e5e5e5"><?php echo wp_kses_post( $product_price ); ?></td>
							<td style="border-right:1px solid #e5e5e5"><?php echo esc_html( $qty ); ?></td>	
						</tr>
						<?php
					}
				}
			}
			?>
		</tbody>
		</table>
		<br><br>
		<?php
	}

	/*
	 * Create a customer coupon by return request id
	 *
	 * @since  1.0
	 */
	public function create_coupon_by_return_requestId( $request_id ) {
		global $wpdb;

		if ( empty( $request_id ) ) {
			return;
		}

		$return_data  = zorem_returns_exchanges()->common->get_data_by_return_id( $request_id );
		$order        = wc_get_order( $return_data->order_id );
		$customerName = $order->get_billing_first_name();
		$coupon_code  = isset( $customerName ) ? strtoupper( esc_html( $customerName . $request_id ) ) : '';

		$coupon_amount = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			$return_items = unserialize( $return_data->return_items );
			$return_qty   = isset( $return_items[ $item_id ]['return_product_qty'] ) ? $return_items[ $item_id ]['return_product_qty'] : 0;
			if ( $return_qty <= 0 ) {
				continue;
			}
			$ordered_qty    = $item->get_quantity();
			$item_total     = $item->get_total() + $item->get_total_tax();
			$per_item       = $ordered_qty > 0 ? ( $item_total / $ordered_qty ) : 0;
			$coupon_amount += $per_item * $return_qty;
		}

		// If refund shipping fee option is enabled
		$refund_shipping_fee = zorem_returns_exchanges()->common->return_settings( 'refund_shipping_fee', 'no' );
		$return_form_data    = zorem_returns_exchanges()->common->get_returned_form_data( $order );
		if ( empty( $return_form_data ) && 'yes' == $refund_shipping_fee ) {
			// Get the shipping total
			$coupon_amount += $order->get_shipping_total();
		}

		// Deduct restocking fee snapshot (frozen at submit time, possibly admin-adjusted before approval).
		$restocking_fee = $this->get_restocking_fee_amount( $return_data );
		if ( $restocking_fee > 0 ) {
			$coupon_amount = max( 0, $coupon_amount - $restocking_fee );
		}

		// Resolve a coupon ID for this request: re-use an existing one (so legacy returns
		// that had their coupon created under the old approve-time flow still notify on
		// completion) or create a new one when none exists yet.
		$coupon_id = wc_get_coupon_id_by_code( $coupon_code );

		if ( 0 == $coupon_id && 0 < $coupon_amount ) {
			$coupon_post = array(
				'post_title'   => $coupon_code,
				'post_content' => '',
				'post_status'  => 'publish',
				'post_author'  => 1,
				'post_type'    => 'shop_coupon',
			);

			$new_coupon_id = wp_insert_post( $coupon_post );

			if ( ! is_wp_error( $new_coupon_id ) && $new_coupon_id ) {
				update_post_meta( $new_coupon_id, 'discount_type', 'fixed_cart' );
				update_post_meta( $new_coupon_id, 'coupon_amount', $coupon_amount );
				update_post_meta( $new_coupon_id, '_zorem_is_store_credit', 'yes' );
				$coupon_id = $new_coupon_id;
			}
		}

		// Always notify on completion when we have a valid coupon to point to,
		// regardless of whether it was just created or already existed.
		if ( $coupon_id && ! empty( $order ) ) {

			/**
			 * Virtual status `return-store-credit` fires when a store-credit coupon
			 * is issued for a completed return. Used by the SMS for WooCommerce plugin
			 * to send the matching SMS notification, and by 3rd-party listeners.
			 * Fires before the email toggle gate so SMS is independent of the email.
			 */
			do_action( 'zorem_return_status_changed', $request_id, $order->get_id(), 'return-store-credit' );

			$this->send_return_store_credit_email( $coupon_id, $order );
		}
	}

	/**
	 * Send the customer the "Return Store Credit" email using the customizable template.
	 * Respects the customer_return_store_credit_status enable/disable toggle.
	 *
	 * @param int      $coupon_id
	 * @param WC_Order $order
	 */
	public function send_return_store_credit_email( $coupon_id, $order ) {

		if ( 'no' === get_option( 'customer_return_store_credit_status', 'yes' ) ) {
			return;
		}

		// WPML: render the email in the language the order was placed in. Restored after send.
		zorem_returns_exchanges()->wpml->switch_to_order_language( $order );

		$defualt_settings = $this->generate_defaults();
		$email_settings   = get_option( 'rt4wc_customer_return_store_credit_settings', $defualt_settings['customer_return_store_credit'] );

		$customer = new WC_Customer( $order->get_customer_id() );

		$replace_keys = array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}' );
		$replace_vals = array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username() );

		$subject       = isset( $email_settings['subject'] ) ? $email_settings['subject'] : $defualt_settings['customer_return_store_credit']['subject'];
		$email_heading = isset( $email_settings['heading'] ) ? $email_settings['heading'] : $defualt_settings['customer_return_store_credit']['heading'];
		$subject       = str_replace( $replace_keys, $replace_vals, $subject );
		$email_heading = str_replace( $replace_keys, $replace_vals, $email_heading );

		ob_start();
		$local_template = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/emails/customer-return-store-credit.php';
		if ( file_exists( $local_template ) && wp_is_writable( $local_template ) ) {
			$directory_path = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/';
		} else {
			$directory_path = zorem_returns_exchanges()->get_plugin_path() . '/templates/';
		}

		$mailer = WC()->mailer();
		$email  = new WC_Email();

		wc_get_template(
			'emails/customer-return-store-credit.php',
			array(
				'coupon_id'      => $coupon_id,
				'order'          => $order,
				'email'          => $email,
				'email_settings' => $email_settings,
			),
			'zorem-returns-exchanges/',
			$directory_path
		);
		$message = ob_get_clean();

		$message = apply_filters( 'woocommerce_mail_content', $email->style_inline( $mailer->wrap_message( $email_heading, $message ) ) );
		add_filter( 'wp_mail_from', array( zorem_returns_exchanges()->front, 'get_from_address' ) );
		add_filter( 'wp_mail_from_name', array( zorem_returns_exchanges()->front, 'get_from_name' ) );

		$recipient = $order->get_billing_email();
		if ( $recipient ) {
			wp_mail( $recipient, $subject, $message, $email->get_headers() );
		}

		zorem_returns_exchanges()->wpml->restore_language();
	}

	/*
	 * Refund to TeraWallet by return request id
	 *
	 * @since  1.6
	 */
	public function refund_to_tera_wallet( $request_id ) {
		if ( empty( $request_id ) ) {
			return;
		}

		if ( ! function_exists( 'woo_wallet' ) ) {
			return;
		}

		$return_data = zorem_returns_exchanges()->common->get_data_by_return_id( $request_id );
		$order       = wc_get_order( $return_data->order_id );

		if ( ! $order ) {
			return;
		}

		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			return;
		}

		// Calculate refund amount from returned items (including tax)
		$refund_amount = 0;
		$return_items  = unserialize( $return_data->return_items );
		foreach ( $order->get_items() as $item_id => $item ) {
			$return_qty = isset( $return_items[ $item_id ]['return_product_qty'] ) ? $return_items[ $item_id ]['return_product_qty'] : 0;
			if ( $return_qty > 0 ) {
				$ordered_qty    = $item->get_quantity();
				$item_total     = $item->get_total() + $item->get_total_tax();
				$per_item       = $ordered_qty > 0 ? ( $item_total / $ordered_qty ) : 0;
				$refund_amount += $per_item * $return_qty;
			}
		}

		// Include shipping if enabled
		$refund_shipping_fee = zorem_returns_exchanges()->common->return_settings( 'refund_shipping_fee', 'no' );
		$return_form_data    = zorem_returns_exchanges()->common->get_returned_form_data( $order );
		if ( empty( $return_form_data ) && 'yes' == $refund_shipping_fee ) {
			$refund_amount += $order->get_shipping_total();
		}

		// Deduct restocking fee snapshot.
		$restocking_fee = $this->get_restocking_fee_amount( $return_data );
		if ( $restocking_fee > 0 ) {
			$refund_amount = max( 0, $refund_amount - $restocking_fee );
		}

		if ( $refund_amount <= 0 ) {
			return;
		}

		// Credit the amount to the customer's TeraWallet
		$transaction_id = woo_wallet()->wallet->credit(
			$user_id,
			$refund_amount,
			sprintf(
			/* translators: %s: order number */
				__( 'Wallet refund for return request on order #%s', 'zorem-returns' ),
				$order->get_order_number()
			)
		);

		if ( $transaction_id ) {
			$note_extra = '';
			if ( $restocking_fee > 0 ) {
				$fee_label   = zorem_returns_exchanges()->restocking_fee->get_label();
				$note_extra  = ' ' . sprintf(
					/* translators: 1: fee label, 2: fee amount */
					__( '(%1$s deducted: %2$s)', 'zorem-returns' ),
					$fee_label,
					wc_price( $restocking_fee )
				);
			}
			$order->add_order_note(
				sprintf(
				/* translators: %1$s: refund amount, %2$s: order number */
					__( 'Refund of %1$s credited to customer wallet for return request #%2$s.', 'zorem-returns' ),
					wc_price( $refund_amount ),
					$request_id
				) . $note_extra
			);
		}
	}

	/*
	 * Process WooCommerce refund for a "Keep the Item" return request.
	 * Called when admin approves a return with payment_method = keep_the_item.
	 *
	 * @param int $request_id The return request ID.
	 * @since  1.6.1
	 */

	public function process_keep_item_refund( $request_id ) {
		if ( empty( $request_id ) ) {
			return;
		}

		$return_data = zorem_returns_exchanges()->common->get_data_by_return_id( $request_id );
		$order       = wc_get_order( $return_data->order_id );

		if ( ! $order ) {
			return;
		}

		// Calculate refund amount from returned items (including tax)
		$refund_amount = 0;
		$line_items    = array();
		$return_items  = unserialize( $return_data->return_items );
		foreach ( $order->get_items() as $item_id => $item ) {
			$return_qty = isset( $return_items[ $item_id ]['return_product_qty'] ) ? (int) $return_items[ $item_id ]['return_product_qty'] : 0;
			if ( $return_qty > 0 ) {
				$ordered_qty   = $item->get_quantity();
				$item_total    = $item->get_total();
				$item_tax      = $item->get_total_tax();
				$per_item      = $ordered_qty > 0 ? ( $item_total / $ordered_qty ) : 0;
				$per_item_tax  = $ordered_qty > 0 ? ( $item_tax / $ordered_qty ) : 0;
				$refund_amount += round( ( $per_item + $per_item_tax ) * $return_qty, 2 );
				$line_items[ $item_id ] = array(
					'qty'          => $return_qty,
					'refund_total' => round( $per_item * $return_qty, 2 ),
					'refund_tax'   => array(),
				);
			}
		}

		// Include shipping if enabled and all items returned
		$refund_shipping_fee = zorem_returns_exchanges()->common->return_settings( 'refund_shipping_fee', 'no' );
		$return_form_data    = zorem_returns_exchanges()->common->get_returned_form_data( $order );
		if ( empty( $return_form_data ) && 'yes' == $refund_shipping_fee ) {
			$refund_amount += $order->get_shipping_total();
		}

		// Deduct restocking fee snapshot.
		$restocking_fee = $this->get_restocking_fee_amount( $return_data );
		if ( $restocking_fee > 0 ) {
			$refund_amount = max( 0, $refund_amount - $restocking_fee );
		}

		if ( $refund_amount <= 0 ) {
			return;
		}

		$refund = wc_create_refund(
			array(
				'amount'        => round( $refund_amount, 2 ),
				'reason'        => __( 'Keep the Item — refund for return request', 'zorem-returns' ),
				'order_id'      => $return_data->order_id,
				'line_items'    => $line_items,
				'restock_items' => false,
			)
		);

		if ( ! is_wp_error( $refund ) ) {
			$note_extra = '';
			if ( $restocking_fee > 0 ) {
				$fee_label  = zorem_returns_exchanges()->restocking_fee->get_label();
				$note_extra = ' ' . sprintf(
					/* translators: 1: fee label, 2: fee amount */
					__( '(%1$s deducted: %2$s)', 'zorem-returns' ),
					$fee_label,
					wc_price( $restocking_fee )
				);
			}
			$order->add_order_note(
				sprintf(
					/* translators: %1$s: refund amount, %2$s: request ID */
					__( 'Keep the Item — refund of %1$s processed automatically for return request #%2$s. Customer retains the item.', 'zorem-returns' ),
					wc_price( $refund_amount ),
					$request_id
				) . $note_extra
			);
		}
	}

	/*
	 * Return setting Options HTML
	 *
	 * @since  1.0
	 */
	public function get_html( $return_settings ) {
		foreach ( (array) $return_settings as $key => $array ) {
			$value               = isset( $array['value'] ) ? $array['value'] : '';
			$settings_menu_value = zorem_returns_exchanges()->common->return_settings( $key, $value );
			$field_class         = isset( $array['class'] ) ? $array['class'] : '';
			$tooltip             = isset( $array['tooltip'] ) ? $array['tooltip'] : '';
			$type                = $array['type'];

			if ( 'title' === $type ) {
				?>
				<div class="zui-row zui-row--title">
					<h3 class="<?php echo esc_attr( $field_class ); ?>"><?php echo esc_html( $array['title'] ); ?></h3>
				</div>
				<?php
				continue;
			}

			// Toggle rows render inline (label left, control right). Everything
			// else stacks the control below the label (full-width control).
			$is_inline_row = ( 'checkbox' === $type );
			$row_class     = 'zui-row rt4wc-row' . ( $is_inline_row ? ' zui-row--inline' : '' );
			$desc          = isset( $array['desc'] ) ? $array['desc'] : '';
			?>
			<div class="<?php echo esc_attr( $row_class ); ?>" data-field="<?php echo esc_attr( $key ); ?>">
				<div class="zui-row__head">
					<span class="zui-row__label">
						<?php echo esc_html( $array['title'] ); ?>
						<?php if ( $tooltip ) : ?>
							<span class="zui-tooltip" tabindex="0" role="img" aria-label="<?php echo esc_attr( $tooltip ); ?>">
								<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
								<span class="zui-tooltip__bubble"><?php echo esc_html( $tooltip ); ?><span class="zui-tooltip__arrow"></span></span>
							</span>
						<?php endif; ?>
					</span>
					<?php if ( '' !== $desc ) : ?>
						<span class="zui-row__desc"><?php echo esc_html( $desc ); ?></span>
					<?php endif; ?>
				</div>
				<div class="zui-row__control">
					<?php
					if ( in_array( $type, array( 'text', 'number' ), true ) ) {
						?>
						<input class="zui-input <?php echo esc_attr( $field_class ); ?>" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $settings_menu_value ); ?>" min="<?php echo isset( $array['min'] ) ? esc_attr( $array['min'] ) : ''; ?>" max="<?php echo isset( $array['max'] ) ? esc_attr( $array['max'] ) : ''; ?>" placeholder="<?php echo isset( $array['placeholder'] ) ? esc_attr( $array['placeholder'] ) : ''; ?>">
						<?php
					} elseif ( 'textarea' === $type ) {
						?>
						<textarea rows="4" class="zui-input <?php echo esc_attr( $field_class ); ?>" name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>" placeholder="<?php echo ! empty( $array['placeholder'] ) ? esc_attr( $array['placeholder'] ) : ''; ?>"><?php echo esc_textarea( stripslashes( $settings_menu_value ) ); ?></textarea>
						<?php
					} elseif ( 'text_editor' === $type ) {
						wp_editor(
							html_entity_decode( $settings_menu_value ),
							$key,
							array(
								'editor_height' => 200,
								'media_buttons' => false,
							)
						);
					} elseif ( 'select' === $type ) {
						?>
						<div class="zui-select-wrap">
							<select class="zui-select <?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" required>
								<?php if ( isset( $array['sub_option'] ) ) { ?>
									<?php foreach ( (array) $array['sub_option'] as $sub_key => $sub_value ) { ?>
										<option value="<?php echo esc_attr( $sub_key ); ?>" <?php selected( $sub_key, $settings_menu_value ); ?>><?php echo esc_html( $sub_value ); ?></option>
									<?php } ?>
								<?php } ?>
							</select>
							<span class="zui-select-chevron">
								<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
							</span>
						</div>
						<?php
					} elseif ( 'multi_select' === $type ) {
						$placeholder = ! empty( $array['placeholder'] ) ? $array['placeholder'] : __( 'Select…', 'zorem-returns' );
						?>
						<div class="zui-ms" data-zui-multiselect data-placeholder="<?php echo esc_attr( $placeholder ); ?>">
							<select class="zui-ms__native" name="<?php echo esc_attr( $key ); ?>[]" id="<?php echo esc_attr( $key ); ?>" multiple hidden>
								<?php foreach ( (array) $array['options'] as $key1 => $val1 ) { ?>
									<option value="<?php echo esc_attr( $key1 ); ?>" <?php echo in_array( $key1, (array) $settings_menu_value ) ? 'selected' : ''; ?>><?php echo esc_html( $val1 ); ?></option>
								<?php } ?>
							</select>
							<div class="zui-ms__control" tabindex="0">
								<div class="zui-ms__chips"></div>
								<span class="zui-ms__caret" aria-hidden="true">
									<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
								</span>
							</div>
							<div class="zui-ms__dropdown" hidden></div>
						</div>
						<?php
					} elseif ( 'multi_select_batch' === $type ) {
						?>
						<select multiple class="zui-multiselect select select2 <?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>[]" data-placeholder="<?php esc_attr_e( 'Search for a product...', 'zorem-returns' ); ?>" autocomplete="false"></select>
						<?php
						$excluded_items = ! empty( $settings_menu_value ) ? $settings_menu_value : array();
						$product_details = array();
						foreach ( $excluded_items as $product_id ) {
							$product = wc_get_product( $product_id );
							if ( ! is_object( $product ) ) {
								continue;
							}
							$product_details[] = array(
								'product_id'   => $product_id,
								'product_name' => $product->get_name(),
							);
						}
						?>
						<script>
							(function(){
								var excludedItems = <?php echo wp_json_encode( $product_details ); ?>;
								excludedItems.forEach(function(item) {
									var newOption = new Option(item.product_name, item.product_id, true, true);
									jQuery('#<?php echo esc_js( $key ); ?>').append(newOption).trigger('change');
								});
							})();
						</script>
						<?php
					} elseif ( 'checkbox' === $type ) {
						?>
						<label class="zui-toggle rt4wc-toggle">
							<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="no">
							<input type="checkbox" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="yes" class="zui-toggle__input <?php echo esc_attr( $field_class ); ?>" <?php echo 'yes' == $settings_menu_value ? 'checked' : ''; ?>>
							<span class="zui-toggle__track"><span class="zui-toggle__thumb"></span></span>
						</label>
						<?php
					} elseif ( 'todo-list' === $type ) {
						$contenteditable = isset( $array['contenteditable'] ) ? $array['contenteditable'] : '';
						$draggable       = isset( $array['draggable'] ) ? $array['draggable'] : '';
						?>
						<div class="rt4wc-todo">
							<div class="rt4wc-todo__input-row">
								<input type="text" id="<?php echo esc_attr( $key ); ?>" class="zui-input rt4wc-todo__input" placeholder="<?php echo esc_attr( $array['placeholder'] ); ?>">
								<button type="button" onclick="newElement('<?php echo esc_js( $key ); ?>')" class="zui-btn-secondary rt4wc-todo__add"><?php esc_html_e( 'Add', 'zorem-returns' ); ?></button>
							</div>
							<ul id="<?php echo esc_attr( $key ); ?>_todo" class="todo-list rt4wc-todo__list">
								<?php if ( ! empty( $settings_menu_value ) ) { ?>
									<?php foreach ( $settings_menu_value as $reason ) { ?>
										<?php if ( ! empty( $reason ) ) { ?>
											<li>
												<input type="hidden" name="<?php echo esc_attr( $key ); ?>[]" value="<?php echo esc_attr( stripslashes( $reason ) ); ?>">
												<?php echo ( $draggable ? '<span class="dashicons dashicons-menu-alt sorting-handler"></span>' : '' ); ?>
												<span class="reason_text"><?php echo esc_html( stripslashes( $reason ) ); ?></span>
												<?php echo ( $contenteditable ? '<span class="dashicons dashicons-edit"></span>' : '' ); ?>
												<span class="dashicons dashicons-trash"></span>
											</li>
										<?php } ?>
									<?php } ?>
								<?php } ?>
							</ul>
						</div>
						<?php
					} elseif ( 'multiple_checkbox' === $type ) {
						?>
						<div class="rt4wc-multi-check">
							<?php foreach ( (array) $array['options'] as $id => $val ) {
								$is_checked = ( isset( $settings_menu_value[ $id ] ) && 1 == $settings_menu_value[ $id ] );
								?>
								<label class="rt4wc-multi-check__row zui-checkbox">
									<input type="hidden" name="<?php echo esc_attr( $key ); ?>[<?php echo esc_attr( $id ); ?>]" value="0">
									<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>[<?php echo esc_attr( $id ); ?>]" class="zui-checkbox__input" <?php echo $is_checked ? 'checked' : ''; ?> value="1">
									<span class="zui-checkbox__box"></span>
									<span class="rt4wc-multi-check__label"><?php echo esc_html( $val ); ?></span>
								</label>
							<?php } ?>
						</div>
						<?php
					} elseif ( 'multiple_input' === $type ) {
						?>
						<div class="rt4wc-multi-input">
							<?php foreach ( (array) $array['options'] as $r_id => $val ) {
								if ( 'country-select' == $val['type'] ) {
									$country_setting = isset( $settings_menu_value[ $r_id ] ) ? $settings_menu_value[ $r_id ] : '';
									if ( strstr( $country_setting, ':' ) ) {
										$country_setting = explode( ':', $country_setting );
										$country         = current( $country_setting );
										$state           = end( $country_setting );
									} else {
										$country = $country_setting;
										$state   = '*';
									}
									?>
									<select name="<?php echo esc_attr( $key ); ?>[<?php echo esc_attr( $r_id ); ?>]" id="<?php echo esc_attr( $r_id ); ?>" data-placeholder="<?php echo esc_attr( $val['placeholder'] ); ?>" class="zui-select select wc-enhanced-select <?php echo esc_attr( $field_class ); ?>">
										<?php WC()->countries->country_dropdown_options( $country, $state ); ?>
									</select>
									<?php
								} else {
									?>
									<input class="zui-input <?php echo esc_attr( $field_class ); ?>" type="text" name="<?php echo esc_attr( $key ); ?>[<?php echo esc_attr( $r_id ); ?>]" id="<?php echo esc_attr( $r_id ); ?>" value="<?php echo ! empty( $settings_menu_value ) ? esc_attr( $settings_menu_value[ $r_id ] ) : ''; ?>" placeholder="<?php echo esc_attr( $val['placeholder'] ); ?>">
									<?php
								}
							} ?>
						</div>
						<?php
					}
					?>
				</div>
			</div>
			<?php
		}
	}

	public function store_return_window_start_date( $order_id, $old_status, $new_status, $order ) {
		$return_window_status = apply_filters( 'zorem_return_window_start_status', 'processing' );

		if ( $new_status === $return_window_status ) {
			$order->update_meta_data( '_return_window_start_date', current_time( 'Y-m-d' ) );
			$order->save();
		}
	}

	/**
	 * Is order object is valid for return
	 *
	 * @since  1.0.0
	 */
	public function is_order_valid_for_return( $order ) {
		$current_date = gmdate( 'Y-m-d' );

		// Check for the stored return window start date first
		$return_window_start_date = $order->get_meta( '_return_window_start_date' );

		if ( $return_window_start_date ) {
			$order_date = $return_window_start_date;
		} elseif ( $order->get_date_completed() ) {
			$order_date = $order->get_date_completed()->date( 'Y-m-d' );
		} else {
			$order_date = $order->get_date_created()->date( 'Y-m-d' );
		}

		$day        = $this->get_date_diff( $current_date, $order_date );
		$valid_days = zorem_returns_exchanges()->common->return_settings( 'return_policy_days', '14' );

		// Per-user return window extension.
		$per_user_enabled = zorem_returns_exchanges()->common->return_settings( 'per_user_return_window_enable', 'no' );
		if ( 'yes' === $per_user_enabled ) {
			$customer_id = $order->get_customer_id();
			if ( $customer_id ) {
				$custom_days = absint( get_user_meta( $customer_id, '_zorem_return_window_days', true ) );
				if ( $custom_days > 0 ) {
					// User has a specific custom window — use it as-is.
					$valid_days = $custom_days;
				}
				// If custom_days = 0 or empty, use the global $valid_days unchanged.
			}
		}

		$eligible_status   = zorem_returns_exchanges()->common->return_settings( 'return_order_statuses', array( 'wc-completed' ) );
		if ( ! is_array( $eligible_status ) || empty( $eligible_status ) ) {
			$eligible_status = array( 'wc-completed' );
		}
		$eligible_status[] = 'wc-partial-return';

		$bool = $order->get_status() == 'return' ? false : in_array( 'wc-' . $order->get_status(), $eligible_status );
		return ( $day <= $valid_days && $bool ) ? true : false;
	}

	/**
	 * Check if the order's payment method is in the admin's "disabled for returns" list.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool True if the order's payment gateway is blocked from returns.
	 */
	public function is_payment_method_blocked_for_return( $order ) {
		if ( ! $order || ! is_object( $order ) ) {
			return false;
		}
		$blocked = zorem_returns_exchanges()->common->return_settings( 'return_disabled_payment_methods', array() );
		if ( empty( $blocked ) || ! is_array( $blocked ) ) {
			return false;
		}
		$payment_method = $order->get_payment_method();
		if ( empty( $payment_method ) ) {
			return false;
		}
		return in_array( $payment_method, $blocked, true );
	}

	/**
	 * Get the admin-configured custom message for return-blocked payment methods.
	 *
	 * @param WC_Order $order Order object.
	 * @return string Message to display to the customer.
	 */
	public function get_return_blocked_message( $order ) {
		$default = __( 'Returns are not available for Cash on Delivery orders. Please contact us directly.', 'zorem-returns' );
		$message = zorem_returns_exchanges()->common->return_settings( 'return_disabled_payment_message', $default );
		if ( empty( $message ) ) {
			$message = $default;
		}
		return apply_filters( 'zorem_return_blocked_message', $message, $order );
	}

	/**
	 * Get combined message shown when BOTH returns and exchanges are blocked for the order's payment method.
	 *
	 * @param WC_Order $order Order object.
	 * @return string Combined message.
	 */
	public function get_combined_blocked_message( $order ) {
		$default = __( 'Returns and exchanges are not available for this payment method. Please contact us directly.', 'zorem-returns' );
		return apply_filters( 'zorem_combined_blocked_message', $default, $order );
	}

	/**
	 * Read the restocking fee snapshot stored on a return request row.
	 * The snapshot is captured at submit time and may be edited by admin before approval.
	 *
	 * @param object $return_data Row from {prefix}return_orders.
	 * @return float Stored fee amount (0 if disabled / not snapshotted).
	 */
	public function get_restocking_fee_amount( $return_data ) {
		if ( ! is_object( $return_data ) || ! isset( $return_data->restocking_fee_amount ) ) {
			return 0.0;
		}
		return (float) $return_data->restocking_fee_amount;
	}

	/**
	 * Is item object is valid for return
	 *
	 * @since  1.0.0
	 */
	public function is_item_valid_for_return( $item ) {

		$eligible = true;

		// get settings data
		$exclude_products    = zorem_returns_exchanges()->common->return_settings( 'return_exclude_products', array() );
		$exclude_categories  = zorem_returns_exchanges()->common->return_settings( 'return_exclude_categories', array() );
		$return_onsale_items = zorem_returns_exchanges()->common->return_settings( 'return_onsale_items', 'no' );

		// exclude products
		if ( ! empty( $exclude_products ) ) {
			$p_bool = in_array( $item->get_product_id(), $exclude_products );
			if ( $p_bool ) {
				$eligible = false;
			}
		}
		// exclude categories
		if ( ! empty( $exclude_categories ) ) {
			$id       = wp_get_post_terms( $item->get_product_id(), 'product_cat', array( 'fields' => 'ids' ) );
			$cat_bool = in_array( $id[0], $exclude_categories );
			if ( $cat_bool ) {
				$eligible = false;
			}
		}

		// Product On-Sale
		if ( 'no' == $return_onsale_items ) {
			$product = wc_get_product( $item->get_product_id() );
			if ( ! is_object( $product ) ) {
				return $eligible;
			}
			if ( ! empty( $product ) && $product->is_on_sale() ) {
				$eligible = false;
			}
		}
		return $eligible;
	}

	/**
	 * Get the eligible return items of order.
	 *
	 * @return array
	 */
	public function get_returned_form_data( $order ) {

		// get order data
		$order_id       = $order->get_id();
		$return_datas   = zorem_returns_exchanges()->common->get_data_by_order_id( $order_id );
		$exchange_datas = zorem_returns_exchanges()->exCommon->get_exchange_data_by_order_id( $order_id );

		$return_form_data = array();
		foreach ( $order->get_items() as $item_id => $item ) {

			$product = $item->get_product();

			if ( empty( $product ) || $product->is_virtual() || $product->is_downloadable() ) {
				continue;
			}

			// return Qty
			$returned_qty  = zorem_returns_exchanges()->common->get_returned_line_item_qty( $item_id, $return_datas );
			$exchanged_qty = zorem_returns_exchanges()->exCommon->get_exchanged_line_item_qty( $item_id, $exchange_datas );

			$product_quantity          = $item['quantity'] - $returned_qty;
			$exchange_product_quantity = $item['quantity'] - $exchanged_qty;
			if ( isset( $product_quantity ) && ( 0 == $product_quantity || 0 == $exchange_product_quantity ) ) {
				continue;
			}

			// prepare return item data
			if ( isset( $product_quantity ) && 0 < $product_quantity ) {
				$return_form_data[ $item->get_id() ]['product_name']     = $item['name'];
				$return_form_data[ $item->get_id() ]['product_id']       = $item['product_id'];
				$return_form_data[ $item->get_id() ]['product_quantity'] = $product_quantity;
			}
		}
		return $return_form_data;
	}

	/**
	 * Get date difference
	 *
	 * @since  1.0.0
	 */
	public function get_date_diff( $current_date, $order_date ) {
		$date1 = new DateTime( $order_date );
		$date2 = new DateTime( $current_date );

		return $date1->diff( $date2 )->days; // total days difference
	}

	/**
	 * Convert hexdec color string to rgb(a) string
	 *
	 * @since  1.0.0
	 */
	public function hex2rgba( $color, $opacity = false ) {

		$default = 'rgba(116,194,225,0.7)';

		// Return default if no color provided
		if ( empty( $color ) ) {
			return $default;
		}

		// Sanitize $color if "#" is provided
		if ( '#' == $color[0] ) {
			$color = substr( $color, 1 );
		}

		// Check if color has 6 or 3 characters and get values
		if ( 6 == strlen( $color ) ) {
			$hex = array( $color[0] . $color[1], $color[2] . $color[3], $color[4] . $color[5] );
		} elseif ( 3 == strlen( $color ) ) {
			$hex = array( $color[0] . $color[0], $color[1] . $color[1], $color[2] . $color[2] );
		} else {
			return $default;
		}

		// Convert hexadec to rgb
		$rgb = array_map( 'hexdec', $hex );

		// Check if opacity is set(rgba or rgb)
		if ( $opacity ) {
			if ( 1 < abs( $opacity ) ) {
				$opacity = 1.0;
			}
			$output = 'rgba(' . implode( ',', $rgb ) . ',' . $opacity . ')';
		} else {
			$output = 'rgb(' . implode( ',', $rgb ) . ')';
		}

		// Return rgb(a) color string
		return $output;
	}

	/**
	 * Upload image from URL programmatically
	 *
	 * @since  1.0.0
	 */
	public function upload_file_by_url( $files, $request_id ) {

		if ( ! empty( $files['return_attachments']['name'][0] ) ) {
			return $this->move_attachment_files( $files['return_attachments'], $request_id, 'rt4wc-images' );
		}

		if ( ! empty( $files['exchange_attachments']['name'][0] ) ) {
			return $this->move_attachment_files( $files['exchange_attachments'], $request_id, 'ex4wc-images' );
		}
	}

	/**
	 * Move uploaded attachment files into a subdirectory of wp-content/uploads.
	 *
	 * Uses WP_Filesystem for the actual move so we avoid the raw move_uploaded_file() call.
	 *
	 * @param array  $file       Slice of $_FILES for the field (name/tmp_name arrays).
	 * @param int    $request_id Return/exchange request id, used for filename prefix.
	 * @param string $subdir     Subdirectory under wp-content/uploads to write into.
	 * @return array Map of index => public URL.
	 */
	private function move_attachment_files( $file, $request_id, $subdir ) {
		$destination_path = trailingslashit( WP_CONTENT_DIR . '/uploads/' . $subdir );
		$destination_url  = trailingslashit( WP_CONTENT_URL . '/uploads/' . $subdir );

		if ( ! file_exists( $destination_path ) ) {
			wp_mkdir_p( $destination_path );
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$length         = isset( $file['name'] ) ? count( (array) $file['name'] ) : 0;
		$attachment_url = array();

		for ( $i = 0; $i < $length; $i++ ) {
			if ( empty( $file['tmp_name'][ $i ] ) ) {
				continue;
			}
			$file_name = sanitize_file_name( $i . $request_id . '_image.jpg' );
			$target    = $destination_path . $file_name;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents = file_get_contents( $file['tmp_name'][ $i ] );
			if ( false === $contents ) {
				continue;
			}
			$wp_filesystem->put_contents( $target, $contents, FS_CHMOD_FILE );

			$attachment_url[ $i . $request_id ] = $destination_url . $file_name;
		}
		return $attachment_url;
	}

	/**
	 * Code for initialize default value for email content
	 */
	public function generate_defaults() {

		$email_defaults = array(
			'customer_return_requested' => array(
				'subject'            => esc_html__( 'Your Return Request Has Been Received!', 'zorem-returns' ),
				'heading'            => esc_html__( 'Your Return Request Received!', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'Thank you for submitting your return request!<br><br>We’ve received your request and our team is currently reviewing it. You’ll hear from us with an update as soon as possible.', 'zorem-returns' ) ),
				'return_items_title' => esc_html__( 'Return Items Requested', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'admin_return_requested'    => array(
				'subject'            => esc_html__( 'New Return Request Received – Order', 'zorem-returns' ) . ' #{order_number}',
				'heading'            => esc_html__( 'A New Return Request Has Been Received', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'A new return request received:', 'zorem-returns' ) ),
				'return_items_title' => esc_html__( 'Return Items Requested', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'admin_return_approved'     => array(
				'subject'            => esc_html__( 'Return Request Approved', 'zorem-returns' ) . ': #{order_number}',
				'heading'            => esc_html__( 'Return Request Approved', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'I wanted to inform you that the return request for order #{order_number} has been approved by the admin. Below are the details:', 'zorem-returns' ) ),
				'return_items_title' => esc_html__( 'Approved Return Items', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'admin_return_cancelled'    => array(
				'subject'            => esc_html__( 'Return Request Cancelled', 'zorem-returns' ) . ': #{order_number}',
				'heading'            => esc_html__( 'Return Request Cancelled', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'I wanted to inform you that the return request for order #{order_number} has been cancelled by the customer. Below are the details:', 'zorem-returns' ) ),
				'return_items_title' => esc_html__( 'Items from Cancelled Return Request', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'customer_return_rejected'  => array(
				'subject'            => esc_html__( 'Your Return Request Has Been Rejected!', 'zorem-returns' ),
				'heading'            => esc_html__( 'Your Return Request Rejected!', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( "We're sorry to inform you that your return request has been rejected.<br><br> Please let us know if you have any questions or concerns.", 'zorem-returns' ) ),
				'return_items_title' => esc_html__( 'Rejected Return Items', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'customer_return_completed' => array(
				'subject'            => esc_html__( 'Your Return Has Been Completed', 'zorem-returns' ),
				'heading'            => esc_html__( 'Your Return Has Been Completed!', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'We are pleased to inform you that your return has been completed. The returned items have been received and verified. Your refund has been processed accordingly.', 'zorem-returns' ) ),
				'return_items_title' => esc_html__( 'Returned Items', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Thank you for your patience.', 'zorem-returns' ) . ' {site_title}',
			),
			'customer_return_approved'  => array(
				'subject'                  => esc_html__( 'Return Request Approved – Instructions for Sending Items Back', 'zorem-returns' ),
				'heading'                  => esc_html__( 'Your Return Request Has Been Approved!', 'zorem-returns' ),
				'email_content'            => htmlspecialchars_decode( __( 'Kindly return the item(s) to the following address', 'zorem-returns' ) ) . ':{store_address}<br>' . __( 'Phone', 'zorem-returns' ) . ':{phone_number}',
				'return_items_title'       => esc_html__( 'Approved Return Items', 'zorem-returns' ),
				'return_guideline_title'   => esc_html__( 'Return Guidelines', 'zorem-returns' ) . ':',
				'return_guideline_content' => htmlspecialchars_decode( __( '<strong>Return Guidelines:</strong><ul><li>Ensure all items being returned are securely packaged to prevent damage during transit.</li><li>Use a reliable shipping service to send the package back to us. We recommend using a service that provides tracking information.</li><li>Once your return package is shipped, please notify us by replying to this email with the tracking number.</li><li>Upon receiving the returned items, we will inspect them to ensure they meet our return policy criteria.</li><li>Refunds or store credits will be processed within 5–7 business days of receiving the returned items.</li></ul>', 'zorem-returns' ) ),
				'additional_content'       => htmlspecialchars_decode( __( 'If you have any questions or concerns about your return, feel free to contact our customer support team at [Customer Support Email/Phone Number].<br><br>Thank you for choosing ', 'zorem-returns' ) ) . '{site_title}' . htmlspecialchars_decode( __( '. We truly appreciate your business and look forward to serving you again soon.', 'zorem-returns' ) ),
			),
			'customer_return_store_credit' => array(
				'subject'            => esc_html__( 'Your Refund Has Been Issued as Store Credit!', 'zorem-returns' ),
				'heading'            => esc_html__( 'Your Refund Has Been Issued as Store Credit!', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'We’re happy to let you know that your return has been completed and your refund has been issued as store credit for your convenience.', 'zorem-returns' ) ),
				'return_items_title' => esc_html__( 'How to Use Your Store Credit', 'zorem-returns' ),
				'additional_content' => htmlspecialchars_decode( __( 'If you have any questions, feel free to reply to this email. <br><br>Thank you for shopping with ', 'zorem-returns' ) ) . '{site_title}.',
			),
			'admin_return_completed'    => array(
				'subject'            => esc_html__( 'Return Completed', 'zorem-returns' ) . ': #{order_number}',
				'heading'            => esc_html__( 'Return Completed', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'The return for order #{order_number} has been marked as completed. Below are the details:', 'zorem-returns' ) ),
				'return_items_title' => esc_html__( 'Returned Items', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'admin_return_rejected'     => array(
				'subject'            => esc_html__( 'Return Request Rejected', 'zorem-returns' ) . ': #{order_number}',
				'heading'            => esc_html__( 'Return Request Rejected', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'The return request for order #{order_number} has been rejected by the admin. Below are the details:', 'zorem-returns' ) ),
				'return_items_title' => esc_html__( 'Rejected Return Items', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
		);
		return $email_defaults;
	}

	/**
	 * Code for initialize default store adress for email content
	 */
	public function generate_default_store_address() {

		$settings_menu_value = zorem_returns_exchanges()->common->return_settings( 'return_store_address', array() );

		$store_address   = isset( $settings_menu_value['line'] ) ? $settings_menu_value['line'] : get_option( 'woocommerce_store_address' );
		$store_address_2 = isset( $settings_menu_value['line_2'] ) ? $settings_menu_value['line_2'] : get_option( 'woocommerce_store_address_2' );
		$store_city      = isset( $settings_menu_value['city'] ) ? $settings_menu_value['city'] : get_option( 'woocommerce_store_city' );
		$store_postcode  = isset( $settings_menu_value['postcode'] ) ? $settings_menu_value['postcode'] : get_option( 'woocommerce_store_postcode' );
		$store_country   = isset( $settings_menu_value['country'] ) ? $settings_menu_value['country'] : get_option( 'woocommerce_default_country' );

		// Construct the full address
		$full_address = $store_address;
		if ( ! empty( $store_address_2 ) ) {
			$full_address .= '<br> ' . $store_address_2;
		}
		if ( ! empty( $store_city ) ) {
			$full_address .= '<br> ' . $store_city;
		}
		if ( ! empty( $store_postcode ) ) {
			$full_address .= ', ' . $store_postcode;
		}
		if ( ! empty( $store_country ) ) {
			$split_country = explode( ':', $store_country );
			$full_address .= isset( $split_country[0] ) ? '<br> ' . WC()->countries->countries[ $split_country[0] ] : '';
		}

		// Output the full address
		return '<i>' . $full_address . '</i>';
	}

	/**
	 * Return option data
	 */
	public function return_settings( $key, $default ) {
		$return_general_settings_option = get_option( 'return_general_settings_option', array() );
		return isset( $return_general_settings_option[ $key ] ) ? $return_general_settings_option[ $key ] : $default;
	}

	public function update_return_settings( $key, $val ) {
		$return_general_settings_option         = get_option( 'return_general_settings_option', array() );
		$return_general_settings_option[ $key ] = $val;
		update_option( 'return_general_settings_option', $return_general_settings_option );
	}

	/*
	* Get data by id
	*/
	public function get_data_by_return_id( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'return_orders';
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
		$results = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %1s WHERE id = %2d', $table, $id ) );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
		return $results;
	}

	/**
	 * Get data by id
	 *
	 * @since  1.0.0
	 */
	public function get_data_by_order_id( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'return_orders';
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
		$results = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %1s WHERE order_id = %2d', $table, $id ) );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
		return $results;
	}

	/**
	 * Return statuses that must NOT reserve item quantity.
	 *
	 * A rejected or cancelled return never consumes the ordered quantity, so those
	 * units stay available for the customer to exchange (the return was declined, e.g.
	 * to let the customer exchange the items instead).
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_inactive_return_statuses() {
		return apply_filters( 'zorem_inactive_return_statuses', array( 'return-rejected', 'return-cancelled' ) );
	}

	/**
	 * Get only the "active" return rows for an order (excludes rejected/cancelled).
	 *
	 * Used by the exchange flow so that an item whose return request was rejected or
	 * cancelled becomes available for exchange again. The return flow keeps using
	 * get_data_by_order_id() so its own remaining-quantity logic is unaffected.
	 *
	 * @since 1.0.0
	 * @param int $id Order ID.
	 * @return array
	 */
	public function get_active_return_data_by_order_id( $id ) {
		$return_datas = $this->get_data_by_order_id( $id );
		if ( empty( $return_datas ) ) {
			return array();
		}

		$inactive = $this->get_inactive_return_statuses();
		$active   = array();
		foreach ( (array) $return_datas as $return_data ) {
			$status = isset( $return_data->return_status ) ? $return_data->return_status : '';
			if ( in_array( $status, $inactive, true ) ) {
				continue;
			}
			$active[] = $return_data;
		}
		return $active;
	}

	/**
	 * Get line item qty by item id
	 *
	 * @since  1.0.0
	 */
	public function get_returned_line_item_qty( $item_id, $return_datas ) {
		$qty = 0;
		foreach ( (array) $return_datas as $return_data ) {
			$return_items = unserialize( $return_data->return_items );
			$return_qty   = ( isset( $return_items[ $item_id ] ) && null != $return_items[ $item_id ] ) ? $return_items[ $item_id ]['return_product_qty'] : '0';
			$qty         += $return_qty;
		}
		return $qty;
	}

	/**
	 * Restock all returned items by return request ID.
	 *
	 * @param int $request_id The return request ID.
	 * @since 1.0.0
	 */
	public function restock_return_items_by_request_id( $request_id ) {
		global $wpdb;

		if ( ! $request_id ) {
			return;
		}

		// Prevent duplicate restocking
		$restocked_key = '_return_restocked_' . $request_id;
		if ( get_option( $restocked_key ) ) {
			return;
		}

		// Get return request row
		$return_data = zorem_returns_exchanges()->common->get_data_by_return_id( $request_id );

		$return_restock_items = zorem_returns_exchanges()->common->return_settings( 'return_restock_items', 'no' );

		if ( ! $return_data || empty( $return_data->return_items ) || 'no' === $return_restock_items ) {
			return;
		}

		$order = wc_get_order( $return_data->order_id ); // Get the order object by order ID

		$return_items = maybe_unserialize( $return_data->return_items ); // Unserialize return_items

		// Loop through and restock each item
		foreach ( $return_items as $item_id => $item_data ) {
			$qty = isset( $item_data['return_product_qty'] ) ? absint( $item_data['return_product_qty'] ) : 0;

			if ( $qty > 0 && $order ) {
				$item = $order->get_item( $item_id ); // Get the item by item ID
				if ( ! $item ) {
					continue;
				}

				// Check if this item is a variation
				if ( $item->get_variation_id() ) {
					$product_id = $item->get_variation_id(); // Get variation product ID
				} else {
					$product_id = $item->get_product_id(); // Get the main product ID
				}

				$product = wc_get_product( $product_id ); // Get the product (or variation) object
				if ( ! is_object( $product ) ) {
					continue;
				}
				if ( $product && $product->managing_stock() ) {
					$current_stock = $product->get_stock_quantity();
					$new_stock     = $current_stock + $qty;

					$product->set_stock_quantity( $new_stock );
					$product->save();
				}
			}
		}

		// Mark as restocked to prevent duplicate restocking
		update_option( $restocked_key, current_time( 'mysql' ) );
	}

	/**
	 * Display return requested data by request id
	 *
	 * @since  1.0.0
	 */
	public function display_return_data_request_id( $request_id ) {

		if ( empty( $request_id ) ) {
			return;
		}

		$return_data = zorem_returns_exchanges()->common->get_data_by_return_id( $request_id );

		$returns_status_instructions = array(
			'return-requested' => esc_html( apply_filters( 'zorem_returns_success_message_text', __( 'Your request has been received. Our customer service team will review it and get back to you within a few business days.', 'zorem-returns' ) ) ),
			'return-approved'  => esc_html__( 'Your return request has been approved. Please proceed with shipping the return item(s) back to us.', 'zorem-returns' ),
			'return-completed' => esc_html__( 'Your return has been completed. The returned items have been received and restocked. Your refund has been processed.', 'zorem-returns' ),
			'return-rejected'  => esc_html__( 'The return request has been rejected. Unfortunately, this order is not eligible for return based on our return/exchange policy.', 'zorem-returns' ),
			'return-cancelled' => esc_html__( 'Your return request has been cancelled.', 'zorem-returns' ),
		);

		if ( $return_data ) {
			?>
			<div class="return_request_content">
				<div class="return_request_header">
					<?php if ( 'return-rejected' == $return_data->return_status || 'return-cancelled' == $return_data->return_status ) { ?>
						<span class="dashicons dashicons-dismiss" ></span>
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
						<p class="return_issue_header">
							<?php esc_html_e( 'Returns message:', 'zorem-returns' ); ?>
						</p>
						<p class="return_issue_note">
							<?php esc_html_e( stripslashes( $return_data->return_issue ), 'zorem-returns' ); ?>
						</p>
					<?php } ?>
					<?php if ( ! empty( $return_data->return_note ) ) { ?>
						<p class="return_issue_header">
							<?php
							if ( 'return-rejected' == $return_data->return_status ) {
								esc_html_e( 'Return Rejected Note:', 'zorem-returns' );
							} else {
								esc_html_e( 'Return Approved Note:', 'zorem-returns' );
							}
							?>
						</p>
						<p class="return_issue_note">
							<?php esc_html_e( stripslashes( $return_data->return_note ), 'zorem-returns' ); ?>
						</p>
					<?php } ?>
					<p class="return_items_header">
						<?php esc_html_e( 'Items Approved for Return', 'zorem-returns' ); ?>
					</p>
					<div class="return_items_note">
						<?php
						$order = wc_get_order( $return_data->order_id );
						if ( is_a( $order, 'WC_Order' ) ) {
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
										$item_all_meta     = $item->get_all_formatted_meta_data();
										$quantity          = $item->get_quantity();
										// Amount actually paid per unit (after discount, incl tax); no integer
										// rounding, so it matches the refund and the admin summary.
										$item_line_gross   = (float) $item->get_total() + (float) $item->get_total_tax();
										$item_price        = $quantity > 0 ? ( $item_line_gross / $quantity ) : $item_line_gross;
									}
									?>
									<div class="product-details">
										<img src="<?php echo esc_url( $product_image_url ); ?>" alt="<?php echo esc_attr( $product_title ); ?>"/>
										<div class="product-detail">
											<p class="product-title"><?php echo esc_html( $product_title ); ?></p>
											<?php if ( $item_all_meta ) { ?>
												<p class="product-variation">
												<?php
												foreach ( $item_all_meta as $key => $item ) {
													$display_key   = $item->display_key;
													$display_value = wp_strip_all_tags( $item->display_value );
													echo wp_kses_post( '<strong>' . $display_key . '</strong> : ' . $display_value );

													$keys = array_keys( $item_all_meta );
													echo ( end( $keys ) !== $key ) ? ' | ' : '';
												}
												?>
												</p>
											<?php } ?>
											<div class="product-qty">
												<strong>Qty. <?php echo esc_html( $return_qty ); ?></strong>
											</div>
											<div><strong>price :</strong> <?php echo wp_kses_post( wc_price( $item_price ) ); ?></div>
										</div>
									</div>
									<?php
									// esc_html_e($product_name . ' x ' . $return_qty, 'zorem-returns');
									// echo '</li>';
								}
							}
						}
						?>
					</div>
					<?php if ( 'return-approved' == $return_data->return_status ) { ?>
						<?php $is_keep_the_item_view = isset( $return_data->keep_the_item ) && 'yes' === $return_data->keep_the_item; ?>

						<?php if ( $is_keep_the_item_view ) { ?>
							<div class="keep-item-notice">
								<strong><?php esc_html_e( 'Keep Your Item', 'zorem-returns' ); ?></strong>
								<p><?php esc_html_e( 'No return shipping required. Customer retains the product. Refund will be processed automatically on approval.', 'zorem-returns' ); ?></p>
							</div>
						<?php } else { ?>
							<?php
							$order_id    = $order->get_id();
							$customer_id = $order->get_customer_id();
							$customer          = $customer_id ? new WC_Customer( $customer_id ) : null;
							$customer_username = $customer && $customer->get_id() ? $customer->get_username() : '';
							$default_settings    = zorem_returns_exchanges()->common->generate_defaults();
							$settings_menu_value = zorem_returns_exchanges()->common->return_settings( 'return_guideline_content', $default_settings['customer_return_approved']['return_guideline_content'] );
							$return_guideline_content = str_replace( array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{store_address}', '{phone_number}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer_username, zorem_returns_exchanges()->common->generate_default_store_address(), zorem_returns_exchanges()->common->return_settings( 'return_store_number', '' ) ), $settings_menu_value );
							?>
							<div class="return_issue_note return_items_header"><?php echo isset( $return_guideline_content ) ? wp_kses_post( html_entity_decode( $return_guideline_content ) ) : ''; ?></div>
							<?php $store_address = zorem_returns_exchanges()->common->generate_default_store_address(); ?>
							<?php if ( ! empty( $store_address ) ) { ?>
								<div class="return_issue_note return_items_header">
									<p class="return_reason_header"><?php esc_html_e( 'Return Shipping Address', 'zorem-returns' ); ?></p>
									<?php echo wp_kses_post( html_entity_decode( zorem_returns_exchanges()->common->generate_default_store_address() ) ); ?>
								</div>
							<?php } ?>
							<?php if ( ! empty( $return_data->return_label_pdf ) && file_exists( $return_data->return_label_pdf ) ) { ?>
								<div class="return_label_download return_items_header">
									<p class="return_reason_header"><?php esc_html_e( 'Return Shipping Label', 'zorem-returns' ); ?></p>
									<p class="shipping_label"><?php esc_html_e( 'A return shipping label has been provided. Please download, print, and attach it to your return package.', 'zorem-returns' ); ?></p>
									<?php $download_nonce = wp_create_nonce( 'rt4wc_download_label' ); ?>
									<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=rt4wc_download_return_label&request_id=' . $return_data->id . '&nonce=' . $download_nonce ) ); ?>" class="button rt4wc-download-label-btn" target="_blank">
										<span class="dashicons dashicons-download"></span>
										<?php esc_html_e( 'Download Return Shipping Label', 'zorem-returns' ); ?>
									</a>
								</div>
							<?php } ?>
						<?php } ?>

					<?php } ?>
					<?php if ( 'return-requested' == $return_data->return_status ) { ?>
						<button type="button" class="return-request-cancel button" style="margin: 1em;" data-request_id="<?php echo esc_attr( $return_data->id ); ?>"><?php esc_html_e( 'Cancel Request', 'zorem-returns' ); ?></button>
						<?php $ajax_nonce = wp_create_nonce( 'return_request_cancel' ); ?>
						<input type="hidden" name="return_request_cancel_nonce" id="return_request_cancel_nonce" value="<?php echo esc_html( $ajax_nonce ); ?>" />
					<?php } ?>
				</div>
				
			</div>
			<?php
		}
	}
}
