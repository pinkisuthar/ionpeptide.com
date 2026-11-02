<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables come from wc_get_template()/wc_mail() extract() calls.
/**
 * Customer exchange store-credit email
 *
 * Sent on `exchange-completed` for cross-product exchanges where the
 * replacement item costs less than the original (store-credit owed).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/zorem-returns-exchanges/emails/customer-exchange-store-credit.php.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 */

defined( 'ABSPATH' ) || exit;

$defualt_settings = zorem_returns_exchanges()->exCommon->generate_defaults();

$coupon_code = isset( $coupon_code ) ? strtoupper( $coupon_code ) : '';
$coupon_amt  = isset( $coupon_amount ) ? floatval( $coupon_amount ) : 0;

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

$email_content = isset( $email_settings['email_content'] ) ? $email_settings['email_content'] : $defualt_settings['customer_exchange_store_credit']['email_content'];
$email_content = str_replace( $replace_keys, $replace_vals, $email_content );
?>
<p><?php echo wp_kses_post( html_entity_decode( $email_content ) ); ?></p>

<?php if ( $coupon_code ) : ?>
	<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;margin:20px 0;">
		<tr>
			<th style="text-align:left;border:1px solid #e0e0e0;padding:8px;"><?php esc_html_e( 'Coupon Code', 'zorem-returns' ); ?></th>
			<td style="border:1px solid #e0e0e0;padding:8px;"><strong style="font-size:16px;letter-spacing:1px;"><?php echo esc_html( $coupon_code ); ?></strong></td>
		</tr>
		<tr>
			<th style="text-align:left;border:1px solid #e0e0e0;padding:8px;"><?php esc_html_e( 'Credit Amount', 'zorem-returns' ); ?></th>
			<td style="border:1px solid #e0e0e0;padding:8px;"><strong><?php echo wp_kses_post( wc_price( $coupon_amt ) ); ?></strong></td>
		</tr>
	</table>
<?php endif; ?>

<?php
$items_title = ! empty( $email_settings['exchange_items_title'] ) ? $email_settings['exchange_items_title'] : $defualt_settings['customer_exchange_store_credit']['exchange_items_title'];
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
$additional_content = isset( $email_settings['additional_content'] ) ? $email_settings['additional_content'] : $defualt_settings['customer_exchange_store_credit']['additional_content'];
$additional_content = str_replace( $replace_keys, $replace_vals, $additional_content );
?>
<p><?php echo wp_kses_post( html_entity_decode( $additional_content ) ); ?></p>
