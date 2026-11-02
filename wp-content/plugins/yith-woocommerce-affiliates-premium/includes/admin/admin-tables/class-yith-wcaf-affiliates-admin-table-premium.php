<?php
/**
 * Affiliate Table Premium class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Affiliates_Admin_Table_Premium' ) ) {
	/**
	 * WooCommerce Affiliates Table Premium
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Affiliates_Admin_Table_Premium extends YITH_WCAF_Affiliates_Admin_Table {
		/**
		 * Print column with affiliate ID
		 *
		 * @param YITH_WCAF_Affiliate $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_id( $item ) {
			$column = sprintf( '<a href="%s"><strong>#%s</strong></a>', esc_url( $item->get_admin_edit_url() ), $item->get_id() );

			return $column;
		}

		/**
		 * Print column with affiliate user details
		 *
		 * @param YITH_WCAF_Affiliate $item Current item row.
		 * @param array               $args Not in use.
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
		 * Print column with affiliate refunds (total of refunded commissions)
		 *
		 * @param YITH_WCAF_Affiliate $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_refunds( $item ) {
			return wc_price( $item->get_refunds() );
		}

		/**
		 * Print column with affiliate balance (earnings - refund - paid)
		 *
		 * @param YITH_WCAF_Affiliate $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_balance( $item ) {
			$column  = '';
			$column .= wc_price( $item->get_balance() );

			if ( $item->has_unpaid_commissions() ) {
				$action = $item->get_admin_action( 'pay' );

				if ( $action ) {
					$column .= sprintf(
						'<a class="button button-primary button-pay-now" href="%1$s">%2$s</a>',
						$action['url'],
						_x( 'Pay now', '[ADMIN] Pay now button in balance column of Affiliates table', 'yith-woocommerce-affiliates' )
					);
				}
			}

			return $column;
		}

		/**
		 * Print column with affiliate clicks
		 *
		 * @param YITH_WCAF_Affiliate $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_click( $item ) {
			$args = array(
				'_user_id' => $item->get_user_id(),
			);

			return sprintf(
				'<a href="%1$s">%2$d</a>',
				esc_url( YITH_WCAF_Admin()->get_tab_url( 'affiliates', 'affiliates-clicks', $args ) ),
				$item->get_clicks_count()
			);
		}

		/**
		 * Print column with affiliate conversions
		 *
		 * @param YITH_WCAF_Affiliate $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_conversion( $item ) {
			$args = array(
				'_user_id' => $item->get_user_id(),
				'status'   => 'converted',
			);

			return sprintf( '<a href="%1$s">%2$d</a>', esc_url( YITH_WCAF_Admin()->get_tab_url( 'affiliates', 'affiliates-clicks', $args ) ), $item->get_conversions() );
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
				'earnings',
				array(
					'refunds' => _x( 'Refunds', '[ADMIN] Affiliates table headings', 'yith-woocommerce-affiliates' ),
				)
			);

			/**
			 * APPLY_FILTERS: yith_wcaf_affiliate_table_columns
			 *
			 * Filters the columns for the Affiliates table in the backend.
			 *
			 * @param array $columns Table columns.
			 */
			return apply_filters( 'yith_wcaf_affiliate_table_columns', $columns );
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

			if ( 'top' !== $which ) {
				return;
			}

			// print export CSV button.
			$this->print_export_csv_button();
		}
	}
}
