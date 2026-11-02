<?php

/**
 * Plugin Name: WooCommerce IDEM Payments Gateway (powered by EasyProcess)
 * Plugin URI: https://www.easyprocess.ai
 * Description: Digital Payment made simple and transparent
 * Developer: EasyProcess
 * Developer URI: https://www.easyprocess.ai/
 * Author: EasyProcess
 * Author URI: https://www.easyprocess.ai/
 * Version: 4.9.0
 *
 * Text Domain: woocommerce-gateway-idem
 * Domain Path: /i18n/languages/
 *
 * Requires at least: 6.0
 * Tested up to: 6.8.3
 *
 * Copyright: © 2009-2025 EasyProcess / IDEM Club
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC Idem Payment gateway plugin class.
 *
 * @class WC_Idem_Payments
 */
class WC_Idem_Payments {

	/**
	 * Plugin bootstrapping.
	 */
	public static function init() {

		// Idem Payments gateway class.
		add_action( 'plugins_loaded', array( __CLASS__, 'includes' ), 0 );

		// Make the Idem Payments gateway available to WC.
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );

		// Registers WooCommerce Blocks integration.
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'woocommerce_gateway_idem_woocommerce_block_support' ) );

        // Add a setting link to the plugin block in the admin
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'woocommerce_gateway_idem_plugin_links') );

        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'woocommerce_gateway_idem_add_to_cart_validation'), 10, 2 );

        // Initialize auto-updater on plugins_loaded
        add_action( 'plugins_loaded', array( __CLASS__, 'init_auto_updater' ) );
	}

	/**
	 * Add the Idem Payment gateway to the list of available gateways.
	 *
	 * @param array $gateways Array of payment gateway instances
	 * @return array Modified array of payment gateway instances
	 */
	public static function add_gateway($gateways) {
		$gateways[] = 'WC_Gateway_Idem';
		$gateways[] = 'WC_Gateway_Idem_Crypto';
		return $gateways;
	}

	/**
	 * Plugin includes.
	 */
	public static function includes() {

		// Make the WC_Gateway_Idem class available.
		if ( class_exists( 'WC_Payment_Gateway' ) ) {
			require_once 'includes/class-wc-idem-api-client.php';
			require_once 'includes/class-wc-gateway-idem.php';
			require_once 'includes/class-wc-gateway-idem-crypto.php';
		}
	}

    /**
     * Initialize the auto-updater.
     */
    public static function init_auto_updater() {
        if ( class_exists( 'WC_Payment_Gateway' ) ) {
            require_once 'includes/class-wc-idem-auto-updater.php';
            $auto_updater = new WC_IDEM_Auto_Updater();
            
            // Register the custom cron schedule before initializing the updater logic
            add_filter('cron_schedules', array($auto_updater, 'add_cron_schedule'));

            $auto_updater->init();
        }
    }

	/**
	 * Plugin url.
	 *
	 * @return string
	 */
	public static function plugin_url() {
		return untrailingslashit( plugins_url( '/', __FILE__ ) );
	}

	/**
	 * Plugin url.
	 *
	 * @return string
	 */
	public static function plugin_abspath() {
		return trailingslashit( plugin_dir_path( __FILE__ ) );
	}

	/**
	 * Registers WooCommerce Blocks integration.
	 *
	 */
	public static function woocommerce_gateway_idem_woocommerce_block_support() {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			require_once 'includes/blocks/class-wc-idem-payments-blocks.php';
			require_once 'includes/blocks/class-wc-idem-crypto-payments-blocks.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new WC_Gateway_Idem_Blocks_Support() );
					$payment_method_registry->register( new WC_Gateway_Idem_Crypto_Blocks_Support() );
				}
			);
		}
	}

    public static function woocommerce_gateway_idem_plugin_links( $links ): array
    {
        $settings_url = add_query_arg(
            array(
                'page' => 'wc-settings',
                'tab' => 'checkout',
                'section' => 'idem',
            ),
            admin_url( 'admin.php' )
        );

        $plugin_links = array(
            '<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'woocommerce-gateway-idem' ) . '</a>',
            '<a href="https://easyprocess.ai/">' . __( 'Support', 'woocommerce-gateway-idem' ) . '</a>'
        );

        return array_merge( $plugin_links, $links );
    }

    public static function woocommerce_gateway_idem_add_to_cart_validation( $passed_validation, $product_id)
    {
        $product = wc_get_product( $product_id );
        if (!$product) {
            return $passed_validation;
        }

        $type = $product->get_type();
        if ($type == 'subscription' || $type == 'variable-subscription' || $type == 'subscription_variation') {
            // Fix product meta
            if ( ! $product->get_sold_individually() ) {
                $product->set_sold_individually( true );
                $product->save();
            }
            // Check functionalities
            $trial = $product->get_meta('_subscription_trial_length');
            if (!empty($trial) && $trial > 0) {
                wc_add_notice( __( 'Sorry, the trial period is not currently supported by IDEM on subscription. Please disable it for this product.', 'woocommerce-gateway-idem' ), 'error' );
                return false;
            }

            // Subscription interval support has been added - no longer needs validation

            if (WC()->cart->is_empty())
                return true;

            wc_add_notice( __( 'You cannot add a subscription product to a cart with other items.', 'woocommerce-gateway-idem' ), 'error' );
            return false;
        }

        // Check if the cart contains any subscriptions
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $cart_product = wc_get_product( $cart_item['product_id'] );
            if ( $cart_product && in_array($cart_product->get_type(), ['subscription', 'variable-subscription', 'subscription_variation']) ) {
                wc_add_notice( __( 'You can only have one subscription product in your cart.', 'woocommerce-gateway-idem' ), 'error' );
                return false;
            }
        }

        return $passed_validation;
    }
}

WC_Idem_Payments::init();
