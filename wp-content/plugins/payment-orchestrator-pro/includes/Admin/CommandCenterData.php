<?php
/**
 * Data for the Command Center dashboard (2.7.33).
 *
 * Builds every figure for one store day (site timezone). Tables use different
 * clocks: po_transactions and po_daily_stats are site time; po_failed_transactions
 * is UTC - failures use the site day's UTC range (SiteTime).
 *
 * @package PaymentOrchestrator\Admin
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Admin;

use PaymentOrchestrator\Core\SiteTime;
use PaymentOrchestrator\Recovery\FailureClassifier;

defined('ABSPATH') || exit;

final class CommandCenterData {

    /** Fixed, easy-to-tell-apart processor colours (assigned in gateway order). */
    public const COLORS = ['#378ADD', '#EF9F27', '#7F77DD', '#1D9E75', '#D4537E', '#5F5E5A'];

    /** Selected store day ('Y-m-d') from ?po_date=, never in the future. */
    public static function selectedDate(): string {
        $today = current_time('Y-m-d');
        $raw   = isset($_GET['po_date']) ? sanitize_text_field(wp_unslash((string) $_GET['po_date'])) : '';
        if ($raw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $today;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $raw));
        if (!checkdate($m, $d, $y)) {
            return $today;
        }
        return $raw > $today ? $today : $raw;
    }

    public static function shiftDate(string $date, int $days): string {
        return (new \DateTimeImmutable($date . ' 12:00:00', wp_timezone()))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /**
     * @param array<int,\PaymentOrchestrator\Gateway\Gateway> $gateways managed gateways (display order)
     */
    public static function build(string $date, array $gateways): array {
        global $wpdb;
        $txn  = $wpdb->prefix . 'po_transactions';
        $fail = $wpdb->prefix . 'po_failed_transactions';
        $ops  = $wpdb->prefix . 'wc_order_operational_data';
        [$utcStart, $utcEnd] = SiteTime::dayUtcRange($date);
        $lastWeek = self::shiftDate($date, -7);
        [$lwStart, $lwEnd] = SiteTime::dayUtcRange($lastWeek);
        $health = FailureClassifier::healthFaultSql('f.');

        $names = [];
        $colors = [];
        foreach (array_values($gateways) as $i => $g) {
            $names[$g->id]  = $g->displayName;
            $colors[$g->id] = self::COLORS[$i % count(self::COLORS)];
        }

        // -- Sales (site-time table)
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT gateway_id, SUM(status='success') AS ok, SUM(status='failed') AS bad,
                    COALESCE(SUM(IF(status='success', amount, 0)), 0) AS vol,
                    COUNT(DISTINCT IF(status='success', order_id, NULL)) AS paid_orders
             FROM {$txn} WHERE DATE(created_at) = %s GROUP BY gateway_id", $date));
        $sales = ['volume' => 0.0, 'approved' => 0, 'declined' => 0, 'paid_orders' => 0, 'by_gateway' => []];
        foreach ($rows as $r) {
            $sales['volume']   += (float) $r->vol;
            $sales['approved'] += (int) $r->ok;
            $sales['declined'] += (int) $r->bad;
            $sales['by_gateway'][$r->gateway_id] = ['volume' => (float) $r->vol, 'paid_orders' => (int) $r->paid_orders];
        }
        $sales['paid_orders'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT order_id) FROM {$txn} WHERE DATE(created_at) = %s AND status = 'success'", $date));
        $attempted = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT order_id) FROM {$txn} WHERE DATE(created_at) = %s AND status IN ('success','failed')", $date));
        $tries = $sales['approved'] + $sales['declined'];
        $sales['approval_rate']    = $tries > 0 ? round($sales['approved'] / $tries * 100, 1) : 0.0;
        $sales['order_conversion'] = $attempted > 0 ? round($sales['paid_orders'] / $attempted * 100, 1) : 0.0;

        // -- Failed orders, one row per order (UTC table)
        $orders = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT f.order_id,
                    MAX(f.order_total) AS total,
                    SUBSTRING_INDEX(GROUP_CONCAT(f.gateway_id ORDER BY f.id), ',', 1) AS first_gw,
                    MAX({$health}) AS proc,
                    MAX(f.failure_category = 'non_retryable') AS cust,
                    MAX(f.failure_category IN ('retryable_gateway','retryable_card_type')) AS retryable,
                    MAX(f.recovered) AS rec,
                    MAX(f.recovered = 1 AND f.recovery_source = 'email') AS rec_email,
                    MAX(f.failure_category IN ('retryable_gateway','retryable_card_type') AND f.retry_gateway IS NOT NULL) AS rerouted,
                    MAX(IF(f.recovered = 1, COALESCE(f.retry_gateway, f.gateway_id), NULL)) AS recovered_on,
                    MAX(op.date_paid_gmt IS NOT NULL) AS paid
             FROM {$fail} f LEFT JOIN {$ops} op ON op.order_id = f.order_id
             WHERE f.created_at >= %s AND f.created_at < %s
             GROUP BY f.order_id", $utcStart, $utcEnd));

        $failed = ['orders' => 0, 'value' => 0.0,
            'cats' => ['processor' => [0, 0.0], 'customer' => [0, 0.0], 'other' => [0, 0.0]],
            'outcome' => ['recovery' => 0, 'customer' => 0, 'unpaid' => 0], 'by_gateway' => []];
        $recovery = ['value' => 0.0, 'orders' => 0, 'retry_value' => 0.0, 'retry_orders' => 0,
            'email_value' => 0.0, 'email_orders' => 0, 'rerouted' => 0, 'rerouted_recovered' => 0,
            'no_eligible' => 0, 'by_gateway' => []];
        foreach ($orders as $o) {
            $total = (float) $o->total;
            $cat = (int) $o->proc ? 'processor' : ((int) $o->cust ? 'customer' : 'other');
            $failed['orders']++;
            $failed['value'] += $total;
            $failed['cats'][$cat][0]++;
            $failed['cats'][$cat][1] += $total;
            $gw = (string) $o->first_gw;
            $failed['by_gateway'][$gw] = $failed['by_gateway'][$gw] ?? ['orders' => 0, 'value' => 0.0, 'processor' => 0, 'customer' => 0];
            $failed['by_gateway'][$gw]['orders']++;
            $failed['by_gateway'][$gw]['value'] += $total;
            if ($cat !== 'other') {
                $failed['by_gateway'][$gw][$cat]++;
            }
            if ((int) $o->rec) {
                $failed['outcome']['recovery']++;
                $recovery['orders']++;
                $recovery['value'] += $total;
                if ((int) $o->rec_email) {
                    $recovery['email_orders']++;
                    $recovery['email_value'] += $total;
                } else {
                    $recovery['retry_orders']++;
                    $recovery['retry_value'] += $total;
                }
                $on = (string) $o->recovered_on;
                $recovery['by_gateway'][$on] = $recovery['by_gateway'][$on] ?? [0, 0.0];
                $recovery['by_gateway'][$on][0]++;
                $recovery['by_gateway'][$on][1] += $total;
            } elseif ((int) $o->paid) {
                $failed['outcome']['customer']++;
            } else {
                $failed['outcome']['unpaid']++;
            }
            if ((int) $o->retryable) {
                if ((int) $o->rerouted) {
                    $recovery['rerouted']++;
                    if ((int) $o->rec && !(int) $o->rec_email) {
                        $recovery['rerouted_recovered']++;
                    }
                } else {
                    $recovery['no_eligible']++;
                }
            }
        }

        // -- Same weekday last week
        $lw = [
            'volume' => (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$txn} WHERE DATE(created_at) = %s AND status = 'success'", $lastWeek)),
            'failed' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT order_id) FROM {$fail} WHERE created_at >= %s AND created_at < %s", $lwStart, $lwEnd)),
            'recovery' => (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(t), 0) FROM (SELECT MAX(order_total) AS t FROM {$fail}
                 WHERE created_at >= %s AND created_at < %s AND recovered = 1 GROUP BY order_id) x", $lwStart, $lwEnd)),
        ];

        // -- Last 7 days of volume, ending on the selected day
        $start7 = self::shiftDate($date, -6);
        $daily = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) AS d, SUM(amount) AS v FROM {$txn}
             WHERE created_at >= %s AND created_at < %s AND status = 'success' GROUP BY DATE(created_at)",
            $start7 . ' 00:00:00', self::shiftDate($date, 1) . ' 00:00:00')) as $r) {
            $daily[(string) $r->d] = (float) $r->v;
        }
        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = self::shiftDate($date, -$i);
            $week[] = ['date' => $d, 'volume' => $daily[$d] ?? 0.0];
        }
        $week7Recovery = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(t), 0) FROM (SELECT MAX(order_total) AS t FROM {$fail}
             WHERE created_at >= %s AND created_at < %s AND recovered = 1 GROUP BY order_id) x",
            SiteTime::dayUtcRange($start7)[0], $utcEnd));

        return [
            'date' => $date, 'last_week_date' => $lastWeek, 'is_today' => $date === current_time('Y-m-d'),
            'names' => $names, 'colors' => $colors,
            'sales' => $sales, 'failed' => $failed, 'recovery' => $recovery,
            'last_week' => $lw, 'week' => $week, 'week_recovery' => $week7Recovery,
        ];
    }

    /** Percentage change, or null when there is no base. */
    public static function change(float $now, float $before): ?float {
        return $before > 0 ? round(($now - $before) / $before * 100) : null;
    }
}
