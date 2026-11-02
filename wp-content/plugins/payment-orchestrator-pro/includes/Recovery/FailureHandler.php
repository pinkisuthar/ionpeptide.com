<?php
/**
 * FailureHandler - Captures payment failures, triggers retries, shows friendly messages
 *
 * ARCHITECTURE:
 * This class runs AFTER WooCommerce's normal failure flow completes.
 * It hooks into woocommerce_order_status_failed (for capture/recording)
 * and checkout_order_processed error handling (for retry redirect).
 *
 * SAFETY:
 * Every public method is wrapped in try/catch. If any code here throws,
 * the customer sees normal WooCommerce error behavior - same as today.
 * This code can only help, never hurt.
 *
 * FLOW:
 * 1. Payment fails -> WooCommerce sets order to wc-failed
 * 2. onOrderFailed() fires: parse note, classify, store in po_failed_transactions
 * 3. If retryable: set order meta, return
 * 4. On the checkout error response: if retryable, add redirect to order-pay page
 * 5. If non-retryable: replace WooCommerce notice with friendly message
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Recovery;

use PaymentOrchestrator\Gateway\GatewayRepository;
use PaymentOrchestrator\Routing\RotationManager;
use PaymentOrchestrator\Core\Logger;

final class FailureHandler {
    private GatewayRepository $gateways;
    private RotationManager $rotation;
    
    /** Lock marker set by THIS request in trackRetryPaymentAttempt() (2.7.22). */
    private ?string $retryLockMarker = null;
    
    /** Orders whose failure was already recorded by the status hook in this request (2.7.28). */
    private array $failedThisRequest = [];
    
    /** Max retry attempts per order */
    private const MAX_RETRIES = 1;
    
    /** Order meta keys */
    private const META_RETRY_ELIGIBLE = '_po_retry_eligible';
    private const META_RETRY_GATEWAY = '_po_retry_gateway';
    private const META_RETRY_COUNT = '_po_retry_count';
    private const META_FAILED_GATEWAY = '_po_failed_gateway';
    private const META_FAILURE_CODE = '_po_failure_code';
    private const META_FAILURE_CATEGORY = '_po_failure_category';
    
    public function __construct(
        GatewayRepository $gateways,
        RotationManager $rotation
    ) {
        $this->gateways = $gateways;
        $this->rotation = $rotation;
    }
    
    /**
     * Handle order status changed to failed.
     * Parses the NMI note, classifies, stores in po_failed_transactions,
     * and determines if the order is eligible for retry.
     *
     * SAFETY: Wrapped in try/catch - failure here does not affect the order.
     */
    public function onOrderFailed(int $orderId, \WC_Order $order): void {
        $this->failedThisRequest[$orderId] = true;
        // DIAGNOSTIC: Log at the very top, before try/catch, to confirm hook fires
        Logger::info('>>> onOrderFailed FIRED', [
            'order_id' => $orderId,
            'gateway' => $order->get_payment_method(),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
        ]);
        
        try {
            $this->processFailure($orderId, $order);
        } catch (\Throwable $e) {
            Logger::error('FailureHandler error (non-fatal)', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 500),
            ]);
        }
    }
    
    /**
     * Internal: process the failure and store structured data.
     */
    private function processFailure(int $orderId, \WC_Order $order): void {
        $gatewayId = $order->get_payment_method();
        
        // Only handle managed gateways
        if (!$gatewayId || !$this->gateways->isManaged($gatewayId)) {
            Logger::warning('>>> processFailure EXIT: gateway not managed', [
                'order_id' => $orderId,
                'gateway' => $gatewayId,
                'is_managed' => $gatewayId ? $this->gateways->isManaged($gatewayId) : false,
            ]);
            return;
        }
        
        // Guard: if this order is already in a recovery attempt and the current
        // payment_method matches the designated retry gateway, this onOrderFailed
        // trigger is a false positive caused by WooCommerce updating payment_method
        // to the retry gateway before the order briefly touches Failed status during
        // the auto-redirect flow. The retry gateway never actually failed - skip.
        $retryGateway  = $order->get_meta(self::META_RETRY_GATEWAY);
        $failedGateway = $order->get_meta(self::META_FAILED_GATEWAY);
        if ($retryGateway && $gatewayId === $retryGateway && $failedGateway && $failedGateway !== $gatewayId) {
            Logger::info('>>> processFailure EXIT: false trigger during recovery redirect', [
                'order_id'      => $orderId,
                'gateway'       => $gatewayId,
                'retry_gateway' => $retryGateway,
                'failed_gateway'=> $failedGateway,
            ]);
            return;
        }
        
        // Check if recovery is enabled
        if (get_option('po_recovery_enabled', 'yes') !== 'yes') {
            Logger::warning('>>> processFailure EXIT: recovery disabled', ['order_id' => $orderId]);
            return;
        }
        
        // Find the failure note matching this specific gateway
        $failureNote = $this->findFailureNote($orderId, $gatewayId);
        if (!$failureNote) {
            Logger::warning('>>> processFailure EXIT: no failure note found', [
                'order_id' => $orderId,
                'gateway' => $gatewayId,
            ]);
            return;
        }
        
        // Classify the failure - route to the correct classifier based on note format
        if (str_starts_with($failureNote, 'G-Payments failure reason:')) {
            $classification = FailureClassifier::classifyGP($failureNote);
        } else {
            $classification = FailureClassifier::classify($failureNote);
        }
        
        Logger::info('>>> Failure classified', [
            'order_id' => $orderId,
            'gateway' => $gatewayId,
            'note' => mb_substr($failureNote, 0, 100),
            'code' => $classification['code'],
            'category' => $classification['category'],
            'is_retryable' => $classification['is_retryable'],
            'forced_gateway' => $classification['forced_retry_gateway'] ?? 'none',
        ]);
        
        // Get current retry count for this order
        $retryCount = (int) $order->get_meta(self::META_RETRY_COUNT);
        
        // Store in po_failed_transactions
        $this->recordFailure($orderId, $order, $gatewayId, $classification, $retryCount);
        
        // Store classification on order meta for downstream use
        $order->update_meta_data(self::META_FAILED_GATEWAY, $gatewayId);
        $order->update_meta_data(self::META_FAILURE_CODE, $classification['code']);
        $order->update_meta_data(self::META_FAILURE_CATEGORY, $classification['category']);
        
        // Determine retry eligibility
        if ($classification['is_retryable'] && $retryCount < self::MAX_RETRIES) {
            // Check for forced gateway (high-risk specific gateway mode)
            $forcedGateway = $classification['forced_retry_gateway'] ?? '';
            
            if ($forcedGateway && $forcedGateway !== $gatewayId) {
                // Use the specific forced gateway (if it's valid and available)
                $retryGateway = $this->validateForcedGateway($forcedGateway, $gatewayId, $classification, (float) $order->get_total());
            } else {
                // 2.7.33: the card's brand (recorded at payment) so the retry never
                // goes to a gateway that doesn't accept it
                $classification['card_brand'] = \PaymentOrchestrator\Frontend\CardBrandCapture::forOrder($order);
                $retryGateway = $this->findRetryGateway($gatewayId, $classification, (float) $order->get_total());
            }
            
            if ($retryGateway) {
                $order->update_meta_data(self::META_RETRY_ELIGIBLE, 'yes');
                $order->update_meta_data(self::META_RETRY_GATEWAY, $retryGateway);
                $order->update_meta_data(self::META_RETRY_COUNT, $retryCount + 1);
                
                Logger::info('Order eligible for retry', [
                    'order_id' => $orderId,
                    'failed_gateway' => $gatewayId,
                    'retry_gateway' => $retryGateway,
                    'failure_code' => $classification['code'],
                    'failure_category' => $classification['category'],
                    'retry_count' => $retryCount + 1,
                ]);
            } else {
                $order->update_meta_data(self::META_RETRY_ELIGIBLE, 'no');
                Logger::info('No alternative gateway for retry', [
                    'order_id' => $orderId,
                    'failed_gateway' => $gatewayId,
                ]);
            }
        } else {
            $order->update_meta_data(self::META_RETRY_ELIGIBLE, 'no');
        }
        
        // Override customer message for codes 201/253 on subsequent failures.
        // First attempt: neutral "not successful, no charges, try again."
        // Subsequent attempts: mention bank security filter + suggest different card.
        $failCode = $classification['code'] ?? '';
        // 2.7.25: pick-up-card declines (250-253, any "pick up card" text) are aligned
        // with 201 as processor-side declines in this vertical.
        $isProcessorDecline = in_array($failCode, ['201', '250', '251', '252', '253'], true)
            || stripos((string) ($classification['reason'] ?? ''), 'pick up card') !== false;
        if ($isProcessorDecline && $retryCount > 0) {
            $classification['customer_message'] = 'This transaction was not successful and no charges were made to your card. Your bank\'s security filter may have blocked the request. Please try again with a different card.';
            
            Logger::info('Escalated customer message for repeat 201/253 failure', [
                'order_id' => $orderId,
                'code' => $failCode,
                'retry_count' => $retryCount,
            ]);
        }
        
        $order->save();
        
        Logger::info('>>> Order meta saved', [
            'order_id' => $orderId,
            'retry_eligible' => $order->get_meta(self::META_RETRY_ELIGIBLE),
            'retry_gateway' => $order->get_meta(self::META_RETRY_GATEWAY),
            'failed_gateway' => $order->get_meta(self::META_FAILED_GATEWAY),
        ]);
        
        // Replace WooCommerce's generic error notice with our friendly message.
        // This runs during the checkout AJAX request - the order status change
        // fires before WC collects notices for the JSON response.
        // Only modify notices during checkout AJAX (not admin or cron contexts).
        $isCheckout = $this->isCheckoutContext();
        Logger::info('>>> Checkout context check', [
            'order_id' => $orderId,
            'is_checkout' => $isCheckout,
            'is_ajax' => wp_doing_ajax(),
            'action' => isset($_REQUEST['wc-ajax']) ? $_REQUEST['wc-ajax'] : (isset($_REQUEST['action']) ? $_REQUEST['action'] : 'none'),
        ]);
        
        if ($isCheckout) {
            $this->replaceCustomerNotice($order, $classification);
        }
        
        // Fire action hook for external plugins (e.g., POP Recovery Email Rules)
        // This fires AFTER all meta is saved and classification is complete.
        // The pay_url is only available if the order is retry-eligible.
        $retryEligible = $order->get_meta(self::META_RETRY_ELIGIBLE) === 'yes';
        $payUrl = $retryEligible ? $order->get_checkout_payment_url() : '';
        
        /**
         * Fires when a payment failure has been classified and recorded.
         *
         * @param int    $orderId        The WooCommerce order ID.
         * @param array  $recoveryData   Structured failure + retry data.
         * @param \WC_Order $order       The WooCommerce order object.
         */
        do_action('po_recovery_eligible', $orderId, [
            'failure_code'     => $classification['code'],
            'failure_reason'   => $classification['reason'],
            'failure_category' => $classification['category'],
            'is_retryable'     => $classification['is_retryable'],
            'failed_gateway'   => $gatewayId,
            'retry_gateway'    => $order->get_meta(self::META_RETRY_GATEWAY) ?: '',
            'retry_eligible'   => $retryEligible,
            'retry_count'      => (int) $order->get_meta(self::META_RETRY_COUNT),
            'order_total'      => (float) $order->get_total(),
            'customer_email'   => $order->get_billing_email(),
            'customer_name'    => $order->get_billing_first_name(),
            'pay_url'          => $payUrl,
            'card_type'        => $classification['card_type'] ?? '',
        ], $order);
    }
    
    /**
     * Check if we're in a checkout AJAX context (not admin, not cron).
     * We only want to modify customer-facing notices during actual checkout.
     */
    private function isCheckoutContext(): bool {
        // Must have WC notices system available
        if (!function_exists('wc_clear_notices')) {
            return false;
        }
        
        // Checkout AJAX calls
        if (wp_doing_ajax()) {
            $action = $_GET['wc-ajax'] ?? '';
            return in_array($action, ['checkout', 'update_order_review'], true);
        }
        
        // Direct checkout page (non-AJAX fallback)
        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Replace WooCommerce's generic error notice with a friendly, actionable message.
     *
     * For RETRYABLE failures: message with a link to retry on order-pay page.
     * For NON-RETRYABLE failures: friendly explanation of what went wrong.
     *
     * SAFETY: Wrapped in try/catch. If this fails, customer sees original WC notice.
     */
    private function replaceCustomerNotice(\WC_Order $order, array $classification): void {
        try {
            // Clear the gateway's raw error notice
            wc_clear_notices();
            
            $retryEligible = $order->get_meta(self::META_RETRY_ELIGIBLE);
            $retryGateway = $order->get_meta(self::META_RETRY_GATEWAY);
            
            if ($retryEligible === 'yes' && $retryGateway) {
                // Store the customer message in order meta for display on pay page
                $customerMessage = $classification['customer_message'] 
                    ?: 'There was an error processing your payment. Please try again.';
                $order->update_meta_data('_po_retry_customer_message', $customerMessage);
                
                // Retry funnel tracking: mark when redirect was set up
                $order->update_meta_data('_po_retry_redirect_set', current_time('mysql'));
                
                // CRITICAL: Pre-stamp the order's payment method to the retry gateway.
                // Without this, the order still has the FAILED gateway's ID as its
                // _payment_method when the customer reaches the order-pay page.
                // For Direct Post gateways (like NMI2), the return URL creates a
                // separate PHP session that can race with the form submission,
                // causing process_payment to fire on BOTH gateways simultaneously.
                // By setting _payment_method here, both sessions will see the same
                // gateway and the duplicate session's process_payment is harmless.
                $wcGateways = WC()->payment_gateways()->get_available_payment_gateways();
                if (isset($wcGateways[$retryGateway])) {
                    $order->set_payment_method($wcGateways[$retryGateway]);
                } else {
                    // Fallback: set just the ID if WC gateway object not available
                    $order->set_payment_method($retryGateway);
                }
                
                $order->save();
                
                // Set the retry gateway in session for the order-pay page
                if (function_exists('WC') && WC()->session) {
                    WC()->session->set('chosen_payment_method', $retryGateway);
                    WC()->session->set('po_locked_gateway', $retryGateway);
                    WC()->session->set('po_locked_gateway_time', time());
                }
                
                $payUrl = $order->get_checkout_payment_url();
                
                // Embed the redirect URL directly in the error notice HTML.
                // WooCommerce checkout AJAX returns the notices HTML in the response.
                // Our JS on the checkout page reads the data attribute and redirects.
                // 2.7.25: show the same classifier message the retry pay page and the
                // recovery email use (previously a fixed "your card was not charged",
                // which is not safe to say after a processor timeout).
                $redirectDetail = $classification['customer_message']
                    ?: 'There was an error processing your payment. Please try again.';
                $message = '<div id="po-retry-redirect" data-url="' . esc_attr($payUrl) . '">'
                    . '<span style="color:#c0392b; font-weight:600; display:block; font-size:15px; margin-bottom:8px;">'
                    . esc_html($redirectDetail)
                    . '</span>'
                    . '<span style="color:#555; font-size:13px;">Redirecting you to try again' . "\u{2026}" . '</span>'
                    . '</div>';
                
                wc_add_notice($message, 'error');
                
                $order->add_order_note(sprintf(
                    'Payment Orchestrator: Auto-redirecting to order-pay page for retry on %s (original gateway %s failed with %s)',
                    $retryGateway,
                    $order->get_meta(self::META_FAILED_GATEWAY),
                    $classification['code'] ?: 'unknown'
                ));
                
                Logger::info('Auto-redirect to retry pay page', [
                    'order_id' => $order->get_id(),
                    'retry_gateway' => $retryGateway,
                    'pay_url' => $payUrl,
                ]);
            } else {
                // Non-retryable: show the friendly message
                $friendlyMessage = $classification['customer_message'] 
                    ?: 'There was an error processing your payment. Please try again.';
                
                $styledMessage = '<strong style="display:block; font-size:15px; color:#c0392b; margin-bottom:4px;">'
                    . esc_html($friendlyMessage) . '</strong>'
                    . FailureClassifier::fixMarker(FailureClassifier::fixField($classification)); // 2.7.30
                
                wc_add_notice($styledMessage, 'error');
                
                Logger::debug('Customer shown friendly error', [
                    'order_id' => $order->get_id(),
                    'code' => $classification['code'],
                    'message' => $friendlyMessage,
                ]);
            }
        } catch (\Throwable $e) {
            Logger::error('Notice replacement failed (non-fatal)', [
                'order_id' => $order->get_id(),
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Find a failure note on an order.
     * Reads the most recent notes and returns the first match for any supported gateway.
     */
    private function findFailureNote(int $orderId, string $gatewayId = ''): ?string {
        $notes = wc_get_order_notes([
            'order_id' => $orderId,
            'limit'    => 20,
            'orderby'  => 'date_created',
            'order'    => 'DESC',
        ]);

        // Determine which note prefix(es) belong to the current gateway.
        // NMI-family gateways write "NMI failure reason:" notes.
        // GivePay (g_payments) writes "G-Payments failure reason:" notes.
        // If no gatewayId provided, fall back to accepting either (legacy behaviour).
        $isGP  = str_contains($gatewayId, 'g_payment') || str_contains($gatewayId, 'gpayment');
        $isNMI = !$isGP && $gatewayId !== '';

        foreach ($notes as $note) {
            $isNMINote = str_starts_with($note->content, 'NMI failure reason:');
            $isGPNote  = str_starts_with($note->content, 'G-Payments failure reason:');

            if (!$isNMINote && !$isGPNote) {
                continue;
            }

            // If we know the gateway, only accept notes from that gateway.
            // This prevents a GivePay onOrderFailed from picking up an older NMI note
            // when the GP failure note hasn't been written yet (race condition).
            if ($isNMI && $isNMINote) return $note->content;
            if ($isGP  && $isGPNote)  return $note->content;

            // No gateway context - accept either (covers edge cases and future gateways)
            if (!$isNMI && !$isGP) return $note->content;
        }

        return null;
    }
    
    /**
     * Validate a forced retry gateway (from high-risk specific mode).
     * Returns the gateway ID if valid, or falls back to auto-selection.
     */
    private function validateForcedGateway(string $forcedId, string $failedGatewayId, array $classification = [], float $orderTotal = 0): ?string {
        // 2.7.22: this class has no $container; using it was a fatal error that
        // aborted failure processing whenever high-risk "specific" mode was on.
        $forced = $this->gateways->getById($forcedId);
        
        if (!$forced) {
            Logger::warning('Forced retry gateway not found, falling back to auto', ['forced' => $forcedId]);
            return $this->findRetryGateway($failedGatewayId, $classification, $orderTotal);
        }
        
        if (!$forced->isEnabled) {
            Logger::warning('Forced retry gateway is disabled, falling back to auto', ['forced' => $forcedId]);
            return $this->findRetryGateway($failedGatewayId, $classification, $orderTotal);
        }
        
        $brand = (string) ($classification['card_brand'] ?? '');
        if ($brand !== '' && !\PaymentOrchestrator\Gateway\CardBrands::accepts($forced, $brand)) {
            Logger::warning('Forced retry gateway does not accept the card brand, falling back to auto', ['forced' => $forcedId, 'brand' => $brand]);
            return $this->findRetryGateway($failedGatewayId, $classification, $orderTotal);
        }
        
        Logger::info('Using forced retry gateway (high-risk specific mode)', [
            'forced_gateway' => $forcedId,
            'failed_gateway' => $failedGatewayId,
        ]);
        
        return $forcedId;
    }
    
    /**
     * Find the best alternative gateway for retry.
     * Excludes the failed gateway. For card-type failures, only considers
     * gateways that accept the rejected card type.
     */
    private function findRetryGateway(string $failedGatewayId, array $classification, float $orderTotal = 0): ?string {
        $managed = $this->gateways->getManaged();
        $overMaxGateways = [];
        
        $isCardType  = ($classification['category'] ?? '') === FailureClassifier::CATEGORY_RETRYABLE_CARD_TYPE;
        $cardType    = (string) ($classification['card_type'] ?? '');
        // 2.7.22: when the refused brand is unknown (e.g. "Card Type Not Accepted"
        // with no brand), prefer a gateway that accepts every brand, so the retry
        // does not land on another card-restricted gateway.
        $preferFullSupport = $isCardType && $cardType === '';
        $restrictedFallback = null;
        
        foreach ($managed as $gateway) {
            // Skip the gateway that just failed
            if ($gateway->id === $failedGatewayId) {
                continue;
            }
            
            // Skip disabled gateways
            if (!$gateway->isEnabled) {
                continue;
            }
            
            // For card-type failures, check if the alternative accepts that card type.
            // 2.7.22: checked BEFORE the max-amount bucket, so the over-max fallback
            // can never pick a gateway that refuses the card.
            if ($isCardType && !\PaymentOrchestrator\Gateway\CardBrands::accepts($gateway, $cardType)) {
                continue;
            }
            // 2.7.33: for every failure, skip gateways that don't accept the card's
            // recorded brand (e.g. a gateway that dropped Mastercard).
            $knownBrand = (string) ($classification['card_brand'] ?? '');
            if ($knownBrand !== '' && !\PaymentOrchestrator\Gateway\CardBrands::accepts($gateway, $knownBrand)) {
                continue;
            }
            
            // Skip if order exceeds gateway's max transaction amount
            // (saved for fallback - if no gateways pass, use highest max)
            if ($gateway->maxTransactionAmount > 0 && $orderTotal > 0 && $orderTotal > $gateway->maxTransactionAmount) {
                $overMaxGateways[] = $gateway;
                continue;
            }
            
            // Check health - don't send retries to an unhealthy gateway
            // Use RotationManager's health monitor
            $healthMonitor = $this->rotation->getHealthMonitor();
            if (!$healthMonitor->canReceiveTraffic($gateway->id)) {
                continue;
            }
            
            if ($preferFullSupport && \PaymentOrchestrator\Gateway\CardBrands::isLimited($gateway)) {
                $restrictedFallback = $restrictedFallback ?? $gateway->id;
                continue;
            }
            
            return $gateway->id;
        }
        
        // Unknown brand and only card-restricted gateways passed: use the first of those.
        if ($restrictedFallback !== null) {
            return $restrictedFallback;
        }
        
        // Fallback: if all gateways exceeded max, pick the one with the highest max
        if (!empty($overMaxGateways)) {
            usort($overMaxGateways, fn($a, $b) => $b->maxTransactionAmount <=> $a->maxTransactionAmount);
            return $overMaxGateways[0]->id;
        }
        
        return null;
    }
    
    /**
     * Record failure in po_failed_transactions table.
     */
    private function recordFailure(
        int $orderId,
        \WC_Order $order,
        string $gatewayId,
        array $classification,
        int $retryCount
    ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'po_failed_transactions';
        
        $row = [
            'order_id' => $orderId,
            'gateway_id' => $gatewayId,
            'failure_code' => $classification['code'] ?: null,
            'failure_reason' => mb_substr($classification['reason'], 0, 500),
            'failure_category' => $classification['category'],
            'card_type' => $classification['card_type'],
            'customer_email' => $order->get_billing_email(),
            'order_total' => (float) $order->get_total(),
            'retry_count' => $retryCount,
            'customer_message' => mb_substr($classification['customer_message'], 0, 500),
            'month_year' => current_time('Y-m'),
        ];
        $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%s'];
        
        // 2.7.22: store the classifier's health verdict so HealthMonitor and every
        // processor rate use it, instead of re-deriving it from the category.
        // Written only once the column is confirmed to exist (see Activator).
        if (get_option('po_fail_health_col') === '1') {
            $row['counts_against_health'] = !empty($classification['counts_against_health']) ? 1 : 0;
            $formats[] = '%d';
        }
        
        $wpdb->insert($table, $row, $formats);
    }
    
    /**
     * Filter available gateways on the order-pay page during retry.
     * Ensures the retry gateway is available and selected as default.
     *
     * SAFETY: Wrapped in try/catch. Falls back to showing all gateways.
     */
    public function filterRetryGateways(array $wcGateways): array {
        try {
            return $this->handleRetryGatewayFilter($wcGateways);
        } catch (\Throwable $e) {
            Logger::error('Retry gateway filter error (non-fatal)', [
                'error' => $e->getMessage(),
            ]);
            return $wcGateways;
        }
    }
    
    /**
     * Internal: filter gateways on order-pay page for retries.
     * 
     * SAFETY: If the designated retry gateway is invalid/missing, falls back to 
     * any available managed gateway (excluding the one that failed). Never shows
     * an empty gateway list.
     */
    private function handleRetryGatewayFilter(array $wcGateways): array {
        // Only on order-pay page
        if (!is_wc_endpoint_url('order-pay')) {
            return $wcGateways;
        }
        
        // Get the order from the URL
        global $wp;
        $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        if (!$orderId) {
            return $wcGateways;
        }
        
        $order = wc_get_order($orderId);
        if (!$order) {
            return $wcGateways;
        }
        
        $retryGateway = $order->get_meta(self::META_RETRY_GATEWAY);
        $failedGateway = $order->get_meta(self::META_FAILED_GATEWAY);
        
        // If no retry gateway set, this isn't a PO retry - don't filter
        if (!$retryGateway) {
            return $wcGateways;
        }
        
        // Collect always-on gateway IDs
        $alwaysOn = $this->gateways->getAlwaysOn();
        $alwaysOnIds = array_map(fn($g) => $g->id, $alwaysOn);
        
        $result = [];
        
        // Check if the designated retry gateway is available
        if (isset($wcGateways[$retryGateway])) {
            $result[$retryGateway] = $wcGateways[$retryGateway];
        } else {
            // Retry gateway is invalid/disabled - fall back to any managed gateway
            // that isn't the one that failed
            Logger::warning('Retry gateway unavailable on pay page, finding fallback', [
                'order_id' => $orderId,
                'retry_gateway' => $retryGateway,
                'failed_gateway' => $failedGateway,
            ]);
            
            $managed = $this->gateways->getManaged();
            $managedIds = array_map(fn($g) => $g->id, $managed);
            
            foreach ($managedIds as $gid) {
                if ($gid !== $failedGateway && isset($wcGateways[$gid])) {
                    $result[$gid] = $wcGateways[$gid];
                    break; // Take the first available alternative
                }
            }
            
            // If still empty (all managed gateways unavailable), return all WC gateways
            if (empty($result)) {
                Logger::warning('No managed gateway available for retry, showing all gateways', [
                    'order_id' => $orderId,
                ]);
                return $wcGateways;
            }
        }
        
        // Add always-on gateways (PayPal etc.)
        foreach ($alwaysOnIds as $gid) {
            if (isset($wcGateways[$gid]) && !isset($result[$gid])) {
                $result[$gid] = $wcGateways[$gid];
            }
        }
        
        return $result;
    }
    
    /**
     * Record a successful retry (order went from failed to processing/completed).
     *
     * SAFETY: Wrapped in try/catch.
     */
    public function onOrderRecovered(int $orderId, \WC_Order $order): void {
        try {
            $this->processRecovery($orderId, $order);
        } catch (\Throwable $e) {
            Logger::error('Recovery recording error (non-fatal)', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Internal: mark the failed transaction as recovered.
     */
    private function processRecovery(int $orderId, \WC_Order $order): void {
        $retryEligible = $order->get_meta(self::META_RETRY_ELIGIBLE);
        
        // 2.7.28: a recovery email counts only when the customer clicked it (the
        // click stamps _po_recovery_source = 'email') AND paid this same order on
        // its pay page. That also credits card-problem (non-retryable) orders.
        $emailClicked = $order->get_meta('_po_recovery_source') === 'email';
        $viaPayPage   = self::isPayPagePaymentRequest() || (string) $order->get_meta('_po_paid_via_pay_page') !== '';
        $emailSuccess = $emailClicked && $viaPayPage;
        
        if ($retryEligible !== 'yes' && !$emailSuccess) {
            return;
        }
        
        $retryGateway = $order->get_meta(self::META_RETRY_GATEWAY);
        $newGateway = $order->get_payment_method();
        
        // Recovery source: 'email' only for the email-success case above;
        // otherwise the orchestrator's retry (default 'auto_redirect').
        $recoverySource = $emailSuccess ? 'email' : 'auto_redirect';
        
        global $wpdb;
        $table = $wpdb->prefix . 'po_failed_transactions';
        
        // Update the most recent unrecovered failure record for this order.
        // AND recovered = 0 is the idempotency guard: if the order transitions
        // failed -> processing -> completed, both status hooks call this method but
        // only the first UPDATE touches a row (rows_affected = 1). The second call
        // finds no unrecovered row (rows_affected = 0) and exits early, preventing
        // duplicate log entries, duplicate notification emails, and double-counting
        // in recovery stats.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET 
                recovered = 1,
                retry_gateway = %s,
                recovered_at = NOW(),
                recovery_source = %s
            WHERE order_id = %d 
            AND recovered = 0
            ORDER BY id DESC 
            LIMIT 1",
            $newGateway ?: $retryGateway,
            $recoverySource,
            $orderId
        ));
        
        // Only proceed if the UPDATE actually changed a row.
        // If rows_affected = 0 the order was already marked recovered (e.g. by a
        // prior status transition), so skip logging, the action hook, and the email.
        if ($wpdb->rows_affected === 0) {
            return;
        }
        
        $order->add_order_note(sprintf(
            'Payment Orchestrator: Payment recovered! Originally failed on %s, succeeded on %s. Source: %s.',
            $order->get_meta(self::META_FAILED_GATEWAY),
            $newGateway,
            $recoverySource
        ));
        
        Logger::info('Revenue recovered', [
            'order_id' => $orderId,
            'original_gateway' => $order->get_meta(self::META_FAILED_GATEWAY),
            'recovery_gateway' => $newGateway,
            'recovery_source' => $recoverySource,
            'amount' => $order->get_total(),
        ]);
        
        /**
         * Fires after a failed order has been successfully recovered (paid).
         *
         * External plugins should listen to this to cancel pending email sequences,
         * update their own tracking, etc.
         *
         * @param int       $orderId       The WooCommerce order ID.
         * @param array     $recoveryData  Recovery details.
         * @param \WC_Order $order         The WooCommerce order object.
         */
        do_action('po_order_recovered', $orderId, [
            'failed_gateway'   => $order->get_meta(self::META_FAILED_GATEWAY),
            'recovery_gateway' => $newGateway,
            'recovery_source'  => $recoverySource,
            'order_total'      => (float) $order->get_total(),
            'customer_email'   => $order->get_billing_email(),
            'failure_code'     => $order->get_meta(self::META_FAILURE_CODE),
        ], $order);
        
        // Send recovery notification email
        $this->sendRecoveryNotification($order, $newGateway);
    }
    
    /**
     * Send email notification when revenue is recovered.
     */
    private function sendRecoveryNotification(\WC_Order $order, string $recoveryGateway): void {
        try {
            $alertEmail = get_option('po_failure_alert_email', get_option('admin_email'));
            if (empty($alertEmail)) {
                return;
            }
            
            $orderId = $order->get_id();
            $amount = wc_price($order->get_total());
            $failedGateway = $order->get_meta(self::META_FAILED_GATEWAY);
            $failureCode = '';
            
            // Look up the failure code from the record
            global $wpdb;
            $table = $wpdb->prefix . 'po_failed_transactions';
            $failureRow = $wpdb->get_row($wpdb->prepare(
                "SELECT failure_code, failure_reason FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
                $orderId
            ));
            if ($failureRow) {
                $failureCode = $failureRow->failure_code . ' - ' . $failureRow->failure_reason;
            }
            
            // Use the already-injected GatewayRepository - no container lookup needed.
            $gateways = $this->gateways;
            $failedName = $failedGateway;
            $recoveryName = $recoveryGateway;
            foreach ($gateways->getAll() as $gw) {
                if ($gw->id === $failedGateway) {
                    $failedName = $gw->displayName ?: $failedGateway;
                }
                if ($gw->id === $recoveryGateway) {
                    $recoveryName = $gw->displayName ?: $recoveryGateway;
                }
            }
            
            $siteName = get_bloginfo('name');
            $orderUrl = admin_url('post.php?post=' . $orderId . '&action=edit');
            
            $subject = sprintf('' . "\u{1F4B0}" . ' Revenue Recovered ' . "\u{2014}" . ' Order #%d (%s)', $orderId, strip_tags($amount));
            
            $body = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:600px;margin:0 auto;">';
            $body .= '<div style="background:#052e16;border:1px solid #22c55e;border-radius:12px;padding:24px;margin-bottom:20px;">';
            $body .= '<h2 style="margin:0 0 8px;color:#22c55e;font-size:20px;">' . "\u{2705}" . ' Payment Recovered!</h2>';
            $body .= '<p style="margin:0;color:#86efac;font-size:14px;">A failed payment was successfully retried on a different gateway.</p>';
            $body .= '</div>';
            
            $body .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
            $body .= '<tr><td style="padding:10px 0;color:#6b7280;border-bottom:1px solid #e5e7eb;width:140px;">Order</td>';
            $body .= '<td style="padding:10px 0;color:#111827;border-bottom:1px solid #e5e7eb;font-weight:600;">';
            $body .= '<a href="' . esc_url($orderUrl) . '" style="color:#1e293b;">#' . $orderId . '</a></td></tr>';
            
            $body .= '<tr><td style="padding:10px 0;color:#6b7280;border-bottom:1px solid #e5e7eb;">Amount</td>';
            $body .= '<td style="padding:10px 0;color:#22c55e;border-bottom:1px solid #e5e7eb;font-weight:700;font-size:18px;">' . $amount . '</td></tr>';
            
            $body .= '<tr><td style="padding:10px 0;color:#6b7280;border-bottom:1px solid #e5e7eb;">Failed On</td>';
            $body .= '<td style="padding:10px 0;color:#ef4444;border-bottom:1px solid #e5e7eb;">' . esc_html($failedName) . '</td></tr>';
            
            if ($failureCode) {
                $body .= '<tr><td style="padding:10px 0;color:#6b7280;border-bottom:1px solid #e5e7eb;">Failure Code</td>';
                $body .= '<td style="padding:10px 0;color:#111827;border-bottom:1px solid #e5e7eb;">' . esc_html($failureCode) . '</td></tr>';
            }
            
            $body .= '<tr><td style="padding:10px 0;color:#6b7280;border-bottom:1px solid #e5e7eb;">Recovered By</td>';
            $body .= '<td style="padding:10px 0;color:#22c55e;border-bottom:1px solid #e5e7eb;font-weight:600;">' . esc_html($recoveryName) . '</td></tr>';
            
            $body .= '<tr><td style="padding:10px 0;color:#6b7280;">Customer</td>';
            $body .= '<td style="padding:10px 0;color:#111827;">' . esc_html($order->get_billing_email()) . '</td></tr>';
            $body .= '</table>';
            
            $body .= '<p style="margin:20px 0 0;font-size:12px;color:#9ca3af;">This order would have been lost without Payment Orchestrator. ' . "\u{2014}" . ' ' . esc_html($siteName) . '</p>';
            $body .= '</div>';
            
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            wp_mail($alertEmail, $subject, $body, $headers);
            
            Logger::debug('Recovery notification sent', ['order_id' => $orderId, 'email' => $alertEmail]);
        } catch (\Throwable $e) {
            Logger::warning('Failed to send recovery notification', [
                'order_id' => $order->get_id(),
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Get recovery stats for reporting.
     */
    public static function getRecoveryStats(?string $monthYear = null): array {
        $default = [
            'total_failures' => 0,
            'retryable_gateway' => 0,
            'retryable_card_type' => 0,
            'non_retryable' => 0,
            'total_retryable' => 0,
            'retryable_count' => 0,
            'recovered_count' => 0,
            'recovered_revenue' => 0.0,
            'lost_retryable_revenue' => 0.0,
            'recovery_rate' => 0.0,
        ];
        
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'po_failed_transactions';
            
            // Check table exists before querying
            $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
            if (!$tableExists) {
                return $default;
            }
            
            $monthYear = $monthYear ?: current_time('Y-m');
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(DISTINCT order_id) as total_failures,
                COUNT(DISTINCT CASE WHEN failure_category = 'retryable_gateway' THEN order_id ELSE NULL END) as retryable_gateway,
                COUNT(DISTINCT CASE WHEN failure_category = 'retryable_card_type' THEN order_id ELSE NULL END) as retryable_card_type,
                COUNT(DISTINCT CASE WHEN failure_category = 'non_retryable' THEN order_id ELSE NULL END) as non_retryable,
                COUNT(DISTINCT CASE WHEN recovered = 1 THEN order_id ELSE NULL END) as recovered_count,
                COALESCE((SELECT SUM(d.order_total) FROM (SELECT DISTINCT order_id, order_total FROM {$table} WHERE month_year = %s AND recovered = 1) d), 0) as recovered_revenue,
                COALESCE((SELECT SUM(d.order_total) FROM (SELECT DISTINCT order_id, order_total FROM {$table} WHERE month_year = %s AND failure_category IN ('retryable_gateway','retryable_card_type') AND recovered = 0) d), 0) as lost_retryable_revenue
            FROM {$table}
            WHERE month_year = %s",
            $monthYear,
            $monthYear,
            $monthYear
        ), ARRAY_A);
        
        $retryable = ((int)($stats['retryable_gateway'] ?? 0)) + ((int)($stats['retryable_card_type'] ?? 0));
        $recoveredCount = (int)($stats['recovered_count'] ?? 0);
        
        // 2.7.25: what actually happened to the month's failed orders.
        //  - paid_after_failure: failed orders later paid by ANY means (POP marks only
        //    retry-eligible recoveries, e.g. Sept: 263 marked vs 987 paid).
        //  - unrecovered_net_revenue: every category, excluding orders paid later and
        //    customers who placed a paid order within 7 days.
        $paidAfter = 0;
        $unrecoveredNet = 0.0;
        try {
            $ops = $wpdb->prefix . 'wc_order_operational_data';
            $ord = $wpdb->prefix . 'wc_orders';
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    COUNT(DISTINCT IF(op.date_paid_gmt IS NOT NULL, f.order_id, NULL)) AS paid_after,
                    COALESCE(SUM(IF(op.date_paid_gmt IS NULL AND NOT EXISTS (
                        SELECT 1 FROM {$ord} o2 JOIN {$ops} op2 ON op2.order_id = o2.id
                        WHERE o2.billing_email = f.customer_email AND o2.type = 'shop_order' AND o2.id <> f.order_id
                          AND op2.date_paid_gmt > f.first_fail AND op2.date_paid_gmt <= f.first_fail + INTERVAL 7 DAY
                    ), f.order_total, 0)), 0) AS unrecovered_net
                 FROM (SELECT order_id, MAX(order_total) AS order_total, MAX(customer_email) AS customer_email, MIN(created_at) AS first_fail
                       FROM {$table} WHERE month_year = %s GROUP BY order_id) f
                 LEFT JOIN {$ops} op ON op.order_id = f.order_id",
                $monthYear
            ));
            $paidAfter = (int) ($row->paid_after ?? 0);
            $unrecoveredNet = (float) ($row->unrecovered_net ?? 0);
        } catch (\Throwable $e) {
            Logger::debug('Paid-after-failure stats skipped', ['error' => $e->getMessage()]);
        }
        
        return [
            'total_failures' => (int)($stats['total_failures'] ?? 0),
            'retryable_gateway' => (int)($stats['retryable_gateway'] ?? 0),
            'retryable_card_type' => (int)($stats['retryable_card_type'] ?? 0),
            'non_retryable' => (int)($stats['non_retryable'] ?? 0),
            'total_retryable' => $retryable,
            'recovered_count' => $recoveredCount,
            'recovered_revenue' => (float)($stats['recovered_revenue'] ?? 0),
            'lost_retryable_revenue' => (float)($stats['lost_retryable_revenue'] ?? 0),
            'recovery_rate' => $retryable > 0 ? round(($recoveredCount / $retryable) * 100, 1) : 0,
            'paid_after_failure' => $paidAfter,
            'paid_after_failure_rate' => ((int)($stats['total_failures'] ?? 0)) > 0 ? round($paidAfter / (int) $stats['total_failures'] * 100, 1) : 0,
            'unrecovered_net_revenue' => $unrecoveredNet,
        ];
        } catch (\Throwable $e) {
            Logger::warning('Failed to get recovery stats', ['error' => $e->getMessage()]);
            return $default;
        }
    }
    
    /**
     * Get top failure codes for reporting.
     */
    public static function getTopFailureCodes(?string $monthYear = null, int $limit = 10): array {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'po_failed_transactions';
            
            $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
            if (!$tableExists) {
                return [];
            }
            
            $monthYear = $monthYear ?: current_time('Y-m');
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT 
                failure_code,
                failure_reason,
                failure_category,
                COUNT(*) as occurrences,
                SUM(order_total) as total_value,
                SUM(CASE WHEN recovered = 1 THEN 1 ELSE 0 END) as recovered
            FROM {$table}
            WHERE month_year = %s
            AND failure_code IS NOT NULL
            AND failure_code != ''
            GROUP BY failure_code, failure_reason, failure_category
            ORDER BY occurrences DESC
            LIMIT %d",
            $monthYear,
            $limit
        ), ARRAY_A) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * Get recovery by gateway pair for reporting.
     */
    public static function getTopFailureCodesToday(int $limit = 15): array {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'po_failed_transactions';
            
            $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
            if (!$tableExists) {
                return [];
            }
            
            $todayUtc = \PaymentOrchestrator\Core\SiteTime::dayUtcRange(); // 2.7.25: failures stored in UTC
            return $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    failure_code,
                    failure_reason,
                    failure_category,
                    COUNT(*) as occurrences,
                    COALESCE(SUM(order_total), 0) as total_value,
                    SUM(CASE WHEN recovered = 1 THEN 1 ELSE 0 END) as recovered
                FROM {$table}
                WHERE created_at >= %s AND created_at < %s
                AND failure_code IS NOT NULL
                AND failure_code != ''
                GROUP BY failure_code, failure_reason, failure_category
                ORDER BY occurrences DESC
                LIMIT %d",
                $todayUtc[0],
                $todayUtc[1],
                $limit
            ), ARRAY_A) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * Get recovery by gateway pair for reporting.
     */
    public static function getRecoveryByGatewayPair(?string $monthYear = null): array {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'po_failed_transactions';
            
            $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
            if (!$tableExists) {
                return [];
            }
            
            $monthYear = $monthYear ?: current_time('Y-m');
            
            return $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    gateway_id as failed_gateway,
                    retry_gateway,
                    COUNT(*) as recovered_count,
                    SUM(order_total) as recovered_revenue
                FROM {$table}
                WHERE month_year = %s
                AND recovered = 1
                AND retry_gateway IS NOT NULL
                GROUP BY gateway_id, retry_gateway
                ORDER BY recovered_revenue DESC",
                $monthYear
            ), ARRAY_A) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * Generate a secure pay URL for a failed order.
     * 
     * For use by external plugins (e.g., recovery email plugin) that need
     * to send customers back to complete payment.
     *
     * @param int    $orderId The WooCommerce order ID.
     * @param string $source  The recovery source identifier (e.g., 'email', 'sms').
     * @return string|null The pay URL, or null if order is not eligible.
     */
    public static function getRecoveryPayUrl(int $orderId, string $source = 'email'): ?string {
        $order = wc_get_order($orderId);
        if (!$order) {
            return null;
        }
        
        // Only generate for orders that need payment
        if (!$order->needs_payment()) {
            return null;
        }
        
        // Stamp the recovery source so processRecovery() can attribute it
        $order->update_meta_data('_po_recovery_source', sanitize_key($source));
        $order->save();
        
        return $order->get_checkout_payment_url();
    }
    
    /**
     * Check if an order is still eligible for recovery outreach.
     *
     * Returns false if the order has been paid, cancelled, or already recovered.
     * External plugins should check this before sending emails/SMS.
     *
     * @param int $orderId The WooCommerce order ID.
     * @return bool True if the order still needs recovery.
     */
    public static function isRecoveryActive(int $orderId): bool {
        $order = wc_get_order($orderId);
        if (!$order) {
            return false;
        }
        
        // Must still need payment
        if (!$order->needs_payment()) {
            return false;
        }
        
        // Must be retry-eligible via POP
        if ($order->get_meta('_po_retry_eligible') !== 'yes') {
            return false;
        }
        
        // Must not already be recovered in our table
        global $wpdb;
        $recovered = $wpdb->get_var($wpdb->prepare(
            "SELECT recovered FROM {$wpdb->prefix}po_failed_transactions 
            WHERE order_id = %d ORDER BY id DESC LIMIT 1",
            $orderId
        ));
        
        return (int) $recovered === 0;
    }
    
    /**
     * Get failure details for an order, for use by external plugins.
     *
     * @param int $orderId The WooCommerce order ID.
     * @return array|null Failure details, or null if not found.
     */
    public static function getFailureDetails(int $orderId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}po_failed_transactions 
            WHERE order_id = %d ORDER BY id DESC LIMIT 1",
            $orderId
        ), ARRAY_A);
        
        return $row ?: null;
    }
    
    /**
     * Get recovery stats for today only.
     */
    public static function getRecoveryStatsToday(): array {
        $default = [
            'total_failures' => 0,
            'total_retryable' => 0,
            'non_retryable' => 0,
            'recovered_count' => 0,
            'recovered_revenue' => 0.0,
            'retryable_revenue' => 0.0,
            'non_retryable_revenue' => 0.0,
            'recovery_rate' => 0.0,
        ];
        
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'po_failed_transactions';
            
            $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
            if (!$tableExists) {
                return $default;
            }
            
            $today = current_time('Y-m-d');
            $todayUtc = \PaymentOrchestrator\Core\SiteTime::dayUtcRange($today); // 2.7.25: failures stored in UTC
            
            $stats = $wpdb->get_row($wpdb->prepare(
                "SELECT 
                    COUNT(DISTINCT order_id) as total_failures,
                    COUNT(DISTINCT CASE WHEN failure_category IN ('retryable_gateway','retryable_card_type') THEN order_id ELSE NULL END) as total_retryable,
                    COUNT(DISTINCT CASE WHEN failure_category = 'non_retryable' THEN order_id ELSE NULL END) as non_retryable,
                    COUNT(DISTINCT CASE WHEN recovered = 1 THEN order_id ELSE NULL END) as recovered_count,
                    COALESCE((SELECT SUM(d.order_total) FROM (SELECT DISTINCT order_id, order_total FROM {$table} WHERE created_at >= %s AND created_at < %s AND recovered = 1) d), 0) as recovered_revenue,
                    COALESCE((SELECT SUM(d.order_total) FROM (SELECT DISTINCT order_id, order_total FROM {$table} WHERE created_at >= %s AND created_at < %s AND failure_category IN ('retryable_gateway','retryable_card_type')) d), 0) as retryable_revenue,
                    COALESCE((SELECT SUM(d.order_total) FROM (SELECT DISTINCT order_id, order_total FROM {$table} WHERE created_at >= %s AND created_at < %s AND failure_category = 'non_retryable') d), 0) as non_retryable_revenue
                FROM {$table}
                WHERE created_at >= %s AND created_at < %s",
                ...array_merge($todayUtc, $todayUtc, $todayUtc, $todayUtc)
            ), ARRAY_A);
            
            $retryable = (int) ($stats['total_retryable'] ?? 0);
            $recoveredCount = (int) ($stats['recovered_count'] ?? 0);
            
            return [
                'total_failures' => (int) ($stats['total_failures'] ?? 0),
                'total_retryable' => $retryable,
                'non_retryable' => (int) ($stats['non_retryable'] ?? 0),
                'recovered_count' => $recoveredCount,
                'recovered_revenue' => (float) ($stats['recovered_revenue'] ?? 0),
                'retryable_revenue' => (float) ($stats['retryable_revenue'] ?? 0),
                'non_retryable_revenue' => (float) ($stats['non_retryable_revenue'] ?? 0),
                'recovery_rate' => $retryable > 0 ? round(($recoveredCount / $retryable) * 100, 1) : 0,
            ];
        } catch (\Throwable $e) {
            return $default;
        }
    }
    
    /**
     * Get recovery by gateway pair for today only.
     */
    public static function getRecoveryByGatewayPairToday(): array {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'po_failed_transactions';
            
            $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
            if (!$tableExists) {
                return [];
            }
            
            $today = current_time('Y-m-d');
            
            return $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    gateway_id as failed_gateway,
                    retry_gateway,
                    COUNT(*) as recovered_count,
                    SUM(order_total) as recovered_revenue
                FROM {$table}
                WHERE created_at >= %s AND created_at < %s
                AND recovered = 1
                AND retry_gateway IS NOT NULL
                GROUP BY gateway_id, retry_gateway
                ORDER BY recovered_revenue DESC",
                ...\PaymentOrchestrator\Core\SiteTime::dayUtcRange($today)
            ), ARRAY_A) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * Display the stored retry message on the pay-for-order page.
     * Hooked to 'before_woocommerce_pay'.
     */
    public function displayRetryMessageOnPayPage(): void {
        try {
            global $wp;
            $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
            if (!$orderId) {
                return;
            }
            
            $order = wc_get_order($orderId);
            if (!$order) {
                return;
            }
            
            // 2.7.22: WooCommerce fires before_woocommerce_pay BEFORE it validates the
            // order key, so without this check anyone could read (and consume) the
            // decline message of any order number. Require the order's own key.
            $key = isset($_GET['key']) && is_string($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
            if ($key === '' || !hash_equals($order->get_order_key(), $key)) {
                return;
            }
            
            $message = $order->get_meta('_po_retry_customer_message');
            if (!$message && $order->get_meta('_po_recovery_source') === 'email' && $order->has_status('failed')) {
                // 2.7.28: customer came back from a recovery email for a card problem -
                // show why the first payment failed (same text as checkout and email).
                global $wpdb;
                $last = $wpdb->get_row($wpdb->prepare(
                    "SELECT customer_message, failure_code, failure_reason FROM {$wpdb->prefix}po_failed_transactions WHERE order_id = %d ORDER BY id DESC LIMIT 1",
                    $orderId
                ));
                $message = $last ? (string) $last->customer_message : '';
                $fix = $last ? FailureClassifier::fixField(['code' => (string) $last->failure_code, 'reason' => (string) $last->failure_reason]) : null;
                // 2.7.31: if the order still has an unresolved address failure, the page
                // shows the billing address fields - state that reason, not a later,
                // unrelated decline, so the message and the fields agree.
                if ($fix !== 'address' && \PaymentOrchestrator\Frontend\PayPageAddress::needsAddressFix($order)) {
                    $message = FailureClassifier::customerMessage(['code' => '300', 'reason' => 'AVS REJECTED']);
                    $fix = 'address';
                }
                if ($message !== '') {
                    // 2.7.29: same prominent box as the retry message (was plain text, easy to miss)
                    wc_print_notice(
                        '<strong style="display:block; font-size:15px; color:#c0392b; margin-bottom:4px;">'
                        . esc_html($message) . '</strong>'
                        . '<span style="display:block; color:#555; font-size:13px;">Please enter your payment details below to try again.</span>'
                        . FailureClassifier::fixMarker($fix), // 2.7.30
                        'error'
                    );
                }
                return;
            }
            if (!$message) {
                return;
            }
            
            // Retry funnel: customer reached the pay page
            $order->update_meta_data('_po_retry_page_viewed', current_time('mysql'));
            $order->save();
            
            Logger::info('Retry pay page viewed by customer', [
                'order_id' => $orderId,
                'retry_gateway' => $order->get_meta(self::META_RETRY_GATEWAY),
            ]);
            
            // Show the message as a styled notice
            wc_print_notice(
                '<strong style="display:block; font-size:15px; color:#c0392b; margin-bottom:4px;">'
                . esc_html($message) . '</strong>'
                . '<span style="display:block; color:#555; font-size:13px;">Please enter your payment details below to try again.</span>',
                'error'
            );
            
            // Clear the message so it doesn't show on subsequent visits
            $order->delete_meta_data('_po_retry_customer_message');
            $order->save();
            
        } catch (\Throwable $e) {
            Logger::debug('Pay page message display failed', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Track when customer attempts payment on the retry pay page.
     * 
     * Hooked to 'woocommerce_before_pay_action'.
     */
    public function trackRetryPaymentAttempt($orderOrId): void {
        try {
            // WooCommerce's woocommerce_before_pay_action passes the WC_Order object, not an int
            if ($orderOrId instanceof \WC_Order) {
                $order = $orderOrId;
                $orderId = $order->get_id();
            } else {
                $orderId = (int) $orderOrId;
                $order = wc_get_order($orderId);
            }
            
            if (!$order) {
                return;
            }
            
            // 2.7.31: a validation error (e.g. missing billing address field) is
            // already pending - WooCommerce will skip payment, so this is not an
            // attempt: don't count it and don't take the lock.
            if (function_exists('wc_notice_count') && wc_notice_count('error') > 0) {
                return;
            }
            
            // -- SPENT-TOKEN GUARD -- (2.7.33 build 2)
            // A card token can be charged once. On payment pages a second submission
            // sometimes arrives a second after a decline, reusing the same token -
            // after the processing lock below was (correctly) released for the
            // customer's next try. NMI then answers "Payment Token does not exist",
            // a false processor failure counted against the gateway's health.
            // Block a token already used for this order: no charge, no failure record.
            // A real new attempt carries a new token, so genuine retries are unaffected.
            $token = isset($_POST['nmi_js_token']) && is_string($_POST['nmi_js_token']) ? (string) wp_unslash($_POST['nmi_js_token']) : '';
            if ($token !== '') {
                $tokenKey = 'po_used_tok_' . md5($orderId . '|' . $token);
                if (get_transient($tokenKey)) {
                    Logger::info('Resubmitted card token blocked (already charged once)', ['order_id' => $orderId]);
                    wc_add_notice('Please re-enter your card details to try again.', 'error');
                    return;
                }
            }
            
            // -- PROCESSING GUARD -- (2.7.29: applies to EVERY pay-page submission,
            // not only retry orders - a double submission on a recovery-email visit
            // reused a spent card token and recorded a false processor failure.)
            // Prevent duplicate process_payment calls when two PHP sessions
            // hit the order-pay page simultaneously (e.g., Direct Post return
            // URL + WooCommerce form submission racing each other).
            //
            // Uses a transient as a short-lived lock. The FIRST request sets
            // the lock and proceeds. Any request arriving within 30 seconds
            // for the same order sees the lock and triggers a WooCommerce
            // error notice, stopping process_payment from firing again.
            //
            // 30-second TTL is intentionally short: long enough to cover the
            // Direct Post race window (~1-2 seconds), short enough that a
            // genuine customer retry after page reload is never blocked.
            $lockKey = 'po_retry_lock_' . $orderId;
            $existingLock = get_transient($lockKey);
            
            if ($existingLock) {
                Logger::warning('Duplicate pay-page payment blocked by processing lock', [
                    'order_id' => $orderId,
                    'existing_lock_session' => $existingLock,
                    'current_request' => substr(session_id() ?: uniqid(), -8),
                ]);
                
                // 2.7.22: add an error notice instead of throwing. WooCommerce fires
                // woocommerce_before_pay_action OUTSIDE its try/catch
                // (WC_Form_Handler::pay_action), so an exception here was a fatal
                // error page. With an error notice present, WooCommerce skips
                // process_payment() (it checks wc_notice_count('error') first).
                wc_add_notice('Your payment is already being processed. Please wait a moment.', 'error');
                return;
            }
            
            // Set the lock - 30 second TTL
            $sessionMarker = substr(session_id() ?: uniqid(), -8);
            set_transient($lockKey, $sessionMarker, 30);
            $this->retryLockMarker = $sessionMarker;
            // This token is about to be charged: remember it for 2 minutes (spent-token guard).
            if ($token !== '') {
                set_transient('po_used_tok_' . md5($orderId . '|' . $token), 1, 120);
            }
            
            // 2.7.28: every pay-page payment attempt (retry or recovery-email visit).
            // 2.7.29: fired only after the lock, so blocked duplicates aren't counted.
            do_action('po_pay_page_attempt', $order);
            
            // Retry bookkeeping below is for retry orders only
            if ($order->get_meta(self::META_RETRY_ELIGIBLE) !== 'yes') {
                return;
            }
            
            $attemptCount = (int) $order->get_meta('_po_retry_payment_attempts');
            $attemptCount++;
            
            $order->update_meta_data('_po_retry_payment_attempted', current_time('mysql'));
            $order->update_meta_data('_po_retry_payment_attempts', $attemptCount);
            $order->update_meta_data('_po_retry_payment_gateway', $order->get_payment_method());
            $order->save();
            
            Logger::info('Retry payment attempted on pay page', [
                'order_id' => $orderId,
                'attempt' => $attemptCount,
                'gateway' => $order->get_payment_method(),
                'lock_session' => $sessionMarker,
            ]);
        } catch (\Throwable $e) {
            Logger::debug('Retry payment tracking failed', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Release the retry processing lock once a pay-page attempt has finished
     * without success, so a customer who re-enters a card straight after a
     * decline is not told their payment is "already being processed".
     * Hooked to 'woocommerce_after_pay_action' (fires only when WooCommerce did
     * not redirect away after a successful payment).
     *
     * @since 2.7.22
     */
    public function releaseRetryPaymentLock($orderOrId): void {
        try {
            // Only release a lock this request created. A duplicate request that was
            // blocked must never free the lock of the attempt that is still running.
            if ($this->retryLockMarker === null) {
                return;
            }
            $orderId = $orderOrId instanceof \WC_Order ? $orderOrId->get_id() : (int) $orderOrId;
            if ($orderId > 0 && get_transient('po_retry_lock_' . $orderId) === $this->retryLockMarker) {
                delete_transient('po_retry_lock_' . $orderId);
            }
            $this->retryLockMarker = null;
        } catch (\Throwable $e) {
            Logger::debug('Retry lock release failed', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Inject JS on checkout page that watches for the retry redirect data attribute
     * in the WooCommerce error notice HTML returned by the AJAX response.
     * 
     * This script is always present on checkout. When a payment fails and the error
     * notices HTML contains our #po-retry-redirect element with a data-url, the JS
     * reads it and auto-redirects. No session dependency.
     * 
     * Hooked to 'woocommerce_after_checkout_form'.
     */
    public function injectRetryRedirectScript(): void {
        ?>
        <script>
        (function() {
            // 2.7.22: only follow same-origin URLs (the pay page is always on this site).
            function poSafeUrl(u) {
                if (!u) return '';
                try {
                    var parsed = new URL(u, window.location.href);
                    return parsed.origin === window.location.origin ? parsed.href : '';
                } catch (e) { return ''; }
            }
            
            // Watch for WooCommerce checkout errors that contain our redirect marker
            jQuery(document.body).on('checkout_error', function() {
                var el = document.getElementById('po-retry-redirect');
                if (!el) return;
                var url = poSafeUrl(el.getAttribute('data-url'));
                if (!url) return;
                
                // Brief delay so customer sees the "Redirecting..." message
                setTimeout(function() {
                    window.location.href = url;
                }, 1500);
            });
            
            // Fallback: MutationObserver in case checkout_error doesn't fire (FunnelKit)
            var observer = new MutationObserver(function(mutations) {
                var el = document.getElementById('po-retry-redirect');
                if (!el) return;
                var url = poSafeUrl(el.getAttribute('data-url'));
                if (!url) return;
                observer.disconnect();
                setTimeout(function() {
                    window.location.href = url;
                }, 1500);
            });
            
            // Observe the checkout form area for error notices being injected
            var target = document.querySelector('.woocommerce-notices-wrapper, form.checkout, .wfacp_main_form, .woocommerce');
            if (target) {
                observer.observe(target, { childList: true, subtree: true });
            }
        })();
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * Pay-page tracking (2.7.28)
     * ------------------------------------------------------------------ */

    /** Is this request the payment submission on a pay-for-order page? */
    public static function isPayPagePaymentRequest(): bool {
        return !empty($_POST['woocommerce_pay'])
            && function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay');
    }

    /**
     * Customer opened a pay-for-order page (valid order key, order needs payment).
     * Hooked to template_redirect. Fires po_pay_page_viewed for funnel tracking.
     */
    public function onPayPageViewed(): void {
        try {
            if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay') || !empty($_POST)) {
                return;
            }
            global $wp;
            $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
            $order   = $orderId ? wc_get_order($orderId) : null;
            if (!$order || !$order->needs_payment()) {
                return;
            }
            $key = isset($_GET['key']) && is_string($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
            if ($key === '' || !hash_equals($order->get_order_key(), $key)) {
                return;
            }
            do_action('po_pay_page_viewed', $order);
        } catch (\Throwable $e) {
            Logger::debug('Pay page view tracking skipped', ['error' => $e->getMessage()]);
        }
    }

    /**
     * A payment attempt on a pay-for-order page did not succeed.
     *
     * When the order was already "failed", WooCommerce fires no status change,
     * so the decline was never recorded (no failure row, no health count, no
     * funnel event) - about 118 orders a month. Hooked to
     * woocommerce_after_pay_action; records the decline once, using the same
     * classification as a normal failure. Skipped when the status hook already
     * recorded it in this request.
     *
     * @param \WC_Order|int $orderOrId
     */
    public function onPayPageAttemptFailed($orderOrId): void {
        try {
            $order = $orderOrId instanceof \WC_Order ? $orderOrId : wc_get_order((int) $orderOrId);
            if (!$order || !$order->needs_payment()) {
                return; // paid (or not payable) - nothing to record
            }
            $orderId = $order->get_id();
            if (isset($this->failedThisRequest[$orderId])) {
                return; // already recorded via woocommerce_order_status_failed
            }
            $note = $this->latestFailureNoteThisRequest($orderId);
            if ($note === null) {
                return; // e.g. validation error before any charge attempt
            }
            $this->failedThisRequest[$orderId] = true;

            $classification = FailureClassifier::classify($note);
            $gatewayId      = (string) $order->get_payment_method();
            $retryCount     = (int) $order->get_meta(self::META_RETRY_COUNT);
            $this->recordFailure($orderId, $order, $gatewayId, $classification, $retryCount);

            Logger::info('Pay page decline recorded', [
                'order_id' => $orderId,
                'gateway'  => $gatewayId,
                'category' => $classification['category'],
                'code'     => $classification['code'],
            ]);
            do_action('po_pay_page_declined', $order, $classification);
        } catch (\Throwable $e) {
            Logger::error('Pay page decline recording failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    /** Most recent processor failure note added during this request, or null. */
    private function latestFailureNoteThisRequest(int $orderId): ?string {
        $since = (int) ($_SERVER['REQUEST_TIME'] ?? time()) - 2;
        foreach (wc_get_order_notes(['order_id' => $orderId, 'limit' => 5]) as $note) {
            $created = $note->date_created instanceof \WC_DateTime ? $note->date_created->getTimestamp() : 0;
            if ($created < $since) {
                break; // notes are newest first
            }
            if (preg_match('/^(NMI|G-Payments) failure reason:/i', (string) $note->content)) {
                return (string) $note->content;
            }
        }
        return null;
    }
}
