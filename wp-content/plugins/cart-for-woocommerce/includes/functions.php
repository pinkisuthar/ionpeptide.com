<?php

use FKCart\Includes\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'fkcart_is_wc_active' ) ) {
	/**
	 * Checks if WooCommerce is active and available.
	 *
	 * @return bool True if WooCommerce is active, false otherwise.
	 * @since 1.0.0
	 */
	function fkcart_is_wc_active() {
		$wc_class_exists = class_exists( '\WooCommerce' );

		return $wc_class_exists || ( in_array( 'woocommerce/woocommerce.php', (array) apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) && $wc_class_exists );
	}
}

if ( ! function_exists( 'fkcart_get_template_part' ) ) {
	/**
	 * Get template path data
	 *
	 * @param $slug
	 * @param $name
	 * @param $args
	 * @param $echo
	 *
	 * @return false|string|void
	 */
	function fkcart_get_template_part( $slug, $name = '', $args = [], $echo = true, $custom_path = '' ) {
		if ( $args && is_array( $args ) ) {
			extract( $args, EXTR_SKIP ); // phpcs:ignore
		}

		$template = '';

		/** Try to locate file in your theme. theme/cart-for-woocommerce/slug-name.php and theme/cart-for-woocommerce/slug.php */
		$template_path = ! empty( $name ) ? "{$slug}-{$name}.php" : "{$slug}.php";

		$template = locate_template( [ 'cart-for-woocommerce/' . $template_path ] );
		$tmp      = FKCART_PLUGIN_DIR;
		if ( ! empty( $custom_path ) && is_dir( $custom_path ) ) {

			$tmp = $custom_path;
		}

		/** Can alter template directory path */
		$template_path = apply_filters( 'fkcart_set_template_path', $tmp . '/templates', $template, $args );

		/** Try to locate slug-name.php */
		if ( ! $template && $name && file_exists( $template_path . "/{$slug}-{$name}.php" ) ) {
			$template = $template_path . "/{$slug}-{$name}.php";
		}

		/** Try to locate slug.php */
		if ( ! $template && ! $name && file_exists( $template_path . "/{$slug}.php" ) ) {
			$template = $template_path . "/{$slug}.php";
		}

		/** Allow altering template file path */
		$template = apply_filters( 'fkcart_get_template_part', $template, $slug, $name );
		if ( ! $template ) {
			return;
		}

		/** Echo data */
		if ( true === $echo ) {
			require $template;

			return '';
		}

		/** Return data */
		ob_start();
		require $template;

		return ob_get_clean();
	}
}

if ( ! function_exists( 'fkcart_variable_product_type' ) ) {
	/**
	 * Check if product type is variable
	 *
	 * @param $type
	 *
	 * @return bool
	 */
	function fkcart_is_variable_product_type( $type ) {
		return in_array( $type, [ 'variable', 'variable-subscription', 'simple-subscription' ] );
	}
}

if ( ! function_exists( 'fkcart_variation_product_type' ) ) {
	/**
	 * Check if product type is variation
	 *
	 * @param $type
	 *
	 * @return bool
	 */
	function fkcart_is_variation_product_type( $type ) {
		return in_array( $type, [ 'variation', 'subscription_variation' ] );
	}
}

if ( ! function_exists( 'fkcart_is_preview' ) ) {
	/**
	 * Check if Cart admin preview
	 *
	 * @return mixed|null
	 */
	function fkcart_is_preview() {
		return apply_filters( 'fkcart_is_preview', isset( $_REQUEST['page'] ) && 'fkcart' == $_REQUEST['page'] );
	}
}

if ( ! function_exists( 'fkcart_get_active_skin_html' ) ) {
	/**
	 * Get active skin HTML
	 *
	 * @return false|string|null
	 */
	function fkcart_get_active_skin_html() {
		$skin = Data::get_active_skin();

		return fkcart_get_template_part( 'skin/' . $skin, '', [], false );
	}
}

