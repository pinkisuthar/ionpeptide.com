<?php
/**
 * Manages single-email recovery sequences.
 *
 * Listens to POP hooks for ALL failure categories (configurable).
 * Also monitors pending/on-hold orders on POP-managed gateways.
 *
 * @package POPRecoveryEmail
 */

declare(strict_types=1);

namespace POPRecoveryEmail;

use PaymentOrchestrator\Recovery\FailureHandler;
use POPRecoveryEmail\Core\Logger;

defined('ABSPATH') || exit;

final class SequenceManager {

    public function register(): void {
        add_action('po_recovery_eligible', [$this, 'onRecoveryEligible'], 10, 3);
        add_action('po_order_recovered', [$this, 'onOrderRecovered'], 10, 3);

        // Monitor pending/on-hold orders for POP-managed gateways
        add_action('woocommerce_order_status_on-hold', [$this, 'onOrderPendingHold'], 10, 2);
        add_action('woocommerce_order_status_pending', [$this, 'onOrderPendingHold'], 10, 2);

        // Cancel if order completes via normal flow
        add_action('woocommerce_order_status_processing', [$this, 'onOrderCompleted'], 10);
        add_action('woocommerce_order_status_completed', [$this, 'onOrderCompleted'], 10);
    }

    public function onRecoveryEligible(int $orderId, array $data, \WC_Order $order): void {
        Logger::info('po_recovery_eligible fired', ['order_id' => $orderId, 'category' => $data['failure_category'] ?? '?', 'code' => $data['failure_code'] ?? '?', 'retry_eligible' => $data['retry_eligible'] ?? false]);

        if (get_option('prer_enabled', 'yes') !== 'yes') {
            Logger::debug('Skipped: plugin disabled', ['order_id' => $orderId]);
            return;
        }

        $category = $data['failure_category'] ?? '';
        $allowed  = get_option('prer_categories', ['retryable_gateway', 'retryable_card_type']);
        if (!is_array($allowed)) {
            $allowed = ['retryable_gateway', 'retryable_card_type'];
        }
        if (!in_array($category, $allowed, true)) {
            Logger::debug('Skipped: category not allowed', ['order_id' => $orderId, 'category' => $category, 'allowed' => $allowed]);
            return;
        }

        // Use category-specific source for delay routing
        $source = in_array($category, ['non_retryable', 'other'], true) ? 'non_retryable' : 'failed';
        $this->maybeEnqueue($orderId, $data, $source);
    }

