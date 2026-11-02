<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>

<style type="text/css">
    .plugin-dependency-notice p {
        max-width: 1000px;
    }
    .plugin-dependency-notice p:after {
        content: '';
        display: table;
        clear: both;
    }
    .plugin-dependency-notice .heading img {
        float: left;
        margin-right: 15px;
        max-width: 190px;
    }
    .plugin-dependency-notice .heading span {
        float: left;
        display: inline-block;
        margin-top: 8px;
        font-size: 16px;
        font-weight: bold;
        text-transform: uppercase;
        color: #035E6B;
    }
    .plugin-dependency-notice .action-wrap {
        margin-bottom: 15px;
    }
    .plugin-dependency-notice .action-wrap .action-button {
        display: inline-block;
        padding: 8px 23px;
        margin-right: 10px;
        background: #C6CD2E;
        font-weight: bold;
        font-size: 16px;
        text-decoration: none;
        color: #000000;
    }
    .plugin-dependency-notice .action-wrap .action-button.disabled {
        opacity: 0.7 !important;
        pointer-events: none;
    }
    .plugin-dependency-notice .action-wrap .action-button.gray {
        background: #cccccc;
    }
    .plugin-dependency-notice .action-wrap .action-button:hover {
        opacity: 0.8;
    }

    .plugin-dependency-notice .action-wrap span {
        color: #035E6B;
    }
</style>

<?php if ( $acfwf_dependency ) : ?>
<div class="notice notice-error plugin-dependency-notice acfwf">
    <p class="heading">
        <img src="<?php echo esc_url( $acfw_logo ); ?>">
        <span><?php esc_html_e( 'Important - please update Advanced Coupons free plugin', 'advanced-coupons-for-woocommerce' ); ?></span>
    </p>
    <p>
    <?php
    echo esc_html(
        sprintf(
            /* Translators: %1$s: ACFWF version, %2$s: ACFWP version. */
            __( 'Advanced Coupons Free Version needs to be on at least version %1$s to work properly with Advanced Coupons Premium %2$s', 'advanced-coupons-for-woocommerce' ),
            $acfwf_version,
            $acfwp_version
        )
    );
    ?>
    </p>
    <p><?php esc_html_e( 'Please update by clicking below.', 'advanced-coupons-for-woocommerce' ); ?></p>
    <p class="action-wrap">
        <a class="action-button" href="<?php echo esc_url( htmlspecialchars_decode( $this->Helper_Functions->get_plugin_dependency_install_url( $acfwf_dependency['plugin-base-name'], true ) ) ); ?>">
            <?php esc_html_e( 'Update Plugin', 'advanced-coupons-for-woocommerce' ); ?>
        </a>
    </p>
</div>
<?php endif; ?>

<?php if ( $admin_notice_msg ) : ?>
    <div class="notice notice-error plugin-dependency-notice">
        <p class="heading">
            <img src="<?php echo esc_url( $acfw_logo ); ?>">
            <span><?php esc_html_e( 'Action required', 'advanced-coupons-for-woocommerce' ); ?></span>
        </p>
        <p>
        <?php
        echo wp_kses_post(
            sprintf(
                /* Translators: %1$s: Formatting tag start. %2$s: Formatting tag end. */
                __( '%1$sAdvanced Coupons for WooCommerce Premium%2$s plugin invalid dependency version.', 'advanced-coupons-for-woocommerce' ),
                '<strong>',
                '</strong>'
            )
        );
            ?>
            </p>
        <p><?php echo wp_kses_post( $admin_notice_msg ); ?></p>
    </div>
<?php endif; ?>
