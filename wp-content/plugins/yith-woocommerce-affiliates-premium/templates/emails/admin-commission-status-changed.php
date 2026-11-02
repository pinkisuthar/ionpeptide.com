<?php
/**
 * Commission changed status email template
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 1.0.0
 */

/**
 * Template variables:
 *
 * @var $email_heading  string
 * @var $email          WC_Email
 * @var $order          WC_Order
 * @var $commissions    YITH_WCAF_Commissions_Collection
 * @var $affiliate      YITH_WCAF_Affiliate
 * @var $confirmed      bool
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php echo esc_html_x( 'A set of commissions has just changed status.', '[EMAILS] Commission status changed email', 'yith-woocommerce-affiliates' ); ?>
	<?php if ( $confirmed ) : ?>
		<br/>
		<?php echo esc_html_x( 'Commissions are confirmed and ready to be paid.', '[EMAILS] Commission status changed email', 'yith-woocommerce-affiliates' ); ?>
	<?php endif; ?>
</p>

<?php
YITH_WCAF_Emails::print_commissions_table(
	$commissions,
	false,
	array(
		'affiliate'     => $affiliate,
		'order'         => $order,
		'token'         => $affiliate->get_token(),
		'sent_to_admin' => true,
	)
);
?>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
