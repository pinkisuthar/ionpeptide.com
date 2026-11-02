<?php

#[\AllowDynamicProperties]
class BWFAN_Model_Message_Unsubscribe extends BWFAN_Model {
	static $primary_key = 'ID';

	/**
	 * @param $where
	 * @param bool $single_row
	 *
	 * @return array|object|string|void|null
	 */
	public static function get_message_unsubscribe_row( $where, $single_row = true ) {
		global $wpdb;

		if ( empty( $where ) || ! is_array( $where ) ) {
			return '';
		}
		if ( true === $single_row ) {
			return $wpdb->get_row( self::prepare_message_unsubscribe_sql( $where ) . ' LIMIT 0, 1', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- columns quoted identifiers; values bound via prepare()
		} else {
			return $wpdb->get_results( self::prepare_message_unsubscribe_sql( $where ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- columns quoted identifiers; values bound via prepare()
		}
	}

	/**
	 * @param $data
	 *
	 * @return string
	 *
	 * Function to create query to get data from table
	 */
	private static function prepare_message_unsubscribe_sql( $data ) {
		global $wpdb;
		$where      = array();
		$args       = array();
		$table_name = $wpdb->prefix . 'bwfan_message_unsubscribe';

		foreach ( $data as $key => $value ) {
			$column = '`' . str_replace( '`', '``', $key ) . '`';

			if ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					continue;
				}
				$placeholders = implode( ',', array_fill( 0, count( $value ), '%s' ) );
				$where[]      = $column . ' IN (' . $placeholders . ')';
				foreach ( $value as $v ) {
					$args[] = $v;
				}
			} elseif ( is_int( $value ) ) {
				$where[] = $column . ' = %d';
				$args[]  = $value;
			} else {
				$where[] = $column . ' = %s';
				$args[]  = $value;
			}
		}

		$query = 'SELECT * FROM ' . $table_name . ' WHERE ' . implode( ' AND ', $where );

		return empty( $args ) ? $query : $wpdb->prepare( $query, ...$args ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param $data
	 *
	 * @return array|false|int|void
	 *
	 * Delete row from table
	 */
	public static function delete_message_unsubscribe_row( $data ) {
		if ( ! is_array( $data ) || empty( $data ) ) {
			return;
		}

		global $wpdb;
		$where      = array();
		$args       = array();
		$table_name = $wpdb->prefix . 'bwfan_message_unsubscribe';

		foreach ( $data as $key => $value ) {
			$column = '`' . str_replace( '`', '``', $key ) . '`';
			if ( is_int( $value ) ) {
				$where[] = $column . ' = %d';
			} else {
				$where[] = $column . ' = %s';
			}
			$args[] = $value;
		}

		$query = 'DELETE FROM ' . $table_name . ' WHERE ' . implode( ' AND ', $where );

		return $wpdb->query( $wpdb->prepare( $query, ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- columns quoted identifiers; values bound via prepare()
	}

	/**
	 * Delete email or phone row from the unsubscribe table
	 *
	 * @param array $emails_or_phones
	 *
	 * @return bool
	 */
	public static function delete_unsubscribers( $emails_or_phones = [] ) {
		global $wpdb;
		if ( ! is_array( $emails_or_phones ) || empty( $emails_or_phones ) ) {
			return false;
		}

		$placeholders = [];
		$where_args   = [];
		foreach ( $emails_or_phones as $recipient ) {
			if ( empty( $recipient ) ) {
				continue;
			}

			$placeholders[] = "%s";
			$where_args[]   = $recipient;
		}

		$placeholders = implode( ',', $placeholders );
		$table        = self::_table();
		$sql          = $wpdb->prepare( "DELETE FROM $table WHERE `recipient` IN ($placeholders)", $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated per value; bound via prepare()
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated per value; bound via prepare()

		return true;
	}

	/**
	 * Add email or phone in the unsubscribe table. May or may not be contact.
	 *
	 * @param array $emails_or_phones Recipients (email or phone).
	 * @param int   $object_id       Automation/Broadcast/Form ID (0 for manual/CSV).
	 * @param int   $object_type     Source type: 1=Automation, 2=Broadcast, 3=Manual, 4=Form, 5=CSV.
	 * @param bool  $stop_hooks      Whether to skip bwfcrm_after_contact_unsubscribed hook.
	 * @param int   $step_id         Step ID (sid) when unsubscribed from automation action.
	 * @return bool
	 */
	public static function add_unsubscribers( $emails_or_phones = [], $object_id = 0, $object_type = 3, $stop_hooks = false, $step_id = 0 ) {
		$step_id       = absint( $step_id );
		$unsubscribers = array_map( function ( $recipient ) use ( $object_id, $object_type, $step_id ) {
			return array(
				'recipient'     => $recipient,
				'mode'          => is_email( $recipient ) ? 1 : 2,
				'c_date'        => current_time( 'mysql', 1 ),
				'automation_id' => ! empty( $object_id ) ? absint( $object_id ) : 0,
				'c_type'        => ! empty( $object_type ) ? absint( $object_type ) : 3,
				'sid'           => $step_id,
			);
		}, $emails_or_phones );

		BWFAN_Model_Message_Unsubscribe::insert_multiple( $unsubscribers, array( 'recipient', 'mode', 'c_date', 'automation_id', 'c_type', 'sid' ), [ '%s', '%d', '%s', '%d', '%d', '%d' ] );
		/** hook when any contact unsubscribed  */
		if ( false === $stop_hooks ) {
			do_action( 'bwfcrm_after_contact_unsubscribed', $unsubscribers );
		}

		return true;
	}

	/**
	 * Get the most recent unsubscribe row for given recipients (email/phone).
	 *
	 * @param array $recipients List of recipient (email or phone).
	 * @return array|null Row with c_type, automation_id, sid; null if none.
	 */
	public static function get_latest_unsubscribe_row( $recipients ) {
		global $wpdb;

		if ( empty( $recipients ) || ! is_array( $recipients ) ) {
			return null;
		}

		$recipients   = array_values( array_filter( array_map( 'trim', $recipients ) ) );
		if ( empty( $recipients ) ) {
			return null;
		}
		$placeholders = implode( ',', array_fill( 0, count( $recipients ), '%s' ) );
		$table        = self::_table();
		$sql          = $wpdb->prepare( "SELECT * FROM {$table} WHERE `recipient` IN ({$placeholders}) ORDER BY `ID` DESC LIMIT 1", $recipients ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated; values bound via prepare()

		return $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; values bound via prepare()
	}

}
