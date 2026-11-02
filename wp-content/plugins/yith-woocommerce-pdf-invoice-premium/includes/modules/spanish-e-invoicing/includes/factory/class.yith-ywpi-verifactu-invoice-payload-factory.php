<?php
/**
 * Facade: resolves scenario and delegates to payload builders.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;


use Bluehost\VerifactiApi\Dto\InvoiceCreateRequest;
use Bluehost\VerifactiApi\Dto\InvoiceModifyRequest;

/**
 * Entry point for building Verifacti InvoiceCreateRequest from a WooCommerce order.
 */
class YITH_YWPI_Verifactu_Invoice_Payload_Factory {

	/** @var YITH_YWPI_Verifactu_Scenario_Resolver */
	private $resolver;

	/** @var YITH_YWPI_Spanish_Invoice_Payload_Builder */
	private $spanish_builder;

	/** @var YITH_YWPI_Eu_B2b_Invoice_Payload_Builder */
	private $eu_b2b_builder;

	/** @var YITH_YWPI_Eu_B2c_Invoice_Payload_Builder */
	private $eu_b2c_builder;

	/** @var YITH_YWPI_Extra_Eu_Invoice_Payload_Builder */
	private $extra_eu_builder;

	/** @var string|null Last skip reason (YITH_YWPI_Verifactu_Skip_Reason). */
	private $last_skip_reason;

	public function __construct(
		?YITH_YWPI_Verifactu_Scenario_Resolver $resolver = null,
		?YITH_YWPI_Spanish_Invoice_Payload_Builder $spanish_builder = null,
		?YITH_YWPI_Eu_B2b_Invoice_Payload_Builder $eu_b2b_builder = null,
		?YITH_YWPI_Eu_B2c_Invoice_Payload_Builder $eu_b2c_builder = null,
		?YITH_YWPI_Extra_Eu_Invoice_Payload_Builder $extra_eu_builder = null
	) {
		$this->resolver         = $resolver ?: new YITH_YWPI_Verifactu_Scenario_Resolver();
		$this->spanish_builder  = $spanish_builder ?: new YITH_YWPI_Spanish_Invoice_Payload_Builder();
		$this->eu_b2b_builder   = $eu_b2b_builder ?: new YITH_YWPI_Eu_B2b_Invoice_Payload_Builder();
		$this->eu_b2c_builder   = $eu_b2c_builder ?: new YITH_YWPI_Eu_B2c_Invoice_Payload_Builder();
		$this->extra_eu_builder = $extra_eu_builder ?: new YITH_YWPI_Extra_Eu_Invoice_Payload_Builder();
	}

	/**
	 * @param WC_Order $order Order.
	 * @param YITH_Invoice  $invoice The document .
	 * @return InvoiceCreateRequest|null Null when auto-submit must not run.
	 */
	public function build_create_request( WC_Order $order , $invoice = null): ?InvoiceCreateRequest {
		$this->last_skip_reason = null;

		$scenario = $this->resolver->resolve( $order );

		if ( YITH_YWPI_Verifactu_Scenario::UNSUPPORTED === $scenario ) {
			$this->last_skip_reason = YITH_YWPI_Verifactu_Skip_Reason::UNSUPPORTED_SCENARIO;
			return null;
		}

		$context = YITH_YWPI_WooCommerce_Order_Invoice_Context::from_order( $order, $invoice );

		if ( in_array( $scenario, array( YITH_YWPI_Verifactu_Scenario::EU_B2C, YITH_YWPI_Verifactu_Scenario::EXTRA_EU ), true ) ) {
			if ( ! $context->has_valid_foreign_document_type() ) {
				$this->last_skip_reason = YITH_YWPI_Verifactu_Skip_Reason::MISSING_DOCUMENT;
				return null;
			}
		}

		if ( YITH_YWPI_Verifactu_Scenario::EU_B2C === $scenario ) {
			/**
			 * Filter whether this EU B2C order uses OSS (above threshold) or Spanish VAT (below).
			 *
			 * Default: OSS when the order has no Spanish VAT (e.g. destination-country tax above
			 * OSS threshold); below threshold when Spanish tax rates are applied on order lines.
			 *
			 * @param bool      $oss   true = OSS (clave_regimen 17), false = below threshold.
			 * @param WC_Order $order Order.
			 */
			$oss = apply_filters(
				'ywpi_verifactu_eu_b2c_oss',
				! $context->has_spanish_line_taxes(),
				$order
			);
			if ( null === $oss ) {
				$this->last_skip_reason = YITH_YWPI_Verifactu_Skip_Reason::OSS_UNDETERMINED;
				return null;
			}
			$context->eu_b2c_oss = (bool) $oss;
			if ( $context->eu_b2c_oss ) {
				$context->apply_eu_b2c_oss_amounts();
			}
		}

		if ( YITH_YWPI_Verifactu_Scenario::EU_B2B === $scenario ) {
			$context->apply_net_line_amounts();
		}

		if ( YITH_YWPI_Verifactu_Scenario::EXTRA_EU === $scenario ) {
			$context->apply_extra_eu_amounts();
		}

		$request = null;

		switch ( $scenario ) {
			case YITH_YWPI_Verifactu_Scenario::SPANISH:
				$request = $this->spanish_builder->build( $context );
				if ( null === $request
					&& YITH_YWPI_Spanish_Invoice_Payload_Builder::allow_f2()
					&& ( $context->order_total_numeric > YITH_YWPI_Spanish_Invoice_Payload_Builder::f2_max_total() ||
					     $context->order_total_numeric > 3000
				     )
				) {
					$this->last_skip_reason = YITH_YWPI_Verifactu_Skip_Reason::F2_OVER_LIMIT;
				}
				break;
			case YITH_YWPI_Verifactu_Scenario::EU_B2B:
				$request = $this->eu_b2b_builder->build( $context );
				break;
			case YITH_YWPI_Verifactu_Scenario::EU_B2C:
				$request = $this->eu_b2c_builder->build( $context );
				break;
			case YITH_YWPI_Verifactu_Scenario::EXTRA_EU:
				$request = $this->extra_eu_builder->build( $context );
				break;
		}

		if ( null === $request && null === $this->last_skip_reason ) {
			$this->last_skip_reason = YITH_YWPI_Verifactu_Skip_Reason::MISSING_PAYLOAD;
		}

		return $request;
	}

	/**
	 * Build PUT /verifactu/modify payload using stored invoice identifiers and current order data.
	 *
	 * @param WC_Order $order Order.
	 * @param YITH_Invoice $invoice Invoice.
	 *
	 * @return InvoiceModifyRequest|null Null when amend cannot run.
	 */
	public function build_modify_request( WC_Order $order, $invoice = null ): ?InvoiceModifyRequest {
		$create = $this->build_create_request( $order, $invoice );
		if ( null === $create ) {
			return null;
		}

		$serie = (string) $order->get_meta( '_ywpi_verifacti_serie' );
		$number = (string) $order->get_meta( '_ywpi_verifacti_number' );
		$fecha  = (string) $order->get_meta( '_ywpi_verifacti_fecha_expedicion' );

		if ( '' === $number || '' === $fecha ) {
			$this->last_skip_reason = YITH_YWPI_Verifactu_Skip_Reason::MISSING_PAYLOAD;

			return null;
		}

		return $create->toModifyRequest( $serie, $number, $fecha, 'N' );
	}

	/**
	 * @return string|null YITH_YWPI_Verifactu_Skip_Reason constant.
	 */
	public function get_last_skip_reason(): ?string {
		return $this->last_skip_reason;
	}
}
