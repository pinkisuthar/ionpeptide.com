<?php
/**
 * Store credits applied state on the order pay page.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/acfw-store-credits/order-pay-applied.php.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package ACFWP\Templates
 * @version 4.0.7
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div id="acfw-order-pay-store-credits-applied" class="acfw-checkout-ui-block">
    <div class="acfw-accordions">
        <div class="acfw-accordion acfw-store-credits-checkout-ui acfw-store-credits-applied">
            <h3>
                <span class="acfw-accordion-title"><?php esc_html_e( 'Apply store credit discounts?', 'advanced-coupons-for-woocommerce' ); ?></span>
            </h3>
            <div class="acfw-accordion-inner" style="display:block; margin-bottom: 20px;">
                <div class="acfw-accordion-content">
                    <p class="acfw-store-credits-applied-amount">
                        <?php
                        echo wp_kses_post(
                            sprintf(
                                /* Translators: %s: formatted price */
                                __( 'Store credit discount applied: %s', 'advanced-coupons-for-woocommerce' ),
                                '<strong>' . wc_price( $sc_data['amount'], array( 'currency' => $order->get_currency() ) ) . '</strong>'
                            )
                        );
                        ?>
                    </p>
                    <button type="button" class="button acfw-remove-store-credits-order-pay">
                        <?php esc_html_e( 'Remove store credits', 'advanced-coupons-for-woocommerce' ); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
