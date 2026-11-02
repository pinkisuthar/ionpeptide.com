<?php
/**
 * Contact functions
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Providing a contact object
 *
 * @param $email
 * @param $wp_id
 *
 * @return WooFunnels_Contact|WooFunnels_Customer
 */
if ( ! function_exists( 'bwf_get_contact' ) ) {
	function bwf_get_contact(  $wp_id, $email  ) {
		return BWF_Contact_Fns::bwf_get_contact( ...func_get_args() );
	}
}

if ( ! function_exists( 'bwf_create_update_contact' ) ) {
	/**
	 * Creating updating contact and customer table
	 * On offer accepted, on order status change and on order indexing
	 *
	 * @param $order_id
	 * @param $products
	 * @param $total
	 * @param false $force
	 *
	 * @return int|void
	 */
	function bwf_create_update_contact(  $order_id, $products, $total, $force = false  ) {
		return BWF_Contact_Fns::bwf_create_update_contact( ...func_get_args() );
	}
}


/**
 * @param WooFunnels_Contact $bwf_contact
 * @param WC_order $order
 */
if ( ! function_exists( 'bwf_contact_maybe_update_creation_date' ) ) {
	function bwf_contact_maybe_update_creation_date(  $bwf_contact, $order  ) {
		return BWF_Contact_Fns::bwf_contact_maybe_update_creation_date( ...func_get_args() );
	}
}


if ( ! function_exists( 'bwf_create_update_contact_object' ) ) {

	/**
	 * Called on login, & checkout order processed
	 *
	 * @param $bwf_contact WooFunnels_Contact
	 * @param $order WC_Order
	 *
	 * @return mixed
	 */
	function bwf_create_update_contact_object(  $bwf_contact, $order  ) {
		return BWF_Contact_Fns::bwf_create_update_contact_object( ...func_get_args() );
	}
}

if ( ! function_exists( 'bwf_get_timezone_from_order' ) ) {

	/**
	 * @param $order WC_Order
	 *
	 * @return array|mixed|string
	 */
	function bwf_get_timezone_from_order(  $order  ) {
		return BWF_Contact_Fns::bwf_get_timezone_from_order( ...func_get_args() );
	}
}

if ( ! function_exists( 'bwf_if_valid_timezone' ) ) {

	/**
	 * @param $timezone
	 *
	 * @return bool
	 */
	function bwf_if_valid_timezone(  $timezone  ) {
		return BWF_Contact_Fns::bwf_if_valid_timezone( ...func_get_args() );
	}
}

if ( ! function_exists( 'bwf_get_countries_data' ) ) {
	/** countries data
	 * @return mixed|null
	 */
	function bwf_get_countries_data() {
		return BWF_Contact_Fns::bwf_get_countries_data( ...func_get_args() );
	}
}

if ( ! function_exists( 'bwf_get_country_timezone_list' ) ) {
	/**
	 * Country => timezone map (memoized — the JSON is ~28K, avoid re-reading per call)
	 *
	 * @return array
	 */
	function bwf_get_country_timezone_list() {
		return BWF_Contact_Fns::bwf_get_country_timezone_list( ...func_get_args() );
	}
}

/**
 * ===========================================================================
 * Customer functions (merged from woofunnels-customer-functions.php to save a
 * frontend file load — every wrapper just delegates to BWF_Customer_Fns).
 * ===========================================================================
 */

/**
 * Providing a new customer object
 *
 * @param $contact
 *
 * @return WooFunnels_Customer
 */
if ( ! function_exists( 'bwf_get_customer' ) ) {
	function bwf_get_customer( $contact ) {
		return new WooFunnels_Customer( $contact );
	}
}

/**
 * @param $bwf_contact
 * @param $order WC_Order
 * @param $order_id
 * @param $products - only in case of upstroke upsell orders
 * @param $total
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
if ( ! function_exists( 'bwf_create_update_customer' ) ) {
	function bwf_create_update_customer(  $bwf_contact, $order, $order_id, $products, $total  ) {
		return BWF_Customer_Fns::bwf_create_update_customer( ...func_get_args() );
	}
}

/**
 * Setting category ids and tag ids
 *
 * @param $product_ids
 * @param $product_id
 */
if ( ! function_exists( 'bwf_update_cats_and_tags' ) ) {
	function bwf_update_cats_and_tags(  $product_id, $cat_ids, $tag_ids  ) {
		return BWF_Customer_Fns::bwf_update_cats_and_tags( ...func_get_args() );
	}
}

/**
 * Updating refunded amount in customer meta
 *
 * @param $order_id
 * @param $amount
 */
if ( ! function_exists( 'bwf_update_customer_refunded' ) ) {
	function bwf_update_customer_refunded(  $order_id, $refund_amount  ) {
		return BWF_Customer_Fns::bwf_update_customer_refunded( ...func_get_args() );
	}
}
/**
 * Reducing customer total spent on order cancelled
 *
 * @param $order_id
 */
if ( ! function_exists( 'bwf_reduce_customer_total_on_cancel' ) ) {
	function bwf_reduce_customer_total_on_cancel(  $order_id  ) {
		return BWF_Customer_Fns::bwf_reduce_customer_total_on_cancel( ...func_get_args() );
	}
}

/**
 * Return the total order amount
 *
 * @param $order WC_Order
 *
 * @return int
 */
if ( ! function_exists( 'bwf_get_order_total' ) ) {
	function bwf_get_order_total(  $order  ) {
		return BWF_Customer_Fns::bwf_get_order_total( ...func_get_args() );
	}
}
/**
 * Return the Order product items ids
 *
 * @param $order WC_Order
 *
 * @return array
 */
if ( ! function_exists( 'bwf_get_order_product_ids' ) ) {
	function bwf_get_order_product_ids(  $order  ) {
		return BWF_Customer_Fns::bwf_get_order_product_ids( ...func_get_args() );
	}
}
/**
 * Return the Order product items categories and terms ids
 *
 * @param $order WC_Order
 *
 * @return array
 */
if ( ! function_exists( 'bwf_get_order_product_terms' ) ) {
	function bwf_get_order_product_terms(  $order  ) {
		return BWF_Customer_Fns::bwf_get_order_product_terms( ...func_get_args() );
	}
}
/**
 * Combine 2 arrays and return unique integer array values
 *
 * @param $arr
 *
 * @return mixed
 */
if ( ! function_exists( 'bwf_get_array_unique_integers' ) ) {
	function bwf_get_array_unique_integers(  $array1 = [], $array2 = []  ) {
		return BWF_Customer_Fns::bwf_get_array_unique_integers( ...func_get_args() );
	}
}

