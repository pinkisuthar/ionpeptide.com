<?php

namespace WDRPro\App\Conditions;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Conditions\Base;
use WDRPro\App\Helpers\CoreMethodCheck;

class PurchaseSpent extends Base
{
    protected static $cache_order_count = array();
    public function __construct()
    {
        parent::__construct();
        $this->name = 'purchase_spent';
        $this->label = __('Total spent', 'woo-discount-rules-pro');
        $this->group = __('Purchase History', 'woo-discount-rules-pro');
        $this->template = WDR_PRO_PLUGIN_PATH . 'App/Views/Admin/Conditions/PurchaseHistory/spent.php';
    }

    function check($cart, $options)
    {
        if (isset($options->operator) && isset($options->time) && isset($options->amount) && !empty($options->amount)) {
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
                $cache_key = CoreMethodCheck::generateBase64Encode($options);
                if(isset(self::$cache_order_count[$cache_key])){
                    $total_spent = self::$cache_order_count[$cache_key];
                } else {
                    $args = array(
                        'meta_query' => $conditions//phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- required for order meta lookup, results cached
                    );
                    if (isset($options->status) && is_array($options->status) && !empty($options->status)) {
                        $args['post_status'] = $options->status;
                    }
                    if ($options->time != "all_time") {
                        $args['date_query'] = array('after' => $this->getDateByString($options->time, 'Y-m-d').' 00:00:00');
                    }
                    $total_spent = 0;
                    if (CoreMethodCheck::customOrdersTableIsEnabled()) {
                        $cot_query_args = CoreMethodCheck::prepareCOTQueryArgsThroughWPQuery($args);
                        $cot_query_args['select'] = 'SUM(total_amount)';
                        $cot_query_args['return'] = 'var';
                        $total_spent = (float) CoreMethodCheck::performCOTQuery($cot_query_args);
                    } else {
                        $orders = CoreMethodCheck::getOrdersThroughWPQuery($args);
                        if (!empty($orders)) {
                            foreach ($orders as $order) {
                                if (!empty($order) && isset($order->ID)) {
                                    $order_obj = self::$woocommerce_helper->getOrder($order->ID);
                                    $total_spent += self::$woocommerce_helper->getOrderTotal($order_obj);
                                }
                            }
                        }
                    }
                    self::$cache_order_count[$cache_key] = $total_spent;
                }

                return $this->doComparisionOperation($options->operator, $total_spent, $options->amount);
            }
        }
        return false;
    }
}