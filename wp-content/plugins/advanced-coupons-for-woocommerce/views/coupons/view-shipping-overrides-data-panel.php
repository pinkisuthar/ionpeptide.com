<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>

<div id="<?php echo esc_attr( $panel_id ); ?>" class="panel woocommerce_options_panel toggle-enable-fields" data-nonce="<?php echo esc_attr( wp_create_nonce( 'acfw_save_shipping_overrides' ) ); ?>">
    <div class="acfw-help-link" data-module="shipping-overrides"></div>

    <div class="shipping-overrides-info">
        <h3><?php esc_html_e( 'Shipping Overrides', 'advanced-coupons-for-woocommerce' ); ?></h3>
        <p><?php esc_html_e( 'Override the shipping costs for the given shipping methods below when they show up in the checkout. You can specify multiple shipping methods here and they will be discounted if the customer selects it.', 'advanced-coupons-for-woocommerce' ); ?></p>
    </div>

    <div class="options_group acfw-enable-toggle-group">
        <?php
        woocommerce_wp_checkbox(
            array(
                'id'          => '_acfw_enable_shipping_overrides',
                'class'       => 'toggle-trigger-field',
                'label'       => esc_html__( 'Enable Shipping Overrides', 'advanced-coupons-for-woocommerce' ),
                'description' => esc_html__( 'When checked, shipping costs will be overridden based on the settings below when this coupon is applied.', 'advanced-coupons-for-woocommerce' ),
                'value'       => $enable_shipping_overrides,
            )
        );
        ?>
    </div>

    <div class="shipping-overrides-table-wrap">

        <table class="shipping-overrides-table acfw-styled-table"
            data-zonemethods="<?php echo esc_attr( wp_json_encode( $zone_methods ) ); ?>"
            data-overrides="<?php echo esc_attr( wp_json_encode( $overrides ) ); ?>"
            data-exclude="<?php echo esc_attr( wp_json_encode( $exclude ) ); ?>">
            <thead>
                <tr>
                    <th class="shipping-zone">
                        <?php esc_html_e( 'Shipping Zone', 'advanced-coupons-for-woocommerce' ); ?>
                    </th>
                    <th class="shipping-method">
                        <?php esc_html_e( 'Shipping Method', 'advanced-coupons-for-woocommerce' ); ?>
                    </th>
                    <th class="discount">
                        <?php esc_html_e( 'Discount', 'advanced-coupons-for-woocommerce' ); ?>
                    </th>
                    <th class="actions"></th>
                </tr>
            </thead>
            <tbody>
                <tr class="no-result">
                    <td colspan="4">
                        <?php esc_html_e( 'No shipping overrides added', 'advanced-coupons-for-woocommerce' ); ?>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">
                        <a class="add-table-row" href="javascript:void(0);">
                            <i class="dashicons dashicons-plus"></i>
                            <?php esc_html_e( 'Add Shipping Override', 'advanced-coupons-for-woocommerce' ); ?>
                        </a>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="shipping-overrides-actions-block">
        <button id="save-shipping-overrides" class="button-primary" type="button" disabled>
            <?php esc_html_e( 'Save Shipping Overrides', 'advanced-coupons-for-woocommerce' ); ?>
        </button>
        <button id="clear-shipping-overrides" class="button" type="button"
            data-prompt="<?php esc_attr_e( 'Are you sure you want to do this?', 'advanced-coupons-for-woocommerce' ); ?>"
            data-nonce="<?php echo esc_attr( wp_create_nonce( 'acfw_clear_shipping_overrides' ) ); ?>"
            <?php echo empty( $overrides ) ? 'disabled' : ''; ?>>
            <?php esc_html_e( 'Clear Shipping Overrides', 'advanced-coupons-for-woocommerce' ); ?>
        </button>
    </div>

    <div class="acfw-overlay" style="background-image:url(<?php echo esc_attr( $spinner_img ); ?>)"></div>
</div>
