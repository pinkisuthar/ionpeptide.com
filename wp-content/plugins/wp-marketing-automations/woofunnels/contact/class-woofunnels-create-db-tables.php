<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Class WooFunnels_Create_DB_Tables
 */

if ( ! class_exists( 'WooFunnels_Create_DB_Tables' ) ) {
	#[AllowDynamicProperties]
	class WooFunnels_Create_DB_Tables {

		/**
		 * instance of class
		 * @var null
		 */
		private static $ins = null;
		/**
		 * Charector collation
		 *
		 * @since 2.0
		 *
		 * @var string
		 */
		protected $charset_collate;

		/**
		 * @var bool
		 */
		protected $last_created_table = array();

		/**
		 * WooFunnels_DB_Tables constructor.
		 */
		public function __construct() {
			global $wpdb;
			if ( $wpdb->has_cap( 'collation' ) ) {
				$this->charset_collate = $wpdb->get_charset_collate();
			}

		}

		/**
		 * @return WooFunnels_Create_DB_Tables|null
		 */
		public static function get_instance() {
			if ( null === self::$ins ) {
				self::$ins = new self;
			}

			return self::$ins;
		}

		/**
		 * @hooked over `admin_head`
		 * This method create new tables in database except core table
		 */
		public function create() {
			$current_table_list = get_option( '_bwf_db_table_list', array( 'tables' => array(), 'version' => '0.0.0' ) );
			$tables             = apply_filters( 'bwf_add_db_table_schema', array(), $current_table_list );

			global $wpdb;

			if ( is_array( $tables ) && count( $tables ) > 0 ) {
				/** dbDelta lives here — only parse wp-admin/includes/upgrade.php when there is actually schema to apply */
				require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
				foreach ( $tables as $table ) {
					$schema = $table['schema'];
					$schema = str_replace( array( '{table_prefix}', '{table_collate}' ), array( $wpdb->prefix, $this->charset_collate ), $schema );

					dbDelta( $schema );

					/**
					 * Handle unique key error
					 * if table exists and try re-attempt to create table
					 */
					$table_name     = $wpdb->prefix . $table['name'];
					$esc_unique_key = "ALTER TABLE {$table_name} ADD UNIQUE KEY";
					if ( ! empty( $wpdb->last_error ) && ( strpos( $wpdb->last_query, $esc_unique_key ) === false ) ) {
						BWF_Logger::get_instance()->log( "bwf failed create table {$table['name']}: " . print_r( $wpdb->last_error, true ), 'woofunnel-failed-actions', 'buildwoofunnels', true ); //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
					} else {
						$this->last_created_table[]     = $table['name'];
						$current_table_list['tables'][] = $table['name'];
					}
				}

				$current_table_list['version'] = BWF_DB_VERSION;

				if ( isset( $current_table_list['tables'] ) && is_array( $current_table_list['tables'] ) ) {
					$current_table_list['tables'] = array_values( array_unique( $current_table_list['tables'] ) );
				}
				update_option( '_bwf_db_table_list', $current_table_list, true );

			} else {

				if ( isset( $current_table_list['tables'] ) && is_array( $current_table_list['tables'] ) ) {
					/**
					 * Check if array of table has duplicate values
					 */
					$current = array_values( array_unique( $current_table_list['tables'] ) );
					if ( sizeof( $current ) !== sizeof( $current_table_list['tables'] ) ) {
						$current_table_list['tables'] = $current;
						update_option( '_bwf_db_table_list', $current_table_list, true );
					}
				}
			}
		}

		/**
		 * get tables list which is created in current version
		 * @return array|bool
		 */
		public function maybe_table_created_current_version() {
			return $this->last_created_table;
		}
	}
}
