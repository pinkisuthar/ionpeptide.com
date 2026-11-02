<?php

namespace BWFAN\Importers;

use BWFAN_Common;
use BWFAN_Model_Export_Import;
use BWFAN_Model_Fields;
use BWFAN_Model_Terms;
use BWFCRM_Contact;
use BWFCRM_Fields;
use BWFAN_Importer;
use BWFCRM_Lists;
use BWFCRM_Tag;
use BWFCRM_Term_Type;
use Exception;
use Error;
use WP_Error;
use WP_User;

/**
 * Abstract class representing an importer.
 *
 * This class provides a base implementation for importers and defines the common properties and methods.
 *
 * @since 1.0.0
 */
#[\AllowDynamicProperties]
abstract class Importer {
	const LIMIT = 50;
	const RECENT_IMPORT_TIME_THRESHOLD = 5; // seconds

	/**
	 * Maximum age of the `is_running` lock before it is treated as abandoned.
	 *
	 * A healthy batch bumps `last_modified` on every iteration, so this window only has to
	 * be longer than a single iteration (one API page plus its contacts), never longer than
	 * a whole import.
	 */
	const RUNNING_LOCK_TIMEOUT = 300; // seconds
	public $slug = '';
	public $name = '';
	public $description = '';
	public $logo_url = '';

	public $submit_text = '';
	public $has_fields = false;
	public $priority = 10;
	public $group = 0;
	protected $import_type = '';
	protected $import_id = 0;
	protected $import = array();
	protected $import_meta = array();
	protected $raw_data = array();
	protected $tags = array();
	protected $lists = array();
	protected $import_log = array(
		'imported' => 0,
		'failed'   => 0,
		'updated'  => 0,
		'skipped'  => 0,
	);
	protected $params = array();
	protected $start_time = 0;
	protected $offset = 0;
	protected $count = 0;
	protected $processed = 0;
	protected $logger = null;
	protected $log_file_path;
	protected $log_headers = [];
	public $import_folder = BWFAN_IMPORT_DIR . '/';
	protected $file_position = null;

	public $api_credentials = null;
	protected $retry = 0;
	private $field_schema;
	
	/**
	 * Cached field types to avoid repeated database queries
	 *
	 * @var array|null
	 */
	private static $cached_field_types = null;

	public function __construct( $params = array() ) {
		// Initialize default arguments
		$default_args = array(
			'import_id'               => 0,
			'update_existing'         => false,
			'offset'                  => 0,
			'limit'                   => self::LIMIT,
			'tags'                    => array(),
			'lists'                   => array(),
			'marketing_status'        => false,
			'disable_events'          => false,
			'prevent_timeouts'        => true,
			'imported_contact_status' => 1,
			'create_import'           => false,
		);

		$params = wp_parse_args( $params, $default_args );

		$this->set_params( $params );

		if ( is_numeric( $params['import_id'] ) && $params['import_id'] > 0 ) {
			$this->set_import_id( $params['import_id'] );
		} elseif ( $params['create_import'] ) {
			$this->create_import();
		}

		if ( $this->get_import_id() > 0 ) {
			$this->read_import();
		}

		// Initialize the logger
		$this->initialize_logger();
	}

	/**
	 * Retrieves the field schema for the importer.
	 *
	 * This method returns an empty array by default, but can be overridden in subclasses to provide
	 * specific field schema information.
	 *
	 * @return array The field schema for the importer.
	 */
	public function get_field_schema() {
		return [];
	}

	/**
	 * Retrieves the log headers for the importer.
	 *
	 * This method returns an empty array by default, but can be overridden in subclasses to provide
	 * specific log headers.
	 *
	 * @return array The log headers for the importer.
	 */
	public function get_default_values() {
		return [];
	}

	/**
	 * Retrieves the import type.
	 *
	 * @return string The import type.
	 */
	public function get_import_type() {
		return $this->import_type;
	}

	/**
	 * Retrieves the import ID.
	 *
	 * @return int The import ID.
	 */
	public function get_import_id() {
		return $this->import_id;
	}

	/**
	 * Retrieves the import status.
	 *
	 * @return int
	 */
	public function get_import_status() {
		return isset( $this->import['status'] ) ? absint( $this->import['status'] ) : 0;
	}

	/**
	 * Reset import data
	 *
	 * @return void
	 */
	public function reset_import_data() {
		$this->import = null;
		$this->read_import();
	}

	/**
	 * Get import
	 *
	 * @return array|mixed
	 */
	public function get_import_db_row() {
		return $this->import;
	}

	/**
	 * Retrieves the import meta data.
	 *
	 * @param string $key Optional. The metadata key to retrieve. Default empty.
	 *
	 * @return mixed The import metadata if $key is provided, otherwise the entire import meta array.
	 */
	public function get_import_meta( $key = '' ) {
		if ( empty( $key ) ) {
			return $this->import_meta;
		}

		return $this->import_meta[ $key ] ?? '';
	}

	/**
	 * Retrieves the tags associated with the importer.
	 *
	 * @return array The tags associated with the importer.
	 */
	public function get_new_tags() {
		return $this->tags;
	}

	/**
	 * Retrieves the lists associated with the importer.
	 *
	 * @return array The lists associated with the importer.
	 */
	public function get_new_lists() {
		return $this->lists;
	}

	/**
	 * Retrieves the import log.
	 *
	 * @return array The import log.
	 */
	public function get_import_log() {
		return $this->import_log;
	}

	/**
	 * Retrieves the offset value.
	 *
	 * @return int The offset value.
	 */
	public function get_offset() {
		return $this->offset;
	}

	/**
	 * Returns the count of items in the importer.
	 *
	 * @return int The count of items.
	 */
	public function get_count() {
		return $this->count;
	}

	/**
	 * Returns the number of items processed by the importer.
	 *
	 * @return int The number of items processed.
	 */
	public function get_processed() {
		return $this->processed;
	}

	/**
	 * Calculates the percentage of completion for the import process.
	 *
	 * @return int|float The percentage of completion (between 0 and 100).
	 */
	public function get_percent_completed() {
		$count     = $this->get_count();
		$processed = $this->get_processed();

		if ( $count <= 0 || $processed <= 0 ) {
			return 0;
		}

		$percentage = min( max( 0, ( $processed / $count ) * 100 ), 100 );

		return intval( $percentage );
	}

