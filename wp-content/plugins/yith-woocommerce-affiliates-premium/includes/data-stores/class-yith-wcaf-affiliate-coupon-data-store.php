<?php
/**
 * Affiliate's coupon data store
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Affiliate_Coupon_Data_Store' ) ) {
	/**
	 * This class implements CRUD methods for Coupons
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Affiliate_Coupon_Data_Store extends WC_Coupon_Data_Store_CPT {

		use YITH_WCAF_Trait_Cacheable;

		/**
		 * Database structure.
		 *
		 * @var array
		 */
		protected $columns = array();

		/**
		 * Constructor method
		 */
		public function __construct() {
			$this->columns = array(
				'post_date'     => '%s',
				'post_title'    => '%d',
				'post_modified' => '%d',
				'menu_order'    => '%d',
			);

			$this->cache_group = 'affiliates';
		}

		/**
		 * Return count of coupons matching filtering criteria
		 *
		 * @param array $args Filtering criteria (@see \YITH_WCAF_Affiliate_Coupon_Data_Store::query).
		 * @return int Count of matching coupons.
		 */
		public function count( $args = array() ) {
			$args['fields'] = 'count';

			return (int) $this->query( $args );
		}

		/**
		 * Return coupons matching filtering criteria
		 *
		 * @param array $args Filtering criteria<br/>:
		 *              [<br/>
		 *              'affiliate_id' => false,   // coupons related affiliate id (int)<br/>
		 *              'orderby' => 'ID',         // sorting direction (ASC/DESC)<br/>
		 *              'order' => 'ASC',          // sorting column (any table valid column)<br/>
		 *              'limit' => 0,              // limit (int)<br/>
		 *              'offset' => 0              // offset (int)<br/>
		 *              'fields => '' ,            // fields to retrieve (count, or any valid column name, optionally prefixed by "id=>" to have result indexed by object ID)<br/>
		 *              ].
		 *
		 * @return YITH_WCAF_Coupons_Collection|string[]|int|bool Matching coupons, or coupons count
		 */
		public function query( $args = array() ) {
			global $wpdb;

			$defaults = array(
				'affiliate_id' => false,
				'limit'        => 0,
				'orderby'      => 'ID',
				'order'        => 'DESC',
				'offset'       => 0,
				'fields'       => '',
			);

			$args = wp_parse_args( $args, $defaults );

			// checks if we're performing a count query.
			$is_counting = ! empty( $args['fields'] ) && 'count' === $args['fields'];

			// retrieve data from cache, when possible.
			$cache_key = $this->get_versioned_cache_key( 'query', $args );
			$res       = $this->cache_get( $cache_key );

			// if no data found in cache, query database.
			if ( false === $res ) {
				$query      = "SELECT p.ID 
					FROM {$wpdb->posts} AS p LEFT JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id 
					WHERE p.post_type = %s 
						AND pm.meta_key = %s";
				$query_args = array(
					'shop_coupon',
					'coupon_referrer',
				);

				if ( $is_counting ) {
					$query = "SELECT COUNT(*) 
					FROM {$wpdb->posts} AS p LEFT JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id 
					WHERE p.post_type = %s 
						AND pm.meta_key = %s";
				}

				if ( ! empty( $args['affiliate_id'] ) ) {
					$query       .= ' AND pm.meta_value = %s';
					$query_args[] = $args['affiliate_id'];
				} else {
					$query .= ' AND pm.meta_value IS NOT NULL';
				}

				if ( ! empty( $args['orderby'] ) && ( 'ID' === $args['orderby'] || array_key_exists( $args['orderby'], $this->columns ) ) && ! $is_counting ) {
					$args['order'] = in_array( $args['order'], array( 'asc', 'desc', 'ASC', 'DESC' ), true ) ? $args['order'] : 'DESC';
					$query        .= sprintf( ' ORDER BY %s %s', $args['orderby'], $args['order'] );
				}

				if ( ! empty( $args['limit'] ) ) {
					$query .= sprintf( ' LIMIT %d, %d', ! empty( $args['offset'] ) ? $args['offset'] : 0, $args['limit'] );
				}

				if ( ! empty( $query_args ) ) {
					$query = $wpdb->prepare( $query, $query_args ); // phpcs:ignore WordPress.DB
				}

				if ( $is_counting ) {
					$res = (int) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB
				} else {
					$res = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB
				}

				$this->cache_set( $cache_key, $res );
			}

			// if we're counting, return count found.
			if ( $is_counting ) {
				return $res;
			}

			// if we have an empty set from db, return empty array/collection and skip next steps.
			if ( ! $res ) {
				return empty( $args['fields'] ) ? new YITH_WCAF_Coupons_Collection() : array();
			}

			$ids = array_map( 'intval', $res );

			if ( ! empty( $args['fields'] ) && 'ids' === $args['fields'] ) {
				return $ids;
			} elseif ( ! empty( $args['fields'] ) ) {
				$res     = array();
				$coupons = new YITH_WCAF_Coupons_Collection( $ids );
				$indexed = 0 === strpos( $args['fields'], 'id=>' );
				$field   = $indexed ? substr( $args['fields'], 4 ) : $args['fields'];

				foreach ( $coupons as $coupon ) {
					$res[] = $coupon->{"get_$field"}();
				}

				if ( $indexed ) {
					$res = array_combine( $ids, $res );
				}
			} else {
				// or get the complete affiliate object.
				$res = new YITH_WCAF_Coupons_Collection( $ids );
			}

			return $res;
		}

		/* === UTILITIES === */

		/**
		 * Clear cache for coupons queries
		 */
		public function clear_cache() {
			$this->invalidate_versioned_cache();
		}

	}
}
