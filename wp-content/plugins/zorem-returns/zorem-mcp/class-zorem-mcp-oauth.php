<?php
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- every query in this file binds its values through wpdb->prepare(); the parameter the sniff flags is the table prefix or a placeholder string, both of which are safe.
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- this library owns the 'zorem-mcp' domain and loads it itself; Plugin Check compares against the host plugin's folder name, which is a different plugin every time this folder ships.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- this package is shared across the Zorem plugin family; its own zorem_mcp_ prefix is the point.
/**
 * Zorem MCP - signing in, so one person can be turned off without the rest.
 *
 * WHAT PROBLEM THIS SOLVES
 *
 * The connection link carries its own credential, which is what lets it work from claude.ai
 * and from a phone. One credential, handed to whoever needs it -- and that is exactly why a
 * merchant cannot take one person's access away. Everybody sends the same secret, so the
 * server cannot tell them apart, and cutting one means replacing the secret and cutting all
 * of them. There is no way round that: a key given to two people cannot be taken back from
 * one of them.
 *
 * So the link stops being the credential. It stays one link, the same for everyone, but now
 * it is an address: whoever adds it signs in once as themselves, and gets a token of their
 * own. Revoking that token touches nothing else.
 *
 * WHO IS ALLOWED THROUGH
 *
 * Only accounts that can manage the store. This is the shop owner's connection and its tools
 * act on the whole shop; a customer account signing in here would be handed a door meant for
 * staff. Zorem Returns runs its own OAuth for customers, on its own endpoint, and the two
 * are deliberately not the same server.
 *
 * WHY THE OLD LINK STILL WORKS
 *
 * Because merchants have already connected with it, and an upgrade that silently disconnects
 * an assistant is worse than an upgrade that leaves an old mechanism running. The server
 * accepts both: a token in the URL, and a token issued here. The screen offers the new one.
 *
 * SECURITY
 *
 * PKCE with S256 is required -- no plaintext challenge is accepted. Authorization codes are
 * single-use and live ten minutes. Access tokens are stored only as hashes, by
 * Zorem_MCP_Keys, so this table gives up nothing if it is read.
 *
 * @package Zorem_MCP
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorem_MCP_OAuth {

	/**
	 * How long an authorization code is worth anything. Long enough for a person to finish
	 * signing in, short enough that one left in a browser history is useless.
	 */
	const CODE_TTL = 600;

	/**
	 * How long a half-finished consent survives.
	 */
	/*
	 * An hour, not fifteen minutes.
	 *
	 * The clock starts when the AI app opens the browser and does not stop while the
	 * merchant hunts for the password to an account they last used on a different machine.
	 * Fifteen minutes turned that into "This sign-in took too long and has expired", on a
	 * page with nothing to click -- and the AI app, having handed off to the browser, does
	 * not offer to try again either. The merchant is simply stuck.
	 *
	 * The window is not a security control here: the nonce is single-use, unguessable, and
	 * useless without the PKCE verifier the app kept. Making it long enough to be usable
	 * costs nothing.
	 */
	const CONSENT_TTL = 3600;

	/*
	 * How long a finished sign-in is remembered, only so that pressing Allow twice can be
	 * told apart from waiting too long. They need very different advice.
	 */
	const DONE_TTL = 900;

	/**
	 * The query argument that carries the consent screen on the front end.
	 */
	const CONSENT_ARG = 'zorem_mcp_consent';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'parse_request', array( __CLASS__, 'handle_well_known' ), 1 );
		add_action( 'init', array( __CLASS__, 'handle_consent' ), 1 );
	}

	/**
	 * Whether signing in is available at all.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'Zorem_MCP_Keys' )
			&& Zorem_MCP_Keys::available()
			&& Zorem_MCP_Settings::enabled();
	}

	/**
	 * The endpoint people connect to. No credential in it -- that is the point.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( Zorem_MCP_Server::REST_NS . '/mcp' );
	}

	/**
	 * This authorization server's own address.
	 *
	 * Path-scoped rather than the site root. RFC 8414 allows it, and here it is necessary:
	 * a store can run more than one MCP endpoint, and an issuer at the root announces a
	 * sign-in service on behalf of all of them -- including ones that have none.
	 *
	 * @return string
	 */
	protected static function issuer() {
		return rest_url( Zorem_MCP_Server::REST_NS );
	}

	/*
	|--------------------------------------------------------------------------
	| Discovery
	|--------------------------------------------------------------------------
	*/

	/**
	 * Serve the two discovery documents, each under its own path.
	 *
	 * @return void
	 */
	public static function handle_well_known() {
		if ( ! self::available() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- sanitised on the next line.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$uri = trim( (string) $uri, '/' );

		$issuer_path = trim( (string) wp_parse_url( self::issuer(), PHP_URL_PATH ), '/' );

		if ( '.well-known/oauth-authorization-server/' . $issuer_path === $uri ) {
			self::json( self::server_metadata() );
		}

		// Each endpoint gets its own document. A store can publish two -- tracking and the
		// whole store -- and a client that pastes one of them must be told about that one:
		// the resource named in the document has to be the address the client actually
		// asked about, or it will decide the answer was not for it.
		foreach ( self::resources() as $resource ) {
			$path = trim( (string) wp_parse_url( $resource, PHP_URL_PATH ), '/' );

			if ( '.well-known/oauth-protected-resource/' . $path === $uri ) {
				self::json( self::resource_metadata( $resource ) );
			}
		}
	}

	/**
	 * Every endpoint on this store that signing in protects.
	 *
	 * One, since the store-wide tools moved onto the same link. Kept as a list because the
	 * discovery and challenge code reads it that way, and a second endpoint is the sort of
	 * thing that comes back.
	 *
	 * @return string[]
	 */
	public static function resources() {
		return array( self::endpoint_url() );
	}

	/**
	 * What this authorization server supports.
	 *
	 * @return array
	 */
	public static function server_metadata() {
		$base = self::issuer();

		return array(
			'issuer'                                => $base,
			'authorization_endpoint'                => $base . '/authorize',
			'token_endpoint'                        => $base . '/token',
			'registration_endpoint'                 => $base . '/register',
			'revocation_endpoint'                   => $base . '/revoke',
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
		);
	}

	/**
	 * What the protected endpoint is, and who guards it.
	 *
	 * @param string $resource The endpoint being described. Defaults to the tracking one.
	 * @return array
	 */
	public static function resource_metadata( $resource = '' ) {
		return array(
			'resource'                 => '' !== $resource ? $resource : self::endpoint_url(),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/**
	 * The header that tells an unauthenticated caller where to sign in.
	 *
	 * Points at the document for the address the caller actually asked for. A store can
	 * publish two, and answering a knock on one door with the other door's paperwork sends
	 * the client somewhere it never wanted to go.
	 *
	 * @return void
	 */
	public static function challenge() {
		if ( headers_sent() || ! self::available() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- sanitised on the next line, and only ever compared.
		$asked = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$asked = trim( (string) $asked, '/' );

		$resource_path = trim( (string) wp_parse_url( self::endpoint_url(), PHP_URL_PATH ), '/' );

		foreach ( self::resources() as $resource ) {
			$path = trim( (string) wp_parse_url( $resource, PHP_URL_PATH ), '/' );

			if ( $asked === $path ) {
				$resource_path = $path;
				break;
			}
		}

		header(
			sprintf(
				'WWW-Authenticate: Bearer resource_metadata="%s"',
				home_url( '/.well-known/oauth-protected-resource/' . $resource_path )
			)
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Endpoints
	|--------------------------------------------------------------------------
	*/

	/**
	 * Register the OAuth routes.
	 *
	 * All four are deliberately open: that is how the handshake works for a client that has
	 * no session yet. Every one of them enforces its own rules inside -- PKCE, single-use
	 * codes, a registered redirect, and a signed-in account that can manage the store.
	 *
	 * @return void
	 */
	public static function register_routes() {
		if ( ! self::available() ) {
			return;
		}

		$ns = Zorem_MCP_Server::REST_NS;

		/*
		 * These are the OAuth 2.0 endpoints (RFC 6749 / RFC 7591). They MUST be reachable without
		 * prior authentication: the caller is a client obtaining authorization, so there is no
		 * WordPress user to check yet. Security is enforced inside each handler instead -- the
		 * registered-client check, the redirect_uri allow-list, PKCE, and one-time authorization
		 * codes. The open permission_callback is therefore correct and intended; each is annotated
		 * for the scanner below.
		 */
		$open = '__return_true';

		register_rest_route(
			$ns,
			'/register',
			array(
				'methods'  => 'POST',
				'callback' => array( __CLASS__, 'handle_register' ),
				// nosemgrep: audit.php.wp.security.rest-route.permission-callback.return-true -- public OAuth 2.0 endpoint by design; the handler validates the request.
				'permission_callback' => $open,
			)
		);

		register_rest_route(
			$ns,
			'/authorize',
			array(
				'methods'  => 'GET',
				'callback' => array( __CLASS__, 'handle_authorize' ),
				// nosemgrep: audit.php.wp.security.rest-route.permission-callback.return-true -- public OAuth 2.0 endpoint by design; the handler validates the request.
				'permission_callback' => $open,
			)
		);

		register_rest_route(
			$ns,
			'/token',
			array(
				'methods'  => 'POST',
				'callback' => array( __CLASS__, 'handle_token' ),
				// nosemgrep: audit.php.wp.security.rest-route.permission-callback.return-true -- public OAuth 2.0 endpoint by design; the handler validates the code/PKCE.
				'permission_callback' => $open,
			)
		);

		register_rest_route(
			$ns,
			'/revoke',
			array(
				'methods'  => 'POST',
				'callback' => array( __CLASS__, 'handle_revoke' ),
				// nosemgrep: audit.php.wp.security.rest-route.permission-callback.return-true -- public OAuth 2.0 endpoint by design; the handler validates the token.
				'permission_callback' => $open,
			)
		);
	}

	/**
	 * An AI client introducing itself (RFC 7591).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_register( $request ) {
		global $wpdb;

		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_client_metadata' ), 400 );
		}

		$redirects = array_values( array_filter( array_map( array( __CLASS__, 'clean_redirect' ), (array) ( isset( $body['redirect_uris'] ) ? $body['redirect_uris'] : array() ) ) ) );

		if ( empty( $redirects ) ) {
			return new WP_REST_Response(
				array(
					'error'             => 'invalid_redirect_uri',
					'error_description' => 'redirect_uris is required',
				),
				400
			);
		}

		$name   = sanitize_text_field( isset( $body['client_name'] ) ? (string) $body['client_name'] : 'AI assistant' );
		$method = isset( $body['token_endpoint_auth_method'] ) ? (string) $body['token_endpoint_auth_method'] : 'none';
		$method = in_array( $method, array( 'none', 'client_secret_post' ), true ) ? $method : 'none';

		$client_id = 'zmcp_client_' . wp_generate_password( 24, false, false );
		$secret    = ( 'none' === $method ) ? '' : wp_generate_password( 40, false, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- insert into this package's own table.
		$wpdb->insert(
			Zorem_MCP_Keys::clients_table(),
			array(
				'client_id'          => $client_id,
				'client_secret_hash' => '' !== $secret ? hash( 'sha256', $secret ) : '',
				'client_name'        => substr( $name, 0, 191 ),
				'redirect_uris'      => wp_json_encode( $redirects ),
				'auth_method'        => $method,
				'created_at'         => current_time( 'mysql', 1 ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$out = array(
			'client_id'                  => $client_id,
			'client_name'                => $name,
			'redirect_uris'              => $redirects,
			// What the client is actually granted, which since refresh tokens arrived is both.
			// This still said 'authorization_code' alone -- so a client that asked for a
			// refresh token was told, in the one reply that is meant to settle the question,
			// that it had not been given one. The metadata said otherwise. Two answers to the
			// same question is worse than either answer alone.
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $method,
		);

		if ( '' !== $secret ) {
			$out['client_secret'] = $secret;
		}

		return new WP_REST_Response( $out, 201 );
	}

	/**
	 * Start the sign-in, on the front end rather than in REST.
	 *
	 * REST is meant to be stateless, and several hosts strip Set-Cookie from a REST reply
	 * to keep it that way. A login that happens inside REST therefore appears to succeed and
	 * then be forgotten on the very next click. Bouncing to an ordinary front-end URL keeps
	 * the cookie, which is the whole reason this detour exists.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return void
	 */
	public static function handle_authorize( $request ) {
		$params                      = $request->get_query_params();
		$params[ self::CONSENT_ARG ] = '1';

		nocache_headers();
		wp_safe_redirect( add_query_arg( $params, home_url( '/' ) ) );
		exit;
	}

	/**
	 * The consent screen and the decision it collects.
	 *
	 * @return void
	 */
	public static function handle_consent() {
		if ( ! self::available() ) {
			return;
		}

		// This screen is reached by an inbound OAuth 2.0 redirect from the AI client, which carries no
		// WordPress nonce. These routing values are read with filter_input() so no request superglobal
		// is touched and each is sanitised at the point of input. The one state-changing step is the
		// consent form, which IS nonce-verified in handle_decision().
		$posted = sanitize_text_field( (string) filter_input( INPUT_POST, 'zorem_mcp_consent_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		$asked  = ! empty( filter_input( INPUT_GET, self::CONSENT_ARG, FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		$after  = sanitize_text_field( (string) filter_input( INPUT_GET, 'zorem_mcp_after_login', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );

		if ( '' !== $after ) {
			$saved = get_transient( 'zorem_mcp_consent_' . $after );

			if ( $saved ) {
				self::render_consent( $after, $saved['client_name'], '' );
			}
		}

		if ( '' !== $posted ) {
			self::handle_decision( $posted );
			return;
		}

		if ( $asked ) {
			self::begin_consent();
		}
	}

	/**
	 * Check the request, remember it, and show the screen.
	 *
	 * @return void
	 */
	protected static function begin_consent() {
		// Inbound OAuth 2.0 authorization request, redirected here by the AI client. Read through
		// filter_input() so no request superglobal is touched; these values only decide what the
		// consent screen shows. The state-changing step is the nonce-verified form in handle_decision().
		$client_id = sanitize_text_field( (string) filter_input( INPUT_GET, 'client_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		$redirect  = self::clean_redirect( (string) filter_input( INPUT_GET, 'redirect_uri', FILTER_SANITIZE_URL ) );
		$type      = sanitize_text_field( (string) filter_input( INPUT_GET, 'response_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		$state     = sanitize_text_field( (string) filter_input( INPUT_GET, 'state', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		$challenge = sanitize_text_field( (string) filter_input( INPUT_GET, 'code_challenge', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		$method    = sanitize_text_field( (string) filter_input( INPUT_GET, 'code_challenge_method', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );

		$client = self::client( $client_id );

		if ( ! $client ) {
			wp_die( esc_html__( 'That AI app is not registered with this store. Remove the connection and add it again.', 'zorem-mcp' ) );
		}

		if ( ! in_array( $redirect, (array) $client['redirect_uris'], true ) ) {
			wp_die( esc_html__( 'That AI app asked to be sent somewhere it has not registered. Nothing has been shared.', 'zorem-mcp' ) );
		}

		// Past this point the client is known and its return address is trusted, so problems
		// can be reported to it rather than dead-ending on a WordPress error page.
		if ( 'code' !== $type ) {
			self::fail( $redirect, 'unsupported_response_type', $state );
		}

		if ( '' === $challenge || 'S256' !== $method ) {
			self::fail( $redirect, 'invalid_request', $state );
		}

		$nonce = wp_generate_password( 32, false, false );

		set_transient(
			'zorem_mcp_consent_' . $nonce,
			array(
				'client_id'   => $client_id,
				'client_name' => $client['client_name'],
				'redirect'    => $redirect,
				'state'       => $state,
				'challenge'   => $challenge,
			),
			self::CONSENT_TTL
		);

		self::render_consent( $nonce, $client['client_name'], '' );
	}

	/**
	 * Sign the person in if they typed credentials, then take their answer.
	 *
	 * @param string $nonce Consent nonce.
	 * @return void
	 */
	protected static function handle_decision( $nonce ) {
		$saved = get_transient( 'zorem_mcp_consent_' . $nonce );

		if ( ! $saved ) {
			// Two very different situations behind one missing transient, and the advice for
			// each is the opposite of the other's: one person should go back to their app
			// because it already has what it needs, the other should ask the app to start
			// over. Telling both of them "expired" sent half of them round again for nothing.
			self::render_dead_end( (bool) get_transient( 'zorem_mcp_done_' . $nonce ) );
		}

		// CSRF guard: the consent form carries a WordPress nonce tied to this exact request,
		// alongside the transient-backed token verified above. A forged or stale POST stops here.
		$wpnonce = isset( $_POST['zorem_mcp_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['zorem_mcp_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $wpnonce, 'zorem_mcp_consent_' . $nonce ) ) {
			self::render_dead_end( (bool) get_transient( 'zorem_mcp_done_' . $nonce ) );
			exit; // render_dead_end() already exits; making the guard terminate explicitly for scanners.
		}

		$decision = isset( $_POST['decision'] ) ? sanitize_text_field( wp_unslash( $_POST['decision'] ) ) : '';
		$login    = isset( $_POST['zorem_mcp_user'] ) ? sanitize_text_field( wp_unslash( $_POST['zorem_mcp_user'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password goes to wp_signon() exactly as typed; sanitising it would change it.
		$password = isset( $_POST['zorem_mcp_pass'] ) ? (string) wp_unslash( $_POST['zorem_mcp_pass'] ) : '';

		if ( ! is_user_logged_in() && '' !== $login && '' !== $password ) {
			$signed = wp_signon(
				array(
					'user_login'    => $login,
					'user_password' => $password,
					'remember'      => true,
				),
				is_ssl()
			);

			if ( is_wp_error( $signed ) ) {
				self::render_consent( $nonce, $saved['client_name'], __( 'That username or password was not right.', 'zorem-mcp' ) );
			}

			// Round-trip through a fresh GET so the browser is holding the new cookie before
			// the next screen is drawn.
			nocache_headers();
			wp_safe_redirect(
				add_query_arg(
					array(
						self::CONSENT_ARG       => '1',
						'zorem_mcp_after_login' => $nonce,
					),
					home_url( '/' )
				)
			);
			exit;
		}

		if ( ! is_user_logged_in() ) {
			self::render_consent( $nonce, $saved['client_name'], __( 'Sign in to continue.', 'zorem-mcp' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			self::render_consent(
				$nonce,
				$saved['client_name'],
				__( 'This account cannot manage the store, so it cannot connect an assistant here. Sign in with an account that can.', 'zorem-mcp' )
			);
		}

		if ( 'allow' !== $decision ) {
			if ( '' === $decision ) {
				self::render_consent( $nonce, $saved['client_name'], '' );
			}

			delete_transient( 'zorem_mcp_consent_' . $nonce );
			self::fail( $saved['redirect'], 'access_denied', $saved['state'] );
		}

		delete_transient( 'zorem_mcp_consent_' . $nonce );

		// Remembered just long enough to recognise a second press of the same button.
		set_transient( 'zorem_mcp_done_' . $nonce, 1, self::DONE_TTL );

		$code = wp_generate_password( 40, false, false );

		set_transient(
			'zorem_mcp_code_' . $code,
			array(
				'client_id' => $saved['client_id'],
				'user_id'   => get_current_user_id(),
				'redirect'  => $saved['redirect'],
				'challenge' => $saved['challenge'],
			),
			self::CODE_TTL
		);

		$back = add_query_arg(
			array_filter(
				array(
					'code'  => $code,
					'state' => $saved['state'],
				),
				static function ( $v ) {
					return '' !== $v;
				}
			),
			$saved['redirect']
		);

		nocache_headers();
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the client's own callback, checked against its registered list above; wp_safe_redirect would block it for being off-site.
		wp_redirect( $back );
		exit;
	}

	/**
	 * Exchange the code for a token of this person's own.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_token( $request ) {
		$grant = (string) $request->get_param( 'grant_type' );

		if ( 'refresh_token' === $grant ) {
			return self::handle_refresh( $request );
		}

		if ( 'authorization_code' !== $grant ) {
			return new WP_REST_Response( array( 'error' => 'unsupported_grant_type' ), 400 );
		}

		$code     = (string) $request->get_param( 'code' );
		$saved    = get_transient( 'zorem_mcp_code_' . $code );
		$bad_code = array(
			'error'             => 'invalid_grant',
			'error_description' => 'Authorization code invalid or expired.',
		);

		if ( ! $saved ) {
			return new WP_REST_Response( $bad_code, 400 );
		}

		// One use only, whatever happens next.
		delete_transient( 'zorem_mcp_code_' . $code );

		if ( $saved['client_id'] !== (string) $request->get_param( 'client_id' )
			|| $saved['redirect'] !== (string) $request->get_param( 'redirect_uri' ) ) {
			return new WP_REST_Response( $bad_code, 400 );
		}

		$client = self::client( $saved['client_id'] );

		if ( ! $client ) {
			return new WP_REST_Response( array( 'error' => 'invalid_client' ), 401 );
		}

		if ( 'client_secret_post' === $client['auth_method'] ) {
			$secret = (string) $request->get_param( 'client_secret' );

			if ( '' === $secret || ! hash_equals( $client['client_secret_hash'], hash( 'sha256', $secret ) ) ) {
				return new WP_REST_Response( array( 'error' => 'invalid_client' ), 401 );
			}
		}

		// PKCE: the client proves it is the same one that started this, by producing the
		// string its opening challenge was the hash of.
		$verifier = (string) $request->get_param( 'code_verifier' );
		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		if ( '' === $verifier || ! hash_equals( $saved['challenge'], $expected ) ) {
			return new WP_REST_Response(
				array(
					'error'             => 'invalid_grant',
					'error_description' => 'PKCE verification failed.',
				),
				400
			);
		}

		$issued = Zorem_MCP_Keys::issue( (int) $saved['user_id'], $client['client_name'], $client['client_name'] );

		if ( ! $issued ) {
			return new WP_REST_Response( array( 'error' => 'server_error' ), 500 );
		}

		return self::token_response( $issued );
	}

	/**
	 * Swap a refresh token for a fresh pair.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	protected static function handle_refresh( $request ) {
		$presented = (string) $request->get_param( 'refresh_token' );
		$row       = Zorem_MCP_Keys::match_refresh( $presented );

		// invalid_grant is the answer a client is built to act on: it clears what it holds
		// and starts a fresh sign-in. Anything vaguer and the app just stops working.
		if ( ! $row ) {
			return new WP_REST_Response(
				array(
					'error'             => 'invalid_grant',
					'error_description' => 'That connection is no longer valid. Sign in again.',
				),
				400
			);
		}

		$rotated = Zorem_MCP_Keys::rotate( (int) $row['id'] );

		if ( ! $rotated ) {
			return new WP_REST_Response( array( 'error' => 'invalid_grant' ), 400 );
		}

		return self::token_response( $rotated );
	}

	/**
	 * The token reply, in the shape a client can act on.
	 *
	 * WHY THERE IS NO expires_in
	 *
	 * Because this said `expires_in: 0`, and zero does not mean "never" -- it means the
	 * token expired the instant it was handed over. A client that reads it does the correct
	 * thing with that: it refuses to use the token and reaches for a refresh token, which
	 * this server did not issue either. VS Code did exactly that and then sat waiting on an
	 * initialize it would never send.
	 *
	 * These tokens genuinely do not expire -- they last until the merchant revokes them --
	 * and RFC 6749 says a server that cannot state a lifetime should simply leave the field
	 * out. So it is left out, and a refresh token is issued for the clients that want one.
	 *
	 * @param array $issued Result of Zorem_MCP_Keys::issue() or ::rotate().
	 * @return WP_REST_Response
	 */
	protected static function token_response( $issued ) {
		$out = array(
			'access_token' => $issued['token'],
			'token_type'   => 'Bearer',
		);

		if ( ! empty( $issued['refresh'] ) ) {
			$out['refresh_token'] = $issued['refresh'];
		}

		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * A client handing its own token back (RFC 7009).
	 *
	 * Answers 200 whatever happened, as the specification requires: telling a caller that a
	 * token it offered was unknown would turn this into a way to test tokens.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_revoke( $request ) {
		$token = (string) $request->get_param( 'token' );

		if ( '' !== $token ) {
			$match = Zorem_MCP_Keys::match( $token );

			if ( $match && ! $match['revoked'] ) {
				Zorem_MCP_Keys::revoke( $match['id'] );
			}
		}

		return new WP_REST_Response( null, 200 );
	}

	/*
	|--------------------------------------------------------------------------
	| The screen a person actually sees
	|--------------------------------------------------------------------------
	*/

	/**
	 * Draw the sign-in and consent screen, and stop.
	 *
	 * Deliberately plain and self-contained: it is shown to somebody who arrived from an AI
	 * app and needs to recognise their own store, not admire it. It carries no theme, so a
	 * broken or half-loaded theme cannot break the one screen that has to work.
	 *
	 * @param string $nonce  Consent nonce.
	 * @param string $client The AI app's name, as it registered it.
	 * @param string $error  Something to tell them, or an empty string.
	 * @return void
	 */
	/**
	 * The end of a sign-in that cannot be continued here.
	 *
	 * WHY THIS IS NOT wp_die()
	 *
	 * Because wp_die() is a full stop. The merchant is looking at a browser tab their AI app
	 * opened; the app has already handed off and is waiting, and nothing on a WordPress error
	 * page tells them how to make it ask again. They were told "start again from your AI app"
	 * and had no idea where in the app to start.
	 *
	 * So this says which button, in each app, actually restarts it.
	 *
	 * @param bool $already True when this sign-in already finished and the button was pressed
	 *                      a second time -- a different problem needing the opposite advice.
	 * @return void Never returns; the page ends here.
	 */
	protected static function render_dead_end( $already ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		status_header( $already ? 200 : 400 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$store = get_bloginfo( 'name' );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<title><?php esc_html_e( 'Connect your AI assistant', 'zorem-mcp' ); ?></title>
			<style>
				:root { color-scheme: light; }
				* { box-sizing: border-box; }
				body {
					margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
					background: #f4f6f9; color: #121822; padding: 24px;
					font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
				}
				.box { width: 100%; max-width: 440px; background: #fff; border: 1px solid #dbe1ea; border-radius: 14px; padding: 32px; }
				h1 { font-size: 20px; line-height: 1.35; margin: 0 0 8px; }
				p { margin: 0 0 16px; color: #4a5666; }
				h2 { font-size: 13px; letter-spacing: .04em; text-transform: uppercase; color: #78849a; margin: 24px 0 10px; }
				ul { margin: 0; padding: 0; list-style: none; }
				li { padding: 9px 0; border-bottom: 1px solid #eef1f6; font-size: 14px; }
				li:last-child { border-bottom: 0; }
				b { color: #121822; }
				.note { font-size: 12.5px; color: #78849a; margin: 22px 0 0; }
			</style>
		</head>
		<body>
			<div class="box">
				<?php if ( $already ) : ?>

					<h1><?php esc_html_e( 'This is already connected', 'zorem-mcp' ); ?></h1>
					<p>
						<?php
						printf(
							/* translators: %s: the store's name. */
							esc_html__( 'You approved this sign-in a moment ago, so %s already sent your AI app what it needs. Nothing else is needed here.', 'zorem-mcp' ),
							esc_html( $store )
						);
						?>
					</p>
					<p><?php esc_html_e( 'Close this tab and go back to the app.', 'zorem-mcp' ); ?></p>

				<?php else : ?>

					<h1><?php esc_html_e( 'This sign-in expired', 'zorem-mcp' ); ?></h1>
					<p><?php esc_html_e( 'It has to be started by the app, not from here — so ask the app to connect again. Nothing was shared, and nothing is broken.', 'zorem-mcp' ); ?></p>

					<h2><?php esc_html_e( 'Where to press', 'zorem-mcp' ); ?></h2>
					<ul>
						<li><b>claude.ai</b> — <?php esc_html_e( 'Settings, Connectors, then Connect on this store.', 'zorem-mcp' ); ?></li>
						<li><b>Claude Desktop</b> — <?php esc_html_e( 'Settings, Connectors, then Connect. Or restart the app.', 'zorem-mcp' ); ?></li>
						<li><b>Cursor</b> — <?php esc_html_e( 'Settings, MCP, then the refresh arrow on this server.', 'zorem-mcp' ); ?></li>
					</ul>

					<p class="note"><?php esc_html_e( 'If the app still does not ask, remove the connection there and add it again — your link has not changed.', 'zorem-mcp' ); ?></p>

				<?php endif; ?>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	protected static function render_consent( $nonce, $client, $error ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$user  = is_user_logged_in() ? wp_get_current_user() : null;
		$store = get_bloginfo( 'name' );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<title><?php esc_html_e( 'Connect your AI assistant', 'zorem-mcp' ); ?></title>
			<style>
				:root { color-scheme: light; }
				* { box-sizing: border-box; }
				body {
					margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
					background: #f4f6f9; color: #121822; padding: 24px;
					font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
				}
				.box { width: 100%; max-width: 400px; background: #fff; border: 1px solid #dbe1ea; border-radius: 14px; padding: 32px; }
				h1 { font-size: 20px; line-height: 1.35; margin: 0 0 8px; }
				p { margin: 0 0 16px; color: #4a5666; }
				.who { font-size: 13px; color: #4a5666; background: #eef1f6; border-radius: 8px; padding: 10px 12px; margin: 0 0 20px; }
				label { display: block; font-size: 13px; font-weight: 600; margin: 0 0 6px; }
				input[type=text], input[type=password] {
					width: 100%; padding: 9px 12px; margin: 0 0 14px; font-size: 15px;
					border: 1px solid #c3ccda; border-radius: 8px; background: #fff; color: inherit;
				}
				input:focus { outline: 2px solid #35528f; outline-offset: 1px; border-color: #35528f; }
				.row { display: flex; gap: 10px; margin-top: 4px; }
				button { flex: 1; padding: 10px 14px; font: inherit; font-weight: 600; border-radius: 8px; cursor: pointer; }
				.allow { background: #35528f; border: 1px solid #35528f; color: #fff; }
				.allow:hover { background: #2b436f; }
				.deny { background: #fff; border: 1px solid #c3ccda; color: #4a5666; }
				.deny:hover { border-color: #78849a; }
				.err { background: #f8e6e1; color: #8f3722; border-radius: 8px; padding: 10px 12px; margin: 0 0 18px; font-size: 14px; }
				.note { font-size: 12.5px; color: #78849a; margin: 18px 0 0; }
			</style>
		</head>
		<body>
			<div class="box">
				<h1>
					<?php
					printf(
						/* translators: 1: the AI app's name, 2: the store's name. */
						esc_html__( 'Connect %1$s to %2$s', 'zorem-mcp' ),
						esc_html( $client ),
						esc_html( $store )
					);
					?>
				</h1>

				<?php if ( '' !== $error ) : ?>
					<p class="err"><?php echo esc_html( $error ); ?></p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( home_url( '/?' . self::CONSENT_ARG . '=1' ) ); ?>">
					<input type="hidden" name="zorem_mcp_consent_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
					<?php wp_nonce_field( 'zorem_mcp_consent_' . $nonce, 'zorem_mcp_wpnonce' ); ?>

					<?php if ( $user ) : ?>
						<p class="who">
							<?php
							printf(
								/* translators: %s: the signed-in account's email address. */
								esc_html__( 'Signed in as %s', 'zorem-mcp' ),
								esc_html( $user->user_email )
							);
							?>
						</p>
						<p>
							<?php esc_html_e( 'It will be able to do what your own account can do on this store, and you can turn it off at any time from the AI Assistant screen.', 'zorem-mcp' ); ?>
						</p>
						<div class="row">
							<button class="deny" type="submit" name="decision" value="deny"><?php esc_html_e( 'Cancel', 'zorem-mcp' ); ?></button>
							<button class="allow" type="submit" name="decision" value="allow"><?php esc_html_e( 'Connect', 'zorem-mcp' ); ?></button>
						</div>
					<?php else : ?>
						<p><?php esc_html_e( 'Sign in with your store account to connect it.', 'zorem-mcp' ); ?></p>
						<label for="zorem_mcp_user"><?php esc_html_e( 'Username or email', 'zorem-mcp' ); ?></label>
						<input type="text" id="zorem_mcp_user" name="zorem_mcp_user" autocomplete="username" autocapitalize="none" required />
						<label for="zorem_mcp_pass"><?php esc_html_e( 'Password', 'zorem-mcp' ); ?></label>
						<input type="password" id="zorem_mcp_pass" name="zorem_mcp_pass" autocomplete="current-password" required />
						<div class="row">
							<button class="allow" type="submit"><?php esc_html_e( 'Sign in', 'zorem-mcp' ); ?></button>
						</div>
					<?php endif; ?>
				</form>

				<p class="note"><?php esc_html_e( 'Only accounts that can manage this store can connect an assistant.', 'zorem-mcp' ); ?></p>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Clean one redirect URI the way OAuth means it, not the way HTML means it.
	 *
	 * WHY NOT esc_url_raw()
	 *
	 * Because it silently deleted half the AI clients. esc_url_raw() exists to make a URL
	 * safe to print in a page, so it drops any scheme outside wp_allowed_protocols() -- a
	 * list of http, https, ftp, mailto and a few others, written years before desktop apps
	 * registered their own. Cursor calls back to cursor://, VS Code to vscode://, Windsurf
	 * to windsurf://. All three came out of esc_url_raw() as an empty string, so
	 * registration answered "redirect_uris is required" and the app could never connect.
	 * claude.ai uses https, which is why the browser worked and nothing else did.
	 *
	 * What a redirect URI has to be, per RFC 6749 §3.1.2, is an absolute URI with no
	 * fragment. That is what this checks. It is not a weaker check than the old one -- the
	 * old one was not checking this at all -- and the value is never printed as a URL: it is
	 * compared for an exact match against the list the client registered, and handed to
	 * wp_redirect(), which does its own escaping.
	 *
	 * @param string $uri Candidate redirect URI.
	 * @return string The URI, or an empty string if it is not usable.
	 */
	protected static function clean_redirect( $uri ) {
		$uri = trim( (string) $uri );

		if ( '' === $uri || strlen( $uri ) > 2000 ) {
			return '';
		}

		// No whitespace or control characters: those are how a second URL gets smuggled
		// into a header.
		if ( preg_match( '/[\x00-\x20\x7F"<>\\^`{|}]/', $uri ) ) {
			return '';
		}

		$parts = wp_parse_url( $uri );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) ) {
			return '';
		}

		// A fragment is forbidden outright -- the browser never sends it to the server, so a
		// client relying on one would be waiting for something that cannot arrive.
		if ( isset( $parts['fragment'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] );

		// A scheme is a scheme: letter first, then letters, digits, + - . (RFC 3986 §3.1).
		if ( ! preg_match( '/^[a-z][a-z0-9+.\-]*$/', $scheme ) ) {
			return '';
		}

		// javascript:, data: and vbscript: are the ones that turn a redirect into an attack.
		// Everything else is somebody's desktop app.
		if ( in_array( $scheme, array( 'javascript', 'data', 'vbscript', 'file', 'about', 'blob' ), true ) ) {
			return '';
		}

		// http:// is only for the loopback, which is where a desktop app listens. Anywhere
		// else it would send a code across the network in the clear.
		if ( 'http' === $scheme ) {
			$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

			if ( ! in_array( $host, array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true ) ) {
				return '';
			}
		}

		return $uri;
	}

	/**
	 * A registered client, with its redirect list decoded.
	 *
	 * @param string $client_id Client id.
	 * @return array|null
	 */
	protected static function client( $client_id ) {
		global $wpdb;

		if ( '' === (string) $client_id || ! class_exists( 'Zorem_MCP_Keys' ) ) {
			return null;
		}

		$table = Zorem_MCP_Keys::clients_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is this package's own; the id is bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s", $client_id ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		$decoded               = json_decode( (string) $row['redirect_uris'], true );
		$row['redirect_uris']  = is_array( $decoded ) ? $decoded : array();

		return $row;
	}

	/**
	 * Send the client back to its own callback with an error, and stop.
	 *
	 * @param string $redirect Registered callback.
	 * @param string $error    OAuth error code.
	 * @param string $state    The client's state, echoed back.
	 * @return void
	 */
	protected static function fail( $redirect, $error, $state ) {
		$url = add_query_arg(
			array_filter(
				array(
					'error' => $error,
					'state' => $state,
				),
				static function ( $v ) {
					return '' !== $v;
				}
			),
			$redirect
		);

		nocache_headers();
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the client's own registered callback; wp_safe_redirect would block it for being off-site.
		wp_redirect( $url );
		exit;
	}

	/**
	 * Answer with JSON and stop.
	 *
	 * @param array $data Payload.
	 * @return void
	 */
	protected static function json( $data ) {
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );

		echo wp_json_encode( $data );
		exit;
	}
}
