<?php
/**
 * Omnisend Sync View
 *
 * @package OmnisendPlugin
 */

defined( 'ABSPATH' ) || exit;

function omnisend_show_sync() {
	?>

	<div class="settings-page">
		<?php
		omnisend_display_omnisend_connected();
		omnisend_display_tabs( 'Sync' );
		?>
		<div class="settings-main-wrapper single-column sync-page">
			<div class="settings-main-content">
				<div class="omnisend-settings-card">
				<h3>Sync Status</h3>
				<div class="omnisend-content-body" style="margin-top: 20px; padding: 15px; background-color: #e7f3ff; border-left: 4px solid #2271b1;">
					<p style="margin: 0;">
						<strong>ℹ️ Note:</strong> Your store data - contacts, orders, products and categories - is now synced with Omnisend automatically through the WooCommerce API on our backend, so it is no longer sent from the plugin and sync status is not shown here. Syncing continues automatically. Learn more in <a href="https://support.omnisend.com/en/articles/1636174-connect-your-woocommerce-wordpress-store-to-omnisend#h_95b645acde" target="_blank" rel="noopener noreferrer">our help article</a>. If you notice any issues, please contact our support team.
					</p>
				</div>
			</div>
			</div>
		</div>
	</div>
	<?php
}
