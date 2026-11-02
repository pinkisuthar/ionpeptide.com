<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>
<div class="product_on_sale wdr-condition-type-options">
    <div class="products_group wdr-products_group">
        <div class="wdr-product_filter_method">
            <select name="filters[{i}][method]">
                <option value="not_in_list" selected><?php esc_html_e('Exclude', 'woo-discount-rules-pro'); ?></option>
                <option value="in_list"><?php esc_html_e('Include', 'woo-discount-rules-pro'); ?></option>
            </select>
        </div>
    </div>
</div>