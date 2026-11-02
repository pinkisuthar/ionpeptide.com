<?php
/**
 * HTML required for the waitlist panel on the product edit screen for variable products
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
} ?>

<div id="wcwl_waitlist_data" class="panel woocommerce_options_panel">
	<?php
	$children = Pie_WCWL_Custom_Tab::$product->get_children();
	foreach ( $children as $product_id ) {
		$variation = wc_get_product( $product_id );
		if ( $variation ) {
			?>
			<div class="wcwl_variation_tab" id="wcwl_variation_<?php esc_attr_e( $product_id ); ?>">
				<div class="wcwl_header_wrap">
					<h3>
						<?php
						// phpcs ignore due to function having sufficient escaping and being directly output
						echo Pie_WCWL_Custom_Tab::return_variation_tab_title( $variation ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</h3>
				</div>
				<div class="wcwl_body_wrap" data-product-id="<?php esc_attr_e( $product_id ); ?>">
					<?php
					/**
					 * Filters the path to the admin panel tabs
					 * 
					 * @since 2.4.0
					 */
					include apply_filters( 'wcwl_include_path_admin_panel_tabs', Pie_WCWL_Custom_Tab::$component_path . 'panel-tabs.php' );
					/**
					 * Filters the path to the admin panel waitlist tab
					 * 
					 * @since 2.4.0
					 */
					include apply_filters( 'wcwl_include_path_admin_panel_waitlist_tab', Pie_WCWL_Custom_Tab::$component_path . 'panel-waitlist.php' );
					/**
					 * Filters the path to the admin panel archive tab
					 * 
					 * @since 2.4.0
					 */
					include apply_filters( 'wcwl_include_path_admin_panel_archive_tab', Pie_WCWL_Custom_Tab::$component_path . 'panel-archive.php' );
					/**
					 * Filters the path to the admin panel options tab
					 * 
					 * @since 2.4.0
					 */
					include apply_filters( 'wcwl_include_path_admin_panel_options_tab', Pie_WCWL_Custom_Tab::$component_path . 'panel-options.php' );
					?>
				</div>
			</div>
		<?php
		}
	}
	?>
</div>
