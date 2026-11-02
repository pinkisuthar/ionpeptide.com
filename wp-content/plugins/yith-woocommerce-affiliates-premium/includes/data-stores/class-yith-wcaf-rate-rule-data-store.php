<?php
/**
 * Rate rule data store
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Rate_Rule_Data_Store' ) ) {
	/**
	 * This class implements CRUD methods for Rate rules
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Rate_Rule_Data_Store implements YITH_WCAF_Object_Data_Store_Interface {

		use YITH_WCAF_Trait_DB_Object, YITH_WCAF_Trait_Cacheable;

		/**
		 * Name of the class that contains rule meta
		 *
		 * @var string
		 */
		protected $meta_table;

		/**
		 * Expected meta structure.
		 *
		 * @var array
		 */
		protected $meta = array(
			'product_id',
			'affiliate_id',
			'product_cat',
			'user_role',
		);

		/**
		 * Maps object properties to database columns
		 * Every prop not included in this list, match the column name
		 *
		 * @var array
		 */
		protected $props_to_meta = array(
			'product_ids'        => 'product_id',
			'affiliate_ids'      => 'affiliate_id',
			'product_categories' => 'product_cat',
			'user_roles'         => 'user_role',
		);

		/**
		 * Constructor method
		 */
		public function __construct() {
			global $wpdb;

			$this->table      = $wpdb->prefix . 'yith_wcaf_rate_rules';
			$this->meta_table = $wpdb->prefix . 'yith_wcaf_rate_rulemeta';

			$wpdb->rate_rulemeta = $this->meta_table;

			$this->cache_group = 'rate_rules';

			$this->columns = array(
				'name'     => '%s',
				'enabled'  => '%d',
				'rate'     => '%f',
				'type'     => '%s',
				'priority' => '%d',
			);

			$this->orderby = array_merge(
				array_keys( $this->columns ),
				array(
					'ID',
				)
			);
		}

		/* === CRUD === */

		/**
		 * Method to create a new record of a WC_Data based object.
		 *
		 * @param YITH_WCAF_Rate_Rule $rule Data object.
		 * @throws Exception When rule cannot be created with current information.
		 */
		public function create( &$rule ) {
			global $wpdb;

			if ( ! $rule->get_name() ) {
				throw new Exception( _x( 'Unable to register rule. Missing required params.', '[DEV] Debug message triggered when unable to create rate rule record.', 'yith-woocommerce-affiliates' ) );
			}

			// set time fields, if necessary.
			if ( ! $rule->get_priority() ) {
				$rule->set_priority( $this->get_max_priority() + 1 );
			}

			$res = $this->save_object( $rule );

			if ( $res ) {
				/**
				 * APPLY_FILTERS: yith_wcaf_rate_rule_correctly_created
				 *
				 * Filters the id of the rate rule created.
				 *
				 * @param int $id Rate rule id.
				 */
				$id = apply_filters( 'yith_wcaf_rate_rule_correctly_created', intval( $wpdb->insert_id ) );

				$rule->set_id( $id );

				// create metadata.
				$changes = $rule->get_changes();

				foreach ( $this->meta as $meta ) {
					$meta_prop = $this->get_meta_prop_name( $meta );

					if ( empty( $changes[ $meta_prop ] ) ) {
						continue;
					}

					foreach ( $changes[ $meta_prop ] as $meta_value ) {
						add_metadata( 'rate_rule', $rule->get_id(), $meta, $meta_value );
					}
				}

				$rule->apply_changes();

				$this->clear_cache( $rule );

				/**
				 * DO_ACTION: yith_wcaf_new_rate_rule
				 *
				 * Allows to trigger some action when a new rate rule is created.
				 *
				 * @param int                 $rate_rule_id Rate rule id.
				 * @param YITH_WCAF_Rate_Rule $rule         Rate rule object.
				 */
				do_action( 'yith_wcaf_new_rate_rule', $rule->get_id(), $rule );
			}
		}

		/**
		 * Method to read a record. Creates a new WC_Data based object.
		 *
		 * @param YITH_WCAF_Rate_Rule $rule Data object.
		 * @throws Exception When rule cannot be retrieved with current information.
		 */
		public function read( &$rule ) {
			global $wpdb;

			$rule->set_defaults();

			$id = $rule->get_id();

			if ( ! $id ) {
				throw new Exception( _x( 'Invalid rate rule.', '[DEV] Debug message triggered when unable to find rate rule record.', 'yith-woocommerce-affiliates' ) );
			}

			$rule_data = $id ? $this->cache_get( 'rate_rule-' . $id ) : false;

			if ( ! $rule_data ) {
				// format query to retrieve rule.
				$query = $wpdb->prepare( "SELECT * FROM {$this->table} WHERE ID = %d", $id );

				// retrieve rule data.
				$rule_data = $wpdb->get_row( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

				if ( $rule_data ) {
					// now also read useful meta, to store them in cache as well.
					$rule_data->metadata = get_metadata( 'rate_rule', $rule_data->ID );

					$this->cache_set( 'rate_rule-' . $rule_data->ID, $rule_data );
				}
			}

			if ( ! $rule_data ) {
				throw new Exception( _x( 'Invalid rate rule.', '[DEV] Debug message triggered when unable to find click record.', 'yith-woocommerce-affiliates' ) );
			}

			$rule->set_id( (int) $rule_data->ID );

			// set rule props.
			foreach ( array_keys( $this->columns ) as $column ) {
				$rule->{"set_{$this->get_column_prop_name( $column )}"}( $rule_data->$column );
			}

			// set rule meta.
			$metadata = isset( $rule_data->metadata ) ? $rule_data->metadata : array();

			if ( $metadata ) {
				foreach ( $this->meta as $meta ) {
					if ( empty( $metadata[ $meta ] ) ) {
						continue;
					}

					$rule->{"set_{$this->get_meta_prop_name( $meta )}"}( $metadata[ $meta ] );
				}
			}

			$rule->set_object_read( true );
		}

		/**
		 * Updates a record in the database.
		 *
		 * @param YITH_WCAF_Rate_Rule $rule Data object.
		 */
		public function update( &$rule ) {
			if ( ! $rule->get_id() ) {
				return;
			}

			$this->update_object( $rule );

			// update metadata.
			$changes = $rule->get_changes();

			foreach ( $this->meta as $meta ) {
				$prop = $this->get_meta_prop_name( $meta );

				if ( ! isset( $changes[ $prop ] ) ) {
					continue;
				}

				delete_metadata( 'rate_rule', $rule->get_id(), $meta );

				if ( ! empty( $changes[ $prop ] ) ) {
					foreach ( $changes[ $prop ] as $meta_value ) {
						add_metadata( 'rate_rule', $rule->get_id(), $meta, $meta_value );
					}
				}
			}

			$rule->apply_changes();

			$this->clear_cache( $rule );

			/**
			 * DO_ACTION: yith_wcaf_update_rate_rule
			 *
			 * Allows to trigger some action when a rate rule is updated.
			 *
			 * @param int                 $rate_rule_id Rate rule id.
			 * @param YITH_WCAF_Rate_Rule $rule         Rate rule object.
			 */
			do_action( 'yith_wcaf_update_rate_rule', $rule->get_id(), $rule );
		}

		/**
		 * Deletes a record from the database.
		 *
		 * @param YITH_WCAF_Rate_Rule $rule Data object.
		 * @param array               $args Not in use.
		 *
		 * @return bool result
		 */
		public function delete( &$rule, $args = array() ) {
			global $wpdb;

			$id = $rule->get_id();

			if ( ! $id ) {
				return false;
			}

			/**
			 * DO_ACTION: yith_wcaf_before_delete_rate_rule
			 *
			 * Allows to trigger some action before deleting a rate rule.
			 *
			 * @param int                 $id   Rate rule id.
			 * @param YITH_WCAF_Rate_Rule $rule Rate rule object.
			 */
			do_action( 'yith_wcaf_before_delete_rate_rule', $id, $rule );

			$this->clear_cache( $rule );

			// delete affiliate.
			$res = $wpdb->delete( $this->table, array( $this->id_column => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( $res ) {
				/**
				 * DO_ACTION: yith_wcaf_delete_rate_rule
				 *
				 * Allows to trigger some action when a rate rule is deleted.
				 *
				 * @param int                 $id   Rate rule id.
				 * @param YITH_WCAF_Rate_Rule $rule Rate rule object.
				 */
				do_action( 'yith_wcaf_delete_rate_rule', $id, $rule );

				// delete meta.
				$wpdb->delete( $this->meta_table, array( 'rate_rule_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

				$rule->set_id( 0 );

				/**
				 * DO_ACTION: yith_wcaf_deleted_rate_rule
				 *
				 * Allows to trigger some action after deleting a rate rule.
				 *
				 * @param int                 $id   Rate rule id.
				 * @param YITH_WCAF_Rate_Rule $rule Rate rule object.
				 */
				do_action( 'yith_wcaf_deleted_rate_rule', $id, $rule );
			}

			return $res;
		}

		/* === QUERY === */

		/**
		 * Return count of rules matching filtering criteria
		 *
		 * @param array $args Filtering criteria (@see \YITH_WCAF_Rate_Rule_Data_Store::query).
		 * @return int Count of matching rules.
		 */
		public function count( $args = array() ) {
			$args['fields'] = 'count';

			return (int) $this->query( $args );
		}

		/**
		 * Return rules matching filtering criteria
		 *
		 * @param array $args Filtering criteria<br/>:
		 *              [<br/>
		 *              'enabled' => 'all',      // rule status (int|bool)<br/>
		 *              'affiliate_id' => false, // rule affiliate id (int)<br/>
		 *              'product_id' => false,   // rule product id (int)<br/>
		 *              'product_cat' => false,  // rule product id (int)<br/>
		 *              'user_role' => false,    // rule user role (int)<br/>
		 *              'type' => false,         // rule type (int)<br/>
		 *              'order' => 'DESC',       // sorting direction (ASC/DESC)<br/>
		 *              'orderby' => 'ID',       // sorting column (any table valid column)<br/>
		 *              'limit' => 0,            // limit (int)<br/>
		 *              'offset' => 0            // offset (int)<br/>
		 *              ].
		 *
		 * @return YITH_WCAF_Rate_Rules_Collection|string[]|int|bool Matching clicks, or clicks count
		 */
		public function query( $args = array() ) {
			global $wpdb;

			$defaults = array(
				'enabled'      => 'all',
				'affiliate_id' => false,
				'product_id'   => false,
				'product_cat'  => false,
				'user_role'    => false,
				'type'         => false,
				'order'        => 'ASC',
				'orderby'      => 'priority',
				'limit'        => 0,
				'offset'       => 0,
				'fields'       => '',
			);

			$args = wp_parse_args( $args, $defaults );

			// checks if we're performing a count query.
			$is_counting = ! empty( $args['fields'] ) && 'count' === $args['fields'];

			// retrieve data from cache, when possible.
			$res = $this->cache_get( $this->get_versioned_cache_key( 'query', $args ) );

			// if no data found in cache, query database.
			if ( false === $res ) {
				$query      = "SELECT yrr.*
					FROM {$this->table} AS yrr";
				$query_args = array();

				if ( $is_counting ) {
					$query = "SELECT COUNT(*)
						FROM {$this->table} AS yrr";
				}

				// append clauses to the query.
				$query .= $this->generate_query_where_clause( $args, $query_args );
				$query .= $this->generate_query_orderby_clause( $args, $query_args );
				$query .= $this->generate_query_limit_clause( $args, $query_args );

				if ( ! empty( $query_args ) ) {
					$query = $wpdb->prepare( $query, $query_args ); // phpcs:ignore WordPress.DB
				}

				if ( $is_counting ) {
					$res = (int) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB
				} else {
					$res = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB
				}

				$this->cache_set( $this->get_versioned_cache_key( 'query', $args ), $res );
			}

			// if we're counting, return count found.
			if ( $is_counting ) {
				return $res;
			}

			// if we have an empty set from db, return empty array/collection and skip next steps.
			if ( ! $res ) {
				return empty( $args['fields'] ) ? new YITH_WCAF_Rate_Rules_Collection() : array();
			}

			$ids = array_map( 'intval', wp_list_pluck( $res, 'ID' ) );

			if ( ! empty( $args['fields'] ) ) {
				// extract required field.
				$indexed = 0 === strpos( $args['fields'], 'id=>' );
				$field   = $indexed ? substr( $args['fields'], 4 ) : $args['fields'];
				$field   = 'ids' === $field ? 'ID' : $field;

				$res = wp_list_pluck( $res, $field );

				if ( $indexed ) {
					$res = array_combine( $ids, $res );
				}
			} else {
				// or get the complete click object.
				$res = new YITH_WCAF_Rate_Rules_Collection( $ids, $this->get_pagination_data( $args ) );
			}

			return $res;
		}

		/**
		 * Generates where clause for the query, given a set of arguments
		 *
		 * @param array $args       Array of query arguments.
		 * @param array $query_args Array of parameters to build up into the query (reference).
		 * @return string Where clause.
		 */
		protected function generate_query_where_clause( $args = array(), array &$query_args = array() ) {
			$where = ' WHERE 1 = 1';

			// if at least one meta is specified in the args, run sub-queries, to intersect results for each meta.
			$meta_conditions = $this->meta ? array_intersect( $this->meta, array_keys( array_filter( $args ) ) ) : array();

			foreach ( $meta_conditions as $meta_key ) {
				$matching_ids = $this->get_rule_ids_by_meta_query( $meta_key, $args[ $meta_key ] );

				if ( empty( $matching_ids ) ) {
					$query_args = array();
					return ' WHERE 1 = 0';
				}

				$query_part = trim( str_repeat( '%d, ', count( $matching_ids ) ), ', ' );

				$where     .= ' AND yrr.ID IN ( ' . $query_part . ' )';
				$query_args = array_merge( $query_args, $matching_ids );
			}

			if ( isset( $args['enabled'] ) && 'all' !== $args['enabled'] ) {
				$where       .= ' AND yrr.enabled = %d';
				$query_args[] = (int) ! ! $args['enabled'];
			}

			if ( ! empty( $args['type'] ) ) {
				$where       .= ' AND yrr.type = %s';
				$query_args[] = $args['type'];
			}

			return $where;
		}

		/* === UTILITIES === */

		/**
		 * Returns table structure to be used in dbDelta.
		 *
		 * @return string
		 */
		public function get_table_structure() {
			global $wpdb;

			$charset_collate = $wpdb->get_charset_collate();

			return "CREATE TABLE $this->table (
                    ID bigint(20) NOT NULL AUTO_INCREMENT,
                    name varchar(255) NOT NULL DEFAULT '',
                    enabled tinyint(1) NOT NULL DEFAULT 1,
                    rate double(9,3) NOT NULL,
                    type varchar(255) NOT NULL DEFAULT '',
                    priority int(5) NOT NULL DEFAULT 0,
                    PRIMARY KEY (ID),
                    KEY rule_type (type)
				) $charset_collate;
				CREATE TABLE $this->meta_table (
                    meta_id bigint(20) NOT NULL AUTO_INCREMENT,
                    rate_rule_id bigint(20) NOT NULL,
                    meta_key varchar(255) NOT NULL DEFAULT '',
                    meta_value longtext NOT NULL DEFAULT '',
                    PRIMARY KEY (meta_id),
                    KEY external_rule (rate_rule_id),
                    KEY object_type (meta_key)
				) $charset_collate;";
		}

		/**
		 * Update priorities of the rules, as specified by the input array
		 *
		 * @param array $priorities Array of priorities, formatted as follows: [ rule_id => priority, ... ].
		 */
		public function update_priorities( $priorities ) {
			global $wpdb;

			foreach ( $priorities as $rule_id => $priority ) {
				$wpdb->update( $this->table, array( 'priority' => $priority ), array( 'ID' => $rule_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		/**
		 * Return max priority registered in the rules table
		 *
		 * @return int Maximum priority value.
		 */
		protected function get_max_priority() {
			global $wpdb;

			$query = "SELECT MAX( yrr.priority ) FROM {$this->table} AS yrr";
			$max   = (int) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

			return $max;
		}

		/**
		 * Returns id of rate rules matching a specific meta query
		 *
		 * @param string $meta_key   Meta key.
		 * @param string $meta_value Meta value; if passed value is empty, method will return array of rules that don't have specified meta ky.
		 *
		 * @return int[] Array of rules id.
		 */
		protected function get_rule_ids_by_meta_query( $meta_key, $meta_value ) {
			global $wpdb;

			if ( ! in_array( $meta_key, $this->meta, true ) ) {
				return array();
			}

			if ( ! empty( $meta_value ) ) {
				$meta_value = (array) $meta_value;
				$where_list = trim( str_repeat( '%s, ', count( $meta_value ) ), ', ' );
				$query      = "SELECT rate_rule_id FROM {$this->meta_table} WHERE meta_key = %s AND meta_value IN ( $where_list )";
				$query_args = array_merge( (array) $meta_key, $meta_value );
			} else {
				$query      = "SELECT rate_rule_id FROM {$this->meta_table} WHERE rate_rule_id NOT IN ( SELECT rate_rule_id FROM {$this->meta_table} WHERE meta_key = %s )";
				$query_args = array( $meta_key );
			}

			$rule_ids_per_meta = $wpdb->get_col( $wpdb->prepare( $query, $query_args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

			return $rule_ids_per_meta;
		}

		/**
		 * Get property name for a meta
		 *
		 * @param string $meta Meta to search for.
		 *
		 * @return string Property name.
		 */
		protected function get_meta_prop_name( $meta ) {
			$meta_to_props = array_flip( $this->props_to_meta );

			if ( ! isset( $meta_to_props[ $meta ] ) ) {
				return $meta;
			}

			return $meta_to_props[ $meta ];
		}

		/**
		 * Clear rule related caches
		 *
		 * @param \YITH_WCAF_Rate_Rule|int $rule Rule object or rule id.
		 *
		 * @return void
		 */
		public function clear_cache( $rule ) {
			$rule = YITH_WCAF_Rate_Rule_Factory::get_rule( $rule );

			if ( ! $rule || ! $rule->get_id() ) {
				return;
			}

			$this->cache_delete( 'rate_rule-' . $rule->get_id() );
			$this->invalidate_versioned_cache();
		}
	}
}
