<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::process_refund().
 *
 * 4 tests total.
 */
class RefundTest extends \WP_UnitTestCase {

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
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * @test
     * A full refund with a valid payment ID should return true and add an order note.
     */
    public function test_full_refund_success(): void {
        $this->mock_token_response();
        $this->mock_refund_response( 'refund-pay-001' );

        $order  = OrderHelper::create_processing_order( 'pay-refund-001' );
        $result = $this->gateway->process_refund( $order->get_id(), 20.00, 'Customer requested refund' );

        $this->assertTrue( $result );

        // Verify an order note was added containing the refund paymentId.
        $order = wc_get_order( $order->get_id() );
        $notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
        $note_content = implode( ' ', array_map( fn( $n ) => $n->content, $notes ) );
        $this->assertStringContainsString( 'refund-pay-001', $note_content );
    }

    /**
     * @test
     * A partial refund should send the correct amount in the API payload.
     */
    public function test_partial_refund(): void {
        $captured_body = null;
        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$captured_body ) {
            if ( strpos( $url, 'v1/refunds' ) !== false ) {
                $captured_body = json_decode( $args['body'], true );
            }
            return $preempt;
        }, 5, 3 );

        $this->mock_token_response();
        $this->mock_refund_response( 'refund-partial-001' );

        $order  = OrderHelper::create_processing_order( 'pay-partial-001' );
        $result = $this->gateway->process_refund( $order->get_id(), 10.00, 'Partial refund' );

        $this->assertTrue( $result );
        $this->assertNotNull( $captured_body );
        $this->assertSame( '10.00', $captured_body['amount']['value'] );
    }

    /**
     * @test
     * When the API returns an error, process_refund() should return a WP_Error.
     */
    public function test_api_failure(): void {
        $this->mock_token_response();
        $this->mock_http_response( 'api.link-sandbox.money/v1/refunds', 400, [
            'errorMessage' => 'Cannot refund a failed payment.',
        ] );

        $order  = OrderHelper::create_processing_order( 'pay-refund-fail' );
        $result = $this->gateway->process_refund( $order->get_id(), 20.00 );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * @test
     * When the order has no META_PAYMENT_ID, process_refund() should return WP_Error.
     */
    public function test_missing_payment_id(): void {
        // Create a processing order WITHOUT a payment ID.
        $order = OrderHelper::create_pending_order();
        $order->update_status( 'processing' );
        $order->save();

        $result = $this->gateway->process_refund( $order->get_id(), 20.00 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'linkmoney_no_payment', $result->get_error_code() );
    }
}
