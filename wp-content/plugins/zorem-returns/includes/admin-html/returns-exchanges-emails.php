<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial: variables come from the including class scope.
/**
 * Notifications — ZUI redesign.
 *
 * Sidebar: Email Notifications | SMS Notifications
 * Sub-tabs inside Email: Customer | Admin
 * Each notification renders as a .zui-list-row style card with the toggle + gear.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$smswoo_active   = function_exists( 'SMSWOO' ) || class_exists( 'Zorem_Sms_For_Woocommerce' );
$sms_returns_url = admin_url( 'admin.php?page=sms-for-woocommerce&tab=returns-sms' );

$customer_emails = array(
	'customer_return_requested'      => esc_html__( 'Return Request Received', 'zorem-returns' ),
	'customer_return_approved'       => esc_html__( 'Return Approved', 'zorem-returns' ),
	'customer_return_completed'      => esc_html__( 'Return Completed', 'zorem-returns' ),
	'customer_return_rejected'       => esc_html__( 'Return Rejected', 'zorem-returns' ),
	'customer_return_store_credit'   => esc_html__( 'Return Store Credit', 'zorem-returns' ),
	'customer_exchange_requested'    => esc_html__( 'Exchange Request Received', 'zorem-returns' ),
	'customer_exchange_approved'     => esc_html__( 'Exchange Approved', 'zorem-returns' ),
	'customer_exchange_completed'    => esc_html__( 'Exchange Completed', 'zorem-returns' ),
	'customer_exchange_rejected'     => esc_html__( 'Exchange Rejected', 'zorem-returns' ),
	'customer_exchange_store_credit' => esc_html__( 'Exchange Store Credit', 'zorem-returns' ),
	'customer_warranty_submitted'    => esc_html__( 'Warranty Claim Submitted', 'zorem-returns' ),
	'customer_warranty_under_review' => esc_html__( 'Warranty Claim Under Review', 'zorem-returns' ),
	'customer_warranty_approved'     => esc_html__( 'Warranty Claim Approved', 'zorem-returns' ),
	'customer_warranty_rejected'     => esc_html__( 'Warranty Claim Rejected', 'zorem-returns' ),
	'customer_warranty_resolved'     => esc_html__( 'Warranty Claim Resolved', 'zorem-returns' ),
);

$admin_emails = array(
	'admin_return_requested'      => esc_html__( 'Return Request Received', 'zorem-returns' ),
	'admin_return_approved'       => esc_html__( 'Return Approved', 'zorem-returns' ),
	'admin_return_completed'      => esc_html__( 'Return Completed', 'zorem-returns' ),
	'admin_return_rejected'       => esc_html__( 'Return Rejected', 'zorem-returns' ),
	'admin_exchange_requested'    => esc_html__( 'Exchange Request Received', 'zorem-returns' ),
	'admin_exchange_approved'     => esc_html__( 'Exchange Approved', 'zorem-returns' ),
	'admin_exchange_completed'    => esc_html__( 'Exchange Completed', 'zorem-returns' ),
	'admin_exchange_rejected'     => esc_html__( 'Exchange Rejected', 'zorem-returns' ),
	'admin_warranty_submitted'    => esc_html__( 'Warranty Claim Submitted', 'zorem-returns' ),
	'admin_warranty_under_review' => esc_html__( 'Warranty Claim Under Review', 'zorem-returns' ),
	'admin_warranty_approved'     => esc_html__( 'Warranty Claim Approved', 'zorem-returns' ),
	'admin_warranty_rejected'     => esc_html__( 'Warranty Claim Rejected', 'zorem-returns' ),
	'admin_warranty_resolved'     => esc_html__( 'Warranty Claim Resolved', 'zorem-returns' ),
);

$docs_url    = 'https://docs.zorem.com/docs/zorem-returns-exchanges/?utm_source=wp-admin&utm_medium=RT4WC&utm_campaign=QuickHelp';
$support_url = 'https://www.zorem.com/my-account/contact-support/';

// Active sidebar channel: 'email' or 'sms'.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$rt_channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : 'email';
if ( ! in_array( $rt_channel, array( 'email', 'sms' ), true ) ) {
	$rt_channel = 'email';
}
?>

<div class="zui-layout">
	<div class="zui-sidebar__overlay" data-ast-drawer-close></div>

	<aside class="zui-sidebar" id="rt4wc-notif-sidebar">
		<div class="zui-sidebar__mobile-head">
			<span class="zui-sidebar__mobile-title"><?php esc_html_e( 'Notifications', 'zorem-returns' ); ?></span>
			<button type="button" class="zui-sidebar__close" data-ast-drawer-close aria-label="<?php esc_attr_e( 'Close menu', 'zorem-returns' ); ?>">
				<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
			</button>
		</div>

		<nav class="zui-sidebar__nav" aria-label="<?php esc_attr_e( 'Notification channels', 'zorem-returns' ); ?>">
			<button type="button" class="zui-sidebar__item<?php echo ( 'email' === $rt_channel ) ? ' is-active' : ''; ?>" data-section="email" aria-controls="rt4wc-notif-email">
				<span class="zui-sidebar__icon">
					<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
				</span>
				<span class="zui-sidebar__label"><?php esc_html_e( 'Email Notifications', 'zorem-returns' ); ?></span>
			</button>
			<button type="button" class="zui-sidebar__item<?php echo ( 'sms' === $rt_channel ) ? ' is-active' : ''; ?>" data-section="sms" aria-controls="rt4wc-notif-sms">
				<span class="zui-sidebar__icon">
					<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
				</span>
				<span class="zui-sidebar__label"><?php esc_html_e( 'SMS Notifications', 'zorem-returns' ); ?></span>
			</button>
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

	<main class="zui-content" id="rt4wc-notif-content">
		<!-- ===== EMAIL PANEL ===== -->
		<section id="rt4wc-notif-email" class="zui-section<?php echo ( 'email' === $rt_channel ) ? ' is-active' : ''; ?>" data-section="email"<?php echo ( 'email' === $rt_channel ) ? '' : ' hidden'; ?>>

			<input type="hidden" id="rt4wc_notifications_status_email" value="<?php echo esc_attr( wp_create_nonce( 'return_notifications_tab' ) ); ?>">

			<div class="rt4wc-notif-subtabs zui-tabs" role="tablist">
				<button type="button" class="zui-tabs__item is-active" data-subtarget="rt4wc-notif-email-customer" role="tab" aria-selected="true">
					<?php esc_html_e( 'Customer', 'zorem-returns' ); ?>
				</button>
				<button type="button" class="zui-tabs__item" data-subtarget="rt4wc-notif-email-admin" role="tab" aria-selected="false">
					<?php esc_html_e( 'Admin', 'zorem-returns' ); ?>
				</button>
			</div>

			<form method="post" id="email_notifications_status" class="email_notifications_status">
				<?php wp_nonce_field( 'email_notifications_status', 'email_notifications_status_nonce' ); ?>
				<input type="hidden" name="action" value="email_notifications_status_update">

				<!-- Customer sub-panel -->
				<div id="rt4wc-notif-email-customer" class="rt4wc-notif-subpanel rt4wc-notif-list">
					<?php foreach ( $customer_emails as $key => $label ) :
						$value          = get_option( $key . '_status', 'yes' );
						$checked        = ( 'yes' === $value ) ? 'checked' : '';
						$customizer_url = admin_url( 'admin.php?page=zorem_returns_exchanges_customizer&preview=' . $key );
						?>
						<?php
						$rt_dot_type = 'default';
						if ( false !== strpos( $key, 'rejected' ) ) {
							$rt_dot_type = 'rejected';
						} elseif ( false !== strpos( $key, 'store_credit' ) ) {
							$rt_dot_type = 'storecredit';
						}
						?>
						<div class="rt4wc-notif-item zui-list-row" data-notif-type="<?php echo esc_attr( $rt_dot_type ); ?>">
							<span class="rt4wc-notif-item__dot"></span>
							<a href="<?php echo esc_url( $customizer_url ); ?>" class="rt4wc-notif-item__label"><?php echo esc_html( $label ); ?></a>
							<span class="rt4wc-notif-item__status <?php echo ( 'yes' === $value ) ? 'is-active' : 'is-inactive'; ?>"><?php echo ( 'yes' === $value ) ? esc_html__( 'ACTIVE', 'zorem-returns' ) : esc_html__( 'INACTIVE', 'zorem-returns' ); ?></span>
							<label class="zui-toggle rt4wc-notif-item__toggle">
								<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="0">
								<input type="checkbox" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" class="zui-toggle__input tgl tgl-flat-rt4wc rt4wc-notif-item__input" value="1" <?php echo esc_attr( $checked ); ?>>
								<span class="zui-toggle__track"><span class="zui-toggle__thumb"></span></span>
							</label>
							<a class="rt4wc-notif-item__gear" href="<?php echo esc_url( $customizer_url ); ?>" title="<?php esc_attr_e( 'Customize', 'zorem-returns' ); ?>" aria-label="<?php esc_attr_e( 'Customize', 'zorem-returns' ); ?>">
								<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
							</a>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Admin sub-panel -->
				<div id="rt4wc-notif-email-admin" class="rt4wc-notif-subpanel rt4wc-notif-list" style="display:none;">
					<?php foreach ( $admin_emails as $key => $label ) :
						$value          = get_option( $key . '_status', 'yes' );
						$checked        = ( 'yes' === $value ) ? 'checked' : '';
						$customizer_url = admin_url( 'admin.php?page=zorem_returns_exchanges_customizer&preview=' . $key );
						?>
						<?php
						$rt_dot_type = 'default';
						if ( false !== strpos( $key, 'rejected' ) ) {
							$rt_dot_type = 'rejected';
						} elseif ( false !== strpos( $key, 'store_credit' ) ) {
							$rt_dot_type = 'storecredit';
						}
						?>
						<div class="rt4wc-notif-item zui-list-row" data-notif-type="<?php echo esc_attr( $rt_dot_type ); ?>">
							<span class="rt4wc-notif-item__dot"></span>
							<a href="<?php echo esc_url( $customizer_url ); ?>" class="rt4wc-notif-item__label"><?php echo esc_html( $label ); ?></a>
							<span class="rt4wc-notif-item__status <?php echo ( 'yes' === $value ) ? 'is-active' : 'is-inactive'; ?>"><?php echo ( 'yes' === $value ) ? esc_html__( 'ACTIVE', 'zorem-returns' ) : esc_html__( 'INACTIVE', 'zorem-returns' ); ?></span>
							<label class="zui-toggle rt4wc-notif-item__toggle">
								<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="0">
								<input type="checkbox" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" class="zui-toggle__input tgl tgl-flat-rt4wc rt4wc-notif-item__input" value="1" <?php echo esc_attr( $checked ); ?>>
								<span class="zui-toggle__track"><span class="zui-toggle__thumb"></span></span>
							</label>
							<a class="rt4wc-notif-item__gear" href="<?php echo esc_url( $customizer_url ); ?>" title="<?php esc_attr_e( 'Customize', 'zorem-returns' ); ?>" aria-label="<?php esc_attr_e( 'Customize', 'zorem-returns' ); ?>">
								<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
							</a>
						</div>
					<?php endforeach; ?>
				</div>
			</form>
		</section>

		<!-- ===== SMS PANEL ===== -->
		<section id="rt4wc-notif-sms" class="zui-section<?php echo ( 'sms' === $rt_channel ) ? ' is-active' : ''; ?>" data-section="sms"<?php echo ( 'sms' === $rt_channel ) ? '' : ' hidden'; ?>>
			<?php if ( $smswoo_active ) : ?>
				<div class="zui-card rt4wc-sms-installed-card">
					<div class="rt4wc-sms-installed-icon">
						<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
					</div>
					<h3><?php esc_html_e( 'SMS for WooCommerce is active', 'zorem-returns' ); ?></h3>
					<p><?php esc_html_e( 'Configure Return &amp; Exchange SMS notifications from the SMS for WooCommerce settings.', 'zorem-returns' ); ?></p>
					<a href="<?php echo esc_url( $sms_returns_url ); ?>" class="zui-btn-primary"><?php esc_html_e( 'Open Returns SMS Settings', 'zorem-returns' ); ?></a>
				</div>
			<?php else : ?>
				<div class="zui-card rt4wc-sms-install-panel">
					<div class="rt4wc-sms-promo-icon">
						<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
					</div>
					<h3><?php esc_html_e( 'Send SMS Notifications for Returns &amp; Exchanges', 'zorem-returns' ); ?></h3>
					<p><?php esc_html_e( 'Keep your customers informed with instant SMS updates when their return or exchange request is received, approved, completed or rejected.', 'zorem-returns' ); ?></p>
					<ul class="rt4wc-sms-feature-list">
						<li><svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?php esc_html_e( 'Return Request Received, Approved, Completed &amp; Rejected', 'zorem-returns' ); ?></li>
						<li><svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?php esc_html_e( 'Exchange Request Received, Approved, Completed &amp; Rejected', 'zorem-returns' ); ?></li>
						<li><svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?php esc_html_e( 'Customisable message templates with order variables', 'zorem-returns' ); ?></li>
						<li><svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><?php esc_html_e( 'Works alongside email notifications independently', 'zorem-returns' ); ?></li>
					</ul>
					<a href="https://zorem.com/products/sms-for-woocommerce/" target="_blank" class="zui-btn-primary rt4wc-sms-install-btn">
						<?php esc_html_e( 'Get SMS for WooCommerce', 'zorem-returns' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</section>
	</main>
</div>
