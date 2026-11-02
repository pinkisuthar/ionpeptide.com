<?php
/**
 * WP Cron queue processor - single email per sequence.
 *
 * Uses smart pre-send verification:
 * - Retryable orders: checks POP's isRecoveryActive() (requires retry_eligible=yes)
 * - Non-retryable / pending: checks order status directly (needs_payment + not recovered)
 *
 * @package POPRecoveryEmail
 */

declare(strict_types=1);

namespace POPRecoveryEmail;

use PaymentOrchestrator\Recovery\FailureHandler;
use POPRecoveryEmail\Core\Logger;

defined('ABSPATH') || exit;

final class Scheduler {

    public function register(): void {
        add_action('prer_process_email_queue', [$this, 'processQueue']);
        add_action('prer_process_email_queue', [$this, 'autoCancelOnHoldOrders']);
    }

    public function processQueue(): void {
        if (get_option('prer_enabled', 'yes') !== 'yes') {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'po_email_sequences';
        $now   = gmdate('Y-m-d H:i:s');

        $sequences = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'pending' AND send_at <= %s ORDER BY send_at ASC LIMIT 50",
            $now
        ));

        if (empty($sequences)) {
            return;
        }

        Logger::info('Processing queue', ['count' => count($sequences)]);
        $sender = new Sender();

        foreach ($sequences as $seq) {
            $orderId  = (int) $seq->order_id;
            $category = $seq->failure_category ?? '';

            try {
                Logger::debug('Processing sequence', [
                    'order_id'   => $orderId,
                    'seq_id'     => $seq->id,
                    'category'   => $category,
                    'send_at'    => $seq->send_at,
                ]);

                // -- Smart pre-send verification based on category --
                if (!$this->isStillRecoverable($orderId, $category)) {
                    Logger::info('Cancelled: order no longer recoverable', [
                        'order_id' => $orderId,
                        'category' => $category,
                    ]);
                    SequenceManager::cancelSequenceForOrder($orderId, 'order_no_longer_active');
                    continue;
                }

                // Verify not unsubscribed
                if (SequenceManager::isUnsubscribed($seq->customer_email)) {
                    Logger::info('Cancelled: customer unsubscribed', ['order_id' => $orderId, 'email' => $seq->customer_email]);
                    SequenceManager::cancelSequencesForEmail($seq->customer_email, 'unsubscribed');
                    continue;
                }

                // Get pay URL - works for all categories (only checks needs_payment)
                $payUrl = $this->getPayUrl($orderId, $category);
                if (!$payUrl) {
                    Logger::error('Cancelled: no pay URL', ['order_id' => $orderId, 'category' => $category]);
                    SequenceManager::cancelSequenceForOrder($orderId, 'no_pay_url');
                    continue;
                }

                // Send it
                Logger::debug('Attempting send', ['order_id' => $orderId, 'email' => $seq->customer_email]);

                // Store pay URL in sequence for click-time fallback
                $wpdb->update($table, ['pay_url' => $payUrl], ['id' => $seq->id], ['%s'], ['%d']);

                $sent = $sender->send($seq, $payUrl);

                if ($sent) {
                    $wpdb->update($table, [
                        'status'  => 'sent',
                        'sent_at' => gmdate('Y-m-d H:i:s'),
                    ], ['id' => $seq->id], ['%s', '%s'], ['%d']);
                    Logger::info('Email sent', [
                        'order_id'    => $orderId,
                        'email'       => $seq->customer_email,
                        'category'    => $category,
                        'template_id' => $seq->template_id,
                    ]);
                } else {
                    Logger::error('Email send FAILED ' . "\u{2014}" . ' wp_mail returned false', ['order_id' => $orderId, 'email' => $seq->customer_email]);
                    // Mark as failed so it doesn't retry forever
                    $wpdb->update($table, [
                        'status'           => 'cancelled',
                        'cancelled_reason' => 'send_failed',
                    ], ['id' => $seq->id], ['%s', '%s'], ['%d']);
                }

            } catch (\Throwable $e) {
                Logger::error('EXCEPTION in queue processing', [
                    'order_id'  => $orderId,
                    'seq_id'    => $seq->id,
                    'error'     => $e->getMessage(),
                    'file'      => $e->getFile() . ':' . $e->getLine(),
                ]);
                // Cancel this sequence to prevent infinite retry loop
                SequenceManager::cancelSequenceForOrder($orderId, 'processing_error');
            }
        }

