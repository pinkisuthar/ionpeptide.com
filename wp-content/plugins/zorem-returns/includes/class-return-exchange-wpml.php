<?php
/**
 * WPML / multilingual compatibility.
 *
 * Two halves make the plugin fully multilingual:
 *
 * 1. String registration — wpml-config.xml registers every customizable,
 *    customer-facing option (email subjects/headings/bodies, guideline
 *    content, reason lists, blocked/disabled messages, restocking-fee label)
 *    with WPML String Translation. WPML then filters get_option() so those
 *    values come back in the current language. Polylang also reads
 *    wpml-config.xml, so the same registrations cover Polylang sites.
 *
 * 2. Language switching for emails — this class. Front-end pages already
 *    render in the visitor's language (WPML switches the locale and filters
 *    the registered options), but emails are built in whatever language the
 *    triggering request happens to run in (e.g. the admin's language when a
 *    request is approved from the dashboard). Every email sender wraps its
 *    build+send in switch_to_order_language() / switch_to_site_language()
 *    + restore_language() so:
 *      - customer emails render in the language the order was placed in, and
 *      - admin emails render in the site's default language.
 *
 * Switching does two things: `wpml_switch_language` makes WPML ST return the
 * translated option values, and switch_to_locale() reloads text domains so
 * the gettext defaults (used when the admin never customized a text) render
 * in the target language too. On non-multilingual sites every method is a
 * no-op, so callers never need to guard.
 *
 * Polylang note: option translation works via wpml-config.xml, and locale
 * switching works when the order language can be resolved (Polylang for
 * WooCommerce sets it); Polylang core does not assign languages to orders,
 * so customer emails then fall back to the site language.
 *
 * @since 1.7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Exchange_WPML {

	/**
	 * Instance of this class.
	 *
	 * @var object Class Instance
	 */
	private static $instance;

	/**
	 * Language code that was active before the current switch, or null when
	 * no WPML language switch is in effect.
	 *
	 * @var string|null
	 */
	private $previous_language = null;

	/**
	 * Whether switch_to_locale() was called and must be undone.
	 *
	 * @var bool
	 */
	private $switched_locale = false;

	/**
	 * Get the class instance.
	 *
	 * @return Return_Exchange_WPML
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Whether WPML is active.
	 *
	 * @return bool
	 */
	public function is_wpml_active() {
		return defined( 'ICL_SITEPRESS_VERSION' ) || did_action( 'wpml_loaded' );
	}

	/**
	 * Whether Polylang is active.
	 *
	 * @return bool
	 */
	public function is_polylang_active() {
		return function_exists( 'pll_current_language' );
	}

	/**
	 * Resolve the language code an order was placed in.
	 *
	 * Sources, in order:
	 *  - `wpml_language` order meta (set by WooCommerce Multilingual, HPOS-safe)
	 *  - WPML's language record for the shop_order post (legacy storage)
	 *  - Polylang's post language (set by Polylang for WooCommerce)
	 *
	 * @param WC_Order|int $order Order object or ID.
	 * @return string Language code (e.g. 'tr'), or '' when unknown.
	 */
	public function get_order_language( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return '';
		}

		$lang = (string) $order->get_meta( 'wpml_language', true );

		if ( '' === $lang && $this->is_wpml_active() ) {
			$lang = (string) apply_filters(
				'wpml_element_language_code', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's official API hook, not ours.
				null,
				array(
					'element_id'   => $order->get_id(),
					'element_type' => 'shop_order',
				)
			);
		}

		if ( '' === $lang && function_exists( 'pll_get_post_language' ) ) {
			$lang = (string) pll_get_post_language( $order->get_id() );
		}

		/**
		 * Filter the language code used to render customer-facing emails for an order.
		 *
		 * @param string   $lang  Language code, '' when it could not be resolved.
		 * @param WC_Order $order The order.
		 */
		return (string) apply_filters( 'zorem_rex_order_email_language', $lang, $order );
	}

	/**
	 * Switch the active language + locale to the language the order was
	 * placed in. Used before building customer-facing emails so both the
	 * WPML-translated option strings and the gettext defaults render in the
	 * customer's language. Always pair with restore_language().
	 *
	 * @param WC_Order|int $order Order object or ID.
	 */
	public function switch_to_order_language( $order ) {
		$this->switch_language( $this->get_order_language( $order ) );
	}

	/**
	 * Switch the active language + locale to the site's default language.
	 * Used before building admin-facing emails so they don't inherit the
	 * customer's front-end language. Always pair with restore_language().
	 */
	public function switch_to_site_language() {
		$lang = '';
		if ( $this->is_wpml_active() ) {
			$lang = (string) apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's official API hook, not ours.
		} elseif ( function_exists( 'pll_default_language' ) ) {
			$lang = (string) pll_default_language( 'slug' );
		}
		$this->switch_language( $lang );
	}

	/**
	 * Restore the language + locale that were active before the last switch.
	 * Safe to call even when no switch happened.
	 */
	public function restore_language() {
		if ( $this->switched_locale && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
		}
		$this->switched_locale = false;

		if ( null !== $this->previous_language ) {
			do_action( 'wpml_switch_language', $this->previous_language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's official API hook, not ours.
			$this->previous_language = null;
		}
	}

	/**
	 * Perform the actual switch. No-op when $lang is empty or the site is not
	 * multilingual.
	 *
	 * @param string $lang Language code.
	 */
	private function switch_language( $lang ) {
		$lang = (string) $lang;
		if ( '' === $lang ) {
			return;
		}

		// WPML: switch the ST language so registered option strings translate.
		if ( $this->is_wpml_active() ) {
			$current = (string) apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's official API hook, not ours.
			if ( '' !== $current && $current !== $lang ) {
				$this->previous_language = $current;
				do_action( 'wpml_switch_language', $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's official API hook, not ours.
			}
		}

		// Switch the WP locale so gettext defaults reload in the target language.
		$locale = $this->get_locale_for_language( $lang );
		if ( $locale && function_exists( 'switch_to_locale' ) && determine_locale() !== $locale ) {
			$this->switched_locale = (bool) switch_to_locale( $locale );
		}
	}

	/**
	 * Map a language code to its full WP locale (e.g. 'tr' => 'tr_TR').
	 *
	 * @param string $lang Language code.
	 * @return string Locale, or '' when unknown.
	 */
	private function get_locale_for_language( $lang ) {
		if ( $this->is_wpml_active() ) {
			$languages = apply_filters( 'wpml_active_languages', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's official API hook, not ours.
			if ( is_array( $languages ) && isset( $languages[ $lang ]['default_locale'] ) ) {
				return (string) $languages[ $lang ]['default_locale'];
			}
		}

		if ( function_exists( 'PLL' ) && isset( PLL()->model ) ) {
			$language = PLL()->model->get_language( $lang );
			if ( $language && ! empty( $language->locale ) ) {
				return (string) $language->locale;
			}
		}

		// Value may already be a full locale (e.g. via the override filter).
		return ( strlen( $lang ) > 3 ) ? $lang : '';
	}
}
