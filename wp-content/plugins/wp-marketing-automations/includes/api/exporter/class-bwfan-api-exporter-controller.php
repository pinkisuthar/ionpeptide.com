<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Exporter_Controller extends BWFAN_API_Controller {

	public static $ins;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function default_args_values() {
		return array( 'type' => '' );
	}

	protected function register_routes() {
		// GET /export/download/ — download export file
		$this->add_route( '/export/download/', array(
			array( 'GET', 'download_export_file' ),
		) );

		// PUT /export/action — start/cancel export
		$this->add_route( '/export/action', array(
			array( 'PUT', 'export_action', array(
				'type'   => array(
					'description' => __( 'Export type', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'action' => array(
					'description' => __( 'Export action', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
			) ),
		) );

		// GET /export/status — get export status
		$this->add_route( '/export/status', array(
			array( 'GET', 'get_export_status', array(
				'type' => array(
					'description' => __( 'Export status type', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
			) ),
		) );
	}

	/**
	 * GET /export/download/ — download export file
	 */
	public function download_export_file() {
		$type    = $this->get_sanitized_arg( 'type', 'text_field' );
		$user_id = absint( $this->get_sanitized_arg( 'user_id', 'text_field' ) );

		// A user may only download their own export file.
		if ( 0 === $user_id || $user_id !== get_current_user_id() ) {
			$this->response_code = 403;

			return $this->error_response( __( 'You are not authorized to perform this action', 'wp-marketing-automations' ) );
		}

		$user_data = get_user_meta( $user_id, 'bwfan_single_export_status', true );
		if ( ! isset( $user_data[ $type ] ) || ! isset( $user_data[ $type ]['url'] ) ) {
			$this->response_code = 404;
			$response            = __( 'Unable to download the exported file', 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$filename = $user_data[ $type ]['url'];

		// Validate path to prevent directory traversal
		$allowed_dir = defined( 'BWFAN_SINGLE_EXPORT_DIR' ) ? BWFAN_SINGLE_EXPORT_DIR : wp_upload_dir()['basedir'] . '/funnelkit/fka-single-export';

		// Extract filename and sanitize
		$file_name = basename( $filename );
		$file_path = $allowed_dir . '/' . $file_name;

		// Validate path using realpath to prevent directory traversal
		$real_path    = realpath( $file_path );
		$real_allowed = realpath( $allowed_dir );

		if ( false === $real_path || false === $real_allowed || strpos( $real_path, $real_allowed ) !== 0 ) {
			$this->response_code = 403;

			return $this->error_response( __( 'Invalid file path', 'wp-marketing-automations' ) );
		}

		if ( file_exists( $real_path ) && is_readable( $real_path ) ) {
			// Define header information
			header( 'Content-Description: File Transfer' );
			header( 'Content-Type: application/octet-stream' );
			header( 'Cache-Control: no-cache, must-revalidate' );
			header( 'Expires: 0' );
			header( 'Content-Disposition: attachment; filename="' . esc_attr( basename( $real_path ) ) . '"' );
			header( 'Content-Length: ' . filesize( $real_path ) );
			header( 'Pragma: public' );

			// Clear system output buffer
			flush();

			// Read and output the file
			readfile( $real_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams the file to the response; WP_Filesystem has no streaming read
			exit;
		}
		wp_die();
	}

	/**
	 * PUT /export/action — start/cancel export
	 */
	public function export_action() {

		$this->response_code = 404;

		/** if isset type param **/
		$type       = $this->get_sanitized_arg( 'type', 'text_field' );
		$action     = $this->get_sanitized_arg( 'action', 'text_field' );
		$extra_data = ! empty( $this->args['extra_data'] ) ? $this->args['extra_data'] : [];

		if ( $type === '' || $action === '' ) {
			$response = __( 'Exporter mandatory options not passed', 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		/** @var  $exporter_registered */
		$exporter_registered = BWFAN_Core()->exporter->get_exporters();

		// check for export type registered
		if ( ! isset( $exporter_registered[ $type ] ) ) {
			$response = __( 'Exporter type is not found', 'wp-marketing-automations' );

			return $this->error_response( $response );
		}
		$action_status = [
			'status' => false,
		];
		/** Export action handler */
		switch ( $action ) {
			case 'start':
				// add user data and start scheduler
				$action_status = BWFAN_Core()->exporter->bwfan_start_export( $type, get_current_user_id(), $extra_data );
				break;
			case 'cancel':
				// remove user data and unset scheduler
				$action_status = BWFAN_Core()->exporter->bwfan_end_export( $type, get_current_user_id() );
				break;
		}

		$this->response_code = 200;

		return $this->success_response( $action_status );
	}

	/**
	 * GET /export/status — get export status
	 */
	public function get_export_status() {

		$this->response_code = 404;

		/** if isset type param **/
		$type = $this->get_sanitized_arg( 'type', 'text_field' );

		if ( $type === '' ) {
			$response = __( "Exporter type is mandatory", 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		/** @var  $exporter_registered */
		$exporter_registered = BWFAN_Core()->exporter->get_exporters();

		if ( ! isset( $exporter_registered[ $type ] ) ) {
			$response = __( "Exporter type is not found", 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$status_data = [
			'status'     => 3,
			'msg'        => [
				__( 'Unable to find the export for user', 'wp-marketing-automations' )
			],
			'percentage' => 0
		];

		$status = get_user_meta( get_current_user_id(), 'bwfan_single_export_status', true );
		if ( isset( $status[ $type ] ) ) {
			$status_data = $status[ $type ];
			if ( isset( $status_data['status'] ) && $status_data['status'] === 1 ) {
				$status_data['percentage'] = $this->get_export_percentage( $status_data['export_id'] );
			}
		}
		$this->response_code = 200;

		return $this->success_response( $status_data );
	}

	/**
	 * Get export percentage
	 *
	 * @param $export_id
	 *
	 * @return float|int
	 */
	private function get_export_percentage( $export_id ) {
		$db_export_row = \BWFAN_Model_Import_Export::get( $export_id );
		if ( empty( $db_export_row ) || empty( $db_export_row['offset'] ) || empty( $db_export_row['count'] ) ) {
			return 0;
		}

		return round( absint( $db_export_row['offset'] ) / absint( $db_export_row['count'] ) * 100, 2 );
	}
}

BWFAN_API_Exporter_Controller::get_instance();
