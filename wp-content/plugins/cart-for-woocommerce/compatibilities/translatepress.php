<?php

namespace FKCart\compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\TranslatePress' ) ) {
	class TranslatePress {
		public function __construct() {

		}


		public function is_enable() {
			return class_exists( '\TRP_Translate_Press' );
		}

		/**
		 * Return current Locale code from Translate
		 * @return string
		 */
		public function get_language_code() {

			return get_locale();
		}
	}

	Compatibility::register( new TranslatePress(), 'translatepress' );
}
