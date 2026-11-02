<?php
/**
 * Order + PDF invoice header data for VeriFactu builders.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

use Bluehost\VerifactiApi\Support\DateHelper;

/**
 * DTO: WooCommerce billing meta, document serie/numero/fecha, and mapped lines.
 */
final class YITH_YWPI_WooCommerce_Order_Invoice_Context {

	/** @var string ISO 2-letter billing country. */
	public $billing_country;

	/** @var string private|company|freelance|empty */
	public $receiver_type;

	/** @var string Document id type for id_otro: 03, 04, or 06 (EU private / non-EU). */
	public $document_type;

	/** @var string NIF, VIES, or foreign document number from _billing_vat_number. */
	public $vat_number;

	/** @var string Foreign document number from _billing_document_number (id_otro id). */
	public $document_number;

	/** @var string Recipient nombre for VeriFactu. */
	public $recipient_name;

	/** @var string Invoice series (PDF prefix). */
	public $series;

	/** @var string Invoice number (raw document number). */
	public $number;

	/** @var string Issue date d-m-Y (Verifacti API format). */
	public $issue_date;

	/** @var string Order total as decimal string for importe_total. */
	public $total_amount;

	/** @var string Header descripcion. */
	public $description;

	/** @var YITH_YWPI_Order_Line_Context[] */
	public $lines;


	/** @var float Numeric order total for F2 €3,000 rule. */
	public $order_total_numeric;

	/** @var \WC_Order Source order. */
	public $order;

	/**
	 * EU B2C OSS flag set by factory (true = OSS regime 17, false = below threshold).
	 * Null means not applicable or not yet resolved.
	 *
	 * @var bool|null
	 */
	public $eu_b2c_oss;

	/**
	 * The invoice document, if any.
	 *
	 * @var YITH_Invoice|null
	 */
	public $invoice;

	/**
	 * Build context from a WooCommerce order and its PDF invoice document.
	 *
	 * @param WC_Order $order Order object.
	 * @param YITH_Invoice $invoice Invoice object.
	 */
	public static function from_order( \WC_Order $order, $invoice = null ): self {
		$context = new self();

		$context->order             = $order;
		$context->invoice           = $invoice;
		$context->billing_country = strtoupper( (string) $order->get_billing_country() );
		$context->receiver_type   = (string) $order->get_meta( '_billing_receiver_type' );
		$context->document_type   = (string) $order->get_meta( '_billing_document_type' );
		$context->vat_number      = (string) ywpi_get_billing_vat_number( $order );
		$context->document_number = (string) $order->get_meta( '_billing_document_number' );
		$context->document_type   = self::normalize_identifier( $context->document_type );
		$context->vat_number      = self::normalize_identifier( $context->vat_number );
		$context->document_number = self::normalize_identifier( $context->document_number );
		$context->recipient_name  = self::resolve_recipient_name( $order );
		$context->order_total_numeric = (float) $order->get_total();


		self::hydrate_document_fields( $context, $order );

		$mapper          = new YITH_YWPI_WooCommerce_Order_Line_Mapper();
		$context->lines  = $mapper->map_order_lines( $order );

		return $context;
	}

