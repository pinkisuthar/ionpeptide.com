<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BRW_PR_License {

	const LICENSE_SERVER     = 'https://buildrightweb.com/wp-json/brw-license/v1/check';
	const LICENSE_OPTION     = 'brw_pr_license_key';
	const LICENSE_STATUS_KEY = 'brw_pr_license_status_v1';
	const LICENSE_TRANSIENT  = 'brw_pr_license_check_v1';

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'license_admin_notice' ) );
		}
	}

	public static function is_license_valid() {
		$status = self::get_license_status();
		return ! empty( $status['valid'] );
	}

	public static function get_license_status() {
		$cached = get_option( self::LICENSE_STATUS_KEY, array() );

		if ( is_array( $cached ) && isset( $cached['valid'] ) ) {
			return $cached;
		}

		return self::check_license( false );
	}

	public static function check_license( $force = false ) {
		$license_key = trim( (string) get_option( self::LICENSE_OPTION, '' ) );
		$domain      = home_url();

		if ( '' === $license_key ) {
			$status = array(
				'valid'   => false,
				'message' => 'No license key saved yet.',
				'checked' => time(),
			);

			update_option( self::LICENSE_STATUS_KEY, $status, false );
			delete_transient( self::LICENSE_TRANSIENT );

			return $status;
		}

		if ( ! $force ) {
			$transient = get_transient( self::LICENSE_TRANSIENT );
			if ( is_array( $transient ) && isset( $transient['valid'] ) ) {
				update_option( self::LICENSE_STATUS_KEY, $transient, false );
				return $transient;
			}
		}

		$response = wp_remote_post(
			self::LICENSE_SERVER,
			array(
				'timeout' => 12,
				'body'    => array(
					'license' => $license_key,
					'domain'  => $domain,
					'product' => 'brw-payment-rotator-sticky',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$status = array(
				'valid'   => false,
				'message' => 'Could not reach the license server.',
				'checked' => time(),
			);

			update_option( self::LICENSE_STATUS_KEY, $status, false );
			set_transient( self::LICENSE_TRANSIENT, $status, HOUR_IN_SECONDS );

			return $status;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		$status = array(
			'valid'   => ( 200 === $code && ! empty( $body['valid'] ) ),
			'message' => ! empty( $body['message'] ) ? sanitize_text_field( (string) $body['message'] ) : '',
			'checked' => time(),
		);

		if ( '' === $status['message'] ) {
			$status['message'] = $status['valid']
				? 'License is active for this domain.'
				: 'License is not valid for this domain.';
		}

		update_option( self::LICENSE_STATUS_KEY, $status, false );
		set_transient( self::LICENSE_TRANSIENT, $status, 12 * HOUR_IN_SECONDS );

		return $status;
	}

	public static function license_admin_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'woocommerce_page_brw-payment-rotator' !== $screen->id ) {
			return;
		}

		$status = self::get_license_status();
		if ( ! empty( $status['valid'] ) ) {
			return;
		}

		$message = ! empty( $status['message'] )
			? (string) $status['message']
			: 'Enter a valid license key to activate this plugin on this domain.';

		echo '<div class="notice notice-warning"><p><strong>BRW Payment Rotator license:</strong> ' . esc_html( $message ) . '</p></div>';
	}
}

BRW_PR_License::init();