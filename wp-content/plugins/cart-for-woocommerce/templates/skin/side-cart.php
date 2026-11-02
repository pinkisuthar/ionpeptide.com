<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_upsells_enabled    = \FKCart\Includes\Data::is_upsells_enabled();
$is_rewards_enabled    = \FKCart\Includes\Data::is_rewards_enabled();
$is_coupon_enabled     = \FKCart\Includes\Data::is_coupon_enabled();
$cart_settings         = \FKCart\Includes\Data::get_settings();
$front                 = \FKCart\Includes\Front::get_instance();
$special_addon_enabled = \FKCart\Includes\Data::is_special_addon_enabled();
$is_preview            = fkcart_is_preview();
$coupon_enable         = wc_coupons_enabled() && ( $is_coupon_enabled || $is_preview );

$show_at_load = ! did_action( 'wc_ajax_get_refreshed_fragments' ) && wp_doing_ajax();


$is_style1_upsell_enabled = $is_preview || ( $is_upsells_enabled && 'style1' === $cart_settings['upsell_style'] );
$is_style2_upsell_enabled = $is_preview || ( $is_upsells_enabled && 'style2' === $cart_settings['upsell_style'] );
$is_style3_upsell_enabled = $is_preview || ( $is_upsells_enabled && 'style3' === $cart_settings['upsell_style'] );
$is_style4_upsell_enabled = $is_preview || ( $is_upsells_enabled && 'style4' === $cart_settings['upsell_style'] );
$is_style5_upsell_enabled = $is_preview || ( $is_upsells_enabled && 'style5' === $cart_settings['upsell_style'] );

$has_zero_state  = '';
$cart_has_items  = true;
if ( ! $is_preview && ! is_null( WC()->cart ) && WC()->cart->is_empty() ) {
	$has_zero_state = 'has-zero-state';
	$cart_has_items = false;
}
$slider_footer_class = ( ! $is_upsells_enabled || $is_style1_upsell_enabled || $is_style2_upsell_enabled || $is_style3_upsell_enabled ) ? 'fkcart-pb-16' : '';
$slider_body_class   = '';
if ( $is_upsells_enabled && $is_style2_upsell_enabled ) {
	$slider_body_class = 'fkcart-body-275';
}
if ( $is_upsells_enabled && $is_style1_upsell_enabled ) {
	$slider_body_class = 'fkcart-body-150';
}

if ( $is_upsells_enabled && $is_style1_upsell_enabled ) {
	$slider_body_class = 'fkcart-body-style-3';
}
$slider_footer_class = empty( $slider_footer_class ) && ! defined( 'WFFN_PRO_BUILD_VERSION' ) ? 'fkcart-pb-24' : $slider_footer_class;

$slider_below_cta_class    = ( $is_style4_upsell_enabled || $is_style5_upsell_enabled ) ? 'fkcart-pt-16' : '';
$reward_progress_bar_style = $cart_settings['reward_progress_bar_style'] ? $cart_settings['reward_progress_bar_style'] : 'classic';

$reward_classes = [ 'fkcart-reward-product-wrap' ];
if ( isset( $cart_settings['reward_progress_bar_enable_animation'] ) && true === $cart_settings['reward_progress_bar_enable_animation'] ) {
	$reward_classes[] = 'fkcart-animation-active';
}

