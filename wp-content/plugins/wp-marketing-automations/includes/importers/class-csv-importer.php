<?php

namespace BWFAN\Importers;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Class CSV_Importer
 *
 * This class represents a CSV importer for the CRM system.
 */
#[\AllowDynamicProperties]
class CSV_Importer extends Importer {
	const DEFAULT_DELIMITER = ',';
	const LIMIT = 10;

	/**
	 * @var string $import_type The type of import (csv).
	 */
	protected $import_type = 'csv';
	protected $raw_keys = array();
	protected $file_position = 0;

	/**
	 * Constructor for the CSV_Importer class.
	 *
	 * @param array $params Parameters for the importer.
	 */
	public function __construct( $params = array() ) {

		$this->slug        = 'csv';
		$this->name        = __( 'CSV', 'wp-marketing-automations' );
		$this->description = __( 'Import CSV', 'wp-marketing-automations' );
		$this->logo_url    = esc_url( plugin_dir_url( BWFAN_PLUGIN_FILE ) . '/admin/assets/img/importer/csv.png' );
		$this->has_fields  = true;
		$this->group       = 0;
		$this->priority    = 5;

		$default_args = array(
			'import_id'         => 0,
			'file'              => '',
			'delimiter'         => self::DEFAULT_DELIMITER,
			'limit'             => self::LIMIT,
			'dont_update_blank' => true,
			'mapping'           => array(),
			'end_position'      => - 1,
		);

		$params = wp_parse_args( $params, $default_args );

		parent::__construct( $params );
	}

	/**
	 * Retrieves the field schema for the CSV importer.
	 *
	 * @return array The field schema for the CSV importer.
	 */
	public function get_field_schema() {
		return [
			[
				'id'         => 'file',
				'type'       => 'file_upload',
				'btnLabel'   => __( 'Drop your CSV file here OR', 'wp-marketing-automations' ),
				'filetype'   => [
					'text/csv',
					'text/plain',
					'application/csv',
					'text/comma-separated-values',
					'application/excel',
					'application/vnd.ms-excel',
					'application/vnd.msexcel',
					'text/anytext',
					'application/octet-stream',
					'application/txt',
				],
				'doUpload'   => true,
				'uploadPath' => $this->import_folder,
				'required'   => true,
			],
			[
				'id'         => 'delimiter',
				'type'       => 'text',
				'label'   => __( 'Delimiter', 'wp-marketing-automations' ),
				'required'   => true,
				'tip'   => __( 'Enter the delimiter character for the CSV file. Default is comma (,).', 'wp-marketing-automations' ),
				'toggler' => [
					'fields' => [
						[
							'id' => 'file',
							'value' => 'non-empty',
						]
					],
					'relation' => 'AND',
				],
			],
		];
	}

	/**
	 * Retrieves the current file position.
	 *
	 * @return int The current file position.
	 */
	public function get_file_position() {
		return $this->file_position;
	}

	/**
	 * Prepares the import data for creating a new import.
	 *
	 * @param array $import_data The import data to be prepared.
	 *
	 * @return array The prepared import data.
	 */
	public function prepare_create_import_data( $import_data = array(), $fields = array() ) {
		$import_data['meta']['file']              = $fields['file'] ?? '';
		$import_data['meta']['delimiter']         = $fields['delimiter'] ?? self::DEFAULT_DELIMITER;
		$import_data['meta']['dont_update_blank'] = $this->params['dont_update_blank'];
		$import_data['meta']['mapped_fields']     = $this->params['mapping'];
		$import_data['meta']['end_position']      = $this->params['end_position'];
		$import_data['count']                     = $this->get_contacts_count();

		return $import_data;
	}

