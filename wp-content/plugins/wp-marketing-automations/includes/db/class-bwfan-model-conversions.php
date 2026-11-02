<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFAN_Model_Conversions' ) && BWFAN_Common::is_pro_3_0() ) {

	#[\AllowDynamicProperties]
	class BWFAN_Model_Conversions extends BWFAN_Model {
		static $primary_key = 'ID';

		public static function get_conversions_by_source_type( $source_id, $source_type = 1, $limit = 25, $offset = 0 ) {
			global $wpdb;
			$table       = self::_table();
			$source_id   = absint( $source_id );
			$source_type = absint( $source_type );
			$limit       = absint( $limit );
			$offset      = absint( $offset );
			/**
			 * Do not join on wp_posts here: under WooCommerce HPOS orders live in wc_orders (not wp_posts),
			 * so an INNER JOIN on posts drops every conversion row and empties the Orders tab. Select the
			 * conversion rows directly and use the HPOS-aware wc_get_order() check below to flag rows whose
			 * order was deleted (the UI renders them as "Order Deleted") instead of dropping them.
			 */
			$query       = $wpdb->prepare( "SELECT bwc.* FROM $table as bwc WHERE bwc.oid = %d AND bwc.otype=%d ORDER BY bwc.wcid DESC LIMIT %d OFFSET %d", ...[ $source_id, $source_type, $limit, $offset ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table from $wpdb->prefix; spread args match placeholders
			$conversions = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; spread args match placeholders
			if ( empty( $conversions ) ) {
				return [ 'conversions' => array(), 'total' => 0 ];
			}

			$total_query = $wpdb->prepare( "SELECT COUNT(*) FROM $table as bwc WHERE bwc.oid = %d AND bwc.otype=%d", ...[ $source_id, $source_type ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table from $wpdb->prefix; spread args match placeholders
			$total       = absint( $wpdb->get_var( $total_query ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; spread args match placeholders
			foreach ( $conversions as $key => $conv ) {
				$order = wc_get_order( absint( $conv['wcid'] ) );

				/** flag the conversion if order deleted or not exists so the UI can show it as "Order Deleted" */
				if ( ! $order instanceof WC_Order ) {
					$conversions[ $key ]['order_deleted'] = 1;
					
					/** order is gone, but surface the contact's name from the contact record if it still exists */
					$contact = new WooFunnels_Contact( '', '', '', absint( $conv['cid'] ) );
					if ( $contact->get_id() > 0 ) {
						$conversions[ $key ]['f_name'] = $contact->get_f_name();
						$conversions[ $key ]['l_name'] = $contact->get_l_name();
						$conversions[ $key ]['email']  = $contact->get_email();
					}

					continue;
				}

				$order_details = [];

				$order_details['f_name']   = $order->get_billing_first_name();
				$order_details['l_name']   = $order->get_billing_last_name();
				$order_details['email']    = $order->get_billing_email();
				$order_details['status']   = $order->get_status();
				$order_items               = $order->get_items();
				$order_details['items']    = [];
				$order_details['currency'] = BWFAN_Automations::get_currency( $order->get_currency() );
				foreach ( $order_items as $item_key => $item ) {
					$product_id   = $item->get_product_id(); // the Product id
					$variation_id = $item->get_variation_id();
					if ( ! empty( $variation_id ) ) {
						$order_details['items'][ $variation_id ] = $item->get_name();
					} else {
						$order_details['items'][ $product_id ] = $item->get_name();
					}
				}

				$conversions[ $key ]            = array_replace( $conv, $order_details );
				$conversions[ $key ]['wctotal'] = $order->get_total();
			}

			return [
				'conversions' => $conversions,
				'total'       => $total
			];
		}

		public static function get_conversions_by_oid( $oid, $contact_id, $engagements_ids = [], $type = 1 ) {
			global $wpdb;
			$table      = self::_table();
			$oid        = absint( $oid );
			$contact_id = absint( $contact_id );
			$type       = absint( $type );
			$query      = $wpdb->prepare( "SELECT wcid,date,wctotal FROM $table WHERE otype=%d AND oid=%d AND cid=%d", $type, $oid, $contact_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- query built with $wpdb->prepare(); ids absint()ed
			if ( ! empty( $engagements_ids ) ) {
				$engagements_ids = array_map( 'absint', $engagements_ids );
				$placeholder     = implode( ', ', array_fill( 0, count( $engagements_ids ), '%d' ) );
				$query          .= $wpdb->prepare( " AND trackid IN($placeholder)", $engagements_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- query built with $wpdb->prepare(); ids absint()ed
			}

			return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare(); ids absint()ed
		}

		public static function get_conversions_for_check_validity( $saved_last_conversion_id ) {
			$saved_last_conversion_id = absint( $saved_last_conversion_id );
			if ( empty( $saved_last_conversion_id ) ) {
				return [];
			}

			global $wpdb;
			$table = self::_table();
			$and   = '';
			if ( ! empty( $saved_last_conversion_id ) ) {
				$and .= $wpdb->prepare( ' AND ID <= %d', $saved_last_conversion_id );
			}

			$query = "SELECT ID,wcid FROM $table WHERE 1=1 $and ORDER BY ID DESC";

			return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
		}

		public static function get_last_conversion_id() {
			global $wpdb;
			$table = self::_table();
			$query = "SELECT MAX(`ID`) FROM $table";

			return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; hardcoded SQL
		}

		public static function delete_conversions_by_track_id( $ids ) {
			if ( empty( $ids ) ) {
				return;
			}

			global $wpdb;
			$table = self::_table();

			$placeholders = array_fill( 0, count( $ids ), '%d' );
			$placeholders = implode( ', ', $placeholders );

			$query = $wpdb->prepare( "DELETE FROM {$table} WHERE trackid IN ($placeholders)", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated in code; ids bound as %d

			return $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated in code; ids bound as %d
		}

		public static function get_automation_revenue( $aid, $start_date, $end_date, $is_interval, $interval ) {
			global $wpdb;
			$table = self::_table();

			$date_col       = "date";
			$interval_query = '';
			$group_by       = '';
			$order_by       = ' ID ';

			if ( 'interval' === $is_interval ) {
				$get_interval   = BWFCRM_Dashboards::get_interval_format_query( $interval, $date_col );
				$interval_query = $get_interval['interval_query'];
				$interval_group = $get_interval['interval_group'];
				$group_by       = "GROUP BY " . $interval_group;
				$order_by       = ' time_interval ';
			}
			$base_query = "SELECT  count(ID) as conversions, SUM(wctotal) as revenue $interval_query FROM `" . $table . "` WHERE 1=1 AND oid = " . absint( $aid ) . " AND otype = 1 AND " . $wpdb->prepare( "`$date_col` >= %s AND `$date_col` <= %s", $start_date, $end_date ) . $group_by . " ORDER BY " . $order_by . " ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- interval SQL comes from Pro BWFCRM_Dashboards helper; other values absint()ed

			return $wpdb->get_results( $base_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- interval SQL comes from Pro BWFCRM_Dashboards helper; other values absint()ed
		}
	}
}