<?php

namespace WDRPro\App\Controllers\Admin;
if (!defined('ABSPATH')) {
    exit;
}
class FreeShipping
{
    public static function init(){
        self::hooks();
    }

    /**
     * Hooks
     * */
    protected static function hooks(){
        add_filter('advanced_woo_discount_rules_adjustment_type', array(__CLASS__, 'addAdjustmentType'));
    }

    /**
     * Add adjustment type
     *
     * @param $adjustment_type array
     * @return array
     * */
    public static function addAdjustmentType($adjustment_type){
        $adjustment_type['wdr_free_shipping'] = array(
            'class' => '',
            'label' => __('Free Shipping', 'woo-discount-rules-pro'),
            'group' => __('Simple Discount', 'woo-discount-rules-pro'),
            'template' => '',
        );

        return $adjustment_type;
    }
}

FreeShipping::init();