<?php
/**
 * Implement the Spanish E-Invoicing
 *
 * @package YITH\PDFInvoice
 *
 */

defined( 'ABSPATH' ) || exit;


class YITH_YWPI_Spanish_Invoice  {

	/**
	 * Single instance of the class
	 *
	 * @var object
	 * @since 5.0.0
	 */
	protected static $instance;


	/**
	 * Returns single instance of the class
	 *
	 * @since 5.0.0
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * The constructor
	 */
	private function __construct() {
		$this->init();
	}
	/**
	 * Init the plugin
	 *
	 * @return void
	 */
	protected function init() {
		YITH_YWPI_Connection::get_instance();
		YITH_YWPI_Spanish_Electronic_Invoice_Manager::get_instance();
		YITH_YWPI_Classic_Checkout::get_instance();
		new YITH_YWPI_Spanish_Invoice_Admin();
	}

	/**
	 * Get the Verifacti connection service.
	 *
	 * @return YITH_YWPI_Connection
	 */
	public static function get_connection_service() {
		return YITH_YWPI_Connection::get_instance();
	}
}