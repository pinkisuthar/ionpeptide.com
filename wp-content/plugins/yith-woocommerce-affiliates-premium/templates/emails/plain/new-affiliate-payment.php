<?php
/**
 * New affiliate payment template plain
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
 * @var $affiliate        YITH_WCAF_Affiliate
 * @var $payment          YITH_WCAF_Payment
 * @var $commissions      YITH_WCAF_Commissions_Collection
 * @var $display_name     string
 * @var $currency         string
 * @var $user             WP_User
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// translators: 1. Affiliate formatted name.
echo esc_html( sprintf( _x( 'Hi %s,', '[EMAILS] New Affiliate email', 'yith-woocommerce-affiliates' ), $display_name ) );
echo "\n\n";

// translators: 1. Payment id. 2. Payment gateway.
echo esc_html( sprintf( _x( 'Payment #%1$s was issued correctly to your account via %2$s.', '[EMAILS] New affiliate payment', 'yith-woocommerce-affiliates' ), $payment->get_id(), $payment->get_formatted_gateway() ) );

echo "\n----------------------------------------\n\n";

echo esc_html_x( 'Amount:', '[EMAILS] New Affiliate email', 'yith-woocommerce-affiliates' );
echo ' ';
echo esc_html(
	wp_strip_all_tags(
		$payment->get_formatted_amount(
			'view',
			array(
				'currency' => $currency,
			)
		)
	)
);
echo "\n";

echo "\n----------------------------------------\n\n";

YITH_WCAF_Emails::print_commissions_table(
	$commissions,
	true,
	array(
		'affiliate' => $affiliate,
		'token'     => $affiliate->get_token(),
	)
);

echo "\n----------------------------------------\n\n";

echo "{content_text}\n\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

