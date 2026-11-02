<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Pay gateway for NMI Gateway 3.
 *
 * This is intentionally separate from the NMI3 credit card gateway so the wallet
 * integration cannot replace or break the normal credit-card fields.
 */
class WC_Gateway_NMI3_Google_Pay extends WC_Payment_Gateway {

	public $testmode;
	public $api_keys;
	public $capture;
	public $private_key;
	public $public_key;
	public $username;
	public $password;
	public $gateway_url;
	public $logging;
	public $debugging;
	public $line_items;
	public $allowed_card_types;
	public $customer_receipt;
	public $use_nmi3_credentials;
	public $country;
	public $button_style;
	public $button_type;
	public $button_height;
	public $button_radius;
	public $request_billing;
	public $request_shipping;

	const NMI_REQUEST_URL_LOGIN    = 'https://secure.networkmerchants.com/api/transact.php';
	const NMI_REQUEST_URL_API_KEYS = 'https://secure.nmi.com/api/transact.php';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'nmi3_google_pay';
		$this->method_title       = __( 'NMI Gateway 3 - Google Pay', 'wc-nmi3' );
		$this->method_description = __( 'Adds Google Pay as its own WooCommerce payment method using NMI Collect.js. The normal NMI credit card gateway stays separate.', 'wc-nmi3' );
		$this->has_fields         = true;
		$this->supports           = array(
			'products',
			'refunds',
		);

		$this->init_form_fields();
		$this->init_settings();
		$this->load_settings_values();

		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Load settings and optionally borrow credentials from the main NMI3 card gateway.
	 */
	public function load_settings_values() {
		$nmi3_settings = get_option( 'woocommerce_nmi3_settings', array() );

		$this->title        = $this->get_option( 'title', __( 'Google Pay', 'wc-nmi3' ) );
		$this->description  = $this->get_option( 'description', __( 'Pay securely with Google Pay.', 'wc-nmi3' ) );
		$this->enabled      = $this->get_option( 'enabled', 'no' );
		$this->testmode     = $this->get_option( 'testmode', 'no' ) === 'yes';

		$this->use_nmi3_credentials = $this->get_option( 'use_nmi3_credentials', 'yes' ) === 'yes';

		$this->public_key = $this->get_option( 'public_key' );
		if ( empty( $this->public_key ) && ! empty( $nmi3_settings['public_key'] ) ) {
			$this->public_key = $nmi3_settings['public_key'];
		}

		if ( $this->use_nmi3_credentials ) {
			$this->api_keys         = isset( $nmi3_settings['api_keys'] ) ? ( 'yes' === $nmi3_settings['api_keys'] ) : true;
			$this->private_key      = isset( $nmi3_settings['private_key'] ) ? $nmi3_settings['private_key'] : '';
			$this->username         = isset( $nmi3_settings['username'] ) ? $nmi3_settings['username'] : '';
			$this->password         = isset( $nmi3_settings['password'] ) ? $nmi3_settings['password'] : '';
			$this->gateway_url      = isset( $nmi3_settings['gateway_url'] ) ? $nmi3_settings['gateway_url'] : '';
			$this->capture          = isset( $nmi3_settings['capture'] ) ? ( 'yes' === $nmi3_settings['capture'] ) : true;
			$this->line_items       = isset( $nmi3_settings['line_items'] ) ? ( 'yes' === $nmi3_settings['line_items'] ) : false;
			$this->customer_receipt = isset( $nmi3_settings['customer_receipt'] ) ? ( 'yes' === $nmi3_settings['customer_receipt'] ) : false;
			$this->logging          = isset( $nmi3_settings['logging'] ) ? ( 'yes' === $nmi3_settings['logging'] ) : false;
			$this->debugging        = isset( $nmi3_settings['debugging'] ) ? ( 'yes' === $nmi3_settings['debugging'] ) : false;
		} else {
			$this->api_keys         = $this->get_option( 'api_keys', 'yes' ) === 'yes';
			$this->private_key      = $this->get_option( 'private_key' );
			$this->username         = $this->get_option( 'username' );
			$this->password         = $this->get_option( 'password' );
			$this->gateway_url      = $this->get_option( 'gateway_url' );
			$this->capture          = $this->get_option( 'capture', 'yes' ) === 'yes';
			$this->line_items       = $this->get_option( 'line_items', 'no' ) === 'yes';
			$this->customer_receipt = $this->get_option( 'customer_receipt', 'no' ) === 'yes';
			$this->logging          = $this->get_option( 'logging', 'no' ) === 'yes';
			$this->debugging        = $this->get_option( 'debugging', 'no' ) === 'yes';
		}

		$this->allowed_card_types = $this->get_option( 'allowed_card_types', array( 'visa', 'mastercard', 'discover', 'amex' ) );
		$this->country            = strtoupper( $this->get_option( 'country', 'US' ) );
		$this->button_style       = $this->get_option( 'button_style', 'black' );
		$this->button_type        = $this->get_option( 'button_type', 'buy' );
		$this->button_height      = $this->get_option( 'button_height', '48px' );
		$this->button_radius      = $this->get_option( 'button_radius', '6px' );
		$this->request_billing    = $this->get_option( 'request_billing', 'yes' ) === 'yes';
		$this->request_shipping   = $this->get_option( 'request_shipping', 'no' ) === 'yes';
	}

