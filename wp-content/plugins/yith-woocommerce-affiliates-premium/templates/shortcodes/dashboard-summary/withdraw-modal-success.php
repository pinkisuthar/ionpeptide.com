<?php
/**
 * Affiliate Dashboard Payments - Withdraw Success
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<div id="withdraw_success">
	<h3 class="centered">
		<?php echo esc_html_x( 'Thanks!', '[FRONTEND] Withdraw success', 'yith-woocommerce-affiliates' ); ?><br/>
		<?php echo esc_html_x( 'Your request has been sent.', '[FRONTEND] Withdraw success', 'yith-woocommerce-affiliates' ); ?>
	</h3>
	<p>
		<?php
		/**
		 * APPLY_FILTERS: yith_wcaf_withdraw_modal_success_message
		 *
		 * Filters the success message in the withdraw modal when requesting the payment.
		 *
		 * @param string $message Message.
		 */
		echo esc_html(
			apply_filters(
				'yith_wcaf_withdraw_modal_success_message',
				_x( 'We will process the payment as soon as possible. You will get a confirmation email once the payment has been processed correctly.', '[FRONTEND] Withdraw success', 'yith-woocommerce-affiliates' )
			)
		);
		?>
	</p>
	<p>
		<?php echo esc_html_x( 'Keep up the good work!', '[FRONTEND] Withdraw success', 'yith-woocommerce-affiliates' ); ?>
	</p>

	<a href="#" class="close-button" role="button">
		<?php echo esc_html_x( 'Close', '[FRONTEND] Withdraw success', 'yith-woocommerce-affiliates' ); ?>
	</a>
</div>
