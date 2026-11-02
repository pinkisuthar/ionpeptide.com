<?php
defined( 'ABSPATH' ) || exit;

use BWFAN\Exporter\Base;

#[\AllowDynamicProperties]
class BWFAN_Exporter_Handler {
	/**
	 * Class instance
	 */
	private static $ins = null;

	/**
	 * Registered exporter list
	 *
	 * @var array
	 */
	private $_exporter = [];

	/**
	 * Exporter hook
	 *
	 * @var string
	 */
	private static $EXPORTER_HOOK = 'bwfan_single_export';

	/**
	 * Returns class instance
	 *
	 * @return BWFAN_Exporter_Handler|null
	 */
	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self;
		}

		return self::$ins;
	}

	/**
	 * Class constructor
	 */
	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'load_exporters' ] );
		add_action( 'wp_loaded', [ $this, 'bwfan_single_exporter_action' ], 9 );
	}

	/**
	 * Load all exporters
	 */
	public function load_exporters() {
		$exporter_dir = BWFAN_PLUGIN_DIR . '/includes/exporter';
		foreach ( glob( $exporter_dir . '/class-*.php' ) as $_field_filename ) {
			if ( ! is_readable( $_field_filename ) ) { // survive update file-swap / partial deploy
				continue;
			}
			$file_data = pathinfo( $_field_filename );
			if ( isset( $file_data['basename'] ) && 'index.php' === $file_data['basename'] ) {
				continue;
			}
			require_once( $_field_filename );
		}

		do_action( 'bwfan_exporters_loaded' );
	}

	/**
	 * Function registers exporters
	 *
	 * @param $slug
	 * @param $class
	 *
	 * @return void
	 */
	public function register_exporter( $slug, $class ) {
		if ( empty( $slug ) ) {
			return;
		}
		$this->_exporter[ $slug ] = $class;
	}

	/**
	 * Returns all registered exporters list
	 *
	 * @return array
	 */
	public function get_exporters() {
		return $this->_exporter;
	}

	/**
	 * Return exporter object by slug
	 *
	 * @param $type
	 *
	 * @return |Base|null
	 */
	public function get_exporter_by_type( $type ) {
		if ( empty( $type ) ) {
			return null;
		}
		$exporter_class = $this->_exporter[ $type ] ?? '';
		$exporter_obj   = class_exists( $exporter_class ) ? ( new $exporter_class( $type ) ) : null;


		return $exporter_obj instanceof Base ? $exporter_obj : null;
	}


	public function bwfan_single_exporter_action() {
		add_action( self::$EXPORTER_HOOK, array( $this, 'bwfan_single_export' ), 10, 3 );
	}

	/**
	 * Export scheduler action
	 *
	 * @param $type - export type
	 * @param $user_id
	 *
	 * @return void
	 */
	public function bwfan_single_export( $type, $user_id, $export_id = 0 ) {
		$exporter_obj = $this->get_exporter_by_type( $type );
		if ( $exporter_obj instanceof Base ) {
			$exporter_obj->handle_export( $user_id, $export_id );
		}
	}

	/**
	 * Start export process
	 *
	 * @param string $type
	 * @param string $user_id
	 * @param array $extra_data
	 *
	 * @return array
	 */
	public function bwfan_start_export( $type = '', $user_id = '', $extra_data = [] ) {
		if ( empty( $type ) || empty( $user_id ) || ! bwfan_is_autonami_pro_active() ) {
			return [
				'status' => false,
			];
		}

		// get export status for user
		$user_data = get_user_meta( $user_id, 'bwfan_single_export_status', true );

		if ( empty( $user_data ) ) {
			$user_data = [];
		}

		$export      = $this->get_exporter_by_type( $type );
		$export_arr  = [
			'type'      => $type,
			'user_id'   => $user_id,
			'export_id' => 0,
		];
		$export_data = [];
		if ( ( defined( 'BWFAN_PRO_VERSION' ) && version_compare( BWFAN_PRO_VERSION, '3.5.3', '>=' ) ) || $type === 'automation' ) {
			$export_data = $export->insert_data_in_table( $extra_data );
			if ( $export_data === 0 ) {
				return [
					'status' => false,
				];
			}
			$export_arr['export_id'] = $export_data['id'];
		}

		// check if already running for user
		if ( bwf_has_action_scheduled( self::$EXPORTER_HOOK, $export_arr ) ) {
			return [
				'status' => false,
			];
		}

		// schedule export action
		bwf_schedule_recurring_action( time(), 30, self::$EXPORTER_HOOK, $export_arr, 'woofunnels' );

		// Added export action to user meta data
		$user_data[ $type ] = [
			'url'       => isset( $export_data['file'] ) ? $export_data['file'] : '',
			'status'    => 1,
			'msg'       => [],
			'export_id' => isset( $export_data['id'] ) ? $export_data['id'] : 0,
		];

		// Update user meta data for export status
		update_user_meta( $user_id, 'bwfan_single_export_status', $user_data );

		BWFAN_Common::ping_woofunnels_worker();

		return [ 'status' => true, 'data' => $user_data ];
	}

	/**
	 * End export process
	 *
	 * @param $type
	 * @param $user_id
	 *
	 * @return array
	 */
	public function bwfan_end_export( $type, $user_id ) {
		// get export status for user
		$user_data = get_user_meta( $user_id, 'bwfan_single_export_status', true );

		if ( empty( $user_data ) || ! isset( $user_data[ $type ] ) ) {
			return [ 'status' => true, 'data' => $user_data ];
		}

		$export_data = $user_data[ $type ];

		$export_arr = [
			'type'      => $type,
			'user_id'   => $user_id,
			'export_id' => isset( $export_data['export_id'] ) ? $export_data['export_id'] : 0
		];

		// check if running for user so remove schedule action
		if ( bwf_has_action_scheduled( self::$EXPORTER_HOOK, $export_arr ) ) {
			$this->unschedule_export_action( $export_arr );
		}


		if ( isset( $export_data['url'] ) && is_file( $export_data['url'] ) ) {
			wp_delete_file( $export_data['url'] );
		}

		unset( $user_data[ $type ] );
		update_user_meta( $user_id, 'bwfan_single_export_status', $user_data );

		return [ 'status' => true, 'data' => $user_data ];
	}

	public function unschedule_export_action( $export_data ) {
		if ( bwf_has_action_scheduled( self::$EXPORTER_HOOK, $export_data ) ) {
			bwf_unschedule_actions( self::$EXPORTER_HOOK, $export_data, 'woofunnels' );
		}
	}
}

/**
 * Register exporter handler to BWFAN_Core
 */
if ( class_exists( 'BWFAN_Exporter_Handler' ) ) {
	BWFAN_Core::register( 'exporter', 'BWFAN_Exporter_Handler' );
}
