<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WC_Gateway_LinkMoney_Blocks extends AbstractPaymentMethodType {

    protected $name = 'linkmoney';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_linkmoney_settings', [] );
    }

    public function is_active() {
        return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles() {
        $base_url = plugin_dir_url( LINKMONEY_PLUGIN_FILE );
        $file     = 'build/index.js';

        wp_register_script(
            'linkmoney-block-checkout',
            $base_url . $file,
            [ 'wc-blocks-registry', 'wp-element', 'wp-i18n' ],
            filemtime( plugin_dir_path( LINKMONEY_PLUGIN_FILE ) . $file ),
            true
        );

        $saved_accounts = [];
        $user_id        = get_current_user_id();
        $skip_api       = is_admin() && ! wp_doing_ajax();

        if ( $user_id && ! $skip_api ) {
            $gateway        = new WC_Gateway_LinkMoney();
            $saved_accounts = $gateway->get_saved_accounts( get_user_meta( $user_id, WC_Gateway_LinkMoney::META_CUSTOMER_ID, true ) );
        }

        wp_add_inline_script(
            'linkmoney-block-checkout',
            'window.linkmoneyData = ' . wp_json_encode( [
                'savedAccounts' => $saved_accounts,
                'sessionUrl'    => '',
            ] ) . ';',
            'before'
        );

        return [ 'linkmoney-block-checkout' ];
    }

    public function get_payment_method_script_handles_for_admin() {
        return $this->get_payment_method_script_handles();
    }


    public function get_payment_method_data() {
        return [
            'title'       => $this->settings['title'] ?? __( 'Pay by Bank (Link Money)', 'linkmoney' ),
            'description' => $this->settings['description'] ?? '',
            'supports'    => [ 'features' => [ 'products' ], 'style' => [] ],
        ];
    }
}