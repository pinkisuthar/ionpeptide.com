<?php
/**
 * The template for the waitlist joined notification sent to a customer on sign up (HTML)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/waitlist-joined.php.
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
	printf( esc_html__( 'You have been sent this email because your email address was registered on a waitlist for %1$s at %2$s. ', 'woocommerce-waitlist' ), '<a href="' . esc_url( $product_link ) . '">' . esc_html( $product_title ) . '</a>', esc_html( get_bloginfo( 'name' ) ) );
	?>
</p>
<p>
	<?php
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
	/* translators: %1$s: product link open, %2$s: product link close */
	printf( esc_html__( 'If you would like to remove your email address from the waitlist you can do so by clicking %1$shere%2$s.', 'woocommerce-waitlist' ), '<a href="' . esc_url( $product_link ) . '">', '</a>' );
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
