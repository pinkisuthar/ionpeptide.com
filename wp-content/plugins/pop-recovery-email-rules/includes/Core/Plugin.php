<?php
declare(strict_types=1);
namespace POPRecoveryEmail\Core;
defined('ABSPATH') || exit;

final class Plugin {
    public function boot(): void {
        add_filter('cron_schedules', [$this, 'addCronInterval']);

        (new \POPRecoveryEmail\SequenceManager())->register();
        (new \POPRecoveryEmail\Scheduler())->register();
        (new \POPRecoveryEmail\Tracker())->register();
        (new \POPRecoveryEmail\FunnelTracker())->register(); // 1.5.9: pay-page funnel (POP 2.7.28 events)
        (new \POPRecoveryEmail\UnsubscribeHandler())->register();

        add_action('prer_purge_old_data', [$this, 'purgeOldData']);
        add_action('woocommerce_order_status_cancelled', [$this, 'onOrderCancelled']);
        add_action('woocommerce_order_status_refunded', [$this, 'onOrderCancelled']);

        if (is_admin()) {
            (new \POPRecoveryEmail\Admin\AdminController())->register();
        }
    }

    public function addCronInterval(array $schedules): array {
        $schedules['every_five_minutes'] = ['interval' => 300, 'display' => 'Every 5 Minutes'];
        return $schedules;
    }

    public function purgeOldData(): void {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-90 days'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}po_email_log WHERE sent_at < %s", $cutoff));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}po_email_sequences WHERE status != 'pending' AND created_at < %s", $cutoff));
    }

    public function onOrderCancelled(int $orderId): void {
        \POPRecoveryEmail\SequenceManager::cancelSequenceForOrder($orderId, 'order_cancelled');
    }
}