do_action( 'fkcart_before_modal_container', $front );
?>
    <div class="fkcart-modal-container <?php echo ( is_null( WC()->cart ) || WC()->cart->is_empty() ) ? '' : 'fkcart-has-items' ?>" data-direction="<?php esc_attr_e( is_rtl() ? 'rtl' : 'ltr' ); ?>" data-slider-pos="<?php esc_attr_e( $cart_settings['cart_icon_position'] ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'fkcart' ) ); ?>">
        <div class="fkcart-preview-ui <?php echo esc_attr( $has_zero_state ); ?>" data-anim="<?php echo esc_attr( $cart_settings['css_animation_speed'] ); ?>">
            <!-- Header -->
            <div class="fkcart-slider-header">
				<?php fkcart_get_template_part( 'cart/header-style1' ); ?>
            </div>

			<?php do_action( 'fkcart_after_header', $front ); ?>

            <!-- Reward -->

            <div class="<?php echo esc_attr( implode( ' ', $reward_classes ) ) ?>">
				<?php
				if ( fkcart_is_preview() ) {
					fkcart_get_template_part( 'cart/rewards/design-2' );
					fkcart_get_template_part( 'cart/rewards' );
				} else {
					if ( isset( $is_rewards_enabled ) && $reward_progress_bar_style == 'modern' ) {
						fkcart_get_template_part( 'cart/rewards/design-2' );
					} else {
						fkcart_get_template_part( 'cart/rewards' );
					}
				}
				?>

				<?php do_action( 'fkcart_before_body', $front ); ?>
                <!-- END: Reward -->

                <!-- Body -->
                <div class="fkcart-slider-body">
                    <!-- Cart Zero State -->
					<?php ! $is_preview && fkcart_get_template_part( 'cart/zero-item-state' ); ?>
                    <!-- END: Cart Zero State -->

                    <!-- START: Cart Items -->
					<?php fkcart_get_template_part( 'cart/items' ); ?>
                    <!-- END: Cart Items -->

                    <!-- START: Upsell Style -->
					<?php $cart_has_items && $is_style1_upsell_enabled && fkcart_get_template_part( 'cart/upsell-style1' ) ?>
					<?php $cart_has_items && $is_style2_upsell_enabled && fkcart_get_template_part( 'cart/upsell-style2' ) ?>
					<?php $cart_has_items && $is_style3_upsell_enabled && fkcart_get_template_part( 'cart/upsell-style3' ) ?>
                    <!-- END: Upsell Style -->
                </div>
				<?php do_action( 'fkcart_after_body', $front ); ?>
            </div>
            <!-- Slider Footer -->
            <div class="fkcart-slider-footer <?php echo esc_attr( $slider_footer_class ) ?>">

                <!-- START: Coupon Area -->
				<?php $cart_has_items && $coupon_enable && fkcart_get_template_part( 'cart/coupon-box' ) ?>
                <!-- END: Coupon Area -->


                <!-- START: Special Addon -->
				<?php
				do_action( 'fkcart_after_coupon_section', $cart_settings );
				?>

                <div class="fkcart_summary_cta">
                    <!-- START: Order Summary -->
					<?php $cart_has_items && fkcart_get_template_part( 'cart/order-summary' ); ?>
                    <!-- END: Order Summary -->

                    <!-- START: CTA -->
					<?php fkcart_get_template_part( 'cart/cart-cta' ); ?>
                    <!-- END: CTA -->
                </div>

                <!-- START: Upsell Style -->
                <div class="fkcart-below-checkout-upsell">
					<?php $cart_has_items && $is_style4_upsell_enabled && fkcart_get_template_part( 'cart/upsell-style1' ) ?>
					<?php $cart_has_items && $is_style5_upsell_enabled && fkcart_get_template_part( 'cart/upsell-style2' ) ?>
                </div>

				<?php if ( $special_addon_enabled ) : ?>
                    <div id="fkcart-popup" class="fkcart-popup">
                        <div class="fkcart-popup-content">
                            <div class="fkcart-title-wrap">
								<span class="fkcart-close">
									<?php fkcart_get_template_part( 'icon/close', '', [ 'width' => 20, 'height' => 20 ] ); ?>
								</span>
                            </div>

                            <div class="fkcart-item-meta-content"></div>
                        </div>
                    </div>
				<?php endif; ?>
            </div>
        </div>

        <!-- START: Quick View -->
		<?php fkcart_get_template_part( 'cart/item-quick-view' ); ?>
        <!-- END: Quick View -->

        <!-- Notice -->
        <div class="fkcart-slider-notices fkcart-hide-notice" id="fkcart-notice" data-status="error">
            <div class="fkcart-notice-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2C17.5228 2 22 6.47715 22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2ZM12 15.125C11.4822 15.125 11.0625 15.5447 11.0625 16.0625C11.0625 16.5803 11.4822 17 12 17C12.5178 17 12.9375 16.5803 12.9375 16.0625C12.9375 15.5447 12.5178 15.125 12 15.125ZM12 7C11.6932 7 11.438 7.22109 11.3851 7.51266L11.375 7.625V13.25L11.3851 13.3623C11.438 13.6539 11.6932 13.875 12 13.875C12.3068 13.875 12.562 13.6539 12.6149 13.3623L12.625 13.25V7.625L12.6149 7.51266C12.562 7.22109 12.3068 7 12 7Z" fill="currentColor"/>
                </svg>
            </div>
            <div class="fkcart-notice-text"></div>
        </div>

        <!-- Modal Shadow -->
        <div class="fkcart-modal-backdrop"></div>
    </div>
<?php
do_action( 'fkcart_after_modal_container', $front );;
