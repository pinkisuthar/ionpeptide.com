<?php
/*
Plugin Name: NMI Gateway 2 for WooCommerce
Plugin URI: https://example.com
Description: A second instance PCI compliant payment gateway for NMI. An NMI account and a server with cURL, SSL support, and a valid SSL certificate is required (for security reasons) for this gateway to function. Requires WC 3.3+
Version: 1.0.0
Author: Custom
Author URI: https://example.com
Text Domain: wc-nmi2
Domain Path: /languages
WC requires at least: 3.3
WC tested up to: 10.3
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Requires Plugins: woocommerce
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_NMI2_VERSION', '1.3.0' );
define( 'WC_NMI2_TEMPLATE_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) . '/templates/' );
define( 'WC_NMI2_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'WC_NMI2_PLUGIN_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );
define( 'WC_NMI2_MAIN_FILE', __FILE__ );

/**
 * Main NMI2 class which sets the gateway up for us
 */
class WC_NMI2 {

	/**
     * @var WC_NMI2 Singleton The reference the *Singleton* instance of this class
     */
    private static $instance;

    /**
     * Returns the *Singleton* instance of this class.
     *
     * @return WC_NMI2 Singleton The *Singleton* instance.
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Notices (array)
     * @var array
	 */
    public $notices = array();

	/**
	 * Constructor
	 */
	public function __construct() {

		add_action( 'before_woocommerce_init', function() {
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
			}
		} );

