<?php
defined( 'ABSPATH' ) || exit;


if ( ! class_exists( 'BWFAN_Model_Link_Metrics' ) ) {

	#[\AllowDynamicProperties]
	class BWFAN_Model_Link_Metrics extends BWFAN_Model {

		public static function get_link_metrics( $link_id, $cid ) {
			global $wpdb;

			$query = " SELECT * FROM {$wpdb->prefix}bwfan_link_metrics WHERE `link_id` = %d AND `contact_id` = %d";

			return $wpdb->get_row( $wpdb->prepare( $query, $link_id, $cid ), ARRAY_A );//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

		}
	}
}
