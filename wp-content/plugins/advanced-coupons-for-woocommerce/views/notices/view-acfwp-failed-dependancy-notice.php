<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
exit;} ?>

<style type="text/css">
    .acfw-old-version-notice p {
        max-width: 1000px;
    }
    .acfw-old-version-notice p:after {
        content: '';
        display: table;
        clear: both;
    }
    .acfw-old-version-notice .heading img {
        float: left;
        margin-right: 15px;
        max-width: 190px;
    }
    .acfw-old-version-notice .heading span {
        float: left;
        display: inline-block;
        margin-top: 8px;
        font-size: 16px;
        font-weight: bold;
        text-transform: uppercase;
        color: #CB423B;
    }
    .acfw-old-version-notice .action-wrap {
        margin-bottom: 15px;
    }
    .acfw-old-version-notice .action-wrap .action-button {
        display: inline-block;
        padding: 8px 23px;
        margin-right: 10px;
        background: #C6CD2E;
        font-weight: bold;
        font-size: 16px;
        text-decoration: none;
        color: #000000;
    }
    .acfw-old-version-notice .action-wrap .action-button.disabled {
        opacity: 0.7 !important;
        pointer-events: none;
    }
    .acfw-old-version-notice .action-wrap .action-button.gray {
        background: #cccccc;
    }
    .acfw-old-version-notice .action-wrap .action-button:hover {
        opacity: 0.8;
    }

    .acfw-old-version-notice .action-wrap span {
        color: #035E6B;
    }
</style>
<div class="notice notice-error acfw-old-version-notice">
    <p class="heading">
        <img src="<?php echo esc_url( $acfw_logo ); ?>">
        <span><?php esc_html_e( 'Action required', 'advanced-coupons-for-woocommerce' ); ?></span>
    </p>
    <p>
    <?php
    echo wp_kses_post(
        sprintf(
            /* Translators: %1$s: Formatting tag start. %2$s: Formatting tag end. */
            __( '%1$sAdvanced Coupons for WooCommerce Premium%2$s plugin missing dependency.', 'advanced-coupons-for-woocommerce' ),
            '<strong>',
            '</strong>'
        )
    );
        ?>
        </p>
    <?php echo wp_kses_post( $admin_notice_msg ); ?>
</div>
