<?php
if (!defined('ABSPATH')) exit; // Exit if accessed directly

add_action('wp_ajax_awdr_switch_version', function (){
    $version = isset($_REQUEST['version'])? sanitize_text_field(wp_unslash($_REQUEST['version'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $page = isset($_REQUEST['page'])? sanitize_text_field(wp_unslash($_REQUEST['page'])): ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $wdr_nonce = isset($_REQUEST['wdr_nonce'])? sanitize_text_field(wp_unslash($_REQUEST['wdr_nonce'])): ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $return['status'] = false;
    $return['message'] = esc_html('Invalid request');
    if($version == "v1"){
        \Wdr\App\Helpers\Helper::validateRequest('wdr_ajax_switch_version', $wdr_nonce);
    } else {
        WDRV1Deprecated::validateRequest('wdr_ajax_switch_version', $wdr_nonce);
    }
    if (current_user_can( 'manage_woocommerce' )) {
        if($version !== '' && $page !== ''){
            $url = esc_url(admin_url('admin.php?page=' . $page . '&awdr_switch_plugin_to=' . $version));
            $do_switch = true;
            if (!awdr_is_environment_compatible()) {
                $return['message'] = 'Discount Rules 2.0 requires minimum PHP version of ' . ' ' . WDR_REQUIRED_PHP_VERSION;
                wp_send_json_success($return);
            }
            if (!awdr_is_woo_compatible()) {
                $return['message'] = 'Discount Rules 2.0 requires at least Woocommerce' . ' ' . WDR_WC_REQUIRED_VERSION;
                wp_send_json_success($return);
            }
            if (defined('WDR_BACKWARD_COMPATIBLE')) {
                if(WDR_BACKWARD_COMPATIBLE == true){
                    if ($version == "v2") {
                        if (!defined('WDR_PRO')) {
                            $do_switch = false;
                        }
                    }
                }
            }
            if($do_switch){
                if(in_array($version, array('v1', 'v2'))){
                    update_option('advanced_woo_discount_rules_load_version', $version);
                }
                $return['status'] = true;
                $return['message'] = '';
                $return['url'] = $url;
            } else {
                $return['message'] = 'Since 2.0, you need BOTH Core and Pro (2.0) packages installed and activated.  Please download the Pro 2.0 pack from My Downloads page in our site, install and activate it.';
                $return['type'] = 'manual_install';
            }
        }
    }

    wp_send_json_success($return);
});


/**
 * Action sto show the toggle button
 */
add_action('advanced_woo_discount_rules_on_settings_head', function () {
    $has_switch = true;
    $page = NULL;
    /* phpcs:ignore WordPress.Security.NonceVerification.Recommended */
    if (isset($_GET['page'])) {
        $page = sanitize_text_field(wp_unslash($_GET['page'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }
    global $awdr_load_version;
    $version = ($awdr_load_version == "v1") ? "v2" : "v1";
    $url = esc_url(admin_url('admin.php?page=' . $page . '&awdr_switch_plugin_to=' . $version));
    $message = 'Discount Rules V2 comes with a better UI and advanced options.';
    $button_text = "Switch to New User Interface";
    if($version == "v1"){
        $has_switch = \Wdr\App\Helpers\Migration::hasSwitchBackOption();
        $message = 'Would you like to switch to older Woo Discount Rules?';
        $button_text = "Click here to Switch back";
    }
    if($has_switch){
        if($version == "v1"){
            $nounce = \Wdr\App\Helpers\Helper::create_nonce('wdr_ajax_switch_version');
        } else {
            $nounce = WDRV1Deprecated::createNonce('wdr_ajax_switch_version');
        }
        echo '<div class="notice notice-danger" style="background: red; color:#fff; padding: 20px;font-size: 13px;font-weight: bold;">
            <p><b>Important: </b>It seems you are using the V1 User Interface. This UI was deprecated since March 2020. We have now removed it as it is no longer supported. Please switch to the new User Interface to create and manage your discount rules.
Click the "Switch" button to start using the new interface.</p>
            </div>';

        echo '<div style="background: #fff;padding: 20px;font-size: 13px;font-weight: bold;">' . esc_html($message) . ' <button class="btn btn-info awdr-switch-version-button" data-version="' . esc_attr($version) . '" data-page="' . esc_attr($page) . '" data-nonce="' . esc_attr($nounce) . '">' . esc_html($button_text) . '</button></div>';
        echo "<div class='wdr_switch_message' style='color:#a00;font-weight: bold;'></div>";
    }
});

/*add_action('advanced_woo_discount_rules_content_next_to_tabs', function () {
    $has_switch = true;
    $page = NULL;
    if (isset($_GET['page'])) {
        $page = sanitize_text_field($_GET['page']);
    }
    global $awdr_load_version;
    $version = ($awdr_load_version == "v1") ? "v2" : "v1";
    if($version == "v1"){
        $has_switch = \Wdr\App\Helpers\Migration::hasSwitchBackOption();
    }
    if($version == "v1"){
        $nounce = \Wdr\App\Helpers\Helper::create_nonce('wdr_ajax_switch_version');
    } else {
        $nounce = WDRV1Deprecated::createNonce('wdr_ajax_switch_version');
    }
    if($has_switch){
        $button_text = "Switch back to Discount Rules 1.x";
        echo '<button class="btn btn-info awdr-switch-version-button awdr-switch-version-button-on-tab" data-version="' . $version . '" data-page="'.$page.'" data-nonce="'.$nounce.'">' . $button_text . '</button>';
    }
});*/

/**
 * Determines if the server environment is compatible with this plugin.
 *
 * @return bool
 * @since 1.0.0
 *
 */
if(!function_exists('awdr_is_environment_compatible')){
    function awdr_is_environment_compatible()
    {
        return version_compare(PHP_VERSION, WDR_REQUIRED_PHP_VERSION, '>=');
    }
}

/**
 * Check the woocommerce is active or not
 * @return bool
 */
if(!function_exists('awdr_is_woo_active')){
    function awdr_is_woo_active()
    {
        $active_plugins = apply_filters('active_plugins', get_option('active_plugins', array()));//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying WordPress core filter
        if (is_multisite()) {
            $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
        }
        return in_array('woocommerce/woocommerce.php', $active_plugins, false) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
    }
}

/**
 * Check woocommerce version is compatibility
 * @return bool
 */
if(!function_exists('awdr_is_woo_compatible')){
    function awdr_is_woo_compatible()
    {
        $current_wc_version = awdr_get_woo_version();
        return version_compare($current_wc_version, WDR_WC_REQUIRED_VERSION, '>=');
    }
}

/**
 * get the version of woocommerce
 * @return mixed|null
 */
if(!function_exists('awdr_get_woo_version')){
    function awdr_get_woo_version()
    {
        if (defined('WC_VERSION')) {
            return WC_VERSION;
        }
        if (!function_exists('get_plugins')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        $plugin_folder = get_plugins('/woocommerce');
        $plugin_file = 'woocommerce.php';
        $wc_installed_version = NULL;
        if (isset($plugin_folder[$plugin_file]['Version'])) {
            $wc_installed_version = $plugin_folder[$plugin_file]['Version'];
        }
        return $wc_installed_version;
    }
}

/**
 * Determines if the WordPress compatible.
 *
 * @return bool
 * @since 1.0.0
 *
 */
if(!function_exists('awdr_is_wp_compatible')){
    function awdr_is_wp_compatible()
    {
        $required_wp_version = 4.9;
        return version_compare(get_bloginfo('version'), $required_wp_version, '>=');
    }
}

if(!function_exists('awdr_check_compatible')){
    function awdr_check_compatible(){
        if (!awdr_is_environment_compatible()) {
            exit(esc_html('This plugin can not be activated because it requires minimum PHP version of ') . ' ' . esc_html(WDR_REQUIRED_PHP_VERSION));
        }
        if (!awdr_is_woo_active()) {
            exit(esc_html('Woocommerce must installed and activated in-order to use Advanced woo discount rules!'));
        }
        if (!awdr_is_woo_compatible()) {
            exit(esc_html(' Advanced woo discount rules requires at least Woocommerce') . ' ' . esc_html(WDR_WC_REQUIRED_VERSION));
        }
    }
}

/**
 * For plugin translation
 * */
/*add_action( 'plugins_loaded', function (){
    if(function_exists('load_plugin_textdomain')){
        load_plugin_textdomain( 'woo-discount-rules', false, basename( dirname( __FILE__ ) ) . '/i18n/languages/' );
    }
});*/
