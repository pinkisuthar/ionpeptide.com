<?php
/**
 * WooCommerce Smart Coupons
 * by storeapps
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\SmartCoupons' ) ) {
	class SmartCoupons {
		public function __construct() {
			add_action( 'fkcart_before_cart_items', [ $this, 'run_actions' ] );
			add_action( 'fkcart_added_add_to_cart', [ $this, 'apply_url_coupons' ] );
			add_action( 'fkcart_after_add_to_cart', [ $this, 'apply_url_coupons' ] );
		}

		public function run_actions() {
			if ( ! class_exists( 'WC_SC_Auto_Apply_Coupon' ) ) {
				return;
			}
			$instance = \WC_SC_Auto_Apply_Coupon::get_instance();
			if ( ! method_exists( $instance, 'auto_apply_coupons' ) ) {
				return;
			}
			$instance->auto_apply_coupons();
		}

		public function apply_url_coupons() {
			if ( ! class_exists( '\WC_SC_URL_Coupon' ) || ! method_exists( '\WC_SC_URL_Coupon', 'apply_coupon_from_session' ) ) {
				return;
			}
			$instance = \WC_SC_URL_Coupon::get_instance();
			$instance->apply_coupon_from_session();
		}

		public function is_enable() {
			return class_exists( '\WC_Smart_Coupons' );
		}
	}

	Compatibility::register( new SmartCoupons(), 'storeapps' );
}
