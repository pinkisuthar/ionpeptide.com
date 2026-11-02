<?php

/**
 * WooCommerce Idem Payments Gateway (powered by EasyProcess)
 *
 * Provides access to the Idem Payments Gateway (powered by EasyProcess)
 *
 * @category    Payment Gateways
 * @author      EasyProcess
 * @package     WooCommerce Idem Payments Gateway
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Idem Gateway.
 *
 * @class    WC_Gateway_Idem
 * @version  1.10.0
 */
class WC_Gateway_Idem extends WC_Payment_Gateway
{

    public $id = 'idem';
    public string $version = '4.9.0';
    private array $available_currencies;
    private string $merchant_id;
    private string $sub_account;
    private string $merchant_key;
    private string $url;
    private string $response_url;
    private string $complete_status;
    private bool $send_debug_email;
    private bool $process_refund_ipn;
    protected string $instructions;
    protected bool $hide_for_non_admin_users;

    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {
        // Get cached checkout customization (pushed from EasyProcess)
        $checkout_config = $this->get_cached_woocommerce_config();

        // Set icon with fallback to default
        $default_icon = 'https://gateway.idem-club.com/files/idem-club/woo-checkout.png';
        $custom_icon = !empty($checkout_config['checkout_icon']) ? $checkout_config['checkout_icon'] : $default_icon;
        $this->icon = apply_filters('woocommerce_idem_gateway_icon', $custom_icon);
        $this->has_fields = false;

        $this->method_title = __('Idem Payment', 'woocommerce-gateway-idem');
        $this->method_description = __('The WooCommerce Plugin operates by directing the customer to the IDEM Pay platform, where they can purchase IDEM Tokens with their Debit or Credit Card. Afterward, the tokens are transferred to the merchant. IDEM Tokens will be automatically converted to FIAT currency by our partner, DCX Direct, which will handle the settlement with the merchant.', 'woocommerce-gateway-idem');

        // Setup available currency codes.
        $this->available_currencies = array('USD', 'GBP', 'EUR', 'CAD');

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Setup constants.
        if (! is_admin()) {
            $this->setup_constants();
        }

        $this->supports = array(
            //'pre-orders',
            'products',
            'subscriptions',
            'refunds',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            /*'subscription_date_changes',
			'multiple_subscriptions'*/
        );

        // Setup default merchant data.
        $this->merchant_id = $this->get_env_option('merchant_id');
        $this->sub_account = $this->get_env_option('sub_account');
        $this->merchant_key = $this->get_env_option('merchant_key');
        $this->complete_status = $this->get_option('complete_status');
        $this->process_refund_ipn = 'yes' === $this->get_option('process_refund_ipn');
        $this->url = 'https://gateway.idem-club.com/idem/';
        // Set title with fallback to default
        $default_title = 'Pay by card (using IDEM Pay)';
        $this->title = !empty($checkout_config['checkout_title']) ? $checkout_config['checkout_title'] : $default_title;
        // Set description with fallback to default
        $default_description = 'Pay securely using your Card, eCheck or preferred alternative payment method with IDEM and earn loyalty points.';
        $this->description = !empty($checkout_config['checkout_description']) ? $checkout_config['checkout_description'] : $default_description;
        // Dynamic title/description for trusted customers (local purchase history, zero latency)
        add_filter('woocommerce_gateway_title', array($this, 'maybe_use_trusted_title'), 10, 2);
        add_filter('woocommerce_gateway_description', array($this, 'maybe_use_trusted_description'), 10, 2);
        $this->instructions = $this->get_option('instructions', $this->description);
        $this->response_url = WC()->api_request_url('WC_Gateway_Idem');
        $this->send_debug_email = 'yes' === $this->get_option('send_debug_email');
        $this->hide_for_non_admin_users = $this->get_option('hide_for_non_admin_users');

        // Set up the test data, if in test mode.
        if ('yes' === $this->get_option('test_mode')) {
            $base_url = $this->get_option('test_url');
            if (empty($base_url))
                $base_url = 'https://gateway.idem-club.info';
            $this->url = $base_url . '/idem/';
            $this->add_test_mode_admin_settings_notice();
        } else {
            $base_url = $this->get_option('gateway_url');
            if (empty($base_url))
                $base_url = 'https://gateway.idem-club.com';
            $this->url = $base_url . '/idem/';
            $this->send_debug_email = false;
        }

        // Actions.
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_scheduled_subscription_payment_idem', array($this, 'process_subscription_payment'), 10, 2);
        //add_action

        // Subscription status change hooks for managing recurring payments
        add_action('woocommerce_subscription_status_cancelled', array($this, 'cancel_subscription_recurring_payment'), 10, 1);
        add_action('woocommerce_subscription_status_pending-cancel', array($this, 'cancel_subscription_recurring_payment'), 10, 1); // Bug #19 fix: Stop EP when customer initiates cancel
        add_action('woocommerce_subscription_status_on-hold', array($this, 'suspend_subscription_recurring_payment'), 10, 1);
        add_action('woocommerce_subscription_status_on-hold_to_active', array($this, 'reactivate_subscription_recurring_payment'), 10, 1);

        //add_action ( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array( $this, 'process_pre_order_release_payment' ), 10 );
        add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'check_api_request'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        // Add filter to allow on-hold status for subscription orders
        add_filter('woocommerce_payment_complete_order_status', array($this, 'maybe_override_order_status'), 10, 3);

        // Check if the base currency supports this gateway.
        if (! $this->is_valid_for_use()) {
            $this->enabled = false;
        }

        // Add is_available check for non-admin users
        add_filter('woocommerce_available_payment_gateways', array($this, 'maybe_hide_gateway_for_non_admin'));

        // Add admin notice for missing API credentials
        add_action('admin_notices', array($this, 'check_api_credentials'));
        add_action('admin_notices', array($this, 'check_version'));
        add_action('admin_notices', array($this, 'check_ipn_health'));

        // One-time migration of legacy flat credentials into the active-environment block
        add_action('admin_init', array($this, 'maybe_migrate_env_credentials'));

        // One-time migration of the legacy shared sub_account into the active-environment block
        add_action('admin_init', array($this, 'maybe_migrate_env_subaccount'));

        // Add payment verification hooks
        add_filter('woocommerce_cancel_unpaid_order', array($this, 'verify_before_cancel'), 10, 2);
        add_action('woocommerce_order_status_pending', array($this, 'schedule_payment_check'), 10, 1);
        add_action('wc_idem_verify_payment', array($this, 'verify_payment_status'), 10, 1);
        add_action('template_redirect', array($this, 'check_return_payment_status'));

        // Add meta box for IDEM transactions in order page
        add_action('add_meta_boxes', array($this, 'add_idem_transactions_meta_box'));

        // Add meta box for IDEM subscription actions
        add_action('add_meta_boxes', array($this, 'add_idem_subscription_actions_meta_box'));

        // AJAX handler for subscription actions
        add_action('wp_ajax_idem_subscription_action', array($this, 'idem_subscription_action_ajax_handler'));

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Add refund status change handler
        add_action('woocommerce_order_status_refunded', array($this, 'handle_manual_refund'), 10, 2);

        // AJAX handler for API connectivity/auth check
        add_action('wp_ajax_idem_check_api', array($this, 'idem_check_api_ajax_handler'));
        add_action('wp_ajax_idem_verify_notification', array($this, 'ajax_verify_notification'));

        // AJAX handler for one-click "Connect to EasyProcess" onboarding
        add_action('wp_ajax_wc_idem_connect', array($this, 'ajax_connect'));
        add_action('wp_ajax_wc_idem_disconnect', array($this, 'ajax_disconnect'));

        // AJAX handlers for the per-environment sub-account dropdown (list / create)
        add_action('wp_ajax_idem_list_subaccounts', array($this, 'idem_list_subaccounts_ajax_handler'));
        add_action('wp_ajax_idem_create_subaccount', array($this, 'idem_create_subaccount_ajax_handler'));

        // AJAX handlers for the per-environment customer-support editor (load / save)
        add_action('wp_ajax_idem_get_customer_support', array($this, 'idem_get_customer_support_ajax_handler'));
        add_action('wp_ajax_idem_save_customer_support', array($this, 'idem_save_customer_support_ajax_handler'));

        // AJAX handlers for auto-update functionality
        add_action('wp_ajax_idem_check_update_status', array($this, 'idem_check_update_status_ajax_handler'));
        add_action('wp_ajax_idem_manual_update', array($this, 'idem_manual_update_ajax_handler'));
        add_action('wp_ajax_idem_rollback', array($this, 'idem_rollback_ajax_handler'));

        // Note: WooCommerce checkout config is now pushed from EasyProcess admin via update_checkout_config API action
        // No cron sync needed - config is updated immediately when admin saves settings
    }

    /**
     * Read a credential for the ACTIVE environment (selected by test_mode).
     *
     * @param string $base one of: merchant_id, merchant_key, api_token, secret_key
     * @return string
     */
    private function get_env_option($base)
    {
        $env = ('yes' === $this->get_option('test_mode')) ? 'sandbox' : 'prod';
        $val = $this->get_option($env . '_' . $base);
        if ($val !== '' && $val !== null) {
            return $val;
        }
        // Legacy fallback ONLY until the one-time migration has copied the flat values into a block.
        // After migration the legacy value lives in exactly one environment's block, so continuing to
        // fall back here would bleed that environment's credentials into the other one.
        $migrated = ($base === 'sub_account')
            ? ('yes' === $this->get_option('subaccount_migrated_v46'))
            : ('yes' === $this->get_option('creds_migrated_v45'));
        if ($migrated) {
            return '';
        }
        $legacy = array(
            'merchant_id'  => 'merchant_id',
            'merchant_key' => 'merchant_key',
            'api_token'    => 'idem_api_key',
            'secret_key'   => 'idem_secret_key',
            'sub_account'  => 'sub_account',
        );
        return isset($legacy[$base]) ? $this->get_option($legacy[$base]) : '';
    }

    /**
     * One-time migration: copy legacy flat credentials into the block matching the
     * current test_mode, so existing installs keep working after the per-environment
     * split. The legacy values remain in the stored settings array (the form fields
     * were removed, not the saved data), so get_option() can still read them. Runs once.
     */
    public function maybe_migrate_env_credentials()
    {
        if ('yes' === $this->get_option('creds_migrated_v45')) {
            return;
        }
        $env = ('yes' === $this->get_option('test_mode')) ? 'sandbox' : 'prod';
        $map = array(
            'merchant_id'     => $env . '_merchant_id',
            'merchant_key'    => $env . '_merchant_key',
            'idem_api_key'    => $env . '_api_token',
            'idem_secret_key' => $env . '_secret_key',
        );
        $changed = false;
        foreach ($map as $legacy => $target) {
            $legacy_val = $this->get_option($legacy);
            if ($legacy_val !== '' && $legacy_val !== null && ($this->get_option($target) === '' || $this->get_option($target) === null)) {
                $this->update_option($target, $legacy_val);
                $changed = true;
            }
        }
        $this->update_option('creds_migrated_v45', 'yes');
        if ($changed && function_exists('wc_get_logger')) {
            wc_get_logger()->info('IDEM: migrated legacy credentials into ' . $env . ' block', array('source' => 'idem'));
        }
    }

    /**
     * One-time migration (v4.6): copy the legacy shared `sub_account` into the block matching the
     * current test_mode, since sub-account IDs are now per-environment. The other environment is
     * left empty (it gets its own ID when the merchant connects/creates one there). Runs once.
     */
    public function maybe_migrate_env_subaccount()
    {
        if ('yes' === $this->get_option('subaccount_migrated_v46')) {
            return;
        }
        $legacy = $this->get_option('sub_account');
        if ($legacy !== '' && $legacy !== null) {
            $env = ('yes' === $this->get_option('test_mode')) ? 'sandbox' : 'prod';
            if ($this->get_option($env . '_sub_account') === '' || $this->get_option($env . '_sub_account') === null) {
                $this->update_option($env . '_sub_account', $legacy);
                if (function_exists('wc_get_logger')) {
                    wc_get_logger()->info('IDEM: migrated legacy sub_account into ' . $env . ' block', array('source' => 'idem'));
                }
            }
        }
        $this->update_option('subaccount_migrated_v46', 'yes');
    }

    /**
     * Render the "Connect to EasyProcess" panel. WooCommerce calls generate_{type}_html()
     * for a field whose type is 'connect_panel'. Display-only: the inputs carry no `name`
     * attribute so they are never submitted with the settings form — the AJAX handler
     * (ajax_connect) reads them and the password is never persisted.
     *
     * @param string $key
     * @param array  $data
     * @return string
     */
    public function generate_connect_panel_html($key, $data)
    {
        $env             = (isset($data['env']) && $data['env'] === 'prod') ? 'prod' : 'sandbox';
        $env_label       = ($env === 'prod') ? __('Production', 'woocommerce-gateway-idem') : __('Sandbox', 'woocommerce-gateway-idem');
        $connected_email = $this->get_option($env . '_connected_email');
        $is_connected    = !empty($connected_email);
        ob_start(); ?>
        <tr valign="top" class="idem-connect-row" data-env="<?php echo esc_attr($env); ?>">
            <td colspan="2" class="forminp">
                <div class="idem-connect-card<?php echo $is_connected ? ' is-connected' : ''; ?>">
                    <div class="idem-connect-head">
                        <span class="dashicons dashicons-admin-links"></span>
                        <strong><?php echo esc_html($data['title']); ?></strong>
                        <?php if ($is_connected) : ?>
                            <span class="idem-connect-badge">&#10004; <?php printf(esc_html__('Connected as %s', 'woocommerce-gateway-idem'), esc_html($connected_email)); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($is_connected) : ?>
                        <p class="idem-connect-desc">
                            <?php printf(
                                esc_html__('This %s environment is linked to your EasyProcess account. Disconnect to link a different account or re-enter the login.', 'woocommerce-gateway-idem'),
                                '<strong>' . esc_html($env_label) . '</strong>'
                            ); ?>
                        </p>
                        <div class="idem-connect-actions">
                            <button type="button" class="button idem-disconnect-btn" data-env="<?php echo esc_attr($env); ?>"><?php esc_html_e('Disconnect', 'woocommerce-gateway-idem'); ?></button>
                            <span class="idem-connect-status" id="idem_connect_status_<?php echo esc_attr($env); ?>"></span>
                        </div>
                    <?php else : ?>
                        <p class="idem-connect-desc">
                            <?php printf(
                                esc_html__('Enter your %s EasyProcess account email and password to auto-fill this environment automatically.', 'woocommerce-gateway-idem'),
                                '<strong>' . esc_html($env_label) . '</strong>'
                            ); ?>
                        </p>
                        <div class="idem-connect-fields">
                            <input type="email" id="idem_connect_email_<?php echo esc_attr($env); ?>" placeholder="<?php esc_attr_e('EasyProcess email', 'woocommerce-gateway-idem'); ?>" autocomplete="off" />
                            <input type="password" id="idem_connect_password_<?php echo esc_attr($env); ?>" placeholder="<?php esc_attr_e('Password', 'woocommerce-gateway-idem'); ?>" autocomplete="new-password" />
                            <button type="button" class="button button-primary idem-connect-btn" data-env="<?php echo esc_attr($env); ?>" id="idem_connect_btn_<?php echo esc_attr($env); ?>"><?php esc_html_e('Connect', 'woocommerce-gateway-idem'); ?></button>
                            <span class="idem-connect-status" id="idem_connect_status_<?php echo esc_attr($env); ?>"></span>
                        </div>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the per-environment "Sub-account" field as a <select>. WooCommerce calls
     * generate_{type}_html() for a field whose type is 'subaccount_select'. The select is seeded
     * with the saved value only (so it round-trips even before JS runs); admin-subaccounts.js
     * populates the merchant's sub-accounts for this environment and wires Refresh / Create.
     *
     * @param string $key  field key (e.g. sandbox_sub_account)
     * @param array  $data field definition
     * @return string
     */
    public function generate_subaccount_select_html($key, $data)
    {
        $env       = (isset($data['env']) && $data['env'] === 'prod') ? 'prod' : 'sandbox';
        $field_key = $this->get_field_key($key);
        $saved     = $this->get_option($key);
        $data      = wp_parse_args($data, array('title' => '', 'description' => ''));

        // Rendered full-width (colspan=2) rather than the native label/th + control/td split: the
        // Connect card above is a colspan=2 row, and its width inflates the th column via the table's
        // auto-layout, pushing this control far to the right. A single full-width cell with a flex row
        // keeps the label, select and buttons aligned left, directly under the Connect card.
        ob_start(); ?>
        <tr valign="top" class="idem-subaccount-row" data-env="<?php echo esc_attr($env); ?>">
            <td colspan="2" class="forminp">
                <div class="idem-subaccount-field">
                    <label class="idem-subaccount-label" for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label>
                    <select name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" class="idem-subaccount-select" data-env="<?php echo esc_attr($env); ?>" data-saved="<?php echo esc_attr($saved); ?>">
                        <option value=""><?php esc_html_e('— None —', 'woocommerce-gateway-idem'); ?></option>
                        <?php if ($saved !== '' && $saved !== null) : ?>
                            <option value="<?php echo esc_attr($saved); ?>" selected="selected"><?php printf(esc_html__('Sub-account #%s', 'woocommerce-gateway-idem'), esc_html($saved)); ?></option>
                        <?php endif; ?>
                    </select>
                    <a href="#" class="idem-subaccount-refresh" data-env="<?php echo esc_attr($env); ?>"><?php esc_html_e('↻ Refresh', 'woocommerce-gateway-idem'); ?></a>
                    <button type="button" class="button idem-subaccount-create" data-env="<?php echo esc_attr($env); ?>"><?php esc_html_e('Create a sub-account for this store', 'woocommerce-gateway-idem'); ?></button>
                </div>
                <span class="idem-subaccount-status" id="idem_subaccount_status_<?php echo esc_attr($env); ?>"></span>
                <?php if (!empty($data['description'])) : ?>
                    <p class="description"><?php echo wp_kses_post($data['description']); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Sanitize the posted sub-account value (an integer sub_id, or empty for "none").
     *
     * @param string      $key
     * @param string|null $value
     * @return string
     */
    public function validate_subaccount_select_field($key, $value)
    {
        $value = is_null($value) ? '' : trim((string) $value);
        return ($value === '') ? '' : (string) absint($value);
    }

    /**
     * Render the per-environment "Customer Support" editor. WooCommerce calls generate_{type}_html()
     * for a field of type 'customer_support'. The inputs carry no `name` attribute so they are never
     * saved with the settings form — admin-support.js loads them from EasyProcess and the Update
     * button writes them back (POST /api/idem/customer-support). Email + message are required: the
     * IDEM gateway refuses to process a payment until both are set.
     *
     * @param string $key
     * @param array  $data field definition (expects 'env')
     * @return string
     */
    public function generate_customer_support_html($key, $data)
    {
        $env  = (isset($data['env']) && $data['env'] === 'prod') ? 'prod' : 'sandbox';
        $data = wp_parse_args($data, array('title' => __('Customer Support', 'woocommerce-gateway-idem')));

        ob_start(); ?>
        <tr valign="top" class="idem-customer-support-row" data-env="<?php echo esc_attr($env); ?>">
            <td colspan="2" class="forminp">
                <div class="idem-cs-head">
                    <strong><?php echo wp_kses_post($data['title']); ?></strong>
                    <span class="idem-cs-target" data-env="<?php echo esc_attr($env); ?>"></span>
                    <span class="idem-cs-required" data-env="<?php echo esc_attr($env); ?>"><?php esc_html_e('Required — the gateway will not process payments until a support email and message are set.', 'woocommerce-gateway-idem'); ?></span>
                </div>
                <div class="idem-cs-fields">
                    <label class="idem-cs-label" for="idem_cs_number_<?php echo esc_attr($env); ?>"><?php esc_html_e('Phone number', 'woocommerce-gateway-idem'); ?></label>
                    <input type="text" id="idem_cs_number_<?php echo esc_attr($env); ?>" class="idem-cs-number" data-env="<?php echo esc_attr($env); ?>" autocomplete="off" />

                    <label class="idem-cs-label" for="idem_cs_email_<?php echo esc_attr($env); ?>"><?php esc_html_e('Email', 'woocommerce-gateway-idem'); ?> <span class="idem-cs-star">*</span></label>
                    <input type="email" id="idem_cs_email_<?php echo esc_attr($env); ?>" class="idem-cs-email" data-env="<?php echo esc_attr($env); ?>" autocomplete="off" />

                    <label class="idem-cs-label" for="idem_cs_message_<?php echo esc_attr($env); ?>"><?php esc_html_e('Message to customer', 'woocommerce-gateway-idem'); ?> <span class="idem-cs-star idem-cs-message-star" data-env="<?php echo esc_attr($env); ?>">*</span></label>
                    <textarea id="idem_cs_message_<?php echo esc_attr($env); ?>" class="idem-cs-message" data-env="<?php echo esc_attr($env); ?>" rows="3"></textarea>
                </div>
                <div class="idem-cs-actions">
                    <button type="button" class="button button-primary idem-cs-save" data-env="<?php echo esc_attr($env); ?>"><?php esc_html_e('Update', 'woocommerce-gateway-idem'); ?></button>
                    <a href="#" class="idem-cs-refresh" data-env="<?php echo esc_attr($env); ?>"><?php esc_html_e('↻ Refresh', 'woocommerce-gateway-idem'); ?></a>
                    <span class="idem-cs-status" id="idem_cs_status_<?php echo esc_attr($env); ?>"></span>
                </div>
                <p class="description idem-cs-desc" data-env="<?php echo esc_attr($env); ?>"><?php esc_html_e('Shown to your customers on the IDEM payment page. Select a sub-account above to edit that store\'s support info; otherwise this is your account default. Saved to EasyProcess — no need to open the dashboard.', 'woocommerce-gateway-idem'); ?></p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a section separator: a horizontal rule + a sub-title. WooCommerce calls
     * generate_{type}_html() for a field of type 'section_break'. Mirrors the native `title` field
     * (closes the current form table and opens a new one) but adds an <hr> so the settings read as
     * clearly separated blocks.
     *
     * @param string $key
     * @param array  $data expects 'title', optional 'description'
     * @return string
     */
    public function generate_section_break_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $data = wp_parse_args($data, array('title' => '', 'description' => ''));
        ob_start(); ?>
        </table>
        <hr class="idem-section-hr" />
        <h3 class="wc-settings-sub-title" id="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></h3>
        <?php if (!empty($data['description'])) : ?>
            <p><?php echo wp_kses_post($data['description']); ?></p>
        <?php endif; ?>
        <table class="form-table">
        <?php
        return ob_get_clean();
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields()
    {

        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'woocommerce-gateway-idem'),
                'type'    => 'checkbox',
                'label'   => __('Enable Idem Payments', 'woocommerce-gateway-idem'),
                'default' => 'yes',
            ),
            'hide_for_non_admin_users' => array(
                'type'    => 'checkbox',
                'label'   => __('Hide at checkout for non-admin users', 'woocommerce-gateway-idem'),
                'default' => 'no',
            ),
            'test_mode' => array(
                'title'       => __('Mode', 'woocommerce-gateway-idem'),
                'type'        => 'select',
                'description' => __('Which environment processes payments: Sandbox for testing (no real charges) or Production for live payments. This also selects the active credential block below.', 'woocommerce-gateway-idem'),
                'default'     => 'no',
                'options'     => array(
                    'yes' => __('Sandbox (test)', 'woocommerce-gateway-idem'),
                    'no'  => __('Production (live)', 'woocommerce-gateway-idem'),
                ),
            ),
            // --- Sandbox environment (grouped into a tab by admin JS) ---
            'sandbox_creds_title' => array(
                'title' => __('Sandbox', 'woocommerce-gateway-idem'),
                'type'  => 'title',
            ),
            'sandbox_connect_panel' => array(
                'title' => __('Connect to EasyProcess', 'woocommerce-gateway-idem'),
                'type'  => 'connect_panel',
                'env'   => 'sandbox',
            ),
            'sandbox_sub_account' => array(
                'title'       => __('Sub-account', 'woocommerce-gateway-idem'),
                'type'        => 'subaccount_select',
                'env'         => 'sandbox',
                'description' => __('Optional. Tag this store\'s transactions with a Sandbox sub-account. Sub-account IDs differ between Sandbox and Production, so each environment has its own.', 'woocommerce-gateway-idem'),
                'default'     => '',
            ),
            'sandbox_customer_support' => array(
                'title' => __('Customer Support', 'woocommerce-gateway-idem'),
                'type'  => 'customer_support',
                'env'   => 'sandbox',
            ),
            'test_url' => array(
                'title'       => __('Sandbox Gateway URL', 'woocommerce-gateway-idem'),
                'type'        => 'text',
                'description' => __('Default: https://gateway.idem-club.info — do not change unless requested by EasyProcess.', 'woocommerce-gateway-idem'),
                'default'     => 'https://gateway.idem-club.info'
            ),
            'sandbox_merchant_id' => array(
                'title'   => __('Merchant ID', 'woocommerce-gateway-idem'),
                'type'    => 'text',
                'default' => ''
            ),
            'sandbox_merchant_key' => array(
                'title'   => __('Encryption / Hash Key', 'woocommerce-gateway-idem'),
                'type'    => 'text',
                'default' => ''
            ),
            'sandbox_api_token' => array(
                'title'   => __('IDEM API Token', 'woocommerce-gateway-idem'),
                'type'    => 'text',
                'default' => ''
            ),
            'sandbox_secret_key' => array(
                'title'   => __('IDEM Secret Key', 'woocommerce-gateway-idem'),
                'type'    => 'text',
                'default' => ''
            ),
            // --- Production environment (grouped into a tab by admin JS) ---
            'prod_creds_title' => array(
                'title' => __('Production', 'woocommerce-gateway-idem'),
                'type'  => 'title',
            ),
            'prod_connect_panel' => array(
                'title' => __('Connect to EasyProcess', 'woocommerce-gateway-idem'),
                'type'  => 'connect_panel',
                'env'   => 'prod',
            ),
            'prod_sub_account' => array(
                'title'       => __('Sub-account', 'woocommerce-gateway-idem'),
                'type'        => 'subaccount_select',
                'env'         => 'prod',
                'description' => __('Optional. Tag this store\'s transactions with a Production sub-account. Sub-account IDs differ between Sandbox and Production, so each environment has its own.', 'woocommerce-gateway-idem'),
                'default'     => '',
            ),
            'prod_customer_support' => array(
                'title' => __('Customer Support', 'woocommerce-gateway-idem'),
                'type'  => 'customer_support',
                'env'   => 'prod',
            ),
            'gateway_url' => array(
                'title'       => __('Production Gateway URL', 'woocommerce-gateway-idem'),
                'type'        => 'text',
                'description' => __('Default: https://gateway.idem-club.com — do not change unless requested by EasyProcess.', 'woocommerce-gateway-idem'),
                'default'     => 'https://gateway.idem-club.com'
            ),
            'prod_merchant_id' => array(
                'title'   => __('Merchant ID', 'woocommerce-gateway-idem'),
                'type'    => 'text',
                'default' => ''
            ),
            'prod_merchant_key' => array(
                'title'   => __('Encryption / Hash Key', 'woocommerce-gateway-idem'),
                'type'    => 'text',
                'default' => ''
            ),
            'prod_api_token' => array(
                'title'   => __('IDEM API Token', 'woocommerce-gateway-idem'),
                'type'    => 'text',
                'default' => ''
            ),
            'prod_secret_key' => array(
                'title'   => __('IDEM Secret Key', 'woocommerce-gateway-idem'),
                'type'    => 'text',
                'default' => ''
            ),
            // Closes the Production tab group so the fields below render outside the tabs.
            'order_settings_title' => array(
                'title' => __('Order &amp; Notification Settings', 'woocommerce-gateway-idem'),
                'type'  => 'title',
            ),
            'complete_status' => array(
                'title'       => __('Order status after payment', 'woocommerce-gateway-idem'),
                'type'        => 'select',
                'description' => __('Choose your desired order status after a successful payment.', 'woocommerce-gateway-idem'),
                'default'     => 'processing',
                'options'     => [
                    'processing' => __('Processing (recommended)', 'woocommerce-gateway-idem'),
                    'on-hold' => __('On-hold', 'woocommerce-gateway-idem'),
                ]
            ),
            'send_debug_email' => array(
                'title'   => __('Send an alert on IPN processing error?', 'woocommerce-gateway-idem'),
                'type'    => 'checkbox',
                'label'   => __('Send an alert to the provided e-mail address if the IPN fails to be processed.', 'woocommerce-gateway-idem'),
                'default' => 'yes'
            ),
            'debug_email' => array(
                'title'       => __('Who receives the IPN alert?', 'woocommerce-gateway-idem'),
                'type'        => 'text',
                'description' => __('The e-mail address to which the IPN error alert is sent.', 'woocommerce-gateway-idem'),
                'default'     => get_option('admin_email')
            ),
            'process_refund_ipn' => array(
                'title'   => __('Process refund automatically?', 'woocommerce-gateway-idem'),
                'type'    => 'checkbox',
                'label'   => __('Automatically set the order as refunded when a refund IPN is received.', 'woocommerce-gateway-idem'),
                'default' => 'no'
            ),
            'refund_with_api' => array(
                'title'   => __('Refund with API?', 'woocommerce-gateway-idem'),
                'type'    => 'checkbox',
                'label'   => __('Refund the transaction with the API when the order is refunded in WooCommerce (requires IDEM API key and Secret Key).', 'woocommerce-gateway-idem'),
                'default' => 'no'
            ),
            'checkout_appearance_title' => array(
                'title' => __('Checkout appearance', 'woocommerce-gateway-idem'),
                'type'  => 'section_break',
            ),
            'checkout_icon_height' => array(
                'title'             => __('Logo height', 'woocommerce-gateway-idem'),
                'type'              => 'number',
                'description'       => __('Height, in pixels, of the payment method logo shown at checkout. Leave empty to let your theme decide. The width follows automatically.', 'woocommerce-gateway-idem'),
                'placeholder'       => __('Theme default', 'woocommerce-gateway-idem'),
                'default'           => '',
                'css'               => 'width:90px;',
                'custom_attributes' => array('min' => 0, 'max' => 300, 'step' => 1),
            ),
            'plugin_settings_title' => array(
                'title' => __('Plugin Settings', 'woocommerce-gateway-idem'),
                'type'  => 'section_break',
            ),
            'verbose_logging' => array(
                'title'       => __('Verbose logging', 'woocommerce-gateway-idem'),
                'type'        => 'checkbox',
                'description' => __('Enable verbose logging (mainly for debugging purposes, in case of transaction issues).', 'woocommerce-gateway-idem'),
                'default'     => 'no'
            ),
            'auto_update_enabled' => array(
                'title'       => __('Auto-Update', 'woocommerce-gateway-idem'),
                'type'        => 'checkbox',
                'label'       => __('Enable automatic plugin updates', 'woocommerce-gateway-idem'),
                'description' => __('Automatically check for and install plugin updates every 6 hours. Updates are installed safely with automatic backups.', 'woocommerce-gateway-idem'),
                'default'     => 'yes'
            ),
            'security_title' => array(
                'title' => __('Security', 'woocommerce-gateway-idem'),
                'type'  => 'section_break',
            ),
            'validate_ipn_ip' => array(
                'title'       => __('Validate IPN IP', 'woocommerce-gateway-idem'),
                'type'        => 'checkbox',
                'label'       => __('Validate that IPN requests come from IDEM servers', 'woocommerce-gateway-idem'),
                'description' => __('Verify that IPN notifications come from authorized IDEM IP addresses. <span style="color: red;"><b>Off by default: stores behind Cloudflare or another proxy/CDN receive IPNs from a proxy IP, which fails this check. Enable it only if your store is not behind a proxy/CDN.</b></span>', 'woocommerce-gateway-idem'),
                'default'     => 'no'
            ),
            'validate_ipn_signature' => array(
                'title'       => __('Validate IPN Signature', 'woocommerce-gateway-idem'),
                'type'        => 'checkbox',
                'label'       => __('Validate the signature of IPN notifications', 'woocommerce-gateway-idem'),
                'description' => __('Enable this to verify the authenticity of IPN notifications using a hash signature. <span style="color: red;"><b>Disable only if you experience issues with IPN processing</b></span>.', 'woocommerce-gateway-idem'),
                'default'     => 'yes'
            ),
            'ipn_test' => array(
                'title' => __('Notification (IPN) reachability', 'woocommerce-gateway-idem'),
                'type'  => 'ipn_test_panel',
            ),
        );
    }

