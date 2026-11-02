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
 * @var $payments_table    YITH_WCAF_Payments_Admin_Table
 * @var $notes             YITH_WCAF_Note[]
 * @var $available_actions array
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<div id="yith_wcaf_panel_commission_details" class="yit-admin-panel-container">
	<form method="post" id="yith-wcaf-list-table-form" autocomplete="off">
		<input type="hidden" name="commission_id" id="commission_id" value="<?php echo esc_attr( $commission->get_id() ); ?>" />
		<input type="hidden" name="commissions[]" value="<?php echo esc_attr( $commission->get_id() ); ?>" />

		<a class="head-me-back" href="<?php echo esc_url( $this->get_tab_url() ); ?>">
			<?php echo esc_html_x( '&lt; Back to Commissions list', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?>
		</a>

		<div class="grid-container">

			<div class="column-1 primary">
				<div class="block highlight" id="commission_details">
					<h2>
						<?php
						// translators: 1. Commission id.
						echo wp_kses_post( sprintf( _x( '<b>Commission #%s</b> details', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ), $commission->get_id() ) );
						?>
					</h2>

					<p class="commission-status">
						<mark class="<?php echo esc_attr( $commission->get_status() ); ?> status-badge">
							<?php echo esc_attr( $commission->get_formatted_status() ); ?>
						</mark>
						<?php
						echo wp_kses_post(
							sprintf(
								// translators: 1. URL to Affiliate edit page. 2. Affiliate formatted name. 3. URL to Order edit page 4. Order number 5. Order status.
								_x( 'Credited to <a target="_blank" href="%1$s">%2$s</a> &#8212; Order: <a target="_blank" href="%3$s">%4$s</a> &#8212; Order status: %5$s', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ),
								$affiliate ? $affiliate->get_admin_edit_url() : '#',
								$affiliate ? $affiliate->get_formatted_name() : _x( 'N/A', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ),
								$commission->get_order_edit_url(),
								$order ? $order->get_order_number() : _x( 'N/A', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ),
								$order ? wc_get_order_status_name( $order->get_status() ) : _x( 'N/A', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' )
							)
						);
						?>
					</p>

					<div class="commission-details-container">
						<div class="column">
							<h4>
								<?php echo esc_html_x( 'General', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?>
							</h4>

							<p>
								<strong><?php echo esc_html_x( 'Commission date:', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></strong>
								<?php echo esc_html( $commission->get_created_at( 'edit' )->date_i18n( wc_date_format() ) ); ?>
							</p>

							<p>
								<strong><?php echo esc_html_x( 'Last update:', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></strong>
								<abbr title="<?php echo esc_attr( $commission->get_last_edit( 'edit' )->date_i18n( wc_date_format() ) ); ?>">
									<?php
									// translators: 1. How much time is passed since commission last edit (1 hours ago/3 days ago).
									echo esc_html( sprintf( _x( '%s ago', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ), human_time_diff( $commission->get_edited_time( 'edit' ) ) ) );
									?>
								</abbr>
							</p>
						</div>
						<div class="column">
							<h4><?php echo esc_html_x( 'Affiliate details', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></h4>

							<p>
								<?php if ( $affiliate && $affiliate->get_user() ) : ?>
									<strong><?php echo esc_html_x( 'Email:', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></strong>
									<?php
									echo wp_kses_post( sprintf( '<a href="mailto:%1$s">%1$s</a>', $affiliate->get_user()->user_email ) );
									?>
								<?php else : ?>
									<em><?php echo esc_html_x( 'User deleted', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></em>
								<?php endif; ?>
							</p>

							<h4><?php echo esc_html_x( 'Billing information', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></h4>

							<p>
								<?php echo wp_kses_post( $affiliate ? $affiliate->get_formatted_invoice_profile() : _x( 'N/A', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ) ); ?>
							</p>
						</div>
						<div class="column">
							<h4><?php echo esc_html_x( 'Order details', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></h4>

							<p>
								<?php if ( $order && $order->get_user() ) : ?>
									<strong><?php echo esc_html_x( 'Email:', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></strong>
									<?php
									echo wp_kses_post( sprintf( '<a href="mailto:%1$s">%1$s</a>', $order->get_user()->user_email ) );
									?>
								<?php else : ?>
									<em><?php echo esc_html_x( 'Guest', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></em>
								<?php endif; ?>
							</p>

							<h4><?php echo esc_html_x( 'Billing information', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></h4>

							<p>
								<?php echo wp_kses_post( $order ? $order->get_formatted_billing_address() : _x( 'N/A', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ) ); ?>
							</p>
						</div>
					</div>
				</div>
				<div class="block" id="items_details">
					<?php require YITH_WCAF_DIR . 'views/commissions/items-table.php'; ?>
				</div>
			</div>

			<div class="column-2 secondary">
				<div class="block" id="item_actions">
					<h3><?php echo esc_html_x( 'Commission actions', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></h3>
					<?php require YITH_WCAF_DIR . 'views/common/actions-select.php'; ?>
				</div>

				<div class="block" id="commission_payments">
					<h3><?php echo esc_html_x( 'Commission payments', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></h3>
					<?php $payments_table->display(); ?>
				</div>

				<div class="block" id="commission_notes">
					<h3><?php echo esc_html_x( 'Commission notes', '[ADMIN] Commission details panel', 'yith-woocommerce-affiliates' ); ?></h3>

					<?php require YITH_WCAF_DIR . 'views/common/notes-list.php'; ?>
				</div>
			</div>

		</div>
		<?php wp_nonce_field( 'edit_commission', 'edit_commission' ); ?>
	</form>
</div>