	/**
	 * Admin settings.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'wc-nmi3' ),
				'label'   => __( 'Enable NMI Google Pay', 'wc-nmi3' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'wc-nmi3' ),
				'type'        => 'text',
				'description' => __( 'This controls the title customers see at checkout.', 'wc-nmi3' ),
				'default'     => __( 'Google Pay', 'wc-nmi3' ),
			),
			'description' => array(
				'title'       => __( 'Description', 'wc-nmi3' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description customers see at checkout.', 'wc-nmi3' ),
				'default'     => __( 'Pay securely with Google Pay.', 'wc-nmi3' ),
			),
			'public_key' => array(
				'title'       => __( 'Public Tokenization Key', 'wc-nmi3' ),
				'type'        => 'text',
				'description' => __( 'Required. This is the NMI Collect.js public tokenization key from Settings > Security Keys.', 'wc-nmi3' ),
				'default'     => '',
			),
			'use_nmi3_credentials' => array(
				'title'       => __( 'Credentials', 'wc-nmi3' ),
				'label'       => __( 'Use the private/API credentials from the main NMI Gateway 3 credit card settings', 'wc-nmi3' ),
				'type'        => 'checkbox',
				'description' => __( 'Recommended. This keeps Google Pay separate at checkout while reusing the already-working NMI transaction credentials.', 'wc-nmi3' ),
				'default'     => 'yes',
			),
			'api_keys' => array(
				'title'       => __( 'API Keys', 'wc-nmi3' ),
				'label'       => __( 'Use API key authentication instead of username/password', 'wc-nmi3' ),
				'type'        => 'checkbox',
				'description' => __( 'Used only when the option above is unchecked.', 'wc-nmi3' ),
				'default'     => 'yes',
			),
			'private_key' => array(
				'title'       => __( 'Private Key', 'wc-nmi3' ),
				'type'        => 'password',
				'description' => __( 'Used only when not borrowing credentials from the main NMI Gateway 3 settings.', 'wc-nmi3' ),
				'default'     => '',
			),
			'username' => array(
				'title'       => __( 'Gateway Username', 'wc-nmi3' ),
				'type'        => 'text',
				'description' => __( 'Legacy API method. Used only when API keys are disabled and credentials are not borrowed.', 'wc-nmi3' ),
				'default'     => '',
			),
			'password' => array(
				'title'       => __( 'Gateway Password', 'wc-nmi3' ),
				'type'        => 'password',
				'description' => __( 'Legacy API method. Used only when API keys are disabled and credentials are not borrowed.', 'wc-nmi3' ),
				'default'     => '',
			),
			'gateway_url' => array(
				'title'       => __( 'Gateway URL', 'wc-nmi3' ),
				'type'        => 'text',
				'description' => __( 'Optional. Leave empty to use the default NMI endpoint.', 'wc-nmi3' ),
				'default'     => '',
				'placeholder' => 'https://secure.nmi.com/api/transact.php',
			),
			'capture' => array(
				'title'       => __( 'Capture', 'wc-nmi3' ),
				'label'       => __( 'Capture charge immediately', 'wc-nmi3' ),
				'type'        => 'checkbox',
				'description' => __( 'When unchecked, Google Pay authorizes only.', 'wc-nmi3' ),
				'default'     => 'yes',
			),
			'allowed_card_types' => array(
				'title'       => __( 'Allowed card networks', 'wc-nmi3' ),
				'class'       => 'wc-enhanced-select',
				'type'        => 'multiselect',
				'description' => __( 'Google Pay can still be backed by a card network. Select the networks this merchant accepts.', 'wc-nmi3' ),
				'default'     => array( 'visa', 'mastercard', 'discover', 'amex' ),
				'options'     => array(
					'visa'       => __( 'Visa', 'wc-nmi3' ),
					'mastercard' => __( 'MasterCard', 'wc-nmi3' ),
					'discover'   => __( 'Discover', 'wc-nmi3' ),
					'amex'       => __( 'American Express', 'wc-nmi3' ),
				),
			),
			'country' => array(
				'title'       => __( 'Processing Country', 'wc-nmi3' ),
				'type'        => 'text',
				'description' => __( 'Two-letter country code used by Collect.js. Usually US.', 'wc-nmi3' ),
				'default'     => 'US',
			),
			'button_style' => array(
				'title'       => __( 'Google Pay Button Style', 'wc-nmi3' ),
				'type'        => 'select',
				'default'     => 'black',
				'options'     => array(
					'black'         => __( 'Black', 'wc-nmi3' ),
					'white'         => __( 'White', 'wc-nmi3' ),
					'white-outline' => __( 'White Outline', 'wc-nmi3' ),
				),
			),
			'button_type' => array(
				'title'       => __( 'Google Pay Button Type', 'wc-nmi3' ),
				'type'        => 'select',
				'default'     => 'buy',
				'options'     => array(
					'buy'       => __( 'Buy', 'wc-nmi3' ),
					'plain'     => __( 'Plain', 'wc-nmi3' ),
					'check-out' => __( 'Check Out', 'wc-nmi3' ),
					'order'     => __( 'Order', 'wc-nmi3' ),
				),
			),
			'button_height' => array(
				'title'       => __( 'Button Height', 'wc-nmi3' ),
				'type'        => 'text',
				'default'     => '70px',
			),
			'button_radius' => array(
				'title'       => __( 'Button Radius', 'wc-nmi3' ),
				'type'        => 'text',
				'default'     => '4px',
			),
			'request_billing' => array(
				'title'       => __( 'Billing Contact', 'wc-nmi3' ),
				'label'       => __( 'Request billing name/address plus phone/email through Google Pay', 'wc-nmi3' ),
				'type'        => 'checkbox',
				'description' => __( 'Leave off for checkout stability unless you specifically want Google Pay to fill billing fields.', 'wc-nmi3' ),
				'default'     => 'no',
			),
			'request_shipping' => array(
				'title'       => __( 'Shipping Contact', 'wc-nmi3' ),
				'label'       => __( 'Request shipping name/address through Google Pay', 'wc-nmi3' ),
				'type'        => 'checkbox',
				'description' => __( 'Leave off unless you specifically want Google Pay to ask for shipping inside the wallet sheet. WooCommerce shipping still controls the order shipping method.', 'wc-nmi3' ),
				'default'     => 'no',
			),
			'line_items' => array(
				'title'       => __( 'Line Items', 'wc-nmi3' ),
				'label'       => __( 'Enable line item description in NMI', 'wc-nmi3' ),
				'type'        => 'checkbox',
				'description' => __( 'Used only when not borrowing the main NMI3 settings.', 'wc-nmi3' ),
				'default'     => 'no',
			),
			'customer_receipt' => array(
				'title'       => __( 'Receipt', 'wc-nmi3' ),
				'label'       => __( 'Send Gateway Receipt', 'wc-nmi3' ),
				'type'        => 'checkbox',
				'default'     => 'no',
			),
			'logging' => array(
				'title'       => __( 'Logging', 'wc-nmi3' ),
				'label'       => __( 'Log debug messages', 'wc-nmi3' ),
				'type'        => 'checkbox',
				'description' => sprintf( __( 'Save debug messages to WooCommerce logs: <code>%s</code>.', 'wc-nmi3' ), WC_Log_Handler_File::get_log_file_path( 'wc-nmi3-google-pay' ) ),
				'default'     => 'no',
			),
			'debugging' => array(
				'title'       => __( 'Gateway Debug', 'wc-nmi3' ),
				'label'       => __( 'Log gateway requests and responses', 'wc-nmi3' ),
				'type'        => 'checkbox',
				'description' => __( '<strong>CAUTION:</strong> Enable only temporarily. Wallet tokens are short-lived, but gateway logs are still sensitive.', 'wc-nmi3' ),
				'default'     => 'no',
			),
		);
	}

	/**
	 * Admin options with a small settings helper.
	 */
	public function admin_options() { ?>
		<script>
			jQuery(function($){
				function nmi3GooglePayToggle(){
					var borrow = $('#woocommerce_nmi3_google_pay_use_nmi3_credentials').is(':checked');
					var apiKeys = $('#woocommerce_nmi3_google_pay_api_keys').is(':checked');
					var borrowedFields = [
						'#woocommerce_nmi3_google_pay_api_keys',
						'#woocommerce_nmi3_google_pay_private_key',
						'#woocommerce_nmi3_google_pay_username',
						'#woocommerce_nmi3_google_pay_password',
						'#woocommerce_nmi3_google_pay_gateway_url',
						'#woocommerce_nmi3_google_pay_capture',
						'#woocommerce_nmi3_google_pay_line_items',
						'#woocommerce_nmi3_google_pay_customer_receipt',
						'#woocommerce_nmi3_google_pay_logging',
						'#woocommerce_nmi3_google_pay_debugging'
					];
					borrowedFields.forEach(function(sel){ $(sel).closest('tr').toggle(!borrow); });
					if (!borrow) {
						$('#woocommerce_nmi3_google_pay_private_key').closest('tr').toggle(apiKeys);
						$('#woocommerce_nmi3_google_pay_username, #woocommerce_nmi3_google_pay_password').closest('tr').toggle(!apiKeys);
					}
				}
				$(document.body).on('change', '#woocommerce_nmi3_google_pay_use_nmi3_credentials, #woocommerce_nmi3_google_pay_api_keys', nmi3GooglePayToggle);
				nmi3GooglePayToggle();
			});
		</script>
		<?php parent::admin_options();
	}

