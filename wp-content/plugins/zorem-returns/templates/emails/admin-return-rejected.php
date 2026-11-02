<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables come from wc_get_template()/wc_mail() extract() calls.
/**
 * Admin return rejected
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/zorem-returns-exchanges/emails/admin-return-rejected.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 */

defined( 'ABSPATH' ) || exit;

$defualt_settings   = zorem_returns_exchanges()->common->generate_defaults();
$store_address      = zorem_returns_exchanges()->common->return_settings( 'return_store_address', array() );
$store_phone_number = isset( $store_address['phone_number'] ) ? $store_address['phone_number'] : '';
$customer           = new WC_Customer( $order->get_customer_id() );

$email_content = isset( $email_settings['email_content'] ) ? $email_settings['email_content'] : $defualt_settings['admin_return_rejected']['email_content'];
$email_content = str_replace( array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{store_address}', '{phone_number}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username(), zorem_returns_exchanges()->common->generate_default_store_address(), $store_phone_number ), $email_content );
?>
<p><?php echo isset( $email_content ) ? wp_kses_post( html_entity_decode( $email_content ) ) : ''; ?></p>
<ul>
	<?php
	echo wp_kses_post( sprintf( '<li><b>' . __( 'Order Number', 'zorem-returns' ) . ':</b><a class="link" href="' . esc_url( $order->get_edit_order_url() ) . '">%s</a></li>', $order->get_order_number() ) );
	echo wp_kses_post( '<li><b>' . __( 'Order Date', 'zorem-returns' ) . ':</b> <time datetime="%s">' . wc_format_datetime( $order->get_date_created() ) . '</time>', $order->get_date_created()->format( 'c' ) ) . '</li>';
	echo wp_kses_post( sprintf( '<li><b>' . __( 'Customer Name: ', 'zorem-returns' ) . '</b>%s</li>', $order->get_formatted_billing_full_name() ) );

	$order_id = $order->get_id();
	if ( 1 == $order_id ) {
		$no_other     = esc_html__( 'Already purchased the product', 'zorem-returns' );
		$other_reason = esc_html__( 'Already purchased the product', 'zorem-returns' );
		$return_issue = esc_html__( 'Return Request Details', 'zorem-returns' );
		$items[]      = esc_html__( 'Tshirt x 1', 'zorem-returns' );
		$return_note  = '';
	} else {
		$return_data   = zorem_returns_exchanges()->common->get_data_by_return_id( $request_id );
		$return_reason = unserialize( $return_data->return_reason );
		$no_other      = isset( $return_reason['no_other'] ) ? $return_reason['no_other'] : '';
		$other_reason  = isset( $return_reason['other_reason'] ) ? $return_reason['other_reason'] : '';
		$return_issue  = isset( $return_data->return_issue ) ? $return_data->return_issue : '';
		$return_note   = isset( $return_data->return_note ) ? $return_data->return_note : '';
		$items         = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$return_items = unserialize( $return_data->return_items );
			$product_name = $item->get_name();
			$return_qty   = isset( $return_items[ $item_id ]['return_product_qty'] ) ? $return_items[ $item_id ]['return_product_qty'] : 0;
			if ( 0 < $return_qty ) {
				$items[] = $product_name . ' x ' . $return_qty;
			}
		}
	}
	echo '<li><b>' . esc_html__( 'Customer Email:', 'zorem-returns' ) . '</b> ' . esc_html( $order->get_billing_email() ) . '</li>';

	$return_items_title = ! empty( $email_settings['return_items_title'] ) ? esc_html( $email_settings['return_items_title'] ) : $defualt_settings['admin_return_rejected']['return_items_title'];

	echo wp_kses_post( sprintf( __( '<li><b>%s : </b>', 'woocommerce' ), $return_items_title ) ) . esc_html( isset( $items ) ? implode( ',', $items ) : '' ) . '</li>';

	if ( 'other_reason' == $no_other ) {
		echo '<li><b>' . esc_html__( 'Return Reason', 'zorem-returns' ) . ':</b> ' . esc_html__( $other_reason, 'zorem-returns' ) . '</li>';
	} else {
		echo '<li><b>' . esc_html__( 'Return Reason', 'zorem-returns' ) . ':</b> ' . esc_html__( $no_other, 'zorem-returns' ) . '</li>';
	}
	echo '<li><b>' . esc_html__( 'Customer’s Reason for Return:', 'zorem-returns' ) . '</b> ' . esc_html__( $return_issue, 'zorem-returns' ) . '</li>';

	if ( ! empty( $return_note ) ) {
		echo '<li><b>' . esc_html__( 'Rejection Note:', 'zorem-returns' ) . '</b> ' . esc_html( stripslashes( $return_note ) ) . '</li>';
	}
	?>
</ul>
<?php
$additional_content = isset( $email_settings['additional_content'] ) ? $email_settings['additional_content'] : $defualt_settings['admin_return_rejected']['additional_content'];
$additional_content = str_replace( array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{store_address}', '{phone_number}' ), array( get_bloginfo( 'name' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ), $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_last_name(), $customer->get_username(), zorem_returns_exchanges()->common->generate_default_store_address(), $store_phone_number ), $additional_content );
?>
<p><?php echo isset( $additional_content ) ? wp_kses_post( html_entity_decode( $additional_content ) ) : ''; ?></p>
