<?php
/**
 * Affiliate details Admin Panel
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Views
 * @version 1.0.0
 */

/**
 * Template variables:
 *
 * @var $this              YITH_WCAF_Affiliates_Admin_Panel
 * @var $affiliate         YITH_WCAF_Affiliate
 * @var $commissions_table YITH_WCAF_Commissions_Admin_Table
 * @var $payments_table    YITH_WCAF_Payments_Admin_Table
 * @var $user              WP_User
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<div id="yith_wcaf_panel_affiliate_details" class="yit-admin-panel-container">
	<form method="post" id="yith-wcaf-list-table-form" autocomplete="off">
		<input type="hidden" name="affiliate_id" id="affiliate_id" value="<?php echo esc_attr( $affiliate->get_id() ); ?>" />
		<input type="hidden" name="affiliates[]" value="<?php echo esc_attr( $affiliate->get_id() ); ?>" />

		<a class="head-me-back" href="<?php echo esc_url( $this->get_tab_url() ); ?>">
			<?php echo esc_html_x( '&lt; Back to Affiliates list', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ); ?>
		</a>

		<div class="grid-container">

			<div class="column-1 primary">

				<div class="block highlight" id="affiliate_stats">
					<h2>
						<?php
						// translators: 1. Formatted affiliate name.
						echo wp_kses_post( sprintf( _x( '<b>%s</b> details', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ), $affiliate->get_formatted_name() ) );
						?>
					</h2>

					<div class="stats-container">
						<?php
						$stats = array(
							'earnings' => _x( 'Total earnings', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ),
							'paid'     => _x( 'Paid', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ),
							'refunds'  => _x( 'Refunds', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ),
							'balance'  => _x( 'Available balance', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ),
						);

						foreach ( $stats as $stat => $stat_label ) :
							$stat_getter = "get_{$stat}";

							if ( ! method_exists( $affiliate, $stat_getter ) ) {
								continue;
							}

							$stat_value = $affiliate->$stat_getter();
							?>
							<div class="stat">
								<h3 class="stat-label">
									<?php echo esc_html( $stat_label ); ?>
								</h3>
								<p class="stat-content <?php echo $stat_value ? '' : 'empty'; ?>">
									<?php echo $stat_value ? wp_kses_post( wc_price( $stat_value ) ) : '-'; ?>
								</p>

								<?php
								if ( 'balance' === $stat && $affiliate->has_unpaid_commissions() ) {
									$pay_action = $affiliate->get_admin_action( 'pay' );

									if ( $pay_action ) {
										printf(
											'<a class="button button-primary button-pay-now" href="%1$s">%2$s</a>',
											esc_url( $pay_action['url'] ),
											esc_html_x( 'Pay now', '[ADMIN] Pay now button in balance column of Affiliates table', 'yith-woocommerce-affiliates' )
										);
									}
								}
								?>
							</div>
							<?php
						endforeach;
						?>
					</div>
				</div>

				<div class="block" id="affiliate_related_items">
					<div class="tabbed-content">
						<ul class="tab-anchors tab-3">
							<li>
								<a href="#" class="tab-anchor" data-tab="last_commissions">
									<?php echo esc_html_x( 'Latest affiliate commissions', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ); ?>
								</a>
							</li>
							<li>
								<a href="#" class="tab-anchor" data-tab="last_payments">
									<?php echo esc_html_x( 'Latest affiliate payments', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ); ?>
								</a>
							</li>
							<li>
								<a href="#" class="tab-anchor" data-tab="associated_users">
									<?php echo esc_html_x( 'Affiliate associated users', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ); ?>
								</a>
							</li>
						</ul>

						<div class="tabs">
							<div id="last_commissions" class="tab">
								<?php $commissions_table->display(); ?>
								<?php if ( $commissions_table->has_items() ) : ?>
									<p class="view-all-items">
										<a href="<?php echo esc_url( YITH_WCAF_Admin()->get_tab_url( 'commissions', '', array( '_affiliate_id' => $affiliate->get_id() ) ) ); ?>">
											<?php echo esc_html_x( 'View all &gt;', '[ADMIN] Affiliate details panel (Commissions)', 'yith-woocommerce-affiliates' ); ?>
										</a>
									</p>
								<?php endif; ?>
							</div>
							<div id="last_payments" class="tab">
								<?php $payments_table->display(); ?>
								<?php if ( $payments_table->has_items() ) : ?>
									<p class="view-all-items">
										<a href="<?php echo esc_url( YITH_WCAF_Admin()->get_tab_url( 'commissions', 'commissions-payments', array( '_affiliate_id' => $affiliate->get_id() ) ) ); ?>">
											<?php echo esc_html_x( 'View all &gt;', '[ADMIN] Affiliate details panel (Payments)', 'yith-woocommerce-affiliates' ); ?>
										</a>
									</p>
								<?php endif; ?>
							</div>
							<div id="associated_users" class="tab">
								<?php if ( ! empty( $associated_users ) ) : ?>
									<ul class="affiliate-users-list">
										<?php
										foreach ( $associated_users as $associated_user ) :
											$user_id = $associated_user->ID;
											?>
											<li class="affiliate-user">
												<div class="referral-user">
													<div class="referral-avatar">
														<?php echo get_avatar( $user_id, 64 ); ?>
													</div>
													<div class="referral-info">
														<h3><a href="<?php echo esc_url( get_edit_user_link( $user_id ) ); ?>"><?php echo esc_html( $associated_user->user_login ); ?></a></h3>
														<a href="mailto:<?php echo esc_attr( $associated_user->user_email ); ?>"><?php echo esc_html( $associated_user->user_email ); ?></a>
													</div>
												</div>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php else : ?>
									<p class="no-items-found">
										<i class="yith-icon yith-icon-affiliates"></i>
										<?php echo esc_html_x( 'No user associated with this affiliate yet', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ); ?>
									</p>
								<?php endif; ?>
							</div>
						</div>
					</div>
				</div>

				<div class="block" id="affiliate_settings_1">
					<h3><?php echo esc_html_x( 'Affiliate options', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ); ?></h3>
					<?php YITH_WCAF_Admin_Profile_Premium::print_profile_fields( $user, 'affiliate_details', false ); ?>
				</div>

				<?php
				/**
				 * DO_ACTION: yith_wcaf_after_affiliate_options
				 *
				 * Allows to render some content after the affiliate options in the affiliate profile.
				 *
				 * @param YITH_WCAF_Affiliate $affiliate Affiliate object
				 */
				do_action( 'yith_wcaf_after_affiliate_options', $affiliate );
				?>
			</div>

			<div class="column-2 secondary">

				<div class="block highlight" id="affiliate_url" data-token="<?php echo esc_attr( $affiliate->get_token() ); ?>" data-token-var="<?php echo esc_attr( YITH_WCAF_Session()->get_ref_name() ); ?>">
					<h3><?php echo esc_html_x( 'Referral link generator', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ); ?></h3>

					<p class="form-row form-row-wide">
						<label for="origin_url">
							<?php echo esc_html_x( 'Page URL:', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ); ?>
						</label>
						<?php
						/**
						 * APPLY_FILTERS: yith_wcaf_origin_url
						 *
						 * Filters the origin URL to build the referral URL.
						 *
						 * @param string $url Origin URL
						 */
						?>
						<input type="url" id="origin_url" class="origin-url" value="<?php echo esc_attr( apply_filters( 'yith_wcaf_origin_url', home_url() ) ); ?>"/>
					</p>

					<p class="form-row form-row-wide">
						<label for="generated_url">
							<?php echo esc_html_x( 'Referral URL:', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ); ?>
						</label>
						<span class="copy-field-wrapper">
							<input type="url" id="generated_url" class="generated-url copy-target" readonly />
							<a class="copy-trigger">
								<?php echo esc_html_x( 'Copy', '[GLOBAL] Copy link', 'yith-woocommerce-affiliates' ); ?>
							</a>
						</span>
					</p>
				</div>

				<div class="block" id="affiliate_settings_2">
					<h3 class="editable"><?php echo esc_html_x( 'Affiliate info', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ); ?></h3>
					<p class="application-date">
						<b><?php echo esc_html_x( 'Application date:', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ); ?></b>
						<?php echo esc_html( date_i18n( wc_date_format(), strtotime( $affiliate->get_meta( 'application_date' ) ) ) ); ?>
					</p>

					<div class="editable-section">
						<div class="edited">
							<?php YITH_WCAF_Admin_Profile_Premium::print_profile_details( $user, 'affiliate_additional_info', false ); ?>
						</div>

						<div class="edit">
							<?php YITH_WCAF_Admin_Profile_Premium::print_profile_fields( $user, 'affiliate_additional_info', false ); ?>
						</div>
					</div>

					<?php
					foreach ( YITH_WCAF_Gateways::get_available_gateways() as $gateway_id => $gateway ) :
						if ( ! $gateway->has_fields() ) {
							continue;
						}
						?>
						<h3>
							<?php
							// translators: 1. Gateway name.
							echo esc_html( sprintf( _x( 'Affiliate %s info', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ), $gateway->get_name() ) );
							?>
						</h3>
						<?php
						YITH_WCAF_Admin_Profile_Premium::print_profile_fields( $user, "affiliate_{$gateway_id}_info", false );
					endforeach;
					?>
				</div>

				<p class="submit">
					<button id="save_profile" class="button-primary yith-plugin-fw__button--xxl">
						<?php echo esc_html_x( 'Update affiliate profile', '[ADMIN] Affiliate details panel', 'yith-woocommerce-affiliates' ); ?>
					</button>
				</p>

			</div>

		</div>
		<?php wp_nonce_field( 'update-user_' . $affiliate->get_user_id() ); ?>
	</form>
</div>
