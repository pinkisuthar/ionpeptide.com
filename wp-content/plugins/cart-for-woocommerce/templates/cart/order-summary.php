<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$front = \FKCart\Includes\Front::get_instance();

$settings = \FKCart\Includes\Data::get_settings();

$coupons                       = $front->get_coupons();
$tax_enabled                   = wc_tax_enabled();
$shipping_enabled              = wc_shipping_enabled();
$coupon_enable                 = wc_coupons_enabled();
$subtotal                      = ( 'true' === $settings['show_sub_total'] || true === $settings['show_sub_total'] );
$shipping_tax_calculation_text = isset( $settings['shipping_tax_calculation_text'] ) ? $settings['shipping_tax_calculation_text'] : esc_attr__( 'Shipping & taxes may be re-calculated at checkout', 'cart-for-woocommerce' );
?>
<div class="fkcart-order-summary fkcart-panel ">
    <div class="fkcart-order-summary-container">
        <div class="fkcart-summary-line-item fkcart-subtotal-wrap <?php echo( ! $subtotal ? "fkcart-hide" : "" ); ?>">
            <div class="fkcart-summary-text"><strong><?php esc_html_e( 'Subtotal', 'woocommerce' ) ?></strong></div>
            <div class="fkcart-summary-amount"><strong><?php echo wp_kses_post( $front->get_subtotal_row() ) ?></strong></div>
        </div>
		<?php
		if ( $coupon_enable ) {
			foreach ( $coupons as $code => $coupon ) {
				?>
                <div class="fkcart-summary-line-item fkcart-coupon-applied">
                    <div class="fkcart-summary-text fkcart-coupon-text">
                        <div class="fkcart-coupon-label"><?php _e( 'Coupon', 'woocommerce' ) ?></div>

                        <div class="fkcart-coupon-code fkcart-coupon-code-wrapper">
                            <span>
                                <?php
                                if ( fkcart_is_preview() ) {
	                                echo esc_html( $coupon['code'] );
                                } else {
	                                echo wp_kses_post( apply_filters( 'woocommerce_cart_totals_coupon_label', $coupon['code'], isset( $coupon['instance'] ) ? $coupon['instance'] : '' ) );
                                }
                                ?>
                            </span>
                            <span class="fkcart-remove-coupon" data-coupon="<?php esc_attr_e( $coupon['code'] ) ?>">
                            <?php fkcart_get_template_part( 'icon/white-close', '', [ 'width' => 8, 'height' => 8 ] ); ?>
                            </span>
                        </div>
                    </div>
                    <div class="fkcart-summary-amount">-<?php echo wp_kses_post( $coupon['value'] ) ?></div>
                </div>
			<?php }
		}

		if ( $shipping_enabled && class_exists( 'FKCart\Pro\Rewards' ) && ! is_null( WC()->session ) ) {
			$free_shipping = WC()->session->get( '_fkcart_free_shipping_methods', '' );
			if ( ! empty( $free_shipping ) ) {
				?>
                <div class="fkcart-summary-line-item fkcart-coupon-applied fkcart-shipping-wrap">
                    <div class="fkcart-summary-text"><?php _e( 'Shipping', 'woocommerce' ) ?></div>
                    <div class="fkcart-summary-amount"><?php echo apply_filters( 'fkcart_free_shipping_text', __( 'Free', 'woocommerce' ), $front ); ?></div>
                </div>
				<?php
			}
		}

		if ( ! is_null( WC()->session ) && ! is_null( WC()->cart ) ) {
			if ( wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() ) {
				$taxable_address = WC()->customer->get_taxable_address();
				$estimated_text  = '';

				if ( WC()->customer->is_customer_outside_base() && ! WC()->customer->has_calculated_shipping() ) {
					/* translators: %s location. */
					$estimated_text = sprintf( ' <small>' . esc_html__( '(estimated for %s)', 'woocommerce' ) . '</small>', WC()->countries->estimated_for_prefix( $taxable_address[0] ) . WC()->countries->countries[ $taxable_address[0] ] );
				}

				if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) {
					foreach ( WC()->cart->get_tax_totals() as $code => $tax ) {
						?>
                        <div class="fkcart-summary-line-item fk-tax-rate fk-tax-rate-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
                            <div class="fkcart-summary-text"><?php echo esc_html( $tax->label ) . $estimated_text; ?></div>
                            <div data-title="<?php echo esc_attr( $tax->label ); ?>" class="fkcart-summary-amount">
								<?php echo wp_kses_post( $tax->formatted_amount ); ?>
                            </div>
                        </div>
						<?php
					}
				} else {
					$tax_total = WC()->cart->get_taxes_total( true, false );
					// Display tax only if the tax total is greater than zero when using the single total tax setting
					$display_tax = apply_filters( 'fkcart_display_single_total_tax', $tax_total > 0, WC()->cart );
					if ( $display_tax ) {
						?>
                        <div class="fkcart-summary-line-item fk-tax-rate">
                            <div class="fkcart-summary-text"><?php echo esc_html( WC()->countries->tax_or_vat() ) . $estimated_text; ?></div>
                            <div data-title="<?php echo esc_attr( WC()->countries->tax_or_vat() ); ?>" class="fkcart-summary-amount">
								<?php wc_cart_totals_taxes_total_html(); ?>
                            </div>
                        </div>
						<?php
					}
				}
			}
		}
		if ( $tax_enabled || $shipping_enabled ) {
			?>
            <div class="fkcart-summary-line-item">
                <div class="fkcart-summary-text fkcart-shipping-tax-calculation-text"><?php echo wp_kses_post( $shipping_tax_calculation_text ) ?></div>
            </div>
		<?php } ?>

		<?php do_action( 'fkcart_after_order_summary', $front ); ?>
        <div class="fkcart-text-light"></div>
    </div>
</div>