	/**
	 * Retrieves the status of the importer.
	 *
	 * This method returns an array containing information about the import status, including the import ID, percentage completed, status text, log, and whether a log file exists.
	 *
	 * @return array The import status.
	 */
	public function get_status() {
		$percent = $this->get_percent_completed();
		$log     = $this->get_import_log();

		$import_status = array(
			'import_id'    => $this->get_import_id(),
			'percent'      => $percent,
			'status'       => BWFAN_Importer::get_status_text( $this->get_import_status() ),
			'log'          => array(
				'succeed' => $log['imported'] + $log['updated'],
				'failed'  => $log['failed'],
				'skipped' => $log['skipped'],
			),
			'has_log_file' => false,
		);

		if ( $percent === 100 && ! empty( $this->import_meta['log_file'] ) ) {
			$import_status['has_log_file'] = true;
			$import_status['log_file']     = $this->import_meta['log_file'];
		}

		return $import_status;
	}

	/**
	 * Returns API data
	 *
	 * @return array
	 */
	public function get_api_data() {
		return [
			'slug'         => $this->slug,
			'name'         => $this->name,
			'description'  => $this->description,
			'logo_url'     => $this->logo_url,
			'has_fields'   => $this->has_fields,
			'field_schema' => $this->field_schema,
		];
	}

	/**
	 * Set the import ID for the importer.
	 *
	 * @param int $import_id The ID of the import.
	 *
	 * @return void
	 */
	public function set_import_id( $import_id ) {
		$this->import_id = $import_id;
	}

	/**
	 * Sets the parameters for the importer.
	 *
	 * @param array $params The parameters to be set.
	 *
	 * @return void
	 */
	public function set_params( $params ) {
		$this->params = $params;
	}

	/**
	 * Checks if the import is recently imported based on the last modified time.
	 *
	 * @return bool True if the import is recently imported, false otherwise.
	 */
	public function is_recently_imported() {
		$last_modified_time = strtotime( $this->import['last_modified'] );
		$current_time       = time();

		$time_difference = $current_time - $last_modified_time;

		return $time_difference <= self::RECENT_IMPORT_TIME_THRESHOLD;
	}

	/**
	 * Checks whether the `is_running` lock has been abandoned by a dead process.
	 *
	 * The lock is set before a batch starts and is only cleared on a clean exit: the end of
	 * a batch, end_import() or update_retry(). If the process dies mid-batch - fatal error,
	 * PHP timeout, memory limit or a server restart - nothing clears it and the import can
	 * never be picked up again. Every batch iteration writes `last_modified`, so a lock
	 * sitting on top of a `last_modified` that has stopped advancing means no process is
	 * working on this import any more and the lock can be ignored.
	 *
	 * @param string $last_modified GMT datetime string of the import's last write.
	 *
	 * @return bool True when the lock is stale and should not block a new batch.
	 */
	public static function is_running_lock_abandoned( $last_modified ) {
		if ( empty( $last_modified ) ) {
			return true;
		}

		$last_modified_time = strtotime( $last_modified );
		if ( false === $last_modified_time ) {
			return true;
		}

		return ( time() - $last_modified_time ) > self::RUNNING_LOCK_TIMEOUT;
	}

	/**
	 * Initializes the logger for the importer.
	 *
	 * This method creates a logger object and sets the file path and headers for the log file.
	 * If the import directory does not exist, it creates the directory.
	 *
	 * @return void
	 */
	public function initialize_logger() {
		if ( $this->get_import_id() === 0 || empty( $this->log_file_path ) ) {
			return;
		}

		/** If a log file does not exist */
		if ( ! file_exists( $this->log_file_path ) ) {
			return;
		}

		$this->log_headers = $this->get_log_headers();
		BWFAN_Importer::create_importer_log_file( $this->log_file_path, $this->log_headers );

		$this->logger = new Logger( $this->log_file_path );
	}

	/**
	 * Reads the import data and initializes the necessary properties.
	 */
	public function read_import() {
		$this->import = empty( $this->import ) ? BWFAN_Model_Export_Import::get( $this->get_import_id() ) : $this->import;

		if ( ! $this->import ) {
			$this->set_import_id( 0 );
		}

		$this->import_meta = empty( $this->import['meta'] ) ? array() : json_decode( $this->import['meta'], true );

		$tags = $this->get_import_meta( 'tags' );
		if ( ! empty( $tags ) ) {
			$this->tags = BWFAN_Model_Terms::get_crm_term_ids( $tags, BWFCRM_Term_Type::$TAG );
		}

		$lists = $this->get_import_meta( 'lists' );
		if ( ! empty( $lists ) ) {
			$this->lists = BWFAN_Model_Terms::get_crm_term_ids( $lists, BWFCRM_Term_Type::$LIST );
		}

		if ( ! empty( $this->import_meta['log'] ) ) {
			$this->import_log = $this->import_meta['log'];
		}

		if ( isset( $this->import_meta['retry'] ) ) {
			$this->retry = intval( $this->import_meta['retry'] );
		}

		$this->log_file_path = empty( $this->log_file_path ) && isset( $this->import_meta['log_file'] ) ? $this->import_meta['log_file'] : $this->log_file_path;
		$this->offset        = ! empty( $this->import['offset'] ) ? absint( $this->import['offset'] ) : 0;
		$this->count         = ! empty( $this->import['count'] ) ? absint( $this->import['count'] ) : 0;
		$this->processed     = ! empty( $this->import['processed'] ) ? absint( $this->import['processed'] ) : 0;
	}

