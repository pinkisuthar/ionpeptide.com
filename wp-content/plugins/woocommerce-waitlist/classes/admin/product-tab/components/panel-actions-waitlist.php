<?php
/**
 * Dropdown and button for various actions for waitlists
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
// Ensure required parameters are defined (required to pass PHP checks when pushing new release)
$product_id = isset( $product_id ) ? $product_id : 0;

$archive = get_option( 'woocommerce_waitlist_archive_on' );
?>
<div class="wcwl_actions">
	<select name="wcwl_action_<?php esc_attr_e( $product_id ); ?>" class="wcwl_action">
		<option disabled selected value="0"><?php esc_html_e( 'Actions', 'woocommerce-waitlist' ); ?></option>
		<option value="wcwl_remove_waitlist"><?php $archive ? esc_html_e( 'Move to archive', 'woocommerce-waitlist' ) : esc_html_e( 'Remove', 'woocommerce-waitlist' ); ?></option>
		<option value="wcwl_email_instock"><?php esc_html_e( 'Send instock email', 'woocommerce-waitlist' ); ?></option>
		<option value="wcwl_email_custom"><?php esc_html_e( 'Send custom email', 'woocommerce-waitlist' ); ?></option>
		<option value="wcwl_export"><?php esc_html_e( 'Export emails', 'woocommerce-waitlist' ); ?></option>
	</select>
	<button type="button" class="button wcwl_action" data-nonce="<?php esc_attr_e( wp_create_nonce( 'wcwl-action-nonce' ) ); ?>">
		<?php esc_html_e( 'Go', 'woocommerce-waitlist' ); ?>
	</button>
</div>
