<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$settings     = \FKCart\Includes\Data::get_settings();
$is_minimized = ( 'minimized' === $settings['coupon_display'] );
?>
<div class="fkcart-coupon-area">
    <div class="fkcart-coupon-head fkcart-panel">
        <div class="fkcart-coupon-title"><?php esc_html_e( $settings['coupon_heading'] ); ?></div>
        <div class="fkcart-coupon-icon">
			<?php fkcart_get_template_part( 'icon/arrow', '', [ 'direction' => 'down', 'hide' => ! $is_minimized ] ); ?>
			<?php fkcart_get_template_part( 'icon/arrow', '', [ 'direction' => 'up', 'hide' => $is_minimized ] ); ?>
        </div>
    </div>
    <div class="fkcart-coupon-body fkcart-panel" style="<?php esc_attr_e( $is_minimized ? "display:none;" : "" ); ?>">
        <div class="fkcart-coupon-input-wrap">
            <input type="text" aria-label="<?php esc_attr_e( $settings['coupon_placeholder_text'] ); ?>" id="fkcart-coupon__input" placeholder="<?php esc_attr_e( $settings['coupon_placeholder_text'] ); ?>" required=""/>
            <div class="fkcart-primary-button fkcart-coupon-button"><?php esc_attr_e( $settings['coupon_button_text'] ); ?></div>
        </div>
        <div class="fkcart-input-error fkcart-hide" data-content="<?php esc_attr_e( 'Invalid coupon code', 'woocommerce' ); ?>"></div>
    </div>
</div>