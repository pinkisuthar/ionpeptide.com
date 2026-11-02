<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>

<style type="text/css">
.acfw-no-license-notice .activate-license-form {
    display: flex;
    flex-wrap: wrap;
    flex-flow: row;
    margin: 1em 0;
}

.acfw-no-license-notice .activate-license-form .acfw-form-field {
    display: flex;
    flex-flow: column;
    margin-right: 1em;
    width: 200px;
}

.acfw-no-license-notice .activate-license-form .acfw-form-field label {
    font-weight: bold;
    font-size: 0.9em;
}
.acfw-no-license-notice .activate-license-form .acfw-form-field input {
    padding: 0.3em 0.5em;
    height: auto;
    border-radius: 0;
}
.acfw-no-license-notice .activate-license-form .acfw-form-field button {
    padding: 0.3em 0.5em;
    height: auto;
    border-radius: 0;
    background: #1594a8;
    border-color: #097788;
    transition: all 0.3s ease;
}
.acfw-no-license-notice .activate-license-form .acfw-form-field button:hover {
    background: #097788;
    border-color: #097788;
}
.acfw-extra-form-actions {
    margin-bottom: 1em;
}
.acfw-extra-form-actions .acfw-login-link {
    display: inline-block;
    font-style: italic;
    font-size: 0.9em;
}
</style>

<div class="notice notice-error <?php echo $is_dismissible ? 'is-dismissible' : ''; ?> acfw-drm-notice acfw-no-license-notice" data-id="no_license">
    <p class="heading">
        <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL . 'acfw-logo.png' ); ?>" alt="ACFW logo" />
    </p>

    <?php if ( 7 > $days_passed ) : ?>
        <p><strong><?php esc_html_e( 'Oops! Did you forget to enter your license for Advanced Coupons Premium?', 'advanced-coupons-for-woocommerce' ); ?></strong></p>
        <p><?php esc_html_e( 'Enter your license to fully activate Advanced Coupons and gain access to automatic updates, technical support and premium features.', 'advanced-coupons-for-woocommerce' ); ?></p>
    <?php else : ?>
        <p><strong>
            <em><?php esc_html_e( 'Action required!', 'advanced-coupons-for-woocommerce' ); ?></em> 
            <?php esc_html_e( 'Enter your license for Advanced Coupons Premium to continue.', 'advanced-coupons-for-woocommerce' ); ?>
        </strong></p>
        <p>
        <?php
        echo wp_kses_post(
            sprintf(
                /* Translators: %1$s: opening link tag. $2$s: closing link tag. */
                __( 'Don’t worry, your coupons are completely safe and your premium advanced coupons features are still working. But you will need to enter a license key to continue using Advanced Coupons premium. If you don’t have a license, %1$splease purchase one to proceed.%2$s', 'advanced-coupons-for-woocommerce' ),
                '<a href="' . esc_url( $purchase_url ) . '" rel="noopener" target="_blank">',
                '</a>'
            )
        );
            ?>
            </p>
    <?php endif; ?>

    <form method="get" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
        <div class="activate-license-form">
            <div class="acfw-form-field license-key-field">
                <label><?php esc_html_e( 'License Key:', 'advanced-coupons-for-woocommerce' ); ?></label>
                <input type="password" name="license-key" placeholder="<?php esc_attr_e( 'Enter your license key', 'advanced-coupons-for-woocommerce' ); ?>" required />
            </div>
            <div class="acfw-form-field activation-email-field">
                <label><?php esc_html_e( 'License Email:', 'advanced-coupons-for-woocommerce' ); ?></label>
                <input type="email"  name="activation-email" placeholder="<?php esc_attr_e( 'Activate License', 'advanced-coupons-for-woocommerce' ); ?>" required />
            </div>
            <div class="acfw-form-field license-form-item">
                <label>&nbsp;</label>
                <button class="button-primary" type="submit"><?php esc_html_e( 'Activate Key', 'advanced-coupons-for-woocommerce' ); ?></button>
            </div>
        </div>
        <div class="acfw-extra-form-actions">
            <span class="acfw-login-link">
            <?php
            echo wp_kses_post(
                sprintf(
                    '%1$s <a href="%2$s" target="_blank">%3$s</a>',
                    __( 'Can’t find your license key?', 'advanced-coupons-for-woocommerce' ),
                    $login_account_url,
                    __( 'Login to your account', 'advanced-coupons-for-woocommerce' )
                )
            );
            ?>
            </span>
        </div>
        <input type="hidden" name="action" value="acfw_activate_license" />
        <input type="hidden" name="is_notice" value="1" />
        <?php wp_nonce_field( 'acfw_activate_license', 'ajax-nonce' ); ?>
    </form>
</div>

<?php

do_action( 'acfw_after_no_license_notice' );
