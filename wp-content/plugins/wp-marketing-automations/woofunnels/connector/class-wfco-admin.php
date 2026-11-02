<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
if ( ! class_exists( 'WFCO_Admin' ) ) {
	#[AllowDynamicProperties]
	class WFCO_Admin {

		private static $ins = null;
		public $admin_path;
		public $admin_url;
		public $section_page = '';
		public $should_show_shortcodes = null;

		public function __construct() {
			define( 'WFCO_PLUGIN_FILE', __FILE__ );
			define( 'WFCO_PLUGIN_DIR', __DIR__ );
			define( 'WFCO_PLUGIN_URL', untrailingslashit( plugin_dir_url( WFCO_PLUGIN_FILE ) ) );
			$this->admin_path = WFCO_PLUGIN_DIR;
			$this->admin_url  = WFCO_PLUGIN_URL;

			add_action( 'admin_enqueue_scripts', array( $this, 'include_global_assets' ), 98 );

			$should_include = apply_filters( 'wfco_include_connector', false );
			if ( false === $should_include ) {
				return;
			}
			$this->initialize_connector();
		}

		private function initialize_connector() {
			include_once( $this->admin_path . '/class-wfco-connector.php' ); //phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			include_once( $this->admin_path . '/class-wfco-call.php' ); //phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			include_once( $this->admin_path . '/class-wfco-load-connectors.php' ); //phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			include_once( $this->admin_path . '/class-wfco-common.php' ); //phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			include_once( $this->admin_path . '/class-wfco-db.php' ); //phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			include_once( $this->admin_path . '/class-wfco-connector-api.php' ); //phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

			WFCO_Common::init();

		}

		public static function get_instance() {
			if ( null === self::$ins ) {
				self::$ins = new self;
			}

			return self::$ins;
		}

		public static function get_plugins() {
			return apply_filters( 'all_plugins', get_plugins() );
		}

		public static function get_oauth_connector() {
			$oauth_connectors = [];
			$all_connector    = WFCO_Admin::get_available_connectors();
			if ( empty( $all_connector ) ) {
				return $oauth_connectors;
			}

			foreach ( $all_connector as $addons ) {
				if ( empty( $addons ) ) {
					continue;
				}
				foreach ( $addons as $addons_slug => $addon ) {
					/**
					 * $addons_slug originates from the remote connector catalog and is used as a class name.
					 * Guard the dynamic dispatch so a malformed/compromised catalog entry cannot reference an
					 * unexpected class; only resolve real connector classes that expose the expected API.
					 */
					if ( $addon->is_activated() && is_string( $addons_slug ) && class_exists( $addons_slug, false ) && is_subclass_of( $addons_slug, 'BWF_CO' ) && method_exists( $addons_slug, 'get_instance' ) ) {
						$instance = $addons_slug::get_instance();
						if ( $instance instanceof BWF_CO && $instance->is_oauth() ) {
							$oauth_connectors[] = $addons_slug;
						}
					}
				}
			}

			return $oauth_connectors;
		}

		public static function get_available_connectors( $type = '' ) {

			$woofunnels_cache_object  = WooFunnels_Cache::get_instance();
			$woofunnels_transient_obj = WooFunnels_Transient::get_instance();

			$data = $woofunnels_cache_object->get_cache( 'get_available_connectors' );
			if ( empty( $data ) ) {
				$data = $woofunnels_transient_obj->get_transient( 'get_available_connectors' );
			}

			if ( ! empty( $data ) && is_array( $data ) ) {
				$data = apply_filters( 'wfco_connectors_loaded', $data );

				return self::load_connector_screens( $data, $type );
			}

			$connector_api = new WFCO_Connector_api();
			$response_data = $connector_api->set_action( 'get_available_connectors' )->get()->get_package();
			if ( is_array( $response_data ) ) {
				$woofunnels_transient_obj->set_transient( 'get_available_connectors', $response_data, 3 * HOUR_IN_SECONDS );
			}

			$response_data = apply_filters( 'wfco_connectors_loaded', $response_data );
			if ( '' !== $type ) {
				return isset( $response_data[ $type ] ) ? $response_data[ $type ] : [];
			}

			return self::load_connector_screens( $response_data, $type );
		}

		private static function load_connector_screens( $response_data, $type = '' ) {
			foreach ( $response_data as $slug => $data ) {
				$connectors = $data['connectors'];
				foreach ( $connectors as $c_slug => $connector ) {
					$connector['type'] = $slug;
					if ( isset( $data['source'] ) && ! empty( $data['source'] ) ) {
						$connector['source'] = $data['source'];
					}
					if ( isset( $data['file'] ) && ! empty( $data['file'] ) ) {
						$connector['file'] = $data['file'];
					}
					if ( isset( $data['support'] ) && ! empty( $data['support'] ) ) {
						$connector['support'] = $data['support'];
					}
					if ( isset( $data['connector_class'] ) && ! empty( $data['connector_class'] ) ) {
						$connector['connector_class'] = $data['connector_class'];
					}
					WFCO_Connector_Screen_Factory::create( $c_slug, $connector );
				}
			}

			return WFCO_Connector_Screen_Factory::getAll( $type );
		}

		public static function get_error_message() {
			$errors      = [];
			$errors[100] = __( 'Connector not found', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			$errors[101] = __( 'FunnelKit Automations license is required in order to install a connector', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			$errors[102] = __( 'FunnelKit Automations license is invalid, kindly contact woofunnels team.', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			$errors[103] = __( 'FunnelKit Automations license is expired, kindly renew and activate it first.', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch

			return $errors;
		}

		public static function js_text() {
			$data = array(
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'text_copied'             => __( 'Text Copied', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'sync_title'              => __( 'Sync Connector', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'sync_text'               => __( 'All the data of this Connector will be Synced.', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'sync_wait'               => __( 'Please Wait...', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'sync_progress'           => __( 'Sync in progress...', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'sync_success_title'      => __( 'Connector Synced', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'sync_success_text'       => __( 'We have detected change in the connector during syncing. Please re-save the Automations.', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'oops_title'              => __( 'Oops', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'oops_text'               => __( 'There was some error. Please try again later.', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'delete_int_title'        => __( 'There was some error. Please try again later.', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'delete_int_text'         => __( 'There was some error. Please try again later.', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'update_int_prompt_title' => __( 'Connector Updated', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'update_int_prompt_text'  => __( 'We have detected change in the connector during updating. Please re-save the Automations.', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'delete_int_prompt_title' => __( 'Disconnecting Connector?', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'delete_int_prompt_text'  => __( 'All the action, tasks, logs of this connector will be deleted.', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'delete_int_wait_title'   => __( 'Please Wait...', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'delete_int_wait_text'    => __( 'Disconnecting the connector ...', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'delete_int_success'      => __( 'Connector Disconnected', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'update_btn'              => __( 'Update', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'save_progress'           => __( 'Saving in progress...', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'update_btn_process'      => __( 'Updating...', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'connect_btn_process'     => __( 'Connecting...', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'install_success_title'   => __( 'Connector Installed Successfully', 'wp-marketing-automations' ),
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'connect_success_title'   => __( 'Connected Successfully', 'wp-marketing-automations' ),
			);

			return $data;
		}

		public function get_admin_url() {
			return plugin_dir_url( WFCO_PLUGIN_FILE ) . 'admin';
		}

		public function include_global_assets() {
			wp_enqueue_script( 'wfco-admin-ajax', $this->admin_url . '/assets/js/wfco-admin-ajax.js', array(), WooFunnel_Loader::$version, false );
			wp_localize_script( 'wfco-admin-ajax', 'bwf_secure', [
				'nonce' => wp_create_nonce( 'bwf_secure_key' ),
			] );
		}

		public function tooltip( $text ) {
			?>
			<span class="wfco-help"><i class="icon"></i><div class="helpText"><?php echo wp_kses_post( $text ); ?></div></span>
			<?php
		}

	}
}