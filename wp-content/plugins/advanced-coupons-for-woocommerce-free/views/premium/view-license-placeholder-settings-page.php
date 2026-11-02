<?php if ( ! defined( 'ABSPATH' ) ) {
exit;} // Exit if accessed directly ?>

<div id="license-placeholder" class="acfwf-license-placeholder-settings-block">

    <div class="overview">
        <h1><?php esc_html_e( 'Advanced Coupons License Activation', 'advanced-coupons-for-woocommerce-free' ); ?></h1>
        <p><?php esc_html_e( 'Advanced Coupons comes in two versions - the free version (with feature limitations) and the Premium add-on.', 'advanced-coupons-for-woocommerce-free' ); ?></p>
        <a class="action-button feature-comparison" href="<?php echo esc_attr( apply_filters( 'acfwp_upsell_link', \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'licensefeaturecomparison' ) ) ); ?>" target="_blank">
            <?php esc_html_e( 'See feature comparison ', 'advanced-coupons-for-woocommerce-free' ); ?>
        </a>
    </div>

    <div class="license-info">

        <div class="heading">
            <div class="left">
                <span><?php esc_html_e( 'Your current license for Advanced Coupons:', 'advanced-coupons-for-woocommerce-free' ); ?></span>
            </div>
            <div class="right">
                <a class="action-button upgrade-premium" href="<?php apply_filters( 'acfwp_upsell_link', \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'licenseupgradetopremium' ) ); ?>" target="_blank">
                    <?php esc_html_e( 'Upgrade To Premium', 'advanced-coupons-for-woocommerce-free' ); ?>
                </a>
            </div>
        </div>

        <div class="content">

            <h2><?php esc_html_e( 'Free Version', 'advanced-coupons-for-woocommerce-free' ); ?></h2>
            <p><?php esc_html_e( 'You are currently using Advanced Coupons for WooCommerce Free on a GPL license. The free version includes a heap of great extra features for your WooCommerce coupons. The only requirement for the free version is that you have WooCommerce installed.', 'advanced-coupons-for-woocommerce-free' ); ?></p>

            <table class="license-specs">
                <tr>
                    <th><?php esc_html_e( 'Plan', 'advanced-coupons-for-woocommerce-free' ); ?></th>
                    <th><?php esc_html_e( 'Version', 'advanced-coupons-for-woocommerce-free' ); ?></th>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Free Version', 'advanced-coupons-for-woocommerce-free' ); ?></td>
                    <td><?php echo esc_attr( $plugin_version ); ?></td>
                </tr>
            </table>
        </div>
    </div>

</div>
