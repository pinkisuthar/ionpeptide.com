<?php
defined( 'ABSPATH' ) || exit;

#[\AllowDynamicProperties]
class BWFAN_Api_Get_WP_User extends BWFAN_API_Base {

	public static $ins;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		parent::__construct();
		$this->method       = WP_REST_Server::READABLE;
		$this->route        = '/wp-users';
		$this->request_args = array(
			'search' => array(
				'description' => __( 'Search from name', 'wp-marketing-automations' ),
				'type'        => 'string',
			),
		);

	}

	public function process_api_call() {
		$search = isset( $this->args['search'] ) ? $this->get_sanitized_arg( 'search', 'text_field' ) : '';
		$limit  = isset( $this->args['limit'] ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 10;
		$data   = $this->get_users( $search, $limit );

		$this->response_code = 200;

		return $this->success_response( $data );
	}

	/**
	 * Get all WC coupons
	 *
	 * @return array
	 */
	public function get_users( $search, $limit = 10 ) {
		$user_data = get_users( array(
			'number'  => $limit,
			'orderby' => 'name',
			'order'   => 'asc',
			'search'  => '*' . esc_attr( $search ) . '*',
			'fields'  => [
				'display_name',
				'user_nicename',
				'user_email',
				'ID'
			]
		) );

		if ( empty( $user_data ) ) {
			return [];
		}
		$data = [];
		foreach ( $user_data as $user ) {
			$data[] = [
				'display_name' => $user->user_nicename,
				'name'         => $user->display_name,
				'email'        => $user->user_email,
				'id'           => $user->id
			];
		}

		return $data;
	}

}

BWFAN_API_Loader::register( 'BWFAN_Api_Get_WP_User' );