<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div id="acfw_payment_methods_restriction" class="panel woocommerce_options_panel acfw_premium_panel">
    <div class="acfw-help-link" data-module="payment-methods-restriction"></div>
    <div class="add-products-info">
        <h3><?php esc_html_e( 'Payment Methods Restriction', 'advanced-coupons-for-woocommerce-free' ); ?></h3>

        <p>
        <?php
        echo wp_kses_post(
            sprintf(
                /* translators: %s: URL to the premium version of the plugin */
                __( 'In the <a href="%s" target="_blank">Premium add-on of Advanced Coupons</a> you can make coupons that automatically filter the available payment gateways visible on the checkout.', 'advanced-coupons-for-woocommerce-free' ),
                apply_filters( 'acfwp_upsell_link', \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'paymentmethodsrestriction' ) )
            )
        );
        ?>
        </p>

        <p><?php esc_html_e( 'If the coupon is applied, the list of gateways is filtered, effectively restricting which payment options are allowed to be used when alongside this coupon.', 'advanced-coupons-for-woocommerce-free' ); ?></p>

        <p><a class="button button-primary button-large" href="<?php echo esc_attr( apply_filters( 'acfwp_upsell_link', \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'paymentmethodsrestriction' ) ) ); ?>" target="_blank">
            <?php esc_html_e( 'See all features & pricing →', 'advanced-coupons-for-woocommerce-free' ); ?>
        </a></p>

    </div>
</div>
