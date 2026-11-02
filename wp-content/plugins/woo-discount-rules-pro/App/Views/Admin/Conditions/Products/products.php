<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

$wdrpro_operator = isset($options->operator) ? $options->operator : 'in_list';
$wdrpro_cartqty = isset($options->cartqty) ? $options->cartqty : 'greater_than_or_equal';
$wdrpro_values = isset($options->value) ? $options->value : false;
echo ($render_saved_condition == true) ? '' : '<div class="cart_item_products">';
?>
<div class="products_group wdr-condition-type-options">
    <div class="wdr-product_filter_method wdr-select-filed-hight">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][operator]" class="awdr-left-align">
            <option value="in_list" <?php echo ($wdrpro_operator == "in_list") ? "selected" : ""; ?>><?php esc_html_e('In List', 'woo-discount-rules-pro'); ?></option>
            <option value="not_in_list" <?php echo ($wdrpro_operator == "not_in_list") ? "selected" : ""; ?>><?php esc_html_e('Not In List', 'woo-discount-rules-pro'); ?></option>
        </select>
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Products should be', 'woo-discount-rules-pro'); ?></span>
    </div>
    <div class="wdr-product-condition-selector wdr-select-filed-hight wdr-search-box">
        <select multiple="" name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][value][]"
                class="awdr-product-validation <?php echo ($render_saved_condition == true) ? 'edit-filters' : '' ?>"
                data-placeholder="<?php esc_attr_e('Search Products', 'woo-discount-rules-pro');?>"
                data-list="products"
                data-field="autocomplete"
                style="width: 100%; max-width: 400px;  min-width: 180px;">
            <?php
            if ($wdrpro_values) {
                $wdrpro_item_name = '';
                foreach ($wdrpro_values as $wdrpro_value) {
                    $wdrpro_item_name = '#'.$wdrpro_value.' '.\WDRPro\App\Helpers\CoreMethodCheck::getTitleOfProduct($wdrpro_value);
                    if ($wdrpro_item_name != '') { ?>
                        <option value="<?php echo esc_attr($wdrpro_value); ?>" selected><?php echo esc_html($wdrpro_item_name); ?></option><?php
                    }
                }
            } ?>
        </select>
        <span class="wdr_select2_desc_text"><?php esc_html_e('Select Products', 'woo-discount-rules-pro'); ?></span>
    </div>
    <div class="wdr-condition-products wdr-select-filed-hight">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][cartqty]" class="awdr-left-align">
            <option value="less_than_or_equal" <?php echo ($wdrpro_cartqty == "less_than_or_equal") ? "selected" : ""; ?>><?php esc_html_e('Less than or equal ( &lt;= )', 'woo-discount-rules-pro') ?></option>
            <option value="less_than" <?php echo ($wdrpro_cartqty == "less_than") ? "selected" : ""; ?>><?php esc_html_e('Less than ( &lt; )', 'woo-discount-rules-pro') ?></option>
            <option value="greater_than_or_equal" <?php echo ($wdrpro_cartqty == "greater_than_or_equal") ? "selected" : ""; ?>><?php esc_html_e('Greater than or equal ( &gt;= )', 'woo-discount-rules-pro') ?></option>
            <option value="greater_than" <?php echo ($wdrpro_cartqty == "greater_than") ? "selected" : ""; ?>><?php esc_html_e('Greater than ( &gt; )', 'woo-discount-rules-pro') ?></option>
        </select>
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Products Quantity In Cart', 'woo-discount-rules-pro'); ?></span>
    </div>
    <div class="wdr-product_filter_qty wdr-input-filed-hight">
        <input type="number" placeholder="<?php esc_attr_e('qty', 'woo-discount-rules-pro');?>" min="0" step="any"
               name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][qty]"
               class="awdr-left-align awdr-num-validation"
               value="<?php echo isset($options->qty) ? esc_attr($options->qty) : '1'; ?>">
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Product Quantity', 'woo-discount-rules-pro'); ?></span>
    </div>
</div>
<?php echo ($render_saved_condition == true) ? '' : '</div>'; ?>
