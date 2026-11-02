<?php
/**
 * Plugin Name: ION COA Safe Consolidator
 * Description: Safe Lab Result mapping/consolidation with dry-run analysis, automatic backups, conflict resolution (including multi-page COAs), review queue, Library Only handling, rollback, and a cleaned Lab Result editor.
 * Version: 1.7.10
 * Author: BuildRightWeb
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ION_COA_Safe_Consolidator {
	const VERSION = '1.7.10';
	const MENU_SLUG = 'ioncoa-safe-consolidator';
	const BACKUP_INDEX_OPTION = 'ioncoa_safe_backup_index';
	const RESULT_TRANSIENT_PREFIX = 'ioncoa_safe_result_';
	const CONSOLIDATED_STATUS = 'ioncoa_consolidated';
	const LIBRARY_ONLY_META = '_ioncoa_library_only';
	const MULTI_CURRENT_META = '_ioncoa_allow_multi_current_signature';

	private static $instance = null;
	private $product_index = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		/* Keep consolidated duplicates recoverable but out of normal Lab Result lists/searches. */
		add_action( 'init', array( $this, 'register_consolidated_status' ), 20 );
		add_action( 'admin_init', array( $this, 'migrate_existing_consolidated_drafts' ), 30 );
		add_action( 'pre_get_posts', array( $this, 'filter_lab_result_admin_list' ), 20 );
		add_filter( 'views_edit-lab_result', array( $this, 'lab_result_views' ) );
		add_action( 'restrict_manage_posts', array( $this, 'preserve_consolidated_view' ), 1, 2 );
		add_filter( 'display_post_states', array( $this, 'display_consolidated_post_state' ), 10, 2 );

		add_action( 'admin_menu', array( $this, 'admin_menu' ), 99 );
		add_action( 'admin_post_ioncoa_safe_dry_run', array( $this, 'handle_dry_run' ) );
		add_action( 'admin_post_ioncoa_safe_backup_only', array( $this, 'handle_backup_only' ) );
		add_action( 'admin_post_ioncoa_safe_consolidate', array( $this, 'handle_consolidate' ) );
		add_action( 'admin_post_ioncoa_safe_consolidate_group', array( $this, 'handle_consolidate_group' ) );
		add_action( 'admin_post_ioncoa_safe_consolidate_selected', array( $this, 'handle_consolidate_selected' ) );
		add_action( 'admin_post_ioncoa_safe_rollback', array( $this, 'handle_rollback' ) );
		add_action( 'admin_post_ioncoa_safe_download_backup', array( $this, 'handle_download_backup' ) );
		add_action( 'admin_post_ioncoa_safe_export_crosscheck', array( $this, 'handle_export_crosscheck' ) );
		add_action( 'admin_post_ioncoa_safe_export_full_audit', array( $this, 'handle_export_full_audit' ) );
		add_action( 'admin_post_ioncoa_safe_import_reviewed', array( $this, 'handle_import_reviewed' ) );
		add_action( 'admin_post_ioncoa_safe_preview_batch_repair', array( $this, 'handle_preview_batch_repair' ) );
		add_action( 'admin_post_ioncoa_safe_apply_batch_repair', array( $this, 'handle_apply_batch_repair' ) );
		add_action( 'admin_post_ioncoa_safe_strength_audit', array( $this, 'handle_strength_audit' ) );
		add_action( 'admin_post_ioncoa_safe_strength_repair', array( $this, 'handle_strength_repair' ) );
		add_action( 'admin_post_ioncoa_safe_resolve_url_conflict', array( $this, 'handle_resolve_url_conflict' ) );
		add_action( 'admin_post_ioncoa_safe_mark_url_older', array( $this, 'handle_mark_url_older' ) );
		add_action( 'admin_post_ioncoa_safe_keep_multipart', array( $this, 'handle_keep_multipart' ) );
		add_action( 'admin_post_ioncoa_safe_set_current', array( $this, 'handle_set_current' ) );
		add_action( 'admin_post_ioncoa_safe_mark_older', array( $this, 'handle_mark_older' ) );
		add_action( 'admin_post_ioncoa_safe_keep_all_current', array( $this, 'handle_keep_all_current' ) );
		/* Legacy destructive handler kept only for backwards compatibility; v1.7 no longer exposes it in the UI. */
		add_action( 'admin_post_ioncoa_safe_remove_current_report', array( $this, 'handle_remove_current_report' ) );
		add_action( 'admin_post_ioncoa_safe_save_review_mapping', array( $this, 'handle_save_review_mapping' ) );
		add_action( 'admin_post_ioncoa_safe_library_only', array( $this, 'handle_library_only' ) );
		add_action( 'admin_post_ioncoa_safe_return_to_review', array( $this, 'handle_return_to_review' ) );
		add_action( 'admin_post_ioncoa_safe_preview_finish', array( $this, 'handle_preview_finish' ) );
		add_action( 'admin_post_ioncoa_safe_bulk_finish', array( $this, 'handle_bulk_finish' ) );
		add_action( 'wp_ajax_ioncoa_safe_product_search', array( $this, 'ajax_product_search' ) );
		add_action( 'admin_notices', array( $this, 'lab_results_notice' ) );
		add_action( 'admin_head-edit.php', array( $this, 'hide_legacy_analyzer' ) );

		/* Keep the Lab Result editor focused on COA data only. */
		add_action( 'add_meta_boxes_lab_result', array( $this, 'cleanup_lab_result_metaboxes' ), PHP_INT_MAX );
		add_action( 'do_meta_boxes', array( $this, 'cleanup_lab_result_metaboxes_late' ), PHP_INT_MAX, 3 );
		add_action( 'admin_head-post.php', array( $this, 'lab_result_editor_cleanup_css' ), PHP_INT_MAX );
		add_action( 'admin_head-post-new.php', array( $this, 'lab_result_editor_cleanup_css' ), PHP_INT_MAX );
	}


	/**
	 * Dedicated internal status for records that were safely merged into a
	 * canonical Lab Result. These posts remain in the database for rollback and
	 * audit purposes, but are excluded from the normal All/Drafts/search views.
	 */
	public function register_consolidated_status() {
		register_post_status(
			self::CONSOLIDATED_STATUS,
			array(
				'label'                     => 'Consolidated',
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
				'label_count'               => _n_noop( 'Consolidated <span class="count">(%s)</span>', 'Consolidated <span class="count">(%s)</span>' ),
			)
		);
	}

	/**
	 * One-time migration for duplicates consolidated by v1.0-v1.2. Those builds
	 * placed duplicates in Draft and marked them with _ioncoa_consolidated_into.
	 * v1.3 moves ONLY those marked drafts into the dedicated Consolidated status.
	 * No batches, URLs, titles, mappings, or canonical posts are changed.
	 */
	public function migrate_existing_consolidated_drafts() {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		$done_version = (string) get_option( 'ioncoa_safe_archive_migration_version', '' );
		if ( version_compare( $done_version, '1.3.0', '>=' ) ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'lab_result',
				'post_status'    => 'draft',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => '_ioncoa_consolidated_into',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( (array) $ids as $post_id ) {
			wp_update_post(
				array(
					'ID'          => absint( $post_id ),
					'post_status' => self::CONSOLIDATED_STATUS,
				)
			);
		}

		update_option( 'ioncoa_safe_archive_migration_version', self::VERSION, false );
	}

	/**
	 * Default Lab Result admin searches stay clean. Consolidated duplicates are
	 * shown only when the dedicated Consolidated view is selected.
	 */
	public function filter_lab_result_admin_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		global $pagenow;
		if ( 'edit.php' !== $pagenow || 'lab_result' !== $query->get( 'post_type' ) ) {
			return;
		}

		$view = isset( $_GET['ioncoa_view'] ) ? sanitize_key( wp_unslash( $_GET['ioncoa_view'] ) ) : '';
		if ( 'consolidated' === $view ) {
			$query->set( 'post_status', self::CONSOLIDATED_STATUS );
			return;
		}
		if ( 'library_only' === $view ) {
			$query->set( 'post_status', 'publish' );
			$query->set(
				'meta_query',
				array(
					array(
						'key'   => self::LIBRARY_ONLY_META,
						'value' => '1',
					),
				)
			);
			return;
		}

		/* If a normal WordPress status tab is explicitly selected, respect it. */
		$requested_status = sanitize_key( (string) $query->get( 'post_status' ) );
		if ( $requested_status && self::CONSOLIDATED_STATUS !== $requested_status && 'publish' !== $requested_status ) {
			return;
		}

		/* Default All/search and Published exclude deliberate Library Only records. */
		if ( ! $requested_status ) {
			$query->set( 'post_status', array( 'publish', 'draft', 'private', 'pending', 'future' ) );
		}

		$meta_query = $query->get( 'meta_query' );
		$meta_query = is_array( $meta_query ) ? $meta_query : array();
		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => self::LIBRARY_ONLY_META,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::LIBRARY_ONLY_META,
				'value'   => '1',
				'compare' => '!=',
			),
		);
		$query->set( 'meta_query', $meta_query );
	}

	/** Add a clean, explicit Consolidated tab beside All/Published/Drafts. */
	public function lab_result_views( $views ) {
		$count = $this->count_consolidated_posts();
		$url   = add_query_arg(
			array(
				'post_type'   => 'lab_result',
				'ioncoa_view' => 'consolidated',
			),
			admin_url( 'edit.php' )
		);
		$current = isset( $_GET['ioncoa_view'] ) && 'consolidated' === sanitize_key( wp_unslash( $_GET['ioncoa_view'] ) );
		$views['ioncoa_consolidated'] = '<a href="' . esc_url( $url ) . '"' . ( $current ? ' class="current" aria-current="page"' : '' ) . '>Consolidated <span class="count">(' . esc_html( $count ) . ')</span></a>';

		$library_count = $this->count_library_only_posts();
		$library_url = add_query_arg(
			array(
				'post_type'   => 'lab_result',
				'ioncoa_view' => 'library_only',
			),
			admin_url( 'edit.php' )
		);
		$library_current = isset( $_GET['ioncoa_view'] ) && 'library_only' === sanitize_key( wp_unslash( $_GET['ioncoa_view'] ) );
		$views['ioncoa_library_only'] = '<a href="' . esc_url( $library_url ) . '"' . ( $library_current ? ' class="current" aria-current="page"' : '' ) . '>Library Only <span class="count">(' . esc_html( $library_count ) . ')</span></a>';
		return $views;
	}


	/** Preserve the archive tab when searching/filtering inside Consolidated. */
	public function preserve_consolidated_view( $post_type, $which ) {
		if ( 'lab_result' !== $post_type || 'top' !== $which ) {
			return;
		}
		if ( isset( $_GET['ioncoa_view'] ) ) {
			$view = sanitize_key( wp_unslash( $_GET['ioncoa_view'] ) );
			if ( in_array( $view, array( 'consolidated', 'library_only' ), true ) ) {
				echo '<input type="hidden" name="ioncoa_view" value="' . esc_attr( $view ) . '">';
			}
		}
	}

	/** Make archived rows unmistakable when viewing the Consolidated tab. */
	public function display_consolidated_post_state( $states, $post ) {
		if ( $post && 'lab_result' === $post->post_type && self::CONSOLIDATED_STATUS === $post->post_status ) {
			$states['ioncoa_consolidated'] = 'Consolidated';
		}
		if ( $post && 'lab_result' === $post->post_type && 'publish' === $post->post_status && '1' === (string) get_post_meta( $post->ID, self::LIBRARY_ONLY_META, true ) ) {
			$states['ioncoa_library_only'] = 'Library Only';
		}
		return $states;
	}

	private function count_consolidated_posts() {
		$counts = wp_count_posts( 'lab_result' );
		return isset( $counts->{self::CONSOLIDATED_STATUS} ) ? absint( $counts->{self::CONSOLIDATED_STATUS} ) : 0;
	}


	private function count_library_only_posts() {
		$query = new WP_Query(
			array(
				'post_type'      => 'lab_result',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_key'       => self::LIBRARY_ONLY_META,
				'meta_value'     => '1',
			)
		);
		return absint( $query->found_posts );
	}

	public function admin_menu() {
		add_submenu_page(
			'edit.php?post_type=lab_result',
			'COA Safety & Consolidation',
			'COA Safety & Consolidation',
			'edit_products',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function hide_legacy_analyzer() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-lab_result' !== $screen->id ) {
			return;
		}
		echo '<style>button[name="ioncoa_v28_bulk_detect"],input[name="ioncoa_v28_bulk_detect"]{display:none!important;}</style>';
	}

	/**
	 * Remove unrelated SEO/page-script meta boxes from Lab Result edit screens.
	 * This is presentation-only cleanup; it does not change COA batches, mapping,
	 * current-batch state, URLs, or WooCommerce product data.
	 */
	public function cleanup_lab_result_metaboxes() {
		foreach ( array( 'normal', 'side', 'advanced' ) as $context ) {
			remove_meta_box( 'wpseo_meta', 'lab_result', $context );
			remove_meta_box( 'yoast_internal_linking', 'lab_result', $context );
			remove_meta_box( 'wpcode-metabox-snippets', 'lab_result', $context );
		}
	}

	/**
	 * Yoast/WPCode can register boxes late, so remove them again immediately
	 * before WordPress renders each meta-box context.
	 */
	public function cleanup_lab_result_metaboxes_late( $screen_id, $context, $post ) {
		if ( 'lab_result' !== $screen_id ) {
			return;
		}
		remove_meta_box( 'wpseo_meta', 'lab_result', $context );
		remove_meta_box( 'yoast_internal_linking', 'lab_result', $context );
		remove_meta_box( 'wpcode-metabox-snippets', 'lab_result', $context );
	}

	/**
	 * Final visual fallback, and hide the generated permalink row.
	 * Scoped strictly to the lab_result editor so Products/Pages keep Yoast and
	 * WPCode normally.
	 */
	public function lab_result_editor_cleanup_css() {
		$screen = get_current_screen();
		if ( ! $screen || 'lab_result' !== $screen->post_type ) {
			return;
		}
		?>
		<style>
			#edit-slug-box,
			#wpseo_meta,
			#yoast_internal_linking,
			#wpcode-metabox-snippets,
			#yoast-update-premium-notification {
				display:none!important;
			}

			/* Remove their Screen Options toggles too, when a late plugin still adds them. */
			label[for="wpseo_meta-hide"],
			label[for="yoast_internal_linking-hide"],
			label[for="wpcode-metabox-snippets-hide"] {
				display:none!important;
			}
		</style>
		<?php
	}

	public function lab_results_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-lab_result' !== $screen->id || ! current_user_can( 'edit_products' ) ) {
			return;
		}
		$url = admin_url( 'edit.php?post_type=lab_result&page=' . self::MENU_SLUG );
		echo '<div class="notice notice-info"><p><strong>ION COA Safety:</strong> Use <a href="' . esc_url( $url ) . '">COA Safety &amp; Consolidation</a> for mapping cleanup and duplicate consolidation. The older bulk analyzer is hidden while this safety plugin is active.</p></div>';
	}

	private function require_admin_nonce( $action ) {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( 'Permission denied.' );
		}
		check_admin_referer( $action );
	}

	private function redirect_with_result( $result, $args = array() ) {
		$key = self::RESULT_TRANSIENT_PREFIX . get_current_user_id();
		set_transient( $key, $result, 10 * MINUTE_IN_SECONDS );
		$url = admin_url( 'edit.php?post_type=lab_result&page=' . self::MENU_SLUG );
		if ( is_array( $args ) && $args ) {
			$url = add_query_arg( $args, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	private function get_last_result() {
		$key = self::RESULT_TRANSIENT_PREFIX . get_current_user_id();
		$result = get_transient( $key );
		delete_transient( $key );
		return is_array( $result ) ? $result : array();
	}

	private function normalize_name( $value ) {
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$value = strtolower( str_replace( "\xC2\xA0", ' ', $value ) );
		return preg_replace( '/[^a-z0-9]+/', '', $value );
	}

	private function normalize_strength( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9.]+/i', '', (string) $value ) );
	}

	private function split_title( $title ) {
		$title = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$title = str_replace( "\xC2\xA0", ' ', trim( $title ) );
		$dose_pattern = '(?:\d+(?:\.\d+)?\s*(?:(?:mcg|mg|g|ml|iu|units?)\s*)?[\/+]\s*)+\d+(?:\.\d+)?\s*(?:mcg|mg|g|ml|iu|units?)|\d+(?:\.\d+)?\s*(?:mcg|mg|g|ml|iu|units?)';
		if ( preg_match( '/^(.*?)(?:\s*[-–—−]\s*|\s+)(' . $dose_pattern . ')\s*(\(\s*(?:No\s+DAC|With\s+DAC)\s*\))?\s*$/iu', $title, $m ) ) {
			$base = trim( $m[1] );
			if ( ! empty( $m[3] ) && false === stripos( $base, $m[3] ) ) {
				$base .= ' - ' . trim( $m[3] );
			}
			return array( $base, preg_replace( '/\s+/', '', trim( $m[2] ) ) );
		}
		return array( $title, '' );
	}

	private function extract_strength( $value ) {
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$dose_pattern = '(?:\d+(?:\.\d+)?\s*(?:(?:mcg|mg|g|ml|iu|units?)\s*)?[\/+]\s*)+\d+(?:\.\d+)?\s*(?:mcg|mg|g|ml|iu|units?)|\d+(?:\.\d+)?\s*(?:mcg|mg|g|ml|iu|units?)';
		if ( preg_match( '/' . $dose_pattern . '/iu', $value, $m ) ) {
			return preg_replace( '/\s+/', '', trim( $m[0] ) );
		}
		return '';
	}

	private function build_product_index() {
		if ( null !== $this->product_index ) {
			return $this->product_index;
		}
		global $wpdb;
		$this->product_index = array();
		$rows = $wpdb->get_results(
			"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type='product' AND post_status NOT IN ('trash','auto-draft')"
		);
		foreach ( (array) $rows as $row ) {
			$key = $this->normalize_name( $row->post_title );
			if ( $key ) {
				$this->product_index[ $key ][] = absint( $row->ID );
			}
		}
		return $this->product_index;
	}

	private function exact_product_id_by_title( $base_title ) {
		$index = $this->build_product_index();
		$key = $this->normalize_name( $base_title );
		$ids = $key && isset( $index[ $key ] ) ? array_values( array_unique( array_map( 'absint', $index[ $key ] ) ) ) : array();
		return 1 === count( $ids ) ? $ids[0] : 0;
	}

	private function variation_for_strength( $product_id, $strength ) {
		$product_id = absint( $product_id );
		$wanted = $this->normalize_strength( $strength );
		if ( ! $product_id || ! $wanted || ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return 0;
		}
		$matches = array();
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
				continue;
			}

			/* Only auto-map to variations that are actually active/selectable.
			 * Disabled/draft/out-of-catalog legacy variations (for example an old
			 * 15mg option) must remain Needs Review unless an admin maps them manually.
			 */
			if ( 'publish' !== $variation->get_status() ) {
				continue;
			}
			if ( method_exists( $variation, 'variation_is_visible' ) && ! $variation->variation_is_visible() ) {
				continue;
			}
			foreach ( (array) $variation->get_attributes() as $value ) {
				$token = $this->extract_strength( $value );
				if ( $token && $this->normalize_strength( $token ) === $wanted ) {
					$matches[] = absint( $variation_id );
					break;
				}
			}
		}
		$matches = array_values( array_unique( $matches ) );
		return 1 === count( $matches ) ? $matches[0] : 0;
	}

	/**
	 * Validate mappings that were explicitly confirmed by a human/reviewed import
	 * or previously produced by this safety plugin. These saved Product/Variation
	 * IDs are more authoritative than comparing the historical Lab Result title
	 * to today's WooCommerce product name.
	 *
	 * IMPORTANT: legacy URL/title auto-mapping sources from the older COA snippet
	 * are intentionally NOT trusted here.
	 */
	private function validate_trusted_mapping( $post_id, $base_title, $strength ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product_id   = absint( get_post_meta( $post_id, '_ioncoa_product_id', true ) );
		$variation_id = absint( get_post_meta( $post_id, '_ioncoa_variation_id', true ) );
		$source       = sanitize_key( (string) get_post_meta( $post_id, '_ioncoa_mapping_source', true ) );

		$trusted_sources = array(
			'manual',
			'reviewed_import',
			'reviewed_batch_repair',
			'safe_title_variation',
			'safe_title_simple',
			'safe_consolidated',
			'safe_consolidated_group',
		);

		if ( ! $product_id || ! in_array( $source, $trusted_sources, true ) ) {
			return array();
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}

		$variation = false;
		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_type( 'variation' ) || absint( $variation->get_parent_id() ) !== $product_id ) {
				return array();
			}

			/* A reviewed mapping should still point to an active/selectable variation. */
			if ( 'publish' !== $variation->get_status() ) {
				return array();
			}
			if ( method_exists( $variation, 'variation_is_visible' ) && ! $variation->variation_is_visible() ) {
				return array();
			}
		} elseif ( $product->is_type( 'variable' ) ) {
			/* Never trust a variable parent without the exact saved variation. */
			return array();
		}

		$resolved_strength = $strength;
		if ( $variation ) {
			$resolved_strength = $this->extract_strength( implode( ' ', $variation->get_attributes() ) ) ?: $strength;
		}

		$reason = 'Existing trusted Product/Variation ID mapping.';
		if ( 'manual' === $source ) {
			$reason = 'Existing manually confirmed mapping.';
		} elseif ( 'reviewed_import' === $source ) {
			$reason = 'Existing HIGH-confidence reviewed cross-check mapping.';
		}

		return array(
			'safe'         => true,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'strength'     => $resolved_strength,
			'source'       => $source,
			'reason'       => $reason,
		);
	}

	private function safe_candidate( $post_id ) {
		if ( '1' === (string) get_post_meta( $post_id, self::LIBRARY_ONLY_META, true ) ) {
			return array(
				'safe' => false,
				'library_only' => true,
				'reason' => 'Marked Library Only. Kept in the public COA library but intentionally excluded from WooCommerce product pages.',
			);
		}
		$title = get_the_title( $post_id );
		list( $base_title, $strength ) = $this->split_title( $title );

		$trusted = $this->validate_trusted_mapping( $post_id, $base_title, $strength );
		if ( $trusted ) {
			return $trusted;
		}

		$product_id = $this->exact_product_id_by_title( $base_title );
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return array(
				'safe' => false,
				'reason' => 'No unique exact WooCommerce product-title match. URL-only matching is intentionally not trusted.',
				'base_title' => $base_title,
				'strength' => $strength,
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array( 'safe' => false, 'reason' => 'Matched product could not be loaded.' );
		}

		if ( $product->is_type( 'variable' ) ) {
			if ( ! $strength ) {
				return array(
					'safe' => false,
					'reason' => 'Variable WooCommerce product found, but the Lab Result title has no strength.',
					'product_id' => $product_id,
					'base_title' => $base_title,
				);
			}
			$variation_id = $this->variation_for_strength( $product_id, $strength );
			if ( ! $variation_id ) {
				return array(
					'safe' => false,
					'reason' => 'Product matched, but no unique ACTUAL WooCommerce variation matches ' . $strength . '.',
					'product_id' => $product_id,
					'base_title' => $base_title,
					'strength' => $strength,
				);
			}
			return array(
				'safe' => true,
				'product_id' => $product_id,
				'variation_id' => $variation_id,
				'strength' => $strength,
				'source' => 'safe_title_variation',
				'reason' => 'Exact product title + exact actual Woo variation strength.',
			);
		}

		if ( $strength ) {
			return array(
				'safe' => false,
				'reason' => 'The Lab Result includes ' . $strength . ' but the exact WooCommerce product is not variable. Manual review required.',
				'product_id' => $product_id,
				'base_title' => $base_title,
				'strength' => $strength,
			);
		}

		return array(
			'safe' => true,
			'product_id' => $product_id,
			'variation_id' => 0,
			'strength' => '',
			'source' => 'safe_title_simple',
			'reason' => 'Exact simple WooCommerce product-title match.',
		);
	}

	private function get_lab_ids( $statuses = array( 'publish', 'draft', 'private', 'pending', self::CONSOLIDATED_STATUS ) ) {
		return get_posts(
			array(
				'post_type' => 'lab_result',
				'post_status' => $statuses,
				'posts_per_page' => -1,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'suppress_filters' => false,
			)
		);
	}

	private function batch_url( $batch ) {
		$image = trim( (string) ( $batch['image'] ?? '' ) );
		$link = trim( (string) ( $batch['link'] ?? '' ) );
		return $image ?: $link;
	}

	private function batch_date_score( $batch ) {
		$date = trim( (string) ( $batch['date'] ?? '' ) );
		$ts = $date ? strtotime( $date . ' 12:00:00 UTC' ) : false;
		return $ts ? $ts : 0;
	}


	private function current_signature_from_numbers( $current_numbers ) {
		$keys = array_values( array_unique( array_filter( array_map( 'strval', array_keys( (array) $current_numbers ) ) ) ) );
		sort( $keys, SORT_STRING );
		return $keys ? md5( implode( '|', $keys ) ) : '';
	}

	private function group_multi_current_signature( $post_ids ) {
		foreach ( (array) $post_ids as $post_id ) {
			$value = sanitize_text_field( (string) get_post_meta( $post_id, self::MULTI_CURRENT_META, true ) );
			if ( $value ) {
				return $value;
			}
		}
		return '';
	}

	private function set_group_multi_current_signature( $post_ids, $signature ) {
		$signature = sanitize_text_field( (string) $signature );
		foreach ( (array) $post_ids as $post_id ) {
			if ( $signature ) {
				update_post_meta( $post_id, self::MULTI_CURRENT_META, $signature );
			} else {
				delete_post_meta( $post_id, self::MULTI_CURRENT_META );
			}
		}
	}

	private function inspect_group_batches( $post_ids ) {
		$by_batch = array();
		$batch_details = array();
		$all = array();
		$current_keys = array();
		$current_details = array();
		$conflicts = array();

		foreach ( $post_ids as $post_id ) {
			$batches = get_post_meta( $post_id, '_lab_batches', true );
			if ( ! is_array( $batches ) ) {
				continue;
			}
			foreach ( $batches as $index => $batch ) {
				if ( ! is_array( $batch ) || '' === trim( (string) ( $batch['batch'] ?? '' ) ) ) {
					continue;
				}
				$number = trim( (string) $batch['batch'] );
				$number_key = strtolower( preg_replace( '/\s+/', '', $number ) );
				$url = $this->batch_url( $batch );
				$url_key = strtolower( trim( $url ) );
				$fingerprint = $number_key . '|' . $url_key;

				if ( isset( $all[ $fingerprint ] ) ) {
					$existing = $all[ $fingerprint ];
					$existing['current'] = ( ! empty( $existing['current'] ) || ! empty( $batch['current'] ) ) ? 1 : 0;
					foreach ( array( 'date', 'purity', 'mass', 'link', 'image', 'ioncoa_multipart', 'ioncoa_part', 'ioncoa_report_set' ) as $field ) {
						if ( empty( $existing[ $field ] ) && ! empty( $batch[ $field ] ) ) {
							$existing[ $field ] = $batch[ $field ];
						}
					}
					/* A Current row always wins. Otherwise preserve an explicitly-approved historical URL. */
					if ( ! empty( $existing['current'] ) ) {
						unset( $existing['ioncoa_older_approved'] );
					} elseif ( ! empty( $batch['ioncoa_older_approved'] ) ) {
						$existing['ioncoa_older_approved'] = 1;
					}
					$all[ $fingerprint ] = $existing;
				} else {
					$clean_batch = $batch;
					if ( ! empty( $clean_batch['current'] ) ) {
						unset( $clean_batch['ioncoa_older_approved'] );
					}
					$all[ $fingerprint ] = $clean_batch;
				}

				$by_batch[ $number_key ][ $url_key ] = true;
				if ( ! isset( $batch_details[ $number_key ] ) ) {
					$batch_details[ $number_key ] = array(
						'batch' => $number,
						'variants' => array(),
					);
				}
				if ( ! isset( $batch_details[ $number_key ]['variants'][ $url_key ] ) ) {
					$batch_details[ $number_key ]['variants'][ $url_key ] = array(
						'url' => $url,
						'rows' => array(),
					);
				}
				$batch_details[ $number_key ]['variants'][ $url_key ]['rows'][] = array(
					'post_id' => absint( $post_id ),
					'index' => absint( $index ),
					'date' => sanitize_text_field( $batch['date'] ?? '' ),
					'current' => ! empty( $batch['current'] ) ? 1 : 0,
					'purity' => $batch['purity'] ?? '',
					'mass' => $batch['mass'] ?? '',
					'multipart' => ! empty( $batch['ioncoa_multipart'] ) ? 1 : 0,
					'part' => absint( $batch['ioncoa_part'] ?? 0 ),
					'report_set' => sanitize_text_field( $batch['ioncoa_report_set'] ?? '' ),
					'older_approved' => ! empty( $batch['ioncoa_older_approved'] ) ? 1 : 0,
					'same_batch_approved' => ! empty( $batch['ioncoa_same_batch_approved'] ) ? 1 : 0,
				);

				if ( ! empty( $batch['current'] ) ) {
					$current_keys[ $fingerprint ] = $batch;

					/*
					 * v1.6: approved multi-page COAs are one logical Current report.
					 * Group their pages together in the resolver instead of showing Page 1
					 * and Page 2 as separate Current choices.
					 */
					$report_set = sanitize_text_field( $batch['ioncoa_report_set'] ?? '' );
					$is_multipart = ! empty( $batch['ioncoa_multipart'] ) && $report_set;
					$current_key = $is_multipart ? 'set:' . $report_set : $fingerprint;
					if ( ! isset( $current_details[ $current_key ] ) ) {
						$current_details[ $current_key ] = array(
							'batch' => $number,
							'batch_key' => $number_key,
							'url' => $url,
							'urls' => array(),
							'date' => sanitize_text_field( $batch['date'] ?? '' ),
							'posts' => array(),
							'multipart' => $is_multipart ? 1 : 0,
							'report_set' => $report_set,
						);
					}
					if ( $url ) {
						$current_details[ $current_key ]['urls'][ $url ] = true;
					}
					$current_details[ $current_key ]['posts'][] = absint( $post_id );
				}
			}
		}

		$url_conflicts = array();
		foreach ( $by_batch as $number_key => $urls ) {
			$detail = $batch_details[ $number_key ] ?? array( 'batch' => $number_key, 'variants' => array() );
			$variants = array_values(
				array_filter(
					(array) ( $detail['variants'] ?? array() ),
					static function ( $variant ) { return ! empty( $variant['url'] ); }
				)
			);

			/*
			 * STABLE v1.7.1: an administrator can keep one same-batch URL as an
			 * intentional Older Report. It stays in _lab_batches and on the public
			 * historical archive, but it no longer counts as an unresolved URL conflict.
			 */
			$active_variants = array_values(
				array_filter(
					$variants,
					static function ( $variant ) {
						foreach ( (array) ( $variant['rows'] ?? array() ) as $row ) {
							if ( empty( $row['older_approved'] ) ) {
								return true;
							}
						}
						return false;
					}
				)
			);

			$meaningful = array_values( array_unique( array_filter( array_map( static function ( $variant ) {
				return strtolower( trim( (string) ( $variant['url'] ?? '' ) ) );
			}, $active_variants ) ) ) );
			if ( count( $meaningful ) <= 1 ) {
				continue;
			}

			/*
			 * v1.7.2 conservative bulk finish can explicitly preserve several
			 * different files for the same batch without guessing which is best.
			 * Every active variant must be approved before the conflict is cleared.
			 */
			$same_batch_ok = ! empty( $active_variants );
			foreach ( $active_variants as $variant ) {
				$rows = (array) ( $variant['rows'] ?? array() );
				if ( ! $rows ) {
					$same_batch_ok = false;
					break;
				}
				foreach ( $rows as $row ) {
					if ( empty( $row['same_batch_approved'] ) ) {
						$same_batch_ok = false;
						break 2;
					}
				}
			}
			if ( $same_batch_ok ) {
				continue;
			}

			/* Approved multi-page sets are valid multiple URLs for one batch. */
			$multipart_ok = ! empty( $active_variants );
			$report_sets = array();
			foreach ( $active_variants as $variant ) {
				$rows = (array) ( $variant['rows'] ?? array() );
				if ( ! $rows ) {
					$multipart_ok = false;
					break;
				}
				foreach ( $rows as $row ) {
					if ( empty( $row['multipart'] ) || empty( $row['report_set'] ) ) {
						$multipart_ok = false;
						break 2;
					}
					$report_sets[ (string) $row['report_set'] ] = true;
				}
			}
			if ( $multipart_ok && 1 === count( $report_sets ) ) {
				continue;
			}

			$detail['variants'] = $active_variants;
			$conflicts[] = 'Batch ' . $number_key . ' has more than one different report URL.';
			$url_conflicts[ $number_key ] = $detail;
		}

		$current_numbers = array();
		foreach ( $current_keys as $batch ) {
			$current_numbers[ strtolower( preg_replace( '/\s+/', '', (string) ( $batch['batch'] ?? '' ) ) ) ] = true;
		}
		$current_conflict_options = array();
		$multi_current_approved = false;
		$current_signature = $this->current_signature_from_numbers( $current_numbers );
		$approved_signature = $this->group_multi_current_signature( $post_ids );
		if ( count( $current_numbers ) > 1 ) {
			$multi_current_approved = $current_signature && hash_equals( $current_signature, $approved_signature );
			if ( ! $multi_current_approved ) {
				$conflicts[] = 'More than one different batch is marked Current.';
				$current_conflict_options = array_values( $current_details );
				foreach ( $current_conflict_options as &$current_option ) {
					$current_option['urls'] = array_values( array_keys( (array) ( $current_option['urls'] ?? array() ) ) );
					$current_option['posts'] = array_values( array_unique( array_map( 'absint', (array) ( $current_option['posts'] ?? array() ) ) ) );
				}
				unset( $current_option );
			}
		}

		$merged = array_values( $all );
		usort( $merged, function ( $a, $b ) {
			$ac = ! empty( $a['current'] ) ? 1 : 0;
			$bc = ! empty( $b['current'] ) ? 1 : 0;
			if ( $ac !== $bc ) {
				return $bc <=> $ac;
			}
			$ad = $this->batch_date_score( $a );
			$bd = $this->batch_date_score( $b );
			if ( $ad !== $bd ) {
				return $bd <=> $ad;
			}
			return strnatcasecmp( (string) ( $a['batch'] ?? '' ), (string) ( $b['batch'] ?? '' ) );
		} );

		return array(
			'merged' => $merged,
			'conflicts' => $conflicts,
			'url_conflicts' => $url_conflicts,
			'current_conflict_options' => $current_conflict_options,
			'multi_current_approved' => $multi_current_approved,
			'current_signature' => $current_signature,
			'input_count' => array_sum( array_map( 'count', array_map( 'array_keys', $by_batch ) ) ),
			'output_count' => count( $merged ),
		);
	}


	/**
	 * Learn product aliases only from mappings that have already been confirmed by
	 * the safety system/manual review. This is deliberately not fuzzy matching.
	 */
	private function confirmed_alias_index() {
		$index = array();
		foreach ( $this->get_lab_ids( array( 'publish', self::CONSOLIDATED_STATUS ) ) as $post_id ) {
			if ( '1' === (string) get_post_meta( $post_id, self::LIBRARY_ONLY_META, true ) ) {
				continue;
			}
			$product_id = absint( get_post_meta( $post_id, '_ioncoa_product_id', true ) );
			$source = sanitize_key( (string) get_post_meta( $post_id, '_ioncoa_mapping_source', true ) );
			if ( ! $product_id || in_array( $source, array( '', 'manual_none', 'safe_needs_review' ), true ) ) {
				continue;
			}
			list( $base_title ) = $this->split_title( get_the_title( $post_id ) );
			$key = $this->normalize_name( $base_title );
			if ( $key ) {
				$index[ $key ][ $product_id ] = true;
			}
		}
		$out = array();
		foreach ( $index as $key => $products ) {
			$ids = array_values( array_map( 'absint', array_keys( $products ) ) );
			if ( 1 === count( $ids ) ) {
				$out[ $key ] = $ids[0];
			}
		}
		return $out;
	}

	private function conservative_mapping_candidate( $post_id, $alias_index ) {
		$title = get_the_title( $post_id );
		list( $base_title, $strength ) = $this->split_title( $title );
		$product_id = $this->exact_product_id_by_title( $base_title );
		$source = 'bulk_exact_title';
		if ( ! $product_id ) {
			$key = $this->normalize_name( $base_title );
			$product_id = $key && isset( $alias_index[ $key ] ) ? absint( $alias_index[ $key ] ) : 0;
			$source = 'bulk_confirmed_alias';
		}
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}
		if ( $product->is_type( 'variable' ) ) {
			if ( ! $strength ) {
				return array();
			}
			$variation_id = $this->variation_for_strength( $product_id, $strength );
			if ( ! $variation_id ) {
				return array();
			}
			return array(
				'safe' => true,
				'product_id' => $product_id,
				'variation_id' => $variation_id,
				'strength' => $strength,
				'source' => $source,
				'reason' => 'Conservative bulk mapping: confirmed product identity + unique active Woo variation.',
			);
		}

		/* Exact/confirmed product identity on a simple product is enough. A legacy
		 * strength suffix is retained as metadata but does not create a fake variation. */
		return array(
			'safe' => true,
			'product_id' => $product_id,
			'variation_id' => 0,
			'strength' => $strength,
			'source' => $source . '_simple',
			'reason' => 'Conservative bulk mapping to a confirmed simple WooCommerce product.',
		);
	}

	private function obvious_multipart_urls( $urls ) {
		$urls = array_values( array_unique( array_filter( array_map( 'strval', (array) $urls ) ) ) );
		if ( count( $urls ) < 2 || count( $urls ) > 10 ) {
			return false;
		}
		$stems = array();
		$parts = array();
		foreach ( $urls as $url ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$name = strtolower( wp_basename( $path ?: $url ) );
			$name = preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $name );
			if ( ! preg_match( '/^(.*?)(?:[-_ ](?:page|pg|p)?[-_ ]?)(\d{1,2})$/i', $name, $m ) ) {
				return false;
			}
			$stems[] = preg_replace( '/[^a-z0-9]+/', '', $m[1] );
			$parts[] = absint( $m[2] );
		}
		return 1 === count( array_unique( $stems ) ) && count( array_unique( $parts ) ) === count( $parts );
	}

	private function approve_same_batch_variants( $post_ids, $batch_key ) {
		$changed = 0;
		foreach ( (array) $post_ids as $post_id ) {
			$batches = get_post_meta( $post_id, '_lab_batches', true );
			if ( ! is_array( $batches ) ) {
				continue;
			}
			foreach ( $batches as &$batch ) {
				if ( ! is_array( $batch ) || $this->normalize_batch_key( $batch['batch'] ?? '' ) !== $batch_key ) {
					continue;
				}
				$batch['ioncoa_same_batch_approved'] = 1;
				$changed++;
			}
			unset( $batch );
			update_post_meta( $post_id, '_lab_batches', array_values( $batches ) );
		}
		return $changed;
	}

	private function mark_obvious_multipart_preserve_state( $post_ids, $batch_key, $urls, $product_id, $variation_id ) {
		$urls = array_values( array_unique( array_filter( array_map( 'strval', (array) $urls ) ) ) );
		$any_current = false;
		foreach ( (array) $post_ids as $post_id ) {
			$batches = get_post_meta( $post_id, '_lab_batches', true );
			foreach ( (array) $batches as $batch ) {
				if ( is_array( $batch ) && $this->normalize_batch_key( $batch['batch'] ?? '' ) === $batch_key && in_array( $this->batch_url( $batch ), $urls, true ) && ! empty( $batch['current'] ) ) {
					$any_current = true;
				}
			}
		}
		$parts = array();
		foreach ( $urls as $i => $url ) {
			$parts[] = array( 'url' => $url, 'part' => $this->multipart_part_number( $url, $i + 1 ) );
		}
		usort( $parts, static function ( $a, $b ) { return (int) $a['part'] <=> (int) $b['part']; } );
		$map = array();
		foreach ( $parts as $i => $part ) {
			$map[ $part['url'] ] = $i + 1;
		}
		$report_set = 'mp-' . substr( md5( $product_id . ':' . $variation_id . ':' . $batch_key . ':' . implode( '|', $urls ) ), 0, 16 );
		$changed = 0;
		foreach ( (array) $post_ids as $post_id ) {
			$batches = get_post_meta( $post_id, '_lab_batches', true );
			if ( ! is_array( $batches ) ) {
				continue;
			}
			foreach ( $batches as &$batch ) {
				$url = is_array( $batch ) ? $this->batch_url( $batch ) : '';
				if ( ! is_array( $batch ) || $this->normalize_batch_key( $batch['batch'] ?? '' ) !== $batch_key || ! isset( $map[ $url ] ) ) {
					continue;
				}
				$batch['ioncoa_multipart'] = 1;
				$batch['ioncoa_part'] = absint( $map[ $url ] );
				$batch['ioncoa_report_set'] = $report_set;
				$batch['current'] = $any_current ? 1 : 0;
				unset( $batch['ioncoa_same_batch_approved'] );
				$changed++;
			}
			unset( $batch );
			update_post_meta( $post_id, '_lab_batches', array_values( $batches ) );
		}
		return $changed;
	}

	private function conservative_finish_plan() {
		$analysis = $this->analyze();
		$alias_index = $this->confirmed_alias_index();
		$plan = array(
			'map' => array(),
			'library_only' => array(),
			'multipart' => array(),
			'approve_same_batch' => array(),
			'approve_multi_current' => array(),
		);
		foreach ( (array) $analysis['needs_review'] as $row ) {
			$post_id = absint( $row['post_id'] );
			$candidate = $this->conservative_mapping_candidate( $post_id, $alias_index );
			if ( $candidate ) {
				$plan['map'][ $post_id ] = $candidate;
			} else {
				$plan['library_only'][ $post_id ] = true;
			}
		}
		foreach ( (array) $analysis['conflict_groups'] as $key => $group ) {
			$inspection = (array) ( $group['batch_inspection'] ?? array() );
			foreach ( (array) ( $inspection['url_conflicts'] ?? array() ) as $batch_key => $detail ) {
				$urls = array_values( array_unique( array_filter( array_map( static function ( $variant ) {
					return (string) ( $variant['url'] ?? '' );
				}, (array) ( $detail['variants'] ?? array() ) ) ) ) );
				$entry = array(
					'group_key' => $key,
					'product_id' => absint( $group['product_id'] ),
					'variation_id' => absint( $group['variation_id'] ),
					'post_ids' => array_values( array_map( 'absint', $group['post_ids'] ) ),
					'batch_key' => (string) $batch_key,
					'urls' => $urls,
				);
				if ( $this->obvious_multipart_urls( $urls ) ) {
					$plan['multipart'][] = $entry;
				} else {
					$plan['approve_same_batch'][] = $entry;
				}
			}
			if ( ! empty( $inspection['current_conflict_options'] ) && ! empty( $inspection['current_signature'] ) ) {
				$plan['approve_multi_current'][] = array(
					'group_key' => $key,
					'post_ids' => array_values( array_map( 'absint', $group['post_ids'] ) ),
					'signature' => sanitize_text_field( (string) $inspection['current_signature'] ),
				);
			}
		}
		return $plan;
	}

	private function apply_conservative_finish( $backup_id ) {
		$plan = $this->conservative_finish_plan();
		$stats = array(
			'backup_id' => $backup_id,
			'mapped' => 0,
			'library_only' => 0,
			'multipart' => 0,
			'same_batch_preserved' => 0,
			'multi_current_approved' => 0,
		);
		foreach ( $plan['map'] as $post_id => $candidate ) {
			delete_post_meta( $post_id, self::LIBRARY_ONLY_META );
			$this->save_safe_mapping( $post_id, $candidate, 'Conservative bulk finish mapping. Backup ' . $backup_id . '.' );
			$stats['mapped']++;
		}
		foreach ( array_keys( $plan['library_only'] ) as $post_id ) {
			delete_post_meta( $post_id, '_ioncoa_product_id' );
			delete_post_meta( $post_id, '_ioncoa_variation_id' );
			delete_post_meta( $post_id, '_ioncoa_strength' );
			update_post_meta( $post_id, '_ioncoa_mapping_source', 'manual_none' );
			update_post_meta( $post_id, '_ioncoa_mapping_confidence', 'confirmed' );
			update_post_meta( $post_id, '_ioncoa_mapping_status', 'Library Only by conservative bulk finish. No ambiguous Woo mapping was guessed. Backup ' . $backup_id . '.' );
			update_post_meta( $post_id, self::LIBRARY_ONLY_META, '1' );
			$stats['library_only']++;
		}
		foreach ( $plan['multipart'] as $entry ) {
			$this->mark_obvious_multipart_preserve_state( $entry['post_ids'], $entry['batch_key'], $entry['urls'], $entry['product_id'], $entry['variation_id'] );
			$stats['multipart']++;
		}
		foreach ( $plan['approve_same_batch'] as $entry ) {
			$this->approve_same_batch_variants( $entry['post_ids'], $entry['batch_key'] );
			$stats['same_batch_preserved']++;
		}
		foreach ( $plan['approve_multi_current'] as $entry ) {
			$this->set_group_multi_current_signature( $entry['post_ids'], $entry['signature'] );
			$stats['multi_current_approved']++;
		}
		$stats['consolidation'] = $this->consolidate( $backup_id );
		$stats['remaining'] = $this->analyze();
		return $stats;
	}


	/**
	 * STABLE v1.7.7 — emergency strength-integrity guard.
	 *
	 * Consolidation groups posts by saved Woo Product/Variation IDs. That is
	 * normally correct, but legacy Lab Result rows can contain a COA file whose
	 * filename explicitly says a different mass (for example a 10mg report
	 * sitting inside a 50mg record). Consolidation faithfully preserves that bad
	 * legacy row, which can make the public library appear to mix strengths.
	 *
	 * These helpers use ONLY explicit mass tokens in the actual report filename
	 * (mg/mcg/g), never fuzzy product-name guessing. A move is proposed only when
	 * the token matches exactly one ACTIVE variation of the same Woo product.
	 */
	private function explicit_mass_tokens( $value ) {
		$value = rawurldecode( html_entity_decode( (string) $value, ENT_QUOTES, get_bloginfo( 'charset' ) ) );
		$tokens = array();
		if ( preg_match_all( '/(?<![a-z0-9])(\d+(?:\.\d+)?)\s*(mcg|mg|g)\b/i', $value, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$number = rtrim( rtrim( (string) $m[1], '0' ), '.' );
				if ( '' === $number ) { $number = '0'; }
				$tokens[] = strtolower( $number . $m[2] );
			}
		}
		return array_values( array_unique( $tokens ) );
	}

	private function batch_mass_evidence( $batch ) {
		if ( ! is_array( $batch ) ) {
			return array();
		}
		$tokens = array();
		foreach ( array( 'image', 'link' ) as $field ) {
			$url = trim( (string) ( $batch[ $field ] ?? '' ) );
			if ( ! $url ) { continue; }
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$name = wp_basename( $path ?: $url );
			$tokens = array_merge( $tokens, $this->explicit_mass_tokens( $name ) );
		}
		/* Batch numbers are used only if they literally include a unit such as 10mg. */
		$tokens = array_merge( $tokens, $this->explicit_mass_tokens( (string) ( $batch['batch'] ?? '' ) ) );
		return array_values( array_unique( array_filter( $tokens ) ) );
	}

	private function variation_mass_signatures( $variation ) {
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return array();
		}
		$signatures = array();
		foreach ( (array) $variation->get_attributes() as $value ) {
			$text = rawurldecode( (string) $value );
			/* Blend labels like 10/10mg should match explicit 20mg total evidence,
			 * but should NOT pretend the whole variation is a plain 10mg product. */
			if ( preg_match( '/(\d+(?:\.\d+)?)\s*[\/+]\s*(\d+(?:\.\d+)?)\s*(mcg|mg|g)\b/i', $text, $m ) ) {
				$total = (float) $m[1] + (float) $m[2];
				$total_s = rtrim( rtrim( number_format( $total, 6, '.', '' ), '0' ), '.' );
				$signatures[] = strtolower( $total_s . $m[3] );
				continue;
			}
			$tokens = $this->explicit_mass_tokens( $text );
			if ( 1 === count( $tokens ) ) {
				$signatures[] = $tokens[0];
			}
		}
		return array_values( array_unique( array_filter( $signatures ) ) );
	}

	private function active_variation_mass_index( $product_id ) {
		$product_id = absint( $product_id );
		$index = array();
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return $index;
		}
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_type( 'variation' ) || 'publish' !== $variation->get_status() ) {
				continue;
			}
			if ( method_exists( $variation, 'variation_is_visible' ) && ! $variation->variation_is_visible() ) {
				continue;
			}
			foreach ( $this->variation_mass_signatures( $variation ) as $signature ) {
				$index[ $signature ][] = absint( $variation_id );
			}
		}
		foreach ( $index as $signature => $ids ) {
			$index[ $signature ] = array_values( array_unique( array_map( 'absint', $ids ) ) );
		}
		return $index;
	}

	private function published_target_post_for_variation( $product_id, $variation_id, $exclude_post_id = 0 ) {
		$ids = get_posts( array(
			'post_type' => 'lab_result',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'no_found_rows' => true,
			'meta_query' => array(
				'relation' => 'AND',
				array( 'key' => '_ioncoa_product_id', 'value' => absint( $product_id ), 'compare' => '=' ),
				array( 'key' => '_ioncoa_variation_id', 'value' => absint( $variation_id ), 'compare' => '=' ),
			),
		) );
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ), static function( $id ) use ( $exclude_post_id ) { return $id && $id !== absint( $exclude_post_id ); } ) );
		if ( ! $ids ) { return 0; }
		/* Prefer the already-consolidated canonical record, then the lowest ID. */
		usort( $ids, function( $a, $b ) {
			$as = sanitize_key( (string) get_post_meta( $a, '_ioncoa_mapping_source', true ) );
			$bs = sanitize_key( (string) get_post_meta( $b, '_ioncoa_mapping_source', true ) );
			$ap = 0 === strpos( $as, 'safe_consolidated' ) ? 0 : 1;
			$bp = 0 === strpos( $bs, 'safe_consolidated' ) ? 0 : 1;
			return $ap === $bp ? ( $a <=> $b ) : ( $ap <=> $bp );
		} );
		return absint( $ids[0] );
	}

	private function batch_fingerprint( $batch ) {
		return $this->normalize_batch_key( $batch['batch'] ?? '' ) . '|' . strtolower( trim( $this->batch_url( $batch ) ) );
	}

	private function strength_integrity_plan() {
		$plan = array( 'moves' => array(), 'ambiguous' => array(), 'checked_posts' => 0, 'checked_batches' => 0 );
		foreach ( $this->get_lab_ids( array( 'publish' ) ) as $post_id ) {
			if ( '1' === (string) get_post_meta( $post_id, self::LIBRARY_ONLY_META, true ) ) { continue; }
			$product_id = absint( get_post_meta( $post_id, '_ioncoa_product_id', true ) );
			$variation_id = absint( get_post_meta( $post_id, '_ioncoa_variation_id', true ) );
			if ( ! $product_id || ! $variation_id ) { continue; }
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
			$current_variation = function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ) : false;
			if ( ! $product || ! $product->is_type( 'variable' ) || ! $current_variation || ! $current_variation->is_type( 'variation' ) ) { continue; }
			$mass_index = $this->active_variation_mass_index( $product_id );
			$current_signatures = $this->variation_mass_signatures( $current_variation );
			if ( ! $mass_index || ! $current_signatures ) { continue; }
			$batches = get_post_meta( $post_id, '_lab_batches', true );
			if ( ! is_array( $batches ) ) { continue; }
			$plan['checked_posts']++;
			foreach ( $batches as $index => $batch ) {
				if ( ! is_array( $batch ) ) { continue; }
				$plan['checked_batches']++;
				$evidence = $this->batch_mass_evidence( $batch );
				if ( ! $evidence ) { continue; }
				/* If any explicit filename token agrees with the mapped variation, leave it alone. */
				if ( array_intersect( $evidence, $current_signatures ) ) { continue; }
				$candidates = array();
				foreach ( $evidence as $token ) {
					foreach ( (array) ( $mass_index[ $token ] ?? array() ) as $candidate_variation ) {
						if ( absint( $candidate_variation ) !== $variation_id ) { $candidates[ absint( $candidate_variation ) ] = true; }
					}
				}
				$candidate_ids = array_keys( $candidates );
				if ( 1 !== count( $candidate_ids ) ) {
					if ( $candidate_ids ) {
						$plan['ambiguous'][] = array( 'post_id' => $post_id, 'batch' => $batch['batch'] ?? '', 'url' => $this->batch_url( $batch ), 'evidence' => $evidence, 'candidate_variations' => $candidate_ids );
					}
					continue;
				}
				$target_variation_id = absint( $candidate_ids[0] );
				$target_post_id = $this->published_target_post_for_variation( $product_id, $target_variation_id, $post_id );
				if ( ! $target_post_id ) {
					$plan['ambiguous'][] = array( 'post_id' => $post_id, 'batch' => $batch['batch'] ?? '', 'url' => $this->batch_url( $batch ), 'evidence' => $evidence, 'candidate_variations' => array( $target_variation_id ), 'reason' => 'No published Lab Result exists for the correct target variation.' );
					continue;
				}
				$plan['moves'][] = array(
					'source_post_id' => absint( $post_id ),
					'source_variation_id' => absint( $variation_id ),
					'target_post_id' => absint( $target_post_id ),
					'target_variation_id' => $target_variation_id,
					'product_id' => $product_id,
					'batch_index' => absint( $index ),
					'fingerprint' => $this->batch_fingerprint( $batch ),
					'batch' => $batch,
					'evidence' => $evidence,
				);
			}
		}
		return $plan;
	}

	private function apply_strength_integrity_repair( $backup_id ) {
		$plan = $this->strength_integrity_plan();
		$moved = 0;
		$deduped = 0;
		$skipped = 0;
		/* Process by source post from highest batch index down so removals are stable. */
		$by_source = array();
		foreach ( $plan['moves'] as $move ) { $by_source[ $move['source_post_id'] ][] = $move; }
		foreach ( $by_source as $source_post_id => $moves ) {
			usort( $moves, static function( $a, $b ){ return (int) $b['batch_index'] <=> (int) $a['batch_index']; } );
			$source_batches = get_post_meta( $source_post_id, '_lab_batches', true );
			if ( ! is_array( $source_batches ) ) { $skipped += count( $moves ); continue; }
			foreach ( $moves as $move ) {
				$idx = absint( $move['batch_index'] );
				if ( ! isset( $source_batches[ $idx ] ) || $this->batch_fingerprint( $source_batches[ $idx ] ) !== $move['fingerprint'] ) { $skipped++; continue; }
				$target_post_id = absint( $move['target_post_id'] );
				if ( 'publish' !== get_post_status( $target_post_id ) ) { $skipped++; continue; }
				$target_batches = get_post_meta( $target_post_id, '_lab_batches', true );
				$target_batches = is_array( $target_batches ) ? array_values( $target_batches ) : array();
				$exists = false;
				foreach ( $target_batches as $tb ) {
					if ( is_array( $tb ) && $this->batch_fingerprint( $tb ) === $move['fingerprint'] ) { $exists = true; break; }
				}
				$batch = $source_batches[ $idx ];
				$batch['ioncoa_strength_repaired_from_post'] = absint( $source_post_id );
				$batch['ioncoa_strength_repair_backup'] = sanitize_text_field( $backup_id );
				if ( ! $exists ) { $target_batches[] = $batch; $moved++; } else { $deduped++; }
				array_splice( $source_batches, $idx, 1 );
				update_post_meta( $target_post_id, '_lab_batches', array_values( $target_batches ) );
			}
			update_post_meta( $source_post_id, '_lab_batches', array_values( $source_batches ) );
		}
		return array( 'backup_id' => $backup_id, 'moved' => $moved, 'deduped' => $deduped, 'skipped' => $skipped, 'ambiguous' => count( $plan['ambiguous'] ), 'plan' => $plan );
	}

	public function handle_strength_audit() {
		$this->require_admin_nonce( 'ioncoa_safe_strength_audit' );
		$plan = $this->strength_integrity_plan();
		$this->redirect_with_result( array( 'type' => 'strength_audit', 'plan' => $plan ) );
	}

	public function handle_strength_repair() {
		$this->require_admin_nonce( 'ioncoa_safe_strength_repair' );
		$this->redirect_with_result( array( 'type' => 'error', 'message' => 'The legacy filename-only MG repair is disabled in v1.7.9 because the full forensic audit proved filenames can be misleading. Use the Reviewed Forensic Batch Repair CSV instead.' ) );
	}

	private function analyze() {
		$ids = $this->get_lab_ids( array( 'publish' ) );
		$groups = array();
		$review = array();
		$safe_count = 0;
		$library_only_count = 0;

		foreach ( $ids as $post_id ) {
			$candidate = $this->safe_candidate( $post_id );
			if ( ! empty( $candidate['library_only'] ) ) {
				$library_only_count++;
				continue;
			}
			if ( empty( $candidate['safe'] ) ) {
				$review[] = array(
					'post_id' => $post_id,
					'title' => get_the_title( $post_id ),
					'reason' => $candidate['reason'] ?? 'Needs review.',
				);
				continue;
			}
			$safe_count++;
			$key = absint( $candidate['product_id'] ) . ':' . absint( $candidate['variation_id'] );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'product_id' => absint( $candidate['product_id'] ),
					'variation_id' => absint( $candidate['variation_id'] ),
					'strength' => $candidate['strength'] ?? '',
					'post_ids' => array(),
					'sources' => array(),
				);
			}
			$groups[ $key ]['post_ids'][] = $post_id;
			$groups[ $key ]['sources'][ $post_id ] = $candidate['source'] ?? '';
		}

		$duplicate_groups = array();
		$conflict_groups = array();
		foreach ( $groups as $key => $group ) {
			if ( count( $group['post_ids'] ) < 2 ) {
				continue;
			}
			$inspection = $this->inspect_group_batches( $group['post_ids'] );
			/* Emergency guard: never consolidate a group while any explicit COA filename
			 * mass token points to a different active variation of the same product. */
			$mass_index = $this->active_variation_mass_index( $group['product_id'] );
			$current_variation = $group['variation_id'] ? wc_get_product( $group['variation_id'] ) : false;
			$current_signatures = $current_variation ? $this->variation_mass_signatures( $current_variation ) : array();
			foreach ( (array) $group['post_ids'] as $guard_post_id ) {
				foreach ( (array) get_post_meta( $guard_post_id, '_lab_batches', true ) as $guard_batch ) {
					if ( ! is_array( $guard_batch ) ) { continue; }
					$evidence = $this->batch_mass_evidence( $guard_batch );
					if ( ! $evidence || array_intersect( $evidence, $current_signatures ) ) { continue; }
					$other = array();
					foreach ( $evidence as $token ) { foreach ( (array) ( $mass_index[ $token ] ?? array() ) as $vid ) { if ( absint( $vid ) !== absint( $group['variation_id'] ) ) { $other[ absint( $vid ) ] = true; } } }
					if ( $other ) { $inspection['conflicts'][] = 'Strength-integrity guard: batch ' . sanitize_text_field( $guard_batch['batch'] ?? '' ) . ' has an explicit COA filename strength that belongs to a different active Woo variation.'; break 2; }
				}
			}
			$inspection['conflicts'] = array_values( array_unique( (array) $inspection['conflicts'] ) );
			$group['batch_inspection'] = $inspection;
			if ( ! empty( $inspection['conflicts'] ) ) {
				$conflict_groups[ $key ] = $group;
			} else {
				$duplicate_groups[ $key ] = $group;
			}
		}

		return array(
			'total_published' => count( $ids ),
			'library_only_count' => $library_only_count,
			'safe_mappable' => $safe_count,
			'needs_review' => $review,
			'groups' => $groups,
			'duplicate_groups' => $duplicate_groups,
			'conflict_groups' => $conflict_groups,
		);
	}

	private function snapshot_meta_keys() {
		return array(
			'_lab_batches',
			'_ioncoa_product_id',
			'_ioncoa_variation_id',
			'_ioncoa_strength',
			'_ioncoa_mapping_source',
			'_ioncoa_mapping_confidence',
			'_ioncoa_mapping_status',
			'_ioncoa_consolidated_into',
			'_ioncoa_consolidated_backup_id',
			self::LIBRARY_ONLY_META,
			self::MULTI_CURRENT_META,
		);
	}

	private function create_backup( $label = 'Pre-consolidation backup' ) {
		$ids = $this->get_lab_ids( array( 'publish', 'draft', 'private', 'pending', self::CONSOLIDATED_STATUS, 'trash' ) );
		$backup_id = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false );
		$snapshot = array(
			'backup_id' => $backup_id,
			'created_gmt' => gmdate( 'c' ),
			'label' => $label,
			'plugin_version' => self::VERSION,
			'site_url' => site_url(),
			'posts' => array(),
		);
		$keys = $this->snapshot_meta_keys();
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$entry = array(
				'ID' => $post_id,
				'post_status' => $post->post_status,
				'post_title' => $post->post_title,
				'meta' => array(),
			);
			foreach ( $keys as $key ) {
				$entry['meta'][ $key ] = get_post_meta( $post_id, $key, false );
			}
			$snapshot['posts'][ $post_id ] = $entry;
		}
		$option_key = 'ioncoa_safe_backup_' . sanitize_key( $backup_id );
		add_option( $option_key, $snapshot, '', false );
		$index = get_option( self::BACKUP_INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}
		$index[ $backup_id ] = array(
			'option_key' => $option_key,
			'created_gmt' => $snapshot['created_gmt'],
			'label' => $label,
			'posts' => count( $snapshot['posts'] ),
		);
		update_option( self::BACKUP_INDEX_OPTION, $index, false );
		return $backup_id;
	}

	private function get_backup( $backup_id ) {
		$backup_id = sanitize_text_field( (string) $backup_id );
		$index = get_option( self::BACKUP_INDEX_OPTION, array() );
		if ( ! is_array( $index ) || empty( $index[ $backup_id ]['option_key'] ) ) {
			return array();
		}
		$data = get_option( $index[ $backup_id ]['option_key'], array() );
		return is_array( $data ) ? $data : array();
	}

	private function restore_backup( $backup_id ) {
		$backup = $this->get_backup( $backup_id );
		if ( empty( $backup['posts'] ) || ! is_array( $backup['posts'] ) ) {
			return new WP_Error( 'missing_backup', 'Backup could not be loaded.' );
		}
		$restored = 0;
		foreach ( $backup['posts'] as $post_id => $entry ) {
			$post_id = absint( $post_id );
			if ( ! $post_id || ! get_post( $post_id ) ) {
				continue;
			}
			wp_update_post( array( 'ID' => $post_id, 'post_status' => sanitize_key( $entry['post_status'] ?? 'draft' ) ) );
			foreach ( $this->snapshot_meta_keys() as $key ) {
				delete_post_meta( $post_id, $key );
				$values = isset( $entry['meta'][ $key ] ) && is_array( $entry['meta'][ $key ] ) ? $entry['meta'][ $key ] : array();
				foreach ( $values as $value ) {
					add_post_meta( $post_id, $key, $value );
				}
			}
			$restored++;
		}
		return $restored;
	}

	private function choose_canonical( $post_ids ) {
		$manual = array();
		$current = array();
		foreach ( $post_ids as $post_id ) {
			$mapping_source = sanitize_key( (string) get_post_meta( $post_id, '_ioncoa_mapping_source', true ) );
			if ( in_array( $mapping_source, array( 'manual', 'reviewed_import', 'reviewed_batch_repair' ), true ) ) {
				$manual[] = $post_id;
			}
			$batches = get_post_meta( $post_id, '_lab_batches', true );
			if ( is_array( $batches ) ) {
				foreach ( $batches as $batch ) {
					if ( is_array( $batch ) && ! empty( $batch['current'] ) ) {
						$current[] = $post_id;
						break;
					}
			}
			}
		}
		$candidates = $manual ?: ( $current ?: $post_ids );
		sort( $candidates, SORT_NUMERIC );
		return absint( $candidates[0] ?? 0 );
	}

	private function save_safe_mapping( $post_id, $candidate, $status = '' ) {
		update_post_meta( $post_id, '_ioncoa_product_id', absint( $candidate['product_id'] ?? 0 ) );
		update_post_meta( $post_id, '_ioncoa_variation_id', absint( $candidate['variation_id'] ?? 0 ) );
		update_post_meta( $post_id, '_ioncoa_strength', sanitize_text_field( $candidate['strength'] ?? '' ) );
		update_post_meta( $post_id, '_ioncoa_mapping_source', sanitize_key( $candidate['source'] ?? 'safe_title_variation' ) );
		update_post_meta( $post_id, '_ioncoa_mapping_confidence', 'confirmed' );
		update_post_meta( $post_id, '_ioncoa_mapping_status', sanitize_text_field( $status ?: ( $candidate['reason'] ?? 'Safe mapping confirmed.' ) ) );
	}

	private function consolidate( $backup_id ) {
		$analysis = $this->analyze();
		$mapped = 0;
		$consolidated_groups = 0;
		$drafted_duplicates = 0;
		$skipped_conflicts = count( $analysis['conflict_groups'] );
		$corrected_unsafe = 0;

		// First, write only safe mappings. Unsafe automatic URL mappings are cleared.
		foreach ( $this->get_lab_ids( array( 'publish' ) ) as $post_id ) {
			$candidate = $this->safe_candidate( $post_id );
			if ( ! empty( $candidate['library_only'] ) ) {
				continue;
			}
			if ( ! empty( $candidate['safe'] ) ) {
				$before_product = absint( get_post_meta( $post_id, '_ioncoa_product_id', true ) );
				$before_variation = absint( get_post_meta( $post_id, '_ioncoa_variation_id', true ) );
				$this->save_safe_mapping( $post_id, $candidate );
				$mapped++;
				if ( $before_product && ( $before_product !== absint( $candidate['product_id'] ) || $before_variation !== absint( $candidate['variation_id'] ) ) ) {
					$corrected_unsafe++;
				}
			} else {
				$source = sanitize_key( (string) get_post_meta( $post_id, '_ioncoa_mapping_source', true ) );
				if ( ! in_array( $source, array( 'manual', 'reviewed_import', 'reviewed_batch_repair' ), true ) && ( get_post_meta( $post_id, '_ioncoa_product_id', true ) || get_post_meta( $post_id, '_ioncoa_variation_id', true ) ) ) {
					delete_post_meta( $post_id, '_ioncoa_product_id' );
					delete_post_meta( $post_id, '_ioncoa_variation_id' );
					delete_post_meta( $post_id, '_ioncoa_strength' );
					update_post_meta( $post_id, '_ioncoa_mapping_source', 'safe_needs_review' );
					update_post_meta( $post_id, '_ioncoa_mapping_confidence', 'none' );
					update_post_meta( $post_id, '_ioncoa_mapping_status', sanitize_text_field( $candidate['reason'] ?? 'Unsafe automatic mapping cleared.' ) );
					$corrected_unsafe++;
				}
			}
		}

		// Re-analyze after safe mappings are written.
		$analysis = $this->analyze();
		foreach ( $analysis['duplicate_groups'] as $group ) {
			$post_ids = array_values( array_map( 'absint', $group['post_ids'] ) );
			$inspection = $group['batch_inspection'];
			if ( ! empty( $inspection['conflicts'] ) ) {
				continue;
			}
			$canonical = $this->choose_canonical( $post_ids );
			if ( ! $canonical ) {
				continue;
			}
			update_post_meta( $canonical, '_lab_batches', array_values( $inspection['merged'] ) );
			$this->save_safe_mapping(
				$canonical,
				array(
					'product_id' => $group['product_id'],
					'variation_id' => $group['variation_id'],
					'strength' => $group['strength'],
					'source' => 'safe_consolidated',
					'reason' => 'Safely consolidated duplicate Lab Result records after backup.',
				),
				'Safely consolidated duplicate Lab Result records. Backup ' . $backup_id . '.'
			);
			foreach ( $post_ids as $post_id ) {
				if ( $post_id === $canonical ) {
					continue;
				}
				update_post_meta( $post_id, '_ioncoa_consolidated_into', $canonical );
				update_post_meta( $post_id, '_ioncoa_consolidated_backup_id', $backup_id );
				wp_update_post( array( 'ID' => $post_id, 'post_status' => self::CONSOLIDATED_STATUS ) );
				$drafted_duplicates++;
			}
			$consolidated_groups++;
		}

		return array(
			'backup_id' => $backup_id,
			'mapped_records' => $mapped,
			'corrected_unsafe_mappings' => $corrected_unsafe,
			'consolidated_groups' => $consolidated_groups,
			'drafted_duplicates' => $drafted_duplicates,
			'skipped_conflict_groups' => $skipped_conflicts,
			'needs_review_count' => count( $analysis['needs_review'] ),
		);
	}


	/**
	 * Consolidate exactly one already-safe Product/Variation group.
	 * Nothing outside the selected group is mapped, merged, drafted, or changed.
	 */
	private function consolidate_one_group( $product_id, $variation_id, $backup_id ) {
		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		$analysis     = $this->analyze();
		$key          = $product_id . ':' . $variation_id;

		if ( empty( $analysis['duplicate_groups'][ $key ] ) ) {
			return new WP_Error( 'group_not_safe', 'That Product/Variation group is no longer in the Safe duplicate groups list. Run Dry Analysis again before changing anything.' );
		}

		$group      = $analysis['duplicate_groups'][ $key ];
		$post_ids   = array_values( array_map( 'absint', $group['post_ids'] ) );
		$inspection = $this->inspect_group_batches( $post_ids );

		if ( count( $post_ids ) < 2 ) {
			return new WP_Error( 'not_duplicate', 'This group no longer contains duplicate Lab Result posts.' );
		}
		if ( ! empty( $inspection['conflicts'] ) ) {
			return new WP_Error( 'group_conflict', 'The group now has a conflict and was not changed: ' . implode( ' ', $inspection['conflicts'] ) );
		}

		/* Revalidate every post at execution time. */
		foreach ( $post_ids as $post_id ) {
			$candidate = $this->safe_candidate( $post_id );
			if ( empty( $candidate['safe'] ) || absint( $candidate['product_id'] ?? 0 ) !== $product_id || absint( $candidate['variation_id'] ?? 0 ) !== $variation_id ) {
				return new WP_Error( 'mapping_changed', 'At least one Lab Result no longer resolves safely to the selected WooCommerce Product/Variation. Nothing was changed.' );
			}
		}

		$canonical = $this->choose_canonical( $post_ids );
		if ( ! $canonical ) {
			return new WP_Error( 'no_canonical', 'A canonical Lab Result could not be selected. Nothing was changed.' );
		}

		/* Only this selected group receives mapping writes. */
		foreach ( $post_ids as $post_id ) {
			$candidate = $this->safe_candidate( $post_id );
			$this->save_safe_mapping( $post_id, $candidate, 'Safe group mapping confirmed before targeted consolidation. Backup ' . $backup_id . '.' );
		}

		update_post_meta( $canonical, '_lab_batches', array_values( $inspection['merged'] ) );
		$this->save_safe_mapping(
			$canonical,
			array(
				'product_id'   => $group['product_id'],
				'variation_id' => $group['variation_id'],
				'strength'     => $group['strength'],
				'source'       => 'safe_consolidated_group',
				'reason'       => 'Safely consolidated one selected duplicate Lab Result group after backup.',
			),
			'Safely consolidated selected duplicate group. Backup ' . $backup_id . '.'
		);

		$drafted = 0;
		foreach ( $post_ids as $post_id ) {
			if ( $post_id === $canonical ) {
				continue;
			}
			update_post_meta( $post_id, '_ioncoa_consolidated_into', $canonical );
			update_post_meta( $post_id, '_ioncoa_consolidated_backup_id', $backup_id );
			wp_update_post( array( 'ID' => $post_id, 'post_status' => self::CONSOLIDATED_STATUS ) );
			$drafted++;
		}

		return array(
			'backup_id'      => $backup_id,
			'product_id'     => $product_id,
			'variation_id'   => $variation_id,
			'canonical'      => $canonical,
			'posts_merged'   => count( $post_ids ),
			'batches_merged' => count( $inspection['merged'] ),
			'drafted'        => $drafted,
			'label'          => $this->product_label( $product_id, $variation_id ),
		);
	}

	public function handle_consolidate_group() {
		$this->require_admin_nonce( 'ioncoa_safe_consolidate_group' );
		$product_id   = absint( $_POST['product_id'] ?? 0 );
		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		if ( ! $product_id ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'Missing WooCommerce Product ID.' ) );
		}

		$label = $this->product_label( $product_id, $variation_id );
		$backup_id = $this->create_backup( 'Targeted pre-consolidation backup: ' . $label );
		$result = $this->consolidate_one_group( $product_id, $variation_id, $backup_id );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_result( array(
				'type'      => 'group_error',
				'message'   => $result->get_error_message(),
				'backup_id' => $backup_id,
			) );
		}
		$this->redirect_with_result( array( 'type' => 'group_consolidated', 'result' => $result ) );
	}


	public function handle_consolidate_selected() {
		$this->require_admin_nonce( 'ioncoa_safe_consolidate_selected' );

		$raw = isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] )
			? wp_unslash( $_POST['selected_groups'] )
			: array();

		$selected = array();
		foreach ( $raw as $value ) {
			$value = sanitize_text_field( (string) $value );
			if ( ! preg_match( '/^(\d+):(\d+)$/', $value, $m ) ) {
				continue;
			}
			$product_id   = absint( $m[1] );
			$variation_id = absint( $m[2] );
			if ( $product_id ) {
				$selected[ $product_id . ':' . $variation_id ] = array( $product_id, $variation_id );
			}
		}

		if ( ! $selected ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'No safe duplicate groups were selected.' ) );
		}

		/* One full backup protects the complete selected batch and keeps the backup list manageable. */
		$backup_id = $this->create_backup( 'Selected-groups pre-consolidation backup (' . count( $selected ) . ' groups)' );
		$done = array();
		$errors = array();
		$total_posts = 0;
		$total_batches = 0;
		$total_archived = 0;

		foreach ( $selected as $key => $ids ) {
			list( $product_id, $variation_id ) = $ids;
			$result = $this->consolidate_one_group( $product_id, $variation_id, $backup_id );
			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'label'   => $this->product_label( $product_id, $variation_id ),
					'message' => $result->get_error_message(),
				);
				continue;
			}
			$done[] = $result;
			$total_posts += absint( $result['posts_merged'] ?? 0 );
			$total_batches += absint( $result['batches_merged'] ?? 0 );
			$total_archived += absint( $result['drafted'] ?? 0 );
		}

		$this->redirect_with_result(
			array(
				'type' => 'selected_groups_consolidated',
				'result' => array(
					'backup_id'       => $backup_id,
					'selected_count'  => count( $selected ),
					'completed_count' => count( $done ),
					'failed_count'    => count( $errors ),
					'posts_merged'    => $total_posts,
					'batches_merged'  => $total_batches,
					'archived'        => $total_archived,
					'completed'       => $done,
					'errors'          => $errors,
				),
			)
		);
	}


	public function handle_preview_finish() {
		$this->require_admin_nonce( 'ioncoa_safe_preview_finish' );
		$plan = $this->conservative_finish_plan();
		$this->redirect_with_result(
			array(
				'type' => 'finish_preview',
				'plan_counts' => array(
					'map' => count( $plan['map'] ),
					'library_only' => count( $plan['library_only'] ),
					'multipart' => count( $plan['multipart'] ),
					'approve_same_batch' => count( $plan['approve_same_batch'] ),
					'approve_multi_current' => count( $plan['approve_multi_current'] ),
				),
			)
		);
	}

	public function handle_bulk_finish() {
		$this->require_admin_nonce( 'ioncoa_safe_bulk_finish' );
		$backup_id = $this->create_backup( 'Conservative bulk-finish backup' );
		$result = $this->apply_conservative_finish( $backup_id );
		$this->redirect_with_result( array( 'type' => 'finish_applied', 'result' => $result ) );
	}

	public function handle_dry_run() {
		$this->require_admin_nonce( 'ioncoa_safe_dry_run' );
		$analysis = $this->analyze();
		$this->redirect_with_result( array( 'type' => 'analysis', 'analysis' => $analysis ) );
	}

	public function handle_backup_only() {
		$this->require_admin_nonce( 'ioncoa_safe_backup_only' );
		$backup_id = $this->create_backup( 'Manual backup only' );
		$this->redirect_with_result( array( 'type' => 'backup', 'backup_id' => $backup_id ) );
	}

	public function handle_consolidate() {
		$this->require_admin_nonce( 'ioncoa_safe_consolidate' );
		$backup_id = $this->create_backup( 'Automatic pre-consolidation backup' );
		$result = $this->consolidate( $backup_id );
		$this->redirect_with_result( array( 'type' => 'consolidated', 'result' => $result ) );
	}

	public function handle_rollback() {
		$this->require_admin_nonce( 'ioncoa_safe_rollback' );
		$backup_id = sanitize_text_field( wp_unslash( $_POST['backup_id'] ?? '' ) );
		$result = $this->restore_backup( $backup_id );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => $result->get_error_message() ) );
		}
		$this->redirect_with_result( array( 'type' => 'rollback', 'backup_id' => $backup_id, 'restored' => $result ) );
	}

	public function handle_download_backup() {
		$this->require_admin_nonce( 'ioncoa_safe_download_backup' );
		$backup_id = sanitize_text_field( wp_unslash( $_GET['backup_id'] ?? '' ) );
		$backup = $this->get_backup( $backup_id );
		if ( ! $backup ) {
			wp_die( 'Backup not found.' );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ioncoa-backup-' . sanitize_file_name( $backup_id ) . '.json"' );
		echo wp_json_encode( $backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	private function normalize_batch_key( $value ) {
		return strtolower( preg_replace( '/\s+/', '', trim( (string) $value ) ) );
	}

	private function get_conflict_group( $product_id, $variation_id ) {
		$analysis = $this->analyze();
		$key = absint( $product_id ) . ':' . absint( $variation_id );
		return ! empty( $analysis['conflict_groups'][ $key ] ) ? $analysis['conflict_groups'][ $key ] : array();
	}

	private function resolve_woo_item( $item_id, $fallback_strength = '' ) {
		$item_id = absint( $item_id );
		if ( ! $item_id || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$item = wc_get_product( $item_id );
		if ( ! $item ) {
			return array();
		}

		$product_id = $item_id;
		$variation_id = 0;
		$strength = $this->extract_strength( $fallback_strength );
		$label = $item->get_name();
		if ( $item->is_type( 'variation' ) ) {
			$variation_id = $item_id;
			$product_id = absint( $item->get_parent_id() );
			$parent = wc_get_product( $product_id );
			$attrs = array_values( array_filter( array_map( 'strval', $item->get_attributes() ) ) );
			foreach ( $attrs as $attr ) {
				if ( ! $strength ) {
					$strength = $this->extract_strength( $attr );
				}
			}
			$label = $parent ? $parent->get_name() : $item->get_name();
			if ( $attrs ) {
				$label .= ' — ' . implode( ' / ', $attrs );
			}
		}

		$edit_url = get_edit_post_link( $product_id, '' );
		return array(
			'item_id' => $item_id,
			'product_id' => $product_id,
			'variation_id' => $variation_id,
			'strength' => $strength,
			'label' => wp_strip_all_tags( $label ),
			'edit_url' => $edit_url ? $edit_url : admin_url( 'post.php?post=' . $product_id . '&action=edit' ),
			'is_variable_parent' => $item->is_type( 'variable' ),
		);
	}

	public function ajax_product_search() {
		check_ajax_referer( 'ioncoa_safe_review_search', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		global $wpdb;
		$q = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		if ( strlen( trim( $q ) ) < 2 ) {
			wp_send_json_success( array() );
		}
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$numeric_id = ctype_digit( trim( $q ) ) ? absint( $q ) : 0;
		if ( $numeric_id ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_parent, post_type, post_title
					 FROM {$wpdb->posts}
					 WHERE post_type IN ('product','product_variation')
					   AND post_status NOT IN ('trash','auto-draft')
					   AND (post_title LIKE %s OR ID = %d OR post_parent = %d)
					 ORDER BY CASE WHEN ID = %d THEN 0 WHEN post_parent = %d THEN 1 ELSE 2 END, post_type ASC, post_title ASC
					 LIMIT 35",
					$like, $numeric_id, $numeric_id, $numeric_id, $numeric_id
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_parent, post_type, post_title
					 FROM {$wpdb->posts}
					 WHERE post_type IN ('product','product_variation')
					   AND post_status NOT IN ('trash','auto-draft')
					   AND post_title LIKE %s
					 ORDER BY post_type ASC, post_title ASC
					 LIMIT 35",
					$like
				)
			);
		}
		$out = array();
		foreach ( (array) $rows as $row ) {
			$resolved = $this->resolve_woo_item( $row->ID );
			if ( ! $resolved ) {
				continue;
			}
			$id_label = $resolved['variation_id']
				? 'Variation #' . $resolved['variation_id'] . ' | Product #' . $resolved['product_id']
				: 'Product #' . $resolved['product_id'];
			$out[] = array(
				'id' => absint( $row->ID ),
				'text' => '[' . $id_label . '] ' . $resolved['label'],
				'label' => $resolved['label'],
				'product_id' => $resolved['product_id'],
				'variation_id' => $resolved['variation_id'],
				'edit_url' => $resolved['edit_url'],
			);
		}
		wp_send_json_success( $out );
	}

	public function handle_resolve_url_conflict() {
		$this->require_admin_nonce( 'ioncoa_safe_resolve_url_conflict' );
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		$batch_key = sanitize_text_field( wp_unslash( $_POST['batch_key'] ?? '' ) );
		$preferred_url = esc_url_raw( trim( wp_unslash( $_POST['preferred_url'] ?? '' ) ) );
		$group = $this->get_conflict_group( $product_id, $variation_id );
		if ( ! $group || ! $batch_key || ! $preferred_url ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'That conflict is no longer available. Run Dry Analysis again.' ) );
		}
		$inspection = $this->inspect_group_batches( $group['post_ids'] );
		if ( empty( $inspection['url_conflicts'][ $batch_key ] ) ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'The selected batch no longer has a URL conflict.' ) );
		}
		$allowed_urls = array_map( static function ( $variant ) { return (string) ( $variant['url'] ?? '' ); }, $inspection['url_conflicts'][ $batch_key ]['variants'] );
		if ( ! in_array( $preferred_url, $allowed_urls, true ) ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'The selected report URL is no longer one of the current conflict options.' ) );
		}

		$label = $this->product_label( $product_id, $variation_id );
		$backup_id = $this->create_backup( 'Conflict resolution backup: ' . $label . ' / batch ' . $batch_key );
		$changed = 0;
		foreach ( $group['post_ids'] as $post_id ) {
			$batches = get_post_meta( $post_id, '_lab_batches', true );
			if ( ! is_array( $batches ) ) {
				continue;
			}
			$new_batches = array();
			foreach ( $batches as $batch ) {
				if ( ! is_array( $batch ) ) {
					continue;
				}
				$current_key = $this->normalize_batch_key( $batch['batch'] ?? '' );
				if ( $current_key === $batch_key ) {
					$url = $this->batch_url( $batch );
					if ( $url && $url !== $preferred_url ) {
						$changed++;
						continue;
					}
				}
				$new_batches[] = $batch;
			}
			update_post_meta( $post_id, '_lab_batches', array_values( $new_batches ) );
		}
		$this->redirect_with_result(
			array(
				'type' => 'conflict_resolved',
				'message' => 'Kept the selected COA URL for batch ' . $batch_key . ' and removed ' . $changed . ' conflicting batch row(s).',
				'backup_id' => $backup_id,
			),
			array( 'resolve_product_id' => $product_id, 'resolve_variation_id' => $variation_id )
		);
	}



	/**
	 * Keep one conflicting URL as a historical/Older Report instead of deleting it.
	 * This uses the same normal POST + page reload flow as the stable resolver.
	 */
	public function handle_mark_url_older() {
		$this->require_admin_nonce( 'ioncoa_safe_mark_url_older' );
		$product_id   = absint( $_POST['product_id'] ?? 0 );
		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		$batch_key    = sanitize_text_field( wp_unslash( $_POST['batch_key'] ?? '' ) );
		$selected_url = esc_url_raw( trim( wp_unslash( $_POST['selected_url'] ?? '' ) ) );
		$group        = $this->get_conflict_group( $product_id, $variation_id );

		if ( ! $group || ! $batch_key || ! $selected_url ) {
			$this->redirect_with_result(
				array( 'type' => 'error', 'message' => 'That URL conflict is no longer available.' ),
				array( 'resolve_product_id' => $product_id, 'resolve_variation_id' => $variation_id )
			);
		}

		$inspection = $this->inspect_group_batches( $group['post_ids'] );
		$detail = (array) ( $inspection['url_conflicts'][ $batch_key ] ?? array() );
		$allowed_urls = array_values( array_filter( array_map( static function ( $variant ) {
			return esc_url_raw( trim( (string) ( $variant['url'] ?? '' ) ) );
		}, (array) ( $detail['variants'] ?? array() ) ) ) );
		if ( ! in_array( $selected_url, $allowed_urls, true ) ) {
			$this->redirect_with_result(
				array( 'type' => 'error', 'message' => 'That report URL is no longer one of the unresolved options.' ),
				array( 'resolve_product_id' => $product_id, 'resolve_variation_id' => $variation_id )
			);
		}

		$label = $this->product_label( $product_id, $variation_id );
		$backup_id = $this->create_backup( 'Keep URL as Older Report backup: ' . $label . ' / batch ' . $batch_key );
		$this->set_group_multi_current_signature( $group['post_ids'], '' );
		$changed = 0;
		foreach ( $group['post_ids'] as $post_id ) {
			$batches = get_post_meta( $post_id, '_lab_batches', true );
			if ( ! is_array( $batches ) ) {
				continue;
			}
			foreach ( $batches as &$batch ) {
				if ( ! is_array( $batch ) ) {
					continue;
				}
				if ( $this->normalize_batch_key( $batch['batch'] ?? '' ) !== $batch_key || $this->batch_url( $batch ) !== $selected_url ) {
					continue;
				}
				$batch['current'] = 0;
				$batch['ioncoa_older_approved'] = 1;
				unset( $batch['ioncoa_multipart'], $batch['ioncoa_part'], $batch['ioncoa_report_set'] );
				$changed++;
			}
			unset( $batch );
			update_post_meta( $post_id, '_lab_batches', array_values( $batches ) );
		}

		$this->redirect_with_result(
			array(
				'type' => 'conflict_resolved',
				'message' => 'Kept the selected COA for batch ' . $batch_key . ' as an Older Report. The URL and report data were preserved; Current was unchecked (' . $changed . ' row(s)).',
				'backup_id' => $backup_id,
			),
			array( 'resolve_product_id' => $product_id, 'resolve_variation_id' => $variation_id )
		);
	}

	private function multipart_part_number( $url, $fallback = 0 ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = wp_basename( $path ?: $url );
		/* Common patterns: report-1.jpg, report_2.png, page-1.pdf, pg2.jpg. */
		if ( preg_match( '/(?:^|[-_])(page|pg|p)?[-_ ]?(\d{1,2})(?=\.[a-z0-9]{2,5}$)/i', $name, $m ) ) {
			return max( 1, absint( $m[2] ) );
		}
		return max( 1, absint( $fallback ) );
	}

	public function handle_keep_multipart() {
		$this->require_admin_nonce( 'ioncoa_safe_keep_multipart' );
		$product_id  = absint( $_POST['product_id'] ?? 0 );
		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		$batch_key   = sanitize_text_field( wp_unslash( $_POST['batch_key'] ?? '' ) );
		$group       = $this->get_conflict_group( $product_id, $variation_id );

		if ( ! $group || ! $batch_key ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'That multi-page conflict is no longer available. Run Dry Analysis again.' ) );
		}

		$inspection = $this->inspect_group_batches( $group['post_ids'] );
		$detail = $inspection['url_conflicts'][ $batch_key ] ?? array();
		$variants = (array) ( $detail['variants'] ?? array() );
		if ( count( $variants ) < 2 ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'This batch no longer has multiple report URLs.' ) );
		}

		$urls = array_values( array_unique( array_filter( array_map( static function ( $variant ) {
			return esc_url_raw( trim( (string) ( $variant['url'] ?? '' ) ) );
		}, $variants ) ) ) );
		if ( count( $urls ) < 2 ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'This batch no longer has enough report pages to create a multi-page set.' ) );
		}

		/* Stable page ordering: detected trailing page number first, then URL. */
		$parts = array();
		foreach ( $urls as $i => $url ) {
			$parts[] = array( 'url' => $url, 'part' => $this->multipart_part_number( $url, $i + 1 ) );
		}
		usort( $parts, static function ( $a, $b ) {
			if ( (int) $a['part'] !== (int) $b['part'] ) {
				return (int) $a['part'] <=> (int) $b['part'];
			}
			return strcmp( (string) $a['url'], (string) $b['url'] );
		} );
		$url_to_part = array();
		foreach ( $parts as $i => $part ) {
			$url_to_part[ (string) $part['url'] ] = $i + 1;
		}

		$label = $this->product_label( $product_id, $variation_id );
		$backup_id = $this->create_backup( 'Multi-page COA backup: ' . $label . ' / batch ' . $batch_key );
		$report_set = 'mp-' . substr( md5( $product_id . ':' . $variation_id . ':' . $batch_key . ':' . implode( '|', $urls ) ), 0, 16 );
		$changed = 0;

		foreach ( $group['post_ids'] as $post_id ) {
			$batches = get_post_meta( $post_id, '_lab_batches', true );
			if ( ! is_array( $batches ) ) {
				continue;
			}
			foreach ( $batches as &$batch ) {
				if ( ! is_array( $batch ) || $this->normalize_batch_key( $batch['batch'] ?? '' ) !== $batch_key ) {
					continue;
				}
				$url = $this->batch_url( $batch );
				if ( ! $url || ! isset( $url_to_part[ $url ] ) ) {
					continue;
				}
				$batch['ioncoa_multipart'] = 1;
				$batch['ioncoa_part'] = absint( $url_to_part[ $url ] );
				$batch['ioncoa_report_set'] = $report_set;
				/* Every page belongs to the same Current verified batch. */
				$batch['current'] = 1;
				$changed++;
			}
			unset( $batch );
			update_post_meta( $post_id, '_lab_batches', array_values( $batches ) );
		}

		$this->redirect_with_result(
			array(
				'type' => 'conflict_resolved',
				'message' => 'Kept all ' . count( $urls ) . ' report pages together for batch ' . $batch_key . ' and marked the full multi-page batch Current. No COA URL was deleted.',
				'backup_id' => $backup_id,
			),
			array( 'resolve_product_id' => $product_id, 'resolve_variation_id' => $variation_id )
		);
	}

	public function handle_set_current() {
		$this->require_admin_nonce( 'ioncoa_safe_set_current' );
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		$selected_batch = sanitize_text_field( wp_unslash( $_POST['selected_batch'] ?? '' ) );
		$selected_report_set = sanitize_text_field( wp_unslash( $_POST['selected_report_set'] ?? '' ) );
		$group = $this->get_conflict_group( $product_id, $variation_id );
		if ( ! $group || ! $selected_batch ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'That Current-batch conflict is no longer available. Run Dry Analysis again.' ) );
		}
		$inspection = $this->inspect_group_batches( $group['post_ids'] );
		$options = $inspection['current_conflict_options'] ?? array();
		$valid = false;
		foreach ( $options as $option ) {
			if ( $this->normalize_batch_key( $option['batch'] ?? '' ) !== $this->normalize_batch_key( $selected_batch ) ) {
				continue;
			}
			$option_set = sanitize_text_field( $option['report_set'] ?? '' );
			if ( $selected_report_set && $option_set !== $selected_report_set ) {
				continue;
			}
			$valid = true;
			break;
		}
		if ( ! $valid ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'The selected Current batch is no longer one of the conflicting options.' ) );
		}
		$label = $this->product_label( $product_id, $variation_id );
		$backup_id = $this->create_backup( 'Current-batch resolution backup: ' . $label );
		$this->set_group_multi_current_signature( $group['post_ids'], '' );
		$selected_key = $this->normalize_batch_key( $selected_batch );
		foreach ( $group['post_ids'] as $post_id ) {
			$batches = get_post_meta( $post_id, '_lab_batches', true );
			if ( ! is_array( $batches ) ) {
				continue;
			}
			foreach ( $batches as &$batch ) {
				if ( ! is_array( $batch ) ) {
					continue;
				}
				/* Current is a batch-level concept. This preserves every approved page
				 * of a multi-page COA when that batch is selected. */
				$match = $this->normalize_batch_key( $batch['batch'] ?? '' ) === $selected_key;
				$batch['current'] = $match ? 1 : 0;
			}
			unset( $batch );
			update_post_meta( $post_id, '_lab_batches', array_values( $batches ) );
		}
		$this->redirect_with_result(
			array(
				'type' => 'conflict_resolved',
				'message' => 'Set batch ' . $selected_batch . ' as the only Current batch for ' . $label . '. Multi-page COA pages were preserved together.',
				'backup_id' => $backup_id,
			),
			array( 'resolve_product_id' => $product_id, 'resolve_variation_id' => $variation_id )
		);
	}


	/**
	 * Keep the COA row but move the selected Current batch into Older Reports.
	 * For approved multi-page COAs every page in the batch is demoted together.
	 */
	public function handle_mark_older() {
		$this->require_admin_nonce( 'ioncoa_safe_mark_older' );
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		$selected_batch = sanitize_text_field( wp_unslash( $_POST['selected_batch'] ?? '' ) );
		$selected_report_set = sanitize_text_field( wp_unslash( $_POST['selected_report_set'] ?? '' ) );
		$group = $this->get_conflict_group( $product_id, $variation_id );
		if ( ! $group || ! $selected_batch ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'That Current-batch conflict is no longer available. Run Dry Analysis again.' ) );
		}

		$inspection = $this->inspect_group_batches( $group['post_ids'] );
		$options = (array) ( $inspection['current_conflict_options'] ?? array() );
		$valid = false;
		foreach ( $options as $option ) {
			if ( $this->normalize_batch_key( $option['batch'] ?? '' ) !== $this->normalize_batch_key( $selected_batch ) ) {
				continue;
			}
			$option_set = sanitize_text_field( $option['report_set'] ?? '' );
			if ( $selected_report_set && $option_set !== $selected_report_set ) {
				continue;
			}
			$valid = true;
			break;
		}
		if ( ! $valid ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'The selected Current batch is no longer one of the conflicting options.' ) );
		}

		$label = $this->product_label( $product_id, $variation_id );
		$backup_id = $this->create_backup( 'Move COA to Older Reports backup: ' . $label . ' / batch ' . $selected_batch );
		$this->set_group_multi_current_signature( $group['post_ids'], '' );
		$selected_key = $this->normalize_batch_key( $selected_batch );
		$changed = 0;
		foreach ( $group['post_ids'] as $post_id ) {
			$batches = get_post_meta( $post_id, '_lab_batches', true );
			if ( ! is_array( $batches ) ) {
				continue;
			}
			foreach ( $batches as &$batch ) {
				if ( ! is_array( $batch ) ) {
					continue;
				}
				if ( $this->normalize_batch_key( $batch['batch'] ?? '' ) === $selected_key && ! empty( $batch['current'] ) ) {
					$batch['current'] = 0;
					$changed++;
				}
			}
			unset( $batch );
			update_post_meta( $post_id, '_lab_batches', array_values( $batches ) );
		}

		$this->redirect_with_result(
			array(
				'type' => 'conflict_resolved',
				'message' => 'Moved batch ' . $selected_batch . ' to Older Reports for ' . $label . '. The COA file and batch data were kept; only Current was unchecked (' . $changed . ' row(s)). A backup was created first.',
				'backup_id' => $backup_id,
			),
			array( 'resolve_product_id' => $product_id, 'resolve_variation_id' => $variation_id )
		);
	}

	/**
	 * Explicitly approve multiple DIFFERENT batches as Current for this exact
	 * Product/Variation. The approval is signature-based; if the Current set later
	 * changes, Dry Analysis flags it again for review automatically.
	 */
	public function handle_keep_all_current() {
		$this->require_admin_nonce( 'ioncoa_safe_keep_all_current' );
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		$group = $this->get_conflict_group( $product_id, $variation_id );
		if ( ! $group ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'That Current-batch conflict is no longer available. Run Dry Analysis again.' ) );
		}
		$inspection = $this->inspect_group_batches( $group['post_ids'] );
		$options = (array) ( $inspection['current_conflict_options'] ?? array() );
		$signature = sanitize_text_field( (string) ( $inspection['current_signature'] ?? '' ) );
		if ( count( $options ) < 2 || ! $signature ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'There are no longer multiple Current batches to approve. Run Dry Analysis again.' ) );
		}
		$label = $this->product_label( $product_id, $variation_id );
		$backup_id = $this->create_backup( 'Approve multiple Current batches backup: ' . $label );
		$this->set_group_multi_current_signature( $group['post_ids'], $signature );
		$this->redirect_with_result(
			array(
				'type' => 'conflict_resolved',
				'message' => 'Approved all ' . count( $options ) . ' different batches as Current for ' . $label . '. No COA was removed or demoted. If the Current set changes later, the conflict will return automatically.',
				'backup_id' => $backup_id,
			),
			array( 'resolve_product_id' => $product_id, 'resolve_variation_id' => $variation_id )
		);
	}

	/**
	 * Remove one unwanted COA from a Current-batch conflict. If the selected
	 * option is an approved multi-page report, the whole report set is removed
	 * together so an administrator cannot accidentally leave Page 2 without Page 1.
	 */
	public function handle_remove_current_report() {
		$this->require_admin_nonce( 'ioncoa_safe_remove_current_report' );
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		$selected_batch = sanitize_text_field( wp_unslash( $_POST['selected_batch'] ?? '' ) );
		$selected_url = esc_url_raw( trim( wp_unslash( $_POST['selected_url'] ?? '' ) ) );
		$selected_report_set = sanitize_text_field( wp_unslash( $_POST['selected_report_set'] ?? '' ) );
		$group = $this->get_conflict_group( $product_id, $variation_id );
		if ( ! $group || ! $selected_batch ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'That Current-batch conflict is no longer available. Run Dry Analysis again.' ) );
		}

		$inspection = $this->inspect_group_batches( $group['post_ids'] );
		$options = (array) ( $inspection['current_conflict_options'] ?? array() );
		$valid = false;
		foreach ( $options as $option ) {
			if ( $this->normalize_batch_key( $option['batch'] ?? '' ) !== $this->normalize_batch_key( $selected_batch ) ) {
				continue;
			}
			$option_set = sanitize_text_field( $option['report_set'] ?? '' );
			if ( $selected_report_set ) {
				if ( $option_set === $selected_report_set ) {
					$valid = true;
					break;
				}
			} else {
				$urls = (array) ( $option['urls'] ?? array() );
				if ( $selected_url && ( (string) ( $option['url'] ?? '' ) === $selected_url || in_array( $selected_url, $urls, true ) ) ) {
					$valid = true;
					break;
				}
			}
		}
		if ( ! $valid ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'The selected COA is no longer one of the Current conflict options.' ) );
		}

		$label = $this->product_label( $product_id, $variation_id );
		$backup_id = $this->create_backup( 'Remove Current COA backup: ' . $label . ' / batch ' . $selected_batch );
		$selected_key = $this->normalize_batch_key( $selected_batch );
		$removed = 0;
		foreach ( $group['post_ids'] as $post_id ) {
			$batches = get_post_meta( $post_id, '_lab_batches', true );
			if ( ! is_array( $batches ) ) {
				continue;
			}
			$new_batches = array();
			foreach ( $batches as $batch ) {
				if ( ! is_array( $batch ) ) {
					continue;
				}
				$remove = false;
				if ( $selected_report_set ) {
					$remove = sanitize_text_field( $batch['ioncoa_report_set'] ?? '' ) === $selected_report_set;
				} else {
					$remove = $this->normalize_batch_key( $batch['batch'] ?? '' ) === $selected_key && $this->batch_url( $batch ) === $selected_url;
				}
				if ( $remove ) {
					$removed++;
					continue;
				}
				$new_batches[] = $batch;
			}
			update_post_meta( $post_id, '_lab_batches', array_values( $new_batches ) );
		}

		$this->redirect_with_result(
			array(
				'type' => 'conflict_resolved',
				'message' => $selected_report_set
					? 'Removed the full multi-page COA set for batch ' . $selected_batch . ' (' . $removed . ' page row(s)). A backup was created first.'
					: 'Removed the selected COA for batch ' . $selected_batch . ' (' . $removed . ' row(s)). A backup was created first.',
				'backup_id' => $backup_id,
			),
			array( 'resolve_product_id' => $product_id, 'resolve_variation_id' => $variation_id )
		);
	}

	private function same_title_strength_posts( $post_id ) {
		$post_id = absint( $post_id );
		list( $base, $strength ) = $this->split_title( get_the_title( $post_id ) );
		$base_key = $this->normalize_name( $base );
		$strength_key = $this->normalize_strength( $strength );
		$matches = array();
		foreach ( $this->get_lab_ids( array( 'publish' ) ) as $candidate_id ) {
			if ( '1' === (string) get_post_meta( $candidate_id, self::LIBRARY_ONLY_META, true ) ) {
				continue;
			}
			list( $candidate_base, $candidate_strength ) = $this->split_title( get_the_title( $candidate_id ) );
			if ( $this->normalize_name( $candidate_base ) === $base_key && $this->normalize_strength( $candidate_strength ) === $strength_key ) {
				$matches[] = absint( $candidate_id );
			}
		}
		return array_values( array_unique( $matches ) );
	}

	public function handle_save_review_mapping() {
		$this->require_admin_nonce( 'ioncoa_safe_save_review_mapping' );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$item_id = absint( $_POST['item_id'] ?? 0 );
		$apply_same = ! empty( $_POST['apply_same_title_strength'] );
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'lab_result' !== $post->post_type || ! $item_id ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'Missing Lab Result or WooCommerce selection.' ) );
		}
		list( , $title_strength ) = $this->split_title( $post->post_title );
		$resolved = $this->resolve_woo_item( $item_id, $title_strength );
		if ( ! $resolved ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'The selected WooCommerce item could not be loaded.' ), array( 'review_post_id' => $post_id ) );
		}
		if ( ! empty( $resolved['is_variable_parent'] ) ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'That is a variable parent product. Select the exact WooCommerce variation instead.' ), array( 'review_post_id' => $post_id ) );
		}

		$targets = $apply_same ? $this->same_title_strength_posts( $post_id ) : array( $post_id );
		$backup_id = $this->create_backup( 'Manual review mapping backup: ' . get_the_title( $post_id ) );
		$mapped = 0;
		foreach ( $targets as $target_id ) {
			delete_post_meta( $target_id, self::LIBRARY_ONLY_META );
			update_post_meta( $target_id, '_ioncoa_product_id', absint( $resolved['product_id'] ) );
			update_post_meta( $target_id, '_ioncoa_variation_id', absint( $resolved['variation_id'] ) );
			update_post_meta( $target_id, '_ioncoa_strength', sanitize_text_field( $resolved['strength'] ?: $title_strength ) );
			update_post_meta( $target_id, '_ioncoa_mapping_source', 'manual' );
			update_post_meta( $target_id, '_ioncoa_mapping_confidence', 'confirmed' );
			update_post_meta( $target_id, '_ioncoa_mapping_status', 'Manually confirmed in COA Safety Review. Backup ' . $backup_id . '.' );
			$mapped++;
		}
		$this->redirect_with_result(
			array(
				'type' => 'review_mapped',
				'message' => 'Mapped ' . $mapped . ' Lab Result record(s) to ' . $resolved['label'] . '.',
				'backup_id' => $backup_id,
			),
			array( 'review_post_id' => $post_id )
		);
	}

	public function handle_library_only() {
		$this->require_admin_nonce( 'ioncoa_safe_library_only' );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$apply_same = ! empty( $_POST['apply_same_title_strength'] );
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'lab_result' !== $post->post_type ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'Lab Result not found.' ) );
		}
		$targets = $apply_same ? $this->same_title_strength_posts( $post_id ) : array( $post_id );
		$backup_id = $this->create_backup( 'Library Only review backup: ' . get_the_title( $post_id ) );
		$changed = 0;
		foreach ( $targets as $target_id ) {
			delete_post_meta( $target_id, '_ioncoa_product_id' );
			delete_post_meta( $target_id, '_ioncoa_variation_id' );
			delete_post_meta( $target_id, '_ioncoa_strength' );
			update_post_meta( $target_id, '_ioncoa_mapping_source', 'manual_none' );
			update_post_meta( $target_id, '_ioncoa_mapping_confidence', 'confirmed' );
			update_post_meta( $target_id, '_ioncoa_mapping_status', 'Library Only: intentionally excluded from WooCommerce product pages. Backup ' . $backup_id . '.' );
			update_post_meta( $target_id, self::LIBRARY_ONLY_META, '1' );
			$changed++;
		}
		$this->redirect_with_result(
			array(
				'type' => 'library_only',
				'message' => 'Marked ' . $changed . ' Lab Result record(s) Library Only. They remain published in the COA library but are excluded from Woo product pages.',
				'backup_id' => $backup_id,
			),
			array( 'review_post_id' => $post_id )
		);
	}

	public function handle_return_to_review() {
		$this->require_admin_nonce( 'ioncoa_safe_return_to_review' );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'lab_result' !== $post->post_type ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'Lab Result not found.' ) );
		}
		$backup_id = $this->create_backup( 'Return Library Only to review: ' . get_the_title( $post_id ) );
		delete_post_meta( $post_id, self::LIBRARY_ONLY_META );
		update_post_meta( $post_id, '_ioncoa_mapping_source', 'safe_needs_review' );
		update_post_meta( $post_id, '_ioncoa_mapping_confidence', 'none' );
		update_post_meta( $post_id, '_ioncoa_mapping_status', 'Returned from Library Only to mapping review. Backup ' . $backup_id . '.' );
		$this->redirect_with_result(
			array( 'type' => 'review_returned', 'message' => 'Returned this Lab Result to the mapping review queue.', 'backup_id' => $backup_id ),
			array( 'review_post_id' => $post_id )
		);
	}

	private function product_label( $product_id, $variation_id = 0 ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '#' . absint( $product_id );
		}
		$product = wc_get_product( $product_id );
		$label = $product ? $product->get_name() : ( 'Product #' . absint( $product_id ) );
		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				$attrs = array_values( array_filter( array_map( 'strval', $variation->get_attributes() ) ) );
				$label .= ' — ' . ( $attrs ? implode( ' / ', $attrs ) : 'Variation #' . $variation_id );
			}
		}
		return $label;
	}

	private function backups_index() {
		$index = get_option( self::BACKUP_INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			return array();
		}
		uksort( $index, static function ( $a, $b ) { return strcmp( $b, $a ); } );
		return $index;
	}

	private function safety_page_url( $args = array() ) {
		$url = admin_url( 'edit.php?post_type=lab_result&page=' . self::MENU_SLUG );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	private function render_conflict_resolver_panel( $product_id, $variation_id ) {
		$product_id = absint( $product_id );
		$variation_id = absint( $variation_id );
		$group = $this->get_conflict_group( $product_id, $variation_id );
		echo '<div class="ioncoa-review-panel">';
		echo '<h2>Conflict Resolver: ' . esc_html( $this->product_label( $product_id, $variation_id ) ) . '</h2>';
		echo '<p><a href="' . esc_url( $this->safety_page_url() ) . '">&larr; Back to Safety Overview</a></p>';
		if ( ! $group ) {
			echo '<div class="notice notice-success inline"><p>This group is no longer in the conflict list. Run Dry Analysis again; it may now be ready for safe consolidation.</p></div></div>';
			return;
		}
		$inspection = $this->inspect_group_batches( $group['post_ids'] );
		echo '<p><strong>Woo mapping:</strong> Product #' . esc_html( $product_id ) . ( $variation_id ? ' / Variation #' . esc_html( $variation_id ) : '' ) . '<br><strong>Lab Result posts:</strong> ' . esc_html( implode( ', ', $group['post_ids'] ) ) . '</p>';
		echo '<div class="notice notice-warning inline"><p>Every resolver button below creates a fresh full backup <strong>before</strong> changing the conflicting batch rows.</p></div>';

		foreach ( (array) ( $inspection['url_conflicts'] ?? array() ) as $batch_key => $detail ) {
			echo '<div class="ioncoa-conflict-card"><h3>Batch ' . esc_html( $detail['batch'] ?? $batch_key ) . ': conflicting report URLs</h3>';
			echo '<p>Open the reports and decide what each file represents. Keep one as the sole report, or move a valid older file to Older Reports. Every action creates a fresh backup first.</p>';
			foreach ( (array) ( $detail['variants'] ?? array() ) as $variant ) {
				$url = (string) ( $variant['url'] ?? '' );
				if ( ! $url ) { continue; }
				$posts = array();
				$dates = array();
				$current = false;
				foreach ( (array) ( $variant['rows'] ?? array() ) as $row ) {
					$posts[] = absint( $row['post_id'] ?? 0 );
					if ( ! empty( $row['date'] ) ) { $dates[] = $row['date']; }
					if ( ! empty( $row['current'] ) ) { $current = true; }
				}
				echo '<div class="ioncoa-option-row"><div><strong>' . esc_html( wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ?: $url ) ) . '</strong>' . ( $current ? ' <span class="ioncoa-current-chip">CURRENT</span>' : '' ) . '<br><code>' . esc_html( $url ) . '</code><br><small>Source posts: ' . esc_html( implode( ', ', array_unique( array_filter( $posts ) ) ) ) . ( $dates ? ' &nbsp; | &nbsp; Dates: ' . esc_html( implode( ', ', array_unique( $dates ) ) ) : '' ) . '</small></div>';
				echo '<div class="ioncoa-option-actions"><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '">Open COA ↗</a>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'A full backup will be created first. Keep this URL and remove the other URL rows for this batch?\');">';
				echo '<input type="hidden" name="action" value="ioncoa_safe_resolve_url_conflict"><input type="hidden" name="product_id" value="' . esc_attr( $product_id ) . '"><input type="hidden" name="variation_id" value="' . esc_attr( $variation_id ) . '"><input type="hidden" name="batch_key" value="' . esc_attr( $batch_key ) . '"><input type="hidden" name="preferred_url" value="' . esc_attr( $url ) . '">';
				wp_nonce_field( 'ioncoa_safe_resolve_url_conflict' );
				echo '<button class="button button-primary">Keep This Report</button></form>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'A full backup will be created first. Keep this COA but move this file to Older Reports?\');">';
				echo '<input type="hidden" name="action" value="ioncoa_safe_mark_url_older"><input type="hidden" name="product_id" value="' . esc_attr( $product_id ) . '"><input type="hidden" name="variation_id" value="' . esc_attr( $variation_id ) . '"><input type="hidden" name="batch_key" value="' . esc_attr( $batch_key ) . '"><input type="hidden" name="selected_url" value="' . esc_attr( $url ) . '">';
				wp_nonce_field( 'ioncoa_safe_mark_url_older' );
				echo '<button class="button">Move to Older Reports</button></form></div></div>';
			}

			/* Legitimate case: two or more files are pages/parts of one COA. */
			echo '<div style="margin:14px 0 2px;padding:12px 14px;border:1px solid #72aee6;background:#f0f6fc;border-radius:5px;">';
			echo '<strong>Are these pages of the same COA?</strong><br><span style="color:#50575e;">Use this only when every URL above belongs to the same batch (for example Page 1 and Page 2). The plugin will keep every file, group them as one multi-page report, and mark every page Current. A full backup is created first.</span>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;" onsubmit="return confirm(\'A full backup will be created first. Keep ALL report URLs as pages of one Current multi-page COA for this batch?\');">';
			echo '<input type="hidden" name="action" value="ioncoa_safe_keep_multipart"><input type="hidden" name="product_id" value="' . esc_attr( $product_id ) . '"><input type="hidden" name="variation_id" value="' . esc_attr( $variation_id ) . '"><input type="hidden" name="batch_key" value="' . esc_attr( $batch_key ) . '">';
			wp_nonce_field( 'ioncoa_safe_keep_multipart' );
			echo '<button class="button button-primary">Keep ALL as Multi-Page Current Report</button></form></div>';
			echo '</div>';
		}

		$options = (array) ( $inspection['current_conflict_options'] ?? array() );
		if ( $options ) {
			echo '<div class="ioncoa-conflict-card"><h3>Multiple batches are marked Current</h3><p>Nothing needs to be deleted. You can make one batch the only Current report, move an individual batch to <strong>Older Reports</strong>, or explicitly approve all different batches as Current. Every action creates a full backup first.</p>';
			foreach ( $options as $option ) {
				$url = (string) ( $option['url'] ?? '' );
				$urls = array_values( array_filter( (array) ( $option['urls'] ?? array() ) ) );
				$report_set = sanitize_text_field( $option['report_set'] ?? '' );
				$is_multipart = ! empty( $option['multipart'] ) && $report_set && count( $urls ) > 1;
				echo '<div class="ioncoa-option-row"><div><strong>' . esc_html( $option['batch'] ?? '' ) . '</strong> <span class="ioncoa-current-chip">CURRENT</span>' . ( $is_multipart ? ' <span class="ioncoa-current-chip" style="background:#2271b1;">' . esc_html( count( $urls ) ) . '-PAGE COA</span>' : '' ) . ( ! empty( $option['date'] ) ? '<br><small>Date: ' . esc_html( $option['date'] ) . '</small>' : '' );
				if ( $is_multipart ) {
					foreach ( $urls as $i => $page_url ) {
						echo '<br><code>Page ' . esc_html( $i + 1 ) . ': ' . esc_html( $page_url ) . '</code>';
					}
				} elseif ( $url ) {
					echo '<br><code>' . esc_html( $url ) . '</code>';
				}
				echo '</div><div class="ioncoa-option-actions">';
				if ( $is_multipart ) {
					foreach ( $urls as $i => $page_url ) {
						echo '<a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $page_url ) . '">Open Page ' . esc_html( $i + 1 ) . ' ↗</a>';
					}
				} elseif ( $url ) {
					echo '<a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '">Open COA ↗</a>';
				}

				/* Keep this batch as the one Current batch. */
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'A full backup will be created first. Keep this batch as Current and make all other batches historical?\');"><input type="hidden" name="action" value="ioncoa_safe_set_current"><input type="hidden" name="product_id" value="' . esc_attr( $product_id ) . '"><input type="hidden" name="variation_id" value="' . esc_attr( $variation_id ) . '"><input type="hidden" name="selected_batch" value="' . esc_attr( $option['batch'] ?? '' ) . '"><input type="hidden" name="selected_report_set" value="' . esc_attr( $report_set ) . '">';
				wp_nonce_field( 'ioncoa_safe_set_current' );
				echo '<button class="button button-primary">Set This Batch Current</button></form>';

				/* Keep the report but demote this batch to the normal historical archive. */
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'A full backup will be created first. Keep this COA but move this batch to Older Reports?\');"><input type="hidden" name="action" value="ioncoa_safe_mark_older"><input type="hidden" name="product_id" value="' . esc_attr( $product_id ) . '"><input type="hidden" name="variation_id" value="' . esc_attr( $variation_id ) . '"><input type="hidden" name="selected_batch" value="' . esc_attr( $option['batch'] ?? '' ) . '"><input type="hidden" name="selected_report_set" value="' . esc_attr( $report_set ) . '">';
				wp_nonce_field( 'ioncoa_safe_mark_older' );
				echo '<button class="button">Move to Older Reports</button></form></div></div>';
			}

			echo '<div style="margin-top:14px;padding:12px 14px;border:1px solid #72aee6;background:#f0f6fc;border-radius:5px;"><strong>Are all of these different batches intentionally Current?</strong><br><span style="color:#50575e;">Use this when the product legitimately has multiple different Current batches at the same time. The plugin keeps every Current flag and records an approval for this exact set. If the set changes later, Dry Analysis will flag it again.</span><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;" onsubmit="return confirm(\'A full backup will be created first. Keep ALL of these different batches Current?\');"><input type="hidden" name="action" value="ioncoa_safe_keep_all_current"><input type="hidden" name="product_id" value="' . esc_attr( $product_id ) . '"><input type="hidden" name="variation_id" value="' . esc_attr( $variation_id ) . '">'; wp_nonce_field( 'ioncoa_safe_keep_all_current' ); echo '<button class="button button-primary">Keep ALL Batches Current</button></form></div>';
			echo '</div>';
		}
		echo '<p><strong>After resolving all conflicts in this group:</strong> run Dry Analysis again. It should move into Safe duplicate groups, where you can back up and consolidate it normally.</p></div>';
	}

	private function render_review_panel( $post_id ) {
		$post_id = absint( $post_id );
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'lab_result' !== $post->post_type ) {
			return;
		}
		$candidate = $this->safe_candidate( $post_id );
		list( $base_title, $strength ) = $this->split_title( $post->post_title );
		$library_only = '1' === (string) get_post_meta( $post_id, self::LIBRARY_ONLY_META, true );
		$similar = $this->same_title_strength_posts( $post_id );
		$similar_count = count( $similar );
		$edit = get_edit_post_link( $post_id, '' );
		$nonce = wp_create_nonce( 'ioncoa_safe_review_search' );

		echo '<div class="ioncoa-review-panel"><h2>Mapping Review: ' . esc_html( $post->post_title ) . ' <small>#' . esc_html( $post_id ) . '</small></h2><p><a href="' . esc_url( $this->safety_page_url( array( 'ioncoa_section' => 'review_queue' ) ) ) . '">&larr; Back to Review Queue</a> &nbsp; | &nbsp; <a target="_blank" href="' . esc_url( $edit ) . '">Edit Lab Result ↗</a></p>';
		echo '<table class="widefat striped ioncoa-review-facts"><tbody><tr><th>Parsed product title</th><td>' . esc_html( $base_title ) . '</td></tr><tr><th>Parsed strength</th><td>' . esc_html( $strength ?: 'None' ) . '</td></tr><tr><th>Current analysis</th><td>' . esc_html( $candidate['reason'] ?? 'Needs review.' ) . '</td></tr><tr><th>Same exact title + strength</th><td>' . esc_html( $similar_count ) . ' published record(s)</td></tr></tbody></table>';

		if ( $library_only ) {
			echo '<div class="notice notice-info inline"><p><strong>Library Only.</strong> This COA stays published in the Lab Results library but is intentionally excluded from WooCommerce product pages.</p></div>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'A full backup will be created first. Return this record to the mapping review queue?\');"><input type="hidden" name="action" value="ioncoa_safe_return_to_review"><input type="hidden" name="post_id" value="' . esc_attr( $post_id ) . '">';
			wp_nonce_field( 'ioncoa_safe_return_to_review' );
			echo '<button class="button">Return to Mapping Review</button></form></div>';
			return;
		}

		echo '<h3>Option A — Map to a WooCommerce product/variation</h3><p>Search by product name, variation name, Product ID, or Variation ID. For variable products choose the <strong>exact variation</strong>, not just the parent product.</p>';
		echo '<div class="ioncoa-search-wrap"><input type="search" id="ioncoa-safe-review-search" placeholder="Search Woo product, variation, or ID..." autocomplete="off"><div id="ioncoa-safe-review-results"></div></div>';
		echo '<form id="ioncoa-safe-map-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return document.getElementById(\'ioncoa-safe-review-item-id\').value ? confirm(\'A full backup will be created first. Save this manual mapping?\') : false;"><input type="hidden" name="action" value="ioncoa_safe_save_review_mapping"><input type="hidden" name="post_id" value="' . esc_attr( $post_id ) . '"><input type="hidden" id="ioncoa-safe-review-item-id" name="item_id" value="">';
		wp_nonce_field( 'ioncoa_safe_save_review_mapping' );
		echo '<div id="ioncoa-safe-selected" style="display:none;"></div><p><label><input type="checkbox" name="apply_same_title_strength" value="1"> Apply this same decision to the other ' . esc_html( max( 0, $similar_count - 1 ) ) . ' published record(s) with the exact same product title + strength</label></p><button class="button button-primary">Save Manual Mapping</button></form>';

		echo '<hr><h3>Option B — Keep as Library Only</h3><p>Use this for historical/discontinued strengths or COAs that should remain searchable in the COA Library but should <strong>not</strong> appear on a WooCommerce product page.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'A full backup will be created first. Keep this Lab Result in the public COA Library but exclude it from Woo product pages?\');"><input type="hidden" name="action" value="ioncoa_safe_library_only"><input type="hidden" name="post_id" value="' . esc_attr( $post_id ) . '">';
		wp_nonce_field( 'ioncoa_safe_library_only' );
		echo '<p><label><input type="checkbox" name="apply_same_title_strength" value="1"> Apply Library Only to the other ' . esc_html( max( 0, $similar_count - 1 ) ) . ' published record(s) with this exact product title + strength</label></p><button class="button">Keep Library Only</button></form>';

		$batches = get_post_meta( $post_id, '_lab_batches', true );
		if ( is_array( $batches ) && $batches ) {
			echo '<h3>COAs on this Lab Result</h3><div class="ioncoa-mini-batches">';
			foreach ( $batches as $batch ) {
				if ( ! is_array( $batch ) ) { continue; }
				$url = $this->batch_url( $batch );
				echo '<div><strong>' . esc_html( $batch['batch'] ?? 'Batch' ) . '</strong>' . ( ! empty( $batch['current'] ) ? ' <span class="ioncoa-current-chip">CURRENT</span>' : '' ) . ( ! empty( $batch['date'] ) ? ' &nbsp; ' . esc_html( $batch['date'] ) : '' ) . ( $url ? ' &nbsp; <a target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '">Open COA ↗</a>' : '' ) . '</div>';
			}
			echo '</div>';
		}

		?>
		<script>
		jQuery(function($){
			var timer = null;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var $search = $('#ioncoa-safe-review-search');
			var $results = $('#ioncoa-safe-review-results');
			var $item = $('#ioncoa-safe-review-item-id');
			var $selected = $('#ioncoa-safe-selected');
			$search.on('input', function(){
				var q = $.trim($search.val());
				clearTimeout(timer);
				if (q.length < 2) { $results.hide().empty(); return; }
				timer = setTimeout(function(){
					$.get(ajaxUrl, {action:'ioncoa_safe_product_search', nonce:nonce, q:q}).done(function(response){
						var rows = response && response.success ? response.data : [];
						$results.empty();
						if (!rows.length) { $results.append('<div class="ioncoa-search-empty">No WooCommerce items found.</div>'); }
						rows.forEach(function(row){
							$('<button type="button"></button>').text(row.text).attr('data-id', row.id).attr('data-label', row.label).attr('data-product', row.product_id).attr('data-variation', row.variation_id).attr('data-edit', row.edit_url || '').appendTo($results);
						});
						$results.show();
					});
				}, 250);
			});
			$results.on('click', 'button[data-id]', function(){
				var $b = $(this); $item.val($b.attr('data-id')); $search.val($b.attr('data-label')); $results.hide().empty();
				var text = 'Selected: Product #' + ($b.attr('data-product') || '');
				if ($b.attr('data-variation')) text += ' | Variation #' + $b.attr('data-variation');
				var edit = $b.attr('data-edit');
				$selected.html('<strong>' + $('<div>').text(text).html() + '</strong>' + (edit ? ' &nbsp; <a target="_blank" rel="noopener noreferrer" href="' + edit + '">Edit Product ↗</a>' : '')).show();
			});
		});
		</script>
		<?php
		echo '</div>';
	}


	/** Build a CSV string with UTF-8 BOM so Excel opens it cleanly. */
	private function csv_string( $headers, $rows ) {
		$fh = fopen( 'php://temp', 'w+' );
		if ( ! $fh ) {
			return '';
		}
		fwrite( $fh, "\xEF\xBB\xBF" );
		fputcsv( $fh, $headers );
		foreach ( (array) $rows as $row ) {
			$line = array();
			foreach ( $headers as $header ) {
				$value = isset( $row[ $header ] ) ? $row[ $header ] : '';
				if ( is_array( $value ) || is_object( $value ) ) {
					$value = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				}
				$line[] = (string) $value;
			}
			fputcsv( $fh, $line );
		}
		rewind( $fh );
		$out = stream_get_contents( $fh );
		fclose( $fh );
		return (string) $out;
	}

	private function legacy_coa_urls_for_product( $product_id ) {
		$raw = get_post_meta( absint( $product_id ), 'coa_tab', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		preg_match_all( "~https?://[^\\s\"'<>]+~i", html_entity_decode( $raw, ENT_QUOTES, get_bloginfo( 'charset' ) ), $matches );
		$urls = array_values( array_unique( array_map( 'esc_url_raw', (array) ( $matches[0] ?? array() ) ) ) );
		return array_values( array_filter( $urls ) );
	}

	private function batch_export_facts( $post_id ) {
		$batches = get_post_meta( absint( $post_id ), '_lab_batches', true );
		if ( ! is_array( $batches ) ) {
			$batches = array();
		}
		$numbers = array();
		$current = array();
		$urls = array();
		$current_urls = array();
		foreach ( $batches as $batch ) {
			if ( ! is_array( $batch ) ) {
				continue;
			}
			$number = trim( (string) ( $batch['batch'] ?? '' ) );
			$url = $this->batch_url( $batch );
			if ( $number ) $numbers[] = $number;
			if ( $url ) $urls[] = $url;
			if ( ! empty( $batch['current'] ) ) {
				if ( $number ) $current[] = $number;
				if ( $url ) $current_urls[] = $url;
			}
		}
		return array(
			'batch_count' => count( $batches ),
			'batch_numbers' => implode( ' | ', array_values( array_unique( $numbers ) ) ),
			'current_batches' => implode( ' | ', array_values( array_unique( $current ) ) ),
			'report_urls' => implode( ' | ', array_values( array_unique( $urls ) ) ),
			'current_report_urls' => implode( ' | ', array_values( array_unique( $current_urls ) ) ),
		);
	}

	private function build_crosscheck_files() {
		$analysis = $this->analyze();
		$files = array();

		/* 1) Needs-review Lab Results. One row per Lab Result post. */
		$review_rows = array();
		foreach ( (array) $analysis['needs_review'] as $row ) {
			$post_id = absint( $row['post_id'] ?? 0 );
			if ( ! $post_id ) continue;
			$title = get_the_title( $post_id );
			list( $base, $strength ) = $this->split_title( $title );
			$facts = $this->batch_export_facts( $post_id );
			$review_rows[] = array_merge( array(
				'lab_result_id' => $post_id,
				'lab_result_title' => $title,
				'parsed_base_name' => $base,
				'parsed_strength' => $strength,
				'wordpress_status' => get_post_status( $post_id ),
				'review_reason' => $row['reason'] ?? '',
				'saved_product_id' => absint( get_post_meta( $post_id, '_ioncoa_product_id', true ) ),
				'saved_variation_id' => absint( get_post_meta( $post_id, '_ioncoa_variation_id', true ) ),
				'saved_strength' => (string) get_post_meta( $post_id, '_ioncoa_strength', true ),
				'mapping_source' => (string) get_post_meta( $post_id, '_ioncoa_mapping_source', true ),
				'mapping_confidence' => (string) get_post_meta( $post_id, '_ioncoa_mapping_confidence', true ),
				'mapping_status' => (string) get_post_meta( $post_id, '_ioncoa_mapping_status', true ),
				'edit_lab_result_url' => get_edit_post_link( $post_id, '' ),
			), $facts );
		}
		$review_headers = array( 'lab_result_id','lab_result_title','parsed_base_name','parsed_strength','wordpress_status','review_reason','saved_product_id','saved_variation_id','saved_strength','mapping_source','mapping_confidence','mapping_status','batch_count','batch_numbers','current_batches','report_urls','current_report_urls','edit_lab_result_url' );
		$files['lab-results-needs-review.csv'] = $this->csv_string( $review_headers, $review_rows );

		/* 2) Conflict groups and 3) detailed conflicting files/current choices. */
		$conflict_rows = array();
		$conflict_file_rows = array();
		foreach ( (array) $analysis['conflict_groups'] as $group_key => $group ) {
			$product_id = absint( $group['product_id'] ?? 0 );
			$variation_id = absint( $group['variation_id'] ?? 0 );
			$inspection = (array) ( $group['batch_inspection'] ?? array() );
			$conflict_rows[] = array(
				'group_key' => $group_key,
				'woo_label' => $this->product_label( $product_id, $variation_id ),
				'product_id' => $product_id,
				'variation_id' => $variation_id,
				'strength' => $group['strength'] ?? '',
				'lab_result_ids' => implode( ' | ', array_map( 'absint', (array) ( $group['post_ids'] ?? array() ) ) ),
				'conflict_reasons' => implode( ' | ', (array) ( $inspection['conflicts'] ?? array() ) ),
				'merged_batch_count' => absint( $inspection['output_count'] ?? 0 ),
				'edit_product_url' => $product_id ? get_edit_post_link( $product_id, '' ) : '',
			);
			foreach ( (array) ( $inspection['url_conflicts'] ?? array() ) as $batch_key => $detail ) {
				foreach ( (array) ( $detail['variants'] ?? array() ) as $variant ) {
					$source_posts = array();
					$dates = array();
					$is_current = 0;
					foreach ( (array) ( $variant['rows'] ?? array() ) as $variant_row ) {
						$source_posts[] = absint( $variant_row['post_id'] ?? 0 );
						if ( ! empty( $variant_row['date'] ) ) $dates[] = $variant_row['date'];
						if ( ! empty( $variant_row['current'] ) ) $is_current = 1;
					}
					$conflict_file_rows[] = array(
						'group_key' => $group_key,
						'woo_label' => $this->product_label( $product_id, $variation_id ),
						'product_id' => $product_id,
						'variation_id' => $variation_id,
						'batch' => $detail['batch'] ?? $batch_key,
						'url' => $variant['url'] ?? '',
						'current' => $is_current,
						'source_lab_result_ids' => implode( ' | ', array_values( array_unique( array_filter( $source_posts ) ) ) ),
						'dates' => implode( ' | ', array_values( array_unique( $dates ) ) ),
						'issue_type' => 'same_batch_multiple_urls',
					);
				}
			}
			foreach ( (array) ( $inspection['current_conflict_options'] ?? array() ) as $option ) {
				$conflict_file_rows[] = array(
					'group_key' => $group_key,
					'woo_label' => $this->product_label( $product_id, $variation_id ),
					'product_id' => $product_id,
					'variation_id' => $variation_id,
					'batch' => $option['batch'] ?? '',
					'url' => implode( ' | ', (array) ( $option['urls'] ?? array() ) ),
					'current' => 1,
					'source_lab_result_ids' => implode( ' | ', array_map( 'absint', (array) ( $option['posts'] ?? array() ) ) ),
					'dates' => $option['date'] ?? '',
					'issue_type' => 'multiple_current_batches',
				);
			}
		}
		$files['conflict-groups.csv'] = $this->csv_string( array( 'group_key','woo_label','product_id','variation_id','strength','lab_result_ids','conflict_reasons','merged_batch_count','edit_product_url' ), $conflict_rows );
		$files['conflict-files.csv'] = $this->csv_string( array( 'group_key','woo_label','product_id','variation_id','batch','url','current','source_lab_result_ids','dates','issue_type' ), $conflict_file_rows );

		/* 4) Full Woo catalog: parent products plus each ACTUAL variation. */
		$catalog_rows = array();
		if ( function_exists( 'wc_get_products' ) ) {
			$product_ids = wc_get_products( array( 'limit' => -1, 'status' => array( 'publish','private','draft','pending' ), 'return' => 'ids' ) );
			foreach ( (array) $product_ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) continue;
				$legacy_urls = implode( ' | ', $this->legacy_coa_urls_for_product( $product_id ) );
				$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
				$categories = is_wp_error( $categories ) ? array() : $categories;
				$base = array(
					'product_id' => absint( $product_id ),
					'product_name' => $product->get_name(),
					'product_slug' => get_post_field( 'post_name', $product_id ),
					'product_sku' => $product->get_sku(),
					'product_type' => $product->get_type(),
					'product_status' => get_post_status( $product_id ),
					'product_categories' => implode( ' | ', (array) $categories ),
					'legacy_product_coa_urls' => $legacy_urls,
					'edit_product_url' => get_edit_post_link( $product_id, '' ),
				);
				if ( $product->is_type( 'variable' ) ) {
					foreach ( (array) $product->get_children() as $variation_id ) {
						$variation = wc_get_product( $variation_id );
						if ( ! $variation || ! $variation->is_type( 'variation' ) ) continue;
						$attrs = array();
						foreach ( (array) $variation->get_attributes() as $name => $value ) {
							$attrs[] = $name . '=' . $value;
						}
						$catalog_rows[] = array_merge( $base, array(
							'variation_id' => absint( $variation_id ),
							'variation_status' => get_post_status( $variation_id ),
							'variation_sku' => $variation->get_sku(),
							'variation_attributes' => implode( ' | ', $attrs ),
							'variation_strength' => $this->extract_strength( implode( ' ', (array) $variation->get_attributes() ) ),
						) );
					}
				} else {
					$catalog_rows[] = array_merge( $base, array(
						'variation_id' => '',
						'variation_status' => '',
						'variation_sku' => '',
						'variation_attributes' => '',
						'variation_strength' => '',
					) );
				}
			}
		}
		$catalog_headers = array( 'product_id','product_name','product_slug','product_sku','product_type','product_status','product_categories','variation_id','variation_status','variation_sku','variation_attributes','variation_strength','legacy_product_coa_urls','edit_product_url' );
		$files['woocommerce-products-variations.csv'] = $this->csv_string( $catalog_headers, $catalog_rows );

		/* 5) Proven historical aliases learned from already-confirmed mappings. */
		$alias_rows = array();
		foreach ( $this->get_lab_ids( array( 'publish', self::CONSOLIDATED_STATUS ) ) as $post_id ) {
			if ( '1' === (string) get_post_meta( $post_id, self::LIBRARY_ONLY_META, true ) ) continue;
			$product_id = absint( get_post_meta( $post_id, '_ioncoa_product_id', true ) );
			$variation_id = absint( get_post_meta( $post_id, '_ioncoa_variation_id', true ) );
			$source = sanitize_key( (string) get_post_meta( $post_id, '_ioncoa_mapping_source', true ) );
			if ( ! $product_id || in_array( $source, array( '', 'manual_none', 'safe_needs_review' ), true ) ) continue;
			list( $base, $strength ) = $this->split_title( get_the_title( $post_id ) );
			$alias_rows[] = array(
				'lab_result_id' => absint( $post_id ),
				'historical_lab_name' => $base,
				'historical_strength' => $strength,
				'product_id' => $product_id,
				'variation_id' => $variation_id,
				'current_woo_label' => $this->product_label( $product_id, $variation_id ),
				'mapping_source' => $source,
			);
		}
		$files['confirmed-name-aliases.csv'] = $this->csv_string( array( 'lab_result_id','historical_lab_name','historical_strength','product_id','variation_id','current_woo_label','mapping_source' ), $alias_rows );

		$files['README.txt'] = "ION COA Cross-Check Package\nGenerated: " . gmdate( 'c' ) . "\n\nPurpose\n-------\nUse these files for human/AI-assisted matching where Lab Result names differ from current WooCommerce product names.\n\nFiles\n-----\nlab-results-needs-review.csv: every currently unresolved Lab Result plus batch URLs and saved mapping metadata.\nconflict-groups.csv: grouped conflicts already tied to a Woo product/variation.\nconflict-files.csv: the actual conflicting URLs/current-batch choices.\nwoocommerce-products-variations.csv: current Woo parent products and ACTUAL variation children, including IDs, SKU, attributes, strength, slug, categories and legacy product-level COA URLs.\nconfirmed-name-aliases.csv: historical Lab Result names already proven to map to current Woo products; useful evidence for renamed products.\n\nRecommended matching priority\n-----------------------------\n1. Existing confirmed Product/Variation IDs and proven aliases.\n2. SKU / explicit variation attributes / strength.\n3. Product identity qualifiers (DAC vs No DAC, blends, nasal/drops, etc.).\n4. Normalized title/slug similarity.\n5. Legacy COA URL only as supporting evidence, never as the sole basis.\n\nDo not bulk-import guessed mappings. Review ambiguous rows and keep truly historical/discontinued items Library Only.\n";

		return $files;
	}


	/**
	 * Extract all explicit mg components from text without collapsing slash blends.
	 * Examples: 5/5mg -> signature 5/5mg, total 10; 10/10mg -> total 20.
	 * This is EXPORT-ONLY evidence and never changes mappings by itself.
	 */
	private function audit_mass_facts( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$signature = '';
		$total_mg = '';
		$components = array();

		if ( preg_match( '/(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)\s*mg\b/i', $text, $m ) ) {
			$components = array( (float) $m[1], (float) $m[2] );
			$signature = rtrim( rtrim( (string) $m[1], '0' ), '.' ) . '/' . rtrim( rtrim( (string) $m[2], '0' ), '.' ) . 'mg';
			$total_mg = array_sum( $components );
		} else {
			preg_match_all( '/(\d+(?:\.\d+)?)\s*mg\b/i', $text, $matches );
			foreach ( (array) ( $matches[1] ?? array() ) as $value ) {
				$components[] = (float) $value;
			}
			if ( $components ) {
				$signature = implode( '+', array_map( static function( $v ) {
					return rtrim( rtrim( (string) $v, '0' ), '.' ) . 'mg';
				}, $components ) );
				$total_mg = array_sum( $components );
			}
		}

		return array(
			'mass_signature' => $signature,
			'total_mg' => '' === $total_mg ? '' : (string) ( 0 + $total_mg ),
		);
	}

	private function audit_local_upload_path( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) return '';
		$uploads = wp_upload_dir();
		$baseurl = rtrim( (string) ( $uploads['baseurl'] ?? '' ), '/' );
		$basedir = rtrim( (string) ( $uploads['basedir'] ?? '' ), '/\\' );
		if ( ! $baseurl || ! $basedir ) return '';

		$decoded = html_entity_decode( $url, ENT_QUOTES, get_bloginfo( 'charset' ) );
		if ( 0 === strpos( $decoded, $baseurl . '/' ) ) {
			$relative = rawurldecode( substr( $decoded, strlen( $baseurl ) + 1 ) );
			$path = $basedir . DIRECTORY_SEPARATOR . str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $relative );
			return is_file( $path ) ? $path : '';
		}

		$parts = wp_parse_url( $decoded );
		$path_part = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '';
		$needle = '/wp-content/uploads/';
		$pos = strpos( $path_part, $needle );
		if ( false !== $pos ) {
			$relative = ltrim( substr( $path_part, $pos + strlen( $needle ) ), '/' );
			$path = $basedir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			return is_file( $path ) ? $path : '';
		}
		return '';
	}

	private function audit_archive_path_for_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$basename = sanitize_file_name( wp_basename( $path ?: $url ) );
		if ( ! $basename ) $basename = 'coa-file';
		return 'coa-files/' . substr( sha1( strtolower( trim( (string) $url ) ) ), 0, 14 ) . '-' . $basename;
	}

	/** Build a full forensic package: every live/consolidated Lab Result, every batch URL,
	 * the actual local COA files when available, exact Woo variation labels/attributes,
	 * and legacy product-level COA URLs. This is completely read-only.
	 */
	private function build_full_audit_package() {
		$files = array();
		$attachments = array();
		$manifest_rows = array();
		$lab_rows = array();
		$seen_attachment_urls = array();
		$statuses = array( 'publish', self::CONSOLIDATED_STATUS );

		foreach ( $this->get_lab_ids( $statuses ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) continue;
			$title = get_the_title( $post_id );
			list( $base, $parsed_strength ) = $this->split_title( $title );
			$title_mass = $this->audit_mass_facts( $title );
			$product_id = absint( get_post_meta( $post_id, '_ioncoa_product_id', true ) );
			$variation_id = absint( get_post_meta( $post_id, '_ioncoa_variation_id', true ) );
			$mapped_label = $product_id ? $this->product_label( $product_id, $variation_id ) : '';
			$mapped_attrs = '';
			$variation_mass = array( 'mass_signature' => '', 'total_mg' => '' );
			if ( $variation_id && function_exists( 'wc_get_product' ) ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation && $variation->is_type( 'variation' ) ) {
					$parts = array();
					foreach ( (array) $variation->get_attributes() as $name => $value ) $parts[] = $name . '=' . $value;
					$mapped_attrs = implode( ' | ', $parts );
					$variation_mass = $this->audit_mass_facts( implode( ' ', (array) $variation->get_attributes() ) );
				}
			}
			$batches = get_post_meta( $post_id, '_lab_batches', true );
			if ( ! is_array( $batches ) ) $batches = array();
			$consolidated_into = absint( get_post_meta( $post_id, '_ioncoa_consolidated_into', true ) );
			$library_only = '1' === (string) get_post_meta( $post_id, self::LIBRARY_ONLY_META, true );
			$lab_rows[] = array(
				'lab_result_id' => $post_id,
				'lab_result_title' => $title,
				'parsed_base_name' => $base,
				'parsed_strength' => $parsed_strength,
				'title_mass_signature' => $title_mass['mass_signature'],
				'title_total_mg' => $title_mass['total_mg'],
				'post_status' => get_post_status( $post_id ),
				'library_only' => $library_only ? 1 : 0,
				'consolidated_into_post_id' => $consolidated_into,
				'mapped_product_id' => $product_id,
				'mapped_variation_id' => $variation_id,
				'mapped_woo_label' => $mapped_label,
				'mapped_variation_attributes' => $mapped_attrs,
				'mapped_variation_mass_signature' => $variation_mass['mass_signature'],
				'mapped_variation_total_mg' => $variation_mass['total_mg'],
				'saved_strength' => (string) get_post_meta( $post_id, '_ioncoa_strength', true ),
				'mapping_source' => (string) get_post_meta( $post_id, '_ioncoa_mapping_source', true ),
				'mapping_confidence' => (string) get_post_meta( $post_id, '_ioncoa_mapping_confidence', true ),
				'batch_count' => count( $batches ),
				'edit_lab_result_url' => get_edit_post_link( $post_id, '' ),
				'edit_product_url' => $product_id ? get_edit_post_link( $product_id, '' ) : '',
			);

			foreach ( $batches as $batch_index => $batch ) {
				if ( ! is_array( $batch ) ) continue;
				$batch_number = trim( (string) ( $batch['batch'] ?? '' ) );
				$url_map = array();
				foreach ( array( 'image', 'link' ) as $source_field ) {
					$url = trim( (string) ( $batch[ $source_field ] ?? '' ) );
					if ( ! $url ) continue;
					if ( ! isset( $url_map[ $url ] ) ) $url_map[ $url ] = array();
					$url_map[ $url ][] = $source_field;
				}
				foreach ( $url_map as $url => $source_fields ) {
					$filename = wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
					$file_mass = $this->audit_mass_facts( $filename );
					$local_path = $this->audit_local_upload_path( $url );
					$archive_path = $this->audit_archive_path_for_url( $url );
					$included = $local_path ? 1 : 0;
					$manifest_rows[] = array(
						'lab_result_id' => $post_id,
						'lab_result_title' => $title,
						'post_status' => get_post_status( $post_id ),
						'parsed_strength' => $parsed_strength,
						'mapped_product_id' => $product_id,
						'mapped_variation_id' => $variation_id,
						'mapped_woo_label' => $mapped_label,
						'mapped_variation_attributes' => $mapped_attrs,
						'mapped_variation_mass_signature' => $variation_mass['mass_signature'],
						'mapped_variation_total_mg' => $variation_mass['total_mg'],
						'batch_index' => $batch_index,
						'batch_number' => $batch_number,
						'current' => ! empty( $batch['current'] ) ? 1 : 0,
						'date' => (string) ( $batch['date'] ?? '' ),
						'purity' => (string) ( $batch['purity'] ?? '' ),
						'mass' => (string) ( $batch['mass'] ?? '' ),
						'url_source_fields' => implode( '+', $source_fields ),
						'url' => $url,
						'filename' => $filename,
						'filename_mass_signature' => $file_mass['mass_signature'],
						'filename_total_mg' => $file_mass['total_mg'],
						'included_in_zip' => $included,
						'archive_path' => $included ? $archive_path : '',
						'local_file_size_bytes' => $local_path && is_file( $local_path ) ? filesize( $local_path ) : '',
					);
					if ( $local_path && ! isset( $seen_attachment_urls[ $url ] ) ) {
						$attachments[] = array( 'source_path' => $local_path, 'archive_path' => $archive_path, 'url' => $url );
						$seen_attachment_urls[ $url ] = true;
					}
				}
			}
		}

		$lab_headers = array( 'lab_result_id','lab_result_title','parsed_base_name','parsed_strength','title_mass_signature','title_total_mg','post_status','library_only','consolidated_into_post_id','mapped_product_id','mapped_variation_id','mapped_woo_label','mapped_variation_attributes','mapped_variation_mass_signature','mapped_variation_total_mg','saved_strength','mapping_source','mapping_confidence','batch_count','edit_lab_result_url','edit_product_url' );
		$manifest_headers = array( 'lab_result_id','lab_result_title','post_status','parsed_strength','mapped_product_id','mapped_variation_id','mapped_woo_label','mapped_variation_attributes','mapped_variation_mass_signature','mapped_variation_total_mg','batch_index','batch_number','current','date','purity','mass','url_source_fields','url','filename','filename_mass_signature','filename_total_mg','included_in_zip','archive_path','local_file_size_bytes' );
		$files['all-lab-results.csv'] = $this->csv_string( $lab_headers, $lab_rows );
		$files['coa-file-manifest.csv'] = $this->csv_string( $manifest_headers, $manifest_rows );

		/* Exact current Woo catalog with raw variation attributes and blend total-mass helpers. */
		$catalog_rows = array();
		$legacy_manifest = array();
		if ( function_exists( 'wc_get_products' ) ) {
			$product_ids = wc_get_products( array( 'limit' => -1, 'status' => array( 'publish','private','draft','pending' ), 'return' => 'ids' ) );
			foreach ( (array) $product_ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) continue;
				$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
				$categories = is_wp_error( $categories ) ? array() : $categories;
				$base = array(
					'product_id' => absint( $product_id ),
					'product_name' => $product->get_name(),
					'product_slug' => get_post_field( 'post_name', $product_id ),
					'product_sku' => $product->get_sku(),
					'product_type' => $product->get_type(),
					'product_status' => get_post_status( $product_id ),
					'product_categories' => implode( ' | ', (array) $categories ),
					'edit_product_url' => get_edit_post_link( $product_id, '' ),
				);
				if ( $product->is_type( 'variable' ) ) {
					foreach ( (array) $product->get_children() as $variation_id ) {
						$variation = wc_get_product( $variation_id );
						if ( ! $variation || ! $variation->is_type( 'variation' ) ) continue;
						$attrs = array(); $raw_values = array();
						foreach ( (array) $variation->get_attributes() as $name => $value ) { $attrs[] = $name . '=' . $value; $raw_values[] = $value; }
						$mass = $this->audit_mass_facts( implode( ' ', $raw_values ) );
						$catalog_rows[] = array_merge( $base, array(
							'variation_id' => absint( $variation_id ),
							'variation_status' => get_post_status( $variation_id ),
							'variation_sku' => $variation->get_sku(),
							'variation_label' => $this->product_label( $product_id, $variation_id ),
							'variation_attributes' => implode( ' | ', $attrs ),
							'variation_mass_signature' => $mass['mass_signature'],
							'variation_total_mg' => $mass['total_mg'],
						) );
					}
				} else {
					$catalog_rows[] = array_merge( $base, array( 'variation_id'=>'','variation_status'=>'','variation_sku'=>'','variation_label'=>$product->get_name(),'variation_attributes'=>'','variation_mass_signature'=>'','variation_total_mg'=>'' ) );
				}

				foreach ( $this->legacy_coa_urls_for_product( $product_id ) as $legacy_url ) {
					$filename = wp_basename( (string) wp_parse_url( $legacy_url, PHP_URL_PATH ) );
					$file_mass = $this->audit_mass_facts( $filename );
					$local_path = $this->audit_local_upload_path( $legacy_url );
					$archive_path = $this->audit_archive_path_for_url( $legacy_url );
					$legacy_manifest[] = array(
						'product_id' => $product_id,
						'product_name' => $product->get_name(),
						'url' => $legacy_url,
						'filename' => $filename,
						'filename_mass_signature' => $file_mass['mass_signature'],
						'filename_total_mg' => $file_mass['total_mg'],
						'included_in_zip' => $local_path ? 1 : 0,
						'archive_path' => $local_path ? $archive_path : '',
					);
					if ( $local_path && ! isset( $seen_attachment_urls[ $legacy_url ] ) ) {
						$attachments[] = array( 'source_path' => $local_path, 'archive_path' => $archive_path, 'url' => $legacy_url );
						$seen_attachment_urls[ $legacy_url ] = true;
					}
				}
			}
		}
		$catalog_headers = array( 'product_id','product_name','product_slug','product_sku','product_type','product_status','product_categories','variation_id','variation_status','variation_sku','variation_label','variation_attributes','variation_mass_signature','variation_total_mg','edit_product_url' );
		$files['woocommerce-variation-reference.csv'] = $this->csv_string( $catalog_headers, $catalog_rows );
		$files['legacy-product-coa-manifest.csv'] = $this->csv_string( array( 'product_id','product_name','url','filename','filename_mass_signature','filename_total_mg','included_in_zip','archive_path' ), $legacy_manifest );

		/* Preserve the smaller cross-check files too, so one ZIP is sufficient for review. */
		foreach ( $this->build_crosscheck_files() as $name => $content ) {
			$files['crosscheck/' . $name] = $content;
		}

		$files['README-FIRST.txt'] = "ION FULL COA FORENSIC AUDIT PACKAGE\nGenerated: " . gmdate( 'c' ) . "\n\nTHIS EXPORT IS READ-ONLY. It does not modify Lab Results or WooCommerce.\n\nPurpose\n-------\nThis package is designed for one-by-one human/AI verification of every COA against the ORIGINAL Lab Result title and the CURRENT WooCommerce Product/Variation catalog. It specifically preserves slash/blend labels such as 5/5mg, 10/10mg and 15/15mg instead of flattening them to a single mg value.\n\nKey files\n---------\nall-lab-results.csv: every Published and Consolidated Lab Result, its original title, saved mapping, mapped exact Woo variation attributes and batch count.\ncoa-file-manifest.csv: one row for every Lab Result batch file/URL, including current/older status and the path of the actual file inside this ZIP when local.\nwoocommerce-variation-reference.csv: exact live Woo Product IDs, Variation IDs and RAW variation labels/attributes. For blends it also calculates total mg for cross-checking.\nlegacy-product-coa-manifest.csv: old product-edit ACF COA URLs as supporting evidence only.\ncoa-files/: unique local COA JPG/PNG/PDF files. A file is stored once even if referenced by several historical Lab Results.\ncrosscheck/: the existing mapping/conflict export package.\n\nReview rule\n-----------\nDo NOT assume a COA labeled 20mg belongs to a Woo variation labeled 20mg. For a two-component 10/10mg blend, the COA may correctly report 20mg total. Compare product identity, components, original Lab Result title, actual Woo variation attributes, and the COA document itself.\n\nRecommended next step\n---------------------\nUpload this entire ZIP to ChatGPT. The review can inspect the actual COA files one-by-one, build a corrected Product ID / Variation ID mapping, identify mixed-strength batches, and separately identify display-label problems where the Lab Results page should show the exact Woo variation label (for example 10/10mg rather than 10mg).\n";

		return array( 'files' => $files, 'attachments' => $attachments );
	}

	public function handle_export_full_audit() {
		if ( ! current_user_can( 'edit_products' ) ) wp_die( 'Permission denied.' );
		check_admin_referer( 'ioncoa_safe_export_full_audit' );
		if ( ! class_exists( 'ZipArchive' ) ) wp_die( 'ZipArchive is required for the Full COA Audit export.' );
		@set_time_limit( 0 );
		$package = $this->build_full_audit_package();
		$stamp = gmdate( 'Ymd-His' );
		$tmp = wp_tempnam( 'ion-full-coa-audit-' . $stamp . '.zip' );
		$zip = new ZipArchive();
		if ( ! $tmp || true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) wp_die( 'Could not create the audit ZIP.' );
		foreach ( (array) $package['files'] as $name => $content ) $zip->addFromString( $name, $content );
		foreach ( (array) $package['attachments'] as $attachment ) {
			$source = (string) ( $attachment['source_path'] ?? '' );
			$archive = (string) ( $attachment['archive_path'] ?? '' );
			if ( $source && $archive && is_file( $source ) ) $zip->addFile( $source, $archive );
		}
		$zip->close();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="ion-full-coa-audit-' . $stamp . '.zip"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		readfile( $tmp );
		@unlink( $tmp );
		exit;
	}

	public function handle_export_crosscheck() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( 'Permission denied.' );
		}
		check_admin_referer( 'ioncoa_safe_export_crosscheck' );
		$files = $this->build_crosscheck_files();
		$stamp = gmdate( 'Ymd-His' );

		if ( class_exists( 'ZipArchive' ) ) {
			$tmp = wp_tempnam( 'ion-coa-crosscheck-' . $stamp . '.zip' );
			$zip = new ZipArchive();
			if ( $tmp && true === $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				foreach ( $files as $name => $content ) {
					$zip->addFromString( $name, $content );
				}
				$zip->close();
				header( 'Content-Type: application/zip' );
				header( 'Content-Disposition: attachment; filename="ion-coa-crosscheck-' . $stamp . '.zip"' );
				header( 'Content-Length: ' . filesize( $tmp ) );
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
				readfile( $tmp );
				@unlink( $tmp );
				exit;
			}
		}

		/* Fallback when ZipArchive is unavailable: export the unresolved Lab Results CSV. */
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ion-coa-needs-review-' . $stamp . '.csv"' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		echo $files['lab-results-needs-review.csv'];
		exit;
	}


	/**
	 * Import a reviewed cross-check CSV produced from the export package.
	 * Safety rules:
	 * - only HIGH confidence rows are eligible;
	 * - MEDIUM/REVIEW rows are always skipped;
	 * - the current Lab Result title must still match the exported expected title;
	 * - Woo product/variation IDs are revalidated against the live database;
	 * - one full backup is created before the first write;
	 * - no Lab Result post is deleted or consolidated by this importer.
	 */
	public function handle_import_reviewed() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( 'Permission denied.' );
		}
		check_admin_referer( 'ioncoa_safe_import_reviewed' );

		if ( empty( $_FILES['reviewed_mapping_csv'] ) || ! isset( $_FILES['reviewed_mapping_csv']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['reviewed_mapping_csv']['error'] ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'Reviewed mapping CSV upload failed.' ) );
		}

		$tmp = (string) $_FILES['reviewed_mapping_csv']['tmp_name'];
		if ( ! $tmp || ! is_readable( $tmp ) ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'The uploaded reviewed mapping CSV could not be read.' ) );
		}

		$fh = fopen( $tmp, 'r' );
		if ( ! $fh ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'The uploaded reviewed mapping CSV could not be opened.' ) );
		}

		$header = fgetcsv( $fh );
		if ( ! is_array( $header ) ) {
			fclose( $fh );
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'The reviewed mapping CSV has no header row.' ) );
		}
		$header = array_map( static function( $v ) {
			$v = (string) $v;
			$v = preg_replace( '/^\xEF\xBB\xBF/', '', $v );
			return trim( $v );
		}, $header );
		$required = array( 'lab_result_id', 'expected_lab_result_title', 'decision', 'product_id', 'variation_id', 'recommended_strength', 'confidence', 'reason' );
		foreach ( $required as $col ) {
			if ( ! in_array( $col, $header, true ) ) {
				fclose( $fh );
				$this->redirect_with_result( array( 'type' => 'error', 'message' => 'Reviewed mapping CSV is missing required column: ' . $col ) );
			}
		}
		$idx = array_flip( $header );

		$plans = array();
		$seen_ids = array();
		$skipped = array(
			'non_high' => 0,
			'keep_existing' => 0,
			'stale_title' => 0,
			'missing_post' => 0,
			'invalid_woo' => 0,
			'unsupported_decision' => 0,
		);
		$rows_read = 0;
		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			$rows_read++;
			if ( $rows_read > 2000 ) {
				break;
			}
			$get = static function( $name ) use ( $row, $idx ) {
				$i = $idx[ $name ];
				return isset( $row[ $i ] ) ? trim( (string) $row[ $i ] ) : '';
			};
			$post_id = absint( $get( 'lab_result_id' ) );
			if ( ! $post_id || isset( $seen_ids[ $post_id ] ) ) {
				continue;
			}
			$seen_ids[ $post_id ] = true;
			$confidence = strtoupper( $get( 'confidence' ) );
			$decision = sanitize_key( $get( 'decision' ) );
			if ( 'HIGH' !== $confidence ) {
				$skipped['non_high']++;
				continue;
			}
			if ( 'keep_existing' === $decision ) {
				$skipped['keep_existing']++;
				continue;
			}
			if ( ! in_array( $decision, array( 'map', 'library_only' ), true ) ) {
				$skipped['unsupported_decision']++;
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post || 'lab_result' !== $post->post_type || 'publish' !== $post->post_status ) {
				$skipped['missing_post']++;
				continue;
			}
			$expected_title = $get( 'expected_lab_result_title' );
			if ( $expected_title && $this->normalize_name( $post->post_title ) !== $this->normalize_name( $expected_title ) ) {
				$skipped['stale_title']++;
				continue;
			}

			$plan = array(
				'post_id' => $post_id,
				'decision' => $decision,
				'product_id' => absint( $get( 'product_id' ) ),
				'variation_id' => absint( $get( 'variation_id' ) ),
				'strength' => sanitize_text_field( $get( 'recommended_strength' ) ),
				'reason' => sanitize_text_field( $get( 'reason' ) ),
			);

			if ( 'map' === $decision ) {
				if ( ! $plan['product_id'] || ! function_exists( 'wc_get_product' ) ) {
					$skipped['invalid_woo']++;
					continue;
				}
				$product = wc_get_product( $plan['product_id'] );
				if ( ! $product || $product->is_type( 'variation' ) ) {
					$skipped['invalid_woo']++;
					continue;
				}
				if ( $plan['variation_id'] ) {
					$variation = wc_get_product( $plan['variation_id'] );
					if ( ! $variation || ! $variation->is_type( 'variation' ) || absint( $variation->get_parent_id() ) !== $plan['product_id'] ) {
						$skipped['invalid_woo']++;
						continue;
					}
				} elseif ( $product->is_type( 'variable' ) ) {
					/* Never import a variable parent without the exact variation ID. */
					$skipped['invalid_woo']++;
					continue;
				}
			}
			$plans[] = $plan;
		}
		fclose( $fh );

		if ( ! $plans ) {
			$this->redirect_with_result( array( 'type' => 'error', 'message' => 'No HIGH-confidence reviewed rows were eligible to apply. Nothing was changed.' ) );
		}

		$backup_id = $this->create_backup( 'Reviewed cross-check import backup' );
		$mapped = 0;
		$library_only = 0;
		foreach ( $plans as $plan ) {
			$post_id = absint( $plan['post_id'] );
			if ( 'library_only' === $plan['decision'] ) {
				delete_post_meta( $post_id, '_ioncoa_product_id' );
				delete_post_meta( $post_id, '_ioncoa_variation_id' );
				delete_post_meta( $post_id, '_ioncoa_strength' );
				update_post_meta( $post_id, '_ioncoa_mapping_source', 'reviewed_import_library_only' );
				update_post_meta( $post_id, '_ioncoa_mapping_confidence', 'confirmed' );
				update_post_meta( $post_id, '_ioncoa_mapping_status', 'Reviewed cross-check: Library Only. Backup ' . $backup_id . '. ' . $plan['reason'] );
				update_post_meta( $post_id, self::LIBRARY_ONLY_META, '1' );
				$library_only++;
				continue;
			}

			delete_post_meta( $post_id, self::LIBRARY_ONLY_META );
			update_post_meta( $post_id, '_ioncoa_product_id', absint( $plan['product_id'] ) );
			update_post_meta( $post_id, '_ioncoa_variation_id', absint( $plan['variation_id'] ) );
			update_post_meta( $post_id, '_ioncoa_strength', sanitize_text_field( $plan['strength'] ) );
			update_post_meta( $post_id, '_ioncoa_mapping_source', 'reviewed_import' );
			update_post_meta( $post_id, '_ioncoa_mapping_confidence', 'confirmed' );
			update_post_meta( $post_id, '_ioncoa_mapping_status', 'Reviewed cross-check mapping. Backup ' . $backup_id . '. ' . $plan['reason'] );
			$mapped++;
		}

		$this->redirect_with_result(
			array(
				'type' => 'reviewed_import',
				'backup_id' => $backup_id,
				'mapped' => $mapped,
				'library_only' => $library_only,
				'skipped' => $skipped,
				'rows_read' => $rows_read,
			),
			array()
		);
	}


	/** Normalize a reviewed-repair title while preserving digits and blend slashes semantically. */
	private function repair_title_key( $value ) {
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$value = str_replace( "\xC2\xA0", ' ', $value );
		$value = str_replace( array( '—', '–', '−' ), '-', $value );
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );
		return strtolower( $value );
	}

	private function repair_url_key( $value ) {
		$value = html_entity_decode( trim( (string) $value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		return strtolower( untrailingslashit( $value ) );
	}

	private function batch_matches_reviewed_row( $batch, $batch_number, $url ) {
		if ( ! is_array( $batch ) ) return false;
		if ( trim( (string) ( $batch['batch'] ?? '' ) ) !== trim( (string) $batch_number ) ) return false;
		$wanted = $this->repair_url_key( $url );
		if ( ! $wanted ) return false;
		$image = $this->repair_url_key( $batch['image'] ?? '' );
		$link  = $this->repair_url_key( $batch['link'] ?? '' );
		return $wanted === $image || $wanted === $link;
	}

	/** Return the exact human-facing variation strength, preserving slash blends. */
	private function exact_variation_strength( $variation_id ) {
		if ( ! $variation_id || ! function_exists( 'wc_get_product' ) ) return '';
		$variation = wc_get_product( absint( $variation_id ) );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) return '';
		foreach ( (array) $variation->get_attributes() as $value ) {
			$text = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
			if ( preg_match( '/(\d+(?:\.\d+)?(?:\s*\/\s*\d+(?:\.\d+)?)?)\s*(mcg|mg|g|ml|iu|units?)\b/i', $text, $m ) ) {
				$amount = preg_replace( '/\s*\/\s*/', '/', trim( $m[1] ) );
				return $amount . strtolower( $m[2] );
			}
		}
		return '';
	}

	private function validate_repair_target( $product_id, $variation_id ) {
		$product_id = absint( $product_id );
		$variation_id = absint( $variation_id );
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) return false;
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variation' ) ) return false;
		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_type( 'variation' ) || absint( $variation->get_parent_id() ) !== $product_id ) return false;
		}
		return true;
	}

	private function load_reviewed_batch_repair_csv( $tmp ) {
		$result = array( 'plans' => array(), 'skipped' => array(), 'rows_read' => 0 );
		if ( ! $tmp || ! is_readable( $tmp ) ) return new WP_Error( 'repair_file', 'The reviewed forensic repair CSV could not be read.' );
		$fh = fopen( $tmp, 'r' );
		if ( ! $fh ) return new WP_Error( 'repair_file', 'The reviewed forensic repair CSV could not be opened.' );
		$header = fgetcsv( $fh );
		if ( ! is_array( $header ) ) { fclose( $fh ); return new WP_Error( 'repair_header', 'The reviewed forensic repair CSV has no header row.' ); }
		$header = array_map( static function( $v ) { return trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $v ) ); }, $header );
		$required = array( 'operation','source_post_id','expected_source_title','expected_batch_number','expected_url','target_post_id','target_product_id','target_variation_id','target_strength','confidence','reason','evidence' );
		foreach ( $required as $col ) { if ( ! in_array( $col, $header, true ) ) { fclose( $fh ); return new WP_Error( 'repair_header', 'Reviewed forensic repair CSV is missing required column: ' . $col ); } }
		$idx = array_flip( $header );
		$allowed = array( 'restore_canonical','remove_batch','move_batch','map_post','library_only_post','dedupe_exact_post' );
		$skip = array( 'non_high'=>0,'unsupported'=>0,'missing_post'=>0,'stale_title'=>0,'batch_not_found'=>0,'invalid_target'=>0,'missing_target_post'=>0 );
		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			$result['rows_read']++;
			if ( $result['rows_read'] > 1000 ) break;
			$get = static function( $name ) use ( $row, $idx ) { $i = $idx[$name]; return isset( $row[$i] ) ? trim( (string) $row[$i] ) : ''; };
			if ( 'HIGH' !== strtoupper( $get('confidence') ) ) { $skip['non_high']++; continue; }
			$operation = sanitize_key( $get('operation') );
			if ( ! in_array( $operation, $allowed, true ) ) { $skip['unsupported']++; continue; }
			$source_post_id = absint( $get('source_post_id') );
			$post = $source_post_id ? get_post( $source_post_id ) : null;
			if ( ! $post || 'lab_result' !== $post->post_type ) { $skip['missing_post']++; continue; }
			$expected_title = $get('expected_source_title');
			if ( $expected_title && $this->repair_title_key( $post->post_title ) !== $this->repair_title_key( $expected_title ) ) { $skip['stale_title']++; continue; }
			$plan = array(
				'operation'=>$operation,'source_post_id'=>$source_post_id,'expected_source_title'=>$expected_title,
				'expected_batch_number'=>$get('expected_batch_number'),'expected_url'=>$get('expected_url'),
				'target_post_id'=>absint($get('target_post_id')),'target_product_id'=>absint($get('target_product_id')),
				'target_variation_id'=>absint($get('target_variation_id')),'target_strength'=>sanitize_text_field($get('target_strength')),
				'reason'=>sanitize_text_field($get('reason')),'evidence'=>sanitize_text_field($get('evidence')),
			);
			if ( in_array( $operation, array( 'remove_batch','move_batch' ), true ) ) {
				$batches = get_post_meta( $source_post_id, '_lab_batches', true );
				$found = false;
				foreach ( is_array($batches) ? $batches : array() as $batch ) { if ( $this->batch_matches_reviewed_row( $batch, $plan['expected_batch_number'], $plan['expected_url'] ) ) { $found = true; break; } }
				if ( ! $found ) { $skip['batch_not_found']++; continue; }
			}
			if ( in_array( $operation, array( 'restore_canonical','map_post','move_batch' ), true ) ) {
				if ( ! $this->validate_repair_target( $plan['target_product_id'], $plan['target_variation_id'] ) ) { $skip['invalid_target']++; continue; }
			}
			if ( 'move_batch' === $operation ) {
				$target = $plan['target_post_id'] ? get_post( $plan['target_post_id'] ) : null;
				if ( ! $target || 'lab_result' !== $target->post_type ) { $skip['missing_target_post']++; continue; }
			}
			$result['plans'][] = $plan;
		}
		fclose( $fh );
		$result['skipped'] = $skip;
		return $result;
	}

	private function set_repair_mapping( $post_id, $product_id, $variation_id, $strength, $backup_id, $reason ) {
		delete_post_meta( $post_id, self::LIBRARY_ONLY_META );
		update_post_meta( $post_id, '_ioncoa_product_id', absint( $product_id ) );
		update_post_meta( $post_id, '_ioncoa_variation_id', absint( $variation_id ) );
		$exact = $this->exact_variation_strength( $variation_id );
		update_post_meta( $post_id, '_ioncoa_strength', sanitize_text_field( $exact ?: $strength ) );
		update_post_meta( $post_id, '_ioncoa_mapping_source', 'reviewed_batch_repair' );
		update_post_meta( $post_id, '_ioncoa_mapping_confidence', 'confirmed' );
		update_post_meta( $post_id, '_ioncoa_mapping_status', 'Reviewed forensic batch repair. Backup ' . $backup_id . '. ' . $reason );
	}

	private function sync_exact_variation_strengths() {
		$changed = 0;
		foreach ( $this->get_lab_ids( array( 'publish', self::CONSOLIDATED_STATUS ) ) as $post_id ) {
			$variation_id = absint( get_post_meta( $post_id, '_ioncoa_variation_id', true ) );
			if ( ! $variation_id ) continue;
			$exact = $this->exact_variation_strength( $variation_id );
			if ( ! $exact ) continue;
			$current = trim( (string) get_post_meta( $post_id, '_ioncoa_strength', true ) );
			if ( $current !== $exact ) { update_post_meta( $post_id, '_ioncoa_strength', $exact ); $changed++; }
		}
		return $changed;
	}

	private function apply_reviewed_batch_repair_plans( $plans, $backup_id ) {
		$stats = array( 'restore_canonical'=>0,'remove_batch'=>0,'move_batch'=>0,'map_post'=>0,'library_only_post'=>0,'dedupe_exact_post'=>0,'skipped_live'=>0,'exact_labels_synced'=>0 );
		foreach ( (array) $plans as $plan ) {
			$op = $plan['operation']; $source = absint( $plan['source_post_id'] );
			$post = $source ? get_post( $source ) : null;
			if ( ! $post || 'lab_result' !== $post->post_type || $this->repair_title_key( $post->post_title ) !== $this->repair_title_key( $plan['expected_source_title'] ) ) { $stats['skipped_live']++; continue; }

			if ( 'restore_canonical' === $op ) {
				if ( ! $this->validate_repair_target( $plan['target_product_id'], $plan['target_variation_id'] ) ) { $stats['skipped_live']++; continue; }
				wp_update_post( array( 'ID'=>$source, 'post_status'=>'publish' ) );
				delete_post_meta( $source, '_ioncoa_consolidated_into' );
				delete_post_meta( $source, '_ioncoa_consolidated_backup_id' );
				$this->set_repair_mapping( $source, $plan['target_product_id'], $plan['target_variation_id'], $plan['target_strength'], $backup_id, $plan['reason'] );
				$stats[$op]++; continue;
			}

			if ( 'map_post' === $op ) {
				if ( ! $this->validate_repair_target( $plan['target_product_id'], $plan['target_variation_id'] ) ) { $stats['skipped_live']++; continue; }
				$this->set_repair_mapping( $source, $plan['target_product_id'], $plan['target_variation_id'], $plan['target_strength'], $backup_id, $plan['reason'] );
				$stats[$op]++; continue;
			}

			if ( 'library_only_post' === $op ) {
				delete_post_meta( $source, '_ioncoa_product_id' ); delete_post_meta( $source, '_ioncoa_variation_id' ); delete_post_meta( $source, '_ioncoa_strength' );
				update_post_meta( $source, self::LIBRARY_ONLY_META, '1' ); update_post_meta( $source, '_ioncoa_mapping_source', 'reviewed_batch_repair_library_only' );
				update_post_meta( $source, '_ioncoa_mapping_confidence', 'confirmed' ); update_post_meta( $source, '_ioncoa_mapping_status', 'Reviewed forensic audit: Library Only. Backup ' . $backup_id . '. ' . $plan['reason'] );
				$stats[$op]++; continue;
			}

			if ( 'dedupe_exact_post' === $op ) {
				$batches = get_post_meta( $source, '_lab_batches', true ); if ( ! is_array($batches) ) { $stats['skipped_live']++; continue; }
				$out=array(); $by=array(); $removed=0;
				foreach ( $batches as $batch ) {
					if ( ! is_array($batch) ) continue;
					$key = strtolower( trim((string)($batch['batch']??'')) . '|' . $this->repair_url_key( $this->batch_url($batch) ) );
					if ( isset($by[$key]) ) {
						$i=$by[$key]; $out[$i]['current'] = ( !empty($out[$i]['current']) || !empty($batch['current']) ) ? 1 : 0;
						foreach ( array('date','purity','mass','link','image') as $field ) { if ( empty($out[$i][$field]) && !empty($batch[$field]) ) $out[$i][$field]=$batch[$field]; }
						$removed++;
					} else { $by[$key]=count($out); $out[]=$batch; }
				}
				if ( $removed ) { update_post_meta( $source, '_lab_batches', array_values($out) ); $stats[$op]+=$removed; } else { $stats['skipped_live']++; }
				continue;
			}

			$batches = get_post_meta( $source, '_lab_batches', true ); if ( ! is_array($batches) ) { $stats['skipped_live']++; continue; }
			$matched=array(); $remaining=array();
			foreach ( $batches as $batch ) { if ( $this->batch_matches_reviewed_row( $batch, $plan['expected_batch_number'], $plan['expected_url'] ) ) $matched[]=$batch; else $remaining[]=$batch; }
			if ( ! $matched ) { $stats['skipped_live']++; continue; }
			if ( 'remove_batch' === $op ) { update_post_meta( $source, '_lab_batches', array_values($remaining) ); $stats[$op]+=count($matched); continue; }
			if ( 'move_batch' === $op ) {
				$target=absint($plan['target_post_id']); if ( ! $target || ! get_post($target) || ! $this->validate_repair_target($plan['target_product_id'],$plan['target_variation_id']) ) { $stats['skipped_live']++; continue; }
				$target_batches=get_post_meta($target,'_lab_batches',true); if(!is_array($target_batches))$target_batches=array();
				foreach($matched as $batch){ $exists=false; foreach($target_batches as $tb){ if($this->batch_matches_reviewed_row($tb,$batch['batch']??'', $this->batch_url($batch))){$exists=true;break;} } if(!$exists)$target_batches[]=$batch; }
				update_post_meta($source,'_lab_batches',array_values($remaining)); update_post_meta($target,'_lab_batches',array_values($target_batches));
				$this->set_repair_mapping($target,$plan['target_product_id'],$plan['target_variation_id'],$plan['target_strength'],$backup_id,$plan['reason']);
				$stats[$op]+=count($matched); continue;
			}
		}
		$stats['exact_labels_synced'] = $this->sync_exact_variation_strengths();
		return $stats;
	}

	public function handle_preview_batch_repair() {
		if ( ! current_user_can('edit_products') ) wp_die('Permission denied.');
		check_admin_referer('ioncoa_safe_preview_batch_repair');
		if ( empty($_FILES['reviewed_batch_repair_csv']) || UPLOAD_ERR_OK !== (int)($_FILES['reviewed_batch_repair_csv']['error']??-1) ) $this->redirect_with_result(array('type'=>'error','message'=>'Reviewed forensic repair CSV upload failed.'));
		$parsed=$this->load_reviewed_batch_repair_csv((string)$_FILES['reviewed_batch_repair_csv']['tmp_name']);
		if(is_wp_error($parsed))$this->redirect_with_result(array('type'=>'error','message'=>$parsed->get_error_message()));
		$counts=array(); foreach($parsed['plans'] as $p){$counts[$p['operation']]=($counts[$p['operation']]??0)+1;}
		$this->redirect_with_result(array('type'=>'batch_repair_preview','rows_read'=>$parsed['rows_read'],'eligible'=>count($parsed['plans']),'counts'=>$counts,'skipped'=>$parsed['skipped']));
	}

	public function handle_apply_batch_repair() {
		if ( ! current_user_can('edit_products') ) wp_die('Permission denied.');
		check_admin_referer('ioncoa_safe_apply_batch_repair');
		if ( empty($_FILES['reviewed_batch_repair_csv']) || UPLOAD_ERR_OK !== (int)($_FILES['reviewed_batch_repair_csv']['error']??-1) ) $this->redirect_with_result(array('type'=>'error','message'=>'Reviewed forensic repair CSV upload failed.'));
		$parsed=$this->load_reviewed_batch_repair_csv((string)$_FILES['reviewed_batch_repair_csv']['tmp_name']);
		if(is_wp_error($parsed))$this->redirect_with_result(array('type'=>'error','message'=>$parsed->get_error_message()));
		if(empty($parsed['plans']))$this->redirect_with_result(array('type'=>'error','message'=>'No reviewed HIGH-confidence forensic repair operations still match the live site. Nothing was changed.'));
		$backup_id=$this->create_backup('Reviewed forensic COA batch repair backup');
		$stats=$this->apply_reviewed_batch_repair_plans($parsed['plans'],$backup_id);
		$this->redirect_with_result(array('type'=>'batch_repair_apply','backup_id'=>$backup_id,'rows_read'=>$parsed['rows_read'],'eligible'=>count($parsed['plans']),'stats'=>$stats,'skipped'=>$parsed['skipped']));
	}

	private function render_review_queue() {
		$analysis = $this->analyze();
		$rows = $analysis['needs_review'];
		$page = max( 1, absint( $_GET['review_page'] ?? 1 ) );
		$per_page = 50;
		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page = min( $page, $pages );
		$slice = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
		echo '<div class="ioncoa-review-panel"><h2>Mapping Review Queue <span class="count">(' . esc_html( $total ) . ')</span></h2><p>These records were intentionally not auto-mapped. Open each review, choose the exact Woo product/variation, or mark historical/discontinued records Library Only.</p><p><a href="' . esc_url( $this->safety_page_url() ) . '">&larr; Back to Safety Overview</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>Lab Result</th><th>Why it needs review</th><th>Action</th></tr></thead><tbody>';
		foreach ( $slice as $row ) {
			$url = $this->safety_page_url( array( 'review_post_id' => absint( $row['post_id'] ) ) );
			echo '<tr><td><strong>' . esc_html( $row['title'] ) . '</strong><br><small>#' . esc_html( $row['post_id'] ) . '</small></td><td>' . esc_html( $row['reason'] ) . '</td><td><a class="button button-primary" href="' . esc_url( $url ) . '">Review / Resolve</a></td></tr>';
		}
		echo '</tbody></table>';
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$url = $this->safety_page_url( array( 'ioncoa_section' => 'review_queue', 'review_page' => $i ) );
				echo $i === $page ? '<span class="button disabled">' . esc_html( $i ) . '</span> ' : '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $i ) . '</a> ';
			}
			echo '</div></div>';
		}
		echo '</div>';
	}

	public function render_page() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( 'Permission denied.' );
		}
		$result = $this->get_last_result();
		$backups = $this->backups_index();
		?>
		<div class="wrap">
			<h1>ION COA Safety &amp; Consolidation</h1>
			<p>This tool fixes duplicate Lab Result records by mapping only when the WooCommerce product and actual variation can be confirmed safely. It does <strong>not</strong> trust legacy COA URLs by themselves.</p>

			<div class="notice notice-warning inline"><p><strong>Safety model:</strong> Dry-run first. Every real consolidation or manual resolution automatically creates a database backup. Duplicate Lab Result posts are moved to the dedicated <strong>Consolidated</strong> archive, never deleted. You can roll back from this page.</p></div>

			<style>
				.ioncoa-safety-nav{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0 20px}.ioncoa-review-panel{max-width:1250px;background:#fff;border:1px solid #ccd0d4;border-left:4px solid #2271b1;padding:18px;margin:18px 0}.ioncoa-conflict-card{border:1px solid #dcdcde;background:#f8f9fa;border-radius:6px;padding:14px;margin:14px 0}.ioncoa-option-row{display:flex;justify-content:space-between;gap:18px;align-items:center;background:#fff;border:1px solid #dcdcde;border-radius:5px;padding:12px;margin:9px 0}.ioncoa-option-row code{display:inline-block;max-width:720px;white-space:normal;word-break:break-all}.ioncoa-option-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.ioncoa-option-actions form{margin:0}.ioncoa-current-chip{display:inline-block;background:#00a32a;color:#fff;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:700}.ioncoa-search-wrap{position:relative;max-width:850px}.ioncoa-search-wrap input{width:100%;min-height:40px}.ioncoa-search-wrap #ioncoa-safe-review-results{display:none;position:absolute;z-index:10000;left:0;right:0;top:100%;max-height:340px;overflow:auto;background:#fff;border:1px solid #8c8f94;box-shadow:0 5px 16px rgba(0,0,0,.15)}.ioncoa-search-wrap #ioncoa-safe-review-results button{display:block;width:100%;text-align:left;padding:10px 12px;border:0;border-bottom:1px solid #eee;background:#fff;cursor:pointer}.ioncoa-search-wrap #ioncoa-safe-review-results button:hover{background:#f0f6fc}.ioncoa-search-empty{padding:10px 12px;color:#646970}.ioncoa-review-facts{max-width:850px;margin:12px 0}.ioncoa-review-facts th{width:220px}.ioncoa-mini-batches>div{padding:7px 0;border-bottom:1px solid #eee}#ioncoa-safe-selected{max-width:850px;margin:10px 0;padding:10px 12px;background:#f0f6fc;border:1px solid #72aee6;border-radius:4px}@media(max-width:900px){.ioncoa-option-row{display:block}.ioncoa-option-actions{margin-top:10px}}
			</style>
			<div class="ioncoa-safety-nav">
				<a class="button" href="<?php echo esc_url( $this->safety_page_url() ); ?>">Safety Overview</a>
				<a class="button" href="<?php echo esc_url( $this->safety_page_url( array( 'ioncoa_section' => 'review_queue' ) ) ); ?>">Mapping Review Queue</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=lab_result&ioncoa_view=library_only' ) ); ?>">Library Only</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=lab_result&ioncoa_view=consolidated' ) ); ?>">Consolidated Archive</a>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ioncoa_safe_export_crosscheck' ), admin_url( 'admin-post.php' ) ), 'ioncoa_safe_export_crosscheck' ) ); ?>">Export Cross-Check Package</a>
				<a class="button button-primary" style="background:#7c3aed;border-color:#7c3aed;" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ioncoa_safe_export_full_audit' ), admin_url( 'admin-post.php' ) ), 'ioncoa_safe_export_full_audit' ) ); ?>" onclick="return confirm('This is READ-ONLY but can create a large ZIP because it includes the actual local COA image/PDF files. Continue?');">Export FULL COA Audit ZIP</a>
			</div>

			<div style="max-width:1250px;background:#fff1f0;border:1px solid #d63638;border-left:5px solid #d63638;padding:14px 16px;margin:14px 0 18px;">
				<h2 style="margin-top:0;color:#8a1f11;">Legacy Filename-Only MG Repair Disabled</h2>
				<p>The full forensic audit proved that filenames can be misleading for blends and even some single-strength reports. <strong>v1.7.9 will not run the old automatic filename-only repair.</strong> Use the reviewed forensic Batch Repair CSV below instead.</p>
			</div>

			<div style="max-width:1250px;background:#f0fdf4;border:1px solid #86efac;border-left:5px solid #16a34a;padding:14px 16px;margin:14px 0 18px;">
				<h2 style="margin-top:0;">Apply Reviewed Forensic Batch Repair CSV</h2>
				<p>This is the repair path for the full COA audit. It validates the <strong>exact Lab Result ID + original title + batch number + exact COA URL</strong>, validates live Woo Product/Variation IDs, creates one full backup before any write, and never deletes the physical COA media files.</p>
				<p>It can restore a pre-consolidation canonical to the correct variation, remove only proven wrong batch references, move a batch to a proven target variation, de-duplicate exact duplicate rows, mark discontinued strengths Library Only, and synchronize the saved frontend strength label to the exact Woo variation such as <strong>5/5mg</strong>, <strong>10/10mg</strong>, or <strong>10/3mg</strong>.</p>
				<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ioncoa_safe_preview_batch_repair">
						<?php wp_nonce_field( 'ioncoa_safe_preview_batch_repair' ); ?>
						<input type="file" name="reviewed_batch_repair_csv" accept=".csv,text/csv" required>
						<button class="button">Preview Forensic Repair</button>
					</form>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('This will revalidate every reviewed operation, create ONE FULL backup, then apply only rows that still exactly match the live Lab Result/batch/URL/Woo IDs. No physical COA files are deleted. Continue?');">
						<input type="hidden" name="action" value="ioncoa_safe_apply_batch_repair">
						<?php wp_nonce_field( 'ioncoa_safe_apply_batch_repair' ); ?>
						<input type="file" name="reviewed_batch_repair_csv" accept=".csv,text/csv" required>
						<button class="button button-primary" style="background:#16a34a;border-color:#16a34a;">Backup &amp; Apply Reviewed Forensic Repair</button>
					</form>
				</div>
			</div>

			<div style="max-width:1250px;background:#f0f6fc;border:1px solid #72aee6;border-left:4px solid #2271b1;padding:14px 16px;margin:14px 0 18px;">
				<h2 style="margin-top:0;">Apply Reviewed Cross-Check CSV</h2>
				<p>Use the reviewed import CSV after cross-checking historical Lab Result names against the current WooCommerce catalog. This importer applies <strong>HIGH-confidence rows only</strong>, skips MEDIUM/REVIEW rows, validates the current Lab Result title and live Woo IDs again, and creates one full backup before any write.</p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('This will validate the reviewed CSV, create a FULL backup, then apply HIGH-confidence mappings and Library Only decisions. MEDIUM/REVIEW rows are skipped. Continue?');">
					<input type="hidden" name="action" value="ioncoa_safe_import_reviewed">
					<?php wp_nonce_field( 'ioncoa_safe_import_reviewed' ); ?>
					<input type="file" name="reviewed_mapping_csv" accept=".csv,text/csv" required>
					<button class="button button-primary" style="margin-left:8px;">Backup &amp; Apply Reviewed HIGH-Confidence CSV</button>
				</form>
			</div>

			<?php
			$this->render_result( $result );
			if ( isset( $_GET['resolve_product_id'] ) ) {
				$this->render_conflict_resolver_panel( absint( $_GET['resolve_product_id'] ), absint( $_GET['resolve_variation_id'] ?? 0 ) );
			} elseif ( isset( $_GET['review_post_id'] ) ) {
				$this->render_review_panel( absint( $_GET['review_post_id'] ) );
			} elseif ( isset( $_GET['ioncoa_section'] ) && 'review_queue' === sanitize_key( wp_unslash( $_GET['ioncoa_section'] ) ) ) {
				$this->render_review_queue();
			}
			?>

			<div style="display:flex;gap:10px;flex-wrap:wrap;margin:20px 0;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ioncoa_safe_dry_run">
					<?php wp_nonce_field( 'ioncoa_safe_dry_run' ); ?>
					<button class="button button-primary">1. Run Dry Analysis</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ioncoa_safe_backup_only">
					<?php wp_nonce_field( 'ioncoa_safe_backup_only' ); ?>
					<button class="button">Create Backup Only</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('This will create a backup first, then safely map/consolidate non-conflicting groups. Duplicate posts are moved to Consolidated, not deleted. Continue?');">
					<input type="hidden" name="action" value="ioncoa_safe_consolidate">
					<?php wp_nonce_field( 'ioncoa_safe_consolidate' ); ?>
					<button class="button button-primary" style="background:#008a20;border-color:#008a20;">Consolidate ALL Safe Groups</button>
				</form>
				<div style="width:100%;padding:9px 12px;background:#fff8e5;border-left:4px solid #dba617;"><strong>Renamed-product sites:</strong> export the Cross-Check Package and review mappings before using Auto-Finish. Auto-Finish intentionally refuses fuzzy/name-guess mappings.</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ioncoa_safe_preview_finish">
					<?php wp_nonce_field( 'ioncoa_safe_preview_finish' ); ?>
					<button class="button">Preview Conservative Auto-Finish</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('This creates a FULL backup first. It never deletes COAs: proven mappings are applied; ambiguous mappings become Library Only; ambiguous same-batch files are preserved; existing multiple Current batches are approved as-is; obvious page pairs are grouped; then newly-safe duplicates are consolidated. Continue?');">
					<input type="hidden" name="action" value="ioncoa_safe_bulk_finish">
					<?php wp_nonce_field( 'ioncoa_safe_bulk_finish' ); ?>
					<button class="button button-primary" style="background:#7a3db8;border-color:#7a3db8;">Backup &amp; Auto-Finish Remaining</button>
				</form>
			</div>

			<h2>Backups / Rollback</h2>
			<?php if ( ! $backups ) : ?>
				<p>No backups created yet.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1100px;">
					<thead><tr><th>Backup</th><th>Created (GMT)</th><th>Lab Results</th><th>Actions</th></tr></thead>
					<tbody>
					<?php foreach ( $backups as $backup_id => $meta ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $backup_id ); ?></strong><br><small><?php echo esc_html( $meta['label'] ?? '' ); ?></small></td>
							<td><?php echo esc_html( $meta['created_gmt'] ?? '' ); ?></td>
							<td><?php echo esc_html( absint( $meta['posts'] ?? 0 ) ); ?></td>
							<td style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
								<?php $download = wp_nonce_url( add_query_arg( array( 'action' => 'ioncoa_safe_download_backup', 'backup_id' => $backup_id ), admin_url( 'admin-post.php' ) ), 'ioncoa_safe_download_backup' ); ?>
								<a class="button button-small" href="<?php echo esc_url( $download ); ?>">Download JSON</a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Rollback will restore Lab Result statuses and COA/mapping metadata to this backup. Continue?');">
									<input type="hidden" name="action" value="ioncoa_safe_rollback">
									<input type="hidden" name="backup_id" value="<?php echo esc_attr( $backup_id ); ?>">
									<?php wp_nonce_field( 'ioncoa_safe_rollback' ); ?>
									<button class="button button-small">Rollback to this backup</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_result( $result ) {
		if ( ! $result ) {
			return;
		}
		$type = $result['type'] ?? '';
		if ( 'analysis' === $type && ! empty( $result['analysis'] ) ) {
			$a = $result['analysis'];
			echo '<div class="notice notice-info inline"><p><strong>Dry analysis complete.</strong> Published: ' . esc_html( $a['total_published'] ) . '; safely mappable: ' . esc_html( $a['safe_mappable'] ) . '; Library Only: ' . esc_html( $a['library_only_count'] ?? 0 ) . '; duplicate groups safe to consolidate: ' . esc_html( count( $a['duplicate_groups'] ) ) . '; conflict groups skipped: ' . esc_html( count( $a['conflict_groups'] ) ) . '; needs review: ' . esc_html( count( $a['needs_review'] ) ) . '.</p></div>';
			if ( $a['duplicate_groups'] ) {
				echo '<h2>Safe duplicate groups</h2><p>You can still consolidate one group at a time, or tick several rows and consolidate only those selected groups together. <strong>Selected-group mode creates one full backup before the whole selected batch.</strong></p>';
				echo '<p><label><input type="checkbox" id="ioncoa-select-all-safe"> <strong>Select all safe groups shown</strong></label></p>';
				echo '<table class="widefat striped" style="max-width:1250px;"><thead><tr><th style="width:44px;">Select</th><th>Woo mapping</th><th>Lab Result posts</th><th>Merged batches</th><th>Targeted action</th></tr></thead><tbody>';
				foreach ( $a['duplicate_groups'] as $group ) {
					$label = $this->product_label( $group['product_id'], $group['variation_id'] );
					$value = absint( $group['product_id'] ) . ':' . absint( $group['variation_id'] );
					echo '<tr><td><input class="ioncoa-safe-group-check" type="checkbox" form="ioncoa-selected-safe-groups" name="selected_groups[]" value="' . esc_attr( $value ) . '"></td><td>' . esc_html( $label ) . '<br><small>Product #' . esc_html( $group['product_id'] ) . ( $group['variation_id'] ? ' / Variation #' . esc_html( $group['variation_id'] ) : '' ) . '</small></td><td>' . esc_html( implode( ', ', $group['post_ids'] ) ) . '</td><td>' . esc_html( $group['batch_inspection']['output_count'] ) . '</td><td>';
					$confirm = "return confirm('This will create a NEW full backup, then consolidate ONLY " . $label . ". No other Lab Result group will be changed. Continue?');";
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="' . esc_attr( $confirm ) . '">';
					echo '<input type="hidden" name="action" value="ioncoa_safe_consolidate_group">';
					echo '<input type="hidden" name="product_id" value="' . esc_attr( $group['product_id'] ) . '">';
					echo '<input type="hidden" name="variation_id" value="' . esc_attr( $group['variation_id'] ) . '">';
					wp_nonce_field( 'ioncoa_safe_consolidate_group' );
					echo '<button class="button button-primary">Backup &amp; Consolidate This Group</button></form>';
					echo '</td></tr>';
				}
				echo '</tbody></table>';
				echo '<form id="ioncoa-selected-safe-groups" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;" onsubmit="var n=document.querySelectorAll(\'.ioncoa-safe-group-check:checked\').length;if(!n){alert(\'Select at least one safe group first.\');return false;}return confirm(\'Create ONE full backup, then consolidate the \' + n + \' selected safe groups only? Groups that fail revalidation will be skipped.\');">';
				echo '<input type="hidden" name="action" value="ioncoa_safe_consolidate_selected">';
				wp_nonce_field( 'ioncoa_safe_consolidate_selected' );
				echo '<button class="button button-primary" style="background:#5b21b6;border-color:#5b21b6;">Backup &amp; Consolidate Selected Groups</button>';
				echo '</form>';
				echo '<script>document.addEventListener("DOMContentLoaded",function(){var a=document.getElementById("ioncoa-select-all-safe");if(!a)return;a.addEventListener("change",function(){document.querySelectorAll(".ioncoa-safe-group-check").forEach(function(c){c.checked=a.checked;});});});</script>';
			}
			if ( $a['conflict_groups'] ) {
				echo '<h2>Skipped conflicts</h2><p>These need a human decision. Use the resolver to open the conflicting COAs or choose the single Current batch. Every resolver action creates a full backup first.</p><table class="widefat striped" style="max-width:1250px;"><thead><tr><th>Woo mapping</th><th>Why it is skipped</th><th>Action</th></tr></thead><tbody>';
				foreach ( $a['conflict_groups'] as $group ) {
					$resolve = $this->safety_page_url( array( 'resolve_product_id' => absint( $group['product_id'] ), 'resolve_variation_id' => absint( $group['variation_id'] ) ) );
					echo '<tr><td>' . esc_html( $this->product_label( $group['product_id'], $group['variation_id'] ) ) . '<br><small>Product #' . esc_html( $group['product_id'] ) . ( $group['variation_id'] ? ' / Variation #' . esc_html( $group['variation_id'] ) : '' ) . '</small></td><td>' . esc_html( implode( ' ', $group['batch_inspection']['conflicts'] ) ) . '</td><td><a class="button button-primary" href="' . esc_url( $resolve ) . '">Resolve Conflict</a></td></tr>';
				}
				echo '</tbody></table>';
			}
			if ( $a['needs_review'] ) {
				echo '<h2>Needs review (first 50)</h2><p><a class="button" href="' . esc_url( $this->safety_page_url( array( 'ioncoa_section' => 'review_queue' ) ) ) . '">Open Full Mapping Review Queue</a></p><table class="widefat striped" style="max-width:1250px;"><thead><tr><th>Lab Result</th><th>Reason</th><th>Action</th></tr></thead><tbody>';
				foreach ( array_slice( $a['needs_review'], 0, 50 ) as $row ) {
					$edit = get_edit_post_link( $row['post_id'], '' );
					$review = $this->safety_page_url( array( 'review_post_id' => absint( $row['post_id'] ) ) );
					echo '<tr><td><a href="' . esc_url( $edit ) . '">' . esc_html( $row['title'] ) . ' (#' . esc_html( $row['post_id'] ) . ')</a></td><td>' . esc_html( $row['reason'] ) . '</td><td><a class="button button-primary" href="' . esc_url( $review ) . '">Review / Resolve</a></td></tr>';
				}
				echo '</tbody></table>';
			}
		} elseif ( 'finish_preview' === $type ) {
			$c = (array) ( $result['plan_counts'] ?? array() );
			echo '<div class="notice notice-info inline"><p><strong>Conservative Auto-Finish preview — no changes made.</strong> Proven mappings: ' . esc_html( absint( $c['map'] ?? 0 ) ) . '; ambiguous records that would become Library Only: ' . esc_html( absint( $c['library_only'] ?? 0 ) ) . '; obvious multi-page batches: ' . esc_html( absint( $c['multipart'] ?? 0 ) ) . '; same-batch multi-file sets preserved as-is: ' . esc_html( absint( $c['approve_same_batch'] ?? 0 ) ) . '; existing multiple-Current sets approved as-is: ' . esc_html( absint( $c['approve_multi_current'] ?? 0 ) ) . '.</p><p><strong>No COA is deleted by this mode.</strong> Use “Backup &amp; Auto-Finish Remaining” if these rules are acceptable.</p></div>';
		} elseif ( 'finish_applied' === $type ) {
			$r = (array) ( $result['result'] ?? array() );
			$con = (array) ( $r['consolidation'] ?? array() );
			$remaining = (array) ( $r['remaining'] ?? array() );
			echo '<div class="notice notice-success inline"><p><strong>Conservative bulk finish completed.</strong> Backup: ' . esc_html( $r['backup_id'] ?? '' ) . '. Proven mappings applied: ' . esc_html( absint( $r['mapped'] ?? 0 ) ) . '; ambiguous records kept as Library Only: ' . esc_html( absint( $r['library_only'] ?? 0 ) ) . '; obvious multi-page sets grouped: ' . esc_html( absint( $r['multipart'] ?? 0 ) ) . '; same-batch multi-file sets preserved: ' . esc_html( absint( $r['same_batch_preserved'] ?? 0 ) ) . '; multiple-Current sets preserved: ' . esc_html( absint( $r['multi_current_approved'] ?? 0 ) ) . '; duplicate groups consolidated: ' . esc_html( absint( $con['consolidated_groups'] ?? 0 ) ) . '; duplicates archived: ' . esc_html( absint( $con['drafted_duplicates'] ?? 0 ) ) . '; remaining conflicts: ' . esc_html( count( (array) ( $remaining['conflict_groups'] ?? array() ) ) ) . '; remaining needs review: ' . esc_html( count( (array) ( $remaining['needs_review'] ?? array() ) ) ) . '.</p></div>';
		} elseif ( 'strength_audit' === $type ) {
			$p = (array) ( $result['plan'] ?? array() );
			echo '<div class="notice notice-warning inline"><p><strong>MG integrity audit — no changes made.</strong> Checked mapped variable-product posts: ' . esc_html( absint( $p['checked_posts'] ?? 0 ) ) . '; batches checked: ' . esc_html( absint( $p['checked_batches'] ?? 0 ) ) . '; HIGH-confidence misplaced batches that can be moved automatically: <strong>' . esc_html( count( (array) ( $p['moves'] ?? array() ) ) ) . '</strong>; ambiguous/file evidence needing review: ' . esc_html( count( (array) ( $p['ambiguous'] ?? array() ) ) ) . '.</p>';
			if ( ! empty( $p['moves'] ) ) {
				echo '<table class="widefat striped" style="max-width:1250px;margin-top:10px;"><thead><tr><th>Currently filed under</th><th>Batch / explicit file evidence</th><th>Will move to</th></tr></thead><tbody>';
				foreach ( array_slice( $p['moves'], 0, 100 ) as $move ) {
					echo '<tr><td>' . esc_html( $this->product_label( $move['product_id'], $move['source_variation_id'] ) ) . '<br><small>Lab Result #' . esc_html( $move['source_post_id'] ) . '</small></td><td><strong>' . esc_html( $move['batch']['batch'] ?? '' ) . '</strong><br><code>' . esc_html( implode( ', ', (array) $move['evidence'] ) ) . '</code><br><small>' . esc_html( $this->batch_url( $move['batch'] ) ) . '</small></td><td>' . esc_html( $this->product_label( $move['product_id'], $move['target_variation_id'] ) ) . '<br><small>Target Lab Result #' . esc_html( $move['target_post_id'] ) . '</small></td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '<p><strong>No COA file was changed or deleted.</strong> Use the red repair button only after this preview looks correct.</p></div>';
		} elseif ( 'strength_repair' === $type ) {
			$r = (array) ( $result['result'] ?? array() );
			echo '<div class="notice notice-success inline"><p><strong>MG integrity repair completed.</strong> Backup: ' . esc_html( $r['backup_id'] ?? 'none needed' ) . '. Misfiled COA rows moved to the exact matching active variation: ' . esc_html( absint( $r['moved'] ?? 0 ) ) . '; duplicates already present at the target and removed from the wrong section: ' . esc_html( absint( $r['deduped'] ?? 0 ) ) . '; skipped due to live revalidation: ' . esc_html( absint( $r['skipped'] ?? 0 ) ) . '; ambiguous evidence left untouched: ' . esc_html( absint( $r['ambiguous'] ?? 0 ) ) . '.</p><p>Clear site/CDN cache, check the public Lab Results page and affected product pages, then run the MG Integrity Audit again. Do not resume consolidation until the high-confidence move count is 0.</p></div>';
		} elseif ( 'backup' === $type ) {
			echo '<div class="notice notice-success inline"><p><strong>Backup created:</strong> ' . esc_html( $result['backup_id'] ) . '. No Lab Result data was changed.</p></div>';
		} elseif ( 'consolidated' === $type ) {
			$r = $result['result'];
			echo '<div class="notice notice-success inline"><p><strong>Safe consolidation completed.</strong> Backup: ' . esc_html( $r['backup_id'] ) . '. Mapped records: ' . esc_html( $r['mapped_records'] ) . '; corrected unsafe mappings: ' . esc_html( $r['corrected_unsafe_mappings'] ) . '; duplicate groups consolidated: ' . esc_html( $r['consolidated_groups'] ) . '; duplicate posts archived as Consolidated: ' . esc_html( $r['drafted_duplicates'] ) . '; conflict groups skipped: ' . esc_html( $r['skipped_conflict_groups'] ) . '; still needs review: ' . esc_html( $r['needs_review_count'] ) . '.</p></div>';
		} elseif ( 'selected_groups_consolidated' === $type ) {
			$r = $result['result'] ?? array();
			echo '<div class="notice notice-success inline"><p><strong>Selected-group consolidation completed.</strong> Backup: ' . esc_html( $r['backup_id'] ?? '' ) . '. Selected: ' . esc_html( absint( $r['selected_count'] ?? 0 ) ) . '; completed: ' . esc_html( absint( $r['completed_count'] ?? 0 ) ) . '; skipped/failed revalidation: ' . esc_html( absint( $r['failed_count'] ?? 0 ) ) . '; Lab Result posts merged: ' . esc_html( absint( $r['posts_merged'] ?? 0 ) ) . '; merged batches: ' . esc_html( absint( $r['batches_merged'] ?? 0 ) ) . '; duplicates archived as Consolidated: ' . esc_html( absint( $r['archived'] ?? 0 ) ) . '.</p>';
			if ( ! empty( $r['errors'] ) ) {
				echo '<p><strong>Skipped groups:</strong></p><ul style="list-style:disc;margin-left:20px;">';
				foreach ( $r['errors'] as $err ) {
					echo '<li>' . esc_html( ( $err['label'] ?? 'Group' ) . ': ' . ( $err['message'] ?? 'Could not consolidate.' ) ) . '</li>';
				}
				echo '</ul>';
			}
			echo '<p>Run Dry Analysis again to refresh the remaining safe/conflict/review queues.</p></div>';
		} elseif ( 'group_consolidated' === $type ) {
			$r = $result['result'];
			echo '<div class="notice notice-success inline"><p><strong>Targeted consolidation completed:</strong> ' . esc_html( $r['label'] ) . '. Backup: ' . esc_html( $r['backup_id'] ) . '. Canonical Lab Result #' . esc_html( $r['canonical'] ) . '; Lab Result posts merged: ' . esc_html( $r['posts_merged'] ) . '; merged batches: ' . esc_html( $r['batches_merged'] ) . '; duplicates archived as Consolidated: ' . esc_html( $r['drafted'] ) . '. No other group was consolidated.</p></div>';
		} elseif ( 'group_error' === $type ) {
			echo '<div class="notice notice-error inline"><p><strong>Targeted consolidation stopped safely.</strong> ' . esc_html( $result['message'] ?? 'The group was not changed.' ) . ' A backup was still created first: ' . esc_html( $result['backup_id'] ?? '' ) . '.</p></div>';
		} elseif ( 'conflict_resolved' === $type || 'review_mapped' === $type || 'library_only' === $type || 'review_returned' === $type ) {
			echo '<div class="notice notice-success inline"><p><strong>Saved safely.</strong> ' . esc_html( $result['message'] ?? '' ) . ' Backup: ' . esc_html( $result['backup_id'] ?? '' ) . '.</p></div>';
		} elseif ( 'batch_repair_preview' === $type ) {
			$c=(array)($result['counts']??array()); $sk=(array)($result['skipped']??array());
			echo '<div class="notice notice-warning inline"><p><strong>Forensic repair preview — NO CHANGES MADE.</strong> CSV rows read: ' . esc_html(absint($result['rows_read']??0)) . '; eligible HIGH-confidence operations still matching live data: <strong>' . esc_html(absint($result['eligible']??0)) . '</strong>.</p><p>Restore canonicals: ' . esc_html(absint($c['restore_canonical']??0)) . '; exact wrong batch references to remove: ' . esc_html(absint($c['remove_batch']??0)) . '; batches to move to a proven variation: ' . esc_html(absint($c['move_batch']??0)) . '; exact mappings: ' . esc_html(absint($c['map_post']??0)) . '; Library Only decisions: ' . esc_html(absint($c['library_only_post']??0)) . '; exact duplicate-row cleanup operations: ' . esc_html(absint($c['dedupe_exact_post']??0)) . '.</p><p>Skipped because the live site no longer exactly matches the reviewed evidence: ' . esc_html(array_sum(array_map('absint',$sk))) . '. No backup was needed because preview is read-only.</p></div>';
		} elseif ( 'batch_repair_apply' === $type ) {
			$st=(array)($result['stats']??array()); $sk=(array)($result['skipped']??array());
			echo '<div class="notice notice-success inline"><p><strong>Reviewed forensic COA repair completed.</strong> Full backup: <strong>' . esc_html($result['backup_id']??'') . '</strong>.</p><p>Canonicals restored: ' . esc_html(absint($st['restore_canonical']??0)) . '; wrong batch references removed: ' . esc_html(absint($st['remove_batch']??0)) . '; batches moved to proven variation: ' . esc_html(absint($st['move_batch']??0)) . '; exact mappings applied: ' . esc_html(absint($st['map_post']??0)) . '; Library Only applied: ' . esc_html(absint($st['library_only_post']??0)) . '; exact duplicate rows removed: ' . esc_html(absint($st['dedupe_exact_post']??0)) . '; frontend exact variation labels synchronized: ' . esc_html(absint($st['exact_labels_synced']??0)) . '; live revalidation skips during apply: ' . esc_html(absint($st['skipped_live']??0)) . '.</p><p><strong>No physical COA image/PDF file was deleted.</strong> Clear site/CDN cache, inspect the Lab Results page and affected product pages, then run Dry Analysis. Do not consolidate again until those checks look correct.</p></div>';
		} elseif ( 'reviewed_import' === $type ) {
			$sk = (array) ( $result['skipped'] ?? array() );
			echo '<div class="notice notice-success inline"><p><strong>Reviewed cross-check import completed.</strong> Backup: ' . esc_html( $result['backup_id'] ?? '' ) . '. Mapped: ' . esc_html( absint( $result['mapped'] ?? 0 ) ) . '; Library Only: ' . esc_html( absint( $result['library_only'] ?? 0 ) ) . '; skipped non-HIGH: ' . esc_html( absint( $sk['non_high'] ?? 0 ) ) . '; keep-existing rows skipped: ' . esc_html( absint( $sk['keep_existing'] ?? 0 ) ) . '; stale title rows skipped: ' . esc_html( absint( $sk['stale_title'] ?? 0 ) ) . '; missing/non-published rows skipped: ' . esc_html( absint( $sk['missing_post'] ?? 0 ) ) . '; invalid Woo IDs skipped: ' . esc_html( absint( $sk['invalid_woo'] ?? 0 ) ) . '.</p><p>No Lab Result posts or COA files were deleted or consolidated by this import. Run Dry Analysis next to identify duplicate groups that are now safe to consolidate.</p></div>';
		} elseif ( 'rollback' === $type ) {
			echo '<div class="notice notice-success inline"><p><strong>Rollback completed.</strong> Restored ' . esc_html( $result['restored'] ) . ' Lab Result records from backup ' . esc_html( $result['backup_id'] ) . '.</p></div>';
		} elseif ( 'error' === $type ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $result['message'] ?? 'Unknown error.' ) . '</p></div>';
		}
	}
}

ION_COA_Safe_Consolidator::instance();
