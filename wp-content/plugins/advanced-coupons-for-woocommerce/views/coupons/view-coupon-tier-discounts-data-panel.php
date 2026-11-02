<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Number of columns of the tiers table. Keep in sync with TIERS_TABLE_COLSPAN in
 * packages/acfwp-edit-advanced-coupon/tier_discounts/templates/placeholder_row.ts, which renders
 * the same placeholder row from JavaScript.
 */
$colspan = 3;
?>

<?php
/**
 * NOTE: this wrapper deliberately does not carry the "options_group" class. The coupon editor
 * script removes every ".options_group" that holds no form field, and the tier rows of this panel
 * are only rendered by the JS afterwards.
 */
?>
<div id="<?php echo esc_attr( $panel_id ); ?>" class="acfw-tier-discounts-panel"
    data-tiers="<?php echo esc_attr( wp_json_encode( $tiers ) ); ?>"
    data-discount-type="<?php echo esc_attr( $discount_type ); ?>"
    data-nonce="<?php echo esc_attr( wp_create_nonce( 'acfw_save_tier_discounts' ) ); ?>"
    data-clear-nonce="<?php echo esc_attr( wp_create_nonce( 'acfw_clear_tier_discounts' ) ); ?>">

    <div class="tier-discounts-info">
        <h3><?php esc_html_e( 'Spend tiers', 'advanced-coupons-for-woocommerce' ); ?></h3>
        <p><?php esc_html_e( 'The cart takes the discount of the highest spend tier it reaches. Tiers never stack.', 'advanced-coupons-for-woocommerce' ); ?></p>
    </div>

    <p class="tier-discount-type-field">
        <label for="acfw_tier_discount_type"><?php esc_html_e( 'Tier discount type', 'advanced-coupons-for-woocommerce' ); ?></label>
        <select id="acfw_tier_discount_type" class="tier-discount-type">
            <option value="fixed" <?php selected( $discount_type, 'fixed' ); ?>>
                <?php
                printf(
                    /* translators: %s: store currency symbol. */
                    esc_html__( 'Fixed (%s)', 'advanced-coupons-for-woocommerce' ),
                    esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) )
                );
                ?>
            </option>
            <option value="percent" <?php selected( $discount_type, 'percent' ); ?>>
                <?php esc_html_e( 'Percent (%)', 'advanced-coupons-for-woocommerce' ); ?>
            </option>
        </select>
        <span class="description">
            <?php esc_html_e( 'The discount type is the same for every tier of this coupon.', 'advanced-coupons-for-woocommerce' ); ?>
        </span>
    </p>

    <div class="tier-discounts-table-wrap">
        <table class="tier-discounts-table acfw-styled-table">
            <thead>
                <tr>
                    <th class="col-threshold">
                        <?php esc_html_e( 'Spend at least', 'advanced-coupons-for-woocommerce' ); ?>
                    </th>
                    <th class="col-value">
                        <?php esc_html_e( 'Discount', 'advanced-coupons-for-woocommerce' ); ?>
                    </th>
                    <th class="col-actions"></th>
                </tr>
            </thead>
            <tbody>
                <tr class="no-result">
                    <td colspan="<?php echo esc_attr( $colspan ); ?>">
                        <?php esc_html_e( 'No tiers added', 'advanced-coupons-for-woocommerce' ); ?>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="<?php echo esc_attr( $colspan ); ?>">
                        <button type="button" class="button-link add-tier-discount">
                            <i class="dashicons dashicons-plus"></i>
                            <?php esc_html_e( 'Add tier', 'advanced-coupons-for-woocommerce' ); ?>
                        </button>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="tier-discounts-error" role="alert"></div>

    <div class="tier-discounts-actions-block">
        <button id="save-tier-discounts" class="button-primary" type="button">
            <?php esc_html_e( 'Save Tiers', 'advanced-coupons-for-woocommerce' ); ?>
        </button>
        <button id="clear-tier-discounts" class="button" type="button"
            data-prompt="<?php esc_attr_e( 'Are you sure you want to remove all spend tiers from this coupon?', 'advanced-coupons-for-woocommerce' ); ?>">
            <?php esc_html_e( 'Clear', 'advanced-coupons-for-woocommerce' ); ?>
        </button>
        <span class="description">
            <?php esc_html_e( "Saved instantly — publishes the coupon if it's still a draft.", 'advanced-coupons-for-woocommerce' ); ?>
        </span>
    </div>

    <div class="acfw-overlay" style="background-image:url(<?php echo esc_url( $spinner_img ); ?>)"></div>
</div>
