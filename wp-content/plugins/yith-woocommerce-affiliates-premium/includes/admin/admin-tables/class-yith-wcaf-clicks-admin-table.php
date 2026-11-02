<?php
/**
 * Clicks Table class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Clicks_Admin_Table' ) ) {
	/**
	 * WooCommerce Clicks Table
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Clicks_Admin_Table extends YITH_WCAF_Abstract_Admin_Table {
		/**
		 * Class constructor method
		 *
		 * @param array $args Arguments for the parent constructor.
		 * @since 1.0.0
		 */
		public function __construct( $args = array() ) {
			// set available filters.
			$this->filters = array(
				'status'       => array(
					'default' => 'all',
				),
				'from'         => array(
					'query_var' => '_from',
				),
				'to'           => array(
					'query_var' => '_to',
				),
				'affiliate_id' => array(
					'sanitize'  => 'intval',
					'query_var' => '_affiliate_id',
				),
				'orderby'      => array(
					'default' => 'click_date',
				),
				'order'        => array(
					'default' => 'DESC',
				),
			);

			// set available views.
			$this->views = array(
				'all'       => _x( 'All', '[ADMIN] Clicks views', 'yith-woocommerce-affiliates' ),
				'converted' => _x( 'Converted', '[ADMIN] Clicks views', 'yith-woocommerce-affiliates' ),
			);

			// Set parent defaults.
			parent::__construct(
				array_merge(
					$args,
					array(
						'singular'      => 'click',
						'plural'        => 'clicks',
						'ajax'          => false,
						'empty_message' => _x( 'Sorry! There is no visit registered yet.', '[ADMIN] Affiliate empty table message', 'yith-woocommerce-affiliates' ),
					)
				)
			);
		}

		/* === COLUMNS METHODS === */

		/**
		 * Print column with affiliate user details
		 *
		 * @param YITH_WCAF_Click $item Current item row.
		 * @param array           $args Not in use.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_affiliate( $item, $args = array() ) {
			return parent::column_affiliate(
				$item,
				array(
					'show_links' => true,
				)
			);
		}

		/**
		 * Print column with click status (converted/not converted)
		 *
		 * @param YITH_WCAF_Click $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_status( $item ) {
			if ( ! $item->is_converted() ) {
				return '-';
			}

			return '<i class="yith-icon yith-icon-green-check-mark"></i>';
		}

		/**
		 * Print column with visited link
		 *
		 * @param YITH_WCAF_Click $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_link( $item ) {
			return sprintf( '%s<small class="meta">%s %s</small>', $item->get_link(), _x( 'Guest IP:', '[ADMIN] URL column in clicks table', 'yith-woocommerce-affiliates' ), $item->get_ip() );
		}

		/**
		 * Print column with user origin
		 *
		 * @param YITH_WCAF_Click $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_origin( $item ) {
			$origin = $item->get_origin();
			$base   = $item->get_origin_base();

			if ( ! empty( $origin ) && ! empty( $base ) ) {
				$column = sprintf( '%s<small class="meta">%s</small>', $base, $origin );
			} else {
				$column = _x( 'N/A', '[ADMIN] Empty admin table column', 'yith-woocommerce-affiliates' );
			}

			return $column;
		}

		/**
		 * Print column with click date
		 *
		 * @param YITH_WCAF_Click $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_date( $item ) {
			$date = $item->get_date( 'edit' );

			if ( $date ) {
				$column = $date->date_i18n( wc_date_format() );
			} else {
				$column = _x( 'N/A', '[ADMIN] Empty admin table column', 'yith-woocommerce-affiliates' );
			}

			return $column;
		}

		/**
		 * Print column with time passed between click and sale
		 *
		 * @param YITH_WCAF_Click $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_conv_time( $item ) {
			$date      = $item->get_date( 'edit' );
			$conv_date = $item->get_conversion_date( 'edit' );

			if ( ! $date || ! $conv_date ) {
				$column = _x( 'N/A', '[ADMIN] Empty admin table column', 'yith-woocommerce-affiliates' );
			} else {
				$column = human_time_diff( $conv_date->getTimestamp(), $date->getTimestamp() );
			}

			return $column;
		}

		/**
		 * Returns columns available in table
		 *
		 * @return array Array of columns of the table
		 * @since 1.0.0
		 */
		public function get_columns() {
			$columns = array(
				'cb'        => '<input type="checkbox" />',
				'date'      => _x( 'Date', '[ADMIN] Clicks table columns', 'yith-woocommerce-affiliates' ),
				'affiliate' => _x( 'Referrer', '[ADMIN] Clicks table columns', 'yith-woocommerce-affiliates' ),
				'order'     => _x( 'Order', '[ADMIN] Clicks table columns', 'yith-woocommerce-affiliates' ),
				'link'      => _x( 'Followed URL', '[ADMIN] Clicks table columns', 'yith-woocommerce-affiliates' ),
				'origin'    => _x( 'Origin URL', '[ADMIN] Clicks table columns', 'yith-woocommerce-affiliates' ),
				'conv_time' => _x( 'Conversion time', '[ADMIN] Clicks table columns', 'yith-woocommerce-affiliates' ),
				'status'    => _x( 'Converted', '[ADMIN] Clicks table columns', 'yith-woocommerce-affiliates' ),
			);

			return $columns;
		}

		/**
		 * Returns column to be sortable in table
		 *
		 * @return array Array of sortable columns
		 * @since 1.0.0
		 */
		public function get_sortable_columns() {
			$sortable_columns = array(
				'affiliate' => array( 'user_login', false ),
				'status'    => array( 'order_id', false ),
				'link'      => array( 'link', false ),
				'origin'    => array( 'origin', false ),
				'date'      => array( 'click_date', true ),
				'order'     => array( 'order_id', false ),
				'conv_time' => array( 'conv_time', false ),
			);

			return $sortable_columns;
		}

		/**
		 * Returns hidden columns for current table
		 *
		 * @return mixed Array of hidden columns
		 * @since 1.0.0
		 */
		public function get_hidden_columns() {
			return get_hidden_columns( get_current_screen() );
		}

		/**
		 * Returns an array of per view items count
		 *
		 * @param array $query_args Query arguments.
		 * @return array
		 */
		public function get_per_view_count( $query_args ) {
			return YITH_WCAF_Clicks()->per_status_count( false, $query_args );
		}

		/**
		 * Returns an array of per available views for current table
		 *
		 * @return array
		 */
		public function get_available_views() {
			return array_merge(
				parent::get_available_views(),
				wp_list_pluck( YITH_WCAF_Clicks::get_available_statuses(), 'slug' )
			);
		}

		/**
		 * Returns labels to be used for a specific view
		 *
		 * @param string $view  View slug.
		 * @param int    $count Count of items in the view (to choose between singular/plural).
		 *
		 * @return string
		 */
		public function get_view_label( $view, $count = 0 ) {
			if ( 'all' === $view ) {
				$label = _x( 'All', '[ADMIN] Clicks view', 'yith-woocommerce-affiliates' );
			} else {
				$label = YITH_WCAF_Clicks::get_readable_status( $view, $count );
			}

			return parent::get_view_label( $label, $count );
		}

		/**
		 * Return list of available bulk actions
		 *
		 * @return array Available bulk action
		 * @since 1.0.0
		 */
		public function get_bulk_actions() {
			$actions = array(
				'delete' => _x( 'Delete', '[ADMIN] Clicks bulk actions', 'yith-woocommerce-affiliates' ),
			);

			return $actions;
		}

		/**
		 * Print filters for current table
		 *
		 * @param string $which Top / Bottom.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		protected function extra_tablenav( $which ) {
			if ( 'top' !== $which ) {
				return;
			}

			$this->print_hidden_fields();
			$this->print_affiliate_filter();
			$this->print_datepicker( '_from' );
			$this->print_datepicker( '_to' );
			$this->print_filter_button();
			$this->print_reset_button();
		}

		/**
		 * Prepare items for table
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function prepare_items() {
			$query_args = $this->get_query_args();

			// sets pagination arguments.
			$per_page     = $this->get_items_per_page( 'edit_clicks_per_page' );
			$current_page = $this->get_pagenum();
			$hits         = YITH_WCAF_Clicks()->get_hits(
				array_merge(
					array(
						'limit'  => $per_page,
						'offset' => ( ( $current_page - 1 ) * $per_page ),
					),
					$query_args
				)
			);

			// sets columns headers.
			$columns  = $this->get_columns();
			$hidden   = $this->get_hidden_columns();
			$sortable = $this->get_sortable_columns();

			$this->_column_headers = array( $columns, $hidden, $sortable );

			// retrieve data for table.
			$this->items = $hits;

			// sets pagination args.
			$this->set_pagination_args(
				array(
					'total_items' => $hits->get_total_items(),
					'per_page'    => $per_page,
					'total_pages' => $hits->get_total_pages(),
				)
			);
		}

		/* === FILTER METHODS === */

		/**
		 * Prepare query arguments from filter parameters
		 *
		 * @return array Array of query parameters.
		 */
		public function get_query_args() {
			$query_vars = $this->get_query_vars();
			$query_args = parent::get_query_args();

			// set correct 'converted' values.
			if ( 'converted' === $query_vars['status'] ) {
				$query_args['converted'] = 'yes';
			} else if ( 'not-converted' === $query_vars['status'] ) {
				$query_args['converted'] = 'no';
			}

			return $query_args;
		}
	}
}
