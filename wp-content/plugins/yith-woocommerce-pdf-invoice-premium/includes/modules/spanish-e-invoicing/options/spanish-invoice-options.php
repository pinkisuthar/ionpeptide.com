<?php
/**
 * Spanish invoice options.
 *
 * @package YITH\PDFInvoice
 * @since   5.0.0
 * @author  YITH <plugins@yithemes.com>
 *
 */

defined( 'ABSPATH' ) || exit;

$description = sprintf(
	__(
		'To use this service, create an account at %1$sVerifacti%2$s and enter the API key for the selected environment.',
		'yith-woocommerce-pdf-invoice'
	),
	'<a href="https://app.verifacti.com/" target="_blank">',
	'</a>'
);

return array(
	'spanish-invoice' => array(
		array(
			'name' => __( 'General settings', 'yith-woocommerce-pdf-invoice' ),
			'type' => 'title',
			'desc' => $description,
		),
		'spanish_invoice_env'                => array(
			'name'      => __( 'Environment', 'yith-woocommerce-pdf-invoice' ),
			'type'      => 'yith-field',
			'yith-type' => 'radio',
			'id'        => 'ywpi_spanish_electronic_invoice_env',
			'options'   => array(
				'production' => __( 'Production', 'yith-woocommerce-pdf-invoice' ),
				'test'       => __( 'Test', 'yith-woocommerce-pdf-invoice' )
			),
			'default'   => 'test',
		),
		'spanish_invoice_production_api_key' => array(
			'name'      => __( 'API key', 'yith-woocommerce-pdf-invoice' ),
			'type'      => 'yith-field',
			'yith-type' => 'text',
			'id'        => 'ywpi_spanish_electronic_invoice_production_api_key',
			'default'   => '',
			'deps'      => array(
				'id'    => 'ywpi_spanish_electronic_invoice_env',
				'value' => 'production',
			),
		),
		'spanish_invoice_test_api_key'       => array(
			'name'      => __( 'API key', 'yith-woocommerce-pdf-invoice' ),
			'type'      => 'yith-field',
			'yith-type' => 'text',
			'id'        => 'ywpi_spanish_electronic_invoice_test_api_key',
			'default'   => '',
			'deps'      => array(
				'id'    => 'ywpi_spanish_electronic_invoice_env',
				'value' => 'test',
			),
		),
		'spanish_connection_status'          => array(
			'name'      => __( 'Verifacti connection', 'yith-woocommerce-pdf-invoice' ),
			'type'      => 'yith-field',
			'yith-type' => 'custom',
			'action'    => 'ywpi_show_spanish_electronic_invoice_connection_status'
		),
		'spanish_allow_f2'                   => array(
			'id'        => 'ywpi_spanish_electronic_invoice_allow_f2_invoice',
			'name'      => __( 'Enable F2 invoices', 'yith-woocommerce-pdf-invoice' ),
			'desc'      => __( 'Enable this option to allow Simplified invoices ( F2 invoices type ) to be generated on Verifacti', 'yith-woocommerce-pdf-invoice' ),
			'type'      => 'yith-field',
			'yith-type' => 'onoff',
			'default'   => 'no'
		),
		'spanish_invoice_f2_threshold'       => array(
			'id'        => 'ywpi_spanish_electronic_invoice_allow_f2_invoice_threshold',
			'name'      => sprintf( __( 'Set the single order threshold ( %s )', 'yith-woocommerce-pdf-invoice' ), get_woocommerce_currency_symbol() ),
			'desc'      => __( 'Set your threshold for F2 invoices', 'yith-woocommerce-pdf-invoice' ),
			'type'      => 'yith-field',
			'yith-type' => 'slider',
			'min'       => 0,
			'max'       => 3000,
			'default'   => 400,
			'deps'      => array(
				'id'    => 'ywpi_spanish_electronic_invoice_allow_f2_invoice',
				'value' => 'yes'
			),
		),
		array(
			'type' => 'sectionend',
		),
	)
);
