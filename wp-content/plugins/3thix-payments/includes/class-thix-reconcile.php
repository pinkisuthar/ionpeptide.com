<?php
/**
 * Background sweep that completes 3thix orders whose payment succeeded but whose
 * WooCommerce status is still unpaid.
 *
 * Why this exists: a WooCommerce order is normally marked paid either by the
 * signed `order.paid` callback (STABLE/USDC escrow integrations ONLY) or when
 * the buyer is redirected back to the thank-you page (`reconcile_on_return`). A
 * DIRECT (card / Stripe Connect) merchant receives NO signed callback, so a
 * buyer who pays successfully on Stripe but never returns to the store (closed
 * the tab, lost connection, killed the redirect) would strand the order at
 * "pending payment" indefinitely even though the money was captured. This
 * WP-Cron sweep closes that gap by re-checking recent unpaid 3thix orders
 * against 3thix on a fixed cadence and completing any that actually paid.
 *
 * @package Thix_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Thix_Reconcile {
    /** Cron event hook name. */
    const HOOK = 'thix_reconcile_pending_orders';

    /** Custom cron schedule key. */
    const SCHEDULE = 'thix_five_minutes';

    /**
     * Age after which an unpaid 3thix order is treated as permanently abandoned
     * and flagged so future sweeps skip it. Comfortably past both the card (~2h)
     * and on-ramp (~24h) 3thix checkout abandonment windows, so a payment can
     * never still land after we give up — this keeps the sweep cheap over time.
     */
    const GIVE_UP_AFTER = 2 * DAY_IN_SECONDS;

    /** Meta flag stamped on orders we have stopped re-checking. */
    const SKIP_META = '_thix_reconcile_skip';

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'register_schedule']);
        add_action(self::HOOK, [__CLASS__, 'run']);
        self::ensure_scheduled();
    }

    /**
     * Register a 5-minute cron interval. NOTE: WP-Cron is traffic-triggered — on
     * a quiet store the sweep can lag until the next page view. A real server
     * cron hitting wp-cron.php makes the cadence exact.
     *
     * @param array $schedules
     * @return array
     */
    public static function register_schedule($schedules) {
        if (!isset($schedules[self::SCHEDULE])) {
            $schedules[self::SCHEDULE] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('Every 5 minutes (3thix reconcile)', 'thix-payments'),
            ];
        }
        return $schedules;
    }

    /** Schedule the recurring sweep if it isn't already queued. */
    public static function ensure_scheduled() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK);
        }
    }

    /** Remove the recurring sweep (called on plugin deactivation). */
    public static function clear() {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Resolve the live gateway instance for its configured API client and the
     * shared `reconcile_order_once()` completion logic.
     *
     * @return Thix_Gateway|null
     */
    private static function gateway() {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return null;
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        return isset($gateways['thix']) ? $gateways['thix'] : null;
    }

    /**
     * Re-check recent unpaid 3thix orders and complete any whose payment 3thix
     * confirms as captured. Bounded per run; abandoned orders past
     * GIVE_UP_AFTER are flagged so they are never re-checked again.
     */
    public static function run() {
        $gateway = self::gateway();
        if (!$gateway) {
            return;
        }

        $orders = wc_get_orders([
            'limit'          => 30,
            'payment_method' => 'thix',
            'status'         => ['pending', 'on-hold'],
            'date_created'   => '>' . (time() - 7 * DAY_IN_SECONDS),
            'orderby'        => 'date',
            'order'          => 'ASC',
            'return'         => 'objects',
            'meta_query'     => [
                [
                    'key'     => self::SKIP_META,
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        if (empty($orders)) {
            return;
        }

        foreach ($orders as $order) {
            // Parked for manual amount-mismatch review — never auto-complete.
            if ($order->get_meta('_thix_hold_review') === '1') {
                continue;
            }

            if ($gateway->reconcile_order_once($order)) {
                continue;
            }

            // Still unpaid and old enough that a 3thix payment can no longer
            // land: stop re-checking it so the sweep stays cheap over time.
            $created = $order->get_date_created();
            if ($created && ($created->getTimestamp() + self::GIVE_UP_AFTER) < time()) {
                $order->update_meta_data(self::SKIP_META, '1');
                $order->save();
            }
        }
    }
}
