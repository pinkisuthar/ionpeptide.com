<?php
/**
 * HTML required for the waitlist panel on the event edit screen
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
$product_id = Pie_WCWL_Custom_Tab::$product->get_id(); ?>
<div class="accordion-header woocommerce-waitlist">
	<?php esc_html_e( 'Waitlist', 'woocommerce-waitlist' ); ?>
</div>
<section class="accordion-content">
	<h4 class="accordion-label"><?php esc_html_e( 'Waitlist Information:', 'woocommerce-waitlist' ); ?></h4>
	<div id="wcwl_waitlist_data" class="panel woocommerce_options_panel">
		<div class="wcwl_body_wrap" data-product-id="<?php esc_attr_e( $product_id ); ?>">
			<?php
			/**
			 * Filters the path to the admin panel tabs
			 * 
			 * @since 2.4.0
			 */
			require apply_filters( 'wcwl_include_path_admin_panel_tabs', Pie_WCWL_Custom_Tab::$component_path . 'panel-tabs.php' );
			/**
			 * Filters the path to the admin panel waitlist tab
			 * 
			 * @since 2.4.0
			 */
			require apply_filters( 'wcwl_include_path_admin_panel_waitlist_tab', Pie_WCWL_Custom_Tab::$component_path . 'panel-waitlist.php' );
			/**
			 * Filters the path to the admin panel archive tab
			 * 
			 * @since 2.4.0
			 */
			require apply_filters( 'wcwl_include_path_admin_panel_archive_tab', Pie_WCWL_Custom_Tab::$component_path . 'panel-archive.php' );
			/**
			 * Filters the path to the admin panel options tab
			 * 
			 * @since 2.4.0
			 */
			require apply_filters( 'wcwl_include_path_admin_panel_options_tab', Pie_WCWL_Custom_Tab::$component_path . 'panel-options.php' );
			?>
		</div>
	</div>
</section>
