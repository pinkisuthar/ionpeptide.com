<?php
/**
 * Edit the billing address on the payment page after an address decline (2.7.31).
 *
 * WooCommerce's pay-for-order page has no billing fields, so a customer whose
 * payment failed address verification (AVS) and who returns through a recovery
 * email or retry link could only retry with the same, wrong address. For unpaid
 * orders that have had an address failure (even if another decline followed), this adds the billing address
 * fields (pre-filled, highlighted) to the payment page and, on Pay, validates
 * them and updates the order's billing address before the charge is attempted.
 *
 * Shipping address and other orders are never touched. Fields only appear with
 * the order's own key on an order that needs payment; WooCommerce verifies the
 * payment form's nonce before woocommerce_before_pay_action runs.
 *
 * @package PaymentOrchestrator\Frontend
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Frontend;

use PaymentOrchestrator\Core\Logger;
use PaymentOrchestrator\Recovery\FailureClassifier;

defined('ABSPATH') || exit;

final class PayPageAddress {

    /** Editable billing fields (order getter suffix => label). */
    public const FIELDS = [
        'address_1' => 'Street address',
        'address_2' => 'Apartment, suite, etc. (optional)',
        'city'      => 'Town / City',
        'state'     => 'State',
        'postcode'  => 'ZIP code',
    ];
    private const REQUIRED = ['address_1', 'city', 'postcode'];

    public function register(): void {
        add_action('wp_footer', [$this, 'render'], 6);
        // Before POP's pay-page lock (10) and WooCommerce's payment processing
        add_action('woocommerce_before_pay_action', [$this, 'save'], 5, 1);
    }

    /**
     * Has this unpaid order had an address (AVS) failure?
     *
     * Any failure, not just the latest: a later, different decline (e.g. a
     * mistyped CVV on the payment page) doesn't mean the address was fixed.
     */
    public static function needsAddressFix(\WC_Order $order): bool {
        if (!$order->needs_payment()) {
            return false;
        }
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT failure_code, failure_reason FROM {$wpdb->prefix}po_failed_transactions WHERE order_id = %d ORDER BY id DESC LIMIT 20",
            $order->get_id()
        ));
        foreach ($rows as $row) {
            if (FailureClassifier::fixField(['code' => (string) $row->failure_code, 'reason' => (string) $row->failure_reason]) === 'address') {
                return true;
            }
        }
        return false;
    }

    /** Order on the current pay-for-order page, with a valid key. */
    private static function payPageOrder(): ?\WC_Order {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
            return null;
        }
        global $wp;
        $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        $order   = $orderId ? wc_get_order($orderId) : null;
        if (!$order) {
            return null;
        }
        $key = isset($_GET['key']) && is_string($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
        return ($key !== '' && hash_equals($order->get_order_key(), $key)) ? $order : null;
    }

    public function render(): void {
        try {
            $order = self::payPageOrder();
            if (!$order || !self::needsAddressFix($order)) {
                return;
            }
            $country = (string) $order->get_billing_country();
            $states  = function_exists('WC') && WC()->countries ? (array) WC()->countries->get_states($country) : [];
            $posted  = !empty($_POST['po_billing_address']) && is_array($_POST['po_billing_address']) ? wp_unslash($_POST['po_billing_address']) : [];

            echo '<div class="po-pay-address-holder" hidden><fieldset class="po-pay-address" aria-labelledby="po-pay-address-title">';
            echo '<h3 id="po-pay-address-title" class="po-pay-address__title">Billing address</h3>';
            echo '<p class="po-pay-address__intro">Update your billing address so it matches your card, then pay below.</p>';
            foreach (self::FIELDS as $key => $label) {
                $getter = 'get_billing_' . $key;
                $value  = isset($posted[$key]) ? wc_clean((string) $posted[$key]) : (string) $order->$getter();
                $id     = 'billing_' . $key; // ids match checkout so field highlighting applies
                $req    = in_array($key, self::REQUIRED, true) || ($key === 'state' && $states);
                echo '<p class="form-row po-pay-address__row" id="' . esc_attr($id) . '_field">';
                echo '<label for="' . esc_attr($id) . '">' . esc_html($label) . ($req ? ' <abbr class="required" title="required">*</abbr>' : '') . '</label>';
                if ($key === 'state' && $states) {
                    echo '<select id="' . esc_attr($id) . '" name="po_billing_address[state]" autocomplete="address-level1"' . ($req ? ' required' : '') . '>';
                    echo '<option value="">Select a state' . "\u{2026}" . '</option>';
                    foreach ($states as $code => $name) {
                        echo '<option value="' . esc_attr((string) $code) . '"' . selected($value, (string) $code, false) . '>' . esc_html((string) $name) . '</option>';
                    }
                    echo '</select>';
                } else {
                    $auto = ['address_1' => 'address-line1', 'address_2' => 'address-line2', 'city' => 'address-level2', 'state' => 'address-level1', 'postcode' => 'postal-code'][$key];
                    echo '<input type="text" class="input-text" id="' . esc_attr($id) . '" name="po_billing_address[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" autocomplete="' . esc_attr($auto) . '"' . ($req ? ' required' : '') . '>';
                }
                echo '</p>';
            }
            echo '</fieldset></div>';
            echo '<span class="po-fix-hint po-fix-address"></span>';
            echo '<style>.po-pay-address{border:1px solid #e2e2e2;border-radius:8px;padding:14px 16px;margin:0 0 18px}'
               . '.po-pay-address__title{margin:0 0 4px;font-size:16px}.po-pay-address__intro{margin:0 0 10px;font-size:14px}'
               . '.po-pay-address__row{margin:0 0 10px}.po-pay-address__row label{display:block;margin-bottom:4px}'
               . '.po-pay-address__row input,.po-pay-address__row select{width:100%;max-width:100%;box-sizing:border-box;min-height:40px}</style>';
            echo '<script>(function(){var h=document.querySelector(".po-pay-address-holder");var f=document.querySelector("form#order_review");'
               . 'if(!h||!f){return;}var a=h.querySelector(".po-pay-address");var p=f.querySelector("#payment, .wfacp_payment");'
               . 'if(p&&p.parentNode){p.parentNode.insertBefore(a,p);}else{f.insertBefore(a,f.firstChild);}h.parentNode.removeChild(h);})();</script>';
        } catch (\Throwable $e) {
            Logger::debug('Pay page address fields skipped', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Validate and save the corrected billing address before the charge.
     * A validation error is added as an error notice, so WooCommerce skips payment.
     *
     * @param \WC_Order|int $orderOrId
     */
    public function save($orderOrId): void {
        try {
            if (empty($_POST['po_billing_address']) || !is_array($_POST['po_billing_address'])) {
                return;
            }
            $order = $orderOrId instanceof \WC_Order ? $orderOrId : wc_get_order((int) $orderOrId);
            if (!$order || !self::needsAddressFix($order)) {
                return;
            }
            $in      = wp_unslash($_POST['po_billing_address']);
            $country = (string) $order->get_billing_country();
            $states  = function_exists('WC') && WC()->countries ? (array) WC()->countries->get_states($country) : [];
            $clean   = [];
            foreach (array_keys(self::FIELDS) as $key) {
                $clean[$key] = isset($in[$key]) && is_scalar($in[$key]) ? mb_substr(trim(wc_clean((string) $in[$key])), 0, 100) : '';
            }
            $missing = [];
            foreach (self::REQUIRED as $key) {
                if ($clean[$key] === '') {
                    $missing[] = self::FIELDS[$key];
                }
            }
            if ($states && !isset($states[$clean['state']])) {
                $missing[] = 'State';
            }
            if ($clean['postcode'] !== '' && class_exists('WC_Validation')
                && !\WC_Validation::is_postcode($clean['postcode'], $country)) {
                $missing[] = 'a valid ZIP code';
            }
            if ($missing) {
                wc_add_notice('Please enter ' . implode(', ', array_unique($missing)) . ' for your billing address.', 'error');
                return;
            }

            $before = [];
            $changed = false;
            foreach ($clean as $key => $value) {
                $getter = 'get_billing_' . $key;
                $setter = 'set_billing_' . $key;
                $before[$key] = (string) $order->$getter();
                if ($before[$key] !== $value) {
                    $order->$setter($value);
                    $changed = true;
                }
            }
            if ($changed) {
                $order->add_order_note('Billing address updated by the customer on the payment page (after an address verification decline).');
                $order->save();
                Logger::info('Pay page billing address updated', ['order_id' => $order->get_id()]);
            }
        } catch (\Throwable $e) {
            Logger::error('Pay page address save failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }
}
