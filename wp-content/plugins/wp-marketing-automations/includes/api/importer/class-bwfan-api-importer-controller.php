<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BWFAN\Importers\Importer;

class BWFAN_API_Importer_Controller extends BWFAN_API_Controller {

	public static $ins;
	public $total_count = 0;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		$this->pagination         = new stdClass();
		$this->pagination->offset = 0;
		$this->pagination->limit  = 20;
		parent::__construct();
	}

	public function default_args_values() {
		return array(
			'importer_type' => '',
			'importer_id'   => 0,
			'fields'        => [],
			'ids'           => [],
			'delete_pending' => 0,
			'type'          => '',
			'data'          => [],
			'id'            => 0,
			'action'        => '',
		);
	}

	protected function register_routes() {
		// GET /contacts/importers
		$this->add_route( '/contacts/importers', array(
			array( 'GET', 'get_importers' ),
		) );

		// POST /contacts/import
		$this->add_route( '/contacts/import', array(
			array( 'POST', 'create_import' ),
		) );

		// DELETE /contacts/import/delete
		$this->add_route( '/contacts/import/delete', array(
			array( 'DELETE', 'delete_imports' ),
		) );

		// PUT /contacts/import/{id}
		$this->add_route( '/contacts/import/(?P<id>\d+)', array(
			array( 'PUT', 'update_import' ),
		) );

		// GET /contacts/import/{id}/status
		$this->add_route( '/contacts/import/(?P<id>\d+)/status', array(
			array( 'GET', 'get_import_status' ),
		) );

		// GET /contacts/import/{id}/cancel
		$this->add_route( '/contacts/import/(?P<id>\d+)/cancel', array(
			array( 'GET', 'cancel_import' ),
		) );

		// GET /contacts/importer/listing
		$this->add_route( '/contacts/importer/listing', array(
			array( 'GET', 'get_importer_listing' ),
		) );

		// GET /importer-log/download/{importer_id}
		$this->add_route( '/importer-log/download/(?P<importer_id>[\\d]+)', array(
			array( 'GET', 'download_importer_log_file' ),
		) );
	}

	/**
	 * GET /contacts/importers
	 *
	 * @throws ReflectionException
	 */
	public function get_importers() {
		// Get all available importers
		$importer_classes = BWFAN_Core()->importer->get_importers();

		if ( empty( $importer_classes ) ) {
			return $this->error_response( __( "No importers found", 'wp-marketing-automations' ) );
		}

		// Prepare the importers data
		$importers_data = array();
		foreach ( $importer_classes as $slug => $importer_class ) {
			$importer = new $importer_class();

			if ( $importer instanceof Importer ) {
				$importers_data[ 0 ][] = array(
					'slug'          => $slug,
					'name'          => $importer->name,
					'description'   => $importer->description,
					'logo_url'      => $importer->logo_url,
					'has_fields'    => $importer->has_fields,
					'field_schema'  => $importer->get_field_schema(),
					'defaultValues' => $importer->get_default_values(),
					'priority'      => $importer->priority,
					'submit_text'  => ! empty( $importer->submit_text ) ? $importer->submit_text : __( 'Import', 'wp-marketing-automations' ),
				);
			}
		}

		if ( empty( $importers_data ) ) {
			return $this->error_response( __( "No valid importers found", 'wp-marketing-automations' ) );
		}

		// Sort the importers by group and priority
		foreach ( $importers_data as $group => $importers ) {
			usort( $importers, function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			} );
			$importers_data[ $group ] = $importers;
		}

		$this->response_code = 200;

		return $this->success_response( $importers_data );
	}

	/**
	 * POST /contacts/import
	 */
	public function create_import() {
		$importer_type = $this->get_sanitized_arg( 'importer_type', 'text_field' );
		$fields        = isset( $this->args['fields'] ) ? $this->args['fields'] : [];

		if ( empty( $importer_type ) ) {
			return $this->error_response( __( 'Importer type is required.', 'wp-marketing-automations' ), null, 400 );
		}

		// Create a new importer instance
		$importer = BWFAN_Core()->importer->get_importer( $importer_type );

		if ( ! $importer ) {
			return $this->error_response( __( 'Invalid importer type.', 'wp-marketing-automations' ), null, 400 );
		}


		/**
		 * Get the second step fields from the importer
		 *
		 * @return array.
		 * @var Importer $importer The importer object.
		 */
		$second_step_fields = method_exists( $importer, 'get_second_step_fields' ) ? $importer->get_second_step_fields( $fields ) : array();

		// Handle the additional fields needed for the configuration
		if ( isset( $second_step_fields['updated_schema'] ) && $second_step_fields['updated_schema'] && ! empty( $second_step_fields['fields'] ) ) {
			return $this->success_response( $second_step_fields, __( 'Additional fields fetched', 'wp-marketing-automations' ) );
		}

		if ( is_array( $second_step_fields ) && isset( $second_step_fields['status'] ) && 3 === $second_step_fields['status'] ) {
			return $this->error_response( $second_step_fields['message'], null, 400 );
		}

		$importer->create_import( $fields );
		$import_id = $importer->get_import_id();

		if ( $import_id === 0 ) {
			return $this->error_response( __( 'Error while creating a new import', 'wp-marketing-automations' ), null, 500 );
		}

		// Prepare the response data
		$response_data = array(
			'import_id' => $import_id,
			'data'      => [
				'second_step_schema' => $second_step_fields,
				'profile_fields'     => $importer->contact_profile_fields(),
				'stepValidation'     => method_exists( $importer, 'validate_second_step' ) ? $importer->validate_second_step() : true,
			]
		);

		return $this->success_response( $response_data, __( 'Import created successfully', 'wp-marketing-automations' ) );
	}

	/**
	 * DELETE /contacts/import/delete
	 */
	public function delete_imports() {
		$ids            = isset( $this->args['ids'] ) ? (array) $this->args['ids'] : [];
		$delete_pending = false;
		if ( isset( $this->args['delete_pending'] ) && intval( $this->args['delete_pending'] ) === 1 ) {
			$delete_pending = true;
		}

		if ( empty( $ids ) && ! $delete_pending ) {
			return $this->error_response( __( 'No import IDs provided', 'wp-marketing-automations' ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'bwfan_import_export';

		if ( ! $delete_pending ) {
			$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// Perform the delete operation
			$query = $wpdb->prepare( "DELETE FROM $table_name WHERE ID IN ($id_placeholders)", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- prefix table; %d placeholders generated in code; ids bound via prepare()
		} else {
			$query = $wpdb->prepare( "DELETE FROM $table_name WHERE type = %d AND status = %d", 1, 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix table; %d placeholders generated in code; ids bound via prepare()
		}

		$result = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prefix table; %d placeholders generated in code; ids bound via prepare()

		if ( $result === false ) {
			return $this->error_response( __( 'Failed to delete imports', 'wp-marketing-automations' ) );
		}

		$response = array(
			'deleted_count'   => $result,
			'total_requested' => count( $ids ),
		);

		return $this->success_response( $response, $delete_pending ? __( 'Successfully Deleted Pending Import', 'wp-marketing-automations' ) : __( 'Import deleted successfully', 'wp-marketing-automations' ) );
	}

	/**
	 * PUT /contacts/import/{id}
	 */
	public function update_import() {
		$importer_type = $this->get_sanitized_arg( 'type', 'text_field' );
		$data          = $this->args['data'] ?? [];
		$import_id     = $this->get_sanitized_arg( 'id', 'text_field' );

		if ( empty( $import_id ) ) {
			return $this->error_response( __( 'Import ID not found', 'wp-marketing-automations' ), null, 400 );
		}

		$importer = BWFAN_Core()->importer->get_importer( $importer_type, array( 'import_id' => $import_id ) );

		if ( ! $importer ) {
			return $this->error_response( __( 'Importer not found', 'wp-marketing-automations' ), null, 404 );
		}

		// Validate array sizes to prevent DoS attacks
		$tags = ! empty( $data['tags'] ) && is_array( $data['tags'] ) ? $data['tags'] : array();
		$lists = ! empty( $data['lists'] ) && is_array( $data['lists'] ) ? $data['lists'] : array();

		$update_data = [
			'tags'                    => $tags,
			'lists'                   => $lists,
			'update_existing'         => ! empty( $data['update_existing'] ),
			'skip_existing'           => ! empty( $data['skip_existing'] ),
			'disable_events'          => ! empty( $data['disable_events'] ),
			'dont_update_blank'       => ! empty( $data['dont_update_blank'] ),
			'trigger_events'          => ! empty( $data['trigger_events'] ),
			'marketing_status'        => ! empty( $data['marketing_status'] ) ? sanitize_text_field( $data['marketing_status'] ) : '',
			'imported_contact_status' => isset( $data['imported_contact_status'] ) ? absint( $data['imported_contact_status'] ) : 0,
			'update_existing_fields'  => ! empty( $data['update_existing_fields'] )
		];

		/**
		 * Check if importer has specific validation/data handling
		 *
		 * @var Importer $importer The importer object.
		 *
		 */
		if ( method_exists( $importer, 'handle_update' ) ) {
			$result = $importer->handle_update( $data );
			if ( is_wp_error( $result ) ) {
				return $this->error_response( $result->get_error_message(), null, 500 );
			}
			if ( ! empty( $result ) ) {
				$update_data = array_merge( $update_data, $result );
			}
		}

		// Update meta
		$importer->update_import_meta( $update_data );

		// Start import
		$importer->start_import();

		$importer_label = $importer->name;
		/* translators: 1: Importer label */
		return $this->success_response( $importer->get_status(), sprintf( __( '%s import updated successfully', 'wp-marketing-automations' ), $importer_label ) );
	}

	/**
	 * GET /contacts/import/{id}/status
	 */
	public function get_import_status() {
		$import_id = intval( $this->get_sanitized_arg( 'id', 'text_field' ) );

		if ( empty( $import_id ) ) {
			return $this->error_response( __( 'Import ID is required.', 'wp-marketing-automations' ), null, 400 );
		}

		$import = BWFAN_Model_Export_Import::get( $import_id );
		if ( ! $import ) {
			return $this->error_response( __( 'Invalid Import ID.', 'wp-marketing-automations' ), null, 404 );
		}


		$meta = $import['meta'] ?? '';
		$meta = ! empty( $meta ) ? json_decode( $meta, true ) : [];
		/** Treat an abandoned lock as free, otherwise a crashed batch leaves the import unrecoverable from the UI too */
		$is_running = ! empty( $meta['is_running'] ) && ! Importer::is_running_lock_abandoned( $import['last_modified'] ?? '' );

		if ( BWFAN_Importer::$IMPORT_IN_PROGRESS === intval( $import['status'] ) && ! $is_running ) {
			if ( bwf_has_action_scheduled( BWFAN_Importer::$IMPORTER_ACTION_HOOK, array( 'import_id' => $import_id ), 'bwfan' ) ) {
				bwf_unschedule_actions( BWFAN_Importer::$IMPORTER_ACTION_HOOK, array( 'import_id' => absint( $import_id ) ), 'bwfan' );
			}
			bwf_schedule_recurring_action( time() + 60, 60, BWFAN_Importer::$IMPORTER_ACTION_HOOK, array( 'import_id' => $import_id ), 'bwfan' );

			$importer_type = $meta['import_type'] ?? '';

			try{
				/** @var Importer $importer_obj */
				$importer_obj = BWFAN_Core()->importer->get_importer( $importer_type );
				$importer_obj->set_import_id( $import_id );

				$importer_obj->read_import();
				$importer_obj->import( 5 );

				/** If still in progress, return the status */
				$importer_obj->reset_import_data();

				/** Get percent completed */
				$percent = $importer_obj->get_percent_completed();
				$import  = $importer_obj->get_import_db_row();

				/** End import if completed */
				if ( $percent >= 100 ) {
					$importer_obj->end_import();
				}
			} catch ( Exception $e ) {
				return $this->error_response( __( 'An error occurred while processing the import.', 'wp-marketing-automations' ), $e->getMessage(), 404 );
			}
		}

		$status = $this->get_import_status_data( $import );

		return $this->success_response( $status, __( 'Import status retrieved successfully.', 'wp-marketing-automations' ) );
	}

	/**
	 * Get status data for Importer process.
	 *
	 * @return array
	 */
	private function get_import_status_data( $import ) {
		$import_meta = json_decode( $import['meta'], true ) ?? [];
		$import_log  = $import_meta['log'] ?? [];
		$percentage  = $this->calculate_percentage( $import );

		$response = [
			'status'     => BWFAN_Importer::get_status_text( $import['status'] ),
			'count'      => $import['count'],
			'skipped'    => $import_log['skipped'] ?? 0,
			'processed'  => ( $import_log['imported'] ?? 0 ) + ( $import_log['updated'] ?? 0 ),
			'failed'     => $import_log['failed'] ?? 0,
			'percentage' => $percentage
		];

		if ( intval( $import['status'] ) === BWFAN_Importer::$IMPORT_FAILED && ! empty( $import_meta['status_msg'] ) ) {
			$response['msg'] = [ $import_meta['status_msg'] ];
		}

		if ( $percentage === 100 && ! empty( $import_meta['log_file'] ) && file_exists( $import_meta['log_file'] ) ) {
			$response['log_file'] = $import_meta['log_file'];
		}

		return $response;
	}

	/**
	 * Calculates the percentage of completion for the import process.
	 *
	 * @return int|float The percentage of completion (between 0 and 100).
	 */
	private function calculate_percentage( $import ) {
		$count     = $import['count'];
		$processed = $import['processed'];

		if ( $count <= 0 || $processed <= 0 ) {
			return 0;
		}

		$percentage = min( max( 0, ( $processed / $count ) * 100 ), 100 );

		return intval( $percentage );
	}

	/**
	 * GET /contacts/import/{id}/cancel
	 */
	public function cancel_import() {
		$import_id = absint( $this->get_sanitized_arg( 'id', 'text_field' ) );

		$action = $this->get_sanitized_arg( 'action', 'text_field' );

		// Check if the action is to dismiss the current import
		if ( $action === 'dismiss' ) {
			BWFAN_Importer::update_import_option( '' );
			return $this->success_response( [], __( 'Successfully Dismissed Importer.', 'wp-marketing-automations' ) );
		}

		// Check if the import ID is provided
		if ( empty( $import_id ) ) {
			return $this->error_response( __( 'Import ID not found.', 'wp-marketing-automations' ), null, 400 );
		}

		$import = BWFAN_Model_Export_Import::get( $import_id );
		// Check if the import exists
		if ( ! $import ) {
			return $this->error_response( __( 'Invalid Import ID.', 'wp-marketing-automations' ), null, 400 );
		}

		// If import is scheduled, cancel it
		if ( bwf_has_action_scheduled( BWFAN_Importer::$IMPORTER_ACTION_HOOK, array( 'import_id' => $import_id ), 'bwfan' ) ) {
			bwf_unschedule_actions( BWFAN_Importer::$IMPORTER_ACTION_HOOK, array( 'import_id' => $import_id ), 'bwfan' );
		}
		BWFAN_Importer::update_import_option( '' );

		// Changed status to canceled
		$status = BWFAN_Model_Export_Import::update( [ 'status' => BWFAN_Importer::$IMPORT_CANCELLED ], [ 'id' => $import_id ] );

		return $this->success_response( [ 'status' => $status], __( 'Successfully Updated Import Status.', 'wp-marketing-automations' ) );
	}

	/**
	 * GET /contacts/importer/listing
	 */
	public function get_importer_listing() {
		$offset = $this->pagination->offset;
		$limit  = $this->pagination->limit;

		$imports = BWFAN_Model_Export_Import::get_lists( $offset, $limit, 1, [ 0 ] );

		if ( empty( $imports ) ) {
			return $this->success_response( [], __( 'No Imported Found.', 'wp-marketing-automations' ) );
		}

		$importer_data = array();
		$get_fields    = BWFCRM_Fields::get_groups_with_fields( true, true, null, true );
		foreach ( $imports as $import ) {
			$meta            = ! empty( $import['meta'] ) && BWFAN_Common::is_json( $import['meta'] ) ? json_decode( $import['meta'], true ) : [];
			$importer_data[] = array(
				'id'              => $import['id'] ?? '',
				'created_on'      => $import['created_date'] ?? '',
				'type'            => self::get_importer_type_label( $meta['import_type'] ?? '' ),
				'contact_count'   => $import['count'] ?? 0,
				'status'          => $import['status'] ?? 0,
				'title'           => $meta['title'] ?? '-',
				'fields_imported' => $this->get_fields( $meta, $get_fields ),
				'tags'            => $this->get_tags( $meta ),
				'lists'           => $this->get_lists( $meta ),
			);
		}

		$this->total_count = BWFAN_Model_Export_Import::total_count();

		return $this->success_response( $importer_data, __( 'All List Imported successfully', 'wp-marketing-automations' ) );
	}

	private function get_fields( $meta, $get_fields ) {
		if ( isset( $meta['mapped_fields'] ) && is_array( $meta['mapped_fields'] ) ) {
			$field_names = array_map( function ( $field_id ) use ( $get_fields ) {
				foreach ( $get_fields as $group ) {
					foreach ( $group['fields'] as $field ) {
						if ( $field['id'] == $field_id ) {
							return $field['name'];
						}
					}
				}

				return '';
			}, array_values( $meta['mapped_fields'] ) );

			return implode( ' | ', array_filter( $field_names ) );
		}

		return '';
	}

	private function get_tags( $meta ) {
		$all_tags = [];

		// Get standard tags
		if ( isset( $meta['tags'] ) && is_array( $meta['tags'] ) ) {
			$standard_tags = array_column( $meta['tags'], 'value' );
			$all_tags      = array_merge( $all_tags, $standard_tags );
		}

		// Get importer specific tags
		if ( isset( $meta['import_type'] ) && ! empty( $meta['import_type'] ) ) {
			$importer_prefix = $meta['import_type'] . '_tags';

			if ( isset( $meta[ $importer_prefix ] ) && is_array( $meta[ $importer_prefix ] ) ) {
				$importer_tags = array_map( function ( $tag ) {
					return $tag['title'] ?? '';
				}, array_values( $meta[ $importer_prefix ] ) );

				$all_tags = array_merge( $all_tags, array_filter( $importer_tags ) );
			}
		}

		return ! empty( $all_tags ) ? implode( ', ', array_unique( $all_tags ) ) : '';
	}

	private function get_lists( $meta ) {
		$all_lists = [];

		// Get standard lists
		if ( isset( $meta['lists'] ) && is_array( $meta['lists'] ) ) {
			$standard_lists = array_column( $meta['lists'], 'value' );
			$all_lists      = array_merge( $all_lists, $standard_lists );
		}

		// Get importer specific lists
		if ( isset( $meta['import_type'] ) && ! empty( $meta['import_type'] ) ) {
			$importer_prefix = $meta['import_type'] . '_lists';

			if ( isset( $meta[ $importer_prefix ] ) && is_array( $meta[ $importer_prefix ] ) ) {
				$importer_lists = array_map( function ( $list ) {
					return $list['title'] ?? '';
				}, array_values( $meta[ $importer_prefix ] ) );

				$all_lists = array_merge( $all_lists, array_filter( $importer_lists ) );
			}
		}

		return ! empty( $all_lists ) ? implode( ', ', array_unique( $all_lists ) ) : '';
	}

	/**
	 * Return Label for Importers
	 *
	 * @param $type
	 *
	 * @return mixed
	 */
	public static function get_importer_type_label( $type ) {
		$types = array(
			'ac'        => __( 'ActiveCampaign', 'wp-marketing-automations' ),
			'affwp'     => __( 'Affiliate WP', 'wp-marketing-automations' ),
			'csv'       => __( 'CSV', 'wp-marketing-automations' ),
			'mailchimp' => __( 'Mailchimp', 'wp-marketing-automations' ),
			'wc'        => __( 'WooCommerce', 'wp-marketing-automations' ),
			'wp'        => __( 'WordPress', 'wp-marketing-automations' ),
			'wlm'       => __( 'Wishlist Member', 'wp-marketing-automations' ),
			'wcs'       => __( 'WooCommerce Subscriptions', 'wp-marketing-automations' ),
		);

		return $types[ $type ] ?? $type;
	}

	/**
	 * GET /importer-log/download/{importer_id}
	 */
	public function download_importer_log_file() {
		$importer_id = absint( $this->get_sanitized_arg( 'importer_id', 'text_field' ) );
		if ( empty( $importer_id ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Invalid Importer ID', 'wp-marketing-automations' ) );
		}

		$import_row  = BWFAN_Model_Export_Import::get( $importer_id );
		$import_meta = ! empty( $import_row['meta'] ) ? json_decode( $import_row['meta'], true ) : array();

		if ( ! isset( $import_meta['log_file'] ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'Log file url is missing', 'wp-marketing-automations' ) );
		}

		// Extract filename from path and use basename to prevent path traversal
		$file_url = explode( '/', $import_meta['log_file'] );
		$file_name = basename( end( $file_url ) );

		// Sanitize filename to remove any dangerous characters
		$file_name = sanitize_file_name( $file_name );

		// Build full path using constant
		$allowed_dir = defined( 'BWFAN_IMPORT_DIR' ) ? BWFAN_IMPORT_DIR : wp_upload_dir()['basedir'] . '/funnelkit/fka-import';
		$filename    = $allowed_dir . '/' . $file_name;

		// Validate path using realpath to prevent directory traversal
		$real_path    = realpath( $filename );
		$real_allowed = realpath( $allowed_dir );

		if ( false === $real_path || false === $real_allowed || strpos( $real_path, $real_allowed ) !== 0 ) {
			$this->response_code = 403;

			return $this->error_response( __( 'Invalid file path', 'wp-marketing-automations' ) );
		}

		// Validate file exists and is readable
		if ( ! file_exists( $real_path ) || ! is_readable( $real_path ) ) {
			$this->response_code = 404;

			return $this->error_response( __( 'File not found or not readable', 'wp-marketing-automations' ) );
		}

		// Additional validation: ensure it's a CSV file
		$ext = strtolower( pathinfo( $real_path, PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			$this->response_code = 403;

			return $this->error_response( __( 'Invalid file type', 'wp-marketing-automations' ) );
		}

			// Set a time limit for script execution
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 60 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- import/export download may need longer than the default time limit; disabled via disable_functions on some hosts (RunCloud default)
			}

			// Define header information
			header( 'Content-Description: File Transfer' );
			header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( basename( $real_path ) ) . '"' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate' );
			header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $real_path ) );

			// Clear system output buffer
			flush();

		// Read and output the file
		readfile( $real_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams the file to the response; WP_Filesystem has no streaming read
			exit;
	}

	public function get_result_total_count() {
		return $this->total_count;
	}
}

BWFAN_API_Importer_Controller::get_instance();
