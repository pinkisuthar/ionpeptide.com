<?php
/**
 * Class for handling the WPCode library authentication.
 *
 * @package WPCode
 */

/**
 * Class WPCode_Library_Auth.
 */
class WPCode_Library_Auth {
	/**
	 * The base api URL.
	 *
	 * @var string
	 */
	public $library_url = 'https://library.wpcode.com';

	/**
	 * Is the current plugin authenticated with the WPCode Library?
	 *
	 * @var bool
	 */
	private $has_auth;

	/**
	 * The api key used for authenticated requests to the library.
	 *
	 * @var string
	 */
	private $auth_key;

	/**
	 * The auth data from the db.
	 *
	 * @var array
	 */
	private $auth_data;

	/**
	 * Passkey connection data from the connect request, merged by save_auth_data().
	 *
	 * Passed here rather than as a save_auth_data() argument: Multisite addon 1.0.5 and older override
	 * save_auth_data() with four arguments, and a fifth would make that override a fatal error on PHP 8.
	 *
	 * @var array
	 */
	protected $connect_passkey_data = array();

	/**
	 * Library auth constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_wpcode_library_store_auth', array( $this, 'store_auth_key' ) );
		add_action( 'wp_ajax_wpcode_library_delete_auth', array( $this, 'delete_auth' ) );
	}

	/**
	 * Ajax handler that returns the auth url used to start the Connect process.
	 *
	 * @return string
	 */
	public function auth_url() {

		if ( $this->has_auth() ) {
			return '';
		}

		$site_name = get_bloginfo( 'name' );
		if ( empty( $site_name ) ) {
			$site_name = __( 'Your WordPress Site', 'insert-headers-and-footers' );
		}

		// This is needed, so we don't run into issues with special characters.
		// Base64 encode without padding for better compatibility between PHP versions.
		$site_name = rtrim( strtr( base64_encode( $site_name ), '+/', '-_' ), '=' );
		$ajax_url  = rtrim( strtr( base64_encode( admin_url( 'admin-ajax.php' ) ), '+/', '-_' ), '=' );

		$auth_url = add_query_arg(
			array(
				'site'    => $site_name,
				'version' => WPCODE_VERSION,
				'ajax'    => $ajax_url,
			),
			$this->get_api_url( 'connect' )
		);

		return $auth_url;
	}

	/**
	 * Get the full URL to an API endpoint by passing the path.
	 *
	 * @param string $path The path for the API endpoint.
	 *
	 * @return string
	 */
	public function get_api_url( $path ) {
		return trailingslashit( $this->library_url ) . 'api/' . $path;
	}

