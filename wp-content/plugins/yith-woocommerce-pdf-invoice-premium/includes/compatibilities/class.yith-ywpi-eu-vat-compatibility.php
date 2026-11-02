<?php
/**
 * Add the integration with YITH EU VAT plugin.
 *
 * @package YITH\PDFInvoice\Compatibilities
 *
 *
 */

defined( 'ABSPATH' ) || exit;

class YITH_YWPI_EU_VAT_Compatibility {
	/**
	 * Single instance of the class
	 *
	 * @var \YITH_YWPI_EU_VAT_Compatibility
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

	/**
	 * Customer billing field key used by YITH EU VAT.
	 */
	public const BILLING_FIELD_KEY = 'billing_yweu_vat';

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_filter( 'yith_ywpi_template_editor_customer_info', array( $this, 'add_customer_vat_info' ), 10, 2 );
		add_filter( 'yith_ywpi_template_editor_customer_info_placeholders', array( $this, 'add_customer_info_placeholders' ) );
		add_filter( 'yith_ywpi_replace_customer_details', array( $this, 'replace_customer_vat_details' ), 10, 3 );
		add_filter( 'ywpi_vat_ssn_block_script_data', array( $this, 'filter_block_checkout_script_data' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'sync_classic_checkout_vat_session' ), 5, 2 );

