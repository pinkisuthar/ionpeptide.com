<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * IDEM Crypto Payments Blocks integration
 */
final class WC_Gateway_Idem_Crypto_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * The gateway instance.
	 *
	 * @var WC_Gateway_Idem_Crypto
	 */
	private $gateway;

	/**
	 * Payment method name/id/slug.
	 *
	 * @var string
	 */
	protected $name = 'idem_crypto';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_idem_settings', [] );
		$gateways       = WC()->payment_gateways->payment_gateways();
		$this->gateway  = isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
	}

	/**
	 * Returns if this payment method should be active.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return $this->gateway && $this->gateway->is_available();
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$script_path       = '/assets/js/frontend/blocks-crypto.js';
		$script_asset_path = WC_Idem_Payments::plugin_abspath() . 'assets/js/frontend/blocks-crypto.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require( $script_asset_path )
			: array(
				'dependencies' => array(),
				'version'      => '1.0.0'
			);
		$script_url        = WC_Idem_Payments::plugin_url() . $script_path;

		wp_register_script(
			'wc-idem-crypto-payments-blocks',
			$script_url,
			$script_asset[ 'dependencies' ],
			$script_asset[ 'version' ],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wc-idem-crypto-payments-blocks', 'woocommerce-gateway-idem', WC_Idem_Payments::plugin_abspath() . 'languages/' );
		}

		return [ 'wc-idem-crypto-payments-blocks' ];
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return [
			'title'       => $this->gateway ? $this->gateway->title : __('Pay with Crypto', 'woocommerce-gateway-idem'),
			'description' => $this->gateway ? $this->gateway->description : __('Pay securely using cryptocurrency.', 'woocommerce-gateway-idem'),
			'icon'        => $this->gateway ? $this->gateway->icon : 'https://gateway.idem-club.com/files/idem-club/woo-checkout-crypto.png',
			'supports'    => $this->gateway ? array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] ) : []
		];
	}
}
