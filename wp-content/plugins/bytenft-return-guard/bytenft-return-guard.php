<?php
/**
 * Plugin Name:       ByteNFT Return Guard
 * Plugin URI:        https://example.com/bytenft-return-guard
 * Description:        Hardens the ByteNFT Payment Gateway plugin's payment-return endpoint. An order's status is changed only when the request carries that order's key and ByteNFT's own status check confirms the outcome. Add-on only: it does nothing unless the ByteNFT Payment Gateway plugin is installed.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 *
 * WHAT THIS IS
 *
 * A defensive add-on for stores that use the ByteNFT Payment Gateway plugin.
 * On the affected versions, the endpoint a shopper lands on after paying can
 * change an order's payment status without first verifying the order's key or
 * confirming the outcome with ByteNFT. This add-on sits in front of that one
 * return and applies a status only when BOTH of these hold:
 *
 *   1. the request carries the order's own key, and
 *   2. ByteNFT's status endpoint reports that outcome for the payment id
 *      stored on the order.
 *
 * ByteNFT's signed server-to-server notification and its after-payment check
 * are left alone, so genuine payments are still recorded the usual way. The
 * add-on makes no outbound calls on load and touches nothing until a matching
 * return arrives. If a later ByteNFT release secures the endpoint itself, this
 * add-on can be removed. Please also report the underlying issue to ByteNFT so
 * it is fixed at the source for every store.
 *
 * This plugin is independent of ByteNFT and is provided as-is, with no
 * warranty, under the GPL v3.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Declared conditionally so a second copy (for example one also placed in
// mu-plugins) cannot fatal with "Cannot redeclare". The second copy no-ops.
if ( ! class_exists( 'BNFT_Return_Guard' ) ) {

	final class BNFT_Return_Guard {

		const ROUTE        = '/bytenft/v1/data';
		const LOG_SOURCE   = 'bytenft-return-guard';
		const THROTTLE_TTL = 5;
		const HTTP_TIMEOUT = 15; // Matches the gateway's own after-payment lookup.

		/** WooCommerce statuses a confirmed result may move an order out of. */
		const PAYABLE_STATUSES = array( 'pending', 'failed', 'cancelled', 'checkout-draft' );

		/** Answers from ByteNFT's status endpoint that count as a successful payment. */
		const CONFIRMED_SUCCESS = array( 'completed', 'paid', 'success' );

		/** Answers that count as a failed payment. */
		const CONFIRMED_FAILURE = array( 'failed', 'cancelled', 'canceled', 'expired' );

		public static function register() {
			// rest_dispatch_request runs after WordPress has matched the route, so
			// HEAD, ?rest_route= and letter-case variants are all caught. Returning
			// a non-null value replaces the gateway's own callback for this request.
			add_filter( 'rest_dispatch_request', array( __CLASS__, 'dispatch' ), 5, 4 );
		}

		/**
		 * @param mixed           $result  Null unless another filter already answered.
		 * @param WP_REST_Request $request
		 * @param string          $route   Matched route pattern.
		 * @param array           $handler
		 */
		public static function dispatch( $result, $request, $route, $handler ) {
			if ( null !== $result || ! ( $request instanceof WP_REST_Request ) ) {
				return $result;
			}
			if ( 0 !== strcasecmp( untrailingslashit( (string) $route ), self::ROUTE ) ) {
				return $result;
			}
			// The gateway's POST branch is the signed webhook; leave it to the gateway.
			if ( 'POST' === strtoupper( (string) $request->get_method() ) ) {
				return $result;
			}
			return self::handle_return( $request );
		}

		private static function handle_return( WP_REST_Request $request ) {
			// Read parameters the same way the gateway does, so nothing reaches it
			// through a path this guard did not check.
			$params = $request->get_params();
			$data   = isset( $params['api_data'] ) ? $params['api_data'] : $params;
			if ( ! is_array( $data ) ) {
				$data = array();
			}

			$order_id = is_scalar( $data['order_id'] ?? null ) ? intval( $data['order_id'] ) : 0;
			$key      = is_scalar( $data['key'] ?? null ) ? sanitize_text_field( (string) $data['key'] ) : '';
			$asked    = is_scalar( $data['order_status'] ?? null ) ? substr( sanitize_text_field( (string) $data['order_status'] ), 0, 40 ) : '';

			$order = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

			// A missing order or a wrong key gets the same answer, so the endpoint
			// does not reveal which order ids exist and never issues a redirect that
			// would carry the real order key to the caller.
			if (
				! ( $order instanceof WC_Order )
				|| '' === $key
				|| '' === (string) $order->get_order_key()
				|| ! hash_equals( (string) $order->get_order_key(), $key )
			) {
				self::log( $order_id, 'denied', 'no order or key mismatch' );
				return self::no_store(
					new WP_REST_Response(
						array(
							'success' => false,
							'message' => 'We could not verify this payment return.',
						),
						403
					)
				);
			}

			// HEAD (link scanners, prefetchers) never triggers any work.
			if ( 'GET' !== strtoupper( (string) $request->get_method() ) ) {
				return self::no_store( new WP_REST_Response( null, 204 ) );
			}

			// Only ByteNFT orders are handled here.
			if ( 'bytenft' !== $order->get_payment_method() ) {
				self::log( $order_id, 'skipped', 'payment method is ' . $order->get_payment_method() );
				return self::redirect( $order->get_checkout_order_received_url() );
			}

			// Parity with the gateway: let a failed attempt resume the same order.
			if ( function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( 'order_awaiting_payment', $order_id );
			}

			if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
				return self::redirect( $order->get_checkout_order_received_url() );
			}
			// The gateway only acted when a status was present; keep that trigger.
			if ( '' === $asked ) {
				return self::redirect( $order->get_checkout_order_received_url() );
			}
			if ( ! $order->has_status( self::PAYABLE_STATUSES ) ) {
				self::log( $order_id, 'skipped', 'status is ' . $order->get_status() );
				return self::redirect( $order->get_checkout_order_received_url() );
			}

			$pay_id = (string) $order->get_meta( '_bytenft_pay_id' );
			if ( '' === $pay_id || ! class_exists( 'BYTENFT_PAYMENT_ENGINE' ) ) {
				self::log( $order_id, 'skipped', '' === $pay_id ? 'no stored pay id' : 'engine class missing' );
				return self::redirect( $order->get_checkout_order_received_url() );
			}

			$throttle = 'bnft_rg_' . $order_id;
			if ( get_transient( $throttle ) ) {
				self::log( $order_id, 'skipped', 'throttled' );
				return self::redirect( $order->get_checkout_order_received_url() );
			}
			set_transient( $throttle, 1, self::THROTTLE_TTL );

			// Ask ByteNFT for the real outcome. The query-string status is ignored.
			list( $confirmed, $why ) = self::lookup( $order_id, $pay_id );

			if ( in_array( $confirmed, self::CONFIRMED_SUCCESS, true ) || in_array( $confirmed, self::CONFIRMED_FAILURE, true ) ) {
				$engine_result = BYTENFT_PAYMENT_ENGINE::handle_event(
					$order_id,
					'redirect',
					array(
						'status'        => $confirmed,
						'payment_token' => $pay_id,
						'source'        => 'Redirect',
					)
				);
				self::log( $order_id, 'applied', 'ByteNFT confirmed ' . $confirmed . ', engine ' . wp_json_encode( $engine_result ) );
			} else {
				self::log( $order_id, 'not applied', 'ByteNFT did not confirm (' . $why . ')' );
			}

			$order = wc_get_order( $order_id );
			// Only a failure ByteNFT itself just confirmed sends the shopper back to
			// checkout. An unconfirmed answer (still pending, or a lookup timeout)
			// must not tell someone who just paid that payment failed: the webhook
			// or the after-payment check records it moments later.
			if ( $order instanceof WC_Order && in_array( $confirmed, self::CONFIRMED_FAILURE, true ) && $order->has_status( array( 'failed', 'cancelled' ) ) ) {
				if ( function_exists( 'wc_add_notice' ) && function_exists( 'WC' ) && WC()->session ) {
					wc_add_notice( 'Payment was not completed. Please try again.', 'error' );
				}
				return self::redirect( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ) );
			}
			return self::redirect( $order instanceof WC_Order ? $order->get_checkout_order_received_url() : home_url( '/' ) );
		}

		/**
		 * Ask ByteNFT for the stored payment id's status, the same request the
		 * gateway's after-payment handler makes. Returns array( status, reason );
		 * status is '' on any failure, so the caller changes nothing.
		 */
		private static function lookup( $order_id, $pay_id ) {
			$host = defined( 'BYTENFT_HOST' ) ? (string) BYTENFT_HOST : 'pay.bytenft.xyz';

			$response = wp_remote_post(
				'https://' . $host . '/api/update-txn-status',
				array(
					'timeout' => self::HTTP_TIMEOUT,
					'headers' => array(
						'Content-Type'  => 'application/json',
						// The gateway sends a WordPress nonce here, not a credential. Kept identical.
						'Authorization' => 'Bearer ' . wp_create_nonce( 'bytenft_payment' ),
					),
					'body'    => wp_json_encode(
						array(
							'order_id'      => (string) $order_id,
							'payment_token' => $pay_id,
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array( '', 'http error ' . $response->get_error_code() );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code > 299 ) {
				return array( '', 'http ' . $code );
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) ) {
				return array( '', 'unreadable body' );
			}
			$status = $body['payment_status'] ?? $body['transaction_status'] ?? '';
			if ( ! is_string( $status ) || '' === trim( $status ) ) {
				return array( '', 'no status in body' );
			}
			return array( strtolower( trim( $status ) ), 'ok' );
		}

		/**
		 * 302 to an allowed host only, falling back to the site home page.
		 * A headless or split-domain store can allow its storefront host with:
		 *   add_filter( 'bnft_return_guard_allowed_redirect_hosts', fn( $h ) => array_merge( $h, array( 'shop.example.com' ) ) );
		 */
		private static function redirect( $url ) {
			$add_hosts = static function ( $hosts ) {
				$extra = (array) apply_filters( 'bnft_return_guard_allowed_redirect_hosts', array() );
				return array_merge( (array) $hosts, array_filter( array_map( 'strval', $extra ) ) );
			};
			add_filter( 'allowed_redirect_hosts', $add_hosts );
			$safe = wp_validate_redirect( (string) $url, home_url( '/' ) );
			remove_filter( 'allowed_redirect_hosts', $add_hosts );

			$response = new WP_REST_Response( null, 302 );
			$response->header( 'Location', $safe );
			return self::no_store( $response );
		}

		/** Keep every answer out of page caches. */
		private static function no_store( WP_REST_Response $response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			return $response;
		}

		private static function log( $order_id, $decision, $detail ) {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}
			wc_get_logger()->info(
				sprintf( 'Order #%d | %s | %s', (int) $order_id, $decision, $detail ),
				array( 'source' => self::LOG_SOURCE )
			);
		}
	}

	BNFT_Return_Guard::register();
}
