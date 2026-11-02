<?php
/**
 * API credentials: minting, verification, scoping, and the one-time pairing
 * codes that let an external platform (bonesfraud.com) complete a handshake
 * without anyone pasting a long-lived secret into a config file.
 *
 * Two rules shape everything here.
 *
 * The first is that a stolen database must not yield working keys. The secret
 * half of a key is shown exactly once, at creation, and only its SHA-256 digest
 * is stored — the same reason a password is never kept in plaintext. A 192-bit
 * random secret needs no salt or key-stretching (there is nothing to guess:
 * brute force is the search space itself), so verification stays a single
 * indexed lookup plus one constant-time compare, which is what makes this
 * affordable on every request.
 *
 * The second is least privilege. A key carries an explicit scope list, an
 * optional IP allow-list, an optional expiry, and its own rate limit, so the
 * credential handed to a reporting dashboard cannot write to the blocklist and
 * the credential handed to a partner platform cannot be replayed from another
 * network.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_API_Keys {

	/**
	 * Public prefix on every token. Makes a leaked key greppable in logs and
	 * lets us reject obvious junk before touching the database.
	 */
	const TOKEN_PREFIX = 'fsk_';

	/**
	 * Prefix on a pairing code, for the same reason.
	 */
	const PAIR_PREFIX = 'fspc_';

	/**
	 * Where a pending pairing code lives (hashed, single use, short lived).
	 */
	const PAIRING_OPTION = 'fs_api_pairing';

	/**
	 * Rolling security log: authentication failures and credential lifecycle.
	 */
	const AUDIT_OPTION = 'fs_api_audit';

	/**
	 * Audit rows kept. Small on purpose — this is a tripwire, not an archive.
	 */
	const AUDIT_CAP = 300;

	/**
	 * Wrong-code attempts allowed against one pairing code before it burns.
	 */
	const PAIR_MAX_ATTEMPTS = 5;

	/**
	 * Per-request cache of rows already looked up, keyed by key id. A signed
	 * request resolves its key in the permission callback and again in the
	 * handler; there is no reason to pay for that lookup twice.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Table holding the credentials.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'fs_api_keys';
	}

	/* ===================== Scopes ===================== */

	/**
	 * Every grantable scope, with the human description shown in the admin and
	 * in the documentation. Read and write are separated deliberately: most
	 * integrations only ever need to ask questions.
	 *
	 * @return array [ scope => label ].
	 */
	public static function scopes() {
		return array(
			'read:risk'        => __( 'Score orders and ad-hoc identities, read aggregate stats', 'fraud-shield' ),
			'read:customers'   => __( 'Read customer risk profiles and verification state', 'fraud-shield' ),
			'read:orders'      => __( 'Read indexed orders and their risk verdicts', 'fraud-shield' ),
			'read:events'      => __( 'Read the event log (webhook alternative: polling)', 'fraud-shield' ),
			'read:blocklist'   => __( 'Read blocklist entries', 'fraud-shield' ),
			'read:verified'    => __( 'Read verified customers and credentials', 'fraud-shield' ),
			'read:idv'         => __( 'Read ID verification sessions and open ID demands', 'fraud-shield' ),
			'read:insights'    => __( 'Read the Financial Protection figures, the fraud-prevented ledger, geography rollups and the customer network', 'fraud-shield' ),
			'write:blocklist'  => __( 'Add and remove blocklist entries and block cards (includes reading them)', 'fraud-shield' ),
			'write:verified'   => __( 'Add and remove verified customers, credentials and allowlisted emails (includes reading them)', 'fraud-shield' ),
			'write:idv'        => __( 'Demand, withdraw, accept and revoke ID verifications (includes reading sessions)', 'fraud-shield' ),
			'write:orders'     => __( 'Mark orders charged back, record recoveries and run card checks (includes reading orders)', 'fraud-shield' ),
			'write:customers'  => __( 'Apply and release gateway restrictions and holds, dismiss checkout refusals (includes reading customers)', 'fraud-shield' ),
			'manage:webhooks'  => __( 'Create, inspect and delete webhook subscriptions', 'fraud-shield' ),
		);
	}

	/**
	 * Keep only scopes we actually recognize. An unknown scope is dropped
	 * rather than stored, so a typo can never widen access by accident and a
	 * scope removed in a later version stops granting anything on upgrade.
	 *
	 * @param array|string $scopes Scope list or comma-separated string.
	 * @return array
	 */
	public static function sanitize_scopes( $scopes ) {
		if ( is_string( $scopes ) ) {
			$scopes = preg_split( '/[\s,]+/', $scopes );
		}
		$known = self::scopes();
		$out   = array();
		foreach ( (array) $scopes as $scope ) {
			$scope = strtolower( trim( (string) $scope ) );
			if ( isset( $known[ $scope ] ) && ! in_array( $scope, $out, true ) ) {
				$out[] = $scope;
			}
		}
		return $out;
	}

	/**
	 * Does this key carry the scope a route demands?
	 *
	 * A route may name several acceptable scopes, in which case any one of them
	 * is enough. That is how a read endpoint accepts either its own read scope or
	 * the matching write scope: a key that may change the blocklist can obviously
	 * look at it, and forcing the write scope on a read-only integration would
	 * have handed it more privilege than it asked for.
	 *
	 * @param array        $row   Key row.
	 * @param string|array $scope Required scope, or list of acceptable scopes
	 *                            ('' / empty = any valid key will do).
	 * @return bool
	 */
	public static function has_scope( $row, $scope ) {
		$needed = array_filter( array_map( 'strval', is_array( $scope ) ? $scope : array( $scope ) ) );
		if ( empty( $needed ) ) {
			return true;
		}
		$granted = preg_split( '/,/', (string) ( $row['scopes'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $needed as $one ) {
			if ( in_array( $one, (array) $granted, true ) ) {
				return true;
			}
		}
		return false;
	}

	/* ===================== Minting ===================== */

	/**
	 * Create a credential and return the ONE copy of its secret.
	 *
	 * @param array $args {
	 *     @type string $label       Human name shown in the admin.
	 *     @type array  $scopes      Granted scopes.
	 *     @type bool   $require_sig Force HMAC-signed requests (reject bearer).
	 *     @type string $ip_allow    Newline/comma separated IPs or CIDRs ('' = any).
	 *     @type int    $rate_limit  Requests per minute (0 = use the global default).
	 *     @type int    $expires_in  Seconds until expiry (0 = never).
	 *     @type string $origin      'admin' | 'handshake'.
	 * }
	 * @return array|WP_Error { token, key_id, id } — token is unrecoverable afterwards.
	 */
	public static function mint( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'label'       => '',
				'scopes'      => array(),
				'require_sig' => false,
				'ip_allow'    => '',
				'rate_limit'  => 0,
				'expires_in'  => 0,
				'origin'      => 'admin',
			)
		);

		$scopes = self::sanitize_scopes( $args['scopes'] );
		if ( empty( $scopes ) ) {
			return new WP_Error( 'fs_api_no_scopes', __( 'A key must grant at least one scope.', 'fraud-shield' ) );
		}

		try {
			$key_id = bin2hex( random_bytes( 8 ) );  // 16 hex chars, the public half.
			$secret = bin2hex( random_bytes( 24 ) ); // 48 hex chars, 192 bits of entropy.
		} catch ( Exception $e ) {
			// No CSPRNG means no credentials. Refusing is the only safe answer.
			return new WP_Error( 'fs_api_no_entropy', __( 'This server could not generate a secure random secret.', 'fraud-shield' ) );
		}

		$expires = (int) $args['expires_in'] > 0 ? gmdate( 'Y-m-d H:i:s', time() + (int) $args['expires_in'] ) : null;

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'key_id'      => $key_id,
				'secret_hash' => hash( 'sha256', $secret ),
				'label'       => mb_substr( sanitize_text_field( (string) $args['label'] ), 0, 191 ),
				'scopes'      => implode( ',', $scopes ),
				'ip_allow'    => self::sanitize_ip_allow( $args['ip_allow'] ),
				'require_sig' => ! empty( $args['require_sig'] ) ? 1 : 0,
				'rate_limit'  => max( 0, (int) $args['rate_limit'] ),
				'status'      => 'active',
				'origin'      => 'handshake' === $args['origin'] ? 'handshake' : 'admin',
				'created_by'  => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
				'expires_at'  => $expires,
			)
		);

		if ( ! $ok ) {
			return new WP_Error( 'fs_api_store_failed', __( 'The key could not be stored.', 'fraud-shield' ) );
		}

		// Before the audit write and the counter refresh: both can INSERT into
		// wp_options (a first-time add_option), and wpdb refreshes insert_id on
		// any INSERT at all.
		$id = (int) $wpdb->insert_id;

		self::audit( 'key.created', $key_id, sprintf( '%s (%s)', $args['label'], implode( ' ', $scopes ) ) );
		FS_REST_API::refresh_counts();

		return array(
			'token'  => self::TOKEN_PREFIX . $key_id . '_' . $secret,
			'key_id' => $key_id,
			'id'     => $id,
		);
	}

	/* ===================== Verification ===================== */

	/**
	 * Resolve a presented token to its stored row.
	 *
	 * Every failure returns null with no explanation. Telling a caller whether
	 * the key id exists, the secret is wrong, or the key was revoked is free
	 * reconnaissance; the audit log records the distinction for staff instead.
	 *
	 * @param string $token Presented token.
	 * @return array|null
	 */
	public static function verify_token( $token ) {
		$parsed = self::parse_token( $token );
		if ( null === $parsed ) {
			return null;
		}

		$row = self::by_key_id( $parsed['key_id'] );
		if ( ! $row ) {
			return null;
		}

		// Constant time: a timing-visible compare on a secret digest is a
		// byte-at-a-time oracle for anyone patient enough to measure it.
		if ( ! hash_equals( (string) $row['secret_hash'], hash( 'sha256', $parsed['secret'] ) ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Split a token into its public id and secret halves without touching the
	 * database. Hex-only on both halves, so a malformed token is rejected
	 * before it can become a query.
	 *
	 * @param string $token Presented token.
	 * @return array|null { key_id, secret }
	 */
	public static function parse_token( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token || 0 !== strpos( $token, self::TOKEN_PREFIX ) ) {
			return null;
		}
		$parts = explode( '_', substr( $token, strlen( self::TOKEN_PREFIX ) ) );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		list( $key_id, $secret ) = $parts;
		if ( 16 !== strlen( $key_id ) || 48 !== strlen( $secret ) || ! ctype_xdigit( $key_id ) || ! ctype_xdigit( $secret ) ) {
			return null;
		}
		return array(
			'key_id' => $key_id,
			'secret' => $secret,
		);
	}

	/**
	 * Fetch by the public key id (unique, indexed).
	 *
	 * @param string $key_id Key id.
	 * @return array|null
	 */
	public static function by_key_id( $key_id ) {
		global $wpdb;

		$key_id = (string) $key_id;
		if ( isset( self::$cache[ $key_id ] ) ) {
			return self::$cache[ $key_id ];
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE key_id = %s', $key_id ),
			ARRAY_A
		);

		self::$cache[ $key_id ] = $row ? $row : null;
		return self::$cache[ $key_id ];
	}

	/**
	 * Why this otherwise-authentic key may not be used right now.
	 *
	 * @param array $row Key row.
	 * @return string '' when usable, else 'revoked' | 'expired'.
	 */
	public static function unusable_reason( $row ) {
		if ( 'active' !== (string) ( $row['status'] ?? '' ) ) {
			return 'revoked';
		}
		$expires = (string) ( $row['expires_at'] ?? '' );
		if ( '' !== $expires && '0000-00-00 00:00:00' !== $expires && strtotime( $expires . ' UTC' ) < time() ) {
			return 'expired';
		}
		return '';
	}

	/* ===================== IP allow-list ===================== */

	/**
	 * Normalize an allow-list into one entry per line.
	 *
	 * @param string $raw Raw textarea contents.
	 * @return string
	 */
	public static function sanitize_ip_allow( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $entry ) {
			$entry = trim( $entry );
			// An entry is an IP or an IP/prefix; anything else is dropped so a
			// stray word can never widen the list into a wildcard.
			$bare = false !== strpos( $entry, '/' ) ? substr( $entry, 0, strpos( $entry, '/' ) ) : $entry;
			if ( filter_var( $bare, FILTER_VALIDATE_IP ) && count( $out ) < 50 ) {
				$out[] = $entry;
			}
		}
		return implode( "\n", array_unique( $out ) );
	}

	/**
	 * Is the caller's address allowed to use this key?
	 *
	 * An empty list means "any address". A non-empty list that the caller
	 * fails to match is a refusal — fail closed, because the whole point of
	 * pinning a key to a network is that a copy taken elsewhere is useless.
	 *
	 * @param array  $row Key row.
	 * @param string $ip  Caller address.
	 * @return bool
	 */
	public static function ip_allowed( $row, $ip ) {
		$list = trim( (string) ( $row['ip_allow'] ?? '' ) );
		if ( '' === $list ) {
			return true;
		}
		$ip = trim( (string) $ip );
		if ( '' === $ip ) {
			return false;
		}
		foreach ( preg_split( '/\s+/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $entry ) {
			if ( self::ip_matches( $ip, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match one address against one allow-list entry (exact, or IPv4/IPv6 CIDR).
	 *
	 * @param string $ip    Caller address.
	 * @param string $entry Allow-list entry.
	 * @return bool
	 */
	public static function ip_matches( $ip, $entry ) {
		$entry = trim( (string) $entry );
		if ( '' === $entry ) {
			return false;
		}
		if ( false === strpos( $entry, '/' ) ) {
			// Compare packed forms, not strings: 192.168.001.001 and ::1 are the
			// same addresses as 192.168.1.1 and 0:0:0:0:0:0:0:1, and a pinned key
			// that refuses its own allow-listed address is a support ticket.
			$a = @inet_pton( $entry ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input is a non-match.
			$b = @inet_pton( $ip );    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false !== $a && false !== $b && hash_equals( $a, $b );
		}

		list( $subnet, $bits ) = explode( '/', $entry, 2 );
		$bits                  = (int) $bits;
		$packed_ip             = @inet_pton( $ip );     // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input is a non-match, not a warning.
		$packed_subnet         = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed_ip || false === $packed_subnet || strlen( $packed_ip ) !== strlen( $packed_subnet ) ) {
			return false;
		}
		$max = strlen( $packed_ip ) * 8;
		if ( $bits < 0 || $bits > $max ) {
			return false;
		}

		// Compare whole bytes, then the partial byte through a mask.
		$whole = intdiv( $bits, 8 );
		if ( $whole > 0 && 0 !== substr_compare( $packed_ip, substr( $packed_subnet, 0, $whole ), 0, $whole ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ~( ( 1 << ( 8 - $rest ) ) - 1 ) & 0xFF;
		return ( ord( $packed_ip[ $whole ] ) & $mask ) === ( ord( $packed_subnet[ $whole ] ) & $mask );
	}

	/* ===================== Usage accounting ===================== */

	/**
	 * Record that a key was used — at most once a minute per key.
	 *
	 * A write on every authenticated request would turn a read-only API into a
	 * write-amplifying one and put the options/keys table on the hot path of
	 * every call. Throttling keeps "last used" honest to the minute, which is
	 * all anyone reads it for, at a fraction of the cost.
	 *
	 * @param array  $row Key row.
	 * @param string $ip  Caller address.
	 */
	public static function touch( $row, $ip ) {
		global $wpdb;

		$key_id = (string) ( $row['key_id'] ?? '' );
		if ( '' === $key_id ) {
			return;
		}
		$guard = 'fs_api_touch_' . $key_id;
		if ( false !== get_transient( $guard ) ) {
			return;
		}
		set_transient( $guard, 1, MINUTE_IN_SECONDS );

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET last_used_at = %s, last_ip = %s, use_count = use_count + 1 WHERE key_id = %s',
				current_time( 'mysql' ),
				mb_substr( (string) $ip, 0, 64 ),
				$key_id
			)
		);
	}

	/* ===================== Listing / lifecycle ===================== */

	/**
	 * All credentials, newest first. Secret digests are never returned.
	 *
	 * @param int $limit  Max rows.
	 * @param int $offset Offset.
	 * @return array[]
	 */
	public static function all( $limit = 100, $offset = 0 ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT id, key_id, label, scopes, ip_allow, require_sig, rate_limit, status, origin, created_by, created_at, expires_at, last_used_at, last_ip, use_count FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d OFFSET %d',
				max( 1, min( 500, (int) $limit ) ),
				max( 0, (int) $offset )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many credentials exist, by status.
	 *
	 * @return array { total:int, active:int }
	 */
	public static function counts() {
		global $wpdb;
		$t = self::table();
		return array(
			'total'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ), // phpcs:ignore WordPress.DB
			'active' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'active'" ), // phpcs:ignore WordPress.DB
		);
	}

	/**
	 * Revoke a key: it stays listed (so the audit trail keeps its name) but
	 * stops authenticating immediately.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function revoke( $id ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return false;
		}
		$wpdb->update( self::table(), array( 'status' => 'revoked' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
		self::$cache = array();
		self::audit( 'key.revoked', (string) $row['key_id'], (string) $row['label'] );
		FS_REST_API::refresh_counts();
		return true;
	}

	/**
	 * Delete a key row outright.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return false;
		}
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
		self::$cache = array();
		self::audit( 'key.deleted', (string) $row['key_id'], (string) $row['label'] );
		FS_REST_API::refresh_counts();
		return true;
	}

	/**
	 * One key by row id.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/* ===================== Pairing / handshake ===================== */

	/**
	 * Open a pairing window: a short-lived, single-use code that an external
	 * platform can exchange for a real credential over the handshake endpoint.
	 *
	 * This exists so nobody has to move a permanent secret by hand. The code is
	 * worthless minutes later, worthless a second time, and worthless without
	 * the scopes an admin picked when creating it — so the worst case for a
	 * leaked code is a stranger claiming a pairing the admin can see and revoke,
	 * rather than a standing key nobody notices.
	 *
	 * @param array $args {
	 *     @type array  $scopes      Scopes the resulting key will carry.
	 *     @type string $label       Label for the resulting key.
	 *     @type int    $ttl         Seconds the code stays valid.
	 *     @type bool   $require_sig Resulting key must use signed requests.
	 *     @type bool   $with_webhook Also create a webhook subscription from the
	 *                               callback_url the platform presents.
	 * }
	 * @return string|WP_Error The plaintext code (shown once).
	 */
	public static function create_pairing_code( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'scopes'       => array(),
				'label'        => __( 'Paired platform', 'fraud-shield' ),
				'ttl'          => 15 * MINUTE_IN_SECONDS,
				'require_sig'  => true,
				'with_webhook' => true,
			)
		);

		$scopes = self::sanitize_scopes( $args['scopes'] );
		if ( empty( $scopes ) ) {
			return new WP_Error( 'fs_api_no_scopes', __( 'Choose at least one scope for the paired platform.', 'fraud-shield' ) );
		}

		try {
			$code = self::PAIR_PREFIX . bin2hex( random_bytes( 20 ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'fs_api_no_entropy', __( 'This server could not generate a secure random code.', 'fraud-shield' ) );
		}

		$ttl = max( MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, (int) $args['ttl'] ) );

		update_option(
			self::PAIRING_OPTION,
			array(
				'hash'         => hash( 'sha256', $code ),
				'scopes'       => $scopes,
				'label'        => mb_substr( sanitize_text_field( (string) $args['label'] ), 0, 191 ),
				'require_sig'  => ! empty( $args['require_sig'] ),
				'with_webhook' => ! empty( $args['with_webhook'] ),
				'expires'      => time() + $ttl,
				'attempts'     => 0,
				'created_by'   => get_current_user_id(),
				'created'      => time(),
			),
			false
		);

		self::audit( 'pairing.opened', '', implode( ' ', $scopes ) );
		return $code;
	}

	/**
	 * The pending pairing window, if any is still open.
	 *
	 * @return array|null Never includes the code itself, only its digest.
	 */
	public static function pairing_pending() {
		$pending = get_option( self::PAIRING_OPTION, array() );
		if ( ! is_array( $pending ) || empty( $pending['hash'] ) ) {
			return null;
		}
		if ( (int) ( $pending['expires'] ?? 0 ) < time() ) {
			return null;
		}
		return $pending;
	}

	/**
	 * Close the pairing window.
	 */
	public static function clear_pairing() {
		delete_option( self::PAIRING_OPTION );
	}

	/**
	 * Redeem a pairing code, burning it in the process.
	 *
	 * @param string $code Presented code.
	 * @return array|WP_Error The stored pairing terms.
	 */
	public static function consume_pairing_code( $code ) {
		$pending = self::pairing_pending();
		if ( null === $pending ) {
			return new WP_Error( 'fs_api_no_pairing', __( 'No pairing is open on this store.', 'fraud-shield' ), array( 'status' => 403 ) );
		}

		$code = trim( (string) $code );
		if ( ! hash_equals( (string) $pending['hash'], hash( 'sha256', $code ) ) ) {
			// Burn the window after a handful of wrong guesses. The code is far
			// too large to brute force, but a window that closes on repeated
			// failure also closes on a stranger probing it.
			$pending['attempts'] = (int) ( $pending['attempts'] ?? 0 ) + 1;
			if ( $pending['attempts'] >= self::PAIR_MAX_ATTEMPTS ) {
				self::clear_pairing();
				self::audit( 'pairing.burned', '', 'too many failed attempts' );
			} else {
				update_option( self::PAIRING_OPTION, $pending, false );
			}
			return new WP_Error( 'fs_api_bad_pairing', __( 'That pairing code is not valid.', 'fraud-shield' ), array( 'status' => 403 ) );
		}

		// Single use: the window closes the instant the right code arrives, so a
		// replayed handshake cannot mint a second credential.
		self::clear_pairing();
		return $pending;
	}

	/* ===================== Audit ===================== */

	/**
	 * Append to the rolling security log.
	 *
	 * Deliberately an option and deliberately capped: auth failures should be
	 * rare, and an unbounded log is its own denial-of-service. Nothing secret
	 * is written here — never a token, never a code, only its public id.
	 *
	 * @param string $event  Event key, e.g. 'auth.bad_signature'.
	 * @param string $key_id Public key id ('' when unknown).
	 * @param string $detail Short human detail.
	 */
	public static function audit( $event, $key_id = '', $detail = '' ) {
		$log = get_option( self::AUDIT_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				't'      => time(),
				'event'  => mb_substr( (string) $event, 0, 40 ),
				'key'    => mb_substr( (string) $key_id, 0, 32 ),
				'detail' => mb_substr( (string) $detail, 0, 191 ),
				'ip'     => mb_substr( FS_REST_API::client_ip(), 0, 64 ),
			)
		);
		if ( count( $log ) > self::AUDIT_CAP ) {
			$log = array_slice( $log, 0, self::AUDIT_CAP );
		}
		update_option( self::AUDIT_OPTION, $log, false );
	}

	/**
	 * Append to the log, but at most once a minute for the same event from the
	 * same address.
	 *
	 * Every authentication failure is caller-triggered, and a stranger can
	 * produce a well-formed-but-wrong token all day. Writing a row for each one
	 * would rewrite a 300-entry option on every request — an options-table write
	 * storm anyone can drive — and, worse, would evict the entire log in a
	 * fraction of a second, destroying exactly the signal it exists to preserve.
	 * Coalescing keeps the tripwire useful: a burst leaves one row per minute,
	 * and the rows it would have buried survive.
	 *
	 * @param string $event  Event key.
	 * @param string $key_id Public key id ('' when unknown).
	 * @param string $detail Short human detail.
	 */
	public static function audit_throttled( $event, $key_id = '', $detail = '' ) {
		$guard = 'fs_api_aud_' . hash( 'sha256', $event . '|' . $key_id . '|' . FS_REST_API::client_ip() );
		if ( false !== get_transient( $guard ) ) {
			return;
		}
		set_transient( $guard, 1, MINUTE_IN_SECONDS );
		self::audit( $event, $key_id, $detail );
	}

	/**
	 * The security log, newest first.
	 *
	 * @return array[]
	 */
	public static function audit_rows() {
		$log = get_option( self::AUDIT_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}
}
