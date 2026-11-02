<?php

namespace WDRPro\App\Controllers\Admin;
if (!defined('ABSPATH')) {
    exit;
}
use Wdr\App\Controllers\Configuration;
use Wdr\App\Helpers\Input;
use WDRPro\App\Helpers\CoreMethodCheck;

class UpdateHandler
{
    protected static $slug = 'discount-rules-v2-pro';
    protected static $plugin_name = 'Discount Rules Pro for WooCommerce';
    protected static $flycart_url = 'https://www.flycart.org/';
    protected static $update_url = 'https://my.flycart.org/';//?wpaction=updatecheck&wpslug=woo-discount-rules&pro=1
    protected static $renew_url = 'https://www.flycart.org/products/wordpress/woocommerce-discount-rules';

    /**
     * Initialise
     * */
    public static function init()
    {
        if (is_admin()) {
            self::hooks();
        }
    }

    /**
     * Hooks
     * */
    protected static function hooks(){
        //add_filter('puc_request_info_result-woo-discount-rules-pro', array(__CLASS__, 'loadWooDiscountRulesUpdateDetails'), 10, 2);

        self::runUpdater();

        add_action('after_plugin_row', array(__CLASS__, 'messageAfterPluginRow'), 10, 3);
        add_action('admin_notices', array(__CLASS__, 'errorNoticeInAdminPages'));
        add_action('wp_ajax_awdr_validate_licence_key', array(__CLASS__, 'validateLicenceKey'));
        add_action('admin_init', array(__CLASS__, 'maybeRevalidateLicence'));
    }

    /**
     * Re-validate the saved licence key once a week. The status option was only
     * ever written on the manual Validate click, so an expired licence kept
     * status 1 forever; this keeps it honest with one remote call per week.
     * */
    public static function maybeRevalidateLicence(){
        $licence_key = self::getLicenceKey();
        if(empty($licence_key)){
            return;
        }
        $verified_time = (int)get_option('advanced_woo_discount_rules_licence_verified_time', 0);
        if($verified_time && (time() - $verified_time) < WEEK_IN_SECONDS){
            return;
        }
        self::updateLicenceKeyAsValidated(self::isValidLicenceKey($licence_key));
    }

    /**
     * Renew link markup (expired and invalid are indistinguishable in the old
     * licensecheck API, so the renew action is offered for both). Shown in the
     * plugins-page notices, same place as the enter-license-key message.
     * */
    public static function getRenewLink(){
        return '<a class="awdr-renew-licence" href="'.esc_url(self::$renew_url).'" target="_blank" rel="noreferrer">'.esc_html__('Renew Now', 'woo-discount-rules-pro').'</a>';
    }

    /**
     * Ajax request for license key validation
     * */
    public static function validateLicenceKey()
    {
        $input = new Input();
        $licence_key = $input->get_post('licence_key');
        $result = null;
        $result['message'] = self::messageForLicenceKey(false, $licence_key);
        if(!empty($licence_key)){
            CoreMethodCheck::validateRequest('awdr_validate_licence_key');
            if(CoreMethodCheck::hasAdminPrivilege() && CoreMethodCheck::isValidLicenceKey($licence_key)){
                $status = self::isValidLicenceKey($licence_key);
                $result['valid'] = $status;
                if($status){
                    $result['message'] = self::messageForLicenceKey($status, $licence_key);
                } else {
                    $result['message'] = self::messageForLicenceKey($status, $licence_key);
                }
                self::updateLicenceKeyInSettings($licence_key);
                self::updateLicenceKeyAsValidated($status);
            }
        }else{
	        self::updateLicenceKeyAsValidated();
        }

        wp_send_json_success($result);
    }

    /**
     * Update Licence key with settings
     * */
    protected static function updateLicenceKeyInSettings($licence_key){
        $config = get_option(Configuration::DEFAULT_OPTION);
        $config['licence_key'] = $licence_key;
        update_option(Configuration::DEFAULT_OPTION, $config);
    }

    /**
     * Update licence key as validated
     * */
    protected static function updateLicenceKeyAsValidated($status = ''){
        update_option('advanced_woo_discount_rules_licence_verified_time', time());
        if($status){
            update_option('advanced_woo_discount_rules_licence_status', 1);
        } else {
            update_option('advanced_woo_discount_rules_licence_status', 0);
        }
    }

    /**
     * Get licence key verified status
     * */
    public static function getLicenceKeyVerifiedStatus(){
        return get_option('advanced_woo_discount_rules_licence_status', 0);
    }

