<?php

namespace WDRPro\App\Conditions;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Conditions\Base;

class ShippingCity extends Base
{
    function __construct()
    {
        parent::__construct();
        $this->name = 'shipping_city';
        $this->label = __('City', 'woo-discount-rules-pro');
        $this->group = __('Shipping', 'woo-discount-rules-pro');
        $this->template = WDR_PRO_PLUGIN_PATH . 'App/Views/Admin/Conditions/Shipping/city.php';
    }

    public function check($cart, $options)
    {
        if (isset($options->value) && isset($options->operator)) {
            $post_data = $this->input->post('post_data');
            $post = array();
            if (!empty($post_data)) {
                parse_str($post_data, $post);
            }
            $shipping_city = $this->input->post('calc_shipping_city', NULL);
            if (empty($shipping_city) || is_null($shipping_city)) {
                $shipping_city = $this->input->post('s_city', NULL);
                if (empty($shipping_city) || is_null($shipping_city)) {
                    if (isset($post['shipping_city']) && !empty($post['shipping_city'])) {
                        $shipping_city = strtolower($post['shipping_city']);
                    } else {
                        $shipping_city = strtolower(self::$woocommerce_helper->getShippingCity());
                    }
                }
            } else {
                $shipping_city = strtolower($shipping_city);
            }

            if (!empty($shipping_city)) {
                $cities_list = (!is_array($options->value) && !is_object($options->value)) ? explode(',', $options->value) : $options->value;
                $cities_array = array();
                if (!empty($cities_list)) {
                    foreach ($cities_list as $cities) {
                        $cities_array[] = strtolower(trim($cities));
                    }
                }
                $shipping_city = strtolower(trim($shipping_city));
                return $this->doCompareInListOperation($options->operator, $shipping_city, $cities_array);
            }
        }
        return false;
    }
}