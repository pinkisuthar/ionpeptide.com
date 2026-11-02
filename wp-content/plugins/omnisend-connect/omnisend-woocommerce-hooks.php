<?php
/**
 * Omnisend WooCommerce Hook Functions
 *
 * @package OmnisendPlugin
 */

defined( 'ABSPATH' ) || exit;

$picker_product_set = false;

/* Product page - add Product Picker */
add_action( 'woocommerce_after_single_product', 'omnisend_product_picker', 5 );
function omnisend_product_picker() {
	Omnisend_Logger::hook();
	global $picker_product_set;
	if ( ! $picker_product_set ) {
		$picker_product_set = true;
		Omnisend_Product::product_picker();
	}
}

/* CONTACTS */

add_action( 'profile_update', 'omnisend_on_user_update', 10, 2 );
function omnisend_on_user_update( $user_id ) {
	Omnisend_Logger::hook();
	Omnisend_Contact_Resolver::update_by_user_id( $user_id );
}

add_action( 'user_register', 'omnisend_on_user_register', 10, 1 );
function omnisend_on_user_register( $user_id ) {
	Omnisend_Logger::hook();
	Omnisend_Contact_Resolver::update_by_user_id( $user_id );
}

/* CARTS */
/* Hook for triggering action when order created. Resets cart ID after order is created */
add_action( 'woocommerce_checkout_update_order_meta', 'omnisend_reset_cart', 20, 2 );
function omnisend_reset_cart() {
	Omnisend_Logger::hook();
	Omnisend_Cart::reset();
}

add_action( 'woocommerce_add_to_cart', 'omnisend_track_add_to_cart_event', 25, 5 );
function omnisend_track_add_to_cart_event( $cart_item_key, $product_id, $request_quantity, $variation_id, $variation ) {
	Omnisend_Logger::hook();
	Omnisend_Cart_Event::added_product_to_cart( gmdate( DATE_ATOM, time() ), $cart_item_key, $product_id, $variation_id, $variation, $request_quantity );
}

add_action( 'wp_enqueue_scripts', 'omnisend_add_snippet_script' );
function omnisend_add_snippet_script() {
	if ( Omnisend_Helper::is_woocommerce_plugin_activated() && Omnisend_Helper::check_wp_wc_compatibility() ) {
		$omnisend_account_id = get_option( 'omnisend_account_id', null );

		if ( $omnisend_account_id !== null ) {
			Omnisend_Logger::hook();

			$omnisend_plugin_version = Omnisend_Helper::omnisend_plugin_version();
			$formatted_date          = gmdate( 'Y-m-d\\TH' );

			$file_name = 'woocommerce.js';
			$file_path = OMNISEND_SNIPPET_URL . '?brandID=' . $omnisend_account_id . '&v=' . $formatted_date;

			wp_register_script( $file_name, $file_path, array(), $omnisend_plugin_version, true );
			wp_localize_script(
				$file_name,
				'omnisend_snippet_vars',
				array(
					'brand_id'       => $omnisend_account_id,
					'plugin_version' => $omnisend_plugin_version,
					'home_url'       => home_url(),
				)
			);
			wp_enqueue_script( $file_name, $file_path, array(), $omnisend_plugin_version, true );
		}
	}
}

add_action( 'wp_enqueue_scripts', 'omnisend_add_checkout_script' );
function omnisend_add_checkout_script() {
	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		Omnisend_Logger::hook();
		$file_name = 'omnisend-checkout-script.js';
		$file_path = plugin_dir_url( __FILE__ ) . 'assets/js/' . $file_name;
		$version   = Omnisend_Helper::asset_version( 'assets/js/' . $file_name );
		wp_register_script( $file_name, $file_path, array(), $version, true );
		wp_localize_script(
			$file_name,
			'omnisend_checkout_vars',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'omnisend-checkout-script-nonce' ),
			)
		);
		wp_enqueue_script( $file_name, $file_path, array(), $version, true );
	}
}

/**
 * Modify order webhook payload
 *
 * Add order status URL from Order via get_view_order_url() method
 *
 * @since 1.11.7
 */
function omnisend_add_order_status_url_to_webhook_payload( $payload, $resource_type ) {
	if ( $resource_type !== 'order' ) {
		return $payload;
	}

	if ( ! isset( $payload['id'] ) ) {
		return $payload;
	}

	$order = wc_get_order( $payload['id'] );
	if ( ! empty( $order ) && $order->get_user() ) {
		$order_url                            = esc_url( $order->get_view_order_url() );
		$payload['omnisend_order_status_url'] = $order_url ? $order_url : null;
	}

	return $payload;
}
add_filter( 'woocommerce_webhook_payload', 'omnisend_add_order_status_url_to_webhook_payload', 10, 4 );


/**
 * Identify user after login - save cookie
 */
function omnisend_wplogin( $user_login, $user ) {
	Omnisend_Logger::hook();
	Omnisend_Contact_Resolver::update_by_user_id( $user->ID );
}

add_action( 'wp_login', 'omnisend_wplogin', 10, 2 );

