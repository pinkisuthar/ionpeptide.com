<?php
/**
 * The Fraud Shield REST API: everything an external platform needs to ask this
 * store what it knows about a customer, and to act on the answer.
 *
 * Design constraints, in priority order.
 *
 * SECURITY. Nothing here is reachable without a credential (the handshake
 * endpoint excepted, and that one needs a code an admin generated minutes
 * earlier). Credentials are hashed at rest, scoped per route, optionally pinned
 * to a network, and optionally required to sign every request. Signed requests
 * carry a timestamp and a nonce, so a captured request cannot be replayed.
 * Failures are deliberately uninformative to the caller and deliberately
 * detailed in the audit log.
 *
 * PERFORMANCE. The plugin's hot path is checkout, and none of this may touch
 * it. Routes register on rest_api_init, which never fires on a normal page
 * load; authentication is one indexed lookup plus one hash compare; usage
 * accounting is throttled to one write per key per minute; every list endpoint
 * is hard-capped and every filter lands on an indexed column. Webhook delivery
 * never happens inside the request that generated the event.
 *
 * The API's own configuration lives in its own option rather than in
 * fs_settings, because the Settings screen rebuilds that array from defaults on
 * every save and would silently reset anything it does not render.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

/**
 * Named FS_REST_API — NOT FS_API — on purpose, and it must stay that way.
 *
 * The Freemius SDK, bundled inside a great many commercial plugins, declares
 * `class FS_Api` (its includes/class-fs-api.php). PHP class names are
 * case-insensitive, so a class named FS_API here IS that class: on any site
 * running one Freemius-carrying plugin, loading this file fataled with
 * "Cannot declare class FS_API, because the name is already in use" before a
 * single hook ran — the v1.24.0–1.24.4 activation fatal. The other FS_*
 * names in this plugin are verified against the Freemius SDK's inventory
 * (FS_Api, FS_Logger, FS_Plugin, FS_User, FS_Site, FS_Options, FS_Security,
 * FS_Admin_Notices, …) — check any NEW class name against that list first.
 */
class FS_REST_API {

	/**
	 * REST namespace. Shared with the Didit webhook that already lives here.
	 */
	const NS = 'fraud-shield/v1';

	/**
	 * Configuration option (autoloaded: read on every API request).
	 */
	const SETTINGS_OPTION = 'fs_api_settings';

	/**
	 * Cheap "is any of this switched on" cache, so event emission can bail in
	 * one autoloaded read instead of two COUNT queries.
	 */
	const COUNTS_OPTION = 'fs_api_counts';

	/**
	 * How far a signed request's timestamp may drift, in seconds.
	 */
	const SIGNATURE_WINDOW = 300;

	/**
	 * Signature scheme version, first line of the canonical string. Bumping
	 * this is how a future scheme change stays unambiguous.
	 */
	const SIGNATURE_VERSION = 'v1';

	/**
	 * Largest request body accepted, in bytes. A signed request has to be
	 * hashed whole, so an unbounded body is an unbounded amount of work.
	 */
	const MAX_BODY_BYTES = 262144;

	/**
	 * Settings cache for this request.
	 *
	 * @var array|null
	 */
	private static $settings_cache = null;

	/**
	 * The credential that authenticated the request being handled.
	 *
	 * @var array|null
	 */
	private $key = null;

	/**
	 * Rate-limit state for the request being handled, for the response headers.
	 *
	 * @var array|null
	 */
	private $rate = null;

	/**
	 * Wire up. Registering on rest_api_init keeps this entirely off the path of
	 * ordinary page loads and checkouts.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'harden_response' ), 10, 3 );
	}

	/* ===================== Configuration ===================== */

