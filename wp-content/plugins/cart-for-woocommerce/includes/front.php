<?php

namespace FKCart\Includes;

use FKCart\Includes\Traits\Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( '\FKCart\Includes\Front' ) ) {
	class Front {

		use Instance;

		private $add_to_cart_trigger = false;
		private $preview_discount = 20;
		private $drawer_displayed = false;
		public $active_free_shipping = false;
		public $default_wc_location = '';
		private $cached_items = null;

		/**
		 * Class constructor
		 */
		private function __construct() {
			add_action( 'woocommerce_add_to_cart', [ $this, 'add_to_cart_trigger' ], - 10 );
			add_action( 'wp', [ $this, 'init_hooks' ] );
			add_filter( 'fkcart_admin_ajax_args', [ $this, 'append_ajax_parameter' ] );

			/** Disable redirect to cart after adding product */
			add_filter( 'pre_option_woocommerce_cart_redirect_after_add', [ $this, 'disable_woocommerce_cart_redirect_after_add' ] );

			/** Enable ajax on add to cart product */
			add_filter( 'pre_option_woocommerce_enable_ajax_add_to_cart', [ $this, 'enable_woocommerce_enable_ajax_add_to_cart' ] );
			add_action( 'after_setup_theme', [ $this, 'register_shortcode' ] );

			/* Display Strike price  */
			add_action( 'fkcart_after_header', [ $this, 'attach_action' ] );


		}


		/**
		 * Initialize hooks after theme setup
		 *
		 * @return void
		 */
		public function init_hooks() {
			/** Prevent Below Code execution when rest api call running */
			if ( $this->is_rest_call() ) {
				return;
			}

			if ( is_admin() ) {
				return;
			}
			/**
			 * Update set cookie after thank u page
			 */
			add_action( 'wp', [ $this, 'unset_cookies' ], 99 );
			add_action( 'woocommerce_thankyou', [ $this, 'unset_js_cookies' ], 99 );

			add_action( 'wfocu_footer_before_print_scripts', [ $this, 'unset_js_cookies' ], 99 );

			if ( false === Data::is_cart_enabled( 'all' ) ) {
				return;
			}

			add_action( 'wp_enqueue_scripts', [ $this, 'load_cart_assets' ], 13 );
			add_action( 'wp_footer', [ $this, 'cart_icon' ], 20 );
			add_action( 'wp_footer', [ $this, 'cart_content' ], 21 );
			add_filter( 'fkcart_fragments', [ $this, 'button_icon_fragments' ] );
			add_filter( 'wp_nav_menu_items', [ $this, 'append_cart_link' ], 99, 2 );
			add_action( 'wp_footer', [ $this, 'handleCartFlickering' ], 21 );

			/** @todo remove below action in Mar month */
			add_filter( 'fkwcs_express_button_selected_location', [ $this, 'enqueue_smart_button_javascript' ] );

			add_filter( 'fkwcs_enqueue_express_button_assets', [ $this, 'enqueue_smart_button_javascript' ] );
		}

		/**
		 * Check if a wc rest call
		 *
		 * @return bool
		 */
		public function is_rest_call() {
			return ( function_exists( 'WC' ) && WC()->is_rest_api_request() );
		}

		/**
		 * Load JS & CSS assets
		 *
		 * @return void
		 */
		public function load_cart_assets() {
			/** Return if display disabled */
			if ( Data::is_display_disabled() ) {
				return;
			}
			Data::load_cart_assets();
			$custom_css = Data::get_value( 'custom_css' );
			if ( ! empty( $custom_css ) ) {
				wp_add_inline_style( 'fkcart-style', $custom_css );
			}
			wp_localize_script( 'fkcart-script', 'fkcart_app_data', $this->localize_data() );
			wp_enqueue_script( 'wc-single-product' );
			wp_enqueue_script( 'wc-add-to-cart-variation' );

			/**
			 * @todo Need to Remove below code in Future version by Jun 2024
			 */
			$smart_buttons_active = Data::is_smart_button_enabled();

			if ( $smart_buttons_active ) {
				wp_enqueue_script( 'wc-cart-fragments' );
			}
		}

		/**
		 * Cart display via shortcode
		 * [fk_cart_menu]
		 *
		 * @return false|string
		 */
		public function mini_cart_shortcode_cb() {
			if ( ( is_admin() && ! wp_doing_ajax() ) || $this->is_rest_call() ) {
				return '';
			}
			if ( false === Data::is_cart_enabled( 'shortcode' ) ) {
				return '';
			}
			if ( wp_doing_cron() ) {
				return '';
			}

			add_action( 'wp_footer', [ $this, 'cart_content' ], 99 );

			return $this->get_mini_cart_toggler();
		}

		/**
		 * Get mini cart HTML
		 *
		 * @return false|string
		 */
		public function get_mini_cart_toggler() {
			ob_start();
			$style = Data::get_active_mini_cart_skin();
			fkcart_get_template_part( 'site/mini-' . $style );

			return ob_get_clean();
		}

		/**
		 * Attach fragment
		 *
		 * @param $fragments
		 *
		 * @return mixed
		 */
		public function button_icon_fragments( $fragments ) {
			ob_start();
			$this->cart_icon();
			$fragments['#fkcart-floating-toggler'] = ob_get_clean();

			return $fragments;
		}

		/**
		 * Floating cart icon view
		 *
		 * @return void
		 */
		public function cart_icon() {
			if ( false === Data::is_cart_enabled() ) {
				return;
			}
			$style = Data::get_active_icon_style();

			fkcart_get_template_part( 'site/button-' . $style );
		}

		/**
		 * Floating cart contact view
		 *
		 * @return string|void
		 */
		public function cart_content() {
			if ( false === Data::is_cart_enabled( 'all' ) ) {
				return '';
			}
			if ( true === $this->drawer_displayed ) {
				return;
			}
			$upsell_style  = Data::get_value( 'upsell_style' );
			$upsell_style  = empty( $upsell_style ) ? 'style1' : $upsell_style;
			$icon_position = Data::get_value( 'cart_icon_position' );
			?>
            <div id="fkcart-modal" class="fkcart-modal" data-upsell-style="<?php esc_attr_e( $upsell_style ); ?>">
                <div class="fkcart-modal-container" data-direction="<?php esc_attr_e( is_rtl() ? 'rtl' : 'ltr' ); ?>" data-slider-pos="<?php esc_attr_e( $icon_position ); ?>">
					<?php fkcart_get_template_part( 'cart/placeholder' ); ?>
                </div>
            </div>


			<?php
			$this->drawer_displayed = true;
		}

		/**
		 * Public localize data
		 *
		 * @return array
		 */
		public function localize_data() {
			$ajax_url                    = admin_url( 'admin-ajax.php' );
			$query                       = apply_filters( 'fkcart_admin_ajax_args', [] );
			$is_ajax_add_to_cart_enabled = wc_string_to_bool( Data::get_value( 'ajax_add_to_cart' ) ) && is_product() && ! self::is_excluded_product_types() ? 'yes' : 'no';

			$themes_trigger_slide_cart_class = apply_filters( 'fkcart_trigger_slide_cart_class', [
				'.cart-item .header-cart-link',
				'.et-cart-info',
				'#mini-cart .cart-head span.cart-icon',
				'.site-header-cart .cart-click',
				'.wcmenucart',
				'.wd-header-cart',
			] );
			$trigger_slide_cart_class        = Data::get_value( 'trigger_slide_cart_class' );
			if ( ! empty( $trigger_slide_cart_class ) ) {
				$themes_trigger_slide_cart_class[] = $trigger_slide_cart_class;

			}
			$arr            = [
				'ajax_nonce'                 => wp_create_nonce( 'fkcart' ),
				'is_preview'                 => fkcart_is_preview(),
				'ajax_url'                   => ! empty( $query ) ? add_query_arg( $query, $ajax_url ) : $ajax_url,
				'force_open_cart'            => 'no',
				'open_side_cart'             => $this->add_to_cart_trigger ? 'yes' : 'no',
				'should_open_cart'           => 'yes',
				'is_cart'                    => is_cart(),
				'is_shop'                    => is_shop(),
				'is_single_product'          => is_product(),
				'need_extra_slide_cart_ajax' => Data::need_re_run_get_slide_cart_ajax() ? 'yes' : 'no',
				'ajax_add_to_cart'           => apply_filters( 'fkcart_is_ajax_add_to_cart_enabled', $is_ajax_add_to_cart_enabled, $this ),
				'wc_endpoints'               => AJAX::get_public_endpoints( $query ),
				'update_side_cart_on_load'   => apply_filters( 'fkcart_update_side_cart_on_load', 'no', $this ),
				'trigger_slide_cart_class'   => $themes_trigger_slide_cart_class,
				'cart_hash_key'              => apply_filters( 'fkcart_hash_key', 'fkcart_hash_' . md5( get_current_blog_id() . '_' . get_site_url( get_current_blog_id(), '/' ) . get_template() ) ),
				'fragment_name'              => apply_filters( 'fkcart_fragment_name', 'fkcart_fragments_' . md5( get_current_blog_id() . '_' . get_site_url( get_current_blog_id(), '/' ) . get_template() ) ),
				'locale'                     => get_locale(),
				'smart_buttons_wrapper'      => apply_filters( 'fkcart_smart_buttons_wrappers', [
					'dynamic_buttons'     => [
						"#fkcart_fkwcs_smart_button" => "#fkcart_fkwcs_smart_button",
					],
					'no_conflict_buttons' => [
						"#fkcart_paypalpayments_smart_button",
						"#fkcart_pymntpl_paypal_payments_smart_button",
						"#fkcart_fkwcs_smart_button_gpay"
					]
				] )

			];
			$auto_open_cart = Data::get_value( 'enable_auto_open_cart' );
			if ( 0 === intval( $auto_open_cart ) || false === $auto_open_cart || 'false' === strval( $auto_open_cart ) ) {
				$arr['should_open_cart'] = 'no';
			}

			if ( true === $this->add_to_cart_trigger ) {
				$arr['force_open_cart'] = 'yes';
			}
			$arr['cookie_names'] = Data::fkcart_frontend_cookie_names();

			return $arr;
		}

		/**
		 * Get current cart count
		 *
		 * @return int
		 */
		public function get_cart_content_count() {
			return fkcart_is_preview() ? 2 : ( ! is_null( WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0 );
		}

		/**
		 * Get cart items
		 *
		 * @return array
		 */
		public function get_items() {
			/** Return cached result to avoid duplicate calculate_totals() within the same request */
			if ( null !== $this->cached_items ) {
				return $this->cached_items;
			}

			$items = [];
			add_filter( 'woocommerce_is_attribute_in_product_name', '__return_false' ); //Do not append attributes with title

			/** When preview */
			if ( fkcart_is_preview() ) {
				$products = fkcart_get_dummy_products();
				$items[]  = $this->get_dummy_preview_item( $products[1] );
				$items[]  = $this->get_dummy_preview_item( $products[2] );

				$this->cached_items = $items;

				return $items;
			}

			if ( ! is_null( WC()->cart ) && ! WC()->cart->is_empty() ) {
				WC()->cart->calculate_totals();
			}
			do_action( 'fkcart_get_cart_item' );
			$cart_contents = WC()->cart->get_cart_contents();
			foreach ( $cart_contents as $cart_item_key => $cart_item ) {
				$items[ $cart_item_key ] = $this->get_cart_items( $cart_item_key, $cart_item );
			}

			$this->cached_items = $items;

			return $items;
		}

		/**
		 * Prepare product data from product object
		 *
		 * @param $_product \WC_Product
		 *
		 * @return array
		 */
		public function get_preview_item( $_product ) {
			$show_link = apply_filters( 'fkcart_preview_enable_item_link', true );

			$product_id        = $_product->get_id();
			$product_permalink = $_product->is_visible() ? $_product->get_permalink() : '';
			$product_name      = $_product->get_name();
			$product_name      = $product_permalink && $show_link ? sprintf( '<a href="%s" class="fkcart-item-title">%s</a>', $product_permalink, $product_name ) : $product_name;
			$product_price     = $_product->get_price();
			$product_subtotal  = $_product->get_price();

			$size      = apply_filters( 'fkcart_item_image_size', 'thumbnail' );
			$thumbnail = $_product->get_image( $size, [
				'class'   => 'fkcart-image',
				'loading' => 'lazy'
			] );
			$thumbnail = $product_permalink && $show_link ? sprintf( '<a href="%s" class="fkcart-image-wrapper" tabindex="-1" aria-hidden="true">%s</a>', esc_url( $product_permalink ), $thumbnail ) : sprintf( '<div class="fkcart-image-wrapper"><span>%s</span></div>', $thumbnail );

			return [
				'is_cart_item'      => 0,
				'product'           => $_product,
				'product_id'        => $product_id,
				'product_permalink' => $product_permalink,
				'product_name'      => $product_name,
				'product_price'     => $_product->get_type() === 'variable' ? $_product->get_price_html() : $product_price,
				'product_subtotal'  => $product_subtotal,
				'thumbnail'         => $thumbnail,
				'quantity'          => 1,
				'price'             => wc_price( 50 ),
				'_fkcart_free_gift' => false,
				'sold_individually' => $_product->is_sold_individually(),
				'product_meta'      => ''
			];
		}

		/**
		 * Get dummy product HTML
		 *
		 * @param $product
		 *
		 * @return array
		 */
		public function get_dummy_preview_item( $product ) {
			$saving = '';
			if ( isset( $product['sale_price'] ) && ( $product['price'] - $product['sale_price'] ) > 0 ) {
				$saving = ( ( $product['price'] - $product['sale_price'] ) * 100 ) / $product['price'];
				$saving = number_format( $saving, 2 );
			}

			return [
				'product_id'     => rand( 1, 10 ),
				'thumbnail'      => '<a href="#" class="fkcart-image-wrapper"><img width="150" height="150" src="' . esc_url( plugin_dir_url( FKCART_PLUGIN_FILE ) . 'admin/assets/img/dummy/' . $product['image'] ) . '" class="fkcart-image"/></a>',
				'product_name'   => '<a href="#" class="fkcart-item-title">' . $product['name'] . '</a>',
				'product_meta'   => isset( $product['meta'] ) ? $product['meta'] : '',
				'price'          => ! empty( $saving ) ? '<del aria-hidden="true">' . wc_price( $product['price'] ) . '</del>' . wc_price( $product['sale_price'] ) : wc_price( $product['price'] ),
				'saving_percent' => $saving,
				'saving_amount'  => number_format( $product['price'] - $product['sale_price'], 2 ),
			];
		}

		/**
		 * Prepare product data from cart item
		 *
		 * @param $cart_item_key
		 * @param $cart_item
		 *
		 * @return array|null
		 */
		public function get_cart_items( $cart_item_key, $cart_item ) {

			$show_link = apply_filters( 'fkcart_enable_item_link', true, $cart_item_key, $cart_item );

			/** @var \WC_Product $_product */
			$_product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
			$product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );
			if ( ! $_product || ! $_product->exists() || $cart_item['quantity'] < 0 || ! apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
				return [ 'visibility_hidden' => 'yes' ];
			}

			$product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
			$product_name      = apply_filters( 'woocommerce_cart_item_name', $_product->get_title(), $cart_item, $cart_item_key );
			$product_price     = apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $_product ), $cart_item, $cart_item_key );
			$product_subtotal  = apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key );

			/** Some hooks modify price on get_product_subtotal */
			$raw_subtotal = preg_replace( "/[^0-9]/", '', strip_tags( ! is_null( $product_subtotal ) ? $product_subtotal : '' ) );

			$size      = apply_filters( 'fkcart_item_image_size', 'thumbnail' );
			$thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image( $size, [
				'class'   => 'fkcart-image',
				'loading' => 'lazy'
			] ), $cart_item, $cart_item_key );
			$thumbnail = $product_permalink && $show_link ? sprintf( '<a href="%s" class="fkcart-image-wrapper" tabindex="-1" aria-hidden="true">%s</a>', esc_url( $product_permalink ), $thumbnail ) : sprintf( '<div class="fkcart-image-wrapper">%s</div>', $thumbnail );

			$product_name           = $product_permalink && $show_link ? sprintf( '<a href="%s" class="fkcart-item-title">%s</a>', $product_permalink, $product_name ) : sprintf( '<div class="fkcart-item-title">%s</div>', $product_name );
			$product_meta           = fkcart_get_formatted_cart_item_data( $cart_item );
			$product_delete_icon    = apply_filters( 'woocommerce_cart_item_remove_link', sprintf( '<a href="%s" class="remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">&times;</a>', esc_url( wc_get_cart_remove_url( $cart_item_key ) ), esc_html__( 'Remove this item', 'cart-for-woocommerce' ), esc_attr( $product_id ), esc_attr( $_product->get_sku() ) ), $cart_item_key );
			$product_quantity_input = woocommerce_quantity_input( array(
				'input_name'   => "cart[{$cart_item_key}][qty]",
				'input_value'  => $cart_item['quantity'],
				'max_value'    => 1,
				'min_value'    => 1,
				'product_name' => $_product->get_name(),
			), $_product, false );
			$product_quantity_input = apply_filters( 'woocommerce_cart_item_quantity', $product_quantity_input, $cart_item_key, $cart_item );
			$product_quantity_input = is_numeric( $product_quantity_input ) && $product_quantity_input;
			$is_child_product       = apply_filters( 'fkcart_is_child_item', false, $cart_item );

			return [
				'_fkcart_variation_gift' => isset( $cart_item['_fkcart_variation_gift'] ),
				'is_cart_item'           => $cart_item_key,
				'product'                => $cart_item['data'],
				'product_id'             => $product_id,
				'product_permalink'      => $product_permalink,
				'product_name'           => $product_name,
				'product_price'          => $product_price,
				'product_subtotal'       => $product_subtotal,
				'thumbnail'              => $thumbnail,
				'price'                  => $product_subtotal,
				'quantity'               => $cart_item['quantity'],
				'is_child_item'          => $is_child_product,
				'hide_you_saved_text'    => apply_filters( 'fkcart_item_hide_you_saved_text', false, $cart_item ),
				'_fkcart_free_gift'      => apply_filters( 'fkcart_item_hide_delete_icon', isset( $cart_item['_fkcart_free_gift'] ) || empty( $product_delete_icon ) || $is_child_product, $cart_item ),
				'sold_individually'      => apply_filters( 'fkcart_cart_item_is_sold_individually', $_product->is_sold_individually() || $product_quantity_input || ! ( $raw_subtotal > 0 ) || $is_child_product || isset( $cart_item['_wfob_options'] ), $cart_item ),
				'product_meta'           => $product_meta,
				'cart_item'              => $cart_item,
			];
		}

		public static function get_dummy_rewards() {

			return [
				"max_amount"    => 50,
				"title"         => "Congrats! You have unlocked all the rewards.",
				"coupons"       => [
					"add"    => [],
					"remove" => []
				],
				"gifts"         => [
					"add"    => [],
					"remove" => []
				],
				"free_shipping" => "free_shipping:2",
				"rewards"       => [
					[
						"type"            => "freeshipping",
						"title"           => "Add items worth {{remaining_amount}} more to unlock FREE Shipping",
						"icon_title"      => "Free Shipping",
						"amount"          => 50,
						"shipping_method" => "free_shipping:2",
						"achieved"        => true,
						"pending_amount"  => 50,
						"progress_width"  => 100
					]
				],
				"progress_bar"  => 100,
				"subtotal"      => 156
			];
		}

		/**
		 * Get cart applied coupons
		 *
		 * @return array
		 */
		public function get_coupons() {
			if ( fkcart_is_preview() ) {
				$output['free20'] = [ 'code' => 'free20', 'value' => wc_price( $this->preview_discount ) ];

				return $output;
			}

			$output = [];
			foreach ( WC()->cart->get_coupons() as $code => $coupon ) {
				if ( is_string( $coupon ) ) {
					$coupon = new \WC_Coupon( $coupon );
				}
				$output[ $code ] = [ 'code' => $coupon->get_code(), 'value' => $this->wc_cart_totals_coupon_html( $coupon, false ), 'instance' => $coupon ];
			}

			return $output;
		}

		/**
		 * Get cart subtotal
		 *
		 * @param $raw
		 *
		 * @return float|int|string
		 */
		public function get_subtotal( $raw = false ) {
			if ( fkcart_is_preview() ) {
				$products = fkcart_get_dummy_products();
				$price    = $products[1]['sale_price'] + $products[2]['sale_price'];

				return false === $raw ? wc_price( $price ) : $price;
			}

			try {
				$price = $this->get_subtotal_row( true ) - WC()->cart->get_discount_total();
				if ( WC()->cart->display_prices_including_tax() ) {
					$price = $price - WC()->cart->get_discount_tax();
				}
			} catch ( \Exception|\Error $e ) {
				$price = 0;
			}

			return false === $raw ? wc_price( $price ) : $price;
		}

		/**
		 * Get subtotal value to display in a row
		 *
		 * @return string
		 */
		public function get_subtotal_row( $raw = false ) {
			if ( fkcart_is_preview() ) {
				$products = fkcart_get_dummy_products();
				$price    = $products[1]['sale_price'] + $products[2]['sale_price'];

				return ( false === $raw ) ? wc_price( $price ) : floatval( $price );
			}

			$price = 0;
			try {
				if ( ! is_null( WC()->cart ) ) {
					$price = ( WC()->cart->display_prices_including_tax() ) ? WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax() : WC()->cart->get_subtotal();
				}
			} catch ( \Error|\Exception $e ) {
			}

			return ( false === $raw ) ? wc_price( $price ) : floatval( $price );
		}

		/**
		 * Get total value of cart after discount
		 *
		 * @return string
		 */
		public function get_total_row( $raw = false ) {
			if ( fkcart_is_preview() ) {
				$products = fkcart_get_dummy_products();
				$price    = $products[1]['sale_price'] + $products[2]['sale_price'];

				return ( false === $raw ) ? wc_price( $price ) : $price;
			}

			$price = ( WC()->cart->display_prices_including_tax() ) ? WC()->cart->get_cart_contents_total() + WC()->cart->get_cart_contents_tax() : WC()->cart->get_cart_contents_total();

			return ( false === $raw ) ? wc_price( $price ) : $price;
		}

		/**
		 * Get discounted sub total
		 * Used in admin preview only
		 *
		 * @return float|mixed|string
		 */
		public function get_discounted_subtotal() {
			if ( ! fkcart_is_preview() ) {
				return '';
			}
			$products = fkcart_get_dummy_products();
			$price    = $products[1]['sale_price'] + $products[2]['sale_price'] - $this->preview_discount;

			return wc_price( $price );
		}

		/**
		 * Get min max step for product input
		 *
		 * @param $_product \WC_Product
		 *
		 * @return array
		 */
		public function get_min_max_step( $_product ) {
			$defaults = array(
				'max_value' => apply_filters( 'woocommerce_quantity_input_max', - 1, $_product ),
				'min_value' => apply_filters( 'woocommerce_quantity_input_min', 0, $_product ),
				'step'      => apply_filters( 'woocommerce_quantity_input_step', 1, $_product )
			);

			$args = apply_filters( 'woocommerce_quantity_input_args', $defaults, $_product );

			/** Apply sanity to min/max args - min cannot be lower than 0 */
			$args['min_value'] = max( $args['min_value'], 0 );
			$args['max_value'] = 0 < $args['max_value'] ? $args['max_value'] : '';

			/** Max cannot be lower than min if defined */
			if ( '' !== $args['max_value'] && $args['max_value'] < $args['min_value'] ) {
				$args['max_value'] = $args['min_value'];
			}

			return [ $args['min_value'], $args['max_value'], $args['step'] ];
		}

		/**
		 * Return or echo cart total
		 *
		 * @param $coupon
		 * @param $echo
		 *
		 * @return string|void
		 */
		function wc_cart_totals_coupon_html( $coupon, $echo = true ) {
			if ( is_string( $coupon ) ) {
				$coupon = new \WC_Coupon( $coupon );
			}

			$amount = WC()->cart->get_coupon_discount_amount( $coupon->get_code(), WC()->cart->display_cart_ex_tax );

			$discount_amount_html = wc_price( $amount );

			if ( $coupon->get_free_shipping() && empty( $amount ) ) {
				$discount_amount_html = __( 'Free shipping coupon', 'woocommerce' );
			}

			$discount_amount_html = apply_filters( 'woocommerce_coupon_discount_amount_html', $discount_amount_html, $coupon );
			$value                = apply_filters( 'woocommerce_cart_totals_coupon_html', $discount_amount_html, $coupon, $discount_amount_html );

			if ( $echo ) {
				echo wp_kses( $value, array_replace_recursive( wp_kses_allowed_html( 'post' ), array( 'a' => array( 'data-coupon' => true ) ) ) ); // phpcs:ignore PHPCompatibility.PHP.NewFunctions.array_replace_recursiveFound

				return;
			}

			return wp_kses( $value, array_replace_recursive( wp_kses_allowed_html( 'post' ), array( 'a' => array( 'data-coupon' => true ) ) ) ); // phpcs:ignore PHPCompatibility.PHP.NewFunctions.array_replace_recursiveFound
		}

		/**
		 * @param $product \WC_Product
		 * @param $qty
		 *
		 * @return array|string
		 */
		function you_saved_price( $product, $cart_item ) {
			if ( ! $product instanceof \WC_Product ) {
				return '';
			}

			$qty = $cart_item['cart_item']['quantity'];
			$percentage       = '';
			$amount           = 0;
			$tax_display_mode = get_option( 'woocommerce_tax_display_shop' );

			$wc_product_regular_price = apply_filters( 'fkcart_wc_you_saved_price', $product->get_regular_price(), $product );

			$product_regular_price = 'incl' === $tax_display_mode ? wc_get_price_including_tax( $product, [ 'price' => $wc_product_regular_price ] ) : wc_get_price_excluding_tax( $product, [ 'price' => $wc_product_regular_price ] );
			$product_price         = 'incl' === $tax_display_mode ? wc_get_price_including_tax( $product, [ 'price' => $product->get_price() ] ) : wc_get_price_excluding_tax( $product, [ 'price' => $product->get_price() ] );


			$product_price = apply_filters( 'fkcart_wc_product_price', $product_price, $product );

			$you_saved_prices = apply_filters( 'fkcart_you_saved_prices', ['regular_price' => $product_regular_price, 'sale_price' => $product_price], $cart_item['cart_item'] );

			if(empty($you_saved_prices)){
				return '';
			}

			$product_regular_price = $you_saved_prices['regular_price'];
			$product_price = $you_saved_prices['sale_price'];
			if ( $product_regular_price > 0 && ! empty( $product_price ) && $product_price != $product_regular_price ) {
				$amount     = ( $product_regular_price - $product_price );
				$percentage = ( $amount * 100 ) / $product_regular_price;
			}

			if ( empty( $percentage ) || $percentage <= 0 ) {
				return '';
			}

			$round_value = apply_filters( 'fkcart_cart_item_saving_value_round', false, $percentage );
			if ( true === $round_value ) {
				$percentage = round( $percentage );
				$amount     = round( $amount * $qty );
			} else {
				$percentage = number_format( $percentage );
				$amount     = number_format( $amount * $qty, wc_get_price_decimals() );
			}

			$percentage = str_replace( '.00', '', $percentage );

			return [ 'percentage' => $percentage, 'amount' => $amount ];
		}

		/**
		 * Set object when item is added to cart
		 *
		 * @return void
		 */
		public function add_to_cart_trigger() {

			$this->add_to_cart_trigger = true;
			Ajax::get_instance()->set_fkcart_cookies();
		}


		/**
		 * Get upsell products
		 *
		 * @return array
		 */
		public function get_upsell_products() {
			if ( class_exists( '\FKCart\Pro\Plugin' ) ) {
				if ( fkcart_is_preview() ) {
					return $this->get_dummy_upsell_products();
				}
				try {
					return \FKCart\Pro\Upsells::getInstance()->get_upsell_products();

				} catch ( \Error|\Exception $error ) {
					return [];
				}


			}

			return [];
		}

		public function get_special_addon_products() {
			if ( class_exists( '\FKCart\Pro\Plugin' ) ) {
				try {
					return \FKCart\Pro\Special_Add_On::get_settings();

				} catch ( \Error|\Exception $error ) {
					return [];
				}

			}

			return [];
		}

		/**
		 * Get dummy products
		 *
		 * @return array
		 */
		public function get_dummy_upsell_products() {
			$products = fkcart_get_dummy_products();
			$items[]  = $this->get_dummy_preview_item( $products[0] );
			$items[]  = $this->get_dummy_preview_item( $products[3] );
			$items[]  = $this->get_dummy_preview_item( $products[4] );

			return $items;
		}

		/**
		 * Get dummy product sale price
		 *
		 * @param $product
		 *
		 * @return mixed
		 */
		public function get_dummy_product_price( $product ) {
			return ( isset( $product['sale_price'] ) && ( $product['price'] - $product['sale_price'] ) > 0 ) ? $product['sale_price'] : $product['price'];
		}

		/**
		 * Append in menu
		 *
		 * @param $menu_items
		 * @param $args
		 *
		 * @return mixed|string
		 */
		public function append_cart_link( $menu_items, $args ) {
			if ( false === Data::is_cart_enabled( 'shortcode' ) || empty( $args->menu ) ) {
				return $menu_items;
			}

			$saved_menu = Data::get_value( 'cart_append_menu' );
			if ( empty( $saved_menu ) ) {
				return $menu_items;
			}

			/**
			 * check if $args->menu is menu slug instead of Term Object then convert slug  to Term Object
			 */
			if ( is_string( $args->menu ) ) {
				$menu_object = get_term_by( 'slug', $args->menu, 'nav_menu' );
				if ( $menu_object instanceof \WP_Term ) {
					$args->menu = $menu_object;
					unset( $menu_object );
				}
			}

			$current_menu_id = ( $args->menu instanceof \WP_Term ) ? $args->menu->term_id : ( is_numeric( $args->menu ) ? $args->menu : false );
			$current_menu_id = intval( $current_menu_id );
			$saved_menu      = array_map( 'intval', (array) $saved_menu );
			if ( false === $current_menu_id || ! in_array( $current_menu_id, $saved_menu ) ) {
				return $menu_items;
			}

			$menu_items .= "<li class='menu-item fkcart-custom-menu-link'>" . $this->get_mini_cart_toggler() . "</li>";

			return $menu_items;
		}

		/**
		 * Get HTML of Funnelkit Apple Pay & Google Pay Smart buttons
		 *
		 * @return false|string|void
		 */
		public function get_smart_buttons() {
			try {
				if ( fkcart_is_preview() ) {
					return;
				}

				if ( ! Data::is_smart_button_enabled() ) {
					return;
				}
				fkcart_get_template_part( 'cart/smart-buttons', '' );
			} catch ( \Error|\Exception $error ) {
				wc_get_logger()->log( 'error', $error->getMessage(), [ 'source' => 'funnelkit-cart' ] );
			}
		}

		/**
		 * Enqueue Smart button javascript button if smart button enabled inside the cart
		 * @return bool
		 */
		public function enqueue_smart_button_javascript() {
			return Data::is_smart_button_enabled();
		}

		public function handleCartFlickering() {
			if ( ! isset( $_REQUEST['fkcart-preview'] ) ) {
				return;
			}
			?>
            <script>
                window.addEventListener('DOMContentLoaded', function () {
                    if (typeof wc_cart_fragments_params === 'undefined') {
                        return false;
                    }
                    var items = document.getElementsByClassName('fkcart-mini-open');
                    if (items.length > 0 && 'sessionStorage' in window && window.sessionStorage !== null) {
                        sessionStorage.removeItem(wc_cart_fragments_params.fragment_name);
                    }
                });

            </script>
			<?php
		}

		/**
		 * Append query currency & language parameter
		 *
		 * @return array
		 */
		public function append_ajax_parameter( $query ) {
			if ( isset( $_GET['currency'] ) ) {
				$query['currency'] = sanitize_text_field( $_GET['currency'] );
			}

			/** WPML Query parameter setting enabled */
			if ( isset( $_GET['lang'] ) ) {
				$query['lang'] = sanitize_text_field( $_GET['lang'] );
			}

			/** Polylang */
			if ( function_exists( 'pll_current_language' ) ) {
				$query['lang'] = pll_current_language();
			}

			/** Weglot */
			if ( function_exists( 'weglot_get_current_language' ) ) {
				$query['lang'] = weglot_get_current_language();
			}

			return $query;
		}

		/**
		 * Disable redirect to cart after adding product
		 *
		 * @param $status
		 *
		 * @return string
		 */
		public function disable_woocommerce_cart_redirect_after_add( $status ) {
			if ( 'no' === $status ) {
				return $status;
			}

			return ( false === Data::is_cart_enabled( 'all' ) ) ? $status : 'no';
		}

		/**
		 * Enable ajax on add product to cart
		 *
		 * @param $status
		 *
		 * @return mixed|string
		 */
		public function enable_woocommerce_enable_ajax_add_to_cart( $status ) {
			if ( 'yes' === $status || false === Data::is_cart_enabled( 'all' ) ) {
				return $status;
			}
			$force_ajax = Data::get_value( 'ajax_add_to_cart' );
			if ( ( 1 === intval( $force_ajax ) || true === $force_ajax || 'true' === strval( $force_ajax ) ) ) {
				return 'yes';
			}

			return $status;
		}

		/**
		 * Do not run ajax on add to cart for Excluded product types
		 *
		 * @return bool
		 */
		public function is_excluded_product_types() {
			$product_id = get_the_ID();
			if ( empty( $product_id ) ) {
				return false;
			}
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product ) {
				return false;
			}
			$excluded_product_type = apply_filters( 'fkcart_ajax_add_to_cart_excluded_product_types', [ 'grouped', 'external' ] );
			if ( empty( $excluded_product_type ) ) {
				return false;
			}

			return in_array( $product->get_type(), $excluded_product_type, true );
		}

		/**
		 * Display Strike Price if active
		 *
		 * @return void
		 */
		public function attach_action() {
			$enable_strike_price = Data::get_strike_through_discounted_price();
			if ( true === wc_string_to_bool( $enable_strike_price ) ) {
				add_filter( 'woocommerce_cart_item_subtotal', [ $this, 'display_strike_price' ], 999, 2 );
			}
		}

		/**
		 * Return Stripe price HTML
		 *
		 * @param $price
		 * @param $cart_item
		 *
		 * @return string
		 */
		public function display_strike_price( $price, $cart_item ) {
			try {
				$product            = $cart_item['data'];
				$qty                = $cart_item['quantity'];
				$regular_price      = $product->get_regular_price();
				$other_product_type = apply_filters( 'fkcart_disable_strike_price_product_type', [
					'booking',
					'gift-card',
					'variable',
					'bundle',
					'yith_bundle',
					'variable-subscription',
					'subscription',
					'subscription_variation',
				] );

				if ( '' == $regular_price ) {
					return $price;
				}

				// Do not support strike price for some product type in cart
				if ( in_array( $product->get_type(), $other_product_type ) ) {
					return $price;
				}

				/**
				 * Check Order meta with Buy Once or Subscribe for WooCommerce Subscriptions by eCommerce Tools
				 */
				if ( defined( 'BOS_PLUGIN_PATH' ) ) {
					$bos4w_plans = $product->get_meta( '_subscription_plan_data' );
					if ( $bos4w_plans ) {
						return $price;
					}
				}

				if ( ( isset( $cart_item['wcsatt_data'] ) && ! empty( $cart_item['wcsatt_data']['active_subscription_scheme'] ) ) ) {
					/** @var $product \WC_Product */
					$regular = $regular_price * $qty;

					if ( $product->is_on_sale() ) {
						$price_html = wc_format_sale_price( $regular, $price );
					} else {
						$price_html = $price;
					}
				} else {
					/** @var $product \WC_Product */
					$regular = $regular_price * $qty;
					$price   = $this->get_product_subtotal( $product, $qty );

					if ( $regular > 0 && ( round( $price, 2 ) !== round( $regular, 2 ) ) ) {
						if ( $price > $regular ) {
							$price_html = wc_price( $price );
						} else {
							$price_html = wc_format_sale_price( $regular, $price );
						}
					} else {
						$price_html = wc_price( $price );
					}
				}

				return $price_html;
			} catch ( \Error|\Exception $error ) {
				return $price;
			}
		}

		/**
		 * Get product subtotal based on tax display mode and quantity
		 *
		 * @param $product
		 * @param $quantity
		 *
		 * @return float|string
		 */
		public function get_product_subtotal( $product, $quantity = 1 ) {
			$price = $product->get_price();
			if ( ! $product->is_taxable() ) {
				return (float) $price * (float) $quantity;
			}

			if ( WC()->cart->display_prices_including_tax() ) {
				return wc_get_price_including_tax( $product, array( 'qty' => $quantity ) );
			}

			return wc_get_price_excluding_tax( $product, array( 'qty' => $quantity ) );
		}

		/**
		 * Register Shortcode
		 *
		 * @return void
		 */
		public function register_shortcode() {
			add_shortcode( 'fk_cart_menu', [ $this, 'mini_cart_shortcode_cb' ] );
		}

		public function fkcart_cart_item_saving_value_round( $round_value ) {
			// Check if WooCommerce is active
			if ( ! function_exists( 'get_woocommerce_currency' ) ) {
				return $round_value;
			}

			// Get current currency
			$currency = get_woocommerce_currency();

			// Check if currency is Vietnamese Dong or Hungarian Forint
			if ( in_array( $currency, [ 'VND', 'HUF' ] ) ) {
				return true;
			}

			// Return original value for other currencies
			return $round_value;
		}

		/**
		 * Unset cookies on order received page or offer page via PHP
		 *
		 * @return void
		 */
		public function unset_cookies() {
			if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
				$this->unset_php_cookies();
			}

			if ( ! function_exists( 'WFOCU_Core' ) || ! class_exists( '\WFOCU_Offers' ) || is_null( WFOCU_Core()->offers ) ) {
				return;
			}

			global $post;
			$maybe_offer = WFOCU_Core()->offers->get_offer_from_post( $post );
			if ( $maybe_offer ) {
				$this->unset_php_cookies();
			}
		}

		/**
		 * Unset PHP cookies
		 *
		 * @return void
		 */
		protected function unset_php_cookies() {
			if ( headers_sent() ) {
				return;
			}

			$cookies_to_destroy = [
				'fkcart_cart_total',
				'fkcart_cart_qty'
			];
			foreach ( $cookies_to_destroy as $cookie_name ) {
				if ( isset( $_COOKIE[ $cookie_name ] ) ) {
					// Remove from $_COOKIE array
					unset( $_COOKIE[ $cookie_name ] );

					// Destroy cookie from root path
					setcookie( $cookie_name, '', time() - 3600, '/' );

					// Also destroy for current path
					setcookie( $cookie_name, '', time() - 3600, $_SERVER['REQUEST_URI'] );

					// Destroy for all possible subdomains
					setcookie( $cookie_name, '', time() - 3600, '/', $_SERVER['HTTP_HOST'] );
				}
			}
		}

		/**
		 * Unset cookies via JavaScript and session storage of fkcart and wc
		 *
		 * @return void
		 */
		public function unset_js_cookies() {
			?>
            <script type="text/javascript">
                (function () {
                    function unset_storage() {
                        try {
                            if (typeof sessionStorage !== 'undefined') {
                                for (let i = sessionStorage.length - 1; i >= 0; i--) {
                                    const key = sessionStorage.key(i);
                                    if (key.startsWith('fkcart_') || key.startsWith('wc_')) {
                                        sessionStorage.removeItem(key);
                                    }
                                }
                            }
                        } catch (e) {

                        }
                    }

                    // Run when the DOM is fully loaded
                    document.addEventListener('DOMContentLoaded', function () {
                        // Unset Storage
                        unset_storage();
                        // Array of cookies to destroy
                        var cookiesToDestroy = [
                            'fkcart_cart_total',
                            'fkcart_cart_qty'
                        ];

                        // Get all domains to delete from
                        var domain = window.location.hostname;
                        var domainParts = domain.split('.');
                        var domains = [];

                        // Add current domain and all parent domains
                        for (var i = 0; i < domainParts.length - 1; i++) {
                            domains.push('.' + domainParts.slice(i).join('.'));
                        }
                        domains.push(''); // Add empty string for no domain specification

                        // Get all paths to delete from
                        var paths = ['/', window.location.pathname];

                        // Loop through each cookie
                        cookiesToDestroy.forEach(function (cookieName) {
                            // Delete for all domains and paths combinations
                            domains.forEach(function (domainStr) {
                                paths.forEach(function (pathStr) {
                                    // Set expired date in the past with empty value
                                    document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=' + pathStr +
                                        (domainStr ? '; domain=' + domainStr : '');
                                });
                            });
                        });
                    });
                })();
            </script>
			<?php
		}
	}
}
