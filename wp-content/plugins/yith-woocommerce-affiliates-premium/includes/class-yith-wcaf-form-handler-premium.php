<?php
/**
 * Form handler class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH/Affiliates/Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Form_Handler_Premium' ) ) {
	/**
	 * This class will handle various all forms submitted by the user
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Form_Handler_Premium extends YITH_WCAF_Form_Handler {

		/**
		 * Adds premium handler to the list of defined ones
		 *
		 * @param string $context Context of the operation.
		 * @return array List of filtered handlers.
		 */
		public static function get_handlers( $context = 'view' ) {
			if ( ! self::$handlers ) {
				self::$handlers = array_merge(
					parent::get_handlers( 'edit' ),
					array(
						'apply_promo'             => array(
							'nonce_validation' => false,
							'action_field'     => YITH_WCAF_Promo::get_apply_promo_param(),
							'fields'           => array(
								YITH_WCAF_Promo::get_apply_promo_param() => array(),
							),
						),
						'download_invoice'        => array(
							'nonce_name' => '_wpnonce',
							'fields'     => array(
								'download_payment_invoice' => array(
									'type' => 'number',
								),
							),
						),
						'save_affiliate_settings' => array(
							'nonce_action' => 'yith-wcaf-save-affiliate-settings',
							'fields'       => array_merge(
								array(
									'payment_email' => array(),
									'profile'       => YITH_WCAF_Affiliates_Profile::get_settings_fields( 'view' ),
									'invoice'       => YITH_WCAF_Affiliates_Invoice_Profile::should_show_fields() ? YITH_WCAF_Affiliates_Invoice_Profile::get_billing_profile_fields( 'view' ) : array(),
									'notify_pending_commissions' => array(
										'type' => 'checkbox',
									),
									'notify_paid_commissions' => array(
										'type' => 'checkbox',
									),
								),
								YITH_WCAF_Gateways::get_available_gateways_fields()
							),
						),
					)
				);
			}

			if ( 'view' === $context ) {
				/**
				 * APPLY_FILTERS: yith_wcaf_form_handlers
				 *
				 * Filters the form handlers.
				 *
				 * @param array $handlers Form handlers.
				 */
				return apply_filters( 'yith_wcaf_form_handlers', self::$handlers );
			}

			return self::$handlers;
		}

		/* === PROMO HANDLING === */

		/**
		 * Apply promo when customer visit link provided by affiliate
		 *
		 * @param array $fields Validated fields.
		 *
		 * @return void
		 * @since 1.7.6
		 */
		public static function apply_promo( $fields ) {
			$promo_handler = YITH_WCAF_Promo();

			if ( ! YITH_WCAF_Coupons()->are_coupons_enabled() ) {
				return;
			}

			$apply_promo_param = $promo_handler->get_apply_promo_param();
			$coupon_code       = isset( $fields[ $apply_promo_param ] ) ? urldecode( $fields[ $apply_promo_param ] ) : false;

			if ( ! $coupon_code ) {
				return;
			}

			$coupon          = new WC_Coupon( $coupon_code );
			$coupon_referrer = $coupon->get_meta( 'coupon_referrer' );

			if ( ! $coupon_referrer ) {
				return;
			}

			$promo_handler->apply( $coupon->get_code() );
		}

		/* === INVOICE DOWNLOAD === */

		/**
		 * Download invoice created for a specific payment
		 *
		 * @param array $fields Array of sanitized fields.
		 * @throws Exception When something fails with download process.
		 */
		public static function download_invoice( $fields ) {
			$payment_id = intval( $fields['download_payment_invoice'] );

			YITH_WCAF_Invoices()->download_invoice( $payment_id );
		}

		/* === AFFILIATE SETTINGS === */

		/**
		 * Registers affiliate's preferences
		 *
		 * @param array $fields Submitted and sanitized fields.
		 * @throws Exception When an error occurs with data processing.
		 * @retuns void.
		 */
		public static function save_affiliate_settings( $fields ) {
			if ( ! is_user_logged_in() ) {
				throw new Exception( _x( 'Sorry, you\'re not allowed to process this action.', '[FRONTEND] Affiliate settings error message', 'yith-woocommerce-affiliates' ) );
			}

			$affiliate = YITH_WCAF_Affiliate_Factory::get_current_affiliate();

			if ( ! $affiliate ) {
				throw new Exception( _x( 'Sorry, you\'re not allowed to process this action.', '[FRONTEND] Affiliate settings error message', 'yith-woocommerce-affiliates' ) );
			}

			// process affiliate profile.
			if ( ! empty( $fields['profile'] ) ) {
				foreach ( $fields['profile'] as $key => $value ) {
					$affiliate->update_meta_data( $key, $value );
				}
			}

			// process billing profile.
			if ( YITH_WCAF_Affiliates_Invoice_Profile::should_show_fields() && ! empty( $fields['invoice'] ) ) {
				$affiliate->set_invoice_profile( $fields['invoice'] );
			}

			// process gateway options.
			if ( YITH_WCAF_Gateways::should_show_fields() ) {
				$gateways = YITH_WCAF_Gateways::get_available_gateways_list();

				foreach ( array_keys( $gateways ) as $gateway_id ) {
					if ( ! isset( $fields[ $gateway_id ] ) ) {
						continue;
					}

					// special handling for PayPal email which will be considered payment_email if none is submitted.
					if ( 'paypal' === $gateway_id && ! empty( $fields[ $gateway_id ]['paypal_email'] ) && empty( $fields['payment_email'] ) ) {
						$fields['payment_email'] = $fields[ $gateway_id ]['paypal_email'];
					}

					$affiliate->set_gateway_preferences( $gateway_id, $fields[ $gateway_id ] );
				}
			} else {
				$affiliate->set_payment_email( $fields['payment_email'] );
			}

			// process payment email.
			$affiliate->set_payment_email( $fields['payment_email'] );

			// process notify options.
			$affiliate->set_notify( 'pending_commission', $fields['notify_pending_commissions'] );
			$affiliate->set_notify( 'paid_commission', $fields['notify_paid_commissions'] );

			// finally save everything into DB.
			$affiliate->save();

			/**
			 * DO_ACTION: yith_wcaf_save_affiliate_settings
			 *
			 * Allows to trigger some action when saving affiliate's settings.
			 *
			 * @param YITH_WCAF_Affiliate $affiliate Affiliate object.
			 * @param array               $fields    Submitted and sanitized fields.
			 */
			do_action( 'yith_wcaf_save_affiliate_settings', $affiliate, $fields );

			// redirect to settings page and show success message.
			wc_add_notice( _x( 'Settings saved successfully!', '[FRONTEND] Affiliate settings success message', 'yith-woocommerce-affiliates' ) );
			wp_safe_redirect( YITH_WCAF_Dashboard()->get_dashboard_url( 'settings' ) );
			die;
		}
	}
}
