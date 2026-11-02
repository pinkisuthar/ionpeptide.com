<?php
/**
 * Single variation cart button
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 7.0.1
 */

defined( 'ABSPATH' ) || exit;
$front           = \FKCart\Includes\Front::get_instance();
$quick_view      = \FKCart\Includes\Quickview::get_instance();
$is_free_product = false;
$quantity        = 1;
if ( ! empty( $quick_view->cart_key ) ) {
	$item            = WC()->cart->get_cart_item( $quick_view->cart_key );
	$is_free_product = isset( $item['_fkcart_free_gift'] );
	$quantity        = $item['quantity'];
}
global $product;
if ( $is_free_product ) {
	?>
    <style>
        .fkcart-quick-view-drawer .fkcart-panel .woocommerce-variation-price {
            visibility: hidden;
        }
        .fkcart-quick-view-drawer .fkcart-panel .fkcart-item-custom {
            visibility: hidden;
        }
    </style>
	<?php
}
?>
<div class="fkcart-product-form-field" data-quantity="<?php echo esc_html( $quantity ) ?>">
    <div class="woocommerce-variation-add-to-cart variations_button">
		<?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>
		<?php if ( ! $is_free_product ): ?>
            <label for="fkcart-select-option" class="fkcart-input-label"><?php esc_html_e( 'Quantity', 'woocommerce' ) ?></label>
            <div class="fkcart-form-input-wrap" style="margin:2px 0 0">
                <div class="fkcart-quantity-selector">
                    <div class="fkcart-quantity-button fkcart-quantity-down" data-action="down">
						<?php fkcart_get_template_part( 'icon/minus' ); ?>
                    </div>
					<?php
					list( $min, $max, $step ) = $front->get_min_max_step( $product );
					?>
                    <input class="fkcart-quantity__input" type="text" autocomplete="off" name="quantity" aria-label="Quantity" step="<?php esc_attr_e( $step ) ?>" min="<?php esc_attr_e( $min ) ?>" max="<?php esc_attr_e( $max ) ?>" pattern="[0-9]*" value="<?php echo esc_attr( $quantity ) ?>">
                    <div class="fkcart-quantity-button fkcart-quantity-up" data-action="up">
						<?php fkcart_get_template_part( 'icon/plus' ); ?>
                    </div>
                </div>
            </div>
		<?php endif; ?>
		<?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>
		<?php
		if ( ! is_null( $quick_view->cart_key ) && ! empty( $quick_view->cart_key ) ) {
			?>
            <input type="hidden" name="fkcart-cart-key" clas="fkcart-cart-key" value="<?php esc_attr_e( $quick_view->cart_key ); ?>">
			<?php
		}
		?>
        <input type="hidden" name="product_id" value="<?php esc_attr_e( intval( $product->get_id() ) ); ?>"/>
        <input type="hidden" name="variation_id" class="variation_id" value="0"/>
    </div>
</div>
