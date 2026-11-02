<?php
/**
 * EU B2C intra-community VeriFactu payloads.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;


use Bluehost\VerifactiApi\Dto\InvoiceCreateRequest;

/**
 * Case 3 — Non-Spanish EU private consumer.
 *
 * Below OSS / distance-sales threshold: Spanish VAT from order lines
 * — https://www.verifacti.com/en/desarrolladores/ejemplos (B2C below threshold).
 *
 * Above threshold (OSS): clave_regimen 17 + calificacion_operacion N2, no Spanish VAT fields
 * — (B2C OSS). Default when the order has no line taxes; filter can override.
 *
 * Recipient: id_otro with _billing_document_type (03/04/06) + _billing_document_number.
 */
class YITH_YWPI_Eu_B2c_Invoice_Payload_Builder extends YITH_YWPI_Abstract_Verifactu_Payload_Builder {

	/**
	 * @param YITH_YWPI_WooCommerce_Order_Invoice_Context $context Context.
	 */
	public function build( YITH_YWPI_WooCommerce_Order_Invoice_Context $context ): ?InvoiceCreateRequest {
		if ( ! $context->has_valid_foreign_document_type() || '' === $context->resolve_id_otro_identifier() ) {
			return null;
		}

		if ( '' === trim( $context->recipient_name ) ) {
			return null;
		}

		// OSS vs below-threshold is resolved by VerifactuInvoicePayloadFactory via $context->eu_b2c_oss.
		if ( true === $context->eu_b2c_oss ) {
			$lines = $this->line_mapper->build_all_invoice_lines(
				$context,
				YITH_YWPI_Verifactu_Line_Mode::EU_B2C_OSS,
				YITH_YWPI_Verifactu_Line_Mode::EU_B2C_OSS,
				YITH_YWPI_Verifactu_Line_Mode::EU_B2C_OSS
			);
		} else {
			// Below threshold: Spanish VAT from order on every line (B2C below threshold example).
			$lines = $this->line_mapper->build_all_invoice_lines(
				$context,
				YITH_YWPI_Verifactu_Line_Mode::EU_B2C_BELOW_THRESHOLD,
				YITH_YWPI_Verifactu_Line_Mode::EU_B2C_BELOW_THRESHOLD,
				YITH_YWPI_Verifactu_Line_Mode::EU_B2C_BELOW_THRESHOLD
			);
		}

		if ( empty( $lines ) ) {
			return null;
		}

		return $this->create_base_builder( $context, $lines, 'F1' )
			->withOtherIdentifier( $this->build_other_identifier( $context ), $context->recipient_name )
			->buildCreate();
	}
}
