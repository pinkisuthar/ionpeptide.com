<?php
/**
 * HTML required for each single options panel on the waitlist tab
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
// Ensure required parameters are defined (required to pass PHP checks when pushing new release)
$product_id = isset( $product_id ) ? $product_id : 0;

$options     = get_post_meta( $product_id, 'wcwl_options', true );
$stock_level = get_option( 'woocommerce_waitlist_minimum_stock' );
if ( ( ! $options || ! is_array( $options ) ) || ( ! isset( $options['enable_waitlist'] ) || ! isset( $options['enable_stock_trigger'] ) || ! isset( $options['minimum_stock'] ) ) ) {
	$options = array(
		/**
		 * Filter default option for enabling waitlist
		 * 
		 * @since 2.4.0
		 */
		'enable_waitlist'      => apply_filters( 'wcwl_default_option_enable_waitlist', 'true', $product_id ),
		'enable_stock_trigger' => 'false',
		'minimum_stock'        => absint( $stock_level ),
	);
	update_post_meta( $product_id, 'wcwl_options', $options );
}
$enable_waitlist       = 'true' == $options['enable_waitlist'] ? 'checked="checked"' : '';
$enable_stock_trigger  = 'true' == $options['enable_stock_trigger'] ? 'checked="checked"' : '';
$disable_trigger_input = 'false' == $options['enable_stock_trigger'] ? 'disabled' : '';
$disable_trigger_class = 'false' == $options['enable_stock_trigger'] ? 'wcwl_disabled' : '';
?>
<div class="options wcwl_tab_content" data-panel="options">
	<fieldset>
		<input type="checkbox" <?php esc_attr_e( $enable_waitlist ); ?> name="enable_waitlist"/>
		<label for="enable_waitlist">
			<?php
			esc_html_e( 'Enable users to join a waitlist for this product', 'woocommerce-waitlist' );
			?>
		</label>
	</fieldset>
	<fieldset>
		<input type="checkbox" <?php esc_attr_e( $enable_stock_trigger ); ?> name="enable_stock_trigger" />
		<label for="enable_stock_trigger">
			<?php
			esc_html_e( 'Check this box to override the default setting for the minimum stock required for this product before waitlist users are notified that it is back in stock', 'woocommerce-waitlist' );
			?>
		</label>
	</fieldset>
	<fieldset>
		<input type="number" data-default-stock="<?php esc_attr_e( $stock_level ); ?>" value="<?php esc_attr_e( $options['minimum_stock'] ); ?>" name="minimum_stock" <?php esc_attr_e( $disable_trigger_input ); ?> />
		<label for="minimum_stock" <?php esc_attr_e( $disable_trigger_class ); ?>>
			<?php
			esc_html_e( 'Minimum stock amount before users are notified that item is back in stock', 'woocommerce-waitlist' );
			?>
		</label>
	</fieldset>
	<button type="button" class="button primary" data-nonce="
		<?php
		esc_attr_e( wp_create_nonce( 'wcwl-update-nonce' ) );
		?>
		">
		<?php
		esc_html_e( 'Update Options', 'woocommerce-waitlist' );
		?>
	</button>
</div>
