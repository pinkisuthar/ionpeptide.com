<?php
/**
 * 3thix WooCommerce payment gateway.
 *
 * @package Thix_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Thix_Gateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'thix';
        $this->method_title = __('3thix Payments', 'thix-payments');
        $this->method_description = __('Charge the exact cart total through 3thix. Card payments (Stripe Connect "direct") or a fiat→USDC on-ramp ("escrow") settling to your payout wallet. Buyers can pay in an embedded checkout that keeps them on your store, or be redirected to the 3thix hosted page.', 'thix-payments');
        $this->has_fields = false;
        $this->supports = ['products', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        // One-time rename: stores still on the old default label get the new
        // one automatically. Merchants who typed a custom title keep theirs.
        if ($this->title === 'Card / Crypto (3thix)') {
            $this->update_option('title', __('Credit Card Payments', 'thix-payments'));
            $this->title = $this->get_option('title');
        }
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        // Embedded checkout: render the 3thix iframe on the order-pay/receipt
        // page so buyers complete payment without leaving the store. Only fires
        // when `process_payment` routed the buyer here (display = embed).
        add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page'], 10, 1);
        // Secondary confirmation: when the buyer is redirected back to the
        // thank-you page, reconcile the order against 3thix in case the signed
        // callback is delayed or was never configured. Idempotent.
        //
        // MUST run on `woocommerce_before_thankyou` (fires at the very top of
        // the thank-you template), NOT on `woocommerce_thankyou`: core hooks
        // `woocommerce_order_details_table` to `woocommerce_thankyou` at the
        // same priority 10 and registers it FIRST, so on `woocommerce_thankyou`
        // our reconcile would run AFTER the details table has already rendered
        // the unpaid-order "Pay / Cancel" actions — leaving a stray Pay/Cancel
        // on a successful order. Reconciling before the table is drawn fixes it.
        add_action('woocommerce_before_thankyou', [$this, 'reconcile_on_return'], 10, 1);
        // Theme-proof fallback for the same reconcile: customized themes often
        // replace the thank-you template and never fire
        // `woocommerce_before_thankyou`, leaving the order to the background
        // sweep (a cron context where some email stacks stay silent).
        // `template_redirect` fires on EVERY frontend page load before any
        // template renders, so the order completes during the buyer's own
        // page view — the same context in which other gateways' emails work.
        add_action('template_redirect', [$this, 'reconcile_on_return_endpoint'], 5);
        // Buyer-facing polish: an on-ramp (Stable Payments) order stays
        // "pending payment" for the ~2-3 minute on-chain settlement window
        // AFTER the buyer has already paid, and WooCommerce (plus most themes,
        // via `wc_get_account_orders_actions`) renders "Pay" / "Cancel"
        // buttons for pending orders — confusing on the confirmation page for
        // a buyer whose money is already in flight. Suppress those actions on
        // the order-received page ONLY (My Account keeps them so a buyer who
        // genuinely abandoned checkout can still retry or cancel), and show a
        // "payment is processing" note that refreshes until the order flips.
        add_filter('woocommerce_my_account_my_orders_actions', [$this, 'suppress_actions_on_order_received'], 10, 2);
        add_filter('woocommerce_valid_order_statuses_for_payment', [$this, 'suppress_pending_status_on_order_received'], 10, 2);
        add_filter('woocommerce_valid_order_statuses_for_cancel', [$this, 'suppress_pending_status_on_order_received'], 10, 2);
        add_action('woocommerce_before_thankyou', [$this, 'render_settling_notice'], 20, 1);
        // The order-received page is technically an endpoint of the Checkout
        // page, so themes title it "Checkout" even after payment — confusing
        // once the buyer has paid. Retitle it "Order confirmation" for 3thix
        // orders (both the on-page heading and the browser tab).
        add_filter('woocommerce_endpoint_order-received_title', [$this, 'order_received_title'], 10, 2);
        add_filter('the_title', [$this, 'order_received_page_title'], 10, 2);
        // Session-token mint for the BLOCK checkout's inline card form: the
        // block checkout re-renders client-side (no payment fragment reload),
        // so the amount-bound token is fetched over admin-ajax whenever the
        // cart total changes. Static guard — WooCommerce can construct the
        // gateway more than once per request.
        static $ajax_hooked = false;
        if (!$ajax_hooked) {
            $ajax_hooked = true;
            add_action('wp_ajax_thix_inline_token', [$this, 'ajax_inline_token']);
            add_action('wp_ajax_nopriv_thix_inline_token', [$this, 'ajax_inline_token']);
        }
    }

    /**
     * AJAX: mint (or return the cached) inline checkout session token for the
     * CURRENT cart total. The amount is computed server-side from the session
     * cart — never taken from the client — and the token is the same
     * session-bound value `process_inline_payment` later verifies, so this
     * endpoint mints nothing a buyer couldn't already get by loading the
     * classic checkout page.
     */
    public function ajax_inline_token() {
        check_ajax_referer('thix_inline_token', 'nonce');
        if (!$this->checkout_runtime_ready()) {
            wp_send_json_error(['message' => 'checkout_not_configured'], 400);
        }
        if (!function_exists('WC') || !WC()->cart || !WC()->session) {
            wp_send_json_error(['message' => 'cart_unavailable'], 400);
        }
        // Inline checkout is direct-card only. Do not mint a USD-denominated
        // payment token for a non-USD cart; that would misrepresent the buyer's
        // order total before the server has a chance to reject it.
        $currency = function_exists('get_woocommerce_currency')
            ? strtoupper((string) get_woocommerce_currency())
            : '';
        if ($currency !== 'USD') {
            wp_send_json_error(['message' => 'direct_card_requires_usd'], 400);
        }
        WC()->cart->calculate_totals();
        $amount_cents = (int) round((float) WC()->cart->get_total('edit') * 100);
        wp_send_json_success([
            'token'       => $this->inline_external_order_id($amount_cents),
            'amountCents' => $amount_cents,
        ]);
    }

    /**
     * Settings handed to the WooCommerce Blocks payment method script
     * (assets/blocks.js) via Thix_Blocks_Support::get_payment_method_data().
     *
     * @return array
     */
    public function blocks_payment_method_data() {
        $ready         = $this->checkout_runtime_ready();
        $api_base      = $ready ? Thix_Api::normalize_base($this->get_option('api_base')) : '';
        $publishable   = $ready ? trim((string) $this->get_option('publishable_key')) : '';
        $inline        = $this->is_inline() && $ready;
        return [
            'title'          => $this->get_option('title'),
            'description'    => $this->get_option('description'),
            'supports'       => array_filter($this->supports, [$this, 'supports']),
            'inline'         => $inline,
            // Do not hand malformed or cross-environment keys to browser
            // checkout code, even when stale settings predate validation.
            'publishableKey' => $publishable,
            'embedSrc'       => $ready ? $api_base . '/public/embed.js' : '',
            'brand'          => $this->branding_args(),
            'providers'      => $this->enabled_providers(),
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'tokenNonce'     => wp_create_nonce('thix_inline_token'),
            'iconHtml'       => $this->get_icon(),
            // A Blocks checkout does not have a WC_Order until submission. The
            // current store/cart currency is UI guidance only; process_payment()
            // re-checks the actual order currency authoritatively.
            'storeCurrency'  => function_exists('get_woocommerce_currency') ? strtoupper((string) get_woocommerce_currency()) : '',
            'cardCurrencySupported' => !function_exists('get_woocommerce_currency') || strtoupper((string) get_woocommerce_currency()) === 'USD',
            'directCardOnly' => $this->get_option('mode') === 'card' && !in_array('coinbase', $this->enabled_providers(), true),
        ];
    }

    /**
     * True when the buyer on the current order-received request paid (or is
     * paying) through 3thix — used to scope confirmation-page tweaks so other
     * gateways' confirmation pages are left untouched.
     */
    private function is_thix_order_received_request() {
        if (!$this->is_order_received_request()) {
            return false;
        }
        global $wp;
        $order_id = isset($wp->query_vars['order-received']) ? absint($wp->query_vars['order-received']) : 0;
        if ($order_id <= 0) {
            return false;
        }
        $order = wc_get_order($order_id);
        return $order && $order->get_payment_method() === $this->id;
    }

    /**
     * WooCommerce endpoint title for the order-received page (used by themes
     * that call `wc_page_endpoint_title` and by the document <title>).
     *
     * @param string $title
     * @param string $endpoint
     * @return string
     */
    public function order_received_title($title, $endpoint = '') {
        if ($this->is_thix_order_received_request()) {
            return __('Order confirmation', 'thix-payments');
        }
        return $title;
    }

    /**
     * Fallback for themes that render the raw Checkout page title instead of
     * the endpoint title: swap the main page heading on order-received.
     *
     * @param string $title
     * @param int    $post_id
     * @return string
     */
    public function order_received_page_title($title, $post_id = 0) {
        if (is_admin() || !function_exists('wc_get_page_id') || !in_the_loop()) {
            return $title;
        }
        if ((int) $post_id === (int) wc_get_page_id('checkout') && $this->is_thix_order_received_request()) {
            return __('Order confirmation', 'thix-payments');
        }
        return $title;
    }

    /**
     * True when the current frontend request is the order-received
     * (thank-you) endpoint — the only place we hide Pay/Cancel.
     */
    private function is_order_received_request() {
        return !is_admin()
            && function_exists('is_wc_endpoint_url')
            && is_wc_endpoint_url('order-received');
    }

    /**
     * Remove the "Pay" / "Cancel" order actions on the order-received page for
     * 3thix orders. Themes that show an Actions row on the confirmation page
     * build it from `wc_get_account_orders_actions`, which applies this filter.
     *
     * @param array    $actions
     * @param WC_Order $order
     * @return array
     */
    public function suppress_actions_on_order_received($actions, $order) {
        if ($order instanceof WC_Order
            && $order->get_payment_method() === $this->id
            && $order->has_status('pending')
            && $this->is_order_received_request()) {
            unset($actions['pay'], $actions['cancel']);
        }
        return $actions;
    }

    /**
     * Belt-and-braces for themes that render Pay/Cancel directly from
     * `$order->needs_payment()` / `WC_Order::get_cancel_order_url()` checks:
     * on the order-received page, a pending 3thix order is not payable or
     * cancellable (the payment is settling). "failed" stays valid so a buyer
     * whose payment actually failed can still retry.
     *
     * @param array         $statuses
     * @param WC_Order|null $order
     * @return array
     */
    public function suppress_pending_status_on_order_received($statuses, $order = null) {
        if ($order instanceof WC_Order
            && $order->get_payment_method() === $this->id
            && $this->is_order_received_request()) {
            $statuses = array_values(array_diff((array) $statuses, ['pending']));
        }
        return $statuses;
    }

    /**
     * On the confirmation page, tell the buyer their payment is processing
     * (instead of the confusing pending-order Pay/Cancel actions) and refresh
     * the page periodically until the order is marked paid. Capped so an order
     * that never funds doesn't reload forever.
     *
     * @param int $order_id
     */
    public function render_settling_notice($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }
        if ($order->is_paid() || !$order->has_status('pending')) {
            return;
        }
        if ($order->get_meta('_thix_hold_review') === '1') {
            return;
        }
        echo '<div class="woocommerce-info thix-settling-notice" role="status">'
            . esc_html__('Your payment is processing — this usually takes a minute or two. This page will update automatically, and you\'ll receive a confirmation email as soon as it completes. No further action is needed.', 'thix-payments')
            . '</div>';
        ?>
        <script>
        (function () {
            var key = 'thixSettle<?php echo (int) $order->get_id(); ?>';
            var attempts = 0;
            try {
                attempts = parseInt(window.sessionStorage.getItem(key) || '0', 10) || 0;
            } catch (e) { /* storage blocked — refresh once, then stop */ attempts = 99; }
            if (attempts < 20) {
                try { window.sessionStorage.setItem(key, String(attempts + 1)); } catch (e) {}
                window.setTimeout(function () { window.location.reload(); }, 15000);
            }
        })();
        </script>
        <?php
    }

    /**
     * Run the buyer-return reconcile when the current request is the
     * order-received (thank-you) endpoint, regardless of theme template.
     * Idempotent with the `woocommerce_before_thankyou` hook: whichever runs
     * first completes the order; the other sees it paid and returns.
     */
    public function reconcile_on_return_endpoint() {
        if (is_admin() || !function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-received')) {
            return;
        }
        global $wp;
        $order_id = isset($wp->query_vars['order-received']) ? absint($wp->query_vars['order-received']) : 0;
        if ($order_id > 0) {
            $this->reconcile_on_return($order_id);
        }
    }

    /**
     * Resolve a configured API client for server-side calls + signature checks.
     */
    public function api() {
        return new Thix_Api(
            $this->get_option('api_base'),
            $this->get_option('secret_key'),
            $this->get_option('signing_secret')
        );
    }

    public function get_signing_secret() {
        return (string) $this->get_option('signing_secret');
    }

    /**
     * Return the selected API-key environment, or an empty string for an
     * invalid/stale setting. Runtime code must fail closed for the latter.
     *
     * @return string `test`, `live`, or ''.
     */
    public function configured_environment() {
        $raw_environment = $this->get_option('environment', 'test');
        $environment = is_scalar($raw_environment) ? trim((string) $raw_environment) : '';
        return in_array($environment, ['test', 'live'], true) ? $environment : '';
    }

    /**
     * Whether it is safe to launch a checkout from the saved settings.
     *
     * The secret key remains optional for stores that only need the hosted
     * checkout and is intentionally not validated here. This lets an admin
     * configure a disabled/incomplete gateway while preventing stale settings
     * from sending a malformed browser key at runtime.
     *
     * @return bool
     */
    public function checkout_runtime_ready() {
        $environment = $this->configured_environment();
        if ($environment === '') {
            return false;
        }

        $raw_publishable = $this->get_option('publishable_key');
        $publishable = is_scalar($raw_publishable) ? trim((string) $raw_publishable) : '';
        return Thix_Api::is_valid_publishable_key($publishable, $environment);
    }

    /**
     * Validate the complete candidate settings array before WooCommerce writes
     * the single gateway option. Empty keys are allowed so a disabled/new
     * gateway can be configured in stages; any supplied key must be complete.
     *
     * Error messages intentionally contain no key material.
     *
     * @param array $settings Candidate settings after WooCommerce field parsing.
     * @return string[] Safe, human-readable errors.
     */
    public static function validate_settings(array $settings) {
        $errors = [];
        $environment = isset($settings['environment']) && is_scalar($settings['environment'])
            ? trim((string) $settings['environment'])
            : '';

        if (!in_array($environment, ['test', 'live'], true)) {
            $errors[] = __('Choose either the Test or Live environment.', 'thix-payments');
            // Avoid adding two misleading key errors when the environment itself
            // is invalid; the save remains atomic either way.
            return $errors;
        }

        $field = 'publishable_key';
        $message = __('The publishable key is incomplete or invalid. Paste the complete key from the 3thix console.', 'thix-payments');
        if (!array_key_exists($field, $settings) || $settings[$field] === null) {
            return $errors;
        }
        if ($settings[$field] === '') {
            return $errors;
        }
        if (!is_string($settings[$field]) && !is_numeric($settings[$field])) {
            $errors[] = $message;
            return $errors;
        }
        $value = trim((string) $settings[$field]);
        if ($value === '') {
            return $errors;
        }
        if (!Thix_Api::is_valid_publishable_key($value)) {
            $errors[] = $message;
        } elseif (!Thix_Api::is_valid_publishable_key($value, $environment)) {
            $errors[] = __('Your saved API keys must match the selected environment.', 'thix-payments');
        }

        return array_values(array_unique($errors));
    }

    /**
     * Build the same candidate WooCommerce will hand to its parent saver.
     * Keeping validation ahead of parent::process_admin_options() means a
     * failed environment/key change cannot partially update the option.
     *
     * @return array
     */
    private function candidate_settings_for_save() {
        $this->init_settings();
        $candidate = is_array($this->settings) ? $this->settings : [];
        $post_data = $this->get_post_data();
        $fields = $this->get_form_fields();

        foreach ((array) $fields as $key => $field) {
            if ($this->get_field_type($field) === 'title') {
                continue;
            }

            $candidate[$key] = $this->get_field_value($key, $field, $post_data);
        }

        return $candidate;
    }

    /**
     * Atomically reject malformed/cross-environment keys before WooCommerce's
     * parent implementation updates the complete gateway option.
     *
     * @return bool
     */
    public function process_admin_options() {
        $errors = self::validate_settings($this->candidate_settings_for_save());
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->add_error($error);
            }
            return false;
        }

        return parent::process_admin_options();
    }

    public function init_form_fields() {
        $this->form_fields = [
            'section_method' => [
                'title'       => __('Payment method', 'thix-payments'),
                'type'        => 'title',
                'description' => __('Turn 3thix on and choose how it is labelled for shoppers at checkout.', 'thix-payments'),
            ],
            'enabled' => [
                'title'   => __('Enable/Disable', 'thix-payments'),
                'type'    => 'checkbox',
                'label'   => __('Enable 3thix Payments', 'thix-payments'),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __('Title', 'thix-payments'),
                'type'        => 'text',
                'description' => __('Payment method name shown to customers at checkout.', 'thix-payments'),
                'default'     => __('Credit Card Payments', 'thix-payments'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'thix-payments'),
                'type'        => 'textarea',
                'description' => __('Shown to customers under the payment method.', 'thix-payments'),
                'default'     => __('Your card details are entered on a secure, encrypted checkout and are never stored on this site.', 'thix-payments'),
            ],
            'show_card_logos' => [
                'title'       => __('Show credit card logos', 'thix-payments'),
                'type'        => 'checkbox',
                'label'       => __('Show accepted card logos next to the payment method title at checkout', 'thix-payments'),
                'description' => __('Display Visa, Mastercard, American Express, and Discover logos in the checkout payment-method list.', 'thix-payments'),
                'default'     => 'yes',
                'desc_tip'    => false,
            ],
            'section_connection' => [
                'title'       => __('Connection', 'thix-payments'),
                'type'        => 'title',
                'description' => __('Link this store to your 3thix account. Keys must match the selected environment (test keys in Test, live keys in Live).', 'thix-payments'),
            ],
            'api_base' => [
                'title'       => __('3thix API base URL', 'thix-payments'),
                'type'        => 'text',
                'description' => __('Your 3thix console API base — it must end in /api. For the live 3thix console use https://pay.3thix.com/api . The exact value for your account is also shown on the console Developers page. (Common mistake: using api.3thix.com — that domain does not exist; the host is pay.3thix.com.)', 'thix-payments'),
                'default'     => 'https://pay.3thix.com/api',
                'placeholder' => 'https://pay.3thix.com/api',
                'desc_tip'    => true,
            ],
            'environment' => [
                'title'       => __('Environment', 'thix-payments'),
                'type'        => 'select',
                'description' => __('Use Test while validating with sandbox keys, then switch to Live. Your keys must match this mode.', 'thix-payments'),
                'default'     => 'test',
                'options'     => [
                    'test' => __('Test (sandbox)', 'thix-payments'),
                    'live' => __('Live', 'thix-payments'),
                ],
                'desc_tip'    => true,
            ],
            'publishable_key' => [
                'title'       => __('Publishable key', 'thix-payments'),
                'type'        => 'text',
                'description' => __('Browser-safe key (thx_test_pk_… / thx_live_pk_…). Paste the complete minted key, including its 40-character hexadecimal suffix; shortened or masked display hints are rejected.', 'thix-payments'),
                'placeholder' => 'thx_test_pk_…',
                'desc_tip'    => true,
            ],
            'secret_key' => [
                'title'       => __('Secret key', 'thix-payments'),
                'type'        => 'password',
                'description' => __('Server-only key (thx_test_… / thx_live_…). Used to sync tracking. Never exposed to buyers.', 'thix-payments'),
                'placeholder' => 'thx_test_…',
                'desc_tip'    => true,
            ],
            'section_flow' => [
                'title'       => __('Checkout flow', 'thix-payments'),
                'type'        => 'title',
                'description' => __('Choose how buyers pay and where the checkout appears. The preview on the right updates as you edit.', 'thix-payments'),
            ],
            'mode' => [
                'title'       => __('Checkout experience', 'thix-payments'),
                'type'        => 'select',
                'description' => __('On-ramp (fiat→USDC into escrow) or a direct card charge. Match this to how your merchant account was set up in 3thix.', 'thix-payments'),
                'default'     => 'card',
                'options'     => [
                    'onramp' => __('Stable Payments', 'thix-payments'),
                    'card'   => __('Card payment', 'thix-payments'),
                ],
                'desc_tip'    => true,
            ],
            'enable_stripe' => [
                'title'       => __('Stripe payments', 'thix-payments'),
                'type'        => 'checkbox',
                'label'       => __('Offer Stripe at checkout (card / Apple Pay / Google Pay)', 'thix-payments'),
                'description' => __('When both Stripe and Coinbase are on, buyers pick one at checkout (Stripe preselected). If only one is on, it is used automatically.', 'thix-payments'),
                'default'     => 'yes',
                'desc_tip'    => false,
            ],
            'enable_coinbase' => [
                'title'       => __('Coinbase payments', 'thix-payments'),
                'type'        => 'checkbox',
                'label'       => __('Offer Coinbase at checkout (pay from a Coinbase account)', 'thix-payments'),
                'description' => __('Off by default — tick to offer Coinbase. Requires a 3thix crypto (Stable Payments) merchant account with Coinbase enabled in the 3thix console. If Coinbase is not available for your account, buyers who pick it are offered the standard checkout instead.', 'thix-payments'),
                'default'     => 'no',
                'desc_tip'    => false,
            ],
            'display' => [
                'title'       => __('Checkout display', 'thix-payments'),
                'type'        => 'select',
                'description' => __('Redirect (recommended) sends buyers to the clean, full-page 3thix checkout, then back to your store — the best-looking experience. Embedded shows the checkout in a secure 3thix iframe on the order-pay page (keeps buyers on your store, but WooCommerce adds its own order table above it). Inline card form renders the secure 3thix card form right inside the "Pay securely by card" box on the checkout page — buyers fill their details and pay without any extra step (card mode only). Card data never touches your site in any mode.', 'thix-payments'),
                'default'     => 'redirect',
                'options'     => [
                    'redirect' => __('Redirect to 3thix hosted page (recommended)', 'thix-payments'),
                    'embed'    => __('Embedded on my store', 'thix-payments'),
                    'inline'   => __('Inline card form on the checkout page', 'thix-payments'),
                ],
                'desc_tip'    => true,
            ],
            'checkout_theme' => [
                'title'       => __('Checkout appearance', 'thix-payments'),
                'type'        => 'select',
                'description' => __('"3thix (black & green)" uses the signature dark 3thix look. "Match my store" switches the checkout to a light theme and automatically pulls your site name and logo so it blends in with your storefront.', 'thix-payments'),
                'default'     => 'match',
                'options'     => [
                    'match' => __('Match my store (light, auto-synced)', 'thix-payments'),
                    'dark'  => __('3thix (black & green)', 'thix-payments'),
                ],
                'desc_tip'    => true,
            ],
            'section_branding' => [
                'title'       => __('Branding', 'thix-payments'),
                'type'        => 'title',
                'description' => __('Make the checkout feel like your store. Leave blank to use your site logo and the 3thix mint accent.', 'thix-payments'),
            ],
            'brand_logo' => [
                'title'       => __('Logo URL (optional)', 'thix-payments'),
                'type'        => 'text',
                'description' => __('Direct https link to your logo (PNG/SVG/JPG). Shown at the top of the checkout. Leave blank in "Match my store" mode to use your site logo automatically.', 'thix-payments'),
                'placeholder' => 'https://yourstore.com/logo.png',
                'desc_tip'    => true,
            ],
            'accent_color' => [
                'title'       => __('Accent color (optional)', 'thix-payments'),
                'type'        => 'text',
                'description' => __('Hex color for the pay button and highlights, e.g. #2CE28D. Leave blank to use the 3thix mint green.', 'thix-payments'),
                'placeholder' => '#2CE28D',
                'desc_tip'    => true,
            ],
            'section_advanced' => [
                'title'       => __('Callbacks & tracking', 'thix-payments'),
                'type'        => 'title',
                'description' => __('Optional. Verify signed crypto callbacks and auto-sync shipment tracking so escrowed funds release faster.', 'thix-payments'),
            ],
            'signing_secret' => [
                'title'       => __('Callback signing secret (CRYPTO ONLY)', 'thix-payments'),
                'type'        => 'password',
                'description' => sprintf(
                    /* translators: %s: callback REST endpoint URL */
                    __('Found on your merchant page in the 3thix console. Verifies signed callbacks. Set your callback URL in 3thix to: %s', 'thix-payments'),
                    esc_url(rest_url('thix/v1/callback'))
                ),
                'desc_tip'    => false,
            ],
            'auto_tracking' => [
                'title'       => __('Automatic tracking sync', 'thix-payments'),
                'type'        => 'checkbox',
                'label'       => __('Automatically send shipment tracking to 3thix when it is added or updated on an order', 'thix-payments'),
                'description' => __('When on, tracking entered on a paid 3thix order — in the 3thix box, or via the WooCommerce Shipment Tracking / Advanced Shipment Tracking plugins — is sent to 3thix automatically as soon as you fulfil the order. This starts the ~1 business day escrow release timer; no need to send it by hand.', 'thix-payments'),
                'default'     => 'yes',
                'desc_tip'    => false,
            ],
        ];
    }

    /**
     * Accepted card-network marks shown to the RIGHT of the method title in the
     * checkout payment-method list (like the other card processors). Visible
     * even before the method is selected. Styled wordmarks + an inline-SVG
     * Mastercard so they render crisply with no bundled image assets; all
     * styles are inline so they don't depend on the (selected-only) panel CSS.
     */
    public function get_icon() {
        if ($this->get_option('show_card_logos', 'yes') !== 'yes') {
            return '';
        }

        $chip = 'display:inline-flex;align-items:center;justify-content:center;height:21px;min-width:34px;padding:0 6px;margin:0 0 0 4px;border:1px solid #e3e6ea;border-radius:4px;background:#fff;font-size:10px;font-weight:800;letter-spacing:.4px;line-height:1;vertical-align:middle;box-shadow:0 1px 1px rgba(16,24,40,.04);font-family:Arial,Helvetica,sans-serif;';
        $mc   = '<svg width="26" height="16" viewBox="0 0 30 19" aria-hidden="true" style="display:block"><circle cx="12" cy="9.5" r="8" fill="#EB001B"></circle><circle cx="18" cy="9.5" r="8" fill="#F79E1B" fill-opacity=".85"></circle></svg>';

        $icons  = '<span class="thix-method-icons" style="display:inline-flex;align-items:center;white-space:nowrap;vertical-align:middle;">';
        $icons .= '<span style="' . $chip . 'color:#1a1f71;font-style:italic;">VISA</span>';
        $icons .= '<span style="' . $chip . 'padding:0 5px;">' . $mc . '</span>';
        $icons .= '<span style="' . $chip . 'background:#1f72cd;border-color:#1f72cd;color:#fff;">AMEX</span>';
        $icons .= '<span style="' . $chip . 'color:#e06b1f;">DISCOVER</span>';
        $icons .= '</span>';

        return apply_filters('woocommerce_gateway_icon', $icons, $this->id);
    }

    /**
     * Render a branded, 3thix-themed payment panel at checkout instead of the
     * default raw description text. Self-contained inline CSS (scoped to
     * `.thix-pay-fields`) so it renders consistently regardless of the store
     * theme; the style block is printed once per page.
     */
    /**
     * Inline card form is opt-in (display = inline) and card-only: the 3thix
     * card iframe self-completes payment, and the Stripe Crypto on-ramp cannot
     * run framed, so on-ramp merchants fall back to the redirect/embed flow.
     */
    private function is_inline() {
        return $this->get_option('display') === 'inline'
            && $this->get_option('mode') === 'card';
    }

    /**
     * Whether the store's checkout page uses the WooCommerce BLOCK checkout
     * (Gutenberg `woocommerce/checkout` block) instead of the classic
     * `[woocommerce_checkout]` shortcode form.
     *
     * The inline card form only works on the classic checkout: its controller
     * script gates on `form.checkout` / `.validate-required` fields (which the
     * block checkout doesn't render), and the block checkout's compatibility
     * layer prints a classic gateway's payment_fields HTML WITHOUT executing
     * its inline <script> tags — so the card iframe can never mount and the
     * "fill in your details above" gate stays forever. On block checkouts the
     * gateway therefore falls back to the redirect/embed flow (Place Order →
     * hosted 3thix checkout), which works fine through the Store API.
     *
     * @return bool
     */
    private function checkout_uses_blocks() {
        if (!function_exists('has_block') || !function_exists('wc_get_page_id')) {
            return false;
        }
        $page_id = (int) wc_get_page_id('checkout');
        if ($page_id <= 0) {
            return false;
        }
        return has_block('woocommerce/checkout', $page_id);
    }

    /**
     * Payment providers the store offers at checkout, in display order.
     * Both are on by default; never returns an empty list (falls back to
     * Stripe so the gateway keeps working if an admin unticks both).
     *
     * @return string[] Subset of ['stripe','coinbase'].
     */
    private function enabled_providers() {
        $out = [];
        if ($this->get_option('enable_stripe', 'yes') === 'yes') {
            $out[] = 'stripe';
        }
        if ($this->get_option('enable_coinbase', 'no') === 'yes') {
            $out[] = 'coinbase';
        }
        if (!$out) {
            $out[] = 'stripe';
        }
        return $out;
    }

    /**
     * Provider the buyer picked at checkout, constrained to the enabled set
     * (an unticked or unknown posted value falls back to the first enabled
     * provider). Preference-only routing — the 3thix server enforces which
     * rails the merchant may actually charge on.
     *
     * @return string 'stripe' or 'coinbase'.
     */
    private function chosen_provider() {
        $providers = $this->enabled_providers();
        $posted    = self::posted_checkout_field('thix_provider');
        return in_array($posted, $providers, true) ? $posted : $providers[0];
    }

    /**
     * Read a checkout field posted with the order, accepting BOTH naming
     * schemes that reach `process_payment`:
     *
     * - classic checkout: the raw form field name (e.g. `thix_payment_id`);
     * - block checkout (Store API): entries from the request's `payment_data`
     *   are bridged into `$_POST` for legacy gateways — depending on the
     *   WooCommerce Blocks version the bridged key is either the raw name or
     *   prefixed as `payment_data_<name>`. Accept both so the native blocks
     *   integration works across versions.
     *
     * WooCommerce / the Store API verify the checkout before dispatching to
     * process_payment(), so no extra nonce check here; values are sanitized.
     *
     * @param string $key Field name without prefix, e.g. 'thix_payment_id'.
     * @return string Sanitized value, or '' when absent.
     */
    public static function posted_checkout_field($key) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        foreach ([$key, 'payment_data_' . $key] as $candidate) {
            if (isset($_POST[$candidate]) && !is_array($_POST[$candidate])) {
                return sanitize_text_field(wp_unslash($_POST[$candidate]));
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return '';
    }

    /**
     * Buyer-facing Stripe/Coinbase selector shown inside the 3thix payment
     * panel when BOTH providers are enabled. Radio selection is posted as
     * `thix_provider` with the order; the flow for the picked provider then
     * starts (redirect/embed preselects it on the hosted checkout).
     */
    private function provider_picker_html() {
        $providers = $this->enabled_providers();
        if (count($providers) < 2) {
            if ($providers) {
                echo '<input type="hidden" name="thix_provider" value="' . esc_attr($providers[0]) . '" />';
            }
            return;
        }

        static $printed_style = false;
        if (!$printed_style) {
            $printed_style = true;
            ?>
            <style>
                .thix-provider-pick{display:flex;flex-direction:column;gap:8px;margin:0 0 12px;}
                .thix-provider-opt{display:flex;align-items:center;gap:10px;border:1px solid #e3e6ea;border-radius:11px;background:#fff;padding:11px 13px;cursor:pointer;transition:border-color .12s,box-shadow .12s;}
                .thix-provider-opt:hover{border-color:#b8bfc9;}
                .thix-provider-opt input[type=radio]{margin:0;flex:0 0 auto;}
                .thix-provider-opt.sel{border-color:#2CE28D;box-shadow:0 0 0 3px rgba(44,226,141,.16);}
                .thix-provider-name{font-weight:700;font-size:13.5px;color:#101828;line-height:1.25;}
                .thix-provider-sub{font-size:12px;color:#667085;line-height:1.35;margin-top:1px;}
            </style>
            <script>
            (function () {
                // WooCommerce re-renders the payment fragment on every
                // updated_checkout (address/shipping changes), which would
                // silently reset the radios to the server-rendered default
                // (Stripe). Persist the buyer's pick in sessionStorage and
                // restore it after each re-render so a Coinbase choice sticks.
                var KEY = 'thix_provider_pick';
                function stored() {
                    try { return window.sessionStorage.getItem(KEY) || ''; } catch (e) { return ''; }
                }
                function store(v) {
                    try { window.sessionStorage.setItem(KEY, v); } catch (e) {}
                }
                function sync(restore) {
                    var radios = document.querySelectorAll('input[name="thix_provider"]');
                    if (restore) {
                        var want = stored();
                        for (var k = 0; k < radios.length; k++) {
                            if (want && radios[k].value === want) { radios[k].checked = true; }
                        }
                    }
                    var opts = document.querySelectorAll('.thix-provider-opt');
                    for (var i = 0; i < opts.length; i++) {
                        var r = opts[i].querySelector('input[type=radio]');
                        opts[i].className = 'thix-provider-opt' + (r && r.checked ? ' sel' : '');
                    }
                    try { document.dispatchEvent(new CustomEvent('thix:provider-change')); } catch (e) {}
                }
                document.addEventListener('change', function (e) {
                    if (e.target && e.target.name === 'thix_provider') {
                        store(e.target.value);
                        sync(false);
                    }
                });
                if (window.jQuery) { window.jQuery(document.body).on('updated_checkout', function () { sync(true); }); }
                if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { sync(true); }); }
                else { sync(true); }
            })();
            </script>
            <?php
        }
        ?>
        <div class="thix-provider-pick" role="radiogroup" aria-label="<?php esc_attr_e('Payment provider', 'thix-payments'); ?>">
            <label class="thix-provider-opt sel">
                <input type="radio" name="thix_provider" value="stripe" checked="checked" />
                <span>
                    <span class="thix-provider-name"><?php esc_html_e('Stripe', 'thix-payments'); ?></span>
                    <span class="thix-provider-sub" style="display:block;"><?php esc_html_e('ACH, Credit Card, Debit Card, Apple Pay, Google Pay', 'thix-payments'); ?></span>
                </span>
            </label>
            <label class="thix-provider-opt">
                <input type="radio" name="thix_provider" value="coinbase" />
                <span>
                    <span class="thix-provider-name"><?php esc_html_e('Coinbase', 'thix-payments'); ?></span>
                    <span class="thix-provider-sub" style="display:block;"><?php esc_html_e('ACH, Debit Card, Apple Pay, Google Pay, PayPal, Wire', 'thix-payments'); ?></span>
                </span>
            </label>
        </div>
        <?php
    }

    public function payment_fields() {
        // Inline card form: render the secure 3thix card iframe right in the
        // checkout payment area instead of a description + Place Order redirect.
        // Classic checkout only — the block checkout renders through the native
        // blocks integration (assets/blocks.js), and its compatibility layer
        // would print this HTML without executing the controller <script>.
        if ($this->is_inline() && !$this->checkout_uses_blocks()) {
            $this->inline_payment_fields();
            return;
        }

        // Buyer-facing checkout copy is intentionally mode-agnostic: shoppers always
        // just "pay by card" and must never see whether routing is direct vs on-ramp
        // (escrow/USDC). The mode is an internal, admin-only setting.
        $description = trim((string) $this->get_description());
        if ($description === '') {
            $description = __('Your card details are entered on a secure, encrypted checkout and are never stored on this site.', 'thix-payments');
        }

        $lock_icon = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>';

        static $printed_style = false;
        if (!$printed_style) {
            $printed_style = true;
            ?>
            <style>
                .thix-pay-fields{margin:10px 0 2px;font-family:inherit;-webkit-font-smoothing:antialiased;}
                .thix-pay-card{border:1px solid #e3e6ea;border-radius:14px;background:#fff;padding:18px 18px 16px;box-shadow:0 1px 3px rgba(16,24,40,.08);}
                .thix-pay-head{display:flex;align-items:center;gap:10px;margin:0 0 8px;}
                .thix-pay-title{font-weight:700;font-size:15px;color:#101828;letter-spacing:.1px;}
                .thix-pay-secure{margin-left:auto;display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#067647;background:#ecfdf3;border:1px solid #abefc6;padding:4px 10px;border-radius:999px;}
                .thix-pay-desc{margin:0 0 12px;color:#475467;font-size:13.5px;line-height:1.55;}
                .thix-pay-foot{display:flex;align-items:center;}
                .thix-pay-powered{margin-left:auto;font-size:11.5px;color:#98a2b3;display:inline-flex;align-items:center;gap:6px;}
                .thix-pay-dot{width:7px;height:7px;border-radius:50%;background:#2CE28D;display:inline-block;}
            </style>
            <?php
        }
        ?>
        <div class="thix-pay-fields">
            <div class="thix-pay-card">
                <div class="thix-pay-head">
                    <span class="thix-pay-title"><?php esc_html_e('Pay securely by card', 'thix-payments'); ?></span>
                    <span class="thix-pay-secure"><?php echo $lock_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e('Encrypted', 'thix-payments'); ?></span>
                </div>
                <?php $this->provider_picker_html(); ?>
                <p class="thix-pay-desc"><?php echo wp_kses_post($description); ?></p>
                <div class="thix-pay-foot">
                    <span class="thix-pay-powered"><span class="thix-pay-dot"></span> <?php esc_html_e('Powered by 3thix', 'thix-payments'); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Resolve a stable client token used as the 3thix `externalOrderId` for the
     * inline card form. There is no WooCommerce order yet when the checkout page
     * renders, so the iframe creates the 3thix order under this token; the token
     * is later stored on the WooCommerce order (`_thix_eoid`) at
     * `process_payment` so the callback/return reconcile can match back to it.
     *
     * The token is cached in the WooCommerce session and REGENERATED whenever the
     * cart total changes: `POST /public/checkout` reuses an existing order for the
     * same (merchant, externalOrderId) and returns its ORIGINAL amount, so reusing
     * a token across an amount change would charge the stale total. Tying the token
     * to the amount guarantees the buyer is always charged the current cart total.
     *
     * @param int $amount_cents Current cart total in cents.
     * @return string
     */
    private function inline_external_order_id($amount_cents) {
        $session = WC()->session;
        if ($session) {
            $stored = $session->get('thix_inline_eoid');
            if (is_array($stored) && !empty($stored['token']) && (int) $stored['amount'] === (int) $amount_cents) {
                return (string) $stored['token'];
            }
        }
        $token = 'wc-' . wp_generate_uuid4();
        if ($session) {
            $session->set('thix_inline_eoid', ['token' => $token, 'amount' => (int) $amount_cents]);
        }
        return $token;
    }

    /**
     * Render the inline 3thix card form directly in the checkout payment area
     * (display = inline, card mode). The secure card fields run inside a 3thix
     * iframe (card data never touches this site), gated behind the buyer having
     * completed WooCommerce's required checkout fields so payment can't be taken
     * before a valid order exists. On success the iframe posts `thix:success`;
     * the controller writes the payment id to a hidden field and submits the
     * WooCommerce checkout so `process_payment` records + confirms the order.
     */
    private function inline_payment_fields() {
        $publishable = (string) $this->get_option('publishable_key');
        $api_base    = Thix_Api::normalize_base($this->get_option('api_base'));
        $cart        = WC()->cart;

        if ($publishable === '' || $api_base === '' || !$cart) {
            echo '<div class="thix-pay-fields"><div class="thix-pay-card"><p class="thix-pay-desc">'
                . esc_html__('Card payment is temporarily unavailable. Please contact the store.', 'thix-payments')
                . '</p></div></div>';
            return;
        }
        $currency = function_exists('get_woocommerce_currency') ? strtoupper((string) get_woocommerce_currency()) : '';
        // A store that also enables Coinbase can still route an EUR buyer to
        // the hosted on-ramp after they select it. Never mount the direct-card
        // iframe for that EUR cart, but keep the provider picker available.
        $can_use_coinbase = in_array('coinbase', $this->enabled_providers(), true);
        if ($currency !== 'USD' && !$can_use_coinbase) {
            echo '<div class="thix-pay-fields"><div class="thix-pay-card"><p class="thix-pay-desc">'
                . esc_html($this->unsupported_currency_message($currency, true))
                . '</p></div></div>';
            return;
        }

        $amount_cents = (int) round((float) $cart->get_total('edit') * 100);
        $token        = $this->inline_external_order_id($amount_cents);
        $email        = WC()->customer ? (string) WC()->customer->get_billing_email() : '';
        $brand        = $this->branding_args();
        $embed_src    = $api_base . '/public/embed.js';
        $lock_icon    = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>';

        static $printed_style = false;
        if (!$printed_style) {
            $printed_style = true;
            ?>
            <style>
                .thix-pay-fields{margin:10px 0 2px;font-family:inherit;-webkit-font-smoothing:antialiased;}
                .thix-pay-card{border:1px solid #e3e6ea;border-radius:14px;background:#fff;padding:18px 18px 16px;box-shadow:0 1px 3px rgba(16,24,40,.08);}
                .thix-pay-head{display:flex;align-items:center;gap:10px;margin:0 0 12px;}
                .thix-pay-title{font-weight:700;font-size:15px;color:#101828;letter-spacing:.1px;}
                .thix-pay-secure{margin-left:auto;display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#067647;background:#ecfdf3;border:1px solid #abefc6;padding:4px 10px;border-radius:999px;}
                .thix-pay-foot{display:flex;align-items:center;margin-top:12px;}
                .thix-pay-powered{margin-left:auto;font-size:11.5px;color:#98a2b3;display:inline-flex;align-items:center;gap:6px;}
                .thix-pay-dot{width:7px;height:7px;border-radius:50%;background:#2CE28D;display:inline-block;}
                .thix-inline-wrap{position:relative;min-height:120px;}
                .thix-inline-gate{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;padding:22px;background:rgba(255,255,255,.94);border:1px dashed #cfd4dc;border-radius:12px;color:#475467;font-size:13.5px;line-height:1.55;z-index:3;}
            </style>
            <?php
        }
        ?>
        <div class="thix-pay-fields">
            <div class="thix-pay-card">
                <div class="thix-pay-head">
                    <span class="thix-pay-title"><?php esc_html_e('Pay securely by card', 'thix-payments'); ?></span>
                    <span class="thix-pay-secure"><?php echo $lock_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e('Encrypted', 'thix-payments'); ?></span>
                </div>
                <?php $this->provider_picker_html(); ?>
                <input type="hidden" id="thix_payment_id" name="thix_payment_id" value="" />
                <input type="hidden" id="thix_eoid" name="thix_eoid" value="<?php echo esc_attr($token); ?>" />
                <div class="thix-inline-wrap">
                    <div
                        id="thix-inline-embed"
                        data-api-key="<?php echo esc_attr($publishable); ?>"
                        data-amount-cents="<?php echo esc_attr((string) $amount_cents); ?>"
                        data-order-id="<?php echo esc_attr($token); ?>"
                        data-email="<?php echo esc_attr($email); ?>"
                        data-currency="usd"
                        data-card-currency-supported="<?php echo $currency === 'USD' ? 'yes' : 'no'; ?>"
                        data-mode="card"
                        <?php if ($brand['theme'] !== '') : ?>data-theme="<?php echo esc_attr($brand['theme']); ?>"<?php endif; ?>
                        <?php if ($brand['accent'] !== '') : ?>data-accent="<?php echo esc_attr($brand['accent']); ?>"<?php endif; ?>
                        <?php if ($brand['logo'] !== '') : ?>data-logo="<?php echo esc_url($brand['logo']); ?>"<?php endif; ?>
                        <?php if ($brand['brand'] !== '') : ?>data-brand="<?php echo esc_attr($brand['brand']); ?>"<?php endif; ?>
                    ></div>
                    <div id="thix-inline-gate" class="thix-inline-gate">
                        <span><?php esc_html_e('Fill in your details above — your secure card form will appear here to complete payment.', 'thix-payments'); ?></span>
                    </div>
                </div>
                <div class="thix-pay-foot">
                    <span class="thix-pay-powered"><span class="thix-pay-dot"></span> <?php esc_html_e('Powered by 3thix', 'thix-payments'); ?></span>
                </div>
            </div>
        </div>
        <script src="<?php echo esc_url($embed_src); ?>"></script>
        <script>
        (function () {
            if (window.__thixInlineInit) { return; }
            window.__thixInlineInit = true;
            var $ = window.jQuery;
            var mountedKey = null;

            function api() { return window.ThixEmbed; }
            function container() { return document.getElementById('thix-inline-embed'); }
            function gate() { return document.getElementById('thix-inline-gate'); }
            function placeBtn() { return document.getElementById('place_order'); }
            function thixChosen() {
                var r = document.getElementById('payment_method_thix');
                return r ? !!r.checked : true;
            }
            // Buyer picked Coinbase in the provider selector: the inline card
            // iframe does not apply — they place the order normally and are
            // redirected to the hosted checkout with Coinbase preselected.
            function coinbaseChosen() {
                var r = document.querySelector('input[name="thix_provider"]:checked');
                return !!(r && r.value === 'coinbase');
            }
            function cardCurrencySupported() {
                var el = container();
                return !!(el && el.getAttribute('data-card-currency-supported') === 'yes');
            }

            // All VISIBLE required checkout fields must be filled before the card
            // form is enabled, so payment is never taken before a valid order.
            function requiredComplete() {
                var form = document.querySelector('form.checkout');
                if (!form) { return false; }
                var rows = form.querySelectorAll('.validate-required');
                for (var i = 0; i < rows.length; i++) {
                    var row = rows[i];
                    if (row.offsetParent === null) { continue; }
                    var field = row.querySelector('input, select, textarea');
                    if (!field) { continue; }
                    if (field.type === 'checkbox') {
                        if (!field.checked) { return false; }
                        continue;
                    }
                    if (String(field.value == null ? '' : field.value).trim() === '') { return false; }
                }
                return true;
            }

            function currentEmail() {
                var f = document.getElementById('billing_email');
                var v = f ? String(f.value || '').trim() : '';
                if (v) { return v; }
                var el = container();
                return el ? (el.getAttribute('data-email') || '') : '';
            }

            function mount() {
                var el = container();
                if (!el || !api()) { return; }
                var key = (el.getAttribute('data-amount-cents') || '') + '|' + (el.getAttribute('data-order-id') || '');
                if (el.getAttribute('data-thix-mounted') && mountedKey === key) { return; }
                try { el.innerHTML = ''; } catch (e) {}
                el.removeAttribute('data-thix-mounted');
                mountedKey = key;
                api().inline(el, {
                    apiKey: el.getAttribute('data-api-key'),
                    amountCents: parseInt(el.getAttribute('data-amount-cents'), 10) || 0,
                    orderId: el.getAttribute('data-order-id') || undefined,
                    email: currentEmail() || undefined,
                    mode: 'card',
                    currency: el.getAttribute('data-currency') || undefined,
                    theme: el.getAttribute('data-theme') || undefined,
                    accent: el.getAttribute('data-accent') || undefined,
                    logo: el.getAttribute('data-logo') || undefined,
                    brand: el.getAttribute('data-brand') || undefined,
                    minHeight: 320,
                    onSuccess: onPaid
                });
            }

            function onPaid(d) {
                var pid = (d && d.paymentId) ? d.paymentId : '';
                var h = document.getElementById('thix_payment_id');
                if (h) { h.value = pid; }
                var btn = placeBtn();
                if (btn) {
                    btn.disabled = false;
                    if ($) { $(btn).trigger('click'); } else { btn.click(); }
                } else if ($) {
                    $('form.checkout').trigger('submit');
                }
            }

            // Show/hide the WooCommerce Place Order button: in inline mode the buyer
            // pays inside the iframe, so Place Order is hidden while 3thix is chosen
            // and restored if they switch to another gateway.
            function syncPlaceBtn() {
                var btn = placeBtn();
                if (!btn) { return; }
                // Coinbase uses the normal Place Order → redirect flow, so the
                // button stays visible; the inline card iframe replaces it only
                // for the Stripe (card) provider.
                btn.style.display = (thixChosen() && cardCurrencySupported() && !coinbaseChosen()) ? 'none' : '';
            }

            var gateDefault = null;
            function refresh() {
                syncPlaceBtn();
                var el = container(), g = gate();
                if (!el) { return; }
                if (g && gateDefault === null) { gateDefault = g.innerHTML; }
                var wrap = el.closest ? el.closest('.thix-inline-wrap') : null;
                if (thixChosen() && coinbaseChosen()) {
                    if (wrap) { wrap.style.display = 'none'; }
                    return;
                }
                if (wrap) { wrap.style.display = ''; }
                if (g && gateDefault !== null) { g.innerHTML = gateDefault; }
                if (thixChosen() && !cardCurrencySupported()) {
                    if (g) {
                        g.style.display = '';
                        g.innerHTML = 'Direct card payments currently support USD orders only. Select Coinbase to continue with the secure on-ramp.';
                    }
                    return;
                }
                if (thixChosen() && requiredComplete()) {
                    if (g) { g.style.display = 'none'; }
                    mount();
                } else if (g) {
                    g.style.display = '';
                }
            }

            function boot() {
                refresh();
                if (!$) { return; }
                $(document.body).on('updated_checkout', function () { refresh(); });
                $(document).on('input change', 'form.checkout', function () { refresh(); });
                $(document.body).on('payment_method_selected', function () { refresh(); });
                document.addEventListener('thix:provider-change', function () { refresh(); });
                // Funds were captured in the iframe but WooCommerce could not place
                // the order (e.g. a late validation/stock error): tell the buyer to
                // contact the store rather than pay again.
                $(document.body).on('checkout_error', function () {
                    var h = document.getElementById('thix_payment_id');
                    var pid = h ? h.value : '';
                    if (pid) {
                        try { window.alert('Your payment was received (reference ' + pid + '), but we could not finish placing your order. Please contact the store to confirm — do not pay again.'); } catch (e) {}
                    }
                });
            }

            if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
            else { boot(); }
        })();
        </script>
        <?php
    }

    /**
     * Render the configured callback URL prominently above the settings table.
     */
    public function admin_options() {
        $enabled   = $this->get_option('enabled') === 'yes';
        $env_live  = $this->get_option('environment') === 'live';
        $site_name = (string) get_bloginfo('name');
        $callback  = rest_url('thix/v1/callback');

        // Sample line items so the live preview's order summary looks realistic.
        $sample = wp_json_encode([
            ['d' => __('Sample product', 'thix-payments'), 'q' => 1, 'u' => 4200],
            ['d' => __('Add-on item', 'thix-payments'),    'q' => 2, 'u' => 1500],
        ]);
        $sample_b64 = rtrim(strtr(base64_encode((string) $sample), '+/', '-_'), '=');
        ?>
        <div class="thix-admin">
            <div class="thix-admin-header">
                <div class="thix-admin-brand">
                    <span class="thix-admin-logo">3thix</span>
                    <div>
                        <div class="thix-admin-h1"><?php esc_html_e('3thix Payments', 'thix-payments'); ?></div>
                        <div class="thix-admin-sub"><?php esc_html_e('Card &amp; stablecoin checkout for WooCommerce', 'thix-payments'); ?></div>
                    </div>
                </div>
                <div class="thix-admin-pills">
                    <span class="thix-pill <?php echo $enabled ? 'on' : 'off'; ?>"><?php echo $enabled ? esc_html__('Enabled', 'thix-payments') : esc_html__('Disabled', 'thix-payments'); ?></span>
                    <span class="thix-pill env"><?php echo $env_live ? esc_html__('Live', 'thix-payments') : esc_html__('Test', 'thix-payments'); ?></span>
                </div>
            </div>

            <div class="thix-admin-grid">
                <div class="thix-admin-main">
                    <div class="notice notice-info inline thix-crypto-only"><p><strong><?php esc_html_e('Callback URL', 'thix-payments'); ?>:</strong> <code><?php echo esc_html($callback); ?></code><br><?php esc_html_e('Paste this into the "Store callbacks" field on your merchant page in the 3thix console so paid/released events reach this store.', 'thix-payments'); ?></p></div>
                    <table class="form-table thix-settings"><?php $this->generate_settings_html(); ?></table>
                </div>

                <aside class="thix-admin-side">
                    <div class="thix-preview-card">
                        <div class="thix-preview-top">
                            <span class="d"></span><span class="d"></span><span class="d"></span>
                            <span class="thix-preview-label"><?php esc_html_e('Live checkout preview', 'thix-payments'); ?></span>
                        </div>
                        <div class="thix-preview-viewport">
                            <iframe id="thix-preview" title="<?php esc_attr_e('3thix checkout preview', 'thix-payments'); ?>" loading="lazy"></iframe>
                        </div>
                        <div class="thix-preview-foot">
                            <span><?php esc_html_e('Sample data &middot; updates as you edit', 'thix-payments'); ?></span>
                            <a id="thix-preview-open" target="_blank" rel="noopener"><?php esc_html_e('Open &#8599;', 'thix-payments'); ?></a>
                        </div>
                    </div>
                    <p class="thix-preview-note"><?php esc_html_e('This is how buyers will see your checkout. Adjust the Branding settings to match your store.', 'thix-payments'); ?></p>
                </aside>
            </div>
        </div>

        <style>
        .thix-admin{max-width:1440px;margin-top:8px;}
        .thix-admin *{box-sizing:border-box;}
        .thix-admin-header{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:linear-gradient(135deg,#0b0f15 0%,#121b24 100%);border:1px solid #1c2634;border-radius:16px;padding:18px 22px;margin:0 0 20px;}
        .thix-admin-brand{display:flex;align-items:center;gap:14px;}
        .thix-admin-logo{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 15px;border-radius:11px;background:#2CE28D;color:#06281b;font-weight:800;font-size:17px;letter-spacing:.5px;}
        .thix-admin-h1{font-size:19px;font-weight:700;line-height:1.2;color:#fff;}
        .thix-admin-sub{font-size:13px;color:#93a1ad;margin-top:3px;}
        .thix-admin-pills{margin-left:auto;display:flex;gap:8px;}
        .thix-pill{font-size:12px;font-weight:700;padding:6px 13px;border-radius:999px;background:#18212c;color:#c7d0da;border:1px solid #2a3444;}
        .thix-pill.on{background:rgba(44,226,141,.14);color:#2CE28D;border-color:rgba(44,226,141,.42);}
        .thix-pill.off{background:rgba(255,120,120,.12);color:#ff9b9b;border-color:rgba(255,120,120,.36);}
        .thix-admin-grid{display:flex;gap:24px;align-items:flex-start;}
        .thix-admin-main{flex:1 1 auto;min-width:0;background:#fff;border:1px solid #e3e7eb;border-radius:16px;padding:6px 24px 20px;box-shadow:0 1px 3px rgba(16,24,40,.06);}
        .thix-admin-side{flex:0 0 460px;position:sticky;top:46px;}
        @media (max-width:1180px){.thix-admin-grid{flex-direction:column;}.thix-admin-side{flex-basis:auto;width:100%;max-width:520px;position:static;}}
        .thix-settings h3{font-size:12px;text-transform:uppercase;letter-spacing:.7px;color:#0b0f15;font-weight:700;margin:26px 0 0;padding-top:18px;border-top:1px solid #eef1f4;}
        .thix-settings h3:first-of-type{border-top:0;padding-top:6px;margin-top:6px;}
        .thix-settings p.description{color:#667085;font-size:12.5px;}
        .thix-settings th{color:#344054;font-weight:600;font-size:13.5px;padding-left:0;vertical-align:top;padding-top:20px;}
        .thix-settings td{padding-top:14px;}
        .thix-settings input[type=text],.thix-settings input[type=password],.thix-settings input[type=email],.thix-settings input[type=url],.thix-settings textarea,.thix-settings select{width:100%;max-width:460px;border:1px solid #d0d5dd;border-radius:9px;padding:9px 12px;min-height:40px;font-size:13.5px;box-shadow:0 1px 2px rgba(16,24,40,.04);}
        .thix-settings textarea{min-height:72px;line-height:1.5;}
        .thix-settings input[type=text]:focus,.thix-settings input[type=password]:focus,.thix-settings input[type=email]:focus,.thix-settings input[type=url]:focus,.thix-settings textarea:focus,.thix-settings select:focus{border-color:#2CE28D;box-shadow:0 0 0 3px rgba(44,226,141,.18);outline:0;}
        .thix-admin .thix-crypto-only{border-radius:10px !important;margin:14px 0 !important;}
        .thix-preview-card{background:#fff;border:1px solid #e3e7eb;border-radius:16px;overflow:hidden;box-shadow:0 12px 34px rgba(16,24,40,.12);}
        .thix-preview-top{display:flex;align-items:center;gap:7px;padding:12px 16px;background:#0b0f15;}
        .thix-preview-top .d{width:11px;height:11px;border-radius:50%;background:#39424f;}
        .thix-preview-top .d:nth-child(1){background:#ff5f57;}
        .thix-preview-top .d:nth-child(2){background:#febc2e;}
        .thix-preview-top .d:nth-child(3){background:#28c840;}
        .thix-preview-label{margin-left:10px;color:#c7d0da;font-size:12px;font-weight:600;}
        .thix-preview-viewport{position:relative;width:100%;height:472px;background:#eef0f3;overflow:hidden;}
        #thix-preview{position:absolute;top:0;left:0;width:1150px;height:1180px;border:0;background:#fff;transform:scale(.4);transform-origin:top left;}
        .thix-preview-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;background:#fff;border-top:1px solid #eef1f4;font-size:12px;color:#98a2b3;}
        .thix-preview-foot a{color:#12b76a;font-weight:700;text-decoration:none;}
        .thix-preview-foot a:hover{text-decoration:underline;}
        .thix-preview-note{color:#667085;font-size:12px;line-height:1.5;margin:12px 2px 0;}
        </style>

        <script>
        (function ($) {
            // Callbacks are a crypto (on-ramp / escrow) concept only — card merchants
            // never receive them — so the signing-secret row + notice show only for on-ramp.
            var $mode = $('#woocommerce_thix_mode');
            if ($mode.length) {
                var $secretRow = $('#woocommerce_thix_signing_secret').closest('tr');
                var $notice = $('.thix-crypto-only');
                var syncMode = function () {
                    var show = $mode.val() === 'onramp';
                    $secretRow.toggle(show);
                    $notice.toggle(show);
                };
                $mode.on('change', syncMode);
                syncMode();
            }

            // Live checkout preview. Mirrors branding_args()/summary params so the
            // iframe reflects exactly what buyers will see. All values are re-validated
            // by the hosted checkout, so this is display-only.
            var SITE = <?php echo wp_json_encode($site_name); ?>;
            var STORE_LOGO = <?php echo wp_json_encode((string) $this->store_logo_url()); ?>;
            var ITEMS = <?php echo wp_json_encode($sample_b64); ?>;
            var AMOUNT = 7200, SUBTOTAL = 7200;
            var $frame = $('#thix-preview'), $open = $('#thix-preview-open');
            if (!$frame.length) return;

            var val = function (id) {
                var el = document.getElementById('woocommerce_thix_' + id);
                return el ? String(el.value || '').trim() : '';
            };
            var build = function () {
                var base = (val('api_base') || 'https://pay.3thix.com/api').replace(/\/+$/, '');
                if (!/^https?:\/\//i.test(base)) base = 'https://pay.3thix.com/api';
                var theme = val('checkout_theme') === 'dark' ? 'dark' : 'light';
                var p = new URLSearchParams();
                p.set('amount', AMOUNT);
                p.set('order', 'preview');
                p.set('key', val('publishable_key') || 'thx_test_pk_preview');
                p.set('theme', theme);
                if (val('mode') === 'card') p.set('mode', 'card');
                var ac = val('accent_color');
                if (ac) {
                    if (ac.charAt(0) !== '#') ac = '#' + ac;
                    if (/^#[0-9a-fA-F]{6}$/.test(ac)) p.set('accent', ac);
                }
                var brand = theme === 'light' ? SITE : '';
                if (brand) p.set('brand', brand);
                // Mirror branding_args(): "match" (light) auto-uses the store logo; a manual https logo always wins.
                var logo = (theme === 'light' && STORE_LOGO) ? STORE_LOGO : '';
                var manual = val('brand_logo');
                if (manual.indexOf('https://') === 0) logo = manual;
                if (logo.indexOf('https://') === 0) p.set('logo', logo);
                p.set('subtotal', SUBTOTAL);
                p.set('shipping', '0');
                p.set('discount', '0');
                p.set('items', ITEMS);
                return base + '/checkout?' + p.toString();
            };
            var last = '';
            var refresh = function () {
                var url = build();
                if (url === last) return;
                last = url;
                $frame.attr('src', url);
                $open.attr('href', url);
            };
            var timer;
            var debounced = function () { clearTimeout(timer); timer = setTimeout(refresh, 450); };
            $(document).on('input change',
                '#woocommerce_thix_api_base,#woocommerce_thix_checkout_theme,#woocommerce_thix_mode,#woocommerce_thix_brand_logo,#woocommerce_thix_accent_color,#woocommerce_thix_publishable_key',
                debounced);
            refresh();
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Resolve the merchant branding to pass to the 3thix checkout so it blends
     * into the store. Returns a sanitized array { theme, accent, logo, brand }:
     *
     * - "Match my store" mode → light theme + auto-pulled site name and logo.
     * - "3thix (black & green)" mode → dark theme, no auto-brand (unless a logo
     *   URL is explicitly configured).
     * - Manual Logo URL / Accent color settings always override the auto values.
     *
     * Every value is re-validated by the hosted checkout (hex accent, https logo,
     * text brand), so these are hints, not trusted input.
     *
     * @return array{theme:string,accent:string,logo:string,brand:string}
     */
    private function branding_args() {
        $out = ['theme' => '', 'accent' => '', 'logo' => '', 'brand' => ''];

        if ($this->get_option('checkout_theme', 'match') === 'match') {
            $out['theme'] = 'light';
            $out['brand'] = (string) get_bloginfo('name');
            $out['logo']  = $this->store_logo_url();
        }

        $manual_logo = trim((string) $this->get_option('brand_logo', ''));
        if ($manual_logo !== '') {
            // Only pass real https URLs through (the hosted page rejects others).
            $manual_logo = esc_url_raw($manual_logo);
            if (strpos($manual_logo, 'https://') === 0) {
                $out['logo'] = $manual_logo;
            }
        }

        $accent = trim((string) $this->get_option('accent_color', ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $out['accent'] = $accent;
        }

        return $out;
    }

    /**
     * Build the optional Shopify-style order-summary args for the hosted checkout
     * (DISPLAY-ONLY — the amount actually charged is always the order total).
     *
     * Returns { items, subtotal, shipping, tax, discount } where `items` is a
     * base64url-encoded JSON array of line items [{ d:name, q:qty, u:unitCents,
     * img:httpsImageUrl, v:variant }] and the rest are integer cents. Product
     * images are only included when they are https (the hosted page rejects
     * non-https image URLs). Capped at 50 line items to keep the URL bounded.
     *
     * @param WC_Order $order
     * @return array{items:string,subtotal:int,shipping:int,tax:int,discount:int}
     */
    private function order_summary_args($order) {
        $items = [];
        $count = 0;
        foreach ($order->get_items() as $item) {
            if ($count >= 50) {
                break;
            }
            $count++;

            $qty = max(1, (int) $item->get_quantity());
            // Line subtotal is pre-discount; unit = line / qty. Order-level
            // discounts are surfaced separately in the breakdown below.
            $line_cents = (int) round((float) $item->get_subtotal() * 100);
            $unit_cents = (int) round($line_cents / $qty);

            $entry = [
                'd' => mb_substr(wp_strip_all_tags((string) $item->get_name()), 0, 120),
                'q' => $qty,
                'u' => $unit_cents,
            ];

            $product = is_callable([$item, 'get_product']) ? $item->get_product() : null;
            if ($product) {
                $img_id = (int) $product->get_image_id();
                if ($img_id > 0) {
                    $img_url = wp_get_attachment_image_url($img_id, 'woocommerce_thumbnail');
                    if (is_string($img_url) && strpos($img_url, 'https://') === 0) {
                        $entry['img'] = $img_url;
                    }
                }
            }

            $items[] = $entry;
        }

        // base64url (the hosted page decodes -_ back to +/ and re-pads).
        $json = wp_json_encode($items);
        $encoded = $json ? rtrim(strtr(base64_encode($json), '+/', '-_'), '=') : '';

        return [
            'items'    => $encoded,
            'subtotal' => (int) round((float) $order->get_subtotal() * 100),
            'shipping' => (int) round((float) $order->get_shipping_total() * 100),
            'tax'      => (int) round((float) $order->get_total_tax() * 100),
            'discount' => (int) round((float) $order->get_total_discount() * 100),
        ];
    }

    /**
     * Best-effort URL for the store's logo (custom logo first, then site icon).
     *
     * @return string An https logo URL, or '' if none / not https.
     */
    private function store_logo_url() {
        $url = '';
        $logo_id = (int) get_theme_mod('custom_logo');
        if ($logo_id > 0) {
            $img = wp_get_attachment_image_src($logo_id, 'full');
            if (is_array($img) && !empty($img[0])) {
                $url = (string) $img[0];
            }
        }
        if ($url === '') {
            $url = (string) get_site_icon_url();
        }
        // The hosted checkout only renders https logos; drop anything else.
        return strpos($url, 'https://') === 0 ? $url : '';
    }

    /**
     * Return the currency frozen on this WooCommerce order, not the merchant's
     * current store setting. WooCommerce persists it at order creation, keeping
     * delayed checkout retries bound to the original order currency.
     */
    private function source_currency($order) {
        return strtoupper((string) $order->get_currency());
    }

    /**
     * Bind a 3thix response to the immutable WooCommerce order that initiated
     * it. `amountCents` on 3thix is canonical USD, while WooCommerce totals are
     * in the original storefront currency, so EUR completion must use the
     * source fields instead. A response without source fields is a pre-FX
     * payload and is only safe for an exact USD/canonical match.
     */
    private function source_amount_matches($order, $payment, $allow_legacy) {
        $expected_currency = $this->source_currency($order);
        $expected_cents    = (int) round((float) $order->get_total() * 100);
        $has_currency      = array_key_exists('sourceCurrency', $payment);
        $has_amount        = array_key_exists('sourceAmountCents', $payment);

        if ($has_currency || $has_amount) {
            if (!$has_currency || !$has_amount
                || !is_string($payment['sourceCurrency'])
                || strtoupper($payment['sourceCurrency']) !== $expected_currency) {
                return false;
            }
            // Zero is the old database sentinel. Its only valid interpretation
            // is a legacy USD payment whose canonical amount exactly matches.
            if ($payment['sourceAmountCents'] === 0) {
                return $expected_currency === 'USD'
                    && isset($payment['currency'], $payment['amountCents'])
                    && strtolower((string) $payment['currency']) === 'usd'
                    && $payment['amountCents'] === $expected_cents;
            }
            return $payment['sourceAmountCents'] === $expected_cents;
        }

        return $allow_legacy
            && $expected_currency === 'USD'
            && isset($payment['currency'], $payment['amountCents'])
            && strtolower((string) $payment['currency']) === 'usd'
            && $payment['amountCents'] === $expected_cents;
    }

    private function expected_rail($order) {
        $rail = (string) $order->get_meta('_thix_rail');
        return in_array($rail, ['card', 'onramp'], true) ? $rail : '';
    }

    /** Validate a secret payment lookup/sync response before fulfillment. */
    public function payment_matches_order($order, $payment) {
        if (!$order || !is_array($payment) || $order->get_payment_method() !== $this->id) {
            return false;
        }
        $expected_eoid = (string) $order->get_meta('_thix_eoid');
        if ($expected_eoid === '') { $expected_eoid = (string) $order->get_id(); }
        if (!isset($payment['externalOrderId']) || !is_string($payment['externalOrderId'])
            || !hash_equals($expected_eoid, $payment['externalOrderId'])) {
            return false;
        }
        $expected_rail = $this->expected_rail($order);
        if (!isset($payment['rail']) || !is_string($payment['rail'])
            || !in_array($payment['rail'], ['card', 'onramp'], true)
            || ($expected_rail !== '' && $payment['rail'] !== $expected_rail)) {
            return false;
        }
        return $this->source_amount_matches($order, $payment, false);
    }

    /** Validate a signed callback, including the legacy USD-only callback form. */
    public function callback_matches_order($order, $payload) {
        if (!$order || !is_array($payload) || $order->get_payment_method() !== $this->id) {
            return false;
        }
        $expected_eoid = (string) $order->get_meta('_thix_eoid');
        if ($expected_eoid === '') { $expected_eoid = (string) $order->get_id(); }
        if (!isset($payload['externalOrderId']) || !is_string($payload['externalOrderId'])
            || !hash_equals($expected_eoid, $payload['externalOrderId'])) {
            return false;
        }
        $expected_rail = $this->expected_rail($order);
        if (isset($payload['rail'])
            && (!is_string($payload['rail'])
                || !in_array($payload['rail'], ['card', 'onramp'], true)
                || ($expected_rail !== '' && $payload['rail'] !== $expected_rail))) {
            return false;
        }
        // New source-bound callbacks must also name their rail. The missing-rail
        // exception is reserved for the historical USD-only payload.
        if ((array_key_exists('sourceCurrency', $payload) || array_key_exists('sourceAmountCents', $payload))
            && !isset($payload['rail'])) {
            return false;
        }
        return $this->source_amount_matches($order, $payload, true);
    }

    private function supports_source_currency($currency) {
        return in_array(strtoupper((string) $currency), ['USD', 'EUR'], true);
    }

    private function direct_card_requires_usd($provider) {
        return $this->get_option('mode') === 'card' && $provider !== 'coinbase';
    }

    private function unsupported_currency_message($currency, $direct_card = false) {
        if ($direct_card) {
            return sprintf(
                /* translators: %s: ISO store currency */
                __('3thix direct card payments currently support USD orders only. This order is in %s and was not sent for payment. Choose the on-ramp rail for supported EUR orders.', 'thix-payments'),
                $currency !== '' ? $currency : __('an unknown currency', 'thix-payments')
            );
        }
        return sprintf(
            /* translators: %s: ISO store currency */
            __('3thix Payments supports USD and EUR orders only. This order is in %s and was not sent for payment.', 'thix-payments'),
            $currency !== '' ? $currency : __('an unknown currency', 'thix-payments')
        );
    }

    /**
     * Redirect the buyer to the 3thix hosted checkout for the exact order total.
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', 'thix-payments'), 'error');
            return ['result' => 'failure'];
        }
        if (!$this->checkout_runtime_ready()) {
            wc_add_notice(__('3thix Payments is not fully configured. Please contact the store.', 'thix-payments'), 'error');
            return ['result' => 'failure'];
        }
        $currency = $this->source_currency($order);
        if (!$this->supports_source_currency($currency)) {
            wc_add_notice($this->unsupported_currency_message($currency), 'error');
            return ['result' => 'failure'];
        }

        $publishable = trim((string) $this->get_option('publishable_key'));
        $api_base = (string) $this->get_option('api_base');
        if ($api_base === '') {
            wc_add_notice(__('3thix Payments is not fully configured. Please contact the store.', 'thix-payments'), 'error');
            return ['result' => 'failure'];
        }

        // Which provider the buyer picked (Stripe vs Coinbase). Recorded on the
        // order and forwarded to the hosted checkout so the matching flow starts.
        $provider = $this->chosen_provider();
        if ($this->direct_card_requires_usd($provider) && $currency !== 'USD') {
            wc_add_notice($this->unsupported_currency_message($currency, true), 'error');
            return ['result' => 'failure'];
        }
        $order->update_meta_data('_thix_provider', $provider);
        $order->update_meta_data(
            '_thix_rail',
            ($this->get_option('mode') === 'card' && $provider !== 'coinbase') ? 'card' : 'onramp'
        );

        // Inline card form: the buyer already entered + paid by card inside the
        // 3thix iframe on the checkout page. The iframe returned a payment id which
        // our checkout controller submitted with the order; record + confirm it
        // here, then send the buyer straight to the thank-you page (no redirect to
        // a hosted page and no order-pay step).
        // A Coinbase pick skips the inline branch entirely — Coinbase cannot run
        // in the inline card iframe, so it always uses the redirect/embed flow.
        if ($this->is_inline() && $provider !== 'coinbase') {
            $posted_pid = self::posted_checkout_field('thix_payment_id');
            // Classic checkout always takes the inline path. On the BLOCK
            // checkout the native blocks integration (assets/blocks.js) posts
            // the payment id through the Store API's payment_data; if it's
            // absent (e.g. the blocks script could not register on an old
            // WooCommerce), fall through to the redirect flow instead of
            // dead-ending the buyer with a "complete the card payment" error.
            if ($posted_pid !== '' || !$this->checkout_uses_blocks()) {
                return $this->process_inline_payment($order);
            }
        }

        // Order stays "pending" until the signed order.paid callback arrives or
        // the buyer-return reconciliation confirms it.
        $order->update_status('pending', __('Awaiting 3thix payment.', 'thix-payments'));

        // Embedded (opt-in): keep the buyer on the store. Send them to the
        // WooCommerce order-pay page, where `receipt_page()` renders the 3thix
        // checkout inside a secure iframe. Redirect is the default (cleaner
        // full-page checkout), so this branch only runs when explicitly chosen.
        if ($this->get_option('display') !== 'redirect') {
            $order->add_order_note(__('Customer shown embedded 3thix checkout on the order-pay page.', 'thix-payments'));
            return [
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            ];
        }

        // Redirect: send the buyer to the 3thix hosted checkout page.
        $amount_cents = (int) round((float) $order->get_total() * 100);
        $brand = $this->branding_args();
        $summary = $this->order_summary_args($order);
        $url = $this->api()->checkout_url([
            'publishable_key' => $publishable,
            'environment'     => $this->configured_environment(),
            'amount_cents'    => $amount_cents,
            'currency'        => $currency,
            'order_id'        => (string) $order->get_id(),
            'email'           => $order->get_billing_email(),
            'redirect'        => $this->get_return_url($order),
            // Coinbase lives on the on-ramp page, so a Coinbase pick never forces
            // card mode; the server still routes direct merchants to card.
            'mode'            => ($this->get_option('mode') === 'card' && $provider !== 'coinbase') ? 'card' : '',
            'provider'        => $provider === 'coinbase' ? 'coinbase' : '',
            'theme'           => $brand['theme'],
            'accent'          => $brand['accent'],
            'logo'            => $brand['logo'],
            'brand'           => $brand['brand'],
            'items'           => $summary['items'],
            'subtotal'        => $summary['subtotal'],
            'shipping'        => $summary['shipping'],
            'tax'             => $summary['tax'],
            'discount'        => $summary['discount'],
        ]);
        $order->add_order_note(__('Customer redirected to 3thix hosted checkout.', 'thix-payments'));

        return [
            'result'   => 'success',
            'redirect' => $url,
        ];
    }

    /**
     * Record + confirm an inline card payment (display = inline). The buyer paid
     * inside the 3thix card iframe on the checkout page; our controller wrote the
     * resulting payment id to a hidden field and submitted the WooCommerce order.
     * Here we persist the client token (for callback/return matching), sync the
     * payment authoritatively, enforce that the charged amount equals the order
     * total, and complete the order (or hold it for review on a mismatch).
     *
     * @param WC_Order $order
     * @return array WooCommerce process_payment result.
     */
    private function process_inline_payment($order) {
        // This method is intentionally guarded as well as process_payment():
        // Blocks/third-party checkout integrations can invoke the legacy
        // gateway method directly, and stale settings must never allow a card
        // session to be accepted without a complete key configuration.
        if (!$this->checkout_runtime_ready()) {
            wc_add_notice(__('3thix Payments is not fully configured. Please contact the store.', 'thix-payments'), 'error');
            return ['result' => 'failure'];
        }
        // Defense in depth: inline is a direct-card capture flow and must never
        // create or accept a non-USD payment. process_payment() performs the
        // same authoritative check before routing here.
        if ($this->source_currency($order) !== 'USD') {
            wc_add_notice($this->unsupported_currency_message($this->source_currency($order), true), 'error');
            return ['result' => 'failure'];
        }
        // WooCommerce verifies its own checkout nonce (and the Store API its
        // own request) before dispatching to process_payment(), so these POST
        // fields (submitted by our controller after the iframe's thix:success,
        // or bridged from the block checkout's payment_data) are trusted here;
        // still sanitized. Accepts both classic and Store API key names.
        $payment_id = self::posted_checkout_field('thix_payment_id');
        $token      = self::posted_checkout_field('thix_eoid');

        if ($payment_id === '' || $token === '') {
            wc_add_notice(__('Please complete the secure card payment above before placing your order.', 'thix-payments'), 'error');
            return ['result' => 'failure'];
        }

        // Fund safety — session binding: the token is minted server-side (per this
        // buyer's WooCommerce session, keyed to the cart total) and the 3thix order
        // is created fresh under it. The posted token MUST match that server session
        // value; a client cannot forge it. Without this, a buyer could post a
        // matched foreign (token + payment id) pair of the same amount from a prior
        // paid order and be fulfilled without a new charge. Reject any mismatch.
        $session       = WC()->session;
        $stored        = $session ? $session->get('thix_inline_eoid') : null;
        $session_token = (is_array($stored) && !empty($stored['token'])) ? (string) $stored['token'] : '';
        if ($session_token === '' || !hash_equals($session_token, $token)) {
            $order->add_order_note(__('3thix inline payment rejected: checkout token did not match this session. No charge applied.', 'thix-payments'));
            $order->save();
            wc_add_notice(__('Your secure card session has expired. Please refresh the page and try again.', 'thix-payments'), 'error');
            return ['result' => 'failure'];
        }

        // The 3thix order was created under the client token, not the numeric
        // WooCommerce order id, so persist the token for the signed callback and
        // the buyer-return reconcile to match back to this order.
        $order->update_meta_data('_thix_eoid', $token);
        $order->update_status('pending', __('Awaiting 3thix inline card payment confirmation.', 'thix-payments'));

        $paid_statuses = ['SUCCEEDED', 'PARTIALLY_REFUNDED'];
        $synced        = $this->api()->sync_payment($payment_id);
        $status        = is_array($synced) ? (string) ($synced['status'] ?? '') : '';

        // Fund safety — payment binding: the client posts `thix_payment_id`, so we
        // MUST NOT trust it on its own. The 3thix payment carries the
        // `externalOrderId` it was created under; it has to match this checkout's
        // server-issued token. Otherwise a buyer could post a stranger's (or a
        // prior, same-amount) payment id to fulfil this order without a real
        // charge. Reject any payment that isn't bound to this token.
        $bound_eoid = (is_array($synced) && isset($synced['externalOrderId']))
            ? (string) $synced['externalOrderId']
            : '';
        // Fail closed: a synced payment MUST expose an externalOrderId that matches
        // this token. A synced-but-unlabelled payment ($bound_eoid === '') is
        // treated as a mismatch and rejected rather than trusted. (A null sync —
        // e.g. finalization lag — skips this and stays pending for reconcile, which
        // resolves the payment authoritatively from the token.)
        if (is_array($synced) && !hash_equals($token, $bound_eoid)) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: 3thix payment id, 2: payment's externalOrderId, 3: this checkout token */
                    __('3thix inline payment rejected: payment %1$s belongs to order token %2$s, not this checkout (%3$s). Possible tampering — no charge applied to this order.', 'thix-payments'),
                    $payment_id,
                    $bound_eoid,
                    $token
                )
            );
            $order->save();
            wc_add_notice(__('We could not verify your card payment for this order. Please try again.', 'thix-payments'), 'error');
            return ['result' => 'failure'];
        }

        // Fund safety: the charged amount MUST equal this order's total. The amount
        // is server-rendered from the cart, but never trust the client — a tampered
        // charge is held for manual review rather than silently fulfilled.
        $order_cents   = (int) round((float) $order->get_total() * 100);
        $charged_cents = (is_array($synced) && isset($synced['amountCents'])) ? (int) $synced['amountCents'] : null;
        if ($charged_cents !== null && $charged_cents !== $order_cents) {
            $order->update_meta_data('_thix_hold_review', '1');
            $order->set_transaction_id($payment_id);
            $order->update_status(
                'on-hold',
                sprintf(
                    /* translators: 1: charged amount in cents, 2: expected order total in cents, 3: 3thix payment id */
                    __('3thix inline payment amount mismatch: charged %1$d¢, order total %2$d¢ (payment %3$s). Held for manual review — do NOT fulfil until reconciled.', 'thix-payments'),
                    $charged_cents,
                    $order_cents,
                    $payment_id
                )
            );
            $order->save();
            $this->clear_inline_token();
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }
        if (is_array($synced) && !$this->payment_matches_order($order, $synced)) {
            $order->add_order_note(__('3thix inline payment rejected: payment binding did not match this order. No charge applied to this order.', 'thix-payments'));
            $order->save();
            wc_add_notice(__('We could not verify your card payment for this order. Please try again.', 'thix-payments'), 'error');
            return ['result' => 'failure'];
        }

        if (in_array($status, $paid_statuses, true)) {
            $this->complete_thix_order($order, $payment_id);
        } else {
            // Rare finalization lag: keep the order pending and let the signed
            // callback / thank-you reconcile finish it (both resolve the payment
            // authoritatively from `_thix_eoid`). Only record the posted id when we
            // could verify it belongs to this token ($synced present ⇒ binding was
            // checked above); if sync returned nothing, don't trust the client id —
            // reconcile will set the transaction id from the token lookup instead.
            if (is_array($synced)) {
                $order->set_transaction_id($payment_id);
            }
            $order->add_order_note(__('3thix inline card payment submitted; awaiting confirmation.', 'thix-payments'));
            $order->save();
        }

        $this->clear_inline_token();
        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    /**
     * Drop the cached inline checkout token so the buyer's next purchase mints a
     * fresh 3thix order instead of reusing this one.
     */
    private function clear_inline_token() {
        if (WC()->session) {
            WC()->session->__unset('thix_inline_eoid');
        }
    }

    /**
     * Render the 3thix embedded checkout on the WooCommerce order-pay/receipt
     * page (the `woocommerce_receipt_{id}` hook). The buyer completes payment in
     * a secure cross-origin 3thix iframe without leaving the store; on success
     * the embed loader redirects them to the WooCommerce thank-you page, where
     * `reconcile_on_return()` (and/or the signed callback) marks the order paid.
     *
     * Card data and the on-ramp run inside 3thix's frame, so the store never
     * touches card details (PCI scope stays with 3thix/Stripe).
     *
     * @param int $order_id WooCommerce order id.
     */
    public function receipt_page($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        if (!$this->checkout_runtime_ready()) {
            echo '<p>' . esc_html__('3thix Payments is not fully configured. Please contact the store.', 'thix-payments') . '</p>';
            return;
        }
        $currency = $this->source_currency($order);
        if (!$this->supports_source_currency($currency)) {
            echo '<p>' . esc_html($this->unsupported_currency_message($currency)) . '</p>';
            return;
        }

        $publishable = trim((string) $this->get_option('publishable_key'));
        $api_base = Thix_Api::normalize_base($this->get_option('api_base'));
        if ($publishable === '' || $api_base === '') {
            echo '<p>' . esc_html__('3thix Payments is not fully configured. Please contact the store.', 'thix-payments') . '</p>';
            return;
        }

        $amount_cents = (int) round((float) $order->get_total() * 100);
        // Provider the buyer picked at checkout (stored by process_payment).
        // Coinbase runs on the on-ramp page, so it never uses card mode here.
        $provider = (string) $order->get_meta('_thix_provider');
        $is_coinbase = $provider === 'coinbase' && in_array('coinbase', $this->enabled_providers(), true);
        $is_card = $this->get_option('mode') === 'card' && !$is_coinbase;
        if ($is_card && $currency !== 'USD') {
            echo '<p>' . esc_html($this->unsupported_currency_message($currency, true)) . '</p>';
            return;
        }
        $return_url = $this->get_return_url($order);
        $embed_src = $api_base . '/public/embed.js';
        $brand = $this->branding_args();
        $summary = $this->order_summary_args($order);

        // Fallback to the hosted page if the iframe can't load (e.g. CSP/extension).
        $hosted_url = $this->api()->checkout_url([
            'publishable_key' => $publishable,
            'environment'     => $this->configured_environment(),
            'amount_cents'    => $amount_cents,
            'currency'        => $currency,
            'order_id'        => (string) $order->get_id(),
            'email'           => $order->get_billing_email(),
            'redirect'        => $return_url,
            'mode'            => $is_card ? 'card' : '',
            'provider'        => $is_coinbase ? 'coinbase' : '',
            'theme'           => $brand['theme'],
            'accent'          => $brand['accent'],
            'logo'            => $brand['logo'],
            'brand'           => $brand['brand'],
            'items'           => $summary['items'],
            'subtotal'        => $summary['subtotal'],
            'shipping'        => $summary['shipping'],
            'tax'             => $summary['tax'],
            'discount'        => $summary['discount'],
        ]);
        ?>
        <div class="thix-checkout-step" style="max-width:940px;margin:24px auto 0;text-align:center;">
            <h2 style="margin:0 0 6px;font-size:20px;line-height:1.3;"><?php esc_html_e('Complete your payment', 'thix-payments'); ?></h2>
            <p style="margin:0 0 18px;color:#475467;font-size:14px;line-height:1.5;">
                <?php
                printf(
                    /* translators: %s: the WooCommerce order number */
                    esc_html__('You\'re almost done — pay securely below to place order #%s.', 'thix-payments'),
                    esc_html((string) $order->get_order_number())
                );
                ?>
            </p>
        </div>
        <div class="thix-embed-wrap" style="max-width:940px;margin:0 auto;">
            <div
                data-thix-embed
                data-api-key="<?php echo esc_attr($publishable); ?>"
                data-amount-cents="<?php echo esc_attr((string) $amount_cents); ?>"
                data-order-id="<?php echo esc_attr((string) $order->get_id()); ?>"
                data-email="<?php echo esc_attr((string) $order->get_billing_email()); ?>"
                data-redirect="<?php echo esc_url($return_url); ?>"
                data-currency="<?php echo esc_attr(strtolower($currency)); ?>"
                <?php if ($is_card) : ?>data-mode="card"<?php endif; ?>
                <?php if ($is_coinbase) : ?>data-provider="coinbase"<?php endif; ?>
                <?php if ($brand['theme'] !== '') : ?>data-theme="<?php echo esc_attr($brand['theme']); ?>"<?php endif; ?>
                <?php if ($brand['accent'] !== '') : ?>data-accent="<?php echo esc_attr($brand['accent']); ?>"<?php endif; ?>
                <?php if ($brand['logo'] !== '') : ?>data-logo="<?php echo esc_url($brand['logo']); ?>"<?php endif; ?>
                <?php if ($brand['brand'] !== '') : ?>data-brand="<?php echo esc_attr($brand['brand']); ?>"<?php endif; ?>
                <?php if ($summary['items'] !== '') : ?>data-items="<?php echo esc_attr($summary['items']); ?>"<?php endif; ?>
                data-subtotal="<?php echo esc_attr((string) $summary['subtotal']); ?>"
                data-shipping="<?php echo esc_attr((string) $summary['shipping']); ?>"
                data-tax="<?php echo esc_attr((string) $summary['tax']); ?>"
                data-discount="<?php echo esc_attr((string) $summary['discount']); ?>"
            ></div>
            <p style="text-align:center;margin-top:14px;font-size:13px;">
                <?php
                printf(
                    /* translators: %s: link to the hosted 3thix checkout page */
                    esc_html__('Trouble loading the checkout? %s', 'thix-payments'),
                    '<a href="' . esc_url($hosted_url) . '">' . esc_html__('Open secure 3thix checkout', 'thix-payments') . '</a>'
                );
                ?>
            </p>
        </div>
        <script src="<?php echo esc_url($embed_src); ?>"></script>
        <script>
        // Bring the payment step into view — on the order-pay page WooCommerce
        // renders the order-summary table first, which can push the 3thix
        // checkout below the fold (notably on mobile). Scroll to it so the buyer
        // lands on the payment, making this feel like the final checkout step
        // rather than a separate page.
        (function () {
            function focusStep() {
                var step = document.querySelector('.thix-checkout-step');
                if (step && step.scrollIntoView) {
                    try { step.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) { step.scrollIntoView(); }
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', focusStep);
            } else {
                focusStep();
            }
        })();
        </script>
        <?php
    }

    /**
     * Reconcile an order against 3thix when the buyer lands on the thank-you
     * page. This is the secondary confirmation path: if the signed order.paid
     * callback is delayed (or the merchant never configured a callback URL), the
     * buyer's redirect still confirms payment. Idempotent — does nothing once
     * the order is already paid.
     *
     * The buyer can reach thank-you a moment before the payment is finalized: the
     * embed/hosted card page fires its `/sync` call in the BACKGROUND (fire-and-
     * forget) and then redirects here, so the 3thix payment can still read PENDING
     * when the buyer lands. Rather than passively poll and hope that background
     * sync (or a webhook) wins the race — which would leave the order `pending`,
     * making WooCommerce show the buyer a stray "Pay" / "Cancel" action on this
     * confirmation page — we actively FORCE the server-side reconcile ourselves so
     * confirmation is deterministic. Idempotent and self-sufficient even when no
     * callback URL is configured.
     *
     * @param int $order_id WooCommerce order id.
     */
    public function reconcile_on_return($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }
        if ($order->is_paid()) {
            return;
        }
        // An inline payment held for a charged-amount mismatch is intentionally
        // parked for manual review — never auto-complete it here.
        if ($order->get_meta('_thix_hold_review') === '1') {
            return;
        }

        // Up to 4 attempts (~0s, +2s, +2s, +2s ≈ 6s worst case) to absorb a brief
        // finalization lag before the buyer returns. Bail early once paid.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            if ($attempt > 0) {
                sleep(2);
                // A concurrent signed callback may have completed the order while
                // we waited — re-read and stop if so.
                $order = wc_get_order($order_id);
                if (!$order || $order->is_paid()) {
                    return;
                }
            }

            // One authoritative check against 3thix; completes the order if the
            // funds are confirmed. Shared with the background sweep so both paths
            // resolve payment identically.
            if ($this->reconcile_order_once($order)) {
                return;
            }
        }
    }

    /**
     * Single authoritative reconcile pass for one order: look the 3thix payment
     * up and, if the funds are confirmed (SUCCEEDED / PARTIALLY_REFUNDED), mark
     * the WooCommerce order paid. Returns true once the order is paid.
     *
     * Self-contained, idempotent, and performs NO sleeping, so it is safe to run
     * both on buyer return (`reconcile_on_return`, in a short retry loop) and in
     * the background sweep (`Thix_Reconcile::run()`, across many orders at once).
     * This is the ONLY completion path for direct (card) merchants, who never
     * receive the signed `order.paid` callback.
     *
     * @param WC_Order $order
     * @return bool True if the order is (now) paid.
     */
    public function reconcile_order_once($order) {
        if (!$order || $order->get_payment_method() !== $this->id) {
            return false;
        }
        if ($order->is_paid()) {
            return true;
        }
        // An inline payment held for a charged-amount mismatch is intentionally
        // parked for manual review — never auto-complete it.
        if ($order->get_meta('_thix_hold_review') === '1') {
            return false;
        }

        // 3thix payment statuses that mean the buyer's funds are in hand.
        $paid_statuses = ['SUCCEEDED', 'PARTIALLY_REFUNDED'];

        // Inline orders create the 3thix payment under a client token stored as
        // `_thix_eoid`; redirect/embed orders use the numeric order id.
        $eoid = (string) $order->get_meta('_thix_eoid');
        $lookup_key = $eoid !== '' ? $eoid : (string) $order->get_id();
        $payment = $this->api()->lookup_payment($lookup_key);
        if (!is_array($payment)) {
            // No payment row yet — buyer may have abandoned before charging.
            return false;
        }

        $status = isset($payment['status']) ? (string) $payment['status'] : '';
        $payment_id = isset($payment['id']) ? (string) $payment['id'] : '';

        if (!$this->payment_matches_order($order, $payment)) {
            return false;
        }

        // Already confirmed — finish the order.
        if (in_array($status, $paid_statuses, true)) {
            $this->complete_thix_order($order, $payment_id);
            return true;
        }

        // On-ramp payments settle on-chain and are reconciled by the 3thix
        // server itself (session polling + deposit sweep); the card /sync
        // endpoint rejects them with 400, so don't call it — just wait for a
        // later lookup (or the signed order.paid callback) to see SUCCEEDED.
        if ((string) ($payment['rail'] ?? '') === 'onramp') {
            return false;
        }

        // A card payment exists but isn't confirmed yet: force the server-side
        // reconcile instead of waiting on the background /sync or a webhook.
        // The sync response carries the authoritative post-reconcile status.
        if ($payment_id !== '') {
            $synced = $this->api()->sync_payment($payment_id);
            if (is_array($synced)
                && $this->payment_matches_order($order, $synced)
                && in_array((string) ($synced['status'] ?? ''), $paid_statuses, true)) {
                $this->complete_thix_order($order, $payment_id);
                return true;
            }
        }

        return false;
    }

    /**
     * Mark a WooCommerce order paid for a confirmed 3thix payment. Idempotent
     * via WooCommerce's own `payment_complete` guard.
     *
     * @param WC_Order $order      The WooCommerce order.
     * @param string   $payment_id The confirmed 3thix payment id (transaction id).
     */
    public function complete_thix_order($order, $payment_id) {
        // Make sure WooCommerce's transactional email hooks are registered
        // BEFORE the status flips. On normal page views they always are, but
        // this method also runs from the REST callback and the WP-Cron
        // reconcile sweep, where some setups never instantiate the mailer —
        // the order would go to "processing" silently, with no New Order /
        // Processing emails ever generated.
        if (function_exists('WC')) {
            WC()->mailer();
        }

        // Watch outgoing mail during the status flip so we can detect a stack
        // where the transition generates no ORDER emails at all (some email
        // customizer / marketing plugins only register their senders on
        // normal frontend page views, so REST-callback and WP-Cron
        // completions stay silent). Only mail addressed to this order's
        // recipients counts — unrelated wp_mail traffic is ignored.
        $expected_recipients = [];
        $billing = strtolower(trim((string) $order->get_billing_email()));
        if ($billing !== '') {
            $expected_recipients[] = $billing;
        }
        if (function_exists('WC')) {
            $wc_emails = WC()->mailer()->get_emails();
            if (!empty($wc_emails['WC_Email_New_Order'])) {
                foreach (explode(',', (string) $wc_emails['WC_Email_New_Order']->get_recipient()) as $addr) {
                    $addr = strtolower(trim($addr));
                    if ($addr !== '') {
                        $expected_recipients[] = $addr;
                    }
                }
            }
        }
        $order_mail_count = 0;
        $mail_counter = function ($args) use (&$order_mail_count, $expected_recipients) {
            $to = isset($args['to']) ? $args['to'] : '';
            $to_list = is_array($to) ? $to : explode(',', (string) $to);
            foreach ($to_list as $addr) {
                if (in_array(strtolower(trim((string) $addr)), $expected_recipients, true)) {
                    $order_mail_count++;
                    break;
                }
            }
            return $args;
        };
        // A fatal error thrown by ANY third-party plugin hooked into the
        // payment-complete / status-change events would otherwise abort this
        // request mid-completion: the order flips to paid (WooCommerce saves
        // the status before running transition hooks) but every later hook —
        // including the transactional emails — dies with it. Seen in the
        // wild: woo-referral-wallet fataling on `__unset() on null`, which
        // silently killed all New Order / Processing emails. Catch the
        // Throwable, record it, and continue so the email fallback below can
        // still deliver the order emails.
        $hook_crash = null;
        add_filter('wp_mail', $mail_counter, 10, 1);
        try {
            $order->payment_complete((string) $payment_id);
        } catch (\Throwable $e) {
            $hook_crash = $e;
        }
        remove_filter('wp_mail', $mail_counter, 10);

        // payment_complete() saves the paid status before its hooks run, so
        // even on a crash the order object may be stale — refresh it.
        if ($hook_crash !== null) {
            $fresh = wc_get_order($order->get_id());
            if ($fresh) {
                $order = $fresh;
            }
            // If the paid status never persisted, the crash was NOT a
            // post-save hook failure — don't mask it with a "confirmed" note
            // or fallback emails; log and bail so a later reconcile retries.
            if (!$order->is_paid()) {
                if (function_exists('wc_get_logger')) {
                    wc_get_logger()->error(
                        'payment_complete failed BEFORE saving paid status for order ' . $order->get_id() . ': ' . $hook_crash->getMessage() . ' @ ' . $hook_crash->getFile() . ':' . $hook_crash->getLine(),
                        ['source' => 'thix-payments']
                    );
                }
                return;
            }
            $order->add_order_note(sprintf(
                /* translators: %s: error summary from the crashing plugin */
                __('3thix: a third-party plugin crashed during payment completion (%s). Order emails were recovered by 3thix if none were sent.', 'thix-payments'),
                substr($hook_crash->getMessage() . ' @ ' . $hook_crash->getFile() . ':' . $hook_crash->getLine(), 0, 300)
            ));
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error(
                    'Third-party crash during payment_complete for order ' . $order->get_id() . ': ' . $hook_crash->getMessage() . ' @ ' . $hook_crash->getFile() . ':' . $hook_crash->getLine(),
                    ['source' => 'thix-payments']
                );
            }
        }

        $order->add_order_note(__('Payment confirmed via 3thix.', 'thix-payments'));

        // If the site defers/queues transactional emails, they are sent later
        // by the queue — no immediate wp_mail is expected and a fallback send
        // here would duplicate them. Leave those stacks alone.
        $emails_deferred = function_exists('apply_filters')
            && apply_filters('woocommerce_defer_transactional_emails', false);

        // Fallback: the flip produced zero order emails — trigger the standard
        // WooCommerce order emails ourselves, exactly once per order. The
        // `add_option` claim is atomic (INSERT-or-fail), so concurrent
        // completion paths (callback / buyer return / cron) cannot both send.
        if ($order_mail_count === 0
            && !$emails_deferred
            && function_exists('WC')
            && add_option('thix_fbmail_' . $order->get_id(), current_time('mysql'), '', false)) {
            $order->update_meta_data('_thix_fallback_emails', current_time('mysql'));
            $order->save();

            $emails = WC()->mailer()->get_emails();
            $to_trigger = ['WC_Email_New_Order'];
            if ($order->has_status('processing')) {
                $to_trigger[] = 'WC_Email_Customer_Processing_Order';
            } elseif ($order->has_status('completed')) {
                $to_trigger[] = 'WC_Email_Customer_Completed_Order';
            } elseif ($order->has_status('on-hold')) {
                $to_trigger[] = 'WC_Email_Customer_On_Hold_Order';
            }
            $sent_any = false;
            foreach ($to_trigger as $email_key) {
                if (!empty($emails[$email_key]) && $emails[$email_key]->is_enabled()) {
                    try {
                        $emails[$email_key]->trigger($order->get_id(), $order);
                        $sent_any = true;
                    } catch (\Throwable $e) {
                        if (function_exists('wc_get_logger')) {
                            wc_get_logger()->error(
                                'Fallback email ' . $email_key . ' crashed for order ' . $order->get_id() . ': ' . $e->getMessage(),
                                ['source' => 'thix-payments']
                            );
                        }
                    }
                }
            }
            if ($sent_any) {
                $order->add_order_note(__('3thix: order emails sent via fallback (status change generated none).', 'thix-payments'));
            }
        }

        $order->save();
    }

    /**
     * Refund a 3thix card payment from the WooCommerce admin "Refund" button.
     *
     * WooCommerce calls this because the gateway advertises `supports['refunds']`.
     * Without an implementation, core's default `process_refund()` returns false
     * and the admin sees the generic "An error occurred while attempting to
     * create the refund using the payment gateway API." Returning a WP_Error
     * here surfaces the real reason inline instead.
     *
     * The 3thix payment id is stored as the order's transaction id at
     * `payment_complete()`; fall back to looking it up by order id for older
     * orders. Only direct card payments are refundable — on-ramp (fiat→USDC)
     * orders are rejected by the API with an actionable message.
     *
     * @param int        $order_id
     * @param float|null $amount   Amount to refund in the store currency, null = full remaining.
     * @param string     $reason
     * @return bool|WP_Error true on success; WP_Error (shown to the admin) on failure.
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('thix_refund_no_order', __('Order not found.', 'thix-payments'));
        }

        if ((string) $this->get_option('secret_key') === '') {
            return new WP_Error(
                'thix_refund_no_secret',
                __('3thix refunds require a SECRET API key. Add it under WooCommerce → Settings → Payments → 3thix Payments.', 'thix-payments')
            );
        }

        // 3thix payment id = the order transaction id set at payment_complete();
        // fall back to a lookup by order id for orders paid before this was stored.
        $payment_id = (string) $order->get_transaction_id();
        if ($payment_id === '') {
            // Inline orders create the 3thix payment under a client token stored
            // as `_thix_eoid`; redirect/embed orders use the numeric order id.
            $eoid = (string) $order->get_meta('_thix_eoid');
            $lookup_key = $eoid !== '' ? $eoid : (string) $order->get_id();
            $payment = $this->api()->lookup_payment($lookup_key);
            if (is_array($payment) && isset($payment['id'])) {
                $payment_id = (string) $payment['id'];
            }
        }
        if ($payment_id === '') {
            return new WP_Error(
                'thix_refund_no_payment',
                __('Could not find the 3thix payment for this order to refund.', 'thix-payments')
            );
        }

        // WooCommerce passes the amount in the store currency; 3thix refunds in
        // cents. A null/blank amount means a full refund of the remaining balance.
        $amount_cents = ($amount === null || $amount === '') ? null : (int) round((float) $amount * 100);

        $result = $this->api()->refund_payment($payment_id, $amount_cents, (string) $reason);
        if (!$result['ok']) {
            $order->add_order_note(sprintf(
                /* translators: %s: error message from 3thix */
                __('3thix refund failed: %s', 'thix-payments'),
                $result['message']
            ));
            return new WP_Error('thix_refund_failed', $result['message']);
        }

        $order->add_order_note(sprintf(
            /* translators: %s: refunded amount */
            __('Refunded %s via 3thix.', 'thix-payments'),
            $amount_cents === null ? __('full remaining balance', 'thix-payments') : wc_price((float) $amount)
        ));
        return true;
    }
}
