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
 * @var $this        YITH_WCAF_Payments_Admin_Panel
 * @var $payment     YITH_WCAF_Payment
 * @var $commissions YITH_WCAF_Commissions_Collection
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<?php
if ( $commissions->is_empty() ) {
	return;
}
?>

<table>
	<thead>
	<tr>
		<th class="item sortable"><?php echo esc_html_x( 'Commission', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></th>
		<th class="product sortable" colspan="2"><?php echo esc_html_x( 'Product', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></th>
		<th class="date sortable"><?php echo esc_html_x( 'Date', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></th>
		<th class="item_cost sortable"><?php echo esc_html_x( 'Amount', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></th>
	</tr>
	</thead>

	<tbody id="order_line_items">
	<?php foreach ( $commissions as $commission ) : ?>
		<?php
			$commission_product = $commission->get_product();
			$commission_order   = $commission->get_order();
			$commission_item    = $commission->get_order_item();
		?>
		<tr data-order_item_id="<?php echo esc_attr( $commission->get_order_item_id() ); ?>">
			<td class="item">
				<?php
				echo wp_kses_post(
					sprintf(
						'<a href="%s">#%d</a> &#8212; %s <a href="%s">#%d</a>',
						$commission->get_admin_edit_url(),
						$commission->get_id(),
						_x( 'Order:', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ),
						$commission->get_order_edit_url(),
						$commission_order ? $commission_order->get_id() : $commission->get_order_id()
					)
				);
				?>
			</td>
			<td class="thumb">
				<?php
				echo wp_kses_post( $commission_product ? $commission_product->get_image( 'shop_thumbnail', array( 'title' => '' ) ) : wc_placeholder_img( 'shop_thumbnail' ) );
				?>
			</td>

			<td class="name">
				<?php echo ( $commission_product && $commission_product->get_sku() ) ? esc_html( $commission_product->get_sku() ) . ' &ndash; ' : ''; ?>

				<?php if ( $commission_product ) : ?>
					<a target="_blank" href="<?php echo esc_url( $commission->get_product_edit_url() ); ?>">
						<?php echo esc_html( $commission_item ? $commission_item->get_name() : $commission->get_product_name() ); ?>
					</a>
				<?php else : ?>
					<?php echo esc_html( $commission_item ? $commission_item->get_name() : $commission->get_product_name() ); ?>
				<?php endif; ?>
			</td>

			<td class="date">
				<?php echo esc_html( $commission->get_created_at( 'edit' )->date_i18n( wc_date_format() ) ); ?>
			</td>

			<td class="item_cost" width="1%">
				<?php echo wp_kses_post( $commission->get_formatted_amount() ); ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>

	<tfoot class="totals">
	<tr>
		<td class="label" colspan="4"><?php echo esc_html_x( 'Total:', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></td>
		<td class="total" colspan="1"><?php echo wp_kses_post( $payment->get_formatted_amount() ); ?></td>
	</tr>
	</tfoot>
</table>
