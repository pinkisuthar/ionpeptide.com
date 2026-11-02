<?php
/**
 * Reasons automatic VeriFactu submission was skipped.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

/**
 * Machine-readable skip reason for order notes and logging.
 */
final class YITH_YWPI_Verifactu_Skip_Reason {

	public const UNSUPPORTED_SCENARIO = 'unsupported_scenario';
	public const MISSING_PAYLOAD    = 'missing_payload';
	public const F2_OVER_LIMIT      = 'f2_over_limit';
	public const MISSING_DOCUMENT   = 'missing_document_type';
	public const OSS_UNDETERMINED     = 'oss_undetermined';

	/**
	 * @param string $reason Constant from this class.
	 */
	public static function customer_note_message( string $reason, WC_Order $order ): string {
		$invoice_number = $order->get_meta( '_ywpi_invoice_formatted_number' );
		if ( '' === $invoice_number ) {
			$invoice_number = $order->get_meta( '_ywpi_invoice_number' );
		}
		if ( '' === $invoice_number ) {
			$invoice_number = '#' . $order->get_order_number();
		}

		switch ( $reason ) {
			case self::F2_OVER_LIMIT:
				return sprintf(
					/* translators: 1: order number, 2: invoice reference, 3: configured F2 threshold amount */
					__( 'VeriFactu: simplified invoice (F2) cannot be submitted automatically because the order total exceeds the configured F2 threshold (%3$s). Please create the VeriFactu record manually at Verifacti for order %1$s (invoice %2$s).', 'yith-woocommerce-pdf-invoice' ),
					$order->get_order_number(),
					$invoice_number,
					YITH_YWPI_Spanish_Invoice_Payload_Builder::f2_max_total()
				);
			case self::OSS_UNDETERMINED:
				return sprintf(
					/* translators: 1: order number, 2: invoice reference */
					__( 'VeriFactu: OSS / distance-sales threshold could not be determined from this order. Please create the VeriFactu record manually at Verifacti for order %1$s (invoice %2$s).', 'yith-woocommerce-pdf-invoice' ),
					$order->get_order_number(),
					$invoice_number
				);
			default:
				return sprintf(
					/* translators: 1: order number, 2: invoice reference */
					__( 'VeriFactu: this invoice cannot be submitted automatically. Please create the VeriFactu record manually at Verifacti for order %1$s (invoice %2$s).', 'yith-woocommerce-pdf-invoice' ),
					$order->get_order_number(),
					$invoice_number
				);
		}
	}
}
