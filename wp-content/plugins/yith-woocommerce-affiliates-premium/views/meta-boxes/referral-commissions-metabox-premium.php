<?php
/**
 * Order Referral MetaBox
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Views
 * @version 1.0.0
 */

/**
 * Template variables:
 *
 * @var $username          string
 * @var $user_email        string
 * @var $order             WC_Order
 * @var $commissions_table YITH_WCAF_Commissions_Admin_Table
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! empty( $referral ) || $commissions_table->has_items() ) :
	if ( ! empty( $referral ) ) :
		$delete_order_affiliate_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'yith_wcaf_delete_order_affiliate',
					'order_id' => $order->get_id(),
				),
				admin_url( 'admin.php' )
			),
			'delete_order_affiliate'
		)
		?>
		<div class="referral-user">
			<a href="<?php echo esc_url( $delete_order_affiliate_url ); ?>" class="delete-affiliate tips" data-tip="<?php echo esc_html_x( 'Delete Affiliates', '[ADMIN] Order commissions metabox', 'yith-woocommerce-affiliates' ); ?>"></a>
			<div class="referral-avatar">
				<?php echo get_avatar( $referral, 64 ); ?>
			</div>
			<div class="referral-info">
				<h3><a href="<?php echo esc_url( get_edit_user_link( $referral ) ); ?>"><?php echo esc_html( $username ); ?></a></h3>
				<a href="mailto:<?php echo esc_attr( $user_email ); ?>"><?php echo esc_html( $user_email ); ?></a>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( $commissions_table->has_items() ) : ?>
		<?php
			$order_total = apply_filters( 'yith_wcaf_commissions_metabox_order_total', $order->get_total(), $order );
			$total       = apply_filters( 'yith_wcaf_commissions_metabox_commissions_total', $commissions_table->has_items() ? $commissions_table->items->get_total_amount() : 0, $order );
			$net_total   = $order_total - $total;
		?>
		<div class="referral-commissions">
			<?php $commissions_table->display(); ?>
			<table class="commissions-totals">
				<tfoot class="totals">
					<tr>
						<td class="label" colspan="3"><?php echo esc_html_x( 'Order Total:', '[ADMIN] Order commissions metabox', 'yith-woocommerce-affiliates' ); ?></td>
						<td class="total">
							<?php
							/**
							 * APPLY_FILTERS: yith_wcaf_commissions_metabox_order_total_html
							 *
							 * Filters the order total inside the metabox in the edit order page.
							 *
							 * @param string   $formatted_total Order total formatted.
							 * @param float    $order_total     Order total.
							 * @param WC_Order $order           Order object.
							 */
							echo wp_kses_post( apply_filters( 'yith_wcaf_commissions_metabox_order_total_formatted', wc_price( $order_total ), $order_total, $order ) );
							?>
						</td>
					</tr>
					<tr>
						<td class="label" colspan="3">
							<?php
							printf(
								'%s <span class="tips" data-tip="%s">[?]</span>:',
								esc_html_x( 'Commissions', '[ADMIN] Order commissions metabox', 'yith-woocommerce-affiliates' ),
								esc_html_x( 'This is the total of commissions credited to referral', '[ADMIN] Order commissions metabox', 'yith-woocommerce-affiliates' )
							);
							?>
						</td>
						<td class="total">
							<?php
							/**
							 * APPLY_FILTERS: yith_wcaf_commissions_metabox_commissions_total_formatted
							 *
							 * Filters the commissions total inside the metabox in the edit order page.
							 *
							 * @param string   $formatted_total Commissions total formatted.
							 * @param double   $total           Commissions total.
							 * @param WC_Order $order           Order object.
							 */
							echo wp_kses_post( apply_filters( 'yith_wcaf_commissions_metabox_commissions_total_formatted', wc_price( $total ), $total, $order ) );
							?>
						</td>
					</tr>
					<tr>
						<td class="label" colspan="3"><?php echo esc_html_x( 'Store earnings:', '[ADMIN] Order commissions metabox', 'yith-woocommerce-affiliates' ); ?></td>
						<td class="total">
							<?php
							/**
							 * APPLY_FILTERS: yith_wcaf_commissions_metabox_net_total_formatted
							 *
							 * Filters the net revenue form the order inside the commissions metabox in the edit order page.
							 *
							 * @param string   $formatted_total Commissions total formatted.
							 * @param double   $net_total       Commissions total.
							 * @param WC_Order $order           Order object.
							 */
							echo wp_kses_post( apply_filters( 'yith_wcaf_commissions_metabox_net_total_formatted', wc_price( $net_total ), $net_total, $order ) );
							?>
						</td>
					</tr>

					<?php
					/**
					 * DO_ACTION: yith_wcaf_referral_totals_table
					 *
					 * Allows to render some content after the referrals commissions table.
					 *
					 * @param WC_Order $order Order object.
					 */
					do_action( 'yith_wcaf_referral_totals_table', $order );
					?>
				</tfoot>
			</table>
		</div>
	<?php endif; ?>
<?php else : ?>
	<div class="referral-user">
		<div class="no-referral-message">
			<?php echo esc_html_x( 'No referral set yet', '[ADMIN] Order commissions metabox', 'yith-woocommerce-affiliates' ); ?>
		</div>
		<div class="set-referral">
			<select
				name="referral_token"
				class="yith-wcaf-enhanced-select"
				data-action="yith_wcaf_get_affiliates_tokens"
				data-placeholder="<?php echo esc_attr_x( 'Select an affiliate', '[ADMIN] Order commissions metabox', 'yith-woocommerce-affiliates' ); ?>"
				data-security="<?php echo esc_attr( wp_create_nonce( 'search-affiliates' ) ); ?>"
				style="min-width: 200px;"
			></select>
			<button class="button button-secondary calculate-commission-button wc-reload">
				<?php echo esc_html_x( 'Calculate commissions', '[ADMIN] Order commissions metabox', 'yith-woocommerce-affiliates' ); ?>
			</button>
		</div>
	</div>
<?php endif; ?>
