<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentResult;
use Automattic\WooCommerce\Blocks\Payments\PaymentContext;

defined( 'ABSPATH' ) || exit;

/**
 * WC_NMI2_Blocks_Support class.
 *
 * @extends AbstractPaymentMethodType
 */
final class WC_NMI2_Blocks_Support extends AbstractPaymentMethodType {
	/**
	 * Payment method name defined by payment methods extending this class.
	 *
	 * @var string
	 */
	protected $name = 'nmi2';

	public function __construct() {
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', [ $this, 'add_error_message_to_payment_result' ], 8, 2 );
	}

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_nmi2_settings', [] );
	}

	/**
	 * Returns if this payment method should be active.
	 * Disabled because NMI2 uses Direct Post (classic checkout form).
	 *
	 * @return boolean
	 */
	public function is_active() {
		return false;
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {

		$asset_path   = WC_NMI2_PLUGIN_PATH . '/build/index.asset.php';
		$version      = WC_NMI2_VERSION;
		$dependencies = [];
		if ( file_exists( $asset_path ) ) {
			$asset        = require $asset_path;
			$version      = is_array( $asset ) && isset( $asset['version'] )
				? $asset['version']
				: $version;
			$dependencies = is_array( $asset ) && isset( $asset['dependencies'] )
				? $asset['dependencies']
				: $dependencies;
		}

		$js_params = $this->get_gateway_javascript_params();

		if( ! empty( $js_params['public_key'] ) ) {
			wp_register_script( 'nmi2-collect-js', WC_NMI2_COLLECTJS_URL, '', null, true );
			$dependencies = array_merge( [ 'nmi2-collect-js' ], $dependencies );
		}

		wp_enqueue_style(
			'wc-nmi2-blocks-checkout-style',
			WC_NMI2_PLUGIN_URL . '/build/style-index.css',
			[],
			$version
		);

		wp_register_script(
			'wc-nmi2-blocks-integration',
			WC_NMI2_PLUGIN_URL . '/build/index.js',
			$dependencies,
			$version,
			true
		);
		wp_set_script_translations(
			'wc-nmi2-blocks-integration',
			'wc-nmi2'
		);

		return [ 'wc-nmi2-blocks-integration' ];
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array_replace_recursive(
			$this->get_gateway_javascript_params(),
			[
				'title'                          => $this->get_title(),
				'icons'                          => $this->get_icons(),
				'supports'                       => $this->get_supported_features(),
				'showSavedCards'                 => $this->get_show_saved_cards(),
				'showSaveOption'                 => $this->get_show_save_option(),
				'isAdmin'                        => is_admin(),
			]
		);
	}

	/**
	 * Returns the NMI2 Payment Gateway JavaScript configuration object.
	 *
	 * @return array  the JS configuration from the NMI2 Payment Gateway.
	 */
	private function get_gateway_javascript_params() {
		$js_configuration = [];

		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		if ( isset( $gateways['nmi2'] ) ) {
			$js_configuration = $gateways['nmi2']->javascript_params();
		}

		return apply_filters(
			'wc_nmi2_params',
			$js_configuration
		);
	}

	/**
	 * Determine if store allows cards to be saved during checkout.
	 *
	 * @return bool
	 */
	private function get_show_saved_cards() {
		return false;
	}

	/**
	 * Determine if the checkbox to enable the user to save their payment method should be shown.
	 *
	 * @return bool
	 */
	private function get_show_save_option() {
		$saved_cards = $this->get_show_saved_cards();
		return apply_filters( 'wc_nmi2_display_save_payment_method_checkbox', filter_var( $saved_cards, FILTER_VALIDATE_BOOLEAN ) );
	}

	/**
	 * Returns the title string to use in the UI.
	 *
	 * @return string
	 */
	private function get_title() {
		return isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Credit / Debit Card (NMI 2)', 'wc-nmi2' );
	}

	/**
	 * Return the icons urls.
	 *
	 * @return array
	 */
	private function get_icons() {
		$icons_src = array();
		$allowed_card_types = isset( $this->settings['allowed_card_types'] ) ? $this->settings['allowed_card_types'] : array();
		if( in_array( 'visa', $allowed_card_types ) ) {
			$icons_src['visa'] = [
				'src' => WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/visa.svg' ),
				'alt' => __( 'Visa', 'wc-nmi2' ),
			];
		}
		if( in_array( 'mastercard', $allowed_card_types ) ) {
			$icons_src['mastercard'] = [
				'src' => WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/mastercard.svg' ),
				'alt' => __( 'Mastercard', 'wc-nmi2' ),
			];
		}
		if( in_array( 'amex', $allowed_card_types ) ) {
			$icons_src['amex'] = [
				'src' => WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/amex.svg' ),
				'alt' => __( 'American Express', 'wc-nmi2' ),
			];
		}

		if ( 'USD' === get_woocommerce_currency() ) {
			if( in_array( 'discover', $allowed_card_types ) ) {
				$icons_src['discover'] = [
					'src' => WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/discover.svg' ),
					'alt' => _x( 'Discover', 'Name of credit card', 'wc-nmi2' ),
				];
			}
			if( in_array( 'jcb', $allowed_card_types ) ) {
				$icons_src['jcb'] = [
					'src' => WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/jcb.svg' ),
					'alt' => __( 'JCB', 'wc-nmi2' ),
				];
			}
			if( in_array( 'diners-club', $allowed_card_types ) ) {
				$icons_src['diners'] = [
					'src' => WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/diners.svg' ),
					'alt' => __( 'Diners', 'wc-nmi2' ),
				];
			}
		}
		return $icons_src;
	}

	/**
	 * Returns an array of supported features.
	 *
	 * @return string[]
	 */
	public function get_supported_features() {
		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		if ( isset( $gateways['nmi2'] ) ) {
			$gateway = $gateways['nmi2'];
			return array_filter( $gateway->supports, [ $gateway, 'supports' ] );
		}
		return [];
	}

	/**
	 * Add error message to the payment result for block checkout.
	 *
	 * @param PaymentContext $context
	 * @param PaymentResult  $result
	 */
	public function add_error_message_to_payment_result( PaymentContext $context, PaymentResult &$result ) {

		if( $this->name === $context->payment_method ) {
			add_action(
				'wc_gateway_nmi2_process_payment_error',
				function( $error ) use ( &$result ) {
					$payment_details                 = $result->payment_details;
					$payment_details['errorMessage'] = wp_strip_all_tags( $error->getMessage() );
					$result->set_payment_details( $payment_details );
				}
			);
		}

	}
}
