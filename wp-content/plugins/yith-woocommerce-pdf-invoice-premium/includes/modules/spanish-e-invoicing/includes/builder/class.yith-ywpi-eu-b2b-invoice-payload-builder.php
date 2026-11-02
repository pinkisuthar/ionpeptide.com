<?php
/**
 * EU B2B intra-community VeriFactu payloads.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

use Bluehost\PDFInvoices\Modules\SpanishElectronicInvoice\Context\WooCommerceOrderInvoiceContext;
use Bluehost\PDFInvoices\Modules\SpanishElectronicInvoice\Scenario\VerifactuLineMode;
use Bluehost\VerifactiApi\Dto\InvoiceCreateRequest;

/**
 * Case 2 — Non-Spanish EU customer, company or freelance (B2B).
 *
 * Goods: operacion_exenta E5 — https://www.verifacti.com/en/desarrolladores/ejemplos (B2B goods).
 * Services: calificacion_operacion N2 — (B2B services).
 * Mixed cart: each line mapped with its own mode (goods → E5, services → N2).
 * Recipient: id_otro id_type 02 (VIES) + nombre.
 */
class YITH_YWPI_Eu_B2b_Invoice_Payload_Builder extends YITH_YWPI_Abstract_Verifactu_Payload_Builder {

	/**
	 * @param YITH_YWPI_WooCommerce_Order_Invoice_Context $context Context.
	 */
	public function build( YITH_YWPI_WooCommerce_Order_Invoice_Context $context ): ?InvoiceCreateRequest {
		if ( '' === trim( $context->vat_number ) ) {
			return null;
		}

		if ( '' === trim( $context->recipient_name ) ) {
			return null;
		}

		$lines = $this->line_mapper->build_all_invoice_lines(
			$context,
			YITH_YWPI_Verifactu_Line_Mode::EU_B2B_GOODS,
			YITH_YWPI_Verifactu_Line_Mode::EU_B2B_SERVICES
		);

		if ( empty( $lines ) ) {
			return null;
		}

		return $this->create_base_builder( $context, $lines, 'F1' )
			->withOtherIdentifier( $this->build_vies_identifier( $context ), $context->recipient_name )
			->buildCreate();
	}
}
