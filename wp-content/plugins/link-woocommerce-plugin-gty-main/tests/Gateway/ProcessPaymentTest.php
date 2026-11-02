<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::process_payment() — classic checkout entry point.
 *
 * 3 tests total.
 */
class ProcessPaymentTest extends \WP_UnitTestCase {

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

        // Start with clean $_POST.
        $_POST = [];
    }

    public function tearDown(): void {
        $_POST = [];
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * @test
     * When no saved account is selected (__new), process_payment() should create a
     * Link Money session, update the order to checkout-draft, and return a success
     * result redirecting to the sessionUrl.
     */
    public function test_new_account_creates_session_and_redirects(): void {
        $_POST['linkmoney_saved_account'] = '__new';

        $this->mock_session_response( 'https://link.money/session/new-flow' );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_payment( $order->get_id() );

        $this->assertIsArray( $result );
        $this->assertSame( 'success', $result['result'] );
        $this->assertStringContainsString( 'https://link.money/session/new-flow', $result['redirect'] );

        // Order should now be in checkout-draft status.
        $order = wc_get_order( $order->get_id() ); // Refresh.
        $this->assertTrue( $order->has_status( 'checkout-draft' ) );
    }

    /**
     * @test
     * When the session API fails, process_payment() should return a failure result.
     */
    public function test_new_account_session_failure(): void {
        $_POST['linkmoney_saved_account'] = '__new';

        $this->mock_http_response( 'api.link-sandbox.money/v2/sessions', 500, [ 'error' => 'server error' ] );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_payment( $order->get_id() );

        $this->assertIsArray( $result );
        $this->assertSame( 'failure', $result['result'] );
    }

    /**
     * @test
     * When a saved account ID is in $_POST, process_payment() delegates to
     * process_saved_payment(), resulting in order → processing.
     */
    public function test_saved_account_delegates_to_process_saved_payment(): void {
        $_POST['linkmoney_saved_account'] = 'acct-saved-001';

        $this->mock_token_response();
        $this->mock_payment_response( 'pay-001', 'AUTHORIZED' );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_payment( $order->get_id() );

        $this->assertIsArray( $result );
        $this->assertSame( 'success', $result['result'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );
    }
}
