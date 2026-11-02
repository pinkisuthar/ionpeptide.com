<?php

namespace BWFAN\Exporter;

/**
 * Calls base class
 */
#[\AllowDynamicProperties]
abstract class Base {

	/** Handle exporter response type */
	public static $EXPORTER_ONGOING = 1;
	public static $EXPORTER_SUCCESS = 2;
	public static $EXPORTER_FAILED = 3;
	public static $export_folder = BWFAN_SINGLE_EXPORT_DIR;

	/**
	 * Exporter Type
	 *
	 * @var string
	 */
	protected $type = '';

	/**
	 * Abstract function to process export action
	 *
	 * @param int $user_id
	 * @param int $export_id
	 *
	 * @return void
	 */
	abstract public function handle_export( $user_id, $export_id );

	/**
	 * Save processed and count data in table
	 *
	 * @param array $extra_data (optional)
	 *
	 * @return array|int
	 */
	public function insert_data_in_table( $extra_data = [] ) {
		if ( ! class_exists( 'BWFAN_Model_Import_Export' ) || ( ! file_exists( self::$export_folder ) && ! wp_mkdir_p( self::$export_folder ) ) ) {
			return 0;
		}

		$file_name = $this->get_file_name();
		if ( empty( $file_name ) ) {
			return 0;
		}

		$meta = [ 'file' => $file_name ];

		// Add extra data to meta
		if ( ! empty( $extra_data ) ) {
			$meta = array_merge( $meta, $extra_data );
		}

		$data = [
			'offset'        => 0,
			'processed'     => 0,
			'type'          => 3,
			'status'        => self::$EXPORTER_ONGOING,
			'count'         => 0,
			'meta'          => wp_json_encode( $meta ),
			'created_date'  => current_time( 'mysql', 1 ),
			'last_modified' => current_time( 'mysql', 1 )
		];

		\BWFAN_Model_Import_Export::insert( $data );
		$export_id = \BWFAN_Model_Import_Export::insert_id();
		if ( empty( $export_id ) ) {
			return 0;
		}

		return [
			'id'   => $export_id,
			'file' => self::$export_folder . '/' . $file_name
		];
	}

	/**
	 * Get total count
	 *
	 * @param array $data
	 *
	 * @return mixed
	 */
	public function get_total_count( $data = [] ) {
		return 0;
	}

	/**
	 * Get export file name
	 *
	 * @return string
	 */
	public function get_file_name() {
		return '';
	}

	/**
	 * Update offset and processed count
	 *
	 * @return void
	 */
	public function update_export_offset( $export_id, $offset, $processed, $count ) {
		\BWFAN_Model_Import_Export::update( [
			"offset"    => $offset,
			"processed" => $processed,
			"count"     => $count
		], [ 'id' => absint( $export_id ) ] );
	}

	/**
	 * End export process
	 *
	 * @param $user_id
	 * @param $export_id
	 * @param $file_name
	 * @param $status
	 * @param $status_message
	 *
	 * @return void
	 */
	public function end_user_export( $user_id, $export_id, $file_name, $status, $status_message ) {

		if ( empty( $status_message ) && $status === self::$EXPORTER_FAILED ) {
			$status_message = __( 'Export error. Export ID: ', 'wp-marketing-automations' ) . $export_id;
		}

		$user_data                = get_user_meta( $user_id, 'bwfan_single_export_status', true ) ?: [];
		$user_data[ $this->type ] = [
			'status' => $status,
			'url'    => self::$export_folder . '/' . $file_name,
			'msg'    => [ $status_message ]
		];
		update_user_meta( $user_id, 'bwfan_single_export_status', $user_data );

		BWFAN_Core()->exporter->unschedule_export_action( [
			'type'      => $this->type,
			'user_id'   => $user_id,
			'export_id' => $export_id
		] );

		$export_meta = [
			'status_msg' => $status_message
		];
		\BWFAN_Model_Import_Export::update( [
			'status' => $status,
			'meta'   => wp_json_encode( $export_meta )
		], [ 'id' => absint( $export_id ) ] );
	}
}
