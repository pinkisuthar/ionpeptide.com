<?php
/**
 * Plugin Name: Monarch ACH Payment Gateway for WooCommerce
 * Description: Monarch Payment Gateway.
 * Version: 2.23
 * Author: Monarch Technologies Inc.
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Tested up to: 6.9
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * Text Domain: monarch-payments-for-woocommerce
 * Domain Path: /languages
 */

// Standard WordPress security check
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active (supports both single site and multisite)
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter name.
$monarch_active_plugins = apply_filters('active_plugins', get_option('active_plugins'));

// Check for multisite network-activated plugins
if (is_multisite()) {
    $monarch_network_plugins = get_site_option('active_sitewide_plugins');
    if ($monarch_network_plugins) {
        $monarch_active_plugins = array_merge($monarch_active_plugins, array_keys($monarch_network_plugins));
    }
}

if (!in_array('woocommerce/woocommerce.php', $monarch_active_plugins) && !class_exists('WooCommerce')) {
    return;
}

define('WC_MONARCH_ACH_PLUGIN_FILE', __FILE__);
define('WC_MONARCH_ACH_VERSION', '2.23');
define('WC_MONARCH_ACH_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_MONARCH_ACH_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Load translations from /languages when present.
 * WordPress.org auto-loads translations for the plugin slug; for private builds that ship .mo
 * in the plugin, this is still the supported hook.
 */
function monarch_ach_load_textdomain() {
    // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Custom /languages in non–WordPress.org distributions.
    load_plugin_textdomain(
        'monarch-payments-for-woocommerce',
        false,
        dirname(plugin_basename(WC_MONARCH_ACH_PLUGIN_FILE)) . '/languages'
    );
}
add_action('plugins_loaded', 'monarch_ach_load_textdomain', 5);

/**
 * Declare compatibility with WooCommerce Cart & Checkout Blocks (FeaturesUtil).
 * Must run inside `before_woocommerce_init`. Register on `plugins_loaded` so the callback is always
 * scheduled before WooCommerce fires that action (fixes missed registration if load order differs).
 */
function monarch_ach_declare_cart_checkout_blocks_compatibility() {
    if (!class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        return;
    }
    $file = WC_MONARCH_ACH_PLUGIN_FILE;
    $basename = plugin_basename($file);
    // Prefer stable plugin id (directory/file.php); also pass full path for path-based resolution.
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', $basename, true);
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', $file, true);
}

function monarch_ach_register_cart_checkout_blocks_compatibility() {
    add_action('before_woocommerce_init', 'monarch_ach_declare_cart_checkout_blocks_compatibility', 0);
}

add_action('plugins_loaded', 'monarch_ach_register_cart_checkout_blocks_compatibility', 0);

/**
 * If WooCommerce still exposes this plugin under wcSettings.incompatibleExtensions (stale cache or ID mismatch),
 * remove it in the block editor so the checkout/cart block warning does not show.
 */
function monarch_ach_strip_wc_blocks_incompatible_notice() {
    static $done = false;
    if ($done) {
        return;
    }
    if (!is_admin() || !function_exists('wp_add_inline_script')) {
        return;
    }
    if (!wp_script_is('wc-settings', 'registered')) {
        return;
    }
    $basename = plugin_basename(WC_MONARCH_ACH_PLUGIN_FILE);
    $dir = dirname($basename);
    $plugin_name = 'Monarch WooCommerce Payment Gateway';
    $js = sprintf(
        '(function(){var dir=%s,pluginTitle=%s;function strip(){try{if(!window.wcSettings||!wcSettings.incompatibleExtensions)return;wcSettings.incompatibleExtensions=wcSettings.incompatibleExtensions.filter(function(e){if(!e)return false;var t=String(e.title||""),id=String(e.id||"");if(t===pluginTitle)return false;if(id&&id===dir)return false;if(t.indexOf("Monarch ACH")!==-1)return false;if(t.indexOf("Monarch")!==-1&&t.indexOf("WooCommerce")!==-1&&t.indexOf("Payment")!==-1)return false;return true;});}catch(err){}}strip();setTimeout(strip,0);setTimeout(strip,300);setTimeout(strip,1200);})();',
        wp_json_encode($dir),
        wp_json_encode($plugin_name)
    );
    wp_add_inline_script('wc-settings', $js, 'after');
    $done = true;
}

add_action('enqueue_block_editor_assets', 'monarch_ach_strip_wc_blocks_incompatible_notice', 100);
add_action('admin_print_footer_scripts', 'monarch_ach_strip_wc_blocks_incompatible_notice', 100);

/**
 * Handle bank callback via WordPress template_redirect hook
 * This is more reliable than the early check as it works after WordPress is fully loaded
 */
add_action('template_redirect', 'monarch_handle_bank_callback_redirect', 1);
function monarch_handle_bank_callback_redirect() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public bank callback query; not an admin or form POST.
    if (!isset($_GET['monarch_bank_callback']) || $_GET['monarch_bank_callback'] !== '1') {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only org_id from same callback URL.
    $org_id = isset($_GET['org_id']) ? sanitize_text_field(wp_unslash($_GET['org_id'])) : '';
    $checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/');

    // Output success page
    ?>
<!DOCTYPE html>
<html>
<head>
    <title>Bank Linked Successfully</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            background: linear-gradient(135deg, #f0fff4 0%, #e8f5e9 100%);
        }
        .success-container {
            text-align: center;
            padding: 40px 50px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.12);
            max-width: 480px;
            width: 100%;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: #28a745;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 40px;
            color: white;
        }
        h1 { color: #1a1a1a; font-size: 26px; margin: 0 0 15px 0; font-weight: 600; }
        p { color: #666; font-size: 15px; margin: 0 0 10px 0; line-height: 1.5; }
        .confirm-btn {
            display: inline-block;
            background: #28a745;
            color: white;
            border: none;
            padding: 16px 36px;
            font-size: 17px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 20px;
            transition: all 0.2s ease;
            width: 100%;
            max-width: 320px;
        }
        .confirm-btn:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
        }
        .note { font-size: 12px; color: #999; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon">&#10003;</div>
        <h1>Bank Linked Successfully!</h1>
        <p>Your bank account has been successfully linked.</p>
        <p>Click the button below to return to checkout.</p>
        <button type="button" class="confirm-btn" onclick="closeAndReturn()">Close & Return to Checkout</button>
        <p class="note">This window will close and you will be redirected to checkout.</p>
    </div>
    <script>
        var orgId = "<?php echo esc_js($org_id); ?>";
        var checkoutUrl = "<?php echo esc_js($checkout_url); ?>";

        function notifyParent() {
            try {
                if (window.opener && !window.opener.closed) {
                    window.opener.postMessage({ type: "MONARCH_BANK_CALLBACK", status: "SUCCESS", org_id: orgId }, "*");
                }
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: "MONARCH_BANK_CALLBACK", status: "SUCCESS", org_id: orgId }, "*");
                }
            } catch (e) {}
        }

        function closeAndReturn() {
            notifyParent();
            if (window.opener) { window.close(); }
            setTimeout(function() { window.location.href = checkoutUrl; }, 300);
        }

        notifyParent();
    </script>
