<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial: variables come from the including class scope.
/**
 * Header file — ZUI-styled brand bar + top tabs.
 *
 * $tab is provided by the calling scope. The ZUI shell wraps the whole admin
 * page (see settings_page_callback) so the header and every section below it
 * share the same .zui-scope styles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$subscription_status = zorem_returns_exchanges()->license->check_subscription_status();

$zui_dir = zorem_returns_exchanges()->get_plugin_path() . '/assets/zui';
if ( ! function_exists( 'zorem_ui_get_plugin_brand' ) && file_exists( $zui_dir . '/brand.php' ) ) {
	require_once $zui_dir . '/brand.php';
}
if ( ! function_exists( 'zorem_ui_icon' ) && file_exists( $zui_dir . '/icons.php' ) ) {
	require_once $zui_dir . '/icons.php';
}

$brand = function_exists( 'zorem_ui_get_plugin_brand' )
	? zorem_ui_get_plugin_brand( 'zorem-returns/zorem-returns.php' )
	: null;
if ( ! is_array( $brand ) ) {
	$brand = array(
		'name'    => __( 'Returns', 'zorem-returns' ),
		'badge'   => 'PRO',
		'tagline' => __( 'Zorem Returns', 'zorem-returns' ),
		'icon'    => 'refresh-cw',
	);
}

$tab_base_url    = admin_url( 'admin.php?page=zorem-returns-exchanges' );

// Context-aware brand: Dashboard/Warranty pages → "Returns Center",
// Settings / Notifications / License → "Returns Settings". The PRO badge is
// intentionally dropped here (source registry keeps it for cross-plugin
// consistency, but the boss preferred a cleaner header). Tagline stays so the
// header still reads "Returns Center | Zorem Returns".
$dashboard_ctx    = empty( $tab ) || in_array( $tab, array( 'returns-orders', 'exchanges-orders', 'warranty-claims' ), true );
$brand['name']    = $dashboard_ctx ? __( 'Returns Center', 'zorem-returns' ) : __( 'Returns Settings', 'zorem-returns' );
$brand['tagline'] = __( 'Zorem Returns', 'zorem-returns' );
$brand['badge']   = '';
$docs_url        = 'https://docs.zorem.com/docs/zorem-returns-exchanges/?utm_source=wp-admin&utm_medium=RT4WC&utm_campaign=DOCS';
$support_url     = 'https://www.zorem.com/my-account/contact-support/';
$dashboard_tab   = empty( $tab ) || in_array( $tab, array( 'returns-orders', 'exchanges-orders', 'warranty-claims' ), true );
$warranty_obj    = isset( zorem_returns_exchanges()->warranty ) ? zorem_returns_exchanges()->warranty : null;
$warranty_show   = $warranty_obj && $warranty_obj->is_enabled();
?>
<header class="zui-header" id="rt4wc-set-header">
	<div class="zui-header__bar">
		<div class="zui-header__lead">
			<button type="button" class="zui-header__menu-toggle" data-ast-drawer-toggle aria-label="<?php esc_attr_e( 'Open settings menu', 'zorem-returns' ); ?>">
				<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
			</button>
			<div class="zui-brand">
				<span class="zui-brand__emblem" aria-hidden="true" style="<?php echo ! empty( $brand['emblem_bg'] ) ? 'background:' . esc_attr( $brand['emblem_bg'] ) . ';' : ''; ?><?php echo ! empty( $brand['emblem_color'] ) ? 'color:' . esc_attr( $brand['emblem_color'] ) . ';' : ''; ?>">
					<?php if ( function_exists( 'zorem_ui_icon' ) ) { zorem_ui_icon( $brand['icon'] ); } else { ?>
						<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>
					<?php } ?>
				</span>
				<span class="zui-brand__name"><?php echo esc_html( $brand['name'] ); ?></span>
				<?php if ( ! empty( $brand['badge'] ) ) : ?>
					<span class="zui-brand__badge"><?php echo esc_html( $brand['badge'] ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $brand['tagline'] ) ) : ?>
					<span class="zui-brand__tagline"><?php echo esc_html( $brand['tagline'] ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<div class="zui-header-actions">
			<?php if ( $dashboard_tab ) : ?>
				<a class="zui-header-action zui-header-action--with-label" href="<?php echo esc_url( $tab_base_url . '&tab=settings' ); ?>">
					<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
					<span class="zui-header-action__label"><?php esc_html_e( 'Settings', 'zorem-returns' ); ?></span>
				</a>
			<?php else : ?>
				<a class="zui-header-action zui-header-action--with-label" href="<?php echo esc_url( $tab_base_url ); ?>">
					<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
					<span class="zui-header-action__label"><?php esc_html_e( 'Returns Dashboard', 'zorem-returns' ); ?></span>
				</a>
				<a class="zui-header-action zui-header-action--with-label" href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noreferrer noopener">
					<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 0 3-3h7z"></path></svg>
					<span class="zui-header-action__label"><?php esc_html_e( 'Docs Portal', 'zorem-returns' ); ?></span>
				</a>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $subscription_status ) : ?>
		<nav class="zui-tabs" aria-label="<?php esc_attr_e( 'Returns modules', 'zorem-returns' ); ?>">
			<?php if ( $dashboard_tab ) : ?>
				<button type="button" class="zui-tabs__item<?php echo ( 'warranty-claims' !== $tab ) ? ' is-active' : ''; ?>" data-tab="dashboard"><?php esc_html_e( 'Dashboard', 'zorem-returns' ); ?></button>
				<?php if ( $warranty_show ) : ?>
					<button type="button" class="zui-tabs__item<?php echo ( 'warranty-claims' === $tab ) ? ' is-active' : ''; ?>" data-tab="warranty-claims"><?php esc_html_e( 'Warranty Claims', 'zorem-returns' ); ?></button>
				<?php endif; ?>
			<?php else : ?>
				<a class="zui-tabs__item<?php echo ( 'settings' === $tab ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( $tab_base_url . '&tab=settings' ); ?>" data-tab="settings"><?php esc_html_e( 'Settings', 'zorem-returns' ); ?></a>
				<a class="zui-tabs__item<?php echo ( 'return-emails' === $tab ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( $tab_base_url . '&tab=return-emails' ); ?>" data-tab="return-emails"><?php esc_html_e( 'Notifications', 'zorem-returns' ); ?></a>
				<a class="zui-tabs__item<?php echo ( 'ai-assistant' === $tab ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( $tab_base_url . '&tab=ai-assistant' ); ?>" data-tab="ai-assistant"><?php esc_html_e( 'AI Assistant', 'zorem-returns' ); ?></a>
				<a class="zui-tabs__item<?php echo ( 'license' === $tab ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( $tab_base_url . '&tab=license' ); ?>" data-tab="license"><?php esc_html_e( 'License', 'zorem-returns' ); ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
</header>
