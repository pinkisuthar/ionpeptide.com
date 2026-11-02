<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>
<div class="options_group">
<p class="form-field">
    <label for="acfw_allowed_customers"><?php esc_html_e( 'Allowed customers', 'advanced-coupons-for-woocommerce' ); ?></label>
    <select class="wc-customer-search acfw-allowed-customers" multiple style="width: 50%;" name="<?php echo esc_attr( $field_name_allowed_customer ); ?>[]"
        data-placeholder="<?php esc_attr_e( 'Search customers&hellip;', 'advanced-coupons-for-woocommerce' ); ?>">
        <?php foreach ( $allowed_customers as $allowed_customer ) : ?>
            <option value="<?php echo esc_attr( $allowed_customer->get_id() ); ?>" selected>
            <?php echo esc_html( sprintf( '%s (#%s - %s)', $helper_functions->get_customer_name( $allowed_customer ), $allowed_customer->get_id(), $helper_functions->get_customer_email( $allowed_customer ) ) ); ?>
        </option>
        <?php endforeach ?>
    </select>
    <?php echo wp_kses_post( wc_help_tip( __( 'Search and select customers that are eligible to only use this coupon.', 'advanced-coupons-for-woocommerce' ) ) ); ?>
</p>
</div>
