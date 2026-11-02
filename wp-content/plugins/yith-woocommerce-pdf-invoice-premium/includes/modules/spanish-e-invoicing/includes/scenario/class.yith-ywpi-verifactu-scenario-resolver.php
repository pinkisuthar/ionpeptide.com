<?php
/**
 * Maps WooCommerce billing data to a VeriFactu legal scenario.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves which VeriFactu example family applies to an order.
 *
 * @see https://www.verifacti.com/en/desarrolladores/ejemplos
 */
class YITH_YWPI_Verifactu_Scenario_Resolver {

	/**
	 * @param WC_Order $order WooCommerce order.
	 * @return string One of YITH_YWPI_Verifactu_Scenario constants.
	 */
	public function resolve( $order ): string {
		$country = strtoupper( (string) $order->get_billing_country() );

		if ( '' === $country ) {
			return YITH_YWPI_Verifactu_Scenario::UNSUPPORTED;
		}

		if ( 'ES' === $country ) {
			return YITH_YWPI_Verifactu_Scenario::SPANISH;
		}

		if ( YITH_YWPI_Eu_Country_Helper::is_non_spanish_eu_country( $country ) ) {
			$receiver = (string) $order->get_meta( '_billing_receiver_type' );

			if ( in_array( $receiver, array( 'company', 'freelance' ), true ) ) {
				return YITH_YWPI_Verifactu_Scenario::EU_B2B;
			}

			if ( 'private' === $receiver || '' === $receiver ) {
				return YITH_YWPI_Verifactu_Scenario::EU_B2C;
			}

			return YITH_YWPI_Verifactu_Scenario::UNSUPPORTED;
		}

		if ( ! YITH_YWPI_Eu_Country_Helper::is_eu_country( $country ) ) {
			return YITH_YWPI_Verifactu_Scenario::EXTRA_EU;
		}

		return YITH_YWPI_Verifactu_Scenario::UNSUPPORTED;
	}
}
