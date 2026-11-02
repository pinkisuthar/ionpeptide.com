<?php

namespace WDRPro\App\Conditions;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Conditions\Base;

class ShippingState extends Base
{
    function __construct()
    {
        parent::__construct();
        $this->name = 'shipping_state';
        $this->label = __('State', 'woo-discount-rules-pro');
        $this->group = __('Shipping', 'woo-discount-rules-pro');
        $this->template = WDR_PRO_PLUGIN_PATH . 'App/Views/Admin/Conditions/Shipping/state.php';
    }

    public function check($cart, $options)
    {
        $check_country = $country_based_validation = false;
        if (isset($options->value) && isset($options->operator)) {
            $post_data = $this->input->post('post_data');
            $post = array();
            if (!empty($post_data)) {
                parse_str($post_data, $post);
            }
            if(isset($options->countries) && !empty(isset($options->countries))){
                $country_based_validation = true;
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
                    $check_country = $this->doCompareInListOperation('in_list', $shipping_country, $options->countries);
                }
            }
            if(($country_based_validation && $check_country) || (!$country_based_validation && !$check_country)){
                $shipping_state = $this->input->post('calc_shipping_state', NULL);
                if (empty($shipping_state) || is_null($shipping_state)) {
                    $shipping_state = $this->input->post('s_state', NULL);
                    if (empty($shipping_state) || is_null($shipping_state)) {
                        if (isset($post['shipping_state']) && !empty($post['shipping_state'])) {
                            $shipping_state = $post['shipping_state'];
                        } else {
                            $shipping_state = self::$woocommerce_helper->getShippingState();
                        }
                    }
                }
                if (!empty($shipping_state)) {
                    return $this->doCompareInListOperation($options->operator, $shipping_state, $options->value);
                }
            }
        }
        return false;
    }
}