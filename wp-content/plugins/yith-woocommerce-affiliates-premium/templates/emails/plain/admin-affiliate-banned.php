<?php
/**
 * Affiliate banned template plain
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 1.0.0
 */

/**
 * Template variables:
 *
 * @var $email_heading          string
 * @var $email                  WC_Email
 * @var $affiliate              YITH_WCAF_Affiliate
 * @var $affiliate_referral_url string
 * @var $old_status             string
 * @var $new_status             string
 * @var $additional_message     string
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly


$user = $affiliate->get_user();

if ( ! $user ) {
	return;
}

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// translators: 1. Affiliate name.
echo esc_html( sprintf( _x( 'Affiliate %s was just banned.', '[EMAILS] Affiliate banned email', 'yith-woocommerce-affiliates' ), $affiliate->get_formatted_name() ) );
echo "\n";

echo "\n----------------------------------------\n\n";

echo esc_html_x( 'Username:', '[EMAILS] Affiliate banned email', 'yith-woocommerce-affiliates' );
echo ' ';
echo esc_html( $user->user_login );
echo "\n";

echo esc_html_x( 'User email:', '[EMAILS] Affiliate banned email', 'yith-woocommerce-affiliates' );
echo ' ';
echo esc_html( $user->user_email );
echo "\n";

echo esc_html_x( 'Affiliate token:', '[EMAILS] Affiliate banned email', 'yith-woocommerce-affiliates' );
echo ' ';
echo esc_html( $affiliate->get_token() );
echo '(' . esc_url( $affiliate->get_referral_url() ) . ')';
echo "\n";

if ( $additional_message ) {
	echo "\n----------------------------------------\n\n";

	echo esc_html_x( 'Ban message:', '[EMAILS] Affiliate banned email', 'yith-woocommerce-affiliates' );
	echo ' ';
	echo esc_html( $additional_message );
	echo "\n";
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

