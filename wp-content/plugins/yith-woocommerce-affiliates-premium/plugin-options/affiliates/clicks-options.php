<?php
/**
 * Clicks report page
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

/**
 * APPLY_FILTERS: yith_wcaf_clicks_settings
 *
 * Filters the options available in the Visits subtab.
 *
 * @param array $options Array with options
 *
 * @return array
 */
return apply_filters(
	'yith_wcaf_clicks_settings',
	array(
		'affiliates-clicks' => array(
			'affiliates-clicks-list-tab' => array(
				'type'   => 'custom_tab',
				'action' => 'yith_wcaf_print_affiliate_clicks_list_tab',
			),
		),
	)
);