        // Expire old pending sequences
        $this->expireOld();
    }

    /**
     * Smart recovery check based on failure category.
     *
     * - Retryable categories: use POP's isRecoveryActive() (checks retry_eligible meta)
     * - Non-retryable / pending / other: check order status directly
     *   (the order just needs to still need payment and not be recovered in POP's table)
     */
    private function isStillRecoverable(int $orderId, string $category): bool {
        $order = wc_get_order($orderId);
        if (!$order) {
            Logger::debug('Not recoverable: order not found', ['order_id' => $orderId]);
            return false;
        }

        // Order must still need payment (covers processing, completed, cancelled, refunded)
        if (!$order->needs_payment()) {
            Logger::debug('Not recoverable: order no longer needs payment', [
                'order_id' => $orderId,
                'status'   => $order->get_status(),
            ]);
            return false;
        }

        // Check POP's failed_transactions - must not already be recovered
        global $wpdb;
        $recovered = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT recovered FROM {$wpdb->prefix}po_failed_transactions WHERE order_id = %d ORDER BY id DESC LIMIT 1",
            $orderId
        ));
        if ($recovered === 1) {
            Logger::debug('Not recoverable: already recovered in POP', ['order_id' => $orderId]);
            return false;
        }

        // For retryable categories, also check POP's retry_eligible meta
        // (this ensures POP's auto-redirect flow hasn't already handled it)
        $retryableCategories = ['retryable_gateway', 'retryable_card_type'];
        if (in_array($category, $retryableCategories, true)) {
            $retryEligible = $order->get_meta('_po_retry_eligible');
            if ($retryEligible === 'no') {
                // POP explicitly marked as not retry-eligible - but for email recovery
                // this is fine, the customer can still use the pay link manually.
                // Only block if the order was already recovered.
                Logger::debug('Retryable but retry_eligible=no, proceeding anyway (order still needs payment)', [
                    'order_id' => $orderId,
                ]);
            }
        }

        return true;
    }

    /**
     * Get the order's pay URL for the email.
     *
     * 1.5.8: does NOT stamp _po_recovery_source at send time. POP's
     * getRecoveryPayUrl() stamps 'email' on every call, so any later recovery -
     * auto-redirect or the customer paying directly - was credited to the email
     * even when it was never clicked (52 such credits in 30 days, 0 clicks).
     * The URL is the same WooCommerce pay link; the source is stamped on click
     * (Tracker::trackClick).
     */
    private function getPayUrl(int $orderId, string $category): ?string {
        $order = wc_get_order($orderId);
        if ($order && $order->needs_payment()) {
            $payUrl = $order->get_checkout_payment_url();
            Logger::debug('Pay URL resolved', ['order_id' => $orderId, 'source' => 'wc', 'url' => $payUrl]);
            return $payUrl;
        }

        Logger::debug('Pay URL failed', ['order_id' => $orderId, 'order_status' => $order ? $order->get_status() : 'not_found', 'needs_payment' => $order ? $order->needs_payment() : false]);
        return null;
    }

    private function expireOld(): void {
        global $wpdb;
        $hours  = (int) get_option('prer_sequence_expiry', 72);
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        $expired = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}po_email_sequences SET status = 'cancelled', cancelled_reason = 'expired' WHERE status = 'pending' AND created_at < %s",
            $cutoff
        ));
        if ($expired > 0) {
            Logger::info('Expired old sequences', ['count' => $expired, 'cutoff_hours' => $hours]);
        }
    }

    /**
     * Auto-cancel on-hold orders that have exceeded the configured time limit.
     *
     * Only affects orders from monitored gateways (prer_onhold_gateways setting).
     * Cancels the WC order with a note explaining the reason, and restocks items.
     */
    public function autoCancelOnHoldOrders(): void {
        if (get_option('prer_pending_enabled', 'no') !== 'yes') {
            return;
        }

        $cancelMinutes = (int) get_option('prer_onhold_cancel_after', 90);
        if ($cancelMinutes <= 0) {
            return; // 0 = disabled
        }

        $onHoldGateways = get_option('prer_onhold_gateways', []);
        if (!is_array($onHoldGateways) || empty($onHoldGateways)) {
            return; // No gateways configured - don't cancel anything
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($cancelMinutes * 60));

        // 1.5.8: page through on-hold orders older than the cutoff. Previously only the
        // 20 oldest were fetched; if those belonged to unmonitored gateways (e.g. bank
        // transfer), monitored orders behind them were never reached.
        $cancelledCount = 0;
        $maxCancelPerRun = 20;
        $orders = [];
        for ($page = 1; $page <= 10; $page++) {
            $batch = wc_get_orders([
                'status'       => 'on-hold',
                'date_created' => '<' . strtotime($cutoff),
                'limit'        => 50,
                'paged'        => $page,
                'orderby'      => 'date',
                'order'        => 'ASC',
                'return'       => 'ids',
            ]);
            if (empty($batch)) {
                break;
            }
            foreach ($batch as $o) {
                $orders[] = $o;
            }
            if (count($batch) < 50) {
                break;
            }
        }

        if (empty($orders)) {
            return;
        }

        foreach ($orders as $orderIdToCheck) {
            if ($cancelledCount >= $maxCancelPerRun) {
                break; // remaining orders are handled on the next 5-minute run
            }
            $order = wc_get_order($orderIdToCheck);
            if (!$order) {
                continue;
            }
            $orderId = $order->get_id();

            // Check if order's gateway matches our monitored list
            $paymentMethod = $order->get_payment_method();
            $popGateway    = $order->get_meta('_po_failed_gateway');
            $orderGateway  = $popGateway ?: $paymentMethod;

            $matched = false;
            foreach ($onHoldGateways as $gw) {
                if (!empty($gw) && stripos($orderGateway, $gw) !== false) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                continue;
            }

            // Don't cancel if order already has a payment (edge case: webhook arrived late)
            if (!$order->needs_payment()) {
                continue;
            }

            // Cancel the order
            $order->update_status(
                'cancelled',
                sprintf(
                    'Auto-cancelled by POP Email Recovery: order was on-hold for more than %d minutes without payment completion. Stock has been released.',
                    $cancelMinutes
                )
            );

            // Cancel any pending email sequence
            SequenceManager::cancelSequenceForOrder($orderId, 'order_auto_cancelled');

            Logger::info('Auto-cancelled on-hold order', [
                'order_id'   => $orderId,
                'gateway'    => $orderGateway,
                'on_hold_since' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '?',
                'cancel_after_min' => $cancelMinutes,
            ]);

            $cancelledCount++;
        }

        if ($cancelledCount > 0) {
            Logger::info('Auto-cancel batch complete', ['cancelled' => $cancelledCount]);
        }
    }
}