</body>
</html>
    <?php
    exit;
}

class WC_Monarch_ACH_Gateway_Plugin {

    private static $gateway_instance = null;

    /**
     * Order IDs for which ACH Payment Details were already rendered this request.
     * Block themes / order-confirmation templates may fire woocommerce_order_details_after_order_table more than once.
     *
     * @var array<int, true>
     */
    private static $monarch_ach_order_details_rendered = array();

    public function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Register AJAX handlers early (before gateway is instantiated)
        add_action('init', array($this, 'register_ajax_handlers'));
    }

    public function init() {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        include_once WC_MONARCH_ACH_PLUGIN_PATH . 'includes/class-wc-monarch-logger.php';
        include_once WC_MONARCH_ACH_PLUGIN_PATH . 'includes/class-monarch-api.php';
        include_once WC_MONARCH_ACH_PLUGIN_PATH . 'includes/class-wc-monarch-ach-gateway.php';
        include_once WC_MONARCH_ACH_PLUGIN_PATH . 'includes/class-wc-monarch-admin.php';
        include_once WC_MONARCH_ACH_PLUGIN_PATH . 'includes/class-wc-monarch-cron.php';

        // Register WooCommerce Blocks support.
        // WooCommerce loads before this plugin alphabetically, so `woocommerce_blocks_loaded` has usually
        // already fired — a late `add_action('woocommerce_blocks_loaded', ...)` never runs and the gateway
        // never registers with Cart/Checkout Blocks (shows "Incompatible with block-based checkout").
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            if (did_action('woocommerce_blocks_loaded')) {
                $this->register_blocks_support();
            } else {
                add_action('woocommerce_blocks_loaded', array($this, 'register_blocks_support'));
            }
        }

        // Register customer-facing transaction display hook (must be here, not in gateway constructor)
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_transaction_details_for_customer'), 10, 1);
    }

    /**
     * Display transaction details to customers on order view page (My Account → Orders → View)
     * This is registered in plugin init() to ensure it always runs, not just when gateway is instantiated
     */
    public function display_transaction_details_for_customer($order) {
        // Only show for Monarch ACH payments
        if ($order->get_payment_method() !== 'monarch_ach') {
            return;
        }

        // Get transaction data from database
        global $wpdb;
        $table_name = esc_sql($wpdb->prefix . 'monarch_ach_transactions');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read for order display.
        $transaction = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name from esc_sql( $wpdb->prefix . constant ); values use prepare().
                'SELECT * FROM ' . $table_name . ' WHERE order_id = %d LIMIT 1',
                $order->get_id()
            )
        );

        // Track if transaction is from database (UTC) or order meta (local timezone)
        $is_db_transaction = false;

        // If no transaction in custom table, try to get from order meta
        if (!$transaction) {
            $transaction_id = $order->get_transaction_id();
            $monarch_transaction_id = $order->get_meta('_monarch_transaction_id');

            if ($transaction_id || $monarch_transaction_id) {
                // Create a pseudo-transaction object from order meta
                // Note: get_date_created() returns date in WordPress timezone, not UTC
                $transaction = (object) array(
                    'transaction_id' => $transaction_id ?: $monarch_transaction_id,
                    'status' => $this->map_order_status_to_transaction_status($order->get_status()),
                    'amount' => $order->get_total(),
                    'created_at' => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : ''
                );
            }
        } else {
            $is_db_transaction = true;
        }

        if (!$transaction) {
            return;
        }

        $order_id = (int) $order->get_id();
        if (isset(self::$monarch_ach_order_details_rendered[$order_id])) {
            return;
        }
        self::$monarch_ach_order_details_rendered[$order_id] = true;

        // Map status to user-friendly text
        $status_labels = array(
            'pending' => 'Processing',
            'processing' => 'Processing',
            'submitted' => 'Submitted',
            'completed' => 'Completed',
            'success' => 'Completed',
            'settled' => 'Completed',
            'approved' => 'Approved',
            'failed' => 'Failed',
            'declined' => 'Declined',
            'rejected' => 'Rejected',
            'returned' => 'Returned',
            'refunded' => 'Refunded',
            'voided' => 'Cancelled',
            'cancelled' => 'Cancelled'
        );

        $status_text = $status_labels[strtolower($transaction->status)] ?? ucfirst($transaction->status);

        // Status colors
        $status_colors = array(
            'pending' => '#0366d6',
            'processing' => '#0366d6',
            'submitted' => '#0366d6',
            'completed' => '#22863a',
            'success' => '#22863a',
            'settled' => '#22863a',
            'approved' => '#22863a',
            'failed' => '#cb2431',
            'declined' => '#cb2431',
            'rejected' => '#cb2431',
            'returned' => '#cb2431',
            'refunded' => '#6f42c1',
            'voided' => '#6a737d',
            'cancelled' => '#6a737d'
        );

        $status_color = $status_colors[strtolower($transaction->status)] ?? '#6a737d';

        ?>
        <section class="woocommerce-monarch-transaction-details">
            <h2>ACH Payment Details</h2>
            <table class="woocommerce-table shop_table monarch-transaction-table">
                <tbody>
                    <tr>
                        <th>Payment Method</th>
                        <td>ACH Bank Transfer</td>
                    </tr>
                    <tr>
                        <th>Transaction ID</th>
                        <td><code style="font-size: 12px;"><?php echo esc_html($transaction->transaction_id); ?></code></td>
                    </tr>
                    <tr>
                        <th>Payment Status</th>
                        <td>
                            <span style="background: <?php echo esc_attr($status_color); ?>15; color: <?php echo esc_attr($status_color); ?>; padding: 4px 10px; border-radius: 4px; font-weight: 500; font-size: 13px;">
                                <?php echo esc_html($status_text); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Amount</th>
                        <td><?php echo wp_kses_post(wc_price($transaction->amount)); ?></td>
                    </tr>
                    <?php if (!empty($transaction->created_at)): ?>
                    <tr>
                        <th>Date</th>
                        <td><?php
                            // DB transactions are stored in UTC, order meta dates are in local timezone
                            $date_string = $is_db_transaction ? $transaction->created_at . ' UTC' : $transaction->created_at;
                            echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($date_string)));
                        ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if (in_array(strtolower($transaction->status), array('pending', 'processing', 'submitted'))): ?>
            <p class="monarch-processing-notice" style="margin-top: 15px; padding: 12px; background: #f0f6fc; border-left: 4px solid #0366d6; font-size: 14px;">
                <strong>Note:</strong> ACH bank transfers typically take 2-5 business days to complete.
                You will receive an email notification once your payment has been processed.
            </p>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * Map WooCommerce order status to transaction status
     */
    private function map_order_status_to_transaction_status($order_status) {
        $status_map = array(
            'pending' => 'pending',
            'processing' => 'processing',
            'on-hold' => 'pending',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed'
        );
        return $status_map[$order_status] ?? 'pending';
    }

    /**
     * Register WooCommerce Blocks payment method support
     */
    public function register_blocks_support() {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }

        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        include_once WC_MONARCH_ACH_PLUGIN_PATH . 'includes/class-wc-monarch-blocks.php';

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function($payment_method_registry) {
                $payment_method_registry->register(new WC_Monarch_ACH_Blocks_Support());
            }
        );
    }

    /**
     * Register AJAX handlers
     * Note: Bank callback is now handled at the very top of the plugin file
     * before WordPress processes the request - see monarch_check_immediate_callback()
     */
    public function register_ajax_handlers() {
        add_action('wp_ajax_monarch_disconnect_bank', array($this, 'ajax_disconnect_bank'));
        add_action('wp_ajax_nopriv_monarch_disconnect_bank', array($this, 'ajax_disconnect_bank'));
        add_action('wp_ajax_monarch_create_organization', array($this, 'ajax_create_organization'));
        add_action('wp_ajax_nopriv_monarch_create_organization', array($this, 'ajax_create_organization'));
        add_action('wp_ajax_monarch_bank_connection_complete', array($this, 'ajax_bank_connection_complete'));
        add_action('wp_ajax_nopriv_monarch_bank_connection_complete', array($this, 'ajax_bank_connection_complete'));
        add_action('wp_ajax_monarch_check_bank_status', array($this, 'ajax_check_bank_status'));
        add_action('wp_ajax_nopriv_monarch_check_bank_status', array($this, 'ajax_check_bank_status'));
        add_action('wp_ajax_monarch_get_latest_paytoken', array($this, 'ajax_get_latest_paytoken'));
        add_action('wp_ajax_nopriv_monarch_get_latest_paytoken', array($this, 'ajax_get_latest_paytoken'));
        add_action('wp_ajax_monarch_manual_bank_entry', array($this, 'ajax_manual_bank_entry'));
        add_action('wp_ajax_nopriv_monarch_manual_bank_entry', array($this, 'ajax_manual_bank_entry'));
        // Bank linking URL for returning users
        add_action('wp_ajax_monarch_get_bank_linking_url', array($this, 'ajax_get_bank_linking_url'));
        add_action('wp_ajax_nopriv_monarch_get_bank_linking_url', array($this, 'ajax_get_bank_linking_url'));
        // Update organization details for returning users before bank linking
        add_action('wp_ajax_monarch_update_organization', array($this, 'ajax_update_organization'));
        add_action('wp_ajax_nopriv_monarch_update_organization', array($this, 'ajax_update_organization'));
        // CRON manual status update handler
        add_action('wp_ajax_monarch_manual_status_update', array($this, 'ajax_manual_status_update'));
        // Bank callback handler - outputs the success page when redirected from Monarch/Yodlee
        add_action('wp_ajax_monarch_bank_callback', array($this, 'ajax_bank_callback'));
        add_action('wp_ajax_nopriv_monarch_bank_callback', array($this, 'ajax_bank_callback'));
    }

    /**
     * AJAX handler for bank callback - outputs the success page
     * This is called when Monarch/Yodlee redirects back after bank linking
     * Using admin-ajax.php ensures WordPress always processes the request (no 404)
     */
    public function ajax_bank_callback() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only callback query arg from redirect.
        $org_id = isset($_GET['org_id']) ? sanitize_text_field(wp_unslash($_GET['org_id'])) : '';

        // Get URLs
        $checkout_url = wc_get_checkout_url();
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('monarch_ach_nonce');

        // Output the success page
        ?>
