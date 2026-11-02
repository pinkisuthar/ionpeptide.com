<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WFCO_Model_ConnectorMeta' ) ) {
	#[\AllowDynamicProperties]
	class WFCO_Model_ConnectorMeta extends WFCO_Model {
		static $primary_key = 'ID';

		public static function get_meta( $id, $key ) {
			$rows  = self::get_connector_meta( $id );
			$value = '';
			if ( count( $rows ) > 0 && isset( $rows[ $key ] ) ) {
				$value = $rows[ $key ];
			}

			return $value;
		}

		public static function get_connector_meta( $id ) {
			if ( 0 === $id ) {
				return [];
			}

			global $wpdb;
			$table = self::_table();

			$result = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE connector_id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is a trusted internal identifier from self::_table(); $id bound via %d.; Prefixed table from class name, id bound via %d; custom table
			$meta      = [];

			if ( is_array( $result ) && count( $result ) > 0 ) {
				foreach ( $result as $meta_values ) {
					$key          = $meta_values['meta_key'];
					$meta[ $key ] = maybe_unserialize( $meta_values['meta_value'] );
				}
			}

			return $meta;
		}

		private static function _table() {
			global $wpdb;
			$table_name = strtolower( get_called_class() );
			$table_name = str_replace( 'wfco_model_', 'wfco_', $table_name );

			return $wpdb->prefix . $table_name;
		}

		public static function get_connectors_meta( $ids = [] ) {
			$meta = [];
			if ( empty( $ids ) || ! is_array( $ids ) ) {
				return $meta;
			}

			global $wpdb;
			$table = self::_table();
			$count = count( $ids );

			$placeholders = array_fill( 0, $count, '%d' );
			$placeholders = implode( ', ', $placeholders );

			/** Fetching connectors meta - single query */
			$query  = "Select * FROM $table WHERE connector_id IN ($placeholders)";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IN placeholders generated in code, ids bound via %d
			$query  = $wpdb->prepare( $query, $ids ); // WPCS: unprepared SQL OK
			$result = self::get_results( $query );

			if ( is_array( $result ) && count( $result ) > 0 ) {
				foreach ( $result as $meta_values ) {
					$meta[ $meta_values['connector_id'] ][ $meta_values['meta_key'] ] = maybe_unserialize( $meta_values['meta_value'] );
				}
			}

			return $meta;
		}


	}
}