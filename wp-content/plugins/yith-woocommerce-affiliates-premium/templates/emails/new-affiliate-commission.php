<?php
/**
 * New confirmed commissions email template
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 1.0.0
 */

/**
 * Template variables:
 *
 * @var $email_heading    string
 * @var $email            WC_Email
 * @var $order            WC_Order
 * @var $affiliate        YITH_WCAF_Affiliate
 * @var $commissions      YITH_WCAF_Commissions_Collection
 * @var $display_name     string
 * @var $currency         string
 * @var $user             WP_User
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	// translators: 1. Affiliate formatted name.
	echo esc_html( sprintf( _x( 'Hi %s,', '[EMAILS] New affiliate commission', 'yith-woocommerce-affiliates' ), $display_name ) );
	?>
</p>

<p>
	<?php echo esc_html( _x( 'The following commissions are confirmed and ready to be paid by an admin:', '[EMAILS] New affiliate commission', 'yith-woocommerce-affiliates' ) ); ?>
</p>

<?php
YITH_WCAF_Emails::print_commissions_table(
	$commissions,
	false,
	array(
		'affiliate' => $affiliate,
		'order'     => $order,
		'token'     => $affiliate->get_token(),
	)
);
?>

<p>
	{content_html}
</p>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
