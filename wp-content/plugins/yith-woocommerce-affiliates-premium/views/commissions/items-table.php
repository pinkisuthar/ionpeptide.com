<?php
/**
 * Commission details Admin Panel
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Views
 * @version 1.0.0
 */

/**
 * Template variables:
 *
 * @var $this              YITH_WCAF_Commissions_Admin_Panel
 * @var $commission        YITH_WCAF_Commission
 * @var $affiliate         YITH_WCAF_Affiliate
 * @var $order             WC_Order
 * @var $item              WC_Order_Item_Product
 * @var $product           WC_Product
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<table>
	<thead>
	<tr>
		<th class="item sortable" colspan="2"><?php echo esc_html_x( 'Item', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></th>
		<th class="quantity sortable"><?php echo esc_html_x( 'Qty', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></th>
		<th class="item_cost sortable"><?php echo esc_html_x( 'Cost', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></th>
	</tr>
	</thead>

	<tbody id="order_line_items">
	<tr data-order_item_id="<?php echo esc_attr( $commission->get_order_item_id() ); ?>">
		<td class="thumb">
			<?php
			echo wp_kses_post( $product ? $product->get_image( 'shop_thumbnail', array( 'title' => '' ) ) : wc_placeholder_img( 'shop_thumbnail' ) );
			?>
		</td>

		<td class="name">
			<?php echo ( $product && $product->get_sku() ) ? esc_html( $product->get_sku() ) . ' &ndash; ' : ''; ?>

			<?php if ( $product ) : ?>
				<a target="_blank" href="<?php echo esc_url( $commission->get_product_edit_url() ); ?>">
					<?php echo esc_html( $product->get_name() ); ?>
				</a>
			<?php else : ?>
				<?php echo esc_html( $commission->get_product_name() ); ?>
			<?php endif; ?>
		</td>

		<td class="quantity" width="1%">
			<?php
			if ( $item ) {
				echo esc_html( $item->get_quantity() );

				$refunded_qty = $order->get_qty_refunded_for_item( $commission->get_line_item_id() );

				if ( $refunded_qty ) {
					echo '<small class="refunded">' . esc_html( $refunded_qty ) . '</small>';
				}
			} else {
				echo '1';
			}
			?>
		</td>

		<td class="item_cost" width="1%">
			<?php
			if ( $item ) {
				if ( $item->get_subtotal() !== $item->get_total() ) {
					echo '<del>' . wp_kses_post( wc_price( $item->get_subtotal(), array( 'currency' => $order->get_currency() ) ) ) . '</del> ';
				}

				echo wp_kses_post( wc_price( $item->get_total(), array( 'currency' => $order->get_currency() ) ) );
			} else {
				echo wp_kses_post( wc_price( $commission->get_line_total(), array( 'currency' => $commission->get_currency() ) ) );
			}

			$refunded = $order ? -1 * $order->get_total_refunded_for_item( $commission->get_line_item_id() ) : false;

			if ( $refunded ) {
				echo '<small class="refunded">' . wp_kses_post( wc_price( $refunded, array( 'currency' => $commission->get_currency() ) ) ) . '</small>';
			}
			?>
		</td>
	</tr>
	</tbody>

	<?php if ( $order && $item && ! empty( $order->get_total_refunded_for_item( $item->get_id() ) ) ) : ?>
		<tbody id="order_refunds">
		<tr>
			<td class="thumb"></td>

			<td class="name">
				<?php
				echo esc_html( _x( 'Refund', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ) );
				?>
			</td>

			<td class="quantity" width="1%">&nbsp;</td>

			<td class="line_cost" width="1%">
				<div class="view">
					<?php echo wp_kses_post( wc_price( $order->get_total_refunded_for_item( $commission->get_line_item_id() ) ) ); ?>
				</div>
			</td>
		</tr>
		</tbody>
	<?php endif; ?>

	<tfoot class="totals">
	<tr>
		<td class="label" colspan="2"><?php echo esc_html_x( 'Rate:', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></td>
		<td class="total" colspan="2"><?php echo esc_html( $commission->get_formatted_rate() ); ?></td>
	</tr>

	<?php if ( ! empty( $commission->get_refunds() ) ) : ?>
		<tr>
			<td class="label refunded-total" colspan="2"><?php echo esc_html_x( 'Refunded:', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></td>
			<td class="total refunded-total" colspan="2"><?php echo wp_kses_post( wc_price( $commission->get_refunds() ) ); ?></td>
		</tr>
	<?php endif; ?>

	<tr>
		<td class="label" colspan="2"><?php echo esc_html_x( 'Commission:', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></td>
		<td class="total" colspan="2">
			<?php echo wp_kses_post( $commission->get_formatted_amount() ); ?>
		</td>
	</tr>

	<tr>
		<td class="label" colspan="2"><?php echo esc_html_x( 'Store earnings:', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></td>
		<td class="total" colspan="2"><?php echo wp_kses_post( wc_price( $commission->get_line_total() - ( $order ? $order->get_total_refunded_for_item( $commission->get_line_item_id() ) : 0 ) - $commission->get_amount() ) ); ?></td>
	</tr>
	</tfoot>
</table>
