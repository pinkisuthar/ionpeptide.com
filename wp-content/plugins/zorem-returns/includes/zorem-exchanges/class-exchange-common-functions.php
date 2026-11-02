<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.DynamicHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

class Exchange_Common_Functions {

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
		// Add 'Return Item' meta-box on Order page
		add_action( 'add_meta_boxes', array( $this, 'add_exchange_order_meta_box' ) );

		// Exchnage item details for emails
		add_action( 'woocommerce_exchange_email_order_details', array( $this, 'exchange_email_detail' ), 10, 3 );
		add_action( 'woocommerce_customer_exchange_email_order_details', array( $this, 'exchange_email_detail' ), 10, 3 );
		add_action( 'woocommerce_exchange_plain_email_order_details', array( $this, 'exchange_email_detail' ), 10, 3 );
		add_action( 'add_meta_boxes', array( $this, 'zorem_exchange_order_metabox' ), 10, 2 );
	}

	/**
	 * Add 'Return Item' meta-box to Orders page
	 *
	 * @since 1.0
	 */
	public function add_exchange_order_meta_box() {
		if ( class_exists( 'Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			$screen = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		} else {
			$screen = 'shop_order';
		}

		add_meta_box( 'exchange_order_meta_box', __( 'Exchange Requests', 'zorem-returns' ), array( $this, 'display_exchange_request_meta_box' ), $screen, 'normal', 'default' );
	}

	/**
	 * Display Order Meta box in admin order
	 *
	 * @since  1.0.0
	 */
	public function display_exchange_request_meta_box( $post_or_order_object ) {
		$order    = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;
		$order_id = $order->get_id();

		$exchange_datas = zorem_returns_exchanges()->exCommon->get_exchange_data_by_order_id( $order_id );

		// Items with exchangeable qty remaining — drives the admin "Create Exchange Request"
		// button. Only offered while the exchange module is active (exCenter holds the
		// AJAX handler and is not loaded when exchanges are disabled in settings).
		$exchangeable_items = ( isset( zorem_returns_exchanges()->exCenter ) && is_object( zorem_returns_exchanges()->exCenter ) )
			? $this->get_admin_exchangeable_items( $order, $exchange_datas )
			: array();

		if ( ! $exchange_datas && empty( $exchangeable_items ) ) {
			echo '<style>#exchange_order_meta_box{display:none;}</style>';
			return;
		}

		wp_enqueue_script( 'rt4wc_order_script', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/order.js', array( 'jquery' ), zorem_returns_exchanges()->version, true );

		// Admin-initiated exchange: support agents can file an exchange on the customer's behalf.
		if ( ! empty( $exchangeable_items ) ) {
			$this->render_admin_create_exchange_ui( $order, $exchangeable_items );
		}

		if ( ! $exchange_datas ) {
			return;
		}

		$count = count( $exchange_datas );
		$i     = 1;
		?>
		<input type="hidden" id="nonce_fullfillment_dashbaord" value="<?php echo esc_attr( wp_create_nonce( 'nonce_fullfillment_dashbaord' ) ); ?>">
		<div class="exchange_request_item">
			<?php
			foreach ( (array) $exchange_datas as $exchange_data ) {

				$exchange_reason = ( null != $exchange_data->exchange_reason ) ? unserialize( $exchange_data->exchange_reason ) : array();
				$no_other        = isset( $exchange_reason['no_other'] ) ? $exchange_reason['no_other'] : '';
				$other_reason    = isset( $exchange_reason['other_reason'] ) ? $exchange_reason['other_reason'] : '';
				$exchange_issue  = isset( $exchange_data->exchange_issue ) ? $exchange_data->exchange_issue : '';
				$exchange_note   = isset( $exchange_data->exchange_note ) ? $exchange_data->exchange_note : '';
				$exchange_status = isset( $exchange_data->exchange_status ) ? $exchange_data->exchange_status : '';
				$exchange_items  = ( null != $exchange_data->exchange_items ) ? unserialize( $exchange_data->exchange_items ) : array();

				if ( 1 < $count ) {
					echo '<h3>' . esc_html__( 'Exchange Request ', 'zorem-returns' ) . '#' . esc_html( $i ) . '</h3>';
					++$i;
				}

				echo '<div class="exchange-details">';
				?>
				<p class="items-status label"><label><?php esc_html_e( 'Status', 'zorem-returns' ); ?>: </label> <span class="exchange_status_label status-<?php echo wp_kses_post( $exchange_status ); ?> desc"><?php echo esc_html_e( ucfirst( str_replace( '-', ' ', $exchange_status ) ), 'zorem-returns' ); ?></span></p>
				<?php

				foreach ( $order->get_items() as $item_id => $item ) {
					if ( empty( $exchange_items[ $item_id ] ) ) {
						continue;
					}

					$exchange_qty = isset( $exchange_items[ $item_id ]['exchange_product_qty'] ) ? $exchange_items[ $item_id ]['exchange_product_qty'] : 0;
					if ( $exchange_qty <= 0 ) {
						continue;
					}

					$product_id = $item->get_product_id();
					$product    = wc_get_product( $product_id );
					if ( ! $product ) {
						continue;
					}

					$product_name  = $item->get_name();
					$quantity      = $item->get_quantity();
					$item_subtotal = round( $item->get_subtotal() + $item->get_subtotal_tax() );
					$item_price    = $item_subtotal / $quantity;

					// Build Original Variation
					$return_variation = '';
					$item_all_meta    = $item->get_all_formatted_meta_data();
					foreach ( $item_all_meta as $key => $meta ) {
						$return_variation .= wp_kses_post( $meta->display_key . ' : ' . wp_strip_all_tags( $meta->display_value ) );
						if ( next( $item_all_meta ) ) {
							$return_variation .= ' | ';
						}
					}

					echo '<h4>✅ ' . esc_html__( 'Original Item (Ordered)', 'zorem-returns' ) . '</h4>';
					echo '<table class="exchange-table">';
					echo '<tr><th>' . esc_html__( 'Product Name', 'zorem-returns' ) . '</th><td>' . esc_html( $product_name ) . '</td></tr>';
					echo '<tr><th>' . esc_html__( 'Variation', 'zorem-returns' ) . '</th><td>' . esc_html( $return_variation ) . '</td></tr>';
					echo '<tr><th>' . esc_html__( 'Quantity', 'zorem-returns' ) . '</th><td>' . esc_html( $exchange_qty ) . '</td></tr>';
					echo '<tr><th>' . esc_html__( 'Price', 'zorem-returns' ) . '</th><td>' . wp_kses_post( wc_price( $item_price ) ) . '</td></tr>';
					echo '</table>';

					echo '<p>⬇️ <strong>' . esc_html__( 'Exchange With', 'zorem-returns' ) . '</strong></p>';

					// Build Requested Item — handles BOTH exchange flows:
					//   - cross-product : customer picked a different product entirely
					//                     (target stored in $exchange_data->new_product_id / new_variation_id)
					//   - same-variation: customer picked a different variant of the same product
					//                     (target attrs stored in $exchange_items[$item_id]['variants'])
					$exchange_type = ! empty( $exchange_data->exchange_type ) ? $exchange_data->exchange_type : 'same-variation';

					if ( 'cross-product' === $exchange_type ) {
						// Per-item target when present (admin multi-item exchange), else row-level.
						list( $new_pid, $new_vid ) = $this->get_cross_target_ids_for_item( $exchange_data, $exchange_items, $item_id );
						$target = wc_get_product( $new_vid ? $new_vid : $new_pid );

						if ( $target ) {
							// Render the chosen variation attributes (if any) for a variable target.
							// Uses the stored choices for "Any …" attributes — THIS item's first.
							$target_variation = $this->get_cross_target_variation_text( $target, array( $exchange_items[ $item_id ] ), ' | ' );

							echo '<h4>' . esc_html__( 'Requested Item (Exchange)', 'zorem-returns' ) . '</h4>';
							echo '<table class="exchange-table">';
							echo '<tr><th>' . esc_html__( 'Product Name', 'zorem-returns' ) . '</th><td>' . esc_html( $target->get_name() ) . '</td></tr>';
							echo '<tr><th>' . esc_html__( 'Variation', 'zorem-returns' ) . '</th><td>' . esc_html( $target_variation ) . '</td></tr>';
							echo '<tr><th>' . esc_html__( 'Quantity', 'zorem-returns' ) . '</th><td>' . esc_html( $exchange_qty ) . '</td></tr>';
							echo '<tr><th>' . esc_html__( 'Price', 'zorem-returns' ) . '</th><td>' . wp_kses_post( wc_price( wc_get_price_including_tax( $target ) ) ) . '</td></tr>';
							echo '</table>';
						}
					} else {
						$attributes = isset( $exchange_items[ $item_id ]['variants'] ) ? $exchange_items[ $item_id ]['variants'] : array();
						if ( ! empty( $attributes ) ) {
							$variable_product_id = wc_get_order_item_meta( $item_id, '_product_id', true );
							$variation_id        = zorem_returns_exchanges()->exCommon->get_variation_id_by_attributes( $variable_product_id, $attributes );
							$product_variation   = wc_get_product( $variation_id );

							$exchange_variation_parts = array();
							foreach ( $attributes as $attr_key => $attr_value ) {
								$attr_label                 = wc_attribute_label( str_replace( 'attribute_', '', $attr_key ), $product );
								$term                       = get_term_by( 'slug', $attr_value, str_replace( 'attribute_', '', $attr_key ) );
								$display_value              = $term ? $term->name : ucfirst( $attr_value );
								$exchange_variation_parts[] = wp_kses_post( $attr_label . ' : ' . $display_value );
							}
							$exchange_variation = implode( ' | ', $exchange_variation_parts );

							echo '<h4>' . esc_html__( 'Requested Item (Exchange)', 'zorem-returns' ) . '</h4>';
							echo '<table class="exchange-table">';
							echo '<tr><th>' . esc_html__( 'Product Name', 'zorem-returns' ) . '</th><td>' . esc_html( $product_variation ? $product_variation->get_name() : '—' ) . '</td></tr>';
							echo '<tr><th>' . esc_html__( 'Variation', 'zorem-returns' ) . '</th><td>' . esc_html( $exchange_variation ) . '</td></tr>';
							echo '<tr><th>' . esc_html__( 'Quantity', 'zorem-returns' ) . '</th><td>' . esc_html( $exchange_qty ) . '</td></tr>';
							echo '<tr><th>' . esc_html__( 'Price', 'zorem-returns' ) . '</th><td>' . wp_kses_post( wc_price( $product_variation ? wc_get_price_including_tax( $product_variation ) : 0 ) ) . '</td></tr>';
							echo '</table>';
						}
					}
				}

				// Reason / Issue / Note
				$reason = ( 'other_reason' === $no_other ) ? stripslashes( $other_reason ) : stripslashes( $no_other );
				echo '<p><strong>Exchange Reason:</strong> ' . esc_html( $reason ) . '</p>';

				if ( ! empty( $exchange_issue ) ) {
					echo '<p><strong>Exchange Message:</strong> ' . esc_html( stripslashes( $exchange_issue ) ) . '</p>';
				}

				if ( 'exchange-requested' == $exchange_status ) {
					echo '<textarea name="exchange_note" id="exchange_note" rows="4" style="width: 100%" placeholder="' . esc_attr__( 'Add a note for exchange approval or rejection...', 'zorem-returns' ) . '"></textarea>';
					echo '<div class="actions-buttons">';
					echo '<button type="button" class="button wc-action-button exchange_approved_button" status="exchange-approved" id="' . esc_attr( $exchange_data->id ) . '">' . esc_html__( 'Approve', 'zorem-returns' ) . '</button>';
					echo '<button type="button" class="button wc-action-button exchange_rejected_button" status="exchange-rejected" id="' . esc_attr( $exchange_data->id ) . '">' . esc_html__( 'Reject', 'zorem-returns' ) . '</button>';
					echo '</div>';
				}

				echo '</div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Items that still have exchangeable quantity left (order qty minus qty already
	 * requested across every existing exchange AND return row for this order — a
	 * unit that was returned cannot also be exchanged, and vice versa).
	 *
	 * Same-variation flow only: an item qualifies when the product is variable and
	 * at least one equal-price replacement variant exists (mirrors the customer
	 * exchange form's eligibility).
	 *
	 * @param WC_Order $order          Order object.
	 * @param array    $exchange_datas Rows from get_exchange_data_by_order_id().
	 * @return array item_id => array( name, variation, max_qty, attributes )
	 * @since 1.6.9
	 */
	public function get_admin_exchangeable_items( $order, $exchange_datas ) {
		// Rejected/cancelled returns must not reserve quantity, so those units stay exchangeable.
		$return_datas = zorem_returns_exchanges()->common->get_active_return_data_by_order_id( $order->get_id() );

		// Cross-product exchange enabled → any purchasable product qualifies and the
		// replacement is chosen via product search (mirrors the customer form, which
		// swaps the variant pickers for the search widget when the setting is on).
		$cross_enabled = class_exists( 'Zorem_Exchange_Product_Search' ) && Zorem_Exchange_Product_Search::is_enabled();

		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! is_object( $product ) || $product->is_virtual() || $product->is_downloadable() ) {
				continue;
			}
			if ( $cross_enabled ) {
				if ( ! $product->is_purchasable() ) {
					continue;
				}
			} elseif ( ! in_array( $product->get_type(), array( 'variable', 'variation' ), true ) ) {
				// Same-variation flow: only variable products can be exchanged here.
				continue;
			}

			$remaining = $item->get_quantity()
				- (int) $this->get_exchanged_line_item_qty( $item_id, $exchange_datas )
				- (int) zorem_returns_exchanges()->common->get_returned_line_item_qty( $item_id, $return_datas );
			if ( $remaining <= 0 ) {
				continue;
			}

			if ( $cross_enabled ) {
				// Replacement is picked via the search widget — no variant pickers needed.
				$meta_parts = array();
				foreach ( $item->get_all_formatted_meta_data() as $meta ) {
					$meta_parts[] = wp_strip_all_tags( $meta->display_key . ': ' . $meta->display_value );
				}
				$items[ $item_id ] = array(
					'name'       => $item->get_name(),
					'variation'  => implode( ' | ', $meta_parts ),
					'max_qty'    => $remaining,
					'attributes' => array(),
				);
				continue;
			}

			// Equal-price replacement variants, keyed by attribute (same filter the
			// customer-facing exchange form uses). No equal-price variant → not exchangeable.
			$parent_product = wc_get_product( $item->get_product_id() );
			$attributes     = $this->get_filtered_variation_attributes_by_price( $item, $parent_product );
			if ( empty( $attributes ) ) {
				continue;
			}

			// Prepare attribute options for rendering: readable labels, sorted.
			$attributes_prepared = array();
			foreach ( $attributes as $attribute => $options ) {
				if ( empty( $options ) ) {
					continue;
				}
				$sorted_options = array();
				foreach ( $options as $option ) {
					$option_label = '';
					$option_value = $option;

					if ( taxonomy_exists( $attribute ) ) {
						if ( is_numeric( $option ) ) {
							$attribute_term = get_term( (int) $option, $attribute );
						} else {
							$attribute_term = get_term_by( 'slug', $option, $attribute );
						}
						if ( $attribute_term && ! is_wp_error( $attribute_term ) ) {
							$option_label = $attribute_term->name;
							$option_value = $attribute_term->slug;
						}
					}
					if ( '' === $option_label ) {
						$option_label = apply_filters( 'woocommerce_variation_option_name', $option );
					}
					$sorted_options[] = array(
						'label' => ucfirst( strtolower( $option_label ) ),
						'value' => $option_value,
					);
				}
				usort(
					$sorted_options,
					function ( $a, $b ) {
						return strcasecmp( $a['label'], $b['label'] );
					}
				);
				$attributes_prepared[ $attribute ] = array(
					'label'   => wc_attribute_label( $attribute ),
					'options' => $sorted_options,
				);
			}
			if ( empty( $attributes_prepared ) ) {
				continue;
			}

			$meta_parts = array();
			foreach ( $item->get_all_formatted_meta_data() as $meta ) {
				$meta_parts[] = wp_strip_all_tags( $meta->display_key . ': ' . $meta->display_value );
			}

			$items[ $item_id ] = array(
				'name'       => $item->get_name(),
				'variation'  => implode( ' | ', $meta_parts ),
				'max_qty'    => $remaining,
				'attributes' => $attributes_prepared,
			);
		}
		return $items;
	}

	/**
	 * "Create Exchange Request" button + modal on the admin order edit screen.
	 * Lets a support agent file an exchange on the customer's behalf, picking an
	 * equal-price replacement variant per item (same-variation flow, mirroring
	 * the customer exchange form). The customer-facing eligibility rules are
	 * surfaced as soft warnings instead of hard blocks — the admin decides.
	 *
	 * @param WC_Order $order              Order object.
	 * @param array    $exchangeable_items From get_admin_exchangeable_items().
	 * @since 1.6.9
	 */
	private function render_admin_create_exchange_ui( $order, $exchangeable_items ) {

		$cross_enabled = class_exists( 'Zorem_Exchange_Product_Search' ) && Zorem_Exchange_Product_Search::is_enabled();

		// Shared modal layout classes live in admin-create-return.css.
		wp_enqueue_style( 'zorem-admin-create-return', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/admin-create-return.css', array(), zorem_returns_exchanges()->version );
		wp_enqueue_style( 'zorem-admin-create-exchange', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/admin-create-exchange.css', array( 'zorem-admin-create-return' ), zorem_returns_exchanges()->version );
		if ( $cross_enabled ) {
			// Reuse the frontend search widget styles (.zre-cp-*) inside the modal.
			wp_enqueue_style( 'zre-cross-product', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/exchange-cross-product.css', array(), zorem_returns_exchanges()->version );
		}
		wp_enqueue_script( 'zorem-admin-create-exchange', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/admin-create-exchange.js', array( 'jquery' ), zorem_returns_exchanges()->version, true );
		wp_localize_script(
			'zorem-admin-create-exchange',
			'zorem_ace',
			array(
				'select_item'    => __( 'Please select a quantity for at least one item.', 'zorem-returns' ),
				'select_variant' => __( 'Please select a replacement variant for every exchanged item.', 'zorem-returns' ),
				'select_product' => __( 'Please select a replacement product (and variant) for every exchanged item.', 'zorem-returns' ),
				'select_reason'  => __( 'Please select an exchange reason.', 'zorem-returns' ),
				'network_error'  => __( 'Network error. Please try again.', 'zorem-returns' ),
				// Cross-product exchange config — same AJAX endpoints the frontend
				// search widget uses (nonce is shared with zre_search_products & co).
				'cross'          => $cross_enabled ? array(
					'nonce' => wp_create_nonce( 'zre_cross_product_nonce' ),
					'i18n'  => array(
						'searching'         => __( 'Searching…', 'zorem-returns' ),
						'no_results'        => __( 'No products found.', 'zorem-returns' ),
						'choose_option'     => __( 'Choose an option', 'zorem-returns' ),
						'combo_unavailable' => __( 'Sorry, this combination is unavailable.', 'zorem-returns' ),
					),
				) : false,
			)
		);

		// Soft warnings — customer-facing rules the admin flow deliberately bypasses.
		$warnings = array();
		if ( ! zorem_returns_exchanges()->common->is_order_valid_for_return( $order ) ) {
			$warnings[] = __( 'This order is outside the return window (or its status is not eligible), so the customer could not request this exchange themselves.', 'zorem-returns' );
		}
		if ( $this->is_payment_method_blocked_for_exchange( $order ) ) {
			$warnings[] = __( "This order's payment method is blocked for customer exchanges.", 'zorem-returns' );
		}

		// Same reasons config as the front-end exchange form.
		$default_exchange_reasons = array(
			__( 'Wrong Size or Fit', 'zorem-returns' ),
			__( 'Wrong Color or Style', 'zorem-returns' ),
			__( 'Wrong item was sent', 'zorem-returns' ),
			__( "Item defective or doesn't work", 'zorem-returns' ),
			__( 'Items or parts missing', 'zorem-returns' ),
		);
		$exchange_reasons         = zorem_returns_exchanges()->common->return_settings( 'exchange_reasons', $default_exchange_reasons );
		?>
		<div class="zorem-ace-open-wrap">
			<button type="button" class="button button-primary" id="zorem_ace_open"><?php esc_html_e( 'Create Exchange Request', 'zorem-returns' ); ?></button>
		</div>

		<div id="zorem_ace_modal" class="zorem-acr-modal" style="display:none;">
			<div class="zorem-acr-modal-content">
				<div class="zorem-acr-modal-header">
					<h2><?php esc_html_e( 'Create Exchange Request', 'zorem-returns' ); ?></h2>
					<button type="button" class="zorem-acr-close zorem-ace-close" aria-label="<?php esc_attr_e( 'Close', 'zorem-returns' ); ?>">&times;</button>
				</div>
				<div class="zorem-acr-modal-body">
					<?php foreach ( $warnings as $warning ) { ?>
						<div class="zorem-acr-warning"><?php echo esc_html( $warning ); ?></div>
					<?php } ?>

					<input type="hidden" id="zorem_ace_nonce" value="<?php echo esc_attr( wp_create_nonce( 'zorem_admin_create_exchange' ) ); ?>">
					<input type="hidden" id="zorem_ace_order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">

					<h4><?php esc_html_e( 'Items to exchange', 'zorem-returns' ); ?></h4>
					<table class="widefat striped zorem-ace-items">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Product', 'zorem-returns' ); ?></th>
								<th class="zorem-ace-qty-col"><?php esc_html_e( 'Qty', 'zorem-returns' ); ?></th>
								<th><?php esc_html_e( 'Exchange With', 'zorem-returns' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $exchangeable_items as $item_id => $row ) { ?>
							<tr>
								<td>
									<?php echo esc_html( $row['name'] ); ?>
									<?php if ( ! empty( $row['variation'] ) ) { ?>
										<br><small><?php echo esc_html( $row['variation'] ); ?></small>
									<?php } ?>
								</td>
								<td class="zorem-ace-qty-col">
									<select class="zorem-ace-qty" data-item-id="<?php echo esc_attr( $item_id ); ?>">
										<?php for ( $q = 0; $q <= $row['max_qty']; $q++ ) { ?>
											<option value="<?php echo esc_attr( $q ); ?>"><?php echo esc_html( $q ); ?></option>
										<?php } ?>
									</select>
								</td>
								<?php if ( $cross_enabled ) { ?>
								<td class="zorem-ace-cp-cell">
									<!-- Per-item cross-product search: each item picks its OWN
									     replacement product (mirrors the customer per-item flow).
									     Same widget structure + endpoints as the customer form. -->
									<div class="zre-cross-product-wrap zorem-ace-cp-wrap" data-item-id="<?php echo esc_attr( $item_id ); ?>">
										<div class="zre-cp-search-box">
											<input type="text" class="zre-cp-search-input" placeholder="<?php esc_attr_e( 'Type a product name…', 'zorem-returns' ); ?>" autocomplete="off">
											<div class="zre-cp-results" style="display:none;"></div>
										</div>
										<!-- Selected product card -->
										<div class="zre-cp-selected" style="display:none;">
											<div class="zre-cp-selected-inner">
												<img class="zre-cp-selected-img" src="" alt="">
												<div class="zre-cp-selected-info">
													<span class="zre-cp-selected-name"></span>
													<span class="zre-cp-selected-price"></span>
												</div>
												<button type="button" class="zre-cp-clear">&times;</button>
											</div>
											<!-- Variation picker (shown for variable products) -->
											<div class="zre-cp-variation-wrap" style="display:none;">
												<div class="zre-cp-attr-selects"></div>
												<p class="zre-cp-attr-price" style="display:none;"></p>
												<p class="zre-cp-attr-msg" style="display:none;"></p>
											</div>
											<!-- Price difference banner -->
											<div class="zre-cp-price-banner" style="display:none;"></div>
										</div>
									</div>
								</td>
								<?php } else { ?>
								<td class="zorem-ace-variants">
									<?php foreach ( $row['attributes'] as $attribute => $attr_data ) { ?>
										<label><?php echo esc_html( $attr_data['label'] ); ?></label>
										<select class="zorem-ace-variant" data-item-id="<?php echo esc_attr( $item_id ); ?>" data-attr="<?php echo esc_attr( $attribute ); ?>">
											<option value=""><?php esc_html_e( 'Select an Option', 'zorem-returns' ); ?></option>
											<?php foreach ( $attr_data['options'] as $opt ) { ?>
												<option value="<?php echo esc_attr( $opt['value'] ); ?>"><?php echo esc_html( $opt['label'] ); ?></option>
											<?php } ?>
										</select>
									<?php } ?>
								</td>
								<?php } ?>
							</tr>
						<?php } ?>
						</tbody>
					</table>

					<?php if ( $cross_enabled ) { ?>
						<p class="description zorem-ace-equal-note"><?php esc_html_e( 'Pick a replacement product for every item you exchange — each item gets its own exchange request.', 'zorem-returns' ); ?></p>
					<?php } else { ?>
						<p class="description zorem-ace-equal-note"><?php esc_html_e( 'Items can only be exchanged for a variant of equal value.', 'zorem-returns' ); ?></p>
					<?php } ?>

					<p>
						<label for="zorem_ace_reason"><strong><?php esc_html_e( 'Exchange Reason', 'zorem-returns' ); ?></strong></label>
						<select id="zorem_ace_reason" class="widefat">
							<?php foreach ( (array) $exchange_reasons as $value ) { ?>
								<?php if ( ! empty( $value ) ) { ?>
									<option value="<?php echo esc_attr( stripslashes( $value ) ); ?>"><?php echo esc_html( stripslashes( $value ) ); ?></option>
								<?php } ?>
							<?php } ?>
							<option value="other_reason"><?php esc_html_e( 'Other (add details below)', 'zorem-returns' ); ?></option>
						</select>
					</p>
					<p class="zorem-ace-other-wrap" style="display:none;">
						<textarea id="zorem_ace_other_reason" class="widefat" rows="2" placeholder="<?php esc_attr_e( 'Reason details…', 'zorem-returns' ); ?>"></textarea>
					</p>
					<p>
						<label for="zorem_ace_issue"><strong><?php esc_html_e( 'Issue description (optional)', 'zorem-returns' ); ?></strong></label>
						<textarea id="zorem_ace_issue" class="widefat" rows="2"></textarea>
					</p>
					<p>
						<label for="zorem_ace_status"><strong><?php esc_html_e( 'Initial status', 'zorem-returns' ); ?></strong></label>
						<select id="zorem_ace_status" class="widefat">
							<option value="exchange-approved" selected><?php esc_html_e( 'Approved', 'zorem-returns' ); ?></option>
							<option value="exchange-requested"><?php esc_html_e( 'Requested', 'zorem-returns' ); ?></option>
						</select>
					</p>
					<p>
						<label><input type="checkbox" id="zorem_ace_notify" checked> <?php esc_html_e( 'Send notification email to the customer', 'zorem-returns' ); ?></label>
					</p>
					<div class="zorem-acr-error zorem-ace-error" style="display:none;"></div>
				</div>
				<div class="zorem-acr-modal-footer">
					<button type="button" class="button zorem-ace-close"><?php esc_html_e( 'Cancel', 'zorem-returns' ); ?></button>
					<button type="button" class="button button-primary" id="zorem_ace_submit"><?php esc_html_e( 'Create Exchange', 'zorem-returns' ); ?></button>
					<span class="spinner zorem-acr-spinner zorem-ace-spinner"></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Custom email for exchange for admin
	 *
	 * @since  1.0.0
	 */
	public function exchange_email_detail( $request_id, $order, $email ) {

		// When the admin has enabled "Show Original → Requested Exchange Table
		// in Customer Emails" in Exchange Settings, render the same comparison
		// table the admin emails use (original product alongside the requested
		// exchange) instead of the basic single-column items list below.
		$show_swap = 'yes' === zorem_returns_exchanges()->common->return_settings( 'show_exchange_swap_in_customer_emails', 'no' );
		if ( $show_swap ) {
			echo wp_kses_post( $this->exchange_swap_table_html( $request_id, $order ) );
			return;
		}

		$order_id = $order->get_id();
		?>
		<table width="100%" style="border:1px solid #e5e5e5;border-collapse: collapse;text-align: center;margin: 0 0 16px">
		<tbody>
			<tr style="border-bottom:1px solid #e5e5e5;">
				<th style="border-right:1px solid #e5e5e5;font-size: 14px;color: #636363;"><?php esc_html_e( 'Product Name', 'zorem-returns' ); ?></th>
				<th style="border-right:1px solid #e5e5e5;font-size: 14px;color: #636363;"><?php esc_html_e( 'Product Price', 'zorem-returns' ); ?></th>
				<th style="border-right:1px solid #e5e5e5;font-size: 14px;color: #636363;"><?php esc_html_e( 'Requested Quantity', 'zorem-returns' ); ?></th>
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
				$exchange_data  = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $request_id );
				$exchange_items = unserialize( $exchange_data->exchange_items );
				foreach ( $order->get_items() as $item_id => $item ) {
					$product = wc_get_product( $item['product_id'] );
					if ( ! is_object( $product ) ) {
						continue;
					}
					$product_price = wc_price( $order->get_item_subtotal( $item, true, true ), array( 'currency' => $order->get_currency() ) );
					$qty           = isset( $exchange_items[ $item_id ]['exchange_product_qty'] ) ? $exchange_items[ $item_id ]['exchange_product_qty'] : '0';

					$is_visible        = $product->is_visible();
					$product_permalink = apply_filters( 'woocommerce_order_item_permalink', $is_visible ? $product->get_permalink( $item ) : '', $item, $order );
					$product_name      = apply_filters( 'woocommerce_order_item_name', $product_permalink ? sprintf( '<a href="%s">%s</a>', $product_permalink, $item->get_name() ) : $item->get_name(), $item, $is_visible );

					if ( isset( $exchange_data->exchange_the_entire_order ) && 1 == $exchange_data->exchange_the_entire_order && $qty > 0 ) {
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

	/**
	 * Render an "Original → Requested Exchange" comparison table for use in
	 * admin notification emails (request, approved, completed, rejected).
	 *
	 * Handles both exchange flows:
	 *   - same-variation: target shares the parent product_id, only variant attrs change.
	 *   - cross-product : target is an entirely different product (and/or variation).
	 *
	 * Returns inline-styled HTML so it renders correctly in email clients without
	 * relying on the WooCommerce email stylesheet.
	 *
	 * @param int      $request_id Exchange request row id from {prefix}exchange_orders.
	 * @param WC_Order $order      Parent order containing the items the customer is exchanging.
	 * @return string HTML, or empty string when nothing to display.
	 */
	public function exchange_swap_table_html( $request_id, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$exchange_data = $this->get_data_by_exchange_id( $request_id );
		if ( ! $exchange_data ) {
			return '';
		}

		$exchange_items = ! empty( $exchange_data->exchange_items ) ? unserialize( $exchange_data->exchange_items ) : array();
		if ( empty( $exchange_items ) ) {
			return '';
		}

		$exchange_type = ! empty( $exchange_data->exchange_type ) ? $exchange_data->exchange_type : 'same-variation';
		$currency_args = array( 'currency' => $order->get_currency() );

		$rows_html = '';
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! isset( $exchange_items[ $item_id ] ) ) {
				continue;
			}
			$qty = isset( $exchange_items[ $item_id ]['exchange_product_qty'] ) ? (int) $exchange_items[ $item_id ]['exchange_product_qty'] : 0;
			if ( $qty <= 0 ) {
				continue;
			}

			// ---- Original side ----
			$original_name  = $item->get_name();
			$original_price = wc_price( $order->get_item_subtotal( $item, true, true ), $currency_args );

			// ---- Requested side ----
			$requested_name  = '—';
			$requested_price = '';

			if ( 'cross-product' === $exchange_type ) {
				// Per-item target when present (admin multi-item exchange), else row-level.
				list( $new_pid, $new_vid ) = $this->get_cross_target_ids_for_item( $exchange_data, $exchange_items, $item_id );
				$target = wc_get_product( $new_vid ? $new_vid : $new_pid );
				if ( $target ) {
					$requested_name  = $target->get_formatted_name();
					$requested_price = wc_price( wc_get_price_including_tax( $target ), $currency_args );
				}
			} else {
				// Same-variation: same product, different variant attributes.
				$product_id   = (int) wc_get_order_item_meta( $item_id, '_product_id', true );
				$attributes   = isset( $exchange_items[ $item_id ]['variants'] ) ? $exchange_items[ $item_id ]['variants'] : array();
				$variation_id = ( $product_id && ! empty( $attributes ) ) ? $this->get_variation_id_by_attributes( $product_id, $attributes ) : 0;
				$target       = $variation_id ? wc_get_product( $variation_id ) : ( $product_id ? wc_get_product( $product_id ) : false );

				if ( $target ) {
					$requested_name  = $target->get_formatted_name();
					$requested_price = wc_price( wc_get_price_including_tax( $target ), $currency_args );
				} elseif ( ! empty( $attributes ) ) {
					// Fall back: display the requested attribute labels even when
					// no matching variation exists (e.g. plugin variant pricing).
					$parts = array();
					foreach ( $attributes as $attr_key => $attr_val ) {
						$attr_label = wc_attribute_label( $attr_key );
						if ( taxonomy_exists( $attr_key ) ) {
							$term     = get_term_by( 'slug', $attr_val, $attr_key );
							$attr_val = $term ? $term->name : $attr_val;
						}
						$parts[] = $attr_label . ': ' . ucfirst( $attr_val );
					}
					$requested_name = $item->get_name() . ( $parts ? ' (' . implode( ' / ', $parts ) . ')' : '' );
				}
			}

			$cell_style = 'border-right:1px solid #e5e5e5;padding:8px;font-size:14px;color:#636363;vertical-align:top;';
			$last_cell  = 'padding:8px;font-size:14px;color:#636363;vertical-align:top;text-align:center;';

			$rows_html .= '<tr style="border-bottom:1px solid #e5e5e5;">';
			$rows_html .= '<td style="' . $cell_style . '">' . wp_kses_post( $original_name ) . '<br><small>' . wp_kses_post( $original_price ) . '</small></td>';
			$rows_html .= '<td style="' . $cell_style . '">' . wp_kses_post( $requested_name );
			$rows_html .= $requested_price ? '<br><small>' . wp_kses_post( $requested_price ) . '</small>' : '';
			$rows_html .= '</td>';
			$rows_html .= '<td style="' . $last_cell . '">' . esc_html( $qty ) . '</td>';
			$rows_html .= '</tr>';
		}

		if ( '' === $rows_html ) {
			return '';
		}

		$head_style = 'border-right:1px solid #e5e5e5;padding:8px;font-size:14px;color:#636363;text-align:left;';
		$head_last  = 'padding:8px;font-size:14px;color:#636363;text-align:center;';

		$html  = '<h3 style="margin:16px 0 8px;">' . esc_html__( 'Exchange Request Summary', 'zorem-returns' ) . '</h3>';
		$html .= '<table width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e5e5e5;border-collapse:collapse;margin:0 0 16px;">';
		$html .= '<thead><tr style="border-bottom:1px solid #e5e5e5;background:#f6f6f6;">';
		$html .= '<th style="' . $head_style . '">' . esc_html__( 'Original Product', 'zorem-returns' ) . '</th>';
		$html .= '<th style="' . $head_style . '">' . esc_html__( 'Requested Exchange', 'zorem-returns' ) . '</th>';
		$html .= '<th style="' . $head_last . '">' . esc_html__( 'Quantity', 'zorem-returns' ) . '</th>';
		$html .= '</tr></thead><tbody>';
		$html .= $rows_html;
		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * Check if the order's payment method is in the admin's "disabled for exchanges" list.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool True if the order's payment gateway is blocked from exchanges.
	 */
	public function is_payment_method_blocked_for_exchange( $order ) {
		if ( ! $order || ! is_object( $order ) ) {
			return false;
		}
		$blocked = zorem_returns_exchanges()->common->return_settings( 'exchange_disabled_payment_methods', array() );
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
	 * Get the admin-configured custom message for exchange-blocked payment methods.
	 *
	 * @param WC_Order $order Order object.
	 * @return string Message to display to the customer.
	 */
	public function get_exchange_blocked_message( $order ) {
		$default = __( 'Exchanges are not available for Cash on Delivery orders. Please contact us directly.', 'zorem-returns' );
		$message = zorem_returns_exchanges()->common->return_settings( 'exchange_disabled_payment_message', $default );
		if ( empty( $message ) ) {
			$message = $default;
		}
		return apply_filters( 'zorem_exchange_blocked_message', $message, $order );
	}

	/**
	 * Is item object is valid for exchange
	 *
	 * @since  1.0.0
	 */
	public function is_item_valid_for_exchange( $item ) {

		$eligible = true;

		// get settings data
		$exclude_products      = zorem_returns_exchanges()->common->return_settings( 'return_exclude_products', array() );
		$exclude_categories    = zorem_returns_exchanges()->common->return_settings( 'return_exclude_categories', array() );
		$exchange_onsale_items = zorem_returns_exchanges()->common->return_settings( 'exchange_onsale_items', 'no' );

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
		if ( 'no' == $exchange_onsale_items ) {
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
	public function get_exchanged_form_data( $order ) {

		// get order data
		$order_id       = $order->get_id();
		$exchange_datas = $this->get_exchange_data_by_order_id( $order_id );
		// Rejected/cancelled returns must not reserve quantity, so those units stay exchangeable.
		$return_datas   = zorem_returns_exchanges()->common->get_active_return_data_by_order_id( $order_id );

		$exchange_form_data = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( empty( $product ) || ( $product->is_virtual() || $product->is_downloadable() ) ) {
				continue;
			}

			// When cross-product exchange is enabled, any purchasable product type is eligible.
			// Otherwise restrict to variable/variation only (original behaviour).
			$cross_enabled = class_exists( 'Zorem_Exchange_Product_Search' ) && Zorem_Exchange_Product_Search::is_enabled();
			if ( ! $cross_enabled && 'variable' != $product->get_type() && 'variation' != $product->get_type() ) {
				continue;
			}

			// exchange Qty
			$exchanged_qty = $this->get_exchanged_line_item_qty( $item_id, $exchange_datas );
			$returned_qty  = zorem_returns_exchanges()->common->get_returned_line_item_qty( $item_id, $return_datas );

			$product_quantity        = $item['quantity'] - $exchanged_qty;
			$return_product_quantity = $item['quantity'] - $returned_qty;
			if ( isset( $product_quantity ) && ( 0 == $product_quantity || 0 == $return_product_quantity ) ) {
				continue;
			}

			// prepare exchange item data
			if ( isset( $product_quantity ) && 0 < $product_quantity ) {
				$exchange_form_data[ $item->get_id() ]['product_name']     = $item['name'];
				$exchange_form_data[ $item->get_id() ]['product_id']       = $item['product_id'];
				$exchange_form_data[ $item->get_id() ]['product_quantity'] = $product_quantity;
			}
		}
		return $exchange_form_data;
	}

	/*
	* Get data by id
	*/
	public function get_data_by_exchange_id( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'exchange_orders';
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
	public function get_exchange_data_by_order_id( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'exchange_orders';
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
		$results = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %1s WHERE order_id = %2d', $table, $id ) );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
		return $results;
	}

	/**
	 * Get line item qty by item id
	 *
	 * @since  1.0.0
	 */
	public function get_exchanged_line_item_qty( $item_id, $exchange_datas ) {
		$qty = 0;
		foreach ( (array) $exchange_datas as $exchange_data ) {
			$exchange_items = unserialize( $exchange_data->exchange_items );
			$exchange_qty   = ( isset( $exchange_items[ $item_id ] ) && null != $exchange_items[ $item_id ] ) ? $exchange_items[ $item_id ]['exchange_product_qty'] : '0';
			$qty           += $exchange_qty;
		}
		return $qty;
	}

	/**
	 * Restock all exchanged items by exchange request ID.
	 *
	 * @param int $request_id The exchange request ID.
	 * @since 1.0.0
	 */
	public function restock_exchange_items_by_request_id( $request_id ) {
		global $wpdb;

		if ( ! $request_id ) {
			return;
		}

		// Get exchange request row
		$exchange_data = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $request_id );

		$return_restock_items = zorem_returns_exchanges()->common->return_settings( 'return_restock_items', 'no' );

		if ( ! $exchange_data || empty( $exchange_data->exchange_items ) || 'no' === $return_restock_items ) {
			return;
		}

		$order = wc_get_order( $exchange_data->order_id ); // Get the order object by order ID

		$exchange_items = maybe_unserialize( $exchange_data->exchange_items ); // Unserialize exchange_items

		// Loop through and restock each item
		foreach ( $exchange_items as $item_id => $item_data ) {
			$qty = isset( $item_data['exchange_product_qty'] ) ? absint( $item_data['exchange_product_qty'] ) : 0;

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
	}

	/**
	 * Display exchange requested data by request id
	 *
	 * @since  1.0.0
	 */
	public function display_exchange_data_request_id( $request_id ) {

		if ( empty( $request_id ) ) {
			return;
		}

		$exchange_data = $this->get_data_by_exchange_id( $request_id );
		$is_keep_the_item_view = ( isset( $exchange_data->keep_the_item ) && 'yes' === $exchange_data->keep_the_item );

		$exchanges_status_instructions = array(
			'exchange-requested'  => esc_html( apply_filters( 'zorem_returns_success_message_text', __( 'Your request has been received. Our customer service team will review it and get back to you within a few business days.', 'zorem-returns' ) ) ),
			'exchange-approved'   => esc_html__( 'Your exchange request has been approved. Please ship the item(s) to the return address provided.', 'zorem-returns' ),
			'exchange-completed'  => esc_html__( 'Your exchange request has been completed successfully.', 'zorem-returns' ),
			'exchange-rejected'   => esc_html__( 'The exchange request has been rejected. Unfortunately, this order is not eligible for exchange based on our return/exchange policy.', 'zorem-returns' ),
			'exchange-cancelled'  => esc_html__( 'Your exchange request has been cancelled.', 'zorem-returns' ),
		);

		if ( $exchange_data ) {
			?>
			<div class="exchange_request_content">
				<div class="exchange_request_header">
					<?php if ( 'exchange-rejected' == $exchange_data->exchange_status || 'exchange-cancelled' == $exchange_data->exchange_status ) { ?>
						<span class="dashicons dashicons-dismiss"></span>
					<?php } else { ?>
						<span class="dashicons dashicons-yes-alt"></span>
					<?php } ?>
					<h4>
						<?php esc_html_e( ucfirst( str_replace( '-', ' ', $exchange_data->exchange_status ) ), 'zorem-returns' ); ?>
					</h4>
				</div>
				<?php
				if ( 'exchange-approved' === $exchange_data->exchange_status && $is_keep_the_item_view ) {
					$exchanges_status_instruction = esc_html__( 'Your exchange request has been approved. You may keep the item. Your replacement will be dispatched automatically.', 'zorem-returns' );
				} else {
					$exchanges_status_instruction = apply_filters( 'customer_exchange_status_instructions', $exchanges_status_instructions[ $exchange_data->exchange_status ], $exchange_data->exchange_status );
				}
				?>
				<p class="exchange_request_note">
					<?php esc_html_e( $exchanges_status_instruction, 'zorem-returns' ); ?>
				</p>
				<div class="exchange_request_details">
					<p class="exchange_reason_header">
						<?php esc_html_e( 'Exchange Reason:', 'zorem-returns' ); ?>
					</p>
					<p class="exchange_reason_note">
						<?php
						$exchange_reason = ( null != $exchange_data->exchange_reason ) ? unserialize( $exchange_data->exchange_reason ) : array();
						if ( 'other_reason' == $exchange_reason['no_other'] ) {
							esc_html_e( stripslashes( $exchange_reason['other_reason'] ), 'zorem-returns' );
						} else {
							esc_html_e( stripslashes( $exchange_reason['no_other'] ), 'zorem-returns' );
						}
						?>
					</p>
					<?php if ( ! empty( $exchange_data->exchange_issue ) ) { ?>
						<p class="exchange_issue_header">
							<?php esc_html_e( 'Customer Message:', 'zorem-returns' ); ?>
						</p>
						<p class="exchange_issue_note">
							<?php esc_html_e( stripslashes( $exchange_data->exchange_issue ), 'zorem-returns' ); ?>
						</p>
					<?php } ?>
					<?php if ( ! empty( $exchange_data->exchange_note ) ) { ?>
						<p class="exchange_issue_header">
							<?php
							if ( 'return-rejected' == $exchange_data->exchange_status ) {
								esc_html_e( 'Exchange Rejected Note:', 'zorem-returns' );
							} else {
								esc_html_e( 'Exchange Approved Note:', 'zorem-returns' );
							}
							?>
						</p>
						<p class="exchange_issue_note">
							<?php esc_html_e( stripslashes( $exchange_data->exchange_note ), 'zorem-returns' ); ?>
						</p>
					<?php } ?>
					<p class="exchange_items_header">
						<?php esc_html_e( 'Exchanged Product Summary', 'zorem-returns' ); ?>
					</p>
					<div class="exchange_items_note">
						<?php if ( null != $exchange_data->exchange_items ) { ?>
							<div class="product-details heading">
								<span><?php esc_html_e( 'Original Item (Ordered)', 'zorem-returns' ); ?></span>
								<span><?php esc_html_e( 'Requested Item (Exchange)', 'zorem-returns' ); ?></span>
							</div>
						<?php } ?>
						<?php
						$order = wc_get_order( $exchange_data->order_id );
						if ( is_a( $order, 'WC_Order' ) ) {
							foreach ( $order->get_items() as $item_id => $item ) {
								$exchange_items = ( null != $exchange_data->exchange_items ) ? unserialize( $exchange_data->exchange_items ) : array();
								$product_name   = $item->get_name();
								$exchange_qty   = isset( $exchange_items[ $item_id ]['exchange_product_qty'] ) ? $exchange_items[ $item_id ]['exchange_product_qty'] : 0;
								if ( 0 < $exchange_qty ) {
									// Reset per item — a stale value from the previous
									// iteration must never render as THIS item's target.
									$variation_name      = '';
									$variation_image_url = '';
									$variation_price     = '';
									$attributes          = array();
									if ( is_a( $item, 'WC_Order_Item_Product' ) ) {
										$product_id = $item->get_product_id();
										$product    = wc_get_product( $product_id );
										if ( ! is_object( $product ) ) {
											continue;
										}
										$product_image_url = wp_get_attachment_url( $product->get_image_id() );
										$item_all_meta     = $item->get_all_formatted_meta_data();
										$product_title     = $product->get_name();
										$quantity          = $item->get_quantity();
										$item_subtotal     = round( $item->get_subtotal() + $item->get_subtotal_tax() );
										$item_price        = $item_subtotal / $quantity;
									}
									?>
									<div class="exchange-summary-row">
									<div class="product-details original-items">
										<img src="<?php echo esc_url( $product_image_url ); ?>" alt="<?php echo esc_attr( $product_title ); ?>"/>
										<div class="product-detail">
											<p class="product-title"><?php echo esc_html( $product_title ); ?></p>
											<?php if ( $item_all_meta ) { ?>
												<p class="product-variation">
												<?php
												foreach ( $item_all_meta as $key => $item ) {
													$display_key   = $item->display_key;
													$display_value = wp_strip_all_tags( $item->display_value );
													echo wp_kses_post( $display_key . ' : ' . $display_value );

													$keys = array_keys( $item_all_meta );
													echo ( end( $keys ) !== $key ) ? ' | ' : '';
												}
												?>
												</p>
											<?php } ?>
											<div class="product-qty">
												Qty. <?php echo esc_html( $exchange_qty ); ?>
											</div>
											<div>price : <?php echo wp_kses_post( wc_price( $item_price ) ); ?></div>
										</div>
									</div>
									<?php
									// Cross-product exchange: use new_product_id / new_variation_id columns.
									// Prices are displayed using wc_get_price_including_tax() so the
									// customer sees the same gross price they'd see on the shop page
									// (matching the original-item column, which already includes tax).
									$is_cross = ( isset( $exchange_data->exchange_type ) && 'cross-product' === $exchange_data->exchange_type );
									if ( $is_cross ) {
										// Per-item target when present (admin multi-item exchange), else row-level.
										list( $new_pid, $new_vid ) = $this->get_cross_target_ids_for_item( $exchange_data, $exchange_items, $item_id );
										$new_obj = wc_get_product( ! empty( $new_vid ) ? $new_vid : $new_pid );
										if ( $new_obj ) {
											$variation_image_url = wp_get_attachment_url( $new_obj->get_image_id() ) ? wp_get_attachment_url( $new_obj->get_image_id() ) : wc_placeholder_img_src();
											$variation_name      = $new_obj->get_name();
											$variation_price     = wc_get_price_including_tax( $new_obj );
										}
									} else {
										$attributes = isset( $exchange_items[ $item_id ]['variants'] ) ? $exchange_items[ $item_id ]['variants'] : array();
										if ( ! empty( $attributes ) ) {
											$variable_product_id = wc_get_order_item_meta( $item_id, '_product_id', true );
											$variation_id        = zorem_returns_exchanges()->exCommon->get_variation_id_by_attributes( $variable_product_id, $attributes );
											$product             = wc_get_product( $variable_product_id );
											$product_variation   = wc_get_product( $variation_id );
											// No `continue` here — the original block is already
											// rendered, so bail out of the pair gracefully instead
											// of breaking the row structure.
											if ( is_object( $product ) && is_object( $product_variation ) ) {
												$variation_image_url = wp_get_attachment_url( $product_variation->get_image_id() );
												$variation_name      = $product->get_name();
												$variation_price     = wc_get_price_including_tax( $product_variation );
											}
										}
									}
									?>
									<?php if ( ! empty( $variation_name ) ) : ?>
									<div class="product-details exchange-items">
										<img src="<?php echo esc_url( $variation_image_url ); ?>" alt="<?php echo esc_attr( $variation_name ); ?>"/>
										<div class="product-detail">
											<p class="product-title"><?php echo esc_html( $variation_name ); ?></p>

											<?php if ( ! $is_cross && $item_all_meta ) { // For cross-product, don't show ORIGINAL item's meta ?>
												<p class="product-variation">
													<?php
													foreach ( $item_all_meta as $key => $item ) {
														$display_key   = $item->display_key;
														$display_value = '';

														if ( isset( $item->key ) ) {
															$attribute       = $item->key;
															$attribute_slug  = str_replace( 'attribute_', '', $attribute );
															$attribute_value = isset( $attributes[ $item->key ] ) ? $attributes[ $item->key ] : '';

															$attr_label = wc_attribute_label( $attribute_slug );

															// ✅ Get readable value label (e.g. large-3 -> XXX-Large)
															if ( taxonomy_exists( $attribute_slug ) ) {
																if ( is_numeric( $attribute_value ) ) {
																	$term = get_term( (int) $attribute_value, $attribute_slug );
																} else {
																	$term = get_term_by( 'slug', $attribute_value, $attribute_slug );
																}

																if ( $term && ! is_wp_error( $term ) ) {
																	$display_value = $term->name;
																}
															}

															// Fallback for custom product attributes (non-taxonomy)
															if ( '' === $display_value && ! empty( $attribute_value ) ) {
																$display_value = apply_filters( 'woocommerce_variation_option_name', ucfirst( $attribute_value ) );
															}
														}

														// ✅ Output with nice label
														echo wp_kses_post( $attr_label . ' : ' . $display_value );

														$keys = array_keys( $item_all_meta );
														echo ( end( $keys ) !== $key ) ? ' | ' : '';
													}
													?>
												</p>
											<?php } ?>

											<div class="product-qty">
												Qty. <?php echo esc_html( $exchange_qty ); ?>
											</div>
											<div>price : <?php echo wp_kses_post( wc_price( isset( $variation_price ) ? $variation_price : $item_price ) ); ?></div>
										</div>
									</div>
									<?php endif; // variation_name exists ?>
									<?php if ( empty( $variation_name ) ) : ?>
										<div class="product-details exchange-items exchange-items-empty"></div>
									<?php endif; ?>
									</div><!-- /.exchange-summary-row -->

									<?php
								}
							}
						}
						?>
					</div>
					<?php if ( 'exchange-approved' == $exchange_data->exchange_status ) { ?>
						<?php if ( $is_keep_the_item_view ) { ?>
							<div class="exchange_issue_note exchange_items_header keep-item-notice">
								<strong><?php esc_html_e( 'Keep Your Item', 'zorem-returns' ); ?></strong>
								<p><?php esc_html_e( 'No return shipping required. You may keep the item. Your replacement will be dispatched automatically.', 'zorem-returns' ); ?></p>
							</div>
						<?php } else { ?>
						<?php
						$order_id    = $order->get_id();
						$customer_id = $order->get_customer_id();

						$customer          = $customer_id ? new WC_Customer( $customer_id ) : null;
						$customer_username = $customer && $customer->get_id() ? $customer->get_username() : '';

						$default_settings    = zorem_returns_exchanges()->exCommon->generate_defaults();
						$settings_menu_value = zorem_returns_exchanges()->common->return_settings( 'exchange_guideline_content', $default_settings['customer_exchange_approved']['exchange_guideline_content'] );

						$exchange_guideline_content = str_replace(
							array(
								'{site_title}',
								'{order_number}',
								'{order_date}',
								'{customer_full_name}',
								'{customer_first_name}',
								'{customer_last_name}',
								'{customer_username}',
								'{store_address}',
								'{phone_number}',
							),
							array(
								get_bloginfo( 'name' ),
								$order->get_order_number(),
								wc_format_datetime( $order->get_date_created() ),
								$order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
								$order->get_billing_first_name(),
								$order->get_billing_last_name(),
								$customer_username,
								zorem_returns_exchanges()->common->generate_default_store_address(),
								zorem_returns_exchanges()->common->return_settings( 'return_store_number', '' ),
							),
							$settings_menu_value
						);
						?>

						<div class="exchange_issue_note exchange_items_header"><?php echo isset( $exchange_guideline_content ) ? wp_kses_post( html_entity_decode( $exchange_guideline_content ) ) : ''; ?></div>
						<?php $store_address = zorem_returns_exchanges()->common->generate_default_store_address(); ?>
						<?php if ( ! empty( $store_address ) ) { ?>
							<div class="exchange_issue_note exchange_items_header">
								<p class="exchange_reason_header">
									<?php esc_html_e( 'Return Shipping Address', 'zorem-returns' ); ?>
								</p>
								<?php echo wp_kses_post( html_entity_decode( zorem_returns_exchanges()->common->generate_default_store_address() ) ); ?>
							</div>
						<?php } ?>
						<?php if ( ! empty( $exchange_data->exchange_label_pdf ) && file_exists( $exchange_data->exchange_label_pdf ) ) { ?>
							<div class="return_label_download exchange_items_header">
								<p class="exchange_reason_header"><?php esc_html_e( 'Return Shipping Label', 'zorem-returns' ); ?></p>
								<p class="shipping_label"><?php esc_html_e( 'A return shipping label has been provided. Please download, print, and attach it to your return package.', 'zorem-returns' ); ?></p>
								<?php $download_nonce = wp_create_nonce( 'rt4wc_download_label' ); ?>
								<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=rt4wc_download_exchange_label&request_id=' . $exchange_data->id . '&nonce=' . $download_nonce ) ); ?>" class="button rt4wc-download-label-btn" target="_blank">
									<span class="dashicons dashicons-download"></span>
									<?php esc_html_e( 'Download Return Shipping Label', 'zorem-returns' ); ?>
								</a>
							</div>
						<?php } ?>
						<?php } ?>
					<?php } ?>
					<?php if ( 'exchange-requested' == $exchange_data->exchange_status ) { ?>
						<button type="button" class="exchange-request-cancel button" style="margin: 1em;" data-request_id="<?php echo esc_attr( $exchange_data->id ); ?>"><?php esc_html_e( 'Cancel Request', 'zorem-returns' ); ?></button>
						<?php $ajax_nonce = wp_create_nonce( 'exchange_request_cancel' ); ?>
						<input type="hidden" name="exchange_request_cancel_nonce" id="exchange_request_cancel_nonce" value="<?php echo esc_html( $ajax_nonce ); ?>" />
					<?php } ?>
				</div>
				
			</div>
			<?php
		}
	}

	/**
	 * Get variation ID by attribute slugs
	 *
	 * @param int   $variable_product_id The variable product ID
	 * @param array $attributes Array of attribute slugs (e.g., ['attribute_pa_color' => 'blue', 'attribute_pa_size' => 'large'])
	 * @return int|null The variation ID if found, null otherwise
	 */
	/**
	 * Returns the customer's stored attribute choices for a cross-product
	 * exchange (saved as 'cross_variants' on the selected exchange items).
	 *
	 * @param array $exchange_items Unserialized exchange_items array.
	 * @return array attribute_* => chosen value
	 */
	/**
	 * Resolve the cross-product replacement target ids for ONE exchange item.
	 * Admin multi-item exchanges store the target PER ITEM inside exchange_items;
	 * customer/legacy rows store a single target on the row — fall back to the
	 * row-level columns when the item carries no target of its own.
	 *
	 * @param object $exchange_data  Exchange request row.
	 * @param array  $exchange_items Unserialized exchange_items array.
	 * @param int    $item_id        Order item id.
	 * @return array [ product_id, variation_id ]
	 */
	public function get_cross_target_ids_for_item( $exchange_data, $exchange_items, $item_id ) {
		$item = ( is_array( $exchange_items ) && isset( $exchange_items[ $item_id ] ) && is_array( $exchange_items[ $item_id ] ) ) ? $exchange_items[ $item_id ] : array();
		if ( ! empty( $item['new_product_id'] ) ) {
			return array( (int) $item['new_product_id'], ! empty( $item['new_variation_id'] ) ? (int) $item['new_variation_id'] : 0 );
		}
		return array(
			isset( $exchange_data->new_product_id ) ? (int) $exchange_data->new_product_id : 0,
			isset( $exchange_data->new_variation_id ) ? (int) $exchange_data->new_variation_id : 0,
		);
	}

	public function get_stored_cross_variants( $exchange_items ) {
		if ( is_array( $exchange_items ) ) {
			foreach ( $exchange_items as $item_data ) {
				if ( is_array( $item_data ) && ! empty( $item_data['cross_variants'] ) && is_array( $item_data['cross_variants'] ) ) {
					return $item_data['cross_variants'];
				}
			}
		}
		return array();
	}

	/**
	 * Builds a display string for a cross-product target variation, filling
	 * "Any …" (empty) attribute values with the customer's stored choices.
	 *
	 * @param WC_Product $target         Target variation (or product) object.
	 * @param array      $exchange_items Unserialized exchange_items array.
	 * @param string     $sep            Separator between attribute parts.
	 * @return string
	 */
	public function get_cross_target_variation_text( $target, $exchange_items = array(), $sep = ' | ' ) {
		if ( ! $target || ! is_a( $target, 'WC_Product' ) || ! $target->is_type( 'variation' ) ) {
			return '';
		}

		$chosen = $this->get_stored_cross_variants( $exchange_items );

		$parts = array();
		foreach ( $target->get_variation_attributes() as $attr_key => $attr_value ) {
			if ( ( '' === $attr_value || null === $attr_value ) && ! empty( $chosen[ $attr_key ] ) ) {
				$attr_value = $chosen[ $attr_key ];
			}

			$tax        = str_replace( 'attribute_', '', $attr_key );
			$attr_label = wc_attribute_label( $tax );

			if ( '' === (string) $attr_value ) {
				/* translators: %s: attribute label */
				$parts[] = sprintf( __( 'Any %s', 'zorem-returns' ), $attr_label );
				continue;
			}

			$term    = taxonomy_exists( $tax ) ? get_term_by( 'slug', $attr_value, $tax ) : false;
			$parts[] = $attr_label . ' : ' . ( $term ? $term->name : ucfirst( $attr_value ) );
		}

		return implode( $sep, $parts );
	}

	public function get_variation_id_by_attributes( $variable_product_id, $attributes ) {
		$product = wc_get_product( $variable_product_id );
		if ( ! is_object( $product ) ) {
			return null;
		}
		if ( $product && $product->is_type( 'variable' ) ) {
			$variations = $product->get_available_variations();
			foreach ( $variations as $variation ) {
				$match = true;
				foreach ( $attributes as $key => $value ) {
					if ( isset( $variation['attributes'][ 'attribute_' . strtolower( $key ) ] ) && $variation['attributes'][ 'attribute_' . strtolower( $key ) ] === $value ) {
						continue;
					} else {
						$match = false;
						break;
					}
				}
				if ( $match ) {
					return $variation['variation_id'];
				}
			}
		}
		return null;
	}

	/**
	 * Check if an item price matches any other variation's price.
	 *
	 * @param WC_Order_Item_Product $item The order item object.
	 * @return bool True if another variation exists with the same price, false otherwise.
	 */
	public function is_item_price_match_variation( $item ) {

		// Cross-product exchange: show the exchange button for any purchasable product.
		if ( class_exists( 'Zorem_Exchange_Product_Search' ) && Zorem_Exchange_Product_Search::is_enabled() ) {
			$product = wc_get_product( $item->get_product_id() );
			return ( $product && $product->is_purchasable() );
		}

		$product_id        = $item->get_product_id();
		$item_variation_id = $item->get_variation_id();

		// Get the main product object
		$product           = wc_get_product( $product_id );
		$variation_product = wc_get_product( $item_variation_id );
		if ( ! is_object( $product ) || ! is_object( $variation_product ) ) {
			return false;
		}
		if ( ! $product || ! in_array( $product->get_type(), array( 'variable', 'variation' ) ) ) {
			return false; // Not a variable product or variation, no need to check
		}

		// Get the correct item price (price per unit, not subtotal)
		// $order_item_price = round($item->get_subtotal() + $item->get_subtotal_tax()) / max(1, $item->get_quantity());

		// Get the variation product current price by variation order item
		$order_item_price = $variation_product ? floatval( $variation_product->get_price() ) : 0;

		// If it's a variation, get the parent product
		if ( $product->get_type() === 'variation' ) {
			$product = wc_get_product( $product->get_parent_id() );
		}

		// Get all variations
		$variations = $product->get_children();

		foreach ( $variations as $variation_id ) {
			// Skip the current variation itself
			if ( $variation_id == $item_variation_id ) {
				continue;
			}

			$variation_product = wc_get_product( $variation_id );
			if ( ! $variation_product ) {
				continue;
			}

			$variation_price = floatval( $variation_product->get_price() );

			// If any other variation has the same price, return true
			if ( $order_item_price == $variation_price ) {
				return true;
			}
		}

		return false; // No matching variation found
	}


	/**
	 * Get filtered variation attributes based on the order item price, excluding the order item variation.
	 *
	 * @param WC_Order_Item_Product $item The order item object.
	 * @param WC_Product            $product The WooCommerce product object.
	 * @return array Filtered variation attributes.
	 */
	public function get_filtered_variation_attributes_by_price( $item, $product ) {
		if ( ! $product || ! is_a( $product, 'WC_Product' ) || ! $item || ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			return array();
		}

		// Get current order item variation
		$item_variation_id = $item->get_variation_id();
		$variation_product = wc_get_product( $item_variation_id );
		if ( ! $variation_product ) {
			return array();
		}

		// ✅ Get the order item price inclusive of tax (so comparison always consistent)
		$order_item_price = wc_get_price_including_tax( $variation_product );

		// Get product variation attributes
		$attributes           = $product->get_variation_attributes();
		$available_variations = $product->get_available_variations();

		// Collect matching variation IDs based on equal (inclusive) price
		$filtered_variation_ids = array();
		foreach ( $available_variations as $variation ) {
			$variation_id = $variation['variation_id'];

			// Skip the same variation as ordered one
			if ( $variation_id == $item_variation_id ) {
				continue;
			}

			// Get inclusive price for comparison (works for both tax settings)
			$variation_obj = wc_get_product( $variation_id );
			if ( ! $variation_obj ) {
				continue;
			}

			$variation_price = wc_get_price_including_tax( $variation_obj );

			// Allow tiny rounding differences (±0.5)
			if ( abs( floatval( $variation_price ) - floatval( $order_item_price ) ) < 0.5 ) {
				$filtered_variation_ids[] = $variation_id;
			}
		}

		// Return empty if no match
		if ( empty( $filtered_variation_ids ) ) {
			return array();
		}

		// ✅ Filter valid attribute options
		foreach ( $attributes as $attribute_name => &$options ) {
			$options = array_filter(
				$options,
				function ( $option ) use ( $filtered_variation_ids, $attribute_name ) {
					foreach ( $filtered_variation_ids as $variation_id ) {
						$variation_obj = wc_get_product( $variation_id );
						if ( ! $variation_obj ) {
							continue;
						}
						$variation_attributes = $variation_obj->get_attributes();

						if ( isset( $variation_attributes[ $attribute_name ] ) && $variation_attributes[ $attribute_name ] === $option ) {
							return true;
						}
					}
					return false;
				}
			);
		}
		unset( $options ); // cleanup reference

		// Remove empty attributes
		return array_filter( $attributes );
	}

	/**
	 * Code for initialize default value for email content
	 */
	public function generate_defaults() {

		$email_defaults = array(
			'customer_exchange_requested' => array(
				'subject'              => esc_html__( "Exchange Request Received – We're Reviewing Your Request!", 'zorem-returns' ),
				'heading'              => esc_html__( 'Your Exchange Request Has Been Received', 'zorem-returns' ),
				'email_content'        => htmlspecialchars_decode( __( "Thank you for your exchange request!<br><br>We've received your request and are currently reviewing it. You'll receive an update once it's processed.", 'zorem-returns' ) ),
				'exchange_items_title' => esc_html__( 'Exchange Items Summary', 'zorem-returns' ),
				'additional_content'   => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'admin_exchange_requested'    => array(
				'subject'              => esc_html__( 'New Exchange Request Received – Order', 'zorem-returns' ) . ' #{order_number}',
				'heading'              => esc_html__( 'A New Exchange Request Has Been Received', 'zorem-returns' ),
				'email_content'        => htmlspecialchars_decode( __( 'A new exchange request received:', 'zorem-returns' ) ),
				'exchange_items_title' => esc_html__( 'Exchange Items Summary', 'zorem-returns' ),
				'additional_content'   => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'admin_exchange_approved'     => array(
				'subject'              => esc_html__( 'Exchange Request Approved', 'zorem-returns' ) . ': #{order_number}',
				'heading'              => esc_html__( 'Exchange Request Approved – Return Instructions Inside', 'zorem-returns' ),
				'email_content'        => htmlspecialchars_decode( __( 'I wanted to inform you that the exchange request for order #{order_number} has been approved by the admin. Below are the details:', 'zorem-returns' ) ),
				'exchange_items_title' => esc_html__( 'Approved Exchange Items', 'zorem-returns' ),
				'additional_content'   => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'admin_exchange_cancelled'    => array(
				'subject'              => esc_html__( 'Exchange Request Cancelled', 'zorem-returns' ) . ': #{order_number}',
				'heading'              => esc_html__( 'Exchange Request Cancelled', 'zorem-returns' ),
				'email_content'        => htmlspecialchars_decode( __( 'I wanted to inform you that the exchange request for order #{order_number} has been cancelled by the customer. Below are the details:', 'zorem-returns' ) ),
				'exchange_items_title' => esc_html__( 'Items from Cancelled Exchange Request', 'zorem-returns' ),
				'additional_content'   => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'customer_exchange_rejected'  => array(
				'subject'              => esc_html__( 'Exchange Request Rejected – Order Not Eligible', 'zorem-returns' ),
				'heading'              => esc_html__( 'Your Exchange Request Has Been Rejected!', 'zorem-returns' ),
				'email_content'        => htmlspecialchars_decode( __( "We're sorry to inform you that your exchange request has been rejected.<br><br> Please let us know if you have any questions or concerns.", 'zorem-returns' ) ),
				'exchange_items_title' => esc_html__( 'Rejected Exchange Items', 'zorem-returns' ),
				'additional_content'   => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'customer_exchange_completed' => array(
			'subject'              => esc_html__( 'Your Exchange Has Been Completed', 'zorem-returns' ),
			'heading'              => esc_html__( 'Your Exchange Is Complete!', 'zorem-returns' ),
			'email_content'        => htmlspecialchars_decode( __( "Great news! Your exchange request has been completed successfully.<br><br>We hope you enjoy your new item. Don't hesitate to reach out if you need any further assistance.", 'zorem-returns' ) ),
			'exchange_items_title' => esc_html__( 'Exchanged Items', 'zorem-returns' ),
			'additional_content'   => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
		),
		'customer_exchange_approved'  => array(
				'subject'                    => esc_html__( 'Exchange Request Approved - Instructions for Return', 'zorem-returns' ),
				'heading'                    => esc_html__( 'Your Exchange Request Has Been Approved!', 'zorem-returns' ),
				'email_content'              => htmlspecialchars_decode( __( 'Kindly return the exchange item(s) to the following address', 'zorem-returns' ) ) . ':{store_address}<br>' . __( 'Phone', 'zorem-returns' ) . ':{phone_number}',
				'exchange_items_title'       => esc_html__( 'Approved Exchange Items', 'zorem-returns' ),
				'exchange_guideline_title'   => esc_html__( 'Exchange Guidelines', 'zorem-returns' ) . ':',
				'exchange_guideline_content' => htmlspecialchars_decode( __( '<strong>Exchange Guidelines:</strong><ul><li>Securely package the item you are returning for exchange to prevent damage during transit. Use the original packaging if possible.</li><li>Use a reliable shipping service to send the package back to us. We recommend a service that provides tracking information. This helps ensure the package reaches us safely and allows you to track its status.</li><li>After shipping your exchange package, please notify us by replying to the initial email or contacting our support team with the tracking number.</li><li>Upon receiving the exchanged items, we will inspect them to ensure they meet our exchange policy criteria. If the items are in good condition and meet the criteria, we will process the exchange.</li><li>Once the exchange is approved, we will ship the replacement item to you. You will receive a confirmation email with tracking information once the new item is dispatched.</li><li>Exchanges will be processed within 5-7 business days of receiving the returned items. The shipping time for the new item may vary depending on your location.</li></ul>', 'zorem-returns' ) ),
				'additional_content'         => esc_html__( 'If you have any questions or concerns about your exchange, feel free to contact our customer support team at [Customer Support Email/Phone Number].<br><br>Thank you for choosing', 'zorem-returns' ) . ' {site_title} ' . esc_html__( '. We truly appreciate your business and look forward to serving you again soon.', 'zorem-returns' ),
			),
			'customer_exchange_store_credit' => array(
				'subject'              => esc_html__( 'Your Exchange Store Credit Has Been Issued!', 'zorem-returns' ),
				'heading'              => esc_html__( 'Your Exchange Store Credit Has Been Issued!', 'zorem-returns' ),
				'email_content'        => htmlspecialchars_decode( __( 'Your exchange has been completed. Since the replacement item costs less than your original item, store credit has been issued for the difference.', 'zorem-returns' ) ),
				'exchange_items_title' => esc_html__( 'How to Use Your Store Credit', 'zorem-returns' ),
				'additional_content'   => htmlspecialchars_decode( __( 'If you have any questions, feel free to reply to this email. <br><br>Thank you for shopping with ', 'zorem-returns' ) ) . '{site_title}.',
			),
			'admin_exchange_completed'    => array(
				'subject'              => esc_html__( 'Exchange Completed', 'zorem-returns' ) . ': #{order_number}',
				'heading'              => esc_html__( 'Exchange Completed', 'zorem-returns' ),
				'email_content'        => htmlspecialchars_decode( __( 'The exchange for order #{order_number} has been marked as completed. Below are the details:', 'zorem-returns' ) ),
				'exchange_items_title' => esc_html__( 'Exchanged Items', 'zorem-returns' ),
				'additional_content'   => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'admin_exchange_rejected'     => array(
				'subject'              => esc_html__( 'Exchange Request Rejected', 'zorem-returns' ) . ': #{order_number}',
				'heading'              => esc_html__( 'Exchange Request Rejected', 'zorem-returns' ),
				'email_content'        => htmlspecialchars_decode( __( 'The exchange request for order #{order_number} has been rejected by the admin. Below are the details:', 'zorem-returns' ) ),
				'exchange_items_title' => esc_html__( 'Rejected Exchange Items', 'zorem-returns' ),
				'additional_content'   => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
		);
		return $email_defaults;
	}

	public function zorem_exchange_order_metabox( $post_type, $post_or_order ) {
		$order  = false;
		$screen = 'shop_order';

		// Logic to determine the correct screen/order object for both Legacy and HPOS
		if ( 'shop_order' === $post_type && $post_or_order instanceof WP_Post ) {
			$order  = wc_get_order( $post_or_order->ID );
			$screen = 'shop_order';
		} elseif ( ! empty( $_GET['id'] ) && class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			$order_id = absint( $_GET['id'] );
			$order    = wc_get_order( $order_id );

			// Get the proper screen ID for HPOS
			$screen = \wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? \wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		}

		if ( ! $order ) {
			return;
		}

		$parent_order_id = $order->get_meta( '_parent_order_id' );

		if ( empty( $parent_order_id ) ) {
			return;
		}

		add_meta_box( 'zorem_exchange_order_box', __( 'Exchange Parent Order', 'zorem-returns' ), array( $this, 'zorem_display_exchange_order_box' ), $screen, 'advanced', 'core' );
	}

	public function zorem_display_exchange_order_box( $post_or_order_object ) {
		$order = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'zorem-returns' ) . '</p>';
			return;
		}

		$parent_order_id  = $order->get_meta( '_parent_order_id' );
		$exchange_type    = $order->get_meta( '_zre_exchange_type' );
		$order_status     = $order->get_status();
		$payment_url      = $order->get_checkout_payment_url();

		echo '<div style="font-size:13px;line-height:1.8;">';

		if ( ! empty( $parent_order_id ) ) {
			$parent_edit_link = admin_url( 'post.php?post=' . intval( $parent_order_id ) . '&action=edit' );

			echo '<p>✅ ' . wp_kses_post( sprintf(
				/* translators: 1: Order edit URL, 2: Parent order ID */
				__( 'This is an exchange child order for <a href="%1$s"><strong>Order #%2$d</strong></a>.', 'zorem-returns' ),
				esc_url( $parent_edit_link ),
				intval( $parent_order_id )
			) ) . '</p>';
		}

		if ( 'cross-product' === $exchange_type ) {
			echo '<p>🔄 <strong>' . esc_html__( 'Type:', 'zorem-returns' ) . '</strong> ' . esc_html__( 'Cross-Product Exchange', 'zorem-returns' ) . '</p>';
		}

		// Show payment link prominently when order is pending payment
		if ( 'pending' === $order_status ) {
			echo '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px 14px;margin-top:10px;">';
			echo '<p style="margin:0 0 8px;font-weight:600;color:#856404;">⏳ ' . esc_html__( 'Awaiting customer payment', 'zorem-returns' ) . '</p>';
			echo '<p style="margin:0 0 10px;color:#555;">' . esc_html__( 'Share this payment link with the customer:', 'zorem-returns' ) . '</p>';
			echo '<div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:8px 10px;word-break:break-all;font-family:monospace;font-size:12px;margin-bottom:10px;">';
			echo '<a href="' . esc_url( $payment_url ) . '" target="_blank">' . esc_url( $payment_url ) . '</a>';
			echo '</div>';
			echo '<a href="' . esc_url( $payment_url ) . '" target="_blank" style="display:inline-block;background:#2271b1;color:#fff;padding:8px 16px;border-radius:4px;text-decoration:none;font-size:13px;font-weight:600;">';
			echo '💳 ' . esc_html__( 'Open Payment Page', 'zorem-returns' );
			echo '</a>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Apply preset shipping method to exchange order
	 */
	public function zre_apply_preset_shipping_to_exchange_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Get plugin settings
		$settings = get_option( 'return_general_settings_option', array() );

		// Custom "Exchange Shipping" line (label + cost) takes priority. Because it
		// is NOT a WooCommerce zone method, it never appears at cart/checkout and
		// leaves carrier plugins (e.g. ReachShip) untouched on the order.
		$custom_label    = isset( $settings['exchange_shipping_label'] ) ? trim( (string) $settings['exchange_shipping_label'] ) : '';
		$custom_cost_set = isset( $settings['exchange_shipping_cost'] ) && '' !== $settings['exchange_shipping_cost'];
		if ( '' !== $custom_label || $custom_cost_set ) {
			$shipping_item = new WC_Order_Item_Shipping();
			$shipping_item->set_method_title( '' !== $custom_label ? $custom_label : __( 'Exchange Shipping', 'zorem-returns' ) );
			$shipping_item->set_method_id( 'zre_exchange_shipping' );
			$shipping_item->set_total( wc_format_decimal( $custom_cost_set ? (float) $settings['exchange_shipping_cost'] : 0 ) );
			$order->add_item( $shipping_item );
			return;
		}

		if ( empty( $settings['exchange_preset_shipping_method'] ) ) {
			return;
		}

		$preset_method = $settings['exchange_preset_shipping_method'];

		if ( strpos( $preset_method, ':' ) === false ) {
			return;
		}

		list( $method_id, $instance_id ) = explode( ':', $preset_method );

		$shipping_method = false;

		$zones = WC_Shipping_Zones::get_zones();

		foreach ( $zones as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( $method->id === $method_id && $method->instance_id == $instance_id ) {
					$shipping_method = $method;
					break 2;
				}
			}
		}

		if ( ! $shipping_method ) {
			$default_zone = new WC_Shipping_Zone( 0 );
			foreach ( $default_zone->get_shipping_methods( true ) as $method ) {
				if ( $method->id === $method_id && $method->instance_id == $instance_id ) {
					$shipping_method = $method;
					break;
				}
			}
		}

		if ( ! $shipping_method ) {
			return;
		}

		// Build package for cost calculation
		$package = array(
			'destination' => array(
				'country'   => $order->get_shipping_country(),
				'state'     => $order->get_shipping_state(),
				'postcode'  => $order->get_shipping_postcode(),
				'city'      => $order->get_shipping_city(),
				'address'   => $order->get_shipping_address_1(),
				'address_2' => $order->get_shipping_address_2(),
			),
			'contents'    => array(),
		);

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product ) {
				$package['contents'][] = array(
					'data'       => $product,
					'quantity'   => $item->get_quantity(),
					'line_total' => $item->get_total(),
				);
			}
		}

		$shipping_method->calculate_shipping( $package );

		if ( empty( $shipping_method->rates ) ) {
			return;
		}

		$rate = reset( $shipping_method->rates );

		$shipping_item = new WC_Order_Item_Shipping();
		$shipping_item->set_method_title( $rate->get_label() );
		$shipping_item->set_method_id( $rate->get_id() );
		$shipping_item->set_total( wc_format_decimal( $rate->get_cost() ) );

		$product_names = array();
		foreach ( $order->get_items() as $item ) {
			$product_names[] = $item->get_name() . ' × ' . $item->get_quantity();
		}

		if ( ! empty( $product_names ) ) {
			$shipping_item->add_meta_data( 'Items', implode( ', ', $product_names ), true );
		}

		$order->add_item( $shipping_item );
	}

	/**
	 * Gross (incl-tax) coupon discount that applies to ONE unit of a replacement
	 * product, by re-applying the parent order's coupons.
	 *
	 * Uses WooCommerce's own WC_Coupon::is_valid_for_product() so the coupon's
	 * product / category / sale-item exclusions are honoured automatically:
	 * if the replacement is excluded (e.g. a sale-category product excluded from
	 * coupons) the coupon is skipped and no discount is applied.
	 *
	 * @param WC_Product $product      Replacement product/variation.
	 * @param WC_Order   $parent_order Original order the exchange is based on.
	 * @return float                    Discount amount (incl tax) per single unit.
	 */
	public function zre_replacement_unit_discount( $product, $parent_order ) {
		if ( ! ( $product instanceof WC_Product ) || ! ( $parent_order instanceof WC_Order ) ) {
			return 0.0;
		}

		$unit_price = (float) wc_get_price_including_tax( $product );
		if ( $unit_price <= 0 ) {
			return 0.0;
		}

		$discount = 0.0;
		foreach ( $parent_order->get_coupon_codes() as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( ! $coupon->get_id() ) {
				continue; // Coupon no longer exists.
			}
			if ( method_exists( $coupon, 'is_valid_for_product' ) && ! $coupon->is_valid_for_product( $product ) ) {
				continue; // Replacement not eligible (product/category/sale exclusion).
			}

			$amount = (float) $coupon->get_amount();
			switch ( $coupon->get_discount_type() ) {
				case 'percent':
					$discount += $unit_price * ( $amount / 100 );
					break;
				case 'fixed_product':
					$discount += min( $amount, $unit_price );
					break;
				default:
					// 'fixed_cart' is a cart-level discount that cannot be
					// attributed to a single product line — skipped.
					break;
			}
		}

		return min( $discount, $unit_price );
	}

	/**
	 * Actual amount PAID (incl tax) for a parent-order line after all discounts
	 * and coupons, prorated to the exchanged quantity. This is the credit value
	 * for the original item — not its current catalog price.
	 *
	 * @param WC_Order_Item_Product $item    Parent order line item.
	 * @param int                   $use_qty Exchanged qty (0 = full ordered qty).
	 * @return float
	 */
	public function zre_original_line_paid_gross( $item, $use_qty = 0 ) {
		if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
			return 0.0;
		}
		$ordered_qty = max( 1, (int) $item->get_quantity() );
		$use_qty     = ( (int) $use_qty >= 1 ) ? min( (int) $use_qty, $ordered_qty ) : $ordered_qty;
		$line_gross  = (float) $item->get_total() + (float) $item->get_total_tax(); // Discounted line, incl tax.
		return ( $line_gross / $ordered_qty ) * $use_qty;
	}

	/**
	 * Cost (store currency) of the preset exchange shipping method for a given
	 * destination + package contents. Returns 0 when no preset method is set or
	 * it produces no rate. Used to show the shipping fee in the request preview,
	 * mirroring the method resolution in zre_apply_preset_shipping_to_exchange_order().
	 *
	 * @param array $destination Package destination (country/state/postcode/city/address/address_2).
	 * @param array $contents    WC cart-style package contents.
	 * @return float
	 */
	public function zre_get_preset_shipping_cost( $destination, $contents = array() ) {
		$settings = get_option( 'return_general_settings_option', array() );

		// Custom "Exchange Shipping" (label + cost) takes priority over the preset
		// method, so the preview matches what the child order will be charged.
		$custom_label    = isset( $settings['exchange_shipping_label'] ) ? trim( (string) $settings['exchange_shipping_label'] ) : '';
		$custom_cost_set = isset( $settings['exchange_shipping_cost'] ) && '' !== $settings['exchange_shipping_cost'];
		if ( '' !== $custom_label || $custom_cost_set ) {
			return $custom_cost_set ? (float) $settings['exchange_shipping_cost'] : 0.0;
		}

		if ( empty( $settings['exchange_preset_shipping_method'] ) ) {
			return 0.0;
		}

		$preset_method = $settings['exchange_preset_shipping_method'];
		if ( false === strpos( $preset_method, ':' ) || ! class_exists( 'WC_Shipping_Zones' ) ) {
			return 0.0;
		}

		list( $method_id, $instance_id ) = explode( ':', $preset_method );

		$shipping_method = false;
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( $method->id === $method_id && $method->instance_id == $instance_id ) {
					$shipping_method = $method;
					break 2;
				}
			}
		}

		if ( ! $shipping_method ) {
			$default_zone = new WC_Shipping_Zone( 0 );
			foreach ( $default_zone->get_shipping_methods( true ) as $method ) {
				if ( $method->id === $method_id && $method->instance_id == $instance_id ) {
					$shipping_method = $method;
					break;
				}
			}
		}

		if ( ! $shipping_method ) {
			return 0.0;
		}

		$shipping_method->calculate_shipping(
			array(
				'destination' => $destination,
				'contents'    => $contents,
			)
		);

		if ( empty( $shipping_method->rates ) ) {
			return 0.0;
		}

		$rate = reset( $shipping_method->rates );
		return (float) $rate->get_cost();
	}
}
