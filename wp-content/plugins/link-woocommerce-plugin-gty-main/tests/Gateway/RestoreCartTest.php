<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::maybe_restore_cart_from_order(),
 * restore_cart_from_order() (private, via reflection), and the deferred
 * abandon-check handler.
 */
class RestoreCartTest extends \WP_UnitTestCase {

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

        // Initialize WooCommerce session and cart for cart tests.
        if ( ! WC()->session ) {
            WC()->initialize_session();
        }
        if ( ! WC()->cart ) {
            WC()->initialize_cart();
        }
        WC()->cart->empty_cart();

        $_GET = [];
    }

    public function tearDown(): void {
        $_GET = [];
        if ( WC()->cart ) {
            WC()->cart->empty_cart();
        }
        if ( WC()->session ) {
            WC()->session->__unset( \WC_Gateway_LinkMoney::SESSION_PENDING_COUPON_CODES );
        }
        // Drop any abandon-check actions scheduled by the test.
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'linkmoney_delayed_abandon_check' );
        }
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * @test
     * Valid lm_restore_order + lm_restore_key should restore the cart immediately
     * and schedule the abandon-check action; the order is NOT cancelled inline so
     * a late-arriving webhook still has a chance to mark it paid.
     */
    public function test_restores_cart_and_defers_cancellation(): void {
        $order    = OrderHelper::create_checkout_draft_order();
        $order_id = $order->get_id();

        $_GET = [
            'lm_restore_order' => $order_id,
            'lm_restore_key'   => $order->get_order_key(),
        ];

        $this->gateway->maybe_restore_cart_from_order();

        // Order should still exist and NOT yet be cancelled.
        $still_draft = wc_get_order( $order_id );
        $this->assertInstanceOf( \WC_Order::class, $still_draft );
        $this->assertNotEquals( 'cancelled', $still_draft->get_status() );

        // Cart should now have the item that was in the order (qty 1 of product).
        $this->assertFalse( WC()->cart->is_empty() );

        // The abandon-check action should be scheduled for this order.
        $this->assertTrue(
            as_has_scheduled_action( 'linkmoney_delayed_abandon_check', [ 'order_id' => $order_id ] ),
            'Expected linkmoney_delayed_abandon_check to be scheduled.'
        );
    }

    /**
     * @test
     * The deferred handler should cancel the order and add the abandonment note
     * when the order is still in checkout-draft (genuine abandonment).
     */
    public function test_delayed_handler_cancels_still_draft_order(): void {
        $order    = OrderHelper::create_checkout_draft_order();
        $order_id = $order->get_id();

        \WC_Gateway_LinkMoney::handle_delayed_abandon_check( $order_id );

        $cancelled = wc_get_order( $order_id );
        $this->assertEquals( 'cancelled', $cancelled->get_status() );

        $notes = wc_get_order_notes( [ 'order_id' => $order_id ] );
        $found = false;
        foreach ( $notes as $note ) {
            if ( str_contains( $note->content, 'Customer abandoned the Link Money bank linking flow.' ) ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue( $found, 'Expected abandonment order note not found.' );
    }

    /**
     * @test
     * The deferred handler should leave the order alone if a webhook (or any
     * other path) has already moved it to processing/paid in the meantime.
     */
    public function test_delayed_handler_skips_already_paid_order(): void {
        $order    = OrderHelper::create_processing_order( 'pay-delayed-skip' );
        $order_id = $order->get_id();

        \WC_Gateway_LinkMoney::handle_delayed_abandon_check( $order_id );

        $still_processing = wc_get_order( $order_id );
        $this->assertEquals( 'processing', $still_processing->get_status() );
    }

    /**
     * @test
     * On restore, the session key store_api_draft_order should be set to 0.
     */
    public function test_clears_store_api_draft_session(): void {
        $order = OrderHelper::create_checkout_draft_order();

        // Simulate a stored Blocks draft order session value.
        WC()->session->set( 'store_api_draft_order', $order->get_id() );

        $_GET = [
            'lm_restore_order' => $order->get_id(),
            'lm_restore_key'   => $order->get_order_key(),
        ];

        $this->gateway->maybe_restore_cart_from_order();

        $this->assertSame( 0, (int) WC()->session->get( 'store_api_draft_order' ) );
    }

    /**
     * @test
     * A wrong order key should result in a no-op (order not deleted).
     */
    public function test_invalid_order_key_no_op(): void {
        $order    = OrderHelper::create_checkout_draft_order();
        $order_id = $order->get_id();

        $_GET = [
            'lm_restore_order' => $order_id,
            'lm_restore_key'   => 'wrong-key-value',
        ];

        $this->gateway->maybe_restore_cart_from_order();

        // Order should still exist.
        $this->assertInstanceOf( \WC_Order::class, wc_get_order( $order_id ) );
    }

    /**
     * @test
     * Missing GET params should result in a no-op.
     */
    public function test_missing_params_no_op(): void {
        $order    = OrderHelper::create_checkout_draft_order();
        $order_id = $order->get_id();

        // No $_GET params set.
        $this->gateway->maybe_restore_cart_from_order();

        // Order should still exist.
        $this->assertInstanceOf( \WC_Order::class, wc_get_order( $order_id ) );
    }

    /**
     * @test
     * restore_cart_from_order() should skip restoration if the order is processing
     * (to avoid restoring items for already-paid orders).
     */
    public function test_restore_skips_processing_order(): void {
        $order = OrderHelper::create_processing_order( 'pay-proc-001' );

        // Call private method via reflection.
        $method = new \ReflectionMethod( \WC_Gateway_LinkMoney::class, 'restore_cart_from_order' );
        $method->setAccessible( true );
        $method->invoke( $this->gateway, $order );

        // Cart should remain empty.
        $this->assertTrue( WC()->cart->is_empty() );
    }

    /**
     * @test
     * maybe_restore_cart_from_order() should re-apply coupon codes from the order.
     */
    public function test_restores_coupon_codes_on_cancel(): void {
        $coupon_code = 'lm_abandon_' . wp_generate_password( 6, false );
        $order       = OrderHelper::create_checkout_draft_order_with_coupon( $coupon_code );
        $formatted   = wc_format_coupon_code( $coupon_code );

        WC()->session->set( 'chosen_payment_method', 'linkmoney' );

        $_GET = [
            'lm_restore_order' => $order->get_id(),
            'lm_restore_key'   => $order->get_order_key(),
        ];

        $this->assertContains( $formatted, $order->get_coupon_codes(), 'Order should have coupon before restore.' );

        $this->gateway->maybe_restore_cart_from_order();

        $applied = WC()->cart->get_applied_coupons();
        $this->assertContains( $formatted, $applied, 'Expected coupon to be restored to the cart.' );
        $this->assertGreaterThan( 0, WC()->cart->get_discount_total() );
    }

    /**
     * @test
     * Stashed coupons should not stay applied after the customer switches payment method.
     */
    public function test_sync_removes_coupons_when_payment_method_changes(): void {
        $coupon_code = 'lm_switch_' . wp_generate_password( 6, false );
        $formatted   = wc_format_coupon_code( $coupon_code );
        $order       = OrderHelper::create_checkout_draft_order_with_coupon( $coupon_code );

        WC()->session->set( 'chosen_payment_method', 'linkmoney' );

        $_GET = [
            'lm_restore_order' => $order->get_id(),
            'lm_restore_key'   => $order->get_order_key(),
        ];

        $this->gateway->maybe_restore_cart_from_order();
        $this->assertContains( $formatted, WC()->cart->get_applied_coupons() );

        WC()->session->set( 'chosen_payment_method', 'stripe' );
        $this->gateway->sync_restored_coupons_with_payment_method();

        $this->assertNotContains( $formatted, WC()->cart->get_applied_coupons() );
    }

    /**
     * @test
     * restore_cart_from_order() should re-apply coupon codes from the order.
     */
    public function test_restore_cart_from_order_restores_coupons(): void {
        $coupon_code = 'lm_decline_' . wp_generate_password( 6, false );
        $formatted   = wc_format_coupon_code( $coupon_code );
        $order       = OrderHelper::create_failed_order();
        $coupon      = OrderHelper::create_percent_coupon( $coupon_code );
        OrderHelper::attach_coupon_to_order( $order, $coupon );
        $order->calculate_totals();
        $order->save();

        WC()->session->set( 'chosen_payment_method', 'linkmoney' );

        $this->assertContains( $formatted, $order->get_coupon_codes() );

        $method = new \ReflectionMethod( \WC_Gateway_LinkMoney::class, 'restore_cart_from_order' );
        $method->setAccessible( true );
        $method->invoke( $this->gateway, $order );

        $this->assertContains( $formatted, WC()->cart->get_applied_coupons() );
        $this->assertGreaterThan( 0, WC()->cart->get_discount_total() );
    }

    /**
     * @test
     * Coupons from a non-Link-Money order should not be stashed for restore.
     */
    public function test_restore_skips_coupons_for_non_linkmoney_order(): void {
        $coupon_code = 'lm_stripe_' . wp_generate_password( 6, false );
        $order       = OrderHelper::create_checkout_draft_order_with_coupon( $coupon_code );
        $order->set_payment_method( 'stripe' );
        $order->save();

        WC()->session->set( 'chosen_payment_method', 'linkmoney' );

        $method = new \ReflectionMethod( \WC_Gateway_LinkMoney::class, 'restore_cart_from_order' );
        $method->setAccessible( true );
        $method->invoke( $this->gateway, $order );

        $this->assertEmpty( WC()->cart->get_applied_coupons() );
        $this->assertNull( WC()->session->get( \WC_Gateway_LinkMoney::SESSION_PENDING_COUPON_CODES ) );
    }

    /**
     * @test
     * restore_cart_from_order() should skip restoration if the cart already has items
     * (to avoid duplicate additions).
     */
    public function test_restore_skips_nonempty_cart(): void {
        $order   = OrderHelper::create_failed_order();
        $product = OrderHelper::create_simple_product( 5.00 );

        // Pre-populate the cart with a different product.
        WC()->cart->add_to_cart( $product->get_id(), 1 );
        $initial_cart_count = count( WC()->cart->get_cart() );

        $method = new \ReflectionMethod( \WC_Gateway_LinkMoney::class, 'restore_cart_from_order' );
        $method->setAccessible( true );
        $method->invoke( $this->gateway, $order );

        // Cart item count should not have changed.
        $this->assertSame( $initial_cart_count, count( WC()->cart->get_cart() ) );
    }

    /**
     * @test
     * clear_pending_coupon_restore() should drop the stash so a later checkout
     * does not auto-apply a previous order's coupons.
     */
    public function test_clear_pending_coupon_restore_removes_stash(): void {
        WC()->session->set( \WC_Gateway_LinkMoney::SESSION_PENDING_COUPON_CODES, [ 'leftover10' ] );

        $this->gateway->clear_pending_coupon_restore();

        $this->assertNull( WC()->session->get( \WC_Gateway_LinkMoney::SESSION_PENDING_COUPON_CODES ) );

        // A subsequent sync with Link Money selected must not re-apply anything.
        WC()->session->set( 'chosen_payment_method', 'linkmoney' );
        $this->gateway->sync_restored_coupons_with_payment_method();
        $this->assertEmpty( WC()->cart->get_applied_coupons() );
    }

    /**
     * @test
     * A stashed coupon that fails to apply (invalid/expired) should be pruned from
     * the session so it isn't retried — and re-notified — on every order-review AJAX.
     */
    public function test_sync_prunes_coupon_that_fails_to_apply(): void {
        $product = OrderHelper::create_simple_product( 20.00 );
        WC()->cart->add_to_cart( $product->get_id(), 1 );

        WC()->session->set( 'chosen_payment_method', 'linkmoney' );
        WC()->session->set(
            \WC_Gateway_LinkMoney::SESSION_PENDING_COUPON_CODES,
            [ 'does_not_exist_' . wp_generate_password( 6, false ) ]
        );

        $this->gateway->sync_restored_coupons_with_payment_method();

        // Nothing applied, and the bogus code is dropped so it won't be retried.
        $this->assertEmpty( WC()->cart->get_applied_coupons() );
        $this->assertNull( WC()->session->get( \WC_Gateway_LinkMoney::SESSION_PENDING_COUPON_CODES ) );
    }

    /**
     * @test
     * On the declined-payment path the cart is not emptied first, so
     * restore_cart_from_order() must still re-apply the order's coupon even when
     * the cart already has items.
     */
    public function test_restore_reapplies_coupon_when_cart_not_empty(): void {
        $coupon_code = 'lm_decline_nonempty_' . wp_generate_password( 6, false );
        $formatted   = wc_format_coupon_code( $coupon_code );
        $order       = OrderHelper::create_checkout_draft_order_with_coupon( $coupon_code );

        // Cart already populated (mirrors a decline where empty_cart() never ran).
        $product = OrderHelper::create_simple_product( 20.00 );
        WC()->cart->add_to_cart( $product->get_id(), 1 );
        $cart_count = count( WC()->cart->get_cart() );

        WC()->session->set( 'chosen_payment_method', 'linkmoney' );

        $method = new \ReflectionMethod( \WC_Gateway_LinkMoney::class, 'restore_cart_from_order' );
        $method->setAccessible( true );
        $method->invoke( $this->gateway, $order );

        // Items are left untouched (not rebuilt), but the coupon is re-applied.
        $this->assertSame( $cart_count, count( WC()->cart->get_cart() ) );
        $this->assertContains( $formatted, WC()->cart->get_applied_coupons() );
    }

    /**
     * @test
     * Browser Back from the Link Money hosted page returns to the bare checkout
     * URL with no lm_restore_order param. When a pending draft order is stashed
     * in the session and the cart was emptied (Blocks/Store API), the recovery
     * must rebuild items and coupons from that order.
     */
    public function test_recovers_cart_from_session_after_browser_back(): void {
        $coupon_code = 'lm_back_' . wp_generate_password( 6, false );
        $formatted   = wc_format_coupon_code( $coupon_code );
        $order       = OrderHelper::create_checkout_draft_order_with_coupon( $coupon_code );

        // Simulate the redirect-to-Link-Money state: order stashed for restore,
        // Link Money selected, and the cart emptied by Store API order placement.
        WC()->session->set( \WC_Gateway_LinkMoney::SESSION_PENDING_RESTORE_ORDER, $order->get_id() );
        WC()->session->set( 'chosen_payment_method', 'linkmoney' );
        WC()->cart->empty_cart();

        // No lm_restore_order param — this is a plain Back navigation.
        $_GET = [];

        $this->gateway->maybe_recover_pending_checkout();

        $this->assertFalse( WC()->cart->is_empty(), 'Items should be restored to the cart.' );
        $this->assertContains( $formatted, WC()->cart->get_applied_coupons(), 'Coupon should be restored.' );
    }

    /**
     * @test
     * Recovery must never touch a cart for an already-paid order, and should drop
     * the stale session pointer.
     */
    public function test_recovery_skips_paid_order_and_clears_pointer(): void {
        $order = OrderHelper::create_processing_order( 'pay-recover-paid' );

        WC()->session->set( \WC_Gateway_LinkMoney::SESSION_PENDING_RESTORE_ORDER, $order->get_id() );
        WC()->cart->empty_cart();
        $_GET = [];

        $this->gateway->maybe_recover_pending_checkout();

        $this->assertTrue( WC()->cart->is_empty(), 'Paid order must not repopulate the cart.' );
        $this->assertNull( WC()->session->get( \WC_Gateway_LinkMoney::SESSION_PENDING_RESTORE_ORDER ) );
    }
}
