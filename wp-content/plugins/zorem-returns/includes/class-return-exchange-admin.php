<?php
// phpcs:ignoreFile WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonces are verified inside each AJAX handler; superglobal reads are wrapped with wp_unslash + sanitize_*.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Exchange_Admin {

	/**
	 * Instance of this class.
	 *
	 * @var object Class Instance
	 */
	private static $instance;

	/**
	 * Initialize the main plugin function
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Get the class instance
	 */
	public static function get_instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/*
	 * init function
	*/
	public function init() {

		// register admin menu
		add_action( 'admin_menu', array( $this, 'register_woocommerce_menu' ), 100 );

		// load css/javascript in admin
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );

		// load css/javascript in frontend
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_css_js' ) );

		// save setting menu
		add_action( 'wp_ajax_return_settings_tab_update', array( $this, 'update_return_settings_tab_callback' ) );
		add_action( 'wp_ajax_email_notifications_status_update', array( $this, 'email_notifications_status_update_callback' ) );
		add_action( 'wp_ajax_rt4wc_fetch_products', array( $this, 'rt4wc_get_products_callback' ) );

		add_action( 'admin_footer', array( $this, 'footer_function' ), 1 );

		// Add "Keet the item" option at product level
		add_action( 'woocommerce_product_options_shipping', array( $this, 'product_options' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_custom_product_fields' ) );

		// Per-user return window extension — user profile field
		add_action( 'show_user_profile', array( $this, 'show_user_return_window_field' ) );
		add_action( 'edit_user_profile', array( $this, 'show_user_return_window_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_return_window_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_return_window_field' ) );

		// Add Return custom order status
		add_action( 'init', array( $this, 'register_return_order_status' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_return_order_status' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_actions_change_order_status' ), 50, 1 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_actions_change_order_status' ), 50, 1 );
		add_filter( 'woocommerce_email_actions', array( $this, 'disable_return_order_status_email_notification' ) );
	}

	/*
	* Admin Menu add function
	* WC sub menu
	* @since 1.0
	*/
	public function register_woocommerce_menu() {
		add_submenu_page( 'woocommerce', __( 'Returns Center', 'zorem-returns' ), __( 'Returns Center', 'zorem-returns' ), $this->get_admin_capability(), 'zorem-returns-exchanges', array( $this, 'settings_page_callback' ) );
	}

	/**
	 * Capability required to access the Returns Center admin pages.
	 *
	 * Defaults to `manage_options` so only administrators can access the Returns Center.
	 * Filter `zorem_returns_exchanges_admin_capability` to grant access to other roles
	 * (e.g. return `manage_woocommerce` to allow shop managers) or a custom capability.
	 *
	 * @return string
	 */
	public function get_admin_capability() {
		return apply_filters( 'zorem_returns_exchanges_admin_capability', 'manage_options' );
	}

	/*
	* Add admin javascript/css
	*/
	public function admin_enqueue() {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'zorem-returns-exchanges' == $page ) {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			// Add the color picker css file
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );

			wp_register_script( 'wc-jquery-blockui', WC()->plugin_url() . '/assets/js/jquery-blockui/jquery.blockUI' . $suffix . '.js', array( 'jquery' ), '2.70', true );
			wp_enqueue_script( 'wc-jquery-blockui' );

			wp_register_style( 'woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css', array(), WC_VERSION );
			wp_enqueue_style( 'woocommerce_admin_styles' );

			wp_enqueue_style( 'return-css', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/admin.css', array(), zorem_returns_exchanges()->version );

			$tiptip_deps = array( 'jquery' );
			if ( wp_script_is( 'dompurify', 'registered' ) ) {
			    $tiptip_deps[] = 'dompurify';
			}
			wp_register_script( 'wc-jquery-tiptip', WC()->plugin_url() . '/assets/js/jquery-tiptip/jquery.tipTip.min.js', $tiptip_deps, WC_VERSION, true );

			wp_register_script( 'wc-select2', WC()->plugin_url() . '/assets/js/select2/select2.full' . $suffix . '.js', array( 'jquery' ), '4.0.3', true );

			// Use WordPress's bundled jQuery UI instead of shipping our own copy.
			// jquery-ui-* handles are listed as explicit deps of return-js so they
			// load before admin.js executes (fixes "$(...).sortable is not a function").
			$return_js_deps = array( 'jquery', 'jquery-ui-core', 'jquery-ui-datepicker', 'jquery-ui-sortable' );
			foreach ( array( 'wc-select2', 'selectWoo', 'wc-enhanced-select', 'wc-jquery-tiptip' ) as $dep ) {
				if ( wp_script_is( $dep, 'registered' ) ) {
					$return_js_deps[] = $dep;
				}
			}
			wp_enqueue_script( 'return-js', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/admin.js', $return_js_deps, zorem_returns_exchanges()->version, true );
			wp_enqueue_style( 'return-zorem-layout', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/zorem-layout.css', array(), zorem_returns_exchanges()->version );

			// Warranty assets — needed for both the Warranty Claims tab AND the Settings tab (warranty section).
			wp_enqueue_style( 'zorem-warranty', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/warranty.css', array(), zorem_returns_exchanges()->version );
			wp_enqueue_script( 'zorem-warranty', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/warranty.js', array( 'jquery' ), zorem_returns_exchanges()->version, true );

			// ZUI canonical bundle (zorem-settings-ui library).
			$zui_ver_file = zorem_returns_exchanges()->get_plugin_path() . '/assets/zui/VERSION';
			$zui_version  = is_readable( $zui_ver_file ) ? trim( file_get_contents( $zui_ver_file ) ) : zorem_returns_exchanges()->version;
			wp_enqueue_style( 'zorem-returns-zui', zorem_returns_exchanges()->plugin_dir_url() . 'assets/zui/css/zui.css', array(), $zui_version );
			if ( file_exists( zorem_returns_exchanges()->get_plugin_path() . '/assets/zui/css/returns-overrides.css' ) ) {
				wp_enqueue_style( 'zorem-returns-zui-overrides', zorem_returns_exchanges()->plugin_dir_url() . 'assets/zui/css/returns-overrides.css', array( 'zorem-returns-zui' ), $zui_version );
			}
			wp_enqueue_script( 'zorem-returns-zui', zorem_returns_exchanges()->plugin_dir_url() . 'assets/zui/js/zui.js', array(), $zui_version, true );
			wp_enqueue_script( 'zorem-returns-zui-app', zorem_returns_exchanges()->plugin_dir_url() . 'assets/zui/js/zui-app.js', array( 'jquery', 'zorem-returns-zui' ), $zui_version, true );

			// Shared MCP screen assets (the "AI Assistant" tab). Enqueued here — from an
			// admin_enqueue_scripts hook — because the library forbids enqueuing during the
			// screen render (too late; silently dropped). Safe to load on every plugin page.
			if ( function_exists( 'zorem_mcp_enqueue_assets' ) ) {
				zorem_mcp_enqueue_assets();
			}
		}
	}

	public function enqueue_css_js() {
		// Only load the return/exchange/warranty frontend assets where they can
		// actually be used: My Account (view-order, my-account endpoints),
		// checkout, and the guest return / warranty claim shortcodes/pages.
		if ( ! function_exists( 'is_account_page' ) ) {
			return;
		}

		global $post;

		$has_return_shortcode = is_a( $post, 'WP_Post' ) && ( has_shortcode( $post->post_content, 'return-center' ) || has_shortcode( $post->post_content, 'warranty-center' ) );

		// Elementor stores widget content in meta, not post_content.
		if ( ! $has_return_shortcode && is_a( $post, 'WP_Post' ) && is_singular() ) {
			$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
			if ( ! empty( $elementor_data ) && ( false !== strpos( $elementor_data, 'return-center' ) || false !== strpos( $elementor_data, 'warranty-center' ) ) ) {
				$has_return_shortcode = true;
			}
		}

		$needs_frontend = is_account_page() || is_wc_endpoint_url() || is_checkout() || $has_return_shortcode;
		$needs_frontend = apply_filters( 'zorem_returns_exchanges_enqueue_frontend', $needs_frontend );
		if ( ! $needs_frontend ) {
			return;
		}

		// Add the WP Media
		wp_enqueue_media();

		wp_enqueue_style( 'return-front', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/frontend.css', array(), zorem_returns_exchanges()->version );
		wp_enqueue_script( 'return-front', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/frontend.js', array(), zorem_returns_exchanges()->version, true );
		wp_script_add_data( 'return-front', 'strategy', 'defer' );
		wp_localize_script( 'return-front', 'ajax_object', array( 'ajax_url' => admin_url( 'admin-ajax.php' ) ) );

		// Warranty workflow — small CSS + JS for the customer claim form and order-page button.
		wp_enqueue_style( 'zorem-warranty', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/warranty.css', array(), zorem_returns_exchanges()->version );
		wp_enqueue_script( 'zorem-warranty', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/warranty.js', array( 'jquery' ), zorem_returns_exchanges()->version, true );
		wp_script_add_data( 'zorem-warranty', 'strategy', 'defer' );

		// Cross-product exchange assets
		$plugin = zorem_returns_exchanges();
		if ( ! isset( $plugin->common ) || ! is_object( $plugin->common ) ) {
			return;
		}
		$cross_enabled = 'yes' === $plugin->common->return_settings( 'allow_cross_product_exchange', 'no' );

		if ( $cross_enabled ) {
			wp_enqueue_style( 'zre-cross-product', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/exchange-cross-product.css', array(), zorem_returns_exchanges()->version );
			wp_enqueue_script( 'zre-cross-product', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/exchange-product-search.js', array( 'jquery' ), zorem_returns_exchanges()->version, true );
			wp_localize_script( 'zre-cross-product', 'zre_cross', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'zre_cross_product_nonce' ),
				'i18n'     => array(
					'search_placeholder' => __( 'Search for a product…', 'zorem-returns' ),
					'searching'          => __( 'Searching…', 'zorem-returns' ),
					'no_results'         => __( 'No products found.', 'zorem-returns' ),
					'select_variation'   => __( 'Select a variant', 'zorem-returns' ),
					'choose_option'      => __( 'Choose an option', 'zorem-returns' ),
					'combo_unavailable'  => __( 'Sorry, this combination is unavailable.', 'zorem-returns' ),
					'pay_more_label'     => __( 'Additional payment required', 'zorem-returns' ),
					'credit_label'       => __( 'Store credit will be issued', 'zorem-returns' ),
					'even_label'         => __( 'Even exchange', 'zorem-returns' ),
				),
			) );
		}
	}

	/*
	* settings_page_callback
	* @since 1.0
	*/
	public function settings_page_callback() {

		$subscription_status = zorem_returns_exchanges()->license->check_subscription_status();

		$defualt_tab = ( ! $subscription_status ) ? 'license' : '';
		$tab         = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : $defualt_tab;
		?>
		<div class="rt4wc-settings-app zui-scope" id="rt4wc-settings-app">
			<?php include __DIR__ . '/admin-html/header.php'; ?>
			<?php
			if ( ! $subscription_status ) {
				?>
				<div class="zui-tab-panel is-active" data-tab-panel="license">
					<?php include __DIR__ . '/admin-html/returns-exchanges-license.php'; ?>
				</div>
				<?php
			} elseif ( 'returns-orders' == $tab || 'exchanges-orders' == $tab || 'warranty-claims' == $tab || empty( $tab ) ) {
				$warranty_obj      = isset( zorem_returns_exchanges()->warranty ) ? zorem_returns_exchanges()->warranty : null;
				$warranty_tab_show = $warranty_obj && $warranty_obj->is_enabled();
				$warranty_active   = ( 'warranty-claims' === $tab );
				?>
				<div class="zui-layout zui-layout--full">
				<main class="zui-content zui-content--full">
					<div class="zui-tab-panel<?php echo ! $warranty_active ? ' is-active' : ''; ?>" data-tab-panel="dashboard"<?php echo ! $warranty_active ? '' : ' hidden'; ?>>
						<?php include __DIR__ . '/admin-html/returns-exchanges-center.php'; ?>
					</div>
					<?php if ( $warranty_tab_show ) : ?>
						<div class="zui-tab-panel<?php echo $warranty_active ? ' is-active' : ''; ?>" data-tab-panel="warranty-claims"<?php echo $warranty_active ? '' : ' hidden'; ?>>
							<?php include __DIR__ . '/admin-html/warranty-claims-dashboard.php'; ?>
						</div>
					<?php endif; ?>
				</main>
				</div>
				<?php
			} else {
				// Render Settings + Notifications + License together and use JS to
				// switch them without reloading the page.
				?>
				<div class="zui-tab-panel<?php echo ( 'settings' === $tab || '' === $tab ) ? ' is-active' : ''; ?>" data-tab-panel="settings"<?php echo ( 'settings' === $tab || '' === $tab ) ? '' : ' hidden'; ?>>
					<?php include __DIR__ . '/admin-html/returns-exchanges-settings.php'; ?>
				</div>
				<div class="zui-tab-panel<?php echo ( 'return-emails' === $tab ) ? ' is-active' : ''; ?>" data-tab-panel="return-emails"<?php echo ( 'return-emails' === $tab ) ? '' : ' hidden'; ?>>
					<?php include __DIR__ . '/admin-html/returns-exchanges-emails.php'; ?>
				</div>
				<div class="zui-tab-panel<?php echo ( 'ai-assistant' === $tab ) ? ' is-active' : ''; ?>" data-tab-panel="ai-assistant"<?php echo ( 'ai-assistant' === $tab ) ? '' : ' hidden'; ?>>
					<?php include __DIR__ . '/admin-html/returns-exchanges-ai.php'; ?>
				</div>
				<div class="zui-tab-panel<?php echo ( 'license' === $tab ) ? ' is-active' : ''; ?>" data-tab-panel="license"<?php echo ( 'license' === $tab ) ? '' : ' hidden'; ?>>
					<?php include __DIR__ . '/admin-html/returns-exchanges-license.php'; ?>
				</div>
				<?php
			}
			?>
		</div><!-- /.rt4wc-settings-app -->
		<?php
	}

	/**
	 * Callback function to get products
	 *
	 * @internal
	 *
	 * @since 2.0.0
	 */
	public function rt4wc_get_products_callback() {

		// Get the search term and other parameters from the request
		$search     = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
		$batch_size = isset( $_GET['batch_size'] ) ? intval( $_GET['batch_size'] ) : 10;
		$offset     = isset( $_GET['offset'] ) && empty( $search ) ? intval( $_GET['offset'] ) : 0;

		// Initialize an empty array to store products
		$products = array();

		// Fetch products in batches
		$total_args  = array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		$total_count = count( get_posts( $total_args ) );
		$products    = array( 'total_count' => $total_count );

		// Query products based on search term and pagination
		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			's'              => $search, // Search term
			'fields'         => 'ids',
		);

		// Retrieve product IDs for the current batch
		$product_ids = get_posts( $args );

		// Loop through the product IDs and retrieve product names
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! is_object( $product ) ) {
				continue;
			}

			$products[ $product_id ]['product_id']   = $product_id;
			$products[ $product_id ]['product_name'] = $product->get_name();
		}

		// Return JSON response
		wp_send_json( $products );
	}


	/*
	* Change style of custom order label
	*/
	public function footer_function() {

		$statuses_colors = array(
			'wc-return-requested' => '#03a9f4',
			'wc-return-approved'  => '#4caf50',
			'wc-return-completed' => '#2196f3',
			'wc-return-rejected'  => '#f44336',
			'wc-return-cancelled' => '#f44336',
		);
		wp_enqueue_style( 'rt4wc-order', zorem_returns_exchanges()->plugin_dir_url( __FILE__ ) . 'assets/css/order.css', array(), zorem_returns_exchanges()->version );

		foreach ( $statuses_colors as $slug => $title ) {
			$Slug     = substr( str_replace( '-', '_', $slug ), 3 );
			$bg_color = $statuses_colors[ $slug ];
			$color    = '#fff';
			?>
			<style>
			.status-<?php echo esc_html( substr( $slug, 3 ) ); ?>::before {
				background-color: <?php echo ! empty( $bg_color ) ? esc_html( $bg_color ) : '#255abc'; ?> !important;
			}
			</style>
			<?php
		}
	}

	public function update_return_settings_tab_callback() {
		if ( ! empty( $_POST ) && check_admin_referer( 'return_settings_tab', 'return_settings_tab_nonce' ) ) {

			$data1              = zorem_returns_exchanges()->admin->return_eligibility_settings();
			$data2              = zorem_returns_exchanges()->admin->return_option_settings();
			$data3              = zorem_returns_exchanges()->admin->exchange_option_settings();
			$data4              = zorem_returns_exchanges()->admin->fraud_prevention_settings();
			$data5              = zorem_returns_exchanges()->admin->warranty_settings();
			$return_eligibility = array_merge( $data1, $data2, $data3, $data4, $data5 );
			foreach ( $return_eligibility as $key => $val ) {
				if ( 'title' == $val['type'] ) {
					continue;
				}
				if ( 'text_editor' == $val['type'] ) {
					$value = isset( $_POST[ $key ] ) ? wp_kses_post( wp_unslash( $_POST[ $key ] ) ) : '';
					zorem_returns_exchanges()->common->update_return_settings( $key, $value );
				} elseif ( 'fraud_prevention_blocklist' === $key ) {
					// Preserve newlines so admins can keep one entry per line.
					$posted_val = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
					zorem_returns_exchanges()->common->update_return_settings( $key, $posted_val );
				} else {
					$posted_val = isset( $_POST[ $key ] ) ? wc_clean( wp_unslash( $_POST[ $key ] ) ) : '';
					zorem_returns_exchanges()->common->update_return_settings( $key, $posted_val );
				}

				$store_credit     = isset( $_POST['refund_to_store_credit'] ) ? wc_clean( wp_unslash( $_POST['refund_to_store_credit'] ) ) : '';
				$customer_account = isset( $_POST['refund_to_customer_account'] ) ? wc_clean( wp_unslash( $_POST['refund_to_customer_account'] ) ) : '';
				$tera_wallet      = isset( $_POST['refund_to_tera_wallet'] ) ? wc_clean( wp_unslash( $_POST['refund_to_tera_wallet'] ) ) : '';
				if ( $store_credit || $customer_account || $tera_wallet ) {
					$value = array(
						'store_credit'     => $store_credit,
						'customer_account' => $customer_account,
						'tera_wallet'      => $tera_wallet,
					);
					zorem_returns_exchanges()->common->update_return_settings( $key, wc_clean( $value ) );
				}
			}
			// Send safe JSON response
			wp_send_json(
				array(
					'success'   => true,
					'post-data' => $_POST,
				)
			);
			die();
		}
	}

	public function email_notifications_status_update_callback() {
		if ( ! empty( $_POST ) && check_admin_referer( 'email_notifications_status', 'email_notifications_status_nonce' ) ) {

			foreach ( $_POST as $key => $value ) {

				if ( ! str_contains( $key, 'exchange' ) && ! str_contains( $key, 'return' ) && ! str_contains( $key, 'warranty' ) ) {
					continue;
				}

				$enabled = isset( $_POST[ $key ] ) && 1 == $_POST[ $key ] ? 'yes' : 'no';
				update_option( $key . '_status', $enabled );
			}

			echo json_encode( array( 'success' => 'true' ) );
			die();
		}
	}

	/**
	 * Display the custom return window field on user profile pages.
	 *
	 * @param WP_User $user The user object.
	 */
	public function show_user_return_window_field( $user ) {
		$enabled = zorem_returns_exchanges()->common->return_settings( 'per_user_return_window_enable', 'no' );
		if ( 'yes' !== $enabled ) {
			return;
		}
		$custom_days = get_user_meta( $user->ID, '_zorem_return_window_days', true );
		?>
		<h3><?php esc_html_e( 'Return Window Extension', 'zorem-returns' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><label for="zorem_return_window_days"><?php esc_html_e( 'Custom Return Window (Days)', 'zorem-returns' ); ?></label></th>
				<td>
					<input type="number" name="zorem_return_window_days" id="zorem_return_window_days" value="<?php echo esc_attr( $custom_days ); ?>" min="0" max="90" style="width: 80px;">
					<p class="description"><?php esc_html_e( 'Enter total return/exchange days for this user (max 90). Leave empty or 0 to use the global Return/Exchange Window (Days) setting.', 'zorem-returns' ); ?></p>
					<script>
					jQuery( document ).on( 'input', '#zorem_return_window_days', function () {
						if ( jQuery( this ).val() > 90 ) {
							jQuery( this ).val( 90 );
						}
						if ( jQuery( this ).val() < 0 ) {
							jQuery( this ).val( 0 );
						}
					} );
					</script>
					<?php wp_nonce_field( 'zorem_return_window_nonce', 'zorem_return_window_nonce' ); ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the custom return window field from user profile pages.
	 *
	 * @param int $user_id The user ID being saved.
	 */
	public function save_user_return_window_field( $user_id ) {
		if ( ! isset( $_POST['zorem_return_window_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zorem_return_window_nonce'] ) ), 'zorem_return_window_nonce' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$custom_days = isset( $_POST['zorem_return_window_days'] ) ? absint( $_POST['zorem_return_window_days'] ) : 0;
		update_user_meta( $user_id, '_zorem_return_window_days', $custom_days );
	}

	/*
	* settings array
	*/
	public function return_eligibility_settings() {

		$cat_args           = array(
			'taxonomy'   => 'product_cat',
			'orderby'    => 'name',
			'order'      => 'ASC',
			'hide_empty' => false,
		);
		$product_categories = get_terms( $cat_args );
		foreach ( $product_categories as $category ) {
			$all_categories[ $category->term_id ] = $category->name;
		}

		$all_status = wc_get_order_statuses();
		unset( $all_status['wc-return'] );

		$input_options = array(
			'line'         => array(
				'type'        => 'text',
				'placeholder' => __( 'Store Address Line 1', 'zorem-returns' ),
				'value'       => get_option( 'woocommerce_store_address' ),
			),
			'line_2'       => array(
				'type'        => 'text',
				'placeholder' => __( 'Store Address Line 2', 'zorem-returns' ),
				'value'       => get_option( 'woocommerce_store_address_2' ),
			),
			'city'         => array(
				'type'        => 'text',
				'placeholder' => __( 'City', 'zorem-returns' ),
				'value'       => get_option( 'woocommerce_store_city' ),
			),
			'country'      => array(
				'type'        => 'country-select',
				'placeholder' => __( 'Choose a country / region&hellip;', 'woocommerce' ),
				'value'       => get_option( 'woocommerce_default_country' ),
			),
			'postcode '    => array(
				'type'        => 'text',
				'placeholder' => __( 'Postcode / ZIP', 'zorem-returns' ),
				'value'       => get_option( 'woocommerce_store_postcode' ),
			),
			'phone_number' => array(
				'type'        => 'text',
				'placeholder' => __( 'Phone Number', 'zorem-returns' ),
				'value'       => '',
			),
		);

		$value_array = array();
		foreach ( $input_options as $key => $option ) {
			$value_array[ $key ] = $option['value'];
		}

		$page_list = wp_list_pluck( get_pages(), 'post_title', 'ID' );

		$return_settings = array(
			'return_policy_days'                      => array(
				'title'   => __( 'Return/Exchange Window (Days)', 'zorem-returns' ),
				'desc'    => __( 'Days after the order date within which customers may request a return or exchange.', 'zorem-returns' ),
				'type'    => 'number',
				'class'   => 'return_days_width',
				'value'   => '30',
				'min'     => '0',
				'max'     => '90',
				'tooltip' => __( 'Set the number of days customers are allowed to request a return or exchange after the order is placed.', 'zorem-returns' ),
			),
			'per_user_return_window_enable'           => array(
				'title'   => __( 'Enable Per-User Return Window Extension', 'zorem-returns' ),
				'desc'    => __( 'Override the global window for individual customers from their user profile.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'no',
				'tooltip' => __( 'When enabled, you can set a custom return/exchange window per customer from their user profile page.', 'zorem-returns' ),
			),
			'return_restock_items'             => array(
				'title'   => __( 'Restock Completed Returns and Exchanges', 'zorem-returns' ),
				'desc'    => __( 'Automatically add stock back on completed returns and approved exchanges.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'no',
				'tooltip' => __( 'Enable this option to automatically restock items when a return is marked as completed or an exchange is approved.', 'zorem-returns' ),
			),
			'return_order_statuses'            => array(
				'title'   => __( 'Eligible Order Statuses for Returns/Exchanges', 'zorem-returns' ),
				'desc'    => __( 'Only orders in one of the selected statuses will show the return/exchange buttons.', 'zorem-returns' ),
				'type'    => 'multi_select',
				'class'   => 'wc-enhanced-select input_settings_menu',
				'value'   => array( 'wc-completed' ),
				'options' => $all_status,
				'tooltip' => __( 'Select the order statuses that allow customers to request a return or exchange.', 'zorem-returns' ),
			),
			'return_terms_and_conditions_page' => array(
				'title'      => __( 'Select Terms and Conditions Page', 'zorem-returns' ),
				'desc'       => __( 'Link customers open when they click the "terms and conditions" text on the return form.', 'zorem-returns' ),
				'id'         => 'return_terms_and_conditions_page',
				'css'        => 'min-width:50px;',
				'default'    => '',
				'show'       => true,
				'type'       => 'select',
				'sub_option' => $page_list,
				'class'      => '',
				'value'      => '',
				'tooltip'    => __( 'Choose a page that outlines your return/exchange terms and conditions. This page will be shown to customers during the return request process.', 'zorem-returns' ),
			),
			'return_exclude_categories'        => array(
				'title'   => __( 'Exclude Categories from Returns/Exchanges', 'zorem-returns' ),
				'desc'    => __( 'Products belonging to any of these categories cannot be returned or exchanged.', 'zorem-returns' ),
				'type'    => 'multi_select',
				'class'   => 'wc-enhanced-select input_settings_menu',
				'value'   => array(),
				'options' => $all_categories,
				'tooltip' => __( 'Select product categories that are not eligible for return or exchange requests.', 'zorem-returns' ),
			),
			'return_exclude_products'          => array(
				'title'   => __( 'Products Not Eligible for Returns/Exchanges', 'zorem-returns' ),
				'desc'    => __( 'Individual products that are excluded from the return/exchange workflow.', 'zorem-returns' ),
				'type'    => 'multi_select_batch',
				'class'   => 'wc-enhanced-select input_settings_menu',
				'value'   => array(),
				'tooltip' => __( 'Select the products that are not eligible for return or exchange requests.', 'zorem-returns' ),
			),
			'return_store_address'             => array(
				'title'   => __( 'Return/Exchange Store Address', 'zorem-returns' ),
				'desc'    => __( 'Address shown to customers for shipping returned items back to your store.', 'zorem-returns' ),
				'type'    => 'multiple_input',
				'class'   => 'return_store_address',
				'value'   => $value_array,
				'options' => $input_options,
				'tooltip' => __( 'Enter the store address where customers should send return or exchange items.', 'zorem-returns' ),
			),

		);
		return $return_settings;
	}

	/*
	* Customizer settings array
	*/
	public function return_option_settings() {

		$refund_payment_options = array(
			'store_credit'       => esc_html__( 'Refund to Store Credit', 'zorem-returns' ),
			'customer_account'   => esc_html__( 'Refund to Original Payment Method', 'zorem-returns' ),
			'keep_item_partial'  => esc_html__( 'Keep the Item & Receive a Partial Refund', 'zorem-returns' ),
		);

		if ( function_exists( 'woo_wallet' ) ) {
			$refund_payment_options['tera_wallet'] = esc_html__( 'Refund to Wallet (TeraWallet)', 'zorem-returns' );
		}

		$enabled_payment_gateways = $this->zre_get_enabled_payment_gateways();

		$default_return_reasons = array(
			'Product damaged, but shipping box OK',
			'Both product and shipping box damaged',
			'Wrong item was sent',
			"Item defective, spoilt, expired, or doesn't work",
			'Items or parts missing',
		);

		$settings = array(
			'return_onsale_items'      => array(
				'title'   => __( 'Allow Returns for Sale Products', 'zorem-returns' ),
				'desc'    => __( 'Include sale-priced items in the return workflow.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'no',
				'tooltip' => __( 'Allows customers to request return for items purchased on sale', 'zorem-returns' ),
			),
			'automatic_return'         => array(
				'title'   => __( 'Automatically Approve Return Requests', 'zorem-returns' ),
				'desc'    => __( 'Skip manual review — every new return is approved on submission.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'no',
				'tooltip' => __( 'Enable this option to automatically approve all new return requests.', 'zorem-returns' ),
			),
			'return_entire_order'      => array(
				'title'   => __( 'Allow Partial Return', 'zorem-returns' ),
				'desc'    => __( 'Customer can choose individual items to return rather than the whole order.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'no',
				'tooltip' => __( 'Allows customers to request partial return for individual items in an order.', 'zorem-returns' ),
			),
			'show_start_return_button' => array(
				'title'   => __( 'Show "Start a Return" Button', 'zorem-returns' ),
				'desc'    => __( 'Display the whole-order "Start a Return" button on the customer\'s order page.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'yes',
				'tooltip' => __( 'Disable to hide the whole-order "Start a Return" button — the per-item Return buttons are not affected.', 'zorem-returns' ),
			),
			'refund_payment_options'   => array(
				'title'   => __( 'Refund Method Preferences', 'zorem-returns' ),
				'desc'    => __( 'Refund destinations offered to the customer when the return is approved.', 'zorem-returns' ),
				'id'      => 'refund_payment_options',
				'css'     => 'min-width:50px;',
				'default' => '',
				'show'    => true,
				'type'    => 'multiple_checkbox',
				'options' => $refund_payment_options,
				'class'   => '',
				'value'   => array( 'customer_account' => '1' ),
				'tooltip' => __( 'Select the available refund methods you want to offer customers.', 'zorem-returns' ),
			),
			'keep_item_partial_refund_percentage' => array(
				'title'       => __( 'Partial Refund Percentage', 'zorem-returns' ),
				'desc'        => __( 'Percentage of the item total refunded when the customer picks the "Keep the Item" resolution. Shown to the customer on the return form.', 'zorem-returns' ),
				'type'        => 'number',
				'class'       => 'keep_item_partial_refund_percentage_field return_days_width',
				'value'       => '15',
				'placeholder' => '15',
				'min'         => '1',
				'max'         => '100',
				'tooltip'     => __( 'Only used when "Keep the Item & Receive a Partial Refund" is enabled above. The plugin does not process this refund automatically — the admin issues it manually from the WooCommerce Edit Order page.', 'zorem-returns' ),
			),
			'refund_shipping_fee'      => array(
				'title'   => __( 'Include Shipping Cost in Refund', 'zorem-returns' ),
				'desc'    => __( 'Add the original shipping charge back into the refund total.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'no',
				'tooltip' => __( "Enable this option to include the original shipping fee in the customer's refund.", 'zorem-returns' ),
			),
			'restocking_fee_enable'    => array(
				'title'   => __( 'Enable Restocking Fee', 'zorem-returns' ),
				'desc'    => __( 'Deduct a fee from every approved refund; shown to the customer up-front.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => 'restocking_fee_enable_toggle',
				'value'   => 'no',
				'tooltip' => __( 'Deduct a restocking fee from the refund amount when a return is approved. The fee is shown to the customer before they submit their return request.', 'zorem-returns' ),
			),
			'restocking_fee_type'      => array(
				'title'      => __( 'Restocking Fee Type', 'zorem-returns' ),
				'desc'       => __( 'How the fee is calculated: a percentage of item price or a flat per-item amount.', 'zorem-returns' ),
				'type'       => 'select',
				'class'      => 'restocking_fee_type_field',
				'value'      => 'percentage',
				'show'       => true,
				'sub_option' => array(
					'percentage' => __( 'Percentage (%)', 'zorem-returns' ),
					'fixed'      => __( 'Fixed amount per item', 'zorem-returns' ),
				),
				'tooltip'    => __( 'Choose how the restocking fee is calculated. Percentage applies to each returned item\'s price; Fixed deducts a flat amount per returned item (multiplied by quantity).', 'zorem-returns' ),
			),
			'restocking_fee_amount'    => array(
				'title'       => __( 'Restocking Fee Amount', 'zorem-returns' ),
				'desc'        => __( 'Value of the fee — a number for percentage, or a currency amount for fixed.', 'zorem-returns' ),
				'type'        => 'text',
				'class'       => 'restocking_fee_amount_field',
				'value'       => '15',
				'placeholder' => '15',
				'tooltip'     => __( 'For percentage: enter a number (e.g., 15 for 15%). For fixed: enter the amount in your store currency (e.g., 5 for ₹5/$5 per item).', 'zorem-returns' ),
			),
			'restocking_fee_label'     => array(
				'title'       => __( 'Restocking Fee Label', 'zorem-returns' ),
				'desc'        => __( 'Text shown to the customer next to the fee line item.', 'zorem-returns' ),
				'type'        => 'text',
				'class'       => 'restocking_fee_label_field',
				'value'       => __( 'Restocking Fee', 'zorem-returns' ),
				'placeholder' => __( 'Restocking Fee', 'zorem-returns' ),
				'tooltip'     => __( 'Label shown to the customer in the return summary, admin dashboard and refund emails.', 'zorem-returns' ),
			),
			'return_disabled_payment_methods' => array(
				'title'   => __( 'Disable Returns for Payment Methods', 'zorem-returns' ),
				'desc'    => __( 'Hide the Return button for orders paid via any of the selected gateways.', 'zorem-returns' ),
				'type'    => 'multi_select',
				'class'   => 'wc-enhanced-select input_settings_menu',
				'value'   => array(),
				'options' => $enabled_payment_gateways,
				'tooltip' => __( 'Select payment gateways that are not eligible for returns. The Return button will be hidden for orders paid with these methods (e.g., Cash on Delivery).', 'zorem-returns' ),
			),
			'return_disabled_payment_message' => array(
				'title'       => __( 'Return Disabled Message', 'zorem-returns' ),
				'desc'        => __( 'Customer-facing text shown when the order was paid via a disabled gateway.', 'zorem-returns' ),
				'type'        => 'textarea',
				'class'       => 'zorem-disabled-payment-message',
				'value'       => __( 'Returns are not available for Cash on Delivery orders. Please contact us directly.', 'zorem-returns' ),
				'placeholder' => __( 'Returns are not available for Cash on Delivery orders. Please contact us directly.', 'zorem-returns' ),
				'tooltip'     => __( 'Message shown to customers when their order is paid via a disabled payment method.', 'zorem-returns' ),
			),
			'return_guideline_content' => array(
				'title'   => __( 'Return Guidelines', 'zorem-returns' ),
				'desc'    => __( 'Instructions included in the return-approved email sent to the customer.', 'zorem-returns' ),
				'type'    => 'text_editor',
				'class'   => 'return_guideline_content',
				'value'   => __( '<strong>Return Guidelines:</strong><ul><li>Ensure all items being returned are securely packaged to prevent damage during transit.</li><li>Use a reliable shipping service to send the package back to us. We recommend using a service that provides tracking information.</li><li>Once your return package is shipped, please notify us by replying to this email with the tracking number.</li><li>Upon receiving the returned items, we will inspect them to ensure they meet our return policy criteria.</li><li>Refunds or store credits will be processed within 5–7 business days of receiving the returned items.</li></ul>', 'zorem-returns' ),
				'tooltip' => __( 'Provide detailed guidelines that customers must follow when returning items.', 'zorem-returns' ),
			),
			'return_reasons'           => array(
				'title'           => __( 'Return Reasons', 'zorem-returns' ),
				'desc'            => __( 'Options shown in the reason dropdown on the customer return form.', 'zorem-returns' ),
				'type'            => 'todo-list',
				'class'           => 'input_settings_menu',
				'draggable'       => true,
				'contenteditable' => true,
				'value'           => $default_return_reasons,
				'placeholder'     => __( 'Add Reason', 'zorem-returns' ),
				'tooltip'         => __( 'Add or remove custom reasons that customers can select when requesting an return.', 'zorem-returns' ),
			),
		);
		return $settings;
	}


	/*
	* settings array
	*/
	public function exchange_option_settings() {

		$default_exchange_reasons = array(
			'Wrong Size or Fit',
			'Wrong Color or Style',
			'Wrong item was sent',
			"Item defective or doesn't work",
			'Items or parts missing',
		);

		$enabled_payment_gateways = $this->zre_get_enabled_payment_gateways();

		$settings = array(
			'exchange_enable'                 => array(
				'title'   => __( 'Enable Exchange Requests', 'zorem-returns' ),
				'desc'    => __( 'Master switch — hides the entire exchange workflow when off.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'yes',
				'tooltip' => __( 'Enable or disable the exchange request option for customers.', 'zorem-returns' ),
			),
			'exchange_onsale_items'           => array(
				'title'   => __( 'Allow exchanges for sale products', 'zorem-returns' ),
				'desc'    => __( 'Include sale-priced items in the exchange workflow.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'no',
				'tooltip' => __( 'Allows customers to request exchanges for items purchased on sale', 'zorem-returns' ),
			),
			'automatic_exchange'              => array(
				'title'   => __( 'Automatically Approve Exchange Requests', 'zorem-returns' ),
				'desc'    => __( 'Skip manual review — every new exchange is approved on submission.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'no',
				'tooltip' => __( 'Enable this option to automatically approve all new exchange requests.', 'zorem-returns' ),
			),
			'exchange_entire_order'           => array(
				'title'   => __( 'Allow Partial Exchange', 'zorem-returns' ),
				'desc'    => __( 'Customer can pick individual items to exchange rather than the whole order.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'no',
				'tooltip' => __( 'Allows customers to request partial exchanges for individual items in an order.', 'zorem-returns' ),
			),
			'show_start_exchange_button'      => array(
				'title'   => __( 'Show "Start an Exchange" Button', 'zorem-returns' ),
				'desc'    => __( 'Display the whole-order "Start an Exchange" button on the customer\'s order page.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'yes',
				'tooltip' => __( 'Disable to hide the whole-order "Start an Exchange" button — the per-item Exchange Item buttons are not affected.', 'zorem-returns' ),
			),
			'allow_cross_product_exchange'    => array(
				'title'   => __( 'Allow Cross-Product Exchange', 'zorem-returns' ),
				'desc'    => __( 'Customer can exchange for any catalog product, not just other variations.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'no',
				'tooltip' => __( 'Allow customers to exchange for any product in the catalog, not just the same product\'s variations.', 'zorem-returns' ),
			),
			'show_exchange_swap_in_customer_emails' => array(
				'title'   => __( 'Show Original → Requested Exchange Table in Customer Emails', 'zorem-returns' ),
				'desc'    => __( 'Include a side-by-side comparison of original vs. requested product in emails.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'no',
				'tooltip' => __( 'When enabled, customer exchange emails (requested/approved/completed/rejected) include a comparison table showing the original product alongside the requested exchange product. When disabled, only the basic original-item summary is shown.', 'zorem-returns' ),
			),
			'cross_exchange_price_action'     => array(
				'title'      => __( 'When new item costs more', 'zorem-returns' ),
				'desc'       => __( 'How the price difference is collected when the replacement is more expensive.', 'zorem-returns' ),
				'id'         => 'cross_exchange_price_action',
				'type'       => 'select',
				'class'      => '',
				'css'        => 'min-width:300px;',
				'default'    => 'pending_payment',
				'value'      => 'pending_payment',
				'show'       => true,
				'sub_option' => array(
					'pending_payment' => __( 'Create pending payment order for the difference', 'zorem-returns' ),
					'ship_first'      => __( 'Ship first and invoice later', 'zorem-returns' ),
				),
				'tooltip'    => __( 'Choose what happens when the replacement product costs more than the original item.', 'zorem-returns' ),
			),
			'cross_exchange_credit_action'    => array(
				'title'      => __( 'When new item costs less', 'zorem-returns' ),
				'desc'       => __( 'How the leftover balance is handled when the replacement is cheaper.', 'zorem-returns' ),
				'id'         => 'cross_exchange_credit_action',
				'type'       => 'select',
				'class'      => '',
				'css'        => 'min-width:300px;',
				'default'    => 'store_credit',
				'value'      => 'store_credit',
				'show'       => true,
				'sub_option' => array(
					'store_credit' => __( 'Issue store credit coupon for the balance', 'zorem-returns' ),
					'no_credit'    => __( 'No credit — discard the difference', 'zorem-returns' ),
				),
				'tooltip'    => __( 'Choose what happens when the replacement product costs less than the original item.', 'zorem-returns' ),
			),
			'cross_exchange_credit_expiry'    => array(
				'title'       => __( 'Store credit coupon expiry (days)', 'zorem-returns' ),
				'desc'        => __( 'Lifetime of the issued store-credit coupon (0 = never expires).', 'zorem-returns' ),
				'type'        => 'number',
				'class'       => '',
				'css'         => 'width:80px;',
				'value'       => '0',
				'placeholder' => '0',
				'min'         => '0',
				'max'         => '365',
				'tooltip'     => __( 'Number of days before the store credit coupon expires. Enter 0 for no expiry.', 'zorem-returns' ),
			),
			'exchange_preset_shipping_method' => array(
				'title'      => __( 'Preset Shipping Method for Exchange Orders', 'zorem-returns' ),
				'desc'       => __( 'Shipping method automatically applied to child exchange orders.', 'zorem-returns' ),
				'id'         => 'exchange_preset_shipping_method',
				'type'       => 'select',
				'class'      => '',
				'css'        => 'min-width:300px;',
				'default'    => '',
				'value'      => '',
				'show'       => true,
				'sub_option' => $this->zre_get_exchange_shipping_methods(),
				'tooltip'    => __( 'Select the shipping method that should automatically be applied to exchange orders created by the plugin. If none selected, WooCommerce default shipping method will be used.', 'zorem-returns' ),
			),
			'exchange_shipping_label'         => array(
				'title'       => __( 'Exchange Shipping Label', 'zorem-returns' ),
				'desc'        => __( 'Custom shipping line applied only to exchange orders. When set, it is used instead of the preset method above.', 'zorem-returns' ),
				'id'          => 'exchange_shipping_label',
				'type'        => 'text',
				'class'       => '',
				'css'         => 'min-width:300px;',
				'value'       => '',
				'placeholder' => __( 'Exchange Shipping', 'zorem-returns' ),
				'tooltip'     => __( 'Because this is not a WooCommerce shipping method, it never appears at cart/checkout, and carrier plugins (e.g. ReachShip) stay available on the exchange order for label generation. Leave empty to use the preset method above.', 'zorem-returns' ),
			),
			'exchange_shipping_cost'         => array(
				'title'       => __( 'Exchange Shipping Cost', 'zorem-returns' ),
				'desc'        => __( 'Cost for the custom exchange shipping line above (0 = free).', 'zorem-returns' ),
				'id'          => 'exchange_shipping_cost',
				'type'        => 'number',
				'class'       => '',
				'css'         => 'width:120px;',
				'value'       => '',
				'placeholder' => '0',
				'min'         => '0',
				'tooltip'     => __( 'The amount charged for the custom exchange shipping line. Applies only when an Exchange Shipping Label is set.', 'zorem-returns' ),
			),
			'exchange_disabled_payment_methods' => array(
				'title'   => __( 'Disable Exchanges for Payment Methods', 'zorem-returns' ),
				'desc'    => __( 'Hide the Exchange button for orders paid via any of the selected gateways.', 'zorem-returns' ),
				'type'    => 'multi_select',
				'class'   => 'wc-enhanced-select input_settings_menu',
				'value'   => array(),
				'options' => $enabled_payment_gateways,
				'tooltip' => __( 'Select payment gateways that are not eligible for exchanges. The Exchange button will be hidden for orders paid with these methods (e.g., Cash on Delivery).', 'zorem-returns' ),
			),
			'exchange_disabled_payment_message' => array(
				'title'       => __( 'Exchange Disabled Message', 'zorem-returns' ),
				'desc'        => __( 'Customer-facing text shown when the order was paid via a disabled gateway.', 'zorem-returns' ),
				'type'        => 'textarea',
				'class'       => 'zorem-disabled-payment-message',
				'value'       => __( 'Exchanges are not available for Cash on Delivery orders. Please contact us directly.', 'zorem-returns' ),
				'placeholder' => __( 'Exchanges are not available for Cash on Delivery orders. Please contact us directly.', 'zorem-returns' ),
				'tooltip'     => __( 'Message shown to customers when their order is paid via a disabled payment method.', 'zorem-returns' ),
			),
			'exchange_guideline_content'      => array(
				'title'   => __( 'Exchange Guidelines', 'zorem-returns' ),
				'desc'    => __( 'Instructions included in the exchange-approved email sent to the customer.', 'zorem-returns' ),
				'type'    => 'text_editor',
				'class'   => 'exchange_guideline_content',
				'value'   => __( '<strong>Exchange Guidelines:</strong><ul><li>Securely package the item you are returning for exchange to prevent damage during transit. Use the original packaging if possible.</li><li>Use a reliable shipping service to send the package back to us. We recommend a service that provides tracking information. This helps ensure the package reaches us safely and allows you to track its status.</li><li>After shipping your exchange package, please notify us by replying to the initial email or contacting our support team with the tracking number.</li><li>Upon receiving the exchanged items, we will inspect them to ensure they meet our exchange policy criteria. If the items are in good condition and meet the criteria, we will process the exchange.</li><li>Once the exchange is approved, we will ship the replacement item to you. You will receive a confirmation email with tracking information once the new item is dispatched.</li><li>Exchanges will be processed within 5-7 business days of receiving the returned items. The shipping time for the new item may vary depending on your location.</li></ul>', 'zorem-returns' ),
				'tooltip' => __( 'Provide detailed guidelines that customers must follow when sending items for exchange.', 'zorem-returns' ),
			),
			'exchange_reasons'                => array(
				'title'           => __( 'Exchange Reasons', 'zorem-returns' ),
				'desc'            => __( 'Options shown in the reason dropdown on the customer exchange form.', 'zorem-returns' ),
				'type'            => 'todo-list',
				'class'           => 'input_settings_menu',
				'draggable'       => true,
				'contenteditable' => true,
				'value'           => $default_exchange_reasons,
				'placeholder'     => __( 'Add Reason', 'zorem-returns' ),
				'tooltip'         => __( 'Add or remove custom reasons that customers can select when requesting an exchange.', 'zorem-returns' ),
			),
		);
		return $settings;
	}

	/*
	* Fraud Prevention — all fields, ordered for the save callback.
	* Rendering is split via the sub-section methods below so the Bulk Upload row
	* can be inserted between Customer Blocklist and Silently Hide Return Button.
	*/
	public function fraud_prevention_settings() {
		return array_merge(
			$this->fraud_prevention_section_master(),
			$this->fraud_prevention_section_limit(),
			$this->fraud_prevention_section_blocklist_before_upload(),
			$this->fraud_prevention_section_blocklist_after_upload()
		);
	}

	/**
	 * Section 1 — master on/off toggle for the whole fraud prevention feature.
	 */
	public function fraud_prevention_section_master() {
		return array(
			'fraud_prevention_enable' => array(
				'title'   => __( 'Enable Fraud Prevention', 'zorem-returns' ),
				'desc'    => __( 'Master switch for the per-customer return limit and the blocklist.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => 'fraud_prevention_enable_toggle',
				'value'   => 'no',
				'tooltip' => __( 'Turn on per-customer return limits and the blocklist. When disabled, no fraud prevention checks are run.', 'zorem-returns' ),
			),
		);
	}

	/**
	 * Section 2 — per-customer return limit and the admin-notification fields
	 * (admin email only fires when the limit is enabled, so they belong together).
	 *
	 * Kept as a merger of the split methods so the save-callback keeps
	 * seeing the same key set.
	 */
	public function fraud_prevention_section_limit() {
		return array_merge(
			$this->fraud_prevention_section_limit_toggle(),
			$this->fraud_prevention_section_limit_fields(),
			$this->fraud_prevention_section_admin_notify_toggle(),
			$this->fraud_prevention_section_admin_notify_field()
		);
	}

	/**
	 * Section 2a — the "Enable Per-Customer Return Limit" master toggle only.
	 */
	public function fraud_prevention_section_limit_toggle() {
		return array(
			'fraud_prevention_limit_enable'  => array(
				'title'   => __( 'Enable Per-Customer Return Limit', 'zorem-returns' ),
				'desc'    => __( 'Cap how many returns a single customer can submit inside a rolling window.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => 'fraud_prevention_limit_enable_toggle',
				'value'   => 'no',
				'tooltip' => __( 'Limit how many returns a single customer can submit within a rolling time window.', 'zorem-returns' ),
			),
		);
	}

	/**
	 * Section 2b — the fields shown only when the limit is enabled.
	 */
	public function fraud_prevention_section_limit_fields() {
		$default_limit_message = __( 'You have reached the maximum of %1$d returns within the last %2$d days. Please contact our support team for assistance.', 'zorem-returns' );

		return array(
			'fraud_prevention_max_returns'   => array(
				'title'       => __( 'Maximum Returns per Customer', 'zorem-returns' ),
				'desc'        => __( 'How many return requests a customer may submit before being blocked.', 'zorem-returns' ),
				'type'        => 'number',
				'class'       => 'fraud_prevention_max_returns_field return_days_width',
				'value'       => '3',
				'placeholder' => '3',
				'min'         => '1',
				'max'         => '100',
				'tooltip'     => __( 'Maximum number of return requests a single customer is allowed within the time window below.', 'zorem-returns' ),
			),
			'fraud_prevention_window_days'   => array(
				'title'       => __( 'Time Window (Days)', 'zorem-returns' ),
				'desc'        => __( 'Rolling look-back window used to count a customer\'s recent returns.', 'zorem-returns' ),
				'type'        => 'number',
				'class'       => 'fraud_prevention_window_days_field return_days_width',
				'value'       => '90',
				'placeholder' => '90',
				'min'         => '1',
				'max'         => '365',
				'tooltip'     => __( 'Rolling window, in days, used to count a customer\'s recent returns (e.g. 90 = the last 90 days).', 'zorem-returns' ),
			),
			'fraud_prevention_limit_message' => array(
				'title'       => __( 'Limit-Reached Message', 'zorem-returns' ),
				'desc'        => __( 'Message shown to customers who exceed the limit above.', 'zorem-returns' ),
				'type'        => 'textarea',
				'class'       => 'fraud_prevention_limit_message_field',
				'value'       => $default_limit_message,
				'placeholder' => $default_limit_message,
				'tooltip'     => __( 'Message shown to a customer who has reached the return limit. You may use %1$d for the max count and %2$d for the window in days.', 'zorem-returns' ),
			),
		);
	}

	/**
	 * Section 2c — "Email Admin When Limit Is Hit" toggle only.
	 */
	public function fraud_prevention_section_admin_notify_toggle() {
		return array(
			'fraud_prevention_admin_notify'  => array(
				'title'   => __( 'Email Admin When Limit Is Hit', 'zorem-returns' ),
				'desc'    => __( 'Send an admin alert each time a customer trips the return limit.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => 'fraud_prevention_admin_notify_toggle',
				'value'   => 'no',
				'tooltip' => __( 'Send a notification email when a customer reaches the configured per-customer return limit. Throttled to one email per customer per day.', 'zorem-returns' ),
			),
		);
	}

	/**
	 * Section 2d — Admin Notification Email field (shown only when admin_notify is on).
	 */
	public function fraud_prevention_section_admin_notify_field() {
		return array(
			'fraud_prevention_admin_email'   => array(
				'title'       => __( 'Admin Notification Email', 'zorem-returns' ),
				'desc'        => __( 'Recipient of the limit-reached notification. Leave blank to use the site admin email.', 'zorem-returns' ),
				'type'        => 'text',
				'class'       => 'fraud_prevention_admin_email_field',
				'value'       => '',
				'placeholder' => get_option( 'admin_email' ),
				'tooltip'     => __( 'Email address to receive limit-reached notifications. Leave blank to use the WordPress site admin email.', 'zorem-returns' ),
			),
		);
	}

	/**
	 * Section 3a — blocklist textarea (rendered BEFORE the Bulk Upload row).
	 */
	public function fraud_prevention_section_blocklist_before_upload() {
		return array(
			'fraud_prevention_blocklist' => array(
				'title'       => __( 'Customer Blocklist', 'zorem-returns' ),
				'desc'        => __( 'Emails or numeric user IDs banned from starting a return. One per line.', 'zorem-returns' ),
				'type'        => 'textarea',
				'class'       => 'fraud_prevention_blocklist_field',
				'value'       => '',
				'placeholder' => "customer@example.com\n1234",
				'tooltip'     => __( 'One entry per line. Accepts customer email addresses OR numeric user IDs. Blocked customers cannot submit return requests.', 'zorem-returns' ),
			),
		);
	}

	/**
	 * Section 3b — silent hide toggle + blocklist message (rendered AFTER the Bulk Upload row).
	 */
	public function fraud_prevention_section_blocklist_after_upload() {
		$default_block_message = __( 'Returns are not available for your account. Please contact our support team for assistance.', 'zorem-returns' );

		return array(
			'fraud_prevention_silent_block'  => array(
				'title'   => __( 'Silently Hide Return Button', 'zorem-returns' ),
				'desc'    => __( 'Hide the Return button with no message (bypasses the Blocklist Message below).', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => '',
				'value'   => 'no',
				'tooltip' => __( 'When enabled, the Start a Return button is hidden without any message for blocked customers / customers over the limit. When disabled (default), the message above is shown.', 'zorem-returns' ),
			),
			'fraud_prevention_block_message' => array(
				'title'       => __( 'Blocklist Message', 'zorem-returns' ),
				'desc'        => __( 'Message shown to blocked customers when they try to start a return.', 'zorem-returns' ),
				'type'        => 'textarea',
				'class'       => 'fraud_prevention_block_message_field',
				'value'       => $default_block_message,
				'placeholder' => $default_block_message,
				'tooltip'     => __( 'Message shown to customers who appear on the blocklist when they try to start a return.', 'zorem-returns' ),
			),
		);
	}

	/*
	* Warranty settings — enable the workflow + global default warranty period + reasons.
	*/
	public function warranty_settings() {
		return array_merge(
			$this->warranty_section_master(),
			$this->warranty_section_body()
		);
	}

	/**
	 * Warranty — master enable toggle only.
	 */
	public function warranty_section_master() {
		return array(
			'warranty_enable'       => array(
				'title'   => __( 'Enable Warranty Workflow', 'zorem-returns' ),
				'desc'    => __( 'Add a warranty claim button after the standard return window has expired.', 'zorem-returns' ),
				'type'    => 'checkbox',
				'class'   => 'warranty_enable_toggle',
				'value'   => 'no',
				'tooltip' => __( 'Show a "Submit Warranty Claim" button on customer orders after the standard return window has expired (while the product is still under warranty).', 'zorem-returns' ),
			),
		);
	}

	/**
	 * Warranty — fields shown only when the workflow is enabled.
	 */
	public function warranty_section_body() {
		$default_reasons = array(
			__( 'Stopped working', 'zorem-returns' ),
			__( 'Manufacturing defect', 'zorem-returns' ),
			__( 'Broken or damaged', 'zorem-returns' ),
			__( 'Missing parts after some use', 'zorem-returns' ),
			__( 'Performance issue', 'zorem-returns' ),
		);

		return array(
			'warranty_default_days' => array(
				'title'       => __( 'Default Warranty Period (Days)', 'zorem-returns' ),
				'desc'        => __( 'Fallback warranty length when a product or category doesn\'t override it. 0 disables by default.', 'zorem-returns' ),
				'type'        => 'number',
				'class'       => 'warranty_default_days_field return_days_width',
				'value'       => '365',
				'placeholder' => '365',
				'min'         => '0',
				'max'         => '3650',
				'tooltip'     => __( 'Default warranty length in days. Used when a product or category does not set its own. 0 = no warranty by default.', 'zorem-returns' ),
			),
			'warranty_reasons'      => array(
				'title'           => __( 'Warranty Reasons', 'zorem-returns' ),
				'desc'            => __( 'Options shown in the reason dropdown on the warranty claim form.', 'zorem-returns' ),
				'type'            => 'todo-list',
				'class'           => 'input_settings_menu',
				'draggable'       => true,
				'contenteditable' => true,
				'value'           => $default_reasons,
				'placeholder'     => __( 'Add Reason', 'zorem-returns' ),
				'tooltip'         => __( 'List of reasons a customer can pick when filing a warranty claim.', 'zorem-returns' ),
			),
		);
	}

	/**
	 * Get all enabled WooCommerce payment gateways for use in settings.
	 *
	 * @return array Associative array of gateway_id => gateway_title for currently enabled gateways.
	 */
	public function zre_get_enabled_payment_gateways() {
		$gateways = array();
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return $gateways;
		}
		$available = WC()->payment_gateways()->payment_gateways();
		if ( ! is_array( $available ) ) {
			return $gateways;
		}
		foreach ( $available as $gateway ) {
			if ( isset( $gateway->enabled ) && 'yes' === $gateway->enabled ) {
				$gateways[ $gateway->id ] = $gateway->get_title();
			}
		}
		return $gateways;
	}

	/**
	 * Get all enabled WooCommerce shipping methods grouped by zone
	 */
	public function zre_get_exchange_shipping_methods() {
		$methods = array(
			'' => __( 'Select Shipping Method', 'zorem-returns' ),
		);

		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return $methods;
		}

		$zones = WC_Shipping_Zones::get_zones();

		foreach ( $zones as $zone ) {
			if ( empty( $zone['shipping_methods'] ) ) {
				continue;
			}

			foreach ( $zone['shipping_methods'] as $method ) {
				if ( 'yes' !== $method->enabled ) {
					continue;
				}

				$key             = $method->id . ':' . $method->instance_id;
				$methods[ $key ] = $zone['zone_name'] . ' → ' . $method->get_title();
			}
		}

		// Rest of the world
		$default_zone = new WC_Shipping_Zone( 0 );
		foreach ( $default_zone->get_shipping_methods( true ) as $method ) {
			if ( 'yes' !== $method->enabled ) {
				continue;
			}

			$key             = $method->id . ':' . $method->instance_id;
			$methods[ $key ] = __( 'Rest of the world', 'zorem-returns' ) . ' → ' . $method->get_title();
		}

		return $methods;
	}

	/**
	 * Add 'Return' Custom order status
	 *
	 * @since 1.0
	 */
	public function register_return_order_status() {
		register_post_status(
			'wc-partial-return',
			array(
				'label'                     => _x( 'Partial Return', 'Partial Return', 'zorem-returns' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: search term */
				'label_count'               => _n_noop( 'Partial Return <span class="count">(%s)</span>', 'Partial Return <span class="count">(%s)</span>', 'zorem-returns' ),
			)
		);

		register_post_status(
			'wc-return',
			array(
				'label'                     => _x( 'Return', 'Return', 'zorem-returns' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: search term */
				'label_count'               => _n_noop( 'Return <span class="count">(%s)</span>', 'Return <span class="count">(%s)</span>', 'zorem-returns' ),
			)
		);
	}

	// Add to list of WC Order statuses
	public function add_return_order_status( $order_statuses ) {

		$order_statuses['wc-return']         = esc_html__( 'Return', 'zorem-returns' );
		$order_statuses['wc-partial-return'] = esc_html__( 'Partial Return', 'zorem-returns' );

		return $order_statuses;
	}

	// Add bulk action change status to custom order status
	public function add_bulk_actions_change_order_status( $bulk_actions ) {

		$bulk_actions['mark_return']         = esc_html__( 'Change status to return', 'zorem-returns' );
		$bulk_actions['mark_partial-return'] = esc_html__( 'Change status to partial return', 'zorem-returns' );

		return $bulk_actions;
	}

	/**
	 * Disable email notifications for return order status
	 *
	 * @since 1.0
	 */
	public function disable_return_order_status_email_notification( $email_actions ) {
		unset( $email_actions['woocommerce_order_status_return'] );
		unset( $email_actions['woocommerce_order_status_partial-return'] );
		return $email_actions;
	}

	/**
	 * Output product options for force local pickup
	 */
	public function product_options() {
		global $post, $wpdb;

		echo '</div><div class="options_group per_product_keep_item">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_per_product_keep_item',
				'label'       => esc_html__( 'Keep the Item', 'zorem-returns' ),
				'description' => esc_html__( 'Allow the customer to keep this item when a return or exchange is requested', 'zorem-returns' ),
			)
		);

		wp_nonce_field( 'save_per_product_keep_item', 'per_product_keep_item_nonce' );
	}

	/*
	* Save the product meta settings of keep the item for simple product
	*
	* @para $post_id
	*/
	public function save_custom_product_fields( $post_id ) {
		$nonce = isset( $_POST['per_product_keep_item_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['per_product_keep_item_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'save_per_product_keep_item' ) ) {
			wp_die( esc_html__( 'Invalid request. Security check failed.', 'zorem-returns' ) );
		}
		$_per_product_keep_item = ! empty( $_POST['_per_product_keep_item'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_per_product_keep_item', $_per_product_keep_item );
	}

	public function check_hpos_enabled() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
				return true;
			}
		}
		return false;
	}
}
