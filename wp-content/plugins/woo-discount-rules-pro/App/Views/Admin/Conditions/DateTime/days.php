<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
$wdrpro_values = isset($options->value) ? $options->value : false;
$wdrpro_operator = isset($options->operator) ? $options->operator : 'in_list';
echo ($render_saved_condition == true) ? '' : '<div class="order_days">';
?>
<div class="wdr_days_group wdr-condition-type-options">
    <div class="wdr-days-method wdr-select-filed-hight">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][operator]" class="awdr-left-align">
            <option value="in_list" <?php echo ($wdrpro_operator == "in_list") ? "selected" : ""; ?>><?php esc_html_e('In List', 'woo-discount-rules-pro'); ?></option>
            <option value="not_in_list" <?php echo ($wdrpro_operator == "not_in_list") ? "selected" : ""; ?>><?php esc_html_e('Not In List', 'woo-discount-rules-pro'); ?></option>
        </select>
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Day should be', 'woo-discount-rules-pro'); ?></span>
    </div>

    <div class="wdr-days-value wdr-select-filed-hight wdr-search-box">
        <select multiple
                class="wdr_order_days <?php echo ($render_saved_condition == true) ? 'edit-all-loaded-values' : '' ?>"
                data-list="weekdays"
                data-field="autoloaded"
                data-placeholder="<?php esc_html_e('Search Days', 'woo-discount-rules-pro') ?>"
                name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][value][]"><?php
            if ($wdrpro_values) {
                $wdrpro_settings_config = new \Wdr\App\Controllers\Admin\Settings();
                $wdrpro_week_days = $wdrpro_settings_config->getWeekDays();
                $wdrpro_week_day_label = '';
                foreach ($wdrpro_values as $wdrpro_value) {
                    foreach ($wdrpro_week_days as $wdrpro_week_day) {
                        if ($wdrpro_value == $wdrpro_week_day['id']) {
                            $wdrpro_week_day_label = $wdrpro_week_day['text'];
                        }
                    }
                    if ($wdrpro_week_day_label != '') {
                        ?>
                        <option value="<?php echo esc_attr($wdrpro_value); ?>" selected><?php echo esc_html($wdrpro_week_day_label); ?></option><?php
                    }
                }
            }
            ?>
        </select>
        <span class="wdr_select2_desc_text"><?php esc_html_e('Select Days', 'woo-discount-rules-pro'); ?></span>
    </div>
</div>
<?php echo ($render_saved_condition == true) ? '' : '</div>'; ?>
