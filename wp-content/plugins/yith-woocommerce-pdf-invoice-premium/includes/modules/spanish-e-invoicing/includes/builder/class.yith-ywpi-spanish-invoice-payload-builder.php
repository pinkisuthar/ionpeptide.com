<?php
/**
 * Spanish domestic VeriFactu payloads (F1 standard, F2 simplified).
 *
 * @package YITH\PDFInvoice
 */


defined( 'ABSPATH' ) || exit;

use Bluehost\VerifactiApi\Dto\InvoiceCreateRequest;

/**
 * Case 1 — Spain (billing_country ES).
 *
 * F1: https://www.verifacti.com/en/desarrolladores/ejemplos — Standard invoice (nif + nombre).
 * F2: Simplified invoice (no recipient).
 *
 * F1 vs F2 decision (explicit):
 * 1. Checkout _billing_invoice_type === 'receipt' → F2 when order total ≤ configured threshold.
 * 2. Receipt orders above the threshold are upgraded to F1 (standard invoice with Spanish NIF).
 * 3. Otherwise → F1 (full invoice with Spanish NIF).
 */
class YITH_YWPI_Spanish_Invoice_Payload_Builder extends YITH_YWPI_Abstract_Verifactu_Payload_Builder {

	/**
	 * Check if F2 invoices are enabled
	 *
	 * @return bool
	 */
	public static function allow_f2(): bool {
		return 'yes' === get_option( 'ywpi_spanish_electronic_invoice_allow_f2_invoice', 'no' );
	}
	/**
	 *
	 */
	/**
	 * Get the maximum order total for F2 in euros (admin-configured).
	 *
	 * @return int
	 */
	public static function f2_max_total(): int {
		return (int) get_option( 'ywpi_spanish_electronic_invoice_allow_f2_invoice_threshold', 400 );
	}

	/**
	 * @param YITH_YWPI_WooCommerce_Order_Invoice_Context $context Context.
	 */
	public function build( YITH_YWPI_WooCommerce_Order_Invoice_Context $context ): ?InvoiceCreateRequest {
		$use_f2     = self::allow_f2();
		$max_total  = self::f2_max_total();
		$total      = $context->order_total_numeric;


		if ( $use_f2 && $total <= $max_total ) {
			return $this->build_f2( $context );
		}

		return $this->build_f1( $context );
	}

	/**
	 * F2 simplified — no nif/nombre.
	 *
	 * @param YITH_YWPI_WooCommerce_Order_Invoice_Context $context Context.
	 */
	private function build_f2( YITH_YWPI_WooCommerce_Order_Invoice_Context $context ): InvoiceCreateRequest {
		$lines = $this->line_mapper->build_all_invoice_lines(
			$context,
			YITH_YWPI_Verifactu_Line_Mode::SPANISH_TAXED,
			YITH_YWPI_Verifactu_Line_Mode::SPANISH_TAXED,
			YITH_YWPI_Verifactu_Line_Mode::SPANISH_TAXED
		);

		return $this->create_base_builder( $context, $lines, 'F2' )->buildCreate();
	}

	/**
	 * F1 standard — Spanish NIF + recipient name.
	 *
	 * @param YITH_YWPI_WooCommerce_Order_Invoice_Context $context Context.
	 */
	private function build_f1( YITH_YWPI_WooCommerce_Order_Invoice_Context $context ): ?InvoiceCreateRequest {
		$nif = $this->normalize_spanish_nif( $context->vat_number );
		if ( '' === $nif ) {
			return null;
		}

		$lines = $this->line_mapper->build_all_invoice_lines(
			$context,
			YITH_YWPI_Verifactu_Line_Mode::SPANISH_TAXED,
			YITH_YWPI_Verifactu_Line_Mode::SPANISH_TAXED,
			YITH_YWPI_Verifactu_Line_Mode::SPANISH_TAXED
		);

		return $this->create_base_builder( $context, $lines, 'F1' )
		            ->withRecipient( $nif, $context->recipient_name )
		            ->buildCreate();
	}

	/**
	 * Strip optional ES prefix from stored NIF/CIF.
	 *
	 * @param string $vat Raw meta value.
	 */
	private function normalize_spanish_nif( string $vat ): string {
		$vat = strtoupper( preg_replace( '/\s+/', '', $vat ) );

		if ( 0 === strpos( $vat, 'ES' ) ) {
			$vat = substr( $vat, 2 );
		}

		return $vat;
	}
}
