<?php
/**
 * The API & Webhooks screen: mint credentials, open a pairing window, manage
 * subscriptions, and read the security log.
 *
 * Secrets are shown exactly once, immediately after they are created, and are
 * handed to the browser through a short-lived transient rather than a query
 * argument — a token in a URL ends up in browser history, in the referrer of
 * the next request, and in any proxy log along the way.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_API_Admin {

	/**
	 * Capability. Same bar as the rest of the plugin: whoever can run the shop
	 * can grant access to its fraud data.
	 */
	const CAP = 'manage_woocommerce';

	/**
	 * Menu slug.
	 */
	const SLUG = 'fraud-shield-api';

	/**
	 * Singleton, so FS_Admin can point a submenu at the same object that
	 * registered the action handlers.
	 *
	 * @var FS_API_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get/create the instance.
	 *
	 * @return FS_API_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the action handlers. Done at construction, not on admin_menu,
	 * because admin-post.php never builds the menu.
	 */
	public function __construct() {
		self::$instance = $this;

		add_action( 'admin_post_fs_api_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_fs_api_key_create', array( $this, 'handle_key_create' ) );
		add_action( 'admin_post_fs_api_key_revoke', array( $this, 'handle_key_revoke' ) );
		add_action( 'admin_post_fs_api_key_delete', array( $this, 'handle_key_delete' ) );
		add_action( 'admin_post_fs_api_pair_start', array( $this, 'handle_pair_start' ) );
		add_action( 'admin_post_fs_api_pair_cancel', array( $this, 'handle_pair_cancel' ) );
		add_action( 'admin_post_fs_api_hook_create', array( $this, 'handle_hook_create' ) );
		add_action( 'admin_post_fs_api_hook_test', array( $this, 'handle_hook_test' ) );
		add_action( 'admin_post_fs_api_hook_toggle', array( $this, 'handle_hook_toggle' ) );
		add_action( 'admin_post_fs_api_hook_delete', array( $this, 'handle_hook_delete' ) );
	}

	/* ===================== Shared plumbing ===================== */

	/**
	 * Refuse anyone without the capability.
	 */
	private function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fraud-shield' ) );
		}
	}

	/**
	 * Back to the screen with a status message.
	 *
	 * @param string $status Status key.
	 */
	private function back( $status ) {
		wp_safe_redirect( add_query_arg( array( 'fs_status' => $status ), admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Stash a freshly created secret for exactly one page view.
	 *
	 * @param string $kind   'key' | 'hook' | 'pair'.
	 * @param string $secret The secret.
	 * @param string $extra  Context line shown beside it.
	 */
	private function flash_secret( $kind, $secret, $extra = '' ) {
		set_transient(
			'fs_api_reveal_' . get_current_user_id(),
			array(
				'kind'   => $kind,
				'secret' => $secret,
				'extra'  => $extra,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and burn the stashed secret.
	 *
	 * @return array|null
	 */
	private function take_secret() {
		$key    = 'fs_api_reveal_' . get_current_user_id();
		$reveal = get_transient( $key );
		if ( ! is_array( $reveal ) ) {
			return null;
		}
		delete_transient( $key );
		return $reveal;
	}

	/**
	 * Render the post-action notice.
	 */
	private function notice() {
		if ( empty( $_GET['fs_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$key = sanitize_key( wp_unslash( $_GET['fs_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		$map = array(
			'saved'         => array( 'success', __( 'API settings saved.', 'fraud-shield' ) ),
			'key_created'   => array( 'success', __( 'API key created. Copy it now — it is shown only once.', 'fraud-shield' ) ),
			'key_revoked'   => array( 'success', __( 'API key revoked. It stops working immediately.', 'fraud-shield' ) ),
			'key_deleted'   => array( 'success', __( 'API key deleted.', 'fraud-shield' ) ),
			'key_failed'    => array( 'error', __( 'The key could not be created. Choose at least one scope.', 'fraud-shield' ) ),
			'paired'        => array( 'success', __( 'Pairing code created. It is valid once, for the time shown.', 'fraud-shield' ) ),
			'pair_cancel'   => array( 'success', __( 'Pairing window closed.', 'fraud-shield' ) ),
			'pair_failed'   => array( 'error', __( 'The pairing code could not be created. Choose at least one scope.', 'fraud-shield' ) ),
			'hook_created'  => array( 'success', __( 'Webhook created. Copy the signing secret now — it is shown only once.', 'fraud-shield' ) ),
			'hook_deleted'  => array( 'success', __( 'Webhook deleted.', 'fraud-shield' ) ),
			'hook_toggled'  => array( 'success', __( 'Webhook updated.', 'fraud-shield' ) ),
			'hook_tested'   => array( 'success', __( 'Test event delivered successfully.', 'fraud-shield' ) ),
			'hook_test_bad' => array( 'error', __( 'The test event was not accepted. See the error on the subscription below.', 'fraud-shield' ) ),
		);

		// The URL validator's reasons are the actionable part of a failed
		// subscription, so they are shown verbatim rather than flattened. Read
		// from a one-shot transient rather than the query string: a message
		// carried in the URL is a sentence anyone can choose and then show to an
		// administrator inside a WordPress error notice.
		if ( 'hook_failed' === $key ) {
			$store  = 'fs_api_hook_error_' . get_current_user_id();
			$detail = (string) get_transient( $store );
			delete_transient( $store );
			printf(
				'<div class="notice notice-error is-dismissible"><p>%1$s %2$s</p></div>',
				esc_html__( 'The webhook could not be added.', 'fraud-shield' ),
				esc_html( $detail )
			);
			return;
		}

		if ( isset( $map[ $key ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $map[ $key ][0] ),
				esc_html( $map[ $key ][1] )
			);
		}
	}

	/* ===================== Page ===================== */

	/**
	 * Render the whole screen.
	 */
	public function render_page() {
		$this->guard();

		// Self-heal the cached counters every time staff look at this page, so a
		// stale count can never quietly stop events being recorded.
		FS_REST_API::refresh_counts();

		$settings = FS_REST_API::settings();
		$reveal   = $this->take_secret();
		$keys     = FS_API_Keys::all( 100 );
		$hooks    = FS_Webhooks::all();
		$pending  = FS_API_Keys::pairing_pending();
		$stats    = FS_Webhooks::stats();
		$base     = rest_url( FS_REST_API::NS );
		?>
		<div class="wrap fs-wrap">
			<h1><?php esc_html_e( 'Fraud Shield — API &amp; Webhooks', 'fraud-shield' ); ?></h1>
			<?php $this->notice(); ?>

			<?php if ( is_array( $reveal ) ) : ?>
				<div class="notice notice-success">
					<p><strong><?php esc_html_e( 'Copy this now. It cannot be shown again.', 'fraud-shield' ); ?></strong></p>
					<p><input type="text" readonly class="large-text code" onclick="this.select();" value="<?php echo esc_attr( $reveal['secret'] ); ?>" /></p>
					<?php if ( '' !== (string) $reveal['extra'] ) : ?>
						<p class="fs-muted"><?php echo esc_html( $reveal['extra'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="fs-panel">
				<h2><?php esc_html_e( 'Endpoint', 'fraud-shield' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Base URL', 'fraud-shield' ); ?></th>
						<td><code><?php echo esc_html( $base ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Store ID', 'fraud-shield' ); ?></th>
						<td>
							<code><?php echo esc_html( FS_REST_API::store_id() ); ?></code>
							<p class="description"><?php esc_html_e( 'Identifies this store in every response and webhook, without exposing the site URL downstream.', 'fraud-shield' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'fraud-shield' ); ?></th>
						<td>
							<?php if ( FS_REST_API::enabled() ) : ?>
								<span class="fs-pill fs-pill-green"><?php esc_html_e( 'Enabled', 'fraud-shield' ); ?></span>
							<?php else : ?>
								<span class="fs-pill fs-pill-black"><?php esc_html_e( 'Disabled', 'fraud-shield' ); ?></span>
							<?php endif; ?>
							<span class="fs-muted">
								<?php
								printf(
									/* translators: 1: active key count, 2: active subscription count, 3: queued delivery count */
									esc_html__( '%1$d active key(s), %2$d subscription(s), %3$d delivery(ies) queued.', 'fraud-shield' ),
									(int) FS_API_Keys::counts()['active'],
									(int) $stats['subscriptions'],
									(int) $stats['pending']
								);
								?>
							</span>
						</td>
					</tr>
				</table>
			</div>

			<?php $this->render_settings( $settings ); ?>
			<?php $this->render_pairing( $pending ); ?>
			<?php $this->render_keys( $keys ); ?>
			<?php $this->render_hooks( $hooks ); ?>
			<?php $this->render_deliveries(); ?>
			<?php $this->render_audit(); ?>
			<?php $this->render_reference( $base ); ?>
		</div>
		<?php
	}

	/**
	 * The API's own settings.
	 *
	 * @param array $s Current settings.
	 */
	private function render_settings( $s ) {
		?>
		<div class="fs-panel">
			<h2><?php esc_html_e( 'Settings', 'fraud-shield' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'fs_api_save' ); ?>
				<input type="hidden" name="action" value="fs_api_save" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'API', 'fraud-shield' ); ?></th>
						<td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?> /> <?php esc_html_e( 'Answer API requests', 'fraud-shield' ); ?></label>
							<p class="description"><?php esc_html_e( 'Turning this off refuses every request, including from keys that already exist. Webhook delivery stops too.', 'fraud-shield' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Transport', 'fraud-shield' ); ?></th>
						<td>
							<label><input type="checkbox" name="require_https" value="1" <?php checked( ! empty( $s['require_https'] ) ); ?> /> <?php esc_html_e( 'Require HTTPS', 'fraud-shield' ); ?></label>
							<p class="description"><?php esc_html_e( 'Leave on. A token sent over plain HTTP is a token given to every device between the caller and this server.', 'fraud-shield' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Request signing', 'fraud-shield' ); ?></th>
						<td>
							<label><input type="checkbox" name="require_signature" value="1" <?php checked( ! empty( $s['require_signature'] ) ); ?> /> <?php esc_html_e( 'Require signed requests from every key', 'fraud-shield' ); ?></label>
							<p class="description"><?php esc_html_e( 'Strongest setting: a captured request cannot be replayed and a token alone is not enough. Individual keys can also require it on their own.', 'fraud-shield' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rate limit', 'fraud-shield' ); ?></th>
						<td>
							<input type="number" name="rate_limit" min="1" max="10000" value="<?php echo esc_attr( (int) $s['rate_limit'] ); ?>" class="small-text" />
							<?php esc_html_e( 'requests per minute per key', 'fraud-shield' ); ?>
							<p class="description"><?php esc_html_e( 'A key can be given its own limit when it is created.', 'fraud-shield' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Caller address', 'fraud-shield' ); ?></th>
						<td>
							<label><input type="checkbox" name="trust_proxy" value="1" <?php checked( ! empty( $s['trust_proxy'] ) ); ?> /> <?php esc_html_e( 'Read the caller IP from proxy headers', 'fraud-shield' ); ?></label>
							<p class="description"><?php esc_html_e( 'Only tick this behind a proxy or CDN that overwrites the forwarded-for header. Otherwise a caller can claim any address it likes, and per-key IP allow-lists become decorative.', 'fraud-shield' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Event retention', 'fraud-shield' ); ?></th>
						<td>
							<input type="number" name="retention_days" min="1" max="365" value="<?php echo esc_attr( (int) $s['retention_days'] ); ?>" class="small-text" />
							<?php esc_html_e( 'days', 'fraud-shield' ); ?>
							<p class="description"><?php esc_html_e( 'Events and delivery history older than this are pruned. Anything a subscriber has not collected by then is gone.', 'fraud-shield' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Delivery', 'fraud-shield' ); ?></th>
						<td>
							<input type="number" name="hook_timeout" min="3" max="30" value="<?php echo esc_attr( (int) $s['hook_timeout'] ); ?>" class="small-text" />
							<?php esc_html_e( 'second timeout,', 'fraud-shield' ); ?>
							<input type="number" name="hook_max_attempts" min="1" max="12" value="<?php echo esc_attr( (int) $s['hook_max_attempts'] ); ?>" class="small-text" />
							<?php esc_html_e( 'attempts per event', 'fraud-shield' ); ?>
							<p class="description"><?php esc_html_e( 'Retries back off exponentially, one minute doubling to a maximum of six hours. A subscription that fails 20 times in a row is switched off.', 'fraud-shield' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Local delivery', 'fraud-shield' ); ?></th>
						<td>
							<label><input type="checkbox" name="allow_private_urls" value="1" <?php checked( ! empty( $s['allow_private_urls'] ) ); ?> /> <?php esc_html_e( 'Allow webhook URLs on private or loopback addresses', 'fraud-shield' ); ?></label>
							<p class="description"><?php esc_html_e( 'For development only. A webhook URL is an address this store connects to on request, so allowing internal addresses lets anyone who can add a webhook reach services behind your firewall.', 'fraud-shield' ); ?></p>
						</td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'fraud-shield' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * The pairing window used to onboard an external platform.
	 *
	 * @param array|null $pending Open window, if any.
	 */
	private function render_pairing( $pending ) {
		?>
		<div class="fs-panel">
			<h2><?php esc_html_e( 'Pair an external platform', 'fraud-shield' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Generates a single-use code that a platform such as bonesfraud.com exchanges for its own scoped key and webhook secret. Nobody has to copy a permanent secret by hand, and the code is worthless once used or expired.', 'fraud-shield' ); ?>
			</p>

			<?php if ( is_array( $pending ) ) : ?>
				<p>
					<span class="fs-pill fs-pill-amber"><?php esc_html_e( 'Pairing open', 'fraud-shield' ); ?></span>
					<span class="fs-muted">
						<?php
						printf(
							/* translators: 1: minutes remaining, 2: space-separated scope list */
							esc_html__( 'Expires in %1$d minute(s). Scopes: %2$s', 'fraud-shield' ),
							(int) max( 0, ceil( ( (int) $pending['expires'] - time() ) / MINUTE_IN_SECONDS ) ),
							esc_html( implode( ' ', (array) $pending['scopes'] ) )
						);
						?>
					</span>
				</p>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fs_api_pair_cancel' ), 'fs_api_pair_cancel' ) ); ?>">
						<?php esc_html_e( 'Close pairing window', 'fraud-shield' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'fs_api_pair_start' ); ?>
				<input type="hidden" name="action" value="fs_api_pair_start" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Platform name', 'fraud-shield' ); ?></th>
						<td><input type="text" name="label" class="regular-text" value="bonesfraud.com" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Scopes', 'fraud-shield' ); ?></th>
						<td><?php $this->scope_checklist( 'scopes', array( 'read:risk', 'read:customers', 'read:orders', 'read:events' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Valid for', 'fraud-shield' ); ?></th>
						<td>
							<select name="ttl">
								<option value="900"><?php esc_html_e( '15 minutes', 'fraud-shield' ); ?></option>
								<option value="3600"><?php esc_html_e( '1 hour', 'fraud-shield' ); ?></option>
								<option value="86400"><?php esc_html_e( '24 hours', 'fraud-shield' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'fraud-shield' ); ?></th>
						<td>
							<label><input type="checkbox" name="require_sig" value="1" checked /> <?php esc_html_e( 'The paired key must sign every request', 'fraud-shield' ); ?></label><br />
							<label><input type="checkbox" name="with_webhook" value="1" checked /> <?php esc_html_e( 'Also create a webhook subscription from the callback URL the platform supplies', 'fraud-shield' ); ?></label>
						</td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Generate pairing code', 'fraud-shield' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Credential list and the create form.
	 *
	 * @param array[] $keys Key rows.
	 */
	private function render_keys( $keys ) {
		?>
		<div class="fs-panel">
			<h2><?php esc_html_e( 'API keys', 'fraud-shield' ); ?></h2>

			<?php if ( empty( $keys ) ) : ?>
				<p class="fs-muted"><?php esc_html_e( 'No keys yet. Until one exists, every API request is refused.', 'fraud-shield' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Key ID', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Scopes', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Status', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Last used', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Calls', 'fraud-shield' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $keys as $key ) : ?>
						<?php
						$revoke = wp_nonce_url( admin_url( 'admin-post.php?action=fs_api_key_revoke&id=' . (int) $key['id'] ), 'fs_api_key_revoke' );
						$remove = wp_nonce_url( admin_url( 'admin-post.php?action=fs_api_key_delete&id=' . (int) $key['id'] ), 'fs_api_key_delete' );
						$active = 'active' === (string) $key['status'] && '' === FS_API_Keys::unusable_reason( $key );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( '' !== (string) $key['label'] ? $key['label'] : __( '(unnamed)', 'fraud-shield' ) ); ?></strong>
								<?php if ( 'handshake' === (string) $key['origin'] ) : ?>
									<span class="fs-pill"><?php esc_html_e( 'paired', 'fraud-shield' ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $key['require_sig'] ) ) : ?>
									<span class="fs-pill fs-pill-green"><?php esc_html_e( 'signed', 'fraud-shield' ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== trim( (string) $key['ip_allow'] ) ) : ?>
									<span class="fs-pill"><?php esc_html_e( 'IP pinned', 'fraud-shield' ); ?></span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $key['key_id'] ); ?></code></td>
							<td class="fs-muted"><?php echo esc_html( str_replace( ',', ' ', (string) $key['scopes'] ) ); ?></td>
							<td>
								<?php if ( $active ) : ?>
									<span class="fs-pill fs-pill-green"><?php esc_html_e( 'active', 'fraud-shield' ); ?></span>
								<?php else : ?>
									<span class="fs-pill fs-pill-black"><?php echo esc_html( 'revoked' === (string) $key['status'] ? __( 'revoked', 'fraud-shield' ) : __( 'expired', 'fraud-shield' ) ); ?></span>
								<?php endif; ?>
							</td>
							<td class="fs-muted">
								<?php
								echo esc_html(
									empty( $key['last_used_at'] )
										? __( 'never', 'fraud-shield' )
										: mysql2date( get_option( 'date_format' ) . ' H:i', $key['last_used_at'] )
								);
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) $key['use_count'] ) ); ?></td>
							<td>
								<?php if ( $active ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $revoke ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Revoke this key? Anything using it stops working immediately.', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Revoke', 'fraud-shield' ); ?></a>
								<?php endif; ?>
								<a class="button button-small" href="<?php echo esc_url( $remove ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this key permanently?', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Delete', 'fraud-shield' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Create a key', 'fraud-shield' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'fs_api_key_create' ); ?>
				<input type="hidden" name="action" value="fs_api_key_create" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Label', 'fraud-shield' ); ?></th>
						<td><input type="text" name="label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Reporting dashboard', 'fraud-shield' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Scopes', 'fraud-shield' ); ?></th>
						<td><?php $this->scope_checklist( 'scopes', array( 'read:risk' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Restrictions', 'fraud-shield' ); ?></th>
						<td>
							<label><input type="checkbox" name="require_sig" value="1" /> <?php esc_html_e( 'Require signed requests from this key', 'fraud-shield' ); ?></label>
							<p>
								<label for="fs-api-ip"><?php esc_html_e( 'Allowed addresses (one IP or CIDR per line, blank for any)', 'fraud-shield' ); ?></label><br />
								<textarea id="fs-api-ip" name="ip_allow" rows="3" class="large-text code" placeholder="203.0.113.7&#10;198.51.100.0/24"></textarea>
							</p>
							<p>
								<?php esc_html_e( 'Rate limit', 'fraud-shield' ); ?>
								<input type="number" name="rate_limit" min="0" max="10000" value="0" class="small-text" />
								<span class="fs-muted"><?php esc_html_e( 'requests per minute (0 = use the default above)', 'fraud-shield' ); ?></span>
							</p>
							<p>
								<?php esc_html_e( 'Expires', 'fraud-shield' ); ?>
								<select name="expires_in">
									<option value="0"><?php esc_html_e( 'never', 'fraud-shield' ); ?></option>
									<option value="2592000"><?php esc_html_e( 'in 30 days', 'fraud-shield' ); ?></option>
									<option value="7776000"><?php esc_html_e( 'in 90 days', 'fraud-shield' ); ?></option>
									<option value="31536000"><?php esc_html_e( 'in a year', 'fraud-shield' ); ?></option>
								</select>
							</p>
						</td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create key', 'fraud-shield' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Subscription list and the create form.
	 *
	 * @param array[] $hooks Subscription rows.
	 */
	private function render_hooks( $hooks ) {
		$topics = FS_Webhooks::topics();
		?>
		<div class="fs-panel">
			<h2><?php esc_html_e( 'Webhooks', 'fraud-shield' ); ?></h2>

			<?php if ( empty( $hooks ) ) : ?>
				<p class="fs-muted"><?php esc_html_e( 'No subscriptions. Events are still recorded and can be polled from /events by any key with the read:events scope.', 'fraud-shield' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Endpoint', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Topics', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Status', 'fraud-shield' ); ?></th>
							<th><?php esc_html_e( 'Last result', 'fraud-shield' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $hooks as $hook ) : ?>
						<?php
						$id     = (int) $hook['id'];
						$test   = wp_nonce_url( admin_url( 'admin-post.php?action=fs_api_hook_test&id=' . $id ), 'fs_api_hook_test' );
						$toggle = wp_nonce_url( admin_url( 'admin-post.php?action=fs_api_hook_toggle&id=' . $id ), 'fs_api_hook_toggle' );
						$remove = wp_nonce_url( admin_url( 'admin-post.php?action=fs_api_hook_delete&id=' . $id ), 'fs_api_hook_delete' );
						$active = 'active' === (string) $hook['status'];
						$count  = count( preg_split( '/,/', (string) $hook['topics'], -1, PREG_SPLIT_NO_EMPTY ) );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $hook['label'] ? $hook['label'] : __( '(unnamed)', 'fraud-shield' ) ); ?></strong><br />
								<span class="fs-muted"><?php echo esc_html( $hook['url'] ); ?></span>
								<?php if ( ! empty( $hook['hash_pii'] ) ) : ?>
									<span class="fs-pill fs-pill-green"><?php esc_html_e( 'hashed identifiers', 'fraud-shield' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="fs-muted">
								<?php
								echo esc_html(
									$count === count( $topics ) - 1
										? __( 'all topics', 'fraud-shield' )
										: str_replace( ',', ' ', (string) $hook['topics'] )
								);
								?>
							</td>
							<td>
								<?php if ( $active ) : ?>
									<span class="fs-pill fs-pill-green"><?php esc_html_e( 'active', 'fraud-shield' ); ?></span>
								<?php else : ?>
									<span class="fs-pill fs-pill-black"><?php esc_html_e( 'disabled', 'fraud-shield' ); ?></span>
								<?php endif; ?>
								<?php if ( (int) $hook['failures'] > 0 ) : ?>
									<span class="fs-pill fs-pill-red">
										<?php
										printf(
											/* translators: %d: consecutive failure count */
											esc_html__( '%d failing', 'fraud-shield' ),
											(int) $hook['failures']
										);
										?>
									</span>
								<?php endif; ?>
							</td>
							<td class="fs-muted">
								<?php
								if ( ! empty( $hook['last_error'] ) ) {
									echo esc_html( $hook['last_error'] );
								} elseif ( ! empty( $hook['last_delivered_at'] ) ) {
									printf(
										/* translators: %s: how long ago the last delivery succeeded */
										esc_html__( 'delivered %s ago', 'fraud-shield' ),
										esc_html( human_time_diff( (int) strtotime( $hook['last_delivered_at'] . ' UTC' ) ) )
									);
								} else {
									esc_html_e( 'nothing sent yet', 'fraud-shield' );
								}
								?>
							</td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( $test ); ?>"><?php esc_html_e( 'Send test', 'fraud-shield' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( $toggle ); ?>"><?php echo esc_html( $active ? __( 'Disable', 'fraud-shield' ) : __( 'Enable', 'fraud-shield' ) ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( $remove ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this subscription?', 'fraud-shield' ) ); ?>');"><?php esc_html_e( 'Delete', 'fraud-shield' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Add a webhook', 'fraud-shield' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'fs_api_hook_create' ); ?>
				<input type="hidden" name="action" value="fs_api_hook_create" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Label', 'fraud-shield' ); ?></th>
						<td><input type="text" name="label" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Delivery URL', 'fraud-shield' ); ?></th>
						<td>
							<input type="url" name="url" class="large-text code" placeholder="https://example.com/hooks/fraud-shield" required />
							<p class="description"><?php esc_html_e( 'Must be HTTPS, and must not resolve to a private address unless local delivery is allowed above.', 'fraud-shield' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Topics', 'fraud-shield' ); ?></th>
						<td>
							<?php
							foreach ( $topics as $topic => $description ) :
								if ( 'test.ping' === $topic ) {
									continue;
								}
								?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="topics[]" value="<?php echo esc_attr( $topic ); ?>" <?php checked( in_array( $topic, array( 'order.flagged', 'checkout.blocked', 'idv.approved' ), true ) ); ?> />
									<code><?php echo esc_html( $topic ); ?></code>
									<span class="fs-muted"><?php echo esc_html( $description ); ?></span>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Privacy', 'fraud-shield' ); ?></th>
						<td>
							<label><input type="checkbox" name="hash_pii" value="1" /> <?php esc_html_e( 'Send hashed identifiers instead of emails, names and IPs', 'fraud-shield' ); ?></label>
							<p class="description"><?php esc_html_e( 'The digest is stable, so the receiver can still group events by customer without receiving personal data.', 'fraud-shield' ); ?></p>
						</td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add webhook', 'fraud-shield' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Recent delivery attempts.
	 */
	private function render_deliveries() {
		$rows = FS_Webhooks::recent_deliveries( 25 );
		if ( empty( $rows ) ) {
			return;
		}
		?>
		<div class="fs-panel">
			<h2><?php esc_html_e( 'Recent deliveries', 'fraud-shield' ); ?></h2>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Topic', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Endpoint', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Result', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Attempts', 'fraud-shield' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$status = (string) $row['status'];
					$pill   = 'delivered' === $status ? 'fs-pill fs-pill-green' : ( 'pending' === $status ? 'fs-pill fs-pill-amber' : 'fs-pill fs-pill-red' );
					$when   = ! empty( $row['delivered_at'] ) ? $row['delivered_at'] : $row['created_at'];
					?>
					<tr>
						<td class="fs-muted">
							<?php
							echo esc_html(
								empty( $when )
									? '—'
									/* translators: %s: elapsed time, e.g. "5 mins" */
									: sprintf( __( '%s ago', 'fraud-shield' ), human_time_diff( (int) strtotime( $when . ' UTC' ) ) )
							);
							?>
						</td>
						<td><code><?php echo esc_html( $row['topic'] ); ?></code></td>
						<td class="fs-muted"><?php echo esc_html( wp_parse_url( (string) $row['url'], PHP_URL_HOST ) ); ?></td>
						<td>
							<span class="<?php echo esc_attr( $pill ); ?>"><?php echo esc_html( $status ); ?></span>
							<?php if ( (int) $row['response_code'] > 0 ) : ?>
								<span class="fs-muted"><?php echo esc_html( 'HTTP ' . (int) $row['response_code'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $row['error'] ) ) : ?>
								<br /><span class="fs-muted"><?php echo esc_html( $row['error'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (int) $row['attempts'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * The security log.
	 */
	private function render_audit() {
		$rows = FS_API_Keys::audit_rows();
		if ( empty( $rows ) ) {
			return;
		}
		?>
		<div class="fs-panel">
			<h2><?php esc_html_e( 'Security log', 'fraud-shield' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Authentication failures and credential changes. Repeated failures from one address are worth a look; a burst of them is worth revoking a key over.', 'fraud-shield' ); ?></p>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Event', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Key', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Address', 'fraud-shield' ); ?></th>
						<th><?php esc_html_e( 'Detail', 'fraud-shield' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( array_slice( $rows, 0, 40 ) as $row ) : ?>
					<?php $bad = 0 === strpos( (string) $row['event'], 'auth.' ) || false !== strpos( (string) $row['event'], 'failed' ); ?>
					<tr>
						<td class="fs-muted">
							<?php
							printf(
								/* translators: %s: elapsed time, e.g. "5 mins" */
								esc_html__( '%s ago', 'fraud-shield' ),
								esc_html( human_time_diff( (int) $row['t'] ) )
							);
							?>
						</td>
						<td><span class="<?php echo esc_attr( $bad ? 'fs-pill fs-pill-red' : 'fs-pill' ); ?>"><?php echo esc_html( $row['event'] ); ?></span></td>
						<td><code><?php echo esc_html( '' !== (string) $row['key'] ? $row['key'] : '—' ); ?></code></td>
						<td class="fs-muted"><?php echo esc_html( '' !== (string) $row['ip'] ? $row['ip'] : '—' ); ?></td>
						<td class="fs-muted"><?php echo esc_html( $row['detail'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Copy-paste reference, so nobody has to leave the screen to make a first call.
	 *
	 * @param string $base API base URL.
	 */
	private function render_reference( $base ) {
		?>
		<div class="fs-panel">
			<h2><?php esc_html_e( 'Quick reference', 'fraud-shield' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Full documentation, including every endpoint, the signing scheme and webhook payloads, is in API.md alongside the plugin.', 'fraud-shield' ); ?>
			</p>
			<p><strong><?php esc_html_e( 'Check a credential', 'fraud-shield' ); ?></strong></p>
			<pre class="fs-code-block"><?php
				echo esc_html( "curl -s '" . $base . "/ping' \\\n  -H 'Authorization: Bearer fsk_<id>_<secret>'" );
			?></pre>
			<p><strong><?php esc_html_e( 'Score an identity before it becomes an order', 'fraud-shield' ); ?></strong></p>
			<pre class="fs-code-block"><?php
				echo esc_html(
					"curl -s -X POST '" . $base . "/risk/evaluate' \\\n"
					. "  -H 'Authorization: Bearer fsk_<id>_<secret>' \\\n"
					. "  -H 'Content-Type: application/json' \\\n"
					. '  -d \'{"email":"buyer@example.com","ip":"203.0.113.7","amount":749.00,'
					. "\n       \"address\":{\"address_1\":\"14148 Greentree Trail\",\"city\":\"Wellington\",\"state\":\"FL\",\"postcode\":\"33414\",\"country\":\"US\"}}'"
				);
			?></pre>
		</div>
		<?php
	}

	/**
	 * Scope checkboxes.
	 *
	 * @param string $field   Field name.
	 * @param array  $checked Scopes ticked by default.
	 */
	private function scope_checklist( $field, $checked = array() ) {
		foreach ( FS_API_Keys::scopes() as $scope => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> <code>%2$s</code> <span class="fs-muted">%4$s</span></label>',
				esc_attr( $field ),
				esc_attr( $scope ),
				in_array( $scope, (array) $checked, true ) ? 'checked' : '',
				esc_html( $label )
			);
		}
	}

	/* ===================== Handlers ===================== */

	/**
	 * Save the API settings.
	 */
	public function handle_save() {
		$this->guard();
		check_admin_referer( 'fs_api_save' );

		FS_REST_API::save_settings(
			array(
				'enabled'            => ! empty( $_POST['enabled'] ),
				'require_https'      => ! empty( $_POST['require_https'] ),
				'require_signature'  => ! empty( $_POST['require_signature'] ),
				'trust_proxy'        => ! empty( $_POST['trust_proxy'] ),
				'allow_private_urls' => ! empty( $_POST['allow_private_urls'] ),
				'rate_limit'         => max( 1, min( 10000, isset( $_POST['rate_limit'] ) ? (int) $_POST['rate_limit'] : 120 ) ),
				'retention_days'     => max( 1, min( 365, isset( $_POST['retention_days'] ) ? (int) $_POST['retention_days'] : 30 ) ),
				'hook_timeout'       => max( 3, min( 30, isset( $_POST['hook_timeout'] ) ? (int) $_POST['hook_timeout'] : 10 ) ),
				'hook_max_attempts'  => max( 1, min( 12, isset( $_POST['hook_max_attempts'] ) ? (int) $_POST['hook_max_attempts'] : 6 ) ),
			)
		);

		$this->back( 'saved' );
	}

	/**
	 * Mint a key.
	 */
	public function handle_key_create() {
		$this->guard();
		check_admin_referer( 'fs_api_key_create' );

		$minted = FS_API_Keys::mint(
			array(
				'label'       => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
				'scopes'      => isset( $_POST['scopes'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['scopes'] ) ) : array(),
				'require_sig' => ! empty( $_POST['require_sig'] ),
				'ip_allow'    => isset( $_POST['ip_allow'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ip_allow'] ) ) : '',
				'rate_limit'  => isset( $_POST['rate_limit'] ) ? (int) $_POST['rate_limit'] : 0,
				'expires_in'  => isset( $_POST['expires_in'] ) ? (int) $_POST['expires_in'] : 0,
			)
		);

		if ( is_wp_error( $minted ) ) {
			$this->back( 'key_failed' );
		}

		$this->flash_secret( 'key', $minted['token'], __( 'Send it as: Authorization: Bearer <token>', 'fraud-shield' ) );
		$this->back( 'key_created' );
	}

	/**
	 * Revoke a key.
	 */
	public function handle_key_revoke() {
		$this->guard();
		check_admin_referer( 'fs_api_key_revoke' );
		FS_API_Keys::revoke( isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0 );
		$this->back( 'key_revoked' );
	}

	/**
	 * Delete a key.
	 */
	public function handle_key_delete() {
		$this->guard();
		check_admin_referer( 'fs_api_key_delete' );
		FS_API_Keys::delete( isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0 );
		$this->back( 'key_deleted' );
	}

	/**
	 * Open a pairing window.
	 */
	public function handle_pair_start() {
		$this->guard();
		check_admin_referer( 'fs_api_pair_start' );

		$code = FS_API_Keys::create_pairing_code(
			array(
				'label'        => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
				'scopes'       => isset( $_POST['scopes'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['scopes'] ) ) : array(),
				'ttl'          => isset( $_POST['ttl'] ) ? (int) $_POST['ttl'] : 900,
				'require_sig'  => ! empty( $_POST['require_sig'] ),
				'with_webhook' => ! empty( $_POST['with_webhook'] ),
			)
		);

		if ( is_wp_error( $code ) ) {
			$this->back( 'pair_failed' );
		}

		$this->flash_secret(
			'pair',
			$code,
			__( 'Give this to the platform once. It exchanges the code at POST /handshake for its own key.', 'fraud-shield' )
		);
		$this->back( 'paired' );
	}

	/**
	 * Close the pairing window.
	 */
	public function handle_pair_cancel() {
		$this->guard();
		check_admin_referer( 'fs_api_pair_cancel' );
		FS_API_Keys::clear_pairing();
		$this->back( 'pair_cancel' );
	}

	/**
	 * Create a subscription.
	 */
	public function handle_hook_create() {
		$this->guard();
		check_admin_referer( 'fs_api_hook_create' );

		$sub = FS_Webhooks::subscribe(
			array(
				'url'      => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
				'topics'   => isset( $_POST['topics'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['topics'] ) ) : array(),
				'label'    => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
				'hash_pii' => ! empty( $_POST['hash_pii'] ),
			)
		);

		if ( is_wp_error( $sub ) ) {
			// The URL validator's messages are the actionable part here (not
			// HTTPS, resolves privately, unresolvable), so carry them across the
			// redirect for one page view rather than flattening them into a
			// generic failure — or putting them in the URL.
			set_transient( 'fs_api_hook_error_' . get_current_user_id(), $sub->get_error_message(), 5 * MINUTE_IN_SECONDS );
			$this->back( 'hook_failed' );
		}

		$this->flash_secret(
			'hook',
			$sub['secret'],
			__( 'Verify deliveries with: HMAC-SHA256 of "<timestamp>.<body>" against the X-FS-Signature header.', 'fraud-shield' )
		);
		$this->back( 'hook_created' );
	}

	/**
	 * Send a test event.
	 */
	public function handle_hook_test() {
		$this->guard();
		check_admin_referer( 'fs_api_hook_test' );

		$result = FS_Webhooks::send_test( isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0 );
		$ok     = ! is_wp_error( $result ) && ! empty( $result['ok'] );

		$this->back( $ok ? 'hook_tested' : 'hook_test_bad' );
	}

	/**
	 * Enable / disable a subscription.
	 */
	public function handle_hook_toggle() {
		$this->guard();
		check_admin_referer( 'fs_api_hook_toggle' );

		$id   = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;
		$hook = FS_Webhooks::get( $id );
		if ( $hook ) {
			FS_Webhooks::set_status( $id, 'active' === (string) $hook['status'] ? 'disabled' : 'active' );
		}
		$this->back( 'hook_toggled' );
	}

	/**
	 * Delete a subscription.
	 */
	public function handle_hook_delete() {
		$this->guard();
		check_admin_referer( 'fs_api_hook_delete' );
		FS_Webhooks::delete( isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0 );
		$this->back( 'hook_deleted' );
	}
}
