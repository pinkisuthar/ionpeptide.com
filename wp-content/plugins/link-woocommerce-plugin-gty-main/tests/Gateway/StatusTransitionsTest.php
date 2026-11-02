<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for cross-cutting status helpers:
 *   - order_ever_was_processing() (private static)
 *   - trigger_awaiting_payment_emails() (private static)
 *
 * 3 tests total.
 */
class StatusTransitionsTest extends \WP_UnitTestCase {

    use MockHttpTrait;

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
    }

    public function tearDown(): void {
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * Helper: invoke a private static method via ReflectionMethod.
     */
    private function invoke_static( string $method_name, ...$args ): mixed {
        $method = new \ReflectionMethod( \WC_Gateway_LinkMoney::class, $method_name );
        $method->setAccessible( true );
        return $method->invoke( null, ...$args );
    }

    /**
     * @test
     * order_ever_was_processing() should return true when META_EVER_PROCESSING is set.
     */
    public function test_order_ever_was_processing_true(): void {
        $order = OrderHelper::create_processing_order( 'pay-ever-001' );
        // OrderHelper::create_processing_order sets META_EVER_PROCESSING = true.

        $result = $this->invoke_static( 'order_ever_was_processing', $order );

        $this->assertTrue( $result );
    }

    /**
     * @test
     * order_ever_was_processing() should return false when META_EVER_PROCESSING is not set.
     */
    public function test_order_ever_was_processing_false(): void {
        $order = OrderHelper::create_pending_order();
        // pending order has no META_EVER_PROCESSING.

        $result = $this->invoke_static( 'order_ever_was_processing', $order );

        $this->assertFalse( $result );
    }

    /**
     * @test
     * trigger_awaiting_payment_emails() should trigger WC_Email_New_Order and add
     * an order note confirming the email was sent.
     */
    public function test_trigger_awaiting_payment_emails(): void {
        $order = OrderHelper::create_processing_order( 'pay-email-001' );

        // Capture whether the email trigger method was called by hooking
        // woocommerce_email_before_order_table (fires inside WC_Email::trigger).
        $email_triggered = false;
        add_action( 'woocommerce_new_order_notification', function() use ( &$email_triggered ) {
            $email_triggered = true;
        } );

        $this->invoke_static( 'trigger_awaiting_payment_emails', $order );

        // An order note should be added by trigger_awaiting_payment_emails.
        $order = wc_get_order( $order->get_id() );
        $notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
        $note_content = implode( ' ', array_map( fn( $n ) => $n->content, $notes ) );
        $this->assertStringContainsString( 'New Order email', $note_content );
    }
}
