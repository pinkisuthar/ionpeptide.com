<?php


defined( 'ABSPATH' ) || exit;

class YITH_YWPI_Electronic_Invoice  {

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

		new YITH_YWPI_Electronic_Invoice_Admin();

		YITH_Electronic_Invoice_Manager::get_instance();
	}
}