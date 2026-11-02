<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables come from wc_get_template()/wc_mail() extract() calls.
/**
 * Customer exchange approved
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/zorem-returns-exchanges/emails/customer-exchange-approved.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 */

defined('ABSPATH') || exit;

/*
 * @hooked WC_Emails::email_header() Output the email header
 */

$before = '<a class="link" href="' . esc_url($order->get_view_order_url()) . '">';
$after = '</a>';
$defualt_settings = zorem_returns_exchanges()->exCommon->generate_defaults();
$store_address = zorem_returns_exchanges()->common->return_settings( 'return_store_address', array() );
$store_phone_number = isset($store_address['phone_number']) ? $store_address['phone_number'] : '';

$customer = new WC_Customer( $order->get_customer_id() );
$email_content = isset($email_settings['email_content']) ? $email_settings['email_content'] : $defualt_settings['customer_exchange_approved']['email_content'];
$email_content = str_replace(array('{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{store_address}', '{phone_number}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username(), zorem_returns_exchanges()->common->generate_default_store_address(), $store_phone_number ), $email_content);
?>
<p><?php echo isset($email_content) ? wp_kses_post(html_entity_decode($email_content)) : ''; ?></p>

<?php
$exchange_data = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id($request_id);
$is_keep_the_item_email = ( isset( $exchange_data->keep_the_item ) && 'yes' === $exchange_data->keep_the_item );
if ( !empty($exchange_data->exchange_note) ) {
	?>
	<p><b><?php esc_html_e('Exchange Approved Note:', 'zorem-returns'); ?></b></p>
	<p><?php echo isset($exchange_data->exchange_note) ? wp_kses_post(html_entity_decode($exchange_data->exchange_note)) : ''; ?></p>
	<?php
}
?>

<p><b><?php !empty($email_settings['exchange_items_title']) ? esc_html_e($email_settings['exchange_items_title']) : $defualt_settings['customer_exchange_approved']['exchange_items_title']; ?></b></p>

<?php
/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action('woocommerce_customer_exchange_email_order_details', $request_id, $order, $email);
if ( $is_keep_the_item_email ) { 
	?>
	<div style="margin: 20px 0; padding: 15px; background-color: #f0f8ff; border: 1px solid #b3d9ff; border-radius: 5px;">
		<p style="margin: 0 0 8px; font-weight: bold; color: #0056b3;"><?php esc_html_e( 'Keep Your Item', 'zorem-returns' ); ?></p>
		<p style="margin: 0; color: #333;"><?php esc_html_e( 'No return shipping required. You may keep the item. Your replacement will be dispatched automatically.', 'zorem-returns' ); ?></p>
	</div>
<?php } else { ?>
	<?php
	$settings_menu_value = zorem_returns_exchanges()->common->return_settings( 'exchange_guideline_content' , $defualt_settings['customer_exchange_approved']['exchange_guideline_content'] );
	$exchange_guideline_content = str_replace(array('{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{store_address}', '{phone_number}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username(), zorem_returns_exchanges()->common->generate_default_store_address(), zorem_returns_exchanges()->common->return_settings( 'return_store_number' , '' ) ), $settings_menu_value);
	?>
	<p><?php echo isset($exchange_guideline_content) ? wp_kses_post(html_entity_decode($exchange_guideline_content)) : ''; ?></p>

	<?php
	if ( !empty($exchange_data->exchange_label_pdf) && file_exists($exchange_data->exchange_label_pdf) ) {
		?>
		<div style="margin: 20px 0; padding: 15px; background-color: #f0f8ff; border: 1px solid #b3d9ff; border-radius: 5px;">
			<p style="margin: 0 0 8px; font-weight: bold; color: #0056b3;"><?php esc_html_e('Return Shipping Label', 'zorem-returns'); ?></p>
			<p style="margin: 0; color: #333;"><?php esc_html_e('A return shipping label (PDF) is attached to this email. Please download, print, and paste it on your return package.', 'zorem-returns'); ?></p>
		</div>
		<?php
	}
}

$additional_content = isset($email_settings['additional_content']) ? $email_settings['additional_content'] : $defualt_settings['customer_exchange_approved']['additional_content'];
$additional_content = str_replace(array('{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{store_address}', '{phone_number}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username(), zorem_returns_exchanges()->common->generate_default_store_address(), $store_phone_number ), $additional_content);
?>
<p><?php echo isset($additional_content) ? wp_kses_post(html_entity_decode($additional_content)) : ''; ?></p>
<?php
