<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
$wdrpro_common_filters = array('product_category', 'product_attributes',
    'product_tags', 'product_sku');
$wdrpro_placeholder = '';
if (in_array($filter->type, $wdrpro_common_filters)) {
    ?>
    <div class="wdr-product_filter_method">
        <select name="filters[<?php echo esc_attr($filter_row_count); ?>][method]">
            <option value="in_list"
                <?php echo (isset($filter->method) && $filter->method == 'in_list') ? 'selected' : ''; ?>><?php esc_html_e('In List', 'woo-discount-rules-pro'); ?></option>
            <option value="not_in_list" <?php echo (isset($filter->method) && $filter->method == 'not_in_list') ? 'selected' : ''; ?>><?php esc_html_e('Not In List', 'woo-discount-rules-pro'); ?></option>
        </select>
    </div>
    <div class="awdr-product-selector">

    <?php
    $wdrpro_item_name = '';
    $wdrpro_selected_options = '';
    if (!empty($filter->value) && is_array($filter->value)) {
        foreach ($filter->value as $wdrpro_option) {
            switch ($filter->type) {
                case 'product_category':
                    //$wdrpro_term_name = get_term_by('term_taxonomy_id', $wdrpro_option);
                    $wdrpro_term = get_term($wdrpro_option);
                    $wdrpro_parant_name = '';
                    if(!empty($wdrpro_term)) {
                        if (!empty($wdrpro_term->parent)) {
                            if (function_exists('get_the_category_by_ID')) {
                                $wdrpro_parant_names = get_the_category_by_ID((int)$wdrpro_term->parent);
                                $wdrpro_parant_name = $wdrpro_parant_names . ' -> ';
                                $wdrpro_parant_category = get_term((int)$wdrpro_term->parent);
                                if (is_object($wdrpro_parant_category) && !empty($wdrpro_parant_category->parent)) {
                                    $wdrpro_grant_parant_names = get_the_category_by_ID((int)$wdrpro_parant_category->parent);
                                    $wdrpro_parant_name = $wdrpro_grant_parant_names . ' -> ' . $wdrpro_parant_names . ' -> ';
                                    $wdrpro_grant_parant_category = get_term((int)$wdrpro_parant_category->parent);
                                    if (is_object($wdrpro_grant_parant_category) && !empty($wdrpro_grant_parant_category->parent)) {
                                        $wdrpro_grant_grant_parant_names = get_the_category_by_ID((int)$wdrpro_grant_parant_category->parent);
                                        $wdrpro_parant_name = $wdrpro_grant_grant_parant_names . ' -> ' . $wdrpro_grant_parant_names . ' -> ' . $wdrpro_parant_names . ' -> ';
                                    }
                                }

                            }
                        }
                        $wdrpro_item_name = $wdrpro_parant_name . $wdrpro_term->name;
                        $wdrpro_placeholder = 'Categories';
                    }
                    break;
                case 'product_attributes':
                    global $wc_product_attributes;
                    foreach (array_keys($wc_product_attributes) as $wdrpro_att_key) {
                        $wdrpro_attribute_name = '';
                        $wdrpro_att_object = get_term_by('id', $wdrpro_option, $wdrpro_att_key);
                        $wdrpro_tax_attribute = isset($wdrpro_att_object->taxonomy) ? $wdrpro_att_object->taxonomy : '';
                        if(!empty($wdrpro_tax_attribute)) {
                           $wdrpro_attribute_name =  isset($wc_product_attributes[$wdrpro_tax_attribute]->attribute_label) ? $wc_product_attributes[$wdrpro_tax_attribute]->attribute_label. ': ' : '';
                        }
                        if (!empty($wdrpro_att_object) && is_object($wdrpro_att_object)) {
                            $wdrpro_item_name = $wdrpro_attribute_name.$wdrpro_att_object->name;
                        }
                    }
                    $wdrpro_placeholder = 'Attributes';
                    break;
                case 'product_tags':
                    $wdrpro_term_name = get_term_by('id', $wdrpro_option, 'product_tag');
                    if (!empty($wdrpro_term_name)) {
                        $wdrpro_item_name = $wdrpro_term_name->name;
                    }
                    $wdrpro_placeholder = 'Tags';
                    break;
                case  'product_sku':
                    $wdrpro_item_name = 'SKU: ' . $wdrpro_option;
                    $wdrpro_placeholder = 'SKUs';
                    break;
                default:
                    $wdrpro_term_name = get_term_by('id', $wdrpro_option, $filter->type);
                    if (!empty($wdrpro_term_name)) {
                        $wdrpro_item_name = $wdrpro_term_name->name;
                    }
                    $wdrpro_placeholder = 'Values';
                    break;
            }
            if (!empty($wdrpro_item_name)) {
                $wdrpro_option_value = esc_attr($wdrpro_option);
                $wdrpro_option_html = esc_html($wdrpro_item_name);
                $wdrpro_selected_options .= "<option value='{$wdrpro_option_value}' selected>{$wdrpro_option_html}</option>";
            }
        }
    }
    ?>
    <select multiple
            class="edit-filters awdr_validation"
            data-list="<?php echo esc_attr($filter->type); ?>"
            data-field="autocomplete"
            data-placeholder="<?php /* translators: %s replace placeholder */ echo sprintf(esc_html__('Select %s', 'woo-discount-rules-pro'), esc_attr($wdrpro_placeholder)); ?>"
            name="filters[<?php echo esc_attr($filter_row_count); ?>][value][]">
        <?php echo wp_kses($wdrpro_selected_options, ['option' => ['value' => [], 'selected' => []]]); ?>
    </select>
    </div><?php
} else if($filter->type == 'product_on_sale'){
    ?>
    <div class="wdr-product_filter_method">
        <select name="filters[<?php echo esc_attr($filter_row_count); ?>][method]">
            <option value="in_list"
                <?php echo (isset($filter->method) && $filter->method == 'in_list') ? 'selected' : ''; ?>><?php esc_html_e('Include', 'woo-discount-rules-pro'); ?></option>
            <option value="not_in_list" <?php echo (isset($filter->method) && $filter->method == 'not_in_list') ? 'selected' : ''; ?>><?php esc_html_e('Exclude', 'woo-discount-rules-pro'); ?></option>
        </select>
    </div>
    <?php
} else if (in_array($filter->type, array_keys($woocommerce_helper->getCustomProductTaxonomies()))) {
    ?>
    <div class="wdr-product_filter_method">
        <select name="filters[<?php echo esc_attr($filter_row_count); ?>][method]">
            <option value="in_list"
                <?php echo (isset($filter->method) && $filter->method == 'in_list') ? 'selected' : ''; ?>><?php esc_html_e('In List', 'woo-discount-rules-pro'); ?></option>
            <option value="not_in_list" <?php echo (isset($filter->method) && $filter->method == 'not_in_list') ? 'selected' : ''; ?>><?php esc_html_e('Not In List', 'woo-discount-rules-pro'); ?></option>
        </select>
    </div>
    <div class="awdr-product-selector">
        <?php
        if (!empty($filter->value) && is_array($filter->value)) {
            $wdrpro_item_name = '';
            $wdrpro_placeholder = '';
            $wdrpro_selected_options = '';
            foreach ($filter->value as $wdrpro_option) {
                $wdrpro_term_name = get_term_by('id', $wdrpro_option, $filter->type);
                if (!empty($wdrpro_term_name)) {
                    $wdrpro_parant_name = '';
                    if(!empty($wdrpro_term_name->parent)){
                        if (function_exists('get_the_category_by_ID')) {
                            $wdrpro_parant_names = get_the_category_by_ID((int)$wdrpro_term_name->parent);
                            $wdrpro_parant_name = $wdrpro_parant_names . ' -> ';
                        }
                    }
                    $wdrpro_item_name = $wdrpro_parant_name.$wdrpro_term_name->name;
                }
                $wdrpro_placeholder = 'Values';
                if (!empty($wdrpro_item_name)) {
                    $wdrpro_option_value = esc_attr($wdrpro_option);
                    $wdrpro_option_html = esc_html($wdrpro_item_name);
                    $wdrpro_selected_options .= "<option value='{$wdrpro_option_value}' selected>{$wdrpro_option_html}</option>";
                }
            }
        }
        ?>
        <select multiple
                class="edit-filters awdr_validation"
                data-list="product_taxonomies"
                data-taxonomy="<?php echo esc_attr($filter->type); ?>"
                data-field="autocomplete"
                data-placeholder="<?php /* translators: %s replace placeholder */ echo sprintf(esc_html__('Select %s', 'woo-discount-rules-pro'), esc_attr($wdrpro_placeholder)); ?>"
                name="filters[<?php echo esc_attr($filter_row_count); ?>][value][]">
            <?php echo wp_kses($wdrpro_selected_options, ['option' => ['value' => [], 'selected' => []]]); ?>
        </select>
    </div>
    <?php
}
?>