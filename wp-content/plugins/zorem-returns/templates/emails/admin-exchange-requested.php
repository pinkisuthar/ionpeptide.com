<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables come from wc_get_template()/wc_mail() extract() calls.
/**
 * Admin exchange request
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/zorem-returns-exchanges/emails/admin-exchange-request.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 */

defined( 'ABSPATH' ) || exit;

$defualt_settings   = zorem_returns_exchanges()->exCommon->generate_defaults();
$store_address      = zorem_returns_exchanges()->common->return_settings( 'return_store_address', array() );
$store_phone_number = isset( $store_address['phone_number'] ) ? $store_address['phone_number'] : '';
$customer           = new WC_Customer( $order->get_customer_id() );

$email_content = isset( $email_settings['email_content'] ) ? $email_settings['email_content'] : $defualt_settings['admin_exchange_requested']['email_content'];
$email_content = str_replace( array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{store_address}', '{phone_number}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username(), zorem_returns_exchanges()->common->generate_default_store_address(), $store_phone_number ), $email_content );
?>
<p><?php echo isset( $email_content ) ? wp_kses_post( html_entity_decode( $email_content ) ) : ''; ?></p>
<ul>
	<?php
	/* translators: %s: Order ID. */
	echo wp_kses_post( sprintf( '<li><b>' . __( 'Order Number', 'zorem-returns' ) . ':</b><a class="link" href="' . esc_url( $order->get_edit_order_url() ) . '">%s</a></li>', $order->get_order_number() ) );
	/* translators: %s: Order ID. */
	echo wp_kses_post( '<li><b>' . __( 'Order Date', 'zorem-returns' ) . ':</b> <time datetime="%s">' . wc_format_datetime( $order->get_date_created() ) . '</time>', $order->get_date_created()->format( 'c' ) ) . '</li>';
	/* translators: %s: Order ID. */
	echo wp_kses_post( sprintf( '<li><b>' . __( 'Customer Name: ', 'zorem-returns' ) . '</b>%s</li>', $order->get_formatted_billing_full_name() ) );


	$order_id = $order->get_id();
	if ( 1 == $order_id ) {
		$no_other       = esc_html__( 'Already purchased the product', 'zorem-returns' );
		$other_reason   = esc_html__( 'Already purchased the product', 'zorem-returns' );
		$exchange_issue = esc_html__( 'Exchanges request details', 'zorem-returns' );
		$items[]        = esc_html__( 'Tshirt x 1', 'zorem-returns' );
	} else {
		$exchange_data   = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $request_id );
		$exchange_reason = unserialize( $exchange_data->exchange_reason );
		$no_other        = isset( $exchange_reason['no_other'] ) ? $exchange_reason['no_other'] : '';
		$other_reason    = isset( $exchange_reason['other_reason'] ) ? $exchange_reason['other_reason'] : '';
		$exchange_issue  = isset( $exchange_data->exchange_issue ) ? $exchange_data->exchange_issue : '';
		$items           = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$exchange_items = unserialize( $exchange_data->exchange_items );
			$product_name   = $item->get_name();
			$exchange_qty   = isset( $exchange_items[ $item_id ]['exchange_product_qty'] ) ? $exchange_items[ $item_id ]['exchange_product_qty'] : 0;
			if ( 0 < $exchange_qty ) {
				$items[] = $product_name . ' x ' . $exchange_qty;
			}
		}
	}
	echo '<li><b>' . esc_html__( 'Customer Email:', 'zorem-returns' ) . '</b> ' . esc_html( $order->get_billing_email() ) . '</li>';

	// "Exchanged Items: ..." bullet removed — the swap table below shows
	// both the original and the requested exchange in more detail.

	if ( 'other_reason' == $no_other ) {
		echo '<li><b>' . esc_html__( 'Exchange Reason', 'zorem-returns' ) . ':</b> ' . esc_html__( $other_reason, 'zorem-returns' ) . '</li>';
	} else {
		echo '<li><b>' . esc_html__( 'Exchange Reason', 'zorem-returns' ) . ':</b> ' . esc_html__( $no_other, 'zorem-returns' ) . '</li>';
	}
	echo '<li><b>' . esc_html__( 'Exchange Issue', 'zorem-returns' ) . ':</b> ' . esc_html__( $exchange_issue, 'zorem-returns' ) . '</li>';
	?>
</ul>
<?php
// Original vs Requested Exchange comparison table.
echo wp_kses_post( zorem_returns_exchanges()->exCommon->exchange_swap_table_html( $request_id, $order ) );
?>
<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>" class="button wc-action-button wc-action-button-exchange_approved_icon" >Approve</a> | <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>" class="button wc-action-button wc-action-button-exchange_rejected_icon" >Reject</a>
<?php
/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */

$additional_content = isset( $email_settings['additional_content'] ) ? $email_settings['additional_content'] : $defualt_settings['admin_exchange_requested']['additional_content'];
$additional_content = str_replace( array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{store_address}', '{phone_number}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username(), zorem_returns_exchanges()->common->generate_default_store_address(), $store_phone_number ), $additional_content );
?>
<p><?php echo isset( $additional_content ) ? wp_kses_post( html_entity_decode( $additional_content ) ) : ''; ?></p>
<?php
