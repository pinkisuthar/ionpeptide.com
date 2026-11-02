<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>
<div class="add_bulk_range" style="display:none;">
    <?php
    $awdr_bulk_index = "{i}";
    include 'Bulk.php';
    ?>
</div>
<!-- Bulk discount Start-->
<div class="wdr_bulk_discount" style="display:none;">
    <div class="wdr-simple-discount-main wdr-bulk-discount-main">
        <div class="wdr-simple-discount-inner">
            <div class="bulk_general_adjustment wdr-select-filed-hight">
                <label class="label_font_weight"><?php esc_html_e('Count Quantities by:', 'woo-discount-rules'); ?> <span style="" class="woocommerce-help-tip" title="<?php esc_html_e("Filter set above : 
This will count the quantities of products set in the “Filter” section.
Example: If you selected a few categories there, it will count the quantities of products in those categories added in cart. If you selected a few products in the filters section, then it will count the quantities together.

Example: Let’s say, you wanted to offer a Bulk Quantity discount for Category A and chosen Category A in the filters.

So when a customer adds 1 quantity each of X, Y and Z from Category A, then the count here is 3. 

Individual Product :

This counts the total quantity of each product / line item separately.
Example : If a customer wanted to buy 2 quantities of Product A,  3 quantities of Product B, then count will be maintained at the product level. 
2 - count of Product A
3 - Count of Product B

In case of variable products, the count will be based on each variant because WooCommerce considers a variant as a product itself.  

All variants in each product together :
Useful when applying discounts based on variable products and you want the quantity to be counted based on the parent product.
Example: 
Say, you have Product A - Small, Medium, Large.
If a customer buys  2 of Product A - Small,  4 of Product A - Medium,  6 of Product A - Large, then the count will be: 6+4+2 = 12
", 'woo-discount-rules'); ?>"></span></label>
                <select name="bulk_adjustments[operator]"
                        class="wdr-bulk-type bulk_discount_select awdr_mode_of_operator">
                    <option value="product_cumulative" title="<?php esc_html_e('This will count the quantities of products set in the “Filter” section.
Example: If you selected a few categories there, it will count the quantities of products in those categories added in cart. If you selected a few products in the filters section, then it will count the quantities together.

Example: Let’s say, you wanted to offer a Bulk Quantity discount for Category A and chosen Category A in the filters.

So when a customer adds 1 quantity each of X, Y and Z from Category A, then the count here is 3.', 'woo-discount-rules') ?>" <?php if ($awdr_bulk_adj_operator == 'product_cumulative') {
                        echo 'selected';
                    } ?>><?php esc_html_e('Filters set above', 'woo-discount-rules') ?></option>
                    <option title="<?php esc_html_e('This counts the total quantity of each product / line item separately.
Example : If a customer wanted to buy 2 quantities of Product A,  3 quantities of Product B, then count will be maintained at the product level. 
2 - count of Product A
3 - Count of Product B

In case of variable products, the count will be based on each variant because WooCommerce considers a variant as a product itself.  
', 'woo-discount-rules') ?>" value="product" <?php if ($awdr_bulk_adj_operator == 'product') {
                        echo 'selected';
                    } ?>><?php esc_html_e('Individual product', 'woo-discount-rules') ?></option>
                    <option  title="<?php esc_html_e('Useful when applying discounts based on variable products and you want the quantity to be counted based on the parent product.
Example: 
Say, you have Product A - Small, Medium, Large.
If a customer buys  2 of Product A - Small,  4 of Product A - Medium,  6 of Product A - Large, then the count will be: 6+4+2 = 12', 'woo-discount-rules') ?>" value="variation" <?php if ($awdr_bulk_adj_operator == 'variation') {
                        echo 'selected';
                    } ?>><?php esc_html_e('All variants in each product together', 'woo-discount-rules') ?></option>
                </select>
            </div>
            <div class="awdr-example"></div>
        </div>
        <div class="bulk_range_setter_group" >
            <?php
            $awdr_bulk_index = 1;
            if ($awdr_bulk_adj_ranges) {
                foreach ($awdr_bulk_adj_ranges as $awdr_range_value) {
                    include 'Bulk.php';
                    $awdr_bulk_index++;
                }
            } else {
                include 'Bulk.php';
            }
            ?>
        </div>
        <div class="add-condition-and-filters awdr-discount-add-row">
            <button type="button" class="button add_discount_elements"
                    data-discount-method="add_bulk_range"
                    data-next-starting-value = ".wdr-discount-group"
                    data-append="bulk_range_setter"><?php esc_html_e('Add Range', 'woo-discount-rules') ?></button>
        </div>
        <div class="apply_discount_as_cart_section">
            <?php  $awdr_is_enabled_rtl = \Wdr\App\Helpers\Woocommerce::isRTLEnable();?>
            <div class="apply_as_cart_checkbox awdr_rtl_compatible <?php echo (!$awdr_is_enabled_rtl) ? 'page__toggle' : ''; ?> ">
                <label class="<?php echo (!$awdr_is_enabled_rtl) ? 'toggle' : ''; ?>">
                    <input class="<?php echo (!$awdr_is_enabled_rtl) ? 'toggle__input' : ''; ?> apply_fee_coupon_checkbox" type="checkbox"
                           name="bulk_adjustments[apply_as_cart_rule]" <?php echo (isset($awdr_bulk_adj_as_cart) && !empty($awdr_bulk_adj_as_cart))  ? 'checked' : '' ?> value="1">
                    <span class="<?php echo (!$awdr_is_enabled_rtl) ? 'toggle__label' : ''; ?>"><span
                                class="<?php echo (!$awdr_is_enabled_rtl) ? 'toggle__text toggle_tic' : ''; ?> "><?php esc_html_e('Show discount in cart as coupon instead of changing the product price ?', 'woo-discount-rules'); ?></span></span>
                </label>
            </div>
            <div class="simple_discount_value wdr-input-filed-hight apply_fee_coupon_label" style="<?php echo (isset($awdr_bulk_adj_as_cart) && !empty($awdr_bulk_adj_as_cart)) ? '' : 'display: none;' ?> <?php echo ($awdr_is_enabled_rtl) ? 'padding-top: 0px !important;' : ''; ?>">
                <input name="bulk_adjustments[cart_label]"
                       type="text"
                       value="<?php echo (isset($awdr_bulk_adj_as_cart_label)) ? esc_attr(wp_unslash($awdr_bulk_adj_as_cart_label)) : ''; ?>"
                       placeholder="<?php esc_html_e('Discount Label', 'woo-discount-rules'); ?>">
            </div>
        </div>
    </div>
</div>
<!-- Bulk discount End-->