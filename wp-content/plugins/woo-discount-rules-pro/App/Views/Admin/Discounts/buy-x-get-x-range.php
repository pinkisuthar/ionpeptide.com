<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wdr-discount-group buyx_getx_individual_range" data-index="<?php echo esc_attr($wdrpro_buyx_getx_index); ?>">
    <div class="range_setter_inner">
        <div class="wdr-buyx-getx-discount-main">
            <div class="wdr-buyx-getx-discount-inner wdr-input-filed-hight">
                <div class="dashicons dashicons-menu awdr-set-sort-icon awdr-sortable-handle" style="padding-top: 16px !important;"></div>
                <div class="awdr-buyx-getx-min">
                    <input type="number" name="buyx_getx_adjustments[ranges][<?php echo esc_attr($wdrpro_buyx_getx_index); ?>][from]"
                           class="awdr-buyx-getx-number-box awdr_value_selector awdr_next_value bxgx-min"
                           placeholder="<?php esc_attr_e('Min Quantity', 'woo-discount-rules-pro'); ?>" min="0" step="any"
                           value="<?php echo (isset($wdrpro_buyx_getx_adjustment->from) && !empty($wdrpro_buyx_getx_adjustment->from)) ? esc_attr($wdrpro_buyx_getx_adjustment->from) : '1';?>"
                    >
                    <span class="wdr_desc_text"><?php echo (isset($wdrpro_buyx_getx_adjustment->recursive) && !empty($wdrpro_buyx_getx_adjustment->recursive)) ? esc_html__('Quantity', 'woo-discount-rules-pro') :  esc_html__('Minimum Quantity', 'woo-discount-rules-pro'); ?></span>
                </div>

                <div class="awdr-buyx-getx-max" style="<?php echo (isset($wdrpro_buyx_getx_adjustment->recursive) && !empty($wdrpro_buyx_getx_adjustment->recursive)) ? 'display:none' : ''; ?>">
                    <input type="number" name="buyx_getx_adjustments[ranges][<?php echo esc_attr($wdrpro_buyx_getx_index); ?>][to]"
                           class="awdr-buyx-getx-number-box awdr_value_selector awdr_auto_add_value bxgx-max"
                           placeholder="<?php esc_attr_e('Max Quantity', 'woo-discount-rules-pro'); ?>" min="0" step="any"
                           value="<?php
                           if(isset($wdrpro_buyx_getx_adjustment->to) && !empty($wdrpro_buyx_getx_adjustment->to)){
                               $wdrpro_buyx_getx_adjustment_to = $wdrpro_buyx_getx_adjustment->to;
                           }elseif(isset($wdrpro_buyx_getx_adjustment->from) && isset($wdrpro_buyx_getx_adjustment->to) && !empty($wdrpro_buyx_getx_adjustment->from) && empty($wdrpro_buyx_getx_adjustment->to)){
                               $wdrpro_buyx_getx_adjustment_to = '';
                           }else{
                               $wdrpro_buyx_getx_adjustment_to = 1;
                           }
                           echo esc_attr($wdrpro_buyx_getx_adjustment_to); ?>"
                    >
                    <span class="wdr_desc_text"><?php esc_html_e('Maximum Quantity', 'woo-discount-rules-pro'); ?></span>
                </div>
                <div class="awdr-buyx-getx-free-qty">
                    <input type="number"
                           name="buyx_getx_adjustments[ranges][<?php echo esc_attr($wdrpro_buyx_getx_index); ?>][free_qty]"
                           class="awdr-buyx-getx-number-box awdr_value_selector bxgx-qty"
                           placeholder="<?php esc_attr_e('Free Quantity', 'woo-discount-rules-pro'); ?>" min="1" step="any"
                           value="<?php echo (isset($wdrpro_buyx_getx_adjustment->free_qty) && !empty($wdrpro_buyx_getx_adjustment->free_qty)) ? esc_attr($wdrpro_buyx_getx_adjustment->free_qty) : '1';?>"
                    >
                    <span class="wdr_desc_text"><?php esc_html_e('Free Quantity', 'woo-discount-rules-pro'); ?></span>
                </div>
                <div class="awdr-buyx-getx-option wdr-select-filed-hight">
                    <select name="buyx_getx_adjustments[ranges][<?php echo esc_attr($wdrpro_buyx_getx_index); ?>][free_type]"
                            class="awdr-bogo-discount-type buyx_getx_discount_select"
                            data-parent="awdr-buyx-getx-option"
                            data-siblings="awdr-getx-value">
                        <option value="free_product" <?php echo (isset($wdrpro_buyx_getx_adjustment->free_type) && $wdrpro_buyx_getx_adjustment->free_type == 'free_product') ? 'selected' : '';?>><?php esc_html_e('Free', 'woo-discount-rules-pro') ?></option>
                        <option value="percentage" <?php echo (isset($wdrpro_buyx_getx_adjustment->free_type) && $wdrpro_buyx_getx_adjustment->free_type == 'percentage') ? 'selected' : '';?>><?php esc_html_e('Percentage discount', 'woo-discount-rules-pro') ?></option>
                        <option value="flat" <?php echo (isset($wdrpro_buyx_getx_adjustment->free_type) && $wdrpro_buyx_getx_adjustment->free_type == 'flat') ? 'selected' : '';?>><?php esc_html_e('Fixed discount', 'woo-discount-rules-pro') ?></option>
                    </select>
                    <span class="wdr_desc_text"><?php esc_html_e('Discount type ', 'woo-discount-rules-pro'); ?></span>
                </div>
                <div class="awdr-getx-value" style="<?php echo (isset($wdrpro_buyx_getx_adjustment->free_type) && $wdrpro_buyx_getx_adjustment->free_type != 'free_product') ? '' : 'display: none;';?>">
                    <input type="number" name="buyx_getx_adjustments[ranges][<?php echo esc_attr($wdrpro_buyx_getx_index); ?>][free_value]"
                           class="awdr-buyx-getx-number-box awdr_value_selector bxgx-value"
                           placeholder="<?php esc_attr_e('Value', 'woo-discount-rules-pro'); ?>" min="0" step="any"
                           value="<?php echo (isset($wdrpro_buyx_getx_adjustment->free_value) && !empty($wdrpro_buyx_getx_adjustment->free_value)) ? esc_attr($wdrpro_buyx_getx_adjustment->free_value) : '1';?>"
                    >
                    <span class="wdr_desc_text"><?php echo (isset($wdrpro_buyx_getx_adjustment->free_type) && $wdrpro_buyx_getx_adjustment->free_type == 'flat') ? esc_html__('Discount value ', 'woo-discount-rules-pro') :  esc_html__('Discount percentage ', 'woo-discount-rules-pro'); ?></span>
                </div>
                <div class="awdr-buyx-getx-recursive"><?php
                    $wdrpro_is_enabled_rtl =  \WDRPro\App\Helpers\CoreMethodCheck::isRTLEnable(); ?>
                    <div class="<?php echo (!$wdrpro_is_enabled_rtl) ? 'page__toggle' : ''; ?>">
                        <label class="<?php echo (!$wdrpro_is_enabled_rtl) ? 'toggle' : ''; ?>">
                            <input class="<?php echo (!$wdrpro_is_enabled_rtl) ? 'toggle__input' : ''; ?> awdr-bogo-recurcive" type="checkbox" style="<?php echo ($wdrpro_is_enabled_rtl) ? 'height: 18px !important;' : ''; ?>"
                                   name="buyx_getx_adjustments[ranges][<?php echo esc_attr($wdrpro_buyx_getx_index); ?>][recursive]"
                                   data-recursive-row="buyx_getx_individual_range"
                                   data-recursive-parent="awdr-buyx-getx-recursive"
                                   data-hide-add-range="hide_getx_recursive"
                                   data-bogo-max-range="awdr-buyx-getx-max"
                                   data-bogo-min-range="awdr-buyx-getx-min"
                                   data-bogo-border="buyx_getx_individual_range"
                                   data-ranges-row-parent="awdr_bogo_main"
                                   value="1" <?php echo (isset($wdrpro_buyx_getx_adjustment->recursive) && !empty($wdrpro_buyx_getx_adjustment->recursive)) ? 'checked' : ''; ?>>
                            <span class="<?php echo (!$wdrpro_is_enabled_rtl) ? 'toggle__label' : ''; ?>"><span
                                class="<?php echo (!$wdrpro_is_enabled_rtl) ? 'toggle__text' : ''; ?>"><?php esc_html_e('Recursive?', 'woo-discount-rules-pro'); ?></span></span>
                        </label>
                    </div>
                </div>
                <div class="wdr-btn-remove" style="vertical-align: middle;">
                    <span class="dashicons dashicons-no-alt wdr_discount_remove" data-rmdiv="bulk_range_group"></span>
                </div>
            </div>
        </div>
    </div>
</div>