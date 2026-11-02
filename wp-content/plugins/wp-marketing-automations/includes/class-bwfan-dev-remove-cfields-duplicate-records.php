<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'BWFAN_DEV_Remove_Contact_Fields_Duplicate_Records' ) ) {
	#[\AllowDynamicProperties]
	final class BWFAN_DEV_Remove_Contact_Fields_Duplicate_Records {
		private static $ins = null;

		public function __construct() {
			$duplicate_records = filter_input( INPUT_GET, 'remove_fields_duplicate_records' );
			if ( 1 === intval( $duplicate_records ) ) {
				add_action( 'admin_head', [ $this, 'check_records' ] );
			}
		}

		public static function get_instance() {
			if ( null === self::$ins ) {
				self::$ins = new self();
			}

			return self::$ins;
		}

		/**
		 * Check for duplicate contact field records
		 *
		 * @return void
		 */
		public function check_records() {
			/** Fetch rows */
			$ids = $this->check_if_duplicate_rows();
			if ( empty( $ids ) ) {
				BWFAN_Common::pr( 'No duplicate records found' );
				exit;
			}

			/** Delete rows */
			$response = $this->delete_duplicate_rows( $ids );
			if ( true === $response ) {
				$this->check_records();
			}
			exit;
		}

		/**
		 * Checks if there are any duplicate records in the contact fields table
		 *
		 * @return array|false
		 */
		public function check_if_duplicate_rows() {
			global $wpdb;
			$query = "SELECT `cid`, count(ID) as `count`, MAX(`ID`) AS `key` FROM `{$wpdb->prefix}bwf_contact_fields` GROUP BY `cid` HAVING COUNT(ID) > 1";

			$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL; table name from $wpdb->prefix
			if ( empty( $query ) ) {
				return false;
			}

			return array_column( $rows, 'key' );
		}

		/**
		 * Delete contact fields duplicate rows
		 *
		 * @param $ids
		 *
		 * @return bool
		 */
		public function delete_duplicate_rows( $ids = [] ) {
			if ( empty( $ids ) ) {
				return false;
			}
			global $wpdb;

			$string_placeholder = array_fill( 0, count( $ids ), '%d' );
			$placeholder        = implode( ', ', $string_placeholder );

			$query = $wpdb->prepare( "DELETE FROM `{$wpdb->prefix}bwf_contact_fields` WHERE `ID` IN ({$placeholder})", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated via array_fill; ids bound via %d

			BWFAN_Common::pr( count( $ids ) . " contacts duplicate rows deleted" );

			return ( $wpdb->query( $query ) > 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
		}
	}

	BWFAN_DEV_Remove_Contact_Fields_Duplicate_Records::get_instance();
}
