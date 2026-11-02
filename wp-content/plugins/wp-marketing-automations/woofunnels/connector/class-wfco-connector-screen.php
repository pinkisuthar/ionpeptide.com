<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WFCO_Connector_Screen' ) ) {
	#[AllowDynamicProperties]
	class WFCO_Connector_Screen {

		private $slug = '';
		private $image = '';
		private $name = '';
		private $desc = '';
		private $is_active = false;
		private $activation_url = '';
		private $file = '';
		private $connector_class = '';
		private $source = '';
		private $support = [];
		private $type = 'FunnelKit Automations';
		private $show_setting_btn = true;

		public function __construct( $slug, $data ) {
			$this->slug = $slug;

			$allowed_props = [ 'image', 'logo', 'name', 'desc', 'is_active', 'activation_url', 'file', 'connector_class', 'source', 'support', 'type', 'show_setting_btn' ];
			if ( is_array( $data ) && count( $data ) > 0 ) {
				foreach ( $data as $property => $val ) {
					if ( ! in_array( $property, $allowed_props, true ) ) {
						continue;
					}
					if ( is_array( $val ) ) {
						$this->{$property} = $val;
						continue;
					}
					if ( is_bool( $val ) || in_array( $val, [ 'true', 'false' ], true ) ) {
						$this->{$property} = (bool) $val;
						continue;
					}
					$this->{$property} = trim( $val );
				}
			}
		}

		public function get_logo() {
			return $this->image;
		}

		public function is_active() {
			return $this->is_active;
		}

		public function is_installed() {
		}

		public function activation_url() {
			return $this->activation_url;
		}

		public function get_path() {
			return $this->file;
		}

		public function get_class() {
			return $this->connector_class;
		}

		public function get_source() {
			return $this->source;
		}

		public function get_support() {
			return $this->support;
		}

		public function get_slug() {
			return $this->slug;
		}

		public function get_type() {
			return $this->type;
		}

		public function get_name() {
			return $this->name;
		}

		public function get_desc() {
			return $this->desc;
		}

		public function is_activated() {
			if ( class_exists( $this->connector_class, false ) ) {
				return true;
			}

			return false;
		}

		public function show_setting_btn() {
			return $this->show_setting_btn;
		}

		public function is_present() {
			$plugins = get_plugins();
			$file    = trim( $this->file );
			if ( '' !== $this->file && isset( $plugins[ $file ] ) ) {
				return true;
			}

			return false;
		}
	}
}