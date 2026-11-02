<?php

namespace WDRPro\App\Conditions;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Conditions\Base;

class CartPaymentMethod extends Base
{
    function __construct()
    {
        parent::__construct();
        $this->name = 'cart_payment_method';
        $this->label = __('Payment Method', 'woo-discount-rules-pro');
        $this->group = __('Cart', 'woo-discount-rules-pro');
        $this->template = WDR_PRO_PLUGIN_PATH . 'App/Views/Admin/Conditions/Cart/payment-method.php';
    }

    public function check($cart, $options)
    {
        if(empty($cart)){
            return false;
        }
        if (isset($options->operator) && isset($options->value)) {
            $post_data = $this->input->post('post_data');
            $payment_method = '';
            $post = array();
            if (!empty($post_data)) {
                parse_str($post_data, $post);
            }
            if(!isset($post['payment_method'])){
                $post['payment_method'] = $this->input->post('payment_method');
            }
            if(isset($post['payment_method']) && !empty($post['payment_method'])){
                $payment_method = $post['payment_method'];
            }
            if(empty($payment_method)){
                $payment_method = self::$woocommerce_helper->getUserSelectedPaymentMethod();
            }
            return $this->doCompareInListOperation($options->operator, $payment_method, $options->value);
        }
        return false;
    }
}