<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::handle_wc_api_callback().
 *
 * handle_wc_api_callback() calls wp_safe_redirect() + exit on every code path.
 * We intercept the redirect via the 'wp_redirect' filter and throw an exception
 * to prevent the exit. Tests catch the exception and assert on the redirect URL
 * and order state.
 *
 * The callback now verifies the URL-supplied paymentId against Link Money's
 * GET /v1/transactions/{id} endpoint before promoting status. URL params can
 * no longer drive status on their own.
 */
class CallbackTest extends \WP_UnitTestCase {

    use MockHttpTrait;

    /** @var \WC_Gateway_LinkMoney */
    private \WC_Gateway_LinkMoney $gateway;

    public function setUp(): void {
        parent::setUp();
        $this->setup_mock_http();
        $this->mock_token_response();

        update_option( 'woocommerce_linkmoney_settings', [
            'enabled'       => 'yes',
            'environment'   => 'sandbox',
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'merchant_id'   => 'test-merchant-id',
        ] );

        $this->gateway = new \WC_Gateway_LinkMoney();
        $_GET = [];
    }

    public function tearDown(): void {
        $_GET = [];
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * Register a wp_redirect filter that captures the URL and throws an exception
     * to abort execution (preventing the wp exit call).
     *
     * @return string The redirect URL captured by the filter.
     */
    private function capture_redirect( callable $action ): string {
        $captured = '';

        // Allow example.org for wp_safe_redirect.
        $hosts_filter = function( $hosts ) {
            $hosts[] = 'example.org';
            return $hosts;
        };
        add_filter( 'allowed_redirect_hosts', $hosts_filter );

        // Intercept wp_redirect to capture URL and abort.
        $redirect_filter = function( string $url ) use ( &$captured ): string {
            $captured = $url;
            throw new \RuntimeException( 'REDIRECT:' . $url );
        };
        add_filter( 'wp_redirect', $redirect_filter, 1 );

        try {
            $action();
        } catch ( \RuntimeException $e ) {
            // Expected — the redirect threw this.
        } finally {
            remove_filter( 'wp_redirect', $redirect_filter, 1 );
            remove_filter( 'allowed_redirect_hosts', $hosts_filter );
        }

        return $captured;
    }

    /**
     * @test
     * API returns AUTHORIZED for the matching order → order promoted to processing.
     */
    public function test_api_authorized_promotes_to_processing(): void {
        $order = OrderHelper::create_checkout_draft_order();
        $this->mock_transaction_response( 'pay-cb-001', (string) $order->get_id(), 'AUTHORIZED' );

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentId'         => 'pay-cb-001',
            'customerId'        => 'cust-cb-001',
            'paymentStatusCode' => '0',
        ];

        $redirect = $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $this->assertStringContainsString( 'order-received', $redirect );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );
        $this->assertSame( 'pay-cb-001', $order->get_meta( \WC_Gateway_LinkMoney::META_PAYMENT_ID ) );
    }

    /**
     * @test
     * API returns TERMINAL_FAILED → order goes to failed and customer is sent
     * back to checkout with an error notice.
     */
    public function test_api_terminal_failed_routes_to_checkout(): void {
        $order = OrderHelper::create_checkout_draft_order();
        $this->mock_transaction_response( 'pay-cb-002', (string) $order->get_id(), 'TERMINAL_FAILED' );

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentId'         => 'pay-cb-002',
            'paymentStatusCode' => '0', // URL claims success — API says otherwise.
        ];

        $redirect = $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $this->assertNotEmpty( $redirect );
        $this->assertStringNotContainsString( 'order-received', $redirect );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'failed' ) );
    }

    /**
     * @test
     * API returns PENDING → order goes to on-hold (only if currently
     * pending/checkout-draft) and customer lands on thank-you.
     */
    public function test_api_pending_sets_on_hold(): void {
        $order = OrderHelper::create_checkout_draft_order();
        $this->mock_transaction_response( 'pay-cb-003', (string) $order->get_id(), 'PENDING' );

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentId'         => 'pay-cb-003',
            'paymentStatusCode' => '0',
        ];

        $redirect = $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $this->assertNotEmpty( $redirect );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'on-hold' ) );
    }

    /**
     * @test
     * status=204 (cancel) should redirect to checkout with lm_restore_order
     * params and must NOT call the transaction API.
     */
    public function test_cancel_status_204(): void {
        $order = OrderHelper::create_checkout_draft_order();

        $_GET = [
            'order_id'  => $order->get_id(),
            'order_key' => $order->get_order_key(),
            'status'    => '204',
        ];

        $redirect = $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $this->assertStringContainsString( 'lm_restore_order=' . $order->get_id(), $redirect );
        $this->assertStringContainsString( 'lm_restore_key=', $redirect );
    }

    /**
     * @test
     * paymentId and customerId in $_GET should be saved to order meta and user meta
     * even before the transaction lookup occurs.
     */
    public function test_saves_payment_metadata(): void {
        $user_id = $this->factory->user->create();
        $order   = OrderHelper::create_checkout_draft_order();
        $order->set_customer_id( $user_id );
        $order->save();

        $this->mock_transaction_response( 'pay-meta-001', (string) $order->get_id(), 'AUTHORIZED' );

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentId'         => 'pay-meta-001',
            'customerId'        => 'cust-meta-001',
            'paymentStatusCode' => '0',
        ];

        $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $order = wc_get_order( $order->get_id() );
        $this->assertSame( 'pay-meta-001', $order->get_meta( \WC_Gateway_LinkMoney::META_PAYMENT_ID ) );
        $this->assertSame( 'cust-meta-001', get_user_meta( $user_id, \WC_Gateway_LinkMoney::META_CUSTOMER_ID, true ) );
    }

    /**
     * @test
     * An invalid order_id should redirect to checkout without modifying any order.
     */
    public function test_invalid_order(): void {
        $_GET = [
            'order_id'  => 999999,
            'order_key' => 'wrong-key',
        ];

        $redirect = $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $this->assertNotEmpty( $redirect );
        $this->assertStringNotContainsString( 'order-received', $redirect );
    }

    /**
     * @test
     * An order using a different payment gateway should redirect to thank-you
     * without processing any payment status logic.
     */
    public function test_wrong_payment_method(): void {
        $order = wc_create_order( [ 'status' => 'pending', 'payment_method' => 'stripe' ] );
        $order->save();

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentStatusCode' => '0',
        ];

        $redirect = $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $this->assertStringContainsString( 'order-received', $redirect );
        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'pending' ) );
    }

    /**
     * @test
     * The wc-api callback MUST invalidate the saved-accounts cache for the
     * customer it processes, so a freshly linked bank shows up next checkout.
     */
    public function test_callback_busts_saved_accounts_cache(): void {
        $order = OrderHelper::create_checkout_draft_order();
        $this->mock_transaction_response( 'pay-bust-001', (string) $order->get_id(), 'AUTHORIZED' );

        $key = 'linkmoney_accounts_' . md5( 'cust-callback-bust' );
        set_transient( $key, [ [ 'accountId' => 'old-acct' ] ], 60 * 10 );
        $this->assertNotFalse( get_transient( $key ), 'Sanity check: cache should be populated.' );

        $_GET = [
            'order_id'          => (string) $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentId'         => 'pay-bust-001',
            'customerId'        => 'cust-callback-bust',
            'paymentStatusCode' => '0',
        ];

        $this->capture_redirect( function() {
            $this->gateway->handle_wc_api_callback();
        } );

        $this->assertFalse( get_transient( $key ), 'Cache should be busted after callback.' );
    }

    // -------------------------------------------------------------------------
    // Forgery defenses
    // -------------------------------------------------------------------------

    /**
     * @test
     * Forged URL with paymentStatusCode=0 but no paymentId — order must NOT be
     * promoted. The only proof of payment is the API lookup; without a paymentId
     * there is nothing to verify.
     */
    public function test_no_payment_id_does_not_promote(): void {
        $order = OrderHelper::create_checkout_draft_order();
        $initial_status = $order->get_status();

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentStatusCode' => '0', // URL says success — but no paymentId to verify.
        ];

        $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $order = wc_get_order( $order->get_id() );
        $this->assertSame( $initial_status, $order->get_status() );
    }

    /**
     * @test
     * Forged URL with a paymentId that belongs to a DIFFERENT order (replay
     * attack) — order must NOT be promoted because clientReferenceId mismatches.
     */
    public function test_payment_id_for_different_order_does_not_promote(): void {
        $victim_order = OrderHelper::create_checkout_draft_order();
        $other_order  = OrderHelper::create_checkout_draft_order();

        // API returns a real, AUTHORIZED transaction — but its clientReferenceId
        // is the OTHER order's id, not the victim's.
        $this->mock_transaction_response(
            'pay-replay-001',
            (string) $other_order->get_id(),
            'AUTHORIZED'
        );

        $_GET = [
            'order_id'          => $victim_order->get_id(),
            'order_key'         => $victim_order->get_order_key(),
            'paymentId'         => 'pay-replay-001',
            'paymentStatusCode' => '0',
        ];

        $initial_status = $victim_order->get_status();
        $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $victim_order = wc_get_order( $victim_order->get_id() );
        $this->assertSame( $initial_status, $victim_order->get_status() );
    }

    /**
     * @test
     * Forged URL pointing at a CREDIT (refund) paymentId — order must NOT be
     * promoted. The customer-return path only ever processes DEBIT transactions.
     */
    public function test_credit_transaction_type_does_not_promote(): void {
        $order = OrderHelper::create_checkout_draft_order();

        // Even with a matching clientReferenceId, a CREDIT must not promote.
        $this->mock_transaction_response(
            'pay-credit-001',
            (string) $order->get_id(),
            'AUTHORIZED',
            'CREDIT'
        );

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentId'         => 'pay-credit-001',
            'paymentStatusCode' => '0',
        ];

        $initial_status = $order->get_status();
        $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $order = wc_get_order( $order->get_id() );
        $this->assertSame( $initial_status, $order->get_status() );
    }

    /**
     * @test
     * API returns 404 for the paymentId — order must NOT be promoted.
     */
    public function test_api_404_does_not_promote(): void {
        $order = OrderHelper::create_checkout_draft_order();
        $this->mock_transaction_not_found( 'pay-missing-001' );

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentId'         => 'pay-missing-001',
            'paymentStatusCode' => '0',
        ];

        $initial_status = $order->get_status();
        $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $order = wc_get_order( $order->get_id() );
        $this->assertSame( $initial_status, $order->get_status() );
    }

    /**
     * @test
     * API returns RETRYABLE_FAILED — internal Link Money state, not merchant
     * actionable. Order must remain in its current state (not flipped to failed).
     */
    public function test_retryable_failed_is_noop(): void {
        $order = OrderHelper::create_checkout_draft_order();
        $this->mock_transaction_response( 'pay-retry-001', (string) $order->get_id(), 'RETRYABLE_FAILED' );

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentId'         => 'pay-retry-001',
            'paymentStatusCode' => '0',
        ];

        $initial_status = $order->get_status();
        $redirect = $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $order = wc_get_order( $order->get_id() );
        $this->assertSame( $initial_status, $order->get_status() );
        // Lands on thank-you, not back at checkout.
        $this->assertStringContainsString( 'order-received', $redirect );
    }
}
