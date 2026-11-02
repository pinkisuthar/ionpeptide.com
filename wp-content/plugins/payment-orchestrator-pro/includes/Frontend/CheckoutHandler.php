<?php
/**
 * CheckoutHandler - Handles checkout gateway display and rotation
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Frontend;

use PaymentOrchestrator\Gateway\GatewayRepository;
use PaymentOrchestrator\Gateway\Gateway;
use PaymentOrchestrator\Routing\RotationManager;
use PaymentOrchestrator\Volume\VolumeTracker;
use PaymentOrchestrator\Core\Logger;

final class CheckoutHandler {
    private GatewayRepository $gateways;
    private RotationManager $rotation;
    private VolumeTracker $volume;
    
    private static ?Gateway $selectedGateway = null;
    
    /** Session key for locked gateway selection */
    private const SESSION_KEY = 'po_locked_gateway';
    
    /** Session key for lock timestamp */
    private const SESSION_TIMESTAMP_KEY = 'po_locked_gateway_time';
    
    /** How long the lock is valid (in seconds) - 30 minutes */
    private const LOCK_DURATION = 1800;
    
    public function __construct(
        GatewayRepository $gateways,
        RotationManager $rotation,
        VolumeTracker $volume
    ) {
        $this->gateways = $gateways;
        $this->rotation = $rotation;
        $this->volume = $volume;
    }
    
    /**
     * Filter available payment gateways at checkout
     * 
     * CRITICAL FIX: Uses session locking to prevent race conditions with AJAX checkouts.
     * The gateway selected on page load is locked in session and reused on AJAX submit.
     * 
     * FAIL-SAFE: This method will NEVER return an empty array.
     * If no managed gateway is available, it falls back to showing all WooCommerce gateways.
     */
    public function filterGateways(array $wcGateways): array {
        // Only filter on checkout
        if (!$this->isCheckout()) {
            return $wcGateways;
        }
        
        // MASTER TOGGLE: If orchestrator is disabled, pass through all gateways untouched
        if (get_option('po_orchestrator_enabled', 'yes') !== 'yes') {
            return $wcGateways;
        }
        
        // NUCLEAR BYPASS: If manually enabled, skip ALL rotation logic
        if (get_option('po_bypass_enabled', 'no') === 'yes') {
            Logger::info('Bypass mode active ' . "\u{2014}" . ' exposing all WooCommerce gateways');
            return $wcGateways;
        }
        
        // AUTO-BYPASS: If all managed gateways are critical/blocked, expose everything
        if ($this->allGatewaysCritical()) {
            Logger::warning('All managed gateways are critical ' . "\u{2014}" . ' auto-bypass exposing all WooCommerce gateways');
            return $wcGateways;
        }
        
        // FAIL-SAFE: If WooCommerce has no gateways, nothing we can do
        if (empty($wcGateways)) {
            Logger::error('WooCommerce has no payment gateways available');
            return $wcGateways;
        }
        
        // 2.7.22: RETRY PAY PAGE - the order already has a designated retry gateway.
        // Do not rotate here: rotation would keep a single gateway and could drop the
        // retry gateway before FailureHandler::filterRetryGateways (priority 101)
        // runs, which then fell back to showing the FAILED gateway. That happened
        // whenever the pay page was opened without the checkout-time session lock
        // (recovery-email links, another device, an expired lock). Lock the session
        // to the retry gateway and pass the full list through for priority 101.
        $retryGatewayId = $this->retryGatewayForPayPage();
        if ($retryGatewayId !== '') {
            if (isset($wcGateways[$retryGatewayId])) {
                $this->lockGateway($retryGatewayId);
                self::$selectedGateway = $this->gateways->getById($retryGatewayId);
            }
            Logger::debug('Retry pay page: deferring to retry gateway', [
                'retry_gateway' => $retryGatewayId,
                'available'     => isset($wcGateways[$retryGatewayId]),
            ]);
            return $wcGateways;
        }
        
        // Get always-on gateways (PayPal, ACH, etc.)
        $alwaysOn = $this->gateways->getAlwaysOn();
        $alwaysOnIds = array_map(fn($g) => $g->id, $alwaysOn);
        
        // CRITICAL: Check for locked gateway in session first (prevents AJAX race condition)
        $lockedGatewayId = $this->getLockedGateway($wcGateways);
        
        if ($lockedGatewayId && isset($wcGateways[$lockedGatewayId])) {
            // Use the locked gateway - this is the key fix for the race condition
            $selected = $this->gateways->getById($lockedGatewayId);
            
            Logger::debug('Using session-locked gateway', [
                'gateway' => $lockedGatewayId,
                'is_ajax' => wp_doing_ajax(),
            ]);
        } else {
            // No valid lock - select a new gateway and lock it
            $selected = $this->rotation->selectGateway();
            
            // Lock the selection in session for subsequent requests
            if ($selected && isset($wcGateways[$selected->id])) {
                $this->lockGateway($selected->id);
            } elseif ($selected) {
                // Selected gateway not in WooCommerce - find fallback and lock that
                Logger::warning('Selected gateway not available in WooCommerce, trying fallback', [
                    'selected' => $selected->id,
                    'available_wc_gateways' => array_keys($wcGateways),
                ]);
                
                $fallbackId = $this->findFallbackManagedGateway($wcGateways);
                if ($fallbackId) {
                    $selected = $this->gateways->getById($fallbackId);
                    $this->lockGateway($fallbackId);
                    Logger::info('Using fallback managed gateway', ['fallback' => $fallbackId]);
                }
            }
        }
        
        // 2.7.33: on a payment page for an order whose card brand is known, never
        // offer a gateway that doesn't accept that brand (keeps rotation otherwise).
        if ($selected) {
            // Never let the brand step break the payment options: any error = brand unknown.
            try {
                $brandSafe = $this->brandSafeGateway($selected, $this->payPageCardBrand(), $wcGateways);
                if ($brandSafe->id !== $selected->id) {
                    $selected = $brandSafe;
                    $this->lockGateway($brandSafe->id);
                }
            } catch (\Throwable $e) {
                Logger::debug('Pay page brand check skipped', ['error' => $e->getMessage()]);
            }
        }
        
        self::$selectedGateway = $selected;
        
        // Build filtered list
        $result = [];
        
        // Add selected managed gateway first
        if ($selected && isset($wcGateways[$selected->id])) {
            $result[$selected->id] = $wcGateways[$selected->id];
        }
        
        // Add always-on gateways (only if they exist in WooCommerce)
        foreach ($alwaysOnIds as $gatewayId) {
            if (isset($wcGateways[$gatewayId]) && !isset($result[$gatewayId])) {
                $result[$gatewayId] = $wcGateways[$gatewayId];
            }
        }
        
        // Add any unmanaged gateways
        foreach ($wcGateways as $id => $gateway) {
            if (!isset($result[$id]) && !$this->gateways->isManaged($id)) {
                $result[$id] = $gateway;
            }
        }
        
        // FAIL-SAFE: Never return empty - always show at least one gateway
        if (empty($result)) {
            Logger::warning('No gateways after filtering, returning ALL WooCommerce gateways as fail-safe');
            return $wcGateways;
        }
        
        // SAFETY NET: During AJAX checkout submission, ensure the submitted payment_method
        // is always in the returned list. This prevents process_payment from failing when
        // the order already has a payment method set.
        if (wp_doing_ajax() && isset($_GET['wc-ajax']) && $_GET['wc-ajax'] === 'checkout') {
            $submittedMethod = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : '';
            if ($submittedMethod && isset($wcGateways[$submittedMethod]) && !isset($result[$submittedMethod])) {
                $result[$submittedMethod] = $wcGateways[$submittedMethod];
                Logger::warning('Safety net: added submitted payment method to results', [
                    'submitted_method' => $submittedMethod,
                    'selected' => $selected?->id,
                ]);
            }
        }
        
        Logger::debug('Gateways filtered', [
            'selected' => $selected?->id,
            'count' => count($result),
            'result_ids' => array_keys($result),
            'session_locked' => $lockedGatewayId !== null,
        ]);
        
        return $result;
    }
    
    /**
     * Get the locked gateway from session if valid
     * 
     * Returns null if:
     * - No lock exists
     * - Lock has expired
     * - Locked gateway is no longer available in WooCommerce
     * - Locked gateway has gone into critical health status
     */
    private function getLockedGateway(array $wcGateways): ?string {
        if (!function_exists('WC') || !WC()->session) {
            return null;
        }
        
        $lockedGatewayId = WC()->session->get(self::SESSION_KEY);
        $lockTime = WC()->session->get(self::SESSION_TIMESTAMP_KEY);
        
        if (!$lockedGatewayId || !$lockTime) {
            return null;
        }
        
        // Check if lock has expired
        if ((time() - $lockTime) > self::LOCK_DURATION) {
            Logger::debug('Gateway lock expired, will reselect', ['gateway' => $lockedGatewayId]);
            $this->clearLock();
            return null;
        }
        
        // Check if gateway still exists in WooCommerce
        if (!isset($wcGateways[$lockedGatewayId])) {
            Logger::warning('Locked gateway no longer available in WooCommerce', [
                'locked_gateway' => $lockedGatewayId,
                'available' => array_keys($wcGateways),
            ]);
            $this->clearLock();
            return null;
        }
        
        // Check if gateway has gone critical (optional - can be disabled for stability)
        // Only invalidate if the gateway is truly unhealthy, not just for score changes
        $gateway = $this->gateways->getById($lockedGatewayId);
        if ($gateway && !$gateway->isEnabled) {
            Logger::warning('Locked gateway is now disabled', ['gateway' => $lockedGatewayId]);
            $this->clearLock();
            return null;
        }
        
        // 2.7.23: the order total may have grown past the locked gateway's maximum
        // since the lock was set (cart changed within the 30-minute lock). Previously
        // the lock was kept and the over-limit gateway still offered, so the charge
        // was declined. Release the lock so rotation picks an eligible gateway.
        if ($gateway && $gateway->maxTransactionAmount > 0) {
            $total = $this->currentTotal();
            if ($total > $gateway->maxTransactionAmount) {
                Logger::info('Locked gateway below order total, reselecting', [
                    'gateway' => $lockedGatewayId,
                    'max'     => $gateway->maxTransactionAmount,
                    'total'   => $total,
                ]);
                $this->clearLock();
                return null;
            }
        }
        
        return $lockedGatewayId;
    }
    
    /**
     * Amount being paid: the order total on a pay-for-order page, else the cart total.
     *
     * @since 2.7.23
     */
    private function currentTotal(): float {
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            global $wp;
            $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
            $order   = $orderId ? wc_get_order($orderId) : null;
            return $order ? (float) $order->get_total() : 0.0;
        }
        if (function_exists('WC') && WC()->cart) {
            return (float) WC()->cart->get_total('edit');
        }
        return 0.0;
    }
    
    /**
     * Lock a gateway selection in session
     */
    private function lockGateway(string $gatewayId): void {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        
        WC()->session->set(self::SESSION_KEY, $gatewayId);
        WC()->session->set(self::SESSION_TIMESTAMP_KEY, time());
        
        Logger::debug('Gateway locked in session', [
            'gateway' => $gatewayId,
            'duration' => self::LOCK_DURATION,
        ]);
    }
    
    /**
     * Clear the gateway lock from session
     */
    private function clearLock(): void {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        
        WC()->session->set(self::SESSION_KEY, null);
        WC()->session->set(self::SESSION_TIMESTAMP_KEY, null);
    }
    
    /**
     * Find a fallback managed gateway that exists in WooCommerce
     * Used when the selected gateway is disabled in WooCommerce
     */
    private function findFallbackManagedGateway(array $wcGateways): ?string {
        $managed = $this->gateways->getManaged();
        
        foreach ($managed as $gateway) {
            if (isset($wcGateways[$gateway->id])) {
                return $gateway->id;
            }
        }
        
        // No managed gateway available in WooCommerce
        Logger::error('No managed gateways available in WooCommerce', [
            'managed_gateways' => array_map(fn($g) => $g->id, $managed),
            'wc_gateways' => array_keys($wcGateways),
        ]);
        
        return null;
    }
    
    /**
     * Clear gateway lock on checkout page load (non-AJAX only)
     * This ensures a fresh selection on each new checkout visit
     */
    public function clearSessionGateway(): void {
        if (!$this->isCheckout()) {
            return;
        }
        
        // Only clear on actual page loads, NOT on AJAX requests
        // This is critical - we don't want to clear the lock during wc-ajax=checkout
        if (wp_doing_ajax()) {
            return;
        }
        
        // Clear the WooCommerce chosen payment method
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('chosen_payment_method', null);
        }
        
        // Clear our gateway lock to allow fresh selection
        // Comment out the next line if you want locks to persist across page refreshes
        // $this->clearLock();
    }
    
    /**
     * Set the default gateway to our selected one (override session)
     */
    public function setDefaultGateway(string $default): string {
        if (!$this->isCheckout()) {
            return $default;
        }
        
        // If we have a selected gateway, use it as default
        if (self::$selectedGateway) {
            return self::$selectedGateway->id;
        }
        
        return $default;
    }
    
    /**
     * Handle order processed - record which gateway was used
     * 
     * CRITICAL: Do NOT clear the session lock here! WooCommerce's process_order_payment()
     * runs AFTER this hook and calls get_available_payment_gateways() again, which triggers
     * our filterGateways(). If the lock is cleared, filterGateways() will re-score and may
     * select a different gateway, causing process_payment() to never fire because the order's
     * payment_method won't exist in the filtered gateway list.
     * 
     * The lock is cleared in onPaymentComplete() instead, which runs after process_payment succeeds.
     */
    public function onOrderProcessed(int $orderId, array $postedData, \WC_Order $order): void {
        $gatewayId = $order->get_payment_method();
        
        if ($gatewayId && $this->gateways->isManaged($gatewayId)) {
            $this->volume->recordRotation($gatewayId);
        }
        
        // DO NOT clear the lock here - process_order_payment() still needs it
        // Lock is cleared in onPaymentComplete() after payment succeeds
    }
    
    /**
     * Handle payment complete - clear the lock so next checkout gets a fresh selection
     */
    public function onPaymentComplete(int $orderId): void {
        $this->clearLock();
        
        Logger::debug('Session lock cleared after payment complete', [
            'order_id' => $orderId,
        ]);
    }
    
    /**
     * Check if current page is checkout
     */
    /**
     * Align the order's saved payment method with the gateway POP offers on its
     * pay-for-order page.
     *
     * Other plugins treat the order's saved method as the selected gateway on the
     * pay page (e.g. "Payment Gateway Based Fees and Discounts" hides every
     * payment box except the saved method's after its fee AJAX returns; PC NMI
     * Accounts uses it to pick the NMI gateway). If the saved method is a POP
     * gateway that is NOT offered - the failed gateway on a retry page, or a
     * different account than rotation picked for a recovery-email visit - the
     * offered gateway's card box was collapsed and the customer could not pay.
     *
     * Runs on a normal page view only (never on the payment POST, where
     * WooCommerce sets the method itself), only with a valid order key, only for
     * orders that need payment, and only when the saved method belongs to POP.
     *
     * Hooked to 'template_redirect'.
     *
     * @since 2.7.22
     */
    public function alignPayPageGateway(): void {
        try {
            if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
                return;
            }
            if (!empty($_POST)) {
                return;
            }
            global $wp;
            $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
            if (!$orderId) {
                return;
            }
            $order = wc_get_order($orderId);
            if (!$order || !$order->needs_payment()) {
                return;
            }
            $key = isset($_GET['key']) && is_string($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
            if ($key === '' || !hash_equals($order->get_order_key(), $key)) {
                return;
            }
            $current = (string) $order->get_payment_method();
            if ($current === '' || !$this->gateways->getById($current)) {
                return; // Not a POP gateway - leave it alone.
            }
            if (!function_exists('WC') || !WC()->payment_gateways()) {
                return;
            }
            $available = WC()->payment_gateways()->get_available_payment_gateways(); // runs POP's filters
            if (isset($available[$current])) {
                return; // Already offered - nothing to align.
            }
            foreach ($available as $id => $gateway) {
                if ($this->gateways->getById((string) $id)) {
                    $order->set_payment_method($gateway);
                    $order->save();
                    Logger::info('Pay page: aligned order payment method with offered gateway', [
                        'order_id' => $orderId,
                        'from'     => $current,
                        'to'       => $id,
                    ]);
                    return;
                }
            }
        } catch (\Throwable $e) {
            Logger::debug('Pay page gateway alignment skipped', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * 2.7.33: the selected gateway, or - when it doesn't accept the known card
     * brand - the first enabled managed gateway (available in WooCommerce) that does.
     * Unknown brand, or no gateway accepts it: the selection is kept.
     */
    private function brandSafeGateway(\PaymentOrchestrator\Gateway\Gateway $selected, string $brand, array $wcGateways): \PaymentOrchestrator\Gateway\Gateway {
        if ($brand === '' || \PaymentOrchestrator\Gateway\CardBrands::accepts($selected, $brand)) {
            return $selected;
        }
        foreach ($this->gateways->getManaged() as $candidate) {
            if ($candidate->isEnabled && isset($wcGateways[$candidate->id])
                && \PaymentOrchestrator\Gateway\CardBrands::accepts($candidate, $brand)) {
                Logger::info('Pay page: gateway changed to one that accepts the card brand', ['from' => $selected->id, 'to' => $candidate->id, 'brand' => $brand]);
                return $candidate;
            }
        }
        return $selected;
    }
    
    /**
     * Card brand recorded on the order of the current pay-for-order page (valid key), or ''.
     */
    private function payPageCardBrand(): string {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
            return '';
        }
        global $wp;
        $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        $order   = $orderId ? wc_get_order($orderId) : null;
        if (!$order) {
            return '';
        }
        $key = isset($_GET['key']) && is_string($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
        if ($key === '' || !hash_equals($order->get_order_key(), $key)) {
            return '';
        }
        return \PaymentOrchestrator\Frontend\CardBrandCapture::forOrder($order);
    }
    
    /**
     * Retry gateway designated for the order on the current order-pay page, or ''.
     *
     * @since 2.7.22
     */
    private function retryGatewayForPayPage(): string {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
            return '';
        }
        global $wp;
        $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        if (!$orderId) {
            return '';
        }
        $order = wc_get_order($orderId);
        if (!$order) {
            return '';
        }
        return (string) $order->get_meta('_po_retry_gateway');
    }
    
    private function isCheckout(): bool {
        if (is_admin() && !wp_doing_ajax()) {
            return false;
        }
        
        // Standard WooCommerce checkout
        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }
        
        // FunnelKit detection - page load
        if (isset($_GET['wfacp_id'])) {
            return true;
        }
        
        // FunnelKit - AJAX checkout (critical for the fix)
        if (wp_doing_ajax()) {
            // Check if this is a WooCommerce checkout AJAX request
            $wcAjax = isset($_GET['wc-ajax']) ? $_GET['wc-ajax'] : '';
            if (in_array($wcAjax, ['checkout', 'update_order_review', 'apply_coupon', 'remove_coupon'])) {
                return true;
            }
            
            // FunnelKit-specific AJAX
            if (isset($_POST['wfacp_id']) || isset($_POST['_wfacp_post_id'])) {
                return true;
            }
        }
        
        // NOTE: Do NOT use a blanket `defined('WFACP_PLUGIN_FILE')` check here.
        // That constant is defined on every page load when FunnelKit is active,
        // which would cause filterGateways() to run on product pages, cart,
        // homepage, etc. - wasting DB queries and potentially interfering with
        // other plugins. The specific FunnelKit checks above (wfacp_id GET param,
        // AJAX POST params) already cover real checkout scenarios.
        
        // FunnelKit checkout page detection (non-AJAX) - catches edge cases where
        // is_checkout() returns false but we're actually on a FunnelKit checkout page
        if (!wp_doing_ajax() && function_exists('get_post_type') && get_post_type() === 'wfacp_checkout') {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if ALL managed gateways are failing in the last 15 minutes.
     * If true, the system auto-bypasses rotation and alerts the admin.
     */
    private function allGatewaysCritical(): bool {
        // Auto-bypass must be enabled
        if (get_option('po_auto_bypass_enabled', 'no') !== 'yes') {
            return false;
        }
        
        $managed = $this->gateways->getManaged();
        
        if (empty($managed)) {
            return false;
        }
        
        // Check if any enabled managed gateway exists
        $hasEnabled = false;
        foreach ($managed as $gateway) {
            if ($gateway->isEnabled) {
                $hasEnabled = true;
                break;
            }
        }
        
        if (!$hasEnabled) {
            $this->triggerAutoBypassAlert('All managed gateways are disabled.');
            return true;
        }
        
        // Check failure rates in a 15-minute window
        // Requirements: 
        //   - All enabled managed gateways must be failing (>90% failure rate)
        //   - Minimum 3 transactions per gateway
        //   - Failures must come from at least 3 unique customer accounts
        //     (prevents one bad card from triggering bypass)
        try {
            global $wpdb;
            $txnTable = $wpdb->prefix . 'po_transactions';
            $failTable = $wpdb->prefix . 'po_failed_transactions';
            
            $txnTableExists = $wpdb->get_var("SHOW TABLES LIKE '{$txnTable}'");
            if (!$txnTableExists) {
                return false;
            }
            
            $windowStart = gmdate('Y-m-d H:i:s', time() - 900); // 15 minutes ago
            $minSampleSize = 3;
            $minUniqueCustomers = 3;
            
            // First: check that failures come from at least 3 unique customers
            $failTableExists = $wpdb->get_var("SHOW TABLES LIKE '{$failTable}'");
            if ($failTableExists) {
                $uniqueFailingCustomers = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT customer_email) 
                    FROM {$failTable} 
                    WHERE created_at >= %s 
                    AND customer_email IS NOT NULL 
                    AND customer_email != ''",
                    $windowStart
                ));
                
                if ($uniqueFailingCustomers < $minUniqueCustomers) {
                    // Not enough unique customers failing - could be one bad card
                    return false;
                }
            }
            
            // Then: check each gateway's failure rate
            $allFailing = true;
            
            foreach ($managed as $gateway) {
                if (!$gateway->isEnabled) {
                    continue;
                }
                
                $stats = $wpdb->get_row($wpdb->prepare(
                    "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successes
                    FROM {$txnTable}
                    WHERE gateway_id = %s AND created_at >= %s",
                    $gateway->id,
                    $windowStart
                ));
                
                $total = (int) ($stats->total ?? 0);
                $successes = (int) ($stats->successes ?? 0);
                
                if ($total < $minSampleSize) {
                    $allFailing = false;
                    break;
                }
                
                $successRate = ($successes / $total) * 100;
                if ($successRate > 10) {
                    $allFailing = false;
                    break;
                }
            }
            
            if ($allFailing) {
                $this->triggerAutoBypassAlert(sprintf(
                    'All managed gateways are failing (>90%% failure rate in the last 15 minutes, %d unique customers affected).',
                    $uniqueFailingCustomers ?? 0
                ));
                return true;
            }
        } catch (\Throwable $e) {
            Logger::warning('Auto-bypass check failed', ['error' => $e->getMessage()]);
        }
        
        return false;
    }
    
    /**
     * Send auto-bypass alert email to admin (rate-limited to once per 30 minutes).
     */
    private function triggerAutoBypassAlert(string $reason): void {
        $lastAlertTime = (int) get_transient('po_auto_bypass_alert_sent');
        if ($lastAlertTime && (time() - $lastAlertTime) < 1800) {
            return; // Already alerted within 30 minutes
        }
        
        set_transient('po_auto_bypass_alert_sent', time(), 3600);
        
        try {
            $alertEmail = get_option('po_failure_alert_email', get_option('admin_email'));
            if (empty($alertEmail)) {
                return;
            }
            
            $siteName = get_bloginfo('name');
            $settingsUrl = admin_url('admin.php?page=payment-orchestrator-settings');
            
            $subject = '' . "\u{1F6A8}" . ' URGENT: Payment Orchestrator Auto-Bypass Activated ' . "\u{2014}" . ' ' . $siteName;
            
            $body = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:600px;margin:0 auto;">';
            $body .= '<div style="background:#7f1d1d;border:1px solid #ef4444;border-radius:12px;padding:24px;margin-bottom:20px;">';
            $body .= '<h2 style="margin:0 0 8px;color:#fca5a5;font-size:20px;">' . "\u{1F6A8}" . ' Auto-Bypass Activated</h2>';
            $body .= '<p style="margin:0;color:#fecaca;font-size:14px;">Rotation logic has been automatically disabled. All WooCommerce gateways are now exposed directly to customers.</p>';
            $body .= '</div>';
            
            $body .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
            $body .= '<tr><td style="padding:10px 0;color:#6b7280;border-bottom:1px solid #e5e7eb;width:100px;">Reason</td>';
            $body .= '<td style="padding:10px 0;color:#dc2626;border-bottom:1px solid #e5e7eb;font-weight:600;">' . esc_html($reason) . '</td></tr>';
            $body .= '<tr><td style="padding:10px 0;color:#6b7280;border-bottom:1px solid #e5e7eb;">Time</td>';
            $body .= '<td style="padding:10px 0;color:#111827;border-bottom:1px solid #e5e7eb;">' . current_time('M j, Y g:i A') . '</td></tr>';
            $body .= '<tr><td style="padding:10px 0;color:#6b7280;">Action Needed</td>';
            $body .= '<td style="padding:10px 0;color:#111827;">Check your payment gateways immediately. The orchestrator will resume normal rotation once gateways start succeeding again.</td></tr>';
            $body .= '</table>';
            
            $body .= '<p style="margin:20px 0 0;"><a href="' . esc_url($settingsUrl) . '" style="'
                . 'display:inline-block;background:#1e293b;color:#fff;padding:12px 24px;border-radius:6px;'
                . 'font-size:14px;font-weight:600;text-decoration:none;">Go to Settings</a></p>';
            
            $body .= '<p style="margin:16px 0 0;font-size:12px;color:#9ca3af;">This alert is rate-limited to once per 30 minutes. ' . "\u{2014}" . ' ' . esc_html($siteName) . '</p>';
            $body .= '</div>';
            
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            wp_mail($alertEmail, $subject, $body, $headers);
            
            Logger::warning('Auto-bypass alert sent', ['email' => $alertEmail, 'reason' => $reason]);
        } catch (\Throwable $e) {
            Logger::error('Failed to send auto-bypass alert', ['error' => $e->getMessage()]);
        }
    }
}
