<?php
/**
 * Cross-sells
 *
 * This template can be overridden by copying it to yourtheme/woo-discount-rules-pro/cross-sells.php.
 *
 * HOWEVER, on occasion Discount rules will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 */

defined( 'ABSPATH' ) || exit;

if ( $cross_sells ) : ?>

	<div class="cross-sells">
		<?php
		$wdrpro_heading = apply_filters( 'advanced_woo_discount_rules_cross_sells_products_heading', __( 'You may be interested in', 'woo-discount-rules-pro' ) );

		if ( $wdrpro_heading ) :
			?>
			<h2><?php echo esc_html( $wdrpro_heading ); ?></h2>
		<?php endif; ?>

		<?php woocommerce_product_loop_start(); ?>

			<?php foreach ( $cross_sells as $wdrpro_cross_sell ) : ?>

				<?php
					$wdrpro_post_object = get_post( $wdrpro_cross_sell->get_id() );

					setup_postdata( $GLOBALS['post'] =& $wdrpro_post_object ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, Squiz.PHP.DisallowMultipleAssignments.Found

					wc_get_template_part( 'content', 'product' );
				?>

			<?php endforeach; ?>

		<?php woocommerce_product_loop_end(); ?>

	</div>
	<?php
endif;

wp_reset_postdata();
