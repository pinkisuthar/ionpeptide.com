<?php
/**
 * Omnisend account information view
 *
 * @package OmnisendPlugin
 */

defined( 'ABSPATH' ) || exit;

function omnisend_display_account_information() {
	$brand_info = Omnisend_Manager::get_brand_info();

	?>
	<div class="omnisend-settings-card">
		<h3>Account information</h3>
		<div class="omnisend-account-info">
			<div>
				<span class="omnisend-content-body strong">Brand name:</span>
				<span id="omnisend-account-brand-name-value" class="omnisend-content-body">
					<?php echo esc_attr( $brand_info['name'] ); ?>
				</span>
			</div>
			<div>
				<span class="omnisend-content-body strong">Brand ID:</span>
				<span id="omnisend-account-brand-id-value" class="omnisend-content-body">
					<?php echo esc_attr( get_option( 'omnisend_account_id' ) ); ?>
				</span>
			</div>
		</div>
	</div>
	<?php
}
