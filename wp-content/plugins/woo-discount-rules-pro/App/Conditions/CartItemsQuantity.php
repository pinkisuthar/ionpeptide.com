<?php

namespace WDRPro\App\Conditions;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Conditions\Base;
use Wdr\App\Controllers\DiscountCalculator;
use Wdr\App\Helpers\Helper;
use Wdr\App\Controllers\Configuration;

class CartItemsQuantity extends Base
{
    public function __construct()
    {
        parent::__construct();
        $this->name = 'cart_items_quantity';
        $this->label = __('Item quantity', 'woo-discount-rules-pro');
        $this->group = __('Cart', 'woo-discount-rules-pro');
        $this->template = WDR_PRO_PLUGIN_PATH . 'App/Views/Admin/Conditions/Cart/cart-quantity.php';
    }

    function check($cart, $options)
    {
        $total_quantities = 0;
        $operator = (isset($options->operator)) ? sanitize_text_field($options->operator) : '';
        $value = (isset($options->value)) ? $options->value : '';
        if(empty($cart)){
            if( apply_filters('advanced_woo_discount_rules_run_cart_quantity_promotiom_message_when_cart_is_empty', true, $cart, $options)){
                $this->processPromotion($operator, $options, $total_quantities, $value);
            }
            return false;
        }

        if (!empty($cart)) {
            if($options->calculate_from == 'from_filter'){
                $total_quantities = DiscountCalculator::getFilterBasedCartQuantities('cart_quantities', $this->rule);
            }else{
                foreach ($cart as $cart_item) {
                    if(Helper::isCartItemConsideredForCalculation(true, $cart_item, "cart_item_qty_condition")){
                        $total_quantities += $this->rule->getCartItemQuantity($cart_item);
                    }
                }
            }
        }
        if (!empty($operator) && !empty($total_quantities) && $value >= 0) {
            $status = $this->doComparisionOperation($operator, $total_quantities, $value);
            if(!$status){
                $config = new Configuration();
                if($config->getConfig('show_cart_quantity_promotion', '') == 1){
                    $this->processPromotion($operator, $options, $total_quantities, $value);
                }
            }
            return $status;
        }
        return false;
    }

    /**
     *  Process promotion
     *
     * @param $operator
     * @param $options
     * @param $total_quantities
     * @param $min_quantity
     */
    function processPromotion($operator, $options, $total_quantities, $min_quantity)
    {
        if(in_array($operator, array('greater_than', 'greater_than_or_equal'))){
            if(!empty($options->cart_quantity_promotion_from) && !empty($options->cart_quantity_promotion_message)){
                if($options->cart_quantity_promotion_from <= $total_quantities){
                    $min_quantity = ($operator == 'greater_than') ? intval($min_quantity) + 1 : $min_quantity;
                    $difference_quantity = $min_quantity - $total_quantities;
                    if($difference_quantity > 0){
                        $message = __($options->cart_quantity_promotion_message, 'woo-discount-rules-pro');//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- admin-entered promotion message passed through for translation plugins
                        $message = str_replace('{{difference_quantity}}', $difference_quantity, $message);
                        $rule_id = $this->rule->rule->id.'_cart_quantity';
                        Helper::setPromotionMessage($message, $rule_id);
                    }
                }
            }
        }
    }
}
