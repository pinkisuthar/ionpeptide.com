<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>
<?php
$wdrpro_operator = isset($options->operator) ? $options->operator : 'greater_than_or_equal';
echo ($render_saved_condition == true) ? '' : '<div class="cart_items_weight">';
?>
    <div class="wdr_cart_weight_group wdr-condition-type-options">
        <div class="wdr-cart-weight wdr-select-filed-hight">
            <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][operator]" class="awdr-left-align">
                <option value="less_than" <?php echo ($wdrpro_operator == "less_than") ? "selected" : ""; ?>><?php esc_html_e('Less than ( &lt; )', 'woo-discount-rules-pro') ?></option>
                <option value="less_than_or_equal" <?php echo ($wdrpro_operator == "less_than_or_equal") ? "selected" : ""; ?>><?php esc_html_e('Less than or equal ( &lt;= )', 'woo-discount-rules-pro') ?></option>
                <option value="greater_than_or_equal" <?php echo ($wdrpro_operator == "greater_than_or_equal") ? "selected" : ""; ?>><?php esc_html_e('Greater than or equal ( &gt;= )', 'woo-discount-rules-pro') ?></option>
                <option value="greater_than" <?php echo ($wdrpro_operator == "greater_than") ? "selected" : ""; ?>><?php esc_html_e('Greater than ( &gt; )', 'woo-discount-rules-pro') ?></option>
            </select>
            <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Weight should be', 'woo-discount-rules-pro'); ?></span>
        </div>

        <div class="cart-weight-value wdr-input-filed-hight">
            <input name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][value]" type="text" class="float_only_field awdr-left-align"
                   value="<?php echo (isset($options->value)) ? esc_attr($options->value) : '' ?>" placeholder="<?php esc_attr_e('0.00', 'woo-discount-rules-pro');?>">
            <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Weight', 'woo-discount-rules-pro'); ?></span>
        </div>
    </div>
<?php echo ($render_saved_condition == true) ? '' : '</div>'; ?>