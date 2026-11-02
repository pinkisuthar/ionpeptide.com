<?php
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every query here is passed through wpdb->prepare(); the only interpolated token is this package own table name and the dynamic WHERE clause is built solely from literal, bound %s placeholders. No user value ever reaches the SQL string unbound.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- every query in this file binds its values through wpdb->prepare(); the parameter the sniff flags is the table prefix or a placeholder string, both of which are safe.
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- this library owns the 'zorem-mcp' domain and loads it itself; Plugin Check compares against the host plugin's folder name, which is a different plugin every time this folder ships.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- this package is shared across the Zorem plugin family; its own zorem_mcp_ prefix is the point.
/**
 * Zorem MCP - the connections a merchant's people have made.
 *
 * WHY THIS EXISTS
 *
 * The connection link carries its own token, which is what lets it work from claude.ai and
 * from a phone. That token is one secret, and a store is often more than one person: the
 * owner connects Claude, a colleague connects Claude, somebody tries ChatGPT. All three
 * send the same secret, so the server cannot tell them apart -- and what cannot be told
 * apart cannot be revoked apart. Cutting one means changing the secret, which cuts everyone.
 *
 * This table is the answer. Each person who connects gets a token of their own, issued to
 * them by name when they sign in, and stored here as a hash. Revoking one row touches
 * nothing else: the link is unchanged, and everybody else keeps working without knowing
 * anything happened.
 *
 * WHAT IS STORED
 *
 * Never the token. A SHA-256 of it, which is enough to recognise a caller and useless to
 * anyone who reads the table, plus the first few characters so a merchant can tell one row
 * from another on screen. The token itself exists only in the AI client that received it.
 *
 * @package Zorem_MCP
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorem_MCP_Keys {

	/**
	 * Schema version, so an upgrade knows to run dbDelta again.
	 */
	const DB_VERSION = '3';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_OPTION = 'zorem_mcp_keys_db_version';

	/**
	 * Prefix every issued token carries. Recognisable in a log, and a merchant who pastes
	 * the wrong string somewhere can see at a glance that it was one of ours.
	 */
	const TOKEN_PREFIX = 'zmcp_';

	/**
	 * Prefix on every refresh token, so the two can never be confused in a log.
	 */
	const REFRESH_PREFIX = 'zmcp_r_';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'wp_ajax_zorem_mcp_revoke', array( __CLASS__, 'ajax_revoke' ) );
		add_action( 'wp_ajax_zorem_mcp_revoke_all', array( __CLASS__, 'ajax_revoke_all' ) );
		add_action( 'wp_ajax_zorem_mcp_reset_client', array( __CLASS__, 'ajax_reset_client' ) );
	}

	/**
	 * Turn one connection off, from the connections screen.
	 *
	 * Guarded by manage_woocommerce rather than ownership: this is the shop owner's list of
	 * who can reach their store, and the whole point of it is being able to cut somebody
	 * else off. A person can only ever have their own connection ended by someone who runs
	 * the store, which is the right way round.
	 *
	 * @return void
	 */
	public static function ajax_revoke() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'zorem-mcp' ) ), 403 );
		}

		check_ajax_referer( Zorem_MCP_Woo_Key::NONCE, Zorem_MCP_Woo_Key::NONCE . '_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified immediately above.
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		if ( ! $id || ! self::revoke( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'That connection could not be turned off. Reload the page and try again.', 'zorem-mcp' ) ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Turned off. Everyone else is still connected.', 'zorem-mcp' ),
				'live'    => self::live_count(),
			)
		);
	}

	/**
	 * Turn off the live connections belonging to one app.
	 *
	 * WHY BY NAME AND NOT BY ID
	 *
	 * Because the merchant is not looking at the connections list when they need this. They
	 * are looking at the row for VS Code, having pressed Add twice with nothing to show for
	 * it, and what they want is "make this one start over" -- not "go and work out which of
	 * these rows is the one VS Code made".
	 *
	 * The name is the one the app gave when it registered, which is also what the list
	 * displays, so the two always agree.
	 *
	 * @param string[] $needles Fragments of the app's name, any of which counts as a match.
	 * @return int How many were turned off.
	 */
	public static function revoke_by_client( $needles ) {
		global $wpdb;

		$needles = array_filter( array_map( 'strval', (array) $needles ) );

		if ( empty( $needles ) || ! self::available() ) {
			return 0;
		}

		$table  = self::table();
		$where  = array();
		$values = array( current_time( 'mysql', 1 ) );

		foreach ( $needles as $needle ) {
			$where[]  = 'client LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is this package's own; every value is bound.
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET revoked_at = %s WHERE revoked_at IS NULL AND ( " . implode( ' OR ', $where ) . ' )',
				$values
			)
		);

		return max( 0, (int) $done );
	}

	/**
	 * Turn off one app's connections, from the button beside it.
	 *
	 * @return void
	 */
	public static function ajax_reset_client() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'zorem-mcp' ) ), 403 );
		}

		check_ajax_referer( Zorem_MCP_Woo_Key::NONCE, Zorem_MCP_Woo_Key::NONCE . '_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified immediately above.
		$raw = isset( $_POST['match'] ) ? sanitize_text_field( wp_unslash( $_POST['match'] ) ) : '';

		$needles = array_filter( array_map( 'sanitize_text_field', explode( '|', (string) $raw ) ) );
		$done    = self::revoke_by_client( $needles );

		wp_send_json_success(
			array(
				'message' => $done
					? __( 'Turned off. The app will ask you to sign in again.', 'zorem-mcp' )
					: __( 'There was nothing to turn off — the app will be asked to sign in anyway.', 'zorem-mcp' ),
				'live'    => self::live_count(),
			)
		);
	}

	/**
	 * Turn every live connection off in one go.
	 *
	 * WHY A MERCHANT NEEDS THIS
	 *
	 * Because an AI app keeps its token, and nothing the merchant does inside that app makes
	 * it forget. Remove the connection in VS Code and add it straight back and the app
	 * quietly reuses the token it already has: no 401, no sign-in, no Allow screen. To the
	 * merchant it looks as though the connection cannot be redone.
	 *
	 * The token is ours, though, and the moment we stop honouring it every app is forced to
	 * sign in again. That is the whole of this method, and it is the only lever that works
	 * from this side of the connection.
	 *
	 * The link itself is untouched. Nobody has to be given a new address; they simply have
	 * to prove who they are once more.
	 *
	 * @return int How many were turned off.
	 */
	public static function revoke_all() {
		global $wpdb;

		if ( ! self::available() ) {
			return 0;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is this package's own; the value is bound.
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET revoked_at = %s WHERE revoked_at IS NULL",
				current_time( 'mysql', 1 )
			)
		);

		return max( 0, (int) $done );
	}

	/**
	 * Turn every connection off, from the connections screen.
	 *
	 * @return void
	 */
	public static function ajax_revoke_all() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'zorem-mcp' ) ), 403 );
		}

		check_ajax_referer( Zorem_MCP_Woo_Key::NONCE, Zorem_MCP_Woo_Key::NONCE . '_nonce' );

		$done = self::revoke_all();

		wp_send_json_success(
			array(
				'message' => $done
					? sprintf(
						/* translators: %d: how many connections were turned off. */
						_n(
							'%d connection turned off. It will ask to sign in again next time it is used.',
							'%d connections turned off. Each will ask to sign in again next time it is used.',
							$done,
							'zorem-mcp'
						),
						$done
					)
					: __( 'There was nothing to turn off.', 'zorem-mcp' ),
				'live'    => self::live_count(),
				'table'   => self::connections_html(),
			)
		);
	}

	/**
	 * The connections table as markup, so the reply can replace it without a page load.
	 *
	 * Every row's status changes at once here, which is why this returns the whole table
	 * rather than a patch — but redrawing it is still not a reason to reload the screen and
	 * throw away everything else the merchant has open on it.
	 *
	 * @return string
	 */
	protected static function connections_html() {
		if ( ! function_exists( 'zorem_mcp_render_connections' ) ) {
			return '';
		}

		ob_start();
		zorem_mcp_render_connections();

		return (string) ob_get_clean();
	}

	/**
	 * The table holding issued connections.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'zorem_mcp_keys';
	}

	/**
	 * The AI clients that have registered themselves to sign people in.
	 *
	 * Separate from the connections table because a client is not a connection: one client
	 * -- claude.ai, say -- signs in many people, and each of those is a row over there.
	 *
	 * @return string
	 */
	public static function clients_table() {
		global $wpdb;

		return $wpdb->prefix . 'zorem_mcp_clients';
	}

	/**
	 * Create or update the table when the schema version moves.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Create the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(191) NOT NULL DEFAULT '',
			token_hash CHAR(64) NOT NULL,
			token_prefix VARCHAR(24) NOT NULL DEFAULT '',
			refresh_hash CHAR(64) NOT NULL DEFAULT '',
			client VARCHAR(64) NOT NULL DEFAULT '',
			client_id VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			last_used_at DATETIME NULL DEFAULT NULL,
			revoked_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY token_hash (token_hash),
			KEY refresh_hash (refresh_hash),
			KEY user_id (user_id),
			KEY revoked_at (revoked_at)
		) {$collate};";

		$clients = self::clients_table();

		$sql_clients = "CREATE TABLE {$clients} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			client_id VARCHAR(64) NOT NULL,
			client_secret_hash CHAR(64) NOT NULL DEFAULT '',
			client_name VARCHAR(191) NOT NULL DEFAULT '',
			redirect_uris TEXT NULL,
			auth_method VARCHAR(32) NOT NULL DEFAULT 'none',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY client_id (client_id)
		) {$collate};";

		dbDelta( $sql );
		dbDelta( $sql_clients );

		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Whether the table is there to be used.
	 *
	 * @return bool
	 */
	public static function available() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off existence check.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Issue a connection for one person, and return the token once.
	 *
	 * The only moment the token exists outside the AI client that will hold it. Nothing here
	 * keeps a copy, and nothing later can produce it again -- a merchant who loses it issues
	 * another rather than recovering this one.
	 *
	 * @param int    $user_id User the connection acts as.
	 * @param string $name    What to call it on screen, e.g. the AI client's own name.
	 * @param string $client  The client's reported name, for the table.
	 * @return array{id:int,token:string,prefix:string}|null
	 */
	public static function issue( $user_id, $name = '', $client = '' ) {
		global $wpdb;

		$user_id = (int) $user_id;

		if ( ! $user_id || ! self::available() ) {
			return null;
		}

		$token   = self::TOKEN_PREFIX . wp_generate_password( 40, false, false );
		$prefix  = substr( $token, 0, 13 );
		$refresh = self::REFRESH_PREFIX . wp_generate_password( 40, false, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- insert into this package's own table.
		$ok = $wpdb->insert(
			self::table(),
			array(
				'user_id'      => $user_id,
				'name'         => substr( '' !== $name ? $name : __( 'AI assistant', 'zorem-mcp' ), 0, 191 ),
				'token_hash'   => hash( 'sha256', $token ),
				'token_prefix' => $prefix,
				'refresh_hash' => hash( 'sha256', $refresh ),
				'client'       => substr( (string) $client, 0, 64 ),
				'created_at'   => current_time( 'mysql', 1 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			return null;
		}

		return array(
			'id'      => (int) $wpdb->insert_id,
			'token'   => $token,
			'prefix'  => $prefix,
			'refresh' => $refresh,
		);
	}

	/**
	 * Give one connection a new pair of secrets, keeping it the same connection.
	 *
	 * WHY ROTATE RATHER THAN ISSUE AFRESH
	 *
	 * Because a refresh is not a new person arriving. It is the same app, still acting for
	 * the same merchant, swapping a secret it has held for a while -- and if each refresh
	 * created a row, "Who is connected" would fill with one entry per hour and the merchant
	 * could no longer see who is actually connected, let alone turn one of them off.
	 *
	 * The row, its history and its place in the list all stay. Only the two secrets change.
	 *
	 * @param int $id Connection id.
	 * @return array|null New token and refresh token, or null if the row is gone or revoked.
	 */
	public static function rotate( $id ) {
		global $wpdb;

		$id = (int) $id;

		if ( ! $id || ! self::available() ) {
			return null;
		}

		$token   = self::TOKEN_PREFIX . wp_generate_password( 40, false, false );
		$refresh = self::REFRESH_PREFIX . wp_generate_password( 40, false, false );

		// The revoked_at guard is the whole point: once a merchant has turned a connection
		// off, no refresh may quietly bring it back.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- direct write to this package's own table.
		$ok = $wpdb->update(
			self::table(),
			array(
				'token_hash'   => hash( 'sha256', $token ),
				'token_prefix' => substr( $token, 0, 13 ),
				'refresh_hash' => hash( 'sha256', $refresh ),
				'last_used_at' => current_time( 'mysql', 1 ),
			),
			array(
				'id'         => $id,
				'revoked_at' => null,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( ! $ok ) {
			return null;
		}

		return array(
			'id'      => $id,
			'token'   => $token,
			'refresh' => $refresh,
		);
	}

	/**
	 * The live connection a refresh token belongs to.
	 *
	 * Revoked rows are not matched at all, so an app holding a refresh token for a
	 * connection the merchant turned off is told to sign in again rather than being handed
	 * a working token.
	 *
	 * @param string $refresh Refresh token as presented.
	 * @return array|null
	 */
	public static function match_refresh( $refresh ) {
		global $wpdb;

		$refresh = (string) $refresh;

		if ( '' === $refresh || ! self::available() ) {
			return null;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is this package's own; the hash is bound.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, client FROM {$table} WHERE refresh_hash = %s AND revoked_at IS NULL",
				hash( 'sha256', $refresh )
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Find the live connection a token belongs to, and note that it was used.
	 *
	 * The lookup is by hash, so a stolen database gives up no working tokens. A revoked row
	 * is deliberately distinguished from an unknown one for the caller's benefit -- "this
	 * was turned off" and "this was never valid" are different things to be told.
	 *
	 * @param string $token Token presented by the caller.
	 * @return array{id:int,user_id:int,revoked:bool}|null Null when no such token exists.
	 */
	public static function match( $token ) {
		global $wpdb;

		$token = (string) $token;

		if ( '' === $token || ! self::available() ) {
			return null;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is this package's own; the hash is bound.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, user_id, revoked_at FROM {$table} WHERE token_hash = %s", hash( 'sha256', $token ) ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$revoked = ! empty( $row['revoked_at'] );

		if ( ! $revoked ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fire and forget; a failed write costs a "last used" reading, nothing more.
			$wpdb->update(
				$table,
				array( 'last_used_at' => current_time( 'mysql', 1 ) ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return array(
			'id'      => (int) $row['id'],
			'user_id' => (int) $row['user_id'],
			'revoked' => $revoked,
		);
	}

	/**
	 * Turn one connection off.
	 *
	 * Marked, not deleted. The row is the merchant's record that this connection existed and
	 * when it was last used, and that history is the reason they came to this screen; a
	 * revoked connection that vanishes leaves them wondering whether they imagined it.
	 *
	 * @param int $id Connection id.
	 * @return bool
	 */
	public static function revoke( $id ) {
		global $wpdb;

		$id = (int) $id;

		if ( ! $id || ! self::available() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- direct write to this package's own table.
		return (bool) $wpdb->update(
			self::table(),
			array( 'revoked_at' => current_time( 'mysql', 1 ) ),
			array(
				'id'         => $id,
				'revoked_at' => null,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Every connection on this store, newest first.
	 *
	 * The whole store, not the current user's own: this list is shown to somebody who can
	 * manage the store, and the question it answers is "who has access", which a per-user
	 * list cannot answer at all.
	 *
	 * @param int $limit Rows to return.
	 * @return array
	 */
	public static function all( $limit = 100 ) {
		global $wpdb;

		if ( ! self::available() ) {
			return array();
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is this package's own; the limit is bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, (int) $limit ) ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many connections are live right now.
	 *
	 * @return int
	 */
	public static function live_count() {
		global $wpdb;

		if ( ! self::available() ) {
			return 0;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is this package's own, no user input in this query.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE revoked_at IS NULL" );
	}
}
