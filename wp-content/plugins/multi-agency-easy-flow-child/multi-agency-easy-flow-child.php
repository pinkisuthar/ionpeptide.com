<?php
/**
 * Plugin Name: Multi Agency Easy Flow - Client
 * Description: Secure Child Checkout Processor (Definitive Stable Build)
 * Version: 12.26.22
 * Author: Welcome Lane
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'updater.php';

if (!class_exists('MAEF_Child_Final')) {
    class MAEF_Child_Final {
        const OPTION_KEY = 'maef_child_settings';
        const VERSION    = '12.26.16';
        private $settings;

        public function __construct() {
            if (!defined('MAEF_CLIENT_UPDATE_SERVER')) {
                define('MAEF_CLIENT_UPDATE_SERVER', 'https://welcomelane.com/wp-content/uploads/maef-updates/');
            }

            new MAEF_Plugin_Updater(__FILE__);

            $this->settings = get_option(self::OPTION_KEY, []);
            add_action('admin_menu',        [$this, 'admin_menu']);
            add_action('admin_init',        [$this, 'register_settings']);
            add_action('wp_loaded',         [$this, 'handle_cart_actions']);
            add_action('wp_loaded',         [$this, 'maybe_start_checkout_process'], 1);
            add_action('template_redirect', [$this, 'maybe_handle_parent_return'], 1);
            add_action('init',              [$this, 'capture_affiliate_id']);
            add_action('rest_api_init',     [$this, 'register_rest_routes']);
            add_action('wp_head',           [$this, 'inject_tracking_script']);

            // Gateway mode: hide pending MAEF orders from customer My Account order list.
            // These are ghost orders created when customer clicks Place Order but hasn't paid yet.
            // They become visible automatically once flipped to processing after payment.
            if ($this->settings['gateway_mode'] ?? false) {
                // NOTE: Order visibility filters intentionally removed in v12.26.8.
                // Hiding pending/on-hold orders from My Account caused customers to
                // believe their order was lost and place duplicate orders. Customers
                // must be able to see their pending order immediately after checkout.
            }

            // Cron: auto-cancel abandoned gateway mode on-hold orders after 15 minutes
            add_action('maef_cancel_abandoned_orders', [$this, 'cancel_abandoned_onhold_orders']);
            if (!wp_next_scheduled('maef_cancel_abandoned_orders')) {
                wp_schedule_event(time(), 'maef_fifteen_minutes', 'maef_cancel_abandoned_orders');
            }
            add_filter('cron_schedules', [$this, 'add_cron_interval']);

            // Suppress premature emails for MAEF pending orders.
            // Child sets orders to pending while customer pays on parent site.
            // Customer should only receive the "processing" email after payment succeeds.
            add_filter('woocommerce_email_enabled_customer_pending_order', [$this, 'suppress_maef_onhold_email'], 10, 2);
            add_filter('woocommerce_email_enabled_new_order',              [$this, 'suppress_maef_onhold_email'], 10, 2);

            // ── FunnelKit / WooFunnels compatibility ──────────────────────
            // FunnelKit intercepts process_payment redirects via these filters.
            // We hook in at priority 999 (after FunnelKit's priority ~10-100)
            // and restore our redirect URL for MAEF gateway orders.
            add_filter('woocommerce_payment_successful_result',   [$this, 'funnelkit_restore_maef_redirect'], 999, 2);
            add_filter('wfacp_payment_successful_result',         [$this, 'funnelkit_restore_maef_redirect'], 999, 2);
            add_filter('woofunnels_payment_successful_result',    [$this, 'funnelkit_restore_maef_redirect'], 999, 2);
            // Prevent FunnelKit from treating MAEF pending orders as failed
            add_filter('wfacp_is_order_failed',                   [$this, 'funnelkit_maef_not_failed'], 10, 2);
            add_filter('woocommerce_valid_order_statuses_for_payment', [$this, 'funnelkit_allow_pending_maef'], 10, 2);

            // Force all WooCommerce cart/checkout URLs to MAEF cart page
            if (!empty($this->settings['cart_page_url'])) {
                add_filter('woocommerce_get_cart_url',     [$this, 'override_cart_url']);
                add_filter('woocommerce_get_checkout_url', [$this, 'override_cart_url']);
                add_action('template_redirect',            [$this, 'redirect_wc_pages_to_maef_cart']);
            }

            add_shortcode('maef_checkout',      [$this, 'render_checkout_button']);
            add_shortcode('maef_cart',          [$this, 'render_cart']);
            add_shortcode('maef_order_summary', [$this, 'render_order_summary']);

        // Gateway mode — register MAEF as a WC payment method (late priority so class exists)
        add_filter('woocommerce_payment_gateways', [$this, 'register_maef_gateway'], 20);
        }

        // ---------------------------------------------------------------
        // REST API: /wp-json/maef/v1/create-coupon
        // Called by GHL workflow on affiliate approval to auto-create
        // a WooCommerce coupon and add the coupon-to-affiliate mapping.
        // ---------------------------------------------------------------

        public function register_rest_routes() {
            register_rest_route('maef/v1', '/create-coupon', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_create_coupon'],
                'permission_callback' => '__return_true',
            ]);
        }

        public function handle_create_coupon($request) {
            $params = $request->get_json_params();

            // Validate secret
            $expected_secret = $this->settings['parent_secret'] ?? '';
            $provided_secret = $params['secret'] ?? '';
            if (empty($expected_secret) || $provided_secret !== $expected_secret) {
                return new WP_REST_Response(['error' => 'Invalid secret'], 401);
            }

            // Validate required fields
            $coupon_code     = sanitize_text_field($params['coupon_code'] ?? '');
            $discount_type   = sanitize_text_field($params['discount_type'] ?? 'percent');
            $discount_amount = floatval($params['discount_amount'] ?? 0);
            $affiliate_id    = sanitize_text_field($params['affiliate_id'] ?? '');

            if (empty($coupon_code) || empty($affiliate_id)) {
                return new WP_REST_Response(['error' => 'Missing coupon_code or affiliate_id'], 400);
            }

            // Check if coupon already exists
            $existing = wc_get_coupon_id_by_code($coupon_code);
            if ($existing) {
                return new WP_REST_Response(['error' => 'Coupon code already exists', 'coupon_id' => $existing], 409);
            }

            // Create WooCommerce coupon
            $coupon = new WC_Coupon();
            $coupon->set_code($coupon_code);
            $coupon->set_discount_type($discount_type === 'fixed' ? 'fixed_cart' : 'percent');
            $coupon->set_amount($discount_amount);
            $coupon->set_individual_use(false);
            $coupon->set_usage_limit(0);
            $coupon->set_description('Affiliate coupon for ' . $affiliate_id);
            $coupon->save();

            if (!$coupon->get_id()) {
                return new WP_REST_Response(['error' => 'Failed to create coupon'], 500);
            }

            // Add coupon-to-affiliate mapping in plugin settings
            $settings = get_option(self::OPTION_KEY, []);
            $mappings = $settings['affiliate_coupons'] ?? '';
            $new_line = strtoupper($coupon_code) . '=' . $affiliate_id;

            if (!empty(trim($mappings))) {
                $mappings = rtrim($mappings, chr(10)) . chr(10) . $new_line;
            } else {
                $mappings = $new_line;
            }

            $settings['affiliate_coupons'] = $mappings;
            update_option(self::OPTION_KEY, $settings);

            return new WP_REST_Response([
                'success'      => true,
                'coupon_id'    => $coupon->get_id(),
                'coupon_code'  => $coupon_code,
                'affiliate_id' => $affiliate_id,
                'discount'     => $discount_amount . ($discount_type === 'fixed' ? ' flat' : '%'),
            ], 201);
        }

        // ---------------------------------------------------------------
        // Force WooCommerce cart/checkout URLs to MAEF cart page
        // ---------------------------------------------------------------

        public function override_cart_url($url) {
            if (!empty($this->settings['gateway_mode'])) return $url; // Gateway mode: don't override cart URL
            $cart_page = trim($this->settings['cart_page_url'] ?? '');
            if ($cart_page) {
                return home_url($cart_page);
            }
            return $url;
        }

        public function redirect_wc_pages_to_maef_cart() {
            if (!empty($this->settings['gateway_mode'])) return;
            $cart_page = trim($this->settings['cart_page_url'] ?? '');
            if (!$cart_page) return;

            // CRITICAL: Never redirect when MAEF is actively processing a checkout POST.
            // Without this guard, plugins that hook template_redirect early can catch
            // the MAEF form submission and loop it back to the cart page.
            if (!empty($_POST['maef_start_checkout']) || 
                !empty($_POST['maef_update_cart']) || 
                !empty($_POST['maef_apply_coupon']) ||
                !empty($_POST['maef_remove_store_credit']) ||
                !empty($_POST['maef_apply_store_credit'])) {
                return;
            }

            $maef_cart_url = home_url($cart_page);

            // Redirect WooCommerce native cart page
            if (function_exists('is_cart') && is_cart()) {
                $current_path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
                $maef_path    = rtrim(parse_url($maef_cart_url, PHP_URL_PATH), '/');
                if ($current_path !== $maef_path) {
                    wp_safe_redirect($maef_cart_url);
                    exit;
                }
            }

            // Redirect WooCommerce native checkout page
            if (function_exists('is_checkout') && is_checkout() && !is_wc_endpoint_url('order-received')) {
                wp_safe_redirect($maef_cart_url);
                exit;
            }
        }

        // ---------------------------------------------------------------
        // Inject GHL affiliate tracking script into site <head>
        // ---------------------------------------------------------------

        public function inject_tracking_script() {
            $script = $this->settings['ghl_tracking_script'] ?? '';
            if (!empty(trim($script))) {
                echo "\n<!-- MAEF: GHL Affiliate Tracking -->\n" . $script . "\n";
            }

            // FunnelKit compatibility — only on checkout pages, only when enabled
            if (!empty($this->settings['funnelkit_compat']) && function_exists('is_checkout') && is_checkout() && !is_wc_endpoint_url('order-received')) {
                ?>
<script>
/* MAEF: FunnelKit Compatibility Fix */
(function($) {
    // Guard flag — prevents double-redirect consuming the token twice
    var _maefRedirecting = false;

    function maefDoRedirect(url) {
        if (_maefRedirecting) return; // already navigating — ignore
        _maefRedirecting = true;
        // Kill FunnelKit's beforeunload listener so "Leave site?" popup never fires
        window.onbeforeunload = null;
        jQuery(window).off('beforeunload');
        window.location.href = url;
    }

    $(document).ready(function() {

        // Override $.ajax to intercept FunnelKit's checkout AJAX handling
        var _ajax = $.ajax.bind($);
        $.ajax = function(options) {
            if (options && options.data && typeof options.data === 'string' &&
                options.data.indexOf('payment_method=maef_child_gateway') !== -1) {

                var originalSuccess = options.success;
                options.success = function(response) {
                    if (response && response.result === 'success' && response.redirect &&
                        (response.redirect.indexOf('maef_start_checkout') !== -1 ||
                         response.redirect.indexOf('maef_complete') !== -1)) {
                        // MAEF redirect detected — bypass FunnelKit and go directly
                        maefDoRedirect(response.redirect);
                        return;
                    }
                    if (originalSuccess) originalSuccess.apply(this, arguments);
                };
            }
            return _ajax(options);
        };

        // Also catch FunnelKit's own checkout success event — guard prevents double-hit
        $(document.body).on('wfacp_checkout_success wfacp_order_bump_success', function(e, response) {
            if (response && response.redirect &&
                response.redirect.indexOf('maef_start_checkout') !== -1) {
                e.stopImmediatePropagation();
                maefDoRedirect(response.redirect);
            }
        });

        // Catch any form submit that FunnelKit processes
        $(document).on('submit', 'form.checkout, form.wfacp-checkout-form', function() {
            var paymentMethod = $('input[name="payment_method"]:checked').val();
            if (paymentMethod === 'maef_child_gateway') {
                window._maefGatewayActive = true;
            }
        });

    });
})(jQuery);
</script>
                <?php
            }
        }

        // ---------------------------------------------------------------
        // Affiliate ID capture — stores ?am_id= (GHL) or ?affiliate_id= (legacy) from URL into cookie
        // ---------------------------------------------------------------

        public function capture_affiliate_id() {
            $aff_id = '';
            if (!empty($_GET['am_id'])) {
                $aff_id = sanitize_text_field($_GET['am_id']);
            } elseif (!empty($_GET['affiliate_id'])) {
                $aff_id = sanitize_text_field($_GET['affiliate_id']);
            }
            if ($aff_id) {
                setcookie('maef_affiliate_id', $aff_id, time() + (30 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
                $_COOKIE['maef_affiliate_id'] = $aff_id;
            }
        }

        private function get_affiliate_id() {
            return isset($_COOKIE['maef_affiliate_id']) ? sanitize_text_field($_COOKIE['maef_affiliate_id']) : '';
        }

        public function admin_menu() {
            add_options_page(
                'MAEF Child Settings',
                'MAEF Child',
                'manage_options',
                'maef-child-settings',
                [$this, 'settings_page']
            );
        }

        public function settings_page() {
            $settings = get_option(self::OPTION_KEY, []);
            ?>
            <div class="wrap">
                <h1>MAEF Child Settings</h1>
                <form method="post" action="options.php">
                    <?php settings_fields(self::OPTION_KEY); ?>
                    <table class="form-table">

                        <tr>
                            <th>Parent Site(s)</th>
                            <td>
                                <textarea name="<?php echo self::OPTION_KEY; ?>[parent_urls]" rows="5" cols="70" style="font-family:monospace;font-size:13px;" placeholder="https://parent1.com|secret_key_one&#10;https://parent2.com|secret_key_two&#10;https://parent3.com|secret_key_three"><?php echo esc_textarea($settings['parent_urls'] ?? $settings['parent_url'] ?? ''); ?></textarea>
                                <p class="description"><strong>One entry per line</strong> in the format: <code>https://parentsite.com|SECRET_KEY</code><br>The secret key is the value shown at the bottom of that parent site's MAEF Parent settings page.<br>If multiple lines are added, checkouts rotate evenly between them (round-robin).</p>
                                <?php
                                $rr_lines = array_values(array_filter(array_map('trim', explode("
", $settings['parent_urls'] ?? $settings['parent_url'] ?? ''))));
                                $rr_urls_only = array_map(function($l){ return explode('|', $l)[0]; }, $rr_lines);
                                if (count($rr_lines) > 1):
                                    $next_idx = intval(get_option('maef_parent_rr_counter', 0));
                                    if ($next_idx >= count($rr_lines)) $next_idx = 0;
                                ?>
                                <p style="margin-top:6px;padding:6px 10px;background:#e8f4fd;border-left:3px solid #0073aa;font-size:12px;font-family:monospace;">
                                    &#8635; Round-robin active &mdash; <?php echo count($rr_lines); ?> parents configured.<br>
                                    Next checkout will use: <strong><?php echo esc_html($rr_urls_only[$next_idx]); ?></strong>
                                </p>
                                <?php endif; ?>
                                <?php
                                // Connection health check — test each parent is reachable
                                if (!empty($rr_lines)):
                                    $health_key = 'maef_health_' . md5(implode('', $rr_lines));
                                    $health     = get_transient($health_key);
                                    if (!$health && !empty($_GET['maef_test_parents'])):
                                        $health = [];
                                        foreach ($rr_lines as $line) {
                                            $parts   = explode('|', $line, 2);
                                            $url     = rtrim($parts[0], '/');
                                            $secret  = preg_replace('/\s+/', '', $parts[1] ?? '');
                                            $payload = json_encode(['timestamp' => time(), 'cart' => [], 'total' => 0, 'health_check' => true]);
                                            $sig     = hash_hmac('sha256', $payload, $secret);
                                            $resp    = wp_remote_post($url . '/wp-json/maef/v1/create-order-session', [
                                                'body'    => $payload,
                                                'headers' => ['Content-Type' => 'application/json', 'x-maef-signature' => $sig],
                                                'timeout' => 8,
                                            ]);
                                            $code = is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_response_code($resp);
                                            // 400 = reached but rejected invalid payload = parent is alive and signature matched
                                            $health[$url] = ($code === 400 || $code === 200) ? 'ok' : (is_string($code) ? $code : 'http_' . $code);
                                        }
                                        set_transient($health_key, $health, 300);
                                    endif;
                                    if ($health):
                                ?>
                                <table style="margin-top:8px;font-size:12px;border-collapse:collapse;width:100%;">
                                <?php foreach ($health as $url => $status): $ok = ($status === 'ok'); ?>
                                    <tr>
                                        <td style="padding:4px 8px;font-family:monospace;"><?php echo esc_html($url); ?></td>
                                        <td style="padding:4px 8px;font-weight:bold;color:<?php echo $ok ? '#2e7d32' : '#dc2626'; ?>;">
                                            <?php echo $ok ? '&#10003; Connected' : '&#10007; ' . esc_html($status); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </table>
                                <?php else: ?>
                                    <a href="<?php echo esc_url(add_query_arg('maef_test_parents', '1')); ?>" class="button button-secondary" style="margin-top:8px;">&#10003; Test Parent Connections</a>
                                <?php endif; endif; ?>
                            </td>
                        </tr>
                        </tr>
                        <tr>
                            <th>Thank You Page Path</th>
                            <td><input type="text" name="<?php echo self::OPTION_KEY; ?>[thank_you]" value="<?php echo esc_attr($settings['thank_you'] ?? '/thank-you'); ?>" size="60"/></td>
                                                </tr>
                        <tr>
                            <th>MAEF Cart Page URL</th>
                            <td>
                                <input type="text" name="<?php echo self::OPTION_KEY; ?>[cart_page_url]" value="<?php echo esc_attr($settings['cart_page_url'] ?? ''); ?>" size="60" placeholder="/cart"/>
                                <p class="description">Path to the page with your <code>[maef_cart]</code> shortcode (e.g. <code>/cart</code>). All WooCommerce cart/checkout buttons and side-cart links will redirect here.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Button Label</th>     <td><input type="text" name="<?php echo self::OPTION_KEY; ?>[button_label]" value="<?php echo esc_attr($settings['button_label'] ?? 'Proceed to Secure Checkout'); ?>" size="40"/></td>
                        </tr>
                        <tr>
                            <th>Button Background Color</th>
                            <td><input type="text" name="<?php echo self::OPTION_KEY; ?>[button_bg]" value="<?php echo esc_attr($settings['button_bg'] ?? '#000000'); ?>"/></td>
                        </tr>
                        <tr>
                            <th>Button Text Color</th>
                            <td><input type="text" name="<?php echo self::OPTION_KEY; ?>[button_text_color]" value="<?php echo esc_attr($settings['button_text_color'] ?? '#ffffff'); ?>"/></td>
                        </tr>

                        <tr><td colspan="2"><hr/><h2 style="margin:0 0 0.5em;">Terms and Conditions</h2></td></tr>

                        <tr>
                            <th>Enable Terms Checkbox</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[terms_enabled]" value="1" <?php checked(!empty($settings['terms_enabled'])); ?>/>
                                    Require customers to accept terms and conditions before checkout
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Terms and Conditions Page URL</th>
                            <td>
                                <input type="url" name="<?php echo self::OPTION_KEY; ?>[terms_url]" value="<?php echo esc_attr($settings['terms_url'] ?? ''); ?>" size="60" placeholder="https://yoursite.com/terms"/>
                                <p class="description">Full URL to your terms and conditions page. Customers will see "I accept the <a href='...'>Terms and Conditions</a>"</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Terms Link Text</th>
                            <td>
                                <input type="text" name="<?php echo self::OPTION_KEY; ?>[terms_text]" value="<?php echo esc_attr($settings['terms_text'] ?? 'Terms and Conditions'); ?>" size="40"/>
                                <p class="description">Link text shown to customers (e.g., "Terms and Conditions" or "Privacy Policy")</p>
                            </td>
                        </tr>

                        <tr><td colspan="2"><hr/><h2 style="margin:0 0 0.5em;">Shipping</h2></td></tr>

                        <tr>
                            <th>Shipping Method</th>
                            <td>
                                <select name="<?php echo self::OPTION_KEY; ?>[shipping_mode]" id="maef_shipping_mode" onchange="
                                    document.getElementById('maef_flat_rate_settings').style.display = this.value === 'flat' ? '' : 'none';
                                    document.getElementById('maef_wc_shipping_note').style.display = this.value === 'woocommerce' ? '' : 'none';
                                ">
                                    <option value="flat" <?php selected($settings['shipping_mode'] ?? 'flat', 'flat'); ?>>MAEF Flat Rate</option>
                                    <option value="woocommerce" <?php selected($settings['shipping_mode'] ?? '', 'woocommerce'); ?>>WooCommerce Native Shipping</option>
                                </select>
                                <p class="description">Choose how shipping is calculated. "MAEF Flat Rate" uses the settings below. "WooCommerce Native" uses your WooCommerce shipping zones and methods.</p>
                            </td>
                        </tr>

                        <tbody id="maef_wc_shipping_note" style="<?php echo ($settings['shipping_mode'] ?? 'flat') === 'woocommerce' ? '' : 'display:none;'; ?>">
                        <tr>
                            <th></th>
                            <td>
                                <div style="padding:12px 16px;background:#f0f6ff;border-left:4px solid #2271b1;border-radius:0 4px 4px 0;">
                                    <strong>Using WooCommerce Shipping</strong><br/>
                                    Configure your shipping zones and methods in <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=shipping'); ?>">WooCommerce &rarr; Settings &rarr; Shipping</a>.<br/>
                                    Customers will see all available shipping options and can select one on the cart page.<br/>
                                    Free shipping coupons, minimum order thresholds, and multiple rates are all supported natively.
                                </div>
                            </td>
                        </tr>
                        </tbody>

                        <tbody id="maef_flat_rate_settings" style="<?php echo ($settings['shipping_mode'] ?? 'flat') !== 'flat' ? 'display:none;' : ''; ?>">
                        <tr>
                            <th>Flat Rate Shipping Cost</th>
                            <td>
                                <input type="number" name="<?php echo self::OPTION_KEY; ?>[flat_shipping_cost]" value="<?php echo esc_attr($settings['flat_shipping_cost'] ?? ''); ?>" min="0" step="0.01" style="width:120px;" placeholder="0.00"/>
                                <p class="description">Enter a flat shipping amount (e.g. <code>9.99</code>). Leave blank or <code>0</code> for free / no shipping.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Shipping Label</th>
                            <td>
                                <input type="text" name="<?php echo self::OPTION_KEY; ?>[shipping_label]" value="<?php echo esc_attr($settings['shipping_label'] ?? 'Shipping'); ?>" size="30" placeholder="Shipping"/>
                                <p class="description">Label shown next to the shipping cost on the receipt.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Free Shipping Over Amount</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[free_shipping_enabled]" value="1" id="maef_free_shipping_enabled" <?php checked(!empty($settings['free_shipping_enabled'])); ?> onchange="document.getElementById('maef_free_threshold_row').style.display = this.checked ? 'table-row' : 'none';"/>
                                    Offer free shipping when the order subtotal reaches a minimum amount
                                </label>
                            </td>
                        </tr>
                        <tr id="maef_free_threshold_row" style="<?php echo empty($settings['free_shipping_enabled']) ? 'display:none;' : ''; ?>">
                            <th>Free Shipping Threshold</th>
                            <td>
                                <input type="number" name="<?php echo self::OPTION_KEY; ?>[free_shipping_threshold]" value="<?php echo esc_attr($settings['free_shipping_threshold'] ?? ''); ?>" min="0" step="0.01" style="width:120px;" placeholder="200.00"/>
                                <p class="description">Orders at or above this subtotal get free shipping.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>International Shipping Cost</th>
                            <td>
                                <input type="number" name="<?php echo self::OPTION_KEY; ?>[international_shipping_cost]" value="<?php echo esc_attr($settings['international_shipping_cost'] ?? ''); ?>" min="0" step="0.01" style="width:120px;" placeholder="0.00"/>
                                <p class="description">Shipping cost for orders outside the United States. Leave blank to use the flat rate for all countries.</p>
                            </td>
                        </tr>
                        </tbody>

                    <tr>
                        <th>Coupon Field Position</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[coupon_below_update]" value="1" <?php checked(!empty($settings['coupon_below_update'])); ?>/>
                                Show coupon field below the Update Cart button (instead of below totals)
                            </label>
                            <p class="description">When enabled, the coupon input appears directly below the cart table and Update Cart button, before the shipping and totals section.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Gateway Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[gateway_mode]" value="1" <?php checked(!empty($settings['gateway_mode'])); ?>/>
                                Register MAEF as a WooCommerce payment method option
                            </label>
                            <p class="description">When enabled, MAEF appears as a payment option in the native WooCommerce checkout alongside other payment methods (e.g. Stripe, PayPal). Customers choose it like any other payment method. Use this when the site has its own checkout flow and MAEF is just one payment option.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>FunnelKit Compatibility</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[funnelkit_compat]" value="1" <?php checked(!empty($settings['funnelkit_compat'])); ?>/>
                                Enable FunnelKit / WooFunnels checkout compatibility
                            </label>
                            <p class="description">Enable this if the child site uses FunnelKit (WooFunnels) Checkout Builder. FunnelKit intercepts WooCommerce checkout redirects in JavaScript — this option injects a fix that restores the MAEF redirect before FunnelKit can override it.</p>
                        </td>
                    </tr>

                    </table>
                    <?php submit_button(); ?>
                </form>

                <hr/>
                <h2>Shortcode Instructions</h2>
                <p>Place the following shortcodes on your child site pages:</p>
                <table class="form-table">
                    <tr>
                        <th style="padding-left:0;">Cart Page:</th>
                        <td><code>[maef_cart]</code></td>
                        <td><em>Full editable cart (quantities + remove buttons) with checkout button. Recommended.</em></td>
                    </tr>
                    <tr>
                        <th style="padding-left:0;">Checkout Button Only:</th>
                        <td><code>[maef_checkout]</code></td>
                        <td><em>Cart summary table + checkout button only (no editing).</em></td>
                    </tr>
                    <tr>
                        <th style="padding-left:0;">Thank You Page:</th>
                        <td><code>[maef_order_summary]</code></td>
                        <td><em>Displays the final order receipt.</em></td>
                    </tr>
                </table>
                <hr/>
                <h2>Affiliate Tracking</h2>
                <p>The plugin automatically captures <code>?am_id=XXX</code> (GoHighLevel) or <code>?affiliate_id=XXX</code> from URLs and stores it in a 30-day cookie. This ID is passed through checkout to the parent site for affiliate attribution.</p>
                
                <table class="form-table">
                    <tr>
                        <th>GHL Affiliate Tracking Script</th>
                        <td>
                            <textarea name="<?php echo self::OPTION_KEY; ?>[ghl_tracking_script]" rows="8" cols="70" style="font-family:monospace;font-size:12px;" placeholder="Paste your GoHighLevel affiliate tracking script here..."><?php echo esc_textarea($settings['ghl_tracking_script'] ?? ''); ?></textarea>
                            <p class="description">Paste the tracking script from GoHighLevel → Affiliate Manager → Settings → External Website. This will be automatically added to every page on your site so affiliate clicks are tracked.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Coupon to Affiliate Mapping</th>
                        <td>
                            <textarea name="<?php echo self::OPTION_KEY; ?>[affiliate_coupons]" rows="6" cols="50" style="font-family:monospace;font-size:13px;" placeholder="JOHN10=john_affiliate_id&#10;SARAH15=sarah_affiliate_id"><?php echo esc_textarea($settings['affiliate_coupons'] ?? ''); ?></textarea>
                            <p class="description">One per line: <code>COUPONCODE=affiliate_id</code>. The coupon must also exist in WooCommerce (Marketing &rarr; Coupons) for the discount to apply. This maps the coupon to the affiliate for commission tracking. If you use the GHL auto-approval workflow, this is filled in automatically.</p>
                        </td>
                    </tr>
                </table>
            </div>
            <?php
        }

        public function register_settings() {
            register_setting(self::OPTION_KEY, self::OPTION_KEY);

            // Whenever MAEF child settings are saved, ensure the gateway order in WooCommerce
            // puts MAEF first (affects both classic and block checkout ordering).
            add_action('update_option_' . self::OPTION_KEY, [$this, 'enforce_gateway_order'], 10, 2);

            // When parent URLs change, reset the round-robin counter and flush health cache
            // so removed parent sites are never used again
            add_action('update_option_' . self::OPTION_KEY, [$this, 'flush_parent_cache_on_save'], 10, 2);
        }

        public function flush_parent_cache_on_save($old_value, $new_value) {
            $old_urls = $old_value['parent_urls'] ?? $old_value['parent_url'] ?? '';
            $new_urls = $new_value['parent_urls'] ?? $new_value['parent_url'] ?? '';
            if ($old_urls === $new_urls) return;

            // Reset round-robin counter
            update_option('maef_parent_rr_counter', 0);

            // Flush health check transients
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_maef_health_%' OR option_name LIKE '_transient_timeout_maef_health_%'");

            // Flush WC sessions that have old guard keys cached
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_maef_btn_guard_%' OR option_name LIKE '_transient_timeout_maef_btn_guard_%'");

            error_log('MAEF: Parent URL settings changed — flushed round-robin counter and session caches.');
        }

        /**
         * After settings save: if gateway mode is on, move MAEF to position 0
         * in WooCommerce's stored gateway order so it appears first at checkout.
         */
        public function enforce_gateway_order($old_value, $new_value) {
            if (empty($new_value['gateway_mode'])) return;

            $order = get_option('woocommerce_gateway_order', []);
            // Remove maef from wherever it is, then put it at position 0
            unset($order['maef_child_gateway']);
            $order = array_merge(['maef_child_gateway' => 0], $order);
            // Re-index values sequentially
            $i = 0;
            foreach ($order as $key => $val) {
                $order[$key] = $i++;
            }
            update_option('woocommerce_gateway_order', $order);
        }

        /**
         * Shipping cost resolver — applies free threshold logic and international rates.
         * @param float $subtotal Cart subtotal before shipping
         * @param string $country Customer's country code (default 'US')
         */
        // ---------------------------------------------------------------
        // Build customer billing/shipping arrays from WC customer session.
        // Returns ['customer_billing' => [...], 'customer_shipping' => [...]]
        // Both arrays are empty if the customer hasn't entered an address yet.
        // Can also accept a WC_Order to pull from order data (gateway mode).
        // ---------------------------------------------------------------
        public function get_customer_address_payload($order = null): array {
            $billing  = [];
            $shipping = [];

            if ($order instanceof WC_Order) {
                // Pull from order object first
                $billing = [
                    'first_name' => $order->get_billing_first_name(),
                    'last_name'  => $order->get_billing_last_name(),
                    'email'      => $order->get_billing_email(),
                    'phone'      => $order->get_billing_phone(),
                    'address_1'  => $order->get_billing_address_1(),
                    'address_2'  => $order->get_billing_address_2(),
                    'city'       => $order->get_billing_city(),
                    'state'      => $order->get_billing_state(),
                    'postcode'   => $order->get_billing_postcode(),
                    'country'    => $order->get_billing_country(),
                    'company'    => $order->get_billing_company(),
                ];

                // CFW and some block checkouts save billing to order AFTER process_payment fires.
                // Try $_POST first, then php://input (CFW sends JSON body), then give up.
                $order_has_billing = !empty(trim($billing['first_name'] . $billing['email'] . $billing['address_1']));
                if (!$order_has_billing) {
                    // Try standard $_POST (classic WC checkout)
                    $post_src = [];
                    if (!empty($_POST['billing_first_name']) || !empty($_POST['billing_email'])) {
                        $post_src = $_POST;
                        error_log('MAEF: billing source = $_POST');
                    } else {
                        // CFW sends checkout data as JSON body
                        $raw = file_get_contents('php://input');
                        if (!empty($raw)) {
                            $json_body = json_decode($raw, true);
                            if (!empty($json_body['billing_first_name']) || !empty($json_body['billing_email'])) {
                                $post_src = $json_body;
                                error_log('MAEF: billing source = php://input JSON');
                            } elseif (!empty($json_body['billing']['first_name'])) {
                                // Nested billing object
                                $b = $json_body['billing'];
                                $billing = [
                                    'first_name' => sanitize_text_field($b['first_name'] ?? ''),
                                    'last_name'  => sanitize_text_field($b['last_name']  ?? ''),
                                    'email'      => sanitize_email($b['email']            ?? ''),
                                    'phone'      => sanitize_text_field($b['phone']       ?? ''),
                                    'address_1'  => sanitize_text_field($b['address_1']  ?? ''),
                                    'address_2'  => sanitize_text_field($b['address_2']  ?? ''),
                                    'city'       => sanitize_text_field($b['city']        ?? ''),
                                    'state'      => sanitize_text_field($b['state']       ?? ''),
                                    'postcode'   => sanitize_text_field($b['postcode']    ?? ''),
                                    'country'    => sanitize_text_field($b['country']     ?? ''),
                                    'company'    => sanitize_text_field($b['company']     ?? ''),
                                ];
                                error_log('MAEF: billing source = php://input nested billing object, email=' . $billing['email']);
                                $post_src = null; // already handled
                            }
                        }
                    }
                    if ($post_src !== null && !empty($post_src)) {
                        $billing = [
                            'first_name' => sanitize_text_field($post_src['billing_first_name'] ?? ''),
                            'last_name'  => sanitize_text_field($post_src['billing_last_name']  ?? ''),
                            'email'      => sanitize_email($post_src['billing_email']            ?? ''),
                            'phone'      => sanitize_text_field($post_src['billing_phone']       ?? ''),
                            'address_1'  => sanitize_text_field($post_src['billing_address_1']  ?? ''),
                            'address_2'  => sanitize_text_field($post_src['billing_address_2']  ?? ''),
                            'city'       => sanitize_text_field($post_src['billing_city']        ?? ''),
                            'state'      => sanitize_text_field($post_src['billing_state']       ?? ''),
                            'postcode'   => sanitize_text_field($post_src['billing_postcode']    ?? ''),
                            'country'    => sanitize_text_field($post_src['billing_country']     ?? ''),
                            'company'    => sanitize_text_field($post_src['billing_company']     ?? ''),
                        ];
                        error_log('MAEF: billing pulled from post_src, email=' . $billing['email']);
                    }
                    if (empty(trim(($billing['first_name'] ?? '') . ($billing['email'] ?? '') . ($billing['address_1'] ?? '')))) {
                        error_log('MAEF: billing still empty after all fallbacks. POST keys=' . implode(',', array_keys($_POST)));
                    }
                }

                $ship_addr = $order->get_shipping_address_1();
                $shipping = [
                    'first_name' => $order->get_shipping_first_name(),
                    'last_name'  => $order->get_shipping_last_name(),
                    'address_1'  => $ship_addr,
                    'address_2'  => $order->get_shipping_address_2(),
                    'city'       => $order->get_shipping_city(),
                    'state'      => $order->get_shipping_state(),
                    'postcode'   => $order->get_shipping_postcode(),
                    'country'    => $order->get_shipping_country(),
                    'company'    => $order->get_shipping_company(),
                ];

                // If shipping fields empty, check $_POST
                if (empty($shipping['address_1']) && !empty($_POST['shipping_address_1'])) {
                    $shipping = [
                        'first_name' => sanitize_text_field($_POST['shipping_first_name'] ?? ''),
                        'last_name'  => sanitize_text_field($_POST['shipping_last_name']  ?? ''),
                        'address_1'  => sanitize_text_field($_POST['shipping_address_1']  ?? ''),
                        'address_2'  => sanitize_text_field($_POST['shipping_address_2']  ?? ''),
                        'city'       => sanitize_text_field($_POST['shipping_city']        ?? ''),
                        'state'      => sanitize_text_field($_POST['shipping_state']       ?? ''),
                        'postcode'   => sanitize_text_field($_POST['shipping_postcode']    ?? ''),
                        'country'    => sanitize_text_field($_POST['shipping_country']     ?? ''),
                        'company'    => sanitize_text_field($_POST['shipping_company']     ?? ''),
                    ];
                }

            } elseif (function_exists('WC') && WC()->customer) {
                // Standard/shortcode flow — pull from WC customer session
                $billing = [
                    'first_name' => WC()->customer->get_billing_first_name(),
                    'last_name'  => WC()->customer->get_billing_last_name(),
                    'email'      => WC()->customer->get_billing_email(),
                    'phone'      => WC()->customer->get_billing_phone(),
                    'address_1'  => WC()->customer->get_billing_address_1(),
                    'address_2'  => WC()->customer->get_billing_address_2(),
                    'city'       => WC()->customer->get_billing_city(),
                    'state'      => WC()->customer->get_billing_state(),
                    'postcode'   => WC()->customer->get_billing_postcode(),
                    'country'    => WC()->customer->get_billing_country(),
                    'company'    => WC()->customer->get_billing_company(),
                ];
                $shipping = [
                    'first_name' => WC()->customer->get_shipping_first_name(),
                    'last_name'  => WC()->customer->get_shipping_last_name(),
                    'address_1'  => WC()->customer->get_shipping_address_1(),
                    'address_2'  => WC()->customer->get_shipping_address_2(),
                    'city'       => WC()->customer->get_shipping_city(),
                    'state'      => WC()->customer->get_shipping_state(),
                    'postcode'   => WC()->customer->get_shipping_postcode(),
                    'country'    => WC()->customer->get_shipping_country(),
                    'company'    => WC()->customer->get_shipping_company(),
                ];
                // Fall back shipping to billing if empty
                if (empty(trim($shipping['address_1'] . $shipping['first_name']))) {
                    $shipping = array_merge($shipping, [
                        'first_name' => $billing['first_name'],
                        'last_name'  => $billing['last_name'],
                        'address_1'  => $billing['address_1'],
                        'address_2'  => $billing['address_2'],
                        'city'       => $billing['city'],
                        'state'      => $billing['state'],
                        'postcode'   => $billing['postcode'],
                        'country'    => $billing['country'],
                        'company'    => $billing['company'],
                    ]);
                }
                // Only include if meaningful data exists
                if (empty(trim($billing['first_name'] . $billing['email'] . $billing['address_1']))) {
                    $billing  = [];
                    $shipping = [];
                }
            }

            return [
                'customer_billing'  => $billing,
                'customer_shipping' => $shipping,
            ];
        }

        private function get_shipping_cost(float $subtotal, string $country = 'US'): float {
            // Check if any applied coupon grants free shipping
            if (function_exists('WC') && WC()->cart) {
                $applied_coupons = WC()->cart->get_applied_coupons();
                foreach ($applied_coupons as $code) {
                    $coupon = new WC_Coupon($code);
                    if ($coupon->get_free_shipping()) {
                        return 0.0;
                    }
                }
            }

            // WooCommerce native shipping mode — use selected method
            if (($this->settings['shipping_mode'] ?? 'flat') === 'woocommerce') {
                return $this->get_wc_selected_shipping_cost();
            }

            // MAEF flat rate mode
            $international_cost = $this->settings['international_shipping_cost'] ?? '';
            if ($country !== 'US' && $international_cost !== '') {
                $intl_rate = round(floatval($international_cost), 2);
                return $intl_rate;
            }
            
            $flat = round(floatval($this->settings['flat_shipping_cost'] ?? 0), 2);
            if (!empty($this->settings['free_shipping_enabled'])) {
                $threshold = round(floatval($this->settings['free_shipping_threshold'] ?? 0), 2);
                if ($threshold > 0 && $subtotal >= $threshold) {
                    return 0.0;
                }
            }
            return $flat;
        }

        // Get the selected WooCommerce shipping method cost
        private function get_wc_selected_shipping_cost(): float {
            if (!function_exists('WC') || !WC()->cart || !WC()->session) return 0.0;

            // First try: read from our saved session value (most reliable)
            $saved_cost = WC()->session->get('maef_selected_shipping_cost');
            if ($saved_cost !== null && floatval($saved_cost) > 0) {
                return round(floatval($saved_cost), 2);
            }

            // Second try: force calculate and read from packages
            WC()->cart->calculate_shipping();
            WC()->cart->calculate_totals();

            $chosen_methods = WC()->session->get('chosen_shipping_methods', []);
            $packages = WC()->shipping()->get_packages();

            foreach ($packages as $package) {
                if (empty($package['rates'])) continue;
                foreach ($package['rates'] as $rate_id => $rate) {
                    if (!empty($chosen_methods) && in_array($rate_id, $chosen_methods)) {
                        $cost = round(floatval($rate->get_cost()), 2);
                        // Save to session for reliability
                        WC()->session->set('maef_selected_shipping_cost', $cost);
                        WC()->session->set('maef_selected_shipping_label', $rate->get_label());
                        return $cost;
                    }
                }
                // If no method chosen yet, return the first available rate
                $first_rate = reset($package['rates']);
                $cost = round(floatval($first_rate->get_cost()), 2);
                WC()->session->set('maef_selected_shipping_cost', $cost);
                WC()->session->set('maef_selected_shipping_label', $first_rate->get_label());
                return $cost;
            }

            // Third try: get shipping total directly from cart
            $cart_shipping = floatval(WC()->cart->get_shipping_total());
            if ($cart_shipping > 0) {
                return round($cart_shipping, 2);
            }

            return 0.0;
        }

        // Get all available WooCommerce shipping rates for display
        private function get_wc_shipping_rates(): array {
            if (!function_exists('WC') || !WC()->cart) return [];

            // Force shipping calculation
            WC()->cart->calculate_shipping();
            $packages = WC()->shipping()->get_packages();
            $rates = [];

            foreach ($packages as $package) {
                if (empty($package['rates'])) continue;
                foreach ($package['rates'] as $rate_id => $rate) {
                    $rates[$rate_id] = [
                        'id'    => $rate_id,
                        'label' => $rate->get_label(),
                        'cost'  => round(floatval($rate->get_cost()), 2),
                    ];
                }
            }

            return $rates;
        }

        // Get the label of the selected WooCommerce shipping method
        private function get_wc_selected_shipping_label(): string {
            if (!function_exists('WC') || !WC()->session) return '';

            // First try: read from session
            $saved_label = WC()->session->get('maef_selected_shipping_label');
            if (!empty($saved_label)) {
                return $saved_label;
            }

            // Fallback: read from packages
            $chosen_methods = WC()->session->get('chosen_shipping_methods', []);
            $packages = WC()->shipping()->get_packages();

            foreach ($packages as $package) {
                if (empty($package['rates'])) continue;
                foreach ($package['rates'] as $rate_id => $rate) {
                    if (!empty($chosen_methods) && in_array($rate_id, $chosen_methods)) {
                        return $rate->get_label();
                    }
                }
                $first_rate = reset($package['rates']);
                return $first_rate->get_label();
            }

            return '';
        }

        // ---------------------------------------------------------------
        // Cart actions — update quantities or remove a single item
        // Runs on wp_loaded via POST. No nested forms — separate POST forms.
        // ---------------------------------------------------------------

        public function handle_cart_actions() {
            if (!function_exists('WC') || !WC()->cart) return;

            // Handle shipping method selection (WooCommerce native mode)
            if (!empty($_POST['maef_shipping_method'])) {
                $chosen = sanitize_text_field($_POST['maef_shipping_method']);
                WC()->session->set('chosen_shipping_methods', [$chosen]);
                WC()->cart->calculate_shipping();
                WC()->cart->calculate_totals();

                // Save the selected shipping cost and label to session
                $packages = WC()->shipping()->get_packages();
                foreach ($packages as $package) {
                    if (empty($package['rates'])) continue;
                    if (isset($package['rates'][$chosen])) {
                        $rate = $package['rates'][$chosen];
                        WC()->session->set('maef_selected_shipping_cost', round(floatval($rate->get_cost()), 2));
                        WC()->session->set('maef_selected_shipping_label', $rate->get_label());
                        break;
                    }
                }

                $redirect_url = wp_get_referer() ? wp_get_referer() : home_url($_SERVER['REQUEST_URI']);
                wp_safe_redirect($redirect_url);
                exit;
            }

            // ── Advanced Coupons: Apply store credit ──────────────────────────
            if (!empty($_POST['maef_apply_store_credit'])) {
                if (empty($_POST['maef_sc_nonce']) || !wp_verify_nonce($_POST['maef_sc_nonce'], 'maef_store_credit_action')) return;
                if (!is_user_logged_in()) {
                    wc_add_notice('Please log in to use your store credit.', 'error');
                    wp_safe_redirect(wp_get_referer() ?: home_url($_SERVER['REQUEST_URI']));
                    exit;
                }
                $amount_requested = round(floatval($_POST['maef_sc_amount'] ?? 0), 2);
                if ($amount_requested <= 0) {
                    wc_add_notice('Please enter a valid store credit amount.', 'error');
                    wp_safe_redirect(wp_get_referer() ?: home_url($_SERVER['REQUEST_URI']));
                    exit;
                }
                $applied = false;
                // Advanced Coupons Store Credit — try the public API first (v4.5+)
                if (function_exists('acfw_apply_store_credit_to_cart')) {
                    $result  = acfw_apply_store_credit_to_cart($amount_requested);
                    $applied = !is_wp_error($result);
                }
                // Fallback: Advanced Coupons internal class method
                if (!$applied && class_exists('ACFWF') && is_object(ACFWF()) && method_exists(ACFWF(), 'Store_Credit_Manager')) {
                    $result  = ACFWF()->Store_Credit_Manager->apply_store_credit($amount_requested);
                    $applied = !is_wp_error($result);
                }
                // Fallback: fire the action hook Advanced Coupons listens on
                if (!$applied) {
                    do_action('acfw_apply_store_credit', $amount_requested, get_current_user_id());
                    $applied = true;
                }
                WC()->cart->calculate_totals();
                wp_safe_redirect(add_query_arg('sc_applied', $applied ? '1' : '0', wp_get_referer() ?: home_url($_SERVER['REQUEST_URI'])));
                exit;
            }

            // ── Advanced Coupons: Remove store credit ─────────────────────────
            if (!empty($_POST['maef_remove_store_credit'])) {
                if (empty($_POST['maef_sc_nonce']) || !wp_verify_nonce($_POST['maef_sc_nonce'], 'maef_store_credit_action')) return;
                if (function_exists('acfw_remove_store_credit_from_cart')) {
                    acfw_remove_store_credit_from_cart();
                } elseif (class_exists('ACFWF') && is_object(ACFWF()) && method_exists(ACFWF(), 'Store_Credit_Manager')) {
                    ACFWF()->Store_Credit_Manager->remove_store_credit();
                } else {
                    do_action('acfw_remove_store_credit');
                }
                WC()->cart->calculate_totals();
                wp_safe_redirect(add_query_arg('sc_removed', '1', wp_get_referer() ?: home_url($_SERVER['REQUEST_URI'])));
                exit;
            }

            // Apply coupon
            if (!empty($_POST['maef_apply_coupon'])) {                if (empty($_POST['maef_coupon_nonce']) || !wp_verify_nonce($_POST['maef_coupon_nonce'], 'maef_coupon_action')) return;
                $coupon_code = sanitize_text_field(trim($_POST['maef_coupon_code'] ?? ''));
                if (!empty($coupon_code)) {
                    WC()->cart->apply_coupon($coupon_code);
                }
                WC()->cart->calculate_totals();
                $redirect_url = wp_get_referer() ? wp_get_referer() : home_url($_SERVER['REQUEST_URI']);
                wp_safe_redirect(add_query_arg('coupon_applied', '1', $redirect_url));
                exit;
            }

            // Remove coupon
            if (!empty($_POST['maef_remove_coupon'])) {
                if (empty($_POST['maef_coupon_nonce']) || !wp_verify_nonce($_POST['maef_coupon_nonce'], 'maef_coupon_action')) return;
                $coupon_code = sanitize_text_field($_POST['maef_remove_coupon']);
                WC()->cart->remove_coupon($coupon_code);
                // Clear cached shipping so it recalculates without the coupon
                if (WC()->session) {
                    WC()->session->__unset('maef_selected_shipping_cost');
                    WC()->session->__unset('maef_selected_shipping_label');
                }
                WC()->cart->calculate_totals();
                $redirect_url = wp_get_referer() ? wp_get_referer() : home_url($_SERVER['REQUEST_URI']);
                wp_safe_redirect(add_query_arg('coupon_removed', '1', $redirect_url));
                exit;
            }

            // Update cart quantities
            if (!empty($_POST['maef_update_cart'])) {
                if (empty($_POST['maef_cart_nonce']) || !wp_verify_nonce($_POST['maef_cart_nonce'], 'maef_cart_action')) return;
                $quantities = isset($_POST['maef_qty']) ? $_POST['maef_qty'] : [];
                foreach (WC()->cart->get_cart() as $key => $item) {
                    $new_qty = isset($quantities[$key]) ? max(0, intval($quantities[$key])) : $item['quantity'];
                    if ($new_qty === 0) {
                        WC()->cart->remove_cart_item($key);
                    } else {
                        WC()->cart->set_quantity($key, $new_qty);
                    }
                }
                WC()->cart->calculate_totals();
                // Use referer or current URL
                $redirect_url = wp_get_referer() ? wp_get_referer() : home_url($_SERVER['REQUEST_URI']);
                wp_safe_redirect(add_query_arg('cart_updated', '1', $redirect_url));
                exit;
            }

            // Remove single item
            if (!empty($_POST['maef_remove_item'])) {
                $cart_key = sanitize_key($_POST['maef_remove_item']);
                
                // Debug: log what we received
                error_log('MAEF Remove: cart_key=' . $cart_key);
                error_log('MAEF Remove: nonce=' . ($_POST['maef_remove_nonce'] ?? 'MISSING'));
                
                // Verify nonce
                if (empty($_POST['maef_remove_nonce'])) {
                    error_log('MAEF Remove: nonce missing');
                    return;
                }
                
                if (!wp_verify_nonce($_POST['maef_remove_nonce'], 'maef_remove_' . $cart_key)) {
                    error_log('MAEF Remove: nonce verification failed');
                    return;
                }
                
                // Check if item exists in cart
                $cart_contents = WC()->cart->get_cart();
                if (!isset($cart_contents[$cart_key])) {
                    error_log('MAEF Remove: cart key not found in cart');
                    return;
                }
                
                error_log('MAEF Remove: attempting to remove ' . $cart_key);
                $removed = WC()->cart->remove_cart_item($cart_key);
                error_log('MAEF Remove: remove result = ' . ($removed ? 'SUCCESS' : 'FAILED'));
                
                WC()->cart->calculate_totals();
                
                // Redirect to current page
                $redirect_url = remove_query_arg(array('cart_updated', 'maef_remove_item', 'maef_remove_nonce'), wp_get_referer());
                if (!$redirect_url) {
                    $redirect_url = home_url($_SERVER['REQUEST_URI']);
                }
                wp_safe_redirect($redirect_url);
                exit;
            }
        }

        // ---------------------------------------------------------------
        // [maef_cart] — editable cart: quantities, remove, checkout
        // Three separate sibling forms — no nesting.
        // ---------------------------------------------------------------

        public function render_cart() {
            if (!function_exists('WC') || !WC()->cart) return '<p>Cart is not available.</p>';

            $cart     = WC()->cart;
            $bg       = esc_attr($this->settings['button_bg'] ?? '#000000');
            $fg       = esc_attr($this->settings['button_text_color'] ?? '#ffffff');
            $label    = esc_html($this->settings['button_label'] ?? 'Proceed to Secure Checkout');
            $page_url = esc_url(get_permalink());
            $items    = $cart->get_cart();

            ob_start();

            if (!empty($_GET['cart_updated'])) {
                echo '<p style="color:green;font-weight:bold;">&#10003; Cart updated.</p>';
            }

            if ($cart->is_empty()) {
                echo '<p>Your cart is empty.</p>';
                return ob_get_clean();
            }

            // --- 1. Hidden remove forms (one per item, display:none, outside main form) ---
            foreach ($items as $cart_key => $item) {
                $nonce = wp_create_nonce('maef_remove_' . $cart_key);
                echo '<form method="post" action="' . $page_url . '" id="maef-rm-' . esc_attr($cart_key) . '" style="display:none;">';
                echo '<input type="hidden" name="maef_remove_item" value="' . esc_attr($cart_key) . '">';
                echo '<input type="hidden" name="maef_remove_nonce" value="' . esc_attr($nonce) . '">';
                echo '</form>';
            }

            // --- 2. Cart update form ---
            echo '<form method="post" action="' . $page_url . '">';
            echo wp_nonce_field('maef_cart_action', 'maef_cart_nonce', true, false);
            echo '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
            echo '<thead><tr style="border-bottom:2px solid #ddd;">';
            echo '<th style="text-align:left;padding:10px 8px;">Product</th>';
            echo '<th style="text-align:center;padding:10px 8px;">Qty</th>';
            echo '<th style="text-align:right;padding:10px 8px;">Price</th>';
            echo '<th style="text-align:center;padding:10px 8px;">Remove</th>';
            echo '</tr></thead><tbody>';

            foreach ($items as $cart_key => $item) {
                $product    = $item['data'];
                $qty        = intval($item['quantity']);
                $line_total = floatval($item['line_total']);
                $img_id     = $product->get_image_id();
                $img_url    = $img_id ? wp_get_attachment_image_url($img_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');

                echo '<tr style="border-bottom:1px solid #eee;">';

                echo '<td style="padding:12px 8px;">';
                echo '<img src="' . esc_url($img_url) . '" alt="' . esc_attr($product->get_name()) . '" style="width:50px;height:50px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:8px;">';
                echo esc_html($product->get_name());
                echo '</td>';

                echo '<td style="text-align:center;padding:12px 8px;">';
                echo '<input type="number" name="maef_qty[' . esc_attr($cart_key) . ']" value="' . esc_attr($qty) . '" min="1" step="1" style="width:55px;text-align:center;padding:4px;border:1px solid #ccc;border-radius:3px;">';
                echo '</td>';

                echo '<td style="text-align:right;padding:12px 8px;">' . wc_price($line_total) . '</td>';

                echo '<td style="text-align:center;padding:12px 8px;">';
                echo '<button type="button" onclick="document.getElementById(\'maef-rm-' . esc_js($cart_key) . '\').submit();" style="background:none;border:none;color:#cc0000;font-size:18px;font-weight:bold;cursor:pointer;" title="Remove">&#x2715;</button>';
                echo '</td>';

                echo '</tr>';
            }

            echo '</tbody>';
            echo '<tfoot><tr>';
            echo '<td colspan="2" style="padding:12px 8px;"><button type="submit" name="maef_update_cart" value="1" style="background:#f0f0f0;color:#333;padding:8px 16px;border:1px solid #ccc;cursor:pointer;border-radius:3px;">Update Cart</button></td>';
            echo '<td colspan="2"></td>';
            echo '</tr></tfoot>';
            echo '</table>';
            echo '</form>';

            // --- Coupon field ABOVE totals (when setting enabled) ---
            $coupon_below_update = !empty($this->settings['coupon_below_update']);
            if ($coupon_below_update) {
                $applied_coupons = WC()->cart->get_applied_coupons();
                if (!empty($applied_coupons)) {
                    foreach ($applied_coupons as $idx => $coupon_code) {
                        $discount = WC()->cart->get_coupon_discount_amount($coupon_code);
                        echo '<div style="display:flex;align-items:center;justify-content:space-between;max-width:360px;margin-bottom:8px;padding:8px 12px;background:rgba(46,125,50,0.08);border-radius:4px;font-size:14px;">';
                        echo '<span style="color:#2e7d32;">&#10003; Coupon: <strong>' . esc_html(strtoupper($coupon_code)) . '</strong> &minus;' . wc_price($discount) . '</span>';
                        echo '<form method="post" style="margin:0;padding:0;display:inline;">';
                        echo '<input type="hidden" name="maef_coupon_nonce" value="' . wp_create_nonce('maef_coupon_action') . '"/>';
                        echo '<input type="hidden" name="maef_remove_coupon" value="' . esc_attr($coupon_code) . '"/>';
                        echo '<button type="submit" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:16px;padding:0 4px;line-height:1;" title="Remove coupon">&times;</button>';
                        echo '</form>';
                        echo '</div>';
                    }
                }
                echo '<div class="maef-coupon-wrap" style="max-width:360px;margin-bottom:20px;display:block !important;visibility:visible !important;overflow:visible !important;clear:both;">';
                echo '<form method="post" style="display:flex !important;gap:8px;visibility:visible !important;">';
                echo '<input type="hidden" name="maef_coupon_nonce" value="' . wp_create_nonce('maef_coupon_action') . '"/>';
                echo '<input type="text" name="maef_coupon_code" placeholder="Coupon code" style="flex:1;padding:8px 12px;border:1px solid #ccc;border-radius:3px;font-size:14px;display:block !important;visibility:visible !important;"/>';
                echo '<button type="submit" name="maef_apply_coupon" value="1" style="padding:8px 16px;background:' . $bg . ';color:' . $fg . ';border:none;cursor:pointer;border-radius:3px;font-size:14px;display:inline-block !important;visibility:visible !important;">Apply</button>';
                echo '</form>';
                echo '</div>';
            }

            // --- Shipping, tax & totals breakdown ---
            $subtotal         = floatval($cart->get_subtotal());
            $flat_rate        = round(floatval($this->settings['flat_shipping_cost'] ?? 0), 2);
            $shipping_label   = esc_html($this->settings['shipping_label'] ?? 'Shipping');
            $customer_country = WC()->customer ? WC()->customer->get_shipping_country() : 'US';
            if (empty($customer_country)) $customer_country = 'US';

            // Ensure WC customer location is set so tax and shipping calculates correctly
            if (WC()->customer) {
                $base_country = WC()->countries->get_base_country();
                $base_state   = WC()->countries->get_base_state();
                $base_zip     = WC()->countries->get_base_postcode();
                $base_city    = WC()->countries->get_base_city();
                WC()->customer->set_billing_location($base_country, $base_state, $base_zip, $base_city);
                WC()->customer->set_shipping_location($base_country, $base_state, $base_zip, $base_city);
            }

            // If WooCommerce native shipping, pre-calculate rates and save to session
            // BEFORE calling get_shipping_cost so the session value is available
            $shipping_mode = $this->settings['shipping_mode'] ?? 'flat';
            $wc_rates = [];
            $active_rate_id = null;
            if ($shipping_mode === 'woocommerce') {
                $wc_rates = $this->get_wc_shipping_rates();
                $chosen_methods = WC()->session ? WC()->session->get('chosen_shipping_methods', []) : [];

                if (!empty($wc_rates)) {
                    foreach ($wc_rates as $rate_id => $rate) {
                        if (!empty($chosen_methods) && in_array($rate_id, $chosen_methods)) {
                            $active_rate_id = $rate_id;
                            break;
                        }
                    }
                    if (!$active_rate_id) {
                        $active_rate_id = array_key_first($wc_rates);
                    }

                    if ($active_rate_id && isset($wc_rates[$active_rate_id])) {
                        $active_rate = $wc_rates[$active_rate_id];
                        WC()->session->set('maef_selected_shipping_cost', $active_rate['cost']);
                        WC()->session->set('maef_selected_shipping_label', $active_rate['label']);
                        if (empty($chosen_methods)) {
                            WC()->session->set('chosen_shipping_methods', [$active_rate_id]);
                        }
                    }
                }
            }

            // Get shipping cost — for WC mode, use the value we just determined directly
            $shipping_cost = 0.0;
            $coupon_free_shipping = false;

            if ($shipping_mode === 'woocommerce' && $active_rate_id && isset($wc_rates[$active_rate_id])) {
                $shipping_cost = round(floatval($wc_rates[$active_rate_id]['cost']), 2);

                // Check if coupon grants free shipping
                if (function_exists('WC') && WC()->cart) {
                    foreach (WC()->cart->get_applied_coupons() as $code) {
                        $coupon_obj = new WC_Coupon($code);
                        if ($coupon_obj->get_free_shipping()) {
                            $coupon_free_shipping = true;
                            $shipping_cost = 0.0;
                            break;
                        }
                    }
                }
            } elseif ($shipping_mode === 'flat') {
                $shipping_cost = $this->get_shipping_cost($subtotal, $customer_country);
            }
            $is_free = ($coupon_free_shipping || ($shipping_cost === 0.0 && !empty($wc_rates)));

            $cart->calculate_totals();
            $tax_total        = round(floatval($cart->get_total_tax()), 2);
            $discount_total   = round(floatval($cart->get_discount_total()), 2);
            $grand_total      = round($subtotal - $discount_total + $shipping_cost + $tax_total, 2);

            // Debug comment (visible in page source only — right click → view source → search MAEF)
            echo '<!-- MAEF Debug: mode=' . $shipping_mode . ' shipping_cost=' . $shipping_cost . ' coupon_free=' . ($coupon_free_shipping ? 'YES' : 'NO') . ' active_rate_id=' . ($active_rate_id ?? 'NONE') . ' rate_count=' . count($wc_rates) . ' subtotal=' . $subtotal . ' discount=' . $discount_total . ' tax=' . $tax_total . ' grand_total=' . $grand_total . ' -->';

            // Free shipping threshold notice
            if (!empty($this->settings['free_shipping_enabled']) && $flat_rate > 0) {
                $threshold = round(floatval($this->settings['free_shipping_threshold'] ?? 0), 2);
                if ($threshold > 0 && $subtotal < $threshold) {
                    $remaining = round($threshold - $subtotal, 2);
                    echo '<p style="margin:0 0 16px;padding:10px 14px;background:#f0f7f0;border-left:4px solid #2e7d32;font-size:0.9em;color:#2e7d32;">Spend ' . wc_price($remaining) . ' more to qualify for <strong>free shipping</strong>!</p>';
                }
            }

            // Totals table
            echo '<table style="width:100%;max-width:360px;margin-left:auto;border-collapse:collapse;margin-bottom:24px;">';
            echo '<tr><td style="padding:10px 4px;border-bottom:1px solid #efefef;color:#555;">Subtotal</td>';
            echo '<td style="padding:10px 4px;border-bottom:1px solid #efefef;text-align:right;font-weight:600;">' . wc_price($subtotal) . '</td></tr>';

            if ($discount_total > 0) {
                echo '<tr><td style="padding:10px 4px;border-bottom:1px solid #efefef;color:#2e7d32;">Discount</td>';
                echo '<td style="padding:10px 4px;border-bottom:1px solid #efefef;text-align:right;font-weight:600;color:#2e7d32;">&minus;' . wc_price($discount_total) . '</td></tr>';
            }

            $shipping_mode = $this->settings['shipping_mode'] ?? 'flat';

            if ($shipping_mode === 'woocommerce') {
                // WooCommerce native shipping — rates already calculated above
                if (!empty($wc_rates)) {
                    echo '<tr><td colspan="2" style="padding:10px 4px;border-bottom:1px solid #efefef;">';
                    echo '<form method="post" style="margin:0;">';
                    echo '<div style="color:#555;margin-bottom:8px;">Shipping</div>';

                    foreach ($wc_rates as $rate_id => $rate) {
                        $checked = ($rate_id === $active_rate_id) ? 'checked' : '';

                        $cost_display = $rate['cost'] > 0 ? wc_price($rate['cost']) : '<span style="color:#2e7d32;font-weight:700;">Free</span>';

                        echo '<label style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;cursor:pointer;font-size:14px;">';
                        echo '<span><input type="radio" name="maef_shipping_method" value="' . esc_attr($rate_id) . '" ' . $checked . ' onchange="this.form.submit();" style="margin-right:8px;"/> ' . esc_html($rate['label']) . '</span>';
                        echo '<span style="font-weight:600;">' . $cost_display . '</span>';
                        echo '</label>';
                    }

                    echo '</form></td></tr>';
                }
            } elseif ($flat_rate > 0 || !empty($this->settings['flat_shipping_cost'])) {
                // MAEF flat rate shipping
                echo '<tr><td style="padding:10px 4px;border-bottom:1px solid #efefef;color:#555;">' . $shipping_label . '</td>';
                echo '<td style="padding:10px 4px;border-bottom:1px solid #efefef;text-align:right;font-weight:600;">';
                if ($is_free) {
                    echo '<span style="color:#2e7d32;font-weight:700;">Free</span>';
                    echo '<del style="color:#999;font-size:0.85em;margin-left:6px;">' . wc_price($flat_rate) . '</del>';
                } else {
                    echo wc_price($shipping_cost);
                }
                echo '</td></tr>';
            }

            if ($tax_total > 0) {
                echo '<tr><td style="padding:10px 4px;border-bottom:1px solid #efefef;color:#555;">Tax</td>';
                echo '<td style="padding:10px 4px;border-bottom:1px solid #efefef;text-align:right;font-weight:600;">' . wc_price($tax_total) . '</td></tr>';
            }

            echo '<tr><td style="padding:14px 4px;font-size:1.1em;"><strong>Total</strong></td>';
            echo '<td style="padding:14px 4px;text-align:right;font-size:1.1em;"><strong>' . wc_price($grand_total) . '</strong></td></tr>';
            echo '</table>';

            // --- Applied coupons + input (below totals — default position) ---
            if (empty($this->settings['coupon_below_update'])) {
                $applied_coupons = WC()->cart->get_applied_coupons();
                if (!empty($applied_coupons)) {
                    foreach ($applied_coupons as $idx => $coupon_code) {
                        $discount = WC()->cart->get_coupon_discount_amount($coupon_code);
                        echo '<div style="display:flex;align-items:center;justify-content:space-between;max-width:360px;margin-left:auto;margin-bottom:8px;padding:8px 12px;background:rgba(46,125,50,0.08);border-radius:4px;font-size:14px;">';
                        echo '<span style="color:#2e7d32;">&#10003; Coupon: <strong>' . esc_html(strtoupper($coupon_code)) . '</strong> &minus;' . wc_price($discount) . '</span>';
                        echo '<form method="post" style="margin:0;padding:0;display:inline;">';
                        echo '<input type="hidden" name="maef_coupon_nonce" value="' . wp_create_nonce('maef_coupon_action') . '"/>';
                        echo '<input type="hidden" name="maef_remove_coupon" value="' . esc_attr($coupon_code) . '"/>';
                        echo '<button type="submit" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:16px;padding:0 4px;line-height:1;" title="Remove coupon">&times;</button>';
                        echo '</form>';
                        echo '</div>';
                    }
                }
                echo '<div class="maef-coupon-wrap" style="max-width:360px;margin-left:auto;margin-bottom:20px;display:block !important;visibility:visible !important;overflow:visible !important;clear:both;">';
                echo '<form method="post" style="display:flex !important;gap:8px;visibility:visible !important;">';
                echo '<input type="hidden" name="maef_coupon_nonce" value="' . wp_create_nonce('maef_coupon_action') . '"/>';
                echo '<input type="text" name="maef_coupon_code" placeholder="Coupon code" style="flex:1;padding:8px 12px;border:1px solid #ccc;border-radius:3px;font-size:14px;display:block !important;visibility:visible !important;"/>';
                echo '<button type="submit" name="maef_apply_coupon" value="1" style="padding:8px 16px;background:' . $bg . ';color:' . $fg . ';border:none;cursor:pointer;border-radius:3px;font-size:14px;display:inline-block !important;visibility:visible !important;">Apply</button>';
                echo '</form>';
                echo '</div>';
            }

            // --- Coupon notices ---
            if (!empty($_GET['coupon_applied'])) {
                $notices = wc_get_notices('success');
                if (!empty($notices)) {
                    foreach ($notices as $notice) {
                        $msg = is_array($notice) ? ($notice['notice'] ?? '') : $notice;
                        echo '<p style="color:#2e7d32;font-size:14px;margin-bottom:8px;">&#10003; ' . wp_kses_post($msg) . '</p>';
                    }
                    wc_clear_notices();
                }
                $errors = wc_get_notices('error');
                if (!empty($errors)) {
                    foreach ($errors as $notice) {
                        $msg = is_array($notice) ? ($notice['notice'] ?? '') : $notice;
                        echo '<p style="color:#dc2626;font-size:14px;margin-bottom:8px;">' . wp_kses_post($msg) . '</p>';
                    }
                    wc_clear_notices();
                }
            }

            // --- Advanced Coupons Store Credit UI ---
            // Only show if Advanced Coupons is active and customer is logged in
            if (is_user_logged_in() && (function_exists('acfw_get_customer_store_credit') || class_exists('ACFWF'))) {
                $user_id  = get_current_user_id();
                $balance  = 0.0;
                // Get balance — try public function first, then internal class
                if (function_exists('acfw_get_customer_store_credit')) {
                    $balance = floatval(acfw_get_customer_store_credit($user_id));
                } elseif (class_exists('ACFWF') && is_object(ACFWF()) && method_exists(ACFWF(), 'Store_Credit_Manager')) {
                    $balance = floatval(ACFWF()->Store_Credit_Manager->get_store_credit_balance($user_id));
                }

                // Check if store credit is already applied (Advanced Coupons stores it in session/cart fees)
                $sc_applied       = false;
                $sc_applied_amount = 0.0;
                foreach (WC()->cart->get_fees() as $fee) {
                    if (stripos($fee->name, 'store credit') !== false || stripos($fee->name, 'acfw') !== false) {
                        $sc_applied        = true;
                        $sc_applied_amount = abs(floatval($fee->amount));
                        break;
                    }
                }
                // Also check Advanced Coupons' own session key
                if (!$sc_applied && WC()->session) {
                    $sc_session = WC()->session->get('acfw_store_credit_data');
                    if (!empty($sc_session['amount'])) {
                        $sc_applied        = true;
                        $sc_applied_amount = floatval($sc_session['amount']);
                    }
                }

                echo '<div class="maef-store-credit-wrap" style="max-width:360px;margin-left:auto;margin-bottom:16px;padding:14px;background:#f0f7ff;border:1px solid #bbd6f5;border-radius:6px;">';
                echo '<div style="font-size:13px;font-weight:600;color:#1e40af;margin-bottom:8px;">🏦 Store Credit';
                if ($balance > 0) echo ' — Available: <strong>' . wc_price($balance) . '</strong>';
                echo '</div>';

                if ($sc_applied) {
                    // Show applied amount + remove button
                    echo '<div style="display:flex;align-items:center;justify-content:space-between;font-size:14px;">';
                    echo '<span style="color:#2e7d32;">&#10003; <strong>' . wc_price($sc_applied_amount) . '</strong> store credit applied</span>';
                    echo '<form method="post" style="margin:0;">';
                    echo wp_nonce_field('maef_store_credit_action', 'maef_sc_nonce', true, false);
                    echo '<button type="submit" name="maef_remove_store_credit" value="1" style="background:none;border:none;color:#dc2626;font-size:13px;cursor:pointer;padding:0;text-decoration:underline;">Remove</button>';
                    echo '</form>';
                    echo '</div>';
                } elseif ($balance > 0) {
                    // Show input + apply button
                    $max_credit = min($balance, $grand_total);
                    echo '<form method="post" style="display:flex;gap:8px;align-items:center;">';
                    echo wp_nonce_field('maef_store_credit_action', 'maef_sc_nonce', true, false);
                    echo '<input type="number" name="maef_sc_amount" value="' . esc_attr(number_format($max_credit, 2, '.', '')) . '" min="0.01" max="' . esc_attr($balance) . '" step="0.01" style="width:110px;padding:7px 10px;border:1px solid #bbd6f5;border-radius:4px;font-size:14px;"/>';
                    echo '<button type="submit" name="maef_apply_store_credit" value="1" style="padding:8px 14px;background:#1e40af;color:#fff;border:none;cursor:pointer;border-radius:4px;font-size:14px;font-weight:600;">Apply</button>';
                    echo '</form>';
                    echo '<p style="font-size:11px;color:#6b7280;margin:6px 0 0;">Enter amount to apply (max ' . wc_price($balance) . ')</p>';
                } else {
                    echo '<p style="font-size:13px;color:#6b7280;margin:0;">No store credit balance available.</p>';
                }
                echo '</div>';

                // Notices for store credit actions
                if (!empty($_GET['sc_applied'])) {
                    if ($_GET['sc_applied'] === '1') {
                        echo '<p style="color:#2e7d32;font-size:14px;margin-bottom:8px;">&#10003; Store credit applied successfully.</p>';
                    } else {
                        echo '<p style="color:#dc2626;font-size:14px;margin-bottom:8px;">Could not apply store credit. Please try again.</p>';
                    }
                }
                if (!empty($_GET['sc_removed'])) {
                    echo '<p style="color:#555;font-size:14px;margin-bottom:8px;">Store credit removed.</p>';
                }
            }

            // --- 3. Checkout form (separate, below table) ---
            echo '<form method="post" id="maef-checkout-form">';
            echo '<input type="hidden" name="maef_start_checkout" value="1">';
            
            // Terms and conditions checkbox if enabled
            if (!empty($this->settings['terms_enabled'])) {
                $terms_url  = esc_url($this->settings['terms_url'] ?? '');
                $terms_text = esc_html($this->settings['terms_text'] ?? 'Terms and Conditions');
                echo '<div style="margin-bottom:16px;">';
                echo '<label style="display:flex;align-items:center;gap:8px;font-size:14px;">';
                echo '<input type="checkbox" name="maef_accept_terms" value="1" required style="width:auto;margin:0;"/>';
                echo '<span>I accept the <a href="' . $terms_url . '" target="_blank" style="color:#2271b1;text-decoration:underline;">' . $terms_text . '</a></span>';
                echo '</label>';
                echo '</div>';
            }
            
            echo '<button type="submit" style="background:' . $bg . ';color:' . $fg . ';padding:12px 28px;border:none;cursor:pointer;font-size:16px;border-radius:3px;">' . $label . '</button>';
            echo '</form>';

            return ob_get_clean();
        }

        // ---------------------------------------------------------------
        // [maef_checkout] — cart summary table + checkout button (no editing)
        // ---------------------------------------------------------------

        public function render_checkout_button() {
            if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
                return '<p>Your cart is empty.</p>';
            }

            $bg             = esc_attr($this->settings['button_bg']         ?? '#000000');
            $color          = esc_attr($this->settings['button_text_color'] ?? '#ffffff');
            $label          = esc_html($this->settings['button_label']      ?? 'Proceed to Secure Checkout');
            $shipping_label = esc_html($this->settings['shipping_label']    ?? 'Shipping');

            $subtotal      = floatval(WC()->cart->get_subtotal());
            $flat_rate     = round(floatval($this->settings['flat_shipping_cost'] ?? 0), 2);
            $customer_country = WC()->customer ? WC()->customer->get_shipping_country() : 'US';
            if (empty($customer_country)) $customer_country = 'US';

            // Ensure WC customer location is set so tax and shipping calculate correctly
            if (WC()->customer) {
                $base_country = WC()->countries->get_base_country();
                $base_state   = WC()->countries->get_base_state();
                $base_zip     = WC()->countries->get_base_postcode();
                $base_city    = WC()->countries->get_base_city();
                WC()->customer->set_billing_location($base_country, $base_state, $base_zip, $base_city);
                WC()->customer->set_shipping_location($base_country, $base_state, $base_zip, $base_city);
            }

            // Shipping calculation (same logic as render_cart)
            $shipping_mode = $this->settings['shipping_mode'] ?? 'flat';
            $wc_rates_checkout = [];
            $active_rate_id_checkout = null;
            if ($shipping_mode === 'woocommerce') {
                $saved_chosen = WC()->session ? WC()->session->get('chosen_shipping_methods', []) : [];
                $wc_rates_checkout = $this->get_wc_shipping_rates();
                if (!empty($saved_chosen) && WC()->session) {
                    WC()->session->set('chosen_shipping_methods', $saved_chosen);
                }
                if (!empty($wc_rates_checkout)) {
                    foreach ($wc_rates_checkout as $rate_id => $rate) {
                        if (!empty($saved_chosen) && in_array($rate_id, $saved_chosen)) {
                            $active_rate_id_checkout = $rate_id;
                            break;
                        }
                    }
                    if (!$active_rate_id_checkout) {
                        $active_rate_id_checkout = array_key_first($wc_rates_checkout);
                    }
                    if ($active_rate_id_checkout && isset($wc_rates_checkout[$active_rate_id_checkout])) {
                        WC()->session->set('maef_selected_shipping_cost', $wc_rates_checkout[$active_rate_id_checkout]['cost']);
                        WC()->session->set('maef_selected_shipping_label', $wc_rates_checkout[$active_rate_id_checkout]['label']);
                    }
                }
            }

            // Get shipping cost
            $shipping_cost = 0.0;
            $coupon_free_shipping = false;
            if ($shipping_mode === 'woocommerce' && $active_rate_id_checkout && isset($wc_rates_checkout[$active_rate_id_checkout])) {
                $shipping_cost = round(floatval($wc_rates_checkout[$active_rate_id_checkout]['cost']), 2);
                if (function_exists('WC') && WC()->cart) {
                    foreach (WC()->cart->get_applied_coupons() as $code) {
                        $coupon_obj = new WC_Coupon($code);
                        if ($coupon_obj->get_free_shipping()) {
                            $coupon_free_shipping = true;
                            $shipping_cost = 0.0;
                            break;
                        }
                    }
                }
            } else {
                $shipping_cost = $this->get_shipping_cost($subtotal, $customer_country);
            }
            $is_free = ($coupon_free_shipping || ($shipping_cost === 0.0 && $flat_rate > 0));

            WC()->cart->calculate_totals();
            $tax_total     = round(floatval(WC()->cart->get_total_tax()), 2);
            $discount_total = round(floatval(WC()->cart->get_discount_total()), 2);
            $grand_total   = round($subtotal - $discount_total + $shipping_cost + $tax_total, 2);

            $threshold_notice = '';
            if (!empty($this->settings['free_shipping_enabled']) && $flat_rate > 0) {
                $threshold = round(floatval($this->settings['free_shipping_threshold'] ?? 0), 2);
                if ($threshold > 0 && $subtotal < $threshold) {
                    $remaining = round($threshold - $subtotal, 2);
                    $threshold_notice = '<p style="margin:0 0 16px;padding:10px 14px;background:#f0f7f0;border-left:4px solid #2e7d32;font-size:0.9em;color:#2e7d32;">Spend ' . wc_price($remaining) . ' more to qualify for <strong>free shipping</strong>!</p>';
                }
            }

            ob_start();
            ?>
            <div class="maef-cart-wrap" style="font-family:inherit;max-width:900px;">
                <style>
                    .maef-cart-table { width:100%; border-collapse:collapse; margin-bottom:24px; }
                    .maef-cart-table th { text-align:left; padding:10px 12px; border-bottom:2px solid #e0e0e0; font-size:0.9em; text-transform:uppercase; letter-spacing:0.04em; color:#555; }
                    .maef-cart-table td { padding:14px 12px; border-bottom:1px solid #efefef; vertical-align:middle; }
                    .maef-cart-table img { width:60px; height:60px; object-fit:cover; border-radius:4px; display:block; }
                    .maef-cart-table .maef-col-img  { width:80px; }
                    .maef-cart-table .maef-col-price, .maef-cart-table .maef-col-qty, .maef-cart-table .maef-col-sub { text-align:right; white-space:nowrap; }
                    .maef-cart-table .maef-col-qty { text-align:center; width:80px; }
                    .maef-totals-table { width:100%; max-width:360px; margin-left:auto; border-collapse:collapse; margin-bottom:24px; }
                    .maef-totals-table td { padding:10px 4px; border-bottom:1px solid #efefef; font-size:0.95em; }
                    .maef-totals-table .maef-total-label { color:#555; }
                    .maef-totals-table .maef-total-value { text-align:right; font-weight:600; }
                    .maef-totals-table tr.maef-grand td { font-size:1.1em; border-bottom:none; padding-top:14px; }
                    .maef-btn-wrap { text-align:right; }
                </style>
                <form method="post" id="maef-checkout-form-main">
                    <input type="hidden" name="maef_start_checkout" value="1"/>
                    <?php echo $threshold_notice; ?>
                    <table class="maef-cart-table">
                        <thead>
                            <tr>
                                <th class="maef-col-img"></th>
                                <th class="maef-col-name">Product</th>
                                <th class="maef-col-price">Price</th>
                                <th class="maef-col-qty">Qty</th>
                                <th class="maef-col-sub">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) :
                            $product  = $cart_item['data'];
                            $qty      = $cart_item['quantity'];
                            $price    = floatval($product->get_price());
                            $line_sub = $price * $qty;
                            $img_id   = $product->get_image_id();
                            $img_url  = $img_id ? wp_get_attachment_image_url($img_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');
                        ?>
                            <tr>
                                <td class="maef-col-img"><img src="<?php echo esc_url($img_url); ?>" alt="<?php echo esc_attr($product->get_name()); ?>"/></td>
                                <td class="maef-col-name"><?php echo esc_html($product->get_name()); ?></td>
                                <td class="maef-col-price"><?php echo wc_price($price); ?></td>
                                <td class="maef-col-qty"><?php echo intval($qty); ?></td>
                                <td class="maef-col-sub"><?php echo wc_price($line_sub); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <table class="maef-totals-table">
                        <tr>
                            <td class="maef-total-label">Subtotal</td>
                            <td class="maef-total-value"><?php echo wc_price($subtotal); ?></td>
                        </tr>
                        <?php if ($discount_total > 0) : ?>
                        <tr>
                            <td class="maef-total-label" style="color:#2e7d32;">Discount</td>
                            <td class="maef-total-value" style="color:#2e7d32;">&minus;<?php echo wc_price($discount_total); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($shipping_mode === 'woocommerce' && !empty($wc_rates_checkout)) : ?>
                        <tr>
                            <td colspan="2" class="maef-total-label" style="padding:10px 4px;">
                                <div style="margin-bottom:4px;">Shipping</div>
                                <?php foreach ($wc_rates_checkout as $rate_id => $rate) :
                                    $checked = ($rate_id === $active_rate_id_checkout) ? 'checked' : '';
                                    $cost_display = $rate['cost'] > 0 ? wc_price($rate['cost']) : '<span style="color:#2e7d32;font-weight:700;">Free</span>';
                                ?>
                                <label style="display:flex;align-items:center;justify-content:space-between;padding:4px 0;cursor:pointer;font-size:14px;">
                                    <span><input type="radio" name="maef_shipping_method" value="<?php echo esc_attr($rate_id); ?>" <?php echo $checked; ?> onchange="document.getElementById('maef-shipping-form-checkout').submit();" style="margin-right:8px;"/> <?php echo esc_html($rate['label']); ?></span>
                                    <span style="font-weight:600;"><?php echo $cost_display; ?></span>
                                </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php elseif ($flat_rate > 0 || !empty($this->settings['flat_shipping_cost'])) : ?>
                        <tr>
                            <td class="maef-total-label"><?php echo $shipping_label; ?></td>
                            <td class="maef-total-value">
                                <?php if ($is_free) : ?>
                                    <span style="color:#2e7d32;font-weight:700;">Free</span>
                                    <del style="color:#999;font-size:0.85em;margin-left:6px;"><?php echo wc_price($flat_rate); ?></del>
                                <?php else : ?>
                                    <?php echo wc_price($shipping_cost); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($tax_total > 0) : ?>
                        <tr>
                            <td class="maef-total-label">Tax</td>
                            <td class="maef-total-value"><?php echo wc_price($tax_total); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="maef-grand">
                            <td class="maef-total-label"><strong>Total</strong></td>
                            <td class="maef-total-value"><strong><?php echo wc_price($grand_total); ?></strong></td>
                        </tr>
                    </table>
                    </form><!-- close main form before coupon/shipping forms -->

                    <?php
                    // Shipping method form (hidden, submitted by radio buttons)
                    if ($shipping_mode === 'woocommerce' && !empty($wc_rates_checkout)) :
                    ?>
                    <form method="post" id="maef-shipping-form-checkout" style="display:none;">
                        <input type="hidden" name="maef_shipping_method" value=""/>
                    </form>
                    <script>
                    document.querySelectorAll('input[name="maef_shipping_method"]').forEach(function(r){
                        r.addEventListener('change', function(){
                            var f = document.getElementById('maef-shipping-form-checkout');
                            f.querySelector('input[name="maef_shipping_method"]').value = this.value;
                            f.submit();
                        });
                    });
                    </script>
                    <?php endif; ?>

                    <?php
                    // Applied coupons display
                    $checkout_applied_coupons = WC()->cart->get_applied_coupons();
                    if (!empty($checkout_applied_coupons)) :
                        foreach ($checkout_applied_coupons as $c_code) :
                            $c_discount = WC()->cart->get_coupon_discount_amount($c_code);
                    ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;max-width:360px;margin-left:auto;margin-bottom:8px;padding:8px 12px;background:rgba(46,125,50,0.08);border-radius:4px;font-size:14px;">
                        <span style="color:#2e7d32;">&#10003; Coupon: <strong><?php echo esc_html(strtoupper($c_code)); ?></strong> &minus;<?php echo wc_price($c_discount); ?></span>
                        <form method="post" style="margin:0;padding:0;display:inline;">
                            <input type="hidden" name="maef_coupon_nonce" value="<?php echo wp_create_nonce('maef_coupon_action'); ?>"/>
                            <input type="hidden" name="maef_remove_coupon" value="<?php echo esc_attr($c_code); ?>"/>
                            <button type="submit" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:16px;padding:0 4px;line-height:1;" title="Remove coupon">&times;</button>
                        </form>
                    </div>
                    <?php endforeach; endif; ?>

                    <!-- Coupon input -->
                    <div class="maef-coupon-wrap" style="max-width:360px;margin-left:auto;margin-bottom:20px;display:block !important;visibility:visible !important;clear:both;">
                        <form method="post" style="display:flex !important;gap:8px;">
                            <input type="hidden" name="maef_coupon_nonce" value="<?php echo wp_create_nonce('maef_coupon_action'); ?>"/>
                            <input type="text" name="maef_coupon_code" placeholder="Coupon code" style="flex:1;padding:8px 12px;border:1px solid #ccc;border-radius:3px;font-size:14px;"/>
                            <button type="submit" name="maef_apply_coupon" value="1" style="padding:8px 16px;background:<?php echo $bg; ?>;color:<?php echo $color; ?>;border:none;cursor:pointer;border-radius:3px;font-size:14px;">Apply</button>
                        </form>
                    </div>

                    <div class="maef-btn-wrap">
                    <form method="post">
                        <input type="hidden" name="maef_start_checkout" value="1"/>
                        <?php if (!empty($this->settings['terms_enabled'])) : 
                            $terms_url  = esc_url($this->settings['terms_url'] ?? '');
                            $terms_text = esc_html($this->settings['terms_text'] ?? 'Terms and Conditions');
                        ?>
                        <div style="margin-bottom:16px;text-align:right;">
                            <label style="display:inline-flex;align-items:center;gap:8px;font-size:14px;">
                                <input type="checkbox" name="maef_accept_terms" value="1" required style="width:auto;margin:0;"/>
                                <span>I accept the <a href="<?php echo $terms_url; ?>" target="_blank" style="color:#2271b1;text-decoration:underline;"><?php echo $terms_text; ?></a></span>
                            </label>
                        </div>
                        <?php endif; ?>
                        <button type="submit" style="background:<?php echo $bg; ?>;color:<?php echo $color; ?>;display:inline-block;padding:14px 28px;font-size:1em;font-weight:600;border:none;cursor:pointer;border-radius:3px;"><?php echo $label; ?></button>
                    </div>
                </form>
            </div>
            <?php
            return ob_get_clean();
        }

        // ---------------------------------------------------------------
        // Round-robin parent selection — single source of truth
        // Validates secret BEFORE incrementing counter so a bad line
        // never consumes a counter tick and falls back to parent #1.
        // ---------------------------------------------------------------

        public function select_parent_for_checkout() {
            $parent_urls_raw = $this->settings['parent_urls'] ?? $this->settings['parent_url'] ?? '';
            $parent_lines    = array_values(array_filter(array_map('trim', explode("\n", $parent_urls_raw))));
            if (empty($parent_lines)) return null;

            $count       = count($parent_lines);
            $counter_key = 'maef_parent_rr_counter';

            // Try each parent starting from current index; skip any with missing secret
            $start_idx = intval(get_option($counter_key, 0));
            if ($start_idx >= $count) $start_idx = 0;

            for ($attempt = 0; $attempt < $count; $attempt++) {
                $try_idx  = ($start_idx + $attempt) % $count;
                $line     = $parent_lines[$try_idx];
                $parts    = explode('|', $line, 2);
                $url      = rtrim(trim($parts[0]), '/');
                $secret   = isset($parts[1]) ? preg_replace('/\s+/', '', $parts[1]) : '';

                if (empty($url) || empty($secret)) continue; // skip invalid lines

                // Valid — advance counter past this entry for next order
                update_option($counter_key, ($try_idx + 1) % $count);

                return [
                    'parent_url'    => $url,
                    'parent_secret' => $secret,
                ];
            }

            return null; // all lines invalid
        }

        // ---------------------------------------------------------------
        // Send cart to parent and redirect
        // ---------------------------------------------------------------

        public function maybe_start_checkout_process() {
            if (empty($_POST['maef_start_checkout'])) return;

            error_log('MAEF CHILD: maybe_start_checkout_process fired — cart=' . (function_exists('WC') && WC()->cart ? (WC()->cart->is_empty() ? 'empty' : 'has_items') : 'no_cart') . ' session=' . (function_exists('WC') && WC()->session ? 'ok' : 'null'));

            if (!function_exists('WC') || !is_object(WC()->cart) || !is_object(WC()->session)) {
                error_log('MAEF CHILD: WC not ready — aborting');
                wc_add_notice('Checkout is not available right now. Please try again.', 'error');
                wp_safe_redirect(wc_get_cart_url());
                exit;
            }
            if (WC()->cart->is_empty()) {
                error_log('MAEF CHILD: Cart is empty — aborting');
                return;
            }

            // Validate terms acceptance if enabled
            if (!empty($this->settings['terms_enabled'])) {
                if (empty($_POST['maef_accept_terms'])) {
                    $terms_text = esc_html($this->settings['terms_text'] ?? 'Terms and Conditions');
                    wc_add_notice('You must accept the ' . $terms_text . ' before proceeding to checkout.', 'error');
                    wp_safe_redirect(wc_get_cart_url());
                    exit;
                }
            }

            // Round-robin parent selection via shared helper
            $selected = $this->select_parent_for_checkout();
            if (!$selected) {
                error_log('MAEF: No valid Parent URL/Secret configured.');
                wc_add_notice('Secure checkout is not available right now. Please contact support.', 'error');
                wp_safe_redirect(wc_get_cart_url());
                exit;
            }
            $selected_parent = $selected['parent_url'];
            $parent_secret   = $selected['parent_secret'];

            // Double-submit guard for button mode: if an active redirect already exists
            // for this exact cart contents, reuse it instead of firing another API call.
            // This prevents duplicate parent sessions when a customer double-clicks or
            // hits the browser back button and resubmits.
            $cart_hash       = md5(json_encode(WC()->cart->get_cart_contents()) . $selected_parent);
            $guard_key       = 'maef_btn_guard_' . $cart_hash;
            $existing_redirect = WC()->session->get($guard_key);

            // Only reuse cached redirect if it points to the currently selected parent
            // (prevents sending to a removed parent site)
            if ($existing_redirect && strpos($existing_redirect, $selected_parent) !== false) {
                error_log('MAEF: Button-mode double-submit detected — reusing existing redirect.');
                wp_redirect($existing_redirect);
                exit;
            } elseif ($existing_redirect) {
                // Cached redirect points to a different/removed parent — clear it
                WC()->session->__unset($guard_key);
            }

            // Store selected parent in session so thank-you page can verify the return
            if (WC()->session) {
                WC()->session->set('maef_selected_parent', $selected_parent);
                WC()->session->set('maef_selected_parent_secret', $parent_secret);
            }

            $cart = [];
            foreach (WC()->cart->get_cart() as $item) {
                $cart[] = [
                    'product_id' => $item['data']->get_id(),
                    'quantity'   => intval($item['quantity']),
                ];
            }

            // Ensure WC customer location is set FIRST — needed for both
            // shipping zone matching and tax calculation
            if (WC()->customer) {
                $base_country = WC()->countries->get_base_country();
                $base_state   = WC()->countries->get_base_state();
                $base_zip     = WC()->countries->get_base_postcode();
                $base_city    = WC()->countries->get_base_city();
                WC()->customer->set_billing_location($base_country, $base_state, $base_zip, $base_city);
                WC()->customer->set_shipping_location($base_country, $base_state, $base_zip, $base_city);
            }

            // Calculate totals FIRST before reading any cart values
            // Some setups (page builders, cached sessions) return 0 if totals haven't been calculated
            WC()->cart->calculate_totals();

            $subtotal       = round(floatval(WC()->cart->get_subtotal()), 2);
            $customer_country = WC()->customer ? WC()->customer->get_shipping_country() : 'US';
            if (empty($customer_country)) $customer_country = 'US';
            $shipping_label = sanitize_text_field($this->settings['shipping_label'] ?? 'Shipping');

            // Get shipping cost — for WC native mode, read from session (saved on cart page)
            if (($this->settings['shipping_mode'] ?? 'flat') === 'woocommerce' && WC()->session) {
                $shipping_cost = round(floatval(WC()->session->get('maef_selected_shipping_cost', 0)), 2);
                $session_label = WC()->session->get('maef_selected_shipping_label', '');
                if (!empty($session_label)) $shipping_label = $session_label;

                // Check if coupon grants free shipping
                foreach (WC()->cart->get_applied_coupons() as $code) {
                    $coupon = new WC_Coupon($code);
                    if ($coupon->get_free_shipping()) {
                        $shipping_cost = 0.0;
                        break;
                    }
                }
            } else {
                $shipping_cost = $this->get_shipping_cost($subtotal, $customer_country);
            }

            $tax_total      = round(floatval(WC()->cart->get_total_tax()), 2);
            $discount_total = round(floatval(WC()->cart->get_discount_total()), 2);
            $grand_total    = round($subtotal - $discount_total + $shipping_cost + $tax_total, 2);

            // Get applied coupon codes
            $applied_coupons = WC()->cart->get_applied_coupons();

            // Get affiliate ID from cookie
            $affiliate_id   = $this->get_affiliate_id();

            $address_data = $this->get_customer_address_payload();
            $payload = array_merge([
                'cart'           => $cart,
                'total'          => $grand_total,
                'subtotal'       => $subtotal,
                'discount_total' => $discount_total,
                'shipping_cost'  => $shipping_cost,
                'shipping_label' => $shipping_label,
                'tax_total'      => $tax_total,
                'coupon_codes'   => $applied_coupons,
                'affiliate_id'   => $affiliate_id,
                'child_domain'   => home_url(),
                'timestamp'      => time(),
            ], $address_data);

            error_log('MAEF Checkout: shipping_mode=' . ($this->settings['shipping_mode'] ?? 'flat') . ' shipping_cost=' . $shipping_cost . ' shipping_label=' . $shipping_label . ' subtotal=' . $subtotal . ' tax=' . $tax_total . ' discount=' . $discount_total . ' total=' . $grand_total);

            $json      = json_encode($payload);
            $signature = hash_hmac('sha256', $json, $parent_secret);

            $response = wp_remote_post(
                $selected_parent . '/wp-json/maef/v1/create-order-session',
                [
                    'headers'   => ['Content-Type' => 'application/json', 'x-maef-signature' => $signature],
                    'body'      => $json,
                    'timeout'   => 20,
                    'sslverify' => false,
                ]
            );

            if (is_wp_error($response)) {
                error_log('MAEF: Parent connection error — ' . $response->get_error_message() . ' | Parent: ' . $selected_parent);
                wc_add_notice('Could not connect to secure checkout. Please try again or contact support.', 'error');
                wp_safe_redirect(wc_get_cart_url());
                exit;
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $raw       = wp_remote_retrieve_body($response);
            $data      = json_decode($raw, true);

            if ($http_code !== 200 || empty($data['success']) || empty($data['redirect_url'])) {
                error_log('MAEF: Invalid parent response (HTTP ' . $http_code . ') from ' . $selected_parent . ' — ' . $raw);
                wc_add_notice('Secure checkout is temporarily unavailable. Please try again.', 'error');
                wp_safe_redirect(wc_get_cart_url());
                exit;
            }

            // Store the guard key so any double-click/back-button resubmit reuses this URL.
            // Cleared automatically when the return handler fires (checkout complete).
            WC()->session->set($guard_key, $data['redirect_url']);

            wp_redirect($data['redirect_url']);
            exit;
        }

        // ---------------------------------------------------------------
        // Handle return from parent after payment
        // ---------------------------------------------------------------

        public function maybe_handle_parent_return() {
            if (empty($_GET['maef_complete']) || empty($_GET['sig'])) return;

            $payload_b64  = urldecode($_GET['maef_complete']);
            $payload_json = base64_decode($payload_b64);
            $signature    = $_GET['sig'];

            // Build ordered list of secrets to try:
            // 1. Secret for the specific parent that sent this return (from maef_parent URL param)
            // 2. Secret stored in WC session (set at checkout time)
            // 3. All secrets from parent_urls config (handles any session drop scenario)
            $secrets_to_try = [];

            $parent_urls_raw = $this->settings['parent_urls'] ?? $this->settings['parent_url'] ?? '';
            $parent_lines    = array_filter(array_map('trim', explode("\n", $parent_urls_raw)));

            // Try to match the returning parent URL to a specific configured secret
            if (!empty($_GET['maef_parent'])) {
                $returning_parent = rtrim(urldecode($_GET['maef_parent']), '/');
                foreach ($parent_lines as $line) {
                    $parts = explode('|', $line, 2);
                    $configured_url = rtrim(trim($parts[0]), '/');
                    if ($configured_url === $returning_parent && isset($parts[1]) && !empty(trim($parts[1]))) {
                        array_unshift($secrets_to_try, preg_replace('/\s+/', '', $parts[1]));
                        break;
                    }
                }
            }

            // Add session secret
            $session_secret = WC()->session ? WC()->session->get('maef_selected_parent_secret') : '';
            if ($session_secret && !in_array($session_secret, $secrets_to_try)) {
                $secrets_to_try[] = $session_secret;
            }

            // Add all remaining configured secrets as fallback
            foreach ($parent_lines as $line) {
                $parts = explode('|', $line, 2);
                if (isset($parts[1]) && !empty(trim($parts[1]))) {
                    $s = preg_replace('/\s+/', '', $parts[1]);
                    if (!in_array($s, $secrets_to_try)) $secrets_to_try[] = $s;
                }
            }

            // Legacy single-secret fallback
            if (!empty($this->settings['parent_secret']) && !in_array($this->settings['parent_secret'], $secrets_to_try)) {
                $secrets_to_try[] = $this->settings['parent_secret'];
            }

            if (empty($secrets_to_try)) {
                error_log('MAEF: Security Error — no Parent Secret Key configured.');
                wc_add_notice('Secure checkout could not be verified. Please contact support.', 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }

            // Try each secret — correct one will match
            $parent_secret = null;
            foreach ($secrets_to_try as $candidate) {
                if (hash_equals(hash_hmac('sha256', $payload_json, $candidate), $signature)) {
                    $parent_secret = $candidate;
                    break;
                }
            }

            if (!$parent_secret) {
                error_log('MAEF: Security Error — invalid return signature. Possible secret key mismatch or tampered payload.');
                wc_add_notice('Payment verification failed. Please try again or contact support.', 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }
            $data = json_decode($payload_json, true);
            if (empty($data['original_payload']['cart']) || empty($data['timestamp']) || !isset($data['billing_details'])) {
                error_log('MAEF: Invalid payload received — missing cart, timestamp, or billing_details.');
                wc_add_notice('Your checkout session was incomplete. Please try again.', 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }

            // Reject expired links (15 minutes)
            if (abs(time() - $data['timestamp']) > 900) {
                error_log('MAEF: Expired return link — timestamp diff: ' . abs(time() - $data['timestamp']) . 's');
                wc_add_notice('Your checkout session has expired. Please try again.', 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }
            
            // Duplicate detection: Prevent page refresh from creating multiple orders.
            // The replay key is based on the exact payload signature — expires after 30 minutes.
            $replay_key = 'maef_replay_' . hash('sha256', $payload_b64);
            $replay_data = get_transient($replay_key);
            if ($replay_data) {
                // Already processed — redirect to the correct order-received page, not a generic URL.
                $existing_order_id  = is_array($replay_data) ? ($replay_data['order_id'] ?? 0) : 0;
                $existing_order     = $existing_order_id ? wc_get_order($existing_order_id) : null;
                $replay_redirect    = $existing_order
                    ? $existing_order->get_checkout_order_received_url()
                    : wc_get_page_permalink('shop');
                error_log('MAEF: Replay detected — redirecting to order-received for order #' . $existing_order_id);
                wp_safe_redirect($replay_redirect);
                exit;
            }

            // Mark this transaction as processed BEFORE creating the order (prevent race conditions).
            // We'll update the transient with the real order ID once we have it.
            set_transient($replay_key, ['order_id' => 0], 1800);
            
            // If this was a gateway mode checkout, a pending order already exists on the child.
            // Update it to processing instead of creating a duplicate new order.
            // Create the order (this method has its own parent_order_id duplicate check)
            $order_id = $this->create_local_order($data);
            if (!$order_id) {
                error_log('MAEF: create_local_order() returned falsy — order could not be created.');
                wc_add_notice('There was a problem recording your order. Please contact support with your payment reference.', 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }

            // Now that we have a real order ID, update the replay transient so a
            // page refresh sends the customer to the correct order-received URL.
            set_transient($replay_key, ['order_id' => $order_id], 1800);

            // Auto-login the customer so the order immediately appears in My Account.
            // create_local_order() links the order to a WC customer account (creating one
            // if needed), but the customer is still a guest at this point. Without logging
            // them in, they would land on the thank-you page and see nothing in My Account.
            if (!is_user_logged_in()) {
                $completed_order_obj = wc_get_order($order_id);
                $customer_id_for_login = $completed_order_obj ? $completed_order_obj->get_customer_id() : 0;
                if ($customer_id_for_login) {
                    wp_set_current_user($customer_id_for_login);
                    wp_set_auth_cookie($customer_id_for_login, true);
                    error_log("MAEF: Auto-logged in customer #{$customer_id_for_login} after order #{$order_id}");
                }
            }

            if (function_exists('WC') && WC()->session) {
                if (!WC()->session->has_session()) WC()->session->set_customer_session_cookie(true);
                WC()->session->set('maef_last_order_id', $order_id);
                // Clear the double-submit guard keys now that checkout is complete
                WC()->session->__unset('maef_active_child_order_id');
                WC()->session->__unset('maef_active_redirect_url');
                // Clear all button-mode double-submit guard keys for this session
                foreach (['maef_btn_guard_'] as $prefix) {
                    $all_session = WC()->session->get_session_data();
                    if (is_array($all_session)) {
                        foreach (array_keys($all_session) as $skey) {
                            if (strpos($skey, $prefix) === 0) WC()->session->__unset($skey);
                        }
                    }
                }
                WC()->session->save_data();
            }
            if (WC()->cart) WC()->cart->empty_cart();

            // Clear affiliate cookie after successful order
            if (isset($_COOKIE['maef_affiliate_id'])) {
                setcookie('maef_affiliate_id', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            }

            // Redirect to WooCommerce native order-received page — no sessions, no custom slugs.
            // This is the standard Stripe-style flow: /checkout/order-received/{id}/?key={key}
            // Works with FunnelKit, CartFlows, and any WC-compatible thank-you page override.
            $completed_order = wc_get_order($order_id);
            $final_redirect  = $completed_order
                ? $completed_order->get_checkout_order_received_url()
                : wc_get_page_permalink('shop');

            wp_safe_redirect($final_redirect);
            exit;
        }

        // ---------------------------------------------------------------
        // Create local order on child after payment confirmed
        // ---------------------------------------------------------------

        private function create_local_order($data) {
            $parent_order_id  = intval($data['parent_order_id'] ?? 0);
            if (!$parent_order_id) {
                error_log('MAEF: create_local_order called without parent_order_id');
                return false;
            }

            // Check if this parent order has already been processed
            $existing = wc_get_orders([
                'limit'      => 1,
                'meta_key'   => '_maef_parent_order_id',
                'meta_value' => $parent_order_id,
                'return'     => 'ids',
            ]);
            
            if (!empty($existing)) {
                // CRITICAL: verify billing email matches before returning existing order
                // Prevents cross-session contamination where Customer B sees Customer A's order
                $existing_order  = wc_get_order($existing[0]);
                $billing_email   = sanitize_email($data['billing_details']['email'] ?? '');
                $existing_email  = $existing_order ? $existing_order->get_billing_email() : '';
                if ($existing_order && $billing_email && $existing_email && 
                    strtolower($billing_email) === strtolower($existing_email)) {
                    error_log("MAEF: Parent order #{$parent_order_id} already processed as child order #{$existing[0]} — same customer, reusing.");
                    return $existing[0];
                } elseif ($existing_order && empty($billing_email)) {
                    // No email to compare — safe to reuse
                    return $existing[0];
                } else {
                    // Different customer — do NOT reuse, create new order
                    error_log("MAEF: Parent order #{$parent_order_id} exists but email mismatch ({$billing_email} vs {$existing_email}) — creating new order.");
                }
            }
            
            $cart_data        = $data['original_payload']['cart'];
            $billing_details  = $data['billing_details'];
            $shipping_details = $data['shipping_details'] ?? [];
            $shipping_cost    = isset($data['shipping_cost'])  ? round(floatval($data['shipping_cost']), 2)   : 0.0;
            $shipping_label   = isset($data['shipping_label']) ? sanitize_text_field($data['shipping_label']) : 'Shipping';
            $paid_total       = floatval($data['original_payload']['total']);

            // Link order to customer account by billing email so it appears in My Account
            $customer_id   = 0;
            $billing_email = sanitize_email($billing_details['email'] ?? '');
            if ($billing_email) {
                $user = get_user_by('email', $billing_email);
                if ($user) {
                    $customer_id = $user->ID;
                    error_log("MAEF: Found existing WC customer #{$customer_id} for {$billing_email}");
                } else {
                    // No WP user at all — create a full WC account
                    $username     = wc_create_new_customer_username($billing_email, [
                        'first_name' => $billing_details['first_name'] ?? '',
                        'last_name'  => $billing_details['last_name']  ?? '',
                    ]);
                    $new_customer = wc_create_new_customer($billing_email, $username, wp_generate_password(), [
                        'first_name' => $billing_details['first_name'] ?? '',
                        'last_name'  => $billing_details['last_name']  ?? '',
                    ]);
                    if (!is_wp_error($new_customer)) {
                        $customer_id = $new_customer;
                        error_log("MAEF: Created new WC customer #{$customer_id} for {$billing_email}");
                    } else {
                        error_log("MAEF: wc_create_new_customer failed for {$billing_email} — " . $new_customer->get_error_message());
                        // Last resort: look up by email again in case of race condition
                        $retry = get_user_by('email', $billing_email);
                        if ($retry) $customer_id = $retry->ID;
                    }
                }
            }

            // In gateway mode, wc_order_id is the pending order already created on this child.
            // Reuse it instead of creating a duplicate new order.
            $existing_order_id = intval($data['original_payload']['wc_order_id'] ?? 0);
            $is_reused_order   = false;
            if ($existing_order_id) {
                $order = wc_get_order($existing_order_id);
                // Verify it's a real MAEF gateway order belonging to this session
                // Accept any status — custom order status plugins may have changed it from on-hold
                if (
                    $order instanceof WC_Order &&
                    $order->get_payment_method() === 'maef_child_gateway' &&
                    !$order->get_meta('_maef_parent_order_id') // not already completed
                ) {
                    error_log("MAEF: Reusing existing order #{$existing_order_id} (status: {$order->get_status()}) for parent order #{$parent_order_id}");
                    $is_reused_order = true;
                    if ($customer_id && !$order->get_customer_id()) {
                        $order->set_customer_id($customer_id);
                    }
                } else {
                    $order = null;
                }
            } else {
                $order = null;
            }

            if (!$order) {
                $order = wc_create_order(['customer_id' => $customer_id]);
                if (!$order) {
                    error_log('MAEF: wc_create_order() failed');
                    return false;
                }
                // Explicitly set again — some WC versions reset it during wc_create_order
                if ($customer_id) {
                    $order->set_customer_id($customer_id);
                }
            }

            // Store parent order ID as meta for duplicate detection
            $order->update_meta_data('_maef_parent_order_id', $parent_order_id);

            // Store affiliate ID if present
            if (!empty($data['original_payload']['affiliate_id'])) {
                $order->update_meta_data('_maef_affiliate_id', sanitize_text_field($data['original_payload']['affiliate_id']));
            }

            // Store coupon codes if present
            if (!empty($data['original_payload']['coupon_codes'])) {
                $order->update_meta_data('_maef_coupon_codes', $data['original_payload']['coupon_codes']);
            }

            $order->set_address($billing_details, 'billing');
            if (!empty($shipping_details) && !empty($shipping_details['first_name'])) {
                $order->set_address($shipping_details, 'shipping');
            }

            $raw_subtotal = 0;
            if (!$is_reused_order) {
                // Only add line items for newly created orders — reused orders already have them
                foreach ($cart_data as $item) {
                    $product = wc_get_product($item['product_id']);
                    if ($product) {
                        $item_id = $order->add_product($product, $item['quantity']);
                        $raw_subtotal += floatval($product->get_price()) * intval($item['quantity']);
                        $order_item = $order->get_item($item_id);
                        if ($order_item) {
                            $order_item->set_taxes(['total' => [0], 'subtotal' => [0]]);
                            $order_item->save();
                        }
                    } else {
                        error_log("MAEF: Product ID {$item['product_id']} not found on child site");
                    }
                }
            } else {
                // Calculate raw_subtotal from existing items for discount reconciliation
                foreach ($order->get_items() as $item) {
                    $raw_subtotal += floatval($item->get_subtotal());
                }
            }

            if (!$is_reused_order) {
            if ($shipping_cost > 0.0) {
                $shipping_fee = new WC_Order_Item_Fee();
                $shipping_fee->set_name($shipping_label);
                $shipping_fee->set_amount($shipping_cost);
                $shipping_fee->set_total($shipping_cost);
                $shipping_fee->set_tax_status('none');
                $order->add_item($shipping_fee);
            }

            // Add tax as a fee line if present
            $tax_total = isset($data['original_payload']['tax_total']) ? round(floatval($data['original_payload']['tax_total']), 2) : 0.0;
            if ($tax_total > 0.0) {
                $tax_fee = new WC_Order_Item_Fee();
                $tax_fee->set_name('Sales Tax');
                $tax_fee->set_amount($tax_total);
                $tax_fee->set_total($tax_total);
                $tax_fee->set_tax_status('none');
                $order->add_item($tax_fee);
            }

            // Reconcile: compare expected vs paid (include shipping + tax)
            $expected_total  = round($raw_subtotal + $shipping_cost + $tax_total, 2);
            $payload_discount = isset($data['original_payload']['discount_total']) ? round(floatval($data['original_payload']['discount_total']), 2) : 0.0;
            $discount_amount = $payload_discount > 0 ? $payload_discount : round($expected_total - $paid_total, 2);
            if (abs($discount_amount) > 0.01) {
                // Apply actual coupon codes if available, otherwise use a generic discount line
                $coupon_codes = $data['original_payload']['coupon_codes'] ?? [];
                if (!empty($coupon_codes)) {
                    $per_coupon = round($discount_amount / count($coupon_codes), 2);
                    foreach ($coupon_codes as $i => $code) {
                        $coupon_item = new WC_Order_Item_Coupon();
                        $coupon_item->set_code($code);
                        if ($i === count($coupon_codes) - 1) {
                            $coupon_item->set_discount($discount_amount - ($per_coupon * $i));
                        } else {
                            $coupon_item->set_discount($per_coupon);
                        }
                        $coupon_item->set_discount_tax(0);
                        $order->add_item($coupon_item);
                    }
                } else {
                    $fee = new WC_Order_Item_Fee();
                    $fee->set_name('Discount');
                    $fee->set_amount(-$discount_amount);
                    $fee->set_total(-$discount_amount);
                    $fee->set_tax_status('none');
                    $order->add_item($fee);
                }

                // Also reduce line item totals proportionally so invoice shows discounted prices
                $order_items = $order->get_items();
                if (!empty($order_items) && $raw_subtotal > 0) {
                    $discount_ratio = $discount_amount / $raw_subtotal;
                    foreach ($order_items as $item) {
                        if (!is_a($item, 'WC_Order_Item_Product')) continue;
                        $original_total = floatval($item->get_total());
                        $item_discount = round($original_total * $discount_ratio, 2);
                        $item->set_subtotal($original_total); // Keep original as subtotal (shows strikethrough)
                        $item->set_total($original_total - $item_discount); // Discounted total
                        $item->save();
                    }
                }
            }

            } // end if (!$is_reused_order) — skip re-adding fees/discounts for reused orders

            $order->add_order_note(sprintf(
                'Payment of %s processed on parent site. Parent Order ID: %d.',
                wc_price($paid_total),
                $parent_order_id
            ));

            // Disable WooCommerce tax calculation for this order — tax is already
            // included as a fee from the parent site. Without this, WC would add
            // its own tax on top, causing double taxation.
            add_filter('woocommerce_order_item_needs_processing', '__return_false');
            $order->calculate_totals(false); // false = skip tax recalculation

            // Remove any WC-generated tax lines — tax is shown via our "Sales Tax" fee
            foreach ($order->get_items('tax') as $tax_item_id => $tax_item) {
                $order->remove_item($tax_item_id);
            }

            // Force the order total to match what was actually paid.
            // Also set discount total explicitly so email receipt shows it correctly.
            $order->set_total($paid_total);
            if ($discount_amount > 0.01) {
                $order->set_discount_total($discount_amount);
                $order->set_discount_tax(0);
            }

            // Re-assert customer_id one final time before saving.
            // calculate_totals() and update_status() can both trigger WC internals
            // that reset the customer_id back to 0 in some configurations.
            if ($customer_id && !$order->get_customer_id()) {
                $order->set_customer_id($customer_id);
            }

            $order->update_status('processing', 'Order created via MAEF parent site.', true);
            $order->save();

            // If this is a newly created customer, retroactively link any previous
            // guest orders with the same email to their new account.
            if ($customer_id && $billing_email) {
                wc_update_new_customer_past_orders($customer_id);
            }

            error_log("MAEF: Successfully created child order #{$order->get_id()} from parent order #{$parent_order_id} — customer_id={$customer_id}");

            return $order->get_id();
        }

        // ---------------------------------------------------------------
        // [maef_order_summary] shortcode
        // ---------------------------------------------------------------

        // ---------------------------------------------------------------
        // Shared payload builder — used by both button checkout and gateway mode
        // ---------------------------------------------------------------
        /**
         * Build payload from a WC_Order — used by gateway mode process_payment.
         * Block checkout empties the cart before process_payment fires, so we
         * reconstruct everything from the order object instead of WC()->cart.
         */
        public function build_maef_payload_from_order($order) {
            $selected = $this->select_parent_for_checkout();
            if (!$selected) return null;
            $selected_parent = $selected['parent_url'];
            $parent_secret   = $selected['parent_secret'];

            $cart = [];
            foreach ($order->get_items() as $item) {
                $cart[] = [
                    'product_id' => $item->get_product_id(),
                    'quantity'   => $item->get_quantity(),
                ];
            }

            $subtotal       = floatval($order->get_subtotal());
            $shipping_cost  = floatval($order->get_shipping_total());
            $shipping_label = sanitize_text_field($this->settings['shipping_label'] ?? 'Shipping');
            $discount_total = floatval($order->get_discount_total());
            $tax_total      = floatval($order->get_total_tax());
            $grand_total    = floatval($order->get_total());
            $applied_coupons = $order->get_coupon_codes();
            $affiliate_id   = $this->get_affiliate_id();
            $thank_you_path = sanitize_text_field($this->settings['thank_you_path'] ?? $this->settings['thank_you'] ?? '/thank-you');
            $return_url     = home_url($thank_you_path);

            $payload = [
                'cart'           => $cart,
                'subtotal'       => $subtotal,
                'shipping_cost'  => $shipping_cost,
                'shipping_label' => $shipping_label,
                'discount_total' => $discount_total,
                'tax_total'      => $tax_total,
                'total'          => $grand_total,
                'return_url'     => $return_url,
                'coupon_codes'   => $applied_coupons,
                'affiliate_id'   => $affiliate_id,
                'child_domain'   => home_url(),
                'timestamp'      => time(),
                'wc_order_id'    => $order->get_id(),
            ];

            return [
                'parent_url'    => $selected_parent,
                'parent_secret' => $parent_secret,
                'payload'       => $payload,
            ];
        }

        public function build_maef_payload() {
            if (!function_exists('WC') || !is_object(WC()->cart) || WC()->cart->is_empty()) return null;

            $selected = $this->select_parent_for_checkout();
            if (!$selected) return null;
            $selected_parent = $selected['parent_url'];
            $parent_secret   = $selected['parent_secret'];

            if (WC()->session) {
                WC()->session->set('maef_selected_parent', $selected_parent);
                WC()->session->set('maef_selected_parent_secret', $parent_secret);
            }

            $cart = [];
            foreach (WC()->cart->get_cart() as $item) {
                $cart[] = [
                    'product_id' => $item['data']->get_id(),
                    'quantity'   => intval($item['quantity']),
                ];
            }

            if (WC()->customer) {
                $base_country = WC()->countries->get_base_country();
                $base_state   = WC()->countries->get_base_state();
                $base_zip     = WC()->countries->get_base_postcode();
                $base_city    = WC()->countries->get_base_city();
                WC()->customer->set_billing_location($base_country, $base_state, $base_zip, $base_city);
                WC()->customer->set_shipping_location($base_country, $base_state, $base_zip, $base_city);
            }

            $subtotal         = floatval(WC()->cart->get_subtotal());
            $customer_country = WC()->customer ? WC()->customer->get_shipping_country() : 'US';
            if (empty($customer_country)) $customer_country = 'US';
            $shipping_label   = sanitize_text_field($this->settings['shipping_label'] ?? 'Shipping');

            if (($this->settings['shipping_mode'] ?? 'flat') === 'woocommerce' && WC()->session) {
                $shipping_cost = round(floatval(WC()->session->get('maef_selected_shipping_cost', 0)), 2);
                $session_label = WC()->session->get('maef_selected_shipping_label', '');
                if (!empty($session_label)) $shipping_label = $session_label;
                foreach (WC()->cart->get_applied_coupons() as $code) {
                    $coupon = new WC_Coupon($code);
                    if ($coupon->get_free_shipping()) { $shipping_cost = 0.0; break; }
                }
            } else {
                $shipping_cost = $this->get_shipping_cost($subtotal, $customer_country);
            }

            $discount_total  = floatval(WC()->cart->get_discount_total());
            $tax_total       = floatval(WC()->cart->get_total_tax());
            $grand_total     = round($subtotal + $shipping_cost + $tax_total - $discount_total, 2);
            $applied_coupons = WC()->cart->get_applied_coupons();
            $affiliate_id    = $this->get_affiliate_id();
            $thank_you_path  = sanitize_text_field($this->settings['thank_you_path'] ?? '/thank-you');
            $return_url      = home_url($thank_you_path);

            $address_data = $this->get_customer_address_payload();
            $payload = array_merge([
                'cart'           => $cart,
                'subtotal'       => $subtotal,
                'shipping_cost'  => $shipping_cost,
                'shipping_label' => $shipping_label,
                'discount_total' => $discount_total,
                'tax_total'      => $tax_total,
                'total'          => $grand_total,
                'return_url'     => $return_url,
                'coupon_codes'   => $applied_coupons,
                'affiliate_id'   => $affiliate_id,
                'child_domain'   => home_url(),
                'timestamp'      => time(),
            ], $address_data);

            return [
                'parent_url'    => $selected_parent,
                'parent_secret' => $parent_secret,
                'payload'       => $payload,
            ];
        }

        // ---------------------------------------------------------------
        // Register MAEF as a WooCommerce payment gateway (gateway mode)
        // ---------------------------------------------------------------
        public function register_maef_gateway($gateways) {
            if (!empty($this->settings['gateway_mode']) && class_exists('MAEF_Child_Payment_Gateway')) {
                // Put MAEF first in the list
                array_unshift($gateways, 'MAEF_Child_Payment_Gateway');
            }
            return $gateways;
        }

        /**
         * Gateway mode: exclude pending MAEF orders from My Account order list query.
         * Customers only see orders once they reach processing/completed.
         */
        /**
         * REMOVED in v12.26.8.
         * These two methods previously hid pending/on-hold MAEF orders from the
         * My Account page. That caused customers to think their order was lost and
         * place duplicate orders — especially when the parent-to-child confirmation
         * signal was delayed or blocked by a firewall. Orders are now always visible.
         *
         * public function hide_onhold_from_my_account($args) { ... }
         * public function hide_onhold_order_visibility($visible, $order) { ... }
         */

        public function add_cron_interval($schedules) {
            $schedules['maef_fifteen_minutes'] = [
                'interval' => 900, // 15 minutes in seconds
                'display'  => __('Every 15 Minutes (MAEF)'),
            ];
            return $schedules;
        }

        /**
         * Suppress the customer pending email and admin new-order email for MAEF
         * gateway orders. The child sets orders to pending while the customer
         * completes payment on the parent site. Emails should only fire
         * after the order moves to processing (payment confirmed).
         */
        // ── FunnelKit compatibility methods ───────────────────────────────

        /**
         * After FunnelKit modifies the payment result redirect, restore our
         * MAEF redirect URL so the customer goes to the parent site, not a funnel page.
         */
        public function funnelkit_restore_maef_redirect($result, $order_id_or_order) {
            // woocommerce_payment_successful_result passes order_id (int)
            // wfacp_payment_successful_result may pass WC_Order object — handle both
            if (is_object($order_id_or_order) && method_exists($order_id_or_order, 'get_payment_method')) {
                $order = $order_id_or_order;
            } else {
                $order = wc_get_order($order_id_or_order);
            }

            if (!$order || $order->get_payment_method() !== 'maef_child_gateway') return $result;

            // Try session first, then fall back to order meta (FunnelKit can drop session)
            $maef_redirect = '';
            if (function_exists('WC') && WC()->session) {
                $maef_redirect = WC()->session->get('maef_active_redirect_url');
            }
            if (empty($maef_redirect)) {
                $maef_redirect = $order->get_meta('_maef_redirect_url');
            }
            if (empty($maef_redirect)) return $result;

            error_log('MAEF: FunnelKit compat — restoring MAEF redirect for order #' . $order->get_id());
            $result['redirect'] = $maef_redirect;
            return $result;
        }

        /**
         * Tell FunnelKit that a MAEF pending order is NOT a failed order.
         * Without this FunnelKit may show an error on its checkout page.
         */
        public function funnelkit_maef_not_failed($is_failed, $order) {
            if (!$order) return $is_failed;
            if ($order->get_payment_method() === 'maef_child_gateway') return false;
            return $is_failed;
        }

        /**
         * Allow 'pending' status for MAEF orders when FunnelKit checks if
         * an order can still be paid — prevents "order already processed" errors.
         */
        public function funnelkit_allow_pending_maef($statuses, $order) {
            if (!$order) return $statuses;
            if ($order->get_payment_method() === 'maef_child_gateway') {
                if (!in_array('pending', $statuses)) $statuses[] = 'pending';
            }
            return $statuses;
        }

        public function suppress_maef_onhold_email($enabled, $order) {
            if (!$order || !is_a($order, 'WC_Order')) return $enabled;
            if ($order->get_payment_method() === 'maef_child_gateway') {
                return false;
            }
            return $enabled;
        }

        public function cancel_abandoned_onhold_orders() {
            $cutoff    = strtotime('-15 minutes');
            $base_args = [
                'status'      => 'pending',
                'date_before' => date('Y-m-d H:i:s', $cutoff),
                'limit'       => 50,
                'return'      => 'objects',
            ];

            // Query both payment method slugs: legacy gateway slug and the button-mode
            // slug added in v12.26.6 so all orders appear in My Account.
            $orders = array_merge(
                wc_get_orders(array_merge($base_args, ['payment_method' => 'maef_child_gateway'])),
                wc_get_orders(array_merge($base_args, ['payment_method' => 'maef_secure_checkout']))
            );

            foreach ($orders as $order) {
                // Only cancel if it was never handed off to the parent (no parent order ID assigned).
                if (!$order->get_meta('_maef_parent_order_id')) {
                    $order->update_status('cancelled', 'MAEF: Auto-cancelled after 15 minutes of inactivity.');
                    error_log('MAEF: Auto-cancelled abandoned pending order #' . $order->get_id());
                }
            }
        }

        public function render_order_summary() {
            if (!function_exists('WC')) return '';

            // Prefer URL params (set by maybe_handle_parent_return redirect) — these
            // survive across the redirect chain even when WC session drops or overlaps.
            $order_id  = 0;
            $order_key = '';

            if (!empty($_GET['maef_order']) && !empty($_GET['order_key'])) {
                $order_id  = intval($_GET['maef_order']);
                $order_key = sanitize_text_field($_GET['order_key']);
                $order     = wc_get_order($order_id);
                // Verify the key so a customer can't enumerate other orders
                if (!$order || !hash_equals($order->get_order_key(), $order_key)) {
                    return '<p>Order not found. Please check your account.</p>';
                }
            } else {
                // Fallback: session-based (button-checkout mode or session still intact)
                if (!WC()->session) return '';
                $order_id = WC()->session->get('maef_last_order_id');
                if (!$order_id) return '<!-- MAEF: No order ID found in session. -->';
                WC()->session->__unset('maef_last_order_id');
                $order = wc_get_order($order_id);
                if (!$order) return '<p>Order not found. Please check your account.</p>';
            }

            ob_start();
            do_action('woocommerce_thankyou', $order->get_id());
            return ob_get_clean();
        }

    } // end class

    global $maef_child_instance;
    $maef_child_instance = new MAEF_Child_Final();
}

// MAEF Payment Gateway — works with classic checkout, blocks, Divi, Elementor, and any page builder
// IMPORTANT: Must use plugins_loaded (not woocommerce_init) so blocks registration fires in time.
// woocommerce_blocks_loaded fires BEFORE woocommerce_init — class must exist by then.
add_action('plugins_loaded', function() {
    if (!class_exists('WC_Payment_Gateway')) return;
    if (class_exists('MAEF_Child_Payment_Gateway')) return;

    class MAEF_Child_Payment_Gateway extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = 'maef_child_gateway';
            $this->method_title       = 'MAEF Secure Checkout';
            $this->method_description = 'Send customers to the MAEF parent site for secure payment via Stripe.';
            $this->has_fields         = false;

            // Tell WooCommerce Blocks this gateway supports the block checkout
            $this->supports = ['products', 'refunds'];

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title');
            $this->description = $this->get_option('description');


            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);


        }

        public function register_block_support() {
            if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) return;
            require_once plugin_dir_path(__FILE__) . 'class-maef-gateway-blocks.php';
            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function( $payment_method_registry ) {
                    $payment_method_registry->register( new MAEF_Gateway_Blocks_Support() );
                }
            );
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title'       => 'Enable/Disable',
                    'type'        => 'checkbox',
                    'label'       => 'Enable MAEF Secure Checkout as a payment option',
                    'default'     => 'yes',
                ],
                'title' => [
                    'title'       => 'Title',
                    'type'        => 'text',
                    'default'     => 'Secure Checkout (Card / Apple Pay / Google Pay)',
                    'desc_tip'    => true,
                    'description' => 'Label shown to customer in the payment method list.',
                ],
                'description' => [
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'default'     => 'Pay securely with credit/debit card, Apple Pay, or Google Pay.',
                    'description' => 'Shown under the payment option.',
                ],
            ];
        }

        public function process_payment($order_id) {
            global $maef_child_instance;

            if (!$maef_child_instance) {
                wc_add_notice('MAEF Secure Checkout is not configured properly.', 'error');
                return ['result' => 'failure'];
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice('Order not found.', 'error');
                return ['result' => 'failure'];
            }

            // Double-submit guard — if this session already has an active MAEF checkout
            // for this exact order, return the same redirect instead of creating a second one
            if (function_exists('WC') && WC()->session) {
                $active_order_id  = WC()->session->get('maef_active_child_order_id');
                $active_redirect  = WC()->session->get('maef_active_redirect_url');
                if ($active_order_id && $active_order_id === $order_id && $active_redirect) {
                    error_log("MAEF: Double-submit detected for order #{$order_id} — reusing existing redirect");
                    return ['result' => 'success', 'redirect' => $active_redirect];
                }
            }

            $built = $maef_child_instance->build_maef_payload_from_order($order);
            if (!$built) {
                wc_add_notice('Unable to connect to secure checkout. Please try another payment method.', 'error');
                return ['result' => 'failure'];
            }

            $address_data = $maef_child_instance->get_customer_address_payload($order);
            $built['payload'] = array_merge($built['payload'], $address_data);

            $json      = json_encode($built['payload']);
            $signature = hash_hmac('sha256', $json, $built['parent_secret']);

            $response = wp_remote_post(
                $built['parent_url'] . '/wp-json/maef/v1/create-order-session',
                [
                    'headers'   => ['Content-Type' => 'application/json', 'x-maef-signature' => $signature],
                    'body'      => $json,
                    'timeout'   => 20,
                    'sslverify' => false,
                ]
            );

            if (is_wp_error($response)) {
                $order->update_status('failed', 'MAEF connection error: ' . $response->get_error_message());
                wc_add_notice('Connection error: ' . esc_html($response->get_error_message()), 'error');
                return ['result' => 'failure'];
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $data      = json_decode(wp_remote_retrieve_body($response), true);

            if ($http_code !== 200 || empty($data['success']) || empty($data['redirect_url'])) {
                $order->update_status('failed', 'MAEF parent rejected session request.');
                wc_add_notice('Secure checkout unavailable. Please try another payment method.', 'error');
                return ['result' => 'failure'];
            }

            $order->update_status('pending', 'Customer redirected to MAEF Secure Checkout.');

            // Store redirect URL in both session AND order meta
            // Session is used by the double-submit guard; order meta is the fallback
            // for FunnelKit which can drop the session between process_payment and the filter
            $order->update_meta_data('_maef_redirect_url', $data['redirect_url']);
            $order->save();

            if (function_exists('WC') && WC()->session) {
                WC()->session->set('maef_active_child_order_id', $order_id);
                WC()->session->set('maef_active_redirect_url', $data['redirect_url']);
            }

            return [
                'result'   => 'success',
                'redirect' => $data['redirect_url'],
            ];
        }
    }
});

// Register blocks support for MAEF gateway — must be at top level, NOT inside woocommerce_init
// woocommerce_blocks_loaded fires before woocommerce_init, so class is ready by plugins_loaded.
add_action('woocommerce_blocks_loaded', function() {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) return;

    // Read gateway_mode from raw options — gateway class may not be instantiated yet at this hook
    $child_settings = get_option('maef_child_settings', []);
    if (empty($child_settings['gateway_mode'])) return;

    require_once plugin_dir_path(__FILE__) . 'class-maef-gateway-blocks.php';
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( $payment_method_registry ) {
            $payment_method_registry->register( new MAEF_Gateway_Blocks_Support() );
        }
    );
});
