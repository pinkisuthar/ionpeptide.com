    <?php
    if (!defined('ABSPATH')) {
        exit; // Exit if accessed directly
    }
    ?>
    <br>

    <div id="wpbody-content" class="awdr-container">
        <?php
        do_action('advanced_woo_discount_rules_on_settings_header');
        ?>
        <div class="awdr-configuration-form">
            <form name="configuration_form" id="configuration-form" method="post">

                <h1><?php esc_html_e('General', 'woo-discount-rules') ?></h1>
                <table class="wdr-general-setting form-table">
                    <tbody style="background-color: #fff;">
                    <?php
                    do_action('advanced_woo_discount_rules_before_general_settings_fields', $configuration);
                    ?>
                    <tr>
                        <td scope="row">
                            <label for="calculate_discount_from" class="awdr-left-align"><?php esc_html_e('Calculate discount from', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Choose which price should be taken to calculate the discount', 'woo-discount-rules');   ?> - <a href="https://docs.flycart.org/en/articles/4167066-discount-based-on-regular-price-sale-price?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=sale_regular_price_settings" target="_blank"><?php esc_html_e('Read Docs', 'woo-discount-rules'); ?></a></span>
                        </td>
                        <td>
                            <select name="calculate_discount_from">
                                <option value="sale_price" <?php echo ($configuration->getConfig('calculate_discount_from', 'sale_price') == 'sale_price') ? 'selected' : ''; ?>><?php esc_html_e('Sale price', 'woo-discount-rules');   ?></option>
                                <option value="regular_price" <?php echo ($configuration->getConfig('calculate_discount_from', 'sale_price') == 'regular_price') ? 'selected' : ''; ?> ><?php esc_html_e('Regular price', 'woo-discount-rules');   ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label for="apply_product_discount_to" class="awdr-left-align"><?php esc_html_e('Choose how the discount rules should apply', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Set the rule priority.', 'woo-discount-rules');   ?><br />
                               <p class="wdr_settings_desc_text text-warning">
                                <strong><?php esc_html_e('Note', 'woo-discount-rules'); ?></strong> :
                                <?php esc_html_e('This does not apply for the "free shipping" rule type.', 'woo-discount-rules'); ?>
                                </p>
                            </span>

                        </td>
                        <td>
                            <select name="apply_product_discount_to" class="apply_product_and_cart_discount_to" data-subsequent="apply_product_discount_subsequently_row">
                                <option value="biggest_discount" <?php echo ($configuration->getConfig('apply_product_discount_to', 'biggest_discount') == 'biggest_discount') ? 'selected' : ''; ?>><?php esc_html_e('Biggest one from matched rules', 'woo-discount-rules');   ?></option>
                                <option value="lowest_discount" <?php echo ($configuration->getConfig('apply_product_discount_to', 'biggest_discount') == 'lowest_discount') ? 'selected' : ''; ?>><?php esc_html_e('Lowest one from matched rules', 'woo-discount-rules');   ?></option>
                                <option value="first" <?php echo ($configuration->getConfig('apply_product_discount_to', 'biggest_discount') == 'first') ? 'selected' : ''; ?> ><?php esc_html_e('First matched rules', 'woo-discount-rules');   ?></option>
                                <option value="all" <?php echo ($configuration->getConfig('apply_product_discount_to', 'biggest_discount') == 'all') ? 'selected' : ''; ?>><?php esc_html_e('All matched rules', 'woo-discount-rules');   ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr class="apply_product_discount_subsequently_row" style="<?php echo ($configuration->getConfig('apply_product_discount_to', 'biggest_discount') != 'all') ? 'display:none' : ''; ?>">
                        <td scope="row">
                            <label for="awdr_subsequent_discount" class="awdr-left-align"><?php esc_html_e('Apply discount sequentially', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('This apply the discount rules in a sequential order.', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                            <input type="radio" name="apply_discount_subsequently" id="do_apply_discount_subsequently"
                                   value="1" <?php echo($configuration->getConfig('apply_discount_subsequently', 0) ? 'checked' : '') ?>><label
                                    for="do_apply_discount_subsequently"><?php esc_html_e('Yes', 'woo-discount-rules');   ?></label>
                            <input type="radio" name="apply_discount_subsequently"
                                   id="do_not_apply_discount_subsequently"
                                   value="0" <?php echo(!$configuration->getConfig('apply_discount_subsequently', 0) ? 'checked' : '') ?>><label
                                    for="do_not_apply_discount_subsequently"><?php esc_html_e('No', 'woo-discount-rules');   ?></label>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label for="disable_coupon_when_rule_applied" class="awdr-left-align"><?php esc_html_e('Choose discount rules behaviour with default WooCommerce Coupons', 'woo-discount-rules')   ?>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Choose how discount rules should work when WooCommerce coupons (or third party) coupons are used?', 'woo-discount-rules');   ?>
                            - <a href="https://docs.flycart.org/en/articles/4178875-choose-how-discount-rules-should-work-when-woocommerce-coupons-are-used?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=choose_how_discount_rules_works_setting" target="_blank"><?php esc_html_e('Read Docs', 'woo-discount-rules');   ?></a></label>
                            </span>

                        </td>
                        <td>
                            <?php
                            $awdr_disable_coupon_when_rule_applied = $configuration->getConfig('disable_coupon_when_rule_applied', 'run_both');
                            ?>
                            <select name="disable_coupon_when_rule_applied" class="disable_coupon_when_rule_applied">
                                <option value="run_both" <?php echo ($awdr_disable_coupon_when_rule_applied == 'run_both') ? 'selected' : ''; ?>><?php esc_html_e('Let both coupons and discount rules run together', 'woo-discount-rules');   ?></option>
                                <option value="disable_coupon" <?php echo ($awdr_disable_coupon_when_rule_applied == 'disable_coupon') ? 'selected' : ''; ?>><?php esc_html_e('Disable the coupons (discount rules will work)', 'woo-discount-rules');   ?></option>
                                <option value="disable_rules" <?php echo ($awdr_disable_coupon_when_rule_applied == 'disable_rules') ? 'selected' : ''; ?> ><?php esc_html_e('Disable the discount rules (coupons will work)', 'woo-discount-rules');   ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label class="awdr-left-align"><?php esc_html_e('Re-check discounts at the checkout page', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Useful when you have purchase history/shipping address based discount.', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                            <input type="radio" name="refresh_order_review" id="refresh_order_review_enable"
                                   value="1" <?php echo($configuration->getConfig('refresh_order_review', 0) ? 'checked' : '') ?>><label
                                    for="refresh_order_review_enable"><?php esc_html_e('Yes', 'woo-discount-rules');   ?></label>
                            <input type="radio" name="refresh_order_review"
                                   id="refresh_order_review_disable"
                                   value="0" <?php echo(!$configuration->getConfig('refresh_order_review', 0) ? 'checked' : '') ?>><label
                                    for="refresh_order_review_disable"><?php esc_html_e('No', 'woo-discount-rules');   ?></label>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label class="awdr-left-align"><?php esc_html_e('Suppress third party discount plugins', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Useful when you are using multiple discount plugins. This will suppress the third party plugins from modifying the prices. When enabled, other discount plugins might not work.', 'woo-discount-rules');   ?></span>
                            <span class="wdr_settings_desc_text awdr-clear-both text-warning"><?php  esc_html_e('Change this option only if recommended.', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                            <input type="radio" name="suppress_other_discount_plugins" id="suppress_other_discount_plugins"
                                   value="1" <?php echo($configuration->getConfig('suppress_other_discount_plugins', 0) ? 'checked' : '') ?>><label
                                    for="suppress_other_discount_plugins"><?php esc_html_e('Yes', 'woo-discount-rules');   ?></label>
                            <input type="radio" name="suppress_other_discount_plugins"
                                   id="do_not_suppress_other_discount_plugins"
                                   value="0" <?php echo(!$configuration->getConfig('suppress_other_discount_plugins', 0) ? 'checked' : '') ?>><label
                                    for="do_not_suppress_other_discount_plugins"><?php esc_html_e('No', 'woo-discount-rules');   ?></label>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label class="awdr-left-align"><?php esc_html_e('Use the minified CSS & JS files of discount rules', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"></span>
                        </td>
                        <td>
                            <input type="radio" name="compress_css_and_js" id="compress_css_and_js_0"
                                   value="1" <?php echo($configuration->getConfig('compress_css_and_js', 0) ? 'checked' : '') ?>><label
                                    for="compress_css_and_js_0"><?php esc_html_e('Yes', 'woo-discount-rules');   ?></label>
                            <input type="radio" name="compress_css_and_js"
                                   id="compress_css_and_js_1"
                                   value="0" <?php echo(!$configuration->getConfig('compress_css_and_js', 0) ? 'checked' : '') ?>><label
                                    for="compress_css_and_js_1"><?php esc_html_e('No', 'woo-discount-rules');   ?></label>
                        </td>
                    </tr>
                    <?php
                    do_action('advanced_woo_discount_rules_general_settings_fields', $configuration);
                    ?>
                    </tbody>
                </table>

                <h1><?php esc_html_e('Product', 'woo-discount-rules')   ?></h1>

                <table class="wdr-general-setting form-table">
                    <tbody style="background-color: #fff;">
                    <tr>
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('On-sale badge', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('NOTE: If you see any wrong percentages on the badge when enabled, it is very likely that your theme might have an override that causes a conflict. Contact our support team for assistance [https://www.flycart.org/support]', 'woo-discount-rules'); ?> - <a href="https://docs.flycart.org/en/articles/4179583-sale-tag-dynamic-sale-badge?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=show_on_sale_badge_setting" target="_blank"><?php esc_html_e('Read Docs', 'woo-discount-rules');   ?></a></span>
                        </td>
                        <td>
                            <?php
                            $awdr_show_on_sale_badge = $configuration->getConfig('show_on_sale_badge', 'disabled');
                            ?>
                            <select name="show_on_sale_badge" class="on_sale_badge_condition">
                                <option value="when_condition_matches" <?php echo ($awdr_show_on_sale_badge == 'when_condition_matches') ? 'selected' : ''; ?> ><?php esc_html_e('Show only after a rule condition is matched exactly', 'woo-discount-rules');   ?></option>
                                <option value="at_least_has_any_rules" <?php echo ($awdr_show_on_sale_badge == 'at_least_has_any_rules') ? 'selected' : ''; ?>><?php esc_html_e('Show on products that are covered under any discount rule in the plugin', 'woo-discount-rules');   ?></option>
                                <option value="disabled" <?php echo ($awdr_show_on_sale_badge == 'disabled') ? 'selected' : ''; ?>><?php esc_html_e('Do not show', 'woo-discount-rules');   ?></option>
                             </select>
                        </td>
                    </tr>
                    <tr class="sale_badge_toggle" style="<?php echo ($awdr_show_on_sale_badge == 'disabled')? 'display:none;':''?>">
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('Do you want to customize the sale badge?', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Customize the sale badge', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                            <?php
                            $awdr_customize_on_sale_badge = $configuration->getConfig('customize_on_sale_badge', '');
                            $awdr_force_override_on_sale_badge = $configuration->getConfig('force_override_on_sale_badge', '');
                            $awdr_display_percentage_on_sale_badge = $configuration->getConfig('display_percentage_on_sale_badge', '');
                            ?>
                            <input type="checkbox" name="customize_on_sale_badge" id="customize_on_sale_badge"
                                   value="1" <?php echo ( $awdr_customize_on_sale_badge == 1 ? 'checked' : '') ?>><label
                                    for="customize_on_sale_badge" class="padding10"><?php esc_html_e('Yes, I would like to customize the sale badge', 'woo-discount-rules');   ?></label>
                            <br>
                            <input type="checkbox" name="force_override_on_sale_badge" id="force_override_on_sale_badge"
                                   value="1" <?php echo ( $awdr_force_override_on_sale_badge == 1 ? 'checked' : '') ?>><label
                                    for="force_override_on_sale_badge" class="padding10"><?php esc_html_e('Force override the label for sale badge (useful when your theme has override for sale badge).', 'woo-discount-rules');   ?></label>
                            <br>
                            <div class="display_percentage_on_sale_badge_con">
                            <input type="checkbox" name="display_percentage_on_sale_badge" id="display_percentage_on_sale_badge"
                                   value="1" <?php echo ( $awdr_display_percentage_on_sale_badge == 1 ? 'checked' : '') ?>><label
                                for="display_percentage_on_sale_badge" class="padding10"><?php esc_html_e('I would like to display percentage in sale badge (Displays only when rule matches else displays default sale badge content).', 'woo-discount-rules');   ?></label>
                            </div>
                        </td>
                    </tr>
                    <tr class="sale_badge_customizer" style="<?php echo ($awdr_show_on_sale_badge != 'disabled' && $awdr_customize_on_sale_badge == 1) ? '':'display:none;'?>">
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('Sale badge content', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both">
                                <span class="wdr_settings_desc_text awdr-clear-both">
                                <?php
                                echo wp_kses_post(__('<b>Eg:</b> <span class="onsale">Sale!</span> <br>NOTE: You can use HTML inside. <b>IMPORTANT:</b> This customized sale badge will apply only for products that are part of discount rules configured in this plugin.', 'woo-discount-rules'));
                                ?>
                               </span>

                            </span>
                        </td>
                        <td>
                            <textarea name="on_sale_badge_html"
                                      placeholder='<span class="onsale"><?php esc_html_e('Sale!', 'woo-discount-rules') ?></span>'
                                      rows="5"
                                      cols="30"><?php echo wp_kses_post($configuration->getConfig('on_sale_badge_html', '<span class="onsale">Sale!</span>')); ?></textarea>
                        </td>
                    </tr>
                    <tr class="sale_badge_percentage_customizer" style="<?php echo ($awdr_show_on_sale_badge != 'disabled' && $awdr_display_percentage_on_sale_badge == 1) ? '':'display:none;'?>">
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('Sale badge percentage content', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both">
                                <?php
                                echo wp_kses_post( sprintf(
                                /* translators:%s for the sale text */
                                    __( 'You can use HTML inside. <br><b>IMPORTANT NOTE:</b> This customized sale badge will be applicable only for products that are part of the discount rules configured in this plugin <b>Eg:</b> <span class="onsale">%s</span>',
                                        'woo-discount-rules'
                                    ),
                                    __('Sale!', 'woo-discount-rules')
                                ) );
                                ?>
                        </td>
                        <td>
                            <textarea name="on_sale_badge_percentage_html"
                                      placeholder='<span class="onsale"><?php esc_html_e('{{percentage}}%', 'woo-discount-rules')   ?></span>'
                                      rows="5"
                                      cols="30"><?php echo wp_kses_post($configuration->getConfig('on_sale_badge_percentage_html', '<span class="onsale">{{percentage}}%</span>')); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('Show bulk discount table', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Turn on / off the discount table on product pages. NOTE: Applies only when you have a Bulk Discount rules.', 'woo-discount-rules');   ?> - <a href="https://docs.flycart.org/en/articles/4230405-display-discount-table?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=show_discount_table_setting" target="_blank"><?php esc_html_e('Read Docs', 'woo-discount-rules'); ?></a></span>
                        </td>
                        <td>
                            <input type="radio" name="show_bulk_table" id="show_bulk_table_layout" class="bulk_table_layout settings_option_show_hide"
                                   value="1" <?php echo esc_attr($configuration->getConfig('show_bulk_table', 0) ? 'checked' : ''); ?> data-name="hide_table_position"><label
                                    for="show_bulk_table_layout"><?php esc_html_e('Yes', 'woo-discount-rules');   ?></label>
                            <input type="radio" name="show_bulk_table" id="dont_show_bulk_table_layout" class="bulk_table_layout settings_option_show_hide"
                                   value="0" <?php echo(!$configuration->getConfig('show_bulk_table', 0) ? 'checked' : '') ?> data-name="hide_table_position"><label
                                    for="dont_show_bulk_table_layout"><?php esc_html_e('No', 'woo-discount-rules');   ?></label>
                            <a class="wdr-popup-link" style="<?php echo (!$configuration->getConfig('show_bulk_table', 0)) ? 'display:none' : ''; ?>"><span class="modal-trigger" data-modal="modal-name"><?php esc_html_e("Customize Discount Table", 'woo-discount-rules');   ?></a>
                        </td>

                    </tr>
                    <tr class="hide_table_position"
                        style="<?php echo (!$configuration->getConfig('show_bulk_table', 0) ? 'display:none' : ''); ?>">
                        <td scope="row">
                            <label for="position_to_show_bulk_table" class="awdr-left-align"><?php esc_html_e('Discount table location', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Choose the location to show the discount table on the product pages', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                            <select name="position_to_show_bulk_table">
                                <option value="woocommerce_before_add_to_cart_form" <?php echo ($configuration->getConfig('position_to_show_bulk_table', 'woocommerce_before_add_to_cart_form') == 'woocommerce_before_add_to_cart_form') ? 'selected' : ''; ?> ><?php esc_html_e('Woocommerce before add to cart form', 'woo-discount-rules');   ?></option>
                                <option value="woocommerce_product_meta_end" <?php echo ($configuration->getConfig('position_to_show_bulk_table', 'woocommerce_before_add_to_cart_form') == 'woocommerce_product_meta_end') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce product meta end', 'woo-discount-rules');   ?></option>
                                <option value="woocommerce_product_meta_start" <?php echo ($configuration->getConfig('position_to_show_bulk_table', 'woocommerce_before_add_to_cart_form') == 'woocommerce_product_meta_start') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce product meta start', 'woo-discount-rules');   ?></option>
                                <option value="woocommerce_after_add_to_cart_form" <?php echo ($configuration->getConfig('position_to_show_bulk_table', 'woocommerce_before_add_to_cart_form') == 'woocommerce_after_add_to_cart_form') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce after add to cart form', 'woo-discount-rules');   ?></option>
                                <option value="woocommerce_after_single_product" <?php echo ($configuration->getConfig('position_to_show_bulk_table', 'woocommerce_before_add_to_cart_form') == 'woocommerce_after_single_product') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce after single product', 'woo-discount-rules');  ?></option>
                                <option value="woocommerce_before_single_product" <?php echo ($configuration->getConfig('position_to_show_bulk_table', 'woocommerce_before_add_to_cart_form') == 'woocommerce_before_single_product') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce before single product', 'woo-discount-rules');   ?></option>
                                <option value="woocommerce_after_single_product_summary" <?php echo ($configuration->getConfig('position_to_show_bulk_table', 'woocommerce_before_add_to_cart_form') == 'woocommerce_after_single_product_summary') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce after single product summary', 'woo-discount-rules');   ?></option>
                                <option value="woocommerce_before_single_product_summary" <?php echo ($configuration->getConfig('position_to_show_bulk_table', 'woocommerce_before_add_to_cart_form') == 'woocommerce_before_single_product_summary') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce before single product summary', 'woo-discount-rules');   ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label for="position_to_show_discount_bar" class="awdr-left-align"><?php esc_html_e('Discount bar location', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Choose the location to show the discount bar on the product pages', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                            <select name="position_to_show_discount_bar">
                                <option value="woocommerce_before_add_to_cart_form" <?php echo ($configuration->getConfig('position_to_show_discount_bar', 'woocommerce_before_add_to_cart_form') == 'woocommerce_before_add_to_cart_form') ? 'selected' : ''; ?> ><?php esc_html_e('Woocommerce before add to cart form', 'woo-discount-rules');   ?></option>
                                <option value="woocommerce_product_meta_end" <?php echo ($configuration->getConfig('position_to_show_discount_bar', 'woocommerce_before_add_to_cart_form') == 'woocommerce_product_meta_end') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce product meta end', 'woo-discount-rules');   ?></option>
                                <option value="woocommerce_product_meta_start" <?php echo ($configuration->getConfig('position_to_show_discount_bar', 'woocommerce_before_add_to_cart_form') == 'woocommerce_product_meta_start') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce product meta start', 'woo-discount-rules');   ?></option>
                                <option value="woocommerce_after_add_to_cart_form" <?php echo ($configuration->getConfig('position_to_show_discount_bar', 'woocommerce_before_add_to_cart_form') == 'woocommerce_after_add_to_cart_form') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce after add to cart form', 'woo-discount-rules');   ?></option>
                                <option value="woocommerce_after_single_product" <?php echo ($configuration->getConfig('position_to_show_discount_bar', 'woocommerce_before_add_to_cart_form') == 'woocommerce_after_single_product') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce after single product', 'woo-discount-rules');   ?></option>
                                <option value="woocommerce_before_single_product" <?php echo ($configuration->getConfig('position_to_show_discount_bar', 'woocommerce_before_add_to_cart_form') == 'woocommerce_before_single_product') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce before single product', 'woo-discount-rules');   ?></option>
                                <option value="woocommerce_after_single_product_summary" <?php echo ($configuration->getConfig('position_to_show_discount_bar', 'woocommerce_before_add_to_cart_form') == 'woocommerce_after_single_product_summary') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce after single product summary', 'woo-discount-rules');   ?></option>
                                <option value="woocommerce_before_single_product_summary" <?php echo ($configuration->getConfig('position_to_show_discount_bar', 'woocommerce_before_add_to_cart_form') == 'woocommerce_before_single_product_summary') ? 'selected' : ''; ?>><?php esc_html_e('Woocommerce before single product summary', 'woo-discount-rules');   ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('Show strikeout price', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Choose pages that display prices with strike through', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                            <input type="checkbox" name="modify_price_at_shop_page" id="modify_price_at_shop_page"
                                   value="1" <?php echo($configuration->getConfig('modify_price_at_shop_page', 1) ? 'checked' : '') ?>><label
                                    for="modify_price_at_shop_page" class="padding10"><?php esc_html_e('Shop', 'woo-discount-rules');   ?></label>
                            <input type="checkbox" name="modify_price_at_product_page" id="modify_price_at_product_page"
                                   value="1" <?php echo($configuration->getConfig('modify_price_at_product_page', 1) ? 'checked' : '') ?>><label
                                    for="modify_price_at_product_page" class="padding10"><?php esc_html_e('Product pages', 'woo-discount-rules');   ?></label>
                            <input type="checkbox" name="modify_price_at_category_page" id="modify_price_at_category_page"
                                   value="1" <?php echo($configuration->getConfig('modify_price_at_category_page', 1) ? 'checked' : '') ?>><label
                                    for="modify_price_at_category_page" class="padding10"><?php esc_html_e('Category pages', 'woo-discount-rules');   ?></label>

                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('When prices should be shown with a strike through', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Choose how to apply the strike through line on the prices', 'woo-discount-rules');   ?> - <a href="https://docs.flycart.org/en/articles/4178894-display-discounted-price-with-strikethrough-on-default-prices?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=show_strike_out_setting" target="_blank"><?php esc_html_e('Read Docs', 'woo-discount-rules'); ?></a></span>
                        </td>
                        <td>
                            <select name="show_strikeout_when">
                                <option value="show_when_matched" <?php echo ($configuration->getConfig('show_strikeout_when', 'show_when_matched') == 'show_when_matched') ? 'selected' : ''; ?> ><?php esc_html_e('Show right before a rule condition matches', 'woo-discount-rules');   ?></option>
                                <option value="show_after_matched" <?php echo ($configuration->getConfig('show_strikeout_when', 'show_when_matched') == 'show_after_matched') ? 'selected' : ''; ?>><?php esc_html_e('Show right after a rule condition matches', 'woo-discount-rules');   ?></option>
                                <option value="show_dynamically" <?php echo ($configuration->getConfig('show_strikeout_when', 'show_when_matched') == 'show_dynamically') ? 'selected' : ''; ?>><?php esc_html_e('Show at the time of quantity being updated (handled dynamically)', 'woo-discount-rules');   ?></option>
                            </select>
                        </td>
                    </tr>
                    <?php
                    do_action('advanced_woo_discount_rules_product_settings_fields', $configuration);
                    ?>

                    </tbody>
                </table>

                <h1><?php esc_html_e('Cart', 'woo-discount-rules');   ?></h1>

                <table class="wdr-general-setting form-table">
                    <tbody style="background-color: #fff;">
                    <tr>
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('Show strikeout on cart page', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Turn this option on to display prices with strike through in the cart pages when discount applies', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                            <input type="radio" name="show_strikeout_on_cart" id="show_strikeout_on_cart"
                                   value="1" <?php echo($configuration->getConfig('show_strikeout_on_cart', 1) ? 'checked' : '') ?>><label
                                    for="show_strikeout_on_cart"><?php esc_html_e('Yes', 'woo-discount-rules');   ?></label>

                            <input type="radio" name="show_strikeout_on_cart" id="dont_show_strikeout_on_cart"
                                   value="0" <?php echo(!$configuration->getConfig('show_strikeout_on_cart', 1) ? 'checked' : '') ?>><label
                                    for="dont_show_strikeout_on_cart"><?php esc_html_e('No', 'woo-discount-rules');  ?></label>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('Apply cart discount as', 'woo-discount-rules')  ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Choose how to process the cart discount rules. You can choose to process as a coupon or a fee.', 'woo-discount-rules');  ?></span>
                        </td>
                        <td>
                            <select name="apply_cart_discount_as">
                                <option value="fee" <?php echo ($configuration->getConfig('apply_cart_discount_as', 'coupon') == 'fee') ? 'selected' : ''; ?> ><?php esc_html_e('Fee', 'woo-discount-rules');  ?></option>
                                <option value="coupon" <?php echo ($configuration->getConfig('apply_cart_discount_as', 'coupon') == 'coupon') ? 'selected' : ''; ?>><?php esc_html_e('Coupon', 'woo-discount-rules');  ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('Combine all cart discounts into one', 'woo-discount-rules')  ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Useful if you would like to show all the cart based discounts as one line item in the cart totals.', 'woo-discount-rules');  ?></span>
                        </td>
                        <td>
                            <input type="radio" name="combine_all_cart_discounts" id="combine_all_cart_discounts"
                                   data-name="combine_all_cart_discounts"
                                   value="1"
                                   class="settings_option_show_hide" <?php echo($configuration->getConfig('combine_all_cart_discounts', 0) ? 'checked' : '') ?>><label
                                    for="combine_all_cart_discounts"><?php esc_html_e('Yes', 'woo-discount-rules');  ?></label>

                            <input type="radio" name="combine_all_cart_discounts" id="dont_combine_all_cart_discounts"
                                   data-name="combine_all_cart_discounts"
                                   value="0"
                                   class="settings_option_show_hide" <?php echo(!$configuration->getConfig('combine_all_cart_discounts', 0) ? 'checked' : '') ?>><label
                                    for="dont_combine_all_cart_discounts"><?php esc_html_e('No', 'woo-discount-rules');   ?></label>
                        </td>
                    </tr>
                    <tr class="combine_all_cart_discounts"
                        style="<?php echo(!$configuration->getConfig('combine_all_cart_discounts', 0) ? 'display:none' : '') ?>">
                        <td scope="row">
                            <label for="discount_label_for_combined_discounts" class="awdr-left-align"><?php esc_html_e('Label for combined discount amount', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('The label shows up under the cart totals section', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                            <input type="text" name="discount_label_for_combined_discounts"
                                   value="<?php echo esc_attr($configuration->getConfig('discount_label_for_combined_discounts', 'Cart discount')); ?>">
                        </td>
                    </tr>
                    <?php
                    do_action('advanced_woo_discount_rules_cart_settings_fields', $configuration);
                    ?>
                    </tbody>
                </table>
                <h1><?php esc_html_e('Promotion', 'woo-discount-rules');   ?></h1>
                <table class="wdr-general-setting form-table">
                    <tbody style="background-color: #fff;">
                    <tr>
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('Promotional Banners', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php esc_html_e('This section allows you to configure simple banners about your discount promotions on the shop / product / cart pages', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                        <div class="awdr-promotion-banner">
                            <div>
                                <div>
                                    <input type="checkbox" name="show_subtotal_promotion" id="show_subtotal_promotion" class="awdr_show_condition_promotion_message"
                                           value="1" <?php echo($configuration->getConfig('show_subtotal_promotion', 0) ? 'checked' : '') ?>>
                                    <label for="show_subtotal_promotion" class=""><?php esc_html_e('Use subtotal based rules', 'woo-discount-rules');   ?></label>
                                </div>

                                <span class="wdr_settings_desc_text awdr-clear-both">
                                    <?php esc_html_e('Enable "Use subtotal based rules" option if you would like to use the discount rules that have subtotal condition. Useful if you want to show banners like "Spend $100 more to get 10% discount"','woo-discount-rules');  ?></span>

                            </div>
                            <div>
                                <div>
                                    <input type="checkbox" name="show_cart_quantity_promotion" id="show_cart_quantity_promotion" class="awdr_show_condition_promotion_message"
                                           value="1" <?php echo($configuration->getConfig('show_cart_quantity_promotion', 0) ? 'checked' : '') ?>><label
                                            for="show_cart_quantity_promotion" class=""><?php esc_html_e('Use quantity based rules', 'woo-discount-rules');   ?></label>
                                </div>
                                <span class="wdr_settings_desc_text awdr-clear-both">
                                    <?php esc_html_e('Enable "Use quantity based rules" option if you would like to use the discount rules that have cart quantity condition. Useful if you want to show banners like "Buy 2 more items to get 10% discount"','woo-discount-rules');  ?></span>

                            </div>
                        </div>
                        </td>

                    </tr>
                    <tr class="awdr_promotion_message_display_pages" <!--style="--><?php /*echo ($configuration->getConfig('show_subtotal_promotion', 0) || $configuration->getConfig('show_cart_quantity_promotion', 0)) ? '' : 'display:none'; */?>">
                        <td scope="row">
                            <label for="show_promo_text" class="awdr-left-align"><?php esc_html_e('Choose pages to show the banner', 'woo-discount-rules')  ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Choose pages to show the banner. Only available for subtotal or item quantity based discount rules.', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                            <?php $awdr_show_promo_text = $configuration->getConfig('show_promo_text', ''); ?>
                            <select name="show_promo_text[]" multiple class="edit-all-loaded-values" id="show_promo_text" data-placeholder="<?php esc_attr_e("Select the page to display promotion message", 'woo-discount-rules');  ?>">
                                <option value="shop_page" <?php echo (!empty($awdr_show_promo_text) && is_array($awdr_show_promo_text) && in_array('shop_page', $awdr_show_promo_text)) ? 'selected' : ''; ?>><?php esc_html_e('Shop page', 'woo-discount-rules');  ?></option>
                                <option value="product_page" <?php echo (!empty($awdr_show_promo_text) && is_array($awdr_show_promo_text) && in_array('product_page', $awdr_show_promo_text)) ? 'selected' : ''; ?> ><?php esc_html_e('Product page', 'woo-discount-rules');  ?></option>
                                <option value="cart_page" <?php echo (!empty($awdr_show_promo_text) && is_array($awdr_show_promo_text) && in_array('cart_page', $awdr_show_promo_text)) ? 'selected' : ''; ?> ><?php esc_html_e('Cart page', 'woo-discount-rules');  ?></option>
                                <option value="checkout_page" <?php echo (!empty($awdr_show_promo_text) && is_array($awdr_show_promo_text) && in_array('checkout_page', $awdr_show_promo_text)) ? 'selected' : ''; ?> ><?php esc_html_e('Checkout page', 'woo-discount-rules');  ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label for="display_saving_text" class="awdr-left-align"><?php esc_html_e('Show "You saved" text under line items in cart / checkout', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Useful to inform the customer that they have saved money with your promotions and helps build long term relationship.', 'woo-discount-rules');   ?> - <a href="https://docs.flycart.org/en/articles/4129525-display-you-saved-message?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=display_you_saved_text" target="_blank"><?php esc_html_e('Read Docs', 'woo-discount-rules'); ?></a></span>
                        </td>
                        <td>
                            <select name="display_saving_text" class="settings_option_show_hide_on_change">
                                <option value="disabled" <?php echo ($configuration->getConfig('display_saving_text', 'disabled') == 'disabled') ? 'selected' : ''; ?>><?php esc_html_e('Disabled', 'woo-discount-rules');  ?></option>
                                <option value="on_each_line_item" <?php echo ($configuration->getConfig('display_saving_text', 'disabled') == 'on_each_line_item') ? 'selected' : ''; ?> ><?php esc_html_e('On each line item', 'woo-discount-rules');  ?></option>
                                <option value="after_total" <?php echo ($configuration->getConfig('display_saving_text', 'disabled') == 'after_total') ? 'selected' : ''; ?> ><?php esc_html_e('On after total', 'woo-discount-rules');  ?></option>
                                <option value="both_line_item_and_after_total" <?php echo ($configuration->getConfig('display_saving_text', 'disabled') == 'both_line_item_and_after_total') ? 'selected' : ''; ?> ><?php esc_html_e('Both in line item and after total', 'woo-discount-rules');  ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr class="display_you_saved_text"
                        style="<?php echo ($configuration->getConfig('display_saving_text', 'disabled') == 'disabled') ? 'display:none' : ''; ?>">
                        <td scope="row">
                            <label for="you_saved_text" class="awdr-left-align"><?php esc_html_e('Savings text to show', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('You save text to show when rule applied', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                            <textarea name="you_saved_text" rows="5"
                                      cols="30"><?php echo wp_kses_post($configuration->getConfig('you_saved_text', 'You saved {{total_discount}}')); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('Show notifications on the cart page', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Turn on / off the notifications that appear on the cart page when a discount rule is applied', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                            <input type="radio" name="show_applied_rules_message_on_cart" class="settings_option_show_hide"
                                   id="show_applied_rules_message_on_cart" data-name="hide_alert_message_text"
                                   value="1" <?php echo ($configuration->getConfig('show_applied_rules_message_on_cart', 0) ? 'checked' : ''); ?>><label
                                    for="show_applied_rules_message_on_cart"><?php esc_html_e('Yes', 'woo-discount-rules');  ?></label>

                            <input type="radio" name="show_applied_rules_message_on_cart" class="settings_option_show_hide"
                                   id="dont_show_applied_rules_message_on_cart" data-name="hide_alert_message_text"
                                   value="0" <?php echo (!$configuration->getConfig('show_applied_rules_message_on_cart', 0) ? 'checked' : ''); ?>><label
                                    for="dont_show_applied_rules_message_on_cart"><?php esc_html_e('No', 'woo-discount-rules');  ?></label>
                        </td>
                    </tr>
                    <tr class="hide_alert_message_text" style="<?php echo (!$configuration->getConfig('show_applied_rules_message_on_cart', 0) ? 'display:none' : ''); ?>">
                        <td scope="row">
                            <label for="applied_rule_message" class="awdr-left-align"><?php esc_html_e('Notification text', 'woo-discount-rules');  ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('You can use HTML tags like to style the text', 'woo-discount-rules');  ?></span>
                        </td>
                        <td>
                            <textarea name="applied_rule_message" rows="5" cols="30"><?php
                                $awdr_user_message = $configuration->getConfig('applied_rule_message', 'Discount <strong>{{title}}</strong> has been applied to your cart.');
                                echo wp_kses_post(__($awdr_user_message, 'woo-discount-rules')); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText?>
                            </textarea>
                        </td>
                    </tr>
                    <?php
                    do_action('advanced_woo_discount_rules_promotion_settings_fields', $configuration);
                    ?>
                    </tbody>
                </table>
                <!--<h1><?php /*esc_html_e('Banner', 'woo-discount-rules'); */?></h1>
                <table class="wdr-general-setting form-table">
                    <tbody style="background-color: #fff;">
                    <?php /*if(!$is_pro) { */?>
                        <tr class="" style="">
                            <td scope="row">
                                <label for="applied_rule_message"
                                       class="awdr-left-align"><?php /*esc_html_e('Banner Content', 'woo-discount-rules') */?></label>
                               <span class="wdr_desc_text awdr-clear-both">
                               <span>
    <?php /* echo __('A static banner that you want to display in your storefront. <br><br> <b>NOTE:</b> It is a static banner. You can use any content or HTML here.', 'woo-discount-rules');*/ ?>
    </span>
</span>

                            </td>
                            <td>
                            <?php
                            /*
                                    echo wp_kses_post(
                                        sprintf(
                                            __( 'Unlock this feature by %s.', 'woo-discount-rules' ),
                                            '<a href="https://www.flycart.org/products/wordpress/woocommerce-discount-rules?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=unlock_pro" target="_blank">' . esc_html__('Upgrading to Pro', 'woo-discount-rules') . '</a>'
                                        )
                                    );
                            */
                            ?>

                            </td>
                        </tr>
                        <tr class="" style="">
                            <td scope="row">
                                <label for="applied_rule_message"
                                       class="awdr-left-align"><?php /*esc_html_e('Banner Content display position', 'woo-discount-rules') */?></label>
                                <span class="wdr_desc_text awdr-clear-both"><?php /*esc_html_e('Choose a display position for the banner in your storefront', 'woo-discount-rules'); */?></span>
                            </td>
                            <td><?php /*
                                    echo wp_kses_post(
                                        sprintf(
                                            __( 'Unlock this feature by %s.', 'woo-discount-rules' ),
                                            '<a href="https://www.flycart.org/products/wordpress/woocommerce-discount-rules?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=unlock_pro" target="_blank">' . esc_html__('Upgrading to Pro', 'woo-discount-rules') . '</a>'
                                        )
                                    );
                            */?></td>
                        </tr>
                    <?php /*} */?>

                    <?php
/*                    do_action('advanced_woo_discount_rules_promotion_settings_fields', $configuration);
                    */?>
                    </tbody>
                </table>-->
                <h1><?php esc_html_e('On-Sale page', 'woo-discount-rules');   ?> - <a href="https://docs.flycart.org/en/articles/4098969-sale-page?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=on_sale_page_settings" target="_blank"><?php esc_html_e('Read Docs', 'woo-discount-rules'); ?></a></h1>
                <table class="wdr-general-setting form-table">
                    <tbody style="background-color: #fff;">
                    <tr>
                        <td scope="row">
                            <?php
                            esc_html_e('Choose the discount rules to be used to build the on sale page?', 'woo-discount-rules' ) ;  
                            ?>
                        </td>
                        <td scope="row">
                            <?php if($is_pro){
                                $awdr_rebuild_on_sale_rules = $configuration->getConfig('awdr_rebuild_on_sale_rules', array()); ?>
                            <div class="awdr_rebuild_on_sale_list_progress">
                            </div>
                            <div class="awdr_rebuild_on_sale_list_con">
                                <div class="wdr-select-filed-hight wdr-search-box">
                                    <select id="awdr_rebuild_on_sale_rules" name="awdr_rebuild_on_sale_rules[]" multiple
                                            class="edit-all-loaded-values"
                                            data-list=""
                                            data-field="autoloaded"
                                            data-placeholder="<?php esc_attr_e("Type the name of the rule to select it", 'woo-discount-rules');  ?>"
                                            style="">
                                        <option value="all"
                                            <?php if(!empty($awdr_rebuild_on_sale_rules) && is_array($awdr_rebuild_on_sale_rules)){
                                                if(in_array("all", $awdr_rebuild_on_sale_rules)){
                                                    echo ' selected ';
                                                }
                                            } ?>
                                        ><?php  esc_html_e("All active rules", 'woo-discount-rules');   ?></option>
                                        <?php
                                        $awdr_rules = \Wdr\App\Controllers\ManageDiscount::$available_rules;
                                        if(!empty($awdr_rules) && is_array($awdr_rules)){
                                            foreach ($awdr_rules as $awdr_rule){
                                                if($awdr_rule->rule->enabled == 1){
                                                    ?>
                                                    <option value="<?php echo esc_attr($awdr_rule->rule->id); ?>"
                                                    <?php if(!empty($awdr_rebuild_on_sale_rules) && is_array($awdr_rebuild_on_sale_rules)){
                                                        if(in_array($awdr_rule->rule->id, $awdr_rebuild_on_sale_rules)){
                                                            echo ' selected ';
                                                        }
                                                    } ?>
                                                    ><?php echo esc_html($awdr_rule->rule->title); ?></option>
                                                    <?php
                                                }
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="awdr_rebuild_on_sale_list_notice">
                                </div>
                            </div>
                            <button type="button" class="btn btn-warning" id="awdr_rebuild_on_sale_list" data-awdr_nonce="<?php echo esc_attr(\Wdr\App\Helpers\Helper::create_nonce('wdr_ajax_rule_build_index')); ?>"><?php esc_html_e('Save and build the page', 'woo-discount-rules' );  ?></button>
                            <?php } else {
                                echo wp_kses_post(
                                    sprintf(
                                    /* translators:%s for the link */
                                        __('Unlock this feature by %s.', 'woo-discount-rules'),
                                        '<a href="https://www.flycart.org/products/wordpress/woocommerce-discount-rules?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=unlock_pro" target="_blank">' . esc_html__('Upgrading to Pro', 'woo-discount-rules') . '</a>'
                                    )
                                );
                            }?>
                        </td>
                    </tr>
                    <?php if($is_pro){ ?>
                    <tr>
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('Exclude out of stock products', 'woo-discount-rules')   ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php esc_html_e('This will exclude out-of-stock items from showing on the "On Sale" page', 'woo-discount-rules');   ?></span>
                        </td>

                        <td>
                            <input type="radio" name="exclude_out_of_stock_products_for_on_sale_page" class="settings_option_show_hide"
                                   id="awdr_exclude_out_of_stock_products_for_on_sale_page_1"
                                   value="1" <?php echo($configuration->getConfig('exclude_out_of_stock_products_for_on_sale_page', 0) ? 'checked' : '') ?>><label
                                    for="awdr_exclude_out_of_stock_products_for_on_sale_page_1"><?php esc_html_e('Yes', 'woo-discount-rules');   ?></label>

                            <input type="radio" name="exclude_out_of_stock_products_for_on_sale_page" class="settings_option_show_hide"
                                   id="awdr_exclude_out_of_stock_products_for_on_sale_page_0"
                                   value="0" <?php echo(!$configuration->getConfig('exclude_out_of_stock_products_for_on_sale_page', 0) ? 'checked' : '') ?>><label
                                    for="awdr_exclude_out_of_stock_products_for_on_sale_page_0"><?php esc_html_e('No', 'woo-discount-rules');  ?></label>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row">
                            <label for="" class="awdr-left-align"><?php esc_html_e('Automatically update the page on a daily basis', 'woo-discount-rules')  ?></label>
                            <span class="wdr_settings_desc_text awdr-clear-both"><?php esc_html_e('This will be useful to automatically add or remove items from the page based on discount rules with specific date validity.', 'woo-discount-rules');   ?></span>
                        </td>
                        <td>
                            <input type="radio" name="run_rebuild_on_sale_index_cron" class="settings_option_show_hide"
                                   id="awdr_run_rebuild_on_sale_index_cron_1"
                                   value="1" <?php echo($configuration->getConfig('run_rebuild_on_sale_index_cron', 0) ? 'checked' : '') ?>><label
                                    for="awdr_run_rebuild_on_sale_index_cron_1"><?php esc_html_e('Yes', 'woo-discount-rules');  ?></label>

                            <input type="radio" name="run_rebuild_on_sale_index_cron" class="settings_option_show_hide"
                                   id="awdr_run_rebuild_on_sale_index_cron_0"
                                   value="0" <?php echo(!$configuration->getConfig('run_rebuild_on_sale_index_cron', 0) ? 'checked' : '') ?>><label
                                    for="awdr_run_rebuild_on_sale_index_cron_0"><?php esc_html_e('No', 'woo-discount-rules');  ?></label>
                        </td>
                    </tr>
                    <tr>
                        <td scope="row" colspan="2">
                            <?php
                            esc_html_e('Shortcode to display the Sale page:', 'woo-discount-rules' );  
                            ?>
                            <span id="awdr_shortcode_text">[awdr_sale_items_list]</span>
                            <button type="button" class="btn btn-warning" id="awdr_shortcode_copy_btn"><?php esc_html_e('Copy ShortCode', 'woo-discount-rules' );   ?></button>
                        </td>
                    </tr>
                    <?php } ?>
                    </tbody>
                </table>
                <h1><?php esc_html_e('Advanced Options - Handle third-party plugin conflicts', 'woo-discount-rules');   ?></h1>
                <p><?php esc_html_e('The following are advanced options primarily to resolve conflicts produced by third-party plugins. Third party plugins that interact with pricing & discount hooks of WooCommerce often cause issues. Use these options ONLY when you see the discount rules are NOT applying.', 'woo-discount-rules');   ?></p>
                <p style="color:tomato; font-weight: normal;">
                    <?php
                    printf(
                    /* translators:%s for the link */
                        esc_html__('Please consult with our support team by opening a ticket at %s before you turn on these options.', 'woo-discount-rules'),
                        '<a href="https://www.flycart.org/support" target="_blank">https://www.flycart.org/support</a>'
                    );
                    ?>
                </p>
                <table class="wdr-general-setting form-table">
                    <tbody style="background-color: #fff;">
                        <tr>
                            <td scope="row">
                                <label for="" class="awdr-left-align"><?php esc_html_e('Do you use a third-party plugin or custom code to set product prices?', 'woo-discount-rules')   ?></label>
                                <span class="wdr_settings_desc_text awdr-clear-both"><?php esc_html_e('Example: If you use plugins for wholesale pricing or country-specific pricing that manage product prices, there\'s a chance that discount rules may not apply. In such cases, you can activate this option to ensure compatibility.', 'woo-discount-rules');   ?></span>
                            </td>
                            <td>
                                <input type="radio" name="wdr_override_custom_price" class="settings_option_show_hide"
                                       id="wdr_override_custom_price_1"
                                       value="1" <?php echo($configuration->getConfig('wdr_override_custom_price', 0) ? 'checked' : '') ?>><label
                                        for="wdr_override_custom_price_1"><?php esc_html_e('Yes', 'woo-discount-rules');   ?></label>

                                <input type="radio" name="wdr_override_custom_price" class="settings_option_show_hide"
                                       id="wdr_override_custom_price_0"
                                       value="0" <?php echo(!$configuration->getConfig('wdr_override_custom_price', 0) ? 'checked' : '') ?>><label
                                        for="wdr_override_custom_price_0"><?php esc_html_e('No', 'woo-discount-rules');   ?></label>
                            </td>
                        </tr>
                        <tr>
                            <td scope="row">
                                <label for="" class="awdr-left-align"><?php esc_html_e('Disable re-calculation of totals on the cart page?', 'woo-discount-rules')  ?></label>
                                <span class="wdr_settings_desc_text awdr-clear-both"><?php esc_html_e('If a third-party plugin triggers a recalculation of totals on the cart page alongside WooCommerce, this may interfere with the application of discount rules. Activating this option can help ensure discount rules function correctly in such scenarios.', 'woo-discount-rules');  ?></span>
                            </td>
                            <td>
                                <input type="radio" name="disable_recalculate_total" class="settings_option_show_hide"
                                       id="do_disable_recalculate_total_1"
                                       value="1" <?php echo($configuration->getConfig('disable_recalculate_total', 0) ? 'checked' : '') ?>><label
                                        for="do_disable_recalculate_total_1"><?php esc_html_e('Yes', 'woo-discount-rules');  ?></label>

                                <input type="radio" name="disable_recalculate_total" class="settings_option_show_hide"
                                       id="do_disable_recalculate_total_0"
                                       value="0" <?php echo(!$configuration->getConfig('disable_recalculate_total', 0) ? 'checked' : '') ?>><label
                                        for="do_disable_recalculate_total_0"><?php esc_html_e('No', 'woo-discount-rules');  ?></label>
                            </td>
                        </tr>
                        <tr>
                            <td scope="row">
                                <label for="" class="awdr-left-align"><?php esc_html_e('Disable re-calculation of totals while a coupon is applied?', 'woo-discount-rules')   ?></label>
                                <span class="wdr_settings_desc_text awdr-clear-both"><?php esc_html_e('This option is useful if discounts are removed or fail to apply after a coupon is used.', 'woo-discount-rules');  ?></span>
                            </td>
                            <td>
                                <input type="radio" name="disable_recalculate_total_when_coupon_apply" class="settings_option_show_hide"
                                       id="disable_recalculate_total_when_coupon_apply_1"
                                       value="1" <?php echo($configuration->getConfig('disable_recalculate_total_when_coupon_apply', 0) ? 'checked' : '') ?>><label
                                        for="disable_recalculate_total_when_coupon_apply_1"><?php esc_html_e('Yes', 'woo-discount-rules');  ?></label>

                                <input type="radio" name="disable_recalculate_total_when_coupon_apply" class="settings_option_show_hide"
                                       id="disable_recalculate_total_when_coupon_apply_0"
                                       value="0" <?php echo(!$configuration->getConfig('disable_recalculate_total_when_coupon_apply', 0) ? 'checked' : '') ?>><label
                                        for="disable_recalculate_total_when_coupon_apply_0"><?php esc_html_e('No', 'woo-discount-rules');  ?></label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php
                do_action('advanced_woo_discount_rules_after_settings_fields', $configuration);
                ?>

            <!--Bulk Table Popup start-->

            <div class="modal" id="modal-name">
                <div class="modal-sandbox"></div>
                <div class="modal-box">
                    <div class="modal-header">
                        <div class="close-modal"><span class="wdr-close-modal-box">&#10006;</span></div>
                        <h1 class="wdr-modal-header-title"><?php esc_html_e("Customize Discount Table", 'woo-discount-rules');  ?></h1>
                    </div>
                    <div class="modal-body">
                        <p class="awdr-save-green wdr-alert-success" style="display: none;"><?php esc_html_e('Settings Saved', 'woo-discount-rules')  ?></p>
                        <p class="awdr-error-red wdr-alert-error" style="display: none;"><?php esc_html_e('Oops! Something went wrong.', 'woo-discount-rules')  ?></p>
                        <p class="wdr-customizer-notes"><b><?php esc_html_e('Note:', 'woo-discount-rules') ?></b><?php esc_html_e(" This table contains sample content for design purpose.", 'woo-discount-rules');  ?></p>
                        <div style="width: 100%">
                            <div class="wdr-customizer-container">
                                <div class="wdr-customizer-grid">
                                    <div class="wdr_customize_table_settings">
                                        <table class="form-table popup-bulk-table">
                                            <tbody style="background-color: #fff;">

                                                <tr>
                                                    <th scope="row">
                                                        <label for="" class="awdr-left-align"><?php esc_html_e('Table Header', 'woo-discount-rules')  ?></label>
                                                        <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Show/Hide table header column names', 'woo-discount-rules');   ?></span>
                                                    </th>
                                                    <td >
                                                        <input type="radio" name="table_column_header" id="show_table_header" class="bulk_table_customizer_preview"
                                                               value="1" data-colname="wdr_bulk_table_thead" data-showhide="show" <?php echo($configuration->getConfig('table_column_header', 1) ? 'checked' : '') ?>><label
                                                                for="show_table_header"><?php esc_html_e('Show', 'woo-discount-rules');   ?></label>
                                                        <input type="radio" name="table_column_header" id="dont_show_table_header" class="bulk_table_customizer_preview"
                                                               value="0" data-colname="wdr_bulk_table_thead" data-showhide="hide" <?php echo(!$configuration->getConfig('table_column_header', 1) ? 'checked' : '') ?>><label
                                                                for="dont_show_table_header"><?php esc_html_e("Don't Show", 'woo-discount-rules');   ?></label>
                                                    </td>
                                                </tr>
                                                <tr class="">
                                                    <th scope="row">
                                                        <label for="" class="awdr-left-align"><?php esc_html_e('Title column Name on table', 'woo-discount-rules');  ?></label>
                                                        <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Give name for rule title column', 'woo-discount-rules');  ?></span>
                                                    </th>
                                                    <td class="awdr_table_columns">
                                                        <input type="checkbox" name="table_title_column" value="1" class="bulk_table_customizer_show_hide_column"
                                                               data-colname="popup_table_title_column"
                                                            <?php echo($configuration->getConfig('table_title_column', 1) ? 'checked' : '') ?>>
                                                        <input type="text" style="width: 90% !important;" class="awdr_popup_col_name_text_box awdr_popup_col_title_keyup" data-keyup="title_on_keyup" name="table_title_column_name" value="<?php echo esc_attr($configuration->getConfig('table_title_column_name', 'Title'));?>">
                                                    </td>
                                                </tr>
                                                <tr class="">
                                                    <th scope="row">
                                                        <label for="" class="awdr-left-align"><?php esc_html_e('Discount column Name on table', 'woo-discount-rules')   ?></label>
                                                        <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Give name for discount column', 'woo-discount-rules');   ?></span>
                                                    </th>
                                                    <td class="awdr_table_columns">
                                                        <input type="checkbox" name="table_discount_column" value="1" class="bulk_table_customizer_show_hide_column"
                                                               data-colname="popup_table_discount_column"
                                                            <?php echo($configuration->getConfig('table_discount_column', 1) ? 'checked' : '') ?>>
                                                        <input type="text" style="width: 90% !important;" class="awdr_popup_col_name_text_box" data-keyup="discount_on_keyup" name="table_discount_column_name" value="<?php echo esc_attr($configuration->getConfig('table_discount_column_name', 'Discount'));?>">
                                                    </td>
                                                </tr>
                                                <tr class="">
                                                    <th scope="row">
                                                        <label for="" class="awdr-left-align"><?php esc_html_e('Range column Name on table', 'woo-discount-rules')   ?></label>
                                                        <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Give name for range column', 'woo-discount-rules');   ?></span>
                                                    </th>
                                                    <td class="awdr_table_columns">
                                                        <input type="checkbox" name="table_range_column" value="1" class="bulk_table_customizer_show_hide_column"
                                                               data-colname="popup_table_range_column"
                                                            <?php echo($configuration->getConfig('table_range_column', 1) ? 'checked' : '') ?>>
                                                        <input type="text" style="width: 90% !important;" class="awdr_popup_col_name_text_box" data-keyup="range_on_keyup" name="table_range_column_name" value="<?php echo esc_attr($configuration->getConfig('table_range_column_name', 'Range'));?>">
                                                    </td>
                                                </tr>
                                                <tr class="">
                                                    <th scope="row">
                                                        <label for="" class="awdr-left-align"><?php esc_html_e('Discount column value on table', 'woo-discount-rules')   ?></label>
                                                        <span class="wdr_settings_desc_text awdr-clear-both"><?php  esc_html_e('Show Discount value/price in table', 'woo-discount-rules');   ?></span>
                                                    </th>
                                                    <td>
                                                        <p><input type="radio" name="table_discount_column_value" id="show_table_discount_column_value" class="popup_table_discount_column_value"
                                                               value="1" <?php echo($configuration->getConfig('table_discount_column_value', 1) ? 'checked' : '') ?>><label
                                                                for="show_table_discount_column_value"><?php esc_html_e('Discount Value', 'woo-discount-rules');   ?></label></p>
                                                        <p><input type="radio" name="table_discount_column_value" id="dont_show_table_discount_column_value" class="popup_table_discount_column_value"
                                                               value="0" <?php echo(!$configuration->getConfig('table_discount_column_value', 1) ? 'checked' : '') ?>><label
                                                                for="dont_show_table_discount_column_value"><?php esc_html_e("Discounted Price", 'woo-discount-rules');  ?></label></p>
                                                    </td>
                                                </tr>
                                               <!-- <tr>
                                                    <th scope="row">
                                                        <label for=""><?php /*esc_html_e('Color Picker', 'woo-discount-rules')   */?></label>
                                                        <span style="float: right" class="wdr-tool-tip"
                                                              title="<?php /*esc_html_e("Rule name / title", 'woo-discount-rules');   */?>"> &#63</span>
                                                    </th>
                                                    <td>
                                                        <input type="color" id="colorpicker" name="color" pattern="^#+([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$" value="#bada55">
                                                        <input type="text" name="wdr_color_picker" pattern="^#+([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$" value="#bada55" id="hexcolor">
                                                    </td>
                                                </tr>-->
                                                </tbody>
                                            </table>
                                        </div>

                                        <div class="wdr_customize_table" style="background-color: #fff;"><?php
                                            $awdr_tbl_title = $configuration->getConfig('customize_bulk_table_title', 0);
                                            $awdr_tbl_range = $configuration->getConfig('customize_bulk_table_range', 1);
                                            $awdr_tbl_discount = $configuration->getConfig('customize_bulk_table_discount', 2);


                                            $awdr_tbl_title_text = $configuration->getConfig('table_title_column_name', 'Title');
                                            $awdr_tbl_discount_text = $configuration->getConfig('table_discount_column_name', 'Discount');
                                            $awdr_tbl_range_text = $configuration->getConfig('table_range_column_name', 'Range');

                                            $awdr_table_sort_by_columns = array(
                                                'tbl_title' => $awdr_tbl_title,
                                                'tbl_range' => $awdr_tbl_range,
                                                'tbl_discount' => $awdr_tbl_discount,
                                            );
                                            asort($awdr_table_sort_by_columns);
                                            ?>
                                            <table id="sort_customizable_table" class="wdr_bulk_table_msg sar-table">
                                                <thead class="wdr_bulk_table_thead">
                                                    <tr class="wdr_bulk_table_tr wdr_bulk_table_thead" style="">
                                                        <?php foreach ($awdr_table_sort_by_columns as $awdr_column => $order) {
                                                            if ($awdr_column == "tbl_title") {
                                                                ?>
                                                            <th id="customize-bulk-table-title" class="wdr_bulk_table_td popup_table_title_column awdr-dragable"
                                                                style="<?php if(!$configuration->getConfig('table_column_header', 0)){
                                                                    echo 'display:none';
                                                                }else{
                                                                    echo((!$configuration->getConfig('table_title_column', 0)) ? 'display:none' : '');
                                                                } ?>"><span class="title_on_keyup"><?php esc_html_e($awdr_tbl_title_text, 'woo-discount-rules')  // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText?></span>
                                                                </th><?php
                                                            } elseif ($awdr_column == "tbl_discount") {
                                                                ?>
                                                            <th id="customize-bulk-table-discount" class="wdr_bulk_table_td popup_table_discount_column awdr-dragable"
                                                                style="<?php if(!$configuration->getConfig('table_column_header', 0)){
                                                                    echo 'display:none';
                                                                }else{
                                                                    echo((!$configuration->getConfig('table_discount_column', 0)) ? 'display:none' : '');
                                                                } ?>"><span class="discount_on_keyup"><?php esc_html_e($awdr_tbl_discount_text, 'woo-discount-rules')  // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></span>
                                                                </th><?php
                                                            } else {
                                                                ?>
                                                            <th id="customize-bulk-table-range" class="wdr_bulk_table_td popup_table_range_column awdr-dragable"
                                                                style="<?php if(!$configuration->getConfig('table_column_header', 0)){
                                                                    echo 'display:none';
                                                                }else{
                                                                    echo((!$configuration->getConfig('table_range_column', 0)) ? 'display:none' : '');
                                                                }?>"><span class="range_on_keyup"><?php esc_html_e($awdr_tbl_range_text, 'woo-discount-rules')  // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></span></th><?php
                                                            }
                                                        }?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr class="wdr_bulk_table_tr bulk_table_row">
                                                        <?php foreach ($awdr_table_sort_by_columns as $awdr_column => $order) {
                                                            if ($awdr_column == "tbl_title") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_title popup_table_title_column col_index_1" data-colindex="1"
                                                                style="<?php echo (!$configuration->getConfig('table_title_column', 0)) ? 'display:none' : '';?>">
                                                                <?php esc_html_e('Bulk Rule', 'woo-discount-rules');   ?>
                                                                </td><?php

                                                            } elseif ($awdr_column == "tbl_discount") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_table_discount  popup_table_discount_column col_index_2" data-colindex="2"
                                                                style="<?php echo (!$configuration->getConfig('table_discount_column', 0)) ? 'display:none' : '';?>">
                                                                <span class="wdr_table_discounted_value" style="<?php echo ( !$configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                    echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(12));
                                                                    esc_html_e(' flat', 'woo-discount-rules');   ?></span>
                                                                <span class="wdr_table_discounted_price" style="<?php echo ( $configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                    echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(33)); ?></span>
                                                                </td><?php
                                                            } else {?>
                                                                <td class="wdr_bulk_table_td wdr_bulk_range popup_table_range_column col_index_3" data-colindex="3"
                                                                    style="<?php echo (!$configuration->getConfig('table_range_column', 0)) ? 'display:none':'';?>"><?php esc_html_e('1 - 5', 'woo-discount-rules');   ?></td><?php
                                                            }
                                                        }?>
                                                    </tr>
                                                    <tr class="wdr_bulk_table_tr bulk_table_row">
                                                        <?php foreach ($awdr_table_sort_by_columns as $awdr_column => $order) {
                                                            if ($awdr_column == "tbl_title") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_title popup_table_title_column col_index_1" data-colindex="1"
                                                                style="<?php echo (!$configuration->getConfig('table_title_column', 0)) ? 'display:none' : '';?>">
                                                                <?php esc_html_e('Bulk Rule', 'woo-discount-rules');   ?>
                                                                </td><?php

                                                            } elseif ($awdr_column == "tbl_discount") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_table_discount  popup_table_discount_column col_index_2" data-colindex="2"
                                                                style="<?php echo (!$configuration->getConfig('table_discount_column', 0)) ? 'display:none' : '';?>">
                                                                <span class="wdr_table_discounted_value" style="<?php echo ( !$configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>">
                                                                     14%
                                                                </span>
                                                                <span class="wdr_table_discounted_price" style="<?php echo ( $configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                    echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(38.70)); ?></span>
                                                                </td><?php
                                                            } else {?>
                                                                <td class="wdr_bulk_table_td wdr_bulk_range popup_table_range_column col_index_3" data-colindex="3"
                                                                    style="<?php echo (!$configuration->getConfig('table_range_column', 0)) ? 'display:none':'';?>"><?php esc_html_e('11 - 15', 'woo-discount-rules');  ?></td><?php
                                                            }
                                                        }?>
                                                    </tr>
                                                    <tr class="wdr_bulk_table_tr bulk_table_row">
                                                        <?php foreach ($awdr_table_sort_by_columns as $awdr_column => $order) {
                                                            if ($awdr_column == "tbl_title") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_title popup_table_title_column col_index_1" data-colindex="1"
                                                                style="<?php echo (!$configuration->getConfig('table_title_column', 0)) ? 'display:none' : '';?>">
                                                                <?php esc_html_e('Bulk Flat discount', 'woo-discount-rules');   ?>
                                                                </td><?php

                                                            } elseif ($awdr_column == "tbl_discount") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_table_discount  popup_table_discount_column col_index_2" data-colindex="2"
                                                                style="<?php echo (!$configuration->getConfig('table_discount_column', 0)) ? 'display:none' : '';?>">
                                                                <span class="wdr_table_discounted_value" style="<?php echo ( !$configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                    echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(10));
                                                                    esc_html_e(' flat', 'woo-discount-rules');  ?> </span>
                                                                <span class="wdr_table_discounted_price" style="<?php echo ( $configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                    echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(35)); ?></span>
                                                                </td><?php
                                                            } else {?>
                                                                <td class="wdr_bulk_table_td wdr_bulk_range popup_table_range_column col_index_3" data-colindex="3"
                                                                    style="<?php echo (!$configuration->getConfig('table_range_column', 0)) ? 'display:none':'';?>"><?php esc_html_e('50 - 60', 'woo-discount-rules');   ?></td><?php
                                                            }
                                                        }?>
                                                    </tr>
                                                    <tr class="wdr_bulk_table_tr bulk_table_row">
                                                        <?php foreach ($awdr_table_sort_by_columns as $awdr_column => $order) {
                                                            if ($awdr_column == "tbl_title") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_title popup_table_title_column col_index_1" data-colindex="1"
                                                                style="<?php echo (!$configuration->getConfig('table_title_column', 0)) ? 'display:none' : '';?>">
                                                                <?php esc_html_e('Bulk percentage discount', 'woo-discount-rules');   ?>
                                                                </td><?php

                                                            } elseif ($awdr_column == "tbl_discount") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_table_discount  popup_table_discount_column col_index_2" data-colindex="2"
                                                                style="<?php echo (!$configuration->getConfig('table_discount_column', 0)) ? 'display:none' : '';?>">
                                                                <span class="wdr_table_discounted_value" style="<?php echo ( !$configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>">
                                                                    10% </span>
                                                                <span class="wdr_table_discounted_price" style="<?php echo ( $configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                    echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(40.50)); ?></span>
                                                                </td><?php
                                                            } else {?>
                                                                <td class="wdr_bulk_table_td wdr_bulk_range popup_table_range_column col_index_3" data-colindex="3"
                                                                    style="<?php echo (!$configuration->getConfig('table_range_column', 0)) ? 'display:none':'';?>"><?php esc_html_e('70 - 80', 'woo-discount-rules');   ?></td><?php
                                                            }
                                                        }?>
                                                    </tr>
                                                    <tr class="wdr_bulk_table_tr bulk_table_row">
                                                        <?php foreach ($awdr_table_sort_by_columns as $awdr_column => $order) {
                                                            if ($awdr_column == "tbl_title") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_title popup_table_title_column col_index_1" data-colindex="1"
                                                                style="<?php echo (!$configuration->getConfig('table_title_column', 0)) ? 'display:none' : '';?>">
                                                                <?php esc_html_e('Bulk % discount', 'woo-discount-rules');   ?>
                                                                </td><?php

                                                            } elseif ($awdr_column == "tbl_discount") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_table_discount  popup_table_discount_column col_index_2" data-colindex="2"
                                                                style="<?php echo (!$configuration->getConfig('table_discount_column', 0)) ? 'display:none' : '';?>">
                                                                <span class="wdr_table_discounted_value" style="<?php echo ( !$configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>">
                                                                    50% </span>
                                                                <span class="wdr_table_discounted_price" style="<?php echo ( $configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                    echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(22.50)); ?></span>
                                                                </td><?php
                                                            } else {?>
                                                                <td class="wdr_bulk_table_td wdr_bulk_range popup_table_range_column col_index_3" data-colindex="3"
                                                                    style="<?php echo (!$configuration->getConfig('table_range_column', 0)) ? 'display:none':'';?>"><?php esc_html_e('450 - 500', 'woo-discount-rules');  ?></td><?php
                                                            }
                                                        }?>
                                                    </tr>
                                                    <tr class="wdr_bulk_table_tr bulk_table_row">
                                                        <?php foreach ($awdr_table_sort_by_columns as $awdr_column => $order) {
                                                            if ($awdr_column == "tbl_title") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_title popup_table_title_column col_index_1" data-colindex="1"
                                                                style="<?php echo (!$configuration->getConfig('table_title_column', 0)) ? 'display:none' : '';?>">
                                                                <?php esc_html_e('Bulk flat', 'woo-discount-rules');  ?>
                                                                </td><?php

                                                            } elseif ($awdr_column == "tbl_discount") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_table_discount  popup_table_discount_column col_index_2" data-colindex="2"
                                                                style="<?php echo (!$configuration->getConfig('table_discount_column', 0)) ? 'display:none' : '';?>">
                                                                <span class="wdr_table_discounted_value" style="<?php echo ( !$configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                    echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(10));
                                                                    esc_html_e(' flat', 'woo-discount-rules'); ?></span>
                                                                <span class="wdr_table_discounted_price" style="<?php echo ( $configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                    echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(35)); ?></span>
                                                                </td><?php
                                                            } else {?>
                                                                <td class="wdr_bulk_table_td wdr_bulk_range popup_table_range_column col_index_3" data-colindex="3"
                                                                    style="<?php echo (!$configuration->getConfig('table_range_column', 0)) ? 'display:none':'';?>"><?php esc_html_e('600 - 700', 'woo-discount-rules'); 
                                                                    ?></td><?php
                                                            }
                                                        }?>
                                                    </tr>
                                                    <tr class="wdr_bulk_table_tr bulk_table_row">
                                                        <?php foreach ($awdr_table_sort_by_columns as $awdr_column => $order) {
                                                            if ($awdr_column == "tbl_title") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_title popup_table_title_column col_index_1" data-colindex="1"
                                                                style="<?php echo (!$configuration->getConfig('table_title_column', 0)) ? 'display:none' : '';?>">
                                                                <?php esc_html_e('set percentage discount', 'woo-discount-rules'); 
                                                                ?>
                                                                </td><?php

                                                            } elseif ($awdr_column == "tbl_discount") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_table_discount  popup_table_discount_column col_index_2" data-colindex="2"
                                                                style="<?php echo (!$configuration->getConfig('table_discount_column', 0)) ? 'display:none' : '';?>">
                                                                <span class="wdr_table_discounted_value" style="<?php echo ( !$configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>">
                                                                   10%</span>
                                                                <span class="wdr_table_discounted_price" style="<?php echo ( $configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                    echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(40.50)); ?></span>
                                                                </td><?php
                                                            } else {?>
                                                                <td class="wdr_bulk_table_td wdr_bulk_range popup_table_range_column col_index_3" data-colindex="3"
                                                                    style="<?php echo (!$configuration->getConfig('table_range_column', 0)) ? 'display:none':'';?>"><?php esc_html_e('5', 'woo-discount-rules');   ?></td><?php
                                                            }
                                                        }?>
                                                    </tr>
                                                    <tr class="wdr_bulk_table_tr bulk_table_row">
                                                        <?php foreach ($awdr_table_sort_by_columns as $awdr_column => $order) {
                                                            if ($awdr_column == "tbl_title") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_title popup_table_title_column col_index_1" data-colindex="1"
                                                                style="<?php echo (!$configuration->getConfig('table_title_column', 0)) ? 'display:none' : '';?>">
                                                                <?php esc_html_e('Fixed discount for set', 'woo-discount-rules');  ?>
                                                                </td><?php

                                                            } elseif ($awdr_column == "tbl_discount") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_table_discount  popup_table_discount_column col_index_2" data-colindex="2"
                                                                style="<?php echo (!$configuration->getConfig('table_discount_column', 0)) ? 'display:none' : '';?>">
                                                                <span class="wdr_table_discounted_value" style="<?php echo ( !$configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                    echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(20)); ?></span>
                                                                <span class="wdr_table_discounted_price" style="<?php echo ( $configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                    echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(2)); ?></span>
                                                                </td><?php
                                                            } else {?>
                                                                <td class="wdr_bulk_table_td wdr_bulk_range popup_table_range_column col_index_3" data-colindex="3"
                                                                    style="<?php echo (!$configuration->getConfig('table_range_column', 0)) ? 'display:none':'';?>"><?php esc_html_e('10', 'woo-discount-rules');  ?></td><?php
                                                            }
                                                        }?>
                                                    </tr>
                                                    <tr class="wdr_bulk_table_tr bulk_table_row">
                                                        <?php foreach ($awdr_table_sort_by_columns as $awdr_column => $order) {
                                                            if ($awdr_column == "tbl_title") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_title popup_table_title_column col_index_1" data-colindex="1"
                                                                style="<?php echo (!$configuration->getConfig('table_title_column', 0)) ? 'display:none' : '';?>">
                                                                <?php esc_html_e('set flat discount', 'woo-discount-rules'); ?>
                                                                </td><?php

                                                            } elseif ($awdr_column == "tbl_discount") {?>
                                                            <td class="wdr_bulk_table_td wdr_bulk_table_discount  popup_table_discount_column col_index_2" data-colindex="2"
                                                                style="<?php echo (!$configuration->getConfig('table_discount_column', 0)) ? 'display:none' : '';?>">
                                                                <span class="wdr_table_discounted_value" style="<?php echo ( !$configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(30)); ?></span>
                                                                <span class="wdr_table_discounted_price" style="<?php echo ( $configuration->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php
                                                                echo  wp_kses_post(\Wdr\App\Helpers\Woocommerce::formatPrice(2));?> </span>
                                                                </td><?php
                                                            } else {?>
                                                                <td class="wdr_bulk_table_td wdr_bulk_range popup_table_range_column col_index_3" data-colindex="3"
                                                                    style="<?php echo (!$configuration->getConfig('table_range_column', 0)) ? 'display:none':'';?>"><?php esc_html_e('15', 'woo-discount-rules'); ?></td><?php
                                                            }
                                                        }?>
                                                    </tr>
                                                </tbody>
                                            </table>





                                            <p class="advanced_layout_preview"></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <br />
                            <a class="bulk-table-customized-setting wdr-model-bottom-btn btn btn-primary" style="text-decoration: none">Save</a>
                            <a class="close-modal wdr-model-bottom-btn btn btn-danger" style="text-decoration: none">Close</a>
                        </div>
                </div>
            </div>

                <!--Bulk Table Popup end-->


                <div class="save-configuration">
                    <input type="hidden" class="customizer_save_alert" name="customizer_save_alert" value="">
                    <input type="hidden" name="customize_bulk_table_title" class="customize_bulk_table_title" value="<?php echo esc_attr($configuration->getConfig('customize_bulk_table_title', 0)); ?>">
                    <input type="hidden" name="customize_bulk_table_discount" class="customize_bulk_table_discount" value="<?php echo esc_attr($configuration->getConfig('customize_bulk_table_discount', 2)); ?>">
                    <input type="hidden" name="customize_bulk_table_range" class="customize_bulk_table_range" value="<?php echo esc_attr($configuration->getConfig('customize_bulk_table_range', 1)); ?>">

                    <input type="hidden" name="method" value="save_configuration">
                   <!-- <input type="hidden" class="customize_banner_content" name="customize_banner_content" value="">-->
                    <input type="hidden" name="action" value="wdr_ajax">
                    <input type="hidden" name="awdr_nonce" value="<?php echo esc_attr(\Wdr\App\Helpers\Helper::create_nonce('wdr_ajax_save_configuration')); ?>">
                    <p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary save-configuration-submit"
                                             value="Save Changes"></p>
                </div>
            </form>
        </div>
    </div>





