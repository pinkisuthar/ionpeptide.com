<?php
/**
 * The template for the waitlist joined notification sent to a customer on sign up (Plain Text)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/waitlist-joined.php.
 *
 * HOWEVER, on occasion WooCommerce Waitlist will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @version 2.4.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Ensure required parameters are defined (required to pass PHP checks when pushing new release)
$product_id = isset( $product_id ) ? $product_id : '';
$product_title = isset( $product_title ) ? $product_title : '';
$email = isset( $email ) ? $email : '';
$key = isset( $key ) ? $key : '';
$product_link = isset( $product_link ) ? $product_link : '';

echo esc_html_x( 'Hi There,', 'Email salutation', 'woocommerce-waitlist' );
echo "\n\n";
/* translators: %1$s: product title, %2$s: site name */
printf( esc_html__( 'You have been sent this email because your email address was registered on a waitlist for %1$s at %2$s. ', 'woocommerce-waitlist' ), esc_html( $product_title ), esc_html( get_bloginfo( 'name' ) ) );
echo "\n\n";
/**
 * Filter to allow the product link to be modified
 * 
 * @since 2.4.0
 */
$product_link = apply_filters( 'wcwl_product_link_joined_email', add_query_arg( array(
	'wcwl_remove_user' => esc_attr( sanitize_email( $email ) ),
	'product_id'       => absint( $product_id ),
	'key'              => $key,
), $product_link ) );
/* translators: %s: product link */
printf( esc_html__( 'If you would like to remove your email address from the waitlist you can do so by clicking here: %s.', 'woocommerce-waitlist' ), esc_url( $product_link ) );
echo "\n\n";
/**
 * Filter email footer text
 * 
 * @since 2.4.0
 */
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
