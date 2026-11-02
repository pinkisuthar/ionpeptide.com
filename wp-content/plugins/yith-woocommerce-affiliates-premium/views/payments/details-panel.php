<?php
/**
 * Payment Details Admin Panel
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Views
 * @version 1.0.0
 */

/**
 * Template variables:
 *
 * @var $this              YITH_WCAF_Payments_Admin_Panel
 * @var $payment           YITH_WCAF_Payment
 * @var $affiliate         YITH_WCAF_Affiliate
 * @var $notes             YITH_WCAF_Note[]
 * @var $available_actions array
 * @var $payment_email     string
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<div id="yith_wcaf_panel_payment_details" class="yit-admin-panel-container">
	<form method="post" id="yith-wcaf-list-table-form" autocomplete="off" enctype="multipart/form-data">
		<input type="hidden" name="payment_id" id="payment_id" value="<?php echo esc_attr( $payment->get_id() ); ?>" />
		<input type="hidden" name="payments[]" value="<?php echo esc_attr( $payment->get_id() ); ?>" />

		<a class="head-me-back" href="<?php echo esc_url( $this->get_tab_url() ); ?>">
			<?php echo esc_html_x( '&lt; Back to Payments list', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?>
		</a>

		<div class="grid-container">

			<div class="column-1 primary">
				<div class="block highlight" id="payment_details">
					<h2>
						<?php
						// translators: 1. Payment id.
						echo wp_kses_post( sprintf( _x( '<b>Payment #%s</b> details', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ), $payment->get_id() ) );
						?>
					</h2>

					<p class="payment-status">
						<mark class="<?php echo esc_attr( $payment->get_status() ); ?> status-badge">
							<?php echo esc_attr( $payment->get_formatted_status() ); ?>
						</mark>
						<?php
						echo wp_kses_post(
							sprintf(
							// translators: 1. URL to Affiliate edit page. 2. Affiliate formatted name. 3. Gateway name.
								_x( 'Paid to <a target="_blank" href="%1$s">%2$s</a> &#8212; Payment via: %3$s', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ),
								$affiliate ? $affiliate->get_admin_edit_url() : '#',
								$affiliate ? $affiliate->get_formatted_name() : _x( 'N/A', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ),
								$payment->get_gateway_id() ? $payment->get_formatted_gateway() : _x( 'N/A', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' )
							)
						);

						if ( $payment->get_transaction_key() ) {
							// translators: 1. Payment transaction key.
							echo wp_kses_post( sprintf( _x( '(Trans. ID: %s)', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ), $payment->get_transaction_key() ) );
						}
						?>
					</p>

					<div class="payment-details-container">
						<div class="column">
							<h4>
								<?php echo esc_html_x( 'General', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?>
							</h4>

							<p>
								<strong><?php echo esc_html_x( 'Payment date:', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></strong>
								<?php echo esc_html( $payment->get_created_at( 'edit' )->date_i18n( wc_date_format() ) ); ?>
							</p>

							<p>
								<strong><?php echo esc_html_x( 'Completed:', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></strong>
								<?php if ( $payment->get_completed_at() ) : ?>
								<abbr title="<?php echo esc_attr( $payment->get_completed_at( 'edit' )->date_i18n( wc_date_format() ) ); ?>">
									<?php
									// translators: 1. How much time is passed since commission last edit (1 hours ago/3 days ago).
									echo esc_html( sprintf( _x( '%s ago', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ), human_time_diff( $payment->get_completed_time( 'edit' ) ) ) );
									?>
								</abbr>
								<?php else : ?>
									<?php echo esc_html_x( 'N/A', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?>
								<?php endif; ?>
							</p>

							<p>
								<strong><?php echo esc_html_x( 'Affiliate invoice:', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></strong>

								<?php if ( $payment->has_invoice() ) : ?>
									<a class="inline-actions" href="<?php echo esc_url( $payment->get_invoice_url() ); ?>" title="<?php echo esc_attr_x( 'Download invoice', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?>">
										<?php echo esc_html_x( 'Download &gt;', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?>
									</a>
								<?php endif; ?>

								<a class="inline-actions" href="<?php echo esc_url( YITH_WCAF_Admin_Actions::get_action_url( 'regenerate_invoice', array( 'payment_id' => $payment->get_id() ) ) ); ?>" title="<?php echo esc_attr_x( 'Regenerate invoice', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?>">
									<?php echo $payment->has_invoice() ? esc_html_x( 'Regenerate &gt;', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ) : esc_html_x( 'Generate &gt;', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?>
								</a>

								<a class="yith-wcaf-attach-file auto-submit inline-actions" href="#" role="button" title="<?php echo esc_attr_x( 'Upload new invoice', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?>">
									<?php echo esc_html_x( 'Upload new &gt;', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?>
								</a>

								<input type="file" id="invoice_file" name="invoice_file" accept="application/pdf"/>
							</p>
						</div>
						<div class="column">
							<h4><?php echo esc_html_x( 'Affiliate details', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></h4>

							<p>
								<?php if ( $affiliate && $affiliate->get_user() ) : ?>
									<strong><?php echo esc_html_x( 'Email:', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></strong>
									<?php
									echo wp_kses_post( sprintf( '<a href="mailto:%1$s">%1$s</a>', $affiliate->get_user()->user_email ) );
									?>
								<?php else : ?>
									<em><?php echo esc_html_x( 'User deleted', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></em>
								<?php endif; ?>
							</p>

							<h4 class="editable"><?php echo esc_html_x( 'Billing information', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></h4>

							<p class="edited">
								<?php echo wp_kses_post( $affiliate ? $affiliate->get_formatted_invoice_profile() : _x( 'N/A', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ) ); ?>
							</p>

							<div class="edit form-table">
								<?php YITH_WCAF_Affiliates_Invoice_Profile::show_fields( $affiliate ); ?>
							</div>
						</div>
						<div class="column">
							<h4 class="editable"><?php echo esc_html_x( 'Gateway details', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></h4>

							<div class="edited">
								<p>
									<strong><?php echo esc_html_x( 'Gateway:', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></strong>
									<?php
									if ( ! $payment->get_gateway_id() ) :
										echo esc_html_x( 'N/A', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' );
									else :
										echo esc_html( $payment->get_formatted_gateway() );
									endif;
									?>
								</p>

								<?php if ( $payment->get_gateway_id() ) : ?>
									<h4><?php echo esc_html_x( 'Gateway preferences:', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></h4>
									<p>
										<?php echo wp_kses_post( nl2br( $payment->get_formatted_gateway_details() ) ); ?>
									</p>
								<?php elseif ( $payment_email ) : ?>
									<h4><?php echo esc_html_x( 'Payment email:', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></h4>
									<p>
										<?php echo esc_html( $payment_email ); ?>
									</p>
								<?php endif; ?>
							</div>

							<div class="edit yith-wcaf-accordion">
								<?php YITH_WCAF_Gateways::show_fields( $payment ); ?>
							</div>
						</div>
					</div>
				</div>

				<div class="block" id="items_details">
					<?php require YITH_WCAF_DIR . 'views/payments/items-table.php'; ?>
				</div>
			</div>

			<div class="column-2 secondary">
				<div class="block" id="item_actions">
					<h3><?php echo esc_html_x( 'Payment actions', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></h3>
					<?php require YITH_WCAF_DIR . 'views/common/actions-select.php'; ?>
				</div>

				<div class="block" id="payment_affiliate">
					<h3><?php echo esc_html_x( 'Payment affiliate', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></h3>
					<div class="referral-user">
						<?php if ( $affiliate && $affiliate->get_user() ) : ?>
							<div class="referral-avatar">
								<?php echo get_avatar( $affiliate->get_user_id(), 64 ); ?>
							</div>
							<div class="referral-info">
								<h3>
									<a href="<?php echo esc_url( $affiliate->get_admin_edit_url() ); ?>">
										<?php echo esc_html( $affiliate->get_formatted_name() ); ?>
									</a>
								</h3>
								<a href="mailto:<?php echo esc_attr( $affiliate->get_user()->user_email ); ?>">
									<?php echo esc_html( $affiliate->get_user()->user_email ); ?>
								</a>
							</div>
						<?php else : ?>
							<em><?php echo esc_html_x( 'User deleted', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></em>
						<?php endif; ?>
					</div>
					<?php if ( $affiliate ) : ?>
						<div class="referral-stats">
							<table>
								<tbody>
								<tr>
									<td class="label">
										<?php echo esc_html_x( 'Earnings:', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?>
									</td>
									<td class="total">
										<?php echo wp_kses_post( wc_price( $affiliate->get_earnings() ) ); ?>
									</td>
								</tr>
								<tr>
									<td class="label">
										<?php echo esc_html_x( 'Paid:', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?>
									</td>
									<td class="total">
										<?php echo wp_kses_post( wc_price( $affiliate->get_paid() ) ); ?>
									</td>
								</tr>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>

				<div class="block" id="payment_notes">
					<h3><?php echo esc_html_x( 'Payment notes', '[ADMIN] Payment details panel', 'yith-woocommerce-affiliates' ); ?></h3>

					<?php require YITH_WCAF_DIR . 'views/common/notes-list.php'; ?>
				</div>
			</div>
		</div>
		<?php wp_nonce_field( 'edit_payment', 'edit_payment' ); ?>
	</form>
</div>
