<?php

namespace FKCart\compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\Weglot' ) ) {
	class Weglot {
		public function __construct() {

		}


		public function is_enable() {
			return defined( 'WEGLOT_SLUG' );
		}

		/**
		 * Return current language code from weglot
		 * @return string
		 */
		public function get_language_code() {
			return weglot_get_current_language();
		}
	}

	Compatibility::register( new Weglot(), 'weglot' );
}
