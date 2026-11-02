<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hide_zero_state = 'fkcart-hide';
if ( ! fkcart_is_preview() && ! is_null( WC()->cart ) && WC()->cart->is_empty() ) {
	return;
}
$front    = \FKCart\Includes\Front::get_instance();
$settings = \FKCart\Includes\Data::get_settings();

$button_icon             = ( 'true' === $settings['show_button_lock_icon'] || true === $settings['show_button_lock_icon'] );
$button_price            = ( 'true' === $settings['show_button_price'] || true === $settings['show_button_price'] );
$continue_link           = ( 'true' === $settings['show_shop_continue_link'] || true === $settings['show_shop_continue_link'] );
$continue_link_behaviour = isset( $settings['continue_shopping_behaviour'] ) ? $settings['continue_shopping_behaviour'] : 'close_cart';

$cart_link = true === fkcart_is_preview() ? '#' : wc_get_checkout_url();
$shop_link = true === fkcart_is_preview() || $continue_link_behaviour === 'close_cart' ? '#' : get_permalink( wc_get_page_id( 'shop' ) );
$shop_link = apply_filters( 'fkcart_shop_continue_link', $shop_link, $front );
$cart_link = apply_filters( 'fkcart_cart_link', $cart_link, $front );
$cart_text = apply_filters( 'fkcart_cart_link_text', $settings['checkout_button_text'], $front );


if ( '#' !== $shop_link ) {
	$continue_link_behaviour = '';
}
do_action( 'fkcart_before_smart_button', $front );

/** Load smart buttons */
$front->get_smart_buttons();
$total_amount = $front->get_subtotal();
if ( ! is_null( WC()->session ) && ! is_null( WC()->cart ) && wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() ) {
	$total_amount = WC()->cart->get_total();

}

do_action( 'fkcart_before_checkout_button', $front );
?>
    <div class="fkcart-checkout-wrap fkcart-panel">
        <div class="fkcart-checkout-info">
            <a href="<?php echo esc_url( $cart_link ); ?>" id="fkcart-checkout-button">
                <div class="fkcart-checkout--icon <?php echo( ! $button_icon ? "fkcart-hide" : "" ); ?>">
					<?php fkcart_get_template_part( 'icon/checkout' ); ?>
                </div>
                <div class="fkcart-checkout--text"><?php esc_attr_e( $cart_text ) ?></div>
                <div class="fkcart-checkout--price <?php echo( ! $button_price ? "fkcart-hide" : "" ); ?>">
					<?php
					if ( fkcart_is_preview() ) {
						$discount_enabled = ( 'true' === $settings['enable_coupon_box'] || true === $settings['enable_coupon_box'] );
						echo wp_kses_post( '<div class="fkcart-checkout--price-discounted ' . ( ! $discount_enabled ? "fkcart-hide" : "" ) . '">' . $front->get_discounted_subtotal() . '</div>' );
						echo wp_kses_post( '<div class="fkcart-checkout--price-normal ' . ( $discount_enabled ? "fkcart-hide" : "" ) . '">' . $front->get_subtotal() . '</div>' );
					} else {
						echo wp_kses_post( apply_filters( 'fkcart_checkout_button_total', $total_amount ) );
					}
					?>
                </div>
            </a>
        </div>
        <a href="<?php echo esc_url( $shop_link ) ?>" class="fkcart-shopping-link <?php echo( ! $continue_link ? "fkcart-hide" : "" ); ?> <?php echo( $continue_link_behaviour === 'close_cart' ? 'fkcart-modal-close' : '' ); ?>"><?php echo esc_html( $settings['continue_shopping_text'] ); ?></a>
    </div>
<?php
do_action( 'fkcart_after_checkout_button', $front );
unset( $shop_link );
