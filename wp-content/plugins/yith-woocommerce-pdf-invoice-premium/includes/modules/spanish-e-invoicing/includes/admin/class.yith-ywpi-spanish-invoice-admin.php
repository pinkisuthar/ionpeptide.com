<?php
/**
 * YITH_YWPI_Spanish_Invoice_Admin .
 *
 * @class   YITH_YWPI_Spanish_Invoice_Admin
 * @since   5.0.0
 * @author  YITH
 * @package YITH\PDFInvoice
 */

use Bluehost\PDFInvoices\Modules\SpanishElectronicInvoice\SpanishElectronicInvoiceManager;
use Bluehost\VerifactiApi\Dto\RecordStatusLookupRequest;

defined( 'ABSPATH' ) || exit;

class YITH_YWPI_Spanish_Invoice_Admin extends YITH_YWPI_Module_Admin {

	/**
	 * The module ID
	 *
	 * @var string
	 */
	protected $module_id = 'spanish-e-invoicing';

	/**
	 * Constructor
	 *
	 * @since  5.0.0
	 */
	public function __construct() {
		parent::__construct();

		add_action( 'ywpi_show_spanish_electronic_invoice_connection_status', array( $this, 'show_connection_status' ) );
		add_action( 'updated_option', array( $this, 'maybe_sync_connection_data' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'maybe_prime_connection_data' ) );

		add_action( 'ywpi_before_view_pdf_invoice', array(
			$this,
			'maybe_show_verifacti_status'
		), 10, 3 );
		add_action( 'ywpi_after_pdf_invoice_buttons', array( $this, 'show_check_status' ), 10, 3 );

		/**
		 * Manage the action 'view'
		 */
		add_action( 'admin_action_check_status', array( $this, 'check_status' ) );

		add_filter( 'woocommerce_admin_billing_fields', array( $this, 'add_custom_billing_field' ), 20 );
		add_filter(
			'woocommerce_order_get_formatted_billing_address',
			array(
				$this,
				'add_formatted_document_type_field',
			),
			15,
			3
		);
	}

	/**
	 * Get the tabs
	 *
	 * @return array[]
	 */
	protected function get_tabs() {
		return array(
			'spanish-invoice' => array(
				'title'       => __( 'Spanish Electronic Invoice', 'yith-woocommerce-pdf-invoice' ),
				'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="23px" height="20px" viewBox="0 0 23 20" enable-background="new 0 0 23 20" xml:space="preserve"><path fill="#94A2B8" d="M17.2,20H5.8C2.602,20,0,17.398,0,14.2V5.8C0,2.602,2.602,0,5.8,0h11.4C20.398,0,23,2.602,23,5.8v8.4 C23,17.398,20.398,20,17.2,20z M5.8,1.6c-2.316,0-4.2,1.884-4.2,4.2v8.4c0,2.315,1.884,4.2,4.2,4.2h11.4c2.315,0,4.2-1.885,4.2-4.2 V5.8c0-2.316-1.885-4.2-4.2-4.2H5.8z M9.374,6.931V13h3.672v-1.292h-2.091v-1.207h1.887V9.26h-1.887V8.223h2.015V6.931H9.374z"/></svg>',
				'description' => __( 'Configure the settings Verifactu service', 'yith-woocommerce-pdf-invoice' ),
			)
		);
	}


	/**
	 * Sync Verifacti data after a relevant option change.
	 *
	 * @param string $option The option name.
	 * @param mixed  $old_value The old value.
	 * @param mixed  $value The new value.
	 *
	 * @return void
	 */
	public function maybe_sync_connection_data( $option, $old_value, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$connection = YITH_YWPI_Connection::get_instance();

		switch ( $option ) {
			case YITH_YWPI_Connection::OPTION_ENV:
				$connection->sync_connection_data( sanitize_key( (string) $value ) );
				break;

			case YITH_YWPI_Connection::OPTION_TEST_API_KEY:
				$connection->sync_connection_data( 'test' );
				break;

			case YITH_YWPI_Connection::OPTION_PRODUCTION_API_KEY:
				$connection->sync_connection_data( 'production' );
				break;
		}
	}

