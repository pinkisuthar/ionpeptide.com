<?php
/**
 * Cross-Product Exchange — Product Search
 *
 * Handles three AJAX endpoints used by the frontend search widget:
 *   zre_search_products           — keyword product search
 *   zre_get_product_variations    — in-stock variations for a variable product
 *   zre_calculate_price_difference — price delta between original item and new product
 *
 * @package Zorem_Returns_Exchanges
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorem_Exchange_Product_Search {

	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init();
	}

	public function init() {
		add_action( 'wp_ajax_zre_search_products', array( $this, 'search_products' ) );
		add_action( 'wp_ajax_nopriv_zre_search_products', array( $this, 'search_products' ) );
		add_action( 'wp_ajax_zre_get_product_variations', array( $this, 'get_product_variations' ) );
		add_action( 'wp_ajax_nopriv_zre_get_product_variations', array( $this, 'get_product_variations' ) );
		add_action( 'wp_ajax_zre_calculate_price_difference', array( $this, 'calculate_price_difference' ) );
		add_action( 'wp_ajax_nopriv_zre_calculate_price_difference', array( $this, 'calculate_price_difference' ) );
	}

	// =========================================================================
	// AJAX: PRODUCT SEARCH
	// =========================================================================

	public function search_products() {
		check_ajax_referer( 'zre_cross_product_nonce', 'nonce' );

		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		if ( strlen( $keyword ) < 2 ) {
			wp_send_json_success( array() );
		}

		$ids = get_posts( array(
			's'              => $keyword,
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'fields'         => 'ids',
		) );

		$results = array();

		foreach ( $ids as $pid ) {
			$product = wc_get_product( $pid );

			if ( ! $product || ! $product->is_purchasable() ) {
				continue;
			}

			if ( $product->is_type( 'simple' ) && ! $product->is_in_stock() ) {
				continue;
			}

			$price_raw = $product->is_type( 'variable' )
				? floatval( $product->get_variation_price( 'min', true ) )
				: floatval( wc_get_price_including_tax( $product ) );

			$price_display = $product->is_type( 'variable' )
				? html_entity_decode( wp_strip_all_tags( wc_price( $product->get_variation_price( 'min', true ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ' – ' .
				html_entity_decode( wp_strip_all_tags( wc_price( $product->get_variation_price( 'max', true ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
				: html_entity_decode( wp_strip_all_tags( wc_price( $price_raw ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			$image_url = wp_get_attachment_image_url( $product->get_image_id(), array( 44, 44 ) );

			$results[] = array(
				'id'          => $product->get_id(),
				'name'        => $product->get_name(),
				'price_raw'   => $price_raw,
				'price'       => $price_display,
				'image'       => ! empty( $image_url ) ? $image_url : wc_placeholder_img_src(),
				'is_variable' => $product->is_type( 'variable' ),
			);
		}

		wp_send_json_success( $results );
	}

	// =========================================================================
	// AJAX: PRODUCT VARIATIONS
	// =========================================================================

	public function get_product_variations() {
		check_ajax_referer( 'zre_cross_product_nonce', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $product_id ) {
			wp_send_json_error();
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			wp_send_json_error();
		}

		$results = array();

		foreach ( $product->get_available_variations() as $v ) {
			$vobj = wc_get_product( $v['variation_id'] );

			if ( ! $vobj || ! $vobj->is_in_stock() ) {
				continue;
			}

			$parts = array();

			foreach ( $v['attributes'] as $key => $val ) {
				$tax   = str_replace( 'attribute_', '', $key );
				$label = wc_attribute_label( $tax );

				// Variation saved with "Any …" for this attribute — WooCommerce
				// returns an empty value. Label it explicitly instead of
				// rendering "Label: " with nothing after the colon.
				if ( '' === $val || null === $val ) {
					/* translators: %s: attribute label, e.g. "Any Size" */
					$parts[] = sprintf( __( 'Any %s', 'zorem-returns' ), $label );
					continue;
				}

				$term = taxonomy_exists( $tax ) ? get_term_by( 'slug', $val, $tax ) : false;

				$parts[] = $label . ': ' . ( $term ? $term->name : ucfirst( $val ) );
			}

			$label_text = implode( ' / ', $parts );

			$results[] = array(
				'variation_id' => $v['variation_id'],
				'label'        => ! empty( $label_text ) ? $label_text : __( 'Default', 'zorem-returns' ),
				'price_raw'    => floatval( wc_get_price_including_tax( $vobj ) ),
				'price'        => html_entity_decode( wp_strip_all_tags( wc_price( wc_get_price_including_tax( $vobj ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'attributes'   => $v['attributes'], // attribute_* => value ('' = Any)
			);
		}

		// Parent attribute definitions so the client can render one dropdown
		// per attribute (same UX as the WooCommerce product page). This lets
		// customers resolve "Any …" variations to concrete attribute values.
		$attribute_data = array();
		foreach ( $product->get_variation_attributes() as $attr_name => $options ) {
			$opts = array();
			foreach ( $options as $option ) {
				$name = $option;
				if ( taxonomy_exists( $attr_name ) ) {
					$term = get_term_by( 'slug', $option, $attr_name );
					if ( $term && ! is_wp_error( $term ) ) {
						$name = $term->name;
					}
				}
				$opts[] = array(
					'slug' => $option,
					'name' => $name,
				);
			}
			$attribute_data[] = array(
				'key'     => 'attribute_' . sanitize_title( $attr_name ),
				'label'   => wc_attribute_label( $attr_name, $product ),
				'options' => $opts,
			);
		}

		wp_send_json_success( array(
			'attributes' => $attribute_data,
			'variations' => $results,
		) );
	}

	// =========================================================================
	// AJAX: PRICE DIFFERENCE
	// =========================================================================

	public function calculate_price_difference() {
		check_ajax_referer( 'zre_cross_product_nonce', 'nonce' );

		$original_item_id = isset( $_POST['original_item_id'] ) ? absint( $_POST['original_item_id'] )  : 0;
		$new_product_id   = isset( $_POST['new_product_id'] )   ? absint( $_POST['new_product_id'] )    : 0;
		$new_variation_id = isset( $_POST['new_variation_id'] ) ? absint( $_POST['new_variation_id'] )  : 0;
		$new_qty          = isset( $_POST['new_qty'] )          ? max( 1, absint( $_POST['new_qty'] ) ) : 1;

		// Preferred payload: JSON array of the items the customer actually
		// selected for exchange — [{ item_id, qty }, ...]. qty 0 means
		// "use the ordered quantity". Falls back to the legacy single
		// original_item_id parameter for backwards compatibility.
		$original_items = array(); // item_id => requested qty (0 = ordered qty)
		$raw_items      = isset( $_POST['original_items'] ) ? sanitize_text_field( wp_unslash( $_POST['original_items'] ) ) : '';
		if ( '' !== $raw_items ) {
			$decoded = json_decode( $raw_items, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $row ) {
					$iid = isset( $row['item_id'] ) ? absint( $row['item_id'] ) : 0;
					$qty = isset( $row['qty'] ) ? absint( $row['qty'] ) : 0;
					if ( $iid ) {
						$original_items[ $iid ] = $qty;
					}
				}
			}
		}
		if ( empty( $original_items ) && $original_item_id ) {
			$original_items[ $original_item_id ] = 0;
		}

		if ( empty( $original_items ) || ! $new_product_id ) {
			wp_send_json_error();
		}

		// Load the parent order (all selected items belong to it) so the preview
		// uses the ACTUAL amount paid for the original line and re-applies its
		// coupons to the replacement — matching approval-time recalculation.
		$parent_order = false;
		foreach ( array_keys( $original_items ) as $iid ) {
			$oid = wc_get_order_id_by_order_item_id( $iid );
			if ( $oid ) {
				$parent_order = wc_get_order( $oid );
				break;
			}
		}

		// Sum of the selected original items using the actual paid amount
		// (incl tax, after coupons/discounts), prorated to the exchanged qty.
		$orig_price = 0;
		foreach ( $original_items as $item_id => $requested_qty ) {
			$line_item = $parent_order ? $parent_order->get_item( $item_id ) : false;
			if ( $line_item instanceof WC_Order_Item_Product ) {
				$orig_price += zorem_returns_exchanges()->exCommon->zre_original_line_paid_gross( $line_item, $requested_qty );
				continue;
			}

			// Fallback to catalog price if the order line can't be loaded.
			$orig_var_id  = (int) wc_get_order_item_meta( $item_id, '_variation_id', true );
			$orig_prod_id = (int) wc_get_order_item_meta( $item_id, '_product_id', true );
			$ordered_qty  = max( 1, (int) wc_get_order_item_meta( $item_id, '_qty', true ) );
			$use_qty      = ( $requested_qty >= 1 ) ? min( $requested_qty, $ordered_qty ) : $ordered_qty;
			$orig_obj     = wc_get_product( ! empty( $orig_var_id ) ? $orig_var_id : $orig_prod_id );
			if ( $orig_obj ) {
				$orig_price += floatval( wc_get_price_including_tax( $orig_obj ) ) * $use_qty;
			}
		}

		// New product price × requested qty, minus the same coupon discount when
		// the replacement is eligible (sale / excluded categories are skipped).
		$target_obj = wc_get_product( ! empty( $new_variation_id ) ? $new_variation_id : $new_product_id );
		if ( ! $target_obj ) {
			wp_send_json_error();
		}
		$new_unit_price = floatval( wc_get_price_including_tax( $target_obj ) );
		if ( $parent_order ) {
			$new_unit_price -= zorem_returns_exchanges()->exCommon->zre_replacement_unit_discount( $target_obj, $parent_order );
		}
		$new_price  = $new_unit_price * $new_qty;
		$difference = round( $new_price - $orig_price, 2 );

		// Preset exchange shipping fee that will be added to the child order on
		// approval — surfaced here so the customer sees the full amount due before
		// submitting. Mirrors zre_apply_preset_shipping_to_exchange_order().
		$exchange_shipping = 0.0;
		if ( $parent_order ) {
			$exchange_shipping = (float) zorem_returns_exchanges()->exCommon->zre_get_preset_shipping_cost(
				array(
					'country'   => $parent_order->get_shipping_country(),
					'state'     => $parent_order->get_shipping_state(),
					'postcode'  => $parent_order->get_shipping_postcode(),
					'city'      => $parent_order->get_shipping_city(),
					'address'   => $parent_order->get_shipping_address_1(),
					'address_2' => $parent_order->get_shipping_address_2(),
				),
				array(
					array(
						'data'       => $target_obj,
						'quantity'   => $new_qty,
						'line_total' => $new_price,
					),
				)
			);
		}
		$exchange_shipping = round( $exchange_shipping, 2 );
		$total_due         = 0.0;

		if ( $difference > 0.5 ) {
			$scenario  = 'pay_more';
			$total_due = round( $difference + $exchange_shipping, 2 );
			if ( $exchange_shipping > 0 ) {
				$message = sprintf(
					/* translators: 1: product price difference, 2: exchange shipping fee, 3: total due — all plain text */
					__( 'Price difference: %1$s + Exchange shipping: %2$s = <strong>Total due upon approval: %3$s</strong>', 'zorem-returns' ),
					html_entity_decode( wp_strip_all_tags( wc_price( $difference ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					html_entity_decode( wp_strip_all_tags( wc_price( $exchange_shipping ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					html_entity_decode( wp_strip_all_tags( wc_price( $total_due ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
				);
			} else {
				$message = sprintf(
					/* translators: %1$s: Additional charge amount (plain text, no HTML) */
					__( 'You will be charged an additional <strong>%1$s</strong> upon approval.', 'zorem-returns' ),
					html_entity_decode( wp_strip_all_tags( wc_price( $difference ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
				);
			}
		} elseif ( $difference < -0.5 ) {
			$scenario = 'store_credit';
			$message  = sprintf(
				/* translators: %1$s: Store credit amount (plain text, no HTML) */
				__( 'You will receive <strong>%1$s</strong> in store credit.', 'zorem-returns' ),
				html_entity_decode( wp_strip_all_tags( wc_price( abs( $difference ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
			);
		} else {
			$scenario   = 'even';
			$difference = 0;
			$message    = __( 'Even exchange — no additional payment needed.', 'zorem-returns' );
		}

		wp_send_json_success( array(
			'difference'        => $difference,
			'scenario'          => $scenario,
			'message'           => $message,
			'exchange_shipping' => $exchange_shipping,
			'total_due'         => $total_due,
		) );
	}

	// =========================================================================
	// HELPER
	// =========================================================================

	/**
	 * Returns true when cross-product exchange is enabled in plugin settings.
	 */
	public static function is_enabled() {
		return 'yes' === zorem_returns_exchanges()->common->return_settings( 'allow_cross_product_exchange', 'no' );
	}
}
