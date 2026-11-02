<?php

namespace BWFAN\Exporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

#[\AllowDynamicProperties]
class Automation extends Base {
	public function __construct() {
		$this->type = 'automation';
	}

	public function get_file_name() {
		$file_name = 'automation-export-' . time() . '-';
		if ( class_exists( '\BWFAN_Common' ) && method_exists( '\BWFAN_Common', 'create_token' ) ) {
			$file_name .= \BWFAN_Common::create_token( 5 );
		} else {
			$file_name .= wp_generate_password( 5, false );
		}
		$file_name .= '.json';

		return $file_name;
	}

	/**
	 * Handle Automation export
	 *
	 * @param int $user_id
	 * @param int $export_id
	 *
	 * @return void
	 */
	public function handle_export( $user_id = 0, $export_id = 0 ) {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
		}
		WP_Filesystem();

		$db_export_row = \BWFAN_Model_Import_Export::get( $export_id );
		if ( empty( $db_export_row ) ) {
			$this->end_user_export( $user_id, $export_id, '', self::$EXPORTER_FAILED, __( 'Export data not found', 'wp-marketing-automations' ) );
		}
		$export_meta = ! empty( $db_export_row['meta'] ) ? json_decode( $db_export_row['meta'], true ) : [];

		$status = ! empty( $export_meta['status'] ) ? intval( $export_meta['status'] ) : '';
		$search = ! empty( $export_meta['search'] ) ? $export_meta['search'] : '';

		$current_pos         = absint( $db_export_row['offset'] );
		$processed_count     = absint( $db_export_row['processed'] );
		$start_time          = time();
		$file_name           = $export_meta['file'];
		$file_path           = self::$export_folder . '/' . $file_name;
		$current_total_count = $this->get_total_count( [
			'status' => $status,
			'search' => $search
		] );

		/** Initialize or load existing data */
		$existing_data = [];
		if ( file_exists( $file_path ) ) {
			$existing_data = $wp_filesystem->get_contents( $file_path );
			$existing_data = json_decode( $existing_data, true );
			$existing_data = is_array( $existing_data ) ? $existing_data : [];
		}
		$batch_limit = 10;
		while ( ( time() - $start_time ) < 30 && ! \BWFAN_Common::memory_exceeded() ) {
			$batch_automations = BWFAN_Core()->automations->get_json( '', 2, $current_pos, $batch_limit, $status, $search );
			if ( empty( $batch_automations ) ) {
				break;
			}

			$batch_data = json_decode( $batch_automations, true );
			if ( ! is_array( $batch_data ) && ! empty( $batch_data ) ) {
				break;
			}

			$existing_data   = array_merge( $existing_data, $batch_data );
			$batch_count     = count( $batch_data );
			$current_pos     += $batch_count;
			$processed_count += $batch_count;
		}
		$wp_filesystem->put_contents( $file_path, wp_json_encode( $existing_data ), FS_CHMOD_FILE );
		$this->update_export_offset( $export_id, $current_pos, $processed_count, $current_total_count );

		/** Check if export is complete */
		if ( $current_pos >= $db_export_row['count'] ) {
			$this->end_user_export( $user_id, $export_id, $file_name, self::$EXPORTER_SUCCESS, __( 'Export completed successfully', 'wp-marketing-automations' ) );
		}
	}

	public function get_total_count( $data = [] ) {
		global $wpdb;

		$query = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bwfan_automations WHERE v=%d", 2 );

		if ( isset( $data['search'] ) ) {
			$query .= $wpdb->prepare( " AND title LIKE %s", '%' . $wpdb->esc_like( $data['search'] ) . '%' );
		}
		if ( isset( $data['status'] ) && intval( $data['status'] ) > 0 ) {
			$query .= $wpdb->prepare( " AND status=%d", intval( $data['status'] ) );
		}

		return $wpdb->get_var( $query ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
	}
}

BWFAN_Core()->exporter->register_exporter( 'automation', 'BWFAN\Exporter\Automation' );
