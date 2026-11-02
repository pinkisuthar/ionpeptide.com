<?php

namespace WDRPro\App\Conditions;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Conditions\Base;

class ShippingCountry extends Base
{
    function __construct()
    {
        parent::__construct();
        $this->name = 'shipping_country';
        $this->label = __('Country', 'woo-discount-rules-pro');
        $this->group = __('Shipping', 'woo-discount-rules-pro');
        $this->template = WDR_PRO_PLUGIN_PATH . 'App/Views/Admin/Conditions/Shipping/country.php';
    }

    public function check($cart, $options)
    {
        if (isset($options->value) && isset($options->operator)) {
            $post_data = $this->input->post('post_data');
            $post = array();
            if (!empty($post_data)) {
                parse_str($post_data, $post);
            }
            $shipping_country = $this->input->post('calc_shipping_country', NULL);
            if (empty($shipping_country) || is_null($shipping_country)) {
                $shipping_country = $this->input->post('s_country', NULL);
                if (empty($shipping_country) || is_null($shipping_country)) {
                    if (isset($post['shipping_country']) && !empty($post['shipping_country'])) {
                        $shipping_country = $post['shipping_country'];
                    } else {
                        $shipping_country = self::$woocommerce_helper->getShippingCountry();
                    }
                }
            }
            if (!empty($shipping_country)) {
                return $this->doCompareInListOperation($options->operator, $shipping_country, $options->value);
            }
        }
        return false;
    }
}