    /**
     * Render the "Test notification reachability" button. WooCommerce calls generate_{type}_html()
     * for a field whose type is 'ipn_test_panel'. The button asks the platform to probe this store's
     * IPN URL from our servers (see ajax_verify_notification) so the merchant can catch a firewall/WAF
     * blocking our inbound notifications before it silently piles up "pending" orders.
     *
     * @param string $key
     * @param array  $data
     * @return string
     */
    public function generate_ipn_test_panel_html($key, $data)
    {
        ob_start(); ?>
        <tr valign="top" class="ep-ipn-test-row">
            <th scope="row" class="titledesc"><?php echo esc_html($data['title']); ?></th>
            <td class="forminp">
                <button type="button" class="button ep-test-ipn"><?php esc_html_e('Test notification reachability', 'woocommerce-gateway-idem'); ?></button>
                <span class="ep-ipn-result" style="margin-left:8px;"></span>
                <p class="description"><?php esc_html_e('Checks whether our servers can reach your store to deliver payment notifications (IPN). If a firewall or CDN (e.g. Cloudflare) blocks us, paid orders stay "pending". Save your settings first.', 'woocommerce-gateway-idem'); ?></p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * add_test_mode_admin_settings_notice()
     * Add a notice to the merchant_key and merchant_id fields when in test mode.
     *
     * @since 1.1.0
     */
    public function add_test_mode_admin_settings_notice(): void
    {
        $this->form_fields['test_mode']['description']  .= '<br/><span style="color: red; font-weight: bold;">' . __('Test mode is currently enabled: no real transactions will be processed!', 'woocommerce-gateway-idem') . '.</span>';
        $this->form_fields['sandbox_merchant_id']['description']  = ($this->form_fields['sandbox_merchant_id']['description'] ?? '') . '<span style="color: red; font-weight: bold;">' . __('Sandbox merchant ID currently in use', 'woocommerce-gateway-idem') . ' (' . esc_html($this->merchant_id) . ').</span>';
        $this->form_fields['sandbox_merchant_key']['description'] = ($this->form_fields['sandbox_merchant_key']['description'] ?? '') . '<span style="color: red; font-weight: bold;">' . __('Sandbox encryption key currently in use', 'woocommerce-gateway-idem') . ' (' . esc_html($this->merchant_key) . ').</span>';
    }

    /**
     * is_valid_for_use()
     *
     * Check if this gateway is enabled and available in the base currency being traded with.
     *
     * @since 1.1.0
     */
    public function is_valid_for_use(): bool
    {
        $is_available          = false;
        $is_available_currency = in_array(get_woocommerce_currency(), $this->available_currencies);

        if ($is_available_currency && $this->merchant_id && $this->merchant_key) {
            $is_available = true;
        }
        return $is_available;
    }

    /**
     * Override is_available to handle crypto_only_mode and non-admin hiding.
     * This is called by both classic checkout (via filter) and Blocks checkout (via is_active).
     */
    public function is_available(): bool
    {
        if (!parent::is_available()) {
            return false;
        }

        // Hide for non-admin users if configured
        if ('yes' === $this->get_option('hide_for_non_admin_users') && !current_user_can('manage_options')) {
            return false;
        }

        // Hide main gateway when crypto_only_mode is forced
        $config = $this->get_cached_woocommerce_config();
        if (isset($config['crypto_only_mode']) && $config['crypto_only_mode'] === 'forced') {
            return false;
        }

        return true;
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     *
     * @since 1.1.0
     */
    public function admin_options(): void
    {
?>
        <h2>
            <?php _e('IDEM Payments Gateway', 'woocommerce-gateway-idem'); ?>
            <?php
            if (function_exists('wc_back_link')) {
                wc_back_link(__('Return to payments', 'woocommerce-gateway-idem'), admin_url('admin.php?page=wc-settings&tab=checkout'));
            }
            ?>
        </h2>
        <?php

        if (is_plugin_active('woocommerce-gateway-easyprocess-idem/gateway-easyprocess-idem.php')) {
        ?>
            <div class="inline error">
                <p>
                    <strong><?php _e('Warning', 'woocommerce-gateway-idem'); ?></strong> <?php _e('The previous version of this plugin, "woocommerce-gateway-easyprocess-idem", is currently activated. This may lead to conflicts with the latest version. Please uninstall the previous version and verify or update the notification URL in your merchant account, as outlined below.', 'woocommerce-gateway-idem'); ?>
                </p>
            </div>
        <?php
        }

        if (in_array(get_woocommerce_currency(), $this->available_currencies)) {
            // Connect (per environment) sets the notification_url on the platform automatically, so
            // only show the notification-URL reminder when the ACTIVE environment was not connected
            // that way — i.e. when there is genuine doubt the URL is configured.
            $active_env    = ('yes' === $this->get_option('test_mode')) ? 'sandbox' : 'prod';
            $env_connected = !empty($this->get_option($active_env . '_connected_email'));
            if (! $env_connected) :
        ?>
            <div class="inline notice">
                <p>
                    <strong><?php _e('One step left: your notification URL must be set so paid orders are completed automatically.', 'woocommerce-gateway-idem'); ?></strong><br/>
                    <?php _e('Easiest way: use the <strong>Connect to EasyProcess</strong> panel below — it configures the notification URL for you automatically.', 'woocommerce-gateway-idem'); ?><br/>
                    <?php /** @noinspection HtmlUnknownTarget */
                    printf(__('Prefer to do it by hand? Open your merchant account <a href="%1$s" target="_blank" rel="noopener noreferrer">here</a> and set the notification URL to:', 'woocommerce-gateway-idem'), 'https://easyprocess.ai/merchants/'); ?>
                    <span style="color: red"><pre><code><?php echo esc_html($this->response_url); ?></code></pre></span>
                </p>
            </div>
        <?php
            endif;
            parent::admin_options();
        } else {
            ?>
            <div class="inline error">
                <p><strong><?php _e('Gateway Disabled', 'woocommerce-gateway-idem'); ?></strong> <?php echo sprintf(__('Choose USD, GBP, EUR or CAD as your store currency in %1$sPricing Options%2$s to enable the IDEM Pay Gateway.', 'woocommerce-gateway-idem'), '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=general')) . '">', '</a>'); ?></p>
            </div>
        <?php
        }
    }

    /**
     * Generate the EasyProcess button link.
     *
     * @since 1.1.0
     */
    public function generate_easyprocess_form($order_id): string
    {
        $order = wc_get_order($order_id);

        // Construct variables for post
        $data_to_send = array(
            // Merchant details
            'merchant_id'   => $this->merchant_id,
            'sub_account_id' => $this->sub_account,
            'completed_url' => $this->get_return_url($order),
            'cancelled_url' => $order->get_cancel_order_url(),
            'rejected_url'  => $order->get_cancel_order_url(),

            // Version information
            'platform' => array(
                'name' => 'WordPress',
                'wp_version' => get_bloginfo('version'),
                'wc_version' => WC()->version,
                'plugin_version' => $this->version,
            ),

            // Billing details
            'user' => array(
                'firstname' => $order->get_billing_first_name() ?? $order->get_shipping_first_name(),
                'lastname'  => $order->get_billing_last_name() ?? $order->get_shipping_last_name(),
                'address1'  => $order->get_billing_address_1() ?? $order->get_shipping_address_1(),
                'address2'  => $order->get_billing_address_2() ?? $order->get_shipping_address_2(),
                'zipcode'   => $order->get_billing_postcode() ?? $order->get_shipping_postcode(),
                'city'      => $order->get_billing_city() ?? $order->get_shipping_city(),
                'country'   => $order->get_billing_country() ?? $order->get_shipping_country(),
                'state'     => $order->get_billing_state() ?? $order->get_shipping_state(),
                'phone'     => $order->get_billing_phone() ?? $order->get_shipping_phone(),
                'email'     => $order->get_billing_email(),
            ),

            // Item details
            'order_ref'         => $order->get_id(),
            'amount'            => $order->get_total(),
            'currency'          => $order->get_currency(),
            'order_description' => sprintf(__('Order %s on %s', 'woocommerce-gateway-idem'), $order->get_order_number(), get_bloginfo('name')),
            'addinfo'           => $order->get_order_key(),
        );

        // Calculate the hash
        $data_to_send['hash'] = md5($this->merchant_id . $data_to_send['amount'] . $data_to_send['currency'] . $data_to_send['order_ref'] . $this->merchant_key);

        // Record this signed attempt: the IPN handler must authenticate the paid
        // amount against a value WE signed, never against a live order total that
        // other plugins can change after the redirect. Keep the last few attempts
        // (multi-tab, re-signed totals): the IPN matches the attempt that was
        // actually submitted, whichever tab it came from.
        $signed_attempts = $order->get_meta('_idem_signed_attempts');
        if (!is_array($signed_attempts)) {
            $signed_attempts = array();
        }
        $signed_attempts[] = array(
            'amount'   => (string) $data_to_send['amount'],
            'currency' => (string) $data_to_send['currency'],
            'time'     => time(),
        );
        $signed_attempts = array_slice($signed_attempts, -5); // bound growth
        $order->update_meta_data('_idem_signed_attempts', $signed_attempts);

        // Snapshot the order lines behind each signed amount (same bound). On an
        // order-total-drift, the diff between this snapshot and the current lines
        // (get_order with_lines=1) says WHICH line moved, without asking the merchant
        // for a screenshot. Diagnostic only: never read by the IPN decision.
        $signed_lines = $order->get_meta('_idem_signed_lines');
        if (!is_array($signed_lines)) {
            $signed_lines = array();
        }
        $signed_lines[] = array_merge(end($signed_attempts), $this->get_order_lines_snapshot($order));
        $signed_lines = array_slice($signed_lines, -5);
        $order->update_meta_data('_idem_signed_lines', $signed_lines);
        $order->save();

        /**
         * Check for subscription in the cart
         * Note: we can have only ONE subscription per order (checked in woocommerce_add_to_cart_validation)
         * @since 2.0.0
         */
        $periodicity_map = [
            'day' => 'days',
            'week' => 'weeks',
            'month' => 'months',
            'year' => 'years',
        ];
        // CRITICAL: Only send recurring data for INITIAL subscription orders, NOT renewal orders.
        // Renewal orders already have an EP subscription - sending recurring data would create a duplicate.
        // Bug discovered 2025-12-16: Customer retrying a failed renewal order through the gateway
        // caused a new EP subscription to be created instead of using the existing one.
        $is_renewal_order = function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order);

        if ($is_renewal_order) {
            $this->log('Order #' . $order->get_id() . ' is a renewal order - skipping recurring data (EP subscription already exists)');
        } else {
            $order_items = $order->get_items();
            foreach ($order_items as $order_item) {
                // Check if the order item is a product line item
                if ($order_item instanceof WC_Order_Item_Product) {
                    $product = $order_item->get_product();
                    if ($product && in_array($product->get_type(), ['subscription', 'variable-subscription', 'subscription_variation'])) {
                    // Get subscription interval from order meta (more reliable for IDEM since it doesn't support multiple products)
                    // First try order meta, then fall back to product meta
                    $subscription_interval = (int) $order->get_meta('_billing_interval', true);

                    // If not found in order meta, try product meta with correct field name
                    if (! $subscription_interval || $subscription_interval <= 0) {
                        $subscription_interval = (int) $product->get_meta('_subscription_period_interval', true);
                    }

                    // Default to 1 if still not found
                    $subscription_interval = $subscription_interval > 0 ? $subscription_interval : 1;

                    // Get subscription length from product meta
                    $subscription_length = (int) $product->get_meta('_subscription_length', true);

                    // Calculate the correct number of payment cycles
                    // For lifetime/perpetual subscriptions (length=0), cycles are 0 (unlimited).
                    // Otherwise, calculate cycles by dividing length by interval.
                    if ($subscription_length > 0 && $subscription_interval > 0) {
                        $cycles = (int)($subscription_length / $subscription_interval) - 1;
                    } else {
                        // length=0 means perpetual/unlimited subscription, cycles=0 signals this to the platform
                        $cycles = 0;
                    }

                    // Always send recurring data for subscription products
                    // cycles=0 means perpetual/unlimited subscription (valid in WooCommerce Subscriptions)
                    $data_to_send['recurring'] = [
                        'enabled' => 'yes',
                        'amount'  => $product->get_meta('_subscription_price'),
                        'periodicity'  => $periodicity_map[$product->get_meta('_subscription_period')],
                        'cycles'  => $cycles,
                        'interval' => $subscription_interval,
                    ];
                    // Calculate the start date
                    $ts = wcs_add_time($subscription_interval, $product->get_meta('_subscription_period'), time());
                    $data_to_send['recurring']['start_date'] = date('Y-m-d', $ts);
                    // Hash calculation with security consideration for interval tampering
                    // For backward compatibility and security:
                    // - If interval is 1 (default): use old hash format (amount + cycles + periodicity + start_date + key)
                    // - If interval > 1: use new hash format that includes interval to prevent tampering
                    $hash_string = $data_to_send['recurring']['amount'] . $data_to_send['recurring']['cycles'] . $data_to_send['recurring']['periodicity'] . $data_to_send['recurring']['start_date'];
                    if ($subscription_interval > 1) {
                        $hash_string .= $data_to_send['recurring']['interval'];
                    }
                    $data_to_send['recurring']['hash'] = md5($hash_string . $this->merchant_key);
                }
            }
        }
        } // End of !$is_renewal_order check

        $easyprocess_args_array = array();

        $this->log(json_encode($data_to_send), true);

