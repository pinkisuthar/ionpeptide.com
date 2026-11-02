<?php
/**
 * Submits VeriFactu payloads via Verifacti API and persists order meta.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

use Bluehost\VerifactiApi\Dto\InvoiceCancelRequest;
use Bluehost\VerifactiApi\Dto\RecordStatusLookupRequest;
use Bluehost\VerifactiApi\Exception\ValidationException;
use Bluehost\VerifactiApi\Support\Arrayable;

/**
 * Orchestrates factory → createInvoice / modifyInvoice / cancelInvoice → order meta + notes.
 */
class YITH_YWPI_Verifactu_Submission_Service {

	/** @var YITH_YWPI_Spanish_Electronic_Invoice_Manager */
	private $manager;

	/** @var YITH_YWPI_Verifactu_Invoice_Payload_Factory */
	private $factory;

	public function __construct(
		YITH_YWPI_Spanish_Electronic_Invoice_Manager $manager,
		?YITH_YWPI_Verifactu_Invoice_Payload_Factory $factory = null
	) {
		$this->manager = $manager;
		$this->factory = $factory ?: new YITH_YWPI_Verifactu_Invoice_Payload_Factory();
	}

	/**
	 * Build payload, submit when possible, or add manual order note when skipped.
	 *
	 * @param WC_Order $order Order.
	 * @param YITH_Invoice $document The document.
	 *
	 * @return bool True when API submission succeeded.
	 */
	public function submit( WC_Order $order, $document = null ): bool {
		$client = $this->manager->get_client();
		if ( ! $client ) {
			return false;
		}

		if ( $this->manager->order_has_invoice( $order ) ) {
			return false;
		}

		$request = $this->factory->build_create_request( $order , $document );

		if ( null === $request ) {
			$this->add_manual_submission_note( $order, $this->factory->get_last_skip_reason() );

			return false;
		}

		$this->persist_request_payload( $order, $request, '_ywpi_verifacti_request_payload' );

		try {
			$response = $client->createInvoice( $request );
			$key      = $response->getUuid();
			$qr_code  = $response->getQrCodeBase64();

			$record_status    = $client->getRecordStatus( new RecordStatusLookupRequest( $key ) );
			$status           = $this->manager->sanitize_status( $record_status->getStatus() );
			$data             = $record_status->getData();
			$serie            = $data['serie'];
			$number           = $data['numero'];
			$fecha_expedicion = $data['fecha_expedicion'];

			$order->update_meta_data( '_ywpi_verifacti_invoice_code', $key );
			$order->update_meta_data( '_ywpi_verifacti_status', $status );
			$order->update_meta_data( '_ywpi_verifacti_qrcode', $qr_code );
			$order->update_meta_data( '_ywpi_verifacti_serie', $serie );
			$order->update_meta_data( '_ywpi_verifacti_number', $number );
			$order->update_meta_data( '_ywpi_verifacti_fecha_expedicion', $fecha_expedicion );

			$order->save_meta_data();

			$order->add_order_note(
				sprintf(
				/* translators: %s: Verifacti record UUID */
					__( 'Invoice created on Verifacti with id %s', 'yith-woocommerce-pdf-invoice' ),
					$key
				)
			);

			return true;
		} catch ( ValidationException $e ) {
			wc_get_logger()->error( $e->getMessage(), array( 'source' => 'spanish-e-invoicing' ) );
			$order->add_order_note(
				sprintf(
				/* translators: %s: validation error details */
					__( 'Verifacti invoice validation failed: %s', 'yith-woocommerce-pdf-invoice' ),
					$e->getMessage()
				)
			);
		} catch ( \Exception $e ) {
			wc_get_logger()->error( $e->getMessage(), array( 'source' => 'spanish-e-invoicing' ) );
			$order->add_order_note(
				sprintf(
				/* translators: %s: error message */
					__( 'Verifacti invoice creation failed: %s', 'yith-woocommerce-pdf-invoice' ),
					$e->getMessage()
				)
			);
		}

		return false;
	}

