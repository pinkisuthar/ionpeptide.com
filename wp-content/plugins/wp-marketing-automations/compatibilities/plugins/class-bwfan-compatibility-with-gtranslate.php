<?php
defined( 'ABSPATH' ) || exit;

/**
 * WordPress gTranslate Plugin Compatibility
 * https://gtranslate.io/
 *
 * @package BWFAN
 * @since 1.0.0
 */

if ( ! class_exists( 'BWFAN_Compatibility_With_GTRANSLATE' ) ) {
	/**
	 * Class BWFAN_Compatibility_With_GTRANSLATE
	 *
	 * Handles compatibility with the GTranslate WordPress plugin
	 */
	class BWFAN_Compatibility_With_GTRANSLATE {

		/**
		 * Constructor
		 */
		public function __construct() {
			add_filter( 'bwfan_enable_language_settings', array( $this, 'bwfan_add_gtranslate_language_settings' ) );
		}

		/**
		 * Get GTranslate language code from various sources
		 *
		 * @return string Language code
		 */
		public static function get_gtranslate_language() {
			// Check if googtrans cookie is set
			if ( isset( $_COOKIE['googtrans'] ) ) {
				$googtrans = sanitize_text_field( wp_unslash( $_COOKIE['googtrans'] ) );
				$googtrans = explode( '/', $googtrans );

				return is_array( $googtrans ) ? end( $googtrans ) : '';
			}

			// Check if HTTP_X_GT_LANG header is set
			if ( isset( $_SERVER['HTTP_X_GT_LANG'] ) && ! empty( $_SERVER['HTTP_X_GT_LANG'] ) ) {
				return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_GT_LANG'] ) );
			}

			// Check domain mapping if HTTP_HOST is set
			if ( isset( $_SERVER['HTTP_HOST'] ) && ! empty( $_SERVER['HTTP_HOST'] ) ) {
				$current_domain = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
				$gtranslate     = get_option( 'GTranslate' );
				$domain         = wp_parse_url( home_url(), PHP_URL_HOST );

				if ( $current_domain === $domain ) {
					return isset( $gtranslate['default_language'] ) ? $gtranslate['default_language'] : 'en';
				}

				if ( isset( $gtranslate['custom_domains'] ) && ! empty( $gtranslate['custom_domains'] ) ) {
					$custom_domains_data = $gtranslate['custom_domains_data'];
					if ( is_string( $custom_domains_data ) && ! empty( $custom_domains_data ) ) {
						$decoded_data = json_decode( stripslashes( $custom_domains_data ), true );
						if ( is_array( $decoded_data ) ) {
							$custom_domains_data = $decoded_data;
						}
					}

					if ( is_array( $custom_domains_data ) && ! empty( $custom_domains_data ) ) {
						$found_lang = array_search( $current_domain, $custom_domains_data, true );
						if ( ! empty( $found_lang ) ) {
							return $found_lang;
						}
					}
				}

				if ( isset( $gtranslate['enterprise_version'] ) && $gtranslate['enterprise_version'] ) {
					$parts = explode( '.', $current_domain );
					if ( count( $parts ) > 2 && ! empty( $parts[0] ) ) {
						$potential_lang = trim( $parts[0] );

						// Validate potential language code format
						if ( ! empty( $potential_lang ) && preg_match( '/^[a-z]{2}(-[A-Z]{2})?$/', $potential_lang ) ) {
							// Check if the subdomain is a valid language code
							$enabled_languages = [];
							if ( isset( $gtranslate['fincl_langs'] ) && ! empty( $gtranslate['fincl_langs'] ) ) {
								$enabled_languages = $gtranslate['fincl_langs'];
							} elseif ( isset( $gtranslate['incl_langs'] ) && ! empty( $gtranslate['incl_langs'] ) ) {
								$enabled_languages = $gtranslate['incl_langs'];
							}

							if ( ! empty( $enabled_languages ) && in_array( $potential_lang, $enabled_languages, true ) ) {
								return $potential_lang;
							}
						}
					}
				}
			}

			return '';
		}

		/**
		 * Add GTranslate languages to FunnelKit Automation language settings
		 *
		 * @param array $settings Existing language settings array
		 *
		 * @return array Modified settings array with GTranslate languages added
		 */
		public function bwfan_add_gtranslate_language_settings( $settings ) {
			// Ensure settings is an array
			if ( ! is_array( $settings ) ) {
				$settings = [];
			}

			$gtranslate_options = get_option( 'GTranslate' );

			if ( empty( $gtranslate_options ) || ! isset( $gtranslate_options['incl_langs'] ) || ! is_array( $gtranslate_options['incl_langs'] ) ) {
				return $settings;
			}

			$gtranslate_language_codes = $gtranslate_options['incl_langs'];

			$gtranslate_languages = [];
			foreach ( $gtranslate_language_codes as $code ) {
				if ( ! empty( $code ) && is_string( $code ) ) {
					$gtranslate_languages[ $code ] = $this->get_language_display_name( $code );
				}
			}

			if ( ! empty( $gtranslate_languages ) ) {
				$settings['lang_options'] = array_merge( isset( $settings['lang_options'] ) ? $settings['lang_options'] : [], $gtranslate_languages );
				$settings['enable_lang']  = 1;
			}

			return $settings;
		}

		/**
		 * Convert a URL to its language-specific domain equivalent
		 *
		 * @param string $url Original URL to be translated
		 * @param string $lang Language code to translate the URL to
		 *
		 * @return string URL with the appropriate language-specific domain or path
		 */
		public static function get_translated_domain_url( $url, $lang ) {
			// Validate input parameters
			if ( empty( $url ) || empty( $lang ) || ! is_string( $url ) || ! is_string( $lang ) ) {
				return $url;
			}

			$gtranslate_settings = get_option( 'GTranslate' );

			if ( empty( $gtranslate_settings ) || ! is_array( $gtranslate_settings ) ) {
				return $url;
			}

			// Default language: do not add prefix — main site URL is for the default language (from GTranslate; can be any language,
			$default_language = isset( $gtranslate_settings['default_language'] ) ? $gtranslate_settings['default_language'] : '';
			if ( $lang === $default_language ) {
				return $url;
			}

			// Parse the URL
			$url_parts = wp_parse_url( $url );
			if ( false === $url_parts || ! isset( $url_parts['host'] ) ) {
				return $url;
			}

			$base_domain = $url_parts['host'];
			$scheme      = isset( $url_parts['scheme'] ) ? $url_parts['scheme'] : 'https';
			$path        = isset( $url_parts['path'] ) ? $url_parts['path'] : '';
			$query       = isset( $url_parts['query'] ) ? '?' . $url_parts['query'] : '';
			$fragment    = isset( $url_parts['fragment'] ) ? '#' . $url_parts['fragment'] : '';

			// Determine URL structure based on GTranslate version
			$url_structure = 'none';
			if ( isset( $gtranslate_settings['pro_version'] ) && ! empty( $gtranslate_settings['pro_version'] ) ) {
				$url_structure = 'sub_directory';
			} elseif ( isset( $gtranslate_settings['enterprise_version'] ) && ! empty( $gtranslate_settings['enterprise_version'] ) ) {
				$url_structure = 'sub_domain';
			}

			// Handle custom domains first (highest priority)
			if ( isset( $gtranslate_settings['custom_domains'] ) && ! empty( $gtranslate_settings['custom_domains'] ) ) {
				$custom_domains_data = $gtranslate_settings['custom_domains_data'];
				if ( is_string( $custom_domains_data ) && ! empty( $custom_domains_data ) ) {
					$decoded_data = json_decode( stripslashes( $custom_domains_data ), true );
					if ( is_array( $decoded_data ) ) {
						$custom_domains_data = $decoded_data;
					}
				}

				// If this language has a custom domain, use it and do not apply subdomain/subdirectory.
				if ( is_array( $custom_domains_data ) && ! empty( $custom_domains_data ) && isset( $custom_domains_data[ $lang ] ) && ! empty( $custom_domains_data[ $lang ] ) ) {
					$new_domain = $custom_domains_data[ $lang ];
					return $scheme . '://' . $new_domain . $path . $query . $fragment;
				}
			}

			// Handle URL structure based on version (subdomain or subdirectory only when no custom domain for this lang)
			if ( 'sub_domain' === $url_structure ) {
				// Enterprise version: lang.domain.com/path
				$url = $scheme . '://' . $lang . '.' . $base_domain . $path . $query . $fragment;
			} elseif ( 'sub_directory' === $url_structure ) {
				// Pro version: domain.com/lang/path
				$url = $scheme . '://' . $base_domain . '/' . $lang . $path . $query . $fragment;
			}

			return $url;
		}

		/**
		 * Get language display name using WordPress built-in functions
		 *
		 * @param string $lang_code Language code
		 *
		 * @return string Language display name
		 */
		private function get_language_display_name( $lang_code ) {
			// Validate input - ensure it's a non-empty string
			if ( ! is_string( $lang_code ) || empty( trim( $lang_code ) ) ) {
				// Return a safe default for invalid input
				return 'UNKNOWN';
			}

			// Try PHP native function first (PHP 8.1+) - faster and more reliable
			if ( function_exists( 'locale_get_display_language' ) ) {
				$display_name = locale_get_display_language( $lang_code, 'en' );
				if ( ! empty( $display_name ) && $display_name !== $lang_code ) {
					return $display_name;
				}
			}

			// Fallback to WordPress method for older PHP versions or edge cases
			$translations = $this->wp_get_available_translations();
			$wp_lang_code = str_replace( '-', '_', $lang_code );

			if ( isset( $translations[ $wp_lang_code ]['native_name'] ) ) {
				return $translations[ $wp_lang_code ]['native_name'];
			}

			// Also try the original format in case it matches
			if ( isset( $translations[ $lang_code ]['native_name'] ) ) {
				return $translations[ $lang_code ]['native_name'];
			}

			if ( strlen( $lang_code ) === 2 && strpos( $lang_code, '_' ) === false && strpos( $lang_code, '-' ) === false ) {
				$with_country = $lang_code . '_' . strtoupper( $lang_code );
				if ( isset( $translations[ $with_country ]['native_name'] ) ) {
					return $translations[ $with_country ]['native_name'];
				}
			}

			// return uppercase language code if translation not found
			return strtoupper( $lang_code );
		}

		/**
		 * Wrap wp_get_available_translations
		 *
		 * @return array Available translations array
		 */
		private function wp_get_available_translations() {
			if ( ! function_exists( 'wp_get_available_translations' ) ) {
				require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			}

			// WordPress may fail silently or return an empty array if offline, and will cache the result otherwise.
			$translations = wp_get_available_translations();

			// Ensure we return an array even if the function fails
			return is_array( $translations ) ? $translations : [];
		}
	}

	new BWFAN_Compatibility_With_GTRANSLATE();
}