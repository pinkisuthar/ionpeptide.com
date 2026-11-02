<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial: variables come from the including class scope.
/**
 * Returns & Exchanges Fulfillment Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$return_order_status = array(
	'return-requested'   => esc_html__( 'Return Requested', 'zorem-returns' ),
	'return-approved'    => esc_html__( 'Return Approved', 'zorem-returns' ),
	'return-completed'   => esc_html__( 'Return Completed', 'zorem-returns' ),
	'return-rejected'    => esc_html__( 'Return Rejected', 'zorem-returns' ),
	'return-cancelled'   => esc_html__( 'Return Cancelled', 'zorem-returns' ),
);

$exchange_order_status = array(
	'exchange-requested' => esc_html__( 'Exchange Requested', 'zorem-returns' ),
	'exchange-approved'  => esc_html__( 'Exchange Approved', 'zorem-returns' ),
	'exchange-rejected'  => esc_html__( 'Exchange Rejected', 'zorem-returns' ),
	'exchange-cancelled' => esc_html__( 'Exchange Cancelled', 'zorem-returns' ),
);
?>

<!-- ===== RETURNS TABLE ===================================================== -->
<section id="return_orders_content" class="tab_section">
	<div class="accordion_container fulfillment_table_container">
		<input type="hidden" id="nonce_fullfillment_dashbaord"   value="<?php echo esc_attr( wp_create_nonce( 'nonce_fullfillment_dashbaord' ) ); ?>">
		<input type="hidden" id="nonce_exfullfillment_dashbaord" value="<?php echo esc_attr( wp_create_nonce( 'nonce_exfullfillment_dashbaord' ) ); ?>">

		<p class="fulfillment_filter_box">
			<select class="fulfillment_filter_select" id="rt4wc_fulfillment_filter">
				<option value=""><?php esc_html_e( 'All Orders', 'zorem-returns' ); ?></option>
				<?php foreach ( $return_order_status as $slug => $label ) { ?>
					<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php } ?>
			</select>
			<input type="search" id="rt4wc_fulfillment_search_input" class="fulfillment_search_input"
				name="fulfillment_search_input" value=""
				placeholder="<?php esc_attr_e( 'Search by Order ID', 'zorem-returns' ); ?>">
			<button type="button" id="rt4wc_fulfillment_search_submit" class="button fulfillment_search_submit">
				<span class="dashicons dashicons-search"></span>
			</button>
			<span class="export_orders">
				<span class="dashicons dashicons-download" title="CSV download"></span> Export CSV
			</span>
		</p>

		<input type="hidden" name="unfulfilled_order_status" id="unfulfilled_order_status" value="all">

		<table class="widefat fixed fullfilments_table hover" cellspacing="0"
			id="rt4wc_fullfilments_table" style="width:100%;">
			<thead>
				<tr>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Request ID', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Order Date', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Order', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Status', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Type', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Customer', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Items', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Attachments', 'zorem-returns' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Actions', 'zorem-returns' ); ?></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>
</section>

