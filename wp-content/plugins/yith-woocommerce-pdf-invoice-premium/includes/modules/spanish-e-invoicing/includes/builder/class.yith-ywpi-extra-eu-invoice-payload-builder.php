<?php
/**
 * Extra-EU (extracommunity) VeriFactu payloads.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

use Bluehost\PDFInvoices\Modules\SpanishElectronicInvoice\Context\WooCommerceOrderInvoiceContext;
use Bluehost\PDFInvoices\Modules\SpanishElectronicInvoice\Scenario\VerifactuLineMode;
use Bluehost\VerifactiApi\Dto\InvoiceCreateRequest;

/**
 * Case 4 — Customer outside the European Union.
 *
 * Goods (export): clave_regimen 02 + operacion_exenta E2.
 * Services: calificacion_operacion N2.
 * Mixed cart: per-line goods/services flags (same as EU B2B split).
 * Recipient: id_otro with document type + id + codigo_pais; B2B uses company nombre.
 *
 * @see https://www.verifacti.com/en/desarrolladores/ejemplos — Extracommunity sections.
 */
class YITH_YWPI_Extra_Eu_Invoice_Payload_Builder extends YITH_YWPI_Abstract_Verifactu_Payload_Builder {

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

		$lines = $this->line_mapper->build_all_invoice_lines(
			$context,
			YITH_YWPI_Verifactu_Line_Mode::EXTRA_EU_GOODS,
			YITH_YWPI_Verifactu_Line_Mode::EXTRA_EU_SERVICES
		);

		if ( empty( $lines ) ) {
			return null;
		}

		return $this->create_base_builder( $context, $lines, 'F1' )
			->withOtherIdentifier( $this->build_other_identifier( $context ), $context->recipient_name )
			->buildCreate();
	}
}
