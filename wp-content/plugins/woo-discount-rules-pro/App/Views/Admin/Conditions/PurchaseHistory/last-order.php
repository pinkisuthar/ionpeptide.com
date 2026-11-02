<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
$wdrpro_operator = isset($options->operator) ? $options->operator : 'later';
$wdrpro_values = isset($options->value) ? $options->value : 'now';
$wdrpro_order_status = isset($options->status) ? $options->status : false;
echo ($render_saved_condition == true) ? '' : '<div class="purchase_last_order">';
?>
<div class="wdr_purchase_last_order_group wdr-condition-type-options">
    <div class="wdr-lastorder-method wdr-select-filed-hight">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][operator]" class="awdr-left-align">
            <option value="later" <?php echo ($wdrpro_operator == "later") ? "selected" : ""; ?>><?php esc_html_e('within past', 'woo-discount-rules-pro') ?></option>
            <option value="earlier" <?php echo ($wdrpro_operator == "earlier") ? "selected" : ""; ?>><?php esc_html_e('earlier than', 'woo-discount-rules-pro') ?></option>
        </select>
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('order should be', 'woo-discount-rules-pro'); ?></span>
    </div>

    <div class="wdr-last-order-value wdr-select-filed-hight">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][value]" class="awdr-left-align">
            <optgroup label="<?php esc_attr_e('Current', 'woo-discount-rules-pro') ?>">
                <option value="now" <?php echo ($wdrpro_values == "now") ? "selected" : ""; ?>><?php esc_html_e('current day', 'woo-discount-rules-pro') ?></option>
                <option value="this_week" <?php echo ($wdrpro_values == "this_week") ? "selected" : ""; ?>><?php esc_html_e('current week', 'woo-discount-rules-pro') ?></option>
                <option value="first_day_of_this_month" <?php echo ($wdrpro_values == "first_day_of_this_month") ? "selected" : ""; ?>><?php esc_html_e('current month', 'woo-discount-rules-pro') ?></option>
                <option value="first_day_of_january_this_year" <?php echo ($wdrpro_values == "first_day_of_january_this_year") ? "selected" : ""; ?>><?php esc_html_e('current year', 'woo-discount-rules-pro') ?></option>
            </optgroup>
            <optgroup label="<?php esc_html_e('Days', 'woo-discount-rules-pro') ?>">
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
            <optgroup label="<?php esc_html_e('Weeks', 'woo-discount-rules-pro') ?>">
                <option value="-1_week" <?php echo ($wdrpro_values == "-1_week") ? "selected" : ""; ?>>
                    1 <?php esc_html_e('week', 'woo-discount-rules-pro') ?></option>
                <option value="-2_weeks" <?php echo ($wdrpro_values == "-2_weeks") ? "selected" : ""; ?>>
                    2 <?php esc_html_e('weeks', 'woo-discount-rules-pro') ?></option>
                <option value="-3_weeks" <?php echo ($wdrpro_values == "-3_weeks") ? "selected" : ""; ?>>
                    3 <?php esc_html_e('weeks', 'woo-discount-rules-pro') ?></option>
                <option value="-4_weeks" <?php echo ($wdrpro_values == "-4_weeks") ? "selected" : ""; ?>>
                    4 <?php esc_html_e('weeks', 'woo-discount-rules-pro') ?></option>
            </optgroup>
            <optgroup label="<?php esc_html_e('Months', 'woo-discount-rules-pro') ?>">
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
            <optgroup label="<?php esc_html_e('Years', 'woo-discount-rules-pro') ?>">
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
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('order time', 'woo-discount-rules-pro'); ?></span>
    </div>
    <div class="wdr-last-order-product-status wdr-select-filed-hight wdr-search-box">
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
