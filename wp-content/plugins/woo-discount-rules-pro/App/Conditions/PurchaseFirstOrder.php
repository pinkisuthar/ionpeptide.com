<?php

namespace WDRPro\App\Conditions;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Conditions\Base;
use Wdr\App\Helpers\Woocommerce;
use WDRPro\App\Helpers\CoreMethodCheck;

class PurchaseFirstOrder extends Base
{
    protected static $cache_order_count = array();

    public function __construct()
    {
        parent::__construct();
        $this->name = 'purchase_first_order';
        $this->label = __('First order', 'woo-discount-rules-pro');
        $this->group = __('Purchase History', 'woo-discount-rules-pro');
        $this->template = WDR_PRO_PLUGIN_PATH . 'App/Views/Admin/Conditions/PurchaseHistory/first-order.php';
    }

    function check($cart, $options)
    {
        $conditions = array();
        $billing_email = self::$woocommerce_helper->getBillingEmailFromPost();
        if($user = get_current_user_id()){
            if(!empty($billing_email) && apply_filters('advanced_woo_discount_rules_check_purchase_history_based_on_email_and_user_id', false)) {
                //This might affect performance due to OR operation
                $conditions = array(
                    'relation' => 'OR',
                    array('key' => '_customer_user', 'value' => $user, 'compare' => '='),
                    array('key' => '_billing_email', 'value' => $billing_email, 'compare' => '=')
                );
            }else{
                $conditions = array(
                    array('key' => '_customer_user', 'value' => $user, 'compare' => '=')
                );
            }
        } else {
            if(!empty($billing_email)) {
                $conditions = array(
                    array('key' => '_billing_email', 'value' => $billing_email, 'compare' => '=')
                );
            }
        }
        if (!empty($conditions)) {
            $cache_key = CoreMethodCheck::generateBase64Encode($options);
            if(isset(self::$cache_order_count[$cache_key])){
                $orders = self::$cache_order_count[$cache_key];
            } else {
                $args = array(
                    'posts_per_page' => 1,
                    'meta_query' => $conditions,//phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- required for order meta lookup, results cached
                    'post_status' => array_keys(apply_filters('advanced_woo_discount_rules_check_purchase_first_order_status', Woocommerce::getOrderStatusList()))
                );
                if (CoreMethodCheck::customOrdersTableIsEnabled()) {
                    $cot_query_args = CoreMethodCheck::prepareCOTQueryArgsThroughWPQuery($args);
                    $orders = CoreMethodCheck::performCOTQuery($cot_query_args);
                } else {
                    $orders = CoreMethodCheck::getOrdersThroughWPQuery($args);
                }
                self::$cache_order_count[$cache_key] = $orders;
            }
            $first_order = (int)isset($options->value) ? $options->value : 1;
            if ($first_order) {
                return empty($orders);
            } else {
                return !empty($orders);
            }
        }
        return false;
    }
}