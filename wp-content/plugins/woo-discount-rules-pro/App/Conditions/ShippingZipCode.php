<?php

namespace WDRPro\App\Conditions;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Conditions\Base;

class ShippingZipCode extends Base
{
    function __construct()
    {
        parent::__construct();
        $this->name = 'shipping_zipcode';
        $this->label = __('Zipcode', 'woo-discount-rules-pro');
        $this->group = __('Shipping', 'woo-discount-rules-pro');
        $this->template = WDR_PRO_PLUGIN_PATH . 'App/Views/Admin/Conditions/Shipping/zip-code.php';
    }

    public function check($cart, $options)
    {
        if (isset($options->value) && isset($options->operator)) {
            $post_data = $this->input->post('post_data');
            $post = array();
            if (!empty($post_data)) {
                parse_str($post_data, $post);
            }
            $shipping_post_code = NULL;
            $shipping_post_code = $this->input->post('s_postcode', NULL);
            if (empty($shipping_post_code) || is_null($shipping_post_code)) {
                if (isset($post['shipping_postcode']) && !empty($post['shipping_postcode'])) {
                    $shipping_post_code = $post['shipping_postcode'];
                } else {
                    $shipping_post_code = self::$woocommerce_helper->getShippingZipCode();
                }
            }

            if (!empty($shipping_post_code)) {
                $post_code_list = (!is_array($options->value) && !is_object($options->value)) ? explode(',', $options->value) : $options->value;
                $post_code_array = array();
                if (!empty($post_code_list)) {
                    foreach ($post_code_list as $post_code) {
                        $post_code_array[] = strtolower(trim($post_code));
                    }
                }
                $shipping_post_code = strtolower(trim($shipping_post_code));
                return $this->doCompareInListOperation($options->operator, $shipping_post_code, $post_code_array);
            }
        }
        return false;
    }
}