<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WFCO_Connector_Screen_Factory' ) ) {
	#[AllowDynamicProperties]
	abstract class WFCO_Connector_Screen_Factory {

		private static $screens = [];

		public static function create( $slug, $data ) {

			$type                            = $data['type'];
			self::$screens[ $type ][ $slug ] = new WFCO_Connector_Screen( $slug, $data );
		}

		public static function get( $screen ) {
			return self::$screens[ $screen ];
		}

		public static function getAll( $type = '' ) {
			if ( empty( $type ) ) {
				return self::$screens;
			}

			return isset( self::$screens[ $type ] ) ? self::$screens[ $type ] : [];
		}

	}
}