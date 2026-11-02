<?php
defined( 'ABSPATH' ) || exit;


if ( ! class_exists( 'BWFAN_Model_Links' ) ) {

	#[\AllowDynamicProperties]
	class BWFAN_Model_Links extends BWFAN_Model {

		/**
		 * Check link is existing or not
		 *
		 * @param $value
		 * @param $search_by
		 * @param $return_col
		 * @param $type
		 *
		 * @return string|null
		 */
		public static function is_link_exists( $value, $search_by = 'ID', $return_col = '', $type = '' ) {
			global $wpdb;
			$type_query = '';
			$return_col = empty( $return_col ) ? "`l_hash`" : $return_col;
			/** Column names are placed in the SQL as identifiers, so allow only real bwfan_links columns */
			$link_columns = array( 'ID', 'url', 'l_hash', 'clean_url', 'created_at', 'oid', 'sid', 'tid', 'type' );
			if ( ! in_array( trim( (string) $search_by, '`' ), $link_columns, true ) || ! in_array( trim( (string) $return_col, '`' ), $link_columns, true ) ) {
				return null;
			}
			$args       = [ $value ];
			if ( ! empty( $type ) ) {
				$type_query = " AND `type` = %d";
				$args[]     = $type;
			}

			$query = "SELECT {$return_col} FROM {$wpdb->prefix}bwfan_links WHERE `{$search_by}` = %s {$type_query}";

			return $wpdb->get_var( $wpdb->prepare( $query, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- columns whitelisted to bwfan_links columns above; value and type bound via placeholders
		}

		/**
		 * Check link hash and cleaned url exists or not
		 * 
		 * @param string $l_hash
		 * @param string $clean_url
		 * @return array|object|null
		 */
		public static function is_link_hash_exists( $l_hash = '', $clean_url = '' ) {
			if ( empty( $l_hash ) ) {
				return [];
			}

			global $wpdb;
			$query = "SELECT `ID`,`url` FROM {$wpdb->prefix}bwfan_links WHERE `l_hash` = %s";
			$args  = [ $l_hash ];
			if ( ! empty( $clean_url ) ) {
				$query .= " AND `clean_url` = %s";
				$args[] = $clean_url;
			}

			return $wpdb->get_row( $wpdb->prepare( $query, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- values bound via prepare()
		}

		/**
		 * Check if links exist
		 *
		 * @param string $link
		 * @param array $data
		 *
		 * @return string|bool|null
		 */
		public static function check_if_link_exists( $link = '', $data = [] ) {
			if ( empty( $link ) ) {
				return false;
			}

			global $wpdb;

			$where = " 1=1 ";

			if ( ! empty( $data['clean_url'] ) ) {
				$where .= $wpdb->prepare( " AND `clean_url` = %s", $data['clean_url'] );
			}

			/**
			 * Read `tid`, which is the key every caller in these plugins actually sends. This
			 * clause looked for `template_id` alone and so never fired: two templates sharing a
			 * broadcast (an A/B split sends both under one oid) matched each other's row and the
			 * second reused the first's l_hash, attributing its clicks to the wrong variant.
			 *
			 * `template_id` is still honoured behind it. This method is public and static, and
			 * that key is what it read before - so an outside caller passing it had a filter that
			 * worked, and narrowing to `tid` alone would have widened their lookups without a word.
			 * `tid` wins when both are present, since that is the one the column is named after.
			 */
			$link_tid = $data['tid'] ?? ( $data['template_id'] ?? 0 );
			if ( ! empty( $link_tid ) ) {
				$where .= $wpdb->prepare( " AND `tid` = %d", intval( $link_tid ) );
			}
			if ( ! empty( $data['type'] ) ) {
				$where .= $wpdb->prepare( " AND `type` = %d", intval( $data['type'] ) );
			}
			if ( ! empty( $data['oid'] ) ) {
				$where .= $wpdb->prepare( " AND `oid` = %d", intval( $data['oid'] ) );
			}
			if ( ! empty( $data['sid'] ) ) {
				$where .= $wpdb->prepare( " AND `sid` = %d", intval( $data['sid'] ) );
			}

			return $wpdb->get_var( "SELECT `l_hash` FROM {$wpdb->prefix}bwfan_links WHERE $where" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- where fragments each built with $wpdb->prepare()
		}

		/**
		 * Get link ID by tid
		 *
		 * @param $cleaned_url
		 * @param $data
		 *
		 * @return string|null
		 */
		public static function get_link_id_by_tid( $cleaned_url, $data ) {
			if ( empty( $cleaned_url ) || empty( $data ) ) {
				return '';
			}
			$tid  = $data['tid'] ?? 0;
			$oid  = $data['oid'] ?? 0;
			$type = $data['type'] ?? 0;

			global $wpdb;
			$query = "SELECT `ID` FROM {$wpdb->prefix}bwfan_links WHERE `clean_url` = %s AND `tid` = %d AND `oid` = %d AND `type`=%d ORDER BY `ID` DESC LIMIT 1";

			//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			return $wpdb->get_var( $wpdb->prepare( $query, [
				$cleaned_url,
				$tid,
				$oid,
				$type
			] ) );
		}
	}
}
