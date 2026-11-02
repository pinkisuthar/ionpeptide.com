<?php
/**
 * Zorem Returns — MCP bridge.
 *
 * Hands this plugin's tools to the shared Zorem MCP connection (the `zorem-mcp`
 * package). The library owns the endpoint, the token, OAuth sign-in, the per-person
 * keys and the audit log; this file owns nothing but the tools and their callbacks.
 *
 * AUTH MODEL (important for the admin / customer split)
 * -----------------------------------------------------
 * Every MCP request runs AS a specific WordPress user — the library calls
 * wp_set_current_user() from the connection token or the per-person key before a
 * callback runs. So there is no separate "customer transport": we simply read
 * get_current_user_id() and current_user_can():
 *
 *   • Customer tools  → scoped to the signed-in user's own orders / requests.
 *   • Admin tools     → gated on manage_woocommerce; refuse otherwise.
 *
 * The merchant connects on the admin "AI Assistant" tab (link acts as the admin).
 * A customer connects on the "Customer AI" tab (per-person key acts as them).
 *
 * TOOLS (12)
 * ----------
 *   Customer : create-return-request, create-exchange-request, list-my-returns,
 *              list-eligible-orders, get-order-items, get-return-reasons,
 *              search-products
 *   Admin    : admin-list-return-requests, admin-list-exchange-requests,
 *              admin-get-return-request, admin-update-return-status,
 *              admin-update-exchange-status
 *
 * @package Zorem_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Exchange_MCP_Bridge {

	/**
	 * Registry slug — also the tool-name prefix (e.g. zorem-returns-create-return-request).
	 * NEVER change once shipped: it is the identifier a client stores.
	 */
	const SLUG = 'zorem-returns';

	/**
	 * Hook the registration. Called unconditionally from the plugin bootstrap; the
	 * licence gate lives inside register() so an expired licence removes THESE tools
	 * without touching the merchant's connection.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'zorem_mcp_loaded', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the tool set with the shared registry.
	 *
	 * @param Zorem_MCP_Registry $registry Shared registry.
	 * @return void
	 */
	public static function register( $registry ) {

		// Register tools INSIDE the licence check — no licence, no tools (the link keeps working).
		if ( ! self::licensed() ) {
			return;
		}

		// Since zorem-mcp 1.26.0 the library derives the tool-title prefix from `label`
		// ("Zorem Returns › …") and no longer takes `prefix` or `scope` — passing them is
		// ignored. Tool NAMES stay `zorem-returns-<key>` (from SLUG), so existing
		// connections are unaffected.
		$registry->add_plugin(
			self::SLUG,
			array(
				'label' => __( 'Zorem Returns', 'zorem-returns' ),
				'tools' => array(

					/* ---------------- Customer tools ---------------- */

					'list-eligible-orders' => array(
						'label'        => __( 'List eligible orders', 'zorem-returns' ),
						'description'  => __( 'Lists the signed-in customer\'s recent orders that are still inside the return window and eligible for a return or exchange.', 'zorem-returns' ),
						'input_schema' => array(
							'type'       => 'object',
							'properties' => array(
								'email'       => array(
									'type'        => 'string',
									'description' => __( 'Customer billing email used on their orders.', 'zorem-returns' ),
								),
								'customer_id' => array(
									'type'        => 'integer',
									'description' => __( 'Admin only: WP user id to look up instead of an email.', 'zorem-returns' ),
								),
								'limit'       => array( 'type' => 'integer', 'description' => __( 'Max eligible orders to return (default 20).', 'zorem-returns' ) ),
							),
						),
						'callback'     => array( __CLASS__, 'list_eligible_orders' ),
					),

					'get-order-items' => array(
						'label'        => __( 'Get order items', 'zorem-returns' ),
						'description'  => __( 'Returns the line items of an order that can still be returned (quantity remaining after prior requests), so a return can be built for it.', 'zorem-returns' ),
						'input_schema' => array(
							'type'       => 'object',
							'properties' => array(
								'order_id' => array( 'type' => 'integer', 'description' => __( 'WooCommerce order id.', 'zorem-returns' ) ),
								'email'    => array( 'type' => 'string', 'description' => __( 'Billing email on the order (required unless calling as a store manager or the order owner).', 'zorem-returns' ) ),
							),
							'required'   => array( 'order_id' ),
						),
						'callback'     => array( __CLASS__, 'get_order_items' ),
					),

					'get-return-reasons' => array(
						'label'        => __( 'Get return reasons', 'zorem-returns' ),
						'description'  => __( 'Lists the configured return reasons and exchange reasons a customer may choose from.', 'zorem-returns' ),
						'input_schema' => array( 'type' => 'object' ),
						'callback'     => array( __CLASS__, 'get_return_reasons' ),
					),

					'search-products' => array(
						'label'        => __( 'Search products', 'zorem-returns' ),
						'description'  => __( 'Searches purchasable, in-stock products by keyword — used to pick a replacement product for a cross-product exchange.', 'zorem-returns' ),
						'input_schema' => array(
							'type'       => 'object',
							'properties' => array(
								'keyword' => array( 'type' => 'string', 'description' => __( 'Search term (min 2 characters).', 'zorem-returns' ) ),
								'limit'   => array( 'type' => 'integer', 'description' => __( 'Max results (default 10, max 25).', 'zorem-returns' ) ),
							),
							'required'   => array( 'keyword' ),
						),
						'callback'     => array( __CLASS__, 'search_products' ),
					),

					'list-my-returns' => array(
						'label'        => __( 'List my returns', 'zorem-returns' ),
						'description'  => __( 'Lists the signed-in customer\'s own return and exchange requests with their current status.', 'zorem-returns' ),
						'input_schema' => array(
							'type'       => 'object',
							'properties' => array(
								'email'       => array( 'type' => 'string', 'description' => __( 'Customer billing email used on their orders.', 'zorem-returns' ) ),
								'customer_id' => array( 'type' => 'integer', 'description' => __( 'Admin only: WP user id to look up instead of an email.', 'zorem-returns' ) ),
								'type'        => array(
									'type'        => 'string',
									'enum'        => array( 'all', 'return', 'exchange' ),
									'description' => __( 'Filter by request type (default all).', 'zorem-returns' ),
								),
							),
						),
						'callback'     => array( __CLASS__, 'list_my_returns' ),
					),

					'create-return-request' => array(
						'label'        => __( 'Create return request', 'zorem-returns' ),
						'writes'       => true,
						'description'  => __( 'Creates a return request for items on an order. Runs the same flow as the customer return form (eligibility, restocking fee, emails).', 'zorem-returns' ),
						'input_schema' => array(
							'type'       => 'object',
							'properties' => array(
								'order_id'       => array( 'type' => 'integer', 'description' => __( 'WooCommerce order id.', 'zorem-returns' ) ),
								'email'          => array( 'type' => 'string', 'description' => __( 'Billing email on the order (required unless calling as a store manager or the order owner).', 'zorem-returns' ) ),
								'items'          => array(
									'type'        => 'array',
									'description' => __( 'Items to return. Get valid item_ids from get-order-items.', 'zorem-returns' ),
									'items'       => array(
										'type'       => 'object',
										'properties' => array(
											'item_id'  => array( 'type' => 'integer', 'description' => __( 'Order line-item id.', 'zorem-returns' ) ),
											'quantity' => array( 'type' => 'integer', 'description' => __( 'Quantity to return.', 'zorem-returns' ) ),
										),
										'required'   => array( 'item_id', 'quantity' ),
									),
								),
								'reason'         => array( 'type' => 'string', 'description' => __( 'Return reason (should match one from get-return-reasons).', 'zorem-returns' ) ),
								'issue'          => array( 'type' => 'string', 'description' => __( 'Free-text description of the issue.', 'zorem-returns' ) ),
								'payment_method' => array(
									'type'        => 'string',
									'enum'        => array( 'customer_account', 'store_credit', 'tera_wallet', 'keep_the_item', 'keep_item_partial' ),
									'description' => __( 'How the refund is resolved (default customer_account).', 'zorem-returns' ),
								),
							),
							'required'   => array( 'order_id', 'items' ),
						),
						'callback'     => array( __CLASS__, 'create_return_request' ),
					),

					'create-exchange-request' => array(
						'label'        => __( 'Create exchange request', 'zorem-returns' ),
						'writes'       => true,
						'description'  => __( 'Creates an exchange request for items on an order (same-variation, or cross-product when a replacement product is supplied).', 'zorem-returns' ),
						'input_schema' => array(
							'type'       => 'object',
							'properties' => array(
								'order_id'         => array( 'type' => 'integer', 'description' => __( 'WooCommerce order id.', 'zorem-returns' ) ),
								'email'            => array( 'type' => 'string', 'description' => __( 'Billing email on the order (required unless calling as a store manager or the order owner).', 'zorem-returns' ) ),
								'items'            => array(
									'type'        => 'array',
									'description' => __( 'Items to exchange. Get valid item_ids from get-order-items.', 'zorem-returns' ),
									'items'       => array(
										'type'       => 'object',
										'properties' => array(
											'item_id'  => array( 'type' => 'integer' ),
											'quantity' => array( 'type' => 'integer' ),
										),
										'required'   => array( 'item_id', 'quantity' ),
									),
								),
								'reason'           => array( 'type' => 'string', 'description' => __( 'Exchange reason.', 'zorem-returns' ) ),
								'issue'            => array( 'type' => 'string' ),
								'new_product_id'   => array( 'type' => 'integer', 'description' => __( 'Cross-product exchange: replacement product id (from search-products).', 'zorem-returns' ) ),
								'new_variation_id' => array( 'type' => 'integer', 'description' => __( 'Cross-product exchange: replacement variation id, if variable.', 'zorem-returns' ) ),
							),
							'required'   => array( 'order_id', 'items' ),
						),
						'callback'     => array( __CLASS__, 'create_exchange_request' ),
					),

					/* ---------------- Admin tools ---------------- */

					'admin-list-return-requests' => array(
						'label'        => __( 'Admin: list return requests', 'zorem-returns' ),
						'description'  => __( 'Lists all return requests across the store, newest first, optionally filtered by status. Requires shop-manager access.', 'zorem-returns' ),
						'input_schema' => array(
							'type'       => 'object',
							'properties' => array(
								'status'   => array( 'type' => 'string', 'description' => __( 'Filter by status slug, e.g. return-requested.', 'zorem-returns' ) ),
								'order_id' => array( 'type' => 'integer', 'description' => __( 'Filter to a single order id.', 'zorem-returns' ) ),
								'limit'    => array( 'type' => 'integer', 'description' => __( 'Max rows (default 25, max 100).', 'zorem-returns' ) ),
								'offset'   => array( 'type' => 'integer', 'description' => __( 'Pagination offset.', 'zorem-returns' ) ),
							),
						),
						'callback'     => array( __CLASS__, 'admin_list_return_requests' ),
					),

					'admin-list-exchange-requests' => array(
						'label'        => __( 'Admin: list exchange requests', 'zorem-returns' ),
						'description'  => __( 'Lists all exchange requests across the store, newest first, optionally filtered by status. Requires shop-manager access.', 'zorem-returns' ),
						'input_schema' => array(
							'type'       => 'object',
							'properties' => array(
								'status'   => array( 'type' => 'string' ),
								'order_id' => array( 'type' => 'integer' ),
								'limit'    => array( 'type' => 'integer' ),
								'offset'   => array( 'type' => 'integer' ),
							),
						),
						'callback'     => array( __CLASS__, 'admin_list_exchange_requests' ),
					),

					'admin-get-return-request' => array(
						'label'        => __( 'Admin: get return request', 'zorem-returns' ),
						'description'  => __( 'Returns the full detail of one return request (items, reason, resolution, status, notes, fee). Requires shop-manager access.', 'zorem-returns' ),
						'input_schema' => array(
							'type'       => 'object',
							'properties' => array(
								'request_id' => array( 'type' => 'integer', 'description' => __( 'Return request id.', 'zorem-returns' ) ),
							),
							'required'   => array( 'request_id' ),
						),
						'callback'     => array( __CLASS__, 'admin_get_return_request' ),
					),

					'admin-update-return-status' => array(
						'label'        => __( 'Admin: update return status', 'zorem-returns' ),
						'writes'       => true,
						'update'       => true,
						'description'  => __( 'Changes a return request status (approve, complete, reject, cancel, delete). Fires the same refunds, restock, coupons, order-status change and emails as the dashboard. Requires shop-manager access.', 'zorem-returns' ),
						'input_schema' => array(
							'type'       => 'object',
							'properties' => array(
								'request_id'            => array( 'type' => 'integer', 'description' => __( 'Return request id.', 'zorem-returns' ) ),
								'status'                => array(
									'type'        => 'string',
									'enum'        => array( 'return-requested', 'return-approved', 'return-completed', 'return-rejected', 'return-cancelled', 'return-delete' ),
									'description' => __( 'Target status.', 'zorem-returns' ),
								),
								'note'                  => array( 'type' => 'string', 'description' => __( 'Admin note (shown to customer on some statuses).', 'zorem-returns' ) ),
								'restocking_fee_amount' => array( 'type' => 'number', 'description' => __( 'Optional restocking-fee override, only applied on return-approved.', 'zorem-returns' ) ),
							),
							'required'   => array( 'request_id', 'status' ),
						),
						'callback'     => array( __CLASS__, 'admin_update_return_status' ),
					),

					'admin-update-exchange-status' => array(
						'label'        => __( 'Admin: update exchange status', 'zorem-returns' ),
						'writes'       => true,
						'update'       => true,
						'description'  => __( 'Changes an exchange request status (approve, complete, reject, cancel, delete). Approving creates the replacement order. Requires shop-manager access.', 'zorem-returns' ),
						'input_schema' => array(
							'type'       => 'object',
							'properties' => array(
								'request_id' => array( 'type' => 'integer', 'description' => __( 'Exchange request id.', 'zorem-returns' ) ),
								'status'     => array(
									'type'        => 'string',
									'enum'        => array( 'exchange-requested', 'exchange-approved', 'exchange-completed', 'exchange-rejected', 'exchange-cancelled', 'exchange-delete' ),
									'description' => __( 'Target status.', 'zorem-returns' ),
								),
								'note'       => array( 'type' => 'string' ),
							),
							'required'   => array( 'request_id', 'status' ),
						),
						'callback'     => array( __CLASS__, 'admin_update_exchange_status' ),
					),
				),
			)
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Guards & helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Is this plugin's licence active? Mirrors the plugin's own gate.
	 *
	 * @return bool
	 */
	protected static function licensed() {
		$plugin = zorem_returns_exchanges();
		return $plugin && isset( $plugin->license ) && (bool) $plugin->license->check_subscription_status();
	}

	/**
	 * Does the current MCP user have store-management rights?
	 *
	 * @return bool
	 */
	protected static function is_admin_user() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Standard "you may not" error for admin-only tools.
	 *
	 * @return WP_Error
	 */
	protected static function deny_admin() {
		return new WP_Error( 'zorem_returns_forbidden', __( 'This tool requires store-manager access on the connected account.', 'zorem-returns' ) );
	}

	/**
	 * Whether the caller may act on a given order.
	 *
	 * This plugin's customer flow is order-number + email (guest-friendly), and the
	 * shared MCP link runs as the merchant — so identity is proven three ways, in order:
	 *   1. the caller is a store manager (merchant link / staff key), OR
	 *   2. a billing email was supplied and matches the order, OR
	 *   3. the signed-in user owns the order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $email Billing email supplied by the caller (optional).
	 * @return bool
	 */
	protected static function verify_order_access( $order, $email = '' ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		if ( self::is_admin_user() ) {
			return true;
		}
		$email = trim( (string) $email );
		if ( '' !== $email && 0 === strcasecmp( $email, (string) $order->get_billing_email() ) ) {
			return true;
		}
		$uid = get_current_user_id();
		return $uid && (int) $order->get_user_id() === (int) $uid;
	}

	/**
	 * Collect the order ids that belong to a customer, identified by email and/or
	 * user id. Used by the "my …" tools, which have no session to lean on over the
	 * merchant link.
	 *
	 * @param string $email       Billing email (optional).
	 * @param int    $customer_id WP user id (optional).
	 * @return int[] Order ids.
	 */
	protected static function order_ids_for_customer( $email, $customer_id = 0 ) {
		$ids   = array();
		$query = array( 'limit' => 200, 'return' => 'ids' );

		if ( $customer_id ) {
			$query['customer_id'] = $customer_id;
			$ids                  = array_merge( $ids, (array) wc_get_orders( $query ) );
			unset( $query['customer_id'] );
		}
		if ( '' !== (string) $email ) {
			// wc_get_orders() matches a billing email through the `customer` arg.
			$query['customer'] = sanitize_email( $email );
			$ids               = array_merge( $ids, (array) wc_get_orders( $query ) );
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Common accessor.
	 *
	 * @return Zorem_Returns_Exchanges
	 */
	protected static function plugin() {
		return zorem_returns_exchanges();
	}

	/**
	 * Shape one return row for output (unserialises items / reason).
	 *
	 * @param object $row Row from return_orders.
	 * @return array
	 */
	protected static function shape_return( $row ) {
		$items  = maybe_unserialize( $row->return_items );
		$reason = maybe_unserialize( $row->return_reason );

		$order = wc_get_order( $row->order_id );

		return array(
			'request_id'     => (int) $row->id,
			'type'           => 'return',
			'order_id'       => (int) $row->order_id,
			'order_number'   => $order ? $order->get_order_number() : (string) $row->order_id,
			'status'         => $row->return_status,
			'items'          => self::describe_items( is_array( $items ) ? $items : array(), 'return_product_qty' ),
			'reason'         => self::describe_reason( $reason ),
			'issue'          => isset( $row->return_issue ) ? $row->return_issue : '',
			'payment_method' => isset( $row->payment_method ) ? $row->payment_method : '',
			'admin_note'     => isset( $row->return_note ) ? $row->return_note : '',
			'restocking_fee' => isset( $row->restocking_fee_amount ) ? $row->restocking_fee_amount : null,
			'created_at'     => isset( $row->created_at ) ? $row->created_at : null,
		);
	}

	/**
	 * Shape one exchange row for output.
	 *
	 * @param object $row Row from exchange_orders.
	 * @return array
	 */
	protected static function shape_exchange( $row ) {
		$items  = maybe_unserialize( $row->exchange_items );
		$reason = maybe_unserialize( $row->exchange_reason );

		$order = wc_get_order( $row->order_id );

		return array(
			'request_id'       => (int) $row->id,
			'type'             => 'exchange',
			'order_id'         => (int) $row->order_id,
			'order_number'     => $order ? $order->get_order_number() : (string) $row->order_id,
			'status'           => $row->exchange_status,
			'items'            => self::describe_items( is_array( $items ) ? $items : array(), 'exchange_product_qty' ),
			'reason'           => self::describe_reason( $reason ),
			'issue'            => isset( $row->exchange_issue ) ? $row->exchange_issue : '',
			'exchange_type'    => isset( $row->exchange_type ) ? $row->exchange_type : 'same-variation',
			'new_product_id'   => isset( $row->new_product_id ) ? (int) $row->new_product_id : 0,
			'new_variation_id' => isset( $row->new_variation_id ) ? (int) $row->new_variation_id : 0,
			'price_difference' => isset( $row->price_difference ) ? $row->price_difference : null,
			'admin_note'       => isset( $row->exchange_note ) ? $row->exchange_note : '',
			'created_at'       => isset( $row->created_at ) ? $row->created_at : null,
		);
	}

	/**
	 * Turn a serialized items map into a readable list.
	 *
	 * @param array  $items   item_id => [ qty_key => qty, ... ].
	 * @param string $qty_key Which key holds the quantity.
	 * @return array
	 */
	protected static function describe_items( $items, $qty_key ) {
		$out = array();
		foreach ( $items as $item_id => $data ) {
			$qty   = is_array( $data ) && isset( $data[ $qty_key ] ) ? (int) $data[ $qty_key ] : 0;
			$out[] = array(
				'item_id'  => (int) $item_id,
				'name'     => self::line_item_name( (int) $item_id ),
				'quantity' => $qty,
			);
		}
		return $out;
	}

	/**
	 * Best-effort readable name for an order line item id.
	 *
	 * @param int $item_id Line item id.
	 * @return string
	 */
	protected static function line_item_name( $item_id ) {
		if ( ! $item_id || ! function_exists( 'WC' ) ) {
			return '';
		}
		try {
			$item = new WC_Order_Item_Product( $item_id );
			return $item ? $item->get_name() : '';
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Flatten the stored reason array to a single string.
	 *
	 * @param mixed $reason Reason payload.
	 * @return string
	 */
	protected static function describe_reason( $reason ) {
		if ( is_string( $reason ) ) {
			return $reason;
		}
		if ( is_array( $reason ) ) {
			$primary = isset( $reason['no_other'] ) ? (string) $reason['no_other'] : '';
			$other   = isset( $reason['other_reason'] ) ? (string) $reason['other_reason'] : '';
			if ( 'other_reason' === $primary && '' !== $other ) {
				return $other;
			}
			return '' !== $primary ? $primary : $other;
		}
		return '';
	}

	/*
	|--------------------------------------------------------------------------
	| Customer tools
	|--------------------------------------------------------------------------
	*/

	/**
	 * List orders that are still eligible for a return.
	 *
	 * @param array $args Tool args.
	 * @return array|WP_Error
	 */
	public static function list_eligible_orders( $args ) {
		$plugin = self::plugin();

		$email       = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
		$customer_id = 0;

		// Admins may target a specific customer; everyone else must prove an email
		// (or be a signed-in customer, handled by the fallback below).
		if ( self::is_admin_user() && ! empty( $args['customer_id'] ) ) {
			$customer_id = absint( $args['customer_id'] );
		}

		$order_ids = self::order_ids_for_customer( $email, $customer_id );

		// Fall back to the signed-in user when nothing else identifies the customer.
		if ( empty( $order_ids ) && ! $customer_id && '' === $email && get_current_user_id() ) {
			$order_ids = self::order_ids_for_customer( '', get_current_user_id() );
		}

		if ( empty( $order_ids ) ) {
			return new WP_Error( 'zorem_returns_no_customer', __( 'Provide the customer\'s email (or a customer_id as admin) to list eligible orders.', 'zorem-returns' ) );
		}

		$limit = isset( $args['limit'] ) ? max( 1, min( 100, absint( $args['limit'] ) ) ) : 20;
		$out   = array();

		foreach ( $order_ids as $order_id ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( ! $plugin->common->is_order_valid_for_return( $order ) ) {
				continue;
			}
			$returnable = $plugin->common->get_returned_form_data( $order );
			if ( empty( $returnable ) ) {
				continue; // Everything already returned/exchanged.
			}
			$out[] = array(
				'order_id'         => $order->get_id(),
				'order_number'     => $order->get_order_number(),
				'date_created'     => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
				'status'           => $order->get_status(),
				'total'            => $order->get_total(),
				'returnable_items' => count( $returnable ),
			);
		}

		return array(
			'count'  => count( $out ),
			'orders' => $out,
		);
	}

	/**
	 * Get the returnable line items of an order.
	 *
	 * @param array $args Tool args.
	 * @return array|WP_Error
	 */
	public static function get_order_items( $args ) {
		$order_id = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		$email    = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';

		if ( ! $order ) {
			return new WP_Error( 'zorem_returns_no_order', __( 'Order not found.', 'zorem-returns' ) );
		}
		if ( ! self::verify_order_access( $order, $email ) ) {
			return new WP_Error( 'zorem_returns_forbidden', __( 'Order/email do not match. Provide the billing email used on the order.', 'zorem-returns' ) );
		}

		$plugin = self::plugin();
		$items  = $plugin->common->get_returned_form_data( $order );

		$out = array();
		foreach ( (array) $items as $item_id => $data ) {
			$out[] = array(
				'item_id'            => (int) $item_id,
				'name'               => isset( $data['product_name'] ) ? $data['product_name'] : self::line_item_name( (int) $item_id ),
				'product_id'         => isset( $data['product_id'] ) ? (int) $data['product_id'] : 0,
				'returnable_quantity' => isset( $data['product_quantity'] ) ? (int) $data['product_quantity'] : 0,
			);
		}

		return array(
			'order_id'          => $order->get_id(),
			'order_number'      => $order->get_order_number(),
			'eligible'          => (bool) $plugin->common->is_order_valid_for_return( $order ),
			'items'             => $out,
		);
	}

	/**
	 * Configured return and exchange reasons.
	 *
	 * @param array $args Tool args.
	 * @return array
	 */
	public static function get_return_reasons( $args ) {
		$common           = self::plugin()->common;
		$return_reasons   = $common->return_settings( 'return_reasons', array() );
		$exchange_reasons = $common->return_settings( 'exchange_reasons', array() );

		return array(
			'return_reasons'   => array_values( (array) $return_reasons ),
			'exchange_reasons' => array_values( (array) $exchange_reasons ),
		);
	}

	/**
	 * Product search for cross-product exchanges.
	 *
	 * @param array $args Tool args.
	 * @return array|WP_Error
	 */
	public static function search_products( $args ) {
		$keyword = isset( $args['keyword'] ) ? sanitize_text_field( $args['keyword'] ) : '';
		if ( strlen( $keyword ) < 2 ) {
			return new WP_Error( 'zorem_returns_short_keyword', __( 'Enter at least 2 characters to search.', 'zorem-returns' ) );
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 25, absint( $args['limit'] ) ) ) : 10;

		$products = wc_get_products(
			array(
				's'       => $keyword,
				'status'  => 'publish',
				'limit'   => $limit,
				'orderby' => 'relevance',
			)
		);

		$out = array();
		foreach ( (array) $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			if ( ! $product->is_purchasable() ) {
				continue;
			}
			$out[] = array(
				'id'          => $product->get_id(),
				'name'        => $product->get_name(),
				'sku'         => $product->get_sku(),
				'price'       => $product->get_price(),
				'price_html'  => wp_strip_all_tags( $product->get_price_html() ),
				'type'        => $product->get_type(),
				'is_variable' => $product->is_type( 'variable' ),
				'in_stock'    => $product->is_in_stock(),
				'permalink'   => get_permalink( $product->get_id() ),
			);
		}

		return array(
			'keyword' => $keyword,
			'count'   => count( $out ),
			'products' => $out,
		);
	}

	/**
	 * The signed-in customer's own return + exchange requests.
	 *
	 * @param array $args Tool args.
	 * @return array|WP_Error
	 */
	public static function list_my_returns( $args ) {
		$email       = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
		$customer_id = self::is_admin_user() && ! empty( $args['customer_id'] ) ? absint( $args['customer_id'] ) : 0;

		// Identify the customer: email / customer_id, or fall back to the signed-in user.
		$order_ids = self::order_ids_for_customer( $email, $customer_id );
		if ( empty( $order_ids ) && '' === $email && ! $customer_id && get_current_user_id() ) {
			$order_ids = self::order_ids_for_customer( '', get_current_user_id() );
		}

		if ( empty( $order_ids ) ) {
			return new WP_Error( 'zorem_returns_no_customer', __( 'Provide the customer\'s email (or a customer_id as admin) to list their requests.', 'zorem-returns' ) );
		}

		$type = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : 'all';

		global $wpdb;
		$out          = array();
		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

		if ( 'all' === $type || 'return' === $type ) {
			// order_id is stored as text; compare loosely against the integer id list.
			$sql  = "SELECT * FROM {$wpdb->prefix}return_orders WHERE order_id IN ({$placeholders}) ORDER BY id DESC LIMIT 200";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built only from %d placeholders and $wpdb->prefix; every value is bound through prepare().
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $order_ids ) );
			foreach ( (array) $rows as $row ) {
				$out[] = self::shape_return( $row );
			}
		}

		if ( 'all' === $type || 'exchange' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off existence check for an optional table.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'exchange_orders' ) );
			if ( $exists ) {
				$sql  = "SELECT * FROM {$wpdb->prefix}exchange_orders WHERE order_id IN ({$placeholders}) ORDER BY id DESC LIMIT 200";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built only from %d placeholders and $wpdb->prefix; every value is bound through prepare().
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $order_ids ) );
				foreach ( (array) $rows as $row ) {
					$out[] = self::shape_exchange( $row );
				}
			}
		}

		return array(
			'count'    => count( $out ),
			'requests' => $out,
		);
	}

	/**
	 * Create a return request via the plugin's own creator (all side effects run).
	 *
	 * @param array $args Tool args.
	 * @return array|WP_Error
	 */
	public static function create_return_request( $args ) {
		$order_id = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		$email    = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';

		if ( ! $order ) {
			return new WP_Error( 'zorem_returns_no_order', __( 'Order not found.', 'zorem-returns' ) );
		}
		if ( ! self::verify_order_access( $order, $email ) ) {
			return new WP_Error( 'zorem_returns_forbidden', __( 'Order/email do not match. Provide the billing email used on the order.', 'zorem-returns' ) );
		}

		$plugin = self::plugin();

		if ( ! $plugin->common->is_order_valid_for_return( $order ) ) {
			return new WP_Error( 'zorem_returns_not_eligible', __( 'This order is not eligible for a return (outside the return window or wrong status).', 'zorem-returns' ) );
		}

		$items = self::build_items_map( $args, 'return_product_id', 'return_product_qty' );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$reason         = isset( $args['reason'] ) ? sanitize_text_field( $args['reason'] ) : '';
		$issue          = isset( $args['issue'] ) ? sanitize_textarea_field( $args['issue'] ) : '';
		$payment_method = isset( $args['payment_method'] ) ? sanitize_key( $args['payment_method'] ) : 'customer_account';

		$post = array(
			'order_id'    => $order_id,
			'return_data' => array(
				'return_items'   => $items,
				'return_reason'  => array( 'no_other' => $reason, 'other_reason' => '' ),
				'return_issue'   => $issue,
				'payment_method' => $payment_method,
			),
		);

		// Always use the customer flow so the request enters the normal queue and honours
		// the store's auto-approve setting, rather than silently auto-approving (and
		// refunding) just because the merchant link runs as an admin. Approve explicitly
		// afterwards with admin-update-return-status.
		$context = 'customer';

		// Empty-but-shaped FILES so the creator's file-loop short-circuits cleanly.
		$files       = array( 'return_attachments' => array( 'name' => array() ) );
		$request_id  = $plugin->center->save_return_requested_data( $post, $files, $context );

		if ( empty( $request_id ) ) {
			return new WP_Error( 'zorem_returns_create_failed', __( 'The return request could not be created. Check the items and quantities.', 'zorem-returns' ) );
		}

		$row = $plugin->common->get_data_by_return_id( $request_id );

		return array(
			'success'    => true,
			'request_id' => (int) $request_id,
			'request'    => $row ? self::shape_return( $row ) : null,
		);
	}

	/**
	 * Create an exchange request via the plugin's own creator.
	 *
	 * @param array $args Tool args.
	 * @return array|WP_Error
	 */
	public static function create_exchange_request( $args ) {
		$order_id = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		$email    = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';

		if ( ! $order ) {
			return new WP_Error( 'zorem_returns_no_order', __( 'Order not found.', 'zorem-returns' ) );
		}
		if ( ! self::verify_order_access( $order, $email ) ) {
			return new WP_Error( 'zorem_returns_forbidden', __( 'Order/email do not match. Provide the billing email used on the order.', 'zorem-returns' ) );
		}

		$plugin = self::plugin();

		if ( ! isset( $plugin->exCenter ) || ! is_object( $plugin->exCenter ) ) {
			return new WP_Error( 'zorem_returns_exchange_off', __( 'Exchanges are not enabled on this store.', 'zorem-returns' ) );
		}

		$items = self::build_items_map( $args, 'exchange_product_id', 'exchange_product_qty' );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$reason = isset( $args['reason'] ) ? sanitize_text_field( $args['reason'] ) : '';
		$issue  = isset( $args['issue'] ) ? sanitize_textarea_field( $args['issue'] ) : '';

		$exchange_data = array(
			'exchange_items'  => $items,
			'exchange_reason' => array( 'no_other' => $reason, 'other_reason' => '' ),
			'exchange_issue'  => $issue,
		);

		// Cross-product exchange when a replacement product is supplied.
		if ( ! empty( $args['new_product_id'] ) ) {
			$exchange_data['exchange_type']    = 'cross-product';
			$exchange_data['new_product_id']   = absint( $args['new_product_id'] );
			$exchange_data['new_variation_id'] = isset( $args['new_variation_id'] ) ? absint( $args['new_variation_id'] ) : 0;
		}

		$post = array(
			'order_id'      => $order_id,
			'exchange_data' => $exchange_data,
		);

		// See note in create_return_request: always use the customer flow.
		$context    = 'customer';
		$files      = array( 'exchange_attachments' => array( 'name' => array() ) );
		$request_id = $plugin->exCenter->save_exchange_requested_data( $post, $files, $context );

		if ( empty( $request_id ) ) {
			return new WP_Error( 'zorem_returns_create_failed', __( 'The exchange request could not be created.', 'zorem-returns' ) );
		}

		$row = $plugin->exCommon->get_data_by_exchange_id( $request_id );

		return array(
			'success'    => true,
			'request_id' => (int) $request_id,
			'request'    => $row ? self::shape_exchange( $row ) : null,
		);
	}

	/**
	 * Build the item_id => [ id_key => item_id, qty_key => qty ] map the creators expect.
	 *
	 * @param array  $args    Tool args (expects `items`).
	 * @param string $id_key  return_product_id / exchange_product_id.
	 * @param string $qty_key return_product_qty / exchange_product_qty.
	 * @return array|WP_Error
	 */
	protected static function build_items_map( $args, $id_key, $qty_key ) {
		$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
		if ( empty( $items ) ) {
			return new WP_Error( 'zorem_returns_no_items', __( 'Provide at least one item to request.', 'zorem-returns' ) );
		}

		$map = array();
		foreach ( $items as $line ) {
			$item_id = isset( $line['item_id'] ) ? absint( $line['item_id'] ) : 0;
			$qty     = isset( $line['quantity'] ) ? absint( $line['quantity'] ) : 0;
			if ( ! $item_id || $qty < 1 ) {
				continue;
			}
			// Non-zero id key marks the line as selected (the creators drop lines whose id is 0).
			$map[ $item_id ] = array(
				$id_key  => $item_id,
				$qty_key => $qty,
			);
		}

		if ( empty( $map ) ) {
			return new WP_Error( 'zorem_returns_no_items', __( 'No valid items — each needs an item_id and a quantity of 1 or more.', 'zorem-returns' ) );
		}

		return $map;
	}

	/*
	|--------------------------------------------------------------------------
	| Admin tools
	|--------------------------------------------------------------------------
	*/

	/**
	 * Admin: list return requests store-wide.
	 *
	 * @param array $args Tool args.
	 * @return array|WP_Error
	 */
	public static function admin_list_return_requests( $args ) {
		if ( ! self::is_admin_user() ) {
			return self::deny_admin();
		}
		return self::admin_list( 'return', $args );
	}

	/**
	 * Admin: list exchange requests store-wide.
	 *
	 * @param array $args Tool args.
	 * @return array|WP_Error
	 */
	public static function admin_list_exchange_requests( $args ) {
		if ( ! self::is_admin_user() ) {
			return self::deny_admin();
		}
		return self::admin_list( 'exchange', $args );
	}

	/**
	 * Shared admin listing for both tables.
	 *
	 * @param string $type return|exchange.
	 * @param array  $args Tool args.
	 * @return array
	 */
	protected static function admin_list( $type, $args ) {
		global $wpdb;

		$is_return   = ( 'return' === $type );
		$table       = $wpdb->prefix . ( $is_return ? 'return_orders' : 'exchange_orders' );
		$status_col  = $is_return ? 'return_status' : 'exchange_status';

		if ( ! $is_return ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off existence check for an optional table.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists ) {
				return array( 'count' => 0, 'total' => 0, 'requests' => array() );
			}
		}

		$limit  = isset( $args['limit'] ) ? max( 1, min( 100, absint( $args['limit'] ) ) ) : 25;
		$offset = isset( $args['offset'] ) ? max( 0, absint( $args['offset'] ) ) : 0;

		$where  = array();
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = "{$status_col} = %s";
			$params[] = sanitize_text_field( $args['status'] );
		}
		if ( ! empty( $args['order_id'] ) ) {
			$where[]  = 'order_id = %s';
			$params[] = (string) absint( $args['order_id'] );
		}

		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		// Total count.
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix and $where_sql is built from %s placeholders only; values are bound through prepare().
		$total     = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );

		// Page of rows.
		$rows_sql  = "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix and $where_sql is built from %s placeholders only; limit/offset are bound absints through prepare().
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $limit, $offset ) ) ) );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = $is_return ? self::shape_return( $row ) : self::shape_exchange( $row );
		}

		return array(
			'count'    => count( $out ),
			'total'    => $total,
			'limit'    => $limit,
			'offset'   => $offset,
			'requests' => $out,
		);
	}

	/**
	 * Admin: full detail of one return request.
	 *
	 * @param array $args Tool args.
	 * @return array|WP_Error
	 */
	public static function admin_get_return_request( $args ) {
		if ( ! self::is_admin_user() ) {
			return self::deny_admin();
		}
		$id  = isset( $args['request_id'] ) ? absint( $args['request_id'] ) : 0;
		$row = $id ? self::plugin()->common->get_data_by_return_id( $id ) : null;

		if ( empty( $row ) ) {
			return new WP_Error( 'zorem_returns_not_found', __( 'Return request not found.', 'zorem-returns' ) );
		}

		return self::shape_return( $row );
	}

	/**
	 * Admin: change a return request's status (full side effects).
	 *
	 * @param array $args Tool args.
	 * @return array|WP_Error
	 */
	public static function admin_update_return_status( $args ) {
		if ( ! self::is_admin_user() ) {
			return self::deny_admin();
		}

		$id     = isset( $args['request_id'] ) ? absint( $args['request_id'] ) : 0;
		$status = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : '';
		$note   = isset( $args['note'] ) ? sanitize_textarea_field( $args['note'] ) : '';
		$fee    = isset( $args['restocking_fee_amount'] ) ? floatval( $args['restocking_fee_amount'] ) : null;

		$dashboard = self::plugin()->fulfillment;
		if ( ! $dashboard || ! method_exists( $dashboard, 'mcp_update_status' ) ) {
			return new WP_Error( 'zorem_returns_unavailable', __( 'The return workflow is not available right now.', 'zorem-returns' ) );
		}

		return $dashboard->mcp_update_status( $id, $status, $note, $fee );
	}

	/**
	 * Admin: change an exchange request's status (full side effects).
	 *
	 * @param array $args Tool args.
	 * @return array|WP_Error
	 */
	public static function admin_update_exchange_status( $args ) {
		if ( ! self::is_admin_user() ) {
			return self::deny_admin();
		}

		$id     = isset( $args['request_id'] ) ? absint( $args['request_id'] ) : 0;
		$status = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : '';
		$note   = isset( $args['note'] ) ? sanitize_textarea_field( $args['note'] ) : '';

		$plugin = self::plugin();
		if ( ! isset( $plugin->exFulfillment ) || ! is_object( $plugin->exFulfillment ) || ! method_exists( $plugin->exFulfillment, 'mcp_update_status' ) ) {
			return new WP_Error( 'zorem_returns_exchange_off', __( 'Exchanges are not enabled on this store.', 'zorem-returns' ) );
		}

		return $plugin->exFulfillment->mcp_update_status( $id, $status, $note );
	}
}