    /**
     * Get message for licence key
     * */
    public static function messageForLicenceKey($status, $licence_key){
        if(empty($licence_key)){
            return '<p class="wdr-licence-invalid-text">'.esc_html__('Please enter a valid license key', 'woo-discount-rules-pro').'</p>';
        } else {
            if($status){
                return '<p class="wdr-licence-valid-text">'.esc_html__('License key check : Passed.', 'woo-discount-rules-pro').'</p>';
            } else {
                return '<p class="wdr-licence-invalid-text">'.esc_html__('License key seems to be Invalid. Please enter a valid license key', 'woo-discount-rules-pro').'</p>';
            }
        }
    }

    /**
     * Check licence key is valid
     *
     * @param $key string
     * @return boolean
     * */
    protected static function isValidLicenceKey($key)
    {
        $licence_check_url = self::getUpdateURL('licensecheck', $key);
        $result = wp_remote_get( $licence_check_url , array());

        $status = self::validateApiResponse($result);
        if ( !is_wp_error($status) ){
            $json = json_decode( $result['body'] );
            if ( is_object($json) && isset($json->license_check)) {
                return (bool)$json->license_check;
            }
        }

        return false;
    }

    /**
     * Check if $result is a successful update API response.
     *
     * @param array|WP_Error $result
     * @return true|WP_Error
     */
    protected static function validateApiResponse($result) {
        if ( is_wp_error($result) ) { /** @var WP_Error $result */
            return new \WP_Error($result->get_error_code(), 'WP HTTP Error: ' . $result->get_error_message());
        }

        if ( !isset($result['response']['code']) ) {
            return new \WP_Error(
                'puc_no_response_code',
                'wp_remote_get() returned an unexpected result.'
            );
        }

        if ( $result['response']['code'] !== 200 ) {
            return new \WP_Error(
                'puc_unexpected_response_code',
                'HTTP response code is ' . $result['response']['code'] . ' (expected: 200)'
            );
        }

        if ( empty($result['body']) ) {
            return new \WP_Error('puc_empty_response', 'The metadata file appears to be empty.');
        }

        return true;
    }

    /**
     * Run Plugin updater
     * */
    protected static function runUpdater(){
		$licence_key = self::getLicenceKey();
	    $verifiedLicense = self::getLicenceKeyVerifiedStatus();
		if(!$verifiedLicense || empty($licence_key)){
			return;
		}
	    require WDR_PRO_PLUGIN_PATH.'/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
        $update_url = self::getUpdateURL();
        try {
            $myUpdateChecker = \Puc_v4_Factory::buildUpdateChecker(
                $update_url,
                WDR_PRO_PLUGIN_PATH.'woo-discount-rules-pro.php',
                'woo-discount-rules-pro'
            );
        } catch (\Exception $e){}
    }

    /**
     * Get licence key
     *
     * @return string
     * */
    protected static function getLicenceKey(){
        $config = Configuration::getInstance();
        return $config->getConfig('licence_key', '');
    }

    /**
     * Get update URL
     * @param $type string
     *
     * @return string
     * */
    public static function getUpdateURL($type = 'updatecheck', $licence_key = null)
    {
        $update_url = self::$update_url.'?wpaction='.$type.'&wpslug='.self::$slug.'&pro=1';
        if(empty($licence_key)){
            $licence_key = self::getLicenceKey();
        }
        $update_url .= '&dlid='.$licence_key;
        return $update_url;
    }

    /**
     * Error notice on admin pages
     * */
    public static function errorNoticeInAdminPages(){
        $htmlPrefix = '<div class="notice notice-warning"><p>';
        $htmlSuffix = '</p></div>';
        $message = self::getErrorMessageIfExists();
        if($message['has_message']){
            echo wp_kses_post($htmlPrefix.$message['message'].$htmlSuffix);
        }
    }

    /**
     * Get message to display
     *
     * @return array
     * */
    protected static function getErrorMessageIfExists(){
        // One message for every non-active state (no key / invalid / expired) —
        // the renew page doubles as the purchase page, so it fits all three.
        $message['has_message'] = false;
        $message['message'] = '';
        $licence_key = self::getLicenceKey();
        if(!empty($licence_key) && self::getLicenceKeyVerifiedStatus()){
            return $message;
        }
        $enter_key_anchor_tag = '<a href="admin.php?page=woo_discount_rules&tab=settings">'.__('enter a valid license key', 'woo-discount-rules-pro').'</a>';
        /* translators: 1: plugin name 2: Renew Now link 3: settings page link */
        $message['message'] = sprintf(__('Your %1$s license is inactive or has expired. %2$s to continue receiving updates, support and security fixes, or %3$s.', 'woo-discount-rules-pro'), self::$plugin_name, self::getRenewLink(), $enter_key_anchor_tag);
        $message['has_message'] = true;

        return $message;
    }