	/**
	 * Creates a new import record.
	 *
	 * This method prepares the import data and inserts a new import record into the database.
	 * It also sets the import ID and schedules a background action for the import.
	 *
	 * @return void
	 */
	public function create_import( $fields = array() ) {

		if ( ! file_exists( $this->import_folder ) ) {
			wp_mkdir_p( $this->import_folder );
		}
		$this->log_file_path = $this->import_folder . $this->get_import_type() . '-import-log-' . time() . '-' . wp_generate_password( 5, false ) . '.csv';

		$import_data = array(
			'offset'        => $this->params['offset'],
			'processed'     => 0,
			'count'         => 0,
			'type'          => BWFAN_Importer::$IMPORT,
			'status'        => BWFAN_Importer::$IMPORT_DRAFT,
			'meta'          => array(
				'title'                   => $this->name . ' ' . __( 'Import', 'wp-marketing-automations' ) . ' (' . current_time( 'mysql', 1 ) . ')',
				'import_type'             => $this->get_import_type(),
				'tags'                    => $this->params['tags'],
				'lists'                   => $this->params['lists'],
				'marketing_status'        => $this->params['marketing_status'],
				'disable_events'          => $this->params['disable_events'],
				'imported_contact_status' => $this->params['imported_contact_status'],
				'update_existing'         => $this->params['update_existing'],
				'prevent_timeouts'        => $this->params['prevent_timeouts'],
				'log_file'                => $this->log_file_path,
				'fields'                  => $fields,
			),
			'created_date'  => current_time( 'mysql', 1 ),
			'last_modified' => gmdate( 'Y-m-d H:i:s', time() - 6 ),
		);

		$import_data = $this->prepare_create_import_data( $import_data, $fields );

		$import_data['meta'] = wp_json_encode( $import_data['meta'] );

		// Insert the new import record into the database
		BWFAN_Model_Export_Import::insert( $import_data );

		// Return the ID of the newly created import record
		$this->set_import_id( BWFAN_Model_Export_Import::insert_id() );
	}

	/**
	 * Updates the import meta-data with the given meta-array.
	 *
	 * @param array $meta The metadata to be updated.
	 *
	 * @return void
	 */
	public function update_import_meta( $meta = array() ) {

		$import_meta = $this->get_import_meta();
		$import_meta = array_merge( $import_meta, $meta );

		BWFAN_Model_Export_Import::update( array( 'meta' => wp_json_encode( $import_meta ) ), array( 'id' => $this->get_import_id() ) );

		$this->import_meta = $import_meta;
	}

	/**
	 * Prepares the import data for creating a new import.
	 *
	 * @param array $import_data The import data to be prepared.
	 *
	 * @return array The prepared import data.
	 */
	public function prepare_create_import_data( $import_data = array(), $fields = array() ) {
		return $import_data;
	}

	/**
	 * Starts the import process.
	 *
	 * This method is responsible for initiating the import process by rescheduling a background action
	 * using the import ID and action hook provided by the importer.
	 *
	 * @return bool Returns true if the import process is successfully started, false otherwise.
	 */
	public function start_import() {
		$this->before_start_import();
		BWFAN_Model_Export_Import::update( [
			'status' => BWFAN_Importer::$IMPORT_IN_PROGRESS,
		], array( 'id' => absint( $this->get_import_id() ) ) );
		BWFAN_Core()->importer->reschedule_background_action( $this->get_import_id() );

		return true;
	}

	/**
	 * Add this method in importer(child) class if you need to perform something before start import
	 *
	 * @return void
	 */
	public function before_start_import() {
	}

	/**
	 * Updates the import record with the current import data.
	 */
	public function update_import_record() {
		$this->import_meta['log'] = $this->get_import_log();
		if ( isset( $this->import_meta['retry'] ) ) {
			unset( $this->import_meta['retry'] );
			unset( $this->import_meta['retry_data'] );
		}

		$import_data = array(
			'offset'        => 'csv' === $this->get_import_type() ? $this->get_file_position() : $this->get_offset(),
			'processed'     => $this->get_processed(),
			'meta'          => wp_json_encode( $this->get_import_meta() ),
			'last_modified' => current_time( 'mysql', 1 ),
			'status'        => $this->get_percent_completed() >= 100 ? BWFAN_Importer::$IMPORT_SUCCESS : BWFAN_Importer::$IMPORT_IN_PROGRESS,
			'count'         => $this->get_count(),
		);

		BWFAN_Model_Export_Import::update( $import_data, array( 'id' => absint( $this->get_import_id() ) ) );

		$this->import = array_merge( $this->import, $import_data );
	}

	/**
	 * Adds a log entry with the specified message and data.
	 *
	 * @param string $message The log message.
	 * @param array $data Additional data to be logged (optional).
	 *
	 * @return void
	 */

	public function add_log( $data, $is_error = false ) {
		$log_data = $this->prepare_log_data( $data['data'], $data['result'] );

		if ( $is_error ) {
			$log_data['Message'] = $data['message'];
		}
		if ( empty( $this->log_file_path ) ) {
			return;
		}

		BWFAN_Importer::append_data_to_log_file( $this->log_file_path, $log_data );
	}

