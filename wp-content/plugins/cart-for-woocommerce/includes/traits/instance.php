<?php

namespace FKCart\Includes\Traits;

/**
 * Trait Instance
 */
if ( ! trait_exists( '\FKCart\Includes\Traits\Instance' ) ) {
	trait Instance {

		/**
		 * @var $instance
		 */
		private static $instance = null;

		/**
		 * @return Instance object
		 */
		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}
	}
}