<?php
/**
 * Outbound webhooks: telling another system what just happened here, reliably,
 * without ever making a customer wait for it.
 *
 * Three ideas carry the whole design.
 *
 * NOTHING SHIPS INLINE. An event is recorded and queued inside the request that
 * caused it — two indexed inserts — and delivered later by cron. A remote
 * endpoint that hangs for thirty seconds is somebody else's outage, not a
 * thirty-second checkout. This is the same lesson the cardholder check learned:
 * a network call on the checkout path is a checkout you have lost control of.
 *
 * EVENTS ARE FACTS, DELIVERIES ARE ATTEMPTS. The event log is written once and
 * is readable over the API, so a platform that would rather poll than expose an
 * endpoint gets identical data. Each subscription then owns its own delivery
 * row, so one broken endpoint cannot delay or lose another's events, and a
 * retry re-sends the same immutable payload rather than re-deriving it from a
 * store that has since moved on.
 *
 * THE URL IS UNTRUSTED. A webhook target is an address this store will connect
 * to on demand, which is the exact shape of a server-side request forgery. URLs
 * must be HTTPS and must not resolve into private or loopback space unless an
 * admin has explicitly allowed it for local development.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Webhooks {

	/**
	 * Cron hook that drains the queue.
	 */
	const CRON_HOOK = 'fs_webhook_dispatch';

	/**
	 * Custom schedule name for the recurring drain.
	 */
	const SCHEDULE = 'fs_five_minutes';

	/**
	 * Deliveries attempted per dispatch run, before the time budget.
	 */
	const BATCH = 40;

	/**
	 * Wall-clock seconds one dispatch run may spend. Same reasoning as the IDV
	 * sync: finish the backlog if we can, stop cleanly if we cannot, and never
	 * be the reason a request times out.
	 */
	const TIME_BUDGET = 20;

	/**
	 * Consecutive failures before a subscription is switched off.
	 */
	const FAILURE_LIMIT = 20;

	/**
	 * Longest gap between retries.
	 */
	const MAX_BACKOFF = 6 * HOUR_IN_SECONDS;

	/**
	 * Payload fields replaced by digests when a subscription asks for hashed
	 * identifiers, so a partner can correlate customers without receiving them.
	 *
	 * @var string[]
	 */
	private static $pii_fields = array(
		'email',
		'email_norm',
		'name',
		'ip',
		'phone',
		'expected_name',
		'full_name',
		// A blocklist target and its title both embed whatever was blocked —
		// "email:buyer@example.com", "Email: buyer@example.com" — and a verified
		// credential's key embeds the raw email or IP it refers to. They read like
		// internal identifiers, which is exactly why they were easy to miss.
		'target',
		'title',
		'key',
		// Masked, but still card data. A subscription that asked for digests did
		// not ask for the last four of anyone's card.
		'last4',
		// v1.30: a block card's title names the person or address it is
		// about, and a removed credential IS the identifier.
		'block_card',
		'credential',
	);

	/**
	 * Current time for this subsystem's tables.
	 *
	 * Every timestamp in the webhook tables is UTC, and every comparison uses
	 * this same clock. The rest of the plugin stores site-local time, which is
	 * fine for rows humans read but wrong for a queue: a store that moves
	 * timezone, or observes daylight saving, would otherwise see scheduled
	 * retries jump an hour into the future or fire an hour early.
	 *
	 * @return string
	 */
	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Format one of this subsystem's UTC timestamps as ISO 8601.
	 *
	 * @param string $mysql_utc Stored value.
	 * @return string|null
	 */
	public static function iso( $mysql_utc ) {
		$mysql_utc = (string) $mysql_utc;
		if ( '' === $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return null;
		}
		$ts = strtotime( $mysql_utc . ' UTC' );
		return false === $ts ? null : gmdate( 'c', $ts );
	}

	/**
	 * Wire up.
	 *
	 * Order and account events are captured from hooks that already fire, so
	 * emitting them costs no edits anywhere else in the plugin: the risk verdict
	 * is read back from the order meta FS_Live has just written, and account
	 * flags are observed through WordPress' own meta actions.
	 */
	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::CRON_HOOK, array( $this, 'dispatch' ) );

		// Risk verdicts. Priority 40 puts us after FS_Live's own handlers (20),
		// so the score and level are on the order by the time we read them.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_classic_order' ), 40, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_blocks_order' ), 40, 1 );

		// Account-level flags, wherever they are changed from.
		add_action( 'added_user_meta', array( $this, 'on_user_meta' ), 10, 4 );
		add_action( 'updated_user_meta', array( $this, 'on_user_meta' ), 10, 4 );
		add_action( 'deleted_user_meta', array( $this, 'on_user_meta_deleted' ), 10, 4 );
	}

	/* ===================== Tables ===================== */

	/**
	 * Subscriptions.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'fs_webhooks';
	}

	/**
	 * The event log.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'fs_api_events';
	}

	/**
	 * Per-subscription delivery attempts.
	 *
	 * @return string
	 */
	public static function deliveries_table() {
		global $wpdb;
		return $wpdb->prefix . 'fs_webhook_deliveries';
	}

	/* ===================== Topics ===================== */

	/**
	 * Every topic a subscription may ask for.
	 *
	 * @return array [ topic => description ].
	 */
	public static function topics() {
		return array(
			'order.scored'         => __( 'An order was risk-scored (every order).', 'fraud-shield' ),
			'order.flagged'        => __( 'An order scored medium risk or worse.', 'fraud-shield' ),
			'checkout.blocked'     => __( 'A checkout attempt was refused or locked.', 'fraud-shield' ),
			'customer.verified'    => __( 'A customer or credential was verified.', 'fraud-shield' ),
			'customer.unverified'  => __( 'A verification was withdrawn.', 'fraud-shield' ),
			'customer.blocked'     => __( 'A customer, address, IP or destination was blocked.', 'fraud-shield' ),
			'idv.approved'         => __( 'An ID verification was approved.', 'fraud-shield' ),
			'idv.declined'         => __( 'An ID verification was declined or abandoned.', 'fraud-shield' ),
			'idv.name_mismatch'    => __( 'An ID was genuine but the name did not match.', 'fraud-shield' ),
			'card.checked'         => __( 'A cardholder check returned an issuer verdict.', 'fraud-shield' ),
			'restriction.applied'  => __( 'A customer was put on restricted checkout.', 'fraud-shield' ),
			'restriction.released' => __( 'A restricted checkout was lifted.', 'fraud-shield' ),
			// v1.30: the controls and ledgers added since the API shipped.
			'order.cancelled'      => __( 'An order was cancelled at creation for sharing credentials with a blocked identity.', 'fraud-shield' ),
			'order.chargeback'     => __( 'An order was marked as charged back, or the mark was cleared.', 'fraud-shield' ),
			'order.recovery'       => __( 'A chargeback recovery was recorded or cleared on an order.', 'fraud-shield' ),
			'customer.unblocked'   => __( 'A block was reversed: the block card and the credentials it owned were released.', 'fraud-shield' ),
			'blocklist.extended'   => __( 'An existing block grew: a re-sync or a matching new order linked more credentials to it.', 'fraud-shield' ),
			'blocklist.removed'    => __( 'One or more blocklist credentials were removed.', 'fraud-shield' ),
			'hold.applied'         => __( 'A new account was placed under a verification hold.', 'fraud-shield' ),
			'hold.released'        => __( 'A verification hold was lifted.', 'fraud-shield' ),
			'idv.requested'        => __( 'An ID check was demanded from an account.', 'fraud-shield' ),
			'idv.request_cleared'  => __( 'A demanded ID check was satisfied or withdrawn.', 'fraud-shield' ),
			// v1.32: the high-value review queue.
			'order.reviewed'       => __( 'A reviewer recorded a verdict on a high-value order: looks good, held, or cancelled.', 'fraud-shield' ),
			'test.ping'            => __( 'Test event, sent only on request.', 'fraud-shield' ),
		);
	}

	/**
	 * Keep only topics we publish.
	 *
	 * @param array|string $topics Requested topics ('*' or empty = all).
	 * @return array
	 */
	public static function sanitize_topics( $topics ) {
		$known = self::topics();
		if ( is_string( $topics ) ) {
			$topics = preg_split( '/[\s,]+/', $topics, -1, PREG_SPLIT_NO_EMPTY );
		}
		$topics = (array) $topics;
		if ( empty( $topics ) || in_array( '*', $topics, true ) ) {
			// A wildcard subscription still excludes the test topic: nobody wants
			// their production feed carrying somebody's button press.
			return array_values( array_diff( array_keys( $known ), array( 'test.ping' ) ) );
		}

		$out = array();
		foreach ( $topics as $topic ) {
			$topic = strtolower( trim( (string) $topic ) );
			if ( isset( $known[ $topic ] ) && ! in_array( $topic, $out, true ) ) {
				$out[] = $topic;
			}
		}
		return $out;
	}

	/* ===================== Subscriptions ===================== */

	/**
	 * Create a subscription and return its one and only copy of the secret.
	 *
	 * @param array $args {
	 *     @type string $url        HTTPS endpoint.
	 *     @type array  $topics     Topics to receive.
	 *     @type string $label      Human name.
	 *     @type bool   $hash_pii   Send digests instead of identifiers.
	 *     @type int    $created_by User id (0 = created over the API).
	 * }
	 * @return array|WP_Error { id, secret, topics }
	 */
	public static function subscribe( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'url'        => '',
				'topics'     => array(),
				'label'      => '',
				'hash_pii'   => false,
				'created_by' => get_current_user_id(),
			)
		);

		$url = self::validate_url( $args['url'] );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$topics = self::sanitize_topics( $args['topics'] );
		if ( empty( $topics ) ) {
			return new WP_Error( 'fs_hook_no_topics', __( 'Choose at least one topic to subscribe to.', 'fraud-shield' ), array( 'status' => 400 ) );
		}

		try {
			$secret = 'fswh_' . bin2hex( random_bytes( 24 ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'fs_hook_no_entropy', __( 'This server could not generate a secure signing secret.', 'fraud-shield' ), array( 'status' => 500 ) );
		}

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'url'        => $url,
				'secret'     => $secret,
				'topics'     => implode( ',', $topics ),
				'label'      => mb_substr( sanitize_text_field( (string) $args['label'] ), 0, 191 ),
				'status'     => 'active',
				'hash_pii'   => ! empty( $args['hash_pii'] ) ? 1 : 0,
				'failures'   => 0,
				'created_by' => (int) $args['created_by'],
				'created_at' => self::now(),
			)
		);
		if ( ! $ok ) {
			return new WP_Error( 'fs_hook_store_failed', __( 'The subscription could not be stored.', 'fraud-shield' ), array( 'status' => 500 ) );
		}

		// Before the option writes below: each can INSERT into wp_options the
		// first time it runs, and that would overwrite insert_id — handing the
		// caller an id that DELETE /webhooks/{id} would never match.
		$id = (int) $wpdb->insert_id;

		FS_REST_API::refresh_counts();
		self::ensure_cron();
		FS_API_Keys::audit( 'webhook.created', '', $url );

		return array(
			'id'     => $id,
			'secret' => $secret,
			'topics' => $topics,
		);
	}

	/**
	 * Validate a webhook target.
	 *
	 * The checks are ordered cheapest first, and the address check is the point
	 * of the whole function: without it, "add a webhook" is a request-forgery
	 * primitive pointed at whatever the store can reach — cloud metadata
	 * services, internal admin panels, databases on localhost.
	 *
	 * @param string $raw Candidate URL.
	 * @return string|WP_Error Normalized URL.
	 */
	public static function validate_url( $raw ) {
		$settings = FS_REST_API::settings();
		$allow    = ! empty( $settings['allow_private_urls'] );

		$url = trim( (string) $raw );
		if ( '' === $url || strlen( $url ) > 500 ) {
			return new WP_Error( 'fs_hook_bad_url', __( 'Provide a delivery URL.', 'fraud-shield' ), array( 'status' => 400 ) );
		}

		$url   = esc_url_raw( $url );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'fs_hook_bad_url', __( 'That is not a valid URL.', 'fraud-shield' ), array( 'status' => 400 ) );
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' !== $scheme && ! ( $allow && 'http' === $scheme ) ) {
			return new WP_Error( 'fs_hook_insecure', __( 'Webhook URLs must use HTTPS.', 'fraud-shield' ), array( 'status' => 400 ) );
		}

		// Credentials in the URL end up in logs and in every retry.
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return new WP_Error( 'fs_hook_bad_url', __( 'Do not put credentials in the URL. Use the signing secret instead.', 'fraud-shield' ), array( 'status' => 400 ) );
		}

		if ( ! $allow ) {
			$addresses = self::resolve_host( trim( $parts['host'], '[]' ) );

			if ( empty( $addresses ) ) {
				return new WP_Error( 'fs_hook_unresolved', __( 'That hostname does not resolve from this server.', 'fraud-shield' ), array( 'status' => 400 ) );
			}

			// EVERY address must be public, not just the first one. A host can
			// publish several A records, or a public A record alongside an AAAA
			// record pointing at ::1 — and the HTTP client, not us, picks which
			// one it connects to.
			foreach ( $addresses as $ip ) {
				if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return new WP_Error(
						'fs_hook_private',
						__( 'That URL resolves to a private or reserved address. Enable local delivery in the API settings if this is a development endpoint.', 'fraud-shield' ),
						array( 'status' => 400 )
					);
				}
			}
		}

		return $url;
	}

	/**
	 * Every address a hostname currently resolves to, IPv4 and IPv6.
	 *
	 * dns_get_record() is preferred because it sees AAAA records and every A
	 * record, where gethostbyname() sees one IPv4 address and silently returns
	 * the hostname itself on failure. It is not always available (disabled
	 * functions, some managed hosts), so the older call remains as a fallback.
	 *
	 * @param string $host Hostname or IP literal.
	 * @return string[] Validated addresses; empty when nothing resolves.
	 */
	private static function resolve_host( $host ) {
		$host = trim( (string) $host );
		if ( '' === $host ) {
			return array();
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$addresses = array();

		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a lookup failure is a non-answer, not a warning to surface.
			$records = @dns_get_record( $host, DNS_A | DNS_AAAA );
			foreach ( (array) $records as $record ) {
				foreach ( array( 'ip', 'ipv6' ) as $field ) {
					if ( ! empty( $record[ $field ] ) && filter_var( $record[ $field ], FILTER_VALIDATE_IP ) ) {
						$addresses[] = (string) $record[ $field ];
					}
				}
			}
		}

		if ( empty( $addresses ) ) {
			$resolved = gethostbyname( $host );
			if ( filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
				$addresses[] = $resolved;
			}
		}

		return array_values( array_unique( $addresses ) );
	}

	/**
	 * All subscriptions, newest first.
	 *
	 * @return array[]
	 */
	public static function all() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 200', ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One subscription.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Active subscriptions carrying a topic.
	 *
	 * @param string $topic Topic.
	 * @return array[]
	 */
	public static function for_topic( $topic ) {
		global $wpdb;
		// FIND_IN_SET over the stored comma list: subscription counts are in the
		// single digits, so this is cheaper than a junction table to maintain.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE status = 'active' AND FIND_IN_SET( %s, topics ) > 0",
				(string) $topic
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many subscriptions are live.
	 *
	 * @return int
	 */
	public static function active_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'active'" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Enable or disable a subscription.
	 *
	 * @param int    $id     Row id.
	 * @param string $status 'active' | 'disabled'.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$status = 'active' === $status ? 'active' : 'disabled';
		$data   = array( 'status' => $status );
		if ( 'active' === $status ) {
			$data['failures'] = 0; // Re-enabling clears the strike count.
		}
		$ok = $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
		FS_REST_API::refresh_counts();
		return false !== $ok;
	}

	/**
	 * Delete a subscription and its delivery history.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->delete( self::deliveries_table(), array( 'webhook_id' => $id ) ); // phpcs:ignore WordPress.DB
		$ok = $wpdb->delete( self::table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		FS_REST_API::refresh_counts();
		FS_API_Keys::audit( 'webhook.deleted', '', '#' . $id );
		return (bool) $ok;
	}

	/**
	 * Present a subscription without its secret.
	 *
	 * @param array $row Subscription row.
	 * @return array
	 */
	public static function shape( $row ) {
		return array(
			'id'             => (int) $row['id'],
			'url'            => (string) $row['url'],
			'label'          => (string) ( $row['label'] ?? '' ),
			'topics'         => preg_split( '/,/', (string) $row['topics'], -1, PREG_SPLIT_NO_EMPTY ),
			'status'         => (string) $row['status'],
			'hash_pii'       => ! empty( $row['hash_pii'] ),
			'failures'       => (int) ( $row['failures'] ?? 0 ),
			'last_status'    => (int) ( $row['last_status'] ?? 0 ),
			'last_error'     => (string) ( $row['last_error'] ?? '' ),
			'last_delivered' => self::iso( $row['last_delivered_at'] ?? '' ),
			'created_at'     => self::iso( $row['created_at'] ?? '' ),
		);
	}

	/* ===================== Emission ===================== */

	/**
	 * Record an event and queue it for anyone subscribed.
	 *
	 * Cheap by design: one autoloaded array read decides whether anything at all
	 * happens, so a store that never touched the API pays effectively nothing
	 * for every emit() call scattered through the plugin. Never throws, never
	 * warns — an analytics side effect must not be able to break a checkout.
	 *
	 * @param string $topic Topic key.
	 * @param array  $data  Topic payload.
	 * @return int Event id (0 when nothing was recorded).
	 */
	public static function emit( $topic, $data = array() ) {
		global $wpdb;

		$topic  = strtolower( trim( (string) $topic ) );
		$topics = self::topics();
		if ( '' === $topic || ! isset( $topics[ $topic ] ) ) {
			return 0;
		}
		if ( ! FS_REST_API::enabled() ) {
			return 0;
		}

		// No credentials and no subscriptions means nobody can ever read this.
		$counts = FS_REST_API::counts();
		if ( $counts['keys'] <= 0 && $counts['hooks'] <= 0 ) {
			return 0;
		}

		$payload = wp_json_encode( is_array( $data ) ? $data : array() );
		if ( false === $payload ) {
			return 0;
		}

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB
			self::events_table(),
			array(
				'topic'      => mb_substr( $topic, 0, 40 ),
				'payload'    => $payload,
				'created_at' => self::now(),
			)
		);
		if ( ! $ok ) {
			return 0;
		}
		$event_id = (int) $wpdb->insert_id;

		$hooks = self::for_topic( $topic );
		if ( empty( $hooks ) ) {
			return $event_id;
		}

		$now = self::now();
		foreach ( $hooks as $hook ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB
				self::deliveries_table(),
				array(
					'event_id'        => $event_id,
					'webhook_id'      => (int) $hook['id'],
					'topic'           => mb_substr( $topic, 0, 40 ),
					'status'          => 'pending',
					'attempts'        => 0,
					'next_attempt_at' => $now,
					'created_at'      => $now,
				)
			);
		}

		self::ensure_cron( true );
		return $event_id;
	}

	/**
	 * Events after a cursor, oldest first — the polling view.
	 *
	 * $last_id comes back by reference because the wire format's `id` is the
	 * string "evt_123", and the cursor a caller must send next has to be the
	 * numeric row id. Deriving one from the other by casting yields 0, which
	 * hands every polling client the same first page forever.
	 *
	 * @param int      $after   Last event id already seen.
	 * @param int      $limit   Max rows (caller has already bounded this).
	 * @param string   $topic   Optional topic filter.
	 * @param int|null $last_id Set to the highest row id returned.
	 * @return array[]
	 */
	public static function events( $after = 0, $limit = 50, $topic = '', &$last_id = null ) {
		global $wpdb;

		$last_id = max( 0, (int) $after );

		$sql    = 'SELECT id, topic, payload, created_at FROM ' . self::events_table() . ' WHERE id > %d';
		$params = array( max( 0, (int) $after ) );

		$topic  = strtolower( trim( (string) $topic ) );
		$topics = self::topics();
		if ( '' !== $topic && isset( $topics[ $topic ] ) ) {
			$sql     .= ' AND topic = %s';
			$params[] = $topic;
		}

		$sql     .= ' ORDER BY id ASC LIMIT %d';
		$params[] = max( 1, min( 100, (int) $limit ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[]   = self::envelope( $row );
			$last_id = max( $last_id, (int) $row['id'] );
		}
		return $out;
	}

	/**
	 * The wire format for one event, identical whether it is polled or pushed.
	 *
	 * @param array $row      Event row.
	 * @param array $delivery Delivery context for a push ('' for a poll).
	 * @param bool  $hash_pii Replace identifiers with digests.
	 * @return array
	 */
	public static function envelope( $row, $delivery = array(), $hash_pii = false ) {
		$data = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$data = is_array( $data ) ? $data : array();
		if ( $hash_pii ) {
			$data = self::redact( $data );
		}

		$envelope = array(
			'id'          => 'evt_' . (int) ( $row['id'] ?? 0 ),
			'topic'       => (string) ( $row['topic'] ?? '' ),
			'occurred_at' => empty( $row['created_at'] ) ? gmdate( 'c' ) : self::iso( $row['created_at'] ),
			'store'       => array(
				'store_id' => FS_REST_API::store_id(),
				'url'      => home_url( '/' ),
				'version'  => defined( 'FS_VERSION' ) ? FS_VERSION : '',
			),
			'data'        => $data,
		);

		if ( ! empty( $delivery ) ) {
			$envelope['delivery'] = $delivery;
		}
		return $envelope;
	}

	/**
	 * Replace identifiers with digests, recursively.
	 *
	 * The digest is stable, so a partner can still group by customer; it simply
	 * cannot read who they are. Chosen over dropping the fields because
	 * correlation is the reason most of these payloads are useful at all.
	 *
	 * @param array $data Payload.
	 * @return array
	 */
	public static function redact( $data ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$out[ $key ] = self::redact( $value );
				continue;
			}
			if ( in_array( (string) $key, self::$pii_fields, true ) && '' !== (string) $value ) {
				$out[ $key . '_sha256' ] = hash( 'sha256', strtolower( (string) $value ) );
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/* ===================== Scheduling ===================== */

	/**
	 * Register the recurring drain interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes (Fraud Shield webhooks)', 'fraud-shield' ),
			);
		}
		return $schedules;
	}

	/**
	 * Make sure the queue will be drained.
	 *
	 * A recurring job catches retries; an immediate single event makes the
	 * common case (nothing is broken) feel instant without a permanent
	 * once-a-minute job on every site that installed the plugin.
	 *
	 * @param bool $soon Also schedule an immediate run.
	 */
	public static function ensure_cron( $soon = false ) {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON_HOOK );
		}
		if ( $soon && false === get_transient( 'fs_webhook_soon' ) ) {
			// One nudge per 30 seconds: a burst of events should not queue a
			// burst of cron entries.
			set_transient( 'fs_webhook_soon', 1, 30 );
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Drop the schedule (used when the last subscription goes away).
	 */
	public static function clear_cron() {
		// Bounded on purpose. wp_unschedule_event() can return without removing
		// the entry (another plugin short-circuiting the filter, a failed cron
		// array write), and an unbounded retry loop on the deactivation hook
		// would hang the plugins screen rather than fail politely.
		for ( $i = 0; $i < 50; $i++ ) {
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
			if ( ! $timestamp ) {
				return;
			}
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/* ===================== Delivery ===================== */

	/**
	 * Drain the queue: send what is due, reschedule what failed.
	 */
	public function dispatch() {
		global $wpdb;

		// One drainer at a time. Two overlapping runs would double-send.
		if ( false !== get_transient( 'fs_webhook_lock' ) ) {
			return;
		}
		set_transient( 'fs_webhook_lock', 1, 5 * MINUTE_IN_SECONDS );

		$deadline = time() + self::TIME_BUDGET;

		$due = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT * FROM ' . self::deliveries_table() . " WHERE status = 'pending' AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d",
				self::now(),
				self::BATCH
			),
			ARRAY_A
		);

		foreach ( (array) $due as $delivery ) {
			if ( time() >= $deadline ) {
				// Out of budget: the rest stays pending and the next run picks
				// it up. Nothing is lost, nothing is sent twice.
				self::ensure_cron( true );
				break;
			}

			// Read the subscription fresh for every delivery rather than caching
			// it: an endpoint that fails its way past the strike limit must stop
			// being called for the REST of this run, and a cached row would keep
			// reporting itself active until the next one. One indexed lookup per
			// delivery, on cron, is not worth optimising away.
			$webhook = self::get( (int) $delivery['webhook_id'] );

			if ( ! $webhook || 'active' !== (string) $webhook['status'] ) {
				// Subscription is gone or switched off: retire the attempt
				// rather than retrying it forever.
				self::finish( $delivery, 'cancelled', 0, __( 'Subscription is inactive.', 'fraud-shield' ) );
				continue;
			}

			$event = self::event_row( (int) $delivery['event_id'] );
			if ( ! $event ) {
				self::finish( $delivery, 'cancelled', 0, __( 'Event has been pruned.', 'fraud-shield' ) );
				continue;
			}

			self::attempt( $delivery, $webhook, $event );
		}

		delete_transient( 'fs_webhook_lock' );

		// Housekeeping, cheap and occasional.
		self::prune();
	}

	/**
	 * Send one delivery.
	 *
	 * @param array $delivery Delivery row.
	 * @param array $webhook  Subscription row.
	 * @param array $event    Event row.
	 */
	private static function attempt( $delivery, $webhook, $event ) {
		global $wpdb;

		$settings = FS_REST_API::settings();
		$attempt  = (int) $delivery['attempts'] + 1;

		// Re-validate at send time, not just at subscribe time. DNS is the
		// subscriber's to change: a host that resolved publicly when it was added
		// can point at 127.0.0.1 or a cloud metadata address by the time we
		// deliver, and that rebinding is the whole trick. Checking here shrinks
		// the window to the gap between this lookup and the HTTP client's own.
		$target = self::validate_url( (string) $webhook['url'] );
		if ( is_wp_error( $target ) ) {
			self::finish( $delivery, 'cancelled', 0, $target->get_error_message() );
			self::set_status( (int) $webhook['id'], 'disabled' );
			FS_API_Keys::audit( 'webhook.disabled', '', 'target became unroutable: ' . $webhook['url'] );
			return;
		}

		$body = wp_json_encode(
			self::envelope(
				$event,
				array(
					'id'      => (int) $delivery['id'],
					'attempt' => $attempt,
				),
				! empty( $webhook['hash_pii'] )
			)
		);
		if ( false === $body ) {
			self::finish( $delivery, 'failed', 0, __( 'Payload could not be encoded.', 'fraud-shield' ) );
			return;
		}

		$timestamp = time();
		$response  = wp_remote_post(
			(string) $webhook['url'],
			array(
				'timeout'     => max( 3, min( 30, (int) $settings['hook_timeout'] ) ),
				'redirection' => 0, // A redirect on a signed POST is not a route we follow.
				'sslverify'   => true,
				'blocking'    => true,
				'user-agent'  => 'FraudShield/' . ( defined( 'FS_VERSION' ) ? FS_VERSION : '0' ) . '; ' . home_url( '/' ),
				'headers'     => array(
					'Content-Type'    => 'application/json; charset=utf-8',
					'Accept'          => 'application/json',
					'X-FS-Topic'      => (string) $event['topic'],
					'X-FS-Event'      => 'evt_' . (int) $event['id'],
					'X-FS-Delivery'   => (string) $delivery['id'],
					'X-FS-Attempt'    => (string) $attempt,
					'X-FS-Store'      => FS_REST_API::store_id(),
					'X-FS-Signature'  => self::signature( $body, (string) $webhook['secret'], $timestamp ),
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::retry_or_fail( $delivery, $webhook, 0, $response->get_error_message() );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			self::finish( $delivery, 'delivered', $code, '' );
			$wpdb->update( // phpcs:ignore WordPress.DB
				self::table(),
				array(
					'failures'          => 0,
					'last_status'       => $code,
					'last_error'        => '',
					'last_delivered_at' => self::now(),
				),
				array( 'id' => (int) $webhook['id'] )
			);
			return;
		}

		self::retry_or_fail(
			$delivery,
			$webhook,
			$code,
			/* translators: %d: HTTP status code returned by the subscriber */
			sprintf( __( 'Endpoint returned HTTP %d.', 'fraud-shield' ), $code )
		);
	}

	/**
	 * Back off and try again, or give up.
	 *
	 * @param array  $delivery Delivery row.
	 * @param array  $webhook  Subscription row.
	 * @param int    $code     HTTP status (0 = transport error).
	 * @param string $error    Human error.
	 */
	private static function retry_or_fail( $delivery, $webhook, $code, $error ) {
		global $wpdb;

		$settings = FS_REST_API::settings();
		$max      = max( 1, min( 12, (int) $settings['hook_max_attempts'] ) );
		$attempts = (int) $delivery['attempts'] + 1;

		// Increment in SQL rather than writing back a number read earlier: several
		// of this subscription's deliveries can fail inside one dispatch run, and
		// each has to move the strike count, or the safety valve below never
		// trips on the endpoint that is failing hardest.
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET failures = failures + 1, last_status = %d, last_error = %s WHERE id = %d',
				(int) $code,
				mb_substr( (string) $error, 0, 191 ),
				(int) $webhook['id']
			)
		);

		// A dead endpoint is switched off rather than retried indefinitely: an
		// unreachable subscription that keeps a queue hot is a slow outage for
		// every other subscription behind it.
		$failures = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT failures FROM ' . self::table() . ' WHERE id = %d', (int) $webhook['id'] )
		);
		if ( $failures >= self::FAILURE_LIMIT ) {
			$wpdb->update( self::table(), array( 'status' => 'disabled' ), array( 'id' => (int) $webhook['id'] ) ); // phpcs:ignore WordPress.DB
			FS_API_Keys::audit( 'webhook.disabled', '', (string) $webhook['url'] );
			FS_REST_API::refresh_counts();
		}

		if ( $attempts >= $max ) {
			self::finish( $delivery, 'failed', $code, $error );
			return;
		}

		// Exponential backoff: 1, 2, 4, 8… minutes, capped.
		$delay = min( self::MAX_BACKOFF, MINUTE_IN_SECONDS * pow( 2, $attempts - 1 ) );

		$wpdb->update( // phpcs:ignore WordPress.DB
			self::deliveries_table(),
			array(
				'attempts'        => $attempts,
				'status'          => 'pending',
				'response_code'   => (int) $code,
				'error'           => mb_substr( (string) $error, 0, 191 ),
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
			),
			array( 'id' => (int) $delivery['id'] )
		);
	}

	/**
	 * Close out a delivery.
	 *
	 * @param array  $delivery Delivery row.
	 * @param string $status   'delivered' | 'failed' | 'cancelled'.
	 * @param int    $code     HTTP status.
	 * @param string $error    Human error.
	 */
	private static function finish( $delivery, $status, $code, $error ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::deliveries_table(),
			array(
				'status'        => $status,
				'attempts'      => (int) $delivery['attempts'] + 1,
				'response_code' => (int) $code,
				'error'         => mb_substr( (string) $error, 0, 191 ),
				'delivered_at'  => self::now(),
			),
			array( 'id' => (int) $delivery['id'] )
		);
	}

	/**
	 * Compute the delivery signature.
	 *
	 * The timestamp is inside the signed material, so a captured body cannot be
	 * replayed later under a fresh header. Subscribers should reject anything
	 * older than a few minutes and compare in constant time.
	 *
	 * @param string $body      Exact bytes sent.
	 * @param string $secret    Subscription secret.
	 * @param int    $timestamp Unix seconds.
	 * @return string Header value: t=<ts>,v1=<hex>.
	 */
	public static function signature( $body, $secret, $timestamp ) {
		$mac = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		return 't=' . (int) $timestamp . ',v1=' . $mac;
	}

	/**
	 * One event row.
	 *
	 * @param int $id Event id.
	 * @return array|null
	 */
	private static function event_row( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT id, topic, payload, created_at FROM ' . self::events_table() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Send a test event to one subscription, synchronously, and report what
	 * happened. Staff clicking "test" want the answer now, and this is the one
	 * path where a slow endpoint costs nobody but the person who asked.
	 *
	 * @param int $id Subscription id.
	 * @return array|WP_Error
	 */
	public static function send_test( $id ) {
		$webhook = self::get( (int) $id );
		if ( ! $webhook ) {
			return new WP_Error( 'fs_hook_not_found', __( 'No such subscription.', 'fraud-shield' ), array( 'status' => 404 ) );
		}

		// Same re-validation as a queued delivery: this path is reachable over the
		// API with the manage:webhooks scope, and its HTTP status comes straight
		// back to the caller — which would otherwise make it a probe.
		$target = self::validate_url( (string) $webhook['url'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$settings = FS_REST_API::settings();
		$event    = array(
			'id'         => 0,
			'topic'      => 'test.ping',
			'payload'    => wp_json_encode(
				array(
					'message' => 'Fraud Shield webhook test.',
					'sent_by' => get_current_user_id(),
				)
			),
			'created_at' => self::now(),
		);

		$body = wp_json_encode(
			self::envelope(
				$event,
				array(
					'id'      => 0,
					'attempt' => 1,
				),
				! empty( $webhook['hash_pii'] )
			)
		);

		$timestamp = time();
		$response  = wp_remote_post(
			(string) $webhook['url'],
			array(
				'timeout'     => max( 3, min( 30, (int) $settings['hook_timeout'] ) ),
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => 'FraudShield/' . ( defined( 'FS_VERSION' ) ? FS_VERSION : '0' ) . '; ' . home_url( '/' ),
				'headers'     => array(
					'Content-Type'   => 'application/json; charset=utf-8',
					'X-FS-Topic'     => 'test.ping',
					'X-FS-Event'     => 'evt_test',
					'X-FS-Store'     => FS_REST_API::store_id(),
					'X-FS-Signature' => self::signature( (string) $body, (string) $webhook['secret'], $timestamp ),
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'error' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'ok'     => $code >= 200 && $code < 300,
			'status' => $code,
		);
	}

	/* ===================== Reporting + housekeeping ===================== */

	/**
	 * Recent delivery attempts, newest first.
	 *
	 * @param int $limit Max rows.
	 * @return array[]
	 */
	public static function recent_deliveries( $limit = 50 ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT d.*, w.url FROM ' . self::deliveries_table() . ' d LEFT JOIN ' . self::table() . ' w ON w.id = d.webhook_id ORDER BY d.id DESC LIMIT %d',
				max( 1, min( 200, (int) $limit ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Queue and subscription counters.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		$d = self::deliveries_table();
		return array(
			'subscriptions' => self::active_count(),
			'events'        => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::events_table() ), // phpcs:ignore WordPress.DB
			'pending'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$d} WHERE status = 'pending'" ), // phpcs:ignore WordPress.DB
			'delivered'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$d} WHERE status = 'delivered'" ), // phpcs:ignore WordPress.DB
			'failed'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$d} WHERE status = 'failed'" ), // phpcs:ignore WordPress.DB
		);
	}

	/**
	 * Drop history past the retention window.
	 *
	 * Runs at most once an hour, and deletes in bounded batches so a store that
	 * has accumulated a year of events cannot turn one cron run into a table
	 * lock. Events go last, so nothing deletes an event a delivery still needs.
	 */
	public static function prune() {
		global $wpdb;

		if ( false !== get_transient( 'fs_webhook_pruned' ) ) {
			return;
		}
		set_transient( 'fs_webhook_pruned', 1, HOUR_IN_SECONDS );

		$settings = FS_REST_API::settings();
		$days     = max( 1, min( 365, (int) $settings['retention_days'] ) );
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$deliveries = self::deliveries_table();

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE FROM {$deliveries} WHERE status <> 'pending' AND created_at < %s LIMIT 2000",
				$cutoff
			)
		);

		// Keep any event a delivery is still waiting on, however old it is.
		// Ordering the two statements is not a guarantee — a pending delivery
		// whose backoff outlasts the retention window would otherwise have its
		// payload deleted from under it and be cancelled as "pruned", which is
		// silent data loss for a subscriber that was merely slow to recover.
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'DELETE FROM ' . self::events_table() . " WHERE created_at < %s
				AND id NOT IN ( SELECT event_id FROM {$deliveries} WHERE status = 'pending' )
				LIMIT 2000",
				$cutoff
			)
		);
	}

	/* ===================== Event sources ===================== */

	/**
	 * Classic checkout finished creating an order.
	 *
	 * @param int $order_id Order id.
	 */
	public function on_classic_order( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( $order ) {
			self::emit_order( $order );
		}
	}

	/**
	 * Blocks / Store API checkout finished creating an order.
	 *
	 * @param WC_Order $order Order.
	 */
	public function on_blocks_order( $order ) {
		if ( is_a( $order, 'WC_Order' ) ) {
			self::emit_order( $order );
		}
	}

	/**
	 * Publish the risk verdict FS_Live just recorded on an order.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function emit_order( $order ) {
		$level = (string) $order->get_meta( '_fs_risk_level' );
		if ( '' === $level ) {
			return; // Not scored (observe-only path or an early rejection).
		}

		$reasons = json_decode( (string) $order->get_meta( '_fs_risk_reasons' ), true );
		$email   = FS_Normalizer::email( $order->get_billing_email() );

		$data = array(
			'order_id'   => $order->get_id(),
			'user_id'    => (int) $order->get_customer_id(),
			'email_norm' => $email,
			'name'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'total'      => (float) $order->get_total(),
			'currency'   => $order->get_currency(),
			'gateway'    => $order->get_payment_method(),
			'status'     => $order->get_status(),
			'ip'         => (string) $order->get_customer_ip_address(),
			'risk'       => array(
				'score'   => (int) $order->get_meta( '_fs_risk_score' ),
				'level'   => $level,
				'reasons' => is_array( $reasons ) ? $reasons : array(),
			),
		);

		self::emit( 'order.scored', $data );

		// A second, narrower topic so a subscriber can take only the orders
		// worth a human's attention without filtering the full firehose.
		if ( in_array( $level, array( 'medium', 'high', 'block' ), true ) ) {
			self::emit( 'order.flagged', $data );
		}
	}

	/**
	 * Account flags, observed through WordPress' meta actions so every route
	 * that sets them — admin screen, API, or another plugin — is covered.
	 *
	 * @param int    $meta_id  Meta row id.
	 * @param int    $user_id  User id.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    New value.
	 */
	public function on_user_meta( $meta_id, $user_id, $meta_key, $value ) {
		if ( '_fs_verified' === $meta_key ) {
			self::emit(
				empty( $value ) ? 'customer.unverified' : 'customer.verified',
				array(
					'user_id'    => (int) $user_id,
					'email_norm' => self::user_email( $user_id ),
					'scope'      => 'account',
				)
			);
			return;
		}

		if ( '_fs_gw_restrict' === $meta_key ) {
			self::emit(
				'restriction.applied',
				array(
					'user_id'    => (int) $user_id,
					'email_norm' => self::user_email( $user_id ),
					'gateways'   => is_array( $value ) && isset( $value['gateways'] ) ? array_values( (array) $value['gateways'] ) : array(),
				)
			);
			return;
		}

		// v1.30: the new-account verification hold and the staff ID demand are
		// account flags too, set from checkout, the admin screens and the API
		// alike — so, like the two above, they are observed here rather than
		// emitted at each writer.
		if ( '_fs_hold' === $meta_key ) {
			$hold = is_array( $value ) ? $value : array();
			self::emit(
				'hold.applied',
				array(
					'user_id'    => (int) $user_id,
					'email_norm' => self::user_email( $user_id ),
					'order_id'   => (int) ( $hold['order_id'] ?? 0 ),
					'amount'     => (float) ( $hold['amount'] ?? 0 ),
					'age_days'   => (int) ( $hold['age_days'] ?? 0 ),
				)
			);
			return;
		}

		if ( '_fs_idv_required' === $meta_key ) {
			$rec = is_array( $value ) ? $value : array();
			self::emit(
				'idv.requested',
				array(
					'user_id'      => (int) $user_id,
					'email_norm'   => self::user_email( $user_id ),
					'requested_by' => (int) ( $rec['by'] ?? 0 ),
				)
			);
		}
	}

	/**
	 * Account flags being removed.
	 *
	 * @param array  $meta_ids Meta row ids.
	 * @param int    $user_id  User id.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    Old value.
	 */
	public function on_user_meta_deleted( $meta_ids, $user_id, $meta_key, $value ) {
		if ( '_fs_verified' === $meta_key ) {
			self::emit(
				'customer.unverified',
				array(
					'user_id'    => (int) $user_id,
					'email_norm' => self::user_email( $user_id ),
					'scope'      => 'account',
				)
			);
			return;
		}
		if ( '_fs_gw_restrict' === $meta_key ) {
			self::emit(
				'restriction.released',
				array(
					'user_id'    => (int) $user_id,
					'email_norm' => self::user_email( $user_id ),
				)
			);
			return;
		}
		if ( '_fs_hold' === $meta_key ) {
			// WordPress hands the deletion FILTER value here, not the stored
			// record, so the held order id is not available at this point.
			self::emit(
				'hold.released',
				array(
					'user_id'    => (int) $user_id,
					'email_norm' => self::user_email( $user_id ),
				)
			);
			return;
		}
		if ( '_fs_idv_required' === $meta_key ) {
			self::emit(
				'idv.request_cleared',
				array(
					'user_id'    => (int) $user_id,
					'email_norm' => self::user_email( $user_id ),
				)
			);
		}
	}

	/**
	 * Normalized email for an account.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function user_email( $user_id ) {
		$user = get_userdata( (int) $user_id );
		return $user ? FS_Normalizer::email( $user->user_email ) : '';
	}
}
