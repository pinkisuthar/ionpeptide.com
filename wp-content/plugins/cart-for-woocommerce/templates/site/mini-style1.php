<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$front    = \FKCart\Includes\Front::get_instance();
$settings = \FKCart\Includes\Data::get_settings();

$icon            = $settings['cart_icon'];
$icon_size       = $settings['cart_menu_icon_size'];
$display_count   = $settings['display_menu_product_count'];
$display_total   = $settings['display_menu_total'];
$cart_item_count = $front->get_cart_content_count();
?>
<div id="fkcart-mini-toggler" class="fkcart-shortcode-container fkcart-mini-open fkcart-mini-toggler">
    <div class="fkcart-shortcode-icon-wrap">
		<?php
		$enable_accessibility = apply_filters( 'fkcart_enable_accessibility_link', false, $front );
		$enable_accessibility = is_bool( $enable_accessibility ) ? $enable_accessibility : false;
		echo $enable_accessibility ? '<a class="fkcart-accessibility-link" href="javascript:void(0)" role="button" aria-label="' . esc_attr__( 'Open cart', 'cart-for-woocommerce' ) . '">' : '';

		fkcart_get_template_part( 'icon/cart/' . $icon, '', [ 'width' => $icon_size, 'height' => $icon_size ], true );

		echo $enable_accessibility ? '</a>' : '';

		if ( true === $display_count || 'true' === strval( $display_count ) ) {
			?>
            <div class="fkcart-shortcode-count fkcart-item-count" data-item-count="<?php echo floatval( $cart_item_count ); ?>"><?php esc_attr_e( $cart_item_count ); ?></div>
			<?php
		}
		?>
    </div>
	<?php
	if ( true === $display_total || 'true' === strval( $display_total ) ) {
		?>
        <div class="fkcart-shortcode-price">
			<?php echo wp_kses_post( $front->get_subtotal() ); ?>
        </div>
		<?php
	}
	?>
</div>
