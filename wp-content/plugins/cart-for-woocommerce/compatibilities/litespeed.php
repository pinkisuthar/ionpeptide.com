<?php

namespace FKCart\compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\Litespeed' ) ) {

	class Litespeed {
		public function __construct() {
			add_action( 'fkcart_quick_before_view_content', [ $this, 'remove_action' ] );
			add_action( 'fkcart_after_header', [ $this, 'remove_action' ] );
		}

		public function is_enable() {
			return defined( 'LSCWP_V' );
		}

		public function remove_action() {
			if ( ! class_exists( '\LiteSpeed\CDN' ) ) {
				return;
			}
			remove_filter( 'wp_get_attachment_image_src', array( \LiteSpeed\CDN::get_instance(), 'attach_img_src' ), 999 );
			remove_filter( 'wp_get_attachment_url', array( \LiteSpeed\CDN::get_instance(), 'url_img' ), 999 );
		}
	}

	Compatibility::register( new Litespeed(), 'litespeed' );
}
