<?php

namespace BWFAN\Importers;

defined( 'ABSPATH' ) || exit;

use BWFAN\Importers\Importer;
use WP_Error;
use WP_Meta_Query;

/**
 * Class WP_Importer
 *
 * This class represents a WP importer for the CRM system.
 */
#[\AllowDynamicProperties]
class WP_Importer extends Importer {
	const LIMIT = 25;

	protected $import_type = 'wp';

	public function __construct( $params = array() ) {
		$this->slug        = 'wp';
		$this->name        = __( 'WordPress', 'wp-marketing-automations' );
		$this->description = __( 'Import contacts from WordPress users', 'wp-marketing-automations' );
		$this->logo_url    = esc_url( plugin_dir_url( BWFAN_PLUGIN_FILE ) . '/admin/assets/img/importer/wp.png' );
		$this->has_fields  = false;
		$this->group       = 0;
		$this->priority    = 6;
		$default_args      = array(
			'roles' => array(),
		);

		$params = wp_parse_args( $params, $default_args );

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
		// Use efficient count query instead of loading all user IDs
		$import_data['count']         = $this->get_contacts_count();
		$import_data['meta']['roles'] = $this->params['roles'];

		return $import_data;
	}

	/**
	 * Prepare the data for importing users.
	 */
	public function populate_contact_data() {
		$user_arr = $this->get_wp_users_array( $this->get_offset(), $this->get_import_meta( 'roles' ), self::LIMIT );
		if ( empty( $user_arr ) ) {
			return [];
		}

		$users    = get_users( array(
			'include' => $user_arr,
		) );
		$contacts = array();
		if ( ! empty( $users ) ) {
			foreach ( $users as $user ) {
				$contacts[ absint( $user->ID ) ] = $user;
			}
			krsort( $contacts, 1 );
		}

		return $contacts;
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
			'ID'      => is_wp_error( $result ) || empty( $result ) ? 0 : $result['id'],
			'User ID' => $data->ID ?? 0,
			'Status'  => is_wp_error( $result ) ? __( 'Failed', 'wp-marketing-automations' ) : ( isset( $result['skipped'] ) && $result['skipped'] ? __( 'Skipped', 'wp-marketing-automations' ) : __( 'Success', 'wp-marketing-automations' ) ),
		];
	}

	/**
	 * Returns current processing users id
	 *
	 * Retrieves WordPress user IDs based on role filters and pagination.
	 * Uses WP_Meta_Query to filter users by capabilities/roles.
	 *
	 * @param int    $offset   Optional. Offset for pagination. Default 0.
	 * @param array  $role__in Optional. Array of role slugs to filter by. Default empty array.
	 * @param string $limit    Optional. Maximum number of users to retrieve. Default empty string (no limit).
	 *
	 * @return array Array of user IDs matching the criteria.
	 */
	public function get_wp_users_array( $offset = 0, $role__in = array(), $limit = '' ) {
		global $wpdb;
		$role__in_clauses = array( 'relation' => 'OR' );

		if ( ! empty( $role__in ) ) {
			foreach ( $role__in as $role ) {
				$role__in_clauses[] = array(
					'key'     => $wpdb->prefix . 'capabilities',
					'value'   => '"' . $role . '"',
					'compare' => 'LIKE',
				);
			}
		}

		$meta_query          = new WP_Meta_Query();
		$meta_query->queries = array(
			'relation' => 'AND',
			array( $role__in_clauses ),
		);

		$metadata = $meta_query->get_sql( 'user', $wpdb->users, 'ID', $role__in_clauses );
		$query    = 'SELECT ID from ' . $wpdb->users . ' ' . $metadata['join'] . " WHERE 1 = 1 " . $metadata['where'] . ' GROUP BY ' . $wpdb->users . '.`ID` ORDER BY ID DESC ';
		
		// Sanitize pagination parameters
		$offset = absint( $offset );
		$limit  = absint( $limit );
		
		if ( $limit > 0 ) {
			$query .= $wpdb->prepare( ' LIMIT %d, %d', $offset, $limit );
		}

		return $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- meta SQL from WP_Meta_Query (core-escaped); LIMIT via prepare()
	}

	/**
	 * Returns all user roles for selection in import process
	 *
	 * @return mixed|string|void
	 */
	public static function get_wp_roles() {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$roles = get_editable_roles();
		if ( ! is_array( $roles ) || empty( $roles ) ) {
			return __( 'Invalid User Roles / Unknown Error', 'wp-marketing-automations' );
		}

		$roles_for_api = array();
		foreach ( $roles as $slug => $role ) {
			$roles_for_api[ $slug ] = $role['name'] ?? $slug;
		}

		return apply_filters( 'bwfan_wp_roles_for_import', $roles_for_api );
	}

	/**
	 * Get the latest count of WordPress Users.
	 *
	 * @return int The number of Users from WordPress
	 */
	public function get_contacts_count() {
		global $wpdb;
		$role__in = $this->get_import_meta( 'roles' );
		
		// If no roles specified, use efficient COUNT query
		if ( empty( $role__in ) || ! is_array( $role__in ) ) {
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			return ! empty( $count ) ? absint( $count ) : 0;
		}
		
		// Build efficient COUNT query with role filtering
		$role__in_clauses = array( 'relation' => 'OR' );
		foreach ( $role__in as $role ) {
			$role__in_clauses[] = array(
				'key'     => $wpdb->prefix . 'capabilities',
				'value'   => '"' . esc_sql( $role ) . '"',
				'compare' => 'LIKE',
			);
		}
		
		$meta_query          = new WP_Meta_Query();
		$meta_query->queries = array(
			'relation' => 'AND',
			array( $role__in_clauses ),
		);
		
		$metadata = $meta_query->get_sql( 'user', $wpdb->users, 'ID', $role__in_clauses );
		$query    = "SELECT COUNT(DISTINCT {$wpdb->users}.ID) FROM {$wpdb->users} {$metadata['join']} WHERE 1=1 {$metadata['where']}";
		
		$count = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- meta SQL from WP_Meta_Query (core-escaped); tables from $wpdb->users
		
		return ! empty( $count ) ? absint( $count ) : 0;
	}

	/**
	 * Get the second step fields for wp importer
	 *
	 * @return array
	 *
	 */
	public function get_second_step_fields() {
		$roles = self::get_wp_roles();

		return [
			[
				'id'       => 'roles',
				'type'     => 'checkbox',
				'selected' => is_array( $roles ) ? array_keys( $roles ) : [],
				'label'    => __( 'Roles', 'wp-marketing-automations' ),
				'options'  => $roles,
			]
		];
	}

	/**
	 * Handle the update process for WP import.
	 *
	 * @return array|WP_Error The result of the update process or an error.
	 */
	public function handle_update( $data ) {
		$roles = ! empty( $data['roles'] ) && is_array( $data['roles'] ) ? $data['roles'] : array();

		if ( empty( $roles ) ) {
			return new WP_Error( 'bwfcrm_missing_roles', __( 'Please select at least one user role', 'wp-marketing-automations' ) );
		}

		return array(
			'roles' => $roles
		);
	}
}

BWFAN_Core()->importer->register( 'wp', 'BWFAN\Importers\WP_Importer' );