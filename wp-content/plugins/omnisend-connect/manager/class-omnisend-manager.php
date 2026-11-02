<?php
/**
 * Omnisend Manager Class
 *
 * @package OmnisendPlugin
 */

defined( 'ABSPATH' ) || exit;

class Omnisend_Manager {

	/**
	 * @return bool
	 */
	public static function is_setup() {
		$api_key = get_option( 'omnisend_api_key', null );
		if ( empty( $api_key ) ) {
			return false;
		}

		if ( ! Omnisend_Helper::is_woocommerce_plugin_activated() ) {
			return false;
		}

		return true;
	}

	public static function update_account_info( $data = '' ) {
		$api_key = get_option( 'omnisend_api_key', null );
		if ( ! empty( $api_key ) ) {
			if ( $data == '' ) {
				$data = Omnisend_Helper::get_account_info();
			}
			$link        = OMNISEND_APP_URL . '/woocommerce/shop-info';
			$curl_result = Omnisend_Helper::omnisend_api( $link, 'POST', $data );
			if ( $curl_result['code'] >= 200 && $curl_result['code'] < 300 ) {
				Omnisend_Logger::log( 'info', 'shop_info', $link, 'Shop info has been updated.' );
			} elseif ( $curl_result['code'] == 403 ) {
				Omnisend_Logger::log( 'warn', 'shop_info', $link, 'Unable to update shop info' );
			} elseif ( $curl_result['code'] == 400 || $curl_result['code'] == 404 || $curl_result['code'] == 422 ) {
				Omnisend_Logger::log( 'warn', 'shop_info', $link, 'Unable to update shop info. ' . $curl_result['response'] );
			} else {
				Omnisend_Logger::log( 'warn', 'shop_info', $link, 'Unable to update shop info. May be server error. ' . $curl_result['response'] );
			}
		}
	}

	public static function get_brand_info() {
		$api_key = get_option( 'omnisend_api_key', null );
		if ( empty( $api_key ) ) {
			return array();
		}

		$link        = OMNISEND_API_URL . '/api/brands/current';
		$curl_result = Omnisend_Helper::omnisend_api( $link, 'GET' );

		if ( $curl_result['code'] >= 300 ) {
			Omnisend_Logger::error( "Unable to get account information. Error: {$curl_result['code']} {$curl_result['body']}" );
			return array();
		}

		$response = json_decode( $curl_result['response'], true );
		if ( ! empty( $response['brandID'] ) ) {
			$account_info = array(
				'brandID' => $response['brandID'],
				'name'    => $response['name'],
			);

			return $account_info;
		}
	}
}
