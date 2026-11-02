<?php


defined( 'ABSPATH' ) || exit();



class Af_Pgbf_Front {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'afpgbf_front_scripts' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'custom_fee_based_on_cart_total' ), 10, 1 );
	}

	public function afpgbf_front_scripts() {
		wp_enqueue_script( 'afpgbf_front_js', AFPGBF_DIR_URL . 'assets/js/front.js', array( 'jquery' ), '1.0.0', true );
	}

	public function af_pgbf_check_date( $rule_id ) {

		$date_match = false;

		$current_date = gmdate('Y-m-d');

		$date_start_init = get_post_meta( $rule_id, 'date_start', true );

		$date_end_init = get_post_meta( $rule_id, 'date_end', true );

		if ( ! empty( $date_start_init ) && strtotime( $date_start_init ) > strtotime( $current_date ) ) {
			
			return $date_match;

		} elseif ( ! empty( $date_end_init ) && strtotime( $date_end_init ) < strtotime( $current_date ) ) {

			return $date_match;

		}

		$date_match = true;

		return $date_match;
	}

	public function custom_fee_based_on_cart_total( $cart ) {

		if ( wc()->cart->is_empty() ) {
			return;
		}

		$posts = get_posts( array(
			'post_type'   => 'afpgbf_rules',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			'orderby'     => 'menu_order', // ← FIXED
			'order'       => 'ASC',        // required for proper ordering
		) );

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$current_user    = is_user_logged_in() ? current( wp_get_current_user()->roles ) : 'guest';
		$current_user_id = get_current_user_id();
		$location        = WC_Geolocation::geolocate_ip();
		$country[]       = $location['country'];
		$cart_total      = $cart->get_subtotal();
		$chosen_gateway  = WC()->session->get( 'chosen_payment_method' );

		$total_amount_discount_and_fee = array();

		$final_array = array();

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

			$rule_matched = false;
			
			$product_id = isset($cart_item['product_id'])?$cart_item['product_id']:'';

			$category_ids = wp_get_post_terms($product_id, 'product_cat', array( 'fields' => 'ids' ));
			$brand_ids    = wp_get_post_terms($product_id, 'product_brand', array( 'fields' => 'ids' ));

			foreach ( $posts as $key => $rule_id ) {
	
				$payment_methods = (array) get_post_meta( $rule_id, 'afpgbf_payment_method', true );

				$payment_methods = array_filter( $payment_methods );

				$tax_class = get_post_meta( $rule_id, 'afpgbf_tax_classes', true );

				$user_roles = (array) get_post_meta( $rule_id, 'afpgbf_user_roles', true );

				$user_roles = array_filter( $user_roles );

				$incl_excl_countries_radio_btn = get_post_meta( $rule_id, 'incl_excl_countries_radio_btn', true );

				$min_cart_amount = get_post_meta( $rule_id, 'min_cart_amount', true );

				$max_cart_amount = get_post_meta( $rule_id, 'max_cart_amount', true );
				

				$countries = (array) get_post_meta( $rule_id, 'include_exclude_countries', true );

				$countries = array_filter( $countries );
				
				$taxable = get_post_meta( $rule_id, 'afpgbf_taxable', true );

				$fee_discount = get_post_meta( $rule_id, 'afpgbf_fee_discount', true );

				$fixed_percentage = get_post_meta( $rule_id, 'afpgbf_fixed_percentage', true );

				$fee_discount_value = get_post_meta( $rule_id, 'afpgbf_fee_discount_value', true );

				$apply_fee_on_pro_sub = get_post_meta( $rule_id, 'apply_fee_on_pro_sub', true );

				$checkout_label = get_post_meta( $rule_id, 'afpgbf_checkout_title', true );
	
				$min_amt_for_percent = get_post_meta( $rule_id, 'afpgbf_min_fee', true );

				$max_amt_for_percent = get_post_meta( $rule_id, 'afpgbf_max_fee', true );
	
				$af_pgbf_ex_customers = get_post_meta( $rule_id, 'af_pgbf_ex_customers', true );
	
				$select_products = get_post_meta($rule_id, 'af_pgbf_select_products', true);
	
				$select_category = get_post_meta($rule_id, 'af_pgbf_select_categories', true);
	
				$select_brands = get_post_meta($rule_id, 'af_pgbf_brands_search', true);

				// !empty($select_products) && 
				// !empty($select_category) &&
				// !empty($select_brands) &&

				$no_product_match = !in_array( $product_id, $select_products);

				$no_category_match = !array_intersect($select_category, $category_ids);

				$no_brand_match = !array_intersect($select_brands, $brand_ids);

				// error_log(print_r($cart_item,true));
				
				// $pro_price                     = $cart_item['data']->get_price();
				
				$pro_price = $cart_item['line_subtotal'];

				if ( empty( $fee_discount_value ) || 0 == $fee_discount_value ) {
					continue;
				}
	
				if ( count( $user_roles ) >= 1 && ! in_array( $current_user, $user_roles ) ) {
					continue;
				}
	
				if (!empty($af_pgbf_ex_customers) && in_array($current_user_id, $af_pgbf_ex_customers)) {
					continue;
				}
	
				if (!$this->af_pgbf_check_date($rule_id)) {
					continue;
				}

				if (!empty($select_brands) || !empty($select_category) || !empty($select_products) ) {

					if ( $no_product_match && $no_category_match && $no_brand_match ) {
						continue;
					}
				}
	
				if ( 'include' == $incl_excl_countries_radio_btn ) {
					if ( count( $countries ) >= 1 && ! array_intersect( $countries, $country ) ) {
						continue;
					}
				}
	
				if ( 'exclude' == $incl_excl_countries_radio_btn ) {
					if ( count( $countries ) >= 1 && array_intersect( $countries, $country ) ) {
						continue;
					}
				}
	
				if ( ! empty( $min_cart_amount ) && ! empty( $max_cart_amount ) ) {
					if ( $cart_total < $min_cart_amount || $cart_total > $max_cart_amount ) {
						continue;
					}
				} elseif ( ! empty( $min_cart_amount ) ) {
					if ( $cart_total < $min_cart_amount ) {
						continue;
					}
				} elseif ( ! empty( $max_cart_amount ) ) {
					if ( $cart_total > $max_cart_amount ) {
						continue;
					}
				}
	
				$gateway1 = count( $payment_methods ) >= 1 ? $payment_methods : array( $chosen_gateway );
				if ( !in_array( $chosen_gateway, $gateway1 ) ) {
					continue;
	
				}

				// rule matched 
				$rule_matched    = true;
				$matched_rule_id = $rule_id;
				break;

			}

			if ( $rule_matched ) {

				if ( ! isset( $total_amount_discount_and_fee[ $matched_rule_id ] ) ) {
					$total_amount_discount_and_fee[ $matched_rule_id ] = 0;
				}

				$apply_fee_on_pro_sub = get_post_meta( $matched_rule_id, 'apply_fee_on_pro_sub', true );

				if ('apply_on_subtotal' == $apply_fee_on_pro_sub) {
					$pro_price = $cart_total;
				}

				$calculated_fee = $this->afpgbf_calculations(
					$fixed_percentage,
					$fee_discount_value,
					$min_amt_for_percent,
					$max_amt_for_percent,
					$fee_discount,
					$taxable,
					$checkout_label,
					$pro_price,
					$tax_class
				);

				$final_array[ $product_id ] = array(
					'calculated_fee' => $calculated_fee,
					'rule_id'        => $matched_rule_id,
				);
			}
		}

		$result        = array();
		$rules_checked = array();

		foreach ($final_array as $product_id => $info) {

			$rule_id = $info['rule_id'];

			$apply_on = get_post_meta($rule_id, 'apply_fee_on_pro_sub', true);

			if ('apply_on_subtotal' == $apply_on) {
				if (!in_array($rule_id, $rules_checked)) {
					$result[ $product_id ] = $info; 
					$rules_checked[]       = $rule_id;
				}
			} else {
				$result[ $product_id ] = $info;
			}
		}

		$final = array();

		foreach ($result as $product_id => $info) {
			$rule_id = $info['rule_id'];
			$fee     = (float) $info['calculated_fee'];

			if (!isset($final[ $rule_id ])) {
				$final[ $rule_id ] = 0;
			}

			$final[ $rule_id ] += $fee;
		}

		foreach ( $final as $rule_id => $fee ) {

			$title = get_post_meta( $rule_id, 'afpgbf_checkout_title', true );

			if ( empty( $title ) ) {
				$title = 'Extra Fee';
			}

			$tax_class  = get_post_meta( $rule_id, 'afpgbf_tax_classes', true );
			$taxable    = get_post_meta( $rule_id, 'afpgbf_taxable', true );
			$is_taxable = 'true' == $taxable ? true : false;

			WC()->cart->add_fee( $title, $fee, $is_taxable, $tax_class );
				
		}
	}

	public function afpgbf_calculations( $fixed_percentage, $fee_discount_value, $min_amt_for_percent, $max_amt_for_percent, $fee_discount, $taxable, $checkout_label, $pro_price, $tax_class ) {

		if ( 'percentage' == $fixed_percentage ) {

			$percent_value = ( $fee_discount_value / 100 ) * $pro_price;

			if ( $percent_value <= $min_amt_for_percent ) {
				$amount = $min_amt_for_percent;

			} elseif ( $percent_value >= $max_amt_for_percent ) {
				$amount = $max_amt_for_percent;
				
			} else {
				$amount = $percent_value;
			}
		}

		$amount      = 'fixed' == $fixed_percentage ? $fee_discount_value : $amount;
		$total_amont = 'fee' == $fee_discount ? $amount : $amount * -1;

		return $total_amont;

		// $is_taxable = 'true' == $taxable ? true : false;

		// wc()->cart->add_fee( $checkout_label, $total_amont, $is_taxable, $tax_class );
	}
}
	
new Af_Pgbf_Front();
