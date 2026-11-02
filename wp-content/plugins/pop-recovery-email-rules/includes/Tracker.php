<?php
/**
 * Open and click tracking.
 *
 * @package POPRecoveryEmail
 */

declare(strict_types=1);

namespace POPRecoveryEmail;

defined('ABSPATH') || exit;

final class Tracker {

    public function register(): void {
        // 1.5.8: priority 20, not 1. WooCommerce registers its order types at init:5,
        // so at init:1 wc_get_order() returned false for every order: the paid-order
        // and closed-order redirects never ran and the click-time attribution stamp
        // was skipped (found in staging: "Click: order check ... status: not_found").
        add_action('init', [$this, 'handleTrackingRequest'], 20);
    }

    public function handleTrackingRequest(): void {
        if (!isset($_GET['po_email_track'], $_GET['token'])) {
            return;
        }

        $action = sanitize_key($_GET['po_email_track']);
        $token  = sanitize_text_field($_GET['token']);

        if (empty($token) || strlen($token) !== 64) {
            return;
        }

        match ($action) {
            'open'  => $this->trackOpen($token),
            'click' => $this->trackClick($token),
            default => null,
        };
    }

    private function trackOpen(string $token): void {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');

        // Update log (first open only)
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}po_email_log SET opened_at = %s WHERE tracking_token = %s AND opened_at IS NULL",
            $now, $token
        ));

        // Update sequence
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}po_email_sequences SET opened_at = %s WHERE tracking_token = %s AND opened_at IS NULL",
            $now, $token
        ));

        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        // 1x1 transparent GIF (43 bytes)
        echo "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF\x21\xF9\x04\x01\x00\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x01\x44\x00\x3B";
        exit;
    }

    private function trackClick(string $token): void {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');

        // Get order ID and stored pay URL from sequence.
        // 1.5.8: SELECT * so a missing/renamed column can never turn every click into an
        // "invalid token" (in 1.5.7 the pay_url column did not exist, the query failed,
        // and every recovery click was redirected to the homepage).
        $seq = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}po_email_sequences WHERE tracking_token = %s LIMIT 1",
            $token
        ));

        if (!$seq) {
            \POPRecoveryEmail\Core\Logger::error('Click: token not found', [
                'token_prefix' => substr($token, 0, 12),
                'db_error'     => $wpdb->last_error ?: null,
            ]);
            wp_safe_redirect(wc_get_page_permalink('shop') ?: home_url());
            exit;
        }

        $orderId = (int) $seq->order_id;

        // Record click (first only)
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}po_email_log SET clicked_at = %s WHERE tracking_token = %s AND clicked_at IS NULL",
            $now, $token
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}po_email_sequences SET clicked_at = %s WHERE tracking_token = %s AND clicked_at IS NULL",
            $now, $token
        ));

        $order = wc_get_order($orderId);
        $orderStatus = $order ? $order->get_status() : 'not_found';
        $needsPayment = $order ? $order->needs_payment() : false;

        \POPRecoveryEmail\Core\Logger::debug('Click: order check', [
            'order_id'      => $orderId,
            'status'        => $orderStatus,
            'needs_payment' => $needsPayment,
        ]);

        // Order no longer needs payment
        if ($order && !$needsPayment) {
            $isPaid = $order->is_paid() || in_array($orderStatus, ['on-hold'], true);
            \POPRecoveryEmail\Core\Logger::info($isPaid ? 'Click: order already paid' : 'Click: order closed', [
                'order_id' => $orderId,
                'email'    => $seq->customer_email,
                'status'   => $orderStatus,
            ]);
            // 1.5.10: show a clear status page instead of redirecting - paid orders went
            // to the checkout "order received" page (looks like a new purchase and can
            // fire purchase tracking again); cancelled/refunded orders went to the shop.
            try {
                \POPRecoveryEmail\OrderStatusPage::render($order, $isPaid);
            } catch (\Throwable $e) {
                // Never show an error on a customer's click: fall back to the shop.
                \POPRecoveryEmail\Core\Logger::error('Click: status page failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
                wp_safe_redirect(wc_get_page_permalink('shop') ?: home_url());
            }
            exit;
        }

        // Build the pay URL - try multiple sources
        $payUrl = null;
        $payUrlSource = 'none';

        // 0. Use stored pay URL from when the email was built (most reliable)
        if (!empty($seq->pay_url ?? '')) {
            $payUrl = $seq->pay_url;
            $payUrlSource = 'stored';
        }

        // 1. Try POP's recovery URL (works for retryable orders)
        if (!$payUrl && class_exists('\\PaymentOrchestrator\\Recovery\\FailureHandler')) {
            try {
                $payUrl = \PaymentOrchestrator\Recovery\FailureHandler::getRecoveryPayUrl($orderId, 'email');
                if ($payUrl) $payUrlSource = 'pop';
            } catch (\Throwable $e) {
                \POPRecoveryEmail\Core\Logger::error('Click: POP getRecoveryPayUrl threw exception', [
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // 2. Fallback: WooCommerce order pay URL (works for all orders that need payment)
        if (!$payUrl && $order) {
            // needs_payment can return false for 'failed' in some WC versions
            // but get_checkout_payment_url works regardless of status
            try {
                $payUrl = $order->get_checkout_payment_url();
                if ($payUrl) {
                    $payUrlSource = 'wc_fallback';
                    $order->update_meta_data('_po_recovery_source', 'email');
                    $order->save();
                }
            } catch (\Throwable $e) {
                \POPRecoveryEmail\Core\Logger::error('Click: WC get_checkout_payment_url threw exception', [
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // 3. Last resort: generic checkout
        if (!$payUrl) {
            $payUrl = wc_get_checkout_url();
            $payUrlSource = 'generic_checkout';
        }

        // 1.5.8: attribute to email at CLICK time (no longer stamped when the email is
        // sent). POP's processRecovery() reads this when the order is paid.
        if ($order && $needsPayment && $order->get_meta('_po_recovery_source') !== 'email') {
            try {
                $order->update_meta_data('_po_recovery_source', 'email');
                $order->save();
            } catch (\Throwable $e) {
                \POPRecoveryEmail\Core\Logger::error('Click: could not stamp recovery source', [
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        \POPRecoveryEmail\Core\Logger::info('Click: redirecting to pay', [
            'order_id'   => $orderId,
            'email'      => $seq->customer_email,
            'url_source' => $payUrlSource,
            'url'        => $payUrl,
        ]);

        wp_safe_redirect($payUrl);
        exit;
    }
}
