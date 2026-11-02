<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use Wdr\App\Helpers\Template;
$awdr_template_helper = new Template();
?>
<br>
<div id="wpbody-content" class="awdr-container" style="background-color: #ffffff;">
    <?php if($is_pro) { ?>
        <div class="awdr-header_text_recipe">
            <p>
                <?php esc_html_e('Use the sample recipes below to create discount rules easily. These are example rules for some of the popular discount scenarios. You can use these recipes to get started quickly.', 'woo-discount-rules'); ?>
            </p>
        </div>
        <div class="awdr_recipe_container">
            <div class="awdr_recipe_left">
                <div class="awdr_recipe_main_card" data-recipe-group="awdr_product_adjustment_card_group">
                    <div class="awdr_product_adjustment_card_container" >
                        <h4><b> <?php esc_html_e('Simple Discount', 'woo-discount-rules');?></b></h4>
                        <p><?php esc_html_e('Product adjustment ', 'woo-discount-rules');?></p>
                    </div>
                </div>
                <div class="awdr_recipe_main_card" data-recipe-group="awdr_set_adjustment_card_group">
                    <div class="awdr_set_adjustment_card_container">
                        <h4><b><?php esc_html_e('Bundle(set) Discount', 'woo-discount-rules');?></b></h4>
                        <p><?php esc_html_e('Bundle(set) Adjustment', 'woo-discount-rules');?></p>
                    </div>
                </div>
                <div class="awdr_recipe_main_card" data-recipe-group="awdr_bogo_adjustment_card_group">
                    <div class="awdr_bogo_adjustment_card_container">
                        <h4><b><?php esc_html_e('BOGO Discount', 'woo-discount-rules');?></b></h4>
                        <p><?php esc_html_e('BOGO Adjustment', 'woo-discount-rules');?></p>
                    </div>
                </div>
            </div>
            <div class="awdr_recipe_right">
                <div class="awdr_recipe_main_content">
                    <?php
                        $awdr_recipe_nonce = \Wdr\App\Helpers\Helper::create_nonce('common_recipe_nonce');
                        $awdr_params['recipe_nonce'] = $awdr_recipe_nonce;
                        $awdr_template_helper->setPath(WDR_PLUGIN_PATH . 'App/Views/Admin/Tabs/RecipeTypes/SimpleDiscountRecipe.php' )->setData($awdr_params)->display();
                        $awdr_template_helper->setPath(WDR_PLUGIN_PATH . 'App/Views/Admin/Tabs/RecipeTypes/CartDiscountRecipe.php' )->setData($awdr_params)->display();
                        $awdr_template_helper->setPath(WDR_PLUGIN_PATH . 'App/Views/Admin/Tabs/RecipeTypes/BulkDiscountRecipe.php' )->setData($awdr_params)->display();
                        $awdr_template_helper->setPath(WDR_PLUGIN_PATH . 'App/Views/Admin/Tabs/RecipeTypes/BundleDiscountRecipe.php' )->setData($awdr_params)->display();
                        $awdr_template_helper->setPath(WDR_PLUGIN_PATH . 'App/Views/Admin/Tabs/RecipeTypes/BogoDiscountRecipe.php' )->setData($awdr_params)->display();
                    ?>
                </div>
            </div>
        </div>
        <?php
    }else{ ?>
       <p> <?php  echo wp_kses_post(__("Unlock this feature by <a href='https://www.flycart.org/products/wordpress/woocommerce-discount-rules?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=unlock_pro' target='_blank'>Upgrading to Pro</a>", 'woo-discount-rules')); ?></p>
    <?php } ?>
</div>

