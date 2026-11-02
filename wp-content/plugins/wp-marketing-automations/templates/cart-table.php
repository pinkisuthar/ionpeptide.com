<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** checking if woocommerce exists other wise return */
if ( ! function_exists( 'bwfan_is_woocommerce_active' ) || ! bwfan_is_woocommerce_active() ) {
	return;
}

add_action( 'bwfan_output_email_style', function () { ?>
    .bwfan-email-cart-table #template_header {
    width: 100%;
    }

    .bwfan-email-cart-table table {
    border: 2px solid #e5e5e5;
    border-collapse: collapse;
    max-width:700px;
    }

    .bwfan-email-cart-table table tr th, .bwfan-email-cart-table table tr td {
    border: 2px solid #e5e5e5;
    }
<?php } );

$subtotal     = 0;
$subtotal_tax = 0;
$total        = 0;
$text_align   = is_rtl() ? 'text-align:right;' : 'text-align:left;';

$disable_product_thumbnail = BWFAN_Common::disable_product_thumbnail();
$currency                  = is_array( $data ) & isset( $data['currency'] ) ? $data['currency'] : '';
$lang                      = is_array( $data ) & isset( $data['lang'] ) ? $data['lang'] : '';
$colspan                   = ' colspan=2';
$colspan_foot              = ' colspan=3';
if ( true === $disable_product_thumbnail ) {
	$colspan      = '';
	$colspan_foot = ' colspan="2"';
}
do_action( 'bwfan_email_setup_locale', $lang );

