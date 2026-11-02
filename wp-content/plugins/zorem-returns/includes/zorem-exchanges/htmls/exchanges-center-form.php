<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial: variables come from the including class scope.
defined( 'ABSPATH' ) || exit;
?>
<form id="exchange-multi-step-form" >
	<div class="multi-form-container">
		<?php
		$exchange_entire_order = zorem_returns_exchanges()->common->return_settings( 'exchange_entire_order', 'no' );
		if ( 'Yes' == $exchange_entire_order && ! empty( $itemid ) ) {
			?>
		<h2 class="form-heading" style="display: flex;flex-direction: column;">
			<?php if ( is_account_page() ) { ?>
		<span style="font-size: 14px;margin-bottom: 5px;"><strong style="color: #008BC5;"><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'view-order' ) . $order->get_id() ); ?>" style="color: #008BC5;"><?php esc_html_e( 'View Order', 'zorem-returns' ); ?></a> ></strong> <?php esc_html_e( 'Exchange Item', 'zorem-returns' ); ?></span>
		<?php } ?>
			<?php esc_html_e( 'Exchange Item', 'zorem-returns' ); ?></h2>
			<?php
		} else {
			?>
		<h2 class="form-heading" style="display: flex;flex-direction: column;">
			<?php if ( is_account_page() ) { ?>
			<span style="font-size: 14px;margin-bottom: 5px;"><strong style="color: #008BC5;"><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'view-order' ) . $order->get_id() ); ?>" style="color: #008BC5;"><?php esc_html_e( 'View Order', 'zorem-returns' ); ?></a> ></strong> <?php esc_html_e( 'Exchange Entire Order', 'zorem-returns' ); ?></span>
		<?php } ?>
			<?php esc_html_e( 'Exchange Entire Order', 'zorem-returns' ); ?></h2>
			<?php
		}
		?>
		<div class="form-content">
			<input type="hidden" id="order_id" name="order_id" value="<?php echo esc_html( $order->get_id( ) ); ?>">
			<?php $ajax_nonce = wp_create_nonce( 'exchange_center_multiStep_form' ); ?>
			<input type="hidden" name="exchange_center_multiStep_nonce" id="exchange_center_multiStep_nonce" value="<?php echo esc_html( $ajax_nonce ); ?>" />
			<div class="step" id="step-1">
				<?php
				// Exchange item options
				$exchange_form_data = zorem_returns_exchanges()->exCommon->get_exchanged_form_data( $order );
				if ( ! empty( $exchange_form_data ) ) {
					$sub_total          = '0';
					$any_keep_the_item  = false;
					$keep_the_item_count = 0;
					$total_product_count = 0;
					?>
					<label>
						<?php esc_html_e( 'Products in This Order', 'zorem-returns' ); ?>
					</label>
					<?php
					$exchange_entire_order = zorem_returns_exchanges()->common->return_settings( 'exchange_entire_order', 'no' );
					foreach ( $exchange_form_data as $item_id => $item ) {

						if ( ! empty( $itemid ) && $itemid != $item_id ) {
							continue;
						}

						$order_item           = new WC_Order_Item_Product( $item_id );
						$available_variations = zorem_returns_exchanges()->exCommon->is_item_price_match_variation( $order_item );
						if ( $available_variations ) {
							if ( is_a( $order_item, 'WC_Order_Item_Product' ) ) {
								$product_id         = $order_item->get_product_id();
								$keep_the_item_meta = get_post_meta( $product_id, '_per_product_keep_item', true );
								$product    = wc_get_product( $product_id );
								if ( ! is_object( $product ) ) {
									continue;
								}
								$total_product_count++;
								$product_image_url = wp_get_attachment_url( $product->get_image_id() );
								$product_title     = $product->get_name();
								$product_price     = $order->get_formatted_line_subtotal( $order_item );
								$product_qty       = isset( $exchange_form_data[ $item_id ]['product_quantity'] ) ? $exchange_form_data[ $item_id ]['product_quantity'] : 0;
								$sub_total         = $order_item->get_product()->get_price() * $product_qty;
								$item_all_meta     = $order_item->get_all_formatted_meta_data();
								$variants          = array();
								foreach ( $item_all_meta as $item ) {
									$variants[ $item->key ] = $item->value;
								}
								$variable_product_id = wc_get_order_item_meta( $item_id, '_product_id', true );
								$variation_id        = $order_item->get_variation_id();

								if ( 'yes' === $keep_the_item_meta ) {
									$keep_the_item_count++;
								}
							}
							?>
							<div class="request_item_details">
								<input type="hidden" id="item_id" name="item_id" value="<?php echo esc_html( $item_id ); ?>">
								<input type="hidden" id="variation_id" name="variation_id" value="<?php echo esc_html( $variation_id ); ?>">
								<div class="product-details">

								<?php if ( 'yes' == $exchange_entire_order && empty( $itemid ) ) { ?>
									<input type="checkbox" name="exchange_data[exchange_items][<?php echo esc_html( $item_id ); ?>][exchange_product_id]" class="product_checked" value="<?php echo esc_html( $item_id ); ?>">
								<?php } else { ?>
									<input type="hidden" name="exchange_data[exchange_items][<?php echo esc_html( $item_id ); ?>][exchange_product_id]" value="<?php echo esc_html( $item_id ); ?>">
									<input type="hidden" name="exchange_data[exchange_items][<?php echo esc_html( $item_id ); ?>][exchange_product_qty]" value="<?php echo esc_html( $product_qty ); ?>">
								<?php } ?>

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
										<strong><?php echo esc_html__( 'Qty. ', 'zorem-returns' ); ?></strong>
										<?php if ( 'yes' == $exchange_entire_order ) { ?>
											<select name="exchange_data[exchange_items][<?php echo esc_html( $item_id ); ?>][exchange_product_qty]" class="order_quantity_number">
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
										 * inside the item block on the exchange request form. Counterpart
										 * of `zorem_return_request_form_item_fields` — see its docblock in
										 * returns-center-form.php for full usage. Injected inputs arrive in
										 * $_POST (FormData) and are available to the
										 * `zorem_exchange_request_validation` filter, e.g. name them
										 * exchange_data[item_fields][<item_id>][field].
										 *
										 * @param string                $html       Field markup to inject (default '').
										 * @param WC_Order_Item_Product $order_item Current order item.
										 * @param WC_Order              $order      The order being exchanged against.
										 */
										echo apply_filters( 'zorem_exchange_request_form_item_fields', '', $order_item, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- developer-supplied form field markup.
										?>
									</div>
								</div>
								<?php if ( ! ( class_exists( 'Zorem_Exchange_Product_Search' ) && Zorem_Exchange_Product_Search::is_enabled() ) ) : ?>
								<div class="exchange_variations">
									<label><?php esc_html_e( 'Exchange With', 'zorem-returns' ); ?></label>
									<div class="cfw-input-wrap cfw-floating-label">
									<?php
										// $attributes = $product->get_variation_attributes();
										$attributes = zorem_returns_exchanges()->exCommon->get_filtered_variation_attributes_by_price( $order_item, $product );

									?>
										<label>
											<?php esc_html_e( 'Select Exchange Variant', 'zorem-returns' ); ?>*
										</label>
										</br>
										<?php
										foreach ( $attributes as $attribute => $options ) {
											if ( empty( $options ) ) {
												continue;
											}
											// Get readable label (e.g., "pa_size" -> "Size")
											$attr_label = wc_attribute_label( $attribute );
											?>
											<label for="variants-<?php echo esc_attr( $attribute ); ?>">
												<?php echo esc_html( $attr_label ); ?>
											</label>

											<select class="mb1em variants" name="exchange_data[exchange_items][<?php echo esc_html( $item_id ); ?>][variants][<?php echo esc_attr( $attribute ); ?>]" id="variants" data-key="<?php echo esc_attr( strtolower( $attribute ) ); ?>" required>
												<option value=""><?php esc_html_e( 'Select an Option', 'zorem-returns' ); ?></option>
												<?php
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

													// Custom attributes fallback
													if ( '' === $option_label ) {
														$option_label = apply_filters( 'woocommerce_variation_option_name', $option );
													}

													$option_label     = ucfirst( strtolower( $option_label ) );
													$sorted_options[] = array(
														'label' => $option_label,
														'value' => $option_value,
													);
												}
												usort(
													$sorted_options,
													function ( $a, $b ) {
														return strcasecmp( $a['label'], $b['label'] );
													}
												);

												foreach ( $sorted_options as $opt ) {
													?>
													<option value="<?php echo esc_attr( $opt['value'] ); ?>">
														<?php echo esc_html( $opt['label'] ); ?>
													</option>
													<?php
												}
												?>
											</select>
											</br>
										<?php } ?>
										<p class="select_available_variation"><?php esc_html_e( 'You can only exchange this item for a variant of equal value.', 'zorem-returns' ); ?></p>
									</div>
								</div>
							<?php endif; // not cross-product ?>
							<?php if ( class_exists( 'Zorem_Exchange_Product_Search' ) && Zorem_Exchange_Product_Search::is_enabled() ) : ?>
							<!-- Per-item cross-product search: each item picks its OWN replacement
							     product; a multi-item exchange becomes one request + one child order. -->
							<div class="zre-cross-product-wrap" data-item-id="<?php echo esc_attr( $item_id ); ?>">
								<label class="zre-cp-label"><?php esc_html_e( 'Exchange this item with', 'zorem-returns' ); ?></label>
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
									<!-- Price difference banner (this item only) -->
									<div class="zre-cp-price-banner" style="display:none;"></div>
								</div>
								<!-- Hidden fields submitted with the form — PER ITEM -->
								<input type="hidden" name="exchange_data[exchange_items][<?php echo esc_attr( $item_id ); ?>][new_product_id]" class="zre-cp-pid-field" value="">
								<input type="hidden" name="exchange_data[exchange_items][<?php echo esc_attr( $item_id ); ?>][new_variation_id]" class="zre-cp-vid-field" value="">
							</div><!-- /.zre-cross-product-wrap -->
							<?php endif; ?>
							</div>
							<?php
						}
					}
					$any_keep_the_item = ( $total_product_count > 0 && $keep_the_item_count === $total_product_count );

					// ── Cross-product exchange ────────────────────────────────────────
					// Search widgets are rendered per item above; this hidden field is
					// set to 'cross-product' by the validation JS once a replacement is
					// chosen (the server also derives it from the per-item targets).
					$cross_product_enabled = class_exists( 'Zorem_Exchange_Product_Search' ) && Zorem_Exchange_Product_Search::is_enabled();
					if ( $cross_product_enabled ) :
						?>
					<input type="hidden" name="exchange_data[exchange_type]" class="zre-cp-type-field" value="">
					<?php
					endif; // cross_product_enabled

					// Description Options
					$default_return_reasons = array(
						'Wrong Size or Fit',
						'Wrong Color or Style',
						'Wrong item was sent',
						"Item defective or doesn't work",
						'Items or parts missing',
					);
					$exchange_reasons       = zorem_returns_exchanges()->common->return_settings( 'exchange_reasons', $default_return_reasons );
					?>
					<h5 class="select_product_item"><?php esc_html_e( 'Please select at least one product.', 'zorem-returns' ); ?></h5>
					<h5 class="select_item"><?php esc_html_e( 'Please select a quantity for the selected items.', 'zorem-returns' ); ?></h5>
					<div class="exchange_dropdown">
						<div class="cfw-input-wrap cfw-floating-label">
							<label>
								<?php esc_html_e( 'Select a Reason for Exchange', 'zorem-returns' ); ?>*
							</label>
							<select class="mb1em" name="exchange_data[exchange_reason][no_other]" id="exchange_reason" required>
								<?php
								foreach ( $exchange_reasons as $value ) {
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
						<div class="exchange_reason mb1em cfw-input-wrap">
							<textarea name="exchange_data[exchange_reason][other_reason]" id="other_reason" rows="3"
								cols="50"></textarea>
						</div>
					</div>
					<div class="exchange_desc mb1em cfw-input-wrap cfw-floating-label">
						<label>
							<?php esc_html_e( 'Describe the issue with the product', 'zorem-returns' ); ?>:*
						</label>
						<textarea name="exchange_data[exchange_issue]" id="exchange_issue" rows="3" cols="50"></textarea>
					</div>
					<div class="exchange_media">
						<label for="image-upload" class="select-media button"><svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M364.2 83.8c-24.4-24.4-64-24.4-88.4 0l-184 184c-42.1 42.1-42.1 110.3 0 152.4s110.3 42.1 152.4 0l152-152c10.9-10.9 28.7-10.9 39.6 0s10.9 28.7 0 39.6l-152 152c-64 64-167.6 64-231.6 0s-64-167.6 0-231.6l184-184c46.3-46.3 121.3-46.3 167.6 0s46.3 121.3 0 167.6l-176 176c-28.6 28.6-75 28.6-103.6 0s-28.6-75 0-103.6l144-144c10.9-10.9 28.7-10.9 39.6 0s10.9 28.7 0 39.6l-144 144c-6.7 6.7-6.7 17.7 0 24.4s17.7 6.7 24.4 0l176-176c24.4-24.4 24.4-64 0-88.4z"/></svg> <?php esc_html_e( 'Upload Photo', 'zorem-returns' ); ?></label>
						<input type="file" name="exchange_attachments[]" id="image-upload" class="file-upload-input" on="previewImages()" multiple
							style="display: none;">
						<div id="preview-container"></div>
					</div>

					<?php if ( $any_keep_the_item ) { ?>
					<div class="keep-item-notice">
						<strong><?php esc_html_e( 'Keep Your Item', 'zorem-returns' ); ?></strong>
						<?php esc_html_e( 'You will receive your replacement item and you do not need to return your original product.', 'zorem-returns' ); ?>
					</div>
					<input type="hidden" name="exchange_data[keep_the_item]" value="yes">
					<?php } ?>

					<div class="exchange-terms-content">
						<?php $terms_and_conditions_page = zorem_returns_exchanges()->common->return_settings( 'return_terms_and_conditions_page', '' ); ?>
						<input type="checkbox" name="exchange_terms" id="exchange_terms">
						<label for="exchange_terms">
							<?php
							// translators: %1$s: opening anchor tag, %2$s: closing anchor tag
							echo wp_kses_post( sprintf( __( 'I agree to the %1$sterms and conditions%2$s.', 'zorem-returns' ), '<a href="' . esc_url( get_permalink( $terms_and_conditions_page ) ) . '" target="_blank" rel="noreferrer noopener">', '</a>' ) );
							?>
						</label>
						<h5 class="no_agree"><?php esc_html_e( 'Please agree to the terms and conditions before submitting your request.', 'zorem-returns' ); ?>*</h5>
					</div>

					<button type="button" class="exchange_button next-step button" data-step="1"><?php esc_html_e( 'Request an Exchange', 'zorem-returns' ); ?></button>
					<input type="hidden" name="action" value="save_exchange_center_data">
					<?php $ajax_nonce = wp_create_nonce( 'exchange_center_request_form' ); ?>
					<input type="hidden" name="exchange_center_request_nonce" id="exchange_center_request_nonce" value="<?php echo esc_html( $ajax_nonce ); ?>" />
					<?php if ( is_account_page() ) { ?>
					<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'view-order' ) . $order->get_id() ); ?>" class="view-order-back-link">
						<?php echo esc_html( apply_filters( 'zorem_returns_keep_item_text', __( 'I’ll Keep This Item', 'zorem-returns' ) ) ); ?>
					</a>
					<?php } else { ?>
						<button class="cancel-form-btn view-order-back-link" type="button" data-order-id="<?php echo esc_html( $order_id ); ?>">
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
