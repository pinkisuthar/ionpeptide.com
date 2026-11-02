<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
if ( ! class_exists( 'BWF_Logger' ) ) {
	#[AllowDynamicProperties]
	class BWF_Logger {

		private static $ins = null;
		public $wc_logger = null;

		public function __construct() {
		}

		public static function get_instance() {
			if ( self::$ins === null ) {
				self::$ins = new self;
			}

			return self::$ins;
		}

		public function log( $message, $file_name = '', $folder_prefix = 'fk-temp', $force = false ) {

			if ( ! $force && false === apply_filters( 'bwf_logs_allowed', false, $file_name ) ) {
				return;
			}
			$plugin_short_name = $folder_prefix . '-logs';
			$transient_key     = $file_name . '-' . gmdate( 'Y-m-d' );
			$transient_key     = $transient_key . '-' . hash_hmac( 'md5', $transient_key, defined( 'AUTH_SALT' ) && AUTH_SALT ? AUTH_SALT : 'funnelkit-logs' );
			$transient_value   = gmdate( 'c', time() ) . ' - ' . $message . "\n";

			$file_api = $this->is_writable( $plugin_short_name, $transient_key );
			if ( false === $file_api ) {
				return;
			}

			$old_content = $file_api->get_contents( $transient_key );
			if ( ! empty( $old_content ) ) {
				$old_content     = bwf_safe_unserialize( $old_content );
				$transient_value = $old_content . $transient_value;
			}
			$transient_value = maybe_serialize( $transient_value );
			$file_api->put_contents( $transient_key, $transient_value );

		}

		public function is_writable( $plugin_short_name, $transient_key ) {
			if ( ! class_exists( 'WooFunnels_File_Api' ) ) {
				return false;
			}

			$file_api = new WooFunnels_File_Api( $plugin_short_name );
			$file_api->touch( $transient_key );
			if ( $file_api->is_writable( $transient_key ) && $file_api->is_readable( $transient_key ) ) {
				return $file_api;
			}

			return false;
		}

		public function get_log_options() {
			$wp_dir                       = wp_upload_dir();
			$woofunnels_uploads_directory = $wp_dir['basedir'];
			$woofunnels_uploads_directory = $woofunnels_uploads_directory . '/funnelkit';

			$final_logs_result = array();

			$plugin_logs_directories = glob( $wp_dir['basedir'] . '/funnelkit/*-logs' );
			foreach ( $plugin_logs_directories as $directory ) {
				$result         = array();
				$directory_data = pathinfo( $directory );
				if ( ! isset( $directory_data['basename'] ) ) {
					continue;
				}

				$plugin_uploads_directory = $woofunnels_uploads_directory . '/' . $directory_data['basename'];
				$files                    = @scandir( $plugin_uploads_directory ); // @codingStandardsIgnoreLine.

				if ( ! is_array( $files ) || 0 === count( $files ) ) {
					continue;
				}

				$file_timestamps = array();
				foreach ( $files as $value ) {
					if ( ! in_array( $value, array( '.', '..' ), true ) ) {
						$file_path = $plugin_uploads_directory . '/' . $value;
						if ( ! is_dir( $file_path ) ) {
							$file_timestamps[$value] = filemtime( $file_path );
						}
					}
				}

				// Sort files by modified time (newest first)
				arsort( $file_timestamps );
				foreach ( $file_timestamps as $file => $timestamp ) {
					$result[$file] = $file;
				}

				if ( is_array( $result ) && count( $result ) > 0 ) {
					$final_logs_result[ $directory_data['basename'] ] = $result;
				}
			}

			return $final_logs_result;
		}


	}

}