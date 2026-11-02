<?php
/**
 * Automatic shipment-tracking sync to 3thix.
 *
 * Watches WooCommerce orders paid with the 3thix gateway and, whenever shipment
 * tracking is added or changed, submits it to 3thix automatically (POST /tracking)
 * so the merchant never has to push it by hand. Tracking is read from, in order:
 *
 *   1. WooCommerce Shipment Tracking (official) / Advanced Shipment Tracking
 *      (Zorem) — both store items under the `_wc_shipment_tracking_items` meta.
 *   2. The plugin's own "3thix Shipment Tracking" metabox fields.
 *
 * Submissions are de-duplicated with a signature meta so the same carrier +
 * number is never re-sent, and re-entry is guarded so saving the order from
 * inside the sync can't loop.
 *
 * @package Thix_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Thix_Auto_Tracking {
    /** @var array<int,bool> Re-entry guard, keyed by order id. */
    private static $in_progress = [];

    public static function init() {
        // Most merchants add tracking and then mark the order processing/completed —
        // the status change is the reliable cross-version (HPOS + legacy) signal.
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_status_changed'], 20, 4);
        // Advanced Shipment Tracking (Zorem) fires this right after tracking is saved.
        add_action('ast_after_save_tracking_items', [__CLASS__, 'on_tracking_saved'], 20, 2);
        // WooCommerce Shipment Tracking (official) fires this on supported versions
        // when a tracking item is added/removed. Harmless if never fired.
        add_action('woocommerce_shipment_tracking_updated', [__CLASS__, 'on_tracking_saved'], 20, 1);
    }

    /** Resolve the active 3thix gateway instance, or null if unavailable. */
    private static function gateway() {
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        return isset($gateways['thix']) ? $gateways['thix'] : null;
    }

    /**
     * @param int        $order_id
     * @param string     $from
     * @param string     $to
     * @param WC_Order|null $order
     */
    public static function on_status_changed($order_id, $from = '', $to = '', $order = null) {
        self::sync_order($order instanceof WC_Order ? $order : $order_id);
    }

    /**
     * @param int   $order_id
     * @param mixed $tracking_items
     */
    public static function on_tracking_saved($order_id, $tracking_items = null) {
        self::sync_order($order_id);
    }

    /**
     * Pull the latest carrier + tracking number off an order from any supported
     * source. Returns [carrier, number] or null when no usable tracking exists.
     *
     * Public so the signed tracking-query REST endpoint (3thix pulling
     * tracking from this store) reads from the exact same sources.
     *
     * @param WC_Order $order
     * @return array{0:string,1:string}|null
     */
    public static function extract_tracking($order) {
        // WooCommerce Shipment Tracking / Advanced Shipment Tracking both store an
        // array of items here; the most-recently-added one is last.
        $items = $order->get_meta('_wc_shipment_tracking_items');
        if (is_array($items) && !empty($items)) {
            $item = end($items);
            if (is_array($item)) {
                $number = isset($item['tracking_number']) ? trim((string) $item['tracking_number']) : '';
                $provider = '';
                if (!empty($item['custom_tracking_provider'])) {
                    $provider = trim((string) $item['custom_tracking_provider']);
                } elseif (!empty($item['tracking_provider'])) {
                    $provider = trim((string) $item['tracking_provider']);
                }
                if ($number !== '') {
                    // 3thix needs a carrier label; fall back to a generic one.
                    return [$provider !== '' ? $provider : 'Other', $number];
                }
            }
        }

        // The plugin's own metabox fields.
        $carrier = trim((string) $order->get_meta('_thix_carrier'));
        $number = trim((string) $order->get_meta('_thix_tracking_number'));
        if ($carrier !== '' && $number !== '') {
            return [$carrier, $number];
        }

        return null;
    }

    /**
     * Submit the order's current tracking to 3thix if it's new (or forced).
     *
     * @param WC_Order|int $order   WooCommerce order or id.
     * @param bool         $force   When true, bypass the opt-out setting and the
     *                              "already synced" de-dupe (manual re-send).
     * @param bool         $confirm When true, the merchant has explicitly answered
     *                              "yes, this already-delivered shipment was sent
     *                              for THIS order" — resubmits with 3thix's
     *                              `confirmAlreadyShipped` flag.
     */
    public static function sync_order($order, $force = false, $confirm = false) {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }
        if (!$order instanceof WC_Order) {
            return;
        }

        // Only orders this store routed through 3thix.
        if ($order->get_payment_method() !== 'thix') {
            return;
        }

        $gateway = self::gateway();
        if (!$gateway) {
            return;
        }

        // Respect the merchant's opt-out unless this is an explicit manual send.
        if (!$force && $gateway->get_option('auto_tracking', 'yes') !== 'yes') {
            return;
        }

        // Tracking only matters once the buyer has actually paid.
        if (!$order->is_paid()) {
            return;
        }

        $tracking = self::extract_tracking($order);
        if (!$tracking) {
            return;
        }
        list($carrier, $number) = $tracking;

        // Skip if we've already synced this exact carrier + number. Normalize the
        // signature (case + whitespace) so trivial formatting differences don't
        // trigger an accidental re-send; the submitted values stay as entered.
        $sig = md5(strtolower(preg_replace('/\s+/', '', $carrier . '|' . $number)));
        if (!$force && (string) $order->get_meta('_thix_tracking_synced_sig') === $sig) {
            return;
        }

        // Guard against re-entry from the $order->save() below.
        $oid = $order->get_id();
        if (!empty(self::$in_progress[$oid])) {
            return;
        }
        self::$in_progress[$oid] = true;

        try {
            $result = $gateway->api()->submit_tracking((string) $oid, $carrier, $number, $confirm);
            $code = isset($result['code']) ? (string) $result['code'] : '';

            if (!empty($result['ok'])) {
                $order->update_meta_data('_thix_carrier', $carrier);
                $order->update_meta_data('_thix_tracking_number', $number);
                $order->update_meta_data('_thix_tracking_synced_sig', $sig);
                $order->update_meta_data('_thix_tracking_synced', current_time('mysql'));
                // Any pending "already delivered — confirm?" question is answered.
                $order->delete_meta_data('_thix_tracking_confirm_sig');
                $order->delete_meta_data('_thix_tracking_stale_sig');
                $order->delete_meta_data('_thix_tracking_fail_sig');
                $order->add_order_note(sprintf(
                    /* translators: 1: carrier, 2: tracking number */
                    __('Tracking auto-synced to 3thix (%1$s %2$s). Escrow release scheduled in ~1 business day.', 'thix-payments'),
                    $carrier,
                    $number
                ));
                $order->save();
            } elseif ($code === 'tracking_already_delivered') {
                // 3thix's anti-fraud gate: the carrier says this shipment was
                // already delivered, so a human must confirm it belongs to THIS
                // order. Flag the order (the 3thix Shipment Tracking box shows a
                // confirm checkbox) and note it ONCE per carrier+number so
                // repeated auto-sync triggers don't spam the timeline.
                if ((string) $order->get_meta('_thix_tracking_confirm_sig') !== $sig) {
                    $order->update_meta_data('_thix_tracking_confirm_sig', $sig);
                    $order->add_order_note(sprintf(
                        /* translators: 1: carrier, 2: tracking number */
                        __('3thix: the carrier reports shipment %1$s %2$s as ALREADY DELIVERED. If this item really was sent for this order, open the "3thix Shipment Tracking" box on this order, tick "Yes — this shipment was sent for this order", and update the order to submit it.', 'thix-payments'),
                        $carrier,
                        $number
                    ));
                    $order->save();
                }
            } elseif ($code === 'tracking_delivered_stale') {
                // Delivered too long ago to accept automatically — needs a 3thix
                // admin, not a store-side retry. Note once per carrier+number.
                if ((string) $order->get_meta('_thix_tracking_stale_sig') !== $sig) {
                    $order->update_meta_data('_thix_tracking_stale_sig', $sig);
                    $order->add_order_note(sprintf(
                        /* translators: 1: carrier, 2: tracking number, 3: server message */
                        __('3thix: tracking %1$s %2$s was rejected — the carrier reports it was delivered too long ago to submit for payout. Please contact a 3thix admin to review this order. (%3$s)', 'thix-payments'),
                        $carrier,
                        $number,
                        isset($result['message']) ? $result['message'] : ''
                    ));
                    $order->save();
                }
            } elseif ($code !== 'order_not_trackable') {
                // Stay quiet for "not funded yet" (a later status change retries);
                // surface anything else so the merchant can act on it. Note once
                // per failure code + carrier+number signature so repeated
                // auto-sync triggers (every order save) don't flood the timeline;
                // a changed tracking number or a manual force re-send still
                // surfaces a fresh note.
                $fail_sig = $code . '|' . $sig;
                if ($force || (string) $order->get_meta('_thix_tracking_fail_sig') !== $fail_sig) {
                    $order->update_meta_data('_thix_tracking_fail_sig', $fail_sig);
                    $order->add_order_note(sprintf(
                        /* translators: %s: error message */
                        __('3thix tracking auto-sync failed: %s', 'thix-payments'),
                        isset($result['message']) ? $result['message'] : 'Unknown error'
                    ));
                    $order->save();
                }
            }
        } finally {
            unset(self::$in_progress[$oid]);
        }
    }
}