/* Add code snippet to the footer, if account ID is set */
add_action(
	'wp_footer',
	function () {
		global $picker_product_set;

		if ( Omnisend_Helper::is_woocommerce_plugin_activated() && Omnisend_Helper::check_wp_wc_compatibility() ) {
			$omnisend_account_id = get_option( 'omnisend_account_id', null );

			if ( $omnisend_account_id !== null ) {
				if ( is_product() && ! $picker_product_set ) {
					$picker_product_set = true;

					Omnisend_Product::product_picker();
				}
			}
		}
	}
);

/* Add verification tag */
add_action(
	'wp_head',
	function () {
		if ( Omnisend_Helper::is_woocommerce_plugin_activated() && Omnisend_Helper::check_wp_wc_compatibility() ) {

			$omnisend_account_id = get_option( 'omnisend_account_id', null );
			if ( $omnisend_account_id !== null ) {
				?>
				<meta name="omnisend-site-verification" content="<?php echo esc_attr( get_option( 'omnisend_account_id', null ) ); ?>"/>
				<?php
			}
		}
	}
);

function omnisend_checkbox_custom_checkout_field( $checkout ) {
	Omnisend_Logger::hook();

	$connected = Omnisend_Helper::is_omnisend_connected();

	if ( ! $connected ) {
		return;
	}

	$newsletter_enabled = Omnisend_Settings::get_checkout_opt_in_status() === Omnisend_Settings::STATUS_ENABLED && Omnisend_Settings::get_checkout_opt_in_text();
	$sms_enabled        = Omnisend_Settings::get_checkout_sms_opt_in_status() === Omnisend_Settings::STATUS_ENABLED && Omnisend_Settings::get_checkout_sms_opt_in_text();

	if ( $newsletter_enabled ) {
		woocommerce_form_field(
			'omnisend_newsletter_checkbox',
			array(
				'type'     => 'checkbox',
				'class'    => array( 'omnisend_newsletter_checkbox_field' ),
				'label'    => Omnisend_Settings::get_checkout_opt_in_text(),
				'value'    => true,
				'default'  => Omnisend_Settings::get_checkout_opt_in_preselected_status() === Omnisend_Settings::STATUS_ENABLED ? 1 : 0,
				'required' => false,
			),
			$checkout->get_value( 'omnisend_newsletter_checkbox' )
		);
	}

	if ( $sms_enabled ) {
		woocommerce_form_field(
			'omnisend_sms_checkbox',
			array(
				'type'     => 'checkbox',
				'class'    => array( 'omnisend_sms_checkbox_field' ),
				'label'    => Omnisend_Settings::get_checkout_sms_opt_in_text(),
				'value'    => true,
				'default'  => 0,
				'required' => false,
			),
			$checkout->get_value( 'omnisend_sms_checkbox' )
		);
	}
}

function omnisend_update_contact_status( $order_id ) {
	Omnisend_Logger::hook();

	// Nonce verification is not required here - we listen for woocommerce hook, where woocommerce verifies nonce.
	// phpcs:disable WordPress.Security.NonceVerification
	$newsletter_consent = isset( $_POST['omnisend_newsletter_checkbox'] ) && sanitize_text_field( wp_unslash( $_POST['omnisend_newsletter_checkbox'] ) );
	$sms_consent        = isset( $_POST['omnisend_sms_checkbox'] ) && sanitize_text_field( wp_unslash( $_POST['omnisend_sms_checkbox'] ) );
	// phpcs:enable WordPress.Security.NonceVerification

	if ( ! $sms_consent && ! $newsletter_consent ) {
		return; // consent was not provided.
	}

	$order = wc_get_order( $order_id );

	$newsletter_enabled = Omnisend_Settings::get_checkout_opt_in_status() === Omnisend_Settings::STATUS_ENABLED && Omnisend_Settings::get_checkout_opt_in_text();
	$sms_enabled        = Omnisend_Settings::get_checkout_sms_opt_in_status() === Omnisend_Settings::STATUS_ENABLED && Omnisend_Settings::get_checkout_sms_opt_in_text();

	if ( $newsletter_enabled && $newsletter_consent ) {
		$order->add_meta_data( 'marketing_opt_in_consent', 'checkout', true );
	}

	if ( $sms_enabled && $sms_consent ) {
		$order->add_meta_data( 'marketing_sms_opt_in_consent', 'checkout', true );
	}

	$tags = array( 'source: woocommerce' );
	$tag  = Omnisend_Settings::get_contact_tag_value();

	if ( $tag ) {
		$tags[] = $tag;
	}

	$order->add_meta_data( 'omnisend_contact_tags', $tags, true );

	$order->save();
}

// Add the checkbox field.
add_action( 'woocommerce_after_checkout_billing_form', 'omnisend_checkbox_custom_checkout_field' );
add_action( 'woocommerce_checkout_update_order_meta', 'omnisend_update_contact_status' );

add_action( 'omnisend_plugin_updated', 'omnisend_notify_about_plugin_update' );
add_action( 'omnisend_plugin_updated', 'omnisend_setup_omnisend_settings' );
add_action( 'omnisend_plugin_updated', 'omnisend_update_plugin_information' );
add_action( 'omnisend_plugin_updated', 'omnisend_setup_omnisend_tables' );
add_action( 'omnisend_wordpress_updated', 'omnisend_update_plugin_information' );