    /**
     * Hook to check and display updates below plugin in Admin Plugins section
     * This plugin checks for license key validation and displays error notices
     * @param string $plugin_file our plugin file
     * @param string $plugin_data Plugin details
     * @param string $status
     * */
    public static function messageAfterPluginRow( $plugin_file, $plugin_data, $status ){
        if( isset($plugin_data['TextDomain']) && $plugin_data['TextDomain'] == 'woo-discount-rules-pro' ){
            $message = self::getErrorMessageIfExists();
            if($message['has_message']){
                // If an update is available ?
                // prevent update if error occurs
                echo '<tr>';
                echo '<td> </td>';
                echo wp_kses_post('<td colspan="2"> <div class="notice-message error inline notice-error notice-alt"><p>'.$message['message'].'</p></div></td>');
                echo '</tr>';
            }

            if(empty($plugin_data['package']) && !empty($plugin_data['upgrade_notice'])){
                echo '<tr class="plugin-update-tr active" data-slug="woo-discount-rules">';
                echo wp_kses_post('<td class="plugin-update colspanchange" colspan="3"> <div class="notice inline notice-warning notice-alt"><p>'.$plugin_data['upgrade_notice'].'</p></div></td>');
                echo '</tr>';
            }
        }

    }

    /**
     * To load Woo discount rules update details
     * */
    // public static function loadWooDiscountRulesUpdateDetails($pluginInfo, $result){
    //     try{
    //         global $wp_version;
    //         // include an unmodified $wp_version
    //         include( ABSPATH . WPINC . '/version.php' );
    //         $args = array('slug' => 'woo-discount-rules', 'fields' => array('active_installs'));
    //         $response = wp_remote_post(
    //             'https://api.wordpress.org/plugins/info/1.0/',
    //             array(
    //                 'user-agent' => 'WordPress/' . $wp_version . '; ' . home_url( '/' ),
    //                 'body' => array(
    //                     'action' => 'plugin_information',
    //                     'request'=>serialize((object)$args)
    //                 )
    //             )
    //         );

    //         if(!empty($response)){
    //             $returned_object = maybe_unserialize(wp_remote_retrieve_body($response));
    //             if(!empty($returned_object)){
    //                 if(empty($pluginInfo)){
    //                     if(class_exists('\Puc_v4p9_Plugin_Info')){
    //                         $pluginInfo = new \Puc_v4p9_Plugin_Info();
    //                     }
    //                 }
    //                 if(!empty($returned_object->name)) $pluginInfo->name = $returned_object->name;
    //                 if(!empty($returned_object->sections)) $pluginInfo->sections = $returned_object->sections;
    //                 if(!empty($returned_object->author)) $pluginInfo->author = $returned_object->author;
    //                 if(!empty($returned_object->author_profile)) $pluginInfo->author_profile = $returned_object->author_profile;
    //                 if(!empty($returned_object->requires)) $pluginInfo->requires = $returned_object->requires;
    //                 if(!empty($returned_object->tested)) $pluginInfo->tested = $returned_object->tested;
    //                 if(!empty($returned_object->rating)) $pluginInfo->rating = $returned_object->rating;
    //                 if(!empty($returned_object->ratings)) $pluginInfo->ratings = $returned_object->ratings;
    //                 if(!empty($returned_object->num_ratings)) $pluginInfo->num_ratings = $returned_object->num_ratings;
    //                 if(!empty($returned_object->support_threads)) $pluginInfo->support_threads = $returned_object->support_threads;
    //                 if(!empty($returned_object->support_threads_resolved)) $pluginInfo->support_threads_resolved = $returned_object->support_threads_resolved;
    //                 if(!empty($returned_object->downloaded)) $pluginInfo->downloaded = $returned_object->downloaded;
    //                 if(!empty($returned_object->last_updated)) $pluginInfo->last_updated = $returned_object->last_updated;
    //                 if(!empty($returned_object->added)) $pluginInfo->added = $returned_object->added;
    //                 if(!empty($returned_object->versions)) $pluginInfo->versions = $returned_object->versions;
    //                 if(!empty($returned_object->tags)) $pluginInfo->tags = $returned_object->tags;
    //                 if(!empty($returned_object->screenshots)) $pluginInfo->screenshots = $returned_object->screenshots;
    //                 if(!empty($returned_object->active_installs)) $pluginInfo->active_installs = $returned_object->active_installs;
    //             }
    //         }
    //     } catch (\Exception $e){}

    //     return $pluginInfo;
    // }
}

UpdateHandler::init();
