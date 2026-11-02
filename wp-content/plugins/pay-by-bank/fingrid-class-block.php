<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class my_fingrid_gateway_Blocks extends AbstractPaymentMethodType
{

    private $gateway;
    protected $name = 'my_fingrid_gateway';// your payment gateway name

    public function initialize()
    {
        $this->settings = get_option('woocommerce_my_fingrid_gateway_settings', []);
        $this->gateway = new Fingrid_Gateway();

          add_action(
            'woocommerce_blocks_checkout_update_order_meta',
            [ $this, 'save_blocks_payment_data' ],
            10,
            1
        );
    }

    public function is_active()
    {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles()
    {

        wp_register_script(
            'my_fingrid_gateway-blocks-integration',
            plugin_dir_url(__FILE__) . 'fingrid-checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );


        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('my_fingrid_gateway-blocks-integration');

        }
        return ['my_fingrid_gateway-blocks-integration'];
    }

    public function get_payment_method_data()
    {
        $user_id = get_current_user_id();
        $saved_banks = [];

        if ($user_id) {
            // Example: load from user meta or custom table
            $saved_banks = get_user_meta($user_id, '_fingrid_saved_banks', true);
            if (!is_array($saved_banks)) {
                $saved_banks = [];
            }
        }

        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
            'supports' => ['products'],
            'savedBanks' => $saved_banks, // 👈 pass to JS
        ];
    }

  public function save_blocks_payment_data( $order ) {
    $payment_data = $order->get_meta( '_wc_payment_data' ); 
    // In Blocks, Woo stores the raw payment_data inside meta
    // You can extract your fields from here if they were returned by JS onSubmit()

    if ( isset( $payment_data['payment_method'] ) && $payment_data['payment_method'] === 'my_fingrid_gateway' ) {
        if ( isset( $payment_data['fingrid_saved_bank_token'] ) ) {
            $order->update_meta_data(
                'fingrid_saved_bank_token',
                sanitize_text_field( $payment_data['fingrid_saved_bank_token'] )
            );
        }
        if ( isset( $payment_data['fingrid_new_bank_token'] ) ) {
            $order->update_meta_data(
                'fingrid_new_bank_token',
                sanitize_text_field( $payment_data['fingrid_new_bank_token'] )
            );
        }
        if ( isset( $payment_data['fingrid_new_bank_name'] ) ) {
            $order->update_meta_data(
                'fingrid_new_bank_name',
                sanitize_text_field( $payment_data['fingrid_new_bank_name'] )
            );
        }
        if ( isset( $payment_data['fingrid_new_bank_last4'] ) ) {
            $order->update_meta_data(
                'fingrid_new_bank_last4',
                sanitize_text_field( $payment_data['fingrid_new_bank_last4'] )
            );
        }
    }
}

}
?>