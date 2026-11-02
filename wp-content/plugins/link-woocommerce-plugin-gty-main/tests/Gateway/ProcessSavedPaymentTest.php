<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::process_saved_payment().
 *
 * 7 tests total.
 */
class ProcessSavedPaymentTest extends \WP_UnitTestCase {

    use MockHttpTrait;

    /** @var \WC_Gateway_LinkMoney */
    private \WC_Gateway_LinkMoney $gateway;

    public function setUp(): void {
        parent::setUp();
        $this->setup_mock_http();

        update_option( 'woocommerce_linkmoney_settings', [
            'enabled'       => 'yes',
            'environment'   => 'sandbox',
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'merchant_id'   => 'test-merchant-id',
        ] );

        $this->gateway = new \WC_Gateway_LinkMoney();
    }

    public function tearDown(): void {
        \LinkMoney_Payment_Lock::reset();
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * @test
     * On a successful AUTHORIZED payment, the order becomes processing,
     * META_EVER_PROCESSING is set, and the result is success.
     */
    public function test_success_sets_processing(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-success-001', 'AUTHORIZED' );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $this->assertSame( 'success', $result['result'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );
        $this->assertNotEmpty( $order->get_meta( \WC_Gateway_LinkMoney::META_EVER_PROCESSING ) );
    }

    /**
     * @test
     * When paymentStatus is PENDING, the order should be set to on-hold.
     */
    public function test_pending_sets_on_hold(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-pending-001', 'PENDING' );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'on-hold' ) );
    }

    /**
     * @test
     * When the payments API returns HTTP 4xx, the order should be set to failed.
     */
    public function test_declined_sets_failed(): void {
        $this->mock_token_response();
        $this->mock_http_response( 'api.link-sandbox.money/v1/payments', 402, [
            'errorMessage' => 'Insufficient funds.',
        ] );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $this->assertSame( 'failure', $result['result'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'failed' ) );
    }

    /**
     * @test
     * When paymentStatus is TERMINAL_FAILED, the order should be set to failed.
     */
    public function test_terminal_failed_sets_failed(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-terminal-001', 'TERMINAL_FAILED' );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $this->assertSame( 'failure', $result['result'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'failed' ) );
    }

    /**
     * @test
     * The duplicate guard should block resubmission when META_PAYMENT_ID is set
     * and the order is not in a failed status.
     */
    public function test_duplicate_guard_blocks_resubmit(): void {
        $this->mock_token_response();
        // No payment mock needed — method should bail before calling the API.

        $order = OrderHelper::create_processing_order( 'pay-existing-001' );
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $this->assertSame( 'failure', $result['result'] );
    }

    /**
     * @test
     * A failed order that previously reached processing (META_EVER_PROCESSING = true)
     * should be set to completed when payment succeeds again.
     */
    public function test_retry_failed_order_sets_completed(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-retry-001', 'AUTHORIZED' );

        $order = OrderHelper::create_failed_order( true ); // ever_processing = true
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $this->assertSame( 'success', $result['result'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'completed' ) );
    }

    /**
     * @test
     * When get_access_token() fails, the order should be set to failed and
     * result should indicate failure.
     */
    public function test_token_failure(): void {
        $this->mock_http_response(
            'api.link-sandbox.money/v1/tokens',
            401,
            [ 'error' => 'invalid_client' ]
        );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $this->assertSame( 'failure', $result['result'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'failed' ) );
    }

    /**
     * @test
     * Regression for the duplicate charge on order #22017: while a first
     * attempt for the same order still holds the in-flight claim, a second
     * overlapping submission must be turned away WITHOUT calling the payments
     * API — no second payment id is recorded and the order is untouched.
     */
    public function test_concurrent_same_order_second_attempt_is_blocked(): void {
        $this->mock_token_response();
        // A payment mock is deliberately registered; if the guard failed and the
        // API were called, the order would be charged and this test would fail.
        $this->mock_payment_response( 'pay-should-not-happen', 'AUTHORIZED' );

        $order = OrderHelper::create_pending_order();

        // Simulate the first request having claimed the slot and still being in
        // flight (its payment id not yet persisted).
        $this->assertTrue( \LinkMoney_Payment_Lock::claim( $order->get_id() ) );

        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $this->assertSame( 'failure', $result['result'] );

        $reloaded = wc_get_order( $order->get_id() );
        $this->assertEmpty(
            $reloaded->get_meta( \WC_Gateway_LinkMoney::META_PAYMENT_ID ),
            'blocked attempt must not record a payment id'
        );
        $this->assertTrue( $reloaded->has_status( 'pending' ), 'blocked attempt must not advance the order' );
    }

    /**
     * @test
     * A declined attempt releases the in-flight claim so the customer can
     * legitimately retry the same order.
     */
    public function test_declined_attempt_releases_claim_for_retry(): void {
        $this->mock_token_response();
        $this->mock_http_response( 'api.link-sandbox.money/v1/payments', 402, [
            'errorMessage' => 'Insufficient funds.',
        ] );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );
        $this->assertSame( 'failure', $result['result'] );

        // The slot is free again: a fresh claim for the same order succeeds.
        $this->assertTrue(
            \LinkMoney_Payment_Lock::claim( $order->get_id() ),
            'declined attempt should have released the claim'
        );
    }

    /**
     * @test
     * A payment can authorize and then fail LATER via an ACH-return webhook.
     * When the customer links a different bank and retries the same order, the
     * request must carry a FRESH idempotency key — otherwise Link Money would
     * dedup it against the original (now-returned) payment and block a valid
     * second payment.
     */
    public function test_retry_after_webhook_failure_uses_a_fresh_idempotency_key(): void {
        // Attempt 1 (e.g. Chase) authorizes successfully.
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-chase-001', 'AUTHORIZED' );

        $order = OrderHelper::create_pending_order();
        $this->gateway->process_saved_payment( $order, 'acct-chase' );

        $payments = $this->captured_request_bodies( '/v1/payments' );
        $this->assertCount( 1, $payments );
        $first_key = $payments[0]['requestKey'];
        $this->assertNotEmpty( $first_key );

        // Later, an ACH return (R01 insufficient funds) arrives by webhook and
        // marks the order failed.
        $order = wc_get_order( $order->get_id() );
        $order->update_status( 'failed' );
        $order->save();

        // Attempt 2 (e.g. Bank of America) on the SAME order.
        $this->mock_payment_response( 'pay-boa-001', 'AUTHORIZED' );
        $result = $this->gateway->process_saved_payment( $order, 'acct-boa' );

        $this->assertSame( 'success', $result['result'], 'retry with a different bank must be allowed' );

        $payments = $this->captured_request_bodies( '/v1/payments' );
        $this->assertCount( 2, $payments );
        $second_key = $payments[1]['requestKey'];

        $this->assertNotSame(
            $first_key,
            $second_key,
            'retry after a webhook failure must use a fresh idempotency key'
        );
    }
}
