<?php
/**
 * Pure unit tests for LinkMoney_Payment_Lock — the atomic same-order payment
 * guard that closes the check-then-act (TOCTOU) window responsible for the
 * duplicate charge on order #22017 (two /v1/payments calls 14s apart).
 *
 * These tests run WITHOUT WordPress or MySQL: the lock is exercised against an
 * in-memory store whose insert_if_absent() models the atomic "INSERT ... on a
 * UNIQUE index" primitive the production store relies on.
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-linkmoney-payment-lock.php';

/**
 * In-memory stand-in for the $wpdb-backed lock store. insert_if_absent() is
 * atomic by contract: it inserts only when the key is absent, mirroring
 * `INSERT IGNORE` against wp_options.option_name (UNIQUE).
 */
class InMemoryLockStore {
	public array $data = [];

	public function insert_if_absent( string $key, string $value ): bool {
		if ( array_key_exists( $key, $this->data ) ) {
			return false;
		}
		$this->data[ $key ] = $value;
		return true;
	}

	public function get( string $key ): ?string {
		return $this->data[ $key ] ?? null;
	}

	public function set( string $key, string $value ): void {
		$this->data[ $key ] = $value;
	}

	public function delete( string $key ): void {
		unset( $this->data[ $key ] );
	}
}

final class PaymentLockTest extends TestCase {
	private InMemoryLockStore $store;
	private int $now;

	protected function setUp(): void {
		$this->store = new InMemoryLockStore();
		$this->now   = 1_000_000;
		LinkMoney_Payment_Lock::set_store( $this->store );
		LinkMoney_Payment_Lock::set_clock( fn() => $this->now );
	}

	protected function tearDown(): void {
		LinkMoney_Payment_Lock::reset();
	}

	/** The core regression: a second concurrent attempt on the same order loses the claim. */
	public function test_second_concurrent_claim_on_same_order_is_denied(): void {
		$this->assertTrue( LinkMoney_Payment_Lock::claim( 22017 ), 'first attempt should win the claim' );
		$this->assertFalse( LinkMoney_Payment_Lock::claim( 22017 ), 'second in-flight attempt must be blocked' );
	}

	/** Different orders never collide. */
	public function test_claims_on_different_orders_are_independent(): void {
		$this->assertTrue( LinkMoney_Payment_Lock::claim( 22017 ) );
		$this->assertTrue( LinkMoney_Payment_Lock::claim( 22018 ) );
	}

	/** After a declined payment the slot is released so a legitimate retry can proceed. */
	public function test_release_allows_a_later_retry(): void {
		$this->assertTrue( LinkMoney_Payment_Lock::claim( 22017 ) );
		LinkMoney_Payment_Lock::release( 22017 );
		$this->assertTrue( LinkMoney_Payment_Lock::claim( 22017 ), 'retry after release should win the claim' );
	}

	/** A crashed request must not wedge an order forever: a stale lock is reclaimable. */
	public function test_stale_lock_past_ttl_is_reclaimable(): void {
		$this->assertTrue( LinkMoney_Payment_Lock::claim( 22017 ) );
		$this->assertFalse( LinkMoney_Payment_Lock::claim( 22017 ) );

		$this->now += LinkMoney_Payment_Lock::LOCK_TTL + 1;
		$this->assertTrue( LinkMoney_Payment_Lock::claim( 22017 ), 'lock older than TTL should be reclaimable' );
	}

	/** requestKey is stable per order so Link Money can dedupe server-side. */
	public function test_request_key_is_stable_for_the_same_order(): void {
		$first  = LinkMoney_Payment_Lock::request_key( 22017 );
		$second = LinkMoney_Payment_Lock::request_key( 22017 );
		$this->assertNotSame( '', $first );
		$this->assertSame( $first, $second, 'same order must reuse one idempotency key' );
	}

	/** Distinct orders get distinct keys. */
	public function test_request_key_differs_across_orders(): void {
		$this->assertNotSame(
			LinkMoney_Payment_Lock::request_key( 22017 ),
			LinkMoney_Payment_Lock::request_key( 22018 )
		);
	}

	/** A confirmed failure rotates the key so the retry is a genuinely new request. */
	public function test_rotate_request_key_changes_it(): void {
		$original = LinkMoney_Payment_Lock::request_key( 22017 );
		LinkMoney_Payment_Lock::rotate_request_key( 22017 );
		$this->assertNotSame( $original, LinkMoney_Payment_Lock::request_key( 22017 ) );
	}
}