	/**
	 * Ajax handler to save the auth API key.
	 *
	 * @return void
	 */
	public function store_auth_key() {
		check_ajax_referer( 'wpcode_admin' );

		if ( ! current_user_can( 'wpcode_activate_snippets' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permissions to connect WPCode to the library.', 'insert-headers-and-footers' ) );
		}

		$key               = ! empty( $_POST['key'] ) ? sanitize_key( $_POST['key'] ) : false;
		$username          = ! empty( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : false;
		$origin            = ! empty( $_POST['origin'] ) ? esc_url_raw( wp_unslash( $_POST['origin'] ) ) : false;
		$deploy_snippet_id = ! empty( $_POST['deploy_snippet_id'] ) ? sanitize_key( $_POST['deploy_snippet_id'] ) : false;
		$webhook_secret    = ! empty( $_POST['webhook_secret'] ) ? sanitize_key( $_POST['webhook_secret'] ) : '';
		$client_id         = ! empty( $_POST['client_id'] ) ? sanitize_key( $_POST['client_id'] ) : false;

		if ( ! $key || $this->library_url !== $origin ) {
			wp_send_json_error();
		}

		// A passkey-capable, deploy-enabled connection ships no webhook secret;
		// instead it carries the authorized public keys the client will verify
		// deploy assertions against. Collect and normalize those here.
		$this->connect_passkey_data = $this->collect_passkey_connect_data( $origin );

		$this->save_auth_data( $key, $username, $webhook_secret, $client_id );
		$this->connect_passkey_data = array();

		if ( ! empty( $deploy_snippet_id ) ) {
			// If we have a snippet id from the deployment process, set that as a transient to show a notice, so they can pick up where they started.
			set_transient( 'wpcode_deploy_snippet_id', $deploy_snippet_id, HOUR_IN_SECONDS );
		}

		// Reset the auth data.
		unset( $this->auth_data );
		unset( $this->auth_key );
		unset( $this->has_auth );

		do_action( 'wpcode_library_api_auth_connected' );

		wp_send_json_success(
			array(
				'title' => __( 'Authentication successfully completed', 'insert-headers-and-footers' ),
				'text'  => __( 'Reloading page, please wait.', 'insert-headers-and-footers' ),
			)
		);
	}

	/**
	 * Save the auth data to the db.
	 *
	 * Merges the passkey connection data that store_auth_key() sets on $connect_passkey_data
	 * (requires_passkey, rp_id, expected_origin, authorized_public_keys, connected_by_user_id).
	 *
	 * @param string $key The auth key.
	 * @param string $username The username.
	 * @param string $webhook_secret The webhook secret.
	 * @param string $client_id The client id.
	 *
	 * @return void
	 */
	public function save_auth_data( $key, $username, $webhook_secret, $client_id ) {
		$this->persist_auth_data( $this->build_auth_data( $key, $username, $webhook_secret, $client_id, $this->connect_passkey_data ) );
	}

	/**
	 * Write the auth data array to storage. This is the single overridable
	 * write path, so every update (connect, sign-count persistence) lands in
	 * the same store in both single-site and network (multisite) contexts.
	 * The Multisite addon detects WPCode 2.4.1+ by this method's name, so don't rename it.
	 *
	 * @param array $data The full auth data array to persist.
	 *
	 * @return void
	 */
	protected function persist_auth_data( $data ) {
		// Don't autoload this as we'll only need it on some pages and in specific requests.
		update_option( 'wpcode_library_api_auth', $data, false );
	}

	/**
	 * Build the auth data array persisted at connect. Shared by single-site and
	 * network (multisite) storage so both stay in sync as fields are added.
	 *
	 * @param string $key The auth key.
	 * @param string $username The username.
	 * @param string $webhook_secret The webhook secret.
	 * @param string $client_id The client id.
	 * @param array  $passkey_data Optional passkey connection data.
	 *
	 * @return array
	 */
	protected function build_auth_data( $key, $username, $webhook_secret, $client_id, $passkey_data = array() ) {
		$data = array(
			'key'            => $key,
			'username'       => $username,
			'webhook_secret' => $webhook_secret,
			'client_id'      => $client_id,
			'connected_at'   => time(),
		);

		if ( ! empty( $passkey_data ) && is_array( $passkey_data ) ) {
			$data = array_merge( $data, $passkey_data );
		}

		return $data;
	}

	/**
	 * Read and sanitize the passkey connection data from a connect request.
	 *
	 * A passkey-capable, deploy-enabled connection carries the user's authorized
	 * public keys (public data only) instead of an HMAC webhook secret. The
	 * connecting WP user is always recorded, so every connection is attributable
	 * and a future multi-user deploy feature can tie deploys to a specific user.
	 *
	 * @param string $origin The validated library origin from the connect request.
	 *
	 * @return array
	 */
	protected function collect_passkey_connect_data( $origin ) {
		// Always attribute the connection to the WP user establishing it.
		$data = array(
			'connected_by_user_id' => get_current_user_id(),
		);

		$requires_passkey = ! empty( $_POST['requires_passkey'] ) && 'false' !== $_POST['requires_passkey']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in store_auth_key() before this runs.

		if ( ! $requires_passkey ) {
			return $data;
		}

		$raw_keys = isset( $_POST['authorized_public_keys'] ) ? wp_unslash( $_POST['authorized_public_keys'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- JSON string sanitized field-by-field in sanitize_authorized_public_keys(); nonce verified in store_auth_key().
		// rp_id is trusted from this authenticated, nonce- and capability-checked,
		// origin-validated connect request (same trust as the HMAC webhook secret).
		// It is not pinned to the origin host because a WebAuthn RP ID may be a
		// registrable suffix of the origin; it only governs the connecting admin's
		// own site's future deploy verification.
		$rp_id = isset( $_POST['rp_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rp_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in store_auth_key().

		$data['requires_passkey']       = true;
		$data['rp_id']                  = $rp_id;
		$data['expected_origin']        = $origin;
		$data['authorized_public_keys'] = $this->sanitize_authorized_public_keys( $raw_keys );

		return $data;
	}

	/**
	 * Sanitize the authorized public keys received at connect.
	 *
	 * Accepts the keys as a JSON string (or already-decoded array) and returns a
	 * clean list of public-data-only entries. Anything malformed is dropped.
	 *
	 * @param string|array $raw The raw authorized_public_keys payload.
	 *
	 * @return array
	 */
	protected function sanitize_authorized_public_keys( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$allowed_transports = array( 'usb', 'nfc', 'ble', 'internal', 'hybrid', 'smart-card' );
		$clean              = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['credential_id'] ) || empty( $entry['public_key'] ) ) {
				continue;
			}

			$credential_id = $this->sanitize_base64url( $entry['credential_id'] );
			$public_key    = $this->sanitize_base64url( $entry['public_key'] );

			if ( '' === $credential_id || '' === $public_key ) {
				continue;
			}

			$transports = array();
			if ( ! empty( $entry['transports'] ) && is_array( $entry['transports'] ) ) {
				foreach ( $entry['transports'] as $transport ) {
					$transport = is_string( $transport ) ? strtolower( trim( $transport ) ) : '';
					if ( in_array( $transport, $allowed_transports, true ) ) {
						$transports[] = $transport;
					}
				}
			}

			$clean[] = array(
				'credential_id' => $credential_id,
				'public_key'    => $public_key,
				'transports'    => array_values( array_unique( $transports ) ),
				'sign_count'    => isset( $entry['sign_count'] ) ? absint( $entry['sign_count'] ) : 0,
				'rp_id'         => isset( $entry['rp_id'] ) ? sanitize_text_field( $entry['rp_id'] ) : '',
				'alg'           => $this->get_key_algorithm( $public_key ),
			);
		}

		return $clean;
	}

	/**
	 * Record the COSE algorithm a public key was registered with, read once at
	 * connect so deploy verification can pin the algorithm independently of
	 * the stored key blob.
	 *
	 * @param string $public_key The base64url-encoded COSE public key.
	 *
	 * @return int|null The COSE algorithm identifier, or null when it cannot
	 *                  be determined (undecodable key, unsupported algorithm,
	 *                  or verifier unavailable in this build).
	 */
	protected function get_key_algorithm( $public_key ) {
		if ( ! class_exists( 'WPCode_WebAuthn_Verifier' ) ) {
			return null;
		}

		try {
			$cose_key = WPCode_WebAuthn_Verifier::cbor_decode( WPCode_WebAuthn_Verifier::base64url_decode( $public_key ) );
		} catch ( Exception $e ) {
			return null;
		}

		if ( ! is_array( $cose_key ) || ! isset( $cose_key[3] ) || ! is_int( $cose_key[3] ) ) {
			return null;
		}

		$supported = array( WPCode_WebAuthn_Verifier::COSE_ALG_ES256, WPCode_WebAuthn_Verifier::COSE_ALG_RS256 );

		return in_array( $cose_key[3], $supported, true ) ? $cose_key[3] : null;
	}

	/**
	 * Restrict a value to the base64url character set.
	 *
	 * @param mixed $value The value to sanitize.
	 *
	 * @return string Empty string when invalid or too long.
	 */
	protected function sanitize_base64url( $value ) {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 8192 ) {
			return '';
		}

		return preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ? $value : '';
	}

	/**
	 * Ajax handler to delete the auth data and disconnect the site from the WPCode Library.
	 *
	 * @return void
	 */
	public function delete_auth() {
		check_ajax_referer( 'wpcode_admin' );

		if ( ! current_user_can( 'wpcode_activate_snippets' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permissions to connect WPCode to the library.', 'insert-headers-and-footers' ) );
		}

		if ( $this->delete_auth_data() ) {
			do_action( 'wpcode_library_api_auth_deleted' );
			wp_send_json_success();
		}

		wp_send_json_error();
	}

	/**
	 * Delete the auth data from the db.
	 *
	 * @return bool
	 */
	public function delete_auth_data() {
		return delete_option( 'wpcode_library_api_auth' );
	}

	/**
	 * Check if the site is authenticated.
	 *
	 * @return bool
	 */
	public function has_auth() {
		if ( ! isset( $this->has_auth ) ) {
			$auth_key = $this->get_auth_key();

			$this->has_auth = ! empty( $auth_key );
		}

		return $this->has_auth;
	}

	/**
	 * The auth key.
	 *
	 * @return bool|string
	 */
	public function get_auth_key() {
		if ( ! isset( $this->auth_key ) ) {
			$data           = $this->get_auth_data();
			$this->auth_key = isset( $data['key'] ) ? $data['key'] : false;
		}

		return $this->auth_key;
	}

	/**
	 * The webhook secret.
	 *
	 * @return bool|string
	 */
	public function get_webhook_secret() {
		$data = $this->get_auth_data();

		return isset( $data['webhook_secret'] ) ? $data['webhook_secret'] : false;
	}

	/**
	 * The client id.
	 *
	 * @return bool|string
	 */
	public function get_client_id() {
		$data = $this->get_auth_data();

		return isset( $data['client_id'] ) ? $data['client_id'] : false;
	}

	/**
	 * Get the auth data from the db.
	 *
	 * @return array|bool
	 */
	public function get_auth_data() {
		if ( ! isset( $this->auth_data ) ) {
			$this->auth_data = $this->load_auth_data();
		}

		return $this->auth_data;
	}

	/**
	 * Get the auth data from the db.
	 *
	 * @return array|bool
	 */
	public function load_auth_data() {
		return get_option( 'wpcode_library_api_auth', false );
	}

	/**
	 * The auth username.
	 *
	 * @return bool|string
	 */
	public function get_auth_username() {
		$data = $this->get_auth_data();

		return isset( $data['username'] ) ? $data['username'] : false;
	}

	/**
	 * Whether this connection authorizes deploys with a passkey assertion
	 * rather than the legacy HMAC secret.
	 *
	 * @return bool
	 */
	public function requires_passkey() {
		$data = $this->get_auth_data();

		return ! empty( $data['requires_passkey'] );
	}

	/**
	 * The Relying Party ID the deploy passkeys are scoped to. This is the
	 * library's host (the ceremony runs on the library, not on this site), as
	 * reported in the connect handshake; falls back to the library host.
	 *
	 * @return string
	 */
	public function get_rp_id() {
		$data = $this->get_auth_data();

		if ( ! empty( $data['rp_id'] ) ) {
			return $data['rp_id'];
		}

		$host = wp_parse_url( $this->library_url, PHP_URL_HOST );

		return is_string( $host ) ? $host : '';
	}

	/**
	 * The origin the browser must report in clientDataJSON: the library origin,
	 * since the passkey ceremony runs there.
	 *
	 * @return string
	 */
	public function get_expected_origin() {
		// Prefer the origin captured and validated at connect, so the rp_id and
		// origin the verifier checks against come from the same source.
		$data = $this->get_auth_data();
		if ( ! empty( $data['expected_origin'] ) ) {
			return $data['expected_origin'];
		}

		$parts = wp_parse_url( $this->library_url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];

		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}

	/**
	 * The public keys authorized to sign deploys for this connection.
	 *
	 * Public data only, as received in the connect handshake: each entry has
	 * credential_id, public_key (base64url COSE), sign_count and transports.
	 *
	 * @return array
	 */
	public function get_authorized_public_keys() {
		$data = $this->get_auth_data();

		return isset( $data['authorized_public_keys'] ) && is_array( $data['authorized_public_keys'] ) ? $data['authorized_public_keys'] : array();
	}

	/**
	 * Find a stored authorized key by its credential id.
	 *
	 * @param string $credential_id The base64url credential id presented in the assertion.
	 *
	 * @return array|null The stored key, or null if this credential is not authorized.
	 */
	public function get_public_key_by_credential_id( $credential_id ) {
		if ( '' === (string) $credential_id ) {
			return null;
		}

		foreach ( $this->get_authorized_public_keys() as $key ) {
			if ( isset( $key['credential_id'] ) && hash_equals( (string) $key['credential_id'], (string) $credential_id ) ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Persist an increased sign count for a stored credential after a
	 * successful assertion, for clone detection on the next deploy.
	 *
	 * @param string $credential_id The credential whose counter to update.
	 * @param int    $sign_count    The new (strictly greater) sign count.
	 *
	 * @return void
	 */
	public function update_sign_count( $credential_id, $sign_count ) {
		$data = $this->get_auth_data();

		if ( empty( $data['authorized_public_keys'] ) || ! is_array( $data['authorized_public_keys'] ) ) {
			return;
		}

		$changed = false;
		foreach ( $data['authorized_public_keys'] as $index => $key ) {
			if ( isset( $key['credential_id'] ) && hash_equals( (string) $key['credential_id'], (string) $credential_id ) ) {
				$data['authorized_public_keys'][ $index ]['sign_count'] = (int) $sign_count;
				$changed = true;
				break;
			}
		}

		if ( ! $changed ) {
			return;
		}

		$this->persist_auth_data( $data );

		// Keep the in-memory copy in sync for the rest of the request.
		$this->auth_data = $data;
	}

	/**
	 * Use the API key saved in the db to sign a value, used for authenticating requests from the library to the plugin.
	 *
	 * @param string $string The string to sign.
	 *
	 * @return string
	 */
	public function sign( $string ) {
		$api_key = $this->get_webhook_secret();

		if ( empty( $api_key ) ) {
			return false;
		}

		return hash_hmac( 'sha256', (string) $string, $api_key );
	}
}
