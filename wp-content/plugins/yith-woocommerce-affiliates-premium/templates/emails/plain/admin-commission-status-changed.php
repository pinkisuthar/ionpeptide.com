<?php
/**
 * Commission status changed email template plain
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

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// translators: 1. Commission id.
echo esc_html_x( 'A set of commissions has just changed status.', '[EMAILS] Commission status changed email', 'yith-woocommerce-affiliates' );
echo "\n";

if ( $confirmed ) {
	echo esc_html_x( 'Commissions are confirmed and ready to be paid.', '[EMAILS] Commission status changed email', 'yith-woocommerce-affiliates' );
	echo "\n";
}

echo "\n----------------------------------------\n\n";

YITH_WCAF_Emails::print_commissions_table(
	$commissions,
	true,
	array(
		'affiliate'     => $affiliate,
		'order'         => $order,
		'token'         => $affiliate->get_token(),
		'sent_to_admin' => true,
	)
);

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
