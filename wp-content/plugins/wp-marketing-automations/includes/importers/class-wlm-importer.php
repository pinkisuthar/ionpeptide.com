<?php

namespace BWFAN\Importers;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_User;

/**
 * Class WLM_Importer
 *
 * This class represents a Wishlist Member importer for the CRM system.
 */
#[\AllowDynamicProperties]
class WLM_Importer extends Importer {
	const LIMIT = 25;
	public static $LEVEL_STATUS_UNCONFIRMED = 1;
	public static $LEVEL_STATUS_ACTIVE = 2;
	public static $LEVEL_STATUS_PENDING = 3;
	public static $LEVEL_STATUS_CANCELLED = 4;
	public static $LEVEL_STATUS_SCHEDULED = 5;
	public static $LEVEL_STATUS_EXPIRED = 6;

	protected $import_type = 'wlm';

	public function __construct( $params = array() ) {
		$this->slug        = 'wlm';
		$this->name        = __( 'Wishlist Member', 'wp-marketing-automations' );
		$this->description = __( 'Import contacts from Wishlist Member', 'wp-marketing-automations' );
		$this->logo_url    = esc_url( plugin_dir_url( BWFAN_PLUGIN_FILE ) . '/admin/assets/img/importer/wlm.png' );
		$this->has_fields  = false;
		$this->group       = 0;
		$this->priority    = 10;
		parent::__construct( $params );

		$this->maybe_create_wlm_table();
	}

	private static function _table() {
		/** @var wpdb $wpdb */ global $wpdb;

		return $wpdb->prefix . 'bwf_contact_wlm_fields';
	}

	/**
	 * Prepares the import data for creating a new import.
	 *
	 * @param array $import_data The import data.
	 *
	 * @return array The modified import data.
	 */
	public function prepare_create_import_data( $import_data = array(), $fields = array() ) {
		$import_data['count'] = $this->get_contacts_count();

		return $import_data;
	}

	/**
	 * Prepare the data for importing users.
	 */
	public function populate_contact_data() {
		/** Fetch members user ids */
		$user_ids = $this->get_members( $this->get_offset(), self::LIMIT );

		if ( empty( $user_ids ) ) {
			return [];
		}

		$users = get_users( array(
			'include' => $user_ids,
		) );

		$contacts = [];
		if ( ! empty( $users ) ) {
			foreach ( $users as $user ) {
				$contacts[ intval( $user->ID ) ] = $user;
			}
		}

		if ( count( $contacts ) !== count( $user_ids ) ) {
			foreach ( $user_ids as $user_id ) {
				if ( isset( $contacts[ $user_id ] ) ) {
					continue;
				}
				$contacts[ $user_id ] = [];
			}
		}
		krsort( $contacts, 1 );

		return $contacts;
	}

	/**
	 * Process an item during the import process.
	 *
	 * @param mixed $data The data to be processed.
	 *
	 * @return mixed The result of the processing.
	 */
	public function process_item( $data = null ) {
		$return = parent::process_item( $data );

		if ( is_wp_error( $return ) ) {
			return $return;
		}

		if ( $data instanceof \WP_User ) {
			$this->import_wlm_member( $return['id'], $data->ID );
		}

		return $return;
	}

	/**
	 * Retrieves members from the Wishlist Member integration.
	 *
	 * @param int $offset The offset for retrieving members.
	 * @param int $limit The maximum number of members to retrieve.
	 *
	 * @return array The member IDs.
	 */
	public function get_members( $offset, $limit ) {
		global $wpdb;

		$query = $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->prefix}wlm_userlevels ORDER BY user_id DESC LIMIT %d, %d", $offset, $limit );

		$members = $wpdb->get_col( $query ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

		return array_map( 'absint', $members );
	}

	/**
	 * Retrieves the count of members from the Wishlist Member integration.
	 *
	 * @return int The count of members.
	 */
	public function get_contacts_count() {
		global $wpdb;

		$count = $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}wlm_userlevels" ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