    public function onOrderPendingHold(int $orderId, $orderOrId = null): void {
        if (get_option('prer_enabled', 'yes') !== 'yes') {
            return;
        }
        if (get_option('prer_pending_enabled', 'no') !== 'yes') {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        // Determine if this is a POP-managed order
        // Check 1: POP failure meta (order failed, then went to on-hold)
        $failedGateway = $order->get_meta('_po_failed_gateway');

        // Check 2: WC payment method for gateway-level detection
        //          Adyen orders go on-hold during 3DS - no POP failure meta exists
        $paymentMethod = $order->get_payment_method();

        // Must be a recognizable gateway order (not a manual admin order or COD)
        if (empty($failedGateway) && empty($paymentMethod)) {
            return;
        }

        // Get configured on-hold gateways (which gateways' on-hold orders to monitor)
        $onHoldGateways = get_option('prer_onhold_gateways', []);
        if (!is_array($onHoldGateways)) {
            $onHoldGateways = [];
        }

        // If specific gateways configured, check if this order's gateway is in the list
        if (!empty($onHoldGateways)) {
            $orderGateway = $failedGateway ?: $paymentMethod;
            $matched = false;
            foreach ($onHoldGateways as $gw) {
                if (stripos($orderGateway, $gw) !== false) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                Logger::debug('Skipped on-hold: gateway not in monitored list', [
                    'order_id' => $orderId,
                    'gateway'  => $orderGateway,
                    'monitored' => $onHoldGateways,
                ]);
                return;
            }
        }

        $gateway = $failedGateway ?: $paymentMethod;
        Logger::info('On-hold order detected', [
            'order_id' => $orderId,
            'gateway'  => $gateway,
            'status'   => $order->get_status(),
            'has_pop_meta' => !empty($failedGateway),
        ]);

        $data = [
            'failure_code'     => $order->get_meta('_po_failure_code') ?: 'on_hold',
            'failure_reason'   => 'Order is awaiting payment completion',
            'failure_category' => 'pending_payment',
            'is_retryable'     => true,
            'retry_eligible'   => true,
            'failed_gateway'   => $gateway,
            'order_total'      => (float) $order->get_total(),
            'customer_email'   => $order->get_billing_email(),
            'customer_name'    => $order->get_billing_first_name(),
            'card_type'        => $order->get_meta('_po_card_type') ?: '',
        ];

        $this->maybeEnqueue($orderId, $data, 'pending');
    }

    public function onOrderRecovered(int $orderId, array $data, \WC_Order $order): void {
        self::markRecovered($orderId);
    }

    public function onOrderCompleted(int $orderId): void {
        self::cancelSequenceForOrder($orderId, 'order_completed');
        self::markRecovered($orderId);
    }

    private function maybeEnqueue(int $orderId, array $data, string $source = 'failed'): void {
        $minValue = (float) get_option('prer_min_order_value', 0);
        if (($data['order_total'] ?? 0) < $minValue) {
            Logger::debug('Skipped: below min order value', ['order_id' => $orderId, 'total' => $data['order_total'] ?? 0, 'min' => $minValue]);
            return;
        }

        $email = strtolower(trim($data['customer_email'] ?? ''));
        if (empty($email)) {
            Logger::debug('Skipped: no customer email', ['order_id' => $orderId]);
            return;
        }

        if (self::isUnsubscribed($email)) {
            Logger::info('Skipped: customer unsubscribed', ['order_id' => $orderId, 'email' => $email]);
            return;
        }
        if (self::hasActiveSequence($email)) {
            Logger::debug('Skipped: active sequence exists', ['order_id' => $orderId, 'email' => $email]);
            return;
        }
        if (self::excessiveFailures($email)) {
            Logger::info('Skipped: excessive failures (24h limit)', ['order_id' => $orderId, 'email' => $email]);
            return;
        }

        $templateId = self::getActiveTemplateId();
        if (!$templateId) {
            Logger::error('Skipped: no active template', ['order_id' => $orderId]);
            return;
        }

        // Non-retryable failures (POP won't auto-redirect these).
        // 1.5.8: honour the "Non-Retryable Delay" setting (previously ignored - emails
        // always fired instantly, often while the customer was still retrying with
        // another card). 0 = send instantly inline; >0 = queue, and the pre-send check
        // cancels the email if the customer has paid by then.
        if ($source === 'non_retryable') {
            // Cooldown: skip if we already emailed this address recently
            if (self::recentlySent($email)) {
                Logger::info('Skipped: cooldown active', ['order_id' => $orderId, 'email' => $email]);
                return;
            }
            $nrDelay = max(0, (int) get_option('prer_non_retryable_delay', 2));
            if ($nrDelay === 0) {
                $this->sendInline($orderId, $data, $templateId);
                return;
            }
            self::createSequence($orderId, $data, $templateId, $nrDelay);
            return;
        }

        // Retryable and pending: queue with delay for cron
        $delayMinutes = match ($source) {
            'pending' => (int) get_option('prer_pending_delay', 120),
            default   => (int) get_option('prer_email_delay', 3),
        };

        self::createSequence($orderId, $data, $templateId, $delayMinutes);
    }

    /**
     * Send a recovery email immediately (inline, no cron).
     * Used for non-retryable failures where there's no reason to wait.
     */
    private function sendInline(int $orderId, array $data, int $templateId): void {
        try {
            $order = wc_get_order($orderId);
            if (!$order) {
                Logger::error('Inline send: order not found', ['order_id' => $orderId]);
                return;
            }

            // Create the sequence record (for tracking/reporting)
            global $wpdb;
            $token = bin2hex(random_bytes(32));
            $now   = gmdate('Y-m-d H:i:s');

            $wpdb->insert($wpdb->prefix . 'po_email_sequences', [
                'order_id'         => $orderId,
                'template_id'      => $templateId,
                'customer_email'   => strtolower($data['customer_email']),
                'failure_code'     => $data['failure_code'] ?? '',
                'failure_reason'   => $data['failure_reason'] ?? '',
                'failure_category' => $data['failure_category'] ?? '',
                'order_total'      => $data['order_total'] ?? 0,
                'status'           => 'pending',
                'send_at'          => $now,
                'tracking_token'   => $token,
                'created_at'       => $now,
            ], ['%d','%d','%s','%s','%s','%s','%f','%s','%s','%s','%s']);

            $seqId = $wpdb->insert_id;
            if (!$seqId) {
                Logger::error('Inline send: failed to create sequence', ['order_id' => $orderId]);
                return;
            }

            // Build sequence object for Sender
            $seq = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}po_email_sequences WHERE id = %d",
                $seqId
            ));

