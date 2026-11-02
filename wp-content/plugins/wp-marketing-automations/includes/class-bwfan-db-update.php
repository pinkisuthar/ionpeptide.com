<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class BWFAN_DB_Update
 *
 * @package Autonami
 *
 * @since 3.0.0
 */
#[AllowDynamicProperties]
class BWFAN_DB_Update {
	private static $ins = null;

	public $db_changes = [];

	/**
	 * 0 - Null | nothing to do
	 * 1 - DB update can start
	 * 2 - DB update started
	 * 3 - DB update complete
	 */

	/**
	 * Class constructor
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'db_update' ], 11 );
		add_action( 'bwfan_db_update_2_11', array( $this, 'db_update_2_11_cb' ) );
		add_action( 'bwfan_db_update_3_6_5', array( $this, 'db_update_3_6_5_cb' ) );
		add_action( 'bwfan_reindex_cart_conversions_base_total', array( $this, 'reindex_cart_conversion_base_total' ) );

		$this->db_changes = array(
			'2.11' => '2_11',
			'3.6.5' => '3_6_5',
		);
	}

	/**
	 * Return the object of current class
	 *
	 * @return null|BWFAN_DB_Update
	 */
	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	/**
	 * Update pro db function for setting value
	 */
	public function db_update() {
		$db_status  = $this->get_saved_data( 'status' );
		$db_version = $this->get_saved_data() ?? false;
		$db_version = ( false === $db_version ) ? '2.8.4' : $db_version;

		/** Status 1 = ready for run, 2 = in progress, 3 = complete */
		if ( in_array( $db_status, [ 1, 2, 3 ] ) ) {
			return;
		}

		foreach ( $this->db_changes as $version => $version_value ) {
			if ( version_compare( $db_version, $version, '<' ) ) {
				$value = [ $version => 1 ];

				/** Should run or not */
				if ( method_exists( $this, 'should_run_' . $version_value ) && false === call_user_func( [ $this, 'should_run_' . $version_value ] ) ) {
					$value = [ $version => 0 ];
				}

				update_option( 'bwfan_db_update', $value, true );

				return;
			}
		}
	}

	/**
	 * Return version or status from the DB saved value
	 *
	 * @param string $type
	 *
	 * @return false|int|mixed|string|null
	 */
	public function get_saved_data( $type = 'version' ) {
		$data = get_option( 'bwfan_db_update', [] );

		if ( ! is_array( $data ) ) {
			return ( 'version' === $type ) ? false : 0;
		}

		/** Return version */
		if ( 'version' === $type ) {
			return key( $data );
		}

		$status = (int) current( $data );

		/** If status is 2 (in processing) then check if action is scheduled  */
		if ( 2 === $status ) {
			$this->is_action_scheduled( $data );
		}

		/** Return status */
		return $status;
	}

	/**
	 * Schedule DB update action
	 *
	 * @return bool
	 */
	public function start_db_update() {
		/** Status */
		$status = $this->get_saved_data( 'status' );
		if ( 0 === $status ) {
			return false;
		}

		/** Check if already scheduled */
		if ( in_array( $status, [ 2, 3 ] ) ) {
			return true;
		}

		/** Version */
		$version = $this->get_saved_data();

		/** Schedule recurring action */
		$this->schedule_action( $version );

		return true;
	}

	/**
	 * Set the DB update current version value to 0
	 *
	 * @return bool
	 */
	public function dismiss_db_update() {
		/** Version */
		$version = $this->get_saved_data();
		if ( false === $version ) {
			return false;
		}

		return update_option( 'bwfan_db_update', [ $version => 0 ], true );
	}

	/**
	 * Mark version complete and check for next DB update.
	 * If available then start it
	 *
	 * @param $version_no
	 */
	protected function mark_complete( $version_no ) {
		$version_name = str_replace( ".", "_", $version_no );
		BWFAN_Core()->logger->log( 'mark complete: ' . $version_no, 'db_update_' . $version_name );

		/** Mark complete */
		update_option( 'bwfan_db_update', [ $version_no => 3 ], true );

		/** Un-schedule action */
		$version_name = str_replace( ".", "_", $version_no );
		bwf_unschedule_actions( 'bwfan_db_update_' . $version_name );

		/** Maybe schedule next version */
		if ( ! is_array( $this->db_changes ) || 0 === count( $this->db_changes ) ) {
			return;
		}
		foreach ( $this->db_changes as $version => $version_value ) {
			if ( version_compare( $version_no, $version, '<' ) ) {
				/** Schedule recurring action */
				$this->schedule_action( $version );

				return;
			}
		}
	}