if ( ! function_exists( 'fkcart_mini_cart_html' ) ) {
	/**
	 * Get active skin HTML
	 *
	 * @return false|string|null
	 */
	function fkcart_mini_cart_html() {
		$front = \FKCart\Includes\Front::get_instance();

		return $front->get_mini_cart_toggler();
	}
}

if ( ! function_exists( 'fkcart_get_dummy_products' ) ) {
	/**
	 * Get dummy products list
	 *
	 * @return array[]
	 */
	function fkcart_get_dummy_products() {
		return [
			0 => [
				"name"       => "Wool Knife",
				"price"      => 50,
				"sale_price" => 47.5,
				"image"      => '4.png',
			],
			1 => [
				"name"       => "Rubber Shoes",
				"price"      => 40,
				"sale_price" => 36,
				"image"      => '2.png',
				"meta"       => '<span class="fkcart-attr-wrap"><span class="fkcart-attr-key">Color</span>:<span class="fkcart-attr-value">Green</span></span>',
			],
			2 => [
				"name"       => "Silk Hat",
				"price"      => 120,
				"sale_price" => 120,
				"image"      => '1.png',
			],
			3 => [
				"name"       => "Bronze Bottle",
				"price"      => 15,
				"sale_price" => 14,
				"image"      => '5.png',
			],
			4 => [
				"name"       => "Iron Pants",
				"price"      => 75,
				"sale_price" => 75,
				"image"      => '3.png',
			],
		];
	}
}

