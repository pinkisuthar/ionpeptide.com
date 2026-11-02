<?php
/**
 * Manual blocklist by email / phone / address-hash / IP / user-id.
 *
 * A blocklist hit is a deliberate merchant decision and always resolves to a
 * hard block (when enforcement is on), independent of the heuristic score.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Blocklist {

	const TYPES = array( 'email', 'phone', 'address', 'ip', 'user', 'place' );

	/**
	 * Normalize a value for storage/matching based on its type.
	 *
	 * @param string $type  One of self::TYPES.
	 * @param string $value Raw value (for address, pass a normalized address string or its hash).
	 * @return string
	 */
	public static function normalize_value( $type, $value ) {
		switch ( $type ) {
			case 'email':
				return FS_Normalizer::email( $value );
			case 'phone':
				return FS_Normalizer::phone( $value );
			case 'address':
				// Accept either a raw address string or a precomputed 64-char hash.
				$value = trim( (string) $value );
				if ( 64 === strlen( $value ) && ctype_xdigit( $value ) ) {
					return $value;
				}
				return FS_Normalizer::hash( FS_Normalizer::canonicalize( $value ) );
			case 'ip':
				return trim( (string) $value );
			case 'user':
				return (string) (int) $value;
			case 'place':
				// Destination lock key: "scope:seg|seg[|seg]". Re-normalize every
				// segment so an imported/typed key canonicalizes identically to a
				// generated one; return '' on anything malformed (rejects the add).
				$value = trim( (string) $value );
				$colon = strpos( $value, ':' );
				if ( false === $colon ) {
					return '';
				}
				$scope = strtolower( trim( substr( $value, 0, $colon ) ) );
				$rest  = substr( $value, $colon + 1 );
				$segs  = explode( '|', $rest );
				$cc    = isset( $segs[0] ) ? $segs[0] : '';
				switch ( $scope ) {
					case 'state':
						return FS_Normalizer::place_target( 'state', $cc, isset( $segs[1] ) ? $segs[1] : '' );
					case 'city':
						return FS_Normalizer::place_target( 'city', $cc, isset( $segs[1] ) ? $segs[1] : '', isset( $segs[2] ) ? $segs[2] : '' );
					case 'zip':
						return FS_Normalizer::place_target( 'zip', $cc, isset( $segs[1] ) ? $segs[1] : '' );
					case 'street':
					case 'streetcounty':
					case 'building':
						// The street segment of a key is already a normalized street
						// name; place_target() canonicalizes it (idempotent) rather
						// than re-parsing it as a raw address line. Segments:
						// CC|STATE|city-or-county-or-number|street.
						return FS_Normalizer::place_target( $scope, $cc, isset( $segs[1] ) ? $segs[1] : '', isset( $segs[2] ) ? $segs[2] : '', isset( $segs[3] ) ? $segs[3] : '' );
					case 'streetzip':
						// CC|ZIP|street.
						return FS_Normalizer::place_target( 'streetzip', $cc, isset( $segs[1] ) ? $segs[1] : '', '', isset( $segs[2] ) ? $segs[2] : '' );
					case 'geo':
						// lat|lng|metres — no country segment.
						return FS_Normalizer::geo_key( isset( $segs[0] ) ? $segs[0] : '', isset( $segs[1] ) ? $segs[1] : '', isset( $segs[2] ) ? $segs[2] : '' );
					default:
						return '';
				}
			default:
				return trim( (string) $value );
		}
	}

	/**
	 * Add an entry. Returns the number of rows actually inserted (0 when the
	 * entry already existed), so callers can report meaningful counts.
	 *
	 * @param string $type    Type.
	 * @param string $value   Raw value.
	 * @param string $reason  Optional reason.
	 * @param string $message Optional per-entry checkout message.
	 * @return int 0|1
	 */
	public static function add( $type, $value, $reason = '', $message = '' ) {
		global $wpdb;
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return 0;
		}
		$value = self::normalize_value( $type, $value );
		if ( '' === $value || '0' === $value ) {
			return 0;
		}
		$inserted = $wpdb->query( // phpcs:ignore
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table() . ' (type, value, reason, message, added_by, created_at) VALUES (%s, %s, %s, %s, %d, %s)',
				$type,
				$value,
				mb_substr( (string) $reason, 0, 255 ),
				(string) $message,
				get_current_user_id(),
				current_time( 'mysql' )
			)
		);
		return $inserted ? 1 : 0;
	}

	/* ----------------------------------------------------------------- *
	 *  Block actions (v1.6): a single "block" that owns the credentials
	 *  it cascaded onto the blocklist, so it can be reversed in one click.
	 * ----------------------------------------------------------------- */

	/**
	 * Create (or fetch, on collision) a block action. Race-safe via the
	 * UNIQUE (kind,target) key: two concurrent Block clicks resolve to one row.
	 *
	 * @param string $kind    'profile' | 'address' | 'ip' | 'manual' | 'import'.
	 * @param string $target  Normalized identity key (email / ship_hash / IP / "type:value" / import batch).
	 * @param string $title   Human label for the card.
	 * @param string $message Optional whole-action checkout message.
	 * @return int Action id (0 on failure).
	 */
	public static function create_action( $kind, $target, $title = '', $message = '' ) {
		global $wpdb;
		$kind   = substr( (string) $kind, 0, 16 );
		$target = mb_substr( (string) $target, 0, 191 );
		if ( '' === $kind || '' === $target ) {
			return 0;
		}
		$actions  = FS_Data::block_actions_table();
		$inserted = $wpdb->query( // phpcs:ignore
			$wpdb->prepare(
				"INSERT IGNORE INTO {$actions} (kind, target, title, message, created_by, created_at) VALUES (%s, %s, %s, %s, %d, %s)",
				$kind,
				$target,
				mb_substr( (string) $title, 0, 255 ),
				(string) $message,
				get_current_user_id(),
				current_time( 'mysql' )
			)
		);
		if ( $inserted ) {
			// Read insert_id BEFORE anything else can write to the database:
			// emit() inserts its own rows, and wpdb refreshes insert_id on every
			// INSERT. Returning it afterwards handed callers a webhook row id,
			// which then became the owner every cascaded credential was linked
			// to — quietly breaking the one-click unblock this table exists for.
			$action_id = (int) $wpdb->insert_id;

			// One event per merchant decision, raised here rather than in add():
			// a single block cascades onto every credential it owns, and a
			// subscriber wants to hear "this person was blocked" once, not once
			// per email, address and IP the cascade happened to touch.
			FS_Webhooks::emit(
				'customer.blocked',
				array(
					'action_id' => $action_id,
					'kind'      => $kind,
					'target'    => $target,
					'title'     => mb_substr( (string) $title, 0, 255 ),
				)
			);
			return $action_id;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$actions} WHERE kind=%s AND target=%s LIMIT 1", $kind, $target ) ); // phpcs:ignore
	}

	/**
	 * Fetch a single action row by id.
	 *
	 * @param int $id Action id.
	 * @return array|null
	 */
	public static function get_action( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . FS_Data::block_actions_table() . ' WHERE id=%d', (int) $id ), ARRAY_A ); // phpcs:ignore
		return $row ? $row : null;
	}

	/**
	 * The existing action for a (kind,target), or null.
	 *
	 * @param string $kind   Action kind.
	 * @param string $target Normalized target.
	 * @return array|null
	 */
	public static function action_for_target( $kind, $target ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . FS_Data::block_actions_table() . ' WHERE kind=%s AND target=%s LIMIT 1', (string) $kind, mb_substr( (string) $target, 0, 191 ) ), ARRAY_A ); // phpcs:ignore
		return $row ? $row : null;
	}

	/**
	 * How many blocklist entries an action currently owns.
	 *
	 * @param int $action_id Action id.
	 * @return int
	 */
	public static function action_entry_count( $action_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . FS_Data::block_action_entries_table() . ' WHERE action_id=%d', (int) $action_id ) ); // phpcs:ignore
	}

	/**
	 * Batch button-state lookup for a list of targets of one kind. One query,
	 * no N+1 — drives the toggle label and the blast-radius count on dashboards.
	 *
	 * @param string $kind    Action kind.
	 * @param array  $targets Normalized targets.
	 * @return array [ target => [ 'id' => int, 'entries' => int ] ]
	 */
	public static function actions_for_targets( $kind, $targets ) {
		global $wpdb;
		$targets = array_values( array_unique( array_filter( array_map( 'strval', (array) $targets ) ) ) );
		$out     = array();
		if ( empty( $targets ) ) {
			return $out;
		}
		$actions = FS_Data::block_actions_table();
		$je      = FS_Data::block_action_entries_table();
		$ph      = implode( ',', array_fill( 0, count( $targets ), '%s' ) );
		$params  = array_merge( array( (string) $kind ), $targets );
		$rows    = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT a.id, a.target, COUNT(j.entry_id) entries
				FROM {$actions} a
				LEFT JOIN {$je} j ON j.action_id = a.id
				WHERE a.kind=%s AND a.target IN ({$ph})
				GROUP BY a.id, a.target",
				$params
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['target'] ] = array(
				'id'      => (int) $r['id'],
				'entries' => (int) $r['entries'],
			);
		}
		return $out;
	}

	/**
	 * Add a blocklist entry and link it to a block action. Unlike add(), this
	 * returns the id of the row whether it was newly created or already existed,
	 * and records ownership in the junction table (INSERT IGNORE, so a credential
	 * shared by several actions is owned by all of them).
	 *
	 * @param string $type      Blocklist type.
	 * @param string $value     Raw value.
	 * @param string $reason    Reason.
	 * @param string $message   Per-entry message.
	 * @param int    $action_id Owning action id.
	 * @return int 1 when a NEW blocklist row was created, else 0 (link still made).
	 */
	public static function add_and_link( $type, $value, $reason, $message, $action_id ) {
		global $wpdb;
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return 0;
		}
		$value = self::normalize_value( $type, $value );
		if ( '' === $value || '0' === $value ) {
			return 0;
		}
		$table    = self::table();
		$inserted = $wpdb->query( // phpcs:ignore
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (type, value, reason, message, added_by, created_at) VALUES (%s, %s, %s, %s, %d, %s)",
				$type,
				$value,
				mb_substr( (string) $reason, 0, 255 ),
				(string) $message,
				get_current_user_id(),
				current_time( 'mysql' )
			)
		);
		if ( $inserted ) {
			$entry_id = (int) $wpdb->insert_id;
			$is_new   = 1;
		} else {
			$entry_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE type=%s AND value=%s LIMIT 1", $type, $value ) ); // phpcs:ignore
			$is_new   = 0;
		}
		if ( $entry_id > 0 && (int) $action_id > 0 ) {
			$je = FS_Data::block_action_entries_table();
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$je} (action_id, entry_id) VALUES (%d, %d)", (int) $action_id, $entry_id ) ); // phpcs:ignore
		}
		return $is_new;
	}

	/**
	 * Reverse a block action: remove its ownership links, then delete the
	 * credentials it owned. Legacy ungrouped rows are never touched because
	 * they were never linked here.
	 *
	 * What happens to a credential that ANOTHER action also owns depends on
	 * $release_shared:
	 *
	 *  - false (default): the credential survives, still enforced by its other
	 *    owner. Right for removing one card on the Blocklist page — deleting a
	 *    shared credential there would silently punch a hole in a different
	 *    block a human deliberately created.
	 *
	 *  - true: the credential is removed EVERYWHERE unless it is the explicit
	 *    TARGET of another standing block — a blocked address's own hash, a
	 *    blocked IP's own address, a manually blocked value, another blocked
	 *    profile's own email, anything on a place lock or an imported list.
	 *    This is what "unblock this profile" has to mean on a mature store:
	 *    months of cluster blocks and auto-extend cross-own nearly every
	 *    credential, and under the default rule a profile unblock visibly
	 *    removed nothing — the block button looked broken. Releasing the
	 *    person while leaving other blocks' own targets standing matches what
	 *    the click is saying: THIS identity may come back; that address / that
	 *    IP / that manual entry are still blocked in their own right, and a
	 *    re-sync or the next matching order re-links whatever belongs to them.
	 *
	 * @param int  $action_id      Action id.
	 * @param bool $release_shared Remove shared credentials too, sparing only
	 *                             other actions' own targets.
	 * @return int Number of blocklist rows actually removed.
	 */
	public static function unblock_action( $action_id, $release_shared = false ) {
		global $wpdb;
		$action_id = (int) $action_id;
		if ( $action_id <= 0 ) {
			return 0;
		}
		$table   = self::table();
		$actions = FS_Data::block_actions_table();
		$je      = FS_Data::block_action_entries_table();

		// Read the card before it goes: the reversal event names it.
		$action = self::get_action( $action_id );

		$entry_ids = array_values( array_filter( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT entry_id FROM {$je} WHERE action_id=%d", $action_id ) ) ) ) ); // phpcs:ignore

		// Drop this action's ownership first, so the ownership tests below see
		// only the OTHER actions.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$je} WHERE action_id=%d", $action_id ) ); // phpcs:ignore

		$removed = 0;
		if ( ! empty( $entry_ids ) ) {
			$in = implode( ',', $entry_ids ); // ints only — safe to inline.

			if ( ! $release_shared ) {
				$removed = (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in}) AND NOT EXISTS (SELECT 1 FROM {$je} j WHERE j.entry_id = {$table}.id)" ); // phpcs:ignore
			} else {
				// Which of this action's credentials are ANCHORED elsewhere —
				// i.e. are the very thing another live action is about, rather
				// than something a cascade merely swept in.
				$owned = $wpdb->get_results( "SELECT b.id, b.type, b.value, a.kind, a.target FROM {$table} b INNER JOIN {$je} j ON j.entry_id = b.id INNER JOIN {$actions} a ON a.id = j.action_id WHERE b.id IN ({$in})", ARRAY_A ); // phpcs:ignore

				$anchored = array();
				foreach ( (array) $owned as $row ) {
					$kind   = (string) $row['kind'];
					$type   = (string) $row['type'];
					$value  = (string) $row['value'];
					$target = (string) $row['target'];

					$is_anchor = ( 'manual' === $kind && $target === $type . ':' . $value )
						|| ( 'address' === $kind && 'address' === $type && $target === $value )
						|| ( 'ip' === $kind && 'ip' === $type && $target === $value )
						|| ( 'profile' === $kind && 'email' === $type && $target === $value )
						// Place locks never cascade (their entry IS their
						// target) and imported lists were chosen line by line.
						// Unknown kinds anchor too: never silently weaken a
						// block this code does not understand.
						|| ! in_array( $kind, array( 'manual', 'address', 'ip', 'profile' ), true );

					if ( $is_anchor ) {
						$anchored[ (int) $row['id'] ] = true;
					}
				}

				$doomed = array_values( array_diff( $entry_ids, array_keys( $anchored ) ) );
				if ( ! empty( $doomed ) ) {
					$doomed_in = implode( ',', array_map( 'intval', $doomed ) );
					// Release from every remaining owner, then delete.
					$wpdb->query( "DELETE FROM {$je} WHERE entry_id IN ({$doomed_in})" ); // phpcs:ignore
					$removed = (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$doomed_in})" ); // phpcs:ignore
				}
			}
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$actions} WHERE id=%d", $action_id ) ); // phpcs:ignore

		// The counterpart of customer.blocked: one event per reversed decision,
		// whichever screen or API call reversed it, so a subscriber mirroring
		// the blocklist hears about releases as well as blocks.
		if ( $action ) {
			FS_Webhooks::emit(
				'customer.unblocked',
				array(
					'action_id'       => $action_id,
					'kind'            => (string) $action['kind'],
					'target'          => (string) $action['target'],
					'title'           => (string) $action['title'],
					'entries_removed' => $removed,
					'release_shared'  => (bool) $release_shared,
				)
			);
		}

		return $removed;
	}

	/**
	 * The credentials one block card currently owns, as blocklist rows.
	 *
	 * @param int $action_id Action id.
	 * @return array
	 */
	public static function action_entries( $action_id ) {
		global $wpdb;
		$action_id = (int) $action_id;
		if ( $action_id <= 0 ) {
			return array();
		}
		$table = self::table();
		$je    = FS_Data::block_action_entries_table();
		return (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT b.* FROM {$je} j INNER JOIN {$table} b ON b.id = j.entry_id WHERE j.action_id = %d ORDER BY FIELD(b.type,'email','phone','ip','user','address','place'), b.value",
				$action_id
			),
			ARRAY_A
		);
	}

	/**
	 * Re-run the cascade for an existing action to sweep in credentials that
	 * appeared after the original block. Dispatches on the action kind.
	 *
	 * @param int $id Action id.
	 * @return array { action_id:int, added:int }
	 */
	public static function resync_action( $id ) {
		$action = self::get_action( $id );
		if ( ! $action ) {
			return array(
				'action_id' => 0,
				'added'     => 0,
			);
		}
		switch ( $action['kind'] ) {
			case 'address':
			case 'ip':
				$result = self::block_cluster( $action['kind'], $action['target'] );
				break;
			case 'profile':
				$result = self::block_identity( $action['target'] );
				break;
			default:
				// manual / import / place actions own a fixed set and intentionally
				// sweep nothing. A 'place' lock especially must never cascade — it
				// blocks a destination, not the people who ship there.
				return array(
					'action_id' => (int) $id,
					'added'     => 0,
				);
		}
		if ( (int) ( $result['added'] ?? 0 ) > 0 ) {
			self::emit_extended( $action, (int) $result['added'], 'resync', 0, array() );
		}
		return $result;
	}

	/**
	 * Tell subscribers that an existing block card grew (v1.30): the cascade
	 * was re-run, or a new order matching the card had its fresh credentials
	 * linked in. customer.blocked fires once per card, so without this a
	 * subscriber mirroring the list would never hear about the credentials a
	 * card sweeps in later.
	 *
	 * @param array  $action   Block action row.
	 * @param int    $added    Credentials newly placed on the list.
	 * @param string $source   'auto_extend' | 'resync'.
	 * @param int    $order_id The order that triggered an auto-extension (0 for a re-sync).
	 * @param array  $types    Credential types linked (auto-extension only).
	 */
	public static function emit_extended( $action, $added, $source, $order_id = 0, $types = array() ) {
		if ( ! class_exists( 'FS_Webhooks' ) || ! is_array( $action ) ) {
			return;
		}
		FS_Webhooks::emit(
			'blocklist.extended',
			array(
				'action_id' => (int) ( $action['id'] ?? 0 ),
				'kind'      => (string) ( $action['kind'] ?? '' ),
				'target'    => (string) ( $action['target'] ?? '' ),
				'title'     => (string) ( $action['title'] ?? '' ),
				'added'     => (int) $added,
				'source'    => 'resync' === $source ? 'resync' : 'auto_extend',
				'order_id'  => (int) $order_id,
				'types'     => array_values( array_unique( array_filter( array_map( 'strval', (array) $types ) ) ) ),
			)
		);
	}

	/**
	 * Block a single credential as its own grouped (kind='manual') action, so it
	 * shows on the blocklist page as a removable card instead of landing in
	 * "Ungrouped". Used by the Add-entry form and the order meta box.
	 *
	 * @param string $type    Blocklist type.
	 * @param string $value   Raw value.
	 * @param string $reason  Reason.
	 * @param string $message Per-entry checkout message.
	 * @return array { action_id:int, added:int }
	 */
	public static function block_manual( $type, $value, $reason = '', $message = '' ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return array(
				'action_id' => 0,
				'added'     => 0,
			);
		}
		$norm = self::normalize_value( $type, $value );
		if ( '' === $norm || '0' === $norm ) {
			return array(
				'action_id' => 0,
				'added'     => 0,
			);
		}
		$action_id = self::create_action( 'manual', $type . ':' . $norm, self::manual_title( $type, $norm ) );
		$added     = self::add_and_link( $type, $norm, $reason, $message, $action_id );
		return array(
			'action_id' => $action_id,
			'added'     => $added,
		);
	}

	/**
	 * Card title for a manual single-credential action.
	 *
	 * @param string $type  Blocklist type.
	 * @param string $value Normalized value.
	 * @return string
	 */
	private static function manual_title( $type, $value ) {
		$labels = array(
			'email'   => __( 'Email', 'fraud-shield' ),
			'phone'   => __( 'Phone', 'fraud-shield' ),
			'address' => __( 'Address', 'fraud-shield' ),
			'ip'      => __( 'IP', 'fraud-shield' ),
			'user'    => __( 'User ID', 'fraud-shield' ),
		);
		$label = isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( $type );
		$shown = ( 'address' === $type && 64 === strlen( (string) $value ) ) ? substr( $value, 0, 12 ) . '…' : $value;
		return mb_substr( $label . ': ' . $shown, 0, 255 );
	}

	/**
	 * Representative human title for an address cluster action.
	 *
	 * @param string $hash ship_hash.
	 * @return string
	 */
	private static function address_title( $hash ) {
		global $wpdb;
		$txt = trim( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ship_text FROM ' . FS_Data::index_table() . " WHERE ship_hash=%s AND ship_text<>'' LIMIT 1", $hash ) ) ); // phpcs:ignore
		if ( '' !== $txt ) {
			/* translators: %s: shipping address text. */
			return mb_substr( sprintf( __( '%s (address)', 'fraud-shield' ), $txt ), 0, 255 );
		}
		/* translators: %s: short address hash prefix. */
		return mb_substr( sprintf( __( '%s… (address)', 'fraud-shield' ), substr( (string) $hash, 0, 12 ) ), 0, 255 );
	}

	/**
	 * Human title for a customer profile action ("Name <email>" or the email).
	 *
	 * @param string $email Normalized email.
	 * @return string
	 */
	private static function profile_title( $email ) {
		$summary = FS_Data::profile_summary( $email );
		$name    = isset( $summary['customer_name'] ) ? trim( (string) $summary['customer_name'] ) : '';
		if ( '' !== $name ) {
			return mb_substr( $name . ' <' . $email . '>', 0, 255 );
		}
		return mb_substr( $email, 0, 255 );
	}

	/**
	 * Create (or fetch) a destination lock: a single 'place' blocklist entry that
	 * blocks any checkout SHIPPING TO the locked scope.
	 *
	 * SAFETY: a place lock NEVER cascades and NEVER auto-extends. It adds exactly
	 * one entry (the place key) — no cluster query, no credential cascade — and
	 * FS_Live::maybe_extend_blocks() skips 'place' matches. Blocking a city must
	 * never convert into identity blocks for everyone who ships there.
	 *
	 * A STREET lock can carry explicit geographic boundaries (v1.31): extra
	 * city names, ZIP codes and counties the same road runs through. Each
	 * becomes one more 'place' entry under the SAME card — "NW 66th St within
	 * Miami, Doral, Medley; within 33166, 33122, 33172; within Miami-Dade" —
	 * so an order typed with any of those destinations meets the lock, while
	 * the identically named road in another county does not. Unlocking the
	 * card removes them all. A BUILDING lock ("8020 NW 66th St, FL") is one
	 * house number on one street anywhere in the state: every unit, mailbox,
	 * forwarding account, business and recipient in it.
	 *
	 * @param string $scope      'city' | 'state' | 'zip' | 'street' | 'streetzip' | 'streetcounty' | 'building'.
	 * @param string $country    Country code.
	 * @param string $a          State/region (city, state, street, streetcounty, building) or postcode (zip, streetzip).
	 * @param string $b          City (city, street), county (streetcounty) or house number (building).
	 * @param string $reason     Reason recorded on the entry.
	 * @param string $message    Optional whole-lock checkout message (set on creation only).
	 *                           A street or building lock with no message gets street_default_message().
	 * @param string $c          Normalized street name (street-level scopes; see FS_Normalizer::street()).
	 * @param array  $boundaries Street scope only: { cities: string[], zips: string[], counties: string[] }.
	 * @return array { action_id:int, added:int }
	 */
	public static function block_place( $scope, $country, $a, $b = '', $reason = '', $message = '', $c = '', $boundaries = array() ) {
		$target = FS_Normalizer::place_target( $scope, $country, $a, $b, $c );
		if ( '' === $target ) {
			return array(
				'action_id' => 0,
				'added'     => 0,
			);
		}
		$street_level = in_array( $scope, array( 'street', 'streetzip', 'streetcounty', 'building' ), true );
		if ( '' === $reason ) {
			if ( 'building' === $scope ) {
				$reason = __( 'Building lock', 'fraud-shield' );
			} else {
				$reason = $street_level ? __( 'Street lock', 'fraud-shield' ) : __( 'Destination lock', 'fraud-shield' );
			}
		}
		// A street or building lock speaks with its own voice by default. The
		// message lives on the action, so the card's "Save block message" edits
		// it like any other block's.
		if ( $street_level && '' === trim( (string) $message ) ) {
			$message = self::street_default_message();
		}
		$action_id = self::create_action( 'place', $target, self::place_title( $scope, $country, $a, $b, $c ), $message );
		$added     = self::add_and_link( 'place', $target, $reason, '', $action_id );

		// The street's explicit boundaries, each one more key under this card.
		if ( 'street' === $scope && (int) $action_id > 0 && ! empty( $boundaries ) ) {
			foreach ( (array) ( $boundaries['cities'] ?? array() ) as $city ) {
				$k = FS_Normalizer::place_target( 'street', $country, $a, $city, $c );
				if ( '' !== $k && $k !== $target ) {
					$added += self::add_and_link( 'place', $k, $reason . ' ' . __( '(same road, another city name)', 'fraud-shield' ), '', $action_id );
				}
			}
			foreach ( (array) ( $boundaries['zips'] ?? array() ) as $zip ) {
				$k = FS_Normalizer::place_target( 'streetzip', $country, $zip, '', $c );
				if ( '' !== $k ) {
					$added += self::add_and_link( 'place', $k, $reason . ' ' . __( '(same road, by ZIP)', 'fraud-shield' ), '', $action_id );
				}
			}
			foreach ( (array) ( $boundaries['counties'] ?? array() ) as $county ) {
				$k = FS_Normalizer::place_target( 'streetcounty', $country, $a, $county, $c );
				if ( '' !== $k ) {
					$added += self::add_and_link( 'place', $k, $reason . ' ' . __( '(same road, by county — reached through address validation)', 'fraud-shield' ), '', $action_id );
				}
			}
		}
		return array(
			'action_id' => (int) $action_id,
			'added'     => $added,
		);
	}

	/**
	 * The block card that owns a place key, whether the key is the card's own
	 * target or one of a street lock's boundary entries. What the Geography
	 * rows, the address page's buttons and the lock toggle resolve through,
	 * so "NW 66th St, Doral" reads as locked when it is covered by the card
	 * created from the Miami row, and unlocking from either row releases the
	 * whole street.
	 *
	 * @param string $key Normalized place key.
	 * @return array|null Action row, or null.
	 */
	public static function place_action_for_key( $key ) {
		global $wpdb;
		$key = (string) $key;
		if ( '' === $key ) {
			return null;
		}
		$own = self::action_for_target( 'place', $key );
		if ( $own ) {
			return $own;
		}
		$aid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT j.action_id FROM ' . self::table() . ' b INNER JOIN ' . FS_Data::block_action_entries_table() . " j ON j.entry_id = b.id WHERE b.type = 'place' AND b.value = %s ORDER BY j.action_id ASC LIMIT 1", $key ) ); // phpcs:ignore
		if ( $aid <= 0 ) {
			return null;
		}
		return self::get_action( $aid );
	}

	/**
	 * Batch form of place_action_for_key() for a page of geography rows: which
	 * of the given place keys are on the blocklist, and under which card.
	 *
	 * @param array $keys Normalized place keys.
	 * @return array [ key => [ 'id' => action id (0 for an ungrouped entry), 'entries' => int ] ]
	 */
	public static function place_locked_keys( $keys ) {
		global $wpdb;
		$keys = array_values( array_unique( array_filter( array_map( 'strval', (array) $keys ) ) ) );
		$out  = array();
		if ( empty( $keys ) ) {
			return $out;
		}
		$ph   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$rows = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				'SELECT b.value, MIN(j.action_id) action_id FROM ' . self::table() . ' b LEFT JOIN ' . FS_Data::block_action_entries_table() . " j ON j.entry_id = b.id WHERE b.type = 'place' AND b.value IN ({$ph}) GROUP BY b.value",
				$keys
			),
			ARRAY_A
		);
		$ids = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( (array) $rows, 'action_id' ) ) ) ) );
		$cnt = array();
		if ( ! empty( $ids ) ) {
			$ph2 = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT action_id, COUNT(*) c FROM ' . FS_Data::block_action_entries_table() . " WHERE action_id IN ({$ph2}) GROUP BY action_id", $ids ), ARRAY_A ) as $r ) { // phpcs:ignore
				$cnt[ (int) $r['action_id'] ] = (int) $r['c'];
			}
		}
		foreach ( (array) $rows as $r ) {
			$aid                        = (int) $r['action_id'];
			$out[ (string) $r['value'] ] = array(
				'id'      => $aid,
				'entries' => $aid > 0 ? ( $cnt[ $aid ] ?? 1 ) : 1,
			);
		}
		return $out;
	}

	/**
	 * Whether any street-level destination lock exists (street, streetzip,
	 * streetcounty or building). Address validation at checkout is only worth
	 * a request when there is one of these to resolve against, so this gates
	 * it. One indexed LIMIT 1 query on a small table; deliberately not cached,
	 * so a lock added or removed mid-request is seen at once.
	 *
	 * @return bool
	 */
	public static function has_street_level_locks( $country = '', $state = '', $postcode = '' ) {
		global $wpdb;
		$cc = strtoupper( trim( (string) $country ) );
		$st = FS_Normalizer::region( $state );
		if ( '' === $cc || '' === $st ) {
			return (bool) $wpdb->get_var( 'SELECT 1 FROM ' . self::table() . " WHERE type = 'place' AND (value LIKE 'street:%' OR value LIKE 'streetzip:%' OR value LIKE 'streetcounty:%' OR value LIKE 'building:%') LIMIT 1" ); // phpcs:ignore
		}
		// Scoped to the address's own state (and, for ZIP-bounded locks, its
		// three-digit ZIP area): a lock in Florida is no reason to geocode a
		// Texan's checkout.
		$patterns = array(
			$wpdb->esc_like( 'street:' . $cc . '|' . $st . '|' ) . '%',
			$wpdb->esc_like( 'streetcounty:' . $cc . '|' . $st . '|' ) . '%',
			$wpdb->esc_like( 'building:' . $cc . '|' . $st . '|' ) . '%',
		);
		$zp = FS_Normalizer::zip_base( $cc, $postcode );
		if ( strlen( $zp ) >= 3 ) {
			$patterns[] = $wpdb->esc_like( 'streetzip:' . $cc . '|' . substr( $zp, 0, 3 ) ) . '%';
		}
		$where = implode( ' OR ', array_fill( 0, count( $patterns ), 'value LIKE %s' ) );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . " WHERE type = 'place' AND ({$where}) LIMIT 1", $patterns ) ); // phpcs:ignore
	}

	/**
	 * Whether a street-level lock on THIS street name stands anywhere in the
	 * state — outside the boundary the order's typed destination fell in. The
	 * "potential match" an unverifiable address is held against: the typed
	 * city and ZIP may be wrong (or rotated), and if the road they name is a
	 * locked road somewhere in the state, the order is stopped for correction
	 * rather than waved through on the strength of text nobody confirmed.
	 *
	 * @param string $country Country code.
	 * @param string $state   State/region.
	 * @param string $street  Normalized street name.
	 * @return bool
	 */
	public static function shadow_place_hit( $country, $state, $street, $postcode = '' ) {
		global $wpdb;
		$cc = strtoupper( trim( (string) $country ) );
		$st = FS_Normalizer::region( $state );
		$sn = FS_Normalizer::align_street( $street );
		if ( '' === $cc || '' === $st || '' === $sn ) {
			return false;
		}
		$tail     = '|' . $wpdb->esc_like( $sn );
		$patterns = array(
			$wpdb->esc_like( 'street:' . $cc . '|' . $st . '|' ) . '%' . $tail,
			$wpdb->esc_like( 'streetcounty:' . $cc . '|' . $st . '|' ) . '%' . $tail,
		);
		// A ZIP-bounded lock carries no state, so it is a potential match only
		// for a typed ZIP in the same three-digit area — not for the same road
		// name a thousand miles away.
		$zp = FS_Normalizer::zip_base( $cc, $postcode );
		if ( strlen( $zp ) >= 3 ) {
			$patterns[] = $wpdb->esc_like( 'streetzip:' . $cc . '|' . substr( $zp, 0, 3 ) ) . '%' . $tail;
		}
		$where = implode( ' OR ', array_fill( 0, count( $patterns ), 'value LIKE %s' ) );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . " WHERE type = 'place' AND ({$where}) LIMIT 1", $patterns ) ); // phpcs:ignore
	}

	/**
	 * The checkout message a street lock carries unless the merchant writes
	 * their own at creation, or edits it on the card afterwards.
	 *
	 * @return string
	 */
	public static function street_default_message() {
		return __( 'You have been blocked from checkout, please contact customer service.', 'fraud-shield' );
	}

	/**
	 * Pretty card title for a destination lock (e.g. "Miami, FL (US) — city lock").
	 *
	 * @param string $scope   'city' | 'state' | 'zip' | 'street'.
	 * @param string $country Country code.
	 * @param string $a       State/region or ZIP prefix.
	 * @param string $b       City.
	 * @param string $c       Normalized street name.
	 * @return string
	 */
	private static function place_title( $scope, $country, $a, $b = '', $c = '' ) {
		$cc = strtoupper( trim( (string) $country ) );
		switch ( $scope ) {
			case 'street':
				/* translators: 1: street name, 2: city, 3: state/region, 4: country code */
				return mb_substr( sprintf( __( '%1$s, %2$s, %3$s (%4$s) — street lock', 'fraud-shield' ), ucwords( FS_Normalizer::align_street( $c ) ), ucwords( FS_Normalizer::city( $b ) ), FS_Normalizer::region( $a ), $cc ), 0, 255 );
			case 'streetzip':
				/* translators: 1: street name, 2: ZIP code, 3: country code */
				return mb_substr( sprintf( __( '%1$s in ZIP %2$s (%3$s) — street lock', 'fraud-shield' ), ucwords( FS_Normalizer::align_street( $c ) ), FS_Normalizer::zip_base( $cc, $a ), $cc ), 0, 255 );
			case 'streetcounty':
				/* translators: 1: street name, 2: county, 3: state/region, 4: country code */
				return mb_substr( sprintf( __( '%1$s, %2$s County, %3$s (%4$s) — street lock', 'fraud-shield' ), ucwords( FS_Normalizer::align_street( $c ) ), ucwords( FS_Normalizer::county( $b ) ), FS_Normalizer::region( $a ), $cc ), 0, 255 );
			case 'building':
				/* translators: 1: house number, 2: street name, 3: state/region, 4: country code */
				return mb_substr( sprintf( __( '%1$s %2$s, %3$s (%4$s) — building lock, every unit', 'fraud-shield' ), preg_replace( '/\D+/', '', (string) $b ), ucwords( FS_Normalizer::align_street( $c ) ), FS_Normalizer::region( $a ), $cc ), 0, 255 );
			case 'geo':
				/* translators: 1: radius in miles, 2: latitude, 3: longitude */
				return mb_substr( sprintf( __( 'Within %1$s mi of %2$s, %3$s — area lock', 'fraud-shield' ), rtrim( rtrim( number_format( (float) $c / 1609.344, 2, '.', '' ), '0' ), '.' ), sprintf( '%.5f', (float) $a ), sprintf( '%.5f', (float) $b ) ), 0, 255 );
			case 'state':
				/* translators: 1: state/region, 2: country code */
				return mb_substr( sprintf( __( '%1$s (%2$s) — state lock', 'fraud-shield' ), FS_Normalizer::region( $a ), $cc ), 0, 255 );
			case 'city':
				/* translators: 1: city, 2: state/region, 3: country code */
				return mb_substr( sprintf( __( '%1$s, %2$s (%3$s) — city lock', 'fraud-shield' ), ucwords( FS_Normalizer::city( $b ) ), FS_Normalizer::region( $a ), $cc ), 0, 255 );
			case 'zip':
				/* translators: 1: ZIP prefix, 2: country code */
				return mb_substr( sprintf( __( 'ZIP %1$s* (%2$s) — prefix lock', 'fraud-shield' ), FS_Normalizer::postcode( $a ), $cc ), 0, 255 );
			default:
				return '';
		}
	}

	/**
	 * Block an entire cluster: the anchor itself plus EVERY credential Fraud
	 * Shield has seen associated with it (emails, phones, IPs, user accounts —
	 * and, for an address, the loose street+ZIP variant). One click on a drop
	 * house locks out every identity that ever used it, including future
	 * accounts re-entering the same details.
	 *
	 * If an action already exists for this cluster, it is adopted and re-synced
	 * (new credentials seen since the original block get linked) rather than
	 * duplicated. Pre-1.6 ungrouped rows encountered by the cascade are linked
	 * to the action, converting a legacy block into a fully reversible one.
	 *
	 * @param string $type    'address' (ship_hash) or 'ip'.
	 * @param string $key     ship_hash or IP value.
	 * @param string $reason  Reason recorded on each entry.
	 * @param string $message Optional whole-action checkout message (set on creation only).
	 * @return array { action_id:int, added:int }
	 */
	public static function block_cluster( $type, $key, $reason = '', $message = '' ) {
		global $wpdb;
		$key = trim( (string) $key );
		if ( '' === $key || ! in_array( $type, array( 'address', 'ip' ), true ) ) {
			return array(
				'action_id' => 0,
				'added'     => 0,
			);
		}

		$index = FS_Data::index_table();
		$added = 0;

		if ( 'address' === $type ) {
			if ( '' === $reason ) {
				$reason = __( 'Blocked address cluster', 'fraud-shield' );
			}
			$action_id = self::create_action( 'address', $key, self::address_title( $key ), $message );
			$added    += self::add_and_link( 'address', $key, $reason, '', $action_id );

			// Rows this address still carries under an older parser are
			// re-derived from their live orders FIRST, so a cascade clicked
			// while the background backfill is running plants the current
			// door key, never a stale one. Free once the backfill is done.
			FS_Data::geo_refresh_orders( $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$index} WHERE ship_hash = %s AND geo_scheme < %d ORDER BY order_date DESC, id DESC LIMIT 100", $key, FS_Data::GEO_SCHEME ) ) ); // phpcs:ignore

			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT email_norm, phone_norm, ip, user_id, ship_loose_hash, ship_door_hash FROM {$index} WHERE ship_hash = %s", $key ), ARRAY_A ); // phpcs:ignore
			foreach ( (array) $rows as $r ) {
				if ( ! empty( $r['ship_loose_hash'] ) ) {
					$added += self::add_and_link( 'address', $r['ship_loose_hash'], $reason . ' ' . __( '(street/ZIP variant)', 'fraud-shield' ), '', $action_id );
				}
				if ( ! empty( $r['ship_door_hash'] ) ) {
					$added += self::add_and_link( 'address', $r['ship_door_hash'], $reason . ' ' . __( '(door key — unit, ZIP and city blind)', 'fraud-shield' ), '', $action_id );
				}
				if ( ! empty( $r['email_norm'] ) ) {
					$added += self::add_and_link( 'email', $r['email_norm'], $reason, '', $action_id );
				}
				if ( ! empty( $r['phone_norm'] ) ) {
					$added += self::add_and_link( 'phone', $r['phone_norm'], $reason, '', $action_id );
				}
				if ( ! empty( $r['ip'] ) ) {
					$added += self::add_and_link( 'ip', $r['ip'], $reason, '', $action_id );
				}
				if ( (int) $r['user_id'] > 0 ) {
					$added += self::add_and_link( 'user', (string) (int) $r['user_id'], $reason, '', $action_id );
				}
			}
		} else {
			if ( '' === $reason ) {
				$reason = __( 'Blocked IP cluster', 'fraud-shield' );
			}
			$action_id = self::create_action( 'ip', $key, $key, $message );
			$added    += self::add_and_link( 'ip', $key, $reason, '', $action_id );

			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT email_norm, phone_norm, user_id FROM {$index} WHERE ip = %s", $key ), ARRAY_A ); // phpcs:ignore
			foreach ( (array) $rows as $r ) {
				if ( ! empty( $r['email_norm'] ) ) {
					$added += self::add_and_link( 'email', $r['email_norm'], $reason, '', $action_id );
				}
				if ( ! empty( $r['phone_norm'] ) ) {
					$added += self::add_and_link( 'phone', $r['phone_norm'], $reason, '', $action_id );
				}
				if ( (int) $r['user_id'] > 0 ) {
					$added += self::add_and_link( 'user', (string) (int) $r['user_id'], $reason, '', $action_id );
				}
			}
		}

		return array(
			'action_id' => (int) $action_id,
			'added'     => $added,
		);
	}

	/**
	 * Block an entire customer identity: the email plus every phone, IP,
	 * shipping address (exact + loose), and user account it has ever used.
	 * After this, none of those credentials can check out — including a brand
	 * new account re-entering any of them.
	 *
	 * If an action already exists for this identity it is adopted and re-synced.
	 *
	 * @param string $email   Email (raw or normalized).
	 * @param string $reason  Reason recorded on each entry.
	 * @param string $message Optional whole-action checkout message (set on creation only).
	 * @return array { action_id:int, added:int }
	 */
	public static function block_identity( $email, $reason = '', $message = '' ) {
		global $wpdb;
		$email = FS_Normalizer::email( $email );
		if ( '' === $email ) {
			return array(
				'action_id' => 0,
				'added'     => 0,
			);
		}
		if ( '' === $reason ) {
			$reason = __( 'Blocked customer profile', 'fraud-shield' );
		}

		$action_id = self::create_action( 'profile', $email, self::profile_title( $email ), $message );
		$added     = self::add_and_link( 'email', $email, $reason, '', $action_id );

		// Same as block_cluster(): the identity's rows still on an older
		// parser are re-derived before their door hashes are read.
		FS_Data::geo_refresh_orders( $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM ' . FS_Data::index_table() . ' WHERE email_norm = %s AND geo_scheme < %d ORDER BY order_date DESC, id DESC LIMIT 100', $email, FS_Data::GEO_SCHEME ) ) ); // phpcs:ignore

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT phone_norm, ip, ship_hash, ship_loose_hash, ship_door_hash, user_id FROM ' . FS_Data::index_table() . ' WHERE email_norm = %s', $email ), ARRAY_A ); // phpcs:ignore
		foreach ( (array) $rows as $r ) {
			if ( ! empty( $r['phone_norm'] ) ) {
				$added += self::add_and_link( 'phone', $r['phone_norm'], $reason, '', $action_id );
			}
			if ( ! empty( $r['ip'] ) ) {
				$added += self::add_and_link( 'ip', $r['ip'], $reason, '', $action_id );
			}
			if ( ! empty( $r['ship_hash'] ) ) {
				$added += self::add_and_link( 'address', $r['ship_hash'], $reason, '', $action_id );
			}
			if ( ! empty( $r['ship_loose_hash'] ) ) {
				$added += self::add_and_link( 'address', $r['ship_loose_hash'], $reason . ' ' . __( '(street/ZIP variant)', 'fraud-shield' ), '', $action_id );
			}
			// The door key: what stands when the fraudster re-enters the same
			// door with the unit rewritten, the case changed, the ZIP rotated
			// or the city renamed.
			if ( ! empty( $r['ship_door_hash'] ) ) {
				$added += self::add_and_link( 'address', $r['ship_door_hash'], $reason . ' ' . __( '(door key — unit, ZIP and city blind)', 'fraud-shield' ), '', $action_id );
			}
			if ( (int) $r['user_id'] > 0 ) {
				$added += self::add_and_link( 'user', (string) (int) $r['user_id'], $reason, '', $action_id );
			}
		}

		return array(
			'action_id' => (int) $action_id,
			'added'     => $added,
		);
	}

	/* ----------------------------------------------------------------- *
	 *  v1.31 upgrade: carry standing address blocks onto the new door key
	 * ----------------------------------------------------------------- */

	/**
	 * How many 'address' blocklist entries still await their v1.31 door key.
	 * Zero once the upgrade pass has finished (or on a fresh install).
	 *
	 * @return int
	 */
	public static function door_migration_remaining() {
		global $wpdb;
		if ( get_option( 'fs_door_v2_done' ) ) {
			return 0;
		}
		$cursor = (int) get_option( 'fs_door_v2_cursor', 0 );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE type = 'address' AND id > %d", $cursor ) ); // phpcs:ignore
	}

	/**
	 * v1.31 upgrade pass, one batch: for each 'address' entry — whichever of
	 * the three hashes it is — read the orders behind it through the index,
	 * compute the CURRENT door key from each order's real address, and plant
	 * it beside the entry under the same block card(s). The entry itself is
	 * left exactly as it was: an exact or loose hash still matches what it
	 * always matched; an old-shape door hash becomes a dead row that matches
	 * nothing, which is harmless and keeps the card's history intact.
	 *
	 * Driven by FS_Data::geo_backfill_batch() (cron and the Geography page's
	 * button alike), which runs this to completion BEFORE rewriting any index
	 * row — the old door hash on a row is one of the three ways the entry's
	 * orders are found. Resumable through the cursor option; terminates once
	 * every row present at the start has been visited (entries this pass adds
	 * sit past the cursor and cost one no-op visit each).
	 *
	 * @param int $batch Entries per call.
	 * @return array { processed:int, remaining:int }
	 */
	public static function migrate_door_entries( $batch = 100 ) {
		global $wpdb;
		if ( get_option( 'fs_door_v2_done' ) ) {
			return array(
				'processed' => 0,
				'remaining' => 0,
			);
		}
		$table  = self::table();
		$index  = FS_Data::index_table();
		$je     = FS_Data::block_action_entries_table();
		$cursor = (int) get_option( 'fs_door_v2_cursor', 0 );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, value FROM {$table} WHERE type = 'address' AND id > %d ORDER BY id ASC LIMIT %d", $cursor, max( 1, (int) $batch ) ), ARRAY_A ); // phpcs:ignore
		if ( empty( $rows ) ) {
			update_option( 'fs_door_v2_done', 1 );
			return array(
				'processed' => 0,
				'remaining' => 0,
			);
		}

		$reason    = __( 'Door key carried over from a standing address block (v1.31)', 'fraud-shield' );
		$processed = 0;
		foreach ( $rows as $r ) {
			$value = (string) $r['value'];
			// A few orders are enough — they all describe one address.
			$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$index} WHERE ship_hash = %s OR ship_loose_hash = %s OR ship_door_hash = %s ORDER BY order_date DESC, id DESC LIMIT 5", $value, $value, $value ) ); // phpcs:ignore
			$keys = array();
			foreach ( (array) $ids as $oid ) {
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $oid ) : false;
				if ( ! $order ) {
					continue;
				}
				$k = FS_Normalizer::door( FS_Data::resolve_shipping( $order ) );
				if ( '' !== $k && $k !== $value ) {
					$keys[ $k ] = true;
				}
			}
			if ( ! empty( $keys ) ) {
				$actions = $wpdb->get_col( $wpdb->prepare( "SELECT action_id FROM {$je} WHERE entry_id = %d", (int) $r['id'] ) ); // phpcs:ignore
				foreach ( array_keys( $keys ) as $k ) {
					if ( empty( $actions ) ) {
						self::add( 'address', $k, $reason );
						continue;
					}
					foreach ( $actions as $aid ) {
						self::add_and_link( 'address', $k, $reason, '', (int) $aid );
					}
				}
			}
			$cursor = (int) $r['id'];
			$processed++;
		}
		update_option( 'fs_door_v2_cursor', $cursor );

		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type = 'address' AND id > %d", $cursor ) ); // phpcs:ignore
		if ( 0 === $remaining ) {
			update_option( 'fs_door_v2_done', 1 );
		}
		return array(
			'processed' => $processed,
			'remaining' => $remaining,
		);
	}

	/**
	 * v1.31 upgrade: rewrite every street lock's stored key to the aligned
	 * street name ("…|nw 66th st" → "…|nw 66 st"), on the entry and on its
	 * card, so locks placed under the old parser keep matching orders the
	 * new one reduces further. Two old spellings that align to one key are
	 * merged: the later entry's ownership links move to the survivor and the
	 * duplicate row goes. Runs in full at upgrade — a store has a handful of
	 * street locks, not thousands.
	 *
	 * @return int Keys rewritten or merged.
	 */
	public static function realign_street_locks() {
		global $wpdb;
		$table   = self::table();
		$actions = FS_Data::block_actions_table();
		$je      = FS_Data::block_action_entries_table();
		$changed = 0;

		$align = function ( $key ) {
			$parts = explode( '|', substr( (string) $key, 7 ), 4 ); // after "street:" → CC|STATE|city|street.
			if ( 4 !== count( $parts ) ) {
				return (string) $key;
			}
			$sn = FS_Normalizer::align_street( $parts[3] );
			return '' === $sn ? (string) $key : 'street:' . $parts[0] . '|' . $parts[1] . '|' . $parts[2] . '|' . $sn;
		};

		$rows = $wpdb->get_results( "SELECT id, value FROM {$table} WHERE type = 'place' AND value LIKE 'street:%' ORDER BY id ASC", ARRAY_A ); // phpcs:ignore
		foreach ( (array) $rows as $r ) {
			$new = $align( $r['value'] );
			if ( $new === (string) $r['value'] ) {
				continue;
			}
			$dup = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = 'place' AND value = %s LIMIT 1", $new ) ); // phpcs:ignore
			if ( $dup > 0 ) {
				$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$je} SET entry_id = %d WHERE entry_id = %d", $dup, (int) $r['id'] ) ); // phpcs:ignore
				$wpdb->delete( $je, array( 'entry_id' => (int) $r['id'] ) ); // phpcs:ignore
				$wpdb->delete( $table, array( 'id' => (int) $r['id'] ) ); // phpcs:ignore
			} else {
				$wpdb->update( $table, array( 'value' => $new ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore
			}
			$changed++;
		}

		$cards = $wpdb->get_results( "SELECT id, target FROM {$actions} WHERE kind = 'place' AND target LIKE 'street:%' ORDER BY id ASC", ARRAY_A ); // phpcs:ignore
		foreach ( (array) $cards as $c ) {
			$new = $align( $c['target'] );
			if ( $new === (string) $c['target'] ) {
				continue;
			}
			// UNIQUE (kind, target): a card already on the aligned key keeps
			// it; this one keeps its old target and simply no longer resolves
			// as THE card for the street (its entry link was merged above).
			$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$actions} SET target = %s WHERE id = %d", $new, (int) $c['id'] ) ); // phpcs:ignore
			$changed++;
		}

		return $changed;
	}

	/**
	 * Whether any AREA ban exists. Gates the geocoder at checkout: with one
	 * on the list, every address is resolved to a point and tested against
	 * it, validation switched on or not.
	 *
	 * @return bool
	 */
	public static function has_geofences( $country = '', $state = '' ) {
		global $wpdb;
		if ( ! (bool) $wpdb->get_var( 'SELECT 1 FROM ' . self::table() . " WHERE type = 'place' AND value LIKE 'geo:%' LIMIT 1" ) ) { // phpcs:ignore
			return false;
		}
		$cc = strtoupper( trim( (string) $country ) );
		$st = FS_Normalizer::region( $state );
		if ( '' === $cc || '' === $st ) {
			return true;
		}
		// A circle has no state in its key, so each ban records the region
		// its centre resolved to when it was drawn. An address in another
		// region is never geocoded on a circle's account; a ban whose region
		// is unknown (drawn from bare coordinates, or before this record
		// existed) keeps every address eligible — the safe direction.
		$regions = get_option( 'fs_geofence_regions', array() );
		if ( ! is_array( $regions ) ) {
			return true;
		}
		foreach ( self::geofences() as $g ) {
			$region = (string) ( $regions[ $g['value'] ] ?? '' );
			if ( '' === $region || $region === $cc . '|' . $st ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every area ban, parsed, with the columns match() reports. A store has
	 * a handful of these, so the whole set is read and tested in PHP — a
	 * radius is not something an IN(...) can do.
	 *
	 * @return array Rows, each with lat, lng, radius_m added.
	 */
	public static function geofences() {
		global $wpdb;
		$table      = self::table();
		$actions    = FS_Data::block_actions_table();
		$je         = FS_Data::block_action_entries_table();
		$action_msg = "(SELECT a.message FROM {$je} j INNER JOIN {$actions} a ON a.id = j.action_id WHERE j.entry_id = {$table}.id AND a.message <> '' ORDER BY a.created_at DESC, a.id DESC LIMIT 1)";
		$rows       = $wpdb->get_results( "SELECT id, type, value, reason, message, added_by, created_at, {$action_msg} AS action_message FROM {$table} WHERE type = 'place' AND value LIKE 'geo:%' ORDER BY id ASC", ARRAY_A ); // phpcs:ignore
		$out        = array();
		foreach ( (array) $rows as $r ) {
			$g = FS_Normalizer::geo_parts( $r['value'] );
			if ( null === $g ) {
				continue;
			}
			$out[] = array_merge( $r, $g );
		}
		return $out;
	}

	/**
	 * The area ban a point falls inside — the tightest one when several do,
	 * so its own message is the one reported — or null.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return array|null Match row (as match() returns them), or null.
	 */
	public static function geofence_hit( $lat, $lng ) {
		$best = null;
		foreach ( self::geofences() as $g ) {
			if ( FS_Normalizer::distance_m( $lat, $lng, $g['lat'], $g['lng'] ) <= $g['radius_m'] && ( null === $best || $g['radius_m'] < $best['radius_m'] ) ) {
				$best = $g;
			}
		}
		if ( null === $best ) {
			return null;
		}
		unset( $best['lat'], $best['lng'], $best['radius_m'] );
		return $best;
	}

	/**
	 * Create (or fetch) an AREA ban: every address resolving to a point within
	 * the radius of the centre is refused. Like every destination lock it
	 * never cascades and never auto-extends; the card carries the label the
	 * merchant gave it (the address it was drawn around) and the message.
	 *
	 * @param float  $lat      Centre latitude.
	 * @param float  $lng      Centre longitude.
	 * @param int    $radius_m Radius in metres (50 m – 100 mi).
	 * @param string $label    What the centre is ("8020 NW 66th St, Miami, FL"), for the card.
	 * @param string $message  Optional checkout message (default: the street-lock default).
	 * @param string $reason   Reason recorded on the entry.
	 * @param string $region   "CC|STATE" the centre resolved to, when known (scopes geocoding to that region).
	 * @return array { action_id:int, added:int, key:string }
	 */
	public static function block_geofence( $lat, $lng, $radius_m, $label = '', $message = '', $reason = '', $region = '' ) {
		$key = FS_Normalizer::geo_key( $lat, $lng, $radius_m );
		if ( '' === $key ) {
			return array(
				'action_id' => 0,
				'added'     => 0,
				'key'       => '',
			);
		}
		if ( '' === $reason ) {
			$reason = __( 'Area lock', 'fraud-shield' );
		}
		if ( '' === trim( (string) $message ) ) {
			$message = self::street_default_message();
		}
		$title = self::place_title( 'geo', '', $lat, $lng, $radius_m );
		if ( '' !== trim( (string) $label ) ) {
			/* translators: 1: the generated title, 2: the merchant's label for the centre */
			$title = mb_substr( sprintf( __( '%1$s — around %2$s', 'fraud-shield' ), $title, trim( (string) $label ) ), 0, 255 );
		}
		$action_id = self::create_action( 'place', $key, $title, $message );
		$added     = self::add_and_link( 'place', $key, $reason, '', $action_id );
		// Remember which country|state the centre is in, so checkouts
		// elsewhere are never geocoded on this circle's account (see
		// has_geofences()). A stale entry after an unlock is harmless.
		$region = strtoupper( trim( (string) $region ) );
		if ( '' !== $region && (int) $action_id > 0 ) {
			$regions = get_option( 'fs_geofence_regions', array() );
			$regions = is_array( $regions ) ? $regions : array();
			$regions[ $key ] = $region;
			update_option( 'fs_geofence_regions', $regions, false );
		}
		return array(
			'action_id' => (int) $action_id,
			'added'     => $added,
			'key'       => $key,
		);
	}

	/**
	 * Whether a single value is on the blocklist.
	 *
	 * @param string $type  Type.
	 * @param string $value Stored (normalized) value.
	 * @return bool
	 */
	public static function is_blocked( $type, $value ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . ' WHERE type=%s AND value=%s LIMIT 1', $type, $value ) ); // phpcs:ignore
	}

	/**
	 * Of the given values, which are already blocked (one query, for tables).
	 *
	 * @param string $type   Type.
	 * @param array  $values Stored values.
	 * @return array Blocked subset.
	 */
	public static function blocked_values( $type, $values ) {
		global $wpdb;
		$values = array_values( array_filter( array_map( 'strval', (array) $values ) ) );
		if ( empty( $values ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		return (array) $wpdb->get_col( $wpdb->prepare( 'SELECT value FROM ' . self::table() . " WHERE type=%s AND value IN ({$placeholders})", array_merge( array( $type ), $values ) ) ); // phpcs:ignore
	}

	/**
	 * The full blocklist rows for a set of values of one type (v1.30, for the
	 * API's customer view: which of this customer's credentials are blocked,
	 * with the row ids provenance() needs).
	 *
	 * @param string $type   Credential type.
	 * @param array  $values Normalized values.
	 * @return array Rows (id, type, value, reason, message, added_by, created_at).
	 */
	public static function rows_for_values( $type, $values ) {
		global $wpdb;
		$values = array_values( array_unique( array_filter( array_map( 'strval', (array) $values ) ) ) );
		if ( empty( $values ) || ! in_array( (string) $type, self::TYPES, true ) ) {
			return array();
		}
		$values       = array_slice( $values, 0, 200 );
		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE type=%s AND value IN ({$placeholders}) ORDER BY created_at DESC, id DESC", array_merge( array( (string) $type ), $values ) ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * The block cards owning each of a set of entries (v1.30, for the API's
	 * blocklist listing). One query for the page.
	 *
	 * @param array $entry_ids Blocklist row ids.
	 * @return array [ entry_id => [ { id:int, kind:string, title:string, target:string } ] ]
	 */
	public static function owners_of_entries( $entry_ids ) {
		global $wpdb;
		$entry_ids = array_values( array_filter( array_map( 'intval', (array) $entry_ids ) ) );
		if ( empty( $entry_ids ) ) {
			return array();
		}
		$in      = implode( ',', $entry_ids ); // ints only.
		$je      = FS_Data::block_action_entries_table();
		$actions = FS_Data::block_actions_table();
		$out     = array();
		foreach ( (array) $wpdb->get_results( "SELECT j.entry_id, a.id, a.kind, a.title, a.target FROM {$je} j INNER JOIN {$actions} a ON a.id = j.action_id WHERE j.entry_id IN ({$in}) ORDER BY a.created_at DESC, a.id DESC", ARRAY_A ) as $r ) { // phpcs:ignore
			$out[ (int) $r['entry_id'] ][] = array(
				'id'     => (int) $r['id'],
				'kind'   => (string) $r['kind'],
				'title'  => (string) $r['title'],
				'target' => (string) $r['target'],
			);
		}
		return $out;
	}

	/**
	 * Update the per-entry checkout message.
	 *
	 * @param int    $id      Row id.
	 * @param string $message Message ('' = use the global default).
	 */
	public static function update_message( $id, $message ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'message' => (string) $message ), array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	/**
	 * One blocklist row by id, or null (v1.30, for the API).
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get_entry( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore
		return $row ? $row : null;
	}

	/**
	 * Remove an entry by id. Also drops any action-ownership links so no block
	 * card keeps counting a credential that no longer exists.
	 *
	 * @param int $id Row id.
	 */
	public static function remove( $id ) {
		global $wpdb;
		$id  = (int) $id;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT type, value FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		$wpdb->delete( self::table(), array( 'id' => $id ) ); // phpcs:ignore
		$wpdb->delete( FS_Data::block_action_entries_table(), array( 'entry_id' => $id ) ); // phpcs:ignore
		if ( is_array( $row ) ) {
			self::emit_removed( array( $row ) );
		}
	}

	/**
	 * Tell subscribers that credentials left the list. One event per removal
	 * call, not per credential: a 500-IP bulk removal is one decision.
	 *
	 * @param array $rows Removed rows, each with type and value.
	 */
	private static function emit_removed( $rows ) {
		$rows = array_values( (array) $rows );
		if ( empty( $rows ) ) {
			return;
		}
		$by_type = array();
		$sample  = array();
		foreach ( $rows as $r ) {
			$type             = (string) ( $r['type'] ?? '' );
			$by_type[ $type ] = ( $by_type[ $type ] ?? 0 ) + 1;
			if ( count( $sample ) < 25 ) {
				$sample[] = array(
					'type'       => $type,
					'credential' => (string) ( $r['value'] ?? '' ),
				);
			}
		}
		ksort( $by_type );
		FS_Webhooks::emit(
			'blocklist.removed',
			array(
				'count'   => count( $rows ),
				'by_type' => $by_type,
				'sample'  => $sample,
			)
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Blocklist page (v1.27): search, bulk IP removal, provenance.
	 * ----------------------------------------------------------------- */

	/**
	 * Most ungrouped / matching rows a search will list.
	 */
	const SEARCH_ROWS = 500;

	/**
	 * The forms a search should try: the text as typed, and — when it looks
	 * like an email or a phone — its normalized form, so a gmail alias or a
	 * formatted number finds the stored credential.
	 *
	 * @param string $q Free text.
	 * @return array Distinct non-empty needles.
	 */
	public static function search_needles( $q ) {
		$q = trim( (string) $q );
		if ( '' === $q ) {
			return array();
		}
		$needles = array( mb_strtolower( $q ) );
		if ( false !== strpos( $q, '@' ) ) {
			$e = FS_Normalizer::email( $q );
			if ( '' !== $e ) {
				$needles[] = $e;
			}
		}
		if ( preg_match( '/\d{5,}/', preg_replace( '/[\s().+\-]/', '', $q ) ) ) {
			$p = FS_Normalizer::phone( $q );
			if ( '' !== $p ) {
				$needles[] = $p;
			}
		}
		return array_values( array_unique( $needles ) );
	}

	/**
	 * WHERE fragment matching a credential row against the needles, on its
	 * value and its reason.
	 *
	 * @param array  $needles Output of search_needles().
	 * @param array  $params  Placeholders, appended.
	 * @param string $alias   Blocklist table alias.
	 * @return string
	 */
	private static function entry_search_sql( $needles, &$params, $alias = 'b' ) {
		global $wpdb;
		$parts = array();
		foreach ( $needles as $n ) {
			$like     = '%' . $wpdb->esc_like( $n ) . '%';
			$parts[]  = "{$alias}.value LIKE %s";
			$params[] = $like;
			$parts[]  = "{$alias}.reason LIKE %s";
			$params[] = $like;
		}
		return '(' . implode( ' OR ', $parts ) . ')';
	}

	/**
	 * WHERE fragment matching a block card against the needles, on its title
	 * and target (the title carries the readable address or customer name an
	 * address / profile card is about, which is what a person will type).
	 *
	 * @param array  $needles Output of search_needles().
	 * @param array  $params  Placeholders, appended.
	 * @param string $alias   Actions table alias.
	 * @return string
	 */
	private static function action_search_sql( $needles, &$params, $alias = 'a' ) {
		global $wpdb;
		$parts = array();
		foreach ( $needles as $n ) {
			$like     = '%' . $wpdb->esc_like( $n ) . '%';
			$parts[]  = "{$alias}.title LIKE %s";
			$params[] = $like;
			$parts[]  = "{$alias}.target LIKE %s";
			$params[] = $like;
		}
		return '(' . implode( ' OR ', $parts ) . ')';
	}

	/**
	 * Does a credential row match the needles? The PHP twin of
	 * entry_search_sql(), for trimming a card's entries to the ones that hit.
	 *
	 * @param array $row     Blocklist row.
	 * @param array $needles Needles.
	 * @return bool
	 */
	private static function entry_matches( $row, $needles ) {
		$hay = mb_strtolower( (string) $row['value'] . ' ' . (string) $row['reason'] );
		foreach ( $needles as $n ) {
			if ( false !== mb_strpos( $hay, $n ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Search the blocklist. A block card is a hit when its title or target
	 * matches, or when it owns a credential whose value or reason matches;
	 * ungrouped credentials are matched on their own.
	 *
	 * Cards that hit only through a credential come back with just the
	 * matching credentials attached (and `entries_total` saying how many they
	 * own), so a search for one IP does not unroll a 200-credential profile;
	 * cards that hit by title come back whole.
	 *
	 * A type filter narrows everything to credentials of one type: a card then
	 * qualifies only through a credential of that type (its title alone is
	 * not enough) and comes back with only those attached, so "IP" plus
	 * select-all is exactly "every IP on this page".
	 *
	 * @param string $q      Free text ('' with a type = every credential of that type).
	 * @param int    $limit  Cards per page.
	 * @param int    $offset Offset.
	 * @param string $type   One of TYPES to narrow to, or ''.
	 * @return array {
	 *     needles:array, type:string, actions:array (each with entries, entries_total, self_match), actions_total:int,
	 *     ungrouped:array, ungrouped_total:int, entries_total:int, ip_total:int
	 * }
	 */
	public static function search( $q, $limit = 100, $offset = 0, $type = '' ) {
		global $wpdb;
		$type    = in_array( (string) $type, self::TYPES, true ) ? (string) $type : '';
		$empty   = array(
			'needles'         => array(),
			'type'            => $type,
			'actions'         => array(),
			'actions_total'   => 0,
			'ungrouped'       => array(),
			'ungrouped_total' => 0,
			'entries_total'   => 0,
			'ip_total'        => 0,
		);
		$needles = self::search_needles( $q );
		if ( empty( $needles ) && '' === $type ) {
			return $empty;
		}
		$table   = self::table();
		$actions = FS_Data::block_actions_table();
		$je      = FS_Data::block_action_entries_table();

		// Cards: by their own title / target, or through a credential they
		// own; under a type filter, only through a credential of that type.
		$params = array();
		$parts  = array();
		if ( '' !== $type ) {
			$parts[]  = 'b.type = %s';
			$params[] = $type;
		}
		if ( ! empty( $needles ) ) {
			$parts[] = '(' . self::action_search_sql( $needles, $params ) . ' OR ' . self::entry_search_sql( $needles, $params ) . ')';
		}
		$where = implode( ' AND ', $parts );
		$from  = "FROM {$actions} a LEFT JOIN {$je} j ON j.action_id = a.id LEFT JOIN {$table} b ON b.id = j.entry_id WHERE {$where}";

		$actions_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT a.id) {$from}", $params ) ); // phpcs:ignore
		$page_params   = array_merge( $params, array( (int) $limit, (int) $offset ) );
		$action_rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT a.* {$from} ORDER BY a.created_at DESC, a.id DESC LIMIT %d OFFSET %d", $page_params ), ARRAY_A ); // phpcs:ignore

		$out = array();
		if ( ! empty( $action_rows ) ) {
			$ids = array();
			foreach ( $action_rows as $a ) {
				$ids[] = (int) $a['id'];
			}
			$in         = implode( ',', $ids ); // ints only.
			$entry_rows = (array) $wpdb->get_results( // phpcs:ignore
				"SELECT j.action_id, b.id, b.type, b.value, b.reason, b.message, b.created_at
				FROM {$je} j INNER JOIN {$table} b ON b.id = j.entry_id
				WHERE j.action_id IN ({$in})
				ORDER BY FIELD(b.type,'email','phone','ip','user','address','place'), b.value",
				ARRAY_A
			);
			$by_action  = array();
			foreach ( $entry_rows as $er ) {
				$by_action[ (int) $er['action_id'] ][] = $er;
			}
			foreach ( $action_rows as $a ) {
				$all  = isset( $by_action[ (int) $a['id'] ] ) ? $by_action[ (int) $a['id'] ] : array();
				$self = false;
				$hay  = mb_strtolower( (string) $a['title'] . ' ' . (string) $a['target'] );
				foreach ( $needles as $n ) {
					if ( false !== mb_strpos( $hay, $n ) ) {
						$self = true;
						break;
					}
				}
				$kept = array_values(
					array_filter(
						$all,
						function ( $row ) use ( $needles, $type, $self ) {
							if ( '' !== $type && (string) $row['type'] !== $type ) {
								return false;
							}
							return $self || empty( $needles ) || self::entry_matches( $row, $needles );
						}
					)
				);
				$a['entries']       = $kept;
				$a['entries_total'] = count( $all );
				$a['self_match']    = $self;
				$out[]              = $a;
			}
		}

		// Ungrouped credentials, and the totals the results header reports —
		// the same filter, on the credential alone.
		$p_un  = array();
		$parts = array();
		if ( '' !== $type ) {
			$parts[] = 'b.type = %s';
			$p_un[]  = $type;
		}
		if ( ! empty( $needles ) ) {
			$parts[] = self::entry_search_sql( $needles, $p_un );
		}
		$w_un      = implode( ' AND ', $parts );
		$ungrouped = (array) $wpdb->get_results( $wpdb->prepare( "SELECT b.* FROM {$table} b LEFT JOIN {$je} j ON j.entry_id = b.id WHERE j.entry_id IS NULL AND {$w_un} ORDER BY b.created_at DESC, b.id DESC LIMIT %d", array_merge( $p_un, array( self::SEARCH_ROWS ) ) ), ARRAY_A ); // phpcs:ignore
		$un_total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} b LEFT JOIN {$je} j ON j.entry_id = b.id WHERE j.entry_id IS NULL AND {$w_un}", $p_un ) ); // phpcs:ignore
		$totals    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) total, SUM(CASE WHEN b.type = 'ip' THEN 1 ELSE 0 END) ips FROM {$table} b WHERE {$w_un}", $p_un ), ARRAY_A ); // phpcs:ignore

		return array(
			'needles'         => $needles,
			'type'            => $type,
			'actions'         => $out,
			'actions_total'   => $actions_total,
			'ungrouped'       => $ungrouped,
			'ungrouped_total' => $un_total,
			'entries_total'   => (int) ( $totals['total'] ?? 0 ),
			'ip_total'        => (int) ( $totals['ips'] ?? 0 ),
		);
	}

	/**
	 * How many credentials of one type are on the list.
	 *
	 * @param string $type Type.
	 * @return int
	 */
	public static function count_type( $type ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE type = %s', (string) $type ) ); // phpcs:ignore
	}

	/**
	 * Split a pasted list of IP addresses — one per line, or separated by
	 * commas, semicolons or spaces — into the valid ones and the tokens that
	 * are not an IP.
	 *
	 * @param string $text Pasted text.
	 * @return array { valid:array, invalid:array }
	 */
	public static function parse_ip_list( $text ) {
		$valid   = array();
		$invalid = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $t ) {
			$t = trim( $t );
			if ( filter_var( $t, FILTER_VALIDATE_IP ) ) {
				$valid[ $t ] = true;
			} else {
				$invalid[] = $t;
			}
		}
		return array(
			'valid'   => array_keys( $valid ),
			'invalid' => $invalid,
		);
	}

	/**
	 * Remove the given IP credentials (exact values), wherever they are owned.
	 *
	 * @param array $ips IP addresses.
	 * @return array { removed:int, missing:int } missing = asked for but not on the list.
	 */
	public static function remove_ips( $ips ) {
		global $wpdb;
		$ips = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', (array) $ips ) ) ) ) );
		if ( empty( $ips ) ) {
			return array(
				'removed' => 0,
				'missing' => 0,
			);
		}
		$ids = array();
		foreach ( array_chunk( $ips, 500 ) as $chunk ) {
			$ph  = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$ids = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE type = 'ip' AND value IN ({$ph})", $chunk ) ) ) ); // phpcs:ignore
		}
		$removed = self::remove_ids( $ids );
		return array(
			'removed' => $removed,
			'missing' => max( 0, count( $ips ) - $removed ),
		);
	}

	/**
	 * Remove a selection of credentials by id — any type — the way the
	 * Blocklist page's checkboxes hand them over. Reports what went, by type,
	 * so the notice can say "12 credentials (9 IP addresses, 3 emails)".
	 *
	 * @param array $ids Blocklist row ids.
	 * @return array { removed:int, by_type:array [ type => count ] }
	 */
	public static function remove_entries( $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array(
				'removed' => 0,
				'by_type' => array(),
			);
		}
		$by_type = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', $chunk ); // ints only.
			foreach ( (array) $wpdb->get_results( 'SELECT type, COUNT(*) n FROM ' . self::table() . " WHERE id IN ({$in}) GROUP BY type", ARRAY_A ) as $r ) { // phpcs:ignore
				$by_type[ (string) $r['type'] ] = ( $by_type[ (string) $r['type'] ] ?? 0 ) + (int) $r['n'];
			}
		}
		ksort( $by_type );
		return array(
			'removed' => self::remove_ids( $ids ),
			'by_type' => $by_type,
		);
	}

	/**
	 * Remove every IP credential on the list.
	 *
	 * @return int Rows removed.
	 */
	public static function remove_all_ips() {
		global $wpdb;
		return self::remove_ids( array_map( 'intval', (array) $wpdb->get_col( 'SELECT id FROM ' . self::table() . " WHERE type = 'ip'" ) ) ); // phpcs:ignore
	}

	/**
	 * Remove the IP credentials a search matches (value or reason).
	 *
	 * @param string $q Free text, as given to search().
	 * @return int Rows removed.
	 */
	public static function remove_ips_matching( $q ) {
		global $wpdb;
		$needles = self::search_needles( $q );
		if ( empty( $needles ) ) {
			return 0;
		}
		$params = array();
		$where  = self::entry_search_sql( $needles, $params );
		return self::remove_ids( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT b.id FROM ' . self::table() . " b WHERE b.type = 'ip' AND {$where}", $params ) ) ) ); // phpcs:ignore
	}

	/**
	 * Delete a set of credentials by id, release them from every card that
	 * owned them, and drop the cards that existed only for an IP and now own
	 * nothing (an "IP address" card, or a manual "ip:…" entry's card).
	 * Profile, address and place cards keep whatever credentials remain.
	 *
	 * @param array $ids Blocklist row ids.
	 * @return int Rows removed.
	 */
	private static function remove_ids( $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$table   = self::table();
		$actions = FS_Data::block_actions_table();
		$je      = FS_Data::block_action_entries_table();
		$removed = 0;
		$gone    = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in   = implode( ',', $chunk ); // ints only.
			$gone = array_merge( $gone, (array) $wpdb->get_results( "SELECT type, value FROM {$table} WHERE id IN ({$in})", ARRAY_A ) ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$je} WHERE entry_id IN ({$in})" ); // phpcs:ignore
			$removed += (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" ); // phpcs:ignore
		}
		$wpdb->query( "DELETE a FROM {$actions} a LEFT JOIN {$je} j ON j.action_id = a.id WHERE j.action_id IS NULL AND (a.kind = 'ip' OR (a.kind = 'manual' AND a.target LIKE 'ip:%'))" ); // phpcs:ignore
		self::emit_removed( $gone );
		return $removed;
	}

	/**
	 * Display names for a set of WordPress user ids, cached for the request
	 * (an Orders list asks for the same few staff over and over). 0 is the
	 * plugin acting on its own — a cascade at checkout, an auto-extension.
	 *
	 * @param array $ids User ids.
	 * @return array [ id => name ]
	 */
	public static function actor_names( $ids ) {
		static $cache = array();
		$ids  = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		$need = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $cache[ $id ] ) ) {
				$need[] = $id;
			}
		}
		if ( ! empty( $need ) ) {
			foreach ( $need as $id ) {
				$cache[ $id ] = $id > 0
					/* translators: %d: WordPress user id */
					? sprintf( __( 'user #%d', 'fraud-shield' ), $id )
					: __( 'Fraud Shield (automatic)', 'fraud-shield' );
			}
			$real = array_values( array_filter( $need ) );
			if ( ! empty( $real ) && function_exists( 'get_users' ) ) {
				foreach ( (array) get_users(
					array(
						'include' => $real,
						'fields'  => array( 'ID', 'display_name', 'user_login' ),
					)
				) as $u ) {
					$cache[ (int) $u->ID ] = '' !== trim( (string) $u->display_name ) ? (string) $u->display_name : (string) $u->user_login;
				}
			}
		}
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = $cache[ $id ];
		}
		return $out;
	}

	/**
	 * Who blocked each matched credential, and when: the credential's own
	 * author and time, plus the block card(s) that own it with their authors.
	 * Two queries (cards, then people) for the whole set.
	 *
	 * @param array $matches Output of match().
	 * @return array [ entry_id => {
	 *     added_by:int, added_by_name:string, created_at:string,
	 *     cards: [ { id:int, kind:string, title:string, target:string, created_by:int, created_by_name:string, created_at:string }, ... ]
	 * } ]
	 */
	public static function provenance( $matches ) {
		global $wpdb;
		$out = array();
		$ids = array();
		foreach ( (array) $matches as $m ) {
			$id = (int) ( $m['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$ids[]      = $id;
			$out[ $id ] = array(
				'added_by'      => (int) ( $m['added_by'] ?? 0 ),
				'added_by_name' => '',
				'created_at'    => (string) ( $m['created_at'] ?? '' ),
				'cards'         => array(),
			);
		}
		if ( empty( $ids ) ) {
			return $out;
		}
		$je      = FS_Data::block_action_entries_table();
		$actions = FS_Data::block_actions_table();
		$in      = implode( ',', $ids ); // ints only.
		$rows    = (array) $wpdb->get_results( "SELECT j.entry_id, a.id, a.kind, a.title, a.target, a.created_by, a.created_at FROM {$je} j INNER JOIN {$actions} a ON a.id = j.action_id WHERE j.entry_id IN ({$in}) ORDER BY a.created_at DESC, a.id DESC", ARRAY_A ); // phpcs:ignore

		$people = array_map(
			function ( $p ) {
				return $p['added_by'];
			},
			$out
		);
		foreach ( $rows as $r ) {
			$people[] = (int) $r['created_by'];
		}
		$names = self::actor_names( $people );

		foreach ( $out as $id => &$p ) {
			$p['added_by_name'] = $names[ $p['added_by'] ] ?? '';
		}
		unset( $p );
		foreach ( $rows as $r ) {
			$eid = (int) $r['entry_id'];
			if ( ! isset( $out[ $eid ] ) ) {
				continue;
			}
			$out[ $eid ]['cards'][] = array(
				'id'              => (int) $r['id'],
				'kind'            => (string) $r['kind'],
				'title'           => (string) $r['title'],
				'target'          => (string) $r['target'],
				'created_by'      => (int) $r['created_by'],
				'created_by_name' => $names[ (int) $r['created_by'] ] ?? '',
				'created_at'      => (string) $r['created_at'],
			);
		}
		return $out;
	}

	/**
	 * Everything staff need to know about a refused checkout's blocklist
	 * matches, in one durable record: each matched credential as the customer
	 * typed it, who blocked it and when, the block card(s) it belongs to, the
	 * reason recorded at the time — and the identities BEHIND the credential,
	 * i.e. the blocked person this customer was matched to. Stored on the
	 * Checkout Locks ledger row, so the answer survives later changes to the
	 * blocklist and is there even though no order exists.
	 *
	 * @param array $matches Output of match().
	 * @param array $signals The customer's normalized signals (for the address text and to exclude their own identity).
	 * @return array { kind:'blocklist', at:string, items:[ {
	 *     type, value, label, search, reason, blocked_at, blocked_by,
	 *     cards:[ { id, kind, title, created_by_name } ],
	 *     identities:[ { email, user_id, name, blocked } ], more:int
	 * } ] }
	 */
	public static function match_evidence( $matches, $signals ) {
		$matches = array_values( (array) $matches );
		$items   = array();
		if ( empty( $matches ) ) {
			return array(
				'kind'  => 'blocklist',
				'at'    => current_time( 'mysql' ),
				'items' => $items,
			);
		}
		$prov = self::provenance( $matches );

		// Who is behind each matched credential — never the customer themselves.
		$pseudo = array();
		foreach ( $matches as $i => $m ) {
			$type = (string) ( $m['type'] ?? '' );
			if ( 'place' === $type || 'review' === $type ) {
				continue; // A destination has no "person behind it".
			}
			$cards        = $prov[ (int) ( $m['id'] ?? 0 ) ]['cards'] ?? array();
			$pseudo[ $i ] = array(
				'blocked_type'  => $type,
				'blocked_value' => (string) ( $m['value'] ?? '' ),
				'action_kind'   => ! empty( $cards ) ? (string) $cards[0]['kind'] : '',
				'action_target' => ! empty( $cards ) ? (string) $cards[0]['target'] : '',
			);
		}
		$own = array(
			'email' => '' !== (string) ( $signals['email_norm'] ?? '' ) ? array( (string) $signals['email_norm'] => true ) : array(),
			'user'  => (int) ( $signals['user_id'] ?? 0 ) > 0 ? array( (string) (int) $signals['user_id'] => true ) : array(),
		);
		$resolved = empty( $pseudo ) ? array() : self::resolve_link_identities( array_values( $pseudo ), $own );
		$by_index = array();
		$n        = 0;
		foreach ( array_keys( $pseudo ) as $i ) {
			$by_index[ $i ] = $resolved[ $n ] ?? array(
				'identities' => array(),
				'more'       => 0,
			);
			$n++;
		}

		foreach ( $matches as $i => $m ) {
			$p    = $prov[ (int) ( $m['id'] ?? 0 ) ] ?? array(
				'added_by_name' => '',
				'created_at'    => (string) ( $m['created_at'] ?? '' ),
				'cards'         => array(),
			);
			$desc = self::describe_match( $m, $signals, $p['cards'] );
			$cards = array();
			foreach ( array_slice( (array) $p['cards'], 0, 3 ) as $c ) {
				$cards[] = array(
					'id'              => (int) $c['id'],
					'kind'            => (string) $c['kind'],
					'title'           => '' !== trim( (string) $c['title'] ) ? (string) $c['title'] : (string) $c['target'],
					'created_by_name' => (string) $c['created_by_name'],
				);
			}
			$items[] = array(
				'type'       => (string) ( $m['type'] ?? '' ),
				'value'      => (string) ( $m['value'] ?? '' ),
				'label'      => (string) $desc['label'],
				'search'     => (string) $desc['search'],
				'reason'     => trim( (string) ( $m['reason'] ?? '' ) ),
				'blocked_at' => (string) $p['created_at'],
				'blocked_by' => (string) $p['added_by_name'],
				'cards'      => $cards,
				'identities' => isset( $by_index[ $i ] ) ? (array) $by_index[ $i ]['identities'] : array(),
				'more'       => isset( $by_index[ $i ] ) ? (int) $by_index[ $i ]['more'] : 0,
			);
		}

		return array(
			'kind'  => 'blocklist',
			'at'    => current_time( 'mysql' ),
			'items' => $items,
		);
	}

	/**
	 * A matched credential as staff should read it, with the order's own
	 * address text standing in for an address hash and the card's title for a
	 * destination lock's key.
	 *
	 * @param array $m       One match from match().
	 * @param array $signals The order's normalized signals (ship_text, ship_hash, ship_loose_hash, ship_door_hash, ship_door_alts).
	 * @param array $cards   The match's cards from provenance() (for a readable place / address title).
	 * @return array { label:string, search:string } label to show; search = the text that finds it on the Blocklist page.
	 */
	public static function describe_match( $m, $signals, $cards = array() ) {
		$type  = (string) ( $m['type'] ?? '' );
		$value = (string) ( $m['value'] ?? '' );
		$title = ! empty( $cards ) ? (string) $cards[0]['title'] : '';
		switch ( $type ) {
			case 'email':
				return array(
					/* translators: %s: email address */
					'label'  => sprintf( __( 'Email %s', 'fraud-shield' ), $value ),
					'search' => $value,
				);
			case 'phone':
				return array(
					/* translators: %s: phone digits */
					'label'  => sprintf( __( 'Phone %s', 'fraud-shield' ), $value ),
					'search' => $value,
				);
			case 'ip':
				return array(
					/* translators: %s: IP address */
					'label'  => sprintf( __( 'IP %s', 'fraud-shield' ), $value ),
					'search' => $value,
				);
			case 'user':
				return array(
					/* translators: %s: account id */
					'label'  => sprintf( __( 'Account #%s', 'fraud-shield' ), $value ),
					'search' => $value,
				);
			case 'address':
				$how = __( 'exact address', 'fraud-shield' );
				if ( $value === (string) ( $signals['ship_door_hash'] ?? '' ) && $value !== (string) ( $signals['ship_hash'] ?? '' ) ) {
					$how = __( 'same door — unit, ZIP or city rewritten', 'fraud-shield' );
				} elseif ( $value === (string) ( $signals['ship_loose_hash'] ?? '' ) && $value !== (string) ( $signals['ship_hash'] ?? '' ) ) {
					$how = __( 'same address line and ZIP', 'fraud-shield' );
				} elseif ( in_array( $value, array_map( 'strval', (array) ( $signals['ship_door_alts'] ?? array() ) ), true ) ) {
					$how = __( 'same door, street type left out', 'fraud-shield' );
				}
				$text = trim( (string) ( $signals['ship_text'] ?? '' ) );
				return array(
					/* translators: 1: shipping address, 2: how it matched */
					'label'  => sprintf( __( 'Shipping address %1$s (%2$s)', 'fraud-shield' ), '' !== $text ? $text : self::entry_label( 'address', $value ), $how ),
					'search' => '' !== $title ? $title : substr( $value, 0, 16 ),
				);
			case 'place':
				$label = sprintf(
					/* translators: %s: the destination lock's card title or key */
					__( 'Destination lock %s', 'fraud-shield' ),
					'' !== $title ? $title : $value
				);
				// Reached through the validated address rather than the typed
				// one? Say what the address resolved to, so staff can see the
				// rotated ZIP or renamed city for what it was.
				$check = ( ! empty( $signals['ship_parts'] ) && class_exists( 'FS_Address_Check' ) ) ? FS_Address_Check::cached( $signals['ship_parts'] ) : null;
				if ( is_array( $check ) && 'confirmed' === $check['status'] && ! empty( $check['replaced'] ) && '' !== (string) $check['formatted'] ) {
					/* translators: 1: the lock label, 2: the validated address */
					$label = sprintf( __( '%1$s — the address validates to %2$s', 'fraud-shield' ), $label, $check['formatted'] );
				}
				return array(
					'label'  => $label,
					'search' => '' !== $title ? $title : $value,
				);
			case 'review':
				$text = trim( (string) ( $signals['ship_text'] ?? '' ) );
				return array(
					/* translators: %s: shipping address */
					'label'  => sprintf( __( 'Shipping address %s could not be verified and names a locked road — stopped for correction', 'fraud-shield' ), '' !== $text ? $text : __( '(as typed)', 'fraud-shield' ) ),
					'search' => '',
				);
			default:
				return array(
					'label'  => $type . ' ' . $value,
					'search' => $value,
				);
		}
	}

	/**
	 * Check a set of signals against the blocklist.
	 *
	 * Each matched row also carries `id` (blocklist row id) and `action_message`
	 * (the most recent non-empty message among the actions that own the entry —
	 * deterministic, newest action wins), so callers can resolve the checkout
	 * message by precedence and link new credentials to the owning actions.
	 *
	 * @param array $s Normalized signals (email_norm, phone_norm, ship_hash, ship_loose_hash, ip, user_id).
	 * @return array Matched entries: [ ['id'=>..,'type'=>..,'value'=>..,'reason'=>..,'message'=>..,'action_message'=>..], ... ]
	 */
	public static function match( $s ) {
		global $wpdb;
		$table   = self::table();
		$actions = FS_Data::block_actions_table();
		$je      = FS_Data::block_action_entries_table();
		$matches = array();

		// Deterministic per-entry action message: newest owning action with a message.
		// added_by / created_at (v1.27) let the order screen say who blocked a
		// credential and when, without a second lookup.
		$action_msg = "(SELECT a.message FROM {$je} j INNER JOIN {$actions} a ON a.id = j.action_id WHERE j.entry_id = {$table}.id AND a.message <> '' ORDER BY a.created_at DESC, a.id DESC LIMIT 1)";
		$cols       = "id, type, value, reason, message, added_by, created_at, {$action_msg} AS action_message";

		$checks = array(
			'email' => $s['email_norm'] ?? '',
			'phone' => $s['phone_norm'] ?? '',
			'ip'    => $s['ip'] ?? '',
			'user'  => ! empty( $s['user_id'] ) ? (string) (int) $s['user_id'] : '',
		);

		foreach ( $checks as $type => $value ) {
			if ( '' === $value || '0' === $value ) {
				continue;
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE type=%s AND value=%s LIMIT 1", $type, $value ), ARRAY_A ); // phpcs:ignore
			if ( $row ) {
				$matches[] = $row;
			}
		}

		// Address: exact hash, loose hash, and the door key. The door key
		// (v1.25.1, widened in v1.31) is what catches the rewrite — same house
		// number, same street, with the unit restyled or moved to the other
		// line, the case changed, the ZIP rotated or the city renamed. The
		// first two hashes all move under that; the door key cannot. Most
		// specific first, so the match is described as precisely as it can be.
		$matched_address = false;
		foreach ( array( $s['ship_hash'] ?? '', $s['ship_loose_hash'] ?? '', $s['ship_door_hash'] ?? '' ) as $h ) {
			if ( '' === $h ) {
				continue;
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE type='address' AND value=%s LIMIT 1", $h ), ARRAY_A ); // phpcs:ignore
			if ( $row ) {
				$matches[]       = $row;
				$matched_address = true;
				break;
			}
		}
		// A street typed WITHOUT its type ("8020 NW 66th") is also tried as
		// each common type (v1.31) — the blocked key stays precise; only the
		// incoming side widens, and only when the type was left out.
		$alts = array_values( array_filter( array_map( 'strval', (array) ( $s['ship_door_alts'] ?? array() ) ) ) );
		if ( ! $matched_address && ! empty( $alts ) ) {
			$ph  = implode( ',', array_fill( 0, count( $alts ), '%s' ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE type='address' AND value IN ({$ph}) ORDER BY id ASC LIMIT 1", $alts ), ARRAY_A ); // phpcs:ignore
			if ( $row ) {
				$matches[] = $row;
			}
		}

		// Destination locks (place) — matched LAST so an identity-specific message
		// outranks a geo message under pick_block_message()'s tier-major precedence
		// (a blocked person sees their message, not the city's). One indexed
		// IN(...) over every candidate key (street / city / state / ZIP prefixes).
		// Empty address (virtual goods) yields no keys → no query → no match.
		//
		// place_keys() lists candidates most specific first, and when several
		// locks cover one destination the most specific one is the match that
		// is reported: a street lock's own message must not lose to the
		// city lock's default because of which row the database returned first.
		//
		// The destination is matched twice over (v1.31): first as the customer
		// TYPED it, and — only when that misses, address validation is
		// configured, there is a street-level lock to resolve against, and the
		// service confirmed the address — as it physically IS: the validated
		// house number, street, city, ZIP and county. A rotated ZIP or an
		// alternative city name changes the first set and not the second. An
		// address the typed text already refuses never costs a request.
		$place_query = function ( $keys ) use ( $wpdb, $cols, $table ) {
			$keys = array_values( array_unique( array_filter( array_map( 'strval', (array) $keys ) ) ) );
			if ( empty( $keys ) ) {
				return null;
			}
			$ph   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE type='place' AND value IN ({$ph})", $keys ), ARRAY_A ); // phpcs:ignore
			if ( empty( $rows ) ) {
				return null;
			}
			$rank = array_flip( $keys );
			usort(
				$rows,
				function ( $x, $y ) use ( $rank ) {
					return ( $rank[ $x['value'] ] ?? PHP_INT_MAX ) <=> ( $rank[ $y['value'] ] ?? PHP_INT_MAX );
				}
			);
			return $rows[0];
		};

		$place_row = $place_query(
			FS_Normalizer::place_keys(
				$s['shipping_country'] ?? '',
				$s['ship_state'] ?? '',
				$s['ship_city'] ?? '',
				$s['ship_postcode'] ?? '',
				$s['ship_street'] ?? '',
				$s['ship_number'] ?? ''
			)
		);

		$check = null;
		if ( null === $place_row && ! empty( $s['ship_parts'] ) && is_array( $s['ship_parts'] ) && class_exists( 'FS_Address_Check' ) && FS_Address_Check::should_check( $s['ship_parts'] ) ) {
			$check = FS_Address_Check::check( $s['ship_parts'] );
			if ( is_array( $check ) && 'confirmed' === $check['status'] ) {
				// Both the geocoder's locality and the USPS city: for one
				// Miami-Dade ZIP those are "Doral" and "MIAMI", and a lock may
				// be bounded to either.
				$validated_keys = array();
				foreach ( array_unique( array_filter( array( $check['city'], $check['city_usps'] ) ) ) as $vcity ) {
					$validated_keys = array_merge( $validated_keys, FS_Normalizer::place_keys( $check['country'], $check['state'], $vcity, $check['postcode'], $check['street'], $check['number'], $check['county'] ) );
				}
				$place_row = $place_query( $validated_keys );
			}
		}

		// Area bans (v1.31): the resolved point against every circle. Only a
		// point located to the building, block or street counts — a town or
		// postcode centroid can sit miles from the door.
		if ( null === $place_row && is_array( $check ) && FS_Address_Check::has_point( $check ) && self::has_geofences() ) {
			$place_row = self::geofence_hit( $check['lat'], $check['lng'] );
		}

		$place_hit = null !== $place_row;
		if ( $place_hit ) {
			$matches[] = $place_row;
		}

		// An address the validation service could NOT confirm, on a road that
		// is locked somewhere in this state: the typed city and ZIP are the
		// only thing keeping it outside the lock's boundary, and nobody has
		// confirmed them. Stopped for correction — never approved on text
		// alone — when the merchant has asked for that (the default once
		// validation is on). Reported as its own kind of match, 'review', with
		// the merchant's correction message; it owns no card, so auto-extend
		// and provenance treat it like a destination lock: nobody is "behind" it.
		if ( ! $place_hit && is_array( $check ) && 'unconfirmed' === $check['status'] && FS_Address_Check::stops_ambiguous()
			&& self::shadow_place_hit( $s['shipping_country'] ?? '', $s['ship_state'] ?? '', $s['ship_street'] ?? '', $s['ship_postcode'] ?? '' ) ) {
			$matches[] = array(
				'id'             => 0,
				'type'           => 'review',
				'value'          => 'address',
				'reason'         => __( 'Shipping address could not be verified and names a locked road', 'fraud-shield' ),
				'message'        => FS_Address_Check::ambiguous_message(),
				'added_by'       => 0,
				'created_at'     => current_time( 'mysql' ),
				'action_message' => '',
			);
		}

		return $matches;
	}

	/**
	 * DISTINCT action ids owning any of the given blocklist entry ids. Used by
	 * auto-extend to know which actions a matched order should be linked to.
	 *
	 * @param array $entry_ids Blocklist row ids.
	 * @return array Action ids (ints).
	 */
	public static function actions_owning_entries( $entry_ids ) {
		global $wpdb;
		$entry_ids = array_values( array_filter( array_map( 'intval', (array) $entry_ids ) ) );
		if ( empty( $entry_ids ) ) {
			return array();
		}
		$in = implode( ',', $entry_ids ); // ints only.
		$je = FS_Data::block_action_entries_table();
		return array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT action_id FROM {$je} WHERE entry_id IN ({$in})" ) ); // phpcs:ignore
	}

	/* ----------------------------------------------------------------- *
	 *  Linked identities (v1.26): credentials a customer SHARES with the
	 *  blocklist without matching it directly.
	 * ----------------------------------------------------------------- */

	/**
	 * Credential types that can link a customer to a blocked identity.
	 */
	const LINK_TYPES = array( 'email', 'phone', 'ip', 'address', 'user' );

	/**
	 * How many of the customer's own index rows feed the credential set. A
	 * customer with more orders than this contributes their most recent ones.
	 */
	const LINK_SELF_ROWS = 200;

	/**
	 * How many neighbouring index rows (other identities on a shared
	 * credential) are examined. Bounds the cost on a carrier-NAT IP shared by
	 * thousands of orders.
	 */
	const LINK_NEIGHBOUR_ROWS = 1000;

	/**
	 * Find every credential this customer shares with a blocked identity.
	 *
	 * match() answers "is anything on THIS order on the blocklist?". This
	 * answers the wider question the auto-cancel rule asks: does the customer
	 * behind this order share a credential with someone who is blocked —
	 * counting every order they ever placed, and counting the other people
	 * who used the same IP, phone or shipping address. Two degrees:
	 *
	 *  1. A credential the customer used on an EARLIER order (a shipping
	 *     address, an IP, a phone, an account, another email on the same
	 *     account) is itself on the blocklist. Their current order does not
	 *     match, because they typed something new — but their history does.
	 *
	 *  2. Another identity that shares an IP, phone or address with the
	 *     customer is blocked by email, account or phone. This is the case a
	 *     cascade never reached: a manually blocked email, an imported list,
	 *     a blocked customer whose newer credentials were never swept in.
	 *
	 * Destination ('place') locks are never a link: blocking a city blocks a
	 * destination, not the people who ship there. Email and account are
	 * identity anchors, not sharing edges — two orders on one email ARE one
	 * customer — so degree 2 only travels along IP, phone and address.
	 *
	 * Read-only and bounded: at most five indexed queries (the customer's
	 * history, their blocked credentials, the neighbourhood, the blocked
	 * neighbours, the owning block cards), with row caps on both the
	 * customer's own history and the neighbourhood.
	 *
	 * @param array      $s     Normalized signals (email_norm, phone_norm, ip, user_id, ship_hash, ship_loose_hash, ship_door_hash).
	 * @param array|null $types Credential types that may form a link (subset of LINK_TYPES; null = all).
	 * @return array Links, degree 1 first. Each: {
	 *     via:string (email|phone|ip|address|user)  the credential the customer shares,
	 *     value:string                              its normalized value (address = hash),
	 *     on_order:bool                             whether it is on THIS order (else from history),
	 *     degree:int                                1 = the credential itself is blocked, 2 = another identity using it is blocked,
	 *     blocked_type:string, blocked_value:string the blocklist entry reached,
	 *     entry_id:int, action_id:int, action_title:string, action_kind:string, action_target:string  the owning block card (0/'' for legacy rows),
	 *     identities:array                          who is behind the blocked credential: up to LINK_IDENTITIES of { email, user_id, name, blocked }, blocked first,
	 *     more:int                                  how many further identities were not listed
	 * }
	 *
	 * Whether the links are enough to ACT on is a separate question — see
	 * linked_sufficient(): an IP-only link never is.
	 */
	public static function linked( $s, $types = null ) {
		global $wpdb;

		$types = null === $types ? self::LINK_TYPES : array_values( array_intersect( self::LINK_TYPES, array_map( 'strval', (array) $types ) ) );
		if ( empty( $types ) ) {
			return array();
		}

		$index = FS_Data::index_table();
		$table = self::table();
		$email = (string) ( $s['email_norm'] ?? '' );
		$uid   = (int) ( $s['user_id'] ?? 0 );

		// --- The customer's own credential set: this order, then their history.
		$creds = array_fill_keys( self::LINK_TYPES, array() );
		$mine  = array_fill_keys( self::LINK_TYPES, array() ); // what is on THIS order.
		$push  = function ( $type, $value, $on_order = false ) use ( &$creds, &$mine ) {
			$value = trim( (string) $value );
			if ( '' === $value || '0' === $value ) {
				return;
			}
			$creds[ $type ][ $value ] = true;
			if ( $on_order ) {
				$mine[ $type ][ $value ] = true;
			}
		};
		$push( 'email', $email, true );
		$push( 'phone', $s['phone_norm'] ?? '', true );
		$push( 'ip', $s['ip'] ?? '', true );
		$push( 'address', $s['ship_hash'] ?? '', true );
		$push( 'address', $s['ship_loose_hash'] ?? '', true );
		$push( 'address', $s['ship_door_hash'] ?? '', true );
		if ( $uid > 0 ) {
			$push( 'user', (string) $uid, true );
		}

		$anchor = array();
		$params = array();
		if ( '' !== $email ) {
			$anchor[] = 'email_norm = %s';
			$params[] = $email;
		}
		if ( $uid > 0 ) {
			$anchor[] = 'user_id = %d';
			$params[] = $uid;
		}
		if ( ! empty( $anchor ) ) {
			$params[] = self::LINK_SELF_ROWS;
			$rows     = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					"SELECT email_norm, phone_norm, ip, ship_hash, ship_loose_hash, ship_door_hash, user_id FROM {$index} WHERE (" . implode( ' OR ', $anchor ) . ') ORDER BY order_date DESC, id DESC LIMIT %d',
					$params
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$push( 'email', $r['email_norm'] );
				$push( 'phone', $r['phone_norm'] );
				$push( 'ip', $r['ip'] );
				$push( 'address', $r['ship_hash'] );
				$push( 'address', $r['ship_loose_hash'] );
				$push( 'address', $r['ship_door_hash'] );
				if ( (int) $r['user_id'] > 0 ) {
					$push( 'user', (string) (int) $r['user_id'] );
				}
			}
		}

		$links = array();
		$seen  = array();
		$add   = function ( $via, $value, $degree, $entry ) use ( &$links, &$seen, $mine ) {
			$key = $via . '|' . $value . '|' . $entry['type'] . '|' . $entry['value'];
			if ( isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			$links[]      = array(
				'via'           => $via,
				'value'         => $value,
				'on_order'      => isset( $mine[ $via ][ $value ] ),
				'degree'        => (int) $degree,
				'blocked_type'  => (string) $entry['type'],
				'blocked_value' => (string) $entry['value'],
				'entry_id'      => (int) $entry['id'],
				'action_id'     => 0,
				'action_title'  => '',
				'action_kind'   => '',
			);
		};

		// --- Degree 1: one of the customer's own credentials is blocked.
		foreach ( self::entries_for( $creds, $types ) as $entry ) {
			$add( $entry['type'], $entry['value'], 1, $entry );
		}

		// --- Degree 2: someone ELSE on a shared IP / phone / address is blocked.
		$edges = array_values( array_intersect( array( 'ip', 'phone', 'address' ), $types ) );

		// Exclude the customer's own identities so a neighbour is a different
		// person, not the customer under another of their orders.
		$not        = '';
		$not_params = array();
		if ( ! empty( $creds['email'] ) ) {
			$emails     = array_keys( $creds['email'] );
			$not       .= ' AND (email_norm IS NULL OR email_norm NOT IN (' . implode( ',', array_fill( 0, count( $emails ), '%s' ) ) . '))';
			$not_params = $emails;
		}
		if ( ! empty( $creds['user'] ) ) {
			$not .= ' AND (user_id = 0 OR user_id NOT IN (' . implode( ',', array_map( 'intval', array_keys( $creds['user'] ) ) ) . '))';
		}

		// One indexed branch per column, joined with UNION ALL, rather than a
		// single OR across five columns: each branch is a plain IN() lookup on
		// its own index, so the cost stays bounded on a large order index no
		// matter what the optimizer would have made of the OR.
		$branches = array();
		$params   = array();
		$columns  = array();
		if ( in_array( 'ip', $edges, true ) && ! empty( $creds['ip'] ) ) {
			$columns['ip'] = array_keys( $creds['ip'] );
		}
		if ( in_array( 'phone', $edges, true ) && ! empty( $creds['phone'] ) ) {
			$columns['phone_norm'] = array_keys( $creds['phone'] );
		}
		if ( in_array( 'address', $edges, true ) && ! empty( $creds['address'] ) ) {
			$hashes                     = array_keys( $creds['address'] );
			$columns['ship_hash']       = $hashes;
			$columns['ship_loose_hash'] = $hashes;
			$columns['ship_door_hash']  = $hashes;
		}
		foreach ( $columns as $col => $vals ) {
			$ph         = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
			$branches[] = "(SELECT email_norm, phone_norm, ip, ship_hash, ship_loose_hash, ship_door_hash, user_id, order_date, id FROM {$index} WHERE {$col} IN ({$ph}){$not} ORDER BY order_date DESC, id DESC LIMIT %d)";
			$params     = array_merge( $params, $vals, $not_params, array( self::LINK_NEIGHBOUR_ROWS ) );
		}

		if ( ! empty( $branches ) ) {
			$neighbours = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare( implode( ' UNION ALL ', $branches ), $params ),
				ARRAY_A
			);

			if ( ! empty( $neighbours ) ) {
				$identities = array(
					'email' => array(),
					'user'  => array(),
					'phone' => array(),
				);
				foreach ( $neighbours as $n ) {
					if ( '' !== (string) $n['email_norm'] ) {
						$identities['email'][ (string) $n['email_norm'] ] = true;
					}
					if ( (int) $n['user_id'] > 0 ) {
						$identities['user'][ (string) (int) $n['user_id'] ] = true;
					}
					if ( '' !== (string) $n['phone_norm'] ) {
						$identities['phone'][ (string) $n['phone_norm'] ] = true;
					}
				}
				$blocked = self::entries_for( $identities, array( 'email', 'user', 'phone' ) );
				if ( ! empty( $blocked ) ) {
					$by_identity = array();
					foreach ( $blocked as $entry ) {
						$by_identity[ $entry['type'] . ':' . $entry['value'] ] = $entry;
					}
					foreach ( $neighbours as $n ) {
						$hits = array();
						foreach ( array( 'email' => (string) $n['email_norm'], 'user' => (int) $n['user_id'] > 0 ? (string) (int) $n['user_id'] : '', 'phone' => (string) $n['phone_norm'] ) as $t => $v ) {
							if ( '' !== $v && isset( $by_identity[ $t . ':' . $v ] ) ) {
								$hits[] = $by_identity[ $t . ':' . $v ];
							}
						}
						if ( empty( $hits ) ) {
							continue;
						}
						// Which shared credential connects this neighbour to the customer.
						$shared = array();
						if ( in_array( 'ip', $edges, true ) && '' !== (string) $n['ip'] && isset( $creds['ip'][ (string) $n['ip'] ] ) ) {
							$shared[] = array( 'ip', (string) $n['ip'] );
						}
						if ( in_array( 'phone', $edges, true ) && '' !== (string) $n['phone_norm'] && isset( $creds['phone'][ (string) $n['phone_norm'] ] ) ) {
							$shared[] = array( 'phone', (string) $n['phone_norm'] );
						}
						if ( in_array( 'address', $edges, true ) ) {
							foreach ( array( $n['ship_hash'], $n['ship_loose_hash'], $n['ship_door_hash'] ) as $h ) {
								if ( '' !== (string) $h && isset( $creds['address'][ (string) $h ] ) ) {
									$shared[] = array( 'address', (string) $h );
									break;
								}
							}
						}
						foreach ( $shared as $edge ) {
							foreach ( $hits as $entry ) {
								// A neighbour blocked on the very phone they share with
								// the customer is a degree-1 link already reported.
								if ( $entry['type'] === $edge[0] && $entry['value'] === $edge[1] ) {
									continue;
								}
								$add( $edge[0], $edge[1], 2, $entry );
							}
						}
					}
				}
			}
		}

		if ( empty( $links ) ) {
			return array();
		}

		// --- Attach the block card that owns each matched entry (newest wins).
		$entry_ids = array_values( array_unique( array_map( 'intval', wp_list_pluck( $links, 'entry_id' ) ) ) );
		$owners    = array();
		if ( ! empty( $entry_ids ) ) {
			$je      = FS_Data::block_action_entries_table();
			$actions = FS_Data::block_actions_table();
			$in      = implode( ',', $entry_ids ); // ints only.
			$rows    = $wpdb->get_results( "SELECT j.entry_id, a.id, a.kind, a.target, a.title FROM {$je} j INNER JOIN {$actions} a ON a.id = j.action_id WHERE j.entry_id IN ({$in}) ORDER BY a.created_at DESC, a.id DESC", ARRAY_A ); // phpcs:ignore
			foreach ( (array) $rows as $r ) {
				if ( ! isset( $owners[ (int) $r['entry_id'] ] ) ) {
					$owners[ (int) $r['entry_id'] ] = $r;
				}
			}
		}
		foreach ( $links as &$l ) {
			$l['action_target'] = '';
			$l['identities']    = array();
			$l['more']          = 0;
			if ( isset( $owners[ $l['entry_id'] ] ) ) {
				$l['action_id']     = (int) $owners[ $l['entry_id'] ]['id'];
				$l['action_kind']   = (string) $owners[ $l['entry_id'] ]['kind'];
				$l['action_title']  = (string) $owners[ $l['entry_id'] ]['title'];
				$l['action_target'] = (string) $owners[ $l['entry_id'] ]['target'];
			}
		}
		unset( $l );

		usort(
			$links,
			function ( $x, $y ) {
				if ( $x['degree'] !== $y['degree'] ) {
					return $x['degree'] <=> $y['degree'];
				}
				$rank = array_flip( self::LINK_TYPES );
				return ( $rank[ $x['via'] ] ?? 99 ) <=> ( $rank[ $y['via'] ] ?? 99 );
			}
		);

		// One link per (credential, block card). An address is three hashes
		// that all lead to the same card; a neighbour blocked by email, phone
		// AND account is one person; a credential that is blocked itself
		// (degree 1) makes the neighbour reached through it (degree 2)
		// redundant. Degree 1 sorts first, so it is the one kept.
		$collapsed = array();
		$kept      = array();
		foreach ( $links as $l ) {
			$owner = $l['action_id'] > 0 ? 'a' . $l['action_id'] : 'e' . $l['entry_id'];
			$key   = $l['via'] . '|' . $owner . ( 'address' === $l['via'] ? '' : '|' . $l['value'] );
			if ( isset( $kept[ $key ] ) ) {
				continue;
			}
			$kept[ $key ] = true;
			$collapsed[]  = $l;
		}

		// --- Who is behind each blocked credential: the account(s) and
		// profile(s) that used it — never the customer themselves.
		return self::resolve_link_identities( $collapsed, $creds );
	}

	/**
	 * The people behind a set of links, flattened: every identity any link
	 * resolved, once each, blocked identities first and the links' own order
	 * otherwise. What the savings ledger stores as "whom the stop was tied
	 * to" (v1.30), so the Financial Protection estimate can weigh a linked
	 * stop by the history of the blocked identity behind the shared
	 * credential rather than by the stopped customer's empty one.
	 *
	 * @param array $links Output of linked() (or the links stored on a ledger row).
	 * @return array [ { email, user_id, name, blocked } ]
	 */
	public static function link_identities( $links ) {
		$out  = array();
		$seen = array();
		foreach ( (array) $links as $l ) {
			foreach ( (array) ( $l['identities'] ?? array() ) as $id ) {
				if ( ! is_array( $id ) ) {
					continue;
				}
				$email = (string) ( $id['email'] ?? '' );
				$uid   = (int) ( $id['user_id'] ?? 0 );
				$key   = '' !== $email ? 'e:' . $email : ( $uid > 0 ? 'u:' . $uid : '' );
				if ( '' === $key || isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$out[]        = array(
					'email'   => $email,
					'user_id' => $uid,
					'name'    => (string) ( $id['name'] ?? '' ),
					'blocked' => ! empty( $id['blocked'] ),
				);
			}
		}
		// A stable partition (usort is not stable before PHP 8): blocked
		// identities first, each group in the order the links gave them.
		$blocked = array();
		$others  = array();
		foreach ( $out as $id ) {
			if ( $id['blocked'] ) {
				$blocked[] = $id;
			} else {
				$others[] = $id;
			}
		}
		return array_merge( $blocked, $others );
	}

	/**
	 * The people behind a refused checkout's blocklist matches, flattened
	 * the same way as link_identities(): whom the matched credential belongs
	 * to — the blocked customer whose address, phone or account this
	 * customer typed.
	 *
	 * @param array $evidence Output of match_evidence().
	 * @return array [ { email, user_id, name, blocked } ]
	 */
	public static function evidence_identities( $evidence ) {
		return self::link_identities( (array) ( $evidence['items'] ?? array() ) );
	}

	/**
	 * Whether a set of links is enough to act on.
	 *
	 * An IP is never enough on its own: carrier and campus NAT put strangers
	 * behind one address, so an IP-only link is evidence, not a verdict. Any
	 * other shared credential — a shipping address above all, a phone, an
	 * account, another email on the same account — is sufficient by itself,
	 * and an IP link then stands beside it as corroboration.
	 *
	 * @param array $links Output of linked().
	 * @return bool
	 */
	public static function linked_sufficient( $links ) {
		foreach ( (array) $links as $l ) {
			if ( 'ip' !== (string) ( $l['via'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * How many identities a link spells out before "and N more".
	 */
	const LINK_IDENTITIES = 3;

	/**
	 * Attach to every link the identities behind its blocked credential: the
	 * accounts and profiles (email, account id, name) that have used it, read
	 * from the order index, with a fallback to the WordPress account and to
	 * the block card's own target for a profile block. The customer's own
	 * identities are excluded, so a link always points at someone ELSE.
	 * Blocked identities sort first and are flagged. Bounded: one indexed
	 * query per credential type present, plus one blocklist check.
	 *
	 * @param array $links Collapsed links.
	 * @param array $creds The customer's own credential set, keyed by type.
	 * @return array Links with `identities` (each: email, user_id, name, blocked) and `more`.
	 */
	private static function resolve_link_identities( $links, $creds ) {
		global $wpdb;
		if ( empty( $links ) ) {
			return $links;
		}
		$index = FS_Data::index_table();

		// Which values to look up, per index column.
		$wanted = array(
			'email_norm'  => array(),
			'user_id'     => array(),
			'phone_norm'  => array(),
			'ip'          => array(),
			'address'     => array(),
		);
		$col_for = array(
			'email' => 'email_norm',
			'user'  => 'user_id',
			'phone' => 'phone_norm',
			'ip'    => 'ip',
		);
		foreach ( $links as $l ) {
			$t = (string) $l['blocked_type'];
			$v = (string) $l['blocked_value'];
			if ( 'address' === $t ) {
				$wanted['address'][ $v ] = true;
			} elseif ( isset( $col_for[ $t ] ) ) {
				$wanted[ $col_for[ $t ] ][ $v ] = true;
			}
		}

		$select   = "SELECT email_norm, user_id, phone_norm, ip, ship_hash, ship_loose_hash, ship_door_hash, customer_name, order_date, id FROM {$index} WHERE ";
		$branches = array();
		$params   = array();
		foreach ( array( 'email_norm', 'phone_norm', 'ip' ) as $col ) {
			if ( empty( $wanted[ $col ] ) ) {
				continue;
			}
			$vals       = array_keys( $wanted[ $col ] );
			$branches[] = "({$select}{$col} IN (" . implode( ',', array_fill( 0, count( $vals ), '%s' ) ) . ') ORDER BY order_date DESC, id DESC LIMIT %d)';
			$params     = array_merge( $params, $vals, array( self::LINK_NEIGHBOUR_ROWS ) );
		}
		if ( ! empty( $wanted['user_id'] ) ) {
			$branches[] = "({$select}user_id IN (" . implode( ',', array_map( 'intval', array_keys( $wanted['user_id'] ) ) ) . ') ORDER BY order_date DESC, id DESC LIMIT %d)';
			$params[]   = self::LINK_NEIGHBOUR_ROWS;
		}
		if ( ! empty( $wanted['address'] ) ) {
			$vals = array_keys( $wanted['address'] );
			$ph   = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
			foreach ( array( 'ship_hash', 'ship_loose_hash', 'ship_door_hash' ) as $col ) {
				$branches[] = "({$select}{$col} IN ({$ph}) ORDER BY order_date DESC, id DESC LIMIT %d)";
				$params     = array_merge( $params, $vals, array( self::LINK_NEIGHBOUR_ROWS ) );
			}
		}

		$rows = empty( $branches ) ? array() : (array) $wpdb->get_results( $wpdb->prepare( implode( ' UNION ALL ', $branches ), $params ), ARRAY_A ); // phpcs:ignore

		// Identities keyed by email (or by account when an order had none),
		// indexed by every credential value that reaches them. Rows arrive
		// newest first per branch, so the first name seen is the latest.
		$identities = array();
		$by_value   = array();
		$own_emails = isset( $creds['email'] ) ? $creds['email'] : array();
		$own_users  = isset( $creds['user'] ) ? $creds['user'] : array();
		$note       = function ( $type, $value, $key ) use ( &$by_value ) {
			if ( '' !== (string) $value ) {
				$by_value[ $type . ':' . $value ][ $key ] = true;
			}
		};
		foreach ( $rows as $r ) {
			$email = (string) $r['email_norm'];
			$uid   = (int) $r['user_id'];
			if ( ( '' !== $email && isset( $own_emails[ $email ] ) ) || ( $uid > 0 && isset( $own_users[ (string) $uid ] ) ) ) {
				continue; // The customer's own orders are not "someone else".
			}
			$key = '' !== $email ? $email : ( $uid > 0 ? 'user:' . $uid : '' );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $identities[ $key ] ) ) {
				$identities[ $key ] = array(
					'email'   => $email,
					'user_id' => $uid,
					'name'    => trim( (string) $r['customer_name'] ),
					'blocked' => false,
				);
			} else {
				if ( $uid > 0 && (int) $identities[ $key ]['user_id'] <= 0 ) {
					$identities[ $key ]['user_id'] = $uid;
				}
				if ( '' === $identities[ $key ]['name'] ) {
					$identities[ $key ]['name'] = trim( (string) $r['customer_name'] );
				}
			}
			$note( 'email', $email, $key );
			$note( 'user', $uid > 0 ? (string) $uid : '', $key );
			$note( 'phone', $r['phone_norm'], $key );
			$note( 'ip', $r['ip'], $key );
			$note( 'address', $r['ship_hash'], $key );
			$note( 'address', $r['ship_loose_hash'], $key );
			$note( 'address', $r['ship_door_hash'], $key );
		}

		// A blocked email or account with no order on record is still an
		// identity worth naming; so is the target of a profile block card.
		$ensure = function ( $email, $uid ) use ( &$identities, &$by_value, $own_emails, $own_users ) {
			$email = (string) $email;
			$uid   = (int) $uid;
			if ( ( '' !== $email && isset( $own_emails[ $email ] ) ) || ( $uid > 0 && isset( $own_users[ (string) $uid ] ) ) ) {
				return '';
			}
			if ( '' === $email && $uid > 0 ) {
				$acct = get_userdata( $uid );
				if ( $acct && ! empty( $acct->user_email ) ) {
					$email = FS_Normalizer::email( $acct->user_email );
				}
			}
			$key = '' !== $email ? $email : ( $uid > 0 ? 'user:' . $uid : '' );
			if ( '' === $key ) {
				return '';
			}
			if ( ! isset( $identities[ $key ] ) ) {
				$name = '';
				$acct = $uid > 0 ? get_userdata( $uid ) : ( '' !== $email ? get_user_by( 'email', $email ) : false );
				if ( $acct ) {
					$name = (string) $acct->display_name;
					if ( $uid <= 0 ) {
						$uid = (int) $acct->ID;
					}
				}
				$identities[ $key ] = array(
					'email'   => $email,
					'user_id' => $uid,
					'name'    => $name,
					'blocked' => false,
				);
			}
			if ( '' !== $email ) {
				$by_value[ 'email:' . $email ][ $key ] = true;
			}
			if ( $uid > 0 ) {
				$by_value[ 'user:' . $uid ][ $key ] = true;
			}
			return $key;
		};
		foreach ( $links as $l ) {
			if ( 'email' === $l['blocked_type'] ) {
				$ensure( $l['blocked_value'], 0 );
			} elseif ( 'user' === $l['blocked_type'] ) {
				$ensure( '', (int) $l['blocked_value'] );
			}
			if ( 'profile' === (string) $l['action_kind'] && '' !== (string) $l['action_target'] ) {
				$key = $ensure( $l['action_target'], 0 );
				if ( '' !== $key ) {
					$by_value[ $l['blocked_type'] . ':' . $l['blocked_value'] ][ $key ] = true;
				}
			}
		}

		// Which of these identities are themselves on the blocklist.
		if ( ! empty( $identities ) ) {
			$check = array(
				'email' => array(),
				'user'  => array(),
			);
			foreach ( $identities as $id ) {
				if ( '' !== $id['email'] ) {
					$check['email'][ $id['email'] ] = true;
				}
				if ( (int) $id['user_id'] > 0 ) {
					$check['user'][ (string) (int) $id['user_id'] ] = true;
				}
			}
			$blocked_emails = array();
			$blocked_users  = array();
			foreach ( self::entries_for( $check, array( 'email', 'user' ) ) as $entry ) {
				if ( 'email' === $entry['type'] ) {
					$blocked_emails[ (string) $entry['value'] ] = true;
				} else {
					$blocked_users[ (int) $entry['value'] ] = true;
				}
			}
			foreach ( $identities as $key => $id ) {
				$identities[ $key ]['blocked'] = ( '' !== $id['email'] && isset( $blocked_emails[ $id['email'] ] ) )
					|| ( (int) $id['user_id'] > 0 && isset( $blocked_users[ (int) $id['user_id'] ] ) );
			}
		}

		foreach ( $links as &$l ) {
			$keys = array_keys( $by_value[ $l['blocked_type'] . ':' . $l['blocked_value'] ] ?? array() );
			$list = array();
			foreach ( $keys as $key ) {
				if ( isset( $identities[ $key ] ) ) {
					$list[] = $identities[ $key ];
				}
			}
			usort(
				$list,
				function ( $a, $b ) {
					return ( $b['blocked'] ? 1 : 0 ) <=> ( $a['blocked'] ? 1 : 0 );
				}
			);
			$l['more']       = max( 0, count( $list ) - self::LINK_IDENTITIES );
			$l['identities'] = array_slice( $list, 0, self::LINK_IDENTITIES );
		}
		unset( $l );

		return $links;
	}

	/**
	 * One identity as a readable label: "Jane Doe (jane@example.com, account #4411)".
	 *
	 * Plain text that survives every renderer it reaches. In particular it
	 * never writes the email in angle brackets: WooCommerce passes order
	 * notes through wp_kses_post(), which reads "<jane@example.com>" as an
	 * HTML tag and strips it — leaving "blocked Jane Doe ." in the note.
	 *
	 * @param array $id Identity { email, user_id, name }.
	 * @return string
	 */
	public static function identity_label( $id ) {
		$name    = trim( (string) ( $id['name'] ?? '' ) );
		$email   = (string) ( $id['email'] ?? '' );
		$uid     = (int) ( $id['user_id'] ?? 0 );
		$details = array();
		if ( '' !== $email ) {
			$details[] = $email;
		}
		if ( $uid > 0 ) {
			/* translators: %d: account id */
			$details[] = sprintf( __( 'account #%d', 'fraud-shield' ), $uid );
		}
		if ( '' !== $name ) {
			if ( empty( $details ) ) {
				return $name;
			}
			/* translators: 1: customer name, 2: their email and/or account id */
			return sprintf( __( '%1$s (%2$s)', 'fraud-shield' ), $name, implode( ', ', $details ) );
		}
		if ( '' !== $email ) {
			/* translators: 1: email, 2: account id */
			return $uid > 0 ? sprintf( __( '%1$s (%2$s)', 'fraud-shield' ), $email, $details[1] ) : $email;
		}
		return $uid > 0 ? $details[0] : '';
	}

	/**
	 * Blocklist rows (id, type, value) matching any of the given values, per
	 * type, in one query. 'place' is never queried.
	 *
	 * @param array $values [ type => [ value => true, ... ] ].
	 * @param array $types  Types to include.
	 * @return array
	 */
	private static function entries_for( $values, $types ) {
		global $wpdb;
		$or     = array();
		$params = array();
		foreach ( (array) $types as $type ) {
			if ( ! in_array( $type, self::LINK_TYPES, true ) || empty( $values[ $type ] ) ) {
				continue;
			}
			$vals     = array_keys( $values[ $type ] );
			$or[]     = '(type = %s AND value IN (' . implode( ',', array_fill( 0, count( $vals ), '%s' ) ) . '))';
			$params[] = $type;
			$params   = array_merge( $params, $vals );
		}
		if ( empty( $or ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, value FROM ' . self::table() . ' WHERE ' . implode( ' OR ', $or ), $params ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * One human sentence describing a set of links, for order notes, the
	 * ledger, the Orders-list column and alert emails. Address hashes are
	 * never shown raw; the block card's title (which carries the readable
	 * address or the customer's name) stands in for them.
	 *
	 * @param array $links Output of linked().
	 * @param int   $max   Links to spell out before "and N more".
	 * @return string
	 */
	public static function linked_summary( $links, $max = 3 ) {
		$links = array_values( (array) $links );
		if ( empty( $links ) ) {
			return '';
		}
		$via_labels = array(
			'email'   => __( 'email', 'fraud-shield' ),
			'phone'   => __( 'phone', 'fraud-shield' ),
			'ip'      => __( 'IP', 'fraud-shield' ),
			'address' => __( 'shipping address', 'fraud-shield' ),
			'user'    => __( 'account', 'fraud-shield' ),
		);
		$parts = array();
		foreach ( array_slice( $links, 0, max( 1, (int) $max ) ) as $l ) {
			$via   = isset( $via_labels[ $l['via'] ] ) ? $via_labels[ $l['via'] ] : (string) $l['via'];
			$shown = 'address' === $l['via'] ? '' : ' ' . $l['value'];
			$who   = self::link_who( $l );
			if ( 1 === (int) $l['degree'] ) {
				/* translators: 1: credential type (e.g. "IP"), 2: the credential value (may be empty), 3: the blocked identity or block card behind it */
				$parts[] = sprintf( __( '%1$s%2$s is blocked — %3$s', 'fraud-shield' ), $via, $shown, $who );
			} else {
				/* translators: 1: credential type (e.g. "IP"), 2: the credential value (may be empty), 3: the blocked identity behind it */
				$parts[] = sprintf( __( 'shares %1$s%2$s with blocked %3$s', 'fraud-shield' ), $via, $shown, $who );
			}
		}
		$more = count( $links ) - count( $parts );
		$text = implode( '; ', $parts );
		if ( $more > 0 ) {
			/* translators: %d: number of further links */
			$text .= ' ' . sprintf( _n( 'and %d more link', 'and %d more links', $more, 'fraud-shield' ), $more );
		}
		$text = ucfirst( $text );
		return mb_substr( $text, 0, 255 );
	}

	/**
	 * Who a link leads to, as text: the identities behind the blocked
	 * credential (name, email, account), falling back to the block card's
	 * title and finally to the bare entry.
	 *
	 * @param array $l   One link.
	 * @param int   $max Identities to spell out.
	 * @return string
	 */
	public static function link_who( $l, $max = 2 ) {
		$ids = isset( $l['identities'] ) && is_array( $l['identities'] ) ? $l['identities'] : array();
		if ( ! empty( $ids ) ) {
			$labels = array();
			foreach ( array_slice( $ids, 0, max( 1, (int) $max ) ) as $id ) {
				$label = self::identity_label( $id );
				if ( '' !== $label ) {
					$labels[] = $label;
				}
			}
			$rest = count( $ids ) - count( $labels ) + (int) ( $l['more'] ?? 0 );
			if ( ! empty( $labels ) ) {
				$text = implode( ', ', $labels );
				if ( $rest > 0 ) {
					/* translators: %d: number of further identities */
					$text .= ' ' . sprintf( _n( 'and %d other', 'and %d others', $rest, 'fraud-shield' ), $rest );
				}
				return $text;
			}
		}
		if ( '' !== (string) ( $l['action_title'] ?? '' ) ) {
			// Card titles may carry "Name <email>"; this text reaches order
			// notes, where wp_kses_post() would read the brackets as a tag.
			return str_replace( array( '<', '>' ), array( '(', ')' ), (string) $l['action_title'] );
		}
		return self::entry_label( (string) ( $l['blocked_type'] ?? '' ), (string) ( $l['blocked_value'] ?? '' ) );
	}

	/**
	 * Readable label for a bare blocklist entry (no owning card): the value
	 * for emails, phones, IPs and accounts; a short hash prefix for addresses.
	 *
	 * @param string $type  Entry type.
	 * @param string $value Entry value.
	 * @return string
	 */
	public static function entry_label( $type, $value ) {
		switch ( $type ) {
			case 'address':
				/* translators: %s: short address hash prefix */
				return sprintf( __( 'address %s…', 'fraud-shield' ), substr( (string) $value, 0, 12 ) );
			case 'user':
				/* translators: %s: account id */
				return sprintf( __( 'account #%s', 'fraud-shield' ), $value );
			default:
				return (string) $value;
		}
	}

	/**
	 * Fetch a page of entries for the admin UI (and the API).
	 *
	 * @param int    $limit  Per page.
	 * @param int    $offset Offset.
	 * @param string $type   Optional type filter (one of TYPES; '' = all).
	 * @return array
	 */
	public static function all( $limit = 100, $offset = 0, $type = '' ) {
		global $wpdb;
		if ( in_array( (string) $type, self::TYPES, true ) ) {
			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE type = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', (string) $type, (int) $limit, (int) $offset ), ARRAY_A ); // phpcs:ignore
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', (int) $limit, (int) $offset ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Total entries.
	 *
	 * @param string $type Optional type filter ('' = all).
	 * @return int
	 */
	public static function count( $type = '' ) {
		global $wpdb;
		if ( in_array( (string) $type, self::TYPES, true ) ) {
			return self::count_type( $type );
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore
	}

	/**
	 * Export rows joined to their owning actions — one row per (entry, action)
	 * pair, using portable NATURAL keys (action kind/target/title/message), never
	 * numeric ids. An entry owned by no action exports once with empty action
	 * columns; an entry owned by several actions exports once per pair.
	 *
	 * @param int $limit  Per page.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function export_pairs( $limit, $offset ) {
		global $wpdb;
		$table   = self::table();
		$je      = FS_Data::block_action_entries_table();
		$actions = FS_Data::block_actions_table();
		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT b.type, b.value, b.reason, b.message, b.created_at,
					a.kind AS action_kind, a.target AS action_target, a.title AS action_title, a.message AS action_message
				FROM {$table} b
				LEFT JOIN {$je} j ON j.entry_id = b.id
				LEFT JOIN {$actions} a ON a.id = j.action_id
				ORDER BY b.id ASC, a.id ASC
				LIMIT %d OFFSET %d",
				(int) $limit,
				(int) $offset
			),
			ARRAY_A
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Grouped-blocklist page helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Number of block actions (for pagination of the grouped page).
	 *
	 * @return int
	 */
	public static function actions_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . FS_Data::block_actions_table() ); // phpcs:ignore
	}

	/**
	 * One page of block actions, each with its owned entries attached under an
	 * `entries` key. Two queries total (actions, then their entries).
	 *
	 * @param int $limit  Actions per page.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function all_actions_with_entries( $limit = 100, $offset = 0 ) {
		global $wpdb;
		$actions = FS_Data::block_actions_table();
		$je      = FS_Data::block_action_entries_table();
		$table   = self::table();

		$action_rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$actions} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", (int) $limit, (int) $offset ), ARRAY_A ); // phpcs:ignore
		if ( empty( $action_rows ) ) {
			return array();
		}

		$ids = array();
		foreach ( $action_rows as $a ) {
			$ids[] = (int) $a['id'];
		}
		$in = implode( ',', $ids ); // ints only.

		$entry_rows = $wpdb->get_results( // phpcs:ignore
			"SELECT j.action_id, b.id, b.type, b.value, b.reason, b.message, b.created_at
			FROM {$je} j INNER JOIN {$table} b ON b.id = j.entry_id
			WHERE j.action_id IN ({$in})
			ORDER BY FIELD(b.type,'email','phone','ip','user','address'), b.value",
			ARRAY_A
		);

		$by_action = array();
		foreach ( (array) $entry_rows as $er ) {
			$by_action[ (int) $er['action_id'] ][] = $er;
		}

		$out = array();
		foreach ( $action_rows as $a ) {
			$a['entries'] = isset( $by_action[ (int) $a['id'] ] ) ? $by_action[ (int) $a['id'] ] : array();
			$out[]        = $a;
		}
		return $out;
	}

	/**
	 * Legacy / pre-1.6 rows: blocklist entries not owned by any action.
	 *
	 * @param int $limit  Per page.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function ungrouped( $limit = 500, $offset = 0 ) {
		global $wpdb;
		$table = self::table();
		$je    = FS_Data::block_action_entries_table();
		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT b.* FROM {$table} b LEFT JOIN {$je} j ON j.entry_id = b.id WHERE j.entry_id IS NULL ORDER BY b.created_at DESC, b.id DESC LIMIT %d OFFSET %d",
				(int) $limit,
				(int) $offset
			),
			ARRAY_A
		);
	}

	/**
	 * Count of ungrouped (legacy) blocklist rows.
	 *
	 * @return int
	 */
	public static function ungrouped_count() {
		global $wpdb;
		$table = self::table();
		$je    = FS_Data::block_action_entries_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} b LEFT JOIN {$je} j ON j.entry_id = b.id WHERE j.entry_id IS NULL" ); // phpcs:ignore
	}

	/**
	 * Delete every ungrouped (legacy) row in one statement.
	 *
	 * @return int Rows removed.
	 */
	public static function remove_all_ungrouped() {
		global $wpdb;
		$table = self::table();
		$je    = FS_Data::block_action_entries_table();
		// Read what is about to go first, so the removal event can say what it
		// was (v1.30) — one event for the whole legacy card, like every other
		// bulk removal.
		$gone    = (array) $wpdb->get_results( "SELECT b.type, b.value FROM {$table} b LEFT JOIN {$je} j ON j.entry_id = b.id WHERE j.entry_id IS NULL", ARRAY_A ); // phpcs:ignore
		$removed = (int) $wpdb->query( "DELETE b FROM {$table} b LEFT JOIN {$je} j ON j.entry_id = b.id WHERE j.entry_id IS NULL" ); // phpcs:ignore
		if ( $removed > 0 ) {
			self::emit_removed( $gone );
		}
		return $removed;
	}

	/**
	 * Set (or clear) the whole-action checkout message.
	 *
	 * @param int    $action_id Action id.
	 * @param string $message   Message ('' clears it → falls back down the precedence chain).
	 */
	public static function set_action_message( $action_id, $message ) {
		global $wpdb;
		$wpdb->update( FS_Data::block_actions_table(), array( 'message' => (string) $message ), array( 'id' => (int) $action_id ) ); // phpcs:ignore
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		return FS_Data::blocklist_table();
	}
}
