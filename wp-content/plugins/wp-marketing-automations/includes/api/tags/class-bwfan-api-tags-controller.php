<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Tags_Controller extends BWFAN_API_Controller {

	public static $ins;
	public $tags = array();
	public $total_count = 0;
	public $count_data = [];

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function default_args_values() {
		return array( 'ids' => '', 'search' => '', 'tag_id' => '', 'tag_name' => '', 'tags' => [], 'tag_ids' => [] );
	}

	protected function register_routes() {
		// GET+POST /v3/tags — list tags / create tag(s)
		$this->add_route( '/v3/tags', array(
			array( 'GET', 'get_tags', array(
				'search' => array(
					'description' => __( 'Search from name', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'ids'    => array(
					'description' => __( 'Search from tag ids', 'wp-marketing-automations' ),
					'type'        => 'string'
				),
			) ),
			array( 'POST', 'create_tag' ),
		) );

		// GET+PUT+DELETE /v3/tags/{id} — get/update/delete tag
		$this->add_route( '/v3/tags/(?P<tag_id>[\\d]+)', array(
			array( 'GET', 'get_tag_by_id' ),
			array( 'PUT', 'update_tag' ),
			array( 'DELETE', 'delete_tag' ),
		) );

		// GET /v3/tags/contacts — get tag contacts count
		$this->add_route( '/v3/tags/contacts', array(
			array( 'GET', 'get_tag_contacts_count' ),
		) );
	}

	/**
	 * GET /v3/tags — list tags
	 */
	public function get_tags() {
		/** if isset search param then get the tag by name **/
		$search  = $this->get_sanitized_arg( 'search', 'text_field' );
		$tag_ids = empty( $this->args['ids'] ) ? array() : explode( ',', $this->args['ids'] );
		$limit   = $this->get_sanitized_arg( 'limit', 'absint' );
		$offset  = $this->get_sanitized_arg( 'offset', 'absint' );

		$tag_data = BWFCRM_Tag::get_tags( $tag_ids, $search, $offset, $limit, ARRAY_A );
		if ( ! is_array( $tag_data ) ) {
			$tag_data = array();
		}
		$tags_count = BWFAN_Model_Terms::get_terms_count( 1, $search, $tag_ids );

		$this->total_count   = $tags_count;
		$this->tags          = $tag_data;
		$this->count_data    = BWFAN_Common::get_contact_data_counts();
		$this->response_code = 200;

		return $this->success_response( $tag_data );
	}

	/**
	 * POST /v3/tags — create tag(s)
	 */
	public function create_tag() {
		$tags = $this->get_sanitized_arg( '', 'text_field', $this->args['tags'] );
		/** IN CASE TAGS PARAMS ARE MISSING **/
		if ( empty( $tags ) ) {
			$this->response_code = 400;
			$response            = __( 'Tag is mandatory', 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$tag_data = array();
		foreach ( $tags as $key => $tag ) {
			if ( ! isset( $tag_data[ $key ] ) ) {
				$tag_data[ $key ] = [];
			}
			$tag_data[ $key ]['id']    = 0;
			$tag_data[ $key ]['value'] = $tag;
		}
		$tags = BWFCRM_Term::get_or_create_terms( $tag_data, BWFCRM_Term_Type::$TAG, true, true );
		if ( is_wp_error( $tags ) ) {
			$this->response_code = 500;

			return $this->error_response( '', $tags );
		}

		if ( ! isset( $tags['existing'] ) || ! isset( $tags['created'] ) ) {
			$this->response_code = 500;

			return $this->error_response( __( 'Some error occurred', 'wp-marketing-automations' ) );
		}

		$existing_tags = BWFCRM_Term::get_collection_array( $tags['existing'] );
		$created_tags  = BWFCRM_Term::get_collection_array( $tags['created'] );
		$all_tags      = array_merge( $existing_tags, $created_tags );

		if ( empty( $created_tags ) ) {
			return $this->success_response( $all_tags, __( 'Provided tag already exists', 'wp-marketing-automations' ) );
		}

		return $this->success_response( $all_tags, __( 'Tag created', 'wp-marketing-automations' ) );
	}

	/**
	 * GET /v3/tags/{id} — get tag by ID
	 */
	public function get_tag_by_id() {
		$tag_id = $this->get_sanitized_arg( 'tag_id', 'key' );
		if ( empty( $tag_id ) ) {
			$this->response_code = 404;
			$response            = __( 'Tag ID is missing ', 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$tag_data = BWFAN_Model_Terms::get( absint( $tag_id ) );
		if ( empty( $tag_data ) ) {
			$this->response_code = 404;
			/* translators: 1: Tag ID */
			$response = sprintf( __( 'No tag data found related with tag id: %1$d', 'wp-marketing-automations' ), $tag_id );

			return $this->error_response( $response );
		}

		$this->response_code = 200;
		/* translators: 1: Tag ID */
		$success_message = sprintf( __( 'Tag data found with tag id: %1$d', 'wp-marketing-automations' ), $tag_id );

		return $this->success_response( $tag_data, $success_message );
	}

	/**
	 * PUT /v3/tags/{id} — update tag
	 */
	public function update_tag() {
		$tag_id = $this->get_sanitized_arg( 'tag_id', 'text_field' );
		if ( empty( $tag_id ) ) {
			$this->response_code = 404;
			$response            = __( "Tag ID is missing", 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$tag_name = $this->get_sanitized_arg( 'tag_name', 'text_field' );
		if ( empty( $tag_name ) ) {
			$this->response_code = 404;
			$response            = __( "Tag name is required", 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		/** Check if tag is already exists */
		$already_exists = BWFCRM_Tag::get_terms( BWFCRM_Term_Type::$TAG, [], $tag_name, 0, 0, ARRAY_A, 'exact' );
		if ( ! empty( $already_exists ) ) {
			$this->response_code = 404;
			/* translators: 1: Tag name */
			$response = sprintf( __( 'Tag already exists with name: %1$s', 'wp-marketing-automations' ), $tag_name );

			return $this->error_response( $response );
		}

		/** Checking if the provided id is tag id or not $data */
		$check_tag = BWFCRM_Tag::get_terms( BWFCRM_Term_Type::$TAG, array( $tag_id ) );
		if ( empty( $check_tag ) ) {
			$this->response_code = 404;
			/* translators: 1: Tag ID */
			$response = sprintf( __( 'Tag doesn\'t exists with ID: %1$d', 'wp-marketing-automations' ), $tag_id );

			return $this->error_response( $response );
		}

		/** check if the passed name and db name are same then return success message */
		if ( isset( $check_tag[0]['name'] ) && $tag_name === $check_tag[0]['name'] ) {
			$this->response_code = 200;
			$response            = __( 'Tag updated', 'wp-marketing-automations' );

			return $this->success_response( $response );
		}

		$data = array(
			'name' => $tag_name,
		);

		$where = array(
			'ID' => $tag_id,
		);

		$update_tag = BWFAN_Model_Terms::update( $data, $where );
		if ( 0 === $update_tag ) {
			$response            = __( 'Unable to update tag with tag id ', 'wp-marketing-automations' ) . $tag_id;
			$this->response_code = 400;

			return $this->error_response( $response );
		}

		$response = __( 'Tag updated', 'wp-marketing-automations' );

		return $this->success_response( [], $response );
	}

	/**
	 * DELETE /v3/tags/{id} — delete tag
	 */
	public function delete_tag() {
		$tag_id = $this->get_sanitized_arg( 'tag_id', 'key' );
		if ( empty( $tag_id ) ) {
			$this->response_code = 404;
			$response            = __( 'Tag ID is missing', 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		/** Checking if the provided id is tag id or not $data */
		$check_tag = BWFCRM_Tag::get_terms( BWFCRM_Term_Type::$TAG, array( $tag_id ) );
		if ( empty( $check_tag ) ) {
			$this->response_code = 404;
			/* translators: 1: Tag ID */
			$response = sprintf( __( 'Tag not exist with given ID #%1$d', 'wp-marketing-automations' ), $tag_id );

			return $this->error_response( $response );
		}

		$delete_tag = BWFCRM_Tag::delete_tag( absint( $tag_id ) );
		if ( false === $delete_tag ) {
			$this->response_code = 404;

			$response = __( 'Unable to delete the tag', 'wp-marketing-automations' );

			return $this->error_response( $response );
		}

		$this->response_code = 200;
		$success_message     = __( 'Tag deleted', 'wp-marketing-automations' );

		return $this->success_response( [], $success_message );
	}

	/**
	 * GET /v3/tags/contacts — get tag contacts count
	 */
	public function get_tag_contacts_count() {
		$tag_ids = $this->get_sanitized_arg( '', 'text_field', $this->args['tag_ids'] );

		$this->response_code = 200;

		/** In case empty **/
		if ( empty( $tag_ids ) || ! is_array( $tag_ids ) ) {
			return $this->success_response( [] );
		}

		$data = [];
		foreach ( $tag_ids as $tag_id ) {
			if ( ! isset( $data[ $tag_id ] ) ) {
				$data[ $tag_id ] = [];
			}

			$count_data                           = $this->get_contact_count( $tag_id );
			$data[ $tag_id ]['contact_count']     = $count_data['count'];
			$data[ $tag_id ]['customer_count']    = $count_data['customers'];
			$data[ $tag_id ]['total_revenue']     = $count_data['revenue'];
			$data[ $tag_id ]['subscribers_count'] = $this->get_contact_count( $tag_id, true );
		}

		return $this->success_response( $data );
	}

	/**
	 * @param $tag_id
	 * @param $exclude_unsubs
	 *
	 * @return array|int|int[]
	 */
	public function get_contact_count( $tag_id, $exclude_unsubs = false ) {
		global $wpdb;
		$tag_id = '%"' . $wpdb->esc_like( $tag_id ) . '"%';
		if ( true === $exclude_unsubs ) {
			$query = "SELECT COUNT(DISTINCT c.id) FROM {$wpdb->prefix}bwf_contact as c   WHERE 1=1 AND ( c.email != '' AND c.email IS NOT NULL ) AND ( c.tags LIKE %s  )";
			$query .= " AND ( c.status = 1 )    AND (   NOT EXISTS   (SELECT 1 FROM {$wpdb->prefix}bwfan_message_unsubscribe AS unsub WHERE c.email = unsub.recipient ) AND   NOT EXISTS  (SELECT 1 FROM {$wpdb->prefix}bwfan_message_unsubscribe AS unsub1 WHERE c.contact_no = unsub1.recipient ))";

			return intval( $wpdb->get_var( $wpdb->prepare( $query, $tag_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL w/ $wpdb->prefix tables; tag_id bound via %s
		}
		$query     = "SELECT COUNT(DISTINCT c.`id`) AS contacts, COUNT(DISTINCT wc.`id`) AS customers, SUM( wc.`total_order_value`) AS total_revenue  FROM {$wpdb->prefix}bwf_contact as c LEFT JOIN {$wpdb->prefix}bwf_wc_customers AS wc ON c.`id`=wc.`cid` WHERE 1=1 AND ( c.email != '' AND c.email IS NOT NULL ) AND ( c.tags LIKE %s  )";
		$customers = $wpdb->get_row( $wpdb->prepare( $query, $tag_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- hardcoded SQL w/ $wpdb->prefix tables; tag_id bound via %s
		if ( ! empty( $customers ) ) {
			return [
				'count'     => $customers['contacts'],
				'customers' => $customers['customers'],
				'revenue'   => $customers['total_revenue'] ?? 0,
			];
		}

		return [
			'count'     => 0,
			'customers' => 0,
			'revenue'   => 0,
		];
	}

	public function get_result_total_count() {
		return $this->total_count;
	}

	public function get_result_count_data() {
		return $this->count_data;
	}
}

BWFAN_API_Tags_Controller::get_instance();