	/**
	 * This method is responsible for importing data.
	 * It iterates over the raw data and processes each item.
	 * If an item is successfully processed, it is logged as imported or updated.
	 * If an error occurs during processing, it is logged as failed.
	 * The method also checks for timeouts and memory limits to prevent issues during import.
	 * If the import is completed, the method ends the import process.
	 *
	 * @param int $time_threshold The time threshold in seconds to prevent timeouts during import.
	 *
	 * @return void
	 *
	 */
	public function import( $time_threshold = 20 ) {
		if ( $this->is_recently_imported() ) {
			return;
		}

		/** Another process holds the lock. Only back off if that process is still alive. */
		if ( ! empty( $this->import_meta['is_running'] ) && ! self::is_running_lock_abandoned( $this->import['last_modified'] ) ) {
			return;
		}

		$this->start_time = time();

		// Verify and update total count to handle any added/deleted items during import
		$this->update_total_count();
		if ( method_exists( $this, 'prepare_tag_list' ) ) {
			$this->prepare_tag_list();
		}

		if ( $this->count == 0 ) {
			$this->end_import( BWFAN_Importer::$IMPORT_FAILED, __( 'No contact found to import.', 'wp-marketing-automations' ) );
			return;
		}

		$this->update_status_to_running();

		do {
			try {
				$contacts = $this->populate_contact_data();
				if ( $contacts instanceof WP_Error ) {
					if ( $this->retry >= 3 ) {
						$this->end_import( BWFAN_Importer::$IMPORT_FAILED, $contacts->get_error_message() );

						return;
					}
					$this->retry ++;
					$this->update_retry( [ $contacts->get_error_code() => $contacts->get_error_message() ] );

					return;
				}

				$contacts = ! empty( $this->raw_data ) ? $this->raw_data : $contacts;
				
				if ( empty( $contacts ) ) {
					$this->update_import_record();
					$this->end_import( BWFAN_Importer::$IMPORT_SUCCESS, __( 'No contact found to import.', 'wp-marketing-automations' ) );

					return;
				}

				foreach ( $contacts as $data ) {
					$result = [];
					try {

						$result   = $this->process_item( $data );
						$log_data = [
							'data'   => is_numeric( $data ) ? $data : 0,
							'result' => $result
						];
						$is_error = false;
						if ( is_wp_error( $result ) ) {
							$log_data['data']    = $result->error_data['invalid_data']['id'] ?? $log_data['data'];
							$log_data['message'] = $result->get_error_message();
							$is_error            = true;
							$this->import_log['failed'] ++;
						} elseif ( isset( $result['skipped'] ) && $result['skipped'] ) {
							$this->import_log['skipped'] ++;
						} elseif ( isset( $result['updated'] ) && $result['updated'] ) {
							$this->import_log['updated'] ++;
						} else {
							$this->import_log['imported'] ++;
						}
						$this->add_log( $log_data, $is_error );

					} catch ( Error|Exception $e ) {
						$this->handle_contact_loop_error();// Handle any errors that occur during the contact loop
						$log_data = [
							'result'  => $result,
							'data'    => $data,
							'message' => $e->getMessage()
						];
					
						$this->add_log( $log_data, true );
					}

					$this->processed ++;
					
					// Update offset - CSV uses file position, others use incremental offset
					if ( 'csv' === $this->get_import_type() && null !== $this->file_position ) {
						$this->offset = $this->file_position;
					} else {
						$this->offset ++;
					}

				}
				// Update the import record with the current state needed for child classes
				$this->modify_import_records();
				$this->update_import_record();
			} catch ( Error|Exception $e ) {
				$this->handle_contact_loop_error();// Handle any errors that occur during the contact loop
				if ( $this->retry >= 3 ) {
					/* translators: 1: Importer fail message */
					$this->end_import( BWFAN_Importer::$IMPORT_FAILED, sprintf( __( 'Import failed: %1$s', 'wp-marketing-automations' ), $e->getMessage() ) );

					return;
				}
				$this->retry ++;
				/* translators: 1: Importer fail message */
				$this->update_retry( [ $e->getCode() => sprintf( __( 'Import failed: %1$s', 'wp-marketing-automations' ), $e->getMessage() ) ] );

				return;
			}

		} while ( $this->get_percent_completed() < 100 && ( ! $this->params['prevent_timeouts'] || ( time() - $this->start_time ) < $time_threshold && ! BWFAN_Common::memory_exceeded() ) );

		if ( $this->get_percent_completed() >= 100 ) {
			$this->end_import();

			return;
		}

		$this->update_status_to_running( true );
	}

	/**
	 * Updates the total count of contacts during an import process
	 *
	 * @return void
	 */
	protected function update_total_count() {
		// Only update count if it's significantly different (more than 10% change)
		// This prevents expensive count queries on every batch for minor changes
		$current_count = $this->get_contacts_count();
		
		// Calculate percentage difference
		$percent_diff = 0;
		if ( $this->count > 0 ) {
			$percent_diff = abs( ( $current_count - $this->count ) / $this->count * 100 );
		}

		// Update if count changed significantly (more than 10%) or if count is zero
		if ( $current_count !== $this->count && ( $percent_diff > 10 || $this->count === 0 ) ) {
			$this->count = $current_count;

			// Update the count in the database
			BWFAN_Model_Export_Import::update( array( 'count' => $current_count ), array( 'id' => $this->get_import_id() ) );
		}
	}

	/**
	 * Process an item during the import process.
	 *
	 * @param mixed $data The data to be processed.
	 *
	 * @return array|WP_Error An array containing the contact ID and update status, or a WP_Error object if there is an error.
	 */
	public function process_item( $data = null ) {
		$contact_data = $this->prepare_contact_data( $data );

		if ( is_wp_error( $contact_data ) ) {
			return $contact_data;
		}

		// Get update settings
		$update_existing        = $this->get_import_meta( 'update_existing' );
		$update_existing_fields = $this->get_import_meta( 'update_existing_fields' );
		$disable_events         = $this->get_import_meta( 'disable_events' );
		
		$contact        = new BWFCRM_Contact( $contact_data['email'], true );
		
		/** Check if contact exists first */
		$contact_exists = $contact->already_exists;

		/** Skip existing contacts entirely if skip_existing is enabled */
		$skip_existing = $this->get_import_meta( 'skip_existing' );
		if ( ! empty( $contact_exists ) && ! empty( $skip_existing ) ) {
			return array(
				'id'      => $contact->get_id(),
				'skipped' => true,
			);
		}

		/** Add a disable events flag if needed */
		if ( true === $disable_events ) {
			$contact_data['data']['disable_events'] = true;
		}

		/** Handle unsubscribe status */
		$do_unsubscribe = false;
		if ( 3 === intval( $contact_data['data']['status'] ) ) {
			$contact_data['data']['status'] = 1;
			$do_unsubscribe                 = true;
		}

		try {

			/** If contact is not exists then update field */
			if ( empty( $contact_exists ) || ! empty( $update_existing_fields ) ) {
				/** Remove creation date for existing contacts */
				if ( isset( $contact_data['data']['creation_date'] ) && ! empty( $contact->contact->get_creation_date() ) ) {
					unset( $contact_data['data']['creation_date'] );
				}

				/** Handle don't update blank fields setting */
				$dont_update_blank = $this->get_import_meta( 'dont_update_blank' );
				if ( true === $dont_update_blank ) {
					$contact_cols = array( 'email', 'f_name', 'l_name', 'state', 'country', 'contact_no', 'timezone', 'creation_date', 'gender', 'company', 'dob' );
					foreach ( $contact_cols as $col ) {
						if ( ! isset( $contact_data['data'][ $col ] ) ) {
							continue;
						}
						/** Only trim if value is a string to avoid warnings */
						$value = is_string( $contact_data['data'][ $col ] ) ? trim( $contact_data['data'][ $col ] ) : $contact_data['data'][ $col ];
						if ( ! empty( $value ) ) {
							continue;
						}
						unset( $contact_data['data'][ $col ] );
					}
				}
			}

			/** If contact is not exists or update existing status is enabled then handle unsubscribe status */
			if ( empty( $contact_exists ) || $update_existing ) {
				/** Handle unsubscribe status */
				if ( $do_unsubscribe ) {
					$contact->set_unsubscribe_context( 0, 0, BWFCRM_Contact::UNSUBSCRIBE_SOURCE_IMPORTER );
					$contact->unsubscribe( $disable_events );
				} else {
					$contact->remove_unsubscribe_status();
				}
			} else {
				unset( $contact_data['data']['status'] );
			}

			/** Set contact data in the contact object */
			$result         = $contact->set_data( $contact_data['data'] );
			$fields_updated = $result['fields_changed'];

			/** Fetch tags and lists */
			if ( isset( $contact_data['data']['tags'] ) && ! is_array( $contact_data['data']['tags'] ) ) {
				$tags        = explode( ',', $contact_data['data']['tags'] );
				$column_tags = array_filter( array_map( function ( $tag ) {
					return ! empty( $tag ) ? array(
						'id'   => 0,
						'name' => trim( $tag ),
					) : [];
				}, $tags ) );
				$tags = array_unique( BWFAN_Model_Terms::get_crm_term_ids( $column_tags, BWFCRM_Term_Type::$TAG ) );
			} else {
				$tags = $contact_data['data']['tags'] ?? array();
			}
			if ( isset( $contact_data['data']['lists'] ) && ! is_array( $contact_data['data']['lists'] ) ) {
				$lists        = explode( ',', $contact_data['data']['lists'] );
				$column_lists = array_filter( array_map( function ( $list ) {
					return ! empty( $list ) ? array(
						'id'   => 0,
						'name' => trim( $list ),
					) : [];
				}, $lists ) );

				$lists = array_unique( BWFAN_Model_Terms::get_crm_term_ids( $column_lists, BWFCRM_Term_Type::$LIST ) );
			} else {
				$lists = $contact_data['data']['lists'] ?? array();
			}

			/** Set tags and lists */
			$to_be_assigned_tags  = array_merge( $tags, $this->get_new_tags() );
			$to_be_assigned_lists = array_merge( $lists, $this->get_new_lists() );
			$contact              = $this->set_contact_tags( $contact, $to_be_assigned_tags );
			$contact              = $this->set_contact_lists( $contact, $to_be_assigned_lists );

			/** If contact exists and update existing status and field disabled, lists and tag is empty then mark skips contact */
			if ( ! empty( $contact_exists ) && empty( $update_existing ) && empty( $update_existing_fields ) && empty( $to_be_assigned_tags ) && empty( $to_be_assigned_lists ) ) {
				return [
					'id'      => $contact->get_id(),
					'skipped' => true,
				];
			}

			/** Save contact fields if fields were updated and contact is new or update existing fields are enabled */
			if ( $fields_updated && ( empty( $contact_exists ) || ! empty( $update_existing_fields ) ) ) {
				$contact->save_fields();
			}
			$contact->save();

			return [
				'id'      => $contact->get_id(),
				'updated' => true,
			];

		} catch ( Error|Exception $e ) {
			return new WP_Error( 'bwfcrm_error_processing_contact', $e->getMessage(), $contact_data );
		}
	}

