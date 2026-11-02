<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$front            = \FKCart\Includes\Front::get_instance();
$settings         = \FKCart\Includes\Data::get_settings();
$should_hide_cart = \FKCart\Includes\Data::hide_empty_cart();
$icon             = $settings['floating_icon'];
$cart_item_count  = $front->get_cart_content_count();
if ( isset( $floating_icon ) ) {
	$icon = $floating_icon;
}

$icon_class = [ 'fkcart-toggler' ];
if ( true === $should_hide_cart ) {
	$icon_class[] = 'fkcart-should-hide';
}
?>
<div id="fkcart-floating-toggler" class="<?php echo esc_attr( implode( ' ', $icon_class ) ); ?>" data-position="<?php esc_attr_e( $settings['cart_icon_position'] ); ?>">
    <div class="fkcart-floating-icon">
		<?php fkcart_get_template_part( 'icon/cart/' . $icon, '', [], true ) ?>
    </div>
    <div class="fkcart-item-count" id="fkit-floating-count" data-item-count="<?php echo esc_attr( floatval( $cart_item_count ) ); ?>"><?php echo esc_html( $cart_item_count ); ?></div>
</div>
