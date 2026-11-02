<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>

<div class="notice notice-error acfw-drm-notice acfw-license-disabled-manually-notice" data-id="license-disabled-manually">
    <p class="heading">
        <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL . 'acfw-logo.png' ); ?>" alt="ACFW logo" />
    </p>

    <p><strong>
        <em><?php esc_html_e( 'Urgent!', 'advanced-coupons-for-woocommerce' ); ?></em> 
        <?php esc_html_e( 'This Advanced Coupons license key has been disabled. This could be due to a number of reasons:', 'advanced-coupons-for-woocommerce' ); ?>
    </strong></p>
    <ul>
        <li><?php esc_html_e( 'A refund or chargeback was initiated against this license key', 'advanced-coupons-for-woocommerce' ); ?></li>
        <li><?php esc_html_e( 'The license key may have violated our Terms of Service', 'advanced-coupons-for-woocommerce' ); ?></li>
        <li><?php esc_html_e( 'There may have been a malfunction with the license key and this is a false positive', 'advanced-coupons-for-woocommerce' ); ?></li>
    </ul>
    <p><?php esc_html_e( 'Don’t worry, your coupons & orders are completely safe and your premium coupon features are still working for now, but a valid license is required to continue using Advanced Coupons.', 'advanced-coupons-for-woocommerce' ); ?></p>
    <p><?php esc_html_e( 'If you feel this is a mistake, please reach out to our support team immediately and we’ll be happy to help.', 'advanced-coupons-for-woocommerce' ); ?></p>

    <div class="acfw-notice-actions">
        <a href="<?php echo esc_url( $contact_support_url ); ?>" class="button button-primary" rel="noopener" target="_blank"><?php esc_html_e( 'Contact Support', 'advanced-coupons-for-woocommerce' ); ?></a>
        <a href="<?php echo esc_url( $learn_more_url ); ?>" rel="noopener" target="_blank"><?php echo esc_html_e( 'Learn more', 'advanced-coupons-for-woocommerce' ); ?></a>
    </div>
        
</div>

<?php
do_action( 'acfw_after_license_disabled_manually_notice' );
