<?php
/**
 * Add the features in the classic checkout page
 *
 * @class   YITH_YWPI_Classic_Checkout
 * @author  YITH
 * @package YITH\PDFInvoice
 */


defined( 'ABSPATH' ) || exit;

class YITH_YWPI_Classic_Checkout {
	/**
	 * Single instance of the class
	 *
	 * @var object
	 * @since 5.0.0
	 */
	protected static $instance;


	/**
	 * Returns single instance of the class
	 *
	 * @since 5.0.0
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		if( !\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_page()) {
			add_filter( 'woocommerce_billing_fields', array( $this, 'add_billing_fields' ), 30 );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_fields' ), 10, 2 );
			add_filter( 'woocommerce_checkout_posted_data', array( $this, 'set_valid_posted_data' ), 10 );
			add_filter( 'yith_ywpi_vat_number_is_required_option', '__return_false' );
		}
	}

	/**
	 * Add the fields for the spanish invoicing in the classic checkout page
	 *
	 * @param array $fields The fields.
	 *
	 * @return array
	 */
	public function add_billing_fields( $fields ) {
		if( isset( $fields['billing_invoice_type'] ) ) {
			$fields['billing_invoice_type']['priority'] =4;
		}
		$fields['billing_document_type'] = [
			'label'        => __( 'Document type', 'yith-woocommerce-pdf-invoice' ),
			'type'         => 'select',
			'class'        => [ 'form-row-first' ],
			'priority' => 115,
			'autocomplete' => 'given-name',
			'options'      => [
				'03' => __( 'Passport', 'yith-woocommerce-pdf-invoice' ),
				'04' => __( 'ID in the country of residence', 'yith-woocommerce-pdf-invoice' ),
				'06' => __( 'Other document type', 'yith-woocommerce-pdf-invoice' ),
			]
		];

		$fields['billing_document_number'] = [
			'label'        => __( 'Document number', 'yith-woocommerce-pdf-invoice' ),
			'type'         => 'text',
			'priority' => 116,
			'class'        => [ 'form-row-last' ],
			'autocomplete' => 'given-name',
		];

		$fields['billing_receiver_type'] = array(
			'label'        => __( 'Customer type', 'yith-woocommerce-pdf-invoice' ),
			'class'        => array( 'form-row-wide' ),
			'autocomplete' => 'given-name',
			'priority'     => 5,
			'required'     => true,
			'type'         => 'select',
			'options'      => array(
				'private'   => apply_filters( 'ywpi_receiver_type_field_private_label', esc_html__( 'Private', 'yith-woocommerce-pdf-invoice' ) ),
				'freelance' => apply_filters( 'ywpi_receiver_type_field_freelance_label', esc_html__( 'Freelance', 'yith-woocommerce-pdf-invoice' ) ),
				'company'   => apply_filters( 'ywpi_receiver_type_field_company_label', esc_html__( 'Company', 'yith-woocommerce-pdf-invoice' ) ),
			),
			'default'      => 'private',
		);

		if( isset( $fields['billing_vat_number'] ) ) {
			$fields['billing_vat_number']['class']    = [ 'form-row-last' ];
		}
		return $fields;
	}

	public function enqueue_scripts() {
		wp_register_script( 'ywpi_spanish_checkout', YITH_YWPI_ASSETS_URL . '/js/'.yit_load_js_file('spanish-checkout.js'), array( 'jquery' ), YITH_YWPI_ENQUEUE_VERSION, true );

		$data = [
			'ue_countries'   => WC()->countries->get_european_union_countries(),
			'billing_vat_id' => ywpi_is_yith_eu_vat_enabled() ? 'billing_yweu_vat' : 'billing_vat_number',
			'optional_label' => apply_filters( 'ywpi_checkout_optional_label', __( '(optional)' ) ),
		];

		wp_localize_script( 'ywpi_spanish_checkout', 'ywpi_spanish_checkout_data', $data );
		wp_enqueue_script( 'ywpi_spanish_checkout' );
	}

	/**
	 * Validate the extra checkout fields
	 *
	 * @param array     $data The data.
	 * @param \WP_Error $errors The errors.
	 *
	 * @return void
	 */
	public function validate_checkout_fields( $data, $errors ) {
		$customer_type = $data['billing_receiver_type'] ?? '';
		$country       = $data['billing_country'];
		$company       = $data['billing_company'] ?? '';
		$document_type = $data['billing_document_type'] ?? '';
		$document_number = $data['billing_document_number'] ?? '';
		$ue_countries  = WC()->countries->get_european_union_countries();
		$vat_number    = $data['billing_vat_number'] ?? '';

		if ( ywpi_is_yith_eu_vat_enabled() ) {
			$yith_vat = $data['billing_yweu_vat'] ?? '';

			if ( '' !== $yith_vat ) {
				$vat_number = $yith_vat;
			}
		}

		if ( '' === $customer_type ) {
			$errors->add( 'validation', __( 'Please select the customer type', 'yith-woocommerce-pdf-invoice' ) );
		}
		if ( ! in_array( $customer_type, array( 'private', 'freelance', 'company' ), true ) ) {
			$errors->add( 'validation', __( 'Please select a valid customer type', 'yith-woocommerce-pdf-invoice' ) );
		}
		if ( 'private' !== $customer_type && '' === $company ) {
			$errors->add( 'validation', __( 'Please enter the company name', 'yith-woocommerce-pdf-invoice' ) );
		}

		if ( 'ES' === $country ) {
			if ( '' === $vat_number ) {
				$errors->add( 'validation', __( 'Please enter the NIF number', 'yith-woocommerce-pdf-invoice' ) );
			}
		} elseif ( in_array( $country, $ue_countries, true ) ) {
			if ( 'private' !== $customer_type ) {
				if ( '' === $vat_number ) {
					$errors->add( 'validation', __( 'Please enter the NIF number', 'yith-woocommerce-pdf-invoice' ) );
				}
			} else {
				if ( '' === $document_type ) {
					$errors->add( 'validation', __( 'Please select the document type', 'yith-woocommerce-pdf-invoice' ) );
				}
				if ( ! in_array( $document_type, array( '03', '04', '06' ), true ) ) {
					$errors->add( 'validation', __( 'Please select a valid document type', 'yith-woocommerce-pdf-invoice' ) );
				}
				if ( '' === $document_number ) {
					$errors->add( 'validation', __( 'Please enter the document number', 'yith-woocommerce-pdf-invoice' ) );
				}

			}
		} else {
			if ( '' === $document_type ) {
				$errors->add( 'validation', __( 'Please select the document type', 'yith-woocommerce-pdf-invoice' ) );
			}
			if ( ! in_array( $document_type, array( '03', '04', '06' ), true ) ) {
				$errors->add( 'validation', __( 'Please select a valid document type', 'yith-woocommerce-pdf-invoice' ) );
			}
			if ( '' === $document_number ) {
				$errors->add( 'validation', __( 'Please enter the document number', 'yith-woocommerce-pdf-invoice' ) );
			}
		}
	}

	/**
	 * Force the right value for document type to 02 when the field is hide in checkout
	 *
	 * @param array $data The posted data.
	 *
	 * @return array
	 */
	public function set_valid_posted_data( $data ) {
		$customer_type = $data['billing_receiver_type'];
		$country       = $data['billing_country'];
		$ue_countries  = WC()->countries->get_european_union_countries();
		if ( 'ES' === $country || ( in_array( $country, $ue_countries, true ) && 'private' !== $customer_type ) ) {
			$data['billing_document_type'] = '02';
		}

		return $data;
	}
}