<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>

<div id="acfw_shipping_overrides" class="panel woocommerce_options_panel acfw_premium_panel">
    <div class="acfw-help-link" data-module="shipping-overrides"></div>
    <div class="shipping-overrides-info">
        <h3><?php esc_html_e( 'Shipping Overrides', 'advanced-coupons-for-woocommerce-free' ); ?></h3>

        <p>
        <?php
        echo wp_kses_post(
            sprintf(
                /* translators: %s: URL to the premium version of the plugin */
                __( 'In the <a href="%s" target="_blank">Premium add-on of Advanced Coupons</a> you can give discounts on any shipping method in your store.', 'advanced-coupons-for-woocommerce-free' ),
                apply_filters( 'acfwp_upsell_link', \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'shippingoverrides' ) )
            )
        );
        ?>
        </p>

        <p><?php esc_html_e( 'Get more creative with your shipping discounts beyond just your usual "free shipping" offer. Give short term discounts on express shipping, specific carriers or even just certain areas. Shipping offers are extremely effective and in Premium you will be able to do more creative shipping offers.', 'advanced-coupons-for-woocommerce-free' ); ?></p>

        <p><a class="button button-primary button-large" href="<?php echo esc_attr( apply_filters( 'acfwp_upsell_link', \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'shippingoverrides' ) ) ); ?>" target="_blank">
            <?php esc_html_e( 'See all features & pricing →', 'advanced-coupons-for-woocommerce-free' ); ?>
        </a></p>
    </div>
</div>
