<?php
/**
 * Omnisend Install Class
 *
 * @package OmnisendPlugin
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;

class Omnisend_Install {

	public static function get_registration_url() {
		$registration_url_params = array(
			'registration_redirect_url' => self::generate_install_url(),
		);

		$partner_link = self::omnisend_get_partner_link();
		if ( ! empty( $partner_link ) ) {
			$registration_url_params['partner_link'] = $partner_link;
		}

		return OMNISEND_REGISTRATION_URL . '?' . http_build_query( $registration_url_params );
	}

	public static function get_connecting_url() {
		$login_url_params = array(
			'url' => '/' . self::generate_install_url(),
		);

		return OMNISEND_LOGIN_URL . '?' . http_build_query( $login_url_params );
	}

	public static function notify_about_plugin_activation() {
		$brand_id = get_option( 'omnisend_account_id', null );
		if ( ! $brand_id ) {
			return;
		}

		$body = array(
			'brandID' => $brand_id,
		);

		Omnisend_Helper::omnisend_api( OMNISEND_ACTIVATION_URL, 'POST', $body );
	}

	public static function notify_about_plugin_update() {
		$brand_id = get_option( 'omnisend_account_id', null );
		if ( ! $brand_id ) {
			return;
		}

		$body = array(
			'brandID' => $brand_id,
		);

		Omnisend_Helper::omnisend_api( OMNISEND_UPDATE_URL, 'POST', $body );
	}

	public static function uninstall() {
		self::cleanup_all_sites();
	}

	public static function disconnect() {
		self::cleanup_all_sites();
	}

	public static function disconnect_current_site() {
		self::cleanup_current_site();
	}

	public static function deactivate() {
		self::delete_omnisend_webhooks();
	}

	/**
	 * Clean up Omnisend data from all sites (multisite-aware).
	 */
	private static function cleanup_all_sites() {
		if ( is_multisite() ) {
			$sites = get_sites();
			foreach ( $sites as $site ) {
				switch_to_blog( $site->blog_id );
				self::cleanup_current_site();
				restore_current_blog();
			}
		} else {
			self::cleanup_current_site();
		}
	}

	/**
	 * Clean up Omnisend data from the current site.
	 */
	private static function cleanup_current_site() {
		self::delete_logs();
		self::delete_omnisend_webhooks();
		self::revoke_omnisend_woo_api_keys();
		self::delete_options();
		self::delete_store_connection_options();
		self::delete_metadata();
	}

	private static function revoke_omnisend_woo_api_keys() {

		$api_keys = self::get_woo_api_keys();

		if ( count( $api_keys ) <= 0 ) {
			return;
		}

		foreach ( $api_keys as $api_key ) {
			self::remove_woo_api_key( $api_key->key_id );
		}
	}

	private static function delete_omnisend_webhooks() {
		if ( ! Omnisend_Helper::is_woocommerce_plugin_activated() ) {
			return;
		}

		$webhook_data_store = WC_Data_Store::load( 'webhook' );
		$num_webhooks       = $webhook_data_store->get_count_webhooks_by_status();
		$count              = array_sum( $num_webhooks );

		if ( $count <= 0 ) {
			return;
		}

		$webhook_ids = $webhook_data_store->get_webhooks_ids();

		foreach ( $webhook_ids as $webhook_id ) {
			$webhook = wc_get_webhook( $webhook_id );
			if ( ! $webhook ) {
				continue;
			}

			$is_omnisend_delivery_url = false !== strpos( $webhook->get_delivery_url(), 'woocommerce.webhooks.omnisend' );
			$is_omnisend_name         = false !== strpos( $webhook->get_name(), 'omnisend::' );
			if ( $is_omnisend_delivery_url && $is_omnisend_name ) {
				$webhook_data_store->delete( $webhook );
			}
		}
	}

	private static function delete_logs() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'omnisend_logs';
		$sql        = "DROP TABLE IF EXISTS $table_name";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql );

		// Also delete contact cache table.
		$table_name = $wpdb->prefix . 'omnisend_contact_cache';
		$sql        = "DROP TABLE IF EXISTS $table_name";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql );
	}

	private static function delete_options() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$plugin_options = $wpdb->get_results( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'omnisend_%'" );

		foreach ( $plugin_options as $option ) {
			delete_option( $option->option_name );
		}
	}

	private static function delete_store_connection_options() {
		delete_option( 'omnisend_api_key' );
		delete_option( 'omnisend_account_id' );
		delete_option( 'omnisend_connect_token' );
		delete_option( 'omnisend_environment' );
		delete_option( 'omnisend_connected_domain' );
		delete_option( 'omnisend_plugin_version' );
		delete_option( 'omnisend_wp_version' );
		delete_option( 'omnisend_batches_inProgress' );
		delete_option( 'omnisend_woo_partner_link' );
	}

	private static function delete_metadata() {
		global $wpdb;

		delete_metadata( 'user', '0', Omnisend_Sync::FIELD_NAME, '', true );
		delete_metadata( 'post', '0', Omnisend_Sync::FIELD_NAME, '', true );
		delete_metadata( 'term', '0', Omnisend_Sync::FIELD_NAME, '', true );

		// Delete WooCommerce order metadata.
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE 'omnisend_%'" );
		}
	}

	/**
	 * Retrieves woocommerce api keys for omnisend.
	 *
	 * @return array of {"key_id": integer}
	 */
	public static function get_woo_api_keys() {
		global $wpdb;

		$like = OMNISEND_WC_API_APP_NAME . ' - API %';
		$sql  = $wpdb->prepare( "SELECT `key_id`, `user_id` FROM {$wpdb->prefix}woocommerce_api_keys WHERE `description` LIKE %s", $like );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( $sql );
	}

	/**
	 * Remove woocommerce api key.
	 *
	 * @param integer $key_id API Key ID.
	 *
	 * @return boolean
	 */
	public static function remove_woo_api_key( $key_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$delete = $wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $key_id ), array( '%d' ) );

		return $delete;
	}

	private static function generate_install_url() {
		$token = get_option( 'omnisend_connect_token', '' );

		if ( $token === '' ) {
			$token = bin2hex( random_bytes( 32 ) );
			update_option( 'omnisend_connect_token', $token );
		}

		$install_url_params = array(
			'token'             => $token,
			'storeUrl'          => home_url(),
			'woocommerceUserId' => get_current_user_id(),
			'_wpnonce'          => wp_create_nonce( 'omnisend-oauth' ),
		);

		return OMNISEND_PLUGIN_INSTALL_URL . '?' . http_build_query( $install_url_params );
	}

	private static function omnisend_get_partner_link() {
		$link = '';
		// Run any filters that may be on the partner link.
		$link = apply_filters( 'omnisend_woo_partner_link', $link );

		if ( empty( $link ) ) {
			$link = get_option( 'omnisend_woo_partner_link', $link );
		}
		return $link;
	}
}
