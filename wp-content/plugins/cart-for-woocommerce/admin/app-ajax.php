<?php

namespace FKCart\Admin;

use FKCart\Includes\Data;
use FKCart\Includes\Traits\Instance;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
if ( ! class_exists( '\FKCart\Admin\App_Ajax' ) ) {
	class App_Ajax {

		use Instance;

		/**
		 * Constructor
		 */
		public function __construct() {
			add_action( 'wp_ajax_fkcart_update_status', [ $this, 'update_status' ] );

			add_action( 'wp_ajax_fkcart_save_settings', [ $this, 'save_settings' ] );

			add_action( 'wp_ajax_fkcart_get_products', [ $this, 'get_products' ] );

			add_action( 'wp_ajax_fkcart_get_products_variations', [ $this, 'get_products_variations' ] );

			add_action( 'wp_ajax_fkcart_get_products_upsell_info', [ $this, 'get_products_upsell_info' ] );

			add_action( 'wp_ajax_fkcart_get_coupons', [ $this, 'get_coupons' ] );

			add_action( 'wp_ajax_fkcart_update_product', [ $this, 'update_product' ] );

			add_action( 'wp_ajax_fkcart_get_checkout_data', [ $this, 'get_checkout_data' ] );

			add_action( 'wp_ajax_fkcart_install_active_plugin', [ $this, 'install_activate_plugin' ] );

			add_action( 'wp_ajax_fkcart_get_stripe_redirect_link', [ $this, 'get_stripe_redirect_link' ] );

			add_action( 'wp_ajax_fkcart_update_user_preference', [ $this, 'update_user_preference' ] );

			add_action( 'wp_ajax_fkcart_get_cart_html', [ $this, 'get_cart_html' ] );
		}

		/**
		 * Verify nonce
		 *
		 * @return void
		 */
		protected function verify_nonce() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json( array(
					'msg'  => __( 'You do not have permission to perform this action.', 'cart-for-woocommerce' ),
					'code' => 403,
				) );
			}

			$nonce = isset( $_POST['fkcart_nonce'] ) ? sanitize_text_field( $_POST['fkcart_nonce'] ) : '';
			if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'fkcart-action-admin' ) ) {
				wp_send_json( array(
					'msg'  => __( 'Unable to save settings. Refresh the page and try again.', 'cart-for-woocommerce' ),
					'code' => 401,
				) );
			}
		}

		/**
		 * Clear cache
		 *
		 * @return void
		 */
		protected function clear_cache() {
			Admin_App::maybe_clear_cache();
		}

		/**
		 * Update status ajax cb
		 *
		 * @return void
		 */
		public function update_status() {
			$this->verify_nonce();
			$this->clear_cache();

			$current_settings                = Data::get_settings();
			$status                          = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : '';
			$current_settings['enable_cart'] = ( $status == 'publish' ) ? 1 : 0;

			if ( Data::save_settings( $current_settings ) ) {
				wp_send_json( array(
					'status' => true,
					'msg'    => __( 'Status updated', 'cart-for-woocommerce' ),
				) );
			}

			/** Unable to save status */
			wp_send_json( array(
				'status' => false,
				'msg'    => __( 'Unable to update status', 'cart-for-woocommerce' ),
			) );
		}

		/**
		 * Save settings ajax cb
		 *
		 * @return void
		 */
		public function save_settings() {
			$this->verify_nonce();
			$this->clear_cache();
			$settings = isset( $_POST['settings'] ) ? sanitize_text_field( $_POST['settings'] ) : [];
			$settings = json_decode( stripslashes( $settings ), true );
			if ( empty( $settings ) ) {
				wp_send_json( array(
					'status' => false,
					'msg'    => __( 'No settings were given', 'cart-for-woocommerce' ),
				) );
			}

			$current_settings = Data::get_settings();

			/** If current settings is same or settings updated returning success */
			if ( $current_settings === $settings || Data::save_settings( $settings ) ) {
				wp_send_json( array(
					'status' => true,
					'msg'    => __( 'Settings updated', 'cart-for-woocommerce' ),
				) );
			}

			/** Unable to save settings */
			wp_send_json( array(
				'status' => false,
				'msg'    => __( 'Unable to update settings', 'cart-for-woocommerce' ),
			) );
		}

		/**
		 * Returns products for auto completer
		 *
		 * @return void
		 */
		public function get_products() {
			$this->verify_nonce();

			ob_start();
			global $wpdb;
			$term           = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
			$image_show     = isset( $_POST['img_show'] ) && 1 === intval( $_POST['img_show'] ) ? sanitize_text_field( $_POST['img_show'] ) : 0;
			$show_variation = isset( $_POST['variations'] ) && 0 === intval( $_POST['variations'] ) ? 0 : 1;
			$special_addon  = isset( $_POST['special_addon'] ) && ( 'yes' === $_POST['special_addon'] );
			$like_term      = '%' . $wpdb->esc_like( $term ) . '%';
			$post_statuses  = [ 'publish' ];

			$p_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT posts.ID FROM {$wpdb->posts} AS posts LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup AS product_meta_lookup ON posts.ID = product_meta_lookup.product_id WHERE (posts.post_title LIKE %s OR product_meta_lookup.sku LIKE %s OR posts.ID LIKE %s) AND posts.post_status IN ('" . implode( "','", $post_statuses ) . "') AND posts.post_type = 'product' ORDER BY posts.post_parent ASC, posts.post_title ASC LIMIT 30", $like_term, $like_term, $like_term ) ); //phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders

			$products = $base_products = [];
			foreach ( $p_ids as $pid ) {
				/** Need to send 10 products only in the request */
				if ( count( $base_products ) >= 10 ) {
					break;
				}

				$prod_obj = wc_get_product( $pid );
				if ( ! $prod_obj instanceof \WC_Product ) {
					continue;
				}

				/** Type checking */
				$type = $prod_obj->get_type();

				/** Special addon handling */
				if ( $special_addon && in_array( $type, [
						'booking',
						'gift-card',
						'yith_bundle',
						'variable-subscription',
						'subscription_variation',
						'subscription',
						'virtual_subscription',
						'bundle',
						'woosb',
						'braintree-subscription',
						'braintree-variable-subscription',
					] ) ) {
					continue;
				}
				if ( ! wc_products_array_filter_editable( $prod_obj ) || ! fkcart_product_add_supported( $prod_obj ) ) {
					continue;
				}

				$products[]      = $this->get_product_data( $prod_obj, $image_show );
				$base_products[] = $pid;
				if ( ! in_array( $type, [ 'variable', 'variable-subscription' ], true ) ) {
					continue;
				}

				/** To include variations or not */
				if ( 0 === $show_variation ) {
					continue;
				}

				/** Variable product */
				$variations = $prod_obj->get_available_variations();
				$variations = array_map( function ( $variation ) use ( $image_show ) {
					return $this->get_product_data( wc_get_product( $variation['variation_id'] ), $image_show );
				}, $variations );

				$products = array_merge( $products, $variations );
			}

			ob_get_clean();
			wp_send_json( array(
				'status'   => true,
				'products' => $products,
				'msg'      => __( 'fetched products', 'cart-for-woocommerce' ),
			) );
		}

		/**
		 * Get product id, name and image as an array
		 *
		 * @param $product \WC_Product
		 * @param $image_show
		 *
		 * @return array
		 */
		protected function get_product_data( $product, $image_show = 0 ) {
			$product_arr = array(
				'id'   => $product->get_id(),
				'name' => trim( rawurldecode( $this->get_formatted_product_name( $product ) ) ),
			);
			if ( 1 === intval( $image_show ) ) {
				$size                 = apply_filters( 'fkcart_item_image_size', 'thumbnail' );
				$image_url            = wp_get_attachment_image_src( get_post_thumbnail_id( $product->get_id() ), $size, true );
				$image_url            = isset( $image_url[0] ) ? $image_url[0] : wc_placeholder_img_src();
				$image_file_name      = basename( $image_url );
				$product_arr['image'] = array( "src" => $image_url, "name" => $image_file_name );
			}

			return $product_arr;
		}

		/**
		 * Format product name
		 *
		 * @param $product \WC_Product
		 *
		 * @return string
		 */
		protected function get_formatted_product_name( $product ) {
			$arguments = array();

			$formatted_variation_list = self::get_variation_attribute( $product );
			if ( ! empty( $formatted_variation_list ) && count( $formatted_variation_list ) > 0 ) {
				foreach ( $formatted_variation_list as $att => $att_val ) {
					if ( '' === $att_val ) {
						$att_val = __( 'any' );
					}
					$att         = strtolower( $att );
					$att_val     = strtolower( $att_val );
					$arguments[] = "$att: $att_val";
				}
			}

			return sprintf( '%s (#%d) %s', $product->get_title(), $product->get_id(), ( count( $arguments ) > 0 ) ? '(' . implode( ',', $arguments ) . ')' : '' );
		}

		/**
		 * Returns product variation attributes
		 *
		 * @param $variation
		 *
		 * @return array
		 */
		public static function get_variation_attribute( $variation ) {
			if ( is_a( $variation, 'WC_Product_Variation' ) ) {
				return $variation->get_attributes();
			}

			$variation_attributes = array();
			if ( is_array( $variation ) ) {
				foreach ( $variation as $key => $value ) {
					$variation_attributes[ str_replace( 'attribute_', '', $key ) ] = $value;
				}
			}

			return ( $variation_attributes );
		}

		/**
		 * Returns products with variations
		 *
		 * @return void
		 */
		public function get_products_variations() {
			$this->verify_nonce();

			ob_start();
			$result = $this->product_search_variant( $_REQUEST );
			ob_get_clean();

			wp_send_json( $result );
		}

		public function product_search_variant( $request ) {
			$resp = array();

			$resp['success'] = false;
			$resp['msg']     = __( 'No Product Found', 'cart-for-woocommerce' );

			$term       = isset( $request['term'] ) ? sanitize_text_field( $request['term'] ) : '';
			$variations = isset( $request['variations'] ) ? wp_validate_boolean( $request['variations'] ) : false;
			$products   = $this->search_products( $term, $variations );

			$products = apply_filters( 'fkcart_woocommerce_json_search_found_products', $products );
			if ( count( $products ) > 0 ) {
				$resp['success']          = true;
				$resp['data']['products'] = $products;
				$resp['msg']              = __( 'Products Loaded', 'cart-for-woocommerce' );
			}

			return $resp;
		}

		/**
		 * Get product data including variation attributes
		 *
		 * @param $product_object
		 *
		 * @return array
		 */
		protected function get_product_variation_data( $product_object ) {
			if ( ! $product_object instanceof \WC_Product ) {
				return [];
			}

			$product_image        = ! empty( get_the_post_thumbnail_url( $product_object->get_id() ) ) ? get_the_post_thumbnail_url( $product_object->get_id(), 50 ) : FKCART_PLUGIN_URL . '/admin/assets/img/product_default_icon.jpg';
			$product_availability = $this->get_availability_price_text( $product_object );
			$product_stock        = $product_availability['text'];
			$stock_status         = ( $product_object->is_in_stock() ) ? true : false;

			$return_arr = array(
				'id'                   => $product_object->get_id(),
				'product'              => rawurldecode( $product_object->get_title() ),
				'product_attribute'    => '',
				'product_price'        => $product_availability['price'],
				'product_image'        => $product_image,
				'product_stock'        => $product_stock,
				'product_stock_status' => $stock_status,
				'product_type'         => $product_object->get_type(),
				'currency_symbol'      => get_woocommerce_currency_symbol(),
			);

			if ( is_a( $product_object, 'WC_Product_Variation' ) ) {
				$return_arr['product_attribute'] = $this->get_name_part( $product_object->get_name(), 1 );
			}

			return $return_arr;
		}

		public function search_products( $term, $include_variations = false ) {
			global $wpdb;
			$like_term     = '%' . $wpdb->esc_like( $term ) . '%';
			$post_statuses = current_user_can( 'edit_private_products' ) ? array(
				'private',
				'publish',
			) : array( 'publish' );

			$p_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT posts.ID FROM {$wpdb->posts} AS posts LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup AS product_meta_lookup ON posts.ID = product_meta_lookup.product_id WHERE (posts.post_title LIKE %s OR product_meta_lookup.sku LIKE %s OR posts.ID LIKE %s) AND posts.post_status IN ('" . implode( "','", $post_statuses ) . "') AND posts.post_type = 'product' ORDER BY posts.post_parent ASC, posts.post_title ASC LIMIT 10", $like_term, $like_term, $like_term ) ); //phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders

			$allowed_types = apply_filters( 'fkcart_offer_product_types', array(
				'simple',
				'variable',
				'course',
				'variation',
				'subscription',
				'variable-subscription',
				'subscription_variation',
				'virtual_subscription',
				'bundle',
				'yith_bundle',
				'woosb',
				'braintree-subscription',
				'braintree-variable-subscription',
			) );

			$products = [];
			foreach ( $p_ids as $pid ) {
				$prod_obj = wc_get_product( $pid );
				if ( ! $prod_obj instanceof \WC_Product ) {
					continue;
				}

				$products[] = $this->get_product_variation_data( $prod_obj );

				/** Type checking */
				$type = $prod_obj->get_type();
				if ( ! $include_variations || ! in_array( $type, [
						'variable',
						'variable-subscription'
					], true ) || ! wc_products_array_filter_editable( $prod_obj ) || ! in_array( $type, $allowed_types, true ) || 'publish' !== $prod_obj->get_status() ) {
					continue;
				}

				/** Variable product */
				$variations = $prod_obj->get_available_variations();
				$variations = array_map( function ( $variation ) {
					return $this->get_product_variation_data( wc_get_product( $variation['variation_id'] ) );
				}, $variations );

				$products = array_merge( $products, $variations );
			}

			return $products;
		}

		/**
		 * Returns product availability text
		 *
		 * @param $product \WC_Product
		 *
		 * @return array
		 */
		public function get_availability_price_text( $product ) {
			if ( ! $product instanceof \WC_Product ) {
				return [ 'text' => '', 'price' => '' ];
			}

			$availability = [];

			$availability_text = "";
			$available         = $product->get_availability();

			if ( ! empty( $available['class'] ) ) {
				switch ( $available['class'] ) {
					case 'available-on-backorder' :
						$availability_text = __( 'On backorder', 'cart-for-woocommerce' );
						break;
					case 'in-stock' :
						$availability_text = __( 'In stock', 'cart-for-woocommerce' );
						break;
					case 'out-of-stock' :
						$availability_text = __( 'Out of stock', 'cart-for-woocommerce' );
						break;
				}
			}

			$availability['text']  = $availability_text;
			$availability['price'] = $this->get_product_price( $product );

			return $availability;
		}

		/**
		 * Returns formatted product price
		 *
		 * @param $product
		 *
		 * @return string
		 */
		public function get_product_price( $product ) {
			if ( ! $product instanceof \WC_Product ) {
				return '';
			}
			if ( 'variable' === $product->get_type() ) {
				return html_entity_decode( strip_tags( $product->get_price_html() ) );
			}

			return html_entity_decode( strip_tags( wc_price( $product->get_price() ) ) );
		}

		/**
		 * Format product name
		 *
		 * @param $name
		 * @param $part
		 *
		 * @return mixed|string|string[]
		 */
		protected function get_name_part( $name, $part = 0 ) {
			if ( ! empty( $name ) && ! empty( $part ) ) {
				$name = explode( "-", $name );
				if ( ! empty( $name[ $part ] ) ) {
					$name = trim( $name[ $part ] );
				}
			}

			return $name;
		}

		/**
		 * Returns products data with upsell and cross-sell
		 *
		 * @return void
		 */
		public function get_products_upsell_info() {
			$this->verify_nonce();

			ob_start();
			$limit  = isset( $_POST['limit'] ) ? sanitize_text_field( $_POST['limit'] ) : '';
			$page   = isset( $_POST['page'] ) ? sanitize_text_field( $_POST['page'] ) : '';
			$search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';

			$param = array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => ! empty( $limit ) ? $limit : 10,
				'paged'          => ! empty( $page ) ? $page : 1,
				'fields'         => 'ids'
			);

			if ( ! empty( $search ) ) {
				$param['s'] = $search;
			}

			$wp_query    = new WP_Query( $param );
			$product_ids = $wp_query->get_posts();
			$products    = [];

			foreach ( $product_ids as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				$upsells    = $product->get_upsell_ids();
				$cross_sell = $product->get_cross_sell_ids();
				$size       = apply_filters( 'fkcart_item_image_size', 'thumbnail' );
				$image_url  = wp_get_attachment_image_src( get_post_thumbnail_id( $product->get_id() ), $size, true );
				$image_url  = isset( $image_url[0] ) ? $image_url[0] : wc_placeholder_img_src();
				$products[] = [
					'name'       => $this->get_formatted_product_name( $product ),
					'id'         => $product->get_id(),
					'image'      => $image_url,
					'upsells'    => $this->get_products_data( $upsells ),
					'cross_sell' => $this->get_products_data( $cross_sell ),
				];
			}

			$total = $wp_query->found_posts;

			ob_get_clean();
			wp_send_json( array(
				'status'   => true,
				'products' => $products,
				'total'    => $total,
				'msg'      => __( 'fetched products', 'cart-for-woocommerce' ),
			) );
		}

		/**
		 * Returns formatted product data
		 *
		 * @param $product_ids
		 *
		 * @return array
		 */
		public function get_products_data( $product_ids ) {
			$result = [];

			foreach ( $product_ids as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				$result[] = [
					'id'   => $pid,
					'name' => $this->get_formatted_product_name( $product ),
				];
			}

			return $result;
		}

		/**
		 * Returns coupons available
		 *
		 * @return void
		 */
		public function get_coupons() {
			$this->verify_nonce();

			ob_start();
			$search       = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
			$limit        = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 10;
			$coupon_codes = array();
			$args         = array(
				'posts_per_page'   => $limit,
				'orderby'          => 'title',
				'order'            => 'asc',
				'post_type'        => 'shop_coupon',
				'post_status'      => 'publish',
				'suppress_filters' => false
			);
			if ( ! empty( $search ) ) {
				$args['s'] = $search;
			}
			$coupons = get_posts( $args );
			foreach ( $coupons as $coupon ) {
				if ( ! empty( $coupon->post_title ) ) {
					$coupon_codes[] = [
						'key'   => $coupon->ID,
						'label' => $coupon->post_title,
					];
				}
			}

			ob_get_clean();
			wp_send_json( array(
				'status'  => true,
				'coupons' => $coupon_codes,
				'msg'     => __( 'fetched coupons', 'cart-for-woocommerce' ),
			) );
		}

		/**
		 * Update product upsell and cross sell data
		 *
		 * @return void
		 */
		public function update_product() {
			$this->verify_nonce();
			$this->clear_cache();

			$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$type         = isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : '';
			$product_data = isset( $_POST['product_data'] ) ? wc_clean( $_POST['product_data'] ) : [];

			if ( empty( $product_id ) || empty( $type ) ) {
				wp_send_json( array(
					'status' => false,
					'msg'    => __( 'Invalid data provided', 'cart-for-woocommerce' ),
				) );
			}

			if ( 'product' !== get_post_type( $product_id ) ) {
				wp_send_json( array(
					'status' => false,
					'msg'    => __( 'Invalid product ID', 'cart-for-woocommerce' ),
				) );
			}

			$result = update_post_meta( $product_id, $type === 'upsells' ? '_upsell_ids' : '_crosssell_ids', $product_data );

			wp_send_json( array(
				'status' => $result,
				'msg'    => $result ? __( 'Product data updated', 'cart-for-woocommerce' ) : __( 'Unable to update product data', 'cart-for-woocommerce' ),
			) );
		}

		/**
		 * Returns checkout template and plugin status data
		 *
		 * @return void
		 */
		public function get_checkout_data() {
			$this->verify_nonce();
			$result        = [];
			$template_data = Data::get_checkout_data();

			if ( ! empty( $template_data ) ) {
				if ( isset( $template_data['funnel'] ) && ! empty( $template_data['funnel'] ) && isset( $template_data['funnel']['elementor'] ) ) {
					$result['templates']['funnel']['elementor'] = $template_data['funnel']['elementor'];
				}
				if ( isset( $template_data['wc_thankyou'] ) && ! empty( $template_data['wc_thankyou'] ) && isset( $template_data['wc_thankyou']['elementor'] ) ) {
					$result['templates']['wc_thankyou']['elementor'] = $template_data['wc_thankyou']['elementor'];
				}
				if ( isset( $template_data['wc_checkout'] ) && ! empty( $template_data['wc_checkout'] ) && isset( $template_data['wc_checkout']['elementor'] ) ) {
					$result['templates']['wc_checkout']['elementor'] = $template_data['wc_checkout']['elementor'];
				}
				if ( isset( $template_data['upsell'] ) && ! empty( $template_data['upsell'] ) && isset( $template_data['upsell']['elementor'] ) ) {
					$result['templates']['upsell']['elementor'] = $template_data['upsell']['elementor'];
				}
				$result['sub_filter_group'] = [
					'wc_thankyou' => [
						'all' => 'All'
					],
					'upsell'      => [
						'all' => 'All'
					],
					'wc_checkout' => [
						'1' => 'One Step',
						'2' => 'Two Step',
						'3' => 'Three Step'
					],
				];
			}

			wp_send_json( array(
				'status' => true,
				'data'   => [
					'template_data'   => $result,
					'checkout_status' => $this->get_plugin_status( 'funnel-builder/funnel-builder.php' )
				],
				'msg'    => $result ? __( 'Fetched checkout data', 'cart-for-woocommerce' ) : __( 'Unable to fetch checkout data', 'cart-for-woocommerce' ),
			) );
		}

		/**
		 * Get plugin status
		 *
		 * @param $plugin_init_file
		 *
		 * @return string|void
		 */
		public function get_plugin_status( $plugin_init_file ) {
			$installed_plugins = get_plugins();
			if ( ! isset( $installed_plugins[ $plugin_init_file ] ) ) {
				return 'install';
			}
			if ( ! is_plugin_active( $plugin_init_file ) ) {
				return 'activate';
			}
			if ( is_plugin_active( $plugin_init_file ) ) {
				return 'activated';
			}
		}

		/**
		 * Install or activate a plugin
		 *
		 * @return void
		 */
		public function install_activate_plugin() {
			$this->verify_nonce();

			$plugin_basename = isset( $_POST['basename'] ) ? sanitize_text_field( $_POST['basename'] ) : 'funnel-builder/funnel-builder.php';
			$plugin_slug     = isset( $_POST['slug'] ) ? sanitize_text_field( $_POST['slug'] ) : 'funnel-builder';

			$plugin_status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : '';
			$plugin_status = ! empty( $plugin_status ) ? $this->get_plugin_status( $plugin_basename ) : $plugin_status;

			$response = $this->install_or_activate_addon_plugins( $plugin_basename, $plugin_slug, $plugin_status );

			wp_send_json( $response );
		}

		/**
		 * Install and Activate any plugin from the WordPress repo
		 *
		 * @param $plugin
		 * @param $plugin_slug
		 * @param $action
		 *
		 * @return mixed|void
		 */
		public function install_or_activate_addon_plugins( $plugin, $plugin_slug, $action ) {
			/** Do not allow WordPress to search/download translations, as this will break JS output. */
			remove_action( 'upgrader_process_complete', [ 'Language_Pack_Upgrader', 'async_upgrade' ], 20 );

			switch ( $action ) {
				case 'install':
					$result = $this->install_plugin( $plugin_slug, $plugin );
					break;
				case 'activate':
					$result = $this->activate_plugin( $plugin );
					break;
				default:
					$result = array(
						'status' => false,
						'msg'    => __( 'Undefined error', 'cart-for-woocommerce' ),
					);
			}

			return apply_filters( 'fkcart_plugin_activate_response', $result, $plugin );
		}

		/**
		 * Install a plugin
		 *
		 * @param $plugin_slug
		 * @param $plugin
		 *
		 * @return array
		 */
		public function install_plugin( $plugin_slug, $plugin ) {
			if ( ! current_user_can( 'install_plugins' ) ) {
				return array(
					'status' => false,
					'msg'    => __( 'You do not have permission to install plugins.', 'cart-for-woocommerce' ),
				);
			}

			if ( empty( $plugin_slug ) ) {
				return array(
					'status' => false,
					'msg'    => __( 'Plugin slug is missing', 'cart-for-woocommerce' ),
				);
			}

			$resp = array(
				'status' => false,
				'msg'    => __( 'Unable to install plugin', 'cart-for-woocommerce' ),
			);

			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			include_once ABSPATH . '/wp-admin/includes/admin.php';
			include_once ABSPATH . '/wp-admin/includes/plugin-install.php';
			include_once ABSPATH . '/wp-admin/includes/plugin.php';
			include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . '/wp-admin/includes/class-plugin-upgrader.php';

			$api = plugins_api( 'plugin_information', array(
				'slug'   => $plugin_slug,
				'fields' => array(
					'sections' => false,
				),
			) );

			if ( is_wp_error( $api ) ) {
				$resp['msg'] = $api->get_error_message();

				return $resp;
			}

			$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
			$result   = $upgrader->install( $api->download_link );

			if ( is_wp_error( $result ) ) {
				$resp['msg'] = $result->get_error_message();

				return $resp;
			}

			if ( is_null( $result ) ) {
				global $wp_filesystem;
				$resp['msg'] = __( 'Unable to connect to the filesystem. Please confirm your credentials.', 'cart-for-woocommerce' );

				/** Pass through the error from WP_Filesystem if one was raised. */
				if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
					$resp['msg'] = esc_html( $wp_filesystem->errors->get_error_message() );
				}

				return $resp;
			}

			return $this->activate_plugin( $plugin );
		}

		/**
		 * Activate a plugin
		 *
		 * @param $plugin
		 *
		 * @return array
		 */
		public function activate_plugin( $plugin ) {
			if ( empty( $plugin ) ) {
				return array(
					'status' => false,
					'msg'    => __( 'Plugin basename is missing', 'cart-for-woocommerce' ),
				);
			}

			/** Check for user permissions */
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return array(
					'status' => false,
					'msg'    => __( 'You don\'t have permission to activate plugin', 'cart-for-woocommerce' ),
				);
			}

			/** Activate the plugin */
			$activated = activate_plugin( $plugin );

			if ( is_wp_error( $activated ) ) {
				return array(
					'status' => false,
					'msg'    => __( 'Some error occurred while activating the plugin', 'cart-for-woocommerce' ),
				);
			}

			return array(
				'status' => true,
				'msg'    => __( 'Plugin activated', 'cart-for-woocommerce' ),
			);
		}

		/**
		 * Update user preference for hide lite bar
		 *
		 * @return void
		 */
		public function update_user_preference() {
			$this->verify_nonce();

			$user_id = get_current_user_id();
			$data    = isset( $_POST['data'] ) ? wc_clean( $_POST['data'] ) : [];

			if ( empty( $user_id ) ) {
				wp_send_json( array(
					'status' => false,
					'msg'    => __( 'Invalid user', 'cart-for-woocommerce' ),
				) );
			}

			if ( ! empty( $data ) ) {
				$userdata   = get_user_meta( $user_id, '_fkcart_notifications_close', true );
				$userdata   = empty( $userdata ) && ! is_array( $userdata ) ? [] : $userdata;
				$userdata[] = $data;
				update_user_meta( $user_id, '_fkcart_notifications_close', $userdata ); //phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.user_meta_update_user_meta

				wp_send_json( array(
					'status' => true,
					'msg'    => __( 'Preferences Updated', 'cart-for-woocommerce' ),
				) );
			}

			wp_send_json( array(
				'status' => false,
			) );
		}

		public function get_cart_html() {
			$this->verify_nonce();

			$_GET['page'] = 'fkcart';
			$output       = '<div id="fkcart-modal" class="fkcart-modal fkcart-show">' . fkcart_get_active_skin_html() . '</div>';

			wp_send_json( [
				'side_html' => $output,
				'code'      => 200
			] );
		}

		/**
		 * Return stripe redirect url
		 *
		 * @return mixed
		 */
		public function get_stripe_redirect_link() {
			$this->verify_nonce();
			$response = [
				'link'   => '',
				'status' => false,
				'reload' => false,
			];
			// check for stripe class
			if ( class_exists( '\FKWCS\Gateway\Stripe\Admin' ) ) {
				$stripe_obj = \FKWCS\Gateway\Stripe\Admin::get_instance();
				if ( $stripe_obj->is_stripe_connected() ) {
					$response['reload'] = true;
				} else {
					$response = [
						'link'   => \FKWCS\Gateway\Stripe\Admin::get_instance()->get_connect_url(),
						'status' => true,
					];
				}
			}
			wp_send_json( $response );
		}
	}
}
