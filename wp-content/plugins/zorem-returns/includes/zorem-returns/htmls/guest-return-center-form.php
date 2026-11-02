<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial: variables come from the including class scope.
defined( 'ABSPATH' ) || exit;
?>
<div class="multi-guest-container">
	<h2 class="form-heading" style="display: flex;flex-direction: column;"><?php esc_html_e( 'Start a Return', 'zorem-returns' ); ?></h2>
	<div class="form-content">
		<?php
		// Return item options
		$return_form_data = ! empty( $order ) ? zorem_returns_exchanges()->common->get_returned_form_data( $order ) : array();

		$return_general_settings_option = get_option( 'return_general_settings_option', array() );
		$exchange_enable                = isset( $return_general_settings_option['exchange_enable'] ) ? $return_general_settings_option['exchange_enable'] : 'yes';
		$exchange_form_data             = ! empty( $order ) && 'yes' == $exchange_enable ? zorem_returns_exchanges()->exCommon->get_exchanged_form_data( $order ) : array();

		$variation_found = false;
		foreach ( $order->get_items() as $item_id => $item ) {
			$available_variations = zorem_returns_exchanges()->exCommon->is_item_price_match_variation( $item );
			if ( $available_variations ) {
				$variation_found = true;
				break;
			}
		}

		if ( ! empty( $return_form_data ) || ! empty( $exchange_form_data ) ) {
			?>
		<label>
			<?php esc_html_e( 'Products in This Order', 'zorem-returns' ); ?>
			</label>
			<div class="request_item_details">
				<?php
				$return_entire_order   = zorem_returns_exchanges()->common->return_settings( 'return_entire_order', 'no' );
				$exchange_entire_order = zorem_returns_exchanges()->common->return_settings( 'exchange_entire_order', 'no' );
				// Get order items
				$order_items = $order->get_items();
				foreach ( $order_items as $item_id => $item ) {
					$order_item           = new WC_Order_Item_Product( $item_id );
					$available_variations = zorem_returns_exchanges()->exCommon->is_item_price_match_variation( $item );
					if ( is_a( $order_item, 'WC_Order_Item_Product' ) ) {
						$product_id = $order_item->get_product_id();
						$product    = wc_get_product( $product_id );
						if ( ! is_object( $product ) ) {
							continue;
						}
						$product_image_url = wp_get_attachment_url( $product->get_image_id() );
						$product_title     = $product->get_name();
						$item_all_meta     = $order_item->get_all_formatted_meta_data();
						$product_price     = $order->get_formatted_line_subtotal( $order_item );
						if ( array_key_exists( $item_id, $exchange_form_data ) && $available_variations ) {
							$product_qty = isset( $exchange_form_data[ $item_id ]['product_quantity'] ) > 0 ? $exchange_form_data[ $item_id ]['product_quantity'] : 0;
						} else {
							$product_qty = isset( $return_form_data[ $item_id ]['product_quantity'] ) > 0 ? $return_form_data[ $item_id ]['product_quantity'] : 0;
						}

						if ( 0 == $product_qty ) {
							continue;
						}
					}
					?>
					<div class="product-details">
						<img src="<?php echo esc_url( $product_image_url ); ?>" alt="<?php echo esc_attr( $product_title ); ?>"/>
						<input type="hidden" name="return_data[return_items][<?php echo esc_html( $item_id ); ?>][return_product_id]" value="<?php echo esc_html( $item_id ); ?>">
						<input type="hidden" name="return_data[return_items][<?php echo esc_html( $item_id ); ?>][return_product_qty]" value="<?php echo esc_html( $product_qty ); ?>">
						<div class="product-detail" style="margin: 10px;width:50%;">
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
							<p class="product-Price"><?php echo wp_kses_post( $product_price ); ?></p>
							<div class="product-qty">
								<strong><?php echo esc_html__( 'Qty. ', 'zorem-returns' ); ?> <?php echo esc_html( $product_qty ); ?></strong>
							</div>
						</div>
						<div class="action-btn" style="margin: 10px;display: inline-block;">

							<?php if ( array_key_exists( $item_id, $exchange_form_data ) && $available_variations && 'yes' == $exchange_entire_order ) { ?>
								<button class="guest_exchange_request_button button" type="button" data-item-id="<?php echo esc_html( $item_id ); ?>" data-order-id="<?php echo esc_html( $order_id ); ?>" data-type="exchange"> Exchange</button>
							<?php } ?>
							<?php if ( array_key_exists( $item_id, $return_form_data ) && 'yes' == $return_entire_order ) { ?>
								<button class="guest_return_request_button button" type="button" data-item-id="<?php echo esc_html( $item_id ); ?>" data-order-id="<?php echo esc_html( $order_id ); ?>" data-type="return"> 
									<?php echo esc_html( apply_filters( 'zorem_returns_label_text', __( 'Return', 'zorem-returns' ), 'item' ) ); ?>
								</button>
							<?php } ?>
						</div>
					</div>
					<?php
				}
				?>
				<?php if ( ! empty( $return_form_data ) ) { ?>
				<button class="guest_return_request_button button" type="button" data-order-id="<?php echo esc_html( $order_id ); ?>" data-type="return"> 
					<?php echo esc_html( apply_filters( 'zorem_returns_label_text', __( 'Return Order', 'zorem-returns' ), 'order' ) ); ?>
				</button>
					
				<?php } ?>
				<?php if ( ! empty( $exchange_form_data ) && $variation_found ) { ?>
				<button class="guest_exchange_request_button button" type="button" data-order-id="<?php echo esc_html( $order_id ); ?>" data-type="exchange"> Exchange Order</button>
				<?php } ?>
				<button class="cancel-form-btn button" type="button" data-order-id="<?php echo esc_html( $order_id ); ?>"> Back</button>
				<?php $ajax_nonce = wp_create_nonce( 'guest_return_center_order_form' ); ?>
				<input type="hidden" name="guest_return_center_order_nonce" id="guest_return_center_order_nonce" value="<?php echo esc_html( $ajax_nonce ); ?>" />
			</div>
		<?php } ?>
	</div>
</div>
