<?php
/**
 * Auto add variation option
 *
 * This template can be overridden by copying it to yourtheme/woo-discount-rules-pro/buy-x-get-y-select-auto-add-variant.php.
 *
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}?>
<div class="awdr-select-free-variant-product-toggle"><?php esc_html_e('Change Variant', 'woo-discount-rules-pro') ?></div>
<div class="awdr-select-variant-product">
<?php
foreach ($available_products as $wdrpro_available_product) { //parent_id
    if ($wdrpro_available_product != $customer_product_choice['chosen']) {
        $wdrpro_product_variation = new WC_Product_Variation( $wdrpro_available_product );
        $wdrpro_is_available = \WDRPro\App\Rules\BOGO::isVariantPurchasableForBXGY($wdrpro_product_variation, 1, $customer_product_choice['parent_product_id'], $wdrpro_available_product);
        if($wdrpro_is_available){
            // get variation featured image
            $wdrpro_variation_image = $wdrpro_product_variation->get_image(array( 50, 50));
            // get variation name with attributes
            // Fix - variation name issue, if variation contains more than two attributes
            $wdrpro_variation_name_include_attributes = \WDRPro\App\Helpers\CoreMethodCheck::getTitleOfProduct($wdrpro_product_variation);
            ?>
            <div class="awdr_free_product_variants">
            <span class="awdr_change_product" data-pid="<?php echo esc_attr($wdrpro_available_product); ?>"
                  data-rule_id="<?php echo esc_attr($customer_product_choice['matched_rule_identification']); ?>"
                  data-parent_id="<?php echo esc_attr($customer_product_choice['parent_product_id']); ?>"><span class="awdr_variation_image"><?php echo wp_kses_post($wdrpro_variation_image); ?></span><span class="awdr-product-name"><?php echo esc_html($wdrpro_variation_name_include_attributes); ?></span></span>
            </div>
            <?php
        }
    }
}
?>
</div>