	/**
	 * Schedule recurring action
	 *
	 * @param $version
	 */
	protected function schedule_action( $version ) {
		if ( empty( $version ) ) {
			return false;
		}

		/** Mark DB update started */
		update_option( 'bwfan_db_update', [ $version => 2 ], true );

		$version_name = str_replace( ".", "_", $version );
		$action       = 'bwfan_db_update_' . $version_name;
		$args         = array( 'datetime' => current_time( 'mysql', 1 ) );

		/** Check if action is already scheduled */
		if ( ! bwf_has_action_scheduled( $action, $args, 'bwf_update' ) ) {
			bwf_schedule_recurring_action( time(), 60, $action, $args, 'bwf_update' );

			BWFAN_Core()->logger->log( 'scheduling action: ' . $version, 'db_update_' . $version_name );
		}

		return true;
	}

	/**
	 * Check if time limit or memory passed
	 *
	 * @param $time
	 *
	 * @return bool
	 */
	protected function should_run( $time ) {
		/** If time exceeds */
		if ( ( time() - $time ) > $this->get_threshold_time() ) {
			return false;
		}

		/** If memory exceeds */
		return ! BWFAN_Common::memory_exceeded();
	}

	/**
	 * Return call duration time in seconds
	 *
	 * @return mixed|void
	 */
	protected function get_threshold_time() {
		return apply_filters( 'bwfan_db_update_call_duration', 20 );
	}

	public function db_update_2_11_cb() {

		global $wpdb;
		$start_time = time();
		$key        = 'bwfan_db_update_2_11_cb';
		BWFAN_Core()->logger->log( 'call starts for: 2.11', 'db_update_2_11' );

		$post_statuses = apply_filters( 'bwfan_recovered_cart_excluded_statuses', array(
			'wc-pending',
			'wc-failed',
			'wc-cancelled',
			'wc-refunded',
			'trash',
			'draft'
		) );
		$post_statuses = is_array( $post_statuses ) ? esc_sql( $post_statuses ) : $post_statuses; // filter output is placed inside quoted SQL literals
		$post_statuses = implode( "','", $post_statuses );

		/** Get if conversion table is empty */
		$is_conversion_empty = bwf_options_get( 'is_conversion_empty' );
		if ( empty( $is_conversion_empty ) ) {
			$is_conversion_empty = BWFAN_Common::is_conversion_empty();
			bwf_options_update( 'is_conversion_empty', $is_conversion_empty );
		}

		do {
			$last_order_id = bwf_options_get( 'last_index_order' );
			$last_order    = intval( $last_order_id ) > 0 ? " AND p.`id` > " . intval( $last_order_id ) . " " : "";

			if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
				$query = "SELECT p.id AS id FROM {$wpdb->prefix}wc_orders as p LEFT JOIN {$wpdb->prefix}wc_orders_meta as m ON p.id = m.order_id WHERE p.type = 'shop_order' AND p.status NOT IN ('$post_statuses') AND m.meta_key = '_bwfan_ab_cart_recovered_a_id' $last_order ORDER BY p.`id` ASC LIMIT 10";
			} else {
				$query = "SELECT p.ID as id FROM {$wpdb->prefix}posts as p LEFT JOIN {$wpdb->prefix}postmeta as m ON p.ID = m.post_id WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ('$post_statuses') AND m.meta_key = '_bwfan_ab_cart_recovered_a_id' $last_order ORDER BY p.`id` ASC LIMIT 10";
			}

			$order_ids = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared
			if ( ! is_array( $order_ids ) || 0 === count( $order_ids ) ) {
				bwf_options_delete( 'last_index_order' );
				bwf_options_delete( 'is_conversion_empty' );
				delete_option( $key );
				$this->mark_complete( '2.11' );

				return;
			}
			$order_id = 0;
			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof WC_Order ) {
					continue;
				}

				/** Check order is already exists in table if table is not empty */
				if ( ! $is_conversion_empty ) {
					$already_exists = BWFAN_Model_Conversions::get_specific_rows( 'wcid', $order_id );
					if ( ! empty( $already_exists ) ) {
						continue;
					}
				}

				$cid = BWF_WC_Compatibility::get_order_meta( $order, '_woofunnel_cid' );
				$oid = BWF_WC_Compatibility::get_order_meta( $order, '_bwfan_ab_cart_recovered_a_id' );

