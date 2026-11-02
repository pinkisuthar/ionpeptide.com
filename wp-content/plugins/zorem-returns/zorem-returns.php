<?php
/**
 * Plugin Name: Zorem Returns
 * Plugin URI:  https://www.zorem.com/plugins/
 * Description: Your customer can request for Returns from My account > Orders. If you are selling a virtual product and you payment gateway support for automatic return & exchange so your customer get instant return & exchange.
 * Version:     1.7.4
 * Author:      zorem
 * Author URI:  http://www.zorem.com/
 * License:     GPL-2.0+
 * License URI: http://www.zorem.com/
 * Text Domain: zorem-returns
 * Domain Path: /lang/
 * Requires at least: 6.2
 * Tested up to: 7.1
 * WC requires at least: 4.0
 * WC tested up to: 11.1.0
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorem_Returns_Exchanges {

	/**
	 * Zorem Returns
	 *
	 * @var string
	 */
	public $version    = '1.7.4';
	public $TextDomain = 'zorem-returns';
	public $plugin_path;
	public $admin;
	public $front;
	public $exFront;
	public $common;
	public $exCommon;
	public $rxCommon;
	public $center;
	public $exCenter;
	public $fulfillment;
	public $exFulfillment;
	public $exProductSearch;
	public $exPriceHandler;
	public $customizer;
	public $license;
	public $install;
	public $restocking_fee;
	public $fraud_prevention;
	public $internal_notes;
	public $warranty;
	public $warranty_center;
	public $wpml;

	/**
	 * Gets the absolute plugin path without a trailing slash, e.g.
	 * /path/to/wp-content/plugins/plugin-directory.
	 *
	 * @return string plugin path
	 */
	public function get_plugin_path() {

		if ( isset( $this->plugin_path ) ) {
			return $this->plugin_path;
		}

		$this->plugin_path = untrailingslashit( plugin_dir_path( __FILE__ ) );

		return $this->plugin_path;
	}

	/**
	 * Return/Zorem Returns
	 *
	 * @ auto loded when object created
	 */
	public function __construct() {

		// Check if Wocoomerce is activated
		if ( $this->is_wc_active() ) {

			// Shared Zorem MCP connection (the "AI Assistant"). Registered BEFORE the
			// licence check on purpose: the merchant's connection is theirs and must not
			// disappear when a licence lapses — only our TOOLS are gated, and that gate
			// lives inside Return_Exchange_MCP_Bridge::register().
			$mcp_bootstrap = $this->get_plugin_path() . '/zorem-mcp/bootstrap.php';
			if ( file_exists( $mcp_bootstrap ) ) {
				require_once $mcp_bootstrap;
				zorem_mcp_register_package(
					trim( (string) file_get_contents( $this->get_plugin_path() . '/zorem-mcp/VERSION' ) ),
					$this->get_plugin_path() . '/zorem-mcp'
				);

				require_once $this->get_plugin_path() . '/includes/class-return-mcp-bridge.php';
				Return_Exchange_MCP_Bridge::init();
			}

			// license
			require_once $this->get_plugin_path() . '/includes/class-return-exchange-license.php';
			$this->license = Return_Exchange_License::get_instance();

			// update-manager (optional — only handles auto-updates from zorem.com;
			// guarded with file_exists so a missing file does not fatal-error the
			// plugin during install/upload edge cases).
			$update_manager_file = $this->get_plugin_path() . '/includes/class-return-exchange-update-manager.php';
			if ( file_exists( $update_manager_file ) ) {
				require_once $update_manager_file;
				new Return_Exchange_Update_Manager(
					$this->version,
					'zorem-returns/zorem-returns.php',
					$this->license->get_item_code()
				);
			}

			$this->includes_for_all();

			// Review request notice (ZUI plugin-notice card)
			add_action( 'admin_notices', array( $this, 'zorem_returns_review_notice' ) );
			add_action( 'admin_init', array( $this, 'zorem_returns_review_notice_ignore' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'zorem_returns_review_notice_styles' ) );

			$subscription_status = $this->license->check_subscription_status();
			if ( $subscription_status ) {
				$this->includes();
				$this->init();
			}
		}
	}

	/*
	* Dismiss the review notice
	*/
	public function zorem_returns_review_notice_ignore() {
		if ( isset( $_GET['zorem-returns-review-ignore-notice'] ) && isset( $_GET['nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_GET['nonce'] ) );
			if ( wp_verify_nonce( $nonce, 'zorem_returns_review_notice' ) ) {
				update_option( 'zorem_returns_review_notice_ignore', 'true' );
			}
		}
	}

	/*
	* ZUI plugin-notice styles — the review notice renders from `admin_notices`
	* on every admin page, outside `.zui-scope`, so the standalone component
	* stylesheet is enqueued here (it has no dependency on the zui.css bundle).
	*/
	public function zorem_returns_review_notice_styles() {

		if ( get_option( 'zorem_returns_review_notice_ignore' ) ) {
			return;
		}

		$zui_version_file = $this->get_plugin_path() . '/assets/zui/VERSION';
		$zui_version      = is_readable( $zui_version_file ) ? trim( file_get_contents( $zui_version_file ) ) : $this->version;

		wp_enqueue_style( 'zorem-returns-zui-pnotice', $this->plugin_dir_url() . 'assets/zui/css/components/plugin-notice.css', array(), $zui_version );
	}

	/*
	* Display the review request notice (ZUI plugin-notice card)
	*/
	public function zorem_returns_review_notice() {

		if ( get_option( 'zorem_returns_review_notice_ignore' ) ) {
			return;
		}

		$nonce           = wp_create_nonce( 'zorem_returns_review_notice' );
		$dismissable_url = esc_url( add_query_arg( array( 'zorem-returns-review-ignore-notice' => 'true', 'nonce' => $nonce ) ) );
		?>
		<div class="zui-pnotice" role="status">
			<span class="zui-pnotice__avatar" aria-hidden="true">
				<svg class="zui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>
			</span>
			<div class="zui-pnotice__body">
				<strong class="zui-pnotice__title">⭐ <?php esc_html_e( 'Enjoying Zorem Returns? Leave Us a Review!', 'zorem-returns' ); ?></strong>
				<p class="zui-pnotice__text"><?php esc_html_e( 'We hope Zorem Returns has made managing returns and exchanges easy for your store and your customers! Your feedback helps us grow and continue improving the plugin.', 'zorem-returns' ); ?></p>
				<p class="zui-pnotice__text"><?php esc_html_e( 'If you love using Zorem Returns, we\'d really appreciate it if you could take a moment to leave us a 5-star review. It helps us keep improving and providing the best experience for you!', 'zorem-returns' ); ?></p>
				<p class="zui-pnotice__text"><strong>👍 <?php esc_html_e( 'Support Zorem Returns & Share Your Experience!', 'zorem-returns' ); ?></strong></p>
				<div class="zui-pnotice__actions">
					<a class="zui-pnotice__btn" href="https://www.zorem.com/product/zorem-returns/?write_review=1" target="_blank" rel="noreferrer noopener"><?php esc_html_e( 'Ok, you deserve it', 'zorem-returns' ); ?></a>
					<a class="zui-pnotice__btn zui-pnotice__btn--ghost" href="<?php echo esc_url( $dismissable_url ); ?>"><?php esc_html_e( 'I already did', 'zorem-returns' ); ?></a>
					<a class="zui-pnotice__link" href="<?php echo esc_url( $dismissable_url ); ?>"><?php esc_html_e( 'Nope, maybe later', 'zorem-returns' ); ?></a>
				</div>
			</div>
			<a class="zui-pnotice__close" href="<?php echo esc_url( $dismissable_url ); ?>" aria-label="<?php esc_attr_e( 'Dismiss', 'zorem-returns' ); ?>">&times;</a>
		</div>
		<?php
	}

	/*
	* init function for hooks
	*/
	public function init() {

		// load text domain for lang
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_analytics_scripts' ] );
		add_filter( 'woocommerce_analytics_report_menu_items', array( $this, 'register_analytics_page' ) );
		$this->protect_ajax_json_output();
	}

	/**
	 * Third-party code (support widgets, code-snippet plugins) can echo markup
	 * during admin-ajax requests, which corrupts the JSON responses of the
	 * return/exchange front-end flows and silently stops them. Buffer any such
	 * stray output as early as possible and discard it right before our
	 * handlers run, so wp_send_json_*() always starts with a clean body.
	 */
	private function protect_ajax_json_output() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only routing on the action name, each handler verifies its own nonce.
		if ( ! wp_doing_ajax() || empty( $_REQUEST['action'] ) ) {
			return;
		}

		$frontend_ajax_actions = array(
			'fetch_return_request_data',
			'fetch_guest_return_form',
			'fetch_return_items_data',
			'save_return_center_data',
			'return_request_cancel',
			'fetch_exchange_items_data',
			'save_exchange_center_data',
			'exchange_request_cancel',
			'zre_search_products',
			'zre_get_product_variations',
			'zre_calculate_price_difference',
			'zorem_calculate_restocking_fee',
			'zorem_submit_warranty_claim',
			'fetch_warranty_request_data',
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		if ( ! in_array( $action, $frontend_ajax_actions, true ) ) {
			return;
		}

		ob_start();
		add_action( 'wp_ajax_' . $action, array( $this, 'discard_stray_ajax_output' ), 0 );
		add_action( 'wp_ajax_nopriv_' . $action, array( $this, 'discard_stray_ajax_output' ), 0 );
	}

	/**
	 * Discard everything echoed before our AJAX handler runs.
	 */
	public function discard_stray_ajax_output() {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/*
	* Add Returns page to WooCommerce Analytics report menu items
	*/
	public function register_analytics_page( $report_pages ) {
		$report_pages[] = array(
			'id'     => 'returns-analytics',
			'title'  => __( 'Returns', 'zorem-returns' ),
			'parent' => 'woocommerce-analytics',
			'path'   => '/analytics/returns',
		);
		return $report_pages;
	}

	public function enqueue_analytics_scripts( $hook ) {
	/**
	 * Enqueue analytics scripts.
	 *
	 * Only load on WooCommerce admin pages.
	 *
	 * @param string $hook The current hook.
	 */
		// Only load on WooCommerce admin pages
		if ( ! function_exists( 'wc_admin_url' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$asset_file = include plugin_dir_path( __FILE__ ) . 'build/analytics/index.asset.php';

		wp_enqueue_script(
			'returns-analytics',
			plugins_url( 'build/analytics/index.js', __FILE__ ),
			array_merge(
				$asset_file['dependencies'],
				[ 'wp-element', 'wp-i18n', 'wc-components', 'wc-currency', 'wc-navigation' ]
			),
			$asset_file['version'],
			true
		);

		wp_set_script_translations(
			'returns-analytics',
			'zorem-returns'
		);
	}

	public function includes_for_all() {
		// admin ui
		require_once $this->get_plugin_path() . '/includes/class-return-exchange-admin.php';
		$this->admin = Return_Exchange_Admin::get_instance();

		// Analytics REST API
		require_once $this->get_plugin_path() . '/includes/class-return-analytics-api.php';
		Return_Analytics_API::get_instance();
	}

	public function includes() {

		// customizer
		require_once $this->get_plugin_path() . '/includes/customizer/customizer-admin.php';
		$this->customizer = Return_Customizer_Admin::get_instance();

		// Return Exchange Common
		require_once $this->get_plugin_path() . '/includes/class-return-exchange-common.php';
		$this->rxCommon = Return_Exchange_Common::get_instance();

		// WPML / multilingual compatibility — language switching for emails.
		// String registration itself lives in wpml-config.xml.
		require_once $this->get_plugin_path() . '/includes/class-return-exchange-wpml.php';
		$this->wpml = Return_Exchange_WPML::get_instance();

		// Return Exchange Installation
		require_once $this->get_plugin_path() . '/includes/class-return-exchange-installation.php';
		$this->install = Return_Exchange_Installation::get_instance();

		// callback for migration function
		add_action( 'admin_init', array( $this->install, 'return_exchange_update_install_callback' ) );

		/*** Returns */

		// front ui
		require_once $this->get_plugin_path() . '/includes/zorem-returns/class-return-front.php';
		$this->front = Return_Front::get_instance();

		// Common functions
		require_once $this->get_plugin_path() . '/includes/zorem-returns/class-return-common-functions.php';
		$this->common = Return_Common_Functions::get_instance();

		// front return center ui
		require_once $this->get_plugin_path() . '/includes/zorem-returns/class-return-center-form.php';
		$this->center = Return_Center_Form::get_instance();

		// fullfillment
		require_once $this->get_plugin_path() . '/includes/zorem-returns/class-return-fulfillment-dashboard.php';
		$this->fulfillment = Return_Fulfillment_Dashboard::get_instance();

		// Restocking fee (deducted from return refunds — does not apply to exchanges)
		require_once $this->get_plugin_path() . '/includes/zorem-returns/class-restocking-fee.php';
		$this->restocking_fee = Restocking_Fee::get_instance();

		// Fraud Prevention — per-customer return limit & blocklist
		require_once $this->get_plugin_path() . '/includes/zorem-returns/class-fraud-prevention.php';
		$this->fraud_prevention = Return_Fraud_Prevention::get_instance();

		// Internal Admin Notes — private per-request notes for support team
		require_once $this->get_plugin_path() . '/includes/zorem-returns/class-internal-notes.php';
		$this->internal_notes = Return_Internal_Notes::get_instance();

		// Warranty Workflow — separate warranty claim flow alongside returns
		require_once $this->get_plugin_path() . '/includes/zorem-warranty/class-warranty.php';
		$this->warranty = Return_Warranty::get_instance();

		// Guest warranty claim shortcode — [warranty-center] order number + email lookup
		require_once $this->get_plugin_path() . '/includes/zorem-warranty/class-warranty-center-form.php';
		$this->warranty_center = Warranty_Center_Form::get_instance();

		// Exchange Common functions
		require_once $this->get_plugin_path() . '/includes/zorem-exchanges/class-exchange-common-functions.php';
		$this->exCommon = Exchange_Common_Functions::get_instance();

		$return_general_settings_option = get_option( 'return_general_settings_option', array() );
		$exchange_enable                = isset( $return_general_settings_option['exchange_enable'] ) ? $return_general_settings_option['exchange_enable'] : 'yes';
		if ( 'yes' == $exchange_enable ) {
			/*** Exchange */

			// Cross-product product search (load before exCommon so is_enabled() is available)
			require_once $this->get_plugin_path() . '/includes/zorem-exchanges/class-exchange-product-search.php';
			$this->exProductSearch = Zorem_Exchange_Product_Search::get_instance();

			// Cross-product price difference handler
			require_once $this->get_plugin_path() . '/includes/zorem-exchanges/class-exchange-price-handler.php';
			$this->exPriceHandler = Exchange_Price_Handler::get_instance();

			// front ui
			require_once $this->get_plugin_path() . '/includes/zorem-exchanges/class-exchange-front.php';
			$this->exFront = Exchange_Front::get_instance();

			// front return center ui
			require_once $this->get_plugin_path() . '/includes/zorem-exchanges/class-exchange-center-form.php';
			$this->exCenter = Exchange_Center_Form::get_instance();

			// fullfillment
			require_once $this->get_plugin_path() . '/includes/zorem-exchanges/class-exchange-fulfillment-dashboard.php';
			$this->exFulfillment = Exchange_Fulfillment_Dashboard::get_instance();
		}
	}

	public function plugin_dir_url() {
		return plugin_dir_url( __FILE__ );
	}

	public function load_textdomain() {
		// WordPress only auto-loads translations from wp-content/languages (wp.org
		// language packs); the bundled /lang files still need an explicit load.
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound, WordPress.WP.DeprecatedParameters.Load_plugin_textdomainParam2Found -- Required to load the plugin's own bundled /lang translations, which WordPress does not auto-load.
		load_plugin_textdomain( 'zorem-returns', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );
	}

	/**
	 * Check if WooCommerce is active
	 *
	 * @since  1.0
	 * @return bool
	 */
	private function is_wc_active() {

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . '/wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			$is_active = true;
		} else {
			$is_active = false;
		}

		// Do the WC active check
		if ( false === $is_active ) {
			add_action( 'admin_notices', array( $this, 'notice_activate_woocommerce' ) );
		}
		return $is_active;
	}

	/**
	 * Display WC active notice
	 *
	 * @since  1.0
	 */
	public function notice_activate_woocommerce() {
		?>
		<div class="error">
			<?php /* translators: %s: search for a tag */ ?>
			<p><?php printf( esc_html__( 'Please install and activate %1$sWooCommerce%2$s for Zorem Returns.', 'zorem-returns' ), '<a href="' . esc_url( admin_url( 'plugin-install.php?tab=search&s=WooCommerce&plugin-search-input=Search+Plugins' ) ) . '">', '</a>' ); ?></p>
		</div>
		<?php
	}
}

function zorem_returns_exchanges() {
	static $instance;

	if ( ! isset( $instance ) ) {
		$instance = new Zorem_Returns_Exchanges();
	}

	return $instance;
}
zorem_returns_exchanges();

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
