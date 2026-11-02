<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Email_Activity_Controller extends BWFAN_API_Controller {

	public static $ins;
	public $total_count = 0;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		$this->pagination = new stdClass();
		$this->pagination->offset = 0;
		$this->pagination->limit  = 20;
		parent::__construct();
	}

	public function default_args_values() {
		return array();
	}

	protected function register_routes() {
		// GET /v3/email-activity — list email activity
		$this->add_route( '/v3/email-activity', array(
			array( 'GET', 'get_email_activity' ),
		) );

		// GET /engagement/{id} — get engagement details
		$this->add_route( '/engagement/(?P<e_id>[\\d]+)', array(
			array( 'GET', 'get_engagement_details' ),
		) );
	}

	/**
	 * GET /v3/email-activity — list email activity
	 */
	public function get_email_activity() {

		$offset        = $this->pagination->offset;
		$limit         = $this->pagination->limit;
		$search        = isset( $this->args['search'] ) ? $this->args['search'] : '';
		$filter_list   = isset( $this->args['filter_list'] ) ? $this->args['filter_list'] : '';
		$filters       = isset( $this->args['filters'] ) ? $this->args['filters'] : [];
		$filters       = BWFAN_Common::is_json( $filters ) ? json_decode( $filters, true ) : [];
		$activity_data = BWFAN_Model_Engagement_Tracking::get_engagements_activity( $search, $filters, $offset, $limit, 1 );

		$this->total_count = $activity_data['total'];

		$response = [
			'data' => $activity_data['data']
		];

		if ( $filter_list ) {
			$response['filter_list'] = self::email_activity_filter_array( $filter_list );
		}

		return $this->success_response( $response );
	}

	/**
	 * GET /engagement/{id} — get engagement details
	 */
	public function get_engagement_details() {
		$e_id       = $this->get_sanitized_arg( 'e_id' );
		$engagement = BWFAN_Core()->conversations->get_conversation_email( $e_id );
		if ( empty( $engagement ) || ! is_array( $engagement ) ) {
			$message = 'Unknown error occurred';

			if ( is_wp_error( $engagement ) ) {
				$message = $engagement->get_error_message();
			} elseif ( is_array( $engagement ) && isset( $engagement['error'] ) ) {
				$message = $engagement['error'];
			}

			return $this->error_response( $message, null, 500 );
		}

		return $this->success_response( $engagement );
	}

	public function get_result_total_count() {
		return $this->total_count;
	}

	/**
	 * Generates and returns a structured filter array for email activity.
	 *
	 * @param $filter_list
	 *
	 * @return array
	 */
	public static function email_activity_filter_array( $filter_list ) {
		if ( empty( $filter_list ) ) {
			return array();
		}
		$operators = [
			1 => __( 'Automation', 'wp-marketing-automations' ),
		];
		if ( bwfan_is_autonami_pro_active() ) {
			$operators[2] = __( 'Broadcast', 'wp-marketing-automations' );
			$operators[6] = __( 'Form Feeds', 'wp-marketing-automations' );
			$operators[9] = __( 'Transactional', 'wp-marketing-automations' );
		}

		return [
			[
				'type'  => 'sticky',
				'rules' => [
					[
						'slug'           => 'source',
						'title'          => __( 'Source', 'wp-marketing-automations' ),
						'operators'      => $operators,
						'op_label'       => __( 'Source', 'wp-marketing-automations' ),
						'type'           => 'search',
						'val_label'      => __( 'Source', 'wp-marketing-automations' ),
						'required'       => [ 'rule' ],
						'readable_text'  => '{{rule /}} - {{value /}}',
						'rule_dependent' => true,
						'api'            => [
							1 => '/search/automations?search={{search}}&version=2',
							2 => '/analytics/engagements/search?type=2&search={{search}}'
						],
						'data_toggler'   => [
							'operator' => '===',
							'value'    => [ 1, 2, '1', '2' ]
						],
					],
					[
						'slug'          => 'status',
						'title'         => __( 'Status', 'wp-marketing-automations' ),
						'type'          => 'select',
						'options'       => [
							2 => __( 'Completed', 'wp-marketing-automations' ),
							3 => __( 'Failed', 'wp-marketing-automations' ),
							4 => __( 'Bounced', 'wp-marketing-automations' ),
						],
						'val_label'     => __( 'Status', 'wp-marketing-automations' ),
						'required'      => [ 'data' ],
						'readable_text' => '{{value /}}',
					],
					[
						'slug'          => 'period',
						'title'         => __( 'Time Period', 'wp-marketing-automations' ),
						'type'          => 'date-range',
						'op_label'      => __( 'Time Period', 'wp-marketing-automations' ),
						'required'      => [ 'rule', 'data' ],
						'readable_text' => '{{value /}}',
						'returnPeriod'  => true,
					],
				],
			],
		];
	}
}

BWFAN_API_Email_Activity_Controller::get_instance();
