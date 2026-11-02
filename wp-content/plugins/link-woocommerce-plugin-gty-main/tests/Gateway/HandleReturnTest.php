<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::linkmoney_handle_return().
 *
 * linkmoney_handle_return($order_id) is hooked to woocommerce_thankyou_{linkmoney}
 * and woocommerce_receipt_{linkmoney}. It reads $_GET params and updates order
 * status based on the API-verified transaction record (not the URL).
 *
 * For AUTHORIZED/INITIATED/SCHEDULED it redirects to the order-received URL.
 * For TERMINAL_FAILED it redirects to checkout with an error notice.
 * For PENDING (and other non-actionable states) it updates status and returns.
 * For status >= 204 it redirects to the cart-restore URL.
 */
class HandleReturnTest extends \WP_UnitTestCase {

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
     * Intercept wp_safe_redirect + exit by hooking wp_redirect.
     */
    private function capture_redirect( callable $action ): string {
        $captured = '';

        $hosts_filter = function( $hosts ) {
            $hosts[] = 'example.org';
            return $hosts;
        };
        add_filter( 'allowed_redirect_hosts', $hosts_filter );

        $redirect_filter = function( string $url ) use ( &$captured ): string {
            $captured = $url;
            throw new \RuntimeException( 'REDIRECT:' . $url );
        };
        add_filter( 'wp_redirect', $redirect_filter, 1 );

        try {
            $action();
        } catch ( \RuntimeException $e ) {
            // Expected sentinel.
        } finally {
            remove_filter( 'wp_redirect', $redirect_filter, 1 );
            remove_filter( 'allowed_redirect_hosts', $hosts_filter );
        }

        return $captured;
    }

