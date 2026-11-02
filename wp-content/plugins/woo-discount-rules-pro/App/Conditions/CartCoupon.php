<?php

namespace WDRPro\App\Conditions;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Conditions\Base;

class CartCoupon extends Base
{
    function __construct()
    {
        parent::__construct();
        $this->name = 'cart_coupon';
        $this->label = __('Coupons', 'woo-discount-rules-pro');
        $this->group = __('Cart', 'woo-discount-rules-pro');
        $this->template = WDR_PRO_PLUGIN_PATH . 'App/Views/Admin/Conditions/Cart/coupon.php';
    }

    public function check($cart, $options)
    {
        // if(empty($cart)){
        //    return false;
        // }
        $result = false;
        if (isset($options->operator)) {
            $list = isset($options->value) ? (array)$options->value : array();
            $list = array_map('strtolower', $list);
            $custom_coupon = isset($options->custom_value) ? $options->custom_value : array();
            $applied_coupons = self::$woocommerce_helper->getAppliedCoupons();
            $applied_coupons = is_array($applied_coupons) ? array_map('strtolower', $applied_coupons) : [];
            if (empty($applied_coupons)) {
                return false;
            }
            switch ($options->operator) {
                case 'at_least_one':
                    $result = !empty(array_intersect($applied_coupons, $list));
                    /**
                     * Hook for "Apply if any one coupon is applied (Select from Woocommerce)" option
                     * @since after 2.3.3
                     * @editor Balakrishnan
                     */
                    $result =  apply_filters('advanced_woo_discount_rules_select_coupon_from_woocommerce', $result, $operator = 'at_least_one', $applied_coupons, $list, $cart, $options);
                    break;
                case 'all':
                    $result = (count(array_intersect($applied_coupons, $list)) >= count($list));
                    break;
                case 'only':
                    $result = (count(array_intersect($applied_coupons, $list)) == count($list));
                    break;
                case 'none':
                    $result = (count(array_intersect($applied_coupons, $list)) == 0);
                    break;
                case 'none_at_all':
                    $result = empty($applied_coupons);
                    break;
                case 'custom_coupon':
                    if(isset($custom_coupon) && !empty($custom_coupon)){
	                    $result = in_array( strtolower($custom_coupon), $applied_coupons);
                    }
                    break;
                default:
                case 'at_least_one_any':
                    $result = !empty($applied_coupons);
                    break;
            }
        }
        return $result;
    }
}