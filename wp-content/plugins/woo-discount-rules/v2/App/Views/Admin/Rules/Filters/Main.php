<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>
<!--Product Filter-->
<div class="wdr-filter-block" id="wdr-filter-block">
    <div class="wdr-block">
        <div class="wdr-row">
            <div class="wdr-filter-group-items">
                <input type="hidden" name="edit_rule"
                       value="<?php echo ($rule->getId()) ? esc_attr($rule->getId()) : ''; ?>"><?php
                if ($rule->hasFilter()) {
                    $awdr_filters = $rule->getFilter();
                    $awdr_filter_row_count = 1;
                    foreach ($awdr_filters as $awdr_filter) {
                        ?>
                        <div class="wdr-grid wdr-filter-group" data-index="<?php echo esc_attr($awdr_filter_row_count); ?>">
                            <div class="wdr-filter-type">
                                <select name="filters[<?php echo esc_attr($awdr_filter_row_count); ?>][type]"
                                        class="wdr-product-filter-type"><?php
                                    if (isset($product_filters) && !empty($product_filters)) {
                                        foreach ($product_filters as $awdr_filter_key => $awdr_filter_value) {
                                            ?>
                                            <optgroup label="<?php esc_attr_e($awdr_filter_key, 'woo-discount-rules');//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?>" ><?php
                                            foreach ($awdr_filter_value as $awdr_key => $awdr_value) {
                                                ?>
                                                <option
                                                <?php
                                                if(isset($awdr_value['active']) && $awdr_value['active'] == false){
                                                    ?>
                                                    disabled="disabled"
                                                    <?php
                                                } else {
                                                    ?>
                                                    value="<?php echo esc_attr($awdr_key); ?>"
                                                    <?php
                                                }
                                                ?>
                                                <?php echo ($awdr_filter->type == $awdr_key) ? 'selected' : ''; ?>><?php esc_attr_e($awdr_value['label'], 'woo-discount-rules');//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></option><?php
                                            } ?>
                                            </optgroup><?php
                                        }
                                    } ?>
                                </select>
                            </div>
                            <?php if ($awdr_filter->type != 'all_products') {?>
                                <div class="products_group wdr-products_group"><?php
                                    if(in_array($awdr_filter->type, array('products'))){
                                        ?>
                                        <div class="wdr-product_filter_method">
                                            <select name="filters[<?php echo esc_attr($awdr_filter_row_count); ?>][method]">
                                                <option value="in_list"
                                                    <?php echo (isset($awdr_filter->method) && $awdr_filter->method == 'in_list') ? 'selected' : ''; ?>><?php esc_html_e('In List', 'woo-discount-rules'); ?></option>
                                                <option value="not_in_list" <?php echo (isset($awdr_filter->method) && $awdr_filter->method == 'not_in_list') ? 'selected' : ''; ?>><?php esc_html_e('Not In List', 'woo-discount-rules'); ?></option>
                                            </select>
                                        </div>
                                        <div class="awdr-product-selector">
                                            <?php
                                            $awdr_placeholder = '';
                                            $awdr_selected_options = '';
                                            if (!empty($awdr_filter->value) && is_array($awdr_filter->value)) {
                                                $awdr_item_name = '';
                                                foreach ($awdr_filter->value as $awdr_option) {
                                                    switch ($awdr_filter->type) {
                                                        case 'products':
                                                            $awdr_item_name = esc_attr('#'.$awdr_option.' '.\Wdr\App\Helpers\Woocommerce::getTitleOfProduct($awdr_option));
                                                            $awdr_placeholder = __('Products', 'woo-discount-rules');
                                                            break;
                                                    }
                                                    if (!empty($awdr_item_name)) {
                                                        $awdr_option_value = esc_attr($awdr_option);
                                                        $awdr_selected_options .= "<option value={$awdr_option_value} selected>{$awdr_item_name}</option>";
                                                    }
                                                }
                                            }
                                            ?>
                                            <select multiple
                                                    class="edit-filters awdr_validation"
                                                    data-list="<?php echo esc_attr($awdr_filter->type); ?>"
                                                    data-field="autocomplete"
                                                    data-placeholder="<?php /* translators: %s replace placeholder */echo sprintf(esc_html__('Select %s', 'woo-discount-rules'),esc_attr($awdr_placeholder)); ?>"
                                                    name="filters[<?php echo esc_attr($awdr_filter_row_count); ?>][value][]">
                                                <?php echo wp_kses($awdr_selected_options,[
	                                                'option' => [
		                                                'value' => [],
		                                                'selected' => []
	                                                ]
                                                ]); ?>
                                            </select>
                                        </div>
                                        <?php
                                    }
                                    do_action('advanced_woo_discount_rules_admin_filter_fields', $rule, $awdr_filter, $awdr_filter_row_count);
                                    ?>
                                </div>
                            <?php } ?>
                            <div class="wdr-btn-remove wdr_filter_remove">
                                <span class="dashicons dashicons-no-alt remove-current-row wdr-filter-alert"></span>
                            </div><?php
                            switch($awdr_filter->type) {
                                case "products": ?>
                                    <div class="wdr_filter_desc_text"><span><?php esc_html_e('Choose products that get the discount using "In List". If you want to exclude a few products, choose "Not In List" and select the products you wanted to exclude from discount. (You can add multiple filters)', 'woo-discount-rules'); ?></span></div>
                                    <?php break;
                                case "product_category": ?>
                                    <div class="wdr_filter_desc_text"><span><?php esc_html_e('Choose categories that get the discount using "In List". If you want to exclude a few categories, choose "Not In List" and select the categories you wanted to exclude from discount. (You can add multiple filters of same type)', 'woo-discount-rules'); ?></span></div>
                                    <?php break;
                                case "product_attributes": ?>
                                   <div class="wdr_filter_desc_text"><span><?php esc_html_e('Choose attributes that get the discount using "In List". If you want to exclude a few attributes, choose "Not In List" and select the attributes you wanted to exclude from discount. (You can add multiple filters of same type)', 'woo-discount-rules'); ?></span></div>
                                    <?php break;
                                case "product_tags": ?>
                                    <div class="wdr_filter_desc_text"><span><?php esc_html_e('Choose tags that get the discount using "In List". If you want to exclude a few tags, choose "Not In List" and select the tags you wanted to exclude from discount. (You can add multiple filters of same type)', 'woo-discount-rules'); ?></span></div>
                                    <?php break;
                                case "product_sku": ?>
                                    <div class="wdr_filter_desc_text"><span><?php esc_html_e('Choose SKUs that get the discount using "In List". If you want to exclude a few SKUs, choose "Not In List" and select the SKUs you wanted to exclude from discount. (You can add multiple filters of same type)', 'woo-discount-rules'); ?></span></div>
                                    <?php break;
                                case "product_on_sale": ?>
                                    <div class="wdr_filter_desc_text"><span><?php esc_html_e('Choose whether you want to include (or exclude) products on sale (those having a sale price) for the discount ', 'woo-discount-rules'); ?></span></div>
                                    <?php break;
                                case "all_products": ?>
                                    <div class="wdr_filter_desc_text"><span><?php esc_html_e('Discount applies to all eligible products in the store', 'woo-discount-rules'); ?></span></div>
                                    <?php break;
                                default:
                                 ?>
                                     <div class="wdr_filter_desc_text"><span><?php esc_html_e('Discount applies to custom taxonomy', 'woo-discount-rules'); ?></span></div>
                                 <?php break;
                            }
                            ?>
                        </div>
                        <?php
                        $awdr_filter_row_count++;
                    }
                } else { ?>
                    <div class="wdr-grid wdr-filter-group" data-index="1">
                        <div class="wdr-filter-type wdr-filter-all-product">
                            <select name="filters[1][type]" class="wdr-product-filter-type"><?php
                                if (isset($product_filters) && !empty($product_filters)) {
                                    foreach ($product_filters as $awdr_filter_key => $awdr_filter_value) {
                                        ?>
                                        <optgroup label="<?php esc_attr_e($awdr_filter_key, 'woo-discount-rules');//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?>"><?php
                                        foreach ($awdr_filter_value as $awdr_key => $awdr_value) {
                                            ?>
                                            <option
                                            <?php
                                            if(isset($awdr_value['active']) && $awdr_value['active'] == false){
                                                ?>
                                                disabled="disabled"
                                                <?php
                                            } else {
                                                ?>
                                                value="<?php echo esc_attr($awdr_key); ?>"
                                                <?php
                                            }
                                            ?>
                                            ><?php esc_html_e($awdr_value['label'], 'woo-discount-rules');//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></option><?php
                                        } ?>
                                        </optgroup><?php
                                    }
                                } ?>
                            </select>
                        </div>
                        <div class="wdr-btn-remove wdr_filter_remove">
                            <span class="dashicons dashicons-no-alt remove-current-row wdr-filter-alert"></span>
                        </div>
                        <div class="wdr_filter_desc_text">
                            <span>
                                <?php esc_html_e('Discount applies to all eligible products in the store', 'woo-discount-rules'); ?>
                            </span>
                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
        <div class="wdr-add-condition add-condition-and-filters">
            <button type="button"
                    class="button add-product-filter"><?php esc_html_e('Add filter', 'woo-discount-rules'); ?></button>
        </div>
    </div>
</div>

