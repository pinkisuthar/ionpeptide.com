<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>

<div class="awdr_product_adjustment_card_group">
    <div class="awdr_simple_discount awdr_common_border_class">
        <b><?php esc_html_e('Coupon based user role discount - sample', 'woo-discount-rules');?></b>
        <button class="button awdr_recipe_content" type="button" data-recipe-nonce="<?php echo esc_attr($recipe_nonce); ?>" data-select-recipe="simple_recipe_1"><?php esc_html_e('Create', 'woo-discount-rules');?></button>
        <a style="display: none;" class="button awdr_recipe_rule_edit" href="" target="_blank" ><?php esc_html_e('View & edit Rule', 'woo-discount-rules');?></a>

    </div>
    <div class="awdr_simple_discount awdr_common_border_class">
        <b><?php esc_html_e('Subtotal tiered discount - sample', 'woo-discount-rules');?></b>
        <button class="button awdr_recipe_content"  type="button" data-recipe-nonce="<?php echo esc_attr($recipe_nonce); ?>" data-select-recipe="simple_recipe_2"><?php esc_html_e('Create', 'woo-discount-rules');?></button>
        <a style="display: none;" class="button awdr_recipe_rule_edit" href="" target="_blank" ><?php esc_html_e('View & edit Rule', 'woo-discount-rules');?></a>
    </div>
</div>