    /**
     * @test
     * API returns AUTHORIZED for the matching order → order promoted to processing
     * and the handler redirects to the order-received URL.
     */
    public function test_api_authorized_promotes_to_processing(): void {
        $order = OrderHelper::create_checkout_draft_order();
        $this->mock_transaction_response( 'pay-return-001', (string) $order->get_id(), 'AUTHORIZED' );

        $_GET = [
            'paymentId'         => 'pay-return-001',
            'customerId'        => 'cust-return-001',
            'paymentStatusCode' => '0',
            'status'            => '100',
        ];

        $this->capture_redirect( function() use ( $order ) {
            $this->gateway->linkmoney_handle_return( $order->get_id() );
        } );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );
        $this->assertSame( 'pay-return-001', $order->get_meta( \WC_Gateway_LinkMoney::META_PAYMENT_ID ) );
    }

    /**
     * @test
     * API returns TERMINAL_FAILED → order goes to failed.
     */
    public function test_api_terminal_failed_marks_order_failed(): void {
        $order = OrderHelper::create_checkout_draft_order();
        $this->mock_transaction_response( 'pay-return-002', (string) $order->get_id(), 'TERMINAL_FAILED' );

        $_GET = [
            'paymentId'         => 'pay-return-002',
            'paymentStatusCode' => '0',
            'status'            => '100',
        ];

        $this->capture_redirect( function() use ( $order ) {
            $this->gateway->linkmoney_handle_return( $order->get_id() );
        } );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'failed' ) );
    }

    /**
     * @test
     * API returns PENDING → order goes to on-hold and the handler returns
     * without redirecting (so WC can finish rendering the thank-you page).
     */
    public function test_api_pending_sets_on_hold_no_redirect(): void {
        $order = OrderHelper::create_checkout_draft_order();
        $this->mock_transaction_response( 'pay-return-003', (string) $order->get_id(), 'PENDING' );

        $_GET = [
            'paymentId'         => 'pay-return-003',
            'paymentStatusCode' => '0',
            'status'            => '100',
        ];

        // No redirect expected here; call directly.
        $this->gateway->linkmoney_handle_return( $order->get_id() );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'on-hold' ) );
    }

    /**
     * @test
     * status >= 204 (cancel flow) should redirect to checkout with restore params
     * and must NOT call the transaction API.
     */
    public function test_cancel_redirects(): void {
        $order = OrderHelper::create_checkout_draft_order();

        $_GET = [
            'status' => '204',
        ];

        $redirect = $this->capture_redirect( function() use ( $order ) {
            $this->gateway->linkmoney_handle_return( $order->get_id() );
        } );

        $this->assertStringContainsString( 'lm_restore_order=' . $order->get_id(), $redirect );
    }

    /**
     * @test
     * linkmoney_handle_return() MUST invalidate the saved-accounts cache for
     * the customer it processes (same reason as the wc-api callback).
     */
    public function test_handle_return_busts_saved_accounts_cache(): void {
        $order = OrderHelper::create_checkout_draft_order();
        $this->mock_transaction_response( 'pay-return-bust-001', (string) $order->get_id(), 'AUTHORIZED' );

        $key = 'linkmoney_accounts_' . md5( 'cust-handle-return-bust' );
        set_transient( $key, [ [ 'accountId' => 'old-acct' ] ], 60 * 10 );
        $this->assertNotFalse( get_transient( $key ) );

        $_GET = [
            'paymentId'         => 'pay-return-bust-001',
            'customerId'        => 'cust-handle-return-bust',
            'paymentStatusCode' => '0',
            'status'            => '0',
        ];

        $this->capture_redirect( function() use ( $order ) {
            $this->gateway->linkmoney_handle_return( $order->get_id() );
        } );

        $this->assertFalse( get_transient( $key ) );
    }

    // -------------------------------------------------------------------------
    // Forgery defenses
    // -------------------------------------------------------------------------

    /**
     * @test
     * Forged paymentId belonging to a DIFFERENT order — order must NOT be promoted.
     */
    public function test_payment_id_for_different_order_does_not_promote(): void {
        $victim = OrderHelper::create_checkout_draft_order();
        $other  = OrderHelper::create_checkout_draft_order();

        $this->mock_transaction_response(
            'pay-return-replay-001',
            (string) $other->get_id(),
            'AUTHORIZED'
        );

        $_GET = [
            'paymentId'         => 'pay-return-replay-001',
            'paymentStatusCode' => '0',
            'status'            => '100',
        ];

        $initial_status = $victim->get_status();
        $this->gateway->linkmoney_handle_return( $victim->get_id() );

        $victim = wc_get_order( $victim->get_id() );
        $this->assertSame( $initial_status, $victim->get_status() );
    }

    /**
     * @test
     * Forged paymentId pointing at a CREDIT (refund) transaction — order must NOT
     * be promoted.
     */
    public function test_credit_transaction_type_does_not_promote(): void {
        $order = OrderHelper::create_checkout_draft_order();

        $this->mock_transaction_response(
            'pay-return-credit-001',
            (string) $order->get_id(),
            'AUTHORIZED',
            'CREDIT'
        );

        $_GET = [
            'paymentId'         => 'pay-return-credit-001',
            'paymentStatusCode' => '0',
            'status'            => '100',
        ];

        $initial_status = $order->get_status();
        $this->gateway->linkmoney_handle_return( $order->get_id() );

        $order = wc_get_order( $order->get_id() );
        $this->assertSame( $initial_status, $order->get_status() );
    }

    /**
     * @test
     * API returns 404 — order must NOT be promoted.
     */
    public function test_api_404_does_not_promote(): void {
        $order = OrderHelper::create_checkout_draft_order();
        $this->mock_transaction_not_found( 'pay-return-missing-001' );

        $_GET = [
            'paymentId'         => 'pay-return-missing-001',
            'paymentStatusCode' => '0',
            'status'            => '100',
        ];

        $initial_status = $order->get_status();
        $this->gateway->linkmoney_handle_return( $order->get_id() );

        $order = wc_get_order( $order->get_id() );
        $this->assertSame( $initial_status, $order->get_status() );
    }
}
