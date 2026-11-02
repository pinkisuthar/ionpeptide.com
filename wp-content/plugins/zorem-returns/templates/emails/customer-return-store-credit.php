<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables come from wc_get_template()/wc_mail() extract() calls.
/**
 * Customer return store-credit email
 *
 * Sent on `return-completed` when refund method = store_credit.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/zorem-returns-exchanges/emails/customer-return-store-credit.php.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 */

defined( 'ABSPATH' ) || exit;

$defualt_settings = zorem_returns_exchanges()->common->generate_defaults();

$coupon_obj  = ( ! empty( $coupon_id ) ) ? new WC_Coupon( $coupon_id ) : false;
$coupon_code = $coupon_obj ? strtoupper( $coupon_obj->get_code() ) : '';
$coupon_amt  = $coupon_obj ? $coupon_obj->get_amount() : 0;

$customer = $order ? new WC_Customer( $order->get_customer_id() ) : null;

$replace_keys = array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{coupon_code}', '{coupon_amount}' );
$replace_vals = array(
	get_bloginfo( 'name' ),
	$order ? $order->get_order_number() : '',
	$order ? wc_format_datetime( $order->get_date_created() ) : '',
	$order ? $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() : '',
	$order ? $order->get_billing_first_name() : '',
	$order ? $order->get_billing_last_name() : '',
	$customer ? $customer->get_username() : '',
	$coupon_code,
	wp_strip_all_tags( wc_price( $coupon_amt ) ),
);

$email_content = isset( $email_settings['email_content'] ) ? $email_settings['email_content'] : $defualt_settings['customer_return_store_credit']['email_content'];
$email_content = str_replace( $replace_keys, $replace_vals, $email_content );
?>
<p><?php echo wp_kses_post( html_entity_decode( $email_content ) ); ?></p>

<?php if ( $coupon_obj ) : ?>
	<p><?php esc_html_e( 'Your Store Credit Coupon Code:', 'zorem-returns' ); ?> <b><?php echo esc_html( $coupon_code ); ?></b></p>
	<p>
	<?php
	printf(
		/* translators: %s: The formatted credit amount. */
		esc_html__( 'You can use this code during checkout to apply a credit of %s on your next purchase.', 'zorem-returns' ),
		wp_kses_post( wc_price( $coupon_amt ) )
	);
	?>
	</p>
<?php endif; ?>

<?php
$items_title = ! empty( $email_settings['return_items_title'] ) ? $email_settings['return_items_title'] : $defualt_settings['customer_return_store_credit']['return_items_title'];
?>
<p><b><?php echo esc_html( $items_title ); ?></b></p>
<ol>
	<li><?php esc_html_e( 'Browse and add your favourite products to the cart.', 'zorem-returns' ); ?></li>
	<li>
		<?php
		printf(
			/* translators: %s: Coupon code. */
			esc_html__( 'At checkout, enter the coupon code %s.', 'zorem-returns' ),
			'<b>' . esc_html( $coupon_code ) . '</b>'
		);
		?>
	</li>
	<li><?php esc_html_e( 'Enjoy your discount!', 'zorem-returns' ); ?></li>
</ol>

<?php
$additional_content = isset( $email_settings['additional_content'] ) ? $email_settings['additional_content'] : $defualt_settings['customer_return_store_credit']['additional_content'];
$additional_content = str_replace( $replace_keys, $replace_vals, $additional_content );
?>
<p><?php echo wp_kses_post( html_entity_decode( $additional_content ) ); ?></p>
