<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** checking if woocommerce exists otherwise return */
if ( ! function_exists( 'bwfan_is_woocommerce_active' ) || ! bwfan_is_woocommerce_active() ) {
	return;
}

$exclude_variable_attribute = apply_filters( 'bwfan_exclude_wc_variable_attribute', false );
$tax_display = get_option( 'woocommerce_tax_display_cart' );
$suffix = BWFAN_Common::get_wc_tax_label_if_displayed();
$product_names_with_price = [];
$currency = is_array( $data ) && isset( $data['currency'] ) ? $data['currency'] : '';

if ( false !== $cart ) {
	foreach ( $cart as $item ) {
		$product = isset( $item['data'] ) ? $item['data'] : '';
		if ( empty( $product ) || ! $product instanceof WC_Product ) {
			continue; 
		}
		$price = $line_total = ( $tax_display === 'excl' ) ? BWFAN_Common::get_line_subtotal( $item ) : BWFAN_Common::get_line_subtotal( $item ) + BWFAN_Common::get_line_subtotal_tax( $item );
		$line_total = is_null( $price ) ? BWFAN_Common::get_prices_with_tax( $product ) : $price;

		$name = $product->get_name();
		if ( $product instanceof WC_Product_Variation && false === $exclude_variable_attribute ) {
			$name .= ' - ' . $product->get_attribute_summary();
		}
		$formatted_price = BWFAN_Common::price( $line_total, $currency );
		$price_display = $formatted_price;
		if ( $suffix && wc_tax_enabled() ) {
			$price_display .= ' ' . $suffix;
		}

		$product_names_with_price[] = $name . " ( " . $price_display . " )";
	}
} else {
	foreach ( $products as $product ) {
		if ( ! $product instanceof WC_Product ) {
			continue;
		}
		$price = isset( $products_price[ $product->get_id() ] ) ? $products_price[ $product->get_id() ] : null;
		$line_total = is_null( $price ) ? BWFAN_Common::get_prices_with_tax( $product ) : $price;
		$name  = $product->get_name();

		if ( $product instanceof WC_Product_Variation && false === $exclude_variable_attribute ) {
			$name .= ' - ' . $product->get_attribute_summary();
		}

		/** Format the price properly */
		$formatted_price = BWFAN_Common::price( $line_total, $currency );

		/** Add suffix if enabled*/
		$price_display = $formatted_price;
		if ( $suffix && wc_tax_enabled() ) {
			$price_display .= ' ' . $suffix;
		}

		$product_names_with_price[] = $name . " ( " . $price_display . " )";
	}
}

$explode_operator = apply_filters( 'bwfan_product_name_separator', ', ' );
echo implode( $explode_operator, $product_names_with_price ); //phpcs:ignore WordPress.Security.EscapeOutput