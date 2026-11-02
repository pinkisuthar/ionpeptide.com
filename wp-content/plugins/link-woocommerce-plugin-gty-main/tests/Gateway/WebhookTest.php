<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::handle_webhook() (static REST endpoint handler).
 *
 * Signature formula: base64(hmac_sha512(unique_id . '.' . timestamp . '.' . body, secret))
 *
 * 7 tests total.
 */
class WebhookTest extends \WP_UnitTestCase {

    use MockHttpTrait;

    private const WEBHOOK_SECRET = 'test-webhook-secret-abc123';

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

        // Store the webhook secret as the gateway saves it.
        update_option( 'linkmoney_webhook_secret', self::WEBHOOK_SECRET );
    }

    public function tearDown(): void {
        delete_option( 'linkmoney_webhook_secret' );
        $this->teardown_mock_http();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Build a WP_REST_Request with the correct HMAC signature headers.
     *
     * @param array  $body_data    Associative array for the JSON request body.
     * @param string $secret       Secret to sign with (defaults to self::WEBHOOK_SECRET).
     * @param string $unique_id    Value for x-signature-uniqueid header.
     * @param string $timestamp    Value for x-signature-timestamp header.
     * @return \WP_REST_Request
     */
    private function make_signed_request(
        array  $body_data,
        string $secret    = self::WEBHOOK_SECRET,
        string $unique_id = 'test-unique-id-001',
        string $timestamp = '1700000000'
    ): \WP_REST_Request {
        $body_json = wp_json_encode( $body_data );
        $signature = base64_encode(
            hash_hmac( 'sha512', "{$unique_id}.{$timestamp}.{$body_json}", $secret, true )
        );

        $request = new \WP_REST_Request( 'POST', '/linkmoney/v1/webhook' );
        $request->set_body( $body_json );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_header( 'x-signature', $signature );
        $request->set_header( 'x-signature-uniqueid', $unique_id );
        $request->set_header( 'x-signature-timestamp', $timestamp );

        return $request;
    }

    /**
     * Build the payload for a webhook event.
     */
    private function make_event_payload( string $event_type, string $payment_id ): array {
        return [
            'eventType' => $event_type,
            'metadata'  => [
                'resourceId' => $payment_id,
            ],
        ];
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    /**
     * @test
     * payment.authorized event should set order to processing and trigger emails.
     */
    public function test_payment_authorized(): void {
        $order = OrderHelper::create_processing_order( 'pay-webhook-auth' );

        $request  = $this->make_signed_request(
            $this->make_event_payload( 'payment.authorized', 'pay-webhook-auth' )
        );
        $response = \WC_Gateway_LinkMoney::handle_webhook( $request );

        $this->assertNotInstanceOf( \WP_Error::class, $response );

        $data = $response->get_data();
        $this->assertTrue( $data['success'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );
    }

    /**
     * @test
     * payment.succeeded on an order that is NOT on-hold (e.g. already processing)
     * should leave the status untouched and only add the settled order note.
     */
    public function test_payment_succeeded_on_processing_order_keeps_status(): void {
        $order            = OrderHelper::create_processing_order( 'pay-webhook-settled' );
        $initial_status   = $order->get_status();

        $request  = $this->make_signed_request(
            $this->make_event_payload( 'payment.succeeded', 'pay-webhook-settled' )
        );
        $response = \WC_Gateway_LinkMoney::handle_webhook( $request );

        $this->assertNotInstanceOf( \WP_Error::class, $response );

        // Status should not change for a non-on-hold order.
        $order = wc_get_order( $order->get_id() );
        $this->assertSame( $initial_status, $order->get_status() );

        // An order note should be added.
        $notes        = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
        $note_content = implode( ' ', array_map( fn( $n ) => $n->content, $notes ) );
        $this->assertStringContainsString( 'settled successfully', $note_content );
    }

    /**
     * @test
     * payment.succeeded on an on-hold order should promote it to processing
     * (safety net for orders that never received payment.authorized/initiated)
     * and still add the settled order note.
     */
    public function test_payment_succeeded_promotes_on_hold_to_processing(): void {
        $order = OrderHelper::create_on_hold_order( 'pay-webhook-settled-onhold' );

        $request  = $this->make_signed_request(
            $this->make_event_payload( 'payment.succeeded', 'pay-webhook-settled-onhold' )
        );
        $response = \WC_Gateway_LinkMoney::handle_webhook( $request );

        $this->assertNotInstanceOf( \WP_Error::class, $response );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );

        $notes        = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
        $note_content = implode( ' ', array_map( fn( $n ) => $n->content, $notes ) );
        $this->assertStringContainsString( 'settled successfully', $note_content );
    }

    /**
     * @test
     * payment.initiated on an on-hold order should promote it to processing,
     * set META_EVER_PROCESSING, and trigger the New Order admin email.
     * Acts as a safety net for orders stuck on-hold when payment.authorized
     * didn't promote them.
     */
    public function test_payment_initiated_promotes_on_hold_to_processing(): void {
        $order = OrderHelper::create_on_hold_order( 'pay-webhook-initiated' );

        $request  = $this->make_signed_request(
            $this->make_event_payload( 'payment.initiated', 'pay-webhook-initiated' )
        );
        $response = \WC_Gateway_LinkMoney::handle_webhook( $request );

        $this->assertNotInstanceOf( \WP_Error::class, $response );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );
        $this->assertTrue( (bool) $order->get_meta( \WC_Gateway_LinkMoney::META_EVER_PROCESSING ) );

        $notes        = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
        $note_content = implode( ' ', array_map( fn( $n ) => $n->content, $notes ) );
        $this->assertStringContainsString( 'payment initiated', $note_content );
    }

    /**
     * @test
     * payment.initiated on an order that is NOT on-hold (e.g., already processing
     * because payment.authorized landed first) should be a no-op.
     */
    public function test_payment_initiated_on_processing_order_is_noop(): void {
        $order          = OrderHelper::create_processing_order( 'pay-webhook-initiated-noop' );
        $initial_status = $order->get_status();

        $request  = $this->make_signed_request(
            $this->make_event_payload( 'payment.initiated', 'pay-webhook-initiated-noop' )
        );
        $response = \WC_Gateway_LinkMoney::handle_webhook( $request );

        $this->assertNotInstanceOf( \WP_Error::class, $response );

        $order = wc_get_order( $order->get_id() );
        $this->assertSame( $initial_status, $order->get_status() );
    }

    /**
     * @test
     * payment.failed event should set order to failed.
     */
    public function test_payment_failed(): void {
        $order = OrderHelper::create_processing_order( 'pay-webhook-failed' );

        $request  = $this->make_signed_request(
            $this->make_event_payload( 'payment.failed', 'pay-webhook-failed' )
        );
        $response = \WC_Gateway_LinkMoney::handle_webhook( $request );

        $this->assertNotInstanceOf( \WP_Error::class, $response );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'failed' ) );
    }

    /**
     * @test
     * A request with a wrong signature should return WP_Error with status 403.
     */
    public function test_invalid_signature(): void {
        $order = OrderHelper::create_processing_order( 'pay-webhook-sig' );

        // Sign with a different secret.
        $request = $this->make_signed_request(
            $this->make_event_payload( 'payment.authorized', 'pay-webhook-sig' ),
            'wrong-secret'
        );

        $response = \WC_Gateway_LinkMoney::handle_webhook( $request );

        $this->assertInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 'invalid_signature', $response->get_error_code() );
    }

    /**
     * @test
     * A webhook referencing a non-existent order should return WP_Error with status 404.
     */
    public function test_missing_order(): void {
        $request  = $this->make_signed_request(
            $this->make_event_payload( 'payment.authorized', 'pay-does-not-exist-xyz' )
        );
        $response = \WC_Gateway_LinkMoney::handle_webhook( $request );

        $this->assertInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 'order_not_found', $response->get_error_code() );
    }

    /**
     * @test
     * When META_PAYMENT_ID isn't yet persisted on the order (webhook arrives
     * before the browser callback), the handler should fall back to looking up
     * the order via clientReferenceId and persist META_PAYMENT_ID for next time.
     */
    public function test_falls_back_to_client_reference_id(): void {
        // Order has no META_PAYMENT_ID set — only the order ID and linkmoney method.
        $order    = OrderHelper::create_checkout_draft_order();
        $order_id = $order->get_id();

        $payload = [
            'eventType'         => 'payment.authorized',
            'clientReferenceId' => (string) $order_id,
            'metadata'          => [
                'resourceId' => 'pay-fallback-001',
            ],
        ];

        $response = \WC_Gateway_LinkMoney::handle_webhook( $this->make_signed_request( $payload ) );

        $this->assertNotInstanceOf( \WP_Error::class, $response );

        $order = wc_get_order( $order_id );
        $this->assertTrue( $order->has_status( 'processing' ) );
        $this->assertSame(
            'pay-fallback-001',
            $order->get_meta( \WC_Gateway_LinkMoney::META_PAYMENT_ID ),
            'Fallback path should persist META_PAYMENT_ID on the order.'
        );
    }

    /**
     * @test
     * Refund (CREDIT) webhooks echo the original DEBIT's clientReferenceId. The
     * fallback must skip CREDIT events so the refund's resourceId never overwrites
     * the original order's META_PAYMENT_ID.
     */
    public function test_credit_transaction_skips_client_reference_id_fallback(): void {
        // Existing settled order with the DEBIT payment ID already persisted.
        $debit_payment_id = 'pay-debit-original-001';
        $order            = OrderHelper::create_processing_order( $debit_payment_id );
        $order_id         = $order->get_id();

        // CREDIT (refund) webhook: resourceId is the refund payment ID, but
        // clientReferenceId echoes the original DEBIT's order ID.
        $payload = [
            'eventType'         => 'payment.succeeded',
            'clientReferenceId' => (string) $order_id,
            'metadata'          => [
                'resourceId'      => 'pay-refund-credit-999',
                'resourceType'    => 'payment',
                'clientReferenceId' => (string) $order_id,
                'transactionType' => 'CREDIT',
            ],
        ];

        $response = \WC_Gateway_LinkMoney::handle_webhook( $this->make_signed_request( $payload ) );

        // No order should be matched — META lookup misses (refund id) and the
        // fallback is suppressed for CREDIT.
        $this->assertInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 'order_not_found', $response->get_error_code() );

        // Critically: the original order's payment ID must remain untouched.
        $order = wc_get_order( $order_id );
        $this->assertSame(
            $debit_payment_id,
            $order->get_meta( \WC_Gateway_LinkMoney::META_PAYMENT_ID ),
            'CREDIT webhook must not rebind the original DEBIT order META_PAYMENT_ID.'
        );
    }

    /**
     * @test
     * An explicit DEBIT transactionType should still take the clientReferenceId
     * fallback path (positive control for the CREDIT skip).
     */
    public function test_debit_transaction_uses_client_reference_id_fallback(): void {
        $order    = OrderHelper::create_checkout_draft_order();
        $order_id = $order->get_id();

        $payload = [
            'eventType'         => 'payment.authorized',
            'clientReferenceId' => (string) $order_id,
            'metadata'          => [
                'resourceId'      => 'pay-debit-fallback-002',
                'transactionType' => 'DEBIT',
            ],
        ];

        $response = \WC_Gateway_LinkMoney::handle_webhook( $this->make_signed_request( $payload ) );

        $this->assertNotInstanceOf( \WP_Error::class, $response );

        $order = wc_get_order( $order_id );
        $this->assertSame(
            'pay-debit-fallback-002',
            $order->get_meta( \WC_Gateway_LinkMoney::META_PAYMENT_ID ),
            'DEBIT fallback should persist META_PAYMENT_ID on the order.'
        );
    }
}
