<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * BWFAN_Public class
 */
#[AllowDynamicProperties]
class BWFAN_Public {

	private static $ins = null;
	public $event_data = [];

	/**
	 * Store active automations by event slug
	 */
	public $active_automations = [];
	public $active_v2_automations = [];

	public function __construct() {
		/**
		 * Enqueue scripts
		 */
		add_action( 'wp_head', array( $this, 'enqueue_assets' ), 99 );
	}

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self;
		}

		return self::$ins;
	}

	/**
	 * Check is unsubscribe page
	 *
	 * @param array $settings
	 *
	 * @return bool
	 */
	public function is_unsubscribe_page( $settings = [] ) {
		if ( empty( $settings['bwfan_unsubscribe_page'] ) ) {
			return false;
		}

		return is_page( intval( $settings['bwfan_unsubscribe_page'] ) );
	}

	/**
	 * Check is manage profile page
	 *
	 * @param array $settings
	 *
	 * @return bool
	 */
	public function is_manage_profile_page( $settings = [] ) {
		if ( empty( $settings['bwfan_profile_page'] ) ) {
			global $post;
			$page_content = $post instanceof WP_Post ? get_post_field( 'post_content', $post->ID ) : '';

			return has_shortcode( $page_content, 'fka_contact_profile_form' );
		}

		return is_page( intval( $settings['bwfan_profile_page'] ) );
	}

	public function enqueue_assets( $setting = [] ) {
		$setting_data = empty( $setting ) ? BWFAN_Common::get_global_settings() : $setting;

		/** Any page containing the WC Checkout block (not just the WC-designated checkout page). */
		$has_checkout_block = false;
		if ( bwfan_is_woocommerce_active() && function_exists( 'has_block' ) ) {
			$queried_id = function_exists( 'get_queried_object_id' ) ? get_queried_object_id() : 0;
			if ( $queried_id > 0 ) {
				$has_checkout_block = has_block( 'woocommerce/checkout', $queried_id );
			}
			if ( ! $has_checkout_block ) {
				global $post;
				if ( $post instanceof WP_Post ) {
					$has_checkout_block = has_block( 'woocommerce/checkout', $post );
				}
			}
		}

		$should_include_scripts = ( bwfan_is_woocommerce_active() && is_checkout() ) || // Checkout page for abandoned cart & dob field
		                          $has_checkout_block || // Any page that embeds the WC Checkout block (e.g. custom URLs)
		                          ( bwfan_is_woocommerce_active() && is_account_page() && ! empty( $setting_data['bwfan_dob_field_my_account'] ) ) || // My account page for DOB field
		                          $this->is_unsubscribe_page( $setting_data ) || // Unsubscribe page
		                          $this->is_manage_profile_page( $setting_data ) || // Manage Profile page
		                          ( function_exists( 'WFOPP_Core' ) && WFOPP_Core()->optin_pages->is_wfop_page() ) || apply_filters( 'bwfan_public_scripts_include', false );

		if ( ! $should_include_scripts ) {
			return;
		}

		$data = [];

		$data['bwfan_checkout_js_data'] = 'no';
		$data['bwfan_no_thanks']        = __( 'No Thanks', 'wp-marketing-automations' );
		$data['is_user_loggedin']       = 0;
		$data['ajax_url']               = admin_url( 'admin-ajax.php' );
		$data['wc_ajax_url']            = class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( '%%endpoint%%' ) : '';
		$data['ajax_nonce']             = wp_create_nonce( 'bwfan-action-public' );
		$data['unsubscribe_nonce']      = wp_create_nonce( 'bwfan-unsubscribe-nonce' );
		$data['message_no_contact']     = __( 'Sorry! We are unable to update preferences as no contact found.', 'wp-marketing-automations' );

		global $post;
		$data['current_page_id'] = ( $post instanceof WP_Post ) ? $post->ID : 0;

		$custom_field = [];
		if ( class_exists( 'WFACP_Common' ) ) {
			/** FunnelKit Checkout advanced fields */
			$checkout_fields = ( $post instanceof WP_Post ) ? WFACP_Common::get_checkout_fields( $post->ID ) : [];
			$custom_field    = isset( $checkout_fields['advanced'] ) ? array_filter( $checkout_fields['advanced'], function ( $fields ) {
				return 'wfacp_html' !== $fields['type'];
			} ) : [];
			$custom_field    = array_column( $custom_field, 'id' );
		}
		$data['bwfan_custom_checkout_field'] = $custom_field;

		$data['bwfan_ab_email_consent']         = '';
		$data['bwfan_ab_email_consent_message'] = '';
		$data['bwfan_ab_enable']                = '';

		if ( ! empty( $setting_data['bwfan_ab_enable'] ) ) {
			$data['bwfan_ab_enable'] = $setting_data['bwfan_ab_enable'];
			$checkout_data           = class_exists( 'WC' ) ? WC()->session->get( 'bwfan_data_for_js' ) : '';
			if ( ! empty( $checkout_data ) ) {
				$data['bwfan_checkout_js_data'] = $checkout_data;
			}
		}

		if ( isset( $setting_data['bwfan_ab_email_consent'] ) ) {
			$data['bwfan_ab_email_consent'] = $setting_data['bwfan_ab_email_consent'];
		}

		if ( isset( $setting_data['bwfan_ab_email_consent_message'] ) ) {
			$data['bwfan_ab_email_consent_message'] = $setting_data['bwfan_ab_email_consent_message'];
		}

		$site_language               = BWFAN_Common::get_site_current_language();
		$data['bwfan_site_language'] = ! empty( $site_language ) ? $site_language : get_locale();

		/** Check for email consent message for a language */
		if ( isset( $setting_data[ 'bwfan_ab_email_consent_message_' . $site_language ] ) ) {
			$data[ 'bwfan_ab_email_consent_message_' . $site_language ] = $setting_data[ 'bwfan_ab_email_consent_message_' . $site_language ];
		}

		if ( isset( $setting_data['bwfan_profile_message_text'] ) ) {
			$data['profile_success'] = $setting_data['bwfan_profile_message_text'];
		}

		// Messages for the profile page
		$data['email_not_fill']    = __( 'Please fill the email field.', 'wp-marketing-automations' );
		$data['email_not_valid']   = __( 'Please enter a valid email.', 'wp-marketing-automations' );
		$data['dob_not_valid']     = __( 'Please enter a valid date of birth.', 'wp-marketing-automations' );
		$data['dob_not_in_future'] = __( 'Date of birth cannot be in the future.', 'wp-marketing-automations' );
		$data['dob_not_all_parts'] = __( 'Please select all date of birth fields (day, month, and year).', 'wp-marketing-automations' );

		/** Flag for Gutenberg Checkout block — JS shim swaps DOM selectors based on this. */
		$data['_is_block_based_checkout'] = ( class_exists( 'BWFAN_Abandoned_Cart' ) && BWFAN_Abandoned_Cart::is_block_checkout() ) ? 1 : 0;

		/** Restored payment method slug — used by JS to re-select the radio on block checkout. */
		if ( class_exists( 'BWFAN_Abandoned_Cart' ) && bwfan_is_woocommerce_active() ) {
			$data['bwfan_restored_payment_method'] = BWFAN_Abandoned_Cart::get_restored_payment_method_slug();
		}

		$data = apply_filters( 'bwfan_external_checkout_custom_data', $data );

		$suffix = ( defined( 'BWFAN_IS_DEV' ) && true === BWFAN_IS_DEV ) ? '' : '.min';

		wp_enqueue_style( 'bwfan-public', BWFAN_PLUGIN_URL . '/assets/css/bwfan-public' . $suffix . '.css', array(), BWFAN_VERSION_DEV );

		$isPreview = filter_input( INPUT_GET, 'bwf-preview' );
		$isPreview = ! empty( $isPreview ) ? sanitize_key( $isPreview ) : '';

		// Enqueue the public styles for prebuild pages
		if ( ( $this->is_manage_profile_page( $setting_data ) && ! empty( $setting_data['bwfan_profile_page_type'] ) && 'prebuild' === $setting_data['bwfan_profile_page_type'] ) || ( $this->is_unsubscribe_page( $setting_data ) && ! empty( $setting_data['bwfan_unsubscribe_page_type'] ) && 'prebuild' === $setting_data['bwfan_unsubscribe_page_type'] ) || ( 'prebuild' === $isPreview ) ) {
			$css_var = [
				'bwfan-brand-color'          => ! empty( $setting_data['bwfan_setting_business_color'] ) ? $setting_data['bwfan_setting_business_color'] : '',
				'bwfan-brand-color-border'   => ! empty( $setting_data['bwfan_setting_business_color'] ) ? $this->adjust_brightness( '#DEDFEA', - 30 ) : '',
				'bwfan-brand-color-contrast' => ! empty( $setting_data['bwfan_setting_business_color'] ) ? $this->get_contrast_color( $setting_data['bwfan_setting_business_color'] ) : '',
			];


			$custom_css = ":root { ";
			foreach ( $css_var as $key => $value ) {
				$custom_css .= "--" . esc_attr( $key ) . ": " . esc_attr( $value ) . "; ";
			}
			$custom_css .= "}";
			wp_register_style( 'bwfan-public-var', false, array(), BWFAN_VERSION ); // no src: handle only carries inline CSS
			wp_enqueue_style( 'bwfan-public-var' );
			wp_add_inline_style( 'bwfan-public-var', $custom_css );
		}


		wp_enqueue_script( 'bwfan-public', BWFAN_PLUGIN_URL . '/assets/js/bwfan-public.js', array( 'jquery' ), BWFAN_VERSION_DEV, true );
		wp_localize_script( 'bwfan-public', 'bwfanParamspublic', $data );
	}

	public function adjust_brightness( $hexColor, $steps = - 30 ) {
		// Handle empty input
		if ( empty( $hexColor ) ) {
			return '#000000';
		}

		// Validate and sanitize inputs
		$steps    = max( - 255, min( 255, $steps ) );
		$hexColor = ltrim( $hexColor, '#' );

		// Handle 3-digit hex codes
		if ( strlen( $hexColor ) === 3 ) {
			$hexColor = $hexColor[0] . $hexColor[0] . $hexColor[1] . $hexColor[1] . $hexColor[2] . $hexColor[2];
		}

		// Quick return for common cases
		if ( $steps === 0 ) {
			return '#' . $hexColor;
		}

		// More efficient RGB conversion using sscanf
		list( $r, $g, $b ) = sscanf( $hexColor, "%2x%2x%2x" );

		// Adjust and clamp RGB values
		$r = max( 0, min( 255, $r + $steps ) );
		$g = max( 0, min( 255, $g + $steps ) );
		$b = max( 0, min( 255, $b + $steps ) );

		// Return formatted hex color
		return sprintf( "#%02x%02x%02x", $r, $g, $b );
	}

	public function get_contrast_color( $hexColor ) {
		// Remove "#" if present
		$hexColor = ltrim( $hexColor, '#' );

		// Convert to RGB
		$r = hexdec( substr( $hexColor, 0, 2 ) );
		$g = hexdec( substr( $hexColor, 2, 2 ) );
		$b = hexdec( substr( $hexColor, 4, 2 ) );

		// Calculate luminance
		$luminance = ( 0.299 * $r + 0.587 * $g + 0.114 * $b );

		// Return black or white depending on brightness
		return ( $luminance > 186 ) ? '#000000' : '#FFFFFF';
	}

	/**
	 * Load all the active automations so that there event function can be registered
	 *
	 * @param $event_slug
	 */
	public function load_active_automations( $event_slug ) {
		if ( empty( $event_slug ) ) {
			return;
		}

		/** Get automations for a event slug if found in cache */
		if ( isset( $this->active_automations[ $event_slug ] ) ) {
			$events_data = $this->active_automations[ $event_slug ];
			if ( isset( $events_data[ $event_slug ] ) && count( $events_data[ $event_slug ] ) > 0 ) {
				$this->set_automation_event_data( $events_data );
			}

			return;
		}

		/** Get all active automations */
		$automations = BWFAN_Core()->automations->get_active_automations( 1, $event_slug );

		if ( empty( $automations ) ) {
			/** No active automations */
			$this->active_automations[ $event_slug ] = [];

			return;
		}

		$events_data = [];
		foreach ( $automations as $automation_id => $automation ) {
			$meta = $automation['meta'];
			unset( $automation['meta'] );
			$merge_data                                   = array_merge( $automation, $meta );
			$events_data[ $event_slug ][ $automation_id ] = $merge_data;
		}

		/** Set cache of found event automations for a event slug */
		$this->active_automations[ $event_slug ] = $events_data;

		if ( isset( $events_data[ $event_slug ] ) && count( $events_data[ $event_slug ] ) > 0 ) {
			$this->set_automation_event_data( $events_data );
		}

	}

	/**
	 * Load all the active automations so that there event function can be registered
	 *
	 * @param $event_slug
	 */
	public function load_active_v2_automations( $event_slug ) {
		if ( empty( $event_slug ) ) {
			return;
		}

		/** Get automations for a event slug if found in cache */
		if ( isset( $this->active_v2_automations[ $event_slug ] ) ) {
			$events_data = $this->active_v2_automations[ $event_slug ];
			if ( isset( $events_data[ $event_slug ] ) && count( $events_data[ $event_slug ] ) > 0 ) {
				$this->set_automation_event_data( $events_data, 2 );
			}

			return;
		}

		/** Get all active automations */
		$automations = BWFAN_Core()->automations->get_active_automations( 2, $event_slug );

		if ( empty( $automations ) ) {
			/** No active automations */
			$this->active_v2_automations[ $event_slug ] = [];

			return;
		}

		$events_data = [];
		foreach ( $automations as $automation_id => $automation ) {
			$meta = $automation['meta'];
			unset( $automation['meta'] );
			$merge_data                                   = array_merge( $automation, $meta );
			$events_data[ $event_slug ][ $automation_id ] = $merge_data;
		}

		/** Set cache of found event automations for a event slug */
		$this->active_v2_automations[ $event_slug ] = $events_data;

		if ( isset( $events_data[ $event_slug ] ) && count( $events_data[ $event_slug ] ) > 0 ) {
			$this->set_automation_event_data( $events_data, 2 );
		}

	}

	/**
	 * Register the actions for every event of every active automation
	 *
	 * @param $events_data
	 */
	private function set_automation_event_data( $events_data, $v = 1 ) {
		foreach ( $events_data as $event_slug => $event_data ) {
			/** @var $event_instance BWFAN_Event */
			$event_instance = BWFAN_Core()->sources->get_event( $event_slug );
			$source         = $event_instance->get_source();

			$this->event_data[ $source ][ $event_slug ] = $event_data; // Source Slug
			$event_instance->set_automations_data( $event_data, $v );
		}
	}

}

if ( class_exists( 'BWFAN_Core' ) ) {
	BWFAN_Core::register( 'public', 'BWFAN_Public' );
}
