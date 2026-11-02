<?php

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\FunnelkitCheckout' ) ) {
	class FunnelkitCheckout {
		public function __construct() {
			add_filter( 'fkcart_reward_enabled', [ $this, 'disable_rewards' ] );
			add_action( 'woocommerce_calculate_totals', [ $this, 'remove_rewards' ] );
		}

		public function is_enable() {
			return class_exists( '\WFACP_Core' );
		}

		public function is_dedicated_page() {
			return false === \WFACP_Public::get_instance()->is_checkout_override();
		}

		public function disable_rewards( $status ) {
			if ( $this->is_dedicated_page() ) {
				$status = true;
			}

			return $status;
		}


		public function remove_rewards() {
			if ( ! class_exists( '\FKCart\Pro\Rewards' ) ) {
				return;
			}

			$disabled_reward = false;

			if ( wp_doing_ajax() ) {
				// Only Disable Reward if required get parameter received for Dedicated checkout page.
				if ( isset( $_REQUEST['wfacp_is_checkout_override'] ) && 'no' === $_REQUEST['wfacp_is_checkout_override'] ) {
					$disabled_reward = true;
				}
			} else {
				if ( is_checkout() && $this->is_dedicated_page() ) {// Only disable Reward if current page is dedicated checkout page
					$disabled_reward = true;
				}
			}

			if ( $disabled_reward ) {
				remove_action( 'woocommerce_calculate_totals', [ \FKCart\Pro\Rewards::getInstance(), 'update_reward' ], 99 );
			}
		}


	}

	new FunnelkitCheckout();
}