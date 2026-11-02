<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>

<div id="acfw_apply_notification" class="panel woocommerce_options_panel acfw_premium_panel">
    <div class="acfw-help-link" data-module="one-click-apply"></div>
    <div class="apply-notifications-info">
        <h3><?php esc_html_e( 'One-Click Apply Notifications', 'advanced-coupons-for-woocommerce-free' ); ?></h3>

        <p>
        <?php
        echo wp_kses_post(
            sprintf(
                /* translators: %s: URL to the premium version of the plugin */
                __( 'In the <a href="%s" target="_blank">Premium add-on of Advanced Coupons</a> you make coupons that are applied by clicking a notice message on the cart.', 'advanced-coupons-for-woocommerce-free' ),
                apply_filters( 'acfwp_upsell_link', \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'oneclicknotifications' ) )
            )
        );
        ?>
        </p>

        <p><?php esc_html_e( 'You can also combine this feature with cart conditions to only show the message when customers qualify.', 'advanced-coupons-for-woocommerce-free' ); ?></p>

        <p><a class="button button-primary button-large" href="<?php echo esc_attr( apply_filters( 'acfwp_upsell_link', \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'oneclicknotifications' ) ) ); ?>" target="_blank">
            <?php esc_html_e( 'See all features & pricing →', 'advanced-coupons-for-woocommerce-free' ); ?>
        </a></p>
    </div>
</div>
