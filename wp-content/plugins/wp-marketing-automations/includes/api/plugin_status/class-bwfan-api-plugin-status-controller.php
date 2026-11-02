<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// using wpmailsmtp options class to check the configuration status
use WPMailSMTP\Options;

class BWFAN_API_Plugin_Status_Controller extends BWFAN_API_Controller {

	public static $ins;

	protected $auto_activate = false;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function default_args_values() {
		return array();
	}

	protected function register_routes() {
		// POST /plugin/install_and_activate — install or activate a plugin
		$this->add_route( '/plugin/install_and_activate', array(
			array( 'POST', 'install_and_activate_plugin' ),
		) );

		// GET /plugin/status — check plugin status
		$this->add_route( '/plugin/status', array(
			array( 'GET', 'get_plugin_status' ),
		) );
	}

	/**
	 * POST /plugin/install_and_activate — install or activate a plugin
	 */
	public function install_and_activate_plugin() {
		self::load_plugin_functions();

		// basename of the plugin - plugin_folder/main_file
		$plugin     = isset( $this->args['plugin'] ) && ! empty( $this->args['plugin'] ) ? sanitize_text_field( wp_unslash( $this->args['plugin'] ) ) : '';
		$pro_plugin = isset( $this->args['plugin_pro'] ) && ! empty( $this->args['plugin_pro'] ) ? sanitize_text_field( wp_unslash( $this->args['plugin_pro'] ) ) : '';

		if ( isset( $this->args['auto_activate'] ) && ! empty( $this->args['auto_activate'] ) ) {
			$this->auto_activate = true;
		}

		// actions - install | activate
		$action = isset( $this->args['action'] ) && ! empty( $this->args['action'] ) ? $this->args['action'] : '';

		$all_plugins = get_plugins();

		/** checking for exists wp stmp pro plugin and also when action is activate */
		if ( array_key_exists( $pro_plugin, $all_plugins ) && 'wp-mail-smtp/wp_mail_smtp.php' === $plugin && 'activate' === $action ) {
			$plugin = $pro_plugin;
		}

		// plugin - full zip url of the plugin
		$plugin_url = isset( $this->args['url'] ) && ! empty( $this->args['url'] ) ? esc_url_raw( wp_unslash( $this->args['url'] ) ) : '';

		if ( empty( $action ) ) {
			return $this->error_response( __( 'Action is missing : install or activate', 'wp-marketing-automations' ) );
		}

		/**
		 * On an explicit activate the basename is request input, so it is bound to the plugins
		 * this UI actually offers. The auto-activate that follows an install is deliberately not
		 * checked here: that basename comes from the installer, and the ZIP it came from was
		 * already pinned to a trusted host by is_allowed_plugin_source().
		 */
		if ( 'activate' === $action && ! in_array( $plugin, self::get_activatable_plugins(), true ) ) {
			$this->response_code = 400;

			return $this->error_response( __( 'This plugin cannot be activated from here', 'wp-marketing-automations' ) );
		}

		return $this->install_or_activate_addon_plugins( $plugin, $plugin_url, $action );
	}

	/**
	 * get_plugins(), is_plugin_active() and activate_plugin() live in an admin include that a
	 * REST request never loads. Called from both public entry points, which every other path
	 * in this class routes through.
	 */
	private static function load_plugin_functions() {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) || ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Plugin basenames this endpoint may activate on request.
	 *
	 * The announcement products own their own init paths (a feed can pick a key, never a path);
	 * wp-mail-smtp is the other screen that calls this endpoint, see admin/frontend/src/smtp/index.js.
	 *
	 * @return string[] Plugin basenames, i.e. folder/main-file.php.
	 */
	public static function get_activatable_plugins() {
		$allowed = array(
			'wp-mail-smtp/wp_mail_smtp.php',
			'wp-mail-smtp-pro/wp_mail_smtp.php',
		);

		if ( class_exists( 'BWFAN_Announcements' ) ) {
			$allowed = array_merge( $allowed, array_values( wp_list_pluck( BWFAN_Announcements::get_products(), 'init' ) ) );
		}

		/**
		 * Filter the plugin basenames the install/activate endpoint may activate on request.
		 *
		 * @param string[] $allowed Plugin basenames.
		 */
		$allowed = apply_filters( 'bwfan_allowed_activate_plugins', $allowed );

		return array_values( array_unique( array_filter( (array) $allowed, 'is_string' ) ) );
	}

