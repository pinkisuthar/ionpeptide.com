<?php
defined( 'ABSPATH' ) || exit;


#[\AllowDynamicProperties]
class BWFAN_API_Wizard extends BWFAN_API_Base {

	public static $ins;
	public static $plugins = [];

	public function __construct() {
		parent::__construct();
		$this->method = WP_REST_Server::CREATABLE;
		$this->route  = '/setup-wizard/(?P<action>[a-zA-Z0-9_-]+)';

	}

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function process_api_call() {
		$action              = $this->get_sanitized_arg( 'action' );
		$this->response_code = 400;

		if ( empty( $action ) ) {
			return $this->error_response( __( 'Invalid / Empty action provided', 'wp-marketing-automations' ), null, 400 );
		}

		$response = [
			'status' => false,
			'msg'    => __( 'Invalid action', 'wp-marketing-automations' ),
		];

		switch ( $action ) {
			case 'optin-track':
				$response = $this->optin_track();
				break;
			case 'install-plugins':
				$response = $this->install_plugins();
				break;
			case 'optin-setup':
				$op_email = $this->get_sanitized_arg( 'op_email', 'email' );
				$response = $this->optin_setup( $op_email );
				break;
			default:
				break;
		}

		if ( isset( $response['status'] ) && true === boolval( $response['status'] ) ) {
			$this->response_code = 200;

			return $this->success_response( [], isset( $response['msg'] ) ? $response['msg'] : __( 'Invalid action', 'wp-marketing-automations' ) );
		}

	}

	public function optin_track() {
		WooFunnels_optIn_Manager::Allow_optin( true, 'FKA' );
		bwf_options_update( 'bwf_usage_tracking_opted_on', current_time( 'timestamp' ) );
		return array(
			'status' => true,
			'msg'    => __( 'Optin tracking enabled', 'wp-marketing-automations' ),
		);
	}

