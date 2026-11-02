<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$settings   = \FKCart\Includes\Data::get_settings();
$cart_count = '';
if ( ! fkcart_is_preview() && WC()->cart->get_cart_contents_count() > 0 ) {
	$cart_count = "<span>(" . WC()->cart->get_cart_contents_count() . ")</span>";
}

?>
<div class="fkcart-slider-heading fkcart-panel">
    <div class="fkcart-title"><?php echo wp_kses_post( $settings['cart_heading'] ); ?><?php echo wp_kses_post( $cart_count ); ?></div>
    <div class="fkcart-modal-close">
		<?php fkcart_get_template_part( 'icon/close', '', [ 'width' => 20, 'height' => 20 ] ); ?>
    </div>
</div>