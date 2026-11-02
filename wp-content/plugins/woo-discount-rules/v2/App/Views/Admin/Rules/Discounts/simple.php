<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
$awdr_is_pro = \Wdr\App\Helpers\Helper::hasPro();
?>
<div class="wdr_simple_discount">
    <div class="wdr-discount-group" data-index="{i}">
        <div class="wdr-simple-discount-main">
            <div class="wdr-simple-discount-inner">
                <div class="simple_discount_option wdr-select-filed-hight">
                    <select name="product_adjustments[type]" class="product_discount_option  awdr-left-align">
                        <option value="percentage" <?php echo (isset($awdr_product_adjustments->type) && $awdr_product_adjustments->type == 'percentage') ? 'selected' : ''; ?>><?php esc_html_e('Percentage discount', 'woo-discount-rules'); ?></option>
                        <option value="flat" <?php echo (isset($awdr_product_adjustments->type) && $awdr_product_adjustments->type == 'flat') ? 'selected' : ''; ?>><?php esc_html_e('Fixed discount', 'woo-discount-rules'); ?></option>
                        <?php if($awdr_is_pro){ ?>
                            <option value="fixed_price" <?php echo (isset($awdr_product_adjustments->type) && $awdr_product_adjustments->type == 'fixed_price') ? 'selected' : ''; ?>><?php esc_html_e('Fixed price per item', 'woo-discount-rules'); ?></option>
                        <?php } else {
                            ?>
                            <option disabled><?php esc_html_e('Fixed price per item -PRO-', 'woo-discount-rules'); ?></option>
                        <?php
                        }?>
                    </select>
                    <span class="wdr_desc_text awdr-clear-both"><?php esc_html_e('Discount Type', 'woo-discount-rules'); ?></span>
                </div>
                <div class="simple_discount_value wdr-input-filed-hight">
                    <input name="product_adjustments[value]"
                           type="number"
                           class="product_discount_value"
                           value="<?php echo (isset($awdr_product_adjustments->value) && $awdr_product_adjustments->value >= 0) ? esc_attr(floatval($awdr_product_adjustments->value)) : ''; ?>"
                           placeholder="0.00" min="0" step="any" style="width: 100%;">
                    <span class="wdr_desc_text"><?php esc_html_e('Value', 'woo-discount-rules'); ?></span>
                </div>
            </div><?php
            $awdr_is_enabled_rtl = \Wdr\App\Helpers\Woocommerce::isRTLEnable();?>
            <div class="apply_discount_as_cart_section">
                <div class="apply_as_cart_checkbox awdr_rtl_compatible <?php echo (!$awdr_is_enabled_rtl) ? 'page__toggle' : ''; ?>">
                    <label class="<?php echo (!$awdr_is_enabled_rtl) ? 'toggle' : ''; ?>">
                        <input class="<?php echo (!$awdr_is_enabled_rtl) ? 'toggle__input' : ''; ?> apply_fee_coupon_checkbox" type="checkbox"
                               name="product_adjustments[apply_as_cart_rule]" <?php echo (isset($awdr_product_adjustments->apply_as_cart_rule) && !empty($awdr_product_adjustments->apply_as_cart_rule)) ? 'checked' : '' ?> value="1">
                        <span class="<?php echo (!$awdr_is_enabled_rtl) ? 'toggle__label' : ''; ?>"><span
                                    class="<?php echo (!$awdr_is_enabled_rtl) ? 'toggle__text toggle_tic' : ''; ?> "><?php esc_html_e('Show discount in cart as coupon instead of changing the product price ?', 'woo-discount-rules'); ?></span></span>
                    </label>
                </div>
                <div class="simple_discount_value wdr-input-filed-hight apply_fee_coupon_label" style="<?php echo (isset($awdr_product_adjustments->apply_as_cart_rule) && !empty($awdr_product_adjustments->apply_as_cart_rule)) ? '' : 'display: none;' ?> <?php echo ($awdr_is_enabled_rtl) ? 'padding-top: 0px !important;' : ''; ?>">
                    <input name="product_adjustments[cart_label]"
                           type="text"
                           value="<?php echo (isset($awdr_product_adjustments->cart_label)) ? esc_attr(wp_unslash($awdr_product_adjustments->cart_label)) : ''; ?>"
                           placeholder="<?php esc_attr_e('Discount Label', 'woo-discount-rules'); ?>">
                </div>
            </div>
        </div>
    </div>
</div>