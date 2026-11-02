<?php
/**
 * Per-line VeriFactu field profile for WooCommerceOrderLineMapper.
 *
 * @package YITH\PDFInvoice
 */

defined( 'ABSPATH' ) || exit;

/**
 * Line mapping mode — mirrors Verifacti documentation sections.
 */
final class YITH_YWPI_Verifactu_Line_Mode {

	/** Spanish F1/F2: base + tipo_impositivo + cuota from order. */
	public const SPANISH_TAXED = 'spanish_taxed';

	/** B2B intra-community goods: operacion_exenta E5. */
	public const EU_B2B_GOODS = 'eu_b2b_goods';

	/** B2B intra-community services: calificacion_operacion N2. */
	public const EU_B2B_SERVICES = 'eu_b2b_services';

	/** B2C below OSS threshold: Spanish VAT from order (tax fields only). */
	public const EU_B2C_BELOW_THRESHOLD = 'eu_b2c_below';

	/** B2C OSS: clave_regimen 17 + calificacion_operacion N2, no Spanish VAT breakdown. */
	public const EU_B2C_OSS = 'eu_b2c_oss';

	/** Export goods: clave_regimen 02 + operacion_exenta E2. */
	public const EXTRA_EU_GOODS = 'extra_eu_goods';

	/** Extra-EU services: calificacion_operacion N2. */
	public const EXTRA_EU_SERVICES = 'extra_eu_services';
}
