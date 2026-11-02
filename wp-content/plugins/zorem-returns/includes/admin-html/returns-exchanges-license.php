<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial: variables come from the including class scope.
/**
 * License tab — ZUI redesign modeled after ALP Pro's wclp_addon_tab.php.
 *
 * Renders:
 *   - Store Ecosystem Management heading + Secure Connection pill
 *   - 2-column grid: [SSO Gateway Verification card + Telemetry preferences] | [Docs & Help]
 *   - Fulfillment Workflow Platform Ecosystem plugin grid (with filter + search)
 */
defined( 'ABSPATH' ) || exit;

// License wiring — free plugins may not have the license class.
$rt_license        = isset( zorem_returns_exchanges()->license ) ? zorem_returns_exchanges()->license : null;
$rt_has_license    = ( $rt_license && method_exists( $rt_license, 'get_license_status' ) );
$lic_active        = $rt_has_license ? (bool) $rt_license->get_license_status() : false;
$lic_key           = ( $rt_license && method_exists( $rt_license, 'get_license_key' ) ) ? $rt_license->get_license_key() : '';
$lic_item_code     = ( $rt_license && method_exists( $rt_license, 'get_item_code' ) ) ? $rt_license->get_item_code() : 'zorem_returns_pro';
$lic_product_id    = ( $rt_license && method_exists( $rt_license, 'get_product_id' ) ) ? $rt_license->get_product_id() : 0;
$lic_email         = get_option( 'zorem_license_email', '' );
$lic_connect       = get_option( 'zorem_license_connected', 0 );
$lic_domain        = wp_parse_url( home_url(), PHP_URL_HOST );
$lic_current_url   = admin_url( 'admin.php?page=zorem-returns-exchanges&tab=license' );
$lic_activate_url  = 'https://www.zorem.com/my-account/license-activation/?product_id=' . rawurlencode( (string) $lic_product_id ) . '&redirect_url=' . rawurlencode( $lic_current_url );
$lic_deactivate_a  = $lic_item_code . '_license_deactivate';

// Ecosystem plugins.
$rt_addons = array(
	array(
		'name'   => __( 'Advanced Shipment Tracking PRO', 'zorem-returns' ),
		'slug'   => 'ast-pro/ast-pro.php',
		'icon'   => 'package',
		'tint'   => '#DBEAFE',
		'accent' => '#2563EB',
		'desc'   => __( 'AST PRO provides powerful features to easily add tracking info to WooCommerce orders, automate fulfillment workflows and keep your customers happy and informed.', 'zorem-returns' ),
		'url'    => 'https://www.zorem.com/product/woocommerce-advanced-shipment-tracking/?utm_source=wp-admin&utm_medium=RT4WC&utm_campaign=add-ons',
		'badge'  => __( 'Recommended', 'zorem-returns' ),
		'stat'   => __( '15k+ active stores', 'zorem-returns' ),
	),
	array(
		'name'   => __( 'TrackShip for WooCommerce', 'zorem-returns' ),
		'slug'   => 'trackship-for-woocommerce/trackship-for-woocommerce.php',
		'icon'   => 'truck',
		'tint'   => '#CCFBF1',
		'accent' => '#0D9488',
		'desc'   => __( 'Take control of post-shipping workflows, reduce time spent on customer service and provide a superior post-purchase experience to your customers.', 'zorem-returns' ),
		'url'    => 'https://wordpress.org/plugins/trackship-for-woocommerce/?utm_source=wp-admin&utm_medium=RT4WC&utm_campaign=add-ons',
		'badge'  => '',
		'stat'   => __( '8k+ active stores', 'zorem-returns' ),
	),
	array(
		'name'   => __( 'SMS for WooCommerce', 'zorem-returns' ),
		'slug'   => 'sms-for-woocommerce/sms-for-woocommerce.php',
		'icon'   => 'phone',
		'tint'   => '#DBEAFE',
		'accent' => '#2563EB',
		'desc'   => __( 'Keep your customers informed by sending them automated SMS text messages with order & delivery updates.', 'zorem-returns' ),
		'url'    => 'https://www.zorem.com/product/sms-for-woocommerce/?utm_source=wp-admin&utm_medium=RT4WC&utm_campaign=add-ons',
		'badge'  => '',
		'stat'   => __( '5k+ installs', 'zorem-returns' ),
	),
	array(
		'name'   => __( 'Sales Report Email Pro', 'zorem-returns' ),
		'slug'   => 'sales-report-email-pro/sales-report-email-pro.php',
		'icon'   => 'mail',
		'tint'   => '#F3E8FF',
		'accent' => '#9333EA',
		'desc'   => __( 'Know how well your store is performing by sending daily, weekly, or monthly sales reports by email.', 'zorem-returns' ),
		'url'    => 'https://www.zorem.com/product/sales-report-email-pro/?utm_source=wp-admin&utm_medium=RT4WC&utm_campaign=add-ons',
		'badge'  => '',
		'stat'   => __( '3k+ active', 'zorem-returns' ),
	),
	array(
		'name'   => __( 'Country Based Restrictions Pro', 'zorem-returns' ),
		'slug'   => 'country-base-restrictions-pro-addon/country-base-restrictions-pro-addon.php',
		'icon'   => 'globe',
		'tint'   => '#FFEDD5',
		'accent' => '#EA580C',
		'desc'   => __( 'Restrict products on your store by visitor country using WooCommerce Geolocation or the shipping country added by the customer.', 'zorem-returns' ),
		'url'    => 'https://www.zorem.com/product/country-based-restriction-pro/?utm_source=wp-admin&utm_medium=RT4WC&utm_campaign=add-ons',
		'badge'  => '',
		'stat'   => __( '2k+ active', 'zorem-returns' ),
	),
	array(
		'name'   => __( 'Zorem Local Pickup Pro', 'zorem-returns' ),
		'slug'   => 'advanced-local-pickup-pro/advanced-local-pickup-pro.php',
		'icon'   => 'store',
		'tint'   => '#DCFCE7',
		'accent' => '#16A34A',
		'desc'   => __( 'Advanced local pickup management with multiple locations, business hours and appointment slots for WooCommerce.', 'zorem-returns' ),
		'url'    => 'https://www.zorem.com/product/zorem-local-pickup-pro/?utm_source=wp-admin&utm_medium=RT4WC&utm_campaign=add-ons',
		'badge'  => '',
		'stat'   => __( '4k+ installs', 'zorem-returns' ),
	),
);

