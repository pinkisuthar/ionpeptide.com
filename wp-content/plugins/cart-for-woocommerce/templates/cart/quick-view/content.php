<?php
global $product;
$front      = \FKCart\Includes\Front::get_instance();
$quick_view = \FKCart\Includes\Quickview::get_instance();
$item_data  = $front->get_preview_item( $product );
do_action( 'fkcart_quick_before_view_content' );

?>


    <div class="fkcart-drawer-content">
        <div class="fkcart-product-form-wrap">
            <div class="fkcart-product-form-thumbnail fkcart-panel">
				<?php echo wp_kses_post( $item_data['thumbnail'] ) ?>
            </div>
            <div class="fkcart-product-name-thumbnail fkcart-panel">
				<?php echo wp_kses_post( $item_data['product_name'] ) ?>
            </div>
            <div class="fkcart-product-form-fields fkcart-panel">
                <div class="fkcart-product-form-field">
					<?php
					$quick_view->get_forms();
					?>
                </div>
            </div>
        </div>
		<?php
		$desc = $product->get_short_description();
		if ( ! empty( $desc ) ) {
			?>
            <div class="fkcart-desc-title fkcart-panel"><?php esc_html_e( 'Description', 'woocommerce' ) ?></div>
            <div class="fkcart-product-description fkcart-panel fkcart-p-10">
                <div><?php echo wp_kses_post( $product->get_short_description() ); ?></div>
            </div>
			<?php
		}
		?>
        <div class="fkcart-view-link-wrap fkcart-panel">
            <a href="<?php echo esc_url( $product->get_permalink() ) ?>" class="fkcart-view-link">
				<?php echo esc_html( apply_filters( 'fkcart_view_details', __( 'View details', 'woocommerce' ) ) ); ?>
            </a>
        </div>
        <div class="fkcart-product-form-button fkcart-panel" style="">
            <button type="submit" class="fkcart-primary-button fkcart-full-width fkcart-add-variant-product"><?php ! empty( $quick_view->cart_key ) ? esc_html_e( 'Update', 'woocommerce' ) : esc_html_e( 'Add to cart', 'woocommerce' ); ?></button>
        </div>
    </div>
<?php
do_action( 'fkcart_quick_after_view_content' );
