<?php
/**
 * Store Credit Adjustment - notification email (plain text).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/store-credit-adjustment.php.
 *
 * @version 4.7.1
 */
defined( 'ABSPATH' ) || exit;

$shop_url = get_permalink( wc_get_page_id( 'shop' ) );

// Get entry data.
$amount     = $store_credit_entry->get_prop( 'amount', 'edit' );
$entry_type = $store_credit_entry->get_prop( 'type', 'edit' );
$note       = $store_credit_entry->get_prop( 'note', 'edit' );

// Determine if it's an increase or decrease.
$is_increase    = 'increase' === $entry_type;
$type_label     = $is_increase ? __( 'credited to', 'advanced-coupons-for-woocommerce-free' ) : __( 'deducted from', 'advanced-coupons-for-woocommerce-free' );
$amount_display = wp_strip_all_tags( \ACFWF()->Helper_Functions->api_wc_price( $amount ) );

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo esc_html( wp_strip_all_tags( $email->get_message() ) ) . "\n\n";

printf(
    /* Translators: %1$s: formatted amount, %2$s: credited to/deducted from */
    esc_html__( '%1$s has been %2$s your store credit balance.', 'advanced-coupons-for-woocommerce-free' ),
    esc_html( $amount_display ),
    esc_html( $type_label )
);
echo "\n\n";

if ( ! empty( $note ) ) {
    echo "----------------------------------------\n";
    echo esc_html__( 'Note:', 'advanced-coupons-for-woocommerce-free' ) . ' ' . esc_html( $note );
    echo "\n----------------------------------------\n\n";
}

echo esc_html__( 'Your New Balance:', 'advanced-coupons-for-woocommerce-free' ) . ' ' . esc_html( wp_strip_all_tags( $new_balance ) );
echo "\n\n----------------------------------------\n\n";

echo esc_html( sprintf( '%s: %s', $email->get_button_text(), $shop_url ) );
echo "\n\n----------------------------------------\n\n";

if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
    echo "\n\n----------------------------------------\n\n";
}

if ( ! apply_filters( 'acfw_use_woocommerce_email_footer', false ) ) {
    esc_html_e( 'Powered by', 'advanced-coupons-for-woocommerce-free' );
    echo ' Advanced Coupons ';
    echo esc_url_raw( \ACFWF()->Helper_Functions->get_utm_url( 'powered-by/', 'acfwf', 'storecreditadjustmentemail', 'storecreditadjustmentpoweredby' ) );
} else {
    echo wp_kses_post( apply_filters( 'acfw_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
}
