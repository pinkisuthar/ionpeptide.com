<?php
/**
 * Record the card brand on the order (2.7.33).
 *
 * The NMI plugin posts Collect.js's tokenization response with every payment
 * (nmi_js_response); it includes the card brand (card.type). POP stores the
 * normalised brand on the order as _po_card_brand so POP Retry, payment pages
 * and reporting can avoid gateways that don't accept that brand (e.g. a gateway
 * that stops accepting Mastercard). Only the brand is stored - no card data.
 *
 * @package PaymentOrchestrator\Frontend
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Frontend;

use PaymentOrchestrator\Gateway\CardBrands;
use PaymentOrchestrator\Core\Logger;

defined('ABSPATH') || exit;

final class CardBrandCapture {

    public const META = '_po_card_brand';

    public function register(): void {
        // Checkout: before the order is first saved (payment runs after this)
        add_action('woocommerce_checkout_create_order', [$this, 'onCheckoutCreateOrder'], 10, 1);
        // Payment pages: before POP's lock (10) and the payment itself
        add_action('woocommerce_before_pay_action', [$this, 'onPayAction'], 1, 1);
    }

    /** Normalised brand from the posted Collect.js response, or ''. */
    public static function postedBrand(): string {
        if (empty($_POST['nmi_js_response']) || !is_string($_POST['nmi_js_response'])) {
            return '';
        }
        $raw = json_decode(wp_unslash($_POST['nmi_js_response']), true);
        $type = is_array($raw) && isset($raw['card']['type']) && is_string($raw['card']['type']) ? $raw['card']['type'] : '';
        $brand = CardBrands::normalize($type);
        return isset(CardBrands::BRANDS[$brand]) ? $brand : '';
    }

    public function onCheckoutCreateOrder($order): void {
        try {
            $brand = self::postedBrand();
            if ($brand !== '' && $order instanceof \WC_Order) {
                $order->update_meta_data(self::META, $brand); // saved with the order
            }
        } catch (\Throwable $e) {
            Logger::debug('Card brand capture skipped', ['error' => $e->getMessage()]);
        }
    }

    public function onPayAction($orderOrId): void {
        try {
            $brand = self::postedBrand();
            $order = $orderOrId instanceof \WC_Order ? $orderOrId : wc_get_order((int) $orderOrId);
            if ($brand !== '' && $order) {
                $order->update_meta_data(self::META, $brand);
                $order->save_meta_data();
            }
        } catch (\Throwable $e) {
            Logger::debug('Card brand capture skipped', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Recorded brand for an order, or ''. Accepts any order-like object and never
     * throws - it is called inside WooCommerce's gateway filter.
     */
    public static function forOrder($order): string {
        try {
            return (is_object($order) && method_exists($order, 'get_meta')) ? (string) $order->get_meta(self::META) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
