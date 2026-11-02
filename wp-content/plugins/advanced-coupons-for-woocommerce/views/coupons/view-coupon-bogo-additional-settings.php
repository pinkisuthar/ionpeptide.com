<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="bogo-settings-field bogo-auto-add-products-field <?php echo 'specific-products' === $deals_type ? 'show' : ''; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'acfw_save_bogo_additional_settings' ) ); ?>">
    <label><?php esc_html_e( 'Automatically add deal products to cart:', 'advanced-coupons-for-woocommerce' ); ?></label>
    <input type="checkbox" name="acfw_bogo_auto_add_products" value="yes" <?php checked( $auto_add_products, true ); ?> />
</div>
<div class="bogo-settings-field bogo-discount-order-field">
    <label><?php esc_html_e( 'Apply discount to:', 'advanced-coupons-for-woocommerce' ); ?></label>
    <select name="acfw_bogo_discount_order">
        <option value="cheapest" <?php selected( $discount_order, 'cheapest' ); ?>>
            <?php esc_html_e( 'Cheapest eligible products', 'advanced-coupons-for-woocommerce' ); ?>
        </option>
        <option value="expensive" <?php selected( $discount_order, 'expensive' ); ?>>
            <?php esc_html_e( 'Most expensive eligible products', 'advanced-coupons-for-woocommerce' ); ?>
        </option>
    </select>
    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e( 'Choose whether to apply the BOGO discount to the least or most expensive eligible products first', 'advanced-coupons-for-woocommerce' ); ?>"></span>
</div>
