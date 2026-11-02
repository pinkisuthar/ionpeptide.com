<?php
/**
 * VeriFactu customer scenario identifiers.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

/**
 * Legal scenario bucket for WooCommerce → VeriFactu payload mapping.
 *
 * @see https://www.verifacti.com/en/desarrolladores/ejemplos
 */
final class YITH_YWPI_Verifactu_Scenario {

	/** Spain (AEAT domestic): F1 standard or F2 simplified. */
	public const SPANISH = 'spanish';

	/** EU member state (not ES), B2B (company or freelance). */
	public const EU_B2B = 'eu_b2b';

	/** EU member state (not ES), private consumer (B2C). */
	public const EU_B2C = 'eu_b2c';

	/** Customer outside the EU. */
	public const EXTRA_EU = 'extra_eu';

	/** Country or billing data combination we cannot map safely. */
	public const UNSUPPORTED = 'unsupported';

	/**
	 * @param string $value One of the scenario constants.
	 */
	private function __construct() {
	}
}
