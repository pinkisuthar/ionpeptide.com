<?php
defined( 'ABSPATH' ) || exit;

#[\AllowDynamicProperties]
class BWFAN_API_Schedule_Db_Update extends BWFAN_API_Base {
	public static $ins;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		parent::__construct();
		$this->method = WP_REST_Server::EDITABLE;
		$this->route  = '/db-update/v3';
	}

	/**
	 * Process API call
	 *
	 * @return mixed
	 */
	public function process_api_call() {
		$status   = isset( $this->args['status'] ) ? $this->get_sanitized_arg( 'status', 'text_field', $this->args['status'] ) : 1;
		$response = $response_pro = false;

		$ins         = BWFAN_DB_Update::get_instance();
		$lite_status = $ins->get_saved_data( 'status' );

		$pro_status = $ins_pro = false;
		if ( bwfan_is_autonami_pro_active() ) {
			$ins_pro    = BWFAN_Pro_DB_Update::get_instance();
			$pro_status = $ins_pro->get_saved_data( 'status' );
		}

		switch ( intval( $status ) ) {
			case 2:
				$response     = ! empty( $lite_status ) && $ins->start_db_update();
				$response_pro = ! empty( $pro_status ) && $ins_pro->start_db_update();

				BWFAN_Common::ping_woofunnels_worker();
				break;
			case 0:
				$response     = ! empty( $lite_status ) && $ins->dismiss_db_update();
				$response_pro = ! empty( $pro_status ) && $ins_pro->dismiss_db_update();
				break;
			default:
				break;
		}

		/** Error */
		if ( ! $response && ! $response_pro ) {
			$this->response_code = 404;
			$response            = __( "Unable to schedule action.", 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		/** Success **/
		return $this->success_response( [ 'status' => true, 'lite' => $response, 'pro' => $response_pro ], '' );
	}
}

BWFAN_API_Loader::register( 'BWFAN_API_Schedule_Db_Update' );
