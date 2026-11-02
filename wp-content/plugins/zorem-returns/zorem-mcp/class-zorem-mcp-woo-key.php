<?php
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- this library owns the 'zorem-mcp' domain and loads it itself; Plugin Check compares against the host plugin's folder name, which is a different plugin every time this folder ships.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- hook and function names are a public API shared with the free plugin; renaming them breaks customer snippets.
/**
 * Zorem MCP - the WooCommerce MCP key.
 *
 * WooCommerce MCP authenticates with a REST API key sent as a header, and the merchant is
 * otherwise expected to leave this screen, find WooCommerce > Settings > Advanced > REST
 * API, get the permissions right, and carry two long strings back. This class does that
 * for them, creating exactly the same row WooCommerce's own screen creates.
 *
 * WHAT IS AND IS NOT STORED
 *
 * The secret is never saved by AST PRO. It is generated, handed straight back to the
 * browser once, and forgotten -- the same contract WooCommerce's own screen offers, and
 * for the same reason: a store-wide credential belongs in the merchant's password manager,
 * not in another plugin's options where a settings export could carry it off. What is
 * kept is the key_id and the last seven characters, which is enough to show what exists
 * and to revoke it later, and is useless to anyone who reads it.
 *
 * Note the scope. A WooCommerce key opens the whole store to whoever holds it, not just
 * tracking, because the key belongs to WooCommerce and AST PRO cannot narrow it. The
 * AST PRO connection in AST_MCP is the narrow route; this one is the powerful one, and
 * the screen says so.
 *
 * @package Zorem_MCP
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorem_MCP_Woo_Key {

	/**
	 * Option holding what we know about the key we created: its id, its truncated tail,
	 * who it belongs to and when it was made. Deliberately NOT in ast_general_settings,
	 * so the settings screen never renders it and an export never carries it.
	 */
	const OPTION = 'zorem_mcp_woo_key';

	/**
	 * AJAX action the Generate button calls.
	 */
	const AJAX = 'zorem_mcp_accept_woo_key';

	/**
	 * AJAX action that removes the stored key.
	 *
	 * @var string
	 */
	const AJAX_FORGET = 'zorem_mcp_forget_woo_key';

	/**
	 * AJAX action that deletes the key in WooCommerce, ending every app using it.
	 *
	 * @var string
	 */
	const AJAX_DELETE = 'zorem_mcp_delete_woo_key';

	/**
	 * Where the answer to tools/list is kept between page loads.
	 *
	 * @var string
	 */
	const TOOLS_TRANSIENT = 'zorem_mcp_woo_tools';

	/**
	 * MCP protocol version announced when asking the route what it offers.
	 *
	 * @var string
	 */
	const PROTOCOL = '2025-06-18';

	/**
	 * clientInfo name this screen announces when it asks the route what it offers, so its
	 * own sessions can be left out of the list of who is connected.
	 *
	 * @var string
	 */
	const PROBE_CLIENT = 'zorem-mcp-screen';

	/**
	 * Cipher used to seal the stored key. AES-256-CBC because it is present on every PHP
	 * build that has OpenSSL at all, which is what decides whether the key is kept.
	 *
	 * @var string
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * Nonce action for the shared AI Assistant form. Named here because this handler and
	 * the form live in different plugins, and both must agree on it.
	 */
	const NONCE = 'zorem_mcp_form';

	/**
	 * Description written on the WooCommerce key, so a merchant looking at their key list
	 * can tell where it came from.
	 */
	/*
	 * What a merchant sees this key called, wherever it is listed.
	 *
	 * Not one plugin's name. It read "AST PRO - AI Assistant", which on a store running only
	 * Zorem Returns names a plugin that is not installed -- and the key is not AST PRO's in
	 * any case: it is WooCommerce's, made by the merchant, and used by whichever Zorem
	 * plugins happen to be on the store.
	 */
	const DESCRIPTION = 'Zorem - AI Assistant';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::AJAX, array( __CLASS__, 'ajax_accept' ) );
		add_action( 'wp_ajax_' . self::AJAX_FORGET, array( __CLASS__, 'ajax_forget' ) );
		add_action( 'wp_ajax_' . self::AJAX_DELETE, array( __CLASS__, 'ajax_delete' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| State
	|--------------------------------------------------------------------------
	*/

	/**
	 * The WooCommerce MCP endpoint for this installation.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( 'woocommerce/mcp' );
	}

	/**
	 * Whether a key can be created here at all: WooCommerce loaded, its hashing helpers
	 * present, and its key table in place.
	 *
	 * @return bool
	 */
	public static function available() {
		global $wpdb;

		if ( ! function_exists( 'wc_rand_hash' ) || ! function_exists( 'wc_api_hash' ) ) {
			return false;
		}

		$table = $wpdb->prefix . 'woocommerce_api_keys';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW TABLES takes no placeholders for the table name; $table is built from $wpdb->prefix, not from input.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * What the merchant told us about the key they entered.
	 *
	 * The last few characters and the date, which is enough for them to recognise which key
	 * this screen was set up with. It is not checked against WooCommerce any more: the key
	 * is theirs, made on their screen, and this plugin never learns its id -- so the only
	 * honest claim left is "this is what you entered, and when".
	 *
	 * @return array Empty when nothing has been entered.
	 */
	public static function record() {
		$record = get_option( self::OPTION, array() );

		return ( is_array( $record ) && ! empty( $record['truncated'] ) ) ? $record : array();
	}

	/**
	 * The key this screen was set up with, ready to draw the setup rows again.
	 *
	 * It used to be thrown away the moment the rows were drawn, so a refresh — or coming back
	 * tomorrow — left the merchant re-pasting a key they had already given us to see steps
	 * they had already seen. That is a real cost paid every time, against a risk that has a
	 * cheaper answer: keep it, but not in a form a stolen database can use.
	 *
	 * Sealed with the site's own salts, which live in wp-config.php and not in the database.
	 * A dump of wp_options alone yields ciphertext; opening it needs the file system too. It
	 * is not a vault — anyone who can already read wp-config.php can read this — but it moves
	 * the credential out of reach of the one thing that actually leaks.
	 *
	 * @return string "ck_...:cs_..." or an empty string.
	 */
	public static function pair() {
		$record = self::record();

		if ( empty( $record['sealed'] ) ) {
			return '';
		}

		return self::unseal( (string) $record['sealed'] );
	}

	/**
	 * Whether this installation can seal the key at all.
	 *
	 * Without OpenSSL there is no way to store it safely, and storing it unsafely is not a
	 * trade this library makes on a merchant's behalf. Those sites keep the old behaviour:
	 * the rows are drawn once, and the key is asked for again next time.
	 *
	 * @return bool
	 */
	protected static function can_seal() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' )
			&& in_array( self::CIPHER, (array) openssl_get_cipher_methods(), true );
	}

	/**
	 * The encryption key, derived from the salts rather than stored anywhere.
	 *
	 * @return string
	 */
	protected static function seal_key() {
		return hash( 'sha256', wp_salt( 'secure_auth' ) . '|zorem-mcp-woo-key', true );
	}

	/**
	 * Encrypt the key pair for storage.
	 *
	 * @param string $pair "ck_...:cs_...".
	 * @return string Ciphertext, or an empty string when sealing is unavailable.
	 */
	protected static function seal( $pair ) {
		if ( ! self::can_seal() ) {
			return '';
		}

		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$out = openssl_encrypt( $pair, self::CIPHER, self::seal_key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $out ) {
			return '';
		}

		return base64_encode( $iv . $out );
	}

	/**
	 * Decrypt a stored key pair.
	 *
	 * Returns nothing rather than failing loudly when the salts have changed since it was
	 * written: the merchant sees the screen asking for the key again, which is exactly what
	 * they need to do, and a rotated salt is not an error worth a message of its own.
	 *
	 * @param string $sealed Ciphertext from seal().
	 * @return string
	 */
	protected static function unseal( $sealed ) {
		if ( ! self::can_seal() ) {
			return '';
		}

		$raw = base64_decode( $sealed, true );
		$len = openssl_cipher_iv_length( self::CIPHER );

		if ( ! is_string( $raw ) || strlen( $raw ) <= $len ) {
			return '';
		}

		$out = openssl_decrypt(
			substr( $raw, $len ),
			self::CIPHER,
			self::seal_key(),
			OPENSSL_RAW_DATA,
			substr( $raw, 0, $len )
		);

		return is_string( $out ) ? $out : '';
	}

	/**
	 * Forget the stored key.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::OPTION );
		delete_transient( self::TOOLS_TRANSIENT );
	}

	/**
	 * The tools WooCommerce's MCP server actually offers, asked of the server itself.
	 *
	 * There is no way to read them from here. WooCommerce builds its own tools only while an
	 * MCP request is being served -- AbilitiesRestBridge returns early unless
	 * MCPAdapterProvider::is_mcp_request() -- so on an admin screen they do not exist, and
	 * listing the registered abilities finds only the ones this plugin adds unconditionally.
	 *
	 * So ask the way an assistant asks: one tools/list call to the route, with the merchant's
	 * key in the header. The answer is what an assistant would actually be handed, which is
	 * the only version of this list worth showing, and it stays right on its own as
	 * WooCommerce changes.
	 *
	 * Cached, because this is a loopback HTTP request and the screen is not worth one on every
	 * page load. Failures are cached too, briefly: a site whose firewall refuses this should
	 * retry occasionally, not on every render.
	 *
	 * @param bool $force Skip the cache.
	 * @return array {
	 *     @type bool  $ok    Whether the server answered.
	 *     @type array $tools Tools, each with label, writes and destructive.
	 *     @type string $error Why not, when it did not.
	 * }
	 */
	/**
	 * What WooCommerce knows about the stored key.
	 *
	 * There is no "who is connected" to show for this route: a WooCommerce key is one
	 * identity, and everyone holding it is that identity — which is the whole reason the
	 * shared link exists alongside it. What can honestly be shown is the key itself: who it
	 * acts as, how far it reaches, and whether it has been used. WooCommerce keeps last_access
	 * up to date on its own, so that column answers the question a merchant actually has,
	 * which is whether any of this is working.
	 *
	 * Found by hashing the stored key the way WooCommerce does — it stores only the hash —
	 * so a key deleted in WooCommerce simply is not found, and the screen can say so instead
	 * of leaving the merchant to work it out from a failing tool list.
	 *
	 * @return array Empty when there is no key, or no row for it.
	 */
	public static function key_details() {
		global $wpdb;

		$pair = self::pair();

		if ( '' === $pair || ! function_exists( 'wc_api_hash' ) ) {
			return array();
		}

		$consumer_key = substr( $pair, 0, strpos( $pair, ':' ) );

		if ( '' === $consumer_key ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one row on an admin screen, keyed on an indexed column.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT key_id, user_id, description, permissions, truncated_key, last_access
				 FROM {$wpdb->prefix}woocommerce_api_keys
				 WHERE consumer_key = %s",
				wc_api_hash( $consumer_key )
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return array( 'found' => false );
		}

		$user = get_userdata( (int) $row['user_id'] );

		return array(
			'found'       => true,
			'description' => (string) $row['description'],
			'user_id'     => (int) $row['user_id'],
			'user'        => $user ? $user->display_name : '',
			'email'       => $user ? $user->user_email : '',
			'permissions' => (string) $row['permissions'],
			'last_access' => $row['last_access'] ? (string) $row['last_access'] : '',
		);
	}

	/**
	 * Delete the key from WooCommerce, ending every app that holds it.
	 *
	 * The only revoke this route actually has. Closing a session achieves nothing — the app
	 * still has the key and opens another — so cutting access means the key itself has to go,
	 * and after this a new one must be made in WooCommerce before anything can connect again.
	 *
	 * Irreversible, and wider than it looks: a merchant may have given this same key to
	 * something that is not an AI assistant, and that stops too. The screen says so before
	 * asking.
	 *
	 * @return bool Whether a key row was removed.
	 */
	public static function delete_key() {
		global $wpdb;

		$pair = self::pair();

		if ( '' === $pair || ! function_exists( 'wc_api_hash' ) ) {
			return false;
		}

		$consumer_key = substr( $pair, 0, strpos( $pair, ':' ) );

		if ( '' === $consumer_key ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one row, keyed on an indexed column; WooCommerce offers no API for this.
		$removed = $wpdb->delete(
			$wpdb->prefix . 'woocommerce_api_keys',
			array( 'consumer_key' => wc_api_hash( $consumer_key ) ),
			array( '%s' )
		);

		// Our copy goes either way. If the row was already gone, keeping a key that opens
		// nothing would only leave the screen describing a connection that cannot exist.
		self::forget();

		return (bool) $removed;
	}

	/**
	 * The assistants currently holding a session on WooCommerce's MCP route.
	 *
	 * The adapter opens a session per initialize and keeps them in the key owner's user meta,
	 * with the client's own name and when it was last active. So there is something real to
	 * show here after all — not people, but apps: every session belongs to the one account
	 * the key acts as, because that is all the key can be.
	 *
	 * This screen's own probe is left out. It opens a session every time it asks what the
	 * route offers, and a connection list that lists the act of reading it is noise.
	 *
	 * @return array List of sessions, newest activity first.
	 */
	public static function sessions() {
		if ( ! class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' ) ) {
			return array();
		}

		$details = self::key_details();

		if ( empty( $details['found'] ) || empty( $details['user_id'] ) ) {
			return array();
		}

		$raw = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( (int) $details['user_id'] );
		$out = array();

		foreach ( (array) $raw as $session ) {
			$params = isset( $session['client_params'] ) ? (array) $session['client_params'] : array();
			$client = isset( $params['clientInfo'] ) ? (array) $params['clientInfo'] : array();
			$name   = isset( $client['name'] ) ? (string) $client['name'] : '';

			if ( self::is_probe_client( $name ) ) {
				continue;
			}

			// Grouped on the app, not the raw name: Claude arrives under two of them and is
			// one app, which is the whole point of naming it.
			$app     = self::app_name( $name );
			$key     = strtolower( $app );
			$created = isset( $session['created_at'] ) ? (int) $session['created_at'] : 0;
			$last    = isset( $session['last_activity'] ) ? (int) $session['last_activity'] : 0;

			// One row per app, not per session. A client opens a fresh session whenever it
			// reconnects — mcp-remote opens several for a single set-up — and a table with six
			// rows for one assistant answers a question nobody asked. Keep the first time it
			// appeared and the last time it did anything.
			if ( isset( $out[ $key ] ) ) {
				$out[ $key ]['created']  = min( $out[ $key ]['created'] ?: $created, $created ?: $out[ $key ]['created'] );
				$out[ $key ]['last']     = max( $out[ $key ]['last'], $last );
				$out[ $key ]['sessions'] = $out[ $key ]['sessions'] + 1;
				continue;
			}

			$out[ $key ] = array(
				'app'      => $app,
				'created'  => $created,
				'last'     => $last,
				'sessions' => 1,
			);
		}

		$out = array_values( $out );

		usort(
			$out,
			function ( $a, $b ) {
				return $b['last'] <=> $a['last'];
			}
		);

		return $out;
	}

	/**
	 * The app a raw clientInfo name belongs to.
	 *
	 * MCP clients name themselves for the machinery, not the merchant: Claude arrives as
	 * "claude-ai (via mcp-remote 0.8.3)" and, in local agent mode, as
	 * "local-agent-mode-<the server name they chose>". Printed as-is, a table of two apps
	 * reads like a table of six, and none of the names are the ones on the buttons above.
	 *
	 * Matched on a fragment rather than the whole string, because the version and the
	 * transport ride along in it and both change without the app changing.
	 *
	 * @param string $raw clientInfo name from the session.
	 * @return string
	 */
	public static function app_name( $raw ) {
		$raw  = trim( (string) $raw );
		$look = strtolower( $raw );

		$known = array(
			'claude desktop'   => 'Claude Desktop',
			'claude-desktop'   => 'Claude Desktop',
			'claude'           => 'Claude',
			'local-agent-mode' => 'Claude',
			'cursor'           => 'Cursor',
			'windsurf'         => 'Windsurf',
			'cline'            => 'Cline',
			'chatgpt'          => 'ChatGPT',
			'openai'           => 'ChatGPT',
			'vscode'           => 'VS Code',
			'visual studio'    => 'VS Code',
			'zed'              => 'Zed',
		);

		foreach ( $known as $needle => $label ) {
			if ( false !== strpos( $look, $needle ) ) {
				return $label;
			}
		}

		// Something we have not met. Drop the "(via mcp-remote 0.8.3)" tail so at least the
		// app's own name is readable, and leave the rest alone rather than guessing at it.
		$raw = trim( (string) preg_replace( '~\s*\(via[^)]*\)\s*$~i', '', $raw ) );

		return '' !== $raw ? $raw : __( 'an unnamed app', 'zorem-mcp' );
	}

	/**
	 * Whether a client name belongs to something probing the route rather than using it.
	 *
	 * This screen's own tools/list call is one. mcp-remote's reachability check is another:
	 * it opens a session named for the test and never comes back, so listing it tells a
	 * merchant an assistant is connected when nothing is.
	 *
	 * The old name of this screen's probe is still matched, because sessions created under it
	 * live on in user meta until they expire, and they are no more real for being older.
	 *
	 * @param string $name clientInfo name from the session.
	 * @return bool
	 */
	protected static function is_probe_client( $name ) {
		$name = strtolower( trim( (string) $name ) );

		if ( '' === $name ) {
			return false;
		}

		foreach ( array( self::PROBE_CLIENT, 'zorem-mcp', 'mcp-remote-fallback-test' ) as $probe ) {
			if ( strtolower( $probe ) === $name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One JSON-RPC call to the WooCommerce MCP route.
	 *
	 * @param string      $pair    "ck_...:cs_...".
	 * @param string      $method  MCP method, e.g. tools/list.
	 * @param array|null  $params  Params, or null to send none.
	 * @param string      $session Session id from a previous initialize. Empty for initialize.
	 * @return array|WP_Error The wp_remote_post response.
	 */
	protected static function rpc( $pair, $method, $params = null, $session = '' ) {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => $method,
		);

		if ( null !== $params ) {
			$message['params'] = $params;
		}

		// What an MCP client sends. The Accept line matters: the transport may answer as an
		// event stream, and a client that does not offer to read one is not speaking the
		// protocol it is being answered in.
		$headers = array(
			'Content-Type'         => 'application/json',
			'Accept'               => 'application/json, text/event-stream',
			'MCP-Protocol-Version' => self::PROTOCOL,
			'X-MCP-API-Key'        => $pair,
		);

		if ( '' !== $session ) {
			$headers['Mcp-Session-Id'] = $session;
		}

		return wp_remote_post(
			self::endpoint_url(),
			array(
				'timeout'   => 15,
				'sslverify' => false, // Loopback to this same site; a self-signed staging cert is not a reason to fail.
				'headers'   => $headers,
				'body'      => (string) wp_json_encode( $message ),
			)
		);
	}

	public static function server_tools( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TOOLS_TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$pair = self::pair();

		if ( '' === $pair ) {
			return array(
				'ok'    => false,
				'tools' => array(),
				'error' => __( 'Enter your WooCommerce key above to see what this route offers.', 'zorem-mcp' ),
			);
		}

		// Two calls, because the route is a session protocol and not a plain endpoint. Only
		// initialize is allowed without a session; it answers with an Mcp-Session-Id header,
		// and every call after it must carry that back or the route refuses with "Missing
		// Mcp-Session-Id header" — which is exactly what asking for tools/list on its own got.
		$init = self::rpc( $pair, 'initialize', array(
			'protocolVersion' => self::PROTOCOL,
			'capabilities'    => new stdClass(),
			// Named so this screen's own probe can be told apart from a real assistant when
			// the sessions are listed back — otherwise reading the connection list would
			// itself appear in the connection list.
			'clientInfo'      => array(
				'name'    => self::PROBE_CLIENT,
				'version' => '1.0',
			),
		) );

		if ( is_wp_error( $init ) ) {
			$out = array( 'ok' => false, 'tools' => array(), 'error' => $init->get_error_message() );
			set_transient( self::TOOLS_TRANSIENT, $out, 10 * MINUTE_IN_SECONDS );

			return $out;
		}

		$session = trim( (string) wp_remote_retrieve_header( $init, 'mcp-session-id' ) );
		$reply   = self::rpc( $pair, 'tools/list', null, $session );


		$out = array( 'ok' => false, 'tools' => array(), 'error' => '' );

		if ( is_wp_error( $reply ) ) {
			$out['error'] = $reply->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $reply );
			$body = json_decode( (string) wp_remote_retrieve_body( $reply ), true );

			// Whatever the route said about itself, because "answered with 400" is a fact the
			// merchant cannot act on and neither can support. The JSON-RPC error message is
			// the one that names the actual problem.
			$said = '';

			if ( is_array( $body ) ) {
				if ( ! empty( $body['error']['message'] ) ) {
					$said = (string) $body['error']['message'];
				} elseif ( ! empty( $body['message'] ) ) {
					$said = (string) $body['message'];
				}
			}

			if ( 401 === $code || 403 === $code ) {
				$out['error'] = __( 'WooCommerce refused the key. Check it is still there in WooCommerce, and that it has Read/Write access.', 'zorem-mcp' );
			} elseif ( '' === $session ) {
				$out['error'] = __( 'WooCommerce MCP did not open a session, so its tool list could not be read.', 'zorem-mcp' );
			} elseif ( 200 !== $code ) {
				$out['error'] = '' !== $said
					/* translators: 1: HTTP status code, 2: message from WooCommerce. */
					? sprintf( __( 'The WooCommerce MCP route answered with %1$d: %2$s', 'zorem-mcp' ), $code, $said )
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'The WooCommerce MCP route answered with %d.', 'zorem-mcp' ), $code );
			} elseif ( is_array( $body ) && ! empty( $body['error']['message'] ) ) {
				/* translators: %s: message from WooCommerce. */
				$out['error'] = sprintf( __( 'WooCommerce MCP said: %s', 'zorem-mcp' ), $said );
			} elseif ( ! is_array( $body ) || ! isset( $body['result']['tools'] ) || ! is_array( $body['result']['tools'] ) ) {
				$out['error'] = __( 'The WooCommerce MCP route answered, but not with a tool list.', 'zorem-mcp' );
			} else {
				foreach ( $body['result']['tools'] as $tool ) {
					if ( empty( $tool['name'] ) ) {
						continue;
					}

					$notes = isset( $tool['annotations'] ) ? (array) $tool['annotations'] : array();
					$label = ! empty( $tool['title'] ) ? $tool['title'] : $tool['name'];

					// Both spellings. The MCP specification names these readOnlyHint and
					// destructiveHint, but the adapter passes an ability's meta['annotations']
					// through untouched, and both WooCommerce's abilities and this plugin's
					// write them as readonly and destructive. Reading only the spec spelling
					// found neither, so every tool came out marked Write — including plainly
					// read-only ones like listing products.
					$readonly    = ! empty( $notes['readonly'] ) || ! empty( $notes['readOnlyHint'] );
					$destructive = ! empty( $notes['destructive'] ) || ! empty( $notes['destructiveHint'] );

					// WooCommerce marks its read abilities and leaves the rest unmarked, so
					// deleting a product and updating one arrive identical. On a card whose
					// job is to show how far the key reaches, that understates it. Fall back
					// to the name, and only to a name that ends in -delete: narrow enough to
					// be wrong only if a tool is named for something it does not do.
					if ( ! $destructive && ! $readonly ) {
						$destructive = (bool) preg_match( '~[-_.]delete$~i', (string) $tool['name'] );
					}

					$out['tools'][] = array(
						'name'        => (string) $tool['name'],
						'label'       => (string) $label,
						'writes'      => ! $readonly,
						'destructive' => $destructive,
					);
				}

				usort(
					$out['tools'],
					function ( $a, $b ) {
						return strcasecmp( $a['label'], $b['label'] );
					}
				);

				$out['ok'] = true;
			}
		}

		set_transient( self::TOOLS_TRANSIENT, $out, $out['ok'] ? 12 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );

		return $out;
	}

	/*
	|--------------------------------------------------------------------------
	| AJAX
	|--------------------------------------------------------------------------
	*/

	/**
	 * Generate a key and reply with the connect rows already rendered.
	 *
	 * Take the key the merchant made in WooCommerce, and draw the setup rows from it.
	 *
	 * WHY THIS DOES NOT MAKE THE KEY
	 *
	 * It used to. One button, and a store-wide credential existed -- created by this plugin,
	 * on the merchant's behalf, opening orders, products and customers. That is a lot of
	 * authority to mint on somebody's behalf from a screen about shipment tracking, and the
	 * merchant had no moment in which they decided to do it.
	 *
	 * So they make it, on WooCommerce's own screen, where the warnings are WooCommerce's and
	 * the permissions are theirs to choose. This takes the two halves and does the part that
	 * is genuinely awkward: turning them into the config each AI client expects.
	 *
	 * The rows are built here rather than in JavaScript so that the deeplink and command
	 * shapes live in exactly one place -- zorem_mcp_client_targets() -- and the screen can
	 * never offer a client something subtly different from what the rest of the plugin
	 * documents.
	 *
	 * @return void
	 */
	public static function ajax_accept() {
		// manage_woocommerce, like every other check in this library.
		//
		// This read AST_PLUGIN_ACCESS until a merchant saw "Uncaught Error: Undefined
		// constant" printed across this screen -- the constant is AST PRO's, and this file
		// had been carried out of AST PRO without noticing it came along. A shared library
		// cannot name another plugin's constants: on the plugin it came from the bug is
		// invisible, and on every other one it is fatal.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'zorem-mcp' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, self::NONCE . '_nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified immediately above.
		$consumer_key    = isset( $_POST['consumer_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['consumer_key'] ) ) ) : '';
		$consumer_secret = isset( $_POST['consumer_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['consumer_secret'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $consumer_key || '' === $consumer_secret ) {
			wp_send_json_error( array( 'message' => __( 'Both halves of the key are needed. Copy them from WooCommerce before leaving that screen — the secret is shown once.', 'zorem-mcp' ) ) );
		}

		// Shape-checked, not verified. WooCommerce stores the consumer key as a hash, so
		// nothing here can confirm a key is real without spending a request pretending to be
		// an AI client -- and a key that is real today can be revoked tomorrow anyway. What
		// this catches is the mistake people actually make: pasting one half twice, or pasting
		// the description instead of the key.
		if ( 0 !== strpos( $consumer_key, 'ck_' ) || 0 !== strpos( $consumer_secret, 'cs_' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Those do not look like a WooCommerce key. The consumer key starts with ck_ and the consumer secret with cs_ — check you have one of each, the right way round.', 'zorem-mcp' ),
				)
			);
		}

		$pair = $consumer_key . ':' . $consumer_secret;

		// The tail and the date so the merchant can recognise which key this is, and the key
		// itself sealed with the site's salts so the setup rows survive a refresh. Storing it
		// in the clear would put a working store-wide credential in the options table; asking
		// for it again on every visit made the merchant repeat a job they had already done.
		update_option(
			self::OPTION,
			array(
				'truncated' => substr( $consumer_key, -4 ),
				'created'   => time(),
				'sealed'    => self::seal( $pair ),
			),
			false
		);

		// A different key can see a different set of tools — its permissions decide what the
		// route hands back — so the previous answer is no longer about this key.
		delete_transient( self::TOOLS_TRANSIENT );

		ob_start();
		foreach ( zorem_mcp_client_targets( self::endpoint_url(), $pair ) as $target ) {
			zorem_mcp_client_row( $target );
		}
		$rows = ob_get_clean();

		wp_send_json_success(
			array(
				'truncated' => substr( $consumer_key, -4 ),
				'rows'      => $rows,
				'hint'      => zorem_mcp_woo_key_hint( self::record() ),
				'tools'     => self::tools_html(),
				'about'     => self::about_html(),
				'conns'     => self::conns_html(),
			)
		);
	}

	/**
	 * The tool list as markup, for the replies that repaint it without a page load.
	 *
	 * @return string
	 */
	protected static function tools_html() {
		if ( ! function_exists( 'zorem_mcp_render_woo_tools' ) ) {
			return '';
		}

		ob_start();
		zorem_mcp_render_woo_tools();

		return (string) ob_get_clean();
	}

	/**
	 * The "Who is connected" body as markup, for the same repaints.
	 *
	 * @return string
	 */
	protected static function conns_html() {
		if ( ! function_exists( 'zorem_mcp_render_woo_connections' ) ) {
			return '';
		}

		ob_start();
		zorem_mcp_render_woo_connections();

		return (string) ob_get_clean();
	}

	/**
	 * The "About this key" body as markup, for the same repaints.
	 *
	 * @return string
	 */
	protected static function about_html() {
		if ( ! function_exists( 'zorem_mcp_render_woo_key_details' ) ) {
			return '';
		}

		ob_start();
		zorem_mcp_render_woo_key_details();

		return (string) ob_get_clean();
	}

	/**
	 * Remove the stored key.
	 *
	 * Only this site's copy. The key goes on working, because it is WooCommerce's and the
	 * merchant made it — deleting it is a decision for WooCommerce's own screen, where they
	 * can see what else might be using it.
	 *
	 * @return void
	 */
	public static function ajax_forget() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'zorem-mcp' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, self::NONCE . '_nonce' );

		self::forget();

		wp_send_json_success(
			array(
				'hint'  => zorem_mcp_woo_key_hint( array() ),
				'tools' => self::tools_html(),
				'about' => '',
				'conns' => '',
			)
		);
	}

	/**
	 * Delete the key in WooCommerce and clear this site's copy of it.
	 *
	 * @return void
	 */
	public static function ajax_delete() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'zorem-mcp' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, self::NONCE . '_nonce' );

		$removed = self::delete_key();

		wp_send_json_success(
			array(
				'message' => $removed
					? __( 'The key is deleted. Nothing can connect with it now — make a new one when you need to set an app up again.', 'zorem-mcp' )
					: __( 'That key was already gone from WooCommerce. This site has stopped offering it.', 'zorem-mcp' ),
				'hint'    => zorem_mcp_woo_key_hint( array() ),
				'tools'   => self::tools_html(),
				'about'   => '',
				'conns'   => '',
			)
		);
	}
}
