<?php
/**
 * New pending payment email template
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
 * @var $payments      YITH_WCAF_Payments_Collection
 * @var $currency      string
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";


echo esc_html_x( 'The following payments were correctly issued to a gateway:', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );
echo "\n";

echo "\n----------------------------------------\n\n";

$first = true;
if ( ! empty( $payments ) ) :
	foreach ( $payments as $payment ) :

		if ( ! $first ) :
			echo "\n==========\n\n";
		endif;

		echo esc_html_x( 'Payment:', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );
		echo ' ';
		echo '#' . esc_html( $payment->get_id() );
		echo "\n";

		$affiliate = $payment->get_affiliate();

		echo esc_html_x( 'Affiliate:', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );
		echo ' ';
		echo $affiliate ? esc_html( $affiliate->get_formatted_name() ) : esc_html_x( 'N/A', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );
		echo "\n";

		echo esc_html_x( 'Amount:', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );
		echo ' ';
		echo esc_html( wp_strip_all_tags( $payment->get_formatted_amount() ) );
		echo "\n";

		echo esc_html_x( 'Gateway:', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );
		echo ' ';
		echo esc_html( $payment->get_formatted_gateway() );
		echo "\n";

		$first = false;
	endforeach;
endif;

echo "\n----------------------------------------\n\n";

echo esc_html_x( 'If the selected gateway requires confirmation, the payment will switch to completed whenever the gateway reports payment as successful.', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );


echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
