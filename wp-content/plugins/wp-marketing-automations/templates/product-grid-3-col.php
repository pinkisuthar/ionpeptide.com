<?php
defined( 'ABSPATH' ) || exit;
$n        = 1;
$currency = is_array( $data ) && isset( $data['currency'] ) ? $data['currency'] : '';

/** checking if woocommerce exists other wise return */
if ( ! function_exists( 'bwfan_is_woocommerce_active' ) || ! bwfan_is_woocommerce_active() ) {
	return;
}

add_action( 'bwfan_output_email_style', function () { ?>
    .bwfan-email-product-3-col .bwfan-product-grid {
    width: 100%;
    border-collapse: collapse;
    max-width:700px;
    }
    .bwfan-email-product-3-col .bwfan-product-grid .bwfan-product-grid-item-3-col img {
    height: auto !important;
    }
    .bwfan-email-product-3-col .bwfan-product-grid-item-3-col {
    width: 29.5%;
    display: inline-block;
    text-align: center;
    padding: 0 0 20px;
    vertical-align: top;
    word-wrap: break-word;
    margin-right: 4%;
    font-size: 14px;
    }
    #body_content .bwfan-email-product-3-col .bwfan-product-grid-item-3-col h4 {
    text-align: center;
    }
    #body_content .bwfan-email-product-3-col .bwfan-product-grid-item-3-col p.price {
    margin-bottom: 0;
    }
<?php } );

if ( is_array( $products ) ) : ?>
    <div class='bwfan-email-product-3-col bwfan-email-table-wrap'>
        <table cellspacing="0" cellpadding="0" class="bwfan-product-grid">
            <tbody>
            <tr>
                <td style="padding: 0;">
                    <div class="bwfan-product-grid-container">
						<?php
						$disable_product_link      = BWFAN_Common::disable_product_link();
						$disable_product_thumbnail = BWFAN_Common::disable_product_thumbnail();

						if ( false !== $cart ) {
							$cartItemLinkEnabled = apply_filters( 'bwfan_block_editor_enable_cart_item_link', true );
							$suffix              = BWFAN_Common::get_wc_tax_label_if_displayed();
							foreach ( $cart as $item ) {
								$product = isset( $item['data'] ) ? $item['data'] : '';
								if ( empty( $product ) || ! $product instanceof WC_Product ) {
									continue; // don't show items if there is no product
								}
								$price      = isset( $products_price[ $product->get_id() ] ) ? $products_price[ $product->get_id() ] : null;
								$line_total = is_null( $price ) ? BWFAN_Common::get_prices_with_tax( $product ) : $price;
								?>
                                <div class="bwfan-product-grid-item-3-col bwfan-product-type-cart" style="<?php echo( $n % 3 ? '' : 'margin-right: 0;' ); ?>">
									<?php if ( false === $disable_product_thumbnail ) : ?>
										<?php if ( true === $cartItemLinkEnabled ) :
											$cartItemLink = BWFAN_Common::decode_merge_tags( apply_filters( 'bwfan_block_editor_alter_cart_item_link', '{{cart_recovery_link}}' ) );
											?>
                                            <a href="<?php echo esc_url( $cartItemLink ); ?>" target="_blank">
												<?php echo wp_kses_post( BWFAN_Common::get_product_image( $product, 'shop_catalog', false, 150 ) ); ?>
                                            </a>
										<?php else : ?>
											<?php echo wp_kses_post( BWFAN_Common::get_product_image( $product, 'shop_catalog', false, 150 ) ); ?>
										<?php endif; ?>
									<?php endif; ?>
                                    <h4 style="vertical-align:middle;"><?php echo wp_kses_post( BWFAN_Common::get_name( $product ) ); ?></h4>
                                    <p class="price">
	                                    <?php if( false === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ): ?>
											<strong>
			                                    <?php
			                                    echo BWFAN_Common::price( $line_total, $currency ); //phpcs:ignore WordPress.Security.EscapeOutput
			                                    ?>
											</strong>
		                                    <?php if ( $suffix && wc_tax_enabled() ): ?>
												<small><?php echo $suffix; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></small>
		                                    <?php endif; ?>
	                                    <?php else: ?>
											<strong><?php echo $price; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
	                                    <?php endif; ?>
                                    </p>
                                </div>
								<?php
								$n ++;
							}
						} else {
							foreach ( $products as $product ) {
								if ( ! $product instanceof WC_Product ) {
									continue;
								}
								$price      = isset( $products_price[ $product->get_id() ] ) ? $products_price[ $product->get_id() ] : null;
								$line_total = is_null( $price ) ? BWFAN_Common::get_prices_with_tax( $product ) : $price;
								?>
                                <div class="bwfan-product-grid-item-3-col bwfan-product-type-product" style="<?php echo( $n % 3 ? '' : 'margin-right: 0;' ); ?>">
									<?php
									if ( true === $disable_product_link ) {
										echo ( false === $disable_product_thumbnail ) ? BWFAN_Common::get_product_image( $product, 'shop_catalog', false, 150 ) : ''; //phpcs:ignore WordPress.Security.EscapeOutput ?>
                                        <h4><?php echo wp_kses_post( BWFAN_Common::get_name( $product ) ); ?></h4>
										<?php
									} else {
										if ( false === $disable_product_thumbnail ) {
											?>
                                            <a href="<?php echo esc_url_raw( $product->get_permalink() ); //phpcs:ignore WordPress.Security.EscapeOutput ?>"><?php echo wp_kses_post( BWFAN_Common::get_product_image( $product, 'shop_catalog', false, 150 ) ); //phpcs:ignore WordPress.Security.EscapeOutput ?></a>
											<?php
										}
										?>
                                        <h4 style="vertical-align:middle;">
                                            <a href="<?php echo esc_url_raw( $product->get_permalink() ); //phpcs:ignore WordPress.Security.EscapeOutput ?>"><?php echo wp_kses_post( BWFAN_Common::get_name( $product ) ); ?></a>
                                        </h4>
										<?php
									}
									?>
                                    <p class="price" style="vertical-align:middle;">
                                        <strong><?php echo wp_kses_post( BWFAN_Common::price( $line_total ) ); //phpcs:ignore WordPress.Security.EscapeOutput ?></strong>
                                    </p>
                                </div>
								<?php
								$n ++;
							}
						}
						?>
                    </div>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
<?php endif;
