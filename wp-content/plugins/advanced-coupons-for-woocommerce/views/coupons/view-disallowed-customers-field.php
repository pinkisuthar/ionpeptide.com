<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>
<div class="options_group">
<p class="form-field">
    <label for="acfw_disallowed_customers"><?php esc_html_e( 'Disallowed customers', 'advanced-coupons-for-woocommerce' ); ?></label>
    <select class="wc-customer-search acfw-disallowed-customers" multiple style="width: 50%;" name="<?php echo esc_attr( $field_name_disallowed_customer ); ?>[]"
        data-placeholder="<?php esc_attr_e( 'Search customers&hellip;', 'advanced-coupons-for-woocommerce' ); ?>">
        <?php foreach ( $disallowed_customers as $disallowed_customer ) : ?>
            <option value="<?php echo esc_attr( $disallowed_customer->get_id() ); ?>" selected>
            <?php echo esc_html( sprintf( '%s (#%s - %s)', $helper_functions->get_customer_name( $disallowed_customer ), $disallowed_customer->get_id(), $helper_functions->get_customer_email( $disallowed_customer ) ) ); ?>
        </option>
        <?php endforeach ?>
    </select>
    <?php echo wp_kses_post( wc_help_tip( __( 'Search and select customers that are NOT allowed to use this coupon.', 'advanced-coupons-for-woocommerce' ) ) ); ?>
</p>
</div>
