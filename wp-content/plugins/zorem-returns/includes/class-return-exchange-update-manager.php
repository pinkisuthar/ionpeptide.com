<?php
// phpcs:ignoreFile PluginCheck.CodeAnalysis.PluginUpdateCheck.PluginUpdaterDetected -- This is a paid Pro plugin distributed via zorem.com (NOT WordPress.org). The custom updater fetches signed updates from the licensed store URL — a mandatory Pro feature.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Exchange_Update_Manager {

	public $store_url = 'https://www.zorem.com/';
	public $slug;
	public $current_version;
	public $plugin;
	public $cachedInstalledVersion;

	/**
	 * Initialize the main plugin function
	 */
	public function __construct( $current_version, $pluginFile, $slug = '' ) {
		$this->slug            = $slug;
		$this->plugin          = $pluginFile;
		$this->current_version = $current_version;
		$this->init();
		$this->cachedInstalledVersion = null;
	}

	/**
	 * Instance of this class.
	 *
	 * @var object Class Instance
	 */
	private static $instance;

	/**
	 * Get the class instance
	 *
	 * @return Return_Update_Manager
	 */
	public static function get_instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/*
	* init from parent mail class
	*/
	public function init() {

		// Insert our update info into the update array maintained by WP
		add_filter( 'site_transient_update_plugins', array( $this, 'check_update' ) ); // WP 3.0+
		add_filter( 'transient_update_plugins', array( $this, 'check_update' ) ); // WP 2.8+
		// echo $this->plugin;exit;
		add_action( 'in_plugin_update_message-' . $this->plugin, array( $this, 'addUpgradeMessageLink' ) );

		add_action( 'upgrader_process_complete', array( $this, 'after_update' ), 10, 2 );
		add_filter( 'plugins_api', array( $this, 'view_plugin_info' ), 20, 3 );
	}

	/**
	 * Add our self-hosted autoupdate plugin to the filter transient
	 *
	 * @param $transient
	 *
	 * @return object $transient
	 */
	public function check_update( $transient ) {

		// delete_transient( 'zorem_upgrade_'.$this->slug );

		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote_update = get_transient( 'zorem_upgrade_' . $this->slug );
		// trying to get from cache first, to disable cache comment 10,20,21,22,24
		if ( false == $remote_update ) {
			// info.json is the file with the actual plugin information on your server
			$remote_update = $this->getRemote_update();

		}

		if ( $remote_update ) {
			$data = json_decode( wp_remote_retrieve_body( $remote_update ) );
			// echo '<pre> data : ';print_r($data);echo '</pre>';exit;

			// If a newer version is available, add the update
			$remote_version = $data->data->package->new_version;

			if ( version_compare( $this->current_version, $remote_version, '<' ) ) {
				$obj                                  = new stdClass();
				$obj->slug                            = $this->slug;
				$obj->new_version                     = $remote_version;
				$obj->plugin                          = $this->plugin;
				$obj->package                         = $data->data->package->package;
				$obj->tested                          = $data->data->package->tested;
				$transient->response[ $this->plugin ] = $obj;
			}
		}

		return $transient;
	}

	/**
	 * Return the remote update
	 *
	 * @return string $remote_update
	 */
	public function getRemote_update() {

		// FIX SSL SNI
		$filter_add = true;
		if ( function_exists( 'curl_version' ) ) {
			$version = curl_version();
			if ( version_compare( $version['version'], '7.18', '>=' ) ) {
				$filter_add = false;
			}
		}
		if ( $filter_add ) {
			add_filter( 'https_ssl_verify', '__return_false' );
		}

		$instance_id = zorem_returns_exchanges()->license->get_instance_id();

		$domain = home_url();

		$api_params = array(
			'wc-api'       => 'wc-am-api',
			'wc_am_action' => 'update',
			'instance'     => $instance_id,
			'object'       => $domain,
			'product_id'   => zorem_returns_exchanges()->license->get_product_id(),
			'api_key'      => zorem_returns_exchanges()->license->get_license_key(),
			'plugin_name'  => $this->plugin,
			'version'      => $this->current_version,
		);

		$request = add_query_arg( $api_params, $this->store_url );

		$response = wp_remote_get(
			$request,
			array(
				'timeout'   => 15,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$authorize_data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( $filter_add ) {
			remove_filter( 'https_ssl_verify', '__return_false' );
		}
		if ( ! is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) === 200 ) {

			set_transient( 'zorem_upgrade_' . $this->slug, $response, 43200 ); // 12 hours cache
			return $response;
		}

		return false;
	}

	/**
	 * Shows message on Wp plugins page with a link for updating from zorem.
	 */
	public function addUpgradeMessageLink() {

		if ( zorem_returns_exchanges()->license->get_license_status() ) {
			return;
		}

		$url = admin_url( 'admin.php?page=zorem-returns-exchanges&tab=license' );

		/* translators: %s: search for a tag */
		printf( ' ' . esc_html__( 'To receive automatic updates license activation is required. Please visit %1$ssettings%2$s to activate your Return for WooCommerce.', 'zorem-returns' ), '<a href="' . esc_url( $url ) . '" target="_blank">', '</a>' );
	}

	/**
	 *
	 * After update
	 */
	public function after_update( $upgrader_object, $options ) {
		if ( 'update' == $options['action'] && 'plugin' === $options['type'] ) {
			// just clean the cache when new plugin version is installed
			delete_transient( 'zorem_upgrade_' . $this->slug );
		}
	}

	/*
	 * $res empty at this step
	 * $action 'plugin_information'
	 * $args stdClass Object ( [slug] => woocommerce [is_ssl] => [fields] => Array ( [banners] => 1 [reviews] => 1 [downloaded] => [active_installs] => 1 ) [per_page] => 24 [locale] => en_US )
	 */
	public function view_plugin_info( $res, $action, $args ) {

		// do nothing if this is not about getting plugin information
		if ( 'plugin_information' !== $action ) {
			return $res;
		}

		// do nothing if it is not our plugin
		if ( 'return' !== $args->slug ) {
			return $res;
		}

		$instance_id = zorem_returns_exchanges()->license->get_instance_id();
		$domain      = home_url();

		$api_params = array(
			'wc-api'       => 'wc-am-api',
			'wc_am_action' => 'plugininformation',
			'instance'     => $instance_id,
			'object'       => $domain,
			'product_id'   => zorem_returns_exchanges()->license->get_product_id(),
			'api_key'      => zorem_returns_exchanges()->license->get_license_key(),
			'plugin_name'  => $this->plugin,
			'version'      => $this->current_version,
		);

		$request = add_query_arg( $api_params, $this->store_url );

		$response = wp_remote_get(
			$request,
			array(
				'timeout'   => 15,
				'sslverify' => false,
			)
		);

		// do nothing if we don't get the correct response from the server
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || empty( wp_remote_retrieve_body( $response ) ) ) {
			return false;
		}

		$plugin_information = unserialize( trim( wp_remote_retrieve_body( $response ) ) );

		$res               = new stdClass();
		$res->name         = $plugin_information->name;
		$res->slug         = $plugin_information->slug;
		$res->author       = $plugin_information->author;
		$res->version      = $plugin_information->version;
		$res->tested       = $plugin_information->tested;
		$res->requires     = $plugin_information->requires;
		$res->requires_php = $plugin_information->requires_php;
		$res->last_updated = $plugin_information->last_updated;
		$res->sections     = array(
			'changelog' => $plugin_information->sections['changelog'],
		);

		$res->banners = array(
			'low'  => zorem_returns_exchanges()->plugin_dir_url() . 'assets/images/zorem-changelog-banner.jpg',
			'high' => zorem_returns_exchanges()->plugin_dir_url() . 'assets/images/zorem-changelog-banner.jpg',
		);

		return $res;
	}
}
