<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


class Xoo_Wsc_Loader{

	protected static $_instance = null;
	
	public $isSideCartPage;

	public $updatedFrom;

	public static function get_instance(){
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	
	public function __construct(){
		$this->includes();
		$this->hooks();
	}


	public function define( $constant_name, $constant_value ){
		if( !defined( $constant_name ) ){
			define( $constant_name, $constant_value );
		}
	}

	/**
	 * File Includes
	*/
	public function includes(){

		//xootix framework
		require_once XOO_WSC_PATH.'/includes/xoo-framework/xoo-framework.php';
		require_once XOO_WSC_PATH.'/includes/class-xoo-wsc-helper.php';

		if( $this->is_request( 'admin' ) ) {
			require_once XOO_WSC_PATH.'/admin/class-xoo-wsc-admin-settings.php';
		}

		if( xoo_wsc_helper()->license->init_plugin() ){

			require_once XOO_WSC_PATH.'/includes/xoo-wsc-functions.php';
			require_once XOO_WSC_PATH.'/includes/class-xoo-wsc-template-args.php';

			if( $this->is_request( 'frontend' ) ){
				require_once XOO_WSC_PATH.'/includes/class-xoo-wsc-frontend.php';
			}
			
			if( $this->is_request( 'admin' ) ) {
				require_once XOO_WSC_PATH.'/admin/class-xoo-wsc-admin-settings.php';
			}

			require_once XOO_WSC_PATH.'/includes/class-xoo-wsc-bars.php';

			require_once XOO_WSC_PATH.'/includes/class-xoo-wsc-cart.php';

		}

	}


	/**
	 * Hooks
	*/
	public function hooks(){
		$this->on_install();
	}


	/**
	 * What type of request is this?
	 *
	 * @param  string $type admin, ajax, cron or frontend.
	 * @return bool
	 */
	private function is_request( $type ) {
		switch ( $type ) {
			case 'admin':
				return is_admin();
			case 'ajax':
				return defined( 'DOING_AJAX' );
			case 'cron':
				return defined( 'DOING_CRON' );
			case 'frontend':
				return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' );
		}
	}


	/**
	* On install
	*/
	public function on_install(){

		$version_option = 'xoo-wsc-version';
		$db_version 	= get_option( $version_option );

		if( $db_version && version_compare( $db_version, XOO_WSC_VERSION, '<') ){

			$glOptions 		= xoo_wsc_helper()->get_general_option();
			$syOptions 		= xoo_wsc_helper()->get_style_option();
			$avOptions 		= xoo_wsc_helper()->get_advanced_option();
			$rwOptions 		= xoo_wsc_helper()->get_rewards_option();

			if( version_compare( $db_version, '4.6', '<') ){

				if( !isset( $syOptions['sch-layout'] ) ){ //new header layout option added
					$syOptions['sch-close-fsize'] 	= 26;
					$syOptions['sch-head-fsize'] 	= 22;
				}

				if( !isset( $avOptions['m-fetch-cart'] ) ){
					$avOptions['m-fetch-cart'] = 'page_load';
				}
			}

			
			if( version_compare( $db_version, '3.0', '>') ){ //version compare check here is to make sure that its not an upgrade from free version.

				if( version_compare( $db_version, '4.1', '<') ){
					$glOptions['scb-show'][] = 'product_qty';
				}
				

				if( version_compare( $db_version, '4.4', '<') ){
					$glOptions['scbar-all-celebrate'] = 'none';
					$glOptions['scbar-one-celebrate'] = 'none';
				}
				

				if( version_compare( $db_version, '4.4.3', '<') ){

					$paymentBtns = array();

					if( $glOptions['scf-pec-enable'] === 'yes' ){
						$paymentBtns[] = 'paypal';
					}

					if( $glOptions['scf-amaz-enable'] === 'yes' ){
						$paymentBtns[] = 'amazon';
					}

					$glOptions['scf-payment-btns'] = $paymentBtns;

					$syOptions['scf-paybutton-pos'] = array( 'paypal', 'amazon', 'gpay' );


				}
				

				if( version_compare( $db_version, '4.4.6', '<') ){

					$layout = isset( $syOptions['scbp-card-en'] ) && $syOptions['scbp-card-en'] === 'yes' ? 'cards' : 'rows';

					$newOptions = array(
						'scbp-card-backtxt-color' 	=> '#000',
						'scbp-card-imgh' 			=> '',
						'scb-playout' 				=> $layout,
					);
					$syOptions = array_merge( $newOptions, $syOptions );
					update_option('xoo-wsc-pattern-init', 'yes' );
				}
				
				if( version_compare( $db_version, '4.6', '<') ){	

					$glOptions['sl-enable'] = 'no';
					$glOptions['m-tooltip'] = 'no';
					$glOptions['sch-show'][] = 'save';

					if( !isset( $syOptions['sch-layout'] ) || empty( $syOptions['sch-layout'] ) ){

						$headAlign = $syOptions['sch-head-align'];


						switch ($headAlign) {
							case 'flex-start':
								$newheadAlign = 'left';
								break;

							case 'flex-end':
								$newheadAlign = 'right';
								break;
							
							default:
								$newheadAlign = 'center';
								break;
						}
						
						$closeAlign = $syOptions['sch-close-align'];

						$newCloseAlign = in_array( $closeAlign , array( 'left','right' ) ) ? $closeAlign : 'right';

						$syOptions['sch-layout'][$newheadAlign][] = 'basket';
						$syOptions['sch-layout'][$newheadAlign][] = 'heading';

						$syOptions['sch-layout'][$newCloseAlign][] = 'save';
						$syOptions['sch-layout'][$newCloseAlign][] = 'close';

					}

				}

				if( version_compare( $db_version, '4.7', '<') ){

					//Check if updated from the version which has checkpoints
					if( isset( $glOptions['scbar-en'] ) ){

						$rewardOptions = array();
						$rewardOptions['scbar-en'] = $glOptions['scbar-en'];
						$rewardOptions['scbar-divide'] = $glOptions['scbar-divide'];
						$rewardOptions['scbar-one-celebrate'] = $glOptions['scbar-one-celebrate'];
						$rewardOptions['scbar-all-celebrate'] = $glOptions['scbar-all-celebrate'];
						
						if( isset( $glOptions['scbar-data'] ) ){

							$prevCheckpoints = $glOptions['scbar-data'];

							if( $prevCheckpoints && !empty( $prevCheckpoints ) ){ //bar enabled and has checkpoints

								$newBarSettings = array(
									'enable' 	=> 'yes',
									'barTitle' 	=> 'Progress bar #1',
									'barValue' 	=> $glOptions['scbar-total'],
									'show' 		=> $glOptions['scbar-show'],
									'location' 	=> $glOptions['scbar-pos'],
									'comptxt' 	=> $glOptions['scbar-comptext'],
									'emptyColor' => $syOptions['sch-pbcolor'],
									'filledColor' => $syOptions['sch-pbfillcolor'],
									'textColor' => $syOptions['scb-txtcolor'],
									'iconColor' => '#444',
									'iconBGColor' => '#fff',
									'iconBorder' => '2px solid #eee',
									'iconColorFilled' => '#fff',
									'iconBGColorFilled' => '#444',
									'iconBorderFilled' => '4px solid #eee',
									'overrideDiscount' => 'yes'
								);


								foreach ( $prevCheckpoints as $index => $point ) {
									unset($point['id']);
									if( $point['type'] === 'gift' ){
										$point[ 'gift_ids' ] = explode(',', $point['gift_ids'] );
									}
									$prevCheckpoints[ $index ] = $point;
								}

								$rewardOptions['bars'][] = array(
									'settings' 		=> $newBarSettings,
									'checkpoints' 	=> $prevCheckpoints
								);

								update_option('xoo-wsc-rewards-options', $rewardOptions );

							}

							
						}

					}

				}
				

				if( version_compare( $db_version, '4.7.5', '<') ){
					$rwOptions['scbar-fg-show'] 		= 'yes';
				}
				

				if( version_compare( $db_version, '4.7.8', '<') ){
					$rwOptions['scbar-fg-qtyexc'] = 'no';
				}

				if( version_compare( $db_version, '4.8.0', '<') ){

					$syOptions['sch-padding'] 			= '15px 15px';
					$syOptions['scb-icon-size'] 		= $syOptions['scb-fsize'];
					$syOptions['scbp-sales-bgcolor'] 	= $syOptions['scbp-bgcolor'];
					$syOptions['scbp-sales-txtcolor'] 	= $syOptions['scb-txtcolor'];
					$syOptions['scbp-sales-border'] 	= '1px solid #000';
					$bars = $rwOptions['bars'];

					if( is_array($bars) ){
						foreach ( $bars as $index => $bar ) {
							if( !isset( $bar['settings']['highestGift'] ) ){
								$bar['settings']['highestGift'] = 'no';
							}
							$bar['settings']['contPadding'] = '0px 0px';
							$bar['settings']['contBGColor'] = 'transparent';
							$bar['settings']['contMargin'] 	= '15px 20px';
							$bar['settings']['contPadding'] = '0px 0px';
							$bars[$index] = $bar;
						}
						$rwOptions['bars'] = $bars;	
					}
				}

				if( version_compare( $db_version, '4.8.1', '<') ){
					if( isset( $glOptions['shbk-menu'] ) && $glOptions['shbk-menu'] !== "none" ){
						$glOptions['shbk-menu'] = array( $glOptions['shbk-menu'] ); //converting to array
					}
				}

				if( version_compare( $db_version, '4.8.6', '<') ){
					$rwOptions['scbar-fg-en-delete'] = 'no';
				}


			}

			update_option('xoo-wsc-sy-options', $syOptions );
			update_option('xoo-wsc-gl-options', $glOptions );
			update_option('xoo-wsc-av-options', $avOptions );
			update_option('xoo-wsc-rewards-options', $rwOptions );
		}


		if( version_compare( $db_version, XOO_WSC_VERSION, '<') ){
			$this->updatedFrom = $db_version;
			//Update to current version
			update_option( $version_option, XOO_WSC_VERSION);
		}



		
	}


	public function isSideCartPage(){

		if( !trim(xoo_wsc_helper()->get_general_option('m-hide-cart')) ){
			$hidePages = array();
		}
		else{
			$hidePages = array_map( 'trim', explode( ',', xoo_wsc_helper()->get_general_option('m-hide-cart') ) );
		}

		if( !isset( $this->isSideCartPage ) ){
			
			$this->isSideCartPage = !( !empty( $hidePages ) && ( ( in_array( 'no-woocommerce', $hidePages )  && !is_woocommerce() && !is_cart() && !is_checkout() ) || is_page( $hidePages ) ) || ( is_product() && in_array( get_the_id() , $hidePages ) ) );

			foreach ( $hidePages as $page_id ) {
				if( is_single( $page_id ) ){
					$this->isSideCartPage = false;
					break;
				}
			}
		
		}


		return apply_filters( 'xoo_wsc_is_sidecart_page', $this->isSideCartPage, $hidePages );
	}

}

?>