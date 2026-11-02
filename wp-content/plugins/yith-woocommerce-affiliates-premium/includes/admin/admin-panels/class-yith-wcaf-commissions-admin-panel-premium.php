<?php
/**
 * Commissions admin panel handling
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Commissions_Admin_Panel_Premium' ) ) {
	/**
	 * Affiliates admin panel handling
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Commissions_Admin_Panel_Premium extends YITH_WCAF_Commissions_Admin_Panel {

		/**
		 * Init panel
		 *
		 * @return void
		 */
		public function __construct() {
			parent::__construct();

			$this->premium_actions();
			$this->premium_screen_columns();

			// premium options handling.
			add_filter( 'yith_wcaf_commissions_list_settings', array( $this, 'premium_options' ) );
			add_filter( 'yith_wcaf_commission_admin_actions', array( $this, 'filter_object_actions' ), 10, 3 );
			add_action( 'yith_wcaf_commission_details_panel', array( $this, 'output_commission_details' ) );
			add_filter( 'yith_wcaf_proceed_with_payment', array( $this, 'prevent_bacs_direct_payment' ), 10, 4 );
		}

		/**
		 * Get affiliates commissions table.
		 *
		 * @return YITH_WCAF_Commissions_Admin_Table
		 */
		protected function get_list_table() {
			return new YITH_WCAF_Commissions_Admin_Table_Premium();
		}

		/**
		 * Adds premium panel actions to registered one.
		 *
		 * @return void.
		 */
		public function premium_actions() {
			$this->admin_actions = array_merge(
				$this->admin_actions,
				array(
					'export_csv' => array( $this, 'export_csv_action' ),
				)
			);
		}

		/**
		 * Add premium screen columns to registered one.
		 *
		 * @return void
		 */
		public function premium_screen_columns() {
			$this->screen_columns = yith_wcaf_append_items(
				$this->screen_columns,
				'line_item_total',
				array(
					'line_item_discounts' => _x( 'Discounts', '[ADMIN] Commissions screen columns', 'yith-woocommerce-affiliates' ),
					'line_item_refunds'   => _x( 'Refunds', '[ADMIN] Commissions screen columns', 'yith-woocommerce-affiliates' ),
				)
			);
		}

		/**
		 * Filers plugin options to add premium-specific data
		 *
		 * @param array $options Array of options.
		 * @return array Filtered array of options.
		 */
		public function premium_options( $options ) {
			if ( $this->is_single_item() ) {
				$options = array(
					'commissions-list' => array(
						'commission_details' => array(
							'type'   => 'custom_tab',
							'action' => 'yith_wcaf_commission_details_panel',
						),
					),
				);
			} elseif ( isset( $options['commissions-list']['commissions_table'] ) ) {
				$options['commissions-list']['commissions_table']['list_table_class']     = 'YITH_WCAF_Commissions_Admin_Table_Premium';
				$options['commissions-list']['commissions_table']['list_table_class_dir'] = YITH_WCAF_INC . 'admin/admin-tables/class-yith-wcaf-commissions-table-premium.php';
			}

			return $options;
		}

		/**
		 * Filter actions available for each Affiliate object
		 *
		 * @param array                $actions       Array of available actions.
		 * @param int                  $commission_id Id of the current commission.
		 * @param YITH_WCAF_Commission $commission    Commission object.
		 *
		 * @return array Array of filtered actions
		 */
		public function filter_object_actions( $actions, $commission_id, $commission ) {
			$actions = array_merge(
				array(
					'view' => array(
						'label' => _x( 'View', '[ADMIN] Single commission actions', 'yith-woocommerce-affiliates' ),
						'url'   => YITH_WCAF_Admin()->get_tab_url( 'commissions', '', array( 'commission_id' => $commission_id ) ),
					),
				),
				$actions
			);

			return $actions;
		}

		/* === ADMIN ACTIONS === */

		/**
		 * Process export, and generate csv file to download with commissions
		 *
		 * @return void
		 * @since 1.1.1
		 */
		public function export_csv_action() {
			$table = new YITH_WCAF_Commissions_Admin_Table_Premium();
			$table->set_items_per_page( -1 );
			$table->prepare_items();

			$commissions = $table->items;

			// mark table object for garbage collection.
			unset( $table );

			/**
			 * APPLY_FILTERS: yith_wcaf_commissions_csv_heading
			 *
			 * Filters the array with the headings to export the commissions into CSV.
			 *
			 * @param array                            $csv_heading Array with the headings for the CSV export.
			 * @param YITH_WCAF_Commissions_Collection $commissions  Collection of the commissions to export.
			 */
			$headings = apply_filters(
				'yith_wcaf_commissions_csv_heading',
				array(
					'ID',
					'order_id',
					'line_item_id',
					'line_total',
					'product_id',
					'product_name',
					'affiliate_id',
					'rate',
					'amount',
					'refunds',
					'status',
					'created_at',
					'last_edit',
					'user_id',
					'user_login',
					'user_email',
					'categories',
				),
				$commissions
			);

			$sitename  = sanitize_key( get_bloginfo( 'name' ) );
			$sitename .= ( ! empty( $sitename ) ) ? '-' : '';
			$filename  = $sitename . 'commissions-' . gmdate( 'Y-m-d' ) . '.csv';

			header( 'Content-Description: File Transfer' );
			header( 'Content-Disposition: attachment; filename=' . $filename );
			header( 'Content-Type: text/xml; charset=' . get_option( 'blog_charset' ), true );

			$df = fopen( 'php://output', 'w' );

			fputcsv( $df, $headings );

			foreach ( $commissions as $commission ) {
				$row = $commission->to_array();

				// process extra info.
				$categories = wp_get_post_terms( $row['product_id'], 'product_cat' );

				if ( empty( $categories ) ) {
					$row['categories'] = '';
				} else {
					$column_items = array();

					foreach ( $categories as $category ) {
						$column_items[] = $category->name;
					}

					$row['categories'] = implode( ' | ', $column_items );
				}

				/**
				 * APPLY_FILTERS: yith_wcaf_commissions_csv_row
				 *
				 * Filters the array with the commission info to export into CSV.
				 *
				 * @param array $row      Commission info to export.
				 * @param array $headings Array with the headings for the CSV export.
				 */
				fputcsv( $df, apply_filters( 'yith_wcaf_commissions_csv_row', $row, $headings ) );
			}

			fclose( $df ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			die();
		}

		/**
		 * Prevent BACS payment from going directly to completed when created from this panel
		 *
		 * @param bool              $proceed    Whether to proceed with payment.
		 * @param int               $payment_id Payment id.
		 * @param YITH_WCAF_Payment $payment    Payment object.
		 * @param string            $gateway_id Gateway id.
		 *
		 * @return bool Filtered proceed value.
		 */
		public function prevent_bacs_direct_payment( $proceed, $payment_id, $payment, $gateway_id ) {
			if ( 'bacs' !== $gateway_id ) {
				return $proceed;
			}

			return false;
		}

		/* === PANEL HANDLING METHODS === */

		/**
		 * Returns an array of localized arguments for current panel
		 *
		 * @return array Array of localized variables specific to current panel.
		 */
		public function get_localize() {
			return array(
				'nonces' => array(
					'add_note'    => wp_create_nonce( 'add_note' ),
					'delete_note' => wp_create_nonce( 'delete_note' ),
				),
			);
		}

		/**
		 * Filters columns hidden by default, to add ones for current screen
		 *
		 * @param array $defaults Array of columns hidden by default.
		 * @return array Hidden columns.
		 */
		public function get_default_hidden_columns( $defaults ) {
			$defaults = array_merge(
				$defaults,
				array(
					'line_item_discounts',
					'line_item_refunds',
				)
			);

			return parent::get_default_hidden_columns( $defaults );
		}

		/**
		 * Output commission panel
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function output_commission_details() {
			// retrieve commissions.
			$commission_id = $this->get_single_item_id();
			$commission    = YITH_WCAF_Commission_Factory::get_commission( $commission_id );

			if ( ! $commission ) {
				// translators: 1. Payment id.
				wp_die( esc_html( sprintf( _x( 'Commission #%d doesn\'t exist.', '[ADMIN] Commission details page', 'yith-woocommerce-affiliates' ), $commission_id ) ) );
			}

			// retrieve affiliate user.
			$affiliate = $commission->get_affiliate();
			$user      = $affiliate ? $affiliate->get_user() : false;

			// retrieve order.
			$order = $commission->get_order();
			$item  = $commission->get_order_item();

			// retrieve product.
			$product = $commission->get_product();

			// retrieve notes.
			$notes = $commission->get_notes();

			// payments table.
			$payments_table = new YITH_WCAF_Payments_Admin_Table_Premium(
				array(
					'table_classes' => array( 'small-status' ),
					'empty_message' => _x( 'No payment registered for this commission yet', '[ADMIN] Affiliate detail panel, empty payments table', 'yith-woocommerce-affiliates' ),
				)
			);

			$payments_table->set_query_var( 'commissions', $commission->get_id() );
			$payments_table->set_items_per_page( 5 );
			$payments_table->set_visible_columns( array( 'id', 'status', 'amount', 'created_at' ) );
			$payments_table->hide_tablenav();
			$payments_table->prepare_items();

			// retrieve available action.
			$available_actions = wp_list_pluck( $commission->get_admin_actions(), 'label' );

			if ( isset( $available_actions['view'] ) ) {
				unset( $available_actions['view'] );
			}

			// require rate panel template.
			include YITH_WCAF_DIR . 'views/commissions/details-panel.php';
		}
	}
}
