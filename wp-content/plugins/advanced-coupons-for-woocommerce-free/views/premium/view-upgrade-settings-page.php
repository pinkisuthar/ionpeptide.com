<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>

<div class="acfwf-upgrade-settings-block">

    <p><img class="logo" src="<?php echo esc_attr( $img_logo ); ?>"></p>
    <h2><?php echo wp_kses_post( __( '<strong>Free</strong> vs <strong>Premium</strong>', 'advanced-coupons-for-woocommerce-free' ) ); ?></h2>
    <p><?php esc_html_e( 'If you are serious about growing your sales within your WooCommerce store then the Premium add-on to the free Advanced Coupons for WooCommerce plugin that you are currently using can help you.', 'advanced-coupons-for-woocommerce-free' ); ?></p>

    <div class="responsive-table">
    <table>
        <thead>
            <tr>
                <th class="feature">
                    <?php esc_html_e( 'Features', 'advanced-coupons-for-woocommerce-free' ); ?>
                </th>
                <th class="free">
                    <?php esc_html_e( 'Free Plugin', 'advanced-coupons-for-woocommerce-free' ); ?>
                </th>
                <th class="premium">
                    <?php esc_html_e( 'Premium Add-on', 'advanced-coupons-for-woocommerce-free' ); ?>
                </th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="feature">
                    <?php esc_html_e( 'Restrict Applying Coupons Using Cart Conditions', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="free dashicons-before dashicons-no">
                    <?php esc_html_e( 'Basic set of cart conditions only', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="premium dashicons-before dashicons-yes-alt">
                    <?php esc_html_e( 'Advanced cart conditions to let you control exactly when coupons should be allowed to apply.', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
            </tr>
            <tr>
                <td class="feature">
                    <?php esc_html_e( 'Run BOGO deals with coupons', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="free dashicons-before dashicons-no">
                    <?php esc_html_e( 'Simple BOGO deals only', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="premium dashicons-before dashicons-yes-alt">
                    <?php esc_html_e( 'Run advanced BOGO deals with multiple products or across product categories.', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
            </tr>
            <tr>
                <td class="feature">
                    <?php esc_html_e( 'Add products upon applying a coupon', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="free dashicons-before dashicons-no">
                    <?php esc_html_e( 'Not available', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="premium dashicons-before dashicons-yes-alt">
                    <?php esc_html_e( 'Automatically add a product to the cart on applying a coupon and optionally override the price of that product.', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
            </tr>
            <tr>
                <td class="feature">
                    <?php esc_html_e( 'Schedule coupon start and end date', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="free dashicons-before dashicons-no">
                    <?php esc_html_e( 'Only WordPress scheduled post', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="premium dashicons-before dashicons-yes-alt">
                    <?php esc_html_e( 'Show a nice message before and after specific start/end dates so you can recapture lost sales.', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
            </tr>
            <tr>
                <td class="feature">
                    <?php esc_html_e( 'One-click Apply Notifications', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="free dashicons-before dashicons-no">
                    <?php esc_html_e( 'Not available', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="premium dashicons-before dashicons-yes-alt">
                    <?php esc_html_e( 'Show a message at the cart with a one-click apply button when the customer is eligible for a coupon.', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
            </tr>
            <tr>
                <td class="feature">
                    <?php esc_html_e( 'Auto Apply Coupons', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="free dashicons-before dashicons-no">
                    <?php esc_html_e( 'Not available', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="premium dashicons-before dashicons-yes-alt">
                    <?php esc_html_e( 'Automatically apply a coupon to the cart when a customer becomes eligible.', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
            </tr>
            <tr>
                <td class="feature">
                    <?php esc_html_e( 'Shipping Override Coupons', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="free dashicons-before dashicons-no">
                    <?php esc_html_e( 'Not available', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="premium dashicons-before dashicons-yes-alt">
                    <?php esc_html_e( "Run more creative discounts on your store's shipping methods.", 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
            </tr>
            <tr>
                <td class="feature">
                    <?php esc_html_e( 'Timed Usage Resets', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="free dashicons-before dashicons-no">
                    <?php esc_html_e( 'Not available', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
                <td class="premium dashicons-before dashicons-yes-alt">
                    <?php esc_html_e( 'Give coupons with usage limits that reset after a time - great for influencer marketing or daily deals.', 'advanced-coupons-for-woocommerce-free' ); ?>
                </td>
            </tr>
        </tbody>
    </table>
    </div>

    <div class="cta-block">
        <h3><?php esc_html_e( "+ 100's of other premium features", 'advanced-coupons-for-woocommerce-free' ); ?></h3>

        <p>
            <a class="acfw-upgrade-button" href="<?php echo esc_attr( apply_filters( 'acfwp_upsell_link', \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'upgradepage' ) ) ); ?>" target="_blank">
                <?php esc_html_e( 'See the full feature list →', 'advanced-coupons-for-woocommerce-free' ); ?>
            </a>
        </p>
    </div>

</div>
