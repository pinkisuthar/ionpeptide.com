<?php


$rewards = array(

	array(
		'callback' 		=> 'rewardfake',
		'title' 		=> 'Enable Rewards',
		'id' 			=> 'scbar-en',
		'section_id' 	=> 'general',
		'default' 		=> 'yes',
	),


	array(
		'callback' 		=> 'select',
		'title' 		=> 'Divide Bar',
		'id' 			=> 'scbar-divide',
		'section_id' 	=> 'general',
		'args' 			=> array(
			'options' 	=> array(
				'equal'			=> 'Equally',
				'prop' 			=> 'Proportionately',
			),
		),
		'default' 	=> 'equal',
	),


	array(
		'callback' 		=> 'number',
		'title' 		=> 'Font Size',
		'id' 			=> 'scbar-font-size',
		'section_id' 	=> 'general',
		'default' 		=> '15',
		'desc' 			=> 'Size in px'
	),


	array(
		'callback' 		=> 'number',
		'title' 		=> 'Icon Size',
		'id' 			=> 'scbar-icon-size',
		'section_id' 	=> 'general',
		'default' 		=> '12',
		'desc' 			=> 'Size in px'
	),


	array(
		'callback' 		=> 'number',
		'title' 		=> 'Icon Circle Size',
		'id' 			=> 'scbar-icon-circle-size',
		'section_id' 	=> 'general',
		'default' 		=> '30',
		'desc' 			=> 'Size in px'
	),



	array(
		'callback' 		=> 'select',
		'title' 		=> 'Checkpoint completed celebration',
		'id' 			=> 'scbar-one-celebrate',
		'section_id' 	=> 'general',
		'args' 			=> array(
			'options' 	=> array(
				'none'			=> 'None',
				'SchoolPride' 	=> 'School Pride',
				'BasicCannon' 	=> 'Basic Cannon',
				'RealisticLook'	=> 'Realistic Look',
				'Stars' 		=> 'Stars',
				'Fireworks' 	=> 'Fireworks',
			),
		),
		'default' 	=> 'RealisticLook',
	),

	array(
		'callback' 		=> 'select',
		'title' 		=> 'Progress bar completed Celebration',
		'id' 			=> 'scbar-all-celebrate',
		'section_id' 	=> 'general',
		'args' 			=> array(
			'options' 	=> array(
				'none'			=> 'None',
				'SchoolPride' 	=> 'School Pride',
				'BasicCannon' 	=> 'Basic Cannon',
				'RealisticLook'	=> 'Realistic Look',
				'Stars' 		=> 'Stars',
				'Fireworks' 	=> 'Fireworks',
			),
		),
		'default' 	=> 'SchoolPride',
	),

	array(
		'callback' 		=> 'bars_custom',
		'title' 		=> 'Bars',
		'id' 			=> 'bars',
		'section_id' 	=> 'general',
		'default' 		=> '',
	),

	array(
		'callback' 		=> 'checkbox',
		'title' 		=> '[Free Gifts] Allow removal',
		'id' 			=> 'scbar-fg-en-delete',
		'section_id' 	=> 'general',
		'default' 		=> 'no',
		'desc' 			=> "Allow gift products to be removed from cart"
	),


	array(
		'callback' 		=> 'checkbox',
		'title' 		=> '[Free Gifts] Show added gifts in side cart',
		'id' 			=> 'scbar-fg-show',
		'section_id' 	=> 'general',
		'default' 		=> 'yes',
	),

	array(
		'callback' 		=> 'checkbox',
		'title' 		=> '[Free Gifts] Hide showcase products once achieved',
		'id' 			=> 'scbar-fg-showcase-hide',
		'section_id' 	=> 'general',
		'default' 		=> 'yes',
		'desc' 			=> "If you've enabled 'Showcase products' under gift checkpoint settings and want to hide them once checkpoint is achieved"
	),


	array(
		'callback' 		=> 'checkbox',
		'title' 		=> 'Exclude Gift quantity from basket count',
		'id' 			=> 'scbar-fg-qtyexc',
		'section_id' 	=> 'general',
		'default' 		=> 'no',
		'desc' 			=> 'Excludes gift quantities from basket count ( The count you see in shortcode and floating basket )'
	),

	
);

return apply_filters( 'xoo_wsc_admin_settings', $rewards, 'rewards' );