<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>
<div class="options_group">
    <p class="form-field acfw-disallowed-emails-restriction-field">
        <label for="acfw_disallowed_emails">
            <?php esc_html_e( 'Disallowed emails', 'advanced-coupons-for-woocommerce' ); ?>
        </label>
        <input
            type="text"
            id="acfw_disallowed_emails"
            name="<?php echo esc_attr( $field_name_disallowed_emails ); ?>[]"
            class="short acfw-disallowed-emails"
            style="width: 50%;"
            placeholder="<?php esc_attr_e( 'No restrictions', 'advanced-coupons-for-woocommerce' ); ?>"
            value="<?php echo esc_attr( implode( ',', $disallowed_emails ) ); ?>"
        />
        <?php
        echo wp_kses_post(
            wc_help_tip(
                __( 'List of disallowed billing email addresses. Separate multiple email addresses with commas. Wildcards like *@gmail.com are supported.', 'advanced-coupons-for-woocommerce' )
            )
        );
        ?>
    </p>
</div>
