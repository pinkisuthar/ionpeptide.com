<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>
<div class="product_category wdr-condition-type-options">
    <div class="product_category_group products_group wdr-products_group">
        <div class="wdr-product_filter_method">
            <select name="filters[{i}][method]">
                <option value="in_list" selected><?php esc_html_e('In List', 'woo-discount-rules-pro'); ?></option>
                <option value="not_in_list"><?php esc_html_e('Not In List', 'woo-discount-rules-pro'); ?></option>
            </select>
        </div>
        <div class="awdr-product-selector">
            <select multiple
                    class="awdr_validation"
                    data-list="product_category"
                    data-field="autocomplete"
                    data-placeholder="<?php esc_attr_e('Select Categories', 'woo-discount-rules-pro');?>"
                    name="filters[{i}][value][]">
            </select>
        </div>
    </div>
</div>