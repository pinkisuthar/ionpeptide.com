<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_WC_Controller extends BWFAN_API_Controller {

	public static $ins;
	public $products = array();
	public $total_count = 0;
	public $count_data = [];
	protected $stock_status = '';
	protected array $categories = [];
	protected array $p_ids = [];

	protected $category_in = [];
	protected $category_not_in = [];

	protected $exclude_ids = [];

	protected $sortby = '';
	protected $serach_title = '';

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function default_args_values() {
		return array( 'ids' => '', 'search' => '' );
	}

	protected function register_routes() {
		// GET /autonami/coupons/ — get automation dynamic coupons
		$this->add_route( '/autonami/coupons/', array(
			array( 'GET', 'get_automation_dynamic_coupons', array(
				'search' => array(
					'description' => __( 'Search from name', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
			) ),
		) );

		// GET /bwf-products — get products
		$this->add_route( '/bwf-products', array(
			array( 'GET', 'get_bwf_products', array(
				'search' => array(
					'description' => __( 'Search from name', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
			) ),
		) );

		// GET /wc-category/ — get WC categories
		$this->add_route( '/wc-category/', array(
			array( 'GET', 'get_wc_categories', array(
				'search' => array(
					'description' => __( 'Search from name', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
				'ids'    => array(
					'description' => __( 'Comma seperated ids to search for', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
			) ),
		) );

		// GET /wc-coupons/ — get WC coupons
		$this->add_route( '/wc-coupons/', array(
			array( 'GET', 'get_wc_coupons', array(
				'search' => array(
					'description' => __( 'Search from name', 'wp-marketing-automations' ),
					'type'        => 'string',
				),
			) ),
		) );
	}

	/**
	 * GET /autonami/coupons/ — get automation dynamic coupons
	 */
	public function get_automation_dynamic_coupons() {
		// check if woocommerce is active
		if ( ! bwfan_is_woocommerce_active() ) {
			return $this->error_response( __( 'WooCommerce is not active', 'wp-marketing-automations' ), null, 400 );
		}
		$automation_id = ! empty( $this->get_sanitized_arg( 'id', 'text_field' ) ) ? $this->get_sanitized_arg( 'id', 'text_field' ) : '';
		$coupons       = [];
		if ( ! empty( $automation_id ) && intval( $automation_id ) > 0 ) {
			$coupons = $this->get_dynamic_coupons( $automation_id );
		}

		$this->response_code = 200;

		return $this->success_response( $coupons, count( $coupons ) > 0 ? __( 'Successfully fetched coupons', 'wp-marketing-automations' ) : __( 'No coupon found.', 'wp-marketing-automations' ) );
	}

	/**
	 * Get Dynamic Coupons
	 *
	 * @since 1.0.0
	 */
	public function get_dynamic_coupons( $automationId ) {
		global $wpdb;

		/** To get automation step with action create coupon and status is 1 */
		$query   = $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bwfan_automation_step WHERE `aid` = %d AND `action` LIKE %s AND `status` = '1'", intval( $automationId ), '%' . $wpdb->esc_like( 'wc_create_coupon' ) . '%' );
		$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() on previous line

		/** Check for empty step */
		if ( empty( $results ) ) {
			return [];
		}

		$finalarr = [];

		/** @var  $automation_obj */
		$automation_obj = BWFAN_Automation_V2::get_instance( $automationId );

		/** Get automation meta data */
		$automation_data = $automation_obj->get_automation_meta_data();
		$mapped_arr      = [];

		/** Form  mapped array with step id and node id */
		foreach ( $automation_data['steps'] as $step ) {
			if ( isset( $step['stepId'] ) ) {
				$mapped_arr[ $step['stepId'] ] = $step['id'];
			}
		}

		/** Iterating over resulting steps */
		foreach ( $results as $data ) {
			$stepid    = $data['ID'];
			$step_data = ( array ) json_decode( $data['data'], true );

			/** Checking for title in coupon sidebar data */
			if ( isset( $step_data['sidebarData'] ) && isset( $step_data['sidebarData']['coupon_data'] ) && isset( $step_data['sidebarData']['coupon_data']['general'] ) && isset( $step_data['sidebarData']['coupon_data']['general']['title'] ) && ! empty( $step_data['sidebarData']['coupon_data']['general']['title'] ) ) {
				$coupon_title = $step_data['sidebarData']['coupon_data']['general']['title'] . ' ( #' . ( ! empty( $mapped_arr ) && isset( $mapped_arr[ $stepid ] ) ? $mapped_arr[ $stepid ] : $stepid ) . ' )';
			} else {
				$coupon_title = '#' . ( ! empty( $mapped_arr ) && isset( $mapped_arr[ $stepid ] ) ? $mapped_arr[ $stepid ] : $stepid );
			}

			$finalarr[] = [
				'key'   => '{{wc_dynamic_coupon id="' . $stepid . '"}}',
				'value' => $coupon_title,
			];
		}

		return $finalarr;
	}

	/**
	 * GET /bwf-products — get products
	 */
	public function get_bwf_products() {
		// check if woocommerce is active
		if ( ! bwfan_is_woocommerce_active() ) {
			return $this->error_response( __( 'WooCommerce is not active', 'wp-marketing-automations' ), null, 400 );
		}

		/** if isset search param then get the tag by name **/
		$search          = $this->get_sanitized_arg( 'search', 'text_field' );
		$type            = $this->get_sanitized_arg( 'type', 'text_field' );
		$limit           = $this->get_sanitized_arg( 'limit', 'absint' );
		$page            = $this->get_sanitized_arg( 'page', 'absint' );
		$stock_status    = $this->get_sanitized_arg( 'stock_status', 'text_field' );
		$category_in     = $this->args['category_in'] ?? [];
		$category_not_in = $this->args['category_not_in'] ?? [];
		$ids             = $this->args['ids'] ?? [];
		$sortby          = $this->get_sanitized_arg( 'sortby', 'text_field' );
		$exclude_ids     = $this->args['exclude_ids'] ?? [];

		if ( ! empty( $search ) ) {
			$this->serach_title = $search;
		}
		if ( ! empty( $category_in ) ) {
			if ( is_array( $category_in ) ) {
				$this->category_in = array_filter( array_map( 'intval', $category_in ) );
			} else {
				$this->category_in = [ intval( $category_in ) ];
			}
		}
		if ( ! empty( $category_not_in ) ) {
			if ( is_array( $category_not_in ) ) {
				$this->category_not_in = array_filter( array_map( 'intval', $category_not_in ) );;
			} else {
				$this->category_not_in = [ intval( $category_not_in ) ];
			}
		}

		if ( ! empty( $exclude_ids ) ) {
			if ( is_array( $exclude_ids ) ) {
				$this->exclude_ids = array_filter( array_map( 'intval', $exclude_ids ) );
			} else {
				$this->exclude_ids = [ intval( $exclude_ids ) ];
			}
		}

		if ( ! empty( $sortby ) ) {
			$this->sortby = $sortby;
		}

		if ( ! empty( $stock_status ) ) {
			$this->stock_status = $stock_status;
		}

		if ( ! empty( $ids ) ) {
			$this->p_ids = is_array( $ids )
				? array_filter( array_map( 'intval', $ids ) )
				: array_filter( array_map( 'intval', explode( ',', $ids ) ) );
		}

		$result            = $this->get_data( $type, $limit, $page );
		$products          = $result['products'];
		$this->total_count = $result['total'];

		$this->response_code = 200;

		return $this->success_response( $products, count( $products ) > 0 ? __( 'Successfully fetched products', 'wp-marketing-automations' ) : __( 'No products found.', 'wp-marketing-automations' ) );
	}

	/**
	 * Get product data by type, limit and page
	 *
	 * @param $type
	 * @param $limit
	 * @param $page
	 *
	 * @return array
	 */
	public function get_data( $type = '', $limit = 10, $page = 1 ) {
		switch ( $type ) {
			case 'on_sale':
				$products = $this->get_sale_products( $limit, $page );
				break;
			case 'featured':
				$products = $this->get_featured_products( $limit, $page );
				break;
			case 'top_rated':
				$products = $this->get_top_rated_products( $limit, $page );
				break;
			case 'best_selling':
			case 'best_selling_store':
				$products = $this->get_best_selling_product( $limit, $page );
				break;
			case 'latest':
				$products = $this->get_recent_products( $limit, $page );
				break;
			case 'category':
				$products = $this->get_products_by_category( $limit, $page );
				break;
			default:
				$products = $this->get_products( $limit, $page );
		}

		return $products;
	}

	/**
	 * Returns best-selling products
	 *
	 * @param $limit
	 * @param $page
	 *
	 * @return array
	 */
	public function get_best_selling_product( $limit, $page ) {
		$param = array(
			'post_type'      => 'product',
			'meta_key'       => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WooCommerce product catalog query for email product blocks; same query WooCommerce runs, limited
			'orderby'        => 'meta_value_num',
			'posts_per_page' => $limit,
			'paged'          => $page,
			'fields'         => 'ids'
		);

		return $this->get_formatted_product_data( $param );
	}

	/**
	 * Returns products by category
	 *
	 * @param $limit
	 * @param $page
	 *
	 * @return array
	 */
	public function get_products_by_category( $limit, $page ) {
		$param = array(
			'post_type'      => 'product',
			'posts_per_page' => $limit,
			'paged'          => $page,
			'fields'         => 'ids'
		);

		if ( ! empty( $this->category_in ) ) {
			$param['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- WooCommerce product catalog query for email product blocks; same query WooCommerce runs, limited
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $this->category_in,
					'operator' => 'IN',
				]
			];
		}

		if ( ! empty( $this->category_not_in ) ) {
			$param['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- WooCommerce product catalog query for email product blocks; same query WooCommerce runs, limited
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $this->category_not_in,
					'operator' => 'NOT IN',
				]
			];
		}

		return $this->get_formatted_product_data( $param );
	}

	/**
	 * Returns on sale products
	 *
	 * @param $limit
	 * @param $page
	 *
	 * @return array
	 */
	public function get_sale_products( $limit, $page ) {
		$param = array(
			'post_type'      => array( 'product', 'product_variation' ),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- WooCommerce product catalog query for email product blocks; same query WooCommerce runs, limited
				'relation' => 'OR',
				array( // Simple products type
					'key'     => '_sale_price',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'numeric'
				),
				array( // Variable products type
					'key'     => '_min_variation_sale_price',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'numeric'
				)
			),
			'posts_per_page' => $limit,
			'paged'          => $page,
			'fields'         => 'ids'
		);

		return $this->get_formatted_product_data( $param );
	}

	/**
	 * Returns featured products
	 *
	 * @param $limit
	 * @param $page
	 *
	 * @return array
	 */
	public function get_featured_products( $limit, $page ) {
		$tax_query[] = array(
			'taxonomy' => 'product_visibility',
			'field'    => 'name',
			'terms'    => 'featured',
			'operator' => 'IN', // or 'NOT IN' to exclude feature products
		);
		$param       = array(
			'post_type'      => 'product',
			'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- WooCommerce product catalog query for email product blocks; same query WooCommerce runs, limited
			'posts_per_page' => $limit,
			'paged'          => $page,
			'fields'         => 'ids',
		);

		return $this->get_formatted_product_data( $param );
	}

	/**
	 * Returns recent added products
	 *
	 * @param $limit
	 * @param $page
	 *
	 * @return array
	 */
	public function get_recent_products( $limit, $page ) {
		$param = array(
			'post_type'      => 'product',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'posts_per_page' => $limit,
			'paged'          => $page,
			'fields'         => 'ids'
		);

		return $this->get_formatted_product_data( $param );
	}

	/**
	 * Returns top-rated products
	 *
	 * @param $limit
	 * @param $page
	 *
	 * @return array
	 */
	public function get_top_rated_products( $limit, $page ) {
		$param = array(
			'post_type'      => 'product',
			'meta_key'       => '_wc_average_rating', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WooCommerce product catalog query for email product blocks; same query WooCommerce runs, limited
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			'meta_query'     => WC()->query->get_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- WooCommerce product catalog query for email product blocks; same query WooCommerce runs, limited
			'tax_query'      => WC()->query->get_tax_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- WooCommerce product catalog query for email product blocks; same query WooCommerce runs, limited
			'posts_per_page' => $limit,
			'paged'          => $page,
			'fields'         => 'ids'
		);

		return $this->get_formatted_product_data( $param );
	}

	/**
	 * Return all products
	 *
	 * @param $limit
	 * @param $page
	 *
	 * @return array
	 */
	public function get_products( $limit, $page ) {
		$param = array(
			'post_type'      => 'product',
			'posts_per_page' => $limit,
			'paged'          => $page,
			'fields'         => 'ids'
		);

		return $this->get_formatted_product_data( $param );
	}

	/**
	 * Add stock status query to argument for WP_Query
	 *
	 * @param $args
	 *
	 * @return array
	 */
	public function append_stock_query_args( $args ) {
		$filter_key = '';
		switch ( $this->stock_status ) {
			case 'instock':
				$filter_key = 'instock';
				break;
			case 'outofstock':
				$filter_key = 'outofstock';
				break;
			case 'backorder':
				$filter_key = 'onbackorder';
				break;
		}

		if ( ! empty( $filter_key ) ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- WooCommerce product catalog query for email product blocks; same query WooCommerce runs, limited
				[
					'key'     => '_stock_status',
					'value'   => $filter_key,
					'compare' => '=',
				]
			];
		}

		return $args;
	}

	/**
	 * Returns formatted product data after fetching
	 *
	 * @param $data
	 *
	 * @return array
	 */
	public function get_formatted_product_data( $data ) {
		$products = [];
		$currency = get_woocommerce_currency_symbol();

		/** Add category argument **/
		if ( ! empty( $this->categories ) ) {
			$data['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- WooCommerce product catalog query for email product blocks; same query WooCommerce runs, limited
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => implode( ',', $this->categories ),
					'operator' => 'IN',
				]
			];
		}

		/** Add stock status argument **/
		if ( ! empty( $this->stock_status ) ) {
			$data = $this->append_stock_query_args( $data );
		}

		/** Add search argument **/
		if ( ! empty( $this->serach_title ) ) {
			$data['s'] = $this->serach_title;
		}

		/** Add post__in argument **/
		if ( ! empty( $this->p_ids ) ) {
			$data['posts_per_page'] = 20;
			$data['post__in']       = $this->p_ids;
		}

		/** Add exclude_ids argument **/
		if ( ! empty( $this->exclude_ids ) ) {
			$data['post__not_in'] = $this->exclude_ids; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- exclude_ids is the short list of products already picked in the search field
		}

		/** Add sort by argument **/
		if ( ! empty( $this->sortby ) ) {
			$data = $this->get_sort_arg( $data );
		}

		$data['post_status'] = 'publish';

		$wp_query    = new WP_Query( $data );
		$product_ids = $wp_query->get_posts();
		foreach ( $product_ids as $pid ) {
			$product    = wc_get_product( $pid );
			$products[] = [
				'id'                => $product->get_id(),
				'name'              => wp_strip_all_tags( $product->get_name() ),
				'short_description' => wp_strip_all_tags( $product->get_short_description() ),
				'type'              => $product->get_type(),
				'status'            => $product->get_status(),
				'downloadable'      => $product->is_downloadable(),
				'virtual'           => $product->is_virtual(),
				'product_url'       => $product->get_permalink(),
				'sku'               => $product->get_sku(),
				'price'             => wc_format_decimal( $product->get_price(), 2 ),
				'regular_price'     => wc_format_decimal( $product->get_regular_price(), 2 ),
				'sale_price'        => $product->get_sale_price() ? wc_format_decimal( $product->get_sale_price(), 2 ) : '',
				'parent_id'         => $product->get_parent_id(),
				'image'             => wp_get_attachment_image_url( get_post_thumbnail_id( $product->get_id() ), 'single-post-thumbnail' ),
				'price_html'        => $product->get_price_html(),
				'stock_status'      => $product->is_in_stock(),
				'currency_prefix'   => html_entity_decode( $currency, ENT_QUOTES | ENT_HTML401 )
			];
		}

		return [
			'products' => $products,
			'total'    => $wp_query->found_posts
		];
	}

	/**
	 * Get sort argument
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function get_sort_arg( $args = [] ) {
		switch ( $this->sortby ) {
			case 'created':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
			case 'modified':
				$args['orderby'] = 'modified';
				$args['order']   = 'DESC';
				break;
			case 'sales':
				$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WooCommerce product catalog query for email product blocks; same query WooCommerce runs, limited
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				break;
			case 'lowest_price':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WooCommerce product catalog query for email product blocks; same query WooCommerce runs, limited
				$args['order']    = 'ASC';
				break;
			case 'highest_price':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WooCommerce product catalog query for email product blocks; same query WooCommerce runs, limited
				$args['order']    = 'DESC';
				break;
			case 'random':
				$args['orderby'] = 'rand';
				$args['order']   = 'DESC';
		}

		if ( empty( $args['post__in'] ) ) {
			unset( $args['post__in'] );
		}

		return $args;
	}

	/**
	 * GET /wc-category/ — get WC categories
	 */
	public function get_wc_categories() {
		// check if woocommerce is active
		if ( ! bwfan_is_woocommerce_active() ) {
			return $this->error_response( __( 'WooCommerce is not active', 'wp-marketing-automations' ), null, 400 );
		}
		$search        = ! empty( $this->get_sanitized_arg( 'search', 'text_field' ) ) ? $this->get_sanitized_arg( 'search', 'text_field' ) : '';
		$ids           = $this->get_sanitized_arg( 'ids', 'text_field' );
		$category_list = $this->get_all_categories( $search, $ids );

		$this->response_code = 200;

		return $this->success_response( $category_list, count( $category_list ) > 0 ? __( 'Successfully fetched categories', 'wp-marketing-automations' ) : __( 'No category found.', 'wp-marketing-automations' ) );
	}

	/**
	 * Get All Categories
	 *
	 * @since 1.0.0
	 */
	public function get_all_categories( $search, $ids ) {
		$param = [
			'taxonomy' => 'product_cat',
			'offset'   => 0,
			'search'   => $search,
		];

		if ( ! empty( $ids ) ) {
			$ids = array_filter( array_map( 'intval', explode( ',', $ids ) ) );
			if ( ! empty( $ids ) ) {
				$param['include'] = $ids;
			}
		} else {
			$param['number'] = 10;
		}

		$categories = get_terms( $param );

		$category_data = [];

		foreach ( $categories as $category ) {
			if ( ! $category instanceof WP_Term ) {
				continue;
			}
			$category_data[] = [
				'key'   => $category->term_id,
				'value' => $category->name,
			];
		}

		return $category_data;
	}

	/**
	 * GET /wc-coupons/ — get WC coupons
	 */
	public function get_wc_coupons() {
		// check if woocommerce is active
		if ( ! bwfan_is_woocommerce_active() ) {
			return $this->error_response( __( 'WooCommerce is not active', 'wp-marketing-automations' ), null, 400 );
		}
		$search           = ! empty( $this->get_sanitized_arg( 'search', 'text_field' ) ) ? $this->get_sanitized_arg( 'search', 'text_field' ) : '';
		$exclude_autonami = ! empty( $this->get_sanitized_arg( 'exclude_autonami', 'bool' ) ) ? $this->get_sanitized_arg( 'exclude_autonami', 'bool' ) : true;
		$coupons          = $this->get_all_coupons( $search, $exclude_autonami );

		$this->response_code = 200;

		return $this->success_response( $coupons, count( $coupons ) > 0 ? __( 'Successfully fetched coupons', 'wp-marketing-automations' ) : __( 'No coupon found.', 'wp-marketing-automations' ) );
	}

	/**
	 * Get all WC coupons
	 *
	 * @param $search
	 * @param $exclude_autonami
	 *
	 * @return array
	 * @throws Exception
	 */
	public function get_all_coupons( $search, $exclude_autonami = false ) {
		$args = array(
			'posts_per_page' => 10,
			'orderby'        => 'name',
			'order'          => 'asc',
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			's'              => $search
		);

		if ( $exclude_autonami ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin coupon search on shop_coupon posts; small table, limited
				[
					'key'     => '_is_bwfan_coupon',
					'compare' => 'NOT EXISTS', // this will exclude the coupons where _is_bwfan_coupon meta key exists
				]
			];
		}

		$coupon_posts = get_posts( $args );

		$coupon_data = [];
		foreach ( $coupon_posts as $coupon_post ) {
			$expiry      = false;
			$coupon      = new WC_Coupon( $coupon_post->ID );
			$expiry_date = $coupon->get_date_expires();
			if ( $expiry_date ) {
				$timezone        = $coupon->get_date_expires()->getTimezone(); // get timezone
				$expiry_datetime = new WC_DateTime( $expiry_date->date( 'Y-m-d' ) );
				$now_datetime    = new WC_DateTime();

				$expiry_datetime->setTimezone( $timezone );
				$now_datetime->setTimezone( $timezone );

				$expiry = $now_datetime->getTimestamp() > $expiry_datetime->getTimestamp();
			}
			$coupon_data[] = [
				'key'     => $coupon_post->post_title,
				'value'   => $coupon_post->ID,
				'expired' => $expiry,
			];
		}

		return $coupon_data;
	}

	/**
	 * Get Result Count Data
	 *
	 * @since 1.0.0
	 */
	public function get_result_count_data() {
		return $this->count_data;
	}
}

BWFAN_API_WC_Controller::get_instance();
