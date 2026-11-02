<?php

/**
 * Automation V2 modal class
 */
#[\AllowDynamicProperties]
class BWFAN_Model_Automations_V2 extends BWFAN_Model {
	static $primary_key = 'ID';

	/**
	 * Check if automation exists
	 *
	 * @param $field
	 * @param $data
	 *
	 * @return bool
	 */
	public static function check_if_automation_exists( $field, $data ) {
		global $wpdb;
		$exists = false;

		$query  = 'SELECT ID FROM ' . self::_table();
		$field  = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $field );
		$query  .= $wpdb->prepare( " WHERE `{$field}` = %s ", $data ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- field regex-whitelisted to [A-Za-z0-9_]; value via %s
		$result = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- field regex-whitelisted to [A-Za-z0-9_]; value via %s
		if ( ! empty( $result ) ) {
			$exists = true;
		}

		return $exists;
	}

	/**
	 * Returns table name
	 *
	 * @return string
	 */
	protected static function _table() {
		global $wpdb;

		return $wpdb->prefix . 'bwfan_automations';
	}

	/**
	 * Insert a new automation to db
	 *
	 * @param $data
	 *
	 * @return int|void
	 */
	public static function create_new_automation( $data ) {
		if ( empty( $data ) ) {
			return;
		}
		global $wpdb;

		$wpdb->insert( self::_table(), $data ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return absint( $wpdb->insert_id );
	}

	/**
	 * Update the automation data
	 *
	 * @param $id
	 * @param $data
	 *
	 * @return bool|int|void
	 */
	public static function update_automation( $id, $data ) {
		if ( empty( $data ) || 0 === intval( $id ) ) {
			return;
		}
		global $wpdb;

		$data = self::verify_columns( $data );

		//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->update( self::_table(), $data, [
			'ID' => $id
		] );
	}

	/**
	 * Parse table columns as passing dynamically from JS
	 *
	 * @param $data
	 *
	 * @return array
	 */
	public static function verify_columns( $data ) {
		$arr = [];

		if ( isset( $data['source'] ) ) {
			$arr['source'] = $data['source'];
		}
		if ( isset( $data['event'] ) ) {
			$arr['event'] = $data['event'];
		}
		if ( isset( $data['status'] ) ) {
			$arr['status'] = $data['status'];
		}
		if ( isset( $data['priority'] ) ) {
			$arr['priority'] = $data['priority'];
		}
		if ( isset( $data['start'] ) ) {
			$arr['start'] = $data['start'];
		}
		if ( isset( $data['v'] ) ) {
			$arr['v'] = $data['v'];
		}
		if ( isset( $data['benchmark'] ) ) {
			$arr['benchmark'] = $data['benchmark'];
		}
		if ( isset( $data['title'] ) ) {
			$arr['title'] = $data['title'];
		}

		return $arr;
	}

	/**
	 * Get automation row by ID
	 *
	 * @param $automation_id
	 *
	 * @return array|false|object|void|null
	 */
	public static function get_automation( $automation_id ) {
		if ( empty( $automation_id ) ) {
			return false;
		}

		global $wpdb;
		$table_name = self::_table();

		$query = 'SELECT * FROM ' . $table_name . '  WHERE ID = ' . intval( $automation_id );

		$core_cache_obj = WooFunnels_Cache::get_instance();

		$result = $core_cache_obj->get_cache( md5( $query ), 'fka-automation' );
		if ( false === $result ) {
			$result = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- automation id intval()ed when building the query
			$core_cache_obj->set_cache( md5( $query ), $result, 'fka-automation' );
		}

		return $result;
	}

	/**
	 * Get active automation that has a goal
	 *
	 * @param $event_slug
	 *
	 * @return array
	 */
	public static function get_goal_automations( $event_slug ) {
		global $wpdb;
		$table_name = self::_table();

		$query = $wpdb->prepare( "SELECT `ID`,`benchmark` FROM {$table_name} WHERE `status` = %d AND `v` = %d AND `benchmark` LIKE %s", 1, 2, "%$event_slug%" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; values bound via prepare

		$core_cache_obj = WooFunnels_Cache::get_instance();

		$result = $core_cache_obj->get_cache( md5( $query ), 'fka-automation' );
		if ( false === $result ) {
			$result = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; values bound via prepare
			$core_cache_obj->set_cache( md5( $query ), $result, 'fka-automation' );
		}

		return $result;
	}

	/**
	 * Return automation should run or not for a contact based on automation setting
	 *
	 * @param $automation_id
	 * @param $contact_id
	 * @param $automation_data
	 *
	 * @return bool
	 */
	public static function validation_automation_run_count( $automation_id = '', $contact_id = '', $automation_data = [], $exclude_check = false ) {
		if ( empty( $automation_id ) || empty( $contact_id ) || empty( $automation_data ) ) {
			return false;
		}

		if ( ! isset( $automation_data['event_meta'] ) || ! is_array( $automation_data['event_meta'] ) ) {
			$automation_data['event_meta'] = [];
		}

		// handling when event_meta is not saved by default. In that case automation will run once by default
		if ( ! isset( $automation_data['event_meta']['bwfan_automation_run'] ) ) {
			$automation_data['event_meta']['bwfan_automation_run'] = 'once';
		}

		$event_meta = $automation_data['event_meta'];

		if ( ! isset( $event_meta['bwfan_automation_run'] ) || 'multiple' === $event_meta['bwfan_automation_run'] ) {
			return true;
		}

		if ( true === $exclude_check ) {
			/** optimized if contact active in automation check is excluded */
			$has_run = self::get_contact_automation_run_count( $automation_id, $contact_id, 'bool' );

			/** If already run then return false as run once case */
			return ! $has_run;
		}

		$has_run = self::get_contact_complete_automation_run_count( $automation_id, $contact_id, 'bool' );

		/** If already run then return false as run once case */
		return ! $has_run;
	}

	/**
	 * Get automation run count for a contact
	 *
	 * @param $automation_id
	 * @param $contact_id
	 * @param $return
	 *
	 * @return bool|int
	 */
	public static function get_contact_automation_run_count( $automation_id = '', $contact_id = '', $return = '' ) {
		if ( empty( $automation_id ) || empty( $contact_id ) ) {
			return 0;
		}

		global $wpdb;
		$automation_contact_table          = $wpdb->prefix . 'bwfan_automation_contact';
		$automation_contact_complete_table = $wpdb->prefix . 'bwfan_automation_complete_contact';

		$query         = $wpdb->prepare( "SELECT count(*) as count FROM {$automation_contact_table} WHERE `cid` = %d AND `aid` = %d", $contact_id, $automation_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from $wpdb->prefix; ids bound via %d
		$running_count = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; ids bound via %d

		if ( absint( $running_count ) > 0 && 'bool' === $return ) {
			return true;
		}

		$query          = $wpdb->prepare( "SELECT count(*) as count FROM {$automation_contact_complete_table} WHERE `cid` = %d AND `aid` = %d", $contact_id, $automation_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from $wpdb->prefix; ids bound via %d
		$complete_count = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; ids bound via %d

		$count = ( absint( $running_count ) + absint( $complete_count ) );
		if ( 'bool' === $return ) {
			return ( $count > 0 );
		}

		return $count;
	}

	/**
	 * Get complete automation run count for a contact
	 *
	 * @param $automation_id
	 * @param $contact_id
	 * @param $return
	 *
	 * @return bool|int
	 */
	public static function get_contact_complete_automation_run_count( $automation_id = '', $contact_id = '', $return = '' ) {
		if ( empty( $automation_id ) || empty( $contact_id ) ) {
			return 0;
		}

		global $wpdb;
		$automation_contact_complete_table = $wpdb->prefix . 'bwfan_automation_complete_contact';

		$query          = $wpdb->prepare( "SELECT count(*) as count FROM {$automation_contact_complete_table} WHERE `cid` = %d AND `aid` = %d", $contact_id, $automation_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from $wpdb->prefix; ids bound via %d
		$complete_count = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; ids bound via %d

		if ( absint( $complete_count ) > 0 && 'bool' === $return ) {
			return true;
		}

		return absint( $complete_count );
	}

	/**
	 * Delete Automations
	 *
	 * @param $ids
	 *
	 * @return bool|int
	 */
	public function delete_automation( $ids ) {
		if ( empty( $ids ) ) {
			return false;
		}

		global $wpdb;
		$table_name = self::_table();

		if ( ! is_array( $ids ) ) {
			$ids = [ $ids ];
		}

		$ids         = array_map( 'absint', $ids );
		$placeholder = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return $wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE `ID` IN( $placeholder )", $ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from $wpdb->prefix; ids absint()ed; %d placeholders
	}

	/**
	 * Get v2 automations ids by event slug
	 *
	 * @param $event_slugs
	 *
	 * @return array|object|stdClass[]|null
	 */
	public static function get_automations_by_slugs( $event_slugs ) {
		if ( ! is_array( $event_slugs ) ) {
			if ( empty( $event_slugs ) ) {
				return [];
			}
			$event_slugs = [ $event_slugs ];
		}

		global $wpdb;
		$table_name = self::_table();

		$args        = $event_slugs;
		$args[]      = 2;
		$placeholder = array_fill( 0, count( $event_slugs ), '%s' );
		$placeholder = implode( ', ', $placeholder );

		$query = $wpdb->prepare( "SELECT `ID` FROM {$table_name} WHERE `event` IN ($placeholder) AND `v` = %d", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated %s placeholders; values bound via prepare

		$core_cache_obj = WooFunnels_Cache::get_instance();

		$result = $core_cache_obj->get_cache( md5( $query ), 'fka-automation' );
		if ( false === $result ) {
			$result = self::get_results( $query );
			$core_cache_obj->set_cache( md5( $query ), $result, 'fka-automation' );
		}

		return $result;
	}
}