<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
echo ($render_saved_condition == true) ? '' : '<div class="order_time">';
?>
    <div class="wdr_time_group wdr-condition-type-options">
        <div class="wdr-time-from wdr-input-filed-hight">
            <input type="text"
                   name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][from]" placeholder="<?php esc_attr_e('From', 'woo-discount-rules-pro');?>"
                   class="wdr_time_picker awdr-left-align wdr-from-time"
                   value="<?php echo isset($options->from) ? esc_attr($options->from) : ''; ?>">
            <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Time From', 'woo-discount-rules-pro'); ?></span>
        </div>
        <div class="wdr-time-to wdr-input-filed-hight">
            <input type="text"
                   name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][to]" placeholder="<?php esc_attr_e('To', 'woo-discount-rules-pro');?>"
                   class="wdr_time_picker awdr-left-align wdr-to-time"
                   value="<?php echo isset($options->to) ? esc_attr($options->to) : ''; ?>">
            <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Time To', 'woo-discount-rules-pro'); ?></span>
        </div>
    </div>
<?php echo ($render_saved_condition == true) ? '' : '</div>'; ?>