<?php
/**
 * Normalized WooCommerce order line for VeriFactu mapping.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single invoice line amounts and goods/services classification from the order (no tax recalculation).
 */
final class YITH_YWPI_Order_Line_Context {

	/** @var string */
	public $taxable_base;

	/** @var string|null */
	public $tax_rate;

	/** @var string|null */
	public $tax_amount;

	/** @var bool True when the line is a sale of physical goods. */
	public $is_goods;

	/** @var bool True when the line is a provision of services (virtual/downloadable/shipping/fee). */
	public $is_service;

	/**
	 * @param string      $taxable_base Line net amount (base imponible).
	 * @param string|null $tax_rate     VAT rate percentage as string, e.g. "21".
	 * @param string|null $tax_amount   VAT quota (cuota repercutida).
	 * @param bool        $is_goods     Physical product line.
	 * @param bool        $is_service   Service-classified line.
	 */
	public function __construct(
		string $taxable_base,
		?string $tax_rate,
		?string $tax_amount,
		bool $is_goods,
		bool $is_service
	) {
		$this->taxable_base = $taxable_base;
		$this->tax_rate     = $tax_rate;
		$this->tax_amount   = $tax_amount;
		$this->is_goods     = $is_goods;
		$this->is_service   = $is_service;
	}
}
