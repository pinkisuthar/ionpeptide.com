<?php
/**
 * MAEF Gateway — WooCommerce Blocks Integration
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) exit;

final class MAEF_Gateway_Blocks_Support extends AbstractPaymentMethodType {

    private $gateway;
    protected $name = 'maef_child_gateway'; // Must match gateway id exactly

    public function initialize() {
        $this->settings = get_option('woocommerce_maef_child_gateway_settings', []);
        $gateways       = WC()->payment_gateways->payment_gateways();
        $this->gateway  = $gateways['maef_child_gateway'] ?? null;
    }

    public function is_active() {
        return $this->gateway && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'maef-gateway-blocks',
            plugin_dir_url(__FILE__) . 'maef-gateway-blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
            '1.0.0',
            true
        );
        return ['maef-gateway-blocks'];
    }

    public function get_payment_method_data() {
        return [
            'title'       => $this->settings['title']       ?? 'Secure Checkout (Card / Apple Pay / Google Pay)',
            'description' => $this->settings['description'] ?? 'Pay securely with credit/debit card, Apple Pay, or Google Pay.',
            'supports'    => $this->get_supported_features(),
        ];
    }

    /**
     * Return a low order value so MAEF sorts to the top of the block checkout payment list.
     * WooCommerce Blocks uses this to determine display order.
     */
    public function get_order() {
        return -1;
    }
}