// Load icon registry.
$rt_zui_dir = zorem_returns_exchanges()->get_plugin_path() . '/assets/zui';
if ( ! function_exists( 'zorem_ui_get_icon' ) && file_exists( $rt_zui_dir . '/icons.php' ) ) {
	require_once $rt_zui_dir . '/icons.php';
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
?>
<div class="zui-layout zui-layout--full">
<main class="zui-content zui-content--full">
<section id="rt4wc-section-license" class="zui-section is-active" data-section="license">

	<div class="zui-lic" id="rt4wc-zui-lic">

		<!-- Page header -->
		<div class="zui-lic-head">
			<div>
				<span class="zui-lic-eyebrow">
					<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline></svg>
					<span><?php esc_html_e( 'Store Ecosystem Management', 'zorem-returns' ); ?></span>
				</span>
				<h1 class="zui-lic-title"><?php esc_html_e( 'License & Extensions', 'zorem-returns' ); ?></h1>
				<p class="zui-lic-sub"><?php esc_html_e( 'Manage your secure zorem.com connection.', 'zorem-returns' ); ?></p>
			</div>
			<div class="zui-lic-telemetry <?php echo $lic_active ? 'is-on' : 'is-off'; ?>">
				<span class="zui-lic-telemetry__dot">
					<span></span>
					<span></span>
				</span>
				<div>
					<div class="zui-lic-telemetry__label"><?php esc_html_e( 'Secure Connection', 'zorem-returns' ); ?></div>
					<div class="zui-lic-telemetry__val">
						<span><?php echo $lic_active ? esc_html__( 'Synchronized', 'zorem-returns' ) : esc_html__( 'Unlinked', 'zorem-returns' ); ?></span>
					</div>
				</div>
			</div>
		</div>

		<!-- 2-column grid -->
		<div class="zui-lic-grid">

			<div class="zui-lic-col-main">

				<!-- SSO Gateway Card -->
				<div class="zui-card zui-lic-card">
					<div class="zui-lic-card__head">
						<span class="zui-lic-card__bar"></span>
						<span class="zui-lic-card__title"><?php esc_html_e( 'Secure SSO Gateway Verification', 'zorem-returns' ); ?></span>
					</div>
					<div class="zui-lic-card__body">
						<?php do_action( 'return_license_form' ); ?>
						<?php if ( $lic_active ) : ?>
							<div class="zui-lic-active">
								<div class="zui-lic-active__left">
									<span class="zui-lic-active__icon">
										<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
									</span>
									<div>
										<span class="zui-lic-active__eyebrow"><?php esc_html_e( 'Active Premium License', 'zorem-returns' ); ?></span>
										<h4 class="zui-lic-active__name"><?php esc_html_e( 'Zorem Returns Pro License', 'zorem-returns' ); ?></h4>
									</div>
								</div>
								<div class="zui-lic-active__domain">
									<span><?php esc_html_e( 'Licensed Domain:', 'zorem-returns' ); ?></span>
									<span class="zui-lic-active__domain-val"><?php echo esc_html( $lic_domain ); ?></span>
								</div>
							</div>
							<div class="zui-lic-deactivate-row">
								<p><?php esc_html_e( 'Want to deactivate the license for any reason?', 'zorem-returns' ); ?></p>
								<form method="post" id="return-licence-form" class="return_licence_form" action="" enctype="multipart/form-data">
									<input type="hidden" name="license_key" id="license_key" value="<?php echo esc_attr( $lic_key ); ?>">
									<?php wp_nonce_field( 'return_license_tab', 'return_license_tab_nonce' ); ?>
									<input type="hidden" name="action" class="licence_action" value="<?php echo esc_attr( $lic_deactivate_a ); ?>">
									<button name="save" type="submit" value="Deactivate" class="zui-lic-deactivate licence_submit"><?php esc_html_e( 'Deactivate License', 'zorem-returns' ); ?></button>
								</form>
							</div>
							<p class="zui-lic-msg" id="rt4wc_pro_license_message"></p>
						<?php elseif ( $rt_has_license ) :
							$is_connected_inactive = ( 1 == $lic_connect && '' != $lic_email );
							?>
							<div class="zui-lic-inactive">
								<span class="zui-lic-inactive__badge">
									<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
									<span><?php esc_html_e( 'Action Needed', 'zorem-returns' ); ?></span>
								</span>
								<h3 class="zui-lic-inactive__title"><?php esc_html_e( 'Activate your license', 'zorem-returns' ); ?></h3>
								<p class="zui-lic-inactive__text"><?php esc_html_e( 'Securely connect your WooCommerce installation via zorem.com to receive automatic updates and premium support. We will authenticate your unique license and redirect you back instantly.', 'zorem-returns' ); ?></p>
								<div class="zui-lic-inactive__action">
									<a class="zui-btn-primary zui-lic-activate" href="<?php echo esc_url( $lic_activate_url ); ?>">
										<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
										<span><?php echo $is_connected_inactive ? esc_html__( 'Activate License', 'zorem-returns' ) : esc_html__( 'Connect & Activate License', 'zorem-returns' ); ?></span>
									</a>
									<span class="zui-lic-inactive__note"><?php esc_html_e( 'Redirects safely to the zorem.com gateway.', 'zorem-returns' ); ?></span>
								</div>
							</div>
						<?php else : ?>
							<div class="zui-lic-inactive">
								<span class="zui-lic-inactive__badge">
									<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z"></path></svg>
									<span><?php esc_html_e( 'Free version', 'zorem-returns' ); ?></span>
								</span>
								<h3 class="zui-lic-inactive__title"><?php esc_html_e( 'Upgrade to Zorem Returns Pro', 'zorem-returns' ); ?></h3>
								<p class="zui-lic-inactive__text"><?php esc_html_e( 'Unlock the Warranty workflow, Fraud Prevention, extended notifications and premium support with the PRO version.', 'zorem-returns' ); ?></p>
								<div class="zui-lic-inactive__action">
									<a class="zui-btn-primary zui-lic-activate" href="https://www.zorem.com/product/zorem-returns/?utm_source=wp-admin&utm_medium=RT4WC&utm_campaign=license-tab" target="_blank" rel="noreferrer noopener">
										<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
										<span><?php esc_html_e( 'View PRO features', 'zorem-returns' ); ?></span>
									</a>
								</div>
							</div>
						<?php endif; ?>
					</div>
				</div>

			</div>

			<!-- Right column: docs help -->
			<div class="zui-lic-col-side">
				<div class="zui-lic-help">
					<h3 class="zui-lic-help__title"><?php esc_html_e( 'Documentation & Help', 'zorem-returns' ); ?></h3>
					<ul class="zui-lic-help__list">
						<li>
							<a href="https://docs.zorem.com/docs/zorem-returns-exchanges/?utm_source=wp-admin&utm_medium=RT4WC&utm_campaign=Docs" target="_blank" rel="noreferrer noopener">
								<span><?php esc_html_e( 'Browse Zorem Knowledge Base', 'zorem-returns' ); ?></span>
								<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
							</a>
						</li>
						<li>
							<a href="https://www.zorem.com/my-account/contact-support/" target="_blank" rel="noreferrer noopener">
								<span><?php esc_html_e( 'Submit Helpdesk Support Ticket', 'zorem-returns' ); ?></span>
								<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
							</a>
						</li>
						<li>
							<a href="https://www.trackship.com/" target="_blank" rel="noreferrer noopener">
								<span><?php esc_html_e( 'TrackShip Fulfillment Engine Portal', 'zorem-returns' ); ?></span>
								<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
							</a>
						</li>
					</ul>
				</div>
			</div>

		</div>

		<!-- Ecosystem plugin grid -->
		<div class="zui-lic-eco">
			<div class="zui-lic-eco__head">
				<div class="zui-lic-eco__heading">
					<span class="zui-lic-eco__icon">
						<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"></path><path d="M5 3v4"></path><path d="M19 17v4"></path><path d="M3 5h4"></path><path d="M17 19h4"></path></svg>
					</span>
					<div>
						<h3><?php esc_html_e( 'Fulfillment Workflow Platform Ecosystem', 'zorem-returns' ); ?></h3>
						<p><?php esc_html_e( 'Install complimentary extensions developed by zorem.', 'zorem-returns' ); ?></p>
					</div>
				</div>
				<div class="zui-lic-eco__filters">
					<button type="button" class="zui-lic-eco__filter is-active" data-filter="all"><?php esc_html_e( 'All', 'zorem-returns' ); ?></button>
					<button type="button" class="zui-lic-eco__filter" data-filter="active"><?php esc_html_e( 'Active', 'zorem-returns' ); ?></button>
					<button type="button" class="zui-lic-eco__filter" data-filter="addons"><?php esc_html_e( 'Add-ons', 'zorem-returns' ); ?></button>
				</div>
			</div>

			<div class="zui-lic-eco__search">
				<span class="zui-lic-eco__search-icon">
					<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
				</span>
				<input type="text" id="zui-lic-search" class="zui-input" placeholder="<?php esc_attr_e( 'Search companion plugins…', 'zorem-returns' ); ?>" autocomplete="off">
			</div>

			<div class="zui-lic-eco__grid" id="zui-lic-grid">
				<?php foreach ( $rt_addons as $eco_p ) :
					$eco_p_active = is_plugin_active( $eco_p['slug'] );
					?>
					<div class="zui-card zui-lic-plugin" data-name="<?php echo esc_attr( $eco_p['name'] ); ?>" data-active="<?php echo $eco_p_active ? '1' : '0'; ?>">
						<div class="zui-lic-plugin__head">
							<span class="zui-lic-plugin__logo" style="background: <?php echo esc_attr( $eco_p['tint'] ); ?>; color: <?php echo esc_attr( $eco_p['accent'] ); ?>;">
								<?php if ( function_exists( 'zorem_ui_get_icon' ) ) { echo zorem_ui_get_icon( $eco_p['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php } ?>
							</span>
							<div class="zui-lic-plugin__id">
								<h4 class="zui-lic-plugin__name"><?php echo esc_html( $eco_p['name'] ); ?></h4>
								<span class="zui-lic-plugin__type"><?php esc_html_e( 'WOO EXTENSION', 'zorem-returns' ); ?></span>
							</div>
							<?php if ( $eco_p['badge'] && ! $eco_p_active ) : ?>
								<span class="zui-lic-plugin__badge"><?php echo esc_html( $eco_p['badge'] ); ?></span>
							<?php endif; ?>
						</div>
						<div class="zui-lic-plugin__body">
							<p class="zui-lic-plugin__desc"><?php echo esc_html( $eco_p['desc'] ); ?></p>
							<div class="zui-lic-plugin__foot">
								<?php if ( $eco_p_active ) : ?>
									<span class="zui-lic-plugin__active"><span class="zui-lic-plugin__dot"></span><?php esc_html_e( 'Active', 'zorem-returns' ); ?></span>
									<span class="zui-lic-plugin__stat"><?php esc_html_e( 'Active in this store', 'zorem-returns' ); ?></span>
								<?php else : ?>
									<a class="zui-lic-plugin__get" href="<?php echo esc_url( $eco_p['url'] ); ?>" target="_blank" rel="noreferrer noopener">
										<span><?php esc_html_e( 'Get Extension', 'zorem-returns' ); ?></span>
										<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
									</a>
									<span class="zui-lic-plugin__stat"><?php echo esc_html( $eco_p['stat'] ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
				<div class="zui-lic-eco__empty" id="zui-lic-empty" hidden><?php esc_html_e( 'No store plugins matched your search.', 'zorem-returns' ); ?></div>
			</div>
		</div>

	</div>

</section>
</main>
</div>
