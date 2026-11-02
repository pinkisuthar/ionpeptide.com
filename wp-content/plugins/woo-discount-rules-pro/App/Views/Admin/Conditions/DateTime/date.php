<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
echo ($render_saved_condition == true) ? '' : '<div class="order_date">';
?>
<div class="wdr_date_group wdr-condition-type-options">
    <div class="wdr-date-from wdr-input-filed-hight">
        <input type="text"
               name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][from]"
               class="wdr-condition-date awdr-from-date awdr-left-align" placeholder="<?php esc_html_e('From date', 'woo-discount-rules-pro'); ?>" data-field="date"
               value="<?php echo isset($options->from) ? esc_attr($options->from) : ''; ?>" data-class="start_dateonly"
               autocomplete="off">
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('select date from', 'woo-discount-rules-pro'); ?></span>
    </div>
    <div class="wdr-date-to wdr-input-filed-hight">
        <input type="text"
               name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][to]"
               value="<?php echo isset($options->to) ? esc_attr($options->to) : ''; ?>"
               class="wdr-condition-date awdr-end-date awdr-left-align" placeholder="<?php esc_html_e('To date', 'woo-discount-rules-pro'); ?>"
               data-field="date" data-class="end_dateonly"
               autocomplete="off">
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('select date to ', 'woo-discount-rules-pro'); ?></span>
    </div>
</div>
<?php echo ($render_saved_condition == true) ? '' : '</div>'; ?>