	/**
	 * Install or activate addon plugins
	 *
	 * @param $plugin
	 * @param $plugin_url
	 * @param $action
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function install_or_activate_addon_plugins( $plugin, $plugin_url, $action ) {
		// includes the required files
		require_once BWFAN_PLUGIN_DIR . '/includes/plugin_helpers/class-bwfan-plugin-install-skin.php';
		require_once BWFAN_PLUGIN_DIR . '/includes/plugin_helpers/class-bwfan-plugin-silent-upgrader.php';

		// Do not allow WordPress to search/download translations, as this will break JS output.
		remove_action( 'upgrader_process_complete', [ 'Language_Pack_Upgrader', 'async_upgrade' ], 20 );

		// Create the plugin upgrader with our custom skin.
		$installer = new BWFAN_Plugin_Silent_Upgrader( new BWFAN_Plugin_Install_Skin() );

		switch ( $action ) {
			case 'install':
				$result = $this->install_plugin( $installer, $plugin_url, $plugin );
				break;
			case 'activate':
				$result = $this->activate_plugin( $plugin );
				break;
			default:
				$this->response_code = 400;

				return $this->error_response( __( 'Invalid action: expected install or activate', 'wp-marketing-automations' ) );
		}

		return $result;
	}

	/**
	 * Install a plugin
	 *
	 * @param $installer
	 * @param $plugin_url
	 * @param $plugin
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function install_plugin( $installer, $plugin_url, $plugin = '' ) {
		if ( empty( $plugin_url ) ) {
			return $this->error_response( __( 'Plugin URL is missing', 'wp-marketing-automations' ) );
		}

		/**
		 * Capability check: installing a plugin requires the install_plugins capability.
		 * On multisite this is restricted to network admins, unlike manage_options.
		 */
		if ( ! current_user_can( 'install_plugins' ) ) {
			return $this->error_response( __( "You don't have permission to install plugins", 'wp-marketing-automations' ) );
		}

		// Only allow installing ZIPs from trusted sources to prevent installing an arbitrary remote plugin.
		if ( ! $this->is_allowed_plugin_source( $plugin_url ) ) {
			return $this->error_response( __( 'Plugin source URL is not allowed', 'wp-marketing-automations' ) );
		}

		// Error check.
		if ( ! method_exists( $installer, 'install' ) ) {
			return $this->error_response( __( 'Some error occurred: install function not found', 'wp-marketing-automations' ) );
		}

		/**
		 * Plugin_Upgrader::install() returns true, false, or the WP_Error it failed with.
		 * A WP_Error is truthy, so a bare falsy check reports a failed install as installed.
		 */
		$install_result = $installer->install( $plugin_url );
		if ( ! $install_result || is_wp_error( $install_result ) ) {
			return $this->error_response( __( 'Some error occurred: installation failed', 'wp-marketing-automations' ), is_wp_error( $install_result ) ? $install_result : null );
		}

		if ( $this->auto_activate ) {
			// Activate what was actually installed; the request's basename is only a fallback.
			$installed = method_exists( $installer, 'plugin_info' ) ? $installer->plugin_info() : false;

			return $this->activate_plugin( is_string( $installed ) && '' !== $installed ? $installed : $plugin );
		}

		$result['is_installed'] = true;
		$result['msg']          = esc_html__( 'Plugin installed', 'wp-marketing-automations' );

		$this->response_code = 200;

