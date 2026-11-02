<?php
/**
 * Shared header fields for VeriFactu InvoiceBuilder.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;


use Bluehost\VerifactiApi\Builder\InvoiceBuilder;
use Bluehost\VerifactiApi\Dto\InvoiceLine;
use Bluehost\VerifactiApi\Dto\OtherIdentifier;

abstract class YITH_YWPI_Abstract_Verifactu_Payload_Builder implements YITH_YWPI_Verifactu_Payload_Builder_Interface {

	/** @var YITH_YWPI_WooCommerce_Order_Line_Mapper */
	protected $line_mapper;

	public function __construct( ?YITH_YWPI_WooCommerce_Order_Line_Mapper $line_mapper = null ) {
		$this->line_mapper = $line_mapper ?: new YITH_YWPI_WooCommerce_Order_Line_Mapper();
	}

	/**
	 * Base F1 header: serie, numero, fecha, descripcion, importe_total, lines.
	 *
	 * @param YITH_YWPI_WooCommerce_Order_Invoice_Context $context Context.
	 * @param InvoiceLine[]                  $lines   VeriFactu lines.
	 */
	protected function create_base_builder(
		YITH_YWPI_WooCommerce_Order_Invoice_Context $context,
		array $lines,
		string $invoice_type = 'F1'
	): InvoiceBuilder {
		$builder = ( new InvoiceBuilder() )
			->withSeries( $context->series )
			->withNumber( $context->number )
			->withIssueDate( $context->issue_date )
			->withInvoiceType( $invoice_type )
			->withDescription( $context->description )
			->withTotalAmount( $context->total_amount );

		foreach ( $lines as $line ) {
			$builder->addLine( $line );
		}

		return $builder;
	}

	/**
	 * id_otro block for EU private / non-EU (document types 03, 04, 06).
	 *
	 * @param YITH_YWPI_WooCommerce_Order_Invoice_Context $context Context.
	 */
	protected function build_other_identifier( YITH_YWPI_WooCommerce_Order_Invoice_Context $context ): OtherIdentifier {
		return new OtherIdentifier(
			$context->billing_country,
			$context->document_type,
			$context->resolve_id_otro_identifier()
		);
	}

	/**
	 * VIES identifier: id_type 02, country + VAT number.
	 *
	 * @param YITH_YWPI_WooCommerce_Order_Invoice_Context $context Context.
	 */
	protected function build_vies_identifier( YITH_YWPI_WooCommerce_Order_Invoice_Context $context ): OtherIdentifier {
		$vat = strtoupper( preg_replace( '/\s+/', '', $context->vat_number ) );
		$country = $context->billing_country;

		// Use full VIES string when it already includes the country prefix.
		if ( 0 !== strpos( $vat, $country ) ) {
			$vat = $country . $vat;
		}

		return new OtherIdentifier( $country, '02', $vat );
	}
}