	/**
	 * Ends the import process and updates the import status and status message.
	 *
	 * @param int $status The import status. Default is 3 (success).
	 * @param string $status_message The status message. Default is an empty string.
	 *
	 * @return void
	 */
	public function end_import( $status = 3, $status_message = '' ) {
		/**
		 * Check if import action is scheduled and the status is in progress
		 */
		if ( bwf_has_action_scheduled( BWFAN_Importer::$IMPORTER_ACTION_HOOK, array( 'import_id' => $this->get_import_id() ), 'bwfan' ) ) {
			bwf_unschedule_actions( BWFAN_Importer::$IMPORTER_ACTION_HOOK, array( 'import_id' => $this->get_import_id() ), 'bwfan' );
		}

		/**
		 * Adding log message
		 */
		if ( ! empty( $status_message ) ) {
			BWFAN_Core()->logger->log( $status_message, 'import_contacts_crm' );
		} elseif ( BWFAN_Importer::$IMPORT_SUCCESS === $status ) {
			BWFAN_Importer::update_import_option();
			/* translators: 1: Importer id */
			$status_message = sprintf( __( 'Contacts imported. Import ID: %1$d', 'wp-marketing-automations' ), $this->get_import_id() );
		} elseif ( BWFAN_Importer::$IMPORT_FAILED === $status ) {
			/* translators: 1: Importer id */
			$status_message = sprintf( __( 'Import failed. Import ID: %1$d', 'wp-marketing-automations' ), $this->get_import_id() );
		}

		$this->import['status']          = $status;
		$this->import_meta['status_msg'] = $status_message;
		$this->import_meta['log_file']   = $this->log_file_path;
		if ( BWFAN_Importer::$IMPORT_FAILED !== $status && isset( $this->import_meta['retry'] ) ) {
			unset( $this->import_meta['retry'] );
			unset( $this->import_meta['retry_data'] );
		}

		if ( isset( $this->import_meta['is_running'] ) ) {
			unset( $this->import_meta['is_running'] );
		}

		/**
		 * Updating importer data in DB
		 */
		BWFAN_Model_Export_Import::update( array(
			'status' => $status,
			'meta'   => wp_json_encode( $this->import_meta ),
		), array( 'id' => $this->get_import_id() ) );
	}

