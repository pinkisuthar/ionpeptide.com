<?php
/**
 * Affiliates' invoices handling class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Invoices' ) ) {
	/**
	 * Invoices Handler
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Invoices {

		use YITH_WCAF_Trait_Singleton;

		/**
		 * Array of available billing fields to use in invoices
		 *
		 * @var array
		 */
		protected $available_billing_fields = array();

		/**
		 * Whether invoice subsystem is enabled or not.
		 *
		 * @var bool
		 */
		protected $invoice_required;

		/**
		 * Invoice mode
		 *
		 * @var string
		 */
		protected $invoice_mode;

		/**
		 * Url to example invoice
		 *
		 * @var string
		 */
		protected $invoice_example;

		/**
		 * String containing "Company" section of the invoice
		 *
		 * @var string
		 */
		protected $invoice_company;

		/**
		 * Array of billing fields
		 *
		 * @var array
		 */
		protected $invoice_fields;

		/**
		 * T&C field label
		 *
		 * @var string
		 */
		protected $invoice_terms_label;

		/**
		 * T&C field anchor url
		 *
		 * @var string
		 */
		protected $invoice_terms_anchor_url;

		/**
		 * T&C field anchor text
		 *
		 * @var string
		 */
		protected $invoice_terms_anchor_text;

		/**
		 * Enable T&C field
		 *
		 * @var bool
		 */
		protected $invoice_terms_show;

		/**
		 * Constructor method
		 */
		public function __construct() {
			// init class.
			$this->retrieve_options();
		}

		/**
		 * Retrieve options for invoices from db
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function retrieve_options() {
			$this->invoice_required          = yith_plugin_fw_is_true( get_option( 'yith_wcaf_payment_require_invoice', 'yes' ) );
			$this->invoice_mode              = get_option( 'yith_wcaf_payment_invoice_mode', 'both' );
			$this->invoice_example           = get_option( 'yith_wcaf_payment_invoice_example', '' );
			$this->invoice_company           = get_option( 'yith_wcaf_payment_invoice_company_section', '' );
			$this->invoice_fields            = get_option( 'yith_wcaf_payment_invoice_fields', array( 'first_name', 'last_name', 'address', 'city', 'vat' ) );
			$this->invoice_terms_label       = get_option( 'yith_wcaf_payment_invoice_terms_label', '' );
			$this->invoice_terms_anchor_url  = get_option( 'yith_wcaf_payment_invoice_terms_anchor_url', '' );
			$this->invoice_terms_anchor_text = get_option( 'yith_wcaf_payment_invoice_terms_anchor_text', '' );
			$this->invoice_terms_show        = yith_plugin_fw_is_true( get_option( 'yith_wcaf_payment_invoice_show_terms_field', 'no' ) );
		}

		/**
		 * Returns value of a specific option
		 *
		 * @param string $option Option to retrieve.
		 * @return mixed|bool Option value or false,when option doesn't exists
		 */
		public function get_option( $option ) {
			if ( ! isset( $this->$option ) ) {
				return false;
			}

			return $this->$option;
		}

		/**
		 * Returns an array containing all public options for the class
		 *
		 * @return array
		 */
		public function get_options() {
			$options        = array();
			$public_options = array(
				'invoice_mode',
				'invoice_example',
				'invoice_company',
				'invoice_fields',
				'invoice_terms_show',
				'invoice_terms_label',
				'invoice_terms_anchor_url',
				'invoice_terms_anchor_text',
			);

			foreach ( $public_options as $option ) {
				$options[ $option ] = $this->$option;
			}

			return $options;
		}

		/* === GETTER METHODS === */

		/**
		 * Returns the max size allowed for an invoice to be uploaded on the site (in Mb)
		 *
		 * @return int
		 */
		public static function get_invoice_max_size() {
			return apply_filters( 'yith_wcaf_max_invoice_size', 3 );
		}

		/**
		 * Return an array of fields used to create invoice
		 *
		 * @return array
		 */
		public function get_invoice_fields() {
			/**
			 * APPLY_FILTERS: yith_wcaf_invoice_fields
			 *
			 * Filters the fields used to create the invoice.
			 *
			 * @param array $invoice_fields Fields used to create the invoice.
			 */
			return apply_filters( 'yith_wcaf_invoice_fields', $this->invoice_fields );
		}

		/**
		 * Check whether invoices are required in order to perform a withdraw or not
		 *
		 * @return bool Whether invoices are required or not.
		 */
		public function are_invoices_required() {
			/**
			 * APPLY_FILTERS: yith_wcaf_are_invoices_required
			 *
			 * Filters whether invoices are required to perform a withdraw.
			 *
			 * @param bool $invoice_required Whether invoices are required or not.
			 */
			return apply_filters( 'yith_wcaf_are_invoices_required', ! ! $this->invoice_required );
		}

		/**
		 * Check whether invoices should be shown on frontend or not.
		 *
		 * @return bool Whether invoices should be shown or not.
		 */
		public function show_invoices_to_affiliate() {
			return apply_filters( 'yith_wcaf_are_invoices_required', $this->are_invoices_required() );
		}

		/**
		 * Check whether payment has invoice
		 *
		 * @param int $payment_id Payment ID.
		 *
		 * @return bool Whether invoice exists or not.
		 */
		public function has_invoice( $payment_id ) {
			return ! ! $this->get_invoice_path( $payment_id );
		}

		/**
		 * Get url to invoice
		 *
		 * @param int $payment_id Payment ID.
		 *
		 * @return string|bool Url to invoice, or false if there is no invoice
		 */
		public function get_invoice_url( $payment_id ) {
			$invoice = $this->get_invoice_path( $payment_id );

			if ( ! $invoice ) {
				return false;
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_get_invoice_url
			 *
			 * Filters the url to get the invoice.
			 *
			 * @param string $invoice_url Invoice url.
			 * @param int    $payment_id  Payment id.
			 */
			return apply_filters( 'yith_wcaf_get_invoice_url', YITH_WCAF_INVOICES_URL . $payment_id . '.pdf', $payment_id );
		}

		/**
		 * Get url to let user download invoice
		 *
		 * @param int $payment_id Payment ID.
		 *
		 * @return string|bool Url to download invoice, or false if there is no invoice
		 */
		public function get_invoice_publishable_url( $payment_id ) {
			$invoice = $this->get_invoice_path( $payment_id );

			if ( ! $invoice ) {
				return false;
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_get_invoice_publishable_url
			 *
			 * Filters the url to download the invoice.
			 *
			 * @param string $invoice_url Invoice url.
			 * @param int    $payment_id  Payment id.
			 */
			return apply_filters( 'yith_wcaf_get_invoice_publishable_url', wp_nonce_url( add_query_arg( 'download_payment_invoice', $payment_id ), 'download-invoice' ), $payment_id );
		}

		/**
		 * Get path to invoice
		 *
		 * @param int $payment_id Payment ID.
		 *
		 * @return string|bool Path to invoice, or false if there is no invoice
		 */
		public function get_invoice_path( $payment_id ) {
			/**
			 * APPLY_FILTERS: yith_wcaf_get_invoice_path
			 *
			 * Filters the path to get the invoice.
			 *
			 * @param string $invoice_path Invoice path.
			 * @param int    $payment_id   Payment id.
			 */
			$invoice_path = apply_filters( 'yith_wcaf_get_invoice_path', YITH_WCAF_INVOICES_DIR . $payment_id . '.pdf', $payment_id );

			if ( ! file_exists( $invoice_path ) ) {
				return false;
			}

			return $invoice_path;
		}

		/* === INVOICE METHODS === */

		/**
		 * Starts download of invoice when customer with correct permissions visit publishable link
		 *
		 * @param int $payment_id Id of the payment to that should be used to retrieve invoice.
		 * @throws Exception When there is an error with invoice download.
		 */
		public function download_invoice( $payment_id ) {
			if ( ! is_user_logged_in() ) {
				throw new Exception( _x( 'You do not have permission to access this content!', '[FRONTEND] Download invoice error message', 'yith-woocommerce-affiliates' ) );
			}

			$payment      = YITH_WCAF_Payment_Factory::get_payment( $payment_id );
			$invoice_path = $this->get_invoice_path( $payment_id );

			if ( $payment && $invoice_path && $payment->current_user_can( 'download_invoice' ) ) {
				WC_Download_Handler::download( $invoice_path, 0 );
				die;
			}

			throw new Exception( _x( 'There was an error while downloading your invoice; please, try again later!', '[FRONTEND] Download invoice error message', 'yith-woocommerce-affiliates' ) );

		}

		/**
		 * Create a new invoice and store it in the appropriate directory
		 *
		 * @param int    $payment_id Payment id (default file name).
		 * @param float  $amount     Amount to be reported in the invoice.
		 * @param array  $args       Array of additional params.
		 * @param string $from       Formatted from date.
		 * @param string $to         Formatted to date.
		 *
		 * @return void
		 */
		public function generate_invoice( $payment_id, $amount = false, $args = array(), $from = false, $to = false ) {
			// retrieve payment.
			$payment = YITH_WCAF_Payment_Factory::get_payment( $payment_id );

			if ( ! $payment ) {
				return;
			}

			// retrieve affiliate.
			$affiliate = $payment->get_affiliate();

			if ( ! $affiliate ) {
				return;
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_affiliate_invoice_template_args
			 *
			 * Filters the array with the arguments sent to the template to generate the payment invoice.
			 *
			 * @param array $args Array of arguments.
			 * @param YITH_WCAF_Payment $payment Payment object.
			 * @param YITH_WCAF_Affiliate $affiliate Affiliate object.
			 *
			 * @return array
			 */
			$args = apply_filters(
				'yith_wcaf_affiliate_invoice_template_args',
				wp_parse_args(
					$args,
					array_merge(
						$affiliate->get_invoice_profile(),
						array(
							'site_url'          => str_replace( array( 'https://', 'http://' ), '', get_site_url() ),
							'blog_name'         => get_bloginfo( 'name' ),
							'affiliate_landing' => YITH_WCAF_Dashboard()->get_dashboard_url(),
							'current_date'      => date_i18n( wc_date_format() ),
							'currency'          => get_woocommerce_currency(),
						)
					)
				),
				$payment,
				$affiliate
			);

			list( $site_url, $blog_name, $affiliate_landing, $current_date, $currency ) = yith_plugin_fw_extract( $args, 'site_url', 'blog_name', 'affiliate_landing', 'current_date', 'currency' );

			if ( ! $amount ) {
				$amount = $payment->get_amount();
			}

			if ( ! $from || ! $to ) {
				$commissions      = $payment->get_commissions();
				$first_commission = $commissions->current();
				$from             = $first_commission ? $first_commission->get_created_at() : false;
				$last_commission  = $commissions->end();
				$to               = $last_commission ? $last_commission->get_created_at() : false;
			}

			$replacements = array(
				'{{payment_id}}'        => $payment_id,
				'{{current_date}}'      => $current_date,
				'{{start_date}}'        => date_i18n( wc_date_format(), strtotime( $from ) ),
				'{{end_date}}'          => date_i18n( wc_date_format(), strtotime( $to ) ),
				'{{site_url}}'          => $site_url,
				'{{blog_name}}'         => $blog_name,
				/**
				 * APPLY_FILTERS: yith_wcaf_invoice_affiliate_landing
				 *
				 * Filters the affiliate landing in the invoice.
				 *
				 * @param string $affiliate_landing Affiliate landing.
				 */
				'{{affiliate_landing}}' => apply_filters( 'yith_wcaf_invoice_affiliate_landing', $affiliate_landing ),
				/**
				 * APPLY_FILTERS: yith_wcaf_invoice_currency
				 *
				 * Filters the currency to be used in the invoice.
				 *
				 * @param string $currency Currency.
				 */
				'{{withdraw_amount}}'   => wc_price( $amount, array( 'currency' => apply_filters( 'yith_wcaf_invoice_currency', $currency ) ) ),
				'{{company_section}}'   => nl2br( $this->invoice_company ),
				'{{affiliate_section}}' => $affiliate->get_formatted_invoice_profile(),
				/**
				 * APPLY_FILTERS: yith_wcaf_invoice_title
				 *
				 * Filters the invoice title.
				 *
				 * @param string $invoice_title Invoice title.
				 */
				'{{title}}'             => apply_filters( 'yith_wcaf_invoice_title', _x( 'Affiliate commission withdrawal', 'Withdraw invoice description', 'yith-woocommerce-affiliates' ) ),
				/**
				 * APPLY_FILTERS: yith_wcaf_invoice_description
				 *
				 * Filters the invoice description.
				 *
				 * @param string $invoice_description Invoice description.
				 */
				// translators: 1. Site url.
				'{{description}}'       => apply_filters( 'yith_wcaf_invoice_description', sprintf( _x( 'Affiliate commission withdrawal on %s', 'Withdraw invoice description', 'yith-woocommerce-affiliates' ), $blog_name ) ),
				/**
				 * APPLY_FILTERS: yith_wcaf_invoice_affiliate_program
				 *
				 * Filters the affiliate program name in the invoice.
				 *
				 * @param string $affiliate_program Affiliate program name.
				 */
				// translators: 1. Site url.
				'{{affiliate_program}}' => apply_filters( 'yith_wcaf_invoice_affiliate_program', sprintf( _x( '%s Affiliate Program', 'Withdraw invoice affiliate program name', 'yith-woocommerce-affiliates' ), $blog_name ) ),
			);

			// process replacement value for each field.
			$invoice_fields = array_keys( YITH_WCAF_Affiliates_Invoice_Profile::get_available_billing_fields() );

			foreach ( $invoice_fields as $field ) {
				$value = isset( $args[ $field ] ) ? $args[ $field ] : '';

				switch ( $field ) {
					case 'cif': // phpcs:ignore
						// translators: 1. Affiliate SSN.
						$value = $value ? sprintf( _x( 'SSN: %s', 'Invoice template', 'yith-woocommerce-affiliates' ), $value ) : '';
					case 'first_name':
					case 'last_name':
						if ( ! empty( $args['type'] ) && 'personal' !== $args['type'] ) {
							$value = '';
						}
						break;
					case 'vat': // phpcs:ignore
						// translators: 1. Affiliate VAT.
						$value = $value ? sprintf( _x( 'VAT: %s', 'Invoice template', 'yith-woocommerce-affiliates' ), $value ) : '';
					case 'company':
						if ( ! empty( $args['type'] ) && 'business' !== $args['type'] ) {
							$value = '';
						}
						break;
					case 'billing_state':
						if ( ! empty( $args['billing_country'] ) && ! empty( $value ) ) {
							$country_states = WC()->countries->get_states( $args['billing_country'] );

							if ( ! empty( $country_states ) ) {
								$value = isset( $country_states[ $value ] ) ? $country_states[ $value ] : $value;
								$value = ! empty( $value ) ? '(' . $value . ')' : '';
							}
						}
						break;
					case 'number':
						/**
						 * APPLY_FILTERS: yith_wcaf_invoice_number
						 *
						 * Filters the Invoice number for current document.
						 *
						 * @param string            $value      Value.
						 * @param int               $payment_id Payment ID.
						 * @param YITH_WCAF_Payment $payment    Payment object.
						 */
						$value = apply_filters( 'yith_wcaf_invoice_number', $value ? $value : $payment_id, $payment_id, $payment );
						break;
				}

				/**
				 * APPLY_FILTERS: yith_wcaf_invoice_replacement
				 *
				 * Filters the replaced value for the invoice field.
				 *
				 * @param string            $value      Value.
				 * @param array             $field      Field.
				 * @param array             $args       Array of arguments.
				 * @param int               $payment_id Payment ID.
				 * @param YITH_WCAF_Payment $payment    Payment object.
				 */
				$value = apply_filters( 'yith_wcaf_invoice_replacement', $value, $field, $args, $payment_id, $payment );

				$replacements[ "{{{$field}}}" ] = $value;
			}

			$replacements = array_map( 'wp_kses_post', $replacements );

			// retrieve invoice template.
			ob_start();
			yith_wcaf_get_template( 'affiliate-invoice.php', $args, 'invoices' );
			$invoice_html_template = ob_get_clean();
			$invoice_html_template = str_replace( array_keys( $replacements ), array_values( $replacements ), $invoice_html_template );

			// retrieve invoice CSS.
			ob_start();
			yith_wcaf_get_template( 'affiliate-invoice.css', array(), 'invoices' );
			$invoice_css = ob_get_clean();

			// generate pdf invoice.
			if ( ! class_exists( 'Mpdf' ) ) {
				include_once YITH_WCAF_DIR . 'vendor/autoload.php';
			}

			try {
				$mpdf = new \Mpdf\Mpdf();
				$mpdf->WriteHTML( $invoice_css, 1 );
				$mpdf->WriteHTML( $invoice_html_template, 2 );
				$template = $mpdf->Output( 'document', 'S' );

				$this->save_generated_invoice( $template, $payment_id . '.pdf' );
			} catch ( Exception $e ) {
				return;
			}
		}

		/**
		 * Move temp file uploaded by the user to its final destination
		 *
		 * @param string      $uploaded_file  Temp file name.
		 * @param string      $filename       New file name.
		 * @param string|bool $subfolder      Subfolder where file should be placed (referred to @see YITH_WCAF_INVOICES_DIR); false to save in main folder.
		 *
		 * @return bool Operation status
		 * @since 1.3.0
		 */
		public function save_uploaded_invoice( $uploaded_file, $filename, $subfolder = false ) {
			$destination_path = untrailingslashit( YITH_WCAF_INVOICES_DIR );

			if ( $subfolder ) {
				$destination_path .= '/' . $subfolder;
			}

			if ( ! file_exists( $destination_path ) ) {
				wp_mkdir_p( $destination_path );
			}

			$save_file_path = sprintf( '%s/%s', $destination_path, $filename );

			return move_uploaded_file( $uploaded_file, $save_file_path );
		}

		/**
		 * Save brand new PDF file into invoices folder
		 *
		 * @param string      $template   PDF raw template.
		 * @param string      $filename   New file name.
		 * @param string|bool $subfolder  Subfolder where file should be placed (referred to @see YITH_WCAF_INVOICES_DIR); false to save in main folder.
		 *
		 * @return bool Operation status
		 * @since 1.3.0
		 */
		public function save_generated_invoice( $template, $filename, $subfolder = false ) {
			global $wp_filesystem;

			$destination_path = YITH_WCAF_INVOICES_DIR;

			if ( $subfolder ) {
				$destination_path .= '/' . $subfolder;
			}

			if ( ! file_exists( $destination_path ) ) {
				wp_mkdir_p( $destination_path );
			}

			$save_file_path = sprintf( '%s/%s', $destination_path, $filename );

			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . '/wp-admin/includes/file.php';
				WP_Filesystem();
			}

			return $wp_filesystem->put_contents( $save_file_path, $template );
		}
	}
}

/**
 * Unique access to instance of YITH_WCAF_Invoices class
 *
 * @return \YITH_WCAF_Invoices
 * @since 1.0.0
 */
function YITH_WCAF_Invoices() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return YITH_WCAF_Invoices::get_instance();
}
