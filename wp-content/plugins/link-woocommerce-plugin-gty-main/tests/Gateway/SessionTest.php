<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::create_session() and get_access_token().
 *
 * 6 tests total.
 */
class SessionTest extends \WP_UnitTestCase {

    use MockHttpTrait;

    /** @var \WC_Gateway_LinkMoney */
    private \WC_Gateway_LinkMoney $gateway;

    public function setUp(): void {
        parent::setUp();
        $this->setup_mock_http();

        // Configure gateway settings so constructor reads them.
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
     * create_session() should return an array containing sessionUrl on success.
     */
    public function test_create_session_success(): void {
        $this->mock_session_response( 'https://link.money/session/abc123' );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->create_session( $order );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'sessionUrl', $result );
        $this->assertSame( 'https://link.money/session/abc123', $result['sessionUrl'] );
    }

    /**
     * @test
     * create_session() payload should include correct billing data, amount, and redirectUrl.
     */
    public function test_create_session_payload(): void {
        // Capture the request args via pre_http_request before returning success.
        $captured_body = null;
        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$captured_body ) {
            if ( strpos( $url, 'v2/sessions' ) !== false ) {
                $captured_body = json_decode( $args['body'], true );
            }
            return $preempt; // Let MockHttpTrait handle the actual response.
        }, 5, 3 ); // Priority 5 runs before MockHttpTrait's priority-10 callback.

        $this->mock_session_response();

        $order  = OrderHelper::create_pending_order();
        $this->gateway->create_session( $order );

        $this->assertNotNull( $captured_body, 'Session request body was not captured.' );
        $this->assertSame( 'Jane', $captured_body['firstName'] );
        $this->assertSame( 'Doe', $captured_body['lastName'] );
        $this->assertSame( '123 Main St', $captured_body['billingAddress']['streetAddress'] );
        $this->assertArrayHasKey( 'redirectUrl', $captured_body );
        $this->assertStringContainsString( 'wc-api=linkmoney', $captured_body['redirectUrl'] );
        // Amount should be formatted as "20.00"
        $this->assertSame( '20.00', $captured_body['orderDetails']['totalAmount']['value'] );
    }

    /**
     * @test
     * create_session() should return WP_Error when the API returns HTTP 400+.
     */
    public function test_create_session_api_failure(): void {
        $this->mock_http_response( 'api.link-sandbox.money/v2/sessions', 400, [ 'error' => 'bad request' ] );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->create_session( $order );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * @test
     * get_access_token() should return a token string on success.
     * Uses ReflectionMethod because the method is protected.
     */
    public function test_get_access_token_success(): void {
        $this->mock_token_response( 'my-access-token' );

        $method = new \ReflectionMethod( \WC_Gateway_LinkMoney::class, 'get_access_token' );
        $method->setAccessible( true );

        $token = $method->invoke( $this->gateway, 'Link-Payment' );

        $this->assertIsString( $token );
        $this->assertSame( 'my-access-token', $token );
    }

    /**
     * @test
     * get_access_token() should return WP_Error when the API returns 401.
     */
    public function test_get_access_token_failure(): void {
        $this->mock_http_response(
            'api.link-sandbox.money/v1/tokens',
            401,
            [ 'error' => 'invalid_client' ]
        );

        $method = new \ReflectionMethod( \WC_Gateway_LinkMoney::class, 'get_access_token' );
        $method->setAccessible( true );

        $result = $method->invoke( $this->gateway, 'Link-Payment' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'linkmoney_token', $result->get_error_code() );
    }

    /**
     * @test
     * create_session() for a guest (not logged in) should use billing_email from the order.
     */
    public function test_guest_uses_order_email(): void {
        // Ensure no user is logged in.
        wp_set_current_user( 0 );

        $captured_body = null;
        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$captured_body ) {
            if ( strpos( $url, 'v2/sessions' ) !== false ) {
                $captured_body = json_decode( $args['body'], true );
            }
            return $preempt;
        }, 5, 3 );

        $this->mock_session_response();

        $order  = OrderHelper::create_pending_order();
        $this->gateway->create_session( $order );

        $this->assertNotNull( $captured_body );
        // Billing email set by OrderHelper::create_order is 'jane@example.com'.
        $this->assertSame( 'jane@example.com', $captured_body['email'] );
    }
}
