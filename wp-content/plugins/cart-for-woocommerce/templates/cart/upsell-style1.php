<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$front    = \FKCart\Includes\Front::get_instance();
$settings = \FKCart\Includes\Data::get_settings();

$cart_contents = $front->get_upsell_products();
if ( empty( $cart_contents ) ) {
	return;
}
?>
<!-- START: Style 1 -->
<div class="fkcart-item-wrap fkcart-carousel-wrap fkcart-upsell-style1">
    <div class="fkcart--item-heading fkcart-upsell-heading fkcart-t--center fkcart-panel"><?php esc_html_e( isset( $settings['upsell_heading'] ) ? $settings['upsell_heading'] : __( 'Even better With These!', 'cart-for-woocommerce' ) ); ?></div>
    <div class="fkcart-carousel fkcart-panel">
        <!-- make data-slide-item count 2 for grid/column view -->
        <div class="fkcart-carousel__viewport" data-slide-item="1" data-count="<?php esc_attr_e( count( $cart_contents ) ); ?>">
            <div class="fkcart-carousel__container">
				<?php
				$count = 0;
				foreach ( $cart_contents as $cart_item_key => $cart_item ) {
					$is_variable = false;
					$price       = '';
					$button      = __( 'Add', 'woocommerce' );
					$product_id  = 0;
					$permalink   = '';
					if ( fkcart_is_preview() ) {
						$_product   = '';
						$price      = $front->get_dummy_product_price( $cart_item );
						$product_id = $cart_item['product_id'];
						$p_type     = 'simple';
					} else {
						/**
						 * @var $_product WC_Product
						 */
						$_product = $cart_item['product'];
						$p_type   = $_product->get_type();

						$is_variable = ( fkcart_is_variable_product_type( $_product->get_type() ) );

						$price = $is_variable ? $_product->get_price_html() : ( $_product->is_on_sale() || $_product->is_taxable() ? $_product->get_price_html() : $_product->get_price_html() );

						$button     = $is_variable ? __( 'Select options', 'woocommerce' ) : $button;
						$product_id = $_product->get_id();
						$permalink  = $_product->get_permalink();

						if ( false === $_product->is_purchasable() ) {
							continue;
						}
					}
					$count ++;
					?>
                    <!-- Cart Item -->
                    <div class="fkcart--item fkcart-carousel__slide" data-key="<?php esc_attr_e( $cart_item_key ) ?>">
						<?php echo wp_kses_post( $cart_item['thumbnail'] ) ?>
                        <div class="fkcart-item-info">
                            <div class="fkcart-item-meta">
								<?php echo wp_kses_post( $cart_item['product_name'] ) ?>
                                <div class="fkcart-item-meta-content"><?php echo wp_kses_post( $cart_item['product_meta'] ) ?></div>
								<?php
								if ( fkcart_product_add_supported( $_product ) ) {
									?>
                                    <div class="fkcart-<?php echo esc_attr( $is_variable ? 'select-product' : 'add-product-button' ) ?> fkcart-button" data-id="<?php esc_html_e( $product_id ); ?>">
										<?php esc_attr_e( $button ) ?>
                                    </div>
								<?php } else {
									?>
                                    <a href="<?php echo esc_url( $permalink ); ?>" class="fkcart-redirect-product fkcart-button" data-id="<?php esc_html_e( $product_id ); ?>">
										<?php echo esc_html__( 'Select options', 'woocommerce' ); ?>
                                    </a>
									<?php
								} ?>
                            </div>
                        </div>
                        <div class="fkcart-item-misc">
							<?php do_action( 'fkcart_before_upsell_price', $product_id, $cart_item ); ?>
                            <div class="fkcart-item-price"><?php echo wp_kses_post( $price ) ?></div>
							<?php do_action( 'fkcart_after_upsell_price', $product_id, $cart_item ); ?>
                        </div>
                    </div>
					<?php
				}
				?>
            </div>
        </div>

		<?php
		if ( $count > 1 ) {
			?>
            <!-- Carousel Navigation -->
            <div class="fkcart-nav-btn fkcart-nav-btn--prev" type="button">
				<?php fkcart_get_template_part( 'icon/arrow', '', [ 'direction' => 'left' ] ); ?>
            </div>
            <div class="fkcart-nav-btn fkcart-nav-btn--next" type="button">
				<?php fkcart_get_template_part( 'icon/arrow', '', [ 'direction' => 'right' ] ); ?>
            </div>
            <!-- Carousel Dots -->
			<?php
		}
		?>
		<?php
		echo ( $count > 1 ) ? '<div class="fkcart-carousel-dots"></div>' : '<div class="fkcart-no-carousel"></div>';
		?>
        <script type="text/template" id="fkcart-carousel-dot-template">
            <div class="fkcart-carousel-dot" type="button"></div>
        </script>
    </div>
</div>
<!-- END: Style 1 -->
