<?php
/**
 * SSTG Payments Blocks Support
 *
 * @package SSTG_Payments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_SSTG_Payments_Blocks_Support extends AbstractPaymentMethodType {

    /**
     * @var string
     */
    protected $name = 'sstg_payments';

    /**
     * @var array
     */
    protected $settings = array();

    /**
     * Initialize the payment method type.
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_sstg_payments_settings', array() );
    }

    /**
     * Check if the payment method is active.
     *
     * @return bool
     */
    public function is_active() {
        $gateway = new WC_Gateway_SSTG_Payments();
        return $gateway->is_available();
    }

    /**
     * Get the payment method script handles.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        wp_localize_script( 'sstg-payments-blocks-integration', 'sstgPaymentsData', array(
            'baseUrl' => esc_url( home_url() ),
            'restUrl' => esc_url_raw( rest_url( 'sstg-payments/v1/' ) ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ) );

        return array( 'sstg-payments-blocks-integration' );
    }

    /**
     * Get the payment method data.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return array(
            'title'        => $this->settings['title'] ?? __( 'Pay with Card', 'sstg-payments' ),
            'description'  => $this->settings['description'] ?? '',
            'base_url' => $this->settings['base_url'] ?? 'https://request.sstgusa.com',
            'supports'     => array( 'products', 'refunds' ),
        );
    }
}