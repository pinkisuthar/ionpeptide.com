<?php
namespace WDRPro\App\Rules;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Controllers\DiscountCalculator;
use Wdr\App\Helpers\Woocommerce;
use Wdr\App\Router;
use WDRPro\App\Helpers\CoreMethodCheck;
use WDRPro\App\Helpers\FreeShippingMethod;

class FreeShipping
{
   public static $isset_free_shipping = 'no', $free_shipping = false, $shipping_obj, $shipping_discounts;

    /**
     * Initialize
     * */
    public static function init()
    {
        self::hooks();
    }

    /**
     * Add hooks
     * */
    protected static function hooks(){
        add_action( 'advanced_woo_discount_rules_loaded', function() {
            add_action('woocommerce_shipping_init', array(__CLASS__, 'mayHaveFreeShipping'));
            add_filter('woocommerce_shipping_methods', array(__CLASS__, 'registerFreeShippingMethod'));
            add_filter('woocommerce_shipping_wdr_free_shipping_is_available', array(__CLASS__, 'cartHasFreeShipping'));
        });
        add_filter('advanced_woo_discount_rules_isset_free_shipping', array(__CLASS__, 'issetFreeShipping'));
        add_action('woocommerce_checkout_update_order_review', array(__CLASS__, 'refreshShippingOptionsOnLoadOrderReview'), 10, 1);
        add_filter('woocommerce_shipping_chosen_method', array(__CLASS__, 'reset_default_shipping_method_woo_discount'), 100, 2);
        add_filter('woocommerce_package_rates', array(__CLASS__, 'wdrHideShippingWhenFreeIsAvailable'), 100 );
        add_action('woocommerce_after_calculate_totals', array(__CLASS__, 'setFreeShippingInAppliedRules'), 11);
    }

    /**
     * Set free shipping on $applied_rules so we can display message and update used count
     * */
    public static function setFreeShippingInAppliedRules(){
        remove_action('woocommerce_after_calculate_totals', array(__CLASS__, 'setFreeShippingInAppliedRules'), 11);
        DiscountCalculator::getFreeshippingMethod();
        add_action('woocommerce_after_calculate_totals', array(__CLASS__, 'setFreeShippingInAppliedRules'), 11);
    }

    /**
     * Refresh shipping on load order review
     * */
    public static function refreshShippingOptionsOnLoadOrderReview($post_data){
        self::cartHasFreeShipping();
        $packages = Woocommerce::get_shipping_packages();
        $refresh_shipping_options = apply_filters('advanced_woo_discount_rules_refresh_shipping_options_on_order_review', false);
        if((self::$free_shipping || $refresh_shipping_options) && !empty($packages)){
            foreach ($packages as $package_key => $package ) {
                Woocommerce::setSession('shipping_for_package_' . $package_key, false );  // Or true
            }
        }
    }

    /**
     * Show the free shipping
     */
    public static function mayHaveFreeShipping()
    {
        $title = DiscountCalculator::$config->getConfig('free_shipping_title', 'free shipping');
        $title = CoreMethodCheck::getCleanHtml($title);
        self::$shipping_obj = new FreeShippingMethod($title);
        self::$isset_free_shipping = 'yes';
        self::$free_shipping = true;
    }

    /**
     * register the shipping method
     * @param $methods
     * @return array
     */
    public static function registerFreeShippingMethod($methods)
    {
        if (self::$free_shipping) {
            $methods['wdr_free_shipping'] = get_class(self::$shipping_obj);
        }
        return $methods;
    }

    /**
     * Check cart has free shipping
     * @return bool
     */
    public static function cartHasFreeShipping()
    {
        self::$free_shipping = false;
        if (empty(self::$shipping_discounts)) {
            self::$shipping_discounts = DiscountCalculator::getFreeshippingMethod();
        }

        if (!empty(self::$shipping_discounts)) {
            if (isset(self::$shipping_discounts['free_shipping']) && !empty(self::$shipping_discounts['free_shipping'])) {
                self::$free_shipping = apply_filters('advanced_woo_discount_rules_apply_free_shipping', true);
                // To remove third party coupon from cart when free shipping is applied as this doesn't trigger when only shipping rule is enabled. From v2.5.0
                if(self::$free_shipping){
                    $manage_discount = Router::$manage_discount;
                    add_action('woocommerce_after_calculate_totals', array($manage_discount, 'removeThirdPartyCoupon'), 20);
                }
            }
        }
        return self::$free_shipping;
    }

    /**
     * Check free shipping is applied and store data into database
     * @return string
     */
    static function issetFreeShipping(){
        if(isset(self::$isset_free_shipping)){
            return self::$isset_free_shipping;
        }
    }

    /**
     * @param $method
     * @param $available_methods
     * @return mixed|string
     */
    static function reset_default_shipping_method_woo_discount( $method, $available_methods ) {
        if(!empty($available_methods) && is_array($available_methods)) {
            $shipping_methods = array_keys($available_methods);
            if(!empty($shipping_methods)){
                foreach ($shipping_methods as $key => $shipping_method) {
                    if (strpos($shipping_method, 'free_shipping') === 0) {
                        $method = $shipping_method;
                    }
                }
                if(in_array('wdr_free_shipping', $shipping_methods)) $method = 'wdr_free_shipping';
            }
        }
        return $method;
    }

    static function wdrHideShippingWhenFreeIsAvailable($rates){
        $hide_other_shipping_methods = DiscountCalculator::$config->getConfig('wdr_hide_other_shipping', 0);
        if($hide_other_shipping_methods){
            $free = array();
            foreach ( $rates as $rate_id => $rate ) {
                if ( 'wdr_free_shipping' === $rate->method_id ) {
                    $free[ $rate_id ] = $rate;
                    break;
                }
            }
            return ! empty( $free ) ? $free : $rates;
        }
       return $rates;
    }
}
FreeShipping::init();