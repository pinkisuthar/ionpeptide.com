<?php
/**
 * EU country helpers for VeriFactu scenario resolution.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

/**
 * Determines whether a billing country is in the EU (excluding Spain when needed).
 */
final class YITH_YWPI_Eu_Country_Helper {

	/**
	 * ISO 3166-1 alpha-2 codes for EU member states (WooCommerce-aligned list).
	 *
	 * @return string[]
	 */
	public static function get_eu_country_codes(): array {
		if ( function_exists( 'WC' ) && WC() && WC()->countries ) {
			return WC()->countries->get_european_union_countries();
		}

		return array(
			'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
			'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL',
			'PT', 'RO', 'SE', 'SI', 'SK',
		);
	}

	/**
	 * @param string $country ISO 2-letter country code.
	 */
	public static function is_eu_country( string $country ): bool {
		$country = strtoupper( trim( $country ) );

		return in_array( $country, self::get_eu_country_codes(), true );
	}

	/**
	 * EU country other than Spain.
	 *
	 * @param string $country ISO 2-letter country code.
	 */
	public static function is_non_spanish_eu_country( string $country ): bool {
		$country = strtoupper( trim( $country ) );

		return 'ES' !== $country && self::is_eu_country( $country );
	}
}
