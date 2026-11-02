<?php
/**
 * Payments Table Premium class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Payments_Admin_Table_Premium' ) ) {
	/**
	 * WooCommerce Payments Table Premium
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Payments_Admin_Table_Premium extends YITH_WCAF_Payments_Admin_Table {

		/**
		 * Print a column with payment ID
		 *
		 * @param YITH_WCAF_Payment $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_id( $item ) {
			return sprintf( '<a href="%s"><strong>#%d</strong></a>', esc_url( $item->get_admin_edit_url() ), $item->get_id() );
		}

		/**
		 * Print column with affiliate user details
		 *
		 * @param YITH_WCAF_Commission $item Current item row.
		 * @param array                $args Not in use.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_affiliate( $item, $args = array() ) {
			return parent::column_affiliate(
				$item,
				array(
					'show_links'         => true,
					'show_payment_email' => true,
				)
			);
		}

		/**
		 * Print a column with the date payment was completed (if any), and eventually transaction key
		 *
		 * @param YITH_WCAF_Payment $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_completed_at( $item ) {
			$completed_at    = $item->get_completed_at( 'edit' );
			$transaction_key = $item->get_transaction_key();

			if ( $completed_at ) {
				$transaction_key = $transaction_key ? $transaction_key : _x( 'N/A', '[ADMIN] Empty admin table column', 'yith-woocommerce-affiliates' );

				$column = sprintf( '%s<small class="meta">%s</small>', $completed_at->date_i18n( wc_date_format() ), $transaction_key );
			} else {
				$column = _x( 'N/A', '[ADMIN] Empty admin table column', 'yith-woocommerce-affiliates' );
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
			$columns = yith_wcaf_append_items(
				parent::get_columns(),
				'created_at',
				array(
					'completed_at' => _x( 'Completed on', '[ADMIN] Payments table heading', 'yith-woocommerce-affiliates' ),
				)
			);

			/**
			 * APPLY_FILTERS: yith_wcaf_payments_table_get_columns
			 *
			 * Filters the columns for the payments table in the backend.
			 *
			 * @param array $columns Table columns.
			 */
			return apply_filters( 'yith_wcaf_payments_table_get_columns', $columns );
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
			parent::extra_tablenav( $which );

			// print export CSV button.
			$this->print_export_csv_button();
		}
	}
}