            // Get pay URL (1.5.8: no attribution stamp at send time - stamped on click)
            $payUrl = $order->needs_payment() ? $order->get_checkout_payment_url() : '';
            $payUrlSource = 'wc';

            if (!$payUrl) {
                Logger::error('Inline send: no pay URL ' . "\u{2014}" . ' order may not need payment', ['order_id' => $orderId, 'order_status' => $order->get_status(), 'needs_payment' => $order->needs_payment()]);
                $wpdb->update($wpdb->prefix . 'po_email_sequences',
                    ['status' => 'cancelled', 'cancelled_reason' => 'no_pay_url'],
                    ['id' => $seqId], ['%s', '%s'], ['%d']
                );
                return;
            }

            Logger::debug('Pay URL resolved', ['order_id' => $orderId, 'source' => $payUrlSource, 'url' => $payUrl]);

            // Store pay URL in sequence for click-time fallback
            $wpdb->update($wpdb->prefix . 'po_email_sequences',
                ['pay_url' => $payUrl],
                ['id' => $seqId], ['%s'], ['%d']
            );

            // Send it
            $sender = new Sender();
            $sent = $sender->send($seq, $payUrl);

            if ($sent) {
                $wpdb->update($wpdb->prefix . 'po_email_sequences', [
                    'status'  => 'sent',
                    'sent_at' => gmdate('Y-m-d H:i:s'),
                ], ['id' => $seqId], ['%s', '%s'], ['%d']);
                Logger::info('Inline email sent', [
                    'order_id'    => $orderId,
                    'email'       => $data['customer_email'],
                    'category'    => $data['failure_category'] ?? '',
                    'template_id' => $templateId,
                    'mode'        => 'inline',
                ]);
            } else {
                $wpdb->update($wpdb->prefix . 'po_email_sequences',
                    ['status' => 'cancelled', 'cancelled_reason' => 'send_failed'],
                    ['id' => $seqId], ['%s', '%s'], ['%d']
                );
                Logger::error('Inline send failed', ['order_id' => $orderId, 'email' => $data['customer_email']]);
            }

        } catch (\Throwable $e) {
            Logger::error('EXCEPTION in inline send', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
                'file'     => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    private static function createSequence(int $orderId, array $data, int $templateId, int $delayMinutes): void {
        global $wpdb;
        $sendAt = gmdate('Y-m-d H:i:s', time() + ($delayMinutes * 60));
        $token  = bin2hex(random_bytes(32));

        $wpdb->insert($wpdb->prefix . 'po_email_sequences', [
            'order_id'         => $orderId,
            'template_id'      => $templateId,
            'customer_email'   => strtolower($data['customer_email']),
            'failure_code'     => $data['failure_code'] ?? '',
            'failure_reason'   => $data['failure_reason'] ?? '',
            'failure_category' => $data['failure_category'] ?? '',
            'order_total'      => $data['order_total'] ?? 0,
            'status'           => 'pending',
            'send_at'          => $sendAt,
            'tracking_token'   => $token,
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ], ['%d','%d','%s','%s','%s','%s','%f','%s','%s','%s','%s']);

        error_log(sprintf('[POP Recovery Email] Queued order #%d ' . "\u{2192}" . ' %s (cat: %s, send: %s)', $orderId, $data['customer_email'], $data['failure_category'] ?? '?', $sendAt));
        Logger::info('Queued recovery email', ['order_id' => $orderId, 'email' => $data['customer_email'], 'category' => $data['failure_category'] ?? '?', 'template_id' => $templateId, 'send_at' => $sendAt, 'delay_min' => $delayMinutes]);
    }

    public static function cancelSequenceForOrder(int $orderId, string $reason = 'recovered'): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}po_email_sequences SET status='cancelled', cancelled_reason=%s WHERE order_id=%d AND status='pending'", $reason, $orderId));
    }

    /**
     * Record that an order was paid.
     *
     * 1.5.8: only credit the email when the customer actually clicked it. Previously
     * every paid order was marked "recovered", including orders paid via POP
     * auto-redirect or a direct retry, which inflated the email recovery numbers
     * (e.g. 912 "recovered" with 0 clicks). POP's recovery_source is not trusted
     * here: before 1.5.8 it was stamped 'email' when the email was SENT.
     *   - clicked                -> status 'recovered', log recovered = 1
     *   - sent but not clicked   -> status 'paid_direct' (not credited to email)
     */
    public static function markRecovered(int $orderId): void {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $seq = $wpdb->prefix . 'po_email_sequences';
        $log = $wpdb->prefix . 'po_email_log';

        // 1.5.9: an email is credited only when the customer clicked it AND paid
        // this same order on its pay page (the email's pay link). Anything else -
        // no click, or a click followed by paying another way - is paid_direct.
        if (self::paidViaPayLink($orderId)) {
            $wpdb->query($wpdb->prepare("UPDATE {$seq} SET status='recovered', recovered_at=%s, paid_via_link_at=%s WHERE order_id=%d AND status='sent' AND clicked_at IS NOT NULL", $now, $now, $orderId));
            $wpdb->query($wpdb->prepare("UPDATE {$log} SET recovered=1, recovered_at=%s WHERE order_id=%d AND recovered=0 AND clicked_at IS NOT NULL", $now, $orderId));
        }
        $wpdb->query($wpdb->prepare("UPDATE {$seq} SET status='paid_direct', recovered_at=%s WHERE order_id=%d AND status='sent'", $now, $orderId));
    }

    /**
     * Was this order paid on its pay-for-order page (the email's pay link)?
     * True during that payment request, or when POP recorded it on the order.
     */
    public static function paidViaPayLink(int $orderId): bool {
        if (!empty($_POST['woocommerce_pay']) && function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            return true;
        }
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        return $order && (string) $order->get_meta('_po_paid_via_pay_page') !== '';
    }

    public static function cancelSequencesForEmail(string $email, string $reason = 'unsubscribed'): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}po_email_sequences SET status='cancelled', cancelled_reason=%s WHERE customer_email=%s AND status='pending'", $reason, strtolower($email)));
    }

    public static function isUnsubscribed(string $email): bool {
        global $wpdb;
        $email = strtolower($email);

        // Check if expiry is enabled
        $expiryDays = (int) get_option('prer_unsub_expiry_days', 30);
        if ($expiryDays > 0) {
            $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$expiryDays} days"));
            return (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}po_email_unsubscribes WHERE customer_email=%s AND created_at>=%s LIMIT 1",
                $email, $cutoff
            ));
        }

        // 0 = never expires (permanent)
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}po_email_unsubscribes WHERE customer_email=%s LIMIT 1",
            $email
        ));
    }

    private static function hasActiveSequence(string $email): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}po_email_sequences WHERE customer_email=%s AND status='pending' LIMIT 1", strtolower($email)));
    }

    private static function excessiveFailures(string $email): bool {
        global $wpdb;
        $max = (int) get_option('prer_max_failures_24h', 2);
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-24 hours'));
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}po_email_sequences WHERE customer_email=%s AND created_at>=%s", strtolower($email), $cutoff)) >= $max;
    }

    /**
     * Check if we recently sent an email to this address (cooldown for inline sends).
     */
    private static function recentlySent(string $email): bool {
        global $wpdb;
        $minutes = (int) get_option('prer_inline_cooldown', 15);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($minutes * 60));
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}po_email_sequences WHERE customer_email=%s AND status IN ('sent','pending','paid_direct','recovered') AND created_at>=%s LIMIT 1",
            strtolower($email), $cutoff
        ));
    }

    private static function getActiveTemplateId(): int {
        $id = (int) get_option('prer_active_template_id', 0);
        if ($id) return $id;
        global $wpdb;
        return (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}po_email_templates WHERE status='active' ORDER BY id ASC LIMIT 1");
    }
}
