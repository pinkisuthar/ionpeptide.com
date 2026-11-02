<?php
/**
 * Affiliate admin panel handling
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Affiliates_Admin_Panel_Premium' ) ) {
	/**
	 * Affiliates admin panel handling
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Affiliates_Admin_Panel_Premium extends YITH_WCAF_Affiliates_Admin_Panel {

		/**
		 * Init panel
		 *
		 * @return void
		 */
		public function __construct() {
			parent::__construct();

			$this->premium_notices();
			$this->premium_actions();
			$this->premium_screen_columns();

			// premium options handling.
			// add_filter( 'yith_wcaf_affiliates_list_settings', array( $this, 'premium_list_options' ) );
			add_filter( 'yith_wcaf_affiliate_admin_actions', array( $this, 'filter_object_actions' ), 10, 3 );
			add_action( 'yith_wcaf_affiliate_details_panel', array( $this, 'output_affiliate_details' ) );
		}

		/**
		 * Get the affiliates list table.
		 *
		 * @return YITH_WCAF_Affiliates_Admin_Table|YITH_WCAF_Affiliates_Admin_Table_Premium
		 */
		protected function get_affiliates_list_table() {
			require_once YITH_WCAF_INC . 'admin/admin-tables/class-yith-wcaf-affiliates-admin-table-premium.php';

			return new YITH_WCAF_Affiliates_Admin_Table_Premium();
		}

		/**
		 * Render the Affiliates List tab.
		 */
		public function render_affiliates_list_tab() {
			if ( $this->is_single_item() ) {
				$this->output_affiliate_details();
			} else {
				parent::render_affiliates_list_tab();
			}
		}

		/**
		 * Register premium actions for this panel
		 *
		 * @return void
		 */
		public function premium_actions() {
			$this->admin_actions = array_merge(
				$this->admin_actions,
				array(
					'process_orphan_commissions' => array( $this, 'process_orphan_commissions_action' ),
					'pay_commissions'            => array( $this, 'pay_commissions_action' ),
					'export_csv'                 => array( $this, 'export_csv_action' ),
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
				'created_at',
				array(
					'earnings' => _x( 'Refunds', '[ADMIN] Affiliate screen columns', 'yith-woocommerce-affiliates' ),
				)
			);
		}

		/**
		 * Register premium notices for this panel
		 *
		 * @return void
		 */
		public function premium_notices() {
			$this->admin_notices = array_merge(
				$this->admin_notices,
				array(
					'processed_commissions' => array(
						'success' => _x( 'Affiliate\'s commissions processed correctly', '[ADMIN] Affiliates action messages', 'yith-woocommerce-affiliates' ),
						'error'   => _x( 'There was an error while processing affiliate\'s commissions', '[ADMIN] Affiliates action messages', 'yith-woocommerce-affiliates' ),
					),
				)
			);
		}

		/**
		 * Filers plugin options to add premium-specific data
		 *
		 * @param array $options Array of options.
		 *
		 * @return array Filtered array of options.
		 */
		public function premium_list_options( $options ) {
			if ( $this->is_single_item() ) {
				$options = array(
					'affiliates-list' => array(
						'affiliate_details' => array(
							'type'   => 'custom_tab',
							'action' => 'yith_wcaf_affiliate_details_panel',
						),
					),
				);
			} elseif ( isset( $options['affiliates-list']['affiliates_table'] ) ) {
				$options['affiliates-list']['affiliates_table']['list_table_class']     = 'YITH_WCAF_Affiliates_Admin_Table_Premium';
				$options['affiliates-list']['affiliates_table']['list_table_class_dir'] = YITH_WCAF_INC . 'admin/admin-tables/class-yith-wcaf-affiliates-table-premium.php';
			}

			return $options;
		}

		/* === ADMIN ACTIONS === */

		/**
		 * Handle "Process dangling commissions" action from panel actions
		 *
		 * @return array Array of parameters to be added to return url.
		 * @since 1.2.4
		 */
		public function process_orphan_commissions_action() {
			// nonce verification is performed by \YITH_WCAF_Admin_Actions::process_action.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$affiliate_id = isset( $_REQUEST['affiliate_id'] ) ? intval( $_REQUEST['affiliate_id'] ) : 0;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ( ! $affiliate_id ) {
				return array(
					'processed_commissions' => 0,
				);
			}

			$affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate_by_id( $affiliate_id );

			if ( ! $affiliate ) {
				return array(
					'processed_commissions' => 0,
				);
			}

			try {
				$data_store = WC_Data_Store::load( 'commission' );

				$data_store->process_orphan_commissions( $affiliate_id, $affiliate->get_token() );
			} catch ( Exception $e ) {
				return array(
					'processed_commissions' => 0,
				);
			}

			return array(
				'processed_commissions' => 1,
			);
		}

		/**
		 * Pay single commission using appropriate gateway
		 *
		 * @return array Array of parameters to be added to return url.
		 */
		public function pay_commissions_action() {
			// nonce verification is performed by \YITH_WCAF_Admin_Actions::process_action.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$affiliate_id = isset( $_GET['affiliate_id'] ) ? (int) $_GET['affiliate_id'] : 0;
			$gateway      = isset( $_GET['gateway'] ) ? sanitize_text_field( wp_unslash( $_GET['gateway'] ) ) : '';
			$gateway      = $gateway && YITH_WCAF_Gateways::is_available_gateway( $gateway ) ? $gateway : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$res = YITH_WCAF_Payments()->pay_all_affiliate_commissions( $affiliate_id, ! empty( $gateway ), $gateway );

			return array(
				'processed_commissions' => $res['status'],
				'redirect_to'           => YITH_WCAF_Admin()->get_tab_url( 'commissions', 'commissions-payments' ),
			);
		}

		/**
		 * Export affiliates in a CSV format
		 *
		 * @return void
		 */
		public function export_csv_action() {
			$table = new YITH_WCAF_Affiliates_Admin_Table_Premium();
			$table->set_items_per_page( - 1 );
			$table->prepare_items();

			$affiliates = $table->items;

			// mark table object for garbage collection.
			unset( $table );

			/**
			 * APPLY_FILTERS: yith_wcaf_affiliates_csv_heading
			 *
			 * Filters the array with the headings to export the affiliates into CSV.
			 *
			 * @param array                           $csv_heading Array with the headings for the CSV export.
			 * @param YITH_WCAF_Affiliates_Collection $affiliates  Collection of the affiliates to export.
			 */
			$headings = apply_filters(
				'yith_wcaf_affiliates_csv_heading',
				array(
					'ID',
					'token',
					'user_id',
					'rate',
					'earnings',
					'refunds',
					'paid',
					'click',
					'conversion',
					'enabled',
					'banned',
					'payment_email',
					'total',
					'balance',
					'conversion_rate',
					'user_login',
					'user_email',
					'user_display_name',
					'user_nicename',
				),
				$affiliates
			);

			$sitename  = sanitize_key( get_bloginfo( 'name' ) );
			$sitename .= ( ! empty( $sitename ) ) ? '-' : '';
			$filename  = $sitename . 'affiliates-' . gmdate( 'Y-m-d' ) . '.csv';

			header( 'Content-Description: File Transfer' );
			header( 'Content-Disposition: attachment; filename=' . $filename );
			header( 'Content-Type: text/xml; charset=' . get_option( 'blog_charset' ), true );

			$df = fopen( 'php://output', 'w' );

			fputcsv( $df, $headings );

			foreach ( $affiliates as $affiliate ) {
				/**
				 * APPLY_FILTERS: yith_wcaf_affiliates_csv_row
				 *
				 * Filters the array with the affiliate info to export into CSV.
				 *
				 * @param array $affiliate_info Affiliate info to export.
				 * @param array $headings       Array with the headings for the CSV export.
				 */
				fputcsv( $df, apply_filters( 'yith_wcaf_affiliates_csv_row', $affiliate->to_array(), $headings ) );
			}

			fclose( $df ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			die();
		}

		/* === PANEL HANDLING METHODS === */

		/**
		 * Enqueue required assets for current panel.
		 *
		 * @return void.
		 */
		public function enqueue_assets() {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			wp_enqueue_script( 'wc-users', WC()->plugin_url() . '/assets/js/admin/users' . $suffix . '.js', array( 'jquery', 'wc-enhanced-select', 'selectWoo' ), WC()->version, true );
			wp_localize_script(
				'wc-users',
				'wc_users_params',
				array(
					'countries' => wp_json_encode( array_merge( WC()->countries->get_allowed_country_states(), WC()->countries->get_shipping_country_states() ) ),
				)
			);

			parent::enqueue_assets();
		}

		/**
		 * Returns an array of localized arguments for current panel
		 *
		 * @return array Array of localized variables specific to current panel.
		 */
		public function get_localize() {
			$localize = parent::get_localize();

			$localize['ban_global_message_enabled']    = 'yes' === get_option( 'yith_wcaf_enable_global_ban_message', '' );
			$localize['reject_global_message_enabled'] = 'yes' === get_option( 'yith_wcaf_enable_global_reject_message', '' );
			$localize['labels']['link_copied_message'] = _x( 'URL copied', '[GLOBAL] After copy message', 'yith-woocommerce-affiliates' );

			return $localize;
		}

		/**
		 * Filter actions available for each Affiliate object
		 *
		 * @param array               $actions      Array of available actions.
		 * @param int                 $affiliate_id Id of the current affiliate.
		 * @param YITH_WCAF_Affiliate $affiliate    Affiliate object.
		 *
		 * @return array Array of filtered actions
		 */
		public function filter_object_actions( $actions, $affiliate_id, $affiliate ) {
			$actions = yith_wcaf_append_items(
				$actions,
				'view_profile',
				array(
					'edit' => array(
						'label' => _x( 'View', '[ADMIN] Single affiliate actions', 'yith-woocommerce-affiliates' ),
						'url'   => YITH_WCAF_Admin()->get_tab_url( 'affiliates', '', array( 'affiliate_id' => $affiliate_id ) ),
					),
				),
				'before'
			);
			$actions = yith_wcaf_append_items(
				$actions,
				'view_profile',
				array(
					'view_commissions' => array(
						'label' => _x( 'View commissions', '[ADMIN] Single affiliate actions', 'yith-woocommerce-affiliates' ),
						'url'   => YITH_WCAF_Admin()->get_tab_url( 'commissions', '', array( '_affiliate_id' => $affiliate_id ) ),
					),
				),
				'after'
			);
			$actions = array_merge(
				$actions,
				array(
					'orphan' => array(
						'label' => _x( 'Process orphan commissions', '[ADMIN] Single affiliate actions', 'yith-woocommerce-affiliates' ),
						'url'   => YITH_WCAF_Admin_Actions::get_action_url(
							'process_orphan_commissions',
							array(
								'affiliate_id' => $affiliate_id,
							)
						),
					),
				)
			);

			if ( $affiliate->has_unpaid_commissions() && class_exists( 'YITH_WCAF_Gateways' ) ) {
				$available_gateways = YITH_WCAF_Gateways::get_available_gateways();

				$actions['pay'] = array(
					'label' => _x( 'Pay commissions', '[ADMIN] Single affiliate actions', 'yith-woocommerce-affiliates' ),
					'url'   => YITH_WCAF_Admin_Actions::get_action_url(
						'pay_commissions',
						array(
							'affiliate_id' => $affiliate_id,
						)
					),
				);

				if ( ! empty( $available_gateways ) ) {
					foreach ( $available_gateways as $gateway_id => $gateway ) {
						$actions[ "pay_via_{$gateway_id}" ] = array(
							// translators: 1. Payment gateway label.
							'label' => sprintf( _x( 'Pay commissions via %s', '[ADMIN] Single affiliate actions', 'yith-woocommerce-affiliates' ), $gateway->get_name() ),
							'class' => 'pay',
							'url'   => YITH_WCAF_Admin_Actions::get_action_url(
								'pay_commissions',
								array(
									'affiliate_id' => $affiliate_id,
									'gateway'      => $gateway_id,
								)
							),
						);
					}
				}
			}

			return $actions;
		}

		/**
		 * Output affiliate panel
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function output_affiliate_details() {
			$affiliate_id = $this->get_single_item_id();
			$affiliate    = yith_wcaf_get_affiliate( $affiliate_id );

			if ( ! $affiliate ) {
				// translators: 1. Affiliate id.
				wp_die( esc_html( sprintf( _x( 'Affiliate #%d doesn\'t exist', '[ADMIN] Affiliate details page', 'yith-woocommerce-affiliates' ), $affiliate_id ) ) );
			}

			// save data, if user is submitting form.
			if ( ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$sets_to_save = array( 'affiliate_details', 'affiliate_additional_info' );

				foreach ( YITH_WCAF_Gateways::get_available_gateways() as $gateway_id => $gateway ) {
					$sets_to_save[] = "affiliate_{$gateway_id}_info";
				}

				YITH_WCAF_Admin_Profile_Premium::save_profile_fields( $affiliate->get_user_id(), $sets_to_save );
			}

			// retrieve user.
			$user     = $affiliate->get_user();
			$username = $affiliate->get_formatted_name();

			// commissions tables.
			$commissions_table = new YITH_WCAF_Commissions_Admin_Table_Premium(
				array(
					'empty_message' => _x( 'No commission registered for this affiliate yet', '[ADMIN] Affiliate detail panel, empty commissions table', 'yith-woocommerce-affiliates' ),
				)
			);

			$commissions_table->set_query_var( 'affiliate_id', $affiliate->get_id() );
			$commissions_table->set_items_per_page( 5 );
			$commissions_table->set_visible_columns( array( 'id', 'status', 'date', 'product', 'rate', 'amount' ) );
			$commissions_table->hide_tablenav();
			$commissions_table->prepare_items();

			// payments table.
			$payments_table = new YITH_WCAF_Payments_Admin_Table_Premium(
				array(
					'empty_message' => _x( 'No payment registered for this affiliate yet', '[ADMIN] Affiliate detail panel, empty payments table', 'yith-woocommerce-affiliates' ),
				)
			);

			$payments_table->set_query_var( 'affiliate_id', $affiliate->get_id() );
			$payments_table->set_items_per_page( 5 );
			$payments_table->set_visible_columns( array( 'id', 'status', 'amount', 'created_at', 'completed_at' ) );
			$payments_table->hide_tablenav();
			$payments_table->prepare_items();

			// retrieve available action.
			$available_affiliate_actions = wp_list_pluck( $affiliate->get_admin_actions(), 'label' );

			if ( isset( $available_affiliate_actions['view'] ) ) {
				unset( $available_affiliate_actions['view'] );
			}

			// affiliate associated users.
			$associated_users = $affiliate->get_associated_users();

			// require rate panel template.
			include YITH_WCAF_DIR . 'views/affiliates/details-panel.php';
		}
	}
}
