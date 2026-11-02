<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>

<div id="<?php echo esc_attr( $panel_id ); ?>" class="panel woocommerce_options_panel toggle-enable-fields"
    data-products="<?php echo esc_attr( wp_json_encode( $add_products ) ); ?>"
    data-nonce="<?php echo esc_attr( wp_create_nonce( 'acfw_save_add_products_data' ) ); ?>"
    <?php
    foreach ( $panel_data_atts as $data_key => $data_value ) :
    printf( 'data-%s="%s"', esc_attr( $data_key ), esc_attr( is_array( $data_value ) ? wp_json_encode( $data_value ) : $data_value ) );
endforeach;
?>
>
    <div class="acfw-help-link" data-module="add-products"></div>

    <div class="acfw-tab-info">
        <h3><?php esc_html_e( 'Add Products', 'advanced-coupons-for-woocommerce' ); ?></h3>
        <p><?php esc_html_e( "This feature lets you add products to a customer's cart when a coupon is successfully applied. The products listed in the table below will be automatically added to their cart in the specified quantities and price overrides.", 'advanced-coupons-for-woocommerce' ); ?></p>
        <p><?php esc_html_e( "The Add Products feature can also be combined with other features like Cart Conditions and Auto Apply to make products appear in the customer's cart like magic once certain conditions are met.", 'advanced-coupons-for-woocommerce' ); ?></p>
    </div>

    <div class="options_group acfw-enable-toggle-group">
        <?php
        woocommerce_wp_checkbox(
            array(
                'id'          => '_acfw_enable_add_products',
                'class'       => 'toggle-trigger-field',
                'label'       => esc_html__( 'Enable Add Products', 'advanced-coupons-for-woocommerce' ),
                'description' => esc_html__( 'When checked, products listed below will be added to the cart when this coupon is applied.', 'advanced-coupons-for-woocommerce' ),
                'value'       => $enable_add_products,
            )
        );
        ?>
    </div>

    <div class="add-products-block">
        <table class="add-products-data-table acfw-styled-table" data-exclude="<?php echo esc_attr( wp_json_encode( $exclude ) ); ?>">
            <thead>
                <tr>
                    <th class="product"><?php esc_html_e( 'Product', 'advanced-coupons-for-woocommerce' ); ?></th>
                    <th class="quantity"><?php esc_html_e( 'Quantity', 'advanced-coupons-for-woocommerce' ); ?></th>
                    <th class="price"><?php esc_html_e( 'Price/Discount', 'advanced-coupons-for-woocommerce' ); ?></th>
                    <th class="actions"></th>
                </tr>
            </thead>
            <tbody></tbody>
            <tfoot>
                <tr>
                    <td colspan="4">
                        <a class="add-product add-table-row" href="javascript:void(0);">
                            <i class="dashicons dashicons-plus"></i>
                            <?php esc_html_e( 'Add Product', 'advanced-coupons-for-woocommerce' ); ?>
                        </a>
                    </td>
                </tr>
            </tfoot>
        </table>

        <div class="acfw-nodiscount-notice" style="display:none;">
            <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
            <p>
                <?php
                printf(
                    /* translators: 1: opening bold tag, 2: closing bold tag, 3: opening bold tag, 4: closing bold tag */
                    esc_html__( 'Products added with %1$sNo discount%2$s are not attached to the coupon, so they won\'t be automatically removed, quantity-locked, or recalculated when the coupon\'s conditions change. To have an added product behave as a coupon-attached free gift, use %3$sPercentage discount%4$s set to 0%% instead.', 'advanced-coupons-for-woocommerce' ),
                    '<strong>',
                    '</strong>',
                    '<strong>',
                    '</strong>'
                );
                ?>
            </p>
        </div>
    </div>

    <div class="add-products-actions-block">
        <button id="save-add-products" class="button-primary" type="button" disabled><?php esc_html_e( 'Save "Add Products" Data', 'advanced-coupons-for-woocommerce' ); ?></button>
        <div class="add-before-cart-condition-check-field">
            <label>
                <input type="checkbox" name="add_before_cart_condition" value="yes" <?php checked( $coupon->get_advanced_prop( 'add_before_conditions' ), true ); ?> />
                <span><?php esc_html_e( 'Add undiscounted products before coupon restrictions & cart conditions.', 'advanced-coupons-for-woocommerce' ); ?></span>
            </label>
        </div>
        <button id="clear-add-products" class="button" type="button"
            data-prompt="<?php esc_attr_e( 'Are you sure you want to do this?', 'advanced-coupons-for-woocommerce' ); ?>"
            data-nonce="<?php echo esc_attr( wp_create_nonce( 'acfw_clear_add_products_data' ) ); ?>"
            <?php echo empty( $add_products ) ? 'disabled' : ''; ?>>
            <?php esc_html_e( 'Clear "Add Products" Data', 'advanced-coupons-for-woocommerce' ); ?>
        </button>
    </div>

    <div class="acfw-overlay" style="background-image:url(<?php echo esc_attr( $spinner_img ); ?>)"></div>
</div>
