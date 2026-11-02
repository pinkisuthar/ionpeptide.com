<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
echo ($render_saved_condition == true) ? '' : '<div class="shipping_zipcode">';
$wdrpro_operator = isset($options->operator) ? $options->operator : 'in_list';
?>
<div class="wdr_shipping_zipcode_group wdr-condition-type-options">
    <div class="wdr-shipping-zipcode-method wdr-select-filed-hight">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][operator]" class="awdr-left-align">
            <option value="in_list" <?php echo ($wdrpro_operator == "in_list") ? "selected" : ""; ?>><?php esc_html_e('In List', 'woo-discount-rules-pro'); ?></option>
            <option value="not_in_list" <?php echo ($wdrpro_operator == "not_in_list") ? "selected" : ""; ?>><?php esc_html_e('Not In List', 'woo-discount-rules-pro'); ?></option>
        </select>
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('zipcode should be', 'woo-discount-rules-pro'); ?></span>
    </div>

    <div class="wdr-shipping-zipcode-value wdr-input-filed-hight">
        <input type="text" style="width: 245px;" name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][value]"
               placeholder="<?php esc_attr_e('Enter Zipcode ', 'woo-discount-rules-pro');?>" class="awdr-left-align awdr-validation"
               value="<?php echo (isset($options->value)) ? esc_attr($options->value) : '' ?>">
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Example: 94027, 90210', 'woo-discount-rules-pro'); ?></span>
    </div>
</div>
<?php echo ($render_saved_condition == true) ? '' : '</div>'; ?>

