<?php

use BWFAN\Importers\Importer;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class BWFAN_Importer
 * @package Autonami
 */
#[AllowDynamicProperties]
class BWFAN_Importer {
	private static $ins = null;

	/** Import type */
	public static $IMPORT = 1;

	/** Import Status */
	public static $IMPORT_DRAFT = 0;
	public static $IMPORT_IN_PROGRESS = 1;
	public static $IMPORT_FAILED = 2;
	public static $IMPORT_SUCCESS = 3;
	public static $IMPORT_CANCELLED = 4;

	// Import option key saves current in-progress import ID
	public static $IMPORT_OPTION_KEY = 'bwfan_contact_importer_data';

	public static $IMPORTER_ACTION_HOOK = 'bwfan_contact_importer';

	private $_importers = array();

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		/** Core Action Scheduler set */
		add_action( 'bwf_as_data_store_set', array( $this, 'bwfan_importer_action' ), 9 );
	}

	/**
	 * Registers actions for each importer and associates them with the import method.
	 */
	public function bwfan_importer_action() {
		add_action( self::$IMPORTER_ACTION_HOOK, array( $this, 'import' ), 10, 1 );
	}

	/**
	 * Loads the importer classes.
	 */
	public function load_importer_classes( $slug = '' ) {
		$importers_dir = __DIR__ . '/importers';

		// Load the required files.
		require_once $importers_dir . '/class-logger.php';
		require_once $importers_dir . '/class-autoresponder-importer-interface.php';

		if ( ! empty( $slug ) ) {
			$class_file = $importers_dir . '/class-' . $slug . '-importer.php';

			if ( file_exists( $class_file ) ) {
				require_once $class_file;
			}
		} else {
			self::load_class_files( $importers_dir );
		}

		do_action( 'bwfan_importer_loaded' );
	}

	public function register( $slug, $class ) {
		if ( empty( $slug ) ) {
			return;
		}

		$this->_importers[ $slug ] = $class;
	}

	public function get_importers() {
		$this->load_importer_classes();

		return $this->_importers;
	}

	/**
	 * Retrieves the importer instance based on the given slug.
	 *
	 * @param string $slug The slug of the importer.
	 * @param array $args Optional arguments to be passed to the importer constructor.
	 *
	 * @return object|null The importer instance if found, null otherwise.
	 */
	public function get_importer( $slug, $args = array() ) {
		if ( empty( $slug ) ) {
			return null;
		}

		$this->load_importer_classes( $slug );
		$importer_class = $this->_importers[ $slug ] ?? '';

		if ( empty( $importer_class ) ) {
			$importer_classes = BWFAN_Core()->importer->get_importers();
			$importer_class   = $importer_classes[ $slug ] ?? '';
		}

		return class_exists( $importer_class ) ? new $importer_class( $args ) : null;
	}

	/**
	 * Imports data using the specified import ID.
	 *
	 * @param int $import_id The ID of the import.
	 *
	 * @return void
	 */
	public function import( $import_id = '' ) {
		if ( empty( $import_id ) ) {
			BWFAN_Core()->logger->log( __( 'Import failed: No import ID provided', 'wp-marketing-automations' ), 'import_contacts_crm' );

			return;
		}

		$import = BWFAN_Model_Export_Import::get( $import_id );
		if ( empty( $import ) ) {
			/* translators: 1: Importer id */
			$message = sprintf( __( 'Import failed: Import with ID %1$s not found', 'wp-marketing-automations' ), $import_id );
			$this->bwfan_unschedule_import( $import_id, BWFAN_Importer::$IMPORT_FAILED, $message );
			/* translators: 1: Importer id */
			BWFAN_Core()->logger->log( sprintf( __( 'Import failed for ID %1$s: Import not found', 'wp-marketing-automations' ), $import_id ), 'import_contacts_crm' );

			return;
		}

		$import_meta = empty( $import['meta'] ) ? array() : json_decode( $import['meta'], true );
		$import_type = empty( $import_meta['import_type'] ) ? '' : $import_meta['import_type'];
		if ( empty( $import_type ) ) {
			$message = __( 'Import failed: Import type not specified', 'wp-marketing-automations' );
			$this->bwfan_unschedule_import( $import_id, BWFAN_Importer::$IMPORT_FAILED, $message );

			/* translators: 1: Importer id */
			BWFAN_Core()->logger->log( sprintf( __( 'Import failed for ID %1$s: Import type not specified', 'wp-marketing-automations' ), $import_id ), 'import_contacts_crm' );

			return;
		}

		$importer = $this->get_importer( $import_type, array( 'import_id' => $import_id ) );
		if ( empty( $importer ) ) {
			/* translators: 1: Importer Type */
			$message = sprintf( __( 'Import failed: Importer for type "%1$s" not found', 'wp-marketing-automations' ), $import_type );
			$this->bwfan_unschedule_import( $import_id, BWFAN_Importer::$IMPORT_FAILED, $message );
			/* translators: 1: importer id 2: Importer type */
			BWFAN_Core()->logger->log( sprintf( __( 'Import failed for ID %1$s: Importer for type "%2$s" not found', 'wp-marketing-automations' ), $import_id, $import_type ), 'import_contacts_crm' );

			return;
		}

		/**
		 * @var Importer $importer
		 */
		$importer->import( $this->get_importer_time_period_threshold( $import_type ) );
	}

	/**
	 * Returns the importer running time period threshold.
	 *
	 * @param string $type The type of importer (optional).
	 *
	 * @return int time period in second.
	 */
	public function get_importer_time_period_threshold( $type = '' ) {
		return apply_filters( 'bwfan_importer_time_period_threshold', 20, $type );
	}

	/**
	 * Unschedules import actions and marks the import as failed with an error message.
	 *
	 * @param int $import_id The ID of the import
	 * @param int $status The status to set for the import (usually IMPORT_FAILED)
	 * @param string $status_message The error message to store
	 * @param array $import_meta Additional import metadata
	 *
	 * @return void
	 */
	public function bwfan_unschedule_import( $import_id, $status = '2', $status_message = '', $import_meta = [] ) {
		// If import_meta is empty, try to get existing meta
		if ( empty( $import_meta ) ) {
			$import = BWFAN_Model_Export_Import::get( $import_id );
			if ( ! empty( $import ) && ! empty( $import['meta'] ) ) {
				$import_meta = json_decode( $import['meta'], true );
			}
		}

		// Update import status and metadata
		BWFAN_Model_Export_Import::update( [
			'status' => $status,
			'meta'   => wp_json_encode( array_merge( $import_meta, [
				'error_msg' => $status_message,
			] ) )
		], [ 'id' => $import_id ] );

		// Unschedule any pending actions for this import
		if ( bwf_has_action_scheduled( BWFAN_Importer::$IMPORTER_ACTION_HOOK, array( 'import_id' => $import_id ), 'bwfan' ) ) {
			bwf_unschedule_actions( BWFAN_Importer::$IMPORTER_ACTION_HOOK, array( 'import_id' => $import_id ), 'bwfan' );
			BWFAN_Importer::update_import_option( '' );
		}

		// Log the error
		BWFAN_Core()->logger->log( $status_message, 'import_contacts_crm' );
	}

	/**
	 * Get Importer Status Text
	 *
	 * @param $status
	 *
	 * @return string
	 */
	public static function get_status_text( $status ) {
		switch ( $status ) {
			case self::$IMPORT_IN_PROGRESS:
				return 'in_progress';
			case self::$IMPORT_FAILED:
				return 'failed';
			case self::$IMPORT_SUCCESS:
				return 'success';
			case self::$IMPORT_CANCELLED:
				return 'cancelled';
			default:
				return '';
		}
	}

	/**
	 * Remove the already running action and Re-schedule new one,
	 * And ping WooFunnels worker to run immediately
	 *
	 * @param $import_id
	 * @param $action_hook
	 */
	public function reschedule_background_action( $import_id ) {
		if ( bwf_has_action_scheduled( BWFAN_Importer::$IMPORTER_ACTION_HOOK, array( 'import_id' => $import_id ), 'bwfan' ) ) {
			bwf_unschedule_actions( BWFAN_Importer::$IMPORTER_ACTION_HOOK, array( 'import_id' => absint( $import_id ) ), 'bwfan' );
			BWFAN_Importer::update_import_option( '' );
		}

		bwf_schedule_recurring_action( time() + 60, 60, BWFAN_Importer::$IMPORTER_ACTION_HOOK, array( 'import_id' => absint( $import_id ) ), 'bwfan' );
		BWFAN_Importer::update_import_option( $import_id );
	}

	/**
	 * Create new log file
	 *
	 * @param $file
	 * @param $file_header
	 *
	 * @return false|resource
	 */
	public static function create_importer_log_file( $file, $file_header ) {
		$file_source = fopen( $file, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- appends to the export/import file; WP_Filesystem has no append API
		if ( ! empty( $file_source ) ) {
			fputcsv( $file_source, $file_header, ',', '"', '\\' );
			fclose( $file_source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the stream opened above
		}

		return $file_source;
	}

	/**
	 * Append data to log file
	 *
	 * @param $data
	 * @param $file_name
	 */
	public static function append_data_to_log_file( $file_name, $data ) {
		$file = fopen( $file_name, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- appends to the export/import file; WP_Filesystem has no append API

		if ( ! empty( $file ) ) {
			fputcsv( $file, $data, ',', '"', '\\' );
			fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the stream opened above
		}
	}

	/**
	 * Loads class files from a specified directory.
	 *
	 * This function iterates through all PHP files in the given directory and requires them.
	 * It skips the file named 'index.php'.
	 *
	 * @param string $dir The directory path to load class files from.
	 *
	 * @return void
	 */
	public static function load_class_files( $dir ) {
		foreach ( glob( $dir . '/class-*.php' ) as $filename ) {
			if ( ! is_readable( $filename ) ) { // survive update file-swap / partial deploy
				continue;
			}
			$file_data = pathinfo( $filename );

			if ( isset( $file_data['basename'] ) && 'index.php' === $file_data['basename'] ) {
				continue;
			}

			require_once $filename;
		}
	}

	/**
	 * Update import option
	 *
	 * @param string | int $data The data to be updated in the import option.
	 *
	 * @return void
	 */
	public static function update_import_option( $data = '' ) {
		update_option( self::$IMPORT_OPTION_KEY, $data, true );
	}

	/**
	 * Get import option
	 *
	 * @return sting The value of the import option.
	 */
	public static function get_import_option() {
		return get_option( self::$IMPORT_OPTION_KEY, '' );
	}

	/**
	 * Get formatted fields
	 *
	 * @param $fields
	 *
	 * @return array
	 */
	public static function get_formatted_fields( $fields ) {
		if ( empty( $fields ) ) {
			return [];
		}
		$mapped_fields_options = [];
		foreach ( $fields as $field ) {
			$namekey = $field['name'] ?? null;
			foreach ( $field['fields'] as $fieldData ) {
				$data = [
					'key'     => $fieldData['id'],
					'label'   => $fieldData['name'],
					'nameKey' => $namekey,
				];

				if ( ! empty( $namekey ) ) {
					$namekey = null;
				}

				$mapped_fields_options[] = $data;
			}
		}

		return $mapped_fields_options;
	}
}

if ( class_exists( 'BWFAN_Importer' ) ) {
	BWFAN_Core::register( 'importer', 'BWFAN_Importer' );
}