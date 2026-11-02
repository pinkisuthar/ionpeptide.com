<?php

namespace WDRPro\App\Conditions;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Conditions\Base;

class CartItemProducts extends Base
{
    function __construct()
    {
        parent::__construct();
        $this->name = 'cart_item_products';
        $this->label = __('Products', 'woo-discount-rules-pro');
        $this->group = __('Cart Items', 'woo-discount-rules-pro');
        $this->template = WDR_PRO_PLUGIN_PATH . 'App/Views/Admin/Conditions/Products/products.php';
    }

    public function check($cart, $options)
    {
        if(empty($cart)){
            return false;
        }
        return $this->doCartItemsCheck($cart, $options, 'products');
    }
}