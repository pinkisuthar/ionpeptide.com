<?php
/**
 * HTML required for each single archive on the waitlist tab
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
// Ensure required parameters are defined (required to pass PHP checks when pushing new release)
$product_id = isset( $product_id ) ? $product_id : 0;
?>
<div class="archive wcwl_tab_content" data-panel="archive">
	<div class="wcwl_add_user_wrap">
	</div>
	<?php
	/**
	 * Filters the path to the admin panel archive actions component
	 * 
	 * @since 2.4.0
	 */
	include apply_filters( 'wcwl_include_path_admin_panel_archive_actions', Pie_WCWL_Custom_Tab::$component_path . 'panel-actions-archive.php' );
	?>
	<p class="wcwl_no_users_text">
		<?php
		esc_html_e( 'There are no saved users for this product.', 'woocommerce-waitlist' );
		?>
	</p>
	<table class="widefat wcwl_waitlist_table">
		<tr>
			<th><input name="wcwl_select_all" type="checkbox"/></th>
			<th><?php esc_html_e( 'User', 'woocommerce-waitlist' ); ?></th>
			<th><?php esc_html_e( 'Removed', 'woocommerce-waitlist' ); ?></th>
		</tr>
		<?php
		$archives = Pie_WCWL_Custom_Tab::retrieve_and_sort_archives( $product_id );
		foreach ( $archives as $date => $users ) {
			?>
			<?php
			foreach ( $users as $user ) {
				/**
				 * Filters the path to the admin panel table row component
				 * 
				 * @since 2.4.0
				 */
				include apply_filters( 'wcwl_include_path_admin_panel_table_row', Pie_WCWL_Custom_Tab::$component_path . 'panel-table-row.php' );
			}
		}
		?>
	</table>
</div>
