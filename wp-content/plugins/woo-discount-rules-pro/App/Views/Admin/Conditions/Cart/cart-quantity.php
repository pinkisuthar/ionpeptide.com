<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
$wdrpro_operator = isset($options->operator) ? $options->operator : 'greater_than_or_equal';
$wdrpro_calculate_from = isset($options->calculate_from) ? $options->calculate_from : 'from_cart';
echo ($render_saved_condition == true) ? '' : '<div class="cart_items_quantity">';
?>
<div class="wdr_cart_item_quantity_group wdr-condition-type-options">
    <div class="wdr-quantity-subtotal wdr-select-filed-hight">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][operator]" class="awdr-left-align wdr_quantity_operator">
            <option value="less_than" <?php echo ($wdrpro_operator == "less_than") ? "selected" : ""; ?>><?php esc_html_e('Less than ( &lt; )', 'woo-discount-rules-pro') ?></option>
            <option value="less_than_or_equal" <?php echo ($wdrpro_operator == "less_than_or_equal") ? "selected" : ""; ?>><?php esc_html_e('Less than or equal ( &lt;= )', 'woo-discount-rules-pro') ?></option>
            <option value="greater_than_or_equal" <?php echo ($wdrpro_operator == "greater_than_or_equal") ? "selected" : ""; ?>><?php esc_html_e('Greater than or equal ( &gt;= )', 'woo-discount-rules-pro') ?></option>
            <option value="greater_than" <?php echo ($wdrpro_operator == "greater_than") ? "selected" : ""; ?>><?php esc_html_e('Greater than ( &gt; )', 'woo-discount-rules-pro') ?></option>
        </select>
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Cart item quantity should be', 'woo-discount-rules-pro'); ?></span>
    </div>

    <div class="cart-quantity-value wdr-input-filed-hight">
        <input name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][value]"
               value="<?php echo (isset($options->value)) ? esc_attr($options->value) : '' ?>" type="text" class="float_only_field awdr-left-align"
               placeholder="<?php esc_attr_e('1', 'woo-discount-rules-pro');?>">
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Cart item quantity', 'woo-discount-rules-pro'); ?></span>
    </div>
    <div class="wdr-quantity-subtotal wdr-select-filed-hight">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][calculate_from]" class="calculate_cart_from awdr-left-align">
            <option value="from_cart" <?php echo ($wdrpro_calculate_from == "from_cart") ? "selected" : ""; ?>><?php esc_html_e('Count all items in cart', 'woo-discount-rules-pro') ?></option>
            <option value="from_filter" <?php echo ($wdrpro_calculate_from == "from_filter") ? "selected" : ""; ?>><?php esc_html_e('Only count items chosen in the filters set for this rule', 'woo-discount-rules-pro') ?></option>
        </select>
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('How to calculate the item quantity', 'woo-discount-rules-pro'); ?></span>
    </div>
</div>
<?php echo ($render_saved_condition == true) ? '' : '</div>'; ?>
