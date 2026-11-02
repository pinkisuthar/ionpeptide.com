<?php

namespace WDRPro\App\Controllers\Admin;
use Wdr\App\Controllers\Configuration;

if (!defined('ABSPATH')) {
    exit;
}
class GeneralHooks
{
    public static function init(){
        self::hook();
    }

    protected static function hook(){
        add_action('admin_enqueue_scripts', array(__CLASS__, 'adminPageScript'), 100 );
    }

    public static function adminPageScript(){
        if ( !isset($_GET['page']) || $_GET['page'] != 'woo_discount_rules') {//phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing check
            return;
        }
        $config = new Configuration();
        $minified_text = '';
        $compress_css_and_js = $config->getConfig('compress_css_and_js', 0);
        if($compress_css_and_js) $minified_text = '.min';
        wp_register_style('woo_discount_pro_admin_style', WDR_PRO_PLUGIN_URL . 'Assets/Css/admin_style'.$minified_text.'.css', array(), WDR_PRO_VERSION);
        wp_enqueue_style('woo_discount_pro_admin_style');

        wp_register_script('woo_discount_pro_admin_script', WDR_PRO_PLUGIN_URL . 'Assets/Js/wdr_pro_admin'.$minified_text.'.js', array(), WDR_PRO_VERSION, false);
        wp_enqueue_script('woo_discount_pro_admin_script');

        $localization_data = self::getLocalizationData();
        wp_localize_script( 'woo_discount_pro_admin_script', 'woo_discount_pro_localization', $localization_data);
    }

    /**
     * Localization text
     *
     * @return array
     * */
    public static function getLocalizationData(){
        return array(
            'validate' => esc_html__('Validate', 'woo-discount-rules-pro'),
            'validating_please_wait' => esc_html__('Validating please wait..', 'woo-discount-rules-pro'),
        );
    }
}

GeneralHooks::init();

