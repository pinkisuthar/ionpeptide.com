<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
$wdrpro_products = isset($options->products) ? $options->products : false;
$wdrpro_operator = isset($options->operator) ? $options->operator : 'greater_than_or_equal';
$wdrpro_values = isset($options->time) ? $options->time : 'all_time';
$wdrpro_order_status = isset($options->status) ? $options->status : false;
echo ($render_saved_condition == true) ? '' : '<div class="purchase_previous_orders_for_specific_product">';
?>
<div class="wdr_purchase_previous_orders_for_specific_product_group wdr-condition-type-options">
    <div class="wdr-previous-order-product-selector wdr-select-filed-hight wdr-search-box">
        <select multiple=""
                class="specific_product <?php echo ($render_saved_condition == true) ? 'edit-filters' : '' ?>"
                name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][products][]"
                data-placeholder="<?php esc_attr_e('Search Product', 'woo-discount-rules-pro');?>"
                data-list="products"
                data-field="autocomplete"
                style="width: 100%; max-width: 400px;  min-width: 180px;"><?php
            if ($wdrpro_products) {
                $wdrpro_item_name = '';
                foreach ($wdrpro_products as $wdrpro_value) {
                    $wdrpro_item_name = \WDRPro\App\Helpers\CoreMethodCheck::getTitleOfProduct($wdrpro_value);
                    if ($wdrpro_item_name != '') { ?>
                        <option value="<?php echo esc_attr($wdrpro_value); ?>" selected><?php echo esc_html($wdrpro_item_name); ?></option><?php
                    }
                }
            } ?>
        </select>
        <span class="wdr_select2_desc_text"><?php esc_html_e('Select Product', 'woo-discount-rules-pro'); ?></span>
    </div>
    <div class="previous-order-against-product-method wdr-select-filed-hight">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][time]" class="awdr-left-align">
            <optgroup label="All time">
                <option value="all_time" <?php echo ($wdrpro_values == "all_time") ? "selected" : ""; ?>><?php esc_html_e('all time', 'woo-discount-rules-pro') ?></option>
            </optgroup>
            <optgroup label="<?php esc_attr_e('Current', 'woo-discount-rules-pro') ?>">
                <option value="now" <?php echo ($wdrpro_values == "now") ? "selected" : ""; ?>><?php esc_html_e('current day', 'woo-discount-rules-pro') ?></option>
                <option value="this_week" <?php echo ($wdrpro_values == "this_week") ? "selected" : ""; ?>><?php esc_html_e('current week', 'woo-discount-rules-pro') ?></option>
                <option value="first_day_of_this_month" <?php echo ($wdrpro_values == "first_day_of_this_month") ? "selected" : ""; ?>><?php esc_html_e('current month', 'woo-discount-rules-pro') ?></option>
                <option value="first_day_of_january_this_year" <?php echo ($wdrpro_values == "first_day_of_january_this_year") ? "selected" : ""; ?>><?php esc_html_e('current year', 'woo-discount-rules-pro') ?></option>
            </optgroup>
            <optgroup label="<?php esc_attr_e('Days', 'woo-discount-rules-pro') ?>">
                <option value="-1_day" <?php echo ($wdrpro_values == "-1_day") ? "selected" : ""; ?>>
                    1 <?php esc_html_e('day', 'woo-discount-rules-pro') ?></option>
                <option value="-2_days" <?php echo ($wdrpro_values == "-2_days") ? "selected" : ""; ?>>
                    2 <?php esc_html_e('days', 'woo-discount-rules-pro') ?></option>
                <option value="-3_days" <?php echo ($wdrpro_values == "-3_days") ? "selected" : ""; ?>>
                    3 <?php esc_html_e('days', 'woo-discount-rules-pro') ?></option>
                <option value="-4_days" <?php echo ($wdrpro_values == "-4_days") ? "selected" : ""; ?>>
                    4 <?php esc_html_e('days', 'woo-discount-rules-pro') ?></option>
                <option value="-5_days" <?php echo ($wdrpro_values == "-5_days") ? "selected" : ""; ?>>
                    5 <?php esc_html_e('days', 'woo-discount-rules-pro') ?></option>
                <option value="-6_days" <?php echo ($wdrpro_values == "-6_days") ? "selected" : ""; ?>>
                    6 <?php esc_html_e('days', 'woo-discount-rules-pro') ?></option>
            </optgroup>
            <optgroup label="<?php esc_attr_e('Weeks', 'woo-discount-rules-pro') ?>">
                <option value="-1_week" <?php echo ($wdrpro_values == "-1_week") ? "selected" : ""; ?>>
                    1 <?php esc_html_e('week', 'woo-discount-rules-pro') ?></option>
                <option value="-2_weeks" <?php echo ($wdrpro_values == "-2_weeks") ? "selected" : ""; ?>>
                    2 <?php esc_html_e('weeks', 'woo-discount-rules-pro') ?></option>
                <option value="-3_weeks" <?php echo ($wdrpro_values == "-3_weeks") ? "selected" : ""; ?>>
                    3 <?php esc_html_e('weeks', 'woo-discount-rules-pro') ?></option>
                <option value="-4_weeks" <?php echo ($wdrpro_values == "-4_weeks") ? "selected" : ""; ?>>
                    4 <?php esc_html_e('weeks', 'woo-discount-rules-pro') ?></option>
            </optgroup>
            <optgroup label="<?php esc_attr_e('Months', 'woo-discount-rules-pro') ?>">
                <option value="-1_month" <?php echo ($wdrpro_values == "-1_month") ? "selected" : ""; ?>>
                    1 <?php esc_html_e('month', 'woo-discount-rules-pro') ?></option>
                <option value="-2_months" <?php echo ($wdrpro_values == "-2_months") ? "selected" : ""; ?>>
                    2 <?php esc_html_e('months', 'woo-discount-rules-pro') ?></option>
                <option value="-3_months" <?php echo ($wdrpro_values == "-3_months") ? "selected" : ""; ?>>
                    3 <?php esc_html_e('months', 'woo-discount-rules-pro') ?></option>
                <option value="-4_months" <?php echo ($wdrpro_values == "-4_months") ? "selected" : ""; ?>>
                    4 <?php esc_html_e('months', 'woo-discount-rules-pro') ?></option>
                <option value="-5_months" <?php echo ($wdrpro_values == "-5_months") ? "selected" : ""; ?>>
                    5 <?php esc_html_e('months', 'woo-discount-rules-pro') ?></option>
                <option value="-6_months" <?php echo ($wdrpro_values == "-6_months") ? "selected" : ""; ?>>
                    6 <?php esc_html_e('months', 'woo-discount-rules-pro') ?></option>
                <option value="-7_months" <?php echo ($wdrpro_values == "-7_months") ? "selected" : ""; ?>>
                    7 <?php esc_html_e('months', 'woo-discount-rules-pro') ?></option>
                <option value="-8_months" <?php echo ($wdrpro_values == "-8_months") ? "selected" : ""; ?>>
                    8 <?php esc_html_e('months', 'woo-discount-rules-pro') ?></option>
                <option value="-9_months" <?php echo ($wdrpro_values == "-9_months") ? "selected" : ""; ?>>
                    9 <?php esc_html_e('months', 'woo-discount-rules-pro') ?></option>
                <option value="-10_months" <?php echo ($wdrpro_values == "-10_months") ? "selected" : ""; ?>>
                    10 <?php esc_html_e('months', 'woo-discount-rules-pro') ?></option>
                <option value="-11_months" <?php echo ($wdrpro_values == "-11_months") ? "selected" : ""; ?>>
                    11 <?php esc_html_e('months', 'woo-discount-rules-pro') ?></option>
                <option value="-12_months" <?php echo ($wdrpro_values == "-12_months") ? "selected" : ""; ?>>
                    12 <?php esc_html_e('months', 'woo-discount-rules-pro') ?></option>
            </optgroup>
            <optgroup label="<?php esc_attr_e('Years', 'woo-discount-rules-pro') ?>">
                <option value="-2_years" <?php echo ($wdrpro_values == "-2_years") ? "selected" : ""; ?>>
                    2 <?php esc_html_e('years', 'woo-discount-rules-pro') ?></option>
                <option value="-3_years" <?php echo ($wdrpro_values == "-3_years") ? "selected" : ""; ?>>
                    3 <?php esc_html_e('years', 'woo-discount-rules-pro') ?></option>
                <option value="-4_years" <?php echo ($wdrpro_values == "-4_years") ? "selected" : ""; ?>>
                    4 <?php esc_html_e('years', 'woo-discount-rules-pro') ?></option>
                <option value="-5_years" <?php echo ($wdrpro_values == "-5_years") ? "selected" : ""; ?>>
                    5 <?php esc_html_e('years', 'woo-discount-rules-pro') ?></option>
                <option value="-6_years" <?php echo ($wdrpro_values == "-6_years") ? "selected" : ""; ?>>
                    6 <?php esc_html_e('years', 'woo-discount-rules-pro') ?></option>
                <option value="-7_years" <?php echo ($wdrpro_values == "-7_years") ? "selected" : ""; ?>>
                    7 <?php esc_html_e('years', 'woo-discount-rules-pro') ?></option>
                <option value="-8_years" <?php echo ($wdrpro_values == "-8_years") ? "selected" : ""; ?>>
                    8 <?php esc_html_e('years', 'woo-discount-rules-pro') ?></option>
                <option value="-9_years" <?php echo ($wdrpro_values == "-9_years") ? "selected" : ""; ?>>
                    9 <?php esc_html_e('years', 'woo-discount-rules-pro') ?></option>
                <option value="-10_years" <?php echo ($wdrpro_values == "-10_years") ? "selected" : ""; ?>>
                    10 <?php esc_html_e('years', 'woo-discount-rules-pro') ?></option>
            </optgroup>
        </select>
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Purchase Before', 'woo-discount-rules-pro'); ?></span>
    </div>

    <div class="previous-order-against-product-comparision wdr-select-filed-hight">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][operator]" class="awdr-left-align">
            <option value="less_than" <?php echo ($wdrpro_operator == "less_than") ? "selected" : ""; ?>><?php esc_html_e('Less than ( &lt; )', 'woo-discount-rules-pro') ?></option>
            <option value="less_than_or_equal" <?php echo ($wdrpro_operator == "less_than_or_equal") ? "selected" : ""; ?>><?php esc_html_e('Less than or equal ( &lt;= )', 'woo-discount-rules-pro') ?></option>
            <option value="greater_than_or_equal" <?php echo ($wdrpro_operator == "greater_than_or_equal") ? "selected" : ""; ?>><?php esc_html_e('Greater than or equal ( &gt;= )', 'woo-discount-rules-pro') ?></option>
            <option value="greater_than" <?php echo ($wdrpro_operator == "greater_than") ? "selected" : ""; ?>><?php esc_html_e('Greater than ( &gt; )', 'woo-discount-rules-pro') ?></option>
        </select>
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Purchase Quantity Should Be', 'woo-discount-rules-pro'); ?></span>
    </div>

    <div class="previous-order-against-product-amount wdr-input-filed-hight">
        <input name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][count]"
               value="<?php echo (isset($options->count)) ? esc_attr($options->count) : '' ?>" type="text"
               class="float_only_field awdr-left-align" placeholder="<?php esc_attr_e('1', 'woo-discount-rules-pro');?>"
               min="1">
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Purchase Quantity', 'woo-discount-rules-pro'); ?></span>
    </div>


    <div class="wdr-previous-order-product-status wdr-select-filed-hight wdr-search-box">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][status][]" multiple
                class="wdr-wc-order-status <?php echo ($render_saved_condition == true) ? 'edit-all-loaded-values' : '' ?>"
                data-placeholder="<?php esc_attr_e('Search Order Status', 'woo-discount-rules-pro');?>"
                data-list="order_status"
                data-field="autoloaded"
                style="width: 100%; max-width: 400px;  min-width: 180px;"><?php
            if ($wdrpro_order_status) {
                $wdrpro_settings_config = new \Wdr\App\Controllers\Admin\Settings();
                $wdrpro_woo_order_status = $wdrpro_settings_config->getWoocommerceOrderStatus();
                foreach ($wdrpro_order_status as $wdrpro_status) {
                    foreach ($wdrpro_woo_order_status as $wdrpro_woo_status) {
                        if ($wdrpro_woo_status['id'] == $wdrpro_status) {
                            $wdrpro_order_status_label = $wdrpro_woo_status['text'];
                        }
                    }
                    if ($wdrpro_order_status_label != '') { ?>
                        <option value="<?php echo esc_attr($wdrpro_status); ?>" selected><?php echo esc_html($wdrpro_order_status_label); ?></option><?php
                    }
                }
            }
            ?>
        </select>
        <span class="wdr_select2_desc_text"><?php esc_html_e('order status', 'woo-discount-rules-pro'); ?></span>
    </div>
</div>
<?php echo ($render_saved_condition == true) ? '' : '</div>'; ?>
