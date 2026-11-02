<?php
/**
 * Omnisend AJAX Functions
 *
 * @package OmnisendPlugin
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_omnisend_identify', 'omnisend_hook_omnisend_ajax_save_email' );
add_action( 'wp_ajax_nopriv_omnisend_identify', 'omnisend_hook_omnisend_ajax_save_email' );

function omnisend_hook_omnisend_ajax_save_email() {
	check_ajax_referer( 'omnisend-front-script-nonce' );

	Omnisend_Logger::hook();
	$email      = ! empty( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
	$contact_id = ! empty( $_GET['contact_id'] ) ? sanitize_text_field( wp_unslash( $_GET['contact_id'] ) ) : '';
	Omnisend_Logger::debug( "omnisend_hook_omnisend_ajax_save_email - received email: $email, contact_id: $contact_id" );
	Omnisend_Ajax::identify_by_email( $email, $contact_id );

	exit;
}

add_action( 'wp_ajax_omnisend_track_started_checkout_event', 'omnisend_track_started_checkout_event' );
add_action( 'wp_ajax_nopriv_omnisend_track_started_checkout_event', 'omnisend_track_started_checkout_event' );

function omnisend_track_started_checkout_event() {
	check_ajax_referer( 'omnisend-checkout-script-nonce' );

	Omnisend_Logger::hook();
	$email = ! empty( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
	Omnisend_Logger::debug( "omnisend_track_started_checkout_event - received email: $email" );
	Omnisend_Cart_Event::started_checkout( $email, gmdate( DATE_ATOM, time() ) );

	exit;
}

add_action( 'wp_ajax_omnisend_update_plugin_setting', 'omnisend_update_plugin_setting' );

function omnisend_update_plugin_setting() {
	check_ajax_referer( 'omnisend-settings-script-nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	Omnisend_Logger::hook();
	$setting_name  = isset( $_POST['setting_name'] ) ? sanitize_text_field( wp_unslash( $_POST['setting_name'] ) ) : '';
	$setting_value = isset( $_POST['setting_value'] ) ? sanitize_text_field( wp_unslash( $_POST['setting_value'] ) ) : '';
	Omnisend_Logger::info( "omnisend_update_plugin_setting - received setting_name: $setting_name, setting_value: $setting_value" );

	$setting_update_source = 'admin';

	switch ( $setting_name ) {
		case 'contact_tag':
			Omnisend_Settings::set_contact_tag( $setting_value, $setting_update_source );
			break;
		case 'contact_tag_status':
			Omnisend_Settings::set_contact_tag_status( $setting_value, $setting_update_source );
			break;
		case 'checkout_opt_in_status':
			Omnisend_Settings::set_checkout_opt_in_status( $setting_value, $setting_update_source );
			break;
		case 'checkout_sms_opt_in_status':
			Omnisend_Settings::set_checkout_sms_opt_in_status( $setting_value, $setting_update_source );
			break;
		case 'checkout_opt_in_text':
			Omnisend_Settings::set_checkout_opt_in_text( $setting_value, $setting_update_source );
			break;
		case 'checkout_sms_opt_in_text':
			Omnisend_Settings::set_checkout_sms_opt_in_text( $setting_value, $setting_update_source );
			break;
		case 'checkout_opt_in_preselected_status':
			Omnisend_Settings::set_checkout_opt_in_preselected_status( $setting_value, $setting_update_source );
			break;
		default:
			break;
	}

	exit;
}

add_action( 'wp_ajax_omnisend_disconnect_current_site', 'omnisend_disconnect_current_site' );

function omnisend_disconnect_current_site() {
	check_ajax_referer( 'omnisend-settings-script-nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	Omnisend_Logger::hook();
	$result = Omnisend_Disconnect_Service::disconnect_current_site();

	if ( $result['success'] ) {
		wp_send_json_success( $result['message'] );
	} else {
		wp_send_json_error( $result['message'] );
	}
}

add_action( 'wp_ajax_omnisend_toggle_logging', 'omnisend_toggle_logging' );

function omnisend_toggle_logging() {
	check_ajax_referer( 'omnisend_logs' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	Omnisend_Logger::hook();
	$enable = isset( $_POST['enable'] ) ? sanitize_text_field( wp_unslash( $_POST['enable'] ) ) : '0';

	if ( '1' === $enable ) {
		Omnisend_Logger::enable_logging();
		wp_send_json_success( 'Logging enabled' );
	} else {
		Omnisend_Logger::disable_logging();
		wp_send_json_success( 'Logging disabled' );
	}
}

class Omnisend_Ajax {
	/**
	 * @return Omnisend_Operation_Status
	 */
	public static function identify_by_email( $email, $contact_id ) {
		if ( ! Omnisend_Manager::is_setup() ) {
			return Omnisend_Operation_Status::error( 'Omnisend is not setup' );
		}

		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return Omnisend_Operation_Status::error( 'Incorrect request (email)' );
		}

		if ( Omnisend_Contact_Resolver::update_by_email( $email ) ) {
			return Omnisend_Operation_Status::success();
		}

		if ( ! empty( $contact_id ) ) {
			Omnisend_Contact_Resolver::update_by_email_and_contact_id( $email, $contact_id );
			return Omnisend_Operation_Status::success();
		}

		$api_url     = OMNISEND_API_URL . '/api/contacts';
		$curl_result = Omnisend_Helper::omnisend_api( $api_url, 'POST', self::generate_contact_payload( $email ) );
		if ( $curl_result['code'] < 200 || $curl_result['code'] >= 300 ) {
			Omnisend_Logger::log( 'warn', 'contacts', $api_url, 'Unable to push contact ' . $email . ' to Omnisend.' . $curl_result['response'] );

			return Omnisend_Operation_Status::error( 'Unable to create contact (api error)' );
		}
		Omnisend_Logger::log( 'info', 'contacts', $api_url, 'Contact ' . $email . ' was successfully pushed to Omnisend.' );

		$response = json_decode( $curl_result['response'], true );
		if ( empty( $response['id'] ) ) {
			Omnisend_Logger::log( 'warn', 'contacts', $api_url, 'Unable to push contact ' . $email . ' to Omnisend. Unexpected API response: ' . $curl_result['response'] );

			return Omnisend_Operation_Status::error( 'Unable to identify contact (api error)' );
		}

		Omnisend_Contact_Resolver::update_by_email_and_contact_id( $email, $response['id'] );

		return Omnisend_Operation_Status::success();
	}

	private static function generate_contact_payload( $email ) {
		$tags = array( 'source: woocommerce' );
		$tag  = Omnisend_Settings::get_contact_tag_value();

		if ( $tag ) {
			$tags[] = $tag;
		}

		$payload = array(
			'identifiers' => array(
				array(
					'type'     => 'email',
					'id'       => $email,
					'channels' => array(
						'email' => array(
							'status'          => 'nonSubscribed',
							'statusChangedAt' => gmdate( OMNISEND_API_DATE_FORMAT ),
						),
					),
				),
			),
			'tags'        => $tags,
		);
		return $payload;
	}
}