/** Tax settings */
$tax_display = get_option( 'woocommerce_tax_display_cart' );
$tax_string  = '';
if ( wc_tax_enabled() ) {
	$tax_string = WC()->countries->tax_or_vat();
}
$suffix         = BWFAN_Common::get_wc_tax_label_if_displayed();
$discount_total = 0;
if ( is_array( $data ) && ! empty( $data['coupons'] ) && is_array( $data['coupons'] ) ) {
	foreach ( $data['coupons'] as $coupon ) {
		if ( $tax_display === 'excl' ) {
			$discount_total += ! empty( $coupon['discount_excl_tax'] ) ? $coupon['discount_excl_tax'] : 0;
		} else {
			$discount_total += ! empty( $coupon['discount_incl_tax'] ) ? $coupon['discount_incl_tax'] : 0;
		}
		if ( isset( $coupon['discount_tax'] ) && floatval( $coupon['discount_tax'] ) > 0 ) {
			$subtotal_tax -= ! empty( $coupon['discount_tax'] ) ? $coupon['discount_tax'] : 0;
		}
	}
}
?>
<div class='bwfan-email-cart-table bwfan-email-table-wrap'>
    <table cellspacing="0" cellpadding="6" border="1" width="100%">
        <thead>
        <th class="td" scope="col" <?php echo esc_html( $colspan ); //phpcs:ignore WordPress.Security.EscapeOutput ?> style="<?php echo esc_html( $text_align ); ?> white-space: nowrap;">
            <?php esc_html_e( 'Product', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></th>
        <th class="td" scope="col" style="width:90px;text-align:center;white-space: nowrap;"><?php esc_html_e( 'Quantity', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></th>
        <th class="td" scope="col" style="width:90px;text-align:center;white-space: nowrap;"><?php esc_html_e( 'Price', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></th>
        </thead>
        <tbody>
		<?php
		if ( false !== $cart ) {
			$cartItemLinkEnabled = apply_filters( 'bwfan_block_editor_enable_cart_item_link', true );
			foreach ( $cart as $item ) :
				$product = isset( $item['data'] ) ? $item['data'] : '';
				if ( empty( $product ) || ! $product instanceof WC_Product ) {
					continue;
				}

				// Calculate prices based on tax display setting
				$line_total = ( $tax_display === 'excl' ) ? BWFAN_Common::get_line_subtotal( $item ) : BWFAN_Common::get_line_subtotal( $item ) + BWFAN_Common::get_line_subtotal_tax( $item );

				$subtotal     += $line_total;
				$subtotal_tax += BWFAN_Common::get_line_subtotal_tax( $item );
				?>
                <tr>
					<?php if ( false === $disable_product_thumbnail ) : ?>
                        <td class="image" style="width: 15%;min-width: 40px;">
							<?php if ( true === $cartItemLinkEnabled ) :
								$cartItemLink = BWFAN_Common::decode_merge_tags( apply_filters( 'bwfan_block_editor_alter_cart_item_link', '{{cart_recovery_link}}' ) );
								?>
                                <a href="<?php echo esc_url( $cartItemLink ); ?>" target="_blank">
									<?php echo wp_kses_post( BWFAN_Common::get_product_image( $product, 'thumbnail', false, 100 ) ); ?>
                                </a>
							<?php else : ?>
								<?php echo wp_kses_post( BWFAN_Common::get_product_image( $product, 'thumbnail', false, 100 ) ); ?>
							<?php endif; ?>
                        </td>
					<?php endif; ?>
                    <td style="width: 60% !important;">
                        <h4 style="vertical-align:middle; <?php echo esc_html( $text_align ); ?> word-wrap: break-word;">
							<?php
								echo wp_kses_post( BWFAN_Common::get_name( $product ) );
								// Meta data.
								if ( false === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
									echo wc_get_formatted_cart_item_data( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}

							?>
                        </h4>
                    </td>
                    <td style="vertical-align:middle; <?php echo esc_html( $text_align ); ?> white-space: nowrap;">
						<?php
						if ( false === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) ) {
							echo esc_html( BWFAN_Common::get_quantity( $item ) );
						} else {
							echo esc_html( 1 );
						}
						?>
                    </td>
                    <td style="vertical-align:middle; <?php echo esc_html( $text_align ); ?> white-space: nowrap;">
						<?php echo wp_kses_post( BWFAN_Common::price( $line_total, $currency ) ); ?>
						<?php if ( false === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) && wc_tax_enabled() && ! empty( $suffix ) ) : ?>
                            <small><?php echo $suffix; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></small>
						<?php endif; ?>
                    </td>
                </tr>
			<?php endforeach;
		} else {
			foreach ( $products as $product ) {
				?>
                <tr>
					<?php if ( false === $disable_product_thumbnail ) : ?>
                        <td class="image" width="100">
							<?php echo wp_kses_post( BWFAN_Common::get_product_image( $product, 'thumbnail', false, 100 ) ); ?>
                        </td>
					<?php endif; ?>
                    <td>
                        <h4 style="vertical-align:middle; <?php echo esc_html( $text_align ); ?> white-space: nowrap;"><?php esc_html_e( 'Test Product', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></h4>
                    </td>
                    <td style="vertical-align:middle; <?php echo esc_html( $text_align ); ?> white-space: nowrap;">1</td>
                    <td style="vertical-align:middle; white-space: nowrap;"><?php echo wp_kses_post( BWFAN_Common::price( 0, $currency ) ); ?></td>
                </tr>
				<?php
			}
		}
		?>
        </tbody>
        <tfoot>
        <tr>
            <th scope="row" <?php echo esc_html( $colspan_foot ); ?> style="<?php echo esc_html( $text_align ); ?>">
				<?php esc_html_e( 'Subtotal', 'woocommerce' );// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?>
            </th>
            <td>
				<?php echo wp_kses_post( BWFAN_Common::price( $subtotal, $currency ) ); ?>
				<?php if ( false === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) && wc_tax_enabled() && ! empty( $suffix ) ) : ?>
                    <small><?php echo $suffix; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></small>
				<?php endif; ?>
            </td>
        </tr>

		<?php if ( is_array( $data ) && isset( $data['shipping_total'] ) && ! empty( $data['shipping_total'] ) && '0.00' !== $data['shipping_total'] ) :
			$shipping_total = ( $tax_display === 'excl' ) ? $data['shipping_total'] : $data['shipping_total'] + ( $data['shipping_tax_total'] ?? 0 );
			$shipping_tax = $data['shipping_tax_total'] ?? 0;
			if ( ! empty( $shipping_tax ) ) {
				$subtotal_tax += floatval( $shipping_tax );
			}
			?>
            <tr>
                <th scope="row" <?php echo esc_html( $colspan_foot ); ?> style="<?php echo esc_html( $text_align ); ?>">
					<?php esc_html_e( 'Shipping', 'woocommerce' );// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					?>
                </th>
                <td><?php echo BWFAN_Common::price( $shipping_total, $currency ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					<?php if ( false === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) && wc_tax_enabled() && ! empty( $suffix ) && $shipping_tax > 0 ) : ?>
                        <small><?php echo $suffix; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></small>
					<?php endif; ?>
                </td>

            </tr>
		<?php endif; ?>
		<?php if ( $discount_total > 0 ) : ?>
            <tr>
                <th scope="row" <?php echo esc_html( $colspan_foot ); ?> style="<?php echo esc_html( $text_align ); ?>">
					<?php esc_html_e( 'Discount', 'woocommerce' );// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?>
                </th>
                <td>-<?php echo BWFAN_Common::price( $discount_total, $currency );//phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
            </tr>
		<?php endif; ?>

		<?php if ( is_array( $data ) && isset( $data['fees'] ) && ! empty( $data['fees'] ) ) :
			$fees_total = 0;
			foreach ( $data['fees'] as $fee ) {
				if ( ! isset( $fee->name ) || empty( $fee->total ) ) {
					continue;
				}
				$fee_total = ( $tax_display === 'excl' ) ? $fee->total : $fee->total + ( $fee->tax ?? 0 );
				$fees_total += $fee_total;
				if ( ! empty( $fee->tax ) ) {
					$subtotal_tax += floatval( $fee->tax );
				}
				?>
                <tr>
                    <th scope="row" <?php echo esc_html( $colspan_foot ); ?> style="<?php echo esc_html( $text_align ); ?>">
                        <?php echo esc_html( $fee->name) ?: esc_html__( 'Fee', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?>
                    </th>
                    <td><?php echo BWFAN_Common::price( $fee_total, $currency ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                </tr>
			<?php }

		endif; ?>

		<?php if ( wc_tax_enabled() && $tax_display === 'excl' && $subtotal_tax ) : ?>
            <tr>
                <th scope="row" <?php echo $colspan_foot; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> style="<?php echo esc_html( $text_align ); ?>">
				<?php echo esc_html( $tax_string ); ?>
                </th>
                <td><?php echo wp_kses_post( BWFAN_Common::price( $subtotal_tax, $currency ) ); ?></td>
            </tr>
		<?php endif; ?>

        <tr>
            <th scope="row" <?php echo $colspan_foot; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> style="<?php echo esc_html( $text_align ); ?>">
				<?php esc_html_e( 'Total', 'woocommerce' );// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?>
				<?php if ( false === BWFAN_Merge_Tag_Loader::get_data( 'is_preview' ) && wc_tax_enabled() && $tax_display !== 'excl' ) : ?>
                    <small><?php printf( __( '(includes %s)', 'woocommerce' ), BWFAN_Common::price( $subtotal_tax, $currency ) );// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.MissingTranslatorsComment, WordPress.Security.EscapeOutput.OutputNotEscaped ?></small>
				<?php endif; ?>
            </th>
            <td>
				<?php
				$final_total = $subtotal;
				if ( isset( $shipping_total ) ) {
					$final_total += $shipping_total;
				}
				if ( ! empty( $data['fees'] ) && isset( $fees_total ) ) {
					$final_total += $fees_total;
				}
				if ( $tax_display === 'excl' ) {
					$final_total += $subtotal_tax;
				}
				if ( $discount_total > 0 ) {
					$final_total -= $discount_total;
				}

				echo wp_kses_post( BWFAN_Common::price( $final_total, $currency ) );
				?>
            </td>
        </tr>
        </tfoot>
    </table>
</div>
