<?php
/**
 * FailureClassifier - Categorizes payment failure codes by gateway
 *
 * Supports multiple gateways:
 *   - NMI (numeric codes via classify()) 
 *   - G-Payments (string decline codes via classifyGP())
 *
 * Pure function: takes a raw order note string, returns structured failure data.
 * No database access, no side effects, no dependencies.
 *
 * Used by:
 *   - FailureHandler: to decide retry vs show error
 *   - HealthMonitor: to exclude customer-caused failures from health scoring
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Recovery;

final class FailureClassifier {
    
    /**
     * Failure categories
     */
    public const CATEGORY_RETRYABLE_GATEWAY = 'retryable_gateway';
    public const CATEGORY_RETRYABLE_CARD_TYPE = 'retryable_card_type';
    public const CATEGORY_NON_RETRYABLE = 'non_retryable';
    public const CATEGORY_OTHER = 'other';
    public const CATEGORY_UNKNOWN = 'unknown';
    
    /**
     * NMI codes that indicate a GATEWAY problem (customer's card is fine).
     * These should be retried on a different gateway.
     * These are the ONLY failures that should count against gateway health.
     */
    private const RETRYABLE_GATEWAY_CODES = [
        '400', // General error
        '410', // Invalid merchant ID
        '420', // Response Timeout
    ];
    
    /**
     * NMI codes with specific sub-reasons that are retryable.
     * Code => array of reason substrings that make it retryable.
     */
    private const RETRYABLE_GATEWAY_REASONS = [
        '440' => ['Re-enter transaction', 'Invalid amount'],
        '300' => [
            'Payment Token does not exist',
            'Duplicate transaction',
            'Error Processing Transaction',
            'Authentication Failed',
        ],
    ];
    
    /**
     * NMI codes that are customer problems (won't work on any gateway).
     */
    private const NON_RETRYABLE_CODES = [
        '201', // Do Not Honor
        '202', // Insufficient funds
        '203', // Exceeds withdrawal limit / Activity limit exceeded
        '220', // Invalid card number
        '222', // Account Closed
        '223', // Expired card
        '225', // CVV mismatch / Invalid CVV / CID Verification error
        '240', // Declined - Contact card issuer
        '250', // Pick up card - NF
        '251', // Pick up card - L (lost)
        '252', // Pick up card - S (stolen)
        '253', // Pick up card - SF (suspected fraud)
        '261', // Customer requested stop recurring
        '441', // Invalid transaction
    ];
    
    /**
     * Codes that become retryable in high-risk mode.
     * These are often bank-side declines based on MID risk profile,
     * not the customer's card. A different gateway may succeed.
     */
    private const HIGH_RISK_RETRYABLE_CODES = [
        '201', // Do Not Honor
        '250', // Pick up card - NF   (2.7.25: processor/MCC decline in this vertical)
        '251', // Pick up card - L    (2.7.25)
        '252', // Pick up card - S    (2.7.25)
        '253', // Pick up card - SF / F
    ];
    
    /**
     * NMI 204 sub-reasons: all are non-retryable (issuer/account problems)
     */
    private const NON_RETRYABLE_204_REASONS = [
        'Blocked',
        'Transaction not permitted',
        'Issuer Declined',
        'No credit account',
    ];
    
    /**
     * NMI 300 sub-reasons that are non-retryable
     */
    private const NON_RETRYABLE_300_REASONS = [
        'AVS REJECTED',
        'CVV REJECTED',
        'Transaction Declined',
        'Invalid Credit Card Number',
        'Card expiration',
        'card security code',
        'Amount exceeds',
        'phone field',
        'rollout requires',
        'token contains incomplete',
    ];
    
    /**
     * NMI 440 sub-reasons that are non-retryable
     */
    private const NON_RETRYABLE_440_REASONS = [
        'Security violation',
    ];
    
    /**
     * Friendly customer messages keyed by NMI code.
     * Used to replace WooCommerce's generic "Payment error" message.
     */
    private const CUSTOMER_MESSAGES = [
        '201' => 'This transaction was not successful and no charges were made to your card. Please try again.',
        '202' => 'Your card has insufficient funds. Please try a different card or contact your bank.',
        '203' => 'Your card limit has been exceeded. Please try a different card or contact your bank.',
        '204' => 'This transaction is not permitted by your bank. Please try a different card or contact your bank.',
        '220' => 'The card number appears to be invalid. Please double-check and try again.',
        '221' => 'We couldn\'t verify your card issuer. Please check your card number or try a different card.',
        '222' => 'This card account has been closed. Please use a different card.',
        '223' => 'This card has expired. Please use a different card.',
        '225' => 'The security code (CVV) doesn\'t match. Please check the 3 or 4 digit code on your card and try again.',
        '240' => 'Your card was declined. Please contact your bank or use a different card.',
        '250' => 'This card cannot be used for this transaction. Please contact your bank or use a different card.',
        '251' => 'This card cannot be used for this transaction. Please contact your bank or use a different card.',
        '252' => 'This card cannot be used for this transaction. Please contact your bank or use a different card.',
        '253' => 'This transaction was not successful and no charges were made to your card. Please try again.',
        '261' => 'Recurring payments have been stopped on this card. Please use a different card.',
        '300' => 'There was an issue processing your payment. Please check your card details and try again.',
        '440' => 'There was a security issue with this transaction. Please try again or use a different card.',
        '441' => 'This transaction could not be processed. Please try again or use a different card.',
    ];
    
    /**
     * Classify a failure from an order note string.
     *
     * @param string $noteContent Raw order note content (e.g. "NMI failure reason: 410 - Invalid merchant ID")
     * @return array{
     *     code: string,
     *     reason: string,
     *     category: string,
     *     is_retryable: bool,
     *     counts_against_health: bool,
     *     customer_message: string,
     *     card_type: string|null
     * }
     */
    /**
     * Classify a failure note and attach the customer-facing message.
     *
     * 2.7.25: messages come from one rule set (customerMessage()) so checkout,
     * the retry pay page and recovery emails always show the same text.
     */
    public static function classify(string $noteContent): array {
        $result = self::classifyRaw($noteContent);
        $result['customer_message'] = self::customerMessage($result);
        // 2.7.25 (business policy): 440 "Invalid amount" is the customer's card
        // refusing the amount (prepaid/limited cards; repeat customers succeed with
        // smaller orders). Keep the retry on another account, but it is not a
        // processor fault, so it no longer counts against gateway health.
        if (($result['code'] ?? '') === '440' && stripos((string) ($result['reason'] ?? ''), 'invalid amount') !== false) {
            $result['counts_against_health'] = false;
        }
        return $result;
    }

    private static function classifyRaw(string $noteContent): array {
        $result = [
            'code' => '',
            'reason' => '',
            'category' => self::CATEGORY_UNKNOWN,
            'is_retryable' => false,
            'counts_against_health' => false,
            'customer_message' => 'There was an error processing your payment. Please try again.',
            'card_type' => null,
        ];
        
        // Parse the note: "NMI failure reason: {code} - {message}"
        if (!preg_match('/^NMI failure reason:\s*(.+)$/i', $noteContent, $outerMatch)) {
            return $result;
        }
        
        $rawReason = trim($outerMatch[1]);
        
        // Try to extract numeric code and message
        if (preg_match('/^(\d{3})\s*-\s*(.+)$/', $rawReason, $codeMatch)) {
            $result['code'] = $codeMatch[1];
            // Strip REFID suffix for clean reason text
            $result['reason'] = preg_replace('/\s*REFID:\d+\s*$/', '', trim($codeMatch[2]));
        } else {
            // Non-standard format (e.g. "cURL error 28: ...", "Credit card details cannot be left incomplete")
            $result['reason'] = $rawReason;
            return self::classifyNonStandard($result);
        }
        
        $code = $result['code'];
        $reason = $result['reason'];
        
        // === RETRYABLE: Gateway-side codes ===
        if (in_array($code, self::RETRYABLE_GATEWAY_CODES, true)) {
            $result['category'] = self::CATEGORY_RETRYABLE_GATEWAY;
            $result['is_retryable'] = true;
            $result['counts_against_health'] = true;
            $result['customer_message'] = 'There was an error processing your payment. Please try again.';
            return $result;
        }
        
        // === RETRYABLE: Code + specific reason combinations ===
        if (isset(self::RETRYABLE_GATEWAY_REASONS[$code])) {
            foreach (self::RETRYABLE_GATEWAY_REASONS[$code] as $retryableReason) {
                if (stripos($reason, $retryableReason) !== false) {
                    $result['category'] = self::CATEGORY_RETRYABLE_GATEWAY;
                    $result['is_retryable'] = true;
                    $result['counts_against_health'] = true;
                    $result['customer_message'] = 'There was an error processing your payment. Please try again.';
                    return $result;
                }
            }
        }
        
        // === RETRYABLE: Card type not accepted ===
        if (preg_match('/cc payment type \[(.+?)\].*not accepted/i', $reason, $cardMatch)) {
            $result['category'] = self::CATEGORY_RETRYABLE_CARD_TYPE;
            $result['is_retryable'] = true;
            $result['counts_against_health'] = false; // Gateway correctly rejected unsupported card
            $result['card_type'] = strtolower(trim($cardMatch[1]));
            $result['customer_message'] = 'There was an error processing your payment. Please try again.';
            return $result;
        }
        
        // === HIGH-RISK MODE: Reclassify bank declines as retryable ===
        // Two modes (mutually exclusive):
        //   1. "auto" - retry on next available gateway (standard rotation)
        //   2. "specific" - retry on a specific chosen gateway
        $highRiskMode = self::getHighRiskMode();
        if ($highRiskMode !== 'off' && (in_array($code, self::HIGH_RISK_RETRYABLE_CODES, true) || stripos((string) $result['reason'], 'pick up card') !== false)) {
            $result['category'] = self::CATEGORY_RETRYABLE_GATEWAY;
            $result['is_retryable'] = true;
            // 2.7.22 (business policy): in this vertical, issuer declines 201/253 are
            // the issuing bank refusing the merchant's MCC on this account, not real
            // fraud. They are a property of the account/MID, so they count against
            // its health and shift traffic to accounts that approve.
            $result['counts_against_health'] = true;
            $result['customer_message'] = 'Payment failed to process. You can try this card again or use a different card to complete your order.';
            
            // If specific gateway mode, attach the forced gateway
            if ($highRiskMode === 'specific') {
                $result['forced_retry_gateway'] = get_option('po_high_risk_retry_gateway', '');
            }
            
            return $result;
        }
        
        // === NON-RETRYABLE: Direct code match ===
        if (in_array($code, self::NON_RETRYABLE_CODES, true)) {
            $result['category'] = self::CATEGORY_NON_RETRYABLE;
            $result['is_retryable'] = false;
            $result['counts_against_health'] = false;
            $result['customer_message'] = self::CUSTOMER_MESSAGES[$code] ?? $result['customer_message'];
            return $result;
        }
        
        // === NON-RETRYABLE: 204 with specific sub-reasons ===
        if ($code === '204') {
            foreach (self::NON_RETRYABLE_204_REASONS as $nr) {
                if (stripos($reason, $nr) !== false) {
                    $result['category'] = self::CATEGORY_NON_RETRYABLE;
                    $result['counts_against_health'] = false;
                    $result['customer_message'] = self::CUSTOMER_MESSAGES['204'];
                    return $result;
                }
            }
        }
        
        // === NON-RETRYABLE: 300 with specific sub-reasons ===
        if ($code === '300') {
            foreach (self::NON_RETRYABLE_300_REASONS as $nr) {
                if (stripos($reason, $nr) !== false) {
                    $result['category'] = self::CATEGORY_NON_RETRYABLE;
                    $result['counts_against_health'] = false;
                    $result['customer_message'] = self::CUSTOMER_MESSAGES['300'];
                    return $result;
                }
            }
        }
        
        // === NON-RETRYABLE: 440 with specific sub-reasons ===
        if ($code === '440') {
            foreach (self::NON_RETRYABLE_440_REASONS as $nr) {
                if (stripos($reason, $nr) !== false) {
                    $result['category'] = self::CATEGORY_NON_RETRYABLE;
                    $result['counts_against_health'] = false;
                    $result['customer_message'] = self::CUSTOMER_MESSAGES['440'];
                    return $result;
                }
            }
        }
        
        // === UNKNOWN: unrecognized code/reason - default safe (don't retry, don't penalize health) ===
        $result['category'] = self::CATEGORY_OTHER;
        $result['counts_against_health'] = false;
        return $result;
    }
    
    /**
     * Classify non-standard failure messages (no numeric code).
     */
    private static function classifyNonStandard(array $result): array {
        $reason = strtolower($result['reason']);
        
        // cURL timeouts and gateway communication errors -> retryable
        if (
            str_contains($reason, 'curl error') ||
            str_contains($reason, 'timed out') ||
            str_contains($reason, 'error with the gateway response') ||
            str_contains($reason, 'unrecognized response')
        ) {
            $result['category'] = self::CATEGORY_RETRYABLE_GATEWAY;
            $result['is_retryable'] = true;
            $result['counts_against_health'] = true;
            $result['customer_message'] = 'There was an error processing your payment. Please try again.';
            return $result;
        }
        
        // Card type refused before any charge -> retry on a gateway that accepts it.
        // Formats (2.7.22):
        //   "Card Type Not Accepted"          - vendor Direct Post check / legacy fork (no brand)
        //   "Card Type Not Accepted (amex)"   - PC NMI Accounts (brand in its card-type vocabulary)
        // Previously these fell through to CATEGORY_OTHER and were never retried.
        if (preg_match('/^card type not accepted\.?(?:\s*\(\s*([a-z][a-z \-]{0,30})\s*\))?\s*$/i', trim($result['reason']), $cardMatch)) {
            $result['category'] = self::CATEGORY_RETRYABLE_CARD_TYPE;
            $result['is_retryable'] = true;
            $result['counts_against_health'] = false; // Gateway correctly rejected unsupported card
            $result['card_type'] = isset($cardMatch[1]) && $cardMatch[1] !== '' ? strtolower(trim($cardMatch[1])) : null;
            $result['customer_message'] = 'There was an error processing your payment. Please try again.';
            return $result;
        }
        
        // Fail-closed refusals: the card was never sent to the gateway because the
        // Collect.js token was missing, unreadable, or minted by another NMI account
        // (PC NMI Accounts), or by an earlier fail-closed guard. Not a gateway fault;
        // the customer simply needs to reload and re-enter the card.
        if (
            (str_contains($reason, 'collect.js') && (
                str_contains($reason, 'token missing') ||
                str_contains($reason, 'response unreadable') ||
                str_contains($reason, 'different nmi gateway')
            )) ||
            str_contains($reason, 'we could not securely process your card')
        ) {
            $result['category'] = self::CATEGORY_NON_RETRYABLE;
            $result['counts_against_health'] = false;
            $result['customer_message'] = 'We could not securely process your card. Please refresh the page and try again.';
            return $result;
        }
        
        // Customer input errors -> non-retryable
        if (str_contains($reason, 'credit card details cannot be left incomplete')) {
            $result['category'] = self::CATEGORY_NON_RETRYABLE;
            $result['counts_against_health'] = false;
            $result['customer_message'] = 'Please fill in all credit card fields and try again.';
            return $result;
        }
        
        // Default: unknown -> don't retry, don't penalize
        $result['category'] = self::CATEGORY_OTHER;
        $result['counts_against_health'] = false;
        return $result;
    }
    
    /**
     * Check if a failure category is retryable.
     */
    public static function isRetryable(string $category): bool {
        return in_array($category, [
            self::CATEGORY_RETRYABLE_GATEWAY,
            self::CATEGORY_RETRYABLE_CARD_TYPE,
        ], true);
    }
    
    /**
     * Check if a failure category should count against gateway health.
     * Only gateway-side errors (not customer card problems) count.
     */
    public static function countsAgainstHealth(string $category): bool {
        return $category === self::CATEGORY_RETRYABLE_GATEWAY;
    }
    
    /* ------------------------------------------------------------------
     * Customer-facing messages (2.7.25). Interprets NMI's response codes and
     * texts; keeps the classifier's existing message when no rule applies.
     * ------------------------------------------------------------------ */
    private const MSG_PROCESSOR_DECLINE = 'Payment failed to process. You can try this card again or use a different card to complete your order. Your card was not charged.';
    private const MSG_TEMP_PROCESSING   = 'We couldn\'t complete your payment due to a temporary processing issue. Your card was not charged ' . "\u{2014}" . ' please try again below.';
    private const MSG_NO_RESPONSE       = 'We didn\'t receive a response from the payment processor. Please check your email for an order confirmation before trying again.';
    private const MSG_INVALID_NUMBER    = 'The card number appears to be invalid. Please check the number and try again.';
    private const MSG_CVV               = 'The security code (CVV) doesn\'t match. Please check the 3 or 4 digit code on your card and try again.';
    private const MSG_CARD_TYPE         = 'That payment option doesn\'t accept this card type. Please continue below with an option that accepts your card.';
    private const MSG_MAX_TICKET        = 'This order is above the amount this payment option can accept. Please split your purchase into smaller orders of $2,000 or less.';

    /**
     * Which checkout field the customer should fix (2.7.30), or null.
     *   'address' - billing address / ZIP (AVS rejected, street address missing)
     *   'cvv'     - card security code (CVV mismatch, rejected, wrong length, missing)
     */
    public static function fixField(array $r): ?string {
        $code   = (string) ($r['code'] ?? '');
        $reason = strtolower((string) ($r['reason'] ?? ''));
        if ($code === '225') {
            return 'cvv';
        }
        if ($code === '300') {
            if (strpos($reason, 'avs') !== false || strpos($reason, 'address1') !== false) {
                return 'address';
            }
            if (strpos($reason, 'cvv') !== false || strpos($reason, 'security code') !== false) {
                return 'cvv';
            }
        }
        return null;
    }

    /** Invisible marker carried inside a notice so the page can highlight the field. */
    public static function fixMarker(?string $field): string {
        return $field ? '<span class="po-fix-hint po-fix-' . $field . '"></span>' : '';
    }

    /**
     * @param array $r Classification result (code, reason, category).
     */
    public static function customerMessage(array $r): string {
        $code   = (string) ($r['code'] ?? '');
        $reason = strtolower((string) ($r['reason'] ?? ''));
        $has    = static fn(string $needle): bool => str_contains($reason, $needle);
        $current = (string) ($r['customer_message'] ?? '');

        // Processor-side declines in this vertical (MCC): Do Not Honor, any pick-up card
        if (in_array($code, ['201', '250', '251', '252', '253'], true) || $has('pick up card')) {
            return self::MSG_PROCESSOR_DECLINE;
        }

        switch ($code) {
            case '203':
                return $has('pin')
                    ? 'Too many incorrect PIN attempts have been made on this card. Please contact your bank or use a different card.'
                    : 'Your card\'s daily spending limit has been reached. Please use a different card, or ask your bank to raise the limit.';
            case '204':
                if ($has('blocked, first used')) {
                    return 'This card may not be activated yet. Please activate it with your bank, or use a different card.';
                }
                if ($has('mcc')) {
                    return 'Your bank doesn\'t allow this type of purchase on this card. Please use a different card, or contact your bank.';
                }
                if ($has('not permitted')) {
                    return 'Your bank didn\'t allow this purchase. Please use a different card, or contact your bank.';
                }
                return $current;
            case '220':
                return $has('verification data')
                    ? 'Your card details couldn\'t be verified. Please check the card number, expiry date and security code.'
                    : self::MSG_INVALID_NUMBER;
            case '221':
                return self::MSG_INVALID_NUMBER;
            case '223':
                return 'This card appears to be expired. Please check the expiry date you entered, or use a different card.';
            case '300':
                if ($has('avs')) {
                    return 'Your billing address or ZIP code didn\'t match your card. Please re-enter them exactly as they appear on your card statement.';
                }
                if ($has('cvv must be')) {
                    return 'The security code must be 3 digits (4 for American Express). Please check it and try again.';
                }
                if ($has('cvv')) {
                    return self::MSG_CVV;
                }
                if ($has('security code has never')) {
                    return 'Please enter the 3 or 4 digit security code from your card.';
                }
                if ($has('invalid credit card number')) {
                    return self::MSG_INVALID_NUMBER;
                }
                if ($has('expiration')) {
                    return 'Please enter the expiry date as MM/YY.';
                }
                if ($has('address1')) {
                    return 'Please enter your billing street address.';
                }
                if ($has('payment token contains incomplete')) {
                    return 'Please refresh the page and re-enter your card details.';
                }
                if ($has('payment token does not exist')) {
                    return 'Your payment session expired. Please refresh the page and re-enter your card details.';
                }
                if ($has('duplicate transaction')) {
                    return 'This looks like a duplicate of a payment just made. Please check your email for an order confirmation before trying again.';
                }
                if ($has('amount exceeds the maximum ticket')) {
                    return self::MSG_MAX_TICKET;
                }
                if ($has('payment type') && $has('not accepted')) {
                    return self::MSG_CARD_TYPE;
                }
                if ($has('internal error')) {
                    return self::MSG_TEMP_PROCESSING;
                }
                return $current;
            case '400':
            case '410':
                return self::MSG_TEMP_PROCESSING;
            case '411':
            case '421':
                return 'Your bank\'s system is temporarily unavailable. Please try again in a few minutes, or use a different card.';
            case '420':
                return self::MSG_NO_RESPONSE;
            case '440':
                if ($has('invalid amount')) {
                    return 'Your card couldn\'t approve this amount. Please use a different card, or contact your bank ' . "\u{2014}" . ' some cards limit the amount of a single purchase.';
                }
                if ($has('security violation')) {
                    return 'Your bank declined this payment for security reasons. Please use a different card, or contact your bank.';
                }
                if ($has('re-enter')) {
                    return self::MSG_TEMP_PROCESSING;
                }
                return $current;
            case '441':
                return 'This card can\'t be used for this type of purchase. Please use a different card.';
        }

        // Non-standard notes (no numeric code)
        if ($has('curl error') || $has('timed out') || $has('unrecognized response') || $has('error with the gateway')) {
            return self::MSG_NO_RESPONSE;
        }
        if ($has('card type not accepted')) {
            return self::MSG_CARD_TYPE;
        }
        return $current;
    }

    /**
     * SQL condition: does a po_failed_transactions row count as a GATEWAY fault?
     *
     * Single source of truth for health scoring and every processor success-rate
     * figure (2.7.22). Rows recorded since 2.7.22 carry the classifier's own
     * counts_against_health flag. Older rows (NULL) use the equivalent rule:
     * category retryable_gateway - processor errors, plus issuer declines 201/253
     * that high-risk mode retries (business policy: an issuer refusing the MCC
     * on an account counts against that account).
     * Card-type refusals never count: the gateway correctly declined an
     * unsupported brand.
     *
     * @param string $alias Table alias including the dot, e.g. 'f.' (or '').
     */
    public static function healthFaultSql(string $alias = ''): string {
        $a = preg_replace('/[^a-zA-Z0-9_.]/', '', $alias);
        $legacy = "({$a}failure_category = 'retryable_gateway' AND COALESCE({$a}failure_reason, '') NOT LIKE 'Invalid amount%')";
        if (get_option('po_fail_health_col') !== '1') {
            return $legacy;
        }
        return "({$a}counts_against_health = 1 OR ({$a}counts_against_health IS NULL AND {$legacy}))";
    }
    
    /**
     * Get high-risk retry mode.
     * Returns: 'off', 'auto' (any gateway), or 'specific' (chosen gateway)
     */
    private static function getHighRiskMode(): string {
        $mode = get_option('po_high_risk_retry_mode', 'off');
        if (in_array($mode, ['auto', 'specific'], true)) {
            return $mode;
        }
        // Legacy support: check old toggle
        if (get_option('po_high_risk_retry_enabled', 'no') === 'yes') {
            return 'auto';
        }
        return 'off';
    }
    
    // =========================================================================
    // G-Payments decline codes
    // Source: GivePayments API - payment.code field on payment_error responses
    // These are exact API string values - do not prefix or transform.
    // Note format: "G-Payments failure reason: {code} - {message}"
    // =========================================================================
    
    /**
     * GP decline codes that indicate a GATEWAY/NETWORK problem.
     * Customer's card is fine - retry on a different gateway.
     * Counts against gateway health.
     */
    private const GP_RETRYABLE_CODES = [
        'processing_error',
        'service_not_available',
        'service_issue',
        'server_issue',       // Server-side implementation error - gateway's infrastructure fault
        'no_action_taken',
        'reenter_transaction',
        'timeout',
        'card_network_issue',
        'duplicate_transaction',
        'resource_locked',
        'bank_auth_required',
        'action_not_supported',
    ];
    
    /**
     * GP decline codes that are customer/issuer problems (won't work on any gateway).
     * Does NOT count against gateway health.
     */
    private const GP_NON_RETRYABLE_CODES = [
        'insufficient_funds',
        'contact_issuer',
        'contact_acquirer',
        'invalid_card',
        'expired_card',
        'lost_card',
        'stolen_card',
        'restricted_card',
        'invalid_number',
        'invalid_cvv',
        'invalid_expiry',
        'invalid_address',
        'invalid_amount',
        'amount_limit_exceeded',
        'rate_limit_exceeded',
        // suspected_fraud removed - MID-dependent bank decision, retried on alternate gateway
        'security_violation',
        'fraud_block',
        'account_not_found',
        'invalid_account',
        'invalid_merchant',
        'incorrect_pin',
        'pin_try_exceeded',
        'pin_error',
        'action_not_allowed',
        'invalid_issuer',
        'invalid_transaction',
        'unknown',
        // Input/API errors surfaced by our plugin when no payment.code is present
        'payment_issue',
        'bad_request',
        // invalid_input removed - handled separately by sub-type (email vs PAN)
    ];
    
    /**
     * GP high-risk retryable codes - same concept as NMI 201/253.
     * Bank-side declines that are often MID-dependent. When high-risk retry
     * mode is enabled, these become retryable on a different gateway.
     * Counts against gateway health (these tend to not be customer fault).
     * When high-risk mode is OFF, these fall through to non-retryable.
     */
    private const GP_HIGH_RISK_RETRYABLE_CODES = [
        'do_not_honor',
        'generic_decline',
        'pickup_card',
    ];
    
    /**
     * Friendly customer messages for GP decline codes.
     * No processor name - plain English only.
     */
    private const GP_CUSTOMER_MESSAGES = [
        'insufficient_funds'    => 'Your card has insufficient funds. Please try a different card.',
        'expired_card'          => 'Your card has expired. Please use a different card.',
        'invalid_expiry'        => 'Your card expiry date is invalid. Please check and try again.',
        'invalid_cvv'           => 'The security code (CVV) is incorrect. Please check the 3 or 4 digit code on your card.',
        'invalid_number'        => 'The card number appears to be invalid. Please double-check and try again.',
        'lost_card'             => 'This card cannot be used. Please contact your bank or use a different card.',
        'stolen_card'           => 'This card cannot be used. Please contact your bank or use a different card.',
        'pickup_card'           => 'This card cannot be used. Please contact your bank or use a different card.',
        'suspected_fraud'       => 'This transaction was not successful. Please try a different card or contact your bank.',
        'security_violation'    => 'This transaction was not successful. Please try a different card or contact your bank.',
        'fraud_block'           => 'This transaction was not successful. Please try a different card or contact your bank.',
        'do_not_honor'          => 'Your card was declined. Please contact your bank or use a different card.',
        'generic_decline'       => 'Your card was declined. Please contact your bank or use a different card.',
        'contact_issuer'        => 'Your card was declined. Please contact your bank or use a different card.',
        'amount_limit_exceeded' => 'This transaction exceeds your card limit. Please try a different card.',
        'rate_limit_exceeded'   => 'Too many payment attempts. Please wait a moment and try again.',
        'invalid_input'         => 'Your card details appear to be invalid. Please check and try again.',
        'payment_issue'         => 'Your payment could not be processed. Please try a different card.',
    ];
    
    /**
     * Classify a G-Payments failure from an order note string.
     *
     * Note format: "G-Payments failure reason: {code} - {message}"
     * The decline code is a string like "insufficient_funds", "do_not_honor", etc.
     *
     * Returns the same result array shape as classify() for NMI.
     */
    public static function classifyGP(string $noteContent): array {
        $result = [
            'code' => '',
            'reason' => '',
            'category' => self::CATEGORY_UNKNOWN,
            'is_retryable' => false,
            'counts_against_health' => false,
            'customer_message' => 'There was an error processing your payment. Please try again.',
            'card_type' => null,
        ];
        
        // Match: "G-Payments failure reason: {code} - {message}"
        // The code may be either a GP string code (e.g. "service_issue") or an NMI
        // numeric code (e.g. "253", "410"). The latter happens when the GP gateway
        // runs on NMI processor infrastructure - the WC plugin writes NMI-style codes
        // into the G-Payments note prefix.
        if (!preg_match('/^G-Payments failure reason:\s*(\S+)\s*-\s*(.+)$/i', $noteContent, $m)) {
            return $result;
        }
        
        $result['code'] = trim($m[1]);
        $result['reason'] = trim($m[2]);
        $code = $result['code'];
        
        // === NMI-BACKED GATEWAY: numeric code found in a G-Payments note ===
        // When GivePay (or another GP-branded gateway) processes via NMI, decline
        // codes are numeric (201, 253, 410, etc.). Delegate to the NMI classifier
        // so all existing NMI retryability logic applies correctly.
        // Rewrite the note into NMI format and call classify().
        if (ctype_digit($code)) {
            return self::classify('NMI failure reason: ' . $code . ' - ' . $result['reason']);
        }
        
        // === SUSPECTED FRAUD: MID-dependent bank-side security challenge ===
        // "For your security, [BANK] requires you to authorize this purchase."
        // The bank is not declining the card - it's flagging the transaction based
        // on GivePay's MID risk profile. Same card often approves on NMI/Glacier.
        // Treat identically to 201 Do Not Honor and 253 Pick Up Card.
        // Does NOT count against health - this is a MID relationship issue, not
        // a GivePay processing failure.
        if ($code === 'suspected_fraud') {
            $result['category'] = self::CATEGORY_RETRYABLE_GATEWAY;
            $result['is_retryable'] = true;
            $result['counts_against_health'] = false;
            $result['customer_message'] = 'Payment failed to process. You can try this card again or use a different card to complete your order.';
            return $result;
        }

        // === INVALID INPUT: branch by sub-type ===
        // GivePay validates inputs before touching the card network.
        // Sub-type is determined by the error message content.
        if ($code === 'invalid_input') {
            $reason = $result['reason'];
            // PAN validation failure - GivePay is stricter than NMI.
            // The card number may still pass NMI's validator and be charged.
            // Classify as retryable_card_type (no health impact, retry on NMI).
            if (str_contains($reason, 'paymethod.card.number') || str_contains($reason, 'PAN')) {
                $result['category'] = self::CATEGORY_RETRYABLE_CARD_TYPE;
                $result['is_retryable'] = true;
                $result['counts_against_health'] = false;
                $result['customer_message'] = 'Your card details appear to be invalid. Please double-check your card number and try again.';
                return $result;
            }
            // Email validation failure - GivePay blocks certain domains (including
            // valid ones like ymail.com). NMI does not validate email at all.
            // Retry on NMI. No health impact - GivePay's validator is at fault.
            if (str_contains($reason, 'customer.email')) {
                $result['category'] = self::CATEGORY_RETRYABLE_GATEWAY;
                $result['is_retryable'] = true;
                $result['counts_against_health'] = false;
                $result['customer_message'] = 'There was an error processing your payment. Please try again.';
                return $result;
            }
            // Any other invalid_input sub-type - treat as non-retryable.
            $result['category'] = self::CATEGORY_NON_RETRYABLE;
            $result['is_retryable'] = false;
            $result['counts_against_health'] = false;
            $result['customer_message'] = self::GP_CUSTOMER_MESSAGES['invalid_input']
                ?? 'Your card details appear to be invalid. Please check and try again.';
            return $result;
        }

        // === RETRYABLE: Gateway/network-side codes ===
        if (in_array($code, self::GP_RETRYABLE_CODES, true)) {
            $result['category'] = self::CATEGORY_RETRYABLE_GATEWAY;
            $result['is_retryable'] = true;
            $result['counts_against_health'] = true;
            $result['customer_message'] = 'There was an error processing your payment. Please try again.';
            return $result;
        }
        
        // === HIGH-RISK MODE: Bank-side declines that are often MID-dependent ===
        // Same logic as NMI 201/253. When high-risk retry is enabled, these
        // become retryable on a different gateway. Counts against health because
        // in high-risk scenarios these tend to not be customer fault.
        $highRiskMode = self::getHighRiskMode();
        if ($highRiskMode !== 'off' && in_array($code, self::GP_HIGH_RISK_RETRYABLE_CODES, true)) {
            $result['category'] = self::CATEGORY_RETRYABLE_GATEWAY;
            $result['is_retryable'] = true;
            $result['counts_against_health'] = true;
            $result['customer_message'] = 'Payment failed to process. You can try this card again or use a different card to complete your order.';
            
            if ($highRiskMode === 'specific') {
                $result['forced_retry_gateway'] = get_option('po_high_risk_retry_gateway', '');
            }
            
            return $result;
        }
        
        // === NON-RETRYABLE: Customer/card-side codes ===
        // Also catches high-risk codes when high-risk mode is OFF
        if (in_array($code, self::GP_NON_RETRYABLE_CODES, true)
            || in_array($code, self::GP_HIGH_RISK_RETRYABLE_CODES, true)) {
            $result['category'] = self::CATEGORY_NON_RETRYABLE;
            $result['is_retryable'] = false;
            $result['counts_against_health'] = false;
            $result['customer_message'] = self::GP_CUSTOMER_MESSAGES[$code]
                ?? 'Your payment was declined. Please use a different card.';
            return $result;
        }
        
        // === UNKNOWN: unrecognized GP code - safe defaults ===
        $result['category'] = self::CATEGORY_OTHER;
        return $result;
    }
}
