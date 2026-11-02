<?php

/**
 * WooCommerce IDEM Crypto Gateway
 *
 * Provides a separate checkout button that redirects to the crypto payment provider
 * via the IDEM module's crypto_only mode.
 *
 * This gateway reuses the main IDEM gateway credentials and configuration.
 * It is only visible when crypto_only_mode is 'allowed' or 'forced' in the
 * EasyProcess checkout config (pushed via syncWooCommerceCheckoutConfig).
 *
 * @class    WC_Gateway_Idem_Crypto
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Idem_Crypto extends WC_Payment_Gateway
{
    public $id = 'idem_crypto';

    /** @var string Main gateway settings (shared credentials) */
    private $main_settings;

    /** @var string */
    private string $merchant_id;

    /** @var string */
    private string $merchant_key;

    /** @var string */
    private string $url;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->icon = apply_filters('woocommerce_idem_crypto_gateway_icon', 'https://gateway.idem-club.com/files/idem-club/woo-checkout-crypto.png');
        $this->has_fields = false;
        $this->method_title = __('IDEM Crypto Payment', 'woocommerce-gateway-idem');
        $this->method_description = __('Accept cryptocurrency payments via the IDEM Pay platform. This gateway redirects customers directly to the crypto payment provider.', 'woocommerce-gateway-idem');

        $this->title = __('Pay with Crypto', 'woocommerce-gateway-idem');
        $this->description = __('Pay securely using cryptocurrency.', 'woocommerce-gateway-idem');

        $this->supports = array('products', 'refunds');

        // Load main gateway settings (shared credentials, per-environment since 4.5.0)
        $this->main_settings = get_option('woocommerce_idem_settings', []);
        $this->merchant_id = $this->get_env_credential('merchant_id');
        $this->merchant_key = $this->get_env_credential('merchant_key');

        // Build URL from main gateway settings
        if ('yes' === (isset($this->main_settings['test_mode']) ? $this->main_settings['test_mode'] : 'no')) {
            $base_url = isset($this->main_settings['test_url']) ? $this->main_settings['test_url'] : '';
            if (empty($base_url)) {
                $base_url = 'https://gateway.idem-club.info';
            }
        } else {
            $base_url = isset($this->main_settings['gateway_url']) ? $this->main_settings['gateway_url'] : '';
            if (empty($base_url)) {
                $base_url = 'https://gateway.idem-club.com';
            }
        }
        $this->url = $base_url . '/idem/';

        // No own settings form — this gateway is controlled entirely from EasyProcess admin
        $this->init_settings();

        // Force enabled state from main gateway (init_settings may have overwritten it with empty value)
        $this->enabled = isset($this->main_settings['enabled']) ? $this->main_settings['enabled'] : 'no';

        // Disable if credentials or currency are invalid
        if (!$this->is_valid_for_use()) {
            $this->enabled = 'no';
        }

        // Register receipt page action
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        // Hide/show based on crypto_only_mode
        add_filter('woocommerce_available_payment_gateways', array($this, 'maybe_hide_crypto_gateway'));
    }

    /**
     * Read a per-environment value from the main gateway settings, matching
     * WC_Gateway_Idem::get_env_option() (per-environment blocks since 4.5.0), with a
     * one-release fallback to the legacy flat option. Used for the signing credentials
     * (merchant_id, merchant_key) and the per-environment sub_account.
     *
     * @param string $base merchant_id | merchant_key | sub_account
     * @return string
     */
    private function get_env_credential($base)
    {
        $env = ('yes' === (isset($this->main_settings['test_mode']) ? $this->main_settings['test_mode'] : 'no')) ? 'sandbox' : 'prod';
        $key = $env . '_' . $base;
        if (!empty($this->main_settings[$key])) {
            return $this->main_settings[$key];
        }
        return isset($this->main_settings[$base]) ? $this->main_settings[$base] : '';
    }

    /**
     * No admin options needed — controlled from EasyProcess
     */
    public function admin_options(): void
    {
        ?>
        <h2><?php _e('IDEM Crypto Payment', 'woocommerce-gateway-idem'); ?></h2>
        <div class="inline notice">
            <p>
                <?php _e('This payment method is controlled from your EasyProcess admin panel. Enable or disable it by changing the "Crypto Only Mode" setting in the merchant settings.', 'woocommerce-gateway-idem'); ?>
            </p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><strong>Disabled</strong>: <?php _e('This button is hidden at checkout.', 'woocommerce-gateway-idem'); ?></li>
                <li><strong>Allowed</strong>: <?php _e('Both IDEM Pay and Crypto buttons are shown at checkout.', 'woocommerce-gateway-idem'); ?></li>
                <li><strong>Forced</strong>: <?php _e('Only the Crypto button is shown at checkout.', 'woocommerce-gateway-idem'); ?></li>
            </ul>
        </div>
        <?php
    }

    /**
     * Check if gateway should be available (valid credentials + crypto mode enabled)
     */
    public function is_valid_for_use(): bool
    {
        if (empty($this->merchant_id) || empty($this->merchant_key)) {
            return false;
        }

        // Check currency support (same as main gateway)
        $allowed_currencies = array('USD', 'GBP', 'EUR', 'CAD');
        if (!in_array(get_woocommerce_currency(), $allowed_currencies)) {
            return false;
        }

        return true;
    }

    /**
     * Override is_available for both classic and Blocks checkout.
     * Blocks calls is_active() -> is_available(), not the woocommerce_available_payment_gateways filter.
     */
    public function is_available(): bool
    {
        if ('yes' !== $this->enabled) {
            return false;
        }

        if (!$this->is_valid_for_use()) {
            return false;
        }

        // Only available when crypto_only_mode is allowed or forced
        $mode = $this->get_crypto_mode();
        if (!in_array($mode, ['allowed', 'forced'])) {
            return false;
        }

        // Not available for subscription carts
        if (class_exists('WC_Subscriptions_Cart') && WC_Subscriptions_Cart::cart_contains_subscription()) {
            return false;
        }

        return true;
    }

    /**
     * Hide crypto gateway for classic checkout (mirrors is_available logic for the filter path)
     */
    public function maybe_hide_crypto_gateway($available_gateways)
    {
        if (!is_admin() && isset($available_gateways[$this->id])) {
            // Hide if crypto_only_mode is not enabled
            $main_gateway = isset($available_gateways['idem']) ? $available_gateways['idem'] : null;
            if (!$main_gateway) {
                $config = $this->get_crypto_mode();
            } else {
                $config = $main_gateway->get_cached_woocommerce_config();
                $config = isset($config['crypto_only_mode']) ? $config['crypto_only_mode'] : 'disabled';
            }

            if (!in_array($config, ['allowed', 'forced'])) {
                unset($available_gateways[$this->id]);
            }

            // Hide for subscription carts — crypto providers don't support recurring
            if (isset($available_gateways[$this->id]) && class_exists('WC_Subscriptions_Cart')
                && WC_Subscriptions_Cart::cart_contains_subscription()) {
                unset($available_gateways[$this->id]);
            }
        }
        return $available_gateways;
    }

    /**
     * Get crypto_only_mode from the shared transient
     */
    private function get_crypto_mode()
    {
        $cached = get_transient('idem_woo_checkout_config');
        if ($cached !== false && is_array($cached) && isset($cached['crypto_only_mode'])) {
            return $cached['crypto_only_mode'];
        }
        return 'disabled';
    }

    /**
     * Process the payment — redirect to receipt page for form auto-submit
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $order->update_status('pending-payment', __('Awaiting crypto payment', 'woocommerce'));
        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(true)
        );
    }

    /**
     * Receipt page — display form and auto-submit to IDEM gateway
     */
    public function receipt_page($order): void
    {
        echo '<p>' . __('Thank you for your order, please click the button below to pay with cryptocurrency.', 'woocommerce-gateway-idem') . '</p>';
        echo $this->generate_crypto_form($order);
    }

    /**
     * Generate the payment form with crypto_only=1
     */
    public function generate_crypto_form($order_id): string
    {
        $order = wc_get_order($order_id);

        $data_to_send = array(
            // Merchant details
            'merchant_id'    => $this->merchant_id,
            'sub_account_id' => $this->get_env_credential('sub_account'),
            'completed_url'  => $this->get_return_url($order),
            'cancelled_url'  => $order->get_cancel_order_url(),
            'rejected_url'   => $order->get_cancel_order_url(),

            // Version information
            'platform' => array(
                'name' => 'WordPress',
                'wp_version' => get_bloginfo('version'),
                'wc_version' => WC()->version,
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

            // Crypto-only flag
            'crypto_only'       => 1,
        );

        // Calculate the hash (same formula as main gateway — crypto_only is NOT in the hash)
        $data_to_send['hash'] = md5($this->merchant_id . $data_to_send['amount'] . $data_to_send['currency'] . $data_to_send['order_ref'] . $this->merchant_key);

        $fields = array();
        foreach ($data_to_send as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $fields[] = '<input type="hidden" name="' . $key . '[' . $k . ']" value="' . esc_attr($v) . '" />';
                }
            } else {
                $fields[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }
        }

        return '<form action="' . esc_url($this->url) . '" method="post" id="easyprocess_crypto_payment_form">
                ' . implode('', $fields) . '
                <input type="submit" class="button-alt" id="submit_easyprocess_crypto_form" value="' . __('Pay with Crypto via IDEM', 'woocommerce-gateway-idem') . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Cancel order &amp; restore cart', 'woocommerce-gateway-idem') . '</a>
                <script type="text/javascript">
                    jQuery(function(){
                        jQuery("body").block(
                            {
                                message: "' . __('Please wait, you will be redirected to the crypto payment provider.', 'woocommerce-gateway-idem') . '",
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
                        jQuery( "#submit_easyprocess_crypto_form" ).click();
                    });
                </script>
            </form>';
    }

    /**
     * Process a refund — delegates to the main gateway's API client
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        // Use the main IDEM gateway for refunds since it has the API client
        $gateways = WC()->payment_gateways->payment_gateways();
        if (isset($gateways['idem']) && method_exists($gateways['idem'], 'process_refund')) {
            return $gateways['idem']->process_refund($order_id, $amount, $reason);
        }
        return new WP_Error('refund_failed', __('Main IDEM gateway not available for refund processing.', 'woocommerce-gateway-idem'));
    }
}
