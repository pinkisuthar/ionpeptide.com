<?php
/**
 * Commissions Table Premium class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Commissions_Admin_Table_Premium' ) ) {
	/**
	 * WooCommerce Commissions Table Premium
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Commissions_Admin_Table_Premium extends YITH_WCAF_Commissions_Admin_Table {

		/**
		 * Print column with commission ID
		 *
		 * @param YITH_WCAF_Commission $item Current item row.
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
		 * @param array                $args Not is use.
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
		 * Print column with commission product details
		 *
		 * @param YITH_WCAF_Commission $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_product( $item ) {
			$product = $item->get_product();

			if ( ! $product ) {
				return $item->get_product_name();
			}

			$column = sprintf( '%s<div class="product-details"><a href="%s">%s</a></div>', $product->get_image( array( 50, 50 ) ), add_query_arg( '_product_id', $product->get_id() ), $product->get_title() );

			if ( $product->is_type( 'variation' ) ) {
				$column  = substr( $column, 0, -6 );
				$column .= sprintf( '<div class="wc-order-item-name"><strong>%s</strong> %s</div>', _x( 'Variation ID:', '[ADMIN] Product column in commissions table', 'yith-woocommerce-affiliates' ), $product->get_id() );
				$column .= '</div>';
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_product_column
			 *
			 * Filters the content on the product column in the commissions table.
			 *
			 * @param string $column     Column content.
			 * @param int    $product_id Product ID.
			 * @param string $table_type Table type.
			 */
			return apply_filters( 'yith_wcaf_product_column', $column, $product->get_id(), 'commissions' );
		}

		/**
		 * Print column with commission category details
		 *
		 * @param YITH_WCAF_Commission $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_category( $item ) {
			$product_id = $item->get_product_id();
			$categories = wp_get_post_terms( $product_id, 'product_cat' );

			if ( empty( $categories ) ) {
				$column = _x( 'N/A', '[ADMIN] Empty admin table column', 'yith-woocommerce-affiliates' );
			} else {
				$column_items = array();

				foreach ( $categories as $category ) {
					$column_items[] = sprintf( '<a href="%s">%s</a>', get_term_link( $category ), $category->name );
				}

				$column = implode( ' | ', $column_items );
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_category_column
			 *
			 * Filters the content of the category column in the commissions table.
			 *
			 * @param string $column     Column output.
			 * @param int    $product_id Product id.
			 * @param string $items      Items to display in the table.
			 */
			return apply_filters( 'yith_wcaf_category_column', $column, $product_id, 'commissions' );
		}

		/**
		 * Print column with line item discount
		 *
		 * @param YITH_WCAF_Commission $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_line_item_discounts( $item ) {
			$exclude_tax = YITH_WCAF_Orders()->exclude_tax();
			$order       = $item->get_order();
			$line_item   = $item->get_order_item();

			if ( ! $order || ! $line_item ) {
				return '';
			}

			$total    = $order->get_item_total( $line_item, 'yes' !== $exclude_tax );
			$subtotal = $order->get_item_subtotal( $line_item, 'yes' !== $exclude_tax );
			$discount = ( $total - $subtotal ) * $line_item->get_quantity();

			return wc_price(
				$discount,
				array(
					'currency' => $order->get_currency(),
				)
			);
		}

		/**
		 * Print column with line item refunds
		 *
		 * @param YITH_WCAF_Commission $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_line_item_refunds( $item ) {
			$order = $item->get_order();

			if ( ! $order ) {
				return '';
			}

			return wc_price(
				$item->get_refunds(),
				array(
					$order->get_currency(),
				)
			);
		}

		/**
		 * Print column with commission active payments (should be one single element)
		 *
		 * @param YITH_WCAF_Commission $item Current item row.
		 *
		 * @return string Column content
		 * @since 1.0.0
		 */
		public function column_payments( $item ) {
			$active_payments = $item->get_active_payments();

			if ( ! $active_payments ) {
				$column = _x( 'N/A', '[ADMIN] Empty admin table column', 'yith-woocommerce-affiliates' );
			} else {
				$items = array();

				foreach ( $active_payments as $active_payment ) {
					$payment_url = YITH_WCAF_Admin()->get_tab_url(
						'commissions',
						'commissions-payments',
						array(
							'payment_id' => $active_payment->get_id(),
						)
					);

					$items[] = sprintf( '<a href="%s">#%d</a>', $payment_url, $active_payment->get_id() );
				}

				$column = implode( ' | ', $items );
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
				'line_item_total',
				array(
					'line_item_discounts' => _x( 'Discounts', '[ADMIN] Commissions table heading', 'yith-woocommerce-affiliates' ),
					'line_item_refunds'   => _x( 'Refunds', '[ADMIN] Commissions table heading', 'yith-woocommerce-affiliates' ),
				)
			);

			/**
			 * APPLY_FILTERS: yith_wcaf_commission_table_columns
			 *
			 * Filters the columns for the commissions table in the backend.
			 *
			 * @param array $columns Table columns.
			 */
			return apply_filters( 'yith_wcaf_commission_table_columns', $columns );
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
