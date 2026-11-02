<?php

namespace WDRPro\App\Conditions;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Conditions\Base;
use Wdr\App\Controllers\Configuration;
use Wdr\App\Helpers\Helper;
use WDRPro\App\Helpers\CoreMethodCheck;

class PurchasePreviousOrdersForSpecificProduct extends Base
{
    public $config;
    protected static $cache_order_count = array();
    public function __construct()
    {
        parent::__construct();
        $this->name = 'purchase_previous_orders_for_specific_product';
        $this->label = __('Number of orders made with following products', 'woo-discount-rules-pro');
        $this->group = __('Purchase History', 'woo-discount-rules-pro');
        $this->template = WDR_PRO_PLUGIN_PATH . 'App/Views/Admin/Conditions/PurchaseHistory/previous-order-against-product.php';
        $this->config = new Configuration();
    }

    function check($cart, $options)
    {
        if (isset($options->operator) && isset($options->time) && isset($options->count) && !empty($options->count) && isset($options->products) && is_array($options->products) && !empty($options->products)) {
            $apply_discount_to_child = apply_filters('advanced_woo_discount_rules_apply_discount_to_child', true);
            if($apply_discount_to_child){
                if(isset($options->product_variants) && !empty($options->product_variants) && is_array($options->product_variants)){
                    if (!CoreMethodCheck::customOrdersTableIsEnabled()) {
                        $options->products = Helper::combineProductArrays($options->products, $options->product_variants);
                    }
                }
            }
            $conditions = '';
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
                $args = array(
                    'meta_query' => $conditions//phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- required for order meta lookup, results cached
                );
                if (isset($options->status) && is_array($options->status) && !empty($options->status)) {
                    $args['post_status'] = $options->status;
                }
                if ($options->time != "all_time") {
                    $args['date_query'] = array('after' => $this->getDateByString($options->time, 'Y-m-d').' 00:00:00');
                }
                $cache_key = CoreMethodCheck::generateBase64Encode($options);
                if(isset(self::$cache_order_count[$cache_key])){
                    $order_count = self::$cache_order_count[$cache_key];
                } else {
                    $order_count = 0;
                    if (CoreMethodCheck::customOrdersTableIsEnabled()) {
                        $cot_query_args = CoreMethodCheck::prepareCOTQueryArgsThroughWPQuery($args);
                        $cot_query_args['select'] = ['id', 'product_id', 'variation_id'];
                        $cot_query_args['join'] = 'order_items';
                        $cot_query_args['where'] = [
                            [
                                'column' => 'product_id',
                                'operator' => 'IN',
                                'value' => $options->products,
                            ],
                            [
                                'column' => 'variation_id',
                                'operator' => 'IN',
                                'value' => $options->products,
                            ]
                        ];
                        $cot_query_args['where_relation'] = 'OR';
                        $cot_query_args['count'] = 'product_id';
                        $cot_query_args['count_distinct'] = true;
                        $cot_query_args['group_by'] = 'id';
                        $cot_query_args['return'] = 'var';
                        $order_count = (int) CoreMethodCheck::performCOTQuery($cot_query_args);
                    } else {
                        $orders = CoreMethodCheck::getOrdersThroughWPQuery($args);
                        if (!empty($orders)) {
                            foreach ($orders as $order) {
                                if (!empty($order) && isset($order->ID)) {
                                    $order_obj = self::$woocommerce_helper->getOrder($order->ID);
                                    $keys = self::$woocommerce_helper->getOrderItemsId($order_obj);
                                    if ($count = count(array_intersect($options->products, $keys))) {
                                        $order_count += $count;
                                    }
                                }
                            }
                        }
                    }
                    self::$cache_order_count[$cache_key] = $order_count;
                }

                return $this->doComparisionOperation($options->operator, $order_count, $options->count);
            }
        }
        return false;
    }
}