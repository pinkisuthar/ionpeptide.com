<?php
/**
 * Scenario-specific VeriFactu create request builder.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

use Bluehost\VerifactiApi\Dto\InvoiceCreateRequest;

interface YITH_YWPI_Verifactu_Payload_Builder_Interface {

	/**
	 * Build API request or return null when auto-submit must be skipped.
	 *
	 * @param YITH_YWPI_WooCommerce_Order_Invoice_Context $context Normalized order data.
	 * @return InvoiceCreateRequest|null
	 */
	public function build( YITH_YWPI_WooCommerce_Order_Invoice_Context $context ): ?InvoiceCreateRequest;
}
