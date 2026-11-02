<?php
/**
 * Affiliates' withdraw handling class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Withdraws' ) ) {
	/**
	 * Withdraws Handler
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Withdraws {

		use YITH_WCAF_Trait_Singleton;

		/**
		 * Whether withdraw are enabled or not.
		 *
		 * @var bool
		 */
		protected $withdraw_enabled;

		/**
		 * Threshold to access withdraw
		 *
		 * @var float
		 */
		protected $payment_threshold;

		/**
		 * Constructor method
		 */
		public function __construct() {
			// init class.
			$this->retrieve_options();
		}

		/* === INIT METHODS === */

		/**
		 * Retrieve options for withdrawals from db
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function retrieve_options() {
			$this->withdraw_enabled  = 'let_user_request' === get_option( 'yith_wcaf_payment_type', 'manually' );
			$this->payment_threshold = (float) get_option( 'yith_wcaf_payment_threshold', 0 );
		}

		/* === GETTERS === */

		/**
		 * Returns currency used for the withdrawal
		 *
		 * @return string
		 */
		public function get_currency() {
			return apply_filters( 'yith_wcaf_withdraw_currency', get_woocommerce_currency() );
		}

		/**
		 * Returns currency symbol used for the withdrawal
		 *
		 * @return string
		 */
		public function get_currency_symbol() {
			return apply_filters( 'yith_wcaf_withdraw_currency_symbol', get_woocommerce_currency_symbol( $this->get_currency() ) );
		}

		/**
		 * Checks whether we should process withdraw request
		 *
		 * @return bool Whether to process withdraw.
		 */
		public function should_process_withdraw() {
			$affiliate = YITH_WCAF_Affiliate_Factory::get_current_affiliate();
			$res       = $this->is_withdraw_enabled() && $affiliate && ! is_wp_error( $affiliate->can_withdraw() );

			/**
			 * APPLY_FILTERS: yith_wcaf_should_process_withdraw
			 *
			 * Filters whether the withdraw request should be processed.
			 *
			 * @param bool $res Whether to process withdraw request or not.
			 */
			return apply_filters( 'yith_wcaf_should_process_withdraw', $res );
		}

		/**
		 * Whether should show
		 *
		 * @return bool Whether to show withdraw popup.
		 */
		public function should_show_withdraw_popup() {
			$affiliate = YITH_WCAF_Affiliate_Factory::get_current_affiliate();
			$res       = $this->is_withdraw_enabled() && $affiliate && ! is_wp_error( $affiliate->can_withdraw() );

			/**
			 * APPLY_FILTERS: yith_wcaf_should_show_withdraw_popup
			 *
			 * Filters whether to show the withdraw popup.
			 *
			 * @param bool $res Whether to show the withdraw popup or not.
			 */
			return apply_filters( 'yith_wcaf_should_show_withdraw_popup', $res );
		}

		/**
		 * Checks whether withdraws are enabled
		 *
		 * @return bool Whether withdraws are enabled.
		 */
		public function is_withdraw_enabled() {
			/**
			 * APPLY_FILTERS: yith_wcaf_is_withdraw_enabled
			 *
			 * Filters whether the withdraws are enabled.
			 *
			 * @param bool $withdraw_enabled Whether the withdraws are enabled or not.
			 */
			return apply_filters( 'yith_wcaf_is_withdraw_enabled', $this->withdraw_enabled );
		}

		/**
		 * Returns amount for minimum amount for withdraw
		 *
		 * @return float Minimum amount for withdraw
		 */
		public function get_minimum_withdraw() {
			/**
			 * APPLY_FILTERS: yith_wcaf_payment_threshold
			 *
			 * Filters the minimum amount to be able to request a withdraw.
			 *
			 * @param float $minimum_amount Minimum amount to request a withdraw.
			 */
			return (float) apply_filters( 'yith_wcaf_payment_threshold', $this->payment_threshold );
		}

		/* === PROCESS WITHDRAW === */

		/**
		 * Process withdraw and created payment/invoice
		 *
		 * @param array $posted Array of posted values.
		 *
		 * @throws Exception When a check fails during withdraw processing.
		 */
		public function process_withdraw( $posted ) {
			$affiliate = YITH_WCAF_Affiliate_Factory::get_current_affiliate();

			// validate affiliate.
			if ( ! $affiliate ) {
				throw new Exception( _x( 'You must be a logged in affiliate in order to request a withdrawal.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
			}

			// check if affiliate can withdraw.
			if ( ! $this->should_process_withdraw() ) {
				throw new Exception( _x( 'We cannot process your request at the moment; please re-try later.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
			}

			/**
			 * DO_ACTION: yith_wcaf_before_process_withdraw_request
			 *
			 * Allows to trigger some action before processing the withdraw request.
			 *
			 * @param array               $posted    Array of posted values.
			 * @param YITH_WCAF_Affiliate $affiliate Affiliate object.
			 */
			do_action( 'yith_wcaf_before_process_withdraw_request', $posted, $affiliate );

			$invoice_options = YITH_WCAF_Invoices()->get_options();
			$invoice_profile = $affiliate->get_invoice_profile();
			$invoice_mode    = $invoice_options['invoice_mode'];

			if ( 'both' === $invoice_mode ) {
				$invoice_mode = isset( $posted['invoice_mode'] ) ? sanitize_text_field( wp_unslash( $posted['invoice_mode'] ) ) : 'generate';
			}

			if ( YITH_WCAF_Invoices()->are_invoices_required() ) {
				// validate terms.
				if ( $invoice_options['invoice_terms_show'] && ! isset( $posted['terms'] ) ) {
					throw new Exception( _x( 'Please, accept our Terms & Conditions.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
				}

				// then proceed with mode-specific fields.
				if ( 'upload' === $invoice_mode ) {
					// check if user uploaded invoice correctly.
					$post_file = isset( $_FILES['invoice_file'] ) ? $_FILES['invoice_file'] : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

					if ( ! $post_file ) {
						throw new Exception( _x( 'Please, upload your invoice in order to continue.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
					}

					if ( ! empty( $post_file['error'] ) ) {
						switch ( $post_file['error'] ) {
							case 1:
							case 2:
								// translators: 1. Max invoice size in Mb.
								throw new Exception( sprintf( _x( 'The file you uploaded exceeded size limit of %dMB; please try again with a smaller file.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ), YITH_WCAF_Invoices::get_invoice_max_size() ) );
							default:
								throw new Exception( _x( 'There was an error with the upload; please try again later.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
						}
					}

					// check uploaded file to see if ti matches requirements.
					if ( empty( $post_file['name'] ) || empty( $post_file['tmp_name'] ) ) {
						throw new Exception( _x( 'There was an error with the invoice file you uploaded; please, try again.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
					}

					$file_name = sanitize_file_name( strtolower( $post_file['name'] ) );

					/**
					 * APPLY_FILTERS: yith_wcaf_invoice_upload_allowed_extensions
					 *
					 * Filters the allowed file extensions for the invoice upload.
					 *
					 * @param array $allowed_extensions Array of allowed extensions for the invoice upload.
					 */
					$allowed_ext_array = apply_filters( 'yith_wcaf_invoice_upload_allowed_extensions', array( 'pdf' ) );
					$file_ext          = pathinfo( $file_name, PATHINFO_EXTENSION );

					if ( ! empty( $allowed_ext_array ) && ( ! in_array( $file_ext, $allowed_ext_array, true ) ) ) {
						throw new Exception( _x( 'The invoice file you selected has an invalid extension; please, choose another file.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
					}

					/**
					 * APPLY_FILTERS: yith_wcaf_max_invoice_size
					 *
					 * Filters the maximum size for the invoice.
					 *
					 * @param int $max_size Maximum size for the invoice.
					 */
					$max_size_byte = 1048576 * apply_filters( 'yith_wcaf_max_invoice_size', 3 );

					if ( $max_size_byte && $post_file['size'] > $max_size_byte ) {
						throw new Exception( _x( 'The invoice file you selected is too big; please, choose another file.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
					}
				} elseif ( 'generate' === $invoice_mode ) {
					// sanitize invoice information.
					try {
						$billing_profile     = YITH_WCAF_Affiliates_Invoice_Profile::validate_billing_fields( $posted, 'withdraw' );
						$new_invoice_profile = YITH_WCAF_Affiliates_Invoice_Profile::validate_billing_fields(
							array_merge(
								$invoice_profile,
								$billing_profile
							)
						);
					} catch ( Exception $e ) {
						if ( apply_filters( 'yith_wcaf_show_complete_invoice_form', false ) ) {
							// if we're showing the complete billing form inside withdraw modal, send back specific error message about the error encountered.
							throw $e;
						} else {
							// otherwise generically ask customer to go and check billing profile on settings.
							throw new Exception( _x( 'You have an error in your billing profile; please review it and try again.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
						}
					}
				} else {
					throw new Exception( _x( 'You need to enter a valid invoice in order to continue with your withdrawal request.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
				}
			}

			// validate amount requirements.
			$max_withdraw = $affiliate->get_balance();
			$min_withdraw = get_option( 'yith_wcaf_payment_threshold', 0 );
			$min_withdraw = max( 0, floatval( $min_withdraw ) );

			$payment_amount = isset( $posted['withdraw_amount'] ) ? floatval( $posted['withdraw_amount'] ) : false;

			// check amount submitted.
			if ( empty( $payment_amount ) || $payment_amount < 0 ) {
				throw new Exception( _x( 'Please, enter a valid amount to withdraw.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_withdraw_amount_allow_exceeding_max
			 *
			 * Filters whether to allow the withdraw amount exceed the maximum available amount to withdraw.
			 *
			 * @param bool $allow_exceed_max_withdraw Whether to allow to withdraw over the max amount or not.
			 */
			if ( apply_filters( 'yith_wcaf_withdraw_amount_allow_exceeding_max', true ) && ( $payment_amount <= $min_withdraw - 0.01 || $payment_amount > $max_withdraw + 0.01 ) ) {
				throw new Exception( _x( 'The payment amount doesn\'t match the requirements; please, update your request and try again.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
			}

			// let's retrieve related commissions.
			$commissions = YITH_WCAF_Commissions()->get_commissions_for_amount( $affiliate->get_id(), $payment_amount );

			if ( ! $commissions || $commissions->is_empty() ) {
				throw new Exception( _x( 'Couldn\'t find commissions for the requested amount; please, update your request and try again.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
			}

			// let's calculate start and end dates.
			if ( ! empty( $commissions ) ) {
				$first_commission = $commissions->current();
				$formatted_from   = $first_commission ? $first_commission->get_created_at() : false;
				$last_commission  = $commissions->end();
				$formatted_to     = $last_commission ? $last_commission->get_created_at() : false;
			}

			// everything's gold! Let's proceed with payment creation.
			$res     = YITH_WCAF_Payments()->register_payment( $commissions->get_ids(), false );
			$payment = $res['status'] ? array_shift( $res['payments'] ) : false;

			if ( ! $payment ) {
				throw new Exception( _x( 'Sorry, there was an error while processing your request; please, try again later.', '[FRONTEND] Withdraw request error message', 'yith-woocommerce-affiliates' ) );
			}

			// now that we have payment ID, we can process invoice (if required).
			if ( YITH_WCAF_Invoices()->are_invoices_required() ) {
				if ( 'upload' === $invoice_mode ) {
					// move tmp file to upload dir.
					YITH_WCAF_Invoices()->save_uploaded_invoice( $post_file['tmp_name'], $payment->get_id() . '.' . $file_ext );
				} elseif ( 'generate' === $invoice_mode ) {
					// generate a new pdf invoice and store it.
					YITH_WCAF_Invoices()->generate_invoice( $payment->get_id(), $payment_amount, $new_invoice_profile, $formatted_from, $formatted_to );

					// save new invoice profile.
					$affiliate->set_invoice_profile( $new_invoice_profile );
					$affiliate->save();
				}
			}

			/**
			 * DO_ACTION: yith_wcaf_after_process_withdraw_request
			 *
			 * Allows to trigger some action after processing the withdraw request.
			 *
			 * @param int                 $payment_id Payment id.
			 * @param YITH_WCAF_Affiliate $affiliate  Affiliate object.
			 */
			do_action( 'yith_wcaf_after_process_withdraw_request', $payment->get_id(), $affiliate );
		}
	}
}

/**
 * Unique access to instance of YITH_WCAF_Withdraws class
 *
 * @return \YITH_WCAF_Withdraws
 * @since 1.0.0
 */
function YITH_WCAF_Withdraws() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return YITH_WCAF_Withdraws::get_instance();
}