if ( ! function_exists( 'fkcart_is_weglot_active' ) ) {
	/**
	 * Weglot language plugin active check
	 *
	 * @return bool
	 */
	function fkcart_is_weglot_active() {
		if ( defined( 'WEGLOT_NAME' ) ) {
			return true;
		}

		return in_array( 'weglot/weglot.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true );
	}
}

if ( ! function_exists( 'fkcart_is_translatepress_active' ) ) {
	/**
	 * Translatepress language plugin active check
	 *
	 * @return bool
	 */
	function fkcart_is_translatepress_active() {
		return in_array( 'translatepress-multilingual/index.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true );
	}
}
if ( ! function_exists( 'fkcart_free_shipping_method' ) ) {
	/**
	 * Get WC free shipping methods
	 *
	 * @return string[]
	 */
	function fkcart_free_shipping_method() {
		return [ 'free_shipping', 'table_rate', 'flexible_shipping_single' ];
	}
}

if ( ! function_exists( 'fkcart_get_formatted_cart_item_data' ) ) {
	/**
	 * Get WC cart item formatted data
	 *
	 * @param $cart_item
	 *
	 * @return string
	 */
	function fkcart_get_formatted_cart_item_data( $cart_item ) {
		$item_data = array();

		/** Variation values are shown only if they are not found in the title as of 3.0. */
		/** This is because variation titles display the attributes */
		if ( $cart_item['data']->is_type( 'variation' ) && is_array( $cart_item['variation'] ) ) {
			foreach ( $cart_item['variation'] as $name => $value ) {
				$taxonomy = wc_attribute_taxonomy_name( str_replace( 'attribute_pa_', '', urldecode( $name ) ) );
				if ( taxonomy_exists( $taxonomy ) ) {
					/** If this is a term slug, get the term's nice name */
					$term = get_term_by( 'slug', $value, $taxonomy );
					if ( ! is_wp_error( $term ) && $term && $term->name ) {
						$value = $term->name;
					}
					$label = wc_attribute_label( $taxonomy );
				} else {
					/** If this is a custom option slug, get the options name */
					$value = apply_filters( 'woocommerce_variation_option_name', $value, null, $taxonomy, $cart_item['data'] );
					$label = wc_attribute_label( str_replace( 'attribute_', '', $name ), $cart_item['data'] );
				}
				$item_data[] = array(
					'key'   => $label,
					'value' => $value,
				);
			}
		}

		/** Filter item data to allow 3rd parties to add more to the array */
		$item_data = apply_filters( 'woocommerce_get_item_data', $item_data, $cart_item );

		/** Format item data ready to display */
		foreach ( $item_data as $key => $data ) {
			/** Set hidden to true to not display meta on cart */
			if ( ! empty( $data['hidden'] ) ) {
				unset( $item_data[ $key ] );
				continue;
			}
			$item_data[ $key ]['key']     = ! empty( $data['key'] ) ? $data['key'] : $data['name'];
			$item_data[ $key ]['display'] = ! empty( $data['display'] ) ? $data['display'] : $data['value'];
		}

		if ( 0 === count( $item_data ) ) {
			return '';
		}

		$use_native_cart_item_data = apply_filters( 'fkcart_use_buit_in_cart_item_data_template', true );
		if ( $use_native_cart_item_data ) {
			return fkcart_get_template_part( 'cart/cart-item-data', '', [ 'item_data' => $item_data ], false );
		}

		ob_start();
		wc_get_template( 'cart/cart-item-data.php', array( 'item_data' => $item_data ) );

		return ob_get_clean();
	}
}

if ( ! function_exists( 'fkcart_fb_pro_min_version_verified' ) ) {
	/**
	 * Check FB Pro min version
	 *
	 * @param $compare_version
	 *
	 * @return bool|int
	 */
	function fkcart_fb_pro_min_version_verified( $compare_version = '' ) {
		$pro_version = defined( 'WFFN_PRO_BUILD_VERSION' ) ? WFFN_PRO_BUILD_VERSION : 0;

		$v2 = empty( $compare_version ) ? FKCART_MIN_FB_PRO_VERSION : $compare_version;

		return version_compare( $pro_version, $v2, '>=' );
	}
}

if ( ! function_exists( 'fkcart_map_variation_attributes' ) ) {
	/**
	 * Map Variation Attributes in case of ANY ,ANY options
	 *
	 * @param $variation_attr
	 * @param $product_attr
	 *
	 * @return array
	 */

	function fkcart_map_variation_attributes( $variation_attr, $product_attr ) {
		$new_product_attr = [];
		foreach ( $product_attr as $k => $item ) {
			$k                      = strtolower( $k );//Lowering the Attribute keys
			$k                      = str_replace( ' ', '-', $k );
			$new_product_attr[ $k ] = $item;
		}
		$output = [];
		foreach ( $variation_attr as $key => $attr ) {
			if ( empty( $attr ) ) {
				$key  = strtolower( $key );
				$key  = str_replace( ' ', '-', $key );
				$attr = $new_product_attr[ $key ][0];
			}
			$output[ 'attribute_' . $key ] = $attr;
		}

		return $output;
	}
}

if ( ! function_exists( 'fkcart_product_add_supported' ) ) {
	/**
	 * @param $product WC_Product
	 *
	 * @return bool
	 */
	function fkcart_product_add_supported( $product ) {
		if ( fkcart_is_preview() ) {
			return true;
		}

		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		/** Disallow if not whitelisted product type */
		if ( false === FKCart\Compatibilities\supportSelectOptions::whitelisted_product_type( $product ) ) {
			return false;
		}

		/** Disallow if blacklisted plugins */
		if ( true === FKCart\Compatibilities\supportSelectOptions::blacklisted_product( $product ) ) {
			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'fkcart_log_error' ) ) {
	/**
	 * Log error message
	 *
	 * @param $message
	 * @param $type
	 *
	 * @return void
	 * @since 1.9.0
	 */
	function fkcart_log_error( $message, $type = 'info' ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger  = wc_get_logger();
		$context = [ 'source' => 'fkcart_error' ];

		if ( is_array( $message ) || is_object( $message ) ) {
			$message = print_r( $message, true );
		}

		if ( $type === 'error' ) {
			$logger->error( $message, $context );
		} else {
			$logger->info( $message, $context );
		}
	}
}