        foreach ($data_to_send as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v)
                    $easyprocess_args_array[] = '<input type="hidden" name="' . $key . '[' . $k . ']" value="' . esc_attr($v) . '" />';
            } else {
                $easyprocess_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }
        }

        return '<form action="' . esc_url($this->url) . '" method="post" id="easyprocess_payment_form">
				' . implode('', $easyprocess_args_array) . '
				<input type="submit" class="button-alt" id="submit_easyprocess_payment_form" value="' . __('Pay via IDEM', 'woocommerce-gateway-idem') . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Cancel order &amp; restore cart', 'woocommerce-gateway-idem') . '</a>
				<script type="text/javascript">
					jQuery(function(){
						jQuery("body").block(
							{
								message: "' . __('Please wait, you will be redirected to the IDEM gateway in order to make your payment.', 'woocommerce-gateway-idem') . '",
								overlayCSS:
								{
									background: "#fff",
									opacity: 0.6
								},
								css: {
							        padding:        20,
							        textAlign:      "center",
							        color:          "#555",
							        border:         "3px solid #aaa",
							        backgroundColor:"#fff",
							        cursor:         "wait"
							    }
							});
						jQuery( "#submit_easyprocess_payment_form" ).click();
					});
				</script>
			</form>';
    }

    /**
     * Process the payment and return the result.
     *
     * @param  int  $order_id
     * @return array
     */
    public function process_payment($order_id)
    {

        $order = wc_get_order($order_id);
        $order->update_status('pending-payment', __('Awaiting payment', 'woocommerce'));
        return array(
            'result'      => 'success',
            'redirect'     => $order->get_checkout_payment_url(true)
        );
    }


    /**
     * Reciept page.
     *
     * Display text and a button to direct the user to EasyProcess.
     *
     * @since 1.1.0
     */
    public function receipt_page($order): void
    {
        echo '<p>' . __('Thank you for your order, please click the button below to pay with Idem.', 'woocommerce-gateway-idem') . '</p>';
        echo $this->generate_easyprocess_form($order);
    }

    /**
     * Check EasyProcess IPN response.
     */
    public function check_api_request(): void
    {
        // Send headers early to ensure they are set
        header('Content-Type: application/json');
        $request = stripslashes_deep($_POST);

        if (!empty($request)) {
            if (!empty($request['action'])) {
                $response = $this->handle_api_request($request);
            } else if (!empty($request)) {
                $response = $this->handle_ipn_request($request);
            } else {
                $response = [
                    'status' => 'error',
                    'message' => 'Invalid action',
                ];
            }
        } else {
            $response = [
                'status' => 'error',
                'message' => 'No data received',
                'platform' => array(
                    'wp_version' => get_bloginfo('version'),
                    'wc_version' => WC()->version,
                    'plugin_version' => $this->version,
                ),
            ];
        }

        echo wp_json_encode($response);
        exit;
    }

    /**
     * Check EasyProcess API request validity.
     *
     * @param array $data
     * @return array Response data
     */
    public function handle_api_request(array $data): array
    {

        if (empty($data['merchant_key']) || $data['merchant_key'] !== $this->merchant_key || empty($data['merchant_id']) || $data['merchant_id'] !== $this->merchant_id) {
            return [
                'status' => 'error',
                'message' => 'Invalid merchant credentials',
            ];
        }

        $action = $data['action'] ?? '';
        $subscription_id = isset($data['subscription_id']) ? absint($data['subscription_id']) : 0;

        try {
            switch ($action) {
                case 'get_settings':
                    $response = ['status' => 'ok', 'settings' => $this->get_settings()];
                    break;

                case 'force_update':
                    // Remote-trigger the auto-updater (bypasses the merchant's dead WP-Cron), running
                    // the same path as the admin "Manual Update" button. Synchronous: download +
                    // backup + install + verify all run before the response returns. force=1 reinstalls
                    // even at an equal version (republished hotfix) and overrides the auto-update
                    // toggle. Returns { status: 'updated'|'up_to_date'|'failed', from, to, message }.
                    if (!class_exists('WC_IDEM_Auto_Updater')) {
                        require_once plugin_dir_path(__FILE__) . 'class-wc-idem-auto-updater.php';
                    }
                    $updater  = new WC_IDEM_Auto_Updater($this);
                    $response = $updater->check_and_update(!empty($data['force']));
                    break;

                case 'get_logs':
                    $response = ['status' => 'ok', 'logs' => $this->get_logs()];
                    break;

                case 'get_order':
                    $order_id = isset($data['order_id']) ? absint($data['order_id']) : 0;
                    if (empty($order_id)) return ['status' => 'error', 'message' => 'Missing order_id'];
                    $with_lines = isset($data['with_lines']) && filter_var($data['with_lines'], FILTER_VALIDATE_BOOLEAN);
                    $order_data = $this->get_order($order_id, $with_lines);
                    if (is_wp_error($order_data)) {
                        return ['status' => 'error', 'message' => $order_data->get_error_message()];
                    }
                    $response = ['status' => 'ok', 'order' => $order_data];
                    break;


                case 'update_subscription_status':
                    if (empty($subscription_id)) return ['status' => 'error', 'message' => 'Missing subscription_id'];
                    $new_status = $data['new_status'] ?? '';
                    if (empty($new_status)) return ['status' => 'error', 'message' => 'Missing new_status'];
                    if (!function_exists('wcs_get_subscription')) return ['status' => 'error', 'message' => 'WooCommerce Subscriptions not active'];
                    $subscription = wcs_get_subscription($subscription_id);
                    if (!$subscription) return ['status' => 'error', 'message' => 'Subscription not found'];
                    
                    $current_status = $subscription->get_status();
                    
                    // Check if this is a cancelled/on-hold to active transition that needs special handling
                    if ($new_status === 'active' && ($current_status === 'cancelled' || 
                        ($current_status === 'on-hold' && $subscription->get_time('end') != 0 && $subscription->get_time('end') < current_time('timestamp', true)))) {
                        
                        $this->log('API: Attempting to reactivate cancelled/previously-cancelled subscription via database workaround', 'info');
                        
                        // Step 1: Update the subscription status directly in the database to on-hold
                        global $wpdb;
                        $wpdb->update(
                            $wpdb->posts,
                            array('post_status' => 'wc-on-hold'),
                            array('ID' => $subscription->get_id()),
                            array('%s'),
                            array('%d')
                        );
                        
                        // Step 2: Clear the end date and cancelled date metadata
                        delete_post_meta($subscription->get_id(), '_schedule_end');
                        delete_post_meta($subscription->get_id(), '_schedule_cancelled');
                        
                        // Step 3: Clear all caches
                        wp_cache_delete($subscription->get_id(), 'posts');
                        wp_cache_delete($subscription->get_id(), 'post_meta');
                        clean_post_cache($subscription->get_id());
                        
                        // Step 4: Reload the subscription object
                        $subscription = wcs_get_subscription($subscription_id);
                        
                        // Add a note about the database workaround
                        $subscription->add_order_note('Subscription status changed from ' . $current_status . ' to on-hold via API (database workaround) to enable reactivation.');
                        
                        // Step 5: Now update from on-hold to active using the proper method
                        // IMPORTANT: Temporarily remove hooks to prevent infinite loop with IDEM API
                        remove_action('woocommerce_subscription_status_on-hold_to_active', array($this, 'reactivate_subscription_recurring_payment'), 10);
                        
                        $subscription->update_status('active', 'Status updated by platform API script after database workaround.');
                        
                        // Re-add the hook for normal operations
                        add_action('woocommerce_subscription_status_on-hold_to_active', array($this, 'reactivate_subscription_recurring_payment'), 10, 1);
                        
                    } else {
                        // Normal status update for all other transitions
                        // Temporarily disable all subscription status hooks to prevent API calls back to IDEM
                        remove_action('woocommerce_subscription_status_cancelled', array($this, 'cancel_subscription_recurring_payment'), 10);
                        remove_action('woocommerce_subscription_status_pending-cancel', array($this, 'cancel_subscription_recurring_payment'), 10);
                        remove_action('woocommerce_subscription_status_on-hold', array($this, 'suspend_subscription_recurring_payment'), 10);
                        remove_action('woocommerce_subscription_status_on-hold_to_active', array($this, 'reactivate_subscription_recurring_payment'), 10);

                        $subscription->update_status($new_status, 'Status updated by platform API script.');

                        // Re-add hooks for normal operations
                        add_action('woocommerce_subscription_status_cancelled', array($this, 'cancel_subscription_recurring_payment'), 10, 1);
                        add_action('woocommerce_subscription_status_pending-cancel', array($this, 'cancel_subscription_recurring_payment'), 10, 1);
                        add_action('woocommerce_subscription_status_on-hold', array($this, 'suspend_subscription_recurring_payment'), 10, 1);
                        add_action('woocommerce_subscription_status_on-hold_to_active', array($this, 'reactivate_subscription_recurring_payment'), 10, 1);
                    }
                    
                    $response = ['status' => 'ok', 'message' => 'Subscription status updated successfully.'];
                    break;
                
                case 'update_subscription_amount':
                    $response = $this->handle_update_subscription_amount($data, $subscription_id);
                    break;

                case 'update_subscription_schedule':
                    $response = $this->handle_update_subscription_schedule($data, $subscription_id);
                    break;

                case 'get_customer_details_by_email':
                    $email = $data['email'] ?? '';
                    if (empty($email) || !is_email($email)) return ['status' => 'error', 'message' => 'Missing or invalid email'];
                    
                    $user = get_user_by('email', $email);
                    if (!$user) {
                        // Search in orders if no user account exists
                        $orders_by_email = wc_get_orders(['customer' => $email, 'limit' => 1]);
                        if (empty($orders_by_email)) {
                            return ['status' => 'error', 'message' => 'No user or orders found for this email.'];
                        }
                        $customer_id = 0; // Guest
                    } else {
                        $customer_id = $user->ID;
                    }

                    $customer_orders = wc_get_orders([
                        'customer' => $customer_id ? $customer_id : $email,
                        'limit' => -1,
                        'status' => ['wc-processing', 'wc-completed'],
                    ]);
                    // Registered customers may also have guest orders placed with the
                    // same email before the account existed — include those too.
                    if ($customer_id && empty($customer_orders)) {
                        $customer_orders = wc_get_orders([
                            'customer' => $email,
                            'limit' => -1,
                            'status' => ['wc-processing', 'wc-completed'],
                        ]);
                    }

                    $order_history = [];
                    foreach ($customer_orders as $order) {
                        $order_history[] = [
                            'order_id' => $order->get_id(),
                            'date' => $order->get_date_created()->format('c'),
                            'status' => $order->get_status(),
                            'total' => $order->get_total(),
                            'payment_method' => $order->get_payment_method_title(),
                        ];
                    }
                    
                    $customer_data = [
                        'id' => $customer_id,
                        'email' => $email,
                        'first_name' => $user ? $user->first_name : 'N/A',
                        'last_name' => $user ? $user->last_name : 'N/A',
                        'order_history' => $order_history,
                    ];

                    $response = ['status' => 'ok', 'customer' => $customer_data];
                    break;

                case 'link_renewal_order':
                        $order_id = isset($data['order_id']) ? absint($data['order_id']) : 0;
                        if (empty($order_id)) return ['status' => 'error', 'message' => 'Missing order_id'];
    
                        $tr_id = isset($data['tr_id']) ? sanitize_text_field($data['tr_id']) : '';
                        $mr_id = isset($data['mr_id']) ? sanitize_text_field($data['mr_id']) : '';
                        $recurring_id = isset($data['recurring_id']) ? sanitize_text_field($data['recurring_id']) : '';
    
                        if (empty($tr_id) || empty($mr_id)) {
                            return ['status' => 'error', 'message' => 'Both tr_id and mr_id are required for renewal linking'];
                        }
    
                        $order = wc_get_order($order_id);
                        if (!$order) {
                            return ['status' => 'error', 'message' => 'Order not found'];
                        }
    
                        // Verify this is actually a renewal order
                        if (!function_exists('wcs_order_contains_renewal') || !wcs_order_contains_renewal($order)) {
                            return ['status' => 'error', 'message' => 'Order is not a renewal order'];
                        }
    
                        // Link the renewal order to the completed payment
                        $order->set_transaction_id($tr_id);
                        $order->update_meta_data('_idem_transaction_id', $tr_id);
                        $order->update_meta_data('_idem_request_id', $mr_id);
    
                        // Get the associated subscription and link it properly
                        $subscriptions = wcs_get_subscriptions_for_renewal_order($order);
                        if (!empty($subscriptions)) {
                            $subscription = reset($subscriptions);
                            $recurring_request_id = !empty($recurring_id) ? $recurring_id : $mr_id;
                            $subscription->update_meta_data('_idem_recurring_request_id', $recurring_request_id);
                            $subscription->save();
                        }
    
                        $order->save();
    
                        // Complete the renewal order
                        $order->payment_complete($tr_id);
                        $order->add_order_note(__('Renewal order linked to completed IDEM payment via API', 'woocommerce-gateway-idem'));
    
                        $response = [
                            'status' => 'ok',
                            'message' => 'Renewal order successfully linked to completed payment',
                            'order_id' => $order_id,
                            'tr_id' => $tr_id,
                            'mr_id' => $mr_id
                        ];
                    break;

                case 'update_order_metas':
                    $order_id = isset($data['order_id']) ? absint($data['order_id']) : 0;
                    if (empty($order_id)) return ['status' => 'error', 'message' => 'Missing order_id'];
                    
                    $tr_id = isset($data['tr_id']) ? sanitize_text_field($data['tr_id']) : '';
                    $mr_id = isset($data['mr_id']) ? sanitize_text_field($data['mr_id']) : '';
                    
                    if (empty($tr_id) && empty($mr_id)) {
                        return ['status' => 'error', 'message' => 'At least one of tr_id or mr_id must be provided'];
                    }
                    
                    $order = wc_get_order($order_id);
                    if (!$order) {
                        return ['status' => 'error', 'message' => 'Order not found'];
                    }
                    
                    $updates_made = [];
                    
                    // Update transaction_id if tr_id provided
                    if (!empty($tr_id)) {
                        $order->set_transaction_id($tr_id);
                        $order->update_meta_data('_idem_transaction_id', $tr_id);
                        $updates_made[] = "transaction_id to {$tr_id}";
                    }
                    
                    // Update request_id if mr_id provided
                    if (!empty($mr_id)) {
                        $order->update_meta_data('_idem_request_id', $mr_id);
                        $updates_made[] = "request_id to {$mr_id}";
                        
                        // Check for associated subscription and update its recurring request ID
                        if (function_exists('wcs_get_subscriptions_for_order')) {
                            $subscriptions = wcs_get_subscriptions_for_order($order->get_id(), array('order_type' => 'any'));
                            if (!empty($subscriptions)) {
                                // Get the first subscription (usually there's only one per order)
                                $subscription = reset($subscriptions);
                                $subscription->update_meta_data('_idem_recurring_request_id', $mr_id);
                                $subscription->save();
                                $updates_made[] = "subscription recurring_request_id to {$mr_id}";
                            }
                        }
                    }
                    
                    $order->save();
                    
                    $note = sprintf('IDEM order metas updated via API: %s', implode(', ', $updates_made));
                    $order->add_order_note($note);
                    
                    $response_data = [
                        'status' => 'ok', 
                        'message' => 'Order metas updated successfully',
                        'updates' => $updates_made,
                        'order' => [
                            'id' => $order->get_id(),
                            'transaction_id' => $order->get_transaction_id(),
                            'meta' => [
                                '_idem_request_id' => $order->get_meta('_idem_request_id', true),
                                '_idem_transaction_id' => $order->get_meta('_idem_transaction_id', true)
                            ]
                        ]
                    ];
                    
                    // Include subscription info if it was updated
                    if (!empty($subscription)) {
                        $response_data['subscription'] = [
                            'id' => $subscription->get_id(),
                            'meta' => [
                                '_idem_recurring_request_id' => $subscription->get_meta('_idem_recurring_request_id', true)
                            ]
                        ];
                    }
                    
                    $response = $response_data;
                    break;

                case 'find_in_notes':
                    $search_string = isset($data['search_string']) ? sanitize_text_field($data['search_string']) : '';
                    if (empty($search_string)) {
                        return ['status' => 'error', 'message' => 'Missing search_string parameter.'];
                    }

                    $full_details = isset($data['full_details']) && filter_var($data['full_details'], FILTER_VALIDATE_BOOLEAN);

                    global $wpdb;
                    $sql = $wpdb->prepare(
                        "SELECT DISTINCT comment_post_ID FROM {$wpdb->comments} WHERE comment_type = 'order_note' AND comment_content LIKE %s",
                        '%' . $wpdb->esc_like($search_string) . '%'
                    );

                    $order_ids = $wpdb->get_col($sql);

                    if (empty($order_ids)) {
                        return ['status' => 'ok', 'data' => [], 'message' => 'No orders found with that note.'];
                    }

                    $result_data = [];
                    if ($full_details) {
                        foreach ($order_ids as $order_id) {
                            $order_data = $this->get_order($order_id);
                            if (!is_wp_error($order_data)) {
                                $result_data[] = $order_data;
                            }
                        }
                    } else {
                        $result_data = $order_ids;
                    }

                    $response = ['status' => 'ok', 'data' => $result_data];
                    break;

                case 'get_order_notes':
                    // Notes are where WooCommerce records WHO changed a status and when;
                    // find_in_notes only answers order ids. Internal notes (system + staff)
                    // by default: diagnostics without pulling customer correspondence.
                    $order_id = isset($data['order_id']) ? absint($data['order_id']) : 0;
                    if (empty($order_id)) return ['status' => 'error', 'message' => 'Missing order_id'];
                    if (!wc_get_order($order_id)) return ['status' => 'error', 'message' => 'Order not found'];
                    $type = isset($data['type']) ? sanitize_key($data['type']) : 'internal';
                    if (!in_array($type, ['internal', 'customer', 'all'], true)) {
                        return ['status' => 'error', 'message' => 'Invalid type (internal, customer or all)'];
                    }
                    $limit = isset($data['limit']) ? absint($data['limit']) : 50;
                    $limit = max(1, min($limit, 200)); // a subscription order can carry hundreds
                    $args = ['order_id' => $order_id, 'limit' => $limit, 'orderby' => 'date_created', 'order' => 'DESC'];
                    if ($type !== 'all') {
                        $args['type'] = $type;
                    }
                    $notes = [];
                    foreach (wc_get_order_notes($args) as $note) {
                        $notes[] = [
                            'id'            => (int) $note->id,
                            'date_created'  => $note->date_created ? $note->date_created->format('c') : null,
                            'added_by'      => $note->added_by,
                            'customer_note' => (bool) $note->customer_note,
                            'content'       => $note->content,
                        ];
                    }
                    $response = ['status' => 'ok', 'order_id' => $order_id, 'type' => $type, 'count' => count($notes), 'notes' => $notes];
                    break;

                case 'get_active_plugins':
                    // Authenticated only (never in the public GET diagnostic): the list of
                    // extensions and their versions is a map of the store's vulnerabilities.
                    if (!function_exists('get_plugins')) {
                        require_once ABSPATH . 'wp-admin/includes/plugin.php';
                    }
                    $plugins = [];
                    foreach (get_plugins() as $file => $meta) {
                        if (!is_plugin_active($file)) continue;
                        $plugins[] = ['file' => $file, 'name' => $meta['Name'], 'version' => $meta['Version'], 'mu' => false];
                    }
                    foreach (get_mu_plugins() as $file => $meta) {
                        $plugins[] = ['file' => $file, 'name' => $meta['Name'], 'version' => $meta['Version'], 'mu' => true];
                    }
                    $theme = wp_get_theme();
                    $response = [
                        'status'  => 'ok',
                        'plugins' => $plugins,
                        'theme'   => [
                            'name'    => $theme->get('Name'),
                            'version' => $theme->get('Version'),
                            'parent'  => $theme->parent() ? $theme->parent()->get('Name') : null,
                        ],
                    ];
                    break;

                case 'update_checkout_config':
                    $response = $this->handle_update_checkout_config($data);
                    break;

                case 'get_checkout_config':
                    $config = $this->get_cached_woocommerce_config();
                    $response = ['status' => 'ok', 'config' => $config];
                    break;

                case 'get_products':
                    $response = ['status' => 'ok', 'products' => $this->api_get_products($data)];
                    break;

                case 'get_product':
                    $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;
                    if (empty($product_id)) return ['status' => 'error', 'message' => 'Missing product_id'];
                    $product_data = $this->api_get_product($product_id);
                    if (is_wp_error($product_data)) {
                        return ['status' => 'error', 'message' => $product_data->get_error_message()];
                    }
                    $response = ['status' => 'ok', 'product' => $product_data];
                    break;

                case 'get_pages':
                    $response = ['status' => 'ok', 'pages' => $this->api_get_pages($data)];
                    break;

                default:
                    $response = ['status' => 'error', 'message' => 'Invalid action'];
                    break;
            }
        } catch (Exception $e) {
            $response = ['status' => 'error', 'message' => 'An exception occurred: ' . $e->getMessage()];
        }

        return $response;
    }

    /**
     * Handle subscription amount update API request
     * @param array $data Request data
     * @param int $subscription_id Subscription ID
     * @return array Response array
     */
    private function handle_update_subscription_amount($data, $subscription_id)
    {
        if (empty($subscription_id)) {
            return ['status' => 'error', 'message' => 'Missing subscription_id'];
        }
        
        $amount = isset($data['amount']) ? floatval($data['amount']) : 0;
        if ($amount <= 0) {
            return ['status' => 'error', 'message' => 'Invalid amount'];
        }
        
        if (!function_exists('wcs_get_subscription')) {
            return ['status' => 'error', 'message' => 'WooCommerce Subscriptions not active'];
        }
        
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription) {
            return ['status' => 'error', 'message' => 'Subscription not found'];
        }
        
        // Logic from idem_subscription_action_ajax_handler
        $old_total = $subscription->get_total();
        $ratio = ($old_total > 0) ? $amount / $old_total : 1;
        foreach ($subscription->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $old_item_total = (float) $item->get_total();
            $new_item_total = $old_item_total * $ratio;
            $item->set_total($new_item_total);
            $item->set_subtotal($new_item_total);
            $item->save();
        }
        $subscription->calculate_totals();
        $subscription->save();
        $note = sprintf(__('Subscription amount updated to %s via platform API.', 'woocommerce-gateway-idem'), wc_price($amount));
        $subscription->add_order_note($note);
        
        return ['status' => 'ok', 'message' => 'Subscription amount updated successfully.'];
    }

    /**
     * Handle subscription schedule update API request
     * @param array $data Request data
     * @param int $subscription_id Subscription ID
     * @return array Response array
     */
    private function handle_update_subscription_schedule($data, $subscription_id)
    {
        if (empty($subscription_id)) {
            return ['status' => 'error', 'message' => 'Missing subscription_id'];
        }
        
        if (!function_exists('wcs_get_subscription')) {
            return ['status' => 'error', 'message' => 'WooCommerce Subscriptions not active'];
        }
        
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription) {
            return ['status' => 'error', 'message' => 'Subscription not found'];
        }

        $dates_to_update = [];
        $notes = [];

        // Update interval and period with validation
        if (isset($data['interval']) && isset($data['period'])) {
            // Validate interval
            $interval = absint($data['interval']);
            if ($interval < 1 || $interval > 365) {
                return ['status' => 'error', 'message' => 'Invalid billing interval (must be 1-365)'];
            }
            
            // Validate period
            $allowed_periods = ['day', 'week', 'month', 'year'];
            $period = sanitize_text_field($data['period']);
            if (!in_array($period, $allowed_periods)) {
                return ['status' => 'error', 'message' => 'Invalid billing period (must be: day, week, month, year)'];
            }
            
            $subscription->set_billing_interval($interval);
            $subscription->set_billing_period($period);
            $notes[] = 'Billing schedule updated to every ' . $interval . ' ' . $period . '(s).';
        }

        // Update next payment date
        if (isset($data['next_payment_date'])) {
            $dates_to_update['next_payment'] = wc_clean($data['next_payment_date']);
            $notes[] = 'Next payment date updated to ' . $dates_to_update['next_payment'] . '.';
        }
        
        // Update end date
        if (isset($data['end_date'])) {
            $dates_to_update['end'] = wc_clean($data['end_date']);
            $notes[] = 'End date updated to ' . $dates_to_update['end'] . '.';
        }

        if (!empty($dates_to_update)) {
            $subscription->update_dates($dates_to_update);
        }
        
        $subscription->save();
        if (!empty($notes)) {
            $subscription->add_order_note('Subscription schedule updated via platform API: ' . implode(' ', $notes));
        }
        
        return ['status' => 'ok', 'message' => 'Subscription schedule updated successfully.'];
    }

    /**
     * Handle checkout configuration update from EasyProcess admin
     *
     * This receives checkout description and icon from EasyProcess and caches them
     * in a WordPress transient for use at checkout.
     *
     * @param array $data Request data containing checkout_description and/or checkout_icon
     * @return array Response array
     */
    private function handle_update_checkout_config($data)
    {
        $transient_key = 'idem_woo_checkout_config';
        $logger = wc_get_logger();

        // Extract and sanitize config
        $config = [
            'checkout_title' => isset($data['checkout_title']) && $data['checkout_title'] !== ''
                ? sanitize_text_field($data['checkout_title'])
                : null,
            'checkout_title_trusted' => isset($data['checkout_title_trusted']) && $data['checkout_title_trusted'] !== ''
                ? sanitize_text_field($data['checkout_title_trusted'])
                : null,
            'checkout_description' => isset($data['checkout_description']) && $data['checkout_description'] !== ''
                ? sanitize_textarea_field($data['checkout_description'])
                : null,
            'checkout_description_trusted' => isset($data['checkout_description_trusted']) && $data['checkout_description_trusted'] !== ''
                ? sanitize_textarea_field($data['checkout_description_trusted'])
                : null,
            'checkout_icon' => isset($data['checkout_icon']) && $data['checkout_icon'] !== ''
                ? esc_url_raw($data['checkout_icon'])
                : null,
            'crypto_only_mode' => isset($data['crypto_only_mode']) && in_array($data['crypto_only_mode'], ['disabled', 'allowed', 'forced'])
                ? $data['crypto_only_mode']
                : 'disabled'
        ];

        // Cache indefinitely (until next update from EasyProcess)
        // Using a very long expiration (1 year) since updates are pushed
        set_transient($transient_key, $config, YEAR_IN_SECONDS);

        $logger->info('WooCommerce checkout config updated via API', ['source' => 'idem', 'config' => $config]);

        return [
            'status' => 'ok',
            'message' => 'Checkout configuration updated successfully',
            'config' => $config
        ];
    }

    /**
     * Check EasyProcess IPN validity.
     *
     * @param array $data
     * @return array Response data
     */
    public function handle_ipn_request(array $data): array
    {
        $response = [
            'status' => 'ok',
            'message' => 'IPN processed successfully'
        ];

        $epError        = false;
        $epErrMsg       = null;   // human message (translatable)
        $epErrCode      = null;   // stable machine code, NEVER derived from a translated message
        $epDone         = false;
        $processRefund  = false;
        $epDebugEmail   = $this->get_option('debug_email', get_option('admin_email'));
        $vendor_name    = get_bloginfo('name');
        $vendor_url     = home_url('/');

        $this->log("\n" . '----------' . "\n" . 'EasyProcess IPN call received');

        // Get data sent by EasyProcess
        $this->log('Get posted data', true);
        $this->log('EasyProcess Data: ' . json_encode($data), true);

        if (empty($data)) {
            $epError  = true;
            $epErrMsg = IDEM_ERR_BAD_ACCESS;
            $epErrCode = 'bad-access';
        }

        // Verify source IP
        if (! $epError) {
            $this->log('Verify source IP', true);
            if ('yes' === $this->get_option('validate_ipn_ip') && !$this->validate_ip($_SERVER['REMOTE_ADDR'])) {
                $epError  = true;
                $epErrMsg = IDEM_ERR_BAD_SOURCE_IP;
                $epErrCode = 'bad-source-ip';
            }
        }

        // Verify data received
        if (! $epError) {
            $this->log('Verify data received', true);
            $epValid = $this->validate_response_data($data);
            if (! $epValid) {
                $epError = true;
                $epErrMsg = IDEM_ERR_BAD_ACCESS;
                $epErrCode = 'bad-access';
            }
        }

        // Verify security signature
        if (! $epError) {
            $this->log('Verify security signature', true);
            // Signature verification failed
            if ('yes' === $this->get_option('validate_ipn_signature') && !$this->validate_signature($data)) {
                $epError  = true;
                $epErrMsg = IDEM_ERR_INVALID_SIGNATURE;
                $epErrCode = 'invalid-signature';
            }
        }

        // Request is valid, go!
        if (! $epError) {
            $response       = $data['MerchantApiResponse'];
            $request        = $response['PlatformRequest'];
            // PlatformTransaction and ref_id are optional (form-encoded post drops nulls, see
            // validate_response_data): read them guarded, or a PHP notice ends up printed
            // ahead of our JSON answer on stores with display_errors on.
            $transaction    = (isset($response['PlatformTransaction']) && is_array($response['PlatformTransaction'])) ? $response['PlatformTransaction'] : null;
            // $user           = $response['PlatformAccount'];
            $sessionid      = $request['addinfo'] ?? null;
            // $request_id     = $request['request_id'];
            $ref_raw        = isset($request['ref_id']) ? $request['ref_id'] : '';
            $ref_id         = trim((string) $ref_raw);
            $order_id       = absint($ref_raw); // cast the raw value as before: only the notice goes away
            // $transaction_id = $request['tr_id'];
            $order_key      = (!empty($sessionid)) ? wc_clean($sessionid) : null;

            // A suffixed payment-link reference (e.g. "39556.1") is NOT an order
            // id of this store: absint() would silently map it onto an unrelated
            // order and fail it with a bogus amount-mismatch. Refuse it upfront.
            if ((string) $order_id !== $ref_id) {
                $this->log('IPN reference "' . $ref_id . '" is not a strict order id - refusing (reference-not-an-order)');
                $epError   = true;
                $epErrMsg  = IDEM_ERR_REF_NOT_ORDER;
                $epErrCode = 'reference-not-an-order';
                $order     = null;
            } else {
                $order = wc_get_order($order_id);
                // wc_get_order() can return a WC_Order_Refund for a numeric id: it
                // extends WC_Abstract_Order, not WC_Order, so it carries neither
                // get_order_key() (correlation gate) nor is_paid() (idempotence) and
                // would fatal. WC_Subscription extends WC_Order and stays allowed.
                if (!empty($order) && !is_a($order, 'WC_Order')) {
                    $this->log('IPN reference ' . $order_id . ' resolves to a ' . get_class($order) . ', not a shop order - refusing (reference-not-an-order)');
                    $epError   = true;
                    $epErrMsg  = IDEM_ERR_REF_NOT_ORDER;
                    $epErrCode = 'reference-not-an-order';
                    $order     = null;
                }
            }
            $request_status = $request['status'];

            // The order designated by ref_id, captured BEFORE the duplicate-prevention
            // switch below can replace $order with a renewal. addinfo echoes the key of
            // the order the payment was created for - for a cycle, the PARENT order - so
            // this is what the correlation gate must check against.
            $correlation_order = $order;

            // DUPLICATE PREVENTION: Check if we already have an order for this request_id
            // This prevents creating duplicate orders when IPN is received multiple times
            $existing_order_for_request = (! $epError) ? $this->find_order_by_idem_request_id($request['request_id']) : null;
            if ($existing_order_for_request) {
                $this->log('Found existing order #' . $existing_order_for_request->get_id() . ' for IDEM request ' . $request['request_id'] . '. Using existing order.');
                $order = $existing_order_for_request;
                $order_id = $order->get_id();
            }

            // CORRELATION GATE - runs BEFORE any state change and BEFORE the
            // cycle->renewal switch. The IPN signature does NOT cover ref_id/addinfo,
            // so this check is the only binding between the IPN and the order it
            // claims to pay.
            // It checks $correlation_order, NEVER the substituted one: a replayed cycle
            // IPN resolves $order to the renewal, whose key legitimately differs from
            // the parent key carried by addinfo - comparing it would fail every replay.
            // The substitution rests on a stronger link anyway: request_id IS covered by
            // the signature, and _idem_request_id was written by us. Falls back to $order
            // when ref_id resolved to nothing, preserving the fail-closed behaviour there.
            $gate_order = !empty($correlation_order) ? $correlation_order : $order;
            if (! $epError && !empty($gate_order) && !empty($order_key) && $gate_order->get_order_key() !== $order_key) {
                $this->log('Order key mismatch on order #' . $gate_order->get_id() . ' - refusing IPN before any state change');
                $epError   = true;
                $epErrMsg  = IDEM_ERR_SESSIONID_MISMATCH;
                $epErrCode = 'session-id-mismatch';
            }

            // Handle cycle payments: when is_a_cycle is true, the ref_id is the parent order ID
            // We need to find or create a renewal order to process the payment against
            // IMPORTANT: Only create orders for completed payments, not refunds/chargebacks/etc.
            $is_cycle_payment = !empty($request['is_a_cycle']);
            $parent_order_for_cycle = null; // Store reference to parent order for cycle payments
            // Tracks whether $order really ended up being a RENEWAL order. Set only
            // where the substitution actually happens - never re-derived afterwards
            // by asking WooCommerce Subscriptions again: a renewal created in this
            // very request is not always resolvable from the database yet (the S14
            // intermittence, QA 2026-08-06), and a false negative here would ignore
            // a legitimate cycle and leave the renewal unpaid.
            $cycle_renewal_resolved = false;

            if (! $epError && $is_cycle_payment && $order && $request_status === 'completed') {
                $this->log('Cycle payment IPN received for order #' . $order_id);

                // Check if this is a parent order (contains subscription) rather than a renewal order
                if (function_exists('wcs_order_contains_subscription') &&
                    function_exists('wcs_order_contains_renewal') &&
                    wcs_order_contains_subscription($order) &&
                    !wcs_order_contains_renewal($order)) {

                    $this->log('Order #' . $order_id . ' is a parent order. Finding or creating renewal order for cycle payment.');
                    $parent_order_for_cycle = $order; // Keep reference to parent order

                    // Find or create the renewal order
                    $renewal_order_result = $this->find_or_create_renewal_order_for_cycle_payment($order);

                    if (is_wp_error($renewal_order_result)) {
                        $this->log('Error finding/creating renewal order: ' . $renewal_order_result->get_error_message());
                        // NO fallback to the parent order: that would settle the order
                        // which CREATED the subscription with a later cycle's payment.
                        // Handled by the cycle guard below.
                    } else {
                        // Switch to the renewal order for payment processing
                        $order = $renewal_order_result;
                        $order_id = $order->get_id();
                        $cycle_renewal_resolved = true;
                        $this->log('Switched to renewal order #' . $order_id . ' for payment processing');
                    }
                } elseif (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order)) {
                    $this->log('Order #' . $order_id . ' is already a renewal order. Processing directly.');
                    $cycle_renewal_resolved = true;
                }
            }

            // CYCLE GUARD - a cycle pays a RENEWAL, never the order that created the
            // subscription. When no renewal order could be resolved (renewal creation
            // failed, WooCommerce Subscriptions inactive, or a refunded cycle, which
            // never even enters the block above), $order is still the PARENT: touching
            // it would settle - or refund - the wrong order, with the wrong date and
            // the wrong transaction id.
            // Acknowledge (HTTP 200, status ok) so the platform neither retries nor
            // raises a daily alert for something that is expected on a store without
            // subscriptions, change NOTHING, and leave an audit note so the merchant
            // can reconcile on the platform. Idempotent: a replay of the same cycle
            // request adds no second note.
            //
            // EXCEPT for the statuses that must reach the 'ko'/'error' branch below
            // (:2335), which puts the WooCommerce subscription on hold - that mutation
            // targets the SUBSCRIPTION, not the parent order, and swallowing it would
            // leave a subscription active after a failed recurring charge (review,
            // 2026-08-07).
            // An ALLOW-LIST, not "everything except completed/refunded": the platform
            // maps exactly these four request statuses to a top-level ERROR response
            // (MerchantRequest::getIpnData()), so they are the only ones that reach the
            // failure branch. Statuses like 'unpaid' or 'chargeback' also emit an IPN
            // but map to no response status at all - they would fall through to the
            // default branch and note the PARENT order, so they stay guarded.
            // The twin plugin guards EVERY status instead, because it has no
            // subscription handling at all and its failure branch would fail the parent
            // order itself.
            // BOTH statuses must agree. The allow-list reads PlatformRequest.status,
            // which IS covered by the signature; the switch that eventually runs reads
            // MerchantApiResponse.status, which is NOT. A forged replay carrying a
            // legitimately signed 'rejected' together with a top-level 'STOPPED' would
            // otherwise slip through this exception and reach the branch that CANCELS
            // the subscription. Legitimate payloads always agree, so requiring an
            // ERROR/KO top-level costs nothing (hardening, review 2026-08-07).
            $response_status = isset($response['status']) ? strtolower((string) $response['status']) : '';
            $cycle_reaches_failure_path = in_array($request_status, array('rejected', 'error', 'cancelled', 'pending'), true)
                && in_array($response_status, array('error', 'ko'), true);
            if (! $epError && $is_cycle_payment && !empty($order) && !$cycle_renewal_resolved && !$cycle_reaches_failure_path) {
                $this->log('Cycle IPN on order #' . $order->get_id() . ' resolved no renewal order (status ' . $request_status . ') - acknowledged without any state change.');
                $cycle_ack_meta = '_idem_cycle_ack_' . $request['request_id'];
                if (!$order->get_meta($cycle_ack_meta)) {
                    $order->add_order_note(sprintf(
                        /* translators: 1: request id, 2: transaction id, 3: amount and currency, 4: request status */
                        __('A recurring cycle payment (request %1$s, transaction %2$s, amount %3$s, status %4$s) was received for this order, but no renewal order could be resolved for it. This order was left untouched on purpose: a cycle must never settle the order that created the subscription. Reconcile the payment on the platform.', 'woocommerce-gateway-idem'),
                        $request['request_id'],
                        isset($request['tr_id']) ? $request['tr_id'] : '?',
                        (isset($request['amount']) ? $request['amount'] : '?') . (isset($request['currency']) ? ' ' . $request['currency'] : ''),
                        $request_status
                    ));
                    $order->update_meta_data($cycle_ack_meta, time());
                    $order->save();
                }
                $epDone = true;
            }
        }

        if (empty($order) && !$epError) {
            $epError  = true;
            $epErrMsg = IDEM_ERR_ORDER_NOT_FOUND;
            $epErrCode = 'order-not-found';
        }

        // Get internal order and verify it hasn't already been processed
        // ! $epDone: the cycle guard above already concluded this IPN without
        // touching anything. Without it, a refunded cycle would still set
        // $processRefund on the PARENT order and refund the wrong one.
        if (! $epError && ! $epDone) {
            //$this->log( "Purchase:\n". json_encode( $order ) );
            // Check if the order has already been processed
            if ($request_status == 'refunded') {
                // IPN for refund
                $epDone = true;
                if ($order->get_status() != 'refunded') {
                    $this->log('Order has been refunded');
                    $processRefund = true;
                }
            } else if ($this->ipn_already_processed($order, $request, $request_status)
                       && empty($request['is_recurring'])) {
                // See ipn_already_processed() for what counts as "already processed"
                // and why a cycle is judged differently from a one-off payment.
                $this->log('Order has already been processed');
                $epDone = true;
                // A DIFFERENT completed request hitting an already-processed order
                // is a probable duplicate charge: audit note, state untouched.
                $existing_request_id = $order->get_meta('_idem_request_id');
                if ('completed' === $request_status && !empty($existing_request_id)
                    && (string) $existing_request_id !== (string) $request['request_id']) {
                    $order->add_order_note(sprintf(
                        /* translators: 1: request id, 2: transaction id, 3: amount */
                        __('Another payment was received for this already-processed order (request %1$s, transaction %2$s, amount %3$s). Verify on the platform: this may be a duplicate charge.', 'woocommerce-gateway-idem'),
                        $request['request_id'],
                        $request['tr_id'],
                        $request['amount'] . (isset($request['currency']) ? ' ' . $request['currency'] : '')
                    ));
                }
            }
        }

        // Check data against internal order
        //
        // NOT for a cycle that never resolved to a renewal: $order is then still the
        // PARENT, and none of the checks below are meaningful against it. The cycle
        // branch would compare the SUBSCRIPTION total with the PARENT's total - and a
        // merchant changing the subscription amount (supported: subscription_amount_
        // changes) makes those differ legitimately, on 8 of the 26 subscriptions of the
        // dev shop alone. That raised order-total-drift, which sets $epError, which
        // skips the failure branch that suspends the subscription: a declined cycle on
        // such a subscription left it active (review, 2026-08-07).
        // Reaching here with a cycle and no renewal means a NON-completed status - the
        // guard above already concluded the completed ones - so there is no captured
        // amount to validate in the first place.
        $cycle_without_renewal = !empty($request['is_a_cycle']) && empty($cycle_renewal_resolved);
        if ($cycle_without_renewal && ! $epError && ! $epDone) {
            $this->log('Cycle IPN with no renewal order resolved (status ' . $request_status . ') - skipping the amount checks: they would compare against the parent order.');
        }
        if (! $epError && ! $epDone && ! $cycle_without_renewal) {
            $this->log('Check data against internal order');

            // Resolve the IPN amount in the order currency - and REQUIRE an
            // explicit currency match: never compare bare numbers across
            // currencies (100 EUR is not 100 USD). For FX transactions, IDEM
            // sends the converted amount in 'amount'/'currency' and the
            // customer-facing amount in 'original_amount'/'original_currency'.
            // NOTE: original_* are NOT covered by the v1 signature (hash_v2
            // follow-up) - the correlation gate (order key) is the binding.
            $order_currency = $order->get_currency();
            $ipn_amount = null;
            if (isset($request['currency']) && $request['currency'] === $order_currency) {
                $ipn_amount = floatval($request['amount']);
            } elseif (!empty($request['original_amount']) && !empty($request['original_currency'])
                && $request['original_currency'] === $order_currency) {
                $ipn_amount = floatval($request['original_amount']);
                $this->log('FX transaction detected: using original_amount ' . $ipn_amount . ' ' . $request['original_currency'] . ' for verification');
            }

            $live_total      = (float) $order->get_total();
            $expected_amount = $live_total; // refined below; used by the on-hold note

            if ($ipn_amount === null) {
                $this->log('Currency mismatch on order #' . $order->get_id() . ': IPN currency ' . (isset($request['currency']) ? $request['currency'] : '(none)') . ', original currency ' . (isset($request['original_currency']) ? $request['original_currency'] : '(none)') . ', order currency ' . $order_currency);
                $epError   = true;
                $epErrMsg  = IDEM_ERR_AMOUNT_MISMATCH;
                $epErrCode = 'currency-mismatch';
            } elseif (!empty($request['is_a_cycle'])) {
                // Recurring cycle - two checks as well:
                // 1) AUTHENTICITY: the IPN amount must match the subscription total
                //    (cycle amounts are not signed per-attempt).
                // 2) COHERENCE: the subscription total must match the renewal
                //    order's own total - a renewal at 110 paid 100 must NOT complete.
                $subscription_total = null;
                // 'any' is REQUIRED here: $order is the renewal order, and the default
                // order_type ('parent') returns zero subscriptions for it - which left
                // $subscription_total null, silently anchored authenticity on the
                // renewal's own total and made the coherence check below a tautology
                // (QA 2026-08-06, S14).
                // function_exists: the rest of the cycle handling guards it (:1759-1760)
                // and the cycle guard above now bails out when no renewal was resolved
                // (which WCS being inactive guarantees), so this is defence in depth -
                // an unguarded call here would be a PHP fatal, before any amount check.
                $subscriptions = function_exists('wcs_get_subscriptions_for_order')
                    ? wcs_get_subscriptions_for_order($order, array('order_type' => 'any'))
                    : array();
                if (!empty($subscriptions)) {
                    $subscription = array_shift($subscriptions); // Get the first (usually only) subscription
                    $subscription_total = (float) $subscription->get_total();
                    $this->log('Recurring payment detected. Checking against subscription amount: ' . $subscription_total);
                } else {
                    // Now that 'any' is passed, this is anomalous rather than routine:
                    // the coherence check below degrades to comparing the renewal total
                    // with itself, so say so instead of degrading silently.
                    $this->log('Cycle IPN on order #' . $order->get_id() . ': no subscription resolved, falling back to the renewal total - the coherence check cannot run.');
                }
                $expected_amount = ($subscription_total !== null) ? $subscription_total : $live_total;
                if (! $this->amounts_identical($ipn_amount, $expected_amount)) {
                    // Always log all values (never gated on verbose logging or debug email)
                    $this->log('Amount mismatch on order #' . $order->get_id() . ' (cycle): IPN amount ' . $ipn_amount . ', subscription amount ' . $expected_amount . ', renewal order total ' . $live_total);
                    $epError   = true;
                    $epErrMsg  = IDEM_ERR_AMOUNT_MISMATCH;
                    $epErrCode = 'amount-mismatch';
                } elseif (! $this->amounts_identical($expected_amount, $live_total)) {
                    $this->log('Order total drift on order #' . $order->get_id() . ' (cycle): subscription amount ' . $expected_amount . ', renewal order total ' . $live_total);
                    $epError   = true;
                    $epErrMsg  = IDEM_ERR_ORDER_TOTAL_DRIFT;
                    $epErrCode = 'order-total-drift';
                }
            } else {
                // Two DISTINCT checks, never merged:
                // 1) AUTHENTICITY - the IPN amount must match an amount THIS store
                //    signed at redirect time (any recorded attempt, order currency).
                // 2) COMMERCIAL COHERENCE - the signed amount must still equal the
                //    CURRENT order total. An authentic payment that no longer covers
                //    the order must NEVER auto-complete it.
                // Both use amounts_identical(): strict at currency precision - the
                // legacy epsilon comparison would let a one-cent gap through.
                $signed_amount   = null;
                $signed_attempts = $order->get_meta('_idem_signed_attempts');
                if (is_array($signed_attempts) && !empty($signed_attempts)) {
                    foreach (array_reverse($signed_attempts) as $attempt) { // most recent first
                        if (isset($attempt['amount'], $attempt['currency'])
                            && $attempt['currency'] === $order_currency
                            && $this->amounts_identical($ipn_amount, $attempt['amount'])) {
                            $signed_amount = (float) $attempt['amount'];
                            break;
                        }
                    }
                } elseif ($this->amounts_identical($ipn_amount, $live_total)) {
                    // Orders created before the snapshot feature: legacy live compare.
                    $signed_amount = $live_total;
                }
                $expected_amount = ($signed_amount !== null) ? $signed_amount : $live_total;

                if ($signed_amount === null) {
                    // Always log all values (never gated on verbose logging or debug email)
                    $this->log('Amount mismatch on order #' . $order->get_id() . ': IPN amount ' . $ipn_amount . ', live order total ' . $live_total . ', signed attempts: ' . wp_json_encode($signed_attempts));
                    $epError   = true;
                    $epErrMsg  = IDEM_ERR_AMOUNT_MISMATCH;
                    $epErrCode = 'amount-mismatch';
                } elseif (! $this->amounts_identical($signed_amount, $live_total)) {
                    $this->log('Order total drift on order #' . $order->get_id() . ': paid (signed) ' . $signed_amount . ', current order total ' . $live_total);
                    $epError   = true;
                    $epErrMsg  = IDEM_ERR_ORDER_TOTAL_DRIFT;
                    $epErrCode = 'order-total-drift';
                }
            }
        }

        // If an error occurred
        if ($epError) {
            if (!empty($epErrMsg))
                $this->log('Error occurred: ' . $epErrMsg);

            // AMOUNT MISMATCH / TOTAL DRIFT on a CAPTURED payment: the money HAS
            // been collected. Never leave the order silently in "pending" (merchant
            // sweeps cancel it, the customer sees nothing and pays again). Park it
            // on-hold with all three amounts; keep refusing "processing".
            // Mutation gates: strict integer reference (T4), order key REQUIRED and
            // matching THIS order, and the order must not belong to another payment
            // (request_id association). The key check is NOT redundant with the
            // correlation gate: that one checks the order designated by ref_id, which
            // for a cycle is the parent - not the renewal $order points to here. An
            // ABSENT key is not sufficient correlation to mutate either.
            // Known consequence: once the cycle->renewal switch has fired, a cycle is never
            // parked on-hold here - a renewal's key never equals the parent key carried by
            // addinfo. Pre-existing gap, not introduced by the correlation-gate anchoring.
            // But on the degraded path where the switch could NOT fire - renewal creation
            // returned WP_Error, see the cycle block above - $order is still the parent,
            // its key DOES match addinfo, and this branch parks the cycle on the parent
            // order. (WCS being inactive cannot reach here: the cycle amount check calls
            // wcs_get_subscriptions_for_order() unguarded and fatals earlier.)
            if (in_array($epErrCode, array('amount-mismatch', 'order-total-drift', 'currency-mismatch'), true)
                && !empty($order)
                && isset($request_status) && 'completed' === $request_status
                && isset($response['status']) && 'ok' === strtolower($response['status'])
                && !empty($order_key) && $order->get_order_key() === $order_key) {

                $existing_request_id = $order->get_meta('_idem_request_id');
                $is_other_request    = (!empty($existing_request_id) && (string) $existing_request_id !== (string) $request['request_id']);

                if ($ipn_amount === null) {
                    // currency-mismatch: no amount is expressible in the order currency,
                    // so report the raw values instead of a three-amount comparison.
                    $note = sprintf(
                        /* translators: 1: raw paid amount and currency, 2: raw original amount and currency, 3: current order total */
                        __('Payment captured by IDEM in a currency that does not match this order. Paid: %1$s — original: %2$s — current order total: %3$s. Review the payment and complete the order manually.', 'woocommerce-gateway-idem'),
                        (isset($request['amount']) ? $request['amount'] : '?') . ' ' . (isset($request['currency']) ? $request['currency'] : '?'),
                        (!empty($request['original_amount']) ? $request['original_amount'] : '?') . ' ' . (!empty($request['original_currency']) ? $request['original_currency'] : '?'),
                        number_format((float) $order->get_total(), 2) . ' ' . $order->get_currency()
                    );
                } else {
                    $note = sprintf(
                        /* translators: 1: amount paid, 2: expected (signed) amount, 3: current order total */
                        __('Payment captured by IDEM but not applied automatically. Paid: %1$s — expected (signed): %2$s — current order total: %3$s. Review the payment and complete the order manually.', 'woocommerce-gateway-idem'),
                        number_format((float) $ipn_amount, 2) . ' ' . $order->get_currency(),
                        number_format((float) $expected_amount, 2) . ' ' . $order->get_currency(),
                        number_format((float) $order->get_total(), 2) . ' ' . $order->get_currency()
                    );
                }

                if ($is_other_request || $order->is_paid()) {
                    // The order already belongs to another payment (or is already
                    // paid): audit trail only. Never overwrite the original payment's
                    // identifiers, never downgrade the status.
                    $order->add_order_note($note . ' ' . sprintf(
                        /* translators: 1: request id, 2: transaction id */
                        __('(distinct payment: request %1$s, transaction %2$s)', 'woocommerce-gateway-idem'),
                        $request['request_id'],
                        $request['tr_id']
                    ));
                    $order->save();
                } else {
                    $order->update_meta_data('_idem_request_id', $request['request_id']);
                    $order->update_meta_data('_idem_transaction_id', $request['tr_id']);
                    // The payment IPN WAS delivered: keep the passive IPN-health check accurate.
                    $order->update_meta_data('_ep_ipn_received', time());
                    $this->clear_ipn_missing($order->get_id());
                    try {
                        $order->set_transaction_id($request['tr_id']);
                        $order->set_date_paid(time());
                    } catch (WC_Data_Exception $e) {
                    }
                    $order->update_status('on-hold', $note);
                    $order->save();
                }
            }

            if ($this->send_debug_email) {
                $this->log('Sending email notification');
                // Send an email
                $subject = "IDEM IPN error: " . ($epErrMsg ?? "");
                $body =
                    "Hi,\n\n" .
                    "An IDEM IPN on your website requires attention\n" .
                    "------------------------------------------------------------\n" .
                    "Site: " . $vendor_name . " (" . $vendor_url . ")\n" .
                    "Remote IP Address: " . $_SERVER['REMOTE_ADDR'] . "\n" .
                    "Remote host name: " . gethostbyaddr($_SERVER['REMOTE_ADDR']) . "\n";
                if (isset($request['ref_id']))
                    $body .= "Reference: " . $request['ref_id'] . "\n";
                if (!empty($order))
                    $body .= "Purchase ID: " . $order->get_id() . "\n";
                if (!empty($order))
                    $body .= "User ID: " . $order->get_user_id() . "\n";
                if (isset($response['status']))
                    $body .= "EasyProcess Response Status: " . $response['status'] . "\n";
                if (isset($request))
                    $body .= "EasyProcess Request ID: " . $request['request_id'] . "\n";
                if (isset($request['status']))
                    $body .= "EasyProcess Request Status: " . $request['status'] . "\n";
                $body .=
                    "\nError: " . ($epErrMsg ?? "") . "\n";

                switch (($epErrMsg ?? "")) {
                    case IDEM_ERR_AMOUNT_MISMATCH:
                        if (isset($request) && isset($order))
                            $body .=
                                "Value received : " . $request['amount'] . "\n" .
                                "Value should be: " . $order->get_formatted_order_total();
                        break;

                    case IDEM_ERR_ORDER_ID_MISMATCH:
                        if (isset($request) && isset($order))
                            $body .=
                                "Value received : " . $request['ref_id'] . "\n" .
                                "Value should be: " . $order->get_id();
                        break;

                    case IDEM_ERR_ORDER_INVALID:
                        if (isset($request) && isset($order))
                            $body .=
                                "Value received : " . $request['addinfo'] . "\n" .
                                "Value should be: " . $order->get_order_key();
                        break;

                    // For all other errors there is no need to add additional information
                    default:
                        break;
                }

                wp_mail($epDebugEmail, $subject, $body);
            }
        } elseif (! $epDone) {

            $this->log('Check status and update order');

            if (empty($order) || empty($response) || empty($request)) {
                // Return a structured error instead of exiting to ensure a valid JSON response
                $this->log('Missing data in IPN request (order/response/request). Returning error.');
                $epError  = true;
                $epErrMsg = IDEM_ERR_BAD_ACCESS;
                $epErrCode = 'bad-access';
            }

            // Check if this is a subscription renewal order (either originally or via cycle payment handling)
            $is_renewal = false;
            $is_cycle_payment = !empty($request['is_a_cycle']);
            if (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order)) {
                $is_renewal = true;
                $this->log('Processing subscription renewal payment');
            } elseif ($is_cycle_payment) {
                // Cycle payments are treated as renewals even if processed against a different order
                $is_renewal = true;
                $this->log('Processing cycle payment as renewal');
            }

            if (! $epError) {
                switch (strtolower($response['status'])) {
                    case 'ok':
                        if ($request['status'] == 'completed') {
                            // If order was cancelled, expired or failed, set it back to pending before processing payment
                            if (in_array($order->get_status(), ['cancelled', 'expired', 'failed', 'trash'])) {
                                $this->log('Order #' . $order->get_id() . ' status was ' . $order->get_status() . ', updating to pending to allow payment completion.');
                                $order->update_status('pending', __('Order status updated from a terminal state to allow payment completion.', 'woocommerce-gateway-idem'));
                            }

                            $this->log('- Complete');
                            $note_text = $is_renewal ? __('IDEM subscription renewal payment completed', 'woocommerce-gateway-idem') : __('Idem payment completed', 'woocommerce-gateway-idem');
                            $order->add_order_note($note_text);
                            $order->update_meta_data('_idem_request_id', $request['request_id']);
                            $order->update_meta_data('_idem_transaction_id', $request['tr_id']);
                            // Mark that the payment IPN was delivered for this order, and retract any
                            // earlier "IPN missing" record (the notification did arrive, just later than
                            // the fallback pull) so the passive IPN-health check stays accurate.
                            $order->update_meta_data('_ep_ipn_received', time());
                            $this->clear_ipn_missing($order->get_id());
                            $order->save();

                            // Get subscriptions - handle both parent orders and renewal orders
                            // IMPORTANT: Check renewal FIRST because wcs_order_contains_subscription()
                            // returns true for both parent and renewal orders
                            $subscriptions = array();
                            if (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order)) {
                                // This is a renewal order - get subscriptions from it
                                $subscriptions = wcs_get_subscriptions_for_renewal_order($order);
                                $this->log('Retrieved subscriptions from renewal order #' . $order->get_id());
                            } elseif (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order)) {
                                // This is a parent order with subscriptions
                                $subscriptions = wcs_get_subscriptions_for_order($order);
                                $this->log('Retrieved subscriptions from parent order #' . $order->get_id());
                            } elseif ($is_cycle_payment && isset($parent_order_for_cycle)) {
                                // Cycle payment with parent order reference - get subscriptions from parent
                                $subscriptions = wcs_get_subscriptions_for_order($parent_order_for_cycle);
                                $this->log('Retrieved subscriptions from parent order reference #' . $parent_order_for_cycle->get_id());
                            }

                            // Update subscription meta and status if needed
                            if (!empty($subscriptions)) {
                                foreach ($subscriptions as $subscription) {
                                    // Reactivate on-hold subscriptions
                                    if ($subscription->get_status() === 'on-hold') {
                                        // Temporarily remove hook to prevent loop
                                        remove_action('woocommerce_subscription_status_on-hold_to_active', array($this, 'reactivate_subscription_recurring_payment'), 10);
                                        $subscription->update_status('active', __('Subscription reactivated following successful recurring payment.', 'woocommerce-gateway-idem'));
                                        add_action('woocommerce_subscription_status_on-hold_to_active', array($this, 'reactivate_subscription_recurring_payment'), 10, 1);
                                        $this->log('Subscription ' . $subscription->get_id() . ' reactivated by IPN.');
                                    }

                                    /**
                                     * Store the EasyProcess recurring request ID on the Woo subscription.
                                     *
                                     * IMPORTANT:
                                     * - For cycle payments, use `recurring_id` (main EP subscription id).
                                     * - For the initial subscription checkout payment, `request_id` may represent the EP subscription id.
                                     * - For manual renewal checkout payments (non-cycle), DO NOT overwrite the existing recurring id
                                     *   with a one-time `request_id`, or cancellation/suspension will target the wrong EP entity.
                                     */
                                    $idem_recurring_request_id = null;
                                    if (!empty($request['recurring_id'])) {
                                        $idem_recurring_request_id = $request['recurring_id'];
                                    } elseif (!$is_renewal) {
                                        // Initial subscription order (not a renewal/cycle): keep legacy behavior.
                                        $idem_recurring_request_id = $request['request_id'];
                                    }

                                    if (!empty($idem_recurring_request_id)) {
                                        $subscription->update_meta_data('_idem_recurring_request_id', $idem_recurring_request_id);
                                    } else {
                                        $this->log('Skipping _idem_recurring_request_id update for subscription #' . $subscription->get_id() . ' (renewal non-cycle payment).');
                                    }

                                    // If this is a renewal order, add reference to it in subscription
                                    if ($is_renewal) {
                                        $subscription->add_order_note(sprintf(
                                            __('Renewal payment completed via IDEM. Renewal order #%d, Transaction ID: %s', 'woocommerce-gateway-idem'),
                                            $order->get_id(),
                                            $request['tr_id']
                                        ));
                                    }

                                    // Update subscription's next payment date from IPN data (cycle payments)
                                    if ($is_cycle_payment && !empty($request['recurring_next'])) {
                                        try {
                                            $new_next_date = $request['recurring_next'];
                                            // Handle both date formats: YYYY-MM-DD and YYYY-MM-DD HH:MM:SS
                                            $timestamp = strtotime($new_next_date);
                                            if ($timestamp) {
                                                // Clear any scheduled payment actions for the old date
                                                if (function_exists('as_unschedule_all_actions')) {
                                                    as_unschedule_all_actions(
                                                        'woocommerce_scheduled_subscription_payment',
                                                        ['subscription_id' => $subscription->get_id()],
                                                        'woocommerce-subscriptions'
                                                    );
                                                }

                                                // Update the subscription's next payment date
                                                $subscription->update_dates(['next_payment' => date('Y-m-d H:i:s', $timestamp)]);

                                                $this->log('Updated subscription #' . $subscription->get_id() . ' next payment date to: ' . date('Y-m-d H:i:s', $timestamp));
                                            }
                                        } catch (Exception $e) {
                                            $this->log('Failed to update subscription next payment date: ' . $e->getMessage());
                                        }
                                    }

                                    $subscription->save();
                                    if (!empty($idem_recurring_request_id)) {
                                        $this->log('Updated subscription #' . $subscription->get_id() . ' with IDEM recurring request ID: ' . $idem_recurring_request_id);
                                    }
                                }
                            }
                            if ($this->complete_status == 'on-hold') {
                                // Set status to on-hold first, before marking as paid
                                $order->update_status('on-hold', __('Payment received, awaiting fulfillment.', 'woocommerce-gateway-idem'));
                                try {
                                    $order->set_transaction_id($request['tr_id']);
                                    $order->set_date_paid(time());
                                    $order->save();
                                } catch (WC_Data_Exception $e) {
                                }
                            } else {
                                $order->payment_complete($request['tr_id']);
                            }
                        } else if ($request['status'] == 'refunded') {
                        }
                        if ($this->send_debug_email) {
                            $subject = "EasyProcess IPN on your site";
                            $body =
                                "Hi,\n\n" .
                                "A EasyProcess transaction has been completed on your website\n" .
                                "------------------------------------------------------------\n" .
                                "Site: " . $vendor_name . " (" . $vendor_url . ")\n" .
                                "Purchase ID: " . $order->get_id() . "\n" .
                                "EasyProcess Request ID: " . $request['request_id'] . "\n" .
                                "EasyProcess Transaction ID: " . $request['tr_id'] . "\n" .
                                "EasyProcess Payment Status: " . $request['status'] . "\n" .
                                "Order Status Code: " . $order->get_status();
                            wp_mail($epDebugEmail, $subject, $body);
                        }
                        break;
                    case 'stopped':
                        $this->log('- Subscription stopped by customer');
                        
                        // Handle subscription cancellation when customer stops it on IDEM platform
                        if (function_exists('wcs_get_subscriptions_for_order')) {
                            $subscriptions = wcs_get_subscriptions_for_order($order);
                            foreach ($subscriptions as $subscription) {
                                $note = __('Subscription cancelled by customer on IDEM platform.', 'woocommerce-gateway-idem');
                                $order->add_order_note($note);
                                $subscription->add_order_note($note);
                                
                                // Temporarily remove the action to prevent an API callback loop
                                remove_action('woocommerce_subscription_status_cancelled', array($this, 'cancel_subscription_recurring_payment'), 10);
                                $subscription->update_status('cancelled', __('Cancelled by customer on IDEM platform.', 'woocommerce-gateway-idem'));
                                add_action('woocommerce_subscription_status_cancelled', array($this, 'cancel_subscription_recurring_payment'), 10, 1);
                                
                                $this->log('Subscription ' . $subscription->get_id() . ' cancelled following customer request on IDEM platform.');
                            }
                        }
                        
                        if ($this->send_debug_email) {
                            $subject = "IDEM Subscription Stopped by Customer";
                            $body =
                                "Hi,\n\n" .
                                "A subscription has been stopped by the customer on IDEM platform\n" .
                                "------------------------------------------------------------\n" .
                                "Site: " . $vendor_name . " (" . $vendor_url . ")\n" .
                                "Order ID: " . $order->get_id() . "\n" .
                                "EasyProcess Request ID: " . ($request['request_id'] ?? 'N/A') . "\n" .
                                "Message: " . ($response['msg'] ?? 'Recurring payment stopped by the customer') . "\n" .
                                "Order Status: " . $order->get_status();
                            wp_mail($epDebugEmail, $subject, $body);
                        }
                        break;
                    case 'ko':
                    case 'error':
                        $this->log('- Failed');

                        // Check if this is a recurring payment failure
                        // 'any' is REQUIRED: on a REPLAYED declined cycle, duplicate
                        // prevention has already substituted the RENEWAL order, and the
                        // default order_type ('parent') resolves no subscription for a
                        // renewal - so the suspension silently did nothing. Same defect
                        // as S14 (QA 2026-08-06), at an occurrence that was missed then;
                        // pre-existing, and only visible once failed cycles reached this
                        // branch again. On a first delivery $order is the parent and both
                        // forms resolve, which is why it went unnoticed.
                        $subscriptions = function_exists('wcs_get_subscriptions_for_order')
                            ? wcs_get_subscriptions_for_order($order, array('order_type' => 'any'))
                            : array();

                        // Branch on what was actually RESOLVED, not on whether the
                        // function exists: WooCommerce Subscriptions can be active and
                        // still return nothing (relation missing, order not linked). The
                        // loop would then iterate zero times and the audit branch below
                        // would never be reached - a silent no-op (review, 2026-08-07).
                        if (!empty($request['recurring_id']) && !empty($subscriptions)) {
                            foreach ($subscriptions as $subscription) {
                                $note = sprintf(__('IDEM recurring payment failed. Status: %s. Subscription put on hold.', 'woocommerce-gateway-idem'), $request['status']);
                                $order->add_order_note($note);
                                $subscription->add_order_note($note);

                                // Temporarily remove the action to prevent an infinite loop
                                remove_action('woocommerce_subscription_status_on-hold', array($this, 'suspend_subscription_recurring_payment'), 10);
                                $subscription->update_status('on-hold', __('Recurring payment failed.', 'woocommerce-gateway-idem'));
                                add_action('woocommerce_subscription_status_on-hold', array($this, 'suspend_subscription_recurring_payment'), 10, 1);

                                $this->log('Subscription ' . $subscription->get_id() . ' put on hold due to failed payment.');
                            }
                        } elseif (!empty($request['recurring_id'])) {
                            // Failed CYCLE with no subscription resolved - either
                            // WooCommerce Subscriptions is inactive, or it is active but
                            // resolved nothing for this order. $order is then still the
                            // PARENT, and failing it would fail the order that CREATED
                            // the subscription because a later period was declined.
                            // Record it and leave the order alone.
                            $this->log('Failed cycle IPN on order #' . $order->get_id() . ' with no resolvable subscription - order left untouched.');
                            // Idempotent like the cycle acknowledgement above: a redelivered
                            // decline must not stack notes on the parent order.
                            $fail_ack_meta = '_idem_cycle_fail_ack_' . $request['request_id'];
                            if (!$order->get_meta($fail_ack_meta)) {
                                $order->add_order_note(sprintf(
                                    /* translators: 1: request id, 2: payment status */
                                    __('A recurring cycle payment failed (request %1$s, status %2$s), but no subscription could be resolved for it. This order was left untouched: a later period must never fail the order that created the subscription.', 'woocommerce-gateway-idem'),
                                    $request['request_id'],
                                    $request['status']
                                ));
                                $order->update_meta_data($fail_ack_meta, time());
                                $order->save();
                            }
                        } else {
                            $order->update_status('failed', sprintf(__('Payment %s via IPN.', 'woocommerce-gateway-idem'), strtolower(sanitize_text_field($request['status']))));
                        }

                        if ($this->send_debug_email) {
                            $subject = "EasyProcess IPN on your site";
                            $body =
                                "Hi,\n\n" .
                                "A failed EasyProcess transaction on your website requires attention\n" .
                                "------------------------------------------------------------\n" .
                                "Site: " . $vendor_name . " (" . $vendor_url . ")\n" .
                                "Purchase ID: " . $order->get_id() . "\n" .
                                "EasyProcess Request ID: " . $request['request_id'] . "\n" .
                                "EasyProcess Transaction ID: " . $request['tr_id'] . "\n" .
                                "EasyProcess Payment Status: " . $request['status'] . "\n" .
                                "Error/Message: " . $response['msg'] . "\n" .
                                $response['info'] . "\n" .
                                "Order Status Code: " . $order->get_status();
                            wp_mail($epDebugEmail, $subject, $body);
                        }
                        break;
                    //case 'pending':
                    //$this->log( '- Pending' );
                    // Need to wait for "Completed" before processing
                    // Don't do this: the same email is send for on-hold.processing, and complete which is not good!
                    // $order->update_status( 'on-hold', sprintf( __( 'Payment %s via IPN.', 'woocommerce-gateway-idem' ), strtolower( sanitize_text_field( $request['status'] ) ) );

                    //break;
                    default:
                        // If unknown status, do nothing (safest course of action)
                        $order->add_order_note(sprintf(__('New EasyProcess IPN received. IPN status: %s Request status: %s. %s', 'woocommerce-gateway-idem'), $response['status'], $request['status'], $response['msg']));
                        break;
                }
            }
        } else if ($processRefund) {
            $this->log('Check order');

            if (empty($order) || empty($response) || empty($request)) {
                // Return a structured error instead of exiting to ensure a valid JSON response
                $this->log('Missing data in refund IPN (order/response/request). Returning error.');
                $epError  = true;
                $epErrMsg = IDEM_ERR_BAD_ACCESS;
                $epErrCode = 'bad-access';
            } elseif (empty($order_key)) {
                $this->log('Missing order key (addinfo) in refund IPN. Returning error instead of exiting.');
                $epError  = true;
                $epErrMsg = IDEM_ERR_NO_SESSION;
                $epErrCode = 'no-session';
            }

            if (! $epError && $this->process_refund_ipn) {
                $order->add_order_note(__('Trying to process refund IPN', 'woocommerce-gateway-idem'));
                if (!empty($request['refund_amount']))
                    $refund_amount = $request['refund_amount'];
                else if (!empty($transaction['tr_amount_refunded']))
                    $refund_amount = $transaction['tr_amount_refunded'];
                else
                    $refund_amount = $request['amount'];
                $this->log('Refund amount: ' . $refund_amount);

                try {
                    $refund = wc_create_refund(array(
                        'amount' => $refund_amount,
                        'reason' => $request['status_infos'] ?? '',
                        'order_id' => $order->get_id(),
                        'refund_payment' => false
                    ));

                    if ($refund instanceof WP_Error) {
                        $order->add_order_note(__('Failed to create a refund:', 'woocommerce-gateway-idem') . ' ' . $refund->get_error_message());
                        $this->log('Failed to create a refund: ' . $refund->get_error_message());
                        // A failed refund must NOT answer "ok" to the platform.
                        $epError   = true;
                        $epErrMsg  = $refund->get_error_message();
                        $epErrCode = 'refund-failed';
                    } else {
                        $order->add_order_note(__('Payment refunded', 'woocommerce-gateway-idem'));
                        $this->log('Payment refunded with refund #' . $refund->get_id());
                    }
                } catch (Exception $e) {
                    $epError  = true;
                    $epErrMsg = $e->getMessage();
                    $epErrCode = 'refund-failed';
                    $this->log('Failed to create a refund: ' . $epErrMsg);
                }
            } elseif (! $epError) {
                $order->add_order_note(__('Ignoring refund IPN', 'woocommerce-gateway-idem'));
            }

            if ($this->send_debug_email) {
                $subject = "EasyProcess Refund IPN on your site";
                $body =
                    "Hi,\n\n" .
                    "A EasyProcess transaction has been refunded on your website\n" .
                    "------------------------------------------------------------\n" .
                    "Site: " . $vendor_name . " (" . $vendor_url . ")\n" .
                    "Purchase ID: " . $order->get_id() . "\n" .
                    "Refund ID: " . (isset($refund) && !($refund instanceof WP_Error) ? $refund->get_id() : "") . "\n" .
                    "EasyProcess Request ID: " . $request['request_id'] . "\n" .
                    "EasyProcess Transaction ID: " . $request['tr_id'] . "\n" .
                    "EasyProcess Payment Status: " . $request['status'] . "\n" .
                    "Order Status Code: " . $order->get_status();
                wp_mail($epDebugEmail, $subject, $body);
            }
        }

        return [
            'status' => $epError ? 'error' : 'ok',
            // Stable machine code; sanitize_title($epErrMsg) kept ONLY as a legacy
            // fallback (it is locale-dependent and must not be relied upon).
            'code' => !empty($epErrCode) ? $epErrCode : sanitize_title((string) $epErrMsg),
            'message' => $epErrMsg,
            'order_id' => $order_id ?? null,
            'order_status' => (!empty($order) && is_a($order, 'WC_Order')) ? $order->get_status() : null,
            'request_status' => $request_status ?? null,
            'platform' => array(
                'wp_version' => get_bloginfo('version'),
                'wc_version' => WC()->version,
                'plugin_version' => $this->version,
            )
        ];
    }

    /**
     * Find or create a renewal order for a cycle payment IPN.
     *
     * When a cycle payment IPN arrives and the ref_id points to the parent order,
     * this method will:
     * 1. Look for an existing pending renewal order for the subscription
     * 2. If none exists, create one using wcs_create_renewal_order()
     *
     * This is a fallback safety mechanism for when WC Subscriptions scheduler
     * hasn't created the renewal order before the payment gateway processes the charge.
     *
     * @param WC_Order $parent_order The parent order from the IPN ref_id
     * @return WC_Order|WP_Error The renewal order to process, or WP_Error on failure
     * @since 4.3.0
     */
    private function find_or_create_renewal_order_for_cycle_payment($parent_order)
    {
        // Verify WC Subscriptions functions are available
        if (!function_exists('wcs_get_subscriptions_for_order') || !function_exists('wcs_create_renewal_order')) {
            $this->log('WooCommerce Subscriptions functions not available for renewal order handling');
            return new WP_Error('wcs_not_available', 'WooCommerce Subscriptions is not active');
        }

        // Get subscriptions for the parent order
        $subscriptions = wcs_get_subscriptions_for_order($parent_order->get_id(), array('order_type' => 'parent'));

        if (empty($subscriptions)) {
            $this->log('No subscriptions found for parent order #' . $parent_order->get_id());
            return new WP_Error('no_subscriptions', 'No subscriptions found for this order');
        }

        // Get the first subscription (usually there's only one per order)
        $subscription = reset($subscriptions);
        $subscription_id = $subscription->get_id();

        $this->log('Processing cycle payment for subscription #' . $subscription_id . ' (parent order #' . $parent_order->get_id() . ')');

        // Check subscription status - only process for active or on-hold subscriptions
        $subscription_status = $subscription->get_status();
        if (!in_array($subscription_status, array('active', 'on-hold', 'pending'))) {
            $this->log('Subscription #' . $subscription_id . ' has status "' . $subscription_status . '" - cannot process renewal');
            return new WP_Error('invalid_subscription_status', 'Subscription is not in a valid status for renewal: ' . $subscription_status);
        }

        // Step 1: Look for existing pending renewal orders
        $renewal_orders = $subscription->get_related_orders('all', 'renewal');

        foreach ($renewal_orders as $renewal_order) {
            // Check if this is a pending/unpaid renewal order
            if (!$renewal_order->get_date_paid() && $renewal_order->has_status(array('pending', 'failed', 'on-hold'))) {
                $this->log('Found existing pending renewal order #' . $renewal_order->get_id() . ' for subscription #' . $subscription_id);
                return $renewal_order;
            }
        }

        // Step 2: No pending renewal order found - create one as fallback
        $this->log('No pending renewal order found for subscription #' . $subscription_id . '. Creating fallback renewal order.');
        $this->log('WARNING: This indicates WC Subscriptions scheduler may not be running properly.');

        try {
            $renewal_order = wcs_create_renewal_order($subscription);

            if (is_wp_error($renewal_order)) {
                $this->log('Failed to create renewal order: ' . $renewal_order->get_error_message());
                return $renewal_order;
            }

            // Ensure payment method is set (wcs_create_renewal_order should copy it, but be explicit)
            if ($renewal_order->get_payment_method() !== $this->id) {
                $renewal_order->set_payment_method($this->id);
                $renewal_order->set_payment_method_title($this->get_title());
            }
            $renewal_order->save();

            // Add a note to both the renewal order and subscription indicating this was a fallback creation
            $note = __('Renewal order created by IDEM IPN fallback mechanism. This indicates WC Subscriptions scheduler did not create the renewal order before the payment was processed.', 'woocommerce-gateway-idem');
            $renewal_order->add_order_note($note);
            $subscription->add_order_note($note . ' Renewal order #' . $renewal_order->get_id());

            $this->log('Successfully created fallback renewal order #' . $renewal_order->get_id() . ' for subscription #' . $subscription_id);

            return $renewal_order;

        } catch (Exception $e) {
            $this->log('Exception creating renewal order: ' . $e->getMessage());
            return new WP_Error('renewal_creation_failed', $e->getMessage());
        }
    }

    /**
     * Find an existing order by IDEM request ID.
     * This prevents duplicate order creation when IPN is received multiple times.
     *
     * @param string $request_id The IDEM request ID to search for.
     * @return WC_Order|null The order if found, null otherwise.
     */
    private function find_order_by_idem_request_id($request_id)
    {
        if (empty($request_id)) {
            return null;
        }

        $orders = wc_get_orders(array(
            'meta_key' => '_idem_request_id',
            'meta_value' => $request_id,
            'limit' => 1,
            'return' => 'objects',
        ));

        if (!empty($orders)) {
            return $orders[0];
        }

        return null;
    }

    /**
     * Setup constants.
     *
     * Setup common values and messages used by the EasyProcess gateway.
     *
     * @since 1.1.0
     */
    public function setup_constants(): void
    {
        //// Create user agent string
        if (!defined('IDEM_SOFTWARE_NAME'))
            define('IDEM_SOFTWARE_NAME', 'WooCommerce');
        if (!defined('IDEM_SOFTWARE_VER'))
            define('IDEM_SOFTWARE_VER', $this->get_woocommerce_version());
        if (!defined('IDEM_MODULE_NAME'))
            define('IDEM_MODULE_NAME', 'WooCommerce-Gateway-Idem');
        if (!defined('IDEM_MODULE_VER'))
            define('IDEM_MODULE_VER', $this->version);

        // Features
        // - PHP
        $epFeatures = 'PHP ' . phpversion() . ';';

        // - cURL
        if (in_array('curl', get_loaded_extensions())) {
            if (!defined('IDEM_CURL'))
                define('IDEM_CURL', '');
            $epVersion = curl_version();
            $epFeatures .= ' curl ' . $epVersion['version'] . ';';
        } else {
            $epFeatures .= ' nocurl;';
        }

        // Create user agrent
        if (!defined('IDEM_USER_AGENT'))
            define('IDEM_USER_AGENT', IDEM_SOFTWARE_NAME . '/' . IDEM_SOFTWARE_VER . ' (' . trim($epFeatures) . ') ' . IDEM_MODULE_NAME . '/' . IDEM_MODULE_VER);

        // General Defines
        if (!defined('IDEM_TIMEOUT'))
            define('IDEM_TIMEOUT', 15);
        if (!defined('IDEM_EPSILON'))
            define('IDEM_EPSILON', 0.01);

        // Messages
        // Error
        if (!defined('IDEM_ERR_ORDER_NOT_FOUND'))
            define('IDEM_ERR_ORDER_NOT_FOUND', __('No order found with this reference', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_REF_NOT_ORDER'))
            define('IDEM_ERR_REF_NOT_ORDER', __('Reference not an order', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_AMOUNT_MISMATCH'))
            define('IDEM_ERR_AMOUNT_MISMATCH', __('Amount mismatch', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_ORDER_TOTAL_DRIFT'))
            define('IDEM_ERR_ORDER_TOTAL_DRIFT', __('Order total changed after the payment was signed', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_BAD_ACCESS'))
            define('IDEM_ERR_BAD_ACCESS', __('Bad access of page', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_BAD_SOURCE_IP'))
            define('IDEM_ERR_BAD_SOURCE_IP', __('Bad source IP address', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_CONNECT_FAILED'))
            define('IDEM_ERR_CONNECT_FAILED', __('Failed to connect to EasyProcess', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_INVALID_SIGNATURE'))
            define('IDEM_ERR_INVALID_SIGNATURE', __('Security signature mismatch', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_MERCHANT_ID_MISMATCH'))
            define('IDEM_ERR_MERCHANT_ID_MISMATCH', __('Merchant ID mismatch', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_NO_SESSION'))
            define('IDEM_ERR_NO_SESSION', __('No saved session found for IPN transaction', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_ORDER_ID_MISSING_URL'))
            define('IDEM_ERR_ORDER_ID_MISSING_URL', __('Order ID not present in URL', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_ORDER_ID_MISMATCH'))
            define('IDEM_ERR_ORDER_ID_MISMATCH', __('Order ID mismatch', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_ORDER_INVALID'))
            define('IDEM_ERR_ORDER_INVALID', __('This order ID is invalid', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_ORDER_NUMBER_MISMATCH'))
            define('IDEM_ERR_ORDER_NUMBER_MISMATCH', __('Order Number mismatch', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_ORDER_PROCESSED'))
            define('IDEM_ERR_ORDER_PROCESSED', __('This order has already been processed', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_PDT_FAIL'))
            define('IDEM_ERR_PDT_FAIL', __('PDT query failed', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_PDT_TOKEN_MISSING'))
            define('IDEM_ERR_PDT_TOKEN_MISSING', __('PDT token not present in URL', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_SESSIONID_MISMATCH'))
            define('IDEM_ERR_SESSIONID_MISMATCH', __('Session ID mismatch', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_ERR_UNKNOWN'))
            define('IDEM_ERR_UNKNOWN', __('Unkown error occurred', 'woocommerce-gateway-idem'));

        // General
        if (!defined('IDEM_MSG_OK'))
            define('IDEM_MSG_OK', __('Payment was successful', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_MSG_FAILED'))
            define('IDEM_MSG_FAILED', __('Payment has failed', 'woocommerce-gateway-idem'));
        if (!defined('IDEM_MSG_PENDING'))
            define(
                'IDEM_MSG_PENDING',
                __('The payment is pending. Please note, you will receive another notification', 'woocommerce-gateway-idem') .
                    __(' when the payment status changes to', 'woocommerce-gateway-idem') .
                    __(' "Completed", or "Failed"', 'woocommerce-gateway-idem')
            );
    }

    /**
     * Log system processes in test mode.
     * @since 1.1.0
     */
    public function log($message, $debug = false): void
    {
        if ($debug) {
            // Verbose logging
            if ('yes' === $this->get_option('verbose_logging'))
                wc_get_logger()->debug($message, ["source" => "idem"]);
            return;
        }
        wc_get_logger()->info($message, ["source" => "idem"]);
    }

    function get_woocommerce_version()
    {
        return WC()->version;
    }

    /**
     * validate_signature()
     *
     * Validate the signature against the returned data.
     *
     * @param array $data
     * @return bool
     * @since 1.1.0
     */
    public function validate_signature(array $data): bool
    {
        // validate_response_data() has already guaranteed MerchantApiResponse,
        // PlatformRequest, request_id and status. amount, currency and hash stay
        // optional on the wire (null values are dropped by the form encoding), so
        // read them defensively - concatenating an absent key yields '' either
        // way, so the hash computed here is unchanged.
        $response   = $data['MerchantApiResponse'];
        $request    = $response['PlatformRequest'];
        $amount     = isset($request['amount']) ? $request['amount'] : '';
        $currency   = isset($request['currency']) ? $request['currency'] : '';
        $sent_hash  = isset($response['hash']) ? $response['hash'] : '';
        $string     = $request['request_id'] . $amount . $currency . $request['status'] . $this->merchant_key;
        $myhash     = md5($string);
        if ($myhash != $sent_hash) {
            $this->log("Hash verification failed. MyHash: " . $myhash . " Hash: " . $sent_hash);
            $this->log("Hash String: " . $string, true);
            return false;
        }
        return true;
    }

    /**
     * Get valid IDEM API IP addresses
     *
     * @return array|WP_Error Array of valid IP addresses or WP_Error on failure
     */
    private function get_valid_ips()
    {
        $response = wp_remote_get('https://www.easyprocess.ai/api/idem/ip');

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !isset($data['data']) || !is_array($data['data'])) {
            return new WP_Error(
                'idem_invalid_ip_response',
                __('Invalid response from IDEM IP endpoint', 'woocommerce-gateway-idem')
            );
        }

        return $data['data'];
    }

    /**
     * Get latest version of the plugin
     *
     * @return string|WP_Error Array of valid IP addresses or WP_Error on failure
     */
    private function get_latest_version()
    {
        $response = wp_remote_get('https://www.easyprocess.ai/api/idem/woo-plugin-version');

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !isset($data['data']) || !is_string($data['data'])) {
            return new WP_Error(
                'idem_invalid_version_response',
                __('Invalid response from IDEM version endpoint', 'woocommerce-gateway-idem')
            );
        }

        return $data['data']; //Expected format: 1.0.0
    }

    /**
     * Display admin notice if API credentials are missing
     */
    public function check_version(): void
    {
        // Only show notice if gateway is enabled
        if ('yes' !== $this->get_option('enabled')) {
            return;
        }

        // Cache version check to avoid HTTP request on every admin page load
        $transient_key = 'idem_latest_version';
        $latest_version = get_transient($transient_key);

        if (false === $latest_version) {
            $latest_version = $this->get_latest_version();
            if (is_wp_error($latest_version)) {
                $this->log('Error checking latest version: ' . $latest_version->get_error_message());
                // Cache the failure for 1 hour to avoid hammering the API
                set_transient($transient_key, 'error', HOUR_IN_SECONDS);
                return;
            }
            set_transient($transient_key, $latest_version, DAY_IN_SECONDS);
        }

        if ('error' === $latest_version) {
            return;
        }

        $current_version = $this->version;
        if (version_compare($current_version, $latest_version, '<')) {
            $this->log('New version available: ' . $latest_version);

            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>' . __('IDEM Payments Gateway', 'woocommerce-gateway-idem') . '</strong></p>';
            echo '<p>' . sprintf(
                /* translators: 1: Missing credentials list, 2: Settings URL */
                __('A new version of the IDEM Payments Gateway is available. Please <a href="%1$s">download the new version</a> and update the plugin.', 'woocommerce-gateway-idem'),
                esc_url("https://www.easyprocess.ai/files/documents/gateway-easyprocess-idem.zip")
            ) . '</p>';
            echo '</div>';
        }
    }


    /**
     * validate_ip()
     *
     * Validate the IP address to make sure it's coming from EasyProcess.
     *
     * @param string $sourceIP
     * @return bool
     * @since 1.1.0
     */
    public function validate_ip(string $sourceIP): bool
    {
        // Local IP (for dev and sites hosted on same servers than EP)
        if (! filter_var($sourceIP, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            return true;
        $valid_ips = $this->get_valid_ips();
        if (is_wp_error($valid_ips)) {
            error_log(sprintf(
                '[WC_Idem_Payments] Error fetching valid IPs: %s',
                $valid_ips->get_error_message()
            ));
            return false;
        }
        $this->log("Valid IPs:\n" . json_encode($valid_ips), true);
        return in_array($sourceIP, $valid_ips);
    }

    /**
     * Has this order already been settled, so that the nominal path must not run again?
     *
     * One-off payment: is_paid() covers processing AND completed - a normally-paid Woo
     * order is usually "processing", and a second, different request must NOT overwrite
     * the original payment's identifiers. 'on-hold' and 'refunded' are terminal too:
     * is_paid() is status-based and covers neither, so without them a replay would
     * re-enter the nominal path and rewrite payment metas.
     *
     * CYCLE: judged on PAID HISTORY, not on the current status.
     * - Cycles used to be excluded from this guard entirely, because a cycle IPN landed
     *   on the already-paid PARENT order and the guard would have stopped the renewal
     *   from ever being paid. The cycle guard upstream now intercepts that case, so by
     *   this point $order IS the renewal order - and a replayed cycle must be blocked,
     *   otherwise it re-runs the nominal path and re-adds a note every time (S17).
     * - But status alone is not enough. get_date_paid() is what separates the two
     *   renewals that both sit in 'on-hold': one never paid, which
     *   find_or_create_renewal_order_for_cycle_payment() deliberately returns for the
     *   cycle payment to RESCUE, and one paid earlier - which a store using the
     *   'on-hold' complete_status produces on every successful payment (:606-614,
     *   :2272-2280). Judging that one on is_paid() alone let a replay re-run the whole
     *   nominal path: notes, metas, subscription activation, date_paid (found in
     *   review, 2026-08-07). A refunded renewal has a paid date too, so it is covered.
     * - Only 'completed' is judged here: a FAILED cycle must reach the failure branch
     *   that puts the subscription on hold, even when the parent order is paid.
     *
     * @param WC_Order $order
     * @param array $request
     * @param string $request_status
     * @return bool
     */
    private function ipn_already_processed($order, array $request, $request_status): bool
    {
        if (!empty($request['recurring_id'])) {
            return 'completed' === $request_status
                && ($order->is_paid() || (bool) $order->get_date_paid());
        }

        return $order->is_paid() || $order->has_status(array('on-hold', 'refunded'));
    }

    /**
     * validate_response_data()
     *
     * Structural check on the IPN payload, run BEFORE validate_signature() and
     * before anything reads the request: both of those dereference
     * MerchantApiResponse.PlatformRequest.* with no guard at all, and signature
     * verification is disableable per merchant (validate_ipn_signature), so
     * without this nothing at all checks the SHAPE of what arrives.
     *
     * Only what a legitimate payload ALWAYS carries is required: the platform
     * posts form-encoded, and PHP drops null values on the way, so every
     * optional field (ref_id, addinfo, tr_id, hash, PlatformTransaction, the FX
     * fields...) can legitimately be absent and is already handled as such
     * downstream. request_id and status are the two the platform always fills
     * and without which no decision can be made at all.
     *
     * @param $post_data array Data received
     * @return bool
     * @since 1.1.0
     */
    public function validate_response_data(array $post_data): bool
    {
        if (empty($post_data['MerchantApiResponse']) || !is_array($post_data['MerchantApiResponse'])) {
            $this->log('IPN payload has no MerchantApiResponse object - refusing');
            return false;
        }
        if (empty($post_data['MerchantApiResponse']['PlatformRequest'])
            || !is_array($post_data['MerchantApiResponse']['PlatformRequest'])) {
            $this->log('IPN payload has no PlatformRequest object - refusing');
            return false;
        }
        $request = $post_data['MerchantApiResponse']['PlatformRequest'];
        foreach (array('request_id', 'status') as $field) {
            if (!isset($request[$field]) || !is_scalar($request[$field]) || '' === trim((string) $request[$field])) {
                $this->log('IPN payload has no usable ' . $field . ' - refusing');
                return false;
            }
        }
        return true;
    }

    /**
     * Check if an order was paid via any IDEM gateway (main or crypto)
     *
     * @param WC_Order $order
     * @return bool
     */
    public function is_idem_order($order): bool
    {
        return in_array($order->get_payment_method(), ['idem', 'idem_crypto']);
    }

    /**
     * amounts_equal()
     *
     * Checks to see whether the given amounts are equal using a proper floating
     * point comparison with an Epsilon which ensures that insignificant decimal
     * places are ignored in the comparison.
     *
     * eg: 100.00 is equal to 100.0001
     *
     * @param $amount1 float Float 1st amount for comparison
     * @param $amount2 float Float 2nd amount for comparison
     * @return bool
     * @since 1.1.0
     */
    public function amounts_equal(float $amount1, float $amount2): bool
    {
        return ! (abs($amount1 -  $amount2) > IDEM_EPSILON);
    }

    /**
     * Strict amount comparison at the currency's minor-unit precision (floor
     * of 2), not the store's display precision.
     *
     * amounts_equal() tolerates an absolute epsilon of 0.01, which considers
     * 9.99 and 10.00 equal - a one-cent underpayment would silently pass a
     * payment decision. Payment decisions MUST compare amounts exactly at the
     * currency's minor unit: normalize both sides to fixed-decimal strings and
     * require strict equality.
     *
     * @param float|string $amount1
     * @param float|string $amount2
     * @return bool
     */
    public function amounts_identical($amount1, $amount2): bool
    {
        // A money decision must never depend on a DISPLAY setting:
        // wc_get_price_decimals() returns the store's woocommerce_price_num_decimals
        // option, so a store configured to show 0 decimals would make 9.60 and 10.00
        // compare equal and auto-complete an underpaid order. Floor at 2 - the minor
        // unit of every currency this gateway supports - while letting a store
        // configured finer than that keep its finer precision.
        $decimals = max(2, (int) wc_get_price_decimals());
        return number_format((float) $amount1, $decimals, '.', '')
            === number_format((float) $amount2, $decimals, '.', '');
    }

    /**
     * Maybe hide the gateway for non-admin users
     * 
     * @param array $available_gateways Array of available payment gateways
     * @return array Modified array of available payment gateways
     */
    public function maybe_hide_gateway_for_non_admin($available_gateways)
    {
        if (!is_admin() && isset($available_gateways[$this->id])) {
            // Check if gateway should be hidden for non-admin users
            if ('yes' === $this->get_option('hide_for_non_admin_users') && !current_user_can('manage_options')) {
                unset($available_gateways[$this->id]);
            }

            // Hide main gateway when crypto_only_mode is forced
            if (isset($available_gateways[$this->id])) {
                $config = $this->get_cached_woocommerce_config();
                if (isset($config['crypto_only_mode']) && $config['crypto_only_mode'] === 'forced') {
                    unset($available_gateways[$this->id]);
                }
            }
        }
        return $available_gateways;
    }

    /**
     * Process a refund if supported.
     *
     * @param int    $order_id Order ID
     * @param float  $amount   Refund amount
     * @param string $reason   Refund reason
     * @return bool|WP_Error True or false based on success, or a WP_Error object
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('invalid_order', __('Invalid order ID', 'woocommerce-gateway-idem'));
        }

        // Check if refund with API is enabled
        if ('yes' !== $this->get_option('refund_with_api')) {
            return new WP_Error(
                'refund_api_disabled',
                __('Refund with API is disabled in gateway settings.', 'woocommerce-gateway-idem')
            );
        }

        // Check for required API credentials
        $api_key = $this->get_env_option('api_token');
        $secret_key = $this->get_env_option('secret_key');
        if (empty($api_key) || empty($secret_key)) {
            return new WP_Error(
                'missing_api_credentials',
                __('IDEM API credentials are required for refunds. Please configure them in the gateway settings.', 'woocommerce-gateway-idem')
            );
        }

        // Get the original transaction ID
        $transaction_id = $order->get_meta('_idem_request_id');
        if (empty($transaction_id)) {
            return new WP_Error(
                'missing_transaction_id',
                __('Original transaction ID not found.', 'woocommerce-gateway-idem')
            );
        }

        try {
            // Initialize API client
            $api_client = new WC_Idem_API_Client(
                $api_key,
                'yes' === $this->get_option('test_mode'),
                wc_get_logger()
            );

            $this->log(sprintf(
                'Processing refund for order #%d. Amount: %s, Reason: %s, Transaction ID: %s',
                $order_id,
                $amount,
                $reason,
                $transaction_id
            ));

            // Call the refund API
            $response = $api_client->refund_request(
                $transaction_id,
                $secret_key,
                $reason,
                $amount,
                $order->get_currency()
            );

            if (is_wp_error($response)) {
                $this->log('Refund failed: ' . $response->get_error_message());
                return $response;
            }

            // Check response status for errors
            if (isset($response['status']) && ($response['status'] === 'KO' || $response['status'] === 'ERROR')) {
                $error_message = isset($response['msg']) ? $response['msg'] : __('Unknown error', 'woocommerce-gateway-idem');
                $this->log('Refund failed: ' . $error_message);
                return new WP_Error('refund_failed', $error_message);
            }

            // Add note to the order
            $order->add_order_note(
                sprintf(
                    __('Refund of %1$s initiated via IDEM API. Reason: %2$s', 'woocommerce-gateway-idem'),
                    wc_price($amount),
                    $reason
                )
            );

            $this->log('Refund processed successfully');
            return true;
        } catch (Exception $e) {
            $this->log('Refund failed with exception: ' . $e->getMessage());
            return new WP_Error('refund_error', $e->getMessage());
        }
    }

    /**
     * Display admin notice if API credentials are missing
     */
    public function check_api_credentials(): void
    {
        // Only show notice if gateway is enabled
        if ('yes' !== $this->get_option('enabled')) {
            return;
        }

        // Check if we're missing any credentials
        $missing_credentials = array();

        // Read live via get_env_option() (not the constructor-time $this->merchant_id/_key
        // snapshot) so the notice reflects the just-saved active environment and doesn't flash
        // a stale "missing" right after a Mode change is saved.
        if (empty($this->get_env_option('merchant_id'))) {
            $missing_credentials[] = __('Merchant ID', 'woocommerce-gateway-idem');
        }

        if (empty($this->get_env_option('merchant_key'))) {
            $missing_credentials[] = __('Encryption / Hash Key', 'woocommerce-gateway-idem');
        }

        // Check API credentials
        if (empty($this->get_env_option('api_token'))) {
            $missing_credentials[] = __('IDEM API Key', 'woocommerce-gateway-idem');
        }
        if (empty($this->get_env_option('secret_key'))) {
            $missing_credentials[] = __('IDEM Secret Key', 'woocommerce-gateway-idem');
        }

        if (!empty($missing_credentials)) {
            $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=idem');

            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>' . __('IDEM Payments Gateway', 'woocommerce-gateway-idem') . '</strong></p>';
            echo '<p>' . sprintf(
                /* translators: 1: Missing credentials list, 2: Settings URL */
                __('The following credentials are missing: %1$s. Please <a href="%2$s">configure the plugin</a> to enable payments.', 'woocommerce-gateway-idem'),
                implode(', ', $missing_credentials),
                esc_url($settings_url)
            ) . '</p>';
            echo '</div>';
        }
    }

    /**
     * Record that an order was resolved by the fallback pull without a payment IPN having arrived —
     * a symptom that our IPNs may be silently blocked by the store's firewall/WAF. Stored as an
     * order_id => timestamp map so re-checks are idempotent and a later IPN can retract the entry.
     *
     * @param int $order_id
     */
    private function record_ipn_missing($order_id)
    {
        $log = get_option('wc_idem_ipn_missing', array());
        if (!is_array($log)) { $log = array(); }
        $log[(int) $order_id] = time();
        // Prune entries older than 30 days and cap the map so it can't grow unbounded.
        $cutoff = time() - (30 * DAY_IN_SECONDS);
        foreach ($log as $oid => $ts) {
            if ($ts < $cutoff) { unset($log[$oid]); }
        }
        if (count($log) > 100) {
            asort($log);
            $log = array_slice($log, -100, null, true);
        }
        update_option('wc_idem_ipn_missing', $log, false);
    }

    /**
     * Retract an order's "IPN missing" record once its IPN is finally received.
     *
     * @param int $order_id
     */
    private function clear_ipn_missing($order_id)
    {
        $log = get_option('wc_idem_ipn_missing', array());
        if (is_array($log) && isset($log[(int) $order_id])) {
            unset($log[(int) $order_id]);
            update_option('wc_idem_ipn_missing', $log, false);
        }
    }

    /**
     * Admin notice: warn when several recent paid orders were confirmed only by the fallback pull
     * because no IPN arrived — the store's firewall/WAF is likely blocking our servers. Self-clears
     * once IPNs come through again (entries get retracted, dropping the count below the threshold).
     */
    public function check_ipn_health()
    {
        if ('yes' !== $this->get_option('enabled')) {
            return;
        }
        $log = get_option('wc_idem_ipn_missing', array());
        if (!is_array($log) || empty($log)) {
            return;
        }
        $cutoff = time() - (7 * DAY_IN_SECONDS);
        $recent = array_filter($log, function ($ts) use ($cutoff) { return $ts >= $cutoff; });
        if (count($recent) < 3) {  // threshold: surface a pattern, not the odd slow/late IPN
            return;
        }

        $ip_html = array();
        foreach ($this->get_whitelist_ips() as $ip) {
            $ip_html[] = '<code>' . esc_html($ip) . '</code>';
        }
        $path = '/?wc-api=WC_Gateway_Idem';

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>' . esc_html__('IDEM Payments — payment notifications may be blocked', 'woocommerce-gateway-idem') . '</strong></p>';
        echo '<p>' . sprintf(
            /* translators: %d: number of affected orders in the last 7 days */
            esc_html__('%d recent paid orders were confirmed by a backup check because no payment notification (IPN) reached your store. Your firewall or a security service (e.g. Cloudflare) is likely blocking our servers, which delays order updates.', 'woocommerce-gateway-idem'),
            (int) count($recent)
        ) . '</p>';
        echo '<p>' . sprintf(
            /* translators: %s: the IPN endpoint path */
            esc_html__('Please allow our notification servers in your firewall / WAF for the IPN path %s:', 'woocommerce-gateway-idem'),
            '<code>' . esc_html($path) . '</code>'
        ) . '</p>';
        echo '<p>' . implode(' &nbsp; ', $ip_html) . '</p>';
        echo '</div>';
    }

    /**
     * Our notification-server IP addresses to whitelist, fetched from the platform (cached 24h) with
     * a hard-coded fallback so the notice still works even if that fetch is itself blocked.
     *
     * @return string[]
     */
    private function get_whitelist_ips()
    {
        $fallback = array('18.205.86.169', '3.221.82.4', '54.225.180.167', '34.237.159.110', '82.66.185.208');
        $cached = get_transient('wc_idem_ipn_ips');
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }
        try {
            $client = new WC_Idem_API_Client(
                $this->get_env_option('api_token'),
                'yes' === $this->get_option('test_mode'),
                null,
                $this->get_option('test_url')
            );
            if (method_exists($client, 'set_prod_url')) {
                $client->set_prod_url($this->get_option('gateway_url'));
            }
            $res = $client->get_ips();
            if (!is_wp_error($res) && !empty($res['data']) && is_array($res['data'])) {
                set_transient('wc_idem_ipn_ips', $res['data'], DAY_IN_SECONDS);
                return $res['data'];
            }
        } catch (Exception $e) {
            // fall through to the hard-coded list
        }
        return $fallback;
    }

    /**
     * Get the current plugin settings
     * 
     * @return array Current settings with sensitive data masked
     */
    public function get_settings(): array
    {
        $settings = $this->settings;

        // Mask sensitive data (legacy flat keys + per-environment credential blocks)
        $sensitive_fields = [
            'merchant_key',
            'idem_api_key',
            'idem_secret_key',
            'sandbox_merchant_key',
            'sandbox_api_token',
            'sandbox_secret_key',
            'prod_merchant_key',
            'prod_api_token',
            'prod_secret_key',
        ];

        foreach ($sensitive_fields as $field) {
            if (!empty($settings[$field])) {
                $settings[$field] = '••••••••' . substr($settings[$field], -4);
            }
        }

        // Add additional useful information
        $settings['notification_url'] = $this->response_url;
        $settings['platform'] = [
            'wp_version' => get_bloginfo('version'),
            'wc_version' => WC()->version,
            'plugin_version' => $this->version,
            'currency' => get_woocommerce_currency(),
            'test_mode' => 'yes' === $this->get_option('test_mode'),
            'verbose_logging' => 'yes' === $this->get_option('verbose_logging'),
        ];

        // Add validation settings
        $settings['validation'] = [
            'ip' => 'yes' === $this->get_option('validate_ipn_ip'),
            'signature' => 'yes' === $this->get_option('validate_ipn_signature'),
        ];

        // Add gateway status
        $ipn_missing = get_option('wc_idem_ipn_missing', array());
        $settings['status'] = [
            'enabled' => 'yes' === $this->enabled,
            'ready' => $this->is_valid_for_use(),
            'available_currencies' => $this->available_currencies,
            // Orders resolved by the fallback pull with no payment IPN received (order_id => when)
            'ipn_missing' => is_array($ipn_missing) ? array_map(function ($ts) { return gmdate('c', (int) $ts); }, $ipn_missing) : [],
        ];

        return $settings;
    }

    /**
     * Get order data for API response
     * 
     * @param int  $order_id   Order ID
     * @param bool $with_lines Also return line_items / coupon_lines / fee_lines / shipping_lines
     *                         (opt-in: find_in_notes calls this in a loop over many orders)
     * @return array|WP_Error Order data or error
     */
    public function get_order($order_id, $with_lines = false)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error(
                'order_not_found',
                __('Order not found', 'woocommerce-gateway-idem')
            );
        }

        // Basic order data
        $order_data = [
            'id' => $order->get_id(),
            'number' => $order->get_order_number(),
            'key' => $order->get_order_key(),
            'created_at' => $order->get_date_created()->format('c'),
            'updated_at' => $order->get_date_modified()->format('c'),
            'date_paid' => $order->get_date_paid() ? $order->get_date_paid()->format('c') : null,
            'status' => $order->get_status(),
            'currency' => $order->get_currency(),
            'total' => $order->get_total(),
            'subtotal' => $order->get_subtotal(),
            'total_tax' => $order->get_total_tax(),
            'shipping_total' => $order->get_shipping_total(),
            'discount_total' => $order->get_discount_total(),
            'payment_method' => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'transaction_id' => $order->get_transaction_id(),
            'customer_note' => $order->get_customer_note(),
        ];

        // Safety-net record: when the order was resolved by the fallback pull because no
        // payment IPN ever arrived (see record_ipn_missing), the timestamp is exposed here.
        $ipn_missing = get_option('wc_idem_ipn_missing', array());
        $order_data['ipn_missing'] = (is_array($ipn_missing) && isset($ipn_missing[$order->get_id()]))
            ? gmdate('c', (int) $ipn_missing[$order->get_id()])
            : null;

        // Customer data
        $order_data['customer'] = [
            'id' => $order->get_customer_id(),
            'ip_address' => $order->get_customer_ip_address(),
            'user_agent' => $order->get_customer_user_agent(),
            'billing' => [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'company' => $order->get_billing_company(),
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
            ],
            'shipping' => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'company' => $order->get_shipping_company(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
            ],
        ];

        // Meta data
        $meta_data = [];
        foreach ($order->get_meta_data() as $meta) {
            // Only include IDEM-related meta
            if (strpos($meta->key, '_idem_') === 0) {
                // The signed-lines history is opt-in like the current lines (find_in_notes
                // full_details=1 calls get_order for every matching order).
                if ($meta->key === '_idem_signed_lines' && !$with_lines) {
                    continue;
                }
                $meta_data[$meta->key] = $meta->value;
            }
        }
        if (!empty($meta_data)) {
            $order_data['meta'] = $meta_data;
        }

        // Add refunds if any
        $refunds = $order->get_refunds();
        if (!empty($refunds)) {
            $order_data['refunds'] = array_map(function ($refund) {
                return [
                    'id' => $refund->get_id(),
                    'reason' => $refund->get_reason(),
                    'amount' => $refund->get_amount(),
                    'date_created' => $refund->get_date_created()->format('c'),
                ];
            }, $refunds);
        }

        if ($with_lines) {
            $order_data = array_merge($order_data, $this->get_order_lines($order));
        }

        return $order_data;
    }

    /**
     * Order lines in the shape shared by get_order (with_lines=1) and the signature-time
     * snapshot (_idem_signed_lines): per item the subtotal/total pair is what makes a
     * discount visible, coupons and fees are what usually moves an order total.
     *
     * @param WC_Order $order
     * @return array line_items, coupon_lines, fee_lines, shipping_lines
     */
    private function get_order_lines($order)
    {
        $lines = [
            'line_items'     => [],
            'coupon_lines'   => [],
            'fee_lines'      => [],
            'shipping_lines' => [],
        ];
        foreach ($order->get_items('line_item') as $item) {
            $lines['line_items'][] = [
                'id'           => $item->get_id(),
                'name'         => $item->get_name(),
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'quantity'     => $item->get_quantity(),
                'subtotal'     => $item->get_subtotal(),
                'total'        => $item->get_total(),
                'total_tax'    => $item->get_total_tax(),
            ];
        }
        foreach ($order->get_items('coupon') as $item) {
            $lines['coupon_lines'][] = [
                'id'           => $item->get_id(),
                'code'         => $item->get_code(),
                'discount'     => $item->get_discount(),
                'discount_tax' => $item->get_discount_tax(),
            ];
        }
        foreach ($order->get_items('fee') as $item) {
            $lines['fee_lines'][] = [
                'id'        => $item->get_id(),
                'name'      => $item->get_name(),
                'total'     => $item->get_total(),
                'total_tax' => $item->get_total_tax(),
            ];
        }
        foreach ($order->get_items('shipping') as $item) {
            $lines['shipping_lines'][] = [
                'id'           => $item->get_id(),
                'method_id'    => $item->get_method_id(),
                'method_title' => $item->get_method_title(),
                'total'        => $item->get_total(),
                'total_tax'    => $item->get_total_tax(),
            ];
        }
        return $lines;
    }

    /**
     * Totals + lines of the order as they stand right now, stored next to each signed
     * amount (_idem_signed_lines) so a later drift can be diffed line by line.
     *
     * @param WC_Order $order
     * @return array
     */
    private function get_order_lines_snapshot($order)
    {
        $lines = $this->get_order_lines($order);
        // Bound what is rewritten on every form generation (five times over): a wholesale or
        // imported cart with hundreds of lines would otherwise store hundreds of KB per order.
        $count = count($lines['line_items']);
        if ($count > 100) {
            $lines['line_items']           = array_slice($lines['line_items'], 0, 100);
            $lines['line_items_truncated'] = true;
            $lines['line_items_count']     = $count;
        }
        return array_merge([
            'totals' => [
                'subtotal'       => (string) $order->get_subtotal(),
                'discount_total' => (string) $order->get_discount_total(),
                'shipping_total' => (string) $order->get_shipping_total(),
                'total_tax'      => (string) $order->get_total_tax(),
                'total'          => (string) $order->get_total(),
            ],
        ], $lines);
    }

    /**
     * Get the latest logs from the IDEM gateway
     * 
     * @param int $limit Maximum number of log entries to return (default 100)
     * @return array Array of log entries
     */
    public function get_logs(int $limit = 100): array
    {
        try {
            // Get logger instance
            wc_get_logger();
            $handler = new WC_Log_Handler_File();
            $files = $handler->get_log_files();

            // Look for the most recent idem log file
            $idem_logs = array_filter($files, function ($file) {
                return strpos($file, 'idem-') === 0;
            });

            if (empty($idem_logs)) {
                return [
                    'status' => 'error',
                    'message' => 'No log files found'
                ];
            }

            // Get log directory path
            $log_dir = '';
            if (defined('WC_LOG_DIR')) {
                $log_dir = constant('WC_LOG_DIR');
            } else {
                // Fallback for older WooCommerce versions or when constant is not defined
                $upload_dir = wp_upload_dir();
                $log_dir = $upload_dir['basedir'] . '/wc-logs/';
            }

            if (!is_dir($log_dir)) {
                return [
                    'status' => 'error',
                    'message' => 'Log directory not found'
                ];
            }

            // Sort files by date (newest first)
            usort($idem_logs, function ($a, $b) use ($log_dir) {
                return filemtime($log_dir . $b) - filemtime($log_dir . $a);
            });

            // Get the most recent log file
            $latest_log = $log_dir . reset($idem_logs);

            if (!file_exists($latest_log)) {
                return [
                    'status' => 'error',
                    'message' => 'Log file not found'
                ];
            }

            // Read the log file
            $logs = array_filter(
                array_map('trim', file($latest_log)),
                function ($line) {
                    return !empty($line);
                }
            );

            // Get the last n entries
            $logs = array_slice($logs, -$limit);

            // Parse the logs into a structured format
            $formatted_logs = array_map(function ($line) {
                $matches = [];
                if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2} ([A-Z]+) (.+)$/', $line, $matches)) {
                    return [
                        'timestamp' => substr($line, 0, 25),
                        'level' => $matches[1],
                        'message' => $matches[2]
                    ];
                }
                return [
                    'timestamp' => null,
                    'level' => 'UNKNOWN',
                    'message' => $line
                ];
            }, $logs);

            return [
                'status' => 'ok',
                'file' => basename($latest_log),
                'entries' => $formatted_logs
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Verify payment status for an order using IDEM API
     *
     * @param int|WC_Order $order
     * @return bool True if payment is verified, false otherwise
     */
    public function verify_payment_status($order)
    {
        if (is_int($order)) {
            $order = wc_get_order($order);
        }

        if (!$order) {
            return false;
        }

        // Don't check if order is not pending payment
        if ($order->get_status() !== 'pending') {
            return false;
        }

        try {
            $api_client = new WC_Idem_API_Client(
                $this->get_env_option('api_token'),
                'yes' === $this->get_option('test_mode'),
                wc_get_logger(),
                $this->get_option('test_url')
            );

            // Search for requests with this order ID
            $search_result = $api_client->search_requests([
                'reference' => $order->get_id(),
                'status' => 'completed'
            ]);

            if (is_wp_error($search_result)) {
                $this->log('Payment verification failed: ' . $search_result->get_error_message());
                return false;
            }

            // If we find a completed payment, process it
            if (!empty($search_result['data']['requests'])) {
                $signed_attempts = $order->get_meta('_idem_signed_attempts');
                $live_total      = (float) $order->get_total();
                // A completable result anywhere in this page wins over a parkable one,
                // whatever order the API returned them in. The search is not paginated
                // here, so that guarantee spans the first page only - which is safe:
                // the worst case is parking an order a later page could have completed,
                // never completing one that should have been parked.
                $park_candidate  = null;
                foreach ($search_result['data']['requests'] as $request) {
                    // A cycle pays a RENEWAL, never the order the subscription was created
                    // for. It carries that parent order's reference - so it shows up in this
                    // search - and the platform now echoes the parent's addinfo on it too,
                    // so it would correlate below and could complete a pending parent with a
                    // renewal's payment, at the same amount for a constant subscription.
                    // Skip it outright: this path exists to settle the order it searched for.
                    // Unlike the status guard below, an absent field is treated as "not a
                    // cycle" rather than failing closed. That is deliberate, and safe by
                    // construction: a platform old enough to omit is_a_cycle also predates the
                    // addinfo propagation, so its cycles carry no addinfo and the correlation
                    // guard right below skips them anyway. Failing closed, by contrast, would
                    // skip EVERY request against such a platform and break the whole fallback.
                    if (!empty($request['is_a_cycle'])) {
                        continue;
                    }
                    // Correlation first: it does not depend on the amount, and an
                    // uncorrelated result must never mutate this order - not even to
                    // park it. The platform echoes our addinfo (= the Woo order key).
                    $correlated = (!empty($request['addinfo'])
                        && (string) $request['addinfo'] === (string) $order->get_order_key());
                    if (!$correlated) {
                        continue;
                    }
                    // Only a captured payment justifies touching the order. Fail closed
                    // on an absent status too: the platform always serialises it, so a
                    // missing one means we cannot tell what we are looking at.
                    if (!isset($request['status']) || $request['status'] !== 'completed') {
                        continue;
                    }

                    // Currency-checked amount resolution (same rule as the IPN path).
                    // Stays NULL when neither the request currency nor the FX original
                    // currency matches the order: the payment cannot be applied, but it
                    // WAS captured, so the order must still be protected below.
                    $api_amount = null;
                    if (isset($request['currency']) && $request['currency'] === $order->get_currency()) {
                        $api_amount = floatval($request['amount']);
                    } elseif (!empty($request['original_amount']) && !empty($request['original_currency'])
                        && $request['original_currency'] === $order->get_currency()) {
                        $api_amount = floatval($request['original_amount']);
                    }

                    // Authenticity: matches one of the amounts we signed.
                    $authentic = false;
                    if ($api_amount !== null) {
                        if (is_array($signed_attempts) && !empty($signed_attempts)) {
                            foreach ($signed_attempts as $attempt) {
                                if (isset($attempt['amount'], $attempt['currency'])
                                    && $attempt['currency'] === $order->get_currency()
                                    && $this->amounts_identical($api_amount, $attempt['amount'])) {
                                    $authentic = true;
                                    break;
                                }
                            }
                        } else {
                            // Orders whose payment form predates the signed-attempt
                            // feature carry no tuple to match: the order key echoed back
                            // by the platform - already verified above - is the only
                            // proof available. Completion still requires the exact live
                            // total below.
                            $authentic = true;
                        }
                    }

                    // Completion is the most destructive action here. Correlation and
                    // the "completed" status are enforced by the two guards at the top
                    // of this loop - do not relax or relocate them without revisiting
                    // this branch.
                    if ($authentic && $this->amounts_identical($api_amount, $live_total)) {
                        // Determine IDs from API response
                        $api_request_id = isset($request['request_id']) ? $request['request_id'] : (isset($request['id']) ? $request['id'] : null);
                        $api_tr_id = isset($request['tr_id']) ? $request['tr_id'] : null;
                        $wc_tr_id = $api_tr_id ? $api_tr_id : $api_request_id; // prefer tr_id for Woo transaction_id

                        // Persist both metas for clarity
                        if (!empty($api_request_id)) {
                            $order->update_meta_data('_idem_request_id', $api_request_id);
                        }
                        if (!empty($api_tr_id)) {
                            $order->update_meta_data('_idem_transaction_id', $api_tr_id);
                        }

                        // Process the successful payment
                        if ($this->complete_status == 'on-hold') {
                            // Set status to on-hold first, before marking as paid
                            $order->update_status('on-hold', __('Payment received, awaiting fulfillment.', 'woocommerce-gateway-idem'));
                            try {
                                if (!empty($wc_tr_id)) {
                                    $order->set_transaction_id($wc_tr_id);
                                }
                                $order->set_date_paid(time());
                                $order->save();
                            } catch (WC_Data_Exception $e) {
                            }
                        } else {
                            if (!empty($wc_tr_id)) {
                                $order->payment_complete($wc_tr_id);
                            } else {
                                $order->payment_complete();
                            }
                        }

                        // Note for audit trail
                        if (!empty($wc_tr_id)) {
                            $order->add_order_note(
                                sprintf(
                                    __('Payment verified via IDEM API. Transaction ID: %s', 'woocommerce-gateway-idem'),
                                    $wc_tr_id
                                )
                            );
                        } else {
                            $order->add_order_note(
                                __('Payment verified via IDEM API.', 'woocommerce-gateway-idem')
                            );
                        }
                        // The fallback pull marked this order paid because no payment IPN arrived
                        // first. Record it for the passive "IPN blocked?" health check (keyed by
                        // order, so a later IPN retracts it and the 5/10/15-min retries never
                        // double-count the same order).
                        if (!$order->get_meta('_ep_ipn_received')) {
                            $this->record_ipn_missing($order->get_id());
                        }
                        return true;
                    }

                    // Correlated and captured, but not safely applicable: unresolvable
                    // currency, an amount matching no signed attempt, or a drifted
                    // total. Remember it and keep scanning - a completable result later
                    // in the list must win over parking.
                    if ($park_candidate === null) {
                        $park_candidate = array('request' => $request, 'amount' => $api_amount);
                    }
                }

                if ($park_candidate !== null) {
                    $request    = $park_candidate['request'];
                    $api_amount = $park_candidate['amount'];
                    // A captured payment that cannot be applied automatically must still
                    // leave "pending", or the unpaid-order sweep will cancel a PAID order.
                    $paid_label = ($api_amount !== null)
                        ? number_format((float) $api_amount, 2) . ' ' . $order->get_currency()
                        : (isset($request['amount']) ? $request['amount'] : '?') . ' ' . (isset($request['currency']) ? $request['currency'] : '?');
                    $api_request_id = isset($request['request_id']) ? $request['request_id'] : (isset($request['id']) ? $request['id'] : null);
                    $api_tr_id = isset($request['tr_id']) ? $request['tr_id'] : null;
                    if (!empty($api_request_id)) {
                        $order->update_meta_data('_idem_request_id', $api_request_id);
                    }
                    if (!empty($api_tr_id)) {
                        $order->update_meta_data('_idem_transaction_id', $api_tr_id);
                    }
                    try {
                        if (!empty($api_tr_id)) {
                            $order->set_transaction_id($api_tr_id);
                        }
                        $order->set_date_paid(time());
                    } catch (WC_Data_Exception $e) {
                    }
                    $order->update_status('on-hold', sprintf(
                        /* translators: 1: amount paid, 2: current order total */
                        __('Payment found via IDEM API but not applied automatically. Paid: %1$s — current order total: %2$s. Review the payment and complete the order manually.', 'woocommerce-gateway-idem'),
                        $paid_label,
                        number_format($live_total, 2) . ' ' . $order->get_currency()
                    ));
                    if (!$order->get_meta('_ep_ipn_received')) {
                        $this->record_ipn_missing($order->get_id());
                    }
                    $this->log('Fallback: captured payment not safely applicable on order #' . $order->get_id() . ' (paid ' . $paid_label . ', order total ' . $live_total . ') - parked on-hold');
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            $this->log('Payment verification exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Last-chance payment verification before WooCommerce cancels an unpaid order.
     *
     * Hooked on the `woocommerce_cancel_unpaid_order` FILTER, which
     * wc_cancel_unpaid_orders() evaluates once per candidate order; returning false
     * keeps the order alive.
     *
     * This used to be hooked on `woocommerce_before_cancel_unpaid_order`, an action
     * WooCommerce never fires - the guard was dead code, so once the +5/10/15 min
     * checks had passed, a captured payment whose IPN never arrived could still be
     * cancelled (proven on a real captured payment during the 2026-08-06 QA).
     *
     * @param bool     $cancel Whether WooCommerce intends to cancel this order.
     * @param WC_Order $order  The order being considered for cancellation.
     * @return bool
     */
    public function verify_before_cancel($cancel, $order = null)
    {
        if (!$cancel || empty($order) || !is_a($order, 'WC_Order')) {
            return $cancel;
        }
        if (!$this->is_idem_order($order)) {
            return $cancel;
        }
        // verify_payment_status() completes the order, or parks it on-hold when the
        // payment cannot be applied safely. Either way it found the money, so the
        // order must survive this sweep.
        if ($this->verify_payment_status($order)) {
            $this->log('Cancellation of order #' . $order->get_id() . ' prevented: the API check found a payment.');
            return false;
        }
        return $cancel;
    }

    /**
     * Schedule a payment verification check
     *
     * @param int $order_id
     */
    public function schedule_payment_check($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order && $this->is_idem_order($order)) {
            // Schedule three verification attempts over 15 minutes
            wp_schedule_single_event(time() + 300, 'wc_idem_verify_payment', array($order_id));  // 5 minutes
            wp_schedule_single_event(time() + 600, 'wc_idem_verify_payment', array($order_id));  // 10 minutes
            wp_schedule_single_event(time() + 900, 'wc_idem_verify_payment', array($order_id));  // 15 minutes
        }
    }

    /**
     * Check payment status when customer returns to the site
     */
    public function check_return_payment_status()
    {
        if (!is_order_received_page()) {
            return;
        }

        $order_id = absint(get_query_var('order-received'));
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if ($order && $this->is_idem_order($order)) {
            $this->verify_payment_status($order);
        }
    }

    /**
     * Add meta box to display IDEM transactions
     */
    public function add_idem_transactions_meta_box()
    {
        add_meta_box(
            'wc_idem_transactions',
            __('IDEM Transactions', 'woocommerce-gateway-idem'),
            array($this, 'render_idem_transactions_meta_box'),
            'shop_order',
            'normal',
            'default'
        );
    }

    /**
     * Render the IDEM transactions meta box
     * 
     * @param WP_Post $post The post object
     */
    public function render_idem_transactions_meta_box($post)
    {
        $order = wc_get_order($post->ID);
        if (!$order) {
            return;
        }

        // Only show for orders processed with IDEM
        if ($order->get_payment_method() !== $this->id) {
            echo '<p>' . __('This order was not processed using IDEM Payments.', 'woocommerce-gateway-idem') . '</p>';
            return;
        }

        try {
            $api_client = new WC_Idem_API_Client(
                $this->get_env_option('api_token'),
                'yes' === $this->get_option('test_mode'),
                wc_get_logger(),
                $this->get_option('test_url'),
                5 // Short timeout for admin display
            );

            // Search for all requests with this order ID
            $search_result = $api_client->search_requests([
                'reference' => $order->get_id()
            ]);

            if (is_wp_error($search_result)) {
                echo '<p class="error">' . esc_html($search_result->get_error_message()) . '</p>';
                return;
            }

            if (empty($search_result['data']['requests'])) {
                echo '<p>' . __('No IDEM transactions found for this order.', 'woocommerce-gateway-idem') . '</p>';
                return;
            }

            echo '<table class="widefat">
                <thead>
                    <tr>
                        <th>' . __('Request ID', 'woocommerce-gateway-idem') . '</th>
                        <th>' . __('Transaction ID', 'woocommerce-gateway-idem') . '</th>
                        <th>' . __('Date', 'woocommerce-gateway-idem') . '</th>
                        <th>' . __('Amount', 'woocommerce-gateway-idem') . '</th>
                        <th>' . __('Status', 'woocommerce-gateway-idem') . '</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($search_result['data']['requests'] as $transaction) {
                $status_class = $this->get_status_class($transaction['status']);
                $amount = wc_price($transaction['amount'], ['currency' => $order->get_currency()]);
                $date = isset($transaction['date']) ?
                    wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction['date'])) :
                    __('N/A', 'woocommerce-gateway-idem');

                $extention = 'yes' === $this->get_option('test_mode') ? 'info' : 'ai';
                $link = "https://www.easyprocess.{$extention}/merchants/myaccount/request/{$transaction['request_id']}";

                echo '<tr>
                    <td><a href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer">' . esc_html($transaction['request_id']) . '</a></td>
                    <td>' . esc_html($transaction['tr_id']) . '</td>
                    <td>' . esc_html($date) . '</td>
                    <td>' . $amount . '</td>
                    <td><mark class="order-status ' . esc_attr($status_class) . '"><span>' . esc_html($transaction['status']) . '</span></mark></td>
                </tr>';
            }

            echo '</tbody></table>';
        } catch (Exception $e) {
            echo '<p class="error">' . esc_html($e->getMessage()) . '</p>';
        }
    }

    /**
     * Get the status class for styling
     * 
     * @param string $status
     * @return string
     */
    private function get_status_class($status)
    {
        $status_classes = [
            'completed' => 'status-completed',
            'pending' => 'status-pending',
            'failed' => 'status-failed',
            'cancelled' => 'status-cancelled',
            'refunded' => 'status-refunded'
        ];

        return isset($status_classes[$status]) ? $status_classes[$status] : 'status-' . sanitize_html_class($status);
    }

    /**
     * Handle manual refund status changes
     *
     * @param int $order_id
     * @param WC_Order $order
     */
    public function handle_manual_refund($order_id, $order)
    {
        // Only process for IDEM payment methods (main or crypto)
        if (!$this->is_idem_order($order)) {
            return;
        }

        // Determine the IDEM request ID (primary source of truth)
        $request_id = $order->get_meta('_idem_request_id', true);

        try {
            $api_client = new WC_Idem_API_Client(
                $this->get_env_option('api_token'),
                'yes' === $this->get_option('test_mode'),
                wc_get_logger(),
                $this->get_option('test_url')
            );

            // Fallback if request ID is missing: search by order reference only
            if (empty($request_id)) {
                $search = $api_client->search_requests([
                    'reference' => $order->get_id(),
                    'status' => 'completed'
                ]);
                if (!is_wp_error($search) && !empty($search['data']['requests'])) {
                    $first = $search['data']['requests'][0];
                    $request_id = $first['request_id'] ?? ($first['id'] ?? null);
                }

                if (empty($request_id)) {
                    $order->add_order_note(
                        __('No IDEM request ID found for this order.', 'woocommerce-gateway-idem')
                    );
                    return;
                }
            }

            // Get the specific request
            $result = $api_client->get_request($request_id);

            if (is_wp_error($result)) {
                $order->add_order_note(
                    sprintf(
                        __('Failed to retrieve IDEM request %s: %s', 'woocommerce-gateway-idem'),
                        $request_id,
                        $result->get_error_message()
                    )
                );
                return;
            }

            // Check if we have a valid response
            if ($result['status'] !== 'OK' || empty($result['request'])) {
                $order->add_order_note(
                    sprintf(
                        __('Invalid response when retrieving IDEM request %s', 'woocommerce-gateway-idem'),
                        $request_id
                    )
                );
                return;
            }

            $request = $result['request'];
            $transaction = $result['transaction'];

            // Check if transaction can be refunded
            if ($request['status'] !== 'completed' || !empty($request['refund_date'])) {
                $order->add_order_note(
                    sprintf(
                        __('IDEM request %s cannot be refunded (status: %s, already refunded: %s)', 'woocommerce-gateway-idem'),
                        $request_id,
                        $request['status'],
                        !empty($request['refund_date']) ? 'yes' : 'no'
                    )
                );
                return;
            }

            // Process refund through IDEM API
            $refund_result = $api_client->refund_request(
                $request_id,
                $this->get_env_option('secret_key'),
                __('Manual refund from WooCommerce', 'woocommerce-gateway-idem'),
                $order->get_total(),
                $order->get_currency()
            );

            if (is_wp_error($refund_result)) {
                $order->add_order_note(
                    sprintf(
                        __('Failed to process IDEM refund for request %s: %s', 'woocommerce-gateway-idem'),
                        $request_id,
                        $refund_result->get_error_message()
                    )
                );
                return;
            }

            // Check refund response
            if ($refund_result['status'] !== 'OK' || empty($refund_result['transaction'])) {
                $order->add_order_note(
                    sprintf(
                        __('Invalid response when processing IDEM refund for request %s', 'woocommerce-gateway-idem'),
                        $request_id
                    )
                );
                return;
            }

            $refunded_transaction = $refund_result['transaction'];
            $refunded_request = $refund_result['request'];

            // Add detailed success note
            $order->add_order_note(
                sprintf(
                    __('IDEM refund processed for transaction %s (Transaction ID: %s)', 'woocommerce-gateway-idem'),
                    $refunded_request['request_id'],
                    $refunded_transaction['tr_id']
                )
            );
        } catch (Exception $e) {
            $this->log('Refund processing error: ' . $e->getMessage());
            $order->add_order_note(
                sprintf(
                    __('Error processing IDEM refund: %s', 'woocommerce-gateway-idem'),
                    $e->getMessage()
                )
            );
        }
    }



    /**
     * Enqueue admin scripts.
     *
     * @param string $hook The current admin page.
     */
    public function enqueue_admin_scripts($hook)
    {
        global $post;

        // Only load on the subscription edit page
        if ('post.php' === $hook && isset($post->post_type) && 'shop_subscription' === $post->post_type) {

            // Enqueue the script
            wp_enqueue_script(
                'idem-admin-subscription',
                plugin_dir_url(__FILE__) . '../assets/js/admin-subscription.js',
                ['jquery', 'jquery-blockui'],
                $this->version . '.' . time(), // Cache busting
                true
            );

            // Pass data to the script
            wp_localize_script(
                'idem-admin-subscription',
                'idem_admin_subscription',
                [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('idem_subscription_actions'),
                    'i18n'     => [
                        'confirm_action' => __('Are you sure you want to perform this action?', 'woocommerce-gateway-idem'),
                        'action_success' => __('Action completed successfully!', 'woocommerce-gateway-idem'),
                        'action_error'   => __('An error occurred: ', 'woocommerce-gateway-idem'),
                        'prompt_amount'  => __('Enter the new amount:', 'woocommerce-gateway-idem'),
                        'confirm_scope'  => __('Apply this amount change to all future payments?\n\n- Click OK to make this change permanent for all future payments.\n- Click Cancel to change the next payment ONLY (the amount will revert afterwards).', 'woocommerce-gateway-idem'),
                        'prompt_cycles'  => __('Enter the new total number of cycles:', 'woocommerce-gateway-idem'),
                        'confirm_extend' => __('Extend the subscription by one cycle to compensate?', 'woocommerce-gateway-idem'),
                    ],
                ]
            );
        }

        // Load scripts for the settings page
        if ('woocommerce_page_wc-settings' === $hook && isset($_GET['section']) && 'idem' === $_GET['section']) {
            // Load update management script
            wp_enqueue_script(
                'idem-admin-updates',
                plugin_dir_url(__FILE__) . '../assets/js/admin-updates.js',
                ['jquery'],
                $this->version . '.' . time(), // Cache busting
                true
            );

            // Load settings script (Test API button)
            wp_enqueue_script(
                'idem-admin-settings',
                plugin_dir_url(__FILE__) . '../assets/js/admin-settings.js',
                ['jquery', 'jquery-blockui'],
                $this->version . '.' . time(),
                true
            );

            wp_localize_script(
                'idem-admin-settings',
                'idem_admin_settings',
                [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('idem_sync_subscriptions'), // used by admin-updates.js
                    'check_nonce' => wp_create_nonce('idem_check_api'),
                ]
            );

            // Environment tabs + per-environment "Connect to EasyProcess" panels
            wp_enqueue_script(
                'idem-admin-connect',
                plugin_dir_url(__FILE__) . '../assets/js/admin-connect.js',
                ['jquery'],
                $this->version . '.' . time(),
                true
            );
            wp_localize_script(
                'idem-admin-connect',
                'idem_connect_data',
                [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('wc_idem_connect'),
                    'i18n'     => [
                        'connecting'        => __('Connecting…', 'woocommerce-gateway-idem'),
                        'disconnecting'     => __('Disconnecting…', 'woocommerce-gateway-idem'),
                        'confirm_disconnect' => __('Disconnect this environment? You will need to reconnect to change its EasyProcess account. Your saved credentials are kept until you reconnect.', 'woocommerce-gateway-idem'),
                        'request_failed'    => __('Request failed', 'woocommerce-gateway-idem'),
                        'error'             => __('Error', 'woocommerce-gateway-idem'),
                        'manual_show'       => __('Enter or edit credentials manually', 'woocommerce-gateway-idem'),
                        'manual_hide'       => __('Hide manual credentials', 'woocommerce-gateway-idem'),
                    ],
                ]
            );
            wp_enqueue_style(
                'idem-admin-connect',
                plugin_dir_url(__FILE__) . '../assets/css/admin-connect.css',
                [],
                $this->version . '.' . time()
            );

            // Per-environment sub-account dropdown (list / create / refresh)
            wp_enqueue_script(
                'idem-admin-subaccounts',
                plugin_dir_url(__FILE__) . '../assets/js/admin-subaccounts.js',
                ['jquery'],
                $this->version . '.' . time(),
                true
            );
            wp_localize_script(
                'idem-admin-subaccounts',
                'idem_subaccounts_data',
                [
                    'ajax_url'  => admin_url('admin-ajax.php'),
                    'nonce'     => wp_create_nonce('idem_subaccounts'),
                    'connected' => [
                        'sandbox' => ($this->get_option('sandbox_api_token') !== '' && $this->get_option('sandbox_api_token') !== null),
                        'prod'    => ($this->get_option('prod_api_token') !== '' && $this->get_option('prod_api_token') !== null),
                    ],
                    'i18n'      => [
                        'loading'        => __('Loading sub-accounts…', 'woocommerce-gateway-idem'),
                        'none'           => __('— None —', 'woocommerce-gateway-idem'),
                        'needs_connect'  => __('Connect this environment first to load your sub-accounts.', 'woocommerce-gateway-idem'),
                        'disabled'       => __('Sub-accounts are not enabled on your EasyProcess account. Contact support to enable them.', 'woocommerce-gateway-idem'),
                        'creating'       => __('Creating…', 'woocommerce-gateway-idem'),
                        'created'        => __('Created sub-account #%s for this store.', 'woocommerce-gateway-idem'),
                        'reused'         => __('Using existing sub-account #%s for this store.', 'woocommerce-gateway-idem'),
                        'confirm_create' => __('Create a new EasyProcess sub-account for this store using its name, URL and logo?', 'woocommerce-gateway-idem'),
                        'error'          => __('Error', 'woocommerce-gateway-idem'),
                        'request_failed' => __('Request failed', 'woocommerce-gateway-idem'),
                    ],
                ]
            );
            wp_enqueue_style(
                'idem-admin-subaccounts',
                plugin_dir_url(__FILE__) . '../assets/css/admin-subaccounts.css',
                [],
                $this->version . '.' . time()
            );

            // Per-environment customer-support editor (load / save)
            wp_enqueue_script(
                'idem-admin-support',
                plugin_dir_url(__FILE__) . '../assets/js/admin-support.js',
                ['jquery'],
                $this->version . '.' . time(),
                true
            );
            wp_localize_script(
                'idem-admin-support',
                'idem_customer_support_data',
                [
                    'ajax_url'  => admin_url('admin-ajax.php'),
                    'nonce'     => wp_create_nonce('idem_customer_support'),
                    'connected' => [
                        'sandbox' => ($this->get_option('sandbox_api_token') !== '' && $this->get_option('sandbox_api_token') !== null),
                        'prod'    => ($this->get_option('prod_api_token') !== '' && $this->get_option('prod_api_token') !== null),
                    ],
                    'i18n'      => [
                        'loading'        => __('Loading…', 'woocommerce-gateway-idem'),
                        'saving'         => __('Saving…', 'woocommerce-gateway-idem'),
                        'saved'          => __('Saved to EasyProcess.', 'woocommerce-gateway-idem'),
                        'required'       => __('A support email and message are required.', 'woocommerce-gateway-idem'),
                        'required_email'     => __('A support email is required.', 'woocommerce-gateway-idem'),
                        'account_default'    => __('Account default', 'woocommerce-gateway-idem'),
                        'required_account'   => __('Required — the gateway will not process payments until a support email and message are set.', 'woocommerce-gateway-idem'),
                        'required_subaccount'=> __('A support email is required. Leave the message blank to use your account default.', 'woocommerce-gateway-idem'),
                        'prefilled'          => __('Pre-filled from your account default — edit and Update to set this sub-account\'s own support info.', 'woocommerce-gateway-idem'),
                        'needs_connect'  => __('Connect this environment first to manage customer support.', 'woocommerce-gateway-idem'),
                        'error'          => __('Error', 'woocommerce-gateway-idem'),
                        'request_failed' => __('Request failed', 'woocommerce-gateway-idem'),
                    ],
                ]
            );
            wp_enqueue_style(
                'idem-admin-support',
                plugin_dir_url(__FILE__) . '../assets/css/admin-support.css',
                [],
                $this->version . '.' . time()
            );
        }
    }

    /**
     * AJAX handler: Check API connectivity and credentials
     */
    public function idem_check_api_ajax_handler()
    {
        check_ajax_referer('idem_check_api', 'nonce');

        // Read posted parameters (allow testing without saving settings)
        $api_key  = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        $test_mode = isset($_POST['test_mode']) ? sanitize_text_field(wp_unslash($_POST['test_mode'])) : 'no';
        $test_url = isset($_POST['test_url']) ? esc_url_raw(wp_unslash($_POST['test_url'])) : '';

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('Please enter an IDEM API Token first.', 'woocommerce-gateway-idem')]);
        }

        try {
            $client = new WC_Idem_API_Client(
                $api_key,
                $test_mode === 'yes',
                wc_get_logger(),
                $test_url
            );
            // The JS sends the environment-appropriate base URL (test_url for sandbox, gateway_url
            // for production), so honor it as the production override too.
            $client->set_prod_url($test_url);

            // 1) Ping
            $ping = $client->ping();
            if (is_wp_error($ping)) {
                wp_send_json_error([
                    'message' => sprintf(__('Ping failed: %s', 'woocommerce-gateway-idem'), $ping->get_error_message()),
                ]);
            }

            // 2) Check auth
            $auth = $client->check_auth();
            if (is_wp_error($auth)) {
                wp_send_json_error([
                    'message' => sprintf(__('Auth check failed: %s', 'woocommerce-gateway-idem'), $auth->get_error_message()),
                ]);
            }

            wp_send_json_success([
                'message' => __('Connection successful. Ping OK and credentials valid.', 'woocommerce-gateway-idem'),
                'details' => [
                    'environment' => ($test_mode === 'yes') ? 'sandbox' : 'production',
                ],
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX handler: ask the platform to check whether OUR servers can reach this store's IPN URL —
     * surfacing a merchant-side firewall/WAF (e.g. Cloudflare) silently blocking our inbound IPNs.
     */
    public function ajax_verify_notification()
    {
        check_ajax_referer('idem_check_api', 'nonce');

        // Which environment to test — default to the active one (Mode).
        if (isset($_POST['env']) && in_array($_POST['env'], ['prod', 'sandbox'], true)) {
            $env = sanitize_text_field(wp_unslash($_POST['env']));
        } else {
            $env = ('yes' === $this->get_option('test_mode')) ? 'sandbox' : 'prod';
        }

        $api_token = $this->get_option($env . '_api_token');
        if (empty($api_token)) {
            wp_send_json_error(['message' => __('Connect or enter an API Token for this environment first.', 'woocommerce-gateway-idem')]);
        }

        $sub_id = $this->get_option($env . '_sub_account');

        try {
            $client = new WC_Idem_API_Client(
                $api_token,
                $env === 'sandbox',
                wc_get_logger(),
                $this->get_option('test_url')
            );
            if (method_exists($client, 'set_prod_url')) {
                $client->set_prod_url($this->get_option('gateway_url'));
            }

            $res = $client->verify_notification($sub_id ? (int) $sub_id : null);
            if (is_wp_error($res)) {
                // If the plugin itself can't reach the platform (e.g. OUR Cloudflare blocks this
                // store), the client already returns a clean Ray-ID message — surface it as-is.
                wp_send_json_error(['message' => $res->get_error_message()]);
            }

            $data = isset($res['data']) && is_array($res['data']) ? $res['data'] : (is_array($res) ? $res : []);
            $data['env']      = $env;
            $data['ips']      = $this->get_whitelist_ips();
            $data['ipn_path'] = '/?wc-api=WC_Gateway_Idem';
            wp_send_json_success($data);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX handler: one-click "Connect to EasyProcess".
     * Sends the merchant's EasyProcess email+password for the requested environment (posted
     * `env` = sandbox|prod) to POST {gateway}/api/idem/connect, then writes the returned
     * credentials into that environment's block. The password is never stored.
     */
    public function ajax_connect()
    {
        check_ajax_referer('wc_idem_connect', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'woocommerce-gateway-idem')));
        }
        $email    = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        if ($email === '' || $password === '') {
            wp_send_json_error(array('message' => __('Email and password are required.', 'woocommerce-gateway-idem')));
        }

        // The environment is explicit: each tab has its own Connect button (data-env), so a
        // Connect always targets that block regardless of the live Mode selection.
        $env = (isset($_POST['env']) && $_POST['env'] === 'prod') ? 'prod'
             : ((isset($_POST['env']) && $_POST['env'] === 'sandbox') ? 'sandbox' : '');
        if ($env === '') {
            wp_send_json_error(array('message' => __('Missing environment.', 'woocommerce-gateway-idem')));
        }
        $is_sandbox = ($env === 'sandbox');
        // Host: the URL posted from that environment's field, else the saved option, else default.
        $api_base = isset($_POST['env_url']) ? esc_url_raw(wp_unslash($_POST['env_url'])) : '';
        if (empty($api_base)) {
            $api_base = $is_sandbox ? $this->get_option('test_url') : $this->get_option('gateway_url');
        }
        if (empty($api_base)) {
            $api_base = $is_sandbox ? 'https://gateway.idem-club.info' : 'https://gateway.idem-club.com';
        }
        $url = trailingslashit($api_base) . 'api/idem/connect';

        // The store's own IPN listener URL — EasyProcess uses it to set notification_url.
        $store_url = WC()->api_request_url('WC_Gateway_Idem');
        $logo_id   = get_theme_mod('custom_logo');
        $logo_url  = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        $body = array(
            'email'          => $email,
            'password'       => $password,
            'store_url'      => $store_url,
            'store_name'     => get_bloginfo('name'),
            'logo_url'       => $logo_url ? $logo_url : '',
            'plugin_version' => $this->version,
            'wp_version'     => get_bloginfo('version'),
            'wc_version'     => defined('WC_VERSION') ? WC_VERSION : '',
        );

        $resp = wp_remote_post($url, array(
            'timeout' => 30,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
        ));

        if (is_wp_error($resp)) {
            wp_send_json_error(array('message' => $resp->get_error_message()));
        }
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($json) || !isset($json['status']) || $json['status'] !== 'OK' || empty($json['data'])) {
            $msg = (!empty($json['msg'])) ? $json['msg'] : __('Connection failed. Check your credentials.', 'woocommerce-gateway-idem');
            wp_send_json_error(array('message' => $msg));
        }

        // Validate every required credential is present before persisting, so a partial or
        // malformed response can never overwrite working credentials with empty strings.
        $d = $json['data'];
        foreach (array('merchant_id', 'hash_key', 'idem_api_token', 'idem_api_secret') as $field) {
            if (empty($d[$field])) {
                wp_send_json_error(array('message' => __('Connection succeeded but the response was incomplete. Please try again.', 'woocommerce-gateway-idem')));
            }
        }

        // Write returned credentials into the requested environment block.
        $prefix = $env;
        $this->update_option($prefix . '_merchant_id',  (string) $d['merchant_id']);
        $this->update_option($prefix . '_merchant_key', (string) $d['hash_key']);
        $this->update_option($prefix . '_api_token',    (string) $d['idem_api_token']);
        $this->update_option($prefix . '_secret_key',   (string) $d['idem_api_secret']);
        $this->update_option($prefix . '_connected_email', $email);

        $warn = (!empty($d['warnings'])) ? ' (' . implode('; ', (array) $d['warnings']) . ')' : '';
        wp_send_json_success(array('message' => sprintf(__('Connected · Merchant #%s', 'woocommerce-gateway-idem'), $d['merchant_id']) . $warn));
    }

    /**
     * AJAX handler: "Disconnect" a connected environment. Clears only the connected marker
     * ({env}_connected_email) so the Connect login form reappears; the stored credentials are left
     * in place so payments keep working until the merchant reconnects. Disconnect is "re-link",
     * not "wipe credentials".
     */
    public function ajax_disconnect()
    {
        check_ajax_referer('wc_idem_connect', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'woocommerce-gateway-idem')));
        }
        $env = (isset($_POST['env']) && $_POST['env'] === 'prod') ? 'prod' : 'sandbox';
        $this->update_option($env . '_connected_email', '');
        wp_send_json_success(array('message' => __('Disconnected.', 'woocommerce-gateway-idem')));
    }

    /**
     * Build a WC_Idem_API_Client for the given environment's saved API token. Sandbox uses the
     * plugin's test_url and production its gateway_url (each falling back to the brand default host
     * when unset). Returns null when that environment has no token yet (not connected).
     *
     * @param string $env sandbox|prod
     * @return WC_Idem_API_Client|null
     */
    private function get_env_api_client($env)
    {
        $token = $this->get_option($env . '_api_token');
        if (empty($token)) {
            return null;
        }
        return (new WC_Idem_API_Client($token, $env === 'sandbox', wc_get_logger(), $this->get_option('test_url')))
            ->set_prod_url($this->get_option('gateway_url'));
    }

    /**
     * AJAX handler: list the merchant's sub-accounts for the requested environment, used to
     * populate the per-environment sub-account dropdown. Proxies GET /api/idem/subaccounts with
     * the environment's saved API token.
     */
    public function idem_list_subaccounts_ajax_handler()
    {
        check_ajax_referer('idem_subaccounts', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'woocommerce-gateway-idem')));
        }
        $env    = (isset($_POST['env']) && $_POST['env'] === 'prod') ? 'prod' : 'sandbox';
        $client = $this->get_env_api_client($env);
        if ($client === null) {
            wp_send_json_error(array('needs_connect' => true, 'message' => __('Connect this environment first to load your sub-accounts.', 'woocommerce-gateway-idem')));
        }

        $resp = $client->get_subaccounts();
        if (is_wp_error($resp)) {
            wp_send_json_error(array('message' => $resp->get_error_message()));
        }
        $data = isset($resp['data']) ? $resp['data'] : array();
        wp_send_json_success(array(
            'subaccounts_enabled' => !empty($data['subaccounts_enabled']),
            'subaccounts'         => isset($data['subaccounts']) ? $data['subaccounts'] : array(),
        ));
    }

    /**
     * AJAX handler: create a sub-account in EasyProcess for THIS store, seeded from the store's
     * own data (listener URL, name, site URL, logo, admin email). Proxies POST /api/idem/subaccounts
     * with the environment's saved API token, then persists the returned sub_id into that
     * environment's block so it is selected immediately.
     */
    public function idem_create_subaccount_ajax_handler()
    {
        check_ajax_referer('idem_subaccounts', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'woocommerce-gateway-idem')));
        }
        $env    = (isset($_POST['env']) && $_POST['env'] === 'prod') ? 'prod' : 'sandbox';
        $client = $this->get_env_api_client($env);
        if ($client === null) {
            wp_send_json_error(array('needs_connect' => true, 'message' => __('Connect this environment first to create a sub-account.', 'woocommerce-gateway-idem')));
        }

        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        $payload = array(
            'store_url'  => WC()->api_request_url('WC_Gateway_Idem'),
            'store_name' => get_bloginfo('name'),
            'site_url'   => home_url('/'),
            'logo_url'   => $logo_url ? $logo_url : '',
            'cs_email'   => get_option('admin_email'),
            'cs_message' => isset($_POST['cs_message']) ? sanitize_text_field(wp_unslash($_POST['cs_message'])) : '',
        );

        $resp = $client->create_subaccount($payload);
        if (is_wp_error($resp)) {
            wp_send_json_error(array('message' => $resp->get_error_message()));
        }
        if (empty($resp['status']) || $resp['status'] !== 'OK' || empty($resp['data']['subaccount'])) {
            $msg = (!empty($resp['msg'])) ? $resp['msg'] : __('Could not create the sub-account.', 'woocommerce-gateway-idem');
            wp_send_json_error(array('message' => $msg));
        }

        $sub = $resp['data']['subaccount'];
        // Persist the new sub-account id into this environment's block (mirrors ajax_connect, which
        // also writes credentials without waiting for a Save). The dropdown JS selects it too.
        $this->update_option($env . '_sub_account', (string) $sub['sub_id']);

        wp_send_json_success(array(
            'subaccount' => $sub,
            'created'    => !empty($resp['data']['created']),
            'warnings'   => isset($resp['data']['warnings']) ? $resp['data']['warnings'] : array(),
        ));
    }

    /**
     * AJAX handler: load the merchant's customer-support info (phone/email/message) for the
     * requested environment, so the merchant can view/edit it in the plugin without opening their
     * EasyProcess account. Proxies GET /api/idem/customer-support with the env's API token.
     */
    public function idem_get_customer_support_ajax_handler()
    {
        check_ajax_referer('idem_customer_support', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'woocommerce-gateway-idem')));
        }
        $env    = (isset($_POST['env']) && $_POST['env'] === 'prod') ? 'prod' : 'sandbox';
        $sub_id = (isset($_POST['sub_id']) && (int) $_POST['sub_id'] > 0) ? (int) $_POST['sub_id'] : null;
        $client = $this->get_env_api_client($env);
        if ($client === null) {
            wp_send_json_error(array('needs_connect' => true, 'message' => __('Connect this environment first to manage customer support.', 'woocommerce-gateway-idem')));
        }

        $resp = $client->get_customer_support($sub_id);
        if (is_wp_error($resp)) {
            wp_send_json_error(array('message' => $resp->get_error_message()));
        }
        $data = isset($resp['data']) ? $resp['data'] : array();
        wp_send_json_success(array(
            'sub_id'     => isset($data['sub_id']) ? $data['sub_id'] : null,
            'cs_number'  => isset($data['cs_number']) ? $data['cs_number'] : '',
            'cs_email'   => isset($data['cs_email']) ? $data['cs_email'] : '',
            'cs_message' => isset($data['cs_message']) ? $data['cs_message'] : '',
            'defaults'   => isset($data['defaults']) ? $data['defaults'] : array(),
        ));
    }

    /**
     * AJAX handler: save the merchant's customer-support info to EasyProcess for the requested
     * environment. Proxies POST /api/idem/customer-support with the env's API token. Email and
     * message are required (the IDEM gateway refuses to process without them).
     */
    public function idem_save_customer_support_ajax_handler()
    {
        check_ajax_referer('idem_customer_support', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'woocommerce-gateway-idem')));
        }
        $env    = (isset($_POST['env']) && $_POST['env'] === 'prod') ? 'prod' : 'sandbox';
        $client = $this->get_env_api_client($env);
        if ($client === null) {
            wp_send_json_error(array('needs_connect' => true, 'message' => __('Connect this environment first to manage customer support.', 'woocommerce-gateway-idem')));
        }

        $payload = array(
            'cs_email'   => isset($_POST['cs_email']) ? sanitize_email(wp_unslash($_POST['cs_email'])) : '',
            'cs_message' => isset($_POST['cs_message']) ? sanitize_textarea_field(wp_unslash($_POST['cs_message'])) : '',
            'cs_number'  => isset($_POST['cs_number']) ? sanitize_text_field(wp_unslash($_POST['cs_number'])) : '',
        );
        if (isset($_POST['sub_id']) && (int) $_POST['sub_id'] > 0) {
            $payload['sub_id'] = (int) $_POST['sub_id'];
        }

        $resp = $client->update_customer_support($payload);
        if (is_wp_error($resp)) {
            wp_send_json_error(array('message' => $resp->get_error_message()));
        }
        if (empty($resp['status']) || $resp['status'] !== 'OK') {
            $msg = (!empty($resp['msg'])) ? $resp['msg'] : __('Could not save customer support.', 'woocommerce-gateway-idem');
            wp_send_json_error(array('message' => $msg));
        }
        $data = isset($resp['data']) ? $resp['data'] : array();
        wp_send_json_success(array(
            'sub_id'     => isset($data['sub_id']) ? $data['sub_id'] : null,
            'cs_number'  => isset($data['cs_number']) ? $data['cs_number'] : '',
            'cs_email'   => isset($data['cs_email']) ? $data['cs_email'] : '',
            'cs_message' => isset($data['cs_message']) ? $data['cs_message'] : '',
            'defaults'   => isset($data['defaults']) ? $data['defaults'] : array(),
            'warnings'   => isset($data['warnings']) ? $data['warnings'] : array(),
        ));
    }

    /**
     * Add meta box for IDEM subscription actions
     */
    public function add_idem_subscription_actions_meta_box()
    {
        add_meta_box(
            'wc_idem_subscription_actions',
            __('IDEM Subscription Actions', 'woocommerce-gateway-idem'),
            array($this, 'render_idem_subscription_actions_meta_box'),
            'shop_subscription', // The screen ID for subscriptions
            'side',
            'high'
        );
    }

    /**
     * Render the IDEM subscription actions meta box
     * 
     * @param WP_Post $post The post object
     */
    public function render_idem_subscription_actions_meta_box($post)
    {
        $subscription = wcs_get_subscription($post->ID);
        if (!$subscription) {
            return;
        }

        // Resolve request_id using helper
        $request_id = $this->resolve_subscription_request_id($subscription);

        if (empty($request_id)) {
            echo '<p>' . __('No IDEM recurring request ID found for this subscription.', 'woocommerce-gateway-idem') . '</p>';
            return;
        }

        // Add nonce for security
        wp_nonce_field('idem_subscription_actions', 'idem_subscription_actions_nonce');
        ?>
        <div id="idem-subscription-actions-container" data-request-id="<?php echo esc_attr($request_id); ?>" data-subscription-id="<?php echo esc_attr($post->ID); ?>">
            <p>
                <button type="button" class="button" id="idem-update-amount"><?php _e('Change Amount', 'woocommerce-gateway-idem'); ?></button>
            </p>
            <p>
                <button type="button" class="button" id="idem-update-cycles"><?php _e('Change Cycles', 'woocommerce-gateway-idem'); ?></button>
            </p>
            <p>
                <button type="button" class="button" id="idem-skip-payment"><?php _e('Skip Next Payment', 'woocommerce-gateway-idem'); ?></button>
            </p>
        </div>
<?php
    }

    /**
     * Process subscription renewal payment
     *
     * @param float $amount_to_charge The amount to charge
     * @param WC_Order $renewal_order The renewal order
     * @return void
     */
    public function process_subscription_payment($amount_to_charge, $renewal_order)
    {
        $this->log('Processing subscription renewal payment for order ' . $renewal_order->get_id());

        // Get the parent order ID from the subscription
        $subscriptions = wcs_get_subscriptions_for_renewal_order($renewal_order);
        if (empty($subscriptions)) {
            $renewal_order->update_status('failed', __('Subscription renewal failed: No subscription found.', 'woocommerce-gateway-idem'));
            return;
        }

        $subscription = array_shift($subscriptions);
        $parent_order_id = $subscription->get_parent_id();
        $parent_order = wc_get_order($parent_order_id);

        if (! $parent_order) {
            $renewal_order->update_status('failed', __('Subscription renewal failed: Parent order not found.', 'woocommerce-gateway-idem'));
            return;
        }

        // For now, since IDEM handles recurring payments on their end,
        // we'll mark the renewal order as pending and wait for the IPN notification
        $renewal_order->update_status('pending', __('Awaiting IDEM recurring payment notification.', 'woocommerce-gateway-idem'));

        // Add a note about the expected payment
        $renewal_order->add_order_note(
            sprintf(
                __('IDEM subscription renewal initiated. Amount: %s. Waiting for payment notification from IDEM.', 'woocommerce-gateway-idem'),
                wc_price($amount_to_charge)
            )
        );

        // Store the expected amount for verification when IPN arrives
        $renewal_order->update_meta_data('_idem_expected_amount', $amount_to_charge);
        $renewal_order->update_meta_data('_idem_parent_order_id', $parent_order_id);
        $renewal_order->save();

        $this->log(sprintf('Subscription renewal order %s created and waiting for IDEM payment notification.', $renewal_order->get_id()));
    }

    /**
     * Override order status to allow on-hold for subscription orders
     *
     * @param string $status The default order status
     * @param int $order_id The order ID
     * @param WC_Order $order The order object
     * @return string
     */
    public function maybe_override_order_status($status, $order_id, $order = null)
    {
        if (! $order) {
            $order = wc_get_order($order_id);
        }

        // Only apply to orders using this gateway
        if ($order && $order->get_payment_method() === $this->id) {
            // If admin has configured to use on-hold status
            if ($this->complete_status === 'on-hold') {
                return 'on-hold';
            }
        }

        return $status;
    }

    /**
     * Cancel subscription recurring payment
     *
     * @param WC_Subscription $subscription The subscription being cancelled
     * @return void
     */
    public function cancel_subscription_recurring_payment($subscription)
    {
        $this->log('Processing subscription cancellation for subscription #' . $subscription->get_id());

        // Ensure this is an IDEM subscription before proceeding
        $parent_order = wc_get_order($subscription->get_parent_id());
        if (!$parent_order || $parent_order->get_payment_method() !== $this->id) {
            $this->log('Skipping cancellation API call: Subscription #'. $subscription->get_id() .' is not an IDEM payment.');
            return;
        }

        // Get the IDEM recurring request ID from subscription meta
        $request_id = $subscription->get_meta('_idem_recurring_request_id');
        $this->log('Initial request ID from subscription meta: ' . $request_id);

        // Fallback for older subscriptions: check parent order meta
        if (empty($request_id)) {
            $this->log('Request ID not found in subscription meta, trying parent order.');
            $parent_order = wc_get_order($subscription->get_parent_id());
            if ($parent_order) {
                $parent_request_id = $parent_order->get_meta('_idem_request_id');
                $this->log('Found parent order #' . $subscription->get_parent_id() . ', request ID from parent meta: ' . $parent_request_id);
                if (!empty($parent_request_id)) {
                    // Found it in the parent order, let's update the subscription meta for next time
                    $subscription->update_meta_data('_idem_recurring_request_id', $parent_request_id);
                    $subscription->save();
                    $subscription->add_order_note('IDEM recurring request ID found in parent order and linked to subscription.');
                    $request_id = $parent_request_id;
                    $this->log('Using request ID from parent: ' . $request_id);
                }
            }
        }

        if (empty($request_id)) {
            // If the request ID is still not found, try searching by parent order reference
            $api_key = $this->get_env_option('api_token');
            if (!empty($api_key)) {
                try {
                    $api_client = new WC_Idem_API_Client(
                        $api_key,
                        'yes' === $this->get_option('test_mode'),
                        wc_get_logger(),
                        $this->get_option('test_url')
                    );
                    $response = $api_client->search_requests(['reference' => $subscription->get_parent_id()]);
                    if (!is_wp_error($response) && !empty($response['data']['requests'])) {
                        $first = $response['data']['requests'][0];
                        $found_request_id = $first['request_id'] ?? ($first['id'] ?? null);
                        if (!empty($found_request_id)) {
                            $request_id = $found_request_id;
                            $subscription->update_meta_data('_idem_recurring_request_id', $request_id);
                            $subscription->save();
                            $subscription->add_order_note('IDEM recurring request ID found via IDEM API and linked to subscription.');
                        }
                    }
                } catch (Exception $e) {
                    // Silently ignore during sync listing; logging available if needed
                    wc_get_logger()->debug('IDEM sync lookup error: ' . $e->getMessage(), ['source' => 'idem']);
                }
            }
        }

        if (empty($request_id)) {
            $subscription->add_order_note(
                __('Unable to cancel IDEM recurring payment: No request ID found.', 'woocommerce-gateway-idem')
            );
            $this->log('Cancellation failed: No IDEM request ID found for subscription #' . $subscription->get_id());
            return;
        }

        try {
            // Initialize API client
            $api_key = $this->get_env_option('api_token');
            if (empty($api_key)) {
                $subscription->add_order_note(
                    __('Unable to cancel IDEM recurring payment: API key not configured.', 'woocommerce-gateway-idem')
                );
                return;
            }

            $this->log('Instantiating API client with test_url: ' . $this->get_option('test_url'));

            $api_client = new WC_Idem_API_Client(
                $api_key,
                'yes' === $this->get_option('test_mode'),
                wc_get_logger(),
                $this->get_option('test_url')
            );

            $this->log('Calling stop_recurring_payment API. Request ID: ' . $request_id);

            // Call the stop recurring payment API
            $response = $api_client->stop_recurring_payment($request_id);

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $subscription->add_order_note(
                    sprintf(
                        __('Failed to cancel IDEM recurring payment: %s', 'woocommerce-gateway-idem'),
                        $error_message
                    )
                );
                $this->log('Cancellation API error: ' . $error_message);

                // Send debug email if enabled
                if ($this->send_debug_email) {
                    $subject = __('IDEM Subscription Cancellation Failed', 'woocommerce-gateway-idem');
                    $body = sprintf(
                        "Subscription ID: %s\nRequest ID: %s\nError: %s\n",
                        $subscription->get_id(),
                        $request_id,
                        $error_message
                    );
                    wp_mail($this->get_option('debug_email', get_option('admin_email')), $subject, $body);
                }
            } else {
                $subscription->add_order_note(
                    sprintf(
                        __('IDEM recurring payment cancelled successfully. Request ID: %s', 'woocommerce-gateway-idem'),
                        $request_id
                    )
                );
                $this->log('Subscription #' . $subscription->get_id() . ' cancelled successfully in IDEM');
            }
        } catch (Exception $e) {
            $subscription->add_order_note(
                sprintf(
                    __('Exception during IDEM recurring payment cancellation: %s', 'woocommerce-gateway-idem'),
                    $e->getMessage()
                )
            );
            $this->log('Cancellation exception: ' . $e->getMessage());
        }
    }

    /**
     * Suspend subscription recurring payment
     *
     * @param WC_Subscription $subscription The subscription being suspended
     * @return void
     */
    public function suspend_subscription_recurring_payment($subscription)
    {
        $this->log('Processing subscription suspension for subscription #' . $subscription->get_id());

        // Ensure this is an IDEM subscription before proceeding
        $parent_order = wc_get_order($subscription->get_parent_id());
        if (!$parent_order || $parent_order->get_payment_method() !== $this->id) {
            $this->log('Skipping suspension API call: Subscription #'. $subscription->get_id() .' is not an IDEM payment.');
            return;
        }

        // Check if this on-hold status is triggered by the WCS scheduled payment action.
        // When WCS scheduler fires woocommerce_scheduled_subscription_payment, it puts the subscription
        // on-hold BEFORE creating the renewal order. We use doing_action() to detect this context
        // and skip the IDEM suspension since EP handles billing externally.
        // Note: The renewal order check (v4.3.4) doesn't work because the order doesn't exist yet
        // when the on-hold hook fires - WCS creates it AFTER changing status.
        if (doing_action('woocommerce_scheduled_subscription_payment')) {
            $this->log('Skipping IDEM suspension: Subscription #' . $subscription->get_id() . ' on-hold triggered by WCS scheduled payment action');
            $subscription->add_order_note(
                __('IDEM suspension skipped: On-hold status triggered by WCS scheduled payment (external billing via IDEM/EasyProcess).', 'woocommerce-gateway-idem')
            );
            return;
        }

        // Fallback check: Also skip if there's a pending renewal order (for edge cases)
        $last_renewal_order = $subscription->get_last_order('all', 'renewal');
        if ($last_renewal_order) {
            $renewal_order = wc_get_order($last_renewal_order);
            if ($renewal_order && $renewal_order->has_status('pending')) {
                $this->log('Skipping IDEM suspension: Subscription #' . $subscription->get_id() . ' has pending renewal order #' . $renewal_order->get_id());
                $subscription->add_order_note(
                    sprintf(
                        __('IDEM suspension skipped: Pending renewal order #%s detected.', 'woocommerce-gateway-idem'),
                        $renewal_order->get_id()
                    )
                );
                return;
            }
        }

        // Get the IDEM recurring request ID from subscription meta
        $request_id = $subscription->get_meta('_idem_recurring_request_id');
        $this->log('Initial request ID from subscription meta: ' . $request_id);

        // Fallback for older subscriptions: check parent order meta
        if (empty($request_id)) {
            $this->log('Request ID not found in subscription meta, trying parent order.');
            $parent_order = wc_get_order($subscription->get_parent_id());
            if ($parent_order) {
                $parent_request_id = $parent_order->get_meta('_idem_request_id');
                $this->log('Found parent order #' . $subscription->get_parent_id() . ', request ID from parent meta: ' . $parent_request_id);
                if (!empty($parent_request_id)) {
                    // Found it in the parent order, let's update the subscription meta for next time
                    $subscription->update_meta_data('_idem_recurring_request_id', $parent_request_id);
                    $subscription->save();
                    $subscription->add_order_note('IDEM recurring request ID found in parent order and linked to subscription.');
                    $request_id = $parent_request_id;
                    $this->log('Using request ID from parent: ' . $request_id);
                }
            }
        }

        if (empty($request_id)) {
            // If the request ID is still not found, try searching by parent order reference
            $api_key = $this->get_env_option('api_token');
            if (!empty($api_key)) {
                try {
                    $api_client = new WC_Idem_API_Client(
                        $api_key,
                        'yes' === $this->get_option('test_mode'),
                        wc_get_logger(),
                        $this->get_option('test_url')
                    );
                    $response = $api_client->search_requests(['reference' => $subscription->get_parent_id()]);
                    if (!is_wp_error($response) && !empty($response['data']['requests'])) {
                        $first = $response['data']['requests'][0];
                        $found_request_id = $first['request_id'] ?? ($first['id'] ?? null);
                        if (!empty($found_request_id)) {
                            $request_id = $found_request_id;
                            $subscription->update_meta_data('_idem_recurring_request_id', $request_id);
                            $subscription->save();
                            $subscription->add_order_note('IDEM recurring request ID found via IDEM API and linked to subscription.');
                        }
                    }
                } catch (Exception $e) {
                    // Silently ignore during sync listing; logging available if needed
                    wc_get_logger()->debug('IDEM sync lookup error: ' . $e->getMessage(), ['source' => 'idem']);
                }
            }
        }

        if (empty($request_id)) {
            $subscription->add_order_note(
                __('Unable to suspend IDEM recurring payment: No request ID found.', 'woocommerce-gateway-idem')
            );
            $this->log('Suspension failed: No IDEM request ID found for subscription #' . $subscription->get_id());
            return;
        }

        try {
            // Initialize API client
            $api_key = $this->get_env_option('api_token');
            if (empty($api_key)) {
                $subscription->add_order_note(
                    __('Unable to suspend IDEM recurring payment: API key not configured.', 'woocommerce-gateway-idem')
                );
                return;
            }

            $this->log('Instantiating API client with test_url: ' . $this->get_option('test_url'));

            $api_client = new WC_Idem_API_Client(
                $api_key,
                'yes' === $this->get_option('test_mode'),
                wc_get_logger(),
                $this->get_option('test_url')
            );

            // Prepare suspension reason
            $reason = sprintf(
                __('Subscription suspended in WooCommerce on %s', 'woocommerce-gateway-idem'),
                current_time('mysql')
            );

            $this->log('Calling suspend_recurring_payment API. Request ID: ' . $request_id . ', Reason: ' . $reason);

            // Call the suspend recurring payment API
            $response = $api_client->suspend_recurring_payment($request_id, $reason);

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $subscription->add_order_note(
                    sprintf(
                        __('Failed to suspend IDEM recurring payment: %s', 'woocommerce-gateway-idem'),
                        $error_message
                    )
                );
                $this->log('Suspension API error: ' . $error_message);

                // Send debug email if enabled
                if ($this->send_debug_email) {
                    $subject = __('IDEM Subscription Suspension Failed', 'woocommerce-gateway-idem');
                    $body = sprintf(
                        "Subscription ID: %s\nRequest ID: %s\nError: %s\n",
                        $subscription->get_id(),
                        $request_id,
                        $error_message
                    );
                    wp_mail($this->get_option('debug_email', get_option('admin_email')), $subject, $body);
                }
            } else {
                $subscription->add_order_note(
                    sprintf(
                        __('IDEM recurring payment suspended successfully. Request ID: %s', 'woocommerce-gateway-idem'),
                        $request_id
                    )
                );
                $this->log('Subscription #' . $subscription->get_id() . ' suspended successfully in IDEM');
            }
        } catch (Exception $e) {
            $subscription->add_order_note(
                sprintf(
                    __('Exception during IDEM recurring payment suspension: %s', 'woocommerce-gateway-idem'),
                    $e->getMessage()
                )
            );
            $this->log('Suspension exception: ' . $e->getMessage());
        }
    }

    /**
     * Reactivate subscription recurring payment
     *
     * @param WC_Subscription $subscription The subscription being reactivated
     * @return void
     */
    public function reactivate_subscription_recurring_payment($subscription)
    {
        $this->log('Processing subscription reactivation for subscription #' . $subscription->get_id());

        // Ensure this is an IDEM subscription before proceeding
        $parent_order = wc_get_order($subscription->get_parent_id());
        if (!$parent_order || $parent_order->get_payment_method() !== $this->id) {
            $this->log('Skipping reactivation API call: Subscription #'. $subscription->get_id() .' is not an IDEM payment.');
            return;
        }

        // Get the IDEM recurring request ID from subscription meta
        $request_id = $subscription->get_meta('_idem_recurring_request_id');
        $this->log('Initial request ID from subscription meta: ' . $request_id);

        // Fallback for older subscriptions: check parent order meta
        if (empty($request_id)) {
            $this->log('Request ID not found in subscription meta, trying parent order.');
            $parent_order = wc_get_order($subscription->get_parent_id());
            if ($parent_order) {
                $parent_request_id = $parent_order->get_meta('_idem_request_id');
                $this->log('Found parent order #' . $subscription->get_parent_id() . ', request ID from parent meta: ' . $parent_request_id);
                if (!empty($parent_request_id)) {
                    // Found it in the parent order, let's update the subscription meta for next time
                    $subscription->update_meta_data('_idem_recurring_request_id', $parent_request_id);
                    $subscription->save();
                    $subscription->add_order_note('IDEM recurring request ID found in parent order and linked to subscription.');
                    $request_id = $parent_request_id;
                    $this->log('Using request ID from parent: ' . $request_id);
                }
            }
        }

        if (empty($request_id)) {
            // If the request ID is still not found, try searching by parent order reference
            $api_key = $this->get_env_option('api_token');
            if (!empty($api_key)) {
                try {
                    $api_client = new WC_Idem_API_Client(
                        $api_key,
                        'yes' === $this->get_option('test_mode'),
                        wc_get_logger(),
                        $this->get_option('test_url')
                    );
                    $response = $api_client->search_requests(['reference' => $subscription->get_parent_id()]);
                    if (!is_wp_error($response) && !empty($response['data']['requests'])) {
                        $first = $response['data']['requests'][0];
                        $found_request_id = $first['request_id'] ?? ($first['id'] ?? null);
                        if (!empty($found_request_id)) {
                            $request_id = $found_request_id;
                            $subscription->update_meta_data('_idem_recurring_request_id', $request_id);
                            $subscription->save();
                            $subscription->add_order_note('IDEM recurring request ID found via IDEM API and linked to subscription.');
                        }
                    }
                } catch (Exception $e) {
                    // Silently ignore during sync listing; logging available if needed
                    wc_get_logger()->debug('IDEM sync lookup error: ' . $e->getMessage(), ['source' => 'idem']);
                }
            }
        }

        if (empty($request_id)) {
            $subscription->add_order_note(
                __('Unable to reactivate IDEM recurring payment: No request ID found.', 'woocommerce-gateway-idem')
            );
            $this->log('Reactivation failed: No IDEM request ID found for subscription #' . $subscription->get_id());
            return;
        }

        try {
            // Initialize API client
            $api_key = $this->get_env_option('api_token');
            if (empty($api_key)) {
                $subscription->add_order_note(
                    __('Unable to reactivate IDEM recurring payment: API key not configured.', 'woocommerce-gateway-idem')
                );
                return;
            }

            $this->log('Instantiating API client with test_url: ' . $this->get_option('test_url'));

            $api_client = new WC_Idem_API_Client(
                $api_key,
                'yes' === $this->get_option('test_mode'),
                wc_get_logger(),
                $this->get_option('test_url')
            );

            $this->log('Calling reactivate_recurring_payment API. Request ID: ' . $request_id);

            // Call the reactivate recurring payment API
            $response = $api_client->reactivate_recurring_payment($request_id);

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $subscription->add_order_note(
                    sprintf(
                        __('Failed to reactivate IDEM recurring payment: %s', 'woocommerce-gateway-idem'),
                        $error_message
                    )
                );
                $this->log('Reactivation API error: ' . $error_message);

                // Send debug email if enabled
                if ($this->send_debug_email) {
                    $subject = __('IDEM Subscription Reactivation Failed', 'woocommerce-gateway-idem');
                    $body = sprintf(
                        "Subscription ID: %s\nRequest ID: %s\nError: %s\n",
                        $subscription->get_id(),
                        $request_id,
                        $error_message
                    );
                    wp_mail($this->get_option('debug_email', get_option('admin_email')), $subject, $body);
                }
            } else {
                $subscription->add_order_note(
                    sprintf(
                        __('IDEM recurring payment reactivated successfully. Request ID: %s', 'woocommerce-gateway-idem'),
                        $request_id
                    )
                );
                $this->log('Subscription #' . $subscription->get_id() . ' reactivated successfully in IDEM');
            }
        } catch (Exception $e) {
            $subscription->add_order_note(
                sprintf(
                    __('Exception during IDEM recurring payment reactivation: %s', 'woocommerce-gateway-idem'),
                    $e->getMessage()
                )
            );
            $this->log('Reactivation exception: ' . $e->getMessage());
        }
    }

    public function idem_subscription_action_ajax_handler()
    {
        // Check nonce
        check_ajax_referer('idem_subscription_actions', 'nonce');

        // Get data (matching JavaScript parameter names)
        $request_id = isset($_POST['request_id']) ? sanitize_text_field($_POST['request_id']) : '';
        $action = isset($_POST['idem_action']) ? sanitize_text_field($_POST['idem_action']) : '';
        $subscription_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;

        if (empty($request_id) || empty($action) || empty($subscription_id)) {
            wp_send_json_error(['message' => __('Missing required parameters.', 'woocommerce-gateway-idem')]);
        }

        // Check API credentials
        $api_key = $this->get_env_option('api_token');
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('IDEM API key not configured.', 'woocommerce-gateway-idem')]);
        }

        try {
            $api_client = new WC_Idem_API_Client(
                $api_key,
                'yes' === $this->get_option('test_mode'),
                wc_get_logger(),
                $this->get_option('test_url')
            );

            $response = null;
            switch ($action) {
                case 'update_amount':
                    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
                    $scope = isset($_POST['scope']) ? sanitize_text_field($_POST['scope']) : 'all';
                    if ($amount <= 0) {
                        wp_send_json_error(['message' => __('Invalid amount.', 'woocommerce-gateway-idem')]);
                    }
                    $response = $api_client->update_recurring_amount($request_id, $amount, $scope);

                    // If IDEM API call was successful, update WooCommerce subscription amount too
                    if (!is_wp_error($response) && (!isset($response['status']) || ($response['status'] !== 'KO' && $response['status'] !== 'ERROR'))) {
                        if (function_exists('wcs_get_subscription')) {
                            $subscription = wcs_get_subscription($subscription_id);
                            if ($subscription) {
                                if ($scope === 'all') {
                                    // Get current amounts for tax calculations
                                    $old_total = $subscription->get_total();
                                    $old_subtotal = floatval($subscription->get_subtotal());

                                    // Calculate proportional change
                                    $ratio = ($old_subtotal > 0) ? $amount / $old_total : 1;

                                    // Update line items proportionally if they exist
                                    $items = $subscription->get_items();
                                    if (!empty($items)) {
                                        foreach ($items as $item) {
                                            /** @var WC_Order_Item_Product $item */
                                            if (method_exists($item, 'get_total') && method_exists($item, 'set_total')) {
                                                $old_item_total = (float) $item->get_total();
                                                $new_item_total = $old_item_total * $ratio;
                                                $item->set_total($new_item_total);
                                                if (method_exists($item, 'set_subtotal')) {
                                                    $item->set_subtotal($new_item_total);
                                                }
                                                if (method_exists($item, 'save')) {
                                                    $item->save();
                                                }
                                            }
                                        }
                                    }

                                    // Update the subscription total
                                    $subscription->set_total($amount);

                                    // Recalculate totals to ensure everything is in sync
                                    $subscription->calculate_totals();
                                    $subscription->save();

                                    $note = sprintf(
                                        __('Subscription amount updated to %s via IDEM API (all future payments).', 'woocommerce-gateway-idem'),
                                        wc_price($amount)
                                    );
                                } else {
                                    // For 'next' scope, store the override amount in meta for the next payment only
                                    $subscription->update_meta_data('_idem_next_payment_override', $amount);
                                    $subscription->save();

                                    $note = sprintf(
                                        __('Next payment amount changed to %s via IDEM API (single payment only).', 'woocommerce-gateway-idem'),
                                        wc_price($amount)
                                    );
                                }
                                $subscription->add_order_note($note);
                            }
                        }
                    }
                    break;

                case 'update_cycles':
                    $cycles = isset($_POST['cycles']) ? intval($_POST['cycles']) : 0;
                    if ($cycles <= 0) {
                        wp_send_json_error(['message' => __('Invalid number of cycles.', 'woocommerce-gateway-idem')]);
                    }
                    $response = $api_client->update_recurring_cycles($request_id, $cycles);

                    // Update WooCommerce subscription cycles if successful
                    if (!is_wp_error($response) && (!isset($response['status']) || ($response['status'] !== 'KO' && $response['status'] !== 'ERROR'))) {
                        if (function_exists('wcs_get_subscription')) {
                            $subscription = wcs_get_subscription($subscription_id);
                            if ($subscription) {
                                try {
                                    // Get the subscription's billing period and interval
                                    $billing_period = $subscription->get_billing_period();
                                    $billing_interval = $subscription->get_billing_interval();

                                    // Get the start date or next payment date as reference
                                    $start_date = $subscription->get_date('start');
                                    $next_payment = $subscription->get_date('next_payment');

                                    // Use next payment if available, otherwise use start date
                                    $reference_date = $next_payment ? $next_payment : $start_date;

                                    if ($reference_date) {
                                        // Calculate the new end date based on the cycles
                                        $reference_timestamp = strtotime($reference_date);

                                        // Calculate total duration from reference date
                                        $interval_string = $billing_interval . ' ' . $billing_period;

                                        // Calculate end date: reference + (cycles * interval)
                                        // Handle perpetual subscriptions (cycles = 0) by not setting an end date
                                        if ($cycles > 0) {
                                            $end_timestamp = strtotime('+' . ($cycles * $billing_interval) . ' ' . $billing_period, $reference_timestamp);
                                            $new_end_date = date('Y-m-d H:i:s', $end_timestamp);
                                            $subscription->update_dates(['end' => $new_end_date]);
                                        } else {
                                            // Perpetual subscription - remove end date
                                            $subscription->update_dates(['end' => 0]);
                                        }

                                        // Store the total cycles in meta data for reference
                                        $subscription->update_meta_data('_idem_total_cycles', $cycles);
                                        $subscription->save();

                                        if ($cycles > 0) {
                                            $note = sprintf(
                                                __('Subscription cycles updated to %d via IDEM API. End date set to %s.', 'woocommerce-gateway-idem'),
                                                $cycles,
                                                date_i18n(wc_date_format(), $end_timestamp)
                                            );
                                        } else {
                                            $note = __('Subscription updated to perpetual (unlimited cycles) via IDEM API. End date removed.', 'woocommerce-gateway-idem');
                                        }
                                    } else {
                                        // Fallback if we can't calculate dates
                                        $subscription->update_meta_data('_idem_total_cycles', $cycles);
                                        $subscription->save();

                                        $note = sprintf(__('Subscription cycles updated to %d via IDEM API.', 'woocommerce-gateway-idem'), $cycles);
                                    }
                                } catch (Exception $e) {
                                    // Log error but don't fail the operation
                                    wc_get_logger()->error('Failed to update subscription cycles: ' . $e->getMessage(), ['source' => 'idem-gateway']);

                                    // At least store the cycles in meta
                                    $subscription->update_meta_data('_idem_total_cycles', $cycles);
                                    $subscription->save();

                                    $note = sprintf(__('Subscription cycles updated to %d via IDEM API (end date calculation failed).', 'woocommerce-gateway-idem'), $cycles);
                                }

                                $subscription->add_order_note($note);
                            }
                        }
                    }
                    break;

                case 'skip_payment':
                    $extend = isset($_POST['extend']) ? rest_sanitize_boolean($_POST['extend']) : false;
                    $response = $api_client->skip_recurring_payment($request_id, $extend);

                    // Add note and update next payment date if successful
                    if (!is_wp_error($response) && (!isset($response['status']) || ($response['status'] !== 'KO' && $response['status'] !== 'ERROR'))) {
                        if (function_exists('wcs_get_subscription')) {
                            $subscription = wcs_get_subscription($subscription_id);
                            if ($subscription) {
                                // Get the current next payment date before we change it
                                $old_next_payment = $subscription->get_date('next_payment');

                                // Update the next payment date if returned by the API
                                if (!empty($response['PlatformRequest']) && !empty($response['PlatformRequest']['recurring_next'])) {
                                    try {
                                        $new_next_date = $response['PlatformRequest']['recurring_next'];
                                        // Convert date format from YYYY-MM-DD to WooCommerce timestamp
                                        $timestamp = strtotime($new_next_date . ' 00:00:00');
                                        if ($timestamp) {
                                            // Clear any scheduled payment actions for the old date
                                            if ($old_next_payment && function_exists('as_unschedule_all_actions')) {
                                                // Unschedule the payment action for this subscription
                                                as_unschedule_all_actions(
                                                    'woocommerce_scheduled_subscription_payment',
                                                    [
                                                        'subscription_id' => $subscription->get_id()
                                                    ],
                                                    'woocommerce-subscriptions'
                                                );
                                            }

                                            // Update the subscription's next payment date
                                            $subscription->update_dates(['next_payment' => date('Y-m-d H:i:s', $timestamp)]);

                                            // If extending, also update the end date
                                            if ($extend) {
                                                $billing_period = $subscription->get_billing_period();
                                                $billing_interval = $subscription->get_billing_interval();
                                                $current_end = $subscription->get_date('end');

                                                if ($current_end && $current_end !== '0000-00-00 00:00:00') {
                                                    // Extend the end date by one billing cycle
                                                    $new_end_timestamp = strtotime('+' . $billing_interval . ' ' . $billing_period, strtotime($current_end));
                                                    $subscription->update_dates(['end' => date('Y-m-d H:i:s', $new_end_timestamp)]);
                                                }
                                            }

                                            // Save subscription to trigger WooCommerce to reschedule the payment
                                            $subscription->save();

                                            $note = $extend
                                                ? sprintf(
                                                    __('Next payment skipped and subscription extended by one cycle via IDEM API. Next payment date updated to %s.', 'woocommerce-gateway-idem'),
                                                    date_i18n(wc_date_format(), $timestamp)
                                                )
                                                : sprintf(
                                                    __('Next payment skipped via IDEM API. Next payment date updated to %s.', 'woocommerce-gateway-idem'),
                                                    date_i18n(wc_date_format(), $timestamp)
                                                );
                                        } else {
                                            // Fallback if date parsing fails
                                            $note = $extend
                                                ? __('Next payment skipped and subscription extended by one cycle via IDEM API.', 'woocommerce-gateway-idem')
                                                : __('Next payment skipped via IDEM API.', 'woocommerce-gateway-idem');
                                        }
                                    } catch (Exception $e) {
                                        // Log error but don't fail the operation
                                        wc_get_logger()->error('Failed to update subscription next payment date: ' . $e->getMessage(), ['source' => 'idem-gateway']);
                                        $note = $extend
                                            ? __('Next payment skipped and subscription extended by one cycle via IDEM API.', 'woocommerce-gateway-idem')
                                            : __('Next payment skipped via IDEM API.', 'woocommerce-gateway-idem');
                                    }
                                } else {
                                    // No date returned from API - calculate it ourselves
                                    try {
                                        if ($old_next_payment && $old_next_payment !== '0000-00-00 00:00:00') {
                                            $billing_period = $subscription->get_billing_period();
                                            $billing_interval = $subscription->get_billing_interval();

                                            // Calculate new next payment date
                                            $new_timestamp = strtotime('+' . $billing_interval . ' ' . $billing_period, strtotime($old_next_payment));

                                            // Clear scheduled actions
                                            if (function_exists('as_unschedule_all_actions')) {
                                                as_unschedule_all_actions(
                                                    'woocommerce_scheduled_subscription_payment',
                                                    [
                                                        'subscription_id' => $subscription->get_id()
                                                    ],
                                                    'woocommerce-subscriptions'
                                                );
                                            }

                                            // Update dates
                                            $subscription->update_dates(['next_payment' => date('Y-m-d H:i:s', $new_timestamp)]);

                                            if ($extend) {
                                                $current_end = $subscription->get_date('end');
                                                if ($current_end && $current_end !== '0000-00-00 00:00:00') {
                                                    $new_end_timestamp = strtotime('+' . $billing_interval . ' ' . $billing_period, strtotime($current_end));
                                                    $subscription->update_dates(['end' => date('Y-m-d H:i:s', $new_end_timestamp)]);
                                                }
                                            }

                                            $subscription->save();

                                            $note = $extend
                                                ? sprintf(
                                                    __('Next payment skipped and subscription extended by one cycle via IDEM API. Next payment date updated to %s.', 'woocommerce-gateway-idem'),
                                                    date_i18n(wc_date_format(), $new_timestamp)
                                                )
                                                : sprintf(
                                                    __('Next payment skipped via IDEM API. Next payment date updated to %s.', 'woocommerce-gateway-idem'),
                                                    date_i18n(wc_date_format(), $new_timestamp)
                                                );
                                        } else {
                                            $note = $extend
                                                ? __('Next payment skipped and subscription extended by one cycle via IDEM API.', 'woocommerce-gateway-idem')
                                                : __('Next payment skipped via IDEM API.', 'woocommerce-gateway-idem');
                                        }
                                    } catch (Exception $e) {
                                        wc_get_logger()->error('Failed to calculate next payment date: ' . $e->getMessage(), ['source' => 'idem-gateway']);
                                        $note = $extend
                                            ? __('Next payment skipped and subscription extended by one cycle via IDEM API.', 'woocommerce-gateway-idem')
                                            : __('Next payment skipped via IDEM API.', 'woocommerce-gateway-idem');
                                    }
                                }
                                $subscription->add_order_note($note);
                            }
                        }
                    }
                    break;

                default:
                    wp_send_json_error(['message' => __('Invalid action.', 'woocommerce-gateway-idem')]);
            }

            if (is_wp_error($response)) {
                wp_send_json_error(['message' => $response->get_error_message()]);
            }

            // Check if the API response indicates an error
            if (isset($response['status']) && ($response['status'] === 'KO' || $response['status'] === 'ERROR')) {
                $error_msg = isset($response['msg']) ? $response['msg'] : __('Unknown API error', 'woocommerce-gateway-idem');
                wp_send_json_error(['message' => $error_msg]);
            }


            wp_send_json_success(['message' => __('Action completed successfully!', 'woocommerce-gateway-idem')]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Check if WooCommerce Subscriptions is active and required functions exist
     */
    private function check_subscriptions_support()
    {
        return function_exists('wcs_get_subscription') && function_exists('wcs_order_contains_subscription');
    }

    /**
     * Resolve and persist the IDEM recurring request ID for a subscription.
     * Tries subscription meta, then parent order meta, then IDEM API search by parent order reference.
     *
     * @param WC_Subscription $subscription
     * @param bool $add_note Whether to add an order note when linking the ID
     * @return string|null The request ID if found, otherwise null
     */
    private function resolve_subscription_request_id($subscription, $add_note = true)
    {
        // 1) Subscription meta
        $request_id = $subscription->get_meta('_idem_recurring_request_id');
        if (!empty($request_id)) {
            return $request_id;
        }

        // 2) Parent order meta
        $parent_order = wc_get_order($subscription->get_parent_id());
        if ($parent_order) {
            $parent_request_id = $parent_order->get_meta('_idem_request_id');
            if (!empty($parent_request_id)) {
                $subscription->update_meta_data('_idem_recurring_request_id', $parent_request_id);
                $subscription->save();
                if ($add_note) {
                    $subscription->add_order_note(__('IDEM recurring request ID found in parent order and linked to subscription.', 'woocommerce-gateway-idem'));
                }
                return $parent_request_id;
            }
        }

        // 3) IDEM API search by reference (parent order ID)
        $api_key = $this->get_env_option('api_token');
        if (!empty($api_key)) {
            try {
                $api_client = new WC_Idem_API_Client(
                    $api_key,
                    'yes' === $this->get_option('test_mode'),
                    wc_get_logger(),
                    $this->get_option('test_url')
                );
                $response = $api_client->search_requests(['reference' => $subscription->get_parent_id()]);
                if (!is_wp_error($response) && !empty($response['data']['requests'])) {
                    $first = $response['data']['requests'][0];
                    $found_request_id = $first['request_id'] ?? ($first['id'] ?? null);
                    if (!empty($found_request_id)) {
                        $subscription->update_meta_data('_idem_recurring_request_id', $found_request_id);
                        $subscription->save();
                        if ($add_note) {
                            $subscription->add_order_note(__('IDEM recurring request ID found via IDEM API and linked to subscription.', 'woocommerce-gateway-idem'));
                        }
                        return $found_request_id;
                    }
                }
            } catch (Exception $e) {
                wc_get_logger()->debug('IDEM sync lookup error: ' . $e->getMessage(), ['source' => 'idem']);
            }
        }

        return null;
    }



    /**
     * AJAX handler: Check update status
     */
    public function idem_check_update_status_ajax_handler()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'idem_sync_subscriptions')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Get update status
        require_once dirname(__FILE__) . '/class-wc-idem-auto-updater.php';
        $updater = new WC_IDEM_Auto_Updater($this);
        $status = $updater->get_update_status();

        wp_send_json_success($status);
    }

    /**
     * AJAX handler: Manual update
     */
    public function idem_manual_update_ajax_handler()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'idem_sync_subscriptions')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Initialize updater
        require_once dirname(__FILE__) . '/class-wc-idem-auto-updater.php';
        $updater = new WC_IDEM_Auto_Updater($this);

        // Set flag to indicate manual update
        set_transient('idem_manual_update', true, 60);

        // Perform update
        ob_start();
        $updater->check_and_update();
        ob_end_clean();

        // Check if update was successful
        $status = $updater->get_update_status();

        if (!$status['update_available']) {
            wp_send_json_success([
                'message' => 'Plugin successfully updated to version ' . $status['current_version'],
                'new_version' => $status['current_version']
            ]);
        } else {
            // Check logs for error message
            $log_file = wp_upload_dir()['basedir'] . '/idem-backups/auto-update.log';
            $recent_log = '';

            if (file_exists($log_file)) {
                $logs = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($logs) {
                    $recent_log = end($logs);
                }
            }

            wp_send_json_error([
                'message' => 'Update failed. ' . $recent_log
            ]);
        }
    }

    /**
     * AJAX handler: Rollback
     */
    public function idem_rollback_ajax_handler()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'idem_sync_subscriptions')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Get backup file
        if (!isset($_POST['backup'])) {
            wp_send_json_error(['message' => 'No backup file specified']);
        }

        $backup_file = sanitize_file_name($_POST['backup']);
        $backup_dir = wp_upload_dir()['basedir'] . '/idem-backups';
        $backup_path = $backup_dir . '/' . $backup_file;

        // Verify backup file exists
        if (!file_exists($backup_path)) {
            wp_send_json_error(['message' => 'Backup file not found']);
        }

        // Initialize updater
        require_once dirname(__FILE__) . '/class-wc-idem-auto-updater.php';
        $updater = new WC_IDEM_Auto_Updater($this);

        // Perform rollback
        $result = $updater->rollback($backup_path);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => 'Rollback failed: ' . $result->get_error_message()]);
        }

        wp_send_json_success(['message' => 'Successfully rolled back to previous version']);
    }

    /**
     * Get cached WooCommerce checkout configuration
     *
     * Returns cached checkout description and icon from transient.
     * If cache is empty/expired, returns null values (uses defaults).
     *
     * @return array Array with 'checkout_description' and 'checkout_icon' keys
     */
    public function get_cached_woocommerce_config() {
        $transient_key = 'idem_woo_checkout_config';
        $cached = get_transient($transient_key);

        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        // Cache miss - return defaults
        return [
            'checkout_title' => null,
            'checkout_title_trusted' => null,
            'checkout_description' => null,
            'checkout_description_trusted' => null,
            'checkout_icon' => null,
            'crypto_only_mode' => 'disabled'
        ];
    }

    /**
     * Sanitize the "Logo height" setting: a whole number of pixels, or empty for the theme default.
     * WooCommerce calls validate_{key}_field() on save.
     *
     * @param string $key
     * @param string $value
     * @return string
     */
    public function validate_checkout_icon_height_field($key, $value)
    {
        $height = (int) $value; // not absint(): a negative value must mean "theme default", not its absolute
        if ($height < 1) {
            return '';
        }
        return (string) min($height, 300);
    }

    /**
     * Logo height chosen by the merchant for the checkout, in pixels (0 = theme default).
     *
     * @return int
     */
    public function get_checkout_icon_height()
    {
        return absint($this->get_option('checkout_icon_height', ''));
    }

    /**
     * Classic checkout: render the gateway icon with the merchant's logo height inline.
     * Themes size the payment-method logo in their CSS and most merchants cannot edit it,
     * so the height (and a matching max-height, which themes commonly cap) is set inline
     * with !important; the width follows to keep the aspect ratio. The block checkout
     * receives the same height through get_payment_method_data() (icon_height).
     *
     * @return string
     */
    public function get_icon()
    {
        $height = $this->get_checkout_icon_height();
        if ($height < 1 || empty($this->icon)) {
            return parent::get_icon();
        }
        $style = sprintf('height:%1$dpx !important;max-height:%1$dpx !important;width:auto !important;max-width:none !important;', $height);
        $icon  = '<img src="' . esc_url(WC_HTTPS::force_https_url($this->icon)) . '" alt="' . esc_attr($this->get_title()) . '" style="' . esc_attr($style) . '" />';
        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }

    /**
     * Get the checkout description for the current customer.
     *
     * Returns the trusted-customer description (pushed from the EasyProcess admin)
     * when the customer has at least one paid order on THIS store, resolved 100%
     * locally (no HTTP call) to avoid any checkout latency. Falls back to the
     * standard description otherwise.
     *
     * @return string
     */
    public function get_checkout_description_for_customer()
    {
        $config = $this->get_cached_woocommerce_config();
        if (!empty($config['checkout_description_trusted']) && $this->current_customer_has_paid_order()) {
            return $config['checkout_description_trusted'];
        }
        return $this->description;
    }

    /**
     * Filter callback (woocommerce_gateway_description): swap in the trusted
     * description on the classic checkout when the customer qualifies.
     *
     * @param string $description
     * @param string $gateway_id
     * @return string
     */
    public function maybe_use_trusted_description($description, $gateway_id)
    {
        if ($gateway_id !== $this->id) {
            return $description;
        }
        if (is_admin() && !wp_doing_ajax()) {
            return $description;
        }
        // Only swap when the trusted override actually applies — otherwise return
        // the incoming value so earlier filter callbacks are preserved.
        $config = $this->get_cached_woocommerce_config();
        if (empty($config['checkout_description_trusted']) || !$this->current_customer_has_paid_order()) {
            return $description;
        }
        return $config['checkout_description_trusted'];
    }

    /**
     * Get the checkout title for the current customer.
     *
     * Returns the trusted-customer title (pushed from the EasyProcess admin)
     * when the customer has at least one paid order on THIS store, resolved 100%
     * locally (no HTTP call) to avoid any checkout latency. Falls back to the
     * standard title otherwise.
     *
     * @return string
     */
    public function get_checkout_title_for_customer()
    {
        $config = $this->get_cached_woocommerce_config();
        if (!empty($config['checkout_title_trusted']) && $this->current_customer_has_paid_order()) {
            return $config['checkout_title_trusted'];
        }
        return $this->title;
    }

    /**
     * Filter callback (woocommerce_gateway_title): swap in the trusted
     * title on the classic checkout when the customer qualifies.
     *
     * @param string $title
     * @param string $gateway_id
     * @return string
     */
    public function maybe_use_trusted_title($title, $gateway_id)
    {
        if ($gateway_id !== $this->id) {
            return $title;
        }
        if (is_admin() && !wp_doing_ajax()) {
            return $title;
        }
        // Only swap when the trusted override actually applies — otherwise return
        // the incoming value so earlier filter callbacks are preserved.
        $config = $this->get_cached_woocommerce_config();
        if (empty($config['checkout_title_trusted']) || !$this->current_customer_has_paid_order()) {
            return $title;
        }
        return $config['checkout_title_trusted'];
    }

    /**
     * Check locally whether the current customer already has a paid order
     * (wc-completed or wc-processing) on this store.
     *
     * The customer is resolved from the logged-in user, the checkout AJAX
     * post_data (classic checkout refresh), or the WC session billing email.
     * Result is cached for the duration of the request.
     *
     * @return bool
     */
    private function current_customer_has_paid_order()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $cached = false;

        // Logged-in customer: check by user id, then by the ACCOUNT email only —
        // a posted/session email must not let a logged-in user borrow another
        // customer's guest order history.
        if (is_user_logged_in()) {
            $orders = wc_get_orders([
                'customer' => get_current_user_id(),
                'status'   => ['wc-processing', 'wc-completed'],
                'limit'    => 1,
                'return'   => 'ids',
            ]);
            if (!empty($orders)) {
                return $cached = true;
            }
            $email = wp_get_current_user()->user_email;
        } else {
            // Guest: resolve the email from the checkout AJAX post_data, direct post,
            // then the WC session
            $email = '';
            if (!empty($_POST['post_data']) && is_string($_POST['post_data'])) {
                parse_str(wp_unslash($_POST['post_data']), $checkout_post_data);
                if (!empty($checkout_post_data['billing_email'])) {
                    $email = sanitize_email($checkout_post_data['billing_email']);
                }
            }
            if (empty($email) && !empty($_POST['billing_email'])) {
                $email = sanitize_email(wp_unslash($_POST['billing_email']));
            }
            if (empty($email) && function_exists('WC') && WC()->customer) {
                $email = WC()->customer->get_billing_email();
            }
        }
        if (empty($email) || !is_email($email)) {
            return $cached = false;
        }

        // Local order lookup by billing email (guest orders included)
        $orders = wc_get_orders([
            'customer' => $email,
            'status'   => ['wc-processing', 'wc-completed'],
            'limit'    => 1,
            'return'   => 'ids',
        ]);
        return $cached = !empty($orders);
    }

    /**
     * Clear WooCommerce config cache
     *
     * Forces default values to be used until next update from EasyProcess.
     */
    public function clear_woocommerce_config_cache() {
        delete_transient('idem_woo_checkout_config');
    }

    /**
     * API action: Get WooCommerce products with optional filters.
     *
     * Supported filters in $data:
     *  - status: product status (publish, draft, pending, private). Default: any.
     *  - name: search by product name (partial match).
     *  - description: search keyword in short or long description.
     *  - category: filter by category slug or ID.
     *  - type: product type (simple, variable, grouped, external).
     *  - per_page: results per page (default 50, max 200).
     *  - page: page number (default 1).
     *
     * @param array $data Request data with optional filters.
     * @return array Products list with pagination info.
     */
    private function api_get_products(array $data): array
    {
        $per_page = isset($data['per_page']) ? min(absint($data['per_page']), 200) : 50;
        $page     = isset($data['page']) ? max(1, absint($data['page'])) : 1;
        $status   = isset($data['status']) ? sanitize_text_field($data['status']) : 'any';

        $args = [
            'limit'   => $per_page,
            'page'    => $page,
            'status'  => $status,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
        ];

        if (!empty($data['type'])) {
            $args['type'] = sanitize_text_field($data['type']);
        }

        if (!empty($data['category'])) {
            $args['category'] = [sanitize_text_field($data['category'])];
        }

        // Name search via WooCommerce product query
        if (!empty($data['name'])) {
            $args['s'] = sanitize_text_field($data['name']);
        }

        $products = wc_get_products($args);

        // If description filter is set, do a post-query filter
        $description_keyword = !empty($data['description']) ? sanitize_text_field($data['description']) : '';

        $result = [];
        foreach ($products as $product) {
            if ($description_keyword !== '') {
                $haystack = strtolower($product->get_description() . ' ' . $product->get_short_description());
                if (strpos($haystack, strtolower($description_keyword)) === false) {
                    continue;
                }
            }

            $result[] = [
                'id'                => $product->get_id(),
                'name'              => $product->get_name(),
                'slug'              => $product->get_slug(),
                'type'              => $product->get_type(),
                'status'            => $product->get_status(),
                'price'             => $product->get_price(),
                'regular_price'     => $product->get_regular_price(),
                'sale_price'        => $product->get_sale_price(),
                'sku'               => $product->get_sku(),
                'short_description' => $product->get_short_description(),
                'url'               => get_permalink($product->get_id()),
                'image'             => $product->get_image_id() ? wp_get_attachment_url($product->get_image_id()) : '',
                'date_created'      => $product->get_date_created() ? $product->get_date_created()->format('c') : null,
            ];
        }

        // Count total for pagination
        $count_args = $args;
        $count_args['return'] = 'ids';
        $count_args['limit']  = -1;
        unset($count_args['page']);
        $total = count(wc_get_products($count_args));

        return [
            'items'       => $result,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ];
    }

    /**
     * API action: Get a single WooCommerce product with full details.
     *
     * @param int $product_id Product ID.
     * @return array|WP_Error Product data or error.
     */
    private function api_get_product(int $product_id)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return new \WP_Error('product_not_found', 'Product not found');
        }

        $item = [
            'id'                => $product->get_id(),
            'name'              => $product->get_name(),
            'slug'              => $product->get_slug(),
            'type'              => $product->get_type(),
            'status'            => $product->get_status(),
            'price'             => $product->get_price(),
            'regular_price'     => $product->get_regular_price(),
            'sale_price'        => $product->get_sale_price(),
            'sku'               => $product->get_sku(),
            'short_description' => $product->get_short_description(),
            'description'       => $product->get_description(),
            'url'               => get_permalink($product->get_id()),
            'image'             => '',
            'categories'        => [],
            'tags'              => [],
            'attributes'        => [],
            'date_created'      => $product->get_date_created() ? $product->get_date_created()->format('c') : null,
            'date_modified'     => $product->get_date_modified() ? $product->get_date_modified()->format('c') : null,
            'stock_status'      => $product->get_stock_status(),
            'stock_quantity'    => $product->get_stock_quantity(),
            'weight'            => $product->get_weight(),
            'dimensions'        => [
                'length' => $product->get_length(),
                'width'  => $product->get_width(),
                'height' => $product->get_height(),
            ],
        ];

        $image_id = $product->get_image_id();
        if ($image_id) {
            $item['image'] = wp_get_attachment_url($image_id);
        }

        $gallery_ids = $product->get_gallery_image_ids();
        if (!empty($gallery_ids)) {
            $item['gallery'] = array_filter(array_map('wp_get_attachment_url', $gallery_ids));
        }

        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (!empty($terms) && !is_wp_error($terms)) {
            $item['categories'] = array_map(fn($term) => [
                'id'   => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ], $terms);
        }

        $tags = get_the_terms($product->get_id(), 'product_tag');
        if (!empty($tags) && !is_wp_error($tags)) {
            $item['tags'] = array_map(fn($tag) => [
                'id'   => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ], $tags);
        }

        foreach ($product->get_attributes() as $attribute) {
            $item['attributes'][] = [
                'name'    => $attribute->get_name(),
                'options' => $attribute->get_options(),
                'visible' => $attribute->get_visible(),
            ];
        }

        return $item;
    }

    /**
     * API action: Get WordPress pages with optional keyword search.
     *
     * Supported filters in $data:
     *  - search: keyword to search in title and content.
     *  - status: page status (publish, draft, private). Default: publish.
     *  - per_page: results per page (default 50, max 200).
     *  - page: page number (default 1).
     *
     * @param array $data Request data with optional filters.
     * @return array Pages list with pagination info.
     */
    private function api_get_pages(array $data): array
    {
        $per_page = isset($data['per_page']) ? min(absint($data['per_page']), 200) : 50;
        $page     = isset($data['page']) ? max(1, absint($data['page'])) : 1;
        $status   = isset($data['status']) ? sanitize_text_field($data['status']) : 'publish';

        $query_args = [
            'post_type'      => 'page',
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if (!empty($data['search'])) {
            $query_args['s'] = sanitize_text_field($data['search']);
        }

        $query = new \WP_Query($query_args);

        $result = [];
        foreach ($query->posts as $post) {
            // Use the raw, cleaned content — never apply_filters('the_content'), which runs every
            // third-party the_content hook. On a protected page (account / affiliation / verification)
            // one of those hooks can wp_redirect() during render, yielding a 302 + non-JSON body that
            // breaks the API. Raw content is also cheaper and side-effect-free; unrendered shortcodes
            // showing as text is acceptable for a content scan.
            $content = wp_strip_all_tags($post->post_content);

            $result[] = [
                'id'           => $post->ID,
                'title'        => $post->post_title,
                'slug'         => $post->post_name,
                'status'       => $post->post_status,
                'url'          => get_permalink($post->ID),
                'content'      => $content,
                'excerpt'      => !empty($post->post_excerpt) ? $post->post_excerpt : wp_trim_words($content, 80),
                'date_created' => get_post_datetime($post) ? get_post_datetime($post)->format('c') : null,
            ];
        }

        return [
            'items'       => $result,
            'total'       => (int) $query->found_posts,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) $query->max_num_pages,
        ];
    }
}
