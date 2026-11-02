<?php
/**
 * Recovery-email funnel (1.5.9).
 *
 * Listens to Payment Orchestrator Pro's pay-page events (POP 2.7.28+) and
 * records, on the order's clicked email sequence:
 *   landed_at     - first time the customer reached the pay page after clicking
 *   pay_attempts  - payment attempts on the pay page
 *   last_decline  - reason of the most recent decline on the pay page
 * Success ("paid via link") is recorded by SequenceManager::markRecovered().
 *
 * Without POP 2.7.28 these events never fire and nothing changes.
 */

namespace POPRecoveryEmail;

defined('ABSPATH') || exit;

class FunnelTracker {

    public function register(): void {
        add_action('po_pay_page_viewed', [$this, 'onViewed'], 10, 1);
        add_action('po_pay_page_attempt', [$this, 'onAttempt'], 10, 1);
        add_action('po_pay_page_declined', [$this, 'onDeclined'], 10, 2);
    }

    public function onViewed($order): void {
        $id = $this->orderId($order);
        if (!$id) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}po_email_sequences SET landed_at = %s
             WHERE order_id = %d AND clicked_at IS NOT NULL AND landed_at IS NULL",
            gmdate('Y-m-d H:i:s'), $id
        ));
    }

    public function onAttempt($order): void {
        $id = $this->orderId($order);
        if (!$id) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}po_email_sequences SET pay_attempts = pay_attempts + 1
             WHERE order_id = %d AND clicked_at IS NOT NULL AND status = 'sent'",
            $id
        ));
    }

    public function onDeclined($order, $classification = []): void {
        $id = $this->orderId($order);
        if (!$id) {
            return;
        }
        $reason = is_array($classification) ? (string) ($classification['reason'] ?? '') : '';
        if ($reason === '') {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}po_email_sequences SET last_decline = %s
             WHERE order_id = %d AND clicked_at IS NOT NULL AND status = 'sent'",
            mb_substr($reason, 0, 255), $id
        ));
    }

    private function orderId($order): int {
        if (is_object($order) && method_exists($order, 'get_id')) {
            return (int) $order->get_id();
        }
        return is_numeric($order) ? (int) $order : 0;
    }
}
