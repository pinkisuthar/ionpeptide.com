<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
echo ($render_saved_condition == true) ? '' : '<div class="order_date_and_time">';
?>
<div class="wdr_date_and_time_group wdr-condition-type-options">
    <div class="wdr-dateandtime-from wdr-input-filed-hight">
        <input type="text"
               name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][from]"
               value="<?php echo isset($options->from) ? esc_attr($options->from) : ''; ?>"
               class="wdr-condition-date awdr-left-align awdr-from-date" placeholder="<?php esc_attr_e('From date', 'woo-discount-rules-pro');?>"
               data-field="date" data-class="start_datetimeonly"
               autocomplete="off">
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('select date from', 'woo-discount-rules-pro'); ?></span>
    </div>
    <div class="wdr-dateandtime-to wdr-input-filed-hight">
        <input type="text"
               name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][to]"
               value="<?php echo isset($options->to) ? esc_attr($options->to) : ''; ?>"
               class="wdr-condition-date awdr-left-align awdr-end-date" placeholder="<?php esc_attr_e('To date', 'woo-discount-rules-pro');?>" data-field="date" data-class="end_datetimeonly"
               autocomplete="off">
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('select date to', 'woo-discount-rules-pro'); ?></span>
    </div>
</div>
<?php echo ($render_saved_condition == true) ? '' : '</div>'; ?>

