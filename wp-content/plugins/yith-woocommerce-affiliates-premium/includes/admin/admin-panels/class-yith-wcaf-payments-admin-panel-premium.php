<?php
/**
 * Payments admin panel handling
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Payments_Admin_Panel_Premium' ) ) {
	/**
	 * Affiliates admin panel handling
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Payments_Admin_Panel_Premium extends YITH_WCAF_Payments_Admin_Panel {

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

			// object saving.
			add_action( 'admin_init', array( $this, 'save' ) );

			// premium options handling.
			add_filter( 'yith_wcaf_payments_settings', array( $this, 'premium_options' ) );
			add_filter( 'yith_wcaf_payment_admin_actions', array( $this, 'filter_object_actions' ), 10, 3 );
			add_action( 'yith_wcaf_payment_details_panel', array( $this, 'output_payment_details' ) );
		}
		/**
		 * Get the affiliates list table.
		 *
		 * @return YITH_WCAF_Payments_Admin_Table
		 */
		protected function get_payments_list_table() {
			return new YITH_WCAF_Payments_Admin_Table_Premium();
		}

		/**
		 * Adds premium panel notices to registered one.
		 *
		 * @return void.
		 */
		public function premium_notices() {
			$this->admin_notices = array_merge(
				$this->admin_notices,
				array(
					'invoice_generated' => array(
						'success' => _x( 'Invoice generated correctly', '[ADMIN] Payments action messages', 'yith-woocommerce-affiliates' ),
						'error'   => _x( 'There was an error while generating invoice', '[ADMIN] Payments action messages', 'yith-woocommerce-affiliates' ),
					),
				)
			);
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
					'regenerate_invoice' => array( $this, 'regenerate_invoice_action' ),
					'export_csv'         => array( $this, 'export_csv_action' ),
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
					'completed_at' => _x( 'Completed on', '[ADMIN] Payments screen columns', 'yith-woocommerce-affiliates' ),
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
					'commissions-payments' => array(
						'payment_details' => array(
							'type'   => 'custom_tab',
							'action' => 'yith_wcaf_payment_details_panel',
						),
					),
				);
			} elseif ( isset( $options['commissions-payments']['payments_table'] ) ) {
				$options['commissions-payments']['payments_table']['list_table_class']     = 'YITH_WCAF_Payments_Admin_Table_Premium';
				$options['commissions-payments']['payments_table']['list_table_class_dir'] = YITH_WCAF_INC . 'admin/admin-tables/class-yith-wcaf-payments-table-premium.php';
			}

			return $options;
		}

		/**
		 * Filter actions available for each Affiliate object
		 *
		 * @param array             $actions    Array of available actions.
		 * @param int               $payment_id Id of the current commission.
		 * @param YITH_WCAF_Payment $payment    Payment object.
		 *
		 * @return array Array of filtered actions
		 */
		public function filter_object_actions( $actions, $payment_id, $payment ) {
			$actions = array_merge(
				array(
					'view' => array(
						'label' => _x( 'View', '[ADMIN] Single payment actions', 'yith-woocommerce-affiliates' ),
						'url'   => YITH_WCAF_Admin()->get_tab_url( 'commissions', 'commissions-payments', array( 'payment_id' => $payment_id ) ),
					),
				),
				$actions
			);

			return $actions;
		}

		/* === ADMIN ACTIONS === */

		/**
		 * Regenerate affiliate invoice for a specific payment, with data registered for the affiliate
		 *
		 * @return array Array of parameters for redirect
		 */
		public function regenerate_invoice_action() {
			// nonce verification is performed by \YITH_WCAF_Admin_Actions::process_action.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$payment_id = isset( $_REQUEST['payment_id'] ) ? intval( $_REQUEST['payment_id'] ) : 0;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			YITH_WCAF_Invoices()->generate_invoice( $payment_id );

			return array(
				'invoice_generated' => true,
				'payment_id'        => $payment_id,
			);
		}

		/**
		 * Process export, and generate csv file to download with commissions
		 *
		 * @return void
		 * @since 1.1.1
		 */
		public function export_csv_action() {
			$table = new YITH_WCAF_Payments_Admin_Table_Premium();
			$table->set_items_per_page( -1 );
			$table->prepare_items();

			$payments = $table->items;

			// mark table object for garbage collection.
			unset( $table );

			/**
			 * APPLY_FILTERS: yith_wcaf_payments_csv_heading
			 *
			 * Filters the array with the headings to export the payments into CSV.
			 *
			 * @param array                         $csv_heading Array with the headings for the CSV export.
			 * @param YITH_WCAF_Payments_Collection $payments    Collection of the payments to export.
			 */
			$headings = apply_filters(
				'yith_wcaf_payments_csv_heading',
				array(
					'ID',
					'affiliate_id',
					'email',
					'gateway_id',
					'status',
					'amount',
					'created_at',
					'completed_at',
					'transaction_key',
					'gateway_details',
				),
				$payments
			);

			$sitename  = sanitize_key( get_bloginfo( 'name' ) );
			$sitename .= ( ! empty( $sitename ) ) ? '-' : '';
			$filename  = $sitename . 'payments-' . gmdate( 'Y-m-d' ) . '.csv';

			header( 'Content-Description: File Transfer' );
			header( 'Content-Disposition: attachment; filename=' . $filename );
			header( 'Content-Type: text/xml; charset=' . get_option( 'blog_charset' ), true );

			$df = fopen( 'php://output', 'w' );

			fputcsv( $df, $headings );

			foreach ( $payments as $payment ) {
				/**
				 * APPLY_FILTERS: yith_wcaf_payments_csv_row
				 *
				 * Filters the array with the payment info to export into CSV.
				 *
				 * @param array $payment_info Payment info to export.
				 * @param array $headings     Array with the headings for the CSV export.
				 */
				fputcsv( $df, apply_filters( 'yith_wcaf_payments_csv_row', $payment->to_array(), $headings ) );
			}

			fclose( $df ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			die();
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
		 * Save single item using data from details panel
		 *
		 * @return void
		 */
		public function save() {
			// only save when on single item page.
			if ( ! $this->is_single_item() ) {
				return;
			}

			// check nonce.
			if ( ! isset( $_POST['edit_payment'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['edit_payment'] ) ), 'edit_payment' ) ) {
				return;
			}

			// retrieve payment.
			$payment_id = $this->get_single_item_id();
			$payment    = YITH_WCAF_Payment_Factory::get_payment( $payment_id );

			if ( ! $payment ) {
				return;
			}

			// retrieve affiliate.
			$affiliate = $payment->get_affiliate();

			// set gateway details.
			if ( isset( $_POST['gateway_preferences'] ) && ! empty( $_POST['gateway_preferences']['gateway'] ) ) {
				$gateway_id = sanitize_text_field( wp_unslash( $_POST['gateway_preferences']['gateway'] ) );
				$gateway    = YITH_WCAF_Gateways::get_gateway( $gateway_id );

				// validation is performed just a couple of lines below, with YITH_WCAF_Abstract_Gateway::validate_fields() method.
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$gateway_details = isset( $_POST['gateway_preferences'][ $gateway_id ] ) ? $_POST['gateway_preferences'][ $gateway_id ] : false;

				$payment->set_gateway_id( $gateway_id );

				if ( $gateway_details ) {
					try {
						$gateway_details = $gateway->validate_fields( $gateway_details );
						$payment->set_gateway_details( $gateway_details );
						$affiliate && $affiliate->set_gateway_preferences( $gateway_id, $gateway_details );
					} catch ( Exception $e ) { // phpcs:ignore
						// do nothing.
					}
				}
			}

			// set billing details.
			if ( isset( $_POST['invoice'] ) ) {
				// validation is performed just a couple of lines below, with YITH_WCAF_Affiliates_Invoice_Profile::validate_billing_fields() method.
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$invoice_profile = $_POST['invoice'];

				try {
					$invoice_profile = YITH_WCAF_Affiliates_Invoice_Profile::validate_billing_fields( $invoice_profile );
					$affiliate && $affiliate->set_invoice_profile( $invoice_profile );

					// regenerate invoice.
					YITH_WCAF_Invoices()->generate_invoice( $payment_id, $payment->get_amount(), $invoice_profile );
				} catch ( Exception $e ) {  // phpcs:ignore
					// do nothing.
				}
			}

			// upload new invoice.
			if ( ! empty( $_FILES['invoice_file'] ) && isset( $_FILES['invoice_file']['tmp_name'] ) && isset( $_FILES['invoice_file']['name'] ) ) {
				$uploaded_file = $_FILES['invoice_file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$file_name     = strtolower( sanitize_file_name( wp_unslash( $_FILES['invoice_file']['name'] ) ) );
				$file_ext      = pathinfo( $file_name, PATHINFO_EXTENSION );

				YITH_WCAF_Invoices()->save_uploaded_invoice( $uploaded_file, $payment_id . '.' . $file_ext );
			}

			// save objects.
			$affiliate && $affiliate->save();
			$payment->save();
		}

		/**
		 * Output payment panel
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function output_payment_details() {
			// define variables to use in template.
			$payment_id = $this->get_single_item_id();
			$payment    = YITH_WCAF_Payment_Factory::get_payment( $payment_id );

			if ( ! $payment ) {
				// translators: 1. Payment id.
				wp_die( esc_html( sprintf( _x( 'Payment #%d doesn\'t exist.', '[ADMIN] Payment details page', 'yith-woocommerce-affiliates' ), $payment_id ) ) );
			}

			// retrieve affiliate.
			$affiliate = $payment->get_affiliate();

			// retrieve commissions.
			$commissions = $payment->get_commissions();

			// retrieve notes.
			$notes = $payment->get_notes();

			// retrieve payment email, if any.
			$payment_email = $payment->get_email();
			$payment_email = ! $payment_email && $affiliate ? $affiliate->get_payment_email() : $payment_email;

			// retrieve available payment actions.
			$available_actions = wp_list_pluck( $payment->get_admin_actions(), 'label' );

			if ( isset( $available_actions['view'] ) ) {
				unset( $available_actions['view'] );
			}

			// require rate panel template.
			include YITH_WCAF_DIR . 'views/payments/details-panel.php';
		}
	}
}
