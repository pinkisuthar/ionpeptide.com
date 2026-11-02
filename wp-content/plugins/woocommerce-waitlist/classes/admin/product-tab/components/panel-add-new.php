<?php
/**
 * Elements to add a new user on the waitlist tab
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
// Ensure required parameters are defined (required to pass PHP checks when pushing new release)
$product_id = isset( $product_id ) ? $product_id : 0;
?>
<div class="wcwl_add_user_wrap">
	<button type="button" class="button wcwl_add">
		<?php esc_html_e( 'Add new user', 'woocommerce-waitlist' ); ?>
	</button>
	<div class="wcwl_add_user_content">
		<input type="email" placeholder="<?php esc_attr_e( 'Email Address', 'woocommerce-waitlist' ); ?>" class="wcwl_email" name="wcwl_email_<?php esc_attr_e( $product_id ); ?>"/>
		<button type="button" class="button wcwl_email_add_user" data-nonce="<?php esc_attr_e( wp_create_nonce( 'wcwl-add-user-nonce' ) ); ?>">
			<?php esc_html_e( 'Add', 'woocommerce-waitlist' ); ?>
		</button>
		<button type="button" class="button wcwl_back">
			X
		</button>
			<span class="wcwl_new_account_text">
				<?php esc_html_e( 'New users will be registered and emailed a "New Account" email', 'woocommerce-waitlist' ); ?>
			</span>
	</div>
</div>
