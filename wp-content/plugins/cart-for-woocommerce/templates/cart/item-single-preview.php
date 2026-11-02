<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * @var $cart_item []
 */
$front         = \FKCart\Includes\Front::get_instance();
$cart_item_key = $cart_item['product_id'];
$you_save_text = \FKCart\Includes\Data::you_save_text();

$is_you_saved_class = '';
if ( ! empty( $cart_item['saving_percent'] ) ) {
	$is_you_saved_class = 'fkcart_save_class_active';
}
?>
<div class="fkcart--item fkcart-panel fkcart-preview <?php echo esc_attr( $is_you_saved_class ); ?>" data-key="<?php esc_attr_e( $cart_item_key ) ?>">

    <div class="fkcart-thumb-wrap">
		<?php echo wp_kses_post( $cart_item['thumbnail'] ) ?>
        <div class="fkcart-remove-item" data-key="<?php esc_attr_e( $cart_item_key ) ?>">
            <span><?php fkcart_get_template_part( 'icon/close', '', [ 'width' => 14, 'height' => 14 ] ); ?></span>
        </div>
    </div>
    <div class="fkcart-item-info fkcart-item-wrap-start">
        <div class="fkcart-item-meta">
            <div class="fkcart-item-title-price">
				<?php echo wp_kses_post( $cart_item['product_name'] ); ?>
                <div class="fkcart-item-meta-content"><?php echo wp_kses_post( $cart_item['product_meta'] ) ?></div>
            </div>

            <div class="fkcart-qty-wrap">
                <div class=" fkcart-quantity-selector">
					<?php
					$min  = 1;
					$max  = 10;
					$step = 1;
					?>
                    <div class="fkcart-quantity-button fkcart-quantity-down" data-action="down">
						<?php fkcart_get_template_part( 'icon/minus' ); ?>
                    </div>
                    <input class="fkcart-quantity__input" name="fkcart-quantity__input" type="text" inputmode="numeric" aria-label="Quantity" step="<?php esc_attr_e( $step ) ?>" min="<?php esc_attr_e( $min ) ?>" max="<?php esc_attr_e( $max ) ?>" data-key="<?php esc_attr_e( $cart_item_key ) ?>" pattern="[0-9]*" value="1">
                    <div class="fkcart-quantity-button fkcart-quantity-up" data-action="up">
						<?php fkcart_get_template_part( 'icon/plus' ); ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="fkcart-item-misc">
            <div class="fkcart-item-price">
				<?php echo wp_kses_post( $cart_item['price'] ) ?>
				<?php
				if ( ! empty( $cart_item['saving_percent'] ) ) {
					?>
                    <div class="fkcart-discounted-price">
                        <div
                            class="fkcart-discounted-text"
                            data-percent="<?php isset( $cart_item['saving_percent'] ) ? esc_attr_e( $cart_item['saving_percent'] ) : ''; ?>"
                            data-saving="<?php isset( $cart_item['saving_amount'] ) ? esc_attr_e( $cart_item['saving_amount'] ) : ''; ?>"
                        >
							<?php esc_html_e( $you_save_text ); ?>
                        </div>
                    </div>
					<?php
				}
				?>
            </div>


        </div>
    </div>

</div>