	/**
	 * Prime the connection data the first time the module settings are opened.
	 *
	 * @return void
	 */
	public function maybe_prime_connection_data() {
		if ( ! $this->is_spanish_module_page() ) {
			return;
		}

		$connection = YITH_YWPI_Connection::get_instance();
		$summary    = $connection->get_connection_summary();

		if ( '' === $connection->get_api_key() ) {
			if ( YITH_YWPI_Connection::STATUS_MISSING_KEY !== $summary['status'] ) {
				$connection->clear_connection_data();
			}

			return;
		}

		if ( empty( $summary['checked_at'] ) ) {
			$connection->sync_connection_data();
		}
	}

	/**
	 * Show the connection status field
	 *
	 * @param array $field The field.
	 *
	 * @return void
	 */
	public function show_connection_status( $field ) {
		$summary            = YITH_YWPI_Connection::get_instance()->get_connection_summary();
		$environment_label  = 'production' === $summary['environment'] ? __( 'Production', 'yith-woocommerce-pdf-invoice' ) : __( 'Test', 'yith-woocommerce-pdf-invoice' );
		$status_label       = __( 'Not checked yet', 'yith-woocommerce-pdf-invoice' );
		$checked_at_display = '';

		if ( YITH_YWPI_Connection::STATUS_CONNECTED === $summary['status'] ) {
			$status_label = __( 'Connected', 'yith-woocommerce-pdf-invoice' );
		} elseif ( YITH_YWPI_Connection::STATUS_DISCONNECTED === $summary['status'] ) {
			$status_label = __( 'Connection failed', 'yith-woocommerce-pdf-invoice' );
		} elseif ( YITH_YWPI_Connection::STATUS_MISSING_KEY === $summary['status'] ) {
			$status_label = __( 'Missing API key', 'yith-woocommerce-pdf-invoice' );
		}

		if ( ! empty( $summary['checked_at'] ) ) {
			$timestamp = strtotime( $summary['checked_at'] );

			if ( false !== $timestamp ) {
				$checked_at_display = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
			}
		}
		?>
		<div id="<?php echo esc_attr( $field['id'] ); ?>">
			<p><strong><?php esc_html_e( 'Selected environment', 'yith-woocommerce-pdf-invoice' ); ?>
					:</strong> <?php echo esc_html( $environment_label ); ?></p>
			<p><strong><?php esc_html_e( 'Status', 'yith-woocommerce-pdf-invoice' ); ?>
					:</strong> <?php echo esc_html( $status_label ); ?></p>
			<?php if ( ! empty( $summary['nif'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Saved NIF', 'yith-woocommerce-pdf-invoice' ); ?>
						:</strong> <?php echo esc_html( $summary['nif'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $checked_at_display ) : ?>
				<p><strong><?php esc_html_e( 'Last check', 'yith-woocommerce-pdf-invoice' ); ?>
						:</strong> <?php echo esc_html( $checked_at_display ); ?></p>
			<?php endif; ?>
			<p class="description"><?php echo esc_html( $summary['message'] ); ?></p>
			<p class="description"><?php esc_html_e( 'Save the settings after changing the environment or API key to refresh the check and update the stored NIF.', 'yith-woocommerce-pdf-invoice' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Check if the current admin page is the Spanish module tab.
	 *
	 * @return bool
	 */
	private function is_spanish_module_page() {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';

		return 'yith_woocommerce_pdf_invoice_panel' === $page && 'spanish-invoice' === $tab;
	}

	/**
	 * Display the Verifacti status based on the given order and invoice data.
	 *
	 * @param WC_Order $order The order object.
	 * @param object    $invoice The invoice object.
	 * @param string    $is_receipt Indicates whether the document is a receipt.
	 *
	 * @return void
	 */
	public function maybe_show_verifacti_status( $order, $invoice, $is_receipt ) {
		if (  $invoice instanceof YITH_Invoice ) {
			$status = $order->get_meta( '_ywpi_verifacti_status' );
			$status = empty( $status ) ? 'not_send' : $status;
			$label  = $this->get_verifacti_status_label( $status );
			?>
            <div class="ywpi-section-row">
                <span class="ywpi-left-label"><?php esc_html_e( 'Verifacti Status: ', 'yith-woocommerce-pdf-invoice' ); ?></span>
                <span class="ywpi-right-value"><?php echo wp_kses_post( $label ); ?></span>
            </div>
			<?php
		}
	}

	/**
	 * Display the button to check the status based on the given order and invoice data.
	 *
	 * @param WC_Order $order The order object.
	 * @param object    $invoice The invoice object.
	 * @param string    $is_receipt Indicates whether the document is a receipt.
	 *
	 * @return void
	 */
	public function show_check_status( $order, $invoice, $is_receipt ) {

		if (  $invoice instanceof YITH_Invoice ) {
			$uui               = YITH_YWPI_Spanish_Electronic_Invoice_Manager::get_instance()->get_invoice_code( $order );
			$status            = $order->get_meta( '_ywpi_verifacti_status' );

			if ( $uui  && ( ! in_array( $status, ['correcto', 'anulado' ], true ) ) ) :
				?>
                <a
                        class="button tips bh_pdf_invoices_check_verifacti_status"
                        data-tip="<?php esc_html_e( 'Check status on Verifacti', 'yith-woocommerce-pdf-invoice' ); ?>"
                        href="<?php echo wp_kses_post( YITH_PDF_Invoice()->get_action_url( 'check_status', 'invoice', $order->get_id() ) ); ?>"><?php esc_html_e( 'Check Status', 'yith-woocommerce-pdf-invoice' ); ?></a>
			<?php
			endif;
		}
	}

	/**
	 * Get the label for the given Verifacti status.
	 *
	 * @param string $status The status slug.
	 *
	 * @return string
	 */
	public function get_verifacti_status_label( $status ) {
		$statuses = [
			'not_send'             => __( 'Invoice not sent', 'yith-woocommerce-pdf-invoice' ),
			'pendiente'            => __( 'Invoice queued and not yet processed.', 'yith-woocommerce-pdf-invoice' ),
			'correcto'             => __( 'Invoice processed correctly by the AEAT', 'yith-woocommerce-pdf-invoice' ),
			'aceptado_con_errores' => __( 'Invoice accepted with errors by the AEAT. An amendment record or corrective invoice must be submitted.', 'yith-woocommerce-pdf-invoice' ),
			'incorrecto'           => __( 'Invoice considered incorrect by the AEAT. An amendment record with rechazo_previo=S or rechazo_previo=X or a corrective invoice must be submitted.', 'yith-woocommerce-pdf-invoice' ),
			'duplicado'            => __( 'Invoice not accepted by the AEAT because a record with the same (serie, numero, fecha_expedicion) already exists', 'yith-woocommerce-pdf-invoice' ),
			'anulado'              => __( 'Cancellation invoice processed correctly by the AEAT.', 'yith-woocommerce-pdf-invoice' ),
			'factura_inexistente'  => __( 'Cancellation invoice not accepted by the AEAT because the invoice does not exist.', 'yith-woocommerce-pdf-invoice' ),
			'no_registrado'        => __( 'Invoice rejected by the AEAT.', 'yith-woocommerce-pdf-invoice' ),
			'error_servidor_aeat'  => __( 'Error on the AEAT server. The invoicing record will be retried.', 'yith-woocommerce-pdf-invoice' ),
		];

		return ! empty( $statuses[ $status ] ) ? $statuses[ $status ] : $statuses['not_send'];
	}

	/**
	 * Check the status on verifacti system
	 *
	 * @return void
	 */
	public function check_status() {

		if ( isset( $_GET['id'] ) && isset( $_GET['_wpnonce'] ) && isset( $_GET['action'] ) && isset( $_GET['type'] ) ) {
			$nonce        = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
			$action       = sanitize_text_field( wp_unslash( $_GET['action'] ) );
			$type         = sanitize_text_field( wp_unslash( $_GET['type'] ) );
			$order_id     = sanitize_text_field( wp_unslash( $_GET['id'] ) );
			$order        = wc_get_order( $order_id );
			$nonce_action = $action . $type . $order_id;
			if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
				return;
			}
            $manager = YITH_YWPI_Spanish_Electronic_Invoice_Manager::get_instance();
			$uui = $manager->get_invoice_code( $order );
			if ( $uui ) {
				try {
					$client = $manager->get_client();
					$status = $client->getRecordStatus( new RecordStatusLookupRequest( $uui ) );
					$status = $manager->sanitize_status( $status->getStatus() );
					$order->update_meta_data( '_ywpi_verifacti_status', $status );
					$order->save();
				} catch ( \Exception $e ) {
					$order->add_order_note( $e->getMessage() );
				}
			}

			if ( is_admin() && isset( $_SERVER['HTTP_REFERER'] ) ) {
				$location = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );

				if ( ! wp_safe_redirect( $location ) ) {
					wp_safe_redirect( $location );
				}

				exit();
			}
		}

	}

	/**
	 * Add custom billing fields for spanish invoicing
	 *
	 * @param array $billing_fields The billing fields.
	 *
	 * @return array
	 */
	public function add_custom_billing_field( $billing_fields ) {

		$billing_fields['document_type'] = [
			'label'   => __( 'Document type', 'yith-woocommerce-pdf-invoice' ),
			'show'    => false,
			'class'   => 'select short',
			'type'    => 'select',
			'options' =>
				[ '' => __( 'Select a document type', 'yith-woocommerce-pdf-invoice' ) ] +
				[
					'02' => __( 'NIF/VAT', 'yith-woocommerce-pdf-invoice' ),
					'03' => __( 'Passport', 'yith-woocommerce-pdf-invoice' ),
					'04' => __( 'ID in the country of residence', 'yith-woocommerce-pdf-invoice' ),
					'06' => __( 'Other document type', 'yith-woocommerce-pdf-invoice' ),
				]

		];
		$billing_fields['document_number'] = [
			'label'   => __( 'Document number', 'yith-woocommerce-pdf-invoice' ),
			'show'    => false,
			'class'   => 'short',
			'type' => 'text'
		];

		return $billing_fields;
	}

	/**
	 * Adds Document type field to formatted address for order's admin view
	 *
	 * @access public
	 *
	 * @param string    $address string with the address data.
	 * @param array     $raw_address array with the address data.
	 * @param \WC_Order $order Order object.
	 *
	 * @return string string with the address data modified
	 * @author Bluehost
	 */
	public function add_formatted_document_type_field( $formatted_address, $address, $order ) {
		$document_type = $order->get_meta( '_billing_document_type' );
		$document_number = $order->get_meta( '_billing_document_number' );
		if( '' !== $document_type  && '' !== $document_number ) {
			$types = [
				'02' => __( 'NIF/VAT', 'yith-woocommerce-pdf-invoice' ),
				'03' => __( 'Passport', 'yith-woocommerce-pdf-invoice' ),
				'04' => __( 'ID in the country of residence', 'yith-woocommerce-pdf-invoice' ),
				'06' => __( 'Other document type', 'yith-woocommerce-pdf-invoice' ),
			];

			$type = $types[ $document_type ] ?? '';

			$formatted_address .= '<br>' . esc_html__('Document type', 'yith-woocommerce-pdf-invoice') . ': ' . $type;
			$formatted_address .= '<br>' . esc_html__('Document number', 'yith-woocommerce-pdf-invoice') . ': ' . $document_number;
		}
		return $formatted_address;
	}
}