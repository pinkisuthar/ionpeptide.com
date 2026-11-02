<?php
/**
 * YITH_YWPI_Electronic_Invoice_Admin .
 *
 * @class   YITH_YWPI_Electronic_Invoice_Admin
 * @since   5.0.0
 * @author  YITH
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

class YITH_YWPI_Electronic_Invoice_Admin extends YITH_YWPI_Module_Admin {

	/**
	 * The module ID
	 *
	 * @var string
	 */
	protected $module_id = 'italian-e-invoicing';

	/**
	 * Constructor
	 *
	 * @since  5.0.0
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Get the tabs
	 *
	 * @return array[]
	 */
	protected function get_tabs() {
		return array(
			'electronic-invoice' => array(
				'title'       => __( 'Italian Electronic Invoice', 'yith-woocommerce-pdf-invoice' ),
				'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="23px" height="20px" viewBox="0 0 23 20" enable-background="new 0 0 23 20" xml:space="preserve"><path fill="#94A2B8" d="M17.2,20H5.8C2.602,20,0,17.398,0,14.2V5.8C0,2.602,2.602,0,5.8,0h11.4C20.398,0,23,2.602,23,5.8v8.4 C23,17.398,20.398,20,17.2,20z M5.8,1.6c-2.316,0-4.2,1.884-4.2,4.2v8.4c0,2.315,1.884,4.2,4.2,4.2h11.4c2.315,0,4.2-1.885,4.2-4.2 V5.8c0-2.316-1.885-4.2-4.2-4.2H5.8z M9.374,6.931V13h3.672v-1.292h-2.091v-1.207h1.887V9.26h-1.887V8.223h2.015V6.931H9.374z"/></svg>',
				'description' => __( 'Configure the settings for the electronic invoice.', 'yith-woocommerce-pdf-invoice' ),
			)
		);
	}
}