<?php
/**
 * WooCommerce Points and Rewards
 * https://woocommerce.com/products/woocommerce-points-and-rewards/
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\WCRewardPoints' ) ) {

	class WCRewardPoints {
		public function __construct() {
			add_filter( 'wc_points_rewards_should_render_earn_points_message', [ $this, 'hide_message' ] );
		}

		/**
		 * Remove Reward points message as it breaks JSON
		 *
		 * @param $status
		 *
		 * @return false|mixed
		 */
		public function hide_message( $status ) {
			if ( false === $status ) {
				return false;
			}

			$wc_ajax = filter_input( INPUT_GET, 'wc-ajax' );
			if ( empty( $wc_ajax ) || false === strpos( strval( $wc_ajax ), 'fkcart' ) ) {
				return $status;
			}

			return false;
		}

		public function is_enable() {
			return class_exists( '\WC_Points_Rewards' );
		}
	}

	Compatibility::register( new WCRewardPoints(), 'wc_reward_points' );
}