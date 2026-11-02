<?php

namespace BWFAN\Importers;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Class AFFWP_Importer
 *
 * This class represents an AffiliateWP importer for the CRM system.
 */
#[\AllowDynamicProperties]
class AFFWP_Importer extends Importer {
	const LIMIT = 25;

	protected $import_type = 'affwp';

	public function __construct( $params = array() ) {
		$this->slug        = 'affwp';
		$this->name        = __( 'AffiliateWP', 'wp-marketing-automations' );
		$this->description = __( 'Import contacts from AffiliateWP affiliates', 'wp-marketing-automations' );
		$this->logo_url    = esc_url( plugin_dir_url( BWFAN_PLUGIN_FILE ) . '/admin/assets/img/importer/affwp.png' );
		$this->has_fields  = false;
		$this->group       = 0;
		$this->priority    = 9;
		parent::__construct( $params );
	}

	/**
	 * Prepares the import data for creating a new import.
	 *
	 * @param array $import_data The import data.
	 *
	 * @return array The prepared import data.
	 */
	public function prepare_create_import_data( $import_data = array(), $fields = array() ) {
		$import_data['count'] = $this->get_contacts_count();

		return $import_data;
	}

	/**
	 * Prepare the data for importing affiliates.
	 */
	public function populate_contact_data() {
		$user_ids = $this->get_affiliates( array(
			'offset' => $this->get_offset(),
			'limit'  => self::LIMIT,
		) );

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
	 * Get the count of affiliates.
	 *
	 * @return int The count of affiliates.
	 */
	public function get_contacts_count() {
		return $this->get_affiliates( array(
			'count_only' => true,
		) );
	}

	/**
	 * Retrieves affiliates based on the provided arguments.
	 *
	 * @param array $args Optional. An array of arguments for retrieving affiliates.
	 *
	 * @return array|int An array of affiliate user IDs or the count of affiliates.
	 */
	public function get_affiliates( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args( $args, array(
			'offset'     => 0,
			'limit'      => self::LIMIT,
			'order'      => 'ASC',
			'order_by'   => 'user_id',
			'count_only' => false,
		) );

		// Whitelist allowed columns for ORDER BY to prevent SQL injection
		$allowed_columns = array( 'user_id', 'date_registered' );
		$allowed_orders  = array( 'ASC', 'DESC' );

		// Sanitize order_by - only allow whitelisted columns
		$order_by = in_array( $args['order_by'], $allowed_columns, true ) ? $args['order_by'] : 'user_id';

		// Sanitize order - only allow ASC or DESC
		$order = in_array( strtoupper( $args['order'] ), $allowed_orders, true ) ? strtoupper( $args['order'] ) : 'ASC';

		// Sanitize pagination parameters
		$offset = absint( $args['offset'] );
		$limit  = absint( $args['limit'] );

		if ( true === $args['count_only'] ) {
			// Use get_var instead of get_col for COUNT queries (more efficient)
			$count = $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}affiliate_wp_affiliates" ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL

			return ! empty( $count ) ? absint( $count ) : 0;
		}

		// Build pagination query with prepared statement
		$pagination_query = '';
		if ( $limit > 0 ) {
			$pagination_query = $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		// Note: wpdb->prepare doesn't support ORDER BY placeholders, so we use esc_sql for column names
		// Since we've whitelisted the values, it's safe to use them directly
		$order_by_safe = esc_sql( $order_by );
		$order_safe     = esc_sql( $order );
		$query          = "SELECT DISTINCT user_id FROM {$wpdb->prefix}affiliate_wp_affiliates ORDER BY {$order_by_safe} {$order_safe}" . $pagination_query;

		$users = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- order by whitelisted; LIMIT prepared; prefix table

		if ( empty( $users ) ) {
			return array();
		}

		$users = array_column( $users, 'user_id' );
		$users = array_map( 'intval', $users );

		return array_filter( $users );
	}

	/**
	 * Get the log headers for this importer.
	 *
	 * @return array
	 */
	protected function get_log_headers() {
		return [ 'ID', 'User ID', 'Status' ];
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
			'ID'      => is_wp_error( $result ) ? 0 : $result['id'],
			'User ID' => $data->ID ?? 0,
			'Status'  => is_wp_error( $result ) ? __( 'Failed', 'wp-marketing-automations' ) : ( isset( $result['skipped'] ) && $result['skipped'] ? __( 'Skipped', 'wp-marketing-automations' ) : __( 'Success', 'wp-marketing-automations' ) ),
		];
	}

	/**
	 * Get the second step fields for affwp importer
	 *
	 * @return array
	 */
	public function get_second_step_fields() {
		$count       = $this->get_contacts_count();
		$this->count = $count;
		$message     = __( 'There are no affiliate to import', 'wp-marketing-automations' );
		$type        = 'error';
		if ( $count > 0 ) {
			/* translators: 1: Affiliate count */
			$message = sprintf( __( 'There are %d affiliates to be imported', 'wp-marketing-automations' ), $count ); //translators: number of affiliates to be imported
			$type    = 'warning';
		}

		return [
			[
				'id'          => 'wc_notice',
				'type'        => 'notice',
				'noticeLabel' => __( 'Affiliates', 'wp-marketing-automations' ),
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

if ( function_exists( 'bwfan_is_affiliatewp_active' ) && bwfan_is_affiliatewp_active() ) {
	BWFAN_Core()->importer->register( 'affwp', 'BWFAN\Importers\AFFWP_Importer' );
}