	/**
	 * Prepare the data for CSV import.
	 *
	 * This method reads the CSV file, sets the delimiter, and retrieves the raw data and keys.
	 * It also removes the BOM signature from the first item of the raw keys.
	 * The method supports offset and limit parameters for partial import.
	 *
	 * @return void
	 */
	public function populate_contact_data() {
		$this->raw_data = array();

		$delimiter = $this->get_import_meta( 'delimiter' );
		$delimiter = empty( $delimiter ) ? self::DEFAULT_DELIMITER : $delimiter;

		$file = $this->get_import_meta( 'file' );

		// Validate file path to prevent directory traversal
		if ( empty( $file ) || ! is_string( $file ) ) {
			return;
		}

		$allowed_dir = defined( 'BWFAN_IMPORT_DIR' ) ? BWFAN_IMPORT_DIR : wp_upload_dir()['basedir'] . '/funnelkit/fka-import';

		// Ensure allowed directory exists before validation
		if ( ! file_exists( $allowed_dir ) ) {
			wp_mkdir_p( $allowed_dir );
		}

		$real_path   = realpath( $file );
		$real_allowed = realpath( $allowed_dir );

		// Ensure file is within allowed directory
		if ( false === $real_path || false === $real_allowed || strpos( $real_path, $real_allowed ) !== 0 ) {
			return;
		}

		// Ensure file exists and is readable
		if ( ! file_exists( $real_path ) || ! is_readable( $real_path ) ) {
			return;
		}

		$handle = fopen( $real_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reads the uploaded CSV line by line; WP_Filesystem has no streaming API

		if ( ! $handle ) {
			return;
		}

		if ( 0 === $this->get_offset() ) {
			// Read the first row (headers) and set the file position to the end of the first row.
			$this->raw_keys = array_map( 'trim', fgetcsv( $handle, 0, $delimiter, '"', '\\' ) );

			// Remove BOM signature from the first item.
			if ( isset( $this->raw_keys[0] ) ) {
				$this->raw_keys[0] = $this->remove_utf8_bom( $this->raw_keys[0] );
			}
		} else {
			// Set the file position to the offset.
			fseek( $handle, $this->get_offset() );
		}

		$row = fgetcsv( $handle, 0, $delimiter, '"', '\\' );

		if ( false === $row ) {
			return;
		}
		$this->raw_data[] = $row;

		$this->file_position = ftell( $handle );
	}

	/**
	 * Extracts and prepares source-specific data from CSV row data.
	 *
	 * This method processes a row of CSV data and transforms it into a standardized format
	 * that the parent class's prepare_contact_data method can use.
	 *
	 * @param array $data Raw CSV row data where each element represents a column value
	 *
	 * @return array|WP_Error
	 *
	 */
	protected function get_source_specific_data( $data ) {
		if ( empty( $data ) ) {
			return new WP_Error( 'bwfcrm_empty_data', __( 'No data provided.', 'wp-marketing-automations' ) );
		}

		$mapped_fields = $this->get_import_meta( 'mapped_fields' );
		$source_data   = [];

		foreach ( $data as $index => $value ) {
			$value = trim( $value );
			if ( ! isset( $mapped_fields[ $index ] ) ) {
				continue;
			}

			// Handle encoding - only convert if needed
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$encoding = mb_detect_encoding( $value, mb_detect_order(), true );
				if ( $encoding && 'UTF-8' !== $encoding ) {
					// Only convert if encoding is detected and different from UTF-8
					$value = mb_convert_encoding( $value, 'UTF-8', $encoding );
				} elseif ( ! $encoding ) {
					// If encoding detection fails, use WordPress fallback
					$value = wp_check_invalid_utf8( $value, true );
				}
				// If already UTF-8, no conversion needed
			} else {
				$value = wp_check_invalid_utf8( $value, true );
			}

			$source_data[ $index ] = $value;
		}

		return $source_data;
	}

	/**
	 * Returns the percentage of completion for the CSV import process.
	 *
	 * @return int The percentage of completion (0-100).
	 */
	public function get_percent_completed() {
		$meta = json_decode( $this->import['meta'], true );
		/** Because file gets deleted after import is completed, so returning 100% */
		if ( ! isset( $meta['file'] ) || ! file_exists( $meta['file'] ) ) {
			return 100;
		}

		$file_size = filesize( $meta['file'] );
		if ( empty( $file_size ) || $file_size <= 0 ) {
			return 100;
		}

		$offset = $this->get_offset();

		// Calculate percentage based on file position (bytes) vs total file size
		// Note: This is approximate since CSV rows have variable lengths
		$percent = floor( ( $offset / $file_size ) * 100 );

		return absint( min( $percent, 100 ) );
	}