function omnisend_notify_about_plugin_update() {
	Omnisend_Logger::info( 'notifying about update' );
	Omnisend_Install::notify_about_plugin_update();
}

function omnisend_setup_omnisend_settings() {
	Omnisend_Settings::add_contact_tag( '', Omnisend_Settings::SOURCE_DEFAULT );
	Omnisend_Settings::add_contact_tag_status( omnisend_map_value_to_status( Omnisend_Settings::get_contact_tag() ), Omnisend_Settings::SOURCE_DEFAULT );

	Omnisend_Settings::add_checkout_opt_in_text( '', Omnisend_Settings::SOURCE_DEFAULT );
	Omnisend_Settings::add_checkout_opt_in_status( omnisend_map_value_to_status( Omnisend_Settings::get_checkout_opt_in_text() ), Omnisend_Settings::SOURCE_DEFAULT );
	Omnisend_Settings::add_checkout_opt_in_preselected_status( false );
}

function omnisend_update_plugin_information() {
	$api_key = get_option( 'omnisend_api_key', null );
	if ( ! empty( $api_key ) ) {
		Omnisend_Manager::update_account_info();
	}
}

function omnisend_setup_omnisend_tables() {
	Omnisend_Logger::ensure_table_exists();
	Omnisend_Contact_Cache::ensure_table_exists();
}

function omnisend_map_value_to_status( $value ) {
	return $value ? Omnisend_Settings::STATUS_ENABLED : Omnisend_Settings::STATUS_DISABLED;
}

add_action( 'plugins_loaded', 'omnisend_detect_environment_changes' );
function omnisend_detect_environment_changes() {
	$option_name = 'omnisend_environment';

	$current_environment  = wp_get_environment_type();
	$previous_environment = get_option( $option_name );

	if ( ! $previous_environment ) {
		Omnisend_Logger::info(
			'detected environment: ' . $current_environment . '. site: ' . Omnisend_Helper::get_domain( home_url() )
		);
	}

	if ( $previous_environment && $previous_environment !== $current_environment ) {
		Omnisend_Logger::info(
			"detected environment change $previous_environment -> $current_environment. site: " . Omnisend_Helper::get_domain( home_url() )
		);
		Omnisend_Install::disconnect();
	}

	update_option( $option_name, $current_environment );
}

add_action( 'plugins_loaded', 'omnisend_detect_domain_change' );
function omnisend_detect_domain_change() {
	$connected_domain_host = Omnisend_Helper::get_domain( get_option( 'omnisend_connected_domain' ) );
	$current_domain        = home_url();
	$current_domain_host   = Omnisend_Helper::get_domain( $current_domain );

	if ( $connected_domain_host && $connected_domain_host !== $current_domain_host ) {
		Omnisend_Logger::info( "detected site domain change $connected_domain_host -> $current_domain_host" );
	}

	update_option( 'omnisend_connected_domain', $current_domain );
}

add_action( 'plugins_loaded', 'omnisend_detect_plugin_updates' );
function omnisend_detect_plugin_updates() {

	$omnisend_plugin_version = Omnisend_Helper::omnisend_plugin_version();
	$version_db              = get_option( 'omnisend_plugin_version', '0.0.0' );

	if ( ! $omnisend_plugin_version ) {
		Omnisend_Logger::warning( "Cannot get Omnisend plugin version from file - version db: $version_db" );
		return;
	}

	if ( $omnisend_plugin_version != $version_db ) {
		update_option( 'omnisend_plugin_version', $omnisend_plugin_version );
		Omnisend_Logger::info( "Omnisend plugin updated - version db: $version_db  -> plugin version: $omnisend_plugin_version" );
	}

	if ( version_compare( $version_db, $omnisend_plugin_version, '<' ) ) {
		wp_schedule_single_event( time() + 60, 'omnisend_plugin_updated', array( $version_db, $omnisend_plugin_version ) );
	}
}

add_action( 'plugins_loaded', 'detect_omnisend_wordpress_updates' );
function detect_omnisend_wordpress_updates() {
	$omnisend_wordpress_version_db = get_option( 'omnisend_wp_version', null );
	$wordpress_version             = get_bloginfo( 'version' );

	if ( $omnisend_wordpress_version_db != $wordpress_version ) {
		update_option( 'omnisend_wp_version', $wordpress_version );
		Omnisend_Logger::info( "WordPress version updated $wordpress_version" );
		do_action( 'omnisend_wordpress_updated' );
	}
}


add_action( 'in_admin_header', 'omnisend_control_notices' );
function omnisend_control_notices(): void {
		$screen = get_current_screen();
	if ( $screen && ( $screen->id === 'toplevel_page_omnisend-woocommerce' ) ) {
		echo '<style>div[class*="notice"]:not([class*="components"], [class*="omnisend-notice"], .notice), .notice:not([class*="omnisend-notice"]) { display: none !important; }</style>';
	}
}

?>
