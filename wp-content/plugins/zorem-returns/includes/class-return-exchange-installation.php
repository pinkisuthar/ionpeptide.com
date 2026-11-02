<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
/**
 * ALP
 *
 * Class Return_Exchange_Installation
 *
 * @version       1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class Return_Exchange_Installation {

	/**
	 * Instance of this class.
	 *
	 * @var object Class Instance
	 */
	private static $instance;
	public $table;

	/**
	 * Function callback for add not existing key in database.
	 */
	public function return_exchange_update_install_callback() {
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare
		if ( version_compare( get_option( 'return_exchange_installation_version', '1.0' ), '1.1', '<' ) ) {

			global $wpdb;

			$wpdb->query( "ALTER TABLE {$wpdb->prefix}return_orders ADD COLUMN user_id BIGINT(20) NOT NULL AFTER order_id" );
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}exchange_orders ADD COLUMN user_id BIGINT(20) NOT NULL AFTER order_id" );

			// Fetch return requests
			$return_requests = $wpdb->get_results( "SELECT id, order_id FROM {$wpdb->prefix}return_orders" );
			foreach ( $return_requests as $return_request ) {
				$order_id = $return_request->order_id;
				$order    = wc_get_order( $order_id );
				if ( $order ) {
					// Get the user ID
					$user_id = $order->get_user_id();
					$wpdb->update( $wpdb->prefix . 'return_orders', array( 'user_id' => $user_id ), array( 'id' => $return_request->id ) );
				}
			}

			// Fetch exchange requests
			$exchange_requests = $wpdb->get_results( "SELECT id, order_id FROM {$wpdb->prefix}exchange_orders" );
			foreach ( $exchange_requests as $exchange_request ) {
				$order_id = $exchange_request->order_id;
				$order    = wc_get_order( $order_id );
				if ( $order ) {
					// Get the user ID
					$user_id = $order->get_user_id();
					$wpdb->update( $wpdb->prefix . 'exchange_orders', array( 'user_id' => $user_id ), array( 'id' => $exchange_request->id ) );
				}
			}

			update_option( 'return_exchange_installation_version', '1.1' );

		}

		if ( version_compare( get_option( 'return_exchange_installation_version', '1.1' ), '1.2', '<' ) ) {
			update_option( 'plugin_permalinks_flushed', 0 );
			update_option( 'return_exchange_installation_version', '1.2' );
		}

		if ( version_compare( get_option( 'return_exchange_installation_version', '1.2' ), '1.3', '<' ) ) {
			global $wpdb;

			$wpdb->query( "ALTER TABLE {$wpdb->prefix}return_orders ADD COLUMN return_note LONGTEXT DEFAULT NULL AFTER return_issue" );
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}exchange_orders ADD COLUMN exchange_note LONGTEXT DEFAULT NULL AFTER exchange_issue" );
			update_option( 'return_exchange_installation_version', '1.3' );
		}

		if ( version_compare( get_option( 'return_exchange_installation_version', '1.3' ), '1.4', '<' ) ) {
			global $wpdb;

			$wpdb->query("ALTER TABLE {$wpdb->prefix}return_orders ADD COLUMN return_label_pdf TEXT DEFAULT NULL AFTER return_note");
			update_option( 'return_exchange_installation_version', '1.4' );
		}

		if ( version_compare( get_option( 'return_exchange_installation_version', '1.4' ), '1.5', '<' ) ) {
			global $wpdb;

			$wpdb->query( "ALTER TABLE {$wpdb->prefix}exchange_orders ADD COLUMN keep_the_item VARCHAR(3) DEFAULT NULL AFTER exchange_note" );
			update_option( 'return_exchange_installation_version', '1.5' );
		}

		if ( version_compare( get_option( 'return_exchange_installation_version', '1.5' ), '1.6', '<' ) ) {
			global $wpdb;

			$wpdb->query( "ALTER TABLE {$wpdb->prefix}return_orders ADD COLUMN keep_the_item VARCHAR(3) DEFAULT NULL AFTER return_label_pdf" );
			update_option( 'return_exchange_installation_version', '1.6' );
		}

		if ( version_compare( get_option( 'return_exchange_installation_version', '1.6' ), '1.7', '<' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'exchange_orders';

			$cols = $wpdb->get_col(
				$wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ),
				0
			);

			if ( ! in_array( 'exchange_type', $cols, true ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'ALTER TABLE %i ADD COLUMN exchange_type VARCHAR(20) DEFAULT NULL',
						$table
					)
				);
			}

			if ( ! in_array( 'price_difference', $cols, true ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'ALTER TABLE %i ADD COLUMN price_difference DECIMAL(10,2) DEFAULT 0.00',
						$table
					)
				);
			}

			if ( ! in_array( 'new_product_id', $cols, true ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'ALTER TABLE %i ADD COLUMN new_product_id BIGINT(20) DEFAULT NULL',
						$table
					)
				);
			}

			if ( ! in_array( 'new_variation_id', $cols, true ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'ALTER TABLE %i ADD COLUMN new_variation_id BIGINT(20) DEFAULT NULL',
						$table
					)
				);
			}

			if ( ! in_array( 'store_credit_coupon', $cols, true ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'ALTER TABLE %i ADD COLUMN store_credit_coupon VARCHAR(100) DEFAULT NULL',
						$table
					)
				);
			}

			update_option( 'return_exchange_installation_version', '1.7' );
		}

		// 1.8 — Restocking fee columns on return_orders (snapshot of fee at submit time).
		if ( version_compare( get_option( 'return_exchange_installation_version', '1.7' ), '1.8', '<' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'return_orders';

			$cols = $wpdb->get_col(
				$wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ),
				0
			);

			if ( ! in_array( 'restocking_fee_amount', $cols, true ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'ALTER TABLE %i ADD COLUMN restocking_fee_amount DECIMAL(10,2) DEFAULT 0.00',
						$table
					)
				);
			}

			if ( ! in_array( 'restocking_fee_type', $cols, true ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'ALTER TABLE %i ADD COLUMN restocking_fee_type VARCHAR(20) DEFAULT NULL',
						$table
					)
				);
			}

			if ( ! in_array( 'restocking_fee_breakdown', $cols, true ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'ALTER TABLE %i ADD COLUMN restocking_fee_breakdown LONGTEXT DEFAULT NULL',
						$table
					)
				);
			}

			update_option( 'return_exchange_installation_version', '1.8' );
		}

		// 1.9 — Fraud Prevention: add created_at column on return_orders so we can count returns
		// within a rolling window per customer. Without this column, we can only fall back to the
		// linked order's created date (less accurate but workable for legacy rows).
		if ( version_compare( get_option( 'return_exchange_installation_version', '1.8' ), '1.9', '<' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'return_orders';

			$cols = $wpdb->get_col(
				$wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ),
				0
			);

			if ( ! in_array( 'created_at', $cols, true ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'ALTER TABLE %i ADD COLUMN created_at DATETIME DEFAULT NULL',
						$table
					)
				);
			}

			update_option( 'return_exchange_installation_version', '1.9' );
		}

		// 1.10 — Internal Admin Notes table. Stores private per-request notes left by
		// support staff (never shown to customers). One row per note so we can list/sort
		// chronologically and capture author + mentions cleanly.
		if ( version_compare( get_option( 'return_exchange_installation_version', '1.9' ), '1.10', '<' ) ) {
			global $wpdb;

			$table           = $wpdb->prefix . 'return_internal_notes';
			$charset_collate = $wpdb->get_charset_collate();

			$create_sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`request_id` BIGINT(20) UNSIGNED NOT NULL,
				`author_id` BIGINT(20) UNSIGNED NOT NULL,
				`note_content` LONGTEXT NOT NULL,
				`mentioned_user_ids` TEXT NULL,
				`created_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				KEY `request_id` (`request_id`),
				KEY `author_id` (`author_id`)
			) {$charset_collate};";

			// Run directly instead of dbDelta(): dbDelta cannot parse IF NOT EXISTS
			// (it reads the table name as "IF" and runs a broken "DESCRIBE IF;" query).
			$wpdb->query( $create_sql );

			update_option( 'return_exchange_installation_version', '1.10' );
		}

		// 1.11 — Warranty Claim Workflow table. Stores warranty claims separately from
		// returns/exchanges so the two flows can have independent statuses, resolutions,
		// and admin dashboards.
		if ( version_compare( get_option( 'return_exchange_installation_version', '1.10' ), '1.11', '<' ) ) {
			global $wpdb;

			$table           = $wpdb->prefix . 'warranty_claims';
			$charset_collate = $wpdb->get_charset_collate();

			$create_sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`order_id` BIGINT(20) UNSIGNED NOT NULL,
				`item_id` BIGINT(20) UNSIGNED DEFAULT NULL,
				`product_id` BIGINT(20) UNSIGNED DEFAULT NULL,
				`user_id` BIGINT(20) UNSIGNED DEFAULT NULL,
				`claim_status` VARCHAR(40) NOT NULL DEFAULT 'submitted',
				`claim_reason` VARCHAR(255) DEFAULT NULL,
				`claim_description` LONGTEXT,
				`claim_attachments` LONGTEXT,
				`resolution_type` VARCHAR(40) DEFAULT NULL,
				`resolution_note` LONGTEXT,
				`admin_user_id` BIGINT(20) UNSIGNED DEFAULT NULL,
				`created_at` DATETIME NOT NULL,
				`updated_at` DATETIME DEFAULT NULL,
				PRIMARY KEY (`id`),
				KEY `order_id` (`order_id`),
				KEY `user_id` (`user_id`),
				KEY `claim_status` (`claim_status`)
			) {$charset_collate};";

			// Run directly instead of dbDelta(): dbDelta cannot parse IF NOT EXISTS
			// (it reads the table name as "IF" and runs a broken "DESCRIBE IF;" query).
			$wpdb->query( $create_sql );

			update_option( 'return_exchange_installation_version', '1.11' );
		}

		// 1.12 — Exchange shipping label: stores the path of the admin-uploaded PDF label
		// on exchange_orders, mirroring return_orders.return_label_pdf for returns.
		if ( version_compare( get_option( 'return_exchange_installation_version', '1.11' ), '1.12', '<' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'exchange_orders';

			$cols = $wpdb->get_col(
				$wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ),
				0
			);

			if ( ! in_array( 'exchange_label_pdf', $cols, true ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'ALTER TABLE %i ADD COLUMN exchange_label_pdf TEXT DEFAULT NULL',
						$table
					)
				);
			}

			update_option( 'return_exchange_installation_version', '1.12' );
		}
	}

	/**
	 * Get the class instance
	 *
	 * @return Return_Exchange_Installation
	 */
	public static function get_instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}
