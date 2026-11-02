<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class to manage the documents table
 *
 * @package YITH\PDF_Invoice\Classes
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'YITH_WCPI_Documents_List_Table' ) ) {
	/**
	 * YITH_WCPI_Documents_List_Table Class
	 *
	 * @class YITH_WCPI_Documents_List_Table
	 * @author  YITH <plugins@yithemes.com>
	 * @package YITH\PDF_Invoice\Classes
	 */
	abstract class YITH_WCPI_Documents_List_Table extends WP_List_Table {

		/**
		 * Construct
		 *
		 * @param  array $args The array of arguments.
		 * @since  2.1
		 */
		public function __construct( $args = array() ) {
			parent::__construct(
				array(
					'singular' => esc_html__( 'Invoice List', 'yith-woocommerce-pdf-invoice' ),
					'plural'   => esc_html__( 'Invoices Tables', 'yith-woocommerce-pdf-invoice' ),
					'ajax'     => false,
				)
			);
		}

		/**
		 * Return the columns for the table
		 *
		 * @return array
		 * @since  2.1.0
		 */
		public function get_columns() {
			$columns = array(
				'cb'              => '<input type="checkbox" class="yith-ywpi-invoice-bulk-action-checkbox">',
				'date'            => _x( 'Date', '[admin] Invoices table column header', 'yith-woocommerce-pdf-invoice' ),
				'document_number' => _x( 'Invoice No.', '[admin] Invoices table column header', 'yith-woocommerce-pdf-invoice' ),
				'order'           => _x( 'Order', '[admin] Invoices table column header', 'yith-woocommerce-pdf-invoice' ),
				'customer'        => _x( 'Customer', '[admin] Invoices table column header', 'yith-woocommerce-pdf-invoice' ),
				'subtotal'        => _x( 'Subtotal', '[admin] Invoices table column header', 'yith-woocommerce-pdf-invoice' ),
				'shipping'        => _x( 'Shipping', '[admin] Invoices table column header', 'yith-woocommerce-pdf-invoice' ),
				'tax'             => _x( 'Tax', '[admin] Invoices table column header', 'yith-woocommerce-pdf-invoice' ),
				'total'           => _x( 'Total', '[admin] Invoices table column header', 'yith-woocommerce-pdf-invoice' ),
				'actions'         => '',
			);

			return $columns;
		}

		/**
		 * The checkbox column.
		 *
		 * @param array|object $order The order object.
		 *
		 * @return string
		 */
		public function column_cb( $order ) {
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}

			if ( ! is_object( $order ) ) {
				return;
			}

			$output = '<input type="checkbox" name="yith_ywpi_checkbox_ids[]" value="' . absint( $order->get_id() ) . '">';

			return $output;
		}

		/**
		 * The order column.
		 *
		 * @param int|object $order The order ID or order object.
		 */
		public function column_order( $order ) {
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}

			if ( $order instanceof \Automattic\WooCommerce\Admin\Overrides\OrderRefund || $order instanceof WC_Order_Refund ) {
				$order = wc_get_order( $order->get_parent_id() );
			}

			if ( ! is_object( $order ) ) {
				return;
			}

			$edit_order_url = '';

			if ( yith_plugin_fw_is_wc_custom_orders_table_usage_enabled() ) {
				$edit_order_url = add_query_arg(
					array(
						'page'   => 'wc-orders',
						'action' => 'edit',
						'id'     => $order->get_id(),
					),
					admin_url( 'admin.php' )
				);
			} else {
				$edit_order_url = get_edit_post_link( $order->get_id() );
			}

			?>
			<a href="<?php echo esc_url( $edit_order_url ); ?>">#<?php echo wp_kses_post( $order->get_id() ); ?></a>
			<?php

			do_action( 'yith_ywpi_order_number_document_table', $order );
		}

		/**
		 * The customer column.
		 *
		 * @param int|object $order The order ID or order object.
		 */
		public function column_customer( $order ) {
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}

			if ( $order instanceof \Automattic\WooCommerce\Admin\Overrides\OrderRefund || $order instanceof WC_Order_Refund ) {
				$order = wc_get_order( $order->get_parent_id() );
			}

			$customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
			$billing_vat   = ywpi_get_billing_vat_number( $order );
			$billing_ssn   = ywpi_get_billing_vat_ssn( $order );

			$billing_country = $order->get_billing_country();
			$countries       = WC()->countries->get_countries();

			if ( isset( $countries[ $billing_country ] ) ) {
				$billing_country = $countries[ $billing_country ];
			}

			$billing_address  = $order->get_billing_address_1();
			$billing_postcode = $order->get_billing_postcode();
			$billing_city     = $order->get_billing_city();

			$output = $customer_name . '<br>' . $billing_address . '<br>' . $billing_postcode . ' ' . $billing_city . '<br>' . $billing_country;
			/**
			 * APPLY_FILTERS: yith_ywpi_vat_field_text
			 *
			 * Filter the VAT field name.
			 *
			 * @param string the VAT field name.
			 *
			 * @return string
			 */
			$output = ( $billing_vat ) ? $output . '<br>' . apply_filters( 'yith_ywpi_vat_field_text', esc_html__( 'VAT', 'yith-woocommerce-pdf-invoice' ) ) . ': ' . $billing_vat : $output;
			/**
			 * APPLY_FILTERS: yith_ywpi_ssn_field_text
			 *
			 * Filter the SSN field name.
			 *
			 * @param string the SSN field name.
			 *
			 * @return string
			 */
			$output = ( $billing_ssn ) ? $output . '<br>' . apply_filters( 'yith_ywpi_ssn_field_text', esc_html__( 'SSN', 'yith-woocommerce-pdf-invoice' ) ) . ': ' . $billing_ssn : $output;

			echo wp_kses_post( $output );
		}

		/**
		 * The subtotal column.
		 *
		 * @param int|object $order The order ID or order object.
		 */
		public function column_subtotal( $order ) {
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}

			$output = wc_price( $order->get_subtotal() );

			echo wp_kses_post( $output );
		}

		/**
		 * The shipping column.
		 *
		 * @param int|object $order The order ID or order object.
		 */
		public function column_shipping( $order ) {
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}

			$output = wc_price( $order->get_shipping_total() );

			echo wp_kses_post( $output );
		}

		/**
		 * The tax column.
		 *
		 * @param int|object $order The order ID or order object.
		 */
		public function column_tax( $order ) {
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}

			$output = wc_price( $order->get_total_tax() );

			echo wp_kses_post( $output );
		}

		/**
		 * The total column.
		 *
		 * @param int|object $order The order ID or order object.
		 */
		public function column_total( $order ) {
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}

			$output = wc_price( $order->get_total() );

			echo wp_kses_post( $output );
		}

		/**
		 * Generates the tbody element for the list table.
		 *
		 * @since 2.1.0
		 */
		public function display_rows_or_placeholder() {
			if ( $this->has_items() ) {
				$this->display_rows();
			} else {
				echo wp_kses_post( '<tr class="no-items"><td class="colspanchange" colspan="' . $this->get_column_count() . '">' );
				$this->no_items();
				echo '</td></tr>';
			}
		}

		/**
		 * Return list of available bulk actions
		 *
		 * @return array Available bulk action
		 * @since 2.1.0
		 */
		public function get_bulk_actions() {
			$actions = array(
				'download'     => __( 'Download PDF', 'yith-woocommerce-pdf-invoice' ),
				'download_xml' => __( 'Download XML', 'yith-woocommerce-pdf-invoice' ),
				'delete'       => __( 'Delete', 'yith-woocommerce-pdf-invoice' ),
				'regenerate'   => __( 'Regenerate', 'yith-woocommerce-pdf-invoice' ),
			);

			if (  ! YITH_YWPI_Modules::is_module_active('italian-e-invoicing' ) ) {
				unset( $actions['download_xml'] );
			}

			return apply_filters( 'yith_ywpi_get_bulk_actions_documents_list', $actions );
		}

		/**
		 * Extra controls to be displayed between bulk actions and pagination.
		 *
		 * @param string $which The which parameter to make some control.
		 */
		protected function extra_tablenav( $which ) {
			if ( strval( $which ) !== 'top' ) {
				return;
			}

			// retrieve other query args.
			$from = isset( $_REQUEST['_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_from'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$to   = isset( $_REQUEST['_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_to'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			echo '<div class="alignleft invoice_date_pickers">';

			/**
			 * APPLY_FILTERS: ywpi_date_format_for_datepickers
			 *
			 * Filter the date format for the datepickers when displaying documents in the table.
			 *
			 * @param string the date format.
			 *
			 * @return string
			 */
			$date_format = apply_filters( 'ywpi_date_format_for_datepickers', 'yy-mm-dd' );

			$datepicker_from = array(
				'id'    => 'documents_date_from',
				'name'  => '_from',
				'type'  => 'datepicker',
				'data'  => array(
					'date-format' => $date_format,
				),
				'value' => ! empty( $from ) ? $from : apply_filters( 'yith_ywpi_documents_table_default_from_date', '' ),
			);
			$datepicker_to   = array(
				'id'    => 'documents_date_to',
				'name'  => '_to',
				'type'  => 'datepicker',
				'data'  => array(
					'date-format' => $date_format,
				),
				'value' => ! empty( $to ) ? $to : apply_filters( 'yith_ywpi_documents_table_default_to_date', '' ),

			);

			$need_reset = false;

			echo '<label for="documents_date_from" class="documents_date_from_label">' . esc_html__( 'From', 'yith-woocommerce-pdf-invoice' ) . '</label>';
			yith_plugin_fw_get_field( $datepicker_from, true );
			echo '<label for="documents_date_to" class="documents_date_to_label">' . esc_html__( 'To', 'yith-woocommerce-pdf-invoice' ) . '</label>';
			yith_plugin_fw_get_field( $datepicker_to, true );

			submit_button( esc_html__( 'Filter', 'yith-woocommerce-pdf-invoice' ), 'button yith-plugin-fw__button--secondary', 'filter_action', false, array( 'id' => 'post-query-submit' ) );
			echo '<input type="hidden" name="export_view_page" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['paged'] ?? 1 ) ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			submit_button( __( 'Export CSV of this view', 'yith-woocommerce-pdf-invoice' ), 'button yith-plugin-fw__button--secondary', 'export_action', false );

			if ( $from || $to ) {
				$need_reset = true;
			}

			if ( $need_reset ) {
				$query_args = array(
					'page' => isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'tab'  => isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				);

				if ( isset( $_GET['sub_tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$query_args['sub_tab'] = isset( $_GET['sub_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['sub_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}

				printf(
					'<a href="%s" id="yith-plugin-fw__wp-list__reset-filters" class="yith-plugin-fw__button--tertiary">%s</a>',
					esc_url(
						add_query_arg(
							$query_args,
							admin_url( 'admin.php' )
						)
					),
					esc_html( __( 'Reset filters', 'yith-woocommerce-pdf-invoice' ) )
				);
			}

			echo '</div>';
		}

		/**
		 * Return the current view.
		 *
		 * @return string
		 * @since  2.1.0
		 */
		public function get_current_view() {
			return empty( $_GET['status'] ) ? 'all' : sanitize_key( wp_unslash( $_GET['status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}
}
