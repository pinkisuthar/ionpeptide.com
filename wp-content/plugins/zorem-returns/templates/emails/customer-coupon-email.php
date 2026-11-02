<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables come from wc_get_template()/wc_mail() extract() calls.
/**
 * Admin return request
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/zorem-returns-exchanges/emails/admin-return-request.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 */

defined( 'ABSPATH' ) || exit;

$coupon = isset( $coupon_id ) ? new WC_Coupon( $coupon_id ) : '';
if ( $coupon ) {
	// Coupon details
	$coupon_code = $coupon->get_code();
	$amount      = $coupon->get_amount();
}
?>
<p><?php printf( esc_html__( 'We’re happy to let you know that your return has been approved and your refund has been issued as store credit for your convenience.', 'zorem-returns' ) ); ?></p>
<p><?php esc_html_e( 'Your Store Credit Coupon Code: ', 'zorem-returns' ); ?><b><?php esc_html_e( strtoupper( $coupon_code ), 'zorem-returns' ); ?></b></p>

<p>
<?php
printf(
	// translators: %s: The formatted credit amount.
	esc_html__( 'You can use this code during checkout to apply a credit of %s on your next purchase.', 'zorem-returns' ),
	wp_kses_post( wc_price( $amount ) )
);
?>
</p>

<p><b><?php esc_html_e( 'How to Use Your Store Credit:', 'zorem-returns' ); ?></b></p>
<ol>
	<li><?php esc_html_e( 'Browse and add your favorite products to the cart.', 'zorem-returns' ); ?></li>
	<li><?php esc_html_e( 'Add products to your cart.', 'zorem-returns' ); ?></li>
	<li><?php esc_html_e( 'At checkout, enter the coupon code', 'zorem-returns' ); ?><b><?php echo esc_html( strtoupper( $coupon_code ) . '.' ); ?></b></li>
	<li><?php esc_html_e( 'Enjoy your discount!', 'zorem-returns' ); ?></li>
</ol>
<p><b><?php esc_html_e( 'Terms and Conditions:', 'zorem-returns' ); ?></b></p>
<ul>
	<li><?php esc_html_e( 'Store credit is redeemable exclusively on our website.', 'zorem-returns' ); ?></li>
	<li><?php esc_html_e( 'This credit does not apply to shipping fees.', 'zorem-returns' ); ?></li>
</ul>
<p><?php esc_html_e( 'Ready to start shopping?', 'zorem-returns' ); ?></p>