	/**
	 * Gateway icon.
	 */
	public function get_icon() {
		return '<span class="nmi3-google-pay-admin-icon" style="display:inline-block;margin-left:.35em;font-weight:600;letter-spacing:-.02em;">Google Pay</span>';
	}

	/**
	 * Admin notices.
	 */
	public function admin_notices() {
		if ( 'yes' !== $this->enabled ) {
			return;
		}

		if ( empty( $this->public_key ) ) {
			echo '<div class="error"><p>' . sprintf( __( 'NMI Google Pay error: Please enter the Public Tokenization Key <a href="%s">here</a>.', 'wc-nmi3' ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nmi3_google_pay' ) ) ) . '</p></div>';
			return;
		}

		if ( $this->api_keys && empty( $this->private_key ) ) {
			echo '<div class="error"><p>' . sprintf( __( 'NMI Google Pay error: Please enter a Private Key in NMI Google Pay or the main NMI Gateway 3 settings <a href="%s">here</a>.', 'wc-nmi3' ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nmi3_google_pay' ) ) ) . '</p></div>';
			return;
		}

		if ( ! $this->api_keys && ( empty( $this->username ) || empty( $this->password ) ) ) {
			echo '<div class="error"><p>' . sprintf( __( 'NMI Google Pay error: Please enter gateway username/password in NMI Google Pay or the main NMI Gateway 3 settings <a href="%s">here</a>.', 'wc-nmi3' ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nmi3_google_pay' ) ) ) . '</p></div>';
			return;
		}

		if ( ! wc_checkout_is_https() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'NMI Google Pay is enabled, but checkout does not appear to be HTTPS. Google Pay requires HTTPS.', 'wc-nmi3' ) . '</p></div>';
		}
	}

	/**
	 * Availability check.
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( is_add_payment_method_page() ) {
			return false;
		}

		if ( empty( $this->public_key ) ) {
			return false;
		}

		if ( $this->api_keys && empty( $this->private_key ) ) {
			return false;
		}

		if ( ! $this->api_keys && ( empty( $this->username ) || empty( $this->password ) ) ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Payment fields on checkout.
	 */
	public function payment_fields() {
		$price = $this->get_checkout_amount();
		?>
		<div class="nmi3-google-pay-payment-data" data-price="<?php echo esc_attr( $price ); ?>">
			<?php if ( $this->description ) : ?>
				<div class="nmi3-google-pay-description"><?php echo wpautop( wp_kses_post( $this->description ) ); ?></div>
			<?php endif; ?>

			<input type="hidden" name="nmi3_google_pay_token" id="nmi3_google_pay_token" value="" />
			<input type="hidden" name="nmi3_google_pay_response" id="nmi3_google_pay_response" value="" />
			<input type="hidden" name="nmi3_google_pay_amount" id="nmi3_google_pay_amount" value="<?php echo esc_attr( $price ); ?>" />

			<div id="nmi3-google-pay-button" class="nmi3-google-pay-button"></div>
			<div id="nmi3-google-pay-status" class="nmi3-google-pay-status" aria-live="polite"></div>

			<noscript><?php esc_html_e( 'Google Pay requires JavaScript.', 'wc-nmi3' ); ?></noscript>
		</div>
		<?php
	}

	/**
	 * Frontend scripts.
	 */
	public function payment_scripts() {
		if ( ! is_checkout() || is_order_received_page() || is_add_payment_method_page() ) {
			return;
		}

		if ( 'yes' !== $this->enabled || empty( $this->public_key ) ) {
			return;
		}

		wp_enqueue_script(
			'nmi3-google-pay-checkout',
			WC_NMI3_PLUGIN_URL . '/assets/js/nmi3-google-pay.js',
			array( 'jquery', 'wc-checkout' ),
			WC_NMI3_VERSION,
			true
		);

		wp_localize_script( 'nmi3-google-pay-checkout', 'wc_nmi3_google_pay_params', $this->javascript_params() );

		wp_register_style( 'nmi3-google-pay-checkout', false, array(), WC_NMI3_VERSION );
		wp_enqueue_style( 'nmi3-google-pay-checkout' );
		wp_add_inline_style( 'nmi3-google-pay-checkout', $this->checkout_css() );
	}

	/**
	 * Frontend JS params.
	 */
	public function javascript_params() {
		return array(
			'gateway_id'        => $this->id,
			'public_key'        => $this->public_key,
			'collect_js_url'    => defined( 'WC_NMI3_COLLECTJS_URL' ) ? WC_NMI3_COLLECTJS_URL : 'https://secure.nmi.com/token/Collect.js',
			'button_selector'   => '#nmi3-google-pay-button',
			'price'             => $this->get_checkout_amount(),
			'currency'          => get_woocommerce_currency(),
			'country'           => $this->country,
			'total_label'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'button_style'      => $this->button_style,
			'button_type'       => $this->button_type,
			'button_height'     => $this->button_height,
			'button_radius'     => $this->button_radius,
			'request_billing'   => $this->request_billing ? 'yes' : 'no',
			'request_shipping'  => $this->request_shipping ? 'yes' : 'no',
			'i18n_unavailable'  => __( 'Google Pay is unavailable on this browser/device or this gateway.', 'wc-nmi3' ),
			'i18n_loading'      => __( 'Loading Google Pay…', 'wc-nmi3' ),
			'i18n_ready'        => __( 'Click the Google Pay button or Place Order to continue.', 'wc-nmi3' ),
			'i18n_token_error'  => __( 'Google Pay could not create a payment token. Please try again or use credit card.', 'wc-nmi3' ),
			'i18n_use_button'   => __( 'Please complete Google Pay first.', 'wc-nmi3' ),
			'i18n_script_error' => __( 'Google Pay could not load. Please refresh or use credit card.', 'wc-nmi3' ),
			'i18n_timeout'      => __( 'Google Pay timed out. Please try again or use credit card.', 'wc-nmi3' ),
		);
	}

	/**
	 * Minimal checkout CSS.
	 */
	public function checkout_css() {
		return '.nmi3-google-pay-button{min-height:70px;min-width:240px;margin:12px 0 8px}.nmi3-google-pay-status{font-size:13px;line-height:1.35;margin-top:8px}.nmi3-google-pay-status.error{color:#b32d2e}.nmi3-google-pay-status.success{color:#1f7a43}body.nmi3-google-pay-unavailable li.payment_method_nmi3_google_pay{display:none!important}';
	}

	/**
	 * Current checkout/cart amount formatted for Collect.js.
	 */
	public function get_checkout_amount() {
		$amount = 0;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$amount = WC()->cart->total;
		}
		return wc_format_decimal( $amount, 2 );
	}

	/**
	 * Process Google Pay payment.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$this->log( "Info: Beginning Google Pay payment for order $order_id for the amount of {$order->get_total()}" );

		$response = false;

		try {
			$token = isset( $_POST['nmi3_google_pay_token'] ) ? sanitize_text_field( wp_unslash( $_POST['nmi3_google_pay_token'] ) ) : '';
			if ( empty( $token ) ) {
				throw new Exception( __( 'Google Pay token was missing. Please try Google Pay again or use credit card.', 'wc-nmi3' ) );
			}

			$wallet_response = $this->get_wallet_response();
			if ( ! empty( $wallet_response['tokenType'] ) && 'googlePay' !== $wallet_response['tokenType'] ) {
				throw new Exception( __( 'Invalid wallet token type. Please use Google Pay or select another payment method.', 'wc-nmi3' ) );
			}

			$network = $this->get_wallet_card_network( $wallet_response );
			if ( $network && ! empty( $this->allowed_card_types ) && ! in_array( $network, $this->allowed_card_types, true ) ) {
				throw new Exception( __( 'This Google Pay card network is not accepted. Please use a different Google Pay card or use credit card.', 'wc-nmi3' ) );
			}

			$this->maybe_update_order_from_wallet( $order, $wallet_response );

			$description = sprintf( __( '%s - Order %s - Google Pay', 'wc-nmi3' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );
			if ( $this->line_items ) {
				$description .= ' (' . $this->get_line_items( $order ) . ')';
			}

			$posted_google_pay_amount = isset( $_POST['nmi3_google_pay_amount'] ) ? wc_format_decimal( wc_clean( wp_unslash( $_POST['nmi3_google_pay_amount'] ) ), 2 ) : '';
			if ( $posted_google_pay_amount ) {
				$order->update_meta_data( '_nmi3_google_pay_token_requested_amount', $posted_google_pay_amount );
			}

			$payment_args = array(
				'payment_token'     => $token,
				'orderid'           => $order->get_order_number(),
				'order_description' => $description,
				'amount'            => $order->get_total(),
				'transactionid'     => $order->get_transaction_id(),
				'type'              => $this->capture ? 'sale' : 'auth',
				'first_name'        => $order->get_billing_first_name(),
				'last_name'         => $order->get_billing_last_name(),
				'address1'          => $order->get_billing_address_1(),
				'address2'          => $order->get_billing_address_2(),
				'city'              => $order->get_billing_city(),
				'state'             => $order->get_billing_state(),
				'country'           => $order->get_billing_country(),
				'zip'               => $order->get_billing_postcode(),
				'email'             => $order->get_billing_email(),
				'phone'             => $order->get_billing_phone(),
				'company'           => $order->get_billing_company(),
				'shipping_firstname'=> $order->get_shipping_first_name(),
				'shipping_lastname' => $order->get_shipping_last_name(),
				'shipping_address_1'=> $order->get_shipping_address_1(),
				'shipping_address_2'=> $order->get_shipping_address_2(),
				'shipping_city'     => $order->get_shipping_city(),
				'shipping_state'    => $order->get_shipping_state(),
				'shipping_country'  => $order->get_shipping_country(),
				'shipping_zip'      => $order->get_shipping_postcode(),
				'currency'          => $this->get_payment_currency( $order_id ),
			);

			if ( $network ) {
				$order->update_meta_data( '_nmi3_google_pay_card_network', $network );
			}

			if ( ! empty( $wallet_response['wallet']['cardDetails'] ) ) {
				$order->update_meta_data( '_nmi3_google_pay_last4', sanitize_text_field( $wallet_response['wallet']['cardDetails'] ) );
			}

			$payment_args = apply_filters( 'wc_nmi3_google_pay_request_args', $payment_args, $order, $wallet_response );

			$response = $this->nmi_request( $payment_args );
			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}

			$order->update_meta_data( '_nmi3_google_pay_charge_id', $response['transactionid'] );
			$order->update_meta_data( '_nmi3_google_pay_authorization_code', isset( $response['authcode'] ) ? $response['authcode'] : '' );
			$order->set_transaction_id( $response['transactionid'] );

			if ( 'sale' === $payment_args['type'] ) {
				$order->update_meta_data( '_nmi3_google_pay_charge_captured', 'yes' );
				$order->update_meta_data( 'NMI3 Google Pay Payment ID', $response['transactionid'] );
				$order->payment_complete( $response['transactionid'] );
				$complete_message = sprintf( __( 'NMI Google Pay charge completed (Charge ID: %s).', 'wc-nmi3' ), $response['transactionid'] );
				$order->add_order_note( $complete_message );
				$this->log( 'Success: ' . wp_strip_all_tags( $complete_message ) );
			} else {
				$order->update_meta_data( '_nmi3_google_pay_charge_captured', 'no' );
				if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
					wc_reduce_stock_levels( $order_id );
				}
				$authorized_message = sprintf( __( 'NMI Google Pay charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization.', 'wc-nmi3' ), $response['transactionid'] );
				$order->update_status( 'on-hold', $authorized_message . "\n" );
				$this->log( 'Success: ' . wp_strip_all_tags( $authorized_message ) );
			}

			$order->save();
			WC()->cart->empty_cart();

			do_action( 'wc_gateway_nmi3_google_pay_process_payment', $response, $order, $wallet_response );

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		} catch ( Exception $e ) {
			wc_add_notice( sprintf( __( 'Gateway Error: %s', 'wc-nmi3' ), $e->getMessage() ), 'error' );
			$this->log( sprintf( __( 'Gateway Error: %s', 'wc-nmi3' ), $e->getMessage() ) );

			if ( is_wp_error( $response ) && $response->get_error_data() ) {
				$error_data = $response->get_error_data();
				$order->add_order_note( sprintf( __( 'NMI Google Pay failure reason: %s', 'wc-nmi3' ), $error_data['response_code'] . ' - ' . $error_data['responsetext'] ) );
			} else {
				$order->add_order_note( sprintf( __( 'NMI Google Pay failure reason: %s', 'wc-nmi3' ), $e->getMessage() ) );
			}

			do_action( 'wc_gateway_nmi3_google_pay_process_payment_error', $e, $order );
			$order->update_status( 'failed' );

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}
	}

	/**
	 * Decode the wallet response JSON posted by checkout JS.
	 */
	public function get_wallet_response() {
		if ( empty( $_POST['nmi3_google_pay_response'] ) ) {
			return array();
		}

		$raw      = wp_unslash( $_POST['nmi3_google_pay_response'] );
		$decoded  = json_decode( $raw, true );
		$response = is_array( $decoded ) ? $decoded : array();

		return $response;
	}

	/**
	 * Normalize Google Pay network names.
	 */
	public function get_wallet_card_network( $wallet_response ) {
		$network = '';
		if ( ! empty( $wallet_response['wallet']['cardNetwork'] ) ) {
			$network = $wallet_response['wallet']['cardNetwork'];
		} elseif ( ! empty( $wallet_response['card']['type'] ) ) {
			$network = $wallet_response['card']['type'];
		}

		$network = strtolower( sanitize_text_field( $network ) );
		$network = str_replace( array( ' ', '_' ), '-', $network );

		$map = array(
			'master-card'      => 'mastercard',
			'mastercard'       => 'mastercard',
			'visa'             => 'visa',
			'amex'             => 'amex',
			'american-express' => 'amex',
			'discover'         => 'discover',
		);

		return isset( $map[ $network ] ) ? $map[ $network ] : $network;
	}

	/**
	 * Fill missing order fields from Google Pay wallet payload when available.
	 */
	public function maybe_update_order_from_wallet( $order, $wallet_response ) {
		if ( empty( $wallet_response['wallet'] ) || ! is_array( $wallet_response['wallet'] ) ) {
			return;
		}

		$wallet   = $wallet_response['wallet'];
		$billing  = isset( $wallet['billingInfo'] ) && is_array( $wallet['billingInfo'] ) ? $wallet['billingInfo'] : array();
		$shipping = isset( $wallet['shippingInfo'] ) && is_array( $wallet['shippingInfo'] ) ? $wallet['shippingInfo'] : array();

		if ( ! empty( $billing ) ) {
			$this->maybe_set_order_prop( $order, 'billing_first_name', 'set_billing_first_name', isset( $billing['firstName'] ) ? $billing['firstName'] : '' );
			$this->maybe_set_order_prop( $order, 'billing_last_name', 'set_billing_last_name', isset( $billing['lastName'] ) ? $billing['lastName'] : '' );
			$this->maybe_set_order_prop( $order, 'billing_address_1', 'set_billing_address_1', isset( $billing['address1'] ) ? $billing['address1'] : '' );
			$this->maybe_set_order_prop( $order, 'billing_address_2', 'set_billing_address_2', isset( $billing['address2'] ) ? $billing['address2'] : '' );
			$this->maybe_set_order_prop( $order, 'billing_city', 'set_billing_city', isset( $billing['city'] ) ? $billing['city'] : '' );
			$this->maybe_set_order_prop( $order, 'billing_state', 'set_billing_state', isset( $billing['state'] ) ? $billing['state'] : '' );
			$this->maybe_set_order_prop( $order, 'billing_country', 'set_billing_country', isset( $billing['country'] ) ? $billing['country'] : '' );
			$this->maybe_set_order_prop( $order, 'billing_postcode', 'set_billing_postcode', isset( $billing['postalCode'] ) ? $billing['postalCode'] : '' );
			$this->maybe_set_order_prop( $order, 'billing_phone', 'set_billing_phone', isset( $billing['phone'] ) ? $billing['phone'] : '' );
		}

		if ( empty( $order->get_billing_email() ) && ! empty( $wallet['email'] ) ) {
			$order->set_billing_email( sanitize_email( $wallet['email'] ) );
		}

		if ( ! empty( $shipping ) ) {
			$this->maybe_set_order_prop( $order, 'shipping_first_name', 'set_shipping_first_name', isset( $shipping['firstName'] ) ? $shipping['firstName'] : '' );
			$this->maybe_set_order_prop( $order, 'shipping_last_name', 'set_shipping_last_name', isset( $shipping['lastName'] ) ? $shipping['lastName'] : '' );
			$this->maybe_set_order_prop( $order, 'shipping_address_1', 'set_shipping_address_1', isset( $shipping['address1'] ) ? $shipping['address1'] : '' );
			$this->maybe_set_order_prop( $order, 'shipping_address_2', 'set_shipping_address_2', isset( $shipping['address2'] ) ? $shipping['address2'] : '' );
			$this->maybe_set_order_prop( $order, 'shipping_city', 'set_shipping_city', isset( $shipping['city'] ) ? $shipping['city'] : '' );
			$this->maybe_set_order_prop( $order, 'shipping_state', 'set_shipping_state', isset( $shipping['state'] ) ? $shipping['state'] : '' );
			$this->maybe_set_order_prop( $order, 'shipping_country', 'set_shipping_country', isset( $shipping['country'] ) ? $shipping['country'] : '' );
			$this->maybe_set_order_prop( $order, 'shipping_postcode', 'set_shipping_postcode', isset( $shipping['postalCode'] ) ? $shipping['postalCode'] : '' );
		}
	}

	/**
	 * Set a prop only if Woo does not already have one.
	 */
	public function maybe_set_order_prop( $order, $getter_key, $setter, $value ) {
		if ( empty( $value ) || ! method_exists( $order, $setter ) ) {
			return;
		}

		$getter = 'get_' . $getter_key;
		if ( method_exists( $order, $getter ) && empty( $order->$getter() ) ) {
			$order->$setter( wc_clean( $value ) );
		}
	}

	/**
	 * NMI request wrapper.
	 */
	public function nmi_request( $args ) {
		$gateway_debug = ( $this->logging && $this->debugging );

		if ( ! empty( $this->gateway_url ) ) {
			$request_url = $this->gateway_url;
		} else {
			$request_url = $this->api_keys ? self::NMI_REQUEST_URL_API_KEYS : self::NMI_REQUEST_URL_LOGIN;
		}
		$request_url = apply_filters( 'wc_nmi3_google_pay_request_url', $request_url );

		$auth_params = $this->api_keys ? array( 'security_key' => $this->private_key ) : array(
			'username' => $this->username,
			'password' => $this->password,
		);

		$args['customer_receipt'] = isset( $args['customer_receipt'] ) ? $args['customer_receipt'] : $this->customer_receipt;
		$args['ipaddress']        = isset( $args['ipaddress'] ) ? $args['ipaddress'] : WC_Geolocation::get_ip_address();

		if ( isset( $args['transactionid'] ) && empty( $args['transactionid'] ) ) {
			unset( $args['transactionid'] );
		}

		if ( isset( $args['currency'] ) && empty( $args['currency'] ) ) {
			$args['currency'] = get_woocommerce_currency();
		}

		if ( ! empty( $args['country'] ) && empty( $args['state'] ) && ! in_array( $args['type'], array( 'capture', 'void', 'refund' ), true ) ) {
			$args['state'] = 'NA';
		}

		$args = array_merge( $args, $auth_params );

		add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ), 9999 );
		$response = wp_remote_post( $request_url, array( 'body' => $args, 'headers' => array() ) );
		$result   = is_wp_error( $response ) ? $response : wp_remote_retrieve_body( $response );

		if ( $gateway_debug ) {
			$safe_args = $args;
			if ( isset( $safe_args['security_key'] ) ) {
				$safe_args['security_key'] = '***redacted***';
			}
			if ( isset( $safe_args['password'] ) ) {
				$safe_args['password'] = '***redacted***';
			}
			if ( isset( $safe_args['payment_token'] ) ) {
				$safe_args['payment_token'] = '***redacted-wallet-token***';
			}
			$message = sprintf( "\nPosting to: \n%s\nRequest: \n%sResponse: \n%s", $request_url, print_r( $safe_args, true ), print_r( $result, true ) );
			$this->log( $message );
		}

		remove_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ), 9999 );

		if ( is_wp_error( $result ) ) {
			return $result;
		} elseif ( empty( $result ) ) {
			$error_message = __( 'There was an error with the gateway response.', 'wc-nmi3' );
			return new WP_Error( 'invalid_response', apply_filters( 'woocommerce_nmi3_google_pay_error_message', $error_message, $result ) );
		}

		parse_str( $result, $result );

		if ( count( $result ) < 8 ) {
			$error_message = sprintf( __( 'Unrecognized response from the gateway: %s', 'wc-nmi3' ), is_string( $response ) ? $response : print_r( $response, true ) );
			return new WP_Error( 'invalid_response', apply_filters( 'woocommerce_nmi3_google_pay_error_message', $error_message, $result ) );
		}

		if ( ! isset( $result['response'] ) || ! in_array( (int) $result['response'], array( 1, 2, 3 ), true ) ) {
			$error_message = __( 'There was an error with the gateway response.', 'wc-nmi3' );
			return new WP_Error( 'invalid_response', apply_filters( 'woocommerce_nmi3_google_pay_error_message', $error_message, $result ) );
		}

		if ( (int) $result['response'] === 2 ) {
			$error_message = '<!-- Error: ' . esc_html( $result['response_code'] ) . ' --> ' . __( 'Your Google Pay payment was declined.', 'wc-nmi3' );
			return new WP_Error( 'decline_response', apply_filters( 'woocommerce_nmi3_google_pay_error_message', $error_message, $result ), $result );
		}

		if ( (int) $result['response'] === 3 ) {
			$error_message = '<!-- Error: ' . esc_html( $result['response_code'] ) . ' --> ' . $result['responsetext'];
			return new WP_Error( 'error_response', apply_filters( 'woocommerce_nmi3_google_pay_error_message', $error_message, $result ), $result );
		}

		return $result;
	}

