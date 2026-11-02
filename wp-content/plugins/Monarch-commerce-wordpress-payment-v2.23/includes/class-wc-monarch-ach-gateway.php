<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Monarch_ACH_Gateway extends WC_Payment_Gateway {

    /**
     * Custom Monarch config values.
     * Declared explicitly to avoid PHP 8.2+ "dynamic property" deprecation notices
     * during AJAX checkout (which can corrupt WooCommerce JSON responses).
     *
     * @var string
     */
    public $api_key = '';

    /** @var string */
    public $app_id = '';

    /** @var string */
    public $merchant_org_id = '';

    /** @var string */
    public $partner_name = '';

    /**
     * When true, checkout bank modal offers manual routing/account entry in addition to bank linking.
     *
     * @var bool
     */
    public $allow_manual_account_checkout = false;

    /** @var bool */
    public $testmode = false;

    /** Default DOB when the field is hidden at checkout (mm/dd/yyyy). */
    private const MONARCH_CHECKOUT_DOB_DEFAULT = '01/01/2001';

    /**
     * DOB from checkout POST normalized to m/d/Y for Monarch; falls back if missing or unparseable.
     */
    private function get_post_monarch_dob_for_api() {
        $raw = '';
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checkout POST; checkout nonce verified by WC before payment fields are read.
        if (isset($_POST['monarch_dob'])) {
            $raw = sanitize_text_field(wp_unslash($_POST['monarch_dob']));
        } elseif (isset($_POST['dob'])) {
            $raw = sanitize_text_field(wp_unslash($_POST['dob']));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ($raw === '') {
            $raw = self::MONARCH_CHECKOUT_DOB_DEFAULT;
        }
        $timestamp = strtotime($raw);
        return $timestamp ? gmdate('m/d/Y', $timestamp) : self::MONARCH_CHECKOUT_DOB_DEFAULT;
    }

    /**
     * Read a text value from POST safely.
     */
    private function post_text($key, $default = '') {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout; checkout nonce is verified by WooCommerce before payment runs.
        if (!isset($_POST[$key])) {
            return $default;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Same as above.
        return sanitize_text_field(wp_unslash($_POST[$key]));
    }

    /**
     * Read an email value from POST safely.
     */
    private function post_email($key, $default = '') {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout; checkout nonce is verified by WooCommerce before payment runs.
        if (!isset($_POST[$key])) {
            return $default;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Same as above.
        return sanitize_email(wp_unslash($_POST[$key]));
    }

    public function __construct() {
        $this->id = 'monarch_ach';
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = 'Monarch ACH';
        $this->method_description = 'Secure ACH bank transfers via Monarch payment gateway';
        $this->supports = array('products');
        
        $this->init_form_fields();
        $this->init_settings();
        
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        
        $this->api_key = $this->testmode ? $this->get_option('test_api_key') : $this->get_option('live_api_key');
        $this->app_id = $this->testmode ? $this->get_option('test_app_id') : $this->get_option('live_app_id');
        $this->merchant_org_id = $this->testmode ? $this->get_option('test_merchant_org_id') : $this->get_option('live_merchant_org_id');
        $this->partner_name = $this->get_option('partner_name');
        $this->allow_manual_account_checkout = 'yes' === $this->get_option('allow_manual_account_checkout');
        
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_loaded', array($this, 'register_monarch_checkout_scripts'), 99);
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

        // Order-view "ACH Payment Details" is output once from WC_Monarch_ACH_Gateway_Plugin::display_transaction_details_for_customer()
        // (do not register woocommerce_order_details_after_order_table here — it would duplicate the section).

        // AJAX hooks
        add_action('wp_ajax_monarch_create_customer', array($this, 'ajax_create_customer'));
        add_action('wp_ajax_nopriv_monarch_create_customer', array($this, 'ajax_create_customer'));
        add_action('wp_ajax_monarch_add_bank_account', array($this, 'ajax_add_bank_account'));
        add_action('wp_ajax_nopriv_monarch_add_bank_account', array($this, 'ajax_add_bank_account'));
        add_action('wp_ajax_monarch_create_organization', array($this, 'ajax_create_organization'));
        add_action('wp_ajax_nopriv_monarch_create_organization', array($this, 'ajax_create_organization'));
        add_action('wp_ajax_monarch_bank_connection_complete', array($this, 'ajax_bank_connection_complete'));
        add_action('wp_ajax_nopriv_monarch_bank_connection_complete', array($this, 'ajax_bank_connection_complete'));
        add_action('wp_ajax_monarch_check_bank_status', array($this, 'ajax_check_bank_status'));
        add_action('wp_ajax_nopriv_monarch_check_bank_status', array($this, 'ajax_check_bank_status'));
        add_action('wp_ajax_monarch_get_latest_paytoken', array($this, 'ajax_get_latest_paytoken'));
        add_action('wp_ajax_nopriv_monarch_get_latest_paytoken', array($this, 'ajax_get_latest_paytoken'));
        add_action('wp_ajax_monarch_disconnect_bank', array($this, 'ajax_disconnect_bank'));
        add_action('wp_ajax_nopriv_monarch_disconnect_bank', array($this, 'ajax_disconnect_bank'));
        add_action('wp_ajax_monarch_manual_bank_entry', array($this, 'ajax_manual_bank_entry'));
        add_action('wp_ajax_nopriv_monarch_manual_bank_entry', array($this, 'ajax_manual_bank_entry'));
        add_action('wp_ajax_monarch_get_bank_linking_url', array($this, 'ajax_get_bank_linking_url'));
        add_action('wp_ajax_nopriv_monarch_get_bank_linking_url', array($this, 'ajax_get_bank_linking_url'));
        add_action('wp_ajax_monarch_update_organization', array($this, 'ajax_update_organization'));
        add_action('wp_ajax_nopriv_monarch_update_organization', array($this, 'ajax_update_organization'));

        // If the customer disconnected their bank, force a "fresh" checkout state (no prefilled fields).
        add_action('wp_loaded', array($this, 'maybe_force_checkout_reset_after_disconnect'), 1);
        add_action('woocommerce_before_checkout_form', array($this, 'maybe_render_disconnect_notice_banner'), 1);
        add_filter('woocommerce_checkout_get_value', array($this, 'filter_blank_checkout_values_after_disconnect'), 10, 2);
    }
    
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable Monarch ACH Payment',
                'default' => 'no'
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'This controls the title displayed during checkout.',
                'default' => 'ACH Bank Transfer',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'This controls the description displayed during checkout.',
                'default' => 'Pay securely using your bank account via ACH transfer.',
            ),
            'allow_manual_account_checkout' => array(
                'title' => 'Allow Manual Account Checkout',
                'label' => 'Show manual bank account entry in the connect-bank popup (in addition to secure bank linking)',
                'type' => 'checkbox',
                'description' => 'When disabled, customers only see automatic bank linking; routing/account number entry is hidden.',
                'default' => 'no',
                'desc_tip' => true,
            ),
            'testmode' => array(
                'title' => 'Test mode',
                'label' => 'Enable Test Mode',
                'type' => 'checkbox',
                'description' => 'Place the payment gateway in test mode using sandbox API credentials.',
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'api_credentials_heading' => array(
                'title' => 'API Credentials',
                'type' => 'title',
                'description' => 'Enter your Monarch API credentials below. You can get these from your Monarch dashboard.',
            ),
            'partner_name' => array(
                'title' => 'Partner Name',
                'type' => 'text',
                'description' => 'Your partner name as registered with Monarch (e.g., "yourcompany").',
                'default' => '',
                'desc_tip' => true,
                'placeholder' => 'Enter your partner name',
            ),
            'test_credentials_heading' => array(
                'title' => 'Sandbox/Test Credentials',
                'type' => 'title',
                'description' => 'These credentials are used when Test Mode is enabled.',
            ),
            'test_api_key' => array(
                'title' => 'Sandbox API Key',
                'type' => 'password',
                'description' => 'Your sandbox API key from Monarch (e.g., "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx").',
                'default' => '',
                'desc_tip' => true,
                'placeholder' => 'Enter sandbox API key',
            ),
            'test_app_id' => array(
                'title' => 'Sandbox App ID',
                'type' => 'text',
                'description' => 'Your sandbox App ID from Monarch (e.g., "a1b2c3d4").',
                'default' => '',
                'desc_tip' => true,
                'placeholder' => 'Enter sandbox App ID',
            ),
            'test_merchant_org_id' => array(
                'title' => 'Sandbox Merchant Org ID',
                'type' => 'text',
                'description' => 'Your sandbox merchant organization ID from Monarch (e.g., "1234567890").',
                'default' => '',
                'desc_tip' => true,
                'placeholder' => 'Enter sandbox Merchant Org ID',
            ),
            'live_credentials_heading' => array(
                'title' => 'Production/Live Credentials',
                'type' => 'title',
                'description' => 'These credentials are used when Test Mode is disabled.',
            ),
            'live_api_key' => array(
                'title' => 'Live API Key',
                'type' => 'password',
                'description' => 'Your production API key from Monarch.',
                'default' => '',
                'desc_tip' => true,
                'placeholder' => 'Enter live API key',
            ),
            'live_app_id' => array(
                'title' => 'Live App ID',
                'type' => 'text',
                'description' => 'Your production App ID from Monarch.',
                'default' => '',
                'desc_tip' => true,
                'placeholder' => 'Enter live App ID',
            ),
            'live_merchant_org_id' => array(
                'title' => 'Live Merchant Org ID',
                'type' => 'text',
                'description' => 'Your production merchant organization ID from Monarch.',
                'default' => '',
                'desc_tip' => true,
                'placeholder' => 'Enter live Merchant Org ID',
            ),
        );
    }
    
    /**
     * Register shared classic checkout JS/CSS early so Cart/Checkout Blocks can depend on `wc-monarch-ach`
     * (blocks register on init priority 5).
     */
    public function register_monarch_checkout_scripts() {
        if (wp_script_is('wc-monarch-ach', 'registered')) {
            return;
        }
        if ('no' === $this->enabled || empty($this->api_key) || empty($this->app_id)) {
            return;
        }
        if (!function_exists('WC')) {
            return;
        }

        $js_path = WC_MONARCH_ACH_PLUGIN_PATH . 'assets/js/monarch-ach.js';
        $js_version = file_exists($js_path) ? (string) filemtime($js_path) : WC_MONARCH_ACH_VERSION;

        wp_register_script(
            'wc-monarch-ach',
            WC_MONARCH_ACH_PLUGIN_URL . 'assets/js/monarch-ach.js',
            array('jquery'),
            $js_version,
            true
        );

        $css_path = WC_MONARCH_ACH_PLUGIN_PATH . 'assets/css/monarch-ach.css';
        $css_version = file_exists($css_path) ? (string) filemtime($css_path) : WC_MONARCH_ACH_VERSION;

        wp_register_style(
            'wc-monarch-ach',
            WC_MONARCH_ACH_PLUGIN_URL . 'assets/css/monarch-ach.css',
            array(),
            $css_version
        );

        wp_localize_script('wc-monarch-ach', 'monarch_ach_params', $this->get_monarch_ach_script_params());

        if (!self::$monarch_wc_checkout_dep_hooked) {
            self::$monarch_wc_checkout_dep_hooked = true;
            add_action('wp_enqueue_scripts', array(__CLASS__, 'patch_wc_monarch_ach_wc_checkout_dependency'), 20);
        }
    }

    /**
     * @var bool
     */
    private static $monarch_wc_checkout_dep_hooked = false;

    /**
     * Ensure wc-monarch-ach runs after wc-checkout on classic checkout (script order / update_checkout binding).
     */
    public static function patch_wc_monarch_ach_wc_checkout_dependency() {
        if (!wp_script_is('wc-monarch-ach', 'registered')) {
            return;
        }
        if (!self::monarch_should_add_wc_checkout_script_dependency()) {
            return;
        }
        $wp_scripts = wp_scripts();
        if (!isset($wp_scripts->registered['wc-monarch-ach'])) {
            return;
        }
        if (in_array('wc-checkout', $wp_scripts->registered['wc-monarch-ach']->deps, true)) {
            return;
        }
        $wp_scripts->registered['wc-monarch-ach']->deps[] = 'wc-checkout';
    }

    /**
     * True when the storefront checkout page uses the Cart/Checkout block (Woo dequeues wc-checkout).
     *
     * @return bool
     */
    private static function monarch_is_block_checkout_page() {
        if (!function_exists('has_block')) {
            return false;
        }
        $post = get_post();
        if (!$post) {
            return false;
        }
        return has_block('woocommerce/checkout', $post);
    }

    /**
     * @return bool
     */
    private static function monarch_should_add_wc_checkout_script_dependency() {
        if (!wp_script_is('wc-checkout', 'registered')) {
            return false;
        }
        if (!function_exists('is_checkout') || !is_checkout()) {
            return false;
        }
        return !self::monarch_is_block_checkout_page();
    }

    /**
     * Localized params for monarch-ach.js (classic + block modal).
     *
     * @return array
     */
    private function get_monarch_ach_script_params() {
        return array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('monarch_ach_nonce'),
            'test_mode' => $this->testmode ? 'yes' : 'no',
            'allow_manual_account_checkout' => $this->allow_manual_account_checkout ? 'yes' : 'no',
            'checkout_url' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '',
            'bank_disconnected_message' => __('Your bank account was disconnected. Please complete the checkout form again.', 'monarch-payments-for-woocommerce'),
            'customer_email' => (function_exists('WC') && WC() && WC()->customer) ? WC()->customer->get_billing_email() : '',
            'monarch_registered_email' => (function () {
                if (!function_exists('WC')) {
                    return '';
                }
                if (is_user_logged_in()) {
                    return (string) get_user_meta(get_current_user_id(), '_monarch_registered_email', true);
                }
                return WC()->session ? (string) WC()->session->get('monarch_registered_email') : '';
            })(),
        );
    }

    public function payment_scripts() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query flag used only to decide script enqueue.
        if (!is_checkout() && !isset($_GET['pay_for_order'])) {
            return;
        }

        if ('no' === $this->enabled) {
            return;
        }

        if (empty($this->api_key) || empty($this->app_id)) {
            return;
        }

        $this->register_monarch_checkout_scripts();

        wp_enqueue_script('wc-monarch-ach');
        wp_enqueue_style('wc-monarch-ach');
    }

    /**
     * If the customer just disconnected their bank (query param or session flag), purge Woo checkout state and
     * force blank checkout field values for a short window so there are no preset values.
     */
    public function maybe_force_checkout_reset_after_disconnect() {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        if (!function_exists('is_checkout') || !is_checkout() || (function_exists('is_order_received_page') && is_order_received_page())) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query flag triggers UI/session reset behavior.
        $has_query_flag = isset($_GET['monarch_bank_disconnected']) && (string) $_GET['monarch_bank_disconnected'] !== '';
        $until = (int) WC()->session->get('monarch_force_blank_checkout_until');
        $has_session_flag = $until > 0 && time() <= $until;

        if (!$has_query_flag && !$has_session_flag) {
            return;
        }

        // Always purge session-based checkout/customer caching so Store API + classic checkout start clean.
        $this->purge_woocommerce_checkout_state();

        // If a query flag brought us here (or the window already exists), keep blanking for ~15 minutes.
        if (!$has_session_flag) {
            WC()->session->set('monarch_force_blank_checkout_until', time() + 15 * 60);
        }

        // Ensure the banner renders on this request.
        WC()->session->set('monarch_show_bank_disconnected_banner', 1);
    }

    /**
     * Render a prominent customer-facing banner after bank disconnection.
     * This is intentionally separate from wc_add_notice so it can't be buried among other notices.
     */
    public function maybe_render_disconnect_notice_banner() {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        $show = (int) WC()->session->get('monarch_show_bank_disconnected_banner');
        if (!$show) {
            return;
        }

        $message = __('Your bank account was disconnected. Please complete the checkout form again.', 'monarch-payments-for-woocommerce');
        echo '<div class="monarch-bank-disconnected-banner" role="alert" aria-live="assertive">' . esc_html($message) . '</div>';

        // Keep blanking for the configured window, but don't keep re-rendering the banner.
        WC()->session->__unset('monarch_show_bank_disconnected_banner');
    }

    /**
     * Blank checkout field values after a bank disconnect so the customer must re-enter details.
     * Only affects initial GET renders (does not interfere with posted checkout submissions).
     *
     * @param mixed  $value
     * @param string $input
     * @return mixed
     */
    public function filter_blank_checkout_values_after_disconnect($value, $input) {
        if (!function_exists('WC') || !WC()->session) {
            return $value;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; actual submitted values validated elsewhere.
        if (!empty($_POST)) {
            // Never blank during submission/validation.
            return $value;
        }
        $until = (int) WC()->session->get('monarch_force_blank_checkout_until');
        if ($until <= 0 || time() > $until) {
            return $value;
        }

        if (strpos($input, 'billing_') === 0 || strpos($input, 'shipping_') === 0 || $input === 'order_comments') {
            return '';
        }
        return $value;
    }
    
    public function is_available() {
        // Check if gateway is enabled
        if ('yes' !== $this->enabled) {
            return false;
        }
        
        // For Store API, don't block on missing credentials
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return parent::is_available();
        }
        
        // Check if required API credentials are configured
        if (empty($this->api_key) || empty($this->app_id)) {
            return false;
        }
        
        // Check if merchant org ID is configured
        if (empty($this->merchant_org_id)) {
            return false;
        }
        
        // Check if partner name is configured
        if (empty($this->partner_name)) {
            return false;
        }
        
        return parent::is_available();
    }
    
    public function payment_fields() {
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }

        // Check if customer is already registered with Monarch
        // Support both logged-in users (user_meta) and guest checkout (WC session)
        $customer_id = get_current_user_id();
        $is_guest = ($customer_id == 0);

        if ($is_guest && WC()->session) {
            // Guest checkout - get data from WC session
            $monarch_org_id = WC()->session->get('monarch_org_id');
            $paytoken_id = WC()->session->get('monarch_paytoken_id');

            // Also check temp values (for newly created orgs during current session)
            if (empty($monarch_org_id)) {
                $monarch_org_id = WC()->session->get('monarch_temp_org_id');
            }
            if (empty($paytoken_id)) {
                $paytoken_id = WC()->session->get('monarch_temp_paytoken_id');
            }
        } else {
            // Logged-in user - get data from user meta
            $monarch_org_id = get_user_meta($customer_id, '_monarch_org_id', true);
            $paytoken_id = get_user_meta($customer_id, '_monarch_paytoken_id', true);
        }
        //print_r($monarch_org_id);die;

        if ($monarch_org_id && $paytoken_id) {
            // User has both org_id and valid paytoken - ready to pay
            $registered_email = '';
            if ($is_guest && WC()->session) {
                $registered_email = (string) WC()->session->get('monarch_registered_email');
            } elseif (!$is_guest && $customer_id) {
                $registered_email = (string) get_user_meta($customer_id, '_monarch_registered_email', true);
            }
            echo '<div class="monarch-bank-connected">';
            echo '<p><strong>Bank account connected</strong></p>';
            if (!empty($registered_email)) {
                echo '<p>Connected as: <strong>' . esc_html($registered_email) . '</strong></p>';
            }
            echo '<p><a href="#" id="monarch-disconnect-bank" class="monarch-disconnect-link">Use a different bank account</a></p>';
            echo '<input type="hidden" name="monarch_org_id" value="' . esc_attr($monarch_org_id) . '">';
            echo '<input type="hidden" name="monarch_paytoken_id" value="' . esc_attr($paytoken_id) . '">';
            echo '</div>';
            return;
        }

        if ($monarch_org_id && !$paytoken_id) {
            // Returning user: has org_id but paytoken expired after last transaction
            // They need to get a new paytoken by selecting their bank again
            ?>
            <div class="monarch-returning-user">
                <p><strong>Welcome back!</strong></p>
                <p>Please click "Continue with Bank" to authorize this payment.</p>
                <input type="hidden" id="monarch_org_id" name="monarch_org_id" value="<?php echo esc_attr($monarch_org_id); ?>">
                <input type="hidden" id="monarch_paytoken_id" name="monarch_paytoken_id" value="">
                <p class="form-row form-row-wide">
                    <button type="button" id="monarch-reconnect-bank" class="button alt">Continue with Bank</button>
                    <span id="monarch-reconnect-spinner" class="spinner" style="display:none; float:none; margin-left:10px;"></span>
                </p>
                <p><a href="#" id="monarch-use-different-bank" class="monarch-disconnect-link">Use a different bank account</a></p>
            </div>
            <?php
            return;
        }

        ?>
        <div id="monarch-ach-form">
            <div id="monarch-ach-errors" class="woocommerce-error" style="display:none;"></div>

            <!-- Phone Number Field with Edit Mode -->
            <div class="form-row form-row-wide monarch-editable-field" id="monarch-phone-wrapper">
                <label for="monarch_phone">Phone Number <span class="required">*</span></label>
                <div class="monarch-field-input">
                    <input id="monarch_phone" name="monarch_phone" type="tel" required>
                </div>
                <div class="monarch-field-display" style="display:none;">
                    <span class="monarch-field-value" id="monarch_phone_display"></span>
                    <button type="button" class="monarch-edit-btn" data-field="phone">Edit</button>
                </div>
            </div>

            <input type="hidden" id="monarch_dob" name="monarch_dob" value="<?php echo esc_attr(self::MONARCH_CHECKOUT_DOB_DEFAULT); ?>">

            <p class="form-row form-row-wide">
                <button type="button" id="monarch-connect-bank" class="button alt">Connect Bank Account</button>
                <span id="monarch-connect-spinner" class="spinner" style="display:none; float:none; margin-left:10px;"></span>
            </p>

            <p class="monarch-security-notice">
                Your bank details are securely transmitted and encrypted.
            </p>

            <input type="hidden" id="monarch_org_id" name="monarch_org_id" value="">
            <input type="hidden" id="monarch_paytoken_id" name="monarch_paytoken_id" value="">
            <input type="hidden" id="monarch_bank_verified" name="monarch_bank_verified" value="">
            <input type="hidden" id="monarch_entry_method" name="monarch_entry_method" value="auto">
            <input type="hidden" id="monarch_info_confirmed" name="monarch_info_confirmed" value="">
        </div>
        <?php
    }
    
    public function validate_fields() {
        $customer_id = get_current_user_id();
        $is_guest = ($customer_id == 0);

        // Prevent "billing email" from diverging from the Monarch purchaser/bank identity.
        // Monarch purchaser details shown in sandbox are derived from the purchaser org (orgId),
        // which is tied to the email used during bank connection.
        $checkout_email = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout; payment field validation runs after WC checkout nonce check.
        if (isset($_POST['billing_email'])) {
            $checkout_email = $this->post_email('billing_email');
        }
        if (empty($checkout_email) && function_exists('WC') && WC()->customer) {
            $checkout_email = (string) WC()->customer->get_billing_email();
        }

        $registered_email = '';
        if ($is_guest && function_exists('WC') && WC()->session) {
            $registered_email = (string) WC()->session->get('monarch_registered_email');
        } elseif (!$is_guest && $customer_id) {
            $registered_email = (string) get_user_meta($customer_id, '_monarch_registered_email', true);
        }

        if (!empty($checkout_email) && !empty($registered_email) && strtolower($checkout_email) !== strtolower($registered_email)) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: registered email, 2: billing email */
                    __('Your bank connection is linked to %1$s. To pay using %2$s, please disconnect your bank and reconnect using that billing email.', 'monarch-payments-for-woocommerce'),
                    '<strong>' . esc_html($registered_email) . '</strong>',
                    '<strong>' . esc_html($checkout_email) . '</strong>'
                ),
                'error'
            );
            return false;
        }

        if ($is_guest) {
            // Guest checkout - check WC session
            $monarch_org_id = WC()->session ? WC()->session->get('monarch_org_id') : '';
            $paytoken_id = WC()->session ? WC()->session->get('monarch_paytoken_id') : '';

            // Also check temp values (for newly created orgs)
            if (empty($monarch_org_id)) {
                $monarch_org_id = WC()->session ? WC()->session->get('monarch_temp_org_id') : '';
            }
        } else {
            // Logged-in user - check user meta
            $monarch_org_id = get_user_meta($customer_id, '_monarch_org_id', true);
            $paytoken_id = get_user_meta($customer_id, '_monarch_paytoken_id', true);

            // Also check temp values (for newly created orgs)
            if (empty($monarch_org_id)) {
                $monarch_org_id = get_user_meta($customer_id, '_monarch_temp_org_id', true);
            }
        }

        // Bank account must be connected through Monarch's verification flow
        if (!$monarch_org_id || !$paytoken_id) {
            wc_add_notice('Please connect your bank account before placing an order.', 'error');
            return false;
        }

        return true;
    }
    
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $customer_id = $order->get_user_id();
        $is_guest = ($customer_id == 0);

        try {
            if ($is_guest) {
                // For guest checkout, get data from session
                $org_id = WC()->session->get('monarch_org_id');
                $paytoken_id = WC()->session->get('monarch_paytoken_id');
                $org_api_key = WC()->session->get('monarch_org_api_key');
                $org_app_id = WC()->session->get('monarch_org_app_id');
                $monarch_user_id = WC()->session->get('monarch_user_id');
                
                // Store data in order meta for future reference
                if ($org_id) $order->update_meta_data('_monarch_org_id', $org_id);
                if ($paytoken_id) $order->update_meta_data('_monarch_paytoken_id', $paytoken_id);
                if ($monarch_user_id) $order->update_meta_data('_monarch_user_id', $monarch_user_id);
                if ($org_api_key) $order->update_meta_data('_monarch_org_api_key', $org_api_key);
                if ($org_app_id) $order->update_meta_data('_monarch_org_app_id', $org_app_id);
                $order->save();
            } else {
                // For logged-in users, get verified org_id and paytoken_id from user meta
                $org_id = get_user_meta($customer_id, '_monarch_org_id', true);
                $paytoken_id = get_user_meta($customer_id, '_monarch_paytoken_id', true);
                $org_api_key = get_user_meta($customer_id, '_monarch_org_api_key', true);
                $org_app_id = get_user_meta($customer_id, '_monarch_org_app_id', true);

                // Check if the org was created with the same merchant_org_id and environment
                // If settings changed, the old org won't work with new merchant credentials
                $stored_merchant_org_id = get_user_meta($customer_id, '_monarch_merchant_org_id', true);
                $stored_testmode = get_user_meta($customer_id, '_monarch_testmode', true);

                if ($org_id && $stored_merchant_org_id && $stored_merchant_org_id !== $this->merchant_org_id) {
                    // Merchant org changed - old org won't work
                    $logger = WC_Monarch_Logger::instance();
                    $logger->warning('Merchant org ID mismatch - clearing stored credentials', array(
                        'stored_merchant_org_id' => $stored_merchant_org_id,
                        'current_merchant_org_id' => $this->merchant_org_id,
                        'customer_id' => $customer_id
                    ));

                    // Clear all stored Monarch data
                    $this->clear_user_monarch_data($customer_id);
                    $org_id = null;
                    $paytoken_id = null;
                }

                // Check if environment (sandbox/prod) changed
                $current_testmode = $this->testmode ? 'yes' : 'no';
                if ($org_id && $stored_testmode && $stored_testmode !== $current_testmode) {
                    // Environment changed - orgs don't transfer between sandbox and production
                    $logger = WC_Monarch_Logger::instance();
                    $logger->warning('Environment mismatch - clearing stored credentials', array(
                        'stored_testmode' => $stored_testmode,
                        'current_testmode' => $current_testmode,
                        'customer_id' => $customer_id
                    ));

                    $this->clear_user_monarch_data($customer_id);
                    $org_id = null;
                    $paytoken_id = null;
                }
            }

            // Bank account must be connected through Monarch's verification flow
            if (!$org_id || !$paytoken_id) {
                throw new Exception('Please connect your bank account before placing an order.');
            }

            // Credential Strategy: Try purchaser credentials first, fall back to merchant credentials
            // The purchaser's API credentials were returned when the organization was created
            $use_purchaser_credentials = false;

            // Check for purchaser credentials (works for both guest and logged-in users)
            if (!empty($org_api_key) && !empty($org_app_id)) {
                // Use purchaser's own credentials for the transaction
                $api_key_for_sale = $org_api_key;
                $app_id_for_sale = $org_app_id;
                $use_purchaser_credentials = true;
                $order->add_order_note('Processing payment with purchaser credentials for org: ' . $org_id);
            } else {
                // Fall back to merchant credentials
                $api_key_for_sale = $this->api_key;
                $app_id_for_sale = $this->app_id;
                $order->add_order_note('Processing payment with merchant credentials for org: ' . $org_id);
            }

            $monarch_api = new Monarch_API(
                $api_key_for_sale,
                $app_id_for_sale,
                $this->merchant_org_id,
                $this->partner_name,
                $this->testmode
            );

            $order->add_order_note('Using bank account - orgId: ' . $org_id . ', payTokenId: ' . $paytoken_id . ', credentials: ' . ($use_purchaser_credentials ? 'purchaser' : 'merchant'));

            // Create Sale Transaction
            $transaction_result = $monarch_api->create_sale_transaction(array(
                'amount' => $order->get_total(),
                'org_id' => $org_id,
                'paytoken_id' => $paytoken_id,
                'comment' => 'Order #' . $order_id . ' - ' . get_bloginfo('name')
            ));

            if (!$transaction_result['success']) {
                $error_message = $transaction_result['error'];
                $status_code = $transaction_result['status_code'] ?? 0;

                $logger = WC_Monarch_Logger::instance();
                $logger->error('Transaction failed', array(
                    'order_id' => $order_id,
                    'error' => $error_message,
                    'status_code' => $status_code,
                    'org_id' => $org_id,
                    'paytoken_id' => $paytoken_id,
                    'merchant_org_id' => $this->merchant_org_id,
                    'testmode' => $this->testmode ? 'yes' : 'no',
                    'full_response' => $transaction_result['response'] ?? null
                ));

                // Log the error for debugging - don't auto-clear user data
                // Show the actual error to the user so they can understand what went wrong
                $logger->debug('Transaction error details', array(
                    'error_message' => $error_message,
                    'status_code' => $status_code,
                    'org_id' => $org_id,
                    'paytoken_id' => $paytoken_id
                ));

                // Simply throw the error - don't try to auto-detect and clear data
                // This prevents false positives and lets the user see the actual problem
                throw new Exception($error_message);
            }

            // Extract transaction ID - Monarch API may return it in various field names
            $data = $transaction_result['data'];
            $transaction_id = $data['id']
                ?? $data['_id']
                ?? $data['transactionId']
                ?? $data['transaction_id']
                ?? $data['txnId']
                ?? $data['txn_id']
                ?? $data['referenceId']
                ?? $data['reference_id']
                ?? '';

            // Log the full response for debugging if transaction ID not found
            $logger = WC_Monarch_Logger::instance();
            if (empty($transaction_id)) {
                $logger->warning('Transaction ID not found in response', array(
                    'order_id' => $order_id,
                    'response_keys' => array_keys($data),
                    'full_response' => $data
                ));
            } else {
                $logger->debug('Transaction created successfully', array(
                    'order_id' => $order_id,
                    'transaction_id' => $transaction_id
                ));
            }

            $order->add_order_note('Transaction created - ID: ' . ($transaction_id ?: 'N/A'));

            // Save transaction ID to order meta (visible in admin) - HPOS compatible
            // Always save even if empty so we can track and debug
            $order->set_transaction_id($transaction_id ?: 'pending');
            $order->update_meta_data('_monarch_transaction_id', $transaction_id ?: 'pending');
            $order->update_meta_data('_monarch_org_id', $org_id);
            $order->update_meta_data('_monarch_paytoken_id', $paytoken_id);
            $order->update_meta_data('_monarch_api_response', json_encode($data));
            $order->save();

            // Mark payment complete FIRST so WooCommerce sets _date_paid
            $order->payment_complete($transaction_id);
            $order->add_order_note('ACH payment processed. Transaction ID: ' . ($transaction_id ?: 'N/A'));

            // Get the exact date_paid that WooCommerce just set
            $date_paid = $order->get_date_paid();
            $payment_date_utc = $date_paid ? $date_paid->format('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s');

            // Save transaction data using the SAME timestamp as WooCommerce
            $this->save_transaction_data($order_id, $data, $org_id, $paytoken_id, $order->get_total(), $payment_date_utc);

            // IMPORTANT: Clear the paytoken after successful transaction
            // Per Monarch: "once the latest pay token is used for a transaction, it expires and cannot be reused"
            // The org_id remains valid - user can get a new paytoken by selecting their bank again
            if (!$is_guest && $customer_id) {
                delete_user_meta($customer_id, '_monarch_paytoken_id');
                $logger->debug('Cleared expired paytoken after successful transaction', array(
                    'customer_id' => $customer_id,
                    'order_id' => $order_id,
                    'used_paytoken' => $paytoken_id
                ));
            } elseif ($is_guest) {
                // For guest users, clear from session
                WC()->session->__unset('monarch_paytoken_id');
            }

            // Redirect behavior:
            // - Logged-in customers: send to the "order details" page in My Account (view order)
            // - Guests: keep WooCommerce default thank-you page (order-received)
            $redirect_url = $this->get_return_url($order);
            if (!$is_guest && $order && method_exists($order, 'get_view_order_url')) {
                $redirect_url = $order->get_view_order_url();
            }

            return array(
                'result' => 'success',
                'redirect' => $redirect_url,
            );

        } catch (Exception $e) {
            wc_add_notice('Payment failed: ' . $e->getMessage(), 'error');
            return array('result' => 'fail', 'redirect' => '');
        }
    }
    
    private function setup_customer_and_bank_account($order, $monarch_api) {
        $customer_data = $this->prepare_customer_data($order);
        
        // Create organization
        $org_result = $monarch_api->create_organization($customer_data);
        if (!$org_result['success']) {
            return array('success' => false, 'error' => $org_result['error']);
        }
        
        $user_id = $org_result['data']['_id'];
        $org_id = $org_result['data']['orgId'];
        
        // Create PayToken
        $bank_data = $this->prepare_bank_data();
        $paytoken_result = $monarch_api->create_paytoken($user_id, $bank_data);
        if (!$paytoken_result['success']) {
            return array('success' => false, 'error' => $paytoken_result['error']);
        }
        
        $paytoken_id = $paytoken_result['data']['_id'];
        
        // Assign PayToken
        $assign_result = $monarch_api->assign_paytoken($paytoken_id, $org_id);
        if (!$assign_result['success']) {
            return array('success' => false, 'error' => $assign_result['error']);
        }
        
        // Save to user meta
        $customer_id = $order->get_user_id();
        if ($customer_id) {
            update_user_meta($customer_id, '_monarch_org_id', $org_id);
            update_user_meta($customer_id, '_monarch_user_id', $user_id);
            update_user_meta($customer_id, '_monarch_paytoken_id', $paytoken_id);
            update_user_meta($customer_id, '_monarch_connected_date', current_time('mysql'));

            // Log customer creation
            $logger = WC_Monarch_Logger::instance();
            $logger->log_customer_event('customer_created', $customer_id, array(
                'org_id' => $org_id,
                'paytoken_id' => $paytoken_id
            ));
        }
        
        return array(
            'success' => true,
            'org_id' => $org_id,
            'paytoken_id' => $paytoken_id
        );
    }
    
    private function prepare_customer_data($order) {
        return array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'password' => wp_generate_password(),
            'phone' => $this->post_text('monarch_phone'),
            'company_name' => $this->post_text('monarch_company') ?: $order->get_billing_company(),
            'dob' => $this->get_post_monarch_dob_for_api(),
            'address_1' => $order->get_billing_address_1(),
            'address_2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'zip' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country()
        );
    }
    
    private function prepare_bank_data() {
        return array(
            'bank_name' => $this->post_text('monarch_bank_name'),
            'account_number' => $this->post_text('monarch_account_number'),
            'routing_number' => $this->post_text('monarch_routing_number'),
            'account_type' => $this->post_text('monarch_account_type')
        );
    }
    
    private function save_transaction_data($order_id, $transaction_data, $org_id, $paytoken_id, $order_total = 0, $payment_date_utc = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'monarch_ach_transactions';

        // Extract transaction ID from various possible field names
        $transaction_id = $transaction_data['id']
            ?? $transaction_data['_id']
            ?? $transaction_data['transactionId']
            ?? $transaction_data['transaction_id']
            ?? $transaction_data['txnId']
            ?? $transaction_data['txn_id']
            ?? $transaction_data['referenceId']
            ?? $transaction_data['reference_id']
            ?? 'txn_' . uniqid();

        // Use order total if provided, otherwise try to get from API response
        $amount = floatval($order_total) > 0 ? floatval($order_total) : floatval($transaction_data['amount'] ?? 0);

        // Use the provided payment date (from WooCommerce) or fallback to current UTC time
        $created_at = $payment_date_utc ?: gmdate('Y-m-d H:i:s');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for plugin's custom transaction table writes.
        $wpdb->insert(
            $table_name,
            array(
                'order_id' => $order_id,
                'transaction_id' => $transaction_id,
                'monarch_org_id' => $org_id,
                'paytoken_id' => $paytoken_id,
                'amount' => $amount,
                'currency' => 'USD',
                'status' => $transaction_data['status'] ?? 'pending',
                'api_response' => json_encode($transaction_data),
                'created_at' => $created_at
            ),
            array('%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s')
        );
    }
    
    public function thankyou_page() {
        echo '<p>Thank you for your payment. Your ACH transaction is being processed and you will receive confirmation once complete.</p>';
    }

    /**
     * AJAX handler for creating organization and getting bank linking URL
     */
    public function ajax_create_organization() {
        check_ajax_referer('monarch_ach_nonce', 'nonce');

        // Support both logged in users and guest checkout
        $is_guest = !is_user_logged_in();
        
        if ($is_guest) {
            // For guest checkout, require session ID for data storage
            if (!WC()->session || !WC()->session->get_session_cookie()) {
                wp_send_json_error('Session required for guest checkout');
                return;
            }
        }

        // Log credentials being used for debugging
        $logger = WC_Monarch_Logger::instance();
        $logger->debug('ajax_create_organization called', array(
            'api_key_last_4' => substr($this->api_key, -4),
            'app_id_last_4' => substr($this->app_id, -4),
            'merchant_org_id' => $this->merchant_org_id,
            'parent_org_id' => $this->merchant_org_id,
            'testmode' => $this->testmode ? 'yes' : 'no',
            'base_url' => $this->testmode ? 'https://devapi.monarch.is/v1' : 'https://api.monarch.is/v1'
        ));

        try {
            $monarch_api = new Monarch_API(
                $this->api_key,
                $this->app_id,
                $this->merchant_org_id,
                $this->partner_name,
                $this->testmode
            );

            
            // Get user data - support both logged in users and guest checkout
            // IMPORTANT: Always use billing_email from form (real-time input), NOT cached WordPress user email
            $form_email = $this->post_email('billing_email');

            if ($is_guest) {
                // For guest checkout, use billing email from form
                $user_email = $form_email;
                $user_id = 'guest_' . substr(md5($user_email . time()), 0, 8);
            } else {
                $current_user = wp_get_current_user();
                // Use form email if provided, otherwise fall back to WordPress user email
                $user_email = !empty($form_email) ? $form_email : $current_user->user_email;
                $user_id = $current_user->ID;
            }

            // IMPORTANT: Always prioritize form data (real-time input) over cached WordPress user data
            // Get form values first
            $form_first_name = $this->post_text('billing_first_name');
            $form_last_name = $this->post_text('billing_last_name');

            // Use form data if provided, otherwise fall back to WordPress user data
            if ($is_guest) {
                $first_name = $form_first_name;
                $last_name = $form_last_name;
            } else {
                $first_name = !empty($form_first_name) ? $form_first_name : $current_user->user_firstname;
                $last_name = !empty($form_last_name) ? $form_last_name : $current_user->user_lastname;
            }

            $logger->debug('Customer data being used for organization', array(
                'form_email' => $form_email,
                'form_first_name' => $form_first_name,
                'form_last_name' => $form_last_name,
                'wp_user_email' => $is_guest ? 'N/A (guest)' : $current_user->user_email,
                'wp_first_name' => $is_guest ? 'N/A (guest)' : $current_user->user_firstname,
                'wp_last_name' => $is_guest ? 'N/A (guest)' : $current_user->user_lastname,
                'final_email' => $user_email,
                'final_first_name' => $first_name,
                'final_last_name' => $last_name,
                'is_guest' => $is_guest
            ));

            // Prepare customer data - ALL fields use form data (real-time input)
            $phone = preg_replace('/[^0-9]/', '', $this->post_text('monarch_phone'));
            $phone = substr($phone, -10); // Last 10 digits

            $dob = $this->get_post_monarch_dob_for_api();

            $customer_data = array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $user_email,
                'password' => wp_generate_password(16, true, true),
                'phone' => $phone,
                'company_name' => $this->post_text('monarch_company'),
                'dob' => $dob,
                'address_1' => $this->post_text('billing_address_1'),
                'address_2' => $this->post_text('billing_address_2'),
                'city' => $this->post_text('billing_city'),
                'state' => $this->post_text('billing_state'),
                'zip' => $this->post_text('billing_postcode'),
                'country' => $this->post_text('billing_country')
            );

            // STEP 1: Check if user already exists via /merchants/verify/{email}
            // Rule: 404 = create new purchaser, 200 with orgId = purchaser already exists
            $logger->debug('STEP 1: Checking if user exists via /merchants/verify', array('email' => $user_email));

            $verify_result = $monarch_api->get_user_by_email($user_email);
            $verify_status_code = $verify_result['status_code'] ?? 0;
            $user_exists = $verify_result['user_exists'] ?? false;
            $existing_org_id = $verify_result['data']['orgId'] ?? $verify_result['org_id'] ?? null;
            $parent_org_id = $verify_result['data']['parentOrgId'] ?? $verify_result['parent_org_id'] ?? null;
           
            //print_r($verify_result);die;


            $logger->debug('Verify email result', array(
                'email' => $user_email,
                'status_code' => $verify_status_code,
                'success' => $verify_result['success'],
                'user_exists' => $user_exists,
                'has_org_id' => !empty($existing_org_id),
                'org_id' => $existing_org_id,
                'parent_org_id' => $parent_org_id,
                'has_data' => !empty($verify_result['data'])
            ));

            // User does NOT exist in Monarch → Create new purchaser
            // Conditions: 404 status code OR user_exists=false OR no orgId in response
            $should_create_new = ($verify_status_code == 404) ||
                                 (!$user_exists) ||
                                 (empty($existing_org_id));

            if ($should_create_new) {
                $logger->debug('User not found - Creating new purchaser organization', array(
                    'email' => $user_email,
                    'reason' => $verify_status_code == 404 ? '404 status' : (!$user_exists ? 'user_exists=false' : 'no orgId')
                ));

                $org_result = $monarch_api->create_organization($customer_data);
               // print_r($org_result);die;
                if (!$org_result['success']) {
                    // Organization creation failed.
                    // If the user actually exists (email already in use), treat as existing user.
                    // Otherwise, return the Monarch validation/error message to the customer.
                    $logger->info('Organization creation failed - treating as existing user', array(
                        'email' => $user_email,
                        'error' => $org_result['error'] ?? 'unknown'
                    ));

                    // Try to get existing user info via verify
                    $existing_verify = $monarch_api->get_user_by_email($user_email);
                    $existing_data = $existing_verify['data'] ?? array();
                    // IMPORTANT: Use purchaser orgId for payment/bank operations.
                    // parentOrgId (when present) is the parent/merchant org and should NOT replace orgId here.
                    $org_id = $existing_data['orgId'] ?? null;
                    $parent_org_id = $existing_data['parentOrgId'] ?? $parent_org_id;
                    $user_id = $existing_data['userId'] ?? ($existing_data['_id'] ?? null);
                    $bank_linking_url = $existing_data['partner_embedded_url'] ?? '';

                    // If verify doesn't return an orgId, this is NOT an existing user case.
                    // Surface the real error message (ex: "Last name must be between 3 and 20 character.")
                    if (empty($org_id)) {
                        $error_message = $org_result['error'] ?? 'Organization creation failed. Please check your details and try again.';
                        if (is_array($error_message)) {
                            $error_message = $error_message['message'] ?? wp_json_encode($error_message);
                        }
                        $error_message = is_string($error_message) ? trim($error_message) : 'Organization creation failed. Please check your details and try again.';

                        $logger->info('Organization creation failed - returning error to customer', array(
                            'email' => $user_email,
                            'error' => $error_message,
                        ));

                        wp_send_json_error($error_message);
                        return;
                    }

                    // Log what we got
                    $logger->debug('Existing user verify result', array(
                        'org_id' => $org_id,
                        'user_id' => $user_id,
                        'has_bank_linking_url' => !empty($bank_linking_url)
                    ));

                    // Save data (org_id may be null initially - will be retrieved after bank linking)
                    if ($is_guest) {
                        if ($org_id) WC()->session->set('monarch_temp_org_id', $org_id);
                        if ($user_id) WC()->session->set('monarch_temp_user_id', $user_id);
                        WC()->session->set('monarch_registered_email', $user_email);
                        if (!empty($bank_linking_url)) {
                            WC()->session->set('monarch_bank_linking_url', $bank_linking_url);
                        }
                    } else {
                        $customer_id = get_current_user_id();
                        if ($org_id) update_user_meta($customer_id, '_monarch_temp_org_id', $org_id);
                        if ($user_id) update_user_meta($customer_id, '_monarch_temp_user_id', $user_id);
                        update_user_meta($customer_id, '_monarch_registered_email', $user_email);
                        if (!empty($bank_linking_url)) {
                            update_user_meta($customer_id, '_monarch_bank_linking_url', $bank_linking_url);
                        }
                    }

                    // Always return success - bank linking process will retrieve org_id if needed
                    wp_send_json_success(array(
                        'org_id' => $org_id,
                        'user_id' => $user_id,
                        'bank_linking_url' => $bank_linking_url,
                        'existing_user' => true
                    ));
                    return;
                }

                // NEW USER - organization created successfully
                $user_id = $org_result['data']['_id'];
                // IMPORTANT: Use purchaser orgId for payment/bank operations.
                $org_id = $org_result['data']['orgId'] ?? null;
                $parent_org_id = $org_result['data']['parentOrgId'] ?? $parent_org_id;
                $bank_linking_url = $org_result['data']['partner_embedded_url'] ?? '';

                // Check if response includes purchaser-specific API credentials
                $org_api_key = null;
                $org_app_id = null;
                if (isset($org_result['data']['api'])) {
                    $credentials_key = $this->testmode ? 'sandbox' : 'prod';
                    $org_credentials = $org_result['data']['api'][$credentials_key] ?? null;
                    if ($org_credentials) {
                        $org_api_key = $org_credentials['api_key'] ?? null;
                        $org_app_id = $org_credentials['app_id'] ?? null;
                    }
                }

                $logger->debug('New organization created', array(
                    'org_id' => $org_id,
                    'user_id' => $user_id,
                    'has_bank_linking_url' => !empty($bank_linking_url),
                    'has_purchaser_credentials' => !empty($org_api_key)
                ));

                // Save org data including purchaser credentials for getlatestpaytoken
                if ($is_guest) {
                    WC()->session->set('monarch_temp_org_id', $org_id);
                    WC()->session->set('monarch_temp_user_id', $user_id);
                WC()->session->set('monarch_registered_email', $user_email);
                    if (!empty($bank_linking_url)) {
                        WC()->session->set('monarch_bank_linking_url', $bank_linking_url);
                    }
                    if ($org_api_key) {
                        WC()->session->set('monarch_temp_org_api_key', $org_api_key);
                        WC()->session->set('monarch_temp_org_app_id', $org_app_id);
                    }
                } else {
                    $customer_id = get_current_user_id();
                    update_user_meta($customer_id, '_monarch_temp_org_id', $org_id);
                    update_user_meta($customer_id, '_monarch_temp_user_id', $user_id);
                update_user_meta($customer_id, '_monarch_registered_email', $user_email);
                    if (!empty($bank_linking_url)) {
                        update_user_meta($customer_id, '_monarch_bank_linking_url', $bank_linking_url);
                    }
                    if ($org_api_key) {
                        update_user_meta($customer_id, '_monarch_temp_org_api_key', $org_api_key);
                        update_user_meta($customer_id, '_monarch_temp_org_app_id', $org_app_id);
                    }
                }

                wp_send_json_success(array(
                    'org_id' => $org_id,
                    'user_id' => $user_id,
                    'bank_linking_url' => $bank_linking_url,
                    'existing_user' => false
                ));
                return;
            }

            // 200 = User EXISTS in Monarch → Use existing purchaser
            $logger->debug('User exists (200) - Using existing purchaser', array('email' => $user_email));

            $verify_data = $verify_result['data'] ?? array();
            // IMPORTANT: Use purchaser orgId for payment/bank operations.
            $org_id = $verify_data['orgId'] ?? null;
            $parent_org_id = $verify_data['parentOrgId'] ?? $parent_org_id;

            $user_id = $verify_data['userId'] ?? null;
            $bank_linking_url = $verify_data['partner_embedded_url'] ?? '';

            $logger->debug('Existing user found via /merchants/verify', array(
                'org_id' => $org_id,
                'parent_org_id' => $parent_org_id,
                'user_id' => $user_id,
                'has_bank_linking_url' => !empty($bank_linking_url)
            ));

            if (!$org_id) {
                wp_send_json_error('Unable to retrieve your organization. Please contact support.');
                return;
            }

            // Best-effort: attempt to sync purchaser profile (name/metadata) to current form input.
            // This is non-fatal; if Monarch doesn't support updates, checkout can still proceed.
            try {
                if (method_exists($monarch_api, 'update_organization_profile')) {
                    $profile_update = $monarch_api->update_organization_profile($org_id, $customer_data);
                    $logger->debug('Existing user profile update result', array(
                        'org_id' => $org_id,
                        'success' => $profile_update['success'] ?? false
                    ));
                } else {
                    $logger->debug('Existing user profile update skipped (method missing)', array(
                        'org_id' => $org_id
                    ));
                }
            } catch (Exception $e) {
                $logger->debug('Existing user profile update exception (non-fatal)', array(
                    'org_id' => $org_id,
                    'error' => $e->getMessage()
                ));
            }

            // Check if this org belongs to our merchant by calling /getlatestpaytoken
            $existing_paytoken_id = null;
            $customer_id = !$is_guest ? get_current_user_id() : 0;
            $credentials_match = true;

            //print_r($this->api_key);
            //print_r($this->app_id);die;
            $api_url = $this->testmode ? 'https://devapi.monarch.is/v1' : 'https://api.monarch.is/v1';
            $paytoken_response = wp_remote_get($api_url . '/getlatestpaytoken/' . $org_id, array(
                'headers' => array(
                    'accept' => 'application/json',
                    'X-API-KEY' => $this->api_key,
                    'X-APP-ID' => $this->app_id
                ),
                'timeout' => 30
            ));
            

            if (!is_wp_error($paytoken_response)) {
                
                $paytoken_status = wp_remote_retrieve_response_code($paytoken_response);
                $paytoken_raw_body = wp_remote_retrieve_body($paytoken_response);
                $paytoken_body = json_decode($paytoken_raw_body, true);

                //print_r($paytoken_body);die;

                if (!is_array($paytoken_body)) {
                    $paytoken_body = array();
                }

                $logger->debug('getlatestpaytoken API response for existing user', array(
                    'org_id' => $org_id,
                    'parent_org_id' => $parent_org_id,
                    'status_code' => $paytoken_status,
                    'raw_body' => $paytoken_raw_body,
                    'decoded_body' => $paytoken_body
                ));

                // Check for credentials mismatch error
                $error_message = '';
                if (isset($paytoken_body['error'])) {
                    if (is_array($paytoken_body['error']) && isset($paytoken_body['error']['message'])) {
                        $error_message = $paytoken_body['error']['message'];
                    } elseif (is_string($paytoken_body['error'])) {
                        $error_message = $paytoken_body['error'];
                    }
                }
                

                // Treat as "different merchant" only when it's clearly an auth failure.
                if (($paytoken_status == 401 || $paytoken_status == 403) &&
                    strpos(strtolower($error_message), 'invalid request headers') !== false) {
                    // This org belongs to a DIFFERENT merchant - cannot use this email
                    $credentials_match = false;

                    $logger->debug('Email belongs to different merchant - user must use different email', array(
                        'org_id' => $org_id,
                        'email' => $user_email
                    ));

                    // Clear any stored data
                    if ($customer_id) {
                        delete_user_meta($customer_id, '_monarch_org_id');
                        delete_user_meta($customer_id, '_monarch_temp_org_id');
                        delete_user_meta($customer_id, '_monarch_paytoken_id');
                        delete_user_meta($customer_id, '_monarch_user_id');
                        delete_user_meta($customer_id, '_monarch_temp_user_id');
                    }

                    wp_send_json_error('This email is already registered with another merchant. Please use a different email address or contact support.');
                    return;
                }

                // Credentials match - extract paytoken if exists
                if ($paytoken_status >= 200 && $paytoken_status < 300 && !empty($paytoken_body)) {
                    $existing_paytoken_id = $paytoken_body['_id']
                        ?? $paytoken_body['payToken']
                        ?? $paytoken_body['payTokenId']
                        ?? $paytoken_body['id']
                        ?? $paytoken_body['paytoken_id']
                        ?? null;

                    if (!$existing_paytoken_id && isset($paytoken_body['dda'])) {
                        $existing_paytoken_id = $paytoken_body['_id'] ?? null;
                    }
                }

                $logger->debug('Paytoken extraction result', array(
                    'org_id' => $org_id,
                    'has_paytoken' => !empty($existing_paytoken_id),
                    'paytoken_id' => $existing_paytoken_id
                ));
            } else {
                $logger->error('getlatestpaytoken API call failed', array(
                    'org_id' => $org_id,
                    'error' => $paytoken_response->get_error_message()
                ));
            }

            // If credentials didn't match, we already handled it above and returned
            if (!$credentials_match) {
                return;
            }

            // Save org data
            if ($is_guest) {
                WC()->session->set('monarch_temp_org_id', $org_id);
                WC()->session->set('monarch_temp_user_id', $user_id);
                WC()->session->set('monarch_registered_email', $user_email);
                if ($existing_paytoken_id) {
                    WC()->session->set('monarch_paytoken_id', $existing_paytoken_id);
                }
            } else {
                $customer_id = get_current_user_id();
                update_user_meta($customer_id, '_monarch_temp_org_id', $org_id);
                update_user_meta($customer_id, '_monarch_temp_user_id', $user_id);
                update_user_meta($customer_id, '_monarch_registered_email', $user_email);
                if ($existing_paytoken_id) {
                    update_user_meta($customer_id, '_monarch_paytoken_id', $existing_paytoken_id);
                }
            }

            // If user already has a paytoken, skip bank linking
            if ($existing_paytoken_id) {
                $logger->debug('User already has bank connected - skipping bank linking', array(
                    'org_id' => $org_id,
                    'paytoken_id' => $existing_paytoken_id
                ));

                wp_send_json_success(array(
                    'org_id' => $org_id,
                    'user_id' => $user_id,
                    'paytoken_id' => $existing_paytoken_id,
                    'existing_user' => true,
                    'has_bank' => true
                ));
                return;
            }

            // User exists but no bank connected - return bank linking URL
            wp_send_json_success(array(
                'org_id' => $org_id,
                'user_id' => $user_id,
                'bank_linking_url' => $bank_linking_url,
                'existing_user' => true,
                'has_bank' => false
            ));

        } catch (Exception $e) {
            wp_send_json_error('Organization creation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for bank connection completion
     *
     * For EMBEDDED bank linking flow:
     * - PayToken is automatically created and assigned by Monarch when user completes bank linking
     * - We only need to save the paytoken_id retrieved from getLatestPayToken
     * - NO need to call assign_paytoken() - Monarch handles this automatically
     */
    public function ajax_bank_connection_complete() {
        check_ajax_referer('monarch_ach_nonce', 'nonce');

        // Support both logged in users and guest checkout
        $is_guest = !is_user_logged_in();
        $customer_id = $is_guest ? 0 : get_current_user_id();

        if ($is_guest) {
            // For guest checkout, get data from session (temp first, then permanent)
            $org_id = WC()->session->get('monarch_temp_org_id');
            if (empty($org_id)) {
                $org_id = WC()->session->get('monarch_org_id');
            }
            $user_id = WC()->session->get('monarch_temp_user_id');
            if (empty($user_id)) {
                $user_id = WC()->session->get('monarch_user_id');
            }
        } else {
            // For logged-in users, check temp first, then permanent storage
            $org_id = get_user_meta($customer_id, '_monarch_temp_org_id', true);
            if (empty($org_id)) {
                $org_id = get_user_meta($customer_id, '_monarch_org_id', true);
            }
            $user_id = get_user_meta($customer_id, '_monarch_temp_user_id', true);
            if (empty($user_id)) {
                $user_id = get_user_meta($customer_id, '_monarch_user_id', true);
            }
        }

        $paytoken_id = $this->post_text('paytoken_id');

        // Check if this is a returning user who already has bank connected
        // In this case, just save the paytoken and return success
        if (!$org_id || !$user_id) {
            // If we have paytoken but no org/user data, this might be a returning user
            // Try to get org_id from the stored paytoken's org
            if ($paytoken_id && $customer_id) {
                $stored_org_id = get_user_meta($customer_id, '_monarch_org_id', true);
                $stored_user_id = get_user_meta($customer_id, '_monarch_user_id', true);
                if ($stored_org_id && $stored_user_id) {
                    $org_id = $stored_org_id;
                    $user_id = $stored_user_id;
                }
            }
        }

        if (!$org_id || !$user_id || !$paytoken_id) {
            wp_send_json_error('Missing organization or paytoken data');
        }

        try {
            $logger = WC_Monarch_Logger::instance();

            // For embedded bank linking, PayToken is already created and assigned by Monarch
            // We just need to save the data to user meta
            $logger->debug('Bank connection complete - saving data', array(
                'org_id' => $org_id,
                'user_id' => $user_id,
                'paytoken_id' => $paytoken_id,
                'flow' => 'embedded_bank_linking'
            ));

            // Save permanent data based on user type
            if ($is_guest) {
                // For guest users, store in session (will be moved to order meta during checkout)
                WC()->session->set('monarch_org_id', $org_id);
                WC()->session->set('monarch_user_id', $user_id);
                WC()->session->set('monarch_paytoken_id', $paytoken_id);
                WC()->session->set('monarch_connected_date', current_time('mysql'));

                // Copy temp API credentials to permanent session keys
                $temp_api_key = WC()->session->get('monarch_temp_org_api_key');
                $temp_app_id = WC()->session->get('monarch_temp_org_app_id');
                if ($temp_api_key && $temp_app_id) {
                    WC()->session->set('monarch_org_api_key', $temp_api_key);
                    WC()->session->set('monarch_org_app_id', $temp_app_id);
                }

                // Clean up temporary session data
                WC()->session->__unset('monarch_temp_org_id');
                WC()->session->__unset('monarch_temp_user_id');
                WC()->session->__unset('monarch_temp_org_api_key');
                WC()->session->__unset('monarch_temp_org_app_id');
            } else {
                // Save permanent user data for logged-in users
                update_user_meta($customer_id, '_monarch_org_id', $org_id);
                update_user_meta($customer_id, '_monarch_user_id', $user_id);
                update_user_meta($customer_id, '_monarch_paytoken_id', $paytoken_id);
                update_user_meta($customer_id, '_monarch_connected_date', current_time('mysql'));

                // IMPORTANT: Store the merchant_org_id and testmode used when creating this connection
                // This allows us to detect if settings changed and the old org won't work
                update_user_meta($customer_id, '_monarch_merchant_org_id', $this->merchant_org_id);
                update_user_meta($customer_id, '_monarch_testmode', $this->testmode ? 'yes' : 'no');

                // Copy temp API credentials to permanent
                $temp_api_key = get_user_meta($customer_id, '_monarch_temp_org_api_key', true);
                $temp_app_id = get_user_meta($customer_id, '_monarch_temp_org_app_id', true);
                if ($temp_api_key && $temp_app_id) {
                    update_user_meta($customer_id, '_monarch_org_api_key', $temp_api_key);
                    update_user_meta($customer_id, '_monarch_org_app_id', $temp_app_id);
                }

                // Clean up temporary data
                delete_user_meta($customer_id, '_monarch_temp_org_id');
                delete_user_meta($customer_id, '_monarch_temp_user_id');
                delete_user_meta($customer_id, '_monarch_temp_org_api_key');
                delete_user_meta($customer_id, '_monarch_temp_org_app_id');
            }

            // Log bank connection
            $logger->log_customer_event('bank_connected', $customer_id, array(
                'org_id' => $org_id,
                'paytoken_id' => $paytoken_id
            ));

            // If we previously forced a blank checkout due to disconnect, clear that now that the bank is reconnected.
            if (function_exists('WC') && WC()->session) {
                WC()->session->__unset('monarch_force_blank_checkout_until');
                WC()->session->__unset('monarch_show_bank_disconnected_banner');
            }

            wp_send_json_success(array(
                'org_id' => $org_id,
                'paytoken_id' => $paytoken_id
            ));

        } catch (Exception $e) {
            wp_send_json_error('Bank connection completion failed: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler for checking bank connection status
     * Uses merchant credentials since child organizations are under the merchant
     */
    public function ajax_check_bank_status() {
        check_ajax_referer('monarch_ach_nonce', 'nonce');

        $org_id = $this->post_text('org_id');

        if (!$org_id) {
            wp_send_json_error('Organization ID is required');
        }

        try {
            // Query Monarch API to get latest paytoken for the organization
            // Use merchant credentials - works for child organizations
            $api_url = $this->testmode
                ? 'https://devapi.monarch.is/v1'
                : 'https://api.monarch.is/v1';

            $response = wp_remote_get($api_url . '/getlatestpaytoken/' . $org_id, array(
                'headers' => array(
                    'accept' => 'application/json',
                    'X-API-KEY' => $this->api_key,
                    'X-APP-ID' => $this->app_id
                ),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                wp_send_json_error('Failed to check bank status: ' . $response->get_error_message());
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($status_code >= 200 && $status_code < 300) {
                // The /getlatestpaytoken/ endpoint returns the latest paytoken directly
                $paytoken_id = $body['_id'] ?? $body['payToken'] ?? $body['id'] ?? null;

                if (!empty($paytoken_id)) {
                    wp_send_json_success(array(
                        'connected' => true,
                        'paytoken_id' => $paytoken_id,
                        'org_id' => $org_id
                    ));
                } else {
                    wp_send_json_success(array(
                        'connected' => false,
                        'message' => 'No bank account connected yet'
                    ));
                }
            } else {
                wp_send_json_error('Failed to retrieve organization status');
            }

        } catch (Exception $e) {
            wp_send_json_error('Error checking bank status: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler for getting latest paytoken after embedded bank linking
     * This is the correct flow per Monarch documentation:
     * After user links bank in iframe, call /v1/getlatestpaytoken/[organizationID]
     *
     * IMPORTANT: Must use the PURCHASER's API credentials (returned when organization was created)
     * NOT the merchant's credentials. The orgId must be associated with the security headers.
     */
    public function ajax_get_latest_paytoken() {
        $logger = WC_Monarch_Logger::instance();

        // Verify nonce
        if (!check_ajax_referer('monarch_ach_nonce', 'nonce', false)) {
            $logger->error('Nonce verification failed');
            wp_send_json_error('Security check failed. Please refresh the page and try again.');
            return;
        }

        // Check if user is logged in (allow guest checkout)
        if (!is_user_logged_in() && !isset($_POST['org_id'])) {
            wp_send_json_error('User must be logged in or provide organization ID');
            return;
        }

        $org_id = $this->post_text('org_id');

        if (empty($org_id)) {
            wp_send_json_error('Organization ID is required');
            return;
        }

        try {
            $customer_id = get_current_user_id();

            // Log the call
            $logger->debug('ajax_get_latest_paytoken called', array(
                'org_id' => $org_id,
                'customer_id' => $customer_id,
                'merchant_api_key_last_4' => substr($this->api_key, -4),
                'merchant_app_id' => $this->app_id,
                'testmode' => $this->testmode ? 'yes' : 'no'
            ));

            // FIRST: Check if user already has a paytoken stored in WordPress
            if ($customer_id) {
                $stored_paytoken = get_user_meta($customer_id, '_monarch_paytoken_id', true);
                $stored_org_id = get_user_meta($customer_id, '_monarch_org_id', true);

                // Also check temp org id
                if (empty($stored_org_id)) {
                    $stored_org_id = get_user_meta($customer_id, '_monarch_temp_org_id', true);
                }

                if (!empty($stored_paytoken) && $stored_org_id === $org_id) {
                    $logger->debug('Found stored paytoken in user meta - returning immediately', array(
                        'org_id' => $org_id,
                        'stored_paytoken' => $stored_paytoken
                    ));

                    wp_send_json_success(array(
                        'connected' => true,
                        'paytoken_id' => $stored_paytoken,
                        'org_id' => $org_id,
                        'message' => 'Bank account already connected'
                    ));
                    return;
                }
            }

            // SECOND: Call API to get paytoken
            // Check if we have purchaser-specific credentials (stored when org was created)
            // These are required for getLatestPayToken to work correctly
            $purchaser_api_key = null;
            $purchaser_app_id = null;
            $is_guest = !is_user_logged_in();

            if ($is_guest && WC()->session) {
                // Guest checkout - get credentials from WC session
                $purchaser_api_key = WC()->session->get('monarch_temp_org_api_key');
                $purchaser_app_id = WC()->session->get('monarch_temp_org_app_id');

                $logger->debug('Guest checkout - checking session for credentials', array(
                    'has_api_key' => !empty($purchaser_api_key),
                    'has_app_id' => !empty($purchaser_app_id),
                    'api_key_last_4' => $purchaser_api_key ? substr($purchaser_api_key, -4) : 'none',
                    'app_id' => $purchaser_app_id ?: 'none'
                ));
            } elseif ($customer_id) {
                // Logged-in user - get credentials from user meta
                // First check temp credentials (for newly created orgs)
                $purchaser_api_key = get_user_meta($customer_id, '_monarch_temp_org_api_key', true);
                $purchaser_app_id = get_user_meta($customer_id, '_monarch_temp_org_app_id', true);

                // If no temp credentials, check permanent credentials
                if (empty($purchaser_api_key)) {
                    $purchaser_api_key = get_user_meta($customer_id, '_monarch_org_api_key', true);
                    $purchaser_app_id = get_user_meta($customer_id, '_monarch_org_app_id', true);
                }
            }

            // Use purchaser credentials if available, otherwise fall back to merchant credentials
            $api_key_to_use = !empty($purchaser_api_key) ? $purchaser_api_key : $this->api_key;
            $app_id_to_use = !empty($purchaser_app_id) ? $purchaser_app_id : $this->app_id;

            $logger->debug('Using credentials for getLatestPayToken', array(
                'org_id' => $org_id,
                'is_guest' => $is_guest,
                'using_purchaser_credentials' => !empty($purchaser_api_key),
                'api_key_last_4' => substr($api_key_to_use, -4),
                'app_id' => $app_id_to_use
            ));

            $monarch_api = new Monarch_API(
                $api_key_to_use,
                $app_id_to_use,
                $this->merchant_org_id,
                $this->partner_name,
                $this->testmode
            );

            // Call getLatestPayToken API endpoint
            $result = $monarch_api->get_latest_paytoken($org_id);

            if ($result['success']) {
                $data = $result['data'];

                // Extract paytoken ID from response
                // The API may return it in different formats, so check multiple possible fields
                $paytoken_id = $data['_id'] ?? $data['payTokenId'] ?? $data['paytoken_id'] ?? $data['payToken'] ?? null;

                if ($paytoken_id) {
                    // Store the paytoken for the current user
                    $customer_id = get_current_user_id();
                    update_user_meta($customer_id, '_monarch_paytoken_id', $paytoken_id);

                    // Also store org_id permanently now that bank is linked
                    $temp_org_id = get_user_meta($customer_id, '_monarch_temp_org_id', true);
                    if ($temp_org_id) {
                        update_user_meta($customer_id, '_monarch_org_id', $temp_org_id);
                    }

                    // Store purchaser credentials permanently (move from temp to permanent)
                    $temp_api_key = get_user_meta($customer_id, '_monarch_temp_org_api_key', true);
                    $temp_app_id = get_user_meta($customer_id, '_monarch_temp_org_app_id', true);
                    if ($temp_api_key) {
                        update_user_meta($customer_id, '_monarch_org_api_key', $temp_api_key);
                        update_user_meta($customer_id, '_monarch_org_app_id', $temp_app_id);
                        // Clean up temp credentials
                        delete_user_meta($customer_id, '_monarch_temp_org_api_key');
                        delete_user_meta($customer_id, '_monarch_temp_org_app_id');
                    }

                    // Log success
                    $logger->log_customer_event('paytoken_retrieved', $customer_id, array(
                        'org_id' => $org_id,
                        'paytoken_id' => $paytoken_id,
                        'has_purchaser_credentials' => !empty($temp_api_key)
                    ));

                    wp_send_json_success(array(
                        'connected' => true,
                        'paytoken_id' => $paytoken_id,
                        'org_id' => $org_id,
                        'message' => 'Bank account connected successfully'
                    ));
                } else {
                    // No paytoken found - bank linking may not be complete yet
                    $logger->debug('PayToken not found in response', array(
                        'org_id' => $org_id,
                        'response_data' => $data
                    ));
                    wp_send_json_error('PayToken not found. Please complete bank linking first.');
                }
            } else {
                // API call failed - could be 404 (no paytoken) or other error
                $error_message = $result['error'] ?? 'Failed to retrieve paytoken';
                $status_code = $result['status_code'] ?? 0;

                $logger->debug('getLatestPayToken API failed', array(
                    'org_id' => $org_id,
                    'error' => $error_message,
                    'status_code' => $status_code,
                    'full_response' => $result
                ));

                // "Invalid request headers" can mean either:
                // - The org truly belongs to a different merchant, OR
                // - We're using the wrong credentials for this endpoint (common for returning/guest flows)
                // Do NOT immediately lock the email. Verify deterministically via /merchants/verify and parentOrgId.
                if (strpos(strtolower($error_message), 'invalid request headers') !== false) {
                    $lookup_email = '';

                    // Prefer Monarch-registered email if we have it; otherwise fall back to posted billing email.
                    if ($customer_id) {
                        $lookup_email = get_user_meta($customer_id, '_monarch_registered_email', true);
                        if (empty($lookup_email)) {
                            $user = get_userdata($customer_id);
                            if ($user) {
                                $lookup_email = $user->user_email;
                            }
                        }
                    } else {
                        $lookup_email = $this->post_email('billing_email');
                        if (empty($lookup_email) && function_exists('WC') && WC()->session) {
                            $lookup_email = WC()->session->get('monarch_registered_email');
                        }
                        if (empty($lookup_email) && function_exists('WC') && WC()->customer) {
                            $lookup_email = WC()->customer->get_billing_email();
                        }
                    }

                    $logger->debug('Invalid request headers - verifying via /merchants/verify', array(
                        'org_id' => $org_id,
                        'lookup_email' => $lookup_email ?: '(empty)'
                    ));

                    if (!empty($lookup_email)) {
                        $verify_api = new Monarch_API(
                            $this->api_key,
                            $this->app_id,
                            $this->merchant_org_id,
                            $this->partner_name,
                            $this->testmode
                        );
                        $verify_result = $verify_api->get_user_by_email($lookup_email);
                        $verify_status = $verify_result['status_code'] ?? 0;
                        $verify_data = $verify_result['data'] ?? array();
                        $verify_parent_org_id = $verify_data['parentOrgId'] ?? null;

                        // If verify confirms the purchaser is under a DIFFERENT parentOrgId, lock the email.
                        if (!empty($verify_parent_org_id) && $verify_parent_org_id !== $this->merchant_org_id) {
                            $logger->error('CRITICAL: Email belongs to different merchant (parentOrgId mismatch)', array(
                                'org_id' => $org_id,
                                'email' => $lookup_email,
                                'expected_parent_org_id' => $this->merchant_org_id,
                                'actual_parent_org_id' => $verify_parent_org_id,
                                'verify_status' => $verify_status
                            ));

                            if ($customer_id) {
                                $this->clear_user_monarch_data($customer_id);
                            }

                            wp_send_json_error(array(
                                'message' => 'This email is registered under a different Monarch merchant account. Please use a different email address.',
                                'action' => 'email_locked',
                                'cleared' => true
                            ));
                            return;
                        }

                        // Otherwise, attempt to recover by using purchaser credentials from verify and retry getLatestPayToken.
                        $credentials_key = $this->testmode ? 'sandbox' : 'prod';
                        $purchaser_creds = $verify_data['api'][$credentials_key] ?? null;
                        $retry_api_key = is_array($purchaser_creds) ? ($purchaser_creds['api_key'] ?? null) : null;
                        $retry_app_id = is_array($purchaser_creds) ? ($purchaser_creds['app_id'] ?? null) : null;

                        if (!empty($retry_api_key) && !empty($retry_app_id)) {
                            $logger->debug('Retrying getLatestPayToken with purchaser credentials from verify', array(
                                'org_id' => $org_id,
                                'api_key_last_4' => substr($retry_api_key, -4),
                                'app_id' => $retry_app_id
                            ));

                            $retry_monarch_api = new Monarch_API(
                                $retry_api_key,
                                $retry_app_id,
                                $this->merchant_org_id,
                                $this->partner_name,
                                $this->testmode
                            );
                            $retry_result = $retry_monarch_api->get_latest_paytoken($org_id);

                            if (!empty($retry_result['success'])) {
                                $data = $retry_result['data'] ?? array();
                                $paytoken_id = $data['_id'] ?? $data['payTokenId'] ?? $data['paytoken_id'] ?? $data['payToken'] ?? null;

                                // Persist recovered purchaser credentials for next time
                                if ($customer_id) {
                                    update_user_meta($customer_id, '_monarch_org_api_key', $retry_api_key);
                                    update_user_meta($customer_id, '_monarch_org_app_id', $retry_app_id);
                                } elseif (function_exists('WC') && WC()->session) {
                                    WC()->session->set('monarch_temp_org_api_key', $retry_api_key);
                                    WC()->session->set('monarch_temp_org_app_id', $retry_app_id);
                                }

                                if ($paytoken_id) {
                                    if ($customer_id) {
                                        update_user_meta($customer_id, '_monarch_paytoken_id', $paytoken_id);
                                    } elseif (function_exists('WC') && WC()->session) {
                                        WC()->session->set('monarch_paytoken_id', $paytoken_id);
                                    }

                                    wp_send_json_success(array(
                                        'connected' => true,
                                        'paytoken_id' => $paytoken_id,
                                        'org_id' => $org_id,
                                        'message' => 'Bank account connected successfully'
                                    ));
                                    return;
                                }
                            }
                        }
                    }

                    // If we couldn't verify/recover, do NOT lock the email; return a transient error.
                    wp_send_json_error('Unable to verify your bank connection right now. Please try again in a moment.');
                    return;
                }

                // 404 typically means no paytoken exists yet
                if ($status_code == 404 || strpos(strtolower($error_message), 'not found') !== false) {
                    wp_send_json_error('PayToken not found. Bank linking may not be complete yet.');
                } else {
                    wp_send_json_error($error_message);
                }
            }

        } catch (Exception $e) {
            wp_send_json_error('Error retrieving paytoken: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler for disconnecting bank account
     */
    public function ajax_disconnect_bank() {
        // Return JSON (avoid wp_die "Bad Request" responses)
        if (!check_ajax_referer('monarch_ach_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed. Please refresh the page and try again.');
            return;
        }

        $logger = WC_Monarch_Logger::instance();

        // Support both logged-in users and guest/returning customers (not logged in)
        if (!is_user_logged_in()) {
            // Guest: clear WC session stored data
            if (function_exists('WC') && WC()->session) {
                // Permanent session keys
                WC()->session->__unset('monarch_org_id');
                WC()->session->__unset('monarch_user_id');
                WC()->session->__unset('monarch_org_api_key');
                WC()->session->__unset('monarch_org_app_id');
                WC()->session->__unset('monarch_connected_date');

                // Temp/session flow keys
                WC()->session->__unset('monarch_temp_org_id');
                WC()->session->__unset('monarch_temp_user_id');
                WC()->session->__unset('monarch_temp_paytoken_id');
                WC()->session->__unset('monarch_paytoken_id');
                WC()->session->__unset('monarch_temp_org_api_key');
                WC()->session->__unset('monarch_temp_org_app_id');
                WC()->session->__unset('monarch_registered_email');
                WC()->session->__unset('monarch_bank_linking_url');
            }

            // Also clear WooCommerce checkout/customer session state (so checkout fields don't stay prefilled)
            $this->purge_woocommerce_checkout_state();

            // Force blank checkout fields for a short window + show banner after redirect.
            WC()->session->set('monarch_force_blank_checkout_until', time() + 15 * 60);
            WC()->session->set('monarch_show_bank_disconnected_banner', 1);

            $logger->debug('Guest bank disconnected - cleared session data', array());
            wp_send_json_success(array(
                'message' => __('Your bank account was disconnected. Please complete the checkout form again.', 'monarch-payments-for-woocommerce'),
                'redirect_url' => add_query_arg('monarch_bank_disconnected', '1', wc_get_checkout_url()),
            ));
            return;
        }

        $customer_id = get_current_user_id();

        // Delete all Monarch-related user meta using helper
        $this->clear_user_monarch_data($customer_id);

        // Also clear any WC session values (logged-in users can still have session data)
        if (function_exists('WC') && WC()->session) {
            WC()->session->__unset('monarch_org_id');
            WC()->session->__unset('monarch_user_id');
            WC()->session->__unset('monarch_paytoken_id');
            WC()->session->__unset('monarch_org_api_key');
            WC()->session->__unset('monarch_org_app_id');
            WC()->session->__unset('monarch_connected_date');
            WC()->session->__unset('monarch_temp_org_id');
            WC()->session->__unset('monarch_temp_user_id');
            WC()->session->__unset('monarch_temp_paytoken_id');
            WC()->session->__unset('monarch_temp_org_api_key');
            WC()->session->__unset('monarch_temp_org_app_id');
            WC()->session->__unset('monarch_registered_email');
            WC()->session->__unset('monarch_bank_linking_url');
        }

        // Clear WooCommerce checkout/customer session state (do not touch cart)
        if (function_exists('WC') && WC()->session) {
            $this->purge_woocommerce_checkout_state();
            WC()->session->set('monarch_force_blank_checkout_until', time() + 15 * 60);
            WC()->session->set('monarch_show_bank_disconnected_banner', 1);
        }

        // Log the disconnection
        $logger->log_customer_event('bank_disconnected', $customer_id, array());

        wp_send_json_success(array(
            'message' => __('Your bank account was disconnected. Please complete the checkout form again.', 'monarch-payments-for-woocommerce'),
            'redirect_url' => add_query_arg('monarch_bank_disconnected', '1', wc_get_checkout_url()),
        ));
    }

    /**
     * AJAX handler for getting bank linking URL for returning users
     * Used when a user has an org_id but their paytoken has expired after a transaction
     * Per Monarch: "They can simply select the bank and proceed by clicking Continue"
     */
    public function ajax_get_bank_linking_url() {
        check_ajax_referer('monarch_ach_nonce', 'nonce');

        $org_id = $this->post_text('org_id');
        
        if (empty($org_id)) {
            wp_send_json_error('Organization ID is required');
            return;
        }

        $logger = WC_Monarch_Logger::instance();
        

        try {
            // IMPORTANT:
            // get_current_user_id() returns 0 when the customer is not logged in (guest / logged-out returning user).
            // This endpoint supports both logged-in and guest flows (see wp_ajax_nopriv registration).
            $is_guest = !is_user_logged_in();
            $customer_id = $is_guest ? 0 : get_current_user_id();

            // If the customer changed their billing email, do not allow reconnecting the bank under a different email
            // while reusing the same stored Monarch purchaser/org. This avoids sandbox showing the old purchaser.
            $posted_billing_email = $this->post_email('billing_email');
            $stored_registered_email = '';
            if ($is_guest && function_exists('WC') && WC()->session) {
                $stored_registered_email = (string) WC()->session->get('monarch_registered_email');
            } elseif ($customer_id) {
                $stored_registered_email = (string) get_user_meta($customer_id, '_monarch_registered_email', true);
            }
            if (!empty($posted_billing_email) && !empty($stored_registered_email) && strtolower($posted_billing_email) !== strtolower($stored_registered_email)) {
                wp_send_json_error(array(
                    'message' => sprintf(
                        /* translators: 1: registered email, 2: billing email */
                        __('Your bank connection is linked to %1$s. Please use that billing email, or disconnect and reconnect using %2$s.', 'monarch-payments-for-woocommerce'),
                        $stored_registered_email,
                        $posted_billing_email
                    ),
                    'action' => 'email_mismatch'
                ));
                return;
            }

            // Get purchaser credentials if available (required by some Monarch endpoints).
            // For guests, these can only exist in the WC session for the current session.
            $purchaser_api_key = null;
            $purchaser_app_id = null;

            if ($is_guest && WC()->session) {
                // Guests may have either "temp" keys (during registration flow) or "org" keys (stored for returning flows)
                $purchaser_api_key = WC()->session->get('monarch_temp_org_api_key');
                $purchaser_app_id = WC()->session->get('monarch_temp_org_app_id');
                if (empty($purchaser_api_key)) {
                    $purchaser_api_key = WC()->session->get('monarch_org_api_key');
                }
                if (empty($purchaser_app_id)) {
                    $purchaser_app_id = WC()->session->get('monarch_org_app_id');
                }
            } elseif ($customer_id) {
                // Logged-in: try temp first, then permanent
                $purchaser_api_key = get_user_meta($customer_id, '_monarch_temp_org_api_key', true);
                $purchaser_app_id = get_user_meta($customer_id, '_monarch_temp_org_app_id', true);

                if (empty($purchaser_api_key)) {
                    $purchaser_api_key = get_user_meta($customer_id, '_monarch_org_api_key', true);
                    $purchaser_app_id = get_user_meta($customer_id, '_monarch_org_app_id', true);
                }
            }

            // Use purchaser credentials if available, otherwise merchant credentials
            $api_key_to_use = $purchaser_api_key ?: $this->api_key;
            $app_id_to_use = $purchaser_app_id ?: $this->app_id;

            $logger->debug('Getting bank linking URL for returning user', array(
                'org_id' => $org_id,
                'customer_id' => $customer_id,
                'is_guest' => $is_guest,
                'using_purchaser_credentials' => !empty($purchaser_api_key)
            ));

            // FIRST: Check if we have a stored bank linking URL
            $stored_url = '';
            if ($is_guest && WC()->session) {
                $stored_url = WC()->session->get('monarch_bank_linking_url');
            } elseif ($customer_id) {
                $stored_url = get_user_meta($customer_id, '_monarch_bank_linking_url', true);
            }
            if (!empty($stored_url)) {
                $logger->debug('Found stored bank linking URL for returning user', array(
                    'url_length' => strlen($stored_url)
                ));
                wp_send_json_success(array(
                    'bank_linking_url' => $stored_url,
                    'org_id' => $org_id,
                    'source' => 'stored'
                ));
                return;
            }

            // SECOND: Check if user already has a valid paytoken
            $api_url = $this->testmode
                ? 'https://devapi.monarch.is/v1'
                : 'https://api.monarch.is/v1';

            $response = wp_remote_get($api_url . '/getlatestpaytoken/' . $org_id, array(
                'headers' => array(
                    'accept' => 'application/json',
                    'X-API-KEY' => $api_key_to_use,
                    'X-APP-ID' => $app_id_to_use
                ),
                'timeout' => 30
            ));

            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);

                if ($status_code >= 200 && $status_code < 300) {
                    $paytoken_id = $body['_id'] ?? $body['payToken'] ?? $body['id'] ?? null;

                    if ($paytoken_id) {
                        // User already has a valid paytoken - save it and return
                        if ($is_guest && WC()->session) {
                            WC()->session->set('monarch_paytoken_id', $paytoken_id);
                        } elseif ($customer_id) {
                            update_user_meta($customer_id, '_monarch_paytoken_id', $paytoken_id);
                        }

                        $logger->debug('Returning user already has valid paytoken', array(
                            'paytoken_id' => $paytoken_id
                        ));

                        wp_send_json_success(array(
                            'paytoken_id' => $paytoken_id,
                            'org_id' => $org_id,
                            'message' => 'Bank account already connected'
                        ));
                        return;
                    }
                }
            }

            // No valid paytoken - need to get bank linking URL via /merchants/verify
            $logger->debug('ajax_get_bank_linking_url: No valid paytoken, calling /merchants/verify', array(
                'org_id' => $org_id
            ));

            // Get the email used to register with Monarch (may differ from WP user email)
            // CRITICAL: Must use the email registered in Monarch, not the WordPress user email
            $user_email = '';
            if ($customer_id) {
                // First try the stored Monarch-registered email
                $user_email = get_user_meta($customer_id, '_monarch_registered_email', true);

                // Fallback to WordPress user email only if no Monarch email stored
                if (empty($user_email)) {
                    $user = get_userdata($customer_id);
                    if ($user) {
                        $user_email = $user->user_email;
                    }
                }

                $logger->debug('ajax_get_bank_linking_url: Email for lookup', array(
                    'monarch_registered_email' => get_user_meta($customer_id, '_monarch_registered_email', true),
                    'wp_user_email' => get_userdata($customer_id)->user_email ?? '',
                    'using_email' => $user_email
                ));
            }
            if (empty($user_email) && $is_guest) {
                // Guest flow: prefer what the customer typed in checkout; fall back to session.
                $user_email = $this->post_email('billing_email');
                if (empty($user_email) && WC()->session) {
                    $user_email = WC()->session->get('monarch_registered_email');
                }
                // Fallback: try WooCommerce customer session email (works even when not logged in)
                if (empty($user_email) && function_exists('WC') && WC()->customer) {
                    $user_email = WC()->customer->get_billing_email();
                }
                // Fallback: pay-for-order page can have an order in session
                if (empty($user_email) && function_exists('WC') && WC()->session) {
                    $order_id = WC()->session->get('order_awaiting_payment');
                    if ($order_id) {
                        $order = wc_get_order($order_id);
                        if ($order) {
                            $user_email = $order->get_billing_email();
                        }
                    }
                }
            }
            if (empty($user_email)) {
                wp_send_json_error(array(
                    'message' => 'Please enter your billing email to reconnect your bank account, then try again.',
                    'action' => 'missing_email'
                ));
                return;
            }

            $bank_linking_url = '';
            if (!empty($user_email)) {
                // IMPORTANT: Use Monarch_API::get_user_by_email() because /merchants/verify
                // requires environment-specific credentials (see Monarch_API implementation).
                $monarch_api = new Monarch_API(
                    $this->api_key,
                    $this->app_id,
                    $this->merchant_org_id,
                    $this->partner_name,
                    $this->testmode
                );
                
                $verify_result = $monarch_api->get_user_by_email($user_email);
                $verify_status = $verify_result['status_code'] ?? 0;
                $verify_data = $verify_result['data'] ?? array();
                $bank_linking_url = $verify_data['partner_embedded_url'] ?? '';

                $logger->debug('ajax_get_bank_linking_url: /merchants/verify result', array(
                    'status_code' => $verify_status,
                    'success' => $verify_result['success'] ?? false,
                    'has_partner_embedded_url' => !empty($bank_linking_url)
                ));
            }

            if (empty($bank_linking_url)) {
                $logger->error('ajax_get_bank_linking_url: Could not retrieve bank linking URL', array(
                    'org_id' => $org_id,
                    'user_email' => $user_email
                ));

                // Only force "re-register" when the Monarch account truly can't be found (404).
                // For other cases (temporary API issues, unexpected response), do NOT clear data.
                $should_force_reregister = false;
                if ((int) ($verify_status ?? 0) === 404) {
                    $should_force_reregister = true;
                }

                if ($should_force_reregister) {
                    if ($customer_id) {
                        $this->clear_user_monarch_data($customer_id);
                    } elseif (WC()->session) {
                        WC()->session->__unset('monarch_temp_org_id');
                        WC()->session->__unset('monarch_temp_user_id');
                        WC()->session->__unset('monarch_paytoken_id');
                        WC()->session->__unset('monarch_temp_org_api_key');
                        WC()->session->__unset('monarch_temp_org_app_id');
                        WC()->session->__unset('monarch_registered_email');
                        WC()->session->__unset('monarch_bank_linking_url');
                    }

                    $logger->debug('Cleared user Monarch data - they need to re-register');

                    wp_send_json_error(array(
                        'message' => 'Your bank connection needs to be refreshed. Please complete the registration form below to reconnect.',
                        'action' => 'show_registration_form',
                        'cleared_data' => true
                    ));
                } else {
                    wp_send_json_error('Unable to retrieve bank linking URL right now. Please try again in a moment.');
                }
                return;
            }

            // Clean up URL (same logic as create_organization)
            $bank_linking_url = urldecode($bank_linking_url);
            if (strpos($bank_linking_url, 'http%3A') !== false) {
                $bank_linking_url = urldecode($bank_linking_url);
            }

            $logger->debug('Bank linking URL retrieved for returning user', array(
                'org_id' => $org_id,
                'url_length' => strlen($bank_linking_url)
            ));

            // Cache URL for this session/user to avoid extra API calls
            if ($is_guest && WC()->session) {
                WC()->session->set('monarch_bank_linking_url', $bank_linking_url);
            } elseif ($customer_id) {
                update_user_meta($customer_id, '_monarch_bank_linking_url', $bank_linking_url);
            }

            wp_send_json_success(array(
                'bank_linking_url' => $bank_linking_url,
                'org_id' => $org_id
            ));

        } catch (Exception $e) {
            wp_send_json_error('Error getting bank linking URL: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler for updating purchaser/org profile details for returning users
     * Calls: PUT /v1/organization/update
     */
    public function ajax_update_organization() {
        check_ajax_referer('monarch_ach_nonce', 'nonce');

        $logger = WC_Monarch_Logger::instance();

        try {
            $is_guest = !is_user_logged_in();
            $customer_id = $is_guest ? 0 : get_current_user_id();

            // Used for logging/diagnostics only (Monarch endpoint does not require orgId in payload).
            $org_id = isset($_POST['org_id']) ? sanitize_text_field(wp_unslash($_POST['org_id'])) : '';

            // Get purchaser credentials if available (required by some Monarch endpoints)
            $purchaser_api_key = null;
            $purchaser_app_id = null;

            if ($is_guest && function_exists('WC') && WC()->session) {
                $purchaser_api_key = WC()->session->get('monarch_temp_org_api_key');
                $purchaser_app_id = WC()->session->get('monarch_temp_org_app_id');
                // Guests may have either "temp" keys (during registration flow) or "org" keys (stored for returning flows)
                if (empty($purchaser_api_key)) {
                    $purchaser_api_key = WC()->session->get('monarch_org_api_key');
                }
                if (empty($purchaser_app_id)) {
                    $purchaser_app_id = WC()->session->get('monarch_org_app_id');
                }
            } elseif ($customer_id) {
                $purchaser_api_key = get_user_meta($customer_id, '_monarch_temp_org_api_key', true);
                $purchaser_app_id = get_user_meta($customer_id, '_monarch_temp_org_app_id', true);

                if (empty($purchaser_api_key)) {
                    $purchaser_api_key = get_user_meta($customer_id, '_monarch_org_api_key', true);
                    $purchaser_app_id = get_user_meta($customer_id, '_monarch_org_app_id', true);
                }
            }

            // Use purchaser credentials if available, otherwise merchant credentials
            $api_key_to_use = $purchaser_api_key ?: $this->api_key;
            $app_id_to_use = $purchaser_app_id ?: $this->app_id;

            // Opt-in: allow logging/returning full API secrets for Postman testing.
            // Define in wp-config.php: define('MONARCH_ACH_LOG_SENSITIVE', true);
            $log_sensitive = defined('MONARCH_ACH_LOG_SENSITIVE') && MONARCH_ACH_LOG_SENSITIVE;

            $logger->debug('ajax_update_organization called', array(
                'org_id' => $org_id,
                'customer_id' => $customer_id,
                'is_guest' => $is_guest,
                'using_purchaser_credentials' => !empty($purchaser_api_key),
                'api_key_last_4' => substr((string) $api_key_to_use, -4),
                'app_id_last_4' => substr((string) $app_id_to_use, -4),
                'testmode' => $this->testmode ? 'yes' : 'no',
            ));

            $monarch_api = new Monarch_API(
                $api_key_to_use,
                $app_id_to_use,
                $this->merchant_org_id,
                $this->partner_name,
                $this->testmode
            );

            

            // Build payload (do not include email; update endpoint uses API key/app id identity)
            $payload = array();

            $map = array(
                'first_name' => 'first_name',
                'last_name' => 'last_name',
                'phone' => 'phone',
                'companyName' => 'companyName',
                'add1' => 'add1',
                'add2' => 'add2',
                'city' => 'city',
                'state' => 'state',
                'zip' => 'zip',
                'country' => 'country',
            );

            foreach ($map as $post_key => $payload_key) {
                if (!array_key_exists($post_key, $_POST)) {
                    continue;
                }

                $raw = sanitize_text_field(wp_unslash($_POST[$post_key]));
                $raw = is_string($raw) ? trim($raw) : '';

                if ($post_key === 'phone') {
                    $digits = preg_replace('/[^0-9]/', '', $raw);
                    // Keep empty string if no digits (lets payload mirror the form)
                    $payload[$payload_key] = (string) $digits;
                    continue;
                }

                $payload[$payload_key] = $raw;
            }

            // Optional DOB (accepts yyyy-mm-dd or mm/dd/yyyy)
            if (array_key_exists('dob', $_POST)) {
                $dob_raw = sanitize_text_field(wp_unslash($_POST['dob']));
                $dob_raw = is_string($dob_raw) ? trim($dob_raw) : '';
                if ($dob_raw === '') {
                    $payload['dob'] = '';
                } else {
                    $timestamp = strtotime($dob_raw);
                    $payload['dob'] = $timestamp ? gmdate('m/d/Y', $timestamp) : $dob_raw;
                }
            }

            // Always send push notification enable if provided; default true
            if (array_key_exists('heloxPushNotificationEnable', $_POST)) {
                $payload['heloxPushNotificationEnable'] = filter_var(
                    wp_unslash($_POST['heloxPushNotificationEnable']),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                );
                if ($payload['heloxPushNotificationEnable'] === null) {
                    $payload['heloxPushNotificationEnable'] = true;
                }
            } else {
                $payload['heloxPushNotificationEnable'] = true;
            }

            // If everything besides the boolean flag is empty, treat as no-op
            $non_bool_payload = $payload;
            unset($non_bool_payload['heloxPushNotificationEnable']);
            $has_any_value = false;
            foreach ($non_bool_payload as $v) {
                if ($v !== '' && $v !== null) {
                    $has_any_value = true;
                    break;
                }
            }

            if (!$has_any_value) {
                wp_send_json_success(array(
                    'message' => 'No profile changes to update',
                    'skipped' => true,
                ));
                return;
            }

            // Build a copy/paste curl for Postman testing (Monarch direct request)
            $monarch_base_url = $this->testmode ? 'https://devapi.monarch.is/v1' : 'https://api.monarch.is/v1';
            $monarch_url = $monarch_base_url . '/organization/update';
            $headers_for_postman = array(
                'accept' => 'application/json',
                'X-API-KEY' => $log_sensitive ? (string) $api_key_to_use : ('****' . substr((string) $api_key_to_use, -4)),
                'X-APP-ID' => $log_sensitive ? (string) $app_id_to_use : ('****' . substr((string) $app_id_to_use, -4)),
                'Content-Type' => 'application/json',
            );
            $payload_json = wp_json_encode($payload);

            // Build curl string (shell-safe)
            $curl_parts = array(
                'curl -X PUT ' . escapeshellarg($monarch_url),
                '-H ' . escapeshellarg('accept: application/json'),
                '-H ' . escapeshellarg('X-API-KEY: ' . ($log_sensitive ? (string) $api_key_to_use : '****' . substr((string) $api_key_to_use, -4))),
                '-H ' . escapeshellarg('X-APP-ID: ' . ($log_sensitive ? (string) $app_id_to_use : '****' . substr((string) $app_id_to_use, -4))),
                '-H ' . escapeshellarg('Content-Type: application/json'),
                '-d ' . escapeshellarg($payload_json),
            );
            $curl_cmd = implode(" \\\n  ", $curl_parts);

            $logger->debug('Monarch organization/update Postman request', array(
                'org_id' => $org_id,
                'url' => $monarch_url,
                'method' => 'PUT',
                'headers' => $headers_for_postman,
                'body' => $payload,
                'curl' => $curl_cmd,
                'note' => $log_sensitive ? 'Sensitive logging enabled (MONARCH_ACH_LOG_SENSITIVE=true)' : 'API secrets masked (define MONARCH_ACH_LOG_SENSITIVE=true to log full values)',
            ));

            $result = $monarch_api->update_organization($payload);

            if (!$result['success']) {
                $error = $result['error'] ?? 'Failed to update profile details. Please try again.';
                wp_send_json_error(array(
                    'message' => $error,
                    // Return Postman info only to admins (and only full secrets when opted-in)
                    'postman' => current_user_can('manage_woocommerce') ? array(
                        'method' => 'PUT',
                        'url' => $monarch_url,
                        'headers' => $headers_for_postman,
                        'body' => $payload,
                        'curl' => $curl_cmd,
                        'sensitive' => $log_sensitive,
                    ) : null,
                ));
                return;
            }

            wp_send_json_success(array(
                'message' => 'Profile updated successfully',
                'data' => $result['data'] ?? array(),
                // Return Postman info only to admins (and only full secrets when opted-in)
                'postman' => current_user_can('manage_woocommerce') ? array(
                    'method' => 'PUT',
                    'url' => $monarch_url,
                    'headers' => $headers_for_postman,
                    'body' => $payload,
                    'curl' => $curl_cmd,
                    'sensitive' => $log_sensitive,
                ) : null,
            ));
        } catch (Exception $e) {
            $logger->error('ajax_update_organization exception', array(
                'message' => $e->getMessage(),
            ));
            wp_send_json_error(array(
                'message' => 'Unexpected error updating profile. Please try again.',
            ));
        }
    }

    /**
     * AJAX handler for manual bank entry
     * Creates organization + paytoken + assigns in one flow
     */
    public function ajax_manual_bank_entry() {
        check_ajax_referer('monarch_ach_nonce', 'nonce');

        if (!$this->allow_manual_account_checkout) {
            wp_send_json_error(array(
                'message' => __('Manual bank account entry is not enabled for this store.', 'monarch-payments-for-woocommerce'),
            ));
            return;
        }

        // Support both logged in users and guest checkout
        $is_guest = !is_user_logged_in();
        
        if ($is_guest) {
            // For guest checkout, require session for data storage
            if (!WC()->session || !WC()->session->get_session_cookie()) {
                wp_send_json_error('Session required for guest checkout');
                return;
            }
        }

        try {
            $monarch_api = new Monarch_API(
                $this->api_key,
                $this->app_id,
                $this->merchant_org_id,
                $this->partner_name,
                $this->testmode
            );

            // Get user data - support both logged in users and guest checkout
            // IMPORTANT: Always use billing_email from form (real-time input), NOT cached WordPress user email
            $form_email = $this->post_email('billing_email');

            $logger = WC_Monarch_Logger::instance();

            if ($is_guest) {
                // For guest checkout, use billing email from form
                $user_email = $form_email;
                $user_id = 'guest_' . substr(md5($user_email . time()), 0, 8);
                $customer_id = null; // No WordPress user ID for guests
            } else {
                $current_user = wp_get_current_user();
                $customer_id = get_current_user_id();
                // Use form email if provided, otherwise fall back to WordPress user email
                $user_email = !empty($form_email) ? $form_email : $current_user->user_email;
                $user_id = $customer_id;
            }

            $logger->debug('Manual entry: Email being used for organization', array(
                'form_email' => $form_email,
                'wp_user_email' => $is_guest ? 'N/A (guest)' : $current_user->user_email,
                'final_email' => $user_email,
                'is_guest' => $is_guest
            ));

            // Validate required fields
            $bank_name = $this->post_text('bank_name');
            $routing_number = $this->post_text('routing_number');
            $account_number = $this->post_text('account_number');
            $account_type = $this->post_text('account_type');

            if (empty($bank_name) || empty($routing_number) || empty($account_number)) {
                wp_send_json_error('Please fill in all bank details');
            }

            // Validate routing number (9 digits)
            if (!preg_match('/^\d{9}$/', $routing_number)) {
                wp_send_json_error('Routing number must be exactly 9 digits');
            }

            // IMPORTANT: Always prioritize form data (real-time input) over cached WordPress user data
            // Get form values first
            $form_first_name = $this->post_text('billing_first_name');
            $form_last_name = $this->post_text('billing_last_name');

            // Use form data if provided, otherwise fall back to WordPress user data
            if ($is_guest) {
                $first_name = $form_first_name;
                $last_name = $form_last_name;
            } else {
                $first_name = !empty($form_first_name) ? $form_first_name : $current_user->user_firstname;
                $last_name = !empty($form_last_name) ? $form_last_name : $current_user->user_lastname;
            }

            $logger->debug('Manual entry: Customer data being used for organization', array(
                'form_email' => $form_email,
                'form_first_name' => $form_first_name,
                'form_last_name' => $form_last_name,
                'wp_user_email' => $is_guest ? 'N/A (guest)' : $current_user->user_email,
                'wp_first_name' => $is_guest ? 'N/A (guest)' : $current_user->user_firstname,
                'wp_last_name' => $is_guest ? 'N/A (guest)' : $current_user->user_lastname,
                'final_email' => $user_email,
                'final_first_name' => $first_name,
                'final_last_name' => $last_name,
                'is_guest' => $is_guest
            ));

            // Prepare customer data - ALL fields use form data (real-time input)
            $phone = preg_replace('/[^0-9]/', '', $this->post_text('monarch_phone'));
            $phone = substr($phone, -10);

            $dob = $this->get_post_monarch_dob_for_api();

            $customer_data = array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $user_email,
                'password' => wp_generate_password(16, true, true),
                'phone' => $phone,
                'company_name' => $this->post_text('monarch_company'),
                'dob' => $dob,
                'address_1' => $this->post_text('billing_address_1'),
                'address_2' => $this->post_text('billing_address_2'),
                'city' => $this->post_text('billing_city'),
                'state' => $this->post_text('billing_state'),
                'zip' => $this->post_text('billing_postcode'),
                'country' => $this->post_text('billing_country')
            );

            // STEP 0: Check if user already exists by email (prevents "email already exists" error)
            $logger->debug('========== MANUAL ENTRY: EMAIL LOOKUP START ==========');
            $logger->debug('Manual entry: Checking if user already exists by email', array('email' => $user_email));
            $existing_user = $monarch_api->get_user_by_email($user_email);

            // Log the COMPLETE response from getUserByEmail for debugging
            $logger->debug('Manual entry: getUserByEmail FULL RESPONSE', array(
                'email_checked' => $user_email,
                'user_exists' => $existing_user['user_exists'] ?? 'NOT SET',
                'success' => $existing_user['success'] ?? 'NOT SET',
                'org_id_direct' => $existing_user['org_id'] ?? 'NOT SET',
                'data' => $existing_user['data'] ?? 'NOT SET',
                'error' => $existing_user['error'] ?? 'NOT SET',
                'status_code' => $existing_user['status_code'] ?? 'NOT SET',
                'full_response' => $existing_user
            ));

            // Check if user exists using the new user_exists flag
            $user_exists = $existing_user['user_exists'] ?? false;
            $found_org_id = null;
            $found_user_id = null;

            if ($user_exists) {
                
                // User exists - extract org_id
                // First check if API returned org_id directly
                $found_org_id = $existing_user['org_id'] ?? null;
                //print_r($found_org_id);
                // If not, search in data
                if (!$found_org_id && !empty($existing_user['data'])) {
                    $data = $existing_user['data'];
                   
                    $found_org_id = $data['orgId'] ?? $data['org_id'] ?? $data['organizationId'] ?? $data['organization_id'] ?? null;
                    $found_user_id = $data['_id'] ?? $data['userId'] ?? $data['user_id'] ?? $data['id'] ?? null;
                } else {
                    $data = $existing_user['data'];
                    $found_org_id = $data['orgId'] ?? $data['org_id'] ?? $data['organizationId'] ?? $data['organization_id'] ?? null;
                    $found_user_id = $data['_id'] ?? $data['userId'] ?? $data['user_id'] ?? $data['id'] ?? null;
                }
               // print_r($found_org_id);
               // print_r($found_user_id);
               // die;

                // Deep search as last resort
                if (!$found_org_id) {
                    $found_org_id = $this->find_org_id_recursive($existing_user);
                }

                $logger->debug('Manual entry: User EXISTS - extracted org_id', array(
                    'found_org_id' => $found_org_id,
                    'found_user_id' => $found_user_id
                ));
            }

            $logger->debug('========== MANUAL ENTRY: EMAIL LOOKUP END ==========', array(
                'user_exists' => $user_exists,
                'final_org_id' => $found_org_id,
                'final_user_id' => $found_user_id,
                'will_use_existing' => $user_exists && !empty($found_org_id)
            ));

            // IMPORTANT: Sale transactions require the purchaser org's API credentials (NOT merchant/partner creds).
            // When the purchaser already exists, /merchants/verify can include these credentials under `api`.
            $purchaser_api_key = null;
            $purchaser_app_id = null;
            $credentials_key = $this->testmode ? 'sandbox' : 'prod';
            if ($user_exists && !empty($existing_user['data']) && is_array($existing_user['data']) && !empty($existing_user['data']['api']) && is_array($existing_user['data']['api'])) {
                $purchaser_creds = $existing_user['data']['api'][$credentials_key] ?? null;
                if (is_array($purchaser_creds)) {
                    $purchaser_api_key = $purchaser_creds['api_key'] ?? null;
                    $purchaser_app_id = $purchaser_creds['app_id'] ?? null;
                }
            }

            $org_id = $found_org_id;
            $user_id = $found_user_id;
            $org_result = null; // Initialize to prevent undefined variable errors
            $is_existing_user = $user_exists && !empty($found_org_id);

            if ($is_existing_user) {
                $logger->debug('Manual entry: SUCCESS - Existing user found, will NOT create new organization', array(
                    'email' => $user_email,
                    'org_id' => $org_id,
                    'user_id' => $user_id
                ));
            }

            // Step 1: Create organization (only if user doesn't exist)
            if (!$org_id) {
                $logger->debug('========== MANUAL ENTRY: USER NOT FOUND - CREATING NEW ORG ==========');
                $logger->debug('Manual entry: User was NOT found by email lookup. Proceeding to create new organization.', array(
                    'email' => $user_email,
                    'user_exists_flag' => $user_exists,
                    'api_error' => $existing_user['error'] ?? 'none',
                    'status_code' => $existing_user['status_code'] ?? 'unknown'
                ));
                $org_result = $monarch_api->create_organization($customer_data);

                if (!$org_result['success']) {
                    // Organization creation failed - treat as existing user
                    $logger->info('Manual entry: Organization creation failed - treating as existing user', array(
                        'email' => $user_email,
                        'error' => $org_result['error'] ?? 'unknown'
                    ));

                    // Try to get existing user info via verify
                    $existing_lookup = $monarch_api->get_user_by_email($user_email);
                    $org_id = $existing_lookup['data']['orgId'] ?? null;
                    $user_id = $existing_lookup['data']['_id'] ?? $existing_lookup['data']['userId'] ?? null;
                    $is_existing_user = true;

                    // Also capture purchaser credentials from verify response if present
                    if (!empty($existing_lookup['data']) && is_array($existing_lookup['data']) && !empty($existing_lookup['data']['api']) && is_array($existing_lookup['data']['api'])) {
                        $purchaser_creds = $existing_lookup['data']['api'][$credentials_key] ?? null;
                        if (is_array($purchaser_creds)) {
                            $purchaser_api_key = $purchaser_creds['api_key'] ?? $purchaser_api_key;
                            $purchaser_app_id = $purchaser_creds['app_id'] ?? $purchaser_app_id;
                        }
                    }

                    // If still no org_id, cannot proceed with manual entry (requires user_id for paytoken)
                    if (!$org_id || !$user_id) {
                        $logger->warning('Manual entry: Cannot proceed without org_id and user_id', array(
                            'email' => $user_email
                        ));
                        wp_send_json_error('Please use the automatic bank linking option instead.');
                        return;
                    }
                } else {
                    $user_id = $org_result['data']['_id'];
                    $org_id = $org_result['data']['orgId'];

                    // For new orgs, purchaser credentials are returned on creation. Prefer these.
                    if (isset($org_result['data']['api']) && is_array($org_result['data']['api'])) {
                        $org_api = $org_result['data']['api'];
                        $org_credentials = $org_api[$credentials_key] ?? null;
                        if (is_array($org_credentials)) {
                            $purchaser_api_key = $org_credentials['api_key'] ?? $purchaser_api_key;
                            $purchaser_app_id = $org_credentials['app_id'] ?? $purchaser_app_id;
                        }
                    }

                    // Log organization creation
                    $logger->log_customer_event('organization_created_manual', $customer_id, array(
                        'org_id' => $org_id,
                        'user_id' => $user_id
                    ));
                }
            } else {
                $logger->debug('Manual entry: Using existing organization', array(
                    'org_id' => $org_id,
                    'user_id' => $user_id
                ));
            }

            // Step 2: Create PayToken with bank details
            $bank_data = array(
                'bank_name' => $bank_name,
                'account_number' => $account_number,
                'routing_number' => $routing_number,
                'account_type' => $account_type
            );

            

            $paytoken_result = $monarch_api->create_paytoken($user_id, $bank_data);

            if (!$paytoken_result['success']) {
                wp_send_json_error('Bank account setup failed: ' . $paytoken_result['error']);
            }

            $paytoken_id = $paytoken_result['data']['payToken'] ?? $paytoken_result['data']['_id'];

            // Step 3: Assign PayToken to organization
            $assign_result = $monarch_api->assign_paytoken($paytoken_id, $org_id);

            if (!$assign_result['success']) {
                wp_send_json_error('Failed to link bank account: ' . $assign_result['error']);
            }

            // Save permanent data based on user type
            if ($is_guest) {
                // For guest users, store in session (will be moved to order meta during checkout)
                WC()->session->set('monarch_org_id', $org_id);
                WC()->session->set('monarch_user_id', $user_id);
                WC()->session->set('monarch_paytoken_id', $paytoken_id);
                WC()->session->set('monarch_connected_date', current_time('mysql'));

                // Store purchaser credentials for sale transactions (required to avoid "Org id is not valid").
                if (!empty($purchaser_api_key) && !empty($purchaser_app_id)) {
                    WC()->session->set('monarch_org_api_key', $purchaser_api_key);
                    WC()->session->set('monarch_org_app_id', $purchaser_app_id);
                }
            } else {
                // Save permanent user data for logged-in users
                update_user_meta($customer_id, '_monarch_org_id', $org_id);
                update_user_meta($customer_id, '_monarch_user_id', $user_id);
                update_user_meta($customer_id, '_monarch_paytoken_id', $paytoken_id);
                update_user_meta($customer_id, '_monarch_connected_date', current_time('mysql'));

                // IMPORTANT: Store the merchant_org_id and testmode used when creating this connection
                update_user_meta($customer_id, '_monarch_merchant_org_id', $this->merchant_org_id);
                update_user_meta($customer_id, '_monarch_testmode', $this->testmode ? 'yes' : 'no');

                // Store purchaser credentials for sale transactions (required to avoid "Org id is not valid").
                if (!empty($purchaser_api_key) && !empty($purchaser_app_id)) {
                    update_user_meta($customer_id, '_monarch_org_api_key', $purchaser_api_key);
                    update_user_meta($customer_id, '_monarch_org_app_id', $purchaser_app_id);
                }
            }

            // Log successful bank connection
            $logger->log_customer_event('bank_connected_manual', $customer_id, array(
                'org_id' => $org_id,
                'paytoken_id' => $paytoken_id,
                'bank_name' => $bank_name
            ));

            // If we previously forced a blank checkout due to disconnect, clear that now that the bank is reconnected.
            if (function_exists('WC') && WC()->session) {
                WC()->session->__unset('monarch_force_blank_checkout_until');
                WC()->session->__unset('monarch_show_bank_disconnected_banner');
            }

            wp_send_json_success(array(
                'org_id' => $org_id,
                'paytoken_id' => $paytoken_id,
                'message' => 'Bank account connected successfully'
            ));

        } catch (Exception $e) {
            wp_send_json_error('Manual bank entry failed: ' . $e->getMessage());
        }
    }

    /**
     * Clear all stored Monarch data for a user
     * Used when credentials become invalid or environment changes
     */
    private function clear_user_monarch_data($customer_id) {
        delete_user_meta($customer_id, '_monarch_org_id');
        delete_user_meta($customer_id, '_monarch_user_id');
        delete_user_meta($customer_id, '_monarch_paytoken_id');
        delete_user_meta($customer_id, '_monarch_org_api_key');
        delete_user_meta($customer_id, '_monarch_org_app_id');
        delete_user_meta($customer_id, '_monarch_connected_date');
        delete_user_meta($customer_id, '_monarch_merchant_org_id');
        delete_user_meta($customer_id, '_monarch_testmode');
        delete_user_meta($customer_id, '_monarch_temp_org_id');
        delete_user_meta($customer_id, '_monarch_temp_user_id');
        delete_user_meta($customer_id, '_monarch_temp_org_api_key');
        delete_user_meta($customer_id, '_monarch_temp_org_app_id');
        delete_user_meta($customer_id, '_monarch_bank_linking_url');
        delete_user_meta($customer_id, '_monarch_registered_email');
    }

    /**
     * Purge WooCommerce checkout/customer cached session data (without clearing the cart).
     * This prevents checkout fields from being auto-filled from a previous attempt after bank disconnect.
     */
    private function purge_woocommerce_checkout_state() {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        // Known WC session keys that can preserve checkout state.
        $keys_to_unset = array(
            'customer',
            'checkout',
            'chosen_payment_method',
            'chosen_shipping_methods',
            'shipping_method_counts',
            'ship_to_different_address',
        );

        foreach ($keys_to_unset as $key) {
            WC()->session->__unset($key);
        }

        // Unset any dynamic shipping package keys.
        if (method_exists(WC()->session, 'get_session_data')) {
            $data = (array) WC()->session->get_session_data();
            foreach (array_keys($data) as $key) {
                if (strpos($key, 'shipping_for_package_') === 0) {
                    WC()->session->__unset($key);
                }
            }
        }
    }

    /**
     * Verify that an organization exists and is accessible with the given credentials
     * This is a pre-flight check before attempting transactions to provide better error messages
     * Uses /getlatestpaytoken/{org_id} endpoint which validates both org existence and paytoken
     */
    private function verify_organization_exists($org_id, $api_key, $app_id) {
        try {
            $api_url = $this->testmode ? 'https://devapi.monarch.is/v1' : 'https://api.monarch.is/v1';

            $response = wp_remote_get($api_url . '/getlatestpaytoken/' . $org_id, array(
                'headers' => array(
                    'accept' => 'application/json',
                    'X-API-KEY' => $api_key,
                    'X-APP-ID' => $app_id
                ),
                'timeout' => 15 // Shorter timeout for pre-flight check
            ));

            if (is_wp_error($response)) {
                // Network error - don't fail, let the transaction attempt proceed
                return array(
                    'valid' => true, // Assume valid on network error
                    'error' => 'Network check skipped: ' . $response->get_error_message()
                );
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            // 2xx status codes indicate the org exists
            if ($status_code >= 200 && $status_code < 300) {
                return array(
                    'valid' => true,
                    'error' => null,
                    'paytoken_id' => $body['_id'] ?? $body['payToken'] ?? null
                );
            }

            // 404 means org not found - this is a definitive "org doesn't exist"
            if ($status_code == 404) {
                return array(
                    'valid' => false,
                    'error' => '404 not found - organization does not exist'
                );
            }

            // 401/403 might mean credential mismatch - still attempt transaction
            if ($status_code == 401 || $status_code == 403) {
                return array(
                    'valid' => true, // Let transaction attempt proceed
                    'error' => 'Auth issue on pre-check (status ' . $status_code . '), will attempt transaction'
                );
            }

            // Other errors - extract message
            $error_msg = $body['error']['message'] ?? $body['message'] ?? 'Unknown error (status ' . $status_code . ')';

            // For most errors, let the transaction attempt proceed
            return array(
                'valid' => true,
                'error' => 'Pre-check warning: ' . $error_msg
            );

        } catch (Exception $e) {
            // On exception, don't block - let transaction attempt proceed
            return array(
                'valid' => true,
                'error' => 'Pre-check exception: ' . $e->getMessage()
            );
        }
    }

    /**
     * Validate that a paytoken exists and can be used with the given credentials
     * This prevents "Paytoken is Invalid" errors by verifying credential consistency
     * Uses /getlatestpaytoken/{org_id} endpoint which works with merchant credentials for child organizations
     */
    private function validate_paytoken_with_credentials($org_id, $paytoken_id, $api_key, $app_id) {
        try {
            $api_url = $this->testmode ? 'https://devapi.monarch.is/v1' : 'https://api.monarch.is/v1';

            // Use /getlatestpaytoken/ endpoint to get paytokens for the organization
            $response = wp_remote_get($api_url . '/getlatestpaytoken/' . $org_id, array(
                'headers' => array(
                    'accept' => 'application/json',
                    'X-API-KEY' => $api_key,
                    'X-APP-ID' => $app_id
                ),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                return array(
                    'valid' => false,
                    'error' => 'Network error: ' . $response->get_error_message()
                );
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($status_code < 200 || $status_code >= 300) {
                $error_msg = $body['error']['message'] ?? $body['message'] ?? 'API error';
                return array(
                    'valid' => false,
                    'error' => "API returned status $status_code: $error_msg"
                );
            }

            // The /getlatestpaytoken/ endpoint returns the latest paytoken directly
            // Check if response contains paytoken data
            $response_paytoken_id = $body['_id'] ?? $body['payToken'] ?? $body['id'] ?? null;

            if (empty($response_paytoken_id)) {
                return array(
                    'valid' => false,
                    'error' => 'No paytoken found for organization'
                );
            }

            // Verify the paytoken matches what we expect
            if ($response_paytoken_id !== $paytoken_id) {
                // The latest paytoken doesn't match - this could mean the user has a newer bank account linked
                // We'll still consider it valid if we got a paytoken back, as the stored one might be outdated
                $this->log('Paytoken mismatch warning', array(
                    'stored_paytoken' => $paytoken_id,
                    'latest_paytoken' => $response_paytoken_id,
                    'org_id' => $org_id
                ));
            }

            return array(
                'valid' => true,
                'error' => null,
                'latest_paytoken_id' => $response_paytoken_id
            );

        } catch (Exception $e) {
            return array(
                'valid' => false,
                'error' => 'Validation exception: ' . $e->getMessage()
            );
        }
    }

    /**
     * Get stored Monarch credentials for a user (supports both logged-in and guest users)
     * Returns array with org_id, paytoken_id, api_key, app_id
     */
    private function get_user_monarch_credentials($customer_id, $is_guest = false, $temporary = false) {
        $prefix = $temporary ? 'monarch_temp_' : 'monarch_';
        
        if ($is_guest) {
            // For guest users, get from session
            return array(
                'org_id' => WC()->session->get($prefix . 'org_id'),
                'paytoken_id' => WC()->session->get($prefix . 'paytoken_id'),
                'api_key' => WC()->session->get($prefix . 'org_api_key'),
                'app_id' => WC()->session->get($prefix . 'org_app_id'),
                'user_id' => WC()->session->get($prefix . 'user_id')
            );
        } else {
            // For logged-in users, get from user meta
            return array(
                'org_id' => get_user_meta($customer_id, '_' . $prefix . 'org_id', true),
                'paytoken_id' => get_user_meta($customer_id, '_' . $prefix . 'paytoken_id', true),
                'api_key' => get_user_meta($customer_id, '_' . $prefix . 'org_api_key', true),
                'app_id' => get_user_meta($customer_id, '_' . $prefix . 'org_app_id', true),
                'user_id' => get_user_meta($customer_id, '_' . $prefix . 'user_id', true)
            );
        }
    }

    /**
     * Recursively search for orgId in a nested array/response
     */
    private function find_org_id_recursive($data, $depth = 0) {
        if ($depth > 5 || !is_array($data)) {
            return null;
        }

        $org_id_keys = array('orgId', 'org_id', 'organizationId', 'organization_id');

        foreach ($org_id_keys as $key) {
            if (isset($data[$key]) && !empty($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $found = $this->find_org_id_recursive($value, $depth + 1);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }
}