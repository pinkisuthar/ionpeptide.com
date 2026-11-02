<?php
/**
 * Variable product add to cart
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/add-to-cart/variable.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 6.1.0
 */

defined( 'ABSPATH' ) || exit;

global $product;


$attribute_keys        = array_keys( $attributes );
$variations_json       = wp_json_encode( $available_variations );
$variations_attr       = function_exists( 'wc_esc_json' ) ? wc_esc_json( $variations_json ) : _wp_specialchars( $variations_json, ENT_QUOTES, 'UTF-8', true );
$settings              = \FKCart\Includes\Data::get_settings();
$reset_to_default_text = isset( $settings['reset_to_variations'] ) ? $settings['reset_to_variations'] : apply_filters( 'fkcart_reset_to_variations', __( 'Clear', 'woocommerce' ) );
do_action( 'woocommerce_before_add_to_cart_form' ); ?>
    <form class="variations_form cart" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype='multipart/form-data' data-product_id="<?php echo absint( $product->get_id() ); ?>" data-product_variations="<?php esc_attr_e( $variations_attr ); // WPCS: XSS ok. ?>">
		<?php

		do_action( 'woocommerce_before_variations_form' );

		if ( isset( $_POST['special_addon_variation'] ) && $_POST['special_addon_variation'] && is_array( $_POST['special_addon_variation'] ) && count( $_POST['special_addon_variation'] ) > 0 ) {

			$special_addon_variation     = $_POST['special_addon_variation'];
			$fkcart_spl_product_id       = isset( $_POST['special_addon_variation']['fkcart_spl_product_id'] ) ? esc_attr( sanitize_text_field( $_POST['special_addon_variation']['fkcart_spl_product_id'] ) ) : '';
			$fkcart_spl_product_cart_key = isset( $_POST['special_addon_variation']['fkcart_spl_product_cart_key'] ) ? esc_attr( sanitize_text_field( $_POST['special_addon_variation']['fkcart_spl_product_cart_key'] ) ) : '';
			$fkcart_spl_product_action   = isset( $_POST['special_addon_variation']['fkcart_spl_product_action'] ) ? esc_attr( sanitize_text_field( $_POST['special_addon_variation']['fkcart_spl_product_action'] ) ) : '';

			printf( '<input type="hidden" name="%1$s" id="%1$s" value="%2$s" />', esc_attr( 'fkcart_spl_product_id' ), esc_attr( $fkcart_spl_product_id ) );
			printf( '<input type="hidden" name="%1$s" id="%1$s" value="%2$s" />', esc_attr( 'fkcart_spl_product_cart_key' ), esc_attr( $fkcart_spl_product_cart_key ) );
			printf( '<input type="hidden" name="%1$s" id="%1$s" value="%2$s" />', esc_attr( 'fkcart_spl_product_action' ), esc_attr( $fkcart_spl_product_action ) );
		}

		?>


		<?php if ( ! empty( $available_variations ) ) { ?>
            <div class="fkcart-product-form-reset-form">
				<?php echo wp_kses_post( apply_filters( 'woocommerce_reset_variations_link', '<a class="reset_variations" href="#">' . esc_html__( apply_filters( 'fkcart_reset_to_variations', __( 'Clear', 'woocommerce' ) ) ) . '</a>' ) ) ?>
            </div>
            <div class="fkcart-product-form-field variations">
                <table class="variations" role="presentation">
                    <tbody>
					<?php foreach ( $attributes as $attribute_name => $options ) : ?>
                        <tr>
                            <th class="label">
                                <label for="<?php echo esc_attr( sanitize_title( $attribute_name ) ); ?>" class="fkcart-input-label"><?php echo wc_attribute_label( $attribute_name ); // WPCS: XSS ok. ?></label>
                            </th>
                            <td class="value">
                                <div class="fkcart-form-input-wrap">
									<?php
									wc_dropdown_variation_attribute_options( array(
										'options'   => $options,
										'attribute' => $attribute_name,
										'product'   => $product,
									) );
									?>
                                </div>
                            </td>
                        </tr>
					<?php endforeach; ?>
                    </tbody>
                </table>
            </div>
			<?php do_action( 'woocommerce_after_variations_table' ); ?>
            <div class="single_variation_wrap">
				<?php
				/**
				 * Hook: woocommerce_before_single_variation.
				 */
				do_action( 'woocommerce_before_single_variation' );

				/**
				 * Hook: woocommerce_single_variation. Used to output the cart button and placeholder for variation data.
				 *
				 * @since 2.4.0
				 * @hooked woocommerce_single_variation - 10 Empty div for variation data.
				 * @hooked woocommerce_single_variation_add_to_cart_button - 20 Qty and cart button.
				 */
				do_action( 'woocommerce_single_variation' );

				/**
				 * Hook: woocommerce_after_single_variation.
				 */
				do_action( 'woocommerce_after_single_variation' );
				?>
            </div>
			<?php
		}
		do_action( 'woocommerce_after_variations_form' );
		?>

    </form>
<?php
do_action( 'woocommerce_after_add_to_cart_form' );
