<?php
/**
 * New affiliate coupon email template
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 1.0.0
 */

/**
 * Template variables:
 *
 * @var $email_heading string
 * @var $email         WC_Email
 * @var $coupon        WC_Coupon
 * @var $affiliate     YITH_WCAF_Affiliate
 * @var $user          WP_User
 * @var $display_name  string
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	// translators: 1. Affiliate formatted name.
	echo esc_html( sprintf( _x( 'Hi %s,', '[EMAILS] New affiliate coupon', 'yith-woocommerce-affiliates' ), $display_name ) );
	?>
</p>

<p>
	{content_html}
</p>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