	/**
	 * Amend an existing VeriFactu record (PUT /verifactu/modify) when the PDF invoice is regenerated.
	 *
	 * @param WC_Order $order Order.
	 * @param YITH_Invoice $invoice The invoice.
	 *
	 * @return bool True when API submission succeeded.
	 */
	public function modify( WC_Order $order, $invoice = null ): bool {
		$client = $this->manager->get_client();
		if ( ! $client ) {
			return false;
		}

		if ( ! $this->manager->order_has_invoice( $order ) ) {
			return false;
		}

		$request = $this->factory->build_modify_request( $order, $invoice );

		if ( null === $request ) {
			$this->add_manual_submission_note( $order, $this->factory->get_last_skip_reason() );

			return false;
		}

		try {
			$response = $client->modifyInvoice( $request );
			$key      = $response->getUuid();
			$qr_code  = $response->getQrCodeBase64();

			$record_status    = $client->getRecordStatus( new RecordStatusLookupRequest( $key ) );
			$status           = $this->manager->sanitize_status( $record_status->getStatus() );
			$data             = $record_status->getData();
			$serie            = $data['serie'];
			$number           = $data['numero'];
			$fecha_expedicion = $data['fecha_expedicion'];

			$order->update_meta_data( '_ywpi_verifacti_invoice_code', $key );
			$order->update_meta_data( '_ywpi_verifacti_status', $status );
			$order->update_meta_data( '_ywpi_verifacti_qrcode', $qr_code );
			$order->update_meta_data( '_ywpi_verifacti_serie', $serie );
			$order->update_meta_data( '_ywpi_verifacti_number', $number );
			$order->update_meta_data( '_ywpi_verifacti_fecha_expedicion', $fecha_expedicion );

			$order->save_meta_data();

			$order->add_order_note(
				sprintf(
				/* translators: %s: Verifacti record UUID */
					__( 'Invoice amended on Verifacti with id %s', 'yith-woocommerce-pdf-invoice' ),
					$key
				)
			);

			return true;
		} catch ( ValidationException $e ) {
			wc_get_logger()->error( $e->getMessage(), array( 'source' => 'spanish-e-invoicing' ) );
			$order->add_order_note(
				sprintf(
				/* translators: %s: validation error details */
					__( 'Verifacti invoice validation failed: %s', 'yith-woocommerce-pdf-invoice' ),
					$e->getMessage()
				)
			);
		} catch ( \Exception $e ) {
			wc_get_logger()->error( $e->getMessage(), array( 'source' => 'spanish-e-invoicing' ) );
			$order->add_order_note(
				sprintf(
				/* translators: %s: error message */
					__( 'Verifacti invoice amendment failed: %s', 'yith-woocommerce-pdf-invoice' ),
					$e->getMessage()
				)
			);
		}

		return false;
	}

	/**
	 * Cancel the invoice if it exists.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return bool
	 */
	public function cancel( WC_Order $order ) {
		$client = $this->manager->get_client();
		if ( ! $client ) {
			return false;
		}

		if ( ! $this->manager->order_has_invoice( $order ) ) {
			return false;
		}

		$serie            = $order->get_meta( '_ywpi_verifacti_serie' );
		$number           = $order->get_meta( '_ywpi_verifacti_number' );
		$fecha_expedicion = $order->get_meta( '_ywpi_verifacti_fecha_expedicion' );
		try {
			$result  = $client->cancelInvoice(
				new InvoiceCancelRequest( $serie, $number, $fecha_expedicion )
			);
			$new_uui = $result->getUuid();
			$status  = $this->manager->sanitize_status( $result->getStatus() );
			$order->update_meta_data( '_ywpi_verifacti_status', $status );
			$order->update_meta_data( '_ywpi_verifacti_invoice_code', $new_uui );
			$order->update_meta_data( '_ywpi_verifacti_qrcode', '' );
			$order->save_meta_data();
			$order->add_order_note(
				sprintf(
				/* translators: %s: Verifacti record UUID */
					__( 'Invoice cancelled on Verifacti with id %s', 'yith-woocommerce-pdf-invoice' ),
					$new_uui
				)
			);
			return true;
		} catch ( ValidationException $e ) {
			wc_get_logger()->error( $e->getMessage(), array( 'source' => 'spanish-e-invoicing' ) );
			$order->add_order_note(
				sprintf(
				/* translators: %s: validation error details */
					__( 'Verifacti invoice validation failed: %s', 'yith-woocommerce-pdf-invoice' ),
					$e->getMessage()
				)
			);
		} catch ( \Exception $e ) {
			wc_get_logger()->error( $e->getMessage(), array( 'source' => 'spanish-e-invoicing' ) );
			$order->add_order_note(
				sprintf(
				/* translators: %s: error message */
					__( 'Verifacti invoice cancellation failed: %s', 'yith-woocommerce-pdf-invoice' ),
					$e->getMessage()
				)
			);
		}

		return false;
	}

	/**
	 * Store the JSON payload sent to Verifacti on the order for debugging.
	 *
	 * @param WC_Order $order Order.
	 * @param Arrayable $request Request DTO (same structure as the API body).
	 * @param string    $meta_key Order meta key.
	 */
	private function persist_request_payload( WC_Order $order, Arrayable $request, string $meta_key ): void {
		$order->update_meta_data(
			$meta_key,
			wp_json_encode( $request->toArray(), JSON_UNESCAPED_UNICODE )
		);
		$order->save_meta_data();
	}

	/**
	 * Customer-visible note when automatic VeriFactu submission is not performed.
	 *
	 * @param WC_Order   $order Order.
	 * @param string|null $reason VerifactuSkipReason constant.
	 */
	private function add_manual_submission_note( WC_Order $order, ?string $reason ): void {
		$message = YITH_YWPI_Verifactu_Skip_Reason::customer_note_message(
			$reason ?: YITH_YWPI_Verifactu_Skip_Reason::MISSING_PAYLOAD,
			$order
		);

		$order->add_order_note( $message, true );

		wc_get_logger()->info(
			'VeriFactu auto-submit skipped: ' . ( $reason ?: 'unknown' ),
			array(
				'source'   => 'spanish-e-invoicing',
				'order_id' => $order->get_id(),
			)
		);
	}

}
