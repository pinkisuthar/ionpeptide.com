<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables come from wc_get_template()/wc_mail() extract() calls.
/**
 * Customer return rejected
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/zorem-returns-exchanges/emails/customer-return-rejected.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 */

defined( 'ABSPATH' ) || exit;
?>

<?php /* translators: %s: Customer billing full name */ ?>
<?php
$before = '<a class="link" href="' . esc_url( $order->get_view_order_url() ) . '">';
$after  = '</a>';
$return_data = zorem_returns_exchanges()->common->get_data_by_return_id($request_id);
$defualt_settings = zorem_returns_exchanges()->common->generate_defaults();
$store_address = zorem_returns_exchanges()->common->return_settings( 'return_store_address', array() );
$store_phone_number = isset($store_address['phone_number']) ? $store_address['phone_number'] : '';

$customer = new WC_Customer( $order->get_customer_id() );
$email_content = isset($email_settings['email_content']) ? $email_settings['email_content'] : $defualt_settings['customer_return_rejected']['email_content'];
$email_content = str_replace(array('{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{store_address}', '{phone_number}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username(), zorem_returns_exchanges()->common->generate_default_store_address(), $store_phone_number ), $email_content);
?>
<p><?php echo isset($email_content) ? wp_kses_post(html_entity_decode($email_content)) : ''; ?></p>

<?php
$return_data = zorem_returns_exchanges()->common->get_data_by_return_id($request_id);
if ( !empty($return_data->return_note) ) {
	?>
	<p><b><?php esc_html_e('Return Rejected Note:', 'zorem-returns'); ?></b></p>
	<p><?php echo isset($return_data->return_note) ? wp_kses_post(html_entity_decode($return_data->return_note)) : ''; ?></p>
	<?php
}
?>

<p><b><?php !empty($email_settings['return_items_title']) ? esc_html_e($email_settings['return_items_title']) : $defualt_settings['customer_return_rejected']['return_items_title']; ?></b></p>

<?php
/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_customer_return_email_order_details', $request_id, $order, $email );

$additional_content = isset($email_settings['additional_content']) ? $email_settings['additional_content'] : $defualt_settings['customer_return_rejected']['additional_content'];
$additional_content = str_replace(array('{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{store_address}', '{phone_number}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username(), zorem_returns_exchanges()->common->generate_default_store_address(), $store_phone_number ), $additional_content);
?>
<p><?php echo isset($additional_content) ? wp_kses_post(html_entity_decode($additional_content)) : ''; ?></p>
<?php