				$data = array(
					'wcid'    => intval( $order_id ),
					'cid'     => intval( $cid ),
					'oid'     => intval( $oid ),
					'otype'   => 1,
					'wctotal' => $order->get_total(),
					'date'    => $order->get_date_created()->date( 'Y-m-d H:i:s' )
				);
				BWFAN_Model_Conversions::insert( $data );
			}
			/** Save last order id to fetch next orders ids */
			bwf_options_update( 'last_index_order', $order_id );
			BWFAN_Core()->logger->log( 'updated: ' . implode( ', ', $order_ids ), 'db_update_2_11' );
		} while ( $this->should_run( $start_time ) ); // keep going until we run out of time, or memory

	}

	/**
	 * Check DB upgrade action scheduler is scheduled or not
	 *
	 * @return void
	 */
	public function is_action_scheduled( $versions ) {
		if ( empty( $versions ) ) {
			return;
		}

		foreach ( $versions as $version => $status ) {
			if ( 2 !== intval( $status ) ) {
				continue;
			}

			if ( ! in_array( $version, $this->db_changes, true ) ) {
				delete_option( 'bwfan_db_update' );
				break;
			}

			$version_name = str_replace( ".", "_", $version );
			$action       = 'bwfan_db_update_' . $version_name;

			/** Check if action is already scheduled */
			if ( ! bwf_has_action_scheduled( $action ) ) {
				$args = array( 'datetime' => current_time( 'mysql', 1 ) );
				bwf_schedule_recurring_action( time(), 60, $action, $args, 'bwf_update' );
			}
		}
	}

	/**
	 * Re-index cart and conversion table base total
	 *
	 * @return void
	 */
	public function reindex_cart_conversion_base_total() {
		global $wpdb;
		$start_time = time();

		do {
			$data_type         = bwf_options_get( 're_index_data_type' );
			$last_processed_id = bwf_options_get( 'last_index_id' );

			/** Fetch rows from conversion table  */
			if ( 'conversion' === $data_type ) {
				$last_id = intval( $last_processed_id ) > 0 ? " AND `ID` > " . intval( $last_processed_id ) . " " : "";
				$query   = "SELECT `ID`, `wctotal` AS total, `wcid` FROM `{$wpdb->prefix}bwfan_conversions` WHERE 1=1 $last_id ORDER BY `ID` ASC LIMIT 0,30";

				$result = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prefix table; last id intval()ed; rest hardcoded
				if ( is_array( $result ) && count( $result ) > 0 ) {
					$last_id = 0;
					foreach ( $result as $data ) {
						$order_id = isset( $data['wcid'] ) ? $data['wcid'] : 0;
						if ( empty( $order_id ) ) {
							continue;
						}
						$id          = $data['ID'];
						$saved_price = isset( $data['total'] ) ? $data['total'] : 0;
						$order       = wc_get_order( $order_id );
						$order_total = $order instanceof WC_Order ? $order->get_total() : 0;
						if ( empty( $order_total ) && empty( $saved_price ) ) {
							$last_id = $id;
							continue;
						}

						$currency = $order instanceof WC_Order ? $order->get_currency() : '';
						if ( ! empty( $currency ) && ! empty( $order_total ) ) {
							$order_total = BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $order_total, $currency );
						}

						$wpdb->update( $wpdb->prefix . 'bwfan_conversions', [ 'wctotal' => $order_total ], array( 'ID' => $id ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$last_id = $id;
					}

					bwf_options_update( 'last_index_id', $last_id );
					continue;
				} else {
					/** cart table will process now */
					$last_processed_id = 0;
					bwf_options_update( 'last_index_id', 0 );
					bwf_options_update( 're_index_data_type', 'cart' );
				}
			}

			/** No conversion rows are left, fetch rows from cart table */
			$last_id = intval( $last_processed_id ) > 0 ? " AND `ID` > " . intval( $last_processed_id ) . " " : "";
			$query   = "SELECT `ID`, `total`, `currency` FROM `{$wpdb->prefix}bwfan_abandonedcarts` WHERE 1=1 $last_id ORDER BY `ID` ASC LIMIT 0,30";

			$result = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prefix table; last id intval()ed; rest hardcoded
			if ( is_array( $result ) && count( $result ) > 0 ) {
				$last_id = 0;
				foreach ( $result as $data ) {
					$id          = $data['ID'];
					$saved_price = isset( $data['total'] ) ? $data['total'] : 0;
					if ( empty( $saved_price ) ) {
						$last_id = $id;
						continue;
					}
					$currency    = isset( $data['currency'] ) ? $data['currency'] : '';
					$order_total = 0;
					if ( ! empty( $currency ) ) {
						$order_total = BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $saved_price, $currency );
					}

					$wpdb->update( $wpdb->prefix . 'bwfan_abandonedcarts', [ 'total_base' => $order_total ], array( 'ID' => $id ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$last_id = $id;
				}

				bwf_options_update( 'last_index_id', $last_id );
				continue;
			}

			/** No cart rows to process */
			bwf_options_delete( 're_index_data_type' );
			bwf_options_delete( 'last_index_id' );

			bwf_unschedule_actions( 'bwfan_reindex_cart_conversions_base_total' );
			break;
		} while ( $this->should_run( $start_time ) ); // keep going until we run out of time, or memory
	}

	/**
	 * Database update callback to add template_hash column and populate existing records
	 *
	 * @return void
	 */
	public function db_update_3_6_5_cb() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'bwfan_templates';

		/** Check if column already exists */
		$column_exists = $wpdb->get_row( "SHOW COLUMNS FROM {$table_name} LIKE 'template_hash'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; hardcoded DDL

		if ( empty( $column_exists ) ) {
			/** Add template_hash column */
			$query = "ALTER TABLE {$table_name} ADD COLUMN `template_hash` varchar(40) default NULL, ADD KEY `template_hash` (`template_hash`)";
			$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; hardcoded DDL

			if ( ! empty( $wpdb->last_error ) ) {
				BWFAN_Common::log_test_data ( 'Error adding template_hash column: ' . $wpdb->last_error, 'db_update_3_6_5', true );

				$this->mark_complete( '3.6.5' );
				return;
			}
		}
		$start_time      = time();
		$global_settings = get_option( 'bwfan_global_settings', array() );
		/** Populate template_hash for existing records that don't have it */
		do {
			$last_id = bwf_options_get( 'last_template_hash_id' );
			$last_id = intval( $last_id );

			if ( $last_id > 0 ) {
				$query = $wpdb->prepare( "SELECT `ID`, `template` FROM {$table_name} WHERE (`template_hash` IS NULL OR `template_hash` = '') AND `template` IS NOT NULL AND `template` != '' AND `ID` > %d ORDER BY `ID` ASC LIMIT 50", $last_id ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix table; id bound via prepare() or literal query
			} else {
				$query = "SELECT `ID`, `template` FROM {$table_name} WHERE (`template_hash` IS NULL OR `template_hash` = '') AND `template` IS NOT NULL AND `template` != '' ORDER BY `ID` ASC LIMIT 50"; //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}
			$templates = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- prefix table; id bound via prepare() or literal query

			if ( ! is_array( $templates ) || 0 === count( $templates ) ) {
				bwf_options_delete( 'last_template_hash_id' );
				$this->mark_complete( '3.6.5' );

				/** Added a flag to the global settings to indicate that the database update is complete */
				$global_settings['__db_update_3_6_5_completed'] = true;
				update_option( 'bwfan_global_settings', $global_settings );

				BWFAN_Common::log_test_data( 'template_hash population complete','db_update_3_6_5', false );
				return;
			}

			$last_processed_id = 0;
			foreach ( $templates as $template ) {
				$template_hash = sha1( $template['template'] );
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off DB upgrade on the plugin templates table; not cacheable
					$table_name,
					[ 'template_hash' => $template_hash ],
					[ 'ID' => $template['ID'] ],
					[ '%s' ],
					[ '%d' ]
				); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				if ( ! empty( $wpdb->last_error ) ) {
					BWFAN_Common::log_test_data('Error updating template_hash for ID '.$template['ID'].': '.$wpdb->last_error,'db_update_3_6_5',true);
				}

				$last_processed_id = $template['ID'];
			}

			/** Save last processed ID */
			bwf_options_update( 'last_template_hash_id', $last_processed_id );
			BWFAN_Common::log_test_data('Updated template_hash for '.count( $templates ).' templates, last ID: '.$last_processed_id,'db_update_3_6_5',false);

		} while ( $this->should_run( $start_time ) ); // keep going until we run out of time, or memory
	}
}

BWFAN_DB_Update::get_instance();