		add_action( 'admin_init', array( $this, 'check_environment' ), 11 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ), 15 );
		add_action( 'plugins_loaded', array( $this, 'init_environment' ), 11 );
	}

	public function settings_url() {
        return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nmi2' );
    }

	/**
	 * Add relevant links to plugins page
	 * @param  array $links
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nmi2' ) . '">' . __( 'Settings', 'wc-nmi2' ) . '</a>',
		);
		return array_merge( $plugin_links, $links );
	}

	/**
     * Initialize the gateway. Called very early - in the context of the plugins_loaded action
     *
     * @since 1.0.0
     */
    public function init_gateways() {

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            return;
        }

        // Includes
		if ( is_admin() ) {
			require_once( dirname( __FILE__ ) . '/includes/class-wc-nmi2-privacy.php' );
		}

        include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-nmi2.php' );

        add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );

    }

	/**
	 * Register the gateway for use
	 */
	public function add_gateways( $methods ) {
		$methods[] = 'WC_Gateway_NMI2';
		return $methods;
	}

	/**
	 * Init localisations and files
	 */
	public function init_environment() {

        // Init the gateway itself
        $this->init_gateways();

        require_once( 'includes/class-wc-gateway-nmi2-logger.php' );

        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ), 11 );
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

		if( version_compare( WC_VERSION, '8.4.0', '<' ) ) {
			add_action( 'woocommerce_order_status_processing', array( $this, 'capture_payment' ), 10, 2 );
			add_action( 'woocommerce_order_status_completed', array( $this, 'capture_payment' ), 10, 2 );
		} else {
			add_action( 'woocommerce_order_status_processing', array( $this, 'capture_payment' ), 10, 3 );
			add_action( 'woocommerce_order_status_completed', array( $this, 'capture_payment' ), 10, 3 );
		}

		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_payment' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'cancel_payment' ) );

	}

	/**
     * Allow this class and other classes to add slug keyed notices (to avoid duplication)
     */
    public function add_admin_notice( $slug, $class, $message ) {
        $this->notices[ $slug ] = array(
            'class'   => $class,
            'message' => $message,
        );
    }

	/**
     * The backup sanity check, in case the plugin is activated in a weird way,
     * or the environment changes after activation. Also handles upgrade routines.
     */
    public function check_environment() {
        $environment_warning = self::get_environment_warning();

        if ( $environment_warning && is_plugin_active( plugin_basename( __FILE__ ) ) ) {
            $this->add_admin_notice( 'bad_environment', 'error', $environment_warning );
            return;
        }

        if ( ! defined( 'IFRAME_REQUEST' ) && ( WC_NMI2_VERSION !== get_option( 'wc_nmi2_version', '1.0.0' ) ) ) {
            $this->install();

            do_action( 'woocommerce_nmi2_updated' );
        }

        $gateway = new WC_Gateway_NMI2();

		$setting_prompt = ( ! $gateway->api_keys && ! $gateway->username ) || ( $gateway->api_keys && ! $gateway->private_key );
        if ( $setting_prompt && ! ( isset( $_GET['page'], $_GET['section'] ) && 'wc-settings' === $_GET['page'] && 'nmi2' === $_GET['section'] ) ) {
            $setting_link = esc_url( $this->settings_url() );
            $this->add_admin_notice( 'prompt_connect', 'notice notice-warning', sprintf( __( 'NMI Gateway 2 is almost ready. To get started, <a href="%s">set your NMI account keys</a>.', 'wc-nmi2' ), $setting_link ) );
        }
    }

	/**
     * Updates the plugin version in db
     *
     * @return bool
     */
    private static function _update_plugin_version() {
        delete_option( 'wc_nmi2_version' );
        update_option( 'wc_nmi2_version', WC_NMI2_VERSION );

        return true;
    }

	/**
     * Handles upgrade routines.
     */
    public function install() {
        if ( ! defined( 'WC_NMI2_INSTALLING' ) ) {
            define( 'WC_NMI2_INSTALLING', true );
        }
        $this->_update_plugin_version();
    }

    /**
     * Checks the environment for compatibility problems.
     */
    static function get_environment_warning() {

        if ( ! defined( 'WC_VERSION' ) ) {
            return __( 'WooCommerce NMI Gateway 2 requires WooCommerce to be activated to work.', 'wc-nmi2' );
        }

        if ( ! function_exists( 'curl_init' ) ) {
            return __( 'WooCommerce NMI Gateway 2 - cURL is not installed.', 'wc-nmi2' );
        }

        return false;
    }

    /**
	 * Display any notices we've collected thus far
	 */
	public function admin_notices() {

		foreach ( (array) $this->notices as $notice ) {
			echo "<div class='" . esc_attr( $notice['class'] ) . "'><p>";
			echo wp_kses( $notice['message'], array( 'a' => array( 'href' => array() ) ) );
			echo '</p></div>';
		}
	}

	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'wc-nmi2', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Capture payment when the order is changed from on-hold to complete or processing
	 *
	 * @param int $order_id
	 * @param $order
	 * @param $status_transition
	 */
	public function capture_payment( $order_id, $order, $status_transition = array() ) {

		if ( $order->get_payment_method() == 'nmi2' ) {
			$charge   = $order->get_meta( '_nmi2_charge_id' );
			$captured = $order->get_meta( '_nmi2_charge_captured' );

			$gateway = new WC_Gateway_NMI2();

			if ( apply_filters( 'wc_nmi2_capture_on_status_change', $gateway->capture_on_status_change, $order, $status_transition ) && $charge && $captured == 'no' ) {

				$gateway->log( "Info: Beginning capture payment for order $order_id for the amount of {$order->get_total()}" );

				$order_total = $order->get_total();

				if ( 0 < $order->get_total_refunded() ) {
					$order_total = $order_total - $order->get_total_refunded();
				}

				$args = array(
					'amount'		=> $order_total,
					'transactionid'	=> $order->get_transaction_id(),
					'type' 			=> 'capture',
					'email' 		=> $order->get_billing_email(),
					'currency'		=> $gateway->get_payment_currency( $order_id ),
				);
				$args = apply_filters( 'wc_nmi2_request_args', $args, $order );

				$response = $gateway->nmi_request( $args );

				if ( is_wp_error( $response ) ) {
					if( $order->get_meta( '_nmi2_capture_failed' ) == 'yes' ) {
						$order->add_order_note( sprintf( __( "<strong>Unable to capture charge!</strong> Please <strong>DO NOT FULFIL THE ORDER</strong> if the amount cannot be captured in the gateway account manually or by changing the status. In that case, set status to Failed manually and do not fulfil. \n\nNMI failure reason: %s \n\n", 'wc-nmi2' ), $response->get_error_message() ) );
					} else {
						$order->update_status( 'failed', sprintf( __( "<strong>Unable to capture charge!</strong> The order status is set to <strong>Failed</strong> the first time to draw your attention. If the next attempt fails, your intended order status will still take place. \n\nPlease double-check that the amount is captured in the gateway account before fulfilling the order. \n\nNMI failure reason: %s \n\n", 'wc-nmi2' ), $response->get_error_message() ) );
						$order->update_meta_data( '_nmi2_capture_failed', 'yes' );
						$order->save();
					}
				} else {
					$complete_message = sprintf( __( 'NMI charge captured (Charge ID: %s).', 'wc-nmi2' ), $response['transactionid'] );
 					$order->add_order_note( $complete_message );
					$gateway->log( "Success: $complete_message" );

					$order->update_meta_data( '_nmi2_charge_captured', 'yes' );
					$order->update_meta_data( 'NMI2 Payment ID', $response['transactionid'] );

					$order->set_transaction_id( $response['transactionid'] );
					$order->save();
				}

			}
		}
	}

	/**
	 * Cancel pre-auth on refund/cancellation
	 *
	 * @param  int $order_id
	 */
	public function cancel_payment( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( $order->get_payment_method() == 'nmi2' ) {
			$charge	  = $order->get_meta( '_nmi2_charge_id' ) ? $order->get_meta( '_nmi2_charge_id' ) : $order->get_transaction_id();
			$captured = $order->get_meta( '_nmi2_charge_captured' );

			if ( $charge && $captured != 'yes' ) {

				$gateway = new WC_Gateway_NMI2();

				$gateway->log( "Info: Beginning cancel payment for order $order_id for the amount of {$order->get_total()}" );

				$args = array(
					'amount'		=> $order->get_total(),
					'transactionid'	=> $order->get_transaction_id(),
					'type' 			=> 'void',
					'email' 		=> $order->get_billing_email(),
					'currency'		=> $gateway->get_payment_currency( $order_id ),
				);
				$args = apply_filters( 'wc_nmi2_request_args', $args, $order );

				$response = $gateway->nmi_request( $args );

				if ( is_wp_error( $response ) ) {
					$order->add_order_note( __( 'Unable to refund charge!', 'wc-nmi2' ) . ' ' . $response->get_error_message() );
				} else {
					$cancel_message = sprintf( __( 'NMI charge refunded (Charge ID: %s).', 'wc-nmi2' ), $response['transactionid'] );
 					$order->add_order_note( $cancel_message );
					$gateway->log( "Success: $cancel_message" );

					$order->delete_meta_data( '_nmi2_charge_captured' );
					$order->delete_meta_data( '_nmi2_charge_id' );
					$order->save();
				}
			}

		}
	}

}
$GLOBALS['wc_nmi2'] = WC_NMI2::get_instance();

// Hook in Blocks integration.
add_action( 'woocommerce_blocks_loaded', 'woocommerce_gateway_nmi2_woocommerce_block_support' );

function woocommerce_gateway_nmi2_woocommerce_block_support() {
	if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once dirname( __FILE__ ) . '/includes/class-wc-nmi2-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {

				$container = Automattic\WooCommerce\Blocks\Package::container();
				$container->register(
					WC_NMI2_Blocks_Support::class,
					function() {
						return new WC_NMI2_Blocks_Support();
					}
				);
				$payment_method_registry->register(
					$container->get( WC_NMI2_Blocks_Support::class )
				);
			},
			5
		);
	}
}
