<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables come from wc_get_template()/wc_mail() extract() calls.
/**
 * Shared body for all 10 warranty emails.
 *
 * Each warranty email template (customer-warranty-*.php, admin-warranty-*.php) sets
 * `$email_key` and includes this file. We keep the per-status difference in
 * generate_email_defaults() so the templates stay one-liners. This way the admin
 * customizes copy via the Email Customizer without ever needing to edit a PHP file.
 *
 * Vars expected: $email_key, $claim, $order, $email_settings
 * In customizer preview mode $claim is null — we render placeholder text instead
 * so the admin sees a real-looking preview while editing.
 */

defined( 'ABSPATH' ) || exit;

$warranty       = zorem_returns_exchanges()->warranty;
$defaults       = $warranty->generate_email_defaults();
$default        = isset( $defaults[ $email_key ] ) ? $defaults[ $email_key ] : array();
$is_preview     = empty( $claim );
$is_admin_email = ( 0 === strpos( $email_key, 'admin_' ) );

$customer           = $order ? new WC_Customer( $order->get_customer_id() ) : null;
$store_address      = zorem_returns_exchanges()->common->return_settings( 'return_store_address', array() );
$store_phone_number = isset( $store_address['phone_number'] ) ? $store_address['phone_number'] : '';

$claim_id_disp     = $is_preview ? 1001 : (int) $claim->id;
$claim_status_disp = $is_preview ? esc_html__( 'Submitted', 'zorem-returns' ) : $warranty->get_status_label( $claim->claim_status );
$claim_reason      = $is_preview ? esc_html__( 'Manufacturing defect', 'zorem-returns' ) : (string) $claim->claim_reason;
$claim_description = $is_preview ? esc_html__( 'The product stopped working two months after delivery. Please advise on next steps.', 'zorem-returns' ) : (string) $claim->claim_description;
$resolution_label  = '';
if ( ! $is_preview && ! empty( $claim->resolution_type ) ) {
	$rt = $warranty->get_resolution_types();
	$resolution_label = isset( $rt[ $claim->resolution_type ] ) ? $rt[ $claim->resolution_type ] : (string) $claim->resolution_type;
}
$resolution_note   = $is_preview ? '' : (string) $claim->resolution_note;
$product_name      = '';
if ( $is_preview ) {
	$product_name = esc_html__( 'Sample Product', 'zorem-returns' );
} elseif ( $claim->product_id ) {
	$p = wc_get_product( $claim->product_id );
	$product_name = $p ? $p->get_name() : '';
}

$replace_keys = array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{store_address}', '{phone_number}', '{claim_id}', '{claim_status}' );
$replace_vals = array(
	get_bloginfo( 'name' ),
	$order ? $order->get_order_number() : '0',
	$order ? wc_format_datetime( $order->get_date_created() ) : '',
	$order ? $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() : '',
	$order ? $order->get_billing_first_name() : '',
	$order ? $order->get_billing_last_name() : '',
	$customer ? $customer->get_username() : '',
	zorem_returns_exchanges()->common->generate_default_store_address(),
	$store_phone_number,
	$claim_id_disp,
	$claim_status_disp,
);

$email_content = isset( $email_settings['email_content'] ) ? $email_settings['email_content'] : $default['email_content'];
$email_content = str_replace( $replace_keys, $replace_vals, $email_content );
?>
<p><?php echo wp_kses_post( html_entity_decode( $email_content ) ); ?></p>

<p><b><?php echo esc_html( ! empty( $email_settings['claim_items_title'] ) ? $email_settings['claim_items_title'] : $default['claim_items_title'] ); ?></b></p>
<ul>
	<li><b><?php esc_html_e( 'Claim ID', 'zorem-returns' ); ?>:</b> #<?php echo esc_html( $claim_id_disp ); ?></li>
	<li><b><?php esc_html_e( 'Order Number', 'zorem-returns' ); ?>:</b>
		<?php if ( $is_admin_email && $order ) { ?>
			<a class="link" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
		<?php } else { ?>
			#<?php echo esc_html( $order ? $order->get_order_number() : '0' ); ?>
		<?php } ?>
	</li>
	<li><b><?php esc_html_e( 'Status', 'zorem-returns' ); ?>:</b> <?php echo esc_html( $claim_status_disp ); ?></li>
	<?php if ( $product_name ) { ?>
		<li><b><?php esc_html_e( 'Product', 'zorem-returns' ); ?>:</b> <?php echo esc_html( $product_name ); ?></li>
	<?php } ?>
	<?php if ( $claim_reason ) { ?>
		<li><b><?php esc_html_e( 'Reason', 'zorem-returns' ); ?>:</b> <?php echo esc_html( $claim_reason ); ?></li>
	<?php } ?>
	<?php if ( $claim_description ) { ?>
		<li><b><?php esc_html_e( 'Customer Description', 'zorem-returns' ); ?>:</b> <?php echo esc_html( $claim_description ); ?></li>
	<?php } ?>
	<?php if ( $resolution_label ) { ?>
		<li><b><?php esc_html_e( 'Resolution', 'zorem-returns' ); ?>:</b> <?php echo esc_html( $resolution_label ); ?></li>
	<?php } ?>
	<?php if ( $resolution_note ) { ?>
		<li><b><?php esc_html_e( 'Resolution Note', 'zorem-returns' ); ?>:</b> <?php echo esc_html( $resolution_note ); ?></li>
	<?php } ?>
</ul>

<?php if ( $is_admin_email && ! $is_preview ) { ?>
	<p>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=zorem-returns-exchanges&tab=warranty-claims' ) ); ?>">
			<?php esc_html_e( 'Open Warranty Claims Dashboard', 'zorem-returns' ); ?>
		</a>
	</p>
<?php } ?>

<?php
$additional_content = isset( $email_settings['additional_content'] ) ? $email_settings['additional_content'] : $default['additional_content'];
$additional_content = str_replace( $replace_keys, $replace_vals, $additional_content );
?>
<p><?php echo wp_kses_post( html_entity_decode( $additional_content ) ); ?></p>
