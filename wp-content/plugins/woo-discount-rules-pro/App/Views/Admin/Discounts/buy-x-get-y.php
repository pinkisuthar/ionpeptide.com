<?php
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Helpers\Helper;

$wdrpro_buyx_gety_adjustment = null;
?>
<!-- Buy X Get Y Start -->
<div class="add_buyx_gety_range" style="display:none;">
    <?php
    $wdrpro_buyx_gety_index = "{i}";
    include 'buy-x-get-y-range.php';
    $wdrpro_filter_set_above_tip = $wdrpro_individual_product_tip = $wdrpro_variants_together_tip = $wdrpro_default_description_tip = '';
   if(class_exists('\Wdr\App\Helpers\Helper')) {
       if(method_exists('\Wdr\App\Helpers\Helper', 'bogoToolTipDescriptionForFilterTogether')){
           $wdrpro_filter_set_above_tip = Helper::bogoToolTipDescriptionForFilterTogether();
       }
       if(method_exists('\Wdr\App\Helpers\Helper', 'bogoToolTipDescriptionForIndividualProduct')){
           $wdrpro_individual_product_tip = Helper::bogoToolTipDescriptionForIndividualProduct();
       }
       if(method_exists('\Wdr\App\Helpers\Helper', 'bogoToolTipDescriptionForvariants')){
           $wdrpro_variants_together_tip = Helper::bogoToolTipDescriptionForvariants();
       }
   }
    if($get_buyx_gety_operator == 'variation'){
        $wdrpro_default_description_tip = $wdrpro_variants_together_tip;
    }elseif ($get_buyx_gety_operator == 'product'){
        $wdrpro_default_description_tip = $wdrpro_individual_product_tip;
    }else{
        $wdrpro_default_description_tip = $wdrpro_filter_set_above_tip;
    }
    ?>
</div>

