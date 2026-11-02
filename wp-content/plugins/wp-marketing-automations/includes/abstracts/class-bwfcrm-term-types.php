<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFCRM_Term_Type' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	abstract class BWFCRM_Term_Type {
		public static $TAG = 1;
		public static $LIST = 2;
	}

	if ( ! function_exists( 'bwfcrm_get_term_type_text' ) ) {
		function bwfcrm_get_term_type_text( $type = 1 ) {
			switch ( $type ) {
				case BWFCRM_Term_Type::$TAG:
					return 'Tag';
				case BWFCRM_Term_Type::$LIST:
					return 'List';
			}

			return 'Tag';
		}
	}
}