	/**
	 * Prepare contact data from user.
	 *
	 * This function prepares contact data from a user object.
	 *
	 * @param WP_User $user The user object.
	 *
	 * @return array|WP_Error The contact data array or WP_Error object if the user object is invalid.
	 */
	public function prepare_contact_data_from_user( $user ) {
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'bwfcrm_invalid_user_object', __( 'Invalid user object.', 'wp-marketing-automations' ), $user );
		}

		/**
		 * Get contact fields
		 */
		$contact_fields = BWFCRM_Fields::get_contact_fields_from_db( 'slug' );

		/**
		 * Set marketing status to set
		 */
		$import_status = isset( $this->import_meta['marketing_status'] ) ? absint( $this->import_meta['marketing_status'] ) : 0;
		if ( isset( $this->import_meta['imported_contact_status'] ) ) {
			$import_status = $this->get_import_meta( 'imported_contact_status' );
		}

		$email = $user->user_email;

		// Get name from user meta
		$first_name = get_user_meta( $user->ID, 'first_name', true );
		$last_name  = get_user_meta( $user->ID, 'last_name', true );

		// Get name from billing info
		$first_name = empty( $first_name ) ? get_user_meta( $user->ID, 'billing_first_name', true ) : $first_name;
		$last_name  = empty( $last_name ) ? get_user_meta( $user->ID, 'billing_last_name', true ) : $last_name;


		// Get name from display name
		if ( empty( $first_name ) && empty( $last_name ) ) {
			$name_parts = explode( ' ', $user->display_name, 2 );
			$first_name = $name_parts[0];
			$last_name  = $name_parts[1] ?? '';
		}

		$contact_data = array(
			'f_name' => $first_name,
			'l_name' => $last_name,
			'status' => $import_status,
			'wp_id'  => $user->ID,
		);

		// WooCommerce User Meta
		$phone    = get_user_meta( $user->ID, 'billing_phone', true );
		$city     = get_user_meta( $user->ID, 'billing_city', true );
		$state    = get_user_meta( $user->ID, 'billing_state', true );
		$country  = get_user_meta( $user->ID, 'billing_country', true );
		$postcode = get_user_meta( $user->ID, 'billing_postcode', true );
		$address1 = get_user_meta( $user->ID, 'billing_address_1', true );
		$address2 = get_user_meta( $user->ID, 'billing_address_2', true );
		$company  = get_user_meta( $user->ID, 'billing_company', true );

		$email = empty( $email ) ? get_user_meta( $user->ID, 'billing_email', true ) : $email;

		! empty( $postcode ) ? $contact_data[ $contact_fields['postcode']['ID'] ] = $postcode : null;
		! empty( $address1 ) ? $contact_data[ $contact_fields['address-1']['ID'] ] = $address1 : null;
		! empty( $address2 ) ? $contact_data[ $contact_fields['address-2']['ID'] ] = $address2 : null;
		! empty( $company ) ? $contact_data[ $contact_fields['company']['ID'] ] = $company : null;
		! empty( $city ) ? $contact_data[ $contact_fields['city']['ID'] ] = $city : null;

		! empty( $phone ) ? $contact_data['contact_no'] = $phone : null;
		! empty( $state ) ? $contact_data['state'] = $state : null;
		! empty( $country ) ? $contact_data['country'] = BWFAN_Common::get_country_iso_code( $country ) : null;

		$contact_data['source'] = $this->get_import_type();

		return array(
			'email' => $email,
			'data'  => $contact_data,
		);
	}

	/**
	 * Sets tags for a contact.
	 *
	 * @param object $contact The contact object.
	 * @param array $tags An array of tags to be set for the contact.
	 *
	 * @return BWFCRM_Contact|mixed
	 */
	public function set_contact_tags( $contact, $tags = array() ) {
		if ( $contact instanceof \WooFunnels_Contact ) {
			$contact = new BWFCRM_Contact( $contact );
		}

		if ( empty( $tags ) ) {
			return $contact;
		}
		$contact->set_tags_v2( $tags, ! $this->get_import_meta( 'disable_events' ) );

		return $contact;
	}

	/**
	 * Sets the contact lists for a given contact.
	 *
	 * @param object $contact The contact object.
	 * @param array $lists An array of contact lists.
	 *
	 * @return BWFCRM_Contact|mixed
	 */
	public function set_contact_lists( $contact, $lists = array() ) {
		if ( $contact instanceof \WooFunnels_Contact ) {
			$contact = new BWFCRM_Contact( $contact );
		}

		if ( empty( $lists ) ) {
			return $contact;
		}

		$contact->set_lists_v2( $lists, ! $this->get_import_meta( 'disable_events' ) );

		return $contact;
	}

	/**
	 * Retrieves CRM fields for the importer.
	 *
	 * This function checks if the BWFCRM_Fields class exists and retrieves groups with fields.
	 * It also adds a mapping option for tags and lists.
	 *
	 * @return array The CRM fields mapping options.
	 */
	public function get_crm_fields( $show_address_fields = true, $add_status_field = false ) {
		// Check if BWFCRM_Fields class exists
		if ( ! class_exists( 'BWFCRM_Fields' ) ) {
			return array();
		}

		// Retrieve groups with fields
		$mapping_options = BWFCRM_Fields::get_groups_with_fields( $show_address_fields, true, true, true );

		$finalData = [];

		foreach ( $mapping_options as $group ) {
			if ( empty( $group ) || ! is_array( $group ) || empty( $group['fields'] ) ) {
				continue;
			}

			$fields_data = [];

			foreach ( $group['fields'] as $field_id => $field ) {
				if ( 'status' === $field_id ) {
					continue;
				}

				$hint = '';

				/** Get options if field types are select(4), radio(5) and checkbox(6) */
				$field_types = [ 4, 5, 6 ];
				if ( isset( $field['type'] ) && in_array( intval( $field['type'] ), $field_types, true ) ) {
					$options = isset( $field['meta']['options'] ) && is_array( $field['meta']['options'] ) ? implode( ', ', $field['meta']['options'] ) : '';
					if ( intval( $field['type'] ) === 6 ) {
						/* translators: %s: Comma-separated list of available field options */
						$hint = sprintf( __( "Available options: %s. Use comma seperated values for multiple options.", 'wp-marketing-automations' ), $options );
					} else {
						/* translators: %s: Comma-separated list of available field options */
						$hint = sprintf( __( "Available options: %s", 'wp-marketing-automations' ), $options );
					}
				}

				/** Set hint for country field */
				if ( 'country' === $field_id ) {
					$hint = __( 'Country code in two digit ISO Code', 'wp-marketing-automations' );
				}

				/** Set hint for a date field type */
				if ( isset( $field['type'] ) && 7 === intval( $field['type'] ) ) {
					$hint = __( 'Date in Y-m-d format', 'wp-marketing-automations' );
				}

				$type_datetime = property_exists( 'BWFCRM_Fields', 'TYPE_DATETIME' ) && BWFCRM_Fields::$TYPE_DATETIME ? BWFCRM_Fields::$TYPE_DATETIME : 8;
				$type_time     = property_exists( 'BWFCRM_Fields', 'TYPE_TIME' ) && BWFCRM_Fields::$TYPE_TIME ? BWFCRM_Fields::$TYPE_TIME : 9;
				/** Set hint for a datetime field type */
				if ( isset( $field['type'] ) && $type_datetime === intval( $field['type'] ) ) {
					$hint = __( 'Date and time in Y-m-d H:i format', 'wp-marketing-automations' );
				}

				/** Set hint for a time field type */
				if ( isset( $field['type'] ) && $type_time === intval( $field['type'] ) ) {
					$hint = __( 'Time in H:i format', 'wp-marketing-automations' );
				}

				/** Set hint for a number field type */
				if ( isset( $field['type'] ) && 2 === intval( $field['type'] ) ) {
					$hint = __( 'Only numeric values are allowed.', 'wp-marketing-automations' );
				}

				if ( ! empty( $hint ) ) {
					$field['hint'] = $hint;
				}

				$fields_data[] = $field;
			}

			if ( ! empty( $fields_data ) ) {
				$finalData[] = array(
					'id'     => $group['id'],
					'name'   => $group['name'],
					'fields' => $fields_data
				);
			}
		}

		// Add the mapping option for tags and lists
		$finalData[] = array(
			'id'     => 0,
			'name'   => __( 'Map Tags and Lists', 'wp-marketing-automations' ),
			'fields' => array(
				array(
					'id'   => 'tags',
					'name' => 'Tags',
				),
				array(
					'id'   => 'lists',
					'name' => 'Lists',
				),
			),
		);

		return $finalData;
	}

	/**
	 * Get CRM lists.
	 *
	 * Retrieves the CRM lists from BWFCRM_Lists class.
	 *
	 * @return array The CRM lists.
	 */
	public function get_crm_lists() {
		$bwfcrm_lists = BWFCRM_Lists::get_lists();
		$lists        = array();
		if ( empty( $bwfcrm_lists ) ) {
			return $lists;
		}

		foreach ( $bwfcrm_lists as $key => $list ) {
			$lists[ $list['ID'] ] = $list['name'];
		}

		return $lists;
	}

	/**
	 * Get CRM tags.
	 *
	 * Retrieves an array of CRM tags.
	 *
	 * @return array An array of CRM tags.
	 */
	public function get_crm_tags() {
		$bwfcrm_tags = BWFCRM_Tag::get_tags();
		$tags        = array();

		if ( empty( $bwfcrm_tags ) ) {
			return $tags;
		}

		foreach ( $bwfcrm_tags as $key => $tag ) {
			$tags[ $tag['ID'] ] = $tag['name'];
		}

		return $tags;
	}

	/**
	 * Prepares contact data for import.
	 *
	 * @param mixed $data The raw data to be prepared
	 *
	 * @return array|WP_Error The prepared contact data or WP_Error
	 */
	public function prepare_contact_data( $data = null ) {
		if ( empty( $data ) ) {
			return new WP_Error( 'bwfcrm_invalid_data', __( 'data is not valid.', 'wp-marketing-automations' ) );
		}
		// If it's a WP_User object, use the existing method
		if ( $data instanceof \WP_User ) {
			return $this->prepare_contact_data_from_user( $data );
		}

		$marketing_status = $this->get_import_meta( 'marketing_status' );
		$import_status    = $this->get_import_meta( 'imported_contact_status' );
		$mapping          = $this->get_import_meta( 'mapped_fields' ) ?? [];

		// Set import status from contact data
		if ( ! empty( $data['status'] ) ) {
			$import_status = $data['status'];
		}

		// Initialize contact data with status
		$contact_data = [ 'status' => empty( $marketing_status ) ? 0 : $marketing_status ];
		$email        = '';

		// Set contact status
		if ( in_array( intval( $import_status ), array( 1, 2, 3 ) ) ) {
			$contact_data['status'] = intval( $import_status );
		}

		// Get field types from CRM (cached at class level to avoid repeated queries)
		$field_types = [];
		if ( null === self::$cached_field_types ) {
			$crm_fields = BWFAN_Model_Fields::get_field_types();
			self::$cached_field_types = [];
			foreach ( $crm_fields as $field ) {
				self::$cached_field_types[ $field['ID'] ] = intval( $field['type'] );
			}
		}
		$field_types = self::$cached_field_types;

		// Get source-specific data from child class
		$source_data = $this->get_source_specific_data( $data );
		if ( is_wp_error( $source_data ) ) {
			return $source_data;
		}

		// Extract email and additional fields from source data
		if ( isset( $source_data['email'] ) ) {
			if ( ! is_email( $source_data['email'] ) ) {
				return new WP_Error( 'bwfcrm_invalid_email', __( 'Email is not valid.', 'wp-marketing-automations' ), $data );
			}
			$email = $source_data['email'];
		}

		// Process mapped fields
		foreach ( $mapping as $source_field => $crm_field ) {

			if ( empty( $source_data[ $source_field ] ) ) {
				continue;
			}

			$value = $source_data[ $source_field ];

			// Handle special fields
			if ( 'email' === $crm_field ) {
				if ( ! is_email( $value ) ) {
					return new WP_Error( 'bwfcrm_invalid_email', __( 'Email is not valid.', 'wp-marketing-automations' ), $data );
				}
				$email = $value;
				continue;
			}

			if ( 'country' === $crm_field ) {
				$contact_data[ $crm_field ] = BWFAN_Common::get_country_iso_code( $value );
				continue;
			}

			if ( 'address' === $crm_field && is_array( $value ) ) {
				$address_fields = $this->map_address_to_crm_fields( $value );
				$contact_data   = array_replace( $contact_data, $address_fields );
				continue;
			}

			// Handle custom field types
			if ( isset( $field_types[ $crm_field ] ) ) {
				$field_type                 = $field_types[ $crm_field ];
				$contact_data[ $crm_field ] = $this->format_field_value( $value, $field_type );
			} else {
				$contact_data[ $crm_field ] = $value;
			}
		}

		// Process tags and lists if available
		if ( isset( $data['tags'] ) ) {
			$contact_data['tags'] = $this->prepare_terms( $data['tags'], 'tags' );
		}

		if ( isset( $data['lists'] ) ) {
			$contact_data['lists'] = $this->prepare_terms( $data['lists'], 'lists' );
		}

		// Validate email
		if ( empty( $email ) ) {
			return new WP_Error( 'bwfcrm_empty_email', __( 'Email is empty.', 'wp-marketing-automations' ), $contact_data );
		}

		// Set source
		$contact_data['source'] = $this->get_import_type();

		return [
			'email' => $email,
			'data'  => $contact_data,
		];
	}

	/**
	 * Maps address data to the appropriate CRM fields
	 *
	 * @param array $address_data The address data array
	 *
	 * @return array Mapped field IDs and values
	 */
	protected function map_address_to_crm_fields( $address_data ) {
		return $address_data;
	}

	/**
	 * Format field value based on a field type
	 *
	 * @param mixed $value The value to format
	 * @param int $field_type The type of field
	 *
	 * @return mixed Formatted value
	 */
	protected function format_field_value( $value, $field_type ) {
		switch ( $field_type ) {
			case BWFCRM_Fields::$TYPE_CHECKBOX:
				return $this->format_select_value( $value );

			case BWFCRM_Fields::$TYPE_DATE:
				if ( empty( $value ) ) {
					return null;
				}
				$date = date_create( $value );

				return $date ? $date->format( 'Y-m-d' ) : null;

			case BWFCRM_Fields::$TYPE_NUMBER:
				return is_numeric( $value ) ? floatval( $value ) : '';

			case BWFCRM_Fields::$TYPE_SELECT:
			case BWFCRM_Fields::$TYPE_RADIO:
				if ( is_array( $value ) ) {
					$value = reset( $value );
				}

				return $this->format_select_value( $value );

			default:
				return $value;
		}
	}

	/**
	 * Format selects value (to be implemented by child classes if needed)
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	protected function format_select_value( $value ) {
		return $value;
	}

	public function update_status_to_running( $remove = false ) {
		if ( $remove && isset( $this->import_meta['is_running'] ) ) {
			unset( $this->import_meta['is_running'] );
		} else {
			$this->import_meta['is_running'] = true;
		}
		$import_meta = wp_json_encode( $this->import_meta );

		/** Update status to running */
		\BWFAN_Model_Import_Export::update( array( 'meta' => $import_meta ), array( 'id' => intval( $this->import_id ) ) );
	}

	/**
	 * Connects to the API and verifies the token.
	 *
	 * @param $call_class
	 * @param $api_credentials
	 *
	 * @return true|WP_Error Returns true if the token is valid, otherwise returns a WP_Error object with the error message.
	 */
	public function connect( $call_class, $api_credentials = array() ) {

		// Set the API credentials
		$this->set_api_credentials( $api_credentials );
		/** @var \WFCO_Call $connect_call */
		$connect_call = new $call_class;
		$connect_call->set_data( $this->get_api_credentials() );

		$verify_token_request = $connect_call->process();

		if ( 200 === $verify_token_request['response'] ) {
			return true;
		}

		return new WP_Error( 'invalid_credentials', __( 'Invalid API credentials', 'wp-marketing-automations' ) );
	}


	/**
	 * Sets the API credentials for the Importer.
	 *
	 * @param array $api_credentials The API credentials to be set.
	 *
	 * @return void
	 */
	public function set_api_credentials( $api_credentials = array() ) {
		bwf_options_update( 'funnelkit_' . $this->slug . '_api_credentials', $api_credentials );
	}

	/**
	 * Retrieves the API credentials.
	 *
	 * @return array The API credentials.
	 */
	public function get_api_credentials() {
		if ( $this->api_credentials === null ) {
			$this->api_credentials = bwf_options_get( 'funnelkit_' . $this->slug . '_api_credentials' );
		}

		return $this->api_credentials;
	}

	/**
	 * update retry count
	 *
	 * @param $data
	 *
	 * @return void
	 */
	public function update_retry( $data ) {
		$retry_data  = $this->import_meta['retry_data'] ?? [];
		$update_data = array_replace( $retry_data, $data );

		$this->import_meta['retry_data'] = $update_data;
		$this->import_meta['retry']      = $this->retry;
		if ( isset( $this->import_meta['is_running'] ) ) {
			unset( $this->import_meta['is_running'] );
		}

		BWFAN_Model_Export_Import::update( array(
			'meta' => wp_json_encode( $this->import_meta ),
		), array( 'id' => $this->get_import_id() ) );
	}

	/**
	 * Return only the fields that are need. If all fields needed to show return blank
	 *
	 * @return array
	 */
	public function contact_profile_fields() {
		return [];
	}

	/**
	 * This method can be overridden by child classes to modify import records after batch processing.
	 *
	 * @return void
	 */
	public function modify_import_records() {}

	/**
	 * This method can be overridden by child classes to handle errors during the contact loop.
	 *
	 * @return void
	 */
	public function handle_contact_loop_error() {}

	/**
	 * Prepare tag and list mappings (to be implemented by child classes if needed)
	 *
	 * @return void
	 */
	public function prepare_tag_list() {
		// Default empty implementation - child classes should override if needed
	}

	/**
	 * Get source-specific data (to be implemented by child classes)
	 *
	 * @param mixed $data
	 *
	 * @return array|WP_Error
	 */
	protected function get_source_specific_data( $data ) {
		// Default implementation - child classes should override
		if ( empty( $data ) ) {
			return new WP_Error( 'bwfcrm_empty_data', __( 'No data provided.', 'wp-marketing-automations' ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Get the current file position (for CSV imports)
	 *
	 * @return int|null The current file position or null if not applicable
	 */
	protected function get_file_position() {
		return $this->file_position;
	}

	/**
	 * Get the total count of contacts to import
	 *
	 * @return int The total count of contacts
	 */
	protected function get_contacts_count() {
		// Default implementation - child classes should override
		return $this->get_count();
	}

	/**
	 * Prepare terms (tags/lists) for import
	 *
	 * @param mixed $value The term values to prepare
	 * @param string $type The type of terms (tags or lists)
	 *
	 * @return array The prepared terms
	 */
	protected function prepare_terms( $value, $type ) {
		// Default implementation - child classes should override if needed
		if ( empty( $value ) ) {
			return array();
		}

		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$terms = explode( ',', $value );
			return array_filter( array_map( 'trim', $terms ) );
		}

		return array();
	}

	/**
	 * Abstract class for importers.
	 *
	 * This class defines the basic structure and methods that all importers should implement.
	 *
	 */
	abstract public function populate_contact_data();

	/**
	 * Get the log headers for this importer.
	 * This class defines the basic structure and methods that all importers should implement.
	 *
	 * @return array
	 */
	abstract protected function get_log_headers();

	/**
	 * Prepare log data for a single item.
	 * This class defines the basic structure and methods that all importers should implement.
	 *
	 * @param mixed $data
	 * @param mixed $result
	 *
	 * @return array
	 */
	abstract protected function prepare_log_data( $data, $result );
}
