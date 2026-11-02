<?php

namespace WDRPro\App\Helpers;
use Wdr\App\Controllers\DiscountCalculator;

if (!defined('ABSPATH')) {
    exit;
}
class FreeShippingMethod extends \WC_Shipping_Method
{
    /**
     * Constructor for your shipping class
     * @param $title
     */
    public function __construct($title = '')
    {
        if(empty($title)){
            if(method_exists(DiscountCalculator::$config, 'getConfig')){
                $title = DiscountCalculator::$config->getConfig('free_shipping_title', 'free shipping');
                $title = CoreMethodCheck::getCleanHtml($title);
            }
        }
        $this->id = 'wdr_free_shipping';
        $this->method_title = __('Free shipping', 'woo-discount-rules-pro');
        $this->method_description = __('Free shipping is a special method which can be triggered with coupons and minimum spends.', 'woo-discount-rules-pro');
        $this->init();
        $this->enabled = true;
        $this->title = (empty($title)) ? __('Wdr Free Shipping', 'woo-discount-rules-pro') : __($title, 'woo-discount-rules-pro');//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- admin-entered shipping title passed through for translation plugins
    }

    /**
     * Initialize Wdr free shipping.
     */
    function init()
    {
        // Load the settings.
        $this->init_settings();
        // Save settings in admin if you have any defined
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Called to calculate shipping rates for this method. Rates can be added using the add_rate() method.
     *
     * @param array $package Shipping package.
     * @uses WC_Shipping_Method::add_rate()
     *
     */
    public function calculate_shipping($package = array())
    {
        $this->add_rate(
            array(
                'label' => $this->title,
                'cost' => 0,
                'taxes' => false,
                'package' => $package,
            )
        );
    }
}