<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>

<p class="description">
<?php esc_html_e( 'Virtual coupons are other codes that are also valid for this coupon. It’s great when you need lots of unique codes for the same deal.', 'advanced-coupons-for-woocommerce' ); ?>
</p>

<div class="feature-control" data-labels="<?php echo esc_attr( wp_json_encode( $app_labels ) ); ?>">
    <label>
        <input id="<?php echo esc_attr( $checkbox_meta ); ?>" type="checkbox" name="<?php echo esc_attr( $checkbox_meta ); ?>" value="yes" <?php checked( $is_enabled, true ); ?>>
        <?php esc_html_e( 'Enable virtual coupons', 'advanced-coupons-for-woocommerce' ); ?>
    </label>
    <?php if ( $is_show_app ) : ?>
    <p class="save-notice" style="display:none;">
        <?php
        echo wp_kses_post(
            sprintf(
                /* Translators: %1$s: Formatting tag start, %2$s: Formatting tag end. */
                __( '%1$sNotice:%2$s You\'ll need to update the coupon to properly enable/disable this feature.', 'advanced-coupons-for-woocommerce' ),
                '<strong>',
                '</strong>'
            )
        );
        ?>
    </p>
    <?php endif; ?>
</div>

<?php if ( $is_show_app ) : ?>
<div id="virtual-coupons-app"></div>
<?php endif; ?>
