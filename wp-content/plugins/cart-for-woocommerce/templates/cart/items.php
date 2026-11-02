<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$front = \FKCart\Includes\Front::get_instance();
do_action( 'fkcart_before_cart_items', $front );
$cart_contents        = $front->get_items();
$is_you_saved_enabled = \FKCart\Includes\Data::is_you_saved_enabled();


?>
    <div class="fkcart-item-wrap fkcart-pt-16">
		<?php
		foreach ( $cart_contents as $cart_item_key => $cart_item ) {
			/** Admin preview */
			if ( fkcart_is_preview() ) {
				fkcart_get_template_part( 'cart/item-single-preview', '', [ 'cart_item' => $cart_item ] );
				continue;
			}

			if ( isset( $cart_item['visibility_hidden'] ) ) {
				continue;
			}

			if ( isset( $cart_item['cart_item']['_fkcart_spl_addon'] ) && true === $cart_item['cart_item']['_fkcart_spl_addon'] ) {
				continue;

			}
			fkcart_get_template_part( 'cart/item-single', '', [ 'cart_item' => $cart_item, 'cart_item_key' => $cart_item_key ] );
		}
		?>
    </div>
<?php
do_action( 'fkcart_after_cart_items', $front );
