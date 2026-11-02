<?php
/**
 * Omnisend Disconnect Service Class
 *
 * @package OmnisendPlugin
 */

defined( 'ABSPATH' ) || exit;

class Omnisend_Disconnect_Service {

	/**
	 * Disconnect all sites (network-wide disconnect).
	 *
	 * @return array Result array with success status and message.
	 */
	public static function disconnect_all_sites() {
		if ( ! Omnisend_Helper::is_omnisend_connected() ) {
			return array(
				'success' => false,
				'message' => 'Site is not connected to Omnisend',
			);
		}

		Omnisend_Logger::info( 'Disconnecting all sites via service' );
		Omnisend_Install::disconnect();

		return array(
			'success' => true,
			'message' => 'All sites disconnected successfully',
		);
	}

	/**
	 * Disconnect current site only.
	 *
	 * @return array Result array with success status and message.
	 */
	public static function disconnect_current_site() {
		if ( ! Omnisend_Helper::is_omnisend_connected() ) {
			return array(
				'success' => false,
				'message' => 'Site is not connected to Omnisend',
			);
		}

		Omnisend_Logger::info( 'Disconnecting current site via service' );
		Omnisend_Install::disconnect_current_site();

		return array(
			'success' => true,
			'message' => 'Current site disconnected successfully',
		);
	}
}
