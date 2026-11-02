<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Number of columns of the rules table. Keep in sync with RULES_TABLE_COLSPAN in
 * packages/acfwp-edit-advanced-coupon/discount_rules/templates/placeholder_row.ts, which renders
 * the same placeholder row from JavaScript.
 */
$colspan = 5;
?>

<?php
/**
 * NOTE: this wrapper deliberately does not carry the "options_group" class. The coupon editor
 * script removes every ".options_group" that holds no form field, and the rule rows of this panel
 * are only rendered by the JS afterwards.
 */
?>
<div id="<?php echo esc_attr( $panel_id ); ?>" class="acfw-discount-rules-panel"
    data-rules="<?php echo esc_attr( wp_json_encode( $rules ) ); ?>"
    data-nonce="<?php echo esc_attr( wp_create_nonce( 'acfw_save_discount_rules' ) ); ?>"
    data-clear-nonce="<?php echo esc_attr( wp_create_nonce( 'acfw_clear_discount_rules' ) ); ?>">

    <div class="discount-rules-info">
        <h3><?php esc_html_e( 'Discount rules', 'advanced-coupons-for-woocommerce' ); ?></h3>
        <p><?php esc_html_e( 'Rules are checked top to bottom — the first matching rule is applied. A product is only ever discounted by one rule.', 'advanced-coupons-for-woocommerce' ); ?></p>
    </div>

    <div class="discount-rules-table-wrap">
        <table class="discount-rules-table acfw-styled-table">
            <thead>
                <tr>
                    <th class="col-priority"></th>
                    <th class="col-target-type">
                        <?php esc_html_e( 'Target type', 'advanced-coupons-for-woocommerce' ); ?>
                    </th>
                    <th class="col-target">
                        <?php esc_html_e( 'Products / categories', 'advanced-coupons-for-woocommerce' ); ?>
                    </th>
                    <th class="col-discount">
                        <?php esc_html_e( 'Discount', 'advanced-coupons-for-woocommerce' ); ?>
                    </th>
                    <th class="col-actions"></th>
                </tr>
            </thead>
            <tbody>
                <tr class="no-result">
                    <td colspan="<?php echo esc_attr( $colspan ); ?>">
                        <?php esc_html_e( 'No rules added', 'advanced-coupons-for-woocommerce' ); ?>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="<?php echo esc_attr( $colspan ); ?>">
                        <button type="button" class="button-link add-discount-rule">
                            <i class="dashicons dashicons-plus"></i>
                            <?php esc_html_e( 'Add rule', 'advanced-coupons-for-woocommerce' ); ?>
                        </button>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="discount-rules-actions-block">
        <button id="save-discount-rules" class="button-primary" type="button">
            <?php esc_html_e( 'Save Rules', 'advanced-coupons-for-woocommerce' ); ?>
        </button>
        <button id="clear-discount-rules" class="button" type="button"
            data-prompt="<?php esc_attr_e( 'Are you sure you want to remove all discount rules from this coupon?', 'advanced-coupons-for-woocommerce' ); ?>">
            <?php esc_html_e( 'Clear', 'advanced-coupons-for-woocommerce' ); ?>
        </button>
        <span class="description">
            <?php esc_html_e( "Saved instantly — publishes the coupon if it's still a draft.", 'advanced-coupons-for-woocommerce' ); ?>
        </span>
    </div>

    <div class="acfw-overlay" style="background-image:url(<?php echo esc_attr( $spinner_img ); ?>)"></div>
</div>
