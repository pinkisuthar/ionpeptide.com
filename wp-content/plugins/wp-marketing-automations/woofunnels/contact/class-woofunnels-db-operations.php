<?php
/**
 * WooFunnels customer and contact DB operations
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WooFunnels_DB_Operations' ) ) {
	/**
	 * Class WooFunnels_DB_Operations
	 */
	#[AllowDynamicProperties]
	class WooFunnels_DB_Operations {
		/**
		 * @var $ins
		 */
		public static $ins;

		/**
		 * @var $contact_tbl
		 */
		public $contact_tbl;

		/**
		 * @var $customer_tbl
		 */
		public $customer_tbl;

		public $cache_query = [];

		public $cache_meta_query = false;
		public $cache_field_query = false;

		/**
		 * WooFunnels_DB_Operations constructor.
		 */
		public function __construct() {
			global $wpdb;
			$this->contact_tbl      = $wpdb->prefix . 'bwf_contact';
			$this->contact_meta_tbl = $wpdb->prefix . 'bwf_contact_meta';
			$this->customer_tbl     = $wpdb->prefix . 'bwf_wc_customers';
		}

		/**
		 * @return WooFunnels_DB_Operations
		 */
		public static function get_instance() {
			if ( null === self::$ins ) {
				self::$ins = new self;
			}

			return self::$ins;
		}

		/**
		 * Inserting a new row in bwf_contact table
		 *
		 * @param $customer
		 *
		 * @return int
		 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
		 */
		public function insert_contact( $contact ) {
			if ( isset( $contact['id'] ) ) {
				unset( $contact['id'] );
			}
			global $wpdb;

			$inserted = $wpdb->insert( $this->contact_tbl, $contact ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$lastId   = 0;
			if ( $inserted ) {
				$lastId = $wpdb->insert_id;
			}
			if ( $wpdb->last_error !== '' ) {
				BWF_Logger::get_instance()->log( 'Get last error in insert_contact: ' . print_r( $wpdb->last_error, true ), 'woofunnels_indexing' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			}

			return $lastId;
		}

		/**
		 * Updating a contact
		 *
		 * @param $contact
		 *
		 * @return array|object|null
		 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
		 */
		public function update_contact( $contact ) {
			global $wpdb;
			$update_data = array();

			foreach ( is_array( $contact ) ? $contact : array() as $key => $value ) {
				$update_data[ $key ] = $value;
			}

			$wpdb->update( $this->contact_tbl, $update_data, array( 'id' => $contact['id'] ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $wpdb->last_error !== '' ) {
				BWF_Logger::get_instance()->log( "Get last error in update_customer for cid: {$contact['id']} " . print_r( $wpdb->last_error, true ), 'woofunnels_indexing' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			}
		}

		/**
		 * Getting contacts
		 *
		 * @return array|object|null
		 */
		public function get_all_contacts_count() {
			global $wpdb;
			$sql = "SELECT COUNT(id) FROM `$this->contact_tbl`";

			return $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Static query on prefixed custom table bwf_contact
		}

		/**
		 * Getting contacts based on given criteria
		 *
		 * @param $args
		 *
		 * @return array|object|null
		 */
		public function get_contacts( $args ) {
			global $wpdb;

			$where  = ' WHERE 1=1 ';
			$limit  = '';
			$params = array();

			if ( ! empty( $args['min_creation_date'] ) ) {
				$where    .= ' AND contact.creation_date >= %s';
				$params[]  = gmdate( 'Y-m-d H:i:s', $args['min_creation_date'] ); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			}

			if ( ! empty( $args['max_creation_date'] ) ) {
				$where    .= ' AND contact.creation_date < %s';
				$params[]  = gmdate( 'Y-m-d H:i:s', $args['max_creation_date'] ); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			}

			if ( - 1 !== $args['contact_limit'] && ! empty( absint( $args['contact_limit'] ) ) ) {
				$limit    = ' LIMIT %d';
				$params[] = absint( $args['contact_limit'] );
			}

			if ( ! empty( $params ) ) {
				$sql = "SELECT * FROM {$this->contact_tbl} AS contact" . $where . $limit;

				// $sql is assembled from static clause fragments; the only interpolated identifier is
				// $this->contact_tbl ($wpdb->prefix + literal). All dynamic values are bound via $params.
				return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static fragments, gmdate/absint values bound via prepare
			}

			return $wpdb->get_results( "SELECT * FROM {$this->contact_tbl} AS contact WHERE 1=1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Static query on prefixed custom table bwf_contact
		}

		protected function get_set_cached_response( $sql, $get = 'row' ) {
			global $wpdb;
			if ( 'row' === $get ) {
				$result = $wpdb->get_row( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Protected helper; every caller passes a $wpdb->prepare() result
			} elseif ( 'var' === $get ) {
				$result = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Protected helper; every caller passes a $wpdb->prepare() result
			} elseif ( 'col' === $get ) {
				$result = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Protected helper; every caller passes a $wpdb->prepare() result
			} else {
				$result = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Protected helper; every caller passes a $wpdb->prepare() result
			}

			return $result;
		}


		/**
		 * Get contact for given uid id if it exists
		 */
		public function get_contact( $uid ) {
			global $wpdb;
			$sql = "SELECT * FROM `$this->contact_tbl` WHERE `uid` = %s";
			$sql = $wpdb->prepare( $sql, $uid ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared; prefixed custom table, value bound via placeholder

			return $this->get_set_cached_response( $sql );
		}

		/**
		 * Get contact for given wpid id if it exists
		 */
		public function get_contact_by_wpid( $wp_id ) {
			global $wpdb;
			$sql = "SELECT * FROM `$this->contact_tbl` WHERE `wpid` = %d";
			$sql = $wpdb->prepare( $sql, $wp_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared; prefixed custom table, value bound via placeholder

			return $this->get_set_cached_response( $sql );
		}

		/**
		 * Get contact for given email id if it exists
		 */
		public function get_contact_by_email( $email ) {
			global $wpdb;
			$sql = "SELECT * FROM `$this->contact_tbl` WHERE `email` = %s";
			$sql = $wpdb->prepare( $sql, $email ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared; prefixed custom table, value bound via placeholder

			return $this->get_set_cached_response( $sql );
		}

		/**
		 * Get contact by given phone number
		 *
		 * @param $phone
		 *
		 * @return array|object|void|null
		 */
		public function get_contact_by_phone( $phone ) {
			global $wpdb;
			$sql = "SELECT * FROM `$this->contact_tbl` WHERE `contact_no` = %s";
			$sql = $wpdb->prepare( $sql, $phone ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared; prefixed custom table, value bound via placeholder

			return $this->get_set_cached_response( $sql );
		}

		/**
		 * Get contact for given contact id if it exists
		 */
		public function get_contact_by_contact_id( $contact_id ) {
			global $wpdb;
			$sql = "SELECT * FROM `$this->contact_tbl` WHERE `id` = %d";
			$sql = $wpdb->prepare( $sql, $contact_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared; prefixed custom table, value bound via placeholder

			return $this->get_set_cached_response( $sql );
		}

		/**
		 * Get all contact meta key value for a given contact id
		 *
		 * @param $contact_id
		 *
		 * @return array|object|null
		 */
		public function get_contact_metadata( $contact_id ) {
			global $wpdb;
			$sql = "SELECT `meta_key`, `meta_value` FROM `$this->contact_meta_tbl` WHERE `contact_id` = %d";
			$sql = $wpdb->prepare( $sql, $contact_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared; prefixed custom table, value bound via placeholder

			if ( true === $this->cache_meta_query ) {
				// extra caching when variable is set, used in actions like FKA broadcast
				if ( isset( $this->cache_query['meta'] ) && isset( $this->cache_query['meta'][ $contact_id ] ) ) {
					return $this->cache_query['meta'][ $contact_id ];
				}
				if ( ! isset( $this->cache_query['meta'] ) ) {
					$this->cache_query['meta'] = [];
				}

				$this->cache_query['meta'][ $contact_id ] = $this->get_set_cached_response( $sql, 'results' );

				return $this->cache_query['meta'][ $contact_id ];
			}

			return $this->get_set_cached_response( $sql, 'results' );
		}

		/**
		 * @param $contact_id
		 * @param $contact_meta
		 */
		public function save_contact_meta( $contact_id, $contact_meta ) {
			global $wpdb;

			foreach ( is_object( $contact_meta ) ? $contact_meta : array() as $meta_key => $meta_value ) {

				$meta_exists = false;
				$meta_value  = ( is_array( $meta_value ) ) ? maybe_serialize( $meta_value ) : $meta_value;

				if ( $this->meta_id_exists( $contact_id, $meta_key ) ) {
					$meta_exists = true;
					//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update( $this->contact_meta_tbl, array(
						'meta_value' => $meta_value,    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					), array(
						'meta_key'   => $meta_key,  //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'contact_id' => $contact_id,
					), array(
						'%s',    // meta_value
					), array( '%s', '%s' ) );
				}
				if ( ! $meta_exists ) {
					$contact_meta = array(
						'contact_id' => $contact_id,
						'meta_key'   => $meta_key, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value' => $meta_value, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					);
					$wpdb->insert( $this->contact_meta_tbl, $contact_meta ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
			}

		}

		/**
		 * @param $contact_id
		 * @param $meta_key
		 */
		public function meta_id_exists( $contact_id, $meta_key ) {
			global $wpdb;
			$sql     = $wpdb->prepare( "SELECT `meta_id` FROM `$this->contact_meta_tbl` WHERE `contact_id` = %d AND `meta_key` = %s", $contact_id, $meta_key ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$meta_id = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Executes prepared string; prefixed table, values bound

			return ( ! empty( $meta_id ) && $meta_id > 0 ) ? true : false;
		}

		/**
		 * @param $contact_id
		 * @param $meta_key
		 * @param $meta_value
		 *
		 * @return int
		 */
		public function update_contact_meta( $contact_id, $meta_key, $meta_value ) {
			global $wpdb;
			$db_meta_value = $this->get_contact_meta_value( $contact_id, $meta_key );

			if ( is_array( $meta_value ) || is_object( $meta_value ) ) {

				$meta_value_ids = empty( $db_meta_value ) ? array() : json_decode( $db_meta_value, true );


				if ( false === is_array( $meta_value_ids ) ) {
					$meta_value_ids = [];
				}

				if ( false === is_array( $meta_value ) ) {
					$meta_value = [];
				}
				$meta_value = wp_json_encode( array_unique( array_merge( $meta_value_ids, $meta_value ) ) );

			}
			$meta_exists = false;

			if ( $this->meta_id_exists( $contact_id, $meta_key ) ) {
				$meta_exists = true;
				//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $this->contact_meta_tbl, array(
					'meta_value' => $meta_value,    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				), array(
					'meta_key'   => $meta_key, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'contact_id' => $contact_id,
				), array(
					'%s',    // meta_value
				), array( '%s', '%s' ) );
			}
			if ( ! $meta_exists ) {
				$contact_meta = array(
					'contact_id' => $contact_id,
					'meta_key'   => $meta_key,      //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $meta_value,    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				);
				$inserted     = $wpdb->insert( $this->contact_meta_tbl, $contact_meta );//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$last_id      = 0;
				if ( $inserted ) {
					$last_id = $wpdb->insert_id;
				}

				return $last_id;
			}
		}

		/**
		 * Get contact meta for a given contact id and meta key
		 *
		 * @param $contact_id
		 *
		 * @return string|null
		 */
		public function get_contact_meta_value( $contact_id, $meta_key ) {
			global $wpdb;
			$sql = "SELECT `meta_value` FROM `$this->contact_meta_tbl` WHERE `contact_id` = %d AND `meta_key` = %s";
			$sql = $wpdb->prepare( $sql, $contact_id, $meta_key ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared; prefixed custom table, values bound via placeholder

			return $this->get_set_cached_response( $sql, 'var' );
		}

		/**
		 * Inserting a new row in bwf_customer table
		 *
		 * @param $customer
		 *
		 * @return int
		 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
		 */
		public function insert_customer( $customer ) {
			global $wpdb;
			$customer_data = array(
				'cid'                     => $customer['cid'],
				'l_order_date'            => $customer['l_order_date'],
				'f_order_date'            => $customer['f_order_date'],
				'total_order_count'       => $customer['total_order_count'],
				'total_order_value'       => $customer['total_order_value'],
				'aov'                     => $customer['aov'],
				'purchased_products'      => $customer['purchased_products'],
				'purchased_products_cats' => $customer['purchased_products_cats'],
				'purchased_products_tags' => $customer['purchased_products_tags'],
				'used_coupons'            => $customer['used_coupons'],
			);

			$inserted = $wpdb->insert( $this->customer_tbl, $customer_data ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			$lastId = 0;
			if ( $inserted ) {
				$lastId = $wpdb->insert_id;
			}

			if ( $wpdb->last_error !== '' ) {
				BWF_Logger::get_instance()->log( 'Get last error in insert_customer: ' . print_r( $wpdb->last_error, true ), 'woofunnels_indexing' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			}

			return $lastId;
		}

		/**
		 * Updating a customer
		 *
		 * @param $customer
		 *
		 * @return array|object|null
		 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
		 */
		public function update_customer( $customer ) {
			global $wpdb;
			$update_data = array();

			foreach ( is_array( $customer ) ? $customer : array() as $key => $value ) {
				$update_data[ $key ] = $value;
			}

			$wpdb->update( $this->customer_tbl, $update_data, array( 'id' => $customer['id'] ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $wpdb->last_error !== '' ) {
				BWF_Logger::get_instance()->log( "Get last error in update_customer for cid: {$customer['cid']} " . print_r( $wpdb->last_error, true ), 'woofunnels_indexing' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			}
		}

		/**
		 * Getting customers based on given criteria
		 *
		 * @param $args
		 *
		 * @return array|object|null
		 */
		public function get_customers( $args ) {
			global $wpdb;

			$where  = ' WHERE 1=1 ';
			$limit  = '';
			$params = array();

			// Order count/value clauses are always applied; every value is bound (%d/%f).
			$where    .= ' AND customer.total_order_count >= %d AND customer.total_order_count < %d';
			$params[]  = absint( $args['min_order_count'] );
			$params[]  = absint( $args['max_order_count'] );

			$where    .= ' AND customer.total_order_value >= %f AND customer.total_order_value < %f';
			$params[]  = floatval( $args['min_order_value'] );
			$params[]  = floatval( $args['max_order_value'] );

			if ( ! empty( $args['min_last_order_date'] ) ) {
				$where    .= ' AND customer.l_order_date >= %s';
				$params[]  = gmdate( 'Y-m-d H:i:s', $args['min_last_order_date'] ); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			}

			if ( ! empty( $args['max_last_order_date'] ) ) {
				$where    .= ' AND customer.l_order_date < %s';
				$params[]  = gmdate( 'Y-m-d H:i:s', $args['max_last_order_date'] ); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			}

			if ( ! empty( $args['min_creation_date'] ) ) {
				$where    .= ' AND customer.creation_date >= %s';
				$params[]  = gmdate( 'Y-m-d H:i:s', $args['min_creation_date'] ); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			}

			if ( ! empty( $args['max_creation_date'] ) ) {
				$where    .= ' AND customer.creation_date < %s';
				$params[]  = gmdate( 'Y-m-d H:i:s', $args['max_creation_date'] ); //phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			}

			if ( - 1 !== $args['customer_limit'] && ! empty( absint( $args['customer_limit'] ) ) ) {
				$limit    = ' LIMIT %d';
				$params[] = absint( $args['customer_limit'] );
			}

			// $sql is assembled from static clause fragments; the only interpolated identifier is
			// $this->customer_tbl ($wpdb->prefix + literal). All dynamic values are bound via $params.
			$sql = "SELECT * FROM {$this->customer_tbl} AS customer" . $where . $limit;

			$customers = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static fragments, cast values bound via prepare

			return $customers;
		}

		/**
		 * Get customer for given uid id if it exists
		 */
		public function get_customer( $uid ) {
			global $wpdb;
			$sql = "SELECT * FROM `$this->customer_tbl` WHERE `uid` = %s";
			$sql = $wpdb->prepare( $sql, $uid ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared; prefixed custom table, value bound via placeholder

			return $this->get_set_cached_response( $sql );
		}

		/**
		 * Get customer for given cid id if it exists
		 */
		public function get_customer_by_cid( $cid ) {
			global $wpdb;
			$sql = "SELECT * FROM `$this->customer_tbl` WHERE `cid` = %d";
			$sql = $wpdb->prepare( $sql, $cid ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared; prefixed custom table, value bound via placeholder

			return $this->get_set_cached_response( $sql );
		}

		/**
		 * Get customer for given customer id if it exists
		 */
		public function get_customer_by_customer_id( $customer_id ) {
			global $wpdb;
			$sql = "SELECT * FROM `$this->customer_tbl` WHERE `id` = %d";
			$sql = $wpdb->prepare( $sql, $customer_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared; prefixed custom table, value bound via placeholder

			return $this->get_set_cached_response( $sql );
		}

		/**
		 * Deleting a meta key from contact meta table
		 *
		 * @param $cid
		 * @param $meta_key
		 */
		public function delete_contact_meta( $cid, $meta_key ) {
			global $wpdb;
			if ( $this->meta_id_exists( $cid, $meta_key ) ) {
				//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $this->contact_meta_tbl, array(
					'contact_id' => $cid,
					'meta_key'   => $meta_key, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				) );
			}
		}
	}

	WooFunnels_DB_Operations::get_instance();
}