<!DOCTYPE html>
<html>
<head>
    <title>Bank Linked Successfully</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            background: linear-gradient(135deg, #f0fff4 0%, #e8f5e9 100%);
        }
        .success-container {
            text-align: center;
            padding: 40px 50px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.12);
            max-width: 480px;
            width: 100%;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: #28a745;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 40px;
            color: white;
        }
        h1 { color: #1a1a1a; font-size: 26px; margin: 0 0 15px 0; font-weight: 600; }
        p { color: #666; font-size: 15px; margin: 0 0 10px 0; line-height: 1.5; }
        .confirm-btn {
            display: inline-block;
            background: #28a745;
            color: white;
            border: none;
            padding: 16px 36px;
            font-size: 17px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 20px;
            transition: all 0.2s ease;
            width: 100%;
            max-width: 320px;
        }
        .confirm-btn:hover { background: #218838; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4); }
        .confirm-btn:disabled { background: #6c757d; cursor: not-allowed; transform: none; box-shadow: none; }
        .spinner { display: none; width: 24px; height: 24px; border: 3px solid #e0e0e0; border-top: 3px solid #28a745; border-radius: 50%; animation: spin 1s linear infinite; margin: 20px auto 0; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .error-message { display: none; color: #dc3545; background: #fff3f3; padding: 12px 16px; border-radius: 8px; margin-top: 20px; font-size: 14px; border: 1px solid #ffcdd2; }
        .status-message { display: none; color: #0066cc; font-size: 14px; margin-top: 15px; padding: 10px; background: #f0f7ff; border-radius: 6px; }
        .note { font-size: 12px; color: #999; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon">✓</div>
        <h1>Bank Linking Complete!</h1>
        <p>Your bank account has been successfully linked.</p>
        <p>Click the button below to verify and return to checkout.</p>
        <button type="button" id="confirm-connection-btn" class="confirm-btn">I've Connected My Bank Account</button>
        <div id="spinner" class="spinner"></div>
        <div id="status-message" class="status-message"></div>
        <div id="error-message" class="error-message"></div>
        <p class="note">This will verify your bank connection and redirect you back to checkout.</p>
    </div>
    <script>
        var orgId = '<?php echo esc_js($org_id); ?>';
        var ajaxUrl = '<?php echo esc_js($ajax_url); ?>';
        var nonce = '<?php echo esc_js($nonce); ?>';
        var checkoutUrl = '<?php echo esc_js($checkout_url); ?>';
        var maxRetries = 5, retryCount = 0, retryDelay = 3000;

        try {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'MONARCH_BANK_CALLBACK', status: 'LANDED', org_id: orgId }, '*');
            }
        } catch (e) { console.log('Could not notify parent:', e); }

        document.getElementById('confirm-connection-btn').addEventListener('click', function() {
            this.disabled = true;
            this.textContent = 'Verifying...';
            document.getElementById('spinner').style.display = 'block';
            document.getElementById('status-message').style.display = 'none';
            document.getElementById('error-message').style.display = 'none';
            getLatestPayToken();
        });

        function getLatestPayToken() {
            retryCount++;
            var statusMsg = document.getElementById('status-message');
            statusMsg.textContent = 'Verifying bank connection... (Attempt ' + retryCount + ' of ' + maxRetries + ')';
            statusMsg.style.display = 'block';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success && response.data && response.data.paytoken_id) {
                            statusMsg.textContent = 'Bank verified! Completing connection...';
                            completeBankConnection(response.data.paytoken_id);
                        } else {
                            if (retryCount < maxRetries) {
                                statusMsg.textContent = 'Verifying... (' + (maxRetries - retryCount) + ' attempts left)';
                                setTimeout(getLatestPayToken, retryDelay);
                            } else {
                                showError('Could not verify bank connection. Please try again or use Manual Entry.');
                            }
                        }
                    } catch (e) {
                        if (retryCount < maxRetries) setTimeout(getLatestPayToken, retryDelay);
                        else showError('Unexpected server response.');
                    }
                } else {
                    if (retryCount < maxRetries) setTimeout(getLatestPayToken, retryDelay);
                    else showError('Server error. Please try again.');
                }
            };
            xhr.onerror = function() {
                if (retryCount < maxRetries) setTimeout(getLatestPayToken, retryDelay);
                else showError('Network error.');
            };
            xhr.send('action=monarch_get_latest_paytoken&nonce=' + nonce + '&org_id=' + orgId);
        }

        function completeBankConnection(paytokenId) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            document.getElementById('status-message').textContent = 'Success! Redirecting...';
                            try {
                                if (window.parent && window.parent !== window) {
                                    window.parent.postMessage({ type: 'MONARCH_BANK_CALLBACK', status: 'SUCCESS', org_id: orgId, paytoken_id: paytokenId }, '*');
                                    try { window.parent.location.reload(); } catch (e) { window.location.href = checkoutUrl; }
                                } else { window.location.href = checkoutUrl; }
                            } catch (e) { window.location.href = checkoutUrl; }
                        } else { showError(response.data || 'Failed to complete connection.'); }
                    } catch (e) { showError('Unexpected response.'); }
                } else { showError('Server error.'); }
            };
            xhr.onerror = function() { showError('Network error.'); };
            xhr.send('action=monarch_bank_connection_complete&nonce=' + nonce + '&paytoken_id=' + paytokenId);
        }

        function showError(message) {
            document.getElementById('spinner').style.display = 'none';
            document.getElementById('status-message').style.display = 'none';
            var errorMsg = document.getElementById('error-message');
            errorMsg.textContent = message;
            errorMsg.style.display = 'block';
            var btn = document.getElementById('confirm-connection-btn');
            btn.disabled = false;
            btn.textContent = 'Try Again';
            retryCount = 0;
        }
    </script>
