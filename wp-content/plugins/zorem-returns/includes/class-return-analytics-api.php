<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Analytics_API {

	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'returns/v1',
			'/analytics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_analytics' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => array(
					'date_min' => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
					),
					'date_max' => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => gmdate( 'Y-m-d' ),
					),
					'date_min_compare' => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'date_max_compare' => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);
	}

	private function is_hpos_enabled() {
		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			try {
				return wc_get_container()
					->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
					->custom_orders_table_usage_is_enabled();
			} catch ( \Exception $e ) {
				return false;
			}
		}
		return false;
	}

	private function get_returns_in_range( $date_min, $date_max ) {
		global $wpdb;

		$date_min_full = $date_min . ' 00:00:00';
		$date_max_full = $date_max . ' 23:59:59';

		if ( $this->is_hpos_enabled() ) {
			// HPOS: orders in wp_wc_orders table
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.id, r.order_id, r.return_status, r.return_items, r.return_issue,
					        r.payment_method, o.date_created_gmt AS order_date, o.total_amount AS order_total
					 FROM {$wpdb->prefix}return_orders r
					 INNER JOIN {$wpdb->prefix}wc_orders o ON r.order_id = o.id
					 WHERE o.date_created_gmt BETWEEN %s AND %s
					   AND o.type = 'shop_order'",
					$date_min_full,
					$date_max_full
				)
			);
		} else {
			// Classic: orders in wp_posts
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.id, r.order_id, r.return_status, r.return_items, r.return_issue,
					        r.payment_method, p.post_date AS order_date, pm.meta_value AS order_total
					 FROM {$wpdb->prefix}return_orders r
					 INNER JOIN {$wpdb->posts} p ON r.order_id = p.ID
					 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
					 WHERE p.post_date BETWEEN %s AND %s
					   AND p.post_type = 'shop_order'",
					$date_min_full,
					$date_max_full
				)
			);
		}
	}

	private function get_total_orders_in_range( $date_min, $date_max ) {
		global $wpdb;

		$date_min_full = $date_min . ' 00:00:00';
		$date_max_full = $date_max . ' 23:59:59';

		if ( $this->is_hpos_enabled() ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders
					 WHERE date_created_gmt BETWEEN %s AND %s AND type = 'shop_order' AND status NOT IN ('wc-cancelled','wc-refunded')",
					$date_min_full,
					$date_max_full
				)
			);
		} else {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					 WHERE post_date BETWEEN %s AND %s
					   AND post_type = 'shop_order'
					   AND post_status NOT IN ('wc-cancelled','wc-refunded')",
					$date_min_full,
					$date_max_full
				)
			);
		}
	}

	/**
	 * Compute summary totals for a set of returns and the total order count for the same period.
	 */
	private function compute_totals( $returns, $total_orders ) {
		$total_returns    = count( $returns );
		$revenue_lost     = 0.0;
		$revenue_retained = 0.0;

		foreach ( $returns as $row ) {
			$order_total = floatval( $row->order_total );
			if ( in_array( $row->return_status, array( 'return-approved', 'return-completed' ), true ) ) {
				$revenue_lost += $order_total;
			} else {
				$revenue_retained += $order_total;
			}
		}

		$return_rate = $total_orders > 0 ? round( ( $total_returns / $total_orders ) * 100, 1 ) : 0;

		return array(
			'total_returns'       => $total_returns,
			'return_rate'         => $return_rate,
			'revenue_lost'        => round( $revenue_lost, 2 ),
			'revenue_retained'    => round( $revenue_retained, 2 ),
			'avg_resolution_time' => 0,
		);
	}

	public function get_analytics( $request ) {
		global $wpdb;

		$date_min = $request->get_param( 'date_min' );
		$date_max = $request->get_param( 'date_max' );

		$returns      = $this->get_returns_in_range( $date_min, $date_max );
		$total_orders = $this->get_total_orders_in_range( $date_min, $date_max );
		$total_returns = count( $returns );

		// Revenue lost = sum of order totals for completed/approved returns
		$revenue_lost      = 0.0;
		$revenue_retained  = 0.0;
		$product_counts    = array();
		$status_counts     = array();
		$reason_map        = array();

		foreach ( $returns as $row ) {
			$order_total = floatval( $row->order_total );

			if ( in_array( $row->return_status, array( 'return-approved', 'return-completed' ), true ) ) {
				$revenue_lost += $order_total;
			} else {
				$revenue_retained += $order_total;
			}

			// Count statuses for resolution split
			$status = $row->return_status;
			$status_counts[ $status ] = ( isset( $status_counts[ $status ] ) ? $status_counts[ $status ] : 0 ) + 1;

			// Tally products from serialized return_items.
			// Keys are WC order item IDs; look up product_id from order item meta.
			$items = maybe_unserialize( $row->return_items );
			if ( is_array( $items ) ) {
				foreach ( $items as $item_id => $item ) {
					$product_id = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta
							 WHERE order_item_id = %d AND meta_key = '_product_id'",
							$item_id
						)
					);
					if ( ! $product_id ) {
						continue;
					}
					if ( ! isset( $product_counts[ $product_id ] ) ) {
						$product_counts[ $product_id ] = array(
							'returns'      => 0,
							'revenue_lost' => 0.0,
						);
					}
					$product_counts[ $product_id ]['returns']++;
					if ( in_array( $row->return_status, array( 'return-approved', 'return-completed' ), true ) ) {
						$product_counts[ $product_id ]['revenue_lost'] += $order_total;
					}
					// Track top reason per product
					if ( ! empty( $row->return_issue ) ) {
						$reason_map[ $product_id ][ $row->return_issue ] = ( isset( $reason_map[ $product_id ][ $row->return_issue ] ) ? $reason_map[ $product_id ][ $row->return_issue ] : 0 ) + 1;
					}
				}
			}
		}

		$return_rate = $total_orders > 0 ? round( ( $total_returns / $total_orders ) * 100, 1 ) : 0;

		// Build top_products (top 10 by return count)
		arsort( $product_counts );
		$top_products = array();
		foreach ( array_slice( $product_counts, 0, 10, true ) as $product_id => $data ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$product_total_orders = $this->get_product_order_count( $product_id, $date_min, $date_max );
			$product_return_rate  = $product_total_orders > 0
				? round( ( $data['returns'] / $product_total_orders ) * 100, 1 )
				: 0;

			// Top reason
			$top_reason = '';
			if ( ! empty( $reason_map[ $product_id ] ) ) {
				arsort( $reason_map[ $product_id ] );
				$top_reason = array_key_first( $reason_map[ $product_id ] );
			}

			$top_products[] = array(
				'product_id'   => $product_id,
				'product_name' => $product->get_name(),
				'product_sku'  => $product->get_sku(),
				'returns'      => $data['returns'],
				'return_rate'  => $product_return_rate,
				'top_reason'   => $top_reason,
				'revenue_lost' => round( $data['revenue_lost'], 2 ),
			);
		}

		// Resolution split for chart
		$resolution_split = array();
		foreach ( $status_counts as $status => $count ) {
			$resolution_split[] = array(
				'label' => ucwords( str_replace( array( 'return-', '-' ), array( '', ' ' ), $status ) ),
				'value' => $count,
			);
		}

		// Comparison period totals
		$comparison_totals = null;
		$date_min_compare  = $request->get_param( 'date_min_compare' );
		$date_max_compare  = $request->get_param( 'date_max_compare' );
		if ( $date_min_compare && $date_max_compare ) {
			$compare_returns      = $this->get_returns_in_range( $date_min_compare, $date_max_compare );
			$compare_total_orders = $this->get_total_orders_in_range( $date_min_compare, $date_max_compare );
			$comparison_totals    = $this->compute_totals( $compare_returns, $compare_total_orders );
		}

		return rest_ensure_response(
			array(
				'totals'               => array(
					'total_returns'       => $total_returns,
					'return_rate'         => $return_rate,
					'revenue_lost'        => round( $revenue_lost, 2 ),
					'revenue_retained'    => round( $revenue_retained, 2 ),
					'avg_resolution_time' => 0,
				),
				'comparison_totals'     => $comparison_totals,
				'top_products'          => $top_products,
				'return_rate_over_time' => $this->get_return_rate_over_time( $returns, $date_min, $date_max ),
				'resolution_split'      => $resolution_split,
				'status_over_time'      => $this->get_status_over_time( $returns, $date_min, $date_max ),
			)
		);
	}

	/**
	 * Returns a time-series array where each day has per-status counts.
	 * Used by the Resolution Split chart in line mode.
	 *
	 * Format: [ { date, requested: { label, value }, approved: { label, value }, ... }, ... ]
	 */
	private function get_status_over_time( $returns, $date_min, $date_max ) {
		$daily        = array();
		$all_statuses = array(); // status_key => human label

		foreach ( $returns as $row ) {
			$day    = substr( $row->order_date, 0, 10 );
			$status = $row->return_status;
			// Sanitize to a JS-safe key: 'return-approved' → 'approved'
			$key    = str_replace( array( 'return-', '-' ), array( '', '_' ), $status );
			$label  = ucwords( str_replace( array( 'return-', '-' ), array( '', ' ' ), $status ) );

			$all_statuses[ $key ] = $label;

			if ( ! isset( $daily[ $day ] ) ) {
				$daily[ $day ] = array();
			}
			$daily[ $day ][ $key ] = ( isset( $daily[ $day ][ $key ] ) ? $daily[ $day ][ $key ] : 0 ) + 1;
		}

		if ( empty( $all_statuses ) ) {
			return array();
		}

		$result  = array();
		$current = new DateTime( $date_min );
		$end     = new DateTime( $date_max );
		while ( $current <= $end ) {
			$day   = $current->format( 'Y-m-d' );
			$point = array( 'date' => $day . 'T00:00:00' );
			foreach ( $all_statuses as $key => $label ) {
				$point[ $key ] = array(
					'label' => $label,
					'value' => isset( $daily[ $day ][ $key ] ) ? $daily[ $day ][ $key ] : 0,

				);
			}
			$result[] = $point;
			$current->modify( '+1 day' );
		}
		return $result;
	}

	private function get_return_rate_over_time( $returns, $date_min, $date_max ) {
		// Group return counts by day.
		$daily = array();
		foreach ( $returns as $row ) {
			$day = substr( $row->order_date, 0, 10 ); // YYYY-MM-DD
			$daily[ $day ] = ( isset( $daily[ $day ] ) ? $daily[ $day ] : 0 ) + 1;
		}

		// Build a full date range so days with 0 returns are included.
		$result   = array();
		$current  = new DateTime( $date_min );
		$end      = new DateTime( $date_max );
		while ( $current <= $end ) {
			$day      = $current->format( 'Y-m-d' );
			$result[] = array(
				'date'  => $day . 'T00:00:00',
				'count' => isset( $daily[ $day ] ) ? $daily[ $day ] : 0,
			);
			$current->modify( '+1 day' );
		}
		return $result;
	}

	private function get_product_order_count( $product_id, $date_min, $date_max ) {
		global $wpdb;

		$date_min_full = $date_min . ' 00:00:00';
		$date_max_full = $date_max . ' 23:59:59';

		if ( $this->is_hpos_enabled() ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT oi.order_id)
					 FROM {$wpdb->prefix}woocommerce_order_items oi
					 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
					 INNER JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id
					 WHERE oim.meta_key = '_product_id' AND oim.meta_value = %d
					   AND o.date_created_gmt BETWEEN %s AND %s
					   AND o.type = 'shop_order'",
					$product_id,
					$date_min_full,
					$date_max_full
				)
			);
		} else {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT oi.order_id)
					 FROM {$wpdb->prefix}woocommerce_order_items oi
					 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
					 INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
					 WHERE oim.meta_key = '_product_id' AND oim.meta_value = %d
					   AND p.post_date BETWEEN %s AND %s
					   AND p.post_type = 'shop_order'",
					$product_id,
					$date_min_full,
					$date_max_full
				)
			);
		}
	}
}
