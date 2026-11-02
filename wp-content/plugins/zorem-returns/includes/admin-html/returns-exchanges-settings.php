<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial: variables come from the including class scope.
/**
 * Returns settings — ZUI redesign.
 *
 * Sidebar-driven layout:
 *   General Settings | Return Settings | Exchange Settings | Fraud Prevention | Warranty
 * Each section wraps the existing get_html() field renderer inside a .zui-card
 * so the current save handler keeps working unchanged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Active sidebar section. Defaults to General Settings.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$rt_section        = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'general-settings';
$rt_valid_sections = array( 'general-settings', 'return-settings', 'exchange-settings', 'fraud-prevention', 'warranty' );
if ( ! in_array( $rt_section, $rt_valid_sections, true ) ) {
	$rt_section = 'general-settings';
}

$rt_sections = array(
	'general-settings'  => array(
		'title' => esc_html__( 'General Settings', 'zorem-returns' ),
		'sub'   => esc_html__( 'Configure how returns and exchanges are displayed and handled.', 'zorem-returns' ),
		'icon'  => '<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
	),
	'return-settings'   => array(
		'title' => esc_html__( 'Return Settings', 'zorem-returns' ),
		'sub'   => esc_html__( 'Refund methods, restocking fees, guidelines and return reasons.', 'zorem-returns' ),
		'icon'  => '<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
	),
	'exchange-settings' => array(
		'title' => esc_html__( 'Exchange Settings', 'zorem-returns' ),
		'sub'   => esc_html__( 'Exchange rules, cross-product options, guidelines and reasons.', 'zorem-returns' ),
		'icon'  => '<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
	),
	'fraud-prevention'  => array(
		'title' => esc_html__( 'Fraud Prevention', 'zorem-returns' ),
		'sub'   => esc_html__( 'Per-customer return limits, blocklist and abuse controls.', 'zorem-returns' ),
		'icon'  => '<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
	),
	'warranty'          => array(
		'title' => esc_html__( 'Warranty', 'zorem-returns' ),
		'sub'   => esc_html__( 'Enable warranty workflow, default period and reasons.', 'zorem-returns' ),
		'icon'  => '<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>',
	),
);

$docs_url    = 'https://docs.zorem.com/docs/zorem-returns-exchanges/?utm_source=wp-admin&utm_medium=RT4WC&utm_campaign=QuickHelp';
$support_url = 'https://www.zorem.com/my-account/contact-support/';
?>

<div class="zui-layout">

	<div class="zui-sidebar__overlay" data-ast-drawer-close></div>

	<aside class="zui-sidebar" id="rt4wc-set-sidebar">
		<div class="zui-sidebar__mobile-head">
			<span class="zui-sidebar__mobile-title"><?php esc_html_e( 'Returns Settings', 'zorem-returns' ); ?></span>
			<button type="button" class="zui-sidebar__close" data-ast-drawer-close aria-label="<?php esc_attr_e( 'Close menu', 'zorem-returns' ); ?>">
				<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
			</button>
		</div>

		<nav class="zui-sidebar__nav" aria-label="<?php esc_attr_e( 'Settings sections', 'zorem-returns' ); ?>">
			<?php foreach ( $rt_sections as $slug => $meta ) : ?>
				<button type="button" class="zui-sidebar__item<?php echo ( $slug === $rt_section ) ? ' is-active' : ''; ?>" data-section="<?php echo esc_attr( $slug ); ?>" aria-controls="rt4wc-section-<?php echo esc_attr( $slug ); ?>"<?php echo ( $slug === $rt_section ) ? ' aria-current="true"' : ''; ?>>
					<span class="zui-sidebar__icon"><?php echo $meta['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — static SVG ?></span>
					<span class="zui-sidebar__label"><?php echo esc_html( $meta['title'] ); ?></span>
				</button>
			<?php endforeach; ?>
		</nav>

		<div class="zui-quickhelp">
			<h4 class="zui-quickhelp__title"><?php esc_html_e( 'Quick Help', 'zorem-returns' ); ?></h4>
			<p class="zui-quickhelp__text"><?php esc_html_e( 'Learn how to configure Zorem Returns for best results.', 'zorem-returns' ); ?></p>
			<div class="zui-quickhelp__links">
				<a class="zui-quickhelp__link" href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noreferrer noopener">
					<span><?php esc_html_e( 'View Documentation', 'zorem-returns' ); ?></span>
					<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
				</a>
				<a class="zui-quickhelp__link zui-quickhelp__link--muted" href="<?php echo esc_url( $support_url ); ?>" target="_blank" rel="noreferrer noopener">
					<span><?php esc_html_e( 'Get Support', 'zorem-returns' ); ?></span>
					<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
				</a>
			</div>
			<div class="zui-quickhelp__art" aria-hidden="true">
				<span class="zui-quickhelp__glow"></span>
				<span class="zui-quickhelp__sphere"></span>
				<span class="zui-quickhelp__paper zui-quickhelp__paper--1">
					<span class="zui-quickhelp__line zui-quickhelp__line--brand"></span>
					<span class="zui-quickhelp__line zui-quickhelp__line--mid"></span>
					<span class="zui-quickhelp__line zui-quickhelp__line--short"></span>
				</span>
				<span class="zui-quickhelp__paper zui-quickhelp__paper--2">
					<span class="zui-quickhelp__line zui-quickhelp__line--accent"></span>
					<span class="zui-quickhelp__line zui-quickhelp__line--mid"></span>
					<span class="zui-quickhelp__line zui-quickhelp__line--mid"></span>
					<span class="zui-quickhelp__line zui-quickhelp__line--short"></span>
				</span>
				<span class="zui-quickhelp__check">&#10003;</span>
			</div>
		</div>
	</aside>

	<main class="zui-content" id="rt4wc-set-content">
		<form class="return_plugin_setting_tab_form rt4wc-settings-form" method="post" id="rt4wc_setting_tab_form">
			<?php $rt_nonce = wp_create_nonce( 'return_settings_tab' ); ?>
			<input type="hidden" name="return_settings_tab_nonce" value="<?php echo esc_attr( $rt_nonce ); ?>">
			<input type="hidden" name="action" value="return_settings_tab_update">

			<?php foreach ( $rt_sections as $slug => $meta ) :
				$is_active = ( $slug === $rt_section );
				?>
				<section id="rt4wc-section-<?php echo esc_attr( $slug ); ?>" class="zui-section<?php echo $is_active ? ' is-active' : ''; ?>" data-section="<?php echo esc_attr( $slug ); ?>"<?php echo $is_active ? '' : ' hidden'; ?>>

					<div class="zui-section-header">
						<div class="zui-section-header__main">
							<span class="zui-section-header__icon"><?php echo $meta['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — static SVG ?></span>
							<div class="zui-section-header__text">
								<div class="zui-section-header__titlewrap">
									<h2 class="zui-section-header__title"><?php echo esc_html( $meta['title'] ); ?></h2>
								</div>
								<p class="zui-section-header__sub"><?php echo esc_html( $meta['sub'] ); ?></p>
							</div>
						</div>
						<div class="zui-section-header__actions">
							<span class="zui-savebtn-wrap">
								<button name="save" type="submit" class="zui-savebtn rt4wc-save button-return" value="Save Changes">
									<span class="zui-savebtn__spinner" aria-hidden="true"></span>
									<span class="zui-savebtn__label"><?php esc_html_e( 'Save Changes', 'zorem-returns' ); ?></span>
								</button>
							</span>
						</div>
					</div>

					<div class="zui-card rt4wc-settings-card">
						<?php
						switch ( $slug ) {
							case 'general-settings':
								zorem_returns_exchanges()->common->get_html( $this->return_eligibility_settings() );
								break;
							case 'return-settings':
								zorem_returns_exchanges()->common->get_html( $this->return_option_settings() );
								break;
							case 'exchange-settings':
								zorem_returns_exchanges()->common->get_html( $this->exchange_option_settings() );
								break;
							case 'fraud-prevention':
								zorem_returns_exchanges()->common->get_html( $this->fraud_prevention_section_master() );
								?>
								<div class="rt4wc-fp-body" data-rt4wc-fp-body>
								<?php
								zorem_returns_exchanges()->common->get_html( $this->fraud_prevention_section_limit_toggle() );
								?>
								<div class="rt4wc-fp-limit-body" data-rt4wc-fp-limit-body>
								<?php
								zorem_returns_exchanges()->common->get_html( $this->fraud_prevention_section_limit_fields() );
								?>
								</div>
								<?php
								zorem_returns_exchanges()->common->get_html( $this->fraud_prevention_section_admin_notify_toggle() );
								?>
								<div class="rt4wc-fp-admin-body" data-rt4wc-fp-admin-body>
								<?php
								zorem_returns_exchanges()->common->get_html( $this->fraud_prevention_section_admin_notify_field() );
								?>
								</div>
								<?php
								zorem_returns_exchanges()->common->get_html( $this->fraud_prevention_section_blocklist_before_upload() );
								?>
								<div class="zui-row rt4wc-row">
									<div class="zui-row__head">
										<span class="zui-row__label">
											<?php esc_html_e( 'Bulk Upload to Blocklist', 'zorem-returns' ); ?>
											<span class="zui-tooltip" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'Upload a CSV or TXT file containing one email address or numeric user ID per line. Headers are detected and skipped. Entries are merged into the blocklist (duplicates ignored).', 'zorem-returns' ); ?>">
												<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
												<span class="zui-tooltip__bubble"><?php esc_attr_e( 'Upload a CSV or TXT file containing one email address or numeric user ID per line.', 'zorem-returns' ); ?><span class="zui-tooltip__arrow"></span></span>
											</span>
										</span>
									</div>
									<div class="zui-row__control">
										<div class="zorem-fp-inline-row">
											<input type="file" id="zorem_blocklist_csv_file" accept=".csv,.txt" />
											<input type="hidden" id="zorem_blocklist_csv_nonce" value="<?php echo esc_attr( wp_create_nonce( 'zorem_blocklist_csv_nonce' ) ); ?>">
											<button type="button" class="zui-btn-secondary" id="zorem_blocklist_csv_upload_btn"><?php esc_html_e( 'Upload', 'zorem-returns' ); ?></button>
											<span class="spinner" id="zorem_blocklist_csv_spinner"></span>
										</div>
										<p class="description" id="zorem_blocklist_csv_result" style="margin-top:6px;"></p>
									</div>
								</div>
								<?php
								zorem_returns_exchanges()->common->get_html( $this->fraud_prevention_section_blocklist_after_upload() );
								$rt_diag_nonce = wp_create_nonce( 'zorem_fraud_diagnostic_nonce' );
								?>
								<div class="zui-row rt4wc-row" data-field="fraud_prevention_check">
									<div class="zui-row__head">
										<span class="zui-row__label">
											<?php esc_html_e( 'Check Customer Return Count', 'zorem-returns' ); ?>
											<span class="zui-tooltip" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'Look up a customer by email or numeric user ID to see how many return rows they have within the current time window and whether they would be blocked.', 'zorem-returns' ); ?>">
												<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
												<span class="zui-tooltip__bubble"><?php esc_attr_e( 'Look up a customer by email or numeric user ID to see how many return rows they have within the current time window.', 'zorem-returns' ); ?><span class="zui-tooltip__arrow"></span></span>
											</span>
										</span>
									</div>
									<div class="zui-row__control">
										<div class="zorem-fp-inline-row">
											<input type="text" id="zorem_fp_lookup" class="zui-input" placeholder="<?php esc_attr_e( 'customer@example.com OR 42', 'zorem-returns' ); ?>" style="max-width:360px;">
											<input type="hidden" id="zorem_fp_diagnostic_nonce" value="<?php echo esc_attr( $rt_diag_nonce ); ?>">
											<button type="button" class="zui-btn-secondary" id="zorem_fp_check_btn"><?php esc_html_e( 'Check', 'zorem-returns' ); ?></button>
											<span class="spinner" id="zorem_fp_check_spinner"></span>
										</div>
										<div class="rt4wc-fp-check-result" id="zorem_fp_check_result" hidden></div>
									</div>
								</div>
								<?php
								?>
								</div>
								<?php
								break;
							case 'warranty':
								zorem_returns_exchanges()->common->get_html( $this->warranty_section_master() );
								?>
								<div class="rt4wc-warranty-body" data-rt4wc-warranty-body>
								<?php
								zorem_returns_exchanges()->common->get_html( $this->warranty_section_body() );
								?>
								</div>
								<?php
								break;
						}
						?>
					</div>

				</section>
			<?php endforeach; ?>
		</form>
	</main>
</div>
