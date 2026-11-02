<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div id="acfw_add_products" class="panel woocommerce_options_panel acfw_premium_panel">
    <div class="acfw-help-link" data-module="add-products"></div>
    <div class="add-products-info">
        <h3><?php esc_html_e( 'Add Products', 'advanced-coupons-for-woocommerce-free' ); ?></h3>

        <p>
        <?php
        echo wp_kses_post(
            sprintf(
                /* translators: %s: URL to the premium version of the plugin */
                __( 'In the <a href="%s" target="_blank">Premium add-on of Advanced Coupons</a> you make coupons that automatically add products to the cart.', 'advanced-coupons-for-woocommerce-free' ),
                apply_filters( 'acfwp_upsell_link', \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'addproducts' ) )
            )
        );
        ?>
        </p>

        <p><?php esc_html_e( "This can also be combined with other features like Cart Conditions and Auto Apply to make products appear in the customer's cart like magic once conditions are met.", 'advanced-coupons-for-woocommerce-free' ); ?></p>

        <p><a class="button button-primary button-large" href="<?php echo esc_attr( apply_filters( 'acfwp_upsell_link', \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'addproducts' ) ) ); ?>" target="_blank">
            <?php esc_html_e( 'See all features & pricing →', 'advanced-coupons-for-woocommerce-free' ); ?>
        </a></p>

    </div>
</div>
