<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
$awdr_operator = isset($options->operator) ? $options->operator : 'greater_than_or_equal';
$awdr_calculate_from = isset($options->calculate_from) ? $options->calculate_from : 'from_cart';
echo ($render_saved_condition == true) ? '' : '<div class="cart_subtotal">';
?>
<div class="wdr_cart_subtotal_group wdr-condition-type-options">
    <div class="wdr_cart_subtotal_group">
        <div class="cart-subtotal-methods wdr-select-filed-hight">
            <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][operator]" class="awdr-left-align subtotal_operator">
                <option value="less_than" <?php echo ($awdr_operator == "less_than") ? "selected" : ""; ?>><?php esc_html_e('Less than ( &lt; )', 'woo-discount-rules') ?></option>
                <option value="less_than_or_equal" <?php echo ($awdr_operator == "less_than_or_equal") ? "selected" : ""; ?>><?php esc_html_e('Less than or equal ( &lt;= )', 'woo-discount-rules') ?></option>
                <option value="greater_than_or_equal" <?php echo ($awdr_operator == "greater_than_or_equal") ? "selected" : ""; ?>><?php esc_html_e('Greater than or equal ( &gt;= )', 'woo-discount-rules') ?></option>
                <option value="greater_than" <?php echo ($awdr_operator == "greater_than") ? "selected" : ""; ?>><?php esc_html_e('Greater than ( &gt; )', 'woo-discount-rules') ?></option>
            </select>
            <span class="wdr_desc_text awdr-clear-both"><?php esc_html_e('Subtotal should be', 'woo-discount-rules'); ?></span>
        </div>

        <div class="cart-subtotal-value wdr-input-filed-hight">
            <input name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][value]" type="text" class="float_only_field awdr-left-align"
                   value="<?php echo (isset($options->value)) ? esc_attr($options->value) : '' ?>" placeholder="<?php esc_html_e('0.00', 'woo-discount-rules');?>" min="0">
            <span class="wdr_desc_text awdr-clear-both"><?php esc_html_e('Subtotal Amount ', 'woo-discount-rules'); ?></span>
        </div>

        <div class="cart-subtotal-methods wdr-select-filed-hight">
            <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][calculate_from]" class="calculate_cart_from awdr-left-align">
                <option value="from_cart" <?php echo ($awdr_calculate_from == "from_cart") ? "selected" : ""; ?>><?php esc_html_e('Count all items in cart', 'woo-discount-rules') ?></option>
                <option value="from_filter" <?php echo ($awdr_calculate_from == "from_filter") ? "selected" : ""; ?>><?php esc_html_e('Only count items chosen in the filters set for this rule', 'woo-discount-rules') ?></option>
            </select>
            <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('How to calculate the subtotal', 'woo-discount-rules'); ?></span>
        </div>
    </div>
</div>
<?php echo ($render_saved_condition == true) ? '' : '</div>'; ?>
