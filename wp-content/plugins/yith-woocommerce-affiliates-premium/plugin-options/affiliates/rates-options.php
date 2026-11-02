<?php
/**
 * Rates options
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

$panel     = YITH_WCAF_Admin()->get_tab_instance();
$rules_doc = $panel && isset( $panel->rules_doc ) ? $panel->rules_doc : '';

/**
 * APPLY_FILTERS: yith_wcaf_rates_settings
 *
 * Filters the options available in the Rates subtab.
 *
 * @param array $options Array with options
 *
 * @return array
 */
return apply_filters(
	'yith_wcaf_rates_settings',
	array(
		'affiliates-rates' => array(
			'affiliates-rates-list-tab' => array(
				'type'   => 'custom_tab',
				'action' => 'yith_wcaf_print_affiliates_rates_list_tab',
			),
		),
	)
);