	/**
	 * Company/freelance → company name; private → first + last name.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function resolve_recipient_name( \WC_Order $order ): string {
		$type = (string) $order->get_meta( '_billing_receiver_type' );

		if ( in_array( $type, array( 'company', 'freelance' ), true ) ) {
			$company = trim( (string) $order->get_billing_company() );
			if ( '' !== $company ) {
				return $company;
			}
		}

		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}

	/**
	 * Serie, numero, fecha_expedicion, descripcion, importe_total from PDF invoice plugin data.
	 *
	 * @param self      $context Context to fill.
	 * @param \WC_Order $order   Order.
	 */
	private static function hydrate_document_fields( self $context, \WC_Order $order ): void {


		if ( $context->invoice instanceof YITH_Invoice ) {
			$context->series =  $context->invoice->prefix;
			$context->number =  $context->invoice->number;
		} else {
			$context->series = (string) $order->get_meta( '_ywpi_invoice_prefix' );
			$context->number = (string) $order->get_meta( '_ywpi_invoice_number' );
		}

		if ( empty( $context->series ) ) {
			$context->series = 'A';
		}

		if ( '' === $context->number ) {
			$context->number = (string) $order->get_order_number();
		}

		$timestamp = time();
		if ( false === $timestamp ) {
			$timestamp = time();
		}
		$context->issue_date = gmdate( DateHelper::API_DATE_FORMAT, $timestamp );

		$context->total_amount = self::format_amount( $context->order_total_numeric );

		$site_name = get_bloginfo( 'name' );
		$context->description = sprintf(
			/* translators: 1: WooCommerce order number, 2: site name */
			__( 'Order %1$s - %2$s', 'yith-woocommerce-pdf-invoice' ),
			$order->get_order_number(),
			$site_name ? $site_name : 'Shop'
		);
	}

	/**
	 * Format monetary values for VeriFactu JSON (string, trimmed decimals).
	 *
	 * @param float|string $amount Amount.
	 */
	public static function format_amount( $amount ): string {
		if ( function_exists( 'wc_format_decimal' ) ) {
			$formatted = wc_format_decimal( $amount, 2, false );
		} else {
			$formatted = number_format( (float) $amount, 2, '.', '' );
		}

		return rtrim( rtrim( $formatted, '0' ), '.' );
	}

	/**
	 * Normalize tax or document identifiers from checkout/order meta.
	 *
	 * YITH EU VAT may persist "-" as an empty VAT placeholder; treat it as absent.
	 *
	 * @param string $value Identifier value.
	 */
	public static function normalize_identifier( string $value ): string {
		$value = str_replace( ' ', '', trim( $value ) );

		if ( '' === $value || '-' === $value ) {
			return '';
		}

		return $value;
	}

	/**
	 * Resolve id_otro id for EU private and extra-EU recipients.
	 *
	 * Prefer _billing_document_number; fall back to the filtered VAT number.
	 */
	public function resolve_id_otro_identifier(): string {
		if ( '' !== $this->document_number ) {
			return $this->document_number;
		}

		return $this->vat_number;
	}



	/**
	 * Allowed id_otro id_type values for private EU and non-EU customers.
	 *
	 * @return bool
	 */
	public function has_valid_foreign_document_type(): bool {
		return in_array( $this->document_type, array( '03', '04', '06' ), true );
	}

	/**
	 * Order contains at least one goods line and at least one service line.
	 */
	public function is_mixed_goods_and_services(): bool {
		$has_goods    = false;
		$has_services = false;

		foreach ( $this->lines as $line ) {
			if ( $line->is_goods ) {
				$has_goods = true;
			}
			if ( $line->is_service ) {
				$has_services = true;
			}
		}

		return $has_goods && $has_services;
	}

