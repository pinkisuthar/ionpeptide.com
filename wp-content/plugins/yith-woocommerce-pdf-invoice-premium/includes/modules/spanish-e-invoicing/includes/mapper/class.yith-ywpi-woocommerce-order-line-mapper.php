<?php
/**
 * Maps WooCommerce order items to VeriFactu line DTOs and InvoiceLine builders.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;


use Bluehost\VerifactiApi\Builder\InvoiceLineBuilder;
use Bluehost\VerifactiApi\Dto\InvoiceLine;

/**
 * Uses order line totals/taxes as-is (no tax recalculation).
 *
 * Goods vs services rules:
 * - line_item + product: goods unless virtual OR downloadable (then service).
 * - shipping, fee: service.
 * - Mixed carts: each line keeps its own classification (per-line flags in EU B2B).
 */
class YITH_YWPI_WooCommerce_Order_Line_Mapper {

	/**
	 * @param WC_Order $order Order.
	 * @return YITH_YWPI_Order_Line_Context[]
	 */
	public function map_order_lines( WC_Order $order ): array {
		$lines = array();

		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
			if ( ! $item instanceof WC_Order_Item ) {
				continue;
			}

			$lines[] = $this->map_item_to_context( $item );
		}

		return $lines;
	}

	/**
	 * @param YITH_YWPI_Order_Line_Context $line   Normalized line.
	 * @param string           $mode   VerifactuLineMode constant.
	 */
	public function build_invoice_line( YITH_YWPI_Order_Line_Context $line, string $mode ): InvoiceLine {
		$builder = ( new InvoiceLineBuilder() )->withTaxableBase( $line->taxable_base );

		switch ( $mode ) {
			case YITH_YWPI_Verifactu_Line_Mode::SPANISH_TAXED:
			case YITH_YWPI_Verifactu_Line_Mode::EU_B2C_BELOW_THRESHOLD:
				// Standard / B2C below threshold: Spanish VAT from order.
				if ( null !== $line->tax_rate && null !== $line->tax_amount ) {
					$builder->withTax( $line->tax_rate, $line->tax_amount );
				}
				break;

			case YITH_YWPI_Verifactu_Line_Mode::EU_B2B_GOODS:
				// https://www.verifacti.com/en/desarrolladores/ejemplos — B2B Intra-Community goods (E5).
				$builder->asExempt( 'E5' );
				break;

			case YITH_YWPI_Verifactu_Line_Mode::EU_B2B_SERVICES:
			case YITH_YWPI_Verifactu_Line_Mode::EXTRA_EU_SERVICES:
				// B2B services / extra-EU services: N2.
				$builder->withOperationClassification( 'N2' );
				break;

			case YITH_YWPI_Verifactu_Line_Mode::EU_B2C_OSS:
				// B2C OSS: regime 17 + N2, no tipo_impositivo / cuota_repercutida.
				$builder->withRegimeKey( '17' )->withOperationClassification( 'N2' );
				break;

			case YITH_YWPI_Verifactu_Line_Mode::EXTRA_EU_GOODS:
				// Export: clave_regimen 02 + operacion_exenta E2.
				$builder->withRegimeKey( '02' )->asExempt( 'E2' );
				break;
		}

		return $builder->build();
	}

	/**
	 * Map all order lines using per-line mode (mixed goods/services) or a single mode.
	 *
	 * Lines are consolidated before building to stay within Verifacti's 12-line limit:
	 * - taxed scenarios: one line per distinct VAT rate (tipo_impositivo);
	 * - exempt / OSS / N2 scenarios: one line per line mode (goods vs services, etc.).
	 *
	 * @param YITH_YWPI_WooCommerce_Order_Invoice_Context $context Order context.
	 * @param string                         $goods_mode    Mode when line is goods.
	 * @param string                         $services_mode Mode when line is service.
	 * @param string|null                    $uniform_mode  When set, applied to every line.
	 * @return InvoiceLine[]
	 */
	public function build_all_invoice_lines(
		YITH_YWPI_WooCommerce_Order_Invoice_Context $context,
		string $goods_mode,
		string $services_mode,
		?string $uniform_mode = null
	): array {
		$grouped = array();

		foreach ( $context->lines as $line ) {

			$mode = $this->resolve_line_mode( $line, $goods_mode, $services_mode, $uniform_mode );
			$key  = $this->grouping_key( $mode, $line );

			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'mode' => $mode,
					'line' => $line,
				);
				continue;
			}

			$grouped[ $key ]['line'] = $this->merge_line_contexts(
				$grouped[ $key ]['line'],
				$line
			);
		}

		$invoice_lines = array();
		foreach ( $grouped as $group ) {
			if ( (float) $group['line']->taxable_base === 0.0 ) {
				continue;
			}
			$invoice_lines[] = $this->build_invoice_line( $group['line'], $group['mode'] );
		}

		return $invoice_lines;
	}

	/**
	 * @param YITH_YWPI_Order_Line_Context $line          Normalized line.
	 * @param string           $goods_mode    Mode when line is goods.
	 * @param string           $services_mode Mode when line is service.
	 * @param string|null      $uniform_mode  When set, applied to every line.
	 */
	private function resolve_line_mode(
		YITH_YWPI_Order_Line_Context $line,
		string $goods_mode,
		string $services_mode,
		?string $uniform_mode
	): string {
		if ( null !== $uniform_mode ) {
			return $uniform_mode;
		}

		if ( $line->is_goods ) {
			return $goods_mode;
		}

		return $services_mode;
	}

	/**
	 * Grouping key for consolidating order lines into Verifacti invoice lines.
	 *
	 * @param string           $mode VerifactuLineMode constant.
	 * @param YITH_YWPI_Order_Line_Context $line Normalized line.
	 */
	private function grouping_key( string $mode, YITH_YWPI_Order_Line_Context $line ): string {
		if ( $this->groups_by_vat_rate( $mode ) ) {
			return $mode . '|vat:' . ( null !== $line->tax_rate ? $line->tax_rate : '_none_' );
		}

		return $mode;
	}

	/**
	 * @param string $mode YITH_YWPI_Verifactu_Line_Mode constant.
	 */
	private function groups_by_vat_rate( string $mode ): bool {
		return in_array(
			$mode,
			array(
				YITH_YWPI_Verifactu_Line_Mode::SPANISH_TAXED,
				YITH_YWPI_Verifactu_Line_Mode::EU_B2C_BELOW_THRESHOLD,
			),
			true
		);
	}

	/**
	 * Sum taxable base and VAT quota for lines sharing the same grouping key.
	 *
	 * @param YITH_YWPI_Order_Line_Context $accumulator Existing grouped line.
	 * @param YITH_YWPI_Order_Line_Context $line        Line to merge in.
	 */
	private function merge_line_contexts( YITH_YWPI_Order_Line_Context $accumulator, YITH_YWPI_Order_Line_Context $line ): YITH_YWPI_Order_Line_Context {
		$taxable_base = (float) $accumulator->taxable_base + (float) $line->taxable_base;

		$tax_amount = null;
		if ( null !== $accumulator->tax_amount || null !== $line->tax_amount ) {
			$tax_amount = (float) ( $accumulator->tax_amount ?? 0 ) + (float) ( $line->tax_amount ?? 0 );
		}

		$tax_rate = $accumulator->tax_rate ?? $line->tax_rate;

		return new YITH_YWPI_Order_Line_Context(
			YITH_YWPI_WooCommerce_Order_Invoice_Context::format_amount( $taxable_base ),
			$tax_rate,
			null !== $tax_amount ? YITH_YWPI_WooCommerce_Order_Invoice_Context::format_amount( $tax_amount ) : null,
			$accumulator->is_goods || $line->is_goods,
			$accumulator->is_service || $line->is_service
		);
	}

	/**
	 * @param WC_Order_Item $item Order item.
	 */
	private function map_item_to_context( WC_Order_Item $item ): YITH_YWPI_Order_Line_Context {
		$net   = (float) $item->get_total();
		$tax   = (float) $item->get_total_tax();
		$is_goods    = $this->item_is_goods( $item );
		$is_service  = ! $is_goods;

		$tax_rate   = null;
		$tax_amount = null;

		if ( $tax > 0 ) {
			$tax_amount = YITH_YWPI_WooCommerce_Order_Invoice_Context::format_amount( $tax );
			$tax_rate   = $this->get_configured_tax_rate( $item );
		}

		return new YITH_YWPI_Order_Line_Context(
			YITH_YWPI_WooCommerce_Order_Invoice_Context::format_amount( $net ),
			$tax_rate,
			$tax_amount,
			$is_goods,
			$is_service
		);
	}

	/**
	 * Look up the configured tax rate percentage from the WooCommerce tax rates table.
	 * Returns the stored rate (e.g. 21, 10, 4) so Verifacti receives an exact legal value
	 * rather than a back-calculated figure that may differ slightly due to price rounding.
	 *
	 * @param WC_Order_Item $item Order item.
	 */
	private function get_configured_tax_rate( WC_Order_Item $item ): ?string {
		$taxes = $item->get_taxes();

		if ( empty( $taxes['total'] ) ) {
			return null;
		}

		// Use the first rate ID that has a non-zero applied amount.
		$rate_id = null;
		foreach ( $taxes['total'] as $id => $amount ) {
			if ( (float) $amount !== 0.0 ) {
				$rate_id = (int) $id;
				break;
			}
		}

		if ( ! $rate_id ) {
			return null;
		}

		global $wpdb;
		$rate = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT tax_rate FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d",
				$rate_id
			)
		);

		if ( null === $rate ) {
			return null;
		}

		return YITH_YWPI_WooCommerce_Order_Invoice_Context::format_amount( (float) $rate );
	}

	/**
	 * Physical product = goods; virtual/downloadable = services; shipping/fee = services.
	 *
	 * @param WC_Order_Item $item Order item.
	 */
	private function item_is_goods( WC_Order_Item $item ): bool {
		if ( 'shipping' === $item->get_type() || 'fee' === $item->get_type() ) {
			return false;
		}

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return false;
		}

		$product = $item->get_product();
		if ( ! $product ) {
			return true;
		}

		if ( $product->is_virtual() || $product->is_downloadable() ) {
			return false;
		}

		return true;
	}
}
