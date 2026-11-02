<?php
/**
 * Rotating cross-promo submenu slot.
 *
 * One sister-plugin promo item shows at a time. Once its plugin has been active
 * for a week the slot moves to the next product, and it disappears when every
 * product has been adopted.
 *
 * @package WPCode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPCode_Promo_Rotation.
 */
class WPCode_Promo_Rotation {

	/**
	 * Key in the ihaf_activated option holding product key => activation timestamp.
	 *
	 * @var string
	 */
	const RECORDS_KEY = 'promo_rotation';

	/**
	 * How long an item stays in the slot after its plugin was activated.
	 *
	 * @var int
	 */
	const ADOPTION_PERIOD = 7 * DAY_IN_SECONDS;

	/**
	 * Page slug of the current slot, memoized per request. Null until computed.
	 *
	 * @var string|null
	 */
	private $current_slug;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'activated_plugin', array( $this, 'record_activation' ) );
	}

	/**
	 * Ordered catalog: product key (as in WPCode_Suggested_Plugins::all_plugins()) => promo page slug.
	 *
	 * @return array
	 */
	public function get_items() {
		return array(
			'wpconsent'             => 'wpcode-wpconsent',
			'duplicator'            => 'wpcode-duplicator',
			'search-replace-wpcode' => 'wpcode-search-replace',
			'wp-mail-smtp'          => 'wpcode-wp-mail-smtp',
			'uncanny-automator'     => 'wpcode-uncanny-automator',
		);
	}

	/**
	 * Plugin basename => product key for the Lite and Pro edition of every catalog product.
	 *
	 * @return array
	 */
	public function get_plugin_map() {
		$plugins = WPCode_Suggested_Plugins::all_plugins();
		$map     = array();

		foreach ( array_keys( $this->get_items() ) as $key ) {
			if ( empty( $plugins[ $key ] ) ) {
				continue;
			}
			$map[ $plugins[ $key ]['slug'] ] = $key;
			if ( ! empty( $plugins[ $key ]['pro_slug'] ) ) {
				$map[ $plugins[ $key ]['pro_slug'] ] = $key;
			}
		}

		return $map;
	}

	/**
	 * Page slug to show in the slot: the first product with no record or a record under a week old.
	 *
	 * @return string Empty string when every product is adopted.
	 */
	public function get_current_slug() {
		if ( null !== $this->current_slug ) {
			return $this->current_slug;
		}

		$this->seed_active_plugins();

		$records            = $this->get_records();
		$now                = time();
		$this->current_slug = '';

		foreach ( $this->get_items() as $key => $page_slug ) {
			if ( ! isset( $records[ $key ] ) || ( $now - $records[ $key ] ) < self::ADOPTION_PERIOD ) {
				$this->current_slug = $page_slug;
				break;
			}
		}

		return $this->current_slug;
	}

	/**
	 * Record the activation time of a catalog plugin. Hooked to activated_plugin.
	 *
	 * @param string $plugin Plugin basename.
	 *
	 * @return void
	 */
	public function record_activation( $plugin ) {
		$map = $this->get_plugin_map();

		if ( isset( $map[ $plugin ] ) ) {
			$this->record( $map[ $plugin ] );
		}
	}

	/**
	 * Record "now" for catalog plugins that are active but have no record yet.
	 * Covers sites upgrading with a plugin already active, network activation and WP-CLI installs.
	 *
	 * @return void
	 */
	private function seed_active_plugins() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$records = $this->get_records();

		foreach ( $this->get_plugin_map() as $basename => $key ) {
			if ( ! isset( $records[ $key ] ) && is_plugin_active( $basename ) ) {
				$this->record( $key );
				$records[ $key ] = time();
			}
		}
	}

	/**
	 * Store the activation time for a product, write-once.
	 *
	 * @param string $key Product key.
	 *
	 * @return void
	 */
	private function record( $key ) {
		$records = $this->get_records();

		if ( isset( $records[ $key ] ) ) {
			return;
		}

		$activated = $this->get_activated_option();

		$activated[ self::RECORDS_KEY ]         = $records;
		$activated[ self::RECORDS_KEY ][ $key ] = time();
		update_option( 'ihaf_activated', $activated );
	}

	/**
	 * Stored records, sanitized to integers.
	 *
	 * @return array
	 */
	private function get_records() {
		$activated = $this->get_activated_option();
		$records   = isset( $activated[ self::RECORDS_KEY ] ) ? $activated[ self::RECORDS_KEY ] : array();

		return is_array( $records ) ? array_map( 'intval', $records ) : array();
	}

	/**
	 * The shared ihaf_activated option, loaded on every admin request already.
	 *
	 * @return array
	 */
	private function get_activated_option() {
		$activated = get_option( 'ihaf_activated', array() );

		return is_array( $activated ) ? $activated : array();
	}
}
