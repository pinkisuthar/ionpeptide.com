<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
exit;}  ?>

<div id="license-placeholder" class="acfwp-license-settings-block">

    <div class="overview">
        <h2><?php esc_html_e( 'Advanced Coupons Premium', 'advanced-coupons-for-woocommerce' ); ?></h2>
        <p><?php esc_html_e( 'Advanced Coupons comes in two versions - the free version (with feature limitations) and the Premium add-on.', 'advanced-coupons-for-woocommerce' ); ?></p>
    </div>

    <div class="license-info">

        <div class="heading">
            <div class="left">
                <span><?php esc_html_e( 'Your current license for Advanced Coupons:', 'advanced-coupons-for-woocommerce' ); ?></span>
            </div>
            <div class="right">
                <?php if ( 'yes' === $license_activated ) : ?>
                    <span class="action-button active-indicator no-hover license-active dashicons-before dashicons-yes-alt"><?php esc_html_e( 'License is Active', 'advanced-coupons-for-woocommerce' ); ?></span>
                <?php else : ?>
                    <span class="action-button active-indicator no-hover"><?php esc_html_e( 'Not Activated Yet', 'advanced-coupons-for-woocommerce' ); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="content">
            <h2><?php esc_html_e( 'Premium Version', 'advanced-coupons-for-woocommerce' ); ?></h2>
            <p><?php esc_html_e( 'You are currently using Advanced Coupons for WooCommerce Premium version. The premium version gives you a massive range of extra extra features for your WooCommerce coupons so you can promote your store better. As the Premium version functions like an add-on, you must have Advanced Coupons for WooCommerce Free installed and activated along with WooCommerce (which is required for both).', 'advanced-coupons-for-woocommerce' ); ?></p>

            <table class="license-specs">
                <tr>
                    <th><?php esc_html_e( 'Plan', 'advanced-coupons-for-woocommerce' ); ?></th>
                    <th><?php esc_html_e( 'Version', 'advanced-coupons-for-woocommerce' ); ?></th>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Premium Version', 'advanced-coupons-for-woocommerce' ); ?></td>
                    <td><?php echo esc_html( $constants->VERSION ); ?></td>
                </tr>
            </table>
        </div>
        <div class="form">
            <div class="flex">
                <div class="form-field">
                    <label for="<?php echo esc_attr( $constants->OPTION_LICENSE_KEY ); ?>"><?php esc_html_e( 'License Key:', 'advanced-coupons-for-woocommerce' ); ?></label>
                    <input class="regular-text ltr" type="password" id="<?php echo esc_attr( $constants->OPTION_LICENSE_KEY ); ?>" name="<?php echo esc_attr( $constants->OPTION_LICENSE_KEY ); ?>" value="<?php echo esc_attr( $license_key ); ?>" />
                </div>
                <div class="form-field">
                    <label for="<?php echo esc_attr( $constants->OPTION_ACTIVATION_EMAIL ); ?>"><?php esc_html_e( 'Activation Email:', 'advanced-coupons-for-woocommerce' ); ?></label>
                    <input class="regular-text ltr" type="email" id="<?php echo esc_attr( $constants->OPTION_ACTIVATION_EMAIL ); ?>" name="<?php echo esc_attr( $constants->OPTION_ACTIVATION_EMAIL ); ?>" value="<?php echo esc_attr( $activation_email ); ?>" />
                </div>
                <div class="form-field action">
                    <button class="action-button <?php echo 'yes' === $license_activated ? 'grayed' : ''; ?>" type="submit" name="save" value="<?php esc_html_e( 'Activate Key', 'advanced-coupons-for-woocommerce' ); ?>"><?php esc_html_e( 'Activate Key', 'advanced-coupons-for-woocommerce' ); ?></button>
                </div>
            </div>
            <div class="help-row">
                <?php
                echo wp_kses_post(
                    sprintf(
                        '%1$s <a href="%2$s" rel="noopener" target="_blank">%3$s</a>',
                        __( 'Can’t find your key?', 'advanced-coupons-for-woocommerce' ),
                        'https://advancedcouponsplugin.com/my-account/?utm_source=acfwp&utm_medium=license&utm_campaign=findkey',
                        __( 'Login to your account.', 'advanced-coupons-for-woocommerce' )
                    )
                );
                ?>
            </div>
        </div>

        <div class="overlay"><img src="<?php echo esc_url( $constants->IMAGES_ROOT_URL . 'spinner-2x.gif' ); ?>" alt="spinner" /></div>
    </div>

</div>
