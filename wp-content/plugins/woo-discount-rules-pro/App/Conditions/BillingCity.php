<?php

namespace WDRPro\App\Conditions;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Conditions\Base;

class BillingCity extends Base
{
    function __construct()
    {
        parent::__construct();
        $this->name = 'Billing_city';
        $this->label = __('City', 'woo-discount-rules-pro');
        $this->group = __('Billing', 'woo-discount-rules-pro');
        $this->template = WDR_PRO_PLUGIN_PATH . 'App/Views/Admin/Conditions/Billing/city.php';
    }

    public function check($cart, $options)
    {
        if (isset($options->value) && isset($options->operator)) {
            $post_data = $this->input->post('post_data');
            $post = array();
            if (!empty($post_data)) {
                parse_str($post_data, $post);
            }
            $billing_city = $this->input->post('billing_city', NULL);

            if (empty($billing_city) || is_null($billing_city)) {
                $billing_city = $this->input->post('city', NULL);
                if (empty($billing_city) || is_null($billing_city)) {
                    if (isset($post['billing_city']) && !empty($post['billing_city'])) {
                        $billing_city = strtolower($post['billing_city']);
                    } else {
                        $billing_city = strtolower(self::$woocommerce_helper->getBillingCity());
                    }
                }
            }

            if (!empty($billing_city)) {
                $cities_list = (!is_array($options->value) && !is_object($options->value)) ? explode(',', $options->value) : $options->value;
                $cities_array = array();
                if (!empty($cities_list)) {
                    foreach ($cities_list as $cities) {
                        $cities_array[] = strtolower(trim($cities));
                    }
                }
                $billing_city = strtolower(trim($billing_city));
                return $this->doCompareInListOperation($options->operator, $billing_city, $cities_array);
            }
        }
        return false;
    }
}