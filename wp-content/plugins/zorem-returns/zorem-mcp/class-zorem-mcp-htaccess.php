<?php
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- this library owns the 'zorem-mcp' domain and loads it itself; Plugin Check compares against the host plugin's folder name, which is a different plugin every time this folder ships.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- this package is shared across the Zorem plugin family; its own zorem_mcp_ prefix is the point.
/**
 * Zorem MCP - getting the connection past the store's own bad-bot list.
 *
 * THE PROBLEM THIS EXISTS FOR
 *
 * Security plugins ship a list of user agents to refuse, inherited from the days when the
 * things worth blocking announced themselves. Those lists name the Python and Perl HTTP
 * libraries -- and the services behind AI assistants are written in exactly those. So a
 * store can have a working link, a correct path and a valid token, and still refuse the one
 * caller it meant to allow.
 *
 * It is worse than it sounds, because the refusal is selective. An assistant's tool calls
 * arrive under its own name and get through; its sign-in service arrives under a library
 * name and does not. The merchant sees an assistant that connects, lists its tools, and
 * then cannot be signed into -- with nothing anywhere saying why.
 *
 * WHY THE PLUGIN CANNOT SIMPLY HANDLE IT
 *
 * The refusal happens in Apache, before any PHP runs. There is nothing to intercept. The
 * only place to answer it is the same file the rule lives in.
 *
 * WHAT THIS WRITES
 *
 * Four lines that say "requests to this plugin's own path are done being rewritten". Placed
 * at the top of .htaccess, they run before the bad-bot rules and stop those rules matching
 * this path -- and only this path. Nothing else on the site changes, and no rule is deleted:
 * the store's protection is exactly as it was for every other URL.
 *
 * NEVER WITHOUT BEING ASKED
 *
 * A plugin that edits .htaccess on its own is a plugin that can take a site down on its own.
 * This runs when a merchant presses a button, having been told what it does, and it can be
 * undone by pressing another. It writes through WordPress's own insert_with_markers(), backs
 * the file up first, and checks its work by making a real request afterwards -- because the
 * only honest way to report a fix to a problem this invisible is to reproduce the problem
 * and show it gone.
 *
 * @package Zorem_MCP
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorem_MCP_Htaccess {

	/**
	 * The name WordPress writes into the file's marker comments.
	 */
	const MARKER = 'Zorem MCP';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_zorem_mcp_htaccess', array( __CLASS__, 'ajax' ) );
	}

	/**
	 * Where the file is, whether or not it exists yet.
	 *
	 * @return string
	 */
	public static function path() {
		return get_home_path() . '.htaccess';
	}

	/**
	 * Whether writing to it is even possible here.
	 *
	 * Apache only. On nginx the file is inert, and a plugin that wrote to it anyway would be
	 * reporting a fix that changed nothing at all.
	 *
	 * @return bool
	 */
	public static function possible() {
		if ( ! function_exists( 'got_mod_rewrite' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		if ( ! got_mod_rewrite() ) {
			return false;
		}

		$file = self::path();

		return file_exists( $file ) ? wp_is_writable( $file ) : wp_is_writable( dirname( $file ) );
	}

	/**
	 * Whether our lines are in the file.
	 *
	 * @return bool
	 */
	public static function present() {
		$file = self::path();

		if ( ! file_exists( $file ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local config file, not a remote resource.
		$contents = (string) file_get_contents( $file );

		return false !== strpos( $contents, '# BEGIN ' . self::MARKER );
	}

	/**
	 * The lines themselves.
	 *
	 * One rule: send this plugin's path to index.php, and stop.
	 *
	 * The obvious version of this -- leave the URL alone and stop rewriting -- is wrong, and
	 * wrong in a way that looks right. WordPress serves /wp-json/ by rewriting it to
	 * index.php; stop the rewriting and Apache goes looking for a file that has never
	 * existed, so the exemption "works" by turning the endpoint into a 404.
	 *
	 * So the rule does the rewrite itself rather than skipping it. WordPress reads the
	 * original address off REQUEST_URI, which the rewrite does not disturb, and routes the
	 * request exactly as it always did.
	 *
	 * END, not L. L stops the current pass, and a pass that changed the URL is followed by
	 * another one -- on which the bad-bot rule, which matches every URL, would catch the
	 * request anyway. END stops rewriting altogether, which is the only flag that actually
	 * gets this path past a rule written to match everything.
	 *
	 * @return string[]
	 */
	protected static function lines() {
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$rest = trim( (string) wp_parse_url( rest_url( Zorem_MCP_Server::REST_NS ), PHP_URL_PATH ), '/' );

		// The rule sits in the home directory's own file, so its pattern is relative to that
		// directory while its target has to be absolute.
		if ( '' !== $home && 0 === strpos( $rest, $home . '/' ) ) {
			$rest = substr( $rest, strlen( $home ) + 1 );
		}

		$target = '/' . ( '' !== $home ? $home . '/' : '' ) . 'index.php';

		return array(
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteRule ^' . preg_quote( $rest ) . '/ ' . $target . ' [END]',
			// The endpoint is not the only address that has to survive the bad-bot list.
			// Signing in STARTS at the discovery documents, and those live outside the
			// plugin's own path -- at /.well-known/, where the specifications put them. An
			// exemption that covers the endpoint and not these lets the assistant reach a
			// door it can never be told how to open.
			'RewriteRule ^\.well-known/oauth- ' . $target . ' [END]',
			'</IfModule>',
		);
	}

	/**
	 * Put the lines in, at the top, with a copy of the file kept first.
	 *
	 * insert_with_markers() writes our block wherever our markers already are, and appends
	 * it at the end when they are not there -- which would put it after the very rules it
	 * needs to precede. So the block is moved to the front afterwards.
	 *
	 * @return true|WP_Error
	 */
	public static function apply() {
		if ( ! self::possible() ) {
			return new WP_Error(
				'zorem_mcp_htaccess_unwritable',
				__( 'The .htaccess file cannot be written on this server, so this has to be changed by hand or by your host.', 'zorem-mcp' )
			);
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$file = self::path();

		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local config file.
			$backup = (string) file_get_contents( $file );

			// One spare copy, kept as an option rather than a second file so that a merchant
			// cleaning up stray files cannot throw away the way back.
			update_option( 'zorem_mcp_htaccess_backup', $backup, false );
		}

		if ( ! insert_with_markers( $file, self::MARKER, self::lines() ) ) {
			return new WP_Error(
				'zorem_mcp_htaccess_failed',
				__( 'The .htaccess file could not be updated. Your host may have made it read-only.', 'zorem-mcp' )
			);
		}

		self::move_to_top();

		return true;
	}

	/**
	 * Take the lines out again.
	 *
	 * @return bool
	 */
	public static function remove() {
		if ( ! self::present() ) {
			return true;
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		return (bool) insert_with_markers( self::path(), self::MARKER, array() );
	}

	/**
	 * Move our block to the front of the file.
	 *
	 * Order is the whole point: a rule that says "stop rewriting" is worth nothing if it sits
	 * after the rule that refuses the request.
	 *
	 * @return void
	 */
	protected static function move_to_top() {
		$file = self::path();

		if ( ! file_exists( $file ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local config file.
		$contents = (string) file_get_contents( $file );

		$begin = '# BEGIN ' . self::MARKER;
		$end   = '# END ' . self::MARKER;

		$from = strpos( $contents, $begin );
		$to   = strpos( $contents, $end );

		if ( false === $from || false === $to || 0 === $from ) {
			return;
		}

		$to   += strlen( $end );
		$block = substr( $contents, $from, $to - $from );
		$rest  = substr( $contents, 0, $from ) . substr( $contents, $to );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- writing the file WordPress itself manages this way.
		file_put_contents( $file, $block . "\n\n" . ltrim( $rest ) );
	}

	/**
	 * Ask the site, from outside, whether a blocked-looking caller gets through now.
	 *
	 * The only report worth making. Writing the lines is easy and proves nothing: the rule
	 * could be somewhere .htaccess cannot reach -- a server config, a CDN -- and the file
	 * would look perfect while the caller was still refused.
	 *
	 * @return array{ok:bool,code:int}
	 */
	public static function verify() {
		return self::probe( 'python-requests/2.31.0 (Zorem link test)' );
	}

	/**
	 * Call the endpoint once, under a given name, and say whether it really answered.
	 *
	 * "Not a 403" is not good enough. A rewrite rule that gets the caller past the bad-bot
	 * list and breaks the route on the way turns a 403 into a 404, which reads as success to
	 * anything only watching for refusals -- and would leave a fix in place that had quietly
	 * disconnected every assistant on the store. So this insists on the real answer.
	 *
	 * @param string $agent Name to call under.
	 * @return array{ok:bool,code:int}
	 */
	protected static function probe( $agent ) {
		$response = wp_remote_post(
			Zorem_MCP_Server::endpoint_url(),
			array(
				'timeout'    => 20,
				'user-agent' => $agent,
				'headers'    => array( 'Content-Type' => 'application/json' ),
				'body'       => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'initialize',
						'params'  => array(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'   => false,
				'code' => 0,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return array(
			'ok'   => ( 200 === $code && isset( $body['result']['serverInfo'] ) ),
			'code' => $code,
		);
	}

	/**
	 * Whether the discovery document can be read under a name the store refuses.
	 *
	 * Checked separately from the endpoint because it is a separate address, and because it
	 * is the one an assistant reads FIRST. A store where the endpoint is reachable and the
	 * discovery document is not is a store where signing in fails with nothing to explain it.
	 *
	 * @param string $agent Name to call under.
	 * @return array{ok:bool,code:int}
	 */
	protected static function probe_discovery( $agent ) {
		$path = trim( (string) wp_parse_url( Zorem_MCP_OAuth::endpoint_url(), PHP_URL_PATH ), '/' );

		$response = wp_remote_get(
			home_url( '/.well-known/oauth-protected-resource/' . $path ),
			array(
				'timeout'    => 20,
				'user-agent' => $agent,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'   => false,
				'code' => 0,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return array(
			'ok'   => ( 200 === $code && isset( $body['resource'] ) ),
			'code' => $code,
		);
	}

	/**
	 * Apply or undo, then say what actually happened.
	 *
	 * @return void
	 */
	public static function ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'zorem-mcp' ) ), 403 );
		}

		check_ajax_referer( Zorem_MCP_Woo_Key::NONCE, Zorem_MCP_Woo_Key::NONCE . '_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified immediately above.
		$undo = isset( $_POST['undo'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['undo'] ) );

		if ( $undo ) {
			self::remove();

			wp_send_json_success(
				array(
					'message' => __( 'The exception has been removed. Your security plugin now applies to this link exactly as it does to the rest of the site.', 'zorem-mcp' ),
					'present' => false,
				)
			);
		}

		$done = self::apply();

		if ( is_wp_error( $done ) ) {
			wp_send_json_error( array( 'message' => $done->get_error_message() ) );
		}

		$check    = self::verify();
		$normal   = self::probe( 'Zorem link test' );
		$discover = self::probe_discovery( 'python-requests/2.31.0 (Zorem link test)' );

		if ( $check['ok'] && $normal['ok'] && $discover['ok'] ) {
			wp_send_json_success(
				array(
					'message' => __( 'Fixed, and checked: a caller that was being refused a moment ago now gets through. Try connecting your AI assistant again.', 'zorem-mcp' ),
					'present' => true,
				)
			);
		}

		// Either the caller is still refused -- so whatever refuses it is not in this file --
		// or the rule broke the route. Both mean the same thing here: take it back out. A
		// change that did not help is not worth the risk of having made it.
		self::remove();

		wp_send_json_error(
			array(
				'message' => sprintf(
					/* translators: %d: the HTTP status still coming back. */
					__( 'That did not do it — the link answered %d, so the rule is not one this file can reach. It is your host or your CDN. Ask them to stop filtering callers by name for this site. The change has been undone and nothing is left behind.', 'zorem-mcp' ),
					(int) ( $normal['ok'] ? $check['code'] : $normal['code'] )
				),
				'present' => false,
			)
		);
	}
}