		if( YITH_YWPI_Modules::is_module_active('spanish-e-invoicing') ){
			add_filter( 'ywpi_get_billing_vat_number', array( $this, 'filter_billing_vat_number' ), 10, 2 );
			add_filter( 'ywpi_verifactu_eu_b2c_oss', array( $this, 'filter_eu_b2c_oss' ), 10, 2 );
			add_filter( 'ywev_disable_vat_exception_same_country', '__return_true' );
			add_filter( 'ywev_enable_vat_number_same_country', '__return_true' );
			add_filter( 'ywev_get_vat_block_script_data', array($this, 'add_script_data') );
		}
	}

	/**
	 * Whether the store uses the classic (shortcode) checkout.
	 *
	 * @return bool
	 */
	private function is_classic_checkout(): bool {
		return \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default();
	}

	/**
	 * Get the EU VAT number from the customer billing field.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return string
	 */
	public function get_order_vat_number( WC_Order $order ): string {
		$vat = $order->get_meta( 'yweu_billing_vat', true );

		if ( empty( $vat ) ) {
			$vat = $order->get_meta( '_' . self::BILLING_FIELD_KEY, true );
		}

		if ( empty( $vat ) ) {
			$user_id = $order->get_user_id();

			if ( $user_id > 0 ) {
				$vat = get_user_meta( $user_id, self::BILLING_FIELD_KEY, true );
			}
		}

		return is_string( $vat ) ? $vat : '';
	}

	/**
	 * Store the VAT number in the WooCommerce session during classic checkout validation.
	 *
	 * YITH only sets the session inside country-confirmation checks, which may not run
	 * for every valid order (same country, hidden field, etc.).
	 *
	 * @param array    $posted_data Posted checkout data.
	 * @param \WP_Error $errors      Validation errors.
	 *
	 * @return void
	 */
	public function sync_classic_checkout_vat_session( $posted_data, $errors ) {
		unset( $errors );

		if ( ! $this->is_classic_checkout() || ! isset( WC()->session ) ) {
			return;
		}

		$vat = $this->get_posted_vat_number( is_array( $posted_data ) ? $posted_data : array() );

		if ( '' !== $vat ) {
			WC()->session->set( self::BILLING_FIELD_KEY, $vat );
		}
	}

	/**
	 * Resolve the VAT number submitted during classic checkout.
	 *
	 * @param array $posted_data Posted checkout data.
	 *
	 * @return string
	 */
	private function get_posted_vat_number( array $posted_data = array() ): string {
		if ( isset( $posted_data[ self::BILLING_FIELD_KEY ] ) ) {
			$vat = $this->normalize_vat_number( (string) $posted_data[ self::BILLING_FIELD_KEY ] );

			if ( '' !== $vat ) {
				return $vat;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ self::BILLING_FIELD_KEY ] ) ) {
			$vat = $this->normalize_vat_number( sanitize_text_field( wp_unslash( $_POST[ self::BILLING_FIELD_KEY ] ) ) );

			if ( '' !== $vat ) {
				return $vat;
			}
		}

		if ( isset( WC()->session ) ) {
			$vat = $this->normalize_vat_number( (string) WC()->session->get( self::BILLING_FIELD_KEY ) );

			if ( '' !== $vat ) {
				return $vat;
			}
		}

		return '';
	}

	/**
	 * Normalize a VAT number value from checkout.
	 *
	 * @param string $vat VAT number.
	 *
	 * @return string
	 */
	private function normalize_vat_number( $vat ): string {
		if ( ! is_string( $vat ) ) {
			return '';
		}

		$vat = str_replace( ' ', '', trim( $vat ) );

		if ( '' === $vat || '-' === $vat ) {
			return '';
		}

		return $vat;
	}

	/**
	 * Hide the PDF Invoices VAT field in the block checkout when YITH is active.
	 *
	 * @param array $data Block script data.
	 *
	 * @return array
	 */
	public function filter_block_checkout_script_data( array $data ): array {
		$data['showVat'] = ywpi_show_vat_field();

		return $data;
	}

	/**
	 * Add YITH EU VAT data to PDF customer info.
	 *
	 * @param array         $customer Customer data.
	 * @param bool|WC_Order $order    Order object.
	 *
	 * @return array
	 */
	public function add_customer_vat_info( array $customer, $order ): array {
		if ( ! $order instanceof WC_Order ) {
			$customer[ self::BILLING_FIELD_KEY ] = '123456789';

			return $customer;
		}

		$customer[ self::BILLING_FIELD_KEY ] = $this->get_order_vat_number( $order );

		return $customer;
	}

	/**
	 * Register YITH EU VAT placeholder in the template editor.
	 *
	 * @param array $placeholders Placeholder keys.
	 *
	 * @return array
	 */
	public function add_customer_info_placeholders( array $placeholders ): array {
		if ( in_array( self::BILLING_FIELD_KEY, $placeholders, true ) ) {
			return $placeholders;
		}

		$placeholders[] = self::BILLING_FIELD_KEY;

		return $placeholders;
	}

	/**
	 * Replace YITH EU VAT placeholder in PDF customer details.
	 *
	 * @param string $replace_value Current replacement value.
	 * @param string $match         Placeholder key.
	 * @param int    $order_id      Order ID.
	 *
	 * @return string
	 */
	public function replace_customer_vat_details( $replace_value, $match, $order_id ) {
		if ( self::BILLING_FIELD_KEY !== $match ) {
			return $replace_value;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return $replace_value;
		}

		$vat_number = $this->get_order_vat_number( $order );

		return '' !== $vat_number ? $vat_number : $replace_value;
	}

	/**
	 * Resolve customer tax identifier for Verifactu from YITH EU VAT and Spanish checkout fields.
	 *
	 * @param string   $vat_number Current VAT number.
	 * @param WC_Order $order      Order object.
	 *
	 * @return string
	 */
	public function filter_billing_vat_number( $vat_number, $order ): string {
		if ( ! $order instanceof WC_Order ) {
			return YITH_YWPI_WooCommerce_Order_Invoice_Context::normalize_identifier( (string) $vat_number );
		}

		$yith_vat = YITH_YWPI_WooCommerce_Order_Invoice_Context::normalize_identifier(
			$this->get_order_vat_number( $order )
		);

		if ( '' !== $yith_vat ) {
			return $yith_vat;
		}

		$document_number = YITH_YWPI_WooCommerce_Order_Invoice_Context::normalize_identifier(
			(string) $order->get_meta( '_billing_document_number' )
		);

		if ( '' !== $document_number ) {
			return $document_number;
		}

		return YITH_YWPI_WooCommerce_Order_Invoice_Context::normalize_identifier( (string) $vat_number );
	}

	/**
	 * Resolve whether EU B2C Verifactu payload must use OSS shape (no Spanish VAT lines).
	 *
	 * When YITH OSS applies destination VAT (above threshold or forced), Verifactu must use
	 * clave_regimen 17 + N2 and must not include Spanish tax breakdown.
	 *
	 * @param bool     $default_oss Default OSS flag from the factory.
	 * @param WC_Order $order       Order object.
	 *
	 * @return bool|null
	 */
	public function filter_eu_b2c_oss( $default_oss, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $default_oss;
		}

		if ( ! function_exists( 'yith_ywev_is_module_active' ) || ! yith_ywev_is_module_active( 'oss' ) ) {
			return $default_oss;
		}

		if ( $this->order_skips_oss_rules( $order ) ) {
			return false;
		}

		return $this->order_uses_oss_taxation( $order );
	}

	/**
	 * YITH skips OSS localization when B2B/reverse-charge signals are present.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return bool
	 */
	private function order_skips_oss_rules( WC_Order $order ): bool {
		if ( '' !== trim( (string) $order->get_billing_company() ) ) {
			return true;
		}

		if ( '' !== YITH_YWPI_WooCommerce_Order_Invoice_Context::normalize_identifier(
				$this->get_order_vat_number( $order )
			) ) {
			return true;
		}

		return (float) $order->get_meta( 'ywev_vat_exemption_amount' ) > 0;
	}

	/**
	 * Whether YITH OSS applied destination-country taxation on this order.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return bool
	 */
	private function order_uses_oss_taxation( WC_Order $order ): bool {
		$billing_country = strtoupper( (string) $order->get_billing_country() );
		$base_country    = strtoupper( (string) WC()->countries->get_base_country() );

		if ( '' === $billing_country || $billing_country === $base_country ) {
			return false;
		}

		if ( 'yes' === get_option( 'ywev_oss_force', 'no' ) ) {
			return true;
		}

		$max_threshold = (float) apply_filters( 'yith_ywev_oss_threshold_value', 10000 );
		$threshold     = (float) get_option( 'yith_ywev_oss_threshold', 0 );

		if ( $threshold >= $max_threshold ) {
			return true;
		}

		// Applied tax rates are authoritative. ywev_COUNTRY may store the billing country
		// (see YITH save_order_data + get_taxable_address) while below-threshold orders
		// still carry Spanish VAT from woocommerce_get_tax_location OSS rules.
		if ( YITH_YWPI_WooCommerce_Order_Invoice_Context::order_has_tax_from_country( $order, $base_country ) ) {
			return false;
		}

		if ( YITH_YWPI_WooCommerce_Order_Invoice_Context::order_has_tax_from_country( $order, $billing_country ) ) {
			return true;
		}

		$taxable_country = strtoupper( (string) $order->get_meta( 'ywev_COUNTRY', true ) );

		if ( '' !== $taxable_country ) {
			return $taxable_country !== $base_country;
		}

		return true;
	}

	/**
	 * Add custom script data to the VAT block.
	 *
	 * @param array $data The data.
	 *
	 * @return array
	 */
	public function add_script_data( $data ) {
		$data['is_pdf_invoice_active'] = true;
		$data['pdf_namespace'] = 'ywpi/electronic-fields';
		return $data;
	}
}