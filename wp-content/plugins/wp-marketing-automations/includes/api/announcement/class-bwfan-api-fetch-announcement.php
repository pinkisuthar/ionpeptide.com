<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Fetch_Announcement extends BWFAN_API_Base {
	public static $ins;
	protected $banner_count = 1;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function __construct() {
		parent::__construct();
		$this->method = WP_REST_Server::READABLE;
		$this->route  = '/v1/fetch-announcements';
	}

	public function process_api_call() {
		// Sanitized at cache time, and again here: the option row is not trusted between the two
		// (a tampered wp_options row or a sanitizer regression must not reach wp-admin). sanitize_feed()
		// is idempotent, so a clean cache passes through unchanged.
		// Nothing to show (empty feed, origin down, kill switch) is a 200 with empty lists, not an
		// error: the app requests this on every FunnelKit Automations admin page.
		$notifications = array_merge( [
			'features'  => [],
			'changelog' => [],
		], BWFAN_Announcements::sanitize_feed( BWFAN_Announcements::get_announcement( 'fka' ) ) );

		// Read state travels with the feed; nothing is seeded on the page.
		$state                          = BWFAN_Announcements::get_announcement_data( 'fka' );
		$notifications['last_seen_id']  = $state['last_seen_id'];
		$notifications['hide_features'] = $state['hide_features'];

		// If multiple banners are present, we will only show the first one by default, if needed to show 2 will share the banner_count in the request.
		if ( isset( $notifications['banner_count'] ) ) {
			$this->banner_count = max( 1, (int) $notifications['banner_count'] );
			unset( $notifications['banner_count'] );
		}

		// Check for sale banners and filter them based on the current version and active status.
		if ( isset( $notifications['sale_banner'] ) && is_array( $notifications['sale_banner'] ) ) {
			$notifications['sale_banner'] = $this->check_sale_banner_data( $notifications['sale_banner'] );
		}

		if ( isset( $notifications['plugin_data'] ) ) {
			if ( ! empty( $notifications['sale_banner'] ) ) {
				unset( $notifications['plugin_data'] );
			} else {
				$notifications['plugin_data']   = $this->get_plugin_data( $notifications['plugin_data'] );
				$notifications['plugin_status'] = $this->get_plugin_status( $notifications['plugin_data'] );
			}
		}

		return $this->success_response( $notifications );
	}

	/**
	 * Resolve feed product keys against the plugin-owned product map.
	 * The feed only chooses which products to show; slug, init path and redirect always come from
	 * BWFAN_Announcements::get_products(), so the installer can never be pointed at another plugin.
	 *
	 * @param array $keys Product keys from the feed; empty = every known product.
	 *
	 * @return array Plugin data for the products whose requirements are met.
	 */
	public function get_plugin_data( $keys = [] ) {
		$products = BWFAN_Announcements::get_products();
		$keys     = is_array( $keys ) && ! empty( $keys ) ? $keys : array_keys( $products );

		$plugins = [];
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || ! isset( $products[ $key ] ) ) {
				continue;
			}
			$product = $products[ $key ];
			if ( ! BWFAN_Announcements::requirements_met( isset( $product['dependency'] ) ? $product['dependency'] : [] ) ) {
				continue;
			}
			$plugins[] = $product;
		}

		return $plugins;
	}

	/**
	 * Get plugin status
	 *
	 * @param array $plugin_data Plugin data
	 *
	 * @return array Plugin statuses for stripe and cart plugins
	 */
	public function get_plugin_status( $plugin_data ) {
		$statuses = [];

		// Nothing to report on: skip the plugin-directory walk get_plugins() does.
		if ( empty( $plugin_data ) || ! is_array( $plugin_data ) ) {
			return $statuses;
		}

		// get_plugins() / is_plugin_active() live in an admin include that REST requests do not load.
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		foreach ( $plugin_data as $plugin ) {
			$data = [
				'status' => 'not_installed',
			];
			if ( isset( $all_plugins[ $plugin['init'] ] ) ) {
				$data = array(
					'status' => is_plugin_active( $plugin['init'] ) ? 'installed' : 'not_activated',
				);

				// Stripe's card is done only once the account is connected. The client vocabulary is
				// 'connected' (successStatus, card hidden) / 'not_connected' (shows "Connect" + link);
				// a bare 'installed' has no button label.
				if ( 'stripe' === $plugin['key'] && 'installed' === $data['status'] ) {
					// Same default as FKWCS; 'test_admin_only' charges admins with the test key, and only admins see this card.
					$mode   = get_option( 'fkwcs_mode', 'test' );
					$secret = get_option( in_array( $mode, array( 'test', 'test_admin_only' ), true ) ? 'fkwcs_test_secret_key' : 'fkwcs_secret_key', '' );
					if ( ! empty( $secret ) ) {
						$data['status'] = 'connected';
					} elseif ( class_exists( '\FKWCS\Gateway\Stripe\Admin' ) ) {
						$data['status'] = 'not_connected';
						$data['link']   = \FKWCS\Gateway\Stripe\Admin::get_instance()->get_connect_url();
					}
					// No admin class: there is nowhere to send them, so leave the status at
					// 'installed'. 'not_connected' without a link renders a "Connect" button
					// that navigates to undefined.
				}

			}
			$statuses[ $plugin['key'] ] = $data;
		}

		return $statuses;
	}

	/**
	 * Check the sale banner data for validity and return the valid banners.
	 *
	 * @param array $data The sale banner data to check.
	 *
	 * @return array The valid sale banners.
	 */
	private function check_sale_banner_data( $data = [] ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return [];
		}

		$banner_data = [];

		foreach ( $data as $banner ) {
			if ( empty( $banner['content'] ) ) {
				continue;
			}

			$dependency = ! empty( $banner['dependency'] ) && is_array( $banner['dependency'] ) ? $banner['dependency'] : [];

			// Site-state predicates (pro/wc/stripe/cart/fb) — fails closed on any unknown shape.
			if ( ! BWFAN_Announcements::requirements_met( $dependency ) ) {
				continue;
			}

			// Check if the banner is for lite or pro version
			$version = ! empty( $dependency['version'] ) && is_array( $dependency['version'] ) ? $dependency['version'] : [];
			if ( ! $this->check_version( $version ) ) {
				continue;
			}

			$is_lite = ! empty( $banner['is_lite'] );
			$is_pro  = ! empty( $banner['is_pro'] );

			if ( $is_lite === $is_pro || ( $is_pro && bwfan_is_autonami_pro_active() ) || ( $is_lite && ! bwfan_is_autonami_pro_active() ) ) {
				$banner_data[] = $banner['content'];
			}
			if ( count( $banner_data ) >= $this->banner_count ) {
				break; // Stop if we have enough banners
			}
		}

		return $banner_data;
	}

	/**
	 * Check the version of FunnelKit Automations and FunnelKit Automations Pro.
	 *
	 * @param array $version The version requirements for lite and pro versions.
	 *
	 * @return bool True if the versions are valid, false otherwise.
	 */
	public function check_version( $version ) {

		if ( empty( $version ) ) {
			return true;
		}

		if ( isset( $version['lite'] ) && version_compare( BWFAN_VERSION, $version['lite'], "<" ) ) {
			return false;
		}

		if ( ! isset( $version['pro'] ) ) {
			return true;
		}

		if ( ! bwfan_is_autonami_pro_active() ) {
			return false;
		}

		if ( version_compare( BWFAN_PRO_VERSION, $version['pro'], "<" ) ) {
			return false;
		}

		return true;
	}
}

BWFAN_API_Loader::register( 'BWFAN_API_Fetch_Announcement' );
