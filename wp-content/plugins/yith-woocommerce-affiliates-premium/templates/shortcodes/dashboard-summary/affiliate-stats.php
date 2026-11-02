<?php
/**
 * Affiliate Dashboard Summary - Stats
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 2.0.0
 */

/**
 * Template variables:
 *
 * @var $affiliate                   YITH_WCAF_Affiliate
 * @var $show_commissions_summary    bool
 * @var $number_of_commissions       int
 * @var $show_clicks_summary         bool
 * @var $number_of_clicks            int
 * @var $show_active_balance         bool
 * @var $has_only_active_balance     bool
 * @var $clicks                      YITH_WCAF_Clicks_Collection
 * @var $commissions                 YITH_WCAF_Commissions_Collection
 * @var $affiliate_total_balance     float
 * @var $affiliate_available_balance float
 * @var $affiliate_earnings          float
 * @var $affiliate_paid              float
 * @var $affiliate_refunds           float
 * @var $affiliate_rate              float
 * @var $affiliate_conversion        int
 * @var $affiliate_visits            int
 * @var $affiliate_visits_today      int
 * @var $balance_description         string
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! $affiliate || ! $affiliate instanceof YITH_WCAF_Affiliate ) {
	return;
}

?>

<!--AFFILIATE STATS-->

<div class="affiliate-stats">
	<div class="stat-box <?php echo $show_active_balance ? 'with-active-balance' : 'without-active-balance'; ?>">
		<div class="stat-item large">
			<span class="stat-label">
				<?php
				echo $has_only_active_balance ?
					esc_html_x( 'Available balance', '[FRONTEND] Affiliate dashboard', 'yith-woocommerce-affiliates' ) :
					esc_html_x( 'Current balance', '[FRONTEND] Affiliate dashboard', 'yith-woocommerce-affiliates' );
				?>
				<?php if ( $show_active_balance ) : ?>
					<span class="desc-tip" data-tip="<?php echo esc_attr( $balance_description ); ?>"></span>
				<?php endif; ?>
			</span>
			<span class="stat-value">
				<?php echo wp_kses_post( wc_price( $affiliate_total_balance ) ); ?>
			</span>
			<?php if ( $show_active_balance ) : ?>
				<span class="stat-item highlight affiliate-active-balance">
					<span class="stat-label">
						<?php echo esc_html_x( 'Available balance', '[FRONTEND] Affiliate dashboard', 'yith-woocommerce-affiliates' ); ?>
					</span>
					<span class="stat-value">
						<?php echo wp_kses_post( wc_price( $affiliate_available_balance ) ); ?>
					</span>
					<?php do_action( 'yith_wcaf_after_affiliate_active_balance' ); ?>
				</span>
			<?php endif; ?>
			<?php do_action( 'yith_wcaf_after_affiliate_balance' ); ?>
		</div>
		<div class="stat-item">
			<span class="stat-label">
				<?php echo esc_html_x( 'Total earnings', '[FRONTEND] Affiliate dashboard', 'yith-woocommerce-affiliates' ); ?>
			</span>
			<span class="stat-value">
				<?php echo wp_kses_post( wc_price( $affiliate_earnings ) ); ?>
			</span>
		</div>
		<div class="stat-item">
			<span class="stat-label">
				<?php echo esc_html_x( 'Total paid', '[FRONTEND] Affiliate dashboard', 'yith-woocommerce-affiliates' ); ?>
			</span>
			<span class="stat-value">
				<?php echo wp_kses_post( wc_price( $affiliate_paid ) ); ?>
			</span>
		</div>
		<div class="stat-item">
			<span class="stat-label">
				<?php echo esc_html_x( 'Total refunded', '[FRONTEND] Affiliate dashboard', 'yith-woocommerce-affiliates' ); ?>
			</span>
			<span class="stat-value">
				<?php echo wp_kses_post( wc_price( $affiliate_refunds ) ); ?>
			</span>
		</div>
	</div>

	<div class="stat-box">
		<div class="stat-item large">
				<span class="stat-label">
					<?php echo esc_html_x( 'Commission rate', '[FRONTEND] Affiliate dashboard', 'yith-woocommerce-affiliates' ); ?>
				</span>
			<span class="stat-value">
					<?php echo esc_html( $affiliate_rate ); ?>
				</span>
		</div>
		<div class="stat-item large">
				<span class="stat-label">
					<?php echo esc_html_x( 'Conversion rate', '[FRONTEND] Affiliate dashboard', 'yith-woocommerce-affiliates' ); ?>
				</span>
			<span class="stat-value">
					<?php echo esc_html( $affiliate_conversion ); ?>
				</span>
		</div>
	</div>

	<?php if ( $show_clicks_summary ) : ?>
		<div class="stat-box">
			<div class="stat-item large">
					<span class="stat-label">
						<?php echo esc_html_x( 'Visits', '[FRONTEND] Affiliate dashboard', 'yith-woocommerce-affiliates' ); ?>
					</span>
				<span class="stat-value">
						<?php echo esc_html( $affiliate_visits ); ?>
					</span>
			</div>
			<div class="stat-item large">
					<span class="stat-label">
						<?php echo esc_html_x( 'Visits today', '[FRONTEND] Affiliate dashboard', 'yith-woocommerce-affiliates' ); ?>
					</span>
				<span class="stat-value">
						<?php echo esc_html( $affiliate_visits_today ); ?>
					</span>
			</div>
		</div>
	<?php endif; ?>
</div>
