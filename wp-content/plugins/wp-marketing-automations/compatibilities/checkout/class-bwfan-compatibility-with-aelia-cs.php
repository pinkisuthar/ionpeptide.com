<?php
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'BWFAN_Compatibility_With_Aelia_CS' ) ) {
	class BWFAN_Compatibility_With_Aelia_CS {

		/**
		 * Cookie name used by Aelia Currency Switcher for selected currency
		 */
		const AELIA_CURRENCY_COOKIE = 'aelia_cs_selected_currency';

		/**
		 * Cookie name used by Aelia Currency Switcher for customer country
		 */
		const AELIA_COUNTRY_COOKIE = 'aelia_customer_country';

		/**
		 * Stored currency cookie value from the incoming request
		 *
		 * @var string|null
		 */
		private $saved_currency_cookie = null;

		/**
		 * Stored country cookie value from the incoming request
		 *
		 * @var string|null
		 */
		private $saved_country_cookie = null;

		public function __construct() {
			add_filter( 'bwfan_ab_cart_total_base', [ $this, 'save_base_price_in_database' ] );
			add_filter( 'bwfan_abandoned_cart_restore_link', [ $this, 'add_currency_parameter_in_url' ], 99, 2 );
			add_action( 'bwfan_before_abandoned_cart_ajax_response', [ $this, 'restore_currency_cookie' ] );

			/** Inject country from checkout_fields_data so Aelia can derive currency correctly */
			add_filter( 'wc_aelia_cs_customer_country', [ $this, 'inject_country_from_checkout_fields' ], 999, 2 );
			/** Override currency when country is not in POST — prevents geolocation from overwriting user's choice */
			add_filter( 'woocommerce_currency', [ $this, 'force_currency_for_abandoned_cart_ajax' ], 999 );

			/** Capture the original cookie early — before Aelia's woocommerce_init overwrites it */
			$this->capture_currency_cookie();
		}

		public function save_base_price_in_database( $price ) {
			$price = $this->get_price_in_currency( $price, get_option( 'woocommerce_currency' ), get_woocommerce_currency() );

			return wc_format_decimal( $price, wc_get_price_decimals() );
		}

		/**
		 * Basic integration with WooCommerce Currency Switcher, developed by Aelia
		 * (http://aelia.co). This method can be used by any 3rd party plugin to
		 * return prices converted to the active currency.
		 *
		 * Need a consultation? Find us on Codeable: https://aelia.co/hire_us
		 *
		 * @param double price The source price.
		 * @param string to_currency The target currency. If empty, the active currency
		 * will be taken.
		 * @param string from_currency The source currency. If empty, WooCommerce base
		 * currency will be taken.
		 *
		 * @return double The price converted from source to destination currency.
		 * @author Aelia <support@aelia.co>
		 * @link https://aelia.co
		 */
		public function get_price_in_currency( $price, $to_currency = null, $from_currency = null ) {
			// If source currency is not specified, take the shop's base currency as a default
			if ( empty( $from_currency ) ) {
				$from_currency = get_option( 'woocommerce_currency' );
			}
			// If target currency is not specified, take the active currency as a default.
			// The Currency Switcher sets this currency automatically, based on the context. Other
			// plugins can also override it, based on their own custom criteria, by implementing
			// a filter for the "woocommerce_currency" hook.
			//
			// For example, a subscription plugin may decide that the active currency is the one
			// taken from a previous subscription, because it's processing a renewal, and such
			// renewal should keep the original prices, in the original currency.
			if ( empty( $to_currency ) ) {
				$to_currency = get_woocommerce_currency();
			}

			// Call the currency conversion filter. Using a filter allows for loose coupling. If the
			// Aelia Currency Switcher is not installed, the filter call will return the original
			// amount, without any conversion being performed. Your plugin won't even need to know if
			// the multi-currency plugin is installed or active
			return apply_filters( 'wc_aelia_cs_convert', $price, $from_currency, $to_currency );
		}

		public function add_currency_parameter_in_url( $url, $token ) {
			global $wpdb;
			$currency = $wpdb->get_var( $wpdb->prepare( "SELECT currency FROM {$wpdb->prefix}bwfan_abandonedcarts WHERE `token` = %s", $token ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$url = add_query_arg( array(
				'aelia_cs_currency' => $currency,
			), $url );

			return $url;
		}

		/**
		 * Capture the original currency cookie value from the incoming request.
		 * Called in constructor — at this point $_COOKIE still holds the browser-sent value
		 * because Aelia's setcookie() only queues a Set-Cookie header, it doesn't modify $_COOKIE.
		 */
		public function capture_currency_cookie() {
			if ( isset( $_COOKIE[ self::AELIA_CURRENCY_COOKIE ] ) ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$this->saved_currency_cookie = sanitize_text_field( wp_unslash( $_COOKIE[ self::AELIA_CURRENCY_COOKIE ] ) ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			}

			if ( isset( $_COOKIE[ self::AELIA_COUNTRY_COOKIE ] ) ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$this->saved_country_cookie = sanitize_text_field( wp_unslash( $_COOKIE[ self::AELIA_COUNTRY_COOKIE ] ) ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			}
		}

		/**
		 * Inject customer country from checkout_fields_data so Aelia can derive currency.
		 *
		 * Aelia only reads billing_country/shipping_country from top-level $_POST during
		 * WooCommerce checkout/update_order_review. The abandoned cart AJAX sends them
		 * inside checkout_fields_data, so Aelia never sees them. This filter provides
		 * the country when available.
		 *
		 * @param string $result Country code Aelia computed.
		 * @param object $aelia WC_Aelia_CurrencySwitcher instance.
		 * @return string Country code to use.
		 */
		public function inject_country_from_checkout_fields( $result, $aelia ) {
			if ( ! $this->is_abandoned_cart_ajax() ) {
				return $result;
			}

			$country = $this->get_country_from_checkout_fields();
			if ( ! empty( $country ) ) {
				return $country;
			}

			return $result;
		}

		/**
		 * Force the currency to the saved cookie value during abandoned cart AJAX when
		 * country is not in POST. Prevents Aelia from overwriting with geolocation.
		 *
		 * @param string $currency Current currency from filter chain.
		 * @return string Currency to use.
		 */
		public function force_currency_for_abandoned_cart_ajax( $currency ) {
			if ( ! $this->is_abandoned_cart_ajax() || null === $this->saved_currency_cookie ) {
				return $currency;
			}

			if ( ! $this->is_force_currency_by_country_active() ) {
				return $currency;
			}

			/** If country is in checkout_fields_data, Aelia will use it via our filter — don't override */
			if ( ! empty( $this->get_country_from_checkout_fields() ) ) {
				return $currency;
			}

			return $this->saved_currency_cookie;
		}

		/**
		 * Restore Aelia cookies before the abandoned cart AJAX response is sent.
		 *
		 * When Aelia's "Force Currency by Country" is enabled, it re-derives the currency
		 * from geolocation on every request (including AJAX). During bwfan_insert_abandoned_cart,
		 * billing_country may be in checkout_fields_data (not top-level POST), so Aelia
		 * doesn't see it and falls back to geolocation.
		 *
		 * Aelia's get_customer_country() calls store_customer_country() BEFORE our
		 * wc_aelia_cs_customer_country filter runs, so the aelia_customer_country cookie
		 * gets overwritten with a potentially wrong value (geolocation instead of form).
		 * We restore both the currency and country cookies here.
		 */
		public function restore_currency_cookie() {
			if ( ! $this->is_force_currency_by_country_active() ) {
				return;
			}

			/** Skip if billing_country is at top-level (standard checkout — Aelia handles it) */
			if ( isset( $_POST['billing_country'] ) && ! empty( $_POST['billing_country'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
				return;
			}

			$country_from_fields = $this->get_country_from_checkout_fields();

			if ( ! empty( $country_from_fields ) ) {
				/**
				 * Country IS in checkout_fields_data — Aelia's store_customer_country() ran
				 * BEFORE our wc_aelia_cs_customer_country filter, so the cookie was set to
				 * the geo-derived value. Overwrite it with the actual form country.
				 */
				setcookie( self::AELIA_COUNTRY_COOKIE, $country_from_fields, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false ); //phpcs:ignore
			} else {
				/**
				 * Country is NOT in checkout_fields_data — Aelia fell back to geolocation.
				 * Restore both cookies to the values the browser originally sent so the
				 * user's prior selection is preserved.
				 */
				if ( null !== $this->saved_currency_cookie ) {
					setcookie( self::AELIA_CURRENCY_COOKIE, $this->saved_currency_cookie, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false ); //phpcs:ignore
				}
				if ( null !== $this->saved_country_cookie ) {
					setcookie( self::AELIA_COUNTRY_COOKIE, $this->saved_country_cookie, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false ); //phpcs:ignore
				}
			}
		}

		/**
		 * Check if the current request is the abandoned cart insert AJAX.
		 *
		 * @return bool
		 */
		private function is_abandoned_cart_ajax() {
			return wp_doing_ajax() && isset( $_REQUEST['wc-ajax'] ) && 'bwfan_insert_abandoned_cart' === sanitize_text_field( wp_unslash( $_REQUEST['wc-ajax'] ) ); //phpcs:ignore WordPress.Security.NonceVerification
		}

		/**
		 * Get billing or shipping country from checkout_fields_data based on Aelia setting.
		 *
		 * @return string Country code or empty string.
		 */
		private function get_country_from_checkout_fields() {
			$fields = isset( $_POST['checkout_fields_data'] ) && is_array( $_POST['checkout_fields_data'] ) ? wp_unslash( $_POST['checkout_fields_data'] ) : array(); //phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput

			if ( empty( $fields ) ) {
				return '';
			}

			$use_shipping = $this->get_force_currency_setting() === 'shipping_country';

			if ( $use_shipping && ! empty( $fields['shipping_country'] ) ) {
				return sanitize_text_field( $fields['shipping_country'] );
			}

			if ( ! empty( $fields['billing_country'] ) ) {
				return sanitize_text_field( $fields['billing_country'] );
			}

			return '';
		}

		/**
		 * Get Aelia's force_currency_by_country setting value.
		 *
		 * @return string 'billing_country', 'shipping_country', or 'disabled'
		 */
		private function get_force_currency_setting() {
			if ( class_exists( 'WC_Aelia_CurrencySwitcher' ) && method_exists( 'WC_Aelia_CurrencySwitcher', 'instance' ) ) {
				$aelia = WC_Aelia_CurrencySwitcher::instance();
				if ( method_exists( $aelia, 'force_currency_by_country' ) ) {
					return $aelia->force_currency_by_country();
				}
			}

			$settings = get_option( 'wc_aelia_currency_switcher', array() );
			return isset( $settings['force_currency_by_country'] ) ? $settings['force_currency_by_country'] : 'disabled';
		}

		/**
		 * Check if Aelia's "Force Currency by Country" setting is active
		 *
		 * @return bool
		 */
		private function is_force_currency_by_country_active() {
			$setting = $this->get_force_currency_setting();

			return ! empty( $setting ) && 'disabled' !== $setting;
		}

	}

	new BWFAN_Compatibility_With_Aelia_CS();
}
