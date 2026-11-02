<?php
/**
 * Admin UI: Dashboard (stats + scan + clusters), Repeat Customers, New Customers,
 * Import CSV, Blocklist, Settings.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Admin {

	const CAP  = 'manage_woocommerce';
	const SLUG = 'fraud-shield';

	/**
	 * Current page's secondary-sort key (Ctrl+click multi-sort). Set per page
	 * render; consumed by sort_th() so existing call sites need no changes.
	 *
	 * @var string
	 */
	private $cur_sort2 = '';

	/**
	 * Current page's secondary-sort direction.
	 *
	 * @var string
	 */
	private $cur_dir2 = 'desc';

	/**
	 * Hook menu + assets + form handlers.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_fs_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_fs_blocklist_add', array( $this, 'handle_blocklist_add' ) );
		add_action( 'admin_post_fs_blocklist_remove', array( $this, 'handle_blocklist_remove' ) );
		add_action( 'admin_post_fs_blocklist_remove_ips', array( $this, 'handle_blocklist_remove_ips' ) );
		add_action( 'admin_post_fs_blocklist_remove_selected', array( $this, 'handle_blocklist_remove_selected' ) );
		add_action( 'admin_post_fs_block_cluster', array( $this, 'handle_block_cluster' ) );
		add_action( 'admin_post_fs_block_profile', array( $this, 'handle_block_profile' ) );
		add_action( 'admin_post_fs_blocklist_message', array( $this, 'handle_blocklist_message' ) );
		add_action( 'admin_post_fs_unblock_action', array( $this, 'handle_unblock_action' ) );
		add_action( 'admin_post_fs_resync_action', array( $this, 'handle_resync_action' ) );
		add_action( 'admin_post_fs_action_message', array( $this, 'handle_action_message' ) );
		add_action( 'admin_post_fs_remove_ungrouped', array( $this, 'handle_remove_ungrouped' ) );
		add_action( 'admin_post_fs_export_report', array( $this, 'handle_export_report' ) );
		add_action( 'admin_post_fs_export_blocklist', array( $this, 'handle_export_blocklist' ) );
		add_action( 'admin_post_fs_import_blocklist', array( $this, 'handle_import_blocklist' ) );
		add_action( 'admin_post_fs_export_verified', array( $this, 'handle_export_verified' ) );
		add_action( 'admin_post_fs_import_verified', array( $this, 'handle_import_verified' ) );
		add_action( 'admin_post_fs_verify_customer', array( $this, 'handle_verify_customer' ) );
		add_action( 'admin_post_fs_allow_email', array( $this, 'handle_allow_email' ) );
		add_action( 'admin_post_fs_resolve_block', array( $this, 'handle_resolve_block' ) );
		add_action( 'admin_post_fs_block_place', array( $this, 'handle_block_place' ) );
		add_action( 'admin_post_fs_verify_cred', array( $this, 'handle_verify_cred' ) );
		add_action( 'admin_post_fs_unverify_cred', array( $this, 'handle_unverify_cred' ) );
		add_action( 'admin_post_fs_gw_restrict', array( $this, 'handle_gw_restrict' ) );
		add_action( 'admin_post_fs_bulk_gw_restrict', array( $this, 'handle_bulk_gw_restrict' ) );
		add_action( 'admin_post_fs_bulk_gw_clear', array( $this, 'handle_bulk_gw_clear' ) );
		add_action( 'admin_post_fs_idv_require', array( $this, 'handle_idv_require' ) );
		add_action( 'admin_post_fs_idv_revoke', array( $this, 'handle_idv_revoke' ) );
		add_action( 'admin_post_fs_idv_accept', array( $this, 'handle_idv_accept' ) );
		add_action( 'admin_post_fs_idv_sync', array( $this, 'handle_idv_sync' ) );
		add_action( 'admin_post_fs_idv_rebuild', array( $this, 'handle_idv_rebuild' ) );
		add_action( 'admin_post_fs_idv_import', array( $this, 'handle_idv_import' ) );
		add_action( 'admin_post_fs_hv_review', array( $this, 'handle_hv_review' ) );
		add_action( 'wp_ajax_fs_cluster_order', array( $this, 'ajax_cluster_order' ) );
		add_action( 'wp_ajax_fs_geo_backfill', array( $this, 'ajax_geo_backfill' ) );

		// WooCommerce → Orders: a "Fraud Shield" column marking the orders the
		// linked-identity rule cancelled (v1.26), on both order storages.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'orders_list_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'orders_list_column_legacy' ), 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'orders_list_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'orders_list_column_hpos' ), 10, 2 );
	}

	/**
	 * Register menu + submenus.
	 */
	public function menu() {
		add_menu_page( __( 'Fraud Shield', 'fraud-shield' ), __( 'Fraud Shield', 'fraud-shield' ), self::CAP, self::SLUG, array( $this, 'page_dashboard' ), 'dashicons-shield', 56 );
		add_submenu_page( self::SLUG, __( 'Dashboard', 'fraud-shield' ), __( 'Dashboard', 'fraud-shield' ), self::CAP, self::SLUG, array( $this, 'page_dashboard' ) );
		add_submenu_page( self::SLUG, __( 'High-Value Review', 'fraud-shield' ), $this->hv_menu_label(), self::CAP, self::SLUG . '-high-value', array( $this, 'page_high_value' ) );
		add_submenu_page( self::SLUG, __( 'Financial Protection', 'fraud-shield' ), __( 'Financial Protection', 'fraud-shield' ), self::CAP, self::SLUG . '-protection', array( $this, 'page_protection' ) );
		add_submenu_page( self::SLUG, __( 'Repeat Customers', 'fraud-shield' ), __( 'Repeat Customers', 'fraud-shield' ), self::CAP, self::SLUG . '-flagged', array( $this, 'page_flagged' ) );
		add_submenu_page( self::SLUG, __( 'New Customers', 'fraud-shield' ), __( 'New Customers', 'fraud-shield' ), self::CAP, self::SLUG . '-new-customers', array( $this, 'page_new_customers' ) );
		add_submenu_page( self::SLUG, __( 'Profiles', 'fraud-shield' ), __( 'Profiles', 'fraud-shield' ), self::CAP, self::SLUG . '-profiles', array( $this, 'page_profiles' ) );
		add_submenu_page( self::SLUG, __( 'Customer Network', 'fraud-shield' ), __( 'Customer Network', 'fraud-shield' ), self::CAP, self::SLUG . '-network', array( $this, 'page_network' ) );
		add_submenu_page( self::SLUG, __( 'Addresses', 'fraud-shield' ), __( 'Addresses', 'fraud-shield' ), self::CAP, self::SLUG . '-addresses', array( $this, 'page_addresses' ) );
		add_submenu_page( self::SLUG, __( 'IPs', 'fraud-shield' ), __( 'IPs', 'fraud-shield' ), self::CAP, self::SLUG . '-ips', array( $this, 'page_ips' ) );
		add_submenu_page( self::SLUG, __( 'Geography', 'fraud-shield' ), __( 'Geography', 'fraud-shield' ), self::CAP, self::SLUG . '-geo', array( $this, 'page_geo' ) );
		add_submenu_page( self::SLUG, __( 'Import CSV', 'fraud-shield' ), __( 'Import CSV', 'fraud-shield' ), self::CAP, self::SLUG . '-import', array( $this, 'page_import' ) );
		add_submenu_page( self::SLUG, __( 'Blocklist', 'fraud-shield' ), __( 'Blocklist', 'fraud-shield' ), self::CAP, self::SLUG . '-blocklist', array( $this, 'page_blocklist' ) );
		add_submenu_page( self::SLUG, __( 'Checkout Locks', 'fraud-shield' ), __( 'Checkout Locks', 'fraud-shield' ), self::CAP, self::SLUG . '-locks', array( $this, 'page_checkout_locks' ) );
		add_submenu_page( self::SLUG, __( 'Auto-Cancelled Orders', 'fraud-shield' ), __( 'Auto-Cancelled', 'fraud-shield' ), self::CAP, self::SLUG . '-cancels', array( $this, 'page_linked_cancels' ) );
		add_submenu_page( self::SLUG, __( 'Verified Customers', 'fraud-shield' ), __( 'Verified Customers', 'fraud-shield' ), self::CAP, self::SLUG . '-verified', array( $this, 'page_verified' ) );
		add_submenu_page( self::SLUG, __( 'ID Verifications', 'fraud-shield' ), __( 'ID Verifications', 'fraud-shield' ), self::CAP, self::SLUG . '-idv', array( $this, 'page_idv' ) );
		add_submenu_page( self::SLUG, __( 'Card Checks', 'fraud-shield' ), __( 'Card Checks', 'fraud-shield' ), self::CAP, self::SLUG . '-cards', array( $this, 'page_card_checks' ) );
		add_submenu_page( self::SLUG, __( 'Test Checkout', 'fraud-shield' ), __( 'Test Checkout', 'fraud-shield' ), self::CAP, self::SLUG . '-test', array( $this, 'page_test' ) );
		add_submenu_page( self::SLUG, __( 'API &amp; Webhooks', 'fraud-shield' ), __( 'API &amp; Webhooks', 'fraud-shield' ), self::CAP, FS_API_Admin::SLUG, array( FS_API_Admin::instance(), 'render_page' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'fraud-shield' ), __( 'Settings', 'fraud-shield' ), self::CAP, self::SLUG . '-settings', array( $this, 'page_settings' ) );
	}

	/**
	 * Enqueue assets on our screens.
	 *
	 * @param string $hook Page hook.
	 */
	public function assets( $hook ) {
		// WooCommerce → Orders (legacy list and HPOS list): only the few rules
		// the Fraud Shield column needs, registered on their own handle so the
		// full admin stylesheet never loads there.
		if ( in_array( (string) $hook, array( 'edit.php', 'woocommerce_page_wc-orders' ), true ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
				wp_register_style( 'fs-orders-list', false, array(), FS_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
				wp_enqueue_style( 'fs-orders-list' );
				wp_add_inline_style(
					'fs-orders-list',
					'.column-fs_shield{width:12ch}.fs-oc-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;line-height:1.6;color:#fff;background:#8b0000;white-space:nowrap;text-decoration:none}.fs-oc-badge.fs-oc-blocklist{background:#1d2327}.fs-oc-badge.fs-oc-hv-pending{background:#dba617;color:#1d2327}.fs-oc-badge.fs-oc-hv-cleared{background:#46b450}.fs-oc-badge.fs-oc-hv-held{background:#dba617;color:#1d2327}.fs-oc-badge.fs-oc-hv-cancelled{background:#1d2327}.fs-oc-why{display:block;margin:3px 0 4px;font-size:11px;line-height:1.4;color:#646970}.fs-oc-none{color:#c3c4c7}'
				);
			}
			return;
		}
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'fs-admin', FS_PLUGIN_URL . 'assets/admin.css', array(), FS_VERSION );
		wp_enqueue_script( 'fs-admin', FS_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), FS_VERSION, true );
		wp_localize_script(
			'fs-admin',
			'FS_ADMIN',
			array(
				'ajax'         => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'fs_scan' ),
				'clusterNonce' => wp_create_nonce( 'fs_cluster' ),
				'geoNonce'     => wp_create_nonce( 'fs_geo_backfill' ),
				'scanState'    => FS_Scanner::get_state(),
				'importState'  => FS_Importer::get_state(),
			)
		);
	}

	/* ===================== Dashboard ===================== */

	/**
	 * Dashboard page.
	 */
	public function page_dashboard() {
		$this->guard();

		// Drill-down: a single address or IP cluster.
		$view = isset( $_GET['fs_view'] ) ? sanitize_key( wp_unslash( $_GET['fs_view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $view, array( 'address', 'ip' ), true ) ) {
			$key = isset( $_GET['fs_key'] ) ? sanitize_text_field( wp_unslash( $_GET['fs_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( '' !== $key ) {
				$this->render_cluster_detail( $view, $key );
				return;
			}
		}

		$stats    = FS_Data::summary_stats();
		$settings = FS_Core::instance()->settings();
		$scan     = FS_Scanner::get_state();
		?>
		<div class="wrap fs-wrap">
			<h1><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Fraud Shield', 'fraud-shield' ); ?></h1>
			<?php $this->maybe_notice(); ?>

			<?php if ( 'observe' === $settings['mode'] ) : ?>
				<div class="notice notice-warning inline"><p>
				<?php
				printf(
					/* translators: 1: opening <strong>, 2: closing </strong>, 3: opening <a>, 4: closing </a> */
					esc_html__( '%1$sObserve mode.%2$s Orders are scored and noted but nothing is blocked or held. Calibrate against your flagged orders, then switch to %3$sEnforce%4$s.', 'fraud-shield' ),
					'<strong>',
					'</strong>',
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ) . '">',
					'</a>'
				);
				?>
				</p></div>
			<?php else : ?>
				<div class="notice notice-success inline"><p>
				<?php
				printf(
					/* translators: 1: opening <strong>, 2: closing </strong>, 3: high-risk action (hold/block/flag) */
					esc_html__( '%1$sEnforce mode is ON.%2$s Blocklist hits and block-level scores are rejected at checkout; high-risk orders are %3$s.', 'fraud-shield' ),
					'<strong>',
					'</strong>',
					esc_html( $settings['action_high'] )
				);
				?>
				</p></div>
			<?php endif; ?>

			<div class="fs-cards">
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['indexed'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Orders indexed', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-red"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['chargebacks'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Charged back', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-red"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['high'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'High / Block risk', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-amber"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['medium'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Medium risk', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['blocklist'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Blocklist entries', 'fraud-shield' ); ?></div></div>
				<?php
				// High-value orders nobody has looked at yet (v1.32): the
				// number this page exists to keep from drifting upwards.
				$hv_amount = FS_Live::hv_review_amount();
				if ( $hv_amount > 0 ) :
					$hv_counts = FS_Data::hv_unreviewed_counts( $hv_amount );
					?>
					<a class="fs-card fs-card-link <?php echo $hv_counts['week'] > 0 ? 'fs-card-amber' : 'fs-card-green'; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-high-value' ) ); ?>">
						<div class="fs-num"><?php echo esc_html( number_format_i18n( $hv_counts['today'] ) ); ?></div>
						<div class="fs-label"><?php esc_html_e( 'High-value orders awaiting review today', 'fraud-shield' ); ?></div>
						<div class="fs-card-sub"><?php echo esc_html( sprintf( /* translators: %s: number of orders */ _n( '%s this week', '%s this week', $hv_counts['week'], 'fraud-shield' ), number_format_i18n( $hv_counts['week'] ) ) ); ?></div>
					</a>
				<?php endif; ?>
			</div>

			<?php $this->render_savings_panel(); ?>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Quick search', 'fraud-shield' ); ?></h2>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-addresses' ); ?>" />
					<p class="fs-filters">
						<input type="search" name="s" placeholder="<?php esc_attr_e( 'Search an address (or email, name, phone, IP)…', 'fraud-shield' ); ?>" class="regular-text" />
						<button class="button button-primary"><?php esc_html_e( 'Search addresses', 'fraud-shield' ); ?></button>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-profiles' ) ); ?>"><?php esc_html_e( 'Search customer profiles instead', 'fraud-shield' ); ?></a>
					</p>
				</form>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Database scan', 'fraud-shield' ); ?></h2>
				<p><?php esc_html_e( 'Index orders, then score them against the complete cross-order history. Progress is saved after every batch — you can pause, close the tab, and resume later. Re-running continues from where the index left off instead of re-fetching everything.', 'fraud-shield' ); ?></p>

				<?php
				$active     = in_array( $scan['status'], array( 'running', 'paused' ), true );
				$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=fs_export_index' ), 'fs_export_index' );
				?>

				<?php if ( $active ) : ?>
					<div class="notice notice-info inline" style="margin:0 0 12px;"><p>
					<?php
					$done  = (int) $scan['offset'];
					$tot   = 'index' === $scan['phase'] ? (int) $scan['index_total'] : (int) $scan['score_total'];
					$phase = 'index' === $scan['phase'] ? __( 'indexing', 'fraud-shield' ) : __( 'scoring', 'fraud-shield' );
					printf(
						/* translators: 1: status word, 2: phase name, 3: processed count, 4: total count */
						esc_html__( 'Scan %1$s (%2$s): %3$s of %4$s processed. Resume to continue, or cancel to discard.', 'fraud-shield' ),
						esc_html( $scan['status'] ),
						esc_html( $phase ),
						esc_html( number_format_i18n( $done ) ),
						esc_html( number_format_i18n( $tot ) )
					);
					?>
					</p></div>
				<?php endif; ?>

				<p class="fs-scan-controls">
					<label><?php esc_html_e( 'Scope', 'fraud-shield' ); ?>
						<select id="fs-scan-scope">
							<option value="all"><?php esc_html_e( 'All time (continue where it left off)', 'fraud-shield' ); ?></option>
							<option value="6m"><?php esc_html_e( 'Last 6 months', 'fraud-shield' ); ?></option>
							<option value="3m"><?php esc_html_e( 'Last 3 months', 'fraud-shield' ); ?></option>
							<option value="1m"><?php esc_html_e( 'Last 1 month', 'fraud-shield' ); ?></option>
							<option value="new"><?php esc_html_e( 'New orders since last scan', 'fraud-shield' ); ?></option>
						</select>
					</label>
					<label title="<?php esc_attr_e( 'Off = skip orders already indexed (faster). On = re-fetch everything.', 'fraud-shield' ); ?>"><input type="checkbox" id="fs-scan-force" /> <?php esc_html_e( 'Re-index orders already scanned', 'fraud-shield' ); ?></label>
				</p>

				<p>
					<button class="button button-primary" id="fs-scan-start"><?php esc_html_e( 'Run scan', 'fraud-shield' ); ?></button>
					<button class="button" id="fs-rescore-start" title="<?php esc_attr_e( 'Recompute scores for every indexed order (use after changing settings or marking chargebacks)', 'fraud-shield' ); ?>"><?php esc_html_e( 'Recompute all scores', 'fraud-shield' ); ?></button>
					<button class="button button-primary" id="fs-scan-resume" style="<?php echo $active ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Resume', 'fraud-shield' ); ?></button>
					<button class="button" id="fs-scan-pause" style="<?php echo ( 'running' === $scan['status'] ) ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Pause', 'fraud-shield' ); ?></button>
					<button class="button button-link-delete" id="fs-scan-cancel" style="<?php echo $active ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Cancel', 'fraud-shield' ); ?></button>
					<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export scanned orders (CSV)', 'fraud-shield' ); ?></a>
				</p>
				<p class="description"><?php esc_html_e( 'Export downloads everything indexed so far as a CSV. Re-upload that file on the Import CSV page to restore the index (for example, after pausing).', 'fraud-shield' ); ?></p>

				<div id="fs-progress" class="fs-progress" style="display:none;">
					<div class="fs-progress-bar"><span id="fs-progress-fill"></span></div>
					<p id="fs-progress-text"><?php esc_html_e( 'Starting…', 'fraud-shield' ); ?></p>
				</div>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Top risky shipping addresses', 'fraud-shield' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Addresses shared by multiple customer emails, or tied to a chargeback. The table shows the top 50 risky ones.', 'fraud-shield' ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-addresses' ) ); ?>"><?php esc_html_e( 'Browse ALL addresses', 'fraud-shield' ); ?></a>
					<a class="button" href="<?php echo esc_url( $this->export_url( 'addresses' ) ); ?>"><?php esc_html_e( 'Export risky (CSV)', 'fraud-shield' ); ?></a>
				</p>
				<?php $this->render_address_clusters(); ?>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Top risky IP addresses', 'fraud-shield' ); ?></h2>
				<p class="description"><?php esc_html_e( 'IPs used by multiple customer accounts, or tied to a chargeback. The table shows the top 50 risky ones.', 'fraud-shield' ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-ips' ) ); ?>"><?php esc_html_e( 'Browse ALL IPs', 'fraud-shield' ); ?></a>
					<a class="button" href="<?php echo esc_url( $this->export_url( 'ips' ) ); ?>"><?php esc_html_e( 'Export risky (CSV)', 'fraud-shield' ); ?></a>
				</p>
				<?php $this->render_ip_clusters(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Dashboard panel: the money Fraud Shield kept out of a fraudster's hands.
	 *
	 * Headline = checkouts stopped by a VERDICT (blocklist, linked identity,
	 * block-level score), summed at the grand total the customer would have
	 * paid. Money merely held back pending verification (velocity lock, new-
	 * account hold, ID rules) is shown alongside but never added in — many of
	 * those customers are legitimate and come back. Each order counts once;
	 * refused attempts count once per identity, rule and day, so retries do
	 * not inflate anything. Counting starts with the version that introduced
	 * the ledger, so it only ever grows from real events.
	 */
	private function render_savings_panel() {
		$period = isset( $_GET['fs_period'] ) ? (int) $_GET['fs_period'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $period, array( 0, 30, 90, 365 ), true ) ) {
			$period = 0;
		}

		$all      = FS_Data::prevented_stats( 0 );
		$window   = 0 === $period ? $all : FS_Data::prevented_stats( $period );
		$month    = FS_Data::prevented_stats( 30 );
		$by_kind  = FS_Data::prevented_by_kind( $period );
		$monthly  = FS_Data::prevented_monthly( 12 );
		$recent   = FS_Data::prevented_recent( 8 );
		$kinds    = FS_Data::prevented_kinds();
		$currency = '' !== $all['currency'] ? array( 'currency' => $all['currency'] ) : array();
		$money    = function ( $v ) use ( $currency ) {
			return wp_kses_post( wc_price( (float) $v, $currency ) );
		};
		$peak = 0.0;
		foreach ( $monthly as $m ) {
			$peak = max( $peak, (float) $m['fraud'] + (float) $m['verify'] );
		}
		$periods = array(
			30  => __( 'Last 30 days', 'fraud-shield' ),
			90  => __( 'Last 90 days', 'fraud-shield' ),
			365 => __( 'Last 12 months', 'fraud-shield' ),
			0   => __( 'All time', 'fraud-shield' ),
		);
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<div class="fs-panel fs-savings">
			<h2><?php esc_html_e( 'Fraud prevented', 'fraud-shield' ); ?> <a class="button button-small" style="margin-left:8px;vertical-align:middle;" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-protection' ) ); ?>"><?php esc_html_e( 'Full Financial Protection report →', 'fraud-shield' ); ?></a></h2>
			<p class="description"><?php esc_html_e( 'The money that did not change hands because Fraud Shield stopped the checkout: orders it cancelled the moment they were placed, attempts it refused before an order existed, and orders staff cancelled or refunded while they were on the blocklist or linked to it — at the total the customer would have paid. The headline counts verdicts only — the blocklist, the linked-to-blocklist rule, and a block-level risk score under Enforce. Money merely held back while a customer verifies (velocity lock, new-account hold, ID rules) is shown separately and never added to the headline. Each order counts once; a customer retrying is counted once per rule per day.', 'fraud-shield' ); ?></p>

			<div class="fs-cards">
				<div class="fs-card fs-card-green"><div class="fs-num"><?php echo $money( $all['fraud_amount'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Saved — all time', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-green"><div class="fs-num"><?php echo $money( $month['fraud_amount'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Saved — last 30 days', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-red"><div class="fs-num"><?php echo esc_html( number_format_i18n( $all['fraud_stopped'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Fraudulent checkouts stopped', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-amber"><div class="fs-num"><?php echo $money( $all['verify_amount'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Held pending verification', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $all['attempts'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Attempts turned away', 'fraud-shield' ); ?></div></div>
			</div>

			<h3 class="fs-savings-sub"><?php esc_html_e( 'Last 12 months', 'fraud-shield' ); ?></h3>
			<div class="fs-bars" role="img" aria-label="<?php esc_attr_e( 'Money stopped per month', 'fraud-shield' ); ?>">
				<?php foreach ( $monthly as $ym => $m ) : ?>
					<?php
					// Pixel heights against the 120px column in admin.css.
					$fraud_h  = $peak > 0 ? (int) round( 120 * (float) $m['fraud'] / $peak ) : 0;
					$verify_h = $peak > 0 ? (int) round( 120 * (float) $m['verify'] / $peak ) : 0;
					$ts       = strtotime( $ym . '-01' );
					$label    = $ts ? wp_date( 'M', $ts ) : $ym;
					$title    = sprintf(
						/* translators: 1: month, 2: money stopped by a verdict, 3: money held pending verification, 4: checkouts stopped */
						__( '%1$s: %2$s prevented, %3$s held pending verification, %4$s checkouts stopped', 'fraud-shield' ),
						$ts ? wp_date( 'F Y', $ts ) : $ym,
						html_entity_decode( wp_strip_all_tags( wc_price( (float) $m['fraud'], $currency ) ) ),
						html_entity_decode( wp_strip_all_tags( wc_price( (float) $m['verify'], $currency ) ) ),
						number_format_i18n( (int) $m['stopped'] )
					);
					?>
					<div class="fs-bar" title="<?php echo esc_attr( $title ); ?>">
						<div class="fs-bar-col">
							<span class="fs-bar-verify" style="height:<?php echo (int) $verify_h; ?>px"></span>
							<span class="fs-bar-fraud" style="height:<?php echo (int) $fraud_h; ?>px"></span>
						</div>
						<span class="fs-bar-label"><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="fs-bars-legend"><span class="fs-legend-fraud"></span> <?php esc_html_e( 'Fraud prevented', 'fraud-shield' ); ?> &nbsp; <span class="fs-legend-verify"></span> <?php esc_html_e( 'Held pending verification', 'fraud-shield' ); ?></p>

			<h3 class="fs-savings-sub"><?php esc_html_e( 'By control', 'fraud-shield' ); ?>
				<span class="fs-savings-periods">
				<?php foreach ( $periods as $days => $plabel ) : ?>
					<a class="button button-small<?php echo $period === $days ? ' button-primary' : ''; ?>" href="<?php echo esc_url( 0 === $days ? $base : add_query_arg( 'fs_period', $days, $base ) ); ?>"><?php echo esc_html( $plabel ); ?></a>
				<?php endforeach; ?>
				</span>
			</h3>
			<table class="wp-list-table widefat striped fs-savings-table">
				<thead><tr>
					<th><?php esc_html_e( 'Control', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Counted as', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Checkouts stopped', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Attempts', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Money', 'fraud-shield' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $kinds as $kind => $meta ) : ?>
					<?php $k = $by_kind[ $kind ] ?? array( 'amount' => 0, 'stopped' => 0, 'attempts' => 0 ); ?>
					<tr>
						<td><strong><?php echo esc_html( $meta['label'] ); ?></strong></td>
						<td><?php echo 'fraud' === $meta['group'] ? '<span class="fs-pill fs-pill-green">' . esc_html__( 'Fraud prevented', 'fraud-shield' ) . '</span>' : '<span class="fs-pill fs-pill-amber">' . esc_html__( 'Held pending verification', 'fraud-shield' ) . '</span>'; ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $k['stopped'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $k['attempts'] ) ); ?></td>
						<td><?php echo $money( $k['amount'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot><tr>
					<th colspan="4"><?php echo esc_html( sprintf( /* translators: %s: the selected period label */ __( 'Fraud prevented — %s', 'fraud-shield' ), $periods[ $period ] ) ); ?></th>
					<th><?php echo $money( $window['fraud_amount'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
				</tr></tfoot>
			</table>

			<?php if ( ! empty( $recent ) ) : ?>
				<h3 class="fs-savings-sub"><?php esc_html_e( 'Most recent', 'fraud-shield' ); ?></h3>
				<table class="wp-list-table widefat striped fs-savings-table">
					<thead><tr>
						<th><?php esc_html_e( 'When', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Control', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'What happened', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Money', 'fraud-shield' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $recent as $r ) : ?>
						<?php
						$r_kind  = (string) $r['kind'];
						$r_email = (string) $r['email_norm'];
						$r_oid   = (int) $r['order_id'];
						$r_tries = (int) $r['attempts'];
						$r_tied  = array();
						foreach ( array_slice( (array) ( $r['linked_to'] ?? array() ), 0, 2 ) as $r_id ) {
							$r_tied[] = FS_Blocklist::identity_label( $r_id );
						}
						$r_tied = array_filter( $r_tied );
						?>
						<tr>
							<td><?php echo esc_html( $r['last_at'] ? mysql2date( 'Y-m-d H:i', (string) $r['last_at'] ) : '—' ); ?></td>
							<td><?php echo esc_html( isset( $kinds[ $r_kind ] ) ? $kinds[ $r_kind ]['label'] : $r_kind ); ?></td>
							<td>
								<?php if ( 'cancelled' === (string) $r['stage'] && $r_oid > 0 ) : ?>
									<?php echo 'staff' === (string) ( $r['stopped_by'] ?? 'rule' ) ? esc_html__( 'Order cancelled by staff', 'fraud-shield' ) : esc_html__( 'Order cancelled', 'fraud-shield' ); ?> <a href="<?php echo esc_url( $this->order_edit_url( $r_oid ) ); ?>">#<?php echo (int) $r_oid; ?></a>
								<?php else : ?>
									<?php echo esc_html( sprintf( /* translators: %s: number of attempts */ _n( 'Refused at checkout (%s attempt)', 'Refused at checkout (%s attempts)', $r_tries, 'fraud-shield' ), number_format_i18n( $r_tries ) ) ); ?>
								<?php endif; ?>
								<?php if ( ! empty( $r_tied ) ) : ?>
									<br/><span class="fs-muted"><?php echo esc_html( sprintf( /* translators: %s: the blocked identities the stop was tied to */ __( 'tied to %s', 'fraud-shield' ), implode( '; ', $r_tied ) ) ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' !== $r_email ) : ?>
									<a href="<?php echo esc_url( $this->profile_url( $r_email ) ); ?>"><?php echo esc_html( '' !== trim( (string) $r['customer_name'] ) ? (string) $r['customer_name'] : $r_email ); ?></a>
									<?php if ( '' !== trim( (string) $r['customer_name'] ) ) : ?><br/><span class="fs-muted"><?php echo esc_html( $r_email ); ?></span><?php endif; ?>
								<?php else : ?>
									<?php echo esc_html( '' !== trim( (string) $r['customer_name'] ) ? (string) $r['customer_name'] : ( (int) $r['user_id'] > 0 ? sprintf( /* translators: %d: account id */ __( 'account #%d', 'fraud-shield' ), (int) $r['user_id'] ) : __( '(guest)', 'fraud-shield' ) ) ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo $money( $r['amount'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="fs-muted"><?php esc_html_e( 'Nothing stopped yet. The moment a control refuses or cancels a checkout, the amount lands here.', 'fraud-shield' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Address cluster table.
	 */
	private function render_address_clusters() {
		global $wpdb;
		$t    = FS_Data::index_table();
		$rows = $wpdb->get_results( // phpcs:ignore
			"SELECT ship_hash, MAX(ship_text) ship_text, COUNT(DISTINCT email_norm) emails,
				COUNT(DISTINCT customer_name) names, COUNT(*) orders, MAX(is_chargeback) has_cb,
				MAX(order_date) last_order
			FROM {$t}
			WHERE ship_hash<>''
			GROUP BY ship_hash
			HAVING emails>=2 OR has_cb=1
			ORDER BY has_cb DESC, emails DESC, orders DESC
			LIMIT 50",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No address clusters yet. Run a full scan.', 'fraud-shield' ) . '</p>';
			return;
		}

		$blocked = FS_Blocklist::blocked_values( 'address', wp_list_pluck( $rows, 'ship_hash' ) );
		$actions = FS_Blocklist::actions_for_targets( 'address', wp_list_pluck( $rows, 'ship_hash' ) );
		$vcreds  = FS_Live::verified_creds();

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Shipping address', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Emails', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Names', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Orders', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Chargeback?', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Last order', 'fraud-shield' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$flagged   = ( $r['has_cb'] || $r['emails'] >= 3 );
			$view_url  = add_query_arg(
				array(
					'page'    => self::SLUG,
					'fs_view' => 'address',
					'fs_key'  => $r['ship_hash'],
				),
				admin_url( 'admin.php' )
			);
			/* translators: %s: first characters of an address hash */
			$addr_text = $r['ship_text'] ? $r['ship_text'] : sprintf( __( '(hash %s…)', 'fraud-shield' ), substr( $r['ship_hash'], 0, 10 ) );

			echo '<tr' . ( $flagged ? ' class="fs-row-flag"' : '' ) . '>';
			echo '<td><a href="' . esc_url( $view_url ) . '">' . esc_html( $addr_text ) . '</a></td>';
			echo '<td>' . (int) $r['emails'] . '</td>';
			echo '<td>' . (int) $r['names'] . '</td>';
			echo '<td>' . (int) $r['orders'] . '</td>';
			echo '<td>' . ( $r['has_cb'] ? '<span class="fs-pill fs-pill-red">' . esc_html__( 'YES', 'fraud-shield' ) . '</span>' : '—' ) . '</td>';
			echo '<td>' . esc_html( $r['last_order'] ) . '</td>';
			echo '<td><a class="button button-small button-primary" href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'fraud-shield' ) . '</a> ' . $this->block_cluster_button( 'address', $r['ship_hash'], isset( $actions[ $r['ship_hash'] ] ) ? $actions[ $r['ship_hash'] ] : null, in_array( $r['ship_hash'], $blocked, true ) ) . ' ' . $this->verify_button( 'address', $r['ship_hash'], isset( $vcreds[ 'address:' . $r['ship_hash'] ] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * IP cluster table.
	 */
	private function render_ip_clusters() {
		global $wpdb;
		$t    = FS_Data::index_table();
		$rows = $wpdb->get_results( // phpcs:ignore
			"SELECT ip, COUNT(DISTINCT email_norm) emails, COUNT(*) orders, MAX(is_chargeback) has_cb, MAX(order_date) last_order
			FROM {$t}
			WHERE ip<>''
			GROUP BY ip
			HAVING emails>=3 OR has_cb=1
			ORDER BY has_cb DESC, emails DESC
			LIMIT 50",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No IP clusters yet.', 'fraud-shield' ) . '</p>';
			return;
		}

		$blocked = FS_Blocklist::blocked_values( 'ip', wp_list_pluck( $rows, 'ip' ) );
		$actions = FS_Blocklist::actions_for_targets( 'ip', wp_list_pluck( $rows, 'ip' ) );
		$vcreds  = FS_Live::verified_creds();

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'IP', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Accounts', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Orders', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Chargeback?', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Last order', 'fraud-shield' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$view_url = add_query_arg(
				array(
					'page'    => self::SLUG,
					'fs_view' => 'ip',
					'fs_key'  => $r['ip'],
				),
				admin_url( 'admin.php' )
			);
			echo '<tr' . ( $r['has_cb'] ? ' class="fs-row-flag"' : '' ) . '>';
			echo '<td><a href="' . esc_url( $view_url ) . '">' . esc_html( $r['ip'] ) . '</a></td>';
			echo '<td>' . (int) $r['emails'] . '</td>';
			echo '<td>' . (int) $r['orders'] . '</td>';
			echo '<td>' . ( $r['has_cb'] ? '<span class="fs-pill fs-pill-red">' . esc_html__( 'YES', 'fraud-shield' ) . '</span>' : '—' ) . '</td>';
			echo '<td>' . esc_html( $r['last_order'] ) . '</td>';
			echo '<td><a class="button button-small button-primary" href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'fraud-shield' ) . '</a> ' . $this->block_cluster_button( 'ip', $r['ip'], isset( $actions[ $r['ip'] ] ) ? $actions[ $r['ip'] ] : null, in_array( $r['ip'], $blocked, true ) ) . ' ' . $this->verify_button( 'ip', $r['ip'], isset( $vcreds[ 'ip:' . $r['ip'] ] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/* ===================== Cluster detail (drill-down) ===================== */

	/**
	 * Build the WooCommerce order-edit URL without loading the order (HPOS-aware).
	 *
	 * @param int $oid Order id.
	 * @return string
	 */
	private function order_edit_url( $oid ) {
		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $oid );
		}
		return admin_url( 'post.php?post=' . (int) $oid . '&action=edit' );
	}

	/**
	 * Human-readable geolocation for an IP, via WooCommerce's geolocation
	 * (MaxMind). Cached per request; returns '' when it can't be resolved.
	 *
	 * @param string $ip IP address.
	 * @return string e.g. "United States (US)" or "".
	 */
	private function ip_location_label( $ip ) {
		static $cache = array();
		$ip = (string) $ip;
		if ( '' === $ip ) {
			return '';
		}
		if ( isset( $cache[ $ip ] ) ) {
			return $cache[ $ip ];
		}

		$label = '';
		if ( class_exists( 'WC_Geolocation' ) ) {
			// Local database only (no network call) so bulk lookups stay fast.
			$geo  = WC_Geolocation::geolocate_ip( $ip, false, false );
			$code = ( is_array( $geo ) && ! empty( $geo['country'] ) ) ? $geo['country'] : '';
			if ( '' !== $code ) {
				$name = '';
				if ( function_exists( 'WC' ) && WC()->countries ) {
					$countries = WC()->countries->get_countries();
					$name      = isset( $countries[ $code ] ) ? $countries[ $code ] : '';
				}
				$label = $name ? $name . ' (' . $code . ')' : $code;
				if ( ! empty( $geo['state'] ) ) {
					$label = $geo['state'] . ', ' . $label;
				}
			}
		}

		$cache[ $ip ] = $label;
		return $label;
	}

	/**
	 * Shared, comprehensive order table used by every drill-down (address, IP,
	 * customer). Each row is expandable to its full per-order detail.
	 *
	 * @param array $rows Index rows.
	 */
	/**
	 * A Block/Unblock toggle for an address/IP cluster. Once a block action
	 * exists it flips to "✓ Blocked — click to unblock" (the same endpoint
	 * decides which way to go from the stored state).
	 *
	 * @param string     $type       'address' | 'ip'.
	 * @param string     $key        ship_hash or IP.
	 * @param array|null $action     Owning action info [ 'id'=>int, 'entries'=>int ] or null.
	 * @param bool       $is_blocked Whether the anchor value is on the blocklist (legacy detection).
	 * @return string HTML.
	 */
	private function block_cluster_button( $type, $key, $action = null, $is_blocked = false ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=fs_block_cluster&type=' . rawurlencode( $type ) . '&key=' . rawurlencode( $key ) ),
			'fs_block_cluster'
		);
		return $this->toggle_button_html( $url, $action, $is_blocked, __( 'Block this and EVERY associated credential (emails, phones, IPs, accounts)? Matching checkouts will be rejected in Enforce mode.', 'fraud-shield' ) );
	}

	/**
	 * A Verify/Unverify toggle for a credential (email / address / IP). Verified
	 * credentials bypass the velocity lock, holds, and gateway restrictions
	 * (never the blocklist) and list on the Verified Customers page. The green
	 * "✓ Verified" state is the visual cue; clicking it removes the verification.
	 *
	 * @param string $type        email|address|ip.
	 * @param string $value       Credential value (normalized email / ship_hash / IP).
	 * @param bool   $is_verified Current verified state (from a single verified_creds() load).
	 * @return string HTML.
	 */
	private function verify_button( $type, $value, $is_verified ) {
		if ( $is_verified ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=fs_unverify_cred&type=' . rawurlencode( $type ) . '&value=' . rawurlencode( $value ) ), 'fs_unverify_cred' );
			return '<a class="button button-small fs-verified-btn" href="' . esc_url( $url ) . '" title="' . esc_attr__( 'Verified — bypasses velocity lock, holds, and gateway restrictions. Click to remove.', 'fraud-shield' ) . '" onclick="return confirm(\'' . esc_js( __( 'Remove this verification? The customer will be subject to checkout controls again.', 'fraud-shield' ) ) . '\');">&#10004; ' . esc_html__( 'Verified', 'fraud-shield' ) . '</a>';
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=fs_verify_cred&type=' . rawurlencode( $type ) . '&value=' . rawurlencode( $value ) ), 'fs_verify_cred' );
		return '<a class="button button-small" href="' . esc_url( $url ) . '" onclick="return confirm(\'' . esc_js( __( 'Verify as a known-good customer? They will bypass the velocity lock, holds, and gateway restrictions (the blocklist still applies).', 'fraud-shield' ) ) . '\');">' . esc_html__( 'Verify', 'fraud-shield' ) . '</a>';
	}

	/**
	 * Shared markup for a Block/Unblock toggle. Three states:
	 *   1. an action exists → green "Blocked — click to unblock" with a
	 *      blast-radius confirm stating how many credentials will be removed;
	 *   2. no action but the anchor is on the blocklist → legacy pre-1.6 row,
	 *      shown blocked; a click rebuilds it as a removable grouped action;
	 *   3. nothing → plain "Block" with the given confirm.
	 *
	 * @param string     $url           Nonced toggle endpoint.
	 * @param array|null $action        Owning action info [ 'id'=>int, 'entries'=>int ] or null.
	 * @param bool       $is_blocked    Whether the anchor value is on the blocklist.
	 * @param string     $block_confirm Confirm text for the initial block.
	 * @return string
	 */
	private function toggle_button_html( $url, $action, $is_blocked, $block_confirm ) {
		if ( is_array( $action ) && ! empty( $action['id'] ) ) {
			$count   = (int) $action['entries'];
			$confirm = sprintf(
				/* translators: %d: number of credentials that will be removed */
				_n(
					'Unblock this? The %d credential created by this block will be removed from the blocklist.',
					'Unblock this? All %d credentials created by this block will be removed from the blocklist.',
					max( 1, $count ),
					'fraud-shield'
				),
				$count
			);
			return '<a class="button button-small fs-blocked-btn" href="' . esc_url( $url ) . '" title="' . esc_attr__( 'On the blocklist. Click to unblock — removes every credential this block created.', 'fraud-shield' ) . '" onclick="return confirm(\'' . esc_js( $confirm ) . '\');">&#10004; ' . esc_html__( 'Blocked — click to unblock', 'fraud-shield' ) . '</a>';
		}
		if ( $is_blocked ) {
			return '<a class="button button-small fs-blocked-btn" href="' . esc_url( $url ) . '" title="' . esc_attr__( 'Blocked before v1.6 — click to rebuild as a removable grouped block.', 'fraud-shield' ) . '">&#10004; ' . esc_html__( 'Blocked (legacy)', 'fraud-shield' ) . '</a>';
		}
		return '<a class="button button-small" href="' . esc_url( $url ) . '" onclick="return confirm(\'' . esc_js( $block_confirm ) . '\');">' . esc_html__( 'Block', 'fraud-shield' ) . '</a>';
	}

	/**
	 * Toggle a cluster block. If an action already exists for this address/IP it
	 * is unblocked (all its created credentials removed); otherwise the cluster
	 * is blocked. Same endpoint, same button — the stored state decides.
	 */
	public function handle_block_cluster() {
		$this->guard();
		check_admin_referer( 'fs_block_cluster' );

		$type = isset( $_REQUEST['type'] ) && 'ip' === $_REQUEST['type'] ? 'ip' : 'address';
		$key  = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';

		// Return to where the click happened (dashboard or the cluster detail).
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::SLUG );
		}

		if ( '' === $key ) {
			wp_safe_redirect( $back );
			exit;
		}

		// The cluster target is the ship_hash / IP verbatim (same value used when
		// the action was created and when the button state was computed).
		$existing = FS_Blocklist::action_for_target( $type, $key );
		if ( $existing ) {
			$removed = FS_Blocklist::unblock_action( (int) $existing['id'] );
			wp_safe_redirect( add_query_arg( array( 'fs_status' => 'unblocked', 'fs_removed' => (int) $removed ), $back ) );
			exit;
		}

		$result = FS_Blocklist::block_cluster( $type, $key, __( 'Blocked cluster from dashboard', 'fraud-shield' ) );
		wp_safe_redirect( add_query_arg( array( 'fs_status' => 'cluster_blocked', 'fs_added' => (int) $result['added'] ), $back ) );
		exit;
	}

	/**
	 * Toggle a customer-identity block. Unblocks the existing profile action, or
	 * blocks the identity (email + every credential it ever used) if none exists.
	 */
	public function handle_block_profile() {
		$this->guard();
		check_admin_referer( 'fs_block_profile' );

		$email = isset( $_REQUEST['email'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['email'] ) ) : '';

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::SLUG . '-profiles' );
		}

		if ( '' === $email ) {
			wp_safe_redirect( $back );
			exit;
		}

		$target   = FS_Normalizer::email( $email );
		$existing = FS_Blocklist::action_for_target( 'profile', $target );
		if ( $existing ) {
			// Release the PERSON: their credentials leave the blocklist even
			// where a cluster block or auto-extend also linked them — on a
			// mature store nearly everything is cross-owned, and the default
			// keep-shared rule made this button appear to do nothing. Only
			// credentials that are another standing block's own target stay.
			$owned   = FS_Blocklist::action_entry_count( (int) $existing['id'] );
			$removed = FS_Blocklist::unblock_action( (int) $existing['id'], true );
			$kept    = max( 0, $owned - $removed );
			wp_safe_redirect(
				add_query_arg(
					array(
						'fs_status'  => 'unblocked',
						'fs_removed' => (int) $removed,
						'fs_kept'    => $kept,
					),
					$back
				)
			);
			exit;
		}

		$result = FS_Blocklist::block_identity( $email, __( 'Blocked customer profile', 'fraud-shield' ) );
		wp_safe_redirect( add_query_arg( array( 'fs_status' => 'profile_blocked', 'fs_added' => (int) $result['added'] ), $back ) );
		exit;
	}

	/**
	 * Toggle a destination lock (kind 'place'). Same toggle semantics as
	 * handle_block_cluster(): if a lock already exists for the target it is
	 * removed; otherwise the destination is locked. Never cascades.
	 */
	public function handle_block_place() {
		$this->guard();
		check_admin_referer( 'fs_block_place' );

		$scope   = isset( $_REQUEST['scope'] ) ? sanitize_key( wp_unslash( $_REQUEST['scope'] ) ) : 'city';
		$country = isset( $_REQUEST['country'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['country'] ) ) : '';
		$state   = isset( $_REQUEST['state'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['state'] ) ) : '';
		$city    = isset( $_REQUEST['city'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['city'] ) ) : '';
		$prefix  = isset( $_REQUEST['prefix'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['prefix'] ) ) : '';
		$message = isset( $_REQUEST['message'] ) ? wp_kses_post( wp_unslash( $_REQUEST['message'] ) ) : '';
		// Street scope takes either: 'street' is a RAW line typed by a human
		// ("123 N. Main Street" — house number and unit are parsed off);
		// 'street_key' is an already-normalized street name from a row button
		// or the address page, which must not be re-parsed (see
		// FS_Normalizer::street() on why its output is not a valid input).
		$street_raw = isset( $_REQUEST['street'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['street'] ) ) : '';
		$street_key = isset( $_REQUEST['street_key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['street_key'] ) ) : '';
		// Building scope (v1.31): the house number, typed or carried from a row.
		$number = isset( $_REQUEST['number'] ) ? preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_REQUEST['number'] ) ) ) : '';
		// Street scope (v1.31): the road's explicit boundaries — the other city
		// names, the ZIPs and the counties it runs through — comma, semicolon or
		// newline separated. Each becomes one more key under the same card.
		$split      = function ( $key ) {
			$raw = isset( $_REQUEST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			return array_values( array_filter( array_map( 'trim', preg_split( '/[,;\n\r]+/', (string) $raw ) ) ) );
		};
		$boundaries = array(
			'cities'   => $split( 'boundary_cities' ),
			'zips'     => $split( 'boundary_zips' ),
			'counties' => $split( 'boundary_counties' ),
		);

		if ( ! in_array( $scope, array( 'city', 'state', 'zip', 'street', 'building', 'geo' ), true ) ) {
			$scope = 'city';
		}

		// Area ban (v1.31): a point and a radius. The point comes with the
		// request (the address page located it), or the typed address is
		// geocoded here; the radius is in miles unless given in metres.
		if ( 'geo' === $scope ) {
			$back = wp_get_referer();
			if ( ! $back ) {
				$back = admin_url( 'admin.php?page=' . self::SLUG . '-geo' );
			}
			$lat      = isset( $_REQUEST['lat'] ) ? (float) $_REQUEST['lat'] : 0.0;
			$lng      = isset( $_REQUEST['lng'] ) ? (float) $_REQUEST['lng'] : 0.0;
			$radius_m = isset( $_REQUEST['radius'] ) ? (int) $_REQUEST['radius'] : FS_Normalizer::miles_to_m( isset( $_REQUEST['radius_mi'] ) ? (float) $_REQUEST['radius_mi'] : 0.5 );
			$label    = isset( $_REQUEST['label'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['label'] ) ) : '';
			$region   = isset( $_REQUEST['region'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['region'] ) ) : '';
			if ( 0.0 === $lat && 0.0 === $lng ) {
				$parts = array(
					'address_1' => $street_raw,
					'address_2' => '',
					'city'      => $city,
					'state'     => $state,
					'postcode'  => $prefix,
					'country'   => $country,
				);
				$check = FS_Address_Check::locate( $parts );
				if ( ! is_array( $check ) || ! FS_Address_Check::has_point( $check ) ) {
					wp_safe_redirect( add_query_arg( array( 'fs_status' => 'nolocation' ), $back ) );
					exit;
				}
				$lat    = (float) $check['lat'];
				$lng    = (float) $check['lng'];
				$label  = '' !== $label ? $label : ( '' !== (string) $check['formatted'] ? (string) $check['formatted'] : trim( $street_raw . ', ' . $city . ' ' . $state . ' ' . $prefix ) );
				$region = (string) $check['country'] . '|' . (string) $check['state'];
			}
			$target = FS_Normalizer::geo_key( $lat, $lng, $radius_m );
			if ( '' === $target ) {
				wp_safe_redirect( add_query_arg( array( 'fs_status' => 'badplace' ), $back ) );
				exit;
			}
			$existing = FS_Blocklist::place_action_for_key( $target );
			if ( $existing ) {
				$removed = FS_Blocklist::unblock_action( (int) $existing['id'] );
				wp_safe_redirect( add_query_arg( array( 'fs_status' => 'unblocked', 'fs_removed' => (int) $removed ), $back ) );
				exit;
			}
			$result = FS_Blocklist::block_geofence( $lat, $lng, $radius_m, $label, $message, '', $region );
			wp_safe_redirect( add_query_arg( array( 'fs_status' => 'street_locked', 'fs_added' => (int) $result['added'] ), $back ) );
			exit;
		}
		// city/state/street/building scopes: $a = state. zip scope: $a = prefix.
		// $b = city (city, street) or house number (building). $c = normalized
		// street name (street, building).
		$a = ( 'zip' === $scope ) ? $prefix : $state;
		$b = in_array( $scope, array( 'city', 'street' ), true ) ? $city : ( 'building' === $scope ? $number : '' );
		$c = '';
		if ( in_array( $scope, array( 'street', 'building' ), true ) ) {
			$c = '' !== $street_key ? $street_key : FS_Normalizer::street( $street_raw );
			// A building typed as one line ("8020 NW 66th St") carries its number.
			if ( 'building' === $scope && '' === $b && '' === $street_key ) {
				$b = FS_Normalizer::house( $street_raw );
			}
		}

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::SLUG . '-geo' );
		}

		$target = FS_Normalizer::place_target( $scope, $country, $a, $b, $c );
		if ( '' === $target ) {
			wp_safe_redirect( add_query_arg( array( 'fs_status' => 'badplace' ), $back ) );
			exit;
		}

		// Toggle: a lock already covering this key — as its own target, or as
		// a boundary entry of a street lock created from another row — is
		// released whole, boundaries and all.
		$existing = FS_Blocklist::place_action_for_key( $target );
		if ( $existing ) {
			$removed = FS_Blocklist::unblock_action( (int) $existing['id'] );
			wp_safe_redirect( add_query_arg( array( 'fs_status' => 'unblocked', 'fs_removed' => (int) $removed ), $back ) );
			exit;
		}

		$result = FS_Blocklist::block_place( $scope, $country, $a, $b, '', $message, $c, $boundaries );
		wp_safe_redirect( add_query_arg( array( 'fs_status' => in_array( $scope, array( 'street', 'building' ), true ) ? 'street_locked' : 'place_locked', 'fs_added' => (int) $result['added'] ), $back ) );
		exit;
	}

	/**
	 * AJAX: backfill geo columns for one batch of older orders. Loops from the
	 * client until remaining hits zero. NULL ship_city is the cursor, so the run
	 * is naturally resumable.
	 */
	public function ajax_geo_backfill() {
		check_ajax_referer( 'fs_geo_backfill', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fraud-shield' ) ), 403 );
		}
		$result = FS_Data::geo_backfill_batch( 100 );
		wp_send_json_success( $result );
	}

	/**
	 * Remove-all (unblock) handler for the per-card button on the Blocklist page.
	 */
	public function handle_unblock_action() {
		$this->guard();
		check_admin_referer( 'fs_unblock_action' );

		$action_id = isset( $_REQUEST['action_id'] ) ? (int) $_REQUEST['action_id'] : 0;
		$removed   = $action_id > 0 ? FS_Blocklist::unblock_action( $action_id ) : 0;

		wp_safe_redirect( add_query_arg( array( 'fs_status' => 'unblocked', 'fs_removed' => (int) $removed ), admin_url( 'admin.php?page=' . self::SLUG . '-blocklist' ) ) );
		exit;
	}

	/**
	 * Re-sync handler: re-run a block action's cascade to sweep in credentials
	 * that appeared since it was created.
	 */
	public function handle_resync_action() {
		$this->guard();
		check_admin_referer( 'fs_resync_action' );

		$action_id = isset( $_REQUEST['action_id'] ) ? (int) $_REQUEST['action_id'] : 0;
		$result    = $action_id > 0 ? FS_Blocklist::resync_action( $action_id ) : array( 'added' => 0 );

		wp_safe_redirect( add_query_arg( array( 'fs_status' => 'resynced', 'fs_added' => (int) $result['added'] ), admin_url( 'admin.php?page=' . self::SLUG . '-blocklist' ) ) );
		exit;
	}

	/**
	 * Save (or clear) a whole-action checkout message from the Blocklist page.
	 */
	public function handle_action_message() {
		$this->guard();
		check_admin_referer( 'fs_action_message' );

		$action_id = isset( $_POST['action_id'] ) ? (int) $_POST['action_id'] : 0;
		$message   = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';
		if ( $action_id > 0 ) {
			FS_Blocklist::set_action_message( $action_id, $message );
		}

		wp_safe_redirect( add_query_arg( array( 'fs_status' => 'msgsaved' ), admin_url( 'admin.php?page=' . self::SLUG . '-blocklist' ) ) );
		exit;
	}

	/**
	 * Bulk-remove every ungrouped (legacy) blocklist row.
	 */
	public function handle_remove_ungrouped() {
		$this->guard();
		check_admin_referer( 'fs_remove_ungrouped' );

		$removed = FS_Blocklist::remove_all_ungrouped();

		wp_safe_redirect( add_query_arg( array( 'fs_status' => 'unblocked', 'fs_removed' => (int) $removed ), admin_url( 'admin.php?page=' . self::SLUG . '-blocklist' ) ) );
		exit;
	}

	/**
	 * Export the entire blocklist as CSV (re-importable on another site).
	 */
	public function handle_export_blocklist() {
		$this->guard();
		check_admin_referer( 'fs_export_blocklist' );

		$out    = $this->csv_start( 'fraud-shield-blocklist', array( 'type', 'value', 'reason', 'message', 'created_at', 'action_kind', 'action_target', 'action_title', 'action_message' ) );
		$batch  = 1000;
		$offset = 0;
		do {
			$rows = FS_Blocklist::export_pairs( $batch, $offset );
			foreach ( (array) $rows as $r ) {
				$this->csv_row(
					$out,
					array(
						$r['type'],
						$r['value'],
						$r['reason'],
						isset( $r['message'] ) ? $r['message'] : '',
						$r['created_at'],
						isset( $r['action_kind'] ) ? $r['action_kind'] : '',
						isset( $r['action_target'] ) ? $r['action_target'] : '',
						isset( $r['action_title'] ) ? $r['action_title'] : '',
						isset( $r['action_message'] ) ? $r['action_message'] : '',
					)
				);
			}
			$offset += $batch;
		} while ( count( (array) $rows ) === $batch );

		fclose( $out ); // phpcs:ignore
		exit;
	}

	/**
	 * Import a blocklist CSV exported from another Fraud Shield site.
	 */
	public function handle_import_blocklist() {
		$this->guard();
		check_admin_referer( 'fs_import_blocklist' );

		$redirect = admin_url( 'admin.php?page=' . self::SLUG . '-blocklist' );

		if ( empty( $_FILES['fs_blocklist_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['fs_blocklist_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_safe_redirect( add_query_arg( 'fs_status', 'nofile', $redirect ) );
			exit;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$fh = fopen( $_FILES['fs_blocklist_csv']['tmp_name'], 'r' ); // phpcs:ignore
		if ( ! $fh ) {
			wp_safe_redirect( add_query_arg( 'fs_status', 'badfile', $redirect ) );
			exit;
		}

		// Header row → column positions (tolerates any column order).
		$header = fgetcsv( $fh );
		$map    = array();
		foreach ( (array) $header as $i => $h ) {
			$map[ strtolower( trim( str_replace( "\xEF\xBB\xBF", '', (string) $h ) ) ) ] = $i;
		}
		if ( ! isset( $map['type'], $map['value'] ) ) {
			fclose( $fh ); // phpcs:ignore
			wp_safe_redirect( add_query_arg( 'fs_status', 'badformat', $redirect ) );
			exit;
		}

		// New-format exports carry action columns; group rows back into their
		// original block actions. Rows without an action (legacy/ungrouped on the
		// source, or an old-format CSV) are collected under one "Imported …"
		// action per run, so a bad import is one click to reverse.
		$has_action_cols  = isset( $map['action_kind'], $map['action_target'] );
		$run_stamp        = current_time( 'mysql' );
		$run_key          = 'import-' . time() . '-' . wp_rand( 1000, 9999 );
		$import_action_id = 0;
		$action_cache     = array();

		$added   = 0;
		$skipped = 0;
		while ( false !== ( $row = fgetcsv( $fh ) ) ) { // phpcs:ignore
			if ( array( null ) === $row ) {
				continue;
			}
			// Undo the CSV formula-injection guard our exporter applies.
			$row     = array_map( array( 'FS_Data', 'csv_unescape_cell' ), $row );
			$type    = isset( $row[ $map['type'] ] ) ? sanitize_key( $row[ $map['type'] ] ) : '';
			$value   = isset( $row[ $map['value'] ] ) ? trim( (string) $row[ $map['value'] ] ) : '';
			$reason  = isset( $map['reason'], $row[ $map['reason'] ] ) ? sanitize_text_field( $row[ $map['reason'] ] ) : __( 'Imported blocklist', 'fraud-shield' );
			$message = isset( $map['message'], $row[ $map['message'] ] ) ? wp_kses_post( $row[ $map['message'] ] ) : '';

			if ( '' === $type || '' === $value ) {
				$skipped++;
				continue;
			}

			$akind   = $has_action_cols && isset( $row[ $map['action_kind'] ] ) ? sanitize_key( $row[ $map['action_kind'] ] ) : '';
			$atarget = $has_action_cols && isset( $row[ $map['action_target'] ] ) ? trim( (string) $row[ $map['action_target'] ] ) : '';

			if ( '' !== $akind && '' !== $atarget ) {
				$ck = $akind . '|' . $atarget;
				if ( ! isset( $action_cache[ $ck ] ) ) {
					$atitle              = ( isset( $map['action_title'], $row[ $map['action_title'] ] ) ) ? sanitize_text_field( $row[ $map['action_title'] ] ) : '';
					$amsg                = ( isset( $map['action_message'], $row[ $map['action_message'] ] ) ) ? wp_kses_post( $row[ $map['action_message'] ] ) : '';
					$action_cache[ $ck ] = FS_Blocklist::create_action( $akind, $atarget, '' !== $atitle ? $atitle : $atarget, $amsg );
				}
				$aid = (int) $action_cache[ $ck ];
			} else {
				if ( 0 === $import_action_id ) {
					/* translators: %s: import date/time */
					$import_action_id = FS_Blocklist::create_action( 'import', $run_key, sprintf( __( 'Imported %s', 'fraud-shield' ), $run_stamp ) );
				}
				$aid = (int) $import_action_id;
			}

			$result = FS_Blocklist::add_and_link( $type, $value, $reason, $message, $aid );
			if ( $result > 0 ) {
				$added++;
			} else {
				$skipped++;
			}
		}
		fclose( $fh ); // phpcs:ignore

		wp_safe_redirect(
			add_query_arg(
				array(
					'fs_status'   => 'blimported',
					'fs_added'    => $added,
					'fs_skipped'  => $skipped,
				),
				$redirect
			)
		);
		exit;
	}

	/**
	 * Export every verified customer as CSV (re-importable on another site).
	 *
	 * Two kinds of rows share the file, because "verified customers" is two
	 * stores: accounts carrying the _fs_verified flag (type "account", carried
	 * by email — the only identity that survives a site move, since user ids
	 * don't), and the verified-credential list (types "email", "address",
	 * "ip") with their notes, origin, and added dates. Addresses travel as
	 * their 64-char hashes — that is what is stored, the plain address never
	 * was — and the import accepts hashes directly.
	 */
	public function handle_export_verified() {
		$this->guard();
		check_admin_referer( 'fs_export_verified' );

		$out = $this->csv_start( 'fraud-shield-verified', array( 'type', 'value', 'label', 'source', 'added_at', 'name', 'registered' ) );
		$this->export_verified_rows( $out );
		fclose( $out ); // phpcs:ignore
		exit;
	}

	/**
	 * Write every verified-customer row onto an open CSV stream (the data
	 * half of the export, separated from the HTTP glue so it can be exercised
	 * against in-memory streams).
	 *
	 * Credentials go FIRST, accounts after: a verified account's email is
	 * also on the credential list (the Verify button syncs it there), and
	 * importing the credential first preserves its true note, origin, and
	 * date — the account row that follows then only flags the account.
	 *
	 * @param resource $out Open output stream.
	 */
	private function export_verified_rows( $out ) {
		foreach ( FS_Live::verified_creds() as $key => $meta ) {
			$pos = strpos( (string) $key, ':' );
			if ( false === $pos ) {
				continue;
			}
			$meta = is_array( $meta ) ? $meta : array();
			$this->csv_row(
				$out,
				array(
					substr( (string) $key, 0, $pos ),
					substr( (string) $key, $pos + 1 ),
					(string) ( $meta['label'] ?? '' ),
					(string) ( $meta['src'] ?? '' ),
					(string) ( $meta['at'] ?? '' ),
					'',
					'',
				)
			);
		}

		$batch  = 500;
		$offset = 0;
		do {
			$users = get_users(
				array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_fs_verified',
							'value'   => 'yes',
							'compare' => '=',
						),
					),
					'number'     => $batch,
					'offset'     => $offset,
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'fields'     => array( 'ID', 'user_email', 'user_registered', 'display_name' ),
				)
			);
			foreach ( $users as $u ) {
				$this->csv_row(
					$out,
					array(
						'account',
						(string) $u->user_email,
						'',
						'',
						'',
						(string) $u->display_name,
						(string) $u->user_registered,
					)
				);
			}
			$offset += $batch;
		} while ( count( $users ) === $batch );
	}

	/**
	 * Import verified customers from CSV: the plugin's own export (round
	 * trip), or any file with an email column — down to a plain
	 * one-email-per-line list.
	 *
	 * Import MERGES: existing entries are kept, duplicates are skipped,
	 * nothing is ever removed. An "account" row (and every bare email) is
	 * matched to a registered account by email and verified the same way the
	 * Verify button does — flag, email credential, open checkout blocks
	 * released, any verification hold lifted (held ORDERS are left alone; a
	 * bulk import should not advance order statuses). When no account exists
	 * here for that email, the email itself is verified instead, so the
	 * customer is recognized the moment they use it.
	 */
	public function handle_import_verified() {
		$this->guard();
		check_admin_referer( 'fs_import_verified' );

		$redirect = admin_url( 'admin.php?page=' . self::SLUG . '-verified' );

		if ( empty( $_FILES['fs_verified_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['fs_verified_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_safe_redirect( add_query_arg( 'fs_status', 'nofile', $redirect ) );
			exit;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$fh = fopen( $_FILES['fs_verified_csv']['tmp_name'], 'r' ); // phpcs:ignore
		if ( ! $fh ) {
			wp_safe_redirect( add_query_arg( 'fs_status', 'badfile', $redirect ) );
			exit;
		}

		$counts = $this->import_verified_stream( $fh );
		fclose( $fh ); // phpcs:ignore

		if ( false === $counts ) {
			wp_safe_redirect( add_query_arg( 'fs_status', 'verified_badformat', $redirect ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'fs_status'   => 'verified_imported',
					'fs_accounts' => $counts['accounts'],
					'fs_creds'    => $counts['creds'],
					'fs_skipped'  => $counts['skipped'],
					'fs_noacct'   => $counts['noacct'],
				),
				$redirect
			)
		);
		exit;
	}

	/**
	 * The parse-and-merge core of the verified-customers import, separated
	 * from the HTTP glue so it can be exercised against in-memory streams.
	 *
	 * @param resource $fh Open CSV stream, positioned at the start.
	 * @return array|false Counts (accounts, creds, skipped, noacct), or false
	 *                     when no recognizable format was found.
	 */
	private function import_verified_stream( $fh ) {
		// Column aliases, so our own export, a hand-made sheet, and common
		// spreadsheet headers all map without renaming anything.
		$aliases = array(
			'type'     => array( 'type', 'kind' ),
			'value'    => array( 'value', 'credential' ),
			'email'    => array( 'email', 'customer email', 'customer_email', 'user_email', 'email_norm' ),
			'label'    => array( 'label', 'note', 'name', 'display_name', 'display name' ),
			'name'     => array( 'name', 'display_name', 'display name', 'customer', 'customer name' ),
			'source'   => array( 'source', 'src' ),
			'added_at' => array( 'added_at', 'added', 'at', 'created_at', 'date', 'verified_at' ),
		);

		$header = fgetcsv( $fh );
		$cols   = array();
		foreach ( (array) $header as $i => $h ) {
			$h = strtolower( trim( str_replace( "\xEF\xBB\xBF", '', (string) $h ) ) );
			foreach ( $aliases as $field => $names ) {
				if ( in_array( $h, $names, true ) && ! isset( $cols[ $field ] ) ) {
					$cols[ $field ] = $i;
				}
			}
		}

		$full_format  = isset( $cols['type'], $cols['value'] );
		$email_format = ! $full_format && isset( $cols['email'] );
		$bare_list    = false;

		if ( ! $full_format && ! $email_format ) {
			// No recognizable header. If the first cell is itself an email
			// address, treat the file as a headerless one-column list of
			// verified customers, first row included.
			$first = isset( $header[0] ) ? trim( str_replace( "\xEF\xBB\xBF", '', (string) $header[0] ) ) : '';
			if ( ! is_email( $first ) ) {
				return false;
			}
			$bare_list = true;
			rewind( $fh );
		}

		$cell = function ( $row, $field ) use ( $cols ) {
			return isset( $cols[ $field ], $row[ $cols[ $field ] ] ) ? trim( (string) $row[ $cols[ $field ] ] ) : '';
		};

		$accounts = 0; // Accounts newly flagged verified.
		$creds    = 0; // Credentials newly added.
		$skipped  = 0; // Duplicates / invalid rows.
		$noacct   = 0; // Account rows with no matching account here (email verified instead).

		while ( false !== ( $row = fgetcsv( $fh ) ) ) { // phpcs:ignore
			if ( array( null ) === $row ) {
				continue;
			}
			// Undo the CSV formula-injection guard our exporter applies.
			$row = array_map( array( 'FS_Data', 'csv_unescape_cell' ), $row );

			if ( $bare_list ) {
				$type  = 'account';
				$value = trim( (string) ( $row[0] ?? '' ) );
				$label = '';
				$src   = '';
				$at    = '';
			} elseif ( $email_format ) {
				$type  = 'account';
				$value = $cell( $row, 'email' );
				$label = $cell( $row, 'label' );
				$src   = '';
				$at    = $cell( $row, 'added_at' );
			} else {
				$type  = sanitize_key( $cell( $row, 'type' ) );
				$value = $cell( $row, 'value' );
				$label = $cell( $row, 'label' );
				$src   = sanitize_key( $cell( $row, 'source' ) );
				$at    = $cell( $row, 'added_at' );
			}
			$label = sanitize_text_field( $label );

			if ( '' === $value ) {
				$skipped++;
				continue;
			}

			if ( 'account' === $type ) {
				$email_norm = FS_Normalizer::email( $value );
				// Only real email addresses: bare lists and hand-made sheets
				// carry stray lines, and a junk "email" credential would sit
				// there matching nothing forever.
				if ( '' === $email_norm || ( ! is_email( $value ) && ! is_email( $email_norm ) ) ) {
					$skipped++;
					continue;
				}
				// Account rows carry the customer's name in its own column
				// (our export writes it there); use it when no label was given
				// so the fallback email credential still says who it is.
				if ( '' === $label && ! $bare_list ) {
					$label = sanitize_text_field( $cell( $row, 'name' ) );
				}
				$user = get_user_by( 'email', $value );
				if ( ! $user && $email_norm !== $value ) {
					$user = get_user_by( 'email', $email_norm );
				}
				if ( $user ) {
					$already = ( 'yes' === get_user_meta( $user->ID, '_fs_verified', true ) );
					// Mirror the Verify button: flag + email credential + open
					// blocks released + hold flag lifted. Unlike the button we
					// never touch the held order's status from a bulk import.
					update_user_meta( $user->ID, '_fs_verified', 'yes' );
					$new_cred = FS_Live::verified_add( 'email', $email_norm, '' !== $label ? $label : (string) $user->display_name, $src, $at );
					FS_Data::resolve_checkout_blocks( (int) $user->ID, $email_norm, array() );
					delete_user_meta( $user->ID, '_fs_hold' );
					if ( $already && ! $new_cred ) {
						$skipped++;
					} else {
						if ( ! $already ) {
							$accounts++;
						}
						if ( $new_cred && $already ) {
							$creds++;
						}
					}
				} else {
					$noacct++;
					if ( FS_Live::verified_add( 'email', $email_norm, $label, $src, $at ) ) {
						$creds++;
					} else {
						$skipped++;
					}
				}
				continue;
			}

			if ( in_array( $type, array( 'email', 'address', 'ip' ), true ) ) {
				if ( FS_Live::verified_add( $type, $value, $label, $src, $at ) ) {
					$creds++;
				} else {
					$skipped++;
				}
				continue;
			}

			$skipped++;
		}

		return array(
			'accounts' => $accounts,
			'creds'    => $creds,
			'skipped'  => $skipped,
			'noacct'   => $noacct,
		);
	}

	/* ===================== Report CSV exports ===================== */

	/**
	 * Build an export link for a report, carrying the current filters.
	 *
	 * @param string $report Report key.
	 * @param array  $args   Filter args to carry.
	 * @return string URL.
	 */
	private function export_url( $report, $args = array() ) {
		return wp_nonce_url(
			add_query_arg(
				array_merge( array( 'action' => 'fs_export_report', 'report' => $report ), array_map( 'rawurlencode', array_map( 'strval', $args ) ) ),
				admin_url( 'admin-post.php' )
			),
			'fs_export_report'
		);
	}

	/**
	 * The optional export date range from the request, as inclusive site-local
	 * bounds ready for order_date comparisons. Inputs come from date pickers,
	 * so anything that is not a plain YYYY-MM-DD is discarded rather than
	 * guessed at; the upper bound covers its whole day.
	 *
	 * @return array{0:string,1:string,2:string} from, to, filename suffix.
	 */
	private function export_range_from_request() {
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked by the export handler.
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : '';
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : '';

		// A reversed range is a typo; honouring it would export nothing and
		// look like a bug. Swap instead.
		if ( '' !== $from && '' !== $to && $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}

		$suffix = '';
		if ( '' !== $from || '' !== $to ) {
			$suffix = '-' . ( '' !== $from ? $from : 'start' ) . '-to-' . ( '' !== $to ? $to : 'now' );
		}

		return array(
			'' !== $from ? $from . ' 00:00:00' : '',
			'' !== $to ? $to . ' 23:59:59' : '',
			$suffix,
		);
	}

	/**
	 * A compact export form: a From/To date pair plus a download button,
	 * posting to the report export handler with the current filters carried
	 * along. Its own form on purpose — the list pages already run a GET search
	 * form, and nesting forms is invalid HTML whose submit buttons fire the
	 * outer form.
	 *
	 * @param string $report Report key.
	 * @param array  $args   Filter args to carry (s, sort, dir, email…).
	 * @param string $label  Button label.
	 */
	private function export_range_form( $report, $args = array(), $label = '' ) {
		$label = '' !== $label ? $label : __( 'Export CSV (Excel)', 'fraud-shield' );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fs-filters" style="display:inline-block;margin:.4em 0;">
			<input type="hidden" name="action" value="fs_export_report" />
			<input type="hidden" name="report" value="<?php echo esc_attr( $report ); ?>" />
			<?php wp_nonce_field( 'fs_export_report' ); ?>
			<?php foreach ( $args as $arg_key => $arg_value ) : ?>
				<?php if ( '' !== (string) $arg_value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $arg_key ); ?>" value="<?php echo esc_attr( $arg_value ); ?>" />
				<?php endif; ?>
			<?php endforeach; ?>
			<label><?php esc_html_e( 'Orders from', 'fraud-shield' ); ?> <input type="date" name="from" /></label>
			<label><?php esc_html_e( 'to', 'fraud-shield' ); ?> <input type="date" name="to" /></label>
			<button class="button"><?php echo esc_html( $label ); ?></button>
			<span class="fs-muted"><?php esc_html_e( 'Leave the dates empty to export everything.', 'fraud-shield' ); ?></span>
		</form>
		<?php
	}

	/**
	 * Start a CSV download (Excel-compatible: UTF-8 BOM) and return the handle.
	 *
	 * @param string $filename Base file name (no extension).
	 * @param array  $columns  Header row.
	 * @return resource
	 */
	private function csv_start( $filename, $columns ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore
		fputcsv( $out, $columns );
		return $out;
	}

	/**
	 * fputcsv wrapper for DATA rows: every cell passes through
	 * FS_Data::csv_escape_cell() to neutralize spreadsheet formula injection
	 * (CWE-1236) from customer-controlled values. Use this — never raw
	 * fputcsv — for any row that can contain order/customer data.
	 *
	 * @param resource $handle Output stream.
	 * @param array    $cells  Row cells.
	 */
	private function csv_row( $handle, $cells ) {
		fputcsv( $handle, array_map( array( 'FS_Data', 'csv_escape_cell' ), $cells ) );
	}

	/**
	 * Export the on-screen reports (all matching rows, not just one page) as
	 * CSV: address clusters, IP clusters, repeat customers, new customers, and
	 * the profiles list.
	 */
	public function handle_export_report() {
		$this->guard();
		check_admin_referer( 'fs_export_report' );
		global $wpdb;

		$report = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$sort   = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : '';
		$dir    = ( isset( $_GET['dir'] ) && 'asc' === $_GET['dir'] ) ? 'asc' : 'desc';
		$t      = FS_Data::index_table();
		$batch  = 1000;

		switch ( $report ) {
			case 'addresses':
				$out    = $this->csv_start( 'fraud-shield-risky-addresses', array( 'shipping_address', 'ship_hash', 'distinct_emails', 'distinct_names', 'orders', 'has_chargeback', 'last_order' ) );
				$offset = 0;
				do {
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ship_hash, MAX(ship_text) ship_text, COUNT(DISTINCT email_norm) emails, COUNT(DISTINCT customer_name) names, COUNT(*) orders, MAX(is_chargeback) has_cb, MAX(order_date) last_order FROM {$t} WHERE ship_hash<>'' GROUP BY ship_hash HAVING emails>=2 OR has_cb=1 ORDER BY has_cb DESC, emails DESC, orders DESC LIMIT %d OFFSET %d", $batch, $offset ), ARRAY_A ); // phpcs:ignore
					foreach ( (array) $rows as $r ) {
						$this->csv_row( $out, array( $r['ship_text'], $r['ship_hash'], $r['emails'], $r['names'], $r['orders'], $r['has_cb'] ? 'yes' : 'no', $r['last_order'] ) );
					}
					$offset += $batch;
				} while ( count( (array) $rows ) === $batch );
				break;

			case 'ips':
				$out    = $this->csv_start( 'fraud-shield-risky-ips', array( 'ip', 'ip_location', 'distinct_accounts', 'orders', 'has_chargeback', 'last_order' ) );
				$offset = 0;
				do {
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ip, COUNT(DISTINCT email_norm) emails, COUNT(*) orders, MAX(is_chargeback) has_cb, MAX(order_date) last_order FROM {$t} WHERE ip<>'' GROUP BY ip HAVING emails>=3 OR has_cb=1 ORDER BY has_cb DESC, emails DESC LIMIT %d OFFSET %d", $batch, $offset ), ARRAY_A ); // phpcs:ignore
					foreach ( (array) $rows as $r ) {
						$this->csv_row( $out, array( $r['ip'], $this->ip_location_label( $r['ip'] ), $r['emails'], $r['orders'], $r['has_cb'] ? 'yes' : 'no', $r['last_order'] ) );
					}
					$offset += $batch;
				} while ( count( (array) $rows ) === $batch );
				break;

			case 'repeat':
				list( $from, $to ) = $this->repeat_window_from_request();
				$min    = isset( $_GET['min_orders'] ) ? max( 2, (int) $_GET['min_orders'] ) : 2;
				$out    = $this->csv_start( 'fraud-shield-repeat-customers', array( 'user_id', 'name', 'account_email', 'registered', 'orders_in_period', 'spend_paid', 'chargebacks', 'distinct_ips', 'distinct_addresses', 'latest_payment', 'latest_ip', 'latest_country', 'first_order', 'last_order' ) );
				$offset = 0;
				do {
					$rows = FS_Data::repeat_customers(
						array(
							'from'       => $from,
							'to'         => $to,
							'min_orders' => $min,
							'orderby'    => $sort ? $sort : 'last_order',
							'dir'        => $dir,
							'search'     => $search,
							'limit'      => $batch,
							'offset'     => $offset,
						)
					);
					foreach ( (array) $rows as $r ) {
						$this->csv_row( $out, array( $r['user_id'], $r['customer_name'] ? $r['customer_name'] : $r['display_name'], $r['user_email'], $r['user_registered'], $r['orders'], $r['spend'], $r['chargebacks'], $r['ips'], $r['addresses'], $r['latest_payment'], $r['latest_ip'], $r['latest_country'], $r['first_order'], $r['last_order'] ) );
					}
					$offset += $batch;
				} while ( count( (array) $rows ) === $batch );
				break;

			case 'new':
				$months = isset( $_GET['months'] ) ? max( 1, (int) $_GET['months'] ) : 3;
				$min    = isset( $_GET['min_orders'] ) ? max( 1, (int) $_GET['min_orders'] ) : 2;
				$out    = $this->csv_start( 'fraud-shield-new-customers', array( 'user_id', 'name', 'account_email', 'registered', 'account_age_days', 'orders', 'orders_per_month', 'spend_paid', 'chargebacks', 'distinct_ips', 'distinct_addresses', 'max_risk', 'first_order', 'last_order' ) );
				$offset = 0;
				do {
					$rows = FS_Data::new_customers(
						array(
							'months'     => $months,
							'min_orders' => $min,
							'orderby'    => $sort ? $sort : 'frequency',
							'dir'        => $dir,
							'search'     => $search,
							'limit'      => $batch,
							'offset'     => $offset,
						)
					);
					foreach ( (array) $rows as $r ) {
						$this->csv_row( $out, array( $r['user_id'], $r['customer_name'] ? $r['customer_name'] : $r['display_name'], $r['user_email'], $r['user_registered'], $r['age_days'], $r['orders'], round( (float) $r['orders_per_month'], 2 ), $r['total_spend'], $r['chargebacks'], $r['ips'], $r['addresses'], $r['max_risk'], $r['first_order'], $r['last_order'] ) );
					}
					$offset += $batch;
				} while ( count( (array) $rows ) === $batch );
				break;

			case 'profiles':
				list( $from, $to, $range_suffix ) = $this->export_range_from_request();
				$out    = $this->csv_start( 'fraud-shield-customer-profiles' . $range_suffix, array( 'email', 'name', 'user_id', 'orders', 'paid_orders', 'spend_paid', 'avg_order_value', 'chargebacks', 'distinct_ips', 'distinct_addresses', 'max_risk', 'first_order', 'last_order' ) );
				$offset = 0;
				do {
					$rows = FS_Data::profiles_list(
						array(
							'orderby' => $sort ? $sort : 'last',
							'dir'     => $dir,
							'search'  => $search,
							'from'    => $from,
							'to'      => $to,
							'limit'   => $batch,
							'offset'  => $offset,
						)
					);
					foreach ( (array) $rows as $r ) {
						$this->csv_row( $out, array( $r['email_norm'], $r['customer_name'], $r['user_id'], $r['orders'], $r['paid_orders'], $r['spend'], round( (float) $r['aov'], 2 ), $r['chargebacks'], $r['ips'], $r['addresses'], $r['max_risk'], $r['first_order'], $r['last_order'] ) );
					}
					$offset += $batch;
				} while ( count( (array) $rows ) === $batch );
				break;

			case 'profile_orders':
				// One identity's complete order dossier, straight off the index.
				$email = isset( $_GET['email'] ) ? FS_Normalizer::email( sanitize_text_field( wp_unslash( $_GET['email'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked at the top of this handler.
				if ( '' === $email ) {
					wp_die( esc_html__( 'Missing profile email.', 'fraud-shield' ) );
				}
				list( $from, $to, $range_suffix ) = $this->export_range_from_request();
				$slug   = sanitize_key( str_replace( array( '@', '.' ), '-', $email ) );
				$out    = $this->csv_start( 'fraud-shield-profile-' . $slug . $range_suffix, array( 'order_id', 'order_date', 'status', 'customer_name', 'email', 'phone', 'ip', 'shipping_address', 'ship_city', 'ship_state', 'ship_postcode', 'shipping_country', 'billing_country', 'order_total', 'payment_method', 'shipping_method', 'coupons', 'risk_score', 'risk_level', 'risk_reasons', 'chargeback', 'user_id' ) );
				$offset = 0;
				do {
					$rows = FS_Data::profile_orders( $email, $batch, $offset, $from, $to );
					foreach ( (array) $rows as $r ) {
						$this->csv_row(
							$out,
							array(
								$r['order_id'],
								$r['order_date'],
								$r['status'],
								$r['customer_name'],
								$r['email_norm'],
								$r['phone_norm'],
								$r['ip'],
								$r['ship_text'],
								$r['ship_city'],
								$r['ship_state'],
								$r['ship_postcode'],
								$r['shipping_country'],
								$r['billing_country'],
								$r['order_total'],
								$r['payment_method'],
								$r['ship_method'],
								$r['coupons'],
								$r['risk_score'],
								$r['risk_level'],
								$r['risk_reasons'],
								! empty( $r['is_chargeback'] ) ? 'yes' : 'no',
								$r['user_id'],
							)
						);
					}
					$offset += $batch;
				} while ( count( (array) $rows ) === $batch );
				break;

			case 'idv':
				$idv_result = isset( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked at the top of this handler.
				$out        = $this->csv_start( 'fraud-shield-id-verifications', array( 'email', 'user_id', 'outcome', 'didit_status', 'document_name', 'expected_name', 'name_match', 'document_type', 'session_id', 'order_id', 'started', 'last_update' ) );
				$offset     = 0;
				do {
					$rows = FS_Data::idv_sessions_list(
						array(
							'orderby' => $sort ? $sort : 'updated',
							'dir'     => $dir,
							'search'  => $search,
							'result'  => $idv_result,
							'limit'   => $batch,
							'offset'  => $offset,
						)
					);
					foreach ( (array) $rows as $r ) {
						$this->csv_row(
							$out,
							array(
								$r['email_norm'],
								$r['user_id'],
								FS_IDV::display_outcome( $r ),
								$r['status'],
								$r['full_name'],
								$r['expected_name'],
								$r['name_match'],
								$r['doc_type'],
								$r['session_id'],
								$r['order_id'],
								$r['created_at'],
								$r['updated_at'],
							)
						);
					}
					$offset += $batch;
				} while ( count( (array) $rows ) === $batch );
				break;

			case 'all_addresses':
			case 'all_ips':
				$type   = ( 'all_ips' === $report ) ? 'ip' : 'address';
				$is_ip  = ( 'ip' === $type );
				$out    = $this->csv_start(
					$is_ip ? 'fraud-shield-all-ips' : 'fraud-shield-all-addresses',
					$is_ip
						? array( 'ip', 'ip_location', 'accounts', 'names', 'orders', 'paid_orders', 'spend_paid', 'chargebacks', 'distinct_addresses', 'max_risk', 'first_order', 'last_order', 'blocked' )
						: array( 'shipping_address', 'ship_hash', 'emails', 'names', 'orders', 'paid_orders', 'spend_paid', 'chargebacks', 'distinct_ips', 'max_risk', 'first_order', 'last_order', 'blocked' )
				);
				$offset = 0;
				do {
					$rows = FS_Data::clusters_list(
						$type,
						array(
							'orderby' => $sort ? $sort : 'emails',
							'dir'     => $dir,
							'search'  => $search,
							'limit'   => $batch,
							'offset'  => $offset,
						)
					);
					$blocked = FS_Blocklist::blocked_values( $type, wp_list_pluck( (array) $rows, 'cluster_key' ) );
					foreach ( (array) $rows as $r ) {
						$is_blocked = in_array( $r['cluster_key'], $blocked, true ) ? 'yes' : 'no';
						if ( $is_ip ) {
							$this->csv_row( $out, array( $r['cluster_key'], $this->ip_location_label( $r['cluster_key'] ), $r['emails'], $r['names'], $r['orders'], $r['paid_orders'], $r['spend'], $r['chargebacks'], $r['other_dim'], $r['max_risk'], $r['first_order'], $r['last_order'], $is_blocked ) );
						} else {
							$this->csv_row( $out, array( $r['ship_text'], $r['cluster_key'], $r['emails'], $r['names'], $r['orders'], $r['paid_orders'], $r['spend'], $r['chargebacks'], $r['other_dim'], $r['max_risk'], $r['first_order'], $r['last_order'], $is_blocked ) );
						}
					}
					$offset += $batch;
				} while ( count( (array) $rows ) === $batch );
				break;

			case 'geo_state':
			case 'geo_city':
			case 'geo_zip':
			case 'geo_street':
				$level  = substr( $report, 4 ); // state | city | zip | street.
				$months = isset( $_GET['months'] ) ? max( 0, (int) $_GET['months'] ) : 0;
				$min    = isset( $_GET['min_orders'] ) ? max( 1, (int) $_GET['min_orders'] ) : 5;
				$cols   = array( 'country', 'state' );
				if ( 'city' === $level ) {
					$cols[] = 'city';
				} elseif ( 'zip' === $level ) {
					$cols[] = 'zip';
				} elseif ( 'street' === $level ) {
					$cols[] = 'city';
					$cols[] = 'street';
				}
				$cols   = array_merge( $cols, array( 'orders', 'customers', 'avg_score', 'high_block', 'chargebacks', 'cb_rate_pct', 'last_order' ) );
				$out    = $this->csv_start( 'fraud-shield-geo-' . $level, $cols );
				$offset = 0;
				do {
					$rows = FS_Data::geo_rollup(
						array(
							'level'      => $level,
							'search'     => $search,
							'months'     => $months,
							'min_orders' => $min,
							'orderby'    => $sort ? $sort : 'avg_score',
							'order'      => $dir,
							'limit'      => $batch,
							'offset'     => $offset,
						)
					);
					foreach ( (array) $rows as $r ) {
						$line = array( $r['shipping_country'], $r['ship_state'] );
						if ( 'city' === $level ) {
							$line[] = $r['ship_city'];
						} elseif ( 'zip' === $level ) {
							$line[] = $r['ship_postcode'];
						} elseif ( 'street' === $level ) {
							$line[] = $r['ship_city'];
							$line[] = $r['ship_street'];
						}
						$line = array_merge( $line, array( $r['orders'], $r['customers'], $r['avg_score'], $r['high_ct'], $r['cbs'], $r['cb_rate'], $r['last_order'] ) );
						$this->csv_row( $out, $line );
					}
					$offset += $batch;
				} while ( count( (array) $rows ) === $batch );
				break;

			case 'cancels':
				// One row per link, so a spreadsheet can pivot on the shared
				// credential and on the block card it leads to; an order with
				// no decodable links still exports once with its summary.
				$out    = $this->csv_start( 'fraud-shield-auto-cancelled', array( 'cancelled_at', 'order_id', 'order_status', 'customer_name', 'email', 'user_id', 'order_total', 'currency', 'shared_credential', 'shared_value', 'where_seen', 'link', 'blocked_entry_type', 'blocked_entry', 'block_card', 'linked_names', 'linked_emails', 'linked_accounts', 'attempts', 'last_attempt_at', 'summary' ) );
				$offset = 0;
				do {
					$rows = FS_Data::linked_cancels_list(
						array(
							'search' => $search,
							'limit'  => $batch,
							'offset' => $offset,
						)
					);
					foreach ( (array) $rows as $r ) {
						$order  = wc_get_order( (int) $r['order_id'] );
						$status = $order ? $order->get_status() : 'deleted';
						$head   = array( $r['cancelled_at'], $r['order_id'], $status, $r['customer_name'], $r['email_norm'], $r['user_id'], $r['order_total'], $r['currency'] );
						$tail   = array( $r['attempts'], $r['last_attempt_at'], $r['summary'] );
						$links  = (array) $r['links'];
						if ( empty( $links ) ) {
							$this->csv_row( $out, array_merge( $head, array( '', '', '', '', '', '', '', '', '', '' ), $tail ) );
							continue;
						}
						foreach ( $links as $l ) {
							$ids      = isset( $l['identities'] ) && is_array( $l['identities'] ) ? $l['identities'] : array();
							$names    = array();
							$emails   = array();
							$accounts = array();
							foreach ( $ids as $id ) {
								if ( '' !== trim( (string) ( $id['name'] ?? '' ) ) ) {
									$names[] = trim( (string) $id['name'] );
								}
								if ( '' !== (string) ( $id['email'] ?? '' ) ) {
									$emails[] = (string) $id['email'];
								}
								if ( (int) ( $id['user_id'] ?? 0 ) > 0 ) {
									$accounts[] = (int) $id['user_id'];
								}
							}
							$this->csv_row(
								$out,
								array_merge(
									$head,
									array(
										(string) ( $l['via'] ?? '' ),
										(string) ( $l['value'] ?? '' ),
										! empty( $l['on_order'] ) ? 'this order' : 'earlier order',
										1 === (int) ( $l['degree'] ?? 1 ) ? 'credential is blocked' : 'shared with a blocked identity',
										(string) ( $l['blocked_type'] ?? '' ),
										(string) ( $l['blocked_value'] ?? '' ),
										(string) ( $l['action_title'] ?? '' ),
										implode( '; ', array_unique( $names ) ),
										implode( '; ', array_unique( $emails ) ),
										implode( '; ', array_unique( $accounts ) ),
									),
									$tail
								)
							);
						}
					}
					$offset += $batch;
				} while ( count( (array) $rows ) === $batch );
				break;

			case 'hv':
				// The High-Value Review queue as shown (window, filter, search,
				// sort), every page: the order, the customer and their history,
				// where it ships and from where, the risk, and the verdict.
				$hv_win  = FS_Data::hv_window( isset( $_GET['win'] ) ? sanitize_key( wp_unslash( $_GET['win'] ) ) : 'today' );
				$hv_show = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : 'unreviewed';
				$hv_show = in_array( $hv_show, array( 'unreviewed', 'reviewed', 'all' ), true ) ? $hv_show : 'unreviewed';
				$out     = $this->csv_start( 'fraud-shield-high-value-' . $hv_win['key'], array( 'placed', 'order_id', 'order_status', 'customer_name', 'email', 'user_id', 'phone', 'order_total', 'payment_method', 'ship_to', 'ship_country', 'ip', 'risk_level', 'risk_score', 'risk_reasons', 'customer_orders', 'customer_chargebacks', 'review', 'reviewed_by', 'reviewed_at', 'review_note', 'reviews' ) );
				$offset  = 0;
				do {
					$rows = FS_Data::hv_queue_list(
						array(
							'amount'       => FS_Live::hv_review_amount(),
							'from'         => $hv_win['from'],
							'to'           => $hv_win['to'],
							'show'         => $hv_show,
							'include_dead' => ! empty( $_GET['dead'] ),
							'search'       => $search,
							'orderby'      => $sort ? $sort : 'date',
							'dir'          => $dir,
							'limit'        => 500,
							'offset'       => $offset,
						)
					);
					$facts = FS_Data::hv_customer_facts( wp_list_pluck( (array) $rows, 'email_norm' ) );
					foreach ( (array) $rows as $r ) {
						$reasons = json_decode( (string) $r['risk_reasons'], true );
						$fact    = $facts[ (string) $r['email_norm'] ] ?? array( 'orders' => 0, 'chargebacks' => 0 );
						$this->csv_row(
							$out,
							array(
								$r['order_date'],
								$r['order_id'],
								$r['status'],
								$r['customer_name'],
								$r['email_norm'],
								$r['user_id'],
								$r['phone_norm'],
								$r['order_total'],
								$r['payment_method'],
								$r['ship_text'],
								$r['shipping_country'],
								$r['ip'],
								$r['risk_level'],
								$r['risk_score'],
								is_array( $reasons ) ? implode( '; ', array_map( 'strval', $reasons ) ) : '',
								$fact['orders'],
								$fact['chargebacks'],
								! empty( $r['review_id'] ) ? (string) $r['verdict'] : 'awaiting review',
								! empty( $r['review_id'] ) ? FS_Data::hv_reviewer_name( (int) $r['reviewer_id'], (string) $r['reviewer_name'] ) : '',
								! empty( $r['review_id'] ) ? (string) $r['reviewed_at'] : '',
								(string) $r['review_note'],
								(int) $r['review_count'],
							)
						);
					}
					$offset += 500;
				} while ( count( (array) $rows ) === 500 );
				break;

			case 'hv_reviews':
				// The review log for the window: who decided what, on which
				// order, and what it did to the order.
				$hv_win = FS_Data::hv_window( isset( $_GET['win'] ) ? sanitize_key( wp_unslash( $_GET['win'] ) ) : 'today' );
				$out    = $this->csv_start( 'fraud-shield-high-value-reviews-' . $hv_win['key'], array( 'reviewed_at', 'reviewer', 'reviewer_user_id', 'verdict', 'order_id', 'customer_name', 'email', 'user_id', 'order_total', 'currency', 'status_before', 'status_after', 'note', 'recorded_from' ) );
				$offset = 0;
				do {
					$rows = FS_Data::hv_reviews_list(
						array(
							'from'        => $hv_win['from'],
							'to'          => $hv_win['to'],
							'reviewer_id' => ( isset( $_GET['reviewer'] ) && '' !== $_GET['reviewer'] ) ? (int) $_GET['reviewer'] : null,
							'limit'       => 500,
							'offset'      => $offset,
						)
					);
					foreach ( (array) $rows as $r ) {
						$this->csv_row( $out, array( $r['reviewed_at'], FS_Data::hv_reviewer_name( (int) $r['reviewer_id'], (string) $r['reviewer_name'] ), $r['reviewer_id'], $r['verdict'], $r['order_id'], $r['customer_name'], $r['email_norm'], $r['user_id'], $r['order_total'], $r['currency'], $r['status_before'], $r['status_after'], $r['note'], $r['source'] ) );
					}
					$offset += 500;
				} while ( count( (array) $rows ) === 500 );
				break;

			case 'protection':
				// The annual-protection forecast, one row per blocked customer with
				// chargeback history, with every input to the arithmetic so the
				// figure can be audited in a spreadsheet.
				$proj = FS_Insights::projection();
				$out  = $this->csv_start( 'fraud-shield-annual-protection', array( 'customer_name', 'email', 'user_id', 'orders', 'chargebacks', 'charged_back_amount', 'recovered', 'net_loss_after_ratio', 'first_order', 'last_order', 'first_chargeback', 'last_chargeback', 'months_observed', 'months_used', 'annualization_factor', 'projected_annual_loss', 'projected_annual_fees', 'confidence' ) );
				foreach ( $proj['rows'] as $r ) {
					$this->csv_row( $out, array( $r['customer_name'], $r['email_norm'], $r['user_id'], $r['orders'], $r['cb_orders'], $r['cb_gross'], $r['recovered'], round( $r['net'], 2 ), $r['first_order'], $r['last_order'], $r['first_cb'], $r['last_cb'], round( $r['span_months'], 2 ), round( $r['window_months'], 2 ), round( $r['factor'], 3 ), round( $r['annual'], 2 ), null === $r['fees_annual'] ? '' : round( $r['fees_annual'], 2 ), $r['confidence'] ) );
				}
				break;

			case 'network':
				$out    = $this->csv_start( 'fraud-shield-customer-network', array( 'customer_name', 'email', 'user_id', 'blocked', 'orders', 'paid_orders', 'spend_paid', 'chargebacks', 'chargeback_amount', 'max_risk', 'phones', 'ips', 'addresses', 'connections', 'strong_connections', 'strongest_link', 'linked_to_blocked_via', 'linked_to_blocked_strength', 'linked_to_blocked_customer', 'first_order', 'last_order' ) );
				$offset = 0;
				$page   = 100;
				do {
					$rows = FS_Insights::network_list(
						array(
							'search'  => $search,
							'orderby' => $sort ? $sort : 'risk',
							'dir'     => $dir,
							'limit'   => $page,
							'offset'  => $offset,
						)
					);
					$conn = FS_Insights::connections_batch( wp_list_pluck( (array) $rows, 'email_norm' ) );
					foreach ( (array) $rows as $r ) {
						$c = $conn[ (string) $r['email_norm'] ] ?? array();
						$this->csv_row( $out, array( $r['customer_name'], $r['email_norm'], $r['user_id'], ! empty( $r['blocked'] ) ? 'yes' : 'no', $r['orders'], $r['paid_orders'], $r['spend'], $r['chargebacks'], $r['cb_amount'], $r['max_risk'], $r['phones'], $r['ips'], $r['addresses'], (int) ( $c['connections'] ?? 0 ), (int) ( $c['strong'] ?? 0 ), (string) ( $c['strongest'] ?? '' ), (string) ( $c['blocked_via'] ?? '' ), (string) ( $c['blocked_strength'] ?? '' ), (string) ( $c['blocked_with'] ?? '' ), $r['first_order'], $r['last_order'] ) );
					}
					$offset += $page;
				} while ( count( (array) $rows ) === $page );
				break;

			default:
				wp_die( esc_html__( 'Unknown report.', 'fraud-shield' ) );
		}

		fclose( $out ); // phpcs:ignore
		exit;
	}

	/**
	 * Resolve the repeat-customers window (from/to) from request args.
	 * Shared by the report page and its CSV export.
	 *
	 * @return array [from, to] MySQL datetimes.
	 */
	private function repeat_window_from_request() {
		$period  = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : '1m';  // phpcs:ignore WordPress.Security.NonceVerification
		$from_in = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$to_in   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';     // phpcs:ignore WordPress.Security.NonceVerification
		$now     = time();

		if ( 'custom' === $period && ( '' !== $from_in || '' !== $to_in ) ) {
			$from = $from_in ? gmdate( 'Y-m-d 00:00:00', strtotime( $from_in ) ) : '1970-01-01 00:00:00';
			$to   = $to_in ? gmdate( 'Y-m-d 23:59:59', strtotime( $to_in ) ) : gmdate( 'Y-m-d H:i:s', $now );
		} else {
			$months = ( '3m' === $period ) ? 3 : ( ( '2m' === $period ) ? 2 : 1 );
			$from   = gmdate( 'Y-m-d H:i:s', $now - $months * 30 * DAY_IN_SECONDS );
			$to     = gmdate( 'Y-m-d H:i:s', $now );
		}
		return array( $from, $to );
	}

	/**
	 * Row class for an order based on its payment outcome: green when the
	 * payment succeeded, red when it failed / was reversed, neutral otherwise.
	 *
	 * @param string $status        Order status (no wc- prefix).
	 * @param int    $is_chargeback Chargeback flag.
	 * @return string
	 */
	private function payment_row_class( $status, $is_chargeback = 0 ) {
		if ( $is_chargeback ) {
			return 'fs-pay-bad';
		}
		if ( in_array( (string) $status, FS_Data::paid_statuses(), true ) ) {
			return 'fs-pay-paid';
		}
		if ( in_array( (string) $status, array( 'failed', 'cancelled', 'refunded', 'charged-back' ), true ) ) {
			return 'fs-pay-bad';
		}
		return '';
	}

	private function render_orders_table( $rows ) {
		?>
		<p class="fs-legend">
			<span class="fs-legend-swatch fs-legend-paid"></span> <?php esc_html_e( 'Payment successful (processing / completed)', 'fraud-shield' ); ?>
			&nbsp;&nbsp;<span class="fs-legend-swatch fs-legend-bad"></span> <?php esc_html_e( 'Failed / cancelled / refunded / charged back', 'fraud-shield' ); ?>
		</p>
		<table class="wp-list-table widefat striped fs-detail-table">
			<thead><tr>
				<th></th>
				<th><?php esc_html_e( 'Order', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Date', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Status', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Email', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Phone', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Payment', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Total', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'IP / location', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Ship to', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Risk', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'CB', 'fraud-shield' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="13"><?php esc_html_e( 'No orders found. Run a full scan from the dashboard.', 'fraud-shield' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $r ) : ?>
					<?php
					$oid  = (int) $r['order_id'];
					$ship = ( '' !== (string) $r['ship_text'] ) ? $r['ship_text'] : $r['shipping_country'];
					?>
					<tr class="<?php echo esc_attr( $this->payment_row_class( $r['status'], (int) $r['is_chargeback'] ) ); ?>">
						<td><button type="button" class="button button-small fs-expand" data-order="<?php echo esc_attr( $oid ); ?>" data-label-open="<?php esc_attr_e( '▸ Details', 'fraud-shield' ); ?>" data-label-close="<?php esc_attr_e( '▾ Hide', 'fraud-shield' ); ?>"><?php esc_html_e( '▸ Details', 'fraud-shield' ); ?></button></td>
						<td><a href="<?php echo esc_url( $this->order_edit_url( $oid ) ); ?>">#<?php echo esc_html( $oid ); ?></a></td>
						<td><?php echo esc_html( mysql2date( 'Y-m-d', $r['order_date'] ) ); ?></td>
						<td><?php echo esc_html( function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $r['status'] ) : $r['status'] ); ?></td>
						<td><?php echo esc_html( $r['customer_name'] ); ?></td>
						<td><?php echo '' !== (string) $r['email_norm'] ? '<a href="' . esc_url( $this->profile_url( $r['email_norm'] ) ) . '">' . esc_html( $r['email_norm'] ) . '</a>' : '—'; ?></td>
						<td><?php echo esc_html( $r['phone_norm'] ); ?></td>
						<td><?php echo esc_html( ! empty( $r['payment_method'] ) ? $r['payment_method'] : '—' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $r['order_total'], 2 ) ); ?></td>
						<td>
							<?php
							echo esc_html( $r['ip'] ? $r['ip'] : '—' );
							$fs_loc = $this->ip_location_label( $r['ip'] );
							if ( '' !== $fs_loc ) {
								echo '<br/><span class="fs-muted">' . esc_html( $fs_loc ) . '</span>';
							}
							?>
						</td>
						<td class="fs-addr"><?php echo esc_html( $ship ); ?></td>
						<td><?php echo $this->level_pill( $r['risk_level'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo (int) $r['risk_score']; ?></td>
						<td><?php echo $r['is_chargeback'] ? '<span class="fs-pill fs-pill-red">' . esc_html__( 'CB', 'fraud-shield' ) . '</span>' : '—'; ?></td>
					</tr>
					<tr class="fs-detail-row" id="fs-detail-<?php echo esc_attr( $oid ); ?>" style="display:none;">
						<td colspan="13"><div class="fs-detail-body"></div></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * A sortable column header. A plain click sets this column as the PRIMARY
	 * sort (toggling asc/desc) and clears any secondary. Ctrl/Cmd+click (handled
	 * in admin.js) keeps the current primary and adds this column as a SECONDARY
	 * sort — e.g. "newest, then by spend". The secondary column shows a small "2".
	 *
	 * @param string $label     Column label.
	 * @param string $key       Sort key.
	 * @param string $cur_sort  Current primary sort key.
	 * @param string $cur_dir   Current primary direction (asc|desc).
	 * @param array  $base_args Base URL args for the page.
	 * @param string $cur_sort2 Current secondary sort key.
	 * @param string $cur_dir2  Current secondary direction.
	 */
	private function sort_th( $label, $key, $cur_sort, $cur_dir, $base_args, $cur_sort2 = null, $cur_dir2 = null ) {
		// Fall back to the current page's secondary sort (set in each page) so the
		// existing sort_th call sites don't all need editing.
		if ( null === $cur_sort2 ) {
			$cur_sort2 = $this->cur_sort2;
		}
		if ( null === $cur_dir2 ) {
			$cur_dir2 = $this->cur_dir2;
		}
		$is_primary   = ( $key === $cur_sort );
		$is_secondary = ( '' !== $cur_sort2 && $key === $cur_sort2 );
		$next_dir     = ( $is_primary && 'desc' === $cur_dir ) ? 'asc' : 'desc';

		// Primary link (no ctrl): sort by this column, drop the secondary.
		$url = add_query_arg( array_merge( $base_args, array( 'sort' => $key, 'dir' => $next_dir ) ), admin_url( 'admin.php' ) );

		// Ctrl+click target (JS uses this): keep current primary, add this as
		// secondary (toggle its direction if it is already the secondary).
		$next_dir2 = ( $is_secondary && 'desc' === $cur_dir2 ) ? 'asc' : 'desc';
		$url2      = add_query_arg(
			array_merge(
				$base_args,
				array(
					'sort'  => '' !== $cur_sort ? $cur_sort : $key,
					'dir'   => '' !== $cur_sort ? $cur_dir : $next_dir,
					'sort2' => $key,
					'dir2'  => $next_dir2,
				)
			),
			admin_url( 'admin.php' )
		);

		$arrow = '';
		if ( $is_primary ) {
			$arrow = ' ' . ( 'asc' === $cur_dir ? '▲' : '▼' );
		} elseif ( $is_secondary ) {
			$arrow = ' ' . ( 'asc' === $cur_dir2 ? '▲' : '▼' ) . '²';
		}

		echo '<th class="fs-sortable' . ( $is_primary || $is_secondary ? ' fs-sorted' : '' ) . '">'
			. '<a href="' . esc_url( $url ) . '" class="fs-sort-link" data-fs-sort2="' . esc_url( $url2 ) . '">'
			. esc_html( $label . $arrow ) . '</a></th>';
	}

	/**
	 * Read the secondary-sort request parameters (shared by the sortable pages).
	 *
	 * @return array [ sort2, dir2 ].
	 */
	private function sort2_from_request() {
		$sort2 = isset( $_GET['sort2'] ) ? sanitize_key( wp_unslash( $_GET['sort2'] ) ) : '';       // phpcs:ignore WordPress.Security.NonceVerification
		$dir2  = ( isset( $_GET['dir2'] ) && 'asc' === $_GET['dir2'] ) ? 'asc' : 'desc';            // phpcs:ignore WordPress.Security.NonceVerification
		return array( $sort2, $dir2 );
	}

	/**
	 * A per-account (email) summary table for a cluster: name, phone, order and
	 * spend totals, first/last order, registration, latest payment, profile link.
	 *
	 * @param array $emails Rows from FS_Data::cluster_emails().
	 */
	private function render_accounts_table( $emails ) {
		if ( empty( $emails ) ) {
			return;
		}
		?>
		<table class="wp-list-table widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Email', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Name', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Phone', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Orders', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Spend (paid)', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'CB', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Registered', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'First order', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Last order', 'fraud-shield' ); ?></th>
				<th><?php esc_html_e( 'Latest payment', 'fraud-shield' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $emails as $e ) : ?>
				<?php
				$uid  = (int) $e['user_id'];
				$reg  = '';
				if ( $uid > 0 ) {
					$u = get_userdata( $uid );
					if ( $u && ! empty( $u->user_registered ) ) {
						$reg = mysql2date( 'Y-m-d', $u->user_registered );
					}
				}
				$profile = $this->profile_url( $e['email_norm'] );
				?>
				<tr<?php echo ( (int) $e['chargebacks'] > 0 ) ? ' class="fs-row-flag"' : ''; ?>>
					<td><a href="<?php echo esc_url( $profile ); ?>"><?php echo esc_html( $e['email_norm'] ); ?></a></td>
					<td><?php echo esc_html( $e['customer_name'] ); ?></td>
					<td><?php echo esc_html( $e['phone_norm'] ); ?></td>
					<td><strong><?php echo (int) $e['orders']; ?></strong></td>
					<td><?php echo wp_kses_post( wc_price( (float) $e['spend'] ) ); ?></td>
					<td><?php echo (int) $e['chargebacks'] ? '<span class="fs-pill fs-pill-red">' . (int) $e['chargebacks'] . '</span>' : '—'; ?></td>
					<td><?php echo esc_html( $reg ? $reg : '—' ); ?></td>
					<td><?php echo esc_html( mysql2date( 'Y-m-d', $e['first_order'] ) ); ?></td>
					<td><?php echo esc_html( mysql2date( 'Y-m-d', $e['last_order'] ) ); ?></td>
					<td><?php echo esc_html( ! empty( $e['latest_payment'] ) ? $e['latest_payment'] : '—' ); ?></td>
					<td><?php echo $profile ? '<a class="button button-small" href="' . esc_url( $profile ) . '">' . esc_html__( 'Profile', 'fraud-shield' ) . '</a>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the drill-down view for one address or IP cluster: a summary plus a
	 * paginated table of every order, each expandable to its full detail.
	 *
	 * @param string $type 'address' | 'ip'.
	 * @param string $key  ship_hash or IP value.
	 */
	private function render_cluster_detail( $type, $key ) {
		$is_ip   = ( 'ip' === $type );
		$summary = FS_Data::cluster_summary( $type, $key );
		$total   = (int) ( $summary['orders'] ?? 0 );

		$per    = 50;
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$offset = ( $paged - 1 ) * $per;
		$rows   = FS_Data::cluster_orders( $type, $key, $per, $offset );
		$pages  = max( 1, (int) ceil( $total / $per ) );

		$heading    = $is_ip ? __( 'IP address', 'fraud-shield' ) : __( 'Shipping address', 'fraud-shield' );
		$title      = $is_ip ? $key : ( ! empty( $summary['ship_text'] ) ? $summary['ship_text'] : substr( $key, 0, 16 ) . '…' );
		$kind       = $is_ip ? 'ip' : 'address';
		$amap       = FS_Blocklist::actions_for_targets( $kind, array( $key ) );
		$action     = isset( $amap[ $key ] ) ? $amap[ $key ] : null;
		$is_blocked = FS_Blocklist::is_blocked( $kind, $key );
		$block_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=fs_block_cluster&type=' . $kind . '&key=' . rawurlencode( $key ) ),
			'fs_block_cluster'
		);
		?>
		<div class="wrap fs-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $heading ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>" class="page-title-action">&larr; <?php esc_html_e( 'Back to dashboard', 'fraud-shield' ); ?></a>
			<?php
			if ( $action ) :
				$fs_count   = (int) $action['entries'];
				$fs_confirm = sprintf(
					/* translators: %d: number of credentials that will be removed */
					_n(
						'Unblock this? The %d credential created by this block will be removed from the blocklist.',
						'Unblock this? All %d credentials created by this block will be removed from the blocklist.',
						max( 1, $fs_count ),
						'fraud-shield'
					),
					$fs_count
				);
				?>
				<a href="<?php echo esc_url( $block_url ); ?>" class="page-title-action fs-blocked-btn" title="<?php esc_attr_e( 'On the blocklist. Click to unblock — removes every credential this block created.', 'fraud-shield' ); ?>" onclick="return confirm('<?php echo esc_js( $fs_confirm ); ?>');">&#10004; <?php esc_html_e( 'Blocked — click to unblock', 'fraud-shield' ); ?></a>
			<?php elseif ( $is_blocked ) : ?>
				<a href="<?php echo esc_url( $block_url ); ?>" class="page-title-action fs-blocked-btn" title="<?php esc_attr_e( 'Blocked before v1.6 — click to rebuild as a removable grouped block.', 'fraud-shield' ); ?>">&#10004; <?php esc_html_e( 'Blocked (legacy)', 'fraud-shield' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( $block_url ); ?>" class="page-title-action" onclick="return confirm('<?php echo esc_js( __( 'Block this and EVERY associated credential (emails, phones, IPs, accounts)? Matching checkouts will be rejected in Enforce mode.', 'fraud-shield' ) ); ?>');"><?php echo esc_html( $is_ip ? __( 'Block this IP + all credentials', 'fraud-shield' ) : __( 'Block this address + all credentials', 'fraud-shield' ) ); ?></a>
			<?php endif; ?>
			<?php
			if ( ! $is_ip ) {
				echo $this->place_lock_buttons( $summary, (string) $key ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
			<h2 class="fs-detail-title"><?php echo esc_html( $title ); ?></h2>
			<?php
			if ( $is_ip ) {
				$fs_ip_loc = $this->ip_location_label( $key );
				if ( '' !== $fs_ip_loc ) {
					echo '<p class="description">' . esc_html__( 'IP location:', 'fraud-shield' ) . ' <strong>' . esc_html( $fs_ip_loc ) . '</strong></p>';
				}
			}
			$this->maybe_notice();
			if ( ! $is_ip ) {
				$this->render_area_ban_panel( $summary, (string) $key );
				$this->render_rewrite_panel( $summary, (string) $key );
			}
			?>

			<div class="fs-cards">
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $total ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Orders', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) ( $summary['emails'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Distinct emails', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) ( $summary['names'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Distinct names', 'fraud-shield' ); ?></div></div>
				<?php if ( $is_ip ) : ?>
					<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) ( $summary['addresses'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Shipping addresses', 'fraud-shield' ); ?></div></div>
				<?php else : ?>
					<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) ( $summary['ips'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Distinct IPs', 'fraud-shield' ); ?></div></div>
				<?php endif; ?>
				<div class="fs-card fs-card-red"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) ( $summary['chargebacks'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Chargebacks', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) ( $summary['paid_orders'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Paid orders', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-amber"><div class="fs-num"><?php echo wp_kses_post( wc_price( (float) ( $summary['total_value'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Total value (paid)', 'fraud-shield' ); ?></div></div>
			</div>

			<?php if ( ! empty( $summary['first_order'] ) ) : ?>
				<p class="description">
				<?php
				printf(
					/* translators: 1: first order date, 2: last order date, 3: number of shipping countries */
					esc_html__( 'Active from %1$s to %2$s · ships to %3$s distinct countries', 'fraud-shield' ),
					esc_html( mysql2date( 'M j, Y', $summary['first_order'] ) ),
					esc_html( mysql2date( 'M j, Y', $summary['last_order'] ) ),
					esc_html( number_format_i18n( (int) ( $summary['countries'] ?? 0 ) ) )
				);
				?>
				</p>
			<?php endif; ?>

			<h2 class="fs-section-title"><?php esc_html_e( 'Accounts / emails at this location', 'fraud-shield' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Every distinct email seen here, with name, phone, order and spend totals, when they registered, and their most recent payment method. Click Profile for the full customer history.', 'fraud-shield' ); ?></p>
			<?php $this->render_accounts_table( FS_Data::cluster_emails( $type, $key ) ); ?>

			<h2 class="fs-section-title"><?php esc_html_e( 'Payment, coupons & devices', 'fraud-shield' ); ?></h2>
			<div class="fs-detail-grid">
				<div class="fs-detail-col">
					<h4><?php esc_html_e( 'Payment methods', 'fraud-shield' ); ?></h4>
					<ul class="fs-badge-list">
						<?php
						$fs_pm = FS_Data::cluster_distinct( $type, $key, 'payment_method' );
						if ( empty( $fs_pm ) ) {
							echo '<li>—</li>';
						}
						foreach ( $fs_pm as $p ) {
							echo '<li>' . esc_html( $p['v'] ) . ' <span class="fs-muted">× ' . (int) $p['c'] . '</span></li>';
						}
						?>
					</ul>
					<h4><?php esc_html_e( 'Coupon codes used', 'fraud-shield' ); ?></h4>
					<ul class="fs-badge-list">
						<?php
						$fs_cp = FS_Data::cluster_coupons( $type, $key );
						if ( empty( $fs_cp ) ) {
							echo '<li>' . esc_html__( 'None recorded', 'fraud-shield' ) . '</li>';
						}
						foreach ( $fs_cp as $code => $c ) {
							echo '<li><code>' . esc_html( $code ) . '</code> <span class="fs-muted">× ' . (int) $c . '</span></li>';
						}
						?>
					</ul>
				</div>
				<div class="fs-detail-col">
					<h4><?php esc_html_e( 'Device signatures (from browser user-agent)', 'fraud-shield' ); ?></h4>
					<ul class="fs-badge-list">
						<?php
						$fs_dev = FS_Data::cluster_distinct( $type, $key, 'user_agent' );
						if ( empty( $fs_dev ) ) {
							echo '<li>' . esc_html__( 'None recorded — re-index to backfill from order data', 'fraud-shield' ) . '</li>';
						}
						foreach ( $fs_dev as $d ) {
							echo '<li title="' . esc_attr( $d['v'] ) . '">' . esc_html( $this->device_label( $d['v'] ) ) . ' <span class="fs-muted">× ' . (int) $d['c'] . '</span> <span class="fs-muted">(' . esc_html( hash( 'crc32b', (string) $d['v'] ) ) . ')</span></li>';
						}
						?>
					</ul>
					<p class="description"><?php esc_html_e( 'One device signature across many accounts here means one person running them all.', 'fraud-shield' ); ?></p>
				</div>
			</div>

			<h2 class="fs-section-title"><?php esc_html_e( 'Orders (newest first)', 'fraud-shield' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Click Details on any order to see the items, full billing/shipping addresses, payment method, IP location, and account.', 'fraud-shield' ); ?></p>
			<?php $this->render_orders_table( $rows ); ?>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
				<?php
				$base = add_query_arg(
					array(
						'page'    => self::SLUG,
						'fs_view' => $type,
						'fs_key'  => $key,
					),
					admin_url( 'admin.php' )
				);
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => $base . '%_%',
							'format'    => '&paged=%#%',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The destination-ban buttons for an address detail page (v1.31): "Block
	 * this street" and "Ban this building", each a toggle. Destination locks
	 * one and two levels finer than a city: they refuse every checkout shipping
	 * to the place and never touch the identities behind it (unlike the
	 * address block beside them).
	 *
	 *  - The street ban is pre-filled with every city name and ZIP the index
	 *    has seen this road under in this state — the road's boundary — so an
	 *    order typed under any of them meets the lock, and the identically
	 *    named road in another county does not.
	 *  - The building ban is one house number on the street, anywhere in the
	 *    state: every unit, mailbox, forwarding account, business and recipient.
	 *
	 * Needs the address's geo columns; an address whose orders predate the geo
	 * backfill shows a hint instead of buttons.
	 *
	 * @param array  $summary Output of FS_Data::cluster_summary( 'address', … ).
	 * @param string $key     The address's ship_hash (to read one order for the house number).
	 * @return string HTML.
	 */
	private function place_lock_buttons( $summary, $key = '' ) {
		global $wpdb;
		$cc = strtoupper( trim( (string) ( $summary['shipping_country'] ?? '' ) ) );
		$st = (string) ( $summary['ship_state'] ?? '' );
		$ct = (string) ( $summary['ship_city'] ?? '' );
		$sn = (string) ( $summary['ship_street'] ?? '' );

		$street_target = FS_Normalizer::place_target( 'street', $cc, $st, $ct, $sn );
		if ( '' === $street_target ) {
			if ( null === ( $summary['ship_street'] ?? null ) && (int) ( $summary['orders'] ?? 0 ) > 0 ) {
				return '<p class="description">' . esc_html__( 'Street and building bans unavailable: this address has not been geo-indexed yet. Run the backfill on the Geography page, then reload.', 'fraud-shield' ) . '</p>';
			}
			return '';
		}

		// The house number, from the most recent live order at this address
		// (the parser reads it off the real line), else from the stored text.
		$number = '';
		if ( '' !== $key ) {
			$oid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM ' . FS_Data::index_table() . ' WHERE ship_hash = %s ORDER BY order_date DESC, id DESC LIMIT 1', $key ) ); // phpcs:ignore
			$ord = ( $oid > 0 && function_exists( 'wc_get_order' ) ) ? wc_get_order( $oid ) : false;
			if ( $ord ) {
				$number = FS_Normalizer::house( FS_Data::resolve_shipping( $ord ) );
			}
		}
		if ( '' === $number ) {
			$number = FS_Normalizer::house( array( 'address_1' => (string) ( $summary['ship_text'] ?? '' ) ) );
		}

		$street_label = ucwords( FS_Normalizer::align_street( $sn ) ) . ', ' . ucwords( FS_Normalizer::city( $ct ) ) . ', ' . FS_Normalizer::region( $st ) . ' (' . $cc . ')';
		$bounds       = FS_Data::street_boundaries( $cc, $st, $sn );
		$args         = array(
			'action'          => 'fs_block_place',
			'scope'           => 'street',
			'country'         => $cc,
			'state'           => $st,
			'city'            => $ct,
			'street_key'      => $sn, // Already normalized — never re-parsed.
			'boundary_cities' => implode( ',', $bounds['cities'] ),
			'boundary_zips'   => implode( ',', $bounds['zips'] ),
		);
		$street_url   = wp_nonce_url( add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), admin_url( 'admin-post.php' ) ), 'fs_block_place' );

		$html = '';
		if ( FS_Blocklist::place_action_for_key( $street_target ) ) {
			/* translators: %s: street label */
			$confirm = sprintf( __( 'Remove the street ban on %s? Checkouts shipping to this street will be allowed again.', 'fraud-shield' ), $street_label );
			$html   .= '<a href="' . esc_url( $street_url ) . '" class="page-title-action fs-blocked-btn" title="' . esc_attr__( 'Every checkout shipping to this street is refused. Click to unlock.', 'fraud-shield' ) . '" onclick="return confirm(\'' . esc_js( $confirm ) . '\');">&#10004; ' . esc_html__( 'Street banned — click to unlock', 'fraud-shield' ) . '</a>';
		} else {
			$stats   = FS_Data::street_stats( $cc, $st, $ct, $sn );
			$confirm = sprintf(
				/* translators: 1: street label, 2: order count, 3: customer count, 4: address count, 5: city names, 6: ZIP codes, 7: checkout message */
				__( 'Ban ALL checkouts shipping to any address on %1$s? %2$d orders from %3$d customers at %4$d addresses have shipped to this street — legitimate neighbours will be blocked too. The ban covers this road under every city name (%5$s) and ZIP (%6$s) it has been typed with; the same road name elsewhere is not affected. Verified customers are exempt; this never cascades to identities. Blocked customers will see: "%7$s"', 'fraud-shield' ),
				$street_label,
				$stats['orders'],
				$stats['customers'],
				$stats['addresses'],
				implode( ', ', array_map( 'ucwords', $bounds['cities'] ) ),
				implode( ', ', $bounds['zips'] ),
				FS_Blocklist::street_default_message()
			);
			$html   .= '<a href="' . esc_url( $street_url ) . '" class="page-title-action" title="' . esc_attr__( 'Refuse every checkout shipping to this street (all house numbers, every city name and ZIP it has been typed with), without blocking anyone\'s identity.', 'fraud-shield' ) . '" onclick="return confirm(\'' . esc_js( $confirm ) . '\');">' . esc_html__( 'Ban this street', 'fraud-shield' ) . '</a>';
		}

		$building_target = '' !== $number ? FS_Normalizer::place_target( 'building', $cc, $st, $number, $sn ) : '';
		if ( '' !== $building_target ) {
			$bargs = array(
				'action'     => 'fs_block_place',
				'scope'      => 'building',
				'country'    => $cc,
				'state'      => $st,
				'number'     => $number,
				'street_key' => $sn,
			);
			$burl  = wp_nonce_url( add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $bargs ) ), admin_url( 'admin-post.php' ) ), 'fs_block_place' );
			$blabel = $number . ' ' . ucwords( FS_Normalizer::align_street( $sn ) ) . ', ' . FS_Normalizer::region( $st ) . ' (' . $cc . ')';
			if ( FS_Blocklist::place_action_for_key( $building_target ) ) {
				/* translators: %s: building label */
				$confirm = sprintf( __( 'Remove the building ban on %s? Checkouts shipping to this building will be allowed again.', 'fraud-shield' ), $blabel );
				$html   .= ' <a href="' . esc_url( $burl ) . '" class="page-title-action fs-blocked-btn" title="' . esc_attr__( 'Every checkout shipping to this building — any unit, box or recipient — is refused. Click to unlock.', 'fraud-shield' ) . '" onclick="return confirm(\'' . esc_js( $confirm ) . '\');">&#10004; ' . esc_html__( 'Building banned — click to unlock', 'fraud-shield' ) . '</a>';
			} else {
				$confirm = sprintf(
					/* translators: 1: building label, 2: checkout message */
					__( 'Ban ALL checkouts shipping to %1$s — every apartment, suite, mailbox, forwarding-account number, business and recipient at this building, under any city name or ZIP, anywhere in the state? Verified customers are exempt; this never cascades to identities. Blocked customers will see: "%2$s"', 'fraud-shield' ),
					$blabel,
					FS_Blocklist::street_default_message()
				);
				$html   .= ' <a href="' . esc_url( $burl ) . '" class="page-title-action" title="' . esc_attr__( 'Refuse every checkout shipping to this one building, whatever unit, city name or ZIP is typed, without blocking anyone\'s identity.', 'fraud-shield' ) . '" onclick="return confirm(\'' . esc_js( $confirm ) . '\');">' . esc_html__( 'Ban this building', 'fraud-shield' ) . '</a>';
			}
		}
		return $html;
	}

	/**
	 * The raw address parts behind an address cluster: from its most recent
	 * live order, else parsed out of the stored text.
	 *
	 * @param array  $summary Cluster summary.
	 * @param string $key     ship_hash.
	 * @return array Address parts.
	 */
	private function cluster_address_parts( $summary, $key ) {
		global $wpdb;
		if ( '' !== $key ) {
			$oid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM ' . FS_Data::index_table() . ' WHERE ship_hash = %s ORDER BY order_date DESC, id DESC LIMIT 1', $key ) ); // phpcs:ignore
			$ord = ( $oid > 0 && function_exists( 'wc_get_order' ) ) ? wc_get_order( $oid ) : false;
			if ( $ord ) {
				return FS_Data::resolve_shipping( $ord );
			}
		}
		// "8020 NW 66th St Ste 152978, Miami FL 33167 US": line, then city/state/ZIP/country.
		$text  = (string) ( $summary['ship_text'] ?? '' );
		$comma = strrpos( $text, ',' );
		return array(
			'address_1' => false !== $comma ? trim( substr( $text, 0, $comma ) ) : $text,
			'address_2' => '',
			'city'      => (string) ( $summary['ship_city'] ?? '' ),
			'state'     => (string) ( $summary['ship_state'] ?? '' ),
			'postcode'  => '',
			'country'   => (string) ( $summary['shipping_country'] ?? '' ),
		);
	}

	/**
	 * The address page's "Location" panel (v1.31): where the geocoder puts
	 * this address, and the AREA ban drawn around it — every address that
	 * resolves to a point within the radius is refused, whatever unit, name,
	 * ZIP or city name is typed. Reads as a toggle when an area ban already
	 * contains the point.
	 *
	 * @param array  $summary Cluster summary.
	 * @param string $key     ship_hash.
	 */
	private function render_area_ban_panel( $summary, $key ) {
		$parts = $this->cluster_address_parts( $summary, $key );
		$label = trim( (string) ( $summary['ship_text'] ?? '' ) );
		echo '<div class="fs-panel fs-area-panel">';
		echo '<h2>' . esc_html__( 'Location and area ban', 'fraud-shield' ) . '</h2>';

		if ( ! FS_Address_Check::provider_ready() ) {
			echo '<p class="description">' . esc_html__( 'The configured geocoder needs a key before it can locate addresses — see Settings → Address resolution. The free US Census geocoder needs none.', 'fraud-shield' ) . '</p></div>';
			return;
		}
		if ( ! FS_Address_Check::covers( $parts['country'] ) ) {
			echo '<p class="description">' . esc_html__( 'The configured geocoder does not cover this country. The US Census geocoder is US-only; choose Google or Nominatim under Settings → Address resolution for addresses elsewhere.', 'fraud-shield' ) . '</p></div>';
			return;
		}

		$check = FS_Address_Check::locate( $parts );
		if ( ! is_array( $check ) || ! FS_Address_Check::has_point( $check ) ) {
			$why = is_array( $check ) && 'unavailable' === $check['status'] ? ( '' !== (string) $check['error'] ? (string) $check['error'] : __( 'the geocoder did not answer', 'fraud-shield' ) ) : __( 'the geocoder could not find this address', 'fraud-shield' );
			/* translators: %s: reason */
			echo '<p class="description">' . esc_html( sprintf( __( 'This address could not be located: %s. An area ban can still be added from the Geography page by typing the address or coordinates.', 'fraud-shield' ), $why ) ) . '</p></div>';
			return;
		}

		$lat  = (float) $check['lat'];
		$lng  = (float) $check['lng'];
		$map  = 'https://www.openstreetmap.org/?mlat=' . rawurlencode( (string) $lat ) . '&mlon=' . rawurlencode( (string) $lng ) . '#map=17/' . rawurlencode( (string) $lat ) . '/' . rawurlencode( (string) $lng );
		$prov = array(
			'census'    => __( 'US Census geocoder', 'fraud-shield' ),
			'google'    => __( 'Google Address Validation', 'fraud-shield' ),
			'nominatim' => __( 'OpenStreetMap Nominatim', 'fraud-shield' ),
		);
		echo '<p>' . esc_html__( 'Resolves to:', 'fraud-shield' ) . ' <strong>' . esc_html( '' !== (string) $check['formatted'] ? (string) $check['formatted'] : $label ) . '</strong>';
		if ( ! empty( $check['county'] ) ) {
			echo ' <span class="fs-muted">(' . esc_html( ucwords( (string) $check['county'] ) . ' ' . __( 'County', 'fraud-shield' ) ) . ')</span>';
		}
		echo '<br/><span class="fs-muted">' . esc_html( sprintf( '%.5f, %.5f', $lat, $lng ) ) . ' · ' . esc_html( $prov[ (string) $check['provider'] ] ?? (string) $check['provider'] ) . '</span> · <a href="' . esc_url( $map ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open map', 'fraud-shield' ) . '</a>';
		if ( ! empty( $check['replaced'] ) ) {
			echo '<br/><span class="fs-muted">' . esc_html__( 'The typed city or ZIP differed from the real one — the geocoder corrected it.', 'fraud-shield' ) . '</span>';
		}
		echo '</p>';

		$inside = FS_Blocklist::geofence_hit( $lat, $lng );
		if ( is_array( $inside ) ) {
			$card = FS_Blocklist::place_action_for_key( (string) $inside['value'] );
			$g    = FS_Normalizer::geo_parts( (string) $inside['value'] );
			$args = array(
				'action' => 'fs_block_place',
				'scope'  => 'geo',
				'lat'    => (string) $g['lat'],
				'lng'    => (string) $g['lng'],
				'radius' => (string) $g['radius_m'],
			);
			$url  = wp_nonce_url( add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin-post.php' ) ), 'fs_block_place' );
			/* translators: %s: the area ban's card title */
			echo '<p><a href="' . esc_url( $url ) . '" class="page-title-action fs-blocked-btn" onclick="return confirm(\'' . esc_js( __( 'Remove this area ban? Checkouts shipping anywhere inside it will be allowed again.', 'fraud-shield' ) ) . '\');">&#10004; ' . esc_html( sprintf( __( 'Area banned: %s — click to unlock', 'fraud-shield' ), is_array( $card ) ? (string) $card['title'] : (string) $inside['value'] ) ) . '</a></p>';
			echo '</div>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fs-filters">
			<?php wp_nonce_field( 'fs_block_place' ); ?>
			<input type="hidden" name="action" value="fs_block_place" />
			<input type="hidden" name="scope" value="geo" />
			<input type="hidden" name="lat" value="<?php echo esc_attr( (string) $lat ); ?>" />
			<input type="hidden" name="lng" value="<?php echo esc_attr( (string) $lng ); ?>" />
			<input type="hidden" name="label" value="<?php echo esc_attr( '' !== (string) $check['formatted'] ? (string) $check['formatted'] : $label ); ?>" />
			<input type="hidden" name="region" value="<?php echo esc_attr( (string) $check['country'] . '|' . (string) $check['state'] ); ?>" />
			<label><?php esc_html_e( 'Ban every checkout shipping within', 'fraud-shield' ); ?>
				<input type="number" name="radius_mi" step="0.05" min="0.05" max="100" value="0.5" class="small-text" /> <?php esc_html_e( 'miles of this location', 'fraud-shield' ); ?>
			</label>
			<input type="text" name="message" class="regular-text" placeholder="<?php esc_attr_e( 'Custom checkout message (optional)', 'fraud-shield' ); ?>" />
			<button class="button" onclick="return confirm('<?php echo esc_js( __( 'Ban ALL checkouts shipping to any address that resolves inside this radius — every building, unit, business and recipient in it, under any city name or ZIP? Legitimate neighbours inside the circle will be refused too. Verified customers are exempt; this never cascades to identities.', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Ban this area', 'fraud-shield' ); ?></button>
		</form>
		<p class="description"><?php esc_html_e( 'An area ban matches the place, not the text: the address a customer types is resolved to a point by the geocoder and tested against the circle, so a changed ZIP, city name, unit or recipient has no effect. Half a mile covers a reshipper\'s building and its immediate neighbours; a tenth of a mile is one block. Addresses the geocoder cannot locate are not matched by an area ban — the street and building bans beside it cover those.', 'fraud-shield' ); ?></p>
		<?php
		echo '</div>';
	}

	/**
	 * The address page's "Rewrite simulation" panel (v1.31): a button that
	 * generates every way this address could be typed — directional,
	 * ordinal, street type, unit style, line placement, city, ZIP, state,
	 * Unicode — and pushes each through the real matcher against the
	 * store's live locks, then lists what got THROUGH. The answer to "would
	 * the next rewrite get past us?" before the next rewrite arrives.
	 *
	 * Runs on demand (a nonce-guarded GET), never on the plain page view:
	 * it is a few hundred matcher calls and up to forty geocoder requests.
	 *
	 * @param array  $summary Cluster summary.
	 * @param string $key     ship_hash.
	 */
	private function render_rewrite_panel( $summary, $key ) {
		$parts = $this->cluster_address_parts( $summary, $key );
		if ( null === FS_Rewrites::decompose( $parts ) ) {
			return; // No house number + street to vary (a PO box, say).
		}
		$run_url = wp_nonce_url( add_query_arg( 'fs_rewrites', '1' ), 'fs_rewrites_' . $key );
		$running = isset( $_GET['fs_rewrites'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'fs_rewrites_' . $key ); // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="fs-panel fs-rewrites-panel">';
		echo '<h2>' . esc_html__( 'Rewrite simulation', 'fraud-shield' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Generates every way this address could be typed — "N.W." and "Northwest", "66th" and "66" and "Sixty-Sixth", "Street" and "Str" and no type at all, the unit as Apt / Ste / # / glued / repeated / on the other line / first / bare / another box number, a name or a unit on line 1 with the street on line 2, the destination typed into the line, the city renamed, misspelled or blank, the ZIP rotated, extended, blanked or invented, the state spelled out, Cyrillic lookalikes, full-width digits, zero-width characters — and runs each through the live blocklist exactly as a checkout would: first on the typed text, then through the geocoder for whatever got past that. What gets through is listed in full.', 'fraud-shield' ) . '</p>';

		if ( ! $running ) {
			echo '<p><a href="' . esc_url( $run_url ) . '" class="button button-primary">' . esc_html( sprintf( /* translators: %d: number of variants */ __( 'Simulate %d rewrites of this address', 'fraud-shield' ), FS_Rewrites::DEFAULT_LIMIT ) ) . '</a> <span class="fs-muted">' . esc_html__( 'Takes a few seconds; up to 40 geocoder requests.', 'fraud-shield' ) . '</span></p></div>';
			return;
		}

		$cc     = strtoupper( trim( (string) ( $parts['country'] ?? '' ) ) );
		$extra  = FS_Data::street_boundaries( $cc, (string) ( $parts['state'] ?? '' ), FS_Normalizer::street( $parts ) );
		$result = FS_Rewrites::simulate( $parts, array( 'extra' => $extra, 'geocode_cap' => FS_Rewrites::GEOCODE_CAP ) );

		$through = (int) $result['through'];
		$class   = $through > 0 ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p><strong>';
		if ( $through > 0 ) {
			/* translators: 1: variants that got through, 2: total variants */
			echo esc_html( sprintf( __( '%1$d of %2$d rewrites would get THROUGH.', 'fraud-shield' ), $through, (int) $result['total'] ) );
		} else {
			/* translators: %d: total variants */
			echo esc_html( sprintf( __( 'All %d rewrites refused.', 'fraud-shield' ), (int) $result['total'] ) );
		}
		echo '</strong> ';
		echo esc_html( sprintf(
			/* translators: 1: refused on typed text, 2: refused after geocoding, 3: stopped for correction, 4: skipped by the geocoder cap */
			__( '%1$d refused on the typed text alone, %2$d refused after the geocoder resolved them, %3$d stopped for correction as unverifiable, %4$d not sent to the geocoder (run cap).', 'fraud-shield' ),
			(int) $result['refused_typed'],
			(int) $result['refused_resolved'],
			(int) $result['review'],
			(int) $result['geocode_skipped']
		) );
		echo '</p></div>';

		if ( $through > 0 ) {
			echo '<h3>' . esc_html__( 'Would get through', 'fraud-shield' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Rewrite', 'fraud-shield' ) . '</th><th>' . esc_html__( 'As typed', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Why it gets through', 'fraud-shield' ) . '</th></tr></thead><tbody>';
			foreach ( $result['through_list'] as $v ) {
				echo '<tr><td>' . esc_html( $v['label'] ) . '</td><td><code>' . esc_html( $this->rewrite_text( $v['parts'] ) ) . '</code></td><td>' . esc_html( $v['how'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'A rewrite that gets through on the typed text but is refused after the geocoder is already counted as refused; what is listed here passed both. The usual fixes: add the building ban (unit-, ZIP- and city-blind), widen the street ban\'s boundary with the city names and ZIPs shown, add an area ban, or switch address validation on under Settings → Address resolution.', 'fraud-shield' ) . '</p>';
		}

		echo '<details><summary>' . esc_html( sprintf( /* translators: %d: total variants */ __( 'Every variant and its outcome (%d)', 'fraud-shield' ), (int) $result['total'] ) ) . '</summary>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Rewrite', 'fraud-shield' ) . '</th><th>' . esc_html__( 'As typed', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Outcome', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Matched', 'fraud-shield' ) . '</th></tr></thead><tbody>';
		$labels = array(
			'refused' => __( 'Refused', 'fraud-shield' ),
			'review'  => __( 'Stopped for correction', 'fraud-shield' ),
			'through' => __( 'THROUGH', 'fraud-shield' ),
		);
		foreach ( $result['variants'] as $v ) {
			$pill = 'through' === $v['outcome'] ? 'fs-pill fs-pill-red' : 'fs-pill';
			echo '<tr><td>' . esc_html( $v['label'] ) . '</td><td><code>' . esc_html( $this->rewrite_text( $v['parts'] ) ) . '</code></td><td><span class="' . esc_attr( $pill ) . '">' . esc_html( $labels[ $v['outcome'] ] ?? $v['outcome'] ) . '</span> <span class="fs-muted">' . esc_html( 'typed' === $v['phase'] ? __( 'typed text', 'fraud-shield' ) : __( 'after geocoding', 'fraud-shield' ) ) . '</span></td><td>' . esc_html( $v['how'] ) . '</td></tr>';
		}
		echo '</tbody></table></details>';
		echo '</div>';
	}

	/**
	 * One variant's address as a single readable line.
	 *
	 * @param array $p Address parts.
	 * @return string
	 */
	private function rewrite_text( $p ) {
		$line = (string) $p['address_1'];
		if ( '' !== (string) $p['address_2'] ) {
			$line .= ' / ' . (string) $p['address_2'];
		}
		return trim( $line . ' — ' . trim( (string) $p['city'] . ' ' . (string) $p['state'] . ' ' . (string) $p['postcode'] ) . ' ' . (string) $p['country'] );
	}

	/**
	 * AJAX: render the full detail panel for one order (items, addresses, account).
	 */
	public function ajax_cluster_order() {
		check_ajax_referer( 'fs_cluster', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fraud-shield' ) ), 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'fraud-shield' ) ), 404 );
		}
		wp_send_json_success( array( 'html' => $this->render_order_detail( $order ) ) );
	}

	/**
	 * Build the expandable per-order detail HTML.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function render_order_detail( $order ) {
		global $wpdb;

		$currency  = $order->get_currency();
		$price_arg = array( 'currency' => $currency );

		// Account context from the index (how many orders share this email).
		$email_norm  = FS_Normalizer::email( $order->get_billing_email() );
		$acct_orders = '' !== $email_norm
			? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . FS_Data::index_table() . ' WHERE email_norm = %s', $email_norm ) ) // phpcs:ignore
			: 0;

		$customer_id = $order->get_customer_id();
		$registered  = '';
		$user_link   = '';
		if ( $customer_id ) {
			$user = get_userdata( $customer_id );
			if ( $user ) {
				$registered = $user->user_registered;
				$user_link  = get_edit_user_link( $customer_id );
			}
		}

		$fmt_address = function ( $parts ) {
			$lines = array();
			foreach ( $parts as $p ) {
				$p = trim( (string) $p, " \t\n\r\0\x0B," );
				if ( '' !== $p ) {
					$lines[] = esc_html( $p );
				}
			}
			return empty( $lines ) ? esc_html__( '—', 'fraud-shield' ) : implode( '<br/>', $lines );
		};

		$billing = array(
			$order->get_billing_company(),
			trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			$order->get_billing_address_1(),
			$order->get_billing_address_2(),
			trim( $order->get_billing_city() . ', ' . $order->get_billing_state() . ' ' . $order->get_billing_postcode() ),
			$order->get_billing_country(),
		);
		$shipping = array(
			$order->get_shipping_company(),
			trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			$order->get_shipping_address_1(),
			$order->get_shipping_address_2(),
			trim( $order->get_shipping_city() . ', ' . $order->get_shipping_state() . ' ' . $order->get_shipping_postcode() ),
			$order->get_shipping_country(),
		);

		ob_start();
		?>
		<div class="fs-detail-grid">
			<div class="fs-detail-col">
				<h4><?php esc_html_e( 'Items ordered', 'fraud-shield' ); ?></h4>
				<table class="fs-items">
					<tbody>
					<?php
					$items = $order->get_items();
					if ( empty( $items ) ) {
						echo '<tr><td>' . esc_html__( 'No line items.', 'fraud-shield' ) . '</td></tr>';
					}
					foreach ( $items as $item ) {
						echo '<tr><td>' . esc_html( $item->get_name() ) . ' &times; ' . esc_html( $item->get_quantity() ) . '</td><td class="fs-items-amt">' . wp_kses_post( wc_price( (float) $item->get_total(), $price_arg ) ) . '</td></tr>';
					}
					?>
					</tbody>
					<tfoot>
						<tr><td><strong><?php esc_html_e( 'Order total', 'fraud-shield' ); ?></strong></td><td class="fs-items-amt"><strong><?php echo wp_kses_post( wc_price( (float) $order->get_total(), $price_arg ) ); ?></strong></td></tr>
					</tfoot>
				</table>

				<h4><?php esc_html_e( 'Order', 'fraud-shield' ); ?></h4>
				<ul class="fs-kv">
					<li><span><?php esc_html_e( 'Status', 'fraud-shield' ); ?></span> <?php echo esc_html( function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $order->get_status() ) : $order->get_status() ); ?></li>
					<li><span><?php esc_html_e( 'Date', 'fraud-shield' ); ?></span> <?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '—' ); ?></li>
					<li><span><?php esc_html_e( 'Payment', 'fraud-shield' ); ?></span> <?php echo esc_html( $order->get_payment_method_title() ? $order->get_payment_method_title() : $order->get_payment_method() ); ?></li>
					<li><span><?php esc_html_e( 'Order IP', 'fraud-shield' ); ?></span> <?php echo esc_html( $order->get_customer_ip_address() ? $order->get_customer_ip_address() : '—' ); ?></li>
					<?php $fs_oip = $order->get_customer_ip_address(); ?>
					<li><span><?php esc_html_e( 'IP location', 'fraud-shield' ); ?></span> <?php echo esc_html( ( $fs_oip && '' !== $this->ip_location_label( $fs_oip ) ) ? $this->ip_location_label( $fs_oip ) : __( 'Unknown', 'fraud-shield' ) ); ?></li>
				</ul>
			</div>

			<div class="fs-detail-col">
				<h4><?php esc_html_e( 'Billing', 'fraud-shield' ); ?></h4>
				<p class="fs-addr-block"><?php echo wp_kses( $fmt_address( $billing ), array( 'br' => array() ) ); ?></p>
				<ul class="fs-kv">
					<li><span><?php esc_html_e( 'Email', 'fraud-shield' ); ?></span> <?php echo esc_html( $order->get_billing_email() ? $order->get_billing_email() : '—' ); ?></li>
					<li><span><?php esc_html_e( 'Phone', 'fraud-shield' ); ?></span> <?php echo esc_html( $order->get_billing_phone() ? $order->get_billing_phone() : '—' ); ?></li>
				</ul>

				<h4><?php esc_html_e( 'Shipping', 'fraud-shield' ); ?></h4>
				<p class="fs-addr-block"><?php echo wp_kses( $fmt_address( $shipping ), array( 'br' => array() ) ); ?></p>

				<h4><?php esc_html_e( 'Account', 'fraud-shield' ); ?></h4>
				<ul class="fs-kv">
					<li><span><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></span>
						<?php
						if ( $user_link ) {
							echo '<a href="' . esc_url( $user_link ) . '">' . esc_html__( 'Registered user', 'fraud-shield' ) . ' #' . (int) $customer_id . '</a>';
						} else {
							esc_html_e( 'Guest checkout', 'fraud-shield' );
						}
						?>
					</li>
					<?php if ( $registered ) : ?>
						<li><span><?php esc_html_e( 'Registered', 'fraud-shield' ); ?></span> <?php echo esc_html( mysql2date( 'Y-m-d', $registered ) ); ?></li>
					<?php endif; ?>
					<li><span><?php esc_html_e( 'Orders from this email', 'fraud-shield' ); ?></span> <?php echo esc_html( number_format_i18n( $acct_orders ) ); ?></li>
				</ul>

				<?php if ( $order->get_customer_note() ) : ?>
					<h4><?php esc_html_e( 'Customer note', 'fraud-shield' ); ?></h4>
					<p class="fs-note"><?php echo esc_html( $order->get_customer_note() ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ===================== New Customers ===================== */

	/**
	 * A green check / amber warning badge for an account-vs-order comparison.
	 *
	 * @param bool $ok Whether the value matches the account.
	 * @return string
	 */
	private function match_badge( $ok ) {
		return $ok
			? '<span class="fs-ok" title="' . esc_attr__( 'Matches the account', 'fraud-shield' ) . '">&#10004;</span>'
			: '<span class="fs-bad" title="' . esc_attr__( 'Differs from the account', 'fraud-shield' ) . '">&#9888;</span>';
	}

	/**
	 * New Customers page: recently-registered accounts ranked by order velocity.
	 * Doubles as the router to a single customer's profile (?fs_customer=ID).
	 */
	public function page_new_customers() {
		$this->guard();

		$uid = isset( $_GET['fs_customer'] ) ? (int) $_GET['fs_customer'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $uid > 0 ) {
			$this->render_customer_profile( $uid );
			return;
		}

		$months = isset( $_GET['months'] ) ? max( 1, (int) $_GET['months'] ) : 3;         // phpcs:ignore WordPress.Security.NonceVerification
		$min    = isset( $_GET['min_orders'] ) ? max( 1, (int) $_GET['min_orders'] ) : 2;  // phpcs:ignore WordPress.Security.NonceVerification
		$sort   = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'frequency'; // phpcs:ignore WordPress.Security.NonceVerification
		$dir    = ( isset( $_GET['dir'] ) && 'asc' === $_GET['dir'] ) ? 'asc' : 'desc';    // phpcs:ignore WordPress.Security.NonceVerification
		list( $sort2, $dir2 ) = $this->sort2_from_request();
		$this->cur_sort2      = $sort2;
		$this->cur_dir2       = $dir2;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;            // phpcs:ignore WordPress.Security.NonceVerification
		$per    = 50;
		$offset = ( $paged - 1 ) * $per;

		$total    = FS_Data::new_customers_count( $months, $min, $search );
		$rows     = FS_Data::new_customers(
			array(
				'months'     => $months,
				'min_orders' => $min,
				'orderby'    => $sort,
				'dir'        => $dir,
				'orderby2'   => $sort2,
				'dir2'       => $dir2,
				'search'     => $search,
				'limit'      => $per,
				'offset'     => $offset,
			)
		);
		$pages    = max( 1, (int) ceil( $total / $per ) );
		$settings = FS_Core::instance()->settings();

		// Batch lock-state for the row Block toggles (no N+1).
		$nc_emails = array();
		foreach ( (array) $rows as $r ) {
			$nc_emails[] = FS_Normalizer::email( $r['user_email'] );
		}
		$nc_actions = FS_Blocklist::actions_for_targets( 'profile', $nc_emails );
		$nc_blocked = FS_Blocklist::blocked_values( 'email', $nc_emails );
		$vcreds     = FS_Live::verified_creds();

		// Base args every sort/pagination link must preserve.
		$base_args = array(
			'page'       => self::SLUG . '-new-customers',
			'months'     => $months,
			'min_orders' => $min,
			's'          => $search,
			'sort2'      => $sort2,
			'dir2'       => $dir2,
		);
		?>
		<div class="wrap fs-wrap">
			<h1><?php esc_html_e( 'New Customers', 'fraud-shield' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Accounts registered recently, ranked by how fast they order. A brand-new account placing several high-value orders in quick succession is the classic pattern for a customer who intends to charge back.', 'fraud-shield' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-new-customers' ); ?>" />
				<p class="fs-filters">
					<label><?php esc_html_e( 'Registered within', 'fraud-shield' ); ?>
						<select name="months">
							<?php
							$mopts = array(
								1  => __( 'Last 1 month', 'fraud-shield' ),
								3  => __( 'Last 3 months', 'fraud-shield' ),
								6  => __( 'Last 6 months', 'fraud-shield' ),
								12 => __( 'Last 12 months', 'fraud-shield' ),
							);
							foreach ( $mopts as $k => $lbl ) {
								echo '<option value="' . (int) $k . '"' . selected( $months, $k, false ) . '>' . esc_html( $lbl ) . '</option>';
							}
							?>
						</select>
					</label>
					<label><?php esc_html_e( 'Min orders', 'fraud-shield' ); ?> <input type="number" name="min_orders" min="1" value="<?php echo (int) $min; ?>" class="small-text" /></label>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, phone, IP, address…', 'fraud-shield' ); ?>" class="regular-text" />
					<button class="button"><?php esc_html_e( 'Filter', 'fraud-shield' ); ?></button>
					<a class="button" href="<?php echo esc_url( $this->export_url( 'new', array( 'months' => $months, 'min_orders' => $min, 's' => $search, 'sort' => $sort, 'dir' => $dir ) ) ); ?>"><?php esc_html_e( 'Export CSV (Excel)', 'fraud-shield' ); ?></a>
				</p>
				<p class="description"><?php esc_html_e( 'Only orders whose payment succeeded (plus chargebacks) are counted. Click any column header to sort; click again to flip the direction.', 'fraud-shield' ); ?></p>
			</form>

			<p class="description">
			<?php
			/* translators: %s: number of new customers */
			printf( esc_html( _n( '%s new customer with activity.', '%s new customers with activity.', $total, 'fraud-shield' ) ), esc_html( number_format_i18n( $total ) ) );
			?>
			</p>

			<table class="wp-list-table widefat striped">
				<thead><tr>
					<th>#</th>
					<?php
					$this->sort_th( __( 'Customer', 'fraud-shield' ), 'name', $sort, $dir, $base_args );
					$this->sort_th( __( 'Account email', 'fraud-shield' ), 'email', $sort, $dir, $base_args );
					$this->sort_th( __( 'Registered', 'fraud-shield' ), 'registered', $sort, $dir, $base_args );
					$this->sort_th( __( 'Orders', 'fraud-shield' ), 'orders', $sort, $dir, $base_args );
					$this->sort_th( __( 'Orders/mo', 'fraud-shield' ), 'frequency', $sort, $dir, $base_args );
					$this->sort_th( __( 'Spend (paid)', 'fraud-shield' ), 'spend', $sort, $dir, $base_args );
					$this->sort_th( __( 'CB', 'fraud-shield' ), 'chargebacks', $sort, $dir, $base_args );
					$this->sort_th( __( 'Max risk', 'fraud-shield' ), 'risk', $sort, $dir, $base_args );
					$this->sort_th( __( 'Last order', 'fraud-shield' ), 'recent', $sort, $dir, $base_args );
					?>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="11"><?php esc_html_e( 'No new customers match. Try widening the window, lowering the minimum orders, or run a full scan from the dashboard.', 'fraud-shield' ); ?></td></tr>
				<?php else : ?>
					<?php
					$rank = $offset;
					foreach ( $rows as $r ) :
						++$rank;
						$cid         = (int) $r['user_id'];
						$profile_url = add_query_arg(
							array(
								'page'        => self::SLUG . '-new-customers',
								'fs_customer' => $cid,
							),
							admin_url( 'admin.php' )
						);
						$opm  = (float) $r['orders_per_month'];
						$flag = ( $opm >= 2 || (int) $r['chargebacks'] > 0 );
						?>
						<tr<?php echo $flag ? ' class="fs-row-flag"' : ''; ?>>
							<td><?php echo (int) $rank; ?></td>
							<td><a href="<?php echo esc_url( $profile_url ); ?>"><strong><?php echo esc_html( $r['customer_name'] ? $r['customer_name'] : $r['display_name'] ); ?></strong></a></td>
							<td><?php echo esc_html( $r['user_email'] ); ?></td>
							<td>
								<?php
								echo esc_html( mysql2date( 'Y-m-d', $r['user_registered'] ) );
								/* translators: %s: number of days */
								echo ' <span class="fs-muted">' . esc_html( sprintf( _n( '(%s day)', '(%s days)', (int) $r['age_days'], 'fraud-shield' ), number_format_i18n( (int) $r['age_days'] ) ) ) . '</span>';
								?>
							</td>
							<td><?php echo (int) $r['orders']; ?></td>
							<td><strong><?php echo esc_html( number_format_i18n( $opm, 1 ) ); ?></strong></td>
							<td><?php echo wp_kses_post( wc_price( (float) $r['total_spend'] ) ); ?></td>
							<td><?php echo (int) $r['chargebacks'] ? '<span class="fs-pill fs-pill-red">' . (int) $r['chargebacks'] . '</span>' : '—'; ?></td>
							<td><?php echo $this->level_pill( FS_Rules::level_for_score( (int) $r['max_risk'], $settings ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo (int) $r['max_risk']; ?></td>
							<td><?php echo esc_html( mysql2date( 'Y-m-d', $r['last_order'] ) ); ?></td>
							<td>
								<a class="button button-small button-primary" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Profile', 'fraud-shield' ); ?></a>
								<?php
								$nc_email = FS_Normalizer::email( $r['user_email'] );
								echo $this->block_profile_button( $nc_email, isset( $nc_actions[ $nc_email ] ) ? $nc_actions[ $nc_email ] : null, in_array( $nc_email, $nc_blocked, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								echo ' ' . $this->verify_button( 'email', $nc_email, isset( $vcreds[ 'email:' . $nc_email ] ) || 'yes' === get_user_meta( $cid, '_fs_verified', true ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
				<?php
				$base = add_query_arg(
					array_merge(
						$base_args,
						array(
							'sort' => $sort,
							'dir'  => $dir,
						)
					),
					admin_url( 'admin.php' )
				);
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => $base . '%_%',
							'format'    => '&paged=%#%',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Per-customer payment-gateway restriction editor. Lets staff unblock a
	 * specific customer while limiting the gateways they may use, with an
	 * optional "allow this gateway when the order is under $X" rule (off unless
	 * an amount is set). Saving also verifies the account so they can check out.
	 *
	 * @param int $uid User id.
	 */
	/**
	 * Every gateway a rule could sensibly name, one map: id => [ title, note ].
	 *
	 * The union of what wp-admin registers and what checkout renders have
	 * actually served (seen_gateways) — a payment rotator typically registers
	 * its rotating gateways on the storefront ONLY, so a checklist built from
	 * wp-admin alone cannot even offer the ids that matter. That is exactly how
	 * the v1.20.1 low-value bug happened: the allow-list had grown the
	 * seen-at-checkout checkboxes in v1.16.1, but the allowance list (and the
	 * velocity, high-value, and per-customer lists) still drew from wp-admin
	 * only, so the allowance could only ever name ids the storefront never
	 * serves. Ticked ids that are known nowhere are kept on the list too:
	 * dropping them would silently wipe them on the next save, and a rotator
	 * pool member that has not come around recently is not the same thing as a
	 * dead id.
	 *
	 * @param array $ticked Gateway ids currently ticked (kept even if unknown).
	 * @return array id => array{ title:string, note:string }
	 */
	private function gateway_choices( $ticked = array() ) {
		$choices = array();

		$registered = ( function_exists( 'WC' ) && WC()->payment_gateways() ) ? WC()->payment_gateways()->payment_gateways() : array();
		foreach ( $registered as $gw ) {
			$id    = (string) $gw->id;
			$title = ( method_exists( $gw, 'get_method_title' ) && $gw->get_method_title() ) ? $gw->get_method_title() : $id;
			$on    = ( isset( $gw->enabled ) && 'yes' === $gw->enabled );
			$choices[ $id ] = array(
				'title' => $title,
				'note'  => $on ? '' : __( 'inactive', 'fraud-shield' ),
			);
		}

		foreach ( FS_Live::seen_gateways() as $id => $title ) {
			$id = (string) $id;
			if ( isset( $choices[ $id ] ) ) {
				continue;
			}
			$choices[ $id ] = array(
				'title' => (string) $title,
				'note'  => __( 'seen at checkout only', 'fraud-shield' ),
			);
		}

		foreach ( array_filter( array_map( 'strval', (array) $ticked ) ) as $id ) {
			if ( isset( $choices[ $id ] ) ) {
				continue;
			}
			$choices[ $id ] = array(
				'title' => $id,
				'note'  => __( 'ticked, but not currently registered or seen', 'fraud-shield' ),
			);
		}

		return $choices;
	}

	/**
	 * One checkbox per gateway a rule could name, drawn from gateway_choices().
	 * Shared by every gateway allow-list in Settings so no list is ever blind
	 * to storefront-only ids again.
	 *
	 * @param string $field_name Form field name (rendered as name[]).
	 * @param array  $ticked     Gateway ids currently ticked.
	 */
	private function render_gateway_checklist( $field_name, $ticked ) {
		$ticked  = array_map( 'strval', (array) $ticked );
		$choices = $this->gateway_choices( $ticked );

		if ( empty( $choices ) ) {
			echo '<p class="description">' . esc_html__( 'No payment gateways are registered yet.', 'fraud-shield' ) . '</p>';
			return;
		}

		foreach ( $choices as $id => $meta ) {
			echo '<label style="display:block;margin:.2em 0;"><input type="checkbox" name="' . esc_attr( $field_name ) . '[]" value="' . esc_attr( $id ) . '" ' . checked( in_array( (string) $id, $ticked, true ), true, false ) . '/> '
				. esc_html( $meta['title'] ) . ' <span class="fs-muted">(' . esc_html( $id ) . ')</span>'
				. ( '' === $meta['note'] ? '' : ' <span class="fs-muted">(' . esc_html( $meta['note'] ) . ')</span>' )
				. '</label>';
		}
	}

	/**
	 * Show exactly what the new-customer gateway rule resolves to right now, at a
	 * given order total.
	 *
	 * The settings form cannot show either of the two ways this rule silently
	 * stops working — a ticked gateway id that no longer exists (so the allow-list
	 * matches nothing and fails open), or an order under the low-value threshold
	 * quietly getting the withheld methods back. Both are invisible until someone
	 * reaches checkout, which is far too late.
	 */
	private function render_gateway_preview() {
		// Read-only preview; a total in the query string changes nothing.
		$total = isset( $_GET['fs_gw_total'] ) ? max( 0, (float) $_GET['fs_gw_total'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$d     = FS_Live::gateway_diagnostics( $total );
		$names = static function ( $ids ) use ( $d ) {
			$out = array();
			foreach ( (array) $ids as $id ) {
				if ( isset( $d['registered'][ $id ] ) ) {
					$name = $d['registered'][ $id ]['title'] . ' (' . $id . ')';
				} elseif ( isset( $d['seen'][ $id ] ) ) {
					$name = $d['seen'][ $id ] . ' (' . $id . ')';
				} else {
					$name = $id;
				}
				// A promise the storefront may not keep is not stated as a fact:
				// enabled in wp-admin but never once offered on a live render.
				if ( in_array( (string) $id, (array) ( $d['unseen_active'] ?? array() ), true ) ) {
					$name .= ' [' . __( 'never seen at a live checkout', 'fraud-shield' ) . ']';
				}
				$out[] = $name;
			}
			return implode( ', ', $out );
		};
		?>
		<div class="fs-bulk-restrict" id="fs-gw-preview">
			<h3><?php esc_html_e( 'Preview: what a new customer sees', 'fraud-shield' ); ?></h3>

			<?php if ( ! $d['enabled'] ) : ?>
				<p class="description"><strong><?php esc_html_e( 'The restriction is switched off,', 'fraud-shield' ); ?></strong> <?php esc_html_e( 'so every customer currently gets every payment method.', 'fraud-shield' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $d['unknown_allowed'] ) ) : ?>
				<div class="notice notice-error inline"><p>
					<strong><?php esc_html_e( 'These ticked gateway ids are not registered in wp-admin and have never been seen at checkout:', 'fraud-shield' ); ?></strong>
					<code><?php echo esc_html( implode( '</code>, <code>', array_map( 'esc_html', $d['unknown_allowed'] ) ) ); ?></code>.
					<?php esc_html_e( 'They can never match, so they contribute nothing. This happens when a gateway is renamed, or when another plugin (a payment rotator, for example) registers its gateways under generated ids. Tick the ids the checklists now offer instead.', 'fraud-shield' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( $d['low_amount'] > 0 && ! empty( $d['low_unreachable'] ) ) : ?>
				<div class="notice notice-error inline"><p>
					<strong><?php esc_html_e( 'The low-value allowance is granting nothing.', 'fraud-shield' ); ?></strong>
					<?php esc_html_e( 'These allowance ids are not registered in wp-admin at all and have never been seen at a checkout, so nothing can ever offer them and orders under the threshold get no extra method from them:', 'fraud-shield' ); ?>
					<code><?php echo esc_html( implode( '</code>, <code>', array_map( 'esc_html', $d['low_unreachable'] ) ) ); ?></code>.
					<?php esc_html_e( 'With a payment rotator, tick the allowance entries marked "seen at checkout only" — those are the ids customers actually get.', 'fraud-shield' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( $d['low_amount'] > 0 && ! empty( $d['low_unconfirmed'] ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<strong><?php esc_html_e( 'The low-value allowance includes ids Fraud Shield cannot confirm yet.', 'fraud-shield' ); ?></strong>
					<?php esc_html_e( 'These ids are registered in wp-admin but not active there, and no checkout render has offered them so far:', 'fraud-shield' ); ?>
					<code><?php echo esc_html( implode( '</code>, <code>', array_map( 'esc_html', $d['low_unconfirmed'] ) ) ); ?></code>.
					<?php esc_html_e( 'That can be entirely normal: wallet gateways such as Apple Pay or Google Pay often read as inactive in wp-admin and decide per device whether to appear at checkout. Fraud Shield filters the live payment list, so if the storefront ever offers one of these ids on an order under the threshold, the allowance grants it automatically — Fraud Shield allows gateways, it cannot create them. To confirm one works, place a test order under the threshold from a device that supports the wallet (for Apple Pay: Safari on an iPhone or Mac with a card in Wallet). The moment a checkout render offers the id it is recorded, its checklist entry changes to "seen at checkout", and this notice clears. If it never appears even with this restriction disabled, the wallet is not working in the payment plugin itself and no Fraud Shield setting will surface it.', 'fraud-shield' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( ! empty( $d['unseen_active'] ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<strong><?php esc_html_e( 'Some ticked ids may never actually reach checkout.', 'fraud-shield' ); ?></strong>
					<?php esc_html_e( 'These ids are enabled in wp-admin, but no live checkout render has ever offered them — while other ids have been recorded there:', 'fraud-shield' ); ?>
					<code><?php echo esc_html( implode( '</code>, <code>', array_map( 'esc_html', $d['unseen_active'] ) ) ); ?></code>.
					<?php esc_html_e( 'That is the signature of a payment rotator serving its own entries in place of the wp-admin gateways. Rules can only grant methods the checkout render actually carries, so if the rotator never offers these ids, neither the allow-list nor the low-value allowance can surface them — the rule quietly grants nothing from them. Tick their "seen at checkout" counterparts as well (for a card processor behind a rotator, that entry is what customers actually get). They are marked below so the preview does not promise them as fact.', 'fraud-shield' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( ! empty( $d['hv_strips'] ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<strong><?php esc_html_e( 'The high-value ID rule cuts across the low-value allowance.', 'fraud-shield' ); ?></strong>
					<?php
					printf(
						/* translators: 1: high-value threshold, 2: low-value threshold, 3: gateway ids */
						esc_html__( 'Your unverified-customer ID threshold (%1$s) is below the low-value threshold (%2$s), and the most restrictive rule wins: on orders between the two amounts, the ID rule strips these allowance gateways because they are not in its fallback set: %3$s. Either raise the ID threshold to at least the low-value amount, or add those gateways to the "Until they verify" set.', 'fraud-shield' ),
						esc_html( number_format_i18n( $d['hv_amount'], 2 ) ),
						esc_html( number_format_i18n( $d['low_amount'], 2 ) ),
						esc_html( implode( ', ', $d['hv_strips'] ) )
					);
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( $d['fails_open'] ) : ?>
				<div class="notice notice-error inline"><p>
					<strong><?php esc_html_e( 'This restriction is currently doing nothing.', 'fraud-shield' ); ?></strong>
					<?php esc_html_e( 'Gateways are ticked, but none of them is enabled in wp-admin or seen at checkout, so the rule falls back to allowing everything rather than leaving customers with no way to pay. New customers are seeing every payment method.', 'fraud-shield' ); ?>
				</p></div>
			<?php endif; ?>

			<?php
			// Deliberately NOT a nested <form> — this panel lives inside the settings
			// form, and a nested form is invalid HTML whose button would submit the
			// OUTER form, saving settings when someone only wanted a preview.
			$preview_base = add_query_arg( array( 'page' => self::SLUG . '-settings' ), admin_url( 'admin.php' ) );
			?>
			<p style="margin:.6em 0;">
				<label><?php esc_html_e( 'Preview for an order total of', 'fraud-shield' ); ?>
					<input type="number" id="fs-gw-total" min="0" step="0.01" value="<?php echo esc_attr( $total ); ?>" style="width:110px;"/>
				</label>
				<button type="button" class="button button-small" onclick="window.location.href='<?php echo esc_js( $preview_base ); ?>&amp;fs_gw_total='+encodeURIComponent(document.getElementById('fs-gw-total').value)+'#fs-gw-preview';"><?php esc_html_e( 'Preview', 'fraud-shield' ); ?></button>
			</p>

			<?php if ( $d['low_amount'] > 0 ) : ?>
				<p class="description">
					<?php
					if ( $d['low_applies'] ) {
						echo '<strong>';
						printf(
							/* translators: 1: order total, 2: low-value threshold */
							esc_html__( 'This %1$s order is UNDER your %2$s low-value threshold,', 'fraud-shield' ),
							esc_html( number_format_i18n( $total, 2 ) ),
							esc_html( number_format_i18n( $d['low_amount'], 2 ) )
						);
						echo '</strong> ';
						printf(
							/* translators: %s: gateway names */
							esc_html__( 'so it also gets the low-value methods: %s.', 'fraud-shield' ),
							esc_html( $names( $d['low_gateways'] ) )
						);
					} else {
						printf(
							/* translators: 1: low-value threshold, 2: gateway names */
							esc_html__( 'Orders under %1$s also get %2$s. Orders at or above it do not.', 'fraud-shield' ),
							esc_html( number_format_i18n( $d['low_amount'], 2 ) ),
							esc_html( $names( $d['low_gateways'] ) )
						);
					}
					?>
				</p>
			<?php endif; ?>

			<?php if ( $d['hv_applies'] ) : ?>
				<p class="description">
					<strong><?php esc_html_e( 'The high-value ID rule also fires at this amount:', 'fraud-shield' ); ?></strong>
					<?php
					printf(
						/* translators: 1: order total, 2: high-value threshold */
						esc_html__( 'a %1$s order is at or over the %2$s unverified-customer threshold, so until the customer verifies their ID the list below is narrowed to the "Until they verify" fallback set.', 'fraud-shield' ),
						esc_html( number_format_i18n( $total, 2 ) ),
						esc_html( number_format_i18n( $d['hv_amount'], 2 ) )
					);
					?>
				</p>
			<?php endif; ?>

			<p>
				<strong><?php esc_html_e( 'A restricted new customer would see:', 'fraud-shield' ); ?></strong>
				<?php echo empty( $d['shown'] ) ? '<em>' . esc_html__( 'nothing (so the rule falls back to showing everything)', 'fraud-shield' ) . '</em>' : esc_html( $names( $d['shown'] ) ); ?><br/>
				<strong><?php esc_html_e( 'Withheld from them:', 'fraud-shield' ); ?></strong>
				<?php echo empty( $d['withheld'] ) ? '<em>' . esc_html__( 'nothing', 'fraud-shield' ) . '</em>' : esc_html( $names( $d['withheld'] ) ); ?>
			</p>

			<table class="wp-list-table widefat striped" style="max-width:720px;">
				<thead><tr>
					<th><?php esc_html_e( 'Gateway', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Gateway id', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Active', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'New customers', 'fraud-shield' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $d['registered'] ) && empty( $d['seen'] ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No payment gateways are registered.', 'fraud-shield' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $d['registered'] as $gw_id => $meta ) : ?>
						<tr>
							<td><?php echo esc_html( $meta['title'] ); ?></td>
							<td><code><?php echo esc_html( $gw_id ); ?></code></td>
							<td>
								<?php
								if ( $meta['enabled'] ) {
									esc_html_e( 'yes', 'fraud-shield' );
								} elseif ( isset( $d['seen'][ $gw_id ] ) ) {
									// Disabled in wp-admin yet served at checkout —
									// another plugin re-enables it on the storefront.
									esc_html_e( 'at checkout', 'fraud-shield' );
								} else {
									echo '<span class="fs-muted">' . esc_html__( 'no', 'fraud-shield' ) . '</span>';
								}
								?>
							</td>
							<td>
								<?php
								if ( ! in_array( (string) $gw_id, $d['active'], true ) ) {
									echo '<span class="fs-muted">' . esc_html__( 'n/a', 'fraud-shield' ) . '</span>';
								} elseif ( in_array( (string) $gw_id, $d['shown'], true ) ) {
									echo '<strong>' . esc_html__( 'allowed', 'fraud-shield' ) . '</strong>';
								} else {
									echo esc_html__( 'withheld', 'fraud-shield' );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php foreach ( array_diff_key( $d['seen'], $d['registered'] ) as $gw_id => $gw_title ) : ?>
						<tr>
							<td><?php echo esc_html( $gw_title ); ?></td>
							<td><code><?php echo esc_html( $gw_id ); ?></code></td>
							<td><?php esc_html_e( 'at checkout', 'fraud-shield' ); ?></td>
							<td>
								<?php
								if ( in_array( (string) $gw_id, $d['shown'], true ) ) {
									echo '<strong>' . esc_html__( 'allowed', 'fraud-shield' ) . '</strong>';
								} else {
									echo esc_html__( 'withheld', 'fraud-shield' );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Rows marked "at checkout" carry ids recorded on real checkout renders — including gateways another plugin (a payment rotator, for example) registers on the storefront only. Every checklist on this page offers these ids too, so the allow-lists and the low-value allowance can name exactly what customers are served.', 'fraud-shield' ); ?></p>
			<?php if ( count( $d['seen'] ) === 1 ) : ?>
				<p class="description"><?php esc_html_e( 'Only one id has ever been seen at checkout. If several processors are rotated behind that single id, Fraud Shield cannot tell them apart: an allow-list works on gateway ids, so the rotator would need to expose one id per processor for this rule to separate them.', 'fraud-shield' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Say where a customer stands against the automatic verification rule, and
	 * why. Staff otherwise have no way to tell whether someone is skipping the
	 * automatic controls because of their trading history.
	 *
	 * @param int $uid User id.
	 */
	private function render_auto_verify_status( $uid ) {
		$uid  = (int) $uid;
		$user = $uid > 0 ? get_userdata( $uid ) : null;
		if ( ! $user ) {
			return;
		}

		$st = FS_Live::auto_verify_status( $uid, FS_Normalizer::email( $user->user_email ) );
		$days = ( null === $st['age_days'] ) ? null : (int) floor( $st['age_days'] );

		if ( ! empty( $st['qualifies'] ) ) {
			echo '<div class="notice notice-success inline"><p><strong>' . esc_html__( 'Automatically verified.', 'fraud-shield' ) . '</strong> ';
			printf(
				/* translators: 1: days as a customer, 2: number of paid orders */
				esc_html__( 'A customer for %1$s with %2$s and no chargebacks or refunds, so they are exempt from the gateway restriction, the velocity lock, and the new-account hold. The blocklist still applies.', 'fraud-shield' ),
				esc_html( sprintf( /* translators: %s: number of days */ _n( '%s day', '%s days', (int) $days, 'fraud-shield' ), number_format_i18n( (int) $days ) ) ),
				esc_html( sprintf( /* translators: %s: number of paid orders */ _n( '%s paid order', '%s paid orders', $st['paid_orders'], 'fraud-shield' ), number_format_i18n( $st['paid_orders'] ) ) )
			);
			echo '</p></div>';
			return;
		}

		// Only explain a near-miss; a brand-new account failing the rule is not
		// news worth a panel.
		if ( 'not_clean' === $st['reason'] ) {
			echo '<p class="description"><strong>' . esc_html__( 'Not automatically verified:', 'fraud-shield' ) . '</strong> ';
			printf(
				/* translators: 1: chargeback count, 2: refund count */
				esc_html__( 'their history shows %1$s and %2$s, which disqualifies them permanently unless you verify them by hand.', 'fraud-shield' ),
				esc_html( sprintf( /* translators: %s: number of chargebacks */ _n( '%s chargeback', '%s chargebacks', $st['chargebacks'], 'fraud-shield' ), number_format_i18n( $st['chargebacks'] ) ) ),
				esc_html( sprintf( /* translators: %s: number of refunds */ _n( '%s refund', '%s refunds', $st['refunds'], 'fraud-shield' ), number_format_i18n( $st['refunds'] ) ) )
			);
			echo '</p>';
			return;
		}
		if ( 'disabled' === $st['reason'] ) {
			echo '<p class="description">' . esc_html__( 'This customer would be automatically verified on their history, but automatic verification is switched off in Settings.', 'fraud-shield' ) . '</p>';
		}
	}

	private function render_per_user_gateway_editor( $uid ) {
		$uid       = (int) $uid;
		$current   = FS_Live::per_user_gateway_restriction( $uid );
		$gw_ids    = isset( $current['gateways'] ) ? array_map( 'strval', (array) $current['gateways'] ) : array();
		$low_amt   = isset( $current['lowvalue_amount'] ) ? (float) $current['lowvalue_amount'] : 0;
		$low_ids   = isset( $current['lowvalue_gateways'] ) ? array_map( 'strval', (array) $current['lowvalue_gateways'] ) : array();
		$choices   = $this->gateway_choices( array_merge( $gw_ids, $low_ids ) );
		$save_url  = admin_url( 'admin-post.php' );
		$clear_url = wp_nonce_url( admin_url( 'admin-post.php?action=fs_gw_restrict&clear=1&user=' . $uid ), 'fs_gw_restrict' );
		?>
		<div class="fs-panel">
			<h2><?php esc_html_e( 'Payment gateways for this customer', 'fraud-shield' ); ?>
				<?php if ( ! empty( $gw_ids ) ) : ?><span class="fs-pill"><?php esc_html_e( 'Restricted', 'fraud-shield' ); ?></span><?php endif; ?>
			</h2>
			<?php $this->render_auto_verify_status( $uid ); ?>
			<p class="description"><?php esc_html_e( 'Allow this customer to check out, but only with the payment methods you tick. Optionally re-enable a gateway for small orders via the under-$X allowance (off unless an amount is set). Saving releases them from the velocity lock and any hold — WITHOUT marking them Verified — so they can order again on the allowed methods only. They appear on the Restricted checkout list on Checkout Locks. Clear the restriction to return them to normal rules.', 'fraud-shield' ); ?></p>
			<?php
			// A restriction is void the moment the customer counts as verified, so
			// say that up front rather than letting staff apply a limit that
			// silently does nothing.
			$gwe_void = FS_Live::gw_restrict_void_reason( $uid, array( 'gateways' => array( 'probe' ) ) );
			if ( '' !== $gwe_void ) {
				echo '<div class="notice notice-warning inline"><p>';
				if ( 'idv' === $gwe_void ) {
					esc_html_e( 'This customer has passed ID verification, so a gateway restriction will not apply to them. Revoke their ID verification first if you need to restrict their payment methods.', 'fraud-shield' );
				} else {
					esc_html_e( 'This customer is verified (account flag, allowlisted email, or verified credential), so a gateway restriction will not apply to them. Remove their verification first if you need to restrict their payment methods.', 'fraud-shield' );
				}
				echo '</p></div>';
			}

			// Where this customer's probation stands, so staff never have to guess
			// whether a restriction is still doing anything.
			if ( ! empty( $gw_ids ) ) {
				$gwe_expires = FS_Live::gw_restrict_expires_at( $current );
				$gwe_applied = isset( $current['applied_at'] ) ? (int) $current['applied_at'] : 0;
				echo '<p class="description">';
				if ( $gwe_applied > 0 ) {
					printf(
						/* translators: %s: date the restriction was applied */
						esc_html__( 'Applied %s.', 'fraud-shield' ),
						esc_html( date_i18n( 'Y-m-d', $gwe_applied ) )
					);
					echo ' ';
				}
				if ( $gwe_expires > 0 ) {
					$gwe_days = (int) ceil( ( $gwe_expires - time() ) / DAY_IN_SECONDS );
					printf(
						/* translators: 1: date the restriction lapses, 2: number of days remaining */
						esc_html__( 'Lapses on its own %1$s (in %2$s).', 'fraud-shield' ),
						esc_html( date_i18n( 'Y-m-d', $gwe_expires ) ),
						esc_html( sprintf( /* translators: %s: number of days */ _n( '%s day', '%s days', $gwe_days, 'fraud-shield' ), number_format_i18n( $gwe_days ) ) )
					);
				} else {
					esc_html_e( 'It does not lapse on its own.', 'fraud-shield' );
				}
				if ( ! empty( FS_Core::instance()->settings()['gw_restrict_release_on_idv'] ) ) {
					echo ' ' . esc_html__( 'An approved ID verification ends it early.', 'fraud-shield' );
				}
				echo '</p>';
			}
			?>
			<?php if ( empty( $choices ) ) : ?>
				<p class="description"><?php esc_html_e( 'No payment gateways are registered.', 'fraud-shield' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( $save_url ); ?>">
					<?php wp_nonce_field( 'fs_gw_restrict' ); ?>
					<input type="hidden" name="action" value="fs_gw_restrict" />
					<input type="hidden" name="user" value="<?php echo esc_attr( $uid ); ?>" />
					<table class="widefat striped" style="max-width:640px;">
						<thead><tr>
							<th><?php esc_html_e( 'Gateway', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Allowed', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Allowed under $X', 'fraud-shield' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $choices as $gid => $meta ) : ?>
							<tr>
								<td><?php echo esc_html( $meta['title'] ); ?> <span class="fs-muted">(<?php echo esc_html( $gid ); ?>)</span><?php echo '' === $meta['note'] ? '' : ' <span class="fs-muted">(' . esc_html( $meta['note'] ) . ')</span>'; ?></td>
								<td><input type="checkbox" name="gateways[]" value="<?php echo esc_attr( $gid ); ?>" <?php checked( in_array( (string) $gid, $gw_ids, true ) ); ?> /></td>
								<td><input type="checkbox" name="low_gateways[]" value="<?php echo esc_attr( $gid ); ?>" <?php checked( in_array( (string) $gid, $low_ids, true ) ); ?> /></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p>
						<label><?php esc_html_e( 'Under-$X amount', 'fraud-shield' ); ?>
							<input type="number" name="low_amount" min="0" step="0.01" value="<?php echo esc_attr( $low_amt ); ?>" style="width:120px;" />
						</label>
						<button class="button button-primary"><?php esc_html_e( 'Save & unlock customer', 'fraud-shield' ); ?></button>
						<?php if ( ! empty( $gw_ids ) ) : ?>
							<a class="button" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Clear restriction', 'fraud-shield' ); ?></a>
						<?php endif; ?>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Full profile for one registered customer: account details, identity
	 * consistency checks, IPs, shipping addresses, and every order.
	 *
	 * @param int $uid User id.
	 */
	private function render_customer_profile( $uid ) {
		$list_url = admin_url( 'admin.php?page=' . self::SLUG . '-new-customers' );
		$user     = get_userdata( $uid );
		if ( ! $user ) {
			echo '<div class="wrap fs-wrap"><h1>' . esc_html__( 'Customer profile', 'fraud-shield' ) . '</h1>';
			echo '<p>' . esc_html__( 'That user no longer exists.', 'fraud-shield' ) . '</p>';
			echo '<a class="button" href="' . esc_url( $list_url ) . '">&larr; ' . esc_html__( 'Back to New Customers', 'fraud-shield' ) . '</a></div>';
			return;
		}

		$summary  = FS_Data::customer_summary( $uid );
		$identity = FS_Data::customer_identity( $uid );
		$ips      = FS_Data::customer_ips( $uid );
		$addrs    = FS_Data::customer_addresses( $uid );
		$settings = FS_Core::instance()->settings();

		$per    = 50;
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$offset = ( $paged - 1 ) * $per;
		$orders = FS_Data::customer_orders( $uid, $per, $offset );
		$ototal = (int) ( $summary['orders'] ?? 0 );
		$pages  = max( 1, (int) ceil( $ototal / $per ) );

		// Account (saved) details.
		$customer      = new WC_Customer( $uid );
		$acct_email    = $user->user_email;
		$acct_email_nm = FS_Normalizer::email( $acct_email );
		$acct_name     = trim( $customer->get_first_name() . ' ' . $customer->get_last_name() );
		if ( '' === $acct_name ) {
			$acct_name = $user->display_name;
		}
		$acct_phone   = FS_Normalizer::phone( $customer->get_billing_phone() );
		$acct_bill    = array(
			'address_1' => $customer->get_billing_address_1(),
			'address_2' => $customer->get_billing_address_2(),
			'city'      => $customer->get_billing_city(),
			'state'     => $customer->get_billing_state(),
			'postcode'  => $customer->get_billing_postcode(),
			'country'   => $customer->get_billing_country(),
		);
		$acct_addr_hash = FS_Normalizer::hash( FS_Normalizer::address( $acct_bill ) );
		$ships_to_bill  = ( '' !== $acct_addr_hash && in_array( $acct_addr_hash, wp_list_pluck( $addrs, 'ship_hash' ), true ) );

		$age_days = max( 0, (int) floor( ( time() - strtotime( $user->user_registered . ' UTC' ) ) / DAY_IN_SECONDS ) );
		$opm      = $ototal / max( 1, $age_days ) * 30;

		$block_email_url = wp_nonce_url( admin_url( 'admin-post.php?action=fs_blocklist_add&type=email&value=' . rawurlencode( $acct_email ) . '&reason=' . rawurlencode( __( 'Blocked from customer profile', 'fraud-shield' ) ) ), 'fs_blocklist_add' );
		$block_user_url  = wp_nonce_url( admin_url( 'admin-post.php?action=fs_blocklist_add&type=user&value=' . (int) $uid . '&reason=' . rawurlencode( __( 'Blocked from customer profile', 'fraud-shield' ) ) ), 'fs_blocklist_add' );

		$verified   = ( 'yes' === get_user_meta( $uid, '_fs_verified', true ) );
		$locked     = FS_Live::is_velocity_locked( $uid, '' );
		$verify_url = wp_nonce_url( admin_url( 'admin-post.php?action=fs_verify_customer&user=' . (int) $uid . '&to=' . ( $verified ? '0' : '1' ) ), 'fs_verify_customer' );
		?>
		<div class="wrap fs-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Customer profile', 'fraud-shield' ); ?></h1>
			<a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action">&larr; <?php esc_html_e( 'Back to New Customers', 'fraud-shield' ); ?></a>
			<a href="<?php echo esc_url( $this->profile_url( $acct_email_nm ) ); ?>" class="page-title-action"><?php esc_html_e( 'Intelligence profile', 'fraud-shield' ); ?></a>
			<a href="<?php echo esc_url( get_edit_user_link( $uid ) ); ?>" class="page-title-action"><?php esc_html_e( 'Edit user', 'fraud-shield' ); ?></a>
			<a href="<?php echo esc_url( $verify_url ); ?>" class="page-title-action"><?php echo $verified ? esc_html__( 'Revoke verification', 'fraud-shield' ) : esc_html__( 'Mark verified (unlock)', 'fraud-shield' ); ?></a>
			<a href="<?php echo esc_url( $block_email_url ); ?>" class="page-title-action" onclick="return confirm('<?php echo esc_js( __( 'Block this email at checkout?', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Block email', 'fraud-shield' ); ?></a>
			<a href="<?php echo esc_url( $block_user_url ); ?>" class="page-title-action" onclick="return confirm('<?php echo esc_js( __( 'Block this user account at checkout?', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Block account', 'fraud-shield' ); ?></a>
			<?php echo $this->idv_request_button( $uid ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- built from escaped parts. ?>

			<h2 class="fs-detail-title"><?php echo esc_html( $acct_name ); ?> &lt;<?php echo esc_html( $acct_email ); ?>&gt;</h2>
			<p class="description">
			<?php
			/* translators: 1: registration date, 2: age in days, 3: orders per month */
			printf(
				esc_html__( 'Registered %1$s (%2$s days ago) · about %3$s orders/month', 'fraud-shield' ),
				esc_html( mysql2date( 'M j, Y', $user->user_registered ) ),
				esc_html( number_format_i18n( $age_days ) ),
				esc_html( number_format_i18n( $opm, 1 ) )
			);
			?>
			</p>
			<?php $this->maybe_notice(); ?>

			<?php if ( $verified ) : ?>
				<div class="notice notice-success inline"><p><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'This customer is verified and is exempt from the velocity lock, holds, and gateway restrictions.', 'fraud-shield' ); ?></p></div>
			<?php elseif ( $locked ) : ?>
				<div class="notice notice-error inline"><p><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'This customer is currently locked at checkout by the velocity rule, pending manual verification. Use “Mark verified” above to unlock them.', 'fraud-shield' ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_refusals_panel( $uid, $acct_email_nm ); ?>

			<?php $this->render_per_user_gateway_editor( $uid ); ?>

			<?php $this->render_idv_panel( $uid, $acct_email_nm ); ?>

			<div class="fs-cards">
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $ototal ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Orders', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-amber"><div class="fs-num"><?php echo wp_kses_post( wc_price( (float) ( $summary['total_spend'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Total spend (paid)', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-red"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) ( $summary['chargebacks'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Chargebacks', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) ( $summary['ips'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Distinct IPs', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) ( $summary['addresses'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Shipping addresses', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) ( $summary['emails'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Billing emails', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo (int) ( $summary['max_risk'] ?? 0 ); ?></div><div class="fs-label"><?php esc_html_e( 'Max risk', 'fraud-shield' ); ?></div></div>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Identity &amp; consistency', 'fraud-shield' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Green check = matches the account on file. Amber = differs (a common fraud tell when one account orders under several names, emails, or never ships to its own billing address).', 'fraud-shield' ); ?></p>
				<div class="fs-detail-grid">
					<div class="fs-detail-col">
						<h4><?php esc_html_e( 'Account on file', 'fraud-shield' ); ?></h4>
						<ul class="fs-kv">
							<li><span><?php esc_html_e( 'Name', 'fraud-shield' ); ?></span> <?php echo esc_html( $acct_name ? $acct_name : '—' ); ?></li>
							<li><span><?php esc_html_e( 'Account email', 'fraud-shield' ); ?></span> <?php echo esc_html( $acct_email ); ?></li>
							<li><span><?php esc_html_e( 'Billing phone', 'fraud-shield' ); ?></span> <?php echo esc_html( $customer->get_billing_phone() ? $customer->get_billing_phone() : '—' ); ?></li>
							<li><span><?php esc_html_e( 'Billing address', 'fraud-shield' ); ?></span> <?php echo esc_html( trim( $acct_bill['address_1'] . ' ' . $acct_bill['address_2'] . ', ' . $acct_bill['city'] . ' ' . $acct_bill['state'] . ' ' . $acct_bill['postcode'] . ' ' . $acct_bill['country'], ' ,' ) ?: '—' ); ?></li>
							<li><span><?php esc_html_e( 'Ships to own billing address', 'fraud-shield' ); ?></span> <?php echo $ships_to_bill ? $this->match_badge( true ) . ' ' . esc_html__( 'Yes', 'fraud-shield' ) : $this->match_badge( false ) . ' ' . esc_html__( 'No', 'fraud-shield' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
						</ul>
					</div>
					<div class="fs-detail-col">
						<h4><?php esc_html_e( 'Emails used on orders', 'fraud-shield' ); ?></h4>
						<ul class="fs-badge-list">
							<?php
							if ( empty( $identity['emails'] ) ) {
								echo '<li>—</li>';
							}
							foreach ( $identity['emails'] as $em ) {
								echo '<li>' . $this->match_badge( $em === $acct_email_nm ) . ' ' . esc_html( $em ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
						</ul>
						<h4><?php esc_html_e( 'Names used on orders', 'fraud-shield' ); ?></h4>
						<ul class="fs-badge-list">
							<?php
							$acct_name_cmp = strtolower( trim( $acct_name ) );
							if ( empty( $identity['names'] ) ) {
								echo '<li>—</li>';
							}
							foreach ( $identity['names'] as $nm ) {
								echo '<li>' . $this->match_badge( strtolower( trim( $nm ) ) === $acct_name_cmp ) . ' ' . esc_html( $nm ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
						</ul>
						<h4><?php esc_html_e( 'Phones used on orders', 'fraud-shield' ); ?></h4>
						<ul class="fs-badge-list">
							<?php
							if ( empty( $identity['phones'] ) ) {
								echo '<li>—</li>';
							}
							foreach ( $identity['phones'] as $ph ) {
								if ( '' !== $acct_phone ) {
									echo '<li>' . $this->match_badge( $ph === $acct_phone ) . ' ' . esc_html( $ph ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
								} else {
									echo '<li>' . esc_html( $ph ) . '</li>';
								}
							}
							?>
						</ul>
					</div>
				</div>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'IP addresses used', 'fraud-shield' ); ?></h2>
				<?php if ( empty( $ips ) ) : ?>
					<p><?php esc_html_e( 'No IP addresses recorded.', 'fraud-shield' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat striped">
						<thead><tr><th><?php esc_html_e( 'IP', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Orders', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'IP location', 'fraud-shield' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $ips as $ip ) : ?>
							<?php
							$ip_url = add_query_arg( array( 'page' => self::SLUG, 'fs_view' => 'ip', 'fs_key' => $ip['ip'] ), admin_url( 'admin.php' ) );
							$ip_loc = $this->ip_location_label( $ip['ip'] );
							?>
							<tr>
								<td><?php echo esc_html( $ip['ip'] ); ?></td>
								<td><?php echo (int) $ip['c']; ?></td>
								<td>
									<?php
									if ( '' !== $ip_loc ) {
										echo esc_html( $ip_loc );
									} elseif ( ! empty( $ip['country'] ) ) {
										echo esc_html( $ip['country'] ) . ' <span class="fs-muted">' . esc_html__( '(from order)', 'fraud-shield' ) . '</span>';
									} else {
										esc_html_e( 'Unknown', 'fraud-shield' );
									}
									?>
								</td>
								<td><a class="button button-small" href="<?php echo esc_url( $ip_url ); ?>"><?php esc_html_e( 'See everyone on this IP', 'fraud-shield' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Shipping addresses used', 'fraud-shield' ); ?></h2>
				<?php if ( empty( $addrs ) ) : ?>
					<p><?php esc_html_e( 'No shipping addresses recorded.', 'fraud-shield' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat striped">
						<thead><tr><th><?php esc_html_e( 'Shipping address', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Orders', 'fraud-shield' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $addrs as $ad ) : ?>
							<?php $ad_url = add_query_arg( array( 'page' => self::SLUG, 'fs_view' => 'address', 'fs_key' => $ad['ship_hash'] ), admin_url( 'admin.php' ) ); ?>
							<tr>
								<td><?php echo esc_html( $ad['ship_text'] ? $ad['ship_text'] : substr( $ad['ship_hash'], 0, 16 ) . '…' ); ?></td>
								<td><?php echo (int) $ad['c']; ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( $ad_url ); ?>"><?php esc_html_e( 'See everyone at this address', 'fraud-shield' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Orders', 'fraud-shield' ); ?></h2>
				<?php $this->render_orders_table( $orders ); ?>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav"><div class="tablenav-pages">
					<?php
					$base = add_query_arg(
						array(
							'page'        => self::SLUG . '-new-customers',
							'fs_customer' => $uid,
						),
						admin_url( 'admin.php' )
					);
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => $base . '%_%',
								'format'    => '&paged=%#%',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
					</div></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* ===================== Repeat Customers ===================== */

	/**
	 * Repeat Customers report: registered customers who placed more than one
	 * order in a chosen time window, newest activity first, each linking to the
	 * full customer profile.
	 */
	public function page_flagged() {
		$this->guard();

		$period  = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : '1m';       // phpcs:ignore WordPress.Security.NonceVerification
		$min     = isset( $_GET['min_orders'] ) ? max( 2, (int) $_GET['min_orders'] ) : 2;                // phpcs:ignore WordPress.Security.NonceVerification
		$from_in = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';      // phpcs:ignore WordPress.Security.NonceVerification
		$to_in   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';          // phpcs:ignore WordPress.Security.NonceVerification
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';            // phpcs:ignore WordPress.Security.NonceVerification
		$sort    = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'last_order';   // phpcs:ignore WordPress.Security.NonceVerification
		$dir     = ( isset( $_GET['dir'] ) && 'asc' === $_GET['dir'] ) ? 'asc' : 'desc';                  // phpcs:ignore WordPress.Security.NonceVerification
		list( $sort2, $dir2 ) = $this->sort2_from_request();
		$this->cur_sort2      = $sort2;
		$this->cur_dir2       = $dir2;
		$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;                          // phpcs:ignore WordPress.Security.NonceVerification
		$per     = 50;
		$offset  = ( $paged - 1 ) * $per;

		$now = time();
		if ( 'custom' === $period && ( '' !== $from_in || '' !== $to_in ) ) {
			$from = $from_in ? gmdate( 'Y-m-d 00:00:00', strtotime( $from_in ) ) : '1970-01-01 00:00:00';
			$to   = $to_in ? gmdate( 'Y-m-d 23:59:59', strtotime( $to_in ) ) : gmdate( 'Y-m-d H:i:s', $now );
		} else {
			$months = ( '3m' === $period ) ? 3 : ( ( '2m' === $period ) ? 2 : 1 );
			$from   = gmdate( 'Y-m-d H:i:s', $now - $months * 30 * DAY_IN_SECONDS );
			$to     = gmdate( 'Y-m-d H:i:s', $now );
		}

		$total = FS_Data::repeat_customers_count( $from, $to, $min, $search );
		$rows  = FS_Data::repeat_customers(
			array(
				'from'       => $from,
				'to'         => $to,
				'min_orders' => $min,
				'orderby'    => $sort,
				'dir'        => $dir,
				'orderby2'   => $sort2,
				'dir2'       => $dir2,
				'search'     => $search,
				'limit'      => $per,
				'offset'     => $offset,
			)
		);
		$pages = max( 1, (int) ceil( $total / $per ) );

		// Batch lock-state for the row Block toggles (no N+1).
		$rc_emails = array();
		foreach ( (array) $rows as $r ) {
			$rc_emails[] = FS_Normalizer::email( $r['user_email'] );
		}
		$rc_actions = FS_Blocklist::actions_for_targets( 'profile', $rc_emails );
		$rc_blocked = FS_Blocklist::blocked_values( 'email', $rc_emails );
		$vcreds     = FS_Live::verified_creds();

		// Base args every sort/pagination link must preserve.
		$base_args = array(
			'page'       => self::SLUG . '-flagged',
			'period'     => $period,
			'from'       => $from_in,
			'to'         => $to_in,
			'min_orders' => $min,
			's'          => $search,
			'sort2'      => $sort2,
			'dir2'       => $dir2,
		);
		?>
		<div class="wrap fs-wrap">
			<h1><?php esc_html_e( 'Repeat Customers', 'fraud-shield' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Registered customers who placed more than one order in the selected period — newest activity first. Click a customer for their full profile: every order with items, payment method, billing/shipping details, IP and location.', 'fraud-shield' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-flagged' ); ?>" />
				<p class="fs-filters">
					<label><?php esc_html_e( 'Period', 'fraud-shield' ); ?>
						<select name="period">
							<?php
							$popts = array(
								'1m'     => __( 'Last 1 month', 'fraud-shield' ),
								'2m'     => __( 'Last 2 months', 'fraud-shield' ),
								'3m'     => __( 'Last 3 months', 'fraud-shield' ),
								'custom' => __( 'Custom range', 'fraud-shield' ),
							);
							foreach ( $popts as $k => $lbl ) {
								echo '<option value="' . esc_attr( $k ) . '"' . selected( $period, $k, false ) . '>' . esc_html( $lbl ) . '</option>';
							}
							?>
						</select>
					</label>
					<label><?php esc_html_e( 'From', 'fraud-shield' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from_in ); ?>" /></label>
					<label><?php esc_html_e( 'To', 'fraud-shield' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to_in ); ?>" /></label>
					<label><?php esc_html_e( 'Min orders', 'fraud-shield' ); ?> <input type="number" name="min_orders" min="2" value="<?php echo (int) $min; ?>" class="small-text" /></label>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, phone, IP, address…', 'fraud-shield' ); ?>" class="regular-text" />
					<button class="button"><?php esc_html_e( 'Filter', 'fraud-shield' ); ?></button>
					<a class="button" href="<?php echo esc_url( $this->export_url( 'repeat', array( 'period' => $period, 'from' => $from_in, 'to' => $to_in, 'min_orders' => $min, 's' => $search, 'sort' => $sort, 'dir' => $dir ) ) ); ?>"><?php esc_html_e( 'Export CSV (Excel)', 'fraud-shield' ); ?></a>
				</p>
				<p class="description"><?php esc_html_e( 'Pick “Custom range” to use the From / To dates. Only orders whose payment succeeded (plus chargebacks) are counted. Click any column header to sort; click again to flip the direction.', 'fraud-shield' ); ?></p>
			</form>

			<p class="description">
			<?php
			printf(
				/* translators: 1: number of customers, 2: window start date, 3: window end date */
				esc_html( _n( '%1$s customer placed more than one successful order between %2$s and %3$s.', '%1$s customers placed more than one successful order between %2$s and %3$s.', $total, 'fraud-shield' ) ),
				esc_html( number_format_i18n( $total ) ),
				esc_html( mysql2date( 'M j, Y', $from ) ),
				esc_html( mysql2date( 'M j, Y', $to ) )
			);
			?>
			</p>

			<table class="wp-list-table widefat striped">
				<thead><tr>
					<?php
					$this->sort_th( __( 'Customer', 'fraud-shield' ), 'name', $sort, $dir, $base_args );
					$this->sort_th( __( 'Account email', 'fraud-shield' ), 'email', $sort, $dir, $base_args );
					$this->sort_th( __( 'Registered', 'fraud-shield' ), 'registered', $sort, $dir, $base_args );
					$this->sort_th( __( 'Orders in period', 'fraud-shield' ), 'orders', $sort, $dir, $base_args );
					$this->sort_th( __( 'Spend (paid)', 'fraud-shield' ), 'spend', $sort, $dir, $base_args );
					$this->sort_th( __( 'CB', 'fraud-shield' ), 'chargebacks', $sort, $dir, $base_args );
					$this->sort_th( __( 'Latest payment', 'fraud-shield' ), 'payment', $sort, $dir, $base_args );
					$this->sort_th( __( 'Latest IP / location', 'fraud-shield' ), 'ip', $sort, $dir, $base_args );
					$this->sort_th( __( 'Last order', 'fraud-shield' ), 'last_order', $sort, $dir, $base_args );
					?>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="10"><?php esc_html_e( 'No repeat customers in this period. Widen the window, or run a scan to index more orders.', 'fraud-shield' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<?php
						$cid     = (int) $r['user_id'];
						$profile = add_query_arg(
							array(
								'page'        => self::SLUG . '-new-customers',
								'fs_customer' => $cid,
							),
							admin_url( 'admin.php' )
						);
						$loc     = $this->ip_location_label( $r['latest_ip'] );
						?>
						<tr<?php echo ( (int) $r['chargebacks'] > 0 ) ? ' class="fs-row-flag"' : ''; ?>>
							<td><a href="<?php echo esc_url( $profile ); ?>"><strong><?php echo esc_html( $r['customer_name'] ? $r['customer_name'] : $r['display_name'] ); ?></strong></a></td>
							<td><?php echo esc_html( $r['user_email'] ); ?></td>
							<td><?php echo esc_html( mysql2date( 'Y-m-d', $r['user_registered'] ) ); ?></td>
							<td><strong><?php echo (int) $r['orders']; ?></strong></td>
							<td><?php echo wp_kses_post( wc_price( (float) $r['spend'] ) ); ?></td>
							<td><?php echo (int) $r['chargebacks'] ? '<span class="fs-pill fs-pill-red">' . (int) $r['chargebacks'] . '</span>' : '—'; ?></td>
							<td><?php echo esc_html( ! empty( $r['latest_payment'] ) ? $r['latest_payment'] : '—' ); ?></td>
							<td>
								<?php
								echo esc_html( $r['latest_ip'] ? $r['latest_ip'] : '—' );
								if ( '' !== $loc ) {
									echo '<br/><span class="fs-muted">' . esc_html( $loc ) . '</span>';
								}
								?>
							</td>
							<td><?php echo esc_html( mysql2date( 'Y-m-d', $r['last_order'] ) ); ?></td>
							<td>
								<a class="button button-small button-primary" href="<?php echo esc_url( $profile ); ?>"><?php esc_html_e( 'Profile', 'fraud-shield' ); ?></a>
								<?php
								$rc_email = FS_Normalizer::email( $r['user_email'] );
								echo $this->block_profile_button( $rc_email, isset( $rc_actions[ $rc_email ] ) ? $rc_actions[ $rc_email ] : null, in_array( $rc_email, $rc_blocked, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								echo ' ' . $this->verify_button( 'email', $rc_email, isset( $vcreds[ 'email:' . $rc_email ] ) || 'yes' === get_user_meta( $cid, '_fs_verified', true ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
				<?php
				$base = add_query_arg(
					array_merge(
						$base_args,
						array(
							'sort' => $sort,
							'dir'  => $dir,
						)
					),
					admin_url( 'admin.php' )
				);
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => $base . '%_%',
							'format'    => '&paged=%#%',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ===================== Customer Profiles ===================== */

	/**
	 * URL of an identity's intelligence profile.
	 *
	 * @param string $email Normalized email.
	 * @return string
	 */
	private function profile_url( $email ) {
		return add_query_arg(
			array(
				'page'       => self::SLUG . '-profiles',
				'fs_profile' => rawurlencode( $email ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Compress a raw browser user-agent into a readable device signature.
	 *
	 * @param string $ua User agent.
	 * @return string e.g. "Chrome on Windows".
	 */
	private function device_label( $ua ) {
		$ua = (string) $ua;
		if ( '' === $ua ) {
			return __( 'Unknown device', 'fraud-shield' );
		}
		$browser = __( 'Other browser', 'fraud-shield' );
		if ( false !== stripos( $ua, 'Edg/' ) || false !== stripos( $ua, 'Edge/' ) ) {
			$browser = 'Edge';
		} elseif ( false !== stripos( $ua, 'OPR/' ) || false !== stripos( $ua, 'Opera' ) ) {
			$browser = 'Opera';
		} elseif ( false !== stripos( $ua, 'Chrome/' ) ) {
			$browser = 'Chrome';
		} elseif ( false !== stripos( $ua, 'Firefox/' ) ) {
			$browser = 'Firefox';
		} elseif ( false !== stripos( $ua, 'Safari/' ) ) {
			$browser = 'Safari';
		}
		$os = __( 'other OS', 'fraud-shield' );
		if ( false !== stripos( $ua, 'Windows' ) ) {
			$os = 'Windows';
		} elseif ( false !== stripos( $ua, 'Android' ) ) {
			$os = 'Android';
		} elseif ( false !== stripos( $ua, 'iPhone' ) || false !== stripos( $ua, 'iPad' ) || false !== stripos( $ua, 'iOS' ) ) {
			$os = 'iOS';
		} elseif ( false !== stripos( $ua, 'Mac OS' ) || false !== stripos( $ua, 'Macintosh' ) ) {
			$os = 'macOS';
		} elseif ( false !== stripos( $ua, 'Linux' ) ) {
			$os = 'Linux';
		}
		/* translators: 1: browser name, 2: operating system */
		return sprintf( __( '%1$s on %2$s', 'fraud-shield' ), $browser, $os );
	}

	/**
	 * Humanize a duration in seconds (e.g. "2.4 days", "5.1 hours").
	 *
	 * @param float $seconds Seconds.
	 * @return string
	 */
	private function human_interval( $seconds ) {
		if ( $seconds >= 1.5 * DAY_IN_SECONDS ) {
			/* translators: %s: number of days */
			return sprintf( __( '%s days', 'fraud-shield' ), number_format_i18n( $seconds / DAY_IN_SECONDS, 1 ) );
		}
		if ( $seconds >= HOUR_IN_SECONDS ) {
			/* translators: %s: number of hours */
			return sprintf( __( '%s hours', 'fraud-shield' ), number_format_i18n( $seconds / HOUR_IN_SECONDS, 1 ) );
		}
		/* translators: %s: number of minutes */
		return sprintf( __( '%s minutes', 'fraud-shield' ), number_format_i18n( max( 1, $seconds / MINUTE_IN_SECONDS ), 0 ) );
	}

	/**
	 * Profiles page: one entry for every customer identity found in orders
	 * (registered or guest), searchable and sortable, each opening a full
	 * intelligence profile.
	 */
	public function page_profiles() {
		$this->guard();

		$profile = isset( $_GET['fs_profile'] ) ? sanitize_text_field( wp_unslash( $_GET['fs_profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $profile ) {
			$this->render_profile_detail( FS_Normalizer::email( $profile ) );
			return;
		}

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$sort   = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'last'; // phpcs:ignore WordPress.Security.NonceVerification
		$dir    = ( isset( $_GET['dir'] ) && 'asc' === $_GET['dir'] ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification
		list( $sort2, $dir2 )        = $this->sort2_from_request();
		$this->cur_sort2             = $sort2;
		$this->cur_dir2              = $dir2;
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$per    = 50;

		$total = FS_Data::profiles_count( $search );
		$rows  = FS_Data::profiles_list(
			array(
				'orderby'  => $sort,
				'dir'      => $dir,
				'orderby2' => $sort2,
				'dir2'     => $dir2,
				'search'   => $search,
				'limit'    => $per,
				'offset'   => ( $paged - 1 ) * $per,
			)
		);
		$pages = max( 1, (int) ceil( $total / $per ) );

		$base_args = array(
			'page'  => self::SLUG . '-profiles',
			's'     => $search,
			'sort2' => $sort2,
			'dir2'  => $dir2,
		);
		$blocked   = FS_Blocklist::blocked_values( 'email', wp_list_pluck( $rows, 'email_norm' ) );
		$actions   = FS_Blocklist::actions_for_targets( 'profile', wp_list_pluck( $rows, 'email_norm' ) );
		$verified  = FS_Live::verified_creds();
		?>
		<div class="wrap fs-wrap">
			<h1><?php esc_html_e( 'Customer Profiles', 'fraud-shield' ); ?></h1>
			<?php $this->maybe_notice(); ?>
			<p class="description"><?php esc_html_e( 'Every customer identity seen in your orders — registered or guest — with a full intelligence profile: linked accounts, addresses, devices, coupon codes, payment methods, ordering cadence, and geo consistency. Come across a name anywhere? Search it here.', 'fraud-shield' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-profiles' ); ?>" />
				<p class="fs-filters">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, phone, IP, address…', 'fraud-shield' ); ?>" class="regular-text" />
					<button class="button"><?php esc_html_e( 'Search', 'fraud-shield' ); ?></button>
				</p>
			</form>
			<?php $this->export_range_form( 'profiles', array( 's' => $search, 'sort' => $sort, 'dir' => $dir ), __( 'Export all profiles (CSV)', 'fraud-shield' ) ); ?>

			<p class="description">
			<?php
			/* translators: %s: number of customer profiles */
			printf( esc_html( _n( '%s customer profile.', '%s customer profiles.', $total, 'fraud-shield' ) ), esc_html( number_format_i18n( $total ) ) );
			?>
			</p>

			<table class="wp-list-table widefat striped">
				<thead><tr>
					<?php
					$this->sort_th( __( 'Customer', 'fraud-shield' ), 'name', $sort, $dir, $base_args );
					$this->sort_th( __( 'Email', 'fraud-shield' ), 'email', $sort, $dir, $base_args );
					$this->sort_th( __( 'Registered', 'fraud-shield' ), 'registered', $sort, $dir, $base_args );
					$this->sort_th( __( 'Orders', 'fraud-shield' ), 'orders', $sort, $dir, $base_args );
					$this->sort_th( __( 'Paid', 'fraud-shield' ), 'paid', $sort, $dir, $base_args );
					$this->sort_th( __( 'Spend (paid)', 'fraud-shield' ), 'spend', $sort, $dir, $base_args );
					$this->sort_th( __( 'Avg order', 'fraud-shield' ), 'aov', $sort, $dir, $base_args );
					$this->sort_th( __( 'CB', 'fraud-shield' ), 'chargebacks', $sort, $dir, $base_args );
					$this->sort_th( __( 'IPs', 'fraud-shield' ), 'ips', $sort, $dir, $base_args );
					$this->sort_th( __( 'Addresses', 'fraud-shield' ), 'addresses', $sort, $dir, $base_args );
					$this->sort_th( __( 'Max risk', 'fraud-shield' ), 'risk', $sort, $dir, $base_args );
					$this->sort_th( __( 'Last order', 'fraud-shield' ), 'last', $sort, $dir, $base_args );
					?>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="13"><?php esc_html_e( 'No profiles yet. Run a scan or import a CSV to index your orders.', 'fraud-shield' ); ?></td></tr>
				<?php else : ?>
					<?php
					foreach ( $rows as $r ) :
						$purl        = $this->profile_url( $r['email_norm'] );
						$is_verified = isset( $verified[ 'email:' . $r['email_norm'] ] );
						$row_class   = $is_verified ? ' class="fs-row-verified"' : ( ( (int) $r['chargebacks'] > 0 ) ? ' class="fs-row-flag"' : '' );
						?>
						<tr<?php echo $row_class; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
							<td>
								<a href="<?php echo esc_url( $purl ); ?>"><strong><?php echo esc_html( $r['customer_name'] ? $r['customer_name'] : '—' ); ?></strong></a>
								<?php if ( $is_verified ) : ?>
									<span class="fs-verified-badge" title="<?php esc_attr_e( 'Verified customer — bypasses velocity lock, holds, and gateway restrictions.', 'fraud-shield' ); ?>">&#10004; <?php esc_html_e( 'Verified', 'fraud-shield' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $r['email_norm'] ); ?></td>
							<td><?php echo esc_html( ! empty( $r['user_registered'] ) ? mysql2date( 'Y-m-d', $r['user_registered'] ) : '—' ); ?></td>
							<td><?php echo (int) $r['orders']; ?></td>
							<td><?php echo (int) $r['paid_orders']; ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) $r['spend'] ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) $r['aov'] ) ); ?></td>
							<td><?php echo (int) $r['chargebacks'] ? '<span class="fs-pill fs-pill-red">' . (int) $r['chargebacks'] . '</span>' : '—'; ?></td>
							<td><?php echo (int) $r['ips']; ?></td>
							<td><?php echo (int) $r['addresses']; ?></td>
							<td><?php echo (int) $r['max_risk']; ?></td>
							<td><?php echo esc_html( $r['last_order'] ? mysql2date( 'Y-m-d', $r['last_order'] ) : '—' ); ?></td>
							<td>
								<a class="button button-small button-primary" href="<?php echo esc_url( $purl ); ?>"><?php esc_html_e( 'Profile', 'fraud-shield' ); ?></a>
								<?php echo $this->block_profile_button( $r['email_norm'], isset( $actions[ $r['email_norm'] ] ) ? $actions[ $r['email_norm'] ] : null, in_array( $r['email_norm'], $blocked, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php echo $this->verify_button( 'email', $r['email_norm'], $is_verified ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( array_merge( $base_args, array( 'sort' => $sort, 'dir' => $dir ) ), admin_url( 'admin.php' ) ) . '%_%',
							'format'    => '&paged=%#%',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A Block/Unblock toggle for a customer identity (profile).
	 *
	 * @param string     $email      Normalized email.
	 * @param array|null $action     Owning action info [ 'id'=>int, 'entries'=>int ] or null.
	 * @param bool       $is_blocked Whether the email is on the blocklist (legacy detection).
	 * @return string HTML.
	 */
	private function block_profile_button( $email, $action = null, $is_blocked = false ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=fs_block_profile&email=' . rawurlencode( $email ) ),
			'fs_block_profile'
		);
		return $this->toggle_button_html( $url, $action, $is_blocked, __( 'Block this profile? Their email, phones, IPs, addresses, and account will ALL be rejected at checkout.', 'fraud-shield' ) );
	}

	/* ----------------------------------------------------------------- *
	 *  ID verification (Didit) — shared profile UI
	 * ----------------------------------------------------------------- */

	/**
	 * The "Block checkout & request ID" toggle for a customer's profile.
	 *
	 * Account-scoped on purpose: the demand is stored against the account and
	 * only a logged-in customer can complete a verification, so there is
	 * nothing to hang it on for a guest identity.
	 *
	 * @param int $uid Account id (0 = guest identity).
	 * @return string HTML ('' when the action cannot apply).
	 */
	private function idv_request_button( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 || ! FS_IDV::configured() ) {
			return '';
		}

		$pending = FS_IDV::requires_verification( $uid );
		$url     = wp_nonce_url(
			admin_url( 'admin-post.php?action=fs_idv_require&user=' . $uid . '&to=' . ( $pending ? '0' : '1' ) ),
			'fs_idv_require'
		);

		if ( $pending ) {
			return '<a href="' . esc_url( $url ) . '" class="page-title-action fs-blocked-btn" title="'
				. esc_attr__( 'Checkout is blocked until this customer verifies their ID. Click to withdraw the request.', 'fraud-shield' ) . '" onclick="return confirm(\''
				. esc_js( __( 'Cancel the ID request and unblock checkout for this customer?', 'fraud-shield' ) ) . '\');">&#10004; '
				. esc_html__( 'ID requested — click to cancel', 'fraud-shield' ) . '</a>';
		}

		return '<a href="' . esc_url( $url ) . '" class="page-title-action" onclick="return confirm(\''
			. esc_js( __( 'Block this customer from checkout until they verify their ID? They will see your ID-requested message with a verify button, and unlock themselves as soon as they pass.', 'fraud-shield' ) ) . '\');">'
			. esc_html__( 'Block checkout & request ID', 'fraud-shield' ) . '</a>';
	}

	/**
	 * Coloured pill for a session's outcome.
	 *
	 * @param array $row Session row.
	 * @return string HTML.
	 */
	private function idv_result_pill( $row ) {
		$result = (string) ( $row['result'] ?? '' );
		$status = (string) ( $row['status'] ?? '' );

		$map = array(
			'approved'      => array( 'green', __( 'Verified', 'fraud-shield' ) ),
			'name_mismatch' => array( 'red', __( 'Name mismatch', 'fraud-shield' ) ),
			'declined'      => array( 'red', __( 'Declined', 'fraud-shield' ) ),
			'expired'       => array( 'black', __( 'Not completed', 'fraud-shield' ) ),
		);

		if ( isset( $map[ $result ] ) ) {
			list( $colour, $label ) = $map[ $result ];
			return '<span class="fs-pill fs-pill-' . esc_attr( $colour ) . '">' . esc_html( $label ) . '</span>';
		}

		// In flight — but only honestly so while the customer can still resume
		// it (24 h). Older, it is abandoned: the customer closed the window and
		// never came back, and staff should read it that way.
		if ( 'abandoned' === FS_IDV::display_outcome( $row ) ) {
			return '<span class="fs-pill fs-pill-black">' . esc_html__( 'Abandoned', 'fraud-shield' ) . '</span>'
				. ( '' !== $status ? ' <span class="fs-muted">' . esc_html( $status ) . '</span>' : '' );
		}

		return '<span class="fs-pill fs-pill-amber">' . esc_html__( 'In progress', 'fraud-shield' ) . '</span>'
			. ( '' !== $status ? ' <span class="fs-muted">' . esc_html( $status ) . '</span>' : '' );
	}

	/**
	 * Nonced link for a per-session staff action.
	 *
	 * @param string $do    'revoke' | 'accept'.
	 * @param int    $uid   Account id.
	 * @param string $email Normalized email.
	 * @param int    $row   Session row id (accept only).
	 * @param string $label Link text.
	 * @param string $extra Extra CSS classes.
	 * @return string HTML.
	 */
	private function idv_action_link( $do, $uid, $email, $row, $label, $extra = '' ) {
		$action = 'revoke' === $do ? 'fs_idv_revoke' : 'fs_idv_accept';
		$url    = wp_nonce_url(
			admin_url(
				'admin-post.php?action=' . $action . '&user=' . (int) $uid
				. '&email=' . rawurlencode( (string) $email )
				. '&row=' . (int) $row
			),
			$action
		);
		return '<a class="button button-small ' . esc_attr( $extra ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * The ID-verification panel shown on a customer's profile: where they stand
	 * right now, and every attempt they have made.
	 *
	 * Rendered for both profile views, so staff never have to go looking for
	 * whether a customer has been through the Didit flow.
	 *
	 * @param int    $uid   Account id (0 = guest identity).
	 * @param string $email Normalized email.
	 */
	private function render_idv_panel( $uid, $email ) {
		$uid   = (int) $uid;
		$email = FS_Normalizer::email( $email );
		$rows  = FS_Data::idv_sessions_for_customer( $uid, $email, 20 );

		// Keep the history visible even after the feature is switched off — it is
		// dispute evidence, not a live control. Only hide the panel entirely for
		// customers with no history on a store that isn't using the feature.
		if ( ! FS_IDV::enabled() && empty( $rows ) ) {
			return;
		}

		$verified = FS_IDV::is_verified( $uid, $email );
		$rec      = FS_IDV::record_for( $uid, $email );
		$required = FS_IDV::requires_verification( $uid );
		$enrich   = FS_Data::idv_enrichment_for( $uid, $email );
		$tier     = FS_Live::verification_tier( $uid, $email );
		$mismatch = null;
		foreach ( $rows as $r ) {
			if ( 'name_mismatch' === (string) $r['result'] ) {
				$mismatch = $r;
				break;
			}
		}

		$tier_labels = array(
			'staff' => array( 'green', __( 'Verified by staff', 'fraud-shield' ) ),
			'idv'   => array( 'green', __( 'ID-verified (Didit)', 'fraud-shield' ) ),
			'auto'  => array( 'green', __( 'Automatically verified (trusted history)', 'fraud-shield' ) ),
			'none'  => array( 'black', __( 'Not verified', 'fraud-shield' ) ),
		);
		$tier_ui     = isset( $tier_labels[ $tier ] ) ? $tier_labels[ $tier ] : $tier_labels['none'];
		?>
		<div class="fs-panel">
			<h2><?php esc_html_e( 'ID verification (Didit)', 'fraud-shield' ); ?></h2>

			<p>
				<?php esc_html_e( 'Current standing:', 'fraud-shield' ); ?>
				<span class="fs-pill fs-pill-<?php echo esc_attr( $tier_ui[0] ); ?>"><?php echo esc_html( $tier_ui[1] ); ?></span>
				<span class="fs-muted"><?php esc_html_e( 'This is the trust level the high-value order rules read for this customer.', 'fraud-shield' ); ?></span>
			</p>

			<?php
			// The payment-side counterpart to the ID check: the issuer's most
			// recent verdict on this customer's card (Card Checks, v1.20).
			$fs_cc_last = class_exists( 'FS_Cardholder' ) ? FS_Cardholder::latest_for_email( $email ) : null;
			if ( $fs_cc_last ) :
				$fs_cc_labels = FS_Cardholder::outcome_labels();
				$fs_cc_out    = (string) ( $fs_cc_last['outcome'] ?? '' );
				$fs_cc_ch     = (string) ( $fs_cc_last['ch'] ?? '' );
				$fs_cc_order  = (int) ( $fs_cc_last['order'] ?? 0 );
				$fs_cc_obj    = $fs_cc_order ? wc_get_order( $fs_cc_order ) : false;
				$fs_cc_url    = $fs_cc_obj ? $fs_cc_obj->get_edit_order_url() : '';
				?>
				<p>
					<?php esc_html_e( 'Latest card check:', 'fraud-shield' ); ?>
					<span class="<?php echo esc_attr( FS_Cardholder::outcome_pill( $fs_cc_out ) ); ?>"><?php echo esc_html( $fs_cc_labels[ $fs_cc_out ] ?? $fs_cc_out ); ?></span>
					<?php if ( 'confirmed' === $fs_cc_ch ) : ?>
						<span class="fs-pill fs-pill-green"><?php esc_html_e( 'Cardholder confirmed', 'fraud-shield' ); ?></span>
					<?php elseif ( 'mismatch' === $fs_cc_ch ) : ?>
						<span class="fs-pill fs-pill-red"><?php esc_html_e( 'Cardholder MISMATCH', 'fraud-shield' ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $fs_cc_url ) : ?>
						<a href="<?php echo esc_url( $fs_cc_url ); ?>"><?php echo esc_html( sprintf( /* translators: %d: order id */ __( 'on order #%d', 'fraud-shield' ), $fs_cc_order ) ); ?></a>
					<?php endif; ?>
					<span class="fs-muted"><?php esc_html_e( 'AVS/CVV verdict from the card issuer, via NMI. See the Card Checks page for the full list.', 'fraud-shield' ); ?></span>
				</p>
			<?php endif; ?>

			<?php if ( $verified && $rec ) : ?>
				<p>
					<span class="fs-pill fs-pill-green"><?php esc_html_e( 'Verified', 'fraud-shield' ); ?></span>
					<?php if ( ! empty( $rec['full_name'] ) ) : ?>
						&nbsp;<?php esc_html_e( 'Name on document:', 'fraud-shield' ); ?> <strong><?php echo esc_html( $rec['full_name'] ); ?></strong>
					<?php endif; ?>
				</p>
				<table class="widefat striped fs-detail-table">
					<tbody>
						<tr>
							<th style="width:220px;"><?php esc_html_e( 'Verified on', 'fraud-shield' ); ?></th>
							<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', (string) $rec['at'] ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'How', 'fraud-shield' ); ?></th>
							<td>
								<?php
								echo esc_html( 'staff' === (string) ( $rec['method'] ?? '' )
									? __( 'Staff accepted a held name mismatch', 'fraud-shield' )
									: __( 'Completed the Didit hosted check', 'fraud-shield' ) );
								?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Expires', 'fraud-shield' ); ?></th>
							<td>
								<?php
								if ( empty( $rec['expires'] ) ) {
									esc_html_e( 'Never', 'fraud-shield' );
								} else {
									echo esc_html( date_i18n( 'Y-m-d', (int) $rec['expires'] ) );
									if ( (int) $rec['expires'] < time() ) {
										echo ' <span class="fs-pill fs-pill-black">' . esc_html__( 'expired', 'fraud-shield' ) . '</span>';
									}
								}
								?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Name match', 'fraud-shield' ); ?></th>
							<td>
								<?php
								$fs_nm     = (string) ( $rec['name_match'] ?? '' );
								$fs_nm_map = array(
									'yes'    => __( 'Matched the billing name', 'fraud-shield' ),
									'no'     => __( 'Did not match the billing name (verified by policy; see the mismatch below)', 'fraud-shield' ),
									'manual' => __( 'Mismatch accepted by staff', 'fraud-shield' ),
									'n/a'    => __( 'Not checked (name matching off, or no name on file)', 'fraud-shield' ),
								);
								echo esc_html( $fs_nm_map[ $fs_nm ] ?? $fs_nm );
								?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Didit session', 'fraud-shield' ); ?></th>
							<td><code><?php echo esc_html( (string) ( $rec['session_id'] ?? '' ) ); ?></code></td>
						</tr>
					</tbody>
				</table>

				<?php $this->render_idv_enrichment( $enrich, $email ); ?>
				<p>
					<?php echo $this->idv_action_link( 'revoke', $uid, $email, 0, __( 'Revoke verification', 'fraud-shield' ) ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped ?>
					<span class="fs-muted"><?php esc_html_e( 'Revoking removes the verified credential this check earned, and the customer is subject to checkout controls again.', 'fraud-shield' ); ?></span>
				</p>

			<?php elseif ( $mismatch ) : ?>
				<p>
					<span class="fs-pill fs-pill-red"><?php esc_html_e( 'Name mismatch', 'fraud-shield' ); ?></span>
					<span class="fs-muted"><?php esc_html_e( 'Didit approved the document, but the name on it did not match this customer.', 'fraud-shield' ); ?></span>
				</p>
				<p>
					<?php esc_html_e( 'Name on document:', 'fraud-shield' ); ?> <strong><?php echo esc_html( (string) $mismatch['full_name'] ); ?></strong><br/>
					<?php esc_html_e( 'Expected:', 'fraud-shield' ); ?> <strong><?php echo esc_html( (string) $mismatch['expected_name'] ); ?></strong>
				</p>
				<p><?php echo $this->idv_action_link( 'accept', $uid, $email, (int) $mismatch['id'], __( 'Accept & mark verified', 'fraud-shield' ), 'button-primary' ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped ?></p>

			<?php elseif ( $required ) : ?>
				<p>
					<span class="fs-pill fs-pill-amber"><?php esc_html_e( 'ID requested', 'fraud-shield' ); ?></span>
					<span class="fs-muted"><?php esc_html_e( 'Checkout is blocked for this customer until they complete a verification. They see your ID-requested message with a verify button.', 'fraud-shield' ); ?></span>
				</p>

			<?php elseif ( empty( $rows ) ) : ?>
				<p class="fs-muted">
					<?php esc_html_e( 'This customer has never been through the ID-verification flow.', 'fraud-shield' ); ?>
					<?php if ( $uid <= 0 ) : ?>
						<?php esc_html_e( 'Guest identities cannot be asked to verify. ID verification needs a registered account.', 'fraud-shield' ); ?>
					<?php endif; ?>
				</p>

			<?php else : ?>
				<p class="fs-muted"><?php esc_html_e( 'This customer has attempted verification but is not currently verified. Their full history is below.', 'fraud-shield' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $rows ) ) : ?>
				<h3><?php esc_html_e( 'Verification history', 'fraud-shield' ); ?></h3>
				<p class="fs-muted"><?php esc_html_e( 'Every check this customer has started, newest first. A customer can hold several completed checks over time; the newest approved one is the one in force.', 'fraud-shield' ); ?></p>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Started', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Outcome', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Name on document', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Expected name', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Document', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Verified from', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Last update', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Order', 'fraud-shield' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $r ) : ?>
							<tr<?php echo 'approved' === (string) $r['result'] ? ' class="fs-row-verified"' : ( in_array( (string) $r['result'], array( 'name_mismatch', 'declined' ), true ) ? ' class="fs-row-flag"' : '' ); ?>>
								<td><?php echo esc_html( $r['created_at'] ? mysql2date( 'Y-m-d H:i', (string) $r['created_at'] ) : '-' ); ?></td>
								<td><?php echo $this->idv_result_pill( $r ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped ?></td>
								<td><?php echo esc_html( '' !== (string) $r['full_name'] ? (string) $r['full_name'] : '-' ); ?></td>
								<td><?php echo esc_html( '' !== (string) $r['expected_name'] ? (string) $r['expected_name'] : '-' ); ?></td>
								<td>
									<?php
									$fs_doc = trim( (string) $r['doc_type'] );
									$fs_org = trim( (string) ( $r['doc_state'] ?? '' ) . ( '' !== (string) ( $r['doc_country'] ?? '' ) ? ( '' !== (string) ( $r['doc_state'] ?? '' ) ? ', ' : '' ) . (string) $r['doc_country'] : '' ) );
									echo esc_html( '' !== $fs_doc ? $fs_doc : '-' );
									if ( '' !== $fs_org ) {
										echo '<br/><span class="fs-muted">' . esc_html( $fs_org ) . '</span>';
									}
									?>
								</td>
								<td>
									<?php
									$fs_from = trim( (string) ( $r['verify_ip_state'] ?? '' ) . ( '' !== (string) ( $r['verify_ip_country'] ?? '' ) ? ( '' !== (string) ( $r['verify_ip_state'] ?? '' ) ? ', ' : '' ) . (string) $r['verify_ip_country'] : '' ) );
									echo esc_html( '' !== $fs_from ? $fs_from : '-' );
									if ( ! empty( $r['verify_vpn'] ) ) {
										echo '<br/><span class="fs-bad">' . esc_html__( 'VPN or Tor', 'fraud-shield' ) . '</span>';
									}
									?>
								</td>
								<td><?php echo esc_html( $r['updated_at'] ? mysql2date( 'Y-m-d H:i', (string) $r['updated_at'] ) : '-' ); ?></td>
								<td>
									<?php if ( (int) $r['order_id'] > 0 ) : ?>
										<a href="<?php echo esc_url( $this->order_edit_url( (int) $r['order_id'] ) ); ?>">#<?php echo (int) $r['order_id']; ?></a>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * What Didit told us about the latest approved check, read against what the
	 * store knows about the customer. This is the "does the ID agree with the
	 * customer's story" table: where the document is from versus where their
	 * orders ship, where they physically were while verifying, whether they hid
	 * behind a VPN, and any warnings Didit raised. The same comparisons feed
	 * the risk score; this is the human-readable copy.
	 *
	 * @param array  $enrich Row from FS_Data::idv_enrichment_for() (may be empty).
	 * @param string $email  Normalized email (for the shipping-state lookup).
	 */
	private function render_idv_enrichment( $enrich, $email ) {
		if ( empty( $enrich ) ) {
			return;
		}

		$doc_state   = (string) ( $enrich['doc_state'] ?? '' );
		$doc_country = (string) ( $enrich['doc_country'] ?? '' );
		$ip_country  = (string) ( $enrich['verify_ip_country'] ?? '' );
		$ip_state    = (string) ( $enrich['verify_ip_state'] ?? '' );
		$vpn         = ! empty( $enrich['verify_vpn'] );
		$warnings    = array();
		if ( ! empty( $enrich['warnings'] ) ) {
			$decoded  = json_decode( (string) $enrich['warnings'], true );
			$warnings = is_array( $decoded ) ? array_filter( array_map( 'strval', $decoded ) ) : array();
		}

		$ship_states = '' !== $email ? FS_Data::distinct_ship_states( $email ) : array();
		$state_known = '' !== $doc_state && ! empty( $ship_states );
		$state_match = false;
		foreach ( $ship_states as $st ) {
			if ( FS_Data::states_match( $doc_state, $st ) ) {
				$state_match = true;
				break;
			}
		}

		$has_anything = ( '' !== $doc_state || '' !== $doc_country || '' !== $ip_country || $vpn || ! empty( $warnings ) );
		if ( ! $has_anything ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'What the check tells us', 'fraud-shield' ); ?></h3>
		<table class="widefat striped fs-detail-table">
			<tbody>
				<?php if ( '' !== $doc_state || '' !== $doc_country ) : ?>
					<tr>
						<th style="width:220px;"><?php esc_html_e( 'Document from', 'fraud-shield' ); ?></th>
						<td>
							<?php echo esc_html( trim( $doc_state . ( '' !== $doc_country ? ( '' !== $doc_state ? ', ' : '' ) . $doc_country : '' ) ) ); ?>
							<?php if ( $state_known ) : ?>
								<?php if ( $state_match ) : ?>
									<span class="fs-ok">&#10003; <?php esc_html_e( 'matches a state this customer ships to', 'fraud-shield' ); ?></span>
								<?php else : ?>
									<span class="fs-bad">&#10007; <?php echo esc_html( sprintf( /* translators: %s: list of states */ __( 'does not match any state this customer ships to (%s)', 'fraud-shield' ), implode( ', ', array_slice( $ship_states, 0, 5 ) ) ) ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ( '' !== $ip_country || '' !== $ip_state ) : ?>
					<tr>
						<th><?php esc_html_e( 'Verified from', 'fraud-shield' ); ?></th>
						<td>
							<?php echo esc_html( trim( $ip_state . ( '' !== $ip_country ? ( '' !== $ip_state ? ', ' : '' ) . $ip_country : '' ) ) ); ?>
							<?php if ( $vpn ) : ?>
								<span class="fs-bad">&#10007; <?php esc_html_e( 'over a VPN or Tor connection', 'fraud-shield' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php elseif ( $vpn ) : ?>
					<tr>
						<th><?php esc_html_e( 'Verified from', 'fraud-shield' ); ?></th>
						<td><span class="fs-bad">&#10007; <?php esc_html_e( 'A VPN or Tor connection', 'fraud-shield' ); ?></span></td>
					</tr>
				<?php endif; ?>
				<?php if ( ! empty( $warnings ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Didit warnings', 'fraud-shield' ); ?></th>
						<td>
							<ul style="margin:0 0 0 1.1em;list-style:disc;">
								<?php foreach ( array_slice( $warnings, 0, 10 ) as $w ) : ?>
									<li><?php echo esc_html( $w ); ?></li>
								<?php endforeach; ?>
							</ul>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<p class="fs-muted"><?php esc_html_e( 'These comparisons also feed the risk score (see the Rule weights in Settings), so contradictions raise the customer\'s score automatically.', 'fraud-shield' ); ?></p>
		<?php
	}

	/**
	 * The full intelligence profile for one identity: everything Fraud Shield
	 * knows about this customer and who they are connected to.
	 *
	 * @param string $email Normalized email.
	 */
	private function render_profile_detail( $email ) {
		$list_url = admin_url( 'admin.php?page=' . self::SLUG . '-profiles' );
		$summary  = FS_Data::profile_summary( $email );

		if ( empty( $summary ) || 0 === (int) $summary['orders'] ) {
			echo '<div class="wrap fs-wrap"><h1>' . esc_html__( 'Customer profile', 'fraud-shield' ) . '</h1>';
			echo '<p>' . esc_html__( 'No orders found for that email address.', 'fraud-shield' ) . '</p>';
			echo '<a class="button" href="' . esc_url( $list_url ) . '">&larr; ' . esc_html__( 'Back to Profiles', 'fraud-shield' ) . '</a></div>';
			return;
		}

		$uid  = (int) $summary['user_id'];
		$user = $uid > 0 ? get_userdata( $uid ) : false;

		$names    = FS_Data::profile_distinct( $email, 'customer_name' );
		$phones   = FS_Data::profile_distinct( $email, 'phone_norm' );
		$payments = FS_Data::profile_distinct( $email, 'payment_method' );
		$devices  = FS_Data::profile_distinct( $email, 'user_agent' );
		$ips      = FS_Data::profile_distinct( $email, 'ip' );
		$addrs    = FS_Data::profile_addresses( $email );
		$coupons  = FS_Data::profile_coupons( $email );
		$linked   = FS_Data::profile_linked( $email );
		$dates    = FS_Data::profile_dates( $email );
		$geo_rows = FS_Data::profile_geo_rows( $email );

		// --- Ordering cadence: intervals + time-of-day pattern ---
		$stamps = array();
		foreach ( $dates as $d ) {
			$ts = strtotime( $d );
			if ( $ts ) {
				$stamps[] = $ts;
			}
		}
		sort( $stamps );
		$gaps = array();
		for ( $i = 1, $n = count( $stamps ); $i < $n; $i++ ) {
			$gaps[] = $stamps[ $i ] - $stamps[ $i - 1 ];
		}
		$avg_gap = $gaps ? array_sum( $gaps ) / count( $gaps ) : 0;
		$min_gap = $gaps ? min( $gaps ) : 0;

		$hours = array_fill( 0, 24, 0 );
		foreach ( $dates as $d ) {
			$h = (int) substr( $d, 11, 2 );
			if ( $h >= 0 && $h <= 23 ) {
				$hours[ $h ]++;
			}
		}
		// Densest 3-hour window.
		$best_start = 0;
		$best_count = 0;
		for ( $h = 0; $h < 24; $h++ ) {
			$c = $hours[ $h ] + $hours[ ( $h + 1 ) % 24 ] + $hours[ ( $h + 2 ) % 24 ];
			if ( $c > $best_count ) {
				$best_count = $c;
				$best_start = $h;
			}
		}
		$total_orders = (int) $summary['orders'];
		$hour_max     = max( 1, max( $hours ) );

		// --- Geo consistency: does the IP's location match the entered country? ---
		$geo_known = 0;
		$geo_match = 0;
		$geo_lines = array();
		foreach ( $geo_rows as $g ) {
			$label = $this->ip_location_label( $g['ip'] );
			if ( '' === $label ) {
				continue;
			}
			$geo_known++;
			$code    = '';
			$matched = false;
			if ( preg_match( '/\(([A-Z]{2})\)$/', $label, $m ) ) {
				$code = $m[1];
			}
			if ( '' !== $code && ( strtoupper( (string) $g['bc'] ) === $code || strtoupper( (string) $g['sc'] ) === $code ) ) {
				$geo_match = $geo_match + 1;
				$matched   = true;
			}
			$geo_lines[] = array(
				'ip'      => $g['ip'],
				'label'   => $label,
				'entered' => trim( $g['bc'] . ' / ' . $g['sc'], ' /' ),
				'orders'  => (int) $g['c'],
				'match'   => $matched,
			);
		}

		$bill_known = (int) $summary['bill_known'];
		$bill_match = (int) $summary['bill_match'];

		// Account-vs-order name check.
		$acct_name = $user ? trim( get_user_meta( $uid, 'first_name', true ) . ' ' . get_user_meta( $uid, 'last_name', true ) ) : '';
		if ( '' === $acct_name && $user ) {
			$acct_name = $user->display_name;
		}

		// Orders table (paginated).
		$per    = 50;
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$orders = FS_Data::profile_orders( $email, $per, ( $paged - 1 ) * $per );
		$opages = max( 1, (int) ceil( $total_orders / $per ) );

		$block_profile_url = wp_nonce_url( admin_url( 'admin-post.php?action=fs_block_profile&email=' . rawurlencode( $email ) ), 'fs_block_profile' );
		$amap              = FS_Blocklist::actions_for_targets( 'profile', array( $email ) );
		$profile_action    = isset( $amap[ $email ] ) ? $amap[ $email ] : null;
		$email_blocked     = FS_Blocklist::is_blocked( 'email', $email );
		$email_verified    = FS_Live::is_cred_verified( 'email', $email );
		?>
		<div class="wrap fs-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Customer profile', 'fraud-shield' ); ?></h1>
			<a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action">&larr; <?php esc_html_e( 'Back to Profiles', 'fraud-shield' ); ?></a>
			<?php if ( $user ) : ?>
				<a href="<?php echo esc_url( get_edit_user_link( $uid ) ); ?>" class="page-title-action"><?php esc_html_e( 'Edit user', 'fraud-shield' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG . '-new-customers', 'fs_customer' => $uid ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Account view', 'fraud-shield' ); ?></a>
			<?php endif; ?>
			<?php
			if ( $profile_action ) :
				$fs_count   = (int) $profile_action['entries'];
				$fs_confirm = sprintf(
					/* translators: %d: number of credentials that will be removed */
					_n(
						'Unblock this profile? The %d credential created by this block will be removed from the blocklist.',
						'Unblock this profile? All %d credentials created by this block will be removed from the blocklist.',
						max( 1, $fs_count ),
						'fraud-shield'
					),
					$fs_count
				);
				?>
				<a href="<?php echo esc_url( $block_profile_url ); ?>" class="page-title-action fs-blocked-btn" title="<?php esc_attr_e( 'On the blocklist. Click to unblock — removes every credential this block created.', 'fraud-shield' ); ?>" onclick="return confirm('<?php echo esc_js( $fs_confirm ); ?>');">&#10004; <?php esc_html_e( 'Blocked — click to unblock', 'fraud-shield' ); ?></a>
			<?php elseif ( $email_blocked ) : ?>
				<a href="<?php echo esc_url( $block_profile_url ); ?>" class="page-title-action fs-blocked-btn" title="<?php esc_attr_e( 'Blocked before v1.6 — click to rebuild as a removable grouped block.', 'fraud-shield' ); ?>">&#10004; <?php esc_html_e( 'Blocked (legacy)', 'fraud-shield' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( $block_profile_url ); ?>" class="page-title-action" onclick="return confirm('<?php echo esc_js( __( 'Block this profile? Their email, phones, IPs, addresses, and account will ALL be rejected at checkout.', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Block profile (all credentials)', 'fraud-shield' ); ?></a>
			<?php endif; ?>
			<?php if ( $email_verified ) : ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fs_unverify_cred&type=email&value=' . rawurlencode( $email ) ), 'fs_unverify_cred' ) ); ?>" class="page-title-action fs-verified-btn" onclick="return confirm('<?php echo esc_js( __( 'Remove this verification? The customer will be subject to checkout controls again.', 'fraud-shield' ) ); ?>');">&#10004; <?php esc_html_e( 'Verified — click to remove', 'fraud-shield' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fs_verify_cred&type=email&value=' . rawurlencode( $email ) ), 'fs_verify_cred' ) ); ?>" class="page-title-action" onclick="return confirm('<?php echo esc_js( __( 'Verify as a known-good customer? They will bypass the velocity lock, holds, and gateway restrictions (the blocklist still applies).', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Verify customer', 'fraud-shield' ); ?></a>
			<?php endif; ?>
			<?php echo $this->idv_request_button( $uid ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- built from escaped parts. ?>
			<?php $this->maybe_notice(); ?>

			<h2 class="fs-detail-title"><?php echo esc_html( $summary['customer_name'] ? $summary['customer_name'] : __( '(no name)', 'fraud-shield' ) ); ?> &lt;<?php echo esc_html( $email ); ?>&gt;</h2>
			<p class="description">
			<?php
			if ( $user ) {
				/* translators: 1: user id, 2: registration date */
				printf( esc_html__( 'Registered account #%1$d since %2$s.', 'fraud-shield' ), (int) $uid, esc_html( mysql2date( 'M j, Y', $user->user_registered ) ) );
			} else {
				esc_html_e( 'Guest customer (no registered account).', 'fraud-shield' );
			}
			?>
			</p>

			<div class="fs-cards">
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $total_orders ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Orders', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) $summary['paid_orders'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Paid orders', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-amber"><div class="fs-num"><?php echo wp_kses_post( wc_price( (float) $summary['spend'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Spend (paid)', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo wp_kses_post( wc_price( (float) $summary['aov'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Avg order value', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-red"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) $summary['chargebacks'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Chargebacks', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) $summary['ips'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'IPs used', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) $summary['addresses'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Addresses', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo (int) $summary['max_risk']; ?></div><div class="fs-label"><?php esc_html_e( 'Max risk', 'fraud-shield' ); ?></div></div>
			</div>

			<?php $this->render_refusals_panel( $uid, $email ); ?>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Consistency checks', 'fraud-shield' ); ?></h2>
				<div class="fs-detail-grid">
					<div class="fs-detail-col">
						<ul class="fs-kv">
							<li><span><?php esc_html_e( 'Shipping = billing address', 'fraud-shield' ); ?></span>
								<?php
								if ( $bill_known > 0 ) {
									$all_match = ( $bill_match === $bill_known );
									echo $this->match_badge( $all_match ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
									/* translators: 1: matching orders, 2: orders with both addresses known */
									printf( esc_html__( 'matches on %1$s of %2$s orders', 'fraud-shield' ), esc_html( number_format_i18n( $bill_match ) ), esc_html( number_format_i18n( $bill_known ) ) );
								} else {
									esc_html_e( 'Unknown (billing address not captured yet — re-index to backfill)', 'fraud-shield' );
								}
								?>
							</li>
							<li><span><?php esc_html_e( 'IP location vs entered country', 'fraud-shield' ); ?></span>
								<?php
								if ( $geo_known > 0 ) {
									echo $this->match_badge( $geo_match === $geo_known ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
									/* translators: 1: matching IPs, 2: geolocatable IPs */
									printf( esc_html__( '%1$s of %2$s IPs geolocate to the entered country', 'fraud-shield' ), esc_html( number_format_i18n( $geo_match ) ), esc_html( number_format_i18n( $geo_known ) ) );
								} else {
									esc_html_e( 'Unknown (no geolocatable IPs — check the MaxMind key)', 'fraud-shield' );
								}
								?>
							</li>
							<li><span><?php esc_html_e( 'Names used', 'fraud-shield' ); ?></span>
								<?php echo $this->match_badge( count( $names ) <= 1 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php echo esc_html( number_format_i18n( count( $names ) ) ); ?>
							</li>
							<?php if ( $user ) : ?>
								<li><span><?php esc_html_e( 'Order name = account name', 'fraud-shield' ); ?></span>
									<?php
									$order_name = strtolower( trim( (string) $summary['customer_name'] ) );
									$match_name = ( '' !== $acct_name && $order_name === strtolower( trim( $acct_name ) ) );
									echo $this->match_badge( $match_name ) . ' ' . esc_html( $acct_name ? $acct_name : '—' ); // phpcs:ignore WordPress.Security.EscapeOutput
									?>
								</li>
							<?php endif; ?>
							<li><span><?php esc_html_e( 'Phones used', 'fraud-shield' ); ?></span> <?php echo esc_html( number_format_i18n( count( $phones ) ) ); ?></li>
						</ul>
					</div>
					<div class="fs-detail-col">
						<?php if ( ! empty( $geo_lines ) ) : ?>
							<h4><?php esc_html_e( 'IP geolocation detail', 'fraud-shield' ); ?></h4>
							<ul class="fs-badge-list">
								<?php foreach ( $geo_lines as $gl ) : ?>
									<li>
										<?php echo $this->match_badge( $gl['match'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<?php echo esc_html( $gl['ip'] ); ?> → <?php echo esc_html( $gl['label'] ); ?>
										<span class="fs-muted">
										<?php
										/* translators: 1: entered country codes, 2: order count */
										printf( esc_html__( '(entered: %1$s · %2$s orders)', 'fraud-shield' ), esc_html( $gl['entered'] ? $gl['entered'] : '—' ), esc_html( number_format_i18n( $gl['orders'] ) ) );
										?>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Ordering behavior', 'fraud-shield' ); ?></h2>
				<div class="fs-detail-grid">
					<div class="fs-detail-col">
						<ul class="fs-kv">
							<li><span><?php esc_html_e( 'Average order value', 'fraud-shield' ); ?></span> <?php echo wp_kses_post( wc_price( (float) $summary['aov'] ) ); ?></li>
							<li><span><?php esc_html_e( 'Avg time between orders', 'fraud-shield' ); ?></span> <?php echo $gaps ? esc_html( $this->human_interval( $avg_gap ) ) : esc_html__( 'n/a (fewer than 2 orders)', 'fraud-shield' ); ?></li>
							<li><span><?php esc_html_e( 'Fastest gap between orders', 'fraud-shield' ); ?></span> <?php echo $gaps ? esc_html( $this->human_interval( $min_gap ) ) : '—'; ?></li>
							<li><span><?php esc_html_e( 'Active period', 'fraud-shield' ); ?></span>
								<?php
								/* translators: 1: first order date, 2: last order date */
								printf( esc_html__( '%1$s → %2$s', 'fraud-shield' ), esc_html( mysql2date( 'M j, Y', $summary['first_order'] ) ), esc_html( mysql2date( 'M j, Y', $summary['last_order'] ) ) );
								?>
							</li>
							<?php if ( $best_count > 0 && $total_orders > 1 ) : ?>
								<li><span><?php esc_html_e( 'Time-of-day pattern', 'fraud-shield' ); ?></span>
									<?php
									printf(
										/* translators: 1: orders in window, 2: total orders, 3: start hour, 4: end hour */
										esc_html__( '%1$s of %2$s orders placed between %3$s:00 and %4$s:59 (store time)', 'fraud-shield' ),
										esc_html( number_format_i18n( $best_count ) ),
										esc_html( number_format_i18n( $total_orders ) ),
										esc_html( str_pad( (string) $best_start, 2, '0', STR_PAD_LEFT ) ),
										esc_html( str_pad( (string) ( ( $best_start + 2 ) % 24 ), 2, '0', STR_PAD_LEFT ) )
									);
									?>
								</li>
							<?php endif; ?>
						</ul>
					</div>
					<div class="fs-detail-col">
						<h4><?php esc_html_e( 'Orders by hour of day', 'fraud-shield' ); ?></h4>
						<div class="fs-hours">
							<?php for ( $h = 0; $h < 24; $h++ ) : ?>
								<?php if ( $hours[ $h ] > 0 ) : ?>
									<div class="fs-hour-row">
										<span class="fs-hour-label"><?php echo esc_html( str_pad( (string) $h, 2, '0', STR_PAD_LEFT ) . ':00' ); ?></span>
										<span class="fs-hour-bar" style="width:<?php echo (int) max( 4, round( $hours[ $h ] / $hour_max * 100 ) ); ?>%;"></span>
										<span class="fs-muted"><?php echo (int) $hours[ $h ]; ?></span>
									</div>
								<?php endif; ?>
							<?php endfor; ?>
						</div>
					</div>
				</div>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Payment, coupons & devices', 'fraud-shield' ); ?></h2>
				<div class="fs-detail-grid">
					<div class="fs-detail-col">
						<h4><?php esc_html_e( 'Payment methods', 'fraud-shield' ); ?></h4>
						<ul class="fs-badge-list">
							<?php
							if ( empty( $payments ) ) {
								echo '<li>—</li>';
							}
							foreach ( $payments as $p ) {
								echo '<li>' . esc_html( $p['v'] ) . ' <span class="fs-muted">× ' . (int) $p['c'] . '</span></li>';
							}
							?>
						</ul>
						<h4><?php esc_html_e( 'Coupon codes used', 'fraud-shield' ); ?></h4>
						<ul class="fs-badge-list">
							<?php
							if ( empty( $coupons ) ) {
								echo '<li>' . esc_html__( 'None recorded', 'fraud-shield' ) . '</li>';
							}
							foreach ( $coupons as $code => $c ) {
								echo '<li><code>' . esc_html( $code ) . '</code> <span class="fs-muted">× ' . (int) $c . '</span></li>';
							}
							?>
						</ul>
					</div>
					<div class="fs-detail-col">
						<h4><?php esc_html_e( 'Device signatures (from browser user-agent)', 'fraud-shield' ); ?></h4>
						<ul class="fs-badge-list">
							<?php
							if ( empty( $devices ) ) {
								echo '<li>' . esc_html__( 'None recorded — re-index to backfill from order data', 'fraud-shield' ) . '</li>';
							}
							foreach ( $devices as $d ) {
								echo '<li title="' . esc_attr( $d['v'] ) . '">' . esc_html( $this->device_label( $d['v'] ) ) . ' <span class="fs-muted">× ' . (int) $d['c'] . '</span> <span class="fs-muted">(' . esc_html( hash( 'crc32b', (string) $d['v'] ) ) . ')</span></li>';
							}
							?>
						</ul>
						<p class="description"><?php esc_html_e( 'The hash in parentheses is a stable signature — the same value on two profiles means the same browser build. Hover for the raw user-agent.', 'fraud-shield' ); ?></p>
					</div>
				</div>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Network: linked customers', 'fraud-shield' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Other customer identities that share an IP address or a shipping address with this one. Multiple linked identities on shared infrastructure is the signature of a ring.', 'fraud-shield' ); ?></p>
				<?php if ( empty( $linked ) ) : ?>
					<p><?php esc_html_e( 'No linked customers found — this identity does not share an IP or address with anyone else.', 'fraud-shield' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat striped">
						<thead><tr>
							<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Email', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Shared IPs', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Shared addresses', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Orders', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'CB', 'fraud-shield' ); ?></th>
							<th></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $linked as $l ) : ?>
							<tr<?php echo ( (int) $l['chargebacks'] > 0 ) ? ' class="fs-row-flag"' : ''; ?>>
								<td><strong><?php echo esc_html( $l['customer_name'] ? $l['customer_name'] : '—' ); ?></strong></td>
								<td><?php echo esc_html( $l['email_norm'] ); ?></td>
								<td><?php echo (int) $l['shared_ips'] ? '<span class="fs-pill fs-pill-amber">' . (int) $l['shared_ips'] . '</span>' : '—'; ?></td>
								<td><?php echo (int) $l['shared_addresses'] ? '<span class="fs-pill fs-pill-amber">' . (int) $l['shared_addresses'] . '</span>' : '—'; ?></td>
								<td><?php echo (int) $l['orders']; ?></td>
								<td><?php echo (int) $l['chargebacks'] ? '<span class="fs-pill fs-pill-red">' . (int) $l['chargebacks'] . '</span>' : '—'; ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( $this->profile_url( $l['email_norm'] ) ); ?>"><?php esc_html_e( 'Profile', 'fraud-shield' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'IPs & addresses used', 'fraud-shield' ); ?></h2>
				<div class="fs-detail-grid">
					<div class="fs-detail-col">
						<h4><?php esc_html_e( 'IP addresses', 'fraud-shield' ); ?></h4>
						<ul class="fs-badge-list">
							<?php
							if ( empty( $ips ) ) {
								echo '<li>—</li>';
							}
							foreach ( $ips as $ipr ) {
								$loc = $this->ip_location_label( $ipr['v'] );
								echo '<li><a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'fs_view' => 'ip', 'fs_key' => $ipr['v'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $ipr['v'] ) . '</a> <span class="fs-muted">× ' . (int) $ipr['c'] . ( $loc ? ' · ' . esc_html( $loc ) : '' ) . '</span></li>';
							}
							?>
						</ul>
					</div>
					<div class="fs-detail-col">
						<h4><?php esc_html_e( 'Shipping addresses', 'fraud-shield' ); ?></h4>
						<ul class="fs-badge-list">
							<?php
							if ( empty( $addrs ) ) {
								echo '<li>—</li>';
							}
							foreach ( $addrs as $ad ) {
								echo '<li><a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'fs_view' => 'address', 'fs_key' => $ad['ship_hash'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $ad['ship_text'] ? $ad['ship_text'] : substr( $ad['ship_hash'], 0, 16 ) . '…' ) . '</a> <span class="fs-muted">× ' . (int) $ad['c'] . '</span></li>';
							}
							?>
						</ul>
					</div>
				</div>
			</div>

			<?php $this->render_idv_panel( $uid, $email ); ?>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Orders', 'fraud-shield' ); ?></h2>
				<?php $this->export_range_form( 'profile_orders', array( 'email' => $email ), __( 'Export this profile (CSV)', 'fraud-shield' ) ); ?>
				<?php $this->render_orders_table( $orders ); ?>
				<?php if ( $opages > 1 ) : ?>
					<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( array( 'page' => self::SLUG . '-profiles', 'fs_profile' => rawurlencode( $email ) ), admin_url( 'admin.php' ) ) . '%_%',
								'format'    => '&paged=%#%',
								'current'   => $paged,
								'total'     => $opages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
					</div></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* ===================== Address & IP registries ===================== */

	/**
	 * Addresses page: EVERY shipping address seen in orders, with a full
	 * profile behind each one.
	 */
	public function page_addresses() {
		$this->guard();
		$this->render_clusters_page( 'address' );
	}

	/**
	 * IPs page: EVERY IP address seen in orders, with a full profile behind
	 * each one.
	 */
	public function page_ips() {
		$this->guard();
		$this->render_clusters_page( 'ip' );
	}

	/**
	 * Geography page: fraud rolled up by ship-to state / city / ZIP, plus
	 * one-click Destination Locks. Answers "which place has the worst fraud".
	 */
	public function page_geo() {
		$this->guard();
		$settings = FS_Core::instance()->settings();

		$level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : 'state'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $level, array( 'state', 'city', 'zip', 'street' ), true ) ) {
			$level = 'state';
		}

		// Drill-down: all customers shipping to one destination.
		if ( ! empty( $_GET['fs_geo_view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$country = isset( $_GET['country'] ) ? sanitize_text_field( wp_unslash( $_GET['country'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$state   = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';     // phpcs:ignore WordPress.Security.NonceVerification
			$key     = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';         // phpcs:ignore WordPress.Security.NonceVerification
			$city    = isset( $_GET['city'] ) ? sanitize_text_field( wp_unslash( $_GET['city'] ) ) : '';       // phpcs:ignore WordPress.Security.NonceVerification
			$this->render_geo_customers( $level, $country, $state, $key, $city );
			return;
		}
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';            // phpcs:ignore WordPress.Security.NonceVerification
		$months = isset( $_GET['months'] ) ? max( 0, (int) $_GET['months'] ) : 0;                        // phpcs:ignore WordPress.Security.NonceVerification
		$min    = isset( $_GET['min_orders'] ) ? max( 1, (int) $_GET['min_orders'] ) : 5;                // phpcs:ignore WordPress.Security.NonceVerification
		$sort   = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'avg_score';    // phpcs:ignore WordPress.Security.NonceVerification
		$dir    = ( isset( $_GET['dir'] ) && 'asc' === $_GET['dir'] ) ? 'asc' : 'desc';                  // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;                          // phpcs:ignore WordPress.Security.NonceVerification
		$per    = 50;

		$args = array(
			'level'      => $level,
			'search'     => $search,
			'months'     => $months,
			'min_orders' => $min,
			'orderby'    => $sort,
			'order'      => $dir,
			'limit'      => $per,
			'offset'     => ( $paged - 1 ) * $per,
		);
		$total     = FS_Data::geo_rollup_count( $args );
		$rows      = FS_Data::geo_rollup( $args );
		$pages     = max( 1, (int) ceil( $total / $per ) );
		$remaining = FS_Data::geo_backfill_remaining();

		$base_args = array(
			'page'       => self::SLUG . '-geo',
			'level'      => $level,
			's'          => $search,
			'months'     => $months,
			'min_orders' => $min,
		);

		// One batched lookup drives every row's lock state (no N+1). Resolved
		// through the ENTRY (v1.31), so a street row covered as a boundary of
		// a lock created from another city's row reads as locked too.
		$keys = array();
		foreach ( (array) $rows as $r ) {
			$keys[] = $this->geo_row_key( $level, $r );
		}
		$place_actions = FS_Blocklist::place_locked_keys( $keys );
		$colspan       = ( 'state' === $level ) ? 10 : ( 'street' === $level ? 12 : 11 );
		?>
		<div class="wrap fs-wrap">
			<h1><?php esc_html_e( 'Geography', 'fraud-shield' ); ?></h1>
			<?php $this->maybe_notice(); ?>
			<p class="description"><?php esc_html_e( 'Where your fraud ships. Average risk score and chargeback rate rolled up by destination — then lock a state, city, ZIP prefix, or street in one click. Destination locks block by where an order ships, never by who placed it: they never cascade to identities and never auto-extend.', 'fraud-shield' ); ?></p>
			<?php if ( 'street' === $level ) : ?>
				<p class="description"><?php esc_html_e( 'Streets are the street NAME within a city — house numbers and unit numbers are stripped, directionals are kept (N Main St and S Main St are separate rows). Locking a street refuses every checkout shipping to any address on it.', 'fraud-shield' ); ?></p>
			<?php endif; ?>

			<?php if ( $remaining > 0 ) : ?>
				<div class="notice notice-warning fs-geo-backfill-notice">
					<p>
						<?php
						/* translators: %s: number of orders */
						printf( esc_html( _n( '%s older order has no destination data yet — the stats below only cover geo-indexed orders.', '%s older orders have no destination data yet — the stats below only cover geo-indexed orders.', $remaining, 'fraud-shield' ) ), esc_html( number_format_i18n( $remaining ) ) );
						?>
						<button type="button" class="button button-primary" id="fs-geo-backfill" data-remaining="<?php echo (int) $remaining; ?>"><?php esc_html_e( 'Backfill now', 'fraud-shield' ); ?></button>
						<span class="fs-geo-backfill-status"></span>
					</p>
					<p class="description"><?php esc_html_e( 'Alternatively, run a full scan with "Re-index orders already scanned" checked — that fills these columns too.', 'fraud-shield' ); ?></p>
				</div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper fs-geo-tabs">
				<?php
				foreach ( array(
					'state'  => __( 'States', 'fraud-shield' ),
					'city'   => __( 'Cities', 'fraud-shield' ),
					'zip'    => __( 'ZIPs', 'fraud-shield' ),
					'street' => __( 'Streets', 'fraud-shield' ),
				) as $lv => $lbl ) {
					$url = add_query_arg(
						array(
							'page'       => self::SLUG . '-geo',
							'level'      => $lv,
							's'          => $search,
							'months'     => $months,
							'min_orders' => $min,
						),
						admin_url( 'admin.php' )
					);
					echo '<a class="nav-tab' . ( $lv === $level ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $lbl ) . '</a>';
				}
				?>
			</h2>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-geo' ); ?>" />
				<input type="hidden" name="level" value="<?php echo esc_attr( $level ); ?>" />
				<input type="hidden" name="sort" value="<?php echo esc_attr( $sort ); ?>" />
				<input type="hidden" name="dir" value="<?php echo esc_attr( $dir ); ?>" />
				<p class="fs-filters">
					<select name="months">
						<?php
						foreach ( array(
							0  => __( 'All time', 'fraud-shield' ),
							12 => __( 'Last 12 months', 'fraud-shield' ),
							6  => __( 'Last 6 months', 'fraud-shield' ),
							3  => __( 'Last 3 months', 'fraud-shield' ),
						) as $mv => $mlbl ) {
							echo '<option value="' . (int) $mv . '"' . selected( $months, $mv, false ) . '>' . esc_html( $mlbl ) . '</option>';
						}
						?>
					</select>
					<label><?php esc_html_e( 'Min orders', 'fraud-shield' ); ?> <input type="number" name="min_orders" value="<?php echo (int) $min; ?>" min="1" style="width:70px;" /></label>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr( 'street' === $level ? __( 'Search street / city / state…', 'fraud-shield' ) : __( 'Search city / state / ZIP…', 'fraud-shield' ) ); ?>" class="regular-text" />
					<button class="button"><?php esc_html_e( 'Apply', 'fraud-shield' ); ?></button>
					<a class="button" href="<?php echo esc_url( $this->export_url( 'geo_' . $level, array( 's' => $search, 'sort' => $sort, 'dir' => $dir, 'months' => $months, 'min_orders' => $min ) ) ); ?>"><?php esc_html_e( 'Export CSV (Excel)', 'fraud-shield' ); ?></a>
				</p>
			</form>

			<p class="description">
			<?php
			/* translators: %s: number of destination groups */
			printf( esc_html( _n( '%s destination.', '%s destinations.', $total, 'fraud-shield' ) ), esc_html( number_format_i18n( $total ) ) );
			?>
			</p>

			<table class="wp-list-table widefat striped">
				<thead><tr>
					<?php
					$this->sort_th( __( 'Country', 'fraud-shield' ), 'country', $sort, $dir, $base_args );
					$this->sort_th( __( 'State', 'fraud-shield' ), 'state', $sort, $dir, $base_args );
					if ( 'city' === $level ) {
						$this->sort_th( __( 'City', 'fraud-shield' ), 'city', $sort, $dir, $base_args );
					} elseif ( 'zip' === $level ) {
						$this->sort_th( __( 'ZIP', 'fraud-shield' ), 'zip', $sort, $dir, $base_args );
					} elseif ( 'street' === $level ) {
						$this->sort_th( __( 'City', 'fraud-shield' ), 'city', $sort, $dir, $base_args );
						$this->sort_th( __( 'Street', 'fraud-shield' ), 'street', $sort, $dir, $base_args );
					}
					$this->sort_th( __( 'Orders', 'fraud-shield' ), 'orders', $sort, $dir, $base_args );
					$this->sort_th( __( 'Customers', 'fraud-shield' ), 'customers', $sort, $dir, $base_args );
					$this->sort_th( __( 'Avg score', 'fraud-shield' ), 'avg_score', $sort, $dir, $base_args );
					$this->sort_th( __( 'High/Block', 'fraud-shield' ), 'high', $sort, $dir, $base_args );
					$this->sort_th( __( 'CB', 'fraud-shield' ), 'chargebacks', $sort, $dir, $base_args );
					$this->sort_th( __( 'CB rate', 'fraud-shield' ), 'cb_rate', $sort, $dir, $base_args );
					$this->sort_th( __( 'Last order', 'fraud-shield' ), 'last_order', $sort, $dir, $base_args );
					?>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="<?php echo (int) $colspan; ?>"><?php esc_html_e( 'No destinations match. Lower the "Min orders" floor, widen the time window, or run the geo backfill.', 'fraud-shield' ); ?></td></tr>
				<?php else : ?>
					<?php
					foreach ( $rows as $r ) :
						$avg      = (float) $r['avg_score'];
						$avg_lvl  = FS_Rules::level_for_score( (int) round( $avg ), $settings );
						$row_key  = $this->geo_row_key( $level, $r );
						$cbs      = (int) $r['cbs'];
						$flagged  = ( $cbs > 0 || $avg >= (int) $settings['levels']['high'] );
						// Drill-down link: all customers shipping to this destination.
						if ( 'city' === $level ) {
							$drill_key = (string) $r['ship_city'];
						} elseif ( 'zip' === $level ) {
							$drill_key = (string) $r['ship_postcode'];
						} elseif ( 'street' === $level ) {
							$drill_key = (string) $r['ship_street'];
						} else {
							$drill_key = '';
						}
						$drill_args = array(
							'page'        => self::SLUG . '-geo',
							'fs_geo_view' => 1,
							'level'       => $level,
							'country'     => rawurlencode( (string) $r['shipping_country'] ),
							'state'       => rawurlencode( (string) $r['ship_state'] ),
							'key'         => rawurlencode( $drill_key ),
						);
						if ( 'street' === $level ) {
							$drill_args['city'] = rawurlencode( (string) $r['ship_city'] );
						}
						$drill_url = add_query_arg( $drill_args, admin_url( 'admin.php' ) );
						?>
						<tr<?php echo $flagged ? ' class="fs-row-flag"' : ''; ?>>
							<td><?php echo esc_html( $r['shipping_country'] ); ?></td>
							<?php if ( 'state' === $level ) : ?>
								<td><a href="<?php echo esc_url( $drill_url ); ?>"><strong><?php echo esc_html( $r['ship_state'] ); ?></strong></a></td>
							<?php else : ?>
								<td><?php echo esc_html( $r['ship_state'] ); ?></td>
							<?php endif; ?>
							<?php if ( 'city' === $level ) : ?>
								<td><a href="<?php echo esc_url( $drill_url ); ?>"><strong><?php echo esc_html( ucwords( (string) $r['ship_city'] ) ); ?></strong></a></td>
							<?php elseif ( 'zip' === $level ) : ?>
								<td><a href="<?php echo esc_url( $drill_url ); ?>"><strong><?php echo esc_html( $r['ship_postcode'] ); ?></strong></a></td>
							<?php elseif ( 'street' === $level ) : ?>
								<td><?php echo esc_html( ucwords( (string) $r['ship_city'] ) ); ?></td>
								<td><a href="<?php echo esc_url( $drill_url ); ?>"><strong><?php echo esc_html( ucwords( (string) $r['ship_street'] ) ); ?></strong></a></td>
							<?php endif; ?>
							<td><?php echo (int) $r['orders']; ?></td>
							<td><?php echo (int) $r['customers']; ?></td>
							<td><?php echo '<span class="fs-pill ' . esc_attr( 'fs-pill-' . ( array( 'low' => 'green', 'medium' => 'amber', 'high' => 'red', 'block' => 'black' )[ $avg_lvl ] ?? 'green' ) ) . '">' . esc_html( number_format_i18n( $avg, 1 ) ) . '</span>'; ?></td>
							<td><?php echo (int) $r['high_ct']; ?></td>
							<td><?php echo $cbs ? '<span class="fs-pill fs-pill-red">' . (int) $cbs . '</span>' : '—'; ?></td>
							<td><?php echo esc_html( number_format_i18n( (float) $r['cb_rate'], 1 ) ) . '%'; ?></td>
							<td><?php echo esc_html( $r['last_order'] ? mysql2date( 'Y-m-d', $r['last_order'] ) : '—' ); ?></td>
							<td><?php echo $this->place_lock_button( $level, $r, isset( $place_actions[ $row_key ] ) ? $place_actions[ $row_key ] : null ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( array_merge( $base_args, array( 'sort' => $sort, 'dir' => $dir ) ), admin_url( 'admin.php' ) ) . '%_%',
							'format'    => '&paged=%#%',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?>
				</div></div>
			<?php endif; ?>

			<?php $this->render_place_lock_form(); ?>
		</div>
		<?php
	}

	/**
	 * The canonical place key for a geo rollup row (matches how block_place and
	 * actions_for_targets build it).
	 *
	 * @param string $level 'state' | 'city' | 'zip' | 'street'.
	 * @param array  $r     Rollup row.
	 * @return string
	 */
	private function geo_row_key( $level, $r ) {
		if ( 'city' === $level ) {
			return FS_Normalizer::place_target( 'city', $r['shipping_country'], $r['ship_state'], $r['ship_city'] );
		}
		if ( 'zip' === $level ) {
			return FS_Normalizer::place_target( 'zip', $r['shipping_country'], $r['ship_postcode'] );
		}
		if ( 'street' === $level ) {
			return FS_Normalizer::place_target( 'street', $r['shipping_country'], $r['ship_state'], $r['ship_city'], $r['ship_street'] );
		}
		return FS_Normalizer::place_target( 'state', $r['shipping_country'], $r['ship_state'] );
	}

	/**
	 * Human label for a geo rollup row (used in confirm dialogs).
	 *
	 * @param string $level Level.
	 * @param array  $r     Rollup row.
	 * @return string
	 */
	private function geo_row_label( $level, $r ) {
		$cc = (string) $r['shipping_country'];
		if ( 'city' === $level ) {
			return ucwords( (string) $r['ship_city'] ) . ', ' . $r['ship_state'] . ' (' . $cc . ')';
		}
		if ( 'zip' === $level ) {
			return 'ZIP ' . $r['ship_postcode'] . '* (' . $cc . ')';
		}
		if ( 'street' === $level ) {
			return ucwords( (string) $r['ship_street'] ) . ', ' . ucwords( (string) $r['ship_city'] ) . ', ' . $r['ship_state'] . ' (' . $cc . ')';
		}
		return $r['ship_state'] . ' (' . $cc . ')';
	}

	/**
	 * Lock / Unlock toggle for a geography row. Two-state (a legacy pre-1.6 state
	 * is impossible for the new 'place' type).
	 *
	 * @param string     $level  Level.
	 * @param array      $r      Rollup row.
	 * @param array|null $action Existing place action [ 'id'=>int, 'entries'=>int ] or null.
	 * @return string HTML.
	 */
	private function place_lock_button( $level, $r, $action ) {
		$scope = in_array( $level, array( 'zip', 'city', 'street' ), true ) ? $level : 'state';
		$args  = array(
			'action'  => 'fs_block_place',
			'scope'   => $scope,
			'country' => (string) $r['shipping_country'],
		);
		if ( 'zip' === $scope ) {
			$args['prefix'] = (string) $r['ship_postcode'];
		} else {
			$args['state'] = (string) $r['ship_state'];
			if ( 'city' === $scope || 'street' === $scope ) {
				$args['city'] = (string) $r['ship_city'];
			}
			if ( 'street' === $scope ) {
				// Already normalized — handed over as the key, never re-parsed.
				$args['street_key'] = (string) $r['ship_street'];
				// The road's boundary: every city name and ZIP it has been
				// typed under in this state (v1.31).
				$bounds                  = FS_Data::street_boundaries( (string) $r['shipping_country'], (string) $r['ship_state'], (string) $r['ship_street'] );
				$args['boundary_cities'] = implode( ',', $bounds['cities'] );
				$args['boundary_zips']   = implode( ',', $bounds['zips'] );
			}
		}
		$url = wp_nonce_url( add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), admin_url( 'admin-post.php' ) ), 'fs_block_place' );

		if ( is_array( $action ) && isset( $action['id'] ) ) {
			return '<a class="button button-small fs-blocked-btn" href="' . esc_url( $url ) . '" onclick="return confirm(\'' . esc_js( __( 'Remove this destination lock? Checkouts shipping here will be allowed again.', 'fraud-shield' ) ) . '\');">&#10004; ' . esc_html__( 'Locked — click to unlock', 'fraud-shield' ) . '</a>';
		}
		if ( 'street' === $scope ) {
			$confirm = sprintf(
				/* translators: 1: street label, 2: order count, 3: customer count, 4: city names, 5: ZIP codes */
				__( 'Ban ALL checkouts shipping to any address on %1$s? %2$d orders from %3$d customers have shipped to this street historically — legitimate neighbours will be blocked too. The ban covers this road under every city name (%4$s) and ZIP (%5$s) it has been typed with; the same road name elsewhere is not affected. Verified customers are exempt; this never cascades to identities.', 'fraud-shield' ),
				$this->geo_row_label( $level, $r ),
				(int) $r['orders'],
				(int) $r['customers'],
				implode( ', ', array_map( 'ucwords', $bounds['cities'] ) ),
				implode( ', ', $bounds['zips'] )
			);
		} else {
			$confirm = sprintf(
				/* translators: 1: destination label, 2: order count, 3: customer count */
				__( 'Block ALL checkouts shipping to %1$s? %2$d orders from %3$d customers have shipped here historically — legitimate customers will be blocked too. Verified customers are exempt. Consider a ZIP-prefix lock for tighter targeting.', 'fraud-shield' ),
				$this->geo_row_label( $level, $r ),
				(int) $r['orders'],
				(int) $r['customers']
			);
		}
		return '<a class="button button-small" href="' . esc_url( $url ) . '" onclick="return confirm(\'' . esc_js( $confirm ) . '\');">' . esc_html__( 'Lock', 'fraud-shield' ) . '</a>';
	}

	/**
	 * Manual "Add destination lock" panel on the Geography page.
	 */
	private function render_place_lock_form() {
		$countries = array();
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();
		}
		$base_country = ( function_exists( 'wc_get_base_location' ) ) ? ( wc_get_base_location()['country'] ?? '' ) : '';
		?>
		<div class="fs-panel">
			<h2><?php esc_html_e( 'Add destination lock', 'fraud-shield' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Lock a destination even if it has no history yet (e.g. a ZIP corridor you spotted). City locks match the EXACT normalized city name; ZIP-prefix locks are the precision tool for metro-wide coverage. Verified customers are always exempt, and destination locks never cascade to identities.', 'fraud-shield' ); ?></p>
			<p class="description">
				<?php esc_html_e( 'Street locks block every checkout shipping to any house on one street: type the street with or without a house number ("123 N. Main Street", "N Main St" and "North Main Street" all lock the same street); unit numbers are ignored and directionals and street types are kept, so N Main St, S Main St and N Main Ave are different streets. Give the road its boundary below — every city name, ZIP and county it runs through — so a rotated ZIP or an alternative city name still meets the lock while the same road name in another county does not.', 'fraud-shield' ); ?>
				<?php esc_html_e( 'Building locks block one house number on one street anywhere in the state — every apartment, suite, mailbox, forwarding-account number, business and recipient in it, under any city name or ZIP. Area locks block every address that the geocoder resolves to a point within a radius of a location — the place itself, however it is written.', 'fraud-shield' ); ?>
				<?php
				/* translators: %s: the default checkout message for street locks */
				printf( esc_html__( 'A street or building lock shows the customer "%s" unless you write a message below (editable later on its Blocklist card).', 'fraud-shield' ), esc_html( FS_Blocklist::street_default_message() ) );
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'fs_block_place' ); ?>
				<input type="hidden" name="action" value="fs_block_place" />
				<p class="fs-filters">
					<label><?php esc_html_e( 'Scope', 'fraud-shield' ); ?>
						<select name="scope">
							<option value="city"><?php esc_html_e( 'City', 'fraud-shield' ); ?></option>
							<option value="state"><?php esc_html_e( 'State', 'fraud-shield' ); ?></option>
							<option value="zip"><?php esc_html_e( 'ZIP prefix', 'fraud-shield' ); ?></option>
							<option value="street"><?php esc_html_e( 'Street', 'fraud-shield' ); ?></option>
							<option value="building"><?php esc_html_e( 'Building (one house number, every unit)', 'fraud-shield' ); ?></option>
							<option value="geo"><?php esc_html_e( 'Area (a radius around the address)', 'fraud-shield' ); ?></option>
						</select>
					</label>
					<label><?php esc_html_e( 'Country', 'fraud-shield' ); ?>
						<?php if ( ! empty( $countries ) ) : ?>
							<select name="country">
								<?php foreach ( $countries as $code => $name ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $base_country ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input type="text" name="country" value="<?php echo esc_attr( $base_country ); ?>" placeholder="US" size="4" />
						<?php endif; ?>
					</label>
					<input type="text" name="state" placeholder="<?php esc_attr_e( 'State (2-letter code for US/CA/AU)', 'fraud-shield' ); ?>" />
					<input type="text" name="city" placeholder="<?php esc_attr_e( 'City (for City and Street scopes)', 'fraud-shield' ); ?>" />
					<input type="text" name="prefix" placeholder="<?php esc_attr_e( 'ZIP prefix (for ZIP scope), e.g. 331', 'fraud-shield' ); ?>" />
					<input type="text" name="street" class="regular-text" placeholder="<?php esc_attr_e( 'Street (Street and Building scopes), e.g. 8020 NW 66th St — Street ignores the house number, Building keeps it', 'fraud-shield' ); ?>" />
				</p>
				<p class="fs-filters">
					<input type="text" name="boundary_cities" class="regular-text" placeholder="<?php esc_attr_e( 'Street boundary — other city names, comma-separated (e.g. Doral, Medley, Miami Springs)', 'fraud-shield' ); ?>" />
					<input type="text" name="boundary_zips" class="regular-text" placeholder="<?php esc_attr_e( 'Street boundary — ZIP codes, comma-separated (e.g. 33166, 33122, 33172)', 'fraud-shield' ); ?>" />
					<input type="text" name="boundary_counties" class="regular-text" placeholder="<?php esc_attr_e( 'Street boundary — counties, comma-separated (e.g. Miami-Dade; matched through address validation)', 'fraud-shield' ); ?>" />
					<label><?php esc_html_e( 'Area radius', 'fraud-shield' ); ?> <input type="number" name="radius_mi" step="0.05" min="0.05" max="100" value="0.5" class="small-text" /> <?php esc_html_e( 'miles (Area scope: the street, city, state and ZIP above are geocoded to find the centre)', 'fraud-shield' ); ?></label>
				</p>
				<p>
					<input type="text" name="message" class="large-text" placeholder="<?php esc_attr_e( 'Custom checkout message for this lock (optional — leave empty to use the default block message)', 'fraud-shield' ); ?>" />
				</p>
				<p><button class="button button-primary"><?php esc_html_e( 'Add destination lock', 'fraud-shield' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Geography drill-down: every customer identity shipping to one destination,
	 * as a sortable/searchable/paginated table with a per-row Block toggle. Rows
	 * link to the full customer profile.
	 *
	 * @param string $level   'state' | 'city' | 'zip' | 'street'.
	 * @param string $country Country code.
	 * @param string $state   State/region.
	 * @param string $key     City (city level), postcode (zip level) or normalized street (street level).
	 * @param string $city    City (street level only).
	 */
	private function render_geo_customers( $level, $country, $state, $key, $city = '' ) {
		$settings = FS_Core::instance()->settings();

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';        // phpcs:ignore WordPress.Security.NonceVerification
		$sort   = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'spend';    // phpcs:ignore WordPress.Security.NonceVerification
		$dir    = ( isset( $_GET['dir'] ) && 'asc' === $_GET['dir'] ) ? 'asc' : 'desc';              // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;                      // phpcs:ignore WordPress.Security.NonceVerification
		$per    = 50;

		$total = FS_Data::geo_customers_count( $level, $country, $state, $key, $search, $city );
		$rows  = FS_Data::geo_customers(
			$level,
			$country,
			$state,
			$key,
			array(
				'orderby' => $sort,
				'dir'     => $dir,
				'search'  => $search,
				'limit'   => $per,
				'offset'  => ( $paged - 1 ) * $per,
				'city'    => $city,
			)
		);
		$pages = max( 1, (int) ceil( $total / $per ) );

		$cc = strtoupper( trim( (string) $country ) );
		if ( 'city' === $level ) {
			$label = ucwords( FS_Normalizer::city( $key ) ) . ', ' . FS_Normalizer::region( $state ) . ' (' . $cc . ')';
		} elseif ( 'zip' === $level ) {
			$label = 'ZIP ' . FS_Normalizer::postcode( $key ) . ' — ' . FS_Normalizer::region( $state ) . ' (' . $cc . ')';
		} elseif ( 'street' === $level ) {
			$label = ucwords( FS_Normalizer::canonicalize( $key ) ) . ', ' . ucwords( FS_Normalizer::city( $city ) ) . ', ' . FS_Normalizer::region( $state ) . ' (' . $cc . ')';
		} else {
			$label = FS_Normalizer::region( $state ) . ' (' . $cc . ')';
		}

		$back      = add_query_arg( array( 'page' => self::SLUG . '-geo', 'level' => $level ), admin_url( 'admin.php' ) );
		$base_args = array(
			'page'        => self::SLUG . '-geo',
			'fs_geo_view' => 1,
			'level'       => $level,
			'country'     => $country,
			'state'       => $state,
			'key'         => $key,
			's'           => $search,
		);
		if ( 'street' === $level ) {
			$base_args['city'] = $city;
		}

		// Batch block-state for the row toggles.
		$emails = array();
		foreach ( (array) $rows as $r ) {
			$emails[] = $r['email_norm'];
		}
		$g_actions = FS_Blocklist::actions_for_targets( 'profile', $emails );
		$g_blocked = FS_Blocklist::blocked_values( 'email', $emails );
		?>
		<div class="wrap fs-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Customers shipping to', 'fraud-shield' ); ?> <?php echo esc_html( $label ); ?></h1>
			<a href="<?php echo esc_url( $back ); ?>" class="page-title-action">&larr; <?php esc_html_e( 'Back to Geography', 'fraud-shield' ); ?></a>
			<?php $this->maybe_notice(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-geo' ); ?>" />
				<input type="hidden" name="fs_geo_view" value="1" />
				<input type="hidden" name="level" value="<?php echo esc_attr( $level ); ?>" />
				<input type="hidden" name="country" value="<?php echo esc_attr( $country ); ?>" />
				<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>" />
				<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>" />
				<?php if ( 'street' === $level ) : ?>
					<input type="hidden" name="city" value="<?php echo esc_attr( $city ); ?>" />
				<?php endif; ?>
				<p class="fs-filters">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email, name, phone, IP, address…', 'fraud-shield' ); ?>" class="regular-text" />
					<button class="button"><?php esc_html_e( 'Search', 'fraud-shield' ); ?></button>
				</p>
			</form>

			<p class="description">
			<?php
			/* translators: %s: number of customers */
			printf( esc_html( _n( '%s customer.', '%s customers.', $total, 'fraud-shield' ) ), esc_html( number_format_i18n( $total ) ) );
			?>
			</p>

			<table class="wp-list-table widefat striped">
				<thead><tr>
					<?php
					$this->sort_th( __( 'Customer', 'fraud-shield' ), 'name', $sort, $dir, $base_args );
					$this->sort_th( __( 'Email', 'fraud-shield' ), 'email', $sort, $dir, $base_args );
					$this->sort_th( __( 'Orders', 'fraud-shield' ), 'orders', $sort, $dir, $base_args );
					$this->sort_th( __( 'Paid', 'fraud-shield' ), 'paid', $sort, $dir, $base_args );
					$this->sort_th( __( 'Spend (paid)', 'fraud-shield' ), 'spend', $sort, $dir, $base_args );
					$this->sort_th( __( 'CB', 'fraud-shield' ), 'chargebacks', $sort, $dir, $base_args );
					$this->sort_th( __( 'IPs', 'fraud-shield' ), 'ips', $sort, $dir, $base_args );
					$this->sort_th( __( 'Addresses', 'fraud-shield' ), 'addresses', $sort, $dir, $base_args );
					$this->sort_th( __( 'Max risk', 'fraud-shield' ), 'risk', $sort, $dir, $base_args );
					$this->sort_th( __( 'Last order', 'fraud-shield' ), 'last', $sort, $dir, $base_args );
					?>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="11"><?php esc_html_e( 'No customers match.', 'fraud-shield' ); ?></td></tr>
				<?php else : ?>
					<?php
					foreach ( $rows as $r ) :
						$email       = $r['email_norm'];
						$profile_url = add_query_arg( array( 'page' => self::SLUG . '-profiles', 'fs_profile' => rawurlencode( $email ) ), admin_url( 'admin.php' ) );
						?>
						<tr<?php echo ( (int) $r['chargebacks'] > 0 ) ? ' class="fs-row-flag"' : ''; ?>>
							<td><a href="<?php echo esc_url( $profile_url ); ?>"><strong><?php echo esc_html( $r['customer_name'] ? $r['customer_name'] : __( '(no name)', 'fraud-shield' ) ); ?></strong></a></td>
							<td><?php echo esc_html( $email ); ?></td>
							<td><?php echo (int) $r['orders']; ?></td>
							<td><?php echo (int) $r['paid_orders']; ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) $r['spend'] ) ); ?></td>
							<td><?php echo (int) $r['chargebacks'] ? '<span class="fs-pill fs-pill-red">' . (int) $r['chargebacks'] . '</span>' : '—'; ?></td>
							<td><?php echo (int) $r['ips']; ?></td>
							<td><?php echo (int) $r['addresses']; ?></td>
							<td><?php echo $this->level_pill( FS_Rules::level_for_score( (int) $r['max_risk'], $settings ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo (int) $r['max_risk']; ?></td>
							<td><?php echo esc_html( $r['last_order'] ? mysql2date( 'Y-m-d', $r['last_order'] ) : '—' ); ?></td>
							<td>
								<a class="button button-small button-primary" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Profile', 'fraud-shield' ); ?></a>
								<?php echo $this->block_profile_button( $email, isset( $g_actions[ $email ] ) ? $g_actions[ $email ] : null, in_array( $email, $g_blocked, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( array_merge( $base_args, array( 'sort' => $sort, 'dir' => $dir ) ), admin_url( 'admin.php' ) ) . '%_%',
							'format'    => '&paged=%#%',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Shared renderer for the Addresses / IPs registry pages: searchable,
	 * sortable, paginated, exportable, with View (profile) + Block actions.
	 *
	 * @param string $type 'address' | 'ip'.
	 */
	private function render_clusters_page( $type ) {
		$is_ip = ( 'ip' === $type );

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$sort   = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'emails'; // phpcs:ignore WordPress.Security.NonceVerification
		$dir    = ( isset( $_GET['dir'] ) && 'asc' === $_GET['dir'] ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification
		list( $sort2, $dir2 ) = $this->sort2_from_request();
		$this->cur_sort2      = $sort2;
		$this->cur_dir2       = $dir2;
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$per    = 50;

		$total = FS_Data::clusters_count( $type, $search );
		$rows  = FS_Data::clusters_list(
			$type,
			array(
				'orderby'  => $sort,
				'dir'      => $dir,
				'orderby2' => $sort2,
				'dir2'     => $dir2,
				'search'   => $search,
				'limit'    => $per,
				'offset'   => ( $paged - 1 ) * $per,
			)
		);
		$pages = max( 1, (int) ceil( $total / $per ) );

		$slug      = $is_ip ? self::SLUG . '-ips' : self::SLUG . '-addresses';
		$base_args = array(
			'page'  => $slug,
			's'     => $search,
			'sort2' => $sort2,
			'dir2'  => $dir2,
		);
		$blocked   = FS_Blocklist::blocked_values( $is_ip ? 'ip' : 'address', wp_list_pluck( $rows, 'cluster_key' ) );
		$actions   = FS_Blocklist::actions_for_targets( $is_ip ? 'ip' : 'address', wp_list_pluck( $rows, 'cluster_key' ) );
		$vcreds    = FS_Live::verified_creds();
		?>
		<div class="wrap fs-wrap">
			<h1><?php echo esc_html( $is_ip ? __( 'IP Addresses', 'fraud-shield' ) : __( 'Shipping Addresses', 'fraud-shield' ) ); ?></h1>
			<?php $this->maybe_notice(); ?>
			<p class="description"><?php echo esc_html( $is_ip ? __( 'Every IP address seen across your orders — not just the risky ones. Click any IP for its full profile: every account and order behind it, payment methods, coupons, and devices.', 'fraud-shield' ) : __( 'Every shipping address seen across your orders — not just the risky ones. Click any address for its full profile: every account and order behind it, payment methods, coupons, and devices.', 'fraud-shield' ) ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( $slug ); ?>" />
				<p class="fs-filters">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search address, email, name, phone, IP…', 'fraud-shield' ); ?>" class="regular-text" />
					<button class="button"><?php esc_html_e( 'Search', 'fraud-shield' ); ?></button>
					<a class="button" href="<?php echo esc_url( $this->export_url( $is_ip ? 'all_ips' : 'all_addresses', array( 's' => $search, 'sort' => $sort, 'dir' => $dir ) ) ); ?>"><?php esc_html_e( 'Export CSV (Excel)', 'fraud-shield' ); ?></a>
				</p>
			</form>

			<p class="description">
			<?php
			if ( $is_ip ) {
				/* translators: %s: number of IPs */
				printf( esc_html( _n( '%s IP address.', '%s IP addresses.', $total, 'fraud-shield' ) ), esc_html( number_format_i18n( $total ) ) );
			} else {
				/* translators: %s: number of addresses */
				printf( esc_html( _n( '%s shipping address.', '%s shipping addresses.', $total, 'fraud-shield' ) ), esc_html( number_format_i18n( $total ) ) );
			}
			?>
			</p>

			<table class="wp-list-table widefat striped">
				<thead><tr>
					<?php
					if ( $is_ip ) {
						$this->sort_th( __( 'IP', 'fraud-shield' ), 'key', $sort, $dir, $base_args );
					} else {
						$this->sort_th( __( 'Shipping address', 'fraud-shield' ), 'address', $sort, $dir, $base_args );
					}
					$this->sort_th( $is_ip ? __( 'Accounts', 'fraud-shield' ) : __( 'Emails', 'fraud-shield' ), 'emails', $sort, $dir, $base_args );
					$this->sort_th( __( 'Names', 'fraud-shield' ), 'names', $sort, $dir, $base_args );
					$this->sort_th( __( 'Orders', 'fraud-shield' ), 'orders', $sort, $dir, $base_args );
					$this->sort_th( __( 'Paid', 'fraud-shield' ), 'paid', $sort, $dir, $base_args );
					$this->sort_th( __( 'Spend (paid)', 'fraud-shield' ), 'spend', $sort, $dir, $base_args );
					$this->sort_th( __( 'CB', 'fraud-shield' ), 'chargebacks', $sort, $dir, $base_args );
					$this->sort_th( $is_ip ? __( 'Addresses', 'fraud-shield' ) : __( 'IPs', 'fraud-shield' ), 'other', $sort, $dir, $base_args );
					$this->sort_th( __( 'Max risk', 'fraud-shield' ), 'risk', $sort, $dir, $base_args );
					$this->sort_th( __( 'Last order', 'fraud-shield' ), 'last', $sort, $dir, $base_args );
					?>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="11"><?php esc_html_e( 'Nothing indexed yet. Run a scan or import a CSV first.', 'fraud-shield' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<?php
						$key      = $r['cluster_key'];
						$view_url = add_query_arg(
							array(
								'page'    => self::SLUG,
								'fs_view' => $type,
								'fs_key'  => rawurlencode( $key ),
							),
							admin_url( 'admin.php' )
						);
						if ( $is_ip ) {
							$label = $key;
							$loc   = $this->ip_location_label( $key );
						} else {
							/* translators: %s: first characters of an address hash */
							$label = $r['ship_text'] ? $r['ship_text'] : sprintf( __( '(hash %s…)', 'fraud-shield' ), substr( $key, 0, 10 ) );
							$loc   = '';
						}
						$flagged = ( (int) $r['chargebacks'] > 0 || (int) $r['emails'] >= 3 );
						?>
						<tr<?php echo $flagged ? ' class="fs-row-flag"' : ''; ?>>
							<td>
								<a href="<?php echo esc_url( $view_url ); ?>"><strong><?php echo esc_html( $label ); ?></strong></a>
								<?php if ( '' !== $loc ) : ?>
									<br/><span class="fs-muted"><?php echo esc_html( $loc ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo (int) $r['emails']; ?></td>
							<td><?php echo (int) $r['names']; ?></td>
							<td><?php echo (int) $r['orders']; ?></td>
							<td><?php echo (int) $r['paid_orders']; ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) $r['spend'] ) ); ?></td>
							<td><?php echo (int) $r['chargebacks'] ? '<span class="fs-pill fs-pill-red">' . (int) $r['chargebacks'] . '</span>' : '—'; ?></td>
							<td><?php echo (int) $r['other_dim']; ?></td>
							<td><?php echo (int) $r['max_risk']; ?></td>
							<td><?php echo esc_html( $r['last_order'] ? mysql2date( 'Y-m-d', $r['last_order'] ) : '—' ); ?></td>
							<td>
								<a class="button button-small button-primary" href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'View', 'fraud-shield' ); ?></a>
								<?php echo $this->block_cluster_button( $type, $key, isset( $actions[ $key ] ) ? $actions[ $key ] : null, in_array( $key, $blocked, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php echo $this->verify_button( $is_ip ? 'ip' : 'address', $key, isset( $vcreds[ ( $is_ip ? 'ip:' : 'address:' ) . $key ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( array_merge( $base_args, array( 'sort' => $sort, 'dir' => $dir ) ), admin_url( 'admin.php' ) ) . '%_%',
							'format'    => '&paged=%#%',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ===================== Import CSV ===================== */

	/**
	 * Import CSV page: upload → confirm column mapping → resumable import + score.
	 */
	public function page_import() {
		$this->guard();

		$state    = FS_Importer::get_state();
		$status   = $state['status'];
		$headers  = is_array( $state['headers'] ) ? $state['headers'] : array();
		$labels   = FS_Importer::field_labels();
		$mid_flow = ( 'ready' === $status || 'running' === $status );

		// Upload-step notices.
		$notice = '';
		$is_err = false;
		if ( ! empty( $_GET['fs_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			switch ( sanitize_key( wp_unslash( $_GET['fs_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				case 'uploaded':
					$notice = __( 'CSV uploaded. Confirm the column mapping below, then start the import.', 'fraud-shield' );
					break;
				case 'nofile':
					$notice = __( 'No file was selected.', 'fraud-shield' );
					$is_err = true;
					break;
				case 'badfile':
					$notice = __( 'Please upload a .csv file.', 'fraud-shield' );
					$is_err = true;
					break;
				case 'movefail':
					$notice = __( 'Could not save the uploaded file — check the uploads folder permissions.', 'fraud-shield' );
					$is_err = true;
					break;
			}
		}
		?>
		<div class="wrap fs-wrap">
			<h1><?php esc_html_e( 'Import orders from CSV', 'fraud-shield' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Upload an exported orders CSV to populate the index without walking the live order database — much faster on large stores. After importing, Fraud Shield scores every order automatically. When you only change settings later, use “Recompute scores” on the dashboard instead of re-importing.', 'fraud-shield' ); ?></p>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice <?php echo $is_err ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'complete' === $status ) : ?>
				<div class="notice notice-success"><p>
				<?php
				/* translators: %s: number of imported orders */
				printf( esc_html__( 'Import complete — %s orders imported and scored.', 'fraud-shield' ), esc_html( number_format_i18n( (int) $state['imported'] ) ) );
				?>
				</p></div>
			<?php endif; ?>

			<?php if ( $mid_flow ) : ?>
				<div class="fs-panel">
				<?php if ( ! empty( $state['native'] ) ) : ?>
					<h2><?php esc_html_e( 'Restore Fraud Shield export', 'fraud-shield' ); ?></h2>
					<p class="description">
					<?php
					/* translators: %s: uploaded file name */
					printf( esc_html__( 'File: %s. This is a Fraud Shield index export — its rows (including risk scores and chargeback flags) will be restored directly, so no column mapping is needed.', 'fraud-shield' ), '<code>' . esc_html( $state['filename'] ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput
					?>
					</p>
				<?php else : ?>
					<h2><?php esc_html_e( 'Map your columns', 'fraud-shield' ); ?></h2>
					<p class="description">
					<?php
					/* translators: %s: uploaded file name */
					printf( esc_html__( 'File: %s. We auto-matched the columns below — adjust any that look wrong. Order ID is required.', 'fraud-shield' ), '<code>' . esc_html( $state['filename'] ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput
					?>
					</p>
					<table class="form-table fs-map-table" role="presentation">
						<?php
						foreach ( FS_Importer::FIELDS as $field ) {
							$sel = isset( $state['mapping'][ $field ] ) ? (int) $state['mapping'][ $field ] : -1;
							echo '<tr><th scope="row">' . esc_html( $labels[ $field ] ) . '</th><td>';
							echo '<select class="fs-map" data-field="' . esc_attr( $field ) . '">';
							echo '<option value="-1">' . esc_html__( '— not mapped —', 'fraud-shield' ) . '</option>';
							foreach ( $headers as $i => $h ) {
								/* translators: %d: 1-based column number */
								$label = ( '' !== $h ) ? $h : sprintf( __( 'Column %d', 'fraud-shield' ), (int) $i + 1 );
								echo '<option value="' . (int) $i . '"' . selected( $sel, $i, false ) . '>' . esc_html( $label ) . '</option>';
							}
							echo '</select></td></tr>';
						}
						?>
					</table>
				<?php endif; ?>

					<p>
						<button class="button button-primary" id="fs-import-start"><?php echo ! empty( $state['native'] ) ? esc_html__( 'Restore now', 'fraud-shield' ) : esc_html__( 'Start import', 'fraud-shield' ); ?></button>
						<button class="button button-primary" id="fs-import-resume" style="<?php echo ( 'running' === $status ) ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Resume import', 'fraud-shield' ); ?></button>
						<button class="button button-link-delete" id="fs-import-cancel"><?php esc_html_e( 'Discard file', 'fraud-shield' ); ?></button>
					</p>
					<div id="fs-import-progress" class="fs-progress" style="display:none;">
						<div class="fs-progress-bar"><span id="fs-import-fill"></span></div>
						<p id="fs-import-text"><?php esc_html_e( 'Starting…', 'fraud-shield' ); ?></p>
					</div>
				</div>
			<?php else : ?>
				<div class="fs-panel">
					<h2><?php esc_html_e( 'Upload a CSV', 'fraud-shield' ); ?></h2>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'fs_import_upload' ); ?>
						<input type="hidden" name="action" value="fs_import_upload" />
						<p>
							<input type="file" name="fs_csv" accept=".csv,text/csv,text/plain" required />
							<button class="button button-primary"><?php esc_html_e( 'Upload CSV', 'fraud-shield' ); ?></button>
						</p>
						<p class="description">
						<?php
						/* translators: %s: max upload size, e.g. 64 MB */
						printf( esc_html__( 'Maximum upload size on this server: %s. The next step lets you match your columns to the fields Fraud Shield needs.', 'fraud-shield' ), esc_html( size_format( wp_max_upload_size() ) ) );
						?>
						</p>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ===================== Blocklist ===================== */

	/**
	 * Blocklist page.
	 */
	public function page_blocklist() {
		$this->guard();

		$paged           = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$search          = isset( $_GET['s'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
		$type            = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$type            = in_array( $type, FS_Blocklist::TYPES, true ) ? $type : '';
		$per             = 100;
		$total_entries   = FS_Blocklist::count();
		$total_actions   = FS_Blocklist::actions_count();
		$ungrouped_total = FS_Blocklist::ungrouped_count();
		$ip_total        = FS_Blocklist::count_type( 'ip' );
		$type_labels     = array(
			'email'   => __( 'Email', 'fraud-shield' ),
			'phone'   => __( 'Phone', 'fraud-shield' ),
			'ip'      => __( 'IP address', 'fraud-shield' ),
			'address' => __( 'Address', 'fraud-shield' ),
			'user'    => __( 'Account', 'fraud-shield' ),
			'place'   => __( 'Destination lock', 'fraud-shield' ),
		);
		$results         = null;
		if ( '' !== $search || '' !== $type ) {
			// Search / filter mode (v1.27): only the cards and credentials that match.
			$results = FS_Blocklist::search( $search, $per, ( $paged - 1 ) * $per, $type );
			$pages   = max( 1, (int) ceil( $results['actions_total'] / $per ) );
			$actions = $results['actions'];
		} else {
			$pages   = max( 1, (int) ceil( $total_actions / $per ) );
			$actions = FS_Blocklist::all_actions_with_entries( $per, ( $paged - 1 ) * $per );
		}
		$list_url  = admin_url( 'admin.php?page=' . self::SLUG . '-blocklist' );
		$keep_args = array_filter(
			array(
				's'     => $search,
				'type'  => $type,
				'paged' => $paged > 1 ? $paged : '',
			),
			'strlen'
		);
		?>
		<div class="wrap fs-wrap">
			<h1><?php esc_html_e( 'Blocklist', 'fraud-shield' ); ?></h1>
			<?php $this->maybe_notice(); ?>

			<form method="get" class="fs-blocklist-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-blocklist' ); ?>" />
				<p class="fs-filters">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Search email, phone, IP, account id, address, block name or reason…', 'fraud-shield' ); ?>" />
					<label><?php esc_html_e( 'Type', 'fraud-shield' ); ?>
						<select name="type">
							<option value=""><?php esc_html_e( 'All types', 'fraud-shield' ); ?></option>
							<?php foreach ( $type_labels as $fs_t => $fs_tl ) : ?>
								<option value="<?php echo esc_attr( $fs_t ); ?>" <?php selected( $type, $fs_t ); ?>><?php echo esc_html( $fs_tl ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button class="button"><?php esc_html_e( 'Search', 'fraud-shield' ); ?></button>
					<?php if ( '' !== $search || '' !== $type ) : ?>
						<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Clear', 'fraud-shield' ); ?></a>
					<?php endif; ?>
					<span class="fs-muted"><?php esc_html_e( 'Finds blocks by name and credentials by value or reason; emails and phones are matched in their normalized form too. Pick a type to see only those credentials — “IP address” then “Select all” is every IP on the page.', 'fraud-shield' ); ?></span>
				</p>
			</form>

			<?php if ( null !== $results ) : ?>
				<div class="fs-panel fs-search-results">
					<h2>
					<?php
					if ( '' !== $search && '' !== $type ) {
						/* translators: 1: the search text, 2: credential type, 3: blocks matched, 4: credentials matched */
						$fs_head = sprintf( __( 'Results for “%1$s” among %2$s credentials: %3$s blocks · %4$s credentials', 'fraud-shield' ), $search, $type_labels[ $type ], number_format_i18n( $results['actions_total'] ), number_format_i18n( $results['entries_total'] ) );
					} elseif ( '' !== $type ) {
						/* translators: 1: credential type, 2: blocks, 3: credentials */
						$fs_head = sprintf( __( 'Every %1$s credential: %2$s blocks · %3$s credentials', 'fraud-shield' ), $type_labels[ $type ], number_format_i18n( $results['actions_total'] ), number_format_i18n( $results['entries_total'] ) );
					} else {
						/* translators: 1: the search text, 2: blocks matched, 3: credentials matched, 4: IP credentials matched */
						$fs_head = sprintf( __( 'Results for “%1$s”: %2$s blocks · %3$s credentials (%4$s IP addresses)', 'fraud-shield' ), $search, number_format_i18n( $results['actions_total'] ), number_format_i18n( $results['entries_total'] ), number_format_i18n( $results['ip_total'] ) );
					}
					echo esc_html( $fs_head );
					?>
					</h2>
					<?php if ( $results['ip_total'] > 0 ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<?php wp_nonce_field( 'fs_blocklist_remove_ips' ); ?>
							<input type="hidden" name="action" value="fs_blocklist_remove_ips" />
							<input type="hidden" name="mode" value="search" />
							<input type="hidden" name="q" value="<?php echo esc_attr( $search ); ?>" />
							<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>" />
							<button class="button button-link-delete" onclick="return confirm('<?php echo esc_js( sprintf( /* translators: %s: number of IP credentials */ _n( 'Remove the %s IP address this search matches from the blocklist?', 'Remove all %s IP addresses this search matches from the blocklist?', $results['ip_total'], 'fraud-shield' ), number_format_i18n( $results['ip_total'] ) ) ); ?>');">
								<?php echo esc_html( sprintf( /* translators: %s: number of IP credentials */ _n( 'Remove the %s matching IP address', 'Remove the %s matching IP addresses', $results['ip_total'], 'fraud-shield' ), number_format_i18n( $results['ip_total'] ) ) ); ?>
							</button>
						</form>
						<span class="fs-muted"><?php esc_html_e( 'Only the IP credentials go; every other credential in the same blocks stays.', 'fraud-shield' ); ?></span>
					<?php endif; ?>
					<?php if ( 0 === $results['actions_total'] && 0 === $results['ungrouped_total'] ) : ?>
						<p><?php esc_html_e( 'Nothing on the blocklist matches. Addresses are stored as hashes: search the block\'s name (which carries the address as it was blocked), or the first characters of the hash shown on its row.', 'fraud-shield' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Add entry', 'fraud-shield' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'fs_blocklist_add' ); ?>
					<input type="hidden" name="action" value="fs_blocklist_add" />
					<p>
						<select name="type">
							<option value="email"><?php esc_html_e( 'Email', 'fraud-shield' ); ?></option>
							<option value="phone"><?php esc_html_e( 'Phone', 'fraud-shield' ); ?></option>
							<option value="address"><?php esc_html_e( 'Address (text)', 'fraud-shield' ); ?></option>
							<option value="ip"><?php esc_html_e( 'IP', 'fraud-shield' ); ?></option>
							<option value="user"><?php esc_html_e( 'User ID', 'fraud-shield' ); ?></option>
						</select>
						<input type="text" name="value" class="regular-text" placeholder="<?php esc_attr_e( 'Value to block', 'fraud-shield' ); ?>" required />
						<input type="text" name="reason" class="regular-text" placeholder="<?php esc_attr_e( 'Reason (optional)', 'fraud-shield' ); ?>" />
						<button class="button button-primary"><?php esc_html_e( 'Add to blocklist', 'fraud-shield' ); ?></button>
					</p>
					<p>
						<input type="text" name="message" class="large-text" placeholder="<?php esc_attr_e( 'Custom checkout message for this entry (optional — leave empty to use the default block message)', 'fraud-shield' ); ?>" />
					</p>
					<p class="description"><?php esc_html_e( 'Email/phone are normalized automatically (gmail aliases, phone formatting). Address is normalized and hashed. The custom message is what THIS blocked person sees at checkout — each entry can have its own.', 'fraud-shield' ); ?></p>
				</form>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Bulk-remove IP addresses', 'fraud-shield' ); ?> <span class="fs-pill"><?php echo esc_html( sprintf( /* translators: %s: number of IP credentials on the list */ _n( '%s IP on the list', '%s IPs on the list', $ip_total, 'fraud-shield' ), number_format_i18n( $ip_total ) ) ); ?></span></h2>
				<p class="description"><?php esc_html_e( 'An IP is the noisiest credential — carrier and campus networks put strangers behind one address — so IP blocks are the ones most often taken back. Removing an IP credential removes it from every block that shares it; a block that existed only for that IP goes with it. Profile, address and destination blocks keep their other credentials. To remove the IPs that match a search, search first and use the button in the results.', 'fraud-shield' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'fs_blocklist_remove_ips' ); ?>
					<input type="hidden" name="action" value="fs_blocklist_remove_ips" />
					<input type="hidden" name="mode" value="list" />
					<p>
						<textarea name="ips" rows="4" class="large-text code" placeholder="<?php esc_attr_e( 'Paste IP addresses to remove — one per line, or separated by commas or spaces', 'fraud-shield' ); ?>"></textarea>
					</p>
					<p>
						<button class="button button-primary"><?php esc_html_e( 'Remove the listed IPs', 'fraud-shield' ); ?></button>
						<span class="fs-muted"><?php esc_html_e( 'You will be told how many were removed, how many were not on the list, and how many lines were not an IP address.', 'fraud-shield' ); ?></span>
					</p>
				</form>
				<?php if ( $ip_total > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'fs_blocklist_remove_ips' ); ?>
						<input type="hidden" name="action" value="fs_blocklist_remove_ips" />
						<input type="hidden" name="mode" value="all" />
						<p>
							<button class="button button-link-delete" onclick="return confirm('<?php echo esc_js( sprintf( /* translators: %s: number of IP credentials */ _n( 'Remove the only IP address on the blocklist? Every other credential stays.', 'Remove ALL %s IP addresses from the blocklist? Every other credential stays. This cannot be undone.', $ip_total, 'fraud-shield' ), number_format_i18n( $ip_total ) ) ); ?>');"><?php echo esc_html( sprintf( /* translators: %s: number of IP credentials */ _n( 'Remove the %s IP address on the list', 'Remove ALL %s IP addresses on the list', $ip_total, 'fraud-shield' ), number_format_i18n( $ip_total ) ) ); ?></button>
						</p>
					</form>
				<?php endif; ?>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Transfer between sites', 'fraud-shield' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Export this blocklist as a CSV, then import it into Fraud Shield on any other site — every entry, its custom message, AND its block grouping carry over (via portable natural keys). Importing never removes existing entries; duplicates are skipped. An old-format CSV imports under one reversible “Imported …” block.', 'fraud-shield' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fs_export_blocklist' ), 'fs_export_blocklist' ) ); ?>"><?php esc_html_e( 'Export blocklist (CSV)', 'fraud-shield' ); ?></a></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'fs_import_blocklist' ); ?>
					<input type="hidden" name="action" value="fs_import_blocklist" />
					<p>
						<input type="file" name="fs_blocklist_csv" accept=".csv,text/csv,text/plain" required />
						<button class="button button-primary"><?php esc_html_e( 'Import blocklist', 'fraud-shield' ); ?></button>
					</p>
				</form>
			</div>

			<div class="fs-panel">
				<h2>
				<?php
				if ( null !== $results ) {
					/* translators: 1: matching blocks, 2: all blocks, 3: all credentials */
					printf( esc_html__( 'Matching blocks (%1$s of %2$s) · Credentials on the list (%3$s)', 'fraud-shield' ), esc_html( number_format_i18n( $results['actions_total'] ) ), esc_html( number_format_i18n( $total_actions ) ), esc_html( number_format_i18n( $total_entries ) ) );
				} else {
					/* translators: 1: number of blocks (actions), 2: number of credentials */
					printf( esc_html__( 'Blocks (%1$s) · Credentials (%2$s)', 'fraud-shield' ), esc_html( number_format_i18n( $total_actions ) ), esc_html( number_format_i18n( $total_entries ) ) );
				}
				?>
				</h2>
				<p class="description"><?php esc_html_e( 'Each card is one block you performed and owns the credentials it created. “Remove all (unblock)” reverses the whole block in one click. Removing a single credential deletes it everywhere — including any other block that shares it.', 'fraud-shield' ); ?></p>

				<?php
				$fs_has_rows = ! empty( $actions ) || ( null !== $results && ! empty( $results['ungrouped'] ) ) || ( null === $results && $paged >= $pages && $ungrouped_total > 0 );
				if ( $fs_has_rows ) :
					// Every credential row on the page carries a checkbox bound to
					// this form (by id, so the per-row message forms never nest);
					// "Select all" here or the header box in any table, then
					// "Remove selected" — the same removal as a row's Remove button.
					?>
					<form id="fs-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fs-bulk-bar">
						<?php wp_nonce_field( 'fs_blocklist_remove_selected' ); ?>
						<input type="hidden" name="action" value="fs_blocklist_remove_selected" />
						<?php foreach ( $keep_args as $fs_k => $fs_v ) : ?>
							<input type="hidden" name="back_<?php echo esc_attr( $fs_k ); ?>" value="<?php echo esc_attr( $fs_v ); ?>" />
						<?php endforeach; ?>
						<span class="fs-bulk-count"><?php esc_html_e( 'Selected:', 'fraud-shield' ); ?> <strong class="fs-sel-count">0</strong></span>
						<button type="button" class="button" id="fs-sel-all"><?php esc_html_e( 'Select all on this page', 'fraud-shield' ); ?></button>
						<button type="button" class="button" id="fs-sel-ips"><?php esc_html_e( 'Select all IPs on this page', 'fraud-shield' ); ?></button>
						<button type="button" class="button" id="fs-sel-none"><?php esc_html_e( 'Clear selection', 'fraud-shield' ); ?></button>
						<button type="submit" class="button button-link-delete" id="fs-bulk-remove" disabled data-confirm="<?php esc_attr_e( 'Remove the %d selected credentials from the blocklist? Each is removed from every block that shares it; a block left with nothing that existed only for an IP goes with it. This cannot be undone.', 'fraud-shield' ); ?>"><?php esc_html_e( 'Remove selected', 'fraud-shield' ); ?></button>
						<span class="fs-muted"><?php esc_html_e( 'Selection covers this page. To clear every IP on the list in one go, use “Remove ALL … IP addresses” above; to narrow the page to one type first, use the Type filter.', 'fraud-shield' ); ?></span>
					</form>
				<?php endif; ?>

				<?php if ( empty( $actions ) && 1 === $paged ) : ?>
					<?php if ( null !== $results ) : ?>
						<p><?php esc_html_e( 'No block matches this search.', 'fraud-shield' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'No grouped blocks yet. Use the Block buttons on the dashboard, Profiles, Addresses or IPs — or add an entry above.', 'fraud-shield' ); ?></p>
					<?php endif; ?>
				<?php else : ?>
					<?php foreach ( $actions as $fs_action ) { $this->render_action_card( $fs_action ); } ?>
				<?php endif; ?>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav"><div class="tablenav-pages">
					<?php
					$fs_base = array( 'page' => self::SLUG . '-blocklist' );
					if ( '' !== $search ) {
						$fs_base['s'] = $search;
					}
					if ( '' !== $type ) {
						$fs_base['type'] = $type;
					}
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( $fs_base, admin_url( 'admin.php' ) ) . '%_%',
								'format'    => '&paged=%#%',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
					</div></div>
				<?php endif; ?>

				<?php
				// Legacy card is pinned to the last page of actions; in a search
				// it lists only the ungrouped credentials that match.
				if ( null !== $results ) {
					if ( $paged >= $pages && $results['ungrouped_total'] > 0 ) {
						$this->render_ungrouped_card( $results['ungrouped_total'], $results['ungrouped'], $search );
					}
				} elseif ( $paged >= $pages && $ungrouped_total > 0 ) {
					$this->render_ungrouped_card( $ungrouped_total );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Remove IP credentials in bulk: a pasted list, everything the current
	 * search matches, or every IP on the list. Nothing but IP credentials is
	 * ever touched; cards that existed only for an IP are dropped with it.
	 */
	public function handle_blocklist_remove_ips() {
		$this->guard();
		check_admin_referer( 'fs_blocklist_remove_ips' );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'list';
		$args = array( 'fs_status' => 'ips_removed' );
		if ( 'all' === $mode ) {
			$args['fs_n'] = FS_Blocklist::remove_all_ips();
		} elseif ( 'search' === $mode ) {
			// "The IPs this view matches": a search, a type filter of IP with
			// no search (every IP), or both.
			$q    = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
			$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
			$args['fs_n'] = '' === $q ? FS_Blocklist::remove_all_ips() : FS_Blocklist::remove_ips_matching( $q );
			if ( '' !== $q ) {
				$args['s'] = $q;
			}
			if ( in_array( $type, FS_Blocklist::TYPES, true ) ) {
				$args['type'] = $type;
			}
		} else {
			// Every token is validated as an IP before it is used; the rest are only counted.
			$parsed             = FS_Blocklist::parse_ip_list( isset( $_POST['ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ips'] ) ) : '' );
			$res                = FS_Blocklist::remove_ips( $parsed['valid'] );
			$args['fs_n']       = (int) $res['removed'];
			$args['fs_missing'] = (int) $res['missing'];
			$args['fs_invalid'] = count( $parsed['invalid'] );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG . '-blocklist' ) ) );
		exit;
	}

	/**
	 * Remove the credentials ticked on the Blocklist page — any type — and
	 * return to the same view (search, type filter, page).
	 */
	public function handle_blocklist_remove_selected() {
		$this->guard();
		check_admin_referer( 'fs_blocklist_remove_selected' );

		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- ints only.
		$res = FS_Blocklist::remove_entries( $ids );

		$args = array(
			'fs_status' => 'selected_removed',
			'fs_n'      => (int) $res['removed'],
			'fs_ips'    => (int) ( $res['by_type']['ip'] ?? 0 ),
		);
		$back = array( 's' => 'back_s', 'type' => 'back_type', 'paged' => 'back_paged' );
		foreach ( $back as $arg => $field ) {
			if ( ! isset( $_POST[ $field ] ) || '' === $_POST[ $field ] ) {
				continue;
			}
			$v = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			if ( 'type' === $arg && ! in_array( $v, FS_Blocklist::TYPES, true ) ) {
				continue;
			}
			$args[ $arg ] = 'paged' === $arg ? max( 1, (int) $v ) : $v;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG . '-blocklist' ) ) );
		exit;
	}

	/**
	 * Render one block-action card (header + controls + owned credentials).
	 *
	 * @param array $action Action row with an attached `entries` array.
	 */
	private function render_action_card( $action ) {
		$kinds = array(
			'profile' => array( 'dashicons-admin-users', __( 'Customer profile', 'fraud-shield' ) ),
			'address' => array( 'dashicons-location', __( 'Shipping address', 'fraud-shield' ) ),
			'ip'      => array( 'dashicons-admin-site-alt3', __( 'IP address', 'fraud-shield' ) ),
			'manual'  => array( 'dashicons-edit', __( 'Manual entry', 'fraud-shield' ) ),
			'import'  => array( 'dashicons-upload', __( 'Imported batch', 'fraud-shield' ) ),
			'place'   => array( 'dashicons-location-alt', __( 'Destination lock', 'fraud-shield' ) ),
		);
		$kind    = isset( $kinds[ $action['kind'] ] ) ? $kinds[ $action['kind'] ] : array( 'dashicons-shield', $action['kind'] );
		$entries = isset( $action['entries'] ) && is_array( $action['entries'] ) ? $action['entries'] : array();
		$count   = count( $entries );
		$title   = '' !== trim( (string) $action['title'] ) ? $action['title'] : $action['target'];

		$unblock_url = wp_nonce_url( admin_url( 'admin-post.php?action=fs_unblock_action&action_id=' . (int) $action['id'] ), 'fs_unblock_action' );
		$resync_url  = wp_nonce_url( admin_url( 'admin-post.php?action=fs_resync_action&action_id=' . (int) $action['id'] ), 'fs_resync_action' );
		$can_resync  = in_array( $action['kind'], array( 'profile', 'address', 'ip' ), true );

		$confirm = sprintf(
			/* translators: %d: number of credentials that will be removed */
			_n(
				'Unblock this? The %d credential created by this block will be removed from the blocklist.',
				'Unblock this? All %d credentials created by this block will be removed from the blocklist.',
				max( 1, $count ),
				'fraud-shield'
			),
			$count
		);
		?>
		<div class="fs-block-action">
			<div class="fs-ba-head">
				<span class="fs-ba-title"><span class="dashicons <?php echo esc_attr( $kind[0] ); ?>"></span> <?php echo esc_html( $title ); ?></span>
				<span class="fs-ba-meta">
					<span class="fs-pill"><?php echo esc_html( $kind[1] ); ?></span>
					<?php
					/* translators: %s: number of credentials */
					printf( esc_html( _n( '%s credential', '%s credentials', $count, 'fraud-shield' ) ), esc_html( number_format_i18n( $count ) ) );
					if ( ! empty( $action['created_at'] ) ) {
						echo ' · ' . esc_html( $action['created_at'] );
					}
					?>
				</span>
				<span class="fs-ba-actions">
					<?php if ( $can_resync ) : ?>
						<a class="button button-small" href="<?php echo esc_url( $resync_url ); ?>" title="<?php esc_attr_e( 'Re-scan the index and link any new matching credentials into this block.', 'fraud-shield' ); ?>"><?php esc_html_e( 'Re-sync', 'fraud-shield' ); ?></a>
					<?php endif; ?>
					<a class="button button-small button-link-delete" href="<?php echo esc_url( $unblock_url ); ?>" onclick="return confirm('<?php echo esc_js( $confirm ); ?>');"><?php esc_html_e( 'Remove all (unblock)', 'fraud-shield' ); ?></a>
				</span>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fs-ba-msg">
				<?php wp_nonce_field( 'fs_action_message' ); ?>
				<input type="hidden" name="action" value="fs_action_message" />
				<input type="hidden" name="action_id" value="<?php echo (int) $action['id']; ?>" />
				<input type="text" name="message" value="<?php echo esc_attr( (string) $action['message'] ); ?>" placeholder="<?php esc_attr_e( 'Whole-block checkout message (optional — overrides per-credential and default messages)', 'fraud-shield' ); ?>" />
				<button class="button button-small"><?php esc_html_e( 'Save block message', 'fraud-shield' ); ?></button>
			</form>

			<?php if ( isset( $action['entries_total'] ) && empty( $action['self_match'] ) && $count < (int) $action['entries_total'] ) : ?>
				<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: matching credentials shown, 2: credentials the block owns */
						__( 'Showing the %1$s of this block\'s %2$s credentials that match the search. Clear the search to see the whole block.', 'fraud-shield' ),
						number_format_i18n( $count ),
						number_format_i18n( (int) $action['entries_total'] )
					)
				);
				?>
				</p>
			<?php endif; ?>
			<?php $this->render_entries_table( $entries ); ?>
		</div>
		<?php
	}

	/**
	 * Render the legacy "Ungrouped" card (rows owned by no block action).
	 *
	 * @param int $total Total ungrouped rows (for the "showing first N" note).
	 */
	private function render_ungrouped_card( $total, $rows = null, $search = '' ) {
		$rows           = null === $rows ? FS_Blocklist::ungrouped( 500, 0 ) : (array) $rows;
		$remove_all_url = wp_nonce_url( admin_url( 'admin-post.php?action=fs_remove_ungrouped' ), 'fs_remove_ungrouped' );
		?>
		<div class="fs-block-action fs-ba-ungrouped">
			<div class="fs-ba-head">
				<span class="fs-ba-title"><span class="dashicons dashicons-archive"></span> <?php esc_html_e( 'Ungrouped (pre-1.6 / legacy)', 'fraud-shield' ); ?></span>
				<span class="fs-ba-meta">
					<?php
					if ( '' !== $search ) {
						/* translators: %s: number of credentials */
						printf( esc_html( _n( '%s matching credential', '%s matching credentials', (int) $total, 'fraud-shield' ) ), esc_html( number_format_i18n( (int) $total ) ) );
					} else {
						/* translators: %s: number of credentials */
						printf( esc_html( _n( '%s credential', '%s credentials', (int) $total, 'fraud-shield' ) ), esc_html( number_format_i18n( (int) $total ) ) );
					}
					?>
				</span>
				<span class="fs-ba-actions">
					<?php if ( '' === $search ) : ?>
						<a class="button button-small button-link-delete" href="<?php echo esc_url( $remove_all_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove ALL ungrouped legacy entries? This cannot be undone.', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Remove all ungrouped', 'fraud-shield' ); ?></a>
					<?php endif; ?>
				</span>
			</div>
			<p class="description"><?php esc_html_e( 'Legacy credentials not owned by any block (blocked before v1.6, or imported without groups). Re-blocking the matching address, IP, or profile converts these into a removable group.', 'fraud-shield' ); ?></p>
			<?php $this->render_entries_table( $rows ); ?>
			<?php if ( (int) $total > 500 ) : ?>
				<p class="description"><?php esc_html_e( 'Showing the first 500. Remove some, or use “Remove all ungrouped”.', 'fraud-shield' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the shared per-credential table used by both action cards and the
	 * ungrouped card (type, value, reason, per-entry message editor, remove).
	 *
	 * @param array $entries Blocklist rows.
	 */
	private function render_entries_table( $entries ) {
		if ( empty( $entries ) ) {
			echo '<p class="description">' . esc_html__( 'No credentials remain in this block.', 'fraud-shield' ) . '</p>';
			return;
		}
		echo '<table class="wp-list-table widefat striped fs-blocklist-table"><thead><tr>';
		echo '<th style="width:28px;"><input type="checkbox" class="fs-check-all" title="' . esc_attr__( 'Select every credential in this block', 'fraud-shield' ) . '" /></th>';
		echo '<th>' . esc_html__( 'Type', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Value', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Reason', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Checkout message', 'fraud-shield' ) . '</th><th>' . esc_html__( 'Added', 'fraud-shield' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( (array) $entries as $r ) {
			$remove_url = wp_nonce_url( admin_url( 'admin-post.php?action=fs_blocklist_remove&id=' . (int) $r['id'] ), 'fs_blocklist_remove' );
			/* translators: %s: first characters of an address hash */
			$val = ( 'address' === $r['type'] ) ? sprintf( __( '%s… (hash)', 'fraud-shield' ), substr( (string) $r['value'], 0, 16 ) ) : $r['value'];

			echo '<tr>';
			// Bound to the page's bulk form by id (the row's own message form
			// below is a different form, and forms cannot nest).
			echo '<td><input type="checkbox" class="fs-sel" name="ids[]" value="' . (int) $r['id'] . '" form="fs-bulk-form" data-type="' . esc_attr( (string) $r['type'] ) . '" aria-label="' . esc_attr( sprintf( /* translators: 1: credential type, 2: value */ __( 'Select %1$s %2$s', 'fraud-shield' ), (string) $r['type'], $val ) ) . '" /></td>';
			echo '<td><span class="fs-pill">' . esc_html( $r['type'] ) . '</span></td>';
			echo '<td>' . esc_html( $val ) . '</td>';
			echo '<td>' . esc_html( (string) $r['reason'] ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fs-msg-form">';
			echo wp_nonce_field( 'fs_blocklist_message', '_wpnonce', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<input type="hidden" name="action" value="fs_blocklist_message" />';
			echo '<input type="hidden" name="id" value="' . (int) $r['id'] . '" />';
			echo '<input type="text" name="message" value="' . esc_attr( isset( $r['message'] ) ? $r['message'] : '' ) . '" placeholder="' . esc_attr__( '(default message)', 'fraud-shield' ) . '" />';
			echo '<button class="button button-small">' . esc_html__( 'Save', 'fraud-shield' ) . '</button>';
			echo '</form></td>';
			echo '<td>' . esc_html( (string) $r['created_at'] ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $remove_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Remove this credential? It will be removed from every block that shares it.', 'fraud-shield' ) ) . '\');">' . esc_html__( 'Remove', 'fraud-shield' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/* ===================== Checkout Locks ===================== */

	/**
	 * Checkout Locks: everyone currently blocked from checkout pending manual
	 * verification, from both lock sources, with one-click release. Two sections:
	 * the new-account Verification queue (CS call list) and the Velocity locks.
	 */
	public function page_checkout_locks() {
		$this->guard();
		$settings = FS_Core::instance()->settings();

		// --- Verification queue (new-account high-value holds) ---
		$held_users = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_fs_hold',
						'compare' => 'EXISTS',
					),
				),
				'number'     => 500,
				'fields'     => array( 'ID', 'user_email', 'user_registered', 'display_name' ),
			)
		);

		// --- Blocked at checkout ---
		// Source of truth = the durable ledger of real block events, reconciled
		// against the LIVE predicate on every view so it can't drift:
		//   * a velocity row whose customer is no longer velocity-locked is
		//     auto-resolved (self-heals stale rows), and
		//   * a customer who IS currently velocity-locked but has no open ledger
		//     row (e.g. a row wrongly resolved by a prior bug, or locked before
		//     the ledger existed) is re-logged so they reappear.
		// This guarantees the page shows everyone currently blocked from checkout.
		// In velocity-RESTRICT mode over-threshold customers are not blocked (they
		// get restricted gateways instead), so velocity rows are excluded from the
		// Blocked list and no velocity re-surfacing happens; those customers show
		// on the Restricted checkout list below instead.
		$velocity_blocking = ! empty( $settings['velocity_lock'] ) && 'restrict' !== FS_Live::velocity_action( $settings );

		$blocked  = FS_Data::checkout_blocks_list( 1000, 0 );
		$seen_uid = array();
		$seen_eml = array();
		$kept     = array();
		foreach ( $blocked as $row ) {
			if ( 'velocity' === $row['kind'] ) {
				if ( ! $velocity_blocking ) {
					continue; // Not blocked in restrict mode — listed under Restricted checkout.
				}
				if ( ! FS_Live::is_velocity_locked( (int) $row['user_id'], (string) $row['email'] ) ) {
					FS_Data::resolve_checkout_block( (int) $row['id'] ); // No longer locked → self-heal.
					continue;
				}
			} elseif ( 'idv' === $row['kind'] && ! FS_IDV::requires_verification( (int) $row['user_id'], (string) $row['email'] ) ) {
				// The customer verified (or staff withdrew the request), so the
				// row is history — self-heal it the same way velocity rows do.
				// The email matters: a row logged from a signed-out attempt has
				// no user id, and dropping it would hide a live block.
				FS_Data::resolve_checkout_block( (int) $row['id'] );
				continue;
			}
			$kept[] = $row;
			if ( (int) $row['user_id'] > 0 ) {
				$seen_uid[ (int) $row['user_id'] ] = true;
			}
			if ( '' !== (string) $row['email'] ) {
				$seen_eml[ strtolower( (string) $row['email'] ) ] = true;
			}
		}

		// Re-surface currently velocity-locked customers missing an open row.
		if ( $velocity_blocking ) {
			foreach ( FS_Data::velocity_candidates( (int) $settings['velocity_lock_threshold'], 1000 ) as $c ) {
				$cuid = (int) $c['user_id'];
				$cem  = FS_Normalizer::email( $c['user_email'] );
				if ( ( $cuid > 0 && isset( $seen_uid[ $cuid ] ) ) || ( '' !== $cem && isset( $seen_eml[ strtolower( $cem ) ] ) ) ) {
					continue;
				}
				if ( FS_Live::is_velocity_locked( $cuid, $cem ) ) {
					FS_Data::log_checkout_block( 'velocity', $cuid, $cem, (string) $c['customer_name'], __( 'Detected on Checkout Locks review', 'fraud-shield' ) );
				}
			}
			// Re-read once so re-logged customers appear with real ledger ids.
			$kept = array();
			foreach ( FS_Data::checkout_blocks_list( 1000, 0 ) as $row ) {
				if ( 'velocity' === $row['kind'] && ! FS_Live::is_velocity_locked( (int) $row['user_id'], (string) $row['email'] ) ) {
					continue;
				}
				if ( 'idv' === $row['kind'] && ! FS_IDV::requires_verification( (int) $row['user_id'], (string) $row['email'] ) ) {
					continue;
				}
				$kept[] = $row;
			}
		}
		$blocked = $kept;

		// --- Restricted checkout list: per-account gateway restrictions (durable,
		// bulk-removable) plus, in velocity-restrict mode, the customers currently
		// pushed onto the restricted gateway set by the velocity rule (derived).
		//
		// Sweep first so the list shows who is actually restricted right now:
		// restrictions that have served their term, or that an ID check released,
		// are deleted here (enforcement already ignored them).
		FS_Live::sweep_gw_restrictions( 500 );

		$restricted_users = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_fs_gw_restrict',
						'compare' => 'EXISTS',
					),
				),
				'number'     => 500,
				'fields'     => array( 'ID', 'user_email', 'display_name' ),
			)
		);
		$velocity_restricted = array();
		if ( ! empty( $settings['velocity_lock'] ) && 'restrict' === FS_Live::velocity_action( $settings ) ) {
			$restricted_ids = array();
			foreach ( $restricted_users as $ru ) {
				$restricted_ids[ (int) $ru->ID ] = true;
			}
			foreach ( FS_Data::velocity_candidates( (int) $settings['velocity_lock_threshold'], 500 ) as $c ) {
				$cuid = (int) $c['user_id'];
				if ( $cuid > 0 && isset( $restricted_ids[ $cuid ] ) ) {
					continue; // Already listed via their per-account restriction.
				}
				if ( FS_Live::is_velocity_locked( $cuid, FS_Normalizer::email( $c['user_email'] ) ) ) {
					$velocity_restricted[] = $c;
				}
			}
		}

		$allowlist   = FS_Live::allowlist();
		$total_locks = count( $held_users ) + count( $blocked );
		?>
		<div class="wrap fs-wrap">
			<h1><?php esc_html_e( 'Checkout Locks', 'fraud-shield' ); ?></h1>
			<?php $this->maybe_notice(); ?>
			<p class="description">
				<?php
				/* translators: %s: total number of locked customers */
				printf( esc_html( _n( '%s customer is currently blocked from checkout.', '%s customers are currently blocked from checkout.', $total_locks, 'fraud-shield' ) ), '<strong>' . esc_html( number_format_i18n( $total_locks ) ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</p>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Verification queue — new-account high-value holds', 'fraud-shield' ); ?>
					<span class="fs-pill"><?php echo esc_html( number_format_i18n( count( $held_users ) ) ); ?></span>
				</h2>
				<p class="description"><?php esc_html_e( 'Recently-registered accounts that placed a high-value order and are blocked from ordering again until verified. Call the customer, then click "Verify & unlock" to release them (and their held order).', 'fraud-shield' ); ?></p>
				<?php if ( empty( $settings['hold_enabled'] ) ) : ?>
					<p class="description" style="color:#b26900;"><?php esc_html_e( 'Note: the new-account hold rule is currently OFF in Settings, so these holds are not being enforced. Enable it, or release the accounts below.', 'fraud-shield' ); ?></p>
				<?php endif; ?>
				<?php if ( empty( $held_users ) ) : ?>
					<p><?php esc_html_e( 'No accounts are awaiting verification.', 'fraud-shield' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat striped">
						<thead><tr>
							<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Email', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Phone', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Account age', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Triggering order', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Held since', 'fraud-shield' ); ?></th>
							<th></th>
						</tr></thead>
						<tbody>
						<?php
						foreach ( $held_users as $u ) :
							$uid   = (int) $u->ID;
							$hold  = get_user_meta( $uid, '_fs_hold', true );
							$oid   = ( is_array( $hold ) && ! empty( $hold['order_id'] ) ) ? (int) $hold['order_id'] : 0;
							$amt   = ( is_array( $hold ) && isset( $hold['amount'] ) ) ? (float) $hold['amount'] : 0;
							$age   = ( is_array( $hold ) && isset( $hold['age_days'] ) ) ? (int) $hold['age_days'] : 0;
							$since = ( is_array( $hold ) && ! empty( $hold['ts'] ) ) ? $hold['ts'] : '';
							$phone = get_user_meta( $uid, 'billing_phone', true );
							$profile_url = add_query_arg( array( 'page' => self::SLUG . '-new-customers', 'fs_customer' => $uid ), admin_url( 'admin.php' ) );
							$verify_url  = wp_nonce_url( admin_url( 'admin-post.php?action=fs_verify_customer&to=1&user=' . $uid ), 'fs_verify_customer' );
							?>
							<tr>
								<td><a href="<?php echo esc_url( $profile_url ); ?>"><strong><?php echo esc_html( $u->display_name ? $u->display_name : ( '#' . $uid ) ); ?></strong></a></td>
								<td><?php echo esc_html( $u->user_email ); ?></td>
								<td><?php echo esc_html( $phone ? $phone : '—' ); ?></td>
								<td><?php echo esc_html( sprintf( _n( '%s day', '%s days', $age, 'fraud-shield' ), number_format_i18n( $age ) ) ); ?></td>
								<td>
									<?php if ( $oid ) : ?>
										<a href="<?php echo esc_url( $this->order_edit_url( $oid ) ); ?>">#<?php echo (int) $oid; ?></a>
										<span class="fs-muted"><?php echo wp_kses_post( wc_price( $amt ) ); ?></span>
									<?php else : ?>—<?php endif; ?>
								</td>
								<td><?php echo esc_html( $since ? mysql2date( 'Y-m-d H:i', $since ) : '—' ); ?></td>
								<td><a class="button button-small button-primary" href="<?php echo esc_url( $verify_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Verify this customer and unlock checkout (and release their held order)?', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Verify & unlock', 'fraud-shield' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Blocked at checkout', 'fraud-shield' ); ?>
					<span class="fs-pill"><?php echo esc_html( number_format_i18n( count( $blocked ) ) ); ?></span>
				</h2>
				<p class="description"><?php esc_html_e( 'Customers actually stopped at checkout by the velocity rule, the blocklist, or the linked-to-blocklist rule — or whose order the velocity rule placed On-Hold for review — recorded as it happens. For blocklist and linked refusals the row shows exactly which credentials matched, when and by whom they were blocked, and the blocked person they were matched to. Registered accounts unlock via "Verify"; guest emails via "Allow" — either also releases their paid held orders to processing. "Dismiss" just clears the row (the detail stays on the customer\'s profile). Blocklist hits are managed on the Blocklist page — dismissing here only removes the reminder.', 'fraud-shield' ); ?></p>
				<?php if ( empty( $blocked ) ) : ?>
					<p><?php esc_html_e( 'Nobody has been blocked at checkout recently.', 'fraud-shield' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'fs_bulk_gw_restrict' ); ?>
					<input type="hidden" name="action" value="fs_bulk_gw_restrict" />
					<table class="wp-list-table widefat striped">
						<thead><tr>
							<th style="width:28px;"><input type="checkbox" class="fs-check-all" title="<?php esc_attr_e( 'Select all', 'fraud-shield' ); ?>" /></th>
							<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Email', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Blocked by', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Attempts', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Last attempt', 'fraud-shield' ); ?></th>
							<th></th>
						</tr></thead>
						<tbody>
						<?php
						$fs_kind_labels = array(
							'velocity'  => __( 'Velocity lock', 'fraud-shield' ),
							'blocklist' => __( 'Blocklist', 'fraud-shield' ),
							'idv'       => __( 'ID requested', 'fraud-shield' ),
							'linked'    => __( 'Linked to the blocklist', 'fraud-shield' ),
						);
						foreach ( $blocked as $b ) :
							$uid      = (int) $b['user_id'];
							$email    = (string) $b['email'];
							$kind     = (string) $b['kind'];
							$dismiss  = wp_nonce_url( admin_url( 'admin-post.php?action=fs_resolve_block&id=' . (int) $b['id'] ), 'fs_resolve_block' );
							$profile_url = $uid > 0 ? add_query_arg( array( 'page' => self::SLUG . '-new-customers', 'fs_customer' => $uid ), admin_url( 'admin.php' ) ) : '';
							?>
							<tr>
								<td>
									<?php if ( $uid > 0 ) : ?>
										<input type="checkbox" name="uids[]" value="<?php echo (int) $uid; ?>" />
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $profile_url ) : ?>
										<a href="<?php echo esc_url( $profile_url ); ?>"><strong><?php echo esc_html( $b['name'] ? $b['name'] : ( '#' . $uid ) ); ?></strong></a>
									<?php else : ?>
										<strong><?php echo esc_html( $b['name'] ? $b['name'] : __( '(guest)', 'fraud-shield' ) ); ?></strong>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $email ? $email : '—' ); ?></td>
								<td class="fs-blocked-by">
									<?php
									echo '<strong>' . esc_html( isset( $fs_kind_labels[ $kind ] ) ? $fs_kind_labels[ $kind ] : $kind ) . '</strong>';
									if ( ! empty( $b['detail'] ) ) {
										echo ' <span class="fs-muted">(' . esc_html( $b['detail'] ) . ')</span>';
									}
									// What matched, and whom they were matched to (v1.29).
									echo $this->render_block_evidence( (array) ( $b['evidence'] ?? array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
									?>
								</td>
								<td><strong><?php echo (int) $b['attempts']; ?></strong></td>
								<td><?php echo esc_html( $b['last_at'] ? mysql2date( 'Y-m-d H:i', $b['last_at'] ) : '—' ); ?></td>
								<td>
									<?php if ( 'idv' === $kind ) : ?>
										<?php
										// A row logged from a signed-out attempt carries no user id, so
										// resolve the account the demand belongs to from the email.
										$fs_idv_uid = $uid > 0 ? $uid : FS_IDV::requirement_owner( $email );
										?>
										<?php if ( $fs_idv_uid > 0 ) : ?>
											<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fs_idv_require&to=0&user=' . $fs_idv_uid ), 'fs_idv_require' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Cancel the ID request and unblock checkout for this customer?', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Cancel ID request', 'fraud-shield' ); ?></a>
										<?php endif; ?>
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-idv' ) ); ?>"><?php esc_html_e( 'ID Verifications', 'fraud-shield' ); ?></a>
									<?php elseif ( 'velocity' === $kind || 'linked' === $kind ) : ?>
										<?php if ( $uid > 0 ) : ?>
											<a class="button button-small button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fs_verify_customer&to=1&user=' . $uid ), 'fs_verify_customer' ) ); ?>"><?php esc_html_e( 'Verify & unlock', 'fraud-shield' ); ?></a>
										<?php elseif ( $email ) : ?>
											<a class="button button-small button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fs_allow_email&email=' . rawurlencode( $email ) ), 'fs_allow_email' ) ); ?>"><?php esc_html_e( 'Allow (unlock)', 'fraud-shield' ); ?></a>
										<?php endif; ?>
										<?php if ( 'linked' === $kind ) : ?>
											<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG . '-cancels', 's' => $email ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Auto-Cancelled', 'fraud-shield' ); ?></a>
										<?php endif; ?>
									<?php else : ?>
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-blocklist' ) ); ?>"><?php esc_html_e( 'Manage on Blocklist', 'fraud-shield' ); ?></a>
									<?php endif; ?>
									<a class="button button-small" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'fraud-shield' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<?php $fs_bulk_choices = $this->gateway_choices(); ?>
					<?php if ( ! empty( $fs_bulk_choices ) ) : ?>
						<div class="fs-bulk-restrict">
							<h3><?php esc_html_e( 'Restrict Checkout & Unblock (selected)', 'fraud-shield' ); ?></h3>
							<p class="description"><?php esc_html_e( 'Unblocks every checked registered account and lets them check out ONLY with the gateways ticked below (optional under-$X allowance). They move to the Restricted checkout list. Guest rows have no account to restrict — release those via "Allow" instead.', 'fraud-shield' ); ?></p>
							<p>
							<?php foreach ( $fs_bulk_choices as $fs_gw_id => $fs_gw_meta ) : ?>
								<label style="margin-right:14px;"><input type="checkbox" name="gateways[]" value="<?php echo esc_attr( $fs_gw_id ); ?>" /> <?php echo esc_html( $fs_gw_meta['title'] ); ?><?php echo '' === $fs_gw_meta['note'] ? '' : ' <span class="fs-muted">(' . esc_html( $fs_gw_meta['note'] ) . ')</span>'; ?></label>
							<?php endforeach; ?>
							</p>
							<p>
								<label><?php esc_html_e( 'Also allow under', 'fraud-shield' ); ?> $<input type="number" name="low_amount" min="0" step="0.01" value="0" style="width:100px;" /></label>
								<?php foreach ( $fs_bulk_choices as $fs_gw_id => $fs_gw_meta ) : ?>
									<label style="margin-left:10px;"><input type="checkbox" name="low_gateways[]" value="<?php echo esc_attr( $fs_gw_id ); ?>" /> <?php echo esc_html( $fs_gw_meta['title'] ); ?></label>
								<?php endforeach; ?>
							</p>
							<p><button class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Unblock the selected customers and restrict them to the ticked gateways?', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Restrict Checkout & Unblock selected', 'fraud-shield' ); ?></button></p>
						</div>
					<?php endif; ?>
					</form>
					<p class="description"><?php esc_html_e( '“Verify” / “Allow” permanently exempts that customer from the velocity lock and holds (a Verified account is also exempt from the blocklist). Use it once you have confirmed the customer is legitimate.', 'fraud-shield' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Restricted checkout', 'fraud-shield' ); ?>
					<span class="fs-pill"><?php echo esc_html( number_format_i18n( count( $restricted_users ) + count( $velocity_restricted ) ) ); ?></span>
				</h2>
				<p class="description"><?php esc_html_e( 'Customers who may check out but only on a limited set of payment gateways: per-account restrictions applied by staff (bulk-removable below), and — when the velocity rule is set to "restrict" — customers currently pushed onto the restricted gateway set by their order velocity.', 'fraud-shield' ); ?></p>
				<?php
				$fs_gw_months = (int) ( $settings['gw_restrict_expire_months'] ?? 6 );
				$fs_gw_idv    = ! empty( $settings['gw_restrict_release_on_idv'] );
				if ( $fs_gw_months > 0 || $fs_gw_idv ) {
					echo '<p class="description">';
					if ( $fs_gw_months > 0 ) {
						printf(
							/* translators: %s: number of months */
							esc_html( _n( 'A staff-applied restriction lapses on its own after %s month and the customer returns to the normal rules.', 'A staff-applied restriction lapses on its own after %s months and the customer returns to the normal rules.', $fs_gw_months, 'fraud-shield' ) ),
							esc_html( number_format_i18n( $fs_gw_months ) )
						);
					} else {
						esc_html_e( 'Staff-applied restrictions do not lapse on their own (expiry is switched off).', 'fraud-shield' );
					}
					if ( $fs_gw_idv ) {
						echo ' ' . esc_html__( 'Verifying the customer by hand, or an approved ID verification, ends it early. Both are configurable in Settings.', 'fraud-shield' );
					} else {
						echo ' ' . esc_html__( 'Verifying the customer by hand ends it early. Configurable in Settings.', 'fraud-shield' );
					}
					echo '</p>';
				}
				?>

				<?php if ( empty( $restricted_users ) && empty( $velocity_restricted ) ) : ?>
					<p><?php esc_html_e( 'Nobody is currently on restricted checkout.', 'fraud-shield' ); ?></p>
				<?php else : ?>
					<?php if ( ! empty( $restricted_users ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'fs_bulk_gw_clear' ); ?>
						<input type="hidden" name="action" value="fs_bulk_gw_clear" />
						<table class="wp-list-table widefat striped">
							<thead><tr>
								<th style="width:28px;"><input type="checkbox" class="fs-check-all" title="<?php esc_attr_e( 'Select all', 'fraud-shield' ); ?>" /></th>
								<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
								<th><?php esc_html_e( 'Email', 'fraud-shield' ); ?></th>
								<th><?php esc_html_e( 'Allowed gateways', 'fraud-shield' ); ?></th>
								<th><?php esc_html_e( 'Under-$X allowance', 'fraud-shield' ); ?></th>
								<th><?php esc_html_e( 'Releases', 'fraud-shield' ); ?></th>
								<th></th>
							</tr></thead>
							<tbody>
							<?php
							foreach ( $restricted_users as $ru ) :
								$r_uid  = (int) $ru->ID;
								$r_meta = FS_Live::per_user_gateway_restriction( $r_uid );
								if ( empty( $r_meta['gateways'] ) ) {
									continue; // Swept out from under us — not restricted any more.
								}
								$r_gw   = implode( ', ', array_map( 'strval', (array) $r_meta['gateways'] ) );
								$r_amt  = isset( $r_meta['lowvalue_amount'] ) ? (float) $r_meta['lowvalue_amount'] : 0;
								$r_low  = isset( $r_meta['lowvalue_gateways'] ) ? implode( ', ', array_map( 'strval', (array) $r_meta['lowvalue_gateways'] ) ) : '';
								$r_url  = add_query_arg( array( 'page' => self::SLUG . '-new-customers', 'fs_customer' => $r_uid ), admin_url( 'admin.php' ) );
								$r_exp  = FS_Live::gw_restrict_expires_at( $r_meta );
								?>
								<tr>
									<td><input type="checkbox" name="uids[]" value="<?php echo (int) $r_uid; ?>" /></td>
									<td><a href="<?php echo esc_url( $r_url ); ?>"><strong><?php echo esc_html( $ru->display_name ? $ru->display_name : ( '#' . $r_uid ) ); ?></strong></a></td>
									<td><?php echo esc_html( $ru->user_email ); ?></td>
									<td><?php echo esc_html( $r_gw ); ?></td>
									<td><?php echo $r_amt > 0 ? esc_html( sprintf( '%s: %s', number_format_i18n( $r_amt, 2 ), $r_low ) ) : '—'; ?></td>
									<td>
										<?php
										if ( $r_exp > 0 ) {
											$r_days = (int) ceil( ( $r_exp - time() ) / DAY_IN_SECONDS );
											printf(
												/* translators: 1: date the restriction lapses, 2: number of days remaining */
												esc_html__( '%1$s (%2$s)', 'fraud-shield' ),
												esc_html( date_i18n( 'Y-m-d', $r_exp ) ),
												esc_html( sprintf( /* translators: %s: number of days */ _n( '%s day', '%s days', $r_days, 'fraud-shield' ), number_format_i18n( $r_days ) ) )
											);
										} else {
											echo '<span class="fs-muted">' . esc_html__( 'no expiry', 'fraud-shield' ) . '</span>';
										}
										?>
									</td>
									<td><a class="button button-small" href="<?php echo esc_url( $r_url ); ?>"><?php esc_html_e( 'Edit', 'fraud-shield' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p><button class="button" onclick="return confirm('<?php echo esc_js( __( 'Remove the selected customers from restricted checkout? They return to the normal checkout rules.', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Remove selected from restricted checkout', 'fraud-shield' ); ?></button></p>
						</form>
					<?php endif; ?>

					<?php if ( ! empty( $velocity_restricted ) ) : ?>
						<h3><?php esc_html_e( 'Restricted by the velocity rule (automatic)', 'fraud-shield' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Currently over the velocity threshold with the rule set to "restrict" — they release automatically as the 30-day window rolls, or Verify them to release now.', 'fraud-shield' ); ?></p>
						<table class="wp-list-table widefat striped">
							<thead><tr>
								<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
								<th><?php esc_html_e( 'Email', 'fraud-shield' ); ?></th>
								<th><?php esc_html_e( 'Orders (30d)', 'fraud-shield' ); ?></th>
								<th></th>
							</tr></thead>
							<tbody>
							<?php
							foreach ( $velocity_restricted as $vr ) :
								$vr_uid = (int) $vr['user_id'];
								$vr_url = $vr_uid > 0 ? add_query_arg( array( 'page' => self::SLUG . '-new-customers', 'fs_customer' => $vr_uid ), admin_url( 'admin.php' ) ) : '';
								?>
								<tr>
									<td>
										<?php if ( $vr_url ) : ?>
											<a href="<?php echo esc_url( $vr_url ); ?>"><strong><?php echo esc_html( $vr['customer_name'] ? $vr['customer_name'] : ( '#' . $vr_uid ) ); ?></strong></a>
										<?php else : ?>
											<strong><?php echo esc_html( $vr['customer_name'] ? $vr['customer_name'] : __( '(guest)', 'fraud-shield' ) ); ?></strong>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $vr['user_email'] ); ?></td>
									<td><strong><?php echo (int) $vr['recent']; ?></strong></td>
									<td>
										<?php if ( $vr_uid > 0 ) : ?>
											<a class="button button-small button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fs_verify_customer&to=1&user=' . $vr_uid ), 'fs_verify_customer' ) ); ?>"><?php esc_html_e( 'Verify & release', 'fraud-shield' ); ?></a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $allowlist ) ) : ?>
				<div class="fs-panel">
					<h2><?php esc_html_e( 'Allowlisted emails', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'These emails are exempt from the velocity lock and the new-account hold (never exempt from the blocklist).', 'fraud-shield' ); ?></p>
					<table class="wp-list-table widefat striped">
						<thead><tr><th><?php esc_html_e( 'Email', 'fraud-shield' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $allowlist as $email ) : ?>
							<?php $rm = wp_nonce_url( admin_url( 'admin-post.php?action=fs_allow_email&remove=1&email=' . rawurlencode( $email ) ), 'fs_allow_email' ); ?>
							<tr>
								<td><?php echo esc_html( $email ); ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( $rm ); ?>"><?php esc_html_e( 'Remove from allowlist', 'fraud-shield' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ===================== Test Checkout ===================== */

	/**
	 * Test Checkout: enter any credentials and see exactly what that customer
	 * would experience at checkout — blocked (and with which message), locked
	 * for verification, held, or allowed — plus the full scoring breakdown.
	 * Read-only: nothing is saved.
	 */
	public function page_test() {
		$this->guard();
		$settings = FS_Core::instance()->settings();

		$fields = array(
			'email'     => '',
			'phone'     => '',
			'first'     => '',
			'last'      => '',
			'address_1' => '',
			'address_2' => '',
			'city'      => '',
			'state'     => '',
			'postcode'  => '',
			'country'   => '',
			'ip'        => '',
			'user_id'   => '',
		);
		$result = null;

		if ( isset( $_POST['fs_test'] ) && check_admin_referer( 'fs_test_checkout' ) ) {
			foreach ( $fields as $k => $unused ) {
				$fields[ $k ] = isset( $_POST[ 'fs_' . $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'fs_' . $k ] ) ) : '';
			}

			$addr = array(
				'address_1' => $fields['address_1'],
				'address_2' => $fields['address_2'],
				'city'      => $fields['city'],
				'state'     => $fields['state'],
				'postcode'  => $fields['postcode'],
				'country'   => $fields['country'],
			);

			$signals = array(
				'order_id'         => 0,
				'order_date'       => current_time( 'mysql' ),
				'user_id'          => (int) $fields['user_id'],
				'customer_name'    => trim( $fields['first'] . ' ' . $fields['last'] ),
				'email_norm'       => FS_Normalizer::email( $fields['email'] ),
				'email_domain'     => FS_Normalizer::email_domain( $fields['email'] ),
				'phone_norm'       => FS_Normalizer::phone( $fields['phone'] ),
				'ship_hash'        => FS_Normalizer::hash( FS_Normalizer::address( $addr ) ),
				'ship_loose_hash'  => FS_Normalizer::hash( FS_Normalizer::address_loose( $addr ) ),
				// The address as typed, so a match is described in words, and
				// the door key, so the preview matches the way checkout does.
				'ship_text'        => trim( preg_replace( '/\s+/', ' ', $addr['address_1'] . ' ' . $addr['address_2'] . ', ' . $addr['city'] . ' ' . $addr['state'] . ' ' . $addr['postcode'] . ' ' . $addr['country'] ), " ," ),
				'ship_door_hash'   => FS_Normalizer::door( $addr ),
				'ship_door_alts'   => FS_Normalizer::door_alternates( $addr ),
				'ship_number'      => FS_Normalizer::house( $addr ),
				'ship_parts'       => $addr,
				'ship_city'        => FS_Normalizer::city( $addr['city'] ),
				'ship_state'       => FS_Normalizer::region( $addr['state'] ),
				'ship_postcode'    => FS_Normalizer::postcode( $addr['postcode'] ),
				'ship_street'      => FS_Normalizer::street( $addr ),
				'billing_country'  => $fields['country'],
				'shipping_country' => $fields['country'],
				'ip'               => $fields['ip'],
			);

			$matches  = FS_Blocklist::match( $signals );
			$verified = FS_Live::is_verified_customer( (int) $fields['user_id'] );
			$velocity = FS_Live::is_velocity_locked( (int) $fields['user_id'], $signals['email_norm'], 0, $signals['phone_norm'], $signals['ship_door_hash'] );
			$profile  = FS_Data::build_profile( $signals, 0 );
			$scored   = FS_Rules::score_profile( $profile, $settings );

			// Linked-identity rule (v1.26): what it WOULD do to this order.
			// Evaluated whenever it is on and the customer is not a direct
			// blocklist hit, exactly as the post-creation handler does.
			$links = array();
			if ( ! empty( $settings['linked_cancel_enabled'] ) && empty( $matches ) && ! FS_Live::is_signal_verified( $signals ) ) {
				$links = FS_Blocklist::linked( $signals, (array) ( $settings['linked_cancel_types'] ?? array() ) );
			}
			$linked_sufficient = FS_Blocklist::linked_sufficient( $links );

			// Mirror the real checkout decision order: blocklist (ANY mode, unless
			// merchant-verified) → linked-identity cancel → velocity lock →
			// heuristic score (Enforce only).
			if ( ! empty( $matches ) && ! $verified ) {
				$outcome = 'blocked';
				$msg     = FS_Live::pick_block_message( $matches, $settings );
			} elseif ( $linked_sufficient ) {
				$outcome = 'cancelled';
				$msg     = '' !== trim( wp_strip_all_tags( (string) ( $settings['linked_cancel_message'] ?? '' ) ) ) ? $settings['linked_cancel_message'] : $settings['block_message'];
			} elseif ( $velocity && 'hold' === FS_Live::velocity_action( $settings ) ) {
				$outcome = 'velocity_held';
				$msg     = '';
			} elseif ( $velocity && 'block' === FS_Live::velocity_action( $settings ) ) {
				$outcome = 'locked';
				$msg     = $settings['velocity_lock_message'];
			} elseif ( 'enforce' === $settings['mode'] && ( 'block' === $scored['level'] || ( 'high' === $scored['level'] && 'block' === $settings['action_high'] ) ) ) {
				$outcome = 'blocked';
				$msg     = $settings['block_message'];
			} elseif ( 'enforce' === $settings['mode'] && 'high' === $scored['level'] && 'hold' === $settings['action_high'] ) {
				$outcome = 'held';
				$msg     = '';
			} else {
				$outcome = 'allowed';
				$msg     = '';
			}

			$result = compact( 'matches', 'verified', 'velocity', 'scored', 'outcome', 'msg', 'links', 'linked_sufficient' );
		}
		?>
		<div class="wrap fs-wrap">
			<h1><?php esc_html_e( 'Test Checkout', 'fraud-shield' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Enter a test address, email, phone, IP, or user ID and see the checkout exactly as that customer would: whether they are blocked, which message they see, and why. Nothing is saved.', 'fraud-shield' ); ?></p>

			<?php if ( null !== $result ) : ?>
				<div class="fs-panel">
					<h2><?php esc_html_e( 'Result', 'fraud-shield' ); ?></h2>

					<?php if ( 'blocked' === $result['outcome'] || 'locked' === $result['outcome'] ) : ?>
						<p><strong><?php echo 'locked' === $result['outcome'] ? esc_html__( 'CHECKOUT LOCKED (velocity rule) — the customer sees:', 'fraud-shield' ) : esc_html__( 'CHECKOUT BLOCKED (in every mode) — the customer sees:', 'fraud-shield' ); ?></strong></p>
						<div class="fs-checkout-preview"><?php echo wp_kses_post( $result['msg'] ); ?></div>
					<?php elseif ( 'cancelled' === $result['outcome'] ) : ?>
						<p><strong><?php esc_html_e( 'ORDER AUTO-CANCELLED (linked to the blocklist, in every mode) — the order is created, cancelled before payment, and the customer sees:', 'fraud-shield' ); ?></strong></p>
						<div class="fs-checkout-preview"><?php echo wp_kses_post( $result['msg'] ); ?></div>
					<?php elseif ( 'held' === $result['outcome'] ) : ?>
						<p><strong><?php esc_html_e( 'Checkout would complete, but the order would be placed On-Hold for manual review (high risk).', 'fraud-shield' ); ?></strong></p>
					<?php elseif ( 'velocity_held' === $result['outcome'] ) : ?>
						<p><strong><?php echo esc_html( sprintf( /* translators: %s: the velocity window ("30 days") */ __( 'Checkout would complete, but the order would be placed On-Hold for manual review — the customer is over the velocity threshold for the last %s.', 'fraud-shield' ), FS_Live::velocity_window_label( $settings ) ) ); ?></strong></p>
					<?php else : ?>
						<p><strong><?php esc_html_e( 'Checkout ALLOWED — no block, no lock.', 'fraud-shield' ); ?></strong></p>
					<?php endif; ?>

					<h4><?php esc_html_e( 'Why', 'fraud-shield' ); ?></h4>
					<ul class="fs-kv">
						<li><span><?php esc_html_e( 'Blocklist', 'fraud-shield' ); ?></span>
							<?php
							if ( empty( $result['matches'] ) ) {
								esc_html_e( 'No matches', 'fraud-shield' );
							} else {
								// The same evidence a refusal records: each matched
								// credential, its provenance, and whom it is matched to.
								echo esc_html( sprintf( /* translators: %d: number of matched credentials */ _n( '%d credential matches', '%d credentials match', count( $result['matches'] ), 'fraud-shield' ), count( $result['matches'] ) ) );
								echo $this->render_block_evidence( FS_Blocklist::match_evidence( $result['matches'], $signals ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
							}
							?>
						</li>
						<?php if ( ! empty( $settings['linked_cancel_enabled'] ) ) : ?>
							<li><span><?php esc_html_e( 'Linked to the blocklist', 'fraud-shield' ); ?></span>
								<?php
								if ( ! empty( $result['links'] ) && ! empty( $result['linked_sufficient'] ) ) {
									echo esc_html( FS_Blocklist::linked_summary( $result['links'], 5 ) );
								} elseif ( ! empty( $result['links'] ) ) {
									echo '<span class="fs-muted">' . esc_html( sprintf( /* translators: %s: the IP-only link summary */ __( 'IP only — not enough to cancel on its own: %s', 'fraud-shield' ), FS_Blocklist::linked_summary( $result['links'], 3 ) ) ) . '</span>';
								} elseif ( ! empty( $result['matches'] ) ) {
									esc_html_e( 'Not evaluated — a direct blocklist match comes first', 'fraud-shield' );
								} else {
									esc_html_e( 'No shared credentials with a blocked identity', 'fraud-shield' );
								}
								?>
							</li>
						<?php endif; ?>
						<li><span><?php esc_html_e( 'Velocity lock', 'fraud-shield' ); ?></span> <?php echo $result['velocity'] ? esc_html__( 'Locked (too many recent orders, not verified)', 'fraud-shield' ) : esc_html__( 'Not locked', 'fraud-shield' ); ?></li>
						<?php if ( ! empty( $result['verified'] ) ) : ?>
							<li><span><?php esc_html_e( 'Verified', 'fraud-shield' ); ?></span> <?php esc_html_e( 'Yes — this customer is merchant-verified and bypasses the blocklist and velocity lock', 'fraud-shield' ); ?></li>
						<?php endif; ?>
						<li><span><?php esc_html_e( 'Risk score', 'fraud-shield' ); ?></span> <?php echo $this->level_pill( $result['scored']['level'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo (int) $result['scored']['score']; ?></li>
						<li><span><?php esc_html_e( 'Mode', 'fraud-shield' ); ?></span> <?php echo esc_html( $settings['mode'] ); ?></li>
					</ul>
					<?php if ( ! empty( $result['scored']['reasons'] ) ) : ?>
						<ul style="margin:.4em 0 0 1.2em;list-style:disc;">
							<?php foreach ( $result['scored']['reasons'] as $reason ) : ?>
								<li><?php echo esc_html( $reason ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Test credentials', 'fraud-shield' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'fs_test_checkout' ); ?>
					<input type="hidden" name="fs_test" value="1" />
					<table class="form-table" role="presentation">
						<tr><th scope="row"><?php esc_html_e( 'Email', 'fraud-shield' ); ?></th><td><input type="text" name="fs_email" class="regular-text" value="<?php echo esc_attr( $fields['email'] ); ?>" /></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Phone', 'fraud-shield' ); ?></th><td><input type="text" name="fs_phone" class="regular-text" value="<?php echo esc_attr( $fields['phone'] ); ?>" /></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Name', 'fraud-shield' ); ?></th><td>
							<input type="text" name="fs_first" placeholder="<?php esc_attr_e( 'First', 'fraud-shield' ); ?>" value="<?php echo esc_attr( $fields['first'] ); ?>" />
							<input type="text" name="fs_last" placeholder="<?php esc_attr_e( 'Last', 'fraud-shield' ); ?>" value="<?php echo esc_attr( $fields['last'] ); ?>" />
						</td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Shipping address', 'fraud-shield' ); ?></th><td>
							<input type="text" name="fs_address_1" class="regular-text" placeholder="<?php esc_attr_e( 'Address line 1', 'fraud-shield' ); ?>" value="<?php echo esc_attr( $fields['address_1'] ); ?>" />
							<input type="text" name="fs_address_2" placeholder="<?php esc_attr_e( 'Line 2 / unit', 'fraud-shield' ); ?>" value="<?php echo esc_attr( $fields['address_2'] ); ?>" /><br/>
							<input type="text" name="fs_city" placeholder="<?php esc_attr_e( 'City', 'fraud-shield' ); ?>" value="<?php echo esc_attr( $fields['city'] ); ?>" style="margin-top:6px;" />
							<input type="text" name="fs_state" placeholder="<?php esc_attr_e( 'State', 'fraud-shield' ); ?>" class="small-text" value="<?php echo esc_attr( $fields['state'] ); ?>" style="margin-top:6px;" />
							<input type="text" name="fs_postcode" placeholder="<?php esc_attr_e( 'ZIP', 'fraud-shield' ); ?>" class="small-text" value="<?php echo esc_attr( $fields['postcode'] ); ?>" style="margin-top:6px;" />
							<input type="text" name="fs_country" placeholder="<?php esc_attr_e( 'Country (US)', 'fraud-shield' ); ?>" class="small-text" value="<?php echo esc_attr( $fields['country'] ); ?>" style="margin-top:6px;" />
						</td></tr>
						<tr><th scope="row"><?php esc_html_e( 'IP address', 'fraud-shield' ); ?></th><td><input type="text" name="fs_ip" class="regular-text" value="<?php echo esc_attr( $fields['ip'] ); ?>" /></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'User ID (optional)', 'fraud-shield' ); ?></th><td><input type="number" name="fs_user_id" class="small-text" value="<?php echo esc_attr( $fields['user_id'] ); ?>" /></td></tr>
					</table>
					<p><button class="button button-primary button-hero"><?php esc_html_e( 'Run test', 'fraud-shield' ); ?></button></p>
				</form>
			</div>
		</div>
		<?php
	}

	/* ===================== Settings ===================== */

	/**
	 * Settings page.
	 */
	/**
	 * A standard "what happens" block for the Settings page: one line for the
	 * enabled state, one for the disabled state, so nobody has to guess what a
	 * checkbox will do to their store.
	 *
	 * @param string $on  What happens while the control is on.
	 * @param string $off What happens while it is off.
	 */
	private function settings_effects( $on, $off ) {
		echo '<div class="fs-effects">';
		echo '<p class="fs-effect fs-effect-on"><span class="fs-effect-tag">' . esc_html__( 'When ON', 'fraud-shield' ) . '</span> ' . esc_html( $on ) . '</p>';
		echo '<p class="fs-effect fs-effect-off"><span class="fs-effect-tag">' . esc_html__( 'When OFF', 'fraud-shield' ) . '</span> ' . esc_html( $off ) . '</p>';
		echo '</div>';
	}

	/**
	 * A section header that groups related settings panels, so the page reads
	 * as a small number of areas instead of one long wall.
	 *
	 * @param string $title Section title.
	 * @param string $sub   One-line description of the section.
	 */
	private function settings_section( $title, $sub ) {
		echo '<div class="fs-settings-section">';
		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '<p>' . esc_html( $sub ) . '</p>';
		echo '</div>';
	}

	public function page_settings() {
		$this->guard();
		$s = FS_Core::instance()->settings();
		?>
		<div class="wrap fs-wrap fs-settings">
			<h1><?php esc_html_e( 'Fraud Shield Settings', 'fraud-shield' ); ?></h1>
			<?php $this->maybe_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'fs_save_settings' ); ?>
				<input type="hidden" name="action" value="fs_save_settings" />

				<?php $this->settings_section( __( 'General', 'fraud-shield' ), __( 'How the plugin behaves overall, and what customers see at the top of checkout.', 'fraud-shield' ) ); ?>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Checkout banner', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'A short reassurance line at the top of the checkout page, shown to every customer on both the classic and the blocks checkout.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Show the banner', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="trust_banner" value="1" <?php checked( ! empty( $s['trust_banner'] ) ); ?>/> <?php esc_html_e( 'Display a "protected by Fraud Shield" banner at the top of checkout', 'fraud-shield' ); ?></label>
								<?php
								$this->settings_effects(
									__( 'Every customer sees the banner text below at the top of checkout.', 'fraud-shield' ),
									__( 'Checkout looks exactly as it did without Fraud Shield. Nothing else changes.', 'fraud-shield' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Banner text', 'fraud-shield' ); ?></th>
							<td>
								<input type="text" name="trust_banner_text" class="large-text" value="<?php echo esc_attr( $s['trust_banner_text'] ?? '' ); ?>"/>
								<p class="description"><?php esc_html_e( 'Plain text, shown next to the Fraud Shield shield mark. Leave empty to hide the banner.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Operating mode', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'The mode controls only what the risk SCORE is allowed to do. Deliberate controls you switch on further down (the blocklist, velocity lock, holds, gateway rules, ID verification) act in both modes; that is what makes them deliberate.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Operating mode', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="radio" name="mode" value="observe" <?php checked( $s['mode'], 'observe' ); ?>/>
								<?php
								printf(
									/* translators: 1: opening <strong>, 2: closing </strong> */
									esc_html__( '%1$sObserve%2$s: risk scores annotate orders but never block anything. Recommended while you tune the rules.', 'fraud-shield' ),
									'<strong>',
									'</strong>'
								);
								?>
								</label><br/>
								<label><input type="radio" name="mode" value="enforce" <?php checked( $s['mode'], 'enforce' ); ?>/>
								<?php
								printf(
									/* translators: 1: opening <strong>, 2: closing </strong> */
									esc_html__( '%1$sEnforce%2$s: orders that score at the BLOCK level are refused at checkout, and HIGH scores get the action you pick below.', 'fraud-shield' ),
									'<strong>',
									'</strong>'
								);
								?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Action at HIGH risk', 'fraud-shield' ); ?></th>
							<td>
								<select name="action_high">
									<option value="hold" <?php selected( $s['action_high'], 'hold' ); ?>><?php esc_html_e( 'Hold for review (set the order On-Hold)', 'fraud-shield' ); ?></option>
									<option value="block" <?php selected( $s['action_high'], 'block' ); ?>><?php esc_html_e( 'Hard block at checkout', 'fraud-shield' ); ?></option>
									<option value="flag" <?php selected( $s['action_high'], 'flag' ); ?>><?php esc_html_e( 'Flag only (allow the order)', 'fraud-shield' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Applies in Enforce mode only. Blocklist hits are always hard-blocked, in every mode.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Checkout block message', 'fraud-shield' ); ?></th>
							<td>
								<input type="text" name="block_message" class="large-text" value="<?php echo esc_attr( $s['block_message'] ); ?>" />
								<p class="description"><?php esc_html_e( 'What a customer sees when their order is refused because of its risk score.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Treat "Refunded" as chargeback', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="count_refunded_as_chargeback" value="1" <?php checked( ! empty( $s['count_refunded_as_chargeback'] ) ); ?>/> <?php esc_html_e( 'Count refunded orders as fraud losses in the scoring rules', 'fraud-shield' ); ?></label>
								<p class="description"><?php esc_html_e( 'Off by default, because refunds are not always fraud. Turn on only if refunds in your store usually mean a dispute.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Auto-extend blocks', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="auto_extend_blocks" value="1" <?php checked( ! empty( $s['auto_extend_blocks'] ) ); ?>/> <?php esc_html_e( 'When a new order matches an existing block, add its other credentials (email, phone, IP, account, address) to that block automatically', 'fraud-shield' ); ?></label>
								<?php
								$this->settings_effects(
									__( 'A blocked ring stays blocked as it rotates to fresh emails and cards, because each blocked attempt teaches the block its new credentials. Only fires on orders that already matched a block.', 'fraud-shield' ),
									__( 'Blocks only ever contain the credentials you added. Use each block\'s Re-sync button to grow it by hand.', 'fraud-shield' )
								);
								?>
							</td>
						</tr>
					</table>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Cancel orders linked to the blocklist', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'The blocklist refuses a customer whose own email, phone, IP, account or address is blocked. This rule reaches one step further: a customer who is NOT blocked but shares a credential with someone who is — the same shipping address, phone or account as a blocked identity, on this order or on any earlier order they placed — has the order cancelled the moment it is created, before any payment is taken. A shared shipping address is enough on its own. A shared IP never is: an IP only counts alongside another shared credential, because carrier and campus networks put strangers behind one address. It catches the ring member whose own details were never blocked, the drop address a blocked customer reused under a fresh email, and blocks that were imported or added by hand and so never cascaded. Every cancelled order records exactly which accounts and profiles the shared credentials lead to. Applies in Observe and Enforce mode alike, like the blocklist itself.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Auto-cancel linked orders', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="linked_cancel_enabled" value="1" <?php checked( ! empty( $s['linked_cancel_enabled'] ) ); ?>/> <?php esc_html_e( 'Automatically cancel any order placed by a customer who shares credentials with a blocked identity', 'fraud-shield' ); ?></label>
								<?php
								$this->settings_effects(
									__( 'The order is created, cancelled with a note naming the shared credential and the blocked account or profile it leads to, and the checkout stops before payment with the message below. The order shows an "Auto-cancelled" badge in the Fraud Shield column of WooCommerce → Orders and is listed on the Auto-Cancelled page, where one click blocks the customer outright or verifies them. If the same customer tries again while the link stands, the retry is refused without creating another order and counted against the first. Verified customers, verified credentials, allowlisted emails and completed ID checks are exempt: the link is an inference, not a verdict.', 'fraud-shield' ),
									__( 'Only a direct blocklist match stops a checkout. A customer who shares an address or IP with a blocked identity, but is not blocked themselves, can order normally; the risk score still notes the shared address or IP.', 'fraud-shield' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Credentials that count as a link', 'fraud-shield' ); ?></th>
							<td>
								<?php
								$fs_link_types = array(
									'address' => __( 'Shipping address — the exact address, the same first address line and ZIP, or the same door with the unit rewritten. Never a street alone. Enough on its own.', 'fraud-shield' ),
									'phone'   => __( 'Phone — the customer shares a phone number with a blocked identity. Enough on its own.', 'fraud-shield' ),
									'user'    => __( 'Account — an account this customer has used is blocked. Enough on its own.', 'fraud-shield' ),
									'email'   => __( 'Email — another email this account has ordered under is blocked. Enough on its own.', 'fraud-shield' ),
									'ip'      => __( 'IP address — corroborating only: an IP shared with a blocked identity is recorded as evidence and never cancels an order by itself. It counts only alongside one of the credentials above.', 'fraud-shield' ),
								);
								$fs_ticked     = isset( $s['linked_cancel_types'] ) && is_array( $s['linked_cancel_types'] ) ? $s['linked_cancel_types'] : array();
								foreach ( $fs_link_types as $fs_lt => $fs_lt_label ) :
									?>
									<label style="display:block;margin:0 0 6px;"><input type="checkbox" name="linked_cancel_types[]" value="<?php echo esc_attr( $fs_lt ); ?>" <?php checked( in_array( $fs_lt, $fs_ticked, true ) ); ?>/> <?php echo esc_html( $fs_lt_label ); ?></label>
								<?php endforeach; ?>
								<?php if ( ! empty( $s['linked_cancel_enabled'] ) && empty( array_diff( $fs_ticked, array( 'ip' ) ) ) ) : ?>
									<p class="description" style="color:#d63638;font-weight:600;"><?php esc_html_e( 'No credential that can cancel on its own is ticked (an IP alone never does), so this rule can never fire. Tick at least one of address, phone, account or email — or switch the rule off.', 'fraud-shield' ); ?></p>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'A link through a destination lock (a blocked city, ZIP or street) never counts: those block places, not people.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Message the customer sees', 'fraud-shield' ); ?></th>
							<td>
								<input type="text" name="linked_cancel_message" class="large-text" value="<?php echo esc_attr( $s['linked_cancel_message'] ?? '' ); ?>" />
								<p class="description"><?php esc_html_e( 'Shown at checkout when the order is cancelled, and again on any later attempt while the link stands. Keep it neutral: it is read by the person you are refusing.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Financial Protection assumptions', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'The Financial Protection page turns recorded chargebacks and stopped checkouts into loss figures. It only ever uses what you enter here and what the store has actually recorded; anything not configured is shown as unavailable rather than guessed.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Chargeback fee', 'fraud-shield' ); ?></th>
							<td>
								<input type="number" name="fin_chargeback_fee" min="0" step="0.01" class="small-text" value="<?php echo esc_attr( (float) ( $s['fin_chargeback_fee'] ?? 0 ) > 0 ? wc_format_decimal( $s['fin_chargeback_fee'], 2 ) : '' ); // A number input wants "12.50" in every locale, never "12,50". ?>" placeholder="<?php esc_attr_e( 'not set', 'fraud-shield' ); ?>" /> <?php echo esc_html( get_woocommerce_currency() ); ?>
								<p class="description"><?php esc_html_e( 'What your processor charges per chargeback, on top of the reversed amount. Leave empty and fees are reported as unavailable instead of assumed.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Share of a charged-back order lost', 'fraud-shield' ); ?></th>
							<td>
								<input type="number" name="fin_loss_ratio" min="1" max="100" step="1" class="small-text" value="<?php echo esc_attr( (int) ( $s['fin_loss_ratio'] ?? 100 ) ); ?>" /> %
								<p class="description"><?php esc_html_e( '100% means the goods are gone and the full amount is reversed — the usual outcome of a fraudulent order. Lower it if you routinely recover shipped goods, or if you would rather estimate lost cost than lost revenue.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Minimum observation window for projections', 'fraud-shield' ); ?></th>
							<td>
								<input type="number" name="fin_min_window_days" min="30" max="365" step="1" class="small-text" value="<?php echo esc_attr( (int) ( $s['fin_min_window_days'] ?? 90 ) ); ?>" /> <?php esc_html_e( 'days', 'fraud-shield' ); ?>
								<p class="description"><?php esc_html_e( 'A blocked customer\'s observed chargebacks are annualized over the time they were active, never over less than this. At 90 days a burst of chargebacks in one week is projected at four times its value, not fifty.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Email alerts', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Send an email the moment an order is placed at or above the chosen risk level, or by anyone on the blocklist. Works in Observe mode too, so you hear about risky orders even before you enforce anything.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable alerts', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="notify_enabled" value="1" <?php checked( ! empty( $s['notify_enabled'] ) ); ?>/> <?php esc_html_e( 'Email me when a risky order is placed', 'fraud-shield' ); ?></label>
								<?php
								$this->settings_effects(
									__( 'The recipients below get an email for each order at or above the chosen level, and for every blocklist match.', 'fraud-shield' ),
									__( 'No emails are sent. Risky orders still get scored and annotated; you just have to come looking.', 'fraud-shield' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Recipients', 'fraud-shield' ); ?></th>
							<td>
								<input type="text" name="notify_emails" class="large-text" value="<?php echo esc_attr( $s['notify_emails'] ); ?>" placeholder="fraud@example.com, owner@example.com" />
								<p class="description"><?php esc_html_e( 'One or more email addresses, separated by commas.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Alert at level', 'fraud-shield' ); ?></th>
							<td>
								<select name="notify_min_level">
									<option value="medium" <?php selected( $s['notify_min_level'], 'medium' ); ?>><?php esc_html_e( 'Medium and above', 'fraud-shield' ); ?></option>
									<option value="high" <?php selected( $s['notify_min_level'], 'high' ); ?>><?php esc_html_e( 'High and above (recommended)', 'fraud-shield' ); ?></option>
									<option value="block" <?php selected( $s['notify_min_level'], 'block' ); ?>><?php esc_html_e( 'Block level only', 'fraud-shield' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Blocklist matches always trigger an alert regardless of the level.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Data on uninstall', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'What happens to Fraud Shield\'s data if the plugin is ever deleted from the Plugins screen. The order index, the blocklist, and your customers\' completed ID verifications cannot be recreated by hand, so keeping them is the default; ID verifications can also be pulled back from Didit on the ID Verifications page.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Remove all data on uninstall', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="remove_data_on_uninstall" value="1" <?php checked( ! empty( $s['remove_data_on_uninstall'] ) ); ?>/> <?php esc_html_e( 'Deleting the plugin also deletes every table, option, and meta value it created', 'fraud-shield' ); ?></label>
								<?php
								$this->settings_effects(
									__( 'Uninstalling wipes everything: the order index, blocklist, checkout ledger, ID verifications, and all settings. There is no undo.', 'fraud-shield' ),
									__( 'Uninstalling removes only the code. All data stays in the database and is picked straight back up on reinstall.', 'fraud-shield' )
								);
								?>
							</td>
						</tr>
					</table>
				</div>

				<?php $this->settings_section( __( 'Checkout controls', 'fraud-shield' ), __( 'Deliberate rules that limit who can check out and how. Each one works in both Observe and Enforce mode.', 'fraud-shield' ) ); ?>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Checkout velocity rule', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'A plain count, not points: once one customer already has the configured number of orders inside the window you set, their next order is placed On-Hold for review, refused at checkout, or limited to the restricted gateways — your choice below. The 7-day and 30-day velocity rules in the risk score are separate and unaffected.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable the velocity rule', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="velocity_lock" value="1" <?php checked( ! empty( $s['velocity_lock'] ) ); ?>/> <?php esc_html_e( 'Act on high-frequency customers', 'fraud-shield' ); ?></label>
								<?php
								$this->settings_effects(
									__( 'A customer over the threshold has their next order held for review, refused, or restricted (the choice below) until you verify them or their window rolls on.', 'fraud-shield' ),
									__( 'Customers can place any number of orders. The 7-day and 30-day velocity rules still add to the risk score.', 'fraud-shield' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Threshold and window', 'fraud-shield' ); ?></th>
							<td>
								<?php esc_html_e( 'Act once the customer already has', 'fraud-shield' ); ?>
								<input type="number" name="velocity_lock_threshold" min="1" value="<?php echo (int) $s['velocity_lock_threshold']; ?>" class="small-text"/>
								<?php esc_html_e( 'orders in the last', 'fraud-shield' ); ?>
								<input type="number" name="velocity_lock_window" min="1" value="<?php echo (int) ( $s['velocity_lock_window'] ?? 30 ); ?>" class="small-text"/>
								<select name="velocity_lock_window_unit">
									<option value="days" <?php selected( (string) ( $s['velocity_lock_window_unit'] ?? 'days' ), 'days' ); ?>><?php esc_html_e( 'days', 'fraud-shield' ); ?></option>
									<option value="hours" <?php selected( (string) ( $s['velocity_lock_window_unit'] ?? 'days' ), 'hours' ); ?>><?php esc_html_e( 'hours', 'fraud-shield' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'The order that crosses the line is the one acted on: with "2 orders in the last 30 days", a customer\'s third order in a month is held (or refused). "2 orders in the last 24 hours" acts on the third order of a day. Set the threshold to 1 to allow a single order per window.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'When over the threshold', 'fraud-shield' ); ?></th>
							<td>
								<?php $fs_vact = FS_Live::velocity_action( $s ); ?>
								<select name="velocity_lock_action">
									<option value="hold" <?php selected( $fs_vact, 'hold' ); ?>><?php esc_html_e( 'Accept the order but place it On-Hold for manual review (recommended)', 'fraud-shield' ); ?></option>
									<option value="block" <?php selected( $fs_vact, 'block' ); ?>><?php esc_html_e( 'Refuse the checkout until manually verified', 'fraud-shield' ); ?></option>
									<option value="restrict" <?php selected( $fs_vact, 'restrict' ); ?>><?php esc_html_e( 'Allow checkout, but only on the restricted gateways below', 'fraud-shield' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Hold: the checkout completes, the order is set On-Hold with a note saying how many earlier orders the customer had in the window, it stays On-Hold when the payment lands, the customer is listed on Checkout Locks under the velocity rule, and your hold (or alert) recipients are emailed. Nothing ships until a person looks; verifying the customer releases their paid held orders to processing. Refuse: the customer sees the lock message and no order is created. Restrict: high-velocity customers keep checking out on the gateways you tick below (for example, methods that cannot be charged back) and release automatically as their window rolls.', 'fraud-shield' ); ?></p>
								<?php
								$this->render_gateway_checklist( 'velocity_restrict_gateways', (array) ( $s['velocity_restrict_gateways'] ?? array() ) );
								echo '<p class="description">' . esc_html__( 'Used only when "restrict" is selected. Important: with every box unticked the restriction turns itself off and all gateways show (this fail-safe prevents an empty list from locking every customer out), so tick at least one.', 'fraud-shield' ) . '</p>';
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'New accounts only', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="velocity_lock_new_only" value="1" <?php checked( ! empty( $s['velocity_lock_new_only'] ) ); ?>/> <?php esc_html_e( 'Only lock accounts registered within the last', 'fraud-shield' ); ?></label>
								<input type="number" name="velocity_lock_new_months" min="1" value="<?php echo (int) $s['velocity_lock_new_months']; ?>" class="small-text"/> <?php esc_html_e( 'months', 'fraud-shield' ); ?>
								<p class="description"><?php esc_html_e( 'Leave unchecked to apply the lock to every customer, not just new accounts.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Who counts as the same customer', 'fraud-shield' ); ?></th>
							<td>
								<p class="description"><?php esc_html_e( 'Orders are always counted by account and by email. A customer who rotates email addresses between orders keeps everything else — tick what else should count toward their total.', 'fraud-shield' ); ?></p>
								<label><input type="checkbox" name="velocity_lock_by_phone" value="1" <?php checked( ! empty( $s['velocity_lock_by_phone'] ) ); ?>/> <?php esc_html_e( 'The same billing phone number', 'fraud-shield' ); ?></label><br/>
								<label><input type="checkbox" name="velocity_lock_by_door" value="1" <?php checked( ! empty( $s['velocity_lock_by_door'] ) ); ?>/> <?php esc_html_e( 'The same shipping door (house number + street, any unit, any ZIP)', 'fraud-shield' ); ?></label>
								<p class="description"><?php esc_html_e( 'The door is unit-blind, so in an apartment building it counts every resident — a locked neighbour sees the lock message and is released with Verify. Turn it on when the fraud you see ships to a reshipper or drop address under changing names.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Lock message', 'fraud-shield' ); ?></th>
							<td>
								<textarea name="velocity_lock_message" rows="3" class="large-text"><?php echo esc_textarea( $s['velocity_lock_message'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Shown to the customer at checkout when they are locked. Basic HTML is allowed, for example a link to your contact page.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
					<p class="description"><?php echo wp_kses_post( sprintf( /* translators: %s: Checkout Locks page URL */ __( 'See and release everyone currently locked on the <a href="%s">Checkout Locks</a> page.', 'fraud-shield' ), esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-locks' ) ) ) ); ?></p>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'New-account verification hold', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'When a recently-registered account places a large first order, lock the account from placing further orders and email your team to call the customer. Review and release held accounts in the Verification queue on the Checkout Locks page.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable hold', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="hold_enabled" value="1" <?php checked( ! empty( $s['hold_enabled'] ) ); ?>/> <?php esc_html_e( 'Hold new accounts after a large order until your team verifies them', 'fraud-shield' ); ?></label>
								<?php
								$this->settings_effects(
									__( 'The triggering order is accepted (and optionally set On-Hold); the account cannot place further orders until verified, and your team is emailed to make the call.', 'fraud-shield' ),
									__( 'New accounts can place any number of large orders back to back. The risk score still notes new-account signals.', 'fraud-shield' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Account age', 'fraud-shield' ); ?></th>
							<td>
								<?php esc_html_e( 'Registered within the last', 'fraud-shield' ); ?>
								<input type="number" name="hold_days" min="1" value="<?php echo (int) $s['hold_days']; ?>" class="small-text"/> <?php esc_html_e( 'days', 'fraud-shield' ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Order amount over', 'fraud-shield' ); ?></th>
							<td>
								<input type="number" name="hold_amount" min="0" step="0.01" value="<?php echo esc_attr( $s['hold_amount'] ); ?>" class="regular-text" style="width:120px;"/>
								<p class="description"><?php esc_html_e( 'An order above this total from an account newer than the age above triggers the hold. The triggering order is placed (and optionally held for review); it is the account\'s FURTHER orders that are blocked until verified.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Hold the triggering order', 'fraud-shield' ); ?></th>
							<td><label><input type="checkbox" name="hold_order" value="1" <?php checked( ! empty( $s['hold_order'] ) ); ?>/> <?php esc_html_e( 'Also set the triggering order to On-Hold so it is not fulfilled before verification (recommended)', 'fraud-shield' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Notify emails', 'fraud-shield' ); ?></th>
							<td>
								<input type="text" name="hold_emails" class="large-text" value="<?php echo esc_attr( $s['hold_emails'] ); ?>"/>
								<p class="description"><?php esc_html_e( 'Comma-separated. These addresses are emailed each time an account is put under a verification hold, with the details to make the call.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Hold message', 'fraud-shield' ); ?></th>
							<td>
								<textarea name="hold_message" rows="3" class="large-text"><?php echo esc_textarea( $s['hold_message'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Shown to the held customer at checkout. Basic HTML allowed.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'High-value order review', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Every order at or above this amount is listed on the High-Value Review page for a person to look at — the customer\'s profile, address, IP and who they are connected to — and record a verdict: looks good, something off (hold the package), or cancel now. The page also shows who reviewed what this week and a leaderboard. This is a queue, not a control: nothing is held or refused until someone decides.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Order total at or above', 'fraud-shield' ); ?></th>
							<td>
								<input type="number" name="hv_review_amount" min="0" step="0.01" value="<?php echo esc_attr( $s['hv_review_amount'] ); ?>" class="regular-text" style="width:120px;"/>
								<p class="description"><?php esc_html_e( 'Compared against the order total (what the customer paid) as recorded in the order index, so orders placed before this version appear on the same footing as new ones. Set to 0 to switch the review page off.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<?php $this->settings_section( __( 'Payment methods', 'fraud-shield' ), __( 'Which payment methods each kind of customer may use, and the order amounts those rules compare against.', 'fraud-shield' ) ); ?>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Order amounts', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Several rules on this page compare the order against a money threshold: the low-value allowance below and the high-value ID rules further down. This is the one place that decides what "the order amount" means for all of them, so no two rules can measure the same order differently.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Compare thresholds against', 'fraud-shield' ); ?></th>
							<td>
								<select name="amount_basis">
									<option value="subtotal" <?php selected( (string) ( $s['amount_basis'] ?? 'subtotal' ), 'subtotal' ); ?>><?php esc_html_e( 'Item subtotal: what the items cost after coupons, before shipping and tax (recommended)', 'fraud-shield' ); ?></option>
									<option value="total" <?php selected( (string) ( $s['amount_basis'] ?? 'subtotal' ), 'total' ); ?>><?php esc_html_e( 'Grand total: the final amount the customer pays, shipping and tax included', 'fraud-shield' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Item subtotal matches what most people mean by "a $500 order". With the grand total, a $480 basket can cross a $500 threshold once shipping and tax land on top, which is usually a surprise.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'New-customer payment gateways', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Limit which payment methods a recently-registered account may use, for example to keep brand-new accounts off credit-card processors until they have a track record. Verified customers and accounts older than the window below always get every gateway.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable restriction', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="gateway_restrict_enabled" value="1" <?php checked( ! empty( $s['gateway_restrict_enabled'] ) ); ?>/> <?php esc_html_e( 'Restrict payment methods for recently-registered accounts', 'fraud-shield' ); ?></label>
								<?php
								$this->settings_effects(
									__( 'New customers see only the gateways ticked below (plus the low-value allowance). Everyone else sees every gateway.', 'fraud-shield' ),
									__( 'Every customer sees every gateway, regardless of account age.', 'fraud-shield' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Account age', 'fraud-shield' ); ?></th>
							<td>
								<?php esc_html_e( 'Applies to accounts registered within the last', 'fraud-shield' ); ?>
								<input type="number" name="gateway_restrict_days" min="1" value="<?php echo (int) $s['gateway_restrict_days']; ?>" class="small-text"/> <?php esc_html_e( 'days', 'fraud-shield' ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Allowed gateways', 'fraud-shield' ); ?></th>
							<td>
								<?php $this->render_gateway_checklist( 'gateway_restrict_allowed', (array) $s['gateway_restrict_allowed'] ); ?>
								<p class="description"><?php esc_html_e( 'This is an ALLOW list: new customers may ONLY use the checked gateways, and everything unchecked is withheld from them. With every box unchecked (or only inactive gateways checked) the restriction turns itself off; this fail-safe prevents an empty list from locking every new customer out of checkout.', 'fraud-shield' ); ?></p>
								<p class="description"><?php esc_html_e( 'Match is by exact gateway id. Entries marked "seen at checkout only" are added by another plugin (a payment rotator, for example) on the storefront without registering in wp-admin — tick those very ids to govern them.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Low-value allowance', 'fraud-shield' ); ?></th>
							<td>
								<?php esc_html_e( 'Also allow the gateways below when the order amount is under', 'fraud-shield' ); ?>
								<input type="number" name="gateway_restrict_lowvalue_amount" min="0" step="0.01" value="<?php echo esc_attr( $s['gateway_restrict_lowvalue_amount'] ); ?>" class="regular-text" style="width:110px;"/>
								<p class="description"><?php esc_html_e( 'Lets a restricted customer use an otherwise-withheld processor (for example a credit card) for small orders, while larger orders stay restricted. This allowance is a rule about order size, so it applies to EVERY restricted checkout: new customers, velocity-restricted customers, and accounts on the Restricted checkout list — except where a per-account allowance was set on the account itself, which then takes precedence. Set the amount to 0 to switch the allowance off. "Order amount" means whichever basis you chose under Order amounts above; this also applies on the order-pay page, where the amount is read from the order being paid.', 'fraud-shield' ); ?></p>
								<?php $this->render_gateway_checklist( 'gateway_restrict_lowvalue_gateways', (array) $s['gateway_restrict_lowvalue_gateways'] ); ?>
								<p class="description"><?php esc_html_e( 'The allowance grants exactly the ids ticked here, so tick the id the checkout actually serves — with a payment rotator that is usually an entry marked "seen at checkout only", not the wp-admin gateway of the same name. The preview below says so whenever a ticked id cannot currently reach a customer.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Checkout notice', 'fraud-shield' ); ?></th>
							<td>
								<textarea name="gateway_restrict_notice" rows="2" class="large-text"><?php echo esc_textarea( $s['gateway_restrict_notice'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Optional. Shown above the payment methods to restricted new customers on the classic checkout (leave empty to show nothing). Block checkout does not display it.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Rejection message', 'fraud-shield' ); ?></th>
							<td>
								<textarea name="gateway_restrict_reject_message" rows="2" class="large-text"><?php echo esc_textarea( $s['gateway_restrict_reject_message'] ?? '' ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Shown if a customer submits an order on a method they are not allowed to use. The allowed methods are the only ones displayed, so this is the backstop for a stale checkout page, a cached form, or a request that skipped the page entirely. The order is refused rather than taken on the wrong processor.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'How new customers get out', 'fraud-shield' ); ?></th>
							<td>
								<p class="description"><?php esc_html_e( 'A new customer who needs a withheld payment method has three routes: complete the Didit ID verification at checkout, be verified by your team, or simply age past the window above with a clean history. Nothing else lifts it.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>

					<?php $this->render_gateway_preview(); ?>
				</div>

				<?php $this->settings_section( __( 'Trust and identity', 'fraud-shield' ), __( 'How customers become trusted: automatically through history, or by proving who they are with a government ID.', 'fraud-shield' ) ); ?>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Automatic verification for established customers', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Long-standing customers who have never cost you money should not keep tripping the automatic controls. A customer who meets every condition below is treated as verified: full payment methods, no velocity lock, no new-account hold. This is evaluated live, so a customer who takes a chargeback tomorrow stops qualifying immediately.', 'fraud-shield' ); ?></p>
					<p class="description"><strong><?php esc_html_e( 'It never overrides the blocklist,', 'fraud-shield' ); ?></strong> <?php esc_html_e( 'a destination lock, or a staff request for an ID. Those are human decisions, and no amount of good history undoes them.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="auto_verify_enabled" value="1" <?php checked( ! empty( $s['auto_verify_enabled'] ) ); ?>/> <?php esc_html_e( 'Automatically verify established customers with a clean history', 'fraud-shield' ); ?></label>
								<?php
								$this->settings_effects(
									__( 'Customers meeting every condition below get full payment methods and skip the velocity lock and new-account hold, with no staff action. The high-value rules treat them as "automatically verified".', 'fraud-shield' ),
									__( 'History earns nothing by itself. Customers are only ever verified by your team or by an ID check.', 'fraud-shield' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Customer for at least', 'fraud-shield' ); ?></th>
							<td>
								<input type="number" name="auto_verify_min_days" min="1" value="<?php echo (int) ( $s['auto_verify_min_days'] ?? 180 ); ?>" class="small-text"/> <?php esc_html_e( 'days', 'fraud-shield' ); ?>
								<p class="description"><?php esc_html_e( 'Measured from the account registration date, or, for a customer who has never registered, from the first order you ever took from their email. Default 180 days.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Paid orders', 'fraud-shield' ); ?></th>
							<td>
								<?php esc_html_e( 'At least', 'fraud-shield' ); ?>
								<input type="number" name="auto_verify_min_orders" min="0" value="<?php echo (int) ( $s['auto_verify_min_orders'] ?? 1 ); ?>" class="small-text"/> <?php esc_html_e( 'paid orders', 'fraud-shield' ); ?>
								<p class="description"><?php esc_html_e( 'An old account that has never bought anything has not proved anything, so it does not qualify on age alone. Set to 0 to let age carry it by itself.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Clean history', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="auto_verify_require_clean" value="1" <?php checked( ! empty( $s['auto_verify_require_clean'] ) ); ?>/> <?php esc_html_e( 'Require no chargebacks and no refunds', 'fraud-shield' ); ?></label>
								<p class="description"><?php esc_html_e( 'A single chargeback or fully-refunded order disqualifies the customer for good, unless staff verify them by hand. Note that a PARTIAL refund does not change an order status, so it is not counted here.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Restricted checkout: how customers get out', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'When staff unblock a customer into restricted checkout, that limit is meant to be probation rather than a permanent state. These two rules end it without anyone having to remember to go back and clear it. They apply to per-customer restrictions only; customers restricted by the velocity rule already release themselves as their 30-day window rolls.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Lapses after', 'fraud-shield' ); ?></th>
							<td>
								<input type="number" name="gw_restrict_expire_months" min="0" max="120" value="<?php echo (int) ( $s['gw_restrict_expire_months'] ?? 6 ); ?>" class="small-text"/> <?php esc_html_e( 'months', 'fraud-shield' ); ?>
								<p class="description"><?php esc_html_e( 'The restriction is dropped this long after it was applied, and the customer goes back to the normal checkout rules. Default 6 months. Set to 0 to keep restrictions until staff clear them by hand. Changing this re-times restrictions already in place, since the window is measured from the date each one was applied. Months count as 30 days.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'ID verification', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="gw_restrict_release_on_idv" value="1" <?php checked( ! empty( $s['gw_restrict_release_on_idv'] ) ); ?>/> <?php esc_html_e( 'An approved ID check ends restricted checkout immediately', 'fraud-shield' ); ?></label>
								<p class="description"><?php esc_html_e( 'A customer who proves their identity through Didit is removed from the Restricted checkout list and regains every payment method straight away, without waiting out the window above. Blocklist entries are untouched either way: passing an ID check is never a way around an explicit block.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
					<p class="description"><?php echo wp_kses_post( sprintf( /* translators: %s: Checkout Locks page URL */ __( 'Everyone currently on restricted checkout is listed on the <a href="%s">Checkout Locks</a> page, with the date each restriction lapses.', 'fraud-shield' ), esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-locks' ) ) ) ); ?></p>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Address resolution: geocoder, validation and area bans', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'A street or building lock is only as good as the city and ZIP the customer types — and a reshipper types a different ZIP, or one of a city\'s several acceptable names, and the carrier still delivers. A geocoder resolves every checkout that could meet a lock to its physical destination first — the standardized street, the delivery ZIP, the city, the county, and the point on the map — so the lock is matched against THAT as well as the typed text, and an area ban (a radius around a location) can be tested at all. An address the geocoder cannot find, on a road that is locked anywhere in the state, is stopped for correction rather than approved on text nobody confirmed. Results are cached per address; a store with no street, building or area lock never makes a request; an outage falls back to typed-text matching and never blocks a checkout.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Geocoder', 'fraud-shield' ); ?></th>
							<td>
								<?php $fs_prov = FS_Address_Check::provider(); ?>
								<select name="addr_check_provider">
									<option value="census" <?php selected( $fs_prov, 'census' ); ?>><?php esc_html_e( 'US Census Bureau geocoder — free, no key, US addresses only (recommended for US stores)', 'fraud-shield' ); ?></option>
									<option value="google" <?php selected( $fs_prov, 'google' ); ?>><?php esc_html_e( 'Google Address Validation — worldwide, delivery-point confirmation, paid beyond the monthly credit (key below)', 'fraud-shield' ); ?></option>
									<option value="nominatim" <?php selected( $fs_prov, 'nominatim' ); ?>><?php esc_html_e( 'OpenStreetMap Nominatim — free, worldwide, one request per second by policy (small stores outside the US)', 'fraud-shield' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'The Census geocoder matches the house number and street to a census address range and returns the corrected street, city, state and ZIP, the county, and a point on the block — accurate to tens of metres, which is what an area ban needs. It does not confirm that a particular unit exists. Google adds USPS delivery-point confirmation and a rooftop point. Nominatim returns the OpenStreetMap house number and road where the map knows them; its usage policy asks for a contact address and allows one request per second, which Fraud Shield enforces.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Validate destination locks', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="addr_check_enabled" value="1" <?php checked( ! empty( $s['addr_check_enabled'] ) ); ?>/> <?php esc_html_e( 'Resolve the physical destination before matching street and building locks', 'fraud-shield' ); ?></label>
								<?php
								$this->settings_effects(
									__( 'Street and building locks match the resolved destination — a rotated ZIP or an alternative city name no longer walks past them — and an address that cannot be found, on a locked road, is stopped for correction.', 'fraud-shield' ),
									__( 'Street and building locks match the typed city and ZIP only, within the boundaries you give each lock. Area bans still resolve the address — they cannot work any other way.', 'fraud-shield' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Google API key', 'fraud-shield' ); ?></th>
							<td>
								<input type="password" name="addr_check_google_key" class="regular-text" value="<?php echo esc_attr( (string) ( $s['addr_check_google_key'] ?? '' ) ); ?>" autocomplete="off"/>
								<p class="description"><?php esc_html_e( 'Only for the Google geocoder: a Google Cloud API key with the Address Validation API enabled (restrict it to that API).', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Contact for Nominatim', 'fraud-shield' ); ?></th>
							<td>
								<input type="text" name="addr_check_contact" class="regular-text" value="<?php echo esc_attr( (string) ( $s['addr_check_contact'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"/>
								<p class="description"><?php esc_html_e( 'Only for Nominatim: the email address or URL its usage policy asks every application to identify itself with. Your admin email when empty.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Unverifiable address on a locked road', 'fraud-shield' ); ?></th>
							<td>
								<select name="addr_check_ambiguous_action">
									<option value="block" <?php selected( (string) ( $s['addr_check_ambiguous_action'] ?? 'block' ), 'block' ); ?>><?php esc_html_e( 'Stop the checkout for correction (recommended)', 'fraud-shield' ); ?></option>
									<option value="allow" <?php selected( (string) ( $s['addr_check_ambiguous_action'] ?? 'block' ), 'allow' ); ?>><?php esc_html_e( 'Allow it on the typed city and ZIP', 'fraud-shield' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Applies only when the service could not confirm the address AND the road it names is locked somewhere in the same state — the one case where the typed city and ZIP are all that keeps the order outside a lock. Everyone else is unaffected.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Correction message', 'fraud-shield' ); ?></th>
							<td>
								<textarea name="addr_check_ambiguous_message" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $s['addr_check_ambiguous_message'] ?? '' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Shown to the customer when their address is stopped for correction. Basic HTML is allowed.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'ID verification (Didit)', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'A one-time government-ID check, hosted by Didit, offered at checkout to customers whose payment methods are restricted. When Didit approves the document and the name on it matches the customer, they become a verified credential: full payment methods, no velocity lock, no new-account hold. Blocklist entries always still win, and ID document images never touch this site.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="idv_enabled" value="1" <?php checked( ! empty( $s['idv_enabled'] ) ); ?>/> <?php esc_html_e( 'Offer ID verification at checkout', 'fraud-shield' ); ?></label>
								<?php
								$this->settings_effects(
									__( 'Restricted customers see a "Verify my ID" card above the payment methods and can unlock themselves in about a minute. The high-value rules below and the "Block checkout and request ID" profile action also become available.', 'fraud-shield' ),
									__( 'No verification is offered or required anywhere. Any outstanding ID request or high-value rule stands down rather than stranding customers behind a check that cannot run.', 'fraud-shield' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'API key', 'fraud-shield' ); ?></th>
							<td>
								<input type="password" name="idv_api_key" class="regular-text" value="<?php echo esc_attr( $s['idv_api_key'] ); ?>" autocomplete="off"/>
								<p class="description"><?php esc_html_e( 'From the Didit Business Console: your application, then API & Webhooks, then API Key.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Workflow ID', 'fraud-shield' ); ?></th>
							<td>
								<input type="text" name="idv_workflow_id" class="regular-text" value="<?php echo esc_attr( $s['idv_workflow_id'] ); ?>"/>
								<p class="description"><?php esc_html_e( 'The verification workflow to run (Console, then Workflows). Use one with ID Verification; add Face Match or Liveness there if you also want a selfie check.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Webhook', 'fraud-shield' ); ?></th>
							<td>
								<code><?php echo esc_html( FS_IDV::webhook_url() ); ?></code>
								<p class="description"><?php esc_html_e( 'Paste this URL as a webhook in the Didit console, then paste its secret key below. Recommended for instant results; without it, checkout falls back to polling Didit directly.', 'fraud-shield' ); ?></p>
								<input type="password" name="idv_webhook_secret" class="regular-text" value="<?php echo esc_attr( $s['idv_webhook_secret'] ); ?>" autocomplete="off" placeholder="<?php esc_attr_e( 'Webhook secret key', 'fraud-shield' ); ?>"/>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Name match', 'fraud-shield' ); ?></th>
							<td>
								<select name="idv_name_match">
									<option value="lenient" <?php selected( $s['idv_name_match'], 'lenient' ); ?>><?php esc_html_e( 'Lenient: last name plus first initial must match (recommended)', 'fraud-shield' ); ?></option>
									<option value="strict" <?php selected( $s['idv_name_match'], 'strict' ); ?>><?php esc_html_e( 'Strict: the full billing name must appear on the document', 'fraud-shield' ); ?></option>
									<option value="off" <?php selected( $s['idv_name_match'], 'off' ); ?>><?php esc_html_e( 'Off: any approved document verifies the customer', 'fraud-shield' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Compares the name on the ID with the customer\'s billing name. A stolen card plus someone else\'s genuine ID passes Didit but fails this. Innocent mismatches are common too: married names, transliterations, a middle name printed on the document.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'On a name mismatch', 'fraud-shield' ); ?></th>
							<td>
								<select name="idv_mismatch_action">
									<option value="verify" <?php selected( $s['idv_mismatch_action'] ?? 'verify', 'verify' ); ?>><?php esc_html_e( 'Verify the customer anyway, and flag it for review', 'fraud-shield' ); ?></option>
									<option value="hold" <?php selected( $s['idv_mismatch_action'] ?? 'verify', 'hold' ); ?>><?php esc_html_e( 'Hold: do not verify until staff accept the mismatch', 'fraud-shield' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'The customer completed a genuine ID check, so the default is to honour it: their payment methods unlock immediately and the mismatch is still recorded, shown on the order screen, and listed on the ID Verifications page for review. Choose "Hold" for the stricter posture, where a mismatch unlocks nothing until a human accepts it. That is safer against a stolen ID paired with a stolen card, at the cost of stranding customers whose name simply differs from their document.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Valid for', 'fraud-shield' ); ?></th>
							<td><input type="number" name="idv_valid_days" min="0" value="<?php echo (int) $s['idv_valid_days']; ?>" class="small-text"/> <?php esc_html_e( 'days (0 = never expires)', 'fraud-shield' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Attempt limit', 'fraud-shield' ); ?></th>
							<td><input type="number" name="idv_max_attempts" min="1" value="<?php echo (int) $s['idv_max_attempts']; ?>" class="small-text"/> <?php esc_html_e( 'new sessions per customer per 24 h (protects your Didit balance)', 'fraud-shield' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Checkout invitation', 'fraud-shield' ); ?></th>
							<td><textarea name="idv_notice_text" rows="2" class="large-text"><?php echo esc_textarea( $s['idv_notice_text'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Button label', 'fraud-shield' ); ?></th>
							<td><input type="text" name="idv_button_text" class="regular-text" value="<?php echo esc_attr( $s['idv_button_text'] ); ?>"/></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'In-progress message', 'fraud-shield' ); ?></th>
							<td><textarea name="idv_pending_text" rows="2" class="large-text"><?php echo esc_textarea( $s['idv_pending_text'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Verified message', 'fraud-shield' ); ?></th>
							<td><input type="text" name="idv_verified_text" class="large-text" value="<?php echo esc_attr( $s['idv_verified_text'] ); ?>"/></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Declined message', 'fraud-shield' ); ?></th>
							<td><textarea name="idv_declined_text" rows="2" class="large-text"><?php echo esc_textarea( $s['idv_declined_text'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Consent note', 'fraud-shield' ); ?></th>
							<td>
								<textarea name="idv_consent_text" rows="2" class="large-text"><?php echo esc_textarea( $s['idv_consent_text'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Shown under the button. Mention Didit and update your privacy policy, since ID documents are processed by Didit on your behalf.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'ID requested message', 'fraud-shield' ); ?></th>
							<td>
								<textarea name="idv_required_message" rows="2" class="large-text"><?php echo esc_textarea( $s['idv_required_message'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Shown on the blocked-checkout page to a customer you have hit with "Block checkout & request ID" from their profile. That page also carries the verify button, so they can unlock themselves.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Cardholder check (NMI)', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'An ID check proves who the person is; only the card issuer can attest whose card it is. After a card payment, Fraud Shield asks your NMI account(s) for the issuer\'s verdict on that exact transaction — the AVS address check and the CVV security-code check — and combines it with the ID-verification state. An ID-verified customer whose card passes is recorded as cardholder confirmed; an ID-verified customer whose card FAILS is the classic stolen-card-with-real-ID pattern and can be flagged or held before the order ships.', 'fraud-shield' ); ?></p>
					<p class="description"><?php esc_html_e( 'Only result codes and masked card details (brand, BIN, last four) are stored — never the card number. Results appear on each order\'s Card Check box, in an order note, and on the Card Checks page.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="card_check_enabled" value="1" <?php checked( ! empty( $s['card_check_enabled'] ) ); ?>/> <?php esc_html_e( 'Check each card payment against the issuer\'s AVS/CVV verdict', 'fraud-shield' ); ?></label>
								<?php
								$this->settings_effects(
									__( 'Shortly after each payment on the gateways below, the transaction is looked up on NMI and the verdict lands on the order. Failed checks are flagged or held per the policy below. Staff can also run the check on any past NMI order from its Card Check box.', 'fraud-shield' ),
									__( 'No lookups run and nothing new is stored. Results already on orders remain visible.', 'fraud-shield' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'NMI security keys', 'fraud-shield' ); ?></th>
							<td>
								<textarea name="card_check_keys" rows="4" class="large-text code" autocomplete="off" spellcheck="false"><?php echo esc_textarea( $s['card_check_keys'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One key per line, optionally labelled: "Main MID|abc123…". Create each key in that NMI account under Options → Settings → Security Keys (an API key of type "API"). With a payment rotator, add one key per NMI account the rotator settles through — Fraud Shield tries each until it finds the transaction and remembers which key served which gateway. Treat these like passwords: a security key grants API access to its merchant account, so use a dedicated restricted user where your NMI reseller allows it. Lines starting with # are ignored.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Query endpoint', 'fraud-shield' ); ?></th>
							<td>
								<input type="text" name="card_check_endpoint" class="large-text code" value="<?php echo esc_attr( $s['card_check_endpoint'] ); ?>"/>
								<p class="description"><?php esc_html_e( 'Leave the default unless your processor runs a white-labelled NMI portal (a branded domain instead of secure.nmi.com) — then use https://your-portal-domain/api/query.php.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Gateways to check', 'fraud-shield' ); ?></th>
							<td>
								<?php $this->render_gateway_checklist( 'card_check_gateways', (array) ( $s['card_check_gateways'] ?? array() ) ); ?>
								<p class="description"><?php esc_html_e( 'Tick the NMI-backed gateways. With every box unticked, every order that carries a gateway transaction id is tried — harmless for non-NMI orders (they simply come back "not found"), but tidier to scope it. A staff-triggered check from the order screen ignores this scope.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Partial address matches', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="card_check_accept_partial" value="1" <?php checked( ! empty( $s['card_check_accept_partial'] ) ); ?>/> <?php esc_html_e( 'Count a partial AVS match (ZIP only, or street only) as a pass', 'fraud-shield' ); ?></label>
								<p class="description"><?php esc_html_e( 'Unticked (recommended), only a full street-and-ZIP match passes; partial matches are recorded as their own middle verdict and never trigger the failure action either way. A failure always means the issuer positively said "no match" — or the CVV was wrong.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'When a check fails', 'fraud-shield' ); ?></th>
							<td>
								<select name="card_check_action">
									<option value="flag" <?php selected( $s['card_check_action'] ?? 'flag', 'flag' ); ?>><?php esc_html_e( 'Flag: order note + Card Checks list (recommended to start)', 'fraud-shield' ); ?></option>
									<option value="hold" <?php selected( $s['card_check_action'] ?? 'flag', 'hold' ); ?>><?php esc_html_e( 'Hold: also set the paid order to On-Hold so it is not fulfilled', 'fraud-shield' ); ?></option>
									<option value="none" <?php selected( $s['card_check_action'] ?? 'flag', 'none' ); ?>><?php esc_html_e( 'Record only', 'fraud-shield' ); ?></option>
								</select>
								<select name="card_check_scope">
									<option value="all" <?php selected( $s['card_check_scope'] ?? 'all', 'all' ); ?>><?php esc_html_e( 'for every failed check', 'fraud-shield' ); ?></option>
									<option value="idv" <?php selected( $s['card_check_scope'] ?? 'all', 'idv' ); ?>><?php esc_html_e( 'only for ID-verified customers (the stolen-card-with-real-ID case)', 'fraud-shield' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Hold only touches orders still in Processing — an order already completed is flagged, not pulled back. The payment itself is never voided or refunded automatically; the hold exists so a human looks before anything ships.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
					<p class="description"><?php echo wp_kses_post( sprintf( /* translators: %s: Card Checks page URL */ __( 'Every result lands on the <a href="%s">Card Checks</a> page. To confirm your keys work, open any recent NMI order and press "Run card check now" in its Card Check box.', 'fraud-shield' ), esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-cards' ) ) ) ); ?></p>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'High-value orders', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Big orders deserve a higher bar. Above the amounts you set here, the order requires an ID check, and WHICH amount applies depends on how much you already trust the customer: someone with no verification is asked sooner, an automatically verified regular later, and even an ID-verified customer can be asked to verify again for a very large order.', 'fraud-shield' ); ?></p>
					<p class="description"><?php esc_html_e( 'Amounts use the basis chosen under Order amounts. A customer who completes the check inside the freshness window below sails through; one who declines can still pay with the fallback gateways you tick, except at or above the hard amount, where the order is refused until they verify. Requires Didit to be configured above; if it is not, these rules stand down entirely so nobody is ever blocked behind a check that cannot run. The staff Verified account flag is exempt from everything here.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable', 'fraud-shield' ); ?></th>
							<td>
								<label><input type="checkbox" name="hv_enabled" value="1" <?php checked( ! empty( $s['hv_enabled'] ) ); ?>/> <?php esc_html_e( 'Require ID verification for high-value orders', 'fraud-shield' ); ?></label>
								<?php
								$this->settings_effects(
									__( 'Orders over the per-tier amounts show a verification card at checkout and limit payment to the fallback gateways until the customer verifies. Orders at or above the hard amount are refused until a recent check exists.', 'fraud-shield' ),
									__( 'Order size never triggers a verification. Every other ID verification feature keeps working as before.', 'fraud-shield' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Customers with no verification', 'fraud-shield' ); ?></th>
							<td>
								<?php esc_html_e( 'Ask for an ID when the order amount is at or over', 'fraud-shield' ); ?>
								<input type="number" name="hv_amount_none" min="0" step="0.01" value="<?php echo esc_attr( $s['hv_amount_none'] ); ?>" class="regular-text" style="width:120px;"/>
								<p class="description"><?php esc_html_e( 'Applies to guests and to accounts that are neither verified nor old enough to qualify for automatic verification. Set 0 to switch this tier off.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Automatically verified customers', 'fraud-shield' ); ?></th>
							<td>
								<?php esc_html_e( 'Ask for an ID when the order amount is at or over', 'fraud-shield' ); ?>
								<input type="number" name="hv_amount_auto" min="0" step="0.01" value="<?php echo esc_attr( $s['hv_amount_auto'] ); ?>" class="regular-text" style="width:120px;"/>
								<p class="description"><?php esc_html_e( 'Applies to customers who qualified through the Automatic verification rules above (established account, clean history). Their track record buys them a higher threshold, not a free pass. Set 0 to switch this tier off.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'ID-verified customers', 'fraud-shield' ); ?></th>
							<td>
								<?php esc_html_e( 'Ask for a FRESH ID check when the order amount is at or over', 'fraud-shield' ); ?>
								<input type="number" name="hv_amount_idv" min="0" step="0.01" value="<?php echo esc_attr( $s['hv_amount_idv'] ); ?>" class="regular-text" style="width:120px;"/>
								<p class="description"><?php esc_html_e( 'Re-verification: a customer who verified a month ago and now places an order this large is asked to verify again, because the person placing today\'s order is the one who matters. Their existing verification stays valid for everything else. Set 0 to never re-ask.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'A check stays fresh for', 'fraud-shield' ); ?></th>
							<td>
								<input type="number" name="hv_fresh_hours" min="1" value="<?php echo (int) $s['hv_fresh_hours']; ?>" class="small-text"/> <?php esc_html_e( 'hours', 'fraud-shield' ); ?>
								<p class="description"><?php esc_html_e( 'A check completed within this window satisfies every rule in this panel, so a customer who verifies during checkout is not asked again by the next page load, and two large orders on the same day need only one check. Default 24.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Until they verify', 'fraud-shield' ); ?></th>
							<td>
								<p style="margin-top:0;"><?php esc_html_e( 'A customer who has not completed the required check may still pay using these gateways:', 'fraud-shield' ); ?></p>
								<?php $this->render_gateway_checklist( 'hv_gateways', (array) ( $s['hv_gateways'] ?? array() ) ); ?>
								<p class="description"><?php esc_html_e( 'Tick the methods you are comfortable taking on a large order from an unverified customer, for example methods that cannot be charged back. Leave every box unticked and nothing is withheld: the card at checkout still invites verification, but all payment methods stay available (invite-only mode). If none of the ticked methods is available at checkout, the restriction stands down rather than leaving the customer with nothing.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Hard requirement at', 'fraud-shield' ); ?></th>
							<td>
								<?php esc_html_e( 'Refuse the order outright when the amount is at or over', 'fraud-shield' ); ?>
								<input type="number" name="hv_hard_amount" min="0" step="0.01" value="<?php echo esc_attr( $s['hv_hard_amount'] ); ?>" class="regular-text" style="width:120px;"/>
								<p class="description"><?php esc_html_e( 'At or above this amount there is no fallback: the order is refused at submission until a fresh check exists, no matter how the customer is verified. Only the staff Verified account flag is exempt. Set 0 to have no hard rule. Example: 10000 means every order of $10,000 or more requires an ID verification, full stop.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Checkout invitation', 'fraud-shield' ); ?></th>
							<td>
								<textarea name="hv_notice_text" rows="2" class="large-text"><?php echo esc_textarea( $s['hv_notice_text'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Shown on the verification card for customers verifying because of the order size. {amount} is replaced with the threshold that fired.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Re-verification invitation', 'fraud-shield' ); ?></th>
							<td>
								<textarea name="hv_reverify_text" rows="2" class="large-text"><?php echo esc_textarea( $s['hv_reverify_text'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Shown instead of the text above to customers who have verified before and are being asked again. Saying "verify once to unlock everything" to someone who already did reads as a broken promise, so this text should acknowledge the repeat. {amount} works here too.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Refusal message', 'fraud-shield' ); ?></th>
							<td>
								<textarea name="hv_block_message" rows="2" class="large-text"><?php echo esc_textarea( $s['hv_block_message'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Shown when an order at or above the hard amount is submitted without a fresh check. {amount} is replaced with the hard amount. Guests are additionally told to sign in, since verification is saved to the account.', 'fraud-shield' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<?php $this->settings_section( __( 'Risk scoring', 'fraud-shield' ), __( 'How the heuristic score is built: the points each signal adds, when signals fire, and where the level cut-offs sit.', 'fraud-shield' ) ); ?>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Risk level cut-offs', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'A score at or above each threshold sets that level.', 'fraud-shield' ); ?></p>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><?php esc_html_e( 'Medium ≥', 'fraud-shield' ); ?></th><td><input type="number" name="levels[medium]" value="<?php echo (int) $s['levels']['medium']; ?>" class="small-text"/></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'High ≥', 'fraud-shield' ); ?></th><td><input type="number" name="levels[high]" value="<?php echo (int) $s['levels']['high']; ?>" class="small-text"/></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Block ≥', 'fraud-shield' ); ?></th><td><input type="number" name="levels[block]" value="<?php echo (int) $s['levels']['block']; ?>" class="small-text"/></td></tr>
					</table>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Rule weights (points)', 'fraud-shield' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						$weight_labels = array(
							'cb_email'              => __( 'Email linked to a prior chargeback', 'fraud-shield' ),
							'cb_phone'              => __( 'Phone linked to a prior chargeback', 'fraud-shield' ),
							'cb_ship'               => __( 'Exact shipping address linked to a prior chargeback', 'fraud-shield' ),
							'cb_ship_loose'         => __( 'Same street/ZIP (different unit) linked to a chargeback', 'fraud-shield' ),
							'cb_ip'                 => __( 'Order IP linked to a prior chargeback', 'fraud-shield' ),
							'addr_multi_email'      => __( 'Address shared by many emails (base)', 'fraud-shield' ),
							'addr_multi_email_each' => __( '… per extra email beyond threshold', 'fraud-shield' ),
							'addr_multi_name'       => __( 'Address shared by many names', 'fraud-shield' ),
							'email_multi_addr'      => __( 'One email shipping to many addresses', 'fraud-shield' ),
							'ip_multi_account'      => __( 'Many accounts from one IP', 'fraud-shield' ),
							'new_account'           => __( 'New account / first-seen recently', 'fraud-shield' ),
							'velocity_7d'           => __( 'Order velocity (7 days)', 'fraud-shield' ),
							'velocity_7d_each'      => __( '… per extra order beyond the 7-day threshold', 'fraud-shield' ),
							'velocity_30d'          => __( 'Order velocity (30 days)', 'fraud-shield' ),
							'order_burst'           => __( 'Back-to-back orders (within 24 hours)', 'fraud-shield' ),
							'order_burst_each'      => __( '… per extra order beyond the 24-hour threshold', 'fraud-shield' ),
							'disposable_email'      => __( 'Disposable email domain', 'fraud-shield' ),
							'country_mismatch'      => __( 'Billing vs shipping country mismatch', 'fraud-shield' ),
							'bill_ship_mismatch'    => __( 'Billing ≠ shipping address (repeat)', 'fraud-shield' ),
							'card_only'             => __( 'Pays exclusively via card processors', 'fraud-shield' ),
							'fast_shipping'         => __( 'Expedited / overnight shipping method', 'fraud-shield' ),
							'idv_name_mismatch'     => __( 'Approved ID names a different person', 'fraud-shield' ),
							'idv_doc_state_mismatch' => __( 'ID document state differs from shipping state', 'fraud-shield' ),
							'idv_geo_mismatch'      => __( 'ID verified from a different country than the order', 'fraud-shield' ),
							'idv_vpn'               => __( 'ID verified over a VPN or Tor', 'fraud-shield' ),
						);
						foreach ( $weight_labels as $key => $label ) {
							echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><input type="number" name="weights[' . esc_attr( $key ) . ']" value="' . (int) ( $s['weights'][ $key ] ?? 0 ) . '" class="small-text"/></td></tr>';
						}
						?>
					</table>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Rule thresholds', 'fraud-shield' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						$th_labels = array(
							'addr_email_min'   => __( 'Distinct emails at one address to flag', 'fraud-shield' ),
							'addr_name_min'    => __( 'Distinct names at one address to flag', 'fraud-shield' ),
							'email_addr_min'   => __( 'Distinct addresses per email to flag', 'fraud-shield' ),
							'ip_account_min'   => __( 'Distinct accounts per IP to flag', 'fraud-shield' ),
							'new_account_days' => __( '"New account" age (days)', 'fraud-shield' ),
							'velocity_7d_min'  => __( 'Orders in 7 days to flag', 'fraud-shield' ),
							'velocity_30d_min' => __( 'Orders in 30 days to flag', 'fraud-shield' ),
							'order_burst_min'  => __( 'Orders within 24 hours to flag (back-to-back)', 'fraud-shield' ),
							'cap_addr_email'   => __( 'Cap on per-extra-email bonus', 'fraud-shield' ),
							'cap_velocity_7d'  => __( 'Cap on per-extra-order bonus (7 days)', 'fraud-shield' ),
							'cap_order_burst'  => __( 'Cap on per-extra-order bonus (24 hours)', 'fraud-shield' ),
							'bill_ship_min'    => __( 'Billing ≠ shipping orders to flag', 'fraud-shield' ),
							'card_only_min'    => __( 'Card-only orders required to flag', 'fraud-shield' ),
						);
						foreach ( $th_labels as $key => $label ) {
							echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><input type="number" name="thresholds[' . esc_attr( $key ) . ']" value="' . (int) ( $s['thresholds'][ $key ] ?? 0 ) . '" class="small-text"/></td></tr>';
						}
						?>
					</table>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Payment method classification', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Label each payment method seen in your orders. Methods marked "Card processor" can be charged back, and customers who pay exclusively with them are the population most capable of a chargeback; the "Pays exclusively via card processors" rule uses these labels. Unlabeled methods are treated as not a card processor.', 'fraud-shield' ); ?></p>
					<?php
					$fs_pm       = FS_Data::distinct_payment_methods();
					$fs_pm_label = FS_Core::payment_labels();
					if ( empty( $fs_pm ) ) {
						echo '<p class="description">' . esc_html__( 'No payment methods indexed yet. Run a scan first.', 'fraud-shield' ) . '</p>';
					} else {
						echo '<table class="form-table" role="presentation">';
						foreach ( $fs_pm as $row ) {
							$m   = (string) $row['method'];
							$cur = isset( $fs_pm_label[ $m ] ) ? $fs_pm_label[ $m ] : 'other';
							echo '<tr><th scope="row">' . esc_html( $m ) . ' <span class="fs-muted">(' . esc_html( number_format_i18n( (int) $row['c'] ) ) . ')</span></th><td>';
							echo '<input type="hidden" name="fs_pm_key[]" value="' . esc_attr( $m ) . '" />';
							echo '<select name="fs_pm_val[]">';
							echo '<option value="other"' . selected( $cur, 'other', false ) . '>' . esc_html__( 'Other (not chargebackable)', 'fraud-shield' ) . '</option>';
							echo '<option value="card"' . selected( $cur, 'card', false ) . '>' . esc_html__( 'Card processor (chargebackable)', 'fraud-shield' ) . '</option>';
							echo '</select></td></tr>';
						}
						echo '</table>';
					}
					?>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Shipping method classification', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Label each shipping method seen in your orders. Methods marked "Expedited / overnight" feed the expedited-shipping risk rule. Unlabeled methods are treated as standard. Older orders need a re-index to capture their shipping method: run a full scan with "Re-index orders already scanned".', 'fraud-shield' ); ?></p>
					<?php
					$fs_sm       = FS_Data::distinct_shipping_methods();
					$fs_sm_label = FS_Core::shipping_labels();
					if ( empty( $fs_sm ) ) {
						echo '<p class="description">' . esc_html__( 'No shipping methods indexed yet. Run (or re-run) a scan to capture them.', 'fraud-shield' ) . '</p>';
					} else {
						echo '<table class="form-table" role="presentation">';
						foreach ( $fs_sm as $row ) {
							$m   = (string) $row['method'];
							$cur = isset( $fs_sm_label[ $m ] ) ? $fs_sm_label[ $m ] : 'other';
							echo '<tr><th scope="row">' . esc_html( $m ) . ' <span class="fs-muted">(' . esc_html( number_format_i18n( (int) $row['c'] ) ) . ')</span></th><td>';
							echo '<input type="hidden" name="fs_sm_key[]" value="' . esc_attr( $m ) . '" />';
							echo '<select name="fs_sm_val[]">';
							echo '<option value="other"' . selected( $cur, 'other', false ) . '>' . esc_html__( 'Standard', 'fraud-shield' ) . '</option>';
							echo '<option value="expedited"' . selected( $cur, 'expedited', false ) . '>' . esc_html__( 'Expedited / overnight', 'fraud-shield' ) . '</option>';
							echo '</select></td></tr>';
						}
						echo '</table>';
					}
					?>
				</div>

				<div class="fs-panel">
					<h2><?php esc_html_e( 'Disposable email domains', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Orders placed from these throwaway email domains gain the disposable-email risk points. One domain per line.', 'fraud-shield' ); ?></p>
					<textarea name="disposable_domains" rows="8" class="large-text code"><?php echo esc_textarea( implode( "\n", $s['disposable_domains'] ) ); ?></textarea>
				</div>

				<div class="fs-save-row">
					<button class="button button-primary button-hero"><?php esc_html_e( 'Save settings', 'fraud-shield' ); ?></button>
					<p class="description">
					<?php
					printf(
						/* translators: 1: opening <strong>, 2: closing </strong> */
						esc_html__( 'After changing weights or thresholds, use %1$sRecompute scores%2$s on the Dashboard to re-apply them to historical orders.', 'fraud-shield' ),
						'<strong>',
						'</strong>'
					);
					?>
					</p>
				</div>
			</form>
		</div>
		<?php
	}

	/* ===================== Handlers ===================== */

	/**
	 * Save settings.
	 */
	public function handle_save_settings() {
		$this->guard();
		check_admin_referer( 'fs_save_settings' );

		$defaults = FS_Core::default_settings();
		$new      = $defaults;

		$new['trust_banner']      = ! empty( $_POST['trust_banner'] );
		$new['trust_banner_text'] = isset( $_POST['trust_banner_text'] ) ? sanitize_text_field( wp_unslash( $_POST['trust_banner_text'] ) ) : $defaults['trust_banner_text'];

		$new['mode']          = ( isset( $_POST['mode'] ) && 'enforce' === $_POST['mode'] ) ? 'enforce' : 'observe';
		$allowed_high         = array( 'hold', 'block', 'flag' );
		$new['action_high']   = ( isset( $_POST['action_high'] ) && in_array( $_POST['action_high'], $allowed_high, true ) ) ? sanitize_key( wp_unslash( $_POST['action_high'] ) ) : 'hold';
		$new['block_message'] = isset( $_POST['block_message'] ) ? sanitize_text_field( wp_unslash( $_POST['block_message'] ) ) : $defaults['block_message'];
		$new['count_refunded_as_chargeback'] = ! empty( $_POST['count_refunded_as_chargeback'] );
		$new['auto_extend_blocks']           = ! empty( $_POST['auto_extend_blocks'] );

		// Linked-identity auto-cancel. An empty type list is stored as empty
		// (the Settings page warns that the rule can then never fire) rather
		// than silently reset to "everything" behind the merchant's back.
		$new['linked_cancel_enabled'] = ! empty( $_POST['linked_cancel_enabled'] );
		$new['linked_cancel_types']   = array();
		if ( isset( $_POST['linked_cancel_types'] ) && is_array( $_POST['linked_cancel_types'] ) ) {
			$new['linked_cancel_types'] = array_values( array_intersect( FS_Blocklist::LINK_TYPES, array_map( 'sanitize_key', array_map( 'wp_unslash', $_POST['linked_cancel_types'] ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$new['linked_cancel_message'] = isset( $_POST['linked_cancel_message'] ) ? wp_kses_post( wp_unslash( $_POST['linked_cancel_message'] ) ) : $defaults['linked_cancel_message'];
		if ( '' === trim( wp_strip_all_tags( (string) $new['linked_cancel_message'] ) ) ) {
			$new['linked_cancel_message'] = $defaults['linked_cancel_message'];
		}

		// Financial Protection assumptions.
		$new['fin_chargeback_fee']  = isset( $_POST['fin_chargeback_fee'] ) ? max( 0.0, (float) wc_format_decimal( wp_unslash( $_POST['fin_chargeback_fee'] ) ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$new['fin_loss_ratio']      = isset( $_POST['fin_loss_ratio'] ) ? min( 100, max( 1, (int) $_POST['fin_loss_ratio'] ) ) : $defaults['fin_loss_ratio'];
		$new['fin_min_window_days'] = isset( $_POST['fin_min_window_days'] ) ? min( 365, max( 30, (int) $_POST['fin_min_window_days'] ) ) : $defaults['fin_min_window_days'];

		$new['notify_enabled']   = ! empty( $_POST['notify_enabled'] );
		$new['notify_emails']    = isset( $_POST['notify_emails'] ) ? sanitize_text_field( wp_unslash( $_POST['notify_emails'] ) ) : $defaults['notify_emails'];
		$allowed_levels          = array( 'medium', 'high', 'block' );
		$new['notify_min_level'] = ( isset( $_POST['notify_min_level'] ) && in_array( $_POST['notify_min_level'], $allowed_levels, true ) ) ? sanitize_key( wp_unslash( $_POST['notify_min_level'] ) ) : 'high';

		$new['velocity_lock']            = ! empty( $_POST['velocity_lock'] );
		$new['velocity_lock_threshold']  = isset( $_POST['velocity_lock_threshold'] ) ? max( 1, (int) $_POST['velocity_lock_threshold'] ) : $defaults['velocity_lock_threshold'];
		$new['velocity_lock_action']     = ( isset( $_POST['velocity_lock_action'] ) && in_array( $_POST['velocity_lock_action'], array( 'hold', 'block', 'restrict' ), true ) ) ? sanitize_key( wp_unslash( $_POST['velocity_lock_action'] ) ) : 'hold';
		$new['velocity_lock_window']     = isset( $_POST['velocity_lock_window'] ) ? max( 1, (int) $_POST['velocity_lock_window'] ) : $defaults['velocity_lock_window'];
		$new['velocity_lock_window_unit'] = ( isset( $_POST['velocity_lock_window_unit'] ) && 'hours' === $_POST['velocity_lock_window_unit'] ) ? 'hours' : 'days';
		$new['velocity_restrict_gateways'] = array();
		if ( isset( $_POST['velocity_restrict_gateways'] ) && is_array( $_POST['velocity_restrict_gateways'] ) ) {
			$new['velocity_restrict_gateways'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['velocity_restrict_gateways'] ) ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$new['velocity_lock_new_only']   = ! empty( $_POST['velocity_lock_new_only'] );
		$new['velocity_lock_new_months'] = isset( $_POST['velocity_lock_new_months'] ) ? max( 1, (int) $_POST['velocity_lock_new_months'] ) : $defaults['velocity_lock_new_months'];
		$new['velocity_lock_by_phone']   = ! empty( $_POST['velocity_lock_by_phone'] );
		$new['velocity_lock_by_door']    = ! empty( $_POST['velocity_lock_by_door'] );
		$new['velocity_lock_message']    = isset( $_POST['velocity_lock_message'] ) ? wp_kses_post( wp_unslash( $_POST['velocity_lock_message'] ) ) : $defaults['velocity_lock_message'];

		$new['hold_enabled'] = ! empty( $_POST['hold_enabled'] );
		$new['hold_days']    = isset( $_POST['hold_days'] ) ? max( 1, (int) $_POST['hold_days'] ) : $defaults['hold_days'];
		$new['hold_amount']  = isset( $_POST['hold_amount'] ) ? max( 0, (float) $_POST['hold_amount'] ) : $defaults['hold_amount'];
		$new['hold_order']   = ! empty( $_POST['hold_order'] );
		$new['hold_emails']  = isset( $_POST['hold_emails'] ) ? sanitize_text_field( wp_unslash( $_POST['hold_emails'] ) ) : $defaults['hold_emails'];
		$new['hold_message'] = isset( $_POST['hold_message'] ) ? wp_kses_post( wp_unslash( $_POST['hold_message'] ) ) : $defaults['hold_message'];

		// High-value order review (v1.32). 0 is a valid answer: it switches the
		// review page off rather than falling back to the default.
		$new['hv_review_amount'] = isset( $_POST['hv_review_amount'] ) ? max( 0, (float) $_POST['hv_review_amount'] ) : $defaults['hv_review_amount'];

		$new['gateway_restrict_enabled'] = ! empty( $_POST['gateway_restrict_enabled'] );
		$new['gateway_restrict_days']    = isset( $_POST['gateway_restrict_days'] ) ? max( 1, (int) $_POST['gateway_restrict_days'] ) : $defaults['gateway_restrict_days'];
		$new['gateway_restrict_notice']  = isset( $_POST['gateway_restrict_notice'] ) ? wp_kses_post( wp_unslash( $_POST['gateway_restrict_notice'] ) ) : '';
		$new['gateway_restrict_reject_message'] = isset( $_POST['gateway_restrict_reject_message'] ) ? wp_kses_post( wp_unslash( $_POST['gateway_restrict_reject_message'] ) ) : $defaults['gateway_restrict_reject_message'];
		if ( '' === trim( wp_strip_all_tags( (string) $new['gateway_restrict_reject_message'] ) ) ) {
			// A refusal with no explanation is a dead end for the customer.
			$new['gateway_restrict_reject_message'] = $defaults['gateway_restrict_reject_message'];
		}
		$new['gateway_restrict_allowed'] = array();
		if ( isset( $_POST['gateway_restrict_allowed'] ) && is_array( $_POST['gateway_restrict_allowed'] ) ) {
			$new['gateway_restrict_allowed'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['gateway_restrict_allowed'] ) ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$new['gateway_restrict_lowvalue_amount']   = isset( $_POST['gateway_restrict_lowvalue_amount'] ) ? max( 0, (float) $_POST['gateway_restrict_lowvalue_amount'] ) : 0;
		$new['gateway_restrict_lowvalue_gateways'] = array();
		if ( isset( $_POST['gateway_restrict_lowvalue_gateways'] ) && is_array( $_POST['gateway_restrict_lowvalue_gateways'] ) ) {
			$new['gateway_restrict_lowvalue_gateways'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['gateway_restrict_lowvalue_gateways'] ) ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		// How a per-customer restricted checkout ends. Capped at 120 months so a
		// fat-fingered value can't become an effectively permanent restriction;
		// 0 means "never lapses" on purpose.
		$new['gw_restrict_expire_months']  = isset( $_POST['gw_restrict_expire_months'] ) ? min( 120, max( 0, (int) $_POST['gw_restrict_expire_months'] ) ) : $defaults['gw_restrict_expire_months'];
		$new['gw_restrict_release_on_idv'] = ! empty( $_POST['gw_restrict_release_on_idv'] );

		// Automatic verification for established, clean customers.
		$new['auto_verify_enabled']       = ! empty( $_POST['auto_verify_enabled'] );
		$new['auto_verify_min_days']      = isset( $_POST['auto_verify_min_days'] ) ? max( 1, (int) $_POST['auto_verify_min_days'] ) : $defaults['auto_verify_min_days'];
		$new['auto_verify_min_orders']    = isset( $_POST['auto_verify_min_orders'] ) ? max( 0, (int) $_POST['auto_verify_min_orders'] ) : $defaults['auto_verify_min_orders'];
		$new['auto_verify_require_clean'] = ! empty( $_POST['auto_verify_require_clean'] );

		// Address validation for destination locks (v1.31).
		$new['addr_check_enabled']           = ! empty( $_POST['addr_check_enabled'] );
		$new['addr_check_provider']          = ( isset( $_POST['addr_check_provider'] ) && in_array( $_POST['addr_check_provider'], array( 'census', 'google', 'nominatim' ), true ) ) ? sanitize_key( wp_unslash( $_POST['addr_check_provider'] ) ) : 'census';
		$new['addr_check_google_key']        = isset( $_POST['addr_check_google_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['addr_check_google_key'] ) ) ) : '';
		$new['addr_check_contact']           = isset( $_POST['addr_check_contact'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['addr_check_contact'] ) ) ) : '';
		$new['addr_check_ambiguous_action']  = ( isset( $_POST['addr_check_ambiguous_action'] ) && 'allow' === $_POST['addr_check_ambiguous_action'] ) ? 'allow' : 'block';
		$new['addr_check_ambiguous_message'] = isset( $_POST['addr_check_ambiguous_message'] ) ? wp_kses_post( wp_unslash( $_POST['addr_check_ambiguous_message'] ) ) : $defaults['addr_check_ambiguous_message'];

		$new['idv_enabled']        = ! empty( $_POST['idv_enabled'] );
		$new['idv_api_key']        = isset( $_POST['idv_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['idv_api_key'] ) ) ) : '';
		$new['idv_workflow_id']    = isset( $_POST['idv_workflow_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['idv_workflow_id'] ) ) ) : '';
		$new['idv_webhook_secret'] = isset( $_POST['idv_webhook_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['idv_webhook_secret'] ) ) ) : '';
		$allowed_nm                = array( 'off', 'lenient', 'strict' );
		$new['idv_name_match']     = ( isset( $_POST['idv_name_match'] ) && in_array( $_POST['idv_name_match'], $allowed_nm, true ) ) ? sanitize_key( wp_unslash( $_POST['idv_name_match'] ) ) : 'lenient';
		$new['idv_mismatch_action'] = ( isset( $_POST['idv_mismatch_action'] ) && 'hold' === $_POST['idv_mismatch_action'] ) ? 'hold' : 'verify';
		$new['idv_valid_days']     = isset( $_POST['idv_valid_days'] ) ? max( 0, (int) $_POST['idv_valid_days'] ) : $defaults['idv_valid_days'];
		$new['idv_max_attempts']   = isset( $_POST['idv_max_attempts'] ) ? max( 1, (int) $_POST['idv_max_attempts'] ) : $defaults['idv_max_attempts'];
		$new['idv_notice_text']    = isset( $_POST['idv_notice_text'] ) ? wp_kses_post( wp_unslash( $_POST['idv_notice_text'] ) ) : $defaults['idv_notice_text'];
		$new['idv_button_text']    = isset( $_POST['idv_button_text'] ) ? sanitize_text_field( wp_unslash( $_POST['idv_button_text'] ) ) : $defaults['idv_button_text'];
		$new['idv_pending_text']   = isset( $_POST['idv_pending_text'] ) ? wp_kses_post( wp_unslash( $_POST['idv_pending_text'] ) ) : $defaults['idv_pending_text'];
		$new['idv_verified_text']  = isset( $_POST['idv_verified_text'] ) ? sanitize_text_field( wp_unslash( $_POST['idv_verified_text'] ) ) : $defaults['idv_verified_text'];
		$new['idv_declined_text']  = isset( $_POST['idv_declined_text'] ) ? wp_kses_post( wp_unslash( $_POST['idv_declined_text'] ) ) : $defaults['idv_declined_text'];
		$new['idv_consent_text']   = isset( $_POST['idv_consent_text'] ) ? wp_kses_post( wp_unslash( $_POST['idv_consent_text'] ) ) : $defaults['idv_consent_text'];

		$new['idv_required_message'] = isset( $_POST['idv_required_message'] ) ? wp_kses_post( wp_unslash( $_POST['idv_required_message'] ) ) : $defaults['idv_required_message'];
		if ( '' === trim( wp_strip_all_tags( (string) $new['idv_required_message'] ) ) ) {
			// Never gate a customer behind an empty explanation.
			$new['idv_required_message'] = $defaults['idv_required_message'];
		}

		// Cardholder check via NMI.
		$new['card_check_enabled']  = ! empty( $_POST['card_check_enabled'] );
		$new['card_check_keys']     = isset( $_POST['card_check_keys'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['card_check_keys'] ) ) ) : '';
		$new['card_check_endpoint'] = isset( $_POST['card_check_endpoint'] ) ? esc_url_raw( trim( wp_unslash( $_POST['card_check_endpoint'] ) ) ) : $defaults['card_check_endpoint'];
		if ( '' === $new['card_check_endpoint'] || 0 !== strpos( $new['card_check_endpoint'], 'https://' ) ) {
			// The security key travels in the request body; it never goes out unencrypted.
			$new['card_check_endpoint'] = $defaults['card_check_endpoint'];
		}
		$new['card_check_gateways'] = array();
		if ( isset( $_POST['card_check_gateways'] ) && is_array( $_POST['card_check_gateways'] ) ) {
			$new['card_check_gateways'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['card_check_gateways'] ) ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$new['card_check_accept_partial'] = ! empty( $_POST['card_check_accept_partial'] );
		$allowed_cc_action                = array( 'none', 'flag', 'hold' );
		$new['card_check_action']         = ( isset( $_POST['card_check_action'] ) && in_array( $_POST['card_check_action'], $allowed_cc_action, true ) ) ? sanitize_key( wp_unslash( $_POST['card_check_action'] ) ) : 'flag';
		$new['card_check_scope']          = ( isset( $_POST['card_check_scope'] ) && 'idv' === $_POST['card_check_scope'] ) ? 'idv' : 'all';

		// The shared basis every money threshold compares against.
		$new['amount_basis'] = ( isset( $_POST['amount_basis'] ) && 'total' === $_POST['amount_basis'] ) ? 'total' : 'subtotal';

		$new['remove_data_on_uninstall'] = ! empty( $_POST['remove_data_on_uninstall'] );

		// High-value order rules.
		$new['hv_enabled']     = ! empty( $_POST['hv_enabled'] );
		$new['hv_amount_none'] = isset( $_POST['hv_amount_none'] ) ? max( 0, (float) $_POST['hv_amount_none'] ) : $defaults['hv_amount_none'];
		$new['hv_amount_auto'] = isset( $_POST['hv_amount_auto'] ) ? max( 0, (float) $_POST['hv_amount_auto'] ) : $defaults['hv_amount_auto'];
		$new['hv_amount_idv']  = isset( $_POST['hv_amount_idv'] ) ? max( 0, (float) $_POST['hv_amount_idv'] ) : $defaults['hv_amount_idv'];
		$new['hv_hard_amount'] = isset( $_POST['hv_hard_amount'] ) ? max( 0, (float) $_POST['hv_hard_amount'] ) : $defaults['hv_hard_amount'];
		$new['hv_fresh_hours'] = isset( $_POST['hv_fresh_hours'] ) ? max( 1, (int) $_POST['hv_fresh_hours'] ) : $defaults['hv_fresh_hours'];
		$new['hv_gateways']    = ( isset( $_POST['hv_gateways'] ) && is_array( $_POST['hv_gateways'] ) )
			? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['hv_gateways'] ) ) ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();
		$new['hv_notice_text']   = isset( $_POST['hv_notice_text'] ) ? wp_kses_post( wp_unslash( $_POST['hv_notice_text'] ) ) : $defaults['hv_notice_text'];
		$new['hv_reverify_text'] = isset( $_POST['hv_reverify_text'] ) ? wp_kses_post( wp_unslash( $_POST['hv_reverify_text'] ) ) : $defaults['hv_reverify_text'];
		$new['hv_block_message'] = isset( $_POST['hv_block_message'] ) ? wp_kses_post( wp_unslash( $_POST['hv_block_message'] ) ) : $defaults['hv_block_message'];
		if ( '' === trim( wp_strip_all_tags( (string) $new['hv_block_message'] ) ) ) {
			// A hard refusal must always come with an explanation.
			$new['hv_block_message'] = $defaults['hv_block_message'];
		}
		foreach ( array( 'hv_notice_text', 'hv_reverify_text' ) as $hv_text_key ) {
			if ( '' === trim( wp_strip_all_tags( (string) $new[ $hv_text_key ] ) ) ) {
				$new[ $hv_text_key ] = $defaults[ $hv_text_key ];
			}
		}

		foreach ( array_keys( $defaults['weights'] ) as $k ) {
			if ( isset( $_POST['weights'][ $k ] ) ) {
				$new['weights'][ $k ] = max( 0, (int) $_POST['weights'][ $k ] );
			}
		}
		foreach ( array_keys( $defaults['thresholds'] ) as $k ) {
			if ( isset( $_POST['thresholds'][ $k ] ) ) {
				$new['thresholds'][ $k ] = max( 0, (int) $_POST['thresholds'][ $k ] );
			}
		}
		foreach ( array_keys( $defaults['levels'] ) as $k ) {
			if ( isset( $_POST['levels'][ $k ] ) ) {
				$new['levels'][ $k ] = max( 0, (int) $_POST['levels'][ $k ] );
			}
		}

		if ( isset( $_POST['disposable_domains'] ) ) {
			$raw   = sanitize_textarea_field( wp_unslash( $_POST['disposable_domains'] ) );
			$lines = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', strtolower( $raw ) ) ) );
			$new['disposable_domains'] = array_values( array_unique( $lines ) );
		}

		// Payment/shipping classification labels (stored as their own options, not
		// part of fs_settings). Parallel key/value arrays kept aligned by the form.
		$this->save_method_labels( 'fs_payment_labels', 'fs_pm_key', 'fs_pm_val', array( 'card', 'other' ) );
		$this->save_method_labels( 'fs_shipping_labels', 'fs_sm_key', 'fs_sm_val', array( 'expedited', 'other' ) );

		update_option( 'fs_settings', $new );
		FS_Core::instance()->flush_settings();

		$this->redirect_with( self::SLUG . '-settings', 'saved' );
	}

	/**
	 * Persist payment/shipping method classifications from parallel key/value
	 * form arrays. Only meaningful (non-"other") labels are stored, so unlabeled
	 * methods default to "other". Fully rebuilt each save. Nonce is already
	 * verified by the calling handler (handle_save_settings).
	 *
	 * @param string $option    Option name to store the label map under.
	 * @param string $key_field POST field holding the method strings.
	 * @param string $val_field POST field holding the chosen labels.
	 * @param array  $allowed   Allowed label values.
	 */
	private function save_method_labels( $option, $key_field, $val_field, $allowed ) {
		if ( ! isset( $_POST[ $key_field ] ) || ! is_array( $_POST[ $key_field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return; // No rows submitted (nothing indexed) — leave existing labels untouched.
		}
		$raw_keys = wp_unslash( $_POST[ $key_field ] ); // phpcs:ignore WordPress.Security -- each element type-checked + sanitized below.
		$raw_vals = ( isset( $_POST[ $val_field ] ) && is_array( $_POST[ $val_field ] ) ) ? wp_unslash( $_POST[ $val_field ] ) : array(); // phpcs:ignore WordPress.Security

		$map = array();
		foreach ( $raw_keys as $i => $k ) {
			// Type-check BEFORE sanitizing: a crafted nested array (fs_pm_key[0][])
			// would otherwise hit sanitize_text_field() as an array.
			if ( ! is_string( $k ) || ! isset( $raw_vals[ $i ] ) || ! is_string( $raw_vals[ $i ] ) ) {
				continue;
			}
			$k = sanitize_text_field( $k );
			$v = sanitize_key( $raw_vals[ $i ] );
			if ( '' === $k || ! in_array( $v, $allowed, true ) ) {
				continue;
			}
			if ( 'other' !== $v ) {
				$map[ $k ] = $v;
			}
		}
		update_option( $option, $map );
	}

	/**
	 * Add a blocklist entry (handles both POST form and GET "Block" links).
	 */
	public function handle_blocklist_add() {
		$this->guard();
		check_admin_referer( 'fs_blocklist_add' );

		$type    = isset( $_REQUEST['type'] ) ? sanitize_key( wp_unslash( $_REQUEST['type'] ) ) : '';
		$value   = isset( $_REQUEST['value'] ) ? wp_unslash( $_REQUEST['value'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$value   = is_string( $value ) ? sanitize_text_field( $value ) : '';
		$reason  = isset( $_REQUEST['reason'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['reason'] ) ) : '';
		$message = isset( $_REQUEST['message'] ) ? wp_kses_post( wp_unslash( $_REQUEST['message'] ) ) : '';

		// Wrap in a manual block action so it shows as a removable card (never
		// lands in "Ungrouped").
		FS_Blocklist::block_manual( $type, $value, $reason, $message );
		$this->redirect_with( self::SLUG . '-blocklist', 'blocked' );
	}

	/**
	 * Save a blocklist entry's per-entry checkout message.
	 */
	public function handle_blocklist_message() {
		$this->guard();
		check_admin_referer( 'fs_blocklist_message' );

		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$paged   = isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1;
		$message = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';

		if ( $id ) {
			FS_Blocklist::update_message( $id, $message );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::SLUG . '-blocklist',
					'paged'     => $paged,
					'fs_status' => 'msgsaved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Mark a customer verified (exempt from the velocity lock) or revoke it.
	 */
	public function handle_verify_customer() {
		$this->guard();
		check_admin_referer( 'fs_verify_customer' );

		$uid = isset( $_REQUEST['user'] ) ? (int) $_REQUEST['user'] : 0;
		$to  = isset( $_REQUEST['to'] ) && '1' === (string) $_REQUEST['to'];

		if ( $uid > 0 ) {
			// One shared path with the API (v1.30): the Verified flag, the
			// credential-list mirror that lists them on Verified Customers, the
			// open Checkout Locks rows, and the hold (plus its paid held order)
			// are all handled in FS_Live::verify_account().
			if ( $to ) {
				FS_Live::verify_account( $uid );
			} else {
				FS_Live::unverify_account( $uid );
			}
		}

		// Return to wherever the click happened (Checkout Locks page or the
		// account view), falling back to the account view.
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = add_query_arg(
				array(
					'page'        => self::SLUG . '-new-customers',
					'fs_customer' => $uid,
				),
				admin_url( 'admin.php' )
			);
		}
		wp_safe_redirect( add_query_arg( array( 'fs_status' => $to ? 'verified' : 'unverified' ), $back ) );
		exit;
	}

	/**
	 * Add or remove an email from the velocity/hold allowlist (releases a locked
	 * guest email). Does NOT affect the blocklist.
	 */
	public function handle_allow_email() {
		$this->guard();
		check_admin_referer( 'fs_allow_email' );

		$email  = isset( $_REQUEST['email'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['email'] ) ) : '';
		$remove = isset( $_REQUEST['remove'] ) && '1' === (string) $_REQUEST['remove'];

		if ( '' !== $email ) {
			if ( $remove ) {
				FS_Live::allowlist_remove( $email );
			} else {
				FS_Live::allowlist_add( $email );
				// Allowlisting exempts the email from the velocity lock and hold —
				// clear those open ledger rows (blocklist rows stay: allowlist
				// never overrides the blocklist).
				FS_Data::resolve_checkout_blocks( 0, FS_Normalizer::email( $email ), array( 'velocity' ) );
			}
		}

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::SLUG . '-locks' );
		}
		wp_safe_redirect( add_query_arg( array( 'fs_status' => $remove ? 'allow_removed' : 'allowed' ), $back ) );
		exit;
	}

	/**
	 * Dismiss a single checkout-block ledger row (mark it resolved) without
	 * verifying or allowlisting — for a block that was transient or handled.
	 */
	public function handle_resolve_block() {
		$this->guard();
		check_admin_referer( 'fs_resolve_block' );

		$id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;
		if ( $id > 0 ) {
			FS_Data::resolve_checkout_block( $id );
		}

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::SLUG . '-locks' );
		}
		wp_safe_redirect( add_query_arg( array( 'fs_status' => 'dismissed' ), $back ) );
		exit;
	}

	/**
	 * Remove a blocklist entry.
	 */
	public function handle_blocklist_remove() {
		$this->guard();
		check_admin_referer( 'fs_blocklist_remove' );
		$id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;
		if ( $id ) {
			FS_Blocklist::remove( $id );
		}
		$this->redirect_with( self::SLUG . '-blocklist', 'removed' );
	}

	/**
	 * Add a credential (email / address / IP) to the Verified Customers list. A
	 * verified credential bypasses the velocity lock, holds, and gateway
	 * restrictions (never an explicit blocklist entry). Also clears any open
	 * checkout-block ledger rows for a verified email.
	 */
	public function handle_verify_cred() {
		$this->guard();
		check_admin_referer( 'fs_verify_cred' );

		$type  = isset( $_REQUEST['type'] ) ? sanitize_key( wp_unslash( $_REQUEST['type'] ) ) : '';
		$value = isset( $_REQUEST['value'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['value'] ) ) : '';
		$label = isset( $_REQUEST['label'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['label'] ) ) : '';

		$ok = FS_Live::verified_add( $type, $value, $label );
		if ( $ok && 'email' === $type ) {
			FS_Data::resolve_checkout_blocks( 0, FS_Normalizer::email( $value ), array( 'velocity' ) );
		}

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::SLUG . '-verified' );
		}
		wp_safe_redirect( add_query_arg( array( 'fs_status' => $ok ? 'verified_added' : 'verified_dup' ), $back ) );
		exit;
	}

	/**
	 * Remove a credential from the Verified Customers list.
	 */
	public function handle_unverify_cred() {
		$this->guard();
		check_admin_referer( 'fs_unverify_cred' );

		$type  = isset( $_REQUEST['type'] ) ? sanitize_key( wp_unslash( $_REQUEST['type'] ) ) : '';
		$value = isset( $_REQUEST['value'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['value'] ) ) : '';
		FS_Live::verified_remove( $type, $value );

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::SLUG . '-verified' );
		}
		wp_safe_redirect( add_query_arg( array( 'fs_status' => 'verified_removed' ), $back ) );
		exit;
	}

	/**
	 * Demand (or withdraw) an ID verification for one account: "Block checkout
	 * & request ID".
	 *
	 * Deliberately not a blocklist entry. A blocklist entry is a verdict that
	 * holds until staff lift it; this is a question the customer is expected to
	 * answer, and passing the ID check clears it automatically. Keeping the two
	 * apart is what lets this block be self-releasing without ever making an
	 * approved document a way around the blocklist.
	 */
	public function handle_idv_require() {
		$this->guard();
		check_admin_referer( 'fs_idv_require' );

		$uid = isset( $_REQUEST['user'] ) ? (int) $_REQUEST['user'] : 0;
		$to  = isset( $_REQUEST['to'] ) && '1' === (string) $_REQUEST['to'];

		$status = 'idv_noop';
		if ( $uid > 0 ) {
			if ( $to ) {
				// Refuse to strand a customer behind a check the store cannot
				// run — without credentials there would be no way to comply.
				if ( FS_IDV::configured() ) {
					FS_IDV::require_verification( $uid );
					$status = 'idv_requested';
				} else {
					$status = 'idv_unconfigured';
				}
			} else {
				FS_IDV::clear_requirement( $uid );
				$status = 'idv_request_cleared';
			}
		}

		$this->idv_redirect_back( $uid, $status );
	}

	/**
	 * Revoke a completed ID verification (account + email record, and the
	 * verified credential the check itself earned).
	 */
	public function handle_idv_revoke() {
		$this->guard();
		check_admin_referer( 'fs_idv_revoke' );

		$uid   = isset( $_REQUEST['user'] ) ? (int) $_REQUEST['user'] : 0;
		$email = isset( $_REQUEST['email'] ) ? FS_Normalizer::email( rawurldecode( sanitize_text_field( wp_unslash( $_REQUEST['email'] ) ) ) ) : '';

		FS_IDV::revoke( $uid, $email );

		$this->idv_redirect_back( $uid, 'idv_revoked' );
	}

	/**
	 * Accept a name mismatch: the document was genuine and the difference is
	 * explainable (married name, transliteration), so treat it as verified.
	 */
	public function handle_idv_accept() {
		$this->guard();
		check_admin_referer( 'fs_idv_accept' );

		$uid = isset( $_REQUEST['user'] ) ? (int) $_REQUEST['user'] : 0;
		$row = isset( $_REQUEST['row'] ) ? (int) $_REQUEST['row'] : 0;

		$status = 'idv_noop';
		if ( $row > 0 && FS_IDV::accept_mismatch( $row ) ) {
			$status = 'idv_accepted';
		}

		$this->idv_redirect_back( $uid, $status );
	}

	/**
	 * Pull verification sessions back from Didit and rebuild local state.
	 * The recovery path after an uninstall or migration, and the ongoing way
	 * to keep a store in step with its Didit console. Idempotent, so running
	 * it repeatedly (or twice by accident) changes nothing it already did.
	 */
	public function handle_idv_sync() {
		$this->guard();
		check_admin_referer( 'fs_idv_sync' );

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::SLUG . '-idv' );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$report = FS_IDV::sync_from_didit( 240 );
		if ( is_wp_error( $report ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'fs_status' => 'idv_sync_failed',
						'fs_msg'    => rawurlencode( mb_substr( $report->get_error_message(), 0, 200 ) ),
					),
					$back
				)
			);
			exit;
		}

		$args = array(
			'fs_status'    => 'idv_synced',
			'fs_listed'    => (int) $report['listed'],
			'fs_restored'  => (int) $report['restored'],
			'fs_history'   => (int) $report['history'],
			'fs_known'     => (int) $report['known'],
			'fs_unmatched' => (int) $report['unmatched'],
			'fs_foreign'   => (int) ( $report['foreign'] ?? 0 ),
			'fs_remaining' => (int) $report['remaining'],
		);
		if ( ! empty( $report['error'] ) ) {
			$args['fs_partial'] = 1;
			$args['fs_msg']     = rawurlencode( mb_substr( (string) $report['error'], 0, 200 ) );
		} elseif ( ! empty( $report['more'] ) ) {
			$args['fs_more'] = 1;
		}

		wp_safe_redirect( add_query_arg( $args, $back ) );
		exit;
	}

	/**
	 * Rebuild ID-verification attribution from scratch: wipe finished session
	 * rows, withdraw Didit-earned verifications (staff decisions survive),
	 * then run the sync to completion under the ownership rules. The repair
	 * for stores whose table was polluted by another store sharing the same
	 * Didit account — sessions attributed to whoever held the same numeric
	 * user id here, showing customers under other people's names.
	 */
	public function handle_idv_rebuild() {
		$this->guard();
		check_admin_referer( 'fs_idv_rebuild' );

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::SLUG . '-idv' );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$reset  = FS_IDV::rebuild_reset();
		$report = FS_IDV::sync_from_didit( 240 );
		if ( is_wp_error( $report ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'fs_status' => 'idv_sync_failed',
						'fs_msg'    => rawurlencode( mb_substr( $report->get_error_message(), 0, 200 ) ),
					),
					$back
				)
			);
			exit;
		}

		$args = array(
			'fs_status'    => 'idv_rebuilt',
			'fs_purged'    => (int) $reset['purged'],
			'fs_revoked'   => (int) $reset['revoked'],
			'fs_kept'      => (int) $reset['kept_pending'],
			'fs_listed'    => (int) $report['listed'],
			'fs_restored'  => (int) $report['restored'],
			'fs_history'   => (int) $report['history'],
			'fs_unmatched' => (int) $report['unmatched'],
			'fs_foreign'   => (int) ( $report['foreign'] ?? 0 ),
			'fs_remaining' => (int) $report['remaining'],
		);
		if ( ! empty( $report['error'] ) ) {
			$args['fs_partial'] = 1;
			$args['fs_msg']     = rawurlencode( mb_substr( (string) $report['error'], 0, 200 ) );
		} elseif ( ! empty( $report['more'] ) ) {
			$args['fs_more'] = 1;
		}

		wp_safe_redirect( add_query_arg( $args, $back ) );
		exit;
	}

	/**
	 * Import verifications from a CSV: the plugin's own ID Verifications
	 * export (round trip), a Didit console export, or a bare list of emails.
	 */
	public function handle_idv_import() {
		$this->guard();
		check_admin_referer( 'fs_idv_import' );

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::SLUG . '-idv' );
		}

		if ( empty( $_FILES['fs_idv_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['fs_idv_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_safe_redirect( add_query_arg( 'fs_status', 'nofile', $back ) );
			exit;
		}

		$fh = fopen( $_FILES['fs_idv_csv']['tmp_name'], 'r' ); // phpcs:ignore
		if ( ! $fh ) {
			wp_safe_redirect( add_query_arg( 'fs_status', 'badfile', $back ) );
			exit;
		}

		// Column aliases, so the plugin's export, a Didit console export, and a
		// quick hand-made sheet all map without anyone renaming headers.
		$aliases = array(
			'email'       => array( 'email', 'email_norm', 'customer email', 'customer_email' ),
			'user_id'     => array( 'user_id', 'user', 'account', 'account_id' ),
			'session_id'  => array( 'session_id', 'session', 'didit session', 'session id' ),
			'result'      => array( 'outcome', 'result', 'status' ),
			'full_name'   => array( 'document_name', 'full_name', 'name', 'document name', 'name on document' ),
			'name_match'  => array( 'name_match', 'name match' ),
			'doc_type'    => array( 'document_type', 'doc_type', 'document', 'document type' ),
			'verified_at' => array( 'started', 'verified_at', 'verified on', 'date', 'created_at', 'created at' ),
		);

		$header = fgetcsv( $fh );
		$cols   = array();
		foreach ( (array) $header as $i => $h ) {
			$h = strtolower( trim( str_replace( "\xEF\xBB\xBF", '', (string) $h ) ) );
			foreach ( $aliases as $field => $names ) {
				if ( in_array( $h, $names, true ) && ! isset( $cols[ $field ] ) ) {
					$cols[ $field ] = $i;
				}
			}
		}

		$rows = array();

		if ( ! isset( $cols['email'] ) && ! isset( $cols['user_id'] ) ) {
			// No recognizable header. If the first cell is itself an email
			// address, treat the file as a headerless one-column list of
			// verified customers, first row included.
			$first = isset( $header[0] ) ? trim( str_replace( "\xEF\xBB\xBF", '', (string) $header[0] ) ) : '';
			if ( ! is_email( $first ) ) {
				fclose( $fh ); // phpcs:ignore
				wp_safe_redirect( add_query_arg( 'fs_status', 'idv_badformat', $back ) );
				exit;
			}
			$cols = array( 'email' => 0 );
			$rows[] = array( 'email' => $first );
		}

		$counts = array(
			'restored' => 0,
			'history'  => 0,
			'known'    => 0,
			'invalid'  => 0,
		);
		$read   = count( $rows );

		while ( $read < 2000 && false !== ( $line = fgetcsv( $fh ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( ! is_array( $line ) || ( 1 === count( $line ) && '' === trim( (string) $line[0] ) ) ) {
				continue;
			}
			$read++;
			$data = array();
			foreach ( $cols as $field => $idx ) {
				// Undo the spreadsheet formula guard our own export adds, so the
				// round trip is faithful ('-Maria Lopez stays -Maria Lopez).
				$data[ $field ] = isset( $line[ $idx ] ) ? sanitize_text_field( FS_Data::csv_unescape_cell( (string) $line[ $idx ] ) ) : '';
			}
			$rows[] = $data;
		}
		fclose( $fh ); // phpcs:ignore

		foreach ( $rows as $data ) {
			$outcome = FS_IDV::import_verification( $data );
			if ( isset( $counts[ $outcome ] ) ) {
				$counts[ $outcome ]++;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'fs_status'   => 'idv_imported',
					'fs_restored' => (int) $counts['restored'],
					'fs_history'  => (int) $counts['history'],
					'fs_known'    => (int) $counts['known'],
					'fs_invalid'  => (int) $counts['invalid'],
				),
				$back
			)
		);
		exit;
	}

	/**
	 * Send an ID-verification action back where it was clicked from.
	 *
	 * @param int    $uid    Account id (fallback destination).
	 * @param string $status Notice key.
	 */
	private function idv_redirect_back( $uid, $status ) {
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = (int) $uid > 0
				? add_query_arg(
					array(
						'page'        => self::SLUG . '-new-customers',
						'fs_customer' => (int) $uid,
					),
					admin_url( 'admin.php' )
				)
				: admin_url( 'admin.php?page=' . self::SLUG . '-idv' );
		}
		wp_safe_redirect( add_query_arg( array( 'fs_status' => $status ), $back ) );
		exit;
	}

	/**
	 * Set or clear a per-account gateway restriction (unblock a user but limit
	 * their payment methods, with an optional under-$X low-value allowance).
	 */
	public function handle_gw_restrict() {
		$this->guard();
		check_admin_referer( 'fs_gw_restrict' );

		$uid    = isset( $_REQUEST['user'] ) ? (int) $_REQUEST['user'] : 0;
		$clear  = ! empty( $_REQUEST['clear'] );
		$back   = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::SLUG . '-locks' );
		}
		if ( $uid <= 0 ) {
			wp_safe_redirect( $back );
			exit;
		}

		if ( $clear ) {
			delete_user_meta( $uid, '_fs_gw_restrict' );
			wp_safe_redirect( add_query_arg( array( 'fs_status' => 'gw_cleared' ), $back ) );
			exit;
		}

		$gateways = ( isset( $_POST['gateways'] ) && is_array( $_POST['gateways'] ) )
			? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['gateways'] ) ) ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();
		$low_gateways = ( isset( $_POST['low_gateways'] ) && is_array( $_POST['low_gateways'] ) )
			? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['low_gateways'] ) ) ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();
		$low_amount = isset( $_POST['low_amount'] ) ? max( 0, (float) $_POST['low_amount'] ) : 0;

		$this->apply_gw_restrict_to_user( $uid, $gateways, $low_amount, $low_gateways );

		wp_safe_redirect( add_query_arg( array( 'fs_status' => 'gw_saved' ), $back ) );
		exit;
	}

	/**
	 * Bulk "Restrict Checkout & Unblock": applies the submitted gateway set to
	 * every selected registered account, releasing each from blocks/holds onto
	 * restricted checkout.
	 */
	public function handle_bulk_gw_restrict() {
		$this->guard();
		check_admin_referer( 'fs_bulk_gw_restrict' );

		$uids = ( isset( $_POST['uids'] ) && is_array( $_POST['uids'] ) ) ? array_values( array_unique( array_filter( array_map( 'intval', wp_unslash( $_POST['uids'] ) ) ) ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$gateways = ( isset( $_POST['gateways'] ) && is_array( $_POST['gateways'] ) )
			? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['gateways'] ) ) ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();
		$low_gateways = ( isset( $_POST['low_gateways'] ) && is_array( $_POST['low_gateways'] ) )
			? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['low_gateways'] ) ) ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();
		$low_amount = isset( $_POST['low_amount'] ) ? max( 0, (float) $_POST['low_amount'] ) : 0;

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::SLUG . '-locks' );
		}

		// Without at least one allowed gateway the restriction would fail open —
		// refuse rather than silently un-restricting everyone selected.
		if ( empty( $uids ) || empty( $gateways ) ) {
			wp_safe_redirect( add_query_arg( array( 'fs_status' => 'bulk_gw_invalid' ), $back ) );
			exit;
		}

		foreach ( $uids as $uid ) {
			$this->apply_gw_restrict_to_user( $uid, $gateways, $low_amount, $low_gateways );
		}

		wp_safe_redirect( add_query_arg( array( 'fs_status' => 'bulk_restricted', 'fs_count' => count( $uids ) ), $back ) );
		exit;
	}

	/**
	 * Bulk-remove selected accounts from restricted checkout (clears their
	 * per-account gateway restriction; normal checkout rules apply again).
	 */
	public function handle_bulk_gw_clear() {
		$this->guard();
		check_admin_referer( 'fs_bulk_gw_clear' );

		$uids = ( isset( $_POST['uids'] ) && is_array( $_POST['uids'] ) ) ? array_values( array_unique( array_filter( array_map( 'intval', wp_unslash( $_POST['uids'] ) ) ) ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $uids as $uid ) {
			delete_user_meta( $uid, '_fs_gw_restrict' );
		}

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::SLUG . '-locks' );
		}
		wp_safe_redirect( add_query_arg( array( 'fs_status' => 'bulk_gw_cleared', 'fs_count' => count( $uids ) ), $back ) );
		exit;
	}

	/**
	 * Apply a per-account gateway restriction and release the customer to
	 * restricted checkout: the restriction itself exempts them from the velocity
	 * lock and hold (via FS_Live::is_exempt), so we deliberately do NOT mark the
	 * account Verified — a restricted customer is not a trusted one, and marking
	 * them verified would bypass the very restriction being imposed.
	 *
	 * Stamps applied_at so the restriction can lapse on its own after the
	 * configured window — restricted checkout is probation, not a life sentence.
	 * Re-saving an existing restriction restarts that clock, which is what staff
	 * changing someone's allowed methods means.
	 *
	 * @param int   $uid          User id.
	 * @param array $gateways     Allowed gateway ids.
	 * @param float $low_amount   Under-$X threshold (0 = off).
	 * @param array $low_gateways Gateways allowed only under the threshold.
	 */
	private function apply_gw_restrict_to_user( $uid, $gateways, $low_amount, $low_gateways ) {
		// One shared path with the API (v1.30): the record, the released hold
		// and block rows, and the withdrawn ID demand all live in FS_Live.
		FS_Live::apply_gw_restrict( (int) $uid, $gateways, $low_amount, $low_gateways );
	}

	/**
	 * Verified Customers page: the green "known-good" list (emails, addresses,
	 * IPs) that bypasses the velocity lock, holds, and gateway restrictions
	 * (never an explicit blocklist entry). Add manually or via the Verify buttons
	 * elsewhere; remove here.
	 */
	public function page_verified() {
		$this->guard();
		$creds = FS_Live::verified_creds();

		// Accounts carrying the Verified flag (includes accounts verified before
		// v1.13 synced account-verification onto the credential list).
		$verified_accounts = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_fs_verified',
						'value'   => 'yes',
						'compare' => '=',
					),
				),
				'number'     => 500,
				'fields'     => array( 'ID', 'user_email', 'user_registered', 'display_name' ),
			)
		);

		$groups = array(
			'email'   => array(),
			'address' => array(),
			'ip'      => array(),
		);
		foreach ( $creds as $key => $meta ) {
			$pos = strpos( $key, ':' );
			if ( false === $pos ) {
				continue;
			}
			$type = substr( $key, 0, $pos );
			$val  = substr( $key, $pos + 1 );
			if ( isset( $groups[ $type ] ) ) {
				$groups[ $type ][ $val ] = is_array( $meta ) ? $meta : array();
			}
		}
		$labels = array(
			'email'   => __( 'Verified emails', 'fraud-shield' ),
			'address' => __( 'Verified addresses', 'fraud-shield' ),
			'ip'      => __( 'Verified IPs', 'fraud-shield' ),
		);
		?>
		<div class="wrap fs-wrap">
			<h1><?php esc_html_e( 'Verified Customers', 'fraud-shield' ); ?></h1>
			<?php $this->maybe_notice(); ?>
			<p class="description"><?php esc_html_e( 'Known-good customers. A verified email, shipping address, or IP bypasses the velocity lock, the new-account hold, and gateway restrictions — so these customers always check out normally. It does NOT override an explicit blocklist or destination lock (an explicit block always wins). Verified profiles show green on the Profiles page.', 'fraud-shield' ); ?></p>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Add a verified credential', 'fraud-shield' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'fs_verify_cred' ); ?>
					<input type="hidden" name="action" value="fs_verify_cred" />
					<p class="fs-filters">
						<select name="type">
							<option value="email"><?php esc_html_e( 'Email', 'fraud-shield' ); ?></option>
							<option value="address"><?php esc_html_e( 'Address (text or hash)', 'fraud-shield' ); ?></option>
							<option value="ip"><?php esc_html_e( 'IP', 'fraud-shield' ); ?></option>
						</select>
						<input type="text" name="value" class="regular-text" placeholder="<?php esc_attr_e( 'Value to verify', 'fraud-shield' ); ?>" required />
						<input type="text" name="label" class="regular-text" placeholder="<?php esc_attr_e( 'Note (optional)', 'fraud-shield' ); ?>" />
						<button class="button button-primary"><?php esc_html_e( 'Add to verified', 'fraud-shield' ); ?></button>
					</p>
					<p class="description"><?php esc_html_e( 'Emails are normalized; an address is normalized and hashed (or paste a 64-char hash). To verify a registered account across everything, use "Verify" on their profile.', 'fraud-shield' ); ?></p>
				</form>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Transfer', 'fraud-shield' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Export everything on this page as one CSV: verified accounts (carried by email — the identity that survives a site move) and the verified emails, addresses, and IPs below, with their notes and dates. Importing MERGES — duplicates are skipped and nothing is removed. Accounts are matched by email and verified exactly as the Verify button does; an account email with no registered account on this site is added as a verified email instead, so the customer is recognized the moment they use it. A plain one-email-per-line list imports too. Addresses travel as their stored hashes, never as plain addresses.', 'fraud-shield' ); ?></p>
				<p class="fs-filters">
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fs_export_verified' ), 'fs_export_verified' ) ); ?>"><?php esc_html_e( 'Export CSV', 'fraud-shield' ); ?></a>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'fs_import_verified' ); ?>
					<input type="hidden" name="action" value="fs_import_verified" />
					<p class="fs-filters">
						<input type="file" name="fs_verified_csv" accept=".csv,text/csv,text/plain" required />
						<button class="button button-primary"><?php esc_html_e( 'Import CSV', 'fraud-shield' ); ?></button>
					</p>
				</form>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Verified accounts', 'fraud-shield' ); ?> <span class="fs-pill"><?php echo esc_html( number_format_i18n( count( $verified_accounts ) ) ); ?></span></h2>
				<p class="description"><?php esc_html_e( 'Registered accounts carrying the Verified flag. Verifying an account also verifies its email below; revoking removes both.', 'fraud-shield' ); ?></p>
				<?php if ( empty( $verified_accounts ) ) : ?>
					<p><?php esc_html_e( 'None yet.', 'fraud-shield' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat striped">
						<thead><tr><th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Email', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Registered', 'fraud-shield' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php
						foreach ( $verified_accounts as $va ) :
							$va_uid = (int) $va->ID;
							$va_url = add_query_arg( array( 'page' => self::SLUG . '-new-customers', 'fs_customer' => $va_uid ), admin_url( 'admin.php' ) );
							$va_rm  = wp_nonce_url( admin_url( 'admin-post.php?action=fs_verify_customer&to=0&user=' . $va_uid ), 'fs_verify_customer' );
							?>
							<tr class="fs-row-verified">
								<td><a href="<?php echo esc_url( $va_url ); ?>"><strong><?php echo esc_html( $va->display_name ? $va->display_name : ( '#' . $va_uid ) ); ?></strong></a></td>
								<td><?php echo esc_html( $va->user_email ); ?></td>
								<td><?php echo esc_html( mysql2date( 'Y-m-d', $va->user_registered ) ); ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( $va_rm ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Revoke this account\'s verification?', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Revoke', 'fraud-shield' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php foreach ( $labels as $type => $heading ) : ?>
				<div class="fs-panel">
					<h2><?php echo esc_html( $heading ); ?> <span class="fs-pill"><?php echo esc_html( number_format_i18n( count( $groups[ $type ] ) ) ); ?></span></h2>
					<?php if ( empty( $groups[ $type ] ) ) : ?>
						<p><?php esc_html_e( 'None yet.', 'fraud-shield' ); ?></p>
					<?php else : ?>
						<table class="wp-list-table widefat striped">
							<thead><tr><th><?php esc_html_e( 'Value', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Note', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Added', 'fraud-shield' ); ?></th><th></th></tr></thead>
							<tbody>
							<?php foreach ( $groups[ $type ] as $val => $meta ) : ?>
								<?php $rm = wp_nonce_url( admin_url( 'admin-post.php?action=fs_unverify_cred&type=' . rawurlencode( $type ) . '&value=' . rawurlencode( $val ) ), 'fs_unverify_cred' ); ?>
								<tr class="fs-row-verified">
									<td><?php echo esc_html( 'address' === $type ? substr( $val, 0, 16 ) . '…' : $val ); ?></td>
									<td><?php echo esc_html( isset( $meta['label'] ) ? $meta['label'] : '' ); ?></td>
									<td><?php echo esc_html( isset( $meta['at'] ) ? $meta['at'] : '' ); ?></td>
									<td><a class="button button-small" href="<?php echo esc_url( $rm ); ?>"><?php esc_html_e( 'Remove', 'fraud-shield' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Card Checks: the issuer's AVS/CVV verdict on each card payment, pulled
	 * from NMI after the order completes.
	 *
	 * The ID Verifications page answers "who proved who they are"; this one
	 * answers "whose card was it" — and the Cardholder mismatch filter is the
	 * intersection that matters: verified people paying with cards the issuer
	 * says are not theirs.
	 */
	public function page_card_checks() {
		$this->guard();

		$s       = FS()->settings();
		$rows    = FS_Cardholder::log_rows();
		$labels  = FS_Cardholder::outcome_labels();
		$filter  = isset( $_GET['outcome'] ) ? sanitize_key( wp_unslash( $_GET['outcome'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		// Counts for the filter row, before filtering.
		$counts = array(
			''         => count( $rows ),
			'fail'     => 0,
			'mismatch' => 0,
			'pass'     => 0,
			'partial'  => 0,
			'other'    => 0,
		);
		foreach ( $rows as $r ) {
			$o = (string) ( $r['outcome'] ?? '' );
			if ( 'mismatch' === ( $r['ch'] ?? '' ) ) {
				$counts['mismatch']++;
			}
			if ( isset( $counts[ $o ] ) && '' !== $o ) {
				$counts[ $o ]++;
			} elseif ( ! in_array( $o, array( 'pass', 'partial', 'fail' ), true ) ) {
				$counts['other']++;
			}
		}

		if ( '' !== $filter ) {
			$rows = array_values(
				array_filter(
					$rows,
					function ( $r ) use ( $filter ) {
						if ( 'mismatch' === $filter ) {
							return 'mismatch' === ( $r['ch'] ?? '' );
						}
						if ( 'other' === $filter ) {
							return ! in_array( (string) ( $r['outcome'] ?? '' ), array( 'pass', 'partial', 'fail' ), true );
						}
						return ( $r['outcome'] ?? '' ) === $filter;
					}
				)
			);
		}

		$base = admin_url( 'admin.php?page=' . self::SLUG . '-cards' );

		// Build order-edit links without loading order objects (up to 200 rows).
		$hpos = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();
		?>
		<div class="wrap fs-wrap">
			<h1><?php esc_html_e( 'Card Checks', 'fraud-shield' ); ?></h1>
			<p class="description"><?php esc_html_e( 'The issuer\'s verdict on each card payment, pulled from NMI after the order: did the billing address match the cardholder\'s bank records (AVS), and was the security code right (CVV). Combined with ID verification it answers the real question — is the verified person also the cardholder? A Cardholder MISMATCH row is an ID-verified customer paying with a card the issuer says is not theirs: the classic stolen-card-with-real-ID pattern.', 'fraud-shield' ); ?></p>

			<?php if ( empty( $s['card_check_enabled'] ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: settings page URL */
							__( 'The cardholder check is currently disabled. Enable it and add your NMI security keys under <a href="%s">Settings → Cardholder check (NMI)</a>.', 'fraud-shield' ),
							esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) )
						)
					);
					?>
				</p></div>
			<?php endif; ?>

			<p class="fs-filters">
				<?php
				$filter_links = array(
					''         => __( 'All', 'fraud-shield' ),
					'mismatch' => __( 'Cardholder mismatches', 'fraud-shield' ),
					'fail'     => __( 'Failures', 'fraud-shield' ),
					'partial'  => __( 'Partial matches', 'fraud-shield' ),
					'pass'     => __( 'Passes', 'fraud-shield' ),
					'other'    => __( 'No verdict / errors', 'fraud-shield' ),
				);
				$parts = array();
				foreach ( $filter_links as $key => $label ) {
					$url     = '' === $key ? $base : add_query_arg( 'outcome', $key, $base );
					$current = ( $filter === $key ) ? ' style="font-weight:600;text-decoration:none;"' : '';
					$parts[] = '<a href="' . esc_url( $url ) . '"' . $current . '>' . esc_html( $label ) . ' (' . (int) $counts[ $key ] . ')</a>';
				}
				echo wp_kses_post( implode( ' | ', $parts ) );
				?>
			</p>

			<table class="wp-list-table widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Order', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Gateway', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Card', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'AVS', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'CVV', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( '3DS', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Outcome', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Cardholder', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'NMI account', 'fraud-shield' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="11">
						<?php
						if ( '' !== $filter ) {
							esc_html_e( 'No checks match this filter.', 'fraud-shield' );
						} else {
							esc_html_e( 'No card checks yet. Once enabled, each card payment is checked shortly after it completes. To try it immediately, open a recent NMI order and press "Run card check now" in its Card Check box.', 'fraud-shield' );
						}
						?>
					</td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<?php
						$order_id = (int) ( $r['order'] ?? 0 );
						$email    = (string) ( $r['email'] ?? '' );
						$outcome  = (string) ( $r['outcome'] ?? '' );
						$ch       = (string) ( $r['ch'] ?? '' );
						$card     = trim( ( $r['brand'] ?? '' ) . ( '' !== ( $r['last4'] ?? '' ) ? ' •••• ' . $r['last4'] : '' ) );
						$edit     = $hpos
							? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id )
							: admin_url( 'post.php?post=' . $order_id . '&action=edit' );
						?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $r['t'] ?? 0 ) ) ); ?></td>
							<td><?php echo $order_id ? '<a href="' . esc_url( $edit ) . '">#' . (int) $order_id . '</a>' : '—'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo '' !== $email ? '<a href="' . esc_url( $this->profile_url( $email ) ) . '">' . esc_html( $email ) . '</a>' : '—'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo esc_html( (string) ( $r['gw'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( '' !== $card ? $card : '—' ); ?></td>
							<td title="<?php echo esc_attr( FS_Cardholder::avs_meaning( $r['avs'] ?? '' ) ); ?>"><?php echo esc_html( '' !== ( $r['avs'] ?? '' ) ? $r['avs'] : '—' ); ?></td>
							<td title="<?php echo esc_attr( FS_Cardholder::csc_meaning( $r['csc'] ?? '' ) ); ?>"><?php echo esc_html( '' !== ( $r['csc'] ?? '' ) ? $r['csc'] : '—' ); ?></td>
							<td><?php echo esc_html( '' !== ( $r['tds'] ?? '' ) ? $r['tds'] : '—' ); ?></td>
							<td><span class="<?php echo esc_attr( FS_Cardholder::outcome_pill( $outcome ) ); ?>"><?php echo esc_html( $labels[ $outcome ] ?? $outcome ); ?></span></td>
							<td>
								<?php
								if ( 'confirmed' === $ch ) {
									echo '<span class="fs-pill fs-pill-green">' . esc_html__( 'Confirmed', 'fraud-shield' ) . '</span>';
								} elseif ( 'mismatch' === $ch ) {
									echo '<span class="fs-pill fs-pill-red">' . esc_html__( 'MISMATCH', 'fraud-shield' ) . '</span>';
								} elseif ( ! empty( $r['idv'] ) ) {
									echo '<span class="fs-muted">' . esc_html__( 'ID-verified', 'fraud-shield' ) . '</span>';
								} else {
									echo '<span class="fs-muted">—</span>';
								}
								?>
							</td>
							<td><?php echo esc_html( '' !== ( $r['key'] ?? '' ) ? $r['key'] : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<p class="description">
				<?php
				printf(
					/* translators: %d: log cap */
					esc_html__( 'This list keeps the most recent %d checks. Every result also lives permanently on its order — the Card Check box and an order note.', 'fraud-shield' ),
					(int) FS_Cardholder::LOG_CAP
				);
				?>
				<?php esc_html_e( 'Hover an AVS or CVV code for its meaning.', 'fraud-shield' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * ID Verifications: every customer who has been through the Didit flow,
	 * whether they passed or not.
	 *
	 * The Verified Customers page answers "who is trusted"; this one answers
	 * "who tried, when, and what happened" — including the failures, which are
	 * the rows worth reading.
	 */
	public function page_idv() {
		$this->guard();

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$result = isset( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$sort   = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'updated'; // phpcs:ignore WordPress.Security.NonceVerification
		$dir    = isset( $_GET['dir'] ) && 'asc' === $_GET['dir'] ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$per    = 50;

		list( $sort2, $dir2 ) = $this->sort2_from_request();
		$this->cur_sort2      = $sort2;
		$this->cur_dir2       = $dir2;

		$total = FS_Data::idv_sessions_count( $search, $result );
		$rows  = FS_Data::idv_sessions_list(
			array(
				'orderby'  => $sort,
				'dir'      => $dir,
				'orderby2' => $sort2,
				'dir2'     => $dir2,
				'search'   => $search,
				'result'   => $result,
				'limit'    => $per,
				'offset'   => ( $paged - 1 ) * $per,
			)
		);
		$pages = (int) ceil( $total / $per );
		$stats   = FS_Data::idv_stats();
		$queue   = FS_IDV::pending_requirements( 200 );
		$q_tries = FS_Data::idv_attempt_counts( wp_list_pluck( $queue, 'ID' ) );

		$base_args = array(
			'page'   => self::SLUG . '-idv',
			's'      => $search,
			'result' => $result,
			'sort2'  => $sort2,
			'dir2'   => $dir2,
		);

		$filters = array(
			''              => __( 'All', 'fraud-shield' ),
			'approved'      => __( 'Verified', 'fraud-shield' ),
			'name_mismatch' => __( 'Name mismatch', 'fraud-shield' ),
			'declined'      => __( 'Declined', 'fraud-shield' ),
			'pending'       => __( 'In progress', 'fraud-shield' ),
			'expired'       => __( 'Not completed', 'fraud-shield' ),
		);
		?>
		<div class="wrap fs-wrap">
			<h1><?php esc_html_e( 'ID Verifications', 'fraud-shield' ); ?></h1>
			<?php $this->maybe_notice(); ?>

			<?php if ( ! FS_IDV::enabled() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'ID verification is switched off, so no new checks can run. Anything below is history.', 'fraud-shield' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ); ?>"><?php esc_html_e( 'Open settings', 'fraud-shield' ); ?></a>
				</p></div>
			<?php elseif ( ! FS_IDV::configured() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'ID verification is enabled but missing its API key or workflow ID, so checks cannot run and any outstanding ID request is being ignored rather than stranding the customer.', 'fraud-shield' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ); ?>"><?php esc_html_e( 'Finish setup', 'fraud-shield' ); ?></a>
				</p></div>
			<?php endif; ?>

			<p class="description"><?php esc_html_e( 'Every customer who has been through the Didit flow. A completed check makes them a verified credential — exempt from the velocity lock, new-account holds and gateway restrictions — but never exempt from the blocklist.', 'fraud-shield' ); ?></p>

			<div class="fs-cards">
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['people'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Customers', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['approved'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Verified', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-red"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['name_mismatch'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Name mismatch', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-red"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['declined'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Declined', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-amber"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['pending'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'In progress', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['expired'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Not completed', 'fraud-shield' ); ?></div></div>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Restore and sync', 'fraud-shield' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Didit keeps every verification session this store ever started, and each one carries the customer it belonged to. If this install is fresh (after an uninstall, a migration, or a lost database), pull them back from Didit instead of re-entering anything. Both tools are safe to run repeatedly: sessions that already exist here are skipped, and restored verifications keep their original dates, so expiry and the high-value freshness rules keep counting from the day the customer really verified.', 'fraud-shield' ); ?></p>

				<h3><?php esc_html_e( 'Sync from Didit', 'fraud-shield' ); ?></h3>
				<?php if ( FS_IDV::configured() ) : ?>
					<p class="description"><?php esc_html_e( 'Fetches your sessions from the Didit API, matches each one back to its customer, and rebuilds their verification with the original date — the whole backlog in one go (the request runs for up to a few minutes on large accounts). Only if the notice says sessions remain — a very large backlog on a slow host, or a mid-run API failure — does it need another click, and it continues where it stopped. Sessions that belong to a different store sharing the same Didit account are recognized and left alone.', 'fraud-shield' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 8px;">
						<?php wp_nonce_field( 'fs_idv_sync' ); ?>
						<input type="hidden" name="action" value="fs_idv_sync" />
						<button class="button button-primary"><?php esc_html_e( 'Sync from Didit now', 'fraud-shield' ); ?></button>
					</form>

					<h3><?php esc_html_e( 'Rebuild attributions', 'fraud-shield' ); ?></h3>
					<p class="description"><?php esc_html_e( 'If verifications on this page are attached to the wrong customers (a document showing under someone else\'s name — the signature of two stores sharing one Didit account), rebuild from scratch: every finished session row is cleared, every verification EARNED BY an ID check is withdrawn, and the sync re-imports only the sessions that provably belong to this store, re-granting verifications to their real owners with their original dates — all in one click; the request runs for up to a few minutes on large accounts. Staff decisions survive untouched: accepted mismatches, the Verified account flag, and hand-added credentials. In-flight checks from the last 24 hours are kept so nobody loses a session mid-verification. Only if the notice says sessions remain does it need a follow-up: press Sync from Didit (not Rebuild), which continues where the rebuild stopped.', 'fraud-shield' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 8px;" onsubmit="return window.confirm('<?php echo esc_js( __( 'Clear all ID-verification rows and Didit-earned verifications, then rebuild from Didit? Staff decisions are kept. This cannot be undone.', 'fraud-shield' ) ); ?>');">
						<?php wp_nonce_field( 'fs_idv_rebuild' ); ?>
						<input type="hidden" name="action" value="fs_idv_rebuild" />
						<button class="button"><?php esc_html_e( 'Rebuild attributions from Didit', 'fraud-shield' ); ?></button>
					</form>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Needs the Didit API key and workflow ID under Settings, ID verification (Didit). Once those are saved, the sync button appears here.', 'fraud-shield' ); ?></p>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Import from a CSV', 'fraud-shield' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Accepts the CSV this page exports (a full round trip: export before a migration, import after), or any file with an "email" column, down to a plain one-email-per-line list. Optional columns: user_id, session_id, outcome, document_name, name_match, document_type, and started (the verification date; without it, today is used).', 'fraud-shield' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'fs_idv_import' ); ?>
					<input type="hidden" name="action" value="fs_idv_import" />
					<p class="fs-filters">
						<input type="file" name="fs_idv_csv" accept=".csv,text/csv" required />
						<button class="button"><?php esc_html_e( 'Import verifications', 'fraud-shield' ); ?></button>
					</p>
				</form>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Awaiting a requested ID', 'fraud-shield' ); ?> <span class="fs-pill"><?php echo esc_html( number_format_i18n( count( $queue ) ) ); ?></span></h2>
				<?php if ( empty( $queue ) ) : ?>
					<p><?php esc_html_e( 'Nobody has been asked to verify. Use “Block checkout & request ID” on a customer profile to demand one.', 'fraud-shield' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'These customers cannot check out until they complete a verification. They see your ID-requested message with a verify button, so no staff action is needed unless you want to withdraw the request.', 'fraud-shield' ); ?></p>
					<table class="wp-list-table widefat striped">
						<thead><tr>
							<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Email', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Requested', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Attempts so far', 'fraud-shield' ); ?></th>
							<th></th>
						</tr></thead>
						<tbody>
						<?php
						foreach ( $queue as $qu ) :
							$q_uid   = (int) $qu->ID;
							$q_email = FS_Normalizer::email( $qu->user_email );
							$q_rec   = FS_IDV::requirement_record( $q_uid );
							$q_count = isset( $q_tries[ $q_uid ] ) ? (int) $q_tries[ $q_uid ] : 0;
							$q_clear = wp_nonce_url( admin_url( 'admin-post.php?action=fs_idv_require&to=0&user=' . $q_uid ), 'fs_idv_require' );
							?>
							<tr class="fs-row-flag">
								<td><a href="<?php echo esc_url( $this->profile_url( $q_email ) ); ?>"><strong><?php echo esc_html( $qu->display_name ? $qu->display_name : ( '#' . $q_uid ) ); ?></strong></a></td>
								<td><?php echo esc_html( $qu->user_email ); ?></td>
								<td><?php echo esc_html( ! empty( $q_rec['at'] ) ? mysql2date( 'Y-m-d H:i', (string) $q_rec['at'] ) : '—' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $q_count ) ); ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( $q_clear ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Cancel the ID request and unblock checkout for this customer?', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Cancel request', 'fraud-shield' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Verification attempts', 'fraud-shield' ); ?> <span class="fs-pill"><?php echo esc_html( number_format_i18n( $total ) ); ?></span></h2>

				<p class="fs-filters">
					<?php foreach ( $filters as $fkey => $flabel ) : ?>
						<?php
						$furl = add_query_arg(
							array_merge(
								$base_args,
								array(
									'result' => $fkey,
									'sort'   => $sort,
									'dir'    => $dir,
								)
							),
							admin_url( 'admin.php' )
						);
						?>
						<a class="button<?php echo $result === $fkey ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $furl ); ?>"><?php echo esc_html( $flabel ); ?></a>
					<?php endforeach; ?>
				</p>

				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-idv' ); ?>" />
					<input type="hidden" name="result" value="<?php echo esc_attr( $result ); ?>" />
					<p class="fs-filters">
						<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email, document name, session id…', 'fraud-shield' ); ?>" class="regular-text" />
						<button class="button"><?php esc_html_e( 'Search', 'fraud-shield' ); ?></button>
						<a class="button" href="<?php echo esc_url( $this->export_url( 'idv', array( 's' => $search, 'result' => $result, 'sort' => $sort, 'dir' => $dir ) ) ); ?>"><?php esc_html_e( 'Export CSV (Excel)', 'fraud-shield' ); ?></a>
					</p>
				</form>

				<table class="wp-list-table widefat striped">
					<thead><tr>
						<?php
						$this->sort_th( __( 'Customer', 'fraud-shield' ), 'email', $sort, $dir, $base_args );
						$this->sort_th( __( 'Outcome', 'fraud-shield' ), 'result', $sort, $dir, $base_args );
						$this->sort_th( __( 'Name on document', 'fraud-shield' ), 'name', $sort, $dir, $base_args );
						$this->sort_th( __( 'Expected name', 'fraud-shield' ), 'expected', $sort, $dir, $base_args );
						$this->sort_th( __( 'Document', 'fraud-shield' ), 'doc', $sort, $dir, $base_args );
						?>
						<th><?php esc_html_e( 'Verified from', 'fraud-shield' ); ?></th>
						<?php
						$this->sort_th( __( 'Started', 'fraud-shield' ), 'created', $sort, $dir, $base_args );
						$this->sort_th( __( 'Last update', 'fraud-shield' ), 'updated', $sort, $dir, $base_args );
						?>
						<th><?php esc_html_e( 'Order', 'fraud-shield' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="10"><?php esc_html_e( 'No verification attempts match. Once a customer runs the Didit flow they appear here, passed or failed.', 'fraud-shield' ); ?></td></tr>
					<?php endif; ?>
					<?php
					foreach ( $rows as $r ) :
						$r_uid      = (int) $r['user_id'];
						$r_email    = (string) $r['email_norm'];
						$r_verified = FS_IDV::is_verified( $r_uid, $r_email );
						$row_class  = 'approved' === (string) $r['result']
							? ' class="fs-row-verified"'
							: ( in_array( (string) $r['result'], array( 'name_mismatch', 'declined' ), true ) ? ' class="fs-row-flag"' : '' );
						?>
						<tr<?php echo $row_class; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- fixed literal. ?>>
							<td>
								<?php if ( '' !== $r_email ) : ?>
									<a href="<?php echo esc_url( $this->profile_url( $r_email ) ); ?>"><strong><?php echo esc_html( $r_email ); ?></strong></a>
								<?php else : ?>
									<span class="fs-muted"><?php esc_html_e( '(no email recorded)', 'fraud-shield' ); ?></span>
								<?php endif; ?>
								<?php if ( $r_uid > 0 ) : ?>
									<br/><span class="fs-muted"><?php echo esc_html( sprintf( /* translators: %d: account id */ __( 'account #%d', 'fraud-shield' ), $r_uid ) ); ?></span>
								<?php else : ?>
									<br/><span class="fs-muted"><?php esc_html_e( 'no account', 'fraud-shield' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php echo $this->idv_result_pill( $r ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped ?>
								<?php if ( 'approved' === (string) $r['result'] && ! $r_verified ) : ?>
									<br/><span class="fs-muted"><?php esc_html_e( 'no longer in force (expired or revoked)', 'fraud-shield' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( '' !== (string) $r['full_name'] ? (string) $r['full_name'] : '-' ); ?></td>
							<td><?php echo esc_html( '' !== (string) $r['expected_name'] ? (string) $r['expected_name'] : '-' ); ?></td>
							<td>
								<?php
								$fs_doc = trim( (string) $r['doc_type'] );
								$fs_org = trim( (string) ( $r['doc_state'] ?? '' ) . ( '' !== (string) ( $r['doc_country'] ?? '' ) ? ( '' !== (string) ( $r['doc_state'] ?? '' ) ? ', ' : '' ) . (string) $r['doc_country'] : '' ) );
								echo esc_html( '' !== $fs_doc ? $fs_doc : '-' );
								if ( '' !== $fs_org ) {
									echo '<br/><span class="fs-muted">' . esc_html( $fs_org ) . '</span>';
								}
								?>
							</td>
							<td>
								<?php
								$fs_from = trim( (string) ( $r['verify_ip_state'] ?? '' ) . ( '' !== (string) ( $r['verify_ip_country'] ?? '' ) ? ( '' !== (string) ( $r['verify_ip_state'] ?? '' ) ? ', ' : '' ) . (string) $r['verify_ip_country'] : '' ) );
								echo esc_html( '' !== $fs_from ? $fs_from : '-' );
								if ( ! empty( $r['verify_vpn'] ) ) {
									echo '<br/><span class="fs-bad">' . esc_html__( 'VPN or Tor', 'fraud-shield' ) . '</span>';
								}
								?>
							</td>
							<td><?php echo esc_html( $r['created_at'] ? mysql2date( 'Y-m-d H:i', (string) $r['created_at'] ) : '-' ); ?></td>
							<td><?php echo esc_html( $r['updated_at'] ? mysql2date( 'Y-m-d H:i', (string) $r['updated_at'] ) : '-' ); ?></td>
							<td>
								<?php if ( (int) $r['order_id'] > 0 ) : ?>
									<a href="<?php echo esc_url( $this->order_edit_url( (int) $r['order_id'] ) ); ?>">#<?php echo (int) $r['order_id']; ?></a>
								<?php else : ?>
									-
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 'name_mismatch' === (string) $r['result'] ) : ?>
									<?php echo $this->idv_action_link( 'accept', $r_uid, $r_email, (int) $r['id'], __( 'Accept', 'fraud-shield' ), 'button-primary' ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped ?>
								<?php elseif ( $r_verified && 'approved' === (string) $r['result'] ) : ?>
									<?php echo $this->idv_action_link( 'revoke', $r_uid, $r_email, 0, __( 'Revoke', 'fraud-shield' ) ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg(
									array_merge(
										$base_args,
										array(
											'sort' => $sort,
											'dir'  => $dir,
										)
									),
									admin_url( 'admin.php' )
								) . '%_%',
								'format'    => '&paged=%#%',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
					</div></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* ===================== Financial Protection (v1.26) ===================== */

	/**
	 * A confidence grade as a pill.
	 *
	 * @param string $grade high|medium|low|none.
	 * @return string HTML.
	 */
	private function confidence_pill( $grade ) {
		$map = array(
			'high'   => array( 'fs-pill-green', __( 'High confidence', 'fraud-shield' ) ),
			'medium' => array( 'fs-pill-amber', __( 'Medium confidence', 'fraud-shield' ) ),
			'low'    => array( 'fs-pill-red', __( 'Low confidence', 'fraud-shield' ) ),
			'none'   => array( '', __( 'Not estimable', 'fraud-shield' ) ),
		);
		$m   = $map[ $grade ] ?? $map['none'];
		return '<span class="fs-pill ' . esc_attr( $m[0] ) . '">' . esc_html( $m[1] ) . '</span>';
	}

	/**
	 * A money figure, or an explicit "unavailable" when the store has not
	 * recorded what the figure needs. Never zero for missing data.
	 *
	 * @param float|null $v        Amount or null.
	 * @param string     $currency Currency code.
	 * @param string     $why      Why it is unavailable (title attribute).
	 * @return string HTML.
	 */
	private function money_or_na( $v, $currency, $why = '' ) {
		if ( null === $v ) {
			return '<span class="fs-muted fs-na" title="' . esc_attr( $why ) . '">' . esc_html__( 'unavailable', 'fraud-shield' ) . '</span>';
		}
		return wp_kses_post( wc_price( (float) $v, '' !== $currency ? array( 'currency' => $currency ) : array() ) );
	}

	/**
	 * A customer identity cell: name, email (linked to the network view) and
	 * account, from a row carrying customer_name / email_norm / user_id.
	 *
	 * @param array $r Row.
	 * @return string HTML.
	 */
	private function identity_cell( $r ) {
		$email = (string) ( $r['email_norm'] ?? '' );
		$uid   = (int) ( $r['user_id'] ?? 0 );
		$name  = trim( (string) ( $r['customer_name'] ?? '' ) );
		$out   = '';
		if ( '' !== $email ) {
			$out .= '<a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG . '-network', 'fs_network' => rawurlencode( $email ) ), admin_url( 'admin.php' ) ) ) . '"><strong>' . esc_html( '' !== $name ? $name : $email ) . '</strong></a>';
			if ( '' !== $name ) {
				$out .= '<br/><span class="fs-muted">' . esc_html( $email ) . '</span>';
			}
		} else {
			$out .= '<strong>' . esc_html( '' !== $name ? $name : __( '(no email)', 'fraud-shield' ) ) . '</strong>';
		}
		if ( $uid > 0 ) {
			$out .= '<br/><a class="fs-muted" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG . '-new-customers', 'fs_customer' => $uid ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( sprintf( /* translators: %d: account id */ __( 'account #%d', 'fraud-shield' ), $uid ) ) . '</a>';
		}
		return $out;
	}

	/**
	 * Financial Protection: four measurements, kept apart — recorded losses,
	 * the value of stopped checkouts, the estimated loss avoided on them, and
	 * the projected yearly cost of the customers who are kept blocked.
	 */
	public function page_protection() {
		$this->guard();
		$days = isset( $_GET['fs_period'] ) ? (int) $_GET['fs_period'] : 365; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $days, array( 365, 730, 0 ), true ) ) {
			$days = 365;
		}
		$period   = FS_Insights::period( $days );
		$a        = FS_Insights::assumptions();
		$cov      = FS_Insights::coverage();
		$losses   = FS_Insights::losses( $period );
		$top_loss = FS_Insights::loss_breakdown( $period, 15 );
		$bv       = FS_Insights::blocked_value( $period );
		$est      = FS_Insights::prevented_estimate( $period, $bv );
		$proj     = FS_Insights::projection();
		$cur      = $a['currency'];
		$kinds    = FS_Data::prevented_kinds();
		$base     = admin_url( 'admin.php?page=' . self::SLUG . '-protection' );
		$periods  = array(
			365 => __( 'Last 12 months', 'fraud-shield' ),
			730 => __( 'Last 24 months', 'fraud-shield' ),
			0   => __( 'All time', 'fraud-shield' ),
		);
		$pct      = function ( $v ) {
			return null === $v ? '<span class="fs-muted">' . esc_html__( 'n/a', 'fraud-shield' ) . '</span>' : esc_html( number_format_i18n( 100 * (float) $v, 2 ) . '%' );
		};
		$date     = function ( $d ) {
			return '' !== (string) $d && null !== $d ? mysql2date( 'Y-m-d', (string) $d ) : '—';
		};
		$partial  = null !== $cov['first_order'] && '' !== $period['from'] && $cov['first_order'] > $period['from'];
		$fee_why  = __( 'Set your processor\'s chargeback fee under Settings → Financial Protection assumptions.', 'fraud-shield' );
		?>
		<div class="wrap fs-wrap fs-protection">
			<h1><?php esc_html_e( 'Financial Protection', 'fraud-shield' ); ?></h1>
			<?php $this->maybe_notice(); ?>
			<p class="description"><?php esc_html_e( 'What fraud has cost this store, what Fraud Shield has stopped, and what keeping the identified customers blocked is likely to be worth. Four measurements, kept apart on purpose: recorded losses are facts; the value of stopped checkouts is a fact but not a saving; losses prevented is an estimate calibrated on this store\'s own outcomes; annual protection is a forecast that assumes past behaviour would have continued. Anything the store has not recorded is shown as unavailable, not as zero.', 'fraud-shield' ); ?></p>

			<p class="fs-filters">
				<label><?php esc_html_e( 'Reporting period', 'fraud-shield' ); ?></label>
				<?php foreach ( $periods as $d => $label ) : ?>
					<a class="button<?php echo $days === $d ? ' button-primary' : ''; ?>" href="<?php echo esc_url( 0 === $d ? add_query_arg( 'fs_period', 0, $base ) : add_query_arg( 'fs_period', $d, $base ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
				<span class="fs-muted">
					<?php
					if ( null === $cov['first_order'] ) {
						esc_html_e( 'The order index is empty — run a scan from the Dashboard first.', 'fraud-shield' );
					} else {
						echo esc_html( sprintf( /* translators: 1: number of orders, 2: first order date, 3: last order date */ __( 'Index covers %1$s orders from %2$s to %3$s.', 'fraud-shield' ), number_format_i18n( $cov['orders'] ), $date( $cov['first_order'] ), $date( $cov['last_order'] ) ) );
					}
					?>
				</span>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ); ?>"><?php esc_html_e( 'Assumptions (Settings)', 'fraud-shield' ); ?></a>
			</p>

			<?php if ( $partial ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: 1: period label, 2: first indexed order date */ __( 'Data limitation: the index only reaches back to %2$s, so "%1$s" is partially covered. Observed figures describe the indexed part of the period; run a full scan from the Dashboard to complete it.', 'fraud-shield' ), $period['label'], $date( $cov['first_order'] ) ) ); ?></p></div>
			<?php endif; ?>

			<!-- ============ 1. Historical losses (observed) ============ -->
			<div class="fs-panel">
				<h2><?php esc_html_e( '1. Historical losses', 'fraud-shield' ); ?> <span class="fs-pill"><?php esc_html_e( 'OBSERVED', 'fraud-shield' ); ?></span> <span class="fs-muted fs-panel-period"><?php echo esc_html( $period['label'] ); ?></span></h2>
				<p class="description"><?php esc_html_e( 'Orders recorded as charged back (the Charged Back status or the "Mark as chargeback" action), at the order total that was reversed. Recoveries are the amounts staff recorded on those orders with "Record recovery" — a dispute won, a partial reversal. Fees are your processor\'s per-dispute charge, counted only when you have entered it.', 'fraud-shield' ); ?></p>
				<div class="fs-cards">
					<div class="fs-card fs-card-red"><div class="fs-num"><?php echo $this->money_or_na( $losses['gross'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php echo esc_html( sprintf( /* translators: %s: number of orders */ _n( 'Charged back (%s order)', 'Charged back (%s orders)', $losses['cb_orders'], 'fraud-shield' ), number_format_i18n( $losses['cb_orders'] ) ) ); ?></div></div>
					<div class="fs-card"><div class="fs-num"><?php echo $this->money_or_na( $losses['fees'], $cur, $fee_why ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Chargeback fees', 'fraud-shield' ); ?></div></div>
					<div class="fs-card fs-card-green"><div class="fs-num"><?php echo $losses['recovered_orders'] > 0 ? $this->money_or_na( $losses['recovered'], $cur ) : '<span class="fs-muted fs-na">' . esc_html__( 'none recorded', 'fraud-shield' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php echo esc_html( sprintf( /* translators: %s: number of orders */ __( 'Recovered (%s orders)', 'fraud-shield' ), number_format_i18n( $losses['recovered_orders'] ) ) ); ?></div></div>
					<div class="fs-card fs-card-red"><div class="fs-num"><?php echo $this->money_or_na( $losses['net'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php echo $losses['net_has_fees'] ? esc_html__( 'Net loss (incl. fees)', 'fraud-shield' ) : esc_html__( 'Net loss (fees not configured)', 'fraud-shield' ); ?></div></div>
					<div class="fs-card"><div class="fs-num"><?php echo $pct( $losses['rate_orders'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Chargeback rate (orders)', 'fraud-shield' ); ?></div></div>
					<div class="fs-card"><div class="fs-num"><?php echo $pct( $losses['rate_amount'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Chargeback rate (value)', 'fraud-shield' ); ?></div></div>
				</div>
				<p class="description">
					<?php
					echo esc_html( sprintf( /* translators: 1: number of identities, 2: number of paid orders, 3: paid amount */ __( '%1$s customer identities charged back. Rates are against %2$s paid orders (%3$s) plus the charged-back ones.', 'fraud-shield' ), number_format_i18n( $losses['identities'] ), number_format_i18n( $losses['paid_orders'] ), html_entity_decode( wp_strip_all_tags( wc_price( $losses['paid_amount'], array( 'currency' => $cur ) ) ) ) ) );
					if ( $losses['counts_refunds'] ) {
						echo ' ' . esc_html( sprintf( /* translators: %s: number of refunded orders */ __( 'Includes %s refunded orders, because "Treat Refunded as chargeback" is on.', 'fraud-shield' ), number_format_i18n( $losses['refunded_as_cb'] ) ) );
					}
					?>
				</p>
				<?php if ( ! empty( $top_loss ) ) : ?>
					<h3 class="fs-savings-sub"><?php esc_html_e( 'By customer', 'fraud-shield' ); ?></h3>
					<table class="wp-list-table widefat striped fs-savings-table">
						<thead><tr>
							<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Charged back', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Recovered', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'First / last chargeback', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Status', 'fraud-shield' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $top_loss as $r ) : ?>
							<tr>
								<td><?php echo $this->identity_cell( $r ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $r['cb_orders'] ) ); ?></td>
								<td><?php echo $this->money_or_na( (float) $r['gross'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo (float) $r['recovered'] > 0 ? $this->money_or_na( (float) $r['recovered'], $cur ) : '<span class="fs-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo esc_html( $date( $r['first_cb'] ) . ' → ' . $date( $r['last_cb'] ) ); ?></td>
								<td><?php echo ! empty( $r['blocked'] ) ? '<span class="fs-pill fs-pill-black">' . esc_html__( 'Blocked', 'fraud-shield' ) . '</span>' : '<span class="fs-pill fs-pill-amber">' . esc_html__( 'Not blocked', 'fraud-shield' ) . '</span>'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- ============ 2. Blocked transaction value (observed) ============ -->
			<div class="fs-panel">
				<h2><?php esc_html_e( '2. Blocked transaction value', 'fraud-shield' ); ?> <span class="fs-pill"><?php esc_html_e( 'OBSERVED', 'fraud-shield' ); ?></span> <span class="fs-muted fs-panel-period"><?php echo esc_html( $period['label'] ); ?></span></h2>
				<p class="description"><?php esc_html_e( 'The value of the purchase attempts a verdict stopped — the blocklist, the linked-to-blocklist rule, and a block-level risk score under Enforce — at the total the customer would have paid: orders cancelled the moment they were placed, attempts refused before an order existed, and orders staff cancelled or refunded while they were on the blocklist or linked to it. This is money that did not change hands. It is NOT money saved: some of these customers were not fraudsters, and the same customer trying again counts once per rule per day, not per click. Orders staff later approved are excluded. Money the verification controls held back is shown for context and never counted.', 'fraud-shield' ); ?></p>
				<div class="fs-cards">
					<div class="fs-card"><div class="fs-num"><?php echo $this->money_or_na( $bv['totals']['value'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Value of stopped checkouts', 'fraud-shield' ); ?></div></div>
					<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $bv['totals']['events'] ) ); ?></div><div class="fs-label"><?php echo esc_html( sprintf( /* translators: 1: cancelled orders, 2: refused attempts */ __( 'Stopped (%1$s cancelled, %2$s refused)', 'fraud-shield' ), number_format_i18n( $bv['totals']['cancelled_n'] ), number_format_i18n( $bv['totals']['refused_n'] ) ) ); ?></div></div>
					<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $bv['totals']['identities'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Distinct customers stopped', 'fraud-shield' ); ?></div></div>
					<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $bv['totals']['attempts'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Attempts incl. retries', 'fraud-shield' ); ?></div></div>
					<div class="fs-card fs-card-amber"><div class="fs-num"><?php echo $this->money_or_na( $bv['verify']['amount'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Held pending verification (not counted)', 'fraud-shield' ); ?></div></div>
				</div>
				<table class="wp-list-table widefat striped fs-savings-table">
					<thead><tr><th><?php esc_html_e( 'Control', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Counted as', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Checkouts stopped', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Value', 'fraud-shield' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $kinds as $kind => $meta ) : ?>
						<?php $k = $bv['totals']['by_kind'][ $kind ] ?? array( 'amount' => 0.0, 'events' => 0 ); ?>
						<tr>
							<td><strong><?php echo esc_html( $meta['label'] ); ?></strong></td>
							<td><?php echo 'fraud' === $meta['group'] ? '<span class="fs-pill fs-pill-green">' . esc_html__( 'Blocked value', 'fraud-shield' ) . '</span>' : '<span class="fs-pill fs-pill-amber">' . esc_html__( 'Held — not counted', 'fraud-shield' ) . '</span>'; ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $k['events'] ) ); ?></td>
							<td><?php echo $this->money_or_na( (float) $k['amount'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php
					if ( $bv['totals']['approved_n'] > 0 ) {
						echo esc_html( sprintf( /* translators: 1: number of orders, 2: amount */ __( 'Excluded: %1$s cancelled orders worth %2$s were later moved to a paid status by staff and are not counted as stopped.', 'fraud-shield' ), number_format_i18n( $bv['totals']['approved_n'] ), html_entity_decode( wp_strip_all_tags( wc_price( $bv['totals']['approved_amt'], array( 'currency' => $cur ) ) ) ) ) ) . ' ';
					}
					if ( $bv['totals']['staff_n'] > 0 ) {
						echo esc_html( sprintf( /* translators: 1: number of orders, 2: amount */ __( 'Included: %1$s orders worth %2$s were cancelled or refunded by staff while on the blocklist or linked to it (marked "by staff" wherever they are listed).', 'fraud-shield' ), number_format_i18n( $bv['totals']['staff_n'] ), html_entity_decode( wp_strip_all_tags( wc_price( $bv['totals']['staff_amount'], array( 'currency' => $cur ) ) ) ) ) ) . ' ';
					}
					esc_html_e( 'Counting started with the version that introduced the stopped-checkout ledger; earlier refusals were not recorded with an amount and are not reconstructed.', 'fraud-shield' );
					?>
				</p>
			</div>

			<!-- ============ 3. Estimated losses prevented (estimate) ============ -->
			<div class="fs-panel">
				<h2><?php esc_html_e( '3. Estimated losses prevented', 'fraud-shield' ); ?> <span class="fs-pill fs-pill-amber"><?php esc_html_e( 'ESTIMATE', 'fraud-shield' ); ?></span> <span class="fs-muted fs-panel-period"><?php echo esc_html( $period['label'] ); ?></span> <?php echo $this->confidence_pill( $est['confidence'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></h2>
				<p class="description"><?php esc_html_e( 'How much of the blocked value would probably have become a loss. Each stop is weighted by the share of the settled orders BEHIND it that charged back — the stopped customer\'s own orders and, when the stop was tied to a blocked identity (the person behind the shared address, phone or account, or behind the blocklist entry that matched), that identity\'s orders too — blended with the rate observed on the closest class of customers this store has outcomes for (blocked customers, then HIGH/BLOCK-scored orders, then the whole store), so one chargeback on one order does not read as certainty. Two figures: conservative uses only stops with a recorded chargeback behind them, at that history\'s own rate; expected also weights stops with no history by the class rate. Loss per order = value × probability × loss ratio, plus the chargeback fee × probability when a fee is configured.', 'fraud-shield' ); ?></p>
				<?php if ( 'none' === $est['confidence'] ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Not estimable: this store has no recorded chargebacks to calibrate against (or too few settled orders). Record chargebacks with the Charged Back status or the "Mark as chargeback" action and the estimate will appear. The blocked value above still stands as an observed figure.', 'fraud-shield' ); ?></p></div>
				<?php else : ?>
					<div class="fs-cards">
						<div class="fs-card fs-card-green"><div class="fs-num"><?php echo $this->money_or_na( $est['conservative'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Conservative — a chargeback behind the stop', 'fraud-shield' ); ?></div></div>
						<div class="fs-card fs-card-green"><div class="fs-num"><?php echo $this->money_or_na( $est['expected'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Expected — all stopped customers', 'fraud-shield' ); ?></div></div>
						<div class="fs-card"><div class="fs-num"><?php echo $this->money_or_na( $est['fees_expected'], $cur, $fee_why ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Expected fees avoided', 'fraud-shield' ); ?></div></div>
						<div class="fs-card fs-card-amber"><div class="fs-num"><?php echo $this->money_or_na( $est['uncalibrated'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Blocked value not estimable', 'fraud-shield' ); ?></div></div>
					</div>
					<h3 class="fs-savings-sub"><?php esc_html_e( 'Calibration — what the probabilities rest on', 'fraud-shield' ); ?></h3>
					<table class="wp-list-table widefat striped fs-savings-table">
						<thead><tr><th><?php esc_html_e( 'Class of customer', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Settled orders', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Charged back', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Observed rate', 'fraud-shield' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php
						$class_labels = array(
							'blocked' => __( 'Customers now on the blocklist', 'fraud-shield' ),
							'risky'   => __( 'Orders scored HIGH or BLOCK', 'fraud-shield' ),
							'store'   => __( 'Every settled order', 'fraud-shield' ),
						);
						foreach ( $est['calibration'] as $class => $c ) :
							?>
							<tr>
								<td><strong><?php echo esc_html( $class_labels[ $class ] ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( (int) $c['settled'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $c['chargebacks'] ) ); ?></td>
								<td><?php echo $pct( $c['rate'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo ( $est['prior'] && $est['prior']['class'] === $class ) ? '<span class="fs-pill fs-pill-green">' . esc_html__( 'used as the prior', 'fraud-shield' ) . '</span>' : ''; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description">
						<?php
						echo esc_html( sprintf( /* translators: 1: shrinkage weight, 2: loss ratio percent */ __( 'Each customer\'s own rate is blended with the prior as if the prior had been observed on %1$d extra orders. Loss ratio: %2$d%% of a reversed order\'s value is counted as lost.', 'fraud-shield' ), (int) $est['shrink'], (int) round( 100 * $est['loss_ratio'] ) ) );
						echo ' ' . esc_html( sprintf( /* translators: %s: amount */ __( 'Of the blocked value, %s has at least one chargeback behind it — the stopped customer\'s own, or the blocked identity\'s they were tied to (the conservative basis).', 'fraud-shield' ), html_entity_decode( wp_strip_all_tags( wc_price( $est['covered_value'], array( 'currency' => $cur ) ) ) ) ) );
						?>
					</p>
					<?php if ( ! empty( $est['rows'] ) ) : ?>
						<h3 class="fs-savings-sub"><?php esc_html_e( 'By customer', 'fraud-shield' ); ?></h3>
						<table class="wp-list-table widefat striped fs-savings-table">
							<thead><tr>
								<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
								<th><?php esc_html_e( 'Stopped by', 'fraud-shield' ); ?></th>
								<th><?php esc_html_e( 'Blocked value', 'fraud-shield' ); ?></th>
								<th><?php esc_html_e( 'History behind the stop', 'fraud-shield' ); ?></th>
								<th><?php esc_html_e( 'Probability of loss', 'fraud-shield' ); ?></th>
								<th><?php esc_html_e( 'Expected loss avoided', 'fraud-shield' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( array_slice( $est['rows'], 0, 25 ) as $r ) : ?>
								<?php
								$r_tied_names = array();
								foreach ( array_slice( (array) ( $r['linked_to'] ?? array() ), 0, 3 ) as $r_id ) {
									$r_tied_names[] = FS_Blocklist::identity_label( $r_id );
								}
								$r_tied_names = array_filter( $r_tied_names );
								?>
								<tr>
									<td><?php echo $this->identity_cell( $r ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
									<td>
										<?php echo esc_html( implode( ', ', array_map( function ( $k ) use ( $kinds ) { return $kinds[ $k ]['label'] ?? $k; }, array_filter( explode( ',', (string) $r['kinds'] ) ) ) ) ); ?>
										<?php if ( (int) ( $r['staff_n'] ?? 0 ) > 0 ) : ?><br/><span class="fs-muted"><?php echo esc_html( sprintf( /* translators: %s: number of orders */ _n( '%s order by staff', '%s orders by staff', (int) $r['staff_n'], 'fraud-shield' ), number_format_i18n( (int) $r['staff_n'] ) ) ); ?></span><?php endif; ?>
									</td>
									<td><?php echo $this->money_or_na( $r['value'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput ?><br/><span class="fs-muted"><?php echo esc_html( sprintf( /* translators: 1: stops, 2: attempts */ __( '%1$s stopped · %2$s attempts', 'fraud-shield' ), number_format_i18n( $r['events'] ), number_format_i18n( $r['attempts'] ) ) ); ?></span></td>
									<td>
										<?php echo $r['settled'] > 0 ? esc_html( sprintf( /* translators: 1: chargebacks, 2: settled orders */ __( 'Own: %1$s of %2$s settled orders charged back', 'fraud-shield' ), number_format_i18n( $r['chargebacks'] ), number_format_i18n( $r['settled'] ) ) ) : '<span class="fs-muted">' . esc_html__( 'Own: no settled orders here', 'fraud-shield' ) . '</span>'; ?>
										<?php if ( ! empty( $r_tied_names ) ) : ?>
											<br/><?php echo esc_html( sprintf( /* translators: 1: the blocked identities the stop was tied to, 2: their chargebacks, 3: their settled orders */ __( 'Tied to %1$s: %2$s of %3$s settled orders charged back', 'fraud-shield' ), implode( '; ', $r_tied_names ), number_format_i18n( (int) $r['tied_chargebacks'] ), number_format_i18n( (int) $r['tied_settled'] ) ) ); ?>
										<?php endif; ?>
									</td>
									<td><?php echo null === $r['probability'] ? '<span class="fs-muted">' . esc_html__( 'not estimable', 'fraud-shield' ) . '</span>' : esc_html( number_format_i18n( 100 * $r['probability'], 0 ) . '%' ); ?></td>
									<td><?php echo $this->money_or_na( $r['expected'], $cur, __( 'No calibration available for this customer.', 'fraud-shield' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<!-- ============ 4. Projected annual protection (forecast) ============ -->
			<div class="fs-panel">
				<h2><?php esc_html_e( '4. Projected annual protection', 'fraud-shield' ); ?> <span class="fs-pill fs-pill-red"><?php esc_html_e( 'FORECAST', 'fraud-shield' ); ?></span> <span class="fs-muted fs-panel-period"><?php esc_html_e( 'Next 12 months, at today\'s blocklist', 'fraud-shield' ); ?></span></h2>
				<p class="description">
					<?php
					echo esc_html( sprintf( /* translators: 1: minimum window in months, 2: loss ratio percent */ __( 'What the customers currently on the blocklist were costing per year while they were active — so what keeping them blocked avoids, if the same behaviour would have continued. Per blocked customer with a recorded chargeback: net loss = (charged back − recovered) × %2$d%% loss ratio; annualized = net loss × 12 ÷ months from their first order to their last, floored at %1$d months so a short burst is annualized at most ×%3$d. A customer who charged back $10,000 across two orders three months apart therefore projects to $40,000 a year. This is a run-rate, not a saving already made and not a guarantee; each row carries a confidence grade from how long and how often the behaviour was observed.', 'fraud-shield' ), (int) $proj['min_months'], (int) round( 100 * $proj['loss_ratio'] ), (int) round( 12 / max( 1, (int) $proj['min_months'] ) ) ) );
					?>
				</p>
				<div class="fs-cards">
					<div class="fs-card fs-card-green"><div class="fs-num"><?php echo $proj['identities'] > 0 ? $this->money_or_na( $proj['total'], $cur ) : '<span class="fs-muted fs-na">' . esc_html__( 'unavailable', 'fraud-shield' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Projected chargeback exposure avoided / year', 'fraud-shield' ); ?></div></div>
					<div class="fs-card"><div class="fs-num"><?php echo $this->money_or_na( $proj['fees_total'], $cur, $fee_why ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Projected fees avoided / year', 'fraud-shield' ); ?></div></div>
					<div class="fs-card"><div class="fs-num"><?php echo $this->money_or_na( $proj['trailing_12m'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><div class="fs-label"><?php esc_html_e( 'Their actual chargebacks, last 12 months (observed anchor)', 'fraud-shield' ); ?></div></div>
					<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $proj['identities'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Blocked customers with chargeback history', 'fraud-shield' ); ?></div></div>
				</div>
				<?php if ( $proj['identities'] > 0 ) : ?>
					<p class="description">
						<?php
						echo esc_html( sprintf( /* translators: 1: high-confidence amount, 2: medium, 3: low */ __( 'By confidence: %1$s rests on 12+ months of history with 3+ chargebacks (high); %2$s on 3–11 months with 2+ chargebacks (medium); %3$s on a single chargeback or under 3 months of history (low — the annualization multiplies a short glimpse).', 'fraud-shield' ), html_entity_decode( wp_strip_all_tags( wc_price( $proj['by_confidence']['high'], array( 'currency' => $cur ) ) ) ), html_entity_decode( wp_strip_all_tags( wc_price( $proj['by_confidence']['medium'], array( 'currency' => $cur ) ) ) ), html_entity_decode( wp_strip_all_tags( wc_price( $proj['by_confidence']['low'], array( 'currency' => $cur ) ) ) ) ) );
						?>
					</p>
					<p class="fs-filters"><a class="button" href="<?php echo esc_url( $this->export_url( 'protection' ) ); ?>"><?php esc_html_e( 'Export projection by customer (CSV)', 'fraud-shield' ); ?></a></p>
					<table class="wp-list-table widefat striped fs-savings-table">
						<thead><tr>
							<th><?php esc_html_e( 'Blocked customer', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Chargebacks', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Net loss observed', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Active window', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Annualized', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Confidence', 'fraud-shield' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( array_slice( $proj['rows'], 0, 50 ) as $r ) : ?>
							<tr>
								<td><?php echo $this->identity_cell( $r ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo esc_html( sprintf( /* translators: 1: chargebacks, 2: orders */ __( '%1$s of %2$s orders', 'fraud-shield' ), number_format_i18n( $r['cb_orders'] ), number_format_i18n( $r['orders'] ) ) ); ?><br/><span class="fs-muted"><?php echo esc_html( $date( $r['first_cb'] ) . ' → ' . $date( $r['last_cb'] ) ); ?></span></td>
								<td><?php echo $this->money_or_na( $r['net'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php if ( $r['recovered'] > 0 ) : ?><br/><span class="fs-muted"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'after %s recovered', 'fraud-shield' ), html_entity_decode( wp_strip_all_tags( wc_price( $r['recovered'], array( 'currency' => $cur ) ) ) ) ) ); ?></span><?php endif; ?></td>
								<td><?php echo esc_html( $date( $r['first_order'] ) . ' → ' . $date( $r['last_order'] ) ); ?><br/><span class="fs-muted"><?php echo esc_html( sprintf( /* translators: 1: months observed, 2: months used, 3: factor */ __( '%1$s mo. observed · ×%3$s over %2$s mo.', 'fraud-shield' ), number_format_i18n( $r['span_months'], 1 ), number_format_i18n( $r['window_months'], 1 ), number_format_i18n( $r['factor'], 2 ) ) ); ?></span></td>
								<td><strong><?php echo $this->money_or_na( $r['annual'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput ?></strong><?php if ( null !== $r['fees_annual'] ) : ?><br/><span class="fs-muted"><?php echo esc_html( sprintf( /* translators: %s: amount */ __( '+ %s fees', 'fraud-shield' ), html_entity_decode( wp_strip_all_tags( wc_price( $r['fees_annual'], array( 'currency' => $cur ) ) ) ) ) ); ?></span><?php endif; ?></td>
								<td><?php echo $this->confidence_pill( $r['confidence'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="fs-muted"><?php esc_html_e( 'No customer on the blocklist has a recorded chargeback, so there is no observed behaviour to project. Blocked customers with clean histories contribute nothing here — a projection needs losses to extrapolate from.', 'fraud-shield' ); ?></p>
				<?php endif; ?>
				<p class="description">
					<?php
					echo esc_html( sprintf( /* translators: 1: blocked customers with no chargebacks, 2: blocked email/account entries */ __( 'Not projected: %1$s blocked customers with no chargeback on record, and any block that names only an address, phone or IP (%2$s blocklist entries name an email or account). Overlapping identities are merged by account, then by email, and every order is counted once.', 'fraud-shield' ), number_format_i18n( $proj['no_history'] ), number_format_i18n( $proj['blocked_named'] ) ) );
					?>
				</p>
			</div>

			<!-- ============ Assumptions & limitations ============ -->
			<div class="fs-panel">
				<h2><?php esc_html_e( 'Assumptions and data limitations', 'fraud-shield' ); ?></h2>
				<ul class="fs-kv">
					<li><span><?php esc_html_e( 'Chargeback fee', 'fraud-shield' ); ?></span> <?php echo null === $a['fee'] ? esc_html__( 'not configured — fees shown as unavailable and left out of every net figure', 'fraud-shield' ) : esc_html( sprintf( /* translators: %s: amount */ __( '%s per dispute (from Settings)', 'fraud-shield' ), html_entity_decode( wp_strip_all_tags( wc_price( $a['fee'], array( 'currency' => $cur ) ) ) ) ) ); ?></li>
					<li><span><?php esc_html_e( 'Loss ratio', 'fraud-shield' ); ?></span> <?php echo esc_html( sprintf( /* translators: %d: percent */ __( '%d%% of a reversed order\'s value is counted as lost in estimates and projections; recorded losses are always the full reversed amount.', 'fraud-shield' ), (int) round( 100 * $a['loss_ratio'] ) ) ); ?></li>
					<li><span><?php esc_html_e( 'Minimum window', 'fraud-shield' ); ?></span> <?php echo esc_html( sprintf( /* translators: %d: days */ __( '%d days — the shortest history a chargeback run-rate is annualized over.', 'fraud-shield' ), (int) $a['min_window_days'] ) ); ?></li>
					<li><span><?php esc_html_e( 'Recoveries', 'fraud-shield' ); ?></span> <?php esc_html_e( 'Only what staff recorded on charged-back orders with "Record recovery" on the order screen. Disputes won elsewhere are unknown to this store until recorded.', 'fraud-shield' ); ?></li>
					<li><span><?php esc_html_e( 'Identities', 'fraud-shield' ); ?></span> <?php esc_html_e( 'A customer is their account when logged in, otherwise their email. A guest who later registers appears as two identities.', 'fraud-shield' ); ?></li>
					<li><span><?php esc_html_e( 'History behind a stop', 'fraud-shield' ); ?></span> <?php esc_html_e( 'A stop tied to a blocked identity — a shared shipping address, phone or account the linked rule found, or a blocklist entry that matched — is weighted by that identity\'s settled orders and chargebacks together with the stopped customer\'s own. The ledger names whom each stop was tied to at the time; the identity\'s history is read from the order index as it stands now.', 'fraud-shield' ); ?></li>
					<li><span><?php esc_html_e( 'Stopped by staff', 'fraud-shield' ); ?></span> <?php esc_html_e( 'An order a person cancels or refunds in wp-admin (or over the API) while it is on the blocklist, or — with the linked rule on — linked to a blocked identity, is counted at its total and marked "by staff". Cancellations by cron, by the customer, or by a gateway webhook are not; an order recorded as charged back is a loss, never a stop. A cancelled or refunded order that qualifies but was never counted carries a "Count as fraud prevented" button on its order screen.', 'fraud-shield' ); ?></li>
					<li><span><?php esc_html_e( 'Not counted', 'fraud-shield' ); ?></span> <?php esc_html_e( 'Flagged orders, orders held for review, payment-method refusals, verification holds, and cancelled orders staff later approved are never counted as prevented losses. Nor is a blocked customer who merely opens the checkout page and is turned away there: only a submitted checkout counts.', 'fraud-shield' ); ?></li>
					<li><span><?php esc_html_e( 'Costs not tracked', 'fraud-shield' ); ?></span> <?php esc_html_e( 'Cost of goods, shipping and staff time are not recorded by this store and are not estimated here.', 'fraud-shield' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/* ===================== Customer Network (v1.26) ===================== */

	/**
	 * A connection-strength pill.
	 *
	 * @param string $strength identity|strong|medium|weak.
	 * @return string HTML.
	 */
	private function strength_pill( $strength ) {
		$map = array(
			'identity' => array( 'fs-pill-black', __( 'Same account', 'fraud-shield' ) ),
			'strong'   => array( 'fs-pill-red', __( 'Strong', 'fraud-shield' ) ),
			'medium'   => array( 'fs-pill-amber', __( 'Medium', 'fraud-shield' ) ),
			'weak'     => array( '', __( 'Weak', 'fraud-shield' ) ),
		);
		$m   = $map[ $strength ] ?? $map['weak'];
		return '<span class="fs-pill ' . esc_attr( $m[0] ) . '">' . esc_html( $m[1] ) . '</span>';
	}

	/**
	 * URL of a customer's network view.
	 *
	 * @param string $email Normalized email.
	 * @return string
	 */
	private function network_url( $email ) {
		return add_query_arg(
			array(
				'page'       => self::SLUG . '-network',
				'fs_network' => rawurlencode( (string) $email ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Customer Network: a risk-ranked list of every customer identity and, for
	 * any one of them, the customers they are connected to — through what,
	 * on what evidence, how recently, and what that does to risk. Nothing on
	 * this page decides anything: blocking and verifying go through the same
	 * logged toggles as everywhere else, one customer at a time.
	 */
	public function page_network() {
		$this->guard();

		$explore = isset( $_GET['fs_network'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['fs_network'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $explore ) {
			$this->render_network_explore( $explore );
			return;
		}

		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$orderby = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'risk'; // phpcs:ignore WordPress.Security.NonceVerification
		$dir     = isset( $_GET['dir'] ) && 'asc' === $_GET['dir'] ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification
		$paged   = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$per     = 50;

		$total = FS_Insights::network_count( $search );
		$rows  = FS_Insights::network_list(
			array(
				'search'  => $search,
				'orderby' => $orderby,
				'dir'     => $dir,
				'limit'   => $per,
				'offset'  => ( $paged - 1 ) * $per,
			)
		);
		$pages  = (int) ceil( $total / $per );
		$emails = array_values( array_filter( wp_list_pluck( $rows, 'email_norm' ) ) );
		$conn   = FS_Insights::connections_batch( $emails );
		$acts   = FS_Blocklist::actions_for_targets( 'profile', $emails );
		$vcreds = FS_Live::verified_creds();
		$types  = FS_Insights::edge_types();
		$s      = FS_Core::instance()->settings();
		$base   = array(
			'page' => self::SLUG . '-network',
			's'    => $search,
		);
		$sorts  = array(
			'risk'        => __( 'Risk', 'fraud-shield' ),
			'chargebacks' => __( 'Chargebacks', 'fraud-shield' ),
			'spend'       => __( 'Spend', 'fraud-shield' ),
			'orders'      => __( 'Orders', 'fraud-shield' ),
			'last'        => __( 'Last order', 'fraud-shield' ),
			'name'        => __( 'Name', 'fraud-shield' ),
		);
		?>
		<div class="wrap fs-wrap fs-network">
			<h1><?php esc_html_e( 'Customer Network', 'fraud-shield' ); ?></h1>
			<?php $this->maybe_notice(); ?>
			<p class="description"><?php esc_html_e( 'Every customer identity in the order index, ranked by risk: blocked customers first, then recorded chargebacks, then the highest risk score any of their orders reached. Connections count the other customers who share an identifier with them — an account, a phone number, a shipping address, the same door or building, a street, or an IP — and the strongest of those links to a blocked customer. Open a customer to see each connection with its evidence and recency. A shared street or IP is a weak association and is never, by itself, grounds to block.', 'fraud-shield' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-network' ); ?>" />
				<p class="fs-filters">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email, name, phone, IP or address…', 'fraud-shield' ); ?>" class="regular-text" />
					<label><?php esc_html_e( 'Sort by', 'fraud-shield' ); ?>
						<select name="sort">
							<?php foreach ( $sorts as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $orderby, $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<select name="dir"><option value="desc" <?php selected( $dir, 'desc' ); ?>><?php esc_html_e( 'Descending', 'fraud-shield' ); ?></option><option value="asc" <?php selected( $dir, 'asc' ); ?>><?php esc_html_e( 'Ascending', 'fraud-shield' ); ?></option></select>
					<button class="button"><?php esc_html_e( 'Apply', 'fraud-shield' ); ?></button>
					<a class="button" href="<?php echo esc_url( $this->export_url( 'network', array( 's' => $search, 'sort' => $orderby, 'dir' => $dir ) ) ); ?>"><?php esc_html_e( 'Export CSV (Excel)', 'fraud-shield' ); ?></a>
					<span class="fs-muted"><?php echo esc_html( sprintf( /* translators: %s: number of identities */ _n( '%s identity', '%s identities', $total, 'fraud-shield' ), number_format_i18n( $total ) ) ); ?></span>
				</p>
			</form>

			<table class="wp-list-table widefat striped fs-network-table">
				<thead><tr>
					<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Standing', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Risk', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Chargebacks', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Orders / spend', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Connections', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Last order', 'fraud-shield' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No customers match. The network is built from the order index — run a scan from the Dashboard if it is empty.', 'fraud-shield' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $rows as $r ) :
					$email = (string) $r['email_norm'];
					$uid   = (int) $r['user_id'];
					$c     = $conn[ $email ] ?? array( 'connections' => 0, 'strong' => 0, 'strongest' => '', 'blocked_via' => null, 'blocked_strength' => null, 'blocked_with' => null );
					$level = FS_Rules::level_for_score( (int) $r['max_risk'], $s );
					$is_v  = isset( $vcreds[ 'email:' . $email ] ) || ( $uid > 0 && FS_Live::is_verified_customer( $uid ) );
					$cls   = ! empty( $r['blocked'] ) ? 'fs-level-block' : ( (int) $r['chargebacks'] > 0 ? 'fs-level-high' : ( null !== $c['blocked_strength'] && FS_Insights::strength_rank( $c['blocked_strength'] ) >= 3 ? 'fs-level-medium' : '' ) );
					?>
					<tr class="<?php echo esc_attr( $cls ); ?>">
						<td><?php echo $this->identity_cell( $r ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td>
							<?php
							if ( ! empty( $r['blocked'] ) ) {
								echo '<span class="fs-pill fs-pill-black">' . esc_html__( 'Blocked', 'fraud-shield' ) . '</span> ';
							}
							if ( $is_v ) {
								echo '<span class="fs-pill fs-pill-green">' . esc_html__( 'Verified', 'fraud-shield' ) . '</span> ';
							}
							if ( null !== $c['blocked_strength'] ) {
								$via_label = $types[ $c['blocked_via'] ]['label'] ?? $c['blocked_via'];
								$pill      = 'weak' === $c['blocked_strength'] ? '' : ( 'medium' === $c['blocked_strength'] ? 'fs-pill-amber' : 'fs-pill-red' );
								echo '<span class="fs-pill ' . esc_attr( $pill ) . '" title="' . esc_attr( sprintf( /* translators: %s: connected blocked customer's email */ __( 'Shares this identifier with blocked customer %s', 'fraud-shield' ), $c['blocked_with'] ) ) . '">' . esc_html( sprintf( /* translators: %s: identifier type, e.g. "Phone number" */ __( 'Linked to blocked — %s', 'fraud-shield' ), $via_label ) ) . '</span>';
							}
							if ( empty( $r['blocked'] ) && ! $is_v && null === $c['blocked_strength'] ) {
								echo '<span class="fs-muted">—</span>';
							}
							?>
						</td>
						<td><?php echo $this->level_pill( $level ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo (int) $r['max_risk']; ?></td>
						<td><?php echo (int) $r['chargebacks'] > 0 ? '<strong class="fs-bad">' . esc_html( number_format_i18n( (int) $r['chargebacks'] ) ) . '</strong><br/><span class="fs-muted">' . wp_kses_post( wc_price( (float) $r['cb_amount'] ) ) . '</span>' : '<span class="fs-muted">—</span>'; ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $r['orders'] ) ); ?><br/><span class="fs-muted"><?php echo wp_kses_post( wc_price( (float) $r['spend'] ) ); ?></span></td>
						<td>
							<?php if ( $c['connections'] > 0 ) : ?>
								<strong><?php echo esc_html( number_format_i18n( $c['connections'] ) ); ?></strong>
								<span class="fs-muted"><?php echo esc_html( sprintf( /* translators: %s: number of strong connections */ __( '(%s strong)', 'fraud-shield' ), number_format_i18n( $c['strong'] ) ) ); ?></span><br/>
								<?php echo $this->strength_pill( $c['strongest'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php else : ?>
								<span class="fs-muted"><?php esc_html_e( 'none', 'fraud-shield' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $r['last_order'] ? mysql2date( 'Y-m-d', (string) $r['last_order'] ) : '—' ); ?></td>
						<td>
							<a class="button button-small button-primary" href="<?php echo esc_url( $this->network_url( $email ) ); ?>"><?php esc_html_e( 'Explore', 'fraud-shield' ); ?></a>
							<?php echo $this->block_profile_button( $email, $acts[ $email ] ?? null, ! empty( $r['blocked'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( array_merge( $base, array( 'sort' => $orderby, 'dir' => $dir ) ), admin_url( 'admin.php' ) ) . '%_%',
							'format'    => '&paged=%#%',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?>
				</div></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Connections are computed for the customers on this page from their full order history. "Strong" means a shared phone number, an identical shipping address or the same door; "Medium" the same building or first address line; "Weak" a street, an IP address or a browser signature. "Linked to blocked" names the strongest identifier a customer shares directly with someone on the blocklist. Nothing here blocks anyone: Block and Unblock are the same explicit, logged actions as on every other page, one customer at a time.', 'fraud-shield' ); ?></p>
		</div>
		<?php
	}

	/**
	 * One customer's network: who they are, what they have used, who they are
	 * connected to and on what evidence, and the explicit actions available.
	 *
	 * @param string $email Email (raw or normalized).
	 */
	private function render_network_explore( $email ) {
		$d      = FS_Insights::explore( $email );
		$back   = admin_url( 'admin.php?page=' . self::SLUG . '-network' );
		$types  = FS_Insights::edge_types();
		$s      = FS_Core::instance()->settings();
		$date   = function ( $v ) {
			return '' !== (string) $v ? mysql2date( 'Y-m-d', (string) $v ) : '—';
		};
		?>
		<div class="wrap fs-wrap fs-network">
			<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Customer Network', 'fraud-shield' ); ?></a></p>
			<?php if ( ! $d ) : ?>
				<h1><?php esc_html_e( 'Customer Network', 'fraud-shield' ); ?></h1>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'No orders on record for that email, so there is no network to show.', 'fraud-shield' ); ?></p></div>
			</div>
			<?php
			return;
			endif;
			$sum   = $d['summary'];
			$uid   = (int) $d['user_id'];
			$act   = FS_Blocklist::action_for_target( 'profile', $d['email'] );
			$actv  = $act ? array( 'id' => (int) $act['id'], 'entries' => FS_Blocklist::action_entry_count( (int) $act['id'] ) ) : null;
			$level = FS_Rules::level_for_score( (int) ( $sum['max_risk'] ?? 0 ), $s );
			$n_blk = 0;
			foreach ( $d['neighbours'] as $n ) {
				if ( ! empty( $n['blocked'] ) ) {
					$n_blk++;
				}
			}
			?>
			<h1><?php echo esc_html( '' !== $d['name'] ? $d['name'] : $d['email'] ); ?></h1>
			<?php $this->maybe_notice(); ?>
			<p class="fs-filters">
				<?php if ( $d['blocked'] ) : ?><span class="fs-pill fs-pill-black"><?php esc_html_e( 'Blocked', 'fraud-shield' ); ?></span><?php endif; ?>
				<?php if ( $d['verified'] ) : ?><span class="fs-pill fs-pill-green"><?php esc_html_e( 'Verified', 'fraud-shield' ); ?></span><?php endif; ?>
				<?php if ( $d['idv_verified'] ) : ?><span class="fs-pill fs-pill-green"><?php esc_html_e( 'ID-verified', 'fraud-shield' ); ?></span><?php endif; ?>
				<span><?php echo esc_html( $d['email'] ); ?></span>
				<?php if ( $uid > 0 ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG . '-new-customers', 'fs_customer' => $uid ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: account id */ __( 'account #%d', 'fraud-shield' ), $uid ) ); ?></a>
				<?php else : ?>
					<span class="fs-muted"><?php esc_html_e( 'guest', 'fraud-shield' ); ?></span>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( $this->profile_url( $d['email'] ) ); ?>"><?php esc_html_e( 'Full profile', 'fraud-shield' ); ?></a>
				<span style="margin-left:auto;display:inline-flex;gap:6px;">
					<?php echo $this->block_profile_button( $d['email'], $actv, $d['blocked'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo $this->verify_button( 'email', $d['email'], isset( FS_Live::verified_creds()[ 'email:' . $d['email'] ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo $this->idv_request_button( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</span>
			</p>

			<div class="fs-cards">
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) ( $sum['orders'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php echo esc_html( sprintf( /* translators: %s: paid orders */ __( 'Orders (%s paid)', 'fraud-shield' ), number_format_i18n( (int) ( $sum['paid_orders'] ?? 0 ) ) ) ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo wp_kses_post( wc_price( (float) ( $sum['spend'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Paid spend', 'fraud-shield' ); ?></div></div>
				<div class="fs-card <?php echo (int) ( $sum['chargebacks'] ?? 0 ) > 0 ? 'fs-card-red' : ''; ?>"><div class="fs-num"><?php echo esc_html( number_format_i18n( (int) ( $sum['chargebacks'] ?? 0 ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Chargebacks', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo $this->level_pill( $level ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo (int) ( $sum['max_risk'] ?? 0 ); ?></div><div class="fs-label"><?php esc_html_e( 'Highest risk score', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( count( $d['neighbours'] ) ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Connected customers', 'fraud-shield' ); ?></div></div>
				<div class="fs-card <?php echo $n_blk > 0 ? 'fs-card-red' : ''; ?>"><div class="fs-num"><?php echo esc_html( number_format_i18n( $n_blk ) ); ?></div><div class="fs-label"><?php esc_html_e( 'of them blocked', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num" style="font-size:16px;"><?php echo esc_html( $date( $sum['first_order'] ?? '' ) . ' → ' . $date( $sum['last_order'] ?? '' ) ); ?></div><div class="fs-label"><?php esc_html_e( 'First / last order', 'fraud-shield' ); ?></div></div>
			</div>

			<?php if ( ! empty( $d['block_cards'] ) ) : ?>
				<div class="fs-panel">
					<h2><?php esc_html_e( 'On the blocklist', 'fraud-shield' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Every blocklist entry that names this customer or a credential they have used, with the block card it belongs to and the reason recorded when it was added.', 'fraud-shield' ); ?></p>
					<table class="wp-list-table widefat striped">
						<thead><tr><th><?php esc_html_e( 'Blocked', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Block card', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Reason', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Since', 'fraud-shield' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $d['block_cards'] as $bc ) : ?>
							<tr>
								<td><strong><?php echo esc_html( ucfirst( (string) $bc['type'] ) ); ?></strong> <?php echo esc_html( (string) $bc['display'] ); ?></td>
								<td><?php echo '' !== (string) $bc['action_title'] ? '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-blocklist' ) ) . '">' . esc_html( (string) $bc['action_title'] ) . '</a> <span class="fs-muted">(' . esc_html( (string) $bc['action_kind'] ) . ')</span>' : '<span class="fs-muted">' . esc_html__( 'ungrouped entry', 'fraud-shield' ) . '</span>'; ?></td>
								<td><?php echo esc_html( '' !== (string) $bc['reason'] ? (string) $bc['reason'] : '—' ); ?></td>
								<td><?php echo esc_html( $date( $bc['created_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Identifiers on record', 'fraud-shield' ); ?></h2>
				<p class="description"><?php esc_html_e( 'What this customer has used across their orders — the identifiers a connection can run over. Addresses are shown as typed; IP and browser signatures are weak identifiers shared by many people.', 'fraud-shield' ); ?></p>
				<ul class="fs-kv fs-idents">
					<?php
					foreach ( array( 'phone', 'address', 'street', 'ip', 'device', 'account' ) as $via ) :
						$list = $d['credentials'][ $via ] ?? array();
						if ( empty( $list ) ) {
							continue;
						}
						?>
						<li><span><?php echo esc_html( $types[ $via ]['label'] ); ?></span>
							<?php
							$parts = array();
							foreach ( array_slice( $list, 0, 8 ) as $cval ) {
								$shown = '' !== $cval['label'] ? $cval['label'] : ( 'street' === $via ? $cval['label'] : $cval['value'] );
								if ( 'device' === $via ) {
									$shown = $this->device_label( $cval['value'] );
								}
								if ( 'account' === $via ) {
									$shown = sprintf( /* translators: %s: account id */ __( 'account #%s', 'fraud-shield' ), $cval['value'] );
								}
								$parts[] = '<code>' . esc_html( $shown ) . '</code> <span class="fs-muted">' . esc_html( sprintf( /* translators: 1: orders, 2: last used date */ __( '%1$s orders, last %2$s', 'fraud-shield' ), number_format_i18n( $cval['orders'] ), $date( $cval['last'] ) ) ) . '</span>';
							}
							echo implode( '<br/>', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
							if ( count( $list ) > 8 ) {
								echo '<br/><span class="fs-muted">' . esc_html( sprintf( /* translators: %d: number */ __( 'and %d more', 'fraud-shield' ), count( $list ) - 8 ) ) . '</span>';
							}
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Connections', 'fraud-shield' ); ?> <span class="fs-pill"><?php echo esc_html( number_format_i18n( count( $d['neighbours'] ) ) ); ?></span></h2>
				<p class="description"><?php esc_html_e( 'Other customers who share an identifier with this one, strongest contribution to risk first. Each row says exactly what matches, how many orders on each side used it and when, the connected customer\'s own standing, and how much the connection should weigh. Where a connected customer is not blocked but leads on to a blocked one over a strong link, the path is shown. A weak association — a street, an IP, a browser — never justifies blocking on its own.', 'fraud-shield' ); ?></p>
				<?php if ( empty( $d['neighbours'] ) ) : ?>
					<p><?php esc_html_e( 'No other customer shares an identifier with this one.', 'fraud-shield' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat striped fs-network-table">
						<thead><tr>
							<th><?php esc_html_e( 'Connected customer', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'What matches, and the evidence', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Their standing', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Contribution to risk', 'fraud-shield' ); ?></th>
							<th></th>
						</tr></thead>
						<tbody>
						<?php
						$n_acts = FS_Blocklist::actions_for_targets( 'profile', wp_list_pluck( $d['neighbours'], 'email_norm' ) );
						foreach ( $d['neighbours'] as $n ) :
							$ne     = (string) $n['email_norm'];
							$nlevel = FS_Rules::level_for_score( (int) ( $n['max_risk'] ?? 0 ), $s );
							$rcls   = 'high' === $n['risk_level'] ? 'fs-level-high' : ( 'medium' === $n['risk_level'] ? 'fs-level-medium' : '' );
							?>
							<tr class="<?php echo esc_attr( $rcls ); ?>">
								<td>
									<?php echo $this->identity_cell( $n ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<br/><span class="fs-muted"><?php echo esc_html( sprintf( /* translators: 1: orders, 2: first order date, 3: last order date */ __( '%1$s orders, %2$s → %3$s', 'fraud-shield' ), number_format_i18n( (int) ( $n['orders'] ?? 0 ) ), $date( $n['first_order'] ?? '' ), $date( $n['last_order'] ?? '' ) ) ); ?></span>
								</td>
								<td class="fs-why">
									<ul>
									<?php foreach ( $n['edges'] as $e ) : ?>
										<li>
											<?php echo $this->strength_pill( $e['strength'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<strong><?php echo esc_html( $e['label'] ); ?></strong>
											<code><?php echo esc_html( 'device' === $e['via'] ? $this->device_label( $e['value'] ) : $e['display'] ); ?></code><br/>
											<span class="fs-muted">
												<?php
												echo esc_html(
													sprintf(
														/* translators: 1: their orders, 2: their last date, 3: this customer's orders, 4: this customer's last date */
														__( 'Used on %1$s of their orders (last %2$s) and %3$s of this customer\'s (last %4$s).', 'fraud-shield' ),
														number_format_i18n( $e['their_orders'] ),
														$date( $e['their_last'] ),
														number_format_i18n( $e['mine_orders'] ),
														$date( $e['mine_last'] )
													)
												);
												$links = array();
												foreach ( $e['order_ids'] as $oid => $odate ) {
													$links[] = '<a href="' . esc_url( $this->order_edit_url( (int) $oid ) ) . '">#' . (int) $oid . '</a>';
												}
												if ( ! empty( $links ) ) {
													echo ' ' . esc_html__( 'Their orders:', 'fraud-shield' ) . ' ' . implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
												}
												?>
											</span>
										</li>
									<?php endforeach; ?>
									</ul>
								</td>
								<td>
									<?php
									if ( ! empty( $n['blocked'] ) ) {
										echo '<span class="fs-pill fs-pill-black">' . esc_html__( 'Blocked', 'fraud-shield' ) . '</span> ';
									}
									if ( ! empty( $n['verified'] ) ) {
										echo '<span class="fs-pill fs-pill-green">' . esc_html__( 'Verified', 'fraud-shield' ) . '</span> ';
									}
									echo $this->level_pill( $nlevel ) . ' ' . (int) ( $n['max_risk'] ?? 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
									if ( (int) ( $n['chargebacks'] ?? 0 ) > 0 ) {
										echo '<br/><strong class="fs-bad">' . esc_html( sprintf( /* translators: 1: chargebacks, 2: amount */ _n( '%1$s chargeback (%2$s)', '%1$s chargebacks (%2$s)', (int) $n['chargebacks'], 'fraud-shield' ), number_format_i18n( (int) $n['chargebacks'] ), html_entity_decode( wp_strip_all_tags( wc_price( (float) $n['cb_amount'] ) ) ) ) ) . '</strong>';
									}
									echo '<br/><span class="fs-muted">' . wp_kses_post( wc_price( (float) ( $n['spend'] ?? 0 ) ) ) . ' ' . esc_html__( 'paid', 'fraud-shield' ) . '</span>';
									?>
								</td>
								<td>
									<?php echo $this->level_pill( $n['risk_level'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<br/><span class="fs-why"><?php echo esc_html( $n['risk_reason'] ); ?></span>
									<?php if ( ! empty( $n['paths'] ) ) : ?>
										<ul class="fs-paths">
										<?php foreach ( array_slice( $n['paths'], 0, 3 ) as $p ) : ?>
											<li><span class="fs-muted"><?php esc_html_e( 'Leads to blocked', 'fraud-shield' ); ?></span> <a href="<?php echo esc_url( $this->network_url( $p['blocked_email'] ) ); ?>"><?php echo esc_html( '' !== $p['blocked_name'] ? $p['blocked_name'] : $p['blocked_email'] ); ?></a> <span class="fs-muted"><?php echo esc_html( sprintf( /* translators: 1: identifier type, 2: value, 3: date */ __( 'via %1$s %2$s (last %3$s)', 'fraud-shield' ), strtolower( $p['label'] ), $p['display'], $date( $p['last'] ) ) ); ?></span> <?php echo $this->strength_pill( $p['strength'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
										<?php endforeach; ?>
										</ul>
									<?php endif; ?>
								</td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( $this->network_url( $ne ) ); ?>"><?php esc_html_e( 'Explore', 'fraud-shield' ); ?></a>
									<?php echo $this->block_profile_button( $ne, $n_acts[ $ne ] ?? null, ! empty( $n['blocked'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $d['chargebacks'] ) ) : ?>
				<div class="fs-panel">
					<h2><?php esc_html_e( 'Chargebacks', 'fraud-shield' ); ?> <span class="fs-pill fs-pill-red"><?php echo esc_html( number_format_i18n( count( $d['chargebacks'] ) ) ); ?></span></h2>
					<table class="wp-list-table widefat striped">
						<thead><tr><th><?php esc_html_e( 'Order', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Date', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Amount', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Recovered', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Ship to', 'fraud-shield' ); ?></th><th><?php esc_html_e( 'Status', 'fraud-shield' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $d['chargebacks'] as $o ) : ?>
							<tr class="fs-row-flag">
								<td><a href="<?php echo esc_url( $this->order_edit_url( (int) $o['order_id'] ) ); ?>">#<?php echo (int) $o['order_id']; ?></a></td>
								<td><?php echo esc_html( $date( $o['order_date'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( (float) $o['order_total'] ) ); ?></td>
								<td><?php echo (float) $o['recovered'] > 0 ? wp_kses_post( wc_price( (float) $o['recovered'] ) ) : '<span class="fs-muted">—</span>'; ?></td>
								<td class="fs-addr"><?php echo esc_html( (string) $o['ship_text'] ); ?></td>
								<td><?php echo esc_html( function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( (string) $o['status'] ) : (string) $o['status'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Recent orders', 'fraud-shield' ); ?></h2>
				<?php if ( $d['rows_capped'] ) : ?>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %d: number of orders */ __( 'This customer has more than %d orders; the network above was built from the most recent %d. The full list is on their profile.', 'fraud-shield' ), FS_Insights::SELF_ROWS, FS_Insights::SELF_ROWS ) ); ?></p>
				<?php endif; ?>
				<?php $this->render_orders_table( $d['orders'] ); ?>
			</div>
		</div>
		<?php
	}

	/* ===================== WooCommerce → Orders column (v1.26) ===================== */

	/**
	 * Add the "Fraud Shield" column to the WooCommerce Orders list, right after
	 * the status column. Shared by the legacy (posts) and HPOS list tables.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function orders_list_column( $columns ) {
		$out   = array();
		$added = false;
		foreach ( (array) $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['fs_shield'] = __( 'Fraud Shield', 'fraud-shield' );
				$added            = true;
			}
		}
		if ( ! $added ) {
			$out['fs_shield'] = __( 'Fraud Shield', 'fraud-shield' );
		}
		return $out;
	}

	/**
	 * Legacy (posts) Orders list cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Order post id.
	 */
	public function orders_list_column_legacy( $column, $post_id ) {
		if ( 'fs_shield' !== $column ) {
			return;
		}
		$this->render_orders_list_cell( wc_get_order( (int) $post_id ) );
	}

	/**
	 * HPOS Orders list cell.
	 *
	 * @param string   $column Column key.
	 * @param WC_Order $order  Order.
	 */
	public function orders_list_column_hpos( $column, $order ) {
		if ( 'fs_shield' !== $column ) {
			return;
		}
		$this->render_orders_list_cell( $order );
	}

	/**
	 * The cell: an "Auto-cancelled" badge with the reason for orders the
	 * linked-identity rule cancelled; a quiet dash for everything else.
	 *
	 * @param WC_Order|false|null $order Order.
	 */
	private function render_orders_list_cell( $order ) {
		if ( ! $order ) {
			echo '<span class="fs-oc-none">&mdash;</span>';
			return;
		}
		$shown = false;

		$rec = FS_Live::linked_cancel_record( $order );
		if ( $rec ) {
			$url = add_query_arg(
				array(
					'page' => self::SLUG . '-cancels',
					's'    => (int) $order->get_id(),
				),
				admin_url( 'admin.php' )
			);
			echo '<a class="fs-oc-badge" href="' . esc_url( $url ) . '" title="' . esc_attr__( 'Cancelled automatically by Fraud Shield: this customer shares credentials with a blocked identity. Click to open it on the Auto-Cancelled page.', 'fraud-shield' ) . '">' . esc_html__( 'Auto-cancelled', 'fraud-shield' ) . '</a>';
			if ( '' !== $rec['summary'] ) {
				echo '<span class="fs-oc-why">' . esc_html( $rec['summary'] ) . '</span>';
			}
			$shown = true;
		}

		// Which of this customer's credentials are on the blocklist right now,
		// since when and by whom (v1.27): one indexed lookup per row, the full
		// provenance on the order screen's Fraud Shield box.
		$signals = FS_Data::extract_signals( $order );
		$matches = FS_Blocklist::match( $signals );
		if ( ! empty( $matches ) ) {
			$types = array();
			$since = '';
			$whos  = array();
			foreach ( $matches as $m ) {
				$types[ (string) $m['type'] ] = true;
				$at                            = (string) ( $m['created_at'] ?? '' );
				if ( '' !== $at && ( '' === $since || $at < $since ) ) {
					$since = $at;
				}
				$whos[ (int) ( $m['added_by'] ?? 0 ) ] = true;
			}
			$names = FS_Blocklist::actor_names( array_keys( $whos ) );
			$who   = 1 === count( $names ) ? (string) reset( $names ) : __( 'several people', 'fraud-shield' );
			$first = FS_Blocklist::describe_match( $matches[0], $signals );
			$url   = add_query_arg(
				array(
					'page' => self::SLUG . '-blocklist',
					's'    => rawurlencode( $first['search'] ),
				),
				admin_url( 'admin.php' )
			);
			if ( $shown ) {
				echo '<br/>';
			}
			echo '<a class="fs-oc-badge fs-oc-blocklist" href="' . esc_url( $url ) . '" title="' . esc_attr__( 'This customer\'s credentials are on the blocklist. Open the order for the full detail, or click to find them on the Blocklist page.', 'fraud-shield' ) . '">' . esc_html__( 'On blocklist', 'fraud-shield' ) . '</a>';
			echo '<span class="fs-oc-why">' . esc_html(
				sprintf(
					/* translators: 1: comma-separated credential types, 2: date, 3: staff member */
					__( '%1$s · since %2$s · by %3$s', 'fraud-shield' ),
					implode( ', ', array_keys( $types ) ),
					'' !== $since ? mysql2date( 'Y-m-d', $since ) : '—',
					$who
				)
			) . '</span>';
			$shown = true;
		}

		// High-value review (v1.32): the verdict and who gave it, or that the
		// order is still waiting for one. Read from the order's own meta, so
		// the list costs no extra query per row.
		$hv_rec = FS_Live::hv_review_record( $order );
		if ( $hv_rec || FS_Live::is_high_value( $order ) ) {
			$hv_url = add_query_arg(
				array(
					'page' => self::SLUG . '-high-value',
					'win'  => '90d',
					'show' => 'all',
					'dead' => '1',
					's'    => (int) $order->get_id(),
				),
				admin_url( 'admin.php' )
			);
			if ( $shown ) {
				echo '<br/>';
			}
			if ( $hv_rec ) {
				$v = FS_Data::hv_verdicts()[ $hv_rec['verdict'] ] ?? null;
				echo '<a class="fs-oc-badge fs-oc-hv fs-oc-hv-' . esc_attr( $hv_rec['verdict'] ) . '" href="' . esc_url( $hv_url ) . '" title="' . esc_attr__( 'High-value review verdict. Click to open it on the High-Value Review page.', 'fraud-shield' ) . '">' . esc_html( $v ? $v['short'] : $hv_rec['verdict'] ) . '</a>';
				echo '<span class="fs-oc-why">' . esc_html(
					sprintf(
						/* translators: 1: reviewer's name, 2: date */
						__( 'reviewed by %1$s · %2$s', 'fraud-shield' ),
						FS_Data::hv_reviewer_name( $hv_rec['by'], $hv_rec['by_name'] ),
						'' !== $hv_rec['at'] ? mysql2date( 'Y-m-d', $hv_rec['at'] ) : '—'
					)
				) . '</span>';
			} else {
				echo '<a class="fs-oc-badge fs-oc-hv fs-oc-hv-pending" href="' . esc_url( $hv_url ) . '" title="' . esc_attr__( 'A high-value order nobody has reviewed yet. Click to open it on the High-Value Review page.', 'fraud-shield' ) . '">' . esc_html__( 'High value · awaiting review', 'fraud-shield' ) . '</a>';
			}
			$shown = true;
		}

		if ( ! $shown ) {
			echo '<span class="fs-oc-none">&mdash;</span>';
		}
	}

	/* ===================== Refused-checkout evidence (v1.29) ===================== */

	/**
	 * What a refused checkout matched, as staff should read it: for a
	 * blocklist refusal, each blocked credential as the customer typed it,
	 * blocked since when and by whom, under which block, the reason, and the
	 * person it was MATCHED TO (the identities behind the credential, each
	 * linking to its profile); for a linked-identity refusal, the shared
	 * credentials and the blocked identities behind them. Every line links to
	 * the Blocklist page's search for that credential.
	 *
	 * @param array $evidence Decoded `evidence` from a Checkout Locks ledger row.
	 * @return string HTML ('' when the row predates the evidence column).
	 */
	private function render_block_evidence( $evidence ) {
		$items = isset( $evidence['items'] ) && is_array( $evidence['items'] ) ? $evidence['items'] : array();
		if ( empty( $items ) ) {
			return '';
		}
		$kind      = (string) ( $evidence['kind'] ?? 'blocklist' );
		$blocklist = admin_url( 'admin.php?page=' . self::SLUG . '-blocklist' );
		$via_label = array(
			'email'   => __( 'Email', 'fraud-shield' ),
			'phone'   => __( 'Phone', 'fraud-shield' ),
			'ip'      => __( 'IP', 'fraud-shield' ),
			'address' => __( 'Shipping address', 'fraud-shield' ),
			'user'    => __( 'Account', 'fraud-shield' ),
		);
		$out = '<ul class="fs-why fs-evidence">';
		foreach ( array_slice( $items, 0, 8 ) as $it ) {
			$out .= '<li>';
			if ( 'linked' === $kind ) {
				// A link: the shared credential, then who is behind it.
				$via  = $via_label[ (string) ( $it['via'] ?? '' ) ] ?? (string) ( $it['via'] ?? '' );
				$val  = 'address' === ( $it['via'] ?? '' ) ? '' : (string) ( $it['value'] ?? '' );
				$card = (string) ( $it['action_title'] ?? '' );
				$out .= '<strong>' . esc_html( $via ) . '</strong>' . ( '' !== $val ? ' <code>' . esc_html( $val ) . '</code>' : '' );
				$out .= ' <span class="fs-muted">' . ( ! empty( $it['on_order'] ) ? esc_html__( '(on this attempt)', 'fraud-shield' ) : esc_html__( '(on an earlier order)', 'fraud-shield' ) ) . '</span><br/>';
				$out .= 1 === (int) ( $it['degree'] ?? 1 ) ? esc_html__( 'is itself on the blocklist', 'fraud-shield' ) : esc_html__( 'is also used by a blocked identity', 'fraud-shield' );
				if ( '' !== $card ) {
					$out .= ' — <a href="' . esc_url( add_query_arg( 's', rawurlencode( $card ), $blocklist ) ) . '">' . esc_html( $card ) . '</a>';
				}
				$out .= $this->link_identities_html( $it, __( 'Matched to:', 'fraud-shield' ) );
			} else {
				// A blocklist match: the credential, its provenance, and who it leads to.
				$find = add_query_arg( 's', rawurlencode( (string) ( $it['search'] ?? $it['value'] ?? '' ) ), $blocklist );
				$out .= '<strong>' . esc_html( (string) ( $it['label'] ?? '' ) ) . '</strong><br/>';
				$when = '' !== (string) ( $it['blocked_at'] ?? '' ) ? mysql2date( 'Y-m-d H:i', (string) $it['blocked_at'] ) : __( 'unknown date', 'fraud-shield' );
				$who  = '' !== (string) ( $it['blocked_by'] ?? '' ) ? (string) $it['blocked_by'] : __( 'unknown', 'fraud-shield' );
				/* translators: 1: date and time, 2: the staff member (or "Fraud Shield (automatic)") */
				$out .= esc_html( sprintf( __( 'Blocked %1$s by %2$s', 'fraud-shield' ), $when, $who ) );
				$cards = isset( $it['cards'] ) && is_array( $it['cards'] ) ? $it['cards'] : array();
				if ( ! empty( $cards ) ) {
					$titles = array();
					foreach ( $cards as $c ) {
						$t = (string) ( $c['title'] ?? '' );
						if ( '' !== (string) ( $c['created_by_name'] ?? '' ) && (string) $c['created_by_name'] !== $who ) {
							/* translators: 1: block card title, 2: who created the block */
							$t = sprintf( __( '%1$s (block by %2$s)', 'fraud-shield' ), $t, $c['created_by_name'] );
						}
						$titles[] = '<a href="' . esc_url( add_query_arg( 's', rawurlencode( (string) ( $c['title'] ?? '' ) ), $blocklist ) ) . '">' . esc_html( $t ) . '</a>';
					}
					$out .= '<br/><span class="fs-muted">' . esc_html__( 'Under:', 'fraud-shield' ) . '</span> ' . implode( '; ', $titles );
				}
				if ( '' !== (string) ( $it['reason'] ?? '' ) ) {
					/* translators: %s: the reason recorded when the credential was blocked */
					$out .= '<br/><span class="fs-muted">' . esc_html( sprintf( __( 'Reason: %s', 'fraud-shield' ), (string) $it['reason'] ) ) . '</span>';
				}
				$out .= $this->link_identities_html( $it, __( 'Matched to:', 'fraud-shield' ) );
				$out .= ' <a href="' . esc_url( $find ) . '">' . esc_html__( 'Find on Blocklist', 'fraud-shield' ) . '</a>';
			}
			$out .= '</li>';
		}
		if ( count( $items ) > 8 ) {
			$out .= '<li class="fs-muted">' . esc_html( sprintf( /* translators: %d: number of further matched credentials */ _n( 'and %d more', 'and %d more', count( $items ) - 8, 'fraud-shield' ), count( $items ) - 8 ) ) . '</li>';
		}
		$out .= '</ul>';
		if ( '' !== (string) ( $evidence['at'] ?? '' ) ) {
			/* translators: %s: date and time of the attempt the evidence describes */
			$out .= '<p class="fs-muted" style="margin:2px 0 0;font-size:11px;">' . esc_html( sprintf( __( 'As matched on the attempt at %s.', 'fraud-shield' ), mysql2date( 'Y-m-d H:i', (string) $evidence['at'] ) ) ) . '</p>';
		}
		return $out;
	}

	/**
	 * The "Refused at checkout" panel for a customer's profile or account view:
	 * every Checkout Locks ledger row for this identity — including rows staff
	 * have since dismissed — with the full evidence of what matched and whom
	 * it was matched to. Renders nothing when the customer was never refused.
	 *
	 * @param int    $uid   Account id (0 = guest).
	 * @param string $email Normalized email.
	 */
	private function render_refusals_panel( $uid, $email ) {
		$rows = FS_Data::checkout_blocks_for_identity( (int) $uid, (string) $email, 20 );
		if ( empty( $rows ) ) {
			return;
		}
		$labels = array(
			'velocity'  => __( 'Velocity lock', 'fraud-shield' ),
			'blocklist' => __( 'Blocklist', 'fraud-shield' ),
			'idv'       => __( 'ID requested', 'fraud-shield' ),
			'linked'    => __( 'Linked to the blocklist', 'fraud-shield' ),
		);
		?>
		<div class="fs-panel">
			<h2><?php esc_html_e( 'Refused at checkout', 'fraud-shield' ); ?> <span class="fs-pill fs-pill-red"><?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?></span></h2>
			<p class="description"><?php esc_html_e( 'Each time this customer was stopped at checkout, which control stopped them, and — for the blocklist and the linked-to-blocklist rule — exactly which credentials matched, when and by whom they were blocked, and the blocked person they were matched to. Kept even after the Checkout Locks row is dismissed.', 'fraud-shield' ); ?></p>
			<table class="wp-list-table widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Stopped by', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'What matched — and to whom', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Attempts', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'First', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Last', 'fraud-shield' ); ?></th>
					<th><?php esc_html_e( 'Status', 'fraud-shield' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $b ) : ?>
					<?php $html = $this->render_block_evidence( (array) $b['evidence'] ); ?>
					<tr>
						<td><strong><?php echo esc_html( $labels[ (string) $b['kind'] ] ?? (string) $b['kind'] ); ?></strong><?php echo ! empty( $b['detail'] ) ? '<br/><span class="fs-muted">' . esc_html( (string) $b['detail'] ) . '</span>' : ''; ?></td>
						<td><?php echo '' !== $html ? $html : '<span class="fs-muted">' . esc_html__( 'No detail recorded for this attempt (it predates the evidence record, or the control keeps none).', 'fraud-shield' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></td>
						<td><strong><?php echo (int) $b['attempts']; ?></strong></td>
						<td><?php echo esc_html( $b['first_at'] ? mysql2date( 'Y-m-d H:i', (string) $b['first_at'] ) : '—' ); ?></td>
						<td><?php echo esc_html( $b['last_at'] ? mysql2date( 'Y-m-d H:i', (string) $b['last_at'] ) : '—' ); ?></td>
						<td><?php echo empty( $b['resolved'] ) ? '<span class="fs-pill fs-pill-red">' . esc_html__( 'Open', 'fraud-shield' ) . '</span>' : '<span class="fs-pill">' . esc_html__( 'Dismissed / released', 'fraud-shield' ) . '</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ===================== Auto-Cancelled Orders (v1.26) ===================== */

	/**
	 * The accounts and profiles a link leads to, as a "Linked to:" line with a
	 * way into each: the customer profile (by email) and the account view (by
	 * id), and a BLOCKED pill on identities that are themselves on the list.
	 *
	 * @param array  $l     One link from FS_Blocklist::linked(), or a match item from match_evidence().
	 * @param string $label Lead-in text; defaults to "Linked to:".
	 * @return string HTML ('' when the link names nobody).
	 */
	private function link_identities_html( $l, $label = '' ) {
		$ids = isset( $l['identities'] ) && is_array( $l['identities'] ) ? $l['identities'] : array();
		if ( empty( $ids ) ) {
			return '';
		}
		if ( '' === $label ) {
			$label = __( 'Linked to:', 'fraud-shield' );
		}
		$parts = array();
		foreach ( $ids as $id ) {
			$email = (string) ( $id['email'] ?? '' );
			$uid   = (int) ( $id['user_id'] ?? 0 );
			$name  = trim( (string) ( $id['name'] ?? '' ) );
			$bits  = array();
			if ( '' !== $name ) {
				$bits[] = '<strong>' . esc_html( $name ) . '</strong>';
			}
			if ( '' !== $email ) {
				$bits[] = '<a href="' . esc_url( $this->profile_url( $email ) ) . '">' . esc_html( $email ) . '</a>';
			}
			if ( $uid > 0 ) {
				$bits[] = '<a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG . '-new-customers', 'fs_customer' => $uid ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( sprintf( /* translators: %d: account id */ __( 'account #%d', 'fraud-shield' ), $uid ) ) . '</a>';
			}
			if ( empty( $bits ) ) {
				continue;
			}
			$html = implode( ' ', $bits );
			if ( ! empty( $id['blocked'] ) ) {
				$html .= ' <span class="fs-pill fs-pill-black">' . esc_html__( 'BLOCKED', 'fraud-shield' ) . '</span>';
			}
			$parts[] = $html;
		}
		if ( empty( $parts ) ) {
			return '';
		}
		$out = '<br/><span class="fs-muted">' . esc_html( $label ) . '</span> ' . implode( '; ', $parts );
		if ( (int) ( $l['more'] ?? 0 ) > 0 ) {
			$out .= ' <span class="fs-muted">' . esc_html( sprintf( /* translators: %d: number of further identities */ _n( 'and %d other', 'and %d others', (int) $l['more'], 'fraud-shield' ), (int) $l['more'] ) ) . '</span>';
		}
		return $out;
	}

	/**
	 * Every order the linked-identity rule cancelled: who, what they shared
	 * with the blocklist, with whom, how many times they came back — with the
	 * one-click Block (make the link a verdict) and Verify (make it an
	 * exemption) toggles, so a row is never a dead end.
	 */
	public function page_linked_cancels() {
		$this->guard();
		$settings = FS_Core::instance()->settings();

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$per    = 50;

		$total = FS_Data::linked_cancels_count( $search );
		$rows  = FS_Data::linked_cancels_list(
			array(
				'search' => $search,
				'limit'  => $per,
				'offset' => ( $paged - 1 ) * $per,
			)
		);
		$pages = (int) ceil( $total / $per );
		$stats = FS_Data::linked_cancel_stats();

		// Button state for every customer on the page, in two queries.
		$emails         = array_values( array_unique( array_filter( wp_list_pluck( $rows, 'email_norm' ) ) ) );
		$profile_acts   = FS_Blocklist::actions_for_targets( 'profile', $emails );
		$blocked_emails = array_flip( FS_Blocklist::blocked_values( 'email', $emails ) );
		$verified_creds = FS_Live::verified_creds();

		$via_labels = array(
			'email'   => __( 'Email', 'fraud-shield' ),
			'phone'   => __( 'Phone', 'fraud-shield' ),
			'ip'      => __( 'IP', 'fraud-shield' ),
			'address' => __( 'Shipping address', 'fraud-shield' ),
			'user'    => __( 'Account', 'fraud-shield' ),
		);
		$blocklist_url = admin_url( 'admin.php?page=' . self::SLUG . '-blocklist' );
		$base_args     = array(
			'page' => self::SLUG . '-cancels',
			's'    => $search,
		);
		?>
		<div class="wrap fs-wrap">
			<h1><?php esc_html_e( 'Auto-Cancelled Orders', 'fraud-shield' ); ?></h1>
			<?php $this->maybe_notice(); ?>

			<p class="description"><?php esc_html_e( 'Orders Fraud Shield cancelled the moment they were placed because the customer shares a credential with a blocked identity — the same shipping address, phone or account as someone on the blocklist, on this order or on an earlier one (an IP counts only alongside one of those) — without themselves matching the blocklist. Each row names the accounts and profiles the shared credentials lead to. Each order was cancelled before any payment was taken; the customer saw the message set under Settings. The same orders carry an "Auto-cancelled" badge in the Fraud Shield column of WooCommerce → Orders.', 'fraud-shield' ); ?></p>

			<?php if ( empty( $settings['linked_cancel_enabled'] ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: settings page URL */
							__( 'This rule is currently switched off, so no new orders are cancelled this way; anything below is history. Turn it on under <a href="%s">Settings → Cancel orders linked to the blocklist</a>.', 'fraud-shield' ),
							esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) )
						)
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="fs-cards">
				<div class="fs-card fs-card-red"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Orders cancelled', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['last_30d'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Last 30 days', 'fraud-shield' ); ?></div></div>
				<div class="fs-card"><div class="fs-num"><?php echo wp_kses_post( wc_price( $stats['value'], '' !== $stats['currency'] ? array( 'currency' => $stats['currency'] ) : array() ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Order value stopped', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-amber"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['retries'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Repeat attempts refused', 'fraud-shield' ); ?></div></div>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Cancelled orders', 'fraud-shield' ); ?> <span class="fs-pill"><?php echo esc_html( number_format_i18n( $total ) ); ?></span></h2>

				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-cancels' ); ?>" />
					<p class="fs-filters">
						<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email, name, or order number…', 'fraud-shield' ); ?>" class="regular-text" />
						<button class="button"><?php esc_html_e( 'Search', 'fraud-shield' ); ?></button>
						<?php if ( '' !== $search ) : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-cancels' ) ); ?>"><?php esc_html_e( 'Clear', 'fraud-shield' ); ?></a>
						<?php endif; ?>
						<a class="button" href="<?php echo esc_url( $this->export_url( 'cancels', array( 's' => $search ) ) ); ?>"><?php esc_html_e( 'Export CSV (Excel)', 'fraud-shield' ); ?></a>
					</p>
				</form>

				<table class="wp-list-table widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Cancelled', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Order', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Total', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Shared with the blocklist', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Attempts', 'fraud-shield' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7">
							<?php
							if ( '' !== $search ) {
								esc_html_e( 'No cancelled orders match that search.', 'fraud-shield' );
							} elseif ( empty( $settings['linked_cancel_enabled'] ) ) {
								esc_html_e( 'Nothing yet. Once the rule is on, every order it cancels is listed here the moment it happens.', 'fraud-shield' );
							} else {
								esc_html_e( 'Nothing yet. No order from a customer linked to the blocklist has been placed since the rule was switched on.', 'fraud-shield' );
							}
							?>
						</td></tr>
					<?php endif; ?>
					<?php
					foreach ( $rows as $r ) :
						$oid      = (int) $r['order_id'];
						$uid      = (int) $r['user_id'];
						$email    = (string) $r['email_norm'];
						$order    = wc_get_order( $oid );
						$status   = $order ? $order->get_status() : '';
						$attempts = max( 1, (int) $r['attempts'] );
						$links    = (array) $r['links'];
						$is_v     = '' !== $email && isset( $verified_creds[ 'email:' . $email ] );
						?>
						<tr class="fs-row-flag">
							<td><?php echo esc_html( $r['cancelled_at'] ? mysql2date( 'Y-m-d H:i', (string) $r['cancelled_at'] ) : '—' ); ?></td>
							<td>
								<a href="<?php echo esc_url( $this->order_edit_url( $oid ) ); ?>"><strong>#<?php echo (int) $oid; ?></strong></a><br/>
								<?php if ( ! $order ) : ?>
									<span class="fs-muted"><?php esc_html_e( 'order deleted', 'fraud-shield' ); ?></span>
								<?php elseif ( 'cancelled' === $status ) : ?>
									<span class="fs-pill fs-pill-black"><?php echo esc_html( wc_get_order_status_name( $status ) ); ?></span>
								<?php else : ?>
									<span class="fs-pill fs-pill-amber" title="<?php esc_attr_e( 'The order has since been moved out of Cancelled by staff or a payment update.', 'fraud-shield' ); ?>"><?php echo esc_html( wc_get_order_status_name( $status ) ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' !== $email ) : ?>
									<a href="<?php echo esc_url( $this->profile_url( $email ) ); ?>"><strong><?php echo esc_html( '' !== (string) $r['customer_name'] ? (string) $r['customer_name'] : $email ); ?></strong></a>
									<br/><span class="fs-muted"><?php echo esc_html( $email ); ?></span>
								<?php else : ?>
									<strong><?php echo esc_html( '' !== (string) $r['customer_name'] ? (string) $r['customer_name'] : __( '(no email)', 'fraud-shield' ) ); ?></strong>
								<?php endif; ?>
								<?php if ( $uid > 0 ) : ?>
									<br/><a class="fs-muted" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG . '-new-customers', 'fs_customer' => $uid ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: account id */ __( 'account #%d', 'fraud-shield' ), $uid ) ); ?></a>
								<?php else : ?>
									<br/><span class="fs-muted"><?php esc_html_e( 'guest', 'fraud-shield' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo wp_kses_post( wc_price( (float) $r['order_total'], '' !== (string) $r['currency'] ? array( 'currency' => (string) $r['currency'] ) : array() ) ); ?></td>
							<td class="fs-why">
								<?php if ( empty( $links ) ) : ?>
									<?php echo esc_html( (string) $r['summary'] ); ?>
								<?php else : ?>
									<ul>
									<?php foreach ( array_slice( $links, 0, 6 ) as $l ) : ?>
										<li>
											<?php
											$via  = isset( $via_labels[ $l['via'] ?? '' ] ) ? $via_labels[ $l['via'] ] : (string) ( $l['via'] ?? '' );
											$val  = 'address' === ( $l['via'] ?? '' ) ? '' : (string) ( $l['value'] ?? '' );
											$card = (string) ( $l['action_title'] ?? '' );
											if ( '' === $card ) {
												$card = FS_Blocklist::entry_label( (string) ( $l['blocked_type'] ?? '' ), (string) ( $l['blocked_value'] ?? '' ) );
											}
											echo '<strong>' . esc_html( $via ) . '</strong>';
											if ( '' !== $val ) {
												echo ' <code>' . esc_html( $val ) . '</code>';
											}
											echo ! empty( $l['on_order'] ) ? ' <span class="fs-muted">' . esc_html__( '(on this order)', 'fraud-shield' ) . '</span>' : ' <span class="fs-muted">' . esc_html__( '(on an earlier order)', 'fraud-shield' ) . '</span>';
											echo '<br/>';
											if ( 1 === (int) ( $l['degree'] ?? 1 ) ) {
												echo esc_html__( 'is itself on the blocklist:', 'fraud-shield' );
											} else {
												echo esc_html__( 'is also used by blocked', 'fraud-shield' );
											}
											echo ' <a href="' . esc_url( $blocklist_url ) . '">' . esc_html( $card ) . '</a>';
											echo $this->link_identities_html( $l ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
											?>
										</li>
									<?php endforeach; ?>
									<?php if ( count( $links ) > 6 ) : ?>
										<li class="fs-muted"><?php echo esc_html( sprintf( /* translators: %d: number of further links */ _n( 'and %d more link', 'and %d more links', count( $links ) - 6, 'fraud-shield' ), count( $links ) - 6 ) ); ?></li>
									<?php endif; ?>
									</ul>
								<?php endif; ?>
							</td>
							<td>
								<strong><?php echo (int) $attempts; ?></strong>
								<?php if ( $attempts > 1 ) : ?>
									<br/><span class="fs-muted"><?php echo esc_html( sprintf( /* translators: 1: number of refused retries, 2: date/time of the last one */ _n( '%1$d retry refused, last %2$s', '%1$d retries refused, last %2$s', $attempts - 1, 'fraud-shield' ), $attempts - 1, $r['last_attempt_at'] ? mysql2date( 'Y-m-d H:i', (string) $r['last_attempt_at'] ) : '—' ) ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' !== $email ) : ?>
									<?php echo $this->block_profile_button( $email, $profile_acts[ $email ] ?? null, isset( $blocked_emails[ $email ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<?php echo $this->verify_button( 'email', $email, $is_v ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( $base_args, admin_url( 'admin.php' ) ) . '%_%',
								'format'    => '&paged=%#%',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
					</div></div>
				<?php endif; ?>

				<p class="description"><?php esc_html_e( '"Block" turns the link into a verdict: the customer\'s email and every credential they ever used join the blocklist, so their next attempt is refused before an order exists. "Verify" does the opposite: a verified email is exempt from this rule (and from the velocity lock, holds and gateway restrictions — never from the blocklist itself), so a customer you have confirmed is legitimate can order again. Attempts counts the checkouts refused after the cancel: while the link stands, a customer who tries again is stopped without creating another order.', 'fraud-shield' ); ?></p>
			</div>
		</div>
		<?php
	}

	/* ===================== High-Value Review (v1.32) ===================== */

	/**
	 * The menu label, with the number of high-value orders still awaiting a
	 * look this week as a bubble — the device WordPress uses for pending
	 * comments, so the queue is visible from every admin screen.
	 *
	 * @return string
	 */
	private function hv_menu_label() {
		$label  = __( 'High-Value Review', 'fraud-shield' );
		$amount = FS_Live::hv_review_amount();
		if ( $amount <= 0 ) {
			return $label;
		}
		$counts = FS_Data::hv_unreviewed_counts( $amount );
		if ( $counts['week'] > 0 ) {
			$label .= ' <span class="awaiting-mod count-' . (int) $counts['week'] . '"><span class="pending-count">' . esc_html( number_format_i18n( $counts['week'] ) ) . '</span></span>';
		}
		return $label;
	}

	/**
	 * URL of the review page with the given state.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	private function hv_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG . '-high-value' ), array_filter( $args, 'strlen' ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * Verdict pill markup.
	 *
	 * @param string $verdict cleared | held | cancelled.
	 * @return string
	 */
	private function hv_verdict_pill( $verdict ) {
		$v = FS_Data::hv_verdicts()[ $verdict ] ?? null;
		if ( ! $v ) {
			return '<span class="fs-pill">' . esc_html( (string) $verdict ) . '</span>';
		}
		return '<span class="fs-pill ' . esc_attr( $v['pill'] ) . '">' . esc_html( $v['short'] ) . '</span>';
	}

	/**
	 * The verdict form for one queue row: an optional note and the three
	 * buttons. Holding and cancelling confirm first, because they change the
	 * order. Posts to admin-post.php and returns to the page as it was.
	 *
	 * @param int    $oid  Order id.
	 * @param string $back URL to return to.
	 */
	private function hv_verdict_form( $oid, $back ) {
		$verdicts = FS_Data::hv_verdicts();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fs-hv-form">
			<input type="hidden" name="action" value="fs_hv_review" />
			<input type="hidden" name="order_id" value="<?php echo (int) $oid; ?>" />
			<input type="hidden" name="redirect" value="<?php echo esc_url( $back ); ?>" />
			<?php wp_nonce_field( 'fs_hv_review_' . (int) $oid ); ?>
			<input type="text" name="note" class="fs-hv-note" maxlength="1000" placeholder="<?php esc_attr_e( 'Optional note (what you checked, what you found)', 'fraud-shield' ); ?>" />
			<div class="fs-hv-buttons">
				<button class="button button-primary button-small" name="verdict" value="cleared"><?php echo esc_html( $verdicts['cleared']['label'] ); ?></button>
				<button class="button button-small" name="verdict" value="held" onclick="return confirm('<?php echo esc_js( __( 'Mark this order as "something off" and set it On-Hold so the package does not ship?', 'fraud-shield' ) ); ?>');"><?php echo esc_html( $verdicts['held']['label'] ); ?></button>
				<button class="button button-small button-link-delete" name="verdict" value="cancelled" onclick="return confirm('<?php echo esc_js( __( 'Cancel this order now? This cancels it in WooCommerce; it does NOT refund the payment — issue any refund from the order screen.', 'fraud-shield' ) ); ?>');"><?php echo esc_html( $verdicts['cancelled']['label'] ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * High-Value Review: every order at or above the review amount, placed
	 * today (or in the chosen window), for a person to look at and decide on.
	 * Each row carries what a reviewer needs to triage without leaving the
	 * page — who, where it ships, from which IP, how the customer has behaved
	 * before, the risk score and its reasons, who they are connected to — and
	 * a way into every deeper view (the order, the profile, the network, the
	 * address, the IP, the account). Below the queue: what was reviewed in
	 * the window and by whom, and a leaderboard of reviewers.
	 */
	public function page_high_value() {
		$this->guard();
		$amount = FS_Live::hv_review_amount();

		$win_key  = isset( $_GET['win'] ) ? sanitize_key( wp_unslash( $_GET['win'] ) ) : 'today'; // phpcs:ignore WordPress.Security.NonceVerification
		$win      = FS_Data::hv_window( $win_key );
		$show     = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : 'unreviewed'; // phpcs:ignore WordPress.Security.NonceVerification
		$show     = in_array( $show, array( 'unreviewed', 'reviewed', 'all' ), true ) ? $show : 'unreviewed';
		$dead     = ! empty( $_GET['dead'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$sort     = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'date'; // phpcs:ignore WordPress.Security.NonceVerification
		$sort     = in_array( $sort, array( 'date', 'total', 'risk', 'reviewed' ), true ) ? $sort : 'date';
		$dir      = ( isset( $_GET['dir'] ) && 'asc' === $_GET['dir'] ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification
		$paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$rpaged   = max( 1, isset( $_GET['rpaged'] ) ? (int) $_GET['rpaged'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$reviewer = ( isset( $_GET['reviewer'] ) && '' !== $_GET['reviewer'] ) ? (int) $_GET['reviewer'] : null; // phpcs:ignore WordPress.Security.NonceVerification
		$per      = 50;

		$base = array(
			'page'     => self::SLUG . '-high-value',
			'win'      => $win['key'],
			'show'     => $show,
			'dead'     => $dead ? '1' : '',
			's'        => $search,
			'sort'     => $sort,
			'dir'      => $dir,
			'reviewer' => null === $reviewer ? '' : (string) $reviewer,
		);
		$base = array_filter( $base, 'strlen' );
		$here = add_query_arg( array_merge( $base, array_filter( array( 'paged' => $paged > 1 ? $paged : '', 'rpaged' => $rpaged > 1 ? $rpaged : '' ), 'strlen' ) ), admin_url( 'admin.php' ) );

		$verdicts = FS_Data::hv_verdicts();
		$windows  = FS_Data::hv_windows();
		$currency = function_exists( 'get_woocommerce_currency' ) ? array( 'currency' => get_woocommerce_currency() ) : array();
		?>
		<div class="wrap fs-wrap fs-hv">
			<h1><?php esc_html_e( 'High-Value Review', 'fraud-shield' ); ?></h1>
			<?php $this->maybe_notice(); ?>

			<?php if ( $amount <= 0 ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: settings page URL */
							__( 'The review amount is 0, so this page is switched off. Set the order total that counts as high value under <a href="%s">Settings → High-value order review</a> and every order at or above it will be listed here for review.', 'fraud-shield' ),
							esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) )
						)
					);
					?>
				</p></div>
			</div>
			<?php
			return;
		endif;

		$q_args = array(
			'amount'       => $amount,
			'from'         => $win['from'],
			'to'           => $win['to'],
			'show'         => $show,
			'include_dead' => $dead,
			'search'       => $search,
			'orderby'      => $sort,
			'dir'          => $dir,
			'limit'        => $per,
			'offset'       => ( $paged - 1 ) * $per,
		);
		$rows   = FS_Data::hv_queue_list( $q_args );
		$total  = FS_Data::hv_queue_count( $q_args );
		$pages  = (int) ceil( $total / $per );
		$stats  = FS_Data::hv_queue_stats( $amount, $win );

		// Everything a row shows beyond its own index columns, in batch:
		// the customer's history, their connections (and the strongest link
		// to a blocked customer), whether they are blocked or verified.
		$emails         = array_values( array_unique( array_filter( wp_list_pluck( $rows, 'email_norm' ) ) ) );
		$facts          = FS_Data::hv_customer_facts( $emails );
		$conn           = FS_Insights::connections_batch( $emails );
		$blocked_emails = array_flip( FS_Blocklist::blocked_values( 'email', $emails ) );
		$profile_acts   = FS_Blocklist::actions_for_targets( 'profile', $emails );
		$vcreds         = FS_Live::verified_creds();
		$edge_types     = FS_Insights::edge_types();

		$r_args  = array(
			'from'        => $win['from'],
			'to'          => $win['to'],
			'reviewer_id' => $reviewer,
			'limit'       => $per,
			'offset'      => ( $rpaged - 1 ) * $per,
		);
		$reviews = FS_Data::hv_reviews_list( $r_args );
		$rtotal  = FS_Data::hv_reviews_count( $r_args );
		$rpages  = (int) ceil( $rtotal / $per );
		$board   = FS_Data::hv_leaderboard( $win['from'], $win['to'] );
		$board_n = 0;
		foreach ( $board as $b ) {
			$board_n += (int) $b['reviews'];
		}
		?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: formatted amount */
						__( 'Every order of %s or more, for a person to look at before it ships. Open the customer\'s profile, their address, their IP and who they are connected to, then record what you found: Looks good leaves the order alone; Something off sets it On-Hold so the package does not ship; Cancel now cancels it. Every verdict is noted on the order with your name, listed below under Reviewed, and counted on the leaderboard.', 'fraud-shield' ),
						html_entity_decode( wp_strip_all_tags( wc_price( $amount, $currency ) ) )
					)
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ); ?>"><?php esc_html_e( 'Change the amount', 'fraud-shield' ); ?></a>
			</p>

			<h2 class="nav-tab-wrapper fs-hv-tabs">
				<?php foreach ( $windows as $k => $label ) : ?>
					<a class="nav-tab <?php echo $k === $win['key'] ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $this->hv_url( array_merge( $base, array( 'win' => $k ) ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
				<span class="fs-hv-range"><?php echo esc_html( mysql2date( 'D j M', $win['from'] ) . ' – ' . mysql2date( 'D j M', gmdate( 'Y-m-d H:i:s', strtotime( $win['to'] . ' UTC' ) - 1 ) ) ); ?></span>
			</h2>

			<div class="fs-cards">
				<a class="fs-card fs-card-link <?php echo $stats['unreviewed'] > 0 ? 'fs-card-amber' : 'fs-card-green'; ?>" href="<?php echo esc_url( $this->hv_url( array_merge( $base, array( 'show' => 'unreviewed' ) ) ) ); ?>">
					<div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['unreviewed'] ) ); ?></div>
					<div class="fs-label"><?php esc_html_e( 'Awaiting review', 'fraud-shield' ); ?></div>
					<div class="fs-card-sub"><?php echo wp_kses_post( wc_price( $stats['unreviewed_value'], $currency ) ); ?></div>
				</a>
				<a class="fs-card fs-card-link" href="<?php echo esc_url( $this->hv_url( array_merge( $base, array( 'show' => 'reviewed' ) ) ) ); ?>">
					<div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['reviewed'] ) ); ?></div>
					<div class="fs-label"><?php esc_html_e( 'Reviewed', 'fraud-shield' ); ?></div>
					<div class="fs-card-sub"><?php echo esc_html( sprintf( /* translators: %s: number of orders */ _n( 'of %s high-value order', 'of %s high-value orders', $stats['total'], 'fraud-shield' ), number_format_i18n( $stats['total'] ) ) ); ?></div>
				</a>
				<div class="fs-card fs-card-green"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['cleared'] ) ); ?></div><div class="fs-label"><?php echo esc_html( $verdicts['cleared']['label'] ); ?></div></div>
				<div class="fs-card fs-card-amber"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['held'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Held', 'fraud-shield' ); ?></div></div>
				<div class="fs-card fs-card-red"><div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['cancelled'] ) ); ?></div><div class="fs-label"><?php esc_html_e( 'Cancelled', 'fraud-shield' ); ?></div></div>
				<a class="fs-card fs-card-link <?php echo $stats['backlog'] > 0 ? 'fs-card-red' : ''; ?>" href="<?php echo esc_url( $this->hv_url( array( 'win' => '90d', 'show' => 'unreviewed', 'sort' => 'date', 'dir' => 'asc' ) ) ); ?>" title="<?php esc_attr_e( 'Live high-value orders placed before this window (within the previous 90 days) that nobody has reviewed. Click to see the last 90 days, oldest first.', 'fraud-shield' ); ?>">
					<div class="fs-num"><?php echo esc_html( number_format_i18n( $stats['backlog'] ) ); ?></div>
					<div class="fs-label"><?php esc_html_e( 'Older, still unreviewed', 'fraud-shield' ); ?></div>
					<div class="fs-card-sub">
						<?php
						if ( '' !== $stats['oldest_unreviewed'] ) {
							echo esc_html( sprintf( /* translators: %s: date */ __( 'oldest from %s', 'fraud-shield' ), mysql2date( 'Y-m-d', $stats['oldest_unreviewed'] ) ) );
						} else {
							esc_html_e( 'nothing left behind', 'fraud-shield' );
						}
						?>
					</div>
				</a>
			</div>

			<div class="fs-panel">
				<h2>
					<?php
					if ( 'unreviewed' === $show ) {
						esc_html_e( 'Awaiting review', 'fraud-shield' );
					} elseif ( 'reviewed' === $show ) {
						esc_html_e( 'Reviewed orders', 'fraud-shield' );
					} else {
						esc_html_e( 'All high-value orders', 'fraud-shield' );
					}
					?>
					<span class="fs-panel-period"><?php echo esc_html( $win['label'] ); ?></span>
					<span class="fs-pill"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
				</h2>

				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-high-value' ); ?>" />
					<input type="hidden" name="win" value="<?php echo esc_attr( $win['key'] ); ?>" />
					<input type="hidden" name="sort" value="<?php echo esc_attr( $sort ); ?>" />
					<input type="hidden" name="dir" value="<?php echo esc_attr( $dir ); ?>" />
					<p class="fs-filters">
						<label><?php esc_html_e( 'Show', 'fraud-shield' ); ?>
							<select name="show">
								<option value="unreviewed" <?php selected( $show, 'unreviewed' ); ?>><?php esc_html_e( 'Awaiting review', 'fraud-shield' ); ?></option>
								<option value="reviewed" <?php selected( $show, 'reviewed' ); ?>><?php esc_html_e( 'Reviewed', 'fraud-shield' ); ?></option>
								<option value="all" <?php selected( $show, 'all' ); ?>><?php esc_html_e( 'All', 'fraud-shield' ); ?></option>
							</select>
						</label>
						<label title="<?php esc_attr_e( 'Unreviewed orders that are cancelled, failed, refunded or charged back ship nothing, so they are hidden unless you ask. Reviewed orders always show, whatever their status.', 'fraud-shield' ); ?>"><input type="checkbox" name="dead" value="1" <?php checked( $dead ); ?> /> <?php esc_html_e( 'Include cancelled / failed', 'fraud-shield' ); ?></label>
						<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, phone, IP, address or order #…', 'fraud-shield' ); ?>" class="regular-text" />
						<button class="button"><?php esc_html_e( 'Apply', 'fraud-shield' ); ?></button>
						<?php if ( '' !== $search || $dead || 'unreviewed' !== $show ) : ?>
							<a class="button" href="<?php echo esc_url( $this->hv_url( array( 'win' => $win['key'] ) ) ); ?>"><?php esc_html_e( 'Reset', 'fraud-shield' ); ?></a>
						<?php endif; ?>
						<a class="button" href="<?php echo esc_url( $this->export_url( 'hv', array( 'win' => $win['key'], 'show' => $show, 'dead' => $dead ? '1' : '', 's' => $search, 'sort' => $sort, 'dir' => $dir ) ) ); ?>"><?php esc_html_e( 'Export CSV (Excel)', 'fraud-shield' ); ?></a>
					</p>
				</form>

				<table class="wp-list-table widefat striped fs-hv-table">
					<thead><tr>
						<?php $this->sort_th( __( 'Placed', 'fraud-shield' ), 'date', $sort, $dir, $base, '', 'desc' ); ?>
						<th><?php esc_html_e( 'Order', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
						<?php $this->sort_th( __( 'Total', 'fraud-shield' ), 'total', $sort, $dir, $base, '', 'desc' ); ?>
						<th><?php esc_html_e( 'Ships to / placed from', 'fraud-shield' ); ?></th>
						<?php $this->sort_th( __( 'Risk', 'fraud-shield' ), 'risk', $sort, $dir, $base, '', 'desc' ); ?>
						<th><?php esc_html_e( 'Connections', 'fraud-shield' ); ?></th>
						<th class="fs-hv-review-col"><?php esc_html_e( 'Review', 'fraud-shield' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="8">
							<?php
							if ( '' !== $search ) {
								esc_html_e( 'No high-value orders match that search in this window.', 'fraud-shield' );
							} elseif ( 'unreviewed' === $show ) {
								echo esc_html( sprintf( /* translators: %s: window label, lower-case */ __( 'Nothing awaiting review for %s — every high-value order in this window has been looked at.', 'fraud-shield' ), mb_strtolower( $win['label'] ) ) );
							} else {
								echo esc_html( sprintf( /* translators: %s: window label, lower-case */ __( 'No high-value orders for %s.', 'fraud-shield' ), mb_strtolower( $win['label'] ) ) );
							}
							?>
						</td></tr>
					<?php endif; ?>
					<?php
					foreach ( $rows as $r ) :
						$oid     = (int) $r['order_id'];
						$uid     = (int) $r['user_id'];
						$email   = (string) $r['email_norm'];
						$name    = trim( (string) $r['customer_name'] );
						$status  = (string) $r['status'];
						$level   = (string) $r['risk_level'];
						$reasons = json_decode( (string) $r['risk_reasons'], true );
						$reasons = is_array( $reasons ) ? array_values( array_filter( array_map( 'strval', $reasons ) ) ) : array();
						$fact    = $facts[ $email ] ?? array( 'orders' => 0, 'chargebacks' => 0, 'spend' => 0.0, 'first_order' => '' );
						$c       = $conn[ $email ] ?? array( 'connections' => 0, 'strong' => 0, 'strongest' => '', 'blocked_via' => null, 'blocked_strength' => null, 'blocked_with' => null );
						$is_v    = ( '' !== $email && isset( $vcreds[ 'email:' . $email ] ) ) || ( $uid > 0 && FS_Live::is_verified_customer( $uid ) );
						$is_b    = '' !== $email && isset( $blocked_emails[ $email ] );
						$user    = $uid > 0 ? get_userdata( $uid ) : false;
						$age     = ( $user && ! empty( $user->user_registered ) ) ? max( 0, (int) floor( ( time() - strtotime( $user->user_registered . ' UTC' ) ) / DAY_IN_SECONDS ) ) : null;
						$dead_st = in_array( $status, FS_Data::hv_dead_statuses(), true );
						$cls     = $is_b ? 'fs-level-block' : ( in_array( $level, array( 'high', 'block' ), true ) ? 'fs-level-' . $level : ( 'medium' === $level ? 'fs-level-medium' : '' ) );
						$ship    = '' !== (string) $r['ship_text'] ? (string) $r['ship_text'] : (string) $r['shipping_country'];
						$loc     = $this->ip_location_label( (string) $r['ip'] );
						$diverge = '' !== (string) $r['bill_hash'] && '' !== (string) $r['ship_hash'] && (string) $r['bill_hash'] !== (string) $r['ship_hash'];
						?>
						<tr class="<?php echo esc_attr( $cls ); ?>">
							<td>
								<?php echo esc_html( $r['order_date'] ? mysql2date( 'Y-m-d', (string) $r['order_date'] ) : '—' ); ?><br/>
								<span class="fs-muted"><?php echo esc_html( $r['order_date'] ? mysql2date( 'H:i', (string) $r['order_date'] ) : '' ); ?></span>
							</td>
							<td>
								<a href="<?php echo esc_url( $this->order_edit_url( $oid ) ); ?>"><strong>#<?php echo (int) $oid; ?></strong></a><br/>
								<span class="fs-pill <?php echo $dead_st ? 'fs-pill-black' : ( in_array( $status, FS_Data::paid_statuses(), true ) ? 'fs-pill-green' : ( 'on-hold' === $status ? 'fs-pill-amber' : '' ) ); ?>"><?php echo esc_html( function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) : $status ); ?></span>
								<?php if ( ! empty( $r['payment_method'] ) ) : ?>
									<br/><span class="fs-muted"><?php echo esc_html( (string) $r['payment_method'] ); ?></span>
								<?php endif; ?>
								<?php if ( $r['is_chargeback'] ) : ?>
									<br/><span class="fs-pill fs-pill-red"><?php esc_html_e( 'Charged back', 'fraud-shield' ); ?></span>
								<?php endif; ?>
								<p class="fs-hv-links">
									<button type="button" class="button-link fs-expand" data-order="<?php echo esc_attr( $oid ); ?>" data-label-open="<?php esc_attr_e( '▸ Items & addresses', 'fraud-shield' ); ?>" data-label-close="<?php esc_attr_e( '▾ Hide', 'fraud-shield' ); ?>"><?php esc_html_e( '▸ Items & addresses', 'fraud-shield' ); ?></button>
								</p>
							</td>
							<td>
								<?php if ( '' !== $email ) : ?>
									<a href="<?php echo esc_url( $this->profile_url( $email ) ); ?>"><strong><?php echo esc_html( '' !== $name ? $name : $email ); ?></strong></a>
									<br/><span class="fs-muted"><?php echo esc_html( $email ); ?></span>
								<?php else : ?>
									<strong><?php echo esc_html( '' !== $name ? $name : __( '(no email)', 'fraud-shield' ) ); ?></strong>
								<?php endif; ?>
								<?php if ( ! empty( $r['phone_norm'] ) ) : ?>
									<br/><span class="fs-muted"><?php echo esc_html( (string) $r['phone_norm'] ); ?></span>
								<?php endif; ?>
								<br/>
								<?php if ( $uid > 0 ) : ?>
									<a class="fs-muted" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG . '-new-customers', 'fs_customer' => $uid ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: account id */ __( 'account #%d', 'fraud-shield' ), $uid ) ); ?></a>
									<?php if ( null !== $age ) : ?>
										<span class="fs-muted">· <?php echo esc_html( sprintf( /* translators: %s: number of days */ _n( 'registered %s day ago', 'registered %s days ago', $age, 'fraud-shield' ), number_format_i18n( $age ) ) ); ?></span>
									<?php endif; ?>
								<?php else : ?>
									<span class="fs-muted"><?php esc_html_e( 'guest checkout', 'fraud-shield' ); ?></span>
								<?php endif; ?>
								<div class="fs-hv-pills">
									<?php if ( $is_b ) : ?>
										<span class="fs-pill fs-pill-black"><?php esc_html_e( 'Blocked', 'fraud-shield' ); ?></span>
									<?php endif; ?>
									<?php if ( $is_v ) : ?>
										<span class="fs-pill fs-pill-green"><?php esc_html_e( 'Verified', 'fraud-shield' ); ?></span>
									<?php endif; ?>
									<?php if ( null !== $age && $age <= 30 ) : ?>
										<span class="fs-pill fs-pill-amber"><?php esc_html_e( 'New account', 'fraud-shield' ); ?></span>
									<?php endif; ?>
									<?php if ( (int) $fact['orders'] <= 1 ) : ?>
										<span class="fs-pill fs-pill-amber"><?php esc_html_e( 'First order', 'fraud-shield' ); ?></span>
									<?php else : ?>
										<span class="fs-pill"><?php echo esc_html( sprintf( /* translators: 1: order count, 2: formatted settled spend */ __( '%1$s orders · %2$s settled', 'fraud-shield' ), number_format_i18n( (int) $fact['orders'] ), html_entity_decode( wp_strip_all_tags( wc_price( (float) $fact['spend'], $currency ) ) ) ) ); ?></span>
									<?php endif; ?>
									<?php if ( (int) $fact['chargebacks'] > 0 ) : ?>
										<span class="fs-pill fs-pill-red"><?php echo esc_html( sprintf( /* translators: %s: number of chargebacks */ _n( '%s chargeback', '%s chargebacks', (int) $fact['chargebacks'], 'fraud-shield' ), number_format_i18n( (int) $fact['chargebacks'] ) ) ); ?></span>
									<?php endif; ?>
								</div>
							</td>
							<td><strong><?php echo wp_kses_post( wc_price( (float) $r['order_total'], $currency ) ); ?></strong></td>
							<td class="fs-hv-where">
								<span class="fs-addr"><?php echo esc_html( $ship ); ?></span>
								<?php if ( $diverge ) : ?>
									<br/><span class="fs-pill fs-pill-amber" title="<?php esc_attr_e( 'The billing address differs from the shipping address.', 'fraud-shield' ); ?>"><?php esc_html_e( 'Billing ≠ shipping', 'fraud-shield' ); ?></span>
								<?php endif; ?>
								<br/><span class="fs-muted">
									<?php
									echo esc_html( ! empty( $r['ip'] ) ? (string) $r['ip'] : __( 'IP unknown', 'fraud-shield' ) );
									if ( '' !== $loc ) {
										echo ' · ' . esc_html( $loc );
									}
									if ( ! empty( $r['shipping_country'] ) && '' !== $loc && false === stripos( $loc, '(' . (string) $r['shipping_country'] . ')' ) ) {
										echo ' <span class="fs-pill fs-pill-amber" title="' . esc_attr__( 'The IP geolocates to a different country than the one the order ships to.', 'fraud-shield' ) . '">' . esc_html__( 'IP ≠ ship country', 'fraud-shield' ) . '</span>';
									}
									?>
								</span>
								<p class="fs-hv-links">
									<?php if ( '' !== (string) $r['ship_hash'] ) : ?>
										<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'fs_view' => 'address', 'fs_key' => (string) $r['ship_hash'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Everyone at this address', 'fraud-shield' ); ?></a>
									<?php endif; ?>
									<?php if ( ! empty( $r['ip'] ) ) : ?>
										<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'fs_view' => 'ip', 'fs_key' => (string) $r['ip'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Everyone on this IP', 'fraud-shield' ); ?></a>
									<?php endif; ?>
								</p>
							</td>
							<td class="fs-why">
								<?php echo $this->level_pill( $level ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo (int) $r['risk_score']; ?>
								<?php if ( ! empty( $reasons ) ) : ?>
									<ul>
										<?php foreach ( array_slice( $reasons, 0, 3 ) as $reason ) : ?>
											<li><?php echo esc_html( $reason ); ?></li>
										<?php endforeach; ?>
										<?php if ( count( $reasons ) > 3 ) : ?>
											<li class="fs-muted"><?php echo esc_html( sprintf( /* translators: %d: number of further reasons */ _n( 'and %d more', 'and %d more', count( $reasons ) - 3, 'fraud-shield' ), count( $reasons ) - 3 ) ); ?></li>
										<?php endif; ?>
									</ul>
								<?php endif; ?>
							</td>
							<td class="fs-hv-conn">
								<?php if ( '' === $email ) : ?>
									<span class="fs-muted">—</span>
								<?php else : ?>
									<a href="<?php echo esc_url( $this->network_url( $email ) ); ?>">
										<?php
										if ( (int) $c['connections'] > 0 ) {
											echo esc_html( sprintf( /* translators: 1: number of connected customers, 2: how many through a strong link */ __( '%1$s connected (%2$s strong)', 'fraud-shield' ), number_format_i18n( (int) $c['connections'] ), number_format_i18n( (int) $c['strong'] ) ) );
										} else {
											esc_html_e( 'No connections', 'fraud-shield' );
										}
										?>
									</a>
									<?php if ( null !== $c['blocked_with'] ) : ?>
										<br/><span class="fs-hv-linked"><?php echo esc_html( sprintf( /* translators: 1: how the link is made (e.g. "Phone number"), 2: the blocked customer */ __( 'Linked to blocked %2$s via %1$s', 'fraud-shield' ), mb_strtolower( (string) ( $edge_types[ $c['blocked_via'] ]['label'] ?? $c['blocked_via'] ) ), (string) $c['blocked_with'] ) ); ?></span>
									<?php endif; ?>
									<p class="fs-hv-links">
										<a href="<?php echo esc_url( $this->profile_url( $email ) ); ?>"><?php esc_html_e( 'Profile', 'fraud-shield' ); ?></a>
										<a href="<?php echo esc_url( $this->network_url( $email ) ); ?>"><?php esc_html_e( 'Network', 'fraud-shield' ); ?></a>
									</p>
								<?php endif; ?>
							</td>
							<td class="fs-hv-review-col">
								<?php if ( ! empty( $r['review_id'] ) ) : ?>
									<?php echo $this->hv_verdict_pill( (string) $r['verdict'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<br/><span class="fs-muted">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: reviewer's name, 2: date and time */
												__( 'by %1$s, %2$s', 'fraud-shield' ),
												FS_Data::hv_reviewer_name( (int) $r['reviewer_id'], (string) $r['reviewer_name'] ),
												$r['reviewed_at'] ? mysql2date( 'Y-m-d H:i', (string) $r['reviewed_at'] ) : '—'
											)
										);
										?>
									</span>
									<?php if ( '' !== trim( (string) $r['review_note'] ) ) : ?>
										<br/><span class="fs-hv-note-text"><?php echo esc_html( (string) $r['review_note'] ); ?></span>
									<?php endif; ?>
									<?php if ( (int) $r['review_count'] > 1 ) : ?>
										<br/><span class="fs-muted"><?php echo esc_html( sprintf( /* translators: %d: number of verdicts recorded */ __( 'reviewed %d times', 'fraud-shield' ), (int) $r['review_count'] ) ); ?></span>
									<?php endif; ?>
									<details class="fs-hv-again">
										<summary><?php esc_html_e( 'Review again', 'fraud-shield' ); ?></summary>
										<?php $this->hv_verdict_form( $oid, $here ); ?>
									</details>
								<?php else : ?>
									<?php $this->hv_verdict_form( $oid, $here ); ?>
								<?php endif; ?>
								<?php if ( '' !== $email ) : ?>
									<p class="fs-hv-links">
										<?php echo $this->block_profile_button( $email, $profile_acts[ $email ] ?? null, $is_b ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<?php echo $this->verify_button( 'email', $email, isset( $vcreds[ 'email:' . $email ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr class="fs-detail-row" id="fs-detail-<?php echo esc_attr( $oid ); ?>" style="display:none;">
							<td colspan="8"><div class="fs-detail-body"></div></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( $base, admin_url( 'admin.php' ) ) . '%_%',
								'format'    => '&paged=%#%',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
					</div></div>
				<?php endif; ?>

				<p class="description"><?php esc_html_e( 'Rows are tinted by risk (and dark when the customer is on the blocklist). "Items & addresses" opens the order\'s line items, full billing and shipping addresses and IP geolocation in place. Block and Verify are the same one-customer toggles as on every other page. A verdict can be changed later — every verdict is kept, and the latest one is the order\'s current state.', 'fraud-shield' ); ?></p>
			</div>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Reviewed', 'fraud-shield' ); ?> <span class="fs-panel-period"><?php echo esc_html( $win['label'] ); ?></span> <span class="fs-pill"><?php echo esc_html( number_format_i18n( $rtotal ) ); ?></span></h2>
				<p class="description"><?php esc_html_e( 'Every verdict recorded in this window, newest first: which order, who checked it, what they decided and what that did to the order.', 'fraud-shield' ); ?></p>

				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-high-value' ); ?>" />
					<?php foreach ( array( 'win', 'show', 'dead', 's', 'sort', 'dir' ) as $carry ) : ?>
						<?php if ( isset( $base[ $carry ] ) ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $carry ); ?>" value="<?php echo esc_attr( $base[ $carry ] ); ?>" />
						<?php endif; ?>
					<?php endforeach; ?>
					<p class="fs-filters">
						<label><?php esc_html_e( 'Reviewer', 'fraud-shield' ); ?>
							<select name="reviewer">
								<option value=""><?php esc_html_e( 'Everyone', 'fraud-shield' ); ?></option>
								<?php foreach ( $board as $b ) : ?>
									<option value="<?php echo (int) $b['reviewer_id']; ?>" <?php selected( $reviewer, (int) $b['reviewer_id'] ); ?>><?php echo esc_html( $b['reviewer_name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<button class="button"><?php esc_html_e( 'Apply', 'fraud-shield' ); ?></button>
						<a class="button" href="<?php echo esc_url( $this->export_url( 'hv_reviews', array( 'win' => $win['key'], 'reviewer' => null === $reviewer ? '' : (string) $reviewer ) ) ); ?>"><?php esc_html_e( 'Export CSV (Excel)', 'fraud-shield' ); ?></a>
					</p>
				</form>

				<table class="wp-list-table widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Reviewed', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Reviewer', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Verdict', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Order', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Total', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Order status', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Note', 'fraud-shield' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $reviews ) ) : ?>
						<tr><td colspan="8"><?php echo esc_html( sprintf( /* translators: %s: window label, lower-case */ __( 'No verdicts recorded for %s.', 'fraud-shield' ), mb_strtolower( $win['label'] ) ) ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $reviews as $rv ) : ?>
						<?php
						$rv_oid   = (int) $rv['order_id'];
						$rv_email = (string) $rv['email_norm'];
						$rv_name  = trim( (string) $rv['customer_name'] );
						?>
						<tr>
							<td><?php echo esc_html( $rv['reviewed_at'] ? mysql2date( 'Y-m-d H:i', (string) $rv['reviewed_at'] ) : '—' ); ?></td>
							<td>
								<strong><?php echo esc_html( FS_Data::hv_reviewer_name( (int) $rv['reviewer_id'], (string) $rv['reviewer_name'] ) ); ?></strong>
								<?php if ( 'admin' !== (string) $rv['source'] ) : ?>
									<br/><span class="fs-muted"><?php echo esc_html( 'api' === (string) $rv['source'] ? __( 'over the API', 'fraud-shield' ) : __( 'from the order screen', 'fraud-shield' ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo $this->hv_verdict_pill( (string) $rv['verdict'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><a href="<?php echo esc_url( $this->order_edit_url( $rv_oid ) ); ?>"><strong>#<?php echo (int) $rv_oid; ?></strong></a></td>
							<td>
								<?php if ( '' !== $rv_email ) : ?>
									<a href="<?php echo esc_url( $this->profile_url( $rv_email ) ); ?>"><?php echo esc_html( '' !== $rv_name ? $rv_name : $rv_email ); ?></a>
									<?php if ( '' !== $rv_name ) : ?>
										<br/><span class="fs-muted"><?php echo esc_html( $rv_email ); ?></span>
									<?php endif; ?>
								<?php else : ?>
									<?php echo esc_html( '' !== $rv_name ? $rv_name : __( '(no email)', 'fraud-shield' ) ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo wp_kses_post( wc_price( (float) $rv['order_total'], '' !== (string) $rv['currency'] ? array( 'currency' => (string) $rv['currency'] ) : $currency ) ); ?></td>
							<td>
								<?php
								$sb = (string) $rv['status_before'];
								$sa = (string) $rv['status_after'];
								$nm = function ( $s ) {
									return '' === $s ? '—' : ( function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $s ) : $s );
								};
								if ( $sb !== $sa ) {
									echo esc_html( $nm( $sb ) ) . ' → <strong>' . esc_html( $nm( $sa ) ) . '</strong>';
								} else {
									echo esc_html( $nm( $sa ) ) . ' <span class="fs-muted">' . esc_html__( '(unchanged)', 'fraud-shield' ) . '</span>';
								}
								?>
							</td>
							<td class="fs-hv-note-text"><?php echo '' !== trim( (string) $rv['note'] ) ? esc_html( (string) $rv['note'] ) : '<span class="fs-muted">—</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $rpages > 1 ) : ?>
					<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( array_merge( $base, array( 'paged' => $paged ) ), admin_url( 'admin.php' ) ) . '%_%',
								'format'    => '&rpaged=%#%',
								'current'   => $rpaged,
								'total'     => $rpages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
					</div></div>
				<?php endif; ?>
			</div>

			<div class="fs-panel fs-hv-board">
				<h2><?php esc_html_e( 'Leaderboard', 'fraud-shield' ); ?> <span class="fs-panel-period"><?php echo esc_html( $win['label'] ); ?></span></h2>
				<p class="description"><?php esc_html_e( 'Who is checking the high-value orders: every verdict recorded in this window counts one, whatever it was. A second look at the same order counts too — the point is that someone looked.', 'fraud-shield' ); ?></p>
				<?php if ( empty( $board ) ) : ?>
					<p><em><?php echo esc_html( sprintf( /* translators: %s: window label, lower-case */ __( 'Nobody has reviewed a high-value order %s yet.', 'fraud-shield' ), 'today' === $win['key'] ? __( 'today', 'fraud-shield' ) : mb_strtolower( $win['label'] ) ) ); ?></em></p>
				<?php else : ?>
					<table class="wp-list-table widefat striped fs-hv-board-table">
						<thead><tr>
							<th class="fs-hv-rank">#</th>
							<th><?php esc_html_e( 'Reviewer', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Reviews', 'fraud-shield' ); ?></th>
							<th class="fs-hv-share"><?php esc_html_e( 'Share', 'fraud-shield' ); ?></th>
							<th><?php echo esc_html( $verdicts['cleared']['label'] ); ?></th>
							<th><?php esc_html_e( 'Held', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Cancelled', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Order value reviewed', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Last review', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'All time', 'fraud-shield' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $board as $b ) : ?>
							<tr class="<?php echo 1 === (int) $b['rank'] ? 'fs-hv-leader' : ''; ?>">
								<td class="fs-hv-rank"><?php echo 1 === (int) $b['rank'] ? '🏆' : (int) $b['rank']; ?></td>
								<td><a href="<?php echo esc_url( $this->hv_url( array_merge( $base, array( 'reviewer' => (string) $b['reviewer_id'] ) ) ) ); ?>"><strong><?php echo esc_html( $b['reviewer_name'] ); ?></strong></a></td>
								<td><strong><?php echo esc_html( number_format_i18n( (int) $b['reviews'] ) ); ?></strong></td>
								<td class="fs-hv-share">
									<div class="fs-hv-sharebar" title="<?php echo esc_attr( number_format_i18n( $b['share'] * 100, 0 ) . '%' ); ?>"><span style="width:<?php echo esc_attr( max( 2, round( $b['share'] * 100 ) ) ); ?>%"></span></div>
									<span class="fs-muted"><?php echo esc_html( number_format_i18n( $b['share'] * 100, 0 ) ); ?>%</span>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) $b['cleared'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $b['held'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $b['cancelled'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( (float) $b['value'], $currency ) ); ?></td>
								<td><?php echo esc_html( '' !== $b['last_at'] ? mysql2date( 'Y-m-d H:i', $b['last_at'] ) : '—' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $b['all_time'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
						<tfoot><tr>
							<th></th>
							<th><?php esc_html_e( 'Everyone', 'fraud-shield' ); ?></th>
							<th><?php echo esc_html( number_format_i18n( $board_n ) ); ?></th>
							<th></th>
							<th><?php echo esc_html( number_format_i18n( array_sum( wp_list_pluck( $board, 'cleared' ) ) ) ); ?></th>
							<th><?php echo esc_html( number_format_i18n( array_sum( wp_list_pluck( $board, 'held' ) ) ) ); ?></th>
							<th><?php echo esc_html( number_format_i18n( array_sum( wp_list_pluck( $board, 'cancelled' ) ) ) ); ?></th>
							<th><?php echo wp_kses_post( wc_price( (float) array_sum( wp_list_pluck( $board, 'value' ) ), $currency ) ); ?></th>
							<th></th>
							<th></th>
						</tr></tfoot>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Record a verdict from the review page, then return to it as it was.
	 */
	public function handle_hv_review() {
		$this->guard();
		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification -- checked on the next line, keyed by the order.
		check_admin_referer( 'fs_hv_review_' . $order_id );

		$verdict = isset( $_POST['verdict'] ) ? sanitize_key( wp_unslash( $_POST['verdict'] ) ) : '';
		$note    = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$back    = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';
		$back    = wp_validate_redirect( $back, $this->hv_url() );
		$back    = remove_query_arg( array( 'fs_status', 'fs_order' ), $back );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_safe_redirect( add_query_arg( array( 'fs_status' => 'hv_notfound', 'fs_order' => $order_id ), $back ) );
			exit;
		}
		$result = FS_Live::review_high_value( $order, $verdict, $note, 'admin' );
		if ( empty( $result['ok'] ) ) {
			$status = 'hv_invalid';
		} else {
			$status = 'hv_' . $result['verdict'] . ( empty( $result['changed'] ) && 'cleared' !== $result['verdict'] ? '_noop' : '' );
		}
		wp_safe_redirect( add_query_arg( array( 'fs_status' => $status, 'fs_order' => $order_id ), $back ) );
		exit;
	}

	/* ===================== Helpers ===================== */

	/**
	 * Capability guard.
	 */
	private function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fraud-shield' ) );
		}
	}

	/**
	 * Redirect back to a page with a status flag.
	 *
	 * @param string $page   Page slug.
	 * @param string $status Status key.
	 */
	private function redirect_with( $page, $status ) {
		wp_safe_redirect( add_query_arg( array( 'fs_status' => $status ), admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	/**
	 * Render a success notice based on ?fs_status.
	 */
	private function maybe_notice() {
		if ( empty( $_GET['fs_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$map = array(
			'saved'      => __( 'Settings saved.', 'fraud-shield' ),
			'blocked'    => __( 'Added to blocklist.', 'fraud-shield' ),
			'removed'    => __( 'Blocklist entry removed.', 'fraud-shield' ),
			'msgsaved'   => __( 'Checkout message saved.', 'fraud-shield' ),
			'verified'   => __( 'Customer verified — checkout unlocked (velocity lock and holds released).', 'fraud-shield' ),
			'unverified' => __( 'Verification revoked — the velocity lock applies again.', 'fraud-shield' ),
			'allowed'    => __( 'Email allowlisted — it is now exempt from the velocity lock and holds.', 'fraud-shield' ),
			'allow_removed' => __( 'Email removed from the allowlist.', 'fraud-shield' ),
			'dismissed'  => __( 'Checkout block dismissed.', 'fraud-shield' ),
			'verified_added'   => __( 'Credential added to Verified Customers — it now bypasses velocity, holds, and gateway restrictions.', 'fraud-shield' ),
			'verified_dup'     => __( 'That credential is already verified.', 'fraud-shield' ),
			'verified_removed' => __( 'Removed from Verified Customers.', 'fraud-shield' ),
			'gw_saved'         => __( 'Payment-gateway restriction saved — the customer is released to restricted checkout on the allowed gateways.', 'fraud-shield' ),
			'gw_cleared'       => __( 'Per-customer gateway restriction cleared.', 'fraud-shield' ),
			'place_locked' => __( 'Destination locked — checkouts shipping there will be rejected (verified customers exempt).', 'fraud-shield' ),
			'street_locked' => __( 'Street locked — every checkout shipping to any address on that street will be rejected (verified customers exempt). Edit the message it shows on its Blocklist card.', 'fraud-shield' ),
			'idv_requested'       => __( 'ID requested — this customer is blocked from checkout until they verify, and unlocks themselves as soon as they pass.', 'fraud-shield' ),
			'idv_request_cleared' => __( 'ID request withdrawn — checkout is open for this customer again.', 'fraud-shield' ),
			'idv_revoked'         => __( 'ID verification revoked — the credential it earned has been removed and checkout controls apply again.', 'fraud-shield' ),
			'idv_accepted'        => __( 'Name mismatch accepted — the customer is now ID-verified.', 'fraud-shield' ),
		);
		if ( 0 === strpos( sanitize_key( wp_unslash( $_GET['fs_status'] ) ), 'hv_' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			// High-value review outcomes (v1.32), each naming the order.
			$fs_key   = sanitize_key( wp_unslash( $_GET['fs_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$fs_order = isset( $_GET['fs_order'] ) ? (int) $_GET['fs_order'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$fs_hv    = array(
				'hv_cleared'        => array( 'success', __( 'Order #%d marked as looks good. It is listed under Reviewed and counted on the leaderboard.', 'fraud-shield' ) ),
				'hv_held'           => array( 'success', __( 'Order #%d marked as something off and set On-Hold — the package does not ship until someone releases it on the order screen.', 'fraud-shield' ) ),
				'hv_held_noop'      => array( 'warning', __( 'Order #%d marked as something off. Its status was left as it is — it was already held, already completed, or no longer live.', 'fraud-shield' ) ),
				'hv_cancelled'      => array( 'success', __( 'Order #%d cancelled. The payment is not refunded by this — issue the refund from the order screen if one is due.', 'fraud-shield' ) ),
				'hv_cancelled_noop' => array( 'warning', __( 'Order #%d marked as cancel now. It was already cancelled or refunded, so its status was left alone.', 'fraud-shield' ) ),
				'hv_invalid'        => array( 'error', __( 'Nothing recorded for order #%d: the verdict was not recognized.', 'fraud-shield' ) ),
				'hv_notfound'       => array( 'error', __( 'Nothing recorded: order #%d no longer exists.', 'fraud-shield' ) ),
			);
			if ( isset( $fs_hv[ $fs_key ] ) ) {
				/* translators: %d: order number */
				echo '<div class="notice notice-' . esc_attr( $fs_hv[ $fs_key ][0] ) . ' is-dismissible"><p>' . esc_html( sprintf( $fs_hv[ $fs_key ][1], $fs_order ) ) . '</p></div>';
			}
			return;
		}
		if ( 'selected_removed' === sanitize_key( wp_unslash( $_GET['fs_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$fs_n   = isset( $_GET['fs_n'] ) ? (int) $_GET['fs_n'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$fs_ips = isset( $_GET['fs_ips'] ) ? (int) $_GET['fs_ips'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			if ( 0 === $fs_n ) {
				$fs_text = __( 'Nothing was removed — no credential was selected.', 'fraud-shield' );
			} elseif ( $fs_ips === $fs_n ) {
				/* translators: %s: number of IP credentials removed */
				$fs_text = sprintf( _n( '%s IP address removed from the blocklist.', '%s IP addresses removed from the blocklist.', $fs_n, 'fraud-shield' ), number_format_i18n( $fs_n ) );
			} else {
				/* translators: 1: credentials removed, 2: how many of them were IP addresses */
				$fs_text = sprintf( _n( '%1$s credential removed from the blocklist (%2$s of them IP addresses).', '%1$s credentials removed from the blocklist (%2$s of them IP addresses).', $fs_n, 'fraud-shield' ), number_format_i18n( $fs_n ), number_format_i18n( $fs_ips ) );
			}
			echo '<div class="notice ' . ( $fs_n > 0 ? 'notice-success' : 'notice-warning' ) . ' is-dismissible"><p>' . esc_html( $fs_text ) . '</p></div>';
			return;
		}
		if ( 'ips_removed' === sanitize_key( wp_unslash( $_GET['fs_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$fs_n       = isset( $_GET['fs_n'] ) ? (int) $_GET['fs_n'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$fs_missing = isset( $_GET['fs_missing'] ) ? (int) $_GET['fs_missing'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$fs_invalid = isset( $_GET['fs_invalid'] ) ? (int) $_GET['fs_invalid'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			/* translators: %s: number of IP credentials removed */
			$fs_text = sprintf( _n( '%s IP address removed from the blocklist.', '%s IP addresses removed from the blocklist.', $fs_n, 'fraud-shield' ), number_format_i18n( $fs_n ) );
			if ( $fs_missing > 0 ) {
				/* translators: %s: number of IPs that were not on the list */
				$fs_text .= ' ' . sprintf( _n( '%s was not on the list.', '%s were not on the list.', $fs_missing, 'fraud-shield' ), number_format_i18n( $fs_missing ) );
			}
			if ( $fs_invalid > 0 ) {
				/* translators: %s: number of lines that were not an IP address */
				$fs_text .= ' ' . sprintf( _n( '%s line was not an IP address and was skipped.', '%s lines were not IP addresses and were skipped.', $fs_invalid, 'fraud-shield' ), number_format_i18n( $fs_invalid ) );
			}
			echo '<div class="notice ' . ( $fs_n > 0 ? 'notice-success' : 'notice-warning' ) . ' is-dismissible"><p>' . esc_html( $fs_text ) . '</p></div>';
			return;
		}
		if ( 'idv_unconfigured' === sanitize_key( wp_unslash( $_GET['fs_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Nothing was changed: ID verification has no API key or workflow ID yet, so this customer would have been blocked with no way to comply. Finish the setup under Settings → ID verification (Didit) first.', 'fraud-shield' ) . '</p></div>';
			return;
		}
		if ( 'idv_noop' === sanitize_key( wp_unslash( $_GET['fs_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Nothing to do — that ID verification is no longer in the state the action expected.', 'fraud-shield' ) . '</p></div>';
			return;
		}
		if ( in_array( sanitize_key( wp_unslash( $_GET['fs_status'] ) ), array( 'idv_synced', 'idv_rebuilt' ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$fs_rebuilt = 'idv_rebuilt' === sanitize_key( wp_unslash( $_GET['fs_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$fs_n       = function ( $k ) {
				return isset( $_GET[ $k ] ) ? number_format_i18n( (int) $_GET[ $k ] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification
			};
			echo '<div class="notice notice-success is-dismissible"><p><strong>' . ( $fs_rebuilt ? esc_html__( 'Attribution rebuilt.', 'fraud-shield' ) : esc_html__( 'Didit sync finished.', 'fraud-shield' ) ) . '</strong> ';
			if ( $fs_rebuilt ) {
				echo esc_html(
					sprintf(
						/* translators: 1: purged rows, 2: withdrawn verifications, 3: kept in-flight sessions */
						__( '%1$s old session rows cleared and %2$s Didit-earned verifications withdrawn (%3$s in-flight sessions kept; staff decisions untouched).', 'fraud-shield' ),
						$fs_n( 'fs_purged' ),
						$fs_n( 'fs_revoked' ),
						$fs_n( 'fs_kept' )
					)
				) . ' ';
			}
			echo esc_html(
					sprintf(
						/* translators: 1: sessions seen, 2: verifications restored, 3: history rows, 4: already known, 5: unmatched */
						__( '%1$s sessions found on Didit: %2$s verifications restored, %3$s recorded as history, %4$s already known, %5$s could not be matched to a customer.', 'fraud-shield' ),
						$fs_n( 'fs_listed' ),
						$fs_n( 'fs_restored' ),
						$fs_n( 'fs_history' ),
						$fs_n( 'fs_known' ),
						$fs_n( 'fs_unmatched' )
					)
				);
			$fs_foreign = isset( $_GET['fs_foreign'] ) ? (int) $_GET['fs_foreign'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			if ( $fs_foreign > 0 ) {
				echo ' ' . esc_html( sprintf( /* translators: %s: number of sessions */ __( '%s sessions belong to a different store on the same Didit account and were left alone.', 'fraud-shield' ), number_format_i18n( $fs_foreign ) ) );
			}
			$fs_remaining = isset( $_GET['fs_remaining'] ) ? (int) $_GET['fs_remaining'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			if ( $fs_remaining > 0 ) {
				echo ' <strong>' . esc_html( sprintf( /* translators: %s: number of sessions */ __( '%s approved sessions are still waiting: run the sync again to continue. Each run picks up where the last one stopped.', 'fraud-shield' ), number_format_i18n( $fs_remaining ) ) ) . '</strong>';
			} elseif ( ! empty( $_GET['fs_more'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				echo ' <strong>' . esc_html__( 'There are more sessions beyond this run\'s window: run the sync again to continue.', 'fraud-shield' ) . '</strong>';
			}
			echo '</p></div>';
			if ( ! empty( $_GET['fs_partial'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$fs_msg = isset( $_GET['fs_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['fs_msg'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
				echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'The sync stopped early.', 'fraud-shield' ) . '</strong> ' . esc_html( $fs_msg ) . ' ' . esc_html__( 'Everything restored so far is saved; run the sync again to finish.', 'fraud-shield' ) . '</p></div>';
			}
			return;
		}
		if ( 'idv_imported' === sanitize_key( wp_unslash( $_GET['fs_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$fs_n = function ( $k ) {
				return isset( $_GET[ $k ] ) ? number_format_i18n( (int) $_GET[ $k ] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification
			};
			echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Import finished.', 'fraud-shield' ) . '</strong> '
				. esc_html(
					sprintf(
						/* translators: 1: restored, 2: history rows, 3: already known, 4: unusable rows */
						__( '%1$s verifications restored, %2$s recorded as history only, %3$s already known and skipped, %4$s rows were unusable (no email or account, or an outcome that grants nothing, such as a check still in progress).', 'fraud-shield' ),
						$fs_n( 'fs_restored' ),
						$fs_n( 'fs_history' ),
						$fs_n( 'fs_known' ),
						$fs_n( 'fs_invalid' )
					)
				) . '</p></div>';
			return;
		}
		if ( 'idv_sync_failed' === sanitize_key( wp_unslash( $_GET['fs_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$fs_msg = isset( $_GET['fs_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['fs_msg'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The Didit sync could not run.', 'fraud-shield' ) . ' ' . esc_html( $fs_msg ) . '</p></div>';
			return;
		}
		if ( 'idv_badformat' === sanitize_key( wp_unslash( $_GET['fs_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That file has no column this import recognizes. Use the ID Verifications CSV export, or a file with at least an "email" column (a plain one-email-per-line list works too).', 'fraud-shield' ) . '</p></div>';
			return;
		}
		if ( 'verified_imported' === sanitize_key( wp_unslash( $_GET['fs_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$fs_n = function ( $k ) {
				return isset( $_GET[ $k ] ) ? number_format_i18n( (int) $_GET[ $k ] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification
			};
			echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Import finished.', 'fraud-shield' ) . '</strong> '
				. esc_html(
					sprintf(
						/* translators: 1: accounts verified, 2: credentials added, 3: already present / invalid, 4: emails without a matching account */
						__( '%1$s accounts verified, %2$s credentials added, %3$s already verified or unusable and skipped. %4$s account emails have no registered account on this site and were added as verified emails instead.', 'fraud-shield' ),
						$fs_n( 'fs_accounts' ),
						$fs_n( 'fs_creds' ),
						$fs_n( 'fs_skipped' ),
						$fs_n( 'fs_noacct' )
					)
				) . '</p></div>';
			return;
		}
		if ( 'verified_badformat' === sanitize_key( wp_unslash( $_GET['fs_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That file has no column this import recognizes. Use the Verified Customers CSV export, or a file with "type" and "value" columns, or at least an "email" column (a plain one-email-per-line list works too).', 'fraud-shield' ) . '</p></div>';
			return;
		}
		if ( 'badplace' === sanitize_key( wp_unslash( $_GET['fs_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not create that destination lock — please provide the required country/state/city, a ZIP prefix, country/state/city plus a street name (a PO box or a bare house number is not a street), a house number plus a street for a building, or a radius between 0.05 and 100 miles for an area.', 'fraud-shield' ) . '</p></div>';
			return;
		}
		if ( 'nolocation' === sanitize_key( wp_unslash( $_GET['fs_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The geocoder could not locate that address, so no area ban was created. Check the street, city, state and ZIP, or choose a different geocoder under Settings → Address resolution (the US Census geocoder covers US addresses only).', 'fraud-shield' ) . '</p></div>';
			return;
		}
		if ( 'bulk_gw_invalid' === sanitize_key( wp_unslash( $_GET['fs_status'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Select at least one customer AND at least one allowed gateway before applying the bulk restriction (with no allowed gateway the restriction would fail open).', 'fraud-shield' ) . '</p></div>';
			return;
		}
		$key = sanitize_key( wp_unslash( $_GET['fs_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'cluster_blocked' === $key || 'profile_blocked' === $key ) {
			$added = isset( $_GET['fs_added'] ) ? (int) $_GET['fs_added'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$label = ( 'profile_blocked' === $key )
				/* translators: %s: number of blocklist entries created */
				? _n( 'Profile blocked — %s credential added to the blocklist. This customer can no longer check out.', 'Profile blocked — %s credentials added to the blocklist. This customer can no longer check out.', $added, 'fraud-shield' )
				/* translators: %s: number of blocklist entries created */
				: _n( 'Cluster blocked — %s credential added to the blocklist.', 'Cluster blocked — %s credentials added to the blocklist.', $added, 'fraud-shield' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( $label, number_format_i18n( $added ) ) ) . '</p></div>';
			return;
		}
		if ( 'unblocked' === $key ) {
			$removed = isset( $_GET['fs_removed'] ) ? (int) $_GET['fs_removed'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$kept    = isset( $_GET['fs_kept'] ) ? (int) $_GET['fs_kept'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			/* translators: %s: number of blocklist entries removed */
			$label = _n( 'Unblocked — %s credential removed from the blocklist.', 'Unblocked — %s credentials removed from the blocklist.', $removed, 'fraud-shield' );
			$text  = sprintf( $label, number_format_i18n( $removed ) );
			if ( $kept > 0 ) {
				$text .= ' ' . sprintf(
					/* translators: %s: number of credentials still blocked by other block cards */
					_n(
						'%s credential stayed because it is the target of another block (see its card on the Blocklist page).',
						'%s credentials stayed because they are the targets of other blocks (see their cards on the Blocklist page).',
						$kept,
						'fraud-shield'
					),
					number_format_i18n( $kept )
				);
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
			return;
		}
		if ( 'resynced' === $key ) {
			$added = isset( $_GET['fs_added'] ) ? (int) $_GET['fs_added'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			/* translators: %s: number of new blocklist entries linked */
			$label = _n( 'Re-synced — %s new credential linked to this block.', 'Re-synced — %s new credentials linked to this block.', $added, 'fraud-shield' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( $label, number_format_i18n( $added ) ) ) . '</p></div>';
			return;
		}
		if ( 'bulk_restricted' === $key ) {
			$count = isset( $_GET['fs_count'] ) ? (int) $_GET['fs_count'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			/* translators: %s: number of customers */
			$label = _n( '%s customer unblocked and moved to restricted checkout.', '%s customers unblocked and moved to restricted checkout.', $count, 'fraud-shield' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( $label, number_format_i18n( $count ) ) ) . '</p></div>';
			return;
		}
		if ( 'bulk_gw_cleared' === $key ) {
			$count = isset( $_GET['fs_count'] ) ? (int) $_GET['fs_count'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			/* translators: %s: number of customers */
			$label = _n( '%s customer removed from restricted checkout.', '%s customers removed from restricted checkout.', $count, 'fraud-shield' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( $label, number_format_i18n( $count ) ) ) . '</p></div>';
			return;
		}
		if ( 'blimported' === $key ) {
			$added   = isset( $_GET['fs_added'] ) ? (int) $_GET['fs_added'] : 0;   // phpcs:ignore WordPress.Security.NonceVerification
			$skipped = isset( $_GET['fs_skipped'] ) ? (int) $_GET['fs_skipped'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			/* translators: 1: entries imported, 2: entries skipped */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Blocklist imported: %1$s new entries added, %2$s skipped (duplicates or invalid).', 'fraud-shield' ), number_format_i18n( $added ), number_format_i18n( $skipped ) ) ) . '</p></div>';
			return;
		}
		if ( 'badformat' === $key ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That file does not look like a Fraud Shield blocklist export (missing "type" and "value" columns).', 'fraud-shield' ) . '</p></div>';
			return;
		}
		if ( 'nofile' === $key || 'badfile' === $key ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'No usable file was uploaded. Please choose a .csv file.', 'fraud-shield' ) . '</p></div>';
			return;
		}
		if ( isset( $map[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $key ] ) . '</p></div>';
		}
	}

	/**
	 * Level pill markup.
	 *
	 * @param string $level Level key.
	 * @return string
	 */
	private function level_pill( $level ) {
		$cls = array(
			'low'    => 'fs-pill-green',
			'medium' => 'fs-pill-amber',
			'high'   => 'fs-pill-red',
			'block'  => 'fs-pill-black',
		);
		$c = $cls[ $level ] ?? '';
		return '<span class="fs-pill ' . esc_attr( $c ) . '">' . esc_html( strtoupper( $level ? $level : 'low' ) ) . '</span>';
	}
}
