<?php

namespace DPWAP;

if (!defined('ABSPATH')) {
    exit;
}

use DPWAP\Plugins\Base as pluginBase;
use DPWAP\Themes\Base as themeBase;

class Main
{
    protected static $instance = null;
    public $extensions = array();

    public function __construct()
    {
        $this->addActions();
        $this->loadTextdomain();

        add_action('admin_enqueue_scripts', array($this, 'dpwap_load_common_admin_scripts'));
        add_action('admin_notices', array($this, 'dpwap_render_pro_promo_notice'));
        add_action('admin_notices', array($this, 'dpwap_render_review_notice'));
        add_action('admin_notices', array($this, 'dpwap_render_eventprime_promo_notice'));
        add_action('admin_footer', array($this, 'dpwap_render_pro_welcome_modal'));

        $plugins = new pluginBase();
        $plugins->setup();

        $themes = new themeBase();
        $themes->setup();
    }

    public function addActions()
    {
        add_action('admin_init', array($this, 'dpwap_plugin_redirect'));
        add_action('admin_init', array($this, 'dpwap_refresh_pro_promo_state'));
        add_action('admin_menu', array($this, 'dpwap_load_menus'));
        add_action('wp_ajax_dpwap_dismiss_notice_action', array($this, 'dpwap_dismiss_notice_action'));
        add_action('wp_ajax_dpwap_dismiss_admin_notice', array($this, 'dpwap_dismiss_admin_notice'));
        add_action('admin_footer', [$this, 'dpwap_customize_modal']);
        add_action('wp_ajax_dpwap_customize_plugin', [$this, 'submit_customization_request']);
    }


    public function dpwap_customize_plugin()
    {
        // print_r($_POST);
        // die;

        // if (!isset($_POST['security']) || empty($_POST['security']) || !wp_verify_nonce(wp_unslash($_POST['security']), 'customize_plugin_action')) {
        //     wp_send_json_error('Invalid nonce');
        //     return;
        // }
        if (isset($_POST['user_email']) && filter_var($_POST['user_email'], FILTER_VALIDATE_EMAIL)) {
            wp_send_json_success('Valid email');
        } else {
            wp_send_json_error('Invalid email');
        }
        // wp_send_json_success('Valid email');

    }

    public function loadTextdomain()
    {
        load_textdomain('download-plugin', WP_LANG_DIR . '/download-plugin/download_plugin-' . get_locale() . '.mo');
    }

    /**
     * redirect plugin to menu on activation
     */
    public function dpwap_plugin_redirect()
    {
        if (get_option('download_plugin_do_activation_redirect', false)) {
            delete_option('download_plugin_do_activation_redirect');
            if ( wp_doing_ajax() ) {
                return;
            }

            wp_safe_redirect( admin_url( 'plugins.php' ) );
            exit;
        }
    }

    public function dpwap_refresh_pro_promo_state()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $stored_version = (string) get_option('dpwap_pro_notice_version', '');
        if ($stored_version === DPWAP_VERSION) {
            return;
        }

