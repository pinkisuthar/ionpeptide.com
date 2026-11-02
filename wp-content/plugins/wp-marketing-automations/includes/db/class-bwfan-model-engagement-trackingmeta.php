<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFAN_Model_Engagement_Trackingmeta' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFAN_Model_Engagement_Trackingmeta extends BWFAN_Model {
		static $primary_key = 'ID';

		static function get_meta( $con_id, $key = '' ) {
			global $wpdb;
			$table = self::_table();

			if ( ! empty( $key ) ) {
				$query = $wpdb->prepare( "SELECT meta_key, meta_value FROM {$table} WHERE meta_key = %s AND eid = %d", $key, $con_id ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$query = $wpdb->prepare( "SELECT meta_key, meta_value FROM {$table} WHERE eid = %d", $con_id ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

			return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above; table from $wpdb->prefix
		}

		/**
		 * get_merge_tags() for several engagements in one query.
		 *
		 * @param array $con_ids Engagement ids.
		 *
		 * @return array [engagement id => merge tags]
		 */
		static function get_merge_tags_for( array $con_ids ) {
			global $wpdb;
			$con_ids = array_values( array_filter( array_map( 'absint', $con_ids ) ) );
			if ( empty( $con_ids ) ) {
				return array();
			}

			$table        = self::_table();
			$placeholders = implode( ', ', array_fill( 0, count( $con_ids ), '%d' ) );
			$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT eid, meta_value FROM {$table} WHERE meta_key = 'merge_tags' AND eid IN ($placeholders)", $con_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders generated in code; ids bound as %d; table from $wpdb->prefix

			$merge_tags = array();
			foreach ( (array) $rows as $row ) {
				$eid = (int) $row['eid'];
				if ( ! isset( $merge_tags[ $eid ] ) ) {
					$merge_tags[ $eid ] = json_decode( $row['meta_value'], true );
				}
			}

			return $merge_tags;
		}

		static function get_merge_tags( $con_id ) {
			$result = self::get_meta( $con_id, 'merge_tags' );
			if ( empty( $result ) ) {
				return array();
			}

			try {
				$merge_tags = json_decode( $result[0]['meta_value'], true );
			} catch ( Exception $e ) {
				return array();
			}

			return $merge_tags;
		}

		static function get_notification_data( $con_id ) {
			$result = self::get_meta( $con_id, 'notification_data' );
			if ( empty( $result ) ) {
				return array();
			}
			try {
				$merge_tags = json_decode( $result[0]['meta_value'], true );
			} catch ( Exception $e ) {
				return array();
			}

			return $merge_tags;
		}

		public static function delete_engagements_meta( $ids ) {
			if ( empty( $ids ) ) {
				return;
			}

			global $wpdb;
			$table = self::_table();

			$placeholders = array_fill( 0, count( $ids ), '%d' );
			$placeholders = implode( ', ', $placeholders );

			$query = $wpdb->prepare( "DELETE FROM {$table} WHERE eid IN ($placeholders)", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- generated %d placeholders; ids bound via prepare

			return $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- generated %d placeholders; ids bound via prepare
		}
	}
}