	/**
	 * Refund support.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $order->get_transaction_id() || $amount <= 0 ) {
			return false;
		}

		$this->log( "Info: Beginning Google Pay refund for order $order_id for the amount of {$amount}" );

		$args = array(
			'amount'            => $amount,
			'transactionid'     => $order->get_transaction_id(),
			'email'             => $order->get_billing_email(),
			'type'              => 'refund',
			'order_description' => $reason,
			'currency'          => $this->get_payment_currency( $order_id ),
		);

		$args     = apply_filters( 'wc_nmi3_google_pay_refund_request_args', $args, $order );
		$response = $this->nmi_request( $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Gateway Error: ' . $response->get_error_message() );
			return $response;
		} elseif ( ! empty( $response['transactionid'] ) ) {
			$refund_message = sprintf( __( 'Refunded %s - Google Pay Refund ID: %s - Reason: %s', 'wc-nmi3' ), wc_price( $args['amount'], array( 'currency' => $args['currency'] ) ), $response['transactionid'], $reason );
			$order->add_order_note( $refund_message );
			$order->save();

			$this->log( 'Success: ' . wp_strip_all_tags( $refund_message ) );
			return true;
		}
	}

	public function http_request_timeout( $timeout_value ) {
		return 45;
	}

	public function get_line_items( $order ) {
		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			$line_items[] = $item->get_name() . ' x ' . $item->get_quantity();
		}
		return implode( ', ', $line_items );
	}

	public function get_payment_currency( $order_id = false ) {
		$currency = get_woocommerce_currency();
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$currency = $order->get_currency();
			}
		}
		return $currency;
	}

	public function log( $message ) {
		if ( $this->logging ) {
			if ( class_exists( 'WC_NMI3_Logger' ) ) {
				WC_NMI3_Logger::log( '[Google Pay] ' . $message );
			} else {
				wc_get_logger()->debug( '[Google Pay] ' . $message, array( 'source' => 'wc-nmi3-google-pay' ) );
			}
		}
	}
}
