<?php
/**
 * Commission table template part plain
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes\Eamils
 * @version 1.0.0
 */

/**
 * Templates variables:
 *
 * @var $commissions   YITH_WCAF_Commissions_Collection
 * @var $affiliate     YITH_WCAF_Affiliate
 * @var $sent_to_admin bool
 * @var $order         WC_Order (no longer in use)
 * @var $token         string (no longer in use)
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<?php
if ( $commissions->is_empty() ) {
	return;
}

$exclude_tax = YITH_WCAF_Orders()->exclude_tax();
?>

<?php
if ( $affiliate && $sent_to_admin ) {
	echo esc_html( strtoupper( _x( 'Affiliate', '[EMAILS] Commissions table', 'yith-woocommerce-affiliates' ) ) ) . "\n\n";
	echo esc_html( $affiliate->get_formatted_name() ) . "\n\n";
	echo esc_html( $affiliate->get_formatted_invoice_profile() );
}
?>

<?php
echo esc_html( strtoupper( _x( 'Commissions', '[EMAILS] Commissions table', 'yith-woocommerce-affiliates' ) ) ) . "\n\n";

foreach ( $commissions as $commission ) {
	/**
	 * APPLY_FILTERS: yith_wcaf_commission_visible
	 *
	 * Filters whether the commission will be visible in the commissions table.
	 *
	 * @param bool  $is_commission_visible Whether the commission is visible or not.
	 * @param array $commission            Commission.
	 */
	if ( ! apply_filters( 'yith_wcaf_commission_visible', true, $commission ) ) {
		continue;
	}

	echo esc_html( sprintf( '#%d', $commission->get_id() ) );

	echo ' | ';

	echo esc_html( $commission->get_product_name() );

	echo ' | ';

	echo esc_html(
		wp_strip_all_tags(
			$commission->get_formatted_line_total(
				'view',
				array(
					/**
					 * APPLY_FILTERS: yith_wcaf_email_currency
					 *
					 * Filters the commission currency in the commissions table.
					 *
					 * @param string $currency   Commission currency.
					 * @param array  $commission Commission.
					 */
					'currency' => apply_filters( 'yith_wcaf_email_currency', $commission->get_currency(), $commission ),
				)
			)
		)
	);

	echo ' | ';

	echo esc_html( $commission->get_formatted_rate() );

	echo ' | ';

	echo esc_html( wp_strip_all_tags( $commission->get_formatted_amount() ) );

	echo "\n";
}
