<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial: variables come from the including class scope (Warranty_Center_Form), not from a global declaration.
/**
 * Guest warranty center form ([warranty-center] shortcode result view).
 *
 * Vars in scope (set in Warranty_Center_Form::fetch_warranty_request_data_callback):
 *   $order           WC_Order
 *   $warranty        Return_Warranty
 *   $eligible        array of eligible items (item_id, product_id, name, days_left, warranty_days)
 *   $existing_claims array of existing claim rows for this order
 *   $reasons         string[] warranty reason options
 *   $guest_email     string  billing email entered by the guest ('' when logged in)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="multi-guest-container warranty-guest-container">

	<div class="zorem-warranty-section">
		<h2>
			<?php
			printf(
				/* translators: %s: order number */
				esc_html__( 'Warranty — Order #%s', 'zorem-returns' ),
				esc_html( $order->get_order_number() )
			);
			?>
		</h2>

		<?php if ( ! empty( $existing_claims ) ) { ?>
			<div class="zorem-warranty-claims-list">
				<?php foreach ( $existing_claims as $claim ) { ?>
					<div class="zorem-warranty-claim-row status-<?php echo esc_attr( $claim->claim_status ); ?>">
						<div class="zorem-warranty-claim-meta">
							<strong>#<?php echo esc_html( $claim->id ); ?></strong> &middot;
							<span class="zorem-warranty-status-pill status-<?php echo esc_attr( $claim->claim_status ); ?>"><?php echo esc_html( $warranty->get_status_label( $claim->claim_status ) ); ?></span>
							<span class="zorem-warranty-claim-date"><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $claim->created_at . ' UTC' ) ) ); ?></span>
						</div>
						<?php if ( ! empty( $claim->claim_reason ) ) { ?>
							<div class="zorem-warranty-claim-reason"><b><?php esc_html_e( 'Reason', 'zorem-returns' ); ?>:</b> <?php echo esc_html( $claim->claim_reason ); ?></div>
						<?php } ?>
						<?php
						if ( ! empty( $claim->resolution_type ) ) {
							$resolution_types = $warranty->get_resolution_types();
							$resolution_label = isset( $resolution_types[ $claim->resolution_type ] ) ? $resolution_types[ $claim->resolution_type ] : $claim->resolution_type;
							?>
							<div class="zorem-warranty-claim-resolution"><b><?php esc_html_e( 'Resolution', 'zorem-returns' ); ?>:</b> <?php echo esc_html( $resolution_label ); ?><?php echo ! empty( $claim->resolution_note ) ? ' &mdash; ' . esc_html( $claim->resolution_note ) : ''; ?></div>
						<?php } ?>
					</div>
				<?php } ?>
			</div>
		<?php } ?>

		<?php if ( ! empty( $eligible ) ) { ?>
			<?php include __DIR__ . '/warranty-claim-form.php'; ?>
		<?php } elseif ( ! empty( $existing_claims ) ) { ?>
			<p class="zorem-warranty-all-claimed" style="margin-top:8px;color:#646970;font-style:italic;"><?php esc_html_e( 'All eligible items for this order have an active warranty claim. Contact support if you need to file again.', 'zorem-returns' ); ?></p>
		<?php } ?>

		<div class="zorem-warranty-back">
			<button type="button" class="button warranty-back-btn"><?php esc_html_e( 'Look up another order', 'zorem-returns' ); ?></button>
		</div>
	</div>
</div>
