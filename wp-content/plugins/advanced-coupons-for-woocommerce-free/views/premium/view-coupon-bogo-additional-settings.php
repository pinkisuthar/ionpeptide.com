<?php if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// Exit if accessed directly. ?>

<div class="bogo-settings-field bogo-auto-add-products-field upsell <?php echo 'specific-products' === $deals_type ? 'show' : ''; ?>">
    <label><?php esc_html_e( 'Automatically add deal products to cart (Premium):', 'advanced-coupons-for-woocommerce-free' ); ?></label>
    <input type="checkbox" name="acfw_bogo_auto_add_products" value="yes" />
</div>
<div class="bogo-settings-field bogo-discount-order-field upsell">
    <label><?php esc_html_e( 'Apply discount to (Premium):', 'advanced-coupons-for-woocommerce-free' ); ?></label>
    <select name="acfw_bogo_discount_order">
        <option value="none">
            <?php esc_html_e( 'Any eligible products', 'advanced-coupons-for-woocommerce-free' ); ?>
        </option>
        <option value="cheapest">
            <?php esc_html_e( 'Cheapest eligible products', 'advanced-coupons-for-woocommerce-free' ); ?>
        </option>
        <option value="expensive">
            <?php esc_html_e( 'Most expensive eligible products', 'advanced-coupons-for-woocommerce-free' ); ?>
        </option>
    </select>
    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e( 'Choose whether to apply the BOGO discount to the least or most expensive eligible products first', 'advanced-coupons-for-woocommerce-free' ); ?>"></span>
</div>
