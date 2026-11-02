<?php
/**
 * Database layer: index table + blocklist table, plus the aggregate
 * "profile" queries that feed the scoring engine.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Data {

	/**
	 * Which revision of the address parser derived an index row's geo columns
	 * (ship_street, ship_door_hash). The geo backfill re-derives every row
	 * stamped below this, so a parser change is a one-line bump here plus a
	 * backfill — never a manual "please click the button" step.
	 *
	 *  0 — never geo-indexed (pre-v1.8), or the v1.25/v1.25.1 parser
	 *      (number + street + ZIP door key; "66th" and "66" two streets).
	 *  2 — v1.31: the state-anchored door key, every ordinal collapsed,
	 *      directionals and type aliases aligned, line 2 consulted.
	 *  3 — v1.31.1: spelled-out ordinals, split suffixes, glued directionals,
	 *      "No." markers, box-first lines, the destination-tail cut.
	 *
	 * @var int
	 */
	const GEO_SCHEME = 3;

	/**
	 * Cron hook that drives the background geo backfill (v1.31).
	 *
	 * @var string
	 */
	const CRON_GEO = 'fs_geo_backfill';

	/**
	 * Order index table name.
	 *
	 * @return string
	 */
	public static function index_table() {
		global $wpdb;
		return $wpdb->prefix . 'fs_order_index';
	}

	/**
	 * Blocklist table name.
	 *
	 * @return string
	 */
	public static function blocklist_table() {
		global $wpdb;
		return $wpdb->prefix . 'fs_blocklist';
	}

	/**
	 * Block-actions table name. Each row is one "block" the merchant performed
	 * (a profile, address, IP, manual entry, or import batch) and owns the
	 * blocklist credentials it created via the junction table.
	 *
	 * @return string
	 */
	public static function block_actions_table() {
		global $wpdb;
		return $wpdb->prefix . 'fs_block_actions';
	}

	/**
	 * Junction table linking block actions to the blocklist entries they own.
	 * Many-to-many: one credential can be owned by several actions, so
	 * unblocking one action never removes a credential another action still needs.
	 *
	 * @return string
	 */
	public static function block_action_entries_table() {
		global $wpdb;
		return $wpdb->prefix . 'fs_block_action_entries';
	}

	/**
	 * Checkout-block ledger table name. A durable record of who was actually
	 * blocked at checkout (velocity / blocklist), so the Checkout Locks page
	 * reflects real events instead of reconstructing them from the order index.
	 *
	 * @return string
	 */
	public static function checkout_blocks_table() {
		global $wpdb;
		return $wpdb->prefix . 'fs_checkout_blocks';
	}

	/**
	 * Didit ID-verification sessions table name.
	 *
	 * @return string
	 */
	public static function idv_sessions_table() {
		global $wpdb;
		return $wpdb->prefix . 'fs_idv_sessions';
	}

	/**
	 * Linked-identity auto-cancel ledger (v1.26). One row per order Fraud
	 * Shield cancelled because its customer shares a credential with a blocked
	 * identity: who, what was shared, with whom, and how many times they came
	 * back afterwards. The Auto-Cancelled page reads this; the order itself
	 * carries the same facts in `_fs_linked_cancel` meta and an order note.
	 *
	 * @return string
	 */
	public static function linked_cancels_table() {
		global $wpdb;
		return $wpdb->prefix . 'fs_linked_cancels';
	}

	/**
	 * Fraud-prevented ledger (v1.26). One row per checkout Fraud Shield
	 * stopped — an order it cancelled, or an attempt it refused before an
	 * order existed — with the money that would have changed hands. The
	 * Dashboard's "Fraud prevented" panel is a sum over this table. Refused
	 * attempts are coalesced per identity, rule and day (retries bump
	 * `attempts`), so a bot hammering Place Order cannot inflate the figure.
	 *
	 * @return string
	 */
	public static function prevented_table() {
		global $wpdb;
		return $wpdb->prefix . 'fs_prevented';
	}

	/**
	 * High-value review ledger (v1.32). One row per verdict a person (or an
	 * API caller) recorded on a high-value order — append-only, so a second
	 * look at the same order is a second row and the latest row per order is
	 * its current verdict. The High-Value Review page's queue is a view over
	 * the order index joined to this ledger; its leaderboard is a count over
	 * it. Customer and order facts are snapshotted so the history still reads
	 * when the order or its index row is gone.
	 *
	 * @return string
	 */
	public static function hv_reviews_table() {
		global $wpdb;
		return $wpdb->prefix . 'fs_hv_reviews';
	}

	/**
	 * Search + result filter fragment for ID-verification session queries.
	 *
	 * @param string $search Raw search.
	 * @param string $result Result filter: '' (any), or one of the stored
	 *                      result values, or 'pending' for rows still running.
	 * @param array  $params Params (appended by reference).
	 * @return string SQL starting with ' WHERE ' (always non-empty).
	 */
	private static function idv_where_sql( $search, $result, &$params ) {
		global $wpdb;
		$where  = ' WHERE 1=1';
		$search = trim( (string) $search );

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			for ( $i = 0; $i < 4; $i++ ) {
				$params[] = $like;
			}
			$where .= ' AND (email_norm LIKE %s OR full_name LIKE %s OR expected_name LIKE %s OR session_id LIKE %s)';
		}

		$result = (string) $result;
		if ( 'pending' === $result ) {
			// Still in flight: no terminal result recorded yet.
			$where .= " AND result = ''";
		} elseif ( in_array( $result, array( 'approved', 'name_mismatch', 'declined', 'expired' ), true ) ) {
			$where   .= ' AND result = %s';
			$params[] = $result;
		}

		return $where;
	}

	/**
	 * Count ID-verification sessions matching a search + result filter.
	 *
	 * @param string $search Free-text search.
	 * @param string $result Result filter (see idv_where_sql()).
	 * @return int
	 */
	public static function idv_sessions_count( $search = '', $result = '' ) {
		global $wpdb;
		$t      = self::idv_sessions_table();
		$params = array();
		$where  = self::idv_where_sql( $search, $result, $params );

		$sql = "SELECT COUNT(*) FROM {$t}{$where}";
		return empty( $params )
			? (int) $wpdb->get_var( $sql ) // phpcs:ignore WordPress.DB
			: (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * ID-verification sessions, newest first by default. Sortable + searchable.
	 *
	 * @param array $args orderby, dir, orderby2, dir2, search, result, limit, offset.
	 * @return array
	 */
	public static function idv_sessions_list( $args = array() ) {
		global $wpdb;
		$a = wp_parse_args(
			$args,
			array(
				'orderby'  => 'updated',
				'dir'      => 'desc',
				'orderby2' => '',
				'dir2'     => 'desc',
				'search'   => '',
				'result'   => '',
				'limit'    => 50,
				'offset'   => 0,
			)
		);
		$t = self::idv_sessions_table();

		$sort_map = array(
			'email'    => 'email_norm',
			'name'     => 'full_name',
			'expected' => 'expected_name',
			'result'   => 'result',
			'status'   => 'status',
			'doc'      => 'doc_type',
			'match'    => 'name_match',
			'created'  => 'created_at',
			'updated'  => 'updated_at',
		);
		$order_by = self::order_by_2( $sort_map, $a['orderby'], $a['dir'], $a['orderby2'], $a['dir2'], 'updated_at', 'id DESC' );

		$params = array();
		$where  = self::idv_where_sql( $a['search'], $a['result'], $params );
		array_push( $params, (int) $a['limit'], (int) $a['offset'] );

		$sql = "SELECT * FROM {$t}{$where} ORDER BY {$order_by} LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Every ID-verification session belonging to one customer (account id or
	 * email), newest first — the history shown on a customer's profile.
	 *
	 * @param int    $user_id    Account id (0 = email only).
	 * @param string $email_norm Normalized email.
	 * @param int    $limit      Max rows.
	 * @return array
	 */
	public static function idv_sessions_for_customer( $user_id, $email_norm, $limit = 20 ) {
		global $wpdb;
		$t       = self::idv_sessions_table();
		$user_id = (int) $user_id;
		$email   = FS_Normalizer::email( $email_norm );

		if ( $user_id > 0 && '' !== $email ) {
			$sql    = "SELECT * FROM {$t} WHERE user_id = %d OR email_norm = %s ORDER BY id DESC LIMIT %d";
			$params = array( $user_id, $email, (int) $limit );
		} elseif ( $user_id > 0 ) {
			$sql    = "SELECT * FROM {$t} WHERE user_id = %d ORDER BY id DESC LIMIT %d";
			$params = array( $user_id, (int) $limit );
		} elseif ( '' !== $email ) {
			$sql    = "SELECT * FROM {$t} WHERE email_norm = %s ORDER BY id DESC LIMIT %d";
			$params = array( $email, (int) $limit );
		} else {
			return array();
		}

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Attempts per account for a set of accounts, in one query — so a queue of
	 * customers doesn't cost a query each.
	 *
	 * @param array $user_ids Account ids.
	 * @return array user_id => attempt count.
	 */
	public static function idv_attempt_counts( $user_ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $user_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$t  = self::idv_sessions_table();
		$in = implode( ',', $ids ); // Ints only, built above.

		$rows = $wpdb->get_results( "SELECT user_id, COUNT(*) c FROM {$t} WHERE user_id IN ({$in}) GROUP BY user_id", ARRAY_A ); // phpcs:ignore WordPress.DB

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['user_id'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * Headline counts for the ID Verifications page.
	 *
	 * @return array { total, approved, name_mismatch, declined, expired, pending, people }
	 */
	public static function idv_stats() {
		global $wpdb;
		$t = self::idv_sessions_table();

		$rows = $wpdb->get_results( "SELECT result, COUNT(*) c FROM {$t} GROUP BY result", ARRAY_A ); // phpcs:ignore WordPress.DB

		$out = array(
			'total'         => 0,
			'approved'      => 0,
			'name_mismatch' => 0,
			'declined'      => 0,
			'expired'       => 0,
			'pending'       => 0,
			'people'        => 0,
		);
		foreach ( (array) $rows as $r ) {
			$c            = (int) $r['c'];
			$out['total'] += $c;
			$key          = '' === (string) $r['result'] ? 'pending' : (string) $r['result'];
			if ( isset( $out[ $key ] ) ) {
				$out[ $key ] += $c;
			}
		}
		$out['people'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT email_norm) FROM {$t} WHERE email_norm <> ''" ); // phpcs:ignore WordPress.DB

		return $out;
	}

	/**
	 * Create / upgrade tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// The schema version this site is coming FROM (0 = fresh install, or
		// the probe rigs, which keep no options). Read before anything below
		// can touch it; the version-gated upgrade steps at the end need it.
		$from = (int) get_option( 'fs_db_version', 0 );

		$charset_collate = $wpdb->get_charset_collate();
		$index           = self::index_table();
		$blocklist       = self::blocklist_table();
		$actions         = self::block_actions_table();
		$action_entries  = self::block_action_entries_table();
		$checkout_blocks = self::checkout_blocks_table();

		$sql_index = "CREATE TABLE {$index} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			order_date DATETIME NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			customer_name VARCHAR(191) NULL,
			email_norm VARCHAR(191) NULL,
			phone_norm VARCHAR(32) NULL,
			ship_hash CHAR(64) NULL,
			ship_loose_hash CHAR(64) NULL,
			ship_text VARCHAR(255) NULL,
			ship_city VARCHAR(100) NULL,
			ship_state VARCHAR(32) NULL,
			ship_postcode VARCHAR(16) NULL,
			ship_street VARCHAR(100) NULL,
			ship_door_hash VARCHAR(64) NULL,
			geo_scheme TINYINT UNSIGNED NOT NULL DEFAULT 0,
			billing_country VARCHAR(8) NULL,
			shipping_country VARCHAR(8) NULL,
			ip VARCHAR(64) NULL,
			order_total DECIMAL(18,2) NULL DEFAULT 0,
			status VARCHAR(32) NULL,
			payment_method VARCHAR(100) NULL,
			ship_method VARCHAR(191) NULL,
			coupons VARCHAR(255) NULL,
			user_agent VARCHAR(255) NULL,
			bill_hash CHAR(64) NULL,
			is_chargeback TINYINT(1) NOT NULL DEFAULT 0,
			recovered DECIMAL(18,2) NOT NULL DEFAULT 0,
			risk_score INT NOT NULL DEFAULT 0,
			risk_level VARCHAR(16) NULL,
			risk_reasons TEXT NULL,
			indexed_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY email_norm (email_norm),
			KEY phone_norm (phone_norm),
			KEY ship_hash (ship_hash),
			KEY ship_loose_hash (ship_loose_hash),
			KEY ip (ip),
			KEY is_chargeback (is_chargeback),
			KEY user_id (user_id),
			KEY risk_level (risk_level),
			KEY order_date (order_date),
			KEY ship_geo (shipping_country,ship_state,ship_city),
			KEY ship_postcode (ship_postcode),
			KEY ship_street (ship_street),
			KEY ship_door_hash (ship_door_hash),
			KEY geo_scheme (geo_scheme)
		) {$charset_collate};";

		$sql_blocklist = "CREATE TABLE {$blocklist} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(16) NOT NULL,
			value VARCHAR(191) NOT NULL,
			reason VARCHAR(255) NULL,
			message TEXT NULL,
			added_by BIGINT UNSIGNED NULL,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_value (type,value),
			KEY type (type)
		) {$charset_collate};";

		// v1.6 (DB v5): reversible, grouped blocking. A "block action" owns the
		// credentials it cascades onto the blocklist; the junction table records
		// that ownership without touching the existing fs_blocklist schema.
		$sql_actions = "CREATE TABLE {$actions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(16) NOT NULL,
			target VARCHAR(191) NOT NULL,
			title VARCHAR(255) NULL,
			message TEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY kind_target (kind,target),
			KEY kind (kind)
		) {$charset_collate};";

		$sql_action_entries = "CREATE TABLE {$action_entries} (
			action_id BIGINT UNSIGNED NOT NULL,
			entry_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (action_id,entry_id),
			KEY entry_id (entry_id)
		) {$charset_collate};";

		// v1.10 (DB v7): durable ledger of real checkout-block events, so the
		// Checkout Locks page shows who was actually stopped (and CS can release
		// them) instead of reconstructing a fragile list from indexed orders.
		// v1.29 (DB v19): `evidence` keeps WHAT matched on the latest attempt —
		// each blocked credential as typed, who blocked it and when, under
		// which block, and the identities behind it — so a refused checkout
		// that never became an order still answers "which credentials, and
		// matched to whom?".
		$sql_checkout_blocks = "CREATE TABLE {$checkout_blocks} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(16) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email VARCHAR(191) NOT NULL DEFAULT '',
			name VARCHAR(191) NULL,
			detail VARCHAR(255) NULL,
			evidence LONGTEXT NULL,
			attempts INT NOT NULL DEFAULT 1,
			first_at DATETIME NULL,
			last_at DATETIME NULL,
			resolved TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY ident (kind,user_id,email),
			KEY resolved (resolved),
			KEY last_at (last_at)
		) {$charset_collate};";

		dbDelta( $sql_index );
		dbDelta( $sql_blocklist );
		dbDelta( $sql_actions );
		dbDelta( $sql_action_entries );
		dbDelta( $sql_checkout_blocks );

		// v1.14 (DB v9): Didit ID-verification sessions — one row per hosted
		// verification a customer starts, updated by webhook or the polling
		// fallback. Stores the decision + extracted name only; never images.
		// v1.18 (DB v11) adds the decision context Didit already returns and we
		// previously threw away: where the document was issued (country/state),
		// where the customer physically was while verifying (IP country/state,
		// VPN or Tor flag), and Didit's own risk warnings. Location + flags
		// only; still no images, no document numbers, no date of birth.
		$idv_sessions = self::idv_sessions_table();

		$sql_idv_sessions = "CREATE TABLE {$idv_sessions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(64) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email_norm VARCHAR(191) NOT NULL DEFAULT '',
			expected_name VARCHAR(191) NOT NULL DEFAULT '',
			url TEXT NULL,
			status VARCHAR(32) NOT NULL DEFAULT '',
			result VARCHAR(32) NOT NULL DEFAULT '',
			full_name VARCHAR(191) NOT NULL DEFAULT '',
			doc_type VARCHAR(64) NOT NULL DEFAULT '',
			name_match VARCHAR(8) NOT NULL DEFAULT '',
			doc_country VARCHAR(8) NOT NULL DEFAULT '',
			doc_state VARCHAR(64) NOT NULL DEFAULT '',
			verify_ip_country VARCHAR(8) NOT NULL DEFAULT '',
			verify_ip_state VARCHAR(64) NOT NULL DEFAULT '',
			verify_vpn TINYINT(1) NOT NULL DEFAULT 0,
			warnings TEXT NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY user_id (user_id),
			KEY email_norm (email_norm)
		) {$charset_collate};";

		dbDelta( $sql_idv_sessions );

		// v1.24 (DB v12): the API + webhook subsystem.
		//
		// fs_api_keys holds credentials as digests, never secrets. fs_api_events
		// is the immutable record of what happened, readable over the API by a
		// platform that would rather poll than expose an endpoint.
		// fs_webhook_deliveries is one attempt per subscription per event, so a
		// broken endpoint retries its own backlog without touching anyone
		// else's; its (status,next_attempt_at) index is the only thing the
		// dispatcher's hot query touches. Every timestamp in these four tables
		// is UTC — a retry queue must not shift when a store changes timezone.
		$api_keys   = FS_API_Keys::table();
		$hooks      = FS_Webhooks::table();
		$events     = FS_Webhooks::events_table();
		$deliveries = FS_Webhooks::deliveries_table();

		$sql_api_keys = "CREATE TABLE {$api_keys} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			key_id CHAR(16) NOT NULL,
			secret_hash CHAR(64) NOT NULL,
			label VARCHAR(191) NOT NULL DEFAULT '',
			scopes VARCHAR(255) NOT NULL DEFAULT '',
			ip_allow TEXT NULL,
			require_sig TINYINT(1) NOT NULL DEFAULT 0,
			rate_limit INT NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			origin VARCHAR(16) NOT NULL DEFAULT 'admin',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NULL,
			expires_at DATETIME NULL,
			last_used_at DATETIME NULL,
			last_ip VARCHAR(64) NOT NULL DEFAULT '',
			use_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY key_id (key_id),
			KEY status (status)
		) {$charset_collate};";

		$sql_hooks = "CREATE TABLE {$hooks} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(500) NOT NULL,
			secret VARCHAR(64) NOT NULL,
			topics VARCHAR(500) NOT NULL DEFAULT '',
			label VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			hash_pii TINYINT(1) NOT NULL DEFAULT 0,
			failures INT NOT NULL DEFAULT 0,
			last_status INT NOT NULL DEFAULT 0,
			last_error VARCHAR(191) NOT NULL DEFAULT '',
			last_delivered_at DATETIME NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset_collate};";

		$sql_events = "CREATE TABLE {$events} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			topic VARCHAR(40) NOT NULL DEFAULT '',
			payload LONGTEXT NULL,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY topic (topic),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_deliveries = "CREATE TABLE {$deliveries} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			webhook_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			topic VARCHAR(40) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			attempts INT NOT NULL DEFAULT 0,
			response_code INT NOT NULL DEFAULT 0,
			error VARCHAR(191) NOT NULL DEFAULT '',
			next_attempt_at DATETIME NULL,
			created_at DATETIME NULL,
			delivered_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY due (status,next_attempt_at),
			KEY webhook_id (webhook_id),
			KEY event_id (event_id)
		) {$charset_collate};";

		dbDelta( $sql_api_keys );
		dbDelta( $sql_hooks );
		dbDelta( $sql_events );
		dbDelta( $sql_deliveries );

		// v1.26 (DB v15): the linked-identity auto-cancel ledger. A row is
		// written the moment an order is cancelled for sharing a credential
		// with a blocked identity; `links` keeps the full evidence as JSON
		// (which credential, shared with which blocked entry, under which block
		// card) and `attempts` counts the checkouts refused at the gate after
		// the cancel, so a retrying customer produces one row, not a pile of
		// cancelled orders. Local time, like the checkout-block ledger.
		$linked = self::linked_cancels_table();

		$sql_linked = "CREATE TABLE {$linked} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email_norm VARCHAR(191) NOT NULL DEFAULT '',
			customer_name VARCHAR(191) NULL,
			order_total DECIMAL(18,2) NULL DEFAULT 0,
			currency VARCHAR(8) NULL,
			summary VARCHAR(255) NULL,
			links TEXT NULL,
			attempts INT NOT NULL DEFAULT 1,
			cancelled_at DATETIME NULL,
			last_attempt_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY email_norm (email_norm),
			KEY user_id (user_id),
			KEY cancelled_at (cancelled_at)
		) {$charset_collate};";

		dbDelta( $sql_linked );

		// v1.26 (DB v16): the fraud-prevented ledger behind the Dashboard's
		// savings panel. `dedupe_key` is 'o:<order id>' for a cancelled order
		// (each order counts once, whatever else happens to it) and
		// 'r:<kind>:<user>:<email>:<day>' for a refused attempt (one row per
		// identity, rule and day; retries bump attempts and keep the largest
		// cart seen). `kind` is the control that stopped it.
		//
		// v1.30 (DB v20): `linked_to` names the blocked identities the stop
		// was tied to — the person behind the shared address, phone or
		// account (linked rule), or behind the matched credential (blocklist)
		// — so the Financial Protection estimate can weigh the stop by THEIR
		// chargeback history, not only the stopped customer's (usually empty)
		// one. `stopped_by` is 'rule' when a control stopped the checkout and
		// 'staff' when a person cancelled or refunded an order that was on
		// the blocklist or linked to it.
		$prevented = self::prevented_table();

		$sql_prevented = "CREATE TABLE {$prevented} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			dedupe_key VARCHAR(191) NOT NULL,
			kind VARCHAR(16) NOT NULL,
			stage VARCHAR(12) NOT NULL DEFAULT 'refused',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email_norm VARCHAR(191) NOT NULL DEFAULT '',
			customer_name VARCHAR(191) NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NULL,
			attempts INT NOT NULL DEFAULT 1,
			first_at DATETIME NULL,
			last_at DATETIME NULL,
			linked_to TEXT NULL,
			stopped_by VARCHAR(12) NOT NULL DEFAULT 'rule',
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe_key (dedupe_key),
			KEY kind (kind),
			KEY first_at (first_at),
			KEY email_norm (email_norm)
		) {$charset_collate};";

		dbDelta( $sql_prevented );

		// v1.32 (DB v23): the high-value review ledger. Append-only — every
		// verdict a reviewer records is one row, the latest row per order is
		// the order's current verdict — so a second look is kept beside the
		// first and the leaderboard counts every check that was made. `source`
		// says where the verdict was recorded (the review page, the order
		// screen's box, or the API); `status_before` / `status_after` keep
		// what the verdict did to the order. Site-local time, like the other
		// ledgers.
		$hv = self::hv_reviews_table();

		$sql_hv = "CREATE TABLE {$hv} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email_norm VARCHAR(191) NOT NULL DEFAULT '',
			customer_name VARCHAR(191) NULL,
			order_total DECIMAL(18,2) NULL DEFAULT 0,
			currency VARCHAR(8) NULL,
			verdict VARCHAR(16) NOT NULL,
			note TEXT NULL,
			status_before VARCHAR(32) NULL,
			status_after VARCHAR(32) NULL,
			reviewer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			reviewer_name VARCHAR(191) NULL,
			source VARCHAR(12) NOT NULL DEFAULT 'admin',
			reviewed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY reviewer_id (reviewer_id),
			KEY reviewed_at (reviewed_at),
			KEY verdict (verdict)
		) {$charset_collate};";

		dbDelta( $sql_hv );

		// Orders the linked-identity rule cancelled before this ledger existed
		// carry their amounts on their own ledger; count them once here.
		$wpdb->query( // phpcs:ignore
			"INSERT IGNORE INTO {$prevented} (dedupe_key, kind, stage, order_id, user_id, email_norm, customer_name, amount, currency, attempts, first_at, last_at)
			SELECT CONCAT('o:', order_id), 'linked', 'cancelled', order_id, user_id, email_norm, customer_name, COALESCE(order_total, 0), currency, 1, cancelled_at, cancelled_at
			FROM {$linked}"
		);

		// v1.30 (DB v20): rows the linked rule wrote before `linked_to`
		// existed learn whom the stop was tied to from the Auto-Cancelled
		// ledger, whose links already name the identities behind each shared
		// credential. Only rows still missing the column's value are visited,
		// so the pass is idempotent and cheap after the first run.
		self::backfill_prevented_linked_to();

		// v1.26.1 (DB v18): 1.26.0 recorded a customer's same-day retry after a
		// linked cancel as a second, refused row — the same cart counted
		// twice. Fold any such row into the cancelled order's row (attempts
		// added, largest cart kept, latest time kept) and drop it. Idempotent:
		// once folded there is nothing left to match.
		$wpdb->query( // phpcs:ignore
			"UPDATE {$prevented} c INNER JOIN {$prevented} r
				ON r.kind = 'linked' AND r.stage = 'refused'
				AND r.user_id = c.user_id AND r.email_norm = c.email_norm
				AND DATE(r.first_at) = DATE(c.first_at)
			SET c.attempts = c.attempts + r.attempts,
				c.amount = GREATEST(c.amount, r.amount),
				c.last_at = GREATEST(COALESCE(c.last_at, c.first_at), COALESCE(r.last_at, r.first_at))
			WHERE c.kind = 'linked' AND c.stage = 'cancelled' AND c.order_id > 0"
		);
		$wpdb->query( // phpcs:ignore
			"DELETE r FROM {$prevented} r INNER JOIN {$prevented} c
				ON c.kind = 'linked' AND c.stage = 'cancelled' AND c.order_id > 0
				AND c.user_id = r.user_id AND c.email_norm = r.email_norm
				AND DATE(c.first_at) = DATE(r.first_at)
			WHERE r.kind = 'linked' AND r.stage = 'refused'"
		);

		// v1.25 (DB v13): adds ship_street to the order index (dbDelta above).
		// Existing rows get NULL, which is what puts them in front of the geo
		// backfill on the Geography page; new orders write it on the way in.
		//
		// v1.25.1 (DB v14): adds ship_door_hash — the unit-blind door key
		// (house number + street + postcode) that closes the re-registration
		// hole: a blocked customer re-entering their blocked address with the
		// unit rewritten, moved to the other address line, or the case changed
		// used to produce fresh exact AND loose hashes and walk through. The
		// backfill cursor moved to ship_door_hash then; since v1.31 (DB v21,
		// below) it is the geo_scheme stamp, which covers both.

		// v1.16: per-customer gateway restrictions now carry the date they were
		// applied so they can lapse on their own. Records saved before this have
		// no such date, and an undated record can never expire — so stamp them
		// once here, starting their window from the upgrade. Idempotent: the
		// sweep only touches records that are still missing the stamp.
		if ( class_exists( 'FS_Live' ) ) {
			FS_Live::sweep_gw_restrictions( 1000 );
		}

		// v1.31 (DB v21): the door key changes shape — number + street + STATE
		// for US/CA/AU, blind to the ZIP and the city — and the street parser
		// collapses every ordinal and aligns directionals and type aliases.
		// Three things carry the existing blocks across:
		//   1. every 'address' blocklist entry gets the v1.31 door key of the
		//      orders behind it planted beside it, under the same block card
		//      (migrate_door_entries, drained by the backfill batches);
		//   2. every street lock's stored key is rewritten to the aligned
		//      street name, in place, right now (it is a handful of rows);
		//   3. every index row is re-derived (geo_scheme is the cursor; the
		//      new column defaults every existing row to 0 = "re-derive").
		// The backfill runs itself from cron: nobody has to press a button for
		// the blocks they already placed to keep working.
		//
		// v1.31.1 (DB v22): the parser changed again (GEO_SCHEME 3), so the same
		// three steps run again — every row re-derived, standing address blocks
		// given their current door key, street locks realigned. Any future
		// parser change is this block with the version raised.
		if ( $from > 0 && $from < 22 ) {
			update_option( 'fs_door_v2_cursor', 0 );
			delete_option( 'fs_door_v2_done' );
			FS_Blocklist::realign_street_locks();
		} elseif ( 0 === $from ) {
			// Nothing to carry forward on a fresh install.
			update_option( 'fs_door_v2_done', 1 );
		}
		if ( self::geo_backfill_remaining() > 0 ) {
			self::schedule_geo_backfill();
		}

		update_option( 'fs_db_version', FS_DB_VERSION );
	}

	/**
	 * Resolve an order's shipping address parts, falling back to billing when no
	 * separate shipping address was entered. Shared by extract_signals() and the
	 * geo backfill so both derive geography from the exact same address.
	 *
	 * @param WC_Order $order Order object.
	 * @return array Parts: address_1, address_2, city, state, postcode, country.
	 */
	public static function resolve_shipping( $order ) {
		$ship = array(
			'address_1' => $order->get_shipping_address_1(),
			'address_2' => $order->get_shipping_address_2(),
			'city'      => $order->get_shipping_city(),
			'state'     => $order->get_shipping_state(),
			'postcode'  => $order->get_shipping_postcode(),
			'country'   => $order->get_shipping_country(),
		);
		if ( '' === trim( (string) $ship['address_1'] ) ) {
			$ship = array(
				'address_1' => $order->get_billing_address_1(),
				'address_2' => $order->get_billing_address_2(),
				'city'      => $order->get_billing_city(),
				'state'     => $order->get_billing_state(),
				'postcode'  => $order->get_billing_postcode(),
				'country'   => $order->get_billing_country(),
			);
		}
		return $ship;
	}

	/**
	 * Pull normalized signals out of a WC_Order (HPOS-safe via CRUD).
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	public static function extract_signals( $order ) {
		$ship = self::resolve_shipping( $order );

		$ship_text = trim(
			$ship['address_1'] . ' ' . $ship['address_2'] . ', ' .
			$ship['city'] . ' ' . $ship['state'] . ' ' . $ship['postcode'] . ' ' . $ship['country']
		);

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		}

		$bill = array(
			'address_1' => $order->get_billing_address_1(),
			'address_2' => $order->get_billing_address_2(),
			'city'      => $order->get_billing_city(),
			'state'     => $order->get_billing_state(),
			'postcode'  => $order->get_billing_postcode(),
			'country'   => $order->get_billing_country(),
		);

		$coupons = method_exists( $order, 'get_coupon_codes' ) ? (array) $order->get_coupon_codes() : array();

		$date = $order->get_date_created();

		return array(
			'order_id'         => $order->get_id(),
			'order_date'       => $date ? $date->date( 'Y-m-d H:i:s' ) : null,
			'user_id'          => (int) $order->get_customer_id(),
			'customer_name'    => mb_substr( $name, 0, 191 ),
			'email_norm'       => FS_Normalizer::email( $order->get_billing_email() ),
			'email_domain'     => FS_Normalizer::email_domain( $order->get_billing_email() ),
			'phone_norm'       => FS_Normalizer::phone( $order->get_billing_phone() ),
			'ship_hash'        => FS_Normalizer::hash( FS_Normalizer::address( $ship ) ),
			'ship_loose_hash'  => FS_Normalizer::hash( FS_Normalizer::address_loose( $ship ) ),
			'ship_text'        => mb_substr( $ship_text, 0, 255 ),
			// Geo columns come from the SAME resolved $ship array as the hashes,
			// so geography always describes the address ship_hash describes.
			'ship_city'        => FS_Normalizer::city( $ship['city'] ),
			'ship_state'       => FS_Normalizer::region( $ship['state'] ),
			'ship_postcode'    => FS_Normalizer::postcode( $ship['postcode'] ),
			// v1.25: the street NAME (house number and unit stripped) — what a
			// street lock keys on and the Geography page rolls up by.
			'ship_street'      => FS_Normalizer::street( $ship ),
			// v1.25.1 / v1.31: the door key hash — number + street + state (US,
			// CA, AU) or postcode, blind to the unit, the ZIP and the city.
			'ship_door_hash'   => FS_Normalizer::door( $ship ),
			// v1.31: the extra door keys an address typed WITHOUT its street
			// type is checked against at the blocklist. Not stored.
			'ship_door_alts'   => FS_Normalizer::door_alternates( $ship ),
			// v1.31: the house number (building locks) and the raw parts
			// (address validation resolves the physical destination from
			// them). Neither is stored.
			'ship_number'      => FS_Normalizer::house( $ship ),
			'ship_parts'       => $ship,
			// v1.31: which parser derived the geo columns (see GEO_SCHEME).
			'geo_scheme'       => self::GEO_SCHEME,
			'billing_country'  => $order->get_billing_country(),
			'shipping_country' => $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country(),
			'ip'               => $order->get_customer_ip_address(),
			'order_total'      => (float) $order->get_total(),
			'status'           => $order->get_status(),
			'payment_method'   => $order->get_payment_method_title() ? $order->get_payment_method_title() : $order->get_payment_method(),
			'ship_method'      => mb_substr( (string) $order->get_shipping_method(), 0, 191 ),
			'coupons'          => mb_substr( implode( ',', array_map( 'strval', $coupons ) ), 0, 255 ),
			'user_agent'       => mb_substr( (string) $order->get_customer_user_agent(), 0, 255 ),
			'bill_hash'        => FS_Normalizer::hash( FS_Normalizer::address( $bill ) ),
			// v1.26: money won back on a charged-back order (a dispute won, a
			// partial reversal), recorded by staff on the order screen. Read
			// defensively: the same signals are also built from order-shaped
			// stand-ins (imports, probes) that carry no meta at all.
			'recovered'        => method_exists( $order, 'get_meta' ) ? max( 0.0, (float) $order->get_meta( '_fs_cb_recovered' ) ) : 0.0,
		);
	}

	/**
	 * Decide whether an order counts as a confirmed chargeback / fraud loss.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $settings Plugin settings.
	 * @return bool
	 */
	public static function is_chargeback( $order, $settings ) {
		if ( 'charged-back' === $order->get_status() ) {
			return true;
		}
		if ( 'yes' === $order->get_meta( '_fs_chargeback' ) ) {
			return true;
		}
		if ( ! empty( $settings['count_refunded_as_chargeback'] ) && 'refunded' === $order->get_status() ) {
			return true;
		}
		return false;
	}

	/**
	 * Mark an order as charged back by hand, or clear the mark (v1.30: the one
	 * path the order meta box and `POST/DELETE /orders/{id}/chargeback` share,
	 * so both write the same note, re-index the same way and raise the same
	 * `order.chargeback` event).
	 *
	 * @param WC_Order $order  Order.
	 * @param bool     $flag   True to mark, false to clear the manual mark.
	 * @param string   $source Who did it, for the event: 'manual' (wp-admin) | 'api'.
	 * @return bool Whether the order now counts as charged back (a cleared mark
	 *              may still count through the Charged back status or a refund).
	 */
	public static function set_chargeback( $order, $flag, $source = 'manual' ) {
		$settings = FS_Core::instance()->settings();
		$before   = self::is_chargeback( $order, $settings );
		if ( $flag ) {
			$order->update_meta_data( '_fs_chargeback', 'yes' );
			$order->add_order_note( 'api' === $source ? __( 'Fraud Shield: marked as charged back over the API.', 'fraud-shield' ) : __( 'Fraud Shield: manually marked as charged back.', 'fraud-shield' ) );
		} else {
			$order->delete_meta_data( '_fs_chargeback' );
			$order->add_order_note( 'api' === $source ? __( 'Fraud Shield: chargeback flag cleared over the API.', 'fraud-shield' ) : __( 'Fraud Shield: chargeback flag cleared.', 'fraud-shield' ) );
		}
		$order->save();

		$after = self::is_chargeback( $order, $settings );
		self::upsert_index( self::extract_signals( $order ), $after );
		if ( $after !== $before ) {
			self::emit_chargeback( $order, $after, $source );
		}
		if ( $after ) {
			// A charged-back order is a recorded loss, not a stop: a savings
			// row a staff cancellation or refund earned it is withdrawn (v1.30).
			// Rows a control wrote at checkout stay — that verdict happened.
			self::withdraw_staff_prevented( (int) $order->get_id() );
		}
		return $after;
	}

	/**
	 * Remove a staff-attributed savings row for an order (see set_chargeback()).
	 *
	 * @param int $order_id Order id.
	 * @return bool Whether a row was removed.
	 */
	public static function withdraw_staff_prevented( $order_id ) {
		global $wpdb;
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return false;
		}
		return (bool) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::prevented_table() . " WHERE dedupe_key = %s AND stopped_by = 'staff'", 'o:' . $order_id ) ); // phpcs:ignore
	}

	/**
	 * Raise `order.chargeback` for a flip of an order's charged-back state,
	 * from whichever path flipped it: the manual mark (wp-admin or API), the
	 * Charged back order status, or a refund on a store that counts refunds.
	 * The payload mirrors `order.scored` so a subscriber can join the two on
	 * `order_id` without a second lookup.
	 *
	 * @param WC_Order $order        Order.
	 * @param bool     $charged_back Whether the order now counts as charged back.
	 * @param string   $source       'manual' | 'api' | 'status' | 'refund'.
	 */
	public static function emit_chargeback( $order, $charged_back, $source = 'manual' ) {
		if ( ! class_exists( 'FS_Webhooks' ) ) {
			return;
		}
		FS_Webhooks::emit(
			'order.chargeback',
			array(
				'order_id'     => (int) $order->get_id(),
				'user_id'      => (int) $order->get_customer_id(),
				'email_norm'   => FS_Normalizer::email( (string) $order->get_billing_email() ),
				'name'         => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'total'        => (float) $order->get_total(),
				'currency'     => (string) $order->get_currency(),
				'gateway'      => (string) $order->get_payment_method(),
				'status'       => (string) $order->get_status(),
				'charged_back' => (bool) $charged_back,
				'recovered'    => round( max( 0.0, (float) $order->get_meta( '_fs_cb_recovered' ) ), 2 ),
				'source'       => in_array( $source, array( 'manual', 'api', 'status', 'refund' ), true ) ? $source : 'manual',
			)
		);
	}

	/**
	 * Record the money won back on a charged-back order, or clear it (v1.30:
	 * the one path the order meta box's "Record recovery" and
	 * `POST/DELETE /orders/{id}/recovery` share). Capped at the order total,
	 * because a recovery cannot exceed what was lost; the Financial Protection
	 * report nets it against the chargeback.
	 *
	 * @param WC_Order   $order  Order.
	 * @param float|null $amount Amount recovered, or null to clear the record.
	 * @param string     $source 'manual' (wp-admin) | 'api'.
	 * @return float The amount now on record (0 when cleared).
	 */
	public static function set_recovery( $order, $amount, $source = 'manual' ) {
		$settings = FS_Core::instance()->settings();
		if ( null === $amount ) {
			$order->delete_meta_data( '_fs_cb_recovered' );
			$order->add_order_note( 'api' === $source ? __( 'Fraud Shield: chargeback recovery cleared over the API.', 'fraud-shield' ) : __( 'Fraud Shield: chargeback recovery cleared.', 'fraud-shield' ) );
			$recorded = 0.0;
		} else {
			$recorded = round( min( max( 0.0, (float) $amount ), (float) $order->get_total() ), 2 );
			$order->update_meta_data( '_fs_cb_recovered', $recorded );
			$money = function_exists( 'wc_price' )
				? html_entity_decode( wp_strip_all_tags( wc_price( $recorded, array( 'currency' => $order->get_currency() ) ) ) )
				: number_format( $recorded, 2 ) . ' ' . $order->get_currency();
			$order->add_order_note(
				'api' === $source
					/* translators: %s: formatted amount */
					? sprintf( __( 'Fraud Shield: chargeback recovery recorded over the API — %s won back.', 'fraud-shield' ), $money )
					/* translators: %s: formatted amount */
					: sprintf( __( 'Fraud Shield: chargeback recovery recorded — %s won back.', 'fraud-shield' ), $money )
			);
		}
		$order->save();
		self::upsert_index( self::extract_signals( $order ), self::is_chargeback( $order, $settings ) );

		if ( class_exists( 'FS_Webhooks' ) ) {
			FS_Webhooks::emit(
				'order.recovery',
				array(
					'order_id'   => (int) $order->get_id(),
					'user_id'    => (int) $order->get_customer_id(),
					'email_norm' => FS_Normalizer::email( (string) $order->get_billing_email() ),
					'total'      => (float) $order->get_total(),
					'currency'   => (string) $order->get_currency(),
					'recovered'  => $recorded,
					'cleared'    => null === $amount,
					'source'     => 'api' === $source ? 'api' : 'manual',
				)
			);
		}
		return $recorded;
	}

	/**
	 * Insert or update one order in the index (raw fields only; score set separately).
	 *
	 * @param array $signals      Output of extract_signals().
	 * @param bool  $is_chargeback Confirmed-bad flag.
	 */
	public static function upsert_index( $signals, $is_chargeback ) {
		global $wpdb;
		$table = self::index_table();

		$row = array(
			'order_id'         => (int) $signals['order_id'],
			'order_date'       => $signals['order_date'],
			'user_id'          => (int) $signals['user_id'],
			'customer_name'    => $signals['customer_name'],
			'email_norm'       => $signals['email_norm'],
			'phone_norm'       => $signals['phone_norm'],
			'ship_hash'        => $signals['ship_hash'],
			'ship_loose_hash'  => $signals['ship_loose_hash'],
			'ship_text'        => $signals['ship_text'],
			// NULL (not '') when a caller omits geo — e.g. a CSV import without
			// city/state/postcode columns — so those rows remain eligible for the
			// geo backfill (which reads the real order, if it still exists).
			'ship_city'        => isset( $signals['ship_city'] ) ? mb_substr( (string) $signals['ship_city'], 0, 100 ) : null,
			'ship_state'       => isset( $signals['ship_state'] ) ? mb_substr( (string) $signals['ship_state'], 0, 32 ) : null,
			'ship_postcode'    => isset( $signals['ship_postcode'] ) ? mb_substr( (string) $signals['ship_postcode'], 0, 16 ) : null,
			'ship_street'      => isset( $signals['ship_street'] ) ? mb_substr( (string) $signals['ship_street'], 0, 100 ) : null,
			'ship_door_hash'   => isset( $signals['ship_door_hash'] ) ? mb_substr( (string) $signals['ship_door_hash'], 0, 64 ) : null,
			// 0 unless the caller derived the geo with the current parser, so
			// a geo-less row is exactly what the backfill cursor selects.
			'geo_scheme'       => isset( $signals['geo_scheme'] ) ? (int) $signals['geo_scheme'] : 0,
			'billing_country'  => $signals['billing_country'],
			'shipping_country' => $signals['shipping_country'],
			'ip'               => $signals['ip'],
			'order_total'      => $signals['order_total'],
			'status'           => $signals['status'],
			'payment_method'   => isset( $signals['payment_method'] ) ? mb_substr( (string) $signals['payment_method'], 0, 100 ) : '',
			'ship_method'      => isset( $signals['ship_method'] ) ? mb_substr( (string) $signals['ship_method'], 0, 191 ) : '',
			'coupons'          => isset( $signals['coupons'] ) ? mb_substr( (string) $signals['coupons'], 0, 255 ) : '',
			'user_agent'       => isset( $signals['user_agent'] ) ? mb_substr( (string) $signals['user_agent'], 0, 255 ) : '',
			'bill_hash'        => isset( $signals['bill_hash'] ) ? mb_substr( (string) $signals['bill_hash'], 0, 64 ) : '',
			'is_chargeback'    => $is_chargeback ? 1 : 0,
			'recovered'        => isset( $signals['recovered'] ) ? round( max( 0.0, (float) $signals['recovered'] ), 2 ) : 0,
			'indexed_at'       => current_time( 'mysql' ),
		);

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d", (int) $signals['order_id'] ) ); // phpcs:ignore

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'order_id' => (int) $signals['order_id'] ) ); // phpcs:ignore
		} else {
			$wpdb->insert( $table, $row ); // phpcs:ignore
		}
	}

	/**
	 * Persist a computed score onto an index row.
	 *
	 * @param int    $order_id Order ID.
	 * @param int    $score    Risk score.
	 * @param string $level    Risk level key.
	 * @param array  $reasons  Reason strings.
	 */
	public static function update_score( $order_id, $score, $level, $reasons ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore
			self::index_table(),
			array(
				'risk_score'   => (int) $score,
				'risk_level'   => $level,
				'risk_reasons' => wp_json_encode( array_values( $reasons ) ),
			),
			array( 'order_id' => (int) $order_id )
		);
	}

	/**
	 * Order statuses that mean the payment succeeded (money actually moved).
	 *
	 * @return array Status keys without the wc- prefix.
	 */
	public static function paid_statuses() {
		$statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		return array_values( array_unique( (array) $statuses ) );
	}

	/**
	 * Escaped SQL IN-list of paid statuses, e.g. "'processing','completed'".
	 *
	 * @return string
	 */
	public static function paid_in_sql() {
		return "'" . implode( "','", array_map( 'esc_sql', self::paid_statuses() ) ) . "'";
	}

	/**
	 * SQL fragment: rows that "count" for customer reports — successful payments
	 * plus confirmed chargebacks (a chargeback WAS a completed payment; dropping
	 * them would hide exactly the fraudsters we're hunting).
	 *
	 * @param string $alias Table alias (e.g. 'i.') or ''.
	 * @return string
	 */
	public static function counted_orders_sql( $alias = '' ) {
		return '(' . $alias . 'status IN (' . self::paid_in_sql() . ') OR ' . $alias . 'is_chargeback = 1)';
	}

	/**
	 * Total rows in the index.
	 *
	 * @return int
	 */
	public static function index_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::index_table() ); // phpcs:ignore
	}

	/**
	 * Domain portion of a normalized email.
	 *
	 * @param string $email Normalized email.
	 * @return string
	 */
	public static function domain_from_email( $email ) {
		$at = strpos( (string) $email, '@' );
		return ( false === $at ) ? '' : substr( $email, $at + 1 );
	}

	/**
	 * Score one batch of already-indexed rows against the complete history.
	 * Shared by the database scan and the CSV import.
	 *
	 * @param int        $offset   Row offset (id ordering).
	 * @param int        $batch    Batch size.
	 * @param array|null $settings Settings (fetched if null).
	 * @return int Rows processed.
	 */
	public static function score_index_batch( $offset, $batch, $settings = null ) {
		global $wpdb;
		if ( null === $settings ) {
			$settings = FS_Core::instance()->settings();
		}
		$table = self::index_table();

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d", (int) $batch, (int) $offset ), ARRAY_A ); // phpcs:ignore
		$rows = (array) $rows;

		foreach ( $rows as $row ) {
			$signals = array(
				'order_id'         => (int) $row['order_id'],
				'order_date'       => $row['order_date'],
				'user_id'          => (int) $row['user_id'],
				'customer_name'    => $row['customer_name'],
				'email_norm'       => $row['email_norm'],
				'email_domain'     => self::domain_from_email( $row['email_norm'] ),
				'phone_norm'       => $row['phone_norm'],
				'ship_hash'        => $row['ship_hash'],
				'ship_loose_hash'  => $row['ship_loose_hash'],
				'ship_city'        => isset( $row['ship_city'] ) ? $row['ship_city'] : '',
				'ship_state'       => isset( $row['ship_state'] ) ? $row['ship_state'] : '',
				'ship_postcode'    => isset( $row['ship_postcode'] ) ? $row['ship_postcode'] : '',
				'ship_method'      => isset( $row['ship_method'] ) ? $row['ship_method'] : '',
				'ip'               => $row['ip'],
				'billing_country'  => $row['billing_country'],
				'shipping_country' => $row['shipping_country'],
			);

			$profile = self::build_profile( $signals, (int) $row['order_id'] );
			$scored  = FS_Rules::score_profile( $profile, $settings );
			self::update_score( (int) $row['order_id'], $scored['score'], $scored['level'], $scored['reasons'] );
		}

		return count( $rows );
	}

	/**
	 * Of the given order ids, which are already in the index. Lets a scan skip
	 * re-loading orders it has already processed.
	 *
	 * @param array $ids Order ids.
	 * @return array Subset that already exists (ints).
	 */
	public static function existing_order_ids( $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		// Ids are integers, safe to inline.
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_col( "SELECT order_id FROM " . self::index_table() . " WHERE order_id IN ({$in})" ); // phpcs:ignore
		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Number of indexed rows that have not been scored yet.
	 *
	 * @return int
	 */
	public static function unscored_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::index_table() . " WHERE risk_level IS NULL OR risk_level = ''" ); // phpcs:ignore
	}

	/**
	 * Score the next batch of not-yet-scored rows. Because scored rows drop out
	 * of the filter, this always reads the next unscored batch (no offset needed)
	 * — used for fast incremental scans.
	 *
	 * @param int        $batch    Batch size.
	 * @param array|null $settings Settings (fetched if null).
	 * @return int Rows processed.
	 */
	public static function score_unscored_batch( $batch, $settings = null ) {
		global $wpdb;
		if ( null === $settings ) {
			$settings = FS_Core::instance()->settings();
		}
		$table = self::index_table();

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE risk_level IS NULL OR risk_level = '' ORDER BY id ASC LIMIT %d", (int) $batch ), ARRAY_A ); // phpcs:ignore
		$rows = (array) $rows;

		foreach ( $rows as $row ) {
			$signals = array(
				'order_id'         => (int) $row['order_id'],
				'order_date'       => $row['order_date'],
				'user_id'          => (int) $row['user_id'],
				'customer_name'    => $row['customer_name'],
				'email_norm'       => $row['email_norm'],
				'email_domain'     => self::domain_from_email( $row['email_norm'] ),
				'phone_norm'       => $row['phone_norm'],
				'ship_hash'        => $row['ship_hash'],
				'ship_loose_hash'  => $row['ship_loose_hash'],
				'ship_city'        => isset( $row['ship_city'] ) ? $row['ship_city'] : '',
				'ship_state'       => isset( $row['ship_state'] ) ? $row['ship_state'] : '',
				'ship_postcode'    => isset( $row['ship_postcode'] ) ? $row['ship_postcode'] : '',
				'ship_method'      => isset( $row['ship_method'] ) ? $row['ship_method'] : '',
				'ip'               => $row['ip'],
				'billing_country'  => $row['billing_country'],
				'shipping_country' => $row['shipping_country'],
			);
			$profile = self::build_profile( $signals, (int) $row['order_id'] );
			$scored  = FS_Rules::score_profile( $profile, $settings );
			self::update_score( (int) $row['order_id'], $scored['score'], $scored['level'], $scored['reasons'] );
		}

		return count( $rows );
	}

	/**
	 * Restore one index row verbatim from a Fraud Shield CSV export (keeps the
	 * exact hashes, chargeback flag and scores — no re-normalization).
	 *
	 * @param array $row Associative row keyed by export column names.
	 */
	public static function restore_index_row( $row ) {
		global $wpdb;
		// Undo the CSV formula-injection guard our exporter applies (see
		// csv_escape_cell), so export → restore round-trips are faithful.
		$row      = array_map( array( __CLASS__, 'csv_unescape_cell' ), (array) $row );
		$table    = self::index_table();
		$order_id = (int) ( isset( $row['order_id'] ) ? preg_replace( '/[^0-9]/', '', (string) $row['order_id'] ) : 0 );
		if ( $order_id <= 0 ) {
			return;
		}

		$order_date = isset( $row['order_date'] ) && '' !== trim( (string) $row['order_date'] ) ? trim( (string) $row['order_date'] ) : null;

		$data = array(
			'order_id'         => $order_id,
			'order_date'       => $order_date,
			'user_id'          => (int) ( $row['user_id'] ?? 0 ),
			'customer_name'    => mb_substr( (string) ( $row['customer_name'] ?? '' ), 0, 191 ),
			'email_norm'       => mb_substr( (string) ( $row['email_norm'] ?? '' ), 0, 191 ),
			'phone_norm'       => mb_substr( (string) ( $row['phone_norm'] ?? '' ), 0, 32 ),
			'ship_hash'        => mb_substr( (string) ( $row['ship_hash'] ?? '' ), 0, 64 ),
			'ship_loose_hash'  => mb_substr( (string) ( $row['ship_loose_hash'] ?? '' ), 0, 64 ),
			'ship_text'        => mb_substr( (string) ( $row['ship_text'] ?? '' ), 0, 255 ),
			'ship_city'        => mb_substr( (string) ( $row['ship_city'] ?? '' ), 0, 100 ),
			'ship_state'       => mb_substr( (string) ( $row['ship_state'] ?? '' ), 0, 32 ),
			'ship_postcode'    => mb_substr( (string) ( $row['ship_postcode'] ?? '' ), 0, 16 ),
			// A backup taken before v1.25 has no street column. NULL (not '')
			// keeps those rows eligible for the geo backfill, which recomputes
			// the street from the real order if it still exists.
			'ship_street'      => array_key_exists( 'ship_street', $row ) ? mb_substr( (string) $row['ship_street'], 0, 100 ) : null,
			'ship_door_hash'   => array_key_exists( 'ship_door_hash', $row ) ? mb_substr( (string) $row['ship_door_hash'], 0, 64 ) : null,
			// A backup taken under an older parser restores as "re-derive me".
			'geo_scheme'       => isset( $row['geo_scheme'] ) ? min( (int) $row['geo_scheme'], self::GEO_SCHEME ) : 0,
			'billing_country'  => mb_substr( (string) ( $row['billing_country'] ?? '' ), 0, 8 ),
			'shipping_country' => mb_substr( (string) ( $row['shipping_country'] ?? '' ), 0, 8 ),
			'ip'               => mb_substr( (string) ( $row['ip'] ?? '' ), 0, 64 ),
			'order_total'      => (float) ( $row['order_total'] ?? 0 ),
			'status'           => mb_substr( (string) ( $row['status'] ?? '' ), 0, 32 ),
			'payment_method'   => mb_substr( (string) ( $row['payment_method'] ?? '' ), 0, 100 ),
			'ship_method'      => mb_substr( (string) ( $row['ship_method'] ?? '' ), 0, 191 ),
			'coupons'          => mb_substr( (string) ( $row['coupons'] ?? '' ), 0, 255 ),
			'user_agent'       => mb_substr( (string) ( $row['user_agent'] ?? '' ), 0, 255 ),
			'bill_hash'        => mb_substr( (string) ( $row['bill_hash'] ?? '' ), 0, 64 ),
			'is_chargeback'    => ( ! empty( $row['is_chargeback'] ) && '0' !== (string) $row['is_chargeback'] ) ? 1 : 0,
			'recovered'        => round( max( 0.0, (float) ( $row['recovered'] ?? 0 ) ), 2 ),
			'risk_score'       => (int) ( $row['risk_score'] ?? 0 ),
			'risk_level'       => mb_substr( (string) ( $row['risk_level'] ?? '' ), 0, 16 ),
			'risk_reasons'     => (string) ( $row['risk_reasons'] ?? '' ),
			'indexed_at'       => current_time( 'mysql' ),
		);

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d", $order_id ) ); // phpcs:ignore
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'order_id' => $order_id ) ); // phpcs:ignore
		} else {
			$wpdb->insert( $table, $data ); // phpcs:ignore
		}
	}

	/**
	 * Column order used by the CSV export / native restore.
	 *
	 * @return array
	 */
	public static function export_columns() {
		return array(
			'order_id',
			'order_date',
			'user_id',
			'customer_name',
			'email_norm',
			'phone_norm',
			'ship_hash',
			'ship_loose_hash',
			'ship_text',
			'ship_city',
			'ship_state',
			'ship_postcode',
			'ship_street',
			'ship_door_hash',
			'billing_country',
			'shipping_country',
			'ip',
			'order_total',
			'status',
			'payment_method',
			'ship_method',
			'coupons',
			'user_agent',
			'bill_hash',
			'is_chargeback',
			'recovered',
			'risk_score',
			'risk_level',
			'risk_reasons',
			'geo_scheme',
		);
	}

	/**
	 * Neutralize spreadsheet formula injection (CWE-1236) in one CSV cell.
	 *
	 * Exports contain attacker-controlled strings (billing names, address text,
	 * user agents, coupon codes). A value like "=HYPERLINK(...)" or
	 * "+cmd|' /C ...'!A0" executes when the merchant opens the export in
	 * Excel/Sheets. Per OWASP guidance, cells whose first character is
	 * = + - @ TAB or CR are prefixed with a single quote, which spreadsheets
	 * render as a literal. Numeric cells (e.g. negative totals) are exempt so
	 * legitimate numbers survive untouched.
	 *
	 * @param mixed $v Cell value.
	 * @return string
	 */
	public static function csv_escape_cell( $v ) {
		$v = ( null === $v || is_scalar( $v ) ) ? (string) $v : '';
		if ( '' !== $v && ! is_numeric( $v ) && false !== strpos( "=+-@\t\r", $v[0] ) ) {
			return "'" . $v;
		}
		return $v;
	}

	/**
	 * Inverse of csv_escape_cell() for OUR OWN import paths, so an
	 * export → import round-trip is faithful: strips the guard quote only when
	 * it unambiguously matches the escape pattern ('=, '+, '-, '@, tab, CR).
	 * Any other leading quote is real data and is preserved.
	 *
	 * @param mixed $v Cell value.
	 * @return mixed
	 */
	public static function csv_unescape_cell( $v ) {
		if ( is_string( $v ) && strlen( $v ) >= 2 && "'" === $v[0] && false !== strpos( "=+-@\t\r", $v[1] ) ) {
			return substr( $v, 1 );
		}
		return $v;
	}

	/**
	 * Count a customer's orders within the trailing N days — by account id
	 * and/or normalized email, and (v1.31) optionally by the billing phone
	 * and the shipping door key, so a customer who rotates email addresses
	 * but keeps their phone, or ships every order to one door, is still one
	 * customer to the velocity lock. Optionally excludes one order.
	 *
	 * @param int    $user_id          Account id (0 for guest).
	 * @param string $email_norm       Normalized email.
	 * @param int    $days             Trailing window in days.
	 * @param int    $exclude_order_id Order id to exclude (e.g. the current one).
	 * @param string $phone_norm       Normalized phone to count as the same customer ('' = ignore).
	 * @param string $door_hash        Door key to count as the same customer ('' = ignore).
	 * @return int
	 */
	public static function recent_order_count( $user_id, $email_norm, $days = 30, $exclude_order_id = 0, $phone_norm = '', $door_hash = '' ) {
		global $wpdb;
		$table = self::index_table();
		$uid   = (int) $user_id;
		$email = (string) $email_norm;
		$phone = (string) $phone_norm;
		$door  = (string) $door_hash;
		$ex    = (int) $exclude_order_id;

		$ors  = array();
		$args = array();
		if ( $uid > 0 ) {
			$ors[]  = 'user_id=%d';
			$args[] = $uid;
		}
		if ( '' !== $email ) {
			$ors[]  = 'email_norm=%s';
			$args[] = $email;
		}
		if ( '' !== $phone ) {
			$ors[]  = 'phone_norm=%s';
			$args[] = $phone;
		}
		if ( '' !== $door ) {
			$ors[]  = 'ship_door_hash=%s';
			$args[] = $door;
		}
		if ( empty( $ors ) ) {
			return 0;
		}

		// $days may be fractional (an hours-long window is hours / 24).
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - (int) round( max( 0.0, (float) $days ) * DAY_IN_SECONDS ) ); // phpcs:ignore
		$where  = '(' . implode( ' OR ', $ors ) . ')';
		$args[] = $cutoff;
		$args[] = $ex;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where} AND order_date >= %s AND order_id <> %d", $args ) ); // phpcs:ignore
	}

	/**
	 * Earliest indexed order date for a normalized email (proxy for "first seen"
	 * when there is no registered account).
	 *
	 * @param string $email_norm Normalized email.
	 * @return string|null MySQL datetime or null.
	 */
	public static function email_first_seen( $email_norm ) {
		global $wpdb;
		if ( '' === (string) $email_norm ) {
			return null;
		}
		return $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(order_date) FROM ' . self::index_table() . ' WHERE email_norm = %s', $email_norm ) ); // phpcs:ignore
	}

	/**
	 * One-query trading history for a customer: how long they have been ordering
	 * and whether that history is clean. Backs the "established clean customer"
	 * auto-verification, so it is deliberately cheap — a single aggregate over
	 * indexed columns.
	 *
	 * Refunds are counted from the indexed order status, which means a FULLY
	 * refunded order counts and a partial refund does not (the order status does
	 * not change on a partial refund). Chargebacks use the same flag the rest of
	 * the plugin does, so a manually-marked chargeback counts too.
	 *
	 * @param int    $user_id    Account id (0 = none).
	 * @param string $email_norm Normalized email.
	 * @return array { orders:int, paid_orders:int, chargebacks:int, refunds:int, first_order:?string, last_order:?string }
	 */
	public static function customer_history( $user_id, $email_norm ) {
		global $wpdb;

		$empty = array(
			'orders'      => 0,
			'paid_orders' => 0,
			'chargebacks' => 0,
			'refunds'     => 0,
			'first_order' => null,
			'last_order'  => null,
		);

		$uid   = (int) $user_id;
		$email = (string) $email_norm;
		if ( $uid <= 0 && '' === $email ) {
			return $empty;
		}

		if ( $uid > 0 && '' !== $email ) {
			$where = '(user_id = %d OR email_norm = %s)';
			$args  = array( $uid, $email );
		} elseif ( $uid > 0 ) {
			$where = 'user_id = %d';
			$args  = array( $uid );
		} else {
			$where = 'email_norm = %s';
			$args  = array( $email );
		}

		$paid = self::paid_in_sql();
		$row  = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare(
				"SELECT
					COUNT(*) orders,
					SUM(CASE WHEN status IN ({$paid}) THEN 1 ELSE 0 END) paid_orders,
					COALESCE(SUM(is_chargeback),0) chargebacks,
					SUM(CASE WHEN status IN ('refunded','charged-back') THEN 1 ELSE 0 END) refunds,
					MIN(order_date) first_order,
					MAX(order_date) last_order
				FROM " . self::index_table() . "
				WHERE {$where}",
				$args
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'orders'      => (int) $row['orders'],
			'paid_orders' => (int) $row['paid_orders'],
			'chargebacks' => (int) $row['chargebacks'],
			'refunds'     => (int) $row['refunds'],
			'first_order' => $row['first_order'] ? $row['first_order'] : null,
			'last_order'  => $row['last_order'] ? $row['last_order'] : null,
		);
	}

	/**
	 * Build the aggregate profile for a set of signals, excluding the order itself.
	 *
	 * Returns counts/booleans the scoring engine consumes. All lookups hit the
	 * indexed columns, so this stays fast even on large catalogs.
	 *
	 * @param array $s               Normalized signals.
	 * @param int   $exclude_order_id Order to exclude from history (0 = none).
	 * @return array
	 */
	public static function build_profile( $s, $exclude_order_id = 0 ) {
		global $wpdb;
		$table = self::index_table();
		$ex    = (int) $exclude_order_id;

		$email = (string) ( $s['email_norm'] ?? '' );
		$phone = (string) ( $s['phone_norm'] ?? '' );
		$ship  = (string) ( $s['ship_hash'] ?? '' );
		$loose = (string) ( $s['ship_loose_hash'] ?? '' );
		$ip    = (string) ( $s['ip'] ?? '' );
		$uid   = (int) ( $s['user_id'] ?? 0 );

		$profile = array(
			'chargeback_email'         => false,
			'chargeback_phone'         => false,
			'chargeback_ship'          => false,
			'chargeback_ship_loose'    => false,
			'chargeback_ip'            => false,
			'emails_at_address'        => 1,
			'names_at_address'         => 1,
			'addresses_for_email'      => 1,
			'accounts_at_ip'           => 0,
			'orders_7d'                => 0,
			'orders_30d'               => 0,
			'orders_burst'             => 0,
			'account_age_days'         => null,
			'disposable_email'         => false,
			'country_mismatch'         => false,
			'bill_ship_mismatch'       => 0,
			'pay_orders'               => 0,
			'pay_card_orders'          => 0,
			'pay_card_only'            => false,
			'fast_shipping'            => false,
		);

		// --- Confirmed-chargeback links on each identifier ---
		if ( '' !== $ship ) {
			$profile['chargeback_ship'] = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE ship_hash=%s AND is_chargeback=1 AND order_id<>%d LIMIT 1", $ship, $ex ) ); // phpcs:ignore
		}
		if ( '' !== $loose ) {
			$profile['chargeback_ship_loose'] = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE ship_loose_hash=%s AND is_chargeback=1 AND order_id<>%d LIMIT 1", $loose, $ex ) ); // phpcs:ignore
		}
		if ( '' !== $email ) {
			$profile['chargeback_email'] = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE email_norm=%s AND is_chargeback=1 AND order_id<>%d LIMIT 1", $email, $ex ) ); // phpcs:ignore
		}
		if ( '' !== $phone ) {
			$profile['chargeback_phone'] = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE phone_norm=%s AND is_chargeback=1 AND order_id<>%d LIMIT 1", $phone, $ex ) ); // phpcs:ignore
		}
		if ( '' !== $ip ) {
			$profile['chargeback_ip'] = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE ip=%s AND is_chargeback=1 AND order_id<>%d LIMIT 1", $ip, $ex ) ); // phpcs:ignore
		}

		// --- Clustering ---
		if ( '' !== $ship ) {
			$distinct_emails = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT email_norm) FROM {$table} WHERE ship_hash=%s AND email_norm<>'' AND email_norm<>%s AND order_id<>%d", $ship, $email, $ex ) ); // phpcs:ignore
			$profile['emails_at_address'] = $distinct_emails + ( '' !== $email ? 1 : 0 );

			$distinct_names = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT customer_name) FROM {$table} WHERE ship_hash=%s AND customer_name<>'' AND customer_name<>%s AND order_id<>%d", $ship, (string) ( $s['customer_name'] ?? '' ), $ex ) ); // phpcs:ignore
			$profile['names_at_address'] = $distinct_names + ( '' !== (string) ( $s['customer_name'] ?? '' ) ? 1 : 0 );
		}

		if ( '' !== $email ) {
			$distinct_addr = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ship_hash) FROM {$table} WHERE email_norm=%s AND ship_hash<>'' AND ship_hash<>%s AND order_id<>%d", $email, $ship, $ex ) ); // phpcs:ignore
			$profile['addresses_for_email'] = $distinct_addr + ( '' !== $ship ? 1 : 0 );
		}

		if ( '' !== $ip ) {
			$profile['accounts_at_ip'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT email_norm) FROM {$table} WHERE ip=%s AND email_norm<>'' AND order_id<>%d", $ip, $ex ) ); // phpcs:ignore
		}

		// --- Velocity + account age (both anchored to THIS order's date) ---
		// The window MUST be relative to the order itself, not "now". Otherwise a
		// historical re-scan measures each old order's velocity against today and
		// always sees zero, silently disabling the velocity signal on back-history.
		$now      = current_time( 'timestamp' );
		$order_ts = ( isset( $s['order_date'] ) && $s['order_date'] ) ? strtotime( $s['order_date'] ) : $now;
		if ( ! $order_ts ) {
			$order_ts = $now;
		}
		$anchor = gmdate( 'Y-m-d H:i:s', $order_ts );
		$d7     = gmdate( 'Y-m-d H:i:s', $order_ts - 7 * DAY_IN_SECONDS );
		$d30    = gmdate( 'Y-m-d H:i:s', $order_ts - 30 * DAY_IN_SECONDS );
		$dburst = gmdate( 'Y-m-d H:i:s', $order_ts - 24 * HOUR_IN_SECONDS ); // "back-to-back" burst window.

		$id_where = '';
		$id_args  = array();
		if ( $uid > 0 ) {
			$id_where  = '(user_id=%d OR email_norm=%s)';
			$id_args[] = $uid;
			$id_args[] = $email;
		} elseif ( '' !== $email ) {
			$id_where  = 'email_norm=%s';
			$id_args[] = $email;
		}

		if ( '' !== $id_where ) {
			// Single round-trip for both windows: orders in [anchor-7d, anchor] and
			// [anchor-30d, anchor], excluding the order being scored.
			$row = $wpdb->get_row( // phpcs:ignore
				$wpdb->prepare(
					"SELECT
						SUM(CASE WHEN order_date >= %s THEN 1 ELSE 0 END) AS o7,
						SUM(CASE WHEN order_date >= %s THEN 1 ELSE 0 END) AS o30,
						SUM(CASE WHEN order_date >= %s THEN 1 ELSE 0 END) AS oburst
					FROM {$table}
					WHERE {$id_where} AND order_date <= %s AND order_id <> %d",
					array_merge( array( $d7, $d30, $dburst ), $id_args, array( $anchor, $ex ) )
				),
				ARRAY_A
			);
			if ( $row ) {
				$profile['orders_7d']    = (int) $row['o7'];
				$profile['orders_30d']   = (int) $row['o30'];
				$profile['orders_burst'] = (int) $row['oburst'];
			}
		}

		// --- Account age (anchored to the order date, same as velocity) ---
		if ( $uid > 0 ) {
			$user = get_userdata( $uid );
			if ( $user && ! empty( $user->user_registered ) ) {
				$reg                       = strtotime( $user->user_registered );
				$profile['account_age_days'] = max( 0, (int) floor( ( $order_ts - $reg ) / DAY_IN_SECONDS ) );
			}
		} elseif ( '' !== $email ) {
			$first_seen = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(order_date) FROM {$table} WHERE email_norm=%s AND order_id<>%d", $email, $ex ) ); // phpcs:ignore
			if ( $first_seen ) {
				$profile['account_age_days'] = max( 0, (int) floor( ( $order_ts - strtotime( $first_seen ) ) / DAY_IN_SECONDS ) );
			}
		}

		// --- Heuristics ---
		$profile['disposable_email'] = FS_Rules::is_disposable_domain( $s['email_domain'] ?? '' );

		$bc = strtoupper( (string) ( $s['billing_country'] ?? '' ) );
		$sc = strtoupper( (string) ( $s['shipping_country'] ?? '' ) );
		$profile['country_mismatch'] = ( '' !== $bc && '' !== $sc && $bc !== $sc );

		// --- Billing vs shipping divergence (count of this identity's orders where
		// billing and shipping addresses both exist and differ) ---
		if ( '' !== $email ) {
			$profile['bill_ship_mismatch'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email_norm=%s AND bill_hash<>'' AND ship_hash<>'' AND bill_hash<>ship_hash AND order_id<>%d", $email, $ex ) ); // phpcs:ignore
		}

		// --- Payment-processor exclusivity: of this identity's counted orders that
		// carry a payment method, what share is on gateways labeled "card"? ---
		if ( '' !== $email ) {
			$pay_rows = $wpdb->get_results( $wpdb->prepare( "SELECT payment_method, COUNT(*) c FROM {$table} WHERE email_norm=%s AND payment_method<>'' AND " . self::counted_orders_sql() . " AND order_id<>%d GROUP BY payment_method", $email, $ex ), ARRAY_A ); // phpcs:ignore
			$pay_total = 0;
			$pay_card  = 0;
			foreach ( (array) $pay_rows as $pr ) {
				$c          = (int) $pr['c'];
				$pay_total += $c;
				if ( FS_Core::is_card_gateway( $pr['payment_method'] ) ) {
					$pay_card += $c;
				}
			}
			$profile['pay_orders']      = $pay_total;
			$profile['pay_card_orders'] = $pay_card;
			$profile['pay_card_only']   = ( $pay_total > 0 && $pay_card === $pay_total );
		}

		// --- Expedited shipping (per-order): did THIS order use a shipping method
		// the merchant labeled expedited/overnight? ---
		$profile['fast_shipping'] = FS_Core::is_expedited_shipping( $s['ship_method'] ?? '' );

		// --- ID verification context (v1.18): what Didit told us about this
		// customer's most recent approved check, compared against this order.
		// A completed check is mostly a GOOD signal (it already exempts them
		// from the automatic controls); what scores here are the contradictions
		// inside it: the document naming someone else, a document from a state
		// the customer never ships to, a verification performed from another
		// country, or one performed behind a VPN.
		$idv_row = self::idv_enrichment_for( $uid, $email );
		if ( ! empty( $idv_row ) ) {
			$profile['idv_name_mismatch'] = ( 'no' === (string) ( $idv_row['name_match'] ?? '' ) );
			$profile['idv_vpn']           = ! empty( $idv_row['verify_vpn'] );

			$doc_state = (string) ( $idv_row['doc_state'] ?? '' );
			$doc_ctry  = strtoupper( (string) ( $idv_row['doc_country'] ?? '' ) );
			$ship_st   = (string) ( $s['ship_state'] ?? '' );
			// Same-country comparison only: a passport has no useful state, and
			// comparing a German document against a Texas shipping address says
			// nothing a country mismatch doesn't already say better.
			if ( '' !== $doc_state && '' !== $ship_st && ( '' === $doc_ctry || '' === $sc || $doc_ctry === $sc ) ) {
				$profile['idv_doc_state_mismatch'] = ! self::states_match( $doc_state, $ship_st );
			}

			$verify_ctry = strtoupper( (string) ( $idv_row['verify_ip_country'] ?? '' ) );
			$order_ctry  = '' !== $sc ? $sc : $bc;
			if ( '' !== $verify_ctry && '' !== $order_ctry ) {
				$profile['idv_geo_mismatch'] = ( $verify_ctry !== $order_ctry );
			}
		}

		return $profile;
	}

	/**
	 * The enrichment columns of a customer's most recent approved ID check, or
	 * an empty array. One query, used by scoring and the profile panels.
	 *
	 * @param int    $user_id    Account id (0 = none).
	 * @param string $email_norm Normalized email.
	 * @return array
	 */
	public static function idv_enrichment_for( $user_id, $email_norm ) {
		global $wpdb;
		$t       = self::idv_sessions_table();
		$user_id = (int) $user_id;
		$email   = FS_Normalizer::email( (string) $email_norm );

		if ( $user_id > 0 && '' !== $email ) {
			$sql    = "SELECT name_match, doc_country, doc_state, verify_ip_country, verify_ip_state, verify_vpn, warnings, full_name, doc_type, updated_at FROM {$t} WHERE result = 'approved' AND (user_id = %d OR email_norm = %s) ORDER BY id DESC LIMIT 1";
			$params = array( $user_id, $email );
		} elseif ( $user_id > 0 ) {
			$sql    = "SELECT name_match, doc_country, doc_state, verify_ip_country, verify_ip_state, verify_vpn, warnings, full_name, doc_type, updated_at FROM {$t} WHERE result = 'approved' AND user_id = %d ORDER BY id DESC LIMIT 1";
			$params = array( $user_id );
		} elseif ( '' !== $email ) {
			$sql    = "SELECT name_match, doc_country, doc_state, verify_ip_country, verify_ip_state, verify_vpn, warnings, full_name, doc_type, updated_at FROM {$t} WHERE result = 'approved' AND email_norm = %s ORDER BY id DESC LIMIT 1";
			$params = array( $email );
		} else {
			return array();
		}

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : array();
	}

	/**
	 * Do two state values name the same place? Tolerates the code-versus-name
	 * split ("CA" on the order, "California" on the document) for US states,
	 * since driver's licenses are the document this comparison exists for.
	 *
	 * @param string $a One state (code or name).
	 * @param string $b The other state (code or name).
	 * @return bool
	 */
	public static function states_match( $a, $b ) {
		$norm = function ( $v ) {
			$v  = strtoupper( trim( (string) $v ) );
			$us = array(
				'ALABAMA'        => 'AL', 'ALASKA' => 'AK', 'ARIZONA' => 'AZ', 'ARKANSAS' => 'AR',
				'CALIFORNIA'     => 'CA', 'COLORADO' => 'CO', 'CONNECTICUT' => 'CT', 'DELAWARE' => 'DE',
				'FLORIDA'        => 'FL', 'GEORGIA' => 'GA', 'HAWAII' => 'HI', 'IDAHO' => 'ID',
				'ILLINOIS'       => 'IL', 'INDIANA' => 'IN', 'IOWA' => 'IA', 'KANSAS' => 'KS',
				'KENTUCKY'       => 'KY', 'LOUISIANA' => 'LA', 'MAINE' => 'ME', 'MARYLAND' => 'MD',
				'MASSACHUSETTS'  => 'MA', 'MICHIGAN' => 'MI', 'MINNESOTA' => 'MN', 'MISSISSIPPI' => 'MS',
				'MISSOURI'       => 'MO', 'MONTANA' => 'MT', 'NEBRASKA' => 'NE', 'NEVADA' => 'NV',
				'NEW HAMPSHIRE'  => 'NH', 'NEW JERSEY' => 'NJ', 'NEW MEXICO' => 'NM', 'NEW YORK' => 'NY',
				'NORTH CAROLINA' => 'NC', 'NORTH DAKOTA' => 'ND', 'OHIO' => 'OH', 'OKLAHOMA' => 'OK',
				'OREGON'         => 'OR', 'PENNSYLVANIA' => 'PA', 'RHODE ISLAND' => 'RI', 'SOUTH CAROLINA' => 'SC',
				'SOUTH DAKOTA'   => 'SD', 'TENNESSEE' => 'TN', 'TEXAS' => 'TX', 'UTAH' => 'UT',
				'VERMONT'        => 'VT', 'VIRGINIA' => 'VA', 'WASHINGTON' => 'WA', 'WEST VIRGINIA' => 'WV',
				'WISCONSIN'      => 'WI', 'WYOMING' => 'WY', 'DISTRICT OF COLUMBIA' => 'DC', 'PUERTO RICO' => 'PR',
			);
			return isset( $us[ $v ] ) ? $us[ $v ] : $v;
		};
		return $norm( $a ) === $norm( $b );
	}

	/**
	 * Every distinct customer email the order index knows. Used to resolve the
	 * hashed guest keys Didit sessions carry back into addresses.
	 *
	 * @param int $limit Safety cap.
	 * @return string[] Normalized emails.
	 */
	public static function all_customer_emails( $limit = 20000 ) {
		global $wpdb;
		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT DISTINCT email_norm FROM ' . self::index_table() . " WHERE email_norm <> '' LIMIT %d", (int) $limit )
		);
		return array_values( array_filter( array_map( 'strval', (array) $rows ) ) );
	}

	/**
	 * The billing name on a customer's most recent indexed order — the name
	 * they actually ship things under, which beats an empty profile for both
	 * guests and accounts.
	 *
	 * @param int    $uid        Account id (0 = guest).
	 * @param string $email_norm Normalized email.
	 * @return string '' when the index knows no order for them.
	 */
	public static function latest_customer_name( $uid, $email_norm ) {
		global $wpdb;
		$table = self::index_table();
		if ( (int) $uid > 0 ) {
			$name = $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare( "SELECT customer_name FROM {$table} WHERE user_id = %d AND customer_name <> '' ORDER BY order_date DESC LIMIT 1", (int) $uid ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			if ( '' !== trim( (string) $name ) ) {
				return trim( (string) $name );
			}
		}
		$email_norm = FS_Normalizer::email( (string) $email_norm );
		if ( '' !== $email_norm ) {
			$name = $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare( "SELECT customer_name FROM {$table} WHERE email_norm = %s AND customer_name <> '' ORDER BY order_date DESC LIMIT 1", $email_norm ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			if ( '' !== trim( (string) $name ) ) {
				return trim( (string) $name );
			}
		}
		return '';
	}

	/**
	 * Distinct shipping states on record for one identity, most used first.
	 *
	 * @param string $email_norm Normalized email.
	 * @return string[]
	 */
	public static function distinct_ship_states( $email_norm ) {
		global $wpdb;
		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT ship_state FROM ' . self::index_table() . " WHERE email_norm = %s AND ship_state <> '' GROUP BY ship_state ORDER BY COUNT(*) DESC LIMIT 10",
				FS_Normalizer::email( (string) $email_norm )
			)
		);
		return array_values( array_filter( array_map( 'strval', (array) $rows ) ) );
	}

	/**
	 * Distinct payment-method labels seen in the index, with order counts.
	 * Drives the payment-method classification UI.
	 *
	 * @return array Rows: method, c.
	 */
	public static function distinct_payment_methods() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT payment_method method, COUNT(*) c FROM ' . self::index_table() . " WHERE payment_method <> '' GROUP BY payment_method ORDER BY c DESC LIMIT 200", ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Distinct shipping-method labels seen in the index, with order counts.
	 * Drives the shipping-method classification UI.
	 *
	 * @return array Rows: method, c.
	 */
	public static function distinct_shipping_methods() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT ship_method method, COUNT(*) c FROM ' . self::index_table() . " WHERE ship_method <> '' GROUP BY ship_method ORDER BY c DESC LIMIT 200", ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Map a cluster type to its (whitelisted) index column.
	 *
	 * @param string $type 'ip' or 'address'.
	 * @return string Column name.
	 */
	public static function cluster_column( $type ) {
		return 'ip' === $type ? 'ip' : 'ship_hash';
	}

	/**
	 * Total orders that belong to a given address/IP cluster.
	 *
	 * @param string $type 'address' | 'ip'.
	 * @param string $key  ship_hash or IP value.
	 * @return int
	 */
	public static function cluster_orders_count( $type, $key ) {
		global $wpdb;
		$table = self::index_table();
		$col   = self::cluster_column( $type );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$col} = %s", $key ) ); // phpcs:ignore
	}

	/**
	 * One page of orders in a cluster (chargebacks first, then newest).
	 *
	 * @param string $type   'address' | 'ip'.
	 * @param string $key    ship_hash or IP value.
	 * @param int    $limit  Per page.
	 * @param int    $offset Offset.
	 * @return array
	 */
	public static function cluster_orders( $type, $key, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = self::index_table();
		$col   = self::cluster_column( $type );
		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$col} = %s ORDER BY order_date DESC, id DESC LIMIT %d OFFSET %d",
				$key,
				(int) $limit,
				(int) $offset
			),
			ARRAY_A
		);
	}

	/**
	 * Aggregate stats for a single cluster (header of the detail view).
	 *
	 * @param string $type 'address' | 'ip'.
	 * @param string $key  ship_hash or IP value.
	 * @return array
	 */
	public static function cluster_summary( $type, $key ) {
		global $wpdb;
		$table = self::index_table();

		$paid = self::paid_in_sql();

		if ( 'ip' === $type ) {
			$sql = "SELECT
				COUNT(*) orders,
				SUM(CASE WHEN status IN ({$paid}) THEN 1 ELSE 0 END) paid_orders,
				COUNT(DISTINCT email_norm) emails,
				COUNT(DISTINCT customer_name) names,
				COUNT(DISTINCT ship_hash) addresses,
				COUNT(DISTINCT shipping_country) countries,
				COALESCE(SUM(is_chargeback),0) chargebacks,
				COALESCE(SUM(CASE WHEN status IN ({$paid}) THEN order_total ELSE 0 END),0) total_value,
				MIN(order_date) first_order,
				MAX(order_date) last_order
				FROM {$table} WHERE ip = %s";
		} else {
			$sql = "SELECT
				COUNT(*) orders,
				SUM(CASE WHEN status IN ({$paid}) THEN 1 ELSE 0 END) paid_orders,
				COUNT(DISTINCT email_norm) emails,
				COUNT(DISTINCT customer_name) names,
				COUNT(DISTINCT ip) ips,
				COUNT(DISTINCT shipping_country) countries,
				COALESCE(SUM(is_chargeback),0) chargebacks,
				COALESCE(SUM(CASE WHEN status IN ({$paid}) THEN order_total ELSE 0 END),0) total_value,
				MIN(order_date) first_order,
				MAX(order_date) last_order,
				MAX(ship_text) ship_text,
				MAX(shipping_country) shipping_country,
				MAX(ship_state) ship_state,
				MAX(ship_city) ship_city,
				MAX(ship_street) ship_street
				FROM {$table} WHERE ship_hash = %s";
		}

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $key ), ARRAY_A ); // phpcs:ignore
		return is_array( $row ) ? $row : array();
	}

	/**
	 * Number of distinct clusters (addresses or IPs) in the index.
	 *
	 * @param string $type   'address' | 'ip'.
	 * @param string $search Free-text search.
	 * @return int
	 */
	public static function clusters_count( $type, $search = '' ) {
		global $wpdb;
		$t          = self::index_table();
		$col        = self::cluster_column( $type );
		$params     = array();
		$search_sql = self::profile_search_sql( $search, $params );

		$sql = "SELECT COUNT(DISTINCT {$col}) FROM {$t} WHERE {$col} <> ''{$search_sql}";
		return empty( $params )
			? (int) $wpdb->get_var( $sql ) // phpcs:ignore
			: (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/**
	 * One row per cluster (EVERY shipping address or IP, not just the top risky
	 * ones), with headline aggregates. Sortable and searchable.
	 *
	 * @param string $type 'address' | 'ip'.
	 * @param array  $args orderby, dir, search, limit, offset.
	 * @return array
	 */
	public static function clusters_list( $type, $args = array() ) {
		global $wpdb;
		$a = wp_parse_args(
			$args,
			array(
				'orderby'  => 'emails',
				'dir'      => 'desc',
				'orderby2' => '',
				'dir2'     => 'desc',
				'search'   => '',
				'limit'    => 50,
				'offset'   => 0,
			)
		);
		$t    = self::index_table();
		$col  = self::cluster_column( $type );
		$paid = self::paid_in_sql();

		$other = ( 'ip' === $type )
			? 'COUNT(DISTINCT ship_hash) other_dim'
			: 'COUNT(DISTINCT ip) other_dim';

		$sort_map = array(
			'key'         => $col,
			'address'     => 'ship_text',
			'emails'      => 'emails',
			'names'       => 'names',
			'orders'      => 'orders',
			'paid'        => 'paid_orders',
			'spend'       => 'spend',
			'chargebacks' => 'chargebacks',
			'other'       => 'other_dim',
			'risk'        => 'max_risk',
			'first'       => 'first_order',
			'last'        => 'last_order',
		);
		$order_by = self::order_by_2( $sort_map, $a['orderby'], $a['dir'], $a['orderby2'], $a['dir2'], 'emails', 'orders DESC' );

		$params     = array();
		$search_sql = self::profile_search_sql( $a['search'], $params );
		array_push( $params, (int) $a['limit'], (int) $a['offset'] );

		$sql = "SELECT
				{$col} cluster_key,
				MAX(ship_text) ship_text,
				COUNT(DISTINCT email_norm) emails,
				COUNT(DISTINCT customer_name) names,
				COUNT(*) orders,
				SUM(CASE WHEN status IN ({$paid}) THEN 1 ELSE 0 END) paid_orders,
				COALESCE(SUM(CASE WHEN status IN ({$paid}) THEN order_total ELSE 0 END),0) spend,
				COALESCE(SUM(is_chargeback),0) chargebacks,
				{$other},
				MAX(risk_score) max_risk,
				MIN(order_date) first_order,
				MAX(order_date) last_order
			FROM {$t}
			WHERE {$col} <> ''{$search_sql}
			GROUP BY {$col}
			ORDER BY {$order_by}
			LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Distinct values (with counts) of one column across a cluster's orders.
	 *
	 * @param string $type 'address' | 'ip'.
	 * @param string $key  ship_hash or IP.
	 * @param string $what payment_method | user_agent.
	 * @return array
	 */
	public static function cluster_distinct( $type, $key, $what ) {
		global $wpdb;
		$allowed = array( 'payment_method', 'user_agent' );
		if ( ! in_array( $what, $allowed, true ) ) {
			return array();
		}
		$col = self::cluster_column( $type );
		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( "SELECT {$what} v, COUNT(*) c FROM " . self::index_table() . " WHERE {$col} = %s AND {$what} <> '' GROUP BY {$what} ORDER BY c DESC LIMIT 50", $key ),
			ARRAY_A
		);
	}

	/**
	 * Coupon codes used at a cluster, with counts.
	 *
	 * @param string $type 'address' | 'ip'.
	 * @param string $key  ship_hash or IP.
	 * @return array code => times used.
	 */
	public static function cluster_coupons( $type, $key ) {
		global $wpdb;
		$col  = self::cluster_column( $type );
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT coupons FROM ' . self::index_table() . " WHERE {$col} = %s AND coupons <> ''", $key ) ); // phpcs:ignore

		$counts = array();
		foreach ( (array) $rows as $list ) {
			foreach ( explode( ',', (string) $list ) as $code ) {
				$code = trim( $code );
				if ( '' !== $code ) {
					$counts[ $code ] = isset( $counts[ $code ] ) ? $counts[ $code ] + 1 : 1;
				}
			}
		}
		arsort( $counts );
		return $counts;
	}

	/**
	 * Each distinct email/account seen at a cluster, with a per-account summary
	 * (name, phone, order + spend totals, first/last order, latest payment,
	 * representative account id). Answers "who are all the people here?".
	 *
	 * @param string $type 'address' | 'ip'.
	 * @param string $key  ship_hash or IP value.
	 * @return array
	 */
	public static function cluster_emails( $type, $key ) {
		global $wpdb;
		$table = self::index_table();
		$col   = self::cluster_column( $type );
		$paid  = self::paid_in_sql();
		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT
					email_norm,
					MAX(customer_name) customer_name,
					MAX(phone_norm) phone_norm,
					MAX(user_id) user_id,
					COUNT(*) orders,
					SUM(CASE WHEN status IN ({$paid}) THEN 1 ELSE 0 END) paid_orders,
					COALESCE(SUM(CASE WHEN status IN ({$paid}) THEN order_total ELSE 0 END),0) spend,
					COALESCE(SUM(is_chargeback),0) chargebacks,
					MIN(order_date) first_order,
					MAX(order_date) last_order,
					SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(payment_method,'') ORDER BY order_date DESC SEPARATOR 0x1f), 0x1f, 1) latest_payment
				FROM {$table}
				WHERE {$col} = %s AND email_norm <> ''
				GROUP BY email_norm
				ORDER BY orders DESC, spend DESC",
				$key
			),
			ARRAY_A
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Customer profiles (every identity seen in orders, keyed by email)
	 * ----------------------------------------------------------------- */

	/**
	 * Search fragment for profile queries (index table only, no users join).
	 *
	 * @param string $search Raw search.
	 * @param array  $params Params (appended by reference).
	 * @return string SQL starting with ' AND ' or ''.
	 */
	private static function profile_search_sql( $search, &$params ) {
		global $wpdb;
		$search = trim( (string) $search );
		if ( '' === $search ) {
			return '';
		}
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		for ( $i = 0; $i < 5; $i++ ) {
			$params[] = $like;
		}
		return ' AND (email_norm LIKE %s OR customer_name LIKE %s OR phone_norm LIKE %s OR ip LIKE %s OR ship_text LIKE %s)';
	}

	/**
	 * Number of distinct customer identities (emails) in the index.
	 *
	 * @param string $search Free-text search.
	 * @param string $from   Inclusive order_date lower bound ('' = none).
	 * @param string $to     Inclusive order_date upper bound ('' = none).
	 * @return int
	 */
	public static function profiles_count( $search = '', $from = '', $to = '' ) {
		global $wpdb;
		$t          = self::index_table();
		$params     = array();
		$search_sql = self::profile_search_sql( $search, $params );
		$range_sql  = self::order_date_range_sql( $from, $to, $params );

		$sql = "SELECT COUNT(DISTINCT email_norm) FROM {$t} WHERE email_norm <> ''{$search_sql}{$range_sql}";
		return empty( $params )
			? (int) $wpdb->get_var( $sql ) // phpcs:ignore
			: (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/**
	 * WHERE fragment restricting rows to an order_date window. Bounds are
	 * site-local 'Y-m-d H:i:s' strings, matching how order_date is stored;
	 * empty bounds add nothing. The column is written bare on purpose — the
	 * users table these queries sometimes join has no order_date, so it can
	 * never be ambiguous.
	 *
	 * @param string $from   Inclusive lower bound ('' = none).
	 * @param string $to     Inclusive upper bound ('' = none).
	 * @param array  $params Collected placeholders, by reference.
	 * @return string
	 */
	private static function order_date_range_sql( $from, $to, &$params ) {
		$sql = '';
		if ( '' !== (string) $from ) {
			$sql     .= ' AND order_date >= %s';
			$params[] = (string) $from;
		}
		if ( '' !== (string) $to ) {
			$sql     .= ' AND order_date <= %s';
			$params[] = (string) $to;
		}
		return $sql;
	}

	/**
	 * One row per customer identity (email), with the headline intelligence
	 * numbers. Sortable and searchable.
	 *
	 * With a from/to window, only orders inside the window are aggregated: the
	 * counts, spend and risk describe the period, and identities with no orders
	 * in it drop out entirely — which is what "export profiles for July" means.
	 *
	 * @param array $args orderby, dir, search, from, to, limit, offset.
	 * @return array
	 */
	public static function profiles_list( $args = array() ) {
		global $wpdb;
		$a = wp_parse_args(
			$args,
			array(
				'orderby'  => 'last',
				'dir'      => 'desc',
				'orderby2' => '',
				'dir2'     => 'desc',
				'search'   => '',
				'from'     => '',
				'to'       => '',
				'limit'    => 50,
				'offset'   => 0,
			)
		);
		$t     = self::index_table();
		$users = $wpdb->users;
		$paid  = self::paid_in_sql();

		$sort_map = array(
			'email'       => 'email_norm',
			'name'        => 'customer_name',
			'registered'  => 'user_registered',
			'orders'      => 'orders',
			'paid'        => 'paid_orders',
			'spend'       => 'spend',
			'aov'         => 'aov',
			'chargebacks' => 'chargebacks',
			'ips'         => 'ips',
			'addresses'   => 'addresses',
			'risk'        => 'max_risk',
			'first'       => 'first_order',
			'last'        => 'last_order',
		);
		$order_by = self::order_by_2( $sort_map, $a['orderby'], $a['dir'], $a['orderby2'], $a['dir2'], 'last_order', 'last_order DESC' );

		$params     = array();
		$search_sql = self::profile_search_sql( $a['search'], $params );
		$range_sql  = self::order_date_range_sql( $a['from'], $a['to'], $params );
		array_push( $params, (int) $a['limit'], (int) $a['offset'] );

		$sql = "SELECT
				i.email_norm,
				MAX(i.customer_name) customer_name,
				MAX(i.user_id) user_id,
				MAX(u.user_registered) user_registered,
				COUNT(*) orders,
				SUM(CASE WHEN i.status IN ({$paid}) THEN 1 ELSE 0 END) paid_orders,
				COALESCE(SUM(CASE WHEN i.status IN ({$paid}) THEN i.order_total ELSE 0 END),0) spend,
				COALESCE(SUM(CASE WHEN i.status IN ({$paid}) THEN i.order_total ELSE 0 END) / NULLIF(SUM(CASE WHEN i.status IN ({$paid}) THEN 1 ELSE 0 END),0),0) aov,
				COALESCE(SUM(i.is_chargeback),0) chargebacks,
				COUNT(DISTINCT i.ip) ips,
				COUNT(DISTINCT i.ship_hash) addresses,
				MAX(i.risk_score) max_risk,
				MIN(i.order_date) first_order,
				MAX(i.order_date) last_order
			FROM {$t} i
			LEFT JOIN {$users} u ON u.ID = i.user_id
			WHERE i.email_norm <> ''{$search_sql}{$range_sql}
			GROUP BY i.email_norm
			ORDER BY {$order_by}
			LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Full aggregate summary for one identity.
	 *
	 * @param string $email Normalized email.
	 * @return array
	 */
	public static function profile_summary( $email ) {
		global $wpdb;
		$t    = self::index_table();
		$paid = self::paid_in_sql();

		$sql = "SELECT
				MAX(customer_name) customer_name,
				MAX(user_id) user_id,
				COUNT(*) orders,
				SUM(CASE WHEN status IN ({$paid}) THEN 1 ELSE 0 END) paid_orders,
				COALESCE(SUM(CASE WHEN status IN ({$paid}) THEN order_total ELSE 0 END),0) spend,
				COALESCE(SUM(CASE WHEN status IN ({$paid}) THEN order_total ELSE 0 END) / NULLIF(SUM(CASE WHEN status IN ({$paid}) THEN 1 ELSE 0 END),0),0) aov,
				COALESCE(SUM(is_chargeback),0) chargebacks,
				COUNT(DISTINCT ip) ips,
				COUNT(DISTINCT ship_hash) addresses,
				COUNT(DISTINCT customer_name) names,
				COUNT(DISTINCT phone_norm) phones,
				COUNT(DISTINCT payment_method) payment_methods,
				COUNT(DISTINCT shipping_country) countries,
				MAX(risk_score) max_risk,
				MIN(order_date) first_order,
				MAX(order_date) last_order,
				SUM(CASE WHEN bill_hash <> '' AND ship_hash <> '' THEN 1 ELSE 0 END) bill_known,
				SUM(CASE WHEN bill_hash <> '' AND ship_hash <> '' AND bill_hash = ship_hash THEN 1 ELSE 0 END) bill_match
			FROM {$t} WHERE email_norm = %s";

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $email ), ARRAY_A ); // phpcs:ignore
		return is_array( $row ) ? $row : array();
	}

	/**
	 * One page of an identity's orders (newest first).
	 *
	 * @param string $email  Normalized email.
	 * @param int    $limit  Per page.
	 * @param int    $offset Offset.
	 * @param string $from   Inclusive order_date lower bound ('' = none).
	 * @param string $to     Inclusive order_date upper bound ('' = none).
	 * @return array
	 */
	public static function profile_orders( $email, $limit = 50, $offset = 0, $from = '', $to = '' ) {
		global $wpdb;
		$params    = array( $email );
		$range_sql = self::order_date_range_sql( $from, $to, $params );
		array_push( $params, (int) $limit, (int) $offset );

		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( 'SELECT * FROM ' . self::index_table() . " WHERE email_norm = %s{$range_sql} ORDER BY order_date DESC, id DESC LIMIT %d OFFSET %d", $params ),
			ARRAY_A
		);
	}

	/**
	 * Distinct values (with counts) of one column across an identity's orders.
	 *
	 * @param string $email Normalized email.
	 * @param string $what  payment_method | user_agent | ip | customer_name | phone_norm.
	 * @return array [ ['v'=>..,'c'=>..], ... ]
	 */
	public static function profile_distinct( $email, $what ) {
		global $wpdb;
		$allowed = array( 'payment_method', 'user_agent', 'ip', 'customer_name', 'phone_norm' );
		if ( ! in_array( $what, $allowed, true ) ) {
			return array();
		}
		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( "SELECT {$what} v, COUNT(*) c FROM " . self::index_table() . " WHERE email_norm = %s AND {$what} <> '' GROUP BY {$what} ORDER BY c DESC LIMIT 50", $email ),
			ARRAY_A
		);
	}

	/**
	 * Shipping addresses used by an identity, with counts.
	 *
	 * @param string $email Normalized email.
	 * @return array
	 */
	public static function profile_addresses( $email ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( 'SELECT ship_hash, MAX(ship_text) ship_text, COUNT(*) c FROM ' . self::index_table() . " WHERE email_norm = %s AND ship_hash <> '' GROUP BY ship_hash ORDER BY c DESC LIMIT 50", $email ),
			ARRAY_A
		);
	}

	/**
	 * Coupon codes an identity has used, with counts (codes are stored as a
	 * comma list per order, so aggregation happens here).
	 *
	 * @param string $email Normalized email.
	 * @return array code => times used, most used first.
	 */
	public static function profile_coupons( $email ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT coupons FROM ' . self::index_table() . " WHERE email_norm = %s AND coupons <> ''", $email ) ); // phpcs:ignore

		$counts = array();
		foreach ( (array) $rows as $list ) {
			foreach ( explode( ',', (string) $list ) as $code ) {
				$code = trim( $code );
				if ( '' !== $code ) {
					$counts[ $code ] = isset( $counts[ $code ] ) ? $counts[ $code ] + 1 : 1;
				}
			}
		}
		arsort( $counts );
		return $counts;
	}

	/**
	 * Other identities that share an IP or a shipping address with this one —
	 * the "who are they connected to" network view.
	 *
	 * @param string $email Normalized email.
	 * @return array Rows: email_norm, customer_name, user_id, orders, shared_ips, shared_addresses, chargebacks.
	 */
	public static function profile_linked( $email ) {
		global $wpdb;
		$t = self::index_table();

		$ips    = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ip FROM {$t} WHERE email_norm = %s AND ip <> '' LIMIT 50", $email ) ); // phpcs:ignore
		$hashes = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ship_hash FROM {$t} WHERE email_norm = %s AND ship_hash <> '' LIMIT 50", $email ) ); // phpcs:ignore

		if ( empty( $ips ) && empty( $hashes ) ) {
			return array();
		}

		$parts  = array();
		$params = array();

		// CASE expressions (select list) come first in parameter order.
		$ip_case   = '0';
		$addr_case = '0';
		if ( ! empty( $ips ) ) {
			$ph      = implode( ',', array_fill( 0, count( $ips ), '%s' ) );
			$ip_case = "COUNT(DISTINCT CASE WHEN ip IN ({$ph}) THEN ip END)";
			$params  = array_merge( $params, $ips );
		}
		if ( ! empty( $hashes ) ) {
			$ph        = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
			$addr_case = "COUNT(DISTINCT CASE WHEN ship_hash IN ({$ph}) THEN ship_hash END)";
			$params    = array_merge( $params, $hashes );
		}

		// WHERE conditions next.
		$params[] = $email;
		if ( ! empty( $ips ) ) {
			$ph      = implode( ',', array_fill( 0, count( $ips ), '%s' ) );
			$parts[] = "ip IN ({$ph})";
			$params  = array_merge( $params, $ips );
		}
		if ( ! empty( $hashes ) ) {
			$ph      = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
			$parts[] = "ship_hash IN ({$ph})";
			$params  = array_merge( $params, $hashes );
		}
		$where = implode( ' OR ', $parts );

		// Ordered from a derived table: MariaDB refuses an aggregate alias
		// inside an ORDER BY expression ("Reference 'shared_ips' not supported"),
		// which silently emptied this list on MariaDB hosts. As columns of the
		// inner query the aliases are plain values on every engine.
		$sql = "SELECT * FROM (
				SELECT
					email_norm,
					MAX(customer_name) customer_name,
					MAX(user_id) user_id,
					COUNT(*) orders,
					{$ip_case} shared_ips,
					{$addr_case} shared_addresses,
					COALESCE(SUM(is_chargeback),0) chargebacks
				FROM {$t}
				WHERE email_norm <> %s AND email_norm <> '' AND ({$where})
				GROUP BY email_norm
			) linked
			ORDER BY (shared_ips + shared_addresses) DESC, orders DESC
			LIMIT 100";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Every order date for an identity, oldest first (for cadence analysis).
	 *
	 * @param string $email Normalized email.
	 * @return array Datetime strings.
	 */
	public static function profile_dates( $email ) {
		global $wpdb;
		return (array) $wpdb->get_col( $wpdb->prepare( 'SELECT order_date FROM ' . self::index_table() . ' WHERE email_norm = %s AND order_date IS NOT NULL ORDER BY order_date ASC LIMIT 500', $email ) ); // phpcs:ignore
	}

	/**
	 * Distinct IPs with the countries entered on those orders (for the
	 * geo-consistency check).
	 *
	 * @param string $email Normalized email.
	 * @return array
	 */
	public static function profile_geo_rows( $email ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( 'SELECT ip, MAX(billing_country) bc, MAX(shipping_country) sc, COUNT(*) c FROM ' . self::index_table() . " WHERE email_norm = %s AND ip <> '' GROUP BY ip ORDER BY c DESC LIMIT 30", $email ),
			ARRAY_A
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Repeat-customer report (accounts with 2+ orders in a time window)
	 * ----------------------------------------------------------------- */

	/**
	 * Free-text search WHERE fragment for customer reports: matches any of the
	 * customer's order rows on email, name, phone, IP, or shipping address, or
	 * the account email itself.
	 *
	 * @param string $search Raw search string.
	 * @param array  $params Query params (appended by reference).
	 * @return string SQL fragment starting with ' AND ' or ''.
	 */
	private static function customer_search_sql( $search, &$params ) {
		global $wpdb;
		$search = trim( (string) $search );
		if ( '' === $search ) {
			return '';
		}
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		for ( $i = 0; $i < 6; $i++ ) {
			$params[] = $like;
		}
		return ' AND (i.email_norm LIKE %s OR i.customer_name LIKE %s OR i.phone_norm LIKE %s OR i.ip LIKE %s OR i.ship_text LIKE %s OR u.user_email LIKE %s)';
	}

	/**
	 * Sanitize an ORDER BY direction.
	 *
	 * @param string $dir Requested direction.
	 * @return string 'ASC' or 'DESC'.
	 */
	private static function dir_sql( $dir ) {
		return ( 'asc' === strtolower( (string) $dir ) ) ? 'ASC' : 'DESC';
	}

	/**
	 * Build a whitelisted, injection-safe ORDER BY clause supporting an optional
	 * SECONDARY sort column (Ctrl+click multi-sort). Both keys are resolved
	 * through the caller's whitelist map; anything unknown falls back to the
	 * default. A stable tiebreaker is always appended.
	 *
	 * @param array  $map      key => SQL expression whitelist.
	 * @param string $orderby  Primary sort key.
	 * @param string $dir      Primary direction.
	 * @param string $orderby2 Secondary sort key ('' = none).
	 * @param string $dir2     Secondary direction.
	 * @param string $default  Default SQL expression when the key is unknown.
	 * @param string $tiebreak Trailing stable tiebreaker SQL (already safe).
	 * @return string SQL after "ORDER BY ".
	 */
	private static function order_by_2( $map, $orderby, $dir, $orderby2, $dir2, $default, $tiebreak ) {
		$primary = isset( $map[ $orderby ] ) ? $map[ $orderby ] : $default;
		$clause  = $primary . ' ' . self::dir_sql( $dir );

		$orderby2 = (string) $orderby2;
		if ( '' !== $orderby2 && isset( $map[ $orderby2 ] ) && $map[ $orderby2 ] !== $primary ) {
			$clause .= ', ' . $map[ $orderby2 ] . ' ' . self::dir_sql( $dir2 );
		}
		if ( '' !== $tiebreak ) {
			$clause .= ', ' . $tiebreak;
		}
		return $clause;
	}

	/**
	 * Registered customers with at least $min_orders COUNTED orders (successful
	 * payments + chargebacks) whose order date falls in [$from, $to]. Sortable
	 * and searchable. Includes the latest order's IP / payment / country.
	 *
	 * @param array $args from, to, min_orders, orderby, dir, search, limit, offset.
	 * @return array
	 */
	public static function repeat_customers( $args = array() ) {
		global $wpdb;
		$a = wp_parse_args(
			$args,
			array(
				'from'       => '1970-01-01 00:00:00',
				'to'         => current_time( 'mysql' ),
				'min_orders' => 2,
				'orderby'    => 'last_order',
				'dir'        => 'desc',
				'orderby2'   => '',
				'dir2'       => 'desc',
				'search'     => '',
				'limit'      => 50,
				'offset'     => 0,
			)
		);
		$index   = self::index_table();
		$users   = $wpdb->users;
		$counted = self::counted_orders_sql( 'i.' );
		$paid    = self::paid_in_sql();

		$sort_map = array(
			'name'        => 'customer_name',
			'email'       => 'u.user_email',
			'registered'  => 'u.user_registered',
			'orders'      => 'orders',
			'spend'       => 'spend',
			'chargebacks' => 'chargebacks',
			'payment'     => 'latest_payment',
			'ip'          => 'latest_ip',
			'last_order'  => 'last_order',
		);
		$order_by = self::order_by_2( $sort_map, $a['orderby'], $a['dir'], $a['orderby2'], $a['dir2'], 'last_order', 'last_order DESC' );

		$params = array( $a['from'], $a['to'] );
		$search = self::customer_search_sql( $a['search'], $params );
		array_push( $params, (int) $a['min_orders'], (int) $a['limit'], (int) $a['offset'] );

		$sql = "SELECT
				i.user_id,
				u.user_email,
				u.user_registered,
				u.display_name,
				MAX(i.customer_name) customer_name,
				COUNT(*) orders,
				COALESCE(SUM(CASE WHEN i.status IN ({$paid}) THEN i.order_total ELSE 0 END),0) spend,
				COUNT(DISTINCT i.ip) ips,
				COUNT(DISTINCT i.ship_hash) addresses,
				COALESCE(SUM(i.is_chargeback),0) chargebacks,
				MIN(i.order_date) first_order,
				MAX(i.order_date) last_order,
				SUBSTRING_INDEX(GROUP_CONCAT(i.ip ORDER BY i.order_date DESC SEPARATOR 0x1f), 0x1f, 1) latest_ip,
				SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(i.payment_method,'') ORDER BY i.order_date DESC SEPARATOR 0x1f), 0x1f, 1) latest_payment,
				SUBSTRING_INDEX(GROUP_CONCAT(i.shipping_country ORDER BY i.order_date DESC SEPARATOR 0x1f), 0x1f, 1) latest_country
			FROM {$index} i
			INNER JOIN {$users} u ON u.ID = i.user_id
			WHERE i.user_id > 0 AND {$counted} AND i.order_date >= %s AND i.order_date <= %s{$search}
			GROUP BY i.user_id, u.user_email, u.user_registered, u.display_name
			HAVING orders >= %d
			ORDER BY {$order_by}
			LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Count of repeat customers matching the window + threshold + search.
	 *
	 * @param string $from       Start datetime.
	 * @param string $to         End datetime.
	 * @param int    $min_orders Minimum counted orders in the window.
	 * @param string $search     Free-text search.
	 * @return int
	 */
	public static function repeat_customers_count( $from, $to, $min_orders = 2, $search = '' ) {
		global $wpdb;
		$index   = self::index_table();
		$users   = $wpdb->users;
		$counted = self::counted_orders_sql( 'i.' );

		$params     = array( $from, $to );
		$search_sql = self::customer_search_sql( $search, $params );
		$params[]   = (int) $min_orders;

		$sql = "SELECT COUNT(*) FROM (
				SELECT i.user_id
				FROM {$index} i
				INNER JOIN {$users} u ON u.ID = i.user_id
				WHERE i.user_id > 0 AND {$counted} AND i.order_date >= %s AND i.order_date <= %s{$search_sql}
				GROUP BY i.user_id
				HAVING COUNT(*) >= %d
			) t";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/* ----------------------------------------------------------------- *
	 *  New-customer analysis (accounts registered recently, by velocity)
	 * ----------------------------------------------------------------- */

	/**
	 * GMT cutoff datetime for "registered within the last N months".
	 *
	 * user_registered is stored in GMT, so we compare against a GMT cutoff.
	 *
	 * @param int $months Window in months.
	 * @return string MySQL datetime.
	 */
	private static function registered_since( $months ) {
		$months = max( 1, (int) $months );
		return gmdate( 'Y-m-d H:i:s', time() - $months * 30 * DAY_IN_SECONDS );
	}

	/**
	 * Ranked list of new customers (registered accounts) with COUNTED order
	 * activity (successful payments + chargebacks). Sortable and searchable.
	 *
	 * @param array $args months, min_orders, orderby, dir, search, limit, offset.
	 * @return array
	 */
	public static function new_customers( $args = array() ) {
		global $wpdb;
		$a = wp_parse_args(
			$args,
			array(
				'months'     => 3,
				'min_orders' => 2,
				'orderby'    => 'frequency',
				'dir'        => 'desc',
				'orderby2'   => '',
				'dir2'       => 'desc',
				'search'     => '',
				'limit'      => 50,
				'offset'     => 0,
			)
		);

		$index   = self::index_table();
		$users   = $wpdb->users;
		$since   = self::registered_since( $a['months'] );
		$counted = self::counted_orders_sql( 'i.' );
		$paid    = self::paid_in_sql();

		$sort_map = array(
			'frequency'   => 'orders_per_month',
			'spend'       => 'total_spend',
			'orders'      => 'orders',
			'chargebacks' => 'chargebacks',
			'risk'        => 'max_risk',
			'recent'      => 'last_order',
			'registered'  => 'u.user_registered',
			'age'         => 'age_days',
			'name'        => 'customer_name',
			'email'       => 'u.user_email',
		);
		$order_by = self::order_by_2( $sort_map, $a['orderby'], $a['dir'], $a['orderby2'], $a['dir2'], 'orders_per_month', 'orders_per_month DESC' );

		$params = array( $since );
		$search = self::customer_search_sql( $a['search'], $params );
		array_push( $params, (int) $a['min_orders'], (int) $a['limit'], (int) $a['offset'] );

		// $index, $users, $orderby and $dir are all trusted (internal / whitelisted).
		$sql = "SELECT
				i.user_id,
				u.user_email,
				u.user_registered,
				u.display_name,
				COUNT(*) AS orders,
				COALESCE(SUM(CASE WHEN i.status IN ({$paid}) THEN i.order_total ELSE 0 END),0) AS total_spend,
				COALESCE(SUM(i.is_chargeback),0) AS chargebacks,
				COUNT(DISTINCT i.ip) AS ips,
				COUNT(DISTINCT i.ship_hash) AS addresses,
				COUNT(DISTINCT i.email_norm) AS emails,
				MAX(i.risk_score) AS max_risk,
				MIN(i.order_date) AS first_order,
				MAX(i.order_date) AS last_order,
				MAX(i.customer_name) AS customer_name,
				DATEDIFF(UTC_TIMESTAMP(), u.user_registered) AS age_days,
				(COUNT(*) / GREATEST(DATEDIFF(UTC_TIMESTAMP(), u.user_registered), 1) * 30) AS orders_per_month
			FROM {$index} i
			INNER JOIN {$users} u ON u.ID = i.user_id
			WHERE i.user_id > 0 AND {$counted} AND u.user_registered >= %s{$search}
			GROUP BY i.user_id, u.user_email, u.user_registered, u.display_name
			HAVING orders >= %d
			ORDER BY {$order_by}
			LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Count of new customers matching the window + min-orders + search filters.
	 *
	 * @param int    $months     Window.
	 * @param int    $min_orders Minimum counted orders.
	 * @param string $search     Free-text search.
	 * @return int
	 */
	public static function new_customers_count( $months, $min_orders, $search = '' ) {
		global $wpdb;
		$index   = self::index_table();
		$users   = $wpdb->users;
		$since   = self::registered_since( $months );
		$counted = self::counted_orders_sql( 'i.' );

		$params     = array( $since );
		$search_sql = self::customer_search_sql( $search, $params );
		$params[]   = (int) $min_orders;

		$sql = "SELECT COUNT(*) FROM (
				SELECT i.user_id
				FROM {$index} i
				INNER JOIN {$users} u ON u.ID = i.user_id
				WHERE i.user_id > 0 AND {$counted} AND u.user_registered >= %s{$search_sql}
				GROUP BY i.user_id
				HAVING COUNT(*) >= %d
			) t";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/**
	 * Aggregate stats for a single registered customer.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function customer_summary( $user_id ) {
		global $wpdb;
		$t    = self::index_table();
		$paid = self::paid_in_sql();
		$sql  = "SELECT
				COUNT(*) orders,
				SUM(CASE WHEN status IN ({$paid}) THEN 1 ELSE 0 END) paid_orders,
				COALESCE(SUM(CASE WHEN status IN ({$paid}) THEN order_total ELSE 0 END),0) total_spend,
				COALESCE(SUM(is_chargeback),0) chargebacks,
				COUNT(DISTINCT ip) ips,
				COUNT(DISTINCT ship_hash) addresses,
				COUNT(DISTINCT email_norm) emails,
				COUNT(DISTINCT shipping_country) countries,
				MAX(risk_score) max_risk,
				MIN(order_date) first_order,
				MAX(order_date) last_order
			FROM {$t} WHERE user_id = %d";
		$row  = $wpdb->get_row( $wpdb->prepare( $sql, (int) $user_id ), ARRAY_A ); // phpcs:ignore
		return is_array( $row ) ? $row : array();
	}

	/**
	 * Orders placed by one customer (chargebacks + highest risk first).
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Per page.
	 * @param int $offset  Offset.
	 * @return array
	 */
	public static function customer_orders( $user_id, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$t = self::index_table();
		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE user_id = %d ORDER BY order_date DESC, id DESC LIMIT %d OFFSET %d",
				(int) $user_id,
				(int) $limit,
				(int) $offset
			),
			ARRAY_A
		);
	}

	/**
	 * Distinct IPs a customer has ordered from, with counts.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function customer_ips( $user_id ) {
		global $wpdb;
		$t = self::index_table();
		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( "SELECT ip, COUNT(*) c, MAX(shipping_country) country FROM {$t} WHERE user_id = %d AND ip <> '' GROUP BY ip ORDER BY c DESC", (int) $user_id ),
			ARRAY_A
		);
	}

	/**
	 * Distinct shipping addresses a customer has used, with counts.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function customer_addresses( $user_id ) {
		global $wpdb;
		$t = self::index_table();
		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( "SELECT ship_hash, MAX(ship_text) ship_text, COUNT(*) c FROM {$t} WHERE user_id = %d AND ship_hash <> '' GROUP BY ship_hash ORDER BY c DESC", (int) $user_id ),
			ARRAY_A
		);
	}

	/**
	 * Distinct billing identities (email / name / phone) seen across a
	 * customer's orders — used to check consistency against the account.
	 *
	 * @param int $user_id User id.
	 * @return array { emails:[], names:[], phones:[] }
	 */
	public static function customer_identity( $user_id ) {
		global $wpdb;
		$t   = self::index_table();
		$uid = (int) $user_id;
		return array(
			'emails' => $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT email_norm FROM {$t} WHERE user_id = %d AND email_norm <> ''", $uid ) ), // phpcs:ignore
			'names'  => $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT customer_name FROM {$t} WHERE user_id = %d AND customer_name <> ''", $uid ) ), // phpcs:ignore
			'phones' => $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT phone_norm FROM {$t} WHERE user_id = %d AND phone_norm <> ''", $uid ) ), // phpcs:ignore
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Geography rollups + geo backfill (v1.7)
	 * ----------------------------------------------------------------- */

	/**
	 * Grouping config for a geography level. All fragments are internal /
	 * whitelisted — no user input is interpolated.
	 *
	 * @param string $level 'city' | 'state' | 'zip'.
	 * @return array [ group_by_sql, select_prefix_sql, where_sql, search_cols[] ]
	 */
	private static function geo_level_sql( $level ) {
		switch ( $level ) {
			case 'street':
				return array(
					'shipping_country,ship_state,ship_city,ship_street',
					'shipping_country, ship_state, ship_city, ship_street,',
					"shipping_country <> '' AND ship_state <> '' AND ship_state IS NOT NULL AND ship_city <> '' AND ship_city IS NOT NULL AND ship_street <> '' AND ship_street IS NOT NULL",
					array( 'ship_street', 'ship_city', 'ship_state', 'shipping_country' ),
				);
			case 'city':
				return array(
					'shipping_country,ship_state,ship_city',
					'shipping_country, ship_state, ship_city,',
					"shipping_country <> '' AND ship_state <> '' AND ship_state IS NOT NULL AND ship_city <> '' AND ship_city IS NOT NULL",
					array( 'ship_city', 'ship_state', 'shipping_country' ),
				);
			case 'zip':
				return array(
					'shipping_country,ship_state,ship_postcode',
					'shipping_country, ship_state, ship_postcode,',
					"shipping_country <> '' AND ship_postcode <> '' AND ship_postcode IS NOT NULL",
					array( 'ship_postcode', 'ship_state', 'shipping_country' ),
				);
			case 'state':
			default:
				return array(
					'shipping_country,ship_state',
					'shipping_country, ship_state,',
					"shipping_country <> '' AND ship_state <> '' AND ship_state IS NOT NULL",
					array( 'ship_state', 'shipping_country' ),
				);
		}
	}

	/**
	 * Fraud rollup grouped by ship-to state / city / ZIP. Shows both the
	 * average risk score (the merchant's question) and chargeback count/rate
	 * (ground truth). Defaults sort by avg score DESC so the worst place is row 1.
	 *
	 * @param array $args level, search, months, min_orders, orderby, order, limit, offset.
	 * @return array
	 */
	public static function geo_rollup( $args = array() ) {
		global $wpdb;
		$a = wp_parse_args(
			$args,
			array(
				'level'      => 'state',
				'search'     => '',
				'months'     => 0,
				'min_orders' => 5,
				'orderby'    => 'avg_score',
				'order'      => 'DESC',
				'limit'      => 50,
				'offset'     => 0,
			)
		);
		$t = self::index_table();
		list( $group_sql, $select_prefix, $where, $search_cols ) = self::geo_level_sql( $a['level'] );

		$sort_map = array(
			'country'     => 'shipping_country',
			'state'       => 'ship_state',
			'city'        => 'ship_city',
			'zip'         => 'ship_postcode',
			'street'      => 'ship_street',
			'orders'      => 'orders',
			'customers'   => 'customers',
			'avg_score'   => 'avg_score',
			'high'        => 'high_ct',
			'chargebacks' => 'cbs',
			'cb_rate'     => 'cb_rate',
			'last_order'  => 'last_order',
		);
		$orderby = isset( $sort_map[ $a['orderby'] ] ) ? $sort_map[ $a['orderby'] ] : 'avg_score';
		$dir     = self::dir_sql( $a['order'] );

		$params = array();
		if ( (int) $a['months'] > 0 ) {
			$where   .= ' AND order_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)';
			$params[] = (int) $a['months'];
		}
		$search = trim( (string) $a['search'] );
		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$parts = array();
			foreach ( $search_cols as $col ) {
				$parts[]  = $col . ' LIKE %s';
				$params[] = $like;
			}
			$where .= ' AND (' . implode( ' OR ', $parts ) . ')';
		}
		$params[] = (int) $a['min_orders'];
		$params[] = (int) $a['limit'];
		$params[] = (int) $a['offset'];

		$sql = "SELECT {$select_prefix}
				COUNT(*) orders,
				COUNT(DISTINCT email_norm) customers,
				ROUND(AVG(risk_score),1) avg_score,
				SUM(risk_level IN ('high','block')) high_ct,
				SUM(is_chargeback) cbs,
				ROUND(SUM(is_chargeback)/COUNT(*)*100,1) cb_rate,
				MAX(order_date) last_order
			FROM {$t}
			WHERE {$where}
			GROUP BY {$group_sql}
			HAVING orders >= %d
			ORDER BY {$orderby} {$dir}, orders DESC
			LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Number of geography groups matching the rollup filters (for pagination).
	 *
	 * @param array $args level, search, months, min_orders.
	 * @return int
	 */
	public static function geo_rollup_count( $args = array() ) {
		global $wpdb;
		$a = wp_parse_args(
			$args,
			array(
				'level'      => 'state',
				'search'     => '',
				'months'     => 0,
				'min_orders' => 5,
			)
		);
		$t = self::index_table();
		list( $group_sql, $select_prefix, $where, $search_cols ) = self::geo_level_sql( $a['level'] );
		unset( $select_prefix );

		$params = array();
		if ( (int) $a['months'] > 0 ) {
			$where   .= ' AND order_date >= DATE_SUB(NOW(), INTERVAL %d MONTH)';
			$params[] = (int) $a['months'];
		}
		$search = trim( (string) $a['search'] );
		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$parts = array();
			foreach ( $search_cols as $col ) {
				$parts[]  = $col . ' LIKE %s';
				$params[] = $like;
			}
			$where .= ' AND (' . implode( ' OR ', $parts ) . ')';
		}
		$params[] = (int) $a['min_orders'];

		$sql = "SELECT COUNT(*) FROM ( SELECT 1 FROM {$t} WHERE {$where} GROUP BY {$group_sql} HAVING COUNT(*) >= %d ) t";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/**
	 * How many indexed rows still lack destination (geo) data derived by the
	 * CURRENT parser.
	 *
	 * geo_scheme is the cursor since v1.31 (before that, a NULL door hash
	 * was): a row stamped below GEO_SCHEME was geo-indexed by an older parser
	 * or never at all, and gets every geo column re-derived from its order.
	 * Blocklist door entries awaiting their v1.31 re-derivation count too, so
	 * "remaining" reads zero only when the whole upgrade has landed.
	 *
	 * @return int
	 */
	public static function geo_backfill_remaining() {
		global $wpdb;
		$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::index_table() . ' WHERE geo_scheme < %d', self::GEO_SCHEME ) ); // phpcs:ignore
		return $rows + FS_Blocklist::door_migration_remaining();
	}

	/**
	 * Backfill geo columns for up to $batch rows stamped below GEO_SCHEME.
	 * A deleted order is stamped current with '' columns so the row never
	 * re-selects and the loop terminates.
	 *
	 * Order of work matters on a v1.31 upgrade: blocklist door entries are
	 * re-derived FIRST (FS_Blocklist::migrate_door_entries() reads each
	 * entry's orders through the index, by exact, loose or old door hash), and
	 * only then are index rows rewritten — so no row loses its old door hash
	 * before the entry that depended on it has been carried forward.
	 *
	 * @param int $batch Rows per call.
	 * @return array { processed:int, remaining:int }
	 */
	public static function geo_backfill_batch( $batch = 100 ) {
		global $wpdb;
		$batch = max( 1, (int) $batch );

		$migrated = FS_Blocklist::migrate_door_entries( $batch );
		if ( $migrated['remaining'] > 0 ) {
			return array(
				'processed' => $migrated['processed'],
				'remaining' => self::geo_backfill_remaining(),
			);
		}

		$t   = self::index_table();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$t} WHERE geo_scheme < %d ORDER BY id DESC LIMIT %d", self::GEO_SCHEME, $batch ) ); // phpcs:ignore

		return array(
			'processed' => $migrated['processed'] + self::geo_refresh_orders( $ids ),
			'remaining' => self::geo_backfill_remaining(),
		);
	}

	/**
	 * Re-derive the geo columns of the given index rows from their live
	 * orders, with the current parser, and stamp them GEO_SCHEME. The one
	 * place a row's ship_street / ship_door_hash is (re)computed after
	 * indexing — the backfill uses it for its batches, and the blocklist
	 * cascades run it over an address's rows BEFORE reading their door hashes,
	 * so a cascade clicked mid-backfill can never plant a stale key.
	 *
	 * A row whose order no longer exists is stamped current with '' in any
	 * still-NULL geo column (what an earlier backfill found is kept).
	 *
	 * @param array $order_ids Order ids.
	 * @return int Rows touched.
	 */
	public static function geo_refresh_orders( $order_ids ) {
		global $wpdb;
		$t     = self::index_table();
		$count = 0;
		foreach ( array_unique( array_map( 'intval', (array) $order_ids ) ) as $oid ) {
			if ( $oid <= 0 ) {
				continue;
			}
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $oid ) : false;
			if ( $order ) {
				$ship = self::resolve_shipping( $order );
				$wpdb->update( // phpcs:ignore
					$t,
					array(
						'ship_city'      => mb_substr( FS_Normalizer::city( $ship['city'] ), 0, 100 ),
						'ship_state'     => mb_substr( FS_Normalizer::region( $ship['state'] ), 0, 32 ),
						'ship_postcode'  => mb_substr( FS_Normalizer::postcode( $ship['postcode'] ), 0, 16 ),
						'ship_street'    => mb_substr( FS_Normalizer::street( $ship ), 0, 100 ),
						'ship_door_hash' => mb_substr( FS_Normalizer::door( $ship ), 0, 64 ),
						'geo_scheme'     => self::GEO_SCHEME,
					),
					array( 'order_id' => $oid )
				);
			} else {
				$wpdb->query( // phpcs:ignore
					$wpdb->prepare(
						"UPDATE {$t} SET geo_scheme = %d, ship_door_hash = COALESCE(ship_door_hash, ''), ship_street = COALESCE(ship_street, ''), ship_city = COALESCE(ship_city, ''), ship_state = COALESCE(ship_state, ''), ship_postcode = COALESCE(ship_postcode, '') WHERE order_id = %d",
						self::GEO_SCHEME,
						$oid
					)
				);
			}
			$count++;
		}
		return $count;
	}

	/**
	 * Make sure the geo backfill will run in the background. One single
	 * event; cron_geo_backfill() re-arms itself until nothing remains. The
	 * Geography page's "Backfill now" button still works and simply gets
	 * there sooner.
	 *
	 * @param int $delay Seconds until the first run.
	 */
	public static function schedule_geo_backfill( $delay = 15 ) {
		if ( ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( self::CRON_GEO ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CRON_GEO );
	}

	/**
	 * Cron: one backfill batch, then re-arm while work remains. Batches are
	 * sized for a shared host's request budget; a large store finishes over
	 * the hours after the update without anyone watching.
	 */
	public static function cron_geo_backfill() {
		$result = self::geo_backfill_batch( 200 );
		if ( $result['remaining'] > 0 ) {
			self::schedule_geo_backfill( 30 );
		}
	}

	/* ----------------------------------------------------------------- *
	 *  Checkout locks + geography drill-down (v1.8)
	 * ----------------------------------------------------------------- */

	/**
	 * Candidate customers for the velocity-lock list: registered accounts and
	 * guest emails with at least $threshold orders in the trailing 30 days. This
	 * is only the CANDIDATE set — the admin confirms each against
	 * FS_Live::is_velocity_locked() so the displayed list matches enforcement
	 * exactly (new-only window, verified, allowlist).
	 *
	 * @param int $threshold Orders-in-30-days threshold.
	 * @param int $limit     Safety cap.
	 * @return array Rows: user_id, user_email, user_registered, customer_name, phone_norm, recent, last_order.
	 */
	public static function velocity_candidates( $threshold, $limit = 500 ) {
		global $wpdb;
		$t         = self::index_table();
		$users     = $wpdb->users;
		$threshold = max( 1, (int) $threshold );
		$cutoff    = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		$accounts = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT i.user_id, u.user_email, u.user_registered,
					MAX(i.customer_name) customer_name, MAX(i.phone_norm) phone_norm,
					COUNT(*) recent, MAX(i.order_date) last_order
				FROM {$t} i INNER JOIN {$users} u ON u.ID = i.user_id
				WHERE i.user_id > 0 AND i.order_date >= %s
				GROUP BY i.user_id, u.user_email, u.user_registered
				HAVING recent >= %d
				ORDER BY recent DESC
				LIMIT %d",
				$cutoff,
				$threshold,
				(int) $limit
			),
			ARRAY_A
		);

		$guests = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT 0 user_id, i.email_norm user_email, NULL user_registered,
					MAX(i.customer_name) customer_name, MAX(i.phone_norm) phone_norm,
					COUNT(*) recent, MAX(i.order_date) last_order
				FROM {$t} i
				WHERE i.user_id = 0 AND i.email_norm <> '' AND i.order_date >= %s
				GROUP BY i.email_norm
				HAVING recent >= %d
				ORDER BY recent DESC
				LIMIT %d",
				$cutoff,
				$threshold,
				(int) $limit
			),
			ARRAY_A
		);

		return array_merge( (array) $accounts, (array) $guests );
	}

	/* ----------------------------------------------------------------- *
	 *  Checkout-block ledger (v1.10): durable record of real block events
	 * ----------------------------------------------------------------- */

	/**
	 * Record (or refresh) a checkout block. One row per identity+kind: repeat
	 * attempts bump the counter and un-resolve the row rather than duplicating.
	 * Deliberately non-fatal — a logging failure must never break a checkout.
	 *
	 * @param string     $kind     'velocity' | 'blocklist' | 'idv' | 'linked'.
	 * @param int        $user_id  Account id (0 for guest).
	 * @param string     $email    Normalized email.
	 * @param string     $name     Best-effort customer name.
	 * @param string     $detail   Human reason detail.
	 * @param array|null $evidence What matched, for the staff view (see FS_Blocklist::match_evidence()
	 *                             and FS_Live's linked evidence). Stored as JSON; the latest attempt
	 *                             that supplies evidence wins, and an attempt without any keeps what
	 *                             is already there. Never sent to webhooks.
	 */
	public static function log_checkout_block( $kind, $user_id, $email, $name = '', $detail = '', $evidence = null ) {
		global $wpdb;
		$kind    = substr( (string) $kind, 0, 16 );
		$user_id = (int) $user_id;
		$email   = mb_substr( (string) $email, 0, 191 );
		$json    = null;
		if ( is_array( $evidence ) && ! empty( $evidence ) ) {
			$json = wp_json_encode( $evidence );
			if ( false === $json ) {
				$json = null;
			}
		}
		// For a logged-in account, key on the ACCOUNT email so the same person
		// can't create two rows (e.g. account email at the gate vs. a different
		// billing email at submit) under the (kind,user_id,email) unique key.
		if ( $user_id > 0 ) {
			$acct = get_userdata( $user_id );
			if ( $acct && ! empty( $acct->user_email ) ) {
				$email = mb_substr( FS_Normalizer::email( $acct->user_email ), 0, 191 );
			}
		}
		if ( '' === $kind || ( $user_id <= 0 && '' === $email ) ) {
			return;
		}
		$t   = self::checkout_blocks_table();
		$now = current_time( 'mysql' );
		// Two statements rather than an embedded literal: an attempt that
		// brings evidence replaces what is stored; one that brings none (a
		// velocity refusal, say) leaves the earlier evidence untouched.
		$common = array( $kind, $user_id, $email, mb_substr( (string) $name, 0, 191 ), mb_substr( (string) $detail, 0, 255 ) );
		if ( null === $json ) {
			$wpdb->query( // phpcs:ignore
				$wpdb->prepare(
					"INSERT INTO {$t} (kind, user_id, email, name, detail, evidence, attempts, first_at, last_at, resolved)
					VALUES (%s, %d, %s, %s, %s, NULL, 1, %s, %s, 0)
					ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_at = VALUES(last_at), name = VALUES(name), detail = VALUES(detail), resolved = 0",
					array_merge( $common, array( $now, $now ) )
				)
			);
		} else {
			$wpdb->query( // phpcs:ignore
				$wpdb->prepare(
					"INSERT INTO {$t} (kind, user_id, email, name, detail, evidence, attempts, first_at, last_at, resolved)
					VALUES (%s, %d, %s, %s, %s, %s, 1, %s, %s, 0)
					ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_at = VALUES(last_at), name = VALUES(name), detail = VALUES(detail), evidence = VALUES(evidence), resolved = 0",
					array_merge( $common, array( $json, $now, $now ) )
				)
			);
		}

		// The one place every refused checkout passes through, whichever rule
		// refused it — so it is also the one place the event has to be raised.
		//
		// Throttled per identity+kind, because the ledger above deliberately
		// collapses repeat attempts into one row and the event stream has to
		// agree with it. Without this, a bot retrying a blocked address turns a
		// single ledger row into unbounded event and delivery rows — on the
		// checkout path, driven by an unauthenticated caller.
		$seen = 'fs_hook_blk_' . md5( $kind . '|' . $user_id . '|' . $email );
		if ( false === get_transient( $seen ) ) {
			set_transient( $seen, 1, 5 * MINUTE_IN_SECONDS );
			FS_Webhooks::emit(
				'checkout.blocked',
				array(
					'kind'       => $kind,
					'user_id'    => $user_id,
					'email_norm' => $email,
					'name'       => mb_substr( (string) $name, 0, 191 ),
					'detail'     => mb_substr( (string) $detail, 0, 255 ),
				)
			);
		}
	}

	/**
	 * Unresolved checkout blocks, newest activity first.
	 *
	 * @param int $limit  Per page.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function checkout_blocks_list( $limit = 200, $offset = 0 ) {
		global $wpdb;
		return self::decode_block_rows(
			$wpdb->get_results( // phpcs:ignore
				$wpdb->prepare( 'SELECT * FROM ' . self::checkout_blocks_table() . ' WHERE resolved = 0 ORDER BY last_at DESC, id DESC LIMIT %d OFFSET %d', (int) $limit, (int) $offset ),
				ARRAY_A
			)
		);
	}

	/**
	 * Every ledger row for one identity — resolved or not, newest first — so a
	 * customer's profile can show each time they were refused at checkout and
	 * exactly what matched, even after staff dismissed the Checkout Locks row.
	 *
	 * @param int    $user_id Account id (0 = guest).
	 * @param string $email   Normalized email.
	 * @param int    $limit   Rows.
	 * @return array
	 */
	public static function checkout_blocks_for_identity( $user_id, $email, $limit = 20 ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$email   = (string) $email;
		$where   = array();
		$params  = array();
		if ( $user_id > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}
		if ( '' !== $email ) {
			$where[]  = 'email = %s';
			$params[] = $email;
		}
		if ( empty( $where ) ) {
			return array();
		}
		$params[] = (int) $limit;
		return self::decode_block_rows(
			$wpdb->get_results( // phpcs:ignore
				$wpdb->prepare( 'SELECT * FROM ' . self::checkout_blocks_table() . ' WHERE ' . implode( ' OR ', $where ) . ' ORDER BY last_at DESC, id DESC LIMIT %d', $params ),
				ARRAY_A
			)
		);
	}

	/**
	 * WHERE fragment for the API's view of the checkout-block ledger.
	 *
	 * @param array $a      kind, email, user_id, resolved ('open' | 'resolved' | 'all').
	 * @param array $params Placeholders, appended.
	 * @return string Starts with ' WHERE '.
	 */
	private static function checkout_blocks_where( $a, &$params ) {
		$where    = array();
		$resolved = isset( $a['resolved'] ) ? (string) $a['resolved'] : 'open';
		if ( 'open' === $resolved ) {
			$where[] = 'resolved = 0';
		} elseif ( 'resolved' === $resolved ) {
			$where[] = 'resolved = 1';
		}
		if ( ! empty( $a['kind'] ) ) {
			$where[]  = 'kind = %s';
			$params[] = (string) $a['kind'];
		}
		if ( ! empty( $a['email'] ) ) {
			$where[]  = 'email = %s';
			$params[] = (string) $a['email'];
		}
		if ( ! empty( $a['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $a['user_id'];
		}
		return empty( $where ) ? ' WHERE 1=1' : ' WHERE ' . implode( ' AND ', $where );
	}

	/**
	 * A filtered page of the checkout-block ledger, newest activity first,
	 * with `evidence` decoded (v1.30, for the API).
	 *
	 * @param array $args kind, email, user_id, resolved, limit, offset.
	 * @return array
	 */
	public static function checkout_blocks_query( $args = array() ) {
		global $wpdb;
		$a = wp_parse_args(
			$args,
			array(
				'kind'     => '',
				'email'    => '',
				'user_id'  => 0,
				'resolved' => 'open',
				'limit'    => 50,
				'offset'   => 0,
			)
		);
		$params = array();
		$where  = self::checkout_blocks_where( $a, $params );
		array_push( $params, max( 1, min( 100, (int) $a['limit'] ) ), max( 0, (int) $a['offset'] ) );
		return self::decode_block_rows(
			$wpdb->get_results( // phpcs:ignore
				$wpdb->prepare( 'SELECT * FROM ' . self::checkout_blocks_table() . $where . ' ORDER BY last_at DESC, id DESC LIMIT %d OFFSET %d', $params ),
				ARRAY_A
			)
		);
	}

	/**
	 * How many ledger rows match the same filters.
	 *
	 * @param array $args See checkout_blocks_query().
	 * @return int
	 */
	public static function checkout_blocks_query_count( $args = array() ) {
		global $wpdb;
		$params = array();
		$where  = self::checkout_blocks_where( $args, $params );
		$sql    = 'SELECT COUNT(*) FROM ' . self::checkout_blocks_table() . $where;
		return empty( $params )
			? (int) $wpdb->get_var( $sql ) // phpcs:ignore
			: (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/**
	 * One ledger row by id, with `evidence` decoded, or null.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function checkout_block_row( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::checkout_blocks_table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return null;
		}
		$rows = self::decode_block_rows( array( $row ) );
		return $rows[0];
	}

	/**
	 * Decode each ledger row's JSON `evidence` into an array (empty when the
	 * row predates the column or an attempt carried none).
	 *
	 * @param array $rows Raw rows.
	 * @return array
	 */
	private static function decode_block_rows( $rows ) {
		$out = array();
		foreach ( (array) $rows as $r ) {
			$decoded       = isset( $r['evidence'] ) ? json_decode( (string) $r['evidence'], true ) : null;
			$r['evidence'] = is_array( $decoded ) ? $decoded : array();
			$out[]         = $r;
		}
		return $out;
	}

	/**
	 * Count of unresolved checkout blocks.
	 *
	 * @return int
	 */
	public static function checkout_blocks_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::checkout_blocks_table() . ' WHERE resolved = 0' ); // phpcs:ignore
	}

	/**
	 * Mark a single ledger row resolved (a manual "dismiss").
	 *
	 * @param int $id Row id.
	 */
	public static function resolve_checkout_block( $id ) {
		global $wpdb;
		$wpdb->update( self::checkout_blocks_table(), array( 'resolved' => 1 ), array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Resolve all unresolved ledger rows for an identity (used when CS verifies
	 * an account or allowlists an email, and when a velocity-blocked customer
	 * later completes a paid order).
	 *
	 * @param int    $user_id Account id.
	 * @param string $email   Normalized email.
	 * @param array  $kinds   Optional kind filter (empty = all kinds).
	 * @return int Rows resolved.
	 */
	public static function resolve_checkout_blocks( $user_id, $email, $kinds = array() ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$email   = (string) $email;
		$where   = array();
		$params  = array();

		if ( $user_id > 0 && '' !== $email ) {
			$where[]  = '(user_id = %d OR email = %s)';
			$params[] = $user_id;
			$params[] = $email;
		} elseif ( $user_id > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		} elseif ( '' !== $email ) {
			$where[]  = 'email = %s';
			$params[] = $email;
		} else {
			return 0;
		}

		$kinds = array_filter( array_map( 'strval', (array) $kinds ) );
		if ( ! empty( $kinds ) ) {
			$ph      = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
			$where[] = "kind IN ({$ph})";
			$params  = array_merge( $params, $kinds );
		}

		$sql = 'UPDATE ' . self::checkout_blocks_table() . ' SET resolved = 1 WHERE resolved = 0 AND ' . implode( ' AND ', $where );
		return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/* ----------------------------------------------------------------- *
	 *  Linked-identity auto-cancel ledger (v1.26)
	 * ----------------------------------------------------------------- */

	/**
	 * Record an order cancelled for sharing credentials with a blocked
	 * identity. One row per order; a re-run for the same order refreshes the
	 * evidence rather than duplicating. Non-fatal by design: a ledger failure
	 * must never break the checkout that triggered it.
	 *
	 * @param WC_Order $order   The cancelled order.
	 * @param array    $signals Its normalized signals.
	 * @param array    $links   Links from FS_Blocklist::linked().
	 * @param string   $summary One-line human summary of the links.
	 */
	public static function log_linked_cancel( $order, $signals, $links, $summary ) {
		global $wpdb;
		$t   = self::linked_cancels_table();
		$now = current_time( 'mysql' );
		$wpdb->query( // phpcs:ignore
			$wpdb->prepare(
				"INSERT INTO {$t} (order_id, user_id, email_norm, customer_name, order_total, currency, summary, links, attempts, cancelled_at, last_attempt_at)
				VALUES (%d, %d, %s, %s, %f, %s, %s, %s, 1, %s, %s)
				ON DUPLICATE KEY UPDATE summary = VALUES(summary), links = VALUES(links), customer_name = VALUES(customer_name)",
				(int) $order->get_id(),
				(int) ( $signals['user_id'] ?? 0 ),
				mb_substr( (string) ( $signals['email_norm'] ?? '' ), 0, 191 ),
				mb_substr( (string) ( $signals['customer_name'] ?? '' ), 0, 191 ),
				(float) $order->get_total(),
				mb_substr( (string) $order->get_currency(), 0, 8 ),
				mb_substr( (string) $summary, 0, 255 ),
				wp_json_encode( array_values( (array) $links ) ),
				$now,
				$now
			)
		);
	}

	/**
	 * The most recent auto-cancel row for an identity (account or email), or
	 * null. The gate reads this before doing any real work: a customer with no
	 * cancelled order behind them is never refused at the gate by this rule.
	 *
	 * @param int    $user_id    Account id (0 = guest).
	 * @param string $email_norm Normalized email.
	 * @return array|null
	 */
	public static function linked_cancel_for_identity( $user_id, $email_norm ) {
		global $wpdb;
		$user_id    = (int) $user_id;
		$email_norm = (string) $email_norm;
		$where      = array();
		$params     = array();
		if ( $user_id > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}
		if ( '' !== $email_norm ) {
			$where[]  = 'email_norm = %s';
			$params[] = $email_norm;
		}
		if ( empty( $where ) ) {
			return null;
		}
		$row = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare( 'SELECT * FROM ' . self::linked_cancels_table() . ' WHERE ' . implode( ' OR ', $where ) . ' ORDER BY cancelled_at DESC, id DESC LIMIT 1', $params ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Decode the `links` JSON on a ledger row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function decode_linked_row( $row ) {
		$decoded      = json_decode( (string) ( $row['links'] ?? '' ), true );
		$row['links'] = is_array( $decoded ) ? $decoded : array();
		return $row;
	}

	/**
	 * One auto-cancel ledger row by id, links decoded, or null (v1.30).
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function linked_cancel_row( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::linked_cancels_table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore
		return $row ? self::decode_linked_row( $row ) : null;
	}

	/**
	 * The auto-cancel ledger row for an order, links decoded, or null (v1.30).
	 *
	 * @param int $order_id Order id.
	 * @return array|null
	 */
	public static function linked_cancel_for_order( $order_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::linked_cancels_table() . ' WHERE order_id = %d', (int) $order_id ), ARRAY_A ); // phpcs:ignore
		return $row ? self::decode_linked_row( $row ) : null;
	}

	/**
	 * Count a checkout attempt refused at the gate against an existing
	 * auto-cancel row (the retry that did NOT become another cancelled order).
	 *
	 * @param int $id Ledger row id.
	 */
	public static function bump_linked_cancel_attempt( $id ) {
		global $wpdb;
		$wpdb->query( // phpcs:ignore
			$wpdb->prepare(
				'UPDATE ' . self::linked_cancels_table() . ' SET attempts = attempts + 1, last_attempt_at = %s WHERE id = %d',
				current_time( 'mysql' ),
				(int) $id
			)
		);
	}

	/**
	 * Search fragment for the auto-cancel ledger: email, name, or an order
	 * number (with or without a leading #).
	 *
	 * @param string $search  Free text.
	 * @param array  $params  Prepared-statement params (appended to).
	 * @return string WHERE fragment ('1=1' when no search).
	 */
	private static function linked_cancels_where( $search, &$params ) {
		global $wpdb;
		$search = trim( (string) $search );
		if ( '' === $search ) {
			return '1=1';
		}
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$parts  = array( 'email_norm LIKE %s', 'customer_name LIKE %s', 'summary LIKE %s' );
		$params = array_merge( $params, array( $like, $like, $like ) );
		$num    = ltrim( $search, '#' );
		if ( ctype_digit( $num ) ) {
			$parts[]  = 'order_id = %d';
			$params[] = (int) $num;
		}
		return '(' . implode( ' OR ', $parts ) . ')';
	}

	/**
	 * Number of auto-cancel rows matching a search.
	 *
	 * @param string $search Free text.
	 * @return int
	 */
	public static function linked_cancels_count( $search = '' ) {
		global $wpdb;
		$params = array();
		$where  = self::linked_cancels_where( $search, $params );
		$sql    = 'SELECT COUNT(*) FROM ' . self::linked_cancels_table() . ' WHERE ' . $where;
		return (int) ( empty( $params ) ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore
	}

	/**
	 * A page of auto-cancel rows, newest first, with `links` decoded.
	 *
	 * @param array $args { search, limit, offset }.
	 * @return array
	 */
	public static function linked_cancels_list( $args = array() ) {
		global $wpdb;
		$args   = wp_parse_args(
			$args,
			array(
				'search' => '',
				'limit'  => 50,
				'offset' => 0,
			)
		);
		$params = array();
		$where  = self::linked_cancels_where( $args['search'], $params );
		$params[] = (int) $args['limit'];
		$params[] = (int) $args['offset'];
		$rows     = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( 'SELECT * FROM ' . self::linked_cancels_table() . ' WHERE ' . $where . ' ORDER BY cancelled_at DESC, id DESC LIMIT %d OFFSET %d', $params ),
			ARRAY_A
		);
		$out      = array();
		foreach ( (array) $rows as $r ) {
			$decoded    = json_decode( (string) $r['links'], true );
			$r['links'] = is_array( $decoded ) ? $decoded : array();
			$out[]      = $r;
		}
		return $out;
	}

	/**
	 * Headline numbers for the Auto-Cancelled page.
	 *
	 * @return array { total:int, last_30d:int, value:float, retries:int, currency:string }
	 */
	public static function linked_cancel_stats() {
		global $wpdb;
		$t   = self::linked_cancels_table();
		$row = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare(
				"SELECT COUNT(*) total,
					SUM(CASE WHEN cancelled_at >= %s THEN 1 ELSE 0 END) last_30d,
					COALESCE(SUM(order_total), 0) value,
					COALESCE(SUM(attempts) - COUNT(*), 0) retries
				FROM {$t}",
				gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 30 * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		// Money only adds up inside one currency; report the one most rows use.
		$currency = (string) $wpdb->get_var( "SELECT currency FROM {$t} WHERE currency IS NOT NULL AND currency <> '' GROUP BY currency ORDER BY COUNT(*) DESC LIMIT 1" ); // phpcs:ignore
		return array(
			'total'    => (int) ( $row['total'] ?? 0 ),
			'last_30d' => (int) ( $row['last_30d'] ?? 0 ),
			'value'    => (float) ( $row['value'] ?? 0 ),
			'retries'  => max( 0, (int) ( $row['retries'] ?? 0 ) ),
			'currency' => $currency,
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Fraud-prevented ledger (v1.26): the money that did not change hands
	 * ----------------------------------------------------------------- */

	/**
	 * The controls that stop a checkout, in the order the Dashboard lists
	 * them, each in one of two groups:
	 *
	 *  - 'fraud'  — a verdict: the blocklist, the linked-identity rule, and a
	 *               block-level risk score under Enforce. Money these stop is
	 *               counted as fraud prevented.
	 *  - 'verify' — a question: the velocity lock, the new-account hold, a
	 *               staff-requested ID check and the high-value ID rule refuse
	 *               the attempt until the customer verifies. Many of them are
	 *               legitimate and will come back, so their money is shown as
	 *               "held pending verification", never added to the headline.
	 *
	 * Payment-method refusals are not recorded at all: the customer simply
	 * pays another way.
	 *
	 * @return array [ kind => { label:string, group:'fraud'|'verify' } ].
	 */
	public static function prevented_kinds() {
		return array(
			'blocklist'  => array(
				'label' => __( 'Blocklist', 'fraud-shield' ),
				'group' => 'fraud',
			),
			'linked'     => array(
				'label' => __( 'Linked to the blocklist', 'fraud-shield' ),
				'group' => 'fraud',
			),
			'score'      => array(
				'label' => __( 'Risk score at BLOCK (Enforce)', 'fraud-shield' ),
				'group' => 'fraud',
			),
			'velocity'   => array(
				'label' => __( 'Velocity lock', 'fraud-shield' ),
				'group' => 'verify',
			),
			'hold'       => array(
				'label' => __( 'New-account hold', 'fraud-shield' ),
				'group' => 'verify',
			),
			'idv'        => array(
				'label' => __( 'ID verification requested', 'fraud-shield' ),
				'group' => 'verify',
			),
			'high_value' => array(
				'label' => __( 'High-value ID rule', 'fraud-shield' ),
				'group' => 'verify',
			),
		);
	}

	/**
	 * SQL IN-list of the kinds in one group, quoted.
	 *
	 * @param string $group 'fraud' | 'verify'.
	 * @return string e.g. "'blocklist','linked','score'".
	 */
	private static function prevented_group_sql( $group ) {
		$kinds = array();
		foreach ( self::prevented_kinds() as $kind => $meta ) {
			if ( $meta['group'] === $group ) {
				$kinds[] = "'" . esc_sql( $kind ) . "'";
			}
		}
		return empty( $kinds ) ? "''" : implode( ',', $kinds );
	}

	/**
	 * How many identities a ledger row names behind a stop.
	 */
	const PREVENTED_LINKED_TO_MAX = 10;

	/**
	 * Normalize "whom the stop was tied to" for the savings ledger: a list
	 * of { email, user_id, name }, one entry per person, in the order given
	 * (blocked identities come first from the detectors), capped. Accepts the
	 * identity arrays FS_Blocklist attaches to links and to match evidence.
	 *
	 * @param array|null $identities Identity arrays (email, user_id, name, …).
	 * @return array Normalized list; empty when nothing usable was given.
	 */
	public static function prevented_identities( $identities ) {
		$out  = array();
		$seen = array();
		foreach ( (array) $identities as $id ) {
			if ( ! is_array( $id ) ) {
				continue;
			}
			$email = FS_Normalizer::email( (string) ( $id['email'] ?? '' ) );
			$uid   = (int) ( $id['user_id'] ?? 0 );
			$key   = '' !== $email ? 'e:' . $email : ( $uid > 0 ? 'u:' . $uid : '' );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'email'   => $email,
				'user_id' => $uid,
				'name'    => mb_substr( trim( (string) ( $id['name'] ?? '' ) ), 0, 191 ),
			);
			if ( count( $out ) >= self::PREVENTED_LINKED_TO_MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Decode a savings-ledger row's `linked_to` JSON into a list.
	 *
	 * @param array $row Raw row.
	 * @return array Row with `linked_to` as an array (possibly empty) and `stopped_by` set.
	 */
	public static function decode_prevented_row( $row ) {
		$decoded          = json_decode( (string) ( $row['linked_to'] ?? '' ), true );
		$row['linked_to'] = is_array( $decoded ) ? array_values( $decoded ) : array();
		if ( empty( $row['stopped_by'] ) ) {
			$row['stopped_by'] = 'rule';
		}
		return $row;
	}

	/**
	 * The savings-ledger row for a cancelled order, or null when the order
	 * was never counted. Decoded.
	 *
	 * @param int $order_id Order id.
	 * @return array|null
	 */
	public static function prevented_row_for_order( $order_id ) {
		global $wpdb;
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::prevented_table() . ' WHERE dedupe_key = %s', 'o:' . $order_id ), ARRAY_A ); // phpcs:ignore
		return $row ? self::decode_prevented_row( $row ) : null;
	}

	/**
	 * Record a stopped checkout and the money it carried.
	 *
	 * A cancelled order is keyed by its order id and counted once. A refused
	 * attempt (no order exists) is keyed by rule + identity + day: a retry
	 * bumps `attempts` and keeps the largest cart seen, so the figure counts
	 * the customer's intent once, not every click. Nothing is recorded when
	 * the amount is unknown or zero. Non-fatal by design.
	 *
	 * `linked_to` names the blocked identities the stop was tied to (the
	 * person behind the shared credential, or behind the matched blocklist
	 * entry). A later write that brings identities replaces what is stored;
	 * one that brings none leaves it alone. `stopped_by` is set on the first
	 * write and never changed by a retry.
	 *
	 * @param string     $kind       One of prevented_kinds().
	 * @param string     $stage      'cancelled' | 'refused'.
	 * @param float      $amount     The order / cart grand total.
	 * @param string     $currency   Currency code.
	 * @param int        $user_id    Account id (0 = guest).
	 * @param string     $email      Normalized email.
	 * @param string     $name       Best-effort customer name.
	 * @param int        $order_id   Order id (cancelled stage), 0 otherwise.
	 * @param array|null $linked_to  Identities the stop was tied to (see prevented_identities()).
	 * @param string     $stopped_by 'rule' (a control stopped it) | 'staff' (a person cancelled or refunded it).
	 */
	public static function log_prevented( $kind, $stage, $amount, $currency, $user_id, $email, $name = '', $order_id = 0, $linked_to = null, $stopped_by = 'rule' ) {
		global $wpdb;
		$kind       = sanitize_key( substr( (string) $kind, 0, 16 ) );
		$stage      = 'cancelled' === $stage ? 'cancelled' : 'refused';
		$amount     = round( (float) $amount, 2 );
		$order_id   = (int) $order_id;
		$user_id    = (int) $user_id;
		$email      = mb_substr( (string) $email, 0, 191 );
		$stopped_by = 'staff' === $stopped_by ? 'staff' : 'rule';
		$tied       = self::prevented_identities( $linked_to );
		$tied_json  = empty( $tied ) ? null : wp_json_encode( $tied );
		if ( '' === $kind || $amount <= 0 ) {
			return;
		}
		if ( 'cancelled' === $stage ) {
			if ( $order_id <= 0 ) {
				return;
			}
			$key = 'o:' . $order_id;
		} else {
			if ( $user_id <= 0 && '' === $email ) {
				return;
			}
			$key = 'r:' . $kind . ':' . $user_id . ':' . md5( $email ) . ':' . substr( current_time( 'mysql' ), 0, 10 );
		}
		$now = current_time( 'mysql' );
		$wpdb->query( // phpcs:ignore
			$wpdb->prepare(
				'INSERT INTO ' . self::prevented_table() . ' (dedupe_key, kind, stage, order_id, user_id, email_norm, customer_name, amount, currency, attempts, first_at, last_at, linked_to, stopped_by)
				VALUES (%s, %s, %s, %d, %d, %s, %s, %f, %s, 1, %s, %s, ' . ( null === $tied_json ? 'NULL' : '%s' ) . ', %s)
				ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_at = VALUES(last_at), amount = GREATEST(amount, VALUES(amount)), customer_name = VALUES(customer_name), linked_to = COALESCE(VALUES(linked_to), linked_to)',
				array_merge(
					array(
						$key,
						$kind,
						$stage,
						$order_id,
						$user_id,
						$email,
						mb_substr( (string) $name, 0, 191 ),
						$amount,
						mb_substr( (string) $currency, 0, 8 ),
						$now,
						$now,
					),
					null === $tied_json ? array() : array( $tied_json ),
					array( $stopped_by )
				)
			)
		);
	}

	/**
	 * One-time pass (v1.30): give the linked rule's older ledger rows their
	 * `linked_to` from the Auto-Cancelled ledger's links. Visits only rows
	 * whose value is still NULL; a row whose links name nobody stays NULL and
	 * costs one decode per run, which is nothing.
	 *
	 * @param int $limit Rows per run.
	 */
	public static function backfill_prevented_linked_to( $limit = 2000 ) {
		global $wpdb;
		if ( ! class_exists( 'FS_Blocklist' ) ) {
			return;
		}
		$p    = self::prevented_table();
		$l    = self::linked_cancels_table();
		$rows = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT p.id, l.links FROM {$p} p INNER JOIN {$l} l ON l.order_id = p.order_id
				WHERE p.kind = 'linked' AND p.stage = 'cancelled' AND p.order_id > 0 AND p.linked_to IS NULL AND l.links IS NOT NULL AND l.links <> ''
				LIMIT %d",
				(int) $limit
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$links = json_decode( (string) $r['links'], true );
			if ( ! is_array( $links ) ) {
				continue;
			}
			$tied = self::prevented_identities( FS_Blocklist::link_identities( $links ) );
			if ( empty( $tied ) ) {
				continue;
			}
			$wpdb->update( $p, array( 'linked_to' => wp_json_encode( $tied ) ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore
		}
	}

	/**
	 * Local-time lower bound for "the last N days" ('' = all time).
	 *
	 * @param int $days Days back (0 = all time).
	 * @return string
	 */
	private static function since_sql( $days ) {
		$days = (int) $days;
		if ( $days <= 0 ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $days * DAY_IN_SECONDS );
	}

	/**
	 * Headline savings for a window.
	 *
	 * @param int $days Window in days (0 = all time).
	 * @return array {
	 *     fraud_amount:float   money stopped by a verdict (blocklist, linked, block score) — the headline,
	 *     verify_amount:float  money held back pending verification (velocity, hold, ID rules),
	 *     fraud_stopped:int    verdict rows (cancelled orders + refused attempts),
	 *     cancelled:int        orders cancelled, all kinds,
	 *     refused:int          attempts refused before an order existed, all kinds,
	 *     attempts:int         every attempt including retries, all kinds,
	 *     staff_stopped:int    verdict rows where a person cancelled or refunded the order (v1.30),
	 *     staff_amount:float   the money on those rows (already inside fraud_amount),
	 *     currency:string
	 * }
	 */
	public static function prevented_stats( $days = 0 ) {
		global $wpdb;
		$t      = self::prevented_table();
		$since  = self::since_sql( $days );
		$where  = '' === $since ? '1=1' : $wpdb->prepare( 'first_at >= %s', $since );
		$fraud  = self::prevented_group_sql( 'fraud' );
		$verify = self::prevented_group_sql( 'verify' );
		$row    = $wpdb->get_row( // phpcs:ignore
			"SELECT COALESCE(SUM(CASE WHEN kind IN ({$fraud}) THEN amount ELSE 0 END), 0) fraud_amount,
				COALESCE(SUM(CASE WHEN kind IN ({$verify}) THEN amount ELSE 0 END), 0) verify_amount,
				SUM(CASE WHEN kind IN ({$fraud}) THEN 1 ELSE 0 END) fraud_stopped,
				SUM(CASE WHEN stage = 'cancelled' THEN 1 ELSE 0 END) cancelled,
				SUM(CASE WHEN stage = 'refused' THEN 1 ELSE 0 END) refused,
				COALESCE(SUM(attempts), 0) attempts,
				SUM(CASE WHEN kind IN ({$fraud}) AND stopped_by = 'staff' THEN 1 ELSE 0 END) staff_stopped,
				COALESCE(SUM(CASE WHEN kind IN ({$fraud}) AND stopped_by = 'staff' THEN amount ELSE 0 END), 0) staff_amount
			FROM {$t} WHERE {$where}",
			ARRAY_A
		);
		$currency = (string) $wpdb->get_var( "SELECT currency FROM {$t} WHERE currency IS NOT NULL AND currency <> '' GROUP BY currency ORDER BY COUNT(*) DESC LIMIT 1" ); // phpcs:ignore
		return array(
			'fraud_amount'  => (float) ( $row['fraud_amount'] ?? 0 ),
			'verify_amount' => (float) ( $row['verify_amount'] ?? 0 ),
			'fraud_stopped' => (int) ( $row['fraud_stopped'] ?? 0 ),
			'cancelled'     => (int) ( $row['cancelled'] ?? 0 ),
			'refused'       => (int) ( $row['refused'] ?? 0 ),
			'attempts'      => (int) ( $row['attempts'] ?? 0 ),
			'staff_stopped' => (int) ( $row['staff_stopped'] ?? 0 ),
			'staff_amount'  => (float) ( $row['staff_amount'] ?? 0 ),
			'currency'      => $currency,
		);
	}

	/**
	 * Savings broken down by the control that stopped the checkout.
	 *
	 * @param int $days Window in days (0 = all time).
	 * @return array [ kind => { amount:float, stopped:int, attempts:int } ], every known kind present.
	 */
	public static function prevented_by_kind( $days = 0 ) {
		global $wpdb;
		$t     = self::prevented_table();
		$since = self::since_sql( $days );
		$where = '' === $since ? '1=1' : $wpdb->prepare( 'first_at >= %s', $since );
		$rows  = $wpdb->get_results( "SELECT kind, COALESCE(SUM(amount), 0) amount, COUNT(*) stopped, COALESCE(SUM(attempts), 0) attempts FROM {$t} WHERE {$where} GROUP BY kind", ARRAY_A ); // phpcs:ignore
		$out   = array();
		foreach ( array_keys( self::prevented_kinds() ) as $kind ) {
			$out[ $kind ] = array(
				'amount'   => 0.0,
				'stopped'  => 0,
				'attempts' => 0,
			);
		}
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['kind'] ] = array(
				'amount'   => (float) $r['amount'],
				'stopped'  => (int) $r['stopped'],
				'attempts' => (int) $r['attempts'],
			);
		}
		return $out;
	}

	/**
	 * Savings per calendar month for the last N months, oldest first, with
	 * empty months filled in so a chart has a bar for each.
	 *
	 * @param int $months Months back, including the current one.
	 * @return array [ 'YYYY-MM' => { fraud:float, verify:float, stopped:int } ].
	 */
	public static function prevented_monthly( $months = 12 ) {
		global $wpdb;
		$months = max( 1, (int) $months );
		$t      = self::prevented_table();
		$fraud  = self::prevented_group_sql( 'fraud' );
		$verify = self::prevented_group_sql( 'verify' );
		$now    = strtotime( current_time( 'mysql' ) );
		$first  = strtotime( gmdate( 'Y-m-01', $now ) );
		$start  = gmdate( 'Y-m-01 00:00:00', strtotime( '-' . ( $months - 1 ) . ' months', $first ) );
		$rows   = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT DATE_FORMAT(first_at, '%%Y-%%m') ym,
					COALESCE(SUM(CASE WHEN kind IN ({$fraud}) THEN amount ELSE 0 END), 0) fraud,
					COALESCE(SUM(CASE WHEN kind IN ({$verify}) THEN amount ELSE 0 END), 0) verify,
					COUNT(*) stopped
				FROM {$t} WHERE first_at >= %s GROUP BY ym",
				$start
			),
			ARRAY_A
		);
		$by_month = array();
		foreach ( (array) $rows as $r ) {
			$by_month[ (string) $r['ym'] ] = array(
				'fraud'   => (float) $r['fraud'],
				'verify'  => (float) $r['verify'],
				'stopped' => (int) $r['stopped'],
			);
		}
		$out = array();
		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$ym         = gmdate( 'Y-m', strtotime( '-' . $i . ' months', $first ) );
			$out[ $ym ] = isset( $by_month[ $ym ] ) ? $by_month[ $ym ] : array(
				'fraud'   => 0.0,
				'verify'  => 0.0,
				'stopped' => 0,
			);
		}
		return $out;
	}

	/**
	 * The most recent stopped checkouts, for the Dashboard's savings panel.
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function prevented_recent( $limit = 10 ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::prevented_table() . ' ORDER BY last_at DESC, id DESC LIMIT %d', (int) $limit ), ARRAY_A ); // phpcs:ignore
		return array_map( array( __CLASS__, 'decode_prevented_row' ), $rows );
	}

	/**
	 * WHERE fragment + params for a geography drill-down at a given level.
	 *
	 * @param string $level   'state' | 'city' | 'zip'.
	 * @param string $country Country code.
	 * @param string $state   State/region.
	 * @param string $key     City (city level) or postcode (zip level); ignored for state.
	 * @return array [ where_sql, params[] ]
	 */
	private static function geo_where( $level, $country, $state, $key, $city = '' ) {
		$where  = 'shipping_country = %s AND ship_state = %s';
		$params = array( strtoupper( trim( (string) $country ) ), FS_Normalizer::region( $state ) );
		if ( 'city' === $level ) {
			$where   .= ' AND ship_city = %s';
			$params[] = FS_Normalizer::city( $key );
		} elseif ( 'zip' === $level ) {
			$where   .= ' AND ship_postcode = %s';
			$params[] = FS_Normalizer::postcode( $key );
		} elseif ( 'street' === $level ) {
			// $key is the normalized street; the city rides in separately.
			$where   .= ' AND ship_city = %s AND ship_street = %s';
			$params[] = FS_Normalizer::city( $city );
			$params[] = mb_substr( FS_Normalizer::canonicalize( $key ), 0, 100 );
		}
		return array( $where, $params );
	}

	/**
	 * Orders, customers and distinct addresses on one street — what a merchant
	 * is about to lock out. Drives the confirm dialog on the address page.
	 *
	 * @param string $country Country code.
	 * @param string $state   State/region.
	 * @param string $city    City.
	 * @param string $street  Normalized street name.
	 * @return array { orders:int, customers:int, addresses:int }
	 */
	public static function street_stats( $country, $state, $city, $street ) {
		global $wpdb;
		list( $where, $params ) = self::geo_where( 'street', $country, $state, $street, $city );
		$row = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare(
				'SELECT COUNT(*) orders, COUNT(DISTINCT email_norm) customers, COUNT(DISTINCT ship_hash) addresses FROM ' . self::index_table() . " WHERE {$where}",
				$params
			),
			ARRAY_A
		);
		return array(
			'orders'    => (int) ( $row['orders'] ?? 0 ),
			'customers' => (int) ( $row['customers'] ?? 0 ),
			'addresses' => (int) ( $row['addresses'] ?? 0 ),
		);
	}

	/**
	 * Every city name and ZIP the index has seen for one street in one state —
	 * the boundary a street lock is pre-filled with (v1.31). For "NW 66th St,
	 * FL" after the incident that is {miami, doral} and {33166, 33167, 33172,
	 * 33195}: every destination the road was typed under, including the
	 * rotated ZIPs, each of which becomes one more key under the lock's card.
	 * Staff can add or remove from the list before confirming.
	 *
	 * @param string $country Country code.
	 * @param string $state   State/region.
	 * @param string $street  Normalized street name.
	 * @return array { cities: string[], zips: string[] }
	 */
	public static function street_boundaries( $country, $state, $street ) {
		global $wpdb;
		$cc = strtoupper( trim( (string) $country ) );
		$st = FS_Normalizer::region( $state );
		$sn = FS_Normalizer::align_street( $street );
		$out = array(
			'cities' => array(),
			'zips'   => array(),
		);
		if ( '' === $cc || '' === $st || '' === $sn ) {
			return $out;
		}
		$rows = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				'SELECT DISTINCT ship_city, ship_postcode FROM ' . self::index_table() . " WHERE shipping_country = %s AND ship_state = %s AND ship_street = %s AND ship_street <> ''",
				$cc,
				$st,
				$sn
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$c = FS_Normalizer::city( (string) $r['ship_city'] );
			$z = FS_Normalizer::zip_base( $cc, (string) $r['ship_postcode'] );
			if ( '' !== $c ) {
				$out['cities'][ $c ] = true;
			}
			if ( '' !== $z ) {
				$out['zips'][ $z ] = true;
			}
		}
		// Keys, not values, so each is listed once — cast back to strings
		// because PHP stores a numeric string key ("33166") as an integer.
		$out['cities'] = array_map( 'strval', array_keys( $out['cities'] ) );
		$out['zips']   = array_map( 'strval', array_keys( $out['zips'] ) );
		sort( $out['cities'], SORT_STRING );
		sort( $out['zips'], SORT_STRING );
		return $out;
	}

	/**
	 * Per-identity (email) customer rollup for every customer shipping to a
	 * given destination. Same shape as profiles_list(), scoped by geography.
	 *
	 * @param string $level   Level.
	 * @param string $country Country.
	 * @param string $state   State.
	 * @param string $key     City / postcode / normalized street (level-dependent).
	 * @param array  $args    orderby, dir, search, limit, offset, city (street level).
	 * @return array
	 */
	public static function geo_customers( $level, $country, $state, $key, $args = array() ) {
		global $wpdb;
		$a = wp_parse_args(
			$args,
			array(
				'orderby' => 'last',
				'dir'     => 'desc',
				'search'  => '',
				'limit'   => 50,
				'offset'  => 0,
				'city'    => '',
			)
		);
		$t    = self::index_table();
		$paid = self::paid_in_sql();

		list( $where, $params ) = self::geo_where( $level, $country, $state, $key, $a['city'] );
		$search_sql             = self::profile_search_sql( $a['search'], $params );

		$sort_map = array(
			'email'       => 'email_norm',
			'name'        => 'customer_name',
			'orders'      => 'orders',
			'paid'        => 'paid_orders',
			'spend'       => 'spend',
			'chargebacks' => 'chargebacks',
			'ips'         => 'ips',
			'addresses'   => 'addresses',
			'risk'        => 'max_risk',
			'first'       => 'first_order',
			'last'        => 'last_order',
		);
		$orderby = isset( $sort_map[ $a['orderby'] ] ) ? $sort_map[ $a['orderby'] ] : 'last_order';
		$dir     = self::dir_sql( $a['dir'] );
		array_push( $params, (int) $a['limit'], (int) $a['offset'] );

		$sql = "SELECT
				email_norm,
				MAX(customer_name) customer_name,
				MAX(user_id) user_id,
				COUNT(*) orders,
				SUM(CASE WHEN status IN ({$paid}) THEN 1 ELSE 0 END) paid_orders,
				COALESCE(SUM(CASE WHEN status IN ({$paid}) THEN order_total ELSE 0 END),0) spend,
				COALESCE(SUM(is_chargeback),0) chargebacks,
				COUNT(DISTINCT ip) ips,
				COUNT(DISTINCT ship_hash) addresses,
				MAX(risk_score) max_risk,
				MIN(order_date) first_order,
				MAX(order_date) last_order
			FROM {$t}
			WHERE {$where}{$search_sql} AND email_norm <> ''
			GROUP BY email_norm
			ORDER BY {$orderby} {$dir}, last_order DESC
			LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Distinct customers shipping to a destination (for pagination).
	 *
	 * @param string $level   Level.
	 * @param string $country Country.
	 * @param string $state   State.
	 * @param string $key     City / postcode / normalized street.
	 * @param string $search  Free-text search.
	 * @param string $city    City (street level only).
	 * @return int
	 */
	public static function geo_customers_count( $level, $country, $state, $key, $search = '', $city = '' ) {
		global $wpdb;
		$t                      = self::index_table();
		list( $where, $params ) = self::geo_where( $level, $country, $state, $key, $city );
		$search_sql             = self::profile_search_sql( $search, $params );
		$sql                    = "SELECT COUNT(DISTINCT email_norm) FROM {$t} WHERE {$where}{$search_sql} AND email_norm <> ''";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	/**
	 * WHERE fragment for the generic index queries the API exposes.
	 *
	 * Every filter lands on an indexed column (risk_level, email_norm,
	 * is_chargeback, order_date), because an API filter is a filter a stranger
	 * chooses and a table scan is a stranger choosing to slow the store down.
	 *
	 * @param array $a       Filters: level, email, chargeback, from, to.
	 * @param array $params  Collected placeholders, by reference.
	 * @return string
	 */
	private static function index_where( $a, &$params ) {
		$where = ' WHERE 1=1';

		$levels = array( 'none', 'low', 'medium', 'high', 'block' );
		if ( ! empty( $a['level'] ) && in_array( $a['level'], $levels, true ) ) {
			$where   .= ' AND risk_level = %s';
			$params[] = (string) $a['level'];
		}
		if ( ! empty( $a['email'] ) ) {
			$where   .= ' AND email_norm = %s';
			$params[] = (string) $a['email'];
		}
		if ( isset( $a['chargeback'] ) && null !== $a['chargeback'] ) {
			$where   .= ' AND is_chargeback = %d';
			$params[] = ! empty( $a['chargeback'] ) ? 1 : 0;
		}

		// v1.30: the other indexed columns an integrator correlates on. Every
		// one lands on an index (user_id, ip, ship_hash, phone_norm, the
		// ship_geo composite, ship_postcode) or on a short VARCHAR equality.
		if ( ! empty( $a['user_id'] ) ) {
			$where   .= ' AND user_id = %d';
			$params[] = (int) $a['user_id'];
		}
		if ( ! empty( $a['ip'] ) ) {
			$where   .= ' AND ip = %s';
			$params[] = (string) $a['ip'];
		}
		if ( ! empty( $a['address_id'] ) ) {
			$where   .= ' AND ship_hash = %s';
			$params[] = (string) $a['address_id'];
		}
		if ( ! empty( $a['phone'] ) ) {
			$where   .= ' AND phone_norm = %s';
			$params[] = (string) $a['phone'];
		}
		if ( ! empty( $a['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = (string) $a['status'];
		}
		if ( ! empty( $a['gateway'] ) ) {
			$where   .= ' AND payment_method = %s';
			$params[] = (string) $a['gateway'];
		}
		if ( ! empty( $a['country'] ) ) {
			$where   .= ' AND shipping_country = %s';
			$params[] = strtoupper( (string) $a['country'] );
		}
		if ( ! empty( $a['state'] ) ) {
			$where   .= ' AND ship_state = %s';
			$params[] = FS_Normalizer::region( (string) $a['state'] );
		}
		if ( ! empty( $a['city'] ) ) {
			$where   .= ' AND ship_city = %s';
			$params[] = FS_Normalizer::city( (string) $a['city'] );
		}
		if ( ! empty( $a['postcode'] ) ) {
			$where   .= ' AND ship_postcode = %s';
			$params[] = FS_Normalizer::postcode( (string) $a['postcode'] );
		}
		if ( isset( $a['min_score'] ) && null !== $a['min_score'] && (int) $a['min_score'] > 0 ) {
			$where   .= ' AND risk_score >= %d';
			$params[] = (int) $a['min_score'];
		}
		if ( ! empty( $a['linked_cancelled'] ) ) {
			$where .= ' AND order_id IN (SELECT order_id FROM ' . self::linked_cancels_table() . ')';
		}
		if ( ! empty( $a['from'] ) ) {
			$from = self::api_date_bound( $a['from'] );
			if ( '' !== $from ) {
				$where   .= ' AND order_date >= %s';
				$params[] = $from;
			}
		}
		if ( ! empty( $a['to'] ) ) {
			$to = self::api_date_bound( $a['to'] );
			if ( '' !== $to ) {
				$where   .= ' AND order_date <= %s';
				$params[] = $to;
			}
		}

		return $where;
	}

	/**
	 * Turn an API date filter into the wall clock `order_date` actually stores.
	 *
	 * order_date is SITE-LOCAL (upsert_index writes $order->get_date_created()
	 * unconverted), so a bound has to be a local wall clock too. That makes the
	 * two shapes of input behave differently:
	 *
	 * - "2026-08-01" or "2026-08-01 13:45:00" carries no zone, so the caller
	 *   means that wall clock in the store's own time. It is parsed as UTC and
	 *   rendered back as UTC, which returns the same digits it arrived as — a
	 *   deliberate no-op, and the reason ' UTC' is pinned onto the string rather
	 *   than trusting whatever timezone PHP happens to be running in.
	 * - "2026-08-01T00:00:00-04:00" names a real instant, so it has to be
	 *   converted into the store's zone. Without that the filter is skewed by the
	 *   UTC offset, and feeding this endpoint's own ISO output straight back into
	 *   it would silently lose or gain that many hours of orders.
	 *
	 * Accepts ISO 8601 and bare dates. A named timezone ("… Europe/Paris") is not
	 * recognised as zoned and will fail to parse, which yields no bound rather
	 * than a wrong one.
	 *
	 * @param string $raw Caller-supplied date.
	 * @return string 'Y-m-d H:i:s' in site-local time, or '' when unparseable.
	 */
	public static function api_date_bound( $raw ) {
		$raw   = trim( (string) $raw );
		$zoned = (bool) preg_match( '/(?:Z|[+-]\d{2}:?\d{2})$/i', $raw );

		$ts = strtotime( $zoned ? $raw : $raw . ' UTC' );
		if ( false === $ts ) {
			return '';
		}

		$utc = gmdate( 'Y-m-d H:i:s', $ts );
		return $zoned ? get_date_from_gmt( $utc ) : $utc;
	}

	/**
	 * Indexed orders matching the API's filters, newest first.
	 *
	 * @param array $args level, email, chargeback, user_id, ip, address_id, phone, status, gateway,
	 *                    country, state, city, postcode, min_score, linked_cancelled, from, to, limit, offset.
	 * @return array[]
	 */
	public static function index_list( $args = array() ) {
		global $wpdb;
		$a = wp_parse_args(
			$args,
			array(
				'level'            => '',
				'email'            => '',
				'chargeback'       => null,
				'user_id'          => 0,
				'ip'               => '',
				'address_id'       => '',
				'phone'            => '',
				'status'           => '',
				'gateway'          => '',
				'country'          => '',
				'state'            => '',
				'city'             => '',
				'postcode'         => '',
				'min_score'        => null,
				'linked_cancelled' => false,
				'from'             => '',
				'to'               => '',
				'limit'            => 50,
				'offset'           => 0,
			)
		);

		$t      = self::index_table();
		$params = array();
		$where  = self::index_where( $a, $params );
		array_push( $params, max( 1, min( 100, (int) $a['limit'] ) ), max( 0, (int) $a['offset'] ) );

		$sql  = "SELECT * FROM {$t}{$where} ORDER BY order_date DESC, id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many indexed orders match the same filters.
	 *
	 * @param array $args See index_list().
	 * @return int
	 */
	public static function index_list_count( $args = array() ) {
		global $wpdb;
		$t      = self::index_table();
		$params = array();
		$where  = self::index_where( $args, $params );

		$sql = "SELECT COUNT(*) FROM {$t}{$where}";
		return empty( $params )
			? (int) $wpdb->get_var( $sql ) // phpcs:ignore WordPress.DB
			: (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * One indexed order.
	 *
	 * @param int $order_id Order id.
	 * @return array|null
	 */
	public static function index_row( $order_id ) {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT * FROM ' . self::index_table() . ' WHERE order_id = %d', (int) $order_id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Aggregate stats for the dashboard header.
	 *
	 * @return array
	 */
	public static function summary_stats() {
		global $wpdb;
		$t = self::index_table();
		return array(
			'indexed'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ), // phpcs:ignore
			'chargebacks' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE is_chargeback=1" ), // phpcs:ignore
			'high'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE risk_level IN ('high','block')" ), // phpcs:ignore
			'medium'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE risk_level='medium'" ), // phpcs:ignore
			'blocklist'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::blocklist_table() ), // phpcs:ignore
		);
	}

	/* ----------------------------------------------------------------- *
	 *  High-value order review (v1.32)
	 *
	 *  The queue is a VIEW: every indexed order at or above the threshold,
	 *  placed inside a window, joined to its latest verdict. Nothing has to
	 *  be written when an order arrives, a change of threshold applies at
	 *  once, and orders placed before this version are on the same footing
	 *  as new ones. Only the verdicts are stored.
	 * ----------------------------------------------------------------- */

	/**
	 * The verdicts a reviewer can record, in the order the buttons appear.
	 *
	 * 'cleared'   — looks good; the order is left as it is.
	 * 'held'      — something is off; the order is set On-Hold so nothing
	 *               ships while it is looked into.
	 * 'cancelled' — cancel now; the order is cancelled in WooCommerce (the
	 *               payment is NOT refunded by this — that stays a decision
	 *               on the order screen).
	 *
	 * @return array [ verdict => { label:string, short:string, pill:string } ].
	 */
	public static function hv_verdicts() {
		return array(
			'cleared'   => array(
				'label' => __( 'Looks good', 'fraud-shield' ),
				'short' => __( 'Looks good', 'fraud-shield' ),
				'pill'  => 'fs-pill-green',
			),
			'held'      => array(
				'label' => __( 'Something off — hold package', 'fraud-shield' ),
				'short' => __( 'Held', 'fraud-shield' ),
				'pill'  => 'fs-pill-amber',
			),
			'cancelled' => array(
				'label' => __( 'Cancel now', 'fraud-shield' ),
				'short' => __( 'Cancelled', 'fraud-shield' ),
				'pill'  => 'fs-pill-black',
			),
		);
	}

	/**
	 * The windows the review page offers.
	 *
	 * @return array [ key => label ].
	 */
	public static function hv_windows() {
		return array(
			'today'     => __( 'Today', 'fraud-shield' ),
			'yesterday' => __( 'Yesterday', 'fraud-shield' ),
			'week'      => __( 'This week', 'fraud-shield' ),
			'7d'        => __( 'Last 7 days', 'fraud-shield' ),
			'30d'       => __( 'Last 30 days', 'fraud-shield' ),
			'90d'       => __( 'Last 90 days', 'fraud-shield' ),
		);
	}

	/**
	 * Bounds of a review window as site-local wall-clock datetimes — the
	 * clock `order_date` and `reviewed_at` keep — half-open: [from, to).
	 * "Today" is the site's calendar day, not a rolling 24 hours; "this
	 * week" starts on the day WordPress' "Week Starts On" setting names and
	 * runs to the end of today; "last 7 days" is today and the six before it.
	 *
	 * @param string   $key Window key (see hv_windows()); unknown reads as today.
	 * @param int|null $now Site-local timestamp to anchor on (tests); null = now.
	 * @return array { key:string, from:string, to:string, label:string }
	 */
	public static function hv_window( $key, $now = null ) {
		$windows = self::hv_windows();
		$key     = isset( $windows[ $key ] ) ? $key : 'today';
		// current_time('timestamp') is WordPress' site-local epoch (UTC plus
		// the offset), so gmdate() on it yields the local wall clock.
		$now = null === $now ? (int) current_time( 'timestamp' ) : (int) $now;
		$sow = (int) get_option( 'start_of_week', 1 );
		$mid = (int) strtotime( gmdate( 'Y-m-d', $now ) . ' 00:00:00 UTC' ); // local midnight, same convention.
		$to  = $mid + DAY_IN_SECONDS; // end of today, exclusive.
		switch ( $key ) {
			case 'yesterday':
				$from = $mid - DAY_IN_SECONDS;
				$to   = $mid;
				break;
			case 'week':
				$back = ( (int) gmdate( 'w', $now ) - $sow + 7 ) % 7;
				$from = $mid - $back * DAY_IN_SECONDS;
				break;
			case '7d':
				$from = $mid - 6 * DAY_IN_SECONDS;
				break;
			case '30d':
				$from = $mid - 29 * DAY_IN_SECONDS;
				break;
			case '90d':
				$from = $mid - 89 * DAY_IN_SECONDS;
				break;
			default:
				$from = $mid;
		}
		return array(
			'key'   => $key,
			'from'  => gmdate( 'Y-m-d H:i:s', $from ),
			'to'    => gmdate( 'Y-m-d H:i:s', $to ),
			'label' => $windows[ $key ],
		);
	}

	/**
	 * Order statuses under which nothing will ship, so an unreviewed order
	 * needs no review: refused, reversed, or never real. A REVIEWED order is
	 * shown whatever its status — its outcome is the point.
	 *
	 * @return array
	 */
	public static function hv_dead_statuses() {
		return array( 'cancelled', 'failed', 'refunded', 'charged-back', 'checkout-draft', 'auto-draft', 'draft', 'trash' );
	}

	/**
	 * SQL for "this index row is a live order": not under a dead status, and
	 * not recorded as charged back (a chargeback is a loss on the books,
	 * whatever status the order was left in — there is nothing to review).
	 * A NULL status (a CSV-imported row) is treated as live.
	 *
	 * @param array $params Params (the dead statuses are appended).
	 * @return string SQL fragment in parentheses, over alias i.
	 */
	private static function hv_live_sql( &$params ) {
		$dead   = self::hv_dead_statuses();
		$params = array_merge( $params, $dead );
		return '(i.is_chargeback = 0 AND (i.status IS NULL OR i.status NOT IN (' . implode( ',', array_fill( 0, count( $dead ), '%s' ) ) . ')))';
	}

	/**
	 * FROM clause of the queue: the index (alias i) joined to each order's
	 * latest verdict (alias r) and its review count (alias l).
	 *
	 * @return string
	 */
	private static function hv_queue_from() {
		$i = self::index_table();
		$r = self::hv_reviews_table();
		return " FROM {$i} i
			LEFT JOIN (SELECT order_id, MAX(id) AS max_id, COUNT(*) AS n FROM {$r} GROUP BY order_id) l ON l.order_id = i.order_id
			LEFT JOIN {$r} r ON r.id = l.max_id";
	}

	/**
	 * WHERE fragment of the queue.
	 *
	 * @param array $a      amount, from, to, show ('unreviewed'|'reviewed'|'all'), include_dead, search.
	 * @param array $params Prepared-statement params (appended to).
	 * @return string SQL starting with ' WHERE '.
	 */
	private static function hv_queue_where( $a, &$params ) {
		global $wpdb;
		$where    = ' WHERE i.order_total >= %f';
		$params[] = (float) $a['amount'];
		if ( '' !== (string) $a['from'] ) {
			$where   .= ' AND i.order_date >= %s';
			$params[] = (string) $a['from'];
		}
		if ( '' !== (string) $a['to'] ) {
			$where   .= ' AND i.order_date < %s';
			$params[] = (string) $a['to'];
		}
		if ( empty( $a['include_dead'] ) ) {
			$where .= ' AND (r.id IS NOT NULL OR ' . self::hv_live_sql( $params ) . ')';
		}
		if ( 'unreviewed' === $a['show'] ) {
			$where .= ' AND r.id IS NULL';
		} elseif ( 'reviewed' === $a['show'] ) {
			$where .= ' AND r.id IS NOT NULL';
		}
		$search = trim( (string) $a['search'] );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$parts  = array( 'i.email_norm LIKE %s', 'i.customer_name LIKE %s', 'i.ship_text LIKE %s', 'i.ip LIKE %s', 'i.phone_norm LIKE %s' );
			$params = array_merge( $params, array( $like, $like, $like, $like, $like ) );
			$num    = ltrim( $search, '#' );
			if ( ctype_digit( $num ) ) {
				$parts[]  = 'i.order_id = %d';
				$params[] = (int) $num;
			}
			$where .= ' AND (' . implode( ' OR ', $parts ) . ')';
		}
		return $where;
	}

	/**
	 * Defaults shared by the queue list and count.
	 *
	 * @param array $args Caller args.
	 * @return array
	 */
	private static function hv_queue_args( $args ) {
		return wp_parse_args(
			$args,
			array(
				'amount'       => 0,
				'from'         => '',
				'to'           => '',
				'show'         => 'all',
				'include_dead' => false,
				'search'       => '',
				'orderby'      => 'date',
				'dir'          => 'desc',
				'limit'        => 50,
				'offset'       => 0,
			)
		);
	}

	/**
	 * A page of the queue: index rows with the latest verdict beside each
	 * (review_id, verdict, review_note, reviewer_id, reviewer_name,
	 * reviewed_at, review_source, review_count — all empty/0 when unreviewed).
	 *
	 * @param array $args See hv_queue_args(); orderby ∈ date|total|risk|reviewed.
	 * @return array[]
	 */
	public static function hv_queue_list( $args = array() ) {
		global $wpdb;
		$a      = self::hv_queue_args( $args );
		$params = array();
		$where  = self::hv_queue_where( $a, $params );
		$cols   = array(
			'date'     => 'i.order_date',
			'total'    => 'i.order_total',
			'risk'     => 'i.risk_score',
			'reviewed' => 'r.reviewed_at',
		);
		$col    = $cols[ $a['orderby'] ] ?? 'i.order_date';
		$dir    = 'asc' === strtolower( (string) $a['dir'] ) ? 'ASC' : 'DESC';
		array_push( $params, max( 1, min( 500, (int) $a['limit'] ) ), max( 0, (int) $a['offset'] ) );

		$sql  = 'SELECT i.*, r.id AS review_id, r.verdict, r.note AS review_note, r.reviewer_id, r.reviewer_name, r.reviewed_at, r.source AS review_source, COALESCE(l.n, 0) AS review_count'
			. self::hv_queue_from() . $where . " ORDER BY {$col} {$dir}, i.id {$dir} LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many queue rows match.
	 *
	 * @param array $args See hv_queue_list().
	 * @return int
	 */
	public static function hv_queue_count( $args = array() ) {
		global $wpdb;
		$a      = self::hv_queue_args( $args );
		$params = array();
		$where  = self::hv_queue_where( $a, $params );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . self::hv_queue_from() . $where, $params ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Headline numbers for one window: how many high-value orders were
	 * placed, how many still await a look, the verdicts so far, the money —
	 * plus the backlog: unreviewed live orders OLDER than the window (within
	 * the 90 days before it, so the query stays on the date index) and the
	 * oldest of them, so a stale queue is visible from any tab.
	 *
	 * @param float $amount Threshold.
	 * @param array $win    hv_window() result.
	 * @return array { total, unreviewed, reviewed, cleared, held, cancelled, value, unreviewed_value, backlog, oldest_unreviewed, currency }
	 */
	public static function hv_queue_stats( $amount, $win ) {
		global $wpdb;

		$params = array( 'cleared', 'held', 'cancelled', (float) $amount, $win['from'], $win['to'] );
		$live   = self::hv_live_sql( $params );
		$row    = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT COUNT(*) total,
					SUM(CASE WHEN r.id IS NULL THEN 1 ELSE 0 END) unreviewed,
					SUM(CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END) reviewed,
					SUM(CASE WHEN r.verdict = %s THEN 1 ELSE 0 END) cleared,
					SUM(CASE WHEN r.verdict = %s THEN 1 ELSE 0 END) held,
					SUM(CASE WHEN r.verdict = %s THEN 1 ELSE 0 END) cancelled,
					COALESCE(SUM(i.order_total), 0) value,
					COALESCE(SUM(CASE WHEN r.id IS NULL THEN i.order_total ELSE 0 END), 0) unreviewed_value'
				. self::hv_queue_from()
				. " WHERE i.order_total >= %f AND i.order_date >= %s AND i.order_date < %s AND (r.id IS NOT NULL OR {$live})",
				$params
			),
			ARRAY_A
		);

		$floor  = gmdate( 'Y-m-d H:i:s', strtotime( $win['from'] . ' UTC' ) - 90 * DAY_IN_SECONDS );
		$params = array( (float) $amount, $floor, $win['from'] );
		$live   = self::hv_live_sql( $params );
		$old    = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT COUNT(*) n, MIN(i.order_date) oldest'
				. self::hv_queue_from()
				. " WHERE i.order_total >= %f AND i.order_date >= %s AND i.order_date < %s AND r.id IS NULL AND {$live}",
				$params
			),
			ARRAY_A
		);

		// Money only adds up inside one currency; report the one the store sells in.
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';

		return array(
			'total'             => (int) ( $row['total'] ?? 0 ),
			'unreviewed'        => (int) ( $row['unreviewed'] ?? 0 ),
			'reviewed'          => (int) ( $row['reviewed'] ?? 0 ),
			'cleared'           => (int) ( $row['cleared'] ?? 0 ),
			'held'              => (int) ( $row['held'] ?? 0 ),
			'cancelled'         => (int) ( $row['cancelled'] ?? 0 ),
			'value'             => (float) ( $row['value'] ?? 0 ),
			'unreviewed_value'  => (float) ( $row['unreviewed_value'] ?? 0 ),
			'backlog'           => (int) ( $old['n'] ?? 0 ),
			'oldest_unreviewed' => (string) ( $old['oldest'] ?? '' ),
			'currency'          => $currency,
		);
	}

	/**
	 * The two numbers the Dashboard card shows: unreviewed live high-value
	 * orders placed today and this week, in one query.
	 *
	 * @param float $amount Threshold.
	 * @return array { today:int, week:int }
	 */
	public static function hv_unreviewed_counts( $amount ) {
		global $wpdb;
		$today  = self::hv_window( 'today' );
		$week   = self::hv_window( 'week' );
		$params = array( $today['from'], (float) $amount, $week['from'], $week['to'] );
		$live   = self::hv_live_sql( $params );
		$row    = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT COUNT(*) week, SUM(CASE WHEN i.order_date >= %s THEN 1 ELSE 0 END) today'
				. self::hv_queue_from()
				. " WHERE i.order_total >= %f AND i.order_date >= %s AND i.order_date < %s AND r.id IS NULL AND {$live}",
				$params
			),
			ARRAY_A
		);
		return array(
			'today' => (int) ( $row['today'] ?? 0 ),
			'week'  => (int) ( $row['week'] ?? 0 ),
		);
	}

	/**
	 * Per-identity history for a page of queue rows, in one query: orders
	 * placed under the email, how many are charged back, settled spend, and
	 * the first order — so a reviewer sees "first order ever" or "4th order,
	 * 1 chargeback" without opening the profile.
	 *
	 * @param array $emails Normalized emails.
	 * @return array [ email => { orders:int, chargebacks:int, spend:float, first_order:string } ]
	 */
	public static function hv_customer_facts( $emails ) {
		global $wpdb;
		$emails = array_values( array_unique( array_filter( array_map( 'strval', (array) $emails ) ) ) );
		if ( empty( $emails ) ) {
			return array();
		}
		$t    = self::index_table();
		$ph   = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		$paid = self::paid_in_sql();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT email_norm, COUNT(*) orders, SUM(is_chargeback) chargebacks,
					COALESCE(SUM(CASE WHEN status IN ({$paid}) THEN order_total ELSE 0 END), 0) spend,
					MIN(order_date) first_order
				FROM {$t} WHERE email_norm IN ({$ph}) GROUP BY email_norm",
				$emails
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['email_norm'] ] = array(
				'orders'      => (int) $r['orders'],
				'chargebacks' => (int) $r['chargebacks'],
				'spend'       => (float) $r['spend'],
				'first_order' => (string) $r['first_order'],
			);
		}
		return $out;
	}

	/**
	 * Append a verdict to the ledger. FS_Live::review_high_value() has
	 * already changed the order (or decided not to); this only records.
	 *
	 * @param WC_Order $order         Order.
	 * @param string   $verdict       cleared | held | cancelled.
	 * @param string   $note          Reviewer's note ('' = none).
	 * @param int      $reviewer_id   WordPress user id (0 = API caller).
	 * @param string   $reviewer_name Display name to snapshot.
	 * @param string   $source        admin | order | api.
	 * @param string   $status_before Order status before the verdict.
	 * @param string   $status_after  Order status after it.
	 * @return int Row id (0 on failure).
	 */
	public static function hv_record_review( $order, $verdict, $note, $reviewer_id, $reviewer_name, $source, $status_before, $status_after ) {
		global $wpdb;
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$ok   = $wpdb->insert( // phpcs:ignore WordPress.DB
			self::hv_reviews_table(),
			array(
				'order_id'      => (int) $order->get_id(),
				'user_id'       => (int) $order->get_customer_id(),
				'email_norm'    => mb_substr( FS_Normalizer::email( (string) $order->get_billing_email() ), 0, 191 ),
				'customer_name' => mb_substr( $name, 0, 191 ),
				'order_total'   => (float) $order->get_total(),
				'currency'      => mb_substr( (string) $order->get_currency(), 0, 8 ),
				'verdict'       => isset( self::hv_verdicts()[ $verdict ] ) ? $verdict : 'cleared',
				'note'          => '' !== trim( (string) $note ) ? trim( (string) $note ) : null,
				'status_before' => mb_substr( (string) $status_before, 0, 32 ),
				'status_after'  => mb_substr( (string) $status_after, 0, 32 ),
				'reviewer_id'   => max( 0, (int) $reviewer_id ),
				'reviewer_name' => mb_substr( trim( (string) $reviewer_name ), 0, 191 ),
				'source'        => in_array( $source, array( 'admin', 'order', 'api' ), true ) ? $source : 'admin',
				'reviewed_at'   => current_time( 'mysql' ),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * The latest verdict on an order, or null when it was never reviewed.
	 *
	 * @param int $order_id Order id.
	 * @return array|null
	 */
	public static function hv_latest_review( $order_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::hv_reviews_table() . ' WHERE order_id = %d ORDER BY id DESC LIMIT 1', (int) $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * Every verdict ever recorded on an order, newest first.
	 *
	 * @param int $order_id Order id.
	 * @return array[]
	 */
	public static function hv_reviews_for_order( $order_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::hv_reviews_table() . ' WHERE order_id = %d ORDER BY id DESC', (int) $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One ledger row by id.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function hv_review_row( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::hv_reviews_table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * WHERE fragment for the review log.
	 *
	 * @param array $a      from, to (on reviewed_at), reviewer_id (null = any), verdict, search.
	 * @param array $params Params (appended to).
	 * @return string SQL starting with ' WHERE '.
	 */
	private static function hv_reviews_where( $a, &$params ) {
		global $wpdb;
		$where = ' WHERE 1=1';
		if ( '' !== (string) $a['from'] ) {
			$where   .= ' AND reviewed_at >= %s';
			$params[] = (string) $a['from'];
		}
		if ( '' !== (string) $a['to'] ) {
			$where   .= ' AND reviewed_at < %s';
			$params[] = (string) $a['to'];
		}
		if ( null !== $a['reviewer_id'] && '' !== (string) $a['reviewer_id'] ) {
			$where   .= ' AND reviewer_id = %d';
			$params[] = (int) $a['reviewer_id'];
		}
		if ( '' !== (string) $a['verdict'] && isset( self::hv_verdicts()[ $a['verdict'] ] ) ) {
			$where   .= ' AND verdict = %s';
			$params[] = (string) $a['verdict'];
		}
		$search = trim( (string) $a['search'] );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$parts  = array( 'email_norm LIKE %s', 'customer_name LIKE %s', 'reviewer_name LIKE %s', 'note LIKE %s' );
			$params = array_merge( $params, array( $like, $like, $like, $like ) );
			$num    = ltrim( $search, '#' );
			if ( ctype_digit( $num ) ) {
				$parts[]  = 'order_id = %d';
				$params[] = (int) $num;
			}
			$where .= ' AND (' . implode( ' OR ', $parts ) . ')';
		}
		return $where;
	}

	/**
	 * Defaults shared by the review log list and count.
	 *
	 * @param array $args Caller args.
	 * @return array
	 */
	private static function hv_reviews_args( $args ) {
		return wp_parse_args(
			$args,
			array(
				'from'        => '',
				'to'          => '',
				'reviewer_id' => null,
				'verdict'     => '',
				'search'      => '',
				'limit'       => 50,
				'offset'      => 0,
			)
		);
	}

	/**
	 * A page of the review log, newest first: who checked which order, what
	 * they decided, and what it did to the order.
	 *
	 * @param array $args See hv_reviews_args().
	 * @return array[]
	 */
	public static function hv_reviews_list( $args = array() ) {
		global $wpdb;
		$a      = self::hv_reviews_args( $args );
		$params = array();
		$where  = self::hv_reviews_where( $a, $params );
		array_push( $params, max( 1, min( 500, (int) $a['limit'] ) ), max( 0, (int) $a['offset'] ) );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT * FROM ' . self::hv_reviews_table() . $where . ' ORDER BY reviewed_at DESC, id DESC LIMIT %d OFFSET %d', $params ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many review-log rows match.
	 *
	 * @param array $args See hv_reviews_list().
	 * @return int
	 */
	public static function hv_reviews_count( $args = array() ) {
		global $wpdb;
		$a      = self::hv_reviews_args( $args );
		$params = array();
		$where  = self::hv_reviews_where( $a, $params );
		$sql    = 'SELECT COUNT(*) FROM ' . self::hv_reviews_table() . $where;
		return (int) ( empty( $params ) ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A reviewer's name as it should read now: the WordPress account's
	 * current display name when the account still exists, else the name
	 * snapshotted on the ledger row, else a placeholder. API callers are
	 * reviewer 0 and read as the key that reviewed.
	 *
	 * @param int    $reviewer_id WordPress user id (0 = API).
	 * @param string $fallback    Snapshotted name.
	 * @return string
	 */
	public static function hv_reviewer_name( $reviewer_id, $fallback = '' ) {
		$reviewer_id = (int) $reviewer_id;
		if ( $reviewer_id > 0 && function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $reviewer_id );
			if ( $user && ! empty( $user->display_name ) ) {
				return (string) $user->display_name;
			}
		}
		if ( '' !== trim( (string) $fallback ) ) {
			return trim( (string) $fallback );
		}
		/* translators: %d: WordPress user id */
		return $reviewer_id > 0 ? sprintf( __( 'user #%d', 'fraud-shield' ), $reviewer_id ) : __( 'API', 'fraud-shield' );
	}

	/**
	 * Who is checking the high-value orders: one row per reviewer, ranked by
	 * verdicts recorded in the window, with the verdict breakdown, the order
	 * value they looked at, their last review, their all-time count and
	 * their share of the window's reviews. Ties rank by the more recent
	 * review (then by the later ledger row, so two verdicts in the same
	 * second still order the same way every time). API callers (reviewer 0)
	 * are one row.
	 *
	 * @param string $from Window start on reviewed_at ('' = all time).
	 * @param string $to   Window end, exclusive ('' = none).
	 * @return array[] { rank, reviewer_id, reviewer_name, reviews, cleared, held, cancelled, value, last_at, all_time, share }
	 */
	public static function hv_leaderboard( $from = '', $to = '' ) {
		global $wpdb;
		$t      = self::hv_reviews_table();
		$params = array( 'cleared', 'held', 'cancelled' );
		$where  = ' WHERE 1=1';
		if ( '' !== (string) $from ) {
			$where   .= ' AND reviewed_at >= %s';
			$params[] = (string) $from;
		}
		if ( '' !== (string) $to ) {
			$where   .= ' AND reviewed_at < %s';
			$params[] = (string) $to;
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT reviewer_id, COUNT(*) reviews,
					SUM(CASE WHEN verdict = %s THEN 1 ELSE 0 END) cleared,
					SUM(CASE WHEN verdict = %s THEN 1 ELSE 0 END) held,
					SUM(CASE WHEN verdict = %s THEN 1 ELSE 0 END) cancelled,
					COALESCE(SUM(order_total), 0) value,
					MAX(reviewed_at) last_at,
					MAX(id) last_id
				FROM {$t}{$where}
				GROUP BY reviewer_id
				ORDER BY reviews DESC, last_at DESC, last_id DESC",
				$params
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return array();
		}

		// The name to show comes from each reviewer's most recent row.
		$ids   = array_map( 'intval', wp_list_pluck( $rows, 'last_id' ) );
		$names = array();
		foreach ( (array) $wpdb->get_results( 'SELECT id, reviewer_name FROM ' . $t . ' WHERE id IN (' . implode( ',', $ids ) . ')', ARRAY_A ) as $n ) { // phpcs:ignore WordPress.DB
			$names[ (int) $n['id'] ] = (string) $n['reviewer_name'];
		}
		$all = array();
		foreach ( (array) $wpdb->get_results( "SELECT reviewer_id, COUNT(*) n FROM {$t} GROUP BY reviewer_id", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB
			$all[ (int) $x['reviewer_id'] ] = (int) $x['n'];
		}

		$total = 0;
		foreach ( $rows as $r ) {
			$total += (int) $r['reviews'];
		}
		$out  = array();
		$rank = 0;
		foreach ( $rows as $r ) {
			++$rank;
			$rid   = (int) $r['reviewer_id'];
			$out[] = array(
				'rank'          => $rank,
				'reviewer_id'   => $rid,
				'reviewer_name' => self::hv_reviewer_name( $rid, $names[ (int) $r['last_id'] ] ?? '' ),
				'reviews'       => (int) $r['reviews'],
				'cleared'       => (int) $r['cleared'],
				'held'          => (int) $r['held'],
				'cancelled'     => (int) $r['cancelled'],
				'value'         => (float) $r['value'],
				'last_at'       => (string) $r['last_at'],
				'all_time'      => (int) ( $all[ $rid ] ?? 0 ),
				'share'         => $total > 0 ? round( (int) $r['reviews'] / $total, 4 ) : 0.0,
			);
		}
		return $out;
	}
}
