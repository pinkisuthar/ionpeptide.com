<?php

namespace FKCart\Includes;

use FKCart\Includes\Traits\Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class Admin
 */
#[\AllowDynamicProperties]
class DB {

	use Instance;

	public $db_version = [];

	public function __construct() {
		/** First time */
		add_action( 'init', [ $this, 'first_time' ], 10 );

		/** Updates */
		add_action( 'init', [ $this, 'db_update' ], 12 );
	}

	/**
	 * Run on first time plugin activation
	 *
	 * @return void
	 */
	public function first_time() {
		$db_options = get_option( 'fkcart_db_options', [] );
		$db_version = $db_options['db_version'] ?? '0.1';

		if ( version_compare( $db_version, '1.0.0', '>' ) ) {
			return;
		}

		$this->create_db();

		$this->db_version = [ '1.0.0' ];

		/** Set default setting value */
		$data = [
			'enable_strike_through_discounted_price' => true,
			'show_shop_continue_link'                => false,
			'enable_special_addon'                   => true,
			'reward_progress_bar_style'              => 'modern',
		];
		update_option( 'fkcart_settings', $data, false );

		$this->update_db_version( '1.0.0' );
	}

	/**
	 * Create cart and cart products tables
	 *
	 * @return void
	 */
	public static function create_db() {
		global $wpdb;
		$charset_collate  = $wpdb->get_charset_collate();
		$fk_cart          = $wpdb->prefix . 'fk_cart';
		$fk_cart_products = $wpdb->prefix . 'fk_cart_products';
		$sql              = "
	    CREATE TABLE $fk_cart (
	        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	        oid bigint(20) unsigned NOT NULL DEFAULT 0,
	        onumber varchar(100) NOT NULL DEFAULT '',
	        addon_viewed varchar(255) NOT NULL DEFAULT '',
	        free_gift_viewed varchar(255) NOT NULL DEFAULT '',
	        upsells_viewed varchar(255) NOT NULL DEFAULT '',
	        discount varchar(100) NOT NULL DEFAULT '',
	        free_shipping tinyint(2) unsigned COMMENT '1- yes 0- no',
	        date_created DateTime NOT NULL,
	        PRIMARY KEY (id),
	        KEY oid (oid),
	        KEY discount (discount),
	        KEY free_shipping (free_shipping),
	        KEY date_created (date_created)
	    ) $charset_collate;

	    CREATE TABLE $fk_cart_products (
	        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	        oid bigint(20) unsigned NOT NULL,
	        product_id bigint(20) unsigned NOT NULL,
	        price double NOT NULL,
	        type tinyint(1) NOT NULL COMMENT '1 - Upsell, 2 - Free Gift, 3 - Addon',
	        PRIMARY KEY (id),
	        KEY oid (oid),
	        KEY product_id (product_id),
	        KEY type (type)
	    ) $charset_collate;
	    ";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		\dbDelta( $sql );
	}

	/**
	 * Update db option key with version
	 *
	 * @param $version
	 *
	 * @return void
	 */
	private function update_db_version( $version ) {
		$db_options               = get_option( 'fkcart_db_options', [] );
		$db_options['db_version'] = $version;

		$db_options[ 'db_' . $version ] = current_time( 'mysql' );

		if ( ! isset( $db_options['id'] ) || empty( $db_options['id'] ) ) {
			$db_options['id'] = current_time( 'mysql' ); // Install date
		}

		/** Updating version */
		update_option( 'fkcart_db_options', $db_options, true );
	}

	/**
	 * Perform DB update
	 *
	 * @return void
	 */
	public function db_update() {
		$db_changes = array(
			'1.7.1' => '1_7_1',
			'1.7.2' => '1_7_2',
			'1.8.1' => '1_8_1',
			'1.8.2' => '1_8_2',
			'1.8.3' => '1_8_3',
			'1.8.4' => '1_8_4',
		);

		$db_options = get_option( 'fkcart_db_options', [] );
		$db_version = $db_options['db_version'] ?? '0.1';

		/** Checking if current db version is greater than then the saved version */
		if ( false === version_compare( FKCART_DB_VERSION, $db_version, '>' ) ) {
			return;
		}

		foreach ( $db_changes as $version_key => $version_value ) {
			if ( version_compare( $db_version, $version_key, '<' ) ) {
				$function_name = 'db_update_' . $version_value;
				$this->$function_name( $version_key );
			}
		}
	}

	/**
	 * 1.7.1
	 *
	 * @param $version_key
	 *
	 * @return void
	 */
	protected function db_update_1_7_1( $version_key ) {
		if ( in_array( '1.0.0', $this->db_version, true ) ) {
			$this->update_db_version( $version_key );

			return;
		}
		$this->create_db();
		$this->update_db_version( $version_key );
	}

