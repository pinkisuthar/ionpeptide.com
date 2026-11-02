<?php
/**
 * Affiliates' invoices profile handling class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Affiliates_Invoice_Profile' ) ) {
	/**
	 * Invoices Handler
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Affiliates_Invoice_Profile {

		/**
		 * Array of options for the available billing fields.
		 *
		 * @var array
		 */
		protected static $billing_fields = array();

		/**
		 * Array of available billing fields to use in invoices
		 *
		 * @var array
		 */
		protected static $available_billing_fields = array();

		/**
		 * Constructor method
		 */
		public static function init() {
			// init fields handling.
			add_action( 'yith_wcaf_settings_form_start', array( self::class, 'show_fields' ) );
			add_action( 'yith_wcaf_withdraw_modal_billing_fields', array( self::class, 'show_fields' ) );
		}

		/* === BILLING PROFILE === */

		/**
		 * Returns available fields for invoice
		 *
		 * @return array Available fields, as name => label array
		 */
		public static function get_available_billing_fields() {

			if ( empty( self::$available_billing_fields ) ) {
				/**
				 * APPLY_FILTERS: yith_wcaf_invoice_fields
				 *
				 * Filters the billing fields for the invoice.
				 *
				 * @param array $invoice_fields Billing fields for the invoice.
				 */
				self::$available_billing_fields = apply_filters(
					'yith_wcaf_invoice_fields',
					array(
						'number'            => _x( 'Invoice number', '[ADMIN] Invoice fields label', 'yith-woocommerce-affiliates' ),
						'type'              => _x( 'Type', '[ADMIN] Invoice fields label', 'yith-woocommerce-affiliates' ),
						'first_name'        => _x( 'First name', '[ADMIN] Invoice fields label', 'yith-woocommerce-affiliates' ),
						'last_name'         => _x( 'Last name', '[ADMIN] Invoice fields label', 'yith-woocommerce-affiliates' ),
						'company'           => _x( 'Company', '[ADMIN] Invoice fields label', 'yith-woocommerce-affiliates' ),
						'billing_country'   => _x( 'Billing country', '[ADMIN] Invoice fields label', 'yith-woocommerce-affiliates' ),
						'billing_state'     => _x( 'Billing state', '[ADMIN] Invoice fields label', 'yith-woocommerce-affiliates' ),
						'billing_city'      => _x( 'Billing city', '[ADMIN] Invoice fields label', 'yith-woocommerce-affiliates' ),
						'billing_postcode'  => _x( 'Billing ZIP code', '[ADMIN] Invoice fields label', 'yith-woocommerce-affiliates' ),
						'billing_address_1' => _x( 'Billing address', '[ADMIN] Invoice fields label', 'yith-woocommerce-affiliates' ),
						'vat'               => _x( 'Company VAT', '[ADMIN] Invoice fields label', 'yith-woocommerce-affiliates' ),
						'cif'               => _x( 'SSN', '[ADMIN] Invoice fields label', 'yith-woocommerce-affiliates' ),
					)
				);
			}

			return self::$available_billing_fields;
		}

		/**
		 * Returns the fields used for invoice creation
		 *
		 * @param string $context Context of the operation.
		 * @return array Array of formatted billing fields.
		 */
		public static function get_billing_fields( $context = 'view' ) {
			$invoice_fields = YITH_WCAF_Invoices()->get_invoice_fields();

			if ( ! $invoice_fields ) {
				return array();
			}

			if ( 'edit' === $context ) {
				return $invoice_fields;
			}

			$fields = array();

			foreach ( $invoice_fields as $field ) {
				$fields[ $field ] = self::get_billing_field( $field );
			}

			return $fields;
		}

		/**
		 * Returns the fields used for billing profile
		 *
		 * @param string $context Context of the operation.
		 * @return array Array of formatted billing fields.
		 */
		public static function get_billing_profile_fields( $context = 'view' ) {
			$fields = self::get_billing_fields( $context );

			if ( isset( $fields['number'] ) ) {
				$fields['number']['required'] = false;
			}

			return $fields;
		}

		/**
		 * Returns the fields used for withdraw action
		 *
		 * @param string $context Context of the operation.
		 * @return array Array of formatted billing fields.
		 */
		public static function get_withdraw_fields( $context = 'view' ) {
			/**
			 * APPLY_FILTERS: yith_wcaf_show_complete_invoice_form
			 *
			 * Filters whether to show all fields in the form to withdraw amount.
			 *
			 * @param bool $show_all_fields Whether to show all fields in the form or not.
			 */
			if ( apply_filters( 'yith_wcaf_show_complete_invoice_form', false ) ) {
				$fields = self::get_billing_fields( $context );
			} else {
				$fields = array(
					'number' => self::get_billing_field( 'number' ),
				);
			}

			$fields['number']['required'] = true;

			return $fields;
		}

		/**
		 * Returns array of options for a specific billing field
		 *
		 * @param string $field_id Field to retrieve.
		 * @return array Array of option for the specified field.
		 */
		public static function get_billing_field( $field_id ) {
			$fields = self::maybe_init_billing_fields();

			if ( ! isset( $fields[ $field_id ] ) ) {
				/**
				 * APPLY_FILTERS: yith_wcaf_billing_form_field
				 *
				 * Filters the billing field in the form.
				 *
				 * @param bool   $billing_field Billing field.
				 * @param string $field_id      Field id.
				 * @param array  $fields        Fields.
				 */
				return apply_filters( 'yith_wcaf_billing_form_field', false, $field_id, $fields );
			}

			return $fields[ $field_id ];
		}

		/**
		 * Validate fields submitted as billing profile
		 *
		 * @param array  $posted  Array of submitted values to sanitize.
		 * @param string $context Context to use to retrieve fields.
		 *
		 * @return array Array of sanitized values to process
		 * @throws Exception When a problem occurs with fields validation process.
		 */
		public static function validate_billing_fields( $posted, $context = '' ) {
			if ( $context ) {
				$getter = "get_{$context}_fields";
			}

			if ( ! isset( $getter ) || ! method_exists( self::class, $getter ) ) {
				$getter = 'get_billing_fields';
			}

			$fields = self::$getter( 'view' );

			return yith_wcaf_parse_settings( $posted, $fields );
		}

		/**
		 * Init billing fields and return them
		 *
		 * @return array Array of billing fields.
		 */
		protected static function maybe_init_billing_fields() {
			if ( empty( self::$billing_fields ) ) {
				$fields = WC()->countries->get_address_fields();

				// rename general fields.
				$fields['first_name'] = $fields['billing_first_name'];
				$fields['last_name']  = $fields['billing_last_name'];

				// add custom invoice fields.
				$fields = array_merge(
					$fields,
					array(
						'number'  => array(
							'label'        => _x( 'Invoice number', '[FRONTEND] Invoice fields label', 'yith-woocommerce-affiliates' ),
							'required'     => false,
							'type'         => 'text',
							'class'        => array( 'form-row-wide' ),
							'autocomplete' => 'invoice-number',
							'priority'     => 100,
						),
						'vat'     => array(
							'label'        => _x( 'VAT number', '[FRONTEND] Invoice fields label', 'yith-woocommerce-affiliates' ),
							'required'     => true,
							'type'         => 'text',
							'class'        => array( 'form-row-wide' ),
							'autocomplete' => 'vat-number',
							'priority'     => 100,
						),
						'cif'     => array(
							'label'        => _x( 'SSN', '[FRONTEND] Invoice fields label', 'yith-woocommerce-affiliates' ),
							'required'     => true,
							'type'         => 'text',
							'class'        => array( 'form-row-wide' ),
							'autocomplete' => 'cif',
							'priority'     => 100,
						),
						'company' => array(
							'label'        => _x( 'Company', '[FRONTEND] Invoice fields label', 'yith-woocommerce-affiliates' ),
							'required'     => true,
							'type'         => 'text',
							'class'        => array( 'form-row-wide' ),
							'autocomplete' => 'company',
							'priority'     => 100,
						),
						'type'    => array(
							'required' => true,
							'type'     => 'radio',
							'options'  => array(
								'personal' => _x( 'Personal', '[FRONTEND] Invoice fields label', 'yith-woocommerce-affiliates' ),
								'business' => _x( 'Business', '[FRONTEND] Invoice fields label', 'yith-woocommerce-affiliates' ),
							),
							'default'  => 'business',
							'class'    => array( 'form-row-wide', 'radio-checkout' ),
							'priority' => 5,
						),
					)
				);

				// apply changes to fields preset.
				foreach ( $fields as $field_id => & $field ) {
					switch ( $field_id ) {
						case 'first_name':
						case 'last_name':
						case 'cif':
							$field['deps'] = array(
								'id'    => 'type',
								'value' => 'personal',
							);
							break;
						case 'company':
						case 'vat':
							$field['deps'] = array(
								'id'    => 'type',
								'value' => 'business',
							);
							break;
						case 'billing_country':
							$field['id']          = $field_id;
							$field['class']       = array( 'form-row-first' );
							$field['input_class'] = array( 'js_field-country' );
							break;
						case 'billing_state':
							$field['id']          = $field_id;
							$field['type']        = is_admin() ? 'text' : 'state'; // on admin side script behaves differently and won't accept an hidden state.
							$field['class']       = array( 'form-row-last' );
							$field['input_class'] = array( 'js_field-state' );
							$field['deps']        = array(
								'id'    => 'billing_country',
								'value' => array_keys( array_filter( WC()->countries->get_states() ) ),
							);
							break;
						case 'billing_city':
							$field['id']    = $field_id;
							$field['class'] = array( 'form-row-first' );
							break;
						case 'billing_postcode':
							$field['id']    = $field_id;
							$field['class'] = array( 'form-row-last' );
							break;
					}
				}

				self::$billing_fields = $fields;
			}

			return self::$billing_fields;
		}

		/* === PRINT METHODS === */

		/**
		 * Show billing fields when necessary
		 *
		 * @param YITH_WCAF_Abstract_Object $item Affiliate object or anything with ->get_affiliate() method; if not provided, current affiliate will be used instead.
		 *
		 * @return void
		 */
		public static function show_fields( $item = false ) {
			if ( ! self::should_show_fields() ) {
				return;
			}

			if ( ! $item ) {
				$affiliate = YITH_WCAF_Affiliate_Factory::get_current_affiliate();
			} elseif ( $item instanceof YITH_WCAF_Affiliate ) {
				$affiliate = $item;
			} elseif ( method_exists( $item, 'get_affiliate' ) ) {
				$affiliate = $item->get_affiliate();
			} else {
				$affiliate = false;
			}

			$fields  = self::get_fields_to_show();
			$profile = $affiliate ? $affiliate->get_invoice_profile() : array();

			if ( empty( $fields ) ) {
				return;
			}

			self::maybe_open_form_container();

			foreach ( $fields as $field_key => $field ) {
				$field_name    = self::get_field_name( $field_key );
				$field_default = isset( $profile[ $field_key ] ) ? $profile[ $field_key ] : null;

				/**
				 * APPLY_FILTERS: yith_wcaf_invoice_$field_key_label
				 *
				 * Filters the label for the invoice field.
				 * <code>$field_key</code> will be replaced with the key for each field.
				 *
				 * @param string $label Field label.
				 * @param array  $field Field.
				 */
				$field['label'] = apply_filters( "yith_wcaf_invoice_{$field_key}_label", isset( $field['label'] ) ? $field['label'] : '', $field );

				/**
				 * APPLY_FILTERS: yith_wcaf_invoice_$field_key_required
				 *
				 * Filters whether the invoice field will be required.
				 * <code>$field_key</code> will be replaced with the key for each field.
				 *
				 * @param bool  $is_field_required Whether the field is required or not.
				 * @param array $field Field.
				 */
				$field['required'] = apply_filters( "yith_wcaf_invoice_{$field_key}_required", isset( $field['required'] ) ? $field['required'] : false, $field );
				$field['id']       = self::get_field_id( $field_key );

				// set affiliate country when processing state.
				if ( 'billing_state' === $field_key ) {
					$field['country'] = isset( $profile['billing_country'] ) ? $profile['billing_country'] : null;
				}

				// add dependencies attributes.
				if ( isset( $field['deps'] ) ) {
					$field['custom_attributes'] = array_merge(
						isset( $field['custom_attributes'] ) ? $field['custom_attributes'] : array(),
						array(
							'data-dep-target' => self::get_field_id( $field_key ),
							'data-dep-id'     => self::get_field_id( $field['deps']['id'] ),
							'data-dep-value'  => is_scalar( $field['deps']['value'] ) ? $field['deps']['value'] : wp_json_encode( $field['deps']['value'] ),
						)
					);
				}

				woocommerce_form_field( $field_name, $field, YITH_WCAF_Form_Handler::get_posted_data( $field_name, $field_default ) );
			}

			self::maybe_close_form_container();
		}

		/**
		 * Checks whether we should show billing fields for the affiliate
		 *
		 * @return bool Whether we should show billing fields for the affiliate
		 */
		public static function should_show_fields() {
			return YITH_WCAF_Withdraws()->is_withdraw_enabled() && YITH_WCAF_Invoices()->are_invoices_required();
		}

		/**
		 * Returns an array of fields to show
		 *
		 * @reutrn array Array of fields to show.
		 */
		public static function get_fields_to_show() {
			$current_action = current_action();

			// remove number when editing general invoice profile.
			if ( 'yith_wcaf_settings_form_start' === $current_action ) {
				$fields = self::get_billing_profile_fields( 'view' );
			} elseif ( 'yith_wcaf_withdraw_modal_billing_fields' === $current_action ) {
				$fields = self::get_withdraw_fields( 'view' );
			} else {
				$fields = self::get_billing_fields( 'view' );
			}

			return $fields;
		}

		/**
		 * Returns ID to use for a specific field
		 *
		 * @param string $field_key Key of the field.
		 * @return string ID to use for the field.
		 */
		public static function get_field_id( $field_key ) {
			$field = self::get_billing_field( $field_key );

			if ( $field && isset( $field['id'] ) ) {
				return $field['id'];
			}

			return "invoice_$field_key";
		}

		/**
		 * Returns name to use for a specific field
		 *
		 * @param string $field_key Key of the field.
		 * @return string Name to use for the field.
		 */
		public static function get_field_name( $field_key ) {
			$current_action = current_action();
			$field_name     = $field_key;

			if ( in_array( $current_action, array( 'yith_wcaf_settings_form_start', 'yith_wcaf_payment_details_panel' ), true ) ) {
				$field_name = "invoice[$field_name]";
			}

			return $field_name;
		}

		/**
		 * Wrap fields when required
		 */
		public static function maybe_open_form_container() {
			$current_action = current_action();

			if ( 'yith_wcaf_settings_form_start' === $current_action ) :
				?>
				<div class="settings-box">
				<h3><?php echo esc_html_x( 'Billing info', '[FRONTEND] Billing fields form', 'yith-woocommerce-affiliates' ); ?></h3>
				<?php
			endif;
		}

		/**
		 * Closes fields wrap when was previously opened
		 */
		public static function maybe_close_form_container() {
			$current_action = current_action();

			if ( 'yith_wcaf_settings_form_start' === $current_action ) :
				?>
				</div>
				<?php
			endif;
		}
	}
}
