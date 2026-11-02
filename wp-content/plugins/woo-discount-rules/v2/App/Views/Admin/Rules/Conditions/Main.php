<?php

use Wdr\App\Helpers\Helper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
$awdr_is_pro = \Wdr\App\Helpers\Helper::hasPro();
?>
<div class="wdr-rule-menu">
    <h2><?php esc_html_e('Rules (Optional)', 'woo-discount-rules'); ?> - <span><a href="https://docs.flycart.org/en/articles/3834240-conditions-rules?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=rule_condition" target="_blank" style="font-size: 12px;"><?php esc_html_e('Read Docs', 'woo-discount-rules'); ?></a></span></h2>
    <div class="awdr-rules-content">
        <?php echo wp_kses_post(Helper::ruleConditionDescription());?>
    </div>
</div>
<div class="wdr-rule-options-con"><?php
    if ($awdr_conditions = $rule->getConditions()) {
        $awdr_condition_relationship = $rule->getRelationship('condition', 'and');
        $awdr_product_conditions = $base->getProductConditionsTypes();
        $awdr_discount_type = $rule->getRuleDiscountType();?>
        <!--Product Condition Start  promo_show_hide_-->
        <div class="wdr-condition-template">
        <div class="wdr-block">
            <div class="wdr-conditions-relationship">
                <label><b><?php esc_html_e('Conditions Relationship ', 'woo-discount-rules'); ?></b></label>&nbsp;&nbsp;&nbsp;&nbsp;
                <label><input type="radio" name="additional[condition_relationship]"
                              value="and" <?php echo ($awdr_condition_relationship == 'and') ? 'checked' : '' ?>
                    ><?php esc_html_e('Match All', 'woo-discount-rules'); ?></label>
                <label><input type="radio" name="additional[condition_relationship]"
                              value="or" <?php echo ($awdr_condition_relationship == 'or') ? 'checked' : '' ?>><?php esc_html_e('Match Any', 'woo-discount-rules'); ?>
                </label>
            </div>
            <div class="wdr-condition-group-items">
                <div class="wdr-conditions-container wdr-condition-group" data-index="1"></div><?php
                $i = 2;//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared template variable, read by pro add-on condition templates
                $render_saved_condition = false;//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared template variable, read by pro add-on condition templates
                foreach ($awdr_conditions as $awdr_condition) {
                    $type = isset($awdr_condition->type) ? $awdr_condition->type : NULL;
                    $custom_taxonomy_type_on_edit = $type;//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared template variable, read by pro add-on condition templates
                    if($awdr_discount_type != 'wdr_free_shipping' && $type == 'cart_item_product_onsale'){
                        continue;
                    }
                    if (!empty($type) && isset($rule->available_conditions[$type]['object'])) {
                        $awdr_template = $rule->available_conditions[$type]['template'];
                        $awdr_extra_params = isset($rule->available_conditions[$type]['extra_params']) ? $rule->available_conditions[$type]['extra_params'] : array();
                        if (file_exists($awdr_template)) {
                            $options = isset($awdr_condition->options) ? $awdr_condition->options : array();//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared template variable, read by pro add-on condition templates ?>
                            <div class="wdr-grid wdr-conditions-container wdr-condition-group" data-index="<?php echo esc_attr($i); ?>">
                                <div class="wdr-condition-type">
                                    <select name="conditions[<?php echo esc_attr($i); ?>][type]"
                                            class="wdr-product-condition-type awdr-left-align"
                                            style="width: 100%"><?php
                                        if (isset($awdr_product_conditions) && !empty($awdr_product_conditions)) {
                                            foreach ($awdr_product_conditions as $awdr_condition_key => $awdr_condition_value) {
                                                ?>
                                                <optgroup
                                                label="<?php esc_html_e($awdr_condition_key, 'woo-discount-rules');//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?>"><?php
                                                foreach ($awdr_condition_value as $awdr_key => $awdr_value) {?>
                                                    <option class="<?php echo ($awdr_discount_type != 'wdr_free_shipping' && $awdr_key == 'cart_item_product_onsale') ? 'wdr-hide awdr-free-shipping-special-condition' : 'awdr-free-shipping-special-condition'; ?>"
                                                    <?php
                                                    if(isset($awdr_value['enable']) && $awdr_value['enable'] === false){
                                                        ?>
                                                        disabled="disabled"
                                                        <?php
                                                    } else {
                                                        ?>
                                                        value="<?php echo esc_attr($awdr_key); ?>"
                                                        <?php
                                                    }
                                                    ?>
                                                    <?php if ($awdr_key == $type) {
                                                        echo 'selected';
                                                    } ?>><?php esc_html_e($awdr_value['label'], 'woo-discount-rules');//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></option><?php
                                                } ?>
                                                </optgroup><?php
                                            }
                                        } ?>
                                    </select>
                                    <span class="wdr_desc_text awdr-clear-both"><?php esc_html_e('Condition Type', 'woo-discount-rules'); ?></span>
                                </div><?php
                                extract($awdr_extra_params);
                                $render_saved_condition = true;//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared template variable, read by pro add-on condition templates
                                include $awdr_template;
                                $custom_taxonomy_type_on_edit = null;//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared template variable, read by pro add-on condition templates

                                ?>
                                <div class="wdr-btn-remove" style="float: left">
                                    <span class="dashicons dashicons-no-alt remove-current-row"></span>
                                </div>
                            </div><?php
                            $awdr_config = new \Wdr\App\Controllers\Configuration();
                            $awdr_subtotal_promo = $awdr_config->getConfig("show_subtotal_promotion", '');
                            $awdr_cart_quantity_promo = $awdr_config->getConfig("show_cart_quantity_promotion", '');
                            $awdr_type_promotion = isset($awdr_condition->type) ? $awdr_condition->type : NULL;
                            if($awdr_type_promotion == 'cart_subtotal' && $awdr_subtotal_promo == 1){
                                $awdr_operator = isset($options->operator) ? $options->operator : 'greater_than_or_equal';?>
                                <div class="wdr-grid wdr-conditions-container wdr-condition-group <?php echo 'promo_show_hide_'.esc_attr($i); ?>" data-index="<?php echo esc_attr($i); ?>" style="<?php echo ($awdr_operator == 'greater_than_or_equal' || $awdr_operator == 'greater_than') ? '': 'display: none'; ?>">
                                    <?php include(WDR_PLUGIN_PATH . 'App/Views/Admin/Rules/Others/SubtotalPromotion.php'); ?>
                                </div>
                               <?php
                            }else if($awdr_type_promotion == 'cart_items_quantity' && $awdr_cart_quantity_promo == 1 && $awdr_is_pro){
                                $awdr_operator = isset($options->operator) ? $options->operator : 'greater_than_or_equal';?>
                                <div class="wdr-grid wdr-conditions-container wdr-condition-group <?php echo 'promo_show_hide_'.esc_attr($i); ?>" data-index="<?php echo esc_attr($i); ?>" style="<?php echo ($awdr_operator == 'greater_than_or_equal' || $awdr_operator == 'greater_than') ? '': 'display: none'; ?>">
                                    <?php include(WDR_PLUGIN_PATH . 'App/Views/Admin/Rules/Others/QuantityPromotion.php'); ?>
                                </div>
                                <?php
                            }
                            $i++;
                        }
                    }
                    $custom_taxonomy_type_on_edit = null;//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared template variable, read by pro add-on condition templates
                } ?>
            </div>
            <div class="add-condition add-condition-and-filters">
                <button type="button"
                        class="button add-product-condition"><?php esc_html_e('Add condition', 'woo-discount-rules'); ?></button>
            </div>
        </div>
        </div><?php
    } else {?>
        <div class="wdr-condition-template">
            <div class="wdr-block">
                <div class="wdr-conditions-relationship">
                    <label><b><?php esc_html_e('Conditions Relationship', 'woo-discount-rules'); ?></b></label>&nbsp;&nbsp;&nbsp;&nbsp;
                    <label><input type="radio" name="additional[condition_relationship]"
                                  value="and" checked><?php esc_html_e('Match All', 'woo-discount-rules'); ?></label>
                    <label><input type="radio" name="additional[condition_relationship]"
                                  value="or"><?php esc_html_e('Match Any', 'woo-discount-rules'); ?>
                    </label>
                </div>
                <div class="wdr-condition-group-items">
                    <div class="wdr-conditions-container wdr-condition-group" data-index="1"></div>
                </div>
                <div class="wdp-block add-condition">
                    <button type="button"
                            class="button add-product-condition"><?php esc_html_e('Add condition', 'woo-discount-rules'); ?></button>
                </div>
            </div>
        </div>
    <?php } ?>
    <!--Product Condition End-->
    <!--Rule Limit Start-->
    <div class="wdr-condition-template">
        <div class="wdr-block">
            <div class="wdr-conditions-relationship"><?php
                $awdr_usage_limits = $rule->getUsageLimits();
                $awdr_used_limits = $rule->getUsedLimits(); ?>
                <label><b><?php esc_html_e('Rule Limits', 'woo-discount-rules'); ?></b>
                    <span class="awdr-rule-limit-timestamp"><?php
                        if(!empty($awdr_current_time)){
                            /* translators: %s used to display current server date and time */
	                        echo wp_kses_post(sprintf(__('Current server date and time: %s', 'woo-discount-rules'), '<b>' . gmdate('Y-m-d H:i', $awdr_current_time) . '</b>'));
                        }  ?>
                    </span>
                    <span class="awdr-rule-limit-timestamp "> <?php
                        esc_html_e('Rule Used: ', 'woo-discount-rules');
                        echo "<b class='awdr-used-limit-total'>". esc_html($awdr_used_limits) ."</b>"; ?>
                    </span>
                </label>

            </div>
            <div class="awdr-general-settings-section">
                <div class="wdr-rule-setting">
                    <div class="wdr-apply-to" style="float:left;">

                        <input type="number" name="usage_limits" value="<?php echo (!empty($awdr_usage_limits)) ? esc_attr($awdr_usage_limits) : '';?>" min="1" class="wdr-title number_only_field" id="select_usage_limits" placeholder="Unlimited">

                        <span class="wdr_desc_text"><?php esc_html_e('Maximum usage limit', 'woo-discount-rules'); ?></span>
                    </div>
                    <div class="wdr-rule-date-valid">
                        <div class="wdr-dateandtime-value">
                            <input type="text"
                                   name="date_from"
                                   class="wdr-condition-date wdr-title"
                                   data-class="start_datetimeonly"
                                   placeholder="<?php esc_attr_e('Rule Vaild From', 'woo-discount-rules'); ?>"
                                   data-field="date"
                                   autocomplete="off"
                                   id="rule_datetime_from"
                                   value="<?php echo esc_attr($rule->getStartDate(false, 'Y-m-d H:i')); ?>">
                            <span class="wdr_desc_text"><?php esc_html_e('Vaild from', 'woo-discount-rules'); ?></span>
                        </div>
                        <div class="wdr-dateandtime-value">
                            <input type="text"
                                   name="date_to"
                                   class="wdr-condition-date wdr-title"
                                   data-class="end_datetimeonly"
                                   placeholder="<?php esc_attr_e('Rule Valid To', 'woo-discount-rules'); ?>"
                                   data-field="date" autocomplete="off"
                                   id="rule_datetime_to"
                                   value="<?php echo esc_attr($rule->getEndDate(false, 'Y-m-d H:i')); ?>">
                            <span class="wdr_desc_text"><?php esc_html_e('Vaild to', 'woo-discount-rules'); ?></span>
                        </div>
                    </div>
                    <?php
                    if (!empty($site_languages) && is_array($site_languages) && count($site_languages) > 1) {
                        ?>
                        <div class="wdr-language-value">
                            <select multiple
                                    class="edit-preloaded-values"
                                    data-list="site_languages"
                                    data-field="preloaded"
                                    data-placeholder="<?php esc_attr_e('Select values', 'woo-discount-rules') ?>"
                                    name="rule_language[]"><?php
                                $awdr_chosen_languages = $rule->getLanguages();
                                foreach ($site_languages as $awdr_language_key => $awdr_name) {
                                    if (in_array($awdr_language_key, $awdr_chosen_languages)) {
                                        ?>
                                        <option value="<?php echo esc_attr($awdr_language_key); ?>"
                                                selected><?php echo esc_html($awdr_name); ?></option>
                                        <?php
                                    }
                                }
                                ?>
                            </select>
                            <span class="wdr_desc_text"><?php esc_html_e('Language', 'woo-discount-rules'); ?></span>
                        </div>
                        <?php
                    } ?>
                </div>
            </div>
        </div>
    </div>
    <!--Rule Limit End-->
</div>