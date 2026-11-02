<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NMI3 logging class which saves important data to the log
 */
class WC_NMI3_Logger {

	public static $logger;

	/**
	 * Log a message
	 */
	public static function log( $message ) {

		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		if ( empty( self::$logger ) ) {
			self::$logger = wc_get_logger();
		}

		self::$logger->debug( $message, array( 'source' => 'woocommerce-gateway-nmi3' ) );

	}

}

new WC_NMI3_Logger();
