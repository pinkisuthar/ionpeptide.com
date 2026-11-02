<?php
/**
 * Thin client for the 3thix REST API + callback signature verification.
 *
 * @package Thix_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Thix_Api {
    /** @var string Base URL of the 3thix API, e.g. https://pay.3thix.com/api */
    private $api_base;
    /** @var string Merchant SECRET API key (thx_live_/thx_test_) — server-side only. */
    private $secret_key;
    /** @var string Callback signing secret used to verify inbound callbacks. */
    private $signing_secret;

    public function __construct($api_base, $secret_key, $signing_secret) {
        $this->api_base = self::normalize_base($api_base);
        $this->secret_key = (string) $secret_key;
        $this->signing_secret = (string) $signing_secret;
    }

    /**
     * Validate a publishable merchant API key against the format minted by the
     * API.
     *
     * Minted publishable keys are
     * `thx_<test|live>_pk_<40 lowercase hex chars>`. The complete random suffix
     * is intentional: accepting a shortened or masked console hint can make a
     * store appear configured while every request is unauthorized.
     *
     * This method deliberately returns only a boolean. Callers should report a
     * generic configuration error rather than echoing any part of a key.
     *
     * @param mixed       $key         Candidate key.
     * @param string|null $environment Expected `test` or `live`, or null for either.
     * @return bool
     */
    public static function is_valid_publishable_key($key, $environment = null) {
        if (!is_scalar($key)) {
            return false;
        }

        $key = trim((string) $key);
        if ($key === '') {
            return false;
        }

        if (!preg_match('/\Athx_(test|live)_pk_[a-f0-9]{40}\z/', $key, $matches)) {
            return false;
        }

        if ($environment === null || $environment === '') {
            return true;
        }

        return in_array($environment, ['test', 'live'], true)
            && $matches[1] === $environment;
    }

    /**
     * Forgiving normalizer for the merchant-entered "API base URL". Fixes the
     * most common copy/paste mistakes so checkout links don't silently 404 / hit
     * a dead domain:
     *   - trims whitespace and trailing slashes
     *   - adds https:// if no scheme was given
     *   - drops a trailing path the merchant may have pasted by accident
     *     (/checkout, /payments, /tracking)
     *   - ensures the path ends in /api (the 3thix API lives under /api)
     *
     * Examples (all → https://pay.3thix.com/api):
     *   "pay.3thix.com"            "https://pay.3thix.com/"
     *   "https://pay.3thix.com"    "https://pay.3thix.com/api/checkout"
     *
     * Note: this cannot fix a wrong HOST (e.g. api.3thix.com instead of
     * pay.3thix.com) — that domain simply doesn't exist, so the merchant must
     * enter the correct host shown on their console's Developers page.
     *
     * @param string $api_base Raw value from settings.
     * @return string Normalized base ending in /api (no trailing slash).
     */
    public static function normalize_base($api_base) {
        $base = trim((string) $api_base);
        if ($base === '') {
            return '';
        }
        // Default to https when the merchant omits the scheme.
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        // Drop any accidentally-pasted query string / fragment.
        $base = preg_replace('/[?#].*$/', '', $base);
        $base = rtrim($base, '/');
        // Strip an accidentally-pasted endpoint path so we don't double it up.
        $base = preg_replace('#/(checkout|payments|tracking)$#i', '', $base);
        $base = rtrim($base, '/');
        // Ensure the base targets the API mount.
        if (!preg_match('#/api$#i', $base)) {
            $base .= '/api';
        }
        return $base;
    }

    /**
     * Submit shipment tracking for an escrow order to 3thix.
     *
     * @param string $external_order_id The WooCommerce order id (sent as `order` at checkout).
     * @param string $carrier           Shipping carrier name.
     * @param string $tracking_number   Carrier tracking number.
     * @param bool   $confirm_already_shipped When true, the merchant has explicitly
     *                                        confirmed a carrier-reported "already
     *                                        delivered" shipment belongs to THIS
     *                                        order (3thix `confirmAlreadyShipped`).
     * @return array{ok:bool, code:string, message:string}
     */
    public function submit_tracking($external_order_id, $carrier, $tracking_number, $confirm_already_shipped = false) {
        if ($this->secret_key === '') {
            return ['ok' => false, 'code' => 'no_secret_key', 'message' => 'No 3thix secret API key configured.'];
        }

        $payload = [
            'externalOrderId' => (string) $external_order_id,
            'carrier'         => (string) $carrier,
            'trackingNumber'  => (string) $tracking_number,
        ];
        if ($confirm_already_shipped) {
            $payload['confirmAlreadyShipped'] = true;
        }

        $response = wp_remote_post($this->api_base . '/tracking', [
            'timeout' => 20,
            'headers' => [
                'x-api-key'    => $this->secret_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'code' => 'network_error', 'message' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'code' => 'ok', 'message' => 'Tracking submitted to 3thix.'];
        }

        $code = is_array($body) && isset($body['error']['code']) ? (string) $body['error']['code'] : 'http_' . $status;
        $message = is_array($body) && isset($body['error']['message'])
            ? (string) $body['error']['message']
            : 'Tracking submission failed (HTTP ' . $status . ').';

        return ['ok' => false, 'code' => $code, 'message' => $message];
    }

    /**
     * Report delivery of a DIGITAL (virtual/downloadable) order to 3thix.
     *
     * The digital counterpart of submit_tracking(): there is no carrier, so the
     * order is identified by `externalOrderId` and the delivery by a stable
     * per-order reference (`deliveryRef`) that 3thix de-duplicates per merchant.
     *
     * @param string $external_order_id The id sent as `order` at checkout
     *                                  (numeric WC id, or the `_thix_eoid` token
     *                                  for inline orders).
     * @param string $delivery_ref      Stable per-order delivery reference.
     * @return array{ok:bool, code:string, message:string}
     */
    public function submit_digital_delivery($external_order_id, $delivery_ref) {
        if ($this->secret_key === '') {
            return ['ok' => false, 'code' => 'no_secret_key', 'message' => 'No 3thix secret API key configured.'];
        }

        $response = wp_remote_post($this->api_base . '/orders/digital-delivery', [
            'timeout' => 20,
            'headers' => [
                'x-api-key'    => $this->secret_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'externalOrderId' => (string) $external_order_id,
                'deliveryRef'     => (string) $delivery_ref,
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'code' => 'network_error', 'message' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'code' => 'ok', 'message' => 'Digital delivery reported to 3thix.'];
        }

        $code = is_array($body) && isset($body['error']['code']) ? (string) $body['error']['code'] : 'http_' . $status;
        $message = is_array($body) && isset($body['error']['message'])
            ? (string) $body['error']['message']
            : 'Digital delivery report failed (HTTP ' . $status . ').';

        return ['ok' => false, 'code' => $code, 'message' => $message];
    }

    /**
     * Look up the 3thix payment for a WooCommerce order, by the order id we sent
     * at checkout (`externalOrderId`). Server-side only (uses the secret key).
     *
     * Used by the buyer return/thank-you reconciliation path so an order can be
     * confirmed even if the signed callback is delayed or never configured.
     *
     * @param string $external_order_id The WooCommerce order id.
     * @return array|null The latest matching payment object, or null if none/error.
     */
    public function lookup_payment($external_order_id) {
        if ($this->secret_key === '') {
            return null;
        }

        $url = add_query_arg(
            ['externalOrderId' => (string) $external_order_id, 'limit' => 1],
            $this->api_base . '/payments'
        );

        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'x-api-key' => $this->secret_key,
                'Accept'    => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['data']) || !is_array($body['data'][0])) {
            return null;
        }

        return $body['data'][0];
    }

    /**
     * Force a server-side reconcile of a card payment against the processor and
     * return the refreshed payment (with its authoritative `status`).
     *
     * The buyer-facing checkout fires this same `/sync` in the background and then
     * redirects, so the payment can still read PENDING for a moment when the buyer
     * lands on the thank-you page. Calling it here ourselves makes confirmation
     * deterministic instead of racing the background call or a webhook. Server-side
     * only (uses the secret key). On-ramp payments reconcile on a different
     * endpoint, so this returns null for them (handled gracefully by the caller).
     *
     * @param string $payment_id The 3thix payment id.
     * @return array|null The refreshed payment object, or null on error/wrong rail.
     */
    public function sync_payment($payment_id) {
        $payment_id = (string) $payment_id;
        if ($this->secret_key === '' || $payment_id === '') {
            return null;
        }

        $response = wp_remote_post(
            $this->api_base . '/payments/' . rawurlencode($payment_id) . '/sync',
            [
                'timeout' => 20,
                'headers' => [
                    'x-api-key' => $this->secret_key,
                    'Accept'    => 'application/json',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) ? $body : null;
    }

    /**
     * Issue a refund for a 3thix card payment. Server-side only (secret key).
     *
     * Hits `POST /payments/{id}/refund` (structured `{error:{code,message}}`
     * envelope). On-ramp (fiat→USDC) payments are rejected by the API with an
     * actionable message, which is surfaced verbatim to the WooCommerce admin.
     *
     * @param string   $payment_id   The 3thix payment id (the order's transaction id).
     * @param int|null $amount_cents Partial amount in cents, or null for a full refund of the remaining balance.
     * @param string   $reason       Optional reason logged with the refund.
     * @return array{ok:bool, code:string, message:string}
     */
    public function refund_payment($payment_id, $amount_cents = null, $reason = '') {
        $payment_id = (string) $payment_id;
        if ($this->secret_key === '') {
            return ['ok' => false, 'code' => 'no_secret_key', 'message' => 'No 3thix secret API key configured.'];
        }
        if ($payment_id === '') {
            return ['ok' => false, 'code' => 'no_payment_id', 'message' => 'No 3thix payment id on this order.'];
        }

        $payload = [];
        if ($amount_cents !== null) {
            $payload['amountCents'] = (int) $amount_cents;
        }
        if ($reason !== '') {
            $payload['reason'] = (string) $reason;
        }

        $response = wp_remote_post(
            $this->api_base . '/payments/' . rawurlencode($payment_id) . '/refund',
            [
                'timeout' => 30,
                'headers' => [
                    'x-api-key'    => $this->secret_key,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                // Cast to object so an amount-less full refund serializes as `{}`,
                // not `[]` (the server parses the body as a JSON object).
                'body' => wp_json_encode((object) $payload),
            ]
        );

        if (is_wp_error($response)) {
            return ['ok' => false, 'code' => 'network_error', 'message' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'code' => 'ok', 'message' => 'Refund created via 3thix.'];
        }

        $code = is_array($body) && isset($body['error']['code']) ? (string) $body['error']['code'] : 'http_' . $status;
        $message = is_array($body) && isset($body['error']['message'])
            ? (string) $body['error']['message']
            : 'Refund failed (HTTP ' . $status . ').';

        return ['ok' => false, 'code' => $code, 'message' => $message];
    }

    /**
     * Verify a 3thix callback signature header against the raw request body.
     *
     * Header format: `t=<unix_ts>,v1=<hex_hmac_sha256>` where the HMAC is taken
     * over `<ts>.<raw_body>` keyed by the callback signing secret. Enforces a
     * 5-minute skew window and a constant-time digest comparison.
     *
     * @param string $raw_body   The exact raw POST body received.
     * @param string $sig_header Value of the `x-3thix-signature` header.
     * @return bool
     */
    public function verify_signature($raw_body, $sig_header) {
        if ($this->signing_secret === '' || !is_string($sig_header) || $sig_header === '') {
            return false;
        }

        $timestamp = '';
        $signature = '';
        foreach (explode(',', $sig_header) as $part) {
            $part = trim($part);
            if (strpos($part, 't=') === 0) {
                $timestamp = substr($part, 2);
            } elseif (strpos($part, 'v1=') === 0) {
                $signature = substr($part, 3);
            }
        }

        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
            return false;
        }

        // Reject stale or future-dated signatures (5-minute skew window).
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $raw_body, $this->signing_secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Build the 3thix hosted-checkout redirect URL for a WooCommerce order.
     *
     * @param array $args { publishable_key, amount_cents, order_id, currency, email, redirect, mode, theme, accent, logo, brand }
     * @return string
     */
    public function checkout_url(array $args) {
        $publishable = isset($args['publishable_key']) && is_scalar($args['publishable_key'])
            ? trim((string) $args['publishable_key'])
            : '';
        $environment = isset($args['environment']) && is_scalar($args['environment'])
            ? trim((string) $args['environment'])
            : '';

        if (!self::is_valid_publishable_key($publishable)) {
            throw new InvalidArgumentException('Invalid 3thix publishable key.');
        }
        if ($environment !== '' && !self::is_valid_publishable_key($publishable, $environment)) {
            // Do not include the key (or a prefix/suffix) in this message.
            throw new InvalidArgumentException('The 3thix publishable key does not match the selected environment.');
        }

        $params = [
            'key'    => $publishable,
            'amount' => $args['amount_cents'],
            'order'  => $args['order_id'],
        ];
        if (!empty($args['email'])) {
            $params['email'] = $args['email'];
        }
        if (!empty($args['redirect'])) {
            $params['redirect'] = $args['redirect'];
        }
        if (!empty($args['mode'])) {
            $params['mode'] = $args['mode'];
        }
        // The amount is expressed in the source order currency. Only the two
        // currencies the checkout contract supports may leave the plugin;
        // callers reject every other store currency before creating a checkout.
        if (array_key_exists('currency', $args) && $args['currency'] !== '' && $args['currency'] !== null) {
            $currency = strtoupper((string) $args['currency']);
            if (!in_array($currency, ['USD', 'EUR'], true)) {
                throw new InvalidArgumentException('Unsupported checkout currency.');
            }
            $params['currency'] = strtolower($currency);
        }
        // Preselects the payment rail on the hosted page (e.g. `coinbase`).
        // Preference-only: the hosted page honors it only when the merchant's
        // 3thix config actually enables that rail.
        if (!empty($args['provider'])) {
            $params['provider'] = $args['provider'];
        }
        // Branding so the hosted checkout blends into the merchant's store. The
        // hosted page re-validates each value (accent must be hex, logo must be
        // https, brand is rendered as text), so passing them through is safe.
        if (!empty($args['theme'])) {
            $params['theme'] = $args['theme'];
        }
        if (!empty($args['accent'])) {
            $params['accent'] = $args['accent'];
        }
        if (!empty($args['logo'])) {
            $params['logo'] = $args['logo'];
        }
        if (!empty($args['brand'])) {
            $params['brand'] = $args['brand'];
        }

        // Optional Shopify-style order summary (DISPLAY-ONLY — the amount actually
        // charged is always `amount`). `items` is a base64url-encoded JSON array;
        // the rest are integer cents. The hosted page re-validates/escapes each
        // value (text escaped, image URLs must be https), so passing them is safe.
        if (!empty($args['items'])) {
            $params['items'] = $args['items'];
        }
        foreach (['subtotal', 'shipping', 'tax', 'discount'] as $k) {
            if (isset($args[$k]) && $args[$k] !== '' && $args[$k] !== null) {
                $params[$k] = (int) $args[$k];
            }
        }

        // add_query_arg() does NOT URL-encode values (verified empirically: a
        // redirect URL containing `&` splits the query string and its own
        // `key=wc_order_…` overrides the publishable `key` param; a `+` in the
        // buyer email decodes to a space). Build the query with RFC 3986
        // encoding instead so redirect/email/brand round-trip intact.
        return $this->api_base . '/checkout?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
