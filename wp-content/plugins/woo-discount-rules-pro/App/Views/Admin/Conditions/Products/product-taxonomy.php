<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

foreach (\Wdr\App\Helpers\Woocommerce::getCustomProductTaxonomies() as $taxonomy):
    $wdrpro_operator = isset($options->operator) ? $options->operator : 'in_list';
    $wdrpro_cartqty = isset($options->cartqty) ? $options->cartqty : 'greater_than_or_equal';
    $wdrpro_values = isset($options->value) ? $options->value : false;
    $wdrpro_taxonomy_name = isset($taxonomy->name) ? $taxonomy->name : "";
    $wdrpro_custom_taxonomy_name_pass = false;
    if(!isset($custom_taxonomy_type_on_edit)){
        $custom_taxonomy_type_on_edit = null;//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared template variable set by core plugin Conditions/Main.php
    }
    if(is_null($custom_taxonomy_type_on_edit)){
        $custom_taxonomy_type_on_edit = "wdr_cart_item_". esc_attr($wdrpro_taxonomy_name);//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared template variable set by core plugin Conditions/Main.php
        $wdrpro_custom_taxonomy_name_pass = true;
    }
    if(isset($custom_taxonomy_type_on_edit) && !empty($custom_taxonomy_type_on_edit) && $custom_taxonomy_type_on_edit != "wdr_cart_item_".esc_attr($wdrpro_taxonomy_name)){
        continue;
    }
    if($wdrpro_custom_taxonomy_name_pass){
        $custom_taxonomy_type_on_edit = null;//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared template variable set by core plugin Conditions/Main.php
        $wdrpro_custom_taxonomy_name_pass = false;
    }
    echo ($render_saved_condition == true) ? '' : '<div class="wdr_cart_item_' . esc_attr($wdrpro_taxonomy_name). '">'; ?>

<div class="<?php echo 'custom_taxonomy_' . esc_attr($wdrpro_taxonomy_name) . '_group'; ?>  wdr-condition-type-options" id="condition_tax_group">
    <div class="wdr-product_filter_method wdr-select-filed-hight ">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][operator]" class="awdr-left-align">
            <option value="in_list" <?php echo ($wdrpro_operator == "in_list") ? "selected" : ""; ?>><?php esc_html_e('In List', 'woo-discount-rules-pro'); ?></option>
            <option value="not_in_list" <?php echo ($wdrpro_operator == "not_in_list") ? "selected" : ""; ?>><?php esc_html_e('Not In List', 'woo-discount-rules-pro'); ?></option>
        </select>
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Taxonomy should be', 'woo-discount-rules-pro'); ?></span>
    </div>

    <div class="wdr-product-tax-selector wdr-select-filed-hight wdr-search-box">
        <select multiple
                class="<?php echo ($render_saved_condition == true) ? 'edit-filters' : '' ?>"
                data-list="product_taxonomies"
                data-taxonomy="<?php echo esc_attr($wdrpro_taxonomy_name); ?>"
                data-field="autocomplete"
                data-placeholder="<?php esc_attr_e('Search Taxonomies', 'woo-discount-rules-pro'); ?>"
                name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][value][]"><?php
            if ($wdrpro_values) {
                $wdrpro_custome_taxonomy_name = str_replace("wdr_cart_item_", "", $type);
                foreach ($wdrpro_values as $wdrpro_value) {
                    $wdrpro_term_name = get_term_by('id', $wdrpro_value, $wdrpro_custome_taxonomy_name);
                    if (!empty($wdrpro_term_name)) {
                        $wdrpro_parant_name = '';
                        if(isset($wdrpro_term_name->parent) && !empty($wdrpro_term_name->parent)){
                            if (function_exists('get_the_category_by_ID')) {
                                $wdrpro_parant_names = get_the_category_by_ID((int)$wdrpro_term_name->parent);
                                $wdrpro_parant_name = $wdrpro_parant_names . ' -> ';
                            }
                        }
                        $wdrpro_item_name = $wdrpro_parant_name.$wdrpro_term_name->name; ?>
                        <option value="<?php echo esc_attr($wdrpro_value); ?>" selected><?php echo esc_html($wdrpro_item_name); ?></option><?php
                    }
                }
            } ?>
        </select>
        <span class="wdr_select2_desc_text"><?php esc_html_e('Select Taxonomies', 'woo-discount-rules-pro'); ?></span>
    </div>
    <div class="wdr-product-tax wdr-select-filed-hight">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][cartqty]" class="awdr-left-align">
            <option value="less_than_or_equal" <?php echo ($wdrpro_cartqty == "less_than_or_equal") ? "selected" : ""; ?>><?php esc_html_e('Less than or equal ( &lt;= )', 'woo-discount-rules-pro') ?></option>
            <option value="less_than" <?php echo ($wdrpro_cartqty == "less_than") ? "selected" : ""; ?>><?php esc_html_e('Less than ( &lt; )', 'woo-discount-rules-pro') ?></option>
            <option value="greater_than_or_equal" <?php echo ($wdrpro_cartqty == "greater_than_or_equal") ? "selected" : ""; ?>><?php esc_html_e('Greater than or equal ( &gt;= )', 'woo-discount-rules-pro') ?></option>
            <option value="greater_than" <?php echo ($wdrpro_cartqty == "greater_than") ? "selected" : ""; ?>><?php esc_html_e('Greater than ( &gt; )', 'woo-discount-rules-pro') ?></option>
        </select>
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Categories Quantity In Cart', 'woo-discount-rules-pro'); ?></span>
    </div>
    <div class="wdr-product_filter_qty wdr-input-filed-hight">
        <input type="number" placeholder="<?php esc_attr_e('qty', 'woo-discount-rules-pro'); ?>" min="0" step="any"
               name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][qty]"
               class="awdr-left-align"
               value="<?php echo isset($options->qty) ? esc_attr($options->qty) : '1'; ?>">
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Category Quantity', 'woo-discount-rules-pro'); ?></span>
    </div>
    </div><?php
    echo ($render_saved_condition == true) ? '' : '</div>';
endforeach; ?>