	/**
	 * Defaults for the API's own settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			// Master switch. On by default is safe: with no keys minted, every
			// request is refused anyway, and an admin who mints a key expects it
			// to work without hunting for a second toggle.
			'enabled'           => true,

			// Refuse plaintext HTTP. A bearer token over HTTP is a token given
			// away to every device on the path.
			'require_https'     => true,

			// Force HMAC signing for every key, not just the ones flagged for it.
			'require_signature' => false,

			// Requests per minute per key, when the key does not set its own.
			'rate_limit'        => 120,

			// Handshake attempts allowed per hour per IP.
			'pair_rate_limit'   => 10,

			// Trust WooCommerce's proxy-aware IP resolution. OFF by default: a
			// forwarded-for header is caller-controlled, and an IP allow-list
			// that trusts it is decorative. Turn on only behind a proxy that
			// overwrites the header.
			'trust_proxy'       => false,

			// Days of event log and delivery history to keep.
			'retention_days'    => 30,

			// Webhook delivery.
			'hook_timeout'      => 10,
			'hook_max_attempts' => 6,

			// Allow webhook URLs that resolve to private or loopback addresses.
			// OFF by default: an admin-supplied URL is a server-side request
			// this store will make on demand, which is the shape of an SSRF.
			'allow_private_urls' => false,
		);
	}

	/**
	 * Merged API settings.
	 *
	 * @return array
	 */
	public static function settings() {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}
		$stored               = get_option( self::SETTINGS_OPTION, array() );
		self::$settings_cache = array_merge( self::default_settings(), is_array( $stored ) ? $stored : array() );
		return self::$settings_cache;
	}

	/**
	 * Persist API settings.
	 *
	 * @param array $new Full or partial settings.
	 */
	public static function save_settings( $new ) {
		$merged = array_merge( self::settings(), is_array( $new ) ? $new : array() );
		update_option( self::SETTINGS_OPTION, $merged );
		self::$settings_cache = null;
	}

	/**
	 * Is the API switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		$s = self::settings();
		return ! empty( $s['enabled'] );
	}

	/* ===================== Identity ===================== */

	/**
	 * Stable, non-guessable identifier for this store, safe to hand out.
	 *
	 * A remote platform serving many merchants needs to know which store a
	 * payload came from without us shipping the site URL inside every webhook
	 * body it forwards onward.
	 *
	 * @return string
	 */
	public static function store_id() {
		return substr( hash( 'sha256', 'fs-store|' . home_url( '/' ) ), 0, 32 );
	}

	/**
	 * The store descriptor returned by /ping, /handshake and every webhook.
	 *
	 * @return array
	 */
	public static function store_identity() {
		return array(
			'store_id'    => self::store_id(),
			'name'        => get_bloginfo( 'name' ),
			'url'         => home_url( '/' ),
			'plugin'      => 'fraud-shield',
			'version'     => defined( 'FS_VERSION' ) ? FS_VERSION : '',
			'db_version'  => defined( 'FS_DB_VERSION' ) ? FS_DB_VERSION : '',
			'api_version' => self::SIGNATURE_VERSION,
		);
	}

	/**
	 * The caller's IP address.
	 *
	 * Defaults to the transport-level address, which a caller cannot forge.
	 * WooCommerce's proxy-aware resolution is opt-in precisely because it reads
	 * headers the caller controls.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$s = self::settings();
		if ( ! empty( $s['trust_proxy'] ) && class_exists( 'WC_Geolocation' ) ) {
			$ip = WC_Geolocation::get_ip_address();
			if ( '' !== (string) $ip ) {
				return (string) $ip;
			}
		}
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		$ip = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return $ip ? (string) $ip : '';
	}

	/* ===================== Activity counters ===================== */

	/**
	 * Cached counts of keys and webhook subscriptions.
	 *
	 * Event emission consults this before doing anything at all, so a store
	 * that never enabled the API pays one autoloaded array read — not a query —
	 * for every event that would have been logged.
	 *
	 * @return array { keys:int, hooks:int }
	 */
	public static function counts() {
		$counts = get_option( self::COUNTS_OPTION, array() );
		if ( ! is_array( $counts ) || ! isset( $counts['keys'], $counts['hooks'] ) ) {
			return self::refresh_counts();
		}
		return array(
			'keys'  => (int) $counts['keys'],
			'hooks' => (int) $counts['hooks'],
		);
	}

	/**
	 * Recount and cache. Called by every mutator, and by the admin screen so a
	 * stale count can never outlive a single page view.
	 *
	 * @return array
	 */
	public static function refresh_counts() {
		$counts = array(
			'keys'  => class_exists( 'FS_API_Keys' ) ? (int) FS_API_Keys::counts()['active'] : 0,
			'hooks' => class_exists( 'FS_Webhooks' ) ? (int) FS_Webhooks::active_count() : 0,
		);
		update_option( self::COUNTS_OPTION, $counts );
		return $counts;
	}

	/* ===================== Routing ===================== */

	/**
	 * Register every route.
	 *
	 * Each route names the scope it needs; the permission callback resolves the
	 * credential once and refuses anything short of that scope. Read routes and
	 * write routes are separate scopes so a reporting integration cannot mutate
	 * the blocklist.
	 */
	public function register_routes() {
		// Reads accept their own scope or the matching write scope; writes
		// require the write scope. has_scope() is a plain membership test, so
		// the implication is spelled out here rather than inferred from names.
		$ro = array( 'read:orders', 'write:orders' );
		$rc = array( 'read:customers', 'write:customers' );
		$rb = array( 'read:blocklist', 'write:blocklist' );
		$rv = array( 'read:verified', 'write:verified' );
		$ri = array( 'read:idv', 'write:idv' );

		$routes = array(
			// Diagnostics. Any valid credential.
			array( '/ping', 'GET', 'route_ping', '' ),

			// Pairing. No credential — a one-time code stands in for one.
			array( '/handshake', 'POST', 'route_handshake', false ),

			array( '/stats', 'GET', 'route_stats', 'read:risk' ),
			array( '/settings', 'GET', 'route_settings', 'read:risk' ),
			array( '/risk/evaluate', 'POST', 'route_evaluate', 'read:risk' ),

			// Customers and the places they ship from / to.
			array( '/customers', 'GET', 'route_customers', $rc ),
			array( '/customers/(?P<ident>[^/]+)', 'GET', 'route_customer', $rc ),
			array( '/customers/(?P<ident>[^/]+)/restriction', 'POST', 'route_customer_restrict', 'write:customers' ),
			array( '/customers/(?P<ident>[^/]+)/restriction', 'DELETE', 'route_customer_unrestrict', 'write:customers' ),
			array( '/customers/(?P<ident>[^/]+)/hold', 'DELETE', 'route_customer_release_hold', 'write:customers' ),
			array( '/addresses', 'GET', 'route_addresses', $rc ),
			array( '/addresses/(?P<hash>[A-Fa-f0-9]{64})', 'GET', 'route_address', $rc ),
			array( '/ips', 'GET', 'route_ips', $rc ),
			array( '/ips/(?P<ip>[^/]+)', 'GET', 'route_ip', $rc ),
			array( '/reports/repeat-customers', 'GET', 'route_report_repeat', $rc ),
			array( '/reports/new-customers', 'GET', 'route_report_new', $rc ),
			array( '/restrictions', 'GET', 'route_restrictions', $rc ),
			array( '/holds', 'GET', 'route_holds', $rc ),
			array( '/checkout-locks', 'GET', 'route_locks', $rc ),
			array( '/checkout-locks/(?P<id>\d+)', 'GET', 'route_lock', $rc ),
			array( '/checkout-locks/(?P<id>\d+)', 'DELETE', 'route_lock_dismiss', 'write:customers' ),

			// Orders and the ledgers that hang off them.
			array( '/orders', 'GET', 'route_orders', $ro ),
			array( '/orders/(?P<id>\d+)', 'GET', 'route_order', $ro ),
			array( '/orders/(?P<id>\d+)/chargeback', 'POST', 'route_order_chargeback', 'write:orders' ),
			array( '/orders/(?P<id>\d+)/chargeback', 'DELETE', 'route_order_chargeback_clear', 'write:orders' ),
			array( '/orders/(?P<id>\d+)/recovery', 'POST', 'route_order_recovery', 'write:orders' ),
			array( '/orders/(?P<id>\d+)/recovery', 'DELETE', 'route_order_recovery_clear', 'write:orders' ),
			array( '/orders/(?P<id>\d+)/card-check', 'POST', 'route_order_card_check', 'write:orders' ),
			array( '/card-checks', 'GET', 'route_card_checks', $ro ),
			array( '/linked-cancels', 'GET', 'route_linked_cancels', $ro ),
			array( '/linked-cancels/(?P<id>\d+)', 'GET', 'route_linked_cancel', $ro ),

			// The high-value review queue (v1.32): what awaits a look, what
			// was decided and by whom, the leaderboard, and recording a verdict.
			array( '/high-value', 'GET', 'route_high_value', $ro ),
			array( '/high-value/reviews', 'GET', 'route_high_value_reviews', $ro ),
			array( '/high-value/reviews/(?P<id>\d+)', 'GET', 'route_high_value_review', $ro ),
			array( '/high-value/leaderboard', 'GET', 'route_high_value_leaderboard', $ro ),
			array( '/orders/(?P<id>\d+)/review', 'POST', 'route_order_review', 'write:orders' ),

			// Blocklist: credentials, and the reversible block cards that own them.
			array( '/blocklist', 'GET', 'route_blocklist_list', $rb ),
			array( '/blocklist', 'POST', 'route_blocklist_add', 'write:blocklist' ),
			array( '/blocklist', 'DELETE', 'route_blocklist_bulk_delete', 'write:blocklist' ),
			array( '/blocklist/(?P<id>\d+)', 'DELETE', 'route_blocklist_delete', 'write:blocklist' ),
			array( '/blocklist/(?P<id>\d+)', 'PATCH', 'route_blocklist_message', 'write:blocklist' ),
			array( '/blocklist/actions', 'GET', 'route_block_actions', $rb ),
			array( '/blocklist/actions/(?P<id>\d+)', 'GET', 'route_block_action', $rb ),
			array( '/blocklist/actions/(?P<id>\d+)', 'DELETE', 'route_block_action_delete', 'write:blocklist' ),
			array( '/blocklist/actions/(?P<id>\d+)', 'PATCH', 'route_block_action_message', 'write:blocklist' ),
			array( '/blocklist/actions/(?P<id>\d+)/resync', 'POST', 'route_block_action_resync', 'write:blocklist' ),

			// The known-good lists.
			array( '/verified', 'GET', 'route_verified_list', $rv ),
			array( '/verified', 'POST', 'route_verified_add', 'write:verified' ),
			array( '/verified', 'DELETE', 'route_verified_delete', 'write:verified' ),
			array( '/allowlist', 'GET', 'route_allowlist', $rv ),
			array( '/allowlist', 'POST', 'route_allowlist_add', 'write:verified' ),
			array( '/allowlist', 'DELETE', 'route_allowlist_delete', 'write:verified' ),

			// ID verification.
			array( '/idv/sessions', 'GET', 'route_idv_sessions', $ri ),
			array( '/idv/sessions/(?P<id>\d+)/accept', 'POST', 'route_idv_accept', 'write:idv' ),
			array( '/idv/requests', 'GET', 'route_idv_requests', $ri ),
			array( '/idv/require', 'POST', 'route_idv_require', 'write:idv' ),
			array( '/idv/require', 'DELETE', 'route_idv_require_clear', 'write:idv' ),
			array( '/idv/verified', 'DELETE', 'route_idv_revoke', 'write:idv' ),

			// Insights: the Dashboard's savings panel, the Financial Protection
			// report, the Customer Network and the Geography rollups.
			array( '/insights/prevented', 'GET', 'route_insights_prevented', 'read:insights' ),
			array( '/insights/financial', 'GET', 'route_insights_financial', 'read:insights' ),
			array( '/network', 'GET', 'route_network', 'read:insights' ),
			array( '/network/(?P<ident>[^/]+)', 'GET', 'route_network_customer', 'read:insights' ),
			array( '/geography/(?P<level>state|city|zip|street)', 'GET', 'route_geography', 'read:insights' ),
			array( '/geography/(?P<level>state|city|zip|street)/customers', 'GET', 'route_geography_customers', 'read:insights' ),

			// The Dashboard's scan panel, read-only: whether a scan is running
			// and how far it has got. Starting one stays a wp-admin action.
			array( '/scan', 'GET', 'route_scan', 'read:risk' ),

			array( '/events', 'GET', 'route_events', 'read:events' ),

			array( '/webhooks', 'GET', 'route_hooks_list', 'manage:webhooks' ),
			array( '/webhooks', 'POST', 'route_hooks_create', 'manage:webhooks' ),
			array( '/webhooks/(?P<id>\d+)', 'DELETE', 'route_hooks_delete', 'manage:webhooks' ),
			array( '/webhooks/(?P<id>\d+)/test', 'POST', 'route_hooks_test', 'manage:webhooks' ),
		);

		foreach ( $routes as $route ) {
			list( $path, $method, $handler, $scope ) = $route;

			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $method,
					'callback'            => array( $this, $handler ),
					'permission_callback' => false === $scope
						? array( $this, 'permission_open' )
						: function ( $request ) use ( $scope ) {
							return $this->authorize( $request, $scope );
						},
				)
			);
		}
	}

	/**
	 * Permission callback for the handshake: no credential, but the API must be
	 * on and the transport must be secure. The code check happens in the
	 * handler, where a failure can be rate-limited and audited.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission_open( $request ) {
		$this->key  = null;
		$this->rate = null;

		if ( ! self::enabled() ) {
			return self::error( 'fs_api_disabled', __( 'The Fraud Shield API is switched off on this store.', 'fraud-shield' ), 503 );
		}
		return $this->check_transport( $request );
	}

	/* ===================== Authentication ===================== */

	/**
	 * Resolve and authorize the credential behind a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $scope   Required scope.
	 * @return true|WP_Error
	 */
	public function authorize( $request, $scope ) {
		// Per-request state, cleared up front: one PHP process can serve several
		// requests, and stale limit figures must never be reported on a later
		// response that belongs to a different credential.
		$this->key  = null;
		$this->rate = null;

		if ( ! self::enabled() ) {
			return self::error( 'fs_api_disabled', __( 'The Fraud Shield API is switched off on this store.', 'fraud-shield' ), 503 );
		}

		$transport = $this->check_transport( $request );
		if ( is_wp_error( $transport ) ) {
			return $transport;
		}

		$settings = self::settings();
		$ip       = self::client_ip();

		// Which credential is being presented, and how.
		$bearer = $this->bearer_token( $request );
		$signed = (string) $request->get_header( 'x_fs_key' );

		if ( '' === $bearer && '' === $signed ) {
			return self::error( 'fs_api_no_credentials', __( 'Authentication required.', 'fraud-shield' ), 401 );
		}

		if ( '' !== $signed ) {
			$row = FS_API_Keys::verify_token( $signed );
		} else {
			$row = FS_API_Keys::verify_token( $bearer );
		}

		if ( ! $row ) {
			FS_API_Keys::audit_throttled( 'auth.bad_key', '', 'unrecognized or malformed credential' );
			return self::error( 'fs_api_unauthorized', __( 'Authentication failed.', 'fraud-shield' ), 401 );
		}

		$unusable = FS_API_Keys::unusable_reason( $row );
		if ( '' !== $unusable ) {
			FS_API_Keys::audit_throttled( 'auth.' . $unusable, (string) $row['key_id'], (string) $row['label'] );
			return self::error( 'fs_api_unauthorized', __( 'Authentication failed.', 'fraud-shield' ), 401 );
		}

		if ( ! FS_API_Keys::ip_allowed( $row, $ip ) ) {
			FS_API_Keys::audit_throttled( 'auth.ip_blocked', (string) $row['key_id'], $ip );
			return self::error( 'fs_api_unauthorized', __( 'Authentication failed.', 'fraud-shield' ), 401 );
		}

		// Signing: demanded globally, or per key. A key that must sign cannot
		// fall back to a bearer token — otherwise the requirement is advisory.
		$must_sign = ! empty( $settings['require_signature'] ) || ! empty( $row['require_sig'] );
		if ( $must_sign ) {
			$verified = $this->verify_signature( $request, $row );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
		} elseif ( '' !== $signed ) {
			// Signature headers present on a key that does not require them:
			// still verify. Presenting a signature and having it ignored is a
			// worse outcome than being told it was wrong.
			$verified = $this->verify_signature( $request, $row );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
		}

		// Rate limit before scope, so a key hammering a route it cannot reach
		// is throttled rather than merely refused over and over.
		$rate = $this->check_rate( $row );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		if ( ! FS_API_Keys::has_scope( $row, $scope ) ) {
			$needed = implode( ' or ', (array) $scope );
			FS_API_Keys::audit_throttled( 'auth.scope_denied', (string) $row['key_id'], $needed );
			return self::error(
				'fs_api_forbidden',
				/* translators: %s: the required scope(s), e.g. "read:orders" or "read:idv or write:idv" */
				sprintf( __( 'This key does not carry the %s scope.', 'fraud-shield' ), $needed ),
				403
			);
		}

		$this->key = $row;
		FS_API_Keys::touch( $row, $ip );
		return true;
	}

	/**
	 * Refuse plaintext transport and oversized bodies.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private function check_transport( $request ) {
		$settings = self::settings();

		if ( ! empty( $settings['require_https'] ) && ! is_ssl() ) {
			// A reverse proxy that terminates TLS may leave is_ssl() false while
			// the customer-facing connection was encrypted; WordPress' own
			// forwarded-proto convention is honoured so those stores still work.
			$proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) : '';
			if ( 'https' !== $proto ) {
				return self::error( 'fs_api_insecure', __( 'This API requires HTTPS.', 'fraud-shield' ), 403 );
			}
		}

		// Refuse multipart outright. PHP consumes a multipart body into $_POST and
		// leaves php://input empty, so the raw body WordPress hands us — the body
		// the signature commits to, and the body the size cap measures — would be
		// empty while the handler still reads the caller's parameters. That turns
		// a signed empty-body request into a signed blank cheque. This API speaks
		// JSON; nothing here needs file uploads.
		$type = strtolower( (string) $request->get_header( 'content_type' ) );
		if ( '' !== $type && 0 === strpos( $type, 'multipart/' ) ) {
			return self::error( 'fs_api_unsupported_media', __( 'Send a JSON body; multipart requests are not accepted.', 'fraud-shield' ), 415 );
		}

		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return self::error( 'fs_api_body_too_large', __( 'Request body is too large.', 'fraud-shield' ), 413 );
		}

		return true;
	}

	/**
	 * Pull a bearer token out of the Authorization header.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function bearer_token( $request ) {
		$header = (string) $request->get_header( 'authorization' );
		if ( '' === $header || 0 !== stripos( $header, 'bearer ' ) ) {
			return '';
		}
		return trim( substr( $header, 7 ) );
	}

	/**
	 * Verify an HMAC-signed request.
	 *
	 * The signature covers the method, the route, the sorted query, a digest of
	 * the body, the timestamp and the nonce — so none of those can be altered
	 * in flight, and the same request cannot be sent twice. The nonce is
	 * remembered for twice the acceptance window, which is exactly long enough
	 * that a replay is either a duplicate nonce or an expired timestamp.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array           $row     Key row.
	 * @return true|WP_Error
	 */
	private function verify_signature( $request, $row ) {
		$key_id    = (string) $row['key_id'];
		$timestamp = (string) $request->get_header( 'x_fs_timestamp' );
		$nonce     = (string) $request->get_header( 'x_fs_nonce' );
		$presented = (string) $request->get_header( 'x_fs_signature' );

		if ( '' === $timestamp || '' === $nonce || '' === $presented ) {
			FS_API_Keys::audit_throttled( 'auth.signature_missing', $key_id, 'timestamp, nonce or signature absent' );
			return self::error( 'fs_api_signature_required', __( 'This key requires signed requests.', 'fraud-shield' ), 401 );
		}
		if ( ! ctype_digit( $timestamp ) ) {
			return self::error( 'fs_api_bad_signature', __( 'Signature verification failed.', 'fraud-shield' ), 401 );
		}
		if ( abs( time() - (int) $timestamp ) > self::SIGNATURE_WINDOW ) {
			FS_API_Keys::audit_throttled( 'auth.stale_timestamp', $key_id, 'outside the ' . self::SIGNATURE_WINDOW . 's window' );
			return self::error( 'fs_api_stale_request', __( 'Request timestamp is outside the accepted window.', 'fraud-shield' ), 401 );
		}
		if ( strlen( $nonce ) < 8 || strlen( $nonce ) > 128 || ! preg_match( '/^[A-Za-z0-9._:-]+$/', $nonce ) ) {
			return self::error( 'fs_api_bad_nonce', __( 'Signature verification failed.', 'fraud-shield' ), 401 );
		}

		$replay_key = 'fs_api_n_' . hash( 'sha256', $key_id . '|' . $nonce );
		if ( false !== get_transient( $replay_key ) ) {
			FS_API_Keys::audit_throttled( 'auth.replay', $key_id, 'nonce seen before' );
			return self::error( 'fs_api_replay', __( 'This request has already been used.', 'fraud-shield' ), 409 );
		}

		// The secret never leaves the presented token: we re-derive it from the
		// header rather than storing it, because only its digest is on disk.
		$parsed = FS_API_Keys::parse_token( (string) $request->get_header( 'x_fs_key' ) );
		if ( null === $parsed ) {
			return self::error( 'fs_api_bad_signature', __( 'Signature verification failed.', 'fraud-shield' ), 401 );
		}

		$expected = hash_hmac( 'sha256', self::canonical_string( $request, $timestamp, $nonce ), $parsed['secret'] );
		$given    = strtolower( trim( preg_replace( '/^v1=/', '', $presented ) ) );

		if ( ! hash_equals( $expected, $given ) ) {
			FS_API_Keys::audit_throttled( 'auth.bad_signature', $key_id, $request->get_method() . ' ' . $request->get_route() );
			return self::error( 'fs_api_bad_signature', __( 'Signature verification failed.', 'fraud-shield' ), 401 );
		}

		set_transient( $replay_key, 1, self::SIGNATURE_WINDOW * 2 );
		return true;
	}

	/**
	 * Build the string a signature is computed over.
	 *
	 * Uses the REST route rather than the raw request URI so a store reached
	 * through /wp-json/ and one reached through ?rest_route= sign identically,
	 * and rebuilds the query from parsed parameters so encoding differences
	 * between HTTP clients cannot break an otherwise valid signature.
	 *
	 * @param WP_REST_Request $request   Request.
	 * @param string          $timestamp Unix timestamp header.
	 * @param string          $nonce     Nonce header.
	 * @return string
	 */
	public static function canonical_string( $request, $timestamp, $nonce ) {
		$query = $request->get_query_params();
		unset( $query['rest_route'], $query['_locale'] );
		ksort( $query );

		$pairs = array();
		foreach ( $query as $k => $v ) {
			if ( is_array( $v ) ) {
				$v = implode( ',', array_map( 'strval', $v ) );
			}
			$pairs[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
		}

		return implode(
			"\n",
			array(
				self::SIGNATURE_VERSION,
				strtoupper( (string) $request->get_method() ),
				(string) $request->get_route(),
				implode( '&', $pairs ),
				hash( 'sha256', (string) $request->get_body() ),
				(string) $timestamp,
				(string) $nonce,
			)
		);
	}

	/* ===================== Rate limiting ===================== */

	/**
	 * Fixed-window request counter for one key.
	 *
	 * Uses the object cache atomically when one is available and transients
	 * otherwise. The transient path can undercount under heavy concurrency;
	 * that is an acceptable trade for not adding a database write per request,
	 * and the window is short enough that the ceiling still holds in practice.
	 *
	 * @param array $row Key row.
	 * @return array|WP_Error { limit, remaining, reset }
	 */
	private function check_rate( $row ) {
		$settings = self::settings();
		$limit    = (int) $row['rate_limit'] > 0 ? (int) $row['rate_limit'] : (int) $settings['rate_limit'];
		$limit    = max( 1, min( 10000, $limit ) );

		$window = MINUTE_IN_SECONDS;
		$slot   = (int) floor( time() / $window );
		$reset  = ( $slot + 1 ) * $window;
		$bucket = 'fs_api_rl_' . $row['key_id'] . '_' . $slot;

		$used = self::bump_counter( $bucket, $window * 2 );

		$this->rate = array(
			'limit'     => $limit,
			'remaining' => max( 0, $limit - $used ),
			'reset'     => $reset,
		);

		if ( $used > $limit ) {
			FS_API_Keys::audit_throttled( 'auth.rate_limited', (string) $row['key_id'], $used . '/' . $limit . ' per minute' );
			return self::error(
				'fs_api_rate_limited',
				__( 'Rate limit exceeded. Slow down and try again shortly.', 'fraud-shield' ),
				429,
				array( 'retry_after' => max( 1, $reset - time() ) )
			);
		}

		return $this->rate;
	}

	/**
	 * Increment a short-lived counter and return its new value.
	 *
	 * @param string $bucket Counter key.
	 * @param int    $ttl    Lifetime in seconds.
	 * @return int
	 */
	public static function bump_counter( $bucket, $ttl ) {
		if ( wp_using_ext_object_cache() ) {
			// add() then incr() is the atomic idiom: exactly one caller wins the
			// add, everyone else increments what it created.
			if ( wp_cache_add( $bucket, 1, 'fraud-shield', $ttl ) ) {
				return 1;
			}
			$used = wp_cache_incr( $bucket, 1, 'fraud-shield' );
			return false === $used ? 1 : (int) $used;
		}

		$used = (int) get_transient( $bucket ) + 1;
		set_transient( $bucket, $used, $ttl );
		return $used;
	}

	/* ===================== Responses ===================== */

	/**
	 * A successful response.
	 *
	 * @param mixed $data   Payload.
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	private function ok( $data, $status = 200 ) {
		return new WP_REST_Response( $data, $status );
	}

	/**
	 * An error, in WordPress' standard REST error shape.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human message. Never contains customer data.
	 * @param int    $status  HTTP status.
	 * @param array  $extra   Extra data merged into the error payload.
	 * @return WP_Error
	 */
	public static function error( $code, $message, $status = 400, $extra = array() ) {
		return new WP_Error( $code, $message, array_merge( array( 'status' => (int) $status ), $extra ) );
	}

	/**
	 * Attach security and rate-limit headers to everything this namespace
	 * returns, successes and failures alike.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public function harden_response( $response, $server, $request ) {
		if ( ! is_a( $response, 'WP_HTTP_Response' ) || ! is_a( $request, 'WP_REST_Request' ) ) {
			return $response;
		}
		if ( 0 !== strpos( (string) $request->get_route(), '/' . self::NS ) ) {
			return $response;
		}

		// Risk answers are per-customer and must never be cached by a proxy.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'Referrer-Policy', 'no-referrer' );

		if ( is_array( $this->rate ) ) {
			$response->header( 'X-RateLimit-Limit', (string) $this->rate['limit'] );
			$response->header( 'X-RateLimit-Remaining', (string) $this->rate['remaining'] );
			$response->header( 'X-RateLimit-Reset', (string) $this->rate['reset'] );
		}
		if ( 429 === (int) $response->get_status() && is_array( $this->rate ) ) {
			$response->header( 'Retry-After', (string) max( 1, $this->rate['reset'] - time() ) );
		}

		return $response;
	}

	/* ===================== Routes: diagnostics ===================== */

	/**
	 * Confirm a credential works and report what this store can do.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_ping( $request ) {
		$settings = FS_Core::instance()->settings();

		return $this->ok(
			array(
				'ok'    => true,
				'store' => self::store_identity(),
				'time'  => gmdate( 'c' ),
				'key'   => array(
					'key_id' => (string) $this->key['key_id'],
					'label'  => (string) $this->key['label'],
					'scopes' => preg_split( '/,/', (string) $this->key['scopes'], -1, PREG_SPLIT_NO_EMPTY ),
					'signed' => ! empty( $this->key['require_sig'] ),
				),
				'features' => array(
					'mode'            => (string) $settings['mode'],
					'id_verification' => class_exists( 'FS_IDV' ) && FS_IDV::configured(),
					'cardholder_check' => class_exists( 'FS_Cardholder' ) && FS_Cardholder::enabled(),
					'webhook_topics'  => array_keys( FS_Webhooks::topics() ),
					// v1.30: which deliberate controls this store has switched on,
					// so a platform knows which decisions /risk/evaluate can reach
					// and which events it can expect.
					'controls'        => array(
						'action_high'          => (string) ( $settings['action_high'] ?? 'hold' ),
						'linked_cancel'        => ! empty( $settings['linked_cancel_enabled'] ),
						'linked_cancel_types'  => array_values( (array) ( $settings['linked_cancel_types'] ?? array() ) ),
						'auto_extend_blocks'   => ! empty( $settings['auto_extend_blocks'] ),
						'velocity_lock'        => ! empty( $settings['velocity_lock'] ),
						'velocity_lock_action' => FS_Live::velocity_action( $settings ),
						'velocity_lock_window' => (int) ( $settings['velocity_lock_window'] ?? 30 ),
						'velocity_lock_window_unit' => (string) ( $settings['velocity_lock_window_unit'] ?? 'days' ),
						'new_account_hold'     => ! empty( $settings['hold_enabled'] ),
						'gateway_restriction'  => ! empty( $settings['gateway_restrict_enabled'] ),
						'high_value_rules'     => ! empty( $settings['hv_enabled'] ),
						'auto_verify'          => ! empty( $settings['auto_verify_enabled'] ),
						'amount_basis'         => (string) ( $settings['amount_basis'] ?? 'subtotal' ),
					),
					'scopes'          => array_keys( FS_API_Keys::scopes() ),
				),
			)
		);
	}

	/**
	 * Aggregate counters — the numbers a remote dashboard opens with.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_stats( $request ) {
		$stats  = FS_Data::summary_stats();
		$linked = FS_Data::linked_cancel_stats();
		$all    = FS_Data::prevented_stats( 0 );
		$month  = FS_Data::prevented_stats( 30 );

		return $this->ok(
			array(
				'orders_indexed' => (int) $stats['indexed'],
				'chargebacks'    => (int) $stats['chargebacks'],
				'high_risk'      => (int) $stats['high'],
				'medium_risk'    => (int) $stats['medium'],
				'blocklist'      => (int) $stats['blocklist'],
				'block_actions'  => (int) FS_Blocklist::actions_count(),
				'checkout_blocks' => (int) FS_Data::checkout_blocks_count(),
				'verified_credentials' => count( FS_Live::verified_creds() ),
				'allowlist'      => count( FS_Live::allowlist() ),
				'id_verification' => FS_Data::idv_stats(),
				// v1.30: the ledgers added since the API shipped.
				'linked_cancels' => array(
					'total'    => (int) $linked['total'],
					'last_30d' => (int) $linked['last_30d'],
					'value'    => (float) $linked['value'],
					'retries'  => (int) $linked['retries'],
					'currency' => (string) $linked['currency'],
				),
				'prevented'      => array(
					'currency'      => (string) $all['currency'],
					'all_time'      => self::shape_prevented_stats( $all ),
					'last_30d'      => self::shape_prevented_stats( $month ),
				),
				'card_checks'    => class_exists( 'FS_Cardholder' ) ? count( FS_Cardholder::log_rows() ) : 0,
				'webhooks'       => FS_Webhooks::stats(),
			)
		);
	}

	/**
	 * The merchant-visible configuration that shapes every answer this API
	 * gives: the mode, the rule weights, thresholds and level cut-offs, and
	 * the deliberate controls with their numbers. Read-only, and never the
	 * secrets (Didit / NMI keys, webhook secrets) or the free-text messages.
	 *
	 * A platform that interprets `risk.score` across many stores needs this to
	 * know what a 70 means on THIS one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_settings( $request ) {
		$s = FS_Core::instance()->settings();

		$numeric = function ( $arr ) {
			$out = array();
			foreach ( (array) $arr as $k => $v ) {
				$out[ (string) $k ] = is_numeric( $v ) ? ( false === strpos( (string) $v, '.' ) ? (int) $v : (float) $v ) : $v;
			}
			return $out;
		};

		return $this->ok(
			array(
				'mode'          => (string) $s['mode'],
				'action_high'   => (string) ( $s['action_high'] ?? 'hold' ),
				'count_refunded_as_chargeback' => ! empty( $s['count_refunded_as_chargeback'] ),
				'amount_basis'  => (string) ( $s['amount_basis'] ?? 'subtotal' ),
				'scoring'       => array(
					'levels'     => $numeric( $s['levels'] ?? array() ),
					'weights'    => $numeric( $s['weights'] ?? array() ),
					'thresholds' => $numeric( $s['thresholds'] ?? array() ),
					'disposable_domains' => count( (array) ( $s['disposable_domains'] ?? array() ) ),
				),
				'controls'      => array(
					'auto_extend_blocks' => ! empty( $s['auto_extend_blocks'] ),
					'linked_cancel'      => array(
						'enabled' => ! empty( $s['linked_cancel_enabled'] ),
						'types'   => array_values( (array) ( $s['linked_cancel_types'] ?? array() ) ),
					),
					'velocity_lock'      => array(
						'enabled'    => ! empty( $s['velocity_lock'] ),
						'threshold'  => (int) ( $s['velocity_lock_threshold'] ?? 0 ),
						'action'     => FS_Live::velocity_action( $s ),
						'window'     => FS_Live::velocity_window_label( $s ),
						'gateways'   => array_values( (array) ( $s['velocity_restrict_gateways'] ?? array() ) ),
						'new_only'   => ! empty( $s['velocity_lock_new_only'] ),
						'new_months' => (int) ( $s['velocity_lock_new_months'] ?? 0 ),
					),
					'new_account_hold'   => array(
						'enabled'    => ! empty( $s['hold_enabled'] ),
						'days'       => (int) ( $s['hold_days'] ?? 0 ),
						'amount'     => (float) ( $s['hold_amount'] ?? 0 ),
						'hold_order' => ! empty( $s['hold_order'] ),
					),
					'gateway_restriction' => array(
						'enabled'            => ! empty( $s['gateway_restrict_enabled'] ),
						'days'               => (int) ( $s['gateway_restrict_days'] ?? 0 ),
						'allowed'            => array_values( (array) ( $s['gateway_restrict_allowed'] ?? array() ) ),
						'low_value_amount'   => (float) ( $s['gateway_restrict_lowvalue_amount'] ?? 0 ),
						'low_value_gateways' => array_values( (array) ( $s['gateway_restrict_lowvalue_gateways'] ?? array() ) ),
						'expire_months'      => (int) ( $s['gw_restrict_expire_months'] ?? 0 ),
						'release_on_idv'     => ! empty( $s['gw_restrict_release_on_idv'] ),
					),
					'auto_verify'        => array(
						'enabled'       => ! empty( $s['auto_verify_enabled'] ),
						'min_days'      => (int) ( $s['auto_verify_min_days'] ?? 0 ),
						'min_orders'    => (int) ( $s['auto_verify_min_orders'] ?? 0 ),
						'require_clean' => ! empty( $s['auto_verify_require_clean'] ),
					),
					'high_value'         => array(
						'enabled'     => ! empty( $s['hv_enabled'] ),
						'amount_none' => (float) ( $s['hv_amount_none'] ?? 0 ),
						'amount_auto' => (float) ( $s['hv_amount_auto'] ?? 0 ),
						'amount_idv'  => (float) ( $s['hv_amount_idv'] ?? 0 ),
						'hard_amount' => (float) ( $s['hv_hard_amount'] ?? 0 ),
						'fresh_hours' => (int) ( $s['hv_fresh_hours'] ?? 0 ),
						'gateways'    => array_values( (array) ( $s['hv_gateways'] ?? array() ) ),
					),
					'id_verification'    => array(
						'configured'      => class_exists( 'FS_IDV' ) && FS_IDV::configured(),
						'name_match'      => (string) ( $s['idv_name_match'] ?? '' ),
						'mismatch_action' => (string) ( $s['idv_mismatch_action'] ?? '' ),
						'valid_days'      => (int) ( $s['idv_valid_days'] ?? 0 ),
						'max_attempts'    => (int) ( $s['idv_max_attempts'] ?? 0 ),
					),
					'cardholder_check'   => array(
						'enabled'        => class_exists( 'FS_Cardholder' ) && FS_Cardholder::enabled(),
						'gateways'       => array_values( (array) ( $s['card_check_gateways'] ?? array() ) ),
						'accept_partial' => ! empty( $s['card_check_accept_partial'] ),
						'action'         => (string) ( $s['card_check_action'] ?? 'flag' ),
						'scope'          => (string) ( $s['card_check_scope'] ?? 'all' ),
					),
				),
				'financial'     => array(
					'chargeback_fee'  => (float) ( $s['fin_chargeback_fee'] ?? 0 ),
					'loss_ratio'      => (int) ( $s['fin_loss_ratio'] ?? 100 ),
					'min_window_days' => (int) ( $s['fin_min_window_days'] ?? 90 ),
				),
				'classification' => array(
					'payment_methods'  => FS_Core::payment_labels(),
					'shipping_methods' => FS_Core::shipping_labels(),
				),
			)
		);
	}

	/**
	 * The Dashboard's scan panel, read-only (v1.30): whether a back-history
	 * scan or a re-score is running, how far it has got, and how much of the
	 * index is still unscored. Starting, pausing and cancelling a scan remain
	 * wp-admin actions — they are operations on the store, not facts about it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_scan( $request ) {
		$s        = class_exists( 'FS_Scanner' ) ? FS_Scanner::get_state() : array();
		$status   = (string) ( $s['status'] ?? 'idle' );
		$phase    = (string) ( $s['phase'] ?? '' );
		$total    = 'score' === $phase ? (int) ( $s['score_total'] ?? 0 ) : (int) ( $s['index_total'] ?? 0 );
		$done     = (int) ( $s['offset'] ?? 0 );
		$unscored = FS_Data::unscored_count();

		return $this->ok(
			array(
				'status'       => $status,
				'kind'         => (string) ( $s['kind'] ?? '' ),
				'phase'        => $phase,
				'range'        => (string) ( $s['range'] ?? '' ),
				'date_after'   => ! empty( $s['date_after'] ) ? gmdate( 'c', (int) $s['date_after'] ) : null,
				'skip_indexed' => ! empty( $s['skip_indexed'] ),
				'score_mode'   => (string) ( $s['score_mode'] ?? '' ),
				'progress'     => array(
					'done'    => $done,
					'total'   => $total,
					'percent' => $total > 0 ? round( min( 100, $done / $total * 100 ), 1 ) : null,
				),
				'index'        => array(
					'orders'   => FS_Data::index_count(),
					'unscored' => $unscored,
				),
				'started_at'   => empty( $s['started_at'] ) ? null : mysql2date( 'c', $s['started_at'] ),
				'updated_at'   => empty( $s['updated_at'] ) ? null : mysql2date( 'c', $s['updated_at'] ),
			)
		);
	}

	/**
	 * Typed view of one prevented_stats() window.
	 *
	 * @param array $p Output of FS_Data::prevented_stats().
	 * @return array
	 */
	private static function shape_prevented_stats( $p ) {
		return array(
			'fraud_amount'  => (float) ( $p['fraud_amount'] ?? 0 ),
			'verify_amount' => (float) ( $p['verify_amount'] ?? 0 ),
			'fraud_stopped' => (int) ( $p['fraud_stopped'] ?? 0 ),
			'cancelled'     => (int) ( $p['cancelled'] ?? 0 ),
			'refused'       => (int) ( $p['refused'] ?? 0 ),
			'attempts'      => (int) ( $p['attempts'] ?? 0 ),
			'staff_stopped' => (int) ( $p['staff_stopped'] ?? 0 ),
			'staff_amount'  => (float) ( $p['staff_amount'] ?? 0 ),
		);
	}

	/**
	 * Typed view of whom a stop was tied to (v1.30): the blocked identities
	 * behind the shared credential or the matched blocklist entry.
	 *
	 * @param array $linked_to Decoded `linked_to` list.
	 * @return array [ { email_norm, user_id, name } ]
	 */
	private static function shape_linked_to( $linked_to ) {
		$out = array();
		foreach ( (array) $linked_to as $id ) {
			if ( ! is_array( $id ) ) {
				continue;
			}
			$out[] = array(
				'email_norm' => (string) ( $id['email'] ?? '' ),
				'user_id'    => (int) ( $id['user_id'] ?? 0 ),
				'name'       => (string) ( $id['name'] ?? '' ),
			);
		}
		return $out;
	}

	/* ===================== Routes: handshake ===================== */

	/**
	 * Exchange a one-time pairing code for a real credential.
	 *
	 * This is the whole point of the pairing dance: the platform ends up with a
	 * scoped key and (optionally) a webhook secret that only ever existed in
	 * this one response, and the admin never copies a permanent secret between
	 * two systems by hand.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_handshake( $request ) {
		$settings = self::settings();
		$ip       = self::client_ip();

		// Throttle by address: the code is unguessable, but an open endpoint
		// still deserves a ceiling.
		$slot = (int) floor( time() / HOUR_IN_SECONDS );
		$used = self::bump_counter( 'fs_api_pair_' . hash( 'sha256', $ip ) . '_' . $slot, HOUR_IN_SECONDS * 2 );
		if ( $used > max( 1, (int) $settings['pair_rate_limit'] ) ) {
			FS_API_Keys::audit_throttled( 'pairing.rate_limited', '', $ip );
			return self::error( 'fs_api_rate_limited', __( 'Too many pairing attempts. Try again later.', 'fraud-shield' ), 429 );
		}

		$body = $this->json_body( $request );
		$code = isset( $body['code'] ) ? (string) $body['code'] : '';

		$terms = FS_API_Keys::consume_pairing_code( $code );
		if ( is_wp_error( $terms ) ) {
			FS_API_Keys::audit_throttled( 'pairing.failed', '', $ip );
			return $terms;
		}

		$platform = isset( $body['platform'] ) ? sanitize_text_field( (string) $body['platform'] ) : '';
		$label    = '' !== $platform
			/* translators: %s: name the remote platform gave for itself */
			? sprintf( __( '%s (paired)', 'fraud-shield' ), $platform )
			: (string) $terms['label'];

		$minted = FS_API_Keys::mint(
			array(
				'label'       => $label,
				'scopes'      => $terms['scopes'],
				'require_sig' => ! empty( $terms['require_sig'] ),
				'origin'      => 'handshake',
			)
		);
		if ( is_wp_error( $minted ) ) {
			return $minted;
		}

		// Optional companion subscription, so events start flowing immediately
		// instead of after a second round trip.
		$hook = null;
		if ( ! empty( $terms['with_webhook'] ) && ! empty( $body['callback_url'] ) ) {
			$topics = isset( $body['topics'] ) ? (array) $body['topics'] : array_keys( FS_Webhooks::topics() );
			$sub    = FS_Webhooks::subscribe(
				array(
					'url'        => (string) $body['callback_url'],
					'topics'     => $topics,
					'label'      => $label,
					'created_by' => 0,
				)
			);
			if ( is_wp_error( $sub ) ) {
				// A bad callback URL must not cost the platform its credential;
				// report the problem alongside the key it did get.
				$hook = array( 'error' => $sub->get_error_message() );
			} else {
				$hook = array(
					'id'        => (int) $sub['id'],
					'secret'    => (string) $sub['secret'],
					'topics'    => $sub['topics'],
					'signature' => 'X-FS-Signature: t=<unix>,v1=<hex hmac-sha256 of "t.body">',
				);
			}
		}

		FS_API_Keys::audit( 'pairing.completed', $minted['key_id'], $platform );

		return $this->ok(
			array(
				'ok'         => true,
				'store'      => self::store_identity(),
				'api'        => array(
					'base'      => rest_url( self::NS ),
					'namespace' => self::NS,
					'signing'   => array(
						'required'  => ! empty( $terms['require_sig'] ) || ! empty( $settings['require_signature'] ),
						'algorithm' => 'HMAC-SHA256',
						'window'    => self::SIGNATURE_WINDOW,
						'canonical' => 'v1\nMETHOD\nroute\nsorted_query\nsha256(body)\ntimestamp\nnonce',
						'headers'   => array( 'X-FS-Key', 'X-FS-Timestamp', 'X-FS-Nonce', 'X-FS-Signature' ),
					),
				),
				'credential' => array(
					'token'  => $minted['token'],
					'key_id' => $minted['key_id'],
					'scopes' => $terms['scopes'],
					'notice' => __( 'Store this token now. It cannot be retrieved again.', 'fraud-shield' ),
				),
				'webhook'    => $hook,
			),
			201
		);
	}

	/* ===================== Routes: risk ===================== */

	/**
	 * Score an identity that has no order yet.
	 *
	 * The single most useful thing an external platform can ask: given an
	 * email, an address, an IP and an amount, what does this store already know
	 * — and what would it do at checkout right now?
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_evaluate( $request ) {
		$body = $this->json_body( $request );
		if ( empty( $body ) ) {
			return self::error( 'fs_api_empty_payload', __( 'Provide at least one identity signal.', 'fraud-shield' ), 400 );
		}

		$signals = self::signals_from_payload( $body );
		if ( '' === $signals['email_norm'] && '' === $signals['ip'] && '' === $signals['phone_norm'] && '' === $signals['ship_hash'] && $signals['user_id'] <= 0 ) {
			return self::error( 'fs_api_no_signals', __( 'Provide an email, phone, IP, address or user_id.', 'fraud-shield' ), 400 );
		}

		$settings = FS_Core::instance()->settings();
		$exclude  = isset( $body['exclude_order_id'] ) ? (int) $body['exclude_order_id'] : 0;
		$profile  = FS_Data::build_profile( $signals, $exclude );
		$scored   = FS_Rules::score_profile( $profile, $settings );

		$matches   = FS_Blocklist::match( $signals );
		$verified  = FS_Live::is_signal_verified( $signals );
		$amount    = isset( $body['amount'] ) ? (float) $body['amount'] : null;
		$velocity  = ! $verified && ! empty( $settings['velocity_lock'] ) && FS_Live::is_velocity_locked( $signals['user_id'], $signals['email_norm'], $exclude, (string) $signals['phone_norm'], (string) $signals['ship_door_hash'] );
		$on_hold   = ! $verified && FS_Live::is_on_hold( $signals['user_id'], $signals['email_norm'] );
		$idv_due   = FS_IDV::requires_verification( $signals['user_id'], $signals['email_norm'] );
		$hv        = FS_Live::high_value_rule( $signals['user_id'], $signals['email_norm'], $amount );

		// Linked-identity rule (v1.26): the credentials this identity shares
		// with a blocked one, evaluated under the same conditions the
		// post-creation handler uses (rule on, no direct match, not verified).
		$links = array();
		if ( ! empty( $settings['linked_cancel_enabled'] ) && empty( $matches ) && ! $verified ) {
			$links = FS_Blocklist::linked( $signals, (array) ( $settings['linked_cancel_types'] ?? array() ) );
		}
		// An IP-only link is reported as evidence but is never enough to act on.
		$linked_sufficient = FS_Blocklist::linked_sufficient( $links );

		// What matched, in the detail the Checkout Locks screen shows (v1.30):
		// each blocked credential as submitted, since when and by whom, the
		// block card it belongs to, and the blocked identities behind it.
		$evidence = empty( $matches ) ? array() : self::shape_match_evidence( FS_Blocklist::match_evidence( $matches, $signals ) );

		// What checkout would actually do, in the same precedence order the
		// enforcement points use. Kept in sync with FS_Live::validate_checkout().
		$decision = 'allow';
		$reason   = '';
		if ( ! empty( $matches ) && ! FS_Live::is_verified_customer( $signals['user_id'] ) ) {
			$decision = 'block';
			$reason   = 'blocklist';
		} elseif ( $linked_sufficient ) {
			$decision = 'block';
			$reason   = 'linked_blocklist';
		} elseif ( $idv_due ) {
			$decision = 'verify';
			$reason   = 'id_verification_requested';
		} elseif ( $velocity ) {
			// 'hold': the order is accepted and placed On-Hold for review (v1.31).
			$decision = FS_Live::velocity_action( $settings );
			$reason   = 'velocity';
		} elseif ( $on_hold ) {
			$decision = 'block';
			$reason   = 'verification_hold';
		} elseif ( null !== $hv && 'hard' === $hv['mode'] ) {
			$decision = 'verify';
			$reason   = 'high_value';
		} elseif ( 'enforce' === $settings['mode'] && ! $verified
			&& ( 'block' === $scored['level'] || ( 'high' === $scored['level'] && 'block' === $settings['action_high'] ) ) ) {
			$decision = 'block';
			$reason   = 'risk_score';
		} elseif ( in_array( $scored['level'], array( 'medium', 'high' ), true ) ) {
			$decision = 'review';
			$reason   = 'risk_score';
		}

		$gateway = isset( $body['gateway'] ) ? sanitize_text_field( (string) $body['gateway'] ) : '';

		return $this->ok(
			array(
				'decision' => $decision,
				'reason'   => $reason,
				'risk'     => array(
					'score'   => (int) $scored['score'],
					'level'   => (string) $scored['level'],
					'reasons' => array_values( (array) $scored['reasons'] ),
				),
				'identity' => array(
					'email_norm' => $signals['email_norm'],
					'user_id'    => $signals['user_id'],
					'address_id' => $signals['ship_hash'],
				),
				'state'    => array(
					'verified'          => (bool) $verified,
					'verified_account'  => FS_Live::is_verified_customer( $signals['user_id'] ),
					'id_verified'       => FS_IDV::is_verified( $signals['user_id'], $signals['email_norm'] ),
					'id_check_required' => (bool) $idv_due,
					'velocity_locked'   => (bool) $velocity,
					'verification_hold' => (bool) $on_hold,
					'blocklist_matches' => array_values( array_unique( wp_list_pluck( $matches, 'type' ) ) ),
					'blocklist_evidence' => $evidence,
					'blocklist_links'   => array_values(
						array_map(
							function ( $l ) {
								return array(
									'via'           => (string) $l['via'],
									'value'         => (string) $l['value'],
									'on_order'      => (bool) $l['on_order'],
									'degree'        => (int) $l['degree'],
									'blocked_type'  => (string) $l['blocked_type'],
									'blocked_value' => (string) $l['blocked_value'],
									'block_card'    => (string) $l['action_title'],
									'identities'    => array_values(
										array_map(
											function ( $id ) {
												return array(
													'email'   => (string) $id['email'],
													'user_id' => (int) $id['user_id'],
													'name'    => (string) $id['name'],
													'blocked' => (bool) $id['blocked'],
												);
											},
											(array) ( $l['identities'] ?? array() )
										)
									),
								);
							},
							$links
						)
					),
					'linked_sufficient' => (bool) $linked_sufficient,
					'high_value_rule'   => $hv,
				),
				'gateway'  => '' === $gateway ? null : array(
					'id'      => $gateway,
					'allowed' => FS_Live::is_gateway_allowed_for( $signals['user_id'], $signals['email_norm'], $gateway, $amount ),
				),
				'profile'  => $profile,
			)
		);
	}

	/**
	 * Build a signals array from an API payload, normalized exactly the way an
	 * order would be, so an ad-hoc lookup and a real order resolve to the same
	 * keys and therefore the same history.
	 *
	 * @param array $p Decoded payload.
	 * @return array
	 */
	public static function signals_from_payload( $p ) {
		$address = isset( $p['address'] ) && is_array( $p['address'] ) ? $p['address'] : array();
		$parts   = array(
			'address_1' => isset( $address['address_1'] ) ? (string) $address['address_1'] : '',
			'address_2' => isset( $address['address_2'] ) ? (string) $address['address_2'] : '',
			'city'      => isset( $address['city'] ) ? (string) $address['city'] : '',
			'state'     => isset( $address['state'] ) ? (string) $address['state'] : '',
			'postcode'  => isset( $address['postcode'] ) ? (string) $address['postcode'] : '',
			'country'   => isset( $address['country'] ) ? (string) $address['country'] : '',
		);
		$has_address = '' !== trim( $parts['address_1'] . $parts['postcode'] . $parts['city'] );

		$email   = isset( $p['email'] ) ? (string) $p['email'] : '';
		$country = isset( $p['country'] ) ? (string) $p['country'] : $parts['country'];
		$ip      = isset( $p['ip'] ) ? trim( (string) $p['ip'] ) : '';
		if ( '' !== $ip && ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '';
		}

		return array(
			'order_id'         => 0,
			// Velocity windows are anchored on the order date; an ad-hoc lookup
			// is asking about right now.
			'order_date'       => current_time( 'mysql' ),
			'user_id'          => isset( $p['user_id'] ) ? max( 0, (int) $p['user_id'] ) : 0,
			'customer_name'    => isset( $p['name'] ) ? mb_substr( sanitize_text_field( (string) $p['name'] ), 0, 191 ) : '',
			'email_norm'       => FS_Normalizer::email( $email ),
			'email_domain'     => FS_Normalizer::email_domain( $email ),
			'phone_norm'       => FS_Normalizer::phone( isset( $p['phone'] ) ? (string) $p['phone'] : '' ),
			'ship_hash'        => $has_address ? FS_Normalizer::hash( FS_Normalizer::address( $parts ) ) : '',
			'ship_loose_hash'  => $has_address ? FS_Normalizer::hash( FS_Normalizer::address_loose( $parts ) ) : '',
			'ship_city'        => FS_Normalizer::city( $parts['city'] ),
			'ship_state'       => FS_Normalizer::region( $parts['state'] ),
			'ship_postcode'    => FS_Normalizer::postcode( $parts['postcode'] ),
			'ship_street'      => FS_Normalizer::street( $parts ),
			'ship_door_hash'   => $has_address ? FS_Normalizer::door( $parts ) : '',
			'ship_door_alts'   => $has_address ? FS_Normalizer::door_alternates( $parts ) : array(),
			'ship_number'      => $has_address ? FS_Normalizer::house( $parts ) : '',
			'ship_parts'       => $has_address ? $parts : array(),
			'billing_country'  => strtoupper( substr( (string) $country, 0, 8 ) ),
			'shipping_country' => strtoupper( substr( (string) ( '' !== $parts['country'] ? $parts['country'] : $country ), 0, 8 ) ),
			'ip'               => $ip,
			'order_total'      => isset( $p['amount'] ) ? (float) $p['amount'] : 0.0,
			'status'           => '',
			'payment_method'   => isset( $p['gateway'] ) ? sanitize_text_field( (string) $p['gateway'] ) : '',
			'ship_method'      => isset( $p['shipping_method'] ) ? mb_substr( sanitize_text_field( (string) $p['shipping_method'] ), 0, 191 ) : '',
			'coupons'          => '',
			'user_agent'       => '',
			'bill_hash'        => '',
		);
	}

	/* ===================== Routes: customers ===================== */

	/**
	 * Everything this store knows about one identity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_customer( $request ) {
		$who = $this->resolve_ident( (string) $request['ident'] );
		if ( is_wp_error( $who ) ) {
			return $who;
		}
		$user_id = (int) $who['user_id'];
		$email   = (string) $who['email'];
		$user    = $who['user'];
		$summary = $who['summary'];

		$restriction = FS_Live::per_user_gateway_restriction( $user_id );
		$rec         = FS_Live::gw_restrict_record( $user_id );
		$hold        = $user_id > 0 ? get_user_meta( $user_id, '_fs_hold', true ) : null;
		$idv_rec     = FS_IDV::record_for( $user_id, $email );
		$idv_req     = FS_IDV::requirement_record( $user_id );

		// Every credential the identity has used, each with how often (v1.30).
		$distinct = function ( $what ) use ( $email ) {
			$out = array();
			foreach ( (array) FS_Data::profile_distinct( $email, $what ) as $r ) {
				$out[] = array(
					'value'  => (string) $r['v'],
					'orders' => (int) $r['c'],
				);
			}
			return $out;
		};
		$devices = array();
		foreach ( (array) FS_Data::profile_distinct( $email, 'user_agent' ) as $r ) {
			$devices[] = array(
				'device_id' => hash( 'sha256', (string) $r['v'] ),
				'orders'    => (int) $r['c'],
			);
		}
		$addresses = array();
		foreach ( (array) FS_Data::profile_addresses( $email ) as $r ) {
			$addresses[] = array(
				'address_id' => (string) $r['ship_hash'],
				'orders'     => (int) $r['c'],
			);
		}
		$coupons = array();
		foreach ( FS_Data::profile_coupons( $email ) as $code => $n ) {
			$coupons[] = array(
				'code'   => (string) $code,
				'orders' => (int) $n,
			);
		}
		$phones = $distinct( 'phone_norm' );
		$ips    = $distinct( 'ip' );

		// Which of those credentials sit on the blocklist, with provenance.
		$blocked_rows = array_merge(
			FS_Blocklist::rows_for_values( 'email', array( $email ) ),
			$user_id > 0 ? FS_Blocklist::rows_for_values( 'user', array( (string) $user_id ) ) : array(),
			FS_Blocklist::rows_for_values( 'phone', wp_list_pluck( $phones, 'value' ) ),
			FS_Blocklist::rows_for_values( 'ip', wp_list_pluck( $ips, 'value' ) ),
			FS_Blocklist::rows_for_values( 'address', wp_list_pluck( $addresses, 'address_id' ) )
		);

		// Other identities sharing an IP or address, as the Profiles page lists them.
		$links = array();
		foreach ( (array) FS_Data::profile_linked( $email ) as $l ) {
			$links[] = array(
				'email_norm'       => (string) $l['email_norm'],
				'name'             => (string) ( $l['customer_name'] ?? '' ),
				'user_id'          => (int) ( $l['user_id'] ?? 0 ),
				'orders'           => (int) ( $l['orders'] ?? 0 ),
				'shared_ips'       => (int) ( $l['shared_ips'] ?? 0 ),
				'shared_addresses' => (int) ( $l['shared_addresses'] ?? 0 ),
				'chargebacks'      => (int) ( $l['chargebacks'] ?? 0 ),
			);
		}

		$refusals = array();
		foreach ( FS_Data::checkout_blocks_for_identity( $user_id, $email, 20 ) as $row ) {
			$refusals[] = self::shape_lock_row( $row );
		}
		$cancel = FS_Data::linked_cancel_for_identity( $user_id, $email );
		if ( is_array( $cancel ) ) {
			$decoded         = json_decode( (string) ( $cancel['links'] ?? '' ), true );
			$cancel['links'] = is_array( $decoded ) ? $decoded : array();
		}
		$card = class_exists( 'FS_Cardholder' ) ? FS_Cardholder::latest_for_email( $email ) : null;

		return $this->ok(
			array(
				'identity' => array(
					'email_norm' => $email,
					'user_id'    => $user_id,
					'name'       => is_array( $summary ) ? (string) ( $summary['customer_name'] ?? '' ) : '',
					'registered' => $user_id > 0 && $user ? mysql2date( 'c', $user->user_registered ) : null,
				),
				'history'  => self::shape_history( $summary ),
				'state'    => array(
					'verified_account'  => FS_Live::is_verified_customer( $user_id ),
					'auto_verified'     => FS_Live::is_auto_verified( $user_id, $email ),
					'email_verified'    => FS_Live::is_email_verified( $email ),
					'email_allowlisted' => FS_Live::is_email_allowlisted( $email ),
					'id_verified'       => FS_IDV::is_verified( $user_id, $email ),
					'id_check_required' => FS_IDV::requires_verification( $user_id, $email ),
					'velocity_locked'   => FS_Live::is_velocity_locked( $user_id, $email ),
					'verification_hold' => FS_Live::is_on_hold( $user_id, $email ),
					'gateway_restriction' => empty( $restriction ) ? null : self::shape_restriction( $user_id, $restriction, $email ),
					'trust_tier'        => FS_Live::verification_tier( $user_id, $email ),
					'blocked'           => ! empty( $blocked_rows ),
				),
				// v1.30 — the rest of the dossier.
				'trust'    => array(
					'auto_verify'   => self::shape_auto_verify( FS_Live::auto_verify_status( $user_id, $email ) ),
					'id_verification' => self::shape_idv_record( $idv_rec, $idv_req ),
					'restriction'   => empty( $rec ) ? null : self::shape_restriction( $user_id, $rec, $email ),
					'hold'          => empty( $hold ) ? null : self::shape_hold( $user_id, $hold, $email ),
				),
				'credentials' => array(
					'phones'    => $phones,
					'ips'       => $ips,
					'addresses' => $addresses,
					'names'     => $distinct( 'customer_name' ),
					'gateways'  => $distinct( 'payment_method' ),
					'devices'   => $devices,
					'coupons'   => $coupons,
				),
				'blocklist'   => self::shape_blocked_rows( $blocked_rows ),
				'links'       => $links,
				'refusals'    => $refusals,
				'linked_cancel' => is_array( $cancel ) ? self::shape_linked_cancel( $cancel ) : null,
				'card_check'  => is_array( $card ) ? self::shape_card_log_row( $card ) : null,
				'analysis'    => self::shape_profile_analysis( $email, $user_id, $summary, $user ),
			)
		);
	}

	/**
	 * The stored IDV warnings (a JSON array of short strings) as a list.
	 *
	 * @param string $raw Stored column.
	 * @return array
	 */
	private static function shape_idv_warnings( $raw ) {
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? array_values( array_map( 'strval', $decoded ) ) : array();
	}

	/**
	 * The profile page's analysis blocks (v1.30): ordering cadence and
	 * time-of-day pattern, IP-geolocation versus the countries entered,
	 * billing/shipping consistency, and — for an account — whether the order
	 * details match the account.
	 *
	 * Geolocation uses WooCommerce's local MaxMind database only (no network
	 * call), and is skipped entirely when the integration is not available.
	 *
	 * @param string       $email   Normalized email.
	 * @param int          $user_id Account id (0 = guest).
	 * @param array|null   $summary FS_Data::profile_summary() output.
	 * @param WP_User|null $user    The account, when there is one.
	 * @return array
	 */
	private static function shape_profile_analysis( $email, $user_id, $summary, $user ) {
		// Cadence: gaps between orders, and the hour-of-day histogram (store time).
		$stamps = array();
		$hours  = array_fill( 0, 24, 0 );
		foreach ( FS_Data::profile_dates( $email ) as $d ) {
			$ts = strtotime( (string) $d );
			if ( $ts ) {
				$stamps[] = $ts;
			}
			$h = (int) substr( (string) $d, 11, 2 );
			if ( $h >= 0 && $h <= 23 ) {
				$hours[ $h ]++;
			}
		}
		sort( $stamps );
		$gaps = array();
		for ( $i = 1, $n = count( $stamps ); $i < $n; $i++ ) {
			$gaps[] = $stamps[ $i ] - $stamps[ $i - 1 ];
		}
		$best_start = 0;
		$best_count = 0;
		for ( $h = 0; $h < 24; $h++ ) {
			$c = $hours[ $h ] + $hours[ ( $h + 1 ) % 24 ] + $hours[ ( $h + 2 ) % 24 ];
			if ( $c > $best_count ) {
				$best_count = $c;
				$best_start = $h;
			}
		}
		$patterns = array(
			'orders_dated'      => count( $stamps ),
			'average_gap_days'  => $gaps ? round( array_sum( $gaps ) / count( $gaps ) / DAY_IN_SECONDS, 1 ) : null,
			'shortest_gap_days' => $gaps ? round( min( $gaps ) / DAY_IN_SECONDS, 1 ) : null,
			'busiest_window'    => array(
				'start_hour' => $best_start,
				'end_hour'   => ( $best_start + 3 ) % 24,
				'orders'     => $best_count,
			),
			'hour_histogram'    => array_values( $hours ),
		);

		// Geo consistency: where each IP geolocates against the countries entered.
		$geo_rows = FS_Data::profile_geo_rows( $email );
		$checks   = array();
		$known    = 0;
		$matched  = 0;
		$can_geo  = class_exists( 'WC_Geolocation' );
		foreach ( (array) $geo_rows as $g ) {
			$code  = '';
			$state = '';
			if ( $can_geo ) {
				$geo   = WC_Geolocation::geolocate_ip( (string) $g['ip'], false, false );
				$code  = is_array( $geo ) ? strtoupper( (string) ( $geo['country'] ?? '' ) ) : '';
				$state = is_array( $geo ) ? (string) ( $geo['state'] ?? '' ) : '';
			}
			$bc  = strtoupper( (string) ( $g['bc'] ?? '' ) );
			$sc  = strtoupper( (string) ( $g['sc'] ?? '' ) );
			$hit = null;
			if ( '' !== $code ) {
				$known++;
				$hit = ( $code === $bc || $code === $sc );
				if ( $hit ) {
					$matched++;
				}
			}
			$checks[] = array(
				'ip'               => (string) $g['ip'],
				'orders'           => (int) ( $g['c'] ?? 0 ),
				'ip_country'       => $code,
				'ip_state'         => $state,
				'billing_country'  => $bc,
				'shipping_country' => $sc,
				'match'            => $hit,
			);
		}

		$out = array(
			'patterns'   => $patterns,
			'geo_checks' => array(
				'available' => $can_geo,
				'known'     => $known,
				'matched'   => $matched,
				'ips'       => $checks,
			),
			'consistency' => array(
				'billing_shipping' => array(
					'known'   => (int) ( $summary['bill_known'] ?? 0 ),
					'matched' => (int) ( $summary['bill_match'] ?? 0 ),
				),
			),
		);

		// Account-vs-order identity: the emails, names and phones this account
		// has ordered under, against the account's own details.
		if ( $user_id > 0 && $user ) {
			$ident      = FS_Data::customer_identity( $user_id );
			$acct_email = FS_Normalizer::email( (string) $user->user_email );
			$acct_name  = trim( (string) get_user_meta( $user_id, 'first_name', true ) . ' ' . (string) get_user_meta( $user_id, 'last_name', true ) );
			if ( '' === $acct_name ) {
				$acct_name = (string) $user->display_name;
			}
			$emails = array_values( array_map( 'strval', (array) ( $ident['emails'] ?? array() ) ) );
			$names  = array_values( array_map( 'strval', (array) ( $ident['names'] ?? array() ) ) );
			$out['consistency']['account'] = array(
				'account_email'       => $acct_email,
				'account_name'        => $acct_name,
				'emails_used'         => $emails,
				'names_used'          => $names,
				'phones_used'         => array_values( array_map( 'strval', (array) ( $ident['phones'] ?? array() ) ) ),
				'other_emails'        => count( array_diff( $emails, array( $acct_email ) ) ),
				'name_matches_account' => '' === $acct_name ? null : in_array( mb_strtolower( $acct_name ), array_map( 'mb_strtolower', $names ), true ),
			);
		}
		return $out;
	}

	/**
	 * Resolve `{ident}` — a URL-encoded email, or `id:<number>` — to an
	 * identity this store knows (v1.30: shared by the customer, restriction,
	 * hold and network routes).
	 *
	 * @param string $raw        Raw path segment.
	 * @param bool   $need_order Require at least one indexed order for a guest email.
	 * @return array|WP_Error { user_id:int, email:string, user:WP_User|null, summary:array|null }
	 */
	private function resolve_ident( $raw, $need_order = true ) {
		$ident   = rawurldecode( (string) $raw );
		$user_id = 0;
		$email   = '';
		$user    = null;

		if ( 0 === stripos( $ident, 'id:' ) ) {
			$user_id = (int) substr( $ident, 3 );
			$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
			if ( ! $user ) {
				return self::error( 'fs_api_not_found', __( 'No such customer.', 'fraud-shield' ), 404 );
			}
			$email = FS_Normalizer::email( $user->user_email );
		} else {
			$email = FS_Normalizer::email( $ident );
			if ( '' === $email || false === strpos( $email, '@' ) ) {
				return self::error( 'fs_api_bad_identifier', __( 'Provide an email address or id:<number>.', 'fraud-shield' ), 400 );
			}
			$user    = get_user_by( 'email', $ident );
			$user_id = $user ? (int) $user->ID : 0;
		}

		$summary = FS_Data::profile_summary( $email );
		if ( 0 === $user_id && $need_order && ( ! is_array( $summary ) || empty( $summary['orders'] ) ) ) {
			return self::error( 'fs_api_not_found', __( 'No such customer.', 'fraud-shield' ), 404 );
		}
		if ( is_array( $summary ) && $user_id <= 0 && ! empty( $summary['user_id'] ) ) {
			$user_id = (int) $summary['user_id'];
			$user    = get_userdata( $user_id ) ?: null;
		}

		return array(
			'user_id' => $user_id,
			'email'   => $email,
			'user'    => $user ? $user : null,
			'summary' => is_array( $summary ) ? $summary : null,
		);
	}

	/**
	 * Every customer identity the index knows, one row per normalized email —
	 * the Profiles page as an endpoint (v1.30).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_customers( $request ) {
		$args = array(
			'search'  => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'orderby' => sanitize_key( (string) $request->get_param( 'orderby' ) ),
			'dir'     => 'asc' === strtolower( (string) $request->get_param( 'dir' ) ) ? 'asc' : 'desc',
			'from'    => FS_Data::api_date_bound( sanitize_text_field( (string) $request->get_param( 'from' ) ) ),
			'to'      => FS_Data::api_date_bound( sanitize_text_field( (string) $request->get_param( 'to' ) ) ),
			'limit'   => self::limit_param( $request, 50 ),
			'offset'  => max( 0, (int) $request->get_param( 'offset' ) ),
		);
		if ( '' === $args['orderby'] ) {
			$args['orderby'] = 'last';
		}

		$rows = array();
		foreach ( (array) FS_Data::profiles_list( $args ) as $r ) {
			$rows[] = self::shape_customer_row( $r );
		}

		return $this->ok(
			array(
				'total'     => FS_Data::profiles_count( $args['search'], $args['from'], $args['to'] ),
				'limit'     => $args['limit'],
				'offset'    => $args['offset'],
				'customers' => $rows,
			)
		);
	}

	/**
	 * Put an account on restricted checkout (v1.30).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_customer_restrict( $request ) {
		$who = $this->resolve_ident( (string) $request['ident'], false );
		if ( is_wp_error( $who ) ) {
			return $who;
		}
		if ( (int) $who['user_id'] <= 0 ) {
			return self::error( 'fs_api_bad_value', __( 'A gateway restriction applies to a registered account. Provide id:<number> or the email of an account.', 'fraud-shield' ), 400 );
		}
		$body     = $this->json_body( $request );
		$gateways = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $body['gateways'] ?? array() ) ) ) ) ) );
		if ( empty( $gateways ) ) {
			return self::error( 'fs_api_bad_value', __( 'gateways must list at least one payment gateway id; a restriction with none would fail open.', 'fraud-shield' ), 400 );
		}
		$rec = FS_Live::apply_gw_restrict(
			(int) $who['user_id'],
			$gateways,
			isset( $body['low_value_amount'] ) ? (float) $body['low_value_amount'] : 0,
			isset( $body['low_value_gateways'] ) ? (array) $body['low_value_gateways'] : array()
		);
		if ( empty( $rec ) ) {
			return self::error( 'fs_api_not_found', __( 'No such account.', 'fraud-shield' ), 404 );
		}

		return $this->ok(
			array(
				'ok'          => true,
				'user_id'     => (int) $who['user_id'],
				'restriction' => self::shape_restriction( (int) $who['user_id'], $rec, (string) $who['email'] ),
			),
			201
		);
	}

	/**
	 * Take an account off restricted checkout (v1.30).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_customer_unrestrict( $request ) {
		$who = $this->resolve_ident( (string) $request['ident'], false );
		if ( is_wp_error( $who ) ) {
			return $who;
		}
		$released = (int) $who['user_id'] > 0 && FS_Live::release_gw_restrict( (int) $who['user_id'] );
		return $this->ok(
			array(
				'ok'       => true,
				'user_id'  => (int) $who['user_id'],
				'released' => (bool) $released,
			)
		);
	}

	/**
	 * Lift a new-account verification hold without verifying the account (v1.30).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_customer_release_hold( $request ) {
		$who = $this->resolve_ident( (string) $request['ident'], false );
		if ( is_wp_error( $who ) ) {
			return $who;
		}
		$released = (int) $who['user_id'] > 0 && FS_Live::release_hold( (int) $who['user_id'] );
		return $this->ok(
			array(
				'ok'       => true,
				'user_id'  => (int) $who['user_id'],
				'released' => (bool) $released,
			)
		);
	}

	/**
	 * The Addresses registry (v1.30): every shipping address in the index with
	 * its headline aggregates — browse, search, sort. `orderby=risk` is the
	 * Dashboard's "top risky addresses" table.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_addresses( $request ) {
		return $this->clusters_response( 'address', $request );
	}

	/**
	 * The IPs registry (v1.30): every IP in the index with its aggregates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_ips( $request ) {
		return $this->clusters_response( 'ip', $request );
	}

	/**
	 * Shared body of the two registry lists.
	 *
	 * @param string          $type    'address' | 'ip'.
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	private function clusters_response( $type, $request ) {
		$orderby = sanitize_key( (string) $request->get_param( 'orderby' ) );
		$args    = array(
			'search'  => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'orderby' => '' !== $orderby ? $orderby : 'emails',
			'dir'     => 'asc' === strtolower( (string) $request->get_param( 'dir' ) ) ? 'asc' : 'desc',
			'limit'   => self::limit_param( $request, 50 ),
			'offset'  => max( 0, (int) $request->get_param( 'offset' ) ),
		);
		$rows    = (array) FS_Data::clusters_list( $type, $args );

		// Lock and trust state for the whole page in two queries.
		$keys     = array_map( 'strval', wp_list_pluck( $rows, 'cluster_key' ) );
		$blocked  = array_fill_keys( FS_Blocklist::blocked_values( $type, $keys ), true );
		$verified = FS_Live::verified_creds();

		$out = array();
		foreach ( $rows as $r ) {
			$key = (string) $r['cluster_key'];
			$row = array(
				'orders'         => (int) ( $r['orders'] ?? 0 ),
				'paid_orders'    => (int) ( $r['paid_orders'] ?? 0 ),
				'spend'          => round( (float) ( $r['spend'] ?? 0 ), 2 ),
				'distinct_emails' => (int) ( $r['emails'] ?? 0 ),
				'distinct_names' => (int) ( $r['names'] ?? 0 ),
				'chargebacks'    => (int) ( $r['chargebacks'] ?? 0 ),
				'max_risk_score' => (int) ( $r['max_risk'] ?? 0 ),
				'first_order'    => empty( $r['first_order'] ) ? null : mysql2date( 'c', $r['first_order'] ),
				'last_order'     => empty( $r['last_order'] ) ? null : mysql2date( 'c', $r['last_order'] ),
				'blocked'        => isset( $blocked[ $key ] ),
				'verified'       => isset( $verified[ $type . ':' . $key ] ),
			);
			if ( 'ip' === $type ) {
				$out[] = array_merge( array( 'ip' => $key ), $row, array( 'distinct_addresses' => (int) ( $r['other_dim'] ?? 0 ) ) );
			} else {
				$out[] = array_merge(
					array(
						'address_id' => $key,
						'address'    => (string) ( $r['ship_text'] ?? '' ),
					),
					$row,
					array( 'distinct_ips' => (int) ( $r['other_dim'] ?? 0 ) )
				);
			}
		}

		return $this->ok(
			array(
				'total'  => (int) FS_Data::clusters_count( $type, $args['search'] ),
				'limit'  => $args['limit'],
				'offset' => $args['offset'],
				( 'ip' === $type ? 'ips' : 'addresses' ) => $out,
			)
		);
	}

	/**
	 * The Repeat Customers report (v1.30): registered accounts with at least
	 * N counted orders (paid or charged back) in a window.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_report_repeat( $request ) {
		$from    = FS_Data::api_date_bound( sanitize_text_field( (string) $request->get_param( 'from' ) ) );
		$to      = FS_Data::api_date_bound( sanitize_text_field( (string) $request->get_param( 'to' ) ) );
		$orderby = sanitize_key( (string) $request->get_param( 'orderby' ) );
		$args    = array(
			'from'       => '' !== $from ? $from : '1970-01-01 00:00:00',
			'to'         => '' !== $to ? $to : current_time( 'mysql' ),
			'min_orders' => max( 1, (int) ( $request->get_param( 'min_orders' ) ?: 2 ) ),
			'orderby'    => '' !== $orderby ? $orderby : 'last_order',
			'dir'        => 'asc' === strtolower( (string) $request->get_param( 'dir' ) ) ? 'asc' : 'desc',
			'search'     => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'limit'      => self::limit_param( $request, 50 ),
			'offset'     => max( 0, (int) $request->get_param( 'offset' ) ),
		);
		$rows    = array();
		foreach ( (array) FS_Data::repeat_customers( $args ) as $r ) {
			$rows[] = array(
				'user_id'            => (int) ( $r['user_id'] ?? 0 ),
				'email'              => FS_Normalizer::email( (string) ( $r['user_email'] ?? '' ) ),
				'name'               => (string) ( '' !== (string) ( $r['customer_name'] ?? '' ) ? $r['customer_name'] : ( $r['display_name'] ?? '' ) ),
				'registered'         => empty( $r['user_registered'] ) ? null : mysql2date( 'c', $r['user_registered'] ),
				'orders'             => (int) ( $r['orders'] ?? 0 ),
				'spend'              => round( (float) ( $r['spend'] ?? 0 ), 2 ),
				'chargebacks'        => (int) ( $r['chargebacks'] ?? 0 ),
				'distinct_ips'       => (int) ( $r['ips'] ?? 0 ),
				'distinct_addresses' => (int) ( $r['addresses'] ?? 0 ),
				'first_order'        => empty( $r['first_order'] ) ? null : mysql2date( 'c', $r['first_order'] ),
				'last_order'         => empty( $r['last_order'] ) ? null : mysql2date( 'c', $r['last_order'] ),
				'latest_ip'          => (string) ( $r['latest_ip'] ?? '' ),
				'latest_gateway'     => (string) ( $r['latest_payment'] ?? '' ),
				'latest_country'     => (string) ( $r['latest_country'] ?? '' ),
			);
		}
		return $this->ok(
			array(
				'total'      => FS_Data::repeat_customers_count( $args['from'], $args['to'], $args['min_orders'], $args['search'] ),
				'limit'      => $args['limit'],
				'offset'     => $args['offset'],
				'from'       => mysql2date( 'c', $args['from'] ),
				'to'         => mysql2date( 'c', $args['to'] ),
				'min_orders' => $args['min_orders'],
				'customers'  => $rows,
			)
		);
	}

	/**
	 * The New Customers report (v1.30): accounts registered in the last N
	 * months, ranked by order velocity — the burst-then-chargeback pattern.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_report_new( $request ) {
		$orderby = sanitize_key( (string) $request->get_param( 'orderby' ) );
		$args    = array(
			'months'     => max( 1, min( 120, (int) ( $request->get_param( 'months' ) ?: 3 ) ) ),
			'min_orders' => max( 1, (int) ( $request->get_param( 'min_orders' ) ?: 2 ) ),
			'orderby'    => '' !== $orderby ? $orderby : 'frequency',
			'dir'        => 'asc' === strtolower( (string) $request->get_param( 'dir' ) ) ? 'asc' : 'desc',
			'search'     => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'limit'      => self::limit_param( $request, 50 ),
			'offset'     => max( 0, (int) $request->get_param( 'offset' ) ),
		);
		$rows    = array();
		foreach ( (array) FS_Data::new_customers( $args ) as $r ) {
			$rows[] = array(
				'user_id'            => (int) ( $r['user_id'] ?? 0 ),
				'email'              => FS_Normalizer::email( (string) ( $r['user_email'] ?? '' ) ),
				'name'               => (string) ( '' !== (string) ( $r['customer_name'] ?? '' ) ? $r['customer_name'] : ( $r['display_name'] ?? '' ) ),
				'registered'         => empty( $r['user_registered'] ) ? null : mysql2date( 'c', $r['user_registered'] ),
				'age_days'           => (int) ( $r['age_days'] ?? 0 ),
				'orders'             => (int) ( $r['orders'] ?? 0 ),
				'orders_per_month'   => round( (float) ( $r['orders_per_month'] ?? 0 ), 2 ),
				'spend'              => round( (float) ( $r['total_spend'] ?? 0 ), 2 ),
				'chargebacks'        => (int) ( $r['chargebacks'] ?? 0 ),
				'distinct_ips'       => (int) ( $r['ips'] ?? 0 ),
				'distinct_addresses' => (int) ( $r['addresses'] ?? 0 ),
				'distinct_emails'    => (int) ( $r['emails'] ?? 0 ),
				'max_risk_score'     => (int) ( $r['max_risk'] ?? 0 ),
				'first_order'        => empty( $r['first_order'] ) ? null : mysql2date( 'c', $r['first_order'] ),
				'last_order'         => empty( $r['last_order'] ) ? null : mysql2date( 'c', $r['last_order'] ),
			);
		}
		return $this->ok(
			array(
				'total'      => FS_Data::new_customers_count( $args['months'], $args['min_orders'], $args['search'] ),
				'limit'      => $args['limit'],
				'offset'     => $args['offset'],
				'months'     => $args['months'],
				'min_orders' => $args['min_orders'],
				'customers'  => $rows,
			)
		);
	}

	/**
	 * Everything behind one shipping address (v1.30): the cluster summary and
	 * every identity that shipped there — the Addresses registry's detail page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_address( $request ) {
		return $this->cluster_response( 'address', strtolower( (string) $request['hash'] ) );
	}

	/**
	 * Everything behind one IP address (v1.30) — the IPs registry's detail page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_ip( $request ) {
		$ip = rawurldecode( (string) $request['ip'] );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return self::error( 'fs_api_bad_identifier', __( 'Provide a valid IP address.', 'fraud-shield' ), 400 );
		}
		return $this->cluster_response( 'ip', $ip );
	}

	/**
	 * Shared body of the address and IP detail routes.
	 *
	 * @param string $type 'address' | 'ip'.
	 * @param string $key  Hash or IP.
	 * @return WP_REST_Response|WP_Error
	 */
	private function cluster_response( $type, $key ) {
		$summary = FS_Data::cluster_summary( $type, $key );
		if ( empty( $summary ) || (int) ( $summary['orders'] ?? 0 ) <= 0 ) {
			return self::error( 'fs_api_not_found', 'address' === $type ? __( 'No orders ship to that address.', 'fraud-shield' ) : __( 'No orders were placed from that IP.', 'fraud-shield' ), 404 );
		}

		$identities = array();
		foreach ( (array) FS_Data::cluster_emails( $type, $key ) as $r ) {
			$identities[] = array(
				'email_norm'     => (string) $r['email_norm'],
				'name'           => (string) ( $r['customer_name'] ?? '' ),
				'user_id'        => (int) ( $r['user_id'] ?? 0 ),
				'phone'          => (string) ( $r['phone_norm'] ?? '' ),
				'orders'         => (int) ( $r['orders'] ?? 0 ),
				'paid_orders'    => (int) ( $r['paid_orders'] ?? 0 ),
				'spend'          => round( (float) ( $r['spend'] ?? 0 ), 2 ),
				'chargebacks'    => (int) ( $r['chargebacks'] ?? 0 ),
				'first_order'    => empty( $r['first_order'] ) ? null : mysql2date( 'c', $r['first_order'] ),
				'last_order'     => empty( $r['last_order'] ) ? null : mysql2date( 'c', $r['last_order'] ),
				'latest_gateway' => (string) ( $r['latest_payment'] ?? '' ),
			);
		}

		$action = FS_Blocklist::action_for_target( $type, $key );
		$entry  = FS_Blocklist::rows_for_values( $type, array( $key ) );

		// The rest of the detail page: payment methods, device signatures and
		// coupon codes seen at the location, and its most recent orders.
		$gateways = array();
		foreach ( (array) FS_Data::cluster_distinct( $type, $key, 'payment_method' ) as $r ) {
			$gateways[] = array(
				'value'  => (string) $r['v'],
				'orders' => (int) $r['c'],
			);
		}
		$devices = array();
		foreach ( (array) FS_Data::cluster_distinct( $type, $key, 'user_agent' ) as $r ) {
			$devices[] = array(
				'device_id' => hash( 'sha256', (string) $r['v'] ),
				'orders'    => (int) $r['c'],
			);
		}
		$coupons = array();
		foreach ( FS_Data::cluster_coupons( $type, $key ) as $code => $n ) {
			$coupons[] = array(
				'code'   => (string) $code,
				'orders' => (int) $n,
			);
		}
		$orders = array_map( array( __CLASS__, 'shape_order' ), (array) FS_Data::cluster_orders( $type, $key, 25, 0 ) );

		$out = array(
			'type'      => $type,
			'id'        => $key,
			'summary'   => array(
				'orders'          => (int) ( $summary['orders'] ?? 0 ),
				'paid_orders'     => (int) ( $summary['paid_orders'] ?? 0 ),
				'distinct_emails' => (int) ( $summary['emails'] ?? 0 ),
				'distinct_names'  => (int) ( $summary['names'] ?? 0 ),
				'distinct_countries' => (int) ( $summary['countries'] ?? 0 ),
				'chargebacks'     => (int) ( $summary['chargebacks'] ?? 0 ),
				'paid_value'      => round( (float) ( $summary['total_value'] ?? 0 ), 2 ),
				'first_order'     => empty( $summary['first_order'] ) ? null : mysql2date( 'c', $summary['first_order'] ),
				'last_order'      => empty( $summary['last_order'] ) ? null : mysql2date( 'c', $summary['last_order'] ),
			),
			'blocked'   => ! empty( $entry ),
			'block_action' => $action ? self::shape_action( $action ) : null,
			'verified'  => FS_Live::is_cred_verified( $type, $key ),
			'identities' => $identities,
			'gateways'  => $gateways,
			'devices'   => $devices,
			'coupons'   => $coupons,
			'orders'    => $orders,
			'orders_capped' => count( $orders ) >= 25,
		);
		if ( 'ip' === $type ) {
			$out['summary']['distinct_addresses'] = (int) ( $summary['addresses'] ?? 0 );
		} else {
			$out['address']                 = (string) ( $summary['ship_text'] ?? '' );
			$out['summary']['distinct_ips'] = (int) ( $summary['ips'] ?? 0 );
			$out['geo']                     = array(
				'country'  => (string) ( $summary['shipping_country'] ?? '' ),
				'state'    => (string) ( $summary['ship_state'] ?? '' ),
				'city'     => (string) ( $summary['ship_city'] ?? '' ),
				'street'   => (string) ( $summary['ship_street'] ?? '' ),
			);
		}
		return $this->ok( $out );
	}

	/**
	 * Accounts on staff-applied restricted checkout (v1.30) — the Restricted
	 * checkout list, stored records only (a velocity-derived restriction is a
	 * live computation, visible per customer as `state.velocity_locked`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_restrictions( $request ) {
		$limit  = self::limit_param( $request, 50 );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$users  = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_fs_gw_restrict',
						'compare' => 'EXISTS',
					),
				),
				'number'     => $limit,
				'offset'     => $offset,
				'orderby'    => 'ID',
				'order'      => 'DESC',
				'count_total' => true,
			)
		);
		$rows = array();
		foreach ( $users as $u ) {
			$rec = FS_Live::gw_restrict_record( (int) $u->ID );
			if ( empty( $rec['gateways'] ) ) {
				continue;
			}
			$rows[] = self::shape_restriction( (int) $u->ID, $rec, FS_Normalizer::email( $u->user_email ) );
		}
		return $this->ok(
			array(
				'total'        => $this->meta_user_count( '_fs_gw_restrict' ),
				'limit'        => $limit,
				'offset'       => $offset,
				'restrictions' => $rows,
			)
		);
	}

	/**
	 * Accounts under a new-account verification hold (v1.30) — the
	 * Verification queue on Checkout Locks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_holds( $request ) {
		$limit  = self::limit_param( $request, 50 );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$users  = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_fs_hold',
						'compare' => 'EXISTS',
					),
				),
				'number'     => $limit,
				'offset'     => $offset,
				'orderby'    => 'ID',
				'order'      => 'DESC',
			)
		);
		$rows = array();
		foreach ( $users as $u ) {
			$hold = get_user_meta( (int) $u->ID, '_fs_hold', true );
			if ( empty( $hold ) ) {
				continue;
			}
			$rows[] = self::shape_hold( (int) $u->ID, $hold, FS_Normalizer::email( $u->user_email ) );
		}
		return $this->ok(
			array(
				'total'  => $this->meta_user_count( '_fs_hold' ),
				'limit'  => $limit,
				'offset' => $offset,
				'holds'  => $rows,
			)
		);
	}

	/**
	 * How many accounts carry a user-meta flag.
	 *
	 * @param string $key Meta key.
	 * @return int
	 */
	private function meta_user_count( $key ) {
		$q = new WP_User_Query(
			array(
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => $key,
						'compare' => 'EXISTS',
					),
				),
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => true,
			)
		);
		return (int) $q->get_total();
	}

	/**
	 * The Checkout Locks ledger (v1.30): every identity a control refused,
	 * with the evidence of the latest refusal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_locks( $request ) {
		$resolved = sanitize_key( (string) $request->get_param( 'resolved' ) );
		$args     = array(
			'kind'     => sanitize_key( (string) $request->get_param( 'kind' ) ),
			'email'    => FS_Normalizer::email( (string) $request->get_param( 'email' ) ),
			'user_id'  => max( 0, (int) $request->get_param( 'user_id' ) ),
			'resolved' => in_array( $resolved, array( 'open', 'resolved', 'all' ), true ) ? $resolved : 'open',
			'limit'    => self::limit_param( $request, 50 ),
			'offset'   => max( 0, (int) $request->get_param( 'offset' ) ),
		);
		$rows     = array();
		foreach ( FS_Data::checkout_blocks_query( $args ) as $row ) {
			$rows[] = self::shape_lock_row( $row );
		}
		return $this->ok(
			array(
				'total'  => FS_Data::checkout_blocks_query_count( $args ),
				'limit'  => $args['limit'],
				'offset' => $args['offset'],
				'locks'  => $rows,
			)
		);
	}

	/**
	 * One Checkout Locks row (v1.30).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_lock( $request ) {
		$row = FS_Data::checkout_block_row( (int) $request['id'] );
		if ( ! $row ) {
			return self::error( 'fs_api_not_found', __( 'No such checkout lock.', 'fraud-shield' ), 404 );
		}
		return $this->ok( self::shape_lock_row( $row ) );
	}

	/**
	 * Dismiss a Checkout Locks row (v1.30). Clears the queue entry only; the
	 * underlying control still applies and the evidence stays on the profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_lock_dismiss( $request ) {
		$row = FS_Data::checkout_block_row( (int) $request['id'] );
		if ( ! $row ) {
			return self::error( 'fs_api_not_found', __( 'No such checkout lock.', 'fraud-shield' ), 404 );
		}
		FS_Data::resolve_checkout_block( (int) $row['id'] );
		return $this->ok( array( 'ok' => true ) );
	}

	/* ===================== Shapes: customers ===================== */

	/**
	 * A Profiles / Network row as a stable API object.
	 *
	 * @param array $r Aggregate row.
	 * @return array
	 */
	public static function shape_customer_row( $r ) {
		$out = array(
			'email_norm'          => (string) ( $r['email_norm'] ?? '' ),
			'name'                => (string) ( $r['customer_name'] ?? '' ),
			'user_id'             => (int) ( $r['user_id'] ?? 0 ),
			'registered'          => empty( $r['user_registered'] ) ? null : mysql2date( 'c', $r['user_registered'] ),
			'orders'              => (int) ( $r['orders'] ?? 0 ),
			'paid_orders'         => (int) ( $r['paid_orders'] ?? 0 ),
			'spend'               => round( (float) ( $r['spend'] ?? 0 ), 2 ),
			'average_order_value' => round( (float) ( $r['aov'] ?? 0 ), 2 ),
			'chargebacks'         => (int) ( $r['chargebacks'] ?? 0 ),
			'distinct_ips'        => (int) ( $r['ips'] ?? 0 ),
			'distinct_addresses'  => (int) ( $r['addresses'] ?? 0 ),
			'max_risk_score'      => (int) ( $r['max_risk'] ?? 0 ),
			'first_order'         => empty( $r['first_order'] ) ? null : mysql2date( 'c', $r['first_order'] ),
			'last_order'          => empty( $r['last_order'] ) ? null : mysql2date( 'c', $r['last_order'] ),
		);
		if ( array_key_exists( 'blocked', $r ) ) {
			$out['blocked'] = ! empty( $r['blocked'] );
		}
		if ( array_key_exists( 'cb_amount', $r ) ) {
			$out['chargeback_amount'] = round( (float) $r['cb_amount'], 2 );
		}
		if ( array_key_exists( 'phones', $r ) ) {
			$out['distinct_phones'] = (int) $r['phones'];
		}
		return $out;
	}

	/**
	 * A stored gateway restriction with the dates that govern it.
	 *
	 * @param int    $user_id Account id.
	 * @param array  $rec     Stored record.
	 * @param string $email   Normalized email.
	 * @return array
	 */
	public static function shape_restriction( $user_id, $rec, $email = '' ) {
		$expires = FS_Live::gw_restrict_expires_at( $rec );
		return array(
			'user_id'            => (int) $user_id,
			'email_norm'         => (string) $email,
			'gateways'           => array_values( (array) ( $rec['gateways'] ?? array() ) ),
			'low_value_amount'   => (float) ( $rec['lowvalue_amount'] ?? 0 ),
			'low_value_gateways' => array_values( (array) ( $rec['lowvalue_gateways'] ?? array() ) ),
			'applied_at'         => empty( $rec['applied_at'] ) ? null : gmdate( 'c', (int) $rec['applied_at'] ),
			'expires_at'         => $expires > 0 ? gmdate( 'c', $expires ) : null,
			'void_reason'        => FS_Live::gw_restrict_void_reason( (int) $user_id, $rec ),
		);
	}

	/**
	 * A verification hold record.
	 *
	 * @param int    $user_id Account id.
	 * @param mixed  $hold    Stored meta.
	 * @param string $email   Normalized email.
	 * @return array
	 */
	public static function shape_hold( $user_id, $hold, $email = '' ) {
		$hold = is_array( $hold ) ? $hold : array();
		return array(
			'user_id'    => (int) $user_id,
			'email_norm' => (string) $email,
			'order_id'   => (int) ( $hold['order_id'] ?? 0 ),
			'amount'     => (float) ( $hold['amount'] ?? 0 ),
			'age_days'   => (int) ( $hold['age_days'] ?? 0 ),
			'since'      => empty( $hold['ts'] ) ? null : mysql2date( 'c', $hold['ts'] ),
		);
	}

	/**
	 * The auto-verification verdict and the numbers behind it.
	 *
	 * @param array $st Output of FS_Live::auto_verify_status().
	 * @return array
	 */
	public static function shape_auto_verify( $st ) {
		return array(
			'qualifies'   => ! empty( $st['qualifies'] ),
			'enabled'     => ! empty( $st['enabled'] ),
			'reason'      => (string) ( $st['reason'] ?? '' ),
			'age_days'    => isset( $st['age_days'] ) && null !== $st['age_days'] ? (int) $st['age_days'] : null,
			'min_days'    => (int) ( $st['min_days'] ?? 0 ),
			'paid_orders' => (int) ( $st['paid_orders'] ?? 0 ),
			'min_orders'  => (int) ( $st['min_orders'] ?? 0 ),
			'chargebacks' => (int) ( $st['chargebacks'] ?? 0 ),
			'refunds'     => (int) ( $st['refunds'] ?? 0 ),
		);
	}

	/**
	 * A customer's ID-verification standing: the verification in force and
	 * any open staff demand.
	 *
	 * @param array|null $rec Output of FS_IDV::record_for().
	 * @param array|null $req Output of FS_IDV::requirement_record().
	 * @return array
	 */
	public static function shape_idv_record( $rec, $req ) {
		$expires = is_array( $rec ) ? (int) ( $rec['expires'] ?? 0 ) : 0;
		return array(
			'verified'    => is_array( $rec ) && ( 0 === $expires || $expires > time() ),
			'session_id'  => is_array( $rec ) ? (string) ( $rec['session_id'] ?? '' ) : '',
			'full_name'   => is_array( $rec ) ? (string) ( $rec['full_name'] ?? '' ) : '',
			'name_match'  => is_array( $rec ) ? (string) ( $rec['name_match'] ?? '' ) : '',
			'method'      => is_array( $rec ) ? (string) ( $rec['method'] ?? '' ) : '',
			'verified_at' => is_array( $rec ) && ! empty( $rec['at'] ) ? ( is_numeric( $rec['at'] ) ? gmdate( 'c', (int) $rec['at'] ) : mysql2date( 'c', $rec['at'] ) ) : null,
			'expires_at'  => $expires > 0 ? gmdate( 'c', $expires ) : null,
			'requested'   => is_array( $req ) ? array(
				'at' => empty( $req['at'] ) ? null : mysql2date( 'c', $req['at'] ),
				'by' => (int) ( $req['by'] ?? 0 ),
			) : null,
		);
	}

	/**
	 * Blocklist rows for one customer, with who blocked each and under which card.
	 *
	 * @param array $rows Blocklist rows.
	 * @return array
	 */
	public static function shape_blocked_rows( $rows ) {
		$rows = array_values( (array) $rows );
		if ( empty( $rows ) ) {
			return array();
		}
		$prov = FS_Blocklist::provenance( $rows );
		$out  = array();
		foreach ( $rows as $r ) {
			$p     = $prov[ (int) $r['id'] ] ?? array();
			$cards = array();
			foreach ( (array) ( $p['cards'] ?? array() ) as $c ) {
				$cards[] = array(
					'id'         => (int) $c['id'],
					'kind'       => (string) $c['kind'],
					'title'      => (string) $c['title'],
					'created_by' => (string) ( $c['created_by_name'] ?? '' ),
					'created_at' => empty( $c['created_at'] ) ? null : mysql2date( 'c', $c['created_at'] ),
				);
			}
			$out[] = array(
				'id'         => (int) $r['id'],
				'type'       => (string) $r['type'],
				'value'      => (string) $r['value'],
				'reason'     => (string) ( $r['reason'] ?? '' ),
				'blocked_at' => empty( $r['created_at'] ) ? null : mysql2date( 'c', $r['created_at'] ),
				'blocked_by' => (string) ( $p['added_by_name'] ?? '' ),
				'cards'      => $cards,
			);
		}
		return $out;
	}

	/**
	 * A Checkout Locks ledger row, evidence included.
	 *
	 * @param array $row Decoded row.
	 * @return array
	 */
	public static function shape_lock_row( $row ) {
		$ev = isset( $row['evidence'] ) && is_array( $row['evidence'] ) ? $row['evidence'] : array();
		return array(
			'id'         => (int) ( $row['id'] ?? 0 ),
			'kind'       => (string) ( $row['kind'] ?? '' ),
			'user_id'    => (int) ( $row['user_id'] ?? 0 ),
			'email_norm' => (string) ( $row['email'] ?? '' ),
			'name'       => (string) ( $row['name'] ?? '' ),
			'detail'     => (string) ( $row['detail'] ?? '' ),
			'attempts'   => (int) ( $row['attempts'] ?? 0 ),
			'first_at'   => empty( $row['first_at'] ) ? null : mysql2date( 'c', $row['first_at'] ),
			'last_at'    => empty( $row['last_at'] ) ? null : mysql2date( 'c', $row['last_at'] ),
			'resolved'   => ! empty( $row['resolved'] ),
			'evidence'   => empty( $ev ) ? null : ( 'linked' === (string) ( $ev['kind'] ?? '' ) ? self::shape_linked_evidence( $ev ) : self::shape_match_evidence( $ev ) ),
		);
	}

	/**
	 * FS_Blocklist::match_evidence() as API items.
	 *
	 * @param array $ev Evidence record.
	 * @return array
	 */
	public static function shape_match_evidence( $ev ) {
		$items = array();
		foreach ( (array) ( $ev['items'] ?? array() ) as $it ) {
			$cards = array();
			foreach ( (array) ( $it['cards'] ?? array() ) as $c ) {
				$cards[] = array(
					'id'         => (int) ( $c['id'] ?? 0 ),
					'kind'       => (string) ( $c['kind'] ?? '' ),
					'title'      => (string) ( $c['title'] ?? '' ),
					'created_by' => (string) ( $c['created_by_name'] ?? '' ),
				);
			}
			$items[] = array(
				'type'       => (string) ( $it['type'] ?? '' ),
				'value'      => (string) ( $it['value'] ?? '' ),
				'label'      => (string) ( $it['label'] ?? '' ),
				'reason'     => (string) ( $it['reason'] ?? '' ),
				'blocked_at' => empty( $it['blocked_at'] ) ? null : mysql2date( 'c', $it['blocked_at'] ),
				'blocked_by' => (string) ( $it['blocked_by'] ?? '' ),
				'cards'      => $cards,
				'identities' => self::shape_identities( $it['identities'] ?? array() ),
				'more'       => (int) ( $it['more'] ?? 0 ),
			);
		}
		return array(
			'kind'  => 'blocklist',
			'at'    => empty( $ev['at'] ) ? null : mysql2date( 'c', $ev['at'] ),
			'items' => $items,
		);
	}

	/**
	 * A linked-identity evidence record (the links as found) as API items.
	 *
	 * @param array $ev Evidence record.
	 * @return array
	 */
	public static function shape_linked_evidence( $ev ) {
		return array(
			'kind'  => 'linked',
			'at'    => empty( $ev['at'] ) ? null : mysql2date( 'c', $ev['at'] ),
			'links' => self::shape_links( $ev['items'] ?? array() ),
		);
	}

	/**
	 * Links from FS_Blocklist::linked() (or a stored copy) as API objects.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function shape_links( $links ) {
		$out = array();
		foreach ( (array) $links as $l ) {
			$out[] = array(
				'via'           => (string) ( $l['via'] ?? '' ),
				'value'         => (string) ( $l['value'] ?? '' ),
				'on_order'      => ! empty( $l['on_order'] ),
				'degree'        => (int) ( $l['degree'] ?? 0 ),
				'blocked_type'  => (string) ( $l['blocked_type'] ?? '' ),
				'blocked_value' => (string) ( $l['blocked_value'] ?? '' ),
				'block_card'    => (string) ( $l['action_title'] ?? '' ),
				'identities'    => self::shape_identities( $l['identities'] ?? array() ),
			);
		}
		return $out;
	}

	/**
	 * The people behind a credential, as the ledgers store them.
	 *
	 * @param array $ids Identity rows.
	 * @return array
	 */
	private static function shape_identities( $ids ) {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$out[] = array(
				'email'   => (string) ( $id['email'] ?? '' ),
				'user_id' => (int) ( $id['user_id'] ?? 0 ),
				'name'    => (string) ( $id['name'] ?? '' ),
				'blocked' => ! empty( $id['blocked'] ),
			);
		}
		return $out;
	}

	/**
	 * Present a customer's aggregate history as typed JSON.
	 *
	 * The database hands back every aggregate as a string, and a couple of the
	 * columns exist only to feed the scoring engine. Passing that through raw
	 * meant callers received `"orders": "2"` and `"aov": "265.750000"` and had to
	 * cast everything themselves — and had to know what `bill_known` meant.
	 *
	 * @param array|null $summary FS_Data::profile_summary() output.
	 * @return array
	 */
	public static function shape_history( $summary ) {
		if ( ! is_array( $summary ) ) {
			return array();
		}

		return array(
			'orders'              => (int) ( $summary['orders'] ?? 0 ),
			'paid_orders'         => (int) ( $summary['paid_orders'] ?? 0 ),
			'spend'               => round( (float) ( $summary['spend'] ?? 0 ), 2 ),
			'average_order_value' => round( (float) ( $summary['aov'] ?? 0 ), 2 ),
			'chargebacks'         => (int) ( $summary['chargebacks'] ?? 0 ),
			'distinct_ips'        => (int) ( $summary['ips'] ?? 0 ),
			'distinct_addresses'  => (int) ( $summary['addresses'] ?? 0 ),
			'distinct_names'      => (int) ( $summary['names'] ?? 0 ),
			'distinct_phones'     => (int) ( $summary['phones'] ?? 0 ),
			'distinct_gateways'   => (int) ( $summary['payment_methods'] ?? 0 ),
			'distinct_countries'  => (int) ( $summary['countries'] ?? 0 ),
			'max_risk_score'      => (int) ( $summary['max_risk'] ?? 0 ),
			'first_order'         => empty( $summary['first_order'] ) ? null : mysql2date( 'c', $summary['first_order'] ),
			'last_order'          => empty( $summary['last_order'] ) ? null : mysql2date( 'c', $summary['last_order'] ),
		);
	}

	/* ===================== Routes: orders ===================== */

	/**
	 * Indexed orders, filtered.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_orders( $request ) {
		$ip = trim( (string) $request->get_param( 'ip' ) );
		$args = array(
			'level'      => sanitize_key( (string) $request->get_param( 'level' ) ),
			'email'      => FS_Normalizer::email( (string) $request->get_param( 'email' ) ),
			'chargeback' => null !== $request->get_param( 'chargeback' ) ? (bool) $request->get_param( 'chargeback' ) : null,
			// v1.30: every other indexed column an integrator correlates on.
			'user_id'    => max( 0, (int) $request->get_param( 'user_id' ) ),
			'ip'         => filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '',
			'address_id' => preg_match( '/^[A-Fa-f0-9]{64}$/', (string) $request->get_param( 'address_id' ) ) ? strtolower( (string) $request->get_param( 'address_id' ) ) : '',
			'phone'      => FS_Normalizer::phone( (string) $request->get_param( 'phone' ) ),
			'status'     => sanitize_key( (string) $request->get_param( 'status' ) ),
			'gateway'    => sanitize_text_field( (string) $request->get_param( 'gateway' ) ),
			'country'    => sanitize_text_field( (string) $request->get_param( 'country' ) ),
			'state'      => sanitize_text_field( (string) $request->get_param( 'state' ) ),
			'city'       => sanitize_text_field( (string) $request->get_param( 'city' ) ),
			'postcode'   => sanitize_text_field( (string) $request->get_param( 'postcode' ) ),
			'min_score'  => null !== $request->get_param( 'min_score' ) ? max( 0, (int) $request->get_param( 'min_score' ) ) : null,
			'linked_cancelled' => (bool) $request->get_param( 'linked_cancelled' ),
			'from'       => sanitize_text_field( (string) $request->get_param( 'from' ) ),
			'to'         => sanitize_text_field( (string) $request->get_param( 'to' ) ),
			'limit'      => self::limit_param( $request, 50 ),
			'offset'     => max( 0, (int) $request->get_param( 'offset' ) ),
		);

		return $this->ok(
			array(
				'total'  => FS_Data::index_list_count( $args ),
				'limit'  => $args['limit'],
				'offset' => $args['offset'],
				'orders' => array_map( array( __CLASS__, 'shape_order' ), FS_Data::index_list( $args ) ),
			)
		);
	}

	/**
	 * One indexed order, with everything the order screen's Fraud Shield box
	 * knows (v1.30): the linked-cancel record, the cardholder verdict, the ID
	 * session it was placed under, and which of its credentials are on the
	 * blocklist right now, with provenance.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_order( $request ) {
		$row = FS_Data::index_row( (int) $request['id'] );
		if ( ! $row ) {
			return self::error( 'fs_api_not_found', __( 'That order is not in the index.', 'fraud-shield' ), 404 );
		}
		$out = self::shape_order( $row );

		$cancel                = FS_Data::linked_cancel_for_order( (int) $row['order_id'] );
		$out['linked_cancel']  = $cancel ? self::shape_linked_cancel( $cancel ) : null;

		// The high-value review verdict (v1.32), when one was recorded.
		$review                   = FS_Data::hv_latest_review( (int) $row['order_id'] );
		$out['high_value_review'] = $review ? self::shape_hv_review( $review ) : null;

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row['order_id'] ) : null;
		$out['card_check']      = null;
		$out['id_verification'] = null;
		if ( $order ) {
			$rec = $order->get_meta( '_fs_card_check' );
			if ( is_array( $rec ) && ! empty( $rec ) ) {
				$out['card_check'] = self::shape_card_check_meta( (int) $row['order_id'], $rec );
			}
			$session = (string) $order->get_meta( '_fs_idv_session' );
			if ( '' !== $session ) {
				$out['id_verification'] = array( 'session_id' => $session );
			}
			$out['status'] = (string) $order->get_status();
		}

		// Which credentials on the order are blocked NOW — the 1.27 provenance
		// box: since when, by whom, under which card, and the identities behind.
		$signals = $order ? FS_Data::extract_signals( $order ) : self::signals_from_row( $row );
		$matches = FS_Blocklist::match( $signals );
		$out['blocklist'] = empty( $matches ) ? array() : self::shape_match_evidence( FS_Blocklist::match_evidence( $matches, $signals ) )['items'];

		return $this->ok( $out );
	}

	/**
	 * Rebuild a signals array from an index row, for a store whose order object
	 * is gone (deleted order, CSV-only row) but whose index row is still there.
	 *
	 * @param array $row Index row.
	 * @return array
	 */
	private static function signals_from_row( $row ) {
		return array(
			'order_id'         => (int) ( $row['order_id'] ?? 0 ),
			'order_date'       => (string) ( $row['order_date'] ?? '' ),
			'user_id'          => (int) ( $row['user_id'] ?? 0 ),
			'customer_name'    => (string) ( $row['customer_name'] ?? '' ),
			'email_norm'       => (string) ( $row['email_norm'] ?? '' ),
			'email_domain'     => FS_Data::domain_from_email( (string) ( $row['email_norm'] ?? '' ) ),
			'phone_norm'       => (string) ( $row['phone_norm'] ?? '' ),
			'ship_hash'        => (string) ( $row['ship_hash'] ?? '' ),
			'ship_loose_hash'  => (string) ( $row['ship_loose_hash'] ?? '' ),
			'ship_door_hash'   => (string) ( $row['ship_door_hash'] ?? '' ),
			'ship_text'        => (string) ( $row['ship_text'] ?? '' ),
			'ship_city'        => (string) ( $row['ship_city'] ?? '' ),
			'ship_state'       => (string) ( $row['ship_state'] ?? '' ),
			'ship_postcode'    => (string) ( $row['ship_postcode'] ?? '' ),
			'ship_street'      => (string) ( $row['ship_street'] ?? '' ),
			'billing_country'  => (string) ( $row['billing_country'] ?? '' ),
			'shipping_country' => (string) ( $row['shipping_country'] ?? '' ),
			'ip'               => (string) ( $row['ip'] ?? '' ),
			'order_total'      => (float) ( $row['order_total'] ?? 0 ),
			'status'           => (string) ( $row['status'] ?? '' ),
			'payment_method'   => (string) ( $row['payment_method'] ?? '' ),
			'ship_method'      => (string) ( $row['ship_method'] ?? '' ),
			'coupons'          => (string) ( $row['coupons'] ?? '' ),
			'user_agent'       => (string) ( $row['user_agent'] ?? '' ),
			'bill_hash'        => (string) ( $row['bill_hash'] ?? '' ),
		);
	}

	/**
	 * Present an index row as a stable API object.
	 *
	 * Address and user agent are reduced to hashes: a partner platform needs to
	 * know that two orders share an address, not what the address is. The
	 * phone is the normalized digits, like the email — it is a credential the
	 * blocklist keys on, and a platform correlating across stores needs it.
	 *
	 * @param array $row Index row.
	 * @return array
	 */
	public static function shape_order( $row ) {
		$reasons = json_decode( (string) ( $row['risk_reasons'] ?? '' ), true );
		$coupons = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $row['coupons'] ?? '' ) ) ) ) );
		$ua      = (string) ( $row['user_agent'] ?? '' );

		return array(
			'order_id'   => (int) ( $row['order_id'] ?? 0 ),
			'date'       => empty( $row['order_date'] ) ? null : mysql2date( 'c', $row['order_date'] ),
			'user_id'    => (int) ( $row['user_id'] ?? 0 ),
			'email_norm' => (string) ( $row['email_norm'] ?? '' ),
			'name'       => (string) ( $row['customer_name'] ?? '' ),
			'phone'      => (string) ( $row['phone_norm'] ?? '' ),
			'total'      => (float) ( $row['order_total'] ?? 0 ),
			'status'      => (string) ( $row['status'] ?? '' ),
			'gateway'     => (string) ( $row['payment_method'] ?? '' ),
			'shipping_method' => (string) ( $row['ship_method'] ?? '' ),
			'coupons'     => $coupons,
			'ip'          => (string) ( $row['ip'] ?? '' ),
			'device_id'   => '' === $ua ? '' : hash( 'sha256', $ua ),
			'address_id'  => (string) ( $row['ship_hash'] ?? '' ),
			'address_loose_id' => (string) ( $row['ship_loose_hash'] ?? '' ),
			'door_id'     => (string) ( $row['ship_door_hash'] ?? '' ),
			'billing_address_id' => (string) ( $row['bill_hash'] ?? '' ),
			'billing_country' => (string) ( $row['billing_country'] ?? '' ),
			'geo'         => array(
				'country'  => (string) ( $row['shipping_country'] ?? '' ),
				'state'    => (string) ( $row['ship_state'] ?? '' ),
				'city'     => (string) ( $row['ship_city'] ?? '' ),
				'postcode' => (string) ( $row['ship_postcode'] ?? '' ),
				'street'   => (string) ( $row['ship_street'] ?? '' ),
			),
			'chargeback'  => ! empty( $row['is_chargeback'] ),
			'recovered'   => round( (float) ( $row['recovered'] ?? 0 ), 2 ),
			'risk'        => array(
				'score'   => (int) ( $row['risk_score'] ?? 0 ),
				'level'   => (string) ( $row['risk_level'] ?? '' ),
				'reasons' => is_array( $reasons ) ? $reasons : array(),
			),
			'indexed_at'  => empty( $row['indexed_at'] ) ? null : mysql2date( 'c', $row['indexed_at'] ),
		);
	}

	/**
	 * Load a WooCommerce order for a write route.
	 *
	 * @param int $id Order id.
	 * @return WC_Order|WP_Error
	 */
	private function order_for_write( $id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $id ) : null;
		if ( ! $order ) {
			return self::error( 'fs_api_not_found', __( 'No such order.', 'fraud-shield' ), 404 );
		}
		return $order;
	}

	/**
	 * Mark an order as charged back (v1.30). Same path as the order screen's
	 * button: note, re-index, `order.chargeback` event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_order_chargeback( $request ) {
		$order = $this->order_for_write( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$now = FS_Data::set_chargeback( $order, true, 'api' );
		return $this->ok(
			array(
				'ok'         => true,
				'order_id'   => (int) $order->get_id(),
				'chargeback' => (bool) $now,
			)
		);
	}

	/**
	 * Clear the manual chargeback mark (v1.30). The order may still count as
	 * charged back through the Charged back status or a counted refund.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_order_chargeback_clear( $request ) {
		$order = $this->order_for_write( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$now = FS_Data::set_chargeback( $order, false, 'api' );
		return $this->ok(
			array(
				'ok'         => true,
				'order_id'   => (int) $order->get_id(),
				'chargeback' => (bool) $now,
			)
		);
	}

	/**
	 * Record a chargeback recovery (v1.30): the amount won back on a dispute or
	 * a partial reversal. Capped at the order total.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_order_recovery( $request ) {
		$order = $this->order_for_write( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$body   = $this->json_body( $request );
		$amount = isset( $body['amount'] ) ? (float) $body['amount'] : 0;
		if ( $amount <= 0 ) {
			return self::error( 'fs_api_bad_value', __( 'amount must be more than zero.', 'fraud-shield' ), 400 );
		}
		$recorded = FS_Data::set_recovery( $order, $amount, 'api' );
		return $this->ok(
			array(
				'ok'        => true,
				'order_id'  => (int) $order->get_id(),
				'recovered' => $recorded,
				'total'     => (float) $order->get_total(),
			),
			201
		);
	}

	/**
	 * Clear a recorded recovery (v1.30).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_order_recovery_clear( $request ) {
		$order = $this->order_for_write( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		FS_Data::set_recovery( $order, null, 'api' );
		return $this->ok(
			array(
				'ok'        => true,
				'order_id'  => (int) $order->get_id(),
				'recovered' => 0.0,
			)
		);
	}

	/**
	 * Run the NMI cardholder check on an order now (v1.30) — the order screen's
	 * "Run card check" button. Synchronous: the issuer's verdict is in the
	 * response, and `card.checked` is emitted as usual.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_order_card_check( $request ) {
		if ( ! class_exists( 'FS_Cardholder' ) || ! FS_Cardholder::enabled() ) {
			return self::error( 'fs_api_card_check_unavailable', __( 'The cardholder check is not configured on this store.', 'fraud-shield' ), 409 );
		}
		$order = $this->order_for_write( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$runner = FS_Core::instance()->cardholder;
		$code   = $runner ? (string) $runner->run_check( $order, true ) : 'disabled';
		$rec    = $order->get_meta( '_fs_card_check' );
		return $this->ok(
			array(
				'ok'         => 'ok' === $code,
				'result'     => $code,
				'order_id'   => (int) $order->get_id(),
				'card_check' => is_array( $rec ) && ! empty( $rec ) ? self::shape_card_check_meta( (int) $order->get_id(), $rec ) : null,
			)
		);
	}

	/**
	 * The Card Checks page (v1.30): the issuer's verdict on every checked
	 * payment, newest first. Option-backed and capped, so paging is in memory.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_card_checks( $request ) {
		$limit      = self::limit_param( $request, 50 );
		$offset     = max( 0, (int) $request->get_param( 'offset' ) );
		$outcome    = sanitize_key( (string) $request->get_param( 'outcome' ) );
		$cardholder = sanitize_key( (string) $request->get_param( 'cardholder' ) );
		$email      = FS_Normalizer::email( (string) $request->get_param( 'email' ) );

		$rows = class_exists( 'FS_Cardholder' ) ? FS_Cardholder::log_rows() : array();
		$rows = array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $outcome, $cardholder, $email ) {
					if ( '' !== $outcome && (string) ( $r['outcome'] ?? '' ) !== $outcome ) {
						return false;
					}
					if ( '' !== $cardholder && (string) ( $r['ch'] ?? '' ) !== $cardholder ) {
						return false;
					}
					if ( '' !== $email && (string) ( $r['email'] ?? '' ) !== $email ) {
						return false;
					}
					return true;
				}
			)
		);

		return $this->ok(
			array(
				'total'  => count( $rows ),
				'limit'  => $limit,
				'offset' => $offset,
				'checks' => array_map( array( __CLASS__, 'shape_card_log_row' ), array_slice( $rows, $offset, $limit ) ),
			)
		);
	}

	/**
	 * The Auto-Cancelled ledger (v1.30): every order the linked-identity rule
	 * cancelled, with the shared credentials and the identities behind them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_linked_cancels( $request ) {
		$args = array(
			'search' => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'limit'  => self::limit_param( $request, 50 ),
			'offset' => max( 0, (int) $request->get_param( 'offset' ) ),
		);
		$rows = array();
		foreach ( FS_Data::linked_cancels_list( $args ) as $r ) {
			$rows[] = self::shape_linked_cancel( $r );
		}
		$stats = FS_Data::linked_cancel_stats();
		return $this->ok(
			array(
				'total'   => FS_Data::linked_cancels_count( $args['search'] ),
				'limit'   => $args['limit'],
				'offset'  => $args['offset'],
				'stats'   => array(
					'total'    => (int) $stats['total'],
					'last_30d' => (int) $stats['last_30d'],
					'value'    => (float) $stats['value'],
					'retries'  => (int) $stats['retries'],
					'currency' => (string) $stats['currency'],
				),
				'cancels' => $rows,
			)
		);
	}

	/**
	 * One Auto-Cancelled row (v1.30).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_linked_cancel( $request ) {
		$row = FS_Data::linked_cancel_row( (int) $request['id'] );
		if ( ! $row ) {
			return self::error( 'fs_api_not_found', __( 'No such auto-cancel record.', 'fraud-shield' ), 404 );
		}
		return $this->ok( self::shape_linked_cancel( $row ) );
	}

	/* ===================== Routes: high-value review (v1.32) ===================== */

	/**
	 * The window a high-value request asks for, defaulting to today.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array hv_window() result.
	 */
	private static function hv_window_param( $request ) {
		return FS_Data::hv_window( sanitize_key( (string) $request->get_param( 'window' ) ) );
	}

	/**
	 * The High-Value Review queue: every indexed order at or above the review
	 * amount placed in the window, each with its latest verdict (or none),
	 * plus the page's headline numbers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_high_value( $request ) {
		$amount = FS_Live::hv_review_amount();
		if ( $amount <= 0 ) {
			return self::error( 'fs_api_hv_disabled', __( 'High-value review is switched off on this store (the review amount is 0).', 'fraud-shield' ), 409 );
		}
		$win  = self::hv_window_param( $request );
		$show = sanitize_key( (string) $request->get_param( 'show' ) );
		$args = array(
			'amount'       => $amount,
			'from'         => $win['from'],
			'to'           => $win['to'],
			'show'         => in_array( $show, array( 'unreviewed', 'reviewed', 'all' ), true ) ? $show : 'all',
			'include_dead' => (bool) $request->get_param( 'include_dead' ),
			'search'       => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'orderby'      => sanitize_key( (string) $request->get_param( 'orderby' ) ),
			'dir'          => sanitize_key( (string) $request->get_param( 'dir' ) ),
			'limit'        => self::limit_param( $request, 50 ),
			'offset'       => max( 0, (int) $request->get_param( 'offset' ) ),
		);
		$rows  = FS_Data::hv_queue_list( $args );
		$facts = FS_Data::hv_customer_facts( wp_list_pluck( $rows, 'email_norm' ) );
		$out   = array();
		foreach ( $rows as $r ) {
			$out[] = self::shape_hv_queue_row( $r, $facts[ (string) $r['email_norm'] ] ?? null );
		}
		return $this->ok(
			array(
				'total'  => FS_Data::hv_queue_count( $args ),
				'limit'  => $args['limit'],
				'offset' => $args['offset'],
				'amount' => $amount,
				'window' => self::shape_hv_window( $win ),
				'stats'  => self::shape_hv_stats( FS_Data::hv_queue_stats( $amount, $win ) ),
				'orders' => $out,
			)
		);
	}

	/**
	 * The review log: every verdict recorded in the window, newest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_high_value_reviews( $request ) {
		$win      = self::hv_window_param( $request );
		$all_time = (bool) $request->get_param( 'all_time' );
		$reviewer = $request->get_param( 'reviewer_id' );
		$args     = array(
			'from'        => $all_time ? '' : $win['from'],
			'to'          => $all_time ? '' : $win['to'],
			'reviewer_id' => ( null === $reviewer || '' === (string) $reviewer ) ? null : max( 0, (int) $reviewer ),
			'verdict'     => sanitize_key( (string) $request->get_param( 'verdict' ) ),
			'search'      => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'limit'       => self::limit_param( $request, 50 ),
			'offset'      => max( 0, (int) $request->get_param( 'offset' ) ),
		);
		return $this->ok(
			array(
				'total'   => FS_Data::hv_reviews_count( $args ),
				'limit'   => $args['limit'],
				'offset'  => $args['offset'],
				'window'  => $all_time ? null : self::shape_hv_window( $win ),
				'reviews' => array_map( array( __CLASS__, 'shape_hv_review' ), FS_Data::hv_reviews_list( $args ) ),
			)
		);
	}

	/**
	 * One review-log row.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_high_value_review( $request ) {
		$row = FS_Data::hv_review_row( (int) $request['id'] );
		if ( ! $row ) {
			return self::error( 'fs_api_not_found', __( 'No such review.', 'fraud-shield' ), 404 );
		}
		return $this->ok( self::shape_hv_review( $row ) );
	}

	/**
	 * Who is checking the high-value orders: the leaderboard for the window
	 * (or all time).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_high_value_leaderboard( $request ) {
		$win      = self::hv_window_param( $request );
		$all_time = (bool) $request->get_param( 'all_time' );
		$board    = FS_Data::hv_leaderboard( $all_time ? '' : $win['from'], $all_time ? '' : $win['to'] );
		$rows     = array();
		foreach ( $board as $b ) {
			$rows[] = array(
				'rank'          => (int) $b['rank'],
				'reviewer_id'   => (int) $b['reviewer_id'],
				'reviewer_name' => (string) $b['reviewer_name'],
				'reviews'       => (int) $b['reviews'],
				'cleared'       => (int) $b['cleared'],
				'held'          => (int) $b['held'],
				'cancelled'     => (int) $b['cancelled'],
				'value'         => (float) $b['value'],
				'share'         => (float) $b['share'],
				'last_at'       => '' !== (string) $b['last_at'] ? mysql2date( 'c', $b['last_at'] ) : null,
				'all_time'      => (int) $b['all_time'],
			);
		}
		return $this->ok(
			array(
				'window'      => $all_time ? null : self::shape_hv_window( $win ),
				'reviews'     => (int) array_sum( wp_list_pluck( $board, 'reviews' ) ),
				'leaderboard' => $rows,
			)
		);
	}

	/**
	 * Record a verdict on an order (v1.32) — the review page's buttons over
	 * the API, through the same path: the note, the status change and the
	 * `order.reviewed` event are identical. The reviewer is the API key.
	 *
	 * Body: { verdict: "cleared"|"held"|"cancelled", note?: string }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_order_review( $request ) {
		$order = $this->order_for_write( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$body    = $this->json_body( $request );
		$verdict = sanitize_key( (string) ( $body['verdict'] ?? '' ) );
		if ( ! isset( FS_Data::hv_verdicts()[ $verdict ] ) ) {
			return self::error( 'fs_api_invalid_verdict', __( 'verdict must be one of: cleared, held, cancelled.', 'fraud-shield' ), 400 );
		}
		$result = FS_Live::review_high_value(
			$order,
			$verdict,
			isset( $body['note'] ) ? (string) $body['note'] : '',
			'api',
			array(
				'id'   => 0,
				/* translators: %s: API key label */
				'name' => sprintf( __( 'API: %s', 'fraud-shield' ), (string) ( $this->key['label'] ?? '' ) ),
			)
		);
		if ( empty( $result['ok'] ) ) {
			return self::error( 'fs_api_review_failed', (string) $result['message'], 400 );
		}
		$row = FS_Data::hv_review_row( (int) $result['review_id'] );
		return $this->ok(
			array(
				'ok'            => true,
				'order_id'      => (int) $order->get_id(),
				'verdict'       => $result['verdict'],
				'status_before' => $result['status_before'],
				'status_after'  => $result['status_after'],
				'changed'       => (bool) $result['changed'],
				'message'       => $result['message'],
				'review'        => $row ? self::shape_hv_review( $row ) : null,
			),
			201
		);
	}

	/* ===================== Shapes: high-value review ===================== */

	/**
	 * A window as an API object.
	 *
	 * @param array $win hv_window() result.
	 * @return array
	 */
	public static function shape_hv_window( $win ) {
		return array(
			'key'   => (string) $win['key'],
			'label' => (string) $win['label'],
			'from'  => mysql2date( 'c', $win['from'] ),
			'to'    => mysql2date( 'c', $win['to'] ),
		);
	}

	/**
	 * The page's headline numbers as an API object.
	 *
	 * @param array $s hv_queue_stats() result.
	 * @return array
	 */
	public static function shape_hv_stats( $s ) {
		return array(
			'total'             => (int) $s['total'],
			'unreviewed'        => (int) $s['unreviewed'],
			'reviewed'          => (int) $s['reviewed'],
			'cleared'           => (int) $s['cleared'],
			'held'              => (int) $s['held'],
			'cancelled'         => (int) $s['cancelled'],
			'value'             => (float) $s['value'],
			'unreviewed_value'  => (float) $s['unreviewed_value'],
			'backlog'           => (int) $s['backlog'],
			'oldest_unreviewed' => '' !== (string) $s['oldest_unreviewed'] ? mysql2date( 'c', $s['oldest_unreviewed'] ) : null,
			'currency'          => (string) $s['currency'],
		);
	}

	/**
	 * A queue row: the indexed order plus its latest verdict and the
	 * customer's history.
	 *
	 * @param array      $r    Queue row (index columns + review columns).
	 * @param array|null $fact hv_customer_facts() entry for the email.
	 * @return array
	 */
	public static function shape_hv_queue_row( $r, $fact = null ) {
		$out           = self::shape_order( $r );
		$out['review'] = empty( $r['review_id'] ) ? null : array(
			'id'            => (int) $r['review_id'],
			'verdict'       => (string) $r['verdict'],
			'note'          => (string) ( $r['review_note'] ?? '' ),
			'reviewer_id'   => (int) $r['reviewer_id'],
			'reviewer_name' => FS_Data::hv_reviewer_name( (int) $r['reviewer_id'], (string) $r['reviewer_name'] ),
			'reviewed_at'   => empty( $r['reviewed_at'] ) ? null : mysql2date( 'c', $r['reviewed_at'] ),
			'source'        => (string) ( $r['review_source'] ?? '' ),
			'count'         => (int) ( $r['review_count'] ?? 0 ),
		);
		$out['customer_history'] = null === $fact ? null : array(
			'orders'      => (int) $fact['orders'],
			'chargebacks' => (int) $fact['chargebacks'],
			'spend'       => (float) $fact['spend'],
			'first_order' => '' !== (string) $fact['first_order'] ? mysql2date( 'c', $fact['first_order'] ) : null,
		);
		return $out;
	}

	/**
	 * A review-log row as an API object — the same fields as the
	 * `order.reviewed` event.
	 *
	 * @param array $r Ledger row.
	 * @return array
	 */
	public static function shape_hv_review( $r ) {
		return array(
			'id'            => (int) ( $r['id'] ?? 0 ),
			'order_id'      => (int) ( $r['order_id'] ?? 0 ),
			'user_id'       => (int) ( $r['user_id'] ?? 0 ),
			'email_norm'    => (string) ( $r['email_norm'] ?? '' ),
			'name'          => (string) ( $r['customer_name'] ?? '' ),
			'total'         => (float) ( $r['order_total'] ?? 0 ),
			'currency'      => (string) ( $r['currency'] ?? '' ),
			'verdict'       => (string) ( $r['verdict'] ?? '' ),
			'note'          => (string) ( $r['note'] ?? '' ),
			'status_before' => (string) ( $r['status_before'] ?? '' ),
			'status_after'  => (string) ( $r['status_after'] ?? '' ),
			'reviewer'      => array(
				'id'   => (int) ( $r['reviewer_id'] ?? 0 ),
				'name' => FS_Data::hv_reviewer_name( (int) ( $r['reviewer_id'] ?? 0 ), (string) ( $r['reviewer_name'] ?? '' ) ),
			),
			'source'        => (string) ( $r['source'] ?? '' ),
			'reviewed_at'   => empty( $r['reviewed_at'] ) ? null : mysql2date( 'c', $r['reviewed_at'] ),
		);
	}

	/* ===================== Shapes: orders ===================== */

	/**
	 * An Auto-Cancelled ledger row (links decoded) as an API object.
	 *
	 * @param array $r Row.
	 * @return array
	 */
	public static function shape_linked_cancel( $r ) {
		$links = isset( $r['links'] ) && is_array( $r['links'] ) ? $r['links'] : array();
		return array(
			'id'              => (int) ( $r['id'] ?? 0 ),
			'order_id'        => (int) ( $r['order_id'] ?? 0 ),
			'user_id'         => (int) ( $r['user_id'] ?? 0 ),
			'email_norm'      => (string) ( $r['email_norm'] ?? '' ),
			'name'            => (string) ( $r['customer_name'] ?? '' ),
			'total'           => (float) ( $r['order_total'] ?? 0 ),
			'currency'        => (string) ( $r['currency'] ?? '' ),
			'summary'         => (string) ( $r['summary'] ?? '' ),
			'shares'          => array_values( array_unique( array_filter( array_map( 'strval', wp_list_pluck( $links, 'via' ) ) ) ) ),
			'links'           => self::shape_links( $links ),
			'attempts'        => (int) ( $r['attempts'] ?? 0 ),
			'cancelled_at'    => empty( $r['cancelled_at'] ) ? null : mysql2date( 'c', $r['cancelled_at'] ),
			'last_attempt_at' => empty( $r['last_attempt_at'] ) ? null : mysql2date( 'c', $r['last_attempt_at'] ),
		);
	}

	/**
	 * A Card Checks log row as an API object — the same fields as the
	 * `card.checked` event.
	 *
	 * @param array $r Log row.
	 * @return array
	 */
	public static function shape_card_log_row( $r ) {
		return array(
			'order_id'    => (int) ( $r['order'] ?? 0 ),
			'email_norm'  => (string) ( $r['email'] ?? '' ),
			'gateway'     => (string) ( $r['gw'] ?? '' ),
			'outcome'     => (string) ( $r['outcome'] ?? '' ),
			'cardholder'  => (string) ( $r['ch'] ?? '' ),
			'id_verified' => ! empty( $r['idv'] ),
			'avs'         => (string) ( $r['avs'] ?? '' ),
			'cvv'         => (string) ( $r['csc'] ?? '' ),
			'brand'       => (string) ( $r['brand'] ?? '' ),
			'last4'       => (string) ( $r['last4'] ?? '' ),
			'three_ds'    => (string) ( $r['tds'] ?? '' ),
			'key_label'   => (string) ( $r['key'] ?? '' ),
			'checked_at'  => empty( $r['t'] ) ? null : gmdate( 'c', (int) $r['t'] ),
		);
	}

	/**
	 * The `_fs_card_check` order meta as an API object.
	 *
	 * @param int   $order_id Order id.
	 * @param array $rec      Stored record.
	 * @return array
	 */
	public static function shape_card_check_meta( $order_id, $rec ) {
		return array(
			'order_id'    => (int) $order_id,
			'gateway'     => (string) ( $rec['gateway'] ?? '' ),
			'outcome'     => (string) ( $rec['outcome'] ?? '' ),
			'cardholder'  => (string) ( $rec['cardholder'] ?? '' ),
			'id_verified' => ! empty( $rec['idv'] ),
			'avs'         => (string) ( $rec['avs'] ?? '' ),
			'cvv'         => (string) ( $rec['csc'] ?? '' ),
			'brand'       => (string) ( $rec['brand'] ?? '' ),
			'bin'         => (string) ( $rec['bin'] ?? '' ),
			'last4'       => (string) ( $rec['last4'] ?? '' ),
			'three_ds'    => (string) ( $rec['tds_version'] ?? '' ),
			'eci'         => (string) ( $rec['eci'] ?? '' ),
			'cavv_result' => (string) ( $rec['cavv_result'] ?? '' ),
			'condition'   => (string) ( $rec['condition'] ?? '' ),
			'key_label'   => (string) ( $rec['key_label'] ?? '' ),
			'reason'      => (string) ( $rec['reason'] ?? '' ),
			'checked_at'  => empty( $rec['checked_at'] ) ? null : gmdate( 'c', (int) $rec['checked_at'] ),
		);
	}

	/* ===================== Routes: blocklist ===================== */

	/**
	 * Current blocklist entries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_blocklist_list( $request ) {
		$limit  = self::limit_param( $request, 100 );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$type   = sanitize_key( (string) $request->get_param( 'type' ) );
		$type   = in_array( $type, FS_Blocklist::TYPES, true ) ? $type : '';
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );

		if ( '' !== $search ) {
			// The Blocklist page's search (v1.27), flattened to credentials: the
			// entries each matching card kept, plus matching ungrouped rows.
			$found = FS_Blocklist::search( $search, $limit, $offset, $type );
			$rows  = array();
			foreach ( (array) $found['actions'] as $a ) {
				foreach ( (array) $a['entries'] as $e ) {
					$rows[] = $e;
				}
			}
			foreach ( (array) $found['ungrouped'] as $e ) {
				$rows[] = $e;
			}
			$total = (int) $found['entries_total'];
		} else {
			$rows  = FS_Blocklist::all( $limit, $offset, $type );
			$total = FS_Blocklist::count( $type );
		}

		return $this->ok(
			array(
				'total'   => $total,
				'limit'   => $limit,
				'offset'  => $offset,
				'type'    => $type,
				'search'  => $search,
				'entries' => self::shape_entries( $rows ),
			)
		);
	}

	/**
	 * Blocklist rows as API objects, with who added each and the block cards
	 * that own it (v1.30). Two queries for the page, however long.
	 *
	 * @param array $rows Blocklist rows.
	 * @return array
	 */
	private static function shape_entries( $rows ) {
		$rows = array_values( (array) $rows );
		if ( empty( $rows ) ) {
			return array();
		}
		$ids    = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$owners = FS_Blocklist::owners_of_entries( $ids );
		$names  = FS_Blocklist::actor_names( array_map( 'intval', wp_list_pluck( $rows, 'added_by' ) ) );
		$out    = array();
		foreach ( $rows as $row ) {
			$cards = array();
			foreach ( (array) ( $owners[ (int) $row['id'] ] ?? array() ) as $c ) {
				$cards[] = array(
					'id'    => (int) $c['id'],
					'kind'  => (string) $c['kind'],
					'title' => (string) $c['title'],
				);
			}
			$out[] = array(
				'id'            => (int) $row['id'],
				'type'          => (string) $row['type'],
				'value'         => (string) $row['value'],
				'label'         => FS_Blocklist::entry_label( (string) $row['type'], (string) $row['value'] ),
				'reason'        => (string) ( $row['reason'] ?? '' ),
				'message'       => (string) ( $row['message'] ?? '' ),
				'added_by'      => (int) ( $row['added_by'] ?? 0 ),
				'added_by_name' => (string) ( $names[ (int) ( $row['added_by'] ?? 0 ) ] ?? '' ),
				'created_at'    => empty( $row['created_at'] ) ? null : mysql2date( 'c', $row['created_at'] ),
				'cards'         => $cards,
			);
		}
		return $out;
	}

	/**
	 * Remove many credentials at once (v1.30): by row id, by IP value, or
	 * every IP on the list — the Blocklist page's bulk tools. One
	 * `blocklist.removed` event per call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_blocklist_bulk_delete( $request ) {
		$body = $this->json_body( $request );

		if ( ! empty( $body['ids'] ) && is_array( $body['ids'] ) ) {
			$result = FS_Blocklist::remove_entries( array_map( 'intval', $body['ids'] ) );
			return $this->ok(
				array(
					'ok'      => true,
					'removed' => (int) $result['removed'],
					'by_type' => (array) $result['by_type'],
				)
			);
		}

		$type = isset( $body['type'] ) ? sanitize_key( (string) $body['type'] ) : '';
		if ( 'ip' === $type && ! empty( $body['all'] ) ) {
			$removed = FS_Blocklist::remove_all_ips();
			return $this->ok(
				array(
					'ok'      => true,
					'removed' => (int) $removed,
					'by_type' => $removed > 0 ? array( 'ip' => (int) $removed ) : array(),
				)
			);
		}
		if ( 'ip' === $type && ! empty( $body['values'] ) ) {
			$ips    = is_array( $body['values'] ) ? $body['values'] : FS_Blocklist::parse_ip_list( (string) $body['values'] );
			$result = FS_Blocklist::remove_ips( array_filter( array_map( 'strval', (array) $ips ), 'strlen' ) );
			return $this->ok(
				array(
					'ok'      => true,
					'removed' => (int) $result['removed'],
					'missing' => (int) $result['missing'],
					'by_type' => $result['removed'] > 0 ? array( 'ip' => (int) $result['removed'] ) : array(),
				)
			);
		}

		if ( ! empty( $body['ungrouped'] ) ) {
			// The legacy "Ungrouped" card: pre-1.6 rows no block action owns.
			$removed = FS_Blocklist::remove_all_ungrouped();
			return $this->ok(
				array(
					'ok'      => true,
					'removed' => (int) $removed,
					'by_type' => array(),
				)
			);
		}

		return self::error( 'fs_api_bad_value', __( 'Provide ids (row ids), type "ip" with values (a list of IPs) or all: true, or ungrouped: true.', 'fraud-shield' ), 400 );
	}

	/**
	 * Set or clear the checkout message on one credential (v1.30) — what the
	 * refused customer reads when this credential matches. An empty message
	 * falls back down the precedence chain: block card → store default.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_blocklist_message( $request ) {
		$id   = (int) $request['id'];
		$body = $this->json_body( $request );
		if ( ! array_key_exists( 'message', $body ) ) {
			return self::error( 'fs_api_bad_value', __( 'Provide message (an empty string clears it).', 'fraud-shield' ), 400 );
		}
		if ( ! FS_Blocklist::get_entry( $id ) ) {
			return self::error( 'fs_api_not_found', __( 'No such blocklist entry.', 'fraud-shield' ), 404 );
		}
		FS_Blocklist::update_message( $id, wp_kses_post( (string) $body['message'] ) );
		return $this->ok(
			array(
				'ok'      => true,
				'id'      => $id,
				'message' => wp_kses_post( (string) $body['message'] ),
			)
		);
	}

	/**
	 * Set or clear the whole-card checkout message (v1.30). Precedence at
	 * checkout: per-credential message → this card message → store default.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_block_action_message( $request ) {
		$id     = (int) $request['id'];
		$action = FS_Blocklist::get_action( $id );
		if ( ! $action ) {
			return self::error( 'fs_api_not_found', __( 'No such block.', 'fraud-shield' ), 404 );
		}
		$body = $this->json_body( $request );
		if ( ! array_key_exists( 'message', $body ) ) {
			return self::error( 'fs_api_bad_value', __( 'Provide message (an empty string clears it).', 'fraud-shield' ), 400 );
		}
		FS_Blocklist::set_action_message( $id, wp_kses_post( (string) $body['message'] ) );
		return $this->ok(
			array(
				'ok'      => true,
				'id'      => $id,
				'message' => wp_kses_post( (string) $body['message'] ),
			)
		);
	}

	/**
	 * Block cards (v1.30): one per block decision, each with the credentials
	 * it currently owns — the Blocklist page as an endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_block_actions( $request ) {
		$limit  = self::limit_param( $request, 50 );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$cards  = array();
		foreach ( FS_Blocklist::all_actions_with_entries( $limit, $offset ) as $a ) {
			$cards[] = self::shape_action( $a, isset( $a['entries'] ) ? (array) $a['entries'] : null );
		}
		return $this->ok(
			array(
				'total'     => FS_Blocklist::actions_count(),
				'limit'     => $limit,
				'offset'    => $offset,
				'ungrouped' => FS_Blocklist::ungrouped_count(),
				'actions'   => $cards,
			)
		);
	}

	/**
	 * One block card with every credential it owns (v1.30).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_block_action( $request ) {
		$action = FS_Blocklist::get_action( (int) $request['id'] );
		if ( ! $action ) {
			return self::error( 'fs_api_not_found', __( 'No such block.', 'fraud-shield' ), 404 );
		}
		return $this->ok( self::shape_action( $action, FS_Blocklist::action_entries( (int) $action['id'] ) ) );
	}

	/**
	 * Reverse a block (v1.30): the card's "Remove all (unblock)". Credentials
	 * another standing card still owns survive unless `release_shared=1`,
	 * which is the profile toggle's "release the person" semantics. Emits
	 * `customer.unblocked`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_block_action_delete( $request ) {
		$id     = (int) $request['id'];
		$action = FS_Blocklist::get_action( $id );
		if ( ! $action ) {
			return self::error( 'fs_api_not_found', __( 'No such block.', 'fraud-shield' ), 404 );
		}
		$released = FS_Blocklist::unblock_action( $id, (bool) $request->get_param( 'release_shared' ) );
		return $this->ok(
			array(
				'ok'       => true,
				'id'       => $id,
				'released' => (int) $released,
			)
		);
	}

	/**
	 * Re-run a card's cascade (v1.30) to sweep in credentials that appeared
	 * after the original block — the card's "Re-sync".
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_block_action_resync( $request ) {
		$id     = (int) $request['id'];
		$action = FS_Blocklist::get_action( $id );
		if ( ! $action ) {
			return self::error( 'fs_api_not_found', __( 'No such block.', 'fraud-shield' ), 404 );
		}
		$result = FS_Blocklist::resync_action( $id );
		return $this->ok(
			array(
				'ok'    => true,
				'id'    => $id,
				'added' => (int) ( $result['added'] ?? 0 ),
			)
		);
	}

	/**
	 * A block action as an API object, optionally with its entries.
	 *
	 * @param array      $a       Action row.
	 * @param array|null $entries Owned blocklist rows (null = omit).
	 * @return array
	 */
	public static function shape_action( $a, $entries = null ) {
		$names = FS_Blocklist::actor_names( array( (int) ( $a['created_by'] ?? 0 ) ) );
		$out   = array(
			'id'              => (int) ( $a['id'] ?? 0 ),
			'kind'            => (string) ( $a['kind'] ?? '' ),
			'target'          => (string) ( $a['target'] ?? '' ),
			'title'           => (string) ( $a['title'] ?? '' ),
			'message'         => (string) ( $a['message'] ?? '' ),
			'created_by'      => (int) ( $a['created_by'] ?? 0 ),
			'created_by_name' => (string) ( $names[ (int) ( $a['created_by'] ?? 0 ) ] ?? '' ),
			'created_at'      => empty( $a['created_at'] ) ? null : mysql2date( 'c', $a['created_at'] ),
		);
		if ( null !== $entries ) {
			$out['entries'] = self::shape_entries( $entries );
		}
		return $out;
	}

	/**
	 * Block a credential.
	 *
	 * Goes through block_manual() rather than a bare insert so the entry is
	 * owned by a reversible block action, exactly like one created in wp-admin —
	 * an API caller should not be able to create blocks staff cannot undo in
	 * one click.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_blocklist_add( $request ) {
		$body  = $this->json_body( $request );
		$type  = isset( $body['type'] ) ? sanitize_key( (string) $body['type'] ) : '';
		$value = isset( $body['value'] ) ? (string) $body['value'] : '';

		if ( ! in_array( $type, FS_Blocklist::TYPES, true ) ) {
			return self::error(
				'fs_api_bad_type',
				/* translators: %s: comma-separated list of accepted values */
				sprintf( __( 'type must be one of: %s.', 'fraud-shield' ), implode( ', ', FS_Blocklist::TYPES ) ),
				400
			);
		}
		if ( '' === trim( $value ) ) {
			return self::error( 'fs_api_bad_value', __( 'value is required.', 'fraud-shield' ), 400 );
		}

		$normalized = FS_Blocklist::normalize_value( $type, $value );
		if ( '' === $normalized || '0' === $normalized ) {
			return self::error( 'fs_api_bad_value', __( 'That value could not be normalized for this type.', 'fraud-shield' ), 400 );
		}

		$reason  = isset( $body['reason'] ) ? sanitize_text_field( (string) $body['reason'] ) : '';
		$message = isset( $body['message'] ) ? wp_kses_post( (string) $body['message'] ) : '';
		if ( '' === $reason ) {
			$reason = sprintf(
				/* translators: %s: API key label */
				__( 'Added over the API by %s', 'fraud-shield' ),
				(string) $this->key['label']
			);
		}

		// A destination lock is its own kind of action, not a manual one. Routing
		// it through block_manual() would create a 'manual' action whose target
		// happens to start with "place:" — invisible to, and not releasable from,
		// the Geography screen, which looks locks up by ( 'place', <bare key> ).
		if ( 'place' === $type ) {
			$colon = strpos( $normalized, ':' );
			$scope = substr( $normalized, 0, $colon );
			$segs  = explode( '|', substr( $normalized, $colon + 1 ) );

			// Segments: state → CC|ST; zip → CC|PREFIX; city → CC|ST|CITY;
			// street → CC|ST|CITY|STREET; streetcounty → CC|ST|COUNTY|STREET;
			// building → CC|ST|NUMBER|STREET; streetzip → CC|ZIP|STREET (the
			// street already normalized in every case).
			// An area ban has no country segment: lat|lng|metres.
			if ( 'geo' === $scope ) {
				$placed = FS_Blocklist::block_geofence( isset( $segs[0] ) ? $segs[0] : '', isset( $segs[1] ) ? $segs[1] : '', isset( $segs[2] ) ? $segs[2] : '', '', $message, $reason );
				return $this->ok(
					array(
						'ok'        => (bool) $placed['action_id'],
						'type'      => $type,
						'value'     => $normalized,
						'action_id' => (int) $placed['action_id'],
					),
					201
				);
			}
			$placed = FS_Blocklist::block_place(
				$scope,
				isset( $segs[0] ) ? $segs[0] : '',
				isset( $segs[1] ) ? $segs[1] : '',
				in_array( $scope, array( 'city', 'street', 'streetcounty', 'building' ), true ) && isset( $segs[2] ) ? $segs[2] : '',
				$reason,
				$message,
				'streetzip' === $scope ? ( isset( $segs[2] ) ? $segs[2] : '' ) : ( in_array( $scope, array( 'street', 'streetcounty', 'building' ), true ) && isset( $segs[3] ) ? $segs[3] : '' )
			);

			return $this->ok(
				array(
					'ok'        => (bool) $placed['action_id'],
					'type'      => $type,
					'value'     => $normalized,
					'action_id' => (int) $placed['action_id'],
				),
				201
			);
		}

		// cascade: true (v1.30) is the admin's "Block this address / IP + all
		// credentials" and "Block profile" buttons — the cluster or identity
		// block that sweeps in every email, phone, IP, account and address ever
		// associated with the target, under one reversible card.
		if ( ! empty( $body['cascade'] ) && in_array( $type, array( 'address', 'ip', 'email' ), true ) ) {
			$result = 'email' === $type
				? FS_Blocklist::block_identity( $normalized, $reason, $message )
				: FS_Blocklist::block_cluster( $type, $normalized, $reason, $message );
			if ( empty( $result['action_id'] ) ) {
				return self::error( 'fs_api_bad_value', __( 'That value could not be blocked.', 'fraud-shield' ), 400 );
			}
			return $this->ok(
				array(
					'ok'        => true,
					'type'      => $type,
					'value'     => $normalized,
					'action_id' => (int) $result['action_id'],
					'cascade'   => true,
					'added'     => (int) ( $result['added'] ?? 0 ),
				),
				201
			);
		}

		// block_manual() creates the reversible action and cascades the entry,
		// and emits customer.blocked on the way through — so an API block and an
		// admin block produce the same event.
		$action_id = FS_Blocklist::block_manual( $type, $value, $reason, $message );

		return $this->ok(
			array(
				'ok'        => true,
				'type'      => $type,
				'value'     => $normalized,
				'action_id' => (int) $action_id,
				'cascade'   => false,
			),
			201
		);
	}

	/**
	 * Remove one blocklist entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_blocklist_delete( $request ) {
		FS_Blocklist::remove( (int) $request['id'] );
		return $this->ok( array( 'ok' => true ) );
	}

	/* ===================== Routes: verified customers ===================== */

	/**
	 * Verified credentials (the known-good list).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_verified_list( $request ) {
		$limit  = self::limit_param( $request, 100 );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );

		$creds = FS_Live::verified_creds();
		$rows  = array();
		foreach ( $creds as $key => $rec ) {
			$split = explode( ':', (string) $key, 2 );
			$rows[] = array(
				'type'     => isset( $split[0] ) ? $split[0] : '',
				'value'    => isset( $split[1] ) ? $split[1] : '',
				'label'    => is_array( $rec ) ? (string) ( $rec['label'] ?? '' ) : '',
				'source'   => is_array( $rec ) ? (string) ( $rec['src'] ?? '' ) : '',
				'added_at' => is_array( $rec ) && ! empty( $rec['at'] ) ? mysql2date( 'c', $rec['at'] ) : null,
			);
		}

		return $this->ok(
			array(
				'total'       => count( $rows ),
				'limit'       => $limit,
				'offset'      => $offset,
				'credentials' => array_slice( $rows, $offset, $limit ),
			)
		);
	}

	/**
	 * Verify a credential, or flag an account as verified.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_verified_add( $request ) {
		$body = $this->json_body( $request );

		// Account-level flag: the merchant's master override. One shared path
		// with the admin Verify button (v1.30): the flag, the credential-list
		// mirror, the released Checkout Locks rows, and the lifted hold.
		if ( ! empty( $body['user_id'] ) ) {
			$user_id = (int) $body['user_id'];
			if ( ! FS_Live::verify_account( $user_id ) ) {
				return self::error( 'fs_api_not_found', __( 'No such account.', 'fraud-shield' ), 404 );
			}

			return $this->ok(
				array(
					'ok'      => true,
					'user_id' => $user_id,
					'scope'   => 'account',
				),
				201
			);
		}

		$type  = isset( $body['type'] ) ? sanitize_key( (string) $body['type'] ) : '';
		$value = isset( $body['value'] ) ? (string) $body['value'] : '';
		if ( ! in_array( $type, array( 'email', 'address', 'ip' ), true ) ) {
			return self::error( 'fs_api_bad_type', __( 'type must be one of: email, address, ip.', 'fraud-shield' ), 400 );
		}
		if ( '' === trim( $value ) ) {
			return self::error( 'fs_api_bad_value', __( 'value is required.', 'fraud-shield' ), 400 );
		}

		$label = isset( $body['label'] ) ? sanitize_text_field( (string) $body['label'] ) : '';
		$added = FS_Live::verified_add( $type, $value, $label );

		return $this->ok(
			array(
				'ok'    => true,
				'type'  => $type,
				'added' => (bool) $added,
			),
			201
		);
	}

	/**
	 * Withdraw a verification.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_verified_delete( $request ) {
		$body = $this->json_body( $request );

		if ( ! empty( $body['user_id'] ) ) {
			if ( ! FS_Live::unverify_account( (int) $body['user_id'] ) ) {
				return self::error( 'fs_api_not_found', __( 'No such account.', 'fraud-shield' ), 404 );
			}
			return $this->ok( array( 'ok' => true ) );
		}

		$type  = isset( $body['type'] ) ? sanitize_key( (string) $body['type'] ) : '';
		$value = isset( $body['value'] ) ? (string) $body['value'] : '';
		if ( ! in_array( $type, array( 'email', 'address', 'ip' ), true ) || '' === trim( $value ) ) {
			return self::error( 'fs_api_bad_value', __( 'Provide user_id, or type and value.', 'fraud-shield' ), 400 );
		}

		FS_Live::verified_remove( $type, $value );

		return $this->ok( array( 'ok' => true ) );
	}

	/* ===================== Routes: email allowlist ===================== */

	/**
	 * The email allowlist (v1.30): the guest-friendly release from the velocity
	 * lock and the new-account hold. Never overrides the blocklist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_allowlist( $request ) {
		$limit  = self::limit_param( $request, 100 );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$list   = FS_Live::allowlist();
		return $this->ok(
			array(
				'total'  => count( $list ),
				'limit'  => $limit,
				'offset' => $offset,
				'emails' => array_values( array_slice( $list, $offset, $limit ) ),
			)
		);
	}

	/**
	 * Allowlist an email (v1.30). Also releases the open velocity / hold
	 * Checkout Locks rows for it, as the Allow button does.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_allowlist_add( $request ) {
		$body  = $this->json_body( $request );
		$email = FS_Normalizer::email( isset( $body['email'] ) ? (string) $body['email'] : '' );
		if ( '' === $email || false === strpos( $email, '@' ) ) {
			return self::error( 'fs_api_bad_value', __( 'Provide an email address.', 'fraud-shield' ), 400 );
		}
		$added = FS_Live::allowlist_add( $email );
		FS_Data::resolve_checkout_blocks( 0, $email, array( 'velocity' ) );
		return $this->ok(
			array(
				'ok'         => true,
				'email_norm' => $email,
				'added'      => (bool) $added,
			),
			201
		);
	}

	/**
	 * Remove an email from the allowlist (v1.30).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_allowlist_delete( $request ) {
		$body  = $this->json_body( $request );
		$email = FS_Normalizer::email( isset( $body['email'] ) ? (string) $body['email'] : '' );
		if ( '' === $email ) {
			return self::error( 'fs_api_bad_value', __( 'Provide an email address.', 'fraud-shield' ), 400 );
		}
		$removed = FS_Live::allowlist_remove( $email );
		return $this->ok(
			array(
				'ok'      => true,
				'removed' => (bool) $removed,
			)
		);
	}

	/* ===================== Routes: ID verification ===================== */

	/**
	 * ID verification sessions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_idv_sessions( $request ) {
		$limit  = self::limit_param( $request, 50 );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$result = sanitize_key( (string) $request->get_param( 'result' ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );

		$rows = FS_Data::idv_sessions_list(
			array(
				'search' => $search,
				'result' => $result,
				'limit'  => $limit,
				'offset' => $offset,
			)
		);

		$sessions = array();
		foreach ( (array) $rows as $row ) {
			$row        = (array) $row;
			$sessions[] = array(
				'id'          => (int) ( $row['id'] ?? 0 ),
				'session_id'  => (string) ( $row['session_id'] ?? '' ),
				'user_id'     => (int) ( $row['user_id'] ?? 0 ),
				'email_norm'  => (string) ( $row['email_norm'] ?? '' ),
				'status'      => (string) ( $row['status'] ?? '' ),
				'result'      => (string) ( $row['result'] ?? '' ),
				'name_match'  => (string) ( $row['name_match'] ?? '' ),
				// v1.30: the rest of what the session stores — the name on the
				// document against the name expected, where the document is
				// from, where the customer physically was, and the vendor's
				// risk warnings. Never images, document numbers or birth dates.
				'full_name'     => (string) ( $row['full_name'] ?? '' ),
				'expected_name' => (string) ( $row['expected_name'] ?? '' ),
				'doc_type'    => (string) ( $row['doc_type'] ?? '' ),
				'doc_country' => (string) ( $row['doc_country'] ?? '' ),
				'doc_state'   => (string) ( $row['doc_state'] ?? '' ),
				'verify_country' => (string) ( $row['verify_ip_country'] ?? '' ),
				'verify_state'   => (string) ( $row['verify_ip_state'] ?? '' ),
				'vpn'         => ! empty( $row['verify_vpn'] ),
				'warnings'    => self::shape_idv_warnings( $row['warnings'] ?? '' ),
				'order_id'    => (int) ( $row['order_id'] ?? 0 ),
				'created_at'  => empty( $row['created_at'] ) ? null : mysql2date( 'c', $row['created_at'] ),
				'updated_at'  => empty( $row['updated_at'] ) ? null : mysql2date( 'c', $row['updated_at'] ),
			);
		}

		return $this->ok(
			array(
				'total'    => FS_Data::idv_sessions_count( $search, $result ),
				'limit'    => $limit,
				'offset'   => $offset,
				'sessions' => $sessions,
			)
		);
	}

	/**
	 * Demand an ID check from an account before it may check out again.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_idv_require( $request ) {
		$body    = $this->json_body( $request );
		$user_id = isset( $body['user_id'] ) ? (int) $body['user_id'] : 0;

		if ( $user_id <= 0 && ! empty( $body['email'] ) ) {
			$user    = get_user_by( 'email', sanitize_email( (string) $body['email'] ) );
			$user_id = $user ? (int) $user->ID : 0;
		}
		if ( $user_id <= 0 ) {
			return self::error( 'fs_api_bad_value', __( 'An ID check can only be demanded from a registered account. Provide user_id or a registered email.', 'fraud-shield' ), 400 );
		}
		if ( ! FS_IDV::configured() ) {
			return self::error( 'fs_api_idv_unavailable', __( 'ID verification is not configured on this store.', 'fraud-shield' ), 409 );
		}

		$ok = FS_IDV::require_verification( $user_id );

		return $this->ok(
			array(
				'ok'      => (bool) $ok,
				'user_id' => $user_id,
			)
		);
	}

	/**
	 * Resolve the account behind an IDV write body: user_id, or a registered email.
	 *
	 * @param array $body Decoded body.
	 * @return int 0 when neither resolves.
	 */
	private static function account_from_body( $body ) {
		$user_id = isset( $body['user_id'] ) ? (int) $body['user_id'] : 0;
		if ( $user_id <= 0 && ! empty( $body['email'] ) ) {
			$user    = get_user_by( 'email', sanitize_email( (string) $body['email'] ) );
			$user_id = $user ? (int) $user->ID : 0;
		}
		return $user_id > 0 && get_userdata( $user_id ) ? $user_id : 0;
	}

	/**
	 * Withdraw a demanded ID check (v1.30) — "Cancel request". Also closes
	 * the Checkout Locks rows the demand opened.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_idv_require_clear( $request ) {
		$user_id = self::account_from_body( $this->json_body( $request ) );
		if ( $user_id <= 0 ) {
			return self::error( 'fs_api_bad_value', __( 'Provide user_id or a registered email.', 'fraud-shield' ), 400 );
		}
		$had = null !== FS_IDV::requirement_record( $user_id );
		FS_IDV::clear_requirement( $user_id );
		return $this->ok(
			array(
				'ok'      => true,
				'user_id' => $user_id,
				'cleared' => $had,
			)
		);
	}

	/**
	 * Accounts currently blocked awaiting a demanded ID check (v1.30) — the
	 * "Awaiting a requested ID" queue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_idv_requests( $request ) {
		$limit  = self::limit_param( $request, 50 );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$users  = FS_IDV::pending_requirements( 200 );
		$ids    = array();
		foreach ( $users as $u ) {
			$ids[] = (int) $u->ID;
		}
		$attempts = ! empty( $ids ) ? FS_Data::idv_attempt_counts( $ids ) : array();
		$rows     = array();
		foreach ( array_slice( $users, $offset, $limit ) as $u ) {
			$rec    = FS_IDV::requirement_record( (int) $u->ID );
			$rows[] = array(
				'user_id'      => (int) $u->ID,
				'email_norm'   => FS_Normalizer::email( $u->user_email ),
				'name'         => (string) $u->display_name,
				'requested_at' => is_array( $rec ) && ! empty( $rec['at'] ) ? mysql2date( 'c', $rec['at'] ) : null,
				'requested_by' => is_array( $rec ) ? (int) ( $rec['by'] ?? 0 ) : 0,
				'attempts'     => (int) ( $attempts[ (int) $u->ID ] ?? 0 ),
			);
		}
		return $this->ok(
			array(
				'total'    => count( $users ),
				'limit'    => $limit,
				'offset'   => $offset,
				'requests' => $rows,
			)
		);
	}

	/**
	 * Accept a name mismatch (v1.30): the session's document was genuine, the
	 * name differed, and a human has decided it is the same person. Completes
	 * the verification as a staff decision.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_idv_accept( $request ) {
		$ok = FS_IDV::accept_mismatch( (int) $request['id'] );
		if ( ! $ok ) {
			return self::error( 'fs_api_not_found', __( 'No name-mismatch session with that id.', 'fraud-shield' ), 404 );
		}
		return $this->ok(
			array(
				'ok' => true,
				'id' => (int) $request['id'],
			)
		);
	}

	/**
	 * Revoke an ID verification (v1.30). Withdraws only the credential the
	 * check itself earned; a credential staff added by hand survives.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_idv_revoke( $request ) {
		$body    = $this->json_body( $request );
		$user_id = isset( $body['user_id'] ) ? (int) $body['user_id'] : 0;
		$email   = FS_Normalizer::email( isset( $body['email'] ) ? (string) $body['email'] : '' );
		if ( $user_id <= 0 && '' === $email ) {
			return self::error( 'fs_api_bad_value', __( 'Provide user_id and/or email.', 'fraud-shield' ), 400 );
		}
		if ( $user_id > 0 && '' === $email ) {
			$user  = get_userdata( $user_id );
			$email = $user ? FS_Normalizer::email( $user->user_email ) : '';
		}
		$was = FS_IDV::is_verified( $user_id, $email );
		FS_IDV::revoke( $user_id, $email );
		return $this->ok(
			array(
				'ok'      => true,
				'user_id' => $user_id,
				'email_norm' => $email,
				'revoked' => (bool) $was,
			)
		);
	}

	/* ===================== Routes: insights (v1.30) ===================== */

	/**
	 * The Dashboard's "Fraud prevented" panel: the money that did not change
	 * hands because a control stopped the checkout. Verdicts (blocklist,
	 * linked rule, block-level score) are the headline; money merely held back
	 * pending verification is reported beside it and never added in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_insights_prevented( $request ) {
		$days   = max( 0, min( 3650, (int) $request->get_param( 'days' ) ) );
		$months = max( 1, min( 36, (int) ( $request->get_param( 'months' ) ?: 12 ) ) );
		$stats  = FS_Data::prevented_stats( $days );

		$kinds = array();
		$meta  = FS_Data::prevented_kinds();
		foreach ( FS_Data::prevented_by_kind( $days ) as $kind => $k ) {
			$kinds[] = array(
				'kind'     => (string) $kind,
				'label'    => (string) ( $meta[ $kind ]['label'] ?? $kind ),
				'group'    => (string) ( $meta[ $kind ]['group'] ?? '' ),
				'amount'   => (float) $k['amount'],
				'stopped'  => (int) $k['stopped'],
				'attempts' => (int) $k['attempts'],
			);
		}

		$monthly = array();
		foreach ( FS_Data::prevented_monthly( $months ) as $ym => $m ) {
			$monthly[] = array(
				'month'   => (string) $ym,
				'fraud'   => (float) $m['fraud'],
				'verify'  => (float) $m['verify'],
				'stopped' => (int) $m['stopped'],
			);
		}

		$recent = array();
		foreach ( FS_Data::prevented_recent( self::limit_param( $request, 10 ) ) as $r ) {
			$recent[] = array(
				'kind'       => (string) ( $r['kind'] ?? '' ),
				'group'      => (string) ( $meta[ (string) ( $r['kind'] ?? '' ) ]['group'] ?? '' ),
				'stage'      => (string) ( $r['stage'] ?? '' ),
				'order_id'   => (int) ( $r['order_id'] ?? 0 ),
				'user_id'    => (int) ( $r['user_id'] ?? 0 ),
				'email_norm' => (string) ( $r['email_norm'] ?? '' ),
				'name'       => (string) ( $r['customer_name'] ?? '' ),
				'amount'     => (float) ( $r['amount'] ?? 0 ),
				'currency'   => (string) ( $r['currency'] ?? '' ),
				'attempts'   => (int) ( $r['attempts'] ?? 0 ),
				'stopped_by' => (string) ( $r['stopped_by'] ?? 'rule' ),
				'linked_to'  => self::shape_linked_to( $r['linked_to'] ?? array() ),
				'first_at'   => empty( $r['first_at'] ) ? null : mysql2date( 'c', $r['first_at'] ),
				'last_at'    => empty( $r['last_at'] ) ? null : mysql2date( 'c', $r['last_at'] ),
			);
		}

		return $this->ok(
			array(
				'period'   => array(
					'days'  => $days,
					'label' => 0 === $days ? 'all_time' : 'last_' . $days . '_days',
				),
				'currency' => (string) $stats['currency'],
				'totals'   => self::shape_prevented_stats( $stats ),
				'by_kind'  => $kinds,
				'monthly'  => $monthly,
				'recent'   => $recent,
			)
		);
	}

	/**
	 * The Financial Protection report: four figures kept apart on purpose —
	 * historical losses (observed), blocked transaction value (observed),
	 * estimated losses prevented (estimate) and projected annual protection
	 * (forecast) — with the assumptions and calibration behind each.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_insights_financial( $request ) {
		$days   = max( 0, min( 3650, (int) $request->get_param( 'days' ) ) );
		$rows_n = self::limit_param( $request, 25 );
		$period = FS_Insights::period( $days );
		$a      = FS_Insights::assumptions();

		$losses = FS_Insights::losses( $period );
		$by_cus = FS_Insights::loss_breakdown( $period, $rows_n );
		$bv     = FS_Insights::blocked_value( $period );
		$est    = FS_Insights::prevented_estimate( $period, $bv );
		$proj   = FS_Insights::projection();

		$money = function ( $v ) {
			return null === $v ? null : round( (float) $v, 2 );
		};
		$date  = function ( $v ) {
			return empty( $v ) ? null : mysql2date( 'c', $v );
		};

		$loss_rows = array();
		foreach ( (array) $by_cus as $r ) {
			$loss_rows[] = array(
				'email_norm'  => (string) ( $r['email_norm'] ?? '' ),
				'name'        => (string) ( $r['customer_name'] ?? '' ),
				'user_id'     => (int) ( $r['user_id'] ?? 0 ),
				'chargebacks' => (int) ( $r['cb_orders'] ?? 0 ),
				'gross'       => $money( $r['gross'] ?? 0 ),
				'recovered'   => $money( $r['recovered'] ?? 0 ),
				'first'       => $date( $r['first_cb'] ?? '' ),
				'last'        => $date( $r['last_cb'] ?? '' ),
			);
		}

		$bv_rows = array();
		foreach ( array_slice( (array) $bv['rows'], 0, $rows_n ) as $r ) {
			$bv_rows[] = array(
				'email_norm'       => (string) ( $r['email_norm'] ?? '' ),
				'name'             => (string) ( $r['customer_name'] ?? '' ),
				'user_id'          => (int) ( $r['user_id'] ?? 0 ),
				'value'            => $money( $r['value'] ?? 0 ),
				'events'           => (int) ( $r['events'] ?? 0 ),
				'attempts'         => (int) ( $r['attempts'] ?? 0 ),
				'cancelled'        => (int) ( $r['cancelled_n'] ?? 0 ),
				'refused'          => (int) ( $r['refused_n'] ?? 0 ),
				'later_approved'   => (int) ( $r['approved_n'] ?? 0 ),
				'later_approved_amount' => $money( $r['approved_amount'] ?? 0 ),
				'stopped_by_staff' => (int) ( $r['staff_n'] ?? 0 ),
				'linked_to'        => self::shape_linked_to( $r['linked_to'] ?? array() ),
				'kinds'            => array_values( array_filter( explode( ',', (string) ( $r['kinds'] ?? '' ) ) ) ),
				'last_at'          => $date( $r['last_at'] ?? '' ),
			);
		}

		$est_rows = array();
		foreach ( array_slice( (array) $est['rows'], 0, $rows_n ) as $r ) {
			$est_rows[] = array(
				'email_norm'      => (string) ( $r['email_norm'] ?? '' ),
				'name'            => (string) ( $r['customer_name'] ?? '' ),
				'user_id'         => (int) ( $r['user_id'] ?? 0 ),
				'kinds'           => array_values( array_filter( explode( ',', (string) ( $r['kinds'] ?? '' ) ) ) ),
				'value'           => $money( $r['value'] ?? 0 ),
				'events'          => (int) ( $r['events'] ?? 0 ),
				'attempts'        => (int) ( $r['attempts'] ?? 0 ),
				'stopped_by_staff' => (int) ( $r['staff_n'] ?? 0 ),
				'own_settled'     => (int) ( $r['settled'] ?? 0 ),
				'own_chargebacks' => (int) ( $r['chargebacks'] ?? 0 ),
				// The blocked identities the stop was tied to, and their
				// history; the probability rests on own + tied together.
				'linked_to'        => self::shape_linked_to( $r['linked_to'] ?? array() ),
				'tied_settled'     => (int) ( $r['tied_settled'] ?? 0 ),
				'tied_chargebacks' => (int) ( $r['tied_chargebacks'] ?? 0 ),
				'basis_settled'    => (int) ( $r['basis_settled'] ?? ( $r['settled'] ?? 0 ) ),
				'basis_chargebacks' => (int) ( $r['basis_chargebacks'] ?? ( $r['chargebacks'] ?? 0 ) ),
				'own_rate'        => isset( $r['own_rate'] ) && null !== $r['own_rate'] ? round( (float) $r['own_rate'], 4 ) : null,
				'probability'     => isset( $r['probability'] ) && null !== $r['probability'] ? round( (float) $r['probability'], 4 ) : null,
				'expected'        => $money( $r['expected'] ?? null ),
				'conservative'    => $money( $r['conservative'] ?? 0 ),
				'last_at'         => $date( $r['last_at'] ?? '' ),
			);
		}

		$proj_rows = array();
		foreach ( array_slice( (array) $proj['rows'], 0, $rows_n ) as $r ) {
			$proj_rows[] = array(
				'email_norm'     => (string) ( $r['email_norm'] ?? '' ),
				'name'           => (string) ( $r['customer_name'] ?? '' ),
				'user_id'        => (int) ( $r['user_id'] ?? 0 ),
				'orders'         => (int) ( $r['orders'] ?? 0 ),
				'chargebacks'    => (int) ( $r['cb_orders'] ?? 0 ),
				'gross'          => $money( $r['cb_gross'] ?? 0 ),
				'recovered'      => $money( $r['recovered'] ?? 0 ),
				'net'            => $money( $r['net'] ?? 0 ),
				'first_order'    => $date( $r['first_order'] ?? '' ),
				'last_order'     => $date( $r['last_order'] ?? '' ),
				'span_months'    => round( (float) ( $r['span_months'] ?? 0 ), 2 ),
				'window_months'  => round( (float) ( $r['window_months'] ?? 0 ), 2 ),
				'factor'         => round( (float) ( $r['factor'] ?? 0 ), 3 ),
				'annual'         => $money( $r['annual'] ?? 0 ),
				'fees_annual'    => $money( $r['fees_annual'] ?? null ),
				'confidence'     => (string) ( $r['confidence'] ?? '' ),
			);
		}

		return $this->ok(
			array(
				'period'       => array(
					'days'  => $days,
					'from'  => $date( $period['from'] ),
					'label' => 0 === $days ? 'all_time' : 'last_' . $days . '_days',
				),
				'currency'     => (string) $a['currency'],
				'assumptions'  => array(
					'chargeback_fee'  => $money( $a['fee'] ),
					'loss_ratio'      => (float) $a['loss_ratio'],
					'min_window_days' => (int) $a['min_window_days'],
				),
				'coverage'     => FS_Insights::coverage(),
				'losses'       => array(
					'chargebacks'       => (int) $losses['cb_orders'],
					'identities'        => (int) $losses['identities'],
					'gross'             => $money( $losses['gross'] ),
					'recovered'         => $money( $losses['recovered'] ),
					'recovered_orders'  => (int) $losses['recovered_orders'],
					'fees'              => $money( $losses['fees'] ),
					'net'               => $money( $losses['net'] ),
					'net_includes_fees' => ! empty( $losses['net_has_fees'] ),
					'paid_orders'       => (int) $losses['paid_orders'],
					'paid_amount'       => $money( $losses['paid_amount'] ),
					'rate_by_orders'    => null === $losses['rate_orders'] ? null : round( (float) $losses['rate_orders'], 4 ),
					'rate_by_amount'    => null === $losses['rate_amount'] ? null : round( (float) $losses['rate_amount'], 4 ),
					'refunded_counted_as_chargebacks' => (int) $losses['refunded_as_cb'],
					'counts_refunds'    => ! empty( $losses['counts_refunds'] ),
					'by_customer'       => $loss_rows,
				),
				'blocked_value' => array(
					'value'          => $money( $bv['totals']['value'] ),
					'events'         => (int) $bv['totals']['events'],
					'attempts'       => (int) $bv['totals']['attempts'],
					'identities'     => (int) $bv['totals']['identities'],
					'cancelled'      => (int) $bv['totals']['cancelled_n'],
					'refused'        => (int) $bv['totals']['refused_n'],
					'later_approved' => (int) $bv['totals']['approved_n'],
					'later_approved_amount' => $money( $bv['totals']['approved_amt'] ),
					'stopped_by_staff' => (int) ( $bv['totals']['staff_n'] ?? 0 ),
					'stopped_by_staff_amount' => $money( $bv['totals']['staff_amount'] ?? 0 ),
					'by_kind'        => (array) $bv['totals']['by_kind'],
					'held_pending_verification' => array(
						'amount' => $money( $bv['verify']['amount'] ),
						'events' => (int) $bv['verify']['events'],
					),
					'by_customer'    => $bv_rows,
				),
				'estimate'     => array(
					'blocked_value' => $money( $est['blocked_value'] ),
					'conservative'  => $money( $est['conservative'] ),
					'expected'      => $money( $est['expected'] ),
					'fees_expected' => $money( $est['fees_expected'] ),
					'uncalibrated'  => $money( $est['uncalibrated'] ),
					'covered_value' => $money( $est['covered_value'] ),
					'confidence'    => (string) $est['confidence'],
					'prior'         => $est['prior'],
					'calibration'   => $est['calibration'],
					'loss_ratio'    => (float) $est['loss_ratio'],
					'shrink_orders' => (int) $est['shrink'],
					'by_customer'   => $est_rows,
				),
				'projection'   => array(
					'annual'         => $money( $proj['total'] ),
					'fees_annual'    => $money( $proj['fees_total'] ),
					'trailing_12m'   => $money( $proj['trailing_12m'] ),
					'by_confidence'  => array_map( $money, (array) $proj['by_confidence'] ),
					'identities'     => (int) $proj['identities'],
					'blocked_without_chargebacks' => (int) $proj['no_history'],
					'blocked_named'  => (int) $proj['blocked_named'],
					'min_months'     => (int) $proj['min_months'],
					'loss_ratio'     => (float) $proj['loss_ratio'],
					'by_customer'    => $proj_rows,
				),
			)
		);
	}

	/**
	 * The Customer Network list: every identity ranked by risk — blocked
	 * first, then recorded chargebacks, then the highest score reached.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_network( $request ) {
		$args = array(
			'search'  => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'orderby' => sanitize_key( (string) $request->get_param( 'orderby' ) ),
			'dir'     => 'asc' === strtolower( (string) $request->get_param( 'dir' ) ) ? 'asc' : 'desc',
			'limit'   => self::limit_param( $request, 50 ),
			'offset'  => max( 0, (int) $request->get_param( 'offset' ) ),
		);
		if ( '' === $args['orderby'] ) {
			$args['orderby'] = 'risk';
		}
		$rows = array();
		foreach ( FS_Insights::network_list( $args ) as $r ) {
			$rows[] = self::shape_customer_row( $r );
		}
		return $this->ok(
			array(
				'total'     => FS_Insights::network_count( $args['search'] ),
				'limit'     => $args['limit'],
				'offset'    => $args['offset'],
				'customers' => $rows,
			)
		);
	}

	/**
	 * One customer's network: their credentials, every connected customer
	 * with what matches, the evidence and recency, that customer's standing,
	 * the link's contribution to risk, and any second hop to a blocked
	 * customer. The Customer Network's explore view.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_network_customer( $request ) {
		$who = $this->resolve_ident( (string) $request['ident'] );
		if ( is_wp_error( $who ) ) {
			return $who;
		}
		$x = FS_Insights::explore( (string) $who['email'] );
		if ( ! is_array( $x ) ) {
			return self::error( 'fs_api_not_found', __( 'No such customer.', 'fraud-shield' ), 404 );
		}
		$date = function ( $v ) {
			return empty( $v ) ? null : mysql2date( 'c', $v );
		};

		$creds = array();
		foreach ( (array) $x['credentials'] as $via => $list ) {
			foreach ( (array) $list as $c ) {
				$creds[] = array(
					'via'    => (string) $via,
					'value'  => (string) $c['value'],
					'label'  => (string) $c['label'],
					'orders' => (int) $c['orders'],
					'first'  => $date( $c['first'] ),
					'last'   => $date( $c['last'] ),
				);
			}
		}

		$neighbours = array();
		foreach ( (array) $x['neighbours'] as $n ) {
			$edges = array();
			foreach ( (array) ( $n['edges'] ?? array() ) as $e ) {
				$edges[] = array(
					'via'          => (string) $e['via'],
					'label'        => (string) $e['label'],
					'strength'     => (string) $e['strength'],
					'value'        => (string) $e['value'],
					'display'      => (string) $e['display'],
					'mine_orders'  => (int) $e['mine_orders'],
					'mine_last'    => $date( $e['mine_last'] ),
					'their_orders' => (int) $e['their_orders'],
					'their_first'  => $date( $e['their_first'] ),
					'their_last'   => $date( $e['their_last'] ),
					'their_order_ids' => array_map( 'intval', array_keys( (array) ( $e['order_ids'] ?? array() ) ) ),
				);
			}
			$paths = array();
			foreach ( (array) ( $n['paths'] ?? array() ) as $p ) {
				$paths[] = array(
					'blocked_email' => (string) $p['blocked_email'],
					'blocked_name'  => (string) $p['blocked_name'],
					'via'           => (string) $p['via'],
					'label'         => (string) $p['label'],
					'strength'      => (string) $p['strength'],
					'display'       => (string) $p['display'],
					'last'          => $date( $p['last'] ),
				);
			}
			$neighbours[] = array(
				'email_norm'        => (string) $n['email_norm'],
				'name'              => (string) ( $n['customer_name'] ?? '' ),
				'user_id'           => (int) ( $n['user_id'] ?? 0 ),
				'blocked'           => ! empty( $n['blocked'] ),
				'verified'          => ! empty( $n['verified'] ),
				'orders'            => (int) ( $n['orders'] ?? 0 ),
				'spend'             => round( (float) ( $n['spend'] ?? 0 ), 2 ),
				'chargebacks'       => (int) ( $n['chargebacks'] ?? 0 ),
				'chargeback_amount' => round( (float) ( $n['cb_amount'] ?? 0 ), 2 ),
				'max_risk_score'    => (int) ( $n['max_risk'] ?? 0 ),
				'first_order'       => $date( $n['first_order'] ?? '' ),
				'last_order'        => $date( $n['last_order'] ?? '' ),
				'strongest'         => (string) ( $n['strongest'] ?? '' ),
				'connections'       => $edges,
				'paths_to_blocked'  => $paths,
				'risk'              => array(
					'score'  => round( (float) ( $n['risk_score'] ?? 0 ), 3 ),
					'level'  => (string) ( $n['risk_level'] ?? '' ),
					'reason' => (string) ( $n['risk_reason'] ?? '' ),
				),
			);
		}

		$cards = array();
		foreach ( (array) $x['block_cards'] as $c ) {
			$cards[] = array(
				'entry_id'     => (int) ( $c['id'] ?? 0 ),
				'type'         => (string) ( $c['type'] ?? '' ),
				'value'        => (string) ( $c['value'] ?? '' ),
				'display'      => (string) ( $c['display'] ?? '' ),
				'reason'       => (string) ( $c['reason'] ?? '' ),
				'blocked_at'   => $date( $c['created_at'] ?? '' ),
				'action_id'    => (int) ( $c['action_id'] ?? 0 ),
				'action_kind'  => (string) ( $c['action_kind'] ?? '' ),
				'action_title' => (string) ( $c['action_title'] ?? '' ),
			);
		}

		return $this->ok(
			array(
				'identity'    => array(
					'email_norm' => (string) $x['email'],
					'user_id'    => (int) $x['user_id'],
					'name'       => (string) $x['name'],
				),
				'history'     => self::shape_history( $x['summary'] ),
				'standing'    => array(
					'blocked'     => ! empty( $x['blocked'] ),
					'verified'    => ! empty( $x['verified'] ),
					'id_verified' => ! empty( $x['idv_verified'] ),
					'block_cards' => $cards,
				),
				'credentials' => $creds,
				'connections' => $neighbours,
				'chargebacks' => array_map( array( __CLASS__, 'shape_order' ), (array) $x['chargebacks'] ),
				'orders'      => array_map( array( __CLASS__, 'shape_order' ), (array) $x['orders'] ),
				'orders_capped' => ! empty( $x['rows_capped'] ),
			)
		);
	}

	/**
	 * Fraud rolled up by ship-to geography — the Geography report at one of
	 * its four levels — with whether each place is currently locked.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_geography( $request ) {
		$level   = (string) $request['level'];
		$orderby = sanitize_key( (string) $request->get_param( 'orderby' ) );
		$args    = array(
			'level'      => $level,
			'search'     => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'months'     => max( 0, (int) $request->get_param( 'months' ) ),
			'min_orders' => null !== $request->get_param( 'min_orders' ) ? max( 1, (int) $request->get_param( 'min_orders' ) ) : 5,
			'orderby'    => '' !== $orderby ? $orderby : 'avg_score',
			'order'      => 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC',
			'limit'      => self::limit_param( $request, 50 ),
			'offset'     => max( 0, (int) $request->get_param( 'offset' ) ),
		);

		$rows = (array) FS_Data::geo_rollup( $args );

		// One query for the lock state of the whole page.
		$keys = array();
		foreach ( $rows as $r ) {
			$keys[] = self::place_key( $level, $r );
		}
		$locked = array_fill_keys( FS_Blocklist::blocked_values( 'place', array_filter( $keys ) ), true );

		$out = array();
		foreach ( $rows as $i => $r ) {
			$key   = $keys[ $i ];
			$place = array(
				'country' => (string) ( $r['shipping_country'] ?? '' ),
				'state'   => (string) ( $r['ship_state'] ?? '' ),
			);
			if ( in_array( $level, array( 'city', 'street' ), true ) ) {
				$place['city'] = (string) ( $r['ship_city'] ?? '' );
			}
			if ( 'zip' === $level ) {
				$place['postcode'] = (string) ( $r['ship_postcode'] ?? '' );
			}
			if ( 'street' === $level ) {
				$place['street'] = (string) ( $r['ship_street'] ?? '' );
			}
			$out[] = array(
				'place'             => $place,
				'place_key'         => $key,
				'locked'            => isset( $locked[ $key ] ),
				'orders'            => (int) ( $r['orders'] ?? 0 ),
				'customers'         => (int) ( $r['customers'] ?? 0 ),
				'average_score'     => (float) ( $r['avg_score'] ?? 0 ),
				'high_or_block'     => (int) ( $r['high_ct'] ?? 0 ),
				'chargebacks'       => (int) ( $r['cbs'] ?? 0 ),
				'chargeback_rate'   => (float) ( $r['cb_rate'] ?? 0 ),
				'last_order'        => empty( $r['last_order'] ) ? null : mysql2date( 'c', $r['last_order'] ),
			);
		}

		return $this->ok(
			array(
				'level'  => $level,
				'total'  => (int) FS_Data::geo_rollup_count( $args ),
				'limit'  => $args['limit'],
				'offset' => $args['offset'],
				'places' => $out,
			)
		);
	}

	/**
	 * Everyone shipping to one place (v1.30) — the Geography page's drill-down.
	 * Identify the place with `country` and `state`, plus `city` (city level),
	 * `postcode` (zip level) or `city` and `street` (street level).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_geography_customers( $request ) {
		$level   = (string) $request['level'];
		$country = strtoupper( sanitize_text_field( (string) $request->get_param( 'country' ) ) );
		$state   = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$city    = sanitize_text_field( (string) $request->get_param( 'city' ) );
		$zip     = sanitize_text_field( (string) $request->get_param( 'postcode' ) );
		$street  = sanitize_text_field( (string) $request->get_param( 'street' ) );

		if ( '' === $country || '' === $state ) {
			return self::error( 'fs_api_bad_value', __( 'Provide country and state — the values a /geography row carries under place.', 'fraud-shield' ), 400 );
		}
		switch ( $level ) {
			case 'city':
				$key = $city;
				break;
			case 'zip':
				$key = $zip;
				break;
			case 'street':
				$key = $street;
				break;
			default:
				$key = '';
		}
		if ( 'state' !== $level && '' === $key ) {
			return self::error( 'fs_api_bad_value', __( 'Provide the place: city, postcode, or city and street for the level.', 'fraud-shield' ), 400 );
		}
		if ( 'street' === $level && '' === $city ) {
			return self::error( 'fs_api_bad_value', __( 'A street is identified within a city: provide city as well.', 'fraud-shield' ), 400 );
		}

		$orderby = sanitize_key( (string) $request->get_param( 'orderby' ) );
		$args    = array(
			'orderby' => '' !== $orderby ? $orderby : 'last',
			'dir'     => 'asc' === strtolower( (string) $request->get_param( 'dir' ) ) ? 'asc' : 'desc',
			'search'  => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'limit'   => self::limit_param( $request, 50 ),
			'offset'  => max( 0, (int) $request->get_param( 'offset' ) ),
			'city'    => $city,
		);
		$rows    = array();
		foreach ( (array) FS_Data::geo_customers( $level, $country, $state, $key, $args ) as $r ) {
			$rows[] = self::shape_customer_row( $r );
		}
		$place = array(
			'country' => $country,
			'state'   => FS_Normalizer::region( $state ),
		);
		if ( in_array( $level, array( 'city', 'street' ), true ) ) {
			$place['city'] = FS_Normalizer::city( $city );
		}
		if ( 'zip' === $level ) {
			$place['postcode'] = FS_Normalizer::postcode( $zip );
		}
		if ( 'street' === $level ) {
			$place['street'] = FS_Normalizer::canonicalize( $street );
		}
		return $this->ok(
			array(
				'level'     => $level,
				'place'     => $place,
				'total'     => (int) FS_Data::geo_customers_count( $level, $country, $state, $key, $args['search'], $city ),
				'limit'     => $args['limit'],
				'offset'    => $args['offset'],
				'customers' => $rows,
			)
		);
	}

	/**
	 * The destination-lock key for a geography row, in the form POST /blocklist
	 * accepts as a `place` value.
	 *
	 * @param string $level Rollup level.
	 * @param array  $r     Rollup row.
	 * @return string
	 */
	private static function place_key( $level, $r ) {
		$cc = strtoupper( (string) ( $r['shipping_country'] ?? '' ) );
		$st = (string) ( $r['ship_state'] ?? '' );
		switch ( $level ) {
			case 'city':
				$raw = 'city:' . $cc . '|' . $st . '|' . (string) ( $r['ship_city'] ?? '' );
				break;
			case 'zip':
				$raw = 'zip:' . $cc . '|' . (string) ( $r['ship_postcode'] ?? '' );
				break;
			case 'street':
				$raw = 'street:' . $cc . '|' . $st . '|' . (string) ( $r['ship_city'] ?? '' ) . '|' . (string) ( $r['ship_street'] ?? '' );
				break;
			default:
				$raw = 'state:' . $cc . '|' . $st;
		}
		// Canonicalize exactly as a stored lock is, so the lookup matches.
		return FS_Blocklist::normalize_value( 'place', $raw );
	}

	/* ===================== Routes: events ===================== */

	/**
	 * The event log, as a forward-only cursor.
	 *
	 * Polling this is the supported alternative to receiving webhooks, for a
	 * platform that would rather pull than expose an endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_events( $request ) {
		$after = max( 0, (int) $request->get_param( 'after' ) );
		$limit = self::limit_param( $request, 50 );
		$topic = sanitize_text_field( (string) $request->get_param( 'topic' ) );

		$cursor = $after;
		$events = FS_Webhooks::events( $after, $limit, $topic, $cursor );

		return $this->ok(
			array(
				'events'      => $events,
				'next_cursor' => (int) $cursor,
				'has_more'    => count( $events ) === $limit,
			)
		);
	}

	/* ===================== Routes: webhooks ===================== */

	/**
	 * Subscriptions on this store.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_hooks_list( $request ) {
		$hooks = array();
		foreach ( FS_Webhooks::all() as $row ) {
			$hooks[] = FS_Webhooks::shape( $row );
		}
		return $this->ok(
			array(
				'topics'   => FS_Webhooks::topics(),
				'webhooks' => $hooks,
			)
		);
	}

	/**
	 * Subscribe to events.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_hooks_create( $request ) {
		$body = $this->json_body( $request );

		$sub = FS_Webhooks::subscribe(
			array(
				'url'      => isset( $body['url'] ) ? (string) $body['url'] : '',
				'topics'   => isset( $body['topics'] ) ? (array) $body['topics'] : array(),
				'label'    => isset( $body['label'] ) ? (string) $body['label'] : (string) $this->key['label'],
				'hash_pii' => ! empty( $body['hash_pii'] ),
			)
		);
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}

		return $this->ok(
			array(
				'ok'      => true,
				'id'      => (int) $sub['id'],
				'secret'  => (string) $sub['secret'],
				'topics'  => $sub['topics'],
				'notice'  => __( 'Store this secret now. It cannot be retrieved again.', 'fraud-shield' ),
			),
			201
		);
	}

	/**
	 * Delete a subscription.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function route_hooks_delete( $request ) {
		FS_Webhooks::delete( (int) $request['id'] );
		return $this->ok( array( 'ok' => true ) );
	}

	/**
	 * Send a test event to one subscription.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_hooks_test( $request ) {
		$result = FS_Webhooks::send_test( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/* ===================== Helpers ===================== */

	/**
	 * Decode a JSON request body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function json_body( $request ) {
		$params = $request->get_json_params();
		if ( is_array( $params ) ) {
			return $params;
		}
		// Tolerate form encoding: some HTTP clients default to it, and refusing
		// on content type alone is a support ticket, not a security control.
		$params = $request->get_body_params();
		return is_array( $params ) ? $params : array();
	}

	/**
	 * A bounded limit parameter. Every list endpoint is capped, so a single
	 * call can never ask the database for an unbounded result set.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $default Default when absent.
	 * @return int
	 */
	private static function limit_param( $request, $default ) {
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = $default;
		}
		return max( 1, min( 100, $limit ) );
	}
}
