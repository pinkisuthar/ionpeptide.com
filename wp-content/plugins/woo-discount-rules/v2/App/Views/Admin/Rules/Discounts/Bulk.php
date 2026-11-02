<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
$awdr_is_pro = \Wdr\App\Helpers\Helper::hasPro();
?>
<div class="wdr-discount-group awdr-bulk-group" data-index="<?php echo esc_attr($awdr_bulk_index); ?>">
    <div class="range_setter_inner">
        <div class="bulk-row-main">
            <div class="bulk-row-start wdr-input-filed-hight bulk-row-inner">
                <div class="dashicons dashicons-menu awdr-sort-icon awdr-sortable-handle"></div>
                <div class="bulk-min">
                    <input type="number"
                           name="bulk_adjustments[ranges][<?php echo esc_attr($awdr_bulk_index); ?>][from]"
                           class="bulk_discount_min awdr_value_selector awdr_next_value"
                           placeholder="<?php esc_html_e('min', 'woo-discount-rules'); ?>"
                           min="0"
                           step="any"
                           value="<?php if (isset($awdr_range_value->from) && !empty($awdr_range_value->from)) {
                               echo esc_attr($awdr_range_value->from);
                           } ?>">
                    <span class="wdr_desc_text"><?php esc_html_e('Minimum Quantity ', 'woo-discount-rules'); ?></span>
                </div>
                <div class="bulk-max">
                    <input type="number"
                           name="bulk_adjustments[ranges][<?php echo esc_attr($awdr_bulk_index); ?>][to]"
                           class="bulk_discount_max awdr_value_selector awdr_auto_add_value"
                           placeholder="<?php esc_html_e('max', 'woo-discount-rules'); ?>"
                           min="0"
                           step="any"
                           value="<?php if (isset($awdr_range_value->to) && !empty($awdr_range_value->to)) {
                               echo esc_attr($awdr_range_value->to);
                           } ?>">
                    <span class="wdr_desc_text"><?php esc_html_e('Maximum Quantity ', 'woo-discount-rules'); ?></span>
                </div>
                <div class="bulk_gen_disc_type wdr-select-filed-hight">
                    <select name="bulk_adjustments[ranges][<?php echo esc_attr($awdr_bulk_index); ?>][type]"
                            class="bulk-discount-type bulk_discount_select">
                        <option value="percentage" <?php if (isset($awdr_range_value->type) && $awdr_range_value->type == 'percentage') {
                            echo 'selected';
                        } ?>><?php esc_html_e('Percentage discount', 'woo-discount-rules') ?></option>
                        <option value="flat" <?php if (isset($awdr_range_value->type) && $awdr_range_value->type == 'flat') {
                            echo 'selected';
                        } ?>><?php esc_html_e('Fixed discount', 'woo-discount-rules') ?></option>
                        <?php if($awdr_is_pro){ ?>
                            <option value="fixed_price" <?php if (isset($awdr_range_value->type) && $awdr_range_value->type == 'fixed_price') {
                                echo 'selected';
                            } ?>><?php esc_html_e('Fixed price for item', 'woo-discount-rules') ?></option>
                        <?php } else { ?>
                            <option disabled><?php esc_html_e('Fixed price for item - PRO -', 'woo-discount-rules') ?></option>
                        <?php } ?>
                    </select>
                    <span class="wdr_desc_text"><?php esc_html_e('Discount Type', 'woo-discount-rules'); ?></span>
                </div>
                <div class="bulk_amount">
                    <input type="number"
                           name="bulk_adjustments[ranges][<?php echo esc_attr($awdr_bulk_index); ?>][value]"
                           class="bulk_discount_value bulk_value_selector awdr_value_selector"
                           placeholder="<?php esc_html_e('Discount', 'woo-discount-rules'); ?>"
                           min="0"
                           step="any"
                           value="<?php echo (isset($awdr_range_value->value) && $awdr_range_value->value >= 0) ? esc_attr(floatval($awdr_range_value->value)) : 0;?>">
                    <span class="wdr_desc_text"><?php esc_html_e('Discount Value', 'woo-discount-rules'); ?></span>
                </div>
                <div class="bulk_amount">
                    <input type="text" name="bulk_adjustments[ranges][<?php echo esc_attr($awdr_bulk_index); ?>][label]"
                           class="bulk_value_selector awdr_value_selector"
                           placeholder="<?php esc_html_e('Label', 'woo-discount-rules'); ?>" min="0"
                           value="<?php if (isset($awdr_range_value->label) && !empty($awdr_range_value->label)) {
                               echo esc_attr(wp_unslash($awdr_range_value->label));
                           } ?>">
                    <span class="wdr_desc_text"><?php esc_html_e('Title column For Bulk Table', 'woo-discount-rules'); ?></span>
                </div>
                <div class="wdr-btn-remove">
                    <span class="dashicons dashicons-no-alt wdr_discount_remove" data-rmdiv="bulk_range_group"></span>
                </div>
            </div>
        </div>
    </div>
</div>