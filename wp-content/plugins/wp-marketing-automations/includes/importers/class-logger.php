<?php

namespace BWFAN\Importers;

use Exception;

/**
 * Class Logger
 *
 * This class represents a logger that logs messages to a file.
 */
#[\AllowDynamicProperties]
class Logger {
	private $file_path;

	public function __construct( $file_path ) {
		$this->file_path = $file_path;
	}

	/**
	 * Logs a message with optional data to a CSV file.
	 *
	 * @param string $message The message to be logged.
	 * @param array $data Optional data to be included in the log entry.
	 * @return void
	 */
	public function log( $message, $data = array() ) {
		$default_args = array(
			'timestamp'     => gmdate( 'Y-m-d H:i:s' ),
			'message'       => empty( $message ) ? __( 'An error occurred during import.', 'wp-marketing-automations' ) : $message,
			'contact_id'    => 0,
			'contact_email' => '',
			'original_id'   => 0,
			'first_name'    => '',
			'last_name'     => '',
			'status'        => 'failed',
		);

		$log_entry = wp_parse_args( $default_args, $data );

		$this->write_to_csv( array_values( $log_entry ) );
	}

	/**
	 * Writes data to a CSV file.
	 *
	 * @param array $data The data to be written to the CSV file.
	 * @return void
	 */
	private function write_to_csv( $data ) {
		try {
			$file_exists = file_exists( $this->file_path );

			$file = fopen( $this->file_path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- appends to the importer log file; WP_Filesystem has no append API

			// Add header if the file is newly created
			if ( ! $file_exists ) {
				fputcsv(
					$file,
					array(
						__( 'Timestamp', 'wp-marketing-automations' ),
						__( 'Message', 'wp-marketing-automations' ),
						__( 'Contact ID', 'wp-marketing-automations' ),
						__( 'Contact Email', 'wp-marketing-automations' ),
						__( 'Original ID', 'wp-marketing-automations' ),
						__( 'Status', 'wp-marketing-automations' ),
					),
					',',
					'"',
					'\\'
				);
			}

			fputcsv( $file, $data, ',', '"', '\\' );

			fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the stream opened above
		} catch ( Exception $e ) {
			/* translators: 1: Error message */
			error_log( sprintf( __( 'Error writing to log file: %1$s', 'wp-marketing-automations' ), $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- fallback when writing the importer log file fails
		}
	}
}
