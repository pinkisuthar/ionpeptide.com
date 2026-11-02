<?php

namespace WDRPro\App\Controllers;
if (!defined('ABSPATH')) {
    exit;
}

use Wdr\App\Controllers\Configuration;
use Wdr\App\Helpers\Input;
use WDRPro\App\Helpers\CoreMethodCheck;
use WDRPro\App\Rules\BuyXGetY;

class FrontEndGeneralHooks
{
    public static function init(){
        self::hook();
    }

    protected static function hook(){
        add_action('wp_enqueue_scripts', array(__CLASS__, 'siteScript'), 100 );
        add_action('wp_ajax_awdr_change_discount_product_in_cart', array(__CLASS__, 'awdr_change_discount_product_in_cart'));
        add_action('wp_ajax_nopriv_awdr_change_discount_product_in_cart', array(__CLASS__, 'awdr_change_discount_product_in_cart'));
        //add_action( 'woocommerce_after_checkout_form', array(__CLASS__, 'addScriptInCheckoutPage'));
    }

    public static function siteScript(){
        $config = new Configuration();
        $minified_text = '';
        $compress_css_and_js = $config->getConfig('compress_css_and_js', 0);
        if($compress_css_and_js) $minified_text = '.min';
        wp_register_style('woo_discount_pro_style', WDR_PRO_PLUGIN_URL . 'Assets/Css/awdr_style'.$minified_text.'.css', array(), WDR_PRO_VERSION);
        wp_enqueue_style('woo_discount_pro_style');

        wp_register_script('woo_discount_pro_script', WDR_PRO_PLUGIN_URL . 'Assets/Js/awdr_pro'.$minified_text.'.js', array(), WDR_PRO_VERSION, true);
        wp_enqueue_script('woo_discount_pro_script');
    }

    public static function awdr_change_discount_product_in_cart(){
        CoreMethodCheck::validateRequest('awdr_ajax_front_end');
        $input = new Input();
        $rule_id = $input->post('rule_unique_id', '');
        if(!empty($rule_id)){
            $rule_id = preg_replace('/[^A-Za-z0-9\_]/', '', $rule_id);
        }
        $product_id = $input->post('product_id', '');
        $product_id = intval($product_id);
        $parent_id = $input->post('parent_id', '');
        $parent_id = intval($parent_id);
        if(!empty($rule_id) && !empty($product_id) && !empty($parent_id)){
            $status = BuyXGetY::changeDiscountedProductInCart($rule_id, $product_id, $parent_id);
            wp_send_json_success($status);
        }
    }

    /**
     * Add script in checkout page for refresh the order review on change email/state
     * */
    public static function addScriptInCheckoutPage(){
        $on_blur_event_for_items = apply_filters('advanced_woo_discount_rules_elements_to_refresh_the_checkout_review_on_blur', 'input#billing_email, select#billing_state');
        $script = '<script type="text/javascript">
                    jQuery( function( $ ) {
                        $(document).ready(function() {
                            $( document.body ).on( "blur", "'.$on_blur_event_for_items.'", function() {
                                $(document.body).trigger("update_checkout");
                            });
                        }); 
                    });
                </script>';
        echo $script;//phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline script built from static class-name selectors, no user input
    }
}

FrontEndGeneralHooks::init();

