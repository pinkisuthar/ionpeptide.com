<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
exit;
} ?>

<p class="form-field acfw-sort-coupon-priority-field" data-value="<?php echo esc_attr( $coupon_priority ); ?>">
    <label for="_acfw_coupon_sort_priority"><?php esc_html_e( 'Sort priority in cart', 'advanced-coupons-for-woocommerce' ); ?></label>
    <select id="_acfw_coupon_sort_select">
        <?php foreach ( $priority_options as $value => $label ) : ?>
            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $coupon_priority ); ?>><?php echo esc_html( $label ); ?></option>
        <?php endforeach; ?>
        <option value="custom" <?php selected( true, $is_custom ); ?>><?php esc_html_e( 'Custom', 'advanced-coupons-for-woocommerce' ); ?></option>
    </select>
    <input type="number" id="_acfw_coupon_sort_priority" name="_acfw_coupon_sort_priority" value="<?php echo esc_attr( $coupon_priority ); ?>" min="1" />
    <?php echo wp_kses_post( wc_help_tip( __( 'Sort priority value that will be used to determine the order of the coupon. Coupons are sorted (ascendingly) in the cart/checkout during calculation of the cart totals. Set to a lower value to prioritize the coupon or a higher value if you want it to be applied last. Default value is "Normal (30)".', 'advanced-coupons-for-woocommerce' ) ) ); ?>
</p>
