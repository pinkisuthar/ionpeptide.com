<?php
/**
 * WooCommerce Blocks (Gutenberg checkout block) payment method integration.
 *
 * Registers the 3thix gateway as a NATIVE blocks payment method so stores on
 * the modern `woocommerce/checkout` block get the same in-page experience as
 * the classic shortcode checkout — including the inline secure card iframe
 * (display = inline, mode = card) instead of the redirect fallback.
 *
 * @package Thix_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Thix_Blocks_Support extends AbstractPaymentMethodType {
    /**
     * Payment method name — must match the gateway id.
     *
     * @var string
     */
    protected $name = 'thix';

    /**
     * Lazily-resolved gateway instance (WooCommerce's registered one when
     * available, so option reads share the same settings cache).
     *
     * @var Thix_Gateway|null
     */
    private $gateway = null;

    public function initialize() {
        $this->settings = get_option('woocommerce_thix_settings', []);
    }

    /**
     * @return Thix_Gateway
     */
    private function gateway() {
        if ($this->gateway === null) {
            $gateways = function_exists('WC') && WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
            $this->gateway = (isset($gateways['thix']) && $gateways['thix'] instanceof Thix_Gateway)
                ? $gateways['thix']
                : new Thix_Gateway();
        }
        return $this->gateway;
    }

    public function is_active() {
        // A stale/shortened key must not make the Blocks integration appear
        // usable. Keep the admin setting screen configurable while failing
        // closed for buyer-facing registration.
        return $this->get_setting('enabled') === 'yes'
            && $this->gateway()->checkout_runtime_ready();
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'thix-payments-blocks',
            plugins_url('assets/blocks.js', THIX_PAYMENTS_FILE),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
            THIX_PAYMENTS_VERSION,
            true
        );
        return ['thix-payments-blocks'];
    }

    /**
     * Data handed to the blocks script via `wc.wcSettings.getSetting('thix_data')`.
     */
    public function get_payment_method_data() {
        return $this->gateway()->blocks_payment_method_data();
    }
}
