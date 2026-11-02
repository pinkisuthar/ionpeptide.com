<?php
/**
 * The template for the waitlist double optin email (HTML)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/waitlist-optin.php.
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
$email = isset( $email ) ? $email : '';
$key = isset( $key ) ? $key : '';
$product_id = isset( $product_id ) ? $product_id : '';
$lang = isset( $lang ) ? $lang : '';
$products = isset( $products ) ? $products : array();

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
	/* translators: %1$s: product title, %2$s: site name */
	printf( esc_html__( 'Please click the link below to confirm your email address and be added to the waitlist for %1$s at %2$s.', 'woocommerce-waitlist' ), esc_html( $product_title ), esc_html( get_bloginfo( 'name' ) ) );
	?>
</p>
<p>
	<?php
	$confirm_link = add_query_arg( array(
		'wcwl_user_optin' => urlencode( esc_attr( sanitize_email( $email ) ) ),
		'product_id'      => absint( $product_id ),
		'products'        => $products,
		'key'             => $key,
		'lang'            => $lang,
	), $product_link );
	echo '<a href="' . esc_url( $confirm_link ) . '">' . esc_html( $product_link ) . '</a>';
	?>
<p>
	<?php esc_html_e( 'If you did not make this request please ignore this email.', 'woocommerce-waitlist' ); ?>
</p>
<?php
/**
 * Action hook to output email footer content
 * 
 * @since 2.4.0
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email_class );
