<?php
/**
 * Omnisend Checkout Block Extend Woo Core
 *
 * @package OmnisendPlugin
 */

use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

class Omnisend_Checkout_Block_Extend_Woo_Core {

	/**
	 * Bootstraps the class and hooks required data.
	 */
	public static function init() {
		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			function ( \WC_Order $order, \WP_REST_Request $request ) {
				self::update_contact_status( $order, $request );
			},
			10,
			2
		);
	}

	private static function update_contact_status( \WC_Order $order, \WP_REST_Request $request ) {
		$sms_consent   = $request['extensions'][ OMNISEND_CHECKOUT_PLUGIN_NAME ]['optin-sms'];
		$email_consent = $request['extensions'][ OMNISEND_CHECKOUT_PLUGIN_NAME ]['optin'];

		if ( ! $sms_consent && ! $email_consent ) {
			return; // consent was not provided.
		}

		if ( $sms_consent ) {
			$order->add_meta_data( 'marketing_sms_opt_in_consent', 'checkout', true );
		}

		if ( $email_consent ) {
			$order->add_meta_data( 'marketing_opt_in_consent', 'checkout', true );
		}

		$tags = array( 'source: woocommerce' );
		$tag  = Omnisend_Settings::get_contact_tag_value();

		if ( $tag ) {
			$tags[] = $tag;
		}

		$order->add_meta_data( 'omnisend_contact_tags', $tags, true );

		$order->save();
	}
}
