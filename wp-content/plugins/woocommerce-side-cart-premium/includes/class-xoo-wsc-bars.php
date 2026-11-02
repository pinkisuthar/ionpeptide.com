<?php

class Xoo_Wsc_Bars{

	protected static $_instance = null;

	public $bars;

	public $options;

	public $barDefaults = array();

	public static function get_instance(){
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	
	public function __construct(){
		$this->options = xoo_wsc_helper()->get_rewards_option();
		$this->hooks();
	}


	public function hooks(){
		add_action( 'xoo_wsc_header_start', array( $this, 'attach_bars' ), 5 );
	}

	public function get_bar_data( $index ){
		if( isset( $this->get_bars()[ $index ] ) ){
			return $this->get_bars()[$index];
		}
	}


	public function get_bar_from_id( $bar_id ){

		$index 	= explode('_', $bar_id)[1];
		$bars 	= $this->get_bars();
		if( isset( $bars[ $index ] ) ){
			return $bars[$index];
		}

	}

	public function get_bars(){

		if( !isset( $this->bars ) ){

			$bars 		= xoo_wsc_helper()->get_rewards_option('bars');
			$bars 		= !is_array( $bars ) ? array() : $bars;
			$defaults 	= $this->get_bar_defaults();
			
			foreach ( $bars as $index => $bar ) {

				$bars[$index]['id'] 		= 'bar_'.$index;

				$bars[$index]['settings'] 	= wp_parse_args( $bars[$index]['settings'], $defaults['settings'] );

				$bars[$index]['settings']['comptxt'] = xoo_wsc_helper()->translate_registered_string( $bar['settings']['comptxt'], $bar['settings']['barTitle'].' | Completed Text' );

				if( isset($bar['checkpoints'] ) && !empty( $bar['checkpoints'] ) ){

					$points 			= $bar['checkpoints'];
					$hasProductFilters 	= isset( $bar['settings']['filter_byproducts'] ) && $bar['settings']['filter_byproducts'] !== 'no';

					foreach ( $points as $point_index => $point) {

						$points[$point_index] = wp_parse_args( $points[$point_index], $defaults['checkpoints'][$point['type']] ); //mergin with defaults

						$points[$point_index]['title'] = xoo_wsc_helper()->translate_registered_string( $point['title'], $bar['settings']['barTitle'].' | checkpoint-'.$point_index.'-title' );
						$points[$point_index]['remaining'] = xoo_wsc_helper()->translate_registered_string( $point['remaining'], $bar['settings']['barTitle'].' | checkpoint-'.$point_index.'-remaining' );

						if( $point['enable'] !== 'yes' || (  $point['type'] === 'freeshipping' && $hasProductFilters ) ){
							unset( $points[ $point_index ] );
						}
					}

					$bars[$index]['checkpoints'] = $points;

				}
			}

			$bars = apply_filters( 'xoo_wsc_bars', $bars );

			$this->bars = $bars;

		}

		return $this->bars;

	}



	public function attach_bars(){

		if( $this->options['scbar-en'] !== 'yes' ) return;

		$bars = $this->get_bars();

		foreach ( $bars as $index => $bar ) {
			add_action( $bar['settings']['location'], array( $this, 'attach_bar_for_location' ) );
		}
	}



	public function attach_bar_for_location(){

		$bars = $this->get_bars();

		foreach ( $bars as $index => $bar ) {

			if( $bar['settings']['location'] !== current_filter() ) continue;
			
			xoo_wsc_helper()->get_template( 'global/header/bar.php', array(
				'bar_index' => $index,
				'bar' 		=> $bar
			) );

		}
	}




	public function bar_template_args( $index ){

		$bar 		= $this->get_bar_data( $index );

		$settings 	= $bar['settings'];
		$points 	= isset( $bar['checkpoints'] ) ? $bar['checkpoints'] : array();

		$enable 	= $settings['enable'];
		
		$args 		= array();

		if( $enable !== 'yes' || empty($points) ) return $args;

		$barValType = $bar['settings']['barValue'];

		$freeShippingpoint = $freeshippingdata = '';

		

		foreach ( $points as $index => $point) {

			if( $point['type'] === 'freeshipping' ){

				if( $settings['barValue'] === 'quantity' ){
					unset( $points[$index] );
					break;
				}

				$freeshippingdata 	= xoo_wsc_cart()->get_free_shipping_method_bar_data();
				if( empty( $freeshippingdata ) ){
					unset( $points[$index] );
				}
				else{
					$freeShippingpoint = $point;
					$points[$index]['amount'] = $freeshippingdata['amount'];
				}
				break;
			}

		}

		if( empty( $points ) ) return $args;

		$cartval 		= xoo_wsc_cart()->bar_cart_value( $bar );
		$divide 		= $this->options['scbar-divide'];

		usort( $points , function( $a, $b ){
			if( $a['amount'] === $b['amount'] ) return 0;
			return $a['amount'] < $b['amount'] ? -1 : 1;
		} );


		$barTotal 		= (end($points))['amount'];
		$currentPoint 	= $currentPointIndex = null;

		$lastPointweight = 0;

		$showcasePoints = array();

		foreach ( $points as $index => $point ) {

			if( !$currentPoint && $cartval < $point['amount'] ){
				$currentPoint = $point;
				$currentPointIndex = $index;
			}

			$weight =  ( $point['amount']/$barTotal ) * 100;
			$width  = $weight - $lastPointweight;

			$points[$index]['width'] 	= $width;
			$points[$index]['weight'] 	= $lastPointweight = $weight;
			$points[$index]['reached'] 	= $cartval >= $point['amount'];

			if( $point['type'] === 'gift' && $point['showcase'] === "yes" ){
				$showcasePoints['point_'.$index] = array(
					'gift_ids' 	=> $point['gift_ids'],
					'gift_qty' 	=> $point['gift_qty'],
					'reached' 	=> $points[$index]['reached']
				);
			}

		}

		if( $currentPoint ){
			$remainingValue = $currentPoint['amount'] - $cartval;
			$replaceValue 	= $barValType === 'quantity' ? $remainingValue : wc_price($remainingValue);
			$remainingText 	=  str_replace( array( '[amount]', '[value]' ) , $replaceValue , $currentPoint['remaining'] );
		}
		else{
			$remainingText 	= $settings['comptxt'];
		}
		 

		if( $divide === 'prop' ){
			$filled = ($cartval/$barTotal) * 100;
		}
		else{

			if( !$currentPoint ){
				$filled = 100;
			}
			else{

				$eachPointWidth 	= 100/count($points);

				$beforePointIndex 	= $currentPointIndex - 1;

				$beforePointAmt 	= isset( $points[ $beforePointIndex ] ) ? $points[ $beforePointIndex ]['amount'] : 0;

				$currentPointFilled = ( ( ( $cartval - $beforePointAmt )/( $currentPoint['amount'] - $beforePointAmt ) ) * 100 ); // % Achieved of current point

				$filled = ( ($currentPointFilled * $eachPointWidth)/100 )  + ( $currentPointIndex * $eachPointWidth ); // % achieved of total bar.

			}
			
		}

		$args = array(
			'enable' 			=> $enable,
			'points' 			=> $points,
			'cartval' 			=> $cartval,
			'barTotal' 			=> $barTotal,
			'filled' 			=> $filled >= 100 ? 100 : $filled,
			'remainingText' 	=> $remainingText,
			'divide' 			=> $divide,
			'show' 				=> $settings['show'],
			'barValType' 		=> $settings['barValue'],
			'showcasePoints' 	=> $showcasePoints
		);


		return $args;

	}


	public function bars_css(){

		$css = '';

		foreach ( $this->get_bars() as $index => $bar ) {

			$bar_id 	 = '#xoo-wsc-bar-id-'.$index;
			$settings 	 = $bar['settings'];

			$css 		.= "
			{$bar_id} .xoo-wsc-bar{
				background-color: {$settings['emptyColor']};
			}
			{$bar_id} span.xoo-wsc-bar-filled{
				background-color: {$settings['filledColor']}
			}
			{$bar_id}, {$bar_id} .amount{
				color: {$settings['textColor']};
			}

			{$bar_id} .xoo-wsc-bar-icons i{
				color: {$settings['iconColor']};
				background-color: {$settings['iconBGColor']};
    			border: {$settings['iconBorder']};
			}

			{$bar_id} .xoo-wsc-bar-icons .xoo-wsc-pt-reached i{
				color: {$settings['iconColorFilled']};
				background-color: {$settings['iconBGColorFilled']};
    			border: {$settings['iconBorderFilled']};
			}
			$bar_id{
				padding: {$settings['contPadding']};
				margin: {$settings['contMargin']};
				background-color: {$settings['contBGColor']};
			}
			";
		}

		$circleSize = "{$this->options['scbar-icon-circle-size']}px";

		$css .= "
			.xoo-wsc-bar-icons i{
				font-size: {$this->options['scbar-icon-size']}px;
				width: {$circleSize};
				height: {$circleSize};
				line-height: {$circleSize};
			}
			.xoo-wsc-bar-cont, .xoo-wsc-bar-cont .amount{
				font-size: {$this->options['scbar-font-size']}px;
			}
		";

		return $css;
	}


	public function get_bar_defaults(){

		if( empty( $this->barDefaults ) ){
			$this->barDefaults = array(
				'settings' => array(
					'barTitle' 				=> 'Progress bar [%^]',
					'enable' 				=> 'yes',
					'barValue' 				=> 'subtotal',
					'location' 				=> 'xoo_wsc_body_start',
					'show' 					=> array( 'remaining', 'amount', 'title', 'icon' ),
					'comptxt' 				=> "Congratulations, you've unlocked all rewards.",
					'emptyColor' 			=> '#eee',
					'filledColor' 			=> '#444',
					'textColor' 			=> '#000',
					'iconColor' 			=> '#444',
					'iconBGColor' 			=> '#fff',
					'iconBorder' 			=> '2px solid #eee',
					'iconColorFilled' 		=> '#fff',
					'iconBGColorFilled' 	=> '#444',
					'iconBorderFilled'		=> '4px solid #eee',
					'contBGColor' 			=> '#fff',
					'contMargin' 			=> '0px 0px',
					'contPadding' 			=> '15px 20px',
					'highestGift' 			=> 'no',
					'overrideDiscount' 		=> 'yes',
					'filter_byproducts' 	=> 'no',
					'filter_product_ids' 	=> '' ,
					'productNotEligibleTxt'	=> 'This product is not eligible for rewards'
				),
				'checkpoints' => array(
					'freeshipping' => array(
						'enable' 	=> 'yes',
						'title' 	=> 'Free Shipping',
						'icon' 		=> 'fas fa-truck',
						'iconFilled' => 'fas fa-check',
						'amount' 	=> 10,
						'remaining' => "You're [value] away from Free Shipping",
					),
					'gift' => array(
						'enable' 	=> 'yes',
						'title' 	=> 'Free Gift',
						'icon' 		=> 'fas fa-gift',
						'iconFilled' => 'fas fa-check',
						'amount' 	=> 15,
						'remaining' => "You're [value] away from a Free Gift",
						'gift_qty' 	=> 1,
						'gift_ids' 	=> '',
						'showcase' 	=> 'no'
					),
					'discount' => array(
						'enable' 	=> 'yes',
						'title' 	=> '10% Discount',
						'icon' 		=> 'fas fa-dollar-sign',
						'iconFilled' => 'fas fa-check',
						'amount' 	=> 20,
						'discount' 	=> 10,
						'remaining' => "You're [value] away from 10% Discount"
					),
					'display' 	=> array(
						'enable' 	=> 'yes',
						'title' 	=> 'Custom Reward',
						'icon' 		=> 'fas fa-gift',
						'iconFilled' => 'fas fa-check',
						'amount' 	=> 25,
						'remaining' => "You're [value] away from ..."
					),

				) 
			);
		}

		return $this->barDefaults;

	}


	public function bar_scripts(){

		if( $this->options['scbar-en'] !== 'yes' ) return;

		$hasIcon = false;

		foreach ( $this->get_bars() as $index => $bar ) {
			if( in_array( 'icon', $bar['settings']['show'] ) ){
				$hasIcon = true;
				break;
			}
		}

		if( $hasIcon ){
			wp_enqueue_style( 'xoo-aff-fa', XOO_WSC_URL.'/library/fontawesome5/css/all.min.css' ); //Font Awesome
		}
	}



}

function xoo_wsc_bars(){
	return Xoo_Wsc_Bars::get_instance();
}
xoo_wsc_bars();
