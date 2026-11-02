<?php
/**
 * Module for Italian Electronic Invoicing
 *
 * @package YITH\PDFInvoice
 *
 */

defined( 'ABSPATH' ) || exit;

defined('YITH_YWPI_ITALIAN_E_INVOICING_MODULE_PATH') || define( 'YITH_YWPI_ITALIAN_E_INVOICING_MODULE_PATH', __DIR__ . DIRECTORY_SEPARATOR );

require_once YITH_YWPI_ITALIAN_E_INVOICING_MODULE_PATH . '/includes/class.yith-ywpi-electronic-invoice.php';
require_once YITH_YWPI_ITALIAN_E_INVOICING_MODULE_PATH . '/includes/class.yith-electronic-invoice-manager.php';
require_once YITH_YWPI_ITALIAN_E_INVOICING_MODULE_PATH . '/includes/admin/class.yith-ywpi-electronic-invoice-admin.php';

YITH_YWPI_Electronic_Invoice::get_instance();