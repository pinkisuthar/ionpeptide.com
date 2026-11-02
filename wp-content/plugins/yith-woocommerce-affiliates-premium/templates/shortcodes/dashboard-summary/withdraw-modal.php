<?php
/**
 * Affiliate Dashboard Payments - Withdraw Modal
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 2.0.0
 */

/**
 * Template variables:
 *
 * @var $affiliate                 YITH_WCAF_Affiliate
 * @var $formatted_profile         string
 * @var $profile                   string
 * @var $min_withdraw              float
 * @var $max_withdraw              float
 * @var $modal_notes               string
 * @var $require_invoice           bool
 * @var $invoice_mode              string
 * @var $invoice_company           string
 * @var $invoice_example           string
 * @var $invoice_terms_show        bool
 * @var $invoice_terms_label       string
 * @var $invoice_terms_anchor_url  string
 * @var $invoice_terms_anchor_text string
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<div id="withdraw_modal">
	<form method="post" enctype="multipart/form-data" data-action="request_withdraw" data-security="<?php echo esc_attr( wp_create_nonce( 'request_withdraw' ) ); ?>">
		<div class="balance-recap">
			<?php
			/**
			 * DO_ACTION: yith_wcaf_before_withdraw_modal_balance
			 *
			 * Allows to render some content before the balance in the withdraw modal.
			 */
			do_action( 'yith_wcaf_before_withdraw_modal_balance' );
			?>

			<h4>
				<?php echo esc_html_x( 'Available balance', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ); ?>
			</h4>
			<span class="affiliate-balance">
				<?php echo wp_kses_post( $affiliate->get_formatted_balance() ); ?>
			</span>

			<?php
			/**
			 * DO_ACTION: yith_wcaf_after_withdraw_modal_balance
			 *
			 * Allows to render some content after the balance in the withdraw modal.
			 */
			do_action( 'yith_wcaf_after_withdraw_modal_balance' );
			?>
		</div>

		<div class="withdraw-amount">
			<p class="form-row validate-required">
				<span class="woocommerce-Price-currencySymbol">
					<?php
					/**
					 * APPLY_FILTERS: yith_wcaf_withdraw_amount_currency_symbol
					 *
					 * Filters the currency symbol for the amount to be withdrawn.
					 *
					 * @param string $currency_symbol Currency symbol.
					 */
					echo esc_html( apply_filters( 'yith_wcaf_withdraw_amount_currency_symbol', YITH_WCAF_Withdraws()->get_currency_symbol() ) );
					?>
				</span>
				<?php
				/**
				 * APPLY_FILTERS: yith_wcaf_withdraw_amount_step
				 *
				 * Filters the step in the input to choose the amount to withdraw.
				 *
				 * @param double $step Step.
				 */
				?>
				<input
					type="number"
					class="amount"
					id="withdraw_amount"
					name="withdraw_amount"
					required="required"
					placeholder="<?php echo esc_attr_x( 'Enter amount', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ); ?>"
					step="<?php echo esc_attr( apply_filters( 'yith_wcaf_withdraw_amount_step', pow( 10, - 1 * wc_get_price_decimals() ) ) ); ?>"
					min="<?php echo esc_attr( $min_withdraw ); ?>"
					max="<?php echo esc_attr( $max_withdraw ); ?>"
					value="<?php echo esc_attr( $max_withdraw ); ?>"
				/>
			</p>
		</div>

		<?php if ( $modal_notes ) : ?>
			<small class="withdraw-notes">
				<?php
				/**
				 * DO_ACTION: yith_wcaf_before_withdraw_modal_notes
				 *
				 * Allows to render some content before the notes in the withdraw modal.
				 */
				do_action( 'yith_wcaf_before_withdraw_modal_notes' );
				?>

				<?php if ( $modal_notes ) : ?>
					<?php echo wp_kses_post( $modal_notes ); ?>
				<?php endif; ?>

				<?php
				/**
				 * DO_ACTION: yith_wcaf_after_withdraw_modal_notes
				 *
				 * Allows to render some content after the notes in the withdraw modal.
				 */
				do_action( 'yith_wcaf_after_withdraw_modal_notes' );
				?>
			</small>
		<?php endif; ?>

		<?php if ( $require_invoice && in_array( $invoice_mode, array( 'both', 'generate' ), true ) ) : ?>
			<div class="billing-info">
				<h4>
					<?php echo esc_html_x( 'Billing info', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ); ?>
				</h4>

				<p class="form-row validate-required">
					<?php if ( $formatted_profile ) : ?>
						<a
							tabindex="-1"
							target="_blank"
							class="formatted-address"
							href="<?php echo esc_url( YITH_WCAF_Dashboard()->get_dashboard_url( 'settings' ) ); ?>"
							title="<?php echo esc_attr_x( 'Edit billing info', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ); ?>"
						>
							<?php echo wp_kses_post( $formatted_profile ); ?>
						</a>
					<?php else : ?>
						<span class="missing-address">
							<?php
							// translators: 1. Url to affiliate dashboard.
							echo wp_kses_post( sprintf( _x( 'Billing info not found. Please, fill in the fields in the <a target="_blank" href="%s">Settings tab &gt;</a>', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ), YITH_WCAF_Dashboard()->get_dashboard_url( 'settings' ) ) );
							?>
						</span>
					<?php endif; ?>
					<input type="hidden" name="billing_profile" value="<?php echo $profile ? esc_attr( wp_json_encode( $profile ) ) : ''; ?>" data-error="<?php esc_attr_e( 'Please, complete your billing profile first!', 'yith-woocommerce-affiliates' ); ?>" />
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $require_invoice ) : ?>
			<div class="invoice-info">
				<h4>
					<?php echo esc_html( _x( 'Invoice', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ) ); ?>
				</h4>

				<p>
					<?php
					echo esc_html( _x( 'To process the payment we need you to provide us with an invoice.', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ) );

					echo '&nbsp;';

					if ( 'both' === $invoice_mode ) :
						echo wp_kses_post( _x( 'You can create your own invoice and upload it in PDF format or we can generate an invoice for you, you will only need to enter a number to identify it.<br>For example, if you already created 5 invoices this year, you can enter "6".', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ) );
					elseif ( 'upload' === $invoice_mode ) :
						echo wp_kses_post( _x( 'Create it using your own invoicing software and upload it in PDF format, using the following form.', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ) );
					elseif ( 'generate' === $invoice_mode ) :
						echo wp_kses_post( _x( 'We\'ll automatically generate one for you, you will only need to enter a number to identify it.<br>For example, if you already created 5 invoices this year, you can enter "6". ', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ) );
					endif;
					?>
				</p>

				<ul class="invoice-modes yith-wcaf-accordion <?php echo esc_attr( $invoice_mode ); ?>">
					<?php if ( in_array( $invoice_mode, array( 'both', 'upload' ), true ) ) : ?>
						<li class="invoice-mode accordion-option">
							<label for="invoice_mode_upload">
								<input type="radio" name="invoice_mode" id="invoice_mode_upload" class="invoice-mode-radio accordion-radio" value="upload" <?php checked( in_array( YITH_WCAF_Form_Handler::get_posted_data( 'invoice_mode', $invoice_mode ), array( 'both', 'upload' ), true ) ); ?> >
								<span class="invoice-mode-label">
									<?php echo esc_html_x( 'Attach a PDF invoice', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ); ?>
								</span>
							</label>

							<div class="invoice-mode-content accordion-content">
								<?php if ( $invoice_company ) : ?>
									<small>
										<?php echo esc_html_x( 'Please, use this info in your invoice:', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ); ?>
									</small>
									<span class="formatted-address">
										<?php echo wp_kses_post( nl2br( $invoice_company ) ); ?>
									</span>
								<?php endif; ?>

								<?php if ( $invoice_example ) : ?>
									<small>
										<?php
										/**
										 * APPLY_FILTERS: yith_wcaf_example_invoice_text
										 *
										 * Filters the text used to add the invoice example in the withdraw modal.
										 *
										 * @param string $text            Text.
										 * @param string $invoice_example URL for the invoice example.
										 */
										// translators: 1. Url to example invoice.
										echo wp_kses_post( apply_filters( 'yith_wcaf_example_invoice_text', sprintf( _x( 'Please, refer to the following <a href="%s" target="_blank">example</a> for invoice creation', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ), $invoice_example ), $invoice_example ) );
										?>
									</small>
								<?php endif; ?>

								<p class="form-row validate-required">
									<?php
									/**
									 * APPLY_FILTERS: yith_wcaf_max_invoice_size
									 *
									 * Filters the maximum size for the invoice.
									 *
									 * @param int $max_size Maximum size for the invoice.
									 */
									?>
									<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( 1048576 * YITH_WCAF_Invoices::get_invoice_max_size() ); ?>" />
									<?php
									/**
									 * APPLY_FILTERS: yith_wcaf_invoice_upload_mime
									 *
									 * Filters the file type for the invoice to be uploaded.
									 *
									 * @param string $file_type_mime File type (mime) Defaul: application/pdf.
									 */
									?>
									<input type="file" id="invoice_file" name="invoice_file" accept="<?php echo esc_attr( apply_filters( 'yith_wcaf_invoice_upload_mime', 'application/pdf' ) ); ?>" data-error="<?php esc_attr_e( 'Please, upload a valid invoice', 'yith-woocommerce-affiliates' ); ?>"/>
									<a href="#" role="button" class="yith-wcaf-attach-file" title="<?php echo esc_attr_x( 'Upload your invoice', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ); ?>">
										<?php echo esc_html_x( 'Click to attach your invoice', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ); ?>
									</a>
								</p>
							</div>
						</li>
					<?php endif; ?>
					<?php if ( in_array( $invoice_mode, array( 'both', 'generate' ), true ) ) : ?>
						<li class="invoice-mode accordion-option">
							<label for="invoice_mode_generate">
								<input type="radio" name="invoice_mode" id="invoice_mode_generate" class="invoice-mode-radio accordion-radio" value="generate" <?php checked( YITH_WCAF_Form_Handler::get_posted_data( 'invoice_mode', $invoice_mode ), 'generate' ); ?> >
								<span class="invoice-mode-label">
									<?php echo esc_html_x( 'Generate an automatic invoice', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ); ?>
								</span>
							</label>

							<div class="invoice-mode-content accordion-content settings-box">
								<?php
								/**
								 * DO_ACTION: yith_wcaf_withdraw_modal_billing_fields
								 *
								 * Allows to render some content in the withdraw_modal.
								 */
								do_action( 'yith_wcaf_withdraw_modal_billing_fields' );
								?>
							</div>
						</li>
					<?php endif; ?>
				</ul>

				<?php
				if ( $invoice_terms_show ) :

					$terms_link = sprintf( '<a target="_blank" href="%s">%s</a>', $invoice_terms_anchor_url, $invoice_terms_anchor_text );

					/**
					 * APPLY_FILTERS: yith_wcaf_terms_label
					 *
					 * Filters the 'Terms and Conditions' label in the withdraw modal.
					 *
					 * @param string $label Label.
					 */
					$terms_label = apply_filters( 'yith_wcaf_terms_label', str_replace( '%TERMS%', $terms_link, $invoice_terms_label ) );

					/**
					 * APPLY_FILTERS: yith_wcaf_terms_required
					 *
					 * Filters whether is required to accept the terms and conditions when requesting a withdraw.
					 *
					 * @param bool $is_terms_required Whether is required to accept terms or not.
					 */
					$terms_required = apply_filters( 'yith_wcaf_terms_required', true );

					?>
					<p class="form-row form-row-wide validate-required">
						<label for="terms" class="terms-label">
							<input type="checkbox" name="terms" id="terms" value="yes" <?php checked( YITH_WCAF_Form_Handler::get_posted_data( 'terms' ) ); ?> />
							<?php echo wp_kses_post( $terms_label ); ?> <?php echo $terms_required ? '<span class="required">*</span>' : ''; ?>
						</label>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php wp_nonce_field( 'yith-wcaf-request-withdraw', 'request_withdraw' ); ?>

		<?php
		/**
		 * APPLY_FILTERS: yith_wcaf_withdraw_submit_button
		 *
		 * Filters the text of the button to request a withdraw.
		 *
		 * @param string $button_text Button text.
		 */
		?>
		<input class="button submit" type="submit" value="<?php echo esc_attr( apply_filters( 'yith_wcaf_withdraw_submit_button', _x( 'Request withdrawal', '[FRONTEND] Withdraw modal', 'yith-woocommerce-affiliates' ) ) ); ?>" />
	</form>
</div>
