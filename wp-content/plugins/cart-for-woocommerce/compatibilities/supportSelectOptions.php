<?php

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\SupportSelectOptions' ) ) {
	class SupportSelectOptions {

		/**
		 * Checks if product black listed
		 *
		 * @param $product \WC_Product
		 *
		 * @return bool
		 */
		public static function blacklisted_product( $product ) {
			if ( ! $product instanceof \WC_Product ) {
				return false;
			}

			/** AliPay */
			if ( defined( 'ADSW_VERSION' ) ) {
				return true;
			}

			/** Woocommerce Custom Product Addons by Acowebs https://acowebs.com */
			$meta = $product->get_meta( '_wcpa_product_meta' );
			if ( ! empty( $meta ) && function_exists( 'WCPA' ) ) {
				return true;
			}

			/** WooCommerce Product Add-ons https://woocommerce.com/products/product-add-ons/ */
			$meta = $product->get_meta( '_product_addons' );
			if ( ! empty( $meta ) && class_exists( '\WC_Product_Addons' ) ) {
				return true;
			}

			/** LearnDash Group Registration plugin https://learndash.com/support/kb/add-ons/groups-management/group-registration-for-learndash/ */
			$meta = $product->get_meta( '_is_group_purchase_active' );
			if ( 'on' === $meta && class_exists( '\LdGroupRegistration\Modules\Classes\Ld_Group_Registration_Woocommerce' ) ) {
				return true;
			}

			/** WPC Variations Radio Buttons for WooCommerce (Premium) https://wpclever.net/ */
			if ( class_exists( '\WPClever_Woovr' ) ) {
				$active  = \WPClever_Woovr::get_setting( 'active', 'yes' );
				$_active = $product->get_meta( '_woovr_active', true ) ?: 'default';
				if ( $_active === 'yes' || ( $_active === 'default' && $active === 'yes' ) ) {
					return true;
				}
			}

			/** Savoy Theme */
			if ( function_exists( 'nm_variation_attribute_options' ) ) {
				/** Check if product is variable or variation type */
				if ( $product->is_type( 'variable' ) || $product->is_type( 'variation' ) ) {
					global $nm_globals;
					if ( ! empty( $nm_globals['custom_variation_controls'] ) ) {
						return true;
					}
				}
			}

			return false;
		}

		/**
		 * Check if white listed product types
		 *
		 * @param $product \WC_Product
		 *
		 * @return bool
		 */
		public static function whitelisted_product_type( $product ) {
			$types = apply_filters( 'fkcart_allow_product_types', array(
				'simple',
				'variable',
				'variation',
				'variable-subscription',
				'subscription_variation',
				'subscription',
			) );

			$type = $product->get_type();

			return in_array( $type, $types, true );
		}
	}

	Compatibility::register( new SupportSelectOptions(), 'SupportSelectOptions' );
}