</body>
</html>
        <?php
        exit;
    }

    /**
     * Get the gateway instance
     */
    private function get_gateway() {
        if (self::$gateway_instance === null) {
            if (!class_exists('WC_Monarch_ACH_Gateway')) {
                include_once WC_MONARCH_ACH_PLUGIN_PATH . 'includes/class-wc-monarch-logger.php';
                include_once WC_MONARCH_ACH_PLUGIN_PATH . 'includes/class-monarch-api.php';
                include_once WC_MONARCH_ACH_PLUGIN_PATH . 'includes/class-wc-monarch-ach-gateway.php';
            }
            self::$gateway_instance = new WC_Monarch_ACH_Gateway();
        }
        return self::$gateway_instance;
    }

    /**
     * AJAX handler for disconnecting bank account
     */
    public function ajax_disconnect_bank() {
        $gateway = $this->get_gateway();
        $gateway->ajax_disconnect_bank();
    }

    /**
     * AJAX handler for creating organization
     */
    public function ajax_create_organization() {
        $gateway = $this->get_gateway();
        $gateway->ajax_create_organization();
    }

    /**
     * AJAX handler for bank connection complete
     */
    public function ajax_bank_connection_complete() {
        $gateway = $this->get_gateway();
        $gateway->ajax_bank_connection_complete();
    }

    /**
     * AJAX handler for checking bank status
     */
    public function ajax_check_bank_status() {
        $gateway = $this->get_gateway();
        $gateway->ajax_check_bank_status();
    }

    /**
     * AJAX handler for getting latest paytoken
     */
    public function ajax_get_latest_paytoken() {
        $gateway = $this->get_gateway();
        $gateway->ajax_get_latest_paytoken();
    }

    /**
     * AJAX handler for manual bank entry
     */
    public function ajax_manual_bank_entry() {
        $gateway = $this->get_gateway();
        $gateway->ajax_manual_bank_entry();
    }

    /**
     * AJAX handler for getting bank linking URL for returning users
     */
    public function ajax_get_bank_linking_url() {
        $gateway = $this->get_gateway();
        $gateway->ajax_get_bank_linking_url();
    }

    /**
     * AJAX handler for updating purchaser/org profile details
     */
    public function ajax_update_organization() {
        $gateway = $this->get_gateway();
        $gateway->ajax_update_organization();
    }

    /**
     * AJAX handler for manual status update (CRON)
     */
    public function ajax_manual_status_update() {
        check_ajax_referer('monarch_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Include required files if not already loaded
        if (!class_exists('WC_Monarch_Logger')) {
            include_once WC_MONARCH_ACH_PLUGIN_PATH . 'includes/class-wc-monarch-logger.php';
        }
        if (!class_exists('Monarch_API')) {
            include_once WC_MONARCH_ACH_PLUGIN_PATH . 'includes/class-monarch-api.php';
        }
        if (!class_exists('WC_Monarch_ACH_Gateway')) {
            include_once WC_MONARCH_ACH_PLUGIN_PATH . 'includes/class-wc-monarch-ach-gateway.php';
        }
        if (!class_exists('WC_Monarch_Cron')) {
            include_once WC_MONARCH_ACH_PLUGIN_PATH . 'includes/class-wc-monarch-cron.php';
        }

        $cron = WC_Monarch_Cron::instance();
        $result = $cron->update_pending_transactions();

        wp_send_json_success(array(
            'message' => sprintf(
                'Status update complete. Processed: %d, Updated: %d, Errors: %d',
                $result['processed'],
                $result['updated'],
                $result['errors']
            ),
            'processed' => $result['processed'],
            'updated' => $result['updated'],
            'errors' => $result['errors']
        ));
    }

    public function add_gateway_class($gateways) {
        $gateways[] = 'WC_Monarch_ACH_Gateway';
        return $gateways;
    }
    
    public function enqueue_scripts() {
        // Scripts are now handled by the gateway's payment_scripts() method
        // This ensures proper loading only when the gateway is available and enabled
    }
    
    public function activate() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'monarch_ach_transactions';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = 'CREATE TABLE ' . $table_name . " (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            transaction_id varchar(100) NOT NULL,
            monarch_org_id varchar(50) NOT NULL,
            paytoken_id varchar(100) NOT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL DEFAULT 'USD',
            status varchar(20) NOT NULL,
            api_response longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY transaction_id (transaction_id),
            KEY order_id (order_id)
        ) " . $charset_collate . ';';

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Schedule CRON job on activation
        if (!wp_next_scheduled('monarch_ach_update_transaction_status')) {
            wp_schedule_event(time(), 'every_two_hours', 'monarch_ach_update_transaction_status');
        }
    }

    /**
     * Plugin deactivation - clean up CRON jobs
     */
    public function deactivate() {
        // Unschedule CRON job
        $timestamp = wp_next_scheduled('monarch_ach_update_transaction_status');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'monarch_ach_update_transaction_status');
        }
    }
}

new WC_Monarch_ACH_Gateway_Plugin();