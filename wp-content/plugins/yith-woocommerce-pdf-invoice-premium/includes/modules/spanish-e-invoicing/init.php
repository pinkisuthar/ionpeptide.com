<?php
/**
 * Module for Spanish Electronic Invoicing
 *
 * @package YITH\PDFInvoice
 *
 */


defined( 'ABSPATH' ) || exit;

defined('YITH_YWPI_SPANISH_E_INVOICING_MODULE_PATH') || define( 'YITH_YWPI_SPANISH_E_INVOICING_MODULE_PATH', __DIR__ . DIRECTORY_SEPARATOR );
defined('YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC') || define( 'YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC', YITH_YWPI_SPANISH_E_INVOICING_MODULE_PATH. DIRECTORY_SEPARATOR.'includes' );

require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/scenario/class.yith-ywpi-verifactu-scenario.php';
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/scenario/class.yith-ywpi-verifactu-line-mode.php';

// 2. EU country helper — optionally delegates to WC(), no class deps.
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/scenario/class.yith-ywpi-eu-country-helper.php';

// 3. Scenario resolver — depends on VerifactuScenario + EuCountryHelper.
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/scenario/class.yith-ywpi-verifactu-scenario-resolver.php';

// 4. Skip reason — method body references SpanishInvoicePayloadBuilder,
//    but only at call time (not at load time), so safe to load here.
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/scenario/class.yith-ywpi-verifactu-skip-reason.php';

// 5. Line context DTO — pure data object, no class deps.
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/context/class.yith-ywpi-order-line-context.php';

// 6. Order invoice context — depends on OrderLineContext + WooCommerceOrderLineMapper
//    (mapper is used inside a method body, so load mapper first).
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/mapper/class.yith-ywpi-woocommerce-order-line-mapper.php';
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/context/class.yith-ywpi-woocommerce-order-invoice-context.php';

// 7. Builder interface — depends on WooCommerceOrderInvoiceContext.
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/builder/class.yith-ywpi-verifactu-payload-builder-interface.php';

// 8. Abstract builder — depends on the interface + mapper + context.
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/builder/class.yith-ywpi-abstract-verifactu-payload-builder.php';

// 9. Concrete builders — all extend the abstract builder.
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/builder/class.yith-ywpi-spanish-invoice-payload-builder.php';
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/builder/class.yith-ywpi-eu-b2b-invoice-payload-builder.php';
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/builder/class.yith-ywpi-eub2c-invoice-payload-builder.php';
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/builder/class.yith-ywpi-extra-eu-invoice-payload-builder.php';

// 10. Payload factory — depends on all four builders + resolver + skip reason.
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/factory/class.yith-ywpi-verifactu-invoice-payload-factory.php';

// 11. Connection — depends on verifacti-api ClientFactory (loaded via Composer).
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/connection/class.yith-ywpi-connection.php';

// 12. Submission service — depends on factory + connection.
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/service/class.yith-ywpi-verifactu-submission-service.php';
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/class.yith-ywpi-spanish-electronic-invoice-manager.php';
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/class.yith-ywpi-classic-checkout.php';
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/class.yith-ywpi-spanish-invoice.php';
require_once YITH_YWPI_SPANISH_E_INVOICING_MODULE_INC . '/admin/class.yith-ywpi-spanish-invoice-admin.php';

YITH_YWPI_Spanish_Invoice::get_instance();