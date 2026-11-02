<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFAN_Model_Engagement_Tracking' ) && BWFAN_Common::is_pro_3_0() ) {

	#[\AllowDynamicProperties]
	class BWFAN_Model_Engagement_Tracking extends BWFAN_Model {
		static $primary_key = 'ID';

		static function get_conversations_by_cid( $cid, $mode, $offset = 0, $limit = 25 ) {
			global $wpdb;
			$table  = self::_table();
			$cid    = absint( $cid );
			$offset = absint( $offset );
			$limit  = absint( $limit );
			$and    = '';
			if ( ! empty( $mode ) ) {
				$mode = absint( $mode );
				$and  = $wpdb->prepare( ' AND con.mode = %d', $mode );
			}
			$query = $wpdb->prepare( "SELECT con.*, ct.subject, ct.template as message FROM $table AS con LEFT JOIN {$wpdb->prefix}bwfan_templates AS ct ON con.tid=ct.ID WHERE cid = %d $and ORDER BY created_at DESC LIMIT %d OFFSET %d", $cid, $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from _table(); $and built with prepare()

			return self::get_results( $query );
		}

		public static function get_total_engagements( $cid, $mode ) {
			global $wpdb;
			$table   = self::_table();
			$query   = [];
			$query[] = "SELECT COUNT(ID) FROM {$table} WHERE 1=1";
			$query[] = $wpdb->prepare( "AND cid = %d", $cid );
			$query[] = $wpdb->prepare( "AND mode = %d", $mode );

			return $wpdb->get_var( implode( ' ', $query ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table from _table(); values bound via prepare()
		}

		public static function get_recipents_by_type( $oid, $type, $offset = 0, $limit = 25 ) {
			global $wpdb;

			/** Fetching all Engagements for broadcast **/
			$table  = self::_table();
			$oid    = absint( $oid );
			$type   = absint( $type );
			$offset = absint( $offset );
			$limit  = absint( $limit );
			$query  = $wpdb->prepare( "SELECT conv.ID AS conversation_id,c.f_name,c.l_name,c.wpid,conv.send_to,conv.cid,conv.mode,conv.type,conv.open,conv.click,conv.oid,if(conv.c_status=2,1,0) as sent,conv.created_at as sent_time FROM {table_name} AS conv LEFT JOIN {$wpdb->prefix}bwf_contact AS c ON c.ID = conv.cid WHERE conv.type = %d AND conv.oid = %d AND (c_status = 2 OR c_status = 3) ORDER BY conv.updated_at DESC LIMIT %d OFFSET %d", $type, $oid, $limit, $offset );
			$results = self::get_results( $query );

			/** Fetch Engagements total count **/
			$query = $wpdb->prepare( "SELECT COUNT(conv.ID) FROM $table AS conv WHERE conv.type = %d AND conv.oid = %d", $type, $oid ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from _table() ($wpdb->prefix)
			$total = absint( $wpdb->get_var( $query ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above

			$conversations_ids = empty( $results ) ? [] : array_column( $results, 'conversation_id' );
			$conversions       = [];

			/** Fetch Engagement's conversions **/
			if ( ! empty( $conversations_ids ) ) {
				$conversations_ids  = array_map( 'absint', $conversations_ids );
				$conv_placeholder   = implode( ', ', array_fill( 0, count( $conversations_ids ), '%d' ) );
				/** Scoped by oid as well, a matching trackid alone does not mean the conversion belongs to this source */
				$conversion_query   = $wpdb->prepare( "SELECT wcid,cid,trackid,wctotal FROM {$wpdb->prefix}bwfan_conversions WHERE trackid IN( $conv_placeholder ) AND otype = %d AND oid = %d", array_merge( $conversations_ids, array( $type, $oid ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders via array_fill; arg count matches at runtime
				$conversions_result = self::get_results( $conversion_query );
				foreach ( $conversions_result as $conversion ) {
					if ( ! isset( $conversions[ absint( $conversion['cid'] ) ] ) ) {
						$conversions[ absint( $conversion['cid'] ) ] = [];
					}
					if ( ! isset( $conversions[ absint( $conversion['cid'] ) ][ absint( $conversion['trackid'] ) ] ) ) {
						$conversions[ absint( $conversion['cid'] ) ][ absint( $conversion['trackid'] ) ] = 0;
					}
					$conversions[ absint( $conversion['cid'] ) ][ absint( $conversion['trackid'] ) ] += floatval( $conversion['wctotal'] );
				}
			}
			$send_to       = empty( $results ) ? [] : array_column( $results, 'send_to' );
			$unsubscribers = [];

			/** Fetch Unsubsribers of broadcast **/
			if ( ! empty( $send_to ) ) {
				$send_to              = array_map( 'sanitize_text_field', $send_to );
				$recipients_ph        = implode( ', ', array_fill( 0, count( $send_to ), '%s' ) );
				$unsubscribe_query    = $wpdb->prepare( "SELECT ID,recipient FROM {$wpdb->prefix}bwfan_message_unsubscribe WHERE recipient IN ($recipients_ph) AND automation_id=%d AND c_type = 2", array_merge( $send_to, array( $oid ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders via array_fill; values bound in prepare()
				$unsubscribers_result = self::get_results( $unsubscribe_query );
				foreach ( $unsubscribers_result as $unsubscriber ) {
					$unsubscribers[ $unsubscriber['recipient'] ] = $unsubscriber;
				}
			}

			$conversations = array_map( function ( $engagement ) use ( $conversions, $unsubscribers ) {
				$conv_id    = absint( $engagement['conversation_id'] );
				$contact_id = absint( $engagement['cid'] );
				$contact    = new BWFCRM_Contact( $contact_id );

				$deleted           = ! $contact->is_contact_exists();
				$revenue           = ( isset( $conversions[ $contact_id ][ $conv_id ] ) ) ? $conversions[ $contact_id ][ $conv_id ] : 0;
				$conversions_count = ( isset( $conversions[ $contact_id ][ $conv_id ] ) ) ? 1 : 0;
				$unsubscribed      = ( isset( $unsubscribers[ $engagement['send_to'] ] ) ) ? 1 : 0;

				$engagement['revenue']         = $revenue;
				$engagement['conversions']     = $conversions_count;
				$engagement['unsubscribed']    = $unsubscribed;
				$engagement['contact_deleted'] = $deleted;

				return $engagement;
			}, $results );

			return array( 'conversations' => $conversations, 'total' => $total );
		}

		public static function get_automation_recipents( $oid, $offset = 0, $limit = 25 ) {
			global $wpdb;
			$table  = self::_table();
			$type   = BWFAN_Email_Conversations::$TYPE_AUTOMATION;
			$oid    = absint( $oid );
			$offset = absint( $offset );
			$limit  = absint( $limit );

			/** Fetching all Engagements for automation **/
			$query       = $wpdb->prepare( "SELECT GROUP_CONCAT(DISTINCT ID) as track_ids,cid, send_to, SUM(open) as open,mode, SUM(click) as click, COUNT(*) as total, SUM(IF(c_status=2,1,0)) as sent,MAX(created_at) as sent_time FROM $table WHERE oid = %d AND type = %d AND cid>0 AND (c_status = 2 OR c_status = 3) GROUP BY send_to,cid,mode ORDER BY sent_time DESC LIMIT %d OFFSET %d", $oid, $type, $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from _table(); values bound via %d
			$engagements = self::get_results( $query );
			if ( empty( $engagements ) ) {
				array( 'conversations' => [], 'total' => 0 );
			}

			/** Get Engagements total count **/
			$count_query = $wpdb->prepare( "SELECT count(send_to) FROM $table WHERE oid = %d AND type = %d AND cid>0 AND (c_status = 2 OR c_status = 3)  GROUP BY send_to,cid,mode", $oid, $type ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from _table(); values bound via %d
			$total       = self::get_results( $count_query );

			$c_ids       = empty( $engagements ) ? [] : array_column( $engagements, 'cid' );
			$conversions = [];
			$contacts    = [];
			if ( ! empty( $c_ids ) ) {

				/** Get contacts f_name, l_name **/
				$c_ids            = array_map( 'absint', $c_ids );
				$cids_placeholder = implode( ',', array_fill( 0, count( $c_ids ), '%d' ) );
				$contact_query    = $wpdb->prepare( "SELECT id,f_name,l_name,email,contact_no FROM {$wpdb->prefix}bwf_contact WHERE id IN($cids_placeholder)", $c_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders via array_fill; ids absint()ed
				$contact_data     = self::get_results( $contact_query );
				foreach ( $contact_data as $contact ) {
					$contacts[ $contact['id'] ] = $contact;
				}

				/** Get contacts conversion **/
				$conversion_query  = $wpdb->prepare( "SELECT c.wcid,c.cid,c.trackid,c.wctotal FROM {$wpdb->prefix}bwfan_conversions as c JOIN {$wpdb->prefix}posts as p on c.wcid=p.ID  WHERE 1=1 AND c.oid = %d AND c.otype = %d AND c.cid IN( $cids_placeholder )", array_merge( array( $oid, $type ), $c_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders via array_fill; arg count matches at runtime
				$conversion_result = self::get_results( $conversion_query );
				foreach ( $conversion_result as $conversion ) {
					if ( ! isset( $conversions[ absint( $conversion['cid'] ) ] ) ) {
						$conversions[ absint( $conversion['cid'] ) ] = [];
					}
					if ( ! isset( $conversions[ absint( $conversion['cid'] ) ][ absint( $conversion['trackid'] ) ] ) ) {
						$conversions[ absint( $conversion['cid'] ) ][ absint( $conversion['trackid'] ) ] = 0;
					}
					$conversions[ absint( $conversion['cid'] ) ][ absint( $conversion['trackid'] ) ] += floatval( $conversion['wctotal'] );
				}
			}

			$send_to       = empty( $engagements ) ? [] : array_column( $engagements, 'send_to' );
			$recipients    = implode( "', '", $send_to );
			$unsubscribers = [];

			if ( ! empty( $recipients ) ) {

				/** Get unsubscribers data **/
				$recipients_ph        = implode( ',', array_fill( 0, count( $send_to ), '%s' ) );
				$unsubscribe_query    = $wpdb->prepare( "SELECT ID,recipient FROM {$wpdb->prefix}bwfan_message_unsubscribe WHERE recipient IN ( $recipients_ph ) AND automation_id = %d AND c_type = %d", array_merge( $send_to, array( $oid, $type ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders via array_fill; arg count matches at runtime
				$unsubscribers_result = self::get_results( $unsubscribe_query );
				foreach ( $unsubscribers_result as $unsubscriber ) {
					$unsubscribers[ $unsubscriber['recipient'] ] = $unsubscriber;
				}
			}

			$conversations = array_map( function ( $engagement ) use ( $contacts, $conversions, $unsubscribers ) {
				$contact_id = absint( $engagement['cid'] );
				$deleted    = true;
				if ( isset( $contacts[ $contact_id ] ) ) {
					$engagement['f_name'] = $contacts[ $contact_id ]['f_name'];
					$engagement['l_name'] = $contacts[ $contact_id ]['l_name'];
					$deleted              = false;
				}

				$revenue           = 0;
				$conversions_count = 0;
				$track_ids         = $engagement['track_ids'];
				$track_ids         = explode( ',', $track_ids );
				$track_ids         = array_map( 'absint', $track_ids );

				if ( isset( $conversions[ $contact_id ] ) ) {
					$conversion_trackids = array_keys( $conversions[ $contact_id ] );
					if ( array_intersect( $track_ids, $conversion_trackids ) ) {
						$revenue = array_sum( $conversions[ $contact_id ] );
					}
					$conversions_count = count( $conversion_trackids );
				}

				$engagement['revenue']         = $revenue;
				$engagement['conversions']     = $conversions_count;
				$engagement['unsubscribed']    = isset( $unsubscribers[ $engagement['send_to'] ] ) ? 1 : 0;
				$engagement['contact_deleted'] = $deleted;

				return $engagement;
			}, $engagements );

			return array( 'conversations' => $conversations, 'total' => count( $total ) );
		}

		public static function get_engagement_recipient_timeline( $convid ) {
			$conv = self::get( absint( $convid ) );
			if ( empty( $conv ) || ! isset( $conv['o_interaction'] ) ) {
				return array();
			}
			$conversions = BWFAN_Model_Conversions::get_specific_rows( 'trackid', absint( $convid ) );
			$final_data  = self::prepare_timeline_data( $conv, $conversions );

			return $final_data;
		}

		public static function prepare_timeline_data( $conv, $conversions = [] ) {
			$opens      = ! empty( $conv['o_interaction'] ) ? json_decode( $conv['o_interaction'], true ) : array();
			$clicks     = ! empty( $conv['c_interaction'] ) ? json_decode( $conv['c_interaction'], true ) : array();
			$mode       = $conv['mode'];
			$final_data = [
				[
					'type' => 2 === absint( $conv['c_status'] ) ? 'sent' : 'failed',
					'mode' => $mode,
					'date' => ! empty( $conv['created_at'] ) ? get_date_from_gmt( $conv['created_at'] ) : ''
				]
			];
			if ( 3 === absint( $conv['c_status'] ) ) {
				$msg                          = BWFAN_Model_Engagement_Trackingmeta::get_meta( $conv['ID'], 'error_msg' );
				$final_data[0]['err_message'] = empty( $msg[0]['meta_value'] ) ? 'Email not sent' : $msg[0]['meta_value'];
			}

			/** Opens */
			$final_data = array_merge( $final_data, array_map( function ( $open ) {
				return array( 'type' => 'open', 'date' => $open );
			}, $opens ) );

			/** Clicks */
			$final_data = array_merge( $final_data, array_map( function ( $click ) use ( $mode ) {
				return array( 'type' => 'click', 'mode' => $mode, 'date' => $click );
			}, $clicks ) );

			if ( ! empty( $conversions ) ) {
				/** Conversions */
				$final_data = array_merge( $final_data, array_map( function ( $conversion ) {
					$order = wc_get_order( $conversion['wcid'] );

					return array(
						'type'     => 'conversion',
						'date'     => ! empty( $conversion['date'] ) ? get_date_from_gmt( $conversion['date'] ) : '',
						'revenue'  => $order->get_total(),
						'order_id' => $conversion['wcid'],
						'currency' => BWFAN_Automations::get_currency( $order->get_currency() )
					);
				}, $conversions ) );
			}

			usort( $final_data, function ( $datum1, $datum2 ) {
				return strtotime( $datum1['date'] ) > strtotime( $datum2['date'] ) ? - 1 : 1;
			} );

			return $final_data;
		}

		/**
		 * @param $automation_id
		 * @param int $contat_id
		 *
		 * @return array
		 */
		public static function get_automation_recipient_timeline( $automation_id, $contact_id, $mode ) {
			$table       = self::_table();
			global $wpdb;
			$query       = $wpdb->prepare( " SELECT ID,mode,c_status,c_interaction,o_interaction,created_at FROM $table WHERE type = 1 AND oid = %d AND cid = %d AND (c_status = 2 OR c_status = 3) AND mode = %d", $automation_id, $contact_id, $mode ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from _table(); values bound via %d
			$engagements = self::get_results( $query );
			$engage_ids  = array_map( function ( $engagement ) {
				return $engagement['ID'];
			}, $engagements );
			$conversions = BWFAN_Model_Conversions::get_conversions_by_oid( $automation_id, $contact_id, $engage_ids );

			$final_data = [];
			foreach ( $engagements as $engagement ) {
				$final_data = array_merge( $final_data, self::prepare_timeline_data( $engagement ) );
			}

			/** Conversions */
			$final_data = array_merge( $final_data, array_map( function ( $conversion ) {
				$order = wc_get_order( $conversion['wcid'] );

				return array(
					'type'     => 'conversion',
					'date'     => ! empty( $conversion['date'] ) ? get_date_from_gmt( $conversion['date'] ) : '',
					'revenue'  => $order->get_total(),
					'order_id' => $conversion['wcid'],
					'currency' => BWFAN_Automations::get_currency( $order->get_currency() )
				);
			}, $conversions ) );

			usort( $final_data, function ( $data1, $data2 ) {
				return strtotime( $data1['date'] ) > strtotime( $data2['date'] ) ? - 1 : 1;
			} );

			return $final_data;
		}

		/**
		 * @param $oid (Conversation's Source ID)
		 * @param int $o_type (Conversation's Source Type)
		 *
		 * @return false|string|null
		 */
		public static function get_first_conversation_date( $oid, $o_type = 1 ) {
			if ( empty( $oid ) ) {
				return false;
			}

			global $wpdb;

			$query = "SELECT MIN(created_at) FROM `{$wpdb->prefix}bwfan_engagement_tracking` where oid = " . intval( $oid ) . " and type = " . intval( $o_type );

			return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- oid and type intval()ed when building the query
		}

		/**
		 * @param $after
		 * @param $before
		 *
		 * @return array|object|null
		 */
		public static function get_stats( $after, $before ) {
			global $wpdb;

			$query = "select count( ID ) as sent_total, sum( open ) as total_open, sum( click ) as total_click from {$wpdb->prefix}bwfan_engagement_tracking where c_status = 2 group by c_status";

			return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; table name from $wpdb->prefix
		}

		/**
		 * @return array|object|null
		 */
		public static function get_popular_emails() {
			global $wpdb;
			$template_table   = $wpdb->prefix . 'bwfan_templates';
			$engagement_table = $wpdb->prefix . 'bwfan_engagement_tracking';

			$query   = "SELECT `tid`, SUM(`open`) as `opens`, SUM(`click`) as `clicks` FROM $engagement_table WHERE tid IS NOT NULL AND open > 0 GROUP BY tid ORDER BY `opens` DESC LIMIT 0,5";
			$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; table name from $wpdb->prefix

			if ( empty( $results ) ) {
				return [];
			}

			$tids = array_column( $results, 'tid' );

			$placeholders = array_fill( 0, count( $tids ), '%d' );
			$placeholders = implode( ', ', $placeholders );

			$query   = $wpdb->prepare( "SELECT `ID`, `subject` FROM $template_table WHERE `ID` IN ($placeholders)", $tids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders via array_fill; ids bound via %d
			$result2 = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above

			$subjects = [];
			foreach ( $result2 as $val ) {
				$subjects[ $val['ID'] ] = $val['subject'];
			}

			foreach ( $results as $key => $val ) {
				if ( isset( $subjects[ $val['tid'] ] ) ) {
					$results[ $key ]['subject'] = $subjects[ $val['tid'] ];
				}
			}

			return $results;
		}

		public static function get_last_engagement_sent_time( $contact_id, $mode = 1 ) {
			$query   = "SELECT max(created_at) as last_sent FROM {table_name} WHERE cid = %d AND mode = %d AND c_status = 2";
			$results = self::get_results( $query, array( absint( $contact_id ), absint( $mode ) ) );
			$res     = '';
			if ( ! empty( $results[0]['last_sent'] ) ) {
				$res = $results[0]['last_sent'];
			}

			return $res;
		}

		public static function get_last_24_hours_conversations_count( $mode = 1 ) {
			global $wpdb;
			$mode       = absint( $mode );
			$start_time = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );
			$and_mode   = ! empty( $mode ) ? " AND mode = {$mode} " : "";
			$query      = $wpdb->prepare( "SELECT COUNT(ID)  FROM {$wpdb->prefix}bwfan_engagement_tracking WHERE c_status = 2 AND created_at > %s {$and_mode}", $start_time ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- mode absint()ed before interpolation

			return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
		}

		public static function get_most_interacted_template( $oid, $otype, $mode ) {
			global $wpdb;

			$interaction = ( BWFAN_Email_Conversations::$MODE_SMS === intval( $mode ) ) ? 'click' : 'open';
			$interaction = apply_filters( 'bwfan_most_interacted_template_based_on', $interaction, $mode, $oid );

			$interaction = in_array( $interaction, array( 'click', 'open' ), true ) ? $interaction : 'open';
			$sql = "SELECT tid, SUM($interaction) AS $interaction FROM `{$wpdb->prefix}bwfan_engagement_tracking` WHERE oid=" . absint( $oid ) . " AND type=" . absint( $otype ) . " AND c_status=2 GROUP BY tid ORDER BY $interaction DESC LIMIT 0, 1";

			return $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- column whitelisted via in_array strict; ids absint()ed
		}

		/**
		 * Return first engagement id
		 */
		public static function get_first_engagement_id() {
			global $wpdb;
			$query = 'SELECT MIN(`ID`) FROM ' . self::_table();

			return $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table name from _table(); no user input
		}

		/**
		 * Get automation step analytics
		 *
		 * @param $oid
		 * @param $step_ids
		 * @param $after_date
		 *
		 * @return array|object|stdClass
		 */
		public static function get_automation_step_analytics( $oid, $step_ids, $after_date = '', $end_date = '' ) {
			global $wpdb;

			if ( empty( $step_ids ) ) {
				return [];
			}

			$table    = "{$wpdb->prefix}bwfan_engagement_tracking";
			$step_ids = ! is_array( $step_ids ) ? [ $step_ids ] : $step_ids;
			$step_ids = array_map( 'absint', $step_ids );
			$ids_ph   = implode( ', ', array_fill( 0, count( $step_ids ), '%d' ) );

			$type = absint( BWFAN_Email_Conversations::$TYPE_AUTOMATION );

			/**
			 * `trackid` only points at an engagement row, it does not identify the source of the
			 * conversion, so an unscoped sub-query lets conversions recorded against another
			 * automation land on this one's steps. Scope it the same way the outer query is scoped.
			 *
			 * Grouping by `trackid` alone (`cid` is not used downstream) keeps this to one row per
			 * engagement, so the join cannot duplicate an engagement row and inflate the
			 * open/click/sent aggregates that are counted in the same query.
			 *
			 * The sub-query is embedded ahead of the outer query's own placeholders, so its
			 * arguments are collected separately and prepended to $args below.
			 */
			$conversions_where = 'otype = %d AND trackid > 0';
			$conversions_args  = array( $type );
			if ( ! empty( $oid ) ) {
				$conversions_where  .= ' AND oid = %d';
				$conversions_args[] = absint( $oid );
			}

			$conversions_query = "SELECT trackid, count(ID) as conversions, SUM(wctotal) as revenue FROM {$wpdb->prefix}bwfan_conversions WHERE {$conversions_where} GROUP BY trackid";
			$query             = "SELECT SUM(if(con.open>0,1,0)) AS open_count,(SUM(IF(con.open>0, 1, 0))/COUNT(con.ID)) * 100 as open_rate ,SUM(IF(con.c_status=2, 1, 0)) as sent,SUM(if(con.click>0,1,0)) AS click_count,(SUM(IF(con.click>0, 1, 0))/COUNT(con.ID)) * 100 as click_rate,  COALESCE(SUM(conv.conversions), 0) as conversions, COALESCE(SUM(conv.revenue), 0) as revenue, COUNT(DISTINCT con.cid) as contacts_count  FROM {$table} AS con LEFT JOIN ({$conversions_query}) as conv ON con.ID = conv.trackid WHERE 1=1 AND con.type = %d AND con.sid IN ({$ids_ph}) AND con.c_status = 2 ";
			$args              = array_merge( $conversions_args, array( $type ), $step_ids );
			if ( ! empty( $oid ) ) {
				$query  .= " AND con.oid IN (%d) ";
				$args[] = absint( $oid );
			}
			/** Add query for get data after date */
			if ( ! empty( $after_date ) && empty( $end_date ) ) {
				$query  .= " AND con.created_at > %s";
				$args[] = $after_date;
			}

			if ( ! empty( $after_date ) && ! empty( $end_date ) ) {
				$query  .= " AND ( con.created_at > %s AND con.created_at < %s ) ";
				$args[] = $after_date;
				$args[] = $end_date;
			}

			$query                    = $wpdb->prepare( $query, $args ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$results                  = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare(); ids absint()ed
			$results['unsubscribers'] = self::get_automation_unsubscribers( $oid, $step_ids );

			return $results;
		}

		public static function get_automation_unsubscribers( $aid, $step_ids = [] ) {
			global $wpdb;
			$query = "SELECT COUNT(*) FROM {$wpdb->prefix}bwfan_message_unsubscribe WHERE `automation_id`=%d AND `c_type`=1";
			$args  = [ $aid ];

			if ( count( $step_ids ) > 0 ) {
				$placeholders = array_fill( 0, count( $step_ids ), '%d' );
				$placeholders = implode( ', ', $placeholders );
				$query        .= " AND `sid` IN($placeholders) ";
				$args         = array_merge( $args, $step_ids );
			}

			$query = $wpdb->prepare( $query, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders via array_fill; values bound in prepare()

			return absint( $wpdb->get_var( $query ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
		}

		public static function get_contact_engagements( $aid, $cid, $start_date, $last_date ) {
			global $wpdb;
			$table = self::_table();
			$query = $wpdb->prepare( "SELECT ID FROM {$table} WHERE cid = %d AND oid = %d AND type = 1 AND `created_at` BETWEEN %s AND %s", $cid, $aid, $start_date, $last_date ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from _table(); values bound via prepare()

			return $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
		}

		public static function delete_contact_engagements( $ids ) {
			if ( empty( $ids ) ) {
				return;
			}

			global $wpdb;
			$table = self::_table();

			$placeholders = array_fill( 0, count( $ids ), '%d' );
			$placeholders = implode( ', ', $placeholders );

			$query = $wpdb->prepare( "DELETE FROM {$table} WHERE ID IN ($placeholders)", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders via array_fill; ids bound via %d

			return $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
		}

		public static function get_engagements_tid( $aid, $sids ) {
			global $wpdb;
			if ( ! is_array( $sids ) || empty( $sids ) ) {
				return [];
			}

			$placeholders = array_fill( 0, count( $sids ), '%d' );
			$placeholders = implode( ', ', $placeholders );
			$args         = [ $aid, 1 ];
			$args         = array_merge( $args, $sids );
			$query        = "SELECT `sid`, `tid` FROM {$wpdb->prefix}bwfan_engagement_tracking WHERE `oid` = %d AND `type` = %d AND `sid` IN ($placeholders)";

			return $wpdb->get_results( $wpdb->prepare( $query, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholders via array_fill; values bound in prepare()
		}

		/**
		 * Get email activity by filters
		 *
		 * @param string $search
		 * @param array $filters
		 * @param int $offset
		 * @param int $limit Limit
		 * @param int $mode
		 *
		 * @return array
		 *
		 */
		public static function get_engagements_activity( $search = '', $filters = [], $offset = 0, $limit = 10, $mode = 0 ) {
			global $wpdb;
			$table        = self::_table();
			$query        = "SELECT `ID`,`cid`,`created_at`,`type`,`send_to`,`open`,`click`,`oid`,`author_id`,`tid`,`c_status`,`mode` FROM {$table} WHERE 1=1";
			$count        = "SELECT COUNT(`ID`) AS total_count FROM {$table} WHERE 1=1";
			$con_data     = [];
			$filter_query = '';

			if ( intval( $mode ) > 0 ) {
				$mode_query = $wpdb->prepare( " AND mode = %d", intval( $mode ) );
				$query      .= $mode_query;
				$count      .= $mode_query;
			}

			if ( ! empty( $search ) ) {
				$filter_query .= $wpdb->prepare( " AND send_to LIKE %s", "%" . $wpdb->esc_like( $search ) . "%" );
			}

			/** Default status filter query to exclude drafts */
			$status_filter_query = $wpdb->prepare( " AND c_status != %d", 1 );

			/** Filters passed */
			foreach ( $filters as $filter ) {
				switch ( $filter['filter'] ) {
					case 'source':
						if ( ! empty( $filter['data'] ) ) {
							$oid = array_map( 'absint', array_column( $filter['data'], 'id' ) );
						}
						switch ( $filter['rule'] ) {
							case 1:
								if ( ! empty( $oid ) ) {
									$filter_query .= " AND oid IN (" . implode( ', ', $oid ) . ") AND type = 1";
								} else {
									$filter_query .= " AND type = 1";
								}
								break;

							case 2:
								if ( ! empty( $oid ) ) {
									$filter_query .= " AND oid IN (" . implode( ', ', $oid ) . ") AND type = 2";
								} else {
									$filter_query .= " AND type = 2";
								}
								break;
							case 6:
								$filter_query .= " AND type = 6";
								break;
							case 9:
								$filter_query .= " AND type = 9";
								break;
						}
						break;
					case 'status':
						$status = ! empty( $filter['data'] ) ? $filter['data'] : '';
						if ( ! empty( $status ) ) {
							$status_filter_query = $wpdb->prepare( " AND c_status = %d", $status );
						}
						break;
					case 'period':
						$start_date = ! empty( $filter['data']['after'] ) ? $filter['data']['after'] : '';
						$end_date   = ! empty( $filter['data']['before'] ) ? $filter['data']['before'] : '';
						if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
							$filter_query .= $wpdb->prepare( " AND (created_at >= %s AND created_at <= %s)", $start_date, $end_date );
						}
						break;
				}
			}

			/** Append Status filter query */
			$filter_query .= $status_filter_query;

			$query .= $filter_query;
			$query .= $wpdb->prepare( " ORDER BY ID DESC LIMIT %d OFFSET %d", $limit, $offset );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built from prepare() fragments and absint() ids
			$results = $wpdb->get_results( $query, ARRAY_A );
			if ( empty( $results ) ) {
				return array(
					'data'  => [],
					'total' => 0
				);
			}

			/** All Contact IDs */
			$cids = array_unique( array_column( $results, 'cid' ) );

			$con_query    = "SELECT ID, f_name, email, l_name FROM {$wpdb->prefix}bwf_contact WHERE ID IN (" . implode( ',', array_map( 'absint', $cids ) ) . ")";
			$contact_data = $wpdb->get_results( $con_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- ids absint()ed before implode

			/** Contact array with ID as key */
			$contact_map = array_column( $contact_data, null, 'ID' );

			/** Single Template IDs array */
			$tids              = [];
			$message_track_ids = [];
			foreach ( $results as $result ) {
				if ( empty( $result['tid'] ) ) {
					$message_track_ids[] = $result['ID'];
					continue;
				}
				$tids[] = $result['tid'];
			}

			$template      = "SELECT ct.ID, ct.subject, ct.title, ct.mode FROM {$wpdb->prefix}bwfan_templates AS ct WHERE ct.ID IN (" . implode( ',', array_map( 'absint', $tids ) ) . ")";
			/** No template ids -> "IN ()" is invalid SQL; get_results() returned [] on that error, so return [] without the failing query */
			$template_data = empty( $tids ) ? array() : $wpdb->get_results( $template, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- template ids absint()ed; query skipped when empty

			/** Map subjects by ID */
			$template_map       = [];
			$transactional_data = [];
			foreach ( $template_data as $template ) {
				if ( class_exists( 'BWFCRM_Core' ) && isset( BWFCRM_Core()->transactional_mails ) && ! empty( $template['mode'] ) && 7 === absint( $template['mode'] ) ) {
					if ( ! isset( $transactional_data[ $template['title'] ] ) ) {
						$mail_class                               = BWFCRM_Core()->transactional_mails;
						$transactional_val                        = $mail_class->get_transactional_mail_by_slug( $template['title'] );
						$title                                    = ! empty( $transactional_val['title'] ) ? $transactional_val['title'] : $template['title'];
						$transactional_data[ $template['title'] ] = $transactional_val['title'] ?? '';
					} else {
						$transactional_data[ $template['title'] ] = $template['title'];
					}
				} else {
					$title = '';
				}
				$template_map[ $template['ID'] ] = [
					'subject' => $template['subject'],
					'title'   => $title
				];
			}

			$message_data = [];
			if ( ! empty( $message_track_ids ) ) {
				$m_data = BWFAN_Model_Message::get_message_by_track_id( $message_track_ids );
				foreach ( $m_data as $data ) {
					$message_data[ $data['track_id'] ] = $data['subject'];
				}
			}

			$con_class = new BWFAN_Email_Conversations();

			foreach ( $results as $key => $val ) {
				$con_data[ $key ]['ID']   = $val['ID'];
				$con_data[ $key ]['mode'] = $val['mode'];
				$con_data[ $key ]['type'] = $val['type'];

				/** Retrieve contact details from map */
				$contact = $contact_map[ $val['cid'] ] ?? array();

				$con_data[ $key ]['contact'] = [];
				if ( ! empty( $contact ) ) {
					$con_data[ $key ]['contact'] = array(
						'id'     => $contact['ID'],
						'f_name' => $contact['f_name'],
						'l_name' => $contact['l_name'],
						'email'  => $contact['email'],
						'link'   => add_query_arg( array( 'page' => 'autonami', 'path' => '/contact/' . $contact['ID'] . '/profile' ), admin_url( 'admin.php' ) ),
					);
				}

				/** Get source data */
				$source = $con_class->get_source( [
					'oid'       => $val['oid'],
					'type'      => $val['type'],
					'author_id' => $val['author_id']
				], ! empty( $val['tid'] ) && isset( $template_map[ $val['tid'] ] ) ? $template_map[ $val['tid'] ] : [] );

				if ( ! empty( $source ) ) {
					$con_data[ $key ]['source'] = $source;
				}

				/** Set sent status and sent date */
				$con_data[ $key ]['c_status'] = $val['c_status'];
				$con_data[ $key ]['sent_on']  = $val['created_at'];
				$con_data[ $key ]['open']     = $val['open'];
				$con_data[ $key ]['click']    = $val['click'];
				$con_data[ $key ]['send_to']  = $val['send_to'];

				/** Retrieve subject from map */
				$subject                     = ! empty( $val['tid'] ) && isset( $template_map[ $val['tid'] ] ) && $template_map[ $val['tid'] ]['subject'] ? $template_map[ $val['tid'] ]['subject'] : '';
				$subject                     = empty( $subject ) && isset( $message_data[ $val['ID'] ] ) ? $message_data[ $val['ID'] ] : $subject;
				$con_data[ $key ]['subject'] = ! empty( $subject ) ? $subject : __( 'No Subject', 'wp-marketing-automations' );
			}

			return [
				'data'  => $con_data,
				'total' => $wpdb->get_var( $count . $filter_query ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built from prepare() fragments and absint() ids
			];
		}

		public static function get_engagements_by_tid( $tid, $only_count = false ) {
			global $wpdb;
			$table = self::_table();
			$tid   = absint( $tid );
			if ( $only_count ) {
				$query = $wpdb->prepare( "SELECT COUNT(ID) as count FROM {$table} WHERE tid = %d", $tid ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from _table(); tid absint()ed
			} else {
				$query = $wpdb->prepare( "SELECT * FROM {$table} WHERE tid = %d", $tid ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from _table(); tid absint()ed
			}


			return $only_count ? $wpdb->get_var( $query ) : $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
		}
	}
}
