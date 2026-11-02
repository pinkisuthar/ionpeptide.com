<?php
/**
 * LinkMoney_Payment_Lock
 *
 * Atomic, per-order guard for the "charge" step. It closes the check-then-act
 * (TOCTOU) window that let a single WooCommerce order be charged twice: the old
 * guard read order meta, then made a blocking /v1/payments call (up to 30s), and
 * only persisted "this order was processed" AFTER the call returned. Two
 * submissions of the same order that overlapped that window both read "not
 * processed" and both charged (see order #22017: two debits 14s apart).
 *
 * claim() performs a single atomic INSERT keyed by order id. Exactly one
 * concurrent request wins; the loser is told a payment is already in flight.
 * The claim is taken BEFORE the API call, so the race window is gone.
 *
 * request_key() gives each order a stable idempotency key (reused across
 * attempts until rotated) so Link Money can also dedupe server-side — defense
 * in depth behind the local lock.
 *
 * The storage primitive is injectable so the logic is unit-testable without
 * WordPress or MySQL. In production it is backed by wp_options, whose
 * option_name UNIQUE index makes insert_if_absent() genuinely atomic.
 */

if ( ! class_exists( 'LinkMoney_Payment_Lock' ) ) {

	class LinkMoney_Payment_Lock {

		/** Option-name prefixes (kept out of the autoloaded options cache). */
		const LOCK_PREFIX   = 'linkmoney_pay_lock_';
		const REQKEY_PREFIX = 'linkmoney_pay_reqkey_';

		/** Safety net: a lock older than this (a crashed request) may be reclaimed. */
		const LOCK_TTL = 120;

		/** @var object|null Injected store; defaults to the wp_options-backed store. */
		private static $store = null;

		/** @var callable|null Injected clock; defaults to time(). */
		private static $clock = null;

		public static function set_store( $store ): void {
			self::$store = $store;
		}

		public static function set_clock( callable $clock ): void {
			self::$clock = $clock;
		}

		/** Restore defaults (used by tests). */
		public static function reset(): void {
			self::$store = null;
			self::$clock = null;
		}

		private static function store() {
			if ( null === self::$store ) {
				self::$store = new LinkMoney_Wpdb_Lock_Store();
			}
			return self::$store;
		}

		private static function now(): int {
			return self::$clock ? (int) ( self::$clock )() : time();
		}

		/**
		 * Atomically claim the charge slot for $order_id.
		 *
		 * @return bool true if THIS request won the slot (proceed); false if a
		 *              payment for this order is already in flight (abort).
		 */
		public static function claim( $order_id ): bool {
			$key = self::LOCK_PREFIX . (int) $order_id;
			$now = self::now();

			if ( self::store()->insert_if_absent( $key, (string) $now ) ) {
				return true;
			}

			// A lock already exists. Reclaim it only if it is stale (the owning
			// request crashed) so a genuine retry is never wedged forever.
			$held = self::store()->get( $key );
			if ( null !== $held && ( $now - (int) $held ) > self::LOCK_TTL ) {
				self::store()->set( $key, (string) $now );
				return true;
			}

			return false;
		}

		/** Release the slot (call after a declined/failed attempt, or once the
		 *  durable payment-id has been saved). */
		public static function release( $order_id ): void {
			self::store()->delete( self::LOCK_PREFIX . (int) $order_id );
		}

		/**
		 * Stable idempotency key for the order. Generated once and reused across
		 * attempts so Link Money treats retries of the same order as one request.
		 */
		public static function request_key( $order_id ): string {
			$key      = self::REQKEY_PREFIX . (int) $order_id;
			$existing = self::store()->get( $key );
			if ( null !== $existing && '' !== $existing ) {
				return $existing;
			}

			$uuid = self::uuid();
			self::store()->insert_if_absent( $key, $uuid );

			// Re-read so concurrent callers converge on whichever key landed first.
			$stored = self::store()->get( $key );
			return ( null !== $stored && '' !== $stored ) ? $stored : $uuid;
		}

		/** Drop the stored key so the next attempt is a genuinely new request
		 *  (call only after a CONFIRMED failure, never mid-flight). */
		public static function rotate_request_key( $order_id ): void {
			self::store()->delete( self::REQKEY_PREFIX . (int) $order_id );
		}

		private static function uuid(): string {
			if ( function_exists( 'wp_generate_uuid4' ) ) {
				return wp_generate_uuid4();
			}
			$data    = random_bytes( 16 );
			$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
			$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
			return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
		}
	}

	/**
	 * Production store: wp_options rows. insert_if_absent() uses INSERT IGNORE
	 * against the UNIQUE option_name index — atomic across concurrent PHP-FPM
	 * workers, unlike get_option()/add_option()'s cache-first check-then-act.
	 */
	class LinkMoney_Wpdb_Lock_Store {

		public function insert_if_absent( string $key, string $value ): bool {
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$key,
					$value
				)
			);
			return 1 === (int) $wpdb->rows_affected;
		}

		public function get( string $key ): ?string {
			global $wpdb;
			$value = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $key )
			);
			return ( null === $value ) ? null : (string) $value;
		}

		public function set( string $key, string $value ): void {
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
					 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
					$key,
					$value
				)
			);
		}

		public function delete( string $key ): void {
			global $wpdb;
			$wpdb->delete( $wpdb->options, [ 'option_name' => $key ] );
		}
	}
}
