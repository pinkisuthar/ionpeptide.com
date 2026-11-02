<?php
/**
 * HTML required for each single waitlist on the waitlist tab
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
// Ensure required parameters are defined (required to pass PHP checks when pushing new release)
$product_id = isset( $product_id ) ? $product_id : 0;

?>
<div class="waitlist wcwl_tab_content current" data-panel="waitlist">
	<?php
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		?>
		<div class="wcwl_no_variation">
			<p class="wcwl_no_variation_text">
				<?php
				/**
				 * Filters the message displayed when no variation is found
				 * 
				 * @since 2.4.0
				 */
				esc_html_e( apply_filters( 'wcwl_no_variation_found_message', __( 'Variation not found.', 'woocommerce-waitlist' ) ) );
				?>
			</p>
		</div>
	<?php
	} else {
		/**
		 * Filters the path to the admin panel add new waitlist component
		 * 
		 * @since 2.4.0
		 */
		include apply_filters( 'wcwl_include_path_admin_panel_waitlist_add_new', Pie_WCWL_Custom_Tab::$component_path . 'panel-add-new.php' );
		/**
		 * Filters the path to the admin panel waitlist actions component
		 * 
		 * @since 2.4.0
		 */
		include apply_filters( 'wcwl_include_path_admin_panel_waitlist_actions', Pie_WCWL_Custom_Tab::$component_path . 'panel-actions-waitlist.php' );
		?>
		<div class="wcwl_no_users">
			<p class="wcwl_no_users_text">
				<?php
				/**
				 * Filters the message displayed when no users are found
				 * 
				 * @since 2.4.0
				 */
				esc_html_e( apply_filters( 'wcwl_empty_waitlist_introduction', __( 'There are no users on the waiting list for this product.', 'woocommerce-waitlist' ) ) );
				?>
			</p>
		</div>

		<table class="widefat wcwl_waitlist_table">
			<tr>
				<th><input name="wcwl_select_all" type="checkbox"/></th>
				<th>
					<?php
					esc_html_e( 'User', 'woocommerce-waitlist' );
					?>
				</th>
				<th>
					<?php
					esc_html_e( 'Added', 'woocommerce-waitlist' );
					?>
				</th>
			</tr>
			<?php
			$waitlist  = new Pie_WCWL_Waitlist( $product );
			$users     = is_array( $waitlist->waitlist ) ? $waitlist->waitlist : array();
			$wl_errors = get_post_meta( $product_id, 'wcwl_mailout_errors', true );
			foreach ( $users as $user => $date ) {
				if ( $user ) {
					/**
					 * Filters the path to the admin panel table row
					 * 
					 * @since 2.4.0
					 */
					include apply_filters( 'wcwl_include_path_admin_panel_table_row', Pie_WCWL_Custom_Tab::$component_path . 'panel-table-row.php' );
				}
			}
			?>
		</table>
	<?php
	}
	?>
</div>