		return $this->success_response( $result );
	}

	/**
	 * Whether a plugin ZIP URL points at a trusted source.
	 * Only HTTPS URLs on WordPress.org or FunnelKit-owned hosts are permitted.
	 *
	 * @param string $plugin_url
	 *
	 * @return bool
	 */
	public function is_allowed_plugin_source( $plugin_url ) {
		$scheme = wp_parse_url( $plugin_url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $plugin_url, PHP_URL_HOST );

		if ( empty( $host ) || 'https' !== strtolower( (string) $scheme ) ) {
			return false;
		}

		$host = strtolower( $host );

		$allowed_hosts = array(
			'wordpress.org',
			'funnelkit.com',
			'buildwoofunnels.com',
		);

		/**
		 * Filter the list of trusted plugin-source hosts for the install endpoint.
		 *
		 * @param array  $allowed_hosts List of allowed host suffixes.
		 * @param string $plugin_url    The requested plugin ZIP URL.
		 */
		$allowed_hosts = apply_filters( 'bwfan_allowed_plugin_source_hosts', $allowed_hosts, $plugin_url );

		foreach ( (array) $allowed_hosts as $allowed_host ) {
			$allowed_host = strtolower( $allowed_host );
			if ( $host === $allowed_host || substr( $host, - ( strlen( $allowed_host ) + 1 ) ) === '.' . $allowed_host ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Activate a plugin
	 *
	 * @param $plugin
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function activate_plugin( $plugin ) {
		if ( empty( $plugin ) ) {
			return $this->error_response( __( 'Plugin basename is missing', 'wp-marketing-automations' ) );
		}

		//Check for permissions.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return $this->error_response( __( "You don't have permission to activate plugin", 'wp-marketing-automations' ) );
		}

		// Activate the plugin silently.
		$activated = activate_plugin( $plugin );

		if ( is_wp_error( $activated ) ) {
			return $this->error_response( __( 'Some error occurred while activating the plugin', 'wp-marketing-automations' ), $activated );
		}

		/**
		 * Fire after plugin activating via the BWFAN installer.
		 */
		do_action( 'bwfan_addon_plugin_activated', $plugin );

		$result['is_activated'] = true;
		$result['msg']          = esc_html__( 'Plugin activated', 'wp-marketing-automations' );

		$products = BWFAN_Announcements::get_products();
		if ( isset( $products['stripe']['init'] ) && $products['stripe']['init'] === $plugin ) {
			// Namespace-relative: the client resolves it through getApiPath() (autonami-app or legacy autonami-admin).
			$result['next_action'] = '/plugin-connect-link';
		}

		$this->response_code = 200;

		return $this->success_response( $result );
	}

	/**
	 * GET /plugin/status — check plugin status
	 */
	public function get_plugin_status() {
		self::load_plugin_functions();

		// plugin - plugin_folder/main_file. Sanitized like the install route: an array here
		// reaches array_key_exists() below, which is a TypeError on PHP 8. sanitize_text_field()
		// returns '' for an array, so the "name is missing" branch catches it instead.
		$plugin     = isset( $this->args['plugin'] ) ? sanitize_text_field( wp_unslash( $this->args['plugin'] ) ) : '';
		$pro_plugin = isset( $this->args['plugin_pro'] ) ? sanitize_text_field( wp_unslash( $this->args['plugin_pro'] ) ) : '';

		if ( empty( $plugin ) && empty( $pro_plugin ) ) {
			return $this->error_response( __( 'Plugin name is missing', 'wp-marketing-automations' ) );
		}

		$install_status   = false;
		$active_status    = false;
		$configure_status = false;

		// fetching all the installed plugins from the site
		$all_plugins = get_plugins();

		// return with status false if not installed
		if ( ! array_key_exists( $plugin, $all_plugins ) && ! array_key_exists( $pro_plugin, $all_plugins ) ) {
			return $this->success_response( [
				'installed'  => $install_status,
				'activated'  => $active_status,
				'configured' => $configure_status
			], __( 'Plugin is not installed', 'wp-marketing-automations' ) );
		}

		$install_status = true;
		if ( is_plugin_active( $plugin ) || is_plugin_active( $pro_plugin ) ) {
			$active_status = true;

			// fetching configure data of wp-mail-smtp
			// although class will be available as plugin is activated, but just making sure that no fatal error occurred due to class doesn't exist,
			if ( class_exists( 'WPMailSMTP\Options' ) ) {
				$default_options = wp_json_encode( Options::get_defaults() );
				$current_options = wp_json_encode( Options::init()->get_all() );

				// Check if the current settings are the same as the default settings.
				if ( $current_options !== $default_options ) {
					$configure_status = true;
				}
			}
		}

		$this->response_code = 200;

		return $this->success_response( [ 'installed' => $install_status, 'activated' => $active_status, 'configured' => $configure_status ], __( 'Plugin status', 'wp-marketing-automations' ) );
	}
}

BWFAN_API_Plugin_Status_Controller::get_instance();
