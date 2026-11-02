<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial: variables come from the including class scope.
defined( 'ABSPATH' ) || exit;
?>
<form id="return-multi-step-form" >
	<div class="multi-form-container">
		<?php
		$return_entire_order = zorem_returns_exchanges()->common->return_settings( 'return_entire_order', 'no' );
		if ( 'yes' == $return_entire_order && ! empty( $item_id ) ) {
			?>
			<h2 class="form-heading" style="display: flex;flex-direction: column;">
			<?php if ( is_account_page() ) { ?>
			<span style="font-size: 14px;margin-bottom: 5px;"><strong style="color: #008BC5;"><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'view-order' ) . $order->get_id() ); ?>" style="color: #008BC5;"><?php esc_html_e( 'View Order', 'zorem-returns' ); ?></a> > </strong> <?php esc_html_e( 'Product Selected for Return', 'zorem-returns' ); ?></span>
			<?php } ?>
			<?php esc_html_e( 'Product Selected for Return', 'zorem-returns' ); ?></h2>
			<?php
		} else {
			?>
			<h2 class="form-heading" style="display: flex;flex-direction: column;">
			<?php if ( is_account_page() ) { ?>
			<span style="font-size: 14px;margin-bottom: 5px;"><strong style="color: #008BC5;"><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'view-order' ) . $order->get_id() ); ?>" style="color: #008BC5;"><?php esc_html_e( 'View Order', 'zorem-returns' ); ?></a> > </strong> <?php esc_html_e( 'Start a Return', 'zorem-returns' ); ?></span>
			<?php } ?>
			<?php esc_html_e( 'Start a Return', 'zorem-returns' ); ?></h2>
			<?php
		}
		?>
		<div class="form-content">
			<input type="hidden" id="order_id" name="order_id" value="<?php echo esc_html( $order->get_id( ) ); ?>">
			<input type="hidden" id="item_id" name="item_id" value="<?php echo esc_html( $item_id ); ?>">
			<?php $ajax_nonce = wp_create_nonce( 'return_center_multiStep_form' ); ?>
			<input type="hidden" name="return_center_multiStep_nonce" id="return_center_multiStep_nonce" value="<?php echo esc_html( $ajax_nonce ); ?>" />
			<div class="step" id="step-1">
				<?php
				// Return item options
				$return_form_data = zorem_returns_exchanges()->common->get_returned_form_data( $order );

				if ( ! empty( $return_form_data ) ) {
					$sub_total = '0';
					?>
					<label>
						<?php esc_html_e( 'Products in This Order', 'zorem-returns' ); ?>
					</label>
					<div class="request_item_details">
						<?php
						$return_entire_order = zorem_returns_exchanges()->common->return_settings( 'return_entire_order', 'no' );
						if ( empty( $item_id ) ) {

							foreach ( $return_form_data as $item_id => $item ) {
								$order_item = new WC_Order_Item_Product( $item_id );
								if ( is_a( $order_item, 'WC_Order_Item_Product' ) ) {
									// Get product ID
									$product_id         = $order_item->get_product_id();
									$keep_the_item_meta = get_post_meta( $product_id, '_per_product_keep_item', true );

									// Get product object
									$product = wc_get_product( $product_id );
									if ( ! is_object( $product ) ) {
										continue;
									}
									$product_image_url = wp_get_attachment_url( $product->get_image_id() );
									$product_title     = $product->get_name();

									// Get product price
									$product_price = $order->get_formatted_line_subtotal( $order_item );

									// Get quantity
									$product_qty = isset( $return_form_data[ $item_id ]['product_quantity'] ) ? $return_form_data[ $item_id ]['product_quantity'] : 0;

									$sub_total += $order_item->get_product()->get_price() * $product_qty;

									$item_all_meta = $order_item->get_all_formatted_meta_data();
								}

								?>
							<div class="product-details" data-keep-item="<?php echo ( 'yes' === $keep_the_item_meta ) ? 'yes' : 'no'; ?>">
								<?php if ( 'yes' == $return_entire_order ) { ?>
									<input type="checkbox" name="return_data[return_items][<?php echo esc_html( $item_id ); ?>][return_product_id]" class="product_checked" value="<?php echo esc_html( $item_id ); ?>">
								<?php } else { ?>
									<input type="hidden" name="return_data[return_items][<?php echo esc_html( $item_id ); ?>][return_product_id]" value="<?php echo esc_html( $item_id ); ?>">
									<input type="hidden" name="return_data[return_items][<?php echo esc_html( $item_id ); ?>][return_product_qty]" value="<?php echo esc_html( $product_qty ); ?>">
								<?php } ?>

								<img src="<?php echo esc_url( $product_image_url ); ?>" alt="<?php echo esc_attr( $product_title ); ?>"/>
								<div class="product-detail" style="margin: 10px;">
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
										<strong><?php echo esc_html__( 'Qty. ', 'zorem-returns' ); ?></strong>
										<?php if ( 'yes' == $return_entire_order ) { ?>
											<select name="return_data[return_items][<?php echo esc_html( $item_id ); ?>][return_product_qty]" class="order_quantity_number order_quantity_update">
											<?php for ( $i = 0; $i <= $product_qty; $i++ ) { ?>
												<option value="<?php echo esc_attr( $i ); ?>" selected= <?php echo ( $i == $product_qty ) ? 'selected' : ''; ?>><?php echo esc_html( $i ); ?></option>
											<?php } ?>
											</select>
										<?php } else { ?>
											<strong><?php echo esc_html( $product_qty ); ?></strong>
										<?php } ?>
									</div>
									<?php
									/**
									 * Developer-injected custom fields for this order item, rendered
									 * inside the item block on the return request form. Receives the
									 * WC_Order_Item_Product so product/category context is available —
									 * e.g. a required hygiene-seal confirmation only for swimwear:
									 *
									 *     add_filter( 'zorem_return_request_form_item_fields', function ( $html, $item, $order ) {
									 *         if ( has_term( array( 'swimwear', 'leotards' ), 'product_cat', $item->get_product_id() ) ) {
									 *             $html .= '<p class="hygiene-confirm"><label><input type="checkbox" name="return_data[item_fields][' . esc_attr( $item->get_id() ) . '][hygiene_confirm]" value="1" required> ' . esc_html( 'Hijyen bandı sağlamdır.' ) . '</label></p>';
									 *         }
									 *         return $html;
									 *     }, 10, 3 );
									 *
									 * The form posts via FormData, so injected inputs arrive in $_POST
									 * and are available to the `zorem_return_request_validation` filter
									 * (e.g. $posted['return_data']['item_fields'][ <item_id> ][...]).
									 *
									 * @param string                $html       Field markup to inject (default '').
									 * @param WC_Order_Item_Product $order_item Current order item.
									 * @param WC_Order              $order      The order being returned against.
									 */
									echo apply_filters( 'zorem_return_request_form_item_fields', '', $order_item, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- developer-supplied form field markup.
									?>
								</div>
							</div>
								<?php

							}
						} else {

							$order_item = new WC_Order_Item_Product( $item_id );
							if ( is_a( $order_item, 'WC_Order_Item_Product' ) ) {
								// Get product ID
								$product_id         = $order_item->get_product_id();
								$keep_the_item_meta = get_post_meta( $product_id, '_per_product_keep_item', true );

								// Get product object
								$product = wc_get_product( $product_id );
								if ( ! is_object( $product ) ) {
									return;
								}
								$product_image_url = wp_get_attachment_url( $product->get_image_id() );
								$product_title     = $product->get_name();

								// Get product price
								$product_price = $order->get_formatted_line_subtotal( $order_item );

								// Get quantity
								$product_qty = isset( $return_form_data[ $item_id ]['product_quantity'] ) ? $return_form_data[ $item_id ]['product_quantity'] : 0;

								$sub_total = $order_item->get_product()->get_price() * $product_qty;

								$item_all_meta = $order_item->get_all_formatted_meta_data();
							}

							?>
							<div class="product-details" data-keep-item="<?php echo ( 'yes' === $keep_the_item_meta ) ? 'yes' : 'no'; ?>">
								<input type="hidden" name="return_data[return_items][<?php echo esc_html( $item_id ); ?>][return_product_id]" value="<?php echo esc_html( $item_id ); ?>">
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
									<p class="product-Price"><?php echo wp_kses_post( $product_price ); ?></p>
									<div class="product-qty">
										<strong><?php echo esc_html__( 'Qty. ', 'zorem-returns' ); ?></strong>
										<select name="return_data[return_items][<?php echo esc_html( $item_id ); ?>][return_product_qty]" class="order_quantity_number order_quantity_update">
										<?php for ( $i = 0; $i <= $product_qty; $i++ ) { ?>
											<option value="<?php echo esc_attr( $i ); ?>" selected= <?php echo ( $i == $product_qty ) ? 'selected' : ''; ?>><?php echo esc_html( $i ); ?></option>
										<?php } ?>
										</select>
									</div>
									<?php
									// Developer-injected custom fields for this order item — same
									// `zorem_return_request_form_item_fields` filter as the multi-item
									// branch above; see its docblock for full usage.
									echo apply_filters( 'zorem_return_request_form_item_fields', '', $order_item, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- developer-supplied form field markup.
									?>
								</div>
							</div>
												<?php

						}

						?>
						<h5 class="select_item"><?php esc_html_e( 'Please select the minimum quantity required for return.', 'zorem-returns' ); ?></h5>
						<h5 class="select_product_item"><?php esc_html_e( 'Please select at least one product.', 'zorem-returns' ); ?></h5>
					</div>
					<?php

					// Description Options
					$return_widget          = get_option( 'rt4wc_return_widget_settings', array() );
					$default_return_reasons = array(
						'Product damaged, but shipping box OK',
						'Both product and shipping box damaged',
						'Wrong item was sent',
						"Item defective, spoilt, expired, or doesn't work",
						'Items or parts missing',
					);
					$return_reasons         = zorem_returns_exchanges()->common->return_settings( 'return_reasons', $default_return_reasons );
					?>
					<div class="return_dropdown">
						<div class="cfw-input-wrap cfw-floating-label">
							<label>
								<?php esc_html_e( 'Reason for Return', 'zorem-returns' ); ?>*
							</label>
							<select class="mb1em" name="return_data[return_reason][no_other]" id="return_reason" required>
								<?php
								foreach ( $return_reasons as $value ) {
									if ( ! empty( $value ) ) {
										?>
										<option value="<?php echo esc_attr( stripslashes( $value ) ); ?>"><?php echo esc_html( stripslashes( $value ) ); ?></option>
									<?php } ?>
								<?php } ?>
								<option value="other_reason">
									<?php esc_html_e( 'Other (add details below)', 'zorem-returns' ); ?>
								</option>
							</select>
						</div>
						<div class="return_reason mb1em cfw-input-wrap">
							<textarea name="return_data[return_reason][other_reason]" id="other_reason" rows="3"
								cols="50"></textarea>
						</div>
					</div>
					<div class="return_desc mb1em cfw-input-wrap cfw-floating-label">
						<label>
							<?php esc_html_e( 'Describe the issue with the product', 'zorem-returns' ); ?>:*
						</label>
						<textarea name="return_data[return_issue]" id="return_issue" rows="3" cols="50"></textarea>
					</div>
					<div class="return_media">
						<label for="image-upload" class="select-media button"><svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M364.2 83.8c-24.4-24.4-64-24.4-88.4 0l-184 184c-42.1 42.1-42.1 110.3 0 152.4s110.3 42.1 152.4 0l152-152c10.9-10.9 28.7-10.9 39.6 0s10.9 28.7 0 39.6l-152 152c-64 64-167.6 64-231.6 0s-64-167.6 0-231.6l184-184c46.3-46.3 121.3-46.3 167.6 0s46.3 121.3 0 167.6l-176 176c-28.6 28.6-75 28.6-103.6 0s-28.6-75 0-103.6l144-144c10.9-10.9 28.7-10.9 39.6 0s10.9 28.7 0 39.6l-144 144c-6.7 6.7-6.7 17.7 0 24.4s17.7 6.7 24.4 0l176-176c24.4-24.4 24.4-64 0-88.4z"/></svg> <?php esc_html_e( 'Upload Photo', 'zorem-returns' ); ?></label>
						<input type="file" name="return_attachments[]" id="image-upload" class="file-upload-input" on="previewImages()" multiple
							style="display: none;">
						<div id="preview-container"></div>
					</div>

					<div class="keep-item-notice return-keep-item-notice" style="display:none;">
					<strong><?php esc_html_e( 'Keep Your Item', 'zorem-returns' ); ?></strong>
					<p><?php esc_html_e( 'No return shipping required. Customer retains the product. Refund will be processed automatically on approval.', 'zorem-returns' ); ?></p>
				</div>
				<input type="hidden" name="return_data[keep_the_item]" value="yes" class="keep-item-flag-input" disabled>
				<?php
					if ( zorem_returns_exchanges()->restocking_fee && zorem_returns_exchanges()->restocking_fee->is_enabled() ) :
					$rf_label = zorem_returns_exchanges()->restocking_fee->get_label();
						?>
					<div class="zorem-restocking-fee-summary" style="display:none;">
						<h4 class="zorem-rf-title"><?php esc_html_e( 'Refund Summary', 'zorem-returns' ); ?></h4>
						<div class="zorem-rf-row">
							<span class="zorem-rf-label"><?php esc_html_e( 'Items refund', 'zorem-returns' ); ?></span>
							<span class="zorem-rf-value zorem-rf-total-refund">&mdash;</span>
						</div>
						<div class="zorem-rf-row zorem-rf-fee-row" style="display:none;">
							<span class="zorem-rf-label"><?php echo esc_html( $rf_label ); ?></span>
							<span class="zorem-rf-value zorem-rf-fee">&mdash;</span>
						</div>
						<div class="zorem-rf-row zorem-rf-net-row">
							<span class="zorem-rf-label"><strong><?php esc_html_e( 'Estimated refund', 'zorem-returns' ); ?></strong></span>
							<span class="zorem-rf-value zorem-rf-net"><strong>&mdash;</strong></span>
						</div>
						<p class="zorem-rf-note"><?php esc_html_e( 'Final refund amount may be adjusted by the store before approval.', 'zorem-returns' ); ?></p>
					</div>
				<?php endif; ?>
				<div class="return-payment-methods-content">
						<label class="step-desc"><?php esc_html_e( 'Select a Return Resolution', 'zorem-returns' ); ?></label>
						<ul class="payment-methods-list">
							<?php $settings_menu_value = zorem_returns_exchanges()->common->return_settings( 'refund_payment_options', array( 'customer_account' => '1' ) ); ?>

							<?php if ( ! empty( $settings_menu_value ) && '1' == $settings_menu_value['store_credit'] ) { ?>
								<li class="single-method">
									<input type="radio" name="return_data[payment_method]" id="payment_method__store_credit" value="store_credit" class="payment_method" <?php echo ( '0' == $settings_menu_value['customer_account'] ) ? 'checked="checked"' : ''; ?>>
									<label class="method_label" for="payment_method__store_credit"><?php esc_html_e( 'Refund to Store Credit', 'zorem-returns' ); ?></label>
									<?php // echo ('no' == $return_entire_order) ? wp_kses_post(wc_price($sub_total)) : ''; ?>
								</li>
							<?php } ?>
							<?php if ( empty( $settings_menu_value ) || '1' == $settings_menu_value['customer_account'] ) { ?>
								<li class="single-method">
									<input type="radio" name="return_data[payment_method]" id="payment_method__customer_account" value="customer_account" class="payment_method" checked="checked">
									<label class="method_label" for="payment_method__customer_account"><?php esc_html_e( 'Refund to Original Payment Method', 'zorem-returns' ); ?></label>
									<?php // echo ('no' == $return_entire_order) ? wp_kses_post(wc_price($sub_total)) : ''; ?>
								</li>
							<?php } ?>
							<?php if ( ! empty( $settings_menu_value ) && ! empty( $settings_menu_value['tera_wallet'] ) && '1' == $settings_menu_value['tera_wallet'] && function_exists( 'woo_wallet' ) ) { ?>
								<li class="single-method">
									<input type="radio" name="return_data[payment_method]" id="payment_method__tera_wallet" value="tera_wallet" class="payment_method">
									<label class="method_label" for="payment_method__tera_wallet"><?php esc_html_e( 'Refund to Wallet', 'zorem-returns' ); ?></label>
								</li>
							<?php } ?>
							<?php if ( ! empty( $settings_menu_value ) && ! empty( $settings_menu_value['keep_item_partial'] ) && '1' == $settings_menu_value['keep_item_partial'] ) {
								$rt_keep_pct = absint( zorem_returns_exchanges()->common->return_settings( 'keep_item_partial_refund_percentage', 15 ) );
								?>
								<li class="single-method">
									<input type="radio" name="return_data[payment_method]" id="payment_method__keep_item_partial" value="keep_item_partial" class="payment_method">
									<label class="method_label" for="payment_method__keep_item_partial">
										<?php
										/* translators: %d — partial refund percentage. */
										echo esc_html( sprintf( __( 'Keep the Item & Receive a %d%% Partial Refund', 'zorem-returns' ), $rt_keep_pct ) );
										?>
									</label>
								</li>
							<?php } ?>
						</ul>
					</div>
					<div class="return-terms-content">
						<?php $terms_and_conditions_page = zorem_returns_exchanges()->common->return_settings( 'return_terms_and_conditions_page', '' ); ?>
						<input type="checkbox" name="return_terms" id="return_terms">
						<label for="return_terms">
							<?php
							// translators: %1$s: opening anchor tag, %2$s: closing anchor tag
							echo wp_kses_post( sprintf( __( 'I agree to the %1$sterms and conditions%2$s.', 'zorem-returns' ), '<a href="' . esc_url( get_permalink( $terms_and_conditions_page ) ) . '" target="_blank" rel="noreferrer noopener">', '</a>' ) );
							?>
						</label>
						<h5 class="no_agree"><?php esc_html_e( 'Please agree to the terms and conditions before submitting your request.', 'zorem-returns' ); ?></h5>
					</div>
					<button type="button" class="return_button next-step button" data-step="1"><?php esc_html_e( 'Request a Return', 'zorem-returns' ); ?></button>
					<input type="hidden" name="action" value="save_return_center_data">
					<?php $ajax_nonce = wp_create_nonce( 'return_center_request_form' ); ?>
					<input type="hidden" name="return_center_request_nonce" id="return_center_request_nonce" value="<?php echo esc_html( $ajax_nonce ); ?>" />
					<?php if ( is_account_page() ) { ?>
					<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'view-order' ) . $order->get_id() ); ?>" class="view-order-back-link">
						<?php echo esc_html( apply_filters( 'zorem_returns_keep_item_text', __( 'I’ll Keep This Item', 'zorem-returns' ) ) ); ?>
					</a>
					<?php } else { ?>
						<button class="cancel-form-btn view-order-back-link" type="button" data-order-id="<?php echo esc_attr( $order_id ); ?>">
							<?php echo esc_html( apply_filters( 'zorem_returns_keep_item_text', __( 'I’ll Keep This Item', 'zorem-returns' ) ) ); ?>
						</button>
					<?php } ?>
				<?php } ?>
			</div>
			<div class="step" id="step-2"></div>
			<div class="step" id="step-3"></div>
		</div>
	</div>
</form>
