<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial: variables come from the including class scope.
/**
 * Warranty claim form (customer-facing).
 *
 * Vars in scope:
 *   $order    WC_Order
 *   $eligible array of eligible items (item_id, product_id, name, days_left, warranty_days)
 *   $reasons  string[] warranty reason options
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="zorem-warranty-form-container">
	<h2><?php esc_html_e( 'Submit Warranty Claim', 'zorem-returns' ); ?></h2>
	<p class="zorem-warranty-form-intro">
		<?php
		printf(
			/* translators: %d: order number */
			esc_html__( 'For order #%d. Please choose the item, tell us what went wrong, and upload any helpful photos. We will review and contact you within a few business days.', 'zorem-returns' ),
			esc_html( $order->get_id() )
		);
		?>
	</p>

	<form id="zorem-warranty-claim-form" enctype="multipart/form-data">
		<input type="hidden" name="action" value="zorem_submit_warranty_claim" />
		<input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>" />
		<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'zorem_warranty_nonce' ) ); ?>" />
		<?php if ( ! empty( $guest_email ) ) { // Guest flow ([warranty-center] shortcode) — billing email re-verified on submit. ?>
		<input type="hidden" name="customer_email" value="<?php echo esc_attr( $guest_email ); ?>" />
		<?php } ?>

		<div class="zorem-warranty-field">
			<label for="warranty_item_id"><strong><?php esc_html_e( 'Item', 'zorem-returns' ); ?></strong></label>
			<select id="warranty_item_id" name="item_id" required>
				<option value=""><?php esc_html_e( 'Select an item…', 'zorem-returns' ); ?></option>
				<?php foreach ( $eligible as $e ) { ?>
					<option value="<?php echo esc_attr( $e['item_id'] ); ?>">
						<?php
						printf(
							/* translators: 1: product name, 2: days remaining */
							esc_html__( '%1$s — %2$d days left on warranty', 'zorem-returns' ),
							esc_html( $e['name'] ),
							(int) $e['days_left']
						);
						?>
					</option>
				<?php } ?>
			</select>
		</div>

		<div class="zorem-warranty-field">
			<label for="warranty_reason"><strong><?php esc_html_e( 'Reason', 'zorem-returns' ); ?></strong></label>
			<select id="warranty_reason" name="reason" required>
				<option value=""><?php esc_html_e( 'Select a reason…', 'zorem-returns' ); ?></option>
				<?php foreach ( $reasons as $r ) { ?>
					<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( $r ); ?></option>
				<?php } ?>
			</select>
		</div>

		<div class="zorem-warranty-field">
			<label for="warranty_description"><strong><?php esc_html_e( 'Describe the problem', 'zorem-returns' ); ?></strong></label>
			<textarea id="warranty_description" name="description" rows="5" required placeholder="<?php esc_attr_e( 'Tell us what happened, when it started, what you have tried, etc.', 'zorem-returns' ); ?>"></textarea>
		</div>

		<div class="zorem-warranty-field">
			<label for="warranty_attachments"><strong><?php esc_html_e( 'Photos (optional)', 'zorem-returns' ); ?></strong></label>
			<input type="file" id="warranty_attachments" name="warranty_attachments[]" multiple accept="image/*" />
			<p class="description"><?php esc_html_e( 'JPG, PNG, GIF or WEBP. Max 5 MB each.', 'zorem-returns' ); ?></p>
		</div>

		<div class="zorem-warranty-actions">
			<button type="submit" class="button zorem-warranty-submit-btn"><?php esc_html_e( 'Submit Claim', 'zorem-returns' ); ?></button>
			<span class="zorem-warranty-spinner" hidden></span>
		</div>

		<div class="zorem-warranty-result" hidden></div>
	</form>
</div>