	/**
	 * All lines classified as goods (used when cart is homogeneous).
	 */
	public function is_all_goods(): bool {
		if ( empty( $this->lines ) ) {
			return false;
		}

		foreach ( $this->lines as $line ) {
			if ( ! $line->is_goods ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether any mapped order line carries tax (plugin does not calculate VAT).
	 */
	public function has_line_taxes(): bool {
		foreach ( $this->lines as $line ) {
			if ( null !== $line->tax_amount && (float) $line->tax_amount > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the order applies Spanish (ES) VAT on at least one line.
	 *
	 * Used for EU B2C: below-threshold payloads include Spanish VAT on lines; when
	 * destination-country VAT applies (OSS), VeriFactu must use regime 17 without Spanish tax fields.
	 */
	public function has_spanish_line_taxes(): bool {
		return self::order_has_tax_from_country( $this->order, 'ES' );
	}

	/**
	 * Whether any positive tax quota on the order comes from a rate in the given country.
	 *
	 * @param \WC_Order|null $order   Order.
	 * @param string         $country ISO 2-letter country code.
	 */
	public static function order_has_tax_from_country( ?\WC_Order $order, string $country ): bool {
		if ( ! $order instanceof \WC_Order || ! class_exists( 'WC_Tax' ) ) {
			return false;
		}

		$country  = strtoupper( $country );
		$rate_ids = self::collect_positive_tax_rate_ids( $order );

		foreach ( array_keys( $rate_ids ) as $rate_id ) {
			$rate = \WC_Tax::_get_tax_rate( $rate_id );

			if ( ! is_array( $rate ) ) {
				continue;
			}

			if ( strtoupper( (string) ( $rate['tax_rate_country'] ?? '' ) ) === $country ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Collect WooCommerce tax rate IDs with a positive quota on order lines.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, true>
	 */
	private static function collect_positive_tax_rate_ids( \WC_Order $order ): array {
		$rate_ids = array();

		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_taxes' ) ) {
				continue;
			}

			$taxes = $item->get_taxes();

			foreach ( array( 'total', 'subtotal' ) as $tax_key ) {
				if ( empty( $taxes[ $tax_key ] ) || ! is_array( $taxes[ $tax_key ] ) ) {
					continue;
				}

				foreach ( $taxes[ $tax_key ] as $rate_id => $amount ) {
					if ( (float) $amount > 0 ) {
						$rate_ids[ (int) $rate_id ] = true;
					}
				}
			}
		}

		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			if ( ! is_object( $tax_item ) || ! method_exists( $tax_item, 'get_rate_id' ) ) {
				continue;
			}

			$rate_id = (int) $tax_item->get_rate_id();

			if ( $rate_id <= 0 ) {
				continue;
			}

			$tax_total      = method_exists( $tax_item, 'get_tax_total' ) ? (float) $tax_item->get_tax_total() : 0.0;
			$shipping_tax   = method_exists( $tax_item, 'get_shipping_tax_total' ) ? (float) $tax_item->get_shipping_tax_total() : 0.0;

			if ( $tax_total + $shipping_tax > 0 ) {
				$rate_ids[ $rate_id ] = true;
			}
		}

		return $rate_ids;
	}

	/**
	 * Normalize header and line amounts to net (base imponible) only.
	 *
	 * Used when VeriFactu payloads must not include Spanish VAT fields (EU B2C OSS,
	 * extra-EU export/services) even if the WooCommerce order still carries tax lines.
	 */
	public function apply_net_line_amounts(): void {
		$net_total = 0.0;

		foreach ( $this->lines as $line ) {
			$line->tax_rate   = null;
			$line->tax_amount = null;
			$net_total       += (float) $line->taxable_base;
		}

		$this->order_total_numeric = $net_total;
		$this->total_amount        = self::format_amount( $net_total );
	}

	/**
	 * Normalize header and line amounts for EU B2C OSS (above threshold).
	 */
	public function apply_eu_b2c_oss_amounts(): void {
		$this->apply_net_line_amounts();
	}

	/**
	 * Normalize header and line amounts for extra-EU (extracommunity) invoices.
	 */
	public function apply_extra_eu_amounts(): void {
		$this->apply_net_line_amounts();
	}

	/**
	 * Sum of mapped line net amounts (base imponible).
	 */
	public function sum_line_taxable_bases(): float {
		$sum = 0.0;

		foreach ( $this->lines as $line ) {
			$sum += (float) $line->taxable_base;
		}

		return $sum;
	}

	/**
	 * All lines classified as services.
	 */
	public function is_all_services(): bool {
		if ( empty( $this->lines ) ) {
			return false;
		}

		foreach ( $this->lines as $line ) {
			if ( ! $line->is_service ) {
				return false;
			}
		}

		return true;
	}
}