		return ! empty( $count ) ? absint( $count ) : 0;
	}

	/**
	 * Checks if the WLM table needs to be created and performs necessary actions.
	 */
	public function maybe_create_wlm_table() {
		if ( get_option( 'bwfan_wlm_table_created' ) ) {
			return;
		}
		$this->maybe_create_db_table();
		$this->maybe_drop_unwanted_columns();
		$this->truncate();
		update_option( 'bwfan_wlm_table_created', 1, true );
	}

	/**
	 * Imports a member from Wishlist Member into the CRM.
	 *
	 * @param int $contact_id The ID of the contact in the CRM.
	 * @param int $user_id The ID of the user in Wishlist Member.
	 *
	 * @return void
	 */
	public function import_wlm_member( $contact_id, $user_id ) {
		$levels = wlmapi_get_member_levels( $user_id );
		if ( ! is_array( $levels ) || empty( $levels ) ) {
			return;
		}

		$status = array();
		$reg    = array();
		$exp    = array();
		foreach ( $levels as $id => $level ) {
			$status[ absint( $id ) ] = strval( $this->get_status_code_from_api( $level ) );
			$reg[ absint( $id ) ]    = gmdate( 'Y-m-d H:i:s', absint( $level->Timestamp ) );
			if ( ! empty( $level->ExpiryDate ) ) {
				$exp[ absint( $id ) ] = gmdate( 'Y-m-d H:i:s', absint( $level->ExpiryDate ) );
			}
		}

		$this->insert( $contact_id, $status, $reg, $exp );
	}

	/** WLM Deep Integration Table Functions */
	public function maybe_create_db_table() {
		$levels = $this->get_levels();
		$table  = self::_table();
		if ( empty( $levels ) ) {
			return false;
		}

		$level_ids = array_keys( $levels );
		$db_cols   = array_map( function ( $id ) {
			return "reg_{$id} datetime default NULL,
                exp_{$id} datetime default NULL";
		}, $level_ids );

		/** Used 'Enter Key' after ',' because dbDelta recognise one line as a column */
		$db_cols = implode( ',
		', $db_cols );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;

		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		$creationSQL = "CREATE TABLE {$wpdb->prefix}$table (
			id bigint(20) unsigned NOT NULL auto_increment,
			cid bigint(20) unsigned NOT NULL default 0,
			status varchar(255) NOT NULL,
			$db_cols,
			PRIMARY KEY (id),			
			KEY cid (cid)
		) $collate;";

		dbDelta( $creationSQL );

		return true;
	}

	/** WLM Helper Methods */
	public function get_levels( $slim_data = true ) {
		$levels = wlmapi_get_levels();
		if ( ! is_array( $levels ) ) {
			return array();
		}

		if ( ! isset( $levels['levels'] ) || ! isset( $levels['levels']['level'] ) || ! is_array( $levels['levels']['level'] ) ) {
			return array();
		}

		$levels = $levels['levels']['level'];

		$return = array();
		foreach ( $levels as $level ) {
			$return[ absint( $level['id'] ) ] = $slim_data ? $level['name'] : $level;
			if ( ! $slim_data ) {
				$level_link = admin_url();
				$level_link = add_query_arg( array(
					'page'     => 'WishListMember',
					'wl'       => 'setup/levels',
					'level_id' => $level['id'] . '#levels_access-' . $level['id'],
				), $level_link );

				$return[ absint( $level['id'] ) ]['link'] = $level_link;
			}
		}

		return $return;
	}

	public function maybe_drop_unwanted_columns() {
		$levels = $this->get_levels();
		if ( empty( $levels ) ) {
			return false;
		}

		global $wpdb;

		$table = self::_table();

		// Sanitize table name to prevent SQL injection
		$table_safe = esc_sql( $table );
		$columns    = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}{$table_safe}", ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

		$default_cols = array( 'id', 'cid', 'status' );
		$levels       = array_keys( $levels );

		$cols_to_drop = array();
		foreach ( $columns as $column ) {
			$col = $column['Field'];
			if ( in_array( $col, $default_cols ) ) {
				continue;
			}

			/** Strip reg_ */
			if ( false !== strpos( $col, 'reg_' ) ) {
				$col = explode( 'reg_', $col )[1];
			}

			/** Strip exp_ */
			if ( false !== strpos( $col, 'exp_' ) ) {
				$col = explode( 'exp_', $col )[1];
			}

			/** if not an level, mark it as drop */
			if ( ! in_array( $col, $levels ) ) {
				$cols_to_drop[] = $column['Field'];
			}
		}

		// Sanitize column names to prevent SQL injection
		$cols_to_drop = array_map( function ( $col ) {
			// Sanitize column name - only allow alphanumeric, underscore, and hyphen
			$col = preg_replace( '/[^a-zA-Z0-9_-]/', '', $col );
			return "DROP COLUMN `" . esc_sql( $col ) . "`";
		}, $cols_to_drop );

		if ( empty( $cols_to_drop ) ) {
			return false;
		}

		$cols_to_drop = implode( ',', $cols_to_drop );

		// Sanitize table name
		$table_safe = esc_sql( $table );
		$query      = "ALTER TABLE {$wpdb->prefix}{$table_safe} " . $cols_to_drop;
		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table hardcoded; column names regex-sanitized from SHOW COLUMNS

		return true;
	}

	public static function truncate() {
		/** @var wpdb $wpdb */ global $wpdb;

		$table = self::_table();

		// Sanitize table name to prevent SQL injection
		$table_safe = esc_sql( $table );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table_safe}" ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
	}

	public function get_status_code_from_api( $api_level ) {
		if ( ! is_object( $api_level ) ) {
			return false;
		}

		if ( isset( $api_level->Active ) && 1 === absint( $api_level->Active ) ) {
			return self::$LEVEL_STATUS_ACTIVE;
		}

		if ( isset( $api_level->Expired ) && 1 === absint( $api_level->Expired ) ) {
			return self::$LEVEL_STATUS_EXPIRED;
		}

		if ( isset( $api_level->Cancelled ) && 1 === absint( $api_level->Cancelled ) ) {
			return self::$LEVEL_STATUS_CANCELLED;
		}

		if ( isset( $api_level->Pending ) && 1 === absint( $api_level->Pending ) ) {
			return self::$LEVEL_STATUS_PENDING;
		}

		if ( isset( $api_level->UnConfirmed ) && 1 === absint( $api_level->UnConfirmed ) ) {
			return self::$LEVEL_STATUS_UNCONFIRMED;
		}

		if ( isset( $api_level->Scheduled ) && 1 === absint( $api_level->Scheduled ) ) {
			return self::$LEVEL_STATUS_SCHEDULED;
		}

		return false;
	}

	public static function insert( $contact_id, $status = array(), $reg = array(), $exp = array() ) {
		/** @var wpdb $wpdb */ global $wpdb;

		$table = self::_table();

		$data = array(
			'status' => wp_json_encode( $status ),
			'cid'    => absint( $contact_id ),
		);

		/** Registered Dates */
		if ( ! empty( $reg ) && is_array( $reg ) ) {
			foreach ( $reg as $id => $date ) {
				$data["reg_$id"] = $date;
			}
		}

		/** Expiry Dates */
		if ( ! empty( $exp ) && is_array( $exp ) ) {
			foreach ( $exp as $id => $date ) {
				$data["exp_$id"] = $date;
			}
		}

		$wpdb->insert( $table, $data ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

		return ! empty( $wpdb->insert_id ) ? absint( $wpdb->insert_id ) : new WP_Error( 500, $wpdb->last_error );
	}

	/**
	 * Get the log headers for this importer.
	 *
	 * @return array
	 */
	protected function get_log_headers() {
		return [ 'ID', 'Member ID', 'Status' ];
	}

	/**
	 * Prepare log data for a single item.
	 *
	 * @param mixed $data
	 * @param mixed $result
	 *
	 * @return array
	 */
	protected function prepare_log_data( $data, $result ) {
		return [
			'ID'        => is_wp_error( $result ) ? 0 : $result['id'],
			'Member ID' => $data->ID ?? 0,
			'Status'    => is_wp_error( $result ) ? __( 'Failed', 'wp-marketing-automations' ) : ( isset( $result['skipped'] ) && $result['skipped'] ? __( 'Skipped', 'wp-marketing-automations' ) : __( 'Success', 'wp-marketing-automations' ) ),
		];
	}

	/**
	 * Get the second step fields for wlm importer
	 *
	 * @return array
	 */
	public function get_second_step_fields() {
		$count       = $this->get_contacts_count();
		$this->count = $count;
		$message     = __( 'There are no member to be import', 'wp-marketing-automations' );
		$type        = 'error';
		if ( $count > 0 ) {
			/* translators: 1: Members count */
			$message = sprintf( __( 'There are %d members to be imported', 'wp-marketing-automations' ), $count ); //translators: number of members to be imported
			$type    = 'warning';
		}

		return [
			[
				'id'          => 'wc_notice',
				'type'        => 'notice',
				'noticeLabel' => __( 'Members', 'wp-marketing-automations' ),
				'nType'       => $type,
				'isHtml'      => true,
				'text'        => '<span class="bwf-heading8-new">' . __( 'Information', 'wp-marketing-automations' ) . ':</span> ' . $message,
				'desc'        => '',
			]
		];
	}

	/**
	 * Validate the count of contacts to be imported.
	 *
	 * @return bool
	 */
	public function validate_second_step() {
		return intval( $this->count ) > 0;
	}
}

if ( function_exists( 'bwfan_is_wlm_active' ) && bwfan_is_wlm_active() ) {
	BWFAN_Core()->importer->register( 'wlm', 'BWFAN\Importers\WLM_Importer' );
}
