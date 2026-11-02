<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::store_api_process_linkmoney().
 *
 * The method is hooked to woocommerce_rest_checkout_process_payment_with_context.
 * We call it directly with mock $context and $result objects.
 *
 * 6 tests total.
 */
class StoreApiProcessTest extends \WP_UnitTestCase {

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

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Build a mock $context object matching WooCommerce's PaymentContext shape.
     */
    private function make_context( \WC_Order $order, string $payment_method, string $selected_account ): object {
        $context                = new \stdClass();
        $context->payment_method = $payment_method;
        $context->order          = $order;
        $context->payment_data   = [
            [ 'key' => 'linkmoney_saved_account', 'value' => $selected_account ],
        ];
        return $context;
    }

    /**
     * Build a mock $result object matching WooCommerce's PaymentResult shape.
     */
    private function make_result(): object {
        return new class {
            public ?string $status       = null;
            public ?string $redirect_url = null;
            public ?string $message      = null;

            public function set_status( string $s ): void       { $this->status       = $s; }
            public function set_redirect_url( string $u ): void { $this->redirect_url = $u; }
            public function set_message( string $m ): void      { $this->message      = $m; }
        };
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    /**
     * @test
     * When selected account is '__new', the method should create a session,
     * set checkout-draft status, and redirect to the session URL.
     */
    public function test_new_account_creates_session_and_redirects(): void {
        $this->mock_token_response();
        $this->mock_session_response( 'https://link.money/session/blocks-new' );

        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'linkmoney', '__new' );
        $result  = $this->make_result();

        $this->gateway->store_api_process_linkmoney( $context, $result );

        $this->assertSame( 'success', $result->status );
        $this->assertSame( 'https://link.money/session/blocks-new', $result->redirect_url );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'checkout-draft' ) );
    }

    /**
     * @test
     * When selected account is '' (empty), treated same as __new — creates
     * session and redirects.
     */
    public function test_empty_selection_creates_session(): void {
        $this->mock_token_response();
        $this->mock_session_response( 'https://link.money/session/blocks-empty' );

        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'linkmoney', '' );
        $result  = $this->make_result();

        $this->gateway->store_api_process_linkmoney( $context, $result );

        $this->assertSame( 'success', $result->status );
        $this->assertNotNull( $result->redirect_url );
    }

    /**
     * @test
     * When session creation fails for __new, an Exception should be thrown.
     */
    public function test_new_account_session_failure_throws(): void {
        $this->mock_token_response();
        $this->mock_http_response( 'api.link-sandbox.money/v2/sessions', 400, [ 'error' => 'bad request' ] );

        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'linkmoney', '__new' );
        $result  = $this->make_result();

        $this->expectException( \Exception::class );
        $this->gateway->store_api_process_linkmoney( $context, $result );
    }

    /**
     * @test
     * When a valid saved account ID is selected, the method should call the
     * payments API and set the order to processing with result status 'success'.
     */
    public function test_saved_account_success(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-blocks-001', 'AUTHORIZED' );

        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'linkmoney', 'acct-saved-001' );
        $result  = $this->make_result();

        $this->gateway->store_api_process_linkmoney( $context, $result );

        $this->assertSame( 'success', $result->status );
        $this->assertNotNull( $result->redirect_url );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );
        $this->assertNotEmpty( $order->get_meta( \WC_Gateway_LinkMoney::META_PAYMENT_ID ) );
    }

    /**
     * @test
     * When paymentStatus is PENDING, the order should be set to on-hold.
     */
    public function test_saved_account_pending_sets_on_hold(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-blocks-pending', 'PENDING' );

        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'linkmoney', 'acct-saved-002' );
        $result  = $this->make_result();

        $this->gateway->store_api_process_linkmoney( $context, $result );

        $this->assertSame( 'success', $result->status );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'on-hold' ) );
    }

    /**
     * @test
     * When payment_method is not 'linkmoney', the method returns without action.
     */
    public function test_non_linkmoney_returns_early(): void {
        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'stripe', 'acct-001' );
        $result  = $this->make_result();

        $this->gateway->store_api_process_linkmoney( $context, $result );

        $this->assertNull( $result->status );
    }

    /**
     * @test
     * When get_access_token() fails, an Exception should be thrown.
     */
    public function test_token_failure_throws_exception(): void {
        $this->mock_http_response(
            'api.link-sandbox.money/v1/tokens',
            401,
            [ 'error' => 'invalid_client' ]
        );

        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'linkmoney', 'acct-saved-003' );
        $result  = $this->make_result();

        $this->expectException( \Exception::class );
        $this->gateway->store_api_process_linkmoney( $context, $result );
    }

    /**
     * @test
     * Blocks-path duplicate protection: while a first attempt for the same order
     * still holds the in-flight claim, a second overlapping submission throws
     * and does not charge.
     */
    public function test_concurrent_same_order_second_attempt_throws(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-should-not-happen', 'AUTHORIZED' );

        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'linkmoney', 'acct-saved-race' );
        $result  = $this->make_result();

        // First request has claimed the slot and is still in flight.
        $this->assertTrue( \LinkMoney_Payment_Lock::claim( $order->get_id() ) );

        $this->expectException( \Exception::class );
        $this->gateway->store_api_process_linkmoney( $context, $result );
    }

    /**
     * @test
     * Blocks path previously had no same-order durable guard: an order that was
     * already charged (payment id + processing) must now be blocked from being
     * charged again.
     */
    public function test_already_processed_order_is_blocked(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-should-not-happen', 'AUTHORIZED' );

        $order   = OrderHelper::create_processing_order( 'pay-existing-blocks-001' );
        $context = $this->make_context( $order, 'linkmoney', 'acct-saved-dup' );
        $result  = $this->make_result();

        $this->expectException( \Exception::class );
        $this->gateway->store_api_process_linkmoney( $context, $result );
    }
}
