<?php
/**
 * Commission table template part
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 1.0.0
 */

/**
 * Templates variables:
 *
 * @var $commissions   YITH_WCAF_Commissions_Collection
 * @var $affiliate     YITH_WCAF_Affiliate
 * @var $sent_to_admin bool
 * @var $order         WC_Order (no longer in use)
 * @var $token         string (no longer in use)
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<?php
if ( $commissions->is_empty() ) {
	return;
}

$text_align  = is_rtl() ? 'right' : 'left';
$exclude_tax = YITH_WCAF_Orders()->exclude_tax();
?>

<?php if ( $affiliate && $sent_to_admin ) : ?>
	<h2>
		<?php echo esc_html_x( 'Affiliate', '[EMAILS] Commissions table', 'yith-woocommerce-affiliates' ); ?>
	</h2>
	<strong><?php echo esc_html( $affiliate->get_formatted_name() ); ?></strong>
	<p>
		<?php echo wp_kses_post( $affiliate->get_formatted_invoice_profile() ); ?>
	</p>
<?php endif; ?>

<h2>
	<?php echo esc_html_x( 'Commissions', '[EMAILS] Commissions table', 'yith-woocommerce-affiliates' ); ?>
</h2>

<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; margin-bottom: 40px;" border="1">
	<thead>
	<tr>
		<th class="td" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;">
			<?php echo esc_html_x( 'ID', '[EMAILS] Commissions table', 'yith-woocommerce-affiliates' ); ?>
		</th>
		<th class="td" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;">
			<?php echo esc_html_x( 'Product', '[EMAILS] Commissions table', 'yith-woocommerce-affiliates' ); ?>
		</th>
		<th class="td" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;">
			<?php echo esc_html_x( 'Total', '[EMAILS] Commissions table', 'yith-woocommerce-affiliates' ); ?>
		</th>
		<th class="td" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;">
			<?php echo esc_html_x( 'Rate', '[EMAILS] Commissions table', 'yith-woocommerce-affiliates' ); ?>
		</th>
		<th class="td" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;">
			<?php echo esc_html_x( 'Amount', '[EMAILS] Commissions table', 'yith-woocommerce-affiliates' ); ?>
		</th>
		<th class="td" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;">
			<?php echo esc_html_x( 'Status', '[EMAILS] Commissions table', 'yith-woocommerce-affiliates' ); ?>
		</th>
	</tr>
	</thead>

	<tbody>
	<?php foreach ( $commissions as $commission ) : ?>

		<?php
		/**
		 * APPLY_FILTERS: yith_wcaf_commission_visible
		 *
		 * Filters whether the commission will be visible in the commissions table.
		 *
		 * @param bool  $is_commission_visible Whether the commission is visible or not.
		 * @param array $commission            Commission.
		 */
		if ( ! apply_filters( 'yith_wcaf_commission_visible', true, $commission ) ) {
			continue;
		}
		?>

		<?php
		/**
		 * APPLY_FILTERS: yith_wcaf_commission_class
		 *
		 * Filters the CSS class for the commission row in the commissions table.
		 *
		 * @param string   $css_class  CSS class.
		 * @param array    $commission Commission.
		 * @param WC_Order $order      Commission order.
		 */
		?>
		<tr class="<?php echo esc_attr( apply_filters( 'yith_wcaf_commission_class', 'commission', $commission, $commission->get_order() ) ); ?>">
			<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<strong>
					<?php
					if ( $sent_to_admin && function_exists( 'YITH_WCAF_Admin' ) ) {
						$commission_url = YITH_WCAF_Admin()->get_tab_url( 'commissions', 'commissions-list', array( 'commission_id' => $commission->get_id() ) );

						$column = sprintf( '<a href="%s">#%s</a>', $commission_url, $commission->get_id() );
					} else {
						$column = sprintf( '#%s', $commission->get_id() );
					}

					echo wp_kses_post( $column );
					?>
				</strong>
			</td>

			<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<?php
				$product    = $commission->get_product();
				$product_id = $commission->get_product_id();

				if ( $sent_to_admin && function_exists( 'YITH_WCAF_Admin' ) ) {
					$product_url = YITH_WCAF_Admin()->get_tab_url( 'commissions', 'commissions-list', array( '_product_id' => $product_id ) );
				} else {
					if ( is_object( $product ) ) {
						$product_url = $product->get_permalink();
					}
				}

				$column = sprintf( '<a href="%s">%s</a>', $product_url, $commission->get_product_name() );

				if ( $product && $product->is_type( 'variation' ) ) {
					$column .= sprintf( '<div class="wc-order-item-name"><strong>%s</strong> %s</div>', _x( 'Variation ID:', '[EMAILS] Commissions table', 'yith-woocommerce-affiliates' ), $product->get_id() );
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
				echo wp_kses_post( apply_filters( 'yith_wcaf_product_column', $column, $product_id, 'commissions' ) );
				?>
			</td>

			<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<?php
				$line_item = $commission->get_order_item();

				echo wp_kses_post(
					$commission->get_formatted_line_total(
						'view',
						array(
							/**
							 * APPLY_FILTERS: yith_wcaf_email_currency
							 *
							 * Filters the commission currency in the commissions table.
							 *
							 * @param string $currency   Commission currency.
							 * @param array  $commission Commission.
							 */
							'currency' => apply_filters( 'yith_wcaf_email_currency', $commission->get_currency(), $commission ),
						)
					)
				);
				?>
			</td>

			<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<?php echo wp_kses_post( $commission->get_formatted_rate() ); ?>
			</td>

			<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<strong>
					<?php echo wp_kses_post( $commission->get_formatted_amount() ); ?>
				</strong>
			</td>

			<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<strong>
					<?php echo wp_kses_post( $commission->get_formatted_status() ); ?>
				</strong>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
