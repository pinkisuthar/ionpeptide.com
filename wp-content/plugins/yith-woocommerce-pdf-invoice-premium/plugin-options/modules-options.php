<?php
/**
 * Module options.
 *
 * @since   5.0.0
 * @author  YITH
 * @package YITH\PDFInvoice
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return array(
	'modules' => array(
		'modules' => array(
			'type'           => 'custom_tab',
			'action'         => 'ywpi_modules_tab',
			'show_container' => true,
		),
	),
);