<div class="wdr_buy_x_get_y_discount" style="display:none;">
    <div class="wdr-discount-block">
        <div class="awdr-get-y-general-settings">
            <div class="buyx-gety-cumulative-option wdr-select-filed-hight">
                <select name="buyx_gety_adjustments[type]" class="awdr-left-align select_bxgy_type">
                    <option value="0"><?php esc_html_e('Select Types', 'woo-discount-rules-pro') ?></option>
                    <option value="bxgy_product" <?php if ($get_buyx_gety_types == 'bxgy_product') {
                        echo 'selected';
                    } ?>><?php esc_html_e('Buy X Get Y - Products ', 'woo-discount-rules-pro') ?></option>
                    <option value="bxgy_category" <?php if ($get_buyx_gety_types == 'bxgy_category') {
                        echo 'selected';
                    } ?>><?php esc_html_e('Buy X Get Y - Categories', 'woo-discount-rules-pro') ?></option>
                    <option value="bxgy_all" <?php if ($get_buyx_gety_types == 'bxgy_all') {
                        echo 'selected';
                    } ?>><?php esc_html_e('Buy X Get Y - All', 'woo-discount-rules-pro') ?></option>

                    <?php do_action('advanced_woo_discount_rules_after_bxgy_discount_type_options', $get_buyx_gety_types); ?>

                </select>
                <span class="wdr_desc_text awdr-clear-both"><?php esc_html_e('Get Y Discount Type ', 'woo-discount-rules-pro'); ?></span>
            </div>
            <div class="buyx-gety-cumulative-option wdr-select-filed-hight bxgy_type_selected" style="padding-left:5px; <?php echo (is_null($get_buyx_gety_types) || $get_buyx_gety_types == '')? 'display: none;': ''?>">
                <select name="buyx_gety_adjustments[operator]" class="awdr-left-align awdr_mode_of_operator">
                    <option value="product_cumulative" title="<?php echo esc_attr($wdrpro_filter_set_above_tip);?>" <?php if ($get_buyx_gety_operator == 'product_cumulative') {
                        echo 'selected';
                        } ?>><?php esc_html_e('Filters set above', 'woo-discount-rules-pro') ?></option>
                    <option value="product" title="<?php echo esc_attr($wdrpro_individual_product_tip);?>" <?php if ($get_buyx_gety_operator == 'product') {
                        echo 'selected';
                    } ?>><?php esc_html_e('Individual Product', 'woo-discount-rules-pro') ?></option>
                    <option value="variation" title="<?php echo esc_attr($wdrpro_variants_together_tip);?>" <?php if ($get_buyx_gety_operator == 'variation') {
                        echo 'selected';
                    } ?>><?php esc_html_e('All variants in each product together', 'woo-discount-rules-pro') ?></option>
                </select>

                <span class="wdr_desc_text awdr-clear-both"><?php esc_html_e('Buy X Count Based on ', 'woo-discount-rules-pro'); ?>  <span style="" class="woocommerce-help-tip awdr-bxgy-dynamic-tip" title="<?php echo esc_attr($wdrpro_default_description_tip);?>"></span></span>

            </div>
            <div class="awdr-example" <!--style="width: min-content"-->></div>
            <div class="awdr-get-y-middle-section">
                <?php

                $wdrpro_is_enable_rtl = \WDRPro\App\Helpers\CoreMethodCheck::isRTLEnable();;
                $wdrpro_rtl_compatibility_p_start = ($wdrpro_is_enable_rtl) ? "<p>" : '';
                $wdrpro_rtl_compatibility_p_end = ($wdrpro_is_enable_rtl) ? "</p>" : '';
                ?>
                <div class="awdr-buyx-gety-cumulative-radio-mode wdr-select-filed-hight bxgy_type_selected" style="<?php echo (is_null($get_buyx_gety_types) || $get_buyx_gety_types == '')? 'display: none;': ''?>">
                    <?php echo wp_kses_post($wdrpro_rtl_compatibility_p_start);?><label class="auto_add" style="<?php echo ($get_buyx_gety_types == 'bxgy_category' || $get_buyx_gety_types == "bxgy_all") ? 'display:none':'';?>"><input type="radio" name="buyx_gety_adjustments[mode]"  value="auto_add" <?php echo (($get_buyx_gety_mode == '' && $get_buyx_gety_types == 'bxgy_product') || $get_buyx_gety_mode == 'auto_add') ? 'checked="checked"' : '';?>><?php esc_html_e('Auto add', 'woo-discount-rules-pro'); ?></label><?php echo wp_kses_post($wdrpro_rtl_compatibility_p_end);?>
                    <?php echo wp_kses_post($wdrpro_rtl_compatibility_p_start);?><label class="cheapest"> <input type="radio" name="buyx_gety_adjustments[mode]"  value="cheapest" <?php echo ($get_buyx_gety_mode == 'cheapest') ? 'checked="checked"' : '';?>><?php esc_html_e('Cheapest', 'woo-discount-rules-pro'); ?></label><?php echo wp_kses_post($wdrpro_rtl_compatibility_p_end);?>
                    <?php echo wp_kses_post($wdrpro_rtl_compatibility_p_start);?><label class="highest"> <input type="radio" name="buyx_gety_adjustments[mode]"  value="highest" <?php echo ($get_buyx_gety_mode == 'highest') ? 'checked="checked"' : '';?>><?php esc_html_e('Highest', 'woo-discount-rules-pro'); ?></label><?php echo wp_kses_post($wdrpro_rtl_compatibility_p_end);?>
                </div>
                <span class="wdr_desc_text awdr-clear-both bxgy_type_selected"><?php esc_html_e('Mode of Apply', 'woo-discount-rules-pro'); ?></span>
            </div>
        </div>

        <div class="wdr-simple-discount-main wdr-buyx-gety-discount-main bxgy_type_selected" style="<?php echo (is_null($get_buyx_gety_types) || $get_buyx_gety_types == '')? 'display: none;': ''?>">
            <div class="awdr_buyx_gety_range_group awdr_bogo_main">
                <?php
                $wdrpro_buyx_gety_index = 1;
                if (isset($get_buyx_gety_adjustments) && !empty($get_buyx_gety_adjustments)) {
                    foreach ($get_buyx_gety_adjustments as $wdrpro_buyx_gety_adjustment) {
                        include 'buy-x-get-y-range.php';
                        $wdrpro_buyx_gety_index++;
                    }
                } else {
                    include 'buy-x-get-y-range.php';
                }
                ?>
            </div>
            <div class="add-condition-and-filters awdr-discount-add-row hide_gety_recursive" style="<?php echo (isset($wdrpro_buyx_gety_adjustment->recursive) && !empty($wdrpro_buyx_gety_adjustment->recursive)) ? 'display:none' : '';?>">
                <button type="button" class="button add_discount_elements"
                        data-discount-method="add_buyx_gety_range"
                        data-append="awdr_buyx_gety_range_setter"
                        data-next-starting-value=".buyx_gety_individual_range"><?php esc_html_e('Add Range', 'woo-discount-rules-pro') ?></button>
            </div>
        </div>
    </div>
</div>
<!-- Buy X Get Y End -->