	/**
	 * Set new db migration status on
	 *
	 * @param $version_key
	 *
	 * @return void
	 */
	protected function db_update_1_7_2( $version_key ) {
		if ( in_array( '1.0.0', $this->db_version, true ) ) {
			$this->update_db_version( $version_key );

			return;
		}
		if ( ! function_exists( 'fkcart_db_migrator' ) ) {
			return;
		}
		global $wpdb;

		$cart_stats_table = $wpdb->prefix . 'fk_cart_stats';
		$table_exists     = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $cart_stats_table ) );
		if ( $table_exists !== $cart_stats_table ) {
			return;
		}

		$entry = $wpdb->get_var( "select count(ID) as total_entry from {$wpdb->prefix}fk_cart_stats WHERE status = 1 LIMIT 1 " );
		if ( ! empty( $entry ) && 0 < absint( $entry ) ) {
			if ( ! in_array( fkcart_db_migrator()->get_upgrade_state(), [ 2, 3 ], true ) ) {
				fkcart_db_migrator()->set_upgrade_state( 1 );
			}
		}

		$this->update_db_version( $version_key );
	}

	/**
	 * 1.8.1
	 *
	 * @param $version_key
	 *
	 * @return void
	 */
	protected function db_update_1_8_1( $version_key ) {
		if ( in_array( '1.0.0', $this->db_version, true ) ) {
			$this->update_db_version( $version_key );

			return;
		}

		delete_option( 'fkcart_db_options_new_db' );

		/** Update strikethrough color */
		$data          = Data::get_settings();
		$primary_color = $data['css_primary_text_color'] ?? '#353030';

		$data['strike_through_price_color'] = $primary_color;
		update_option( 'fkcart_settings', $data, false );


		$this->update_db_version( $version_key );
	}

	/**
	 * 1.8.2
	 *
	 * @param $version_key
	 *
	 * @return void
	 */
	protected function db_update_1_8_2( $version_key ) {
		if ( in_array( '1.0.0', $this->db_version, true ) ) {
			$this->update_db_version( $version_key );

			return;
		}

		/** Update special addon style */
		$data = Data::get_settings();

		$bg_color   = $data['css_bg_color'] ?? '#f9f9ff';
		$text_color = $data['css_primary_text_color'] ?? '#353030';
		$btn_color  = $data['strike_through_price_color'] ?? '#2DA815';

		$upsell_enabled = Data::is_upsells_enabled();
		if ( $upsell_enabled ) {
			$bg_color = $data['css_upsell_bg_color'] ?? $bg_color;
		}

		$data['special_addon_bg_color']      = $bg_color;
		$data['special_addon_heading_color'] = $text_color;
		$data['special_addon_toggle_color']  = $btn_color;

		update_option( 'fkcart_settings', $data, false );


		$this->update_db_version( $version_key );
	}

	/**
	 * 1.8.3
	 *
	 * @param $version_key
	 *
	 * @return void
	 */
	protected function db_update_1_8_3( $version_key ) {
		if ( in_array( '1.0.0', $this->db_version, true ) ) {
			$this->update_db_version( $version_key );

			return;
		}
		global $wpdb;
		$table_name = $wpdb->prefix . 'fk_cart';
		$column     = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM `{$table_name}` LIKE %s", 'onumber' ) );
		if ( empty( $column ) ) {
			$wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN `onumber` varchar(100) NOT NULL DEFAULT '' AFTER `oid`" );
			$max_id = $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$table_name}" );
			update_option( 'fkcart_order_maxid', $max_id );
		}
		$this->update_db_version( $version_key );
	}

	/**
	 * 1.8.4
	 *
	 * Heals installs where `fk_cart_products` was never created because its old
	 * schema declared `FOREIGN KEY (oid) REFERENCES fk_cart(oid)`, referencing a
	 * non-unique column. MySQL 8.0.16+ / MariaDB 10.5+ reject that constraint, so
	 * dbDelta() silently failed and the table went missing. The FK has been removed
	 * from create_db(); re-run it to create the table where it is absent.
	 *
	 * @param $version_key
	 *
	 * @return void
	 */
	protected function db_update_1_8_4( $version_key ) {
		if ( in_array( '1.0.0', $this->db_version, true ) ) {
			$this->update_db_version( $version_key );

			return;
		}

		global $wpdb;
		$wpdb->hide_errors();
		$wpdb->suppress_errors();

		$fk_cart_products = $wpdb->prefix . 'fk_cart_products';
		$table_exists     = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $fk_cart_products ) );
		if ( $table_exists !== $fk_cart_products ) {
			self::create_db();
		}

		$this->update_db_version( $version_key );
	}
}
