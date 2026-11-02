<?php
/**
 * Modules list.
 *
 * @package YITH\Subscription
 * @since   2.3.0
 * @author  YITH
 */

defined( 'ABSPATH' ) || exit;

return array(
	'italian-e-invoicing'    => array(
		'name'        => _x( 'Italian Electronic Invoice', 'Module name', 'yith-woocommerce-pdf-invoice' ),
		'description' => __( 'Enable the electronic invoice system and save the options. Reload the page and you\'ll be able to see a new tab called "Electronic Invoice" and download an XML to forward to "Agenzia delle Entrate".', 'yith-woocommerce-pdf-invoice' ),
	),
	'spanish-e-invoicing' => array(
		'name'        => _x( 'Spanish Electronic Invoice', 'Module name', 'yith-woocommerce-pdf-invoice' ),
		'description' => __( 'Enable integration with Verifacti to validate Spanish electronic invoices.', 'yith-woocommerce-pdf-invoice' ),
	)
);