	/**
	 * Install & Activate plugins
	 *
	 * @return array
	 */
	public function install_plugins() {
		if ( ! function_exists( 'activate_plugin' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = [];
		if ( bwfan_is_woocommerce_active() ) {
			// stripe
			$plugins[] = [
				'slug'           => 'funnelkit-stripe-woo-payment-gateway',
				'init'           => 'funnelkit-stripe-woo-payment-gateway/funnelkit-stripe-woo-payment-gateway.php',
				'status'         => self::get_plugin_status( 'funnelkit-stripe-woo-payment-gateway/funnelkit-stripe-woo-payment-gateway.php' ),
				'update_options' => [
					[
						'key'   => 'fkwcs_wp_stripe',
						'value' => '5364f9f1f1bff083b5b0d2f03b3aeded',
					]
				]
			];
			// fb
			$plugins[] = [
				'slug'   => 'funnel-builder',
				'init'   => 'funnel-builder/funnel-builder.php',
				'status' => self::get_plugin_status( 'funnel-builder/funnel-builder.php' ),
			];
		}
		//smtp
		$smtp_pro_plugins_status = self::get_plugin_status( 'wp-mail-smtp-pro/wp_mail_smtp.php' );

		if ( $smtp_pro_plugins_status === 'install' ) {
			$plugins[] = [
				'slug'   => 'wp-mail-smtp',
				'init'   => 'wp-mail-smtp/wp_mail_smtp.php',
				'status' => self::get_plugin_status( 'wp-mail-smtp/wp_mail_smtp.php' ),
			];
		} else {
			$plugins[] = [
				'slug'   => 'wp-mail-smtp-pro',
				'init'   => 'wp-mail-smtp-pro/wp_mail_smtp.php',
				'status' => $smtp_pro_plugins_status,
			];
		}

		try {
			foreach ( $plugins as $plugin ) {
				$plugin_init   = $plugin['init'];
				$plugin_slug   = $plugin['slug'];
				$plugin_status = $plugin['status'];
				if ( 'activated' === $plugin_status || empty( $plugin_slug ) ) {
					continue;
				}

				if ( $plugin_status === 'install' ) {
					$install_plugin = self::install_plugin( $plugin_slug );
					if ( isset( $install_plugin['status'] ) && $install_plugin['status'] === false ) {
						return $install_plugin;
					}
				}
				$activate = activate_plugin( $plugin_init, '', false, true );

				if ( is_wp_error( $activate ) ) {
					return array(
						'status' => false,
						'msg'    => $activate->get_error_message(),
					);
				}
				if ( isset( $plugin['update_options'] ) ) {
					foreach ( $plugin['update_options'] as $update_option ) {
						if ( isset( $update_option['key'] ) && isset( $update_option['value'] ) ) {
							update_option( $update_option['key'], $update_option['value'], false );
						}
					}
				}
			}
		} catch ( Error $e ) {
			BWFAN_Common::log_test_data( $plugin_slug . '. Error occurred during install or activate. ' . $e->getMessage(), 'plugin-install', true );

			return [
				'status' => false,
				'msg'    => $e->getMessage(),
			];
		}

		return [
			'status' => true,
			'msg'    => __( 'Plugins installed and activated', 'wp-marketing-automations' ),
		];
	}

	public function optin_setup( $op_email = '' ) {
		if ( false === $op_email || ! is_email( $op_email ) ) {
			$this->response_code = 400;

			return [
				'status' => false,
				'msg'    => __( 'Email is not valid', 'wp-marketing-automations' ),
			];
		}
		$op_email = isset( $op_email ) ? trim( $op_email ) : '';

		$api_params = array(
			'action' => 'woofunnelsapi_email_optin',
			'data'   => array( 'email' => $op_email, 'site' => home_url(), 'product' => 'FKA', 'step' => '3', 'locale' => get_locale() ),
		);

		$request_args = WooFunnels_API::get_request_args( array(
			'timeout'   => 0.5, //phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			'sslverify' => WooFunnels_API::$is_ssl,
			'body'      => urlencode_deep( $api_params ),
		) );

		/**
		 * We do not need to track the result of the call, simply move forward and show success to the user
		 */
		wp_remote_post( WooFunnels_API::get_api_url( WooFunnels_API::$woofunnels_api_url ), $request_args );

		update_option( 'bwf_is_opted_email', 'yes', true );
		update_option( 'bwf_is_opted_data', array( 'email' => $op_email ), true );
		update_option( '_bwfan_onboarding_completed', true );

		return [
			'status' => true,
			'msg'    => __( 'Optin setup completed', 'wp-marketing-automations' ),
		];
	}

	/**
	 * Get plugin status
	 *
	 * @param $plugin_file
	 *
	 * @return string
	 */
	public static function get_plugin_status( $plugin_file ) {
		if ( ! empty( self::$plugins ) ) {
			$plugins = self::$plugins;
		} else {
			$plugins       = get_plugins();
			self::$plugins = $plugins;
		}

		if ( ! is_array( $plugins ) || ! isset( $plugins[ $plugin_file ] ) ) {
			return 'install';
		}

		if ( ! is_plugin_active( $plugin_file ) ) {
			return 'activate';
		}

		if ( isset( $plugins[ $plugin_file ] ) ) {
			return 'activated';
		}

		return '';
	}

	/**
	 * Install plugin
	 *
	 * @param $plugin_slug
	 *
	 * @return array
	 */
	public static function install_plugin( $plugin_slug ) {
		$resp = array(
			'status' => false,
			'msg'    => __( 'Unable to install plugin', 'wp-marketing-automations' )
		);

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		include_once ABSPATH . 'wp-admin/includes/admin.php';
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

		$api = plugins_api( 'plugin_information', array(
			'slug'   => $plugin_slug,
			'fields' => array(
				'sections' => false,
			),
		) );

		if ( is_wp_error( $api ) ) {
			$resp['msg'] = $api->get_error_message();

			return $resp;
		}

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			$resp['msg'] = $result->get_error_message();

			return $resp;
		}

		if ( is_null( $result ) ) {
			global $wp_filesystem;
			$resp['msg'] = __( 'Unable to connect to the filesystem. Please confirm your credentials.', 'wp-marketing-automations' );

			// Pass through the error from WP_Filesystem if one was raised.
			if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
				$resp['msg'] = $wp_filesystem->errors->get_error_message(); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			return $resp;
		}

		return install_plugin_install_status( $api );
	}
}

BWFAN_API_Loader::register( 'BWFAN_API_Wizard' );
