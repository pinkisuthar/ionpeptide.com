<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

$awdr_cart_quantity_promotion_from = isset($options->cart_quantity_promotion_from) ? $options->cart_quantity_promotion_from : false;
$awdr_cart_quantity_promotion_message = isset($options->cart_quantity_promotion_message) ? wp_unslash($options->cart_quantity_promotion_message) : false;
echo ($render_saved_condition == true) ? '' : '<div class="wdr-cart-quantity-promo-messeage-main">';
$awdr_condition_index = ($render_saved_condition == true && isset($i)) ? $i : '{i}';
?>
    <div class="wdr_cart_quantity_promotion_container" style="display: grid;">
        <label style="padding-bottom: 20px;"><b><?php esc_html_e('Promotion Message', 'woo-discount-rules'); ?></b></label>
        <div class="wdr_cart_cart_quantity_promo_from">
            <label class="awdr-left-align wdr_cart_quantity_promo_filed_name" style="padding-right: 5px;"><?php esc_html_e('Quantity from', 'woo-discount-rules'); ?></label>
            <input name="conditions[<?php echo esc_attr($awdr_condition_index) ?>][options][cart_quantity_promotion_from]"
                   type="text" class="float_only_field awdr-left-align"
                   value="<?php echo ($awdr_cart_quantity_promotion_from) ? esc_attr($awdr_cart_quantity_promotion_from) : '' ?>"
                   placeholder="<?php esc_html_e('0', 'woo-discount-rules');?>"
                   min="0">
            <span class="wdr_desc_text awdr-clear-both"><?php esc_html_e('Set a threshold from which you want to start showing promotion message', 'woo-discount-rules'); ?></span>
            <span class="wdr_desc_text awdr-clear-both"><?php echo wp_kses_post(__("<b>Example:</b> Let's say you offer a 10% discount for 5 quantities and above. you may want to set 3 here. So that the customer can see the promo text when his cart quantities reaches 3", 'woo-discount-rules')); ?></span>
        </div>
        <div class="wdr_cart_quantity_promo_msg">
            <p class="wdr_cart_quantity_promo_filed_name"><?php esc_html_e('Message', 'woo-discount-rules'); ?></p>
            <textarea
                name="conditions[<?php echo esc_attr($awdr_condition_index) ?>][options][cart_quantity_promotion_message]"
                style="height: 60px;"
                placeholder="<?php esc_html_e('Buy {{difference_quantity}} more products and get 10% discount', 'woo-discount-rules'); ?>"><?php echo wp_kses_post(($awdr_cart_quantity_promotion_message) ? $awdr_cart_quantity_promotion_message : ''); ?></textarea>
        <span class="wdr_desc_text awdr-clear-both"><?php esc_html_e('{{difference_quantity}} -> Difference amount to get discount', 'woo-discount-rules'); ?></span>
        <span class="wdr_desc_text awdr-clear-both"><?php echo wp_kses_post(__('<b>Eg:</b> Buy {{difference_quantity}} more products and get 10% discount', 'woo-discount-rules')); ?></span>
    </div>
</div><?php
echo ($render_saved_condition == true) ? '' : '</div>'; ?>

