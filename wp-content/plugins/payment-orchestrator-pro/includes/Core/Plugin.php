<?php
/**
 * Plugin - Main plugin bootstrap class
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Core;

use PaymentOrchestrator\Admin\AdminController;
use PaymentOrchestrator\Frontend\CheckoutHandler;
use PaymentOrchestrator\Reports\DailyReporter;
use PaymentOrchestrator\Volume\VolumeTracker;
use PaymentOrchestrator\Recovery\FailureHandler;
use PaymentOrchestrator\Recovery\FailureClassifier;

final class Plugin {
    private Container $container;
    
    public function __construct(Container $container) {
        $this->container = $container;
    }
    
    /**
     * Boot the plugin
     */
    public function boot(): void {
        // Check for upgrades
        Activator::maybeUpgrade();
        
        // Admin hooks
        if (is_admin()) {
            $this->bootAdmin();
        }
        
        // Frontend hooks
        if (!is_admin() || wp_doing_ajax()) {
            $this->bootFrontend();
        }
        
        // Cron hooks
        $this->bootCron();
        
        // WooCommerce hooks
        $this->bootWooCommerce();
        
        // REST API
        $this->bootRestApi();

    }
    
    /**
     * Boot admin functionality
     */
    private function bootAdmin(): void {
        $admin = $this->container->get(AdminController::class);
        
        // Early hook for CSV export (before any output)
        add_action('admin_init', [$admin, 'handleCsvExport']);
        
        // Menu
        add_action('admin_menu', [$admin, 'registerMenu']);
        
        // Assets
        add_action('admin_enqueue_scripts', [$admin, 'enqueueAssets']);
        
        // 2.7.27: optional admin themes (Default renders exactly as before)
        (new \PaymentOrchestrator\Admin\Theme())->register();
        
        // Settings link
        add_filter('plugin_action_links_' . PO_PLUGIN_BASENAME, [$admin, 'addSettingsLink']);
        
        // AJAX handlers
        add_action('wp_ajax_po_get_logs', [$admin, 'ajaxGetLogs']);
        add_action('wp_ajax_po_clear_logs', [$admin, 'ajaxClearLogs']);
        add_action('wp_ajax_po_export_logs', [$admin, 'ajaxExportLogs']);
        add_action('wp_ajax_po_send_test_report', [$admin, 'ajaxSendTestReport']);
        add_action('wp_ajax_po_backfill_volume', [$admin, 'ajaxBackfillVolume']);
        add_action('wp_ajax_po_get_history', [$admin, 'ajaxGetHistory']);
        add_action('wp_ajax_po_simulate_rotation', [$admin, 'ajaxSimulateRotation']);
        add_action('wp_ajax_po_reset_sync_all', [$admin, 'ajaxResetSyncAll']);
        add_action('wp_ajax_po_clear_health', [$admin, 'ajaxClearHealth']);
        add_action('wp_ajax_po_drill_down', [$admin, 'ajaxDrillDown']);
        add_action('wp_ajax_po_toggle_orchestrator', [$admin, 'ajaxToggleOrchestrator']);
        add_action('wp_ajax_po_toggle_gateway', [$admin, 'ajaxToggleGateway']);
        add_action('wp_ajax_po_order_lookup', [$admin, 'ajaxOrderLookup']);
        add_action('wp_ajax_po_generate_api_key', [$admin, 'ajaxGenerateApiKey']);
        add_action('wp_ajax_po_revoke_api_key', [$admin, 'ajaxRevokeApiKey']);
    }
    
    /**
     * Boot frontend functionality
     */
    private function bootFrontend(): void {
        $checkout = $this->container->get(CheckoutHandler::class);
        
        // Clear session gateway preference on checkout load
        add_action('woocommerce_before_checkout_form', [$checkout, 'clearSessionGateway'], 5);
        add_action('wfacp_before_checkout_page_found', [$checkout, 'clearSessionGateway'], 5);
        
        // Filter available gateways
        add_filter('woocommerce_available_payment_gateways', [$checkout, 'filterGateways'], 100);
        
        // 2.7.22: keep the order's saved method in step with the gateway offered on its pay page
        add_action('template_redirect', [$checkout, 'alignPayPageGateway'], 5);
        
        // Override default gateway selection (clear session preference)
        add_filter('woocommerce_default_gateway', [$checkout, 'setDefaultGateway'], 100);
        
        // Handle order completion - record rotation
        add_action('woocommerce_checkout_order_processed', [$checkout, 'onOrderProcessed'], 10, 3);
        
        // Clear session lock AFTER payment completes (not before process_payment runs)
        add_action('woocommerce_payment_complete', [$checkout, 'onPaymentComplete'], 10, 1);
        
        // 2.7.30: highlight the field to fix after an address or CVV decline
        (new \PaymentOrchestrator\Frontend\FieldHint())->register();
        // 2.7.33: record the card brand on the order (for brand-aware POP Retry)
        (new \PaymentOrchestrator\Frontend\CardBrandCapture())->register();
        // 2.7.31: edit the billing address on the payment page after an address decline
        (new \PaymentOrchestrator\Frontend\PayPageAddress())->register();
        
        // 2.7.23: card-brand switch (offer / switch to a gateway that accepts the card)
        (new \PaymentOrchestrator\Frontend\CardBrandSwitch(
            $this->container->get(\PaymentOrchestrator\Gateway\GatewayRepository::class),
            $this->container->get(\PaymentOrchestrator\Routing\RotationManager::class)
        ))->register();
    }
    
    /**
     * Boot cron functionality
     */
    private function bootCron(): void {
        // Daily report
        add_action('po_daily_report', function (): void {
            if (get_option('po_daily_report_enabled', 'no') === 'yes') {
                $reporter = $this->container->get(DailyReporter::class);
                $reporter->send();
            }
        });
        
        // Hourly cleanup
        add_action('po_hourly_cleanup', function (): void {
            // Clean old transaction records (keep 90 days)
            global $wpdb;
            $wpdb->query(
                "DELETE FROM {$wpdb->prefix}po_transactions WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
            );
        });
        
        // Monthly reset check (runs daily, resets on the 1st)
        add_action('po_monthly_check', function (): void {
            $today = (int) current_time('j'); // Day of month
            
            // Only run on the 1st
            if ($today !== 1) {
                return;
            }
            
            // Check if we already reset this month
            $lastReset = get_option('po_last_monthly_reset', '');
            $currentMonth = current_time('Y-m');
            
            if ($lastReset === $currentMonth) {
                return; // Already reset this month
            }
            
            // Perform reset - Note: We don't delete data, we just let the new month start fresh
            // The monthly_volume calculations use current month_year, so old data naturally ages out
            
            // Mark as reset
            update_option('po_last_monthly_reset', $currentMonth);
            
            Logger::info('Monthly cycle started', ['month' => $currentMonth]);
        });
    }
    
    /**
     * Boot REST API for external dashboard consumption.
     */
    private function bootRestApi(): void {
        add_action('rest_api_init', function () {
            $restApi = new \PaymentOrchestrator\Api\RestApi($this->container);
            $restApi->registerRoutes();
        });
    }
    
    /**
     * Boot WooCommerce integration
     */
    private function bootWooCommerce(): void {
        $tracker = $this->container->get(VolumeTracker::class);
        $health = $this->container->get(\PaymentOrchestrator\Health\HealthMonitor::class);
        $failureHandler = $this->container->get(FailureHandler::class);
        
        // Track successful orders (volume + health)
        add_action('woocommerce_order_status_processing', function($orderId, $order) use ($tracker, $health, $failureHandler) {
            $tracker->recordTransaction($orderId, $order);
            $gatewayId = $order->get_payment_method();
            if ($gatewayId) {
                $health->recordSuccess($gatewayId);
            }
            // 2.7.28: paid on a pay-for-order page (recovery-email / retry link)
            if (\PaymentOrchestrator\Recovery\FailureHandler::isPayPagePaymentRequest() && (string) $order->get_meta('_po_paid_via_pay_page') === '') {
                $order->update_meta_data('_po_paid_via_pay_page', time());
                $order->save_meta_data();
                do_action('po_pay_page_paid', $order);
            }
            // Check if this was a recovered order (failed -> processing)
            $failureHandler->onOrderRecovered($orderId, $order);
        }, 10, 2);
        
        add_action('woocommerce_order_status_completed', function($orderId, $order) use ($tracker, $health, $failureHandler) {
            $tracker->recordTransaction($orderId, $order);
            $gatewayId = $order->get_payment_method();
            if ($gatewayId) {
                $health->recordSuccess($gatewayId);
            }
            // 2.7.28: paid on a pay-for-order page (recovery-email / retry link)
            if (\PaymentOrchestrator\Recovery\FailureHandler::isPayPagePaymentRequest() && (string) $order->get_meta('_po_paid_via_pay_page') === '') {
                $order->update_meta_data('_po_paid_via_pay_page', time());
                $order->save_meta_data();
                do_action('po_pay_page_paid', $order);
            }
            // Check if this was a recovered order (failed -> completed)
            $failureHandler->onOrderRecovered($orderId, $order);
        }, 10, 2);
        
        // Track failed orders (volume + health + recovery)
        //
        // GPay bridge (priority 4): When the official GPay plugin marks an order
        // failed, it stores the full API response in meta but doesn't write the
        // decline code to order notes. This reads that meta and writes a structured
        // note that POP can classify. Safe when our custom plugin is active - the
        // guard clause exits immediately if a structured note already exists.
        add_action('woocommerce_order_status_failed', function ($orderId, $order) {
            // Only relevant for GPay gateway IDs
            $gateway = $order->get_payment_method();
            if (!in_array($gateway, ['givepayments', 'give_payments_gateway'], true)) {
                return;
            }
            
            // Already has a structured note from our custom plugin - nothing to do
            $notes = wc_get_order_notes(['order_id' => $orderId, 'limit' => 5]);
            foreach ($notes as $note) {
                if (str_starts_with($note->content, 'G-Payments failure reason:')) {
                    return;
                }
            }
            
            // Read the error body the official plugin stored in meta
            $lastError = $order->get_meta('_givepayments_payment_last_error');
            if (empty($lastError) || !is_array($lastError)) {
                return;
            }
            
            $declineCode = $lastError['payment']['code'] ?? ($lastError['code'] ?? '');
            $declineMsg = $lastError['payment']['message'] ?? ($lastError['message'] ?? 'Payment failed.');
            
            if (empty($declineCode)) {
                return;
            }
            
            $order->add_order_note(
                sprintf('G-Payments failure reason: %s - %s', $declineCode, $declineMsg)
            );
        }, 4, 2);
        
        // IMPORTANT: FailureHandler runs at priority 5 to classify the failure.
        // Then VolumeTracker and HealthMonitor run at priority 10 with the classification available.
        add_action('woocommerce_order_status_failed', [$failureHandler, 'onOrderFailed'], 5, 2);
        
        add_action('woocommerce_order_status_failed', function($orderId, $order) use ($tracker, $health) {
            $tracker->recordFailure($orderId, $order);
            // Use _po_failed_gateway set by FailureHandler at priority 5.
            // get_payment_method() may already reflect the retry gateway by priority 10.
            $gatewayId = $order->get_meta('_po_failed_gateway') ?: $order->get_payment_method();
            if ($gatewayId) {
                $failureCategory = $order->get_meta('_po_failure_category');
                $countsAgainstHealth = FailureClassifier::countsAgainstHealth($failureCategory ?: '');
                $health->recordFailure($gatewayId, $countsAgainstHealth);
            }
        }, 10, 2);
        
        // 2.7.28: pay-for-order page tracking (retry and recovery-email visits)
        add_action('template_redirect', [$failureHandler, 'onPayPageViewed'], 6);
        add_action('woocommerce_after_pay_action', [$failureHandler, 'onPayPageAttemptFailed'], 20, 1);
        // A decline on an already-failed order: same volume + health recording as a normal failure
        add_action('po_pay_page_declined', function ($order, $classification) use ($tracker, $health) {
            $gatewayId = (string) $order->get_payment_method(); // the gateway that just declined
            $tracker->recordFailure($order->get_id(), $order, $gatewayId);
            if ($gatewayId) {
                $health->recordFailure($gatewayId, !empty($classification['counts_against_health']));
            }
        }, 10, 2);
        
        // Track refunds
        add_action('woocommerce_order_refunded', [$tracker, 'recordRefund'], 10, 2);
        
        // Retry gateway filtering on order-pay page
        // Priority 101: runs AFTER CheckoutHandler (100) so it can override on order-pay page
        add_filter('woocommerce_available_payment_gateways', [$failureHandler, 'filterRetryGateways'], 101);
        
        // Display stored retry message on pay-for-order page
        add_action('before_woocommerce_pay', [$failureHandler, 'displayRetryMessageOnPayPage']);
        
        // Track when customer attempts payment on retry pay page
        add_action('woocommerce_before_pay_action', [$failureHandler, 'trackRetryPaymentAttempt'], 10, 1);
        add_action('woocommerce_after_pay_action', [$failureHandler, 'releaseRetryPaymentLock'], 10, 1);
        
        // Inject auto-redirect JS on checkout for retryable failures
        add_action('woocommerce_after_checkout_form', [$failureHandler, 'injectRetryRedirectScript']);
    }
}
