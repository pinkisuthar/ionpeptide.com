<?php
/**
 * The template for the waitlist left notification sent to a customer when removed from a waitlist (HTML)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/waitlist-left.php.
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
$product_title = isset( $product_title ) ? $product_title : '';
$email_heading = isset( $email_heading ) ? $email_heading : '';
$email_class = isset( $email_class ) ? $email_class : '';
$product_link = isset( $product_link ) ? $product_link : '';

/**
 * Action hook to output email header content
 * 
 * @since 2.4.0
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email_class ); ?>

<p><?php echo esc_html_x( 'Hi There,', 'Email salutation', 'woocommerce-waitlist' ); ?></p>

<p>
	<?php
	/* translators: %1$s: product link around product title, %2$s: site name */
	printf( esc_html__( 'You have been sent this email because your email address was removed from a waitlist for %1$s at %2$s. ', 'woocommerce-waitlist' ), '<a href="' . esc_url( $product_link ) . '">' . esc_html( $product_title ) . '</a>', esc_html( get_bloginfo( 'name' ) ) );
	?>
</p>
<p>
	<?php
	/* translators: %1$s: product link */
	printf( esc_html__( 'If this is an error you can add yourself back to the waitlist %1$shere%2$s.', 'woocommerce-waitlist' ), '<a href="' . esc_url( $product_link ) . '">', '</a>' );
	?>
</p>
<?php
/**
 * Action hook to output email footer content
 * 
 * @since 2.4.0
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email_class ); ?>
