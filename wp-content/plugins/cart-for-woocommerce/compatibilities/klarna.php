<?php

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\Klarna' ) ) {
	class Klarna {
		private $flag = false;

		public function __construct() {
			add_action( 'woocommerce_after_calculate_totals', [ $this, 'remove_reward_code' ], 97 );
		}

		public function is_enable() {
			return class_exists( '\WC_Klarna_Payments' ) && defined( 'WFFN_PRO_FILE' );
		}

		/**
		 * Unhook update reward callback during the checkout process because of klarna throw the mismatch cart quantity.
		 *
		 * @return void
		 * @throws \Exception
		 */
		public function remove_reward_code() {
			if ( ! class_exists( '\FKCart\Pro\Rewards' ) ) {
				return;
			}
			if ( true === $this->flag ) {
				return;
			}
			$this->flag = true;

			remove_action( 'woocommerce_calculate_totals', [ \FKCart\Pro\Rewards::getInstance(), 'update_reward' ], 99 );
			\FKCart\Pro\Rewards::getInstance()->update_reward();
		}
	}

	Compatibility::register( new Klarna(), 'Klarna' );
}
