<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.DynamicHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Exchange_Common {

	/**
	 * Human-readable label for a stored `payment_method` value on a return row.
	 *
	 * Used by the admin dashboard preview modal and the WC Edit Order metabox
	 * so the store admin can see which resolution the customer picked. The
	 * `keep_item_partial` branch appends the configured percentage so admins
	 * know the exact partial-refund amount to issue manually.
	 *
	 * @param string $method Stored payment_method key (store_credit / customer_account / tera_wallet / keep_item_partial).
	 * @return string
	 */
	public static function get_return_resolution_label( $method ) {
		switch ( (string) $method ) {
			case 'store_credit':
				return __( 'Refund to Store Credit', 'zorem-returns' );
			case 'customer_account':
				return __( 'Refund to Original Payment Method', 'zorem-returns' );
			case 'tera_wallet':
				return __( 'Refund to Wallet (TeraWallet)', 'zorem-returns' );
			case 'keep_item_partial':
				$pct = absint( zorem_returns_exchanges()->common->return_settings( 'keep_item_partial_refund_percentage', 15 ) );
				/* translators: %d — percentage of item total refunded when the customer keeps the product. */
				return sprintf( __( 'Keep the Item & Receive a %d%% Partial Refund', 'zorem-returns' ), $pct );
		}
		return $method;
	}

	/**
	 * Instance of this class.
	 *
	 * @var object Class Instance
	 */
	private static $instance;

	/**
	 * Initialize the main plugin function
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Get the class instance
	 */
	public static function get_instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/*
	 * init function
	*/
	public function init() {

		add_action( 'init', array( $this, 'add_return_exchange_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_return_exchange_menu_item' ) );
		add_action( 'woocommerce_account_return-exchange_endpoint', array( $this, 'return_exchange_content' ) );
		add_action( 'woocommerce_account_view-exchange_endpoint', array( $this, 'view_exchange_request' ) );
		add_action( 'woocommerce_account_view-return_endpoint', array( $this, 'view_return_request' ) );
		add_action( 'woocommerce_account_content', array( $this, 'remove_multistore_other_sites' ), -9999 );
	}

	public function remove_multistore_other_sites() {
		$endpoints = array( 'return-exchange', 'view-return', 'view-exchange' );

		foreach ( $endpoints as $endpoint ) {

			if ( ! is_wc_endpoint_url( $endpoint ) ) {
				continue;
			}

			$hook_name = 'woocommerce_account_' . $endpoint . '_endpoint';

			global $wp_filter;
			if ( empty( $wp_filter[ $hook_name ] ) || empty( $wp_filter[ $hook_name ]->callbacks ) ) {
				return;
			}

			foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {

					$function = $callback['function'];

					if ( ! is_array( $function ) || ! is_object( $function[0] ) ) {
						continue;
					}

					$class_name  = get_class( $function[0] );
					$method_name = $function[1];

					// Only touch Multistore callbacks.
					if ( stripos( $class_name, 'multistore' ) === false && stripos( $class_name, 'woonet' ) === false ) {
						continue;
					}

					// KEEP the start callback (renders current site / GR8 Fencing).
					// REMOVE only the one that appends the other network sites.
					if ( stripos( $method_name, 'start' ) !== false ) {
						continue;
					}

					remove_action( $hook_name, $function, $priority );
				}
			}

			return;
		}
	}

	public function add_return_exchange_endpoint() {
		add_rewrite_endpoint( 'return', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'exchange', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'return-exchange', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'view-return', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'view-exchange', EP_ROOT | EP_PAGES );
		if ( ! get_option( 'plugin_permalinks_flushed' ) ) {

			flush_rewrite_rules();
			update_option( 'plugin_permalinks_flushed', 1 );

		}
	}

	public function add_return_exchange_menu_item( $items ) {
		if ( ! is_user_logged_in() ) {
			return $items;
		}

		global $wpdb;
		$user_id = get_current_user_id();

		// Get plugin settings
		$settings         = get_option( 'return_general_settings_option', array() );
		$exchange_enabled = isset( $settings['exchange_enable'] ) ? $settings['exchange_enable'] : 'no';

		// Check return requests
		$return_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$wpdb->prefix}return_orders WHERE user_id = %d", $user_id ) );

		// Check exchange requests (only if exchange enabled)
		$exchange_count = 0;
		$exchange_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$wpdb->prefix}exchange_orders WHERE user_id = %d", $user_id ) );

		// If no requests at all → remove menu
		if ( empty( $return_count ) && empty( $exchange_count ) ) {
			unset( $items['return-exchange'] );
			return $items;
		}

		// Otherwise show menu with correct label
		if ( 'yes' === $exchange_enabled ) {
			$items['return-exchange'] = __( 'Return & Exchange', 'zorem-returns' );
		} else {
			$items['return-exchange'] = __( 'Return', 'zorem-returns' );
		}

		return $items;
	}

	public function return_exchange_content() {
		global $wpdb;

		$user_id = get_current_user_id();

		$settings         = get_option( 'return_general_settings_option', array() );
		$exchange_enabled = isset( $settings['exchange_enable'] ) ? $settings['exchange_enable'] : 'no';

		if ( ! $user_id ) {
			return array();
		}

		// Fetch return requests
		$return_requests = $wpdb->get_results( $wpdb->prepare( "SELECT *, 'return' AS request_type FROM {$wpdb->prefix}return_orders WHERE user_id = %d ORDER BY id DESC", $user_id ) );

		// Fetch exchange requests
		$exchange_requests = $wpdb->get_results( $wpdb->prepare( "SELECT *, 'exchange' AS request_type FROM {$wpdb->prefix}exchange_orders WHERE user_id = %d ORDER BY id DESC", $user_id ) );

		$return_requests   = ! empty( $return_requests ) ? $return_requests : array();
		$exchange_requests = ! empty( $exchange_requests ) ? $exchange_requests : array();

		// Merge both lists
		$requests = array_merge( $return_requests, $exchange_requests );

		// Dynamic heading
		if ( 'yes' === $exchange_enabled ) {
			$heading       = __( 'My Returns & Exchanges', 'zorem-returns' );
			$empty_message = __( 'You have no return or exchange requests yet.', 'zorem-returns' );
		} else {
			$heading       = __( 'My Returns', 'zorem-returns' );
			$empty_message = __( 'You have no return requests yet.', 'zorem-returns' );
		}

		echo '<h2>' . esc_html( $heading ) . '</h2>';

		if ( ! $requests ) {
			echo '<p>' . esc_html( $empty_message ) . '</p>';
			return;
		}

		echo '<table class="shop_table my_account_orders">';
		echo '<thead><tr><th>' . esc_html__( 'Request ID', 'zorem-returns' ) . '</th><th>' . esc_html__( 'Order', 'zorem-returns' ) . ' </th><th>' . esc_html__( 'Status', 'zorem-returns' ) . '</th><th>' . esc_html__( 'Type', 'zorem-returns' ) . '</th><th>' . esc_html__( 'Total', 'zorem-returns' ) . '</th><th>' . esc_html__( 'Actions', 'zorem-returns' ) . '</th></tr></thead>';
		echo '<tbody>';

		foreach ( $requests as $request ) {
			$total_price    = '0';
			$total_qty      = '0';
			$request_status = 'return' === $request->request_type ? $request->return_status : $request->exchange_status;
			$request_items  = 'return' === $request->request_type ? unserialize( $request->return_items ) : unserialize( $request->exchange_items );
			foreach ( $request_items as $order_item_id => $item ) {
				$qty      = 'return' === $request->request_type ? $item['return_product_qty'] : $item['exchange_product_qty']; // Get quantity
				$order_id = wc_get_order_id_by_order_item_id( $order_item_id ); // Get order ID from item ID
				if ( $order_id ) {
					$order      = wc_get_order( $order_id ); // Get order object
					$order_item = $order->get_item( $order_item_id ); // Get order item

					if ( $order_item ) {
						// Use the actual amount paid for the line (after discount, incl tax),
						// per unit, so the Total matches the refund, the completion email and
						// the admin summary — not the net catalog price.
						$item_qty    = $order_item->get_quantity();
						$line_gross  = (float) $order_item->get_total() + (float) $order_item->get_total_tax();
						$unit_price  = $item_qty > 0 ? ( $line_gross / $item_qty ) : $line_gross;
						$total_price += $unit_price * $qty;
						$total_qty   += $qty;
					}
				}
			}
			// $total = wp_kses_post( wc_price( $total_price ) . ' for ' . $total_qty . ( $total_qty > 1 ? ' items' : ' item' ) );
			$total = sprintf(
				/* translators: %1$s is total price (formatted), %2$d is quantity, %3$s is "item"/"items" */
				esc_html__( '%1$s for %2$d %3$s', 'zorem-returns' ),
				wc_price( $total_price ),
				$total_qty,
				_n( 'item', 'items', $total_qty, 'zorem-returns' )
			);
			$order_link = wc_get_endpoint_url( 'view-order', $order_id, wc_get_page_permalink( 'myaccount' ) );

			if ( 'return' === $request->request_type ) {
				$view_request_link = wc_get_endpoint_url( 'view-return', $request->id, wc_get_page_permalink( 'myaccount' ) );
			} else {
				$view_request_link = wc_get_endpoint_url( 'view-exchange', $request->id, wc_get_page_permalink( 'myaccount' ) );
			}

			$display_order_number = $this->get_display_order_number( $request->order_id );
			if ( '' === $display_order_number ) {
				$display_order_number = $request->order_id; // fallback to raw ID if order missing
			}

			echo '<tr>';
			echo '<th><a href="' . esc_url( $view_request_link ) . '">#' . esc_html( $request->id ) . '</a></th>';
			echo '<th><a href="' . esc_url( $order_link ) . '">#' . esc_html( $display_order_number ) . '</a></th>';
			echo '<td>' . esc_html__( ucfirst( str_replace( '-', ' ', $request_status ) ), 'zorem-returns' ) . '</td>';
			echo '<td>' . esc_html__( ucfirst( $request->request_type ), 'zorem-returns' ) . '</td>';
			echo '<td>' . wp_kses_post( $total ) . '</td>';
			echo '<td><a href="' . esc_url( $view_request_link ) . '" class="button">' . esc_html__( 'View', 'zorem-returns' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	public function view_return_request() {
		global $wpdb;

		// Get request ID from URL
		$request_id = get_query_var( 'view-return' );

		if ( ! $request_id || ! is_numeric( $request_id ) ) {
			echo '<p>' . esc_html( 'Invalid request.' ) . '</p>';
			return;
		}

		echo '<div class="multi-form-container"><div class="form-content">';
		$return_data = zorem_returns_exchanges()->common->display_return_data_request_id( $request_id );
		if ( is_string( $return_data ) ) {
			echo wp_kses_post( $return_data );
		}
		echo '</div></div>';
	}

	public function view_exchange_request() {
		global $wpdb;

		// Get request ID from URL
		$request_id = get_query_var( 'view-exchange' );

		if ( ! $request_id || ! is_numeric( $request_id ) ) {
			echo '<p>' . esc_html( 'Invalid request.' ) . '</p>';
			return;
		}

		echo '<div class="multi-form-container"><div class="form-content">';
		$exchange_data = zorem_returns_exchanges()->exCommon->display_exchange_data_request_id( $request_id );
		if ( is_string( $exchange_data ) ) {
			echo wp_kses_post( $exchange_data );
		}
		echo '</div></div>';
	}

	/**
	 * Return the customer-facing display number for a WooCommerce order.
	 *
	 * Resolves the order number through whichever sequential / custom-order-numbers
	 * plugin is active (Alg / WebToffee / SkyVerge / Booster). Falls back to the
	 * standard `_order_number` post meta (used by most plugins), then to
	 * WC_Order::get_order_number() which runs the full filter chain.
	 *
	 * Use this anywhere an order number is shown to a customer or admin so that
	 * raw post IDs are never exposed when a sequential plugin is active.
	 *
	 * @param int|WC_Order $order_or_id Order ID or order object.
	 * @return string Customer-facing order number, or empty string if order missing.
	 */
	public function get_display_order_number( $order_or_id ) {
		$order = is_object( $order_or_id ) ? $order_or_id : wc_get_order( (int) $order_or_id );
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$id = $order->get_id();

		// 1) Known plugin classes — call directly (most reliable).
		if ( class_exists( 'Alg_WC_Custom_Order_Numbers_Core' ) ) {
			$alg = new Alg_WC_Custom_Order_Numbers_Core();
			return (string) $alg->display_order_number( $id, $order );
		}
		if ( class_exists( 'Wt_Advanced_Order_Number' ) ) {
			$wt = new Wt_Advanced_Order_Number();
			return (string) $wt->display_sequence_number( $id, $order );
		}
		if ( class_exists( 'WC_Seq_Order_Number' ) ) {
			$seq = new WC_Seq_Order_Number();
			return (string) $seq->get_order_number( $id, $order );
		}
		if ( class_exists( 'WCJ_Order_Numbers' ) ) {
			$wcj = new WCJ_Order_Numbers();
			return (string) $wcj->display_order_number( $id, $order );
		}

		// 2) Most sequential plugins store the customer number in _order_number.
		$meta_number = $order->get_meta( '_order_number' );
		if ( '' !== $meta_number && null !== $meta_number ) {
			return (string) $meta_number;
		}
		$meta_alt = $order->get_meta( '_order_number_formatted' );
		if ( '' !== $meta_alt && null !== $meta_alt ) {
			return (string) $meta_alt;
		}

		// 3) Fall back to the WC filter chain.
		return (string) $order->get_order_number();
	}

	public function get_formated_order_id( $order_id ) {
		$original_order_id = $order_id;

		if ( '' === $order_id || null === $order_id ) {
			return apply_filters( 'rt4ex_formated_order_id', $order_id, $original_order_id );
		}

		$resolved_id = null;

		// 1) Custom Order Numbers for WooCommerce (Algoritmika).
		if ( is_plugin_active( 'custom-order-numbers-for-woocommerce/custom-order-numbers-for-woocommerce.php' ) ) {
			if ( 'yes' === get_option( 'alg_wc_custom_order_numbers_enabled' ) ) {
				$prefix    = (string) do_shortcode( get_option( 'alg_wc_custom_order_numbers_prefix', '' ) );
				$suffix    = (string) do_shortcode( get_option( 'alg_wc_custom_order_numbers_suffix', '' ) );
				$candidate = $this->strip_order_number_affixes( (string) $order_id, $prefix, $suffix );
				$resolved_id = $this->find_order_id_by_meta( '_alg_wc_custom_order_number', $candidate );
				if ( ! $resolved_id && $candidate !== (string) $order_id ) {
					$resolved_id = $this->find_order_id_by_meta( '_alg_wc_custom_order_number', $order_id );
				}
			}
		}

		// 2) WooCommerce Sequential Order Numbers (SkyVerge - Free).
		if ( ! $resolved_id && function_exists( 'wc_sequential_order_numbers' ) ) {
			$s_order_id = wc_sequential_order_numbers()->find_order_by_order_number( $order_id );
			if ( $s_order_id ) {
				$resolved_id = (int) $s_order_id;
			}
		}

		// 3) WooCommerce Sequential Order Numbers Pro (SkyVerge).
		if ( ! $resolved_id && function_exists( 'wc_seq_order_number_pro' ) ) {
			$s_order_id = wc_seq_order_number_pro()->find_order_by_order_number( $order_id );
			if ( $s_order_id ) {
				$resolved_id = (int) $s_order_id;
			}
		}

		// 4) Booster for WooCommerce (also Booster Plus / Elite).
		if ( ! $resolved_id ) {
			$booster_plugins = array(
				'woocommerce-jetpack/woocommerce-jetpack.php',
				'booster-plus-for-woocommerce/booster-plus-for-woocommerce.php',
				'booster-elite-for-woocommerce/booster-elite-for-woocommerce.php',
			);
			foreach ( $booster_plugins as $booster_file ) {
				if ( ! is_plugin_active( $booster_file ) ) {
					continue;
				}
				if ( 'yes' !== get_option( 'wcj_order_numbers_enabled' ) ) {
					break;
				}
				$prefix  = (string) do_shortcode( get_option( 'wcj_order_number_prefix', '' ) );
				$prefix .= date_i18n( (string) get_option( 'wcj_order_number_date_prefix', '' ) );
				$suffix  = (string) do_shortcode( get_option( 'wcj_order_number_suffix', '' ) );
				$suffix .= date_i18n( (string) get_option( 'wcj_order_number_date_suffix', '' ) );

				$candidate   = $this->strip_order_number_affixes( (string) $order_id, $prefix, $suffix );
				$resolved_id = $this->find_order_id_by_meta( '_wcj_order_number', $candidate );
				if ( ! $resolved_id && $candidate !== (string) $order_id ) {
					$resolved_id = $this->find_order_id_by_meta( '_wcj_order_number', $order_id );
				}
				break;
			}
		}

		// 5) WP-Lister Lite for Amazon.
		if ( ! $resolved_id && is_plugin_active( 'wp-lister-amazon/wp-lister-amazon.php' ) ) {
			if ( 1 == get_option( 'wpla_use_amazon_order_number' ) ) {
				$resolved_id = $this->find_order_id_by_meta( '_wpla_amazon_order_id', $order_id );
			}
		}

		// 6) WP-Lister Lite for eBay.
		if ( ! $resolved_id && (
			is_plugin_active( 'wp-lister/wp-lister.php' ) ||
			is_plugin_active( 'wp-lister-for-ebay/wp-lister.php' )
		) ) {
			$ebay_orders = wc_get_orders(
				array(
					'limit'      => 1,
					'return'     => 'ids',
					'meta_query' => array(
						'relation' => 'OR',
						array(
							'key'   => '_ebay_extended_order_id',
							'value' => $order_id,
						),
						array(
							'key'   => '_ebay_order_id',
							'value' => $order_id,
						),
					),
				)
			);
			if ( ! empty( $ebay_orders ) ) {
				$resolved_id = (int) $ebay_orders[0];
			}
		}

		// 7) YITH WooCommerce Sequential Order Number (Free + Premium).
		if ( ! $resolved_id && (
			is_plugin_active( 'yith-woocommerce-sequential-order-number-premium/init.php' ) ||
			is_plugin_active( 'yith-woocommerce-sequential-order-number/init.php' )
		) ) {
			$yith_meta_keys = array(
				'_ywson_custom_number_order_complete',
				'_ywsn_order_number',
				'_order_number',
			);
			foreach ( $yith_meta_keys as $yith_meta_key ) {
				$resolved_id = $this->find_order_id_by_meta( $yith_meta_key, $order_id );
				if ( $resolved_id ) {
					break;
				}
			}
		}

		// 8) Sequential Order Number for WooCommerce (WebToffee).
		if ( ! $resolved_id && (
			is_plugin_active( 'wt-advanced-order-number/wt-advanced-order-number.php' ) ||
			is_plugin_active( 'wt-woocommerce-sequential-order-numbers/wt-advanced-order-number.php' )
		) ) {
			$resolved_id = $this->find_order_id_by_meta( '_order_number', $order_id );
		}

		// 9) WooMultistore (network-wide sequential order numbers in _order_number).
		if ( ! $resolved_id && (
			is_plugin_active( 'woocommerce-multistore/woocommerce-multistore.php' ) ||
			class_exists( 'WC_Multistore_Sequential_Order_Number' )
		) ) {
			$resolved_id = $this->find_order_id_by_meta( '_order_number', $order_id );
		}

		// 10) Shopify -> WooCommerce importer.
		if ( ! $resolved_id && class_exists( 'S2W_IMPORT_SHOPIFY_TO_WOOCOMMERCE' ) ) {
			$resolved_id = $this->find_order_id_by_meta( '_s2w_shopify_order_numberc', $order_id );
		}

		// 11) Generic fallback - scan common custom-order-number meta keys.
		// Runs when no plugin-specific lookup matched. This catches white-labeled
		// installs, alternate plugin slugs, and any setup the dedicated branches miss.
		if ( ! $resolved_id ) {
			$fallback_meta_keys = apply_filters(
				'rt4ex_order_number_meta_keys',
				array(
					'_alg_wc_custom_order_number',
					'_order_number',
					'_order_number_formatted',
					'_wcj_order_number',
					'_ywson_custom_number_order_complete',
					'_ywsn_order_number',
					'_wpla_amazon_order_id',
					'_ebay_order_id',
					'_ebay_extended_order_id',
					'_s2w_shopify_order_numberc',
				)
			);
			foreach ( $fallback_meta_keys as $meta_key ) {
				$resolved_id = $this->find_order_id_by_meta( $meta_key, $order_id );
				if ( $resolved_id ) {
					break;
				}
			}
		}

		if ( $resolved_id ) {
			$order_id = $resolved_id;
		}

		return apply_filters( 'rt4ex_formated_order_id', $order_id, $original_order_id );
	}

	/**
	 * Strip a configured prefix and suffix from an order number string.
	 */
	private function strip_order_number_affixes( $value, $prefix, $suffix ) {
		$value = (string) $value;
		if ( '' !== $prefix ) {
			$value = preg_replace( '/^' . preg_quote( $prefix, '/' ) . '/i', '', $value );
		}
		if ( '' !== $suffix ) {
			$value = preg_replace( '/' . preg_quote( $suffix, '/' ) . '$/i', '', $value );
		}
		return $value;
	}

	/**
	 * Find a WooCommerce order ID by a single meta key/value pair (HPOS-safe).
	 */
	private function find_order_id_by_meta( $meta_key, $meta_value ) {
		if ( '' === (string) $meta_value || '' === (string) $meta_key ) {
			return false;
		}
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_key'   => $meta_key,
				'meta_value' => $meta_value,
			)
		);
		if ( ! empty( $orders ) ) {
			return (int) $orders[0];
		}
		return false;
	}
}
