<?php
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every query here is passed through wpdb->prepare(); the only interpolated token is this package own table name and the dynamic WHERE clause is built solely from literal, bound %s placeholders. No user value ever reaches the SQL string unbound.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- every query in this file binds its values through wpdb->prepare(); the parameter the sniff flags is the table prefix or a placeholder string, both of which are safe.
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- this library owns the 'zorem-mcp' domain and loads it itself; Plugin Check compares against the host plugin's folder name, which is a different plugin every time this folder ships.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- hook and function names are a public API shared with the free plugin; renaming them breaks customer snippets.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- this class IS the storage layer for the audit log; there is no WordPress API for a custom table, and caching a log the merchant reads to check what just happened would defeat its purpose.
/**
 * Zorem MCP - audit log storage.
 *
 * Every tool an AI assistant runs is recorded here: when, which tool, as whom, over which
 * channel, and whether it was refused. This is a record the merchant is meant to be able to
 * trust, so it is a real table rather than an option -- an option would cap out after a few
 * hundred rows and be loaded in full on every page view.
 *
 * Deliberately narrow: no order numbers, tracking numbers or customer details are stored.
 * Full request and response bodies belong in the WooCommerce log, behind its own switch.
 *
 * @package Zorem_MCP
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorem_MCP_Audit {

	/**
	 * Table name without the site prefix.
	 */
	const TABLE = 'zorem_mcp_audit';

	/**
	 * Bumped whenever the table's shape changes, so an upgrade runs dbDelta once.
	 */
	const SCHEMA = '2';

	/**
	 * Option remembering which schema this site has.
	 */
	const SCHEMA_OPTION = 'zorem_mcp_audit_schema';


	/**
	 * Default retention, in days.
	 */
	const RETENTION_DEFAULT = 30;

	/**
	 * Cron hook that trims the log.
	 */
	const PURGE_HOOK = 'zorem_mcp_audit_purge';

	/**
	 * Rows shown per page on the Audit Log screen.
	 */
	const PER_PAGE = 50;

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Register the retention cron.
	 *
	 * @return void
	 */
	public static function init() {
		// The table is the library's, not any one plugin's, so no plugin installer creates
		// it. The check is bound to admin_init rather than run here: creating it means
		// loading wp-admin/includes/upgrade.php for dbDelta, which has no business being
		// pulled into a front-end or REST request. A store reaches wp-admin long before it
		// hands anyone an AI link, and until then every read guards on table_exists() and
		// simply shows an empty log.
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( self::PURGE_HOOK, array( __CLASS__, 'purge' ) );

		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Create or upgrade the table, once per schema change.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA ) {
			return;
		}

		self::create_table();
		update_option( self::SCHEMA_OPTION, self::SCHEMA );
	}

	/**
	 * Create the table. Safe to call repeatedly -- dbDelta only applies differences.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			tool varchar(64) NOT NULL DEFAULT '',
			channel varchar(32) NOT NULL DEFAULT '',
			client varchar(64) NOT NULL DEFAULT '',
			user_login varchar(60) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			result varchar(10) NOT NULL DEFAULT 'ok',
			error text NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) $collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Whether the table exists. Used so a missing table degrades to an empty log instead
	 * of a database error on screen.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		static $exists = null;

		if ( null === $exists ) {
			$table  = self::table();
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}

		return $exists;
	}

	/**
	 * Record one tool call.
	 *
	 * @param string         $tool    Tool name, as AST_MCP logs it.
	 * @param string         $channel Which route the call came in over.
	 * @param array|WP_Error $result  Tool outcome.
	 * @param string         $client  Which AI app made the call, where it can be told.
	 * @return void
	 */
	public static function record( $tool, $channel, $result, $client = '' ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return;
		}

		$user     = wp_get_current_user();
		$is_error = is_wp_error( $result );

		$wpdb->insert(
			self::table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'tool'       => substr( (string) $tool, 0, 64 ),
				'channel'    => substr( (string) $channel, 0, 32 ),
				'client'     => substr( (string) $client, 0, 64 ),
				'user_login' => ( $user && $user->exists() ) ? substr( $user->user_login, 0, 60 ) : '',
				// The only thing that separates two people sharing one link. Both send the
				// same token and the same app name, so without this the screen can only say
				// "somebody called" -- which is the answer a merchant already has.
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- sanitised on the same line.
				'ip'         => isset( $_SERVER['REMOTE_ADDR'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), 0, 45 ) : '',
				'result'     => $is_error ? 'error' : 'ok',
				'error'      => $is_error ? $result->get_error_message() : null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * One page of entries, newest first.
	 *
	 * @param int $page Page number, 1-based.
	 * @return array
	 */
	public static function entries( $page = 1 ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$page   = max( 1, (int) $page );
		$offset = ( $page - 1 ) * self::PER_PAGE;
		$table  = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix and a class constant, never from input.
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", self::PER_PAGE, $offset ),
			ARRAY_A
		);
	}

	/**
	 * Total number of entries.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix and a class constant, never from input.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	}

	/**
	 * The distinct callers on one channel: one row per app-and-address, newest first.
	 *
	 * WHY APP PLUS ADDRESS
	 *
	 * A shared link is one secret held by however many people the merchant gave it to, and
	 * every one of them arrives carrying the same token under the same app name. The server
	 * cannot name them -- that is what signing in is for -- but it can still see that these
	 * requests came from somewhere else, and saying so is the difference between a screen
	 * that reports one connection and a screen that reports two.
	 *
	 * The address is not an identity and is not offered as one. It changes, it is shared by
	 * everyone behind an office router, and two people can look like one. What it does
	 * honestly support is a count and a clock: somebody else has been here, and this is when.
	 *
	 * @param string $channel Channel to group.
	 * @param int    $limit   Most callers to return.
	 * @return array List of array( client, ip, first_seen, last_seen, calls ).
	 */
	public static function callers( $channel, $limit = 20 ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is this package's own; both values are bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT client, ip, MIN(created_at) AS first_seen, MAX(created_at) AS last_seen, COUNT(*) AS calls
				 FROM {$table}
				 WHERE channel = %s
				 GROUP BY client, ip
				 ORDER BY last_seen DESC
				 LIMIT %d",
				$channel,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every AI app that has called over a channel, and when it last did.
	 *
	 * The settings screen uses this to tell the merchant which of the apps it lists is
	 * actually talking to the store. It can only ever report apps that have RUN something:
	 * this is a web server, and what sits in a config file on somebody's laptop is not
	 * knowable from here. That turns out to be the more useful signal anyway -- "it has
	 * worked" beats "it has been typed in somewhere".
	 *
	 * @param string $channel Channel slug, as stored.
	 * @return array Lowercased client name => last-used UTC timestamp.
	 */
	public static function clients_seen( $channel ) {
		global $wpdb;

		static $cache = array();

		if ( isset( $cache[ $channel ] ) ) {
			return $cache[ $channel ];
		}

		if ( ! self::table_exists() ) {
			$cache[ $channel ] = array();
			return $cache[ $channel ];
		}

		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT client, MAX(created_at) AS last_used
				 FROM $table
				 WHERE channel = %s AND client <> ''
				 GROUP BY client",
				$channel
			),
			ARRAY_A
		);

		$seen = array();

		foreach ( (array) $rows as $row ) {
			$seen[ strtolower( $row['client'] ) ] = strtotime( $row['last_used'] . ' UTC' );
		}

		$cache[ $channel ] = $seen;

		return $seen;
	}

	/**
	 * When a channel was last used, as a UTC timestamp.
	 *
	 * This is the only honest way to say whether an AI assistant is actually connected:
	 * switching the feature on merely makes a connection possible, and nothing on the
	 * WordPress side is told when a client connects -- an AI having run a tool is the
	 * first moment we can prove one is really there.
	 *
	 * @param string $channel Channel slug, as stored.
	 * @return int|null Timestamp, or null if that channel has never been used.
	 */
	public static function last_used( $channel ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return null;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix and a class constant, never from input.
		$when = $wpdb->get_var(
			$wpdb->prepare( "SELECT created_at FROM $table WHERE channel = %s ORDER BY id DESC LIMIT 1", $channel )
		);

		return $when ? strtotime( $when . ' UTC' ) : null;
	}

	/**
	 * How many days of history the merchant asked to keep.
	 *
	 * @return int Zero means keep everything.
	 */
	public static function retention_days() {
		return max( 0, (int) Zorem_MCP_Settings::get( 'audit_retention' ) );
	}

	/**
	 * Delete entries older than the retention window.
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;

		$days = self::retention_days();

		if ( ! $days || ! self::table_exists() ) {
			return;
		}

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix and a class constant, never from input.
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at < %s", $cutoff ) );
	}
}
