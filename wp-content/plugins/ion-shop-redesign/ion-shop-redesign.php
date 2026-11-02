<?php
/**
 * Plugin Name: Ion Shop Redesign
 * Plugin URI: https://buildrightweb.com/
 * Description: Replaces the Ion Peptide WooCommerce shop archive with a modern catalog layout, live filtering, category dropdown, sorting, product catalog sidebar, and support CTA.
 * Version: 1.0.26
 * Author: BuildRightWeb
 * Author URI: https://buildrightweb.com/
 * Text Domain: ion-shop-redesign
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ion_Shop_Redesign {
	const VERSION = '1.0.26';
	const NONCE_ACTION = 'ion_shop_redesign_nonce_action';
	const NONCE_NAME = 'ion_shop_redesign_nonce';

	private static $instance = null;
	private static $media_catalog_rebuild_queued = false;
	private $shortcode_rendering = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
	}

	public function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		add_filter( 'template_include', array( $this, 'template_override' ), 99 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 100 );
		add_action( 'wp_footer', array( $this, 'render_live_image_guard' ), 999 );
		add_shortcode( 'ion_shop_redesign', array( $this, 'shortcode' ) );

		add_action( 'wp_ajax_ion_shop_redesign_filter', array( $this, 'ajax_filter' ) );
		add_action( 'wp_ajax_nopriv_ion_shop_redesign_filter', array( $this, 'ajax_filter' ) );
		add_action( 'wp_ajax_ion_shop_redesign_yith_page', array( $this, 'yith_page_fragment' ) );
		add_action( 'wp_ajax_nopriv_ion_shop_redesign_yith_page', array( $this, 'yith_page_fragment' ) );
		add_filter( 'woocommerce_product_is_in_stock', array( $this, 'correct_variable_parent_stock_status' ), 20, 2 );

		/*
		 * Keep one tiny static shop-image catalog current. Frontend visitors read
		 * this JSON file directly from /uploads; no AJAX, REST, PHP, or database
		 * request is added when the shop loads.
		 */
		add_action( 'admin_init', array( $this, 'maybe_rebuild_media_catalog' ), 1 );
		add_action( 'save_post_product', array( $this, 'product_media_changed' ), 100, 3 );
		add_action( 'added_post_meta', array( $this, 'media_meta_changed' ), 100, 4 );
		add_action( 'updated_post_meta', array( $this, 'media_meta_changed' ), 100, 4 );
		add_action( 'deleted_post_meta', array( $this, 'media_meta_changed' ), 100, 4 );
		add_action( 'trashed_post', array( $this, 'product_status_changed' ), 100, 2 );
		add_action( 'untrashed_post', array( $this, 'product_status_changed' ), 100, 2 );
	}

	public function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p><strong>Ion Shop Redesign:</strong> WooCommerce must be active for this plugin to work.</p>
		</div>
		<?php
	}

	public function is_shop_context() {
		if ( is_admin() ) {
			return false;
		}

		return function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() );
	}

	public function template_override( $template ) {
		if ( ! $this->is_shop_context() ) {
			return $template;
		}

		$should_override = apply_filters( 'ion_shop_redesign_override_archive', true );
		if ( ! $should_override ) {
			return $template;
		}

		$plugin_template = plugin_dir_path( __FILE__ ) . 'templates/archive-product.php';
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $template;
	}

	public function enqueue_assets() {
		if ( ! $this->is_shop_context() && ! is_singular() ) {
			return;
		}

		$needs_assets = $this->is_shop_context();
		if ( ! $needs_assets && is_singular() ) {
			$post = get_post();
			$needs_assets = $post && has_shortcode( $post->post_content, 'ion_shop_redesign' );
		}

		if ( ! $needs_assets ) {
			return;
		}

		$css_path    = plugin_dir_path( __FILE__ ) . 'assets/ion-shop-redesign.css';
		$js_path     = plugin_dir_path( __FILE__ ) . 'assets/ion-shop-redesign.js';
		$css_version = file_exists( $css_path ) ? (string) filemtime( $css_path ) : self::VERSION;
		$js_version  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : self::VERSION;

		wp_enqueue_style(
			'ion-shop-redesign',
			plugin_dir_url( __FILE__ ) . 'assets/ion-shop-redesign.css',
			array(),
			$css_version
		);

		wp_enqueue_script(
			'ion-shop-redesign',
			plugin_dir_url( __FILE__ ) . 'assets/ion-shop-redesign.js',
			array( 'jquery' ),
			$js_version,
			true
		);

		$media_catalog = $this->media_catalog_location();

		wp_localize_script(
			'ion-shop-redesign',
			'IonShopRedesign',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
				'shopUrl'         => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ),
				'loading'         => __( 'Loading products…', 'ion-shop-redesign' ),
				'noResults'       => __( 'No products found.', 'ion-shop-redesign' ),
				'yithOptions'     => $this->get_yith_infinite_scroll_options(),
				'categoryTerms'   => $this->get_category_term_map(),
				'mediaCatalogUrl' => ! empty( $media_catalog['url'] ) ? esc_url_raw( $media_catalog['url'] ) : '',
			)
		);

		if ( wp_script_is( 'yith-infs', 'enqueued' ) || wp_script_is( 'yith-infs', 'registered' ) ) {
			wp_localize_script(
				'yith-infs',
				'yith_infs',
				$this->get_yith_infinite_scroll_options()
			);
		}
	}

	private function get_yith_infinite_scroll_options() {
		$loader = '';

		if ( defined( 'YITH_INFS_ASSETS_URL' ) ) {
			$loader = YITH_INFS_ASSETS_URL . '/images/loader.gif';
		}

		return array(
			'navSelector'     => 'nav.ion-shop-yith-nav',
			'nextSelector'    => 'nav.ion-shop-yith-nav a.next',
			'itemSelector'    => 'ul.products.ion-shop-grid > li.product',
			'contentSelector' => 'ul.products.ion-shop-grid',
			'loader'          => $loader,
			'shop'            => true,
		);
	}

	public function shortcode( $atts = array() ) {
		$this->shortcode_rendering = true;
		ob_start();
		echo $this->render_shop_page();
		$this->shortcode_rendering = false;
		return ob_get_clean();
	}

	public function ajax_filter() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$filters = $this->get_request_filters( true );
		$query   = $this->get_products_query( $filters );

		ob_start();
		$this->render_product_grid_region( $query, $filters );
		$grid_html = ob_get_clean();

		wp_send_json_success(
			array(
				'grid'     => $grid_html,
				'found'    => (int) $query->found_posts,
				'page'     => (int) $filters['page'],
				'maxPages' => (int) $query->max_num_pages,
				'hasMore'  => (int) $filters['page'] < (int) $query->max_num_pages,
			)
		);
	}

	public function yith_page_fragment() {
		$filters = $this->get_request_filters( false );
		$query   = $this->get_products_query( $filters );

		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

		echo '<div id="ion-shop-redesign" class="ion-shop-redesign ion-shop-yith-fragment">';
		$this->render_product_grid_region( $query, $filters );
		echo '</div>';

		wp_die();
	}

	private function can_access_prior_order_categories() {
		return class_exists( 'ION_Prior_Order_Category_Access' )
			&& is_callable( array( 'ION_Prior_Order_Category_Access', 'user_is_eligible' ) )
			&& ION_Prior_Order_Category_Access::user_is_eligible();
	}

	private function get_prior_order_category_slugs() {
		return array( 'creams', 'drops', 'sprays' );
	}

	private function add_prior_order_category_exclusion( $tax_query ) {
		if ( $this->can_access_prior_order_categories() ) {
			return $tax_query;
		}

		$tax_query[] = array(
			'taxonomy'         => 'product_cat',
			'field'            => 'slug',
			'terms'            => $this->get_prior_order_category_slugs(),
			'operator'         => 'NOT IN',
			'include_children' => true,
		);

		return $tax_query;
	}

	private function get_request_filters( $from_ajax = false ) {
		$source = $from_ajax ? $_POST : $_GET;

		$category = isset( $source['category'] ) ? sanitize_title( wp_unslash( $source['category'] ) ) : '';
		$sort     = isset( $source['sort'] ) ? sanitize_key( wp_unslash( $source['sort'] ) ) : 'az';
		$search   = isset( $source['search'] ) ? sanitize_text_field( wp_unslash( $source['search'] ) ) : '';
		$page     = isset( $source['page'] ) ? absint( $source['page'] ) : 0;

		if ( ! $from_ajax && ! $page ) {
			$page = max( 1, absint( get_query_var( 'paged' ) ), absint( get_query_var( 'page' ) ) );
		}

		if ( ! $page ) {
			$page = 1;
		}

		if ( $this->is_shop_context() && is_product_category() ) {
			$queried = get_queried_object();
			if ( $queried && ! is_wp_error( $queried ) && ! empty( $queried->slug ) && empty( $category ) ) {
				$category = sanitize_title( $queried->slug );
			}
		}

		$category = $this->normalize_category_key( $category );

		if ( ! $this->can_access_prior_order_categories() && in_array( $category, array( 'complex-elixir', 'drops', 'sprays' ), true ) ) {
			$category = '';
		}

		$allowed_sorts = array( 'az', 'za', 'price_asc', 'price_desc', 'newest' );
		if ( ! in_array( $sort, $allowed_sorts, true ) ) {
			$sort = 'az';
		}

		return array(
			'category' => $category,
			'sort'     => $sort,
			'search'   => $search,
			'page'     => max( 1, $page ),
		);
	}

	private function get_products_query( $filters = array() ) {
		$filters = wp_parse_args(
			$filters,
			array(
				'category' => '',
				'sort'     => 'az',
				'search'   => '',
				'page'     => 1,
			)
		);

		/*
		 * A selected dropdown category is deliberately returned as one complete,
		 * exact result set. This prevents a third-party infinite-scroll request
		 * from losing the category parameter and appending the unfiltered catalog.
		 * The All view keeps its existing pagination and infinite-scroll behavior.
		 */
		$posts_per_page = empty( $filters['category'] )
			? apply_filters( 'ion_shop_redesign_products_per_page', 15 )
			: -1;

		$args = array(
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => $posts_per_page,
			'paged'               => max( 1, absint( $filters['page'] ) ),
			'ignore_sticky_posts' => true,
			's'                   => $filters['search'],
			'tax_query'           => array(
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( 'exclude-from-catalog' ),
					'operator' => 'NOT IN',
				),
			),
		);

		$args['tax_query'] = $this->add_prior_order_category_exclusion( $args['tax_query'] );

		if ( ! empty( $filters['category'] ) ) {
			$category_terms = $this->get_category_filter_terms( $filters['category'] );

			if ( ! empty( $category_terms ) ) {
				$args['tax_query'][] = array(
					'taxonomy'         => 'product_cat',
					'field'            => 'slug',
					'terms'            => $category_terms,
					'operator'         => 'IN',
					'include_children' => false,
				);
			}
		}

		switch ( $filters['sort'] ) {
			case 'za':
				$args['orderby'] = 'title';
				$args['order']   = 'DESC';
				break;
			case 'price_asc':
				$args['meta_key'] = '_price';
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'ASC';
				break;
			case 'price_desc':
				$args['meta_key'] = '_price';
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				break;
			case 'newest':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
			case 'az':
			default:
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;
		}

		return new WP_Query( $args );
	}

	private function get_product_catalog_query() {
		$tax_query = $this->add_prior_order_category_exclusion(
			array(
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( 'exclude-from-catalog' ),
					'operator' => 'NOT IN',
				),
			)
		);

		return new WP_Query(
			array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'posts_per_page'      => -1,
				'orderby'             => 'title',
				'order'               => 'ASC',
				'ignore_sticky_posts' => true,
				'fields'              => 'ids',
				'tax_query'           => $tax_query,
			)
		);
	}

	private function get_category_group_definitions() {
		return apply_filters(
			'ion_shop_redesign_category_groups',
			array(
				'lyophilized'   => array(
					'label'      => __( 'Lyophilized', 'ion-shop-redesign' ),
					'candidates' => array( 'lyophilized', 'lyophilized-peptides', 'lyophilized peptide', 'lyophilized peptides' ),
				),
				'raws'          => array(
					'label'      => __( 'Raws', 'ion-shop-redesign' ),
					'candidates' => array( 'raws', 'raw', 'raw-peptides', 'raw peptide', 'raw peptides' ),
				),
				'complex-elixir'=> array(
					'label'      => __( 'Complex/Elixir', 'ion-shop-redesign' ),
					'candidates' => array( 'complex-elixir', 'complex-elixirs', 'complex elixir', 'complex elixirs', 'complex/elixir', 'complex', 'elixir', 'elixirs', 'creams', 'cream', 'serums', 'serum', 'shampoos', 'shampoo', 'specifications', 'specification' ),
				),
				'drops'         => array(
					'label'      => __( 'Drops', 'ion-shop-redesign' ),
					'candidates' => array( 'drops', 'drop' ),
				),
				'sprays'        => array(
					'label'      => __( 'Sprays', 'ion-shop-redesign' ),
					'candidates' => array( 'sprays', 'spray' ),
				),
				'lab-supplies'  => array(
					'label'      => __( 'Lab Supplies', 'ion-shop-redesign' ),
					'candidates' => array( 'lab-supplies', 'lab supplies', 'lab-supply', 'lab supply', 'supplies' ),
				),
			)
		);
	}

	private function get_categories() {
		$all_terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $all_terms ) || empty( $all_terms ) ) {
			return array();
		}

		$terms_by_key = array();
		foreach ( $all_terms as $term ) {
			$terms_by_key[ sanitize_title( $term->slug ) ] = $term;
			$terms_by_key[ sanitize_title( $term->name ) ] = $term;
		}

		$groups = array();
		$seen   = array();

		foreach ( $this->get_category_group_definitions() as $group_key => $group ) {
			$group_key  = sanitize_title( $group_key );

			if ( ! $this->can_access_prior_order_categories() && in_array( $group_key, array( 'complex-elixir', 'drops', 'sprays' ), true ) ) {
				continue;
			}

			$label      = isset( $group['label'] ) ? $group['label'] : ucwords( str_replace( '-', ' ', $group_key ) );
			$candidates = isset( $group['candidates'] ) ? (array) $group['candidates'] : array();
			$candidates = array_unique( array_merge( array( $group_key ), $candidates ) );
			$term_slugs = array();
			$term_ids   = array();

			foreach ( $candidates as $candidate ) {
				$key = sanitize_title( $candidate );

				if ( isset( $terms_by_key[ $key ] ) ) {
					$term = $terms_by_key[ $key ];

					if ( ! isset( $term_ids[ $term->term_id ] ) ) {
						$term_slugs[]              = $term->slug;
						$term_ids[ $term->term_id ] = true;
						$seen[ $term->term_id ]     = true;
					}
				}
			}

			if ( ! empty( $term_slugs ) ) {
				$category             = new stdClass();
				$category->slug       = $group_key;
				$category->name       = $label;
				$category->term_slugs = $term_slugs;
				$groups[]             = $category;
			}
		}

		return $groups;
	}

	private function normalize_category_key( $category ) {
		$category = sanitize_title( $category );

		if ( empty( $category ) ) {
			return '';
		}

		foreach ( $this->get_categories() as $group ) {
			if ( $category === $group->slug || in_array( $category, array_map( 'sanitize_title', (array) $group->term_slugs ), true ) ) {
				return $group->slug;
			}
		}

		foreach ( $this->get_category_group_definitions() as $group_key => $group ) {
			$group_key  = sanitize_title( $group_key );
			$candidates = isset( $group['candidates'] ) ? (array) $group['candidates'] : array();
			$candidates = array_unique( array_merge( array( $group_key ), $candidates ) );

			foreach ( $candidates as $candidate ) {
				if ( $category === sanitize_title( $candidate ) ) {
					return $group_key;
				}
			}
		}

		return $category;
	}

	private function get_category_filter_terms( $category ) {
		$category = $this->normalize_category_key( $category );

		if ( empty( $category ) ) {
			return array();
		}

		foreach ( $this->get_categories() as $group ) {
			if ( $category === $group->slug ) {
				return array_values( array_unique( array_map( 'sanitize_title', (array) $group->term_slugs ) ) );
			}
		}

		return array( sanitize_title( $category ) );
	}

	private function get_category_term_map() {
		$map = array();

		foreach ( $this->get_categories() as $group ) {
			$map[ sanitize_title( $group->slug ) ] = array_values(
				array_unique( array_map( 'sanitize_title', (array) $group->term_slugs ) )
			);
		}

		return $map;
	}

	private function get_product_display_category( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_cat' );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return __( 'Research', 'ion-shop-redesign' );
		}

		$product_term_slugs = wp_list_pluck( $terms, 'slug' );

		foreach ( $this->get_categories() as $group ) {
			if ( array_intersect( $product_term_slugs, (array) $group->term_slugs ) ) {
				return $group->name;
			}
		}

		return $terms[0]->name;
	}

	private function get_icon_svg( $icon ) {
		switch ( $icon ) {
			case 'truck':
				return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M3 6.5h11v9H3v-9Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M14 9.5h3.4l3.1 3.4v2.6H14v-6Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" fill="#ffffff" stroke="currentColor" stroke-width="2"/><path d="M18 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" fill="#ffffff" stroke="currentColor" stroke-width="2"/></svg>';
			case 'bag':
				return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M6.5 9.5h11l-1 10h-9l-1-10Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M9 9.5V7a3 3 0 0 1 6 0v2.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
			case 'shield-check':
			default:
				return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M12 3.25 19 6v5.1c0 4.4-2.85 8.35-7 9.65-4.15-1.3-7-5.25-7-9.65V6l7-2.75Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="m8.8 12.1 2.05 2.05L15.6 9.4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		}
	}


	/**
	 * Read the newest metadata value straight from wp_postmeta so Redis/object
	 * cache cannot keep an obsolete attachment filename alive.
	 */
	private function direct_meta( $post_id, $meta_key ) {
		global $wpdb;

		$post_id  = absint( $post_id );
		$meta_key = (string) $meta_key;

		if ( ! $post_id || '' === $meta_key ) {
			return '';
		}

		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
				$post_id,
				$meta_key
			)
		);

		return null === $raw ? '' : maybe_unserialize( $raw );
	}

	/**
	 * Resolve the current shop image from the attachment's newest database
	 * metadata and the files that actually exist on disk.
	 *
	 * The previous resolver required every generated size to share the same
	 * basename as metadata['file']. The media-renaming process on this site can
	 * leave the old full-size basename in metadata while the current generated
	 * sizes use the new filename. That caused valid files such as
	 * AOD-9604-600x600.jpg to be rejected and stale AOD-768x768.jpg HTML to stay
	 * visible. This resolver evaluates every current metadata candidate and only
	 * selects a file that exists.
	 */
	private function direct_attachment_shop_record( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return array();
		}

		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return array();
		}

		$metadata = $this->direct_meta( $attachment_id, '_wp_attachment_metadata' );
		$metadata = is_array( $metadata ) ? $metadata : array();

		$attached_file = $this->direct_meta( $attachment_id, '_wp_attached_file' );
		$attached_file = is_string( $attached_file ) ? trim( $attached_file ) : '';

		$candidates = array();

		/* Current full-size filename recorded by the attachment metadata. */
		if ( ! empty( $metadata['file'] ) && is_string( $metadata['file'] ) ) {
			$relative = ltrim( wp_normalize_path( trim( $metadata['file'] ) ), '/' );

			if ( $relative && false === strpos( $relative, '../' ) ) {
				$candidates[] = array(
					'relative' => $relative,
					'width'    => ! empty( $metadata['width'] ) ? absint( $metadata['width'] ) : 0,
					'height'   => ! empty( $metadata['height'] ) ? absint( $metadata['height'] ) : 0,
					'priority' => 3000000000,
				);

				$directory = dirname( $relative );
				$directory = '.' === $directory ? '' : trailingslashit( $directory );

				/*
				 * Do not require matching basenames here. The current site rename
				 * process can legitimately leave metadata['file'] and sizes using
				 * different basenames while the size files themselves are correct.
				 */
				if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
					foreach ( $metadata['sizes'] as $size_name => $size ) {
						if ( empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
							continue;
						}

						$size_relative = ltrim( wp_normalize_path( $directory . trim( $size['file'] ) ), '/' );
						if ( ! $size_relative || false !== strpos( $size_relative, '../' ) ) {
							continue;
						}

						$width  = ! empty( $size['width'] ) ? absint( $size['width'] ) : 0;
						$height = ! empty( $size['height'] ) ? absint( $size['height'] ) : 0;

						/*
						 * Prefer a useful catalog size near 720px. A 600x600 or
						 * 768x768 file is ideal, while tiny thumbnails and oversized
						 * originals are naturally ranked lower.
						 */
						$score = 2000000000;
						if ( $width > 0 ) {
							$score += 100000 - min( 99999, abs( $width - 720 ) );
						}
						if ( $width && $width < 300 ) {
							$score -= 50000;
						}
						if ( $width > 1400 ) {
							$score -= 15000;
						}
						if ( in_array( (string) $size_name, array( 'woocommerce_thumbnail', 'woocommerce_single', 'medium_large', 'large' ), true ) ) {
							$score += 5000;
						}

						$candidates[] = array(
							'relative' => $size_relative,
							'width'    => $width,
							'height'   => $height,
							'priority' => $score,
						);
					}
				}
			}
		}

		/* Old attached-file value is retained only as a lower-priority fallback. */
		if ( $attached_file ) {
			$relative = ltrim( wp_normalize_path( $attached_file ), '/' );
			if ( $relative && false === strpos( $relative, '../' ) ) {
				$candidates[] = array(
					'relative' => $relative,
					'width'    => ! empty( $metadata['width'] ) ? absint( $metadata['width'] ) : 0,
					'height'   => ! empty( $metadata['height'] ) ? absint( $metadata['height'] ) : 0,
					'priority' => 1000000000,
				);
			}
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				return (int) $b['priority'] <=> (int) $a['priority'];
			}
		);

		$selected = array();
		foreach ( $candidates as $candidate ) {
			$path = trailingslashit( $uploads['basedir'] ) . $candidate['relative'];
			if ( is_file( $path ) ) {
				$selected = $candidate;
				break;
			}
		}

		/* Preserve intentionally remote/offloaded attachments as a last fallback. */
		if ( empty( $selected ) && ! empty( $candidates ) ) {
			$selected = reset( $candidates );
		}

		if ( empty( $selected ) ) {
			$fallback = wp_get_attachment_url( $attachment_id );
			if ( ! $fallback ) {
				return array();
			}

			return array(
				'id'     => $attachment_id,
				'url'    => esc_url_raw( $fallback ),
				'width'  => ! empty( $metadata['width'] ) ? absint( $metadata['width'] ) : 800,
				'height' => ! empty( $metadata['height'] ) ? absint( $metadata['height'] ) : 800,
				'alt'    => wp_strip_all_tags( (string) $this->direct_meta( $attachment_id, '_wp_attachment_image_alt' ) ),
			);
		}

		$segments = array_map( 'rawurlencode', explode( '/', ltrim( $selected['relative'], '/' ) ) );
		$url      = trailingslashit( $uploads['baseurl'] ) . implode( '/', $segments );
		$alt      = (string) $this->direct_meta( $attachment_id, '_wp_attachment_image_alt' );

		if ( '' === trim( $alt ) ) {
			$alt = (string) get_the_title( $attachment_id );
		}

		return array(
			'id'     => $attachment_id,
			'url'    => esc_url_raw( $url ),
			'width'  => ! empty( $selected['width'] ) ? absint( $selected['width'] ) : 800,
			'height' => ! empty( $selected['height'] ) ? absint( $selected['height'] ) : 800,
			'alt'    => wp_strip_all_tags( $alt ),
		);
	}

	private function media_catalog_location() {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return array();
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'ion-shop-media';

		return array(
			'dir'  => $dir,
			'path' => trailingslashit( $dir ) . 'catalog.json',
			'url'  => trailingslashit( $uploads['baseurl'] ) . 'ion-shop-media/catalog.json',
		);
	}

	private function build_media_catalog() {
		global $wpdb;

		$product_ids = $wpdb->get_col(
			"SELECT ID
			 FROM {$wpdb->posts}
			 WHERE post_type = 'product'
			   AND post_status = 'publish'
			 ORDER BY ID ASC"
		);

		$products = array();

		foreach ( (array) $product_ids as $product_id ) {
			$product_id = absint( $product_id );
			$image_id   = absint( $this->direct_meta( $product_id, '_thumbnail_id' ) );
			$record     = $image_id ? $this->direct_attachment_shop_record( $image_id ) : array();

			$products[ (string) $product_id ] = ! empty( $record ) ? $record : null;
		}

		$catalog = array(
			'version'       => self::VERSION,
			'generated_gmt' => gmdate( 'c' ),
			'products'      => $products,
		);

		$catalog['signature'] = md5( wp_json_encode( $catalog ) );

		return $catalog;
	}

	private function rebuild_media_catalog() {
		$location = $this->media_catalog_location();
		if ( empty( $location ) || ! wp_mkdir_p( $location['dir'] ) ) {
			return false;
		}

		$json = wp_json_encode( $this->build_media_catalog(), JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || '' === $json ) {
			return false;
		}

		$tmp     = $location['path'] . '.' . wp_generate_uuid4() . '.tmp';
		$written = @file_put_contents( $tmp, $json, LOCK_EX );

		if ( false === $written ) {
			@unlink( $tmp );
			return false;
		}

		if ( ! @rename( $tmp, $location['path'] ) ) {
			@unlink( $location['path'] );
			if ( ! @rename( $tmp, $location['path'] ) ) {
				@unlink( $tmp );
				return false;
			}
		}

		return true;
	}

	public static function activate() {
		$instance = self::instance();
		$instance->rebuild_media_catalog();
		update_option( 'ion_shop_media_catalog_version', self::VERSION, false );
	}

	public function maybe_rebuild_media_catalog() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( (string) get_option( 'ion_shop_media_catalog_version', '' ) === self::VERSION ) {
			return;
		}

		$this->rebuild_media_catalog();
		update_option( 'ion_shop_media_catalog_version', self::VERSION, false );
	}

	private function queue_media_catalog_rebuild() {
		if ( self::$media_catalog_rebuild_queued ) {
			return;
		}

		self::$media_catalog_rebuild_queued = true;
		add_action( 'shutdown', array( $this, 'run_queued_media_catalog_rebuild' ), 999 );
	}

	public function run_queued_media_catalog_rebuild() {
		if ( ! self::$media_catalog_rebuild_queued ) {
			return;
		}

		self::$media_catalog_rebuild_queued = false;
		$this->rebuild_media_catalog();
	}

	public function product_media_changed( $post_id, $post, $update ) {
		if (
			! $post ||
			'product' !== $post->post_type ||
			wp_is_post_revision( $post_id ) ||
			( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		) {
			return;
		}

		$this->queue_media_catalog_rebuild();
	}

	public function product_status_changed( $post_id, $previous_status = '' ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			$this->queue_media_catalog_rebuild();
		}
	}

	public function media_meta_changed( $meta_id, $object_id, $meta_key, $meta_value ) {
		global $wpdb;

		$object_id = absint( $object_id );
		$meta_key  = (string) $meta_key;

		if ( ! $object_id ) {
			return;
		}

		if ( '_thumbnail_id' === $meta_key && 'product' === get_post_type( $object_id ) ) {
			$this->queue_media_catalog_rebuild();
			return;
		}

		if ( ! in_array( $meta_key, array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt' ), true ) ) {
			return;
		}

		$is_used = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_thumbnail_id'
				   AND pm.meta_value = %s
				   AND p.post_type = 'product'
				 LIMIT 1",
				(string) $object_id
			)
		);

		if ( $is_used ) {
			$this->queue_media_catalog_rebuild();
		}
	}

	private function get_current_product_image_record( $product_id ) {
		$image_id = absint( $this->direct_meta( $product_id, '_thumbnail_id' ) );
		return $image_id ? $this->direct_attachment_shop_record( $image_id ) : array();
	}

	private function get_product_image_html( $product_id ) {
		$record = $this->get_current_product_image_record( $product_id );

		if ( empty( $record['url'] ) ) {
			return '';
		}

		return sprintf(
			'<img class="ion-shop-product-image" src="%1$s" data-ion-shop-backend-src="%1$s" alt="%2$s" width="%3$d" height="%4$d" loading="lazy" decoding="async">',
			esc_url( $record['url'] ),
			esc_attr( $record['alt'] ),
			max( 1, absint( $record['width'] ) ),
			max( 1, absint( $record['height'] ) )
		);
	}

	private function get_image_fallback_html() {
		return '<div class="ion-shop-image-fallback" aria-hidden="true"><span>ION</span><strong>Product Image</strong></div>';
	}

	public function correct_variable_parent_stock_status( $is_in_stock, $product ) {
		if ( $is_in_stock || ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			return $is_in_stock;
		}

		if ( ! $this->should_correct_variable_stock_context() ) {
			return $is_in_stock;
		}

		return $this->variable_product_has_available_variation( $product ) ? true : $is_in_stock;
	}

	private function should_correct_variable_stock_context() {
		if ( $this->shortcode_rendering || $this->is_shop_context() ) {
			return true;
		}

		if ( wp_doing_ajax() ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

			return in_array(
				$action,
				array(
					'ion_shop_redesign_filter',
					'ion_shop_redesign_yith_page',
					'ion_mu_shop_products',
					'peptide_ajax_products',
				),
				true
			);
		}

		return false;
	}

	private function product_is_effectively_in_stock( WC_Product $product ) {
		if ( $product->is_type( 'variable' ) && $this->variable_product_has_available_variation( $product ) ) {
			return true;
		}

		return $product->is_in_stock();
	}

	private function variable_product_has_available_variation( WC_Product $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return false;
		}

		$children = method_exists( $product, 'get_visible_children' ) ? $product->get_visible_children() : array();

		if ( empty( $children ) ) {
			$children = $product->get_children();
		}

		if ( empty( $children ) ) {
			return false;
		}

		foreach ( array_unique( array_map( 'absint', $children ) ) as $variation_id ) {
			if ( ! $variation_id || 'publish' !== get_post_status( $variation_id ) ) {
				continue;
			}

			$variation = wc_get_product( $variation_id );

			if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
				continue;
			}

			if ( method_exists( $variation, 'variation_is_visible' ) && ! $variation->variation_is_visible() ) {
				continue;
			}

			if ( ! $variation->is_purchasable() ) {
				continue;
			}

			if ( $variation->is_in_stock() || $variation->backorders_allowed() ) {
				return true;
			}

			if ( $variation->managing_stock() ) {
				$stock_quantity = $variation->get_stock_quantity();

				if ( null !== $stock_quantity && (float) $stock_quantity > 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	private function get_product_card_class_attr( WC_Product $product ) {
		$classes = function_exists( 'wc_get_product_class' ) ? wc_get_product_class( 'ion-shop-card', $product ) : array( 'product', 'ion-shop-card' );

		if ( $this->product_is_effectively_in_stock( $product ) ) {
			$classes = array_diff( $classes, array( 'outofstock' ) );
			$classes[] = 'instock';
		} else {
			$classes = array_diff( $classes, array( 'instock' ) );
			$classes[] = 'outofstock';
		}

		$classes = array_values( array_unique( array_filter( array_map( 'sanitize_html_class', $classes ) ) ) );

		return 'class="' . esc_attr( implode( ' ', $classes ) ) . '"';
	}

	public function render_shop_page() {
		$filters = $this->get_request_filters( false );
		$query   = $this->get_products_query( $filters );

		ob_start();
		?>
		<div id="ion-shop-redesign" class="ion-shop-redesign" data-page="<?php echo esc_attr( $filters['page'] ); ?>" data-max-pages="<?php echo esc_attr( max( 1, (int) $query->max_num_pages ) ); ?>" data-loading-more="0">
			<div class="ion-shop-shell">
				<div class="ion-shop-ruo-bar">
					<strong><?php esc_html_e( 'Research Use Only:', 'ion-shop-redesign' ); ?></strong>
					<span><?php esc_html_e( 'All products currently listed on this site are for research purposes ONLY.', 'ion-shop-redesign' ); ?></span>
				</div>

				<div class="ion-shop-layout">
					<?php $this->render_product_catalog(); ?>

					<main class="ion-shop-main" aria-label="Ion Peptide product catalog">
						<header class="ion-shop-heading">
							<div>
								<h1><?php esc_html_e( 'Explore our products', 'ion-shop-redesign' ); ?></h1>
								<div class="ion-shop-proof-row" aria-label="Product highlights">
									<span class="ion-shop-proof"><span class="ion-shop-proof-icon" aria-hidden="true"><?php echo $this->get_icon_svg( 'shield-check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php esc_html_e( '99%+ Purity', 'ion-shop-redesign' ); ?></span>
									<span class="ion-shop-proof"><span class="ion-shop-proof-icon" aria-hidden="true"><?php echo $this->get_icon_svg( 'truck' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php esc_html_e( 'Same-Day Shipping', 'ion-shop-redesign' ); ?></span>
								</div>
							</div>
						</header>

						<?php $this->render_filters( $filters ); ?>

						<div class="ion-shop-status" aria-live="polite">
							<span class="ion-shop-result-count"><?php echo esc_html( sprintf( _n( '%s product found', '%s products found', (int) $query->found_posts, 'ion-shop-redesign' ), number_format_i18n( (int) $query->found_posts ) ) ); ?></span>
							<span class="ion-shop-loading" hidden><?php esc_html_e( 'Loading products…', 'ion-shop-redesign' ); ?></span>
						</div>

						<div class="ion-shop-grid-wrap">
							<?php $this->render_product_grid_region( $query, $filters ); ?>
						</div>
					</main>
				</div>
			</div>

			<?php $this->render_support_section(); ?>
		</div>
		<?php
		wp_reset_postdata();
		return ob_get_clean();
	}

	private function render_product_catalog() {
		$query = $this->get_product_catalog_query();
		?>
		<aside class="ion-shop-sidebar" aria-label="Product catalog">
			<h2><?php esc_html_e( 'Product Catalog', 'ion-shop-redesign' ); ?></h2>
			<div class="ion-shop-sidebar-list-wrap">
				<ul class="ion-shop-sidebar-list">
					<?php if ( $query->have_posts() ) : ?>
						<?php foreach ( $query->posts as $product_id ) : ?>
							<li><a href="<?php echo esc_url( get_permalink( $product_id ) ); ?>"><?php echo esc_html( get_the_title( $product_id ) ); ?></a></li>
						<?php endforeach; ?>
					<?php else : ?>
						<li><?php esc_html_e( 'No products found.', 'ion-shop-redesign' ); ?></li>
					<?php endif; ?>
				</ul>
			</div>
		</aside>
		<?php
		wp_reset_postdata();
	}

	private function render_filters( $filters ) {
		$categories = $this->get_categories();
		?>
		<form class="ion-shop-filters" action="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ) ); ?>" method="get" autocomplete="off">
			<div class="ion-shop-search-field">
				<label class="screen-reader-text" for="ion-shop-search-input"><?php esc_html_e( 'Search products', 'ion-shop-redesign' ); ?></label>
				<span class="ion-shop-search-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"></circle><path d="M20 20l-4.5-4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>
				</span>
				<input id="ion-shop-search-input" class="ion-shop-search-input" type="search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search by product or type', 'ion-shop-redesign' ); ?>">
			</div>

			<div class="ion-shop-select-wrap">
				<label class="screen-reader-text" for="ion-shop-category-select"><?php esc_html_e( 'Filter by category', 'ion-shop-redesign' ); ?></label>
				<select id="ion-shop-category-select" class="ion-shop-category-select" name="category" onchange="this.form.submit();">
					<option value=""><?php esc_html_e( 'All', 'ion-shop-redesign' ); ?></option>
					<?php foreach ( $categories as $category ) : ?>
						<option value="<?php echo esc_attr( $category->slug ); ?>" <?php selected( $filters['category'], $category->slug ); ?>><?php echo esc_html( $category->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ion-shop-select-wrap">
				<label class="screen-reader-text" for="ion-shop-sort-select"><?php esc_html_e( 'Sort products', 'ion-shop-redesign' ); ?></label>
				<select id="ion-shop-sort-select" class="ion-shop-sort-select" name="sort" onchange="this.form.submit();">
					<option value="az" <?php selected( $filters['sort'], 'az' ); ?>><?php esc_html_e( 'A-Z', 'ion-shop-redesign' ); ?></option>
					<option value="za" <?php selected( $filters['sort'], 'za' ); ?>><?php esc_html_e( 'Z-A', 'ion-shop-redesign' ); ?></option>
					<option value="price_asc" <?php selected( $filters['sort'], 'price_asc' ); ?>><?php esc_html_e( 'Price: Low to High', 'ion-shop-redesign' ); ?></option>
					<option value="price_desc" <?php selected( $filters['sort'], 'price_desc' ); ?>><?php esc_html_e( 'Price: High to Low', 'ion-shop-redesign' ); ?></option>
					<option value="newest" <?php selected( $filters['sort'], 'newest' ); ?>><?php esc_html_e( 'Newest', 'ion-shop-redesign' ); ?></option>
				</select>
			</div>
		</form>
		<?php
	}

	private function render_product_grid_region( $query, $filters ) {
		$this->render_product_grid( $query );
		$this->render_yith_navigation( $query, $filters );
	}

	private function render_product_grid( $query ) {
		?>
		<ul class="products ion-shop-grid" role="list">
			<?php if ( $query->have_posts() ) : ?>
				<?php while ( $query->have_posts() ) : $query->the_post(); ?>
					<?php $this->render_product_card( get_the_ID() ); ?>
				<?php endwhile; ?>
			<?php else : ?>
				<li class="ion-shop-empty">
					<h2><?php esc_html_e( 'No products found', 'ion-shop-redesign' ); ?></h2>
					<p><?php esc_html_e( 'Try a different search term, category, or sorting option.', 'ion-shop-redesign' ); ?></p>
				</li>
			<?php endif; ?>
		</ul>
		<?php
		wp_reset_postdata();
	}

	private function render_yith_navigation( $query, $filters ) {
		$current = max( 1, absint( $filters['page'] ) );
		$total   = max( 1, absint( $query->max_num_pages ) );

		if ( $current >= $total ) {
			return;
		}

		$next_url = $this->get_shop_page_url( $current + 1, $filters );
		?>
		<nav class="woocommerce-pagination ion-shop-yith-nav" aria-label="Product pagination">
			<a class="next page-numbers" href="<?php echo esc_url( $next_url ); ?>"><?php esc_html_e( 'Next', 'ion-shop-redesign' ); ?></a>
		</nav>
		<?php
	}

	private function get_shop_page_url( $page, $filters ) {
		$page = max( 1, absint( $page ) );

		if ( $page > 1 ) {
			$args = array(
				'action' => 'ion_shop_redesign_yith_page',
				'page'   => $page,
			);

			if ( ! empty( $filters['search'] ) ) {
				$args['search'] = $filters['search'];
			}

			if ( ! empty( $filters['category'] ) ) {
				$args['category'] = $filters['category'];
			}

			if ( ! empty( $filters['sort'] ) && 'az' !== $filters['sort'] ) {
				$args['sort'] = $filters['sort'];
			}

			return add_query_arg( $args, admin_url( 'admin-ajax.php' ) );
		}

		$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
		$args     = array();

		if ( ! empty( $filters['search'] ) ) {
			$args['search'] = $filters['search'];
		}

		if ( ! empty( $filters['category'] ) ) {
			$args['category'] = $filters['category'];
		}

		if ( ! empty( $filters['sort'] ) && 'az' !== $filters['sort'] ) {
			$args['sort'] = $filters['sort'];
		}

		return ! empty( $args ) ? add_query_arg( $args, $shop_url ) : $shop_url;
	}

	private function render_product_card( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$category     = $this->get_product_display_category( $product_id );
		$description  = $product->get_short_description();
		if ( empty( $description ) ) {
			$description = get_post_field( 'post_excerpt', $product_id );
		}
		if ( empty( $description ) ) {
			$description = get_post_field( 'post_content', $product_id );
		}
		$description = wp_trim_words( wp_strip_all_tags( $description ), 13, '…' );
		$image = $this->get_product_image_html( $product_id );

		if ( empty( $image ) ) {
			$image = $this->get_image_fallback_html();
		}
		?>
		<li <?php echo $this->get_product_card_class_attr( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> role="listitem" data-ion-shop-product-id="<?php echo esc_attr( $product_id ); ?>">
			<a class="ion-shop-card-image-link" href="<?php echo esc_url( get_permalink( $product_id ) ); ?>" aria-label="<?php echo esc_attr( $product->get_name() ); ?>">
				<div class="ion-shop-card-image">
					<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</a>

			<div class="ion-shop-card-body">
				<div class="ion-shop-card-title-row">
					<h2><a href="<?php echo esc_url( get_permalink( $product_id ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></h2>
					<span class="ion-shop-card-badge"><?php echo esc_html( $category ); ?></span>
				</div>

				<?php if ( ! empty( $description ) ) : ?>
					<p class="ion-shop-card-description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>

				<div class="ion-shop-card-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>

				<?php $this->render_product_button( $product ); ?>
			</div>
		</li>
		<?php
	}

	private function render_product_button( WC_Product $product ) {
		$product_id = $product->get_id();

		if ( ! $this->product_is_effectively_in_stock( $product ) ) {
			printf(
				'<button class="ion-shop-card-button ion-shop-card-button--disabled" type="button" disabled>%s</button>',
				esc_html__( 'Out of Stock', 'ion-shop-redesign' )
			);
			return;
		}

		if ( $product->is_type( 'simple' ) && $product->is_purchasable() ) {
			printf(
				'<a href="%1$s" data-quantity="1" class="ion-shop-card-button add_to_cart_button ajax_add_to_cart" data-product_id="%2$d" data-product_sku="%3$s" aria-label="%4$s" rel="nofollow"><span class="ion-shop-bag" aria-hidden="true">' . $this->get_icon_svg( 'bag' ) . '</span>%5$s</a>',
				esc_url( $product->add_to_cart_url() ),
				absint( $product_id ),
				esc_attr( $product->get_sku() ),
				esc_attr( sprintf( __( 'Add %s to cart', 'ion-shop-redesign' ), $product->get_name() ) ),
				esc_html__( 'Add to Cart', 'ion-shop-redesign' )
			);
			return;
		}

		printf(
			'<a href="%1$s" class="ion-shop-card-button"><span class="ion-shop-bag" aria-hidden="true">' . $this->get_icon_svg( 'bag' ) . '</span>%2$s</a>',
			esc_url( get_permalink( $product_id ) ),
			esc_html__( 'Select Options', 'ion-shop-redesign' )
		);
	}

	private function get_pagination_html( $query, $filters ) {
		if ( $query->max_num_pages <= 1 ) {
			return '';
		}

		$current = max( 1, absint( $filters['page'] ) );
		$total   = absint( $query->max_num_pages );

		ob_start();
		?>
		<nav class="ion-shop-pagination" aria-label="Product pagination">
			<?php for ( $i = 1; $i <= $total; $i++ ) : ?>
				<button class="ion-shop-page-button<?php echo $i === $current ? ' is-active' : ''; ?>" type="button" data-page="<?php echo esc_attr( $i ); ?>"><?php echo esc_html( $i ); ?></button>
			<?php endfor; ?>
		</nav>
		<?php
		return ob_get_clean();
	}


	/**
	 * Hard-stop stale responsive image candidates on the shop.
	 *
	 * The broken backend state contains a current thumbnail URL in src while
	 * obsolete renamed files remain inside srcset. Browsers choose the larger
	 * dead srcset candidate (for example AOD-768x768.jpg) instead of the valid
	 * backend src (AOD-9604-300x300.jpg). This guard removes those stale
	 * responsive/lazy candidates immediately and only falls back to the already
	 * generated static product media manifest when the plain src itself fails.
	 *
	 * No admin-ajax, REST, PHP, or database request is made by visitors. The
	 * optional fallback JSON is a static file served directly from /uploads.
	 */
	public function render_live_image_guard() {
		if ( ! $this->is_shop_context() ) {
			return;
		}

		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) ) {
			return;
		}

		$manifest_base = trailingslashit( $uploads['baseurl'] ) . 'ion-product-media/';
		?>
		<script id="ion-shop-live-image-guard-v1019">
		(function () {
			'use strict';

			var manifestBase = <?php echo wp_json_encode( esc_url_raw( $manifest_base ) ); ?>;
			var manifestRequests = Object.create(null);
			var watchedAttributes = [
				'src', 'srcset', 'sizes', 'data-src', 'data-srcset',
				'data-lazy-src', 'data-lazy-srcset', 'data-original',
				'data-orig-file', 'data-large-file'
			];

			function getProductId(card) {
				if (!card) return 0;

				var direct = parseInt(card.getAttribute('data-ion-shop-product-id') || '0', 10);
				if (direct > 0) return direct;

				var button = card.querySelector('[data-product_id]');
				var buttonId = button ? parseInt(button.getAttribute('data-product_id') || '0', 10) : 0;
				if (buttonId > 0) return buttonId;

				var classMatch = String(card.className || '').match(/(?:^|\s)post-(\d+)(?:\s|$)/);
				if (classMatch) return parseInt(classMatch[1], 10) || 0;

				var idMatch = String(card.id || '').match(/(?:product-)?(\d+)/);
				return idMatch ? (parseInt(idMatch[1], 10) || 0) : 0;
			}

			function stripResponsiveCandidates(img, forcedUrl) {
				if (!img || img.nodeType !== 1) return;

				var source = forcedUrl || img.getAttribute('data-ion-shop-backend-src') || '';
				if (!source) {
					var lazySource = img.getAttribute('data-src') || img.getAttribute('data-lazy-src') || '';
					var plainSource = img.getAttribute('src') || '';
					source = lazySource && lazySource.indexOf('data:') !== 0 ? lazySource : plainSource;
				}
				if (!source) return;

				var picture = img.closest ? img.closest('picture') : null;
				if (picture) {
					Array.prototype.slice.call(picture.querySelectorAll('source')).forEach(function (node) {
						node.parentNode.removeChild(node);
					});
				}

				[
					'srcset', 'sizes', 'data-srcset', 'data-lazy-srcset', 'data-sizes',
					'data-original', 'data-orig-file', 'data-large-file', 'data-ll-status'
				].forEach(function (name) {
					if (img.hasAttribute(name)) img.removeAttribute(name);
				});

				/*
				 * Lazy-load plugins can put the obsolete file in data-src. Once we have
				 * a verified source, remove those alternate source attributes too.
				 */
				['data-src', 'data-lazy-src'].forEach(function (name) {
					if (img.hasAttribute(name)) img.removeAttribute(name);
				});

				if (img.getAttribute('data-ion-shop-backend-src') !== source) {
					img.setAttribute('data-ion-shop-backend-src', source);
				}
				if (img.getAttribute('src') !== source) {
					img.setAttribute('src', source);
				}
				if (img.style && img.style.display === 'none') {
					img.style.display = '';
				}
			}

			function getManifest(productId) {
				if (!productId || !window.fetch) return Promise.resolve(null);
				if (manifestRequests[productId]) return manifestRequests[productId];

				var url = manifestBase + 'product-' + productId + '.json?ion_shop_live=' + Date.now();
				manifestRequests[productId] = window.fetch(url, {
					method: 'GET',
					credentials: 'same-origin',
					cache: 'no-store',
					headers: { Accept: 'application/json' }
				})
					.then(function (response) {
						if (!response.ok) throw new Error('Product media manifest unavailable');
						return response.json();
					})
					.catch(function () { return null; });

				return manifestRequests[productId];
			}

			function repairFromManifest(img) {
				var card = img.closest ? img.closest('li.product, .ion-shop-card') : null;
				var productId = getProductId(card);
				if (!productId || img.getAttribute('data-ion-shop-manifest-pending') === '1') return;

				img.setAttribute('data-ion-shop-manifest-pending', '1');
				getManifest(productId).then(function (manifest) {
					img.removeAttribute('data-ion-shop-manifest-pending');
					if (!manifest || !manifest.main || !manifest.main.url) return;

					var url = String(manifest.main.url);
					stripResponsiveCandidates(img, url);
					if (manifest.main.alt) img.setAttribute('alt', String(manifest.main.alt));
					if (parseInt(manifest.main.width || 0, 10) > 0) img.setAttribute('width', parseInt(manifest.main.width, 10));
					if (parseInt(manifest.main.height || 0, 10) > 0) img.setAttribute('height', parseInt(manifest.main.height, 10));
				});
			}

			function bindImage(img) {
				stripResponsiveCandidates(img);
				if (img.getAttribute('data-ion-shop-guard-bound') === '1') return;

				img.setAttribute('data-ion-shop-guard-bound', '1');
				img.addEventListener('error', function () {
					repairFromManifest(img);
				}, false);

				if (img.complete && typeof img.naturalWidth !== 'undefined' && img.naturalWidth === 0) {
					repairFromManifest(img);
				}
			}

			function scan(scope) {
				if (!scope || !scope.querySelectorAll) return;
				if (scope.matches && scope.matches('#ion-shop-redesign .ion-shop-card-image img')) {
					bindImage(scope);
				}
				Array.prototype.slice.call(scope.querySelectorAll('#ion-shop-redesign .ion-shop-card-image img, .ion-shop-card-image img')).forEach(bindImage);
			}

			function start() {
				var root = document.getElementById('ion-shop-redesign');
				if (!root) return;

				scan(root);

				if (window.MutationObserver) {
					var observer = new MutationObserver(function (mutations) {
						mutations.forEach(function (mutation) {
							if (mutation.type === 'attributes' && mutation.target && mutation.target.tagName === 'IMG') {
								bindImage(mutation.target);
								return;
							}
							Array.prototype.slice.call(mutation.addedNodes || []).forEach(function (node) {
								if (node.nodeType === 1) scan(node);
							});
						});
					});

					observer.observe(root, {
						childList: true,
						subtree: true,
						attributes: true,
						attributeFilter: watchedAttributes
					});
				}

				[50, 250, 900, 1800, 3200].forEach(function (delay) {
					window.setTimeout(function () { scan(root); }, delay);
				});
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', start, { once: true });
			} else {
				start();
			}
		})();
		</script>
		<?php
	}

	private function render_support_section() {
		?>
		<section class="ion-shop-support" aria-label="Dedicated team support">
			<div class="ion-shop-support-inner">
				<div class="ion-shop-support-copy">
					<span class="ion-shop-kicker"><?php esc_html_e( 'Support', 'ion-shop-redesign' ); ?></span>
					<h2><?php esc_html_e( 'Dedicated team is here to help', 'ion-shop-redesign' ); ?></h2>
					<h3><?php esc_html_e( 'Get a response within minutes', 'ion-shop-redesign' ); ?></h3>
					<p><?php esc_html_e( 'Our support team can help with product documentation, order questions, shipping, payment, account access, and research-use support follow-up.', 'ion-shop-redesign' ); ?></p>

					<div class="ion-shop-support-actions">
						<button class="ion-shop-support-button ion-shop-live-chat-button" type="button"><?php esc_html_e( 'Live Chat', 'ion-shop-redesign' ); ?> <span aria-hidden="true">→</span></button>
						<a class="ion-shop-support-button ion-shop-support-button--ghost" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Contact Support', 'ion-shop-redesign' ); ?></a>
					</div>
				</div>

				<div class="ion-shop-support-image">
					<img src="https://ionpeptide.com/wp-content/uploads/2026/09/Live-Chat.jpg" alt="Ion Peptide live chat support" width="717" height="1024" loading="lazy" decoding="async">
				</div>
			</div>
		</section>
		<?php
	}
}

register_activation_hook( __FILE__, array( 'Ion_Shop_Redesign', 'activate' ) );
Ion_Shop_Redesign::instance();
