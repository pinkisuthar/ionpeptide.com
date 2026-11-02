<?php
/**
 * Automatic digital-delivery reporting to 3thix.
 *
 * The digital counterpart of Thix_Auto_Tracking: watches WooCommerce orders
 * paid with the 3thix gateway whose items are ALL non-shippable (nothing to
 * track) and, when the order completes (WooCommerce
 * auto-completes fully-virtual paid orders and grants download permissions),
 * reports delivery to 3thix automatically (POST /orders/digital-delivery) so
 * escrowed funds release without the merchant calling the API by hand.
 *
 * The delivery reference is stable per order so 3thix's per-merchant de-dupe
 * holds across retries:
 *   1. `wc-dl-<id>` — the first WooCommerce download-permission id granted for
 *      the order (downloadable products), or
 *   2. `wc-order-<id>-<order_key>` — for virtual-but-not-downloadable items.
 *
 * Submissions are de-duplicated with a signature meta and stay SILENT on the
 * backend's "order not deliverable yet" code (escrow may not be funded when
 * the order completes) — a later status change retries automatically, exactly
 * like the tracking auto-sync.
 *
 * @package Thix_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Thix_Auto_Digital {
    /** @var array<int,bool> Re-entry guard, keyed by order id. */
    private static $in_progress = [];

    public static function init() {
        // Status change is the reliable cross-version (HPOS + legacy) signal;
        // fully-virtual paid orders auto-complete right after payment.
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_status_changed'], 25, 4);
        // Fires after WooCommerce grants download permissions for an order —
        // the moment the buyer can actually access their files.
        add_action('woocommerce_grant_product_download_permissions', [__CLASS__, 'on_permissions_granted'], 20, 1);
    }

    /** Resolve the active 3thix gateway instance, or null if unavailable. */
    private static function gateway() {
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        return isset($gateways['thix']) ? $gateways['thix'] : null;
    }

    /**
     * @param int           $order_id
     * @param string        $from
     * @param string        $to
     * @param WC_Order|null $order
     */
    public static function on_status_changed($order_id, $from = '', $to = '', $order = null) {
        self::sync_order($order instanceof WC_Order ? $order : $order_id);
    }

    /** @param int $order_id */
    public static function on_permissions_granted($order_id) {
        self::sync_order($order_id);
    }

    /**
     * True when every line item on the order is non-shippable — i.e. nothing
     * ever ships, so shipment tracking can never exist for it.
     *
     * NOTE: "downloadable" is NOT sufficient — WooCommerce lets a product be
     * downloadable AND still require shipping (e.g. a boxed game with a bonus
     * digital manual). Those orders must go through the tracking flow, so the
     * gate is needs_shipping() (falls back to is_virtual()), item by item.
     *
     * @param WC_Order $order
     * @return bool
     */
    public static function is_digital_order($order) {
        $items = $order->get_items('line_item');
        if (empty($items)) {
            return false;
        }
        foreach ($items as $item) {
            if (!method_exists($item, 'get_product')) {
                return false;
            }
            $product = $item->get_product();
            if (!$product) {
                return false;
            }
            if (method_exists($product, 'needs_shipping')) {
                if ($product->needs_shipping()) {
                    return false;
                }
            } elseif (!$product->is_virtual()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Build the stable per-order delivery reference. Prefers the first granted
     * download-permission id (a durable WooCommerce row tied to this order);
     * virtual-only orders fall back to the order id + order key, which never
     * change for the life of the order.
     *
     * @param WC_Order $order
     * @return string
     */
    public static function delivery_ref($order) {
        if (class_exists('WC_Data_Store')) {
            try {
                $data_store = WC_Data_Store::load('customer-download');
                if ($data_store && method_exists($data_store, 'get_downloads')) {
                    $downloads = $data_store->get_downloads([
                        'order_id' => $order->get_id(),
                        'orderby'  => 'permission_id',
                        'order'    => 'ASC',
                        'limit'    => 1,
                    ]);
                    if (is_array($downloads) && !empty($downloads)) {
                        $first = reset($downloads);
                        $pid = is_object($first) && method_exists($first, 'get_id') ? (int) $first->get_id() : 0;
                        if ($pid > 0) {
                            return 'wc-dl-' . $pid;
                        }
                    }
                }
            } catch (Exception $e) {
                // Fall through to the order-key reference.
            }
        }
        return 'wc-order-' . $order->get_id() . '-' . $order->get_order_key();
    }

    /**
     * Report the order's digital delivery to 3thix if it's new (or forced).
     *
     * @param WC_Order|int $order WooCommerce order or id.
     * @param bool         $force When true, bypass the opt-out setting and the
     *                            "already reported" de-dupe.
     */
    public static function sync_order($order, $force = false) {
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

        // Respect the merchant's auto-sync opt-out (same switch as tracking)
        // unless this is an explicit manual send.
        if (!$force && $gateway->get_option('auto_tracking', 'yes') !== 'yes') {
            return;
        }

        // Delivery only matters once the buyer has actually paid, and only
        // once the store considers the goods delivered (order completed —
        // WooCommerce auto-completes fully-virtual paid orders).
        if (!$order->is_paid() || !$order->has_status('completed')) {
            return;
        }

        if (!self::is_digital_order($order)) {
            return;
        }

        $delivery_ref = self::delivery_ref($order);

        // Skip if we've already reported this exact reference. Normalized the
        // same way 3thix de-dupes (case + whitespace) so formatting variants
        // never re-send.
        $sig = md5(strtolower(preg_replace('/\s+/', '', $delivery_ref)));
        if (!$force && (string) $order->get_meta('_thix_digital_synced_sig') === $sig) {
            return;
        }

        // Guard against re-entry from the $order->save() below.
        $oid = $order->get_id();
        if (!empty(self::$in_progress[$oid])) {
            return;
        }
        self::$in_progress[$oid] = true;

        try {
            // Inline orders create the 3thix payment under a client token
            // stored as `_thix_eoid`; redirect/embed orders use the numeric id.
            $eoid = (string) $order->get_meta('_thix_eoid');
            $external_order_id = $eoid !== '' ? $eoid : (string) $oid;

            $result = $gateway->api()->submit_digital_delivery($external_order_id, $delivery_ref);
            $code = isset($result['code']) ? (string) $result['code'] : '';

            if (!empty($result['ok'])) {
                $order->update_meta_data('_thix_digital_synced_sig', $sig);
                $order->update_meta_data('_thix_digital_synced', current_time('mysql'));
                $order->delete_meta_data('_thix_digital_fail_sig');
                $order->add_order_note(sprintf(
                    /* translators: %s: delivery reference */
                    __('Digital delivery auto-reported to 3thix (ref %s). Escrow releases after the buyer\'s confirmation window.', 'thix-payments'),
                    $delivery_ref
                ));
                $order->save();
            } elseif ($code === 'order_not_deliverable' || $code === 'not_digital_order' || $code === 'direct_merchant') {
                // Stay quiet:
                //  - order_not_deliverable: escrow not funded yet — a later
                //    status change / permission grant retries automatically.
                //  - not_digital_order: 3thix classified this order as shipped
                //    goods; the tracking auto-sync is the right path for it.
                //  - direct_merchant: no escrow release flow — nothing to report.
                // Noting any of these would spam the timeline on every save.
            } elseif ($code === 'delivery_already_reported' || $code === 'delivery_ref_in_use') {
                // Reported before (possibly with a different reference, e.g.
                // from the merchant's own systems). Record it once so retries
                // stop, without pretending this call succeeded.
                if ((string) $order->get_meta('_thix_digital_synced_sig') !== $sig) {
                    $order->update_meta_data('_thix_digital_synced_sig', $sig);
                    $order->add_order_note(sprintf(
                        /* translators: %s: server message */
                        __('3thix: digital delivery was already reported for this order. (%s)', 'thix-payments'),
                        isset($result['message']) ? $result['message'] : ''
                    ));
                    $order->save();
                }
            } else {
                // Surface anything else so the merchant can act on it — once
                // per failure code + reference, so repeated triggers don't
                // flood the timeline.
                $fail_sig = $code . '|' . $sig;
                if ($force || (string) $order->get_meta('_thix_digital_fail_sig') !== $fail_sig) {
                    $order->update_meta_data('_thix_digital_fail_sig', $fail_sig);
                    $order->add_order_note(sprintf(
                        /* translators: %s: error message */
                        __('3thix digital delivery auto-report failed: %s', 'thix-payments'),
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
