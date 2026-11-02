<?php

namespace LinkMoney\Tests\Helpers;

/**
 * Factory for creating WooCommerce test orders configured for Link Money.
 *
 * All created orders use payment_method=linkmoney and have standard billing
 * details populated. Use the return value directly in tests.
 */
class OrderHelper {

    /**
     * Create a pending WC_Order with Link Money as the payment method.
     *
     * @param array $overrides Key-value pairs to override default order data.
     * @return \WC_Order
     */
    public static function create_pending_order( array $overrides = [] ): \WC_Order {
        return self::create_order( array_merge( [ 'status' => 'pending' ], $overrides ) );
    }

    /**
     * Create a checkout-draft WC_Order with Link Money as the payment method.
     *
     * @param array $overrides Key-value pairs to override default order data.
     * @return \WC_Order
     */
    public static function create_checkout_draft_order( array $overrides = [] ): \WC_Order {
        return self::create_order( array_merge( [ 'status' => 'checkout-draft' ], $overrides ) );
    }

    /**
     * Create a processing WC_Order with a Link Money payment ID set in meta.
     *
     * @param string $payment_id The value for META_PAYMENT_ID.
     * @return \WC_Order
     */
    public static function create_processing_order( string $payment_id ): \WC_Order {
        $order = self::create_order( [ 'status' => 'processing' ] );
        $order->update_meta_data( \WC_Gateway_LinkMoney::META_PAYMENT_ID, $payment_id );
        $order->update_meta_data( \WC_Gateway_LinkMoney::META_EVER_PROCESSING, true );
        $order->save();
        return $order;
    }

    /**
     * Create an on-hold WC_Order with a Link Money payment ID set in meta.
     *
     * @param string $payment_id The value for META_PAYMENT_ID.
     * @return \WC_Order
     */
    public static function create_on_hold_order( string $payment_id ): \WC_Order {
        $order = self::create_order( [ 'status' => 'on-hold' ] );
        $order->update_meta_data( \WC_Gateway_LinkMoney::META_PAYMENT_ID, $payment_id );
        $order->save();
        return $order;
    }

    /**
     * Create a failed WC_Order, optionally with META_EVER_PROCESSING set.
     *
     * @param bool $ever_processing Whether to set META_EVER_PROCESSING on the order.
     * @return \WC_Order
     */
    public static function create_failed_order( bool $ever_processing = false ): \WC_Order {
        $order = self::create_order( [ 'status' => 'failed' ] );
        if ( $ever_processing ) {
            $order->update_meta_data( \WC_Gateway_LinkMoney::META_EVER_PROCESSING, true );
            $order->save();
        }
        return $order;
    }

    /**
     * Create a percent-off WC_Coupon for use in order/cart tests.
     *
     * @param string $code   Coupon code.
     * @param float  $amount Discount amount (percent when type is percent).
     * @return \WC_Coupon
     */
    public static function create_percent_coupon( string $code = 'TEST10', float $amount = 10.0 ): \WC_Coupon {
        $existing_id = wc_get_coupon_id_by_code( $code );
        if ( $existing_id ) {
            return new \WC_Coupon( $existing_id );
        }

        $coupon = new \WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_discount_type( 'percent' );
        $coupon->set_amount( (string) $amount );
        $coupon->save();
        return $coupon;
    }

    /**
     * Attach a coupon to an order for tests, using WooCommerce APIs when possible.
     *
     * @param \WC_Order   $order  Order to update.
     * @param \WC_Coupon  $coupon Coupon to attach.
     */
    public static function attach_coupon_to_order( \WC_Order $order, \WC_Coupon $coupon ): void {
        $result = $order->apply_coupon( $coupon );
        if ( ! is_wp_error( $result ) ) {
            return;
        }

        // Fallback: add a coupon line item so get_coupon_codes() reflects the discount.
        $discount = round( (float) $order->get_subtotal() * ( (float) $coupon->get_amount() / 100 ), 2 );
        $item     = new \WC_Order_Item_Coupon();
        $item->set_code( $coupon->get_code() );
        $item->set_discount( $discount > 0 ? $discount : 1 );
        $item->set_discount_tax( 0 );
        $order->add_item( $item );
        $order->save();
    }

    /**
     * Create a checkout-draft order with a coupon applied.
     *
     * @param string $coupon_code Coupon code to apply to the order.
     * @return \WC_Order
     */
    public static function create_checkout_draft_order_with_coupon( string $coupon_code = 'TEST10' ): \WC_Order {
        $coupon = self::create_percent_coupon( $coupon_code );
        $order  = self::create_checkout_draft_order();
        self::attach_coupon_to_order( $order, $coupon );
        $order->calculate_totals();
        $order->save();
        return $order;
    }

    /**
     * Create a simple WC_Product for use as an order line item.
     *
     * @param float $price Product price.
     * @return \WC_Product_Simple
     */
    public static function create_simple_product( float $price = 20.00 ): \WC_Product_Simple {
        $product = new \WC_Product_Simple();
        $product->set_name( 'Test Product' );
        $product->set_regular_price( (string) $price );
        $product->set_price( (string) $price );
        $product->set_status( 'publish' );
        $product->set_manage_stock( false );
        $product->save();
        return $product;
    }

    /**
     * Internal factory used by all public create_* methods.
     *
     * @param array $args Overrides merged with defaults.
     * @return \WC_Order
     */
    private static function create_order( array $args = [] ): \WC_Order {
        $product = self::create_simple_product( 20.00 );

        $order = wc_create_order( [
            'status' => $args['status'] ?? 'pending',
        ] );

        // Set payment method explicitly (wc_create_order doesn't accept it).
        $order->set_payment_method( 'linkmoney' );
        $order->set_payment_method_title( 'Same-Day Bank Payment' );

        // Add a line item.
        $order->add_product( $product, 1 );

        // Set billing details.
        $order->set_billing_first_name( 'Jane' );
        $order->set_billing_last_name( 'Doe' );
        $order->set_billing_email( 'jane@example.com' );
        $order->set_billing_phone( '555-1234' );
        $order->set_billing_address_1( '123 Main St' );
        $order->set_billing_city( 'Anytown' );
        $order->set_billing_state( 'CA' );
        $order->set_billing_postcode( '90210' );
        $order->set_billing_country( 'US' );

        // Calculate and save totals.
        $order->calculate_totals();
        $order->save();

        return $order;
    }
}
