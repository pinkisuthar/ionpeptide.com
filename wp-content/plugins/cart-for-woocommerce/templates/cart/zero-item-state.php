<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$front    = \FKCart\Includes\Front::get_instance();
$settings = \FKCart\Includes\Data::get_settings();

$hide_zero_state = 'fkcart-hide';
if ( ! is_null( WC()->cart ) && WC()->cart->is_empty() ) {
	$hide_zero_state = '';
}
$link_behaviour = isset( $settings['zero_state_link_behaviour'] ) ? $settings['zero_state_link_behaviour'] : 'close_cart';
$shop_link      = ( true === fkcart_is_preview() ) || ( $link_behaviour === 'close_cart' ) ? '#' : get_permalink( wc_get_page_id( 'shop' ) );
$shop_link      = apply_filters( 'fkcart_zero_state_shop_link', $shop_link, $front );
if ( '#' !== $shop_link ) {
	$link_behaviour = '';
}
?>
    <div class="fkcart-zero-state <?php echo esc_attr( $hide_zero_state ) ?>">
        <div class="fkcart-icon-cart">
			<?php fkcart_get_template_part( 'icon/checkout', '', [ 'width' => 56, 'height' => 56 ] ); ?>
        </div>
        <div class="fkcart-zero-state-title"><?php esc_html_e( $settings['zero_state_title'] ) ?></div>
        <div class="fkcart-zero-state-text"><?php esc_html_e( $settings['zero_state_description'] ) ?></div>
        <a href="<?php echo esc_url( $shop_link ) ?>" class="fkcart-primary-button fkcart-shop-button <?php echo( $link_behaviour === 'close_cart' ? 'fkcart-modal-close' : '' ); ?>"><?php esc_html_e( $settings['zero_state_btn_text'] ) ?></a>
    </div>
<?php
unset( $shop_link );
