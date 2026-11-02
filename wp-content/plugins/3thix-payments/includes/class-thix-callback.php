<?php
/**
 * Inbound signed callback receiver: 3thix → this store.
 *
 * Registers a REST route (thix/v1/callback) that 3thix POSTs `order.paid` and
 * `order.released` events to. The signature is verified against the configured
 * signing secret before any order state is touched, and handling is idempotent.
 *
 * @package Thix_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Thix_Callback {
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_route']);
    }

    public static function register_route() {
        register_rest_route('thix/v1', '/callback', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => '__return_true',
        ]);
        // Signed read-only endpoint: 3thix pulls the current shipment tracking
        // for one order (e.g. the "Refresh tracking" button in the 3thix
        // portal). Same HMAC scheme + secret as /callback.
        register_rest_route('thix/v1', '/tracking-query', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_tracking_query'],
            'permission_callback' => '__return_true',
        ]);
    }

    private static function gateway() {
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        return isset($gateways['thix']) ? $gateways['thix'] : null;
    }

    public static function handle(WP_REST_Request $request) {
        $gateway = self::gateway();
        if (!$gateway) {
            return new WP_REST_Response(['error' => 'gateway_unavailable'], 503);
        }

        $raw_body = $request->get_body();
        $sig = $request->get_header('x_3thix_signature');

        if (!$gateway->api()->verify_signature($raw_body, $sig)) {
            return new WP_REST_Response(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($raw_body, true);
        if (!is_array($payload) || empty($payload['event']) || empty($payload['order'])) {
            return new WP_REST_Response(['error' => 'invalid_payload'], 400);
        }

        $event = (string) $payload['event'];
        $external_order_id = isset($payload['order']['externalOrderId'])
            ? (string) $payload['order']['externalOrderId']
            : '';

        if ($external_order_id === '') {
            return new WP_REST_Response(['error' => 'missing_order'], 400);
        }

        // Inline card orders create the 3thix order under a client token (e.g.
        // "wc-<uuid>") stored on the WooCommerce order as `_thix_eoid`; resolve by
        // that meta first, then fall back to the legacy numeric-id match used by
        // redirect/embed orders (externalOrderId = WooCommerce order id).
        $order = null;
        if (!ctype_digit($external_order_id)) {
            $matches = wc_get_orders([
                'limit'          => 1,
                'payment_method' => 'thix',
                'meta_key'       => '_thix_eoid',
                'meta_value'     => $external_order_id,
                'return'         => 'objects',
            ]);
            if (!empty($matches)) {
                $order = $matches[0];
            }
        } else {
            $numeric = wc_get_order((int) $external_order_id);
            if ($numeric && $numeric->get_id() === (int) $external_order_id) {
                $order = $numeric;
            }
        }

        if (!$order) {
            // Acknowledge so 3thix stops retrying; nothing to do here.
            return new WP_REST_Response(['ok' => true, 'note' => 'order_not_found'], 200);
        }
        if (!$gateway->callback_matches_order($order, $payload['order'])) {
            return new WP_REST_Response(['error' => 'payment_binding_mismatch'], 409);
        }

        switch ($event) {
            case 'order.paid':
                if (!$order->is_paid()) {
                    // Shared completion path: preloads the mailer, flips the
                    // status, and — if the transition generated zero emails
                    // (common in REST-request contexts on sites with email
                    // customizer plugins) — triggers the New Order /
                    // Processing emails itself, once.
                    $gateway->complete_thix_order($order, '');
                    $order->add_order_note(__('3thix confirmed payment (order.paid).', 'thix-payments'));
                } elseif (!$order->get_meta('_thix_paid_replay_noted')) {
                    // Note the first replay only — a retry storm must not fill the
                    // order timeline with identical "ignored" notes.
                    $order->update_meta_data('_thix_paid_replay_noted', current_time('mysql'));
                    $order->add_order_note(__('3thix order.paid received (already paid — ignored).', 'thix-payments'));
                    $order->save();
                }
                break;

            case 'order.released':
                // Guard against duplicate notes if a delivery is retried/replayed.
                if ($order->get_meta('_thix_released')) {
                    break;
                }
                $tx = isset($payload['order']['txHash']) ? (string) $payload['order']['txHash'] : '';
                $order->update_meta_data('_thix_released', current_time('mysql'));
                $order->add_order_note(
                    $tx !== ''
                        ? sprintf(__('3thix released escrowed funds (tx %s).', 'thix-payments'), $tx)
                        : __('3thix released escrowed funds.', 'thix-payments')
                );
                $order->save();
                break;

            default:
                // Unknown event — acknowledge without action.
                break;
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Signed tracking pull: 3thix asks this store for the current shipment
     * tracking on one order. Read-only — never changes order state. Body:
     * {"externalOrderId": "<numeric WC id or _thix_eoid token>"}.
     */
    public static function handle_tracking_query(WP_REST_Request $request) {
        $gateway = self::gateway();
        if (!$gateway) {
            return new WP_REST_Response(['error' => 'gateway_unavailable'], 503);
        }

        $raw_body = $request->get_body();
        $sig = $request->get_header('x_3thix_signature');

        if (!$gateway->api()->verify_signature($raw_body, $sig)) {
            return new WP_REST_Response(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($raw_body, true);
        $external_order_id = (is_array($payload) && isset($payload['externalOrderId']))
            ? (string) $payload['externalOrderId']
            : '';
        if ($external_order_id === '') {
            return new WP_REST_Response(['error' => 'missing_order'], 400);
        }

        // Same order resolution as the callback handler: inline card orders
        // live under the `_thix_eoid` meta token; redirect/embed orders use the
        // numeric WooCommerce order id.
        $order = null;
        if (!ctype_digit($external_order_id)) {
            $matches = wc_get_orders([
                'limit'          => 1,
                'payment_method' => 'thix',
                'meta_key'       => '_thix_eoid',
                'meta_value'     => $external_order_id,
                'return'         => 'objects',
            ]);
            if (!empty($matches)) {
                $order = $matches[0];
            }
        } else {
            $numeric = wc_get_order((int) $external_order_id);
            if ($numeric && $numeric->get_id() === (int) $external_order_id) {
                $order = $numeric;
            }
        }

        if (!$order) {
            return new WP_REST_Response(['ok' => true, 'found' => false, 'note' => 'order_not_found'], 200);
        }
        // Only orders this store routed through 3thix are queryable — don't
        // leak tracking for unrelated orders even with a valid signature.
        if ($order->get_payment_method() !== 'thix') {
            return new WP_REST_Response(['ok' => true, 'found' => false, 'note' => 'not_a_3thix_order'], 200);
        }

        $tracking = class_exists('Thix_Auto_Tracking')
            ? Thix_Auto_Tracking::extract_tracking($order)
            : null;
        if (!$tracking) {
            return new WP_REST_Response(['ok' => true, 'found' => false], 200);
        }

        return new WP_REST_Response([
            'ok'             => true,
            'found'          => true,
            'carrier'        => $tracking[0],
            'trackingNumber' => $tracking[1],
        ], 200);
    }
}
