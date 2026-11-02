<?php
/**
 * The template for the waitlist in stock notification email (HTML)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/waitlist-mailout.php.
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
$triggered_manually = isset( $triggered_manually ) ? $triggered_manually : false;
$remove_link = isset( $remove_link ) ? $remove_link : '';
$product_id = isset( $product_id ) ? $product_id : '';

$email = sanitize_email( $email );
/**
 * Action hook to output email header content
 * 
 * @since 2.4.0
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email_class );?>

<p><?php echo esc_html_x( 'Hi There,', 'Email salutation', 'woocommerce-waitlist' ); ?></p>

<p>
	<?php
	/* translators: %1$s: product title, %2$s: site name */
	printf( esc_html__( '%1$s is now back in stock at %2$s. ', 'woocommerce-waitlist' ), esc_html( $product_title ), esc_html( get_bloginfo( 'name' ) ) );
	esc_html_e( 'You have been sent this email because your email address was registered on a waitlist for this product.', 'woocommerce-waitlist' );
	?>
</p>
<?php $product_link_name = strtok( $product_link, '?' ); ?>
<p>
	<?php
	/* translators: %1$s: product title, %2$s: product link around product name */
	printf( esc_html__( 'If you would like to purchase %1$s please visit the following link: %2$s', 'woocommerce-waitlist' ), esc_html( $product_title ), '<a href="' . esc_url( $product_link ) . '">' . esc_html( $product_link_name ) . '</a>' );
	?>
</p>

<?php if ( WooCommerce_Waitlist_Plugin::persistent_waitlists_are_disabled( $product_id ) && ! $triggered_manually ) {
	echo '<p>' . esc_html__( 'You have been removed from the waitlist for this product', 'woocommerce-waitlist' ) . '</p>';
}
if ( get_option( 'woocommerce_waitlist_archive_on' ) && ! email_exists( $email ) ) {
	$remove_link = add_query_arg( array(
		'wcwl_remove_user' => esc_attr( $email ),
		'product_id'       => absint( $product_id ),
		'key'              => $key,
	), get_permalink( $product_id ) );
	/* translators: %1$s: remove link */
	printf( esc_html__( 'To disassociate your email address with this product please click %1$shere%2$s.', 'woocommerce-waitlist' ), '<a href="' . esc_url( $remove_link ) . '">', '</a>' );
}
/**
 * Action hook to output email footer content
 * 
 * @since 2.4.0
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email_class ); ?>