	/**
	 * Get the log headers for this importer.
	 *
	 * @return array
	 */
	protected function get_log_headers() {
		return [ 'ID', 'Email', 'Status' ];
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
		$mapped_fields = is_array( $this->get_import_meta( 'mapped_fields' ) ) ? $this->get_import_meta( 'mapped_fields' ) : [];
		$email_index   = array_search( 'email', $mapped_fields );

		return [
			'ID'     => is_wp_error( $result ) ? 0 : $result['id'],
			'Email'  => ( $email_index !== false && isset( $data[ $email_index ] ) ) ? $data[ $email_index ] : '',
			'Status' => is_wp_error( $result ) ? __( 'Failed', 'wp-marketing-automations' ) : ( isset( $result['skipped'] ) && $result['skipped'] ? __( 'Skipped', 'wp-marketing-automations' ) : __( 'Success', 'wp-marketing-automations' ) ),
		];
	}

	/**
	 * Remove UTF-8 BOM signature.
	 *
	 * @param string $string String to handle.
	 *
	 * @return string
	 */
	protected function remove_utf8_bom( $string ) {
		if ( 'efbbbf' === substr( bin2hex( $string ), 0, 6 ) ) {
			$string = substr( $string, 3 );
		}

		return $string;
	}

	/**
	 * Retrieves the mapping options for CSV importer.
	 *
	 * This function reads the CSV file, fetches the header, and formats it for mapping.
	 * It also retrieves the contact fields and adds additional data for mapping tags and lists.
	 *
	 * @return string|array The mapping options including headers and fields.
	 */
	public function get_mapping_options( $csv_file, $delimiter ) {
		$mapping_options = array(
			'fields' => $this->get_crm_fields( true, true ),
		);

		$delimiter = empty( $delimiter ) ? self::DEFAULT_DELIMITER : $delimiter;

		// Validate file path to prevent directory traversal
		if ( empty( $csv_file ) || ! is_string( $csv_file ) ) {
			return __( 'Invalid file path', 'wp-marketing-automations' );
		}

		$allowed_dir = defined( 'BWFAN_IMPORT_DIR' ) ? BWFAN_IMPORT_DIR : wp_upload_dir()['basedir'] . '/funnelkit/fka-import';

		// Ensure allowed directory exists before validation
		if ( ! file_exists( $allowed_dir ) ) {
			wp_mkdir_p( $allowed_dir );
		}

		$real_path   = realpath( $csv_file );
		$real_allowed = realpath( $allowed_dir );

		// Ensure file is within allowed directory
		if ( false === $real_path || false === $real_allowed || strpos( $real_path, $real_allowed ) !== 0 ) {
			return __( 'File path is not allowed', 'wp-marketing-automations' );
		}

		if ( ! file_exists( $real_path ) || ! is_readable( $real_path ) ) {
			return __( 'File not found or not readable', 'wp-marketing-automations' );
		}

		$handle = fopen( $real_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reads the uploaded CSV line by line; WP_Filesystem has no streaming API

		/** Fetching CSV header */
		$headers = false !== $handle ? fgetcsv( $handle, 0, $delimiter, '"', '\\' ) : false;
		if ( ! is_array( $headers ) ) {
			return __( 'Unable to read file', 'wp-marketing-automations' );
		}

		if ( isset( $headers[0] ) ) {
			$headers[0] = self::remove_utf8_bom( $headers[0] );
		}

		/** Formatting CSV header for mapping */
		foreach ( $headers as $index => $header ) {
			$headers[ $index ] = array(
				'index'  => $index,
				'header' => $header,
			);
		}

		$mapping_options['headers'] = $headers;

		return $mapping_options;
	}

	/**
	 * Get the latest count of rows in the CSV file.
	 *
	 * This function reads the CSV file and counts the number of data rows,
	 * excluding the header row if present.
	 *
	 * @return int The number of data rows in the CSV file.
	 */
	public function get_contacts_count() {
		/** If count is already set */
		if ( $this->get_count() > 0 ) {
			return $this->get_count();
		}
		$file      = $this->get_import_meta( 'file' ) ?? '';
		$delimiter = $this->get_import_meta( 'delimiter' ) ?? self::DEFAULT_DELIMITER;

		if ( empty( $file ) || ! is_string( $file ) ) {
			return 0;
		}

		// Validate file path to prevent directory traversal
		$allowed_dir = defined( 'BWFAN_IMPORT_DIR' ) ? BWFAN_IMPORT_DIR : wp_upload_dir()['basedir'] . '/funnelkit/fka-import';

		// Ensure allowed directory exists before validation
		if ( ! file_exists( $allowed_dir ) ) {
			wp_mkdir_p( $allowed_dir );
		}

		$real_path   = realpath( $file );
		$real_allowed = realpath( $allowed_dir );

		// Ensure file is within allowed directory
		if ( false === $real_path || false === $real_allowed || strpos( $real_path, $real_allowed ) !== 0 ) {
			return 0;
		}

		if ( ! file_exists( $real_path ) || ! is_readable( $real_path ) ) {
			return 0;
		}

		$handle = fopen( $real_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reads the uploaded CSV line by line; WP_Filesystem has no streaming API
		if ( $handle === false ) {
			return 0;
		}

		fgetcsv( $handle, 0, $delimiter, '"', '\\' );
		$row_count = 0;

		while ( fgetcsv( $handle, 0, $delimiter, '"', '\\' ) !== false ) {
			$row_count ++;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the stream opened above

		return $row_count;
	}

	/**
	 * Get the second step fields for CSV importer
	 *
	 * @return array|string
	 */
	public function get_second_step_fields( $fields ) {
		$file = ! empty( $fields['file'] ) ? $fields['file'] : '';
		$delimiter = ! empty( $fields['delimiter'] ) ? $fields['delimiter'] : self::DEFAULT_DELIMITER;
		if ( empty( $file ) || ! file_exists( $file ) ) {
			return [
				'status'  => 3,
				'message' => __( 'CSV file not found', 'wp-marketing-automations' ),
			];
		}

		$options = $this->get_mapping_options( $file, $delimiter );
		if ( ! is_array( $options ) ) {
			return is_string( $options ) ? $options : __( 'Unknown error occurred', 'wp-marketing-automations' );
		}

		$mapped_fields_options = [
			[
				'key'   => '',
				'label' => __( 'Do Not Import This Field', 'wp-marketing-automations' ),
			]
		];
		$fields_list           = [];
		$default_mapping = [];
		foreach ( $options['headers'] as $header ) {
			$fields_list[] = [
				'slug'  => $header['index'],
				'title' => $header['header']
			];
			if ( ! empty( $header['header'] ) && in_array( strtolower( trim( $header['header'] ) ), [ 'mail', 'email' ] ) ) {
				$default_mapping[ $header['index'] ] = 'email';
			}
		}

		foreach ( $options['fields'] as $field ) {
			$namekey = $field['name'] ?? null;
			foreach ( $field['fields'] as $fieldData ) {
				$data = [
					'key'   => $fieldData['id'],
					'label' => $fieldData['name'],
					'nameKey' => $namekey,
					'helptext'   => $fieldData['hint'] ?? '',
				];

				if ( ! empty( $namekey ) ) {
					$namekey = null;
				}

				$mapped_fields_options[] = $data;
			}
		}

		return [
			[
				'id'           => 'mapped_fields',
				'type'         => 'fields',
				'defaultValue' => $default_mapping,
				'label'        => __( 'CSV Columns', 'wp-marketing-automations' ),
				'options'      => $mapped_fields_options,
				'fieldsList'   => $fields_list,
			]
		];
	}

	/**
	 * Handle the update process for CSV import.
	 *
	 * @return array|WP_Error The result of the update process or an error.
	 */
	public function handle_update( $data ) {
		$mapped_fields = isset( $data['mapped_fields'] ) && is_array( $data['mapped_fields'] ) ? $data['mapped_fields'] : array();

		if ( count( $mapped_fields ) > 0 && ! in_array( 'email', $mapped_fields, true ) ) {
			return new WP_Error( 'bwfcrm_missing_email_mapping', __( 'Email mapping is required', 'wp-marketing-automations' ) );
		}

		return array(
			'mapped_fields' => $mapped_fields
		);
	}

	/**
	 * Get the default values for the CSV importer.
	 *
	 * @return array The default values for the CSV importer.
	 */
	public function get_default_values() {
		return [
			'delimiter' => self::DEFAULT_DELIMITER,
		];
	}
}

BWFAN_Core()->importer->register( 'csv', 'BWFAN\Importers\CSV_Importer' );
