<?php
/**
 * Affiliate Dashboard - Settings
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 2.0.0
 */

/**
 * Template variables:
 *
 * @var $section                string
 * @var $atts                   array
 * @var $affiliate              YITH_WCAF_Affiliate
 * @var $show_billing_fields    bool
 * @var $show_additional_fields bool
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>


<?php
/**
 * DO_ACTION: yith_wcaf_before_dashboard_section
 *
 * Allows to render some content before the section in the Affiliate Dashboard.
 *
 * @param string $section Section.
 * @param array  $atts    Array with section attributes.
 */
do_action( 'yith_wcaf_before_dashboard_section', 'settings', $atts );
?>

<form method="post">

	<div class="affiliate-settings-box <?php echo $show_billing_fields || $show_additional_fields ? 'double-column' : 'single-column'; ?>">

		<div class="personal-info">
			<?php
			/**
			 * DO_ACTION: yith_wcaf_settings_form_start
			 *
			 * Allows to render some content before the settings section in the Affiliate Dashboard.
			 */
			do_action( 'yith_wcaf_settings_form_start' );
			?>

			<?php
			/**
			 * APPLY_FILTERS: yith_wcaf_payment_email_required
			 *
			 * Filters whether the payment email field is required.
			 *
			 * @param bool $is_payment_email_required Whether payment email is required or not.
			 */
			if ( apply_filters( 'yith_wcaf_payment_email_required', true ) ) :
				?>
				<div class="settings-box">
					<h3><?php echo esc_html_x( 'Payment info', '[FRONTEND] Dashboard Settings', 'yith-woocommerce-affiliates' ); ?></h3>

					<p class="form form-row">
						<label for="payment_email">
							<?php
							/**
							 * APPLY_FILTERS: yith_wcaf_payment_email_dashboard_settings
							 *
							 * Filters the label for the payment email field.
							 *
							 * @param string $label Label.
							 */
							echo esc_html( apply_filters( 'yith_wcaf_payment_email_dashboard_settings', _x( 'Payment email', '[FRONTEND] Dashboard Settings', 'yith-woocommerce-affiliates' ) ) );
							?>
						</label>
						<input type="email" name="payment_email" id="payment_email" value="<?php echo esc_attr( $affiliate->get_payment_email() ); ?>"/>
						<small><?php echo esc_html_x( '(Email address where you want to receive PayPal payments for commissions)', '[FRONTEND] Dashboard Settings', 'yith-woocommerce-affiliates' ); ?></small>
					</p>
				</div>
			<?php endif; ?>

			<?php
			/**
			 * DO_ACTION: yith_wcaf_settings_form_after_payment_email
			 *
			 * Allows to render some content after the payment email in the settings form in the Affiliate Dashboard.
			 */
			do_action( 'yith_wcaf_settings_form_after_payment_email' );
			?>

		</div>

		<div class="additional-info">
			<?php
			/**
			 * DO_ACTION: yith_wcaf_settings_form_before_additional_info
			 *
			 * Allows to render some content before the additional info in the settings form in the Affiliate Dashboard.
			 */
			do_action( 'yith_wcaf_settings_form_before_additional_info' );
			?>

			<div class="settings-box">
				<p class="form form-row toggle-right">
					<label for="notify_pending_commissions">
						<input type="checkbox" name="notify_pending_commissions" id="notify_pending_commissions" class="yith-wcaf-toggle" value="yes" <?php checked( $affiliate->should_notify( 'pending_commission' ) ); ?> />
						<?php echo esc_html_x( 'Notify on new commissions', '[FRONTEND] Dashboard Settings', 'yith-woocommerce-affiliates' ); ?>
					</label>
					<small><?php echo esc_html_x( 'Get an email when a commission status switches to pending', '[FRONTEND] Dashboard Settings', 'yith-woocommerce-affiliates' ); ?></small>
				</p>
			</div>

			<div class="settings-box">
				<p class="form form-row toggle-right">
					<label for="notify_paid_commissions">
						<input type="checkbox" name="notify_paid_commissions" id="notify_paid_commissions" class="yith-wcaf-toggle" value="yes" <?php checked( $affiliate->should_notify( 'paid_commission' ) ); ?> />
						<?php echo esc_html_x( 'Notify on paid commissions', '[FRONTEND] Dashboard Settings', 'yith-woocommerce-affiliates' ); ?>
					</label>
					<small><?php echo esc_html_x( 'Get an email when a commission is paid', '[FRONTEND] Dashboard Settings', 'yith-woocommerce-affiliates' ); ?></small>
				</p>
			</div>

			<?php
			/**
			 * DO_ACTION: yith_wcaf_settings_form
			 *
			 * Allows to render some content in the settings form in the Affiliate Dashboard.
			 */
			do_action( 'yith_wcaf_settings_form' );
			?>
		</div>

	</div>

	<?php wp_nonce_field( 'yith-wcaf-save-affiliate-settings', 'save_affiliate_settings' ); ?>

	<input type="submit" name="settings_submit" value="<?php echo esc_attr_x( 'Save info', '[FRONTEND] Dashboard Settings', 'yith-woocommerce-affiliates' ); ?>"/>

</form>

<?php
/**
 * DO_ACTION: yith_wcaf_after_dashboard_section
 *
 * Allows to render some content after the section in the Affiliate Dashboard.
 *
 * @param string $section Section.
 * @param array  $atts    Array with section attributes.
 */
do_action( 'yith_wcaf_after_dashboard_section', 'settings', $atts );