        update_option('dpwap_pro_notice_version', DPWAP_VERSION);
        update_option('dpwap_pro_notice_cooldown_until', 0);
    }

    public function dpwap_load_menus()
    {
        $dpwap = dpwap_plugin_loaded();
        if (in_array('download-users', $dpwap->extensions)) {
            add_menu_page(__('Download', 'download-plugin'), __('Download', 'download-plugin'), 'manage_options', "dpwap_plugin", array($this, 'dpwap_plugin'), 'dashicons-media-archive', '99');
            // download plugin menu
            add_submenu_page("dpwap_plugin", __('Download Plugins', 'download-plugin'), __('Download Plugins', 'download-plugin'), "manage_options", "dpwap_plugin", array($this, 'dpwap_plugin'));
            // download theme menu
            add_submenu_page("dpwap_plugin", __('Download Themes', 'download-plugin'), __('Download Themes', 'download-plugin'), "manage_options", "dpwap_theme", array($this, 'dpwap_theme'));
            // load all extensions
            // show default download user menu
            if (!in_array('download-users', $dpwap->extensions)) {
                add_submenu_page("dpwap_plugin", __('Download Users', 'download-plugin'), __('Download Users', 'download-plugin'), "manage_options", "dpwap_users", array($this, 'duwap_users_check'));
            }
            // show default download bbPress menu
            /*if ( !in_array( 'download-bbpress-integration', $dpwap->extensions ) ) {
                add_submenu_page( "dpwap_plugin", __('bbPress', 'download-plugin'), __('bbPress', 'download-plugin'), "manage_options", "dpwap_bbpress", array( $this, 'duwap_bbpress_check' ) );
            }*/
        }

        do_action('dpwap_downlad_plugin_menus');

        // Enqueue the JavaScript file
        wp_enqueue_script('customize-modal', plugin_dir_url(__DIR__) . '/assets/js/customize-modal.js', array('jquery'), null, true);

        // Localize script to pass AJAX URL
        wp_localize_script('customize-modal', 'ajax_object', array('ajaxurl' => admin_url('admin-ajax.php')));
        // Enqueue the CSS file
        wp_enqueue_style('customize-modal', plugin_dir_url(__DIR__) . '/assets/css/customize-modal.css');
    }

    public function dpwap_plugin()
    {
        $plugin_info_file = DPWAP_DIR . DS . 'app' . DS . 'Plugins' . DS . 'templates' . DS . 'dpwap_plugin_info.php';
        include($plugin_info_file);
        // Add the modal HTML
    }

    public function dpwap_customize_modal()
    {
?>
        <div id="dtwap-customizeModal" style="display:none;">
            <div class="dpmodal-content">
                <span class="dtwap-close-button" onclick="handleCloseButtonClick()"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x-lg" viewBox="0 0 16 16">
                        <path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8z" />
                    </svg></span>
                <script>
                    function handleCloseButtonClick() {
                        // Close logic here (if any)
                        location.reload(); // Refresh the page
                    }
                </script>
                <div class="modal-logo-text">
                    <h1><?php esc_html_e("Download Plugin", "download-plugin") ?></h1>
                    <span> <img src="<?php echo plugin_dir_url(__DIR__) . 'assets/images/mg-logo.svg'; ?>" alt="Success Icon" class="response-icon" width="100px"></span>
                </div>
                <h2><?php esc_html_e("Customize Your Plugin to Match Your Needs", "download-plugin") ?></h2>
                <p id="p3"><?php esc_html_e("Whether you need additional features, design changes, or integrations, our team will tailor the plugin to your exact requirements.", 'download-plugin') ?></p>
                <form id="dtwap-customizeForm" method="post">
                    <?php wp_nonce_field('customize_plugin_action', 'customize_plugin_nonce'); ?>

                    <label for="pluginSelect">Select Plugin:</label>
                    <select id="pluginSelect" name="plugin" required>
                        <?php
                        $active_plugins = get_option('active_plugins');
                        $all_plugins = get_plugins();
                        foreach ($active_plugins as $plugin_file) {
                            if (isset($all_plugins[$plugin_file])) {
                                echo '<option value="' . esc_attr($all_plugins[$plugin_file]['Name']) . '">' . esc_html($all_plugins[$plugin_file]['Name']) . '</option>';
                            }
                        }
                        ?>
                    </select>
                    <label for="email"><?php esc_html_e("Email Address:", "download-plugin") ?> </label>
                    <input type="email" id="email" name="email">
                    <label for="customizationType"><?php esc_html_e("Details:", "download-plugin") ?></label>
                    <textarea id="customizationType" name="customizationType" placeholder="Describe Your Customization Needs"></textarea>
                    <div class="dp-button-block">
                        <button type="submit" class="button button-primary" id="dpwap-submit"><?php esc_html_e("Submit", 'download-plugin') ?></button>
                        <span class="spinner is-active" style="display:none;" aria-hidden="true"></span>
                    </div>
                    <span class="dtwap-close-button"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x-lg" viewBox="0 0 16 16">
                            <path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8z" />
                        </svg></span>
                </form>
                <div id="formResponse" style="display:none;">
                    <div id="successResponse" style="display:none;">
                        <img src="<?php echo plugin_dir_url(__DIR__) . 'assets/images/success-icon.svg'; ?>" alt="Success Icon" class="response-icon">
                        <h2><?php esc_html_e("Request Submitted!", "download-plugin") ?></h2>
                        <p><?php esc_html_e("Thank you for your request! Our team will review it and respond within 12-24 hours. Please check your spam folder if you don’t see our email.", "download-plugin") ?></p>
                        <p>You can also track your tickets directly on our Helpdesk at <a href="https://metagauss.com/customization-help/" target="_blank">https://metagauss.com/customization-help/</a>, where you can add additional details, images, or files as needed.</p>
                    </div>
                    <div id="failureResponse" style="display:none;">
                        <img src="<?php echo plugin_dir_url(__DIR__) . 'assets/images/failure-icon.svg'; ?>" alt="Failure Icon" class="response-icon">
                        <h2><?php esc_html_e("Submission Failed", "download-plugin") ?></h2>
                        <p>Something went wrong. Please try again or create a ticket manually at <a href="https://metagauss.com/customization-help/" target="_blank" rel="noopener noreferrer">https://metagauss.com/customization-help/</a>.</p>
                        <label for="userRequirements"><?php esc_html_e("Your Requirements:", "download-plugin") ?></label>
                        <textarea id="userRequirements" readonly></textarea>
                        <button id="copyButton" class="button button-secondary">Copy</button>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    function submit_customization_request()
    {
        // if (isset($_POST['security']) && !wp_verify_nonce(wp_unslash($_POST['security']), 'customize_plugin_action')) {
        //     wp_send_json_error(array('message' => 'valid nonce.'));
        //     return;
        // }
        // Check if the current user has the necessary permission
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => esc_html__('You do not have permission to perform this action.', 'download-plugin')));
            return;
        }

        if (!isset($_POST['security']) || empty($_POST['security']) || !wp_verify_nonce(wp_unslash($_POST['security']), 'customize_plugin_action')) {
            wp_send_json_error(array('message' => 'Invalid nonce.'));
            return;
        }
        $user_email = isset($_POST['user_email']) ? sanitize_email(wp_unslash($_POST['user_email'])) : '';
        if (!isset($_POST['user_email']) || !filter_var($_POST['user_email'], FILTER_VALIDATE_EMAIL)) {
            wp_send_json_error(array('message' => 'Please enter a valid email address.'));
            return;
        }

        // Check if customization type is provided
        $customization_type = isset($_POST['customizationType']) ? sanitize_textarea_field(wp_unslash($_POST['customizationType'])) : '';
        if (empty($_POST['customizationType'])) {
            wp_send_json_error(array('message' => esc_html__('Please provide details about your customization request.', 'download-plugin')));
            return;
        }

        // Prepare email details
        $to = 'support@metagauss.com';
        $subject = 'WordPress Support Request';
        $user_email = sanitize_email($_POST['user_email']);
        $plugin = sanitize_text_field($_POST['plugin_select']);
        $customization_type = sanitize_textarea_field($_POST['customizationType']);

        // Construct the HTML email body
        $message = "
    <html>
    <body>
        <h2>WordPress Support Request</h2>
        <p>You have received a new customization request. Below are the details:</p>
        <table border='1' cellpadding='10' cellspacing='0' style='border-collapse: collapse; width: 100%;'>
            <tr>
                <th align='left'>Field</th>
                <th align='left'>Submitted Value</th>
            </tr>
            <tr>
                <td>Plugin</td>
                <td>{$plugin}</td>
            </tr>
            <tr>
                <td>Email</td>
                <td>{$user_email}</td>
            </tr>
            <tr>
                <td>Customization Needs</td>
                <td>{$customization_type}</td>
            </tr>
        </table>
    </body>
    </html>";

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $user_email,
        );

        // Send the email
        if (wp_mail($to, $subject, $message, $headers)) {
            wp_send_json_success(array('message' => 'Your request has been submitted successfully. We will get back to you shortly.'));
        } else {
            wp_send_json_error(array(
                'message' => 'Something went wrong. Please try again or create a ticket manually at https://metagauss.freshdesk.com/support/tickets/new.'
            ));
        }
    }


    public function dpwap_theme()
    {
        $theme_info_file = DPWAP_DIR . DS . 'app' . DS . 'Themes' . DS . 'templates' . DS . 'dpwap_theme_info.php';
        include_once $theme_info_file;
    }

    public function duwap_users_check()
    {
        $users_info_file = DPWAP_DIR . DS . 'app' . DS . 'Users' . DS . 'templates' . DS . 'dpwap_users_info.php';
        include_once $users_info_file;
    }

    public function duwap_bbpress_check()
    {
        $bbpress_info_file = DPWAP_DIR . DS . 'app' . DS . 'bbPress' . DS . 'templates' . DS . 'dpwap_bbpress_info.php';
        include_once $bbpress_info_file;
    }

    public function dpwap_load_common_admin_scripts()
    {
        wp_enqueue_script('dpwap_common_js', DPWAP_URL . 'assets/js/dpwap-common.js', array('jquery'), DPWAP_VERSION, true);
        wp_localize_script('dpwap_common_js', 'admin_vars', array('admin_url' => admin_url(), 'ajax_url' => admin_url('admin-ajax.php'),  'nonce' => wp_create_nonce('dpwap_secure_action')));
        wp_enqueue_style('dpwap_common_css', DPWAP_URL . 'assets/css/dpwap-common.css', array(), DPWAP_VERSION);
    }

    public static function dpwap_record_free_download($count = 1)
    {
        if (function_exists('dpwap_is_pro_active') && dpwap_is_pro_active()) {
            return;
        }

        $count = max(1, absint($count));
        $current_count = (int) get_option('dpwap_free_download_count', 0);
        update_option('dpwap_free_download_count', $current_count + $count);
    }

    public static function dpwap_get_free_download_count()
    {
        return (int) get_option('dpwap_free_download_count', 0);
    }

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Admin notice
     */
    // public function dpwap_general_admin_notice()
    // {
    //     $dpwap = dpwap_plugin_loaded();
    //     $get_dismiss_option = get_option('dpwap_dismiss_offer_notice', false);
    //     if (empty($dpwap->extensions) && empty($get_dismiss_option)) {
    //         // echo '<div class="dpwap-notice-pre notice notice-info is-dismissible">
    //         //     <p><b>Download Plugin</b> now has add-on for downloading and uploading your website\'s user accounts. <a href="https://metagauss.com/wordpress-users-import-export-plugin/?utm_source=dp_plugin&utm_medium=admin_notice&utm_campaign=download_users_addon" target="_new">Click here </a>to get it now!</p>
    //         // </div>';
    //     }
    // }


    protected function dpwap_is_high_intent_screen()
    {
        global $pagenow;

        if (in_array($pagenow, array('plugins.php', 'plugin-install.php'), true)) {
            return true;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || empty($screen->base)) {
            return false;
        }

        return in_array($screen->base, array('plugins', 'plugins-network', 'plugin-install'), true);
    }

    protected function dpwap_get_eventprime_promo_data()
    {
        $rival_plugins = array(
            'tec' => array(
                'label'     => 'The Events Calendar',
                'plugins'   => array('the-events-calendar/the-events-calendar.php'),
                'classes'   => array('Tribe__Events__Main'),
                'constants' => array(),
            ),
            'wp_event_manager' => array(
                'label'     => 'WP Event Manager',
                'plugins'   => array('wp-event-manager/wp-event-manager.php'),
                'classes'   => array('WP_Event_Manager'),
                'constants' => array(),
            ),
            'modern_events_calendar' => array(
                'label'     => 'Modern Events Calendar',
                'plugins'   => array(
                    'modern-events-calendar-lite/modern-events-calendar-lite.php',
                    'modern-events-calendar/mec-init.php',
                ),
                'classes'   => array('MEC'),
                'constants' => array('MEC_VERSION'),
            ),
            'eventin' => array(
                'label'     => 'Eventin',
                'plugins'   => array('wp-event-solution/eventin.php'),
                'classes'   => array('Wpeventin'),
                'constants' => array('EVENTIN_VERSION'),
            ),
            'amelia' => array(
                'label'     => 'Amelia',
                'plugins'   => array('ameliabooking/ameliabooking.php'),
                'classes'   => array('AmeliaBooking\\Plugin'),
                'constants' => array('AMELIA_VERSION'),
            ),
            'events_manager' => array(
                'label'     => 'Events Manager',
                'plugins'   => array('events-manager/events-manager.php'),
                'classes'   => array('EM_Object'),
                'constants' => array('EM_VERSION'),
            ),
        );

        foreach ($rival_plugins as $plugin_key => $plugin_data) {
            if ($this->dpwap_is_eventprime_rival_active($plugin_data)) {
                return array(
                    'key'   => $plugin_key,
                    'label' => $plugin_data['label'],
                    'url'   => $this->dpwap_get_eventprime_promo_url($plugin_key),
                );
            }
        }

        return false;
    }

    protected function dpwap_get_eventprime_promo_url($plugin_key)
    {
        $homepage_url = 'https://theeventprime.com?utm_source=download_plugin&utm_medium=admin_notice&utm_campaign=eventprime_promo';
        $tec_url      = 'https://theeventprime.com/the-events-calendar-alternative?utm_source=download_plugin&utm_medium=admin_notice&utm_campaign=eventprime_tec_promo';

        if ('tec' === $plugin_key) {
            return apply_filters('dpwap_eventprime_tec_promo_url', $tec_url, $plugin_key);
        }

        return apply_filters('dpwap_eventprime_promo_url', $homepage_url, $plugin_key);
    }

    protected function dpwap_is_eventprime_rival_active($plugin_data)
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!empty($plugin_data['plugins'])) {
            foreach ($plugin_data['plugins'] as $plugin_file) {
                if (is_plugin_active($plugin_file)) {
                    return true;
                }
            }
        }

        if (!empty($plugin_data['classes'])) {
            foreach ($plugin_data['classes'] as $class_name) {
                if (class_exists($class_name)) {
                    return true;
                }
            }
        }

        if (!empty($plugin_data['constants'])) {
            foreach ($plugin_data['constants'] as $constant_name) {
                if (defined($constant_name)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function dpwap_is_eventprime_notice_screen()
    {
        global $pagenow;

        return 'plugins.php' === $pagenow;
    }

    protected function dpwap_should_suppress_eventprime_notice_for_profilegrid()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('profilegrid-user-profiles-groups-and-communities/profile-magic.php');
    }

    protected function dpwap_should_show_eventprime_notice()
    {
        if (!current_user_can('manage_options') || !$this->dpwap_is_eventprime_notice_screen()) {
            return false;
        }

        if ($this->dpwap_should_suppress_eventprime_notice_for_profilegrid()) {
            return false;
        }

        if ((int) get_option('dpwap_eventprime_notice_dismissed_at', 0) > 0) {
            return false;
        }

        return (bool) $this->dpwap_get_eventprime_promo_data();
    }

    public function dpwap_render_eventprime_promo_notice()
    {
        if (!$this->dpwap_should_show_eventprime_notice()) {
            return;
        }

        $promo_data = $this->dpwap_get_eventprime_promo_data();
        ?>
        <div class="notice notice-info is-dismissible dpwap-dismissible" data-notice="eventprime-notice">
            <p>
                <?php $is_tec = 'tec' === $promo_data['key']; ?>
                <strong><?php echo esc_html($is_tec ? __('Using The Events Calendar?', 'download-plugin') : __('Need an event plugin alternative?', 'download-plugin')); ?></strong>
                <?php echo ' ' . esc_html($is_tec ? __('See how EventPrime handles events, registration, and paid event workflows inside WordPress.', 'download-plugin') : __('Explore EventPrime for WordPress-native event management and paid event workflows.', 'download-plugin')); ?>
                <a href="<?php echo esc_url($promo_data['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($is_tec ? __('Discover Why Users Switch to EventPrime', 'download-plugin') : __('Explore EventPrime', 'download-plugin')); ?></a>
            </p>
        </div>
        <?php
    }

    public function dpwap_render_pro_promo_notice()
    {
        if (!$this->dpwap_should_show_pro_notice()) {
            return;
        }

        $pro_url                    = dpwap_add_utm_params( add_query_arg( 'discount', 'DOWNLOAD50', 'https://theeventprime.com/checkout/?download_id=43730&edd_action=add_to_cart&edd_options[price_id][]=1' ), 'admin_notice', 'pro_upgrade', 'upgrade_to_pro' );
        $is_legacy_notice_audience  = $this->dpwap_is_legacy_pro_notice_audience();
        $notice_audience            = $is_legacy_notice_audience ? 'legacy' : 'recent';
        $discount_text              = $is_legacy_notice_audience ? __('Limited-time Pro offer. Expires in 48 Hrs.', 'download-plugin') : __('New-user offer. Expires in 48 Hrs.', 'download-plugin');
        ?>
        <div class="notice notice-info is-dismissible dpwap-dismissible dpwap-pro-notice" data-notice="pro-notice" data-audience="<?php echo esc_attr($notice_audience); ?>">
            <div class="dpwap-pro-notice__content">
                <h2 class="dpwap-pro-notice__title"><?php esc_html_e('Unlock Everything with Download Plugin Pro', 'download-plugin'); ?></h2>
                <p class="dpwap-pro-notice__text"><?php esc_html_e('You’re using the Free Version for quick downloads. Pro adds tools to upload posts, pages, users, and media ZIP files, download and restore full site backups, and much more.', 'download-plugin'); ?></p>
                <div class="dpwap-pro-notice__actions">
                    <span class="dpwap-pro-notice__discount"><?php echo esc_html($discount_text); ?></span>
                    <?php if ($is_legacy_notice_audience) : ?>
                        <a href="<?php echo esc_url($pro_url); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary dpwap-pro-button" data-action="open-pro-modal" data-checkout-url="<?php echo esc_url($pro_url); ?>">
                            <?php esc_html_e('See what Pro adds', 'download-plugin'); ?>
                        </a>
                    <?php else : ?>
                        <a href="<?php echo esc_url($pro_url); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary dpwap-pro-button" data-action="open-pro-modal" data-checkout-url="<?php echo esc_url( $pro_url ); ?>">
                            <?php esc_html_e('Upgrade to Pro', 'download-plugin'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    protected function dpwap_should_show_pro_notice()
    {
        if (!current_user_can('manage_options') || !$this->dpwap_is_high_intent_screen()) {
            return false;
        }

        if (function_exists('dpwap_is_pro_active') && dpwap_is_pro_active()) {
            return false;
        }

        if ($this->dpwap_is_pro_notice_test_mode()) {
            return true;
        }

        if ($this->dpwap_should_show_welcome_modal()) {
            return false;
        }

        $now = time();
        $activation_time = (int) get_option('dpwap_pro_last_activation_time', 0);
        if ($activation_time > 0 && ($activation_time + DAY_IN_SECONDS) > $now) {
            return false;
        }

        $cooldown_until = (int) get_option('dpwap_pro_notice_cooldown_until', 0);
        return $cooldown_until <= $now;
    }

    protected function dpwap_is_legacy_pro_notice_audience()
    {
        $forced_audience = $this->dpwap_get_forced_pro_notice_audience();
        if ('legacy' === $forced_audience) {
            return true;
        }

        if ('recent' === $forced_audience) {
            return false;
        }

        return 0 >= (int) get_option('dpwap_pro_last_activation_time', 0);
    }

    protected function dpwap_should_render_legacy_pro_notice_modal()
    {
        return $this->dpwap_is_legacy_pro_notice_audience() && $this->dpwap_should_show_pro_notice();
    }

    protected function dpwap_is_pro_notice_test_mode()
    {
        return '' !== $this->dpwap_get_forced_pro_notice_audience();
    }

    protected function dpwap_get_forced_pro_notice_audience()
    {
        if (!current_user_can('manage_options')) {
            return '';
        }

        foreach (array('dpwap_force_pro_notice', 'dpwap_test_pro_notice') as $flag) {
            if (!isset($_GET[$flag])) {
                continue;
            }

            $value = sanitize_key(wp_unslash($_GET[$flag]));
            if (in_array($value, array('legacy', 'recent'), true)) {
                return $value;
            }

            if (in_array($value, array('1', 'true', 'yes'), true)) {
                return 'auto';
            }
        }

        return '';
    }

    protected function dpwap_is_review_notice_screen()
    {
        global $pagenow;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (in_array($pagenow, array('plugins.php', 'themes.php'), true)) {
            return true;
        }

        if (!$screen || empty($screen->base)) {
            return false;
        }

        return in_array($screen->base, array('plugins', 'themes', 'plugins-network'), true);
    }

    protected function dpwap_should_show_review_notice()
    {
        if (
            !current_user_can('manage_options') ||
            (function_exists('dpwap_is_pro_active') && dpwap_is_pro_active())
        ) {
            return false;
        }

        if (!$this->dpwap_is_review_notice_screen()) {
            return false;
        }

        if ($this->dpwap_is_review_notice_test_mode()) {
            return true;
        }

        if ($this->dpwap_should_show_welcome_modal() || $this->dpwap_should_show_pro_notice()) {
            return false;
        }

        if ((int) get_option('dpwap_review_notice_dismissed_at', 0) > 0) {
            return false;
        }

        return self::dpwap_get_free_download_count() >= 10;
    }

    protected function dpwap_is_review_notice_test_mode()
    {
        if (!current_user_can('manage_options')) {
            return false;
        }

        return isset($_GET['dpwap_test_review_notice']) && '1' === sanitize_text_field(wp_unslash($_GET['dpwap_test_review_notice']));
    }

    public function dpwap_render_review_notice()
    {
        if (!$this->dpwap_should_show_review_notice()) {
            return;
        }

        $review_url = dpwap_add_utm_params( 'https://wordpress.org/support/plugin/download-plugin/reviews/#new-post', 'admin_notice', 'review_request', 'leave_a_review' );
        ?>
        <div class="notice notice-info is-dismissible dpwap-dismissible dpwap-review-notice" data-notice="review-notice">
            <div class="dpwap-review-notice__content">
                <h2 class="dpwap-review-notice__title"><?php esc_html_e('Nice! You’ve reached 10 downloads.', 'download-plugin'); ?></h2>
                <p class="dpwap-review-notice__text"><?php esc_html_e('Thanks for using Download Plugin. If it’s been useful, please rate us on WordPress and let other people know about it.', 'download-plugin'); ?></p>
                <div class="dpwap-review-notice__actions">
                    <a href="<?php echo esc_url($review_url); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary dpwap-review-button" data-action="review">
                        <?php esc_html_e('Leave a review', 'download-plugin'); ?>
                    </a>
                    <button type="button" class="button button-secondary dpwap-review-button-secondary" data-action="dismiss">
                        <?php esc_html_e('No, thank you', 'download-plugin'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    protected function dpwap_should_show_welcome_modal()
    {
        global $pagenow;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (
            !current_user_can('manage_options') ||
            (
                'plugins.php' !== $pagenow &&
                (
                    !$screen ||
                    !in_array($screen->base, array('plugins', 'plugins-network'), true)
                )
            ) ||
            dpwap_is_pro_active() ||
            $this->dpwap_is_pro_notice_test_mode()
        ) {
            return false;
        }

        return (bool) get_option('dpwap_pro_welcome_modal_pending', false);
    }

    protected function dpwap_should_render_pro_welcome_modal_shell()
    {
        global $pagenow;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (
            !current_user_can('manage_options') ||
            (
                'plugins.php' !== $pagenow &&
                (
                    !$screen ||
                    !in_array($screen->base, array('plugins', 'plugins-network'), true)
                )
            ) ||
            dpwap_is_pro_active()
        ) {
            return false;
        }

        return true;
    }

    public function dpwap_render_pro_welcome_modal()
    {
        $auto_open_modal = $this->dpwap_should_show_welcome_modal();
        if (
            !$auto_open_modal &&
            !$this->dpwap_should_render_legacy_pro_notice_modal() &&
            !$this->dpwap_should_render_pro_welcome_modal_shell()
        ) {
            return;
        }

        $pro_url     = dpwap_add_utm_params( 'https://theeventprime.com/checkout/?download_id=43730&edd_action=add_to_cart&edd_options[price_id][]=1', 'admin_modal', 'pro_upgrade', 'upgrade_to_pro' );
        $guide_url   = dpwap_add_utm_params( DPWAP_PRO_GUIDE_URL, 'admin_modal', 'guide', 'learn_how_it_works' );
        $glint_title = __('Download Plugin', 'download-plugin');
        ?>
        <div id="dpwap-pro-welcome-modal" class="dpwap-pro-modal" aria-hidden="true" data-auto-open="<?php echo $auto_open_modal ? '1' : '0'; ?>">
            <div class="dpwap-pro-modal__backdrop"></div>
            <div class="dpwap-pro-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="dpwap-pro-welcome-title">
                <button type="button" class="dpwap-pro-modal__close" data-action="dismiss" aria-label="<?php esc_attr_e('Close dialog', 'download-plugin'); ?>">
                    <span aria-hidden="true">&#10005;</span>
                </button>
                <h2 id="dpwap-pro-welcome-title">
                    <?php esc_html_e('Welcome to', 'download-plugin'); ?>
                    <span class="dpwap-pro-modal__glint" data-text="<?php echo esc_attr($glint_title); ?>" aria-label="<?php echo esc_attr($glint_title); ?>"><?php foreach (str_split($glint_title) as $index => $character) : ?><span class="dpwap-pro-modal__glint-letter<?php echo ' ' === $character ? ' dpwap-pro-modal__glint-letter--space' : ''; ?>" style="--dpwap-glint-delay: <?php echo esc_attr(number_format(0.42 + ($index * 0.17), 3)); ?>s;" aria-hidden="true"><?php echo ' ' === $character ? '&nbsp;' : esc_html($character); ?></span><?php endforeach; ?></span><span class="dpwap-pro-modal__bang"><?php esc_html_e('!', 'download-plugin'); ?></span>
                </h2>
                <p class="dpwap-pro-modal__intro"></p>
                <div class="dpwap-pro-modal__grid">
                    <div class="dpwap-pro-modal__column">
                        <h3><?php esc_html_e('FREE VERSION', 'download-plugin'); ?></h3>
                        <ul>
                            <li><?php esc_html_e('Download any free or paid plugin as zip in 1-click', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Download multiple plugins in bulk', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Download free and paid themes', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Download users', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Download posts, custom posts and pages', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Download comments', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Download categories and tags', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Upload multiple plugins', 'download-plugin'); ?></li>
                        </ul>
                    </div>
                    <div class="dpwap-pro-modal__column dpwap-pro-modal__column--accent">
                        <h3><?php esc_html_e('WHAT PRO ADDS', 'download-plugin'); ?></h3>
                        <ul>
                            <li><?php esc_html_e('Upload posts and pages', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Upload users', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Upload comments', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Upload categories and tags', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Upload media files as zip', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Download site database', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Download and restore partial site backups', 'download-plugin'); ?></li>
                            <li><?php esc_html_e('Download and restore full site backups', 'download-plugin'); ?></li>
                        </ul>
                    </div>
                </div>
                <div class="dpwap-pro-modal__actions">
                    <a href="<?php echo esc_url($guide_url); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary dpwap-pro-button" data-action="guide">
                        <?php esc_html_e('Learn how it works', 'download-plugin'); ?>
                    </a>
                    <a href="<?php echo esc_url($pro_url); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary dpwap-pro-button-secondary dpwap-pro-modal__checkout" data-default-url="<?php echo esc_url($pro_url); ?>">
                        <?php esc_html_e('Upgrade to Pro', 'download-plugin'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    public function dpwap_dismiss_admin_notice()
    {
        check_ajax_referer('dpwap_secure_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        $notice = isset($_POST['notice']) ? sanitize_text_field(wp_unslash($_POST['notice'])) : '';
        switch ($notice) {
            case 'pro-notice':
                $dismissed_at = (int) get_option('dpwap_pro_notice_dismissed_at', 0);
                $cooldown_days = $dismissed_at > 0 ? 90 : 30;
                $now = time();
                update_option('dpwap_pro_notice_dismissed_at', $now);
                update_option('dpwap_pro_notice_cooldown_until', $now + ($cooldown_days * DAY_IN_SECONDS));
                break;
            case 'welcome-modal':
                update_option('dpwap_pro_welcome_modal_pending', 0);
                update_option('dpwap_pro_welcome_modal_dismissed', 1);
                break;
            case 'review-notice':
                update_option('dpwap_review_notice_dismissed_at', time());
                break;
            case 'eventprime-notice':
                update_option('dpwap_eventprime_notice_dismissed_at', time());
                break;
        }

        wp_send_json_success();
    }

    public function dpwap_dismiss_notice_action()
    {
        add_option('dpwap_dismiss_offer_notice', true);
        wp_send_json_success('Notice Dismissed');
    }
}
