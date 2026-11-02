<?php
    if (!defined('ABSPATH')) exit; // Exit if accessed directly
?>
<div style="overflow:auto">
    <div class="awdr-container"><br/>
        <?php
        if(isset($wdr_404_found) && !empty($wdr_404_found)){
            echo "<h2 style='color: red;'>" . esc_html($wdr_404_found) . "</h2>";
        }else{
            $awdr_current_time = '';
            if (function_exists('current_time')) {
                $awdr_current_time = current_time('timestamp');
            }
            $awdr_rule_status = $rule->getRuleVaildStatus();
            $awdr_check_rule_limit = $rule->checkRuleUsageLimits();
            $awdr_rule_id = $rule->getId();
            if ($awdr_rule_status == 'in_future') { ?>
                <div class="notice inline notice-warning notice-alt awdr-rule-limit-disabled">
                    <p class="rule_limit_msg_future">
                        <b><?php esc_html_e('This rule is not running currently: ', 'woo-discount-rules'); ?></b><?php esc_html_e(' Start date and time is set in the future date', 'woo-discount-rules'); ?>
                    </p><?php
                    if ($awdr_check_rule_limit == 'Disabled') {?>
                        <p class="rule_limit_msg">
                            <b><?php esc_html_e('This rule is not running currently: ', 'woo-discount-rules'); ?></b><?php esc_html_e(' Rule reached maximum usage limit  ', 'woo-discount-rules'); ?>
                        </p><?php
                    } ?>
                </div><?php
            } elseif ($awdr_rule_status == 'expired') {
                ?>
                <div class="notice inline notice-warning notice-alt awdr-rule-limit-disabled">
                    <p class="rule_limit_msg_expired">
                        <b><?php esc_html_e('This rule is not running currently: ', 'woo-discount-rules'); ?></b><?php esc_html_e(' Validity expired', 'woo-discount-rules'); ?>
                    </p><?php
                    if ($awdr_check_rule_limit == 'Disabled') {?>
                        <p class="rule_limit_msg">
                            <b><?php esc_html_e('This rule is not running currently: ', 'woo-discount-rules'); ?></b><?php esc_html_e(' Rule reached maximum usage limit  ', 'woo-discount-rules'); ?>
                        </p><?php
                    } ?>
                </div><?php
            }else{
                if($awdr_check_rule_limit == 'Disabled') {?>
                    <div class="notice inline notice-warning notice-alt awdr-rule-limit-disabled">
                        <p class="rule_limit_msg">
                            <b><?php esc_html_e('This rule is not running currently: ', 'woo-discount-rules'); ?></b><?php esc_html_e(' Rule reached maximum usage limit  ', 'woo-discount-rules'); ?>
                        </p>
                    </div><?php
                }
            }?>
            <?php
                /* @since 2.3.11 */
                $awdr_notices = apply_filters('advanced_woo_discount_rules_admin_rule_notices', array(), $rule, $awdr_rule_status);
                if (!empty($awdr_notices) && is_array($awdr_notices)) {
                    foreach ($awdr_notices as $awdr_notice) {
                        $awdr_notice_status = 'warning';
                        $awdr_notice_message = $awdr_notice_title = '';
                        if (!empty($awdr_notice)) {
                            if (is_array($awdr_notice)) {
                                $awdr_notice_title = isset($awdr_notice['title']) ? $awdr_notice['title'] : $awdr_notice_title;
                                $awdr_notice_status = isset($awdr_notice['status']) ? $awdr_notice['status'] : $awdr_notice_status;
                                $awdr_notice_message = isset($awdr_notice['message']) ? $awdr_notice['message'] : $awdr_notice_message;
                            } else {
                                $awdr_notice_message = $awdr_notice;
                            }
                            if (!empty($awdr_notice_message)) {
                                ?>
                                    <div class="notice inline notice-<?php echo esc_attr($awdr_notice_status); ?> notice-alt awdr-rule-notices">
                                        <p class="rule-notice">
                                            <?php
                                                if (!empty($awdr_notice_title)) {
                                                    echo '<b>' . esc_html($awdr_notice_title) . ':</b> ';
                                                }
                                                echo esc_html($awdr_notice_message);
                                            ?>
                                        </p>
                                    </div>
                                <?php
                            }
                        }
                    }
                }
            ?>
            <div class="notice inline notice-warning notice-alt awdr-rule-limit-disabled-outer" style="display: none; padding: 10px;">
                <p class="rule_limit_msg_outer"></p>
            </div>
                <form id="wdr-save-rule" name="rule_generator">
                <div class="wdr-sticky-header" id="ruleHeader">
                    <div class="wdr-enable-rule">
                        <div class="wdr-field-title" style="width: 45%">
                            <input class="wdr-title" type="text" name="title" placeholder="<?php esc_attr_e('Rule Title', 'woo-discount-rules'); ?>"
                                   value="<?php echo esc_attr($rule->getTitle()); ?>"><!--awdr-clear-both-->
                        </div><?php
                        $awdr_is_rtl_enabled = \Wdr\App\Helpers\Woocommerce::isRTLEnable();
                        if(!$awdr_is_rtl_enabled){?>
                            <div class="page__toggle">
                                <label class="toggle">
                                    <input class="toggle__input" type="checkbox"
                                           name="enabled" <?php echo ($rule->isEnabled()) ? 'checked' : '' ?> value="1">
                                    <span class="toggle__label"><span
                                                class="toggle__text"><?php esc_html_e('Enable?', 'woo-discount-rules'); ?></span></span>
                                </label>

                            </div>
                            <div class="page__toggle">
                                <label class="toggle">
                                    <input class="toggle__input" type="checkbox"
                                           name="exclusive" <?php echo ($rule->isExclusive()) ? 'checked' : '' ?> value="1">
                                    <span class="toggle__label"><span
                                                class="toggle__text"><?php esc_html_e('Apply this rule if matched and ignore all other rules', 'woo-discount-rules'); ?></span></span>
                                </label>

                            </div><?php
                        }else{?>
                            <div class="awdr_normal_enable_check_box">
                                <label>
                                    <input type="checkbox" name="enabled" class="awdr_enable_check_box_html" <?php echo ($rule->isEnabled()) ? 'checked' : '' ?> value="1"><?php esc_html_e('Enable?', 'woo-discount-rules'); ?>
                                </label>

                            </div>
                            <div class="awdr_normal_exclusive_check_box">
                                <label>
                                    <input class="awdr_exclusive_check_box_html" type="checkbox"name="exclusive" <?php echo ($rule->isExclusive()) ? 'checked' : '' ?> value="1">
                                    <?php esc_html_e('Apply this rule if matched and ignore all other rules', 'woo-discount-rules'); ?>
                                </label>
                            </div><?php
                        }

                        if (isset($awdr_rule_id) && !empty($awdr_rule_id)) { ?>
                            <span class="wdr_desc_text awdr_valide_date_in_desc">
                            <?php esc_html_e('#Rule ID: ', 'woo-discount-rules'); ?><b><?php echo esc_html($awdr_rule_id); ?></b>
                            </span><?php
                        } ?>
                        <input type="hidden" name="current_page" value="<?php echo esc_attr($current_page); ?>">
                        <div class="awdr-common-save">
                            <button type="submit" class="btn btn-primary wdr_save_stay">
                                <?php esc_html_e('Save', 'woo-discount-rules'); ?></button>
                            <button type="button" class="btn btn-success wdr_save_close">
                                <?php esc_html_e('Save & Close', 'woo-discount-rules'); ?></button>
                            <a href="<?php echo esc_url(admin_url("admin.php?" . http_build_query(array('page' => WDR_SLUG, 'tab' => 'rules', 'page_no' => $current_page)))); ?>"
                               class="btn btn-danger" style="text-decoration: none">
                                <?php esc_html_e('Cancel', 'woo-discount-rules'); ?></a>
                        </div>
                    </div>
                    <div class="awdr_discount_type_section">
                        <?php
                        $awdr_product_discount_types = $base->getDiscountTypes();
                        $awdr_rule_discount_type = $rule->getRuleDiscountType();
                        ?>
                        <div class="wdr-discount-type">
                            <b style="display: block;"><?php esc_html_e('Choose a discount type', 'woo-discount-rules'); ?></b>
                            <select name="discount_type" class="awdr-product-discount-type wdr-discount-type-selector"
                                    data-placement="wdr-discount-template-placement">
                                <optgroup label="">
                                    <option value="not_selected"><?php esc_html_e("Select Discount Type", 'woo-discount-rules'); ?></option>
                                </optgroup><?php
                                if (isset($awdr_product_discount_types) && !empty($awdr_product_discount_types)) {
                                    foreach ($awdr_product_discount_types as $awdr_discount_key => $awdr_discount_value) {
                                        ?>
                                    <optgroup label="<?php echo esc_attr($awdr_discount_key); ?>">
                                        <?php
                                        foreach ($awdr_discount_value as $awdr_key => $awdr_value) {
                                            $awdr_enable_option = true;
                                            if (isset($awdr_value['enable']) && $awdr_value['enable'] === false) {
                                                $awdr_enable_option = false;
                                            }
                                            ?>
                                            <option
                                            <?php if ($awdr_enable_option) {
                                                ?>
                                                value="<?php echo esc_attr($awdr_key); ?>"
                                                <?php
                                            } else {
                                                ?>
                                                disabled="disabled"
                                                <?php
                                            } ?>
                                            <?php echo ($awdr_rule_discount_type && $awdr_rule_discount_type == $awdr_key) ? 'selected' : ''; ?>><?php esc_html_e($awdr_value['label'], 'woo-discount-rules'); ?></option><?php // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
                                        } ?>
                                        </optgroup><?php
                                    }
                                } ?>
                            </select>
                            <sub><a href="https://docs.flycart.org/en/articles/3788550-product-adjustment?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=product_adjustment_document" target="_blank" class="awdr_doc_wdr_simple_discount" style="<?php echo ($awdr_rule_discount_type != 'wdr_simple_discount') ? 'display: none' : '';?>"><?php esc_html_e("Read Docs", 'woo-discount-rules'); ?></a></sub>
                            <sub><a href="https://docs.flycart.org/en/articles/3806593-cart-adjustment?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=cart_adjustment_document" target="_blank" class="awdr_doc_wdr_cart_discount" style="<?php echo ($awdr_rule_discount_type != 'wdr_cart_discount') ? 'display: none' : '';?>"><?php esc_html_e("Read Docs", 'woo-discount-rules'); ?></a></sub>
                            <sub><a href="https://docs.flycart.org/en/articles/3807036-free-shipping?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=free_shipping_document" target="_blank" class="awdr_doc_wdr_free_shipping" style="<?php echo ($awdr_rule_discount_type != 'wdr_free_shipping') ? 'display: none' : '';?>"><?php esc_html_e("Read Docs", 'woo-discount-rules'); ?></a></sub>
                            <sub><a href="https://docs.flycart.org/en/articles/3807208-bulk-discounts-or-tiered-pricing?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=bulk_adjustment_document" target="_blank" class="awdr_doc_wdr_bulk_discount" style="<?php echo ($awdr_rule_discount_type != 'wdr_bulk_discount') ? 'display: none' : '';?>"><?php esc_html_e("Read Docs", 'woo-discount-rules'); ?></a></sub>
                            <sub><a href="https://docs.flycart.org/en/articles/3809899-bundle-set-discount?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=set_bundle_adjustment_document" target="_blank" class="awdr_doc_wdr_set_discount" style="<?php echo ($awdr_rule_discount_type != 'wdr_set_discount') ? 'display: none' : '';?>"><?php esc_html_e("Read Docs", 'woo-discount-rules'); ?></a></sub>
                            <sub><a href="https://docs.flycart.org/en/articles/3810071-buy-one-get-one-free-buy-x-get-x?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=bxgx_adjustment" target="_blank" class="awdr_doc_wdr_buy_x_get_x_discount" style="<?php echo ($awdr_rule_discount_type != 'wdr_buy_x_get_x_discount') ? 'display: none' : '';?>"><?php esc_html_e("Read Docs", 'woo-discount-rules'); ?></a></sub>
                            <sub><a href="https://docs.flycart.org/en/articles/3810570-buy-x-get-y?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=bxgy_adjustment_document" target="_blank" class="awdr_doc_wdr_buy_x_get_y_discount" style="<?php echo ($awdr_rule_discount_type != 'wdr_buy_x_get_y_discount') ? 'display: none' : '';?>"><?php esc_html_e("Read Docs", 'woo-discount-rules'); ?></a></sub>
                        </div>
                    </div>
                </div>
                <div class="awdr-hidden-new-rule" style="<?php echo (is_null($awdr_rule_id)) ? "display:none;" : "" ?>">

                    <!-- ------------------------Rule Filter Section Start------------------------ -->
                    <div class="wdr-rule-filters-and-options-con awdr-filter-section">
                        <div class="wdr-rule-menu">
                            <h2 class="awdr-filter-heading"><?php esc_html_e("Filter", 'woo-discount-rules'); ?></h2>
                           <div class="awdr-filter-content">
                               <p><?php esc_html_e("Choose which <b>gets</b> discount (products/categories/attributes/SKU and so on )", 'woo-discount-rules'); ?></p>
                               <p><?php esc_html_e("Note : You can also exclude products/categories.", 'woo-discount-rules'); ?></p>
                           </div>
                        </div>
                        <div class="wdr-rule-options-con">
                            <div id="wdr-save-rule" name="rule_generator">
                                <input type="hidden" name="action" value="wdr_ajax">
                                <input type="hidden" name="method" value="save_rule">
                                <input type="hidden" name="awdr_nonce" value="<?php echo esc_attr(\Wdr\App\Helpers\Helper::create_nonce('wdr_ajax_save_rule')); ?>">
                                <input type="hidden" name="wdr_save_close" value="">
                                <div id="rule_template">
                                    <?php include 'Filters/Main.php'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- ------------------------Rule Filter Section End-------------------------- -->

                    <!-- ------------------------Rule Discount Section Start---------------------- -->
                    <?php
                    //product adjustments
                    $awdr_product_adjustments = ($rule->getProductAdjustments()) ? $rule->getProductAdjustments() : false;

                    //cart adjustments
                    $awdr_cart_adjustment = $rule->getCartAdjustments();
                    //Bulk adjustments
                    if ($awdr_get_bulk_adjustments = $rule->getBulkAdjustments()) {
                        $awdr_bulk_adj_operator = (isset($awdr_get_bulk_adjustments->operator) && !empty($awdr_get_bulk_adjustments->operator)) ? $awdr_get_bulk_adjustments->operator : 'product_cumulative';
                        $awdr_bulk_adj_as_cart = (isset($awdr_get_bulk_adjustments->apply_as_cart_rule) && !empty($awdr_get_bulk_adjustments->apply_as_cart_rule)) ? $awdr_get_bulk_adjustments->apply_as_cart_rule : '';
                        $awdr_bulk_adj_as_cart_label = (isset($awdr_get_bulk_adjustments->cart_label) && !empty($awdr_get_bulk_adjustments->cart_label)) ? $awdr_get_bulk_adjustments->cart_label : '';
                        $awdr_bulk_adj_ranges = (isset($awdr_get_bulk_adjustments->ranges) && !empty($awdr_get_bulk_adjustments->ranges)) ? $awdr_get_bulk_adjustments->ranges : false;
                        $awdr_bulk_cat_selector = (isset($awdr_get_bulk_adjustments->selected_categories) && !empty($awdr_get_bulk_adjustments->selected_categories)) ? $awdr_get_bulk_adjustments->selected_categories : false;
                    } else {
                        $awdr_bulk_adj_operator = 'product_cumulative';
                        $awdr_bulk_adj_as_cart = '';
                        $awdr_bulk_adj_as_cart_label = '';
                        $awdr_bulk_adj_ranges = false;
                        $awdr_bulk_cat_selector = false;
                    }
                    $awdr_show_bulk_discount = $rule->showHideDiscount($awdr_bulk_adj_ranges); ?>
                    <div class="awdr-discount-container">
                        <div class="awdr-discount-row">
                            <div class="wdr-rule-filters-and-options-con">
                                <div class="wdr-rule-menu">
                                    <h2 class="awdr-discount-heading"><?php esc_html_e("Discount", 'woo-discount-rules'); ?></h2>
                                    <div class="awdr-discount-content">
                                        <p><?php esc_html_e("Select discount type and its value (percentage/price/fixed price)", 'woo-discount-rules'); ?></p>
                                    </div>
                                </div>
                                <div class="wdr-rule-options-con">
                                    <div class="wdr-discount-template">
                                        <div class="wdr-block wdr-discount-template-placement">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- ------------------------Rule Discount Section End------------------------ -->

                    <!-- ------------------------Rule Condition Section Start--------------------- -->
                    <div class="awdr-condition-container">
                        <div class="awdr-condition-row">
                            <div class="wdr-rule-filters-and-options-con">
                                <?php include 'Conditions/Main.php'; ?>
                            </div>
                        </div>
                    </div>
                    <!-- ------------------------Rule Condition Section End----------------------- -->


                    <!-- ------------------------Rule Discount Batch Section Start---------------- -->
                    <?php
                    if ($rule->hasAdvancedDiscountMessage()) {
                        $awdr_badge_display = $rule->getAdvancedDiscountMessage('display', 0);
                        $awdr_badge_bg_color = $rule->getAdvancedDiscountMessage('badge_color_picker', '#ffffff');
                        $awdr_badge_text_color = $rule->getAdvancedDiscountMessage('badge_text_color_picker', '#000000');
                        $awdr_badge_text = $rule->getAdvancedDiscountMessage('badge_text');
                    } else {
                        $awdr_badge_display = false;
                        $awdr_badge_bg_color = '#ffffff';
                        $awdr_badge_text_color = '#000000';
                        $awdr_badge_text = false;
                    }
                    ?>
                    <?php include 'DiscountBatch/Main.php'; ?>
                    <!-- ------------------------Rule Discount Batch Section End------------------ -->

                </div>
                <input type="hidden" name="wdr_ajax_select2" value="<?php echo esc_attr(\Wdr\App\Helpers\Helper::create_nonce('wdr_ajax_select2')); ?>">
                </form><?php

        }?>
    </div>
</div>
<?php include 'Discounts/Main.php'; ?>
<div class="awdr-default-template" style="display: none;">
    <?php
    do_action('advanced_woo_discount_rules_admin_after_load_rule_fields', $rule);
    $awdr_discount_types = $base->discountElements();
    //$i = '{i}';
    foreach ($awdr_discount_types as $type => $awdr_discount_type) {
        (isset($awdr_discount_type['template']) && !empty($awdr_discount_type['template'])) ? include $awdr_discount_type['template'] : '';
    }
    include "Others/CommonTemplates.php";?>
</div>


