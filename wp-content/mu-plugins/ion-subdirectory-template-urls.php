<?php
/**
 * Plugin Name: Ion Subdirectory Template URLs
 * Description: Rewrites root-relative URLs when WordPress home URL includes a path (e.g. local /ionpeptide.com/). Production root installs are unchanged.
 * Version: 1.1.0
 * Author: Intangible Treasures LLC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Home URL path prefix (e.g. /ionpeptide.com) or empty on domain-root installs.
 *
 * @return string
 */
function ion_subdirectory_home_path() {
	static $path = null;

	if ( null !== $path ) {
		return $path;
	}

	$parsed = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$parsed = is_string( $parsed ) ? $parsed : '/';

	if ( '/' === untrailingslashit( $parsed ) ) {
		$path = '';
	} else {
		$path = untrailingslashit( $parsed );
	}

	return $path;
}

/**
 * Rewrite root-relative URLs in HTML/inline JS for subdirectory installs.
 *
 * @param string $html Rendered markup.
 * @return string
 */
function ion_subdirectory_rewrite_html( $html ) {
	$base = ion_subdirectory_home_path();
	if ( '' === $base || ! is_string( $html ) || '' === $html ) {
		return $html;
	}

	$quick_check = ( false !== strpos( $html, '="/' ) )
		|| ( false !== strpos( $html, "='/" ) )
		|| ( false !== strpos( $html, 'new URL("/' ) )
		|| ( false !== strpos( $html, "new URL('/" ) )
		|| ( false !== strpos( $html, 'window.location.origin)' ) );

	if ( ! $quick_check ) {
		return $html;
	}

	$rewrite_path = static function ( $path ) {
		if ( ! is_string( $path ) || '' === $path || '/' !== $path[0] ) {
			return $path;
		}

		return esc_url( home_url( $path ) );
	};

	$html = preg_replace_callback(
		'#\b(href|action)="(/[^"]*)"#',
		static function ( $matches ) use ( $rewrite_path ) {
			return $matches[1] . '="' . $rewrite_path( $matches[2] ) . '"';
		},
		$html
	);

	$html = preg_replace_callback(
		"#\b(href|action)='(/[^']*)'#",
		static function ( $matches ) use ( $rewrite_path ) {
			return $matches[1] . "='" . $rewrite_path( $matches[2] ) . "'";
		},
		$html
	);

	// Header search/cart JS: new URL("/wp-json/...", window.location.origin)
	$html = preg_replace(
		'#new URL\("(/[^"]*)",\s*window\.location\.origin\s*\)#',
		'new URL("' . $base . '$1", window.location.origin)',
		$html
	);

	$html = preg_replace(
		"#new URL\\('(/[^']*)',\\s*window\\.location\\.origin\\s*\\)#",
		"new URL('" . $base . "$1', window.location.origin)",
		$html
	);

	// relativeUrl() in header uses window.location.origin only — include install path.
	$html = str_replace(
		'new URL(value || "#", window.location.origin)',
		'new URL(value || "#", window.location.origin + "' . $base . '")',
		$html
	);

	return $html;
}

/**
 * @param string $block_content Rendered block HTML.
 * @param array  $block       Block metadata.
 */
function ion_subdirectory_fix_render_block( $block_content, $block ) {
	if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
		return $block_content;
	}

	if ( ! is_string( $block_content ) || '' === $block_content || '' === ion_subdirectory_home_path() ) {
		return $block_content;
	}

	if ( empty( $block['blockName'] ) ) {
		return $block_content;
	}

	return ion_subdirectory_rewrite_html( $block_content );
}

add_filter( 'render_block', 'ion_subdirectory_fix_render_block', 10, 2 );

/**
 * Classic content and plugin output that bypasses render_block.
 *
 * @param string $content Post content.
 * @return string
 */
function ion_subdirectory_fix_the_content( $content ) {
	if ( is_admin() || ! is_string( $content ) || '' === ion_subdirectory_home_path() ) {
		return $content;
	}

	return ion_subdirectory_rewrite_html( $content );
}

add_filter( 'the_content', 'ion_subdirectory_fix_the_content', 20 );

/**
 * Ion Promo Control top bar HTML (stored as settings, not template parts).
 *
 * @param string $html Top bar markup.
 * @return string
 */
function ion_subdirectory_fix_promo_topbar( $html ) {
	return ion_subdirectory_rewrite_html( $html );
}

add_filter( 'ion_promo_topbar_html', 'ion_subdirectory_fix_promo_topbar', 10, 1 );
