<?php

namespace FKCart\Includes;

use FKCart\Includes\Traits\Instance;

#[\AllowDynamicProperties]
class Cart {
	use Instance;

	private function __construct() {
		add_action( 'woocommerce_checkout_create_order', [ $this, 'update_reward_data_in_order' ] );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'woocommerce_create_order_line_item' ], 999999, 3 );
		add_action( 'woocommerce_thankyou', array( $this, 'insert_stats' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_insert_pending_data' ), 10, 4 );

		/** Unhook FK Cart Pro v3.10.2 (older FB) action */
		add_action( 'bwf_normalize_contact_meta_after_save', [ $this, 'remove_action_upsell_insert_stats' ], 5 );
		
	}

	/**
	 * @param $item \WC_Order_Item
	 * @param $cart_item_key
	 * @param $values
	 */
	public function woocommerce_create_order_line_item( $item, $cart_item_key, $values ) {
		if ( isset( $values['_fkcart_spl_addon'] ) ) {
			$item->add_meta_data( '_fkcart_spl_addon', 'yes' );
		}
	}

	public function update_reward_data_in_order( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$addon_views = $this->get_addons_views();
		if ( ! empty( $addon_views ) ) {
			$order->add_meta_data( '_fkcart_addon_views', wp_json_encode( array_map( 'strval', (array) $addon_views ) ) );
		}
	}

	/**
	 * Insert cart data into reporting table
	 *
	 * @param $order_id
	 *
	 * @return false|void
	 */
	public function insert_stats( $order_id ) {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || $this->is_order_renewal( $order ) ) {
				return;
			}

			/**
			 * Prevent duplicate insertion for ipn gateway and handel by order status changed
			 * or data not insert if order status not paid
			 */
			$payment_method = $order->get_payment_method();
			if ( in_array( $payment_method, $this->get_ipn_gateways(), true ) || ! in_array( $order->get_status(), wc_get_is_paid_statuses(), true ) ) {
				$order->update_meta_data( '_fkcart_need_normalize', 'yes' );
				$order->save_meta_data();

				return false;
			}

			$this->insert_data( $order );
		} catch ( \Exception|\Error $e ) {
		}
	}

	/**
	 *  Insert tracking data on change order status which is not
	 *  insert on thankyou hook due to order status not paid
	 *
	 * @param $order_id
	 * @param $from
	 * @param $to
	 * @param $order
	 *
	 * @return false|void
	 */
	public function maybe_insert_pending_data( $order_id, $from, $to, $order ) {

		if ( in_array( $from, wc_get_is_paid_statuses(), true ) ) {
			return false;
		}

		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$payment_method = $order->get_payment_method();

		/**
		 * If this is a renewal order then delete the meta if exists and return straight away
		 */
		if ( $this->is_order_renewal( $order ) ) {
			return false;
		}


		$ipn_gateways = $this->get_ipn_gateways();

		/**
		 * condition1 : if one of IPN gateways
		 * condition2: Thankyou page hook with pending status ran on this order
		 * condition3: In case thankyou page not open and order mark complete by IPN
		 */
		if ( in_array( $payment_method, $ipn_gateways, true ) || 'yes' === $order->get_meta( '_fkcart_need_normalize' ) || ( class_exists( 'WC_Geolocation' ) && ( $order->get_customer_ip_address() !== \WC_Geolocation::get_ip_address() ) ) ) {
			/**
			 * reaching this code means, 1) we have a ipn gateway OR 2) we have meta stored during thankyou
			 */
			if ( $order_id > 0 && in_array( $to, wc_get_is_paid_statuses(), true ) ) {
				$this->insert_data( $order );

			}
		}

	}

	public function insert_data( $order ) {
		try {
			$meta_keys = [
				'_fkcart_upsell_views',
				'_fkcart_free_gift_views',
				'_fkcart_free_shipping_methods',
				'_fkcart_discount_code_views',
				'_fkcart_addon_views'
			];

			$meta_values = array_combine( $meta_keys, array_map( [ $order, 'get_meta' ], $meta_keys ) );

			// Process free shipping
			$free_shipping_views = $meta_values['_fkcart_free_shipping_methods'];
			if ( ! empty( $free_shipping_views ) ) {
				$shipping_methods    = $order->get_shipping_methods();
				$free_shipping_views = array_filter( $shipping_methods, function ( $method ) use ( $free_shipping_views ) {
					return $free_shipping_views == $method->get_method_id() . ":" . $method->get_instance_id();
				} ) ? $free_shipping_views : '';
			}

			// Process discount views
			$discount_views = $meta_values['_fkcart_discount_code_views'];
			if ( ! empty( $discount_views ) ) {
				$coupons        = array_map( 'strtolower', $order->get_coupon_codes() );
				$discount_views = json_decode( $discount_views, true );
				$discount_views = array_values( array_intersect( array_map( 'strtolower', $discount_views ), $coupons ) );
			}

			if ( ( empty( $meta_values['_fkcart_addon_views'] ) || '[]' === $meta_values['_fkcart_addon_views'] ) && empty( $meta_values['_fkcart_upsell_views'] ) && empty( $free_shipping_views ) && empty( $meta_values['_fkcart_free_gift_views'] ) && empty( $discount_views ) ) {
				return;
			}

			global $wpdb;
			$table_name = $wpdb->prefix . 'fk_cart';
			$data       = [
				'oid'              => $order->get_id(),
				'onumber'          => $order->get_order_number(),
				'addon_viewed'     => ! empty( $meta_values['_fkcart_addon_views'] ) ? $meta_values['_fkcart_addon_views'] : '',
				'free_gift_viewed' => ! empty( $meta_values['_fkcart_free_gift_views'] ) ? $meta_values['_fkcart_free_gift_views'] : '',
				'upsells_viewed'   => ! empty( $meta_values['_fkcart_upsell_views'] ) ? $meta_values['_fkcart_upsell_views'] : '',
				'discount'         => is_array( $discount_views ) ? $this->convert_to_string( $discount_views ) : '',
				'free_shipping'    => ! empty( $free_shipping_views ) ? 1 : 0,
				'date_created'     => current_time( 'mysql' )
			];

			$wpdb->insert( $table_name, $data, [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ] );
			if ( 0 === $wpdb->insert_id ) {
				return;
			}

			$products = [];
			$currency = \BWF_WC_Compatibility::get_order_currency( $order );
			foreach ( $order->get_items() as $item ) {
				$product_id   = $item->get_product_id();
				$variation_id = $item->get_variation_id();
				if ( ! empty( $variation_id ) && $variation_id > 0 ) {
					$product_id = $variation_id;
				}

				$product_data = [
					'oid'        => $order->get_id(),
					'product_id' => $product_id,
					'price'      => 0,
					'type'       => 0
				];

				if ( 'yes' === $item->get_meta( '_fkcart_upsell' ) ) {
					$product_data['price'] = \BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $item['total'], $currency );
					$product_data['type']  = 1;
				} elseif ( 'yes' === $item->get_meta( '_fkcart_free_gift' ) ) {
					$product_data['type'] = 2;
				} elseif ( 'yes' === $item->get_meta( '_fkcart_spl_addon' ) ) {
					$product_data['price'] = \BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $item['total'], $currency );
					$product_data['type']  = 3;
				} else {
					continue;
				}

				$products[] = $product_data;
			}

			if ( ! empty( $products ) ) {
				$table_name         = $wpdb->prefix . 'fk_cart_products';
				$columns            = [ 'oid', 'product_id', 'price', 'type' ];
				$placeholders       = array_fill( 0, count( $columns ), '%s' );
				$placeholder_string = '(' . implode( ', ', $placeholders ) . ')';

				$query = "INSERT INTO $table_name (" . implode( ', ', $columns ) . ") VALUES ";
				$query .= implode( ', ', array_fill( 0, count( $products ), $placeholder_string ) );

				$values = [];
				foreach ( $products as $product ) {
					$values = array_merge( $values, array_values( $product ) );
				}

				$wpdb->query( $wpdb->prepare( "$query ", $values ) );
			}

			$this->record_viewed_products_as_conversions( $order, $products, $data['upsells_viewed'] );
		} catch ( \Exception|\Error $e ) {
		}
	}

	/**
	 * Record products that were both viewed and purchased but not added as conversions
	 *
	 * @param \WC_Order $order
	 * @param array $products Already processed products
	 * @param string $upsells_viewed JSON string of viewed products
	 */
	private function record_viewed_products_as_conversions( $order, array $products, string $upsells_viewed ) {
		global $wpdb;
		if ( empty( $upsells_viewed ) ) {
			return;
		}

		try {
			$viewed_products = json_decode( $upsells_viewed, true );
			if ( ! is_array( $viewed_products ) || empty( $viewed_products ) ) {
				return;
			}

			// Get viewed product IDs
			$viewed_product_ids = array_filter( array_map( 'intval', $viewed_products ) );
			if ( empty( $viewed_product_ids ) ) {
				return;
			}

			// Get already processed product IDs
			$processed_product_ids = array_column( $products, 'product_id' );

			// Find products that were viewed but not yet processed
			$products_to_process = array_diff( $viewed_product_ids, $processed_product_ids );
			if ( empty( $products_to_process ) ) {
				return;
			}
			$products_to_process = array_values( $products_to_process );

			// Get order items with parent product IDs
			$order_items = array_reduce( $order->get_items(), function ( $carry, $item ) {
				$product_id           = isset( $item['product_id'] ) ? intval( $item['product_id'] ) : 0;
				$carry[ $product_id ] = [
					'total' => isset( $item['total'] ) ? floatval( $item['total'] ) : 0,
					'item'  => $item
				];

				return $carry;
			}, [] );

			// Find products which are viewed and purchased
			$products_to_insert = array_intersect( $products_to_process, array_keys( $order_items ) );
			if ( empty( $products_to_insert ) ) {
				return;
			}

			$currency   = \BWF_WC_Compatibility::get_order_currency( $order );
			$table_name = $wpdb->prefix . 'fk_cart_products';

			// Prepare bulk insert data
			$values = array_reduce( $products_to_insert, function ( $carry, $product_id ) use ( $order, $order_items, $currency ) {
				$price   = max( 0, $order_items[ $product_id ]['total'] );
				$carry[] = [
					'oid'        => $order->get_id(),
					'product_id' => $product_id,
					'price'      => \BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $price, $currency ),
					'type'       => 1
				];

				return $carry;
			}, [] );

			// Insert all records
			foreach ( $values as $data ) {
				$wpdb->insert( $table_name, $data, [ '%d', '%d', '%f', '%d' ] );

				// Update the corresponding order item meta to mark as upsell
				if ( isset( $order_items[ $data['product_id'] ]['item'] ) ) {
					$item = $order_items[ $data['product_id'] ]['item'];
					$item->add_meta_data( '_fkcart_upsell', 'yes' );
					$item->save();
				}
			}

		} catch ( \Exception|\Error $e ) {
			fkcart_log_error( 'Order #' . $order->get_id() . ' - ' . $e->getMessage() );
		}
	}

	public function convert_to_string( $input_array ) {
		return wp_json_encode( array_map( 'strval', (array) $input_array ) );
	}

	public function update_addon_views( $addon ) {
		if ( is_null( WC()->cart ) || is_null( WC()->session ) ) {
			return;
		}
		WC()->session->set( '_fkcart_addon_views', array( $addon ) );
	}

	/**
	 * return no of upsell view during checkout process.
	 * @return array
	 */
	public function get_addons_views() {
		if ( is_null( WC()->cart ) || is_null( WC()->session ) ) {
			return [];
		}

		return WC()->session->get( '_fkcart_addon_views' );
	}

	public function is_order_renewal( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		$subscription_id = $order->get_meta( '_subscription_renewal' );

		return ! empty( $subscription_id );
	}

	public function get_ipn_gateways() {
		if ( ! class_exists( 'WFACP_Core' ) ) {
			return [];
		}

		return WFACP_Core()->reporting->get_ipn_gateways();
	}

	public function remove_action_upsell_insert_stats() {
		if ( class_exists( '\FKCart\Pro\Upsells' ) ) {
			remove_action( 'bwf_normalize_contact_meta_after_save', [ \FKCart\Pro\Upsells::getInstance(), 'insert_stats' ] );
		}
	}
}
