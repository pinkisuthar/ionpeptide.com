<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial: variables come from the including class scope.
/**
 * Admin Warranty Claims Dashboard.
 *
 * Rendered alongside the Returns Dashboard inside the Returns Center page so the
 * CSS-radio tab system can switch between them without a page reload.
 *
 * Visibility is gated by `#warranty_orders:checked ~ #warranty_orders_content`
 * (rule lives in warranty.css).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$warranty       = zorem_returns_exchanges()->warranty;
$status_filter  = isset( $_GET['warranty_status'] ) ? sanitize_text_field( wp_unslash( $_GET['warranty_status'] ) ) : '';
$claims         = $warranty->list_claims( array( 'status' => $status_filter, 'limit' => 500 ) );
$statuses       = $warranty->get_statuses();
$resolutions    = $warranty->get_resolution_types();
$admin_nonce    = wp_create_nonce( 'zorem_warranty_admin_nonce' );
?>

<!-- ===== WARRANTY CLAIMS TABLE ===================================================== -->
<section id="warranty_orders_content" class="tab_section">
	<div class="accordion_container fulfillment_table_container">
		<input type="hidden" id="zorem_warranty_admin_nonce" value="<?php echo esc_attr( $admin_nonce ); ?>" />

		<p class="fulfillment_filter_box">
			<select class="fulfillment_filter_select" id="zorem_warranty_status_filter">
				<option value=""><?php esc_html_e( 'All Claims', 'zorem-returns' ); ?></option>
				<?php foreach ( $statuses as $key => $label ) { ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status_filter, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php } ?>
			</select>
			<input type="search" id="zorem_warranty_search_input" class="fulfillment_search_input"
				name="zorem_warranty_search_input" value=""
				placeholder="<?php esc_attr_e( 'Search by Order ID', 'zorem-returns' ); ?>">
			<button type="button" id="zorem_warranty_search_submit" class="button fulfillment_search_submit">
				<span class="dashicons dashicons-search"></span>
			</button>
		</p>

		<div class="dataTables_wrapper">
		<table class="widefat fixed fullfilments_table hover dataTable no-footer" cellspacing="0" id="zorem_warranty_claims_table" style="width:100%;">
			<thead>
				<tr>
					<th class="manage-column" scope="col"><?php esc_html_e( 'ID', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Submitted', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Order', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Status', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Customer', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Product', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Reason', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Actions', 'zorem-returns' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr class="zorem-warranty-empty-row" <?php echo empty( $claims ) ? '' : 'style="display:none;"'; ?>>
					<td colspan="8" style="text-align:center;padding:36px;color:#6b7280;font-style:italic;">
						<?php esc_html_e( 'No warranty claims to show.', 'zorem-returns' ); ?>
					</td>
				</tr>
				<?php 
				foreach ( $claims as $claim ) {
					$claim_order        = wc_get_order( $claim->order_id );
					$customer     = $claim_order ? $claim_order->get_billing_email() : '';
					$customer_nm  = $claim_order ? trim( $claim_order->get_billing_first_name() . ' ' . $claim_order->get_billing_last_name() ) : '';
					$product      = $claim->product_id ? wc_get_product( $claim->product_id ) : null;
					$product_name = $product ? $product->get_name() : '—';
					$status_label = $warranty->get_status_label( $claim->claim_status );
					?>
					<tr class="zorem-warranty-row" data-claim-id="<?php echo esc_attr( $claim->id ); ?>" data-status="<?php echo esc_attr( $claim->claim_status ); ?>" data-order-id="<?php echo esc_attr( $claim->order_id ); ?>">
						<td>#<?php echo esc_html( $claim->id ); ?></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $claim->created_at . ' UTC' ) ) ); ?></td>
						<td>
							<?php if ( $claim_order ) { ?>
								<a href="<?php echo esc_url( $claim_order->get_edit_order_url() ); ?>">#<?php echo esc_html( $claim->order_id ); ?></a>
							<?php } else { ?>
								<?php echo esc_html( '#' . $claim->order_id ); ?>
							<?php } ?>
						</td>
						<td>
							<span class="zorem-warranty-status-pill status-<?php echo esc_attr( $claim->claim_status ); ?>"><?php echo esc_html( $status_label ); ?></span>
						</td>
						<td>
							<?php echo esc_html( $customer_nm ); ?>
							<?php if ( $customer ) { ?>
								<br/>
								<small><?php echo esc_html( $customer ); ?></small>
							<?php } ?>
						</td>
						<td><?php echo esc_html( $product_name ); ?></td>
						<td><?php echo esc_html( $claim->claim_reason ); ?></td>
						<td>
							<button type="button" class="button zorem-warranty-view-btn"><?php esc_html_e( 'View / Update', 'zorem-returns' ); ?></button>
						</td>
					</tr>
					<tr class="zorem-warranty-detail-row" data-claim-id="<?php echo esc_attr( $claim->id ); ?>" style="display:none;">
						<td colspan="8">
							<div class="zorem-warranty-detail">
								<div class="zorem-warranty-detail-cols">
									<div class="zorem-warranty-detail-col">
										<h4><?php esc_html_e( 'Customer description', 'zorem-returns' ); ?></h4>
										<p><?php echo esc_html( $claim->claim_description ); ?></p>

										<?php
										$attachments = ! empty( $claim->claim_attachments ) ? json_decode( $claim->claim_attachments, true ) : array();
										if ( ! empty( $attachments ) ) {
											echo '<h4>' . esc_html__( 'Attachments', 'zorem-returns' ) . '</h4>';
											echo '<div class="zorem-warranty-attachments">';
											foreach ( $attachments as $url ) {
												echo '<a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( $url ) . '" alt="" /></a>';
											}
											echo '</div>';
										}
										?>
									</div>

									<div class="zorem-warranty-detail-col">
										<h4><?php esc_html_e( 'Update Claim', 'zorem-returns' ); ?></h4>

										<label><?php esc_html_e( 'New Status', 'zorem-returns' ); ?></label>
										<select class="zorem-warranty-new-status">
											<?php foreach ( $statuses as $key => $label ) { ?>
												<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $claim->claim_status, $key ); ?>><?php echo esc_html( $label ); ?></option>
											<?php } ?>
										</select>

										<label style="margin-top:8px;"><?php esc_html_e( 'Resolution (required when resolving)', 'zorem-returns' ); ?></label>
										<select class="zorem-warranty-resolution">
											<option value=""><?php esc_html_e( '— Select —', 'zorem-returns' ); ?></option>
											<?php foreach ( $resolutions as $key => $label ) { ?>
												<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $claim->resolution_type, $key ); ?>><?php echo esc_html( $label ); ?></option>
											<?php } ?>
										</select>

										<label style="margin-top:8px;"><?php esc_html_e( 'Resolution note', 'zorem-returns' ); ?></label>
										<textarea class="zorem-warranty-resolution-note" rows="3" placeholder="<?php esc_attr_e( 'Optional — sent to the customer in the notification email.', 'zorem-returns' ); ?>"><?php echo esc_textarea( $claim->resolution_note ); ?></textarea>

										<div style="margin-top:10px;">
											<button type="button" class="button button-primary zorem-warranty-save-btn"><?php esc_html_e( 'Save Changes', 'zorem-returns' ); ?></button>
											<span class="zorem-warranty-save-status"></span>
										</div>
									</div>
								</div>
							</div>
						</td>
					</tr>
				<?php } ?>
			</tbody>
		</table>
		</div>
	</div>
</section>
