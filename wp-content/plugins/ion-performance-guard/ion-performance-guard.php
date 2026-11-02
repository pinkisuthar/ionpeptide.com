<?php
/**
 * Plugin Name: Ion Performance Guard
 * Description: Conservative front-end performance cleanup for IonPeptide.com with safe defaults, page-specific asset unloading, responsive hero delivery, accessibility repairs, and optional advanced optimizations.
 * Version: 1.1.1
 * Author: Intangible Treasures LLC
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: ion-performance-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ION_Performance_Guard {
	const VERSION        = '1.1.1';
	const OPTION_KEY     = 'ionpg_settings';
	const DIM_KEY        = 'ionpg_image_dimensions';
	const VERSION_OPTION = 'ionpg_version';

	private static $settings = null;
	private static $buffer_started = false;
	private static $dimension_cache = null;
	private static $dimension_cache_changed = false;
	private static $page_uses_elementor = null;

	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		self::run_migrations();

		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'plugin_action_links' ) );

		if ( self::is_bypassed() ) {
			add_action( 'send_headers', array( __CLASS__, 'send_bypass_header' ) );
			return;
		}

		add_action( 'init', array( __CLASS__, 'safe_core_cleanup' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'clean_assets' ), PHP_INT_MAX );
		add_action( 'wp_print_styles', array( __CLASS__, 'clean_assets' ), PHP_INT_MAX );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'clean_assets' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'clean_assets' ), 1 );

		add_action( 'wp_head', array( __CLASS__, 'print_request_savers' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'print_hero_preloads' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'print_frontend_fixes' ), 2 );
		add_filter( 'the_content', array( __CLASS__, 'filter_front_page_content' ), PHP_INT_MAX );
		add_action( 'wp_footer', array( __CLASS__, 'print_accessibility_runtime' ), PHP_INT_MAX );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start_buffer' ), 0 );
		add_action( 'send_headers', array( __CLASS__, 'send_headers' ) );

		add_filter( 'script_loader_tag', array( __CLASS__, 'protect_critical_scripts' ), 10, 3 );
		add_filter( 'style_loader_tag', array( __CLASS__, 'remove_broken_font_tags' ), 10, 4 );
	}

	public static function defaults() {
		return array(
			'safe_core_cleanup'             => 1,
			'global_context_cleanup'        => 1,
			'remove_customer_dashicons'     => 1,
			'home_asset_cleanup'            => 1,
			'strip_elementor_home'          => 1,
			'strip_elementor_unused'        => 1,
			'strip_broken_elementor_fonts'  => 1,
			'responsive_heroes'             => 1,
			'preload_active_hero'           => 1,
			'accessibility_fixes'           => 1,
			'image_dimensions'              => 1,
			'protect_critical_scripts'      => 1,
			'lazy_product_catalog'          => 1,
			'skip_empty_cart_rest'          => 1,
			'delay_marketing_interaction'   => 0,
			'remove_sidecart_confetti'      => 1,
			'strip_woocommerce_home'        => 0,
			'strip_discount_assets_home'    => 0,
			'unload_omnisend_home'          => 0,
			'disable_empty_cart_fragments'  => 1,
			'remove_jquery_migrate'         => 0,
			'remove_fontawesome_unused'     => 0,
			'edge_cache_headers'            => 0,
			'edge_cache_ttl'                => 3600,
		);
	}


	private static function run_migrations() {
		$installed_version = (string) get_option( self::VERSION_OPTION, '0.0.0' );
		if ( ! version_compare( $installed_version, self::VERSION, '<' ) ) {
			return;
		}

		$settings = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		/*
		 * v1.0.2 restores responsive hero delivery after fixing the visible
		 * literal "\\n\\n" text that v1.0.0 accidentally printed after the
		 * two preload tags. This migration runs once and does not override a
		 * later manual setting change.
		 */
		if ( version_compare( $installed_version, '1.0.2', '<' ) ) {
			$settings['responsive_heroes'] = 1;
		}

		if ( version_compare( $installed_version, '1.1.0', '<' ) ) {
			$settings['global_context_cleanup']       = 1;
			$settings['remove_customer_dashicons']    = 1;
			$settings['strip_elementor_unused']       = ! empty( $settings['strip_elementor_home'] ) ? 1 : 0;
			$settings['lazy_product_catalog']         = 1;
			$settings['skip_empty_cart_rest']         = 1;
			$settings['remove_sidecart_confetti']     = 1;
			$settings['disable_empty_cart_fragments'] = 1;
		}

		update_option( self::OPTION_KEY, wp_parse_args( $settings, self::defaults() ), false );
		update_option( self::VERSION_OPTION, self::VERSION, false );
		self::$settings = null;
	}

	public static function activate() {
		$current = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		update_option( self::OPTION_KEY, wp_parse_args( $current, self::defaults() ), false );
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	private static function settings() {
		if ( null === self::$settings ) {
			$stored = get_option( self::OPTION_KEY, array() );
			self::$settings = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return self::$settings;
	}

	private static function enabled( $key ) {
		$settings = self::settings();
		return ! empty( $settings[ $key ] );
	}

	private static function is_bypassed() {
		if ( defined( 'IONPG_BYPASS' ) && IONPG_BYPASS ) {
			return true;
		}
		return isset( $_GET['ionpg_bypass'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['ionpg_bypass'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function send_bypass_header() {
		if ( ! headers_sent() ) {
			header( 'X-Ion-Performance-Guard: bypassed' );
		}
	}

	public static function safe_core_cleanup() {
		if ( ! self::enabled( 'safe_core_cleanup' ) || is_admin() ) {
			return;
		}

		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'emoji_svg_url', '__return_false' );

		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
	}

	private static function is_front_page_request() {
		return ! is_admin() && ! wp_doing_ajax() && ( is_front_page() || is_home() );
	}

	private static function is_checkout_request() {
		return function_exists( 'is_checkout' ) && is_checkout();
	}

	private static function is_cart_request() {
		return function_exists( 'is_cart' ) && is_cart();
	}

	/**
	 * Cart and checkout are stateful transaction screens. Do not proxy, cache,
	 * reconstruct, or coalesce Store API responses on these requests.
	 */
	private static function is_transaction_request() {
		if ( self::is_cart_request() || self::is_checkout_request() ) {
			return true;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		foreach ( array( '/order-pay/', '/order-received/' ) as $needle ) {
			if ( false !== strpos( $uri, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	private static function is_account_request() {
		return function_exists( 'is_account_page' ) && is_account_page();
	}

	private static function is_product_display_request() {
		return ( function_exists( 'is_product' ) && is_product() ) || self::is_product_archive_request();
	}

	private static function is_product_archive_request() {
		return ( function_exists( 'is_shop' ) && is_shop() ) ||
			( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() );
	}

	private static function is_affiliate_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return false !== strpos( $uri, 'affiliate-dashboard' ) ||
			false !== strpos( $uri, 'affiliate' ) ||
			false !== strpos( $uri, 'commission' );
	}

	private static function page_uses_elementor() {
		if ( null !== self::$page_uses_elementor ) {
			return self::$page_uses_elementor;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			self::$page_uses_elementor = false;
			return false;
		}
		if ( 'elementor_library' === get_post_type( $post_id ) ) {
			self::$page_uses_elementor = true;
			return true;
		}
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			self::$page_uses_elementor = true;
			return true;
		}
		$data = get_post_meta( $post_id, '_elementor_data', true );
		self::$page_uses_elementor = is_string( $data ) && '' !== trim( $data ) && '[]' !== trim( $data );
		return self::$page_uses_elementor;
	}

	public static function clean_assets() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( self::enabled( 'safe_core_cleanup' ) ) {
			wp_dequeue_script( 'wp-embed' );
		}

		if ( self::enabled( 'remove_customer_dashicons' ) ) {
			if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
				wp_dequeue_style( 'dashicons' );
			}
		}

		if ( self::enabled( 'strip_broken_elementor_fonts' ) ) {
			self::dequeue_styles(
				array(
					'elementor-gf-local-roboto',
					'elementor-gf-local-robotoslab',
					'elementor-gf-local-inter',
					'elementor-gf-local-poppins',
				)
			);
		}

		if ( self::enabled( 'global_context_cleanup' ) ) {
			self::contextual_asset_cleanup();
		}

		if ( self::enabled( 'strip_elementor_unused' ) && ! self::page_uses_elementor() ) {
			self::strip_elementor_home_assets();
		}

		if ( self::enabled( 'remove_sidecart_confetti' ) ) {
			self::dequeue_scripts( array( 'xoo-confetti' ) );
		}

		if (
			self::enabled( 'disable_empty_cart_fragments' ) &&
			self::request_has_no_cart_cookie() &&
			! self::is_cart_request() &&
			! self::is_checkout_request()
		) {
			self::dequeue_scripts( array( 'wc-cart-fragments' ) );
		}

		if ( self::enabled( 'remove_jquery_migrate' ) ) {
			self::dequeue_scripts( array( 'jquery-migrate' ) );
		}

		if ( self::enabled( 'remove_fontawesome_unused' ) ) {
			self::dequeue_styles( array( 'font-awesome', 'fontawesome', 'font-awesome-5-all' ) );
		}

		if ( ! self::is_front_page_request() ) {
			return;
		}

		if ( self::enabled( 'home_asset_cleanup' ) ) {
			self::dequeue_styles(
				array(
					'acfwf-wc-cart-block-integration',
					'acfwf-wc-checkout-block-integration',
					'omnisend-woocommerce-checkout-block-checkout-newsletter-subscription-block',
					'wc-nmi-blocks-checkout-style',
					'wc-authnet-blocks-checkout-style',
					'wc-blocks-style',
					'select2',
					'yith-wcaf',
					'wcwl_frontend',
					'woo-variation-swatches',
					'yith-infs-style',
				)
			);

			self::dequeue_scripts(
				array(
					'afpgbf_front_js',
					'fingrid-banks',
					'yith-wcaf-shortcodes',
					'wc-country-select',
					'selectWoo',
					'jquery-ui-datepicker',
					'wcwl_frontend',
					'wcwl_frontend_account',
					'yith-infinitescroll',
					'yith-infs',
					'woo-variation-swatches',
				)
			);
		}

		if ( self::enabled( 'strip_elementor_home' ) ) {
			self::strip_elementor_home_assets();
		}

		if ( self::enabled( 'strip_woocommerce_home' ) ) {
			self::dequeue_styles(
				array(
					'woocommerce-layout',
					'woocommerce-smallscreen',
					'woocommerce-general',
					'woocommerce-blocktheme',
				)
			);
		}

		if ( self::enabled( 'strip_discount_assets_home' ) ) {
			self::dequeue_styles( array( 'woo_discount_pro_style' ) );
			self::dequeue_scripts( array( 'awdr-main', 'awdr-dynamic-price', 'woo_discount_pro_script' ) );
		}

		if ( self::enabled( 'unload_omnisend_home' ) ) {
			self::dequeue_scripts( array( 'woocommerce.js', 'omnisend-front-script.js' ) );
		}
	}

	private static function contextual_asset_cleanup() {
		if ( ! self::is_checkout_request() ) {
			self::dequeue_styles(
				array(
					'acfwf-wc-checkout-block-integration',
					'omnisend-woocommerce-checkout-block-checkout-newsletter-subscription-block',
					'wc-nmi-blocks-checkout-style',
					'wc-authnet-blocks-checkout-style',
				)
			);
			self::dequeue_scripts( array( 'fingrid-banks' ) );
		}

		if ( ! self::is_cart_request() ) {
			self::dequeue_styles( array( 'acfwf-wc-cart-block-integration' ) );
		}

		if ( ! self::is_checkout_request() && ! self::is_account_request() ) {
			self::dequeue_styles( array( 'select2' ) );
			self::dequeue_scripts( array( 'wc-country-select', 'selectWoo', 'jquery-ui-datepicker' ) );
		}

		if ( ! self::is_affiliate_request() ) {
			self::dequeue_styles( array( 'yith-wcaf' ) );
			self::dequeue_scripts( array( 'afpgbf_front_js', 'yith-wcaf-shortcodes' ) );
		}

		if ( ! self::is_product_display_request() && ! self::is_account_request() ) {
			self::dequeue_styles( array( 'wcwl_frontend', 'woo-variation-swatches' ) );
			self::dequeue_scripts( array( 'wcwl_frontend', 'wcwl_frontend_account', 'woo-variation-swatches' ) );
		}

		if ( ! self::is_product_archive_request() ) {
			self::dequeue_styles( array( 'yith-infs-style' ) );
			self::dequeue_scripts( array( 'yith-infinitescroll', 'yith-infs' ) );
		}
	}

	private static function strip_elementor_home_assets() {
		$styles = array(
			'hfe-widgets-style',
			'hfe-woo-product-grid',
			'hfe-style',
			'elementor-frontend',
			'widget-image',
			'widget-icon-box',
			'widget-icon-list',
			'widget-heading',
			'widget-image-box',
			'hfe-elementor-icons',
			'hfe-icons-list',
			'hfe-social-icons',
			'hfe-social-share-icons-brands',
			'hfe-social-share-icons-fontawesome',
			'hfe-nav-menu-icons',
		);
		self::dequeue_styles( $styles );

		$wp_styles = wp_styles();
		if ( $wp_styles && ! empty( $wp_styles->queue ) ) {
			foreach ( (array) $wp_styles->queue as $handle ) {
				if ( 0 === strpos( $handle, 'elementor-post-' ) || preg_match( '/^widget-(image|icon-box|icon-list|heading|image-box|social-icons)$/', $handle ) ) {
					wp_dequeue_style( $handle );
				}
			}
		}

		self::dequeue_scripts(
			array(
				'elementor-webpack-runtime',
				'elementor-frontend-modules',
				'elementor-frontend',
				'hfe-woo-product-grid',
			)
		);
	}

	private static function dequeue_styles( $handles ) {
		foreach ( $handles as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	private static function dequeue_scripts( $handles ) {
		foreach ( $handles as $handle ) {
			wp_dequeue_script( $handle );
		}
	}

	private static function request_has_no_cart_cookie() {
		$cart_cookies = array(
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'wp_woocommerce_session_',
		);
		foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
			foreach ( $cart_cookies as $needle ) {
				if ( false !== strpos( (string) $cookie_name, $needle ) ) {
					return false;
				}
			}
		}
		return true;
	}

	public static function print_request_savers() {
		if (
			is_admin() ||
			wp_doing_ajax() ||
			self::is_transaction_request() ||
			( ! self::enabled( 'lazy_product_catalog' ) && ! self::enabled( 'skip_empty_cart_rest' ) )
		) {
			return;
		}

		$lazy_catalog = self::enabled( 'lazy_product_catalog' );
		$skip_cart    = self::enabled( 'skip_empty_cart_rest' ) && self::request_has_no_cart_cookie();
		?>
<script data-cfasync="false" id="ionpg-request-savers">
(function(){
  "use strict";
  if (typeof window.fetch !== "function") { return; }

  var nativeFetch = window.fetch.bind(window);
  var lazyCatalog = <?php echo $lazy_catalog ? 'true' : 'false'; ?>;
  var skipEmptyCart = <?php echo $skip_cart ? 'true' : 'false'; ?>;
  var catalogUnlocked = false;
  var pendingCatalog = [];
  var cartInflight = null;
  var cartCache = null;
  var cartCacheMs = 1800;
  var catalogCacheMs = 10 * 60 * 1000;

  function toUrl(input) {
    try {
      if (typeof input === "string") { return new URL(input, window.location.href); }
      if (input && input.url) { return new URL(input.url, window.location.href); }
    } catch (error) {}
    return null;
  }

  function methodIsGet(input, init) {
    var method = init && init.method ? init.method : (input && input.method ? input.method : "GET");
    return String(method || "GET").toUpperCase() === "GET";
  }

  function hasCartCookie() {
    return /(?:^|;\s*)(?:woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_)/i.test(document.cookie || "");
  }

  function isCatalogRequest(url) {
    if (!url || url.origin !== window.location.origin) { return false; }
    if (url.pathname.replace(/\/+$/, "") !== "/wp-json/wc/store/v1/products") { return false; }
    return url.searchParams.get("per_page") === "100" && url.searchParams.has("ion_search_ts");
  }

  function isCartRequest(url) {
    return !!url &&
      url.origin === window.location.origin &&
      url.pathname.replace(/\/+$/, "") === "/wp-json/wc/store/v1/cart";
  }

  function headersToRecord(headers) {
    var record = {};
    if (!headers || typeof headers.forEach !== "function") {
      return {"Content-Type": "application/json"};
    }
    headers.forEach(function(value, key){
      record[key] = value;
    });
    if (!record["content-type"] && !record["Content-Type"]) {
      record["Content-Type"] = "application/json";
    }
    return record;
  }

  function responseFromRecord(record) {
    if (!record || typeof window.Response !== "function") { return null; }
    return new Response(record.body || "", {
      status: record.status || 200,
      statusText: record.statusText || "",
      headers: record.headers || {"Content-Type": "application/json"}
    });
  }

  function currentCartKey() {
    var match = String(document.cookie || "").match(/(?:^|;\s*)woocommerce_cart_hash=([^;]+)/i);
    return match ? decodeURIComponent(match[1]) : "empty";
  }

  function fetchCartCoalesced(input, init) {
    var key = currentCartKey();
    var now = Date.now();

    if (cartCache && cartCache.key === key && now - cartCache.time < cartCacheMs) {
      return Promise.resolve(responseFromRecord(cartCache.record));
    }

    if (cartInflight && cartInflight.key === key) {
      return cartInflight.promise.then(responseFromRecord);
    }

    var promise = nativeFetch(input, init).then(function(response){
      if (!response || typeof response.clone !== "function") {
        return {
          status: response ? response.status : 200,
          statusText: response ? response.statusText : "",
          body: "",
          headers: {"Content-Type":"application/json"}
        };
      }
      var status = response.status;
      var statusText = response.statusText || "";
      var headers = headersToRecord(response.headers);
      return response.clone().text().then(function(body){
        return {
          status: status,
          statusText: statusText,
          body: body,
          headers: headers
        };
      });
    }).then(function(record){
      cartCache = {key: key, time: Date.now(), record: record};
      return record;
    });

    cartInflight = {key: key, promise: promise};
    promise.then(function(){ cartInflight = null; }, function(){ cartInflight = null; });
    return promise.then(responseFromRecord);
  }

  function catalogStorageKey(url) {
    var page = url.searchParams.get("page") || "1";
    var access = window.IONPriorOrderAccess && window.IONPriorOrderAccess.eligible ? "eligible" : "locked";
    return "ionpg_catalog_" + access + "_" + page;
  }

  function getCatalogRecord(url) {
    try {
      var raw = window.sessionStorage.getItem(catalogStorageKey(url));
      var parsed = raw ? JSON.parse(raw) : null;
      if (!parsed || !parsed.time || Date.now() - parsed.time > catalogCacheMs || !parsed.record) { return null; }
      return parsed.record;
    } catch (error) {
      return null;
    }
  }

  function rememberCatalogRecord(url, response) {
    if (!response || !response.ok || typeof response.clone !== "function") { return; }
    var totalPages = response.headers && response.headers.get ? (response.headers.get("X-WP-TotalPages") || "") : "";
    response.clone().text().then(function(body){
      if (!body || body.length > 1800000) { return; }
      try {
        window.sessionStorage.setItem(catalogStorageKey(url), JSON.stringify({
          time: Date.now(),
          record: {
            status: response.status,
            body: body,
            headers: {"Content-Type":"application/json", "X-WP-TotalPages": totalPages}
          }
        }));
      } catch (error) {}
    });
  }

  function unlockCatalog() {
    if (catalogUnlocked) { return; }
    catalogUnlocked = true;
    var queue = pendingCatalog.slice();
    pendingCatalog.length = 0;
    queue.forEach(function(run){ run(); });
  }

  function maybeUnlock(event) {
    var target = event.target;
    if (!target || !target.closest) { return; }
    if (target.closest("#ion-float-products, #ion-float-products-toggle, #ion-float-products-search, .ion-header-search-button, .ion-header-search-box, #ion-header-search-toggle, #ion-header-search-input")) {
      unlockCatalog();
    }
  }

  ["pointerdown", "touchstart", "focusin", "keydown"].forEach(function(type){
    document.addEventListener(type, maybeUnlock, true);
  });

  window.fetch = function(input, init) {
    var url = toUrl(input);

    if (isCartRequest(url) && methodIsGet(input, init) && typeof window.Response === "function") {
      if (skipEmptyCart && !hasCartCookie()) {
        var emptyCart = {
          items: [],
          items_count: 0,
          totals: {
            total_price: "0",
            currency_code: "USD",
            currency_minor_unit: 2,
            currency_prefix: "$",
            currency_suffix: ""
          }
        };
        return Promise.resolve(new Response(JSON.stringify(emptyCart), {
          status: 200,
          headers: {"Content-Type": "application/json"}
        }));
      }
      return fetchCartCoalesced(input, init);
    }

    if (lazyCatalog && isCatalogRequest(url)) {
      var cachedCatalog = getCatalogRecord(url);
      if (cachedCatalog) {
        return Promise.resolve(responseFromRecord(cachedCatalog));
      }
      if (!catalogUnlocked) {
        return new Promise(function(resolve, reject){
          pendingCatalog.push(function(){
            nativeFetch(input, init).then(function(response){
              rememberCatalogRecord(url, response);
              resolve(response);
            }, reject);
          });
        });
      }
      return nativeFetch(input, init).then(function(response){
        rememberCatalogRecord(url, response);
        return response;
      });
    }

    return nativeFetch(input, init);
  };
})();
</script>
		<?php
	}

	public static function protect_critical_scripts( $tag, $handle, $src ) {
		if ( ! self::enabled( 'protect_critical_scripts' ) || false !== strpos( $tag, 'data-cfasync=' ) ) {
			return $tag;
		}

		$critical = array(
			'jquery-core',
			'jquery-migrate',
			'wc-jquery-blockui',
			'wc-add-to-cart',
			'wc-js-cookie',
			'woocommerce',
			'wc-cart-fragments',
			'brw-free-shipping-progress',
			'xoo-wsc-main-js',
		);

		$is_critical = in_array( $handle, $critical, true ) ||
			0 === strpos( $handle, 'ion-' ) ||
			0 === strpos( $handle, 'ion_' ) ||
			0 === strpos( $handle, 'brw-' ) ||
			false !== strpos( $handle, 'xoo-wsc' );

		if ( ! $is_critical ) {
			return $tag;
		}

		return preg_replace( '/<script\s+/i', '<script data-cfasync="false" ', $tag, 1 );
	}

	public static function remove_broken_font_tags( $html, $handle, $href, $media ) {
		if ( ! self::enabled( 'strip_broken_elementor_fonts' ) ) {
			return $html;
		}
		$broken = array(
			'elementor-gf-local-roboto',
			'elementor-gf-local-robotoslab',
			'elementor-gf-local-inter',
			'elementor-gf-local-poppins',
		);
		return in_array( $handle, $broken, true ) ? '' : $html;
	}

	public static function print_hero_preloads() {
		if ( ! self::enabled( 'preload_active_hero' ) || ! self::is_front_page_request() ) {
			return;
		}

		$heroes = array(
			array(
				'url'   => 'https://ionpeptide.com/wp-content/uploads/2026/07/ionheromobile2.webp',
				'media' => '(max-width: 767px)',
				'sizes' => '100vw',
			),
			array(
				'url'   => 'https://ionpeptide.com/wp-content/uploads/2026/07/ionherodesktop2.webp',
				'media' => '(min-width: 768px)',
				'sizes' => '(max-width: 1216px) calc(100vw - 36px), 1180px',
			),
		);

		foreach ( $heroes as $hero ) {
			$srcset = self::attachment_srcset( $hero['url'] );
			echo '<link rel="preload" as="image" href="' . esc_url( $hero['url'] ) . '" media="' . esc_attr( $hero['media'] ) . '" fetchpriority="high"';
			if ( $srcset ) {
				echo ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="' . esc_attr( $hero['sizes'] ) . '"';
			}
			echo '>' . "\n";
		}
	}

	public static function print_frontend_fixes() {
		if ( ! self::is_front_page_request() ) {
			return;
		}
		if ( self::enabled( 'accessibility_fixes' ) ) {
			echo '<style id="ionpg-accessibility-fixes">#ion-home-page .ion-how .ion-section-kicker{color:#004a99!important}</style>' . "\n";
		}
		if ( self::enabled( 'responsive_heroes' ) ) {
			echo '<style id="ionpg-picture-fixes">.ion-hero-picture{display:block!important;width:100%!important;max-width:none!important;margin:0!important;padding:0!important;border:0!important;background:transparent!important;font-size:0!important;line-height:0!important}.ion-hero-picture>img{display:block!important;width:100%!important;max-width:none!important;height:auto!important;margin:0!important;padding:0!important;border:0!important}</style>' . "\n";
		}
	}

	public static function filter_front_page_content( $content ) {
		if ( ! self::is_front_page_request() || ! is_main_query() || ! in_the_loop() || ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		if ( self::enabled( 'responsive_heroes' ) ) {
			$content = self::replace_hero_image_with_picture( $content, 'ion-hero-mobile-image', '(max-width: 767px)', '100vw' );
			$content = self::replace_hero_image_with_picture( $content, 'ion-hero-desktop-image', '(min-width: 768px)', '(max-width: 1216px) calc(100vw - 36px), 1180px' );
		}

		if ( self::enabled( 'image_dimensions' ) ) {
			$content = preg_replace_callback( '/<img\b[^>]*>/i', array( __CLASS__, 'add_image_dimensions' ), $content );
		}

		return $content;
	}

	public static function maybe_start_buffer() {
		if (
			self::$buffer_started ||
			! self::enabled( 'delay_marketing_interaction' ) ||
			is_admin() ||
			wp_doing_ajax() ||
			is_feed() ||
			is_robots() ||
			is_trackback()
		) {
			return;
		}
		self::$buffer_started = true;
		ob_start( array( __CLASS__, 'filter_front_page_html' ) );
	}

	public static function filter_front_page_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		return self::enabled( 'delay_marketing_interaction' ) ? self::delay_marketing_scripts( $html ) : $html;
	}

	private static function replace_hero_image_with_picture( $html, $class_name, $media, $sizes ) {
		$pattern = '/<img\b(?=[^>]*class=(?:"|\')[^"\']*\b' . preg_quote( $class_name, '/' ) . '\b[^"\']*(?:"|\'))[^>]*>/i';
		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $media, $sizes, $class_name ) {
				$tag = $matches[0];
				if ( ! preg_match( '/\bsrc=(?:"|\')([^"\']+)(?:"|\')/i', $tag, $src_match ) ) {
					return $tag;
				}
				$src = html_entity_decode( $src_match[1], ENT_QUOTES, 'UTF-8' );
				if ( 0 === strpos( $src, 'data:' ) ) {
					return $tag;
				}

				$srcset = self::attachment_srcset( $src );
				$source_value = $srcset ? $srcset : $src;
				$fallback = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
				$img = preg_replace( '/\bsrc=(?:"|\')[^"\']+(?:"|\')/i', 'src="' . esc_attr( $fallback ) . '"', $tag, 1 );
				$img = preg_replace( '/\s+srcset=(?:"|\')[^"\']*(?:"|\')/i', '', $img );
				$img = preg_replace( '/\s+sizes=(?:"|\')[^"\']*(?:"|\')/i', '', $img );

				return '<picture class="ion-hero-picture ion-hero-picture-' . esc_attr( sanitize_html_class( $class_name ) ) . '"><source media="' . esc_attr( $media ) . '" srcset="' . esc_attr( $source_value ) . '" sizes="' . esc_attr( $sizes ) . '">' . $img . '</picture>';
			},
			$html,
			1
		);
	}

	private static function attachment_srcset( $url ) {
		$clean_url = preg_replace( '/\?.*$/', '', $url );
		$cache_key = 'srcset_' . md5( $clean_url );
		$cached = wp_cache_get( $cache_key, 'ionpg' );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$attachment_id = attachment_url_to_postid( $clean_url );
		if ( ! $attachment_id ) {
			wp_cache_set( $cache_key, '', 'ionpg', DAY_IN_SECONDS );
			return '';
		}
		$srcset = wp_get_attachment_image_srcset( $attachment_id, 'full' );
		$srcset = is_string( $srcset ) ? $srcset : '';
		wp_cache_set( $cache_key, $srcset, 'ionpg', DAY_IN_SECONDS );
		return $srcset;
	}

	private static function repair_accessibility_markup( $html ) {
		$html = preg_replace_callback(
			'/<input\b(?=[^>]*\bid=(?:"|\')ion-header-search-toggle(?:"|\'))[^>]*>/i',
			function ( $matches ) {
				$tag = preg_replace( '/\s+aria-hidden=(?:"|\')true(?:"|\')/i', '', $matches[0] );
				if ( false === stripos( $tag, 'aria-label=' ) ) {
					$tag = preg_replace( '/<input\b/i', '<input aria-label="Toggle site search"', $tag, 1 );
				}
				return $tag;
			},
			$html
		);

		$html = preg_replace_callback(
			'/<input\b(?=[^>]*\bclass=(?:"|\')[^"\']*\bxoo-wsc-qty\b[^"\']*(?:"|\'))[^>]*>/i',
			function ( $matches ) {
				$tag = $matches[0];
				if ( false === stripos( $tag, 'aria-label=' ) ) {
					$tag = preg_replace( '/<input\b/i', '<input aria-label="Quantity"', $tag, 1 );
				}
				return $tag;
			},
			$html
		);

		$html = preg_replace_callback(
			'/<a\b(?=[^>]*\bclass=(?:"|\')[^"\']*\b(?:ion-hero-mobile-hotspot|ion-hero-hotspot)\b[^"\']*(?:"|\'))[^>]*>.*?<\/a>/is',
			function ( $matches ) {
				$anchor = $matches[0];
				if ( ! preg_match( '/^(<a\b[^>]*>)(.*)(<\/a>)$/is', $anchor, $parts ) ) {
					return $anchor;
				}
				$visible = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $parts[2] ) ) );
				if ( '' === $visible ) {
					return $anchor;
				}
				$open = preg_replace( '/\s+aria-label=(?:"|\')[^"\']*(?:"|\')/i', '', $parts[1] );
				$open = preg_replace( '/<a\b/i', '<a aria-label="' . esc_attr( $visible ) . '"', $open, 1 );
				return $open . $parts[2] . $parts[3];
			},
			$html
		);

		$html = preg_replace_callback(
			'/<a\b(?=[^>]*\bclass=(?:"|\')[^"\']*\bion-header-cart\b[^"\']*(?:"|\'))[^>]*>.*?<\/a>/is',
			function ( $matches ) {
				$anchor = $matches[0];
				$count = '0';
				$total = '';
				if ( preg_match( '/<span\b[^>]*class=(?:"|\')[^"\']*ion-header-cart-count[^"\']*(?:"|\')[^>]*>(.*?)<\/span>/is', $anchor, $count_match ) ) {
					$count = trim( wp_strip_all_tags( $count_match[1] ) );
				}
				if ( preg_match( '/<span\b[^>]*class=(?:"|\')[^"\']*ion-header-cart-total[^"\']*(?:"|\')[^>]*>(.*?)<\/span>/is', $anchor, $total_match ) ) {
					$total = trim( wp_strip_all_tags( $total_match[1] ) );
				}
				$label = 'Cart ' . ( '' !== $total ? $total . ' ' : '' ) . ( '' !== $count ? $count : '0' ) . ' items';
				$anchor = preg_replace( '/\s+aria-label=(?:"|\')[^"\']*(?:"|\')/i', '', $anchor, 1 );
				return preg_replace_callback( '/<a\b/i', function () use ( $label ) { return '<a aria-label="' . esc_attr( $label ) . '"'; }, $anchor, 1 );
			},
			$html,
			1
		);

		return $html;
	}

	private static function load_dimension_cache() {
		if ( null === self::$dimension_cache ) {
			$stored = get_option( self::DIM_KEY, array() );
			self::$dimension_cache = is_array( $stored ) ? $stored : array();
		}
	}

	public static function add_image_dimensions( $matches ) {
		$tag = $matches[0];
		if ( false !== stripos( $tag, ' width=' ) && false !== stripos( $tag, ' height=' ) ) {
			return $tag;
		}
		if ( ! preg_match( '/\bsrc=(?:"|\')([^"\']+)(?:"|\')/i', $tag, $src_match ) ) {
			return $tag;
		}
		$url = html_entity_decode( $src_match[1], ENT_QUOTES, 'UTF-8' );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return $tag;
		}

		$width = 0;
		$height = 0;
		$clean_url = preg_replace( '/\?.*$/', '', $url );

		if ( preg_match( '/-(\d+)x(\d+)\.(?:jpe?g|png|gif|webp|avif)$/i', $clean_url, $size_match ) ) {
			$width = absint( $size_match[1] );
			$height = absint( $size_match[2] );
		} else {
			$known = array(
				'ionherodesktop2.webp' => array( 1672, 941 ),
				'ionheromobile2.webp'  => array( 928, 1680 ),
			);
			$filename = basename( wp_parse_url( $clean_url, PHP_URL_PATH ) );
			if ( isset( $known[ $filename ] ) ) {
				$width = $known[ $filename ][0];
				$height = $known[ $filename ][1];
			} else {
				/*
				 * Do not query the Media Library for arbitrary raw HTML images during
				 * frontend rendering. Runtime attachment lookups across a large page
				 * add database/object-cache work on every uncached request.
				 */
				return $tag;
			}
		}

		if ( ! $width || ! $height ) {
			return $tag;
		}

		if ( false === stripos( $tag, ' width=' ) ) {
			$tag = preg_replace( '/<img\b/i', '<img width="' . absint( $width ) . '"', $tag, 1 );
		}
		if ( false === stripos( $tag, ' height=' ) ) {
			$tag = preg_replace( '/<img\b/i', '<img height="' . absint( $height ) . '"', $tag, 1 );
		}
		return $tag;
	}



	public static function print_accessibility_runtime() {
		if ( ! self::enabled( 'accessibility_fixes' ) || is_admin() ) {
			return;
		}
		?>
<script data-cfasync="false" id="ionpg-accessibility-runtime">
(function(){
  function text(node){ return node ? String(node.textContent || "").replace(/\s+/g," ").trim() : ""; }
  function repair(){
    var search = document.getElementById("ion-header-search-toggle");
    if (search) {
      search.removeAttribute("aria-hidden");
      if (!search.getAttribute("aria-label")) { search.setAttribute("aria-label","Toggle site search"); }
    }
    document.querySelectorAll("input.xoo-wsc-qty").forEach(function(input){
      if (!input.getAttribute("aria-label")) { input.setAttribute("aria-label","Quantity"); }
    });
    document.querySelectorAll("a.ion-hero-mobile-hotspot, a.ion-hero-hotspot").forEach(function(link){
      var label = text(link);
      if (label) { link.setAttribute("aria-label", label); }
    });
    var cart = document.querySelector("#ion-site-header .ion-header-cart");
    if (cart) {
      var total = text(cart.querySelector(".ion-header-cart-total"));
      var count = text(cart.querySelector(".ion-header-cart-count")) || "0";
      cart.setAttribute("aria-label","Cart" + (total ? " " + total : "") + " " + count + (count === "1" ? " item" : " items"));
    }
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", repair, {once:true});
  } else {
    repair();
  }
})();
</script>
		<?php
	}


	private static function delay_marketing_scripts( $html ) {
		$external_domains = '(?:www\\.googletagmanager\\.com|googletagmanager\\.com|analytics\\.tiktok\\.com|www\\.redditstatic\\.com|redditstatic\\.com|alb\\.reddit\\.com|omnisnippet1\\.com)';
		$external_pattern = "~<script\\b([^>]*)\\bsrc=[\"'](https?://" . $external_domains . "[^\"']*)[\"']([^>]*)></script>~is";

		$html = preg_replace_callback(
			$external_pattern,
			function ( $matches ) {
				$attrs = $matches[1] . $matches[3];
				$attrs = preg_replace( "~\\s+type=[\"'][^\"']*[\"']~i", '', $attrs );
				$attrs = preg_replace( "~\\s+src=[\"'][^\"']*[\"']~i", '', $attrs );
				return '<script type="text/plain" data-ionpg-delay="external" data-ionpg-src="' . esc_attr( html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' ) ) . '"' . $attrs . '></script>';
			},
			$html
		);

		$html = preg_replace_callback(
			"~<script\\b((?!\\bsrc=)[^>]*)>(.*?)</script>~is",
			function ( $matches ) {
				$body = $matches[2];
				$signals = array(
					'googletagmanager.com',
					'gtag(',
					'ttq.load',
					'analytics.tiktok.com',
					'redditstatic.com',
					"rdt('init'",
					'rdt("init"',
					'omnisnippet1.com',
				);
				$matched = false;
				foreach ( $signals as $signal ) {
					if ( false !== stripos( $body, $signal ) ) {
						$matched = true;
						break;
					}
				}
				if ( ! $matched || false !== stripos( $matches[1], 'application/ld+json' ) ) {
					return $matches[0];
				}
				$attrs = preg_replace( "~\\s+type=[\"'][^\"']*[\"']~i", '', $matches[1] );
				return '<script type="text/plain" data-ionpg-delay="inline"' . $attrs . '>' . $body . '</script>';
			},
			$html
		);

		$loader = <<<'JS'
<script data-cfasync="false" id="ionpg-interaction-loader">
(function(){
  var started=false;
  var events=['pointerdown','touchstart','mousedown','keydown','scroll'];
  function copyAttributes(from,to){
    for(var i=0;i<from.attributes.length;i++){
      var a=from.attributes[i];
      if(a.name==='type'||a.name==='data-ionpg-delay'||a.name==='data-ionpg-src'){continue;}
      to.setAttribute(a.name,a.value);
    }
  }
  async function release(){
    if(started){return;}
    started=true;
    events.forEach(function(e){window.removeEventListener(e,release,true);});
    var nodes=Array.prototype.slice.call(document.querySelectorAll('script[data-ionpg-delay]'));
    for(var i=0;i<nodes.length;i++){
      var old=nodes[i];
      var fresh=document.createElement('script');
      copyAttributes(old,fresh);
      var src=old.getAttribute('data-ionpg-src');
      if(src){
        await new Promise(function(resolve){
          fresh.onload=resolve;
          fresh.onerror=resolve;
          fresh.src=src;
          old.parentNode.replaceChild(fresh,old);
        });
      }else{
        fresh.text=old.textContent||'';
        old.parentNode.replaceChild(fresh,old);
      }
    }
  }
  events.forEach(function(e){window.addEventListener(e,release,{capture:true,passive:true,once:true});});
})();
</script>
JS;
		if ( false !== stripos( $html, '</body>' ) ) {
			return preg_replace( '~</body>~i', $loader . "\n</body>", $html, 1 );
		}
		return $html . $loader;
	}

	public static function send_headers() {
		if ( headers_sent() || is_admin() ) {
			return;
		}
		header( 'X-Ion-Performance-Guard: active; version=' . self::VERSION );

		if ( ! self::enabled( 'edge_cache_headers' ) || ! self::is_public_cache_candidate() ) {
			return;
		}
		$settings = self::settings();
		$ttl = isset( $settings['edge_cache_ttl'] ) ? absint( $settings['edge_cache_ttl'] ) : 3600;
		$ttl = max( 300, min( 86400, $ttl ) );
		header( 'Cache-Control: public, max-age=0, s-maxage=' . $ttl . ', stale-while-revalidate=60' );
		header( 'CDN-Cache-Control: public, s-maxage=' . $ttl . ', stale-while-revalidate=60' );
	}

	private static function is_public_cache_candidate() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || is_user_logged_in() || wp_doing_ajax() ) {
			return false;
		}
		if ( ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return false;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return false;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return false;
		}
		if ( is_search() || is_preview() || is_404() || is_feed() ) {
			return false;
		}

		$blocked_cookie_fragments = array(
			'wordpress_logged_in',
			'wordpress_sec',
			'wp_woocommerce_session',
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'age_gate',
			'ionlg_',
			'ion_gate',
		);
		foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
			foreach ( $blocked_cookie_fragments as $fragment ) {
				if ( false !== stripos( (string) $cookie_name, $fragment ) ) {
					return false;
				}
			}
		}
		return true;
	}

	public static function admin_menu() {
		add_options_page(
			'Ion Performance Guard',
			'Ion Performance',
			'manage_options',
			'ion-performance-guard',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'ionpg_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize_settings( $input ) {
		$defaults = self::defaults();
		$output = array();
		foreach ( $defaults as $key => $default ) {
			if ( 'edge_cache_ttl' === $key ) {
				$value = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : $default;
				$output[ $key ] = max( 300, min( 86400, $value ) );
			} else {
				$output[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
			}
		}
		self::$settings = null;
		return $output;
	}

	public static function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=ion-performance-guard' ) ) . '">Settings</a>' );
		return $links;
	}

	private static function checkbox_row( $key, $title, $description, $risk = 'safe' ) {
		$settings = self::settings();
		$checked = ! empty( $settings[ $key ] );
		$badge = 'safe' === $risk ? '<span class="ionpg-badge ionpg-safe">SAFE DEFAULT</span>' : '<span class="ionpg-badge ionpg-caution">CAUTION</span>';
		echo '<tr><th scope="row">' . esc_html( $title ) . '<br>' . $badge . '</th><td><label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']" value="1" ' . checked( $checked, true, false ) . '> Enable</label><p class="description">' . wp_kses_post( $description ) . '</p></td></tr>';
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::settings();
		$home = home_url( '/' );
		$bypass = add_query_arg( 'ionpg_bypass', '1', $home );
		?>
		<div class="wrap ionpg-wrap">
			<h1>Ion Performance Guard</h1>
			<p class="ionpg-lead">Site-wide performance protection built around the July 26 Lighthouse and REST-request traces. Safe options are enabled by default. Caution options stay off until tested.</p>

			<div class="ionpg-grid">
				<div class="ionpg-card ionpg-card-red">
					<h2>Report diagnosis</h2>
					<ul>
						<li><strong>Mobile performance:</strong> 74</li>
						<li><strong>Desktop performance:</strong> 95</li>
						<li><strong>Origin response:</strong> 1.67s to 12.85s</li>
						<li><strong>Requests:</strong> 97–102</li>
						<li><strong>Automatic REST load:</strong> full product catalog plus cart request</li>
						<li><strong>Primary target:</strong> reduce PHP requests, not just browser bytes</li>
					</ul>
				</div>
				<div class="ionpg-card">
					<h2>Test correctly</h2>
					<p>The supplied Lighthouse run tested an <code>invalid_login</code> URL containing a <code>nocache</code> parameter. That intentionally defeats page caching.</p>
					<p><a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( $home ); ?>">Open clean homepage</a> <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $bypass ); ?>">Open with optimizer bypassed</a></p>
				</div>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'ionpg_settings_group' ); ?>

				<h2>Safe optimization layer</h2>
				<table class="form-table" role="presentation">
					<?php
					self::checkbox_row( 'safe_core_cleanup', 'Core WordPress cleanup', 'Removes emoji assets, oEmbed discovery overhead, WP Embed, and shortlink output. Does not remove jQuery or WooCommerce.', 'safe' );
					self::checkbox_row( 'remove_customer_dashicons', 'Remove Dashicons for storefront customers', 'Removes the 35 KB Dashicons stylesheet for guests and customer-level users while preserving it for editors and administrators.', 'safe' );
					self::checkbox_row( 'global_context_cleanup', 'Apply page-specific unloads across the entire storefront', 'Checkout, cart, affiliate, waitlist, variation-swatches, country-select, and infinite-scroll assets are kept only on page types that can actually use them.', 'safe' );
					self::checkbox_row( 'lazy_product_catalog', 'Load the floating product catalog only after interaction', 'Prevents the header product finder from downloading the entire WooCommerce catalog on every page load. The request is released when the visitor opens or searches the product finder, then cached for that browser tab for 10 minutes.', 'safe' );
					self::checkbox_row( 'skip_empty_cart_rest', 'Skip Store API cart calls for visitors with no cart', 'Returns a local empty-cart response when no WooCommerce cart/session cookie exists and coalesces duplicate cart GETs when a cart does exist.', 'safe' );
					self::checkbox_row( 'home_asset_cleanup', 'Extra homepage-only cleanup', 'Retains the original homepage-specific unload list as a second layer for the custom homepage.', 'safe' );
					self::checkbox_row( 'strip_elementor_unused', 'Unload Elementor on pages not built with Elementor', 'Checks the queried object for Elementor data and unloads Elementor/Header Footer Elementor only when the current page is not Elementor-built.', 'safe' );
					self::checkbox_row( 'strip_elementor_home', 'Legacy homepage Elementor switch', 'Compatibility fallback for older settings. Leave enabled; the site-wide unused-page switch above takes precedence.', 'safe' );
					self::checkbox_row( 'strip_broken_elementor_fonts', 'Remove broken Elementor local-font requests', 'Stops the Roboto, Roboto Slab, Inter, and Poppins Elementor font CSS requests that currently return HTML and trigger MIME errors.', 'safe' );
					self::checkbox_row( 'responsive_heroes', 'Load only the correct hero image', 'Uses media-aware <code>&lt;picture&gt;</code> delivery so mobile does not download the desktop hero and desktop does not download the mobile hero. v1.0.2 fixes the stray <code>\n\n</code> characters that previously appeared at the top-left.', 'safe' );
					self::checkbox_row( 'preload_active_hero', 'Preload the active hero image', 'Preloads only the matching mobile or desktop hero and reuses WordPress-generated <code>srcset</code> sizes when available.', 'safe' );
					self::checkbox_row( 'image_dimensions', 'Add missing image dimensions', 'Adds explicit dimensions to custom HTML images using filename dimensions or Media Library metadata, reducing audit warnings and layout uncertainty.', 'safe' );
					self::checkbox_row( 'accessibility_fixes', 'Repair Lighthouse accessibility failures', 'Fixes the hidden search checkbox, side-cart quantity labels, hero hotspot accessible names, cart accessible name, and the low-contrast “How It Works” kicker.', 'safe' );
					self::checkbox_row( 'protect_critical_scripts', 'Protect essential commerce scripts from Rocket Loader', 'Adds <code>data-cfasync="false"</code> to jQuery, WooCommerce, side-cart, BRW, and Ion scripts so Cloudflare does not reorder dependencies that must execute in sequence.', 'safe' );
					?>
				</table>

				<h2>Advanced switches — test one at a time</h2>
				<table class="form-table" role="presentation">
					<?php
					self::checkbox_row( 'delay_marketing_interaction', 'Delay analytics and marketing scripts until interaction', 'Releases Google Tag Manager/gtag, TikTok, Reddit, and Omnisend scripts after the visitor clicks, taps, scrolls, presses a key, or moves the pointer. This can significantly improve performance and Best Practices, but bounce visits may not be tracked and marketing popups will wait for interaction.', 'caution' );
					self::checkbox_row( 'remove_sidecart_confetti', 'Remove side-cart confetti JavaScript', 'Removes roughly 39 KB site-wide. Enabled by default because it is decorative and not required for cart operation.', 'safe' );
					self::checkbox_row( 'strip_woocommerce_home', 'Unload WooCommerce base CSS on the homepage', 'The custom homepage may not need WooCommerce layout, small-screen, general, or block-theme CSS. Test product cards, notices, mini-cart, and add-to-cart behavior after enabling.', 'caution' );
					self::checkbox_row( 'strip_discount_assets_home', 'Unload discount-rule assets on the homepage', 'Can reduce homepage CSS and JavaScript, but may affect displayed sale prices or dynamic discount messaging.', 'caution' );
					self::checkbox_row( 'unload_omnisend_home', 'Unload Omnisend storefront JavaScript on the homepage', 'Can remove a meaningful amount of third-party JavaScript. Do not enable if homepage forms, tracking, popups, or Omnisend storefront features are required.', 'caution' );
					self::checkbox_row( 'disable_empty_cart_fragments', 'Disable cart fragments when no cart cookie exists', 'Applies site-wide outside cart and checkout. The side cart and request-saver layer continue to update the header when a real cart is created.', 'safe' );
					self::checkbox_row( 'remove_jquery_migrate', 'Remove jQuery Migrate site-wide', 'Can save a request, but old plugin scripts may depend on it. Leave off until checkout, account, product variations, and side cart are tested.', 'caution' );
					self::checkbox_row( 'remove_fontawesome_unused', 'Remove theme Font Awesome', 'Lighthouse reported the theme Font Awesome stylesheet as 100% unused on the homepage. Enable only after checking icons on shop, account, cart, checkout, FAQ, and footer pages.', 'caution' );
					self::checkbox_row( 'edge_cache_headers', 'Send anonymous edge-cache headers', 'Sends public CDN cache headers only for clean, logged-out GET/HEAD requests with no query string, cart, account, checkout, login, WooCommerce session, or gate cookies. Cloudflare still needs a matching Cache Rule.', 'caution' );
					?>
					<tr>
						<th scope="row">Edge cache TTL</th>
						<td><input type="number" min="300" max="86400" step="60" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[edge_cache_ttl]" value="<?php echo esc_attr( absint( $settings['edge_cache_ttl'] ) ); ?>"> seconds<p class="description">Used only when anonymous edge-cache headers are enabled. Recommended starting value: 3600.</p></td>
					</tr>
				</table>

				<?php submit_button( 'Save Performance Settings' ); ?>
			</form>

			<div class="ionpg-card ionpg-note">
				<h2>Emergency bypass</h2>
				<p>Add <code>?ionpg_bypass=1</code> to any URL to load that request without this plugin’s front-end optimizations. You can also define <code>IONPG_BYPASS</code> as <code>true</code> in <code>wp-config.php</code>.</p>
			</div>
		</div>
		<style>
			.ionpg-wrap{max-width:1180px}.ionpg-lead{font-size:16px;max-width:900px}.ionpg-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:22px 0}.ionpg-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;box-shadow:0 4px 18px rgba(0,0,0,.04)}.ionpg-card-red{border-left:5px solid #d63638}.ionpg-card h2{margin-top:0}.ionpg-card ul{margin-bottom:0}.ionpg-badge{display:inline-block;margin-top:5px;padding:3px 7px;border-radius:999px;font-size:10px;line-height:1;font-weight:800}.ionpg-safe{background:#d7f5df;color:#0a5d21}.ionpg-caution{background:#fff3cd;color:#7a4d00}.ionpg-note{margin-top:24px}.ionpg-wrap code{font-size:12px}@media(max-width:782px){.ionpg-grid{grid-template-columns:1fr}}
		</style>
		<?php
	}
}

ION_Performance_Guard::